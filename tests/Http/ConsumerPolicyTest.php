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
				'max_tool_calls' => 12,
				'max_tokens'     => 10000,
				'max_questions'  => 5,
				'max_plans'      => 3,
			),
			$result['budget']
		);
	}

	public function test_registered_consumer_with_empty_allow_is_refused(): void {
		add_filter( ConsumerPolicy::FILTER, static fn (): array => array( 'mac' => array( 'allow' => array() ) ) );

		$this->assertInstanceOf( WP_Error::class, ConsumerPolicy::resolve( 'mac', null ) );
	}
}
