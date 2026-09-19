<?php
/**
 * Test-only stand-ins for the WooCommerce surface the commerce-pack polyfills
 * call: stage 12 (`WC_Coupon`, `wc_get_coupon_id_by_code()`,
 * `get_post_mime_type()`) and stage 13 (orders/refunds — `WC_Order`,
 * `wc_get_order()`, `wc_create_refund()`, `wc_get_payment_gateway_by_order()`;
 * shipping — `WC_Shipping_Zone`; tax — `WC_Tax`; the store report —
 * `wc_get_orders()`, `wc_get_is_paid_statuses()`, `get_woocommerce_currency()`,
 * `wc_get_low_stock_amount()`, `wc_get_products()`, `WC_Product`).
 *
 * `$GLOBALS['senroflux_test_orders']` is a plain array of order-like stdClass
 * rows a test seeds directly (id, total, total_refunded, payment_method,
 * status, date_created as a Unix timestamp) — a SEPARATE store from
 * `senroflux_test_posts` because a WooCommerce order is a `shop_order`
 * custom-table row in real WooCommerce (HPOS), never a post this stub's
 * shared post store already models.
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

// ------------------------------------------------------------------
// Stage 13: orders / refunds
// ------------------------------------------------------------------

if ( ! isset( $GLOBALS['senroflux_test_orders'] ) ) {
	$GLOBALS['senroflux_test_orders'] = array();
}
if ( ! isset( $GLOBALS['senroflux_test_gateway_supports_refunds'] ) ) {
	$GLOBALS['senroflux_test_gateway_supports_refunds'] = true;
}

if ( ! class_exists( 'WC_Order', false ) ) {
	/**
	 * Minimal stand-in over one row of `$GLOBALS['senroflux_test_orders']`.
	 */
	class WC_Order {

		public function __construct( private int $id ) {}

		public function get_id(): int {
			return $this->id;
		}

		private function row(): ?object {
			$row = $GLOBALS['senroflux_test_orders'][ $this->id ] ?? null;

			return is_object( $row ) ? $row : null;
		}

		public function get_total(): float {
			return (float) ( $this->row()->total ?? 0.0 );
		}

		public function get_total_refunded(): float {
			return (float) ( $this->row()->total_refunded ?? 0.0 );
		}

		public function get_payment_method(): string {
			return (string) ( $this->row()->payment_method ?? '' );
		}

		public function get_status(): string {
			return (string) ( $this->row()->status ?? '' );
		}

		public function get_date_created(): ?int {
			$row = $this->row();

			return ( null !== $row && isset( $row->date_created ) ) ? (int) $row->date_created : null;
		}
	}
}

if ( ! function_exists( 'wc_get_order' ) ) {
	function wc_get_order( int $id ): WC_Order|false {
		return isset( $GLOBALS['senroflux_test_orders'][ $id ] ) ? new WC_Order( $id ) : false;
	}
}

if ( ! function_exists( 'wc_create_refund' ) ) {
	/**
	 * @param array<string,mixed> $args
	 */
	function wc_create_refund( array $args ): object {
		$order_id = (int) ( $args['order_id'] ?? 0 );
		$row      = $GLOBALS['senroflux_test_orders'][ $order_id ] ?? null;
		if ( ! is_object( $row ) ) {
			return new WP_Error( 'not_found', 'Order not found.' );
		}

		$row->total_refunded = (float) ( $row->total_refunded ?? 0.0 ) + (float) ( $args['amount'] ?? 0.0 );

		return (object) array(
			'id'     => $order_id,
			'amount' => (float) ( $args['amount'] ?? 0.0 ),
		);
	}
}

if ( ! class_exists( 'SenroFlux_Test_Payment_Gateway', false ) ) {
	/**
	 * A REAL class with a REAL `supports()` method — `method_exists()` (the
	 * production code's check, matching a genuine `WC_Payment_Gateway`) only
	 * ever returns true for an actual declared method, never a closure
	 * assigned to a `stdClass` property, so the stub must be a real object.
	 */
	class SenroFlux_Test_Payment_Gateway {
		public function supports( string $feature ): bool {
			return 'refunds' === $feature && (bool) $GLOBALS['senroflux_test_gateway_supports_refunds'];
		}
	}
}

if ( ! function_exists( 'wc_get_payment_gateway_by_order' ) ) {
	function wc_get_payment_gateway_by_order( WC_Order|int $order ): object|false {
		return new SenroFlux_Test_Payment_Gateway();
	}
}

// ------------------------------------------------------------------
// Stage 13: shipping zones
// ------------------------------------------------------------------

if ( ! isset( $GLOBALS['senroflux_test_shipping_zones'] ) ) {
	$GLOBALS['senroflux_test_shipping_zones'] = array();
}
if ( ! isset( $GLOBALS['senroflux_test_next_zone_id'] ) ) {
	$GLOBALS['senroflux_test_next_zone_id'] = 1;
}

if ( ! class_exists( 'WC_Shipping_Zone', false ) ) {
	class WC_Shipping_Zone {

		private int $id      = 0;
		private string $name = '';
		/** @var list<array{code:string,type:string}> */
		private array $locations = array();
		/** @var list<string> */
		private array $methods = array();

		public function __construct( int $id = 0 ) {
			$existing = $GLOBALS['senroflux_test_shipping_zones'][ $id ] ?? null;
			if ( $id > 0 && is_array( $existing ) ) {
				$this->id        = $id;
				$this->name      = $existing['name'];
				$this->locations = $existing['locations'];
				$this->methods   = $existing['methods'];
			}
		}

		public function get_id(): int {
			return $this->id;
		}

		public function get_zone_name(): string {
			return $this->name;
		}

		/** @return list<array{code:string,type:string}> */
		public function get_zone_locations(): array {
			return $this->locations;
		}

		/** @return list<string> */
		public function get_shipping_methods(): array {
			return $this->methods;
		}

		public function set_zone_name( string $name ): void {
			$this->name = $name;
		}

		public function add_location( string $code, string $type ): void {
			$this->locations[] = array(
				'code' => $code,
				'type' => $type,
			);
		}

		public function add_shipping_method( string $method_id ): void {
			$this->methods[] = $method_id;
		}

		public function save(): int {
			if ( 0 === $this->id ) {
				$this->id                                = (int) $GLOBALS['senroflux_test_next_zone_id'];
				$GLOBALS['senroflux_test_next_zone_id'] += 1;
			}

			$GLOBALS['senroflux_test_shipping_zones'][ $this->id ] = array(
				'name'      => $this->name,
				'locations' => $this->locations,
				'methods'   => $this->methods,
			);

			return $this->id;
		}
	}
}

// ------------------------------------------------------------------
// Stage 13: tax rates
// ------------------------------------------------------------------

if ( ! isset( $GLOBALS['senroflux_test_tax_rates'] ) ) {
	$GLOBALS['senroflux_test_tax_rates'] = array();
}
if ( ! isset( $GLOBALS['senroflux_test_next_tax_rate_id'] ) ) {
	$GLOBALS['senroflux_test_next_tax_rate_id'] = 1;
}

if ( ! class_exists( 'WC_Tax', false ) ) {
	class WC_Tax {

		/**
		 * @param array<string,mixed> $data
		 */
		// phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore -- mirrors WooCommerce's real WC_Tax method name.
		public static function _insert_tax_rate( array $data ): int {
			$id = (int) $GLOBALS['senroflux_test_next_tax_rate_id'];
			$GLOBALS['senroflux_test_next_tax_rate_id'] += 1;
			$GLOBALS['senroflux_test_tax_rates'][ $id ]  = $data;

			return $id;
		}

		/**
		 * @param array<string,mixed> $data
		 */
		// phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore -- mirrors WooCommerce's real WC_Tax method name.
		public static function _update_tax_rate( int $id, array $data ): void {
			$GLOBALS['senroflux_test_tax_rates'][ $id ] = $data;
		}

		/**
		 * @return array<string,mixed>
		 */
		// phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore -- mirrors WooCommerce's real WC_Tax method name.
		public static function _get_tax_rate( int $id ): array {
			$row = $GLOBALS['senroflux_test_tax_rates'][ $id ] ?? null;

			return is_array( $row ) ? $row : array();
		}
	}
}

// ------------------------------------------------------------------
// Stage 13: store report
// ------------------------------------------------------------------

if ( ! function_exists( 'wc_get_is_paid_statuses' ) ) {
	function wc_get_is_paid_statuses(): array {
		return array( 'processing', 'completed' );
	}
}

if ( ! function_exists( 'wc_get_orders' ) ) {
	/**
	 * @param array<string,mixed> $args
	 * @return list<WC_Order>
	 */
	function wc_get_orders( array $args ): array {
		$statuses = (array) ( $args['status'] ?? array() );
		$window   = is_string( $args['date_created'] ?? null ) ? explode( '...', $args['date_created'] ) : array();
		$from     = isset( $window[0] ) ? (int) $window[0] : null;
		$to       = isset( $window[1] ) ? (int) $window[1] : null;

		$matches = array();
		foreach ( $GLOBALS['senroflux_test_orders'] as $id => $row ) {
			if ( ! is_object( $row ) ) {
				continue;
			}
			if ( array() !== $statuses && ! in_array( (string) ( $row->status ?? '' ), $statuses, true ) ) {
				continue;
			}
			$created = isset( $row->date_created ) ? (int) $row->date_created : null;
			if ( null !== $from && ( null === $created || $created < $from ) ) {
				continue;
			}
			if ( null !== $to && ( null === $created || $created > $to ) ) {
				continue;
			}
			$matches[] = new WC_Order( (int) $id );
		}

		return $matches;
	}
}

if ( ! function_exists( 'get_woocommerce_currency' ) ) {
	function get_woocommerce_currency(): string {
		return $GLOBALS['senroflux_test_currency'] ?? 'USD';
	}
}

if ( ! function_exists( 'wc_get_low_stock_amount' ) ) {
	function wc_get_low_stock_amount(): int {
		return $GLOBALS['senroflux_test_low_stock_amount'] ?? 2;
	}
}

if ( ! isset( $GLOBALS['senroflux_test_products'] ) ) {
	$GLOBALS['senroflux_test_products'] = array();
}

if ( ! class_exists( 'WC_Product', false ) ) {
	class WC_Product {

		public function __construct( private int $id, private ?int $stock_quantity ) {}

		public function get_id(): int {
			return $this->id;
		}

		public function get_stock_quantity(): ?int {
			return $this->stock_quantity;
		}
	}
}

if ( ! function_exists( 'wc_get_products' ) ) {
	/**
	 * @param array<string,mixed> $args
	 * @return list<WC_Product>
	 */
	function wc_get_products( array $args ): array {
		unset( $args );
		$products = array();
		foreach ( $GLOBALS['senroflux_test_products'] as $id => $stock ) {
			$products[] = new WC_Product( (int) $id, null === $stock ? null : (int) $stock );
		}

		return $products;
	}
}
