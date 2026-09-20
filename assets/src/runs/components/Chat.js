import { __, sprintf } from '@wordpress/i18n';
import { groupSteps, stepText, isTerminalStatus } from '../utils';
import PinnedPlan from './PinnedPlan';
import LedgerGroup from './LedgerGroup';
import ParkCard from './ParkCard';

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
 * Counts used against a budget ceiling (S10: "a header showing tool calls,
 * questions and tokens against their ceilings"). `[assumed]`: the REST
 * payload has no `tool_calls_used`/`questions_used` counters of its own (only
 * `step_count`/`tokens_in`/`tokens_out` ride on `run`), so this counts steps
 * client-side — a display aid, not the authoritative count
 * `Runner::driveLoop()` gates budget against server-side (which additionally
 * excludes a parked answer's own ok tool_result and an S7 fence refusal from
 * "used"). Same documented-approximation pattern as `planProgress()`.
 */
function usageAgainstCeilings( run, steps ) {
	const budget = run.budget || {};
	const toolCallsUsed = steps.filter( ( step ) => 'tool_result' === step.kind ).length;
	const questionsUsed = steps.filter( ( step ) => 'question' === step.kind ).length;
	const tokensUsed = ( run.tokens_in || 0 ) + ( run.tokens_out || 0 );

	return {
		toolCalls: { used: toolCallsUsed, max: budget.max_tool_calls },
		questions: { used: questionsUsed, max: budget.max_questions },
		tokens: { used: tokensUsed, max: budget.max_tokens },
	};
}

/**
 * The messenger chat pane (S10): goal + model bubbles, ledger groups for
 * consecutive tool calls, an inline park card, and the pinned plan. The
 * message box lives one level up, in `App` — it is a SINGLE persistent
 * affordance shared across "no run selected" and "a run is selected", not
 * one Chat re-renders per run (17c).
 *
 * @param {Object}   props
 * @param {Object}   props.run
 * @param {Array}    props.steps
 * @param {Function} [props.onResolvePark] `( resume ) => Promise` for the open park, if any.
 * @param {Function} [props.onCancel]      `() => Promise` for the Cancel button.
 * @param {boolean}  [props.busy]          True while a tick/cancel is in flight.
 * @param {number}   [props.tickCount]     How many tick round-trips this run has sent this page-load (the "Tick N" bubble).
 */
export default function Chat( { run, steps, onResolvePark, onCancel, busy, tickCount } ) {
	const entries = groupSteps( steps );
	const park = openPark( run, steps );
	const plan = currentPlan( run, steps );

	const usage = usageAgainstCeilings( run, steps );

	return (
		<div className="senroflux-chat">
			<div className="senroflux-chat-header">
				<h1 className="senroflux-run-heading">{ run.goal }</h1>
				{ park && (
					<button
						type="button"
						className="button senroflux-needs-you"
						onClick={ () => {
							const heading = document.getElementById( 'senroflux-park-heading' );
							if ( heading ) {
								heading.focus();
								heading.scrollIntoView( { block: 'center' } );
							}
						} }
					>
						{ __( 'Needs you', 'senroflux' ) }
					</button>
				) }
				<UsageCeilings usage={ usage } />
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
								onResolve={ onResolvePark }
								busy={ busy }
							/>
						);
					}
					return null;
				} ) }
				{ /*
				 * Between ticks (S10): a typing bubble while a tick round-trip
				 * is in flight. `[assumed]`: the spec's literal wording is
				 * "Tick N: <next action>…", but the harness cannot forecast
				 * WHAT the next model turn will call before its response
				 * comes back (one tick already drains every model turn up to
				 * the next park/completion, B0 rule 4) — there is nothing to
				 * show but that a tick is in flight, so `<next action>` is
				 * rendered as a generic "thinking" placeholder rather than
				 * invented content.
				 */ }
				{ busy && ! park && (
					<div className="senroflux-chat-bubble senroflux-chat-bubble-bot senroflux-typing">
						{ sprintf(
							/* translators: %d: which tick round-trip this is, for this page-load. */
							__( 'Tick %d: thinking…', 'senroflux' ),
							tickCount || 1
						) }
					</div>
				) }
				{ 'running' === run.status && ! park && ! busy && (
					<div className="senroflux-chat-bubble senroflux-chat-bubble-bot senroflux-typing">
						{ __( 'Working…', 'senroflux' ) }
					</div>
				) }
			</div>
			{ /* Live-review finding: a terminal run shows NO Cancel button — there is
			 * simply no button element rendered here for a terminal status, not a
			 * disabled one. */ }
			{ ! isTerminalStatus( run.status ) && (
				<div className="senroflux-run-actions">
					<button
						type="button"
						className="button senroflux-button-danger"
						disabled={ ! onCancel || busy }
						onClick={ () => onCancel && onCancel() }
					>
						{ __( 'Cancel run', 'senroflux' ) }
					</button>
				</div>
			) }
		</div>
	);
}

/** S10 header: "tool calls, questions and tokens against their ceilings". */
function UsageCeilings( { usage } ) {
	const parts = [ usage.toolCalls, usage.questions, usage.tokens ].filter(
		( entry ) => Number.isInteger( entry.max )
	);
	if ( 0 === parts.length ) {
		return null;
	}

	return (
		<div className="senroflux-usage-ceilings">
			{ Number.isInteger( usage.toolCalls.max ) && (
				<span className="senroflux-usage-ceiling">
					{ sprintf( __( 'Tool calls %1$d/%2$d', 'senroflux' ), usage.toolCalls.used, usage.toolCalls.max ) }
				</span>
			) }
			{ Number.isInteger( usage.questions.max ) && (
				<span className="senroflux-usage-ceiling">
					{ sprintf( __( 'Questions %1$d/%2$d', 'senroflux' ), usage.questions.used, usage.questions.max ) }
				</span>
			) }
			{ Number.isInteger( usage.tokens.max ) && (
				<span className="senroflux-usage-ceiling">
					{ sprintf( __( 'Tokens %1$d/%2$d', 'senroflux' ), usage.tokens.used, usage.tokens.max ) }
				</span>
			) }
		</div>
	);
}
