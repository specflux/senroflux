import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * The message box (S10). It only ever starts a NEW run — this system has no
 * mid-run "send another instruction" affordance (a park resolution is the
 * only human input a running conversation accepts), so `onSend` fires once,
 * when idle, and the box goes disabled for the rest of the run's life:
 * "parked" ("Answer the card above to continue") and "running" (ticks are
 * driven automatically once started; see `App`'s `driveTicks`) both disable
 * it, same as before 17c wired the actual submit.
 */
export default function MessageBox( { state, onSend } ) {
	const [ text, setText ] = useState( '' );
	const disabled = 'idle' !== state || ! onSend;

	const placeholder =
		'parked' === state
			? __( 'Answer the card above to continue', 'senroflux' )
			: 'running' === state
			? __( 'Working. Keep this page open; the run pauses if you leave and continues when you come back.', 'senroflux' )
			: __( 'Describe what you want done…', 'senroflux' );

	const submit = () => {
		const goal = text.trim();
		if ( '' === goal || ! onSend ) {
			return;
		}
		onSend( goal );
		setText( '' );
	};

	return (
		<div className="senroflux-message-box">
			<textarea
				className="senroflux-message-box-input"
				disabled={ disabled }
				placeholder={ placeholder }
				value={ text }
				onChange={ ( e ) => setText( e.target.value ) }
				onKeyDown={ ( e ) => {
					if ( 'Enter' === e.key && ! e.shiftKey && ! disabled ) {
						e.preventDefault();
						submit();
					}
				} }
			/>
			<button type="button" className="button button-primary" disabled={ disabled || '' === text.trim() } onClick={ submit }>
				{ __( 'Start run', 'senroflux' ) }
			</button>
		</div>
	);
}
