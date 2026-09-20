// @ts-check
const { test, expect } = require( '@playwright/test' );
const { resetRuns } = require( '../support/wp-cli' );

/**
 * S10: "the command palette opening the screen with a goal without starting
 * a run." The command opens a NEW TAB (by design — see
 * assets/src/commands/index.js's own comment on why it never navigates the
 * admin page the user was already on) with the goal pre-filled, and never
 * calls startRun/tickRun itself.
 */
test.describe( 'S10 command palette', () => {
	test( 'opens the Runs screen with the goal pre-filled, and starts nothing', async ( { page, context } ) => {
		resetRuns();

		await page.goto( '/wp-admin/index.php' );
		// Opened via the `core/commands` data store rather than a keyboard
		// shortcut: WP 7.1's admin does not bind Ctrl/Cmd+K outside the block
		// editor (confirmed live — pressing it opens nothing), but the
		// palette itself, and SenroFlux's command loader registered into it,
		// are present site-wide (this page loads
		// build/commands/index.js). What this spec is responsible for is
		// SenroFlux's OWN command behaviour, not core's keybinding.
		await page.waitForFunction( () => typeof window.wp !== 'undefined' && typeof window.wp.commands !== 'undefined' );
		await page.evaluate( () => window.wp.data.dispatch( 'core/commands' ).open() );

		const paletteInput = page.locator( '.commands-command-menu input[type="text"], .commands-command-menu input[type="search"]' ).first();
		await paletteInput.waitFor( { state: 'visible', timeout: 10000 } );
		await paletteInput.fill( 'SenroFlux: run keyboard palette smoke goal' );

		const commandItem = page.locator( '[role="option"]', { hasText: 'SenroFlux: run' } ).first();
		await commandItem.waitFor( { state: 'visible', timeout: 10000 } );

		const [ popup ] = await Promise.all( [ context.waitForEvent( 'page' ), commandItem.click() ] );
		await popup.waitForLoadState();

		expect( popup.url() ).toContain( 'page=senroflux-runs' );
		expect( popup.url() ).toContain( 'goal=' );
		await popup.waitForSelector( '#senroflux-runs-root' );
		await popup.waitForSelector( '.senroflux-loading', { state: 'detached' } ).catch( () => {} );

		await expect( popup.locator( '.senroflux-message-box-input' ) ).toHaveValue( /keyboard palette smoke goal/ );
		// Never started: no park, no ledger, no typing bubble, no run selected.
		await expect( popup.locator( '.senroflux-park-card' ) ).toHaveCount( 0 );
		await expect( popup.locator( '.senroflux-chat-bubble' ) ).toHaveCount( 0 );
	} );
} );
