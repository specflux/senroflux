// @ts-check
const { test, expect } = require( '@playwright/test' );
const { resetRuns, setScript, wpCli } = require( '../support/wp-cli' );
const { planOnly } = require( '../support/scenarios' );
const { waitLoaded, waitForPark, acceptPlan } = require( '../support/actions' );
const { assertNoSeriousA11y } = require( '../support/a11y' );

/**
 * Regression #5: `wp_localize_script()` casts every scalar to a STRING, so
 * `config.initialRunId` from a deep link (`?run_id=`) used to arrive as
 * `"1"` while every run id from REST/ajax is a number — every tick result
 * was silently discarded as stale (fixed at 450863d). This asserts the
 * FIX'S OBSERVABLE BEHAVIOUR: a park resolved on a deep-linked run updates
 * the screen IN PLACE, with no page reload.
 */
test.describe( 'S10 a deep-linked run updates after a park resolution without reloading', () => {
	test( 'the park card disappears and the list refreshes in-page', async ( { page } ) => {
		resetRuns();
		setScript( planOnly() );

		const out = wpCli( [
			'eval',
			'wp_set_current_user(1); $p = \\Specflux\\SenroFlux\\Http\\ConsumerPolicy::resolve("senroflux-admin", []); $r = senroflux()->start("senroflux-admin", "Deep-linked run", $p["allow"], $p["budget"]); senroflux()->tick($r["run"]["id"], $r["run"]["step_count"], null); echo $r["run"]["id"];',
		] );
		const runId = out.trim().split( /\s+/ ).pop();
		expect( runId ).toMatch( /^\d+$/ );

		// Mark this page instance so a full reload (the bug's fallback path)
		// would be detectable — Playwright's own navigation tracking is used
		// instead, via `page.waitForNavigation` racing a timeout.
		let navigated = false;
		page.on( 'framenavigated', ( frame ) => {
			if ( frame === page.mainFrame() ) {
				navigated = true;
			}
		} );

		await page.goto( `/wp-admin/admin.php?page=senroflux-runs&run_id=${ runId }` );
		await waitLoaded( page );
		navigated = false; // Ignore the initial deep-link navigation itself.

		await waitForPark( page, 'plan', 'Plan: needs your OK' );
		await assertNoSeriousA11y( page, 'plan park' );
		await acceptPlan( page );

		await expect( page.locator( '.senroflux-park-card' ) ).toHaveCount( 0, { timeout: 30000 } );
		expect( navigated, 'resolving the park must update the screen in place, without a page reload' ).toBe( false );
	} );
} );
