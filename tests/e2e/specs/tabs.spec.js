// @ts-check
const { test, expect } = require( '@playwright/test' );
const { resetRuns, setScript, wpCli } = require( '../support/wp-cli' );
const { fullTour, planOnly, quickComplete } = require( '../support/scenarios' );
const { gotoRuns, waitLoaded, startRun, waitForPark } = require( '../support/actions' );
const { assertNoSeriousA11y } = require( '../support/a11y' );

/**
 * Create a `pending` run directly (never ticked) — the exact shape of the
 * stage-17a live-review finding: a run that was started but has not ticked
 * belongs in Running/All, not in an invisible fourth bucket.
 */
function createPendingRunViaCli() {
	wpCli( [
		'eval',
		'wp_set_current_user(1); $p = \\Specflux\\SenroFlux\\Http\\ConsumerPolicy::resolve("senroflux-admin", []); $r = senroflux()->start("senroflux-admin", "Pending, never ticked", $p["allow"], $p["budget"]); echo $r["run"]["id"];',
	] );
}

test.describe( 'S10 run-list tabs partition every run exactly once', () => {
	test( 'Needs you + Running + Finished === All, for every status, and every tab renders a count including (0)', async ( { page } ) => {
		// Three separate run-starts plus page reloads in one test, each a
		// real HTTP round trip through wp-env — comfortably over the
		// suite's default 45s budget on its own merits.
		test.setTimeout( 90000 );
		resetRuns();

		// One `pending` run (regression #2), never ticked.
		setScript( [] );
		createPendingRunViaCli();

		// One finished run.
		resetRunsScriptOnly();
		setScript( quickComplete() );
		await gotoRuns( page );
		await waitLoaded( page );
		await startRun( page, 'A run that finishes immediately' );
		await page.waitForFunction(
			() => ! document.querySelector( '.senroflux-typing' ) && document.querySelector( '.senroflux-run-actions' ) === null,
			null,
			{ timeout: 30000 }
		);

		// One `awaiting_plan` run (parked, needs the viewer).
		setScript( planOnly() );
		await gotoRuns( page );
		await waitLoaded( page );
		await startRun( page, 'A run that parks on its plan' );
		await waitForPark( page, 'plan', 'Plan: needs your OK' );

		// Reload the list fresh and read every tab's own count off its own list.
		await gotoRuns( page );
		await waitLoaded( page );
		await assertNoSeriousA11y( page, 'run list' );

		const counts = {};
		for ( const tab of [ 'needs_you', 'running', 'finished', 'all' ] ) {
			const button = page.locator( `.senroflux-run-tab[role="tab"]`, { hasText: tabLabel( tab ) } );
			const text = await button.first().textContent();
			const match = text.match( /\((\d+)\)/ );
			expect( match, `tab "${ tab }" must render a count, including (0): "${ text }"` ).not.toBeNull();
			counts[ tab ] = parseInt( match[ 1 ], 10 );
		}

		expect( counts.needs_you + counts.running + counts.finished ).toBe( counts.all );
		expect( counts.all ).toBeGreaterThanOrEqual( 3 );
	} );
} );

function resetRunsScriptOnly() {
	// Leave the DB rows alone (we want the pending run from the previous
	// step to survive) — only clear the fake provider's own script/call log.
	wpCli( [ 'option', 'delete', 'senroflux_e2e_script' ] );
	wpCli( [ 'option', 'delete', 'senroflux_e2e_calls' ] );
}

function tabLabel( key ) {
	return {
		needs_you: 'Needs you',
		running: 'Running',
		finished: 'Finished',
		all: 'All',
	}[ key ];
}
