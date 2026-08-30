<?php
/**
 * The S5 resume contract AT THE HTTP SEAMS.
 *
 * TARGET REPO PATH: tests/Http/ResumeContractTest.php
 *
 * `tests/Run/RunnerResumeTest` covers the PHP-level shape rules, but its
 * "removed approval_action" case only asserts that PHP itself raises a
 * TypeError when the old positional argument is passed — which proves a
 * signature changed, not that a REQUEST carrying `approval_action` is refused.
 * A stale MAC bridge does not call PHP; it POSTs. So the refusal is asserted
 * here, on both transports, against the shape a stale consumer would actually
 * send.
 *
 * NOTE for whoever owns tests/Run: `RunnerResumeTest::
 * test_the_removed_approval_action_parameter_is_refused_at_the_http_seam` is
 * misnamed — it never touches an HTTP seam. Rename it to something like
 * `..._is_refused_by_the_php_signature`, or drop it now that these tests exist.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Http;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Approval\ApprovalBridge;
use Specflux\SenroFlux\Http\Ajax;
use Specflux\SenroFlux\Http\Rest;
use Specflux\SenroFlux\Model\ModelGatewayInterface;
use Specflux\SenroFlux\Model\ModelTurn;
use Specflux\SenroFlux\Plugin;
use Specflux\SenroFlux\Run\Runner;
use Specflux\SenroFlux\Run\WpdbRunStore;
use Specflux\SenroFlux\Tools\ToolExecutor;
use Specflux\SenroFlux\Tools\ToolRegistry;
use SenroFluxJsonResponse;
use WP_Error;
use WP_REST_Request;
use wpdb;

final class ResumeContractTest extends TestCase {

	protected function setUp(): void {
		Plugin::reset();
		Plugin::set_dependency_probe( true );
		$GLOBALS['senroflux_test_user_caps']       = array( 'read' => true );
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

	// ------------------------------------------------------------------
	// admin-ajax
	// ------------------------------------------------------------------

	public function test_ajax_tick_refuses_a_legacy_approval_action_field(): void {
		$this->seedRunnerGraph();

		$_POST = array(
			'run_id'          => '1',
			'step_count'      => '0',
			'approval_action' => 'approve',
		);

		$json = $this->captureAjaxTick();

		$this->assertFalse( $json->success );
		$this->assertSame( 400, $json->status );
		$this->assertSame( 'senroflux_bad_request', $json->code() );
	}

	public function test_ajax_tick_refuses_a_resume_that_is_not_an_object(): void {
		$this->seedRunnerGraph();

		$_POST = array(
			'run_id'     => '1',
			'step_count' => '0',
			// A stale consumer sending the old scalar instead of a resume object.
			'resume'     => '"approve"',
		);

		$json = $this->captureAjaxTick();

		$this->assertFalse( $json->success );
		$this->assertSame( 400, $json->status );
		$this->assertSame( 'resume_mismatch', $json->code() );
	}

	public function test_ajax_tick_refuses_a_resume_on_a_run_that_is_not_parked(): void {
		$run_id = $this->seedRun();

		$_POST = array(
			'run_id'     => (string) $run_id,
			'step_count' => '0',
			'resume'     => '{"action":"approve"}',
		);

		$json = $this->captureAjaxTick();

		$this->assertFalse( $json->success );
		$this->assertSame( 400, $json->status );
		$this->assertSame( 'resume_mismatch', $json->code() );
	}

	// ------------------------------------------------------------------
	// REST
	// ------------------------------------------------------------------

	public function test_rest_tick_refuses_a_legacy_approval_action_param(): void {
		$this->seedRunnerGraph();

		$response = ( new Rest() )->routeTick(
			new WP_REST_Request(
				array(
					'run_id'          => 1,
					'step_count'      => 0,
					'approval_action' => 'approve',
				)
			)
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'senroflux_bad_request', $response->get_data()['code'] );
	}

	public function test_rest_tick_refuses_a_resume_on_a_run_that_is_not_parked(): void {
		$run_id = $this->seedRun();

		$response = ( new Rest() )->routeTick(
			new WP_REST_Request(
				array(
					'run_id'     => $run_id,
					'step_count' => 0,
					'resume'     => array( 'action' => 'approve' ),
				)
			)
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'resume_mismatch', $response->get_data()['code'] );
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/** Run `Ajax::handleTick()` and capture the JSON it would have died with. */
	private function captureAjaxTick(): SenroFluxJsonResponse {
		try {
			( new Ajax() )->handleTick();
		} catch ( SenroFluxJsonResponse $json ) {
			return $json;
		}

		$this->fail( 'handleTick() returned without sending a JSON response.' );
	}

	/** A run owned by user 1, in its initial (unparked) status. */
	private function seedRun(): int {
		$this->seedRunnerGraph();

		return ( new WpdbRunStore( $GLOBALS['wpdb'] ) )->createRun(
			1,
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

	/** Wire a real runner graph over the emulated wpdb (mirrors the Admin suite). */
	private function seedRunnerGraph(): void {
		if ( isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof wpdb && array() !== $GLOBALS['wpdb']->tables ) {
			return;
		}

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
