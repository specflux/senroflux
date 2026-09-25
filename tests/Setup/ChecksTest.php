<?php
/**
 * The ONE setup-check evaluator (0.3 S11).
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Setup;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Packs\Pack;
use Specflux\SenroFlux\Plugin;
use Specflux\SenroFlux\Setup\Checks;
use Specflux\SenroFlux\Setup\SetupCheck;
use WP_Error;

final class ChecksTest extends TestCase {

	protected function setUp(): void {
		Plugin::reset();
		// Reset to the suite-wide default (tests/bootstrap.php), not null: null
		// falls through to the real, always-unconfigured `wordpress/php-ai-client`
		// registry in CI, which would strand every test file that runs after
		// this one in the same process once `Plugin::start()` also asks this
		// check (0.3 S11 follow-up).
		Checks::setProviderProbe( true );
		$GLOBALS['senroflux_test_user_caps']       = array();
		$GLOBALS['senroflux_test_user_caps_by_id'] = array();
		$GLOBALS['senroflux_test_user_meta']       = array();
	}

	protected function tearDown(): void {
		Checks::setProviderProbe( true );
		Plugin::reset();
	}

	// ------------------------------------------------------------------
	// senroflux/provider
	//
	// `wordpress/php-ai-client` is a REAL composer dev dependency (needed at
	// runtime by AiClientGateway) — its classes are the genuine library, not
	// something a test double can reliably shadow (whichever test in the
	// suite touches them FIRST wins the class declaration, which makes a
	// class-mocking approach order-dependent — verified as an actual flake
	// while writing this suite, not a hypothetical). `setProviderProbe()` is
	// the seam that keeps this check's PASS/FAIL branches deterministic,
	// mirroring the codebase's existing `Plugin::set_dependency_probe()`.
	// ------------------------------------------------------------------

	public function test_provider_check_has_the_right_id(): void {
		$this->assertSame( 'senroflux/provider', Checks::providerCheck()->id() );
	}

	public function test_the_probe_drives_provider_check_pass_and_fail(): void {
		Checks::setProviderProbe( true );

		$this->assertTrue( Checks::providerCheck()->passed() );

		Checks::setProviderProbe( false );

		$check = Checks::providerCheck();
		$this->assertFalse( $check->passed() );
		$this->assertSame( 'senroflux_provider_unconfigured', $check->errorCode() );
	}

	// ------------------------------------------------------------------
	// senroflux/agent-safety
	// ------------------------------------------------------------------

	public function test_agent_safety_advisory_is_null_when_agent_safety_is_available(): void {
		Plugin::set_dependency_probe( true );

		$this->assertNull( Checks::agentSafetyAdvisory( 1 ) );
	}

	public function test_agent_safety_advisory_appears_when_absent_and_not_dismissed(): void {
		Plugin::set_dependency_probe( false );

		$check = Checks::agentSafetyAdvisory( 1 );

		$this->assertNotNull( $check );
		$this->assertSame( 'senroflux/agent-safety', $check->id() );
		$this->assertSame( SetupCheck::ADVISORY, $check->severity() );
		$this->assertFalse( $check->isBlocking() );
	}

	public function test_agent_safety_advisory_dismissal_is_per_user_and_never_rearms(): void {
		Plugin::set_dependency_probe( false );

		Checks::dismissAgentSafetyFor( 1 );

		$this->assertNull( Checks::agentSafetyAdvisory( 1 ), 'dismissing user no longer sees it' );
		$this->assertNotNull( Checks::agentSafetyAdvisory( 2 ), 'a different user still sees it' );
	}

	// ------------------------------------------------------------------
	// forPack() / firstBlockingFailure()
	// ------------------------------------------------------------------

	public function test_for_pack_folds_in_the_packs_own_checks(): void {
		Plugin::set_dependency_probe( false ); // built-in mode
		Checks::setProviderProbe( true );
		$GLOBALS['senroflux_test_user_caps_by_id'][7]['edit_pages'] = true;

		$pack = $this->fixturePack( 'edit_pages' );

		$checks = Checks::forPack( $pack, 7 );
		$ids    = array_map( static fn ( SetupCheck $c ): string => $c->id(), $checks );

		$this->assertContains( 'senroflux/provider', $ids );
		$this->assertContains( 'fixture/capability', $ids );
	}

	public function test_first_blocking_failure_skips_advisory_checks(): void {
		$advisory = new SetupCheck( 'x/advisory', SetupCheck::ADVISORY, false, 'x' );
		$blocking = new SetupCheck( 'x/blocking', SetupCheck::BLOCKING, false, 'x' );

		$failure = Checks::firstBlockingFailure( array( $advisory, $blocking ) );

		$this->assertNotNull( $failure );
		$this->assertSame( 'x/blocking', $failure->id() );
	}

	public function test_first_blocking_failure_is_null_when_all_pass(): void {
		$check = new SetupCheck( 'x/y', SetupCheck::BLOCKING, true, 'x' );

		$this->assertNull( Checks::firstBlockingFailure( array( $check ) ) );
	}

	private function fixturePack( string $capability ): Pack {
		return new class( $capability ) extends Pack {
			public function __construct( private readonly string $capability ) {
				parent::__construct( array() );
			}

			public function name(): string {
				return 'fixture';
			}

			public function verbMap(): array {
				return array();
			}

			public function runCapability(): string {
				return $this->capability;
			}

			protected function agentSafetyBindingError( int $user_id ): ?WP_Error {
				unset( $user_id );

				return null;
			}
		};
	}
}
