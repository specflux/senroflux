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

if ( ! isset( $GLOBALS['senroflux_test_coupon_meta'] ) ) {
	$GLOBALS['senroflux_test_coupon_meta'] = array();
}

if ( ! class_exists( 'WC_Coupon', false ) ) {
	/**
	 * Minimal stand-in: enough of WC_Coupon's setter/save surface for
	 * {@see \Specflux\SenroFlux\Packs\Commerce\Abilities} to exercise for
	 * real, backed by the shared in-memory post store.
	 *
	 * Stage 14 (AS-15/S19 approval cards) adds the READ side: the amount,
	 * discount type, expiry and usage limit are persisted to a SEPARATE
	 * `senroflux_test_coupon_meta` store on save() (never onto the post
	 * store the code/status pair already use), and a FRESH `new
	 * WC_Coupon($id)` reloads them for {@see \Specflux\SenroFlux\Packs\Commerce\CommerceSummary}
	 * to read, mirroring real WooCommerce's own coupon meta storage.
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

			$meta = $GLOBALS['senroflux_test_coupon_meta'][ $id ] ?? null;
			if ( $id > 0 && is_object( $meta ) ) {
				$this->discount_type = (string) ( $meta->discount_type ?? $this->discount_type );
				$this->amount        = (string) ( $meta->amount ?? $this->amount );
				$this->expires       = isset( $meta->expires ) ? (string) $meta->expires : null;
				$this->usage_limit   = isset( $meta->usage_limit ) ? (int) $meta->usage_limit : null;
			}
		}

		public function get_id(): int {
			return $this->id;
		}

		public function get_code(): string {
			return $this->code;
		}

		public function get_amount(): string {
			return $this->amount;
		}

		public function get_discount_type(): string {
			return $this->discount_type;
		}

		public function get_date_expires(): ?string {
			return $this->expires;
		}

		// Real `WC_Coupon::get_usage_limit()` returns 0 (not null) when unlimited.
		public function get_usage_limit(): int {
			return $this->usage_limit ?? 0;
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
			$GLOBALS['senroflux_test_coupon_meta'][ $this->id ]                  = (object) array(
				'discount_type' => $this->discount_type,
				'amount'        => $this->amount,
				'expires'       => $this->expires,
				'usage_limit'   => $this->usage_limit,
			);

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

		public function get_order_number(): string {
			return (string) $this->id;
		}

		public function get_edit_order_url(): string {
			return 'https://example.test/wp-admin/admin.php?page=wc-orders&action=edit&id=' . $this->id;
		}

		/** Stage 14 (AS-15/S19): the recipient a customer-visible note reaches. */
		public function get_billing_email(): string {
			return (string) ( $this->row()->billing_email ?? '' );
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
if ( ! isset( $GLOBALS['senroflux_test_next_instance_id'] ) ) {
	$GLOBALS['senroflux_test_next_instance_id'] = 1;
}

if ( ! class_exists( 'WC_Shipping_Method', false ) ) {
	/** Instance-settings surface of the real `WC_Shipping_Method` the zone code touches. */
	class WC_Shipping_Method {

		public string $id = '';
		public int $instance_id;
		/** @var array<string,mixed> */
		public array $instance_settings = array();
		public string $enabled          = 'yes';

		public function __construct( int $instance_id = 0 ) {
			$this->instance_id = $instance_id;
		}

		public function get_instance_option_key(): string {
			return $this->instance_id ? 'woocommerce_' . $this->id . '_' . $this->instance_id . '_settings' : '';
		}

		/** Like the real one: stored settings, else the form-field defaults. */
		public function init_instance_settings(): void {
			$stored = get_option( $this->get_instance_option_key(), null );
			if ( is_array( $stored ) ) {
				$this->instance_settings = $stored;
				return;
			}
			$defaults = array(
				'title'      => ucwords( str_replace( '_', ' ', $this->id ) ),
				'tax_status' => 'taxable',
				'cost'       => '',
			);
			if ( 'free_shipping' === $this->id ) {
				$defaults = array(
					'title'    => 'Free shipping',
					'requires' => '',
				);
			}
			$this->instance_settings = $defaults;
		}

		public function get_title(): string {
			$this->init_instance_settings();

			return (string) ( $this->instance_settings['title'] ?? '' );
		}
	}
}

if ( ! class_exists( 'WC_Shipping_Zones', false ) ) {
	class WC_Shipping_Zones {

		/** @return WC_Shipping_Method|false */
		public static function get_shipping_method( int $instance_id ) {
			foreach ( $GLOBALS['senroflux_test_shipping_zones'] as $zone ) {
				foreach ( $zone['methods'] as $row ) {
					if ( $row['instance_id'] === $instance_id ) {
						$method     = new WC_Shipping_Method( $instance_id );
						$method->id = $row['method_id'];

						return $method;
					}
				}
			}

			return false;
		}
	}
}

if ( ! class_exists( 'WC_Shipping_Zone', false ) ) {
	class WC_Shipping_Zone {

		private int $id      = 0;
		private string $name = '';
		/** @var list<object{code:string,type:string}> */
		private array $locations = array();
		/** @var list<array{instance_id:int,method_id:string}> */
		private array $methods = array();

		public function __construct( int|null $zone = null ) {
			$existing = $GLOBALS['senroflux_test_shipping_zones'][ $zone ] ?? null;
			if ( (int) $zone > 0 && is_array( $existing ) ) {
				$this->id        = $zone;
				$this->name      = $existing['name'];
				$this->locations = array_map( static fn( $location ) => (object) $location, $existing['locations'] );
				foreach ( $existing['methods'] as $method ) {
					// Fixtures may list bare method ids; real zones always have instances.
					$this->methods[] = is_array( $method ) ? $method : array(
						'instance_id' => (int) $GLOBALS['senroflux_test_next_instance_id']++,
						'method_id'   => (string) $method,
					);
				}
			}
		}

		public function get_id(): int {
			return $this->id;
		}

		public function get_zone_name(): string {
			return $this->name;
		}

		/** @return list<object{code:string,type:string}> */
		public function get_zone_locations(): array {
			return $this->locations;
		}

		/** @return array<int,WC_Shipping_Method> instance id => method, like the real one. */
		public function get_shipping_methods(): array {
			$methods = array();
			foreach ( $this->methods as $row ) {
				$method                         = new WC_Shipping_Method( $row['instance_id'] );
				$method->id                     = $row['method_id'];
				$methods[ $row['instance_id'] ] = $method;
			}

			return $methods;
		}

		public function set_zone_name( string $name ): void {
			$this->name = $name;
		}

		/** A no-op on a zone with no id yet, exactly like the real one. */
		public function add_location( string $code, string $type ): void {
			if ( 0 !== $this->id ) {
				$this->locations[] = (object) array(
					'code' => $code,
					'type' => $type,
				);
			}
		}

		/** @param list<array{code:string,type:string}> $locations */
		public function set_locations( array $locations = array() ): void {
			$this->locations = array();
			foreach ( $locations as $location ) {
				$this->add_location( $location['code'], $location['type'] );
			}
		}

		/** @return int New instance id, 0 when the method is not registered. */
		public function add_shipping_method( string $type ): int {
			if ( ! in_array( $type, array( 'flat_rate', 'free_shipping', 'local_pickup' ), true ) ) {
				return 0;
			}
			$instance_id     = (int) $GLOBALS['senroflux_test_next_instance_id']++;
			$this->methods[] = array(
				'instance_id' => $instance_id,
				'method_id'   => $type,
			);
			$this->save();

			return $instance_id;
		}

		public function save(): int {
			if ( 0 === $this->id ) {
				$this->id                                = (int) $GLOBALS['senroflux_test_next_zone_id'];
				$GLOBALS['senroflux_test_next_zone_id'] += 1;
			}

			$GLOBALS['senroflux_test_shipping_zones'][ $this->id ] = array(
				'name'      => $this->name,
				'locations' => array_map( 'get_object_vars', $this->locations ),
				'methods'   => $this->methods,
			);

			return $this->id;
		}
	}
}

if ( ! function_exists( 'WC' ) ) {
	/** Minimal `WC()` carrying `countries` and `shipping()`, as the approval cards and zone ability read them. */
	function WC(): object {
		return new class() {
			public object $countries;

			public function __construct() {
				$this->countries = new class() {
					/** @return array<string,string> */
					public function get_countries(): array {
						return $GLOBALS['senroflux_test_countries'] ?? array();
					}

					/** @return array<string,array{name:string,countries:list<string>}> */
					public function get_continents(): array {
						return $GLOBALS['senroflux_test_continents'] ?? array();
					}

					/** @return array<string,string>|false */
					public function get_states( ?string $cc = null ): array|false {
						return $GLOBALS['senroflux_test_states'][ $cc ] ?? false;
					}
				};
			}

			public function shipping(): object {
				return new class() {
					/** @return array<string,string> */
					public function get_shipping_method_class_names(): array {
						return array(
							'flat_rate'     => 'WC_Shipping_Flat_Rate',
							'free_shipping' => 'WC_Shipping_Free_Shipping',
							'local_pickup'  => 'WC_Shipping_Local_Pickup',
						);
					}
				};
			}
		};
	}
}

if ( ! function_exists( 'wc_get_price_decimals' ) ) {
	function wc_get_price_decimals(): int {
		return 2;
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
		 * @return array<string,mixed>|null
		 */
		// phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore -- mirrors WooCommerce's real WC_Tax method name.
		public static function _get_tax_rate( int $id ): ?array {
			$row = $GLOBALS['senroflux_test_tax_rates'][ $id ] ?? null;

			// Real `$wpdb->get_row()` returns null for a missing row.
			return is_array( $row ) ? $row : null;
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
	// Real signature: `wc_get_low_stock_amount( WC_Product $product )` (WC 11);
	// a product-level amount wins, else the store setting.
	function wc_get_low_stock_amount( WC_Product $product ): int {
		return $GLOBALS['senroflux_test_low_stock_by_product'][ $product->get_id() ]
			?? $GLOBALS['senroflux_test_low_stock_amount']
			?? 2;
	}
}

if ( ! isset( $GLOBALS['senroflux_test_products'] ) ) {
	$GLOBALS['senroflux_test_products'] = array();
}

// Stage 14 (AS-15/S19 approval cards): a SEPARATE store for a product's
// name/price/status fields, keyed by the SAME id as `senroflux_test_posts`
// (a product is a post) — `senroflux_test_products` above stays the
// stock-only shape the store-report tests already seed.
if ( ! isset( $GLOBALS['senroflux_test_product_rows'] ) ) {
	$GLOBALS['senroflux_test_product_rows'] = array();
}

if ( ! class_exists( 'WC_Product', false ) ) {
	class WC_Product {

		public function __construct( private int $id, private ?int $stock_quantity ) {}

		public function get_id(): int {
			return $this->id;
		}

		private function row(): ?object {
			$row = $GLOBALS['senroflux_test_product_rows'][ $this->id ] ?? null;

			return is_object( $row ) ? $row : null;
		}

		public function get_stock_quantity(): ?int {
			$row = $this->row();
			if ( null !== $row && property_exists( $row, 'stock_quantity' ) ) {
				return null === $row->stock_quantity ? null : (int) $row->stock_quantity;
			}

			return $this->stock_quantity;
		}

		public function get_name(): string {
			return (string) ( $this->row()->name ?? '' );
		}

		public function get_regular_price(): string {
			return (string) ( $this->row()->regular_price ?? '' );
		}

		public function get_sale_price(): string {
			return (string) ( $this->row()->sale_price ?? '' );
		}

		public function get_status(): string {
			return (string) ( $this->row()->status ?? 'publish' );
		}

		// Catalogue-read surface (`senroflux/products-catalogue`).
		public function get_type(): string {
			return (string) ( $this->row()->type ?? 'simple' );
		}

		public function get_sku(): string {
			return (string) ( $this->row()->sku ?? '' );
		}

		public function get_stock_status(): string {
			return (string) ( $this->row()->stock_status ?? 'instock' );
		}

		/** @return list<int> */
		public function get_category_ids(): array {
			return array_map( 'intval', (array) ( $this->row()->category_ids ?? array() ) );
		}

		public function get_description(): string {
			return (string) ( $this->row()->description ?? '' );
		}

		public function get_short_description(): string {
			return (string) ( $this->row()->short_description ?? '' );
		}
	}
}

if ( ! function_exists( 'wc_get_product' ) ) {
	function wc_get_product( int $id ): WC_Product|false {
		if ( ! isset( $GLOBALS['senroflux_test_product_rows'][ $id ] ) && ! isset( $GLOBALS['senroflux_test_products'][ $id ] ) ) {
			return false;
		}

		$stock = $GLOBALS['senroflux_test_products'][ $id ] ?? null;

		return new WC_Product( $id, null === $stock ? null : (int) $stock );
	}
}

// `product_cat` terms a catalogue test seeds: id => array( name, slug, count ).
if ( ! isset( $GLOBALS['senroflux_test_product_cats'] ) ) {
	$GLOBALS['senroflux_test_product_cats'] = array();
}

if ( ! function_exists( 'wc_get_products' ) ) {
	/**
	 * Honours `category` (slugs), `s`, `limit`, `page` and `paginate` like
	 * WC_Product_Query; every other arg is ignored. No `limit` (or -1) means
	 * all, which is what the store-report low-stock scan relies on.
	 *
	 * @param array<string,mixed> $args
	 * @return list<WC_Product>|object
	 */
	function wc_get_products( array $args ): array|object {
		$products = array();
		foreach ( $GLOBALS['senroflux_test_products'] as $id => $stock ) {
			$products[] = new WC_Product( (int) $id, null === $stock ? null : (int) $stock );
		}

		if ( ! empty( $args['category'] ) ) {
			$ids = array();
			foreach ( $GLOBALS['senroflux_test_product_cats'] as $term_id => $row ) {
				if ( in_array( $row['slug'], (array) $args['category'], true ) ) {
					$ids[] = (int) $term_id;
				}
			}
			$products = array_values(
				array_filter(
					$products,
					static fn ( WC_Product $p ): bool => array() !== array_intersect( $p->get_category_ids(), $ids )
				)
			);
		}

		if ( ! empty( $args['s'] ) ) {
			$products = array_values(
				array_filter(
					$products,
					static fn ( WC_Product $p ): bool => false !== stripos( $p->get_name(), (string) $args['s'] )
				)
			);
		}

		$total = count( $products );
		$limit = (int) ( $args['limit'] ?? -1 );
		if ( $limit > 0 ) {
			$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
			$products = array_slice( $products, ( $page - 1 ) * $limit, $limit );
		}

		if ( ! empty( $args['paginate'] ) ) {
			return (object) array(
				'products'      => $products,
				'total'         => $total,
				'max_num_pages' => $limit > 0 ? (int) ceil( $total / $limit ) : 1,
			);
		}

		return $products;
	}
}
