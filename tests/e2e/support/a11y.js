// @ts-check
const AxeBuilder = require( '@axe-core/playwright' ).default;
const { expect } = require( '@playwright/test' );

/**
 * Run axe against the Runs screen root and fail on any serious/critical
 * impact violation. Shared by every Playwright spec so each park and the
 * report get a single, consistent accessibility check.
 *
 * @param {import('@playwright/test').Page} page  Playwright page.
 * @param {string}                           label Human label for failure output (which park/state).
 * @param {string}                           [scope] CSS selector to scope the scan to. Defaults to
 *                                                    the Runs screen root; pass a different selector
 *                                                    for specs that render outside it.
 */
async function assertNoSeriousA11y( page, label, scope = '#senroflux-runs-root' ) {
	const results = await new AxeBuilder( { page } ).include( scope ).analyze();
	const serious = results.violations.filter( ( v ) => [ 'serious', 'critical' ].includes( v.impact ) );
	expect( serious, `${ label }: ${ JSON.stringify( serious, null, 2 ) }` ).toEqual( [] );
}

module.exports = { assertNoSeriousA11y };
