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
 * A brief suggestion created mid-run shows from the tick that created it, with
 * no reload and no reselecting the run. The tick's RunState carries the new
 * `suggestion` step but no `suggestions` list; the Runs screen derives the
 * pending suggestion from that step (it used to wait for the next full read).
 */
test.describe( 'S12 a live-ticked suggestion appears without a reload', () => {
	test.beforeEach( () => {
		resetRuns();
		setScript( fullTour() );
	} );

	test(
		'a brief suggestion created mid-run appears without a reload',
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

			await expect( page.locator( '.senroflux-suggestion-card' ) ).toBeVisible( { timeout: 5000 } );
		}
	);
} );
