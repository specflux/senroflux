<?php
/**
 * Commerce\Abilities tests (S19, stage 12): `set-product-image`,
 * `coupon-create`, `coupon-enable`.
 *
 * TARGET REPO PATH: tests/Packs/Commerce/AbilitiesTest.php
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Packs\Commerce;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Packs\Commerce\Abilities;
use Specflux\SenroFlux\Run\Budget;
use Specflux\SenroFlux\Run\WpdbRunStore;
use WP_Error;
use wpdb;

final class AbilitiesTest extends TestCase {

	private WpdbRunStore $store;

	private int $runId;

	private function loadShims(): void {
		require_once dirname( __DIR__, 2 ) . '/stubs/blocks.php';
		require_once dirname( __DIR__, 2 ) . '/stubs/media.php';
		require_once dirname( __DIR__, 2 ) . '/stubs/commerce.php';
	}

	protected function setUp(): void {
		$this->loadShims();

		$GLOBALS['senroflux_test_abilities']          = array();
		$GLOBALS['senroflux_test_posts']              = array();
		$GLOBALS['senroflux_test_postmeta']           = array();
		$GLOBALS['senroflux_test_coupon_codes']       = array();
		$GLOBALS['senroflux_test_next_post_id']       = 100;
		$GLOBALS['senroflux_test_ability_categories'] = array();

		Abilities::reset();
		Abilities::registerCategory();
		Abilities::register();

		$this->store = new WpdbRunStore( new wpdb() );
		$this->runId = $this->store->createRun( 1, 'test', 'goal', array(), Budget::defaults() );
		Abilities::useRunContext( $this->runId, $this->store );
	}

	protected function tearDown(): void {
		Abilities::forgetRunContext();
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	private function makePost( string $type, array $overrides = array() ): int {
		$id                                      = (int) $GLOBALS['senroflux_test_next_post_id'];
		$GLOBALS['senroflux_test_next_post_id'] += 1;

		$post                    = new \stdClass();
		$post->ID                = $id;
		$post->post_type         = $type;
		$post->post_status       = $overrides['post_status'] ?? 'publish';
		$post->post_title        = $overrides['post_title'] ?? '';
		$post->post_mime_type    = $overrides['post_mime_type'] ?? '';
		$post->post_modified_gmt = $overrides['post_modified_gmt'] ?? '2026-01-01 00:00:00';
		$post->post_excerpt      = '';
		$post->post_parent       = 0;
		$post->post_name         = '';

		$GLOBALS['senroflux_test_posts'][ $id ] = $post;

		return $id;
	}

	private function callAbility( string $name, array $input ): mixed {
		$ability = wp_get_ability( $name );
		$this->assertNotNull( $ability, "ability $name was not registered" );

		return $ability->execute( $input );
	}

	// ------------------------------------------------------------------
	// set-product-image
	// ------------------------------------------------------------------

	public function test_set_product_image_sets_the_featured_image(): void {
		$product    = $this->makePost( 'product' );
		$attachment = $this->makePost( 'attachment', array( 'post_mime_type' => 'image/jpeg' ) );
		update_post_meta( $attachment, '_wp_attachment_image_alt', 'A widget' );

		$result = $this->callAbility(
			'senroflux/set-product-image',
			array(
				'product_id'    => $product,
				'attachment_id' => $attachment,
			)
		);

		$this->assertSame(
			array(
				'product_id'    => $product,
				'attachment_id' => $attachment,
			),
			$result
		);
		$this->assertSame( $attachment, $GLOBALS['senroflux_test_postmeta'][ $product ]['_thumbnail_id'] ?? null );
	}

	public function test_set_product_image_appends_to_the_gallery_when_asked(): void {
		$product    = $this->makePost( 'product' );
		$attachment = $this->makePost( 'attachment', array( 'post_mime_type' => 'image/png' ) );
		update_post_meta( $attachment, '_wp_attachment_image_alt', 'A widget' );

		$this->callAbility(
			'senroflux/set-product-image',
			array(
				'product_id'    => $product,
				'attachment_id' => $attachment,
				'gallery'       => true,
			)
		);

		$this->assertSame(
			(string) $attachment,
			$GLOBALS['senroflux_test_postmeta'][ $product ]['_product_image_gallery'] ?? null
		);
	}

	public function test_set_product_image_refuses_a_non_image_attachment(): void {
		$product    = $this->makePost( 'product' );
		$attachment = $this->makePost( 'attachment', array( 'post_mime_type' => 'application/pdf' ) );
		update_post_meta( $attachment, '_wp_attachment_image_alt', 'A pdf' );

		$result = $this->callAbility(
			'senroflux/set-product-image',
			array(
				'product_id'    => $product,
				'attachment_id' => $attachment,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'not_an_image', $result->get_error_code() );
	}

	public function test_set_product_image_refuses_an_attachment_with_no_alt_text(): void {
		$product    = $this->makePost( 'product' );
		$attachment = $this->makePost( 'attachment', array( 'post_mime_type' => 'image/jpeg' ) );

		$result = $this->callAbility(
			'senroflux/set-product-image',
			array(
				'product_id'    => $product,
				'attachment_id' => $attachment,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'missing_alt', $result->get_error_code() );
	}

	public function test_set_product_image_refuses_when_product_is_missing(): void {
		$attachment = $this->makePost( 'attachment', array( 'post_mime_type' => 'image/jpeg' ) );
		update_post_meta( $attachment, '_wp_attachment_image_alt', 'A widget' );

		$result = $this->callAbility(
			'senroflux/set-product-image',
			array(
				'product_id'    => 999999,
				'attachment_id' => $attachment,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'not_found', $result->get_error_code() );
	}

	// ------------------------------------------------------------------
	// coupon-create — slug collision (S4 applied to coupons)
	// ------------------------------------------------------------------

	public function test_coupon_create_creates_a_draft_coupon(): void {
		$result = $this->callAbility(
			'senroflux/coupon-create',
			array(
				'code'          => 'SAVE10',
				'discount_type' => 'percent',
				'amount'        => 10,
			)
		);

		$this->assertIsArray( $result );
		$id = $result['coupon_id'];
		$this->assertSame( 'shop_coupon', $GLOBALS['senroflux_test_posts'][ $id ]->post_type );
		$this->assertSame( 'draft', $GLOBALS['senroflux_test_posts'][ $id ]->post_status );
	}

	public function test_coupon_create_refuses_an_existing_code(): void {
		$this->callAbility(
			'senroflux/coupon-create',
			array(
				'code'          => 'SAVE10',
				'discount_type' => 'percent',
				'amount'        => 10,
			)
		);

		$result = $this->callAbility(
			'senroflux/coupon-create',
			array(
				'code'          => 'SAVE10',
				'discount_type' => 'fixed_cart',
				'amount'        => 5,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'slug_collision', $result->get_error_code() );
	}

	public function test_coupon_create_refuses_an_empty_code(): void {
		$result = $this->callAbility(
			'senroflux/coupon-create',
			array(
				'code'          => '',
				'discount_type' => 'percent',
				'amount'        => 10,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_input', $result->get_error_code() );
	}

	// ------------------------------------------------------------------
	// coupon-enable — S8 stale-write coverage (real, sourced from coupon-create)
	// ------------------------------------------------------------------

	public function test_coupon_enable_publishes_a_freshly_created_coupon(): void {
		$created = $this->callAbility(
			'senroflux/coupon-create',
			array(
				'code'          => 'SAVE10',
				'discount_type' => 'percent',
				'amount'        => 10,
			)
		);
		$id      = $created['coupon_id'];

		$result = $this->callAbility( 'senroflux/coupon-enable', array( 'coupon_id' => $id ) );

		$this->assertSame( array( 'coupon_id' => $id ), $result );
		$this->assertSame( 'publish', $GLOBALS['senroflux_test_posts'][ $id ]->post_status );
	}

	public function test_coupon_enable_refuses_a_stale_write(): void {
		$created = $this->callAbility(
			'senroflux/coupon-create',
			array(
				'code'          => 'SAVE10',
				'discount_type' => 'percent',
				'amount'        => 10,
			)
		);
		$id      = $created['coupon_id'];

		// Simulate an external edit this run never saw (S8): bump the marker
		// directly, bypassing this run's own tracker.
		$GLOBALS['senroflux_test_posts'][ $id ]->post_modified_gmt = '2099-01-01 00:00:00';

		$result = $this->callAbility( 'senroflux/coupon-enable', array( 'coupon_id' => $id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'stale_write', $result->get_error_code() );
	}

	public function test_coupon_enable_refuses_an_unread_coupon_fail_closed(): void {
		// A coupon this run never created/read at all (no tracker entry): S8
		// fails closed, same as content abilities.
		$id = $this->makePost(
			'shop_coupon',
			array(
				'post_status' => 'draft',
				'post_title'  => 'EXTERNAL',
			)
		);
		$GLOBALS['senroflux_test_coupon_codes']['external'] = $id;

		$result = $this->callAbility( 'senroflux/coupon-enable', array( 'coupon_id' => $id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'stale_write', $result->get_error_code() );
	}

	public function test_coupon_enable_refuses_a_missing_coupon(): void {
		$result = $this->callAbility( 'senroflux/coupon-enable', array( 'coupon_id' => 999999 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'not_found', $result->get_error_code() );
	}
}
