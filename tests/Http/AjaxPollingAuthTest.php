<?php
/**
 * Polling auth: the admin-ajax tick must honour the S13 delegation the Runs
 * screen's own form submissions honour.
 *
 * TARGET REPO PATH: tests/Http/AjaxPollingAuthTest.php
 *
 * The defect these tests pin: `RunsScreen` installed a `senroflux_can_tick`
 * allowance around its park handlers, but `Ajax::handleTick()` did not. So a
 * screen-capability holder viewing SOMEONE ELSE'S run could answer its park
 * (form → allowance → OK) while the poll on the very same page 403'd — and
 * `runs.js` swallowed it, leaving a frozen page.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Http;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Approval\ApprovalBridge;
use Specflux\SenroFlux\Http\Ajax;
use Specflux\SenroFlux\Model\ModelGatewayInterface;
use Specflux\SenroFlux\Model\ModelTurn;
use Specflux\SenroFlux\Plugin;
use Specflux\SenroFlux\Run\Runner;
use Specflux\SenroFlux\Run\WpdbRunStore;
use Specflux\SenroFlux\Tools\ToolExecutor;
use Specflux\SenroFlux\Tools\ToolRegistry;
use SenroFluxJsonResponse;
use WP_Error;
use wpdb;

final class AjaxPollingAuthTest extends TestCase {

	/** The run belongs to this user; the poller is user 1. */
	private const OWNER = 42;

	protected function setUp(): void {
		Plugin::reset();
		Plugin::set_dependency_probe( true );
		$GLOBALS['senroflux_test_current_user_id'] = 1;
		$GLOBALS['senroflux_test_transients']      = array();
		$GLOBALS['senroflux_test_filters']         = array();
		unset( $_POST );
	}

	protected function tearDown(): void {
		remove_all_filters( 'senroflux_runs_capability' );
		remove_all_filters( 'senroflux_can_tick' );
		unset( $_POST );
	}

	public function test_polling_someone_elses_run_without_the_screen_capability_is_forbidden(): void {
		// `read` only: an ordinary logged-in user, no delegation.
		$GLOBALS['senroflux_test_user_caps'] = array( 'read' => true );

		$json = $this->pollForeignRun();

		$this->assertFalse( $json->success );
		$this->assertSame( 403, $json->status );
		$this->assertSame( 'senroflux_forbidden', $json->code() );
	}

	public function test_polling_someone_elses_run_with_the_screen_capability_is_allowed(): void {
		$GLOBALS['senroflux_test_user_caps'] = array(
			'read'           => true,
			'manage_options' => true,
		);

		$json = $this->pollForeignRun();

		// The tick gets PAST the ownership gate. What it does next (the fake
		// gateway refuses to generate) is not this test's business — the point
		// is that it is no longer a 403.
		$this->assertNotSame( 'senroflux_forbidden', $json->code() );
		$this->assertNotSame( 403, $json->status );
	}

	public function test_the_allowance_is_gone_once_the_request_is_over(): void {
		$GLOBALS['senroflux_test_user_caps'] = array(
			'read'           => true,
			'manage_options' => true,
		);

		$this->seedRunnerGraph();
		$store  = new WpdbRunStore( $GLOBALS['wpdb'] );
		$run_id = $this->createRun( $store );

		$_POST = array(
			'run_id'     => (string) $run_id,
			'step_count' => '0',
		);
		$this->captureAjaxTick();

		// Nothing may survive the handler: a later `senroflux_can_tick` in the
		// same PHP process must fall back to the Runner's owner-only default.
		$this->assertFalse(
			(bool) apply_filters( 'senroflux_can_tick', false, $store->getRun( $run_id ) ),
			'the delegation allowance was removed after the tick'
		);
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/** Poll a run owned by somebody else. */
	private function pollForeignRun(): SenroFluxJsonResponse {
		$this->seedRunnerGraph();
		$run_id = $this->createRun( new WpdbRunStore( $GLOBALS['wpdb'] ) );

		$_POST = array(
			'run_id'     => (string) $run_id,
			'step_count' => '0',
		);

		return $this->captureAjaxTick();
	}

	/** Run `Ajax::handleTick()` and capture the JSON it would have died with. */
	private function captureAjaxTick(): SenroFluxJsonResponse {
		try {
			( new Ajax() )->handleTick();
		} catch ( SenroFluxJsonResponse $json ) {
			return $json;
		}

		$this->fail( 'handleTick() returned without sending a JSON response.' );
	}

	private function createRun( WpdbRunStore $store ): int {
		return $store->createRun(
			self::OWNER,
			'tests',
			'A goal',
			array( 'senroflux/read-content' ),
			array(
				'max_steps'      => 5,
				'max_tool_calls' => 5,
				'max_tokens'     => 100,
				'max_questions'  => 1,
				'max_plans'      => 1,
			)
		);
	}

	private function seedRunnerGraph(): void {
		$db              = new wpdb();
		$db->queryReturn = 1;
		$GLOBALS['wpdb'] = $db;

		$runner = new Runner(
			new WpdbRunStore( $db ),
			new ToolExecutor(),
			new class() implements ModelGatewayInterface {
				public function generateTurn( array $history, string $system_instruction, ToolRegistry $tools ): ModelTurn|WP_Error {
					unset( $history, $system_instruction, $tools );

					return new WP_Error( 'unused', 'no model calls in this test' );
				}
			},
			new ApprovalBridge()
		);

		$prop = new \ReflectionProperty( Plugin::class, 'runner' );
		$prop->setAccessible( true );
		$prop->setValue( Plugin::instance(), $runner );
	}
}
