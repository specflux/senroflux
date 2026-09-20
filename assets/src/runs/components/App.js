import { useEffect, useState, useCallback, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { listRuns, getRun, startRun, tickRun, cancelRun } from '../api';
import { isParkedStatus, isTerminalStatus, runsForTab } from '../utils';
import RunList from './RunList';
import Chat from './Chat';
import EmptyState from './EmptyState';
import MessageBox from './MessageBox';

/**
 * The Runs screen root (S10). Owns the two REST reads 17a needed, plus (17c)
 * every write action: starting a run, driving its ticks, resolving its
 * parks, and cancelling it.
 */
export default function App( { config } ) {
	const [ runs, setRuns ] = useState( [] );
	const [ loaded, setLoaded ] = useState( false );
	const [ activeTab, setActiveTab ] = useState( 'needs_you' );
	const [ selectedRunId, setSelectedRunId ] = useState( config.initialRunId || null );
	const [ runDetail, setRunDetail ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ tickCount, setTickCount ] = useState( 0 );
	const [ actionError, setActionError ] = useState( '' );

	// Guards the auto-continue tick chain: "ticks advance only while the page
	// is open" (S10) — a stale promise resolving after unmount (or after the
	// viewer switched to a different run) must never call setState or queue
	// another tick.
	const aliveRef = useRef( true );
	const activeRunRef = useRef( selectedRunId );
	useEffect( () => {
		activeRunRef.current = selectedRunId;
	}, [ selectedRunId ] );
	useEffect(
		() => () => {
			aliveRef.current = false;
		},
		[]
	);

	const ajaxConfig = { nonce: config.nonce, consumer: config.consumer, ajaxUrl: window.ajaxurl };

	const refreshList = useCallback( () => {
		listRuns().then( ( result ) => {
			if ( aliveRef.current ) {
				setRuns( result );
				setLoaded( true );
			}
		} );
	}, [] );

	useEffect( () => {
		refreshList();
	}, [ refreshList ] );

	useEffect( () => {
		if ( ! selectedRunId ) {
			setRunDetail( null );
			return;
		}
		getRun( selectedRunId ).then( ( detail ) => {
			if ( aliveRef.current && selectedRunId === activeRunRef.current ) {
				setRunDetail( detail );
			}
		} );
	}, [ selectedRunId ] );

	// Once the list loads, default to a run that needs the viewer; failing
	// that, auto-select the first row of the ACTIVE tab (the fifth 17a
	// live-review finding, S10: "on a bare load… the detail pane renders the
	// empty state… while the first run row is visibly selected in the list
	// beside it… either auto-select the first row of the active tab, or
	// render a 'Pick a run' prompt"). This picks the first branch.
	useEffect( () => {
		if ( loaded && ! selectedRunId ) {
			const needsYou = runs.find(
				( run ) => run.viewer_may_tick && isParkedStatus( run.status )
			);
			if ( needsYou ) {
				setSelectedRunId( needsYou.id );
				return;
			}
			const visible = runsForTab( runs, activeTab );
			if ( visible.length > 0 ) {
				setSelectedRunId( visible[ 0 ].id );
			}
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ loaded ] );

	/**
	 * Merge a RunState (`{ run, new_steps, ui }` from admin-ajax, or
	 * `{ run, steps, ... }` from a fresh REST `getRun`) into `runDetail`, and
	 * refresh the list so its status pill / tab counts stay in step.
	 */
	const applyRunState = useCallback( ( runId, state ) => {
		if ( ! aliveRef.current || runId !== activeRunRef.current ) {
			return;
		}
		setRunDetail( ( previous ) => {
			const previousSteps = previous && previous.run.id === runId ? previous.steps : [];
			const appended = Array.isArray( state.new_steps ) ? state.new_steps : [];
			return {
				...( previous || {} ),
				run: state.run,
				steps: Array.isArray( state.steps ) ? state.steps : [ ...previousSteps, ...appended ],
			};
		} );
		refreshList();
	}, [ refreshList ] );

	/**
	 * Drive a run forward: tick, and — while it lands on neither a park nor a
	 * terminal status (a stalled/crash-resumed run, B0 rule 4) — tick again,
	 * automatically, for as long as the page stays open and this run stays
	 * selected. This is the ONLY thing that advances a run; there is no
	 * server-side polling or cron.
	 */
	// A tick round-trip that returns neither parked nor terminal is a stalled
	// run being resumed (a crashed/closed-tab tick, B0 rule 4 — a NORMAL tick
	// always drains to a park or a terminal status in one call). This caps the
	// automatic follow-up chain so a server bug that never parks can't spin
	// the tab forever calling tick().
	const MAX_AUTO_TICKS = 25;

	const driveTicks = useCallback(
		( runId, stepCount, resume ) => {
			setBusy( true );
			setActionError( '' );

			const step = ( id, count, body, iteration ) => {
				setTickCount( ( n ) => n + 1 );
				return tickRun( id, count, body, ajaxConfig ).then( ( state ) => {
					if ( ! aliveRef.current || id !== activeRunRef.current ) {
						return;
					}
					applyRunState( id, state );
					const status = state.run.status;
					if (
						! isParkedStatus( status ) &&
						! isTerminalStatus( status ) &&
						iteration < MAX_AUTO_TICKS
					) {
						return step( id, state.run.step_count, null, iteration + 1 );
					}
				} );
			};

			return step( runId, stepCount, resume, 1 )
				.catch( ( error ) => {
					if ( aliveRef.current ) {
						setActionError( error.message || __( 'Something went wrong.', 'senroflux' ) );
					}
				} )
				.finally( () => {
					if ( aliveRef.current ) {
						setBusy( false );
					}
				} );
		},
		[ applyRunState ] // eslint-disable-line react-hooks/exhaustive-deps
	);

	const handleStart = useCallback(
		( goal ) => {
			setBusy( true );
			setActionError( '' );
			startRun( goal, ajaxConfig )
				.then( ( state ) => {
					if ( ! aliveRef.current ) {
						return;
					}
					setSelectedRunId( state.run.id );
					activeRunRef.current = state.run.id;
					setTickCount( 0 );
					driveTicks( state.run.id, state.run.step_count, null );
				} )
				.catch( ( error ) => {
					if ( aliveRef.current ) {
						setBusy( false );
						setActionError( error.message || __( 'Could not start the run.', 'senroflux' ) );
					}
				} );
		},
		[ driveTicks ] // eslint-disable-line react-hooks/exhaustive-deps
	);

	const handleResolvePark = useCallback(
		( resume ) => {
			if ( ! runDetail ) {
				return;
			}
			driveTicks( runDetail.run.id, runDetail.run.step_count, resume );
		},
		[ runDetail, driveTicks ]
	);

	const handleCancel = useCallback( () => {
		if ( ! runDetail ) {
			return;
		}
		const runId = runDetail.run.id;
		setBusy( true );
		setActionError( '' );
		cancelRun( runId, ajaxConfig )
			.then( ( state ) => {
				applyRunState( runId, state );
			} )
			.catch( ( error ) => {
				if ( aliveRef.current ) {
					setActionError( error.message || __( 'Could not cancel the run.', 'senroflux' ) );
				}
			} )
			.finally( () => {
				if ( aliveRef.current ) {
					setBusy( false );
				}
			} );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ runDetail, applyRunState ] );

	const handleSelectRun = ( runId ) => {
		setTickCount( 0 );
		setActionError( '' );
		setSelectedRunId( runId );
	};

	if ( ! loaded ) {
		return <div className="senroflux-loading">{ __( 'Loading…', 'senroflux' ) }</div>;
	}

	// The message box is ONE persistent affordance (S10: "the message box at
	// the bottom" of the messenger layout), not something Chat re-creates per
	// run: parked/running come from the selected run; everything else (no run
	// selected, or the selected run is terminal) is 'idle' and `onSend`
	// starts a NEW run, replacing the current selection.
	const boxState = ! runDetail
		? 'idle'
		: isParkedStatus( runDetail.run.status )
		? 'parked'
		: 'running' === runDetail.run.status
		? 'running'
		: 'idle';

	return (
		<div className="senroflux-runs-app">
			<RunList
				runs={ runs }
				activeTab={ activeTab }
				onTabChange={ setActiveTab }
				selectedRunId={ selectedRunId }
				onSelectRun={ handleSelectRun }
			/>
			<main className="senroflux-runs-main">
				{ actionError && <p className="senroflux-action-error">{ actionError }</p> }
				{ runDetail ? (
					<Chat
						run={ runDetail.run }
						steps={ runDetail.steps }
						onResolvePark={ handleResolvePark }
						onCancel={ handleCancel }
						busy={ busy }
						tickCount={ tickCount }
					/>
				) : runs.length > 0 ? (
					// Fifth 17a live-review finding (S10, open): "Nothing has
					// run yet" is actively false once the list is non-empty —
					// this happens whenever nothing auto-selected (e.g. the
					// active tab genuinely has no rows), and must say so
					// rather than claim no run has ever started.
					<div className="senroflux-pick-a-run">
						<h2>{ __( 'Pick a run', 'senroflux' ) }</h2>
						<p>{ __( 'Choose a run from the list to see its detail.', 'senroflux' ) }</p>
					</div>
				) : (
					<EmptyState
						gateMode={ config.gateMode }
						examples={ config.examples || [] }
						onPickExample={ () => {} }
					/>
				) }
				<MessageBox state={ boxState } onSend={ handleStart } />
			</main>
		</div>
	);
}
