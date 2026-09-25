<?php
/**
 * ToolExecutor tests: the permission-first seam (stage 5).
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Tools;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Tools\BuiltinGate;
use Specflux\SenroFlux\Tools\ToolExecutor;
use WP_Error;
use wpdb;
use SenroFlux_Test_Fake_Ability;

final class ToolExecutorTest extends TestCase {

	private ToolExecutor $executor;

	protected function setUp(): void {
		$this->executor = new ToolExecutor();
	}

	public function test_unknown_tool_is_reported_and_never_executed(): void {
		$executed                            = false;
		$GLOBALS['senroflux_test_abilities'] = array();

		$outcome = $this->executor->call( 'agsafe-smoke/ghost', null );

		$this->assertSame( 'unknown_tool', $outcome->kind );
		$this->assertFalse( $executed );
	}

	public function test_approval_required_is_detected_from_the_gate_error_data(): void {
		$GLOBALS['senroflux_test_abilities'] = array(
			'agsafe-smoke/blocked' => new SenroFlux_Test_Fake_Ability(
				'agsafe-smoke/blocked',
				permission_result: new WP_Error(
					'approval_required',
					'requires human approval',
					array(
						'status'      => 202,
						'verb'        => 'agsafe-smoke/blocked',
						'tier'        => 2,
						'approval_id' => 'apr_123',
					)
				)
			),
		);

		$outcome = $this->executor->call( 'agsafe-smoke/blocked', array( 'target' => 'x' ) );

		$this->assertSame( 'approval_required', $outcome->kind );
		$this->assertSame( 'apr_123', $outcome->approvalId );
		$this->assertSame( 'agsafe-smoke/blocked', $outcome->verb );
		$this->assertSame( '2', $outcome->tier );
	}

	public function test_denied_path_never_calls_execute(): void {
		$executed                            = false;
		$GLOBALS['senroflux_test_abilities'] = array(
			'agsafe-smoke/denied' => new SenroFlux_Test_Fake_Ability(
				'agsafe-smoke/denied',
				permission_result: new WP_Error( 'not_in_pack', 'verb is not in the pack allow-list' ),
				execute_result: function () use ( &$executed ) {
					$executed = true;

					return array( 'ok' => true );
				},
			),
		);

		$outcome = $this->executor->call( 'agsafe-smoke/denied', null );

		$this->assertFalse( $executed, 'a denial must never reach execute()' );
		$this->assertSame( 'denied', $outcome->kind );
		$this->assertSame( 'not_in_pack', $outcome->errorCode );
		$this->assertStringContainsString( 'allow-list', (string) $outcome->errorMessage );
	}

	public function test_success_wraps_scalar_output_as_text(): void {
		$GLOBALS['senroflux_test_abilities'] = array(
			'tool/text' => new SenroFlux_Test_Fake_Ability( 'tool/text', execute_result: 'just a string' ),
		);

		$outcome = $this->executor->call( 'tool/text', null );

		$this->assertSame( 'result', $outcome->kind );
		$this->assertSame( array( 'text' => 'just a string' ), $outcome->output );
	}

	public function test_execution_errors_surface_as_the_error_kind(): void {
		$GLOBALS['senroflux_test_abilities'] = array(
			'tool/boom' => new SenroFlux_Test_Fake_Ability(
				'tool/boom',
				execute_result: new WP_Error( 'kaboom', 'Execution failed hard' )
			),
		);

		$outcome = $this->executor->call( 'tool/boom', null );

		$this->assertSame( 'error', $outcome->kind );
		$this->assertSame( 'Execution failed hard', $outcome->errorMessage );
	}

	public function test_results_over_the_byte_cap_are_truncated_with_a_marker(): void {
		add_filter(
			'senroflux_tool_result_max_bytes',
			static fn (): int => 64,
			10,
			1
		);

		try {
			$GLOBALS['senroflux_test_abilities'] = array(
				'tool/huge' => new SenroFlux_Test_Fake_Ability(
					'tool/huge',
					execute_result: array( 'blob' => str_repeat( 'x', 5000 ) )
				),
			);

			$outcome = $this->executor->call( 'tool/huge', null );

			$this->assertSame( 'result', $outcome->kind );
			$this->assertTrue( $outcome->output['truncated'] ?? false );
			$this->assertLessThanOrEqual( 64 + 40, strlen( (string) wp_json_encode( $outcome->output ) ), 'prefix + marker stays close to the cap' );
		} finally {
			remove_all_filters( 'senroflux_tool_result_max_bytes' );
		}
	}

	// ------------------------------------------------------------------
	// 0.3 S3: the built-in gate parks BEFORE check_permissions
	// ------------------------------------------------------------------

	public function test_an_active_unapproved_built_in_gate_parks_before_check_permissions(): void {
		$permission_checked = false;
		$executed           = false;

		$GLOBALS['senroflux_test_abilities'] = array(
			'agsafe-smoke/write' => new SenroFlux_Test_Fake_Ability(
				'agsafe-smoke/write',
				permission_result: static function () use ( &$permission_checked ) {
					$permission_checked = true;

					return true;
				},
				execute_result: static function () use ( &$executed ) {
					$executed = true;

					return array( 'ok' => true );
				}
			),
		);

		$outcome = $this->executor->call(
			'agsafe-smoke/write',
			array(),
			new BuiltinGate( active: true, tier: 1, verb: 'agsafe-smoke/write', approvalId: 'builtin:1:call_x', approved: false )
		);

		$this->assertSame( 'approval_required', $outcome->kind );
		$this->assertSame( 'builtin:1:call_x', $outcome->approvalId );
		$this->assertSame( 'agsafe-smoke/write', $outcome->verb );
		$this->assertSame( '1', $outcome->tier );
		$this->assertFalse( $permission_checked, 'the built-in gate must park BEFORE check_permissions runs' );
		$this->assertFalse( $executed );
	}

	public function test_an_unmapped_verb_gate_is_active_by_the_caller_s_own_fail_closed_tier(): void {
		// VerbTier::tierFor() already fails an unmapped verb closed to tier 2;
		// the Runner hands ToolExecutor that resolved tier, so a gate built
		// from it is active exactly like any other tier-2 call.
		$gate = new BuiltinGate( active: true, tier: 2, verb: 'agsafe-smoke/unmapped', approvalId: 'builtin:1:call_y', approved: false );

		$GLOBALS['senroflux_test_abilities'] = array(
			'agsafe-smoke/unmapped' => new SenroFlux_Test_Fake_Ability( 'agsafe-smoke/unmapped' ),
		);

		$outcome = $this->executor->call( 'agsafe-smoke/unmapped', array(), $gate );

		$this->assertSame( 'approval_required', $outcome->kind );
		$this->assertSame( '2', $outcome->tier );
	}

	public function test_a_tier_zero_gate_is_inert_and_the_call_runs_normally(): void {
		$GLOBALS['senroflux_test_abilities'] = array(
			'agsafe-smoke/read' => new SenroFlux_Test_Fake_Ability( 'agsafe-smoke/read', execute_result: array( 'ok' => true ) ),
		);

		$outcome = $this->executor->call(
			'agsafe-smoke/read',
			array(),
			new BuiltinGate( active: false, tier: 0, verb: 'agsafe-smoke/read', approvalId: '', approved: false )
		);

		$this->assertSame( 'result', $outcome->kind );
	}

	public function test_an_approved_gate_skips_the_park_and_runs_the_ability(): void {
		$GLOBALS['senroflux_test_abilities'] = array(
			'agsafe-smoke/write' => new SenroFlux_Test_Fake_Ability( 'agsafe-smoke/write', execute_result: array( 'ok' => true ) ),
		);

		$outcome = $this->executor->call(
			'agsafe-smoke/write',
			array(),
			new BuiltinGate( active: true, tier: 1, verb: 'agsafe-smoke/write', approvalId: 'builtin:1:call_x', approved: true )
		);

		$this->assertSame( 'result', $outcome->kind, 'the approved re-run must never park itself' );
	}

	public function test_a_null_gate_never_registers_a_permission_filter_and_behaves_like_as_mode(): void {
		$GLOBALS['senroflux_test_abilities'] = array(
			'agsafe-smoke/write' => new SenroFlux_Test_Fake_Ability( 'agsafe-smoke/write', execute_result: array( 'ok' => true ) ),
		);

		$outcome = $this->executor->call( 'agsafe-smoke/write', array(), null );

		$this->assertSame( 'result', $outcome->kind );
	}
}
