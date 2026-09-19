<?php
/**
 * The commerce pack's own polyfill abilities (S19, stage 12 catalogue slice):
 * `set-product-image`, `coupon-create`, `coupon-enable`.
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
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Packs\Commerce;

use Specflux\SenroFlux\Run\RunStore;
use Specflux\SenroFlux\Run\Tracker;
use WP_Error;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Registers the three commerce polyfill abilities.
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
