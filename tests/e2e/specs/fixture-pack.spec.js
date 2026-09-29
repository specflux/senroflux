// @ts-check
const { test, expect } = require( '@playwright/test' );
const { resetRuns, setScript, wpCli } = require( '../support/wp-cli' );
const { call, text } = require( '../support/scenarios' );
const {
	gotoRuns,
	waitLoaded,
	startRun,
	waitForPark,
	acceptPlan,
	approveCall,
	waitSettled,
} = require( '../support/actions' );

/**
 * S23: a third-party capability pack drives one scripted run end to end,
 * through the REST/AJAX surface, the same way `tests/Api/FixturePackTest.php`
 * proves at the unit level that a pack built from only `@api` symbols
 * registers, resolves its roles and gets its verbs tiered.
 *
 * This uses the fake provider's already-wired `senroflux-e2e` pack
 * (`tests/e2e/fake-provider/fake-provider.php`, `SenroFlux_E2E_Pack extends
 * Pack`) rather than loading `tests/Api/Fixtures/FixturePack.php` itself:
 * that PHPUnit-only fixture isn't mounted as a WordPress mu-plugin, and
 * wiring a second, separate fixture pack into wp-env just to prove the same
 * property (a plain `extends Pack` class, registered through
 * `senroflux_packs`, driving a plan -> tier-1 approval -> completed run)
 * would duplicate this spec for no new coverage. `SenroFlux_E2E_Pack` is
 * exactly that shape: name()/runCapability()/verbMap() plus the base's
 * defaults, nothing from Agent Safety or any bundled pack.
 *
 * NOT RUN by the agent that wrote this (no wp-env available here) — this is
 * unrun / unverified, wired the same way as every other spec in this suite.
 */
test.describe( 'S23: a third-party pack (senroflux-e2e) drives a run end to end', () => {
	test.beforeEach( () => {
		resetRuns();
		setScript( [
			call( 'senroflux__propose-plan', {
				goal: 'S23 extension-API check',
				steps: [ { text: 'Create the fixture thing', verbs: [ 'senroflux-e2e/create-thing' ] } ],
				assumptions: [],
			} ),
			call( 'wpab__senroflux-e2e__read-thing', { id: 'thing-1' } ),
			call( 'wpab__senroflux-e2e__create-thing', { title: 'S23 Fixture' } ),
			text( 'Done.' ),
		] );
	} );

	test( 'plan -> Tier-0 read -> Tier-1 approval -> completed, entirely through the third-party pack', async ( { page } ) => {
		await gotoRuns( page );
		await waitLoaded( page );
		await startRun( page, 'S23 extension-API check' );

		await waitForPark( page, 'plan', 'Plan: needs your OK' );
		await acceptPlan( page );

		await waitForPark( page, 'approval', 'Approve this change?' );
		await approveCall( page );

		await waitSettled( page );

		const runId = wpCli(
			[ 'db', 'query', 'SELECT id FROM wp_senroflux_runs ORDER BY id DESC LIMIT 1', '--skip-column-names' ]
		).trim();
		expect( runId ).not.toBe( '' );

		const status = wpCli(
			[ 'db', 'query', `SELECT status FROM wp_senroflux_runs WHERE id = ${ runId }`, '--skip-column-names' ]
		).trim();
		expect( status ).toBe( 'completed' );

		// The run's pack is the third-party one, not a bundled one — proves
		// the run was actually driven through senroflux_packs, not a
		// packless direct-allow path.
		const pack = wpCli(
			[ 'db', 'query', `SELECT pack FROM wp_senroflux_runs WHERE id = ${ runId }`, '--skip-column-names' ]
		).trim();
		expect( pack ).toBe( 'senroflux-e2e' );

		await expect( page.locator( '.senroflux-chat-bubble-bot' ).last() ).toContainText( 'Done.' );
	} );
} );
