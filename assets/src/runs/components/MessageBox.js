import { __ } from '@wordpress/i18n';

/**
 * The message box (S10). 17b wires it to start a run; 17a renders it
 * disabled with a clear state rather than half-wiring it, per the stage-17a
 * brief.
 */
export default function MessageBox( { state } ) {
	const placeholder =
		'parked' === state
			? __( 'Answer the card above to continue', 'senroflux' )
			: 'running' === state
			? __( 'Working. Keep this page open; the run pauses if you leave and continues when you come back.', 'senroflux' )
			: __( 'Describe what you want done… (starting a run is not available on this screen yet)', 'senroflux' );

	return (
		<div className="senroflux-message-box">
			<textarea className="senroflux-message-box-input" disabled placeholder={ placeholder } />
			<button type="button" className="button button-primary" disabled>
				{ __( 'Start run', 'senroflux' ) }
			</button>
		</div>
	);
}
