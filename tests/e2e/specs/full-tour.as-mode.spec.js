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

test.describe( 'S10/S12 full run tour (Agent Tollgate mode)', () => {
	test.beforeEach( () => {
		resetRuns();
		setScript( fullTour() );
	} );

	test( 'the SAME tour shows the tier badge, because Agent Tollgate is the active gate', async ( { page } ) => {
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
		//
		// 0.3 S23: scoped to the approval park card itself. Since S23 added a
		// `tier` to tool_result steps, the Tier-0 ledger rows collapsed above
		// this park ALSO carry a (collapsed, hence hidden) tier badge, and an
		// unscoped `[data-testid="tier-badge"]` matches one of those first —
		// this assertion means the approval card's own badge, not whichever
		// badge happens to be first in DOM order.
		const approvalTierBadge = page.locator( '.senroflux-park-card [data-testid="tier-badge"]' ).first();
		await expect( approvalTierBadge ).toBeVisible();
		await expect( approvalTierBadge ).toContainText( 'Tier 1' );
		await assertNoSeriousA11y( page, 'approval park' );
		await approveCall( page );

		await waitSettled( page );
		await assertNoSeriousA11y( page, 'terminal report view' );
	} );
} );
