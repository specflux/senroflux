import { useEffect, useState, useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { listRuns, getRun } from '../api';
import { isParkedStatus } from '../utils';
import RunList from './RunList';
import Chat from './Chat';
import EmptyState from './EmptyState';

/**
 * The Runs screen root (S10). Owns the two REST reads 17a needs — the run
 * list and one run's detail — and the tab/selection state. Starting,
 * ticking, cancelling and resolving parks are 17b's.
 */
export default function App( { config } ) {
	const [ runs, setRuns ] = useState( [] );
	const [ loaded, setLoaded ] = useState( false );
	const [ activeTab, setActiveTab ] = useState( 'needs_you' );
	const [ selectedRunId, setSelectedRunId ] = useState( config.initialRunId || null );
	const [ runDetail, setRunDetail ] = useState( null );

	const refreshList = useCallback( () => {
		listRuns().then( ( result ) => {
			setRuns( result );
			setLoaded( true );
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
		getRun( selectedRunId ).then( setRunDetail );
	}, [ selectedRunId ] );

	// Once the list loads, default to a run that needs the viewer if none is
	// already selected from the URL.
	useEffect( () => {
		if ( loaded && ! selectedRunId ) {
			const needsYou = runs.find(
				( run ) => run.viewer_may_tick && isParkedStatus( run.status )
			);
			if ( needsYou ) {
				setSelectedRunId( needsYou.id );
			}
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ loaded ] );

	if ( ! loaded ) {
		return <div className="senroflux-loading">{ __( 'Loading…', 'senroflux' ) }</div>;
	}

	return (
		<div className="senroflux-runs-app">
			<RunList
				runs={ runs }
				activeTab={ activeTab }
				onTabChange={ setActiveTab }
				selectedRunId={ selectedRunId }
				onSelectRun={ setSelectedRunId }
			/>
			<main className="senroflux-runs-main">
				{ runDetail ? (
					<Chat run={ runDetail.run } steps={ runDetail.steps } />
				) : (
					<EmptyState
						gateMode={ config.gateMode }
						examples={ config.examples || [] }
						onPickExample={ () => {} }
					/>
				) }
			</main>
		</div>
	);
}
