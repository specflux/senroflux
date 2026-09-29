// @ts-check
const { test, expect } = require( '@playwright/test' );
const { resetRuns, setScript, dbQuery, evalPhp } = require( '../support/wp-cli' );
const { fullTour } = require( '../support/scenarios' );
const {
	gotoRuns,
	waitLoaded,
	startRun,
	waitForPark,
	answerChoice,
	acceptPlan,
} = require( '../support/actions' );

/**
 * S22 gate-robustness suite: "forged or replayed [approval] nonces" — the
 * two cases `tests/Adversarial/ApprovalNonceTest.php` leaves
 * `markTestIncomplete()` because this repo's bare-PHPUnit harness stubs
 * `check_admin_referer()` as an unconditional no-op (see that file's
 * docblock). Both are exercised here, against REAL WordPress, by talking
 * directly to the admin-post endpoint the park's Approve/Reject buttons hit
 * (`admin_post_senroflux_approval_decision` ->
 * `RunsScreen::handleApprovalDecision()`), instead of through the screen's
 * own button — the point is to attack the handler itself, forged/replayed
 * nonce included, not the UI affordance around it.
 *
 * `handleApprovalDecision()`:
 *   1. `check_admin_referer( 'senroflux_approval_' . $run_id )` — default
 *      query arg `_wpnonce`. A failing check calls WordPress core's
 *      `wp_nonce_ays()`, which `wp_die()`s with HTTP 403 ("The link you
 *      followed has expired.") — case (a) below.
 *   2. On success, resumes the parked run via
 *      `senroflux()->tick( $run_id, $step_count, [ 'action' => ... ] )`.
 *      `$step_count` must match the run's CURRENT step_count
 *      ({@see \Specflux\SenroFlux\Run\Runner::tick()}, `senroflux_conflict`)
 *      — a stale step_count (a replay of a request whose step_count the
 *      first, successful decision already advanced past) is refused there,
 *      not by nonce single-use semantics (WordPress nonces are valid across
 *      a whole ~24h window, not one-time-use) — case (b) below.
 *
 * Both cases read run/step state straight from the database via wp-cli
 * (`dbQuery`) rather than through the screen, because what they need to
 * prove is the ABSENCE of a state change — nothing this suite's own UI
 * helpers assert on.
 *
 * UNRUN: this suite has no wp-env of its own available to the agent that
 * wrote it (see the S19e brief) — it is written against
 * `RunsScreen::handleApprovalDecision()`'s actual field names and error
 * codes (`run_id`, `step_count`, `senroflux_approval_action`, `_wpnonce`,
 * `senroflux_conflict`), but has never been executed. The owner should run
 * it once under `npx playwright test tests/e2e/specs/adversarial-nonce.spec.js`
 * before trusting it as a green gate.
 */
test.describe( 'S22 adversarial: forged and replayed approval nonces', () => {
	test.beforeEach( () => {
		resetRuns();
		setScript( fullTour() );
	} );

	/** Drive a run to its Tier-2 approval park and return {runId, stepCount}. */
	async function parkAtApproval( page ) {
		await gotoRuns( page );
		await waitLoaded( page );
		await startRun( page, 'Build the launch page' );
		await waitForPark( page, 'plan', 'Plan: needs your OK' );
		await acceptPlan( page );
		await waitForPark( page, 'question', 'Question for you' );
		await answerChoice( page, 'Launch Day' );
		await waitForPark( page, 'approval', 'Approve this change?' );

		const row = dbQuery(
			'SELECT id, status, step_count FROM wp_senroflux_runs ORDER BY id DESC LIMIT 1'
		);
		const [ runId, status, stepCount ] = row.split( /\t/ );
		expect( status, 'run must be parked awaiting_approval before either adversarial case runs' ).toBe(
			'awaiting_approval'
		);
		return { runId, stepCount: Number( stepCount ) };
	}

	function runSnapshot( runId ) {
		const row = dbQuery( `SELECT status, step_count FROM wp_senroflux_runs WHERE id = ${ Number( runId ) }` );
		const [ status, stepCount ] = row.split( /\t/ );
		const stepRows = dbQuery( `SELECT COUNT(*) FROM wp_senroflux_steps WHERE run_id = ${ Number( runId ) }` );
		return { status, stepCount: Number( stepCount ), stepRows: Number( stepRows ) };
	}

	test( 'a forged _wpnonce is refused and the run stays parked', async ( { page } ) => {
		const { runId, stepCount } = await parkAtApproval( page );
		const before = runSnapshot( runId );

		const response = await page.request.post( '/wp-admin/admin-post.php', {
			form: {
				action: 'senroflux_approval_decision',
				run_id: runId,
				step_count: String( stepCount ),
				senroflux_approval_action: 'approve',
				_wpnonce: 'deadbeef00', // Well-formed shape, never a real WP nonce for this action/user.
			},
			maxRedirects: 0,
		} );

		// wp_nonce_ays() -> wp_die( ..., [ 'response' => 403 ] ) on a failed
		// check_admin_referer(), never a redirect.
		expect( response.status() ).toBe( 403 );
		const body = await response.text();
		expect( body.toLowerCase() ).toMatch( /expired|something went wrong/ );

		const after = runSnapshot( runId );
		expect( after, 'a forged nonce must not move the run at all' ).toEqual( before );
	} );

	test( 'a replayed decision request (stale step_count) does not execute twice', async ( { page } ) => {
		const { runId, stepCount } = await parkAtApproval( page );

		const nonce = evalPhp( `echo wp_create_nonce( 'senroflux_approval_' . ${ Number( runId ) } );` );
		expect( nonce ).toMatch( /^[a-f0-9]+$/ );

		const decisionBody = {
			action: 'senroflux_approval_decision',
			run_id: runId,
			step_count: String( stepCount ),
			senroflux_approval_action: 'approve',
			_wpnonce: nonce,
		};

		// First submission: the genuine decision. Expect a redirect (never a
		// 403/die) and the run to have actually advanced.
		const first = await page.request.post( '/wp-admin/admin-post.php', {
			form: decisionBody,
			maxRedirects: 0,
		} );
		expect( [ 301, 302, 303 ] ).toContain( first.status() );
		const firstLocation = first.headers().location || '';
		expect( firstLocation ).toContain( 'page=senroflux-runs' );
		expect( firstLocation, 'the genuine decision must not itself carry an error code' ).not.toContain(
			'senroflux_run_error'
		);

		const afterFirst = runSnapshot( runId );
		expect( afterFirst.status, 'the run must have left awaiting_approval' ).not.toBe( 'awaiting_approval' );
		expect( afterFirst.stepCount, 'the run must have advanced past the parked step_count' ).toBeGreaterThan(
			stepCount
		);

		// Second submission: the EXACT same request — same nonce (still
		// cryptographically valid), same now-stale step_count. This is the
		// replay: the run has moved on, so the tick call must reject it as a
		// conflict rather than resuming (and possibly re-executing) the same
		// approval again.
		const replay = await page.request.post( '/wp-admin/admin-post.php', {
			form: decisionBody,
			maxRedirects: 0,
		} );
		expect( [ 301, 302, 303 ] ).toContain( replay.status() );
		const replayLocation = replay.headers().location || '';
		expect( replayLocation, 'a replay must be refused as a conflict, not resumed' ).toContain(
			'senroflux_run_error=senroflux_conflict'
		);

		const afterReplay = runSnapshot( runId );
		expect( afterReplay, 'the replay must not change run state at all — no double execution' ).toEqual(
			afterFirst
		);
	} );
} );
