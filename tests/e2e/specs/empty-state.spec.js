// @ts-check
const { test, expect } = require( '@playwright/test' );
const { resetRuns } = require( '../support/wp-cli' );
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
} );
