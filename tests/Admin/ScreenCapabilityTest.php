<?php
/**
 * The shared S13 delegation allowance.
 *
 * TARGET REPO PATH: tests/Admin/ScreenCapabilityTest.php
 *
 * One helper now backs BOTH human seams (the screen's park handlers and the
 * admin-ajax poll the same screen drives), so its three promises are pinned
 * here: it re-checks the capability itself, it is scoped to one run, and it
 * leaves no trace behind.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Admin;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Admin\ScreenCapability;
use Specflux\SenroFlux\Packs\Pack;
use WP_Error;

final class ScreenCapabilityTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['senroflux_test_user_caps'] = array( 'manage_options' => true );
		$GLOBALS['senroflux_test_filters']   = array();
		remove_all_filters( 'senroflux_packs' );
		// S10: the computed default walks the registered packs in order —
		// pin the same shape production boots (pages 'edit_pages', posts
		// 'edit_posts', site 'manage_options') so a manage_options-only
		// viewer (every pre-existing test in this file) still resolves to
		// 'manage_options', exactly as the static pre-0.3 default did.
		$this->registerFixturePack( 'pages', 'edit_pages' );
		$this->registerFixturePack( 'posts', 'edit_posts' );
		$this->registerFixturePack( 'site', 'manage_options' );
	}

	protected function tearDown(): void {
		remove_all_filters( ScreenCapability::FILTER );
		remove_all_filters( 'senroflux_can_tick' );
		remove_all_filters( 'senroflux_packs' );
	}

	private function registerFixturePack( string $name, string $capability ): void {
		$pack = new class( $name, $capability ) extends Pack {
			public function __construct( private readonly string $packName, private readonly string $capability ) {
				parent::__construct( array() );
			}

			public function name(): string {
				return $this->packName;
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

		add_filter(
			'senroflux_packs',
			static fn ( array $packs ): array => $packs + array( $name => $pack ),
			10,
			1
		);
	}

	public function test_capability_defaults_to_manage_options(): void {
		$this->assertSame( 'manage_options', ScreenCapability::current() );
	}

	public function test_capability_is_filterable(): void {
		add_filter( ScreenCapability::FILTER, static fn (): string => 'edit_pages' );

		$this->assertSame( 'edit_pages', ScreenCapability::current() );
	}

	public function test_a_filter_returning_nonsense_falls_back_to_the_computed_default(): void {
		// Fail closed: an empty string would be a capability nobody has, but
		// relying on that is luck, not a rule.
		add_filter( ScreenCapability::FILTER, static fn (): string => '' );

		$this->assertSame( 'manage_options', ScreenCapability::current() );
	}

	public function test_an_editor_gets_the_first_pack_capability_they_hold(): void {
		$GLOBALS['senroflux_test_user_caps'] = array( 'edit_pages' => true );

		$this->assertSame( 'edit_pages', ScreenCapability::current() );
	}

	public function test_a_subscriber_holding_no_pack_capability_gets_do_not_allow(): void {
		$GLOBALS['senroflux_test_user_caps'] = array( 'read' => true );

		$this->assertSame( ScreenCapability::NOBODY, ScreenCapability::current() );
	}

	public function test_the_filter_is_applied_after_the_computation_not_instead_of_it(): void {
		// The filter receives the COMPUTED value, so a filter that inspects it
		// (rather than replacing it outright) sees the per-viewer result.
		$GLOBALS['senroflux_test_user_caps'] = array( 'edit_pages' => true );
		$seen                                = null;

		add_filter(
			ScreenCapability::FILTER,
			static function ( $computed ) use ( &$seen ) {
				$seen = $computed;

				return $computed;
			}
		);

		ScreenCapability::current();

		$this->assertSame( 'edit_pages', $seen );
	}

	public function test_without_the_capability_the_tick_is_never_called(): void {
		$GLOBALS['senroflux_test_user_caps'] = array( 'read' => true );

		$called = false;
		$result = ScreenCapability::tickAsScreen(
			7,
			static function () use ( &$called ): array {
				$called = true;

				return array();
			}
		);

		$this->assertFalse( $called, 'the closure must not run without the capability' );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'senroflux_forbidden', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	public function test_the_allowance_covers_only_the_run_being_ticked(): void {
		$mine  = (object) array( 'id' => 7 );
		$other = (object) array( 'id' => 8 );

		$seen = array();
		ScreenCapability::tickAsScreen(
			7,
			static function () use ( &$seen, $mine, $other ): array {
				// The Runner asks the filter with the run it is about to tick;
				// ask it about both, from INSIDE the allowance.
				$seen['mine']  = (bool) apply_filters( 'senroflux_can_tick', false, $mine );
				$seen['other'] = (bool) apply_filters( 'senroflux_can_tick', false, $other );

				return array( 'run' => array() );
			}
		);

		$this->assertTrue( $seen['mine'], 'the run being ticked is allowed' );
		$this->assertFalse( $seen['other'], 'any other run keeps the owner-only default' );
	}

	public function test_the_allowance_never_overrides_a_deny_into_the_next_call(): void {
		$run = (object) array( 'id' => 7 );

		ScreenCapability::tickAsScreen( 7, static fn (): array => array( 'run' => array() ) );

		$this->assertFalse(
			(bool) apply_filters( 'senroflux_can_tick', false, $run ),
			'the allowance is removed once the tick returns'
		);
	}

	public function test_the_allowance_is_removed_even_when_the_tick_throws(): void {
		$run = (object) array( 'id' => 7 );

		try {
			ScreenCapability::tickAsScreen(
				7,
				static function (): array {
					throw new \RuntimeException( 'boom' );
				}
			);
			$this->fail( 'the exception should have propagated' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'boom', $e->getMessage() );
		}

		$this->assertFalse( (bool) apply_filters( 'senroflux_can_tick', false, $run ) );
	}

	public function test_a_nonsense_tick_return_is_normalized_to_an_error(): void {
		/** @var mixed $result */
		$result = ScreenCapability::tickAsScreen( 7, static fn (): mixed => 'not a run state' );

		$this->assertInstanceOf( WP_Error::class, $result );
	}
}
