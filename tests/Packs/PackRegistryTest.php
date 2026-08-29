<?php
/**
 * PackRegistry + base Pack tests (stage 6): S9 registry contract (register /
 * resolve by name / unknown → null), S9 shape-compat resolution into
 * `core/*` vs `senroflux/*`, and the `start(pack)` unknown-pack path.
 *
 * The `pack_unknown` start test is a DIFF PROPOSAL against src/Plugin.php
 * (deliverable 4): it asserts behaviour that lands when `start()` gains the
 * `?string $pack` parameter. Until then it fails against the real plugin —
 * run it only after applying the proposal.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Packs;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Packs\Pack;
use Specflux\SenroFlux\Packs\PackRegistry;
use Specflux\SenroFlux\Plugin;
use Specflux\SenroFlux\Skills\Skill;
use Specflux\SenroFlux\Skills\SkillSource;
use WP_Error;
use wpdb;

final class PackRegistryTest extends TestCase {

	protected function setUp(): void {
		Plugin::reset();
		remove_all_filters( 'senroflux_packs' );
		remove_all_filters( 'senroflux_skills_max_tokens' );
		$GLOBALS['senroflux_test_abilities'] = array();
	}

	protected function tearDown(): void {
		remove_all_filters( 'senroflux_packs' );
		remove_all_filters( 'senroflux_skills_max_tokens' );
		unset( $GLOBALS['wpdb'] );
		Plugin::reset();
	}

	/**
	 * A minimal concrete pack for tests.
	 *
	 * @param string               $name  Pack name.
	 * @param array<string,string> $roles Role => template.
	 */
	private function pack( string $name, array $roles = array() ): Pack {
		return new class( $name, $roles ) extends Pack {
			/** @var string */
			private string $packName;

			/** @param array<string,string> $roles Role => template. */
			public function __construct( string $name, array $roles ) {
				$this->packName = $name;
				parent::__construct( $roles );
			}

			public function name(): string {
				return $this->packName;
			}
		};
	}

	public function test_register_and_resolve_by_name(): void {
		$registry = new PackRegistry();
		$registry->register( $this->pack( 'pages', array( 'read' => 'read-content' ) ) );

		$this->assertSame( 'pages', $registry->get( 'pages' )->name() );
	}

	public function test_get_unknown_name_returns_null(): void {
		$registry = new PackRegistry();
		$registry->register( $this->pack( 'pages' ) );

		$this->assertNull( $registry->get( 'nope' ) );
	}

	public function test_all_returns_registered_packs_keyed_by_name(): void {
		$registry = new PackRegistry();
		$registry->register( $this->pack( 'pages' ) );
		$registry->register( $this->pack( 'shop' ) );

		$all = $registry->all();

		$this->assertArrayHasKey( 'pages', $all );
		$this->assertArrayHasKey( 'shop', $all );
		$this->assertSame( 'shop', $all['shop']->name() );
	}

	public function test_from_filters_registers_only_packs_and_drops_junk(): void {
		add_filter(
			'senroflux_packs',
			static function ( array $packs ): array {
				return array_merge(
					$packs,
					array(
						'pages' => new class() extends Pack {
							public function name(): string {
								return 'pages';
							}
						},
						'junk'  => 'not-a-pack',
					)
				);
			},
			10,
			1
		);

		$registry = PackRegistry::fromFilters();

		$this->assertSame( array( 'pages' ), array_keys( $registry->all() ) );
	}

	public function test_resolve_abilities_adopts_core_when_shape_compatible(): void {
		// A core ability whose schema accepts every property the pack sends.
		$GLOBALS['senroflux_test_abilities']['core/read-content'] = new \SenroFlux_Test_Fake_Ability(
			'core/read-content',
			true,
			array(),
			'Read content.',
			array(
				'properties' => array(
					'id'        => array( 'type' => 'integer' ),
					'post_type' => array( 'type' => 'string' ),
					'fields'    => array( 'type' => 'array' ),
				),
			)
		);

		$pack = new class() extends Pack {
			public function __construct() {
				parent::__construct( array( 'read' => 'read-content' ) );
			}

			public function name(): string {
				return 'pages';
			}

			/** @return list<string> */
			protected function inputProperties( string $template ): array {
				unset( $template );

				return array( 'id', 'post_type', 'fields' );
			}
		};

		$this->assertSame( array( 'read' => 'core/read-content' ), $pack->resolveAbilities() );
		$this->assertSame( array( 'core/read-content' ), $pack->allowList() );
	}

	public function test_resolve_abilities_falls_back_to_senroflux_when_core_incompatible(): void {
		// Core ability exists but its schema lacks a property the pack sends.
		$GLOBALS['senroflux_test_abilities']['core/update-post'] = new \SenroFlux_Test_Fake_Ability(
			'core/update-post',
			true,
			array(),
			'Update a post.',
			array(
				'properties' => array(
					'id' => array( 'type' => 'integer' ),
				),
			)
		);

		$pack = new class() extends Pack {
			public function __construct() {
				parent::__construct( array( 'update' => 'update-post' ) );
			}

			public function name(): string {
				return 'pages';
			}

			/** @return list<string> */
			protected function inputProperties( string $template ): array {
				unset( $template );

				return array( 'id', 'content', 'status' );
			}
		};

		$this->assertSame( array( 'update' => 'senroflux/update-post' ), $pack->resolveAbilities() );
	}

	public function test_resolve_abilities_caches_per_request(): void {
		$pack   = $this->pack( 'pages', array( 'read' => 'read-content' ) );
		$first  = $pack->resolveAbilities();
		$second = $pack->resolveAbilities();

		$this->assertSame( $first, $second );
	}


	public function test_verb_for_maps_abilities_to_s10_verbs(): void {
		$pack = $this->pack( 'pages' );

		$this->assertSame( 'pages/read', $pack->verbFor( 'core/read-content', array( 'id' => 1 ) ) );
		$this->assertSame( 'pages/list-patterns', $pack->verbFor( 'senroflux/list-patterns', array() ) );
		$this->assertSame( 'pages/preview', $pack->verbFor( 'senroflux/get-preview-url', array( 'id' => 1 ) ) );
		$this->assertSame( 'pages/create-draft', $pack->verbFor( 'senroflux/create-post', array( 'status' => 'draft' ) ) );

		// update-post predicate: draft|pending unchanged → update-draft.
		$this->assertSame(
			'pages/update-draft',
			$pack->verbFor(
				'senroflux/update-post',
				array(
					'id'     => 1,
					'status' => 'draft',
				)
			)
		);
		// publish target UNCHANGED (current already publish) → update-live.
		$livePack = new class() extends Pack {
			public function name(): string {
				return 'pages';
			}

			protected function currentStatus( array $input ): string {
				return 'publish';
			}
		};
		$this->assertSame(
			'pages/update-live',
			$livePack->verbFor(
				'senroflux/update-post',
				array(
					'id'     => 1,
					'status' => 'publish',
				)
			)
		);
		// status transition to publish (current draft) → publish.
		$transitionPack = new class() extends Pack {
			public function name(): string {
				return 'pages';
			}

			protected function currentStatus( array $input ): string {
				return 'draft';
			}
		};
		$this->assertSame(
			'pages/publish',
			$transitionPack->verbFor(
				'senroflux/update-post',
				array(
					'id'     => 1,
					'status' => 'publish',
				)
			)
		);
	}

	public function test_verb_map_tiers_per_s10(): void {
		$pack = $this->pack( 'pages' );

		$this->assertSame(
			array(
				'pages/read'          => 0,
				'pages/list-patterns' => 0,
				'pages/preview'       => 0,
				'pages/create-draft'  => 1,
				'pages/update-draft'  => 1,
				'pages/update-live'   => 2,
				'pages/publish'       => 2,
			),
			$pack->verbMap()
		);
	}

	public function test_preflight_passes_within_skills_ceiling_and_no_as_binding(): void {
		$pack = $this->pack( 'pages' );

		$this->assertTrue( $pack->preflight( 1 ) );
	}

	public function test_preflight_returns_skills_too_large_when_ceiling_exceeded(): void {
		add_filter(
			'senroflux_skills_max_tokens',
			static fn ( int $max ): int => min( $max, 1 ),
			10,
			1
		);

		$pack = new class() extends Pack {
			public function name(): string {
				return 'pages';
			}

			/** @return list<Skill> */
			public function skills(): array {
				return array(
					new Skill(
						'pages/copy-rules',
						'Copy rules',
						str_repeat( 'x', 80 ),
						false,
						SkillSource::Pack
					),
				);
			}
		};

		$error = $pack->preflight( 1 );
		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 'skills_too_large', $error->get_error_code() );
	}

	/**
	 * DIFF PROPOSAL (deliverable 4): start('…', …, 'nope') must resolve the
	 * pack via PackRegistry and return `pack_unknown` (400). Fails against the
	 * real src/Plugin.php until start() accepts `?string $pack`.
	 */
	public function test_start_with_unknown_pack_returns_pack_unknown(): void {
		Plugin::set_dependency_probe( true );
		$GLOBALS['wpdb'] = new wpdb();

		$result = Plugin::instance()->start( 'specflux-mac', 'Goal', array(), array(), 'nope' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'pack_unknown', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}
}
