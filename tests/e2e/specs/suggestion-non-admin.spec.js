// @ts-check
const { test, expect } = require( '@playwright/test' );
const { resetRuns, setScript, wpCli } = require( '../support/wp-cli' );
const { fullTour } = require( '../support/scenarios' );
const { gotoRuns, waitLoaded, startRun, waitForPark, answerChoice, acceptPlan, approveCall, waitSettled } = require( '../support/actions' );
const { assertNoSeriousA11y } = require( '../support/a11y' );
const { BASE_URL } = require( '../support/global-setup' );

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
		await assertNoSeriousA11y( page, 'plan park' );
		await acceptPlan( page );
		await waitForPark( page, 'question', 'Question for you' );
		await assertNoSeriousA11y( page, 'question park' );
		await answerChoice( page, 'Launch Day' );
		await waitForPark( page, 'approval', 'Approve this change?' );
		await assertNoSeriousA11y( page, 'approval park' );
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

		// Log in via HTTP POST on the context's own request API (same
		// pattern as global-setup.js / locale-users.js) instead of filling
		// the wp-login form: wp-login.php's wp_attempt_focus() steals focus
		// back to #user_login ~200ms after load, and Playwright's fill()
		// commits its typed text to whatever is focused at the moment it
		// runs, not necessarily the field it just focused. Under load the
		// timer can win the race, the password lands in #user_login instead
		// of #user_pass, the required-field check blocks the submit, and
		// the next goto() silently lands back on wp-login — a 120s timeout
		// with no indication login ever failed. A POST has no such race.
		await editorContext.request.post( '/wp-login.php', {
			form: {
				log: EDITOR_USER,
				pwd: EDITOR_PASS,
				'wp-submit': 'Log In',
				redirect_to: `${ BASE_URL }/wp-admin/`,
				testcookie: '1',
			},
		} );

		// Fail loudly here, not 120s later at an unrelated selector wait,
		// if the login above did not actually authenticate the context.
		const cookies = await editorContext.cookies();
		const loggedIn = cookies.some( ( cookie ) => cookie.name.startsWith( 'wordpress_logged_in_' ) );
		expect( loggedIn, 'Editor login POST to /wp-login.php did not set a wordpress_logged_in_ cookie' ).toBe( true );

		await editorPage.goto( `/wp-admin/admin.php?page=senroflux-runs&run_id=${ runId }` );
		await editorPage.waitForSelector( '#senroflux-runs-root' );
		await editorPage.waitForSelector( '.senroflux-loading', { state: 'detached' } ).catch( () => {} );

		await assertNoSeriousA11y( editorPage, 'non-admin report view' );

		const suggestion = editorPage.locator( '.senroflux-suggestion-card' );
		await expect( suggestion ).toBeVisible();
		await expect( suggestion ).toContainText( 'An administrator can add this to the site brief.' );
		await expect( suggestion.locator( 'button' ) ).toHaveCount( 0 );

		await editorContext.close();
	} );
} );
