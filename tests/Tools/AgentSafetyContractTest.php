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
 * loudly rather than passing vacuously. Set SENROFLUX_REQUIRE_AS_FIXTURE=1
 * (CI does) to turn that skip into a hard failure instead, so the contract
 * cannot silently stop being checked.
 */
final class AgentSafetyContractTest extends TestCase {

	private const FIXTURE = '/agent-safety/plugin/tests/Fixtures/VerdictErrorFixture.php';

	protected function setUp(): void {
		$fixture = dirname( __DIR__, 3 ) . self::FIXTURE;
		if ( ! is_readable( $fixture ) ) {
			if ( self::fixtureRequired() ) {
				$this->fail( 'SENROFLUX_REQUIRE_AS_FIXTURE is set but the Agent Safety contract fixture is unavailable at ' . $fixture );
			}
			$this->markTestSkipped( 'Agent Safety checkout not found beside senroflux/; contract fixture unavailable at ' . $fixture );
		}
		require_once $fixture;
	}

	/**
	 * Any set-and-not-obviously-falsy value counts as "required" — a strict
	 * `'1' === ...` check would silently stop guarding CI the moment someone
	 * writes `true` or an unquoted `1` in YAML, the same vacuous-pass failure
	 * mode this guard exists to close off.
	 */
	private static function fixtureRequired(): bool {
		$value = getenv( 'SENROFLUX_REQUIRE_AS_FIXTURE' );
		if ( false === $value ) {
			return false;
		}
		return ! in_array( strtolower( trim( $value ) ), array( '', '0', 'false' ), true );
	}

	/**
	 * @return iterable<string, array{array{code: string, verb: string, tier: ?int, approval_id: ?string, data: array<string, mixed>}}>
	 */
	public static function cases(): iterable {
		$fixture = dirname( __DIR__, 3 ) . self::FIXTURE;
		if ( ! is_readable( $fixture ) ) {
			// An empty data provider is a PHPUnit runner error, not a skip
			// (PHPUnit builds the test list from this before setUp() ever
			// runs). Yield one placeholder case so setUp() gets to decide
			// skip vs. hard failure instead of PHPUnit reporting a generic
			// "empty data set" error regardless of SENROFLUX_REQUIRE_AS_FIXTURE.
			yield 'fixture missing' => array(
				array(
					'code'        => '',
					'verb'        => '',
					'tier'        => null,
					'approval_id' => null,
					'data'        => array(),
				),
			);
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
