import { useEffect, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import TierBadge from './TierBadge';

const HEADINGS = {
	question: __( 'Question for you', 'senroflux' ),
	plan: __( 'Plan: needs your OK', 'senroflux' ),
	approval: __( 'Approve this change?', 'senroflux' ),
};

/**
 * An inline park card (S10): a yellow top rule, a heading naming the kind,
 * focus moved to the heading. Rendering only — 17b wires Approve/Reject/
 * Answer/Accept/Veto to the tick REST call; here they render disabled with a
 * clear "not yet available" state, per B0 rule 2 (fail closed: an unwired
 * button that LOOKS clickable would mislead a viewer into thinking a click
 * did something).
 *
 * The focus ring is scoped to the HEADING only (a named live-review finding:
 * "the park-heading focus ring must hug the heading, not span the whole
 * card") — the ring lives on `.senroflux-park-heading:focus-visible`, not on
 * `.senroflux-park-card`, and this component moves focus there itself so a
 * skeptic can observe `document.activeElement` after a park appears.
 */
export default function ParkCard( { kind, payload, gateMode } ) {
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
			{ 'question' === kind && <QuestionBody payload={ payload } /> }
			{ 'plan' === kind && <PlanBody payload={ payload } gateMode={ gateMode } /> }
			{ 'approval' === kind && <ApprovalBody payload={ payload } gateMode={ gateMode } /> }
		</section>
	);
}

function QuestionBody( { payload } ) {
	return (
		<div className="senroflux-park-body">
			<p>{ payload.text }</p>
			{ payload.rationale && <p className="senroflux-rationale">{ payload.rationale }</p> }
			<div className="senroflux-park-actions">
				<button type="button" className="button button-primary" disabled>
					{ __( 'Answer', 'senroflux' ) }
				</button>
				<button type="button" className="button" disabled>
					{ __( 'Skip', 'senroflux' ) }
				</button>
			</div>
		</div>
	);
}

function PlanBody( { payload, gateMode } ) {
	const steps = Array.isArray( payload.steps ) ? payload.steps : [];
	const assumptions = Array.isArray( payload.assumptions ) ? payload.assumptions : [];

	return (
		<div className="senroflux-park-body">
			<ol className="senroflux-plan-steps">
				{ steps.map( ( step, index ) => (
					<li key={ index }>
						<span>{ step.text }</span>
						{ Array.isArray( step.verbs ) &&
							step.verbs.map( ( verb ) => <TierBadge key={ verb } gateMode={ gateMode } tier={ step.tier } /> ) }
					</li>
				) ) }
			</ol>
			{ assumptions.length > 0 && (
				<>
					<h4>{ __( 'Assumptions', 'senroflux' ) }</h4>
					<ul>
						{ assumptions.map( ( a, index ) => (
							<li key={ index }>{ a }</li>
						) ) }
					</ul>
				</>
			) }
			<div className="senroflux-park-actions">
				<button type="button" className="button button-primary" disabled>
					{ __( 'Accept plan', 'senroflux' ) }
				</button>
				<button type="button" className="button senroflux-button-danger" disabled>
					{ __( 'Veto', 'senroflux' ) }
				</button>
			</div>
		</div>
	);
}

function ApprovalBody( { payload, gateMode } ) {
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
				<button type="button" className="button button-primary" disabled>
					{ __( 'Approve', 'senroflux' ) }
				</button>
				<button type="button" className="button senroflux-button-danger" disabled>
					{ __( 'Reject', 'senroflux' ) }
				</button>
			</div>
		</div>
	);
}
