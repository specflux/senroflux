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
use Specflux\SenroFlux\Model\ImageCapability;
use Specflux\SenroFlux\Model\MediaGatewayInterface;
use Specflux\SenroFlux\Model\StockImageGatewayInterface;
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
		$GLOBALS['senroflux_test_term_rows']          = array();
		$GLOBALS['senroflux_test_post_terms']         = array();

		$GLOBALS['senroflux_test_transients'] = array();

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
		Media::setStockGateway( null );
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

	public function test_all_thirteen_abilities_are_registered(): void {
		foreach (
			array(
				'senroflux/media-search',
				'senroflux/list-missing-alt',
				'senroflux/media-upload',
				'senroflux/generate-image',
				'senroflux/generate-alt-text',
				'senroflux/set-featured-image',
				'senroflux/update-alt',
				'senroflux/read-media',
				'senroflux/set-terms',
				'senroflux/create-term',
				'senroflux/list-terms',
				'senroflux/stock-image-search',
				'senroflux/stock-image-import',
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

	/**
	 * Bug 1 (live run: the same hero + media-text images on every page) —
	 * `media-search` should mark an already-used image the same way
	 * `stock-image-search` already does, and rank it last.
	 */
	public function test_media_search_ranks_an_image_already_used_in_a_pages_content_last(): void {
		$this->seedAttachment( 501 );
		$this->seedAttachment( 502 );

		$page                                 = new \stdClass();
		$page->ID                             = 900;
		$page->post_type                      = 'page';
		$page->post_status                    = 'publish';
		$page->post_content                   = '<!-- wp:image {"alt":"x"} --><figure class="wp-block-image"><img src="https://example.test/wp-content/uploads/501.jpg" alt="x"/></figure><!-- /wp:image -->';
		$GLOBALS['senroflux_test_posts'][900] = $page;

		$this->grant( 'edit_posts' );
		$result = $this->ability( 'senroflux/media-search' )->execute( array( 'query' => 'image' ) );

		$this->assertIsArray( $result );
		$ids = array_column( $result['results'], 'id' );
		$this->assertSame( array( 502, 501 ), $ids, 'the already-used image drops to the end without being removed' );
		$this->assertFalse( $result['results'][0]['already_used'] );
		$this->assertTrue( $result['results'][1]['already_used'] );
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

		foreach ( array( 'senroflux/update-alt', 'senroflux/generate-alt-text', 'senroflux/read-media' ) as $name ) {
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

	/**
	 * Bug 2 (live run: café photo repeated at the top of a post). TT25's
	 * `single.html` prints the featured image above the content, so setting
	 * an attachment as featured when it is already an image block in the
	 * post's own content would show it twice.
	 */
	public function test_set_featured_image_refuses_an_attachment_already_in_the_posts_content(): void {
		$this->seedAttachment( 5 );
		$post                               = new \stdClass();
		$post->ID                           = 6;
		$post->post_type                    = 'post';
		$post->post_status                  = 'draft';
		$post->post_content                 = '<!-- wp:paragraph --><p>Hello there.</p><!-- /wp:paragraph -->'
			. '<!-- wp:image {"alt":"A cafe photo"} --><figure class="wp-block-image"><img src="https://example.test/wp-content/uploads/5.jpg" alt="A cafe photo"/></figure><!-- /wp:image -->';
		$GLOBALS['senroflux_test_posts'][6] = $post;

		$this->grant( 'edit_post' );
		$result = $this->ability( 'senroflux/set-featured-image' )->execute(
			array(
				'post_id'       => 6,
				'attachment_id' => 5,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'featured_image_duplicated', $result->get_error_code() );
		$this->assertStringContainsString( "already in the post's content", $result->get_error_message() );
		$this->assertStringContainsString( 'update-draft', $result->get_error_message() );
		$this->assertArrayNotHasKey( 6, $GLOBALS['senroflux_test_postmeta'] ?? array() );
	}

	/** The same attachment set as featured on a DIFFERENT post is unaffected. */
	public function test_set_featured_image_allows_an_attachment_used_in_a_different_posts_content(): void {
		$this->seedAttachment( 5 );
		$other                              = new \stdClass();
		$other->ID                          = 7;
		$other->post_type                   = 'post';
		$other->post_status                 = 'draft';
		$other->post_content                = '<!-- wp:image {"alt":"A cafe photo"} --><figure class="wp-block-image"><img src="https://example.test/wp-content/uploads/5.jpg" alt="A cafe photo"/></figure><!-- /wp:image -->';
		$GLOBALS['senroflux_test_posts'][7] = $other;
		$post                               = new \stdClass();
		$post->ID                           = 6;
		$post->post_type                    = 'post';
		$post->post_status                  = 'draft';
		$post->post_content                 = '<!-- wp:paragraph --><p>Hello there.</p><!-- /wp:paragraph -->';
		$GLOBALS['senroflux_test_posts'][6] = $post;

		$this->grant( 'edit_post' );
		$result = $this->ability( 'senroflux/set-featured-image' )->execute(
			array(
				'post_id'       => 6,
				'attachment_id' => 5,
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 5, $GLOBALS['senroflux_test_postmeta'][6]['_thumbnail_id'] ?? null );
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

	public function test_create_term_requires_the_manage_terms_cap_for_a_category_and_says_so(): void {
		$ability = $this->ability( 'senroflux/create-term' );
		$input   = array(
			'taxonomy' => 'category',
			'name'     => 'News',
		);

		$refusal = $ability->check_permissions( $input );
		$this->assertInstanceOf( WP_Error::class, $refusal );
		$this->assertSame( 'term_create_forbidden', $refusal->get_error_code() );
		$this->assertStringContainsString( 'can\'t create categories', $refusal->get_error_message() );
		$this->assertStringContainsString( 'list-terms', $refusal->get_error_message() );

		$this->grant( 'assign_categories' );
		$this->assertInstanceOf( WP_Error::class, $ability->check_permissions( $input ), 'assigning is not creating' );

		$this->grant( 'manage_categories' );
		$this->assertTrue( $ability->check_permissions( $input ) );
	}

	/** A flat taxonomy is created with the assign cap, like core REST and create-post. */
	public function test_create_term_for_a_tag_needs_only_the_assign_cap(): void {
		$ability = $this->ability( 'senroflux/create-term' );
		$input   = array(
			'taxonomy' => 'post_tag',
			'name'     => 'Kitchen',
		);

		$this->assertFalse( (bool) $ability->check_permissions( $input ) );
		$this->grant( 'assign_post_tags' );
		$this->assertTrue( $ability->check_permissions( $input ) );
	}

	// ------------------------------------------------------------------
	// list-terms
	// ------------------------------------------------------------------

	public function test_list_terms_permission_is_the_taxonomys_assign_cap(): void {
		$ability = $this->ability( 'senroflux/list-terms' );

		$this->assertFalse( (bool) $ability->check_permissions( array( 'taxonomy' => 'category' ) ) );
		$this->grant( 'assign_post_tags' );
		$this->assertFalse( (bool) $ability->check_permissions( array( 'taxonomy' => 'category' ) ), 'another taxonomy\'s cap is not enough' );
		$this->assertTrue( (bool) $ability->check_permissions( array( 'taxonomy' => 'post_tag' ) ) );
		$this->assertFalse( (bool) $ability->check_permissions( array( 'taxonomy' => 'product_cat' ) ), 'only category and post_tag' );
		$this->grant( 'assign_categories' );
		$this->assertTrue( (bool) $ability->check_permissions( array( 'taxonomy' => 'category' ) ) );
	}

	public function test_list_terms_returns_names_and_counts_most_used_first_and_schema_valid(): void {
		$GLOBALS['senroflux_test_term_rows'] = array(
			'category' => array(
				'Recipes'          => 3,
				'Food &amp; Drink' => 12,
				'Gardening'        => 7,
			),
		);

		$result = $this->ability( 'senroflux/list-terms' )->execute( array( 'taxonomy' => 'category' ) );

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame(
			array(
				array(
					'name'  => 'Food & Drink',
					'count' => 12,
				),
				array(
					'name'  => 'Gardening',
					'count' => 7,
				),
				array(
					'name'  => 'Recipes',
					'count' => 3,
				),
			),
			$result['terms']
		);

		$narrowed = $this->ability( 'senroflux/list-terms' )->execute(
			array(
				'taxonomy' => 'category',
				'search'   => 'garden',
			)
		);
		$this->assertSame( array( 'Gardening' ), array_column( $narrowed['terms'], 'name' ) );
	}

	public function test_list_terms_returns_at_most_fifty_and_refuses_another_taxonomy(): void {
		$rows = array();
		for ( $i = 1; $i <= 60; $i++ ) {
			$rows[ 'tag ' . $i ] = $i;
		}
		$GLOBALS['senroflux_test_term_rows'] = array( 'post_tag' => $rows );

		$result = $this->ability( 'senroflux/list-terms' )->execute( array( 'taxonomy' => 'post_tag' ) );
		$this->assertCount( 50, $result['terms'] );
		$this->assertSame( 60, $result['terms'][0]['count'] );

		$this->assertInstanceOf( WP_Error::class, $this->ability( 'senroflux/list-terms' )->execute( array( 'taxonomy' => 'nav_menu' ) ) );
	}

	/** Real WordPress refuses an undeclared input field before execute runs; the stub does not. */
	public function test_list_terms_schema_declares_every_field(): void {
		$schema = $this->ability( 'senroflux/list-terms' )->get_input_schema();

		$this->assertFalse( $schema['additionalProperties'] );
		$this->assertSame( array( 'taxonomy', 'search' ), array_keys( $schema['properties'] ) );
		$this->assertSame( array( 'category', 'post_tag' ), $schema['properties']['taxonomy']['enum'] );
		$this->assertSame( array( 'taxonomy' ), $schema['required'] );
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

	public function test_a_no_model_failure_tells_the_model_to_use_stock_and_blocks_later_calls_and_runs(): void {
		$gateway = new class() implements MediaGatewayInterface {
			public int $calls = 0;

			public function generateImage( string $prompt ): array|WP_Error {
				unset( $prompt );
				++$this->calls;

				return new WP_Error( 'image_generation_unavailable', 'No models found that support image_generation for this prompt.' );
			}

			public function generateAltText( string $image_url ): string|WP_Error {
				unset( $image_url );

				return 'alt';
			}
		};
		Media::setGateway( $gateway );
		$ability = $this->ability( 'senroflux/generate-image' );

		$first = $ability->execute( array( 'prompt' => 'a red bicycle' ) );
		$this->assertInstanceOf( WP_Error::class, $first );
		$this->assertSame( 'image_generation_unavailable', $first->get_error_code() );
		$this->assertStringContainsString( 'stock-image-search then stock-image-import', $first->get_error_message() );
		$this->assertSame( 1, $gateway->calls );

		// Same run, second call: refused with the same message, provider untouched.
		$second = $ability->execute( array( 'prompt' => 'a blue bicycle' ) );
		$this->assertInstanceOf( WP_Error::class, $second );
		$this->assertSame( $first->get_error_message(), $second->get_error_message() );
		$this->assertSame( 1, $gateway->calls, 'the second call never reaches the gateway' );

		// New runs withhold the tool.
		ImageCapability::setProbe( true );
		$this->assertFalse( ImageCapability::available() );
		ImageCapability::setProbe( null );
	}

	public function test_a_transient_failure_is_not_remembered(): void {
		$gateway = new class() implements MediaGatewayInterface {
			public int $calls = 0;

			public function generateImage( string $prompt ): array|WP_Error {
				unset( $prompt );
				++$this->calls;

				return new WP_Error( 'gateway_failed', 'cURL error 28: Operation timed out' );
			}

			public function generateAltText( string $image_url ): string|WP_Error {
				unset( $image_url );

				return 'alt';
			}
		};
		Media::setGateway( $gateway );
		$ability = $this->ability( 'senroflux/generate-image' );

		$ability->execute( array( 'prompt' => 'a red bicycle' ) );
		$ability->execute( array( 'prompt' => 'a red bicycle' ) );

		$this->assertSame( 2, $gateway->calls );
		ImageCapability::setProbe( true );
		$this->assertTrue( ImageCapability::available() );
		ImageCapability::setProbe( null );
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
	// generate-alt-text sends a LOCAL file path, never the public URL
	// (0.3 quality fix 3)
	// ------------------------------------------------------------------

	/**
	 * On the pre-fix ability, `generateAltText()` was called with
	 * `attachmentUrl($id)` — the public URL. This fails against that code
	 * because the stub records exactly what it was called with.
	 */
	public function test_generate_alt_text_sends_the_attachments_local_file_path(): void {
		$this->seedAttachment( 5 );
		$filename = 'senroflux-alt-5-large.jpg';
		$path     = wp_upload_dir()['basedir'] . '/' . $filename;
		touch( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch -- test fixture file, not a WP runtime path.
		$GLOBALS['senroflux_test_intermediate_sizes'][5]['large'] = array( 'path' => $filename );

		$gateway = new class() implements MediaGatewayInterface {
			public array $seen = array();
			public function generateImage( string $prompt ): array|WP_Error {
				unset( $prompt );

				return new WP_Error( 'not_used', 'not used' );
			}
			public function generateAltText( string $image_path ): string|WP_Error {
				$this->seen[] = $image_path;

				return 'A red bicycle.';
			}
		};
		Media::setGateway( $gateway );

		$result = $this->ability( 'senroflux/generate-alt-text' )->execute( array( 'attachment_id' => 5 ) );

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( 'A red bicycle.', $result['alt'] );
		$this->assertCount( 1, $gateway->seen );
		$this->assertStringStartsNotWith( 'http', $gateway->seen[0], 'must be a local path, never a public URL' );
		$this->assertSame( $path, $gateway->seen[0] );
	}

	/** A registered attachment whose file is missing on disk is refused clearly. */
	public function test_generate_alt_text_refuses_when_the_attachment_file_is_missing(): void {
		$this->seedAttachment( 9 );
		// No `senroflux_test_attached_files`/`senroflux_test_intermediate_sizes`
		// entry for id 9: get_attached_file() and image_get_intermediate_size()
		// both report nothing on disk.

		$result = $this->ability( 'senroflux/generate-alt-text' )->execute( array( 'attachment_id' => 9 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'attachment_file_missing', $result->get_error_code() );
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

	// ------------------------------------------------------------------
	// read-media (defect fix): the re-read path update-alt never had
	// ------------------------------------------------------------------

	public function test_read_media_returns_the_current_snapshot(): void {
		$this->seedAttachment( 9, 'a red bicycle' );
		$GLOBALS['senroflux_test_attachment_metadata'][9] = array(
			'width'  => 640,
			'height' => 480,
		);

		$result = $this->ability( 'senroflux/read-media' )->execute( array( 'attachment_id' => 9 ) );

		$this->assertIsArray( $result );
		$this->assertSame( 9, $result['attachment_id'] );
		$this->assertSame( 'a red bicycle', $result['alt'] );
		$this->assertSame( 640, $result['width'] );
		$this->assertSame( 480, $result['height'] );
		$this->assertSame( array(), $result['featured_on'] );
	}

	public function test_read_media_lists_posts_using_it_as_the_featured_image(): void {
		$this->seedAttachment( 9 );
		$this->seedPost( 20 );
		$this->seedPost( 21 );
		$GLOBALS['senroflux_test_postmeta'][20]['_thumbnail_id'] = 9;

		$result = $this->ability( 'senroflux/read-media' )->execute( array( 'attachment_id' => 9 ) );

		$this->assertIsArray( $result );
		$this->assertSame( array( 20 ), $result['featured_on'] );
	}

	public function test_read_media_refuses_a_non_attachment(): void {
		$this->seedPost( 6 );

		$result = $this->ability( 'senroflux/read-media' )->execute( array( 'attachment_id' => 6 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'not_found', $result->get_error_code() );
	}

	// ------------------------------------------------------------------
	// stock-image-search / stock-image-import (stock-photo fallback)
	// ------------------------------------------------------------------

	private const VALID_STOCK_ID = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';

	/**
	 * @param list<array<string,mixed>>|WP_Error $search_result
	 * @param array<string,mixed>|WP_Error        $fetch_result
	 * @param array{path:string,filename:string}|WP_Error $download_result
	 */
	private function fakeStockGateway(
		array|WP_Error $search_result = array(),
		array|WP_Error $fetch_result = array(),
		array|WP_Error $download_result = array()
	): StockImageGatewayInterface {
		return new class( $search_result, $fetch_result, $download_result ) implements StockImageGatewayInterface {
			public array $searchCalls   = array();
			public array $fetchCalls    = array();
			public array $downloadCalls = array();

			public function __construct(
				private array|WP_Error $searchResult,
				private array|WP_Error $fetchResult,
				private array|WP_Error $downloadResult
			) {
			}

			public function search( string $query ): array|WP_Error {
				$this->searchCalls[] = $query;

				return $this->searchResult;
			}

			public function fetch( string $id ): array|WP_Error {
				$this->fetchCalls[] = $id;

				return $this->fetchResult;
			}

			public function download( array $detail ): array|WP_Error {
				$this->downloadCalls[] = $detail;

				return $this->downloadResult;
			}
		};
	}

	private function eligibleDetail( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'                  => self::VALID_STOCK_ID,
				'title'               => 'A red bicycle',
				'url'                 => 'https://stock.example/photo.jpg',
				'creator'             => 'Jane Photographer',
				'license'             => 'cc0',
				'license_version'     => '1.0',
				'source'              => 'wordpress',
				'foreign_landing_url' => 'https://stock.example/photos/1',
				'mature'              => false,
			),
			$overrides
		);
	}

	public function test_stock_image_search_permission_requires_edit_posts(): void {
		$ability = $this->ability( 'senroflux/stock-image-search' );

		$this->assertFalse( (bool) $ability->check_permissions( array( 'query' => 'x' ) ) );
		$this->grant( 'edit_posts' );
		$this->assertTrue( (bool) $ability->check_permissions( array( 'query' => 'x' ) ) );
	}

	public function test_stock_image_import_permission_requires_upload_files(): void {
		$ability = $this->ability( 'senroflux/stock-image-import' );

		$this->grant( 'edit_posts' );
		$this->assertFalse( (bool) $ability->check_permissions( array() ) );

		$this->grant( 'upload_files' );
		$this->assertTrue( (bool) $ability->check_permissions( array() ) );
	}

	public function test_stock_image_search_maps_provider_fields(): void {
		$raw = array(
			array(
				'id'      => self::VALID_STOCK_ID,
				'title'   => 'A red bicycle',
				'tags'    => array( array( 'name' => 'bicycle' ), array( 'name' => 'red' ), 'outdoor', 'x', 'y', 'z', 'overflow' ),
				'creator' => 'Jane Photographer',
				'source'  => 'wordpress',
				'license' => 'cc0',
				'width'   => 1600,
				'height'  => 900,
			),
		);
		Media::setStockGateway( $this->fakeStockGateway( search_result: $raw ) );

		$result = $this->ability( 'senroflux/stock-image-search' )->execute( array( 'query' => 'a red bicycle' ) );

		$this->assertIsArray( $result );
		$this->assertCount( 1, $result['results'] );
		$row = $result['results'][0];
		$this->assertSame( self::VALID_STOCK_ID, $row['id'] );
		$this->assertSame( 'A red bicycle', $row['title'] );
		$this->assertSame( 'Jane Photographer', $row['creator'] );
		$this->assertSame( 'wordpress', $row['source'] ); // phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- Openverse's own lowercase source slug, not prose.
		$this->assertSame( 'cc0', $row['license'] );
		$this->assertSame( 1600, $row['width'] );
		$this->assertSame( 900, $row['height'] );
		// At most 6 tags, accepting either {name} or a plain string.
		$this->assertCount( 6, $row['tags'] );
		$this->assertSame( array( 'bicycle', 'red', 'outdoor', 'x', 'y', 'z' ), $row['tags'] );
	}

	/**
	 * 0.3 quality fix (stock image choice): live runs picked 3D renders,
	 * illustrations and engravings instead of real photos. The model only
	 * ever sees what `stock-image-search` returns, so the fix filters the
	 * PROVIDER's results, not the model's choice among them.
	 */
	private function nonPhotoResultsRaw(): array {
		return array(
			array(
				'id'    => 'aaaaaaaa-0000-0000-0000-000000000001',
				'title' => 'A red bicycle',
				'tags'  => array( 'bicycle' ),
			),
			array(
				'id'    => 'aaaaaaaa-0000-0000-0000-000000000002',
				'title' => 'Illustration of a red bicycle',
				'tags'  => array( 'bicycle' ),
			),
			array(
				'id'    => 'aaaaaaaa-0000-0000-0000-000000000003',
				'title' => 'A red bicycle',
				'tags'  => array( '3D render' ),
			),
			array(
				'id'    => 'aaaaaaaa-0000-0000-0000-000000000004',
				'title' => 'Engraving of a bicycle',
				'tags'  => array( 'bicycle' ),
			),
		);
	}

	public function test_stock_image_search_drops_non_photo_results(): void {
		Media::setStockGateway( $this->fakeStockGateway( search_result: $this->nonPhotoResultsRaw() ) );

		$result = $this->ability( 'senroflux/stock-image-search' )->execute( array( 'query' => 'a red bicycle' ) );

		$this->assertIsArray( $result );
		$ids = array_column( $result['results'], 'id' );
		$this->assertSame( array( 'aaaaaaaa-0000-0000-0000-000000000001' ), $ids, 'the illustration, 3D render and engraving must all be dropped, leaving only the real photo' );
	}

	/**
	 * Live batches 2026-09-29-final..final5: every untitled result a run
	 * imported was a 3D render, a tag-stuffed interior ("clinic reception",
	 * "fireplace", "living room") or a building exterior; their tags never
	 * said "3d". The titled ones were the real photos.
	 */
	public function test_stock_image_search_drops_untitled_square_renders_and_statues(): void {
		Media::setStockGateway(
			$this->fakeStockGateway(
				search_result: array(
					array(
						'id'     => 'aaaaaaaa-0000-0000-0000-000000000001',
						'title'  => 'Physiotherapy hospital',
						'tags'   => array( 'medical', 'physiotherapy' ),
						'width'  => 5472,
						'height' => 3648,
					),
					array(
						'id'     => 'aaaaaaaa-0000-0000-0000-000000000002',
						'title'  => '',
						'tags'   => array( 'massage', 'nurse model' ),
						'width'  => 8000,
						'height' => 8000,
					),
					array(
						'id'     => 'aaaaaaaa-0000-0000-0000-000000000004',
						'title'  => '',
						'tags'   => array( 'clinic reception', 'fireplace', 'living room' ),
						'width'  => 6000,
						'height' => 4000,
					),
					array(
						'id'     => 'aaaaaaaa-0000-0000-0000-000000000003',
						'title'  => 'Bronze statue of a wrestler',
						'tags'   => array( 'sculpture' ),
						'width'  => 2313,
						'height' => 3102,
					),
				)
			)
		);

		$result = $this->ability( 'senroflux/stock-image-search' )->execute( array( 'query' => 'physiotherapy' ) );

		$this->assertIsArray( $result );
		$this->assertSame( array( 'aaaaaaaa-0000-0000-0000-000000000001' ), array_column( $result['results'], 'id' ) );
	}

	/**
	 * Live batch 2026-09-29-seed2: 1918 "Walter Reed Physiotherapy story"
	 * photos landed on a clinic's About and Services pages. Openverse tags
	 * them "vintage"/"retro"/"history", sorted alphabetically, so the tag
	 * often sits past the six the result keeps.
	 */
	public function test_stock_image_search_drops_vintage_photos_even_when_the_tag_is_seventh(): void {
		Media::setStockGateway(
			$this->fakeStockGateway(
				search_result: array(
					array(
						'id'    => 'aaaaaaaa-0000-0000-0000-000000000001',
						'title' => 'Red Cross nurse giving physiotherapy',
						'tags'  => array( 'bed', 'cross', 'hospital', 'medical', 'people', 'red', 'vintage' ),
					),
					array(
						'id'    => 'aaaaaaaa-0000-0000-0000-000000000002',
						'title' => 'Walter Reed Physiotherapy story',
						'tags'  => array( 'chairs', 'interior', 'retro' ),
					),
					array(
						'id'    => 'aaaaaaaa-0000-0000-0000-000000000003',
						'title' => 'Physiotherapy hospital',
						'tags'  => array( 'background', 'house', 'medical', 'nurse', 'person', 'physiotherapy' ),
					),
				)
			)
		);

		$result = $this->ability( 'senroflux/stock-image-search' )->execute( array( 'query' => 'physiotherapy' ) );

		$this->assertIsArray( $result );
		$this->assertSame( array( 'aaaaaaaa-0000-0000-0000-000000000003' ), array_column( $result['results'], 'id' ) );
		$this->assertCount( 6, $result['results'][0]['tags'] );
	}

	/**
	 * Live batch 2026-09-29-cards2: two heroes carried a tiled "rawpixel"
	 * watermark. Both files came from `images.rawpixel.com/image_*`; the clean
	 * rawpixel photos all came from `/editor_*`.
	 */
	public function test_stock_image_search_drops_watermarked_rawpixel_previews(): void {
		Media::setStockGateway(
			$this->fakeStockGateway(
				search_result: array(
					array(
						'id'     => 'aaaaaaaa-0000-0000-0000-000000000001',
						'title'  => 'A doctor providing consult for a patient',
						'source' => 'rawpixel',
						'url'    => 'https://images.rawpixel.com/image_1300/abc.jpg',
					),
					array(
						'id'     => 'aaaaaaaa-0000-0000-0000-000000000002',
						'title'  => 'Hands doing massage',
						'source' => 'rawpixel',
						'url'    => 'https://images.rawpixel.com/editor_1024/def.jpg',
					),
				)
			)
		);

		$result = $this->ability( 'senroflux/stock-image-search' )->execute( array( 'query' => 'massage' ) );

		$this->assertIsArray( $result );
		$this->assertSame( array( 'aaaaaaaa-0000-0000-0000-000000000002' ), array_column( $result['results'], 'id' ) );
	}

	/**
	 * Room, desk, corridor and building queries returned the off-topic heroes
	 * in live batches 2026-09-29-final..final5 (a lounge with a fireplace, a
	 * hospital exterior); people and treatment queries returned real photos.
	 */
	public function test_stock_image_search_description_steers_queries_to_people_not_rooms(): void {
		$description = $this->ability( 'senroflux/stock-image-search' )->get_description();

		$this->assertStringContainsString( 'people', $description );
		$this->assertStringContainsString( 'not rooms', $description );
	}

	/**
	 * The description used to steer queries with "massage therapy" and
	 * "pumpkin soup" — which happen to be the two test personas (a physio
	 * clinic and a café) — so the product looked tuned to its own test.
	 * The advice must not name any specific industry.
	 */
	public function test_stock_image_search_description_names_no_specific_industry(): void {
		$description = $this->ability( 'senroflux/stock-image-search' )->get_description();

		foreach ( array( 'physio', 'massage', 'café', 'cafe', 'soup', 'pumpkin' ) as $word ) {
			$this->assertStringNotContainsStringIgnoringCase( $word, $description );
		}
	}

	public function test_stock_image_search_ranks_an_already_imported_result_last(): void {
		$this->seedAttachment( 501 );
		$GLOBALS['senroflux_test_postmeta'][501]['_senroflux_stock_source_id'] = 'aaaaaaaa-0000-0000-0000-000000000001';

		Media::setStockGateway(
			$this->fakeStockGateway(
				search_result: array(
					array(
						'id'    => 'aaaaaaaa-0000-0000-0000-000000000001',
						'title' => 'A red bicycle, already on the site',
						'tags'  => array( 'bicycle' ),
					),
					array(
						'id'    => 'aaaaaaaa-0000-0000-0000-000000000002',
						'title' => 'A different red bicycle',
						'tags'  => array( 'bicycle' ),
					),
				)
			)
		);

		$result = $this->ability( 'senroflux/stock-image-search' )->execute( array( 'query' => 'a red bicycle' ) );

		$this->assertIsArray( $result );
		$ids = array_column( $result['results'], 'id' );
		$this->assertSame(
			array( 'aaaaaaaa-0000-0000-0000-000000000002', 'aaaaaaaa-0000-0000-0000-000000000001' ),
			$ids,
			'the already-used image drops to the end without being removed'
		);
		$this->assertFalse( $result['results'][0]['already_used'] );
		$this->assertTrue( $result['results'][1]['already_used'] );
	}

	public function test_stock_image_search_refuses_when_disabled_by_filter(): void {
		add_filter( 'senroflux_stock_images_enabled', static fn () => false );

		$result = $this->ability( 'senroflux/stock-image-search' )->execute( array( 'query' => 'x' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'stock_images_disabled', $result->get_error_code() );
		$this->assertStringContainsString( 'no_image_reason', $result->get_error_message() );

		remove_all_filters( 'senroflux_stock_images_enabled' );
	}

	public function test_stock_image_search_refuses_on_a_gateway_error(): void {
		Media::setStockGateway( $this->fakeStockGateway( search_result: new WP_Error( 'http_request_failed', 'timed out' ) ) );

		$result = $this->ability( 'senroflux/stock-image-search' )->execute( array( 'query' => 'x' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'stock_images_unavailable', $result->get_error_code() );
		$this->assertStringContainsString( 'no_image_reason', $result->get_error_message() );
	}

	public function test_stock_image_import_refuses_a_malformed_id(): void {
		$gateway = $this->fakeStockGateway();
		Media::setStockGateway( $gateway );

		$result = $this->ability( 'senroflux/stock-image-import' )->execute(
			array(
				'id'  => 'not-a-uuid',
				'alt' => 'A red bicycle',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_input', $result->get_error_code() );
		$this->assertSame( array(), $gateway->fetchCalls, 'a malformed id must never reach the gateway' );
	}

	public function test_stock_image_import_requires_non_empty_alt(): void {
		$result = $this->ability( 'senroflux/stock-image-import' )->execute(
			array(
				'id'  => self::VALID_STOCK_ID,
				'alt' => '',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_input', $result->get_error_code() );
	}

	public function test_stock_image_import_refuses_when_disabled_by_filter(): void {
		add_filter( 'senroflux_stock_images_enabled', static fn () => false );

		$result = $this->ability( 'senroflux/stock-image-import' )->execute(
			array(
				'id'  => self::VALID_STOCK_ID,
				'alt' => 'A red bicycle',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'stock_images_disabled', $result->get_error_code() );

		remove_all_filters( 'senroflux_stock_images_enabled' );
	}

	/** @return array<string,array{0:array<string,mixed>}> */
	public static function ineligibleDetailProvider(): array {
		return array(
			'non-cc0 license'   => array( array( 'license' => 'by' ) ),
			'disallowed source' => array( array( 'source' => 'flickr' ) ),
			'mature'            => array( array( 'mature' => true ) ),
			'non-https url'     => array( array( 'url' => 'http://stock.example/photo.jpg' ) ),
		);
	}

	/**
	 * @dataProvider ineligibleDetailProvider
	 * @param array<string,mixed> $override
	 */
	public function test_stock_image_import_refuses_an_ineligible_detail( array $override ): void {
		$detail  = $this->eligibleDetail( $override );
		$gateway = $this->fakeStockGateway( fetch_result: $detail );
		Media::setStockGateway( $gateway );

		$result = $this->ability( 'senroflux/stock-image-import' )->execute(
			array(
				'id'  => self::VALID_STOCK_ID,
				'alt' => 'A red bicycle',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'stock_image_ineligible', $result->get_error_code() );
		$this->assertSame( array(), $gateway->downloadCalls, 'an ineligible detail must never be downloaded' );
	}

	/**
	 * SECURITY: the model supplies only an id; the gateway's `fetch()` (a
	 * server-side re-fetch by id) is the ONLY source of the url/license/
	 * source fields actually used — a model-supplied url is not even part of
	 * the input schema, so this asserts the real behaviour: download() is
	 * always called with the FETCHED detail, never anything the input carried.
	 */
	/**
	 * Live batch 2026-09-29-final scenario 1-1: after the hero-reuse refusal
	 * the model imported the same Openverse photo again, got a new
	 * attachment and url, and the About page shipped Home's hero.
	 */
	public function test_stock_image_import_of_an_already_imported_photo_returns_the_existing_attachment(): void {
		$this->seedAttachment( 501 );
		$GLOBALS['senroflux_test_postmeta'][501]['_senroflux_stock_source_id'] = self::VALID_STOCK_ID;
		$gateway = $this->fakeStockGateway( fetch_result: $this->eligibleDetail() );
		Media::setStockGateway( $gateway );

		$result = $this->ability( 'senroflux/stock-image-import' )->execute(
			array(
				'id'  => self::VALID_STOCK_ID,
				'alt' => 'A red bicycle',
			)
		);

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( 501, $result['attachment_id'] );
		$this->assertSame( array(), $gateway->downloadCalls, 'nothing is downloaded twice' );
	}

	public function test_stock_image_import_downloads_the_refetched_detail_never_a_model_url(): void {
		$detail = $this->eligibleDetail();
		$path   = sys_get_temp_dir() . '/senroflux-stock-import.jpg';
		touch( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch -- test fixture file, not a WP runtime path.
		$gateway = $this->fakeStockGateway(
			fetch_result: $detail,
			download_result: array(
				'path'     => $path,
				'filename' => basename( $path ),
			)
		);
		Media::setStockGateway( $gateway );

		$result = $this->ability( 'senroflux/stock-image-import' )->execute(
			array(
				'id'  => self::VALID_STOCK_ID,
				'alt' => 'A red bicycle',
			)
		);

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertArrayHasKey( 'attachment_id', $result );
		$this->assertSame( array( $detail ), $gateway->downloadCalls );

		$id = $result['attachment_id'];
		$this->assertSame( 'A red bicycle', $GLOBALS['senroflux_test_postmeta'][ $id ]['_wp_attachment_image_alt'] ?? null );
		$this->assertNotEmpty( $GLOBALS['senroflux_test_posts'][ $id ]->post_excerpt ?? '' );
		$stored = $GLOBALS['senroflux_test_postmeta'][ $id ]['_senroflux_stock_source'] ?? null;
		$this->assertIsArray( $stored );
		$this->assertSame( 'openverse', $stored['provider'] );
		$this->assertSame( self::VALID_STOCK_ID, $stored['id'] );
		// 0.3 quality fix (stock image choice): a plain-string companion key,
		// simple enough for `stock-image-search` to filter used images by a
		// direct `get_posts()` meta lookup without decoding the full record.
		$this->assertSame( self::VALID_STOCK_ID, $GLOBALS['senroflux_test_postmeta'][ $id ]['_senroflux_stock_source_id'] ?? null );
	}

	/**
	 * A real failable check (not a tautology): seed SIX prior successful
	 * `stock-image-import` steps directly in the run's step history — past
	 * the shipped `images` budget of 6 — then confirm `generate-image` still
	 * succeeds. If `spentImages()` ever miscounted `stock-image-import`
	 * alongside `generate-image`, this would refuse `budget_exhausted`.
	 */
	public function test_stock_image_import_does_not_spend_the_images_budget(): void {
		for ( $i = 0; $i < 6; $i++ ) {
			$this->store->appendStep( $this->runId, StepKind::ToolResult, null, 'senroflux/stock-image-import', null, 'ok' );
		}

		$path = sys_get_temp_dir() . '/senroflux-generate-after-stock.jpg';
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

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
	}

	public function test_the_fourth_stock_image_import_in_one_run_is_refused(): void {
		for ( $i = 0; $i < Media::STOCK_IMPORT_CAP; $i++ ) {
			$this->store->appendStep( $this->runId, StepKind::ToolResult, null, 'senroflux/stock-image-import', null, 'ok' );
		}

		$detail = $this->eligibleDetail();
		Media::setStockGateway( $this->fakeStockGateway( fetch_result: $detail ) );

		$result = $this->ability( 'senroflux/stock-image-import' )->execute(
			array(
				'id'  => self::VALID_STOCK_ID,
				'alt' => 'A red bicycle',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'stock_import_cap', $result->get_error_code() );
	}

	// ------------------------------------------------------------------
	// noImageSourceLeft() — the pageImageCheck() escape hatch
	// ------------------------------------------------------------------

	private function exhaustImagesBudget(): void {
		$limit = Budget::defaults()[ Budget::IMAGES ];
		for ( $i = 0; $i < $limit; $i++ ) {
			$this->store->appendStep( $this->runId, StepKind::ToolResult, null, 'senroflux/generate-image', null, 'ok' );
		}
	}

	public function test_no_image_source_left_is_false_outside_a_run_context(): void {
		Media::forgetRunContext();

		$this->assertFalse( Media::noImageSourceLeft() );
	}

	public function test_no_image_source_left_is_false_when_the_images_budget_is_not_exhausted(): void {
		$this->assertFalse( Media::noImageSourceLeft() );
	}

	public function test_no_image_source_left_is_true_when_budget_exhausted_and_stock_disabled(): void {
		$this->exhaustImagesBudget();
		add_filter( 'senroflux_stock_images_enabled', static fn () => false );

		$this->assertTrue( Media::noImageSourceLeft() );

		remove_all_filters( 'senroflux_stock_images_enabled' );
	}

	public function test_no_image_source_left_is_false_when_budget_exhausted_but_stock_not_yet_tried(): void {
		$this->exhaustImagesBudget();

		$this->assertFalse( Media::noImageSourceLeft() );
	}

	public function test_no_image_source_left_is_true_when_a_stock_search_errored(): void {
		$this->exhaustImagesBudget();
		$this->store->appendStep( $this->runId, StepKind::ToolResult, null, 'senroflux/stock-image-search', null, 'error' );

		$this->assertTrue( Media::noImageSourceLeft() );
	}

	public function test_no_image_source_left_is_true_when_a_stock_search_returned_zero_results(): void {
		$this->exhaustImagesBudget();

		$message = array(
			'role'  => 'user',
			'parts' => array(
				array(
					'functionResponse' => array(
						'id'       => 'call-1',
						'name'     => 'senroflux/stock-image-search',
						'response' => array( 'results' => array() ),
					),
				),
			),
		);
		$this->store->appendStep( $this->runId, StepKind::ToolResult, $message, 'senroflux/stock-image-search', null, 'ok' );

		$this->assertTrue( Media::noImageSourceLeft() );
	}

	public function test_no_image_source_left_is_false_when_a_stock_search_returned_results(): void {
		$this->exhaustImagesBudget();

		$message = array(
			'role'  => 'user',
			'parts' => array(
				array(
					'functionResponse' => array(
						'id'       => 'call-1',
						'name'     => 'senroflux/stock-image-search',
						'response' => array( 'results' => array( array( 'id' => 'x' ) ) ),
					),
				),
			),
		);
		$this->store->appendStep( $this->runId, StepKind::ToolResult, $message, 'senroflux/stock-image-search', null, 'ok' );

		$this->assertFalse( Media::noImageSourceLeft() );
	}
}
