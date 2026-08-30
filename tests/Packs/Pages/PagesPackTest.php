<?php
/**
 * PagesPack build-contract tests (stage 8, S9/S10/S13).
 *
 * TARGET REPO PATH: tests/Packs/Pages/PagesPackTest.php
 *
 * Covers the pack's own contract: the S10 verb map, the verb predicate, the
 * three pack skills, and the S13 fail-closed preflight (when Agent Safety is
 * absent the pack returns `pack_unbound`, never a gated pass).
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Packs\Pages;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Packs\Pages\PagesPack;
use Specflux\SenroFlux\Skills\SkillSource;
use WP_Error;

final class PagesPackTest extends TestCase {

	private function loadShims(): void {
		require_once dirname( __DIR__, 2 ) . '/stubs/blocks.php';
	}

	protected function setUp(): void {
		$this->loadShims();
		remove_all_filters( 'senroflux_skills_max_tokens' );
		remove_all_filters( 'senroflux_run_skills' );
	}

	public function test_name_is_pages(): void {
		$this->assertSame( 'pages', ( new PagesPack() )->name() );
	}

	public function test_roles_map_to_templates(): void {
		$roles = ( new PagesPack() )->roles();

		$this->assertSame(
			array(
				'read'     => 'read-content',
				'create'   => 'create-post',
				'update'   => 'update-post',
				'preview'  => 'get-preview-url',
				'patterns' => 'list-patterns',
			),
			$roles
		);
	}

	public function test_verb_map_matches_s10(): void {
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
			( new PagesPack() )->verbMap()
		);
	}

	public function test_verb_for_maps_abilities_to_s10_verbs(): void {
		$pack = new PagesPack();

		$this->assertSame( 'pages/read', $pack->verbFor( 'senroflux/read-content', array( 'id' => 1 ) ) );
		$this->assertSame( 'pages/list-patterns', $pack->verbFor( 'senroflux/list-patterns', array() ) );
		$this->assertSame( 'pages/preview', $pack->verbFor( 'senroflux/get-preview-url', array( 'id' => 1 ) ) );
		$this->assertSame( 'pages/create-draft', $pack->verbFor( 'senroflux/create-post', array( 'status' => 'draft' ) ) );

		// No current status (no post id) → a publish request is a transition.
		$this->assertSame(
			'pages/publish',
			$pack->verbFor(
				'senroflux/update-post',
				array(
					'id'     => 1,
					'status' => 'publish',
				)
			)
		);
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
	}

	public function test_verb_for_publish_unchanged_is_update_live(): void {
		$post                               = new \stdClass();
		$post->ID                           = 9;
		$post->post_type                    = 'page';
		$post->post_title                   = 'Live';
		$post->post_status                  = 'publish';
		$post->post_name                    = '';
		$post->post_parent                  = 0;
		$post->post_excerpt                 = '';
		$GLOBALS['senroflux_test_posts'][9] = $post;

		$pack = new PagesPack();
		$this->assertSame(
			'pages/update-live',
			$pack->verbFor(
				'senroflux/update-post',
				array(
					'id'     => 9,
					'status' => 'publish',
				)
			)
		);
	}

	public function test_skills_returns_three_pack_skills(): void {
		$skills = ( new PagesPack() )->skills();

		$this->assertCount( 3, $skills );
		$ids = array_map( static fn ( $s ) => $s->id, $skills );
		$this->assertContains( 'pages/layout-rules', $ids );
		$this->assertContains( 'pages/copy-rules', $ids );
		$this->assertContains( 'pages/content-language', $ids );

		foreach ( $skills as $skill ) {
			$this->assertSame( '1', $skill->version );
			$this->assertSame( SkillSource::Pack, $skill->source );
		}
	}

	public function test_preflight_fails_closed_without_agent_safety(): void {
		// Agent Safety is absent in the test harness → pack_unbound (fail closed).
		$result = ( new PagesPack() )->preflight( 1 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'pack_unbound', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	public function test_agent_safety_pack_is_null_when_class_absent(): void {
		if ( class_exists( \Specflux\AgentSafety\Packs\Pack::class ) ) {
			$this->markTestSkipped( 'Agent Safety core is loaded in this environment.' );
		}

		$this->assertNull( ( new PagesPack() )->agentSafetyPack() );
	}

	// ------------------------------------------------------------------
	// Agent Safety governance (S9): what the two AS filters get told
	// ------------------------------------------------------------------

	public function test_role_verbs_cover_every_role(): void {
		$pack = new PagesPack();

		$this->assertSame( array_keys( $pack->roles() ), array_keys( $pack->roleVerbs() ) );
	}

	public function test_every_declared_role_verb_is_in_the_verb_map(): void {
		$pack = new PagesPack();
		$map  = $pack->verbMap();

		foreach ( $pack->roleVerbs() as $role => $verbs ) {
			foreach ( $verbs as $verb ) {
				$this->assertArrayHasKey( $verb, $map, sprintf( 'role %s declares an unmapped verb %s', $role, $verb ) );
			}
		}
	}

	/**
	 * S14: a pre-approval grant is issued against the verb AGENT SAFETY sees —
	 * the resolved ability id — so both Tier-2 pack verbs land on the ONE
	 * ability that carries them, and a verb no role declares yields null (fail
	 * closed: no grant, the call parks).
	 */
	public function test_gate_verb_maps_a_pack_verb_onto_its_resolved_ability(): void {
		$pack = new PagesPack();

		$this->assertSame( 'senroflux/update-post', $pack->gateVerbFor( 'pages/publish' ) );
		$this->assertSame( 'senroflux/update-post', $pack->gateVerbFor( 'pages/update-live' ) );
		$this->assertSame( 'senroflux/update-post', $pack->gateVerbFor( 'pages/update-draft' ) );
		$this->assertSame( 'senroflux/create-post', $pack->gateVerbFor( 'pages/create-draft' ) );
		$this->assertSame( 'senroflux/read-content', $pack->gateVerbFor( 'pages/read' ) );
		$this->assertNull( $pack->gateVerbFor( 'pages/not-a-verb' ) );
	}

	public function test_pack_governs_the_senroflux_namespace(): void {
		$this->assertSame( array( 'senroflux/' ), ( new PagesPack() )->governedNamespaces() );
	}

	/**
	 * The Agent Safety verb map is keyed on ABILITY IDS, because that is what
	 * the gate seam passes to the pipeline as the verb — never on `pages/*`.
	 * update-post collapses UP to tier 2: it can publish, and Agent Safety
	 * carries one tier per verb.
	 */
	public function test_agent_safety_verb_map_is_ability_ids_at_the_highest_reachable_tier(): void {
		$this->assertSame(
			array(
				'senroflux/read-content'    => 0,
				'senroflux/create-post'     => 1,
				'senroflux/update-post'     => 2,
				'senroflux/get-preview-url' => 0,
				'senroflux/list-patterns'   => 0,
			),
			( new PagesPack() )->agentSafetyVerbMap()
		);
	}
}
