<?php
/**
 * ToolExecutor tests: the permission-first seam (stage 5).
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Tools;

use PHPUnit\Framework\TestCase;
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
}
