import { __, sprintf } from '@wordpress/i18n';
import { RUN_TABS, runsForTab, tabCounts } from '../utils';
import StatusPill from './StatusPill';

/**
 * The left-hand run list (S10): tabs All / Needs you (count) / Running /
 * Finished, "Needs you" the default. Every tab's count comes from the SAME
 * `runsForTab` predicate that filters its rows, so a count can never drift
 * from the list it describes (a named live-review finding).
 */
export default function RunList( { runs, activeTab, onTabChange, selectedRunId, onSelectRun } ) {
	const counts = tabCounts( runs );
	const visible = runsForTab( runs, activeTab );

	return (
		<aside className="senroflux-run-list" aria-label={ __( 'Runs', 'senroflux' ) }>
			<div className="senroflux-run-tabs" role="tablist">
				{ RUN_TABS.map( ( tab ) => (
					<button
						key={ tab.key }
						type="button"
						role="tab"
						aria-selected={ activeTab === tab.key }
						className={ `senroflux-run-tab${ activeTab === tab.key ? ' is-active' : '' }` }
						onClick={ () => onTabChange( tab.key ) }
					>
						{ tab.label }
						{ ` (${ counts[ tab.key ] })` }
					</button>
				) ) }
			</div>
			<ul className="senroflux-run-rows">
				{ visible.map( ( run ) => (
					<li key={ run.id }>
						<button
							type="button"
							className={ `senroflux-run-row${ run.id === selectedRunId ? ' is-selected' : '' }` }
							onClick={ () => onSelectRun( run.id ) }
						>
							<span className="senroflux-run-row-title">{ run.goal }</span>
							<StatusPill status={ run.status } stalled={ run.stalled } />
							<span className="senroflux-run-row-pack">{ run.pack }</span>
						</button>
					</li>
				) ) }
				{ 0 === visible.length && (
					<li className="senroflux-run-rows-empty">
						{ /* translators: %s: run list tab label. */ sprintf( __( 'No runs in %s.', 'senroflux' ), RUN_TABS.find( ( t ) => t.key === activeTab )?.label || activeTab ) }
					</li>
				) }
			</ul>
		</aside>
	);
}
