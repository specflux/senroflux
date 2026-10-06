<?php
/**
 * `Ajax::sanitizeResume()` / `Ajax::sanitizeBudget()`: the two JSON bodies
 * the admin-ajax surface decodes are sanitized right after decoding, without
 * loosening the exact-shape checks that run afterwards.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Http;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Http\Ajax;
use Specflux\SenroFlux\Run\Resume;
use Specflux\SenroFlux\Run\RunStatus;

final class AjaxSanitizeTest extends TestCase {

	public function test_answer_text_and_choice_are_stripped_of_markup(): void {
		$resume = Ajax::sanitizeResume(
			json_decode( '{"answer":{"text":"Blue <script>alert(1)</script>please","choice":"<b>Yes</b>"}}', true )
		);

		$this->assertSame(
			array(
				'answer' => array(
					'text'   => 'Blue alert(1)please',
					'choice' => 'Yes',
				),
			),
			$resume
		);
	}

	public function test_plan_note_is_stripped_and_the_resolution_still_validates(): void {
		$resume = Ajax::sanitizeResume(
			json_decode( '{"plan":{"action":"veto","note":"<img src=x onerror=alert(1)>Too long"}}', true )
		);

		$this->assertSame( 'Too long', $resume['plan']['note'] );
		$this->assertTrue( Resume::check( RunStatus::AwaitingPlan, $resume ) );
	}

	public function test_skip_true_keeps_its_boolean_type(): void {
		$resume = Ajax::sanitizeResume( json_decode( '{"skip":true}', true ) );

		$this->assertSame( array( 'skip' => true ), $resume );
		$this->assertTrue( Resume::check( RunStatus::AwaitingUser, $resume ) );
	}

	public function test_a_wrongly_typed_value_still_fails_the_shape_check(): void {
		$resume = Ajax::sanitizeResume( json_decode( '{"skip":"true"}', true ) );

		$this->assertInstanceOf( \WP_Error::class, Resume::check( RunStatus::AwaitingUser, $resume ) );
	}

	public function test_a_non_object_resume_stays_a_non_array(): void {
		$this->assertNull( Ajax::sanitizeResume( json_decode( 'not json', true ) ) );
		$this->assertSame( 'approve', Ajax::sanitizeResume( json_decode( '"approve"', true ) ) );
	}

	public function test_budget_keeps_only_non_negative_integer_caps(): void {
		$budget = Ajax::sanitizeBudget(
			json_decode( '{"max_steps":5,"max_tokens":"9000","images":0,"refunds":-1,"max_plans":"<b>3</b>","max_questions":2.5,"MAX_TOOL_CALLS":7}', true )
		);

		$this->assertSame(
			array(
				'max_steps'      => 5,
				'max_tokens'     => 9000,
				'images'         => 0,
				'max_tool_calls' => 7,
			),
			$budget
		);
	}

	public function test_a_non_object_budget_is_empty(): void {
		$this->assertSame( array(), Ajax::sanitizeBudget( json_decode( '[broken', true ) ) );
		$this->assertSame( array(), Ajax::sanitizeBudget( 'string' ) );
	}
}
