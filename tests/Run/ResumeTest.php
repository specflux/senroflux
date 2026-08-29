<?php
/**
 * Park-resolution validation tests (stage-1 check, S5): the 3x3 matrix of
 * park kinds x resolution shapes, plus the shape details that fail closed.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Run;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Run\Resume;
use Specflux\SenroFlux\Run\RunStatus;
use WP_Error;

final class ResumeTest extends TestCase {

	/**
	 * The full 3x3 matrix: park kind x resolution shape. Only the diagonal is
	 * accepted; every off-diagonal combination is `resume_mismatch` with a
	 * 400 status — never a best-effort interpretation.
	 */
	public function test_park_kind_and_resolution_shape_must_match(): void {
		$diagonal = array(
			'awaiting_approval' => array( RunStatus::AwaitingApproval, array( 'action' => 'approve' ) ),
			'awaiting_user'     => array( RunStatus::AwaitingUser, array( 'answer' => array( 'text' => 'Blue' ) ) ),
			'awaiting_plan'     => array( RunStatus::AwaitingPlan, array( 'plan' => array( 'action' => 'accept' ) ) ),
		);

		foreach ( $diagonal as $park_name => $pair ) {
			[ $park, $shape ] = $pair;
			$this->assertTrue( Resume::check( $park, $shape ), $park_name . ' accepts its own shape' );

			foreach ( $diagonal as $other_name => $other_pair ) {
				if ( $park_name === $other_name ) {
					continue;
				}
				$this->assertMismatch( Resume::check( $park, $other_pair[1] ), $park_name . ' + ' . $other_name . ' shape' );
			}
		}
	}

	public function test_a_non_parked_run_accepts_no_resolution(): void {
		foreach ( array( RunStatus::Pending, RunStatus::Running, RunStatus::Completed, RunStatus::Failed, RunStatus::Cancelled ) as $status ) {
			$this->assertMismatch( Resume::check( $status, array( 'action' => 'approve' ) ), $status->value );
			$this->assertMismatch( Resume::check( $status, array( 'skip' => true ) ), $status->value );
			$this->assertMismatch( Resume::check( $status, array( 'plan' => array( 'action' => 'accept' ) ) ), $status->value );
		}
	}

	/**
	 * The removed 0.1 string param (and any other non-array payload) matches
	 * no park kind.
	 */
	public function test_a_string_or_scalar_payload_matches_no_park_kind(): void {
		foreach ( array( RunStatus::AwaitingApproval, RunStatus::AwaitingUser, RunStatus::AwaitingPlan ) as $park ) {
			$this->assertMismatch( Resume::check( $park, 'approve' ), $park->value . ' + string' );
			$this->assertMismatch( Resume::check( $park, 1 ), $park->value . ' + int' );
			$this->assertMismatch( Resume::check( $park, null ), $park->value . ' + null' );
		}
	}

	public function test_approval_shape_rejects_unknown_actions_and_extra_keys(): void {
		$park = RunStatus::AwaitingApproval;

		$this->assertTrue( Resume::check( $park, array( 'action' => 'approve' ) ) );
		$this->assertTrue( Resume::check( $park, array( 'action' => 'reject' ) ) );
		$this->assertMismatch( Resume::check( $park, array( 'action' => 'maybe' ) ), 'unknown action' );
		$this->assertMismatch(
			Resume::check(
				$park,
				array(
					'action' => 'approve',
					'force'  => true,
				)
			),
			'extra key'
		);
	}

	public function test_question_shape_requires_answer_or_skip_exclusively(): void {
		$park = RunStatus::AwaitingUser;

		$this->assertTrue( Resume::check( $park, array( 'skip' => true ) ) );
		$this->assertTrue( Resume::check( $park, array( 'answer' => array( 'text' => 'Blue' ) ) ) );
		$this->assertTrue( Resume::check( $park, array( 'answer' => array( 'choice' => 'blue' ) ) ) );
		$this->assertTrue(
			Resume::check(
				$park,
				array(
					'answer' => array(
						'text'   => 'see',
						'choice' => 'other',
					),
				)
			)
		);

		$this->assertMismatch( Resume::check( $park, array( 'skip' => false ) ), 'skip false' );
		$this->assertMismatch(
			Resume::check(
				$park,
				array(
					'skip'   => true,
					'answer' => array( 'text' => 'x' ),
				)
			),
			'skip and answer'
		);
		$this->assertMismatch( Resume::check( $park, array( 'answer' => array() ) ), 'empty answer' );
		$this->assertMismatch( Resume::check( $park, array( 'answer' => array( 'text' => 7 ) ) ), 'non-string text' );
		$this->assertMismatch( Resume::check( $park, array( 'answer' => array( 'other' => 'x' ) ) ), 'unknown answer key' );
	}

	public function test_plan_shape_rejects_unknown_actions_and_requires_a_veto_note(): void {
		$park = RunStatus::AwaitingPlan;

		$this->assertTrue( Resume::check( $park, array( 'plan' => array( 'action' => 'accept' ) ) ) );
		$this->assertTrue( Resume::check( $park, array( 'plan' => array( 'action' => 'accept_preapprove' ) ) ) );
		$this->assertTrue(
			Resume::check(
				$park,
				array(
					'plan' => array(
						'action' => 'veto',
						'note'   => 'Too broad.',
					),
				)
			)
		);

		$this->assertMismatch( Resume::check( $park, array( 'plan' => array( 'action' => 'approve' ) ) ), 'approval action on a plan park' );
		$this->assertMismatch( Resume::check( $park, array( 'plan' => array( 'action' => 'veto' ) ) ), 'noteless veto' );
		$this->assertMismatch(
			Resume::check(
				$park,
				array(
					'plan' => array(
						'action' => 'veto',
						'note'   => '   ',
					),
				)
			),
			'whitespace-only veto note'
		);
		$this->assertMismatch(
			Resume::check(
				$park,
				array(
					'plan' => array(
						'action' => 'accept',
						'note'   => str_repeat( 'x', 501 ),
					),
				)
			),
			'note over the cap'
		);
		$this->assertTrue(
			Resume::check(
				$park,
				array(
					'plan' => array(
						'action' => 'accept',
						'note'   => str_repeat( 'x', 500 ),
					),
				)
			),
			'note at the cap'
		);
	}

	/**
	 * Assert a WP_Error resume_mismatch carrying a 400 status.
	 *
	 * @param mixed  $result Resume::check()'s result.
	 * @param string $label  Test label.
	 */
	private function assertMismatch( mixed $result, string $label ): void {
		$this->assertInstanceOf( WP_Error::class, $result, $label );
		$this->assertSame( 'resume_mismatch', $result->get_error_code(), $label );
		$this->assertSame( 400, $result->get_error_data()['status'] ?? 0, $label );
	}
}
