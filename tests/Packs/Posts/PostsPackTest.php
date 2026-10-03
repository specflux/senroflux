<?php
/**
 * PostsPack build-contract tests (S5).
 *
 * TARGET REPO PATH: tests/Packs/Posts/PostsPackTest.php
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Packs\Posts;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Run\Budget;
use Specflux\SenroFlux\Packs\Pages\PagesPack;
use Specflux\SenroFlux\Packs\PackRegistry;
use Specflux\SenroFlux\Packs\Posts\PostsPack;
use Specflux\SenroFlux\Skills\SkillSet;
use Specflux\SenroFlux\Skills\SkillSource;

final class PostsPackTest extends TestCase {

	public function test_name_and_run_capability(): void {
		$pack = new PostsPack();

		$this->assertSame( 'posts', $pack->name() );
		$this->assertSame( 'edit_posts', $pack->runCapability() );
	}

	public function test_verb_for_maps_every_ability_template(): void {
		$pack = new PostsPack();

		$this->assertSame( 'posts/read', $pack->verbFor( 'senroflux/read-content', array() ) );
		$this->assertSame( 'posts/list-patterns', $pack->verbFor( 'senroflux/list-patterns', array() ) );
		$this->assertSame( 'posts/preview', $pack->verbFor( 'senroflux/get-preview-url', array() ) );
		$this->assertSame( 'posts/media-search', $pack->verbFor( 'senroflux/media-search', array() ) );
		$this->assertSame( 'posts/list-missing-alt', $pack->verbFor( 'senroflux/list-missing-alt', array() ) );
		$this->assertSame( 'posts/create-draft', $pack->verbFor( 'senroflux/create-post', array() ) );
		$this->assertSame( 'posts/update-draft', $pack->verbFor( 'senroflux/update-post', array() ) );
		$this->assertSame( 'posts/media-upload', $pack->verbFor( 'senroflux/media-upload', array() ) );
		$this->assertSame( 'posts/media-generate', $pack->verbFor( 'senroflux/generate-image', array() ) );
		$this->assertSame( 'posts/generate-alt-text', $pack->verbFor( 'senroflux/generate-alt-text', array() ) );
		$this->assertSame( 'posts/set-featured-image', $pack->verbFor( 'senroflux/set-featured-image', array() ) );
		$this->assertSame( 'posts/update-alt', $pack->verbFor( 'senroflux/update-alt', array() ) );
		$this->assertSame( 'posts/set-terms', $pack->verbFor( 'senroflux/set-terms', array() ) );
		$this->assertSame( 'posts/create-term', $pack->verbFor( 'senroflux/create-term', array() ) );
	}

	/**
	 * Stock-photo-fallback build plan: the new verbs, at the right tiers, and
	 * `stock-import` withheld the same way `upload`/`generate` are.
	 */
	public function test_stock_image_verbs_are_tiered_and_gated_like_the_other_media_writes(): void {
		$pack = new PostsPack();

		$this->assertSame( 'posts/media-stock-search', $pack->verbFor( 'senroflux/stock-image-search', array() ) );
		$this->assertSame( 'posts/media-stock-import', $pack->verbFor( 'senroflux/stock-image-import', array() ) );

		$map = $pack->verbMap();
		$this->assertSame( 0, $map['posts/media-stock-search'] );
		$this->assertSame( 1, $map['posts/media-stock-import'] );

		$this->assertSame( array( 'posts/media-stock-search' ), $pack->roleVerbs()['stock-search'] );
		$this->assertSame( array( 'posts/media-stock-import' ), $pack->roleVerbs()['stock-import'] );

		$this->assertSame( 'upload_files', $pack->roleCapabilities()['stock-import'] );
		$this->assertSame( 'Images are off for this run — your account can\'t upload files.', $pack->withheldRoleNotice( array( 'stock-import' ) ) );
	}

	public function test_publish_verb_routes_publish_future_and_update_live(): void {
		$pack = new PostsPack();

		$this->assertSame( 'posts/publish', $pack->verbFor( 'senroflux/publish-post', array( 'status' => 'publish' ) ) );
		$this->assertSame( 'posts/schedule', $pack->verbFor( 'senroflux/publish-post', array( 'status' => 'future' ) ) );
		$this->assertSame( 'posts/update-live', $pack->verbFor( 'senroflux/publish-post', array( 'status' => 'draft' ) ) );
		$this->assertSame( 'posts/update-live', $pack->verbFor( 'senroflux/publish-post', array() ) );
	}

	public function test_verb_map_has_the_s5_tiers(): void {
		$map = ( new PostsPack() )->verbMap();

		foreach ( array( 'posts/read', 'posts/list-patterns', 'posts/preview', 'posts/media-search', 'posts/list-missing-alt', 'posts/generate-alt-text', 'posts/read-media' ) as $verb ) {
			$this->assertSame( 0, $map[ $verb ], $verb );
		}
		foreach ( array( 'posts/create-draft', 'posts/update-draft', 'posts/set-terms', 'posts/create-term', 'posts/media-upload', 'posts/media-generate', 'posts/set-featured-image', 'posts/update-alt' ) as $verb ) {
			$this->assertSame( 1, $map[ $verb ], $verb );
		}
		foreach ( array( 'posts/update-live', 'posts/publish', 'posts/schedule' ) as $verb ) {
			$this->assertSame( 2, $map[ $verb ], $verb );
		}
	}

	/**
	 * S12 (defect fix): `update-alt`'s write and `read-media`'s verification
	 * must resolve the SAME id key + prefix, or the generic harness tracker
	 * (Runner::trackObjects()) can never match one to the other.
	 */
	public function test_object_id_key_and_prefix_for_attachment_verbs(): void {
		$pack = new PostsPack();

		foreach ( array( 'posts/update-alt', 'posts/read-media' ) as $verb ) {
			$this->assertSame( 'attachment_id', $pack->objectIdKey( $verb ), $verb );
			$this->assertSame( 'attachment:', $pack->objectIdPrefix( $verb ), $verb );
		}

		// Every other verb keeps the harness base default: bare 'id', no prefix.
		$this->assertSame( 'id', $pack->objectIdKey( 'posts/read' ) );
		$this->assertSame( '', $pack->objectIdPrefix( 'posts/read' ) );
	}

	public function test_verb_for_maps_read_media(): void {
		$pack = new PostsPack();

		$this->assertSame( 'posts/read-media', $pack->verbFor( 'senroflux/read-media', array() ) );
	}

	public function test_role_capabilities_require_upload_files_for_every_image_role(): void {
		$pack = new PostsPack();

		$this->assertSame(
			array(
				'search'       => 'upload_files',
				'missing-alt'  => 'upload_files',
				'upload'       => 'upload_files',
				'generate'     => 'upload_files',
				'alt-text'     => 'upload_files',
				'featured'     => 'upload_files',
				'alt'          => 'upload_files',
				'read-media'   => 'upload_files',
				'stock-search' => 'upload_files',
				'stock-import' => 'upload_files',
			),
			$pack->roleCapabilities()
		);
	}

	/** Live J7: a Contributor guessed term names; list-terms is the Tier-0 read that shows the real ones. */
	public function test_list_terms_is_a_tier_zero_read_wired_through_every_seam(): void {
		$pack = new PostsPack();

		$this->assertSame( 'posts/list-terms', $pack->verbFor( 'senroflux/list-terms', array() ) );
		$this->assertSame( 0, $pack->verbMap()['posts/list-terms'] );
		$this->assertSame( array( 'posts/list-terms' ), $pack->roleVerbs()['list-terms'] );
		$this->assertSame( 'senroflux/list-terms', $pack->resolveAbilities()['list-terms'] );
		$this->assertContains( 'senroflux/list-terms', $pack->allowList() );
		$this->assertSame( 'senroflux/list-terms', $pack->gateVerbFor( 'posts/list-terms' ) );
		$this->assertSame( 0, $pack->agentSafetyVerbMap()['senroflux/list-terms'] );
		$this->assertArrayNotHasKey( 'list-terms', $pack->roleCapabilities(), 'not an image role: a Contributor keeps it' );
	}

	public function test_prose_rules_tell_the_model_to_list_terms_first_and_reuse_them(): void {
		$prose = array_values( array_filter( ( new PostsPack() )->skills(), static fn ( $s ) => 'posts/prose-rules' === $s->id ) );

		$this->assertCount( 1, $prose );
		$this->assertStringContainsString( 'call list-terms for category and for post_tag and reuse an existing name', $prose[0]->body );
		$this->assertStringContainsString( 'only if your account may create terms', $prose[0]->body );
		$this->assertStringContainsString( 'posts/list-terms', $prose[0]->body, 'a plan step may name the verb' );
	}

	public function test_withheld_role_notice(): void {
		$pack = new PostsPack();

		$this->assertSame( 'Images are off for this run — your account can\'t upload files.', $pack->withheldRoleNotice( array( 'upload' ) ) );
		$this->assertSame( 'Images are off for this run — your account can\'t upload files.', $pack->withheldRoleNotice( array( 'generate' ) ) );
		$this->assertNull( $pack->withheldRoleNotice( array( 'read' ) ) );
		$this->assertNull( $pack->withheldRoleNotice( array() ) );
	}

	/**
	 * Bug 2 (live run: a café photo repeated at the top of a post) — the
	 * model needs to know WHY, not just that a write got refused: TT25's
	 * single template prints the featured image above the post.
	 */
	public function test_media_rules_warns_against_repeating_the_featured_image_in_content(): void {
		$skills = ( new PostsPack() )->skills();
		$media  = array_values( array_filter( $skills, static fn ( $s ) => 'posts/media-rules' === $s->id ) );

		$this->assertCount( 1, $media );
		$this->assertStringContainsString( 'featured image', $media[0]->body );
		$this->assertStringContainsString( 'above the post', $media[0]->body );
	}

	/**
	 * Live J1: four runs, never a category or tag — nothing told the model a
	 * finished post has them, or how to get a term id.
	 */
	public function test_prose_rules_ask_for_terms_on_create_post_not_a_term_round_trip(): void {
		$skills = ( new PostsPack() )->skills();
		$prose  = array_values( array_filter( $skills, static fn ( $s ) => 'posts/prose-rules' === $s->id ) );

		$this->assertCount( 1, $prose );
		$this->assertStringContainsString( 'one real category (never Uncategorized)', $prose[0]->body );
		$this->assertStringContainsString( 'two to four tags', $prose[0]->body );
		$this->assertStringContainsString( '`categories` and `tags` (names) on create-post, in the same write', $prose[0]->body );
		$this->assertStringContainsString( 'only to change the terms of a post that already exists', $prose[0]->body );
	}

	public function test_skills_returns_three_pack_skills_never_content_language(): void {
		$skills = ( new PostsPack() )->skills();

		$this->assertCount( 3, $skills );
		$ids = array_map( static fn ( $s ) => $s->id, $skills );
		$this->assertContains( 'posts/prose-rules', $ids );
		$this->assertContains( 'posts/copy-rules', $ids );
		$this->assertContains( 'posts/media-rules', $ids );
		$this->assertNotContains( 'posts/content-language', $ids );

		foreach ( $skills as $skill ) {
			$this->assertSame( '1', $skill->version );
			$this->assertSame( SkillSource::Pack, $skill->source );
		}
	}

	public function test_a_posts_run_skill_set_stays_under_the_2000_token_ceiling(): void {
		$skills = SkillSet::collect( 'test-consumer', 'Write a blog post about coffee', new PostsPack(), null, 'en_US' );

		$this->assertNull( SkillSet::ceilingError( $skills ) );
	}

	public function test_agent_safety_pack_is_null_without_the_dependency(): void {
		$this->assertNull( ( new PostsPack() )->agentSafetyPack() );
	}

	public function test_preflight_fails_closed_without_agent_safety(): void {
		$result = ( new PostsPack() )->preflight( 1 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'pack_unbound', $result->get_error_code() );
	}

	/**
	 * The B3 stage-5 check: after the registry merges the pages pack and the
	 * posts pack, `senroflux/update-post` (the ability the two packs SHARE)
	 * must stay Tier 1 — neither pack's own role for it ever spans a Tier-2
	 * verb, so the merge's `max()` has nothing Tier-2 to pick up.
	 */
	public function test_registry_merge_with_pages_keeps_update_post_at_tier_1(): void {
		$registry = new PackRegistry();
		$registry->register( new PagesPack() );
		$registry->register( new PostsPack() );

		$map = $registry->agentSafetyVerbMap();

		$this->assertSame( 1, $map['senroflux/update-post'] );
		$this->assertSame( 2, $map['senroflux/publish-post'] );
	}

	/**
	 * space-bunny live runs (2026-09-28 bunny1-4, fix1) used 228k-286k tokens
	 * and 51-62 steps against the shipped 250000/60.
	 */
	public function test_default_budget_raises_steps_calls_and_tokens(): void {
		$this->assertSame(
			array(
				Budget::MAX_STEPS      => 90,
				Budget::MAX_TOOL_CALLS => 45,
				Budget::MAX_TOKENS     => 400000,
			),
			( new PostsPack() )->defaultBudget()
		);
	}
}
