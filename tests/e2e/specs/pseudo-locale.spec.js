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
const { buildPseudoLocale, LOCALE } = require( '../support/pseudo-locale-build' );

/**
 * S22 translations: "a spec asserts no unwrapped English remains on the
 * Runs screen, the Settings screen and a report [view]." "Report view"
 * (S10/S22 vocabulary) is the same Runs-screen container at a run's
 * terminal state (see rtl.spec.js's identical reading) — there is no
 * separate PHP-rendered report page; `Report.php` is a data shape, not a
 * screen.
 *
 * Data is NOT translatable and must be excluded from the check by an
 * EXISTING semantic class/element wherever one exists (run goals, model/
 * fake-provider text, ability ids, raw args, choice/step text the fake
 * provider supplies). Three places had no existing hook at all — see
 * ParkCard.js and LedgerGroup.js — and got a minimal `data-senroflux-content`
 * attribute added for exactly this purpose (documented in each of those
 * two files' diffs, and again in EXCLUDE_SELECTORS below).
 */

const PSEUDO_STATE_PATH = path.join( __dirname, '..', 'support', 'storage-state.pseudo_en_xa.json' );

/**
 * Every existing selector (or attribute) that marks DATA rather than
 * translatable chrome, for the Runs screen:
 *  - `[data-senroflux-content]`: question text, choice labels, plan-park
 *    step text, plan assumptions, and the ledger group's derived label
 *    suffix — none had any other distinguishing hook (ParkCard.js,
 *    LedgerGroup.js).
 *  - `.senroflux-chat-bubble-user`, `.senroflux-run-heading`,
 *    `.senroflux-run-row-title`: the run's own goal (user-entered).
 *  - `.senroflux-chat-bubble-bot:not(.senroflux-typing)`: fake-provider
 *    "model" text steps — simulated model output, not chrome. The typing
 *    indicator keeps the same base class but IS chrome, hence `:not()`.
 *  - `.senroflux-pinned-plan-goal`, `.senroflux-plan-step-text`: the plan's
 *    own goal/step text (PinnedPlan.js — data, from the model's plan).
 *  - `.senroflux-suggestion-text`, `.senroflux-suggestion-text-final`: the
 *    model-authored brief-suggestion text.
 *  - `.senroflux-ledger-verb`: the raw `wpab__ns__name` ability id.
 *  - `.senroflux-ledger-label`: mechanically derived from that raw id
 *    (stepLabel() in utils.js) — not real UI chrome, cannot be meaningfully
 *    localized.
 *  - `.senroflux-args`: raw JSON tool-call arguments.
 *  - `.senroflux-rationale`: model-authored rationale text.
 *  - `.senroflux-run-row-pack`: the pack slug (a technical identifier).
 *  - `code`: ability verb ids rendered inline (ApprovalBody).
 *  - `textarea`, `input`: user-editable data, never chrome.
 */
const EXCLUDE_SELECTORS = [
	'[data-senroflux-content]',
	'.senroflux-chat-bubble-user',
	'.senroflux-chat-bubble-bot:not(.senroflux-typing)',
	'.senroflux-run-heading',
	'.senroflux-pinned-plan-goal',
	'.senroflux-plan-step-text',
	'.senroflux-suggestion-text',
	'.senroflux-suggestion-text-final',
	'.senroflux-ledger-verb',
	'.senroflux-ledger-label',
	'.senroflux-args',
	'.senroflux-rationale',
	'.senroflux-run-row-title',
	'.senroflux-run-row-pack',
	'code',
	'textarea',
	'input',
];

/**
 * Walk every text node under `rootSelector`; any node containing a Latin
 * letter that is NOT inside an excluded (data) element must contain the
 * pseudo-locale wrap marker `⟦`. Runs in-page via `page.evaluate` — must be
 * a fully self-contained function (no outer closures besides its args).
 */
function findUnwrappedEnglish( [ rootSelector, excludeSelectors ] ) {
	const root = document.querySelector( rootSelector );
	if ( ! root ) {
		return { error: `root not found: ${ rootSelector }` };
	}
	const walker = document.createTreeWalker( root, NodeFilter.SHOW_TEXT, null );
	const offenders = [];
	let node;
	while ( ( node = walker.nextNode() ) ) {
		const text = node.nodeValue.trim();
		if ( ! text || ! /[A-Za-z]/.test( text ) ) {
			continue;
		}
		const parent = node.parentElement;
		if ( ! parent ) {
			continue;
		}
		if ( excludeSelectors.some( ( sel ) => parent.closest( sel ) ) ) {
			continue;
		}
		if ( text.includes( '⟦' ) ) {
			continue;
		}
		const labelEl = parent.closest( '[class]' );
		offenders.push( { text, path: labelEl ? labelEl.className : parent.tagName } );
	}
	return { offenders };
}

async function assertNoUnwrappedEnglish( page, rootSelector, label ) {
	const result = await page.evaluate( findUnwrappedEnglish, [ rootSelector, EXCLUDE_SELECTORS ] );
	expect( result.error, label ).toBeUndefined();
	expect( result.offenders, `${ label }: ${ JSON.stringify( result.offenders, null, 2 ) }` ).toEqual( [] );
}

test.describe( 'S22 pseudo-locale: no unwrapped English on Runs, Settings or the terminal report view', () => {
	test.beforeAll( () => {
		buildPseudoLocale();
		const user = ensureLocaleUser( 'pseudouser', 'pseudouser@example.com', LOCALE, 'administrator', false );
		return loginAs( user.login, user.password, PSEUDO_STATE_PATH );
	} );

	test( 'Runs screen: every park, the ledger, the suggestion card and the terminal state', async ( { browser } ) => {
		const context = await browser.newContext( { storageState: PSEUDO_STATE_PATH } );
		const page = await context.newPage();

		resetRuns();
		setScript( fullTour() );

		await gotoRuns( page );
		await waitLoaded( page );
		await assertNoUnwrappedEnglish( page, '#senroflux-runs-root', 'idle Runs screen' );

		await startRun( page, 'Build the launch page' );

		await waitForPark( page, 'plan', 'Plan: needs your OK' );
		await assertNoUnwrappedEnglish( page, '#senroflux-runs-root', 'plan park' );
		await assertNoSeriousA11y( page, 'plan park (pseudo-locale)' );
		await acceptPlan( page );

		await waitForPark( page, 'question', 'Question for you' );
		await assertNoUnwrappedEnglish( page, '#senroflux-runs-root', 'question park' );
		await answerChoice( page, 'Launch Day' );

		await waitForPark( page, 'approval', 'Approve this change?' );
		await assertNoUnwrappedEnglish( page, '#senroflux-runs-root', 'approval park' );
		await approveCall( page );

		await waitSettled( page );
		await page.locator( '.senroflux-chat-bubble-bot' ).first().waitFor( { state: 'visible', timeout: 30000 } );
		await assertNoUnwrappedEnglish( page, '#senroflux-runs-root', 'terminal report view' );
		await assertNoSeriousA11y( page, 'terminal report view (pseudo-locale)' );

		await context.close();
	} );

	test( 'Settings screen', async ( { browser } ) => {
		const context = await browser.newContext( { storageState: PSEUDO_STATE_PATH } );
		const page = await context.newPage();

		await page.goto( '/wp-admin/admin.php?page=senroflux-settings' );
		await page.waitForSelector( '.wrap form' );

		await assertNoUnwrappedEnglish( page, '.wrap', 'Settings screen' );
		await assertNoSeriousA11y( page, 'Settings screen (pseudo-locale)', '.wrap' );

		await context.close();
	} );
} );
