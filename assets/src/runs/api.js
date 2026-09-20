/**
 * REST calls the Runs screen reads from (S9/S10). Rendering only in 17a:
 * `listRuns`/`getRun` are the only two calls this stage needs; start/tick/
 * cancel/suggestions belong to 17b's interaction layer.
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
