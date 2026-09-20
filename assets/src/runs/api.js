/**
 * REST + admin-ajax calls the Runs screen drives (S9/S10). `listRuns`/
 * `getRun` are plain reads over REST (both scoped by `Plugin::maySee()`,
 * which already covers a screen-capability holder viewing a delegated run).
 *
 * `startRun`/`tickRun`/`cancelRun` go over **admin-ajax**, not REST (17c):
 * {@see \Specflux\SenroFlux\Admin\ScreenCapability::tickAsScreen()} — the
 * delegation allowance that lets a screen-capability holder resolve a run
 * they do not own — only wraps `Ajax::handleTick()`. REST's `/runs/{id}/tick`
 * calls `senroflux()->tick()` directly with no such wrapper, so it would
 * 403 a delegated (non-owner) tick that admin-ajax accepts. Using REST here
 * would silently narrow what the screen can do relative to the retired PHP
 * cards, so this stage uses admin-ajax for every WRITE action and keeps REST
 * for the two reads.
 */

import apiFetch from '@wordpress/api-fetch';

const NAMESPACE = '/senroflux/v1';

/** GET /senroflux/v1/runs -> `{ runs: [...] }` (release/0.3, 0c1107e). */
export function listRuns() {
	return apiFetch( { path: `${ NAMESPACE }/runs` } ).then( ( response ) => response.runs || [] );
}

/** GET /senroflux/v1/runs/{id} -> `{ run, steps, suggestions, ui }`. */
export function getRun( runId ) {
	return apiFetch( { path: `${ NAMESPACE }/runs/${ runId }` } );
}

/**
 * POST one admin-ajax action (`senroflux_start`/`_tick`/`_cancel`), fail
 * closed on a non-2xx transport error or a `{ success: false }` envelope —
 * the caller always gets either the RunState `data` or a thrown Error whose
 * `.code`/`.message` mirror the server's `{ code, message }` shape.
 *
 * @param {string}                    action Ajax action name (without the `senroflux_` prefix already applied).
 * @param {Object}                    fields Extra POST fields (nonce is added here).
 * @param {{ajaxUrl:string,nonce:string}} config Screen config (`window.senrofluxRunsConfig`).
 */
function postAjax( action, fields, config ) {
	const body = new URLSearchParams();
	body.set( 'action', action );
	body.set( 'nonce', config.nonce || '' );
	Object.keys( fields ).forEach( ( key ) => {
		const value = fields[ key ];
		if ( undefined === value || null === value ) {
			return;
		}
		body.set( key, 'object' === typeof value ? JSON.stringify( value ) : String( value ) );
	} );

	return window
		.fetch( config.ajaxUrl || window.ajaxurl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body,
		} )
		.then( ( response ) => response.json() )
		.then( ( json ) => {
			if ( ! json || true !== json.success ) {
				const data = ( json && json.data ) || {};
				const error = new Error( data.message || 'senroflux_request_failed' );
				error.code = data.code;
				throw error;
			}
			return json.data;
		} );
}

/** admin-ajax `senroflux_start`: {consumer, goal} -> RunState. */
export function startRun( goal, config ) {
	return postAjax( 'senroflux_start', { consumer: config.consumer, goal }, config );
}

/** admin-ajax `senroflux_tick`: {run_id, step_count, resume?} -> RunState. */
export function tickRun( runId, stepCount, resume, config ) {
	return postAjax( 'senroflux_tick', { run_id: runId, step_count: stepCount, resume: resume || undefined }, config );
}

/** admin-ajax `senroflux_cancel`: {run_id} -> RunState. */
export function cancelRun( runId, config ) {
	return postAjax( 'senroflux_cancel', { run_id: runId }, config );
}

/**
 * POST /senroflux/v1/runs/{id}/suggestions/{seq}: {action, text?} ->
 * `{run_id, seq, action, text}` (0.3 S20). This goes over REST, not
 * admin-ajax — the route already requires `manage_options` and a REST nonce
 * (`apiFetch`'s own nonce middleware, the same one `listRuns`/`getRun`
 * already rely on), and it is never called on a delegated run's behalf the
 * way tick/cancel are, so it does not need the screen's admin-ajax
 * delegation wrapper.
 *
 * @param {number} runId
 * @param {number} seq    The suggestion step's own `seq`.
 * @param {'save'|'dismiss'} action
 * @param {string} [text] Edited text; omitted keeps the suggestion's own text.
 */
export function resolveSuggestion( runId, seq, action, text ) {
	const data = { action };
	if ( undefined !== text ) {
		data.text = text;
	}
	return apiFetch( { path: `${ NAMESPACE }/runs/${ runId }/suggestions/${ seq }`, method: 'POST', data } );
}
