/**
 * Defect C (0.3 live run): a run that finished (failed/completed/cancelled)
 * while its detail page was open kept showing the "Cancel run" button —
 * only PARK statuses reload the page (`assets/runs.js`); a terminal status
 * just stops polling and updates the badge, leaving whatever was rendered at
 * page load (when the run was still "running") sitting in the DOM.
 *
 * This drives the real `assets/runs.js` poll loop inside jsdom against a
 * stubbed `fetch` that reports the run went `failed`, and asserts the
 * Cancel button is actually removed from the DOM afterwards.
 *
 * Run: `node --test tests/Admin/runs-cancel-button.test.cjs`
 */

const { test } = require( 'node:test' );
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const { JSDOM, VirtualConsole } = require( 'jsdom' );

const SCRIPT_PATH = path.join( __dirname, '..', '..', 'assets', 'runs.js' );

/**
 * Boots a fresh jsdom page with the run-detail markup runs.js expects
 * (status "running", with the server-rendered Cancel button present), wires
 * a stub `fetch` that resolves to `pollResponse`, evaluates the real
 * runs.js source in that window, and waits long enough for one poll to
 * complete.
 */
async function pollToTerminal( pollResponse ) {
	const dom = new JSDOM(
		`<!doctype html><html><body>
			<div id="senroflux-run-detail" data-run-id="1" data-step-count="1" data-status="running">
				<div id="senroflux-live-status"></div>
				<span id="senroflux-status-badge" class="senroflux-badge senroflux-badge-running"></span>
				<ol class="senroflux-steps"></ol>
			</div>
			<p class="senroflux-refresh"><a href="#">Refresh</a></p>
			<p class="senroflux-cancel-run"><a class="button button-secondary" href="admin-post.php?action=senroflux_cancel_run&amp;run_id=1">Cancel run</a></p>
		</body></html>`,
		{ url: 'https://senroflux.invalid/', virtualConsole: new VirtualConsole(), runScripts: 'outside-only' }
	);

	dom.window.senrofluxRuns = {
		ajaxUrl: 'https://senroflux.invalid/wp-admin/admin-ajax.php',
		nonce: 'test-nonce',
		pollInterval: 5,
		parkAnnounceMs: 5,
		i18n: {},
	};

	// Only answer the FIRST poll; a "still running" response would otherwise
	// have the real script reschedule forever and hang the test process.
	let answered = false;
	dom.window.fetch = function () {
		if ( answered ) {
			return new Promise( () => {} ); // Never resolves; nothing left to assert.
		}
		answered = true;
		return Promise.resolve( {
			json: () => Promise.resolve( pollResponse ),
		} );
	};

	dom.window.eval( fs.readFileSync( SCRIPT_PATH, 'utf8' ) );

	// One poll cycle: schedulePoll() waits `pollInterval` ms, then the fetch
	// promise chain resolves on the next microtask/macrotask turns.
	await new Promise( ( resolve ) => dom.window.setTimeout( resolve, 60 ) );

	const cancelLinkPresent = null !== dom.window.document.querySelector( '.senroflux-cancel-run' );
	dom.window.close();

	return cancelLinkPresent;
}

test( 'a run that fails mid-poll removes the stale Cancel run button', async () => {
	const cancelLinkPresent = await pollToTerminal( {
		success: true,
		data: {
			run: { status: 'failed', step_count: 1 },
			new_steps: [],
		},
	} );

	assert.equal( cancelLinkPresent, false, 'the Cancel run button must be removed once the run is terminal' );
} );

test( 'a run that completes mid-poll removes the stale Cancel run button', async () => {
	const cancelLinkPresent = await pollToTerminal( {
		success: true,
		data: {
			run: { status: 'completed', step_count: 1 },
			new_steps: [],
		},
	} );

	assert.equal( cancelLinkPresent, false, 'the Cancel run button must be removed once the run is terminal' );
} );

test( 'a run that is still running keeps the Cancel run button', async () => {
	const cancelLinkPresent = await pollToTerminal( {
		success: true,
		data: {
			run: { status: 'running', step_count: 1 },
			new_steps: [],
		},
	} );

	assert.equal( cancelLinkPresent, true, 'a still-running run must keep offering Cancel' );
} );
