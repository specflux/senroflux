import { useEffect, useState, useCallback, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { listRuns, getRun, startRun, tickRun, cancelRun, resolveSuggestion, fetchSetupState } from '../api';
import { isParkedStatus, isTerminalStatus, runsForTab, sameRunId } from '../utils';
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
	// `config.initialRunId` comes from `wp_localize_script()`, which casts
	// every scalar to a STRING before JSON-encoding it — normalize it to a
	// number here (matching every `run.id` from REST/ajax) so it is never
	// the one value in this component whose type doesn't match. `sameRunId()`
	// below is the defense-in-depth layer for every id comparison regardless.
	const initialRunId = config.initialRunId ? Number( config.initialRunId ) : null;
	const [ selectedRunId, setSelectedRunId ] = useState( initialRunId );
	const [ runDetail, setRunDetail ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ tickCount, setTickCount ] = useState( 0 );
	const [ actionError, setActionError ] = useState( '' );
	// The start state — which packs are runnable, and whether a BLOCKING setup
	// check (no model provider) stops every start. Seeded from the page's
	// config, refreshed whenever the user returns to this tab (J1: configure a
	// provider in Connectors, come back, Start is enabled without a reload).
	const [ setup, setSetup ] = useState( {
		packs: config.packs || [],
		unavailablePacks: config.unavailablePacks || [],
		startBlocked: Boolean( config.startBlocked ),
	} );
	// The finished run a follow-up is being started from (S20), or null.
	const [ followUp, setFollowUp ] = useState( null );

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

	const setupRefreshing = useRef( false );
	const refreshSetup = useCallback( () => {
		if ( setupRefreshing.current ) {
			return;
		}
		setupRefreshing.current = true;
		fetchSetupState( { nonce: config.nonce, consumer: config.consumer, ajaxUrl: window.ajaxurl } )
			.then( ( state ) => {
				if ( ! aliveRef.current ) {
					return;
				}
				// The server-rendered panel is outside the React root: swap its
				// markup (server-escaped, same origin) rather than re-render it.
				const panel = document.getElementById( 'senroflux-setup-panel' );
				if ( panel && 'string' === typeof state.html && '' !== state.html ) {
					panel.outerHTML = state.html;
				} else if ( panel && '' === state.html ) {
					panel.innerHTML = '';
				}
				setSetup( {
					packs: Array.isArray( state.packs ) ? state.packs : [],
					unavailablePacks: Array.isArray( state.unavailable_packs ) ? state.unavailable_packs : [],
					startBlocked: false === state.start_enabled,
				} );
			} )
			// A failed refresh keeps the last known state: never flip Start on a transport blip.
			.catch( () => {} )
			.finally( () => {
				setupRefreshing.current = false;
			} );
	}, [] ); // `config` is the page's fixed localized object.

	useEffect( () => {
		const onVisible = () => {
			if ( 'hidden' !== document.visibilityState ) {
				refreshSetup();
			}
		};
		window.addEventListener( 'focus', refreshSetup );
		document.addEventListener( 'visibilitychange', onVisible );
		return () => {
			window.removeEventListener( 'focus', refreshSetup );
			document.removeEventListener( 'visibilitychange', onVisible );
		};
	}, [ refreshSetup ] );

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
			if ( aliveRef.current && sameRunId( selectedRunId, activeRunRef.current ) ) {
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
		if ( ! aliveRef.current || ! sameRunId( runId, activeRunRef.current ) ) {
			return;
		}
		setRunDetail( ( previous ) => {
			const same = previous && sameRunId( previous.run.id, runId );
			const previousSteps = same ? previous.steps : [];
			const appended = Array.isArray( state.new_steps ) ? state.new_steps : [];
			return {
				...( previous || {} ),
				// The ajax RunState's `run` is a THIN row (no pack, withheld
				// roles, model, budget or report): merge it over what the full
				// read already gave us rather than replacing it, and take the
				// terminal report from `ui.report`.
				run: {
					...( same ? previous.run : {} ),
					...state.run,
					...( state.ui && state.ui.report ? { report: state.ui.report } : {} ),
				},
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
					if ( ! aliveRef.current || ! sameRunId( id, activeRunRef.current ) ) {
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
		( goal, pack, model, followUpOf ) => {
			setBusy( true );
			setActionError( '' );
			startRun( goal, ajaxConfig, pack, model, followUpOf )
				.then( ( state ) => {
					if ( ! aliveRef.current ) {
						return;
					}
					setFollowUp( null );
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

	/**
	 * A suggestion's Save/Dismiss (0.3 S20). Independent of `driveTicks`'s
	 * `busy`/error machinery — resolving a suggestion never ticks the run,
	 * so `SuggestionCard` owns its own busy/error state and this only
	 * updates `runDetail.suggestions` in place once the server confirms.
	 */
	const handleResolveSuggestion = useCallback(
		( seq, action, text ) => {
			if ( ! runDetail ) {
				return Promise.reject( new Error( __( 'No run selected.', 'senroflux' ) ) );
			}
			const runId = runDetail.run.id;
			return resolveSuggestion( runId, seq, action, text ).then( ( result ) => {
				if ( ! aliveRef.current || ! sameRunId( runId, activeRunRef.current ) ) {
					return result;
				}
				setRunDetail( ( previous ) => {
					if ( ! previous || ! Array.isArray( previous.suggestions ) ) {
						return previous;
					}
					return {
						...previous,
						suggestions: previous.suggestions.map( ( suggestion ) =>
							suggestion.seq === seq
								? { ...suggestion, status: 'save' === action ? 'saved' : 'dismissed', text: result.text }
								: suggestion
						),
					};
				} );
				return result;
			} );
		},
		[ runDetail ]
	);

	const handleSelectRun = ( runId ) => {
		setTickCount( 0 );
		setActionError( '' );
		setFollowUp( null );
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
						suggestions={ runDetail.suggestions }
						canManageBrief={ Boolean( config.canManageSiteBrief ) }
						modelChoices={ config.modelChoices || {} }
						onResolvePark={ handleResolvePark }
						onResolveSuggestion={ handleResolveSuggestion }
						onCancel={ handleCancel }
						busy={ busy }
						tickCount={ tickCount }
						// S20: only a viewer who can run the source run's pack (the
						// server's preflight already folds the run capability in) may
						// follow it up; start() re-checks it, and that the viewer may
						// see the run, fail closed.
						canFollowUp={ Boolean( runDetail.run.pack ) && setup.packs.some( ( p ) => p.name === runDetail.run.pack ) }
						onFollowUp={ ( run ) => setFollowUp( { runId: run.id, pack: run.pack } ) }
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
				<MessageBox
					state={ boxState }
					onSend={ handleStart }
					initialText={ config.initialGoal || '' }
					packs={ setup.packs }
					unavailablePacks={ setup.unavailablePacks }
					modelChoices={ config.modelChoices || {} }
					startBlocked={ setup.startBlocked }
					followUp={ followUp }
					onCancelFollowUp={ () => setFollowUp( null ) }
				/>
			</main>
		</div>
	);
}
