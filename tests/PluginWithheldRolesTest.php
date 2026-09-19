<?php
/**
 * Plugin-level tests for 0.3 S6: withheld roles are computed once at
 * start(), dropped from the run's tool set, persisted, and surfaced on
 * every read — a user holding every capability sees nothing withheld.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Packs\Pack;
use Specflux\SenroFlux\Plugin;
use Specflux\SenroFlux\Tools\ToolRegistry;
use WP_Error;
use wpdb;

final class PluginWithheldRolesTest extends TestCase {

	protected function setUp(): void {
		Plugin::reset();
		remove_all_filters( 'senroflux_packs' );
		$GLOBALS['senroflux_test_current_user_id'] = 1;
		$GLOBALS['senroflux_test_user_caps_by_id'] = array();
		$GLOBALS['wpdb']                           = new wpdb();
		Plugin::set_dependency_probe( true ); // AS mode: preflight's binding check is the fixture's own (always bound).
	}

	protected function tearDown(): void {
		remove_all_filters( 'senroflux_packs' );
		unset( $GLOBALS['wpdb'], $GLOBALS['senroflux_test_user_caps_by_id'] );
		Plugin::reset();
	}

	/** A fixture pack whose `generate` role requires `upload_files` (S6's own worked example). */
	private function registerFixturePack(): void {
		add_filter(
			'senroflux_packs',
			static function ( array $packs ): array {
				$packs['fixture'] = new class() extends Pack {
					public function __construct() {
						parent::__construct(
							array(
								'generate' => 'generate-image',
								'read'     => 'read-content',
							)
						);
					}

					public function name(): string {
						return 'fixture';
					}

					public function verbMap(): array {
						return array(
							'senroflux/generate-image' => 0,
							'senroflux/read-content'   => 0,
						);
					}

					public function roleCapabilities(): array {
						return array( 'generate' => 'upload_files' );
					}

					public function withheldRoleNotice( array $withheld ): ?string {
						return in_array( 'generate', $withheld, true )
							? 'This run cannot add images.'
							: null;
					}

					public function runCapability(): string {
						return 'edit_posts';
					}

					protected function agentSafetyBindingError( int $user_id ): ?WP_Error {
						unset( $user_id );

						return null; // Always bound — this test is not about S13.
					}
				};

				return $packs;
			}
		);
	}

	public function test_a_user_lacking_the_capability_gets_the_role_withheld(): void {
		$this->registerFixturePack();
		$GLOBALS['senroflux_test_user_caps_by_id'][1]['upload_files'] = false;

		$result = Plugin::instance()->start( 'specflux-mac', 'Write a post', array(), array(), 'fixture' );

		$this->assertIsArray( $result, is_object( $result ) ? $result->get_error_message() : '' );
		$run_id = $result['run']['id'];

		// Dropped from the run's tool set: the allow-list no longer admits
		// the withheld role's resolved ability, but keeps the other role's.
		$this->assertNotContains( 'senroflux/generate-image', $result['run']['allow'] );
		$this->assertContains( 'senroflux/read-content', $result['run']['allow'] );

		// Persisted, and surfaced on every read (S6/S12).
		$this->assertSame( array( 'generate' ), $result['run']['withheld_roles'] );

		$fresh = Plugin::instance()->get( $run_id );
		$this->assertIsArray( $fresh );
		$this->assertSame( array( 'generate' ), $fresh['run']['withheld_roles'] );
	}

	public function test_a_user_holding_the_capability_has_nothing_withheld(): void {
		$this->registerFixturePack();
		$GLOBALS['senroflux_test_user_caps_by_id'][1]['upload_files'] = true;

		$result = Plugin::instance()->start( 'specflux-mac', 'Write a post', array(), array(), 'fixture' );

		$this->assertIsArray( $result );
		$this->assertSame( array(), $result['run']['withheld_roles'] );
		$this->assertContains( 'senroflux/generate-image', $result['run']['allow'] );
	}

	/** ToolRegistry::forRun() is the actual tool-set seam the model sees. */
	public function test_the_withheld_ability_never_becomes_a_tool(): void {
		$this->registerFixturePack();
		$GLOBALS['senroflux_test_abilities']                          = array(
			'senroflux/generate-image' => new \SenroFlux_Test_Fake_Ability( 'senroflux/generate-image' ),
			'senroflux/read-content'   => new \SenroFlux_Test_Fake_Ability( 'senroflux/read-content' ),
		);
		$GLOBALS['senroflux_test_user_caps_by_id'][1]['upload_files'] = false;

		$result = Plugin::instance()->start( 'specflux-mac', 'Write a post', array(), array(), 'fixture' );
		$this->assertIsArray( $result );

		$prop = new \ReflectionMethod( Plugin::class, 'runner' );
		$prop->setAccessible( true );
		/** @var \Specflux\SenroFlux\Run\Runner $runner */
		$runner = $prop->invoke( Plugin::instance() );

		$run      = $runner->store()->getRun( $result['run']['id'] );
		$registry = ToolRegistry::forRun( $run );

		$this->assertFalse( $registry->admits( 'senroflux/generate-image' ) );
		$this->assertTrue( $registry->admits( 'senroflux/read-content' ) );
	}
}
