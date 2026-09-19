<?php
/**
 * Media registrar tests (0.3 S5, stage 6).
 *
 * TARGET REPO PATH: tests/Packs/Content/MediaTest.php
 *
 * Covers: the permission callback for every ability, `list-missing-alt`
 * capping at 25, the `images` budget exhausting `generate-image` on its 7th
 * call, and `update-alt` refusing a 26th distinct attachment in one run.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Packs\Content;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Model\MediaGatewayInterface;
use Specflux\SenroFlux\Packs\Content\Media;
use Specflux\SenroFlux\Run\Budget;
use Specflux\SenroFlux\Run\StepKind;
use Specflux\SenroFlux\Run\WpdbRunStore;
use WP_Error;
use wpdb;

final class MediaTest extends TestCase {

	private WpdbRunStore $store;

	private int $runId;

	private function loadShims(): void {
		require_once dirname( __DIR__, 2 ) . '/stubs/blocks.php';
		require_once dirname( __DIR__, 2 ) . '/stubs/media.php';
	}

	protected function setUp(): void {
		$this->loadShims();

		$GLOBALS['senroflux_test_abilities']          = array();
		$GLOBALS['senroflux_test_posts']              = array();
		$GLOBALS['senroflux_test_next_post_id']       = 1;
		$GLOBALS['senroflux_test_ability_categories'] = array();
		$GLOBALS['senroflux_test_user_caps']          = array();
		$GLOBALS['senroflux_test_postmeta']           = array();
		$GLOBALS['senroflux_test_terms']              = array();
		$GLOBALS['senroflux_test_post_terms']         = array();

		Media::reset();
		Media::registerCategory();
		Media::register();

		$this->store = new WpdbRunStore( new wpdb() );
		$this->runId = $this->store->createRun( 1, 'test', 'goal', array(), Budget::defaults() );
		Media::useRunContext( $this->runId, $this->store );
	}

	protected function tearDown(): void {
		Media::forgetRunContext();
		Media::setGateway( null );
	}

	private function grant( string ...$caps ): void {
		$GLOBALS['senroflux_test_user_caps'] = array();
		foreach ( $caps as $cap ) {
			$GLOBALS['senroflux_test_user_caps'][ $cap ] = true;
		}
	}

	private function ability( string $name ): object {
		$ability = wp_get_ability( $name );
		$this->assertIsObject( $ability, $name . ' must be registered' );

		return $ability;
	}

	private function seedAttachment( int $id, string $alt = '' ): void {
		$post                                   = new \stdClass();
		$post->ID                               = $id;
		$post->post_type                        = 'attachment';
		$post->post_mime_type                   = 'image/jpeg';
		$post->post_title                       = 'image-' . $id;
		$post->guid                             = 'https://example.test/wp-content/uploads/image-' . $id . '.jpg';
		$post->post_status                      = 'inherit';
		$post->post_parent                      = 0;
		$GLOBALS['senroflux_test_posts'][ $id ] = $post;
		if ( '' !== $alt ) {
			$GLOBALS['senroflux_test_postmeta'][ $id ]['_wp_attachment_image_alt'] = $alt;
		}
	}

	private function seedPost( int $id ): void {
		$post                                   = new \stdClass();
		$post->ID                               = $id;
		$post->post_type                        = 'post';
		$post->post_status                      = 'draft';
		$GLOBALS['senroflux_test_posts'][ $id ] = $post;
	}

	// ------------------------------------------------------------------
	// Registration + permission callbacks
	// ------------------------------------------------------------------

	public function test_all_nine_abilities_are_registered(): void {
		foreach (
			array(
				'senroflux/media-search',
				'senroflux/list-missing-alt',
				'senroflux/media-upload',
				'senroflux/generate-image',
				'senroflux/generate-alt-text',
				'senroflux/set-featured-image',
				'senroflux/update-alt',
				'senroflux/set-terms',
				'senroflux/create-term',
			) as $name
		) {
			$this->assertIsObject( wp_get_ability( $name ), $name );
		}
	}

	public function test_media_search_permission_requires_edit_posts(): void {
		$ability = $this->ability( 'senroflux/media-search' );

		$this->assertFalse( (bool) $ability->check_permissions( array( 'query' => 'x' ) ) );
		$this->grant( 'edit_posts' );
		$this->assertTrue( (bool) $ability->check_permissions( array( 'query' => 'x' ) ) );
	}

	public function test_media_upload_and_generate_image_require_upload_files(): void {
		foreach ( array( 'senroflux/media-upload', 'senroflux/generate-image' ) as $name ) {
			$ability = $this->ability( $name );

			$this->grant( 'edit_posts' );
			$this->assertFalse( (bool) $ability->check_permissions( array() ), $name );

			$this->grant( 'upload_files' );
			$this->assertTrue( (bool) $ability->check_permissions( array() ), $name );
		}
	}

	public function test_update_alt_and_generate_alt_text_require_edit_post_on_an_attachment(): void {
		$this->seedAttachment( 5 );
		$this->seedPost( 6 ); // Not an attachment.

		foreach ( array( 'senroflux/update-alt', 'senroflux/generate-alt-text' ) as $name ) {
			$ability = $this->ability( $name );

			$this->grant( 'edit_post' );
			$this->assertTrue( (bool) $ability->check_permissions( array( 'attachment_id' => 5 ) ), $name );
			// Refused on a non-attachment id even with the same capability.
			$this->assertFalse( (bool) $ability->check_permissions( array( 'attachment_id' => 6 ) ), $name );

			$this->grant();
			$this->assertFalse( (bool) $ability->check_permissions( array( 'attachment_id' => 5 ) ), $name );
		}
	}

	public function test_set_featured_image_permission_requires_edit_post_on_the_target_post(): void {
		$ability = $this->ability( 'senroflux/set-featured-image' );

		$this->assertFalse(
			(bool) $ability->check_permissions(
				array(
					'post_id'       => 6,
					'attachment_id' => 5,
				)
			)
		);
		$this->grant( 'edit_post' );
		$this->assertTrue(
			(bool) $ability->check_permissions(
				array(
					'post_id'       => 6,
					'attachment_id' => 5,
				)
			)
		);
	}

	public function test_set_terms_requires_edit_post_and_the_assign_terms_cap(): void {
		$ability = $this->ability( 'senroflux/set-terms' );
		$input   = array(
			'post_id'  => 6,
			'taxonomy' => 'category',
			'term_ids' => array( 1 ),
		);

		$this->assertFalse( (bool) $ability->check_permissions( $input ) );

		$this->grant( 'edit_post' );
		$this->assertFalse( (bool) $ability->check_permissions( $input ), 'edit_post alone is not enough' );

		$this->grant( 'edit_post', 'assign_categories' );
		$this->assertTrue( (bool) $ability->check_permissions( $input ) );
	}

	public function test_create_term_requires_the_manage_terms_cap(): void {
		$ability = $this->ability( 'senroflux/create-term' );

		$this->assertFalse(
			(bool) $ability->check_permissions(
				array(
					'taxonomy' => 'category',
					'name'     => 'News',
				)
			)
		);
		$this->grant( 'manage_categories' );
		$this->assertTrue(
			(bool) $ability->check_permissions(
				array(
					'taxonomy' => 'category',
					'name'     => 'News',
				)
			)
		);
	}

	// ------------------------------------------------------------------
	// list-missing-alt caps at 25
	// ------------------------------------------------------------------

	public function test_list_missing_alt_caps_at_25(): void {
		for ( $i = 1; $i <= 30; $i++ ) {
			$this->seedAttachment( $i );
		}
		// A few WITH alt text must be excluded, not just truncated off the end.
		$this->seedAttachment( 31, 'already has alt' );

		$result = $this->ability( 'senroflux/list-missing-alt' )->execute( array() );

		$this->assertIsArray( $result );
		$this->assertCount( 25, $result['attachments'] );
		$ids = array_column( $result['attachments'], 'id' );
		$this->assertNotContains( 31, $ids );
	}

	// ------------------------------------------------------------------
	// images budget (S5)
	// ------------------------------------------------------------------

	public function test_the_seventh_generate_image_call_is_refused(): void {
		$gateway = new class() implements MediaGatewayInterface {
			public int $calls = 0;

			public function generateImage( string $prompt ): array|WP_Error {
				unset( $prompt );
				++$this->calls;

				return array(
					'path'     => sys_get_temp_dir() . '/senroflux-generated.jpg',
					'filename' => 'senroflux-generated.jpg',
				);
			}

			public function generateAltText( string $image_url ): string|WP_Error {
				unset( $image_url );

				return 'alt';
			}
		};
		touch( $gateway->generateImage( '' )['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch -- test fixture file, not a WP runtime path.
		Media::setGateway( $gateway );

		$ability = $this->ability( 'senroflux/generate-image' );

		// Six prior successful generations, exactly at the shipped default.
		for ( $i = 0; $i < 6; $i++ ) {
			$this->store->appendStep( $this->runId, StepKind::ToolResult, null, 'senroflux/generate-image', null, 'ok' );
		}

		$result = $ability->execute( array( 'prompt' => 'a red bicycle' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'budget_exhausted', $result->get_error_code() );
	}

	public function test_generate_image_succeeds_under_the_budget(): void {
		$path = sys_get_temp_dir() . '/senroflux-generated-2.jpg';
		touch( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch -- test fixture file, not a WP runtime path.
		$gateway = new class( $path ) implements MediaGatewayInterface {
			public function __construct( private string $path ) {
			}

			public function generateImage( string $prompt ): array|WP_Error {
				unset( $prompt );

				return array(
					'path'     => $this->path,
					'filename' => basename( $this->path ),
				);
			}

			public function generateAltText( string $image_url ): string|WP_Error {
				unset( $image_url );

				return 'alt';
			}
		};
		Media::setGateway( $gateway );

		$result = $this->ability( 'senroflux/generate-image' )->execute( array( 'prompt' => 'a red bicycle' ) );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'attachment_id', $result );
	}

	public function test_raising_the_images_budget_above_the_shipped_default_is_clamped(): void {
		// S5: a consumer may lower `images` and may not raise it.
		$clamped = Budget::clamp( array( 'images' => 99 ), Budget::defaults() );

		$this->assertSame( 6, $clamped['images'] );
	}

	// ------------------------------------------------------------------
	// update-alt 25-attachment cap (S5)
	// ------------------------------------------------------------------

	public function test_update_alt_refuses_a_26th_distinct_attachment(): void {
		$ability = $this->ability( 'senroflux/update-alt' );

		for ( $i = 1; $i <= 25; $i++ ) {
			$this->seedAttachment( $i );
			$result = $ability->execute(
				array(
					'attachment_id' => $i,
					'alt'           => 'alt text ' . $i,
				)
			);
			$this->assertIsArray( $result, 'attachment ' . $i . ' should succeed' );
		}

		$this->seedAttachment( 26 );
		$result = $ability->execute(
			array(
				'attachment_id' => 26,
				'alt'           => 'one too many',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'attachment_cap', $result->get_error_code() );

		// Re-writing an ALREADY-recorded attachment is still free.
		$again = $ability->execute(
			array(
				'attachment_id' => 1,
				'alt'           => 'updated again',
			)
		);
		$this->assertIsArray( $again );
	}

	public function test_update_alt_refuses_empty_alt(): void {
		$this->seedAttachment( 1 );
		$result = $this->ability( 'senroflux/update-alt' )->execute(
			array(
				'attachment_id' => 1,
				'alt'           => '',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_alt', $result->get_error_code() );
	}
}
