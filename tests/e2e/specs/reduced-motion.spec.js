// @ts-check
const { test, expect } = require( '@playwright/test' );
const { resetRuns, setScript } = require( '../support/wp-cli' );
const { planOnly } = require( '../support/scenarios' );
const { gotoRuns, waitLoaded, startRun, waitForPark } = require( '../support/actions' );
const { assertNoSeriousA11y } = require( '../support/a11y' );

/**
 * S22: "every CSS transition and animation sits inside
 * `@media (prefers-reduced-motion: no-preference)`. One spec with
 * `reducedMotion: 'reduce'` asserts computed `transition-duration` is `0s`
 * on the typing bubble and park card."
 *
 * `assets/src/runs/style.css` has no transitions at all, and exactly one
 * animation: the typing indicator's `::after` blink, guarded by
 * `@media not ( prefers-reduced-motion: reduce )`. A `transition-duration`
 * check alone would pass even with the guard deleted, since nothing here
 * ever declares a transition — so this spec additionally asserts the
 * computed `animation-name` of the typing indicator's `::after` pseudo-
 * element: `none` under `reduce`, and the real keyframe name under
 * `no-preference` (the control case — without it, the `reduce` assertion
 * proves nothing, since `none` would also be what a broken/never-applied
 * selector produces).
 *
 * The typing bubble (`.senroflux-typing`, `Chat.js`) renders only while a
 * tick round-trip is in flight (`busy && ! park`) — a `planOnly` scenario
 * parks on its very first turn, so the bubble is visible for the single
 * round trip between clicking "Start run" and the plan park appearing.
 * Against this worktree's own wp-env that round trip is fast enough
 * (observed empirically) that the busy render can commit and unmount
 * between two Playwright DOM polls with nothing left to assert against —
 * so the `senroflux_start` admin-ajax POST is deliberately delayed via
 * `page.route()` for this spec only, to give the busy state time to paint.
 * This delays only this spec's own request; it does not touch app code.
 */

/** Delay the one `senroflux_start` admin-ajax POST so the typing bubble has time to paint. */
async function delayTickAjax( page ) {
	await page.route( '**/admin-ajax.php', async ( route ) => {
		const postData = route.request().postData() || '';
		if ( postData.includes( 'action=senroflux_tick' ) ) {
			await new Promise( ( resolve ) => setTimeout( resolve, 1500 ) );
		}
		await route.continue();
	} );
}

/**
 * @param {import('@playwright/test').Page} page
 */
async function readTypingMotion( page ) {
	const typing = page.locator( '.senroflux-typing' ).first();
	await typing.waitFor( { state: 'attached', timeout: 15000 } );
	return typing.evaluate( ( el ) => {
		const after = getComputedStyle( el, '::after' );
		const base = getComputedStyle( el );
		return {
			animationName: after.animationName,
			afterTransitionDuration: after.transitionDuration,
			baseTransitionDuration: base.transitionDuration,
		};
	} );
}

test.describe( 'S22 reduced motion: typing indicator and park card', () => {
	test.describe( 'reducedMotion: reduce', () => {
		test.use( { reducedMotion: 'reduce' } );

		test( 'animation is disabled and transition-duration is 0s', async ( { page } ) => {
			resetRuns();
			setScript( planOnly() );

			await gotoRuns( page );
			await waitLoaded( page );
			await delayTickAjax( page );
			await startRun( page, 'Build the launch page' );

			const typingMotion = await readTypingMotion( page );
			expect( typingMotion.animationName ).toBe( 'none' );
			expect( typingMotion.afterTransitionDuration ).toBe( '0s' );
			expect( typingMotion.baseTransitionDuration ).toBe( '0s' );

			await waitForPark( page, 'plan', 'Plan: needs your OK' );
			const parkCardDuration = await page
				.locator( '.senroflux-park-card' )
				.evaluate( ( el ) => getComputedStyle( el ).transitionDuration );
			expect( parkCardDuration ).toBe( '0s' );

			await assertNoSeriousA11y( page, 'plan park (reduced motion)' );
		} );
	} );

	test.describe( 'reducedMotion: no-preference (control)', () => {
		test.use( { reducedMotion: 'no-preference' } );

		test( 'animation runs normally — without this control the reduce assertion proves nothing', async ( { page } ) => {
			resetRuns();
			setScript( planOnly() );

			await gotoRuns( page );
			await waitLoaded( page );
			await delayTickAjax( page );
			await startRun( page, 'Build the launch page' );

			const typingMotion = await readTypingMotion( page );
			expect( typingMotion.animationName ).toBe( 'senroflux-typing-blink' );

			await waitForPark( page, 'plan', 'Plan: needs your OK' );
		} );
	} );
} );
