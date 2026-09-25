// @ts-check
const { test, expect } = require( '@playwright/test' );
const { setScript, resetRuns } = require( '../support/wp-cli' );
const { fullTour } = require( '../support/scenarios' );
const { assertNoSeriousA11y } = require( '../support/a11y' );
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

test.describe( 'S10/S12 full run tour (Agent Safety mode)', () => {
	test.beforeEach( () => {
		resetRuns();
		setScript( fullTour() );
	} );

	test( 'the SAME tour shows the tier badge, because Agent Safety is the active gate', async ( { page } ) => {
		await gotoRuns( page );
		await waitLoaded( page );
		await startRun( page, 'Build the launch page' );

		await waitForPark( page, 'plan', 'Plan: needs your OK' );
		await assertNoSeriousA11y( page, 'plan park' );
		await acceptPlan( page );

		await waitForPark( page, 'question', 'Question for you' );
		await assertNoSeriousA11y( page, 'question park' );
		await answerChoice( page, 'Launch Day' );

		await waitForPark( page, 'approval', 'Approve this change?' );
		// The single most important assertion of this stage: the tier badge
		// APPEARS in Agent Safety mode, on the very run that started in it.
		await expect( page.locator( '[data-testid="tier-badge"]' ).first() ).toBeVisible();
		await expect( page.locator( '[data-testid="tier-badge"]' ).first() ).toContainText( 'Tier 1' );
		await assertNoSeriousA11y( page, 'approval park' );
		await approveCall( page );

		await waitSettled( page );
		// S10 also asks for a tier badge on each expanded LEDGER row in Agent
		// Safety mode. That is not implementable today — the step payload
		// carries no tier — so it lives in known-defects.spec.js as a
		// `test.fail()` case rather than being asserted here or quietly
		// dropped. See that file for the root cause.
		await assertNoSeriousA11y( page, 'terminal report view' );
	} );
} );
