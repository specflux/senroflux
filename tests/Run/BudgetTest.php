<?php
/**
 * Budget defaults + sanitization tests (stage-4 check: filterable defaults).
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Run;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Run\Budget;

final class BudgetTest extends TestCase {

	protected function tearDown(): void {
		remove_all_filters( 'senroflux_default_budget' );
	}

	public function test_defaults_carry_the_shipped_ceilings(): void {
		$this->assertSame(
			array(
				'max_steps'      => 20,
				'max_tool_calls' => 12,
				'max_tokens'     => 60000,
				'max_questions'  => 5,
				'max_plans'      => 3,
			),
			Budget::defaults()
		);
	}

	public function test_defaults_are_filterable(): void {
		add_filter(
			'senroflux_default_budget',
			static fn ( array $defaults ): array => array_merge(
				$defaults,
				array(
					'max_steps'     => 3,
					'max_questions' => 1,
				)
			),
			10,
			1
		);

		$defaults = Budget::defaults();

		$this->assertSame( 3, $defaults['max_steps'], 'a site may lower a ceiling…' );
		$this->assertSame( 1, $defaults['max_questions'], '…including the 0.2 park ceilings…' );
		$this->assertSame( 12, $defaults['max_tool_calls'], '…while the untouched keys keep their values' );
	}

	public function test_sanitize_drops_unknown_keys_and_non_positive_caps(): void {
		$sanitized = Budget::sanitize(
			array(
				'max_steps'      => 0,        // non-positive -> default
				'max_tool_calls' => '7',      // numeric string from JSON -> accepted
				'max_tokens'     => -5,       // negative -> default
				'max_questions'  => 2,
				'max_plans'      => '2',
				'max_ponies'     => 99,       // unknown -> dropped entirely
			)
		);

		$this->assertSame(
			array(
				'max_steps'      => 20,
				'max_tool_calls' => 7,
				'max_tokens'     => 60000,
				'max_questions'  => 2,
				'max_plans'      => 2,
			),
			$sanitized
		);
	}

	public function test_clamp_never_raises_a_cap_above_the_ceiling(): void {
		$ceiling = array(
			'max_steps'      => 10,
			'max_tool_calls' => 4,
			'max_tokens'     => 1000,
			'max_questions'  => 5,
			'max_plans'      => 3,
		);

		$this->assertSame( $ceiling, Budget::clamp( 'junk', $ceiling ) );
		$this->assertSame(
			array(
				'max_steps'      => 2,
				'max_tool_calls' => 4,
				'max_tokens'     => 1000,
				'max_questions'  => 1,
				'max_plans'      => 3,
			),
			Budget::clamp(
				array(
					'max_steps'      => 2,
					'max_tool_calls' => 40,
					'max_tokens'     => '999999',
					'max_questions'  => 1,
					'max_plans'      => 9,
					'other'          => 1,
				),
				$ceiling
			)
		);
	}

	public function test_park_ceilings_are_lower_only_like_every_other_cap(): void {
		// The stage-1 check: max_questions/max_plans obey the same
		// lower-only rule the HTTP budget has always obeyed.
		$ceiling = Budget::defaults();

		$clamped = Budget::clamp(
			array(
				'max_questions' => 99,
				'max_plans'     => 99,
			),
			$ceiling
		);

		$this->assertSame( $ceiling['max_questions'], $clamped['max_questions'], 'a request cannot raise max_questions' );
		$this->assertSame( $ceiling['max_plans'], $clamped['max_plans'], 'a request cannot raise max_plans' );

		$lowered = Budget::clamp(
			array(
				'max_questions' => 0,
				'max_plans'     => -1,
			),
			$ceiling
		);

		$this->assertSame( $ceiling['max_questions'], $lowered['max_questions'], 'a non-positive cap falls back to the ceiling' );
		$this->assertSame( $ceiling['max_plans'], $lowered['max_plans'], 'a non-positive cap falls back to the ceiling' );
	}
}
