import { useEffect, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import TierBadge from './TierBadge';
import { planApprovalCount } from '../utils';

const HEADINGS = {
	question: __( 'Question for you', 'senroflux' ),
	plan: __( 'Plan: needs your OK', 'senroflux' ),
	approval: __( 'Approve this change?', 'senroflux' ),
};

/**
 * An inline park card (S10): a yellow top rule, a heading naming the kind,
 * focus moved to the heading. 17c wires Approve/Reject/Answer/Skip/Accept/
 * Veto to the tick call via `onResolve( resume )`, where `resume` is one of
 * the S5 shapes ({@see \Specflux\SenroFlux\Run\Resume}) — this component
 * never invents a shape the Runner doesn't already validate.
 *
 * The focus ring is scoped to the HEADING only (a named live-review finding:
 * "the park-heading focus ring must hug the heading, not span the whole
 * card") — the ring lives on `.senroflux-park-heading:focus-visible`, not on
 * `.senroflux-park-card`, and this component moves focus there itself so a
 * skeptic can observe `document.activeElement` after a park appears.
 *
 * @param {Object}   props
 * @param {string}   props.kind      'question' | 'plan' | 'approval'.
 * @param {Object}   props.payload   The park's message payload.
 * @param {string}   props.gateMode  'agent_safety' | 'built_in'.
 * @param {Function} [props.onResolve] `( resume ) => Promise` — submits a park
 *                                     resolution. Omitted renders the actions
 *                                     disabled (used by rendering-only tests).
 * @param {boolean}  [props.busy]    True while a resolution is in flight —
 *                                   disables every action to prevent a
 *                                   double-submit.
 */
export default function ParkCard( { kind, payload, gateMode, onResolve, busy } ) {
	const headingRef = useRef( null );

	useEffect( () => {
		if ( headingRef.current ) {
			headingRef.current.focus();
		}
		// Only re-focus when the park itself changes, not on every re-render.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ kind, payload ] );

	return (
		<section className="senroflux-park-card" aria-labelledby="senroflux-park-heading">
			<h3
				id="senroflux-park-heading"
				className="senroflux-park-heading"
				tabIndex={ -1 }
				ref={ headingRef }
			>
				{ HEADINGS[ kind ] || HEADINGS.approval }
			</h3>
			{ 'question' === kind && <QuestionBody payload={ payload } onResolve={ onResolve } busy={ busy } /> }
			{ 'plan' === kind && (
				<PlanBody payload={ payload } gateMode={ gateMode } onResolve={ onResolve } busy={ busy } />
			) }
			{ 'approval' === kind && (
				<ApprovalBody payload={ payload } gateMode={ gateMode } onResolve={ onResolve } busy={ busy } />
			) }
		</section>
	);
}

/**
 * S5 answer shape: `{ "answer": { "text"?, "choice"? } }` or `{ "skip": true }`.
 * A choice list (when the payload carries one) renders as radios plus an
 * "Other" option that reveals a free-text field — ported from the retired
 * PHP card's `assembleAnswerResume()` ("the 'Other' affordance… wins: a typed
 * answer is a text answer, never a choice"). No choices at all falls back to
 * a plain textarea.
 */
function QuestionBody( { payload, onResolve, busy } ) {
	const choices = Array.isArray( payload.choices ) ? payload.choices : [];
	const hasChoices = choices.length > 0;
	const [ choice, setChoice ] = useState( '' );
	const [ text, setText ] = useState( '' );

	const canAnswer = hasChoices
		? ( '__other__' === choice ? '' !== text.trim() : '' !== choice )
		: '' !== text.trim();

	const submit = ( resume ) => {
		if ( onResolve ) {
			onResolve( resume );
		}
	};

	return (
		<div className="senroflux-park-body">
			{ /* S22 pseudo-locale: model/user-authored data with no other markup
			 * hook to exclude it by — `data-senroflux-content` marks it as
			 * NOT translatable chrome. */ }
			<p data-senroflux-content dir="auto">{ payload.text }</p>
			{ payload.rationale && <p className="senroflux-rationale">{ payload.rationale }</p> }
			{ hasChoices ? (
				<div className="senroflux-answer-choices" role="radiogroup" aria-label={ __( 'Answer', 'senroflux' ) }>
					{ choices.map( ( option ) => (
						<label key={ option }>
							<input
								type="radio"
								name="senroflux-answer-choice"
								value={ option }
								checked={ choice === option }
								onChange={ () => setChoice( option ) }
								disabled={ busy }
							/>
							<span data-senroflux-content>{ option }</span>
						</label>
					) ) }
					<label>
						<input
							type="radio"
							name="senroflux-answer-choice"
							value="__other__"
							checked={ '__other__' === choice }
							onChange={ () => setChoice( '__other__' ) }
							disabled={ busy }
						/>
						{ __( 'Other', 'senroflux' ) }
					</label>
					{ '__other__' === choice && (
						<textarea
							className="senroflux-answer-other"
							value={ text }
							onChange={ ( e ) => setText( e.target.value ) }
							disabled={ busy }
							aria-label={ __( 'Your answer', 'senroflux' ) }
						/>
					) }
				</div>
			) : (
				<textarea
					className="senroflux-answer-text"
					value={ text }
					onChange={ ( e ) => setText( e.target.value ) }
					disabled={ busy }
					aria-label={ __( 'Your answer', 'senroflux' ) }
				/>
			) }
			<div className="senroflux-park-actions">
				<button
					type="button"
					className="button button-primary"
					disabled={ ! onResolve || busy || ! canAnswer }
					onClick={ () => {
						if ( hasChoices && '__other__' !== choice ) {
							submit( { answer: { choice } } );
							return;
						}
						submit( { answer: { text: text.trim() } } );
					} }
				>
					{ __( 'Answer', 'senroflux' ) }
				</button>
				<button
					type="button"
					className="button"
					disabled={ ! onResolve || busy }
					onClick={ () => submit( { skip: true } ) }
				>
					{ __( 'Skip', 'senroflux' ) }
				</button>
			</div>
		</div>
	);
}

/**
 * S5 plan shape: `{ "plan": { "action": "accept" | "accept_preapprove" |
 * "veto", "note"? } }`. The pre-approve radio's visibility is decided
 * ENTIRELY by `payload.preapprove_available` (`Runner::planUi()` — true only
 * when the `senroflux_enable_preapproval` filter AND Agent Safety's grants
 * service are both on, S14) — this component never re-derives that decision
 * from `gateMode` or anything else, faithfully porting the three retired
 * `RunsScreenParkCardsTest` rules (hidden by default; offered once grants are
 * on; hidden again while Agent Safety grants are off).
 */
function PlanBody( { payload, gateMode, onResolve, busy } ) {
	const steps = Array.isArray( payload.steps ) ? payload.steps : [];
	const assumptions = Array.isArray( payload.assumptions ) ? payload.assumptions : [];
	const approvalCount = planApprovalCount( payload, gateMode );
	const preapproveAvailable = true === payload.preapprove_available;

	const [ action, setAction ] = useState( 'accept' );
	const [ note, setNote ] = useState( '' );

	// Ported from the retired PHP card's `assemblePlanResume()`: a veto needs
	// a note saying why. Resume::check itself only caps the note's length —
	// this is the same UX guard the PHP screen applied before ever posting,
	// kept for parity rather than relaxed just because the harness would
	// accept an empty one.
	const canSubmit = 'veto' !== action || '' !== note.trim();

	const submit = () => {
		if ( ! onResolve ) {
			return;
		}
		const plan = { action };
		if ( '' !== note.trim() ) {
			plan.note = note.trim();
		}
		onResolve( { plan } );
	};

	return (
		<div className="senroflux-park-body">
			<ol className="senroflux-plan-steps">
				{ steps.map( ( step, index ) => (
					<li key={ index }>
						<span data-senroflux-content dir="auto">{ step.text }</span>
						{ Array.isArray( step.verbs ) &&
							step.verbs.map( ( verb ) => <TierBadge key={ verb } gateMode={ gateMode } tier={ step.tier } /> ) }
					</li>
				) ) }
			</ol>
			{ /*
			 * Disclosure, not cosmetics: the built-in gate's whole guarantee is
			 * that a human sees what they are agreeing to BEFORE accepting a
			 * plan. `planApprovalCount()` counts Tier >= 1 VERB OCCURRENCES
			 * across the plan, not steps — see its docblock for the live-run
			 * defect this exact count was the fix for.
			 */ }
			{ null !== approvalCount && (
				<p className="senroflux-plan-approval-count">
					{ sprintf(
						/* translators: %d: number of approvals this plan will ask for. */
						_n(
							'This plan will ask you to approve %d change.',
							'This plan will ask you to approve %d changes.',
							approvalCount,
							'senroflux'
						),
						approvalCount
					) }
				</p>
			) }
			{ assumptions.length > 0 && (
				<>
					<h4>{ __( 'Assumptions', 'senroflux' ) }</h4>
					<ul>
						{ assumptions.map( ( a, index ) => (
							<li key={ index } data-senroflux-content>{ a }</li>
						) ) }
					</ul>
				</>
			) }
			<div className="senroflux-plan-decision" role="radiogroup" aria-label={ __( 'Decision', 'senroflux' ) }>
				<label>
					<input
						type="radio"
						name="senroflux-plan-action"
						value="accept"
						checked={ 'accept' === action }
						onChange={ () => setAction( 'accept' ) }
						disabled={ busy }
					/>
					{ __( 'Accept', 'senroflux' ) }
				</label>
				{ preapproveAvailable && (
					<label>
						<input
							type="radio"
							name="senroflux-plan-action"
							value="accept_preapprove"
							checked={ 'accept_preapprove' === action }
							onChange={ () => setAction( 'accept_preapprove' ) }
							disabled={ busy }
						/>
						{ __( 'Accept and pre-approve this plan\'s calls, without asking again (good for 24 hours)', 'senroflux' ) }
					</label>
				) }
				<label>
					<input
						type="radio"
						name="senroflux-plan-action"
						value="veto"
						checked={ 'veto' === action }
						onChange={ () => setAction( 'veto' ) }
						disabled={ busy }
					/>
					{ __( 'Veto', 'senroflux' ) }
				</label>
			</div>
			{ 'veto' === action && (
				<textarea
					className="senroflux-plan-veto-note"
					value={ note }
					onChange={ ( e ) => setNote( e.target.value ) }
					placeholder={ __( 'Say why (required)', 'senroflux' ) }
					disabled={ busy }
					aria-label={ __( 'Veto note', 'senroflux' ) }
				/>
			) }
			<div className="senroflux-park-actions">
				<button
					type="button"
					className={ 'veto' === action ? 'button senroflux-button-danger' : 'button button-primary' }
					disabled={ ! onResolve || busy || ! canSubmit }
					onClick={ submit }
				>
					{ 'veto' === action ? __( 'Veto', 'senroflux' ) : __( 'Accept plan', 'senroflux' ) }
				</button>
			</div>
		</div>
	);
}

/** S5 approval shape: `{ "action": "approve" | "reject" }`. */
function ApprovalBody( { payload, gateMode, onResolve, busy } ) {
	const args = payload.args && 'object' === typeof payload.args ? payload.args : {};
	const hasArgs = Object.keys( args ).length > 0;

	return (
		<div className="senroflux-park-body">
			<p>
				<strong>{ __( 'Requested action', 'senroflux' ) }:</strong> <code>{ payload.verb }</code>
			</p>
			<TierBadge gateMode={ gateMode } tier={ payload.tier } />
			{ hasArgs && (
				<>
					<p>
						<strong>{ __( 'Arguments', 'senroflux' ) }:</strong>
					</p>
					{ /*
					 * Live-review finding: approval-card arguments must wrap and
					 * show in full. No `overflow: hidden`, no `text-overflow:
					 * ellipsis` — the class below only sets `white-space:
					 * pre-wrap` and `overflow-wrap: anywhere`, verified by a
					 * rendered-DOM test (see components/__tests__).
					 */ }
					<pre className="senroflux-args" tabIndex={ 0 }>
						{ JSON.stringify( args, null, 2 ) }
					</pre>
				</>
			) }
			<div className="senroflux-park-actions">
				<button
					type="button"
					className="button button-primary"
					disabled={ ! onResolve || busy }
					onClick={ () => onResolve && onResolve( { action: 'approve' } ) }
				>
					{ __( 'Approve', 'senroflux' ) }
				</button>
				<button
					type="button"
					className="button senroflux-button-danger"
					disabled={ ! onResolve || busy }
					onClick={ () => onResolve && onResolve( { action: 'reject' } ) }
				>
					{ __( 'Reject', 'senroflux' ) }
				</button>
			</div>
		</div>
	);
}
