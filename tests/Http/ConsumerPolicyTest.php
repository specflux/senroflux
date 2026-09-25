<?php
/**
 * HTTP start policy: the server owns the allow-list; the request may only
 * lower the budget.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Http;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Http\ConsumerPolicy;
use WP_Error;

final class ConsumerPolicyTest extends TestCase {

	protected function tearDown(): void {
		remove_all_filters( ConsumerPolicy::FILTER );
	}

	public function test_unregistered_consumer_is_refused_with_403(): void {
		$result = ConsumerPolicy::resolve( 'anyone', array( 'max_tokens' => 5 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'senroflux_unknown_consumer', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	public function test_registered_consumer_gets_server_allow_list_and_clamped_budget(): void {
		add_filter(
			ConsumerPolicy::FILTER,
			static fn ( array $c ): array => $c + array(
				'mac' => array(
					'allow'  => array( 'marketing-analytics/*', '', 7 ),
					'budget' => array( 'max_tokens' => 10000 ),
				),
			)
		);

		$result = ConsumerPolicy::resolve(
			'mac',
			array(
				'max_tokens' => 999999999,
				'max_steps'  => '3',
				'allow'      => array( '*' ),
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( array( 'marketing-analytics/*' ), $result['allow'] );
		$this->assertSame(
			array(
				'max_steps'      => 3,
				'max_tool_calls' => 30,
				'max_tokens'     => 10000,
				'max_questions'  => 5,
				'max_plans'      => 3,
				'images'         => 6,
				'refunds'        => 1,
			),
			$result['budget']
		);
	}

	public function test_registered_consumer_with_empty_allow_is_refused(): void {
		add_filter( ConsumerPolicy::FILTER, static fn (): array => array( 'mac' => array( 'allow' => array() ) ) );

		$this->assertInstanceOf( WP_Error::class, ConsumerPolicy::resolve( 'mac', null ) );
	}

	/**
	 * S7's pack-baseline ceiling must never LOOSEN a consumer's own registered
	 * budget: a third-party consumer that registered `max_tool_calls => 10`
	 * must still be capped at 10 on a pack run whose own defaults raise the
	 * shipped 30 up to 120 — not silently reset to 120.
	 */
	public function test_pack_override_never_loosens_a_registered_consumer_budget(): void {
		add_filter(
			ConsumerPolicy::FILTER,
			static fn (): array => array(
				'mac' => array(
					'allow'  => array( 'marketing-analytics/*' ),
					'budget' => array( 'max_tool_calls' => 10 ),
				),
			)
		);

		$result = ConsumerPolicy::resolve( 'mac', null, array( 'max_tool_calls' => 120 ) );

		$this->assertIsArray( $result );
		$this->assertSame( 10, $result['budget']['max_tool_calls'] );
	}

	/**
	 * A consumer that registered no budget of its own gets the pack's raised
	 * defaults, per S7.
	 */
	public function test_pack_override_applies_when_consumer_sets_no_budget(): void {
		add_filter(
			ConsumerPolicy::FILTER,
			static fn (): array => array(
				'mac' => array( 'allow' => array( 'marketing-analytics/*' ) ),
			)
		);

		$result = ConsumerPolicy::resolve( 'mac', null, array( 'max_tool_calls' => 120 ) );

		$this->assertIsArray( $result );
		$this->assertSame( 120, $result['budget']['max_tool_calls'] );
	}

	/**
	 * A request may still only lower the resolved ceiling, never raise it,
	 * even once a pack override is in play.
	 */
	public function test_requested_budget_can_still_only_lower_with_pack_override(): void {
		add_filter(
			ConsumerPolicy::FILTER,
			static fn (): array => array(
				'mac' => array( 'allow' => array( 'marketing-analytics/*' ) ),
			)
		);

		$result = ConsumerPolicy::resolve(
			'mac',
			array( 'max_tool_calls' => 999999 ),
			array( 'max_tool_calls' => 120 )
		);

		$this->assertIsArray( $result );
		$this->assertSame( 120, $result['budget']['max_tool_calls'] );
	}
}
