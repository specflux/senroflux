import { useState } from '@wordpress/element';
import { __, sprintf, _n } from '@wordpress/i18n';
import TierBadge from './TierBadge';
import { stepLabel, stepResult, stepVerb, stepTier } from '../utils';

/**
 * A collapsed run of consecutive tool calls (S10: "consecutive tool calls
 * collapse to 'N actions'"). Expanded, each shows a label, the ability id, a
 * tier badge (AS mode only) and the result; a resolved approval is marked on
 * its call.
 *
 * @param {Object}  props
 * @param {Array}   props.calls    `{ step, approvedByViewer }` entries from `groupSteps`.
 * @param {string}  props.gateMode 'agent_safety' | 'built_in'.
 */
export default function LedgerGroup( { calls, gateMode } ) {
	const [ open, setOpen ] = useState( false );
	const count = calls.length;
	const lastLabel = count > 0 ? stepLabel( calls[ count - 1 ].step ) : '';

	return (
		<details className="senroflux-ledger-group" open={ open } onToggle={ ( e ) => setOpen( e.target.open ) }>
			<summary>
				{ /* translators: %d: number of actions in this ledger group. */ sprintf( _n( '%d action', '%d actions', count, 'senroflux' ), count ) }
				{ /* S22 pseudo-locale: lastLabel is mechanically derived from
				 * the raw ability id (stepLabel()), not translatable UI
				 * chrome — data-senroflux-content marks it as data. */ }
				{ lastLabel && <span data-senroflux-content>{ `: ${ lastLabel }` }</span> }
			</summary>
			<ol className="senroflux-ledger">
				{ calls.map( ( call, index ) => (
					<li key={ index } className="senroflux-ledger-row">
						<span className="senroflux-ledger-status">{ 'rejected' === call.step.status ? '✕' : '✓' }</span>
						<span className="senroflux-ledger-label">{ stepLabel( call.step ) }</span>
						<span className="senroflux-ledger-verb">{ stepVerb( call.step ) }</span>
						<TierBadge gateMode={ gateMode } tier={ stepTier( call.step ) } />
						{ call.approvedByViewer && (
							<span className="senroflux-ledger-you">{ __( 'Approved by you', 'senroflux' ) }</span>
						) }
						<span className="senroflux-ledger-result">
							{ 'rejected' === call.step.status ? (
								<span className="senroflux-ledger-you">{ __( 'Rejected by you, not done', 'senroflux' ) }</span>
							) : (
								/* S22 pseudo-locale: raw tool-result data (often
								 * a JSON dump), never chrome. */
								<span data-senroflux-content>{ stepResult( call.step ) }</span>
							) }
						</span>
					</li>
				) ) }
			</ol>
		</details>
	);
}
