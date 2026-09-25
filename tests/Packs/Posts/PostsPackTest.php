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

	public function test_role_capabilities_require_upload_files_for_media_roles_only(): void {
		$pack = new PostsPack();

		$this->assertSame(
			array(
				'upload'   => 'upload_files',
				'generate' => 'upload_files',
			),
			$pack->roleCapabilities()
		);
	}

	public function test_withheld_role_notice(): void {
		$pack = new PostsPack();

		$this->assertSame( 'This run cannot add images.', $pack->withheldRoleNotice( array( 'upload' ) ) );
		$this->assertSame( 'This run cannot add images.', $pack->withheldRoleNotice( array( 'generate' ) ) );
		$this->assertNull( $pack->withheldRoleNotice( array( 'read' ) ) );
		$this->assertNull( $pack->withheldRoleNotice( array() ) );
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
}
