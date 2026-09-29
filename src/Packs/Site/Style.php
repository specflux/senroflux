<?php
/**
 * The style-variation registrar (0.3 quality feature 5):
 * `senroflux/read-style` (Tier 0) and `senroflux/set-style` (Tier 2, one
 * write).
 *
 * TARGET REPO PATH: src/Packs/Site/Style.php
 *
 * Modelled directly on {@see FrontPage}: a singleton object (`OBJECT_ID`),
 * the same S8 stale-write discipline (a marker recorded at `read-style` time,
 * compared at `set-style` time, fail closed on a mismatch or an unread
 * style), and the same after-write re-record so a run may keep editing
 * without re-reading.
 *
 * WHAT "THE CURRENT VARIATION" MEANS. WordPress core does not itself track
 * which named style variation (of `WP_Theme_JSON_Resolver::get_style_variations()`)
 * was last applied — picking one in the Site Editor simply MERGES that
 * variation's `settings`/`styles` into the user's global styles post
 * (`wp_global_styles`); no "active slug" survives anywhere else. This
 * registrar therefore keeps its OWN record of the slug it last applied, in
 * that post's own postmeta ({@see VARIATION_META}), defaulting to
 * `DEFAULT_SLUG` ("the theme's own defaults, no variation applied") when
 * nothing has been recorded yet — exactly the same shape of problem
 * `Navigation`/`FrontPage` do not have (a real WordPress option already names
 * their state) but resolved the same defensive way: recorded, never guessed.
 *
 * NOT SPOT-CHECKED AGAINST A LIVE WordPress (documented per this stage's
 * verification rule): `WP_Theme_JSON_Resolver::get_style_variations()` and
 * `get_user_global_styles_post_id()` are real core APIs (WP 6.2+, see the
 * class docblocks in `wp-includes/class-wp-theme-json-resolver.php`), and the
 * write below mirrors `WP_REST_Global_Styles_Controller::update_item()`'s own
 * persistence (a `{version, isGlobalStylesUserThemeJSON, settings, styles}`
 * JSON blob as the post's `post_content`) — but this plugin's test suite only
 * runs against PHP stubs of those APIs (`tests/stubs/style.php`), never a
 * real WordPress install.
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
 * Registers the two style-variation abilities.
 */
final class Style {

	/** The capability that gates both abilities. */
	private const CAPABILITY = 'edit_theme_options';

	/**
	 * The synthetic tracker id the run's `objects_json` map records the style
	 * marker under (mirrors {@see FrontPage::OBJECT_ID}) — there is exactly
	 * one active style variation in play per run.
	 */
	public const OBJECT_ID = 'site-style';

	/**
	 * The postmeta key this registrar's own "which variation is active"
	 * record lives under, on the user global styles post.
	 */
	private const VARIATION_META = '_senroflux_style_variation';

	/** The slug reported when no variation has ever been recorded. */
	private const DEFAULT_SLUG = 'default';

	/** Whether {@see register()} has run for this request. */
	private static bool $registered = false;

	/** The ticking run's id, or null outside one (S8). */
	private static ?int $current_run_id = null;

	/** The store used to read/persist the current run's `objects_json` (S8). */
	private static ?RunStore $store = null;

	/**
	 * Wire the ability registration hook (call once, from the composition
	 * root). The category is registered by {@see Navigation::registerCategory()}
	 * (shared `senroflux-site` category) — boot() here only needs the
	 * ability-init hook.
	 */
	public static function boot(): void {
		if ( ! function_exists( 'add_action' ) ) {
			return;
		}

		add_action( 'wp_abilities_api_init', array( self::class, 'register' ) );
	}

	/** Forget the per-request registered flag (test-only). */
	public static function reset(): void {
		self::$registered = false;
	}

	/**
	 * Enter the run context for one tick (0.3 S8): mirrors
	 * {@see FrontPage::useRunContext()} — set by the composition root from
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
	 * @param string $marker The style's current marker.
	 */
	private static function recordReadMarker( string $marker ): void {
		if ( null === self::$current_run_id || null === self::$store ) {
			return;
		}

		$objects = Tracker::recordRead( self::currentObjects(), self::OBJECT_ID, $marker );
		self::$store->updateRun( self::$current_run_id, array( 'objects_json' => $objects ) );
	}

	/**
	 * Whether a `set-style` write must be refused as a stale write (0.3 S8).
	 * Fails CLOSED (true, i.e. refuse) with no run context.
	 *
	 * @param string $current_marker The style's CURRENT marker, read fresh.
	 */
	private static function isStaleWrite( string $current_marker ): bool {
		if ( null === self::$current_run_id || null === self::$store ) {
			return true;
		}

		return Tracker::staleWrite( self::currentObjects(), self::OBJECT_ID, $current_marker );
	}

	/**
	 * A hash of the active slug plus the global styles post's own content —
	 * any external Site Editor style edit (even one that leaves the recorded
	 * slug alone) invalidates it, exactly like `FrontPage::marker()` hashing
	 * every watched option.
	 *
	 * @param array<string,mixed> $state {@see self::currentState()}'s shape.
	 */
	private static function marker( array $state ): string {
		return md5( ( $state['slug'] ?? '' ) . '|' . ( $state['content_hash'] ?? '' ) );
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

		self::registerReadStyle();
		self::registerSetStyle();
	}

	/**
	 * senroflux/read-style — Tier 0.
	 */
	private static function registerReadStyle(): void {
		wp_register_ability(
			'senroflux/read-style',
			array(
				'label'               => __( 'Read style variation', 'senroflux' ),
				'description'         => __( 'Read the active block theme\'s current style variation and every variation available to switch to.', 'senroflux' ),
				'category'            => Navigation::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'default'              => array(),
					'properties'           => array(),
				),
				'output_schema'       => self::styleOutputSchema(),
				'execute_callback'    => static function () {
					return self::executeReadStyle();
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
	 * senroflux/set-style — Tier 2, one write.
	 */
	private static function registerSetStyle(): void {
		wp_register_ability(
			'senroflux/set-style',
			array(
				'label'               => __( 'Set style variation', 'senroflux' ),
				'description'         => __( 'Apply one of the active block theme\'s own style variations to the site\'s global styles.', 'senroflux' ),
				'category'            => Navigation::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'slug' ),
					'additionalProperties' => false,
					'properties'           => array(
						'slug' => array( 'type' => 'string' ),
					),
				),
				'output_schema'       => self::styleOutputSchema(),
				'execute_callback'    => static function ( $input = array() ) {
					return self::executeSetStyle( is_array( $input ) ? $input : array() );
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
	private static function styleOutputSchema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'current', 'variations' ),
			'additionalProperties' => false,
			'properties'           => array(
				'current'    => array(
					'type'       => 'object',
					'properties' => array(
						'slug'  => array( 'type' => 'string' ),
						'title' => array( 'type' => 'string' ),
					),
				),
				'variations' => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'slug'        => array( 'type' => 'string' ),
							'title'       => array( 'type' => 'string' ),
							'description' => array( 'type' => 'string' ),
						),
					),
				),
			),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function executeReadStyle(): array {
		$state = self::currentState();
		self::recordReadMarker( self::marker( $state ) );

		return self::payload( $state );
	}

	/**
	 * @param array<string,mixed> $input Call input.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function executeSetStyle( array $input ): array|WP_Error {
		// 0.3 S8: fail closed on a mismatch or an unread style, checked
		// against the state as it stands RIGHT NOW — an external Site Editor
		// style change between the read and this call must be caught.
		if ( self::isStaleWrite( self::marker( self::currentState() ) ) ) {
			return new WP_Error(
				'stale_write',
				__( 'The style variation changed since this run last read it. Re-read it before writing again.', 'senroflux' ),
				array( 'status' => 409 )
			);
		}

		if ( ! self::isBlockTheme() ) {
			return new WP_Error(
				'not_block_theme',
				__( 'The active theme is not a block theme; it has no style variations to switch between.', 'senroflux' ),
				array( 'status' => 400 )
			);
		}

		$slug = (string) ( $input['slug'] ?? '' );
		if ( '' === $slug ) {
			return new WP_Error(
				'style_bad_request',
				__( 'slug is required.', 'senroflux' ),
				array( 'status' => 400 )
			);
		}

		$variation = self::findVariation( $slug );
		if ( null === $variation ) {
			return new WP_Error(
				'not_found',
				__( 'That style variation does not exist.', 'senroflux' ),
				array( 'status' => 400 )
			);
		}

		self::applyVariation( $slug, $variation );

		$state = self::currentState();

		// The write's own after-write re-record, so this run may keep
		// editing without re-reading (same discipline as FrontPage).
		self::recordReadMarker( self::marker( $state ) );

		return self::payload( $state );
	}

	/**
	 * Merge one variation's `settings`/`styles` into the user global styles
	 * post, the same shape `WP_REST_Global_Styles_Controller::update_item()`
	 * persists, then record which slug this registrar just applied (core
	 * itself keeps no such record — see the class docblock).
	 *
	 * @param string               $slug      The variation's slug.
	 * @param array<string,mixed>  $variation {@see self::findVariation()}'s shape.
	 */
	private static function applyVariation( string $slug, array $variation ): void {
		if ( ! class_exists( '\WP_Theme_JSON_Resolver' ) ) {
			return;
		}

		$post_id = (int) \WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
		if ( 0 === $post_id || ! function_exists( 'wp_update_post' ) ) {
			return;
		}

		$config = array(
			'version'                     => 3,
			'isGlobalStylesUserThemeJSON' => true,
			'settings'                    => $variation['settings'] ?? array(),
			'styles'                      => $variation['styles'] ?? array(),
		);

		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => (string) wp_json_encode( $config ),
			)
		);

		if ( function_exists( 'update_post_meta' ) ) {
			update_post_meta( $post_id, self::VARIATION_META, $slug );
		}
	}

	/**
	 * @return bool Whether the active theme is a block theme.
	 */
	private static function isBlockTheme(): bool {
		return function_exists( 'wp_is_block_theme' ) && wp_is_block_theme();
	}

	/**
	 * @param string $slug The requested slug.
	 * @return array<string,mixed>|null The variation, or null when unknown.
	 */
	private static function findVariation( string $slug ): ?array {
		foreach ( self::availableVariations() as $variation ) {
			if ( ( $variation['slug'] ?? '' ) === $slug ) {
				return $variation;
			}
		}

		return null;
	}

	/**
	 * The active block theme's own style variations (0.3 quality feature 5):
	 * `WP_Theme_JSON_Resolver::get_style_variations()`'s raw entries, each
	 * given a `slug` (core includes one on recent WP versions; this derives
	 * one from the title when absent, so the pack never depends on that) and
	 * a short description built from any palette/typography it ships.
	 *
	 * @return list<array<string,mixed>>
	 */
	private static function availableVariations(): array {
		if ( ! self::isBlockTheme() || ! class_exists( '\WP_Theme_JSON_Resolver' ) ) {
			return array();
		}

		$raw  = (array) \WP_Theme_JSON_Resolver::get_style_variations();
		$out  = array();
		$seen = array();
		foreach ( $raw as $variation ) {
			if ( ! is_array( $variation ) ) {
				continue;
			}

			$title = (string) ( $variation['title'] ?? '' );
			$slug  = (string) ( $variation['slug'] ?? ( function_exists( 'sanitize_title' ) ? sanitize_title( $title ) : strtolower( $title ) ) );
			if ( '' === $slug || isset( $seen[ $slug ] ) ) {
				continue;
			}
			$seen[ $slug ] = true;

			$out[] = array(
				'slug'        => $slug,
				'title'       => '' !== $title ? $title : $slug,
				'description' => self::describeVariation( $variation ),
				'settings'    => $variation['settings'] ?? array(),
				'styles'      => $variation['styles'] ?? array(),
			);
		}

		return $out;
	}

	/**
	 * A short, cheap description of a variation's palette/typography — never
	 * more than what the variation's own `settings` already state.
	 *
	 * @param array<string,mixed> $variation One raw `get_style_variations()` entry.
	 */
	private static function describeVariation( array $variation ): string {
		$parts    = array();
		$palette  = $variation['settings']['color']['palette']['theme'] ?? $variation['settings']['color']['palette']['default'] ?? array();
		$families = $variation['settings']['typography']['fontFamilies']['theme'] ?? $variation['settings']['typography']['fontFamilies']['default'] ?? array();

		if ( is_array( $palette ) && array() !== $palette ) {
			$parts[] = sprintf(
				/* translators: %d: number of colours in the palette. */
				__( '%d colours', 'senroflux' ),
				count( $palette )
			);
		}
		if ( is_array( $families ) && array() !== $families ) {
			$names = array_values(
				array_filter(
					array_map(
						static fn ( $family ): string => is_array( $family ) ? (string) ( $family['name'] ?? '' ) : '',
						$families
					)
				)
			);
			if ( array() !== $names ) {
				$parts[] = implode( ' + ', array_slice( $names, 0, 2 ) );
			}
		}

		return implode( ', ', $parts );
	}

	/**
	 * @return array<string,mixed> {slug, title, content_hash}.
	 */
	private static function currentState(): array {
		$slug         = self::DEFAULT_SLUG;
		$content_hash = '';

		if ( class_exists( '\WP_Theme_JSON_Resolver' ) && function_exists( 'get_post' ) ) {
			$post_id = (int) \WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
			if ( $post_id > 0 ) {
				$recorded = function_exists( 'get_post_meta' ) ? get_post_meta( $post_id, self::VARIATION_META, true ) : '';
				if ( is_string( $recorded ) && '' !== $recorded ) {
					$slug = $recorded;
				}

				$post = get_post( $post_id );
				if ( null !== $post && isset( $post->post_content ) ) {
					$content_hash = md5( (string) $post->post_content );
				}
			}
		}

		$title = $slug;
		foreach ( self::availableVariations() as $variation ) {
			if ( ( $variation['slug'] ?? '' ) === $slug ) {
				$title = (string) $variation['title'];
				break;
			}
		}
		if ( self::DEFAULT_SLUG === $slug ) {
			$title = __( 'Default', 'senroflux' );
		}

		return array(
			'slug'         => $slug,
			'title'        => $title,
			'content_hash' => $content_hash,
		);
	}

	/**
	 * @param array<string,mixed> $state {@see self::currentState()}'s shape.
	 * @return array<string,mixed>
	 */
	private static function payload( array $state ): array {
		$variations = array_map(
			static fn ( array $variation ): array => array(
				'slug'        => $variation['slug'],
				'title'       => $variation['title'],
				'description' => $variation['description'],
			),
			self::availableVariations()
		);

		return array(
			'current'    => array(
				'slug'  => $state['slug'],
				'title' => $state['title'],
			),
			'variations' => $variations,
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

	/**
	 * S12-style report lookup for {@see OBJECT_ID}, wired through the
	 * composition root's `$post_lookup` dispatcher (Plugin.php) so a
	 * `set-style` write resolves to a real "Site style" row instead of
	 * falling back to the default post lookup's "unknown".
	 *
	 * @return array{object_type:string,title:string,status:string,edit_url:?string,preview_url:?string}
	 */
	public static function reportLookup(): array {
		return array(
			'object_type' => 'setting',
			'title'       => __( 'Site style', 'senroflux' ),
			'status'      => '',
			'edit_url'    => function_exists( 'admin_url' ) ? admin_url( 'site-editor.php?path=%2Fwp_global_styles' ) : null,
			'preview_url' => null,
		);
	}
}
