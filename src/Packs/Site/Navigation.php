<?php
/**
 * The site navigation registrar (0.3 S7): `senroflux/read-navigation` (Tier
 * 0) and `senroflux/update-navigation` (ALWAYS Tier 2).
 *
 * TARGET REPO PATH: src/Packs/Site/Navigation.php
 *
 * ONE ITEM-LIST SHAPE, either world. A block theme's header Navigation
 * block's `ref` (never assumed — a navigation block with no `ref` falls
 * through to `WP_Navigation_Fallback::get_fallback()`, exactly like core's
 * own resolution) or, with none found there either, the fallback resolver's
 * navigation post; a classic theme's `nav_menu` assigned to the first
 * REGISTERED location that has an assignment `[assumed — S7]`. The model
 * never learns which world it is in — both resolve to `{ kind, items }`,
 * where `kind` is `'items'` or `'page_list'` (a `core/page-list` block).
 *
 * NO CREATE, NO ASSIGN-LOCATION, NO DELETE (S7) — `update-navigation`
 * replaces the resolved navigation's ITEM LIST only; it never creates a new
 * navigation/menu, changes which one a location points to, or removes the
 * navigation itself.
 *
 * ORDERING (S7): `update-navigation` refuses `navigation_links_unpublished`
 * (409) when any linked page is not `publish` at write time — the pack-level
 * enforcement of "the navigation write comes after the publish batch".
 *
 * STALE WRITE (S8), same discipline as `Packs\Content\Abilities`: a marker is
 * recorded on the run's tracker at `read-navigation` time (a `wp_navigation`
 * post's `post_modified_gmt`, or a hash of a classic menu's items) and
 * compared at `update-navigation` time, fail closed on a mismatch or an
 * unread navigation. Scoped to one tick via {@see useRunContext()}, mirroring
 * `Packs\Content\Abilities::useRunContext()`.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Packs\Site;

use Specflux\SenroFlux\Run\RunStore;
use Specflux\SenroFlux\Run\Tracker;
use WP_Error;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Registers the two site-navigation abilities and their resolution logic.
 */
final class Navigation {

	/**
	 * The ability category for the site abilities (shared with FrontPage).
	 */
	public const CATEGORY = 'senroflux-site';

	/**
	 * The capability that gates both abilities. Editing either world's
	 * navigation is core's own `edit_theme_options` gate.
	 */
	private const CAPABILITY = 'edit_theme_options';

	/**
	 * The synthetic tracker id the run's `objects_json` map records the
	 * navigation's marker under (0.3 S8) — there is exactly one navigation in
	 * play per run, so a fixed string key (never a model-supplied argument)
	 * is enough.
	 */
	public const OBJECT_ID = 'site-navigation';

	/** Whether {@see register()} has run for this request. */
	private static bool $registered = false;

	/** The ticking run's id, or null outside one (S8). */
	private static ?int $current_run_id = null;

	/** The store used to read/persist the current run's `objects_json` (S8). */
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

		// S7 quality fix (2026-09-28): the site pack's own propose-plan
		// check ({@see filterPlanError()}) — a no-op for any plan that never
		// names a site-pack page-create/publish verb.
		if ( function_exists( 'add_filter' ) ) {
			add_filter( 'senroflux_plan_error', array( self::class, 'filterPlanError' ), 10, 2 );
		}
	}

	/**
	 * Forget the per-request registered flag (test-only; mirrors
	 * `Content\Abilities::reset()`).
	 */
	public static function reset(): void {
		self::$registered = false;
	}

	/**
	 * Enter the run context for one tick (0.3 S8): mirrors
	 * `Content\Abilities::useRunContext()` — set by the composition root from
	 * the ticking run's id, NEVER from the model.
	 *
	 * @param int|null      $run_id The ticking run's id, or null.
	 * @param RunStore|null $store  The store backing that run, or null.
	 */
	public static function useRunContext( ?int $run_id, ?RunStore $store ): void {
		self::$current_run_id = $run_id;
		self::$store          = $store;
	}

	/** Leave the run context (mirrors {@see useRunContext()}). */
	public static function forgetRunContext(): void {
		self::$current_run_id = null;
		self::$store          = null;
	}

	/**
	 * The current run's `objects_json` map, or an empty set with no run
	 * context resolved (fail closed).
	 *
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
	 * Record the navigation's modified marker on the current run's tracker
	 * (0.3 S8). A no-op with no run context resolved.
	 *
	 * @param string $marker The navigation's current marker.
	 */
	private static function recordReadMarker( string $marker ): void {
		if ( null === self::$current_run_id || null === self::$store ) {
			return;
		}

		$objects = Tracker::recordRead( self::currentObjects(), self::OBJECT_ID, $marker );
		self::$store->updateRun( self::$current_run_id, array( 'objects_json' => $objects ) );
	}

	/**
	 * Whether an `update-navigation` write must be refused as a stale write
	 * (0.3 S8). Fails CLOSED (true, i.e. refuse) with no run context.
	 *
	 * @param string $current_marker The navigation's CURRENT marker, read fresh.
	 */
	private static function isStaleWrite( string $current_marker ): bool {
		if ( null === self::$current_run_id || null === self::$store ) {
			return true;
		}

		return Tracker::staleWrite( self::currentObjects(), self::OBJECT_ID, $current_marker );
	}

	/**
	 * Stage 14 (AS-15/S19 approval cards): the resolved navigation's CURRENT
	 * item list, for {@see \Specflux\SenroFlux\Packs\Site\ContentSummary} to
	 * render beside an `update-navigation` call's proposed list. Read-only —
	 * unlike {@see executeReadNavigation()} this never records a read marker,
	 * because rendering an approval card is not the run "reading" the
	 * navigation (S8's stale-write discipline is untouched by it).
	 *
	 * @return array{kind:string, items:list<array<string,mixed>>}
	 */
	public static function currentItemsForSummary(): array {
		$target  = self::resolveTarget();
		$payload = self::buildPayload( $target );
		if ( is_wp_error( $payload ) ) {
			return array(
				'kind'  => 'items',
				'items' => array(),
			);
		}

		return array(
			'kind'  => $payload['kind'],
			'items' => $payload['items'],
		);
	}

	/**
	 * Register the category (must run before `wp_abilities_api_init`).
	 */
	public static function registerCategory(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'SenroFlux site', 'senroflux' ),
				'description' => __( 'Site navigation and front-page abilities shared by SenroFlux capability packs.', 'senroflux' ),
			)
		);
	}

	/**
	 * Register the two abilities. Idempotent per request.
	 */
	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		self::registerReadNavigation();
		self::registerUpdateNavigation();
	}

	/**
	 * senroflux/read-navigation — Tier 0, `{} => {kind, items}`.
	 */
	private static function registerReadNavigation(): void {
		wp_register_ability(
			'senroflux/read-navigation',
			array(
				'label'               => __( 'Read site navigation', 'senroflux' ),
				'description'         => __( 'Read the resolved site navigation\'s current items.', 'senroflux' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'default'              => array(),
					'properties'           => array(),
				),
				'output_schema'       => self::navigationOutputSchema(),
				'execute_callback'    => static function () {
					return self::executeReadNavigation();
				},
				'permission_callback' => static function () {
					return current_user_can( self::CAPABILITY );
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
	 * senroflux/update-navigation — ALWAYS Tier 2, replaces the resolved
	 * navigation's item list.
	 */
	private static function registerUpdateNavigation(): void {
		wp_register_ability(
			'senroflux/update-navigation',
			array(
				'label'               => __( 'Update site navigation', 'senroflux' ),
				'description'         => __( 'Replace the resolved site navigation\'s items (label, target and order). Refuses a link to an unpublished page.', 'senroflux' ),
				'category'            => self::CATEGORY,
				'input_schema'        => self::updateNavigationSchema(),
				'output_schema'       => self::navigationOutputSchema(),
				'execute_callback'    => static function ( $input = array() ) {
					return self::executeUpdateNavigation( is_array( $input ) ? $input : array() );
				},
				'permission_callback' => static function () {
					return current_user_can( self::CAPABILITY );
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
	 * @return array<string,mixed>
	 */
	private static function navigationOutputSchema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'kind', 'items' ),
			'additionalProperties' => false,
			'properties'           => array(
				'kind'                        => array(
					'type' => 'string',
					'enum' => array( 'items', 'page_list' ),
				),
				'items'                       => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'label'   => array( 'type' => 'string' ),
							'url'     => array( 'type' => 'string' ),
							'page_id' => array( 'type' => array( 'integer', 'null' ) ),
							'order'   => array( 'type' => 'integer' ),
						),
					),
				),
				'multiple_locations_assigned' => array( 'type' => 'boolean' ),
				// S7 quality fix (2026-09-28): a hint, never a write — the id of
				// the WordPress stock Sample Page when it is STILL published,
				// null otherwise, so the model can see the `page_list`-fallback
				// problem {@see \Specflux\SenroFlux\Packs\Site\SitePack}'s own
				// guidance now names (see `structureRulesBody()`).
				'stock_sample_page'           => array( 'type' => array( 'integer', 'null' ) ),
			),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function updateNavigationSchema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'items' ),
			'additionalProperties' => false,
			'properties'           => array(
				'items' => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'required'             => array( 'label', 'order' ),
						'additionalProperties' => false,
						'properties'           => array(
							'label'   => array( 'type' => 'string' ),
							'url'     => array( 'type' => 'string' ),
							'page_id' => array( 'type' => 'integer' ),
							'order'   => array( 'type' => 'integer' ),
						),
					),
				),
			),
		);
	}

	// ------------------------------------------------------------------
	// Resolution (S7)
	// ------------------------------------------------------------------

	/**
	 * Resolve which navigation this call targets, in EITHER world.
	 *
	 * @return array{world:string, ref:int|null, menu_id:int|null, multiple_locations_assigned:bool}
	 */
	private static function resolveTarget(): array {
		if ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) {
			$ref = self::headerNavigationRef();
			if ( null === $ref ) {
				// Never assume a ref: resolve through the same fallback core uses.
				$ref = self::fallbackNavigationId();
			}

			return array(
				'world'                       => 'block',
				'ref'                         => $ref,
				'menu_id'                     => null,
				'multiple_locations_assigned' => false,
			);
		}

		$registered = function_exists( 'get_registered_nav_menus' ) ? get_registered_nav_menus() : array();
		$locations  = function_exists( 'get_nav_menu_locations' ) ? get_nav_menu_locations() : array();
		$assigned   = array();
		foreach ( array_keys( (array) $registered ) as $location ) {
			if ( ! empty( $locations[ $location ] ) ) {
				$assigned[] = (string) $location;
			}
		}

		$menu_id = array() !== $assigned ? (int) $locations[ $assigned[0] ] : null;

		return array(
			'world'                       => 'classic',
			'ref'                         => null,
			'menu_id'                     => $menu_id,
			'multiple_locations_assigned' => count( $assigned ) > 1,
		);
	}

	/**
	 * The `ref` of the header template part's Navigation block, or null when
	 * no header template part exists, or its Navigation block carries no
	 * `ref` at all — either case falls through to the fallback resolver.
	 */
	private static function headerNavigationRef(): ?int {
		if ( ! function_exists( 'get_block_template' ) || ! function_exists( 'get_stylesheet' ) || ! function_exists( 'parse_blocks' ) ) {
			return null;
		}

		$template = get_block_template( get_stylesheet() . '//header', 'wp_template_part' );
		if ( ! is_object( $template ) || ! property_exists( $template, 'content' ) ) {
			return null;
		}

		$content = $template->content;
		if ( ! is_string( $content ) ) {
			return null;
		}

		return self::findNavigationRef( array_values( parse_blocks( $content ) ) );
	}

	/**
	 * Depth-first search for the first `core/navigation` block's `ref`.
	 *
	 * @param list<array<string,mixed>> $blocks Parsed blocks.
	 */
	private static function findNavigationRef( array $blocks ): ?int {
		foreach ( $blocks as $block ) {
			if ( 'core/navigation' === ( $block['blockName'] ?? '' ) ) {
				$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
				if ( isset( $attrs['ref'] ) && is_numeric( $attrs['ref'] ) ) {
					return (int) $attrs['ref'];
				}
				continue; // No ref on THIS block: keep looking, then fall back.
			}
			$inner = $block['innerBlocks'] ?? array();
			if ( is_array( $inner ) && array() !== $inner ) {
				$found = self::findNavigationRef( array_values( $inner ) );
				if ( null !== $found ) {
					return $found;
				}
			}
		}

		return null;
	}

	/**
	 * `WP_Navigation_Fallback::get_fallback()` — the same resolution core
	 * uses when no ref is found on the header's Navigation block.
	 */
	private static function fallbackNavigationId(): ?int {
		if ( ! class_exists( 'WP_Navigation_Fallback' ) ) {
			return null;
		}

		$fallback = \WP_Navigation_Fallback::get_fallback();
		if ( ! is_object( $fallback ) || ! property_exists( $fallback, 'ID' ) ) {
			return null;
		}

		return is_numeric( $fallback->ID ) ? (int) $fallback->ID : null;
	}

	// ------------------------------------------------------------------
	// Payload building
	// ------------------------------------------------------------------

	/**
	 * @param array{world:string, ref:int|null, menu_id:int|null, multiple_locations_assigned:bool} $target Resolved target.
	 * @return array{kind:string, items:list<array<string,mixed>>, marker:string}|WP_Error
	 */
	private static function buildPayload( array $target ): array|WP_Error {
		if ( 'block' === $target['world'] ) {
			if ( null === $target['ref'] ) {
				return self::navigationUnresolved();
			}

			$post = function_exists( 'get_post' ) ? get_post( $target['ref'] ) : null;
			if ( null === $post ) {
				return self::navigationUnresolved();
			}

			$content = (string) ( $post->post_content ?? '' );
			$blocks  = function_exists( 'parse_blocks' ) ? array_values( parse_blocks( $content ) ) : array();
			$marker  = (string) ( $post->post_modified_gmt ?? '' );

			if ( self::containsPageList( $blocks ) ) {
				return array(
					'kind'   => 'page_list',
					'items'  => array(),
					'marker' => $marker,
				);
			}

			return array(
				'kind'   => 'items',
				'items'  => self::itemsFromBlockNav( $blocks ),
				'marker' => $marker,
			);
		}

		if ( null === $target['menu_id'] ) {
			return array(
				'kind'   => 'items',
				'items'  => array(),
				'marker' => self::classicMarker( array() ),
			);
		}

		$menu_items = function_exists( 'wp_get_nav_menu_items' ) ? wp_get_nav_menu_items( $target['menu_id'] ) : array();
		$menu_items = array_values( is_array( $menu_items ) ? $menu_items : array() );

		return array(
			'kind'   => 'items',
			'items'  => self::itemsFromClassicMenu( $menu_items ),
			'marker' => self::classicMarker( $menu_items ),
		);
	}

	/**
	 * Depth-first search for a `core/page-list` block.
	 *
	 * @param list<array<string,mixed>> $blocks Parsed blocks.
	 */
	private static function containsPageList( array $blocks ): bool {
		foreach ( $blocks as $block ) {
			if ( 'core/page-list' === ( $block['blockName'] ?? '' ) ) {
				return true;
			}
			$inner = $block['innerBlocks'] ?? array();
			if ( is_array( $inner ) && array() !== $inner && self::containsPageList( array_values( $inner ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The top-level `core/navigation-link` blocks as `{label,url,page_id,order}`.
	 *
	 * @param list<array<string,mixed>> $blocks Parsed blocks (the navigation's own content).
	 * @return list<array<string,mixed>>
	 */
	private static function itemsFromBlockNav( array $blocks ): array {
		$items = array();
		$order = 0;
		foreach ( $blocks as $block ) {
			if ( 'core/navigation-link' !== ( $block['blockName'] ?? '' ) ) {
				continue;
			}
			$attrs   = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
			$is_page = 'post-type' === ( $attrs['kind'] ?? '' ) && 'page' === ( $attrs['type'] ?? '' ) && isset( $attrs['id'] );

			$items[] = array(
				'label'   => (string) ( $attrs['label'] ?? '' ),
				'url'     => (string) ( $attrs['url'] ?? '' ),
				'page_id' => $is_page ? (int) $attrs['id'] : null,
				'order'   => $order,
			);
			++$order;
		}

		return $items;
	}

	/**
	 * The classic menu's items as `{label,url,page_id,order}`.
	 *
	 * @param list<object> $menu_items `wp_get_nav_menu_items()` result.
	 * @return list<array<string,mixed>>
	 */
	private static function itemsFromClassicMenu( array $menu_items ): array {
		$items = array();
		foreach ( $menu_items as $item ) {
			if ( ! is_object( $item ) ) {
				continue;
			}
			$is_page = 'page' === ( $item->object ?? '' );
			$items[] = array(
				'label'   => (string) ( $item->title ?? '' ),
				'url'     => (string) ( $item->url ?? '' ),
				'page_id' => $is_page ? (int) ( $item->object_id ?? 0 ) : null,
				'order'   => (int) ( $item->menu_order ?? 0 ),
			);
		}

		return $items;
	}

	/**
	 * A hash of a classic menu's items (0.3 S8 marker) — id, title, url,
	 * object id and order, so any of those changing invalidates it.
	 *
	 * @param list<object> $menu_items `wp_get_nav_menu_items()` result.
	 */
	private static function classicMarker( array $menu_items ): string {
		$parts = array();
		foreach ( $menu_items as $item ) {
			if ( ! is_object( $item ) ) {
				continue;
			}
			$parts[] = implode(
				'|',
				array(
					(string) ( $item->ID ?? '' ),
					(string) ( $item->title ?? '' ),
					(string) ( $item->url ?? '' ),
					(string) ( $item->object_id ?? '' ),
					(string) ( $item->menu_order ?? '' ),
				)
			);
		}

		return md5( implode( ';', $parts ) );
	}

	// ------------------------------------------------------------------
	// Execute callbacks
	// ------------------------------------------------------------------

	/**
	 * @return array<string,mixed>|WP_Error
	 */
	private static function executeReadNavigation(): array|WP_Error {
		$target  = self::resolveTarget();
		$payload = self::buildPayload( $target );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		self::recordReadMarker( (string) $payload['marker'] );

		return array(
			'kind'                        => $payload['kind'],
			'items'                       => $payload['items'],
			'multiple_locations_assigned' => $target['multiple_locations_assigned'],
			// S7 quality fix (2026-09-28): read-only hint, no write.
			'stock_sample_page'           => self::stockSamplePageId(),
		);
	}

	/**
	 * @param array<string,mixed> $input Call input.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function executeUpdateNavigation( array $input ): array|WP_Error {
		$items = is_array( $input['items'] ?? null ) ? $input['items'] : array();

		foreach ( $items as $item ) {
			$has_url = isset( $item['url'] ) && '' !== trim( (string) $item['url'] );
			if ( ! isset( $item['page_id'] ) && ! $has_url ) {
				return new WP_Error(
					'navigation_item_incomplete',
					__( 'Every navigation item needs a url or a page_id.', 'senroflux' ),
					array( 'status' => 400 )
				);
			}
		}

		// S7 ordering: refuse a link to a page that is not publish YET.
		foreach ( $items as $item ) {
			if ( ! isset( $item['page_id'] ) ) {
				continue;
			}
			$status = function_exists( 'get_post_status' ) ? get_post_status( (int) $item['page_id'] ) : false;
			if ( 'publish' !== $status ) {
				return new WP_Error(
					'navigation_links_unpublished',
					__( 'Every linked page must be published before the navigation can point to it.', 'senroflux' ),
					array(
						'status'  => 409,
						'page_id' => (int) $item['page_id'],
					)
				);
			}
		}

		$target  = self::resolveTarget();
		$payload = self::buildPayload( $target );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		// 0.3 S8: fail closed on a mismatch or an unread navigation.
		if ( self::isStaleWrite( (string) $payload['marker'] ) ) {
			return new WP_Error(
				'stale_write',
				__( 'The navigation changed since this run last read it. Re-read it before writing again.', 'senroflux' ),
				array( 'status' => 409 )
			);
		}

		usort( $items, static fn ( array $a, array $b ): int => ( (int) ( $a['order'] ?? 0 ) ) <=> ( (int) ( $b['order'] ?? 0 ) ) );

		$result = 'block' === $target['world']
			? self::writeBlockNavigation( $target['ref'], $items )
			: self::writeClassicMenu( $target['menu_id'], $items );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// The write's own after-write re-record, so this run may keep editing
		// without re-reading (same discipline as Content\Abilities).
		self::recordReadMarker( (string) $result['marker'] );

		return array(
			'kind'                        => 'items',
			'items'                       => $items,
			'multiple_locations_assigned' => $target['multiple_locations_assigned'],
		);
	}

	/**
	 * Persist the item list as `core/navigation-link` blocks on the resolved
	 * `wp_navigation` post (never as `core/page-list` — converting a Page
	 * List into explicit links is exactly what this write is for).
	 *
	 * @param int|null                   $ref   The wp_navigation post id.
	 * @param list<array<string,mixed>>  $items Sorted items.
	 * @return array{marker:string}|WP_Error
	 */
	private static function writeBlockNavigation( ?int $ref, array $items ): array|WP_Error {
		if ( null === $ref ) {
			return self::navigationUnresolved();
		}

		$content = '';
		foreach ( $items as $item ) {
			$attrs = array( 'label' => (string) ( $item['label'] ?? '' ) );
			if ( isset( $item['page_id'] ) ) {
				$attrs['id']   = (int) $item['page_id'];
				$attrs['kind'] = 'post-type';
				$attrs['type'] = 'page';
				$attrs['url']  = function_exists( 'get_permalink' ) ? (string) get_permalink( (int) $item['page_id'] ) : (string) ( $item['url'] ?? '' );
			} else {
				$attrs['url'] = (string) ( $item['url'] ?? '' );
			}

			$encoded  = function_exists( 'wp_json_encode' ) ? wp_json_encode( $attrs ) : json_encode( $attrs ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			$content .= '<!-- wp:navigation-link ' . (string) $encoded . ' /-->';
		}

		if ( ! function_exists( 'wp_update_post' ) ) {
			return self::navigationUnresolved();
		}

		$updated = wp_update_post(
			array(
				'ID'           => $ref,
				'post_content' => $content,
			),
			true
		);
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		$post = function_exists( 'get_post' ) ? get_post( $ref ) : null;

		return array( 'marker' => null !== $post ? (string) ( $post->post_modified_gmt ?? '' ) : '' );
	}

	/**
	 * Replace a classic menu's items wholesale: delete every existing item,
	 * insert the new list in order. Still no create/assign/delete of the MENU
	 * or its location (S7) — only its item set changes.
	 *
	 * @param int|null                  $menu_id The nav_menu term id.
	 * @param list<array<string,mixed>> $items   Sorted items.
	 * @return array{marker:string}|WP_Error
	 */
	private static function writeClassicMenu( ?int $menu_id, array $items ): array|WP_Error {
		if ( null === $menu_id ) {
			return self::navigationUnresolved();
		}

		$existing = function_exists( 'wp_get_nav_menu_items' ) ? wp_get_nav_menu_items( $menu_id ) : array();
		foreach ( (array) $existing as $item ) {
			if ( is_object( $item ) && function_exists( 'wp_delete_post' ) ) {
				wp_delete_post( (int) ( $item->ID ?? 0 ), true );
			}
		}

		foreach ( $items as $item ) {
			$data = array(
				'menu-item-title'  => (string) ( $item['label'] ?? '' ),
				'menu-item-status' => 'publish',
			);
			if ( isset( $item['page_id'] ) ) {
				$data['menu-item-object-id'] = (int) $item['page_id'];
				$data['menu-item-object']    = 'page';
				$data['menu-item-type']      = 'post_type';
			} else {
				$data['menu-item-url']  = (string) ( $item['url'] ?? '' );
				$data['menu-item-type'] = 'custom';
			}

			if ( function_exists( 'wp_update_nav_menu_item' ) ) {
				wp_update_nav_menu_item( $menu_id, 0, $data );
			}
		}

		$new_items = function_exists( 'wp_get_nav_menu_items' ) ? wp_get_nav_menu_items( $menu_id ) : array();

		return array( 'marker' => self::classicMarker( array_values( is_array( $new_items ) ? $new_items : array() ) ) );
	}

	/**
	 * @return WP_Error navigation_unresolved (500 — nothing to resolve to,
	 *                   which is an install/theme gap, not the caller's fault).
	 */
	private static function navigationUnresolved(): WP_Error {
		return new WP_Error(
			'navigation_unresolved',
			__( 'No site navigation could be resolved.', 'senroflux' ),
			array( 'status' => 500 )
		);
	}

	/**
	 * @param array<string,mixed> $annotations Ability meta annotations.
	 * @return array<string,mixed>
	 */
	private static function meta( array $annotations ): array {
		return array(
			'annotations' => $annotations,
			'senroflux'   => array( 'hidden' => false ),
		);
	}

	// ------------------------------------------------------------------
	// S7 quality fix (2026-09-28): a site-pack run must not leave WordPress's
	// stock Sample Page visible in the navigation — GOVERNED, via a
	// propose-plan refusal, not a harness-side rewrite.
	//
	// A previous version of this fix ({@see Plugin::tick()}) rewrote the
	// navigation directly once a site-pack run completed — REJECTED: it
	// wrote outside the governed tool path (no plan, no Agent Safety
	// gate/audit) and silently converted a `page_list` fallback into fixed
	// links. {@see filterPlanError()} replaces it: it refuses the PLAN, on
	// the `senroflux_plan_error` filter ({@see boot()}), so the model fixes
	// its own plan through the ordinary approval path.
	// ------------------------------------------------------------------

	/**
	 * The WordPress-installer stock Sample Page's content, the distinctive
	 * substring `wp_install_defaults()` seeds every fresh install with.
	 */
	private const STOCK_SAMPLE_CONTENT_SIGNATURE = 'This is an example page';

	/**
	 * Site-pack pack verbs (S7 spelling, {@see SitePack::verbMap()}) that
	 * create or publish a page — a plan naming one of these, while the
	 * stock Sample Page is still published and visible in the resolved
	 * navigation, needs an `site/update-navigation` step too
	 * ({@see filterPlanError()}).
	 */
	private const PAGE_CREATE_OR_PUBLISH_VERBS = array( 'site/create-draft', 'site/publish' );

	/** The site-pack pack verb for `update-navigation` (S7 spelling). */
	private const UPDATE_NAVIGATION_VERB = 'site/update-navigation';

	/**
	 * S7 quality fix (2026-09-28): refuse a `senroflux/propose-plan` call
	 * that creates or publishes a page while the stock Sample Page is still
	 * published and the resolved navigation would show it — a `page_list`
	 * fallback (which renders every published page), or an explicit link to
	 * it — unless some step also names `site/update-navigation`.
	 *
	 * Registered on the `senroflux_plan_error` filter (see {@see boot()});
	 * PlanTools threads every registered pack's check through this one
	 * filter (it may not depend on `src/Packs`, see its own class docblock),
	 * so this is the SITE pack's own contribution, not site-specific logic
	 * living in PlanTools. A no-op for any plan that never names a
	 * site-pack page-create/publish verb — non-site-pack plans, and
	 * site-pack plans about anything else, are untouched.
	 *
	 * @param WP_Error|null                                         $current_error An earlier check's refusal (this run's OWN
	 *                                                                             `missingImageStepError()`, or another pack's
	 *                                                                             hook) — passed through unmodified; this check
	 *                                                                             never overrides an existing refusal.
	 * @param list<array{text:string,verbs:list<string>,tier:int}>  $steps         Normalized propose-plan steps.
	 * @return WP_Error|null
	 */
	public static function filterPlanError( ?WP_Error $current_error, array $steps ): ?WP_Error {
		if ( $current_error instanceof WP_Error ) {
			return $current_error;
		}

		$verbs = array();
		foreach ( $steps as $step ) {
			foreach ( (array) ( $step['verbs'] ?? array() ) as $verb ) {
				$verbs[] = $verb;
			}
		}

		$creates_or_publishes_page = array() !== array_intersect( self::PAGE_CREATE_OR_PUBLISH_VERBS, $verbs );
		if ( ! $creates_or_publishes_page || in_array( self::UPDATE_NAVIGATION_VERB, $verbs, true ) ) {
			return null;
		}

		$sample_id = self::stockSamplePageId();
		if ( null === $sample_id || ! self::navigationWouldShowSamplePage( $sample_id ) ) {
			return null;
		}

		return new WP_Error(
			'navigation_shows_sample_page',
			__( 'This site\'s menu still shows WordPress\'s sample page "Sample Page". Add update-navigation to a step and set the menu links to the site\'s real pages, leaving the sample page out, then propose the plan again.', 'senroflux' )
		);
	}

	/**
	 * Whether the CURRENTLY resolved navigation would show the given page —
	 * either it appears as an explicit link, or the navigation resolves to a
	 * `page_list` fallback (which renders every published page). Read-only:
	 * never records a read marker, mirroring {@see currentItemsForSummary()}.
	 * Fails closed toward "would not show it" only when the navigation
	 * itself cannot be resolved at all (nothing to warn about then).
	 */
	private static function navigationWouldShowSamplePage( int $sample_id ): bool {
		$target  = self::resolveTarget();
		$payload = self::buildPayload( $target );
		if ( is_wp_error( $payload ) ) {
			return false;
		}

		if ( 'page_list' === $payload['kind'] ) {
			return true;
		}

		foreach ( $payload['items'] as $item ) {
			if ( ( $item['page_id'] ?? null ) === $sample_id ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The id of the currently published WordPress stock Sample Page, or
	 * null when there is none — fails closed toward "leave it alone": a
	 * page merely sharing the slug/title but carrying its OWN content is
	 * never matched.
	 */
	private static function stockSamplePageId(): ?int {
		if ( ! function_exists( 'get_pages' ) ) {
			return null;
		}

		foreach ( (array) get_pages( array( 'post_status' => 'publish' ) ) as $candidate ) {
			if ( ! is_object( $candidate ) || 'sample-page' !== $candidate->post_name ) {
				continue;
			}
			if ( 'Sample Page' !== trim( $candidate->post_title ) ) {
				continue;
			}
			if ( false === stripos( $candidate->post_content, self::STOCK_SAMPLE_CONTENT_SIGNATURE ) ) {
				continue; // Edited/repurposed by the user: leave it alone.
			}

			return (int) $candidate->ID;
		}

		return null;
	}

	/**
	 * S12 (defect fix): the report lookup for {@see OBJECT_ID}, wired
	 * through the composition root's `$post_lookup` dispatcher (Plugin.php)
	 * so an `update-navigation` write resolves to a real "navigation" row
	 * instead of falling back to the default post lookup's "unknown". There
	 * is exactly one navigation object in play per run (S7), so this needs
	 * no argument.
	 *
	 * @return array{object_type:string,title:string,status:string,edit_url:?string,preview_url:?string}
	 */
	public static function reportLookup(): array {
		return array(
			'object_type' => 'navigation',
			'title'       => __( 'Site navigation', 'senroflux' ),
			'status'      => '',
			'edit_url'    => function_exists( 'admin_url' ) ? admin_url( 'site-editor.php?p=%2Fnavigation' ) : null,
			'preview_url' => null,
		);
	}
}
