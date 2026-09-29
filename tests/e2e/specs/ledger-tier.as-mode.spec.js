// @ts-check
const { test, expect } = require( '@playwright/test' );
const { resetRuns, setScript } = require( '../support/wp-cli' );
const { fullTour } = require( '../support/scenarios' );
const {
	gotoRuns,
	waitLoaded,
	startRun,
	waitForPark,
	answerChoice,
	acceptPlan,
	approveCall,
	waitSettled,
} = require( '../support/actions' );
const { assertNoSeriousA11y } = require( '../support/a11y' );

/**
 * S10 asks for a tier badge on each expanded ledger row in Agent Safety mode
 * ("each call shows a label, the ability id, a tier badge (AS mode only) and
 * the result").
 *
 * 0.3 S23: closed. `Runner::appendToolResult()` now writes a `tier` key into
 * the tool_result step's own message payload (mirroring the `tier` an
 * `approval`-kind step's context already carried), and `Plugin::get()` lifts
 * it onto the step itself (`step.tier`) for every kind — see
 * `src/Plugin.php` and `src/Run/Runner.php::appendToolResult()`. A call that
 * ran without parking (Tier 0, or pre-approved under a grant) now has its
 * tier written where the screen can read it, so the ledger row shows one.
 */
test.describe( 'S23: ledger rows carry a tier badge in Agent Safety mode', () => {
	test.beforeEach( () => {
		resetRuns();
		setScript( fullTour() );
	} );

	test(
		'an expanded ledger row shows its tier in Agent Safety mode',
		async ( { page } ) => {
			await gotoRuns( page );
			await waitLoaded( page );
			await startRun( page, 'Build the launch page' );
			await waitForPark( page, 'plan', 'Plan: needs your OK' );
			await acceptPlan( page );
			await waitForPark( page, 'question', 'Question for you' );
			await answerChoice( page, 'Launch Day' );
			await waitForPark( page, 'approval', 'Approve this change?' );
			await assertNoSeriousA11y( page, 'approval park' );
			await approveCall( page );
			await waitSettled( page );
			await assertNoSeriousA11y( page, 'terminal report view' );

			await expect(
				page.locator( '.senroflux-ledger-group [data-testid="tier-badge"]' ).first()
			).toBeVisible( { timeout: 5000 } );
		}
	);
} );
