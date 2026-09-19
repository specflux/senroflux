<?php
/**
 * Pack base-class tests: the gate-mode-aware preflight and
 * `requiresAgentSafety()` (0.3 S3).
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Packs;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Packs\Pack;
use Specflux\SenroFlux\Plugin;
use WP_Error;

final class PackTest extends TestCase {

	protected function setUp(): void {
		remove_all_filters( 'senroflux_skills_max_tokens' );
		remove_all_filters( 'senroflux_run_skills' );
		Plugin::reset();
	}

	protected function tearDown(): void {
		Plugin::reset();
		unset( $GLOBALS['senroflux_test_user_caps_by_id'] );
	}

	/** A pack that never refuses AS binding — the base's built-in path is what's tested. */
	private function ordinaryPack(): Pack {
		return new class() extends Pack {
			public function name(): string {
				return 'fixture';
			}

			public function verbMap(): array {
				return array();
			}

			protected function agentSafetyBindingError( int $user_id ): ?WP_Error {
				unset( $user_id );

				return null; // Always bound in AS mode.
			}
		};
	}

	public function test_requires_agent_safety_defaults_to_false(): void {
		$this->assertFalse( $this->ordinaryPack()->requiresAgentSafety() );
	}

	public function test_run_capability_defaults_to_empty_string(): void {
		$this->assertSame( '', $this->ordinaryPack()->runCapability() );
	}

	public function test_role_capabilities_defaults_to_empty(): void {
		$this->assertSame( array(), $this->ordinaryPack()->roleCapabilities() );
	}

	public function test_withheld_role_notice_defaults_to_null(): void {
		$this->assertNull( $this->ordinaryPack()->withheldRoleNotice( array( 'generate' ) ) );
	}

	public function test_a_pack_requiring_agent_safety_refuses_to_start_in_built_in_mode(): void {
		Plugin::set_dependency_probe( false ); // Agent Safety absent => built-in mode.

		$pack = new class() extends Pack {
			public function name(): string {
				return 'fixture-requires-as';
			}

			public function verbMap(): array {
				return array();
			}

			public function requiresAgentSafety(): bool {
				return true;
			}

			protected function agentSafetyBindingError( int $user_id ): ?WP_Error {
				unset( $user_id );

				return null;
			}
		};

		$result = $pack->preflight( 1 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'pack_requires_agent_safety', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] ?? null );
	}

	public function test_the_same_pack_is_fine_to_start_in_agent_safety_mode(): void {
		Plugin::set_dependency_probe( true ); // Agent Safety present => AS mode.

		$pack = new class() extends Pack {
			public function name(): string {
				return 'fixture-requires-as';
			}

			public function verbMap(): array {
				return array();
			}

			public function requiresAgentSafety(): bool {
				return true;
			}

			protected function agentSafetyBindingError( int $user_id ): ?WP_Error {
				unset( $user_id );

				return null; // Bound.
			}
		};

		$this->assertTrue( $pack->preflight( 1 ) );
	}

	public function test_empty_run_capability_never_blocks_built_in_mode(): void {
		Plugin::set_dependency_probe( false );

		// A pre-0.3 fixture pack that never overrode runCapability(): the
		// empty-string default must not refuse every built-in-mode start.
		$this->assertTrue( $this->ordinaryPack()->preflight( 1 ) );
	}

	public function test_a_run_capability_gates_built_in_mode(): void {
		Plugin::set_dependency_probe( false );

		$pack = new class() extends Pack {
			public function name(): string {
				return 'fixture-capability';
			}

			public function verbMap(): array {
				return array();
			}

			public function runCapability(): string {
				return 'edit_others_posts';
			}

			protected function agentSafetyBindingError( int $user_id ): ?WP_Error {
				unset( $user_id );

				return null;
			}
		};

		$GLOBALS['senroflux_test_user_caps_by_id'] = array( 9 => array( 'edit_others_posts' => false ) );
		$refused                                   = $pack->preflight( 9 );
		$this->assertInstanceOf( WP_Error::class, $refused );
		$this->assertSame( 'pack_unbound', $refused->get_error_code() );

		$GLOBALS['senroflux_test_user_caps_by_id'] = array( 9 => array( 'edit_others_posts' => true ) );
		$this->assertTrue( $pack->preflight( 9 ) );
	}
}
