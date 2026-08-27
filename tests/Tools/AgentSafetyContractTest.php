<?php
/**
 * Consumer side of Agent Safety's frozen gate-error contract.
 *
 * @package SenroFlux
 */

declare(strict_types=1);

namespace Specflux\SenroFlux\Tests\Tools;

use PHPUnit\Framework\TestCase;
use Specflux\AgentSafety\Plugin\Tests\Fixtures\VerdictErrorFixture;
use Specflux\SenroFlux\Tools\ToolExecutor;
use SenroFlux_Test_Fake_Ability;
use WP_Error;

/**
 * Drives ToolExecutor with every WP_Error shape Agent Safety's verdict pipeline
 * can emit, taken from Agent Safety's OWN fixture file rather than a local
 * copy, so the two repositories cannot drift apart silently. The producer
 * side is agent-safety/plugin/tests/Verdict/VerdictErrorContractTest.php.
 *
 * The fixture is loaded by path from the sibling checkout (the two repos live
 * side by side in the working tree); when it is absent the test is skipped
 * loudly rather than passing vacuously.
 */
final class AgentSafetyContractTest extends TestCase {

	private const FIXTURE = '/agent-safety/plugin/tests/Fixtures/VerdictErrorFixture.php';

	protected function setUp(): void {
		$fixture = dirname( __DIR__, 3 ) . self::FIXTURE;
		if ( ! is_readable( $fixture ) ) {
			$this->markTestSkipped( 'Agent Safety checkout not found beside senroflux/; contract fixture unavailable at ' . $fixture );
		}
		require_once $fixture;
	}

	/**
	 * @return iterable<string, array{array{code: string, verb: string, tier: ?int, approval_id: ?string, data: array<string, mixed>}}>
	 */
	public static function cases(): iterable {
		$fixture = dirname( __DIR__, 3 ) . self::FIXTURE;
		if ( ! is_readable( $fixture ) ) {
			return;
		}
		require_once $fixture;
		foreach ( VerdictErrorFixture::cases() as $name => $shape ) {
			yield $name => array( $shape );
		}
	}

	/**
	 * @dataProvider cases
	 * @param array{code: string, verb: string, tier: ?int, approval_id: ?string, data: array<string, mixed>} $shape
	 */
	public function test_every_gate_error_shape_maps_to_the_expected_outcome( array $shape ): void {
		$GLOBALS['senroflux_test_abilities'] = array(
			$shape['verb'] => new SenroFlux_Test_Fake_Ability(
				$shape['verb'],
				permission_result: new WP_Error( $shape['code'], 'blocked', $shape['data'] )
			),
		);

		$outcome = ( new ToolExecutor() )->call( $shape['verb'], array( 'id' => 1 ) );

		if ( VerdictErrorFixture::APPROVAL_CODE === $shape['code'] ) {
			$this->assertSame( 'approval_required', $outcome->kind );
			$this->assertSame( (string) $shape['approval_id'], $outcome->approvalId, 'a missing approval_id key must read as an empty id, never fatal' );
			$this->assertSame( $shape['verb'], $outcome->verb );
			$this->assertSame( null === $shape['tier'] ? null : (string) $shape['tier'], $outcome->tier );
			return;
		}

		$this->assertSame( 'denied', $outcome->kind );
		$this->assertSame( VerdictErrorFixture::DENY_CODE, $outcome->errorCode );
	}
}
