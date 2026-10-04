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
import { __ } from '@wordpress/i18n';

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
		.then( ( response ) => response.json().then( ( json ) => ( { status: response.status, json } ) ) )
		.then( ( { status, json } ) => {
			// check_ajax_referer() answers a stale nonce (a tab left open past
			// its session) with a bare "-1" and HTTP 403: say what to do.
			if ( 403 === status && -1 === json ) {
				const expired = new Error( __( 'This page has expired. Reload it to continue.', 'senroflux' ) );
				expired.code = 'senroflux_session_expired';
				throw expired;
			}
			if ( ! json || true !== json.success ) {
				const data = ( json && json.data ) || {};
				const error = new Error( data.message || 'senroflux_request_failed' );
				error.code = data.code;
				throw error;
			}
			return json.data;
		} );
}

/**
 * admin-ajax `senroflux_start`: {consumer, goal, pack?, model_provider?, model_id?}
 * -> RunState. `model` is `{ provider, id } | null` — null (the "Automatic"
 * choice) sends neither field, so the request is byte-identical to the
 * pre-model-picker shape and the server falls back to its own default.
 *
 * @param {string}      goal
 * @param {Object}      config
 * @param {string}      [pack]
 * @param {?{provider: string, id: string}} [model] Chosen (provider, model) pair, or null/omitted for automatic.
 * @param {number}      [followUpOf] A finished run to follow up (0.3 S20). The server forces
 *                                   the pack to that run's own, whatever `pack` says.
 */
export function startRun( goal, config, pack, model, followUpOf ) {
	const fields = { consumer: config.consumer, goal, pack };
	if ( followUpOf ) {
		fields.follow_up_of = followUpOf;
	}
	if ( model ) {
		fields.model_provider = model.provider;
		fields.model_id = model.id;
	}
	return postAjax( 'senroflux_start', fields, config );
}

/**
 * admin-ajax `senroflux_setup_panel` (0.3 S11, "refresh on window focus"):
 * the setup panel's markup plus the whole start state — `{ html,
 * start_enabled, packs, unavailable_packs }`. Same nonce/capability as every
 * other write, but read-only: it changes nothing on the server.
 */
export function fetchSetupState( config ) {
	return postAjax( 'senroflux_setup_panel', {}, config );
}

/**
 * admin-ajax `senroflux_dismiss_agent_safety_check` (0.3 S11): record the
 * current user's dismissal of the Agent Safety advisory (user meta, never
 * re-armed). The nonce is the one the panel's own Dismiss button carries
 * (`data-nonce`), not the screen's run nonce. Returns `{ html }`, the panel
 * without the advisory.
 */
export function dismissAdvisory( config ) {
	return postAjax( 'senroflux_dismiss_agent_safety_check', {}, config );
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
