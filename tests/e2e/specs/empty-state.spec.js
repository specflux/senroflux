// @ts-check
const { test, expect } = require( '@playwright/test' );
const { resetRuns, wpCli } = require( '../support/wp-cli' );
const { gotoRuns, waitLoaded } = require( '../support/actions' );
const { assertNoSeriousA11y } = require( '../support/a11y' );

test.describe( 'S10 empty state (built-in mode)', () => {
	test( 'shows "Nothing has run yet" with a built-in-mode promise and no tier vocabulary', async ( { page } ) => {
		resetRuns();
		await gotoRuns( page );
		await waitLoaded( page );

		await expect( page.locator( '.senroflux-empty-state h2' ) ).toHaveText( 'Nothing has run yet' );
		// Built-in mode's promise sentence never mentions the tier vocabulary.
		await expect( page.locator( '.senroflux-empty-state' ) ).not.toContainText( 'Tier' );

		await assertNoSeriousA11y( page, 'empty state' );
	} );

	// J1: dismiss the Agent Safety advisory, reload, it stays gone.
	test( 'the SenroGate advisory can be dismissed, and stays dismissed after a reload', async ( { page } ) => {
		const meta = 'senroflux_agent_safety_check_dismissed';
		// `wp user meta delete` fails when the key is absent; that is fine here.
		const clear = () => {
			try {
				wpCli( [ 'user', 'meta', 'delete', '1', meta ] );
			} catch {
				// Already absent.
			}
		};
		clear();
		try {
			await gotoRuns( page );
			await waitLoaded( page );

			const advisory = page.locator( '.senroflux-setup-check[data-check-id="senroflux/agent-safety"]' );
			await expect( advisory ).toBeVisible();
			await advisory.getByRole( 'button', { name: 'Dismiss' } ).click();
			await expect( advisory ).toHaveCount( 0 );
			// Focus moves to the goal box (the panel has no other focusable element here).
			await expect( page.locator( '.senroflux-message-box-input' ) ).toBeFocused();

			// A tab switch back (focus refresh) must not bring it back either.
			await page.evaluate( () => window.dispatchEvent( new Event( 'focus' ) ) );
			await page.waitForTimeout( 1500 );
			await expect( advisory ).toHaveCount( 0 );

			await page.reload();
			await waitLoaded( page );
			await expect( page.locator( '#senroflux-setup-panel' ) ).toHaveCount( 1 );
			await expect( advisory ).toHaveCount( 0 );
		} finally {
			clear();
		}
	} );
} );
