// @ts-check
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

/**
 * S10 asks for a tier badge on each expanded ledger row in Agent Safety mode
 * ("each call shows a label, the ability id, a tier badge (AS mode only) and
 * the result"). It is not implementable today.
 *
 * Root cause: the step payload has no tier. `Plugin::get()` builds each step
 * as `{seq, kind, message, tool_name, approval_id, status, tokens_in,
 * tokens_out, duration_ms}` — see `src/Plugin.php:761-771`. Only an
 * `approval`-kind step carries `{verb, tier, args}`, because the tier is
 * recorded when the call is PARKED. A call that ran without parking (Tier 0,
 * or pre-approved under a grant) never had its tier written anywhere the
 * screen can read, so the row cannot show one.
 *
 * The screen deliberately does not fabricate it: showing "Tier 0" by
 * assumption would be a safety claim the data does not support. Closing this
 * needs the tier surfaced on tool_result steps, which is an API change, not a
 * screen change — deferred with the rest of the surface work.
 */
test.describe( 'S10 known defect: ledger rows carry no tier badge in Agent Safety mode', () => {
	test.beforeEach( () => {
		resetRuns();
		setScript( fullTour() );
	} );

	test.fail(
		'an expanded ledger row should show its tier in Agent Safety mode, but the payload has none',
		async ( { page } ) => {
			await gotoRuns( page );
			await waitLoaded( page );
			await startRun( page, 'Build the launch page' );
			await waitForPark( page, 'plan', 'Plan: needs your OK' );
			await acceptPlan( page );
			await waitForPark( page, 'question', 'Question for you' );
			await answerChoice( page, 'Launch Day' );
			await waitForPark( page, 'approval', 'Approve this change?' );
			await assertNoSeriousA11y( page, 'approval park' );
			await approveCall( page );
			await waitSettled( page );
			await assertNoSeriousA11y( page, 'terminal report view' );

			// The assertion that SHOULD hold once the tier is surfaced on
			// tool_result steps.
			await expect(
				page.locator( '.senroflux-ledger-group [data-testid="tier-badge"]' ).first()
			).toBeVisible( { timeout: 5000 } );
		}
	);
} );
