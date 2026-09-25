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
				'max_steps'      => 60,
				'max_tool_calls' => 30,
				'max_tokens'     => 250000,
				'max_questions'  => 5,
				'max_plans'      => 3,
				'images'         => 6,
				'refunds'        => 1,
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
		$this->assertSame( 30, $defaults['max_tool_calls'], '…while the untouched keys keep their values' );
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
				'max_steps'      => 60,
				'max_tool_calls' => 7,
				'max_tokens'     => 250000,
				'max_questions'  => 2,
				'max_plans'      => 2,
				'images'         => 6,
				'refunds'        => 1,
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
			'images'         => 6,
			'refunds'        => 1,
		);

		$this->assertSame( $ceiling, Budget::clamp( 'junk', $ceiling ) );
		$this->assertSame(
			array(
				'max_steps'      => 2,
				'max_tool_calls' => 4,
				'max_tokens'     => 1000,
				'max_questions'  => 1,
				'max_plans'      => 3,
				'images'         => 1,
				'refunds'        => 1,
			),
			Budget::clamp(
				array(
					'max_steps'      => 2,
					'max_tool_calls' => 40,
					'max_tokens'     => '999999',
					'max_questions'  => 1,
					'max_plans'      => 9,
					'images'         => 1,
					'other'          => 1,
				),
				$ceiling
			)
		);
	}

	public function test_images_is_lower_only_like_every_other_cap(): void {
		// The S5 check: a consumer may lower `images` and may not raise it.
		$this->assertSame( 3, Budget::clamp( array( 'images' => 3 ), Budget::defaults() )['images'] );
		$this->assertSame( 6, Budget::clamp( array( 'images' => 99 ), Budget::defaults() )['images'] );
	}

	// ------------------------------------------------------------------
	// 0.3 S19: the `refunds` budget key.
	// ------------------------------------------------------------------

	public function test_refunds_defaults_to_one(): void {
		$this->assertSame( 1, Budget::defaults()['refunds'] );
	}

	public function test_refunds_is_lower_only_like_images(): void {
		$this->assertSame( 0, Budget::clamp( array( 'refunds' => 0 ), Budget::defaults() )['refunds'] );
		// An attempt to raise it above the shipped default is capped back down.
		$this->assertSame( 1, Budget::clamp( array( 'refunds' => 99 ), Budget::defaults() )['refunds'] );
	}

	public function test_refunds_allows_exactly_zero(): void {
		$sanitized = Budget::sanitize( array( 'refunds' => 0 ) );

		$this->assertSame( 0, $sanitized['refunds'] );
	}

	public function test_refunds_rejects_a_negative_value(): void {
		$sanitized = Budget::sanitize( array( 'refunds' => -1 ) );

		$this->assertSame( 1, $sanitized['refunds'], 'a negative refunds cap falls back to the default' );
	}

	// ------------------------------------------------------------------
	// 0.3 S19: Budget::spentCount() — the shared SPEND-counting helper
	// behind `images` and `refunds`.
	// ------------------------------------------------------------------

	public function test_spent_count_counts_only_ok_steps_for_the_named_ability(): void {
		$store  = new \Specflux\SenroFlux\Run\WpdbRunStore( new \wpdb() );
		$run_id = $store->createRun( 1, 'test', 'goal', array(), Budget::defaults() );

		$store->appendStep( $run_id, \Specflux\SenroFlux\Run\StepKind::ToolResult, null, 'senroflux/orders-refund', null, 'ok' );
		$store->appendStep( $run_id, \Specflux\SenroFlux\Run\StepKind::ToolResult, null, 'senroflux/orders-refund', null, 'ok' );
		// Refused: not spent.
		$store->appendStep( $run_id, \Specflux\SenroFlux\Run\StepKind::ToolResult, null, 'senroflux/orders-refund', null, 'refused' );
		// A different ability: not counted.
		$store->appendStep( $run_id, \Specflux\SenroFlux\Run\StepKind::ToolResult, null, 'senroflux/order-add-note', null, 'ok' );

		$this->assertSame( 2, Budget::spentCount( $store, $run_id, 'orders-refund' ) );
	}

	public function test_spent_count_is_zero_with_no_matching_steps(): void {
		$store  = new \Specflux\SenroFlux\Run\WpdbRunStore( new \wpdb() );
		$run_id = $store->createRun( 1, 'test', 'goal', array(), Budget::defaults() );

		$this->assertSame( 0, Budget::spentCount( $store, $run_id, 'orders-refund' ) );
	}

	public function test_sanitize_falls_back_to_the_filtered_defaults_not_the_shipped_ones(): void {
		add_filter(
			'senroflux_default_budget',
			static fn ( array $defaults ): array => array_merge(
				$defaults,
				array(
					'max_tokens'    => 1234,
					'max_questions' => 2,
				)
			),
			10,
			1
		);

		// A partial override must inherit the SITE's defaults for every key it
		// does not name — re-hardcoding the shipped table here would silently
		// discard `senroflux_default_budget`.
		$budget = Budget::sanitize( array( 'max_steps' => 7 ) );

		$this->assertSame( 7, $budget['max_steps'], 'the caller override wins' );
		$this->assertSame( 1234, $budget['max_tokens'], 'an unnamed key comes from the filtered defaults' );
		$this->assertSame( 2, $budget['max_questions'], 'an unnamed key comes from the filtered defaults' );

		// A non-array input is the same question with no overrides at all.
		$this->assertSame( Budget::defaults(), Budget::sanitize( null ) );
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

	// ------------------------------------------------------------------
	// 0.3 S7: Pack::defaultBudget() overrides.
	// ------------------------------------------------------------------

	private function sitePackOverrides(): array {
		return array(
			'max_steps'      => 200,
			'max_tool_calls' => 120,
			'max_tokens'     => 1000000,
			'max_questions'  => 8,
			'max_plans'      => 3,
			'images'         => 0,
		);
	}

	public function test_pack_overrides_apply_only_when_given(): void {
		// No override: byte-for-byte the shipped table, exactly as before S7
		// (pages/posts budgets are unaffected by the site pack's existence).
		$this->assertSame( Budget::defaults(), Budget::defaults( array() ) );
	}

	public function test_pack_overrides_become_the_new_baseline(): void {
		// The site pack's own override table (6 keys, no `refunds` opinion) is
		// applied over the SHIPPED table (S7), so the untouched `refunds` key
		// still comes through at its shipped default (1) — same rule as any
		// other key the pack override doesn't name.
		$this->assertSame(
			$this->sitePackOverrides() + array( 'refunds' => 1 ),
			Budget::defaults( $this->sitePackOverrides() )
		);
	}

	public function test_the_default_budget_filter_may_only_lower_a_pack_override_never_raise_it(): void {
		add_filter(
			'senroflux_default_budget',
			static fn ( array $defaults ): array => array_merge(
				$defaults,
				array(
					'max_steps' => 999999, // an attempt to raise it.
					'images'    => 5,      // an attempt to raise it above the pack's 0.
				)
			),
			10,
			1
		);

		$defaults = Budget::defaults( $this->sitePackOverrides() );

		$this->assertSame( 200, $defaults['max_steps'], 'the filter cannot raise a pack default' );
		$this->assertSame( 0, $defaults['images'], 'the filter cannot raise a pack default' );
	}

	public function test_the_default_budget_filter_may_still_lower_a_pack_override(): void {
		add_filter(
			'senroflux_default_budget',
			static fn ( array $defaults ): array => array_merge( $defaults, array( 'max_steps' => 50 ) ),
			10,
			1
		);

		$this->assertSame( 50, Budget::defaults( $this->sitePackOverrides() )['max_steps'] );
	}

	public function test_a_pack_override_leaves_the_global_filter_free_to_raise_a_packless_run(): void {
		// Pages/posts (no pack override) must see EXACTLY the pre-0.3 filter
		// behaviour — a filter may still move a key either direction.
		add_filter(
			'senroflux_default_budget',
			static fn ( array $defaults ): array => array_merge( $defaults, array( 'max_steps' => 999 ) ),
			10,
			1
		);

		$this->assertSame( 999, Budget::defaults()['max_steps'] );
	}

	public function test_sanitize_with_pack_overrides_is_also_lower_only(): void {
		$sanitized = Budget::sanitize( array( 'max_steps' => 999999 ), $this->sitePackOverrides() );

		$this->assertSame( 200, $sanitized['max_steps'], 'a caller override cannot raise a pack default either' );

		$lowered = Budget::sanitize( array( 'max_steps' => 10 ), $this->sitePackOverrides() );
		$this->assertSame( 10, $lowered['max_steps'], 'lowering still works' );
	}
}
