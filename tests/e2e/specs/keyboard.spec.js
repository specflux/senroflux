// @ts-check
const { test, expect } = require( '@playwright/test' );
const { resetRuns, setScript } = require( '../support/wp-cli' );
const { keyboardTour } = require( '../support/scenarios' );
const { gotoRuns, waitLoaded } = require( '../support/actions' );

/**
 * S22: "A keyboard-only spec goes from palette launch through question, plan
 * and approval parks, asserting focus lands on each park heading." The
 * palette itself is covered separately (palette.spec.js, S10: it must never
 * start a run) — this spec starts from the Runs screen already open (as the
 * palette would leave it, goal pre-filled) and drives the whole run with the
 * keyboard only, checking focus after each park.
 */
test.describe( 'S22 keyboard-only run: question, plan, approval', () => {
	test( 'focus lands on the park heading at every park, using only the keyboard', async ( { page } ) => {
		resetRuns();
		setScript( keyboardTour() );

		await gotoRuns( page );
		await waitLoaded( page );

		// Tab to the message box, type the goal, submit with Enter — no mouse.
		await page.locator( '.senroflux-message-box-input' ).focus();
		await page.keyboard.type( 'Build the launch page' );
		await page.keyboard.press( 'Tab' ); // to the Start run button
		await page.keyboard.press( 'Enter' );

		// --- Question park: focus on its heading -------------------------
		await page.locator( '#senroflux-park-heading' ).waitFor( { state: 'visible', timeout: 30000 } );
		await expect( page.locator( '#senroflux-park-heading' ) ).toHaveText( 'Question for you' );
		await expect( page.locator( '#senroflux-park-heading' ) ).toBeFocused();

		await page.keyboard.press( 'Tab' ); // into the choice radiogroup
		await page.keyboard.press( 'Space' ); // pick the first radio (News)
		// Tab to the Answer button and press it.
		while ( ! ( await page.locator( '.senroflux-park-actions button:has-text("Answer")' ).evaluate( ( el ) => el === document.activeElement ) ) ) {
			await page.keyboard.press( 'Tab' );
		}
		await page.keyboard.press( 'Enter' );

		// --- Plan park: focus on its heading ------------------------------
		await page.locator( '#senroflux-park-heading' ).waitFor( { state: 'visible', timeout: 30000 } );
		await expect( page.locator( '#senroflux-park-heading' ) ).toHaveText( 'Plan: needs your OK' );
		await expect( page.locator( '#senroflux-park-heading' ) ).toBeFocused();

		while ( ! ( await page.locator( '.senroflux-park-actions button:has-text("Accept plan")' ).evaluate( ( el ) => el === document.activeElement ) ) ) {
			await page.keyboard.press( 'Tab' );
		}
		await page.keyboard.press( 'Enter' );

		// --- Approval park: focus on its heading --------------------------
		await page.locator( '#senroflux-park-heading' ).waitFor( { state: 'visible', timeout: 30000 } );
		await expect( page.locator( '#senroflux-park-heading' ) ).toHaveText( 'Approve this change?' );
		await expect( page.locator( '#senroflux-park-heading' ) ).toBeFocused();

		while ( ! ( await page.locator( '.senroflux-park-actions button:has-text("Approve")' ).evaluate( ( el ) => el === document.activeElement ) ) ) {
			await page.keyboard.press( 'Tab' );
		}
		await page.keyboard.press( 'Enter' );

		await expect( page.locator( '.senroflux-park-card' ) ).toHaveCount( 0, { timeout: 30000 } );
	} );
} );
