import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/** S20: a suggestion's own per-line cap — {@see \Specflux\SenroFlux\Tools\SuggestBriefTool::MAX_TEXT_CHARS}. */
const MAX_TEXT_CHARS = 200;

/**
 * A brief suggestion (0.3 S20), ported from the retired PHP card in
 * `src/Admin/RunsScreen.php` (stage 16) into the React chat stream.
 *
 * The model never writes the site brief — only a human click on Save does,
 * through `POST /senroflux/v1/runs/{id}/suggestions/{seq}`
 * ({@see \Specflux\SenroFlux\Run\SuggestionResolver::resolve()}), which is
 * itself gated by `manage_options` server-side. This component mirrors that
 * split rather than re-deciding it: an administrator gets Save/Dismiss with
 * editable text; anyone else gets the text as read-only, copyable content
 * and a line explaining who can act on it — no buttons at all, since a
 * non-admin clicking anything here could never actually do anything (the
 * REST route would 403 it).
 *
 * @param {Object}   props
 * @param {Object}   props.suggestion     `{ seq, text, status }` — status is
 *                                        'pending' | 'saved' | 'dismissed'.
 * @param {boolean}  props.canManageBrief Server-computed `current_user_can( 'manage_options' )`
 *                                        (0.3 S20) — never re-derived from a role
 *                                        guess on the client.
 * @param {Function} [props.onResolve]    `( seq, action, text ) => Promise` —
 *                                        omitted renders the actions disabled.
 */
export default function SuggestionCard( { suggestion, canManageBrief, onResolve } ) {
	const [ text, setText ] = useState( suggestion.text );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );

	const pending = 'pending' === suggestion.status;

	const submit = ( action ) => {
		if ( ! onResolve || busy ) {
			return;
		}
		setBusy( true );
		setError( '' );
		onResolve( suggestion.seq, action, text.trim() )
			.catch( ( err ) => {
				setError( ( err && err.message ) || __( 'Could not save this suggestion.', 'senroflux' ) );
			} )
			.finally( () => {
				setBusy( false );
			} );
	};

	return (
		<div className="senroflux-suggestion-card">
			<h4 className="senroflux-suggestion-heading">{ __( 'Suggested addition to the site brief', 'senroflux' ) }</h4>

			{ ! pending && (
				<p className="senroflux-suggestion-status">
					{ 'saved' === suggestion.status
						? __( 'Saved to the site brief.', 'senroflux' )
						: __( 'Dismissed.', 'senroflux' ) }
				</p>
			) }

			{ canManageBrief ? (
				pending ? (
					<>
						<textarea
							className="senroflux-suggestion-text"
							value={ text }
							onChange={ ( e ) => setText( e.target.value ) }
							maxLength={ MAX_TEXT_CHARS }
							disabled={ busy }
							aria-label={ __( 'Suggested brief text', 'senroflux' ) }
						/>
						{ error && <p className="senroflux-suggestion-error">{ error }</p> }
						<div className="senroflux-suggestion-actions">
							<button
								type="button"
								className="button button-primary"
								disabled={ busy || '' === text.trim() }
								onClick={ () => submit( 'save' ) }
							>
								{ __( 'Save', 'senroflux' ) }
							</button>
							<button
								type="button"
								className="button"
								disabled={ busy }
								onClick={ () => submit( 'dismiss' ) }
							>
								{ __( 'Dismiss', 'senroflux' ) }
							</button>
						</div>
					</>
				) : (
					<pre className="senroflux-suggestion-text-final">{ suggestion.text }</pre>
				)
			) : (
				<>
					<pre className="senroflux-suggestion-text-final" tabIndex={ 0 }>
						{ suggestion.text }
					</pre>
					{ pending && (
						<p className="senroflux-suggestion-admin-only">
							{ __( 'An administrator can add this to the site brief.', 'senroflux' ) }
						</p>
					) }
				</>
			) }
		</div>
	);
}
