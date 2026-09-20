// @ts-check
const { test, expect } = require( '@playwright/test' );
const { resetRuns, setScript, wpCli } = require( '../support/wp-cli' );
const { fullTour } = require( '../support/scenarios' );
const { gotoRuns, waitLoaded, startRun, waitForPark, answerChoice, acceptPlan, approveCall, waitSettled } = require( '../support/actions' );

const EDITOR_USER = 'senroflux-e2e-editor';
const EDITOR_PASS = 'senroflux-e2e-editor-pass';

function ensureEditor() {
	try {
		wpCli( [ 'user', 'create', EDITOR_USER, 'senroflux-e2e-editor@example.test', '--role=editor', `--user_pass=${ EDITOR_PASS }` ] );
	} catch ( e ) {
		// Already exists from a previous run — fine, just reset the password.
		wpCli( [ 'user', 'update', EDITOR_USER, `--user_pass=${ EDITOR_PASS }` ] );
	}
}

/**
 * S12: "a non-admin sees copyable text with 'An administrator can add this
 * to the site brief' and no buttons." `canManageSiteBrief` is
 * server-computed `manage_options` (S20) — an Editor lacks it, so this is a
 * real non-admin, not a role guess on the client.
 */
test.describe( 'S12 brief-suggestion card, non-admin viewer', () => {
	test( 'an Editor sees the suggested text as read-only, with no Save/Dismiss buttons', async ( { page, browser } ) => {
		resetRuns();
		setScript( fullTour() );
		ensureEditor();

		await gotoRuns( page );
		await waitLoaded( page );
		await startRun( page, 'Build the launch page' );
		await waitForPark( page, 'plan', 'Plan: needs your OK' );
		await acceptPlan( page );
		await waitForPark( page, 'question', 'Question for you' );
		await answerChoice( page, 'Launch Day' );
		await waitForPark( page, 'approval', 'Approve this change?' );
		await approveCall( page );
		await waitSettled( page );

		// The React screen never pushes run_id into the address bar (no
		// client routing), so read the just-started run's id straight from
		// the DB rather than guessing it from `page.url()`.
		const idOut = wpCli( [ 'db', 'query', 'SELECT id FROM wp_senroflux_runs ORDER BY id DESC LIMIT 1', '--skip-column-names' ] );
		const runId = idOut.trim();
		expect( runId ).toMatch( /^\d+$/ );

		// A second context that must start with NO cookies. `storageState:
		// undefined` is load-bearing: this project sets `use.storageState` to
		// the admin's saved session, and a context that inherits it is still
		// the administrator no matter what is typed into wp-login. That is
		// how this spec silently tested the admin view of the card and
		// reported a pass.
		const editorContext = await browser.newContext( { storageState: undefined } );
		const editorPage = await editorContext.newPage();
		await editorPage.goto( '/wp-login.php' );
		await editorPage.fill( '#user_login', EDITOR_USER );
		await editorPage.fill( '#user_pass', EDITOR_PASS );
		await editorPage.click( '#wp-submit' );

		await editorPage.goto( `/wp-admin/admin.php?page=senroflux-runs&run_id=${ runId }` );
		await editorPage.waitForSelector( '#senroflux-runs-root' );
		await editorPage.waitForSelector( '.senroflux-loading', { state: 'detached' } ).catch( () => {} );

		const suggestion = editorPage.locator( '.senroflux-suggestion-card' );
		await expect( suggestion ).toBeVisible();
		await expect( suggestion ).toContainText( 'An administrator can add this to the site brief.' );
		await expect( suggestion.locator( 'button' ) ).toHaveCount( 0 );

		await editorContext.close();
	} );
} );
