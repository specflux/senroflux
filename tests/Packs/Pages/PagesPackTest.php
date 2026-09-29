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
use Specflux\SenroFlux\Plugin;
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
		Plugin::reset();
	}

	protected function tearDown(): void {
		Plugin::reset();
		unset( $GLOBALS['senroflux_test_user_caps_by_id'] );
	}

	public function test_name_is_pages(): void {
		$this->assertSame( 'pages', ( new PagesPack() )->name() );
	}

	/**
	 * 0.3 quality fix: raised from the shipped 60/30/250000 once image work
	 * (cover-hero/media-text) made the shipped table too tight (a live run
	 * hit `max_steps` at 61). `images` stays at the shipped default.
	 */
	public function test_default_budget_raises_steps_calls_and_tokens_for_image_work(): void {
		$budget = ( new PagesPack() )->defaultBudget();

		$this->assertSame(
			array(
				'max_steps'      => 120,
				'max_tool_calls' => 60,
				'max_tokens'     => 1000000,
				'images'         => 6,
			),
			$budget
		);
	}

	public function test_roles_map_to_templates(): void {
		$roles = ( new PagesPack() )->roles();

		$this->assertSame(
			array(
				'read'         => 'read-content',
				'create'       => 'create-post',
				'update'       => 'update-post',
				'publish'      => 'publish-post',
				'preview'      => 'get-preview-url',
				'patterns'     => 'list-patterns',
				'search'       => 'media-search',
				'missing-alt'  => 'list-missing-alt',
				'upload'       => 'media-upload',
				'generate'     => 'generate-image',
				'alt-text'     => 'generate-alt-text',
				'featured'     => 'set-featured-image',
				'alt'          => 'update-alt',
				'read-media'   => 'read-media',
				'stock-search' => 'stock-image-search',
				'stock-import' => 'stock-image-import',
			),
			$roles
		);
	}

	public function test_verb_map_matches_s10(): void {
		$this->assertSame(
			array(
				'pages/read'               => 0,
				'pages/list-patterns'      => 0,
				'pages/preview'            => 0,
				'pages/media-search'       => 0,
				'pages/list-missing-alt'   => 0,
				'pages/generate-alt-text'  => 0,
				'pages/read-media'         => 0,
				'pages/media-stock-search' => 0,
				'pages/create-draft'       => 1,
				'pages/update-draft'       => 1,
				'pages/media-upload'       => 1,
				'pages/media-generate'     => 1,
				'pages/set-featured-image' => 1,
				'pages/update-alt'         => 1,
				'pages/media-stock-import' => 1,
				'pages/update-live'        => 2,
				'pages/publish'            => 2,
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

		// 0.3 S4: update-post is draft-state edits only, so it is ALWAYS
		// pages/update-draft regardless of args — the split moved every
		// publish-adjacent verb onto publish-post.
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

		// No current status (no post id) → a publish request is a transition.
		$this->assertSame(
			'pages/publish',
			$pack->verbFor(
				'senroflux/publish-post',
				array(
					'id'     => 1,
					'status' => 'publish',
				)
			)
		);
	}

	/**
	 * Stock-photo-fallback build plan: the new verbs, at the right tiers, and
	 * `stock-import` withheld the same way `upload`/`generate` are.
	 */
	public function test_stock_image_verbs_are_tiered_and_gated_like_the_other_media_writes(): void {
		$pack = new PagesPack();

		$this->assertSame( 'pages/media-stock-search', $pack->verbFor( 'senroflux/stock-image-search', array() ) );
		$this->assertSame( 'pages/media-stock-import', $pack->verbFor( 'senroflux/stock-image-import', array() ) );

		$map = $pack->verbMap();
		$this->assertSame( 0, $map['pages/media-stock-search'] );
		$this->assertSame( 1, $map['pages/media-stock-import'] );

		$this->assertSame( array( 'pages/media-stock-search' ), $pack->roleVerbs()['stock-search'] );
		$this->assertSame( array( 'pages/media-stock-import' ), $pack->roleVerbs()['stock-import'] );

		$this->assertSame( 'upload_files', $pack->roleCapabilities()['stock-import'] );
		$this->assertArrayNotHasKey( 'stock-search', $pack->roleCapabilities() );
		$this->assertSame( 'This run cannot add images.', $pack->withheldRoleNotice( array( 'stock-import' ) ) );
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
				'senroflux/publish-post',
				array(
					'id'     => 9,
					'status' => 'publish',
				)
			)
		);
	}

	/** Editing an already-public post through publish-post with NO status change is also update-live. */
	public function test_verb_for_publish_post_editing_a_public_post_with_no_status_is_update_live(): void {
		$post                                = new \stdClass();
		$post->ID                            = 10;
		$post->post_type                     = 'page';
		$post->post_title                    = 'Live';
		$post->post_status                   = 'publish';
		$post->post_name                     = '';
		$post->post_parent                   = 0;
		$post->post_excerpt                  = '';
		$GLOBALS['senroflux_test_posts'][10] = $post;

		$pack = new PagesPack();
		$this->assertSame(
			'pages/update-live',
			$pack->verbFor( 'senroflux/publish-post', array( 'id' => 10 ) )
		);
	}

	public function test_skills_returns_two_pack_skills(): void {
		// 0.3 S5: `pages/content-language` is promoted to the harness's own
		// `harness/content-language`. 0.3 quality feature 4 adds a third,
		// `pages/media-rules`, for the pack's new image verbs.
		$skills = ( new PagesPack() )->skills();

		$this->assertCount( 3, $skills );
		$ids = array_map( static fn ( $s ) => $s->id, $skills );
		$this->assertContains( 'pages/layout-rules', $ids );
		$this->assertContains( 'pages/copy-rules', $ids );
		$this->assertContains( 'pages/media-rules', $ids );
		$this->assertNotContains( 'pages/content-language', $ids );

		foreach ( $skills as $skill ) {
			$this->assertSame( '1', $skill->version );
			$this->assertSame( SkillSource::Pack, $skill->source );
		}
	}

	/**
	 * S12 (defect fix, mirrors PostsPackTest): `update-alt`'s write and
	 * `read-media`'s verification must resolve the SAME id key + prefix.
	 */
	public function test_object_id_key_and_prefix_for_attachment_verbs(): void {
		$pack = new PagesPack();

		foreach ( array( 'pages/update-alt', 'pages/read-media' ) as $verb ) {
			$this->assertSame( 'attachment_id', $pack->objectIdKey( $verb ), $verb );
			$this->assertSame( 'attachment:', $pack->objectIdPrefix( $verb ), $verb );
		}

		$this->assertSame( 'id', $pack->objectIdKey( 'pages/read' ) );
		$this->assertSame( '', $pack->objectIdPrefix( 'pages/read' ) );
	}

	public function test_verb_for_maps_media_verbs(): void {
		$pack = new PagesPack();

		$this->assertSame( 'pages/media-search', $pack->verbFor( 'senroflux/media-search', array() ) );
		$this->assertSame( 'pages/list-missing-alt', $pack->verbFor( 'senroflux/list-missing-alt', array() ) );
		$this->assertSame( 'pages/media-upload', $pack->verbFor( 'senroflux/media-upload', array() ) );
		$this->assertSame( 'pages/media-generate', $pack->verbFor( 'senroflux/generate-image', array() ) );
		$this->assertSame( 'pages/generate-alt-text', $pack->verbFor( 'senroflux/generate-alt-text', array() ) );
		$this->assertSame( 'pages/set-featured-image', $pack->verbFor( 'senroflux/set-featured-image', array() ) );
		$this->assertSame( 'pages/update-alt', $pack->verbFor( 'senroflux/update-alt', array() ) );
		$this->assertSame( 'pages/read-media', $pack->verbFor( 'senroflux/read-media', array() ) );
	}

	public function test_role_capabilities_require_upload_files_for_media_roles_only(): void {
		$pack = new PagesPack();

		$this->assertSame(
			array(
				'upload'       => 'upload_files',
				'generate'     => 'upload_files',
				'stock-import' => 'upload_files',
			),
			$pack->roleCapabilities()
		);
	}

	public function test_withheld_role_notice(): void {
		$pack = new PagesPack();

		$this->assertSame( 'This run cannot add images.', $pack->withheldRoleNotice( array( 'upload' ) ) );
		$this->assertSame( 'This run cannot add images.', $pack->withheldRoleNotice( array( 'generate' ) ) );
		$this->assertNull( $pack->withheldRoleNotice( array( 'read' ) ) );
		$this->assertNull( $pack->withheldRoleNotice( array() ) );
	}

	public function test_preflight_fails_closed_without_agent_safety(): void {
		// 0.3 S3: Agent Safety absent resolves built-in mode, whose whole test
		// is the run capability (edit_pages) — absent here too, so this still
		// fails closed to pack_unbound, for a different reason than 0.2's
		// unconditional AS-binding check.
		$result = ( new PagesPack() )->preflight( 1 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'pack_unbound', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	public function test_preflight_passes_in_built_in_mode_when_edit_pages_is_held(): void {
		Plugin::set_dependency_probe( false ); // Agent Safety absent => built-in mode.
		$GLOBALS['senroflux_test_user_caps_by_id'] = array( 7 => array( 'edit_pages' => true ) );

		$result = ( new PagesPack() )->preflight( 7 );

		$this->assertTrue( $result );
	}

	public function test_preflight_still_refuses_in_built_in_mode_without_edit_pages(): void {
		Plugin::set_dependency_probe( false );
		$GLOBALS['senroflux_test_user_caps_by_id'] = array( 7 => array( 'edit_pages' => false ) );

		$result = ( new PagesPack() )->preflight( 7 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'pack_unbound', $result->get_error_code() );
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

		$this->assertSame( 'senroflux/publish-post', $pack->gateVerbFor( 'pages/publish' ) );
		$this->assertSame( 'senroflux/publish-post', $pack->gateVerbFor( 'pages/update-live' ) );
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
	 * 0.3 S4: `update-post` and `publish-post` are now separate abilities, each
	 * spanning only the verbs its own role can produce, so a draft edit no
	 * longer collapses up to Tier 2 (0.2's bug).
	 */
	public function test_agent_safety_verb_map_is_ability_ids_at_the_highest_reachable_tier(): void {
		$this->assertSame(
			array(
				'senroflux/read-content'       => 0,
				'senroflux/create-post'        => 1,
				'senroflux/update-post'        => 1,
				'senroflux/publish-post'       => 2,
				'senroflux/get-preview-url'    => 0,
				'senroflux/list-patterns'      => 0,
				'senroflux/media-search'       => 0,
				'senroflux/list-missing-alt'   => 0,
				'senroflux/media-upload'       => 1,
				'senroflux/generate-image'     => 1,
				'senroflux/generate-alt-text'  => 0,
				'senroflux/set-featured-image' => 1,
				'senroflux/update-alt'         => 1,
				'senroflux/read-media'         => 0,
				'senroflux/stock-image-search' => 0,
				'senroflux/stock-image-import' => 1,
			),
			( new PagesPack() )->agentSafetyVerbMap()
		);
	}
}
