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
			),
			Budget::defaults()
		);
	}

	public function test_defaults_are_filterable(): void {
		add_filter(
			'senroflux_default_budget',
			static fn ( array $defaults ): array => array_merge( $defaults, array( 'max_steps' => 3 ) ),
			10,
			1
		);

		$defaults = Budget::defaults();

		$this->assertSame( 3, $defaults['max_steps'], 'a site may lower a ceiling…' );
		$this->assertSame( 12, $defaults['max_tool_calls'], '…while the untouched keys keep their values' );
	}

	public function test_sanitize_drops_unknown_keys_and_non_positive_caps(): void {
		$sanitized = Budget::sanitize(
			array(
				'max_steps'      => 0,        // non-positive -> default
				'max_tool_calls' => '7',      // numeric string from JSON -> accepted
				'max_tokens'     => -5,       // negative -> default
				'max_ponies'     => 99,       // unknown -> dropped entirely
			)
		);

		$this->assertSame(
			array(
				'max_steps'      => 20,
				'max_tool_calls' => 7,
				'max_tokens'     => 60000,
			),
			$sanitized
		);
	}

	public function test_clamp_never_raises_a_cap_above_the_ceiling(): void {
		$ceiling = array(
			'max_steps'      => 10,
			'max_tool_calls' => 4,
			'max_tokens'     => 1000,
		);

		$this->assertSame( $ceiling, Budget::clamp( 'junk', $ceiling ) );
		$this->assertSame(
			array(
				'max_steps'      => 2,
				'max_tool_calls' => 4,
				'max_tokens'     => 1000,
			),
			Budget::clamp(
				array(
					'max_steps'      => 2,
					'max_tool_calls' => 40,
					'max_tokens'     => '999999',
					'other'          => 1,
				),
				$ceiling
			)
		);
	}
}
