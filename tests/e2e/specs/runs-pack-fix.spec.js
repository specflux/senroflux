// @ts-check
const { test, expect } = require( '@playwright/test' );
const { resetRuns, setScript, setOption, deleteOption, wpCli } = require( '../support/wp-cli' );
const { readBeforeAnyPlan } = require( '../support/scenarios' );
const { gotoRuns, waitLoaded, startRun, waitSettled } = require( '../support/actions' );

/**
 * Runs-pack fix regression (live defect, S10): the Runs screen's own
 * consumer has NO allow-list of its own without a pack
 * ({@see \Specflux\SenroFlux\Admin\RunsScreen::registerAdminConsumer()}
 * only unions the registered packs' allow-lists), and a pack-less start has
 * an EMPTY verb map — every read is refused `plan_required` and every
 * `propose-plan` is refused `unknown_verb`, deadlocking the run forever.
 *
 * `tests/e2e/fake-provider/fake-provider.php` used to mask this completely:
 * it registered its OWN site-wide `senroflux_verb_map` filter, so a
 * pack-less run (the only kind the message box could start, before this fix)
 * still resolved its tiers correctly and every existing spec in this suite
 * passed without ever exercising the real product defect. This spec proves
 * the ACTUAL fix — the message box now sends a pack, and a pack-driven run
 * resolves its own verb map — by removing that site-wide filter for the
 * duration of the test (`senroflux_e2e_no_global_verb_map=1`) and confirming
 * a Tier-0 read still succeeds on the FIRST turn, before any plan exists.
 *
 * On the pre-fix code this test fails exactly like the live report: the
 * scripted read comes back refused and the run never reaches "completed".
 */
test.describe( 'runs-pack fix: a pack-driven run resolves Tier-0 reads with no global verb map', () => {
	test.beforeEach( () => {
		resetRuns();
		setOption( 'senroflux_e2e_no_global_verb_map', '1' );
		setScript( readBeforeAnyPlan() );
	} );

	test.afterEach( () => {
		deleteOption( 'senroflux_e2e_no_global_verb_map' );
	} );

	test( 'a Tier-0 read succeeds on the first turn, with no global senroflux_verb_map filter registered', async ( { page } ) => {
		await gotoRuns( page );
		await waitLoaded( page );

		// The fixture registers exactly one pack (`senroflux-e2e`); the
		// message box auto-selects it (MessageBox's "preselected when
		// exactly one pack" rule), so this is byte-for-byte the same call
		// every other spec in this suite already makes.
		await startRun( page, 'Read the fixture thing' );
		await waitSettled( page );

		await expect( page.locator( '.senroflux-chat-bubble-bot' ).first() ).toContainText( 'Read thing-1' );

		const runId = wpCli(
			[ 'db', 'query', 'SELECT id FROM wp_senroflux_runs ORDER BY id DESC LIMIT 1', '--skip-column-names' ]
		).trim();
		expect( runId ).not.toBe( '' );

		const status = wpCli(
			[ 'db', 'query', `SELECT status FROM wp_senroflux_runs WHERE id = ${ runId }`, '--skip-column-names' ]
		).trim();
		expect( status ).toBe( 'completed' );

		// The regression check: no step for this run was ever refused
		// `plan_required` — the exact refusal a pack-less start produced on
		// the very first read in the live report this fix pins.
		const refusedCount = wpCli(
			[
				'db',
				'query',
				`SELECT COUNT(*) FROM wp_senroflux_steps WHERE run_id = ${ runId } AND message_json LIKE '%plan_required%'`,
				'--skip-column-names',
			]
		).trim();
		expect( refusedCount ).toBe( '0' );
	} );
} );
