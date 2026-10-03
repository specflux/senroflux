<?php
/**
 * Live J7 (a Contributor runs the posts pack): an account without
 * `upload_files` can add no image, so no image tool is offered, planned or
 * mentioned — across all three packs.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Packs\Posts;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Packs\Pack;
use Specflux\SenroFlux\Packs\Pages\PagesPack;
use Specflux\SenroFlux\Packs\Posts\PostsPack;
use Specflux\SenroFlux\Packs\Site\SitePack;
use Specflux\SenroFlux\Plugin;
use Specflux\SenroFlux\Tools\PlanTools;
use wpdb;

final class WithheldImagesTest extends TestCase {

	private const IMAGE_ROLES = array( 'search', 'missing-alt', 'upload', 'generate', 'alt-text', 'featured', 'alt', 'read-media', 'stock-search', 'stock-import' );

	protected function setUp(): void {
		Plugin::reset();
		remove_all_filters( 'senroflux_packs' );
		$GLOBALS['senroflux_test_current_user_id'] = 1;
		$GLOBALS['senroflux_test_user_caps_by_id'] = array(
			1 => array(
				'edit_posts'   => true,
				'upload_files' => false,
			),
		);
		$GLOBALS['wpdb']                           = new wpdb();
		Plugin::set_dependency_probe( true );
	}

	protected function tearDown(): void {
		remove_all_filters( 'senroflux_packs' );
		unset( $GLOBALS['wpdb'], $GLOBALS['senroflux_test_user_caps_by_id'] );
		Plugin::reset();
	}

	/** @return list<string> */
	private function imageAbilities( Pack $pack ): array {
		$resolved = $pack->resolveAbilities();
		$out      = array();
		foreach ( self::IMAGE_ROLES as $role ) {
			$out[] = $resolved[ $role ];
		}

		return $out;
	}

	public function test_every_pack_withholds_every_image_role_from_an_account_without_upload_files(): void {
		foreach ( array( new PostsPack(), new PagesPack(), new SitePack() ) as $pack ) {
			$caps = $pack->roleCapabilities();
			foreach ( self::IMAGE_ROLES as $role ) {
				$this->assertSame( 'upload_files', $caps[ $role ] ?? null, $pack->name() . ' ' . $role );
			}
			$this->assertCount( count( self::IMAGE_ROLES ), $caps, $pack->name() . ' withholds image roles only' );
		}
	}

	public function test_a_contributor_run_offers_no_image_ability_and_still_shows_the_notice(): void {
		$pack = new PostsPack();

		$method = new \ReflectionMethod( Plugin::class, 'withheldRolesFor' );
		$method->setAccessible( true );
		$withheld = $method->invoke( null, $pack, 1 );

		$resolved = $pack->resolveAbilities();
		$allow    = array_values( array_diff( $pack->allowList(), array_map( static fn ( $r ) => $resolved[ $r ], $withheld ) ) );

		foreach ( $this->imageAbilities( $pack ) as $ability ) {
			$this->assertNotContains( $ability, $allow, $ability );
		}
		$this->assertContains( 'senroflux/create-post', $allow );
		$this->assertContains( 'senroflux/set-terms', $allow );
		$this->assertEqualsCanonicalizing( self::IMAGE_ROLES, $withheld );
		$this->assertSame( 'Images are off for this run — your account can\'t upload files.', $pack->withheldRoleNotice( $withheld ) );
	}

	public function test_withheld_run_skills_name_no_image_verb(): void {
		$withheld = self::IMAGE_ROLES;
		foreach ( array( new PostsPack(), new PagesPack(), new SitePack() ) as $pack ) {
			$text = implode( "\n", array_map( static fn ( $s ) => $s->body, $pack->skillsForRun( true, $withheld ) ) );

			foreach ( array( 'media-search', 'media-upload', 'media-generate', 'generate-image', 'stock-image', 'media-stock', 'generate-alt-text', 'update-alt', 'read-media', 'set-featured-image', 'list-missing-alt' ) as $verb ) {
				$this->assertStringNotContainsString( $verb, $text, $pack->name() . ' ' . $verb );
			}
		}
	}

	public function test_posts_run_skills_keep_image_verbs_when_nothing_is_withheld(): void {
		$text = implode( "\n", array_map( static fn ( $s ) => $s->body, ( new PostsPack() )->skillsForRun( true, array() ) ) );

		$this->assertStringContainsString( 'posts/media-search', $text );
		$this->assertStringContainsString( 'stock-image-search', $text );
	}

	public function test_plan_step_description_does_not_ask_for_media_verbs_when_none_are_known(): void {
		$declaration = PlanTools::proposePlanDeclaration( array( 'posts/read', 'posts/create-draft' ) );
		$json        = is_array( $declaration ) ? (string) wp_json_encode( $declaration ) : '';
		$json        = '' === $json ? (string) wp_json_encode( $declaration->toArray() ) : $json;

		$this->assertStringContainsString( 'plan no image step', $json );
		$this->assertStringNotContainsString( 'media-stock-import, generate-alt-text', $json );
	}
}
