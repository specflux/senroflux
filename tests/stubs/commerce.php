<?php
/**
 * Test-only stand-ins for the WooCommerce surface the commerce-pack polyfills
 * call (stage 12): `WC_Coupon`, `wc_get_coupon_id_by_code()`,
 * `get_post_mime_type()`.
 *
 * Deliberately NOT loaded from tests/bootstrap.php: this file never defines a
 * `WooCommerce` marker class, so `class_exists( 'WooCommerce' )` stays false
 * for the WHOLE suite (a real class can never be undefined mid-process) —
 * which is exactly what the "commerce pack is not registered without
 * WooCommerce" test needs to keep meaning something. Tests that need
 * `WC_Coupon` require this file directly.
 *
 * Coupons live in the SAME `senroflux_test_posts` in-memory store
 * tests/stubs/blocks.php uses for pages/posts, as `post_type: 'shop_coupon'`
 * — the same object identity {@see \Specflux\SenroFlux\Run\Tracker} tracks.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

if ( ! isset( $GLOBALS['senroflux_test_posts'] ) ) {
	$GLOBALS['senroflux_test_posts'] = array();
}
if ( ! isset( $GLOBALS['senroflux_test_coupon_codes'] ) ) {
	$GLOBALS['senroflux_test_coupon_codes'] = array();
}
if ( ! isset( $GLOBALS['senroflux_test_next_post_id'] ) ) {
	$GLOBALS['senroflux_test_next_post_id'] = 1000;
}

if ( ! class_exists( 'WC_Coupon', false ) ) {
	/**
	 * Minimal stand-in: enough of WC_Coupon's setter/save surface for
	 * {@see \Specflux\SenroFlux\Packs\Commerce\Abilities} to exercise for
	 * real, backed by the shared in-memory post store.
	 */
	class WC_Coupon {

		private int $id               = 0;
		private string $code          = '';
		private string $discount_type = 'fixed_cart';
		private string $amount        = '0';
		private ?string $expires      = null;
		private ?int $usage_limit     = null;
		/** @var list<int> */
		private array $product_ids = array();
		private string $status     = 'draft';

		public function __construct( int $id = 0 ) {
			$this->id = $id;
			$existing = $GLOBALS['senroflux_test_posts'][ $id ] ?? null;
			if ( $id > 0 && is_object( $existing ) ) {
				$this->code   = (string) ( $existing->post_title ?? '' );
				$this->status = (string) ( $existing->post_status ?? 'draft' );
			}
		}

		public function get_id(): int {
			return $this->id;
		}

		public function set_code( string $code ): void {
			$this->code = $code;
		}

		public function set_discount_type( string $type ): void {
			$this->discount_type = $type;
		}

		public function set_amount( string $amount ): void {
			$this->amount = $amount;
		}

		public function set_date_expires( string $date ): void {
			$this->expires = $date;
		}

		public function set_usage_limit( int $limit ): void {
			$this->usage_limit = $limit;
		}

		/** @param list<int> $ids */
		public function set_product_ids( array $ids ): void {
			$this->product_ids = $ids;
		}

		public function set_status( string $status ): void {
			$this->status = $status;
		}

		public function save(): int {
			if ( 0 === $this->id ) {
				$this->id                                = (int) $GLOBALS['senroflux_test_next_post_id'];
				$GLOBALS['senroflux_test_next_post_id'] += 1;
			}

			$post                    = $GLOBALS['senroflux_test_posts'][ $this->id ] ?? new \stdClass();
			$post->ID                = $this->id;
			$post->post_type         = 'shop_coupon';
			$post->post_title        = $this->code;
			$post->post_status       = $this->status;
			$post->post_modified_gmt = $GLOBALS['senroflux_test_clock_gmt'] ?? gmdate( 'Y-m-d H:i:s' );
			$post->post_excerpt      = '';
			$post->post_parent       = 0;
			$post->post_name         = '';

			$GLOBALS['senroflux_test_posts'][ $this->id ]                        = $post;
			$GLOBALS['senroflux_test_coupon_codes'][ strtolower( $this->code ) ] = $this->id;

			unset( $this->discount_type, $this->amount, $this->expires, $this->usage_limit, $this->product_ids );

			return $this->id;
		}
	}
}

if ( ! function_exists( 'wc_get_coupon_id_by_code' ) ) {
	function wc_get_coupon_id_by_code( string $code ): int {
		return (int) ( $GLOBALS['senroflux_test_coupon_codes'][ strtolower( $code ) ] ?? 0 );
	}
}

if ( ! function_exists( 'get_post_mime_type' ) ) {
	function get_post_mime_type( int $id ): string|false {
		$post = $GLOBALS['senroflux_test_posts'][ $id ] ?? null;

		return ( is_object( $post ) && isset( $post->post_mime_type ) ) ? (string) $post->post_mime_type : false;
	}
}
