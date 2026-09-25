<?php
/**
 * The commerce pack's own polyfill abilities: catalogue (S19 stage 12 —
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Packs\Commerce;

use Specflux\SenroFlux\Run\Budget;
use Specflux\SenroFlux\Run\RunStore;
use Specflux\SenroFlux\Run\Tracker;
use WP_Error;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Registers the three commerce polyfill abilities.
 *
 * `set-product-image`, `coupon-create`, `coupon-enable`) and operations (S19
 * stage 13 — `orders-refund`, `shipping-zone-save`, `tax-rate-save`,
 * `store-report`, `save-store-report`).
 *
 * TARGET REPO PATH: src/Packs/Commerce/Abilities.php
 *
 * Each lives in `senroflux/` (S19's polyfill rule: the mirror is the
 * ability's NAME, never its namespace) and calls WooCommerce's PHP API
 * directly — never internal REST. Every docblock below names the upstream
 * ask these abilities stand in for (S17).
 *
 * STALE WRITE (S8/S19) — COVERAGE DECISION, stated once here rather than
 * guessed per ability:
 *
 *   - `coupon-enable` gets REAL stale-write coverage. `coupon-create` is
 *     this pack's OWN polyfill, so its successful write records the new
 *     coupon's `post_modified_gmt` marker in the run's tracker (mirroring
 *     {@see \Specflux\SenroFlux\Packs\Content\Abilities}'s create-post
 *     pattern) — that marker is the "read" S8 needs. `coupon-enable`
 *     compares it against the coupon's CURRENT `post_modified_gmt` and
 *     refuses `stale_write` (409) on a mismatch or an unrecorded id (fail
 *     closed, same as content abilities). A successful enable updates the
 *     marker to the new value.
 *   - `set-product-image` gets NO stale-write check, and this is a decision,
 *     not an omission: a product's authoritative read path is Woo's OWN
 *     `woocommerce/products-query`, an ability this pack does not register
 *     and whose `execute_callback` it cannot instrument — there is no read
 *     event this pack ever observes to record a marker FROM. Recording a
 *     marker only at `set-product-image`'s own prior writes would check
 *     nothing but the ability's own writes racing each other in one run,
 *     which is not what S8 means by "the run last reading it" and would
 *     misrepresent real coverage. Left unenforced and documented rather
 *     than faked; see the stage-12 report for the upstream ask this
 *     motivates (a Woo product-read ability whose read this pack could
 *     observe).
 *   - `orders-refund` gets NO Tracker entry either, and for a DIFFERENT
 *     reason than `set-product-image`: it is not that this pack lacks a
 *     read event to record from — it's that it always reads the order
 *     FRESH, inside the same call, immediately before deciding whether the
 *     requested amount fits what remains refundable. That live read IS the
 *     anti-stale check S8 exists to approximate with a marker; recording one
 *     anyway would only compare this call's own fresh read against itself.
 *   - `shipping-zone-save`/`tax-rate-save` also get NO Tracker entry, for the
 *     SAME live-read reason: when `zone_id`/`tax_rate_id` is given, the
 *     ability reads the CURRENT record (for the "previous" values the S19
 *     approval card shows) in the same call, immediately before writing —
 *     there is no earlier, separate "read" call whose result could go stale
 *     by the time this write runs, so there is nothing for a marker compare
 *     to protect against that the live read does not already cover. This is
 *     an honest gap, not a copy of `coupon-enable`'s coverage: a genuinely
 *     concurrent editor (another admin, or the WooCommerce settings screen)
 *     could still race this write; S8's mechanism only ever covered races
 *     against THIS run's own prior calls, not external ones, so it would not
 *     have caught that race even if applied here.
 *   - `store-report` performs no write at all (read-only, S19), and
 *     `save-store-report` only ever creates a NEW page — neither has an
 *     existing object a stale write could clobber.
 */
final class Abilities {

	/** The ability category for the commerce polyfills. */
	public const CATEGORY = 'senroflux-commerce';

	/** Whether {@see register()} has run for this request. */
	private static bool $registered = false;

	/** The ticking run's id, or null outside one (S8 run-context pattern). */
	private static ?int $current_run_id = null;

	/** The store backing the current run's row. */
	private static ?RunStore $store = null;

	/**
	 * Wire the category + ability registration hooks (call once, from the
	 * composition root).
	 */
	public static function boot(): void {
		if ( ! function_exists( 'add_action' ) ) {
			return;
		}

		add_action( 'wp_abilities_api_categories_init', array( self::class, 'registerCategory' ) );
		add_action( 'wp_abilities_api_init', array( self::class, 'register' ) );
	}

	/** Forget the per-request registered flag (test-only). */
	public static function reset(): void {
		self::$registered = false;
	}

	/**
	 * Enter the run context for one tick: which run's row backs the S8
	 * stale-write compare. Set by the composition root from the ticking
	 * run's id, NEVER from the model — same discipline as
	 * {@see \Specflux\SenroFlux\Packs\Content\Abilities::useRunContext()}.
	 *
	 * @param int|null      $run_id The ticking run's id, or null.
	 * @param RunStore|null $store  The store backing that run, or null.
	 */
	public static function useRunContext( ?int $run_id, ?RunStore $store ): void {
		self::$current_run_id = $run_id;
		self::$store          = $store;
	}

	/** Leave the run context. */
	public static function forgetRunContext(): void {
		self::$current_run_id = null;
		self::$store          = null;
	}

	/** Register the category. */
	public static function registerCategory(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'SenroFlux commerce', 'senroflux' ),
				'description' => __( 'Commerce abilities the commerce capability pack polyfills ahead of WooCommerce.', 'senroflux' ),
			)
		);
	}

	/** Register the three abilities. Idempotent per request. */
	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		self::registerSetProductImage();
		self::registerCouponCreate();
		self::registerCouponEnable();
		self::registerOrdersRefund();
		self::registerShippingZoneSave();
		self::registerTaxRateSave();
		self::registerStoreReport();
		self::registerSaveStoreReport();
	}

	// ------------------------------------------------------------------
	// Registration
	// ------------------------------------------------------------------

	/**
	 * `senroflux/set-product-image` — upstream ask: a WooCommerce
	 * `product-image` ability (S19/S17). Sets or appends an existing media
	 * library attachment as a product's image; never fetches a URL.
	 */
	private static function registerSetProductImage(): void {
		wp_register_ability(
			'senroflux/set-product-image',
			array(
				'label'               => __( 'Set product image', 'senroflux' ),
				'description'         => __( 'Set (or add to the gallery of) a product\'s image from an existing media library attachment.', 'senroflux' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'product_id', 'attachment_id' ),
					'additionalProperties' => false,
					'properties'           => array(
						'product_id'    => array( 'type' => 'integer' ),
						'attachment_id' => array( 'type' => 'integer' ),
						'gallery'       => array( 'type' => 'boolean' ),
					),
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'required'             => array( 'product_id', 'attachment_id' ),
					'additionalProperties' => false,
					'properties'           => array(
						'product_id'    => array( 'type' => 'integer' ),
						'attachment_id' => array( 'type' => 'integer' ),
					),
				),
				'execute_callback'    => static function ( $input = array() ) {
					return self::executeSetProductImage( is_array( $input ) ? $input : array() );
				},
				'permission_callback' => static function ( $input = array() ) {
					$input = is_array( $input ) ? $input : array();

					return function_exists( 'current_user_can' )
						&& current_user_can( 'edit_post', (int) ( $input['product_id'] ?? 0 ) );
				},
				'meta'                => self::meta(
					array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
					)
				),
			)
		);
	}

	/**
	 * `senroflux/coupon-create` — upstream ask: a WooCommerce coupon-create
	 * ability (S19/S17). Creates a `WC_Coupon` as a DRAFT; refuses a code
	 * collision (S4's slug-collision rule, applied to coupons).
	 */
	private static function registerCouponCreate(): void {
		wp_register_ability(
			'senroflux/coupon-create',
			array(
				'label'               => __( 'Create coupon (draft)', 'senroflux' ),
				'description'         => __( 'Create a draft coupon. Refuses a code that already exists.', 'senroflux' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'code', 'discount_type', 'amount' ),
					'additionalProperties' => false,
					'properties'           => array(
						'code'          => array( 'type' => 'string' ),
						'discount_type' => array( 'type' => 'string' ),
						'amount'        => array( 'type' => 'number' ),
						'expiry'        => array( 'type' => 'string' ),
						'usage_limit'   => array( 'type' => 'integer' ),
						'product_ids'   => array(
							'type'  => 'array',
							'items' => array( 'type' => 'integer' ),
						),
					),
				),
				'output_schema'       => self::couponOutputSchema(),
				'execute_callback'    => static function ( $input = array() ) {
					return self::executeCouponCreate( is_array( $input ) ? $input : array() );
				},
				'permission_callback' => static function ( $input = array() ) {
					unset( $input );

					// phpcs:ignore WordPress.WP.Capabilities.Unknown -- a real WooCommerce capability; unknown only to phpcs's core capability list.
					return function_exists( 'current_user_can' ) && current_user_can( 'manage_woocommerce' );
				},
				'meta'                => self::meta(
					array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					)
				),
			)
		);
	}

	/**
	 * `senroflux/coupon-enable` — companion to `coupon-create` (draft →
	 * publish), the same "adopted object" a plan names before acting on it.
	 * Stale-write checked (see the class docblock).
	 */
	private static function registerCouponEnable(): void {
		wp_register_ability(
			'senroflux/coupon-enable',
			array(
				'label'               => __( 'Enable coupon', 'senroflux' ),
				'description'         => __( 'Publish a draft coupon. Refuses a stale write if the coupon changed since it was created.', 'senroflux' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'coupon_id' ),
					'additionalProperties' => false,
					'properties'           => array(
						'coupon_id' => array( 'type' => 'integer' ),
					),
				),
				'output_schema'       => self::couponOutputSchema(),
				'execute_callback'    => static function ( $input = array() ) {
					return self::executeCouponEnable( is_array( $input ) ? $input : array() );
				},
				'permission_callback' => static function ( $input = array() ) {
					unset( $input );

					// phpcs:ignore WordPress.WP.Capabilities.Unknown -- a real WooCommerce capability; unknown only to phpcs's core capability list.
					return function_exists( 'current_user_can' ) && current_user_can( 'manage_woocommerce' );
				},
				'meta'                => self::meta(
					array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
					)
				),
			)
		);
	}

	/**
	 * `senroflux/orders-refund` — upstream ask: a WooCommerce order-refund
	 * ability (S19/S17). Reads the order fresh, refuses an amount over what
	 * remains refundable, refuses once this run's `refunds` budget (default
	 * 1, lower-only) is spent, then calls `wc_create_refund()`.
	 *
	 * PERMISSION DECISION (S19 item 2, explicitly asked to be stated): a
	 * refund moves money, so it requires BOTH `manage_woocommerce` (the
	 * pack's run capability) AND `edit_shop_orders` — the real WooCommerce
	 * capability for touching an order — rather than either alone. This is
	 * belt-and-braces over the pack-level `manage_woocommerce` check:
	 * `edit_shop_orders` is the capability WooCommerce's OWN order-edit
	 * screen requires, so a user who can start a commerce run but has been
	 * carved out of order editing (a real WooCommerce role shape) still
	 * cannot direct a refund through this ability.
	 */
	private static function registerOrdersRefund(): void {
		wp_register_ability(
			'senroflux/orders-refund',
			array(
				'label'               => __( 'Refund order', 'senroflux' ),
				'description'         => __( 'Refund part or all of an order. Refuses an amount over what remains refundable, and a second refund once this run\'s refund budget is spent.', 'senroflux' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'order_id', 'amount', 'reason', 'restock' ),
					'additionalProperties' => false,
					'properties'           => array(
						'order_id'   => array( 'type' => 'integer' ),
						'amount'     => array( 'type' => 'number' ),
						'reason'     => array( 'type' => 'string' ),
						'restock'    => array( 'type' => 'boolean' ),
						'line_items' => array( 'type' => 'array' ),
					),
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'required'             => array( 'order_id', 'amount', 'money_moved', 'remaining_refundable' ),
					'additionalProperties' => false,
					'properties'           => array(
						'order_id'             => array( 'type' => 'integer' ),
						'amount'               => array( 'type' => 'number' ),
						'money_moved'          => array( 'type' => 'boolean' ),
						'remaining_refundable' => array( 'type' => 'number' ),
					),
				),
				'execute_callback'    => static function ( $input = array() ) {
					return self::executeOrdersRefund( is_array( $input ) ? $input : array() );
				},
				'permission_callback' => static function ( $input = array() ) {
					unset( $input );

					return function_exists( 'current_user_can' )
						&& current_user_can( 'manage_woocommerce' ) // phpcs:ignore WordPress.WP.Capabilities.Unknown -- a real WooCommerce capability; unknown only to phpcs's core capability list.
						&& current_user_can( 'edit_shop_orders' ); // phpcs:ignore WordPress.WP.Capabilities.Unknown -- a real WooCommerce capability; unknown only to phpcs's core capability list.
				},
				'meta'                => self::meta(
					array(
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => false,
					)
				),
			)
		);
	}

	/**
	 * `senroflux/shipping-zone-save` — upstream ask: a WooCommerce
	 * shipping-zone-write ability (S19/S17). Create-or-update only, via
	 * `WC_Shipping_Zone`; records the PREVIOUS values on an update so the
	 * approval card can show current beside proposed.
	 *
	 * PERMISSION DECISION: `manage_woocommerce` only — shipping zones are a
	 * store-wide settings object, not a per-order one, so `edit_shop_orders`
	 * (an order-editing capability) has no bearing here.
	 */
	private static function registerShippingZoneSave(): void {
		wp_register_ability(
			'senroflux/shipping-zone-save',
			array(
				'label'               => __( 'Save shipping zone', 'senroflux' ),
				'description'         => __( 'Create or update a shipping zone. Never deletes one.', 'senroflux' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'name', 'locations', 'methods' ),
					'additionalProperties' => false,
					'properties'           => array(
						'zone_id'   => array( 'type' => 'integer' ),
						'name'      => array( 'type' => 'string' ),
						'locations' => array( 'type' => 'array' ),
						'methods'   => array( 'type' => 'array' ),
					),
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'required'             => array( 'zone_id' ),
					'additionalProperties' => false,
					'properties'           => array(
						'zone_id'  => array( 'type' => 'integer' ),
						'previous' => array( 'type' => array( 'object', 'null' ) ),
					),
				),
				'execute_callback'    => static function ( $input = array() ) {
					return self::executeShippingZoneSave( is_array( $input ) ? $input : array() );
				},
				'permission_callback' => static function ( $input = array() ) {
					unset( $input );

					// phpcs:ignore WordPress.WP.Capabilities.Unknown -- a real WooCommerce capability; unknown only to phpcs's core capability list.
					return function_exists( 'current_user_can' ) && current_user_can( 'manage_woocommerce' );
				},
				'meta'                => self::meta(
					array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
					)
				),
			)
		);
	}

	/**
	 * `senroflux/tax-rate-save` — upstream ask: a WooCommerce tax-rate-write
	 * ability (S19/S17). Create-or-update only, via `WC_Tax`; records the
	 * PREVIOUS rate row on an update.
	 *
	 * PERMISSION DECISION: `manage_woocommerce` only (same reasoning as
	 * `shipping-zone-save` — a store-wide settings object).
	 */
	private static function registerTaxRateSave(): void {
		wp_register_ability(
			'senroflux/tax-rate-save',
			array(
				'label'               => __( 'Save tax rate', 'senroflux' ),
				'description'         => __( 'Create or update a tax rate. Never deletes one.', 'senroflux' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'country', 'rate', 'name', 'priority', 'compound', 'shipping' ),
					'additionalProperties' => false,
					'properties'           => array(
						'tax_rate_id' => array( 'type' => 'integer' ),
						'country'     => array( 'type' => 'string' ),
						'state'       => array( 'type' => 'string' ),
						'rate'        => array( 'type' => 'string' ),
						'name'        => array( 'type' => 'string' ),
						'priority'    => array( 'type' => 'integer' ),
						'compound'    => array( 'type' => 'boolean' ),
						'shipping'    => array( 'type' => 'boolean' ),
						'class'       => array( 'type' => 'string' ),
					),
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'required'             => array( 'tax_rate_id' ),
					'additionalProperties' => false,
					'properties'           => array(
						'tax_rate_id' => array( 'type' => 'integer' ),
						'previous'    => array( 'type' => array( 'object', 'null' ) ),
					),
				),
				'execute_callback'    => static function ( $input = array() ) {
					return self::executeTaxRateSave( is_array( $input ) ? $input : array() );
				},
				'permission_callback' => static function ( $input = array() ) {
					unset( $input );

					// phpcs:ignore WordPress.WP.Capabilities.Unknown -- a real WooCommerce capability; unknown only to phpcs's core capability list.
					return function_exists( 'current_user_can' ) && current_user_can( 'manage_woocommerce' );
				},
				'meta'                => self::meta(
					array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
					)
				),
			)
		);
	}

	/**
	 * `senroflux/store-report` — upstream ask: a WooCommerce sales-report
	 * ability (S19/S17). Read-only: order count, gross/net sales and refunds
	 * over paid statuses via `wc_get_orders()`, in store currency, plus
	 * products at or below `wc_get_low_stock_amount()`. Refuses a window
	 * over 92 days.
	 *
	 * PERMISSION DECISION: `manage_woocommerce` only — a read.
	 */
	private static function registerStoreReport(): void {
		wp_register_ability(
			'senroflux/store-report',
			array(
				'label'               => __( 'Store health report', 'senroflux' ),
				'description'         => __( 'A read-only sales/refunds/low-stock report over a date window of at most 92 days.', 'senroflux' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'from', 'to' ),
					'additionalProperties' => false,
					'properties'           => array(
						'from' => array( 'type' => 'string' ),
						'to'   => array( 'type' => 'string' ),
					),
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'required'             => array( 'from', 'to', 'order_count', 'gross_sales', 'net_sales', 'refunds_total', 'currency', 'low_stock_products' ),
					'additionalProperties' => false,
					'properties'           => array(
						'from'               => array( 'type' => 'string' ),
						'to'                 => array( 'type' => 'string' ),
						'order_count'        => array( 'type' => 'integer' ),
						'gross_sales'        => array( 'type' => 'number' ),
						'net_sales'          => array( 'type' => 'number' ),
						'refunds_total'      => array( 'type' => 'number' ),
						'currency'           => array( 'type' => 'string' ),
						'low_stock_products' => array( 'type' => 'array' ),
					),
				),
				'execute_callback'    => static function ( $input = array() ) {
					return self::executeStoreReport( is_array( $input ) ? $input : array() );
				},
				'permission_callback' => static function ( $input = array() ) {
					unset( $input );

					// phpcs:ignore WordPress.WP.Capabilities.Unknown -- a real WooCommerce capability; unknown only to phpcs's core capability list.
					return function_exists( 'current_user_can' ) && current_user_can( 'manage_woocommerce' );
				},
				'meta'                => self::meta(
					array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					)
				),
			)
		);
	}

	/**
	 * `senroflux/save-store-report` — upstream ask: none (a harness-side
	 * convenience over the report above, not a WooCommerce ask). Creates a
	 * PRIVATE page from the report's harness-built table markup; refuses
	 * (never strips) any tag outside the allowed set.
	 *
	 * PERMISSION DECISION: `manage_woocommerce` (the pack's run capability)
	 * plus `publish_pages` — creating any page, private or not, is a content
	 * action outside WooCommerce's own capability set.
	 */
	private static function registerSaveStoreReport(): void {
		wp_register_ability(
			'senroflux/save-store-report',
			array(
				'label'               => __( 'Save store report as a page', 'senroflux' ),
				'description'         => __( 'Save a store report as a new private page. Refuses markup outside a small allowed tag set.', 'senroflux' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'title', 'markup' ),
					'additionalProperties' => false,
					'properties'           => array(
						'title'  => array( 'type' => 'string' ),
						'markup' => array( 'type' => 'string' ),
					),
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'required'             => array( 'page_id' ),
					'additionalProperties' => false,
					'properties'           => array(
						'page_id' => array( 'type' => 'integer' ),
					),
				),
				'execute_callback'    => static function ( $input = array() ) {
					return self::executeSaveStoreReport( is_array( $input ) ? $input : array() );
				},
				'permission_callback' => static function ( $input = array() ) {
					unset( $input );

					return function_exists( 'current_user_can' )
						&& current_user_can( 'manage_woocommerce' ) // phpcs:ignore WordPress.WP.Capabilities.Unknown -- a real WooCommerce capability; unknown only to phpcs's core capability list.
						&& current_user_can( 'publish_pages' );
				},
				'meta'                => self::meta(
					array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					)
				),
			)
		);
	}

	/** @return array<string,mixed> */
	private static function couponOutputSchema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'coupon_id' ),
			'additionalProperties' => false,
			'properties'           => array(
				'coupon_id' => array( 'type' => 'integer' ),
			),
		);
	}

	// ------------------------------------------------------------------
	// Execute
	// ------------------------------------------------------------------

	/**
	 * @param array<string,mixed> $input Call input.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function executeSetProductImage( array $input ): array|WP_Error {
		$product_id    = (int) ( $input['product_id'] ?? 0 );
		$attachment_id = (int) ( $input['attachment_id'] ?? 0 );
		$gallery       = true === ( $input['gallery'] ?? false );

		if ( 'product' !== self::postType( $product_id ) ) {
			return new WP_Error( 'not_found', __( 'Product not found.', 'senroflux' ), array( 'status' => 400 ) );
		}
		if ( 'attachment' !== self::postType( $attachment_id ) || ! self::isImageAttachment( $attachment_id ) ) {
			return new WP_Error( 'not_an_image', __( 'That attachment is not an image.', 'senroflux' ), array( 'status' => 400 ) );
		}
		if ( '' === self::altText( $attachment_id ) ) {
			return new WP_Error( 'missing_alt', __( 'That image has no alt text.', 'senroflux' ), array( 'status' => 400 ) );
		}

		if ( $gallery ) {
			self::appendToGallery( $product_id, $attachment_id );
		} elseif ( function_exists( 'set_post_thumbnail' ) ) {
			set_post_thumbnail( $product_id, $attachment_id );
		}

		return array(
			'product_id'    => $product_id,
			'attachment_id' => $attachment_id,
		);
	}

	/**
	 * @param array<string,mixed> $input Call input.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function executeCouponCreate( array $input ): array|WP_Error {
		$code = is_string( $input['code'] ?? null ) ? trim( $input['code'] ) : '';
		if ( '' === $code ) {
			return new WP_Error( 'invalid_input', __( 'A coupon code is required.', 'senroflux' ), array( 'status' => 400 ) );
		}
		if ( self::couponCodeExists( $code ) ) {
			return new WP_Error(
				'slug_collision',
				__( 'A coupon with that code already exists.', 'senroflux' ),
				array( 'status' => 409 )
			);
		}
		if ( ! class_exists( '\WC_Coupon' ) ) {
			return new WP_Error( 'gateway_unavailable', __( 'Coupons are not available.', 'senroflux' ), array( 'status' => 400 ) );
		}

		$coupon = new \WC_Coupon();
		$coupon->set_code( $code );
		$coupon->set_discount_type( is_string( $input['discount_type'] ?? null ) ? $input['discount_type'] : 'fixed_cart' );
		$coupon->set_amount( (string) ( $input['amount'] ?? 0 ) );
		if ( is_string( $input['expiry'] ?? null ) && '' !== $input['expiry'] ) {
			$coupon->set_date_expires( $input['expiry'] );
		}
		if ( isset( $input['usage_limit'] ) ) {
			$coupon->set_usage_limit( (int) $input['usage_limit'] );
		}
		if ( is_array( $input['product_ids'] ?? null ) ) {
			$coupon->set_product_ids( array_map( 'intval', $input['product_ids'] ) );
		}
		$coupon->set_status( 'draft' );
		$id = (int) $coupon->save();

		// S8: record the marker THIS run now has for the object it just
		// created — the "read" a later coupon-enable in the same run is
		// compared against.
		self::recordCouponMarker( $id );

		return array( 'coupon_id' => $id );
	}

	/**
	 * @param array<string,mixed> $input Call input.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function executeCouponEnable( array $input ): array|WP_Error {
		$id = (int) ( $input['coupon_id'] ?? 0 );

		if ( 'shop_coupon' !== self::postType( $id ) ) {
			return new WP_Error( 'not_found', __( 'Coupon not found.', 'senroflux' ), array( 'status' => 400 ) );
		}

		if ( self::isStaleWrite( $id, self::modifiedMarker( $id ) ) ) {
			return new WP_Error(
				'stale_write',
				__( 'This coupon changed since this run last saw it — re-read it before enabling it.', 'senroflux' ),
				array( 'status' => 409 )
			);
		}

		if ( class_exists( '\WC_Coupon' ) ) {
			$coupon = new \WC_Coupon( $id );
			$coupon->set_status( 'publish' );
			$coupon->save();
		} elseif ( function_exists( 'wp_update_post' ) ) {
			wp_update_post(
				array(
					'ID'          => $id,
					'post_status' => 'publish',
				)
			);
		}

		self::recordCouponMarker( $id );

		return array( 'coupon_id' => $id );
	}

	/**
	 * @param array<string,mixed> $input Call input.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function executeOrdersRefund( array $input ): array|WP_Error {
		$order_id = (int) ( $input['order_id'] ?? 0 );
		$amount   = is_numeric( $input['amount'] ?? null ) ? (float) $input['amount'] : 0.0;
		$reason   = is_string( $input['reason'] ?? null ) ? $input['reason'] : '';
		$restock  = true === ( $input['restock'] ?? false );

		if ( $amount <= 0 ) {
			return new WP_Error( 'invalid_input', __( 'The refund amount must be greater than zero.', 'senroflux' ), array( 'status' => 400 ) );
		}

		if ( ! function_exists( 'wc_get_order' ) ) {
			return new WP_Error( 'gateway_unavailable', __( 'Orders are not available.', 'senroflux' ), array( 'status' => 400 ) );
		}

		$order = wc_get_order( $order_id );
		if ( ! is_object( $order ) ) {
			return new WP_Error( 'not_found', __( 'Order not found.', 'senroflux' ), array( 'status' => 400 ) );
		}

		$total            = self::orderTotal( $order );
		$already_refunded = self::orderTotalRefunded( $order );
		$remaining        = $total - $already_refunded;

		if ( $amount > $remaining + 0.001 ) {
			return new WP_Error(
				'refund_too_large',
				__( 'That refund is more than remains refundable on this order.', 'senroflux' ),
				array( 'status' => 400 )
			);
		}

		if ( self::refundsExhausted() ) {
			return new WP_Error(
				'budget_exhausted',
				__( 'This run has used up its refund budget.', 'senroflux' ),
				array( 'status' => 409 )
			);
		}

		if ( ! function_exists( 'wc_create_refund' ) ) {
			return new WP_Error( 'gateway_unavailable', __( 'Refunds are not available.', 'senroflux' ), array( 'status' => 400 ) );
		}

		// D4/S19: `refund_payment` = whether the order's payment gateway
		// actually supports refunds `[assumed]`. When it does not (or the
		// gateway cannot be resolved), the refund is recorded on the order
		// only — no money moves through this call — never assumed true.
		$money_moves = self::gatewaySupportsRefunds( $order );

		$refund = wc_create_refund(
			array(
				'order_id'       => $order_id,
				'amount'         => $amount,
				'reason'         => $reason,
				'restock_items'  => $restock,
				'line_items'     => is_array( $input['line_items'] ?? null ) ? $input['line_items'] : array(),
				'refund_payment' => $money_moves,
			)
		);

		if ( $refund instanceof WP_Error ) {
			return $refund;
		}

		return array(
			'order_id'             => $order_id,
			'amount'               => $amount,
			'money_moved'          => $money_moves,
			'remaining_refundable' => max( 0.0, $remaining - $amount ),
		);
	}

	/**
	 * @param array<string,mixed> $input Call input.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function executeShippingZoneSave( array $input ): array|WP_Error {
		$zone_id   = (int) ( $input['zone_id'] ?? 0 );
		$name      = is_string( $input['name'] ?? null ) ? trim( $input['name'] ) : '';
		$locations = is_array( $input['locations'] ?? null ) ? $input['locations'] : array();
		$methods   = is_array( $input['methods'] ?? null ) ? $input['methods'] : array();

		if ( '' === $name ) {
			return new WP_Error( 'invalid_input', __( 'A zone name is required.', 'senroflux' ), array( 'status' => 400 ) );
		}
		if ( ! class_exists( '\WC_Shipping_Zone' ) ) {
			return new WP_Error( 'gateway_unavailable', __( 'Shipping zones are not available.', 'senroflux' ), array( 'status' => 400 ) );
		}

		$previous = null;
		if ( $zone_id > 0 ) {
			$existing = new \WC_Shipping_Zone( $zone_id );
			if ( 0 === $existing->get_id() ) {
				return new WP_Error( 'not_found', __( 'Shipping zone not found.', 'senroflux' ), array( 'status' => 400 ) );
			}
			$previous = array(
				'name'      => $existing->get_zone_name(),
				'locations' => $existing->get_zone_locations(),
				'methods'   => $existing->get_shipping_methods(),
			);
			$zone     = $existing;
		} else {
			$zone = new \WC_Shipping_Zone();
		}

		$zone->set_zone_name( $name );
		foreach ( $locations as $location ) {
			$code = is_array( $location ) ? (string) ( $location['code'] ?? '' ) : (string) $location;
			$type = is_array( $location ) ? (string) ( $location['type'] ?? 'country' ) : 'country';
			if ( '' !== $code ) {
				$zone->add_location( $code, $type );
			}
		}
		$saved_id = (int) $zone->save();

		foreach ( $methods as $method ) {
			$method_id = is_array( $method ) ? (string) ( $method['id'] ?? '' ) : (string) $method;
			if ( '' !== $method_id ) {
				$zone->add_shipping_method( $method_id );
			}
		}

		return array(
			'zone_id'  => $saved_id,
			'previous' => $previous,
		);
	}

	/**
	 * @param array<string,mixed> $input Call input.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function executeTaxRateSave( array $input ): array|WP_Error {
		$tax_rate_id = (int) ( $input['tax_rate_id'] ?? 0 );
		$country     = is_string( $input['country'] ?? null ) ? strtoupper( trim( $input['country'] ) ) : '';

		if ( '' === $country ) {
			return new WP_Error( 'invalid_input', __( 'A country is required.', 'senroflux' ), array( 'status' => 400 ) );
		}
		if ( ! class_exists( '\WC_Tax' ) ) {
			return new WP_Error( 'gateway_unavailable', __( 'Tax rates are not available.', 'senroflux' ), array( 'status' => 400 ) );
		}

		$data = array(
			'tax_rate_country'  => $country,
			'tax_rate_state'    => is_string( $input['state'] ?? null ) ? strtoupper( trim( $input['state'] ) ) : '',
			'tax_rate'          => is_scalar( $input['rate'] ?? null ) ? (string) $input['rate'] : '0',
			'tax_rate_name'     => is_string( $input['name'] ?? null ) ? $input['name'] : '',
			'tax_rate_priority' => (int) ( $input['priority'] ?? 1 ),
			'tax_rate_compound' => ( true === ( $input['compound'] ?? false ) ) ? 1 : 0,
			'tax_rate_shipping' => ( true === ( $input['shipping'] ?? false ) ) ? 1 : 0,
			'tax_rate_class'    => is_string( $input['class'] ?? null ) ? $input['class'] : '',
		);

		$previous = null;
		if ( $tax_rate_id > 0 ) {
			$previous = \WC_Tax::_get_tax_rate( $tax_rate_id );
			if ( ! is_array( $previous ) || array() === $previous ) {
				return new WP_Error( 'not_found', __( 'Tax rate not found.', 'senroflux' ), array( 'status' => 400 ) );
			}
			\WC_Tax::_update_tax_rate( $tax_rate_id, $data );
			$id = $tax_rate_id;
		} else {
			$id = (int) \WC_Tax::_insert_tax_rate( $data );
		}

		return array(
			'tax_rate_id' => $id,
			'previous'    => $previous,
		);
	}

	/**
	 * @param array<string,mixed> $input Call input.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function executeStoreReport( array $input ): array|WP_Error {
		$from = is_string( $input['from'] ?? null ) ? $input['from'] : '';
		$to   = is_string( $input['to'] ?? null ) ? $input['to'] : '';

		$from_ts = strtotime( $from . ' 00:00:00' );
		$to_ts   = strtotime( $to . ' 23:59:59' );
		if ( false === $from_ts || false === $to_ts || $to_ts < $from_ts ) {
			return new WP_Error( 'invalid_input', __( 'A valid from/to date range is required.', 'senroflux' ), array( 'status' => 400 ) );
		}

		$days = ( $to_ts - $from_ts ) / DAY_IN_SECONDS;
		if ( $days > 92 ) {
			return new WP_Error(
				'window_too_long',
				__( 'A store report window may not exceed 92 days.', 'senroflux' ),
				array( 'status' => 400 )
			);
		}

		if ( ! function_exists( 'wc_get_orders' ) ) {
			return new WP_Error( 'gateway_unavailable', __( 'Orders are not available.', 'senroflux' ), array( 'status' => 400 ) );
		}

		$paid_statuses = function_exists( 'wc_get_is_paid_statuses' ) ? wc_get_is_paid_statuses() : array( 'processing', 'completed' );

		$orders = wc_get_orders(
			array(
				'status'       => $paid_statuses,
				'date_created' => $from_ts . '...' . $to_ts,
				'limit'        => -1,
			)
		);

		$order_count   = 0;
		$gross         = 0.0;
		$refunds_total = 0.0;
		foreach ( (array) $orders as $order ) {
			if ( ! is_object( $order ) ) {
				continue;
			}
			++$order_count;
			$gross         += self::orderTotal( $order );
			$refunds_total += self::orderTotalRefunded( $order );
		}

		$currency         = function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : 'USD';
		$low_stock_amount = function_exists( 'wc_get_low_stock_amount' ) ? (int) wc_get_low_stock_amount() : 2;

		return array(
			'from'               => $from,
			'to'                 => $to,
			'order_count'        => $order_count,
			'gross_sales'        => round( $gross, 2 ),
			'net_sales'          => round( $gross - $refunds_total, 2 ),
			'refunds_total'      => round( $refunds_total, 2 ),
			'currency'           => $currency,
			'low_stock_products' => self::lowStockProductIds( $low_stock_amount ),
		);
	}

	/**
	 * @param array<string,mixed> $input Call input.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function executeSaveStoreReport( array $input ): array|WP_Error {
		$title  = is_string( $input['title'] ?? null ) ? trim( $input['title'] ) : '';
		$markup = is_string( $input['markup'] ?? null ) ? $input['markup'] : '';

		if ( '' === $title ) {
			return new WP_Error( 'invalid_input', __( 'A title is required.', 'senroflux' ), array( 'status' => 400 ) );
		}

		$violation = ( new ReportMarkupValidator() )->validate( $markup );
		if ( null !== $violation ) {
			return $violation;
		}

		if ( ! function_exists( 'wp_insert_post' ) ) {
			return new WP_Error( 'gateway_unavailable', __( 'Pages are not available.', 'senroflux' ), array( 'status' => 400 ) );
		}

		$page_id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_title'   => $title,
				'post_content' => $markup,
				'post_status'  => 'private',
			),
			true
		);

		if ( $page_id instanceof WP_Error ) {
			return $page_id;
		}

		return array( 'page_id' => (int) $page_id );
	}

	// ------------------------------------------------------------------
	// Shared helpers
	// ------------------------------------------------------------------

	private static function postType( int $id ): string {
		if ( 0 === $id || ! function_exists( 'get_post' ) ) {
			return '';
		}
		$post = get_post( $id );

		return is_object( $post ) ? (string) $post->post_type : '';
	}

	private static function isImageAttachment( int $attachment_id ): bool {
		if ( ! function_exists( 'get_post_mime_type' ) ) {
			return false;
		}
		$mime = get_post_mime_type( $attachment_id );

		return is_string( $mime ) && str_starts_with( $mime, 'image/' );
	}

	private static function altText( int $attachment_id ): string {
		if ( ! function_exists( 'get_post_meta' ) ) {
			return '';
		}
		$alt = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );

		return is_string( $alt ) ? trim( $alt ) : '';
	}

	private static function appendToGallery( int $product_id, int $attachment_id ): void {
		if ( ! function_exists( 'get_post_meta' ) || ! function_exists( 'update_post_meta' ) ) {
			return;
		}
		$existing = get_post_meta( $product_id, '_product_image_gallery', true );
		$ids      = is_string( $existing ) && '' !== $existing ? array_map( 'intval', explode( ',', $existing ) ) : array();
		if ( ! in_array( $attachment_id, $ids, true ) ) {
			$ids[] = $attachment_id;
		}
		update_post_meta( $product_id, '_product_image_gallery', implode( ',', $ids ) );
	}

	/**
	 * Whether `$code` already names a coupon (S4's slug-collision rule
	 * applied to coupons). Uses Woo's own lookup, the only reliable one — a
	 * `WP_Query( ['post_type' => 'shop_coupon', 'title' => $code] )` probe
	 * was deliberately NOT added as a fallback: `title` is an exact,
	 * case-sensitive `post_title =` match with no slug-style normalisation,
	 * so it would silently under-detect a collision rather than refuse one
	 * (fail-open), which is the one thing S4's rule must never do. Absent
	 * `wc_get_coupon_id_by_code()` (WooCommerce not really active), this pack
	 * has no reliable way to answer and fails CLOSED: return true. Test
	 * stubs provide the function directly rather than a post-title probe.
	 */
	private static function couponCodeExists( string $code ): bool {
		if ( function_exists( 'wc_get_coupon_id_by_code' ) ) {
			return ( (int) wc_get_coupon_id_by_code( $code ) ) > 0;
		}

		return true; // Fail closed: cannot check, so refuse rather than risk a silent collision.
	}

	private static function modifiedMarker( int $id ): string {
		if ( ! function_exists( 'get_post' ) ) {
			return '';
		}
		$post = get_post( $id );

		return is_object( $post ) ? (string) $post->post_modified_gmt : '';
	}

	// ------------------------------------------------------------------
	// S19 operations helpers (refund budget, gateway check, low stock)
	// ------------------------------------------------------------------

	/**
	 * Whether the run's `refunds` budget key is already spent. Fails OPEN
	 * (never exhausted) with no run context resolved — same reasoning as
	 * {@see \Specflux\SenroFlux\Packs\Content\Media::imagesExhausted()}: a
	 * resource cap, not a security gate, and a call reached with no run in
	 * flight has no meaningful budget to check.
	 */
	private static function refundsExhausted(): bool {
		if ( null === self::$current_run_id || null === self::$store ) {
			return false;
		}

		$run = self::$store->getRun( self::$current_run_id );
		if ( null === $run ) {
			return false;
		}

		$limit = (int) ( $run->budget[ Budget::REFUNDS ] ?? Budget::defaults()[ Budget::REFUNDS ] );

		return self::spentRefunds() >= $limit;
	}

	/**
	 * The number of PRIOR successful `orders-refund` calls in this run,
	 * derived from the persisted step history via the shared
	 * {@see Budget::spentCount()} rule (same mechanism `images` uses).
	 */
	private static function spentRefunds(): int {
		if ( null === self::$current_run_id || null === self::$store ) {
			return 0;
		}

		return Budget::spentCount( self::$store, self::$current_run_id, 'orders-refund' );
	}

	/**
	 * An order's total, duck-typed (same defensive style as
	 * {@see gatewaySupportsRefunds()}): `wc_get_order()` returns a genuine
	 * `WC_Order` at runtime, but this codebase has no WooCommerce class
	 * stubs to type it against statically, so every accessor is called
	 * through an explicit `method_exists()` guard rather than assumed.
	 *
	 * @param object $order The order object.
	 */
	private static function orderTotal( object $order ): float {
		return method_exists( $order, 'get_total' ) ? (float) $order->get_total() : 0.0;
	}

	/**
	 * @param object $order The order object.
	 */
	private static function orderTotalRefunded( object $order ): float {
		return method_exists( $order, 'get_total_refunded' ) ? (float) $order->get_total_refunded() : 0.0;
	}

	/**
	 * Whether the order's payment gateway supports refunds `[assumed, S19]`.
	 * Fails CLOSED to false (record-only, no money moves) when the gateway
	 * cannot be resolved or does not say yes — understating "money moves"
	 * would misrepresent what the call actually did; failing to record-only
	 * never does.
	 *
	 * @param object $order The order object.
	 */
	private static function gatewaySupportsRefunds( object $order ): bool {
		if ( ! method_exists( $order, 'get_payment_method' ) ) {
			return false;
		}
		$method = (string) $order->get_payment_method();
		if ( '' === $method || ! function_exists( 'wc_get_payment_gateway_by_order' ) ) {
			return false;
		}
		$gateway = wc_get_payment_gateway_by_order( $order );

		return is_object( $gateway ) && method_exists( $gateway, 'supports' ) && (bool) $gateway->supports( 'refunds' );
	}

	/**
	 * Product ids at or below `$threshold` stock. Read-only (S19
	 * `store-report`): only ever calls `wc_get_products()`, never a writer.
	 *
	 * @return list<int>
	 */
	private static function lowStockProductIds( int $threshold ): array {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return array();
		}

		$products = wc_get_products( array( 'limit' => -1 ) );
		$ids      = array();
		foreach ( (array) $products as $product ) {
			if ( ! is_object( $product ) || ! method_exists( $product, 'get_stock_quantity' ) ) {
				continue;
			}
			$quantity = $product->get_stock_quantity();
			if ( null !== $quantity && (int) $quantity <= $threshold ) {
				$ids[] = method_exists( $product, 'get_id' ) ? (int) $product->get_id() : 0;
			}
		}

		return $ids;
	}

	// ------------------------------------------------------------------
	// S8 stale-write tracker (coupon-enable only — see the class docblock)
	// ------------------------------------------------------------------

	/**
	 * @return array<string,mixed>
	 */
	private static function currentObjects(): array {
		if ( null === self::$current_run_id || null === self::$store ) {
			return array();
		}
		$run = self::$store->getRun( self::$current_run_id );

		return ( null !== $run && is_array( $run->objects ) ) ? $run->objects : array();
	}

	/**
	 * Fails CLOSED (true, i.e. refuse) with no run context resolved — same
	 * fail-closed rule as {@see \Specflux\SenroFlux\Packs\Content\Abilities}.
	 */
	private static function isStaleWrite( int $object_id, string $current_marker ): bool {
		if ( null === self::$current_run_id || null === self::$store ) {
			return true;
		}

		return Tracker::staleWrite( self::currentObjects(), $object_id, $current_marker );
	}

	private static function recordCouponMarker( int $coupon_id ): void {
		if ( null === self::$current_run_id || null === self::$store ) {
			return;
		}

		$objects = Tracker::recordRead( self::currentObjects(), $coupon_id, self::modifiedMarker( $coupon_id ) );
		self::$store->updateRun( self::$current_run_id, array( 'objects_json' => $objects ) );
	}

	/**
	 * @param array<string,mixed> $annotations Tool annotations.
	 * @return array<string,mixed>
	 */
	private static function meta( array $annotations ): array {
		return array(
			'annotations' => $annotations,
			'senroflux'   => array( 'hidden' => false ),
		);
	}
}
