import { __, sprintf } from '@wordpress/i18n';
import { groupSteps, stepText, isTerminalStatus } from '../utils';
import PinnedPlan from './PinnedPlan';
import LedgerGroup from './LedgerGroup';
import ParkCard from './ParkCard';
import MessageBox from './MessageBox';

/**
 * The newest step of the kind matching the run's OWN park status. A question
 * or plan step carries no "resolved" flag of its own — the run's status
 * (`awaiting_user`/`awaiting_plan`/`awaiting_approval`) is the harness's own
 * record of which park (if any) is still open, so that is the source of
 * truth here rather than re-deriving it from step adjacency.
 */
function openPark( run, steps ) {
	const kindForStatus = {
		awaiting_user: 'question',
		awaiting_plan: 'plan',
		awaiting_approval: 'approval',
	};
	const kind = kindForStatus[ run.status ];
	if ( ! kind ) {
		return null;
	}
	for ( let i = steps.length - 1; i >= 0; i-- ) {
		if ( steps[ i ].kind === kind ) {
			return steps[ i ];
		}
	}
	return null;
}

/**
 * The active accepted plan. The newest `plan` step, but ONLY once the run has
 * moved past `awaiting_plan` (still-parked plans render as a `ParkCard`, not
 * the pinned plan). `[assumed]`: a plan step carries no explicit accept/veto
 * marker, so a run cancelled after a double veto (S7) would still show its
 * last-proposed plan here as if accepted — a known, documented approximation
 * for this terminal edge case, flagged in the stage-17a report.
 */
function currentPlan( run, steps ) {
	if ( 'awaiting_plan' === run.status ) {
		return null;
	}
	for ( let i = steps.length - 1; i >= 0; i-- ) {
		const step = steps[ i ];
		if ( 'plan' === step.kind && step.message ) {
			return step.message;
		}
	}
	return null;
}

/**
 * The messenger chat pane (S10): goal + model bubbles, ledger groups for
 * consecutive tool calls, an inline park card, the pinned plan, and the
 * (rendering-only, disabled) message box.
 */
export default function Chat( { run, steps } ) {
	const entries = groupSteps( steps );
	const park = openPark( run, steps );
	const plan = currentPlan( run, steps );

	const boxState = park ? 'parked' : 'running' === run.status ? 'running' : 'idle';

	return (
		<div className="senroflux-chat">
			<div className="senroflux-chat-header">
				<h1 className="senroflux-run-heading">{ run.goal }</h1>
			</div>
			{ /*
			 * S6 disclosure: a role the starter lacks the capability for is
			 * withheld from the run's tool surface — without this line the run
			 * silently has fewer abilities than the pack advertises and the
			 * viewer has no way to know why something wasn't attempted. A
			 * viewer holding every capability sees nothing (no empty
			 * paragraph either).
			 */ }
			{ Array.isArray( run.withheld_roles ) && run.withheld_roles.length > 0 && (
				<p className="senroflux-withheld-roles">
					{ sprintf(
						/* translators: %s: comma-separated list of withheld role names. */
						__(
							'Some abilities are off for this run (your account is missing the capability they need): %s',
							'senroflux'
						),
						run.withheld_roles.join( ', ' )
					) }
				</p>
			) }
			<PinnedPlan plan={ plan } steps={ steps } />
			<div className="senroflux-chat-stream">
				<div className="senroflux-chat-bubble senroflux-chat-bubble-user">{ run.goal }</div>
				{ entries.map( ( entry, index ) => {
					if ( 'ledger' === entry.type ) {
						return <LedgerGroup key={ index } calls={ entry.calls } gateMode={ run.gate_mode } />;
					}

					const step = entry.step;
					if ( 'model' === step.kind ) {
						const text = stepText( step );
						return text ? (
							<div key={ index } className="senroflux-chat-bubble senroflux-chat-bubble-bot">
								{ text }
							</div>
						) : null;
					}
					if ( [ 'question', 'plan', 'approval' ].includes( step.kind ) && step === park ) {
						return (
							<ParkCard
								key={ index }
								kind={ step.kind }
								payload={ step.message }
								gateMode={ run.gate_mode }
							/>
						);
					}
					return null;
				} ) }
				{ 'running' === run.status && ! park && (
					<div className="senroflux-chat-bubble senroflux-chat-bubble-bot senroflux-typing">
						{ __( 'Working…', 'senroflux' ) }
					</div>
				) }
			</div>
			<MessageBox state={ boxState } />
			{ /* Live-review finding: a terminal run shows NO Cancel button — there is
			 * simply no button element rendered here for a terminal status, not a
			 * disabled one. */ }
			{ ! isTerminalStatus( run.status ) && (
				<div className="senroflux-run-actions">
					<button type="button" className="button senroflux-button-danger" disabled>
						{ __( 'Cancel run', 'senroflux' ) }
					</button>
				</div>
			) }
		</div>
	);
}
