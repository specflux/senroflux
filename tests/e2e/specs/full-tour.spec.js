// @ts-check
const { test, expect } = require( '@playwright/test' );
const { setScript, resetRuns } = require( '../support/wp-cli' );
const { fullTour } = require( '../support/scenarios' );
const { wpCli } = require( '../support/wp-cli' );
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

test.describe( 'S10/S12 full run tour (built-in mode)', () => {
	test.beforeEach( () => {
		resetRuns();
		setScript( fullTour() );
	} );

	test( 'plan, question, ledger collapse, approval, suggestion, terminal state — with no tier vocabulary', async ( { page } ) => {
		await gotoRuns( page );
		await waitLoaded( page );
		await startRun( page, 'Build the launch page' );

		// --- Plan park -------------------------------------------------
		await waitForPark( page, 'plan', 'Plan: needs your OK' );
		await expect( page.locator( '.senroflux-plan-steps li' ) ).toContainText( 'Create the launch post' );
		await assertNoSeriousA11y( page, 'plan park' );
		await acceptPlan( page );

		// --- Question park ----------------------------------------------
		await waitForPark( page, 'question', 'Question for you' );
		await assertNoSeriousA11y( page, 'question park' );
		await answerChoice( page, 'Launch Day' );

		// --- Tier-0 calls collapse into one ledger group -----------------
		// (regression #1: a REAL run interleaves model/tool_result steps).
		// The plan's own tool_result is its OWN separate 1-action group (a
		// different, earlier ledger row). The group under test is 4 actions,
		// not 3: the ask-user resolution's own tool_result has no visible
		// model prose before the three read/list/search calls either, so it
		// collapses in with them too — correct behaviour, since it IS a
		// consecutive tool_result with nothing user-visible between it and
		// the next one (the same rule the six-row live-review defect was
		// about). Find the group that actually collapsed them, rather than
		// assuming it is the first one on the page.
		await waitForPark( page, 'approval', 'Approve this change?' );
		const ledgerGroup = page.locator( '.senroflux-ledger-group', { hasText: '4 actions' } );
		await expect( ledgerGroup ).toHaveCount( 1 );
		await expect( ledgerGroup.locator( 'summary' ) ).toContainText( '4 actions' );
		await expect( ledgerGroup.locator( 'summary' ) ).toContainText( 'Search things' );
		// regression #4: the ledger LABEL is human-readable, never the raw
		// ability id doing double duty as its own label (S10 spec: expanded,
		// each call shows a label AND the ability id, as two separate
		// fields — the ability id is expected to appear too, in
		// .senroflux-ledger-verb; the defect this guards against is the
		// label field itself falling back to the raw id).
		const expandedLabel = ledgerGroup.locator( '.senroflux-ledger-label' ).last();
		await expect( expandedLabel ).toHaveText( 'Search things' );
		await expect( expandedLabel ).not.toContainText( 'wpab__' );

		// --- Approval park: tier badge ABSENT (built-in mode), args wrap in full (regression #6) ---
		await expect( page.locator( '[data-testid="tier-badge"]' ) ).toHaveCount( 0 );
		const argsPre = page.locator( '.senroflux-args' );
		await expect( argsPre ).toContainText( 'Launch Day' );
		const overflow = await argsPre.evaluate( ( el ) => getComputedStyle( el ).overflowWrap );
		expect( overflow ).toBe( 'anywhere' );
		await assertNoSeriousA11y( page, 'approval park' );
		await approveCall( page );

		// --- Suggestion card (admin: Save/Dismiss) ----------------------
		await waitSettled( page );

		// KNOWN DEFECT (see known-defects.spec.js): the suggestion created
		// by this same tick is NOT visible yet here — `Runner::tick()`'s
		// ajax response shape is `{run, new_steps, ui}` with no
		// `suggestions` key, and `App.js`'s `applyRunState()` only ever
		// merges that shape, so `runDetail.suggestions` stays whatever the
		// last full `getRun()` fetch had (empty, from before this run
		// started). Reaching the suggestion at all currently requires a
		// fresh `getRun()` — reselecting the run, exactly as a real reload
		// would. This is the realistic path; the live-tick gap is its own
		// tracked, separately-asserted defect, not silently routed around.
		const runId = wpCli( [ 'db', 'query', 'SELECT id FROM wp_senroflux_runs ORDER BY id DESC LIMIT 1', '--skip-column-names' ] ).trim();
		await page.goto( `/wp-admin/admin.php?page=senroflux-runs&run_id=${ runId }` );
		await waitLoaded( page );

		const suggestion = page.locator( '.senroflux-suggestion-card' );
		await expect( suggestion ).toBeVisible();
		await expect( suggestion ).toContainText( 'Mention the launch post' );
		await expect( suggestion.locator( 'button:has-text("Save")' ) ).toBeVisible();
		await expect( suggestion.locator( 'button:has-text("Dismiss")' ) ).toBeVisible();

		// --- Terminal run: no Cancel button, report/report-a11y --------
		await expect( page.locator( '.senroflux-run-actions button' ) ).toHaveCount( 0 );
		await expect( page.locator( '.senroflux-chat-stream' ) ).toContainText( 'All done' );
		await assertNoSeriousA11y( page, 'terminal report view' );
	} );
} );
