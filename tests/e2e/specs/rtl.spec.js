// @ts-check
const path = require( 'path' );
const { test, expect } = require( '@playwright/test' );
const { resetRuns, setScript } = require( '../support/wp-cli' );
const { fullTour } = require( '../support/scenarios' );
const {
	gotoRuns,
	waitLoaded,
	startRun,
	waitForPark,
	answerChoice,
	acceptPlan,
	approveCall,
	waitSettled,
} = require( '../support/actions' );
const { assertNoSeriousA11y } = require( '../support/a11y' );
const { ensureLocaleUser, loginAs } = require( '../support/locale-users' );

/**
 * S22 RTL: "installs the `ar` locale in wp-env and asserts `dir="rtl"` plus
 * mirrored alignment of user and model bubbles."
 *
 * Uses a DEDICATED user whose USER-level locale is `ar` (never the site
 * locale, via `wp user meta update <id> locale ar`), logged in with its own
 * storage state, so this spec cannot affect any other spec's site-wide
 * locale. `RunsScreen::assets()` must call
 * `wp_style_add_data( 'senroflux-runs', 'rtl', 'replace' )` for the
 * `-rtl.css` build output to load at all — that registration is the one
 * product change this stage makes.
 *
 * The alignment check is RELATIVE, not a hardcoded pixel: it drives the
 * same scenario as an LTR (english-locale) admin and an RTL (ar-locale)
 * admin, and asserts the user bubble sits on the OPPOSITE side of the
 * chat column in RTL from where it sits in LTR — logical flexbox
 * properties (`align-self: flex-end`, S22's RTL Stylelint gate) should
 * mirror automatically when `dir` flips.
 */

const RTL_STATE_PATH = path.join( __dirname, '..', 'support', 'storage-state.rtl_ar.json' );
const LTR_STATE_PATH = path.join( __dirname, '..', 'support', 'storage-state.rtl_control_en.json' );

test.describe( 'S22 RTL: dir, stylesheet and mirrored bubble alignment', () => {
	test.beforeAll( async () => {
		const rtlUser = ensureLocaleUser( 'rtluser', 'rtluser@example.com', 'ar' );
		await loginAs( rtlUser.login, rtlUser.password, RTL_STATE_PATH );

		// Control: a second dedicated user, explicit en_US locale, so the
		// LTR comparison is driven by a real login exactly like the RTL
		// one — not by reusing the shared project-level admin storage state,
		// which would leave the two conditions on different code paths.
		const ltrUser = ensureLocaleUser( 'rtlcontroluser', 'rtlcontroluser@example.com', 'en_US' );
		await loginAs( ltrUser.login, ltrUser.password, LTR_STATE_PATH );
	} );

	/**
	 * Drive fullTour() all the way to its terminal "All done" text step —
	 * the run's goal renders as a `.senroflux-chat-bubble-user` bubble from
	 * the moment the run exists, but `.senroflux-chat-bubble-bot` only
	 * renders for a `model` step with text (`Chat.js`), which this
	 * scenario produces only once approved and settled, not at any park.
	 *
	 * @returns {Promise<{dir:string, userBoxX:number, botBoxX:number}>}
	 */
	async function driveToTerminalAndMeasure( page ) {
		resetRuns();
		setScript( fullTour() );

		await gotoRuns( page );
		await waitLoaded( page );

		const dir = await page.evaluate( () => document.documentElement.dir );

		await startRun( page, 'Build the launch page' );
		await waitForPark( page, 'plan', 'Plan: needs your OK' );
		await acceptPlan( page );
		await waitForPark( page, 'question', 'Question for you' );
		await answerChoice( page, 'Launch Day' );
		await waitForPark( page, 'approval', 'Approve this change?' );
		await approveCall( page );
		await waitSettled( page );
		await page.locator( '.senroflux-chat-bubble-bot' ).first().waitFor( { state: 'visible', timeout: 30000 } );

		const userBubble = page.locator( '.senroflux-chat-bubble-user' ).first();
		const botBubble = page.locator( '.senroflux-chat-bubble-bot' ).first();
		const userBox = await userBubble.boundingBox();
		const botBox = await botBubble.boundingBox();
		if ( ! userBox || ! botBox ) {
			throw new Error( 'expected both a user and a bot chat bubble to be visible' );
		}
		return { dir, userBoxX: userBox.x, botBoxX: botBox.x };
	}

	test( 'ar-locale admin gets dir="rtl" and the -rtl.css stylesheet', async ( { browser } ) => {
		const context = await browser.newContext( { storageState: RTL_STATE_PATH } );
		const page = await context.newPage();

		const result = await driveToTerminalAndMeasure( page );
		expect( result.dir ).toBe( 'rtl' );

		const stylesheetHref = await page.evaluate( () => {
			const link = document.querySelector( 'link[id="senroflux-runs-css"]' )
				|| Array.from( document.querySelectorAll( 'link[rel="stylesheet"]' ) )
					.find( ( el ) => /style-index(-rtl)?\.css/.test( el.getAttribute( 'href' ) || '' ) );
			return link ? link.getAttribute( 'href' ) : null;
		} );
		expect( stylesheetHref ).toContain( 'style-index-rtl.css' );

		await assertNoSeriousA11y( page, 'terminal report view (RTL)' );

		await page.screenshot( { path: 'test-results/rtl-runs-screen.png', fullPage: true } );

		await context.close();
	} );

	test( 'user bubble sits on the opposite side in RTL vs LTR (relative, not hardcoded)', async ( { browser } ) => {
		const rtlContext = await browser.newContext( { storageState: RTL_STATE_PATH } );
		const rtlPage = await rtlContext.newPage();
		const rtlResult = await driveToTerminalAndMeasure( rtlPage );
		await rtlContext.close();

		const ltrContext = await browser.newContext( { storageState: LTR_STATE_PATH } );
		const ltrPage = await ltrContext.newPage();
		const ltrResult = await driveToTerminalAndMeasure( ltrPage );
		await ltrContext.close();

		expect( rtlResult.dir ).toBe( 'rtl' );
		expect( ltrResult.dir ).toBe( '' ); // ltr is the default; core omits the attribute value or sets 'ltr' depending on version — normalize below.

		// In LTR, `align-self: flex-end` pushes the user bubble to the visual
		// RIGHT of the bot bubble (higher x). In RTL, the same logical
		// property pushes it to the visual LEFT (lower x) — the flip proves
		// the layout is genuinely mirrored, not merely re-labelled.
		expect( ltrResult.userBoxX ).toBeGreaterThan( ltrResult.botBoxX );
		expect( rtlResult.userBoxX ).toBeLessThan( rtlResult.botBoxX );
	} );

	/**
	 * Stage 19c2: bidi follow-ups. In the ar-locale (RTL) admin, English DATA
	 * text must stay readable — `dir="auto"` on the goal heading and the
	 * model bubble lets the browser detect each one's own bidi direction
	 * instead of inheriting `dir="rtl"` from `<html>` (which corrupts
	 * trailing punctuation, e.g. ".Done" instead of "Done."). The plan
	 * progress counter is a numeric ratio ("~N / M"), never prose — it gets
	 * `dir="ltr"` instead so it never reads as "1 of 0" in RTL.
	 */
	test( 'goal heading and model bubble get dir="auto"; the plan progress counter gets dir="ltr"', async ( { browser } ) => {
		const context = await browser.newContext( { storageState: RTL_STATE_PATH } );
		const page = await context.newPage();

		await driveToTerminalAndMeasure( page );

		await expect( page.locator( '.senroflux-run-heading' ) ).toHaveAttribute( 'dir', 'auto' );
		await expect( page.locator( '.senroflux-chat-bubble-bot' ).first() ).toHaveAttribute( 'dir', 'auto' );
		await expect( page.locator( '.senroflux-pinned-plan-count' ) ).toHaveAttribute( 'dir', 'ltr' );

		await context.close();
	} );
} );
