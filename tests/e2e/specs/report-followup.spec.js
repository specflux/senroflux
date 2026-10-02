// @ts-check
const { test, expect } = require( '@playwright/test' );
const { setScript, resetRuns, dbQuery, wpCli } = require( '../support/wp-cli' );
const { unverifiedWrite, writeThenQuestion } = require( '../support/scenarios' );
const { assertNoSeriousA11y } = require( '../support/a11y' );
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
 * Stage 22b: the report view's "Not checked after the change" state, and a
 * follow-up run started from the screen (S12, S20, J15).
 */
test.describe( 'S12 the report shows a write that was never re-read', () => {
	test( '"Not checked after the change" is said in text, counted in the summary, and keeps its place in the list', async ( { page } ) => {
		resetRuns();
		setScript( unverifiedWrite() );

		await gotoRuns( page );
		await waitLoaded( page );
		await startRun( page, 'Build the launch page' );

		await waitForPark( page, 'plan', 'Plan: needs your OK' );
		await acceptPlan( page );
		await waitForPark( page, 'approval', 'Approve this change?' );
		await approveCall( page );
		await waitSettled( page );

		const report = page.locator( '.senroflux-report' );
		await expect( report ).toBeVisible( { timeout: 30000 } );
		await expect( report.locator( '.senroflux-report-summary' ) ).toHaveText( '1 change, 1 not checked' );

		const row = report.locator( '.senroflux-report-change.is-unchecked' );
		await expect( row ).toHaveCount( 1 );
		await expect( row.locator( '.senroflux-report-state' ) ).toHaveText( 'Not checked after the change' );
		// Distinguishable without colour: the mark differs from a verified
		// row's and is hidden from assistive tech; the words carry the state.
		await expect( row.locator( '.senroflux-report-mark' ) ).not.toHaveText( '✓' );
		await expect( row.locator( '.senroflux-report-mark' ) ).toHaveAttribute( 'aria-hidden', 'true' );
		await expect( report.locator( '.is-verified' ) ).toHaveCount( 0 );
		await assertNoSeriousA11y( page, 'report with a not-checked row' );
	} );
} );

test.describe( 'S20 a follow-up run starts from a cancelled run', () => {
	test( 'same pack, created with follow_up_of, seeded with the source run\'s object list', async ( { page } ) => {
		resetRuns();
		setScript( writeThenQuestion() );

		await gotoRuns( page );
		await waitLoaded( page );
		await startRun( page, 'Build the launch page' );

		await waitForPark( page, 'plan', 'Plan: needs your OK' );
		await acceptPlan( page );
		await waitForPark( page, 'approval', 'Approve this change?' );
		await approveCall( page );
		await waitForPark( page, 'question', 'Question for you' );

		// A run still parked offers no follow-up.
		await expect( page.getByRole( 'button', { name: 'Start a follow-up' } ) ).toHaveCount( 0 );

		const sourceId = dbQuery( 'SELECT id FROM wp_senroflux_runs ORDER BY id DESC LIMIT 1' );
		await page.getByRole( 'button', { name: 'Cancel run' } ).click();

		const followUpButton = page.getByRole( 'button', { name: 'Start a follow-up' } );
		await expect( followUpButton ).toBeVisible( { timeout: 30000 } );
		await expect( page.locator( '.senroflux-report-summary' ) ).toContainText( 'not checked' );
		await followUpButton.click();

		const box = page.locator( '.senroflux-message-box-input' );
		await expect( page.locator( '.senroflux-followup-note' ) ).toContainText( `Follow-up to run #${ sourceId }` );
		await expect( box ).toHaveValue( '' );
		await expect( box ).toBeFocused();
		await assertNoSeriousA11y( page, 'follow-up affordance' );

		await box.fill( 'Follow up on the launch page' );
		await page.locator( '.senroflux-message-box button:has-text("Start run")' ).click();
		await waitForPark( page, 'plan', 'Plan: needs your OK' );

		const row = dbQuery( 'SELECT follow_up_of, pack FROM wp_senroflux_runs ORDER BY id DESC LIMIT 1' ).split( '\t' );
		expect( row[ 0 ] ).toBe( sourceId );
		expect( row[ 1 ] ).toBe( 'senroflux-e2e' );

		// The harness-built object list reached the model's first turn.
		const prompts = JSON.parse( wpCli( [ 'option', 'get', 'senroflux_e2e_prompts', '--format=json' ] ) );
		const followUpPrompt = prompts[ prompts.length - 1 ];
		expect( followUpPrompt ).toContain( 'An earlier run touched these objects' );
		expect( followUpPrompt ).toContain( 'thing-3' );
		expect( followUpPrompt ).toContain( 'Follow up on the launch page' );
	} );
} );
