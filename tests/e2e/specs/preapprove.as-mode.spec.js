// @ts-check
const { test, expect } = require( '@playwright/test' );
const { setScript, resetRuns, setOption, deleteOption, dbQuery } = require( '../support/wp-cli' );
const { publishTwice } = require( '../support/scenarios' );
const { gotoRuns, waitLoaded, startRun, waitForPark, acceptPlan, waitSettled } = require( '../support/actions' );

/**
 * Proof shakedown (journey J3, S14): the plan card must offer "Accept and
 * pre-approve" whenever `senroflux_enable_preapproval` AND Agent Safety's
 * `agent_safety_enable_grants` are on — including after a page reload, since
 * the plan step's stored message carries only the plan, not the offer — and
 * accepting it must mean no approval park follows for the granted verb.
 *
 * The fake-provider mu-plugin turns both switches on from the
 * `senroflux_e2e_preapproval` option (and registers the Tier-2
 * `senroflux-e2e/publish-thing` fixture ability, the only tier a grant covers).
 *
 * UNRUN: written in a worktree that cannot reach the e2e environment. The
 * fixture change needs a wp-env restart (single-file mu-plugin mount); run
 * `npx playwright test tests/e2e/specs/preapprove.as-mode.spec.js` after merge.
 */
test.describe( 'S14 pre-approval from the plan card (Agent Safety mode)', () => {
	test.afterEach( () => {
		deleteOption( 'senroflux_e2e_preapproval' );
	} );

	test( 'offers it after a reload, and accepting it parks no approval for the granted verb', async ( { page } ) => {
		resetRuns();
		setOption( 'senroflux_e2e_preapproval', '1' );
		setScript( publishTwice() );

		await gotoRuns( page );
		await waitLoaded( page );
		await startRun( page, 'Publish two things' );
		await waitForPark( page, 'plan', 'Plan: needs your OK' );

		// Reload-shaped state: the run is selected from the list, its detail
		// comes from the REST read, not from a tick response.
		const runId = dbQuery( 'SELECT id FROM wp_senroflux_runs ORDER BY id DESC LIMIT 1' );
		await gotoRuns( page, `&run_id=${ Number( runId ) }` );
		await waitLoaded( page );
		await waitForPark( page, 'plan', 'Plan: needs your OK' );

		const preapprove = page.getByRole( 'radio', { name: /accept and pre-approve/i } );
		await expect( preapprove ).toBeVisible();
		await expect( page.locator( '.senroflux-plan-decision' ) ).toContainText( '24 hours' );
		await preapprove.check();
		await acceptPlan( page );

		// No approval park for the granted verb: the run settles on its own.
		await waitSettled( page );
		await expect( page.locator( '#senroflux-park-heading', { hasText: 'Approve this change?' } ) ).toHaveCount( 0 );

		const status = dbQuery( `SELECT status FROM wp_senroflux_runs WHERE id = ${ Number( runId ) }` );
		expect( status ).toBe( 'completed' );
		const approvals = dbQuery( `SELECT COUNT(*) FROM wp_senroflux_steps WHERE run_id = ${ Number( runId ) } AND kind = 'approval'` );
		expect( Number( approvals ) ).toBe( 0 );
		expect( dbQuery( "SELECT option_value FROM wp_options WHERE option_name = 'senroflux_e2e_things_created'" ) ).toBe( '2' );
	} );

	test( 'offers no pre-approve option while the switches are off', async ( { page } ) => {
		resetRuns();
		deleteOption( 'senroflux_e2e_preapproval' );
		setScript( publishTwice() );

		await gotoRuns( page );
		await waitLoaded( page );
		await startRun( page, 'Publish two things' );
		await waitForPark( page, 'plan', 'Plan: needs your OK' );

		await expect( page.getByRole( 'radio', { name: 'Accept', exact: true } ) ).toBeVisible();
		await expect( page.getByRole( 'radio', { name: /pre-approve/i } ) ).toHaveCount( 0 );
	} );
} );
