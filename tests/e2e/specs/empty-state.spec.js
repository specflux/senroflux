// @ts-check
const { test, expect } = require( '@playwright/test' );
const AxeBuilder = require( '@axe-core/playwright' ).default;
const { resetRuns } = require( '../support/wp-cli' );
const { gotoRuns, waitLoaded } = require( '../support/actions' );

test.describe( 'S10 empty state (built-in mode)', () => {
	test( 'shows "Nothing has run yet" with a built-in-mode promise and no tier vocabulary', async ( { page } ) => {
		resetRuns();
		await gotoRuns( page );
		await waitLoaded( page );

		await expect( page.locator( '.senroflux-empty-state h2' ) ).toHaveText( 'Nothing has run yet' );
		// Built-in mode's promise sentence never mentions the tier vocabulary.
		await expect( page.locator( '.senroflux-empty-state' ) ).not.toContainText( 'Tier' );

		const results = await new AxeBuilder( { page } ).include( '#senroflux-runs-root' ).analyze();
		const serious = results.violations.filter( ( v ) => [ 'serious', 'critical' ].includes( v.impact ) );
		expect( serious, JSON.stringify( serious, null, 2 ) ).toEqual( [] );
	} );
} );
