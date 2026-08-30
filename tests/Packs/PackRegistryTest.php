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
		remove_all_filters( 'agent_safety_governed_namespaces' );
		remove_all_filters( 'agent_safety_verb_map' );
		$GLOBALS['senroflux_test_abilities'] = array();
	}

	protected function tearDown(): void {
		remove_all_filters( 'senroflux_packs' );
		remove_all_filters( 'senroflux_skills_max_tokens' );
		remove_all_filters( 'agent_safety_governed_namespaces' );
		remove_all_filters( 'agent_safety_verb_map' );
		unset( $GLOBALS['wpdb'] );
		Plugin::reset();
	}

	/**
	 * A minimal concrete pack for tests.
	 *
	 * @param string                     $name       Pack name.
	 * @param array<string,string>       $roles      Role => template.
	 * @param array<string,int>          $verb_map   Verb => tier.
	 * @param array<string,list<string>> $role_verbs Role => verbs.
	 */
	private function pack( string $name, array $roles = array(), array $verb_map = array(), array $role_verbs = array() ): Pack {
		return new class( $name, $roles, $verb_map, $role_verbs ) extends Pack {
			/** @var string */
			private string $packName;

			/** @var array<string,int> */
			private array $verbTiers;

			/** @var array<string,list<string>> */
			private array $roleVerbs;

			/**
			 * @param string                     $name       Pack name.
			 * @param array<string,string>       $roles      Role => template.
			 * @param array<string,int>          $verb_map   Verb => tier.
			 * @param array<string,list<string>> $role_verbs Role => verbs.
			 */
			public function __construct( string $name, array $roles, array $verb_map, array $role_verbs ) {
				$this->packName  = $name;
				$this->verbTiers = $verb_map;
				$this->roleVerbs = $role_verbs;
				parent::__construct( $roles );
			}

			public function name(): string {
				return $this->packName;
			}

			/** @return array<string,int> */
			public function verbMap(): array {
				return $this->verbTiers;
			}

			/** @return array<string,list<string>> */
			public function roleVerbs(): array {
				return $this->roleVerbs;
			}

			protected function agentSafetyBindingError( int $user_id ): ?WP_Error {
				unset( $user_id );

				return null;
			}
		};
	}

	/**
	 * Apply one of AGENT SAFETY's filters. The hook name travels through a
	 * variable on purpose: phpcs reads a literal hook name as this plugin
	 * claiming the hook, and these two belong to Agent Safety.
	 *
	 * @param string $hook  The Agent Safety hook name.
	 * @param mixed  $value The value to filter.
	 * @return mixed
	 */
	private function applyAgentSafetyFilter( string $hook, mixed $value ): mixed {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- these two hooks are Agent Safety's, not this plugin's; the test only reads what our filters contributed to them.
		return apply_filters( $hook, $value );
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

							/** @return array<string,int> */
							public function verbMap(): array {
								return array();
							}

							protected function agentSafetyBindingError( int $user_id ): ?WP_Error {
								unset( $user_id );

								return null;
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

			/** @return array<string,int> */
			public function verbMap(): array {
				return array();
			}

			protected function agentSafetyBindingError( int $user_id ): ?WP_Error {
				unset( $user_id );

				return null;
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

			/** @return array<string,int> */
			public function verbMap(): array {
				return array();
			}

			protected function agentSafetyBindingError( int $user_id ): ?WP_Error {
				unset( $user_id );

				return null;
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

			/** @return array<string,int> */
			public function verbMap(): array {
				return array();
			}

			protected function agentSafetyBindingError( int $user_id ): ?WP_Error {
				unset( $user_id );

				return null;
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

	// ------------------------------------------------------------------
	// Agent Safety governance (S9): namespaces + verb map
	// ------------------------------------------------------------------

	public function test_base_verb_is_the_ability_id(): void {
		$pack = $this->pack( 'shop', array( 'read' => 'read-thing' ) );

		// S9 direct-allow reading: with no argument-aware predicate the verb
		// IS the ability id.
		$this->assertSame( 'senroflux/read-thing', $pack->verbFor( 'senroflux/read-thing', array( 'status' => 'publish' ) ) );
	}

	public function test_a_pack_with_roles_governs_the_polyfill_namespace(): void {
		$pack = $this->pack( 'shop', array( 'read' => 'read-thing' ) );

		$this->assertSame( array( 'senroflux/' ), $pack->governedNamespaces() );
	}

	public function test_a_pack_without_roles_governs_nothing(): void {
		$this->assertSame( array(), $this->pack( 'empty' )->governedNamespaces() );
	}

	public function test_agent_safety_verb_map_collapses_a_role_to_its_highest_tier(): void {
		$pack = $this->pack(
			'shop',
			array(
				'read'   => 'read-thing',
				'update' => 'update-thing',
			),
			array(
				'shop/read'         => 0,
				'shop/update-draft' => 1,
				'shop/publish'      => 2,
			),
			array(
				'read'   => array( 'shop/read' ),
				'update' => array( 'shop/update-draft', 'shop/publish' ),
			)
		);

		$this->assertSame(
			array(
				'senroflux/read-thing'   => 0,
				'senroflux/update-thing' => 2,
			),
			$pack->agentSafetyVerbMap()
		);
	}

	public function test_agent_safety_verb_map_fails_closed_for_an_undeclared_role(): void {
		$pack = $this->pack( 'shop', array( 'delete' => 'delete-thing' ) );

		$this->assertSame( array( 'senroflux/delete-thing' => 2 ), $pack->agentSafetyVerbMap() );
	}

	public function test_agent_safety_verb_map_fails_closed_for_a_verb_missing_from_the_map(): void {
		$pack = $this->pack(
			'shop',
			array( 'read' => 'read-thing' ),
			array(),
			array( 'read' => array( 'shop/read' ) )
		);

		$this->assertSame( array( 'senroflux/read-thing' => 2 ), $pack->agentSafetyVerbMap() );
	}

	public function test_registry_merge_keeps_the_higher_tier_for_a_shared_ability(): void {
		$registry = new PackRegistry();
		$registry->register(
			$this->pack(
				'lenient',
				array( 'update' => 'update-thing' ),
				array( 'a/write' => 1 ),
				array( 'update' => array( 'a/write' ) )
			)
		);
		$registry->register(
			$this->pack(
				'strict',
				array( 'update' => 'update-thing' ),
				array( 'b/publish' => 2 ),
				array( 'update' => array( 'b/publish' ) )
			)
		);

		$this->assertSame( array( 'senroflux/update-thing' => 2 ), $registry->agentSafetyVerbMap() );
		$this->assertSame( array( 'senroflux/' ), $registry->governedNamespaces() );
	}

	public function test_contribute_to_agent_safety_feeds_both_companion_filters(): void {
		add_filter(
			'senroflux_packs',
			fn ( array $packs ): array => $packs + array(
				'shop' => $this->pack(
					'shop',
					array( 'update' => 'update-thing' ),
					array(
						'shop/update-draft' => 1,
						'shop/publish'      => 2,
					),
					array( 'update' => array( 'shop/update-draft', 'shop/publish' ) )
				),
			),
			10,
			1
		);

		PackRegistry::contributeToAgentSafety();

		$this->assertContains( 'senroflux/', $this->applyAgentSafetyFilter( 'agent_safety_governed_namespaces', array( 'core/' ) ) );
		$this->assertSame(
			array(
				'core/read-content'      => 0,
				'senroflux/update-thing' => 2,
			),
			$this->applyAgentSafetyFilter( 'agent_safety_verb_map', array( 'core/read-content' => 0 ) )
		);
	}

	public function test_an_existing_verb_map_entry_is_never_overwritten(): void {
		add_filter(
			'senroflux_packs',
			fn ( array $packs ): array => $packs + array(
				'shop' => $this->pack(
					'shop',
					array( 'update' => 'update-thing' ),
					array( 'shop/publish' => 2 ),
					array( 'update' => array( 'shop/publish' ) )
				),
			),
			10,
			1
		);

		PackRegistry::contributeToAgentSafety();

		// Another contributor already answered for this ability: leave it be.
		$map = $this->applyAgentSafetyFilter( 'agent_safety_verb_map', array( 'senroflux/update-thing' => 1 ) );
		$this->assertSame( array( 'senroflux/update-thing' => 1 ), $map );
	}

	public function test_plugin_govern_registers_the_pages_pack_governance(): void {
		Plugin::instance()->govern();

		$this->assertContains( 'senroflux/', $this->applyAgentSafetyFilter( 'agent_safety_governed_namespaces', array() ) );

		$map = $this->applyAgentSafetyFilter( 'agent_safety_verb_map', array() );
		$this->assertSame( 0, $map['senroflux/read-content'] ?? null );
		$this->assertSame( 1, $map['senroflux/create-post'] ?? null );
		// update-post can publish, so Agent Safety must see it as irreversible.
		$this->assertSame( 2, $map['senroflux/update-post'] ?? null );
	}
}
