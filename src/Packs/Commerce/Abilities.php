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
 * `store-report`, `save-store-report`) — plus the Tier-0 read
 * `products-catalogue`.
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

	/** `products-catalogue` page size when the caller names none. */
	private const CATALOGUE_DEFAULT_PER_PAGE = 20;

	/**
	 * Shipping methods `shipping-zone-save` can add, with the settings each
	 * holds beyond title/enabled — per their `instance_form_fields`
	 * (free_shipping has no cost or tax status).
	 */
	private const ZONE_METHOD_FIELDS = array(
		'flat_rate'     => array( 'cost', 'tax_status' ),
		'free_shipping' => array(),
		'local_pickup'  => array( 'cost', 'tax_status' ),
	);

	/** `products-catalogue` page-size cap (WooCommerce's own `products-query` caps at 100 too). */
	private const CATALOGUE_MAX_PER_PAGE = 100;

	/** Most products `missing_description` scans: description text cannot be filtered in the query. */
	private const CATALOGUE_MAX_SCAN = 2000;

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
		self::registerProductsCatalogue();
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
				'description'         => __( 'Create or update a shipping zone. Never deletes one. On an existing zone the locations given REPLACE its current ones, and the methods given are ADDED to its existing methods (none are removed or edited).', 'senroflux' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'name', 'locations', 'methods' ),
					'additionalProperties' => false,
					'properties'           => array(
						'zone_id'   => array( 'type' => 'integer' ),
						'name'      => array( 'type' => 'string' ),
						'locations' => array(
							'type'  => 'array',
							'items' => array(
								'type'                 => 'object',
								'required'             => array( 'code' ),
								'additionalProperties' => false,
								'properties'           => array(
									'code' => array(
										'type'        => 'string',
										'description' => __( 'Region code: "CA" (country), "CA:ON" (state), "NA" (continent) or a postcode such as "90210".', 'senroflux' ),
									),
									'type' => array(
										'type'        => 'string',
										'enum'        => ShippingZoneInput::LOCATION_TYPES,
										'description' => __( 'Defaults to country.', 'senroflux' ),
									),
								),
							),
						),
						'methods'   => array(
							'type'  => 'array',
							'items' => array(
								'type'                 => 'object',
								'required'             => array( 'method_id' ),
								'additionalProperties' => false,
								'properties'           => array(
									'method_id'  => array(
										'type' => 'string',
										'enum' => array_keys( self::ZONE_METHOD_FIELDS ),
									),
									'title'      => array( 'type' => 'string' ),
									'cost'       => array(
										'type'        => 'string',
										'description' => __( 'Decimal such as "12" or "4.50". Not for free_shipping.', 'senroflux' ),
									),
									'tax_status' => array(
										'type'        => 'string',
										'enum'        => array( 'taxable', 'none' ),
										'description' => __( 'Not for free_shipping.', 'senroflux' ),
									),
									'enabled'    => array( 'type' => 'boolean' ),
								),
							),
						),
					),
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'required'             => array( 'zone_id' ),
					'additionalProperties' => false,
					'properties'           => array(
						'zone_id'   => array( 'type' => 'integer' ),
						'previous'  => array( 'type' => array( 'object', 'null' ) ),
						'locations' => array(
							'type'  => 'array',
							'items' => array(
								'type'                 => 'object',
								'additionalProperties' => false,
								'properties'           => array(
									'code' => array( 'type' => 'string' ),
									'type' => array( 'type' => 'string' ),
								),
							),
						),
						'methods'   => array(
							'type'  => 'array',
							'items' => array(
								'type'                 => 'object',
								'additionalProperties' => false,
								'properties'           => array(
									'method_id'   => array( 'type' => 'string' ),
									'instance_id' => array( 'type' => 'integer' ),
									'title'       => array( 'type' => 'string' ),
									'cost'        => array( 'type' => 'string' ),
									'tax_status'  => array( 'type' => 'string' ),
									'enabled'     => array( 'type' => 'boolean' ),
								),
							),
						),
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
	 * `senroflux/products-catalogue` — upstream ask: a category filter and
	 * category/description fields on WooCommerce's `products-query` (live
	 * journeys J10/J11: the model could not read category assignments or tell
	 * which products lack a description, asked the human, and guessed).
	 * Read-only: products via `wc_get_products()` with their categories and
	 * whether each has description text, plus the category list with product
	 * counts when no product filter is given.
	 *
	 * PERMISSION DECISION: `manage_woocommerce` only — a read, the pack's run
	 * capability (WooCommerce's own `products-query` asks for the product
	 * read capability, which every holder of this one also has).
	 */
	private static function registerProductsCatalogue(): void {
		$category = array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(
				'id'   => array( 'type' => 'integer' ),
				'name' => array( 'type' => 'string' ),
				'slug' => array( 'type' => 'string' ),
			),
		);

		wp_register_ability(
			'senroflux/products-catalogue',
			array(
				'label'               => __( 'Products catalogue', 'senroflux' ),
				'description'         => __( 'A read-only product listing that shows each product\'s categories and whether it has a description (with a short excerpt), filterable by category and by missing description. Without a filter it also returns the category list with product counts.', 'senroflux' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => array(
						'category'            => array(
							'type'        => 'string',
							'description' => 'Only products in this product category: its slug or its name, case-insensitive.',
						),
						'missing_description' => array(
							'type'        => 'boolean',
							'description' => 'Only products whose description has no text.',
						),
						'search'              => array( 'type' => 'string' ),
						'page'                => array(
							'type'    => 'integer',
							'default' => 1,
							'minimum' => 1,
						),
						'per_page'            => array(
							'type'    => 'integer',
							'default' => self::CATALOGUE_DEFAULT_PER_PAGE,
							'minimum' => 1,
							'maximum' => self::CATALOGUE_MAX_PER_PAGE,
						),
					),
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'required'             => array( 'products', 'total', 'total_pages', 'page', 'per_page' ),
					'additionalProperties' => false,
					'properties'           => array(
						'products'    => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'id'                  => array( 'type' => 'integer' ),
									'name'                => array( 'type' => 'string' ),
									'sku'                 => array( 'type' => 'string' ),
									'status'              => array( 'type' => 'string' ),
									'type'                => array( 'type' => 'string' ),
									'regular_price'       => array( 'type' => 'string' ),
									'sale_price'          => array( 'type' => 'string' ),
									'stock_status'        => array( 'type' => 'string' ),
									'categories'          => array(
										'type'  => 'array',
										'items' => $category,
									),
									'has_description'     => array( 'type' => 'boolean' ),
									'description_excerpt' => array( 'type' => 'string' ),
									'has_short_description' => array( 'type' => 'boolean' ),
								),
							),
						),
						'total'       => array( 'type' => 'integer' ),
						'total_pages' => array( 'type' => 'integer' ),
						'page'        => array( 'type' => 'integer' ),
						'per_page'    => array( 'type' => 'integer' ),
						'categories'  => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'id'    => array( 'type' => 'integer' ),
									'name'  => array( 'type' => 'string' ),
									'slug'  => array( 'type' => 'string' ),
									'count' => array( 'type' => 'integer' ),
								),
							),
						),
					),
				),
				'execute_callback'    => static function ( $input = array() ) {
					return self::executeProductsCatalogue( is_array( $input ) ? $input : array() );
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
	 * plus the `page` post type's own `create_posts` and `publish_posts`
	 * capabilities (read from the type object, not hardcoded) — creating any
	 * page, private or not, is a content action outside WooCommerce's own
	 * capability set, and a site may remap the page capabilities.
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

					return self::mayCreateReportPage();
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
	 * Everything is validated before anything is persisted; a refusal leaves
	 * the store untouched. A new zone is saved before its locations are set
	 * because `WC_Shipping_Zone::add_location()` ignores a zone with no id
	 * yet (the REST controller saves first for the same reason).
	 *
	 * @param array<string,mixed> $input Call input.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function executeShippingZoneSave( array $input ): array|WP_Error {
		$zone_id = (int) ( $input['zone_id'] ?? 0 );
		$name    = is_string( $input['name'] ?? null ) ? trim( $input['name'] ) : '';

		if ( '' === $name ) {
			return new WP_Error( 'invalid_input', __( 'A zone name is required.', 'senroflux' ), array( 'status' => 400 ) );
		}
		if ( ! class_exists( '\WC_Shipping_Zone' ) ) {
			return new WP_Error( 'gateway_unavailable', __( 'Shipping zones are not available.', 'senroflux' ), array( 'status' => 400 ) );
		}

		$locations = self::resolveZoneLocations( is_array( $input['locations'] ?? null ) ? $input['locations'] : array() );
		if ( is_wp_error( $locations ) ) {
			return $locations;
		}
		$methods = self::resolveZoneMethods( is_array( $input['methods'] ?? null ) ? $input['methods'] : array() );
		if ( is_wp_error( $methods ) ) {
			return $methods;
		}

		$previous = null;
		if ( $zone_id > 0 ) {
			$zone = new \WC_Shipping_Zone( $zone_id );
			if ( 0 === $zone->get_id() ) {
				return new WP_Error( 'not_found', __( 'Shipping zone not found.', 'senroflux' ), array( 'status' => 400 ) );
			}
			$previous = array(
				'name'      => $zone->get_zone_name(),
				'locations' => $zone->get_zone_locations(),
				'methods'   => $zone->get_shipping_methods(),
			);
		} else {
			$zone = new \WC_Shipping_Zone();
		}

		$zone->set_zone_name( $name );
		if ( 0 === $zone->get_id() ) {
			$zone->save();
		}
		$zone->set_locations( $locations );
		$saved_id = (int) $zone->save();

		$added = array();
		foreach ( $methods as $method ) {
			$result = self::addZoneMethod( $zone, $method );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$added[] = $result;
		}

		$saved_locations = array();
		foreach ( $zone->get_zone_locations() as $location ) {
			$location = (array) $location;
			if ( is_string( $location['code'] ?? null ) && is_string( $location['type'] ?? null ) ) {
				$saved_locations[] = array(
					'code' => $location['code'],
					'type' => $location['type'],
				);
			}
		}

		return array(
			'zone_id'   => $saved_id,
			'previous'  => $previous,
			'locations' => $saved_locations,
			'methods'   => $added,
		);
	}

	/**
	 * @param array<mixed> $locations Raw `locations` input.
	 * @return list<array{code:string,type:string}>|WP_Error
	 */
	private static function resolveZoneLocations( array $locations ): array|WP_Error {
		$resolved  = array();
		$countries = self::wcCountries();

		foreach ( $locations as $location ) {
			if ( ! is_array( $location ) ) {
				return new WP_Error( 'invalid_input', __( 'Each location must be an object with a code.', 'senroflux' ), array( 'status' => 400 ) );
			}
			$extra = array_diff( array_keys( $location ), array( 'code', 'type' ) );
			if ( array() !== $extra ) {
				return new WP_Error(
					'invalid_input',
					sprintf(
						/* translators: %s: unexpected location field names. */
						__( 'Unknown location field: %s. A location takes only code and type.', 'senroflux' ),
						implode( ', ', array_map( 'strval', $extra ) )
					),
					array( 'status' => 400 )
				);
			}

			$parsed = ShippingZoneInput::location( $location );
			if ( is_wp_error( $parsed ) ) {
				return $parsed;
			}
			if ( null !== $countries && ! self::zoneLocationExists( $countries, $parsed ) ) {
				return new WP_Error(
					'unknown_location',
					sprintf(
						/* translators: 1: location type, 2: location code. */
						__( 'WooCommerce has no %1$s "%2$s".', 'senroflux' ),
						$parsed['type'],
						$parsed['code']
					),
					array( 'status' => 400 )
				);
			}
			$resolved[] = $parsed;
		}

		return $resolved;
	}

	/**
	 * `WC()->countries`, or null when WooCommerce cannot say which regions
	 * exist (the check is then skipped rather than refusing everything).
	 */
	private static function wcCountries(): ?object {
		if ( ! function_exists( 'WC' ) ) {
			return null;
		}
		$wc = \WC();
		if ( ! is_object( $wc ) || ! isset( $wc->countries ) || ! is_object( $wc->countries ) || ! method_exists( $wc->countries, 'get_countries' ) ) {
			return null;
		}

		return array() === $wc->countries->get_countries() ? null : $wc->countries;
	}

	/**
	 * @param array{code:string,type:string} $location Parsed location.
	 */
	private static function zoneLocationExists( object $countries, array $location ): bool {
		$code = $location['code'];

		if ( 'continent' === $location['type'] ) {
			$continents = method_exists( $countries, 'get_continents' ) ? $countries->get_continents() : null;

			return ! is_array( $continents ) || isset( $continents[ $code ] );
		}
		if ( 'country' === $location['type'] ) {
			$known = method_exists( $countries, 'get_countries' ) ? $countries->get_countries() : array();

			return is_array( $known ) && isset( $known[ $code ] );
		}
		if ( 'state' === $location['type'] ) {
			list( $country, $state ) = explode( ':', $code, 2 );
			$states                  = method_exists( $countries, 'get_states' ) ? $countries->get_states( $country ) : null;

			return is_array( $states ) && isset( $states[ $state ] );
		}

		return true;
	}

	/**
	 * @param array<mixed> $methods Raw `methods` input.
	 * @return list<array{method_id:string,title:?string,cost:?string,tax_status:?string,enabled:?bool}>|WP_Error
	 */
	private static function resolveZoneMethods( array $methods ): array|WP_Error {
		$resolved = array();
		$known    = array() === $methods ? array() : self::registeredShippingMethods();

		foreach ( $methods as $method ) {
			if ( ! is_array( $method ) ) {
				return new WP_Error( 'invalid_input', __( 'Each method must be an object with a method_id.', 'senroflux' ), array( 'status' => 400 ) );
			}
			$extra = array_diff( array_keys( $method ), array( 'method_id', 'title', 'cost', 'tax_status', 'enabled' ) );
			if ( array() !== $extra ) {
				return new WP_Error(
					'invalid_input',
					sprintf(
						/* translators: %s: unexpected method field names. */
						__( 'Unknown method field: %s. A method takes method_id, title, cost, tax_status and enabled.', 'senroflux' ),
						implode( ', ', array_map( 'strval', $extra ) )
					),
					array( 'status' => 400 )
				);
			}

			$method_id = $method['method_id'] ?? null;
			if ( ! is_string( $method_id ) || ! isset( self::ZONE_METHOD_FIELDS[ $method_id ] ) || ! in_array( $method_id, $known, true ) ) {
				return new WP_Error(
					'invalid_input',
					sprintf(
						/* translators: %s: comma-separated shipping method ids. */
						__( 'method_id must be one of: %s.', 'senroflux' ),
						implode( ', ', array_intersect( array_keys( self::ZONE_METHOD_FIELDS ), $known ) )
					),
					array( 'status' => 400 )
				);
			}

			$settings = array();
			foreach ( array( 'title', 'cost', 'tax_status' ) as $field ) {
				if ( ! array_key_exists( $field, $method ) ) {
					continue;
				}
				$value = $method[ $field ];
				if ( ! is_string( $value ) ) {
					return new WP_Error( 'invalid_input', sprintf( /* translators: %s: field name. */ __( 'Method %s must be a string.', 'senroflux' ), $field ), array( 'status' => 400 ) );
				}
				if ( 'title' !== $field && ! in_array( $field, self::ZONE_METHOD_FIELDS[ $method_id ], true ) ) {
					return new WP_Error(
						'invalid_input',
						sprintf(
							/* translators: 1: method field name, 2: shipping method id. */
							__( '%1$s cannot be set on %2$s.', 'senroflux' ),
							$field,
							$method_id
						),
						array( 'status' => 400 )
					);
				}
				if ( 'cost' === $field && 1 !== preg_match( '/^\d+(\.\d+)?$/', $value ) ) {
					return new WP_Error( 'invalid_input', __( 'Method cost must be a plain decimal such as "12" or "4.50".', 'senroflux' ), array( 'status' => 400 ) );
				}
				if ( 'tax_status' === $field && ! in_array( $value, array( 'taxable', 'none' ), true ) ) {
					return new WP_Error( 'invalid_input', __( 'Method tax_status must be "taxable" or "none".', 'senroflux' ), array( 'status' => 400 ) );
				}
				if ( 'title' === $field ) {
					$value = sanitize_text_field( $value );
					if ( '' === $value ) {
						return new WP_Error( 'invalid_input', __( 'A method title cannot be empty.', 'senroflux' ), array( 'status' => 400 ) );
					}
				}
				$settings[ $field ] = $value;
			}

			$enabled = null;
			if ( array_key_exists( 'enabled', $method ) ) {
				$enabled = filter_var( $method['enabled'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
				if ( null === $enabled ) {
					return new WP_Error( 'invalid_input', __( 'Method enabled must be true or false.', 'senroflux' ), array( 'status' => 400 ) );
				}
			}

			$resolved[] = array(
				'method_id'  => $method_id,
				'title'      => $settings['title'] ?? null,
				'cost'       => $settings['cost'] ?? null,
				'tax_status' => $settings['tax_status'] ?? null,
				'enabled'    => $enabled,
			);
		}

		return $resolved;
	}

	/**
	 * Shipping method ids WooCommerce has registered.
	 *
	 * @return list<string>
	 */
	private static function registeredShippingMethods(): array {
		if ( ! function_exists( 'WC' ) ) {
			return array();
		}
		$wc = \WC();
		if ( ! is_object( $wc ) || ! method_exists( $wc, 'shipping' ) ) {
			return array();
		}
		$shipping = $wc->shipping();

		return is_object( $shipping ) && method_exists( $shipping, 'get_shipping_method_class_names' )
			? array_map( 'strval', array_keys( (array) $shipping->get_shipping_method_class_names() ) )
			: array();
	}

	/**
	 * Add one method to the zone and apply its settings the way WooCommerce's
	 * `WC_REST_Shipping_Zone_Methods_V2_Controller::create_item()` and
	 * `update_fields()` do: instance settings option, then `is_enabled`.
	 *
	 * @param array{method_id:string,title:?string,cost:?string,tax_status:?string,enabled:?bool} $method Validated method.
	 * @return array<string,mixed>|WP_Error What was saved.
	 */
	private static function addZoneMethod( \WC_Shipping_Zone $zone, array $method ): array|WP_Error {
		global $wpdb;

		$instance_id = (int) $zone->add_shipping_method( $method['method_id'] );
		$instance    = $instance_id > 0 ? \WC_Shipping_Zones::get_shipping_method( $instance_id ) : false;
		if ( ! $instance instanceof \WC_Shipping_Method ) {
			return new WP_Error(
				'method_not_added',
				sprintf(
					/* translators: %s: shipping method id. */
					__( 'WooCommerce could not add the %s method to the zone.', 'senroflux' ),
					$method['method_id']
				),
				array( 'status' => 500 )
			);
		}

		$instance->init_instance_settings();
		$settings = (array) $instance->instance_settings;
		foreach ( array( 'title', 'cost', 'tax_status' ) as $field ) {
			if ( null !== $method[ $field ] ) {
				$settings[ $field ] = $method[ $field ];
			}
		}
		update_option(
			$instance->get_instance_option_key(),
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own per-method settings filter, applied as its REST controller does.
			apply_filters( 'woocommerce_shipping_' . $instance->id . '_instance_settings_values', $settings, $instance )
		);

		$enabled = $method['enabled'] ?? true;
		if ( ! $enabled ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- WooCommerce has no API to disable a zone method; its own REST shipping-zone-methods controller does this same update.
			$wpdb->update( "{$wpdb->prefix}woocommerce_shipping_zone_methods", array( 'is_enabled' => 0 ), array( 'instance_id' => $instance_id ) );
		}

		$saved = array(
			'method_id'   => $method['method_id'],
			'instance_id' => $instance_id,
			'title'       => is_string( $settings['title'] ?? null ) ? $settings['title'] : '',
			'cost'        => is_scalar( $settings['cost'] ?? null ) ? (string) $settings['cost'] : '',
			'enabled'     => $enabled,
		);
		if ( is_string( $settings['tax_status'] ?? null ) ) {
			$saved['tax_status'] = $settings['tax_status'];
		}

		return $saved;
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
	private static function executeProductsCatalogue( array $input ): array|WP_Error {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return new WP_Error( 'woocommerce_unavailable', __( 'Products are not available.', 'senroflux' ), array( 'status' => 400 ) );
		}

		$page     = max( 1, is_numeric( $input['page'] ?? null ) ? (int) $input['page'] : 1 );
		$per_page = is_numeric( $input['per_page'] ?? null ) ? (int) $input['per_page'] : self::CATALOGUE_DEFAULT_PER_PAGE;
		$per_page = min( self::CATALOGUE_MAX_PER_PAGE, max( 1, $per_page ) );
		$wanted   = is_string( $input['category'] ?? null ) ? trim( $input['category'] ) : '';
		$search   = is_string( $input['search'] ?? null ) ? trim( $input['search'] ) : '';
		$missing  = true === ( $input['missing_description'] ?? false );

		$categories = self::productCategories();
		$args       = array(
			'return'  => 'objects',
			'orderby' => 'id',
			'order'   => 'ASC',
		);

		if ( '' !== $wanted ) {
			$slug = self::categorySlug( $categories, $wanted );
			if ( null === $slug ) {
				return new WP_Error(
					'unknown_category',
					sprintf(
						/* translators: %s: comma-separated list of product category slugs. */
						__( 'No product category matches that. Known category slugs: %s', 'senroflux' ),
						implode( ', ', array_column( $categories, 'slug' ) )
					),
					array( 'status' => 400 )
				);
			}
			$args['category'] = array( $slug );
		}

		if ( '' !== $search ) {
			$args['s'] = $search;
		}

		if ( $missing ) {
			// Description text is not queryable: scan, filter, then page.
			$args['limit'] = self::CATALOGUE_MAX_SCAN;
			$matches       = array();
			foreach ( (array) wc_get_products( $args ) as $product ) {
				if ( is_object( $product ) && '' === self::descriptionText( $product ) ) {
					$matches[] = $product;
				}
			}
			$total    = count( $matches );
			$products = array_slice( $matches, ( $page - 1 ) * $per_page, $per_page );
		} else {
			$args['limit']    = $per_page;
			$args['page']     = $page;
			$args['paginate'] = true;
			$results          = wc_get_products( $args );
			$products         = is_object( $results ) && isset( $results->products ) ? (array) $results->products : array();
			$total            = is_object( $results ) && isset( $results->total ) ? (int) $results->total : count( $products );
		}

		$by_id = array();
		foreach ( $categories as $category ) {
			$by_id[ $category['id'] ] = array(
				'id'   => $category['id'],
				'name' => $category['name'],
				'slug' => $category['slug'],
			);
		}

		$rows = array();
		foreach ( $products as $product ) {
			if ( is_object( $product ) ) {
				$rows[] = self::catalogueRow( $product, $by_id );
			}
		}

		$result = array(
			'products'    => $rows,
			'total'       => $total,
			'total_pages' => (int) ceil( $total / $per_page ),
			'page'        => $page,
			'per_page'    => $per_page,
		);

		if ( '' === $wanted && '' === $search && ! $missing ) {
			$result['categories'] = $categories;
		}

		return $result;
	}

	/**
	 * Every product category, with its product count (WooCommerce's own term
	 * count: published products only).
	 *
	 * @return list<array{id:int,name:string,slug:string,count:int}>
	 */
	private static function productCategories(): array {
		if ( ! function_exists( 'get_terms' ) ) {
			return array();
		}

		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			)
		);

		$categories = array();
		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			// Cast: a duck-typed term (WP_Term in WordPress, a plain object in tests).
			$row = is_object( $term ) ? get_object_vars( $term ) : array();
			if ( ! isset( $row['term_id'], $row['name'], $row['slug'] ) ) {
				continue;
			}
			$categories[] = array(
				'id'    => (int) $row['term_id'],
				'name'  => html_entity_decode( (string) $row['name'], ENT_QUOTES ),
				'slug'  => (string) $row['slug'],
				'count' => (int) ( $row['count'] ?? 0 ),
			);
		}

		return $categories;
	}

	/**
	 * The slug of the category a model-supplied slug or name points at
	 * (case-insensitive), or null when none does.
	 *
	 * @param list<array{id:int,name:string,slug:string,count:int}> $categories {@see productCategories()}.
	 */
	private static function categorySlug( array $categories, string $wanted ): ?string {
		$needle = mb_strtolower( $wanted );
		$titled = function_exists( 'sanitize_title' ) ? sanitize_title( $wanted ) : $needle;

		foreach ( $categories as $category ) {
			if ( mb_strtolower( $category['slug'] ) === $needle || mb_strtolower( $category['name'] ) === $needle ) {
				return $category['slug'];
			}
		}
		foreach ( $categories as $category ) {
			if ( $category['slug'] === $titled ) {
				return $category['slug'];
			}
		}

		return null;
	}

	/**
	 * A product's description as plain text, whitespace-collapsed ('' when it
	 * has none; markup with no text counts as none).
	 */
	private static function descriptionText( object $product ): string {
		$html = method_exists( $product, 'get_description' ) ? (string) $product->get_description() : '';
		$text = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $html ) : strip_tags( $html ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags

		return trim( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( $text, ENT_QUOTES ) ) );
	}

	/**
	 * @param array<int,array{id:int,name:string,slug:string}> $category_index Categories by term id.
	 * @return array<string,mixed>
	 */
	private static function catalogueRow( object $product, array $category_index ): array {
		$text         = self::descriptionText( $product );
		$category_ids = method_exists( $product, 'get_category_ids' ) ? (array) $product->get_category_ids() : array();
		$categories   = array();
		foreach ( $category_ids as $category_id ) {
			if ( isset( $category_index[ (int) $category_id ] ) ) {
				$categories[] = $category_index[ (int) $category_id ];
			}
		}

		$short = method_exists( $product, 'get_short_description' ) ? (string) $product->get_short_description() : '';
		$short = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $short ) : strip_tags( $short ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags

		$read = static fn ( string $method ): string => method_exists( $product, $method ) ? (string) $product->$method() : '';

		return array(
			'id'                    => method_exists( $product, 'get_id' ) ? (int) $product->get_id() : 0,
			'name'                  => $read( 'get_name' ),
			'sku'                   => $read( 'get_sku' ),
			'status'                => $read( 'get_status' ),
			'type'                  => $read( 'get_type' ),
			'regular_price'         => $read( 'get_regular_price' ),
			'sale_price'            => $read( 'get_sale_price' ),
			'stock_status'          => $read( 'get_stock_status' ),
			'categories'            => $categories,
			'has_description'       => '' !== $text,
			'description_excerpt'   => mb_strlen( $text ) > 160 ? rtrim( mb_substr( $text, 0, 160 ) ) . '…' : $text,
			'has_short_description' => '' !== trim( $short ),
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

		$currency = function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : 'USD';

		return array(
			'from'               => $from,
			'to'                 => $to,
			'order_count'        => $order_count,
			'gross_sales'        => round( $gross, 2 ),
			'net_sales'          => round( $gross - $refunds_total, 2 ),
			'refunds_total'      => round( $refunds_total, 2 ),
			'currency'           => $currency,
			'low_stock_products' => self::lowStockProductIds(),
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

	/**
	 * The save-store-report gate: the store capability plus the page post
	 * type's create and publish capabilities (a private page is still a
	 * publish-tier write). Falls back to the core defaults when the type
	 * object is unavailable, never to "allowed".
	 */
	private static function mayCreateReportPage(): bool {
		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_woocommerce' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- a real WooCommerce capability; unknown only to phpcs's core capability list.
			return false;
		}

		$create  = 'edit_pages';
		$publish = 'publish_pages';
		if ( function_exists( 'get_post_type_object' ) ) {
			$object = get_post_type_object( 'page' );
			if ( is_object( $object ) && isset( $object->cap->create_posts, $object->cap->publish_posts ) ) {
				$create  = (string) $object->cap->create_posts;
				$publish = (string) $object->cap->publish_posts;
			}
		}

		return current_user_can( $create ) && current_user_can( $publish );
	}

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
	 * Product ids at or below their own low-stock threshold. Read-only (S19
	 * `store-report`): only ever calls `wc_get_products()`, never a writer.
	 *
	 * @return list<int>
	 */
	private static function lowStockProductIds(): array {
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
			// WooCommerce's own threshold: the product-level amount, else the store setting.
			$threshold = function_exists( 'wc_get_low_stock_amount' ) ? (int) wc_get_low_stock_amount( $product ) : 2;
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
