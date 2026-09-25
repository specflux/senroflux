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
 * Product defects found by this suite while building 0.3 S10/S12/S22
 * coverage. Kept as `test.fail()` cases — they run for real and are
 * expected to fail; if one starts PASSING, Playwright reports it as an
 * unexpected pass, which is the signal the underlying fix landed and this
 * annotation should be removed. Never silently skipped, never routed around
 * in the specs that cover the surrounding behaviour (see full-tour.spec.js's
 * comment at the point this was found).
 */
test.describe( 'S12 known defect: a live-ticked suggestion is invisible until reload', () => {
	test.beforeEach( () => {
		resetRuns();
		setScript( fullTour() );
	} );

	test.fail(
		'a brief suggestion created mid-run should appear without a reload, but does not',
		async ( { page } ) => {
			// Root cause (confirmed via `senroflux()->get()` vs `senroflux()->tick()`
			// return shapes): `Runner::tick()` (driving the ajax path this
			// screen uses) returns `{run, new_steps, ui}` — no `suggestions`
			// key. `App.js`'s `applyRunState()` merges exactly that shape
			// into `runDetail`, so `runDetail.suggestions` is frozen at
			// whatever the run's last full `GET /senroflux/v1/runs/{id}`
			// fetch returned (empty, since that fetch happened before the
			// suggestion existed). The suggestion step IS correctly
			// persisted and correctly returned by `Plugin::get()` — this is
			// a client-side staleness gap, not a data-loss bug.
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

			// This is the assertion that SHOULD hold once fixed: the
			// suggestion card appears from the SAME tick that created it,
			// with no reload and no reselecting the run.
			await expect( page.locator( '.senroflux-suggestion-card' ) ).toBeVisible( { timeout: 5000 } );
		}
	);
} );
