<?php
/**
 * The front-page registrar (0.3 S7): `senroflux/read-front-page` (Tier 0)
 * and `senroflux/set-front-page` (Tier 2, one write).
 *
 * TARGET REPO PATH: src/Packs/Site/FrontPage.php
 *
 * Reads/writes the three options core's own Reading Settings screen uses
 * (`show_on_front`, `page_on_front`, `page_for_posts`) — nothing new. Setting
 * a static front page in the same call as a posts-page assignment is ONE
 * write (all three options land together); the OLD front page post itself is
 * never modified (S7 — only the option pointing at it changes).
 *
 * STALE WRITE (0.3 S8), same discipline as `Navigation`: a marker — a hash of
 * `show_on_front|page_on_front|page_for_posts` — is recorded on the run's
 * tracker at `read-front-page` time and compared at `set-front-page` time,
 * fail closed on a mismatch or an unread front page. Scoped to one tick via
 * {@see useRunContext()}, mirroring `Navigation::useRunContext()`.
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
 * Registers the two front-page abilities.
 */
final class FrontPage {

	/** The capability that gates both abilities. */
	private const CAPABILITY = 'manage_options';

	/**
	 * The synthetic tracker id the run's `objects_json` map records the
	 * front-page marker under (0.3 S8) — there is exactly one front-page
	 * settings triple in play per run, so a fixed string key (never a
	 * model-supplied argument) is enough.
	 */
	private const OBJECT_ID = 'site-front-page';

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
	 * `Navigation::useRunContext()` — set by the composition root from the
	 * ticking run's id, NEVER from the model.
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
	 * Record the front page's modified marker on the current run's tracker
	 * (0.3 S8). A no-op with no run context resolved.
	 *
	 * @param string $marker The front page's current marker.
	 */
	private static function recordReadMarker( string $marker ): void {
		if ( null === self::$current_run_id || null === self::$store ) {
			return;
		}

		$objects = Tracker::recordRead( self::currentObjects(), self::OBJECT_ID, $marker );
		self::$store->updateRun( self::$current_run_id, array( 'objects_json' => $objects ) );
	}

	/**
	 * Whether a `set-front-page` write must be refused as a stale write (0.3
	 * S8). Fails CLOSED (true, i.e. refuse) with no run context.
	 *
	 * @param string $current_marker The front page's CURRENT marker, read fresh.
	 */
	private static function isStaleWrite( string $current_marker ): bool {
		if ( null === self::$current_run_id || null === self::$store ) {
			return true;
		}

		return Tracker::staleWrite( self::currentObjects(), self::OBJECT_ID, $current_marker );
	}

	/**
	 * A hash of the three front-page options (0.3 S8 marker) — any of them
	 * changing (including an external Reading Settings edit) invalidates it.
	 *
	 * @param array<string,mixed> $settings {@see self::currentSettings()}'s shape.
	 */
	private static function marker( array $settings ): string {
		$page_on_front  = $settings['page_on_front']['id'] ?? 0;
		$page_for_posts = $settings['page_for_posts']['id'] ?? 0;

		return md5( $settings['show_on_front'] . '|' . $page_on_front . '|' . $page_for_posts );
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

		self::registerReadFrontPage();
		self::registerSetFrontPage();
	}

	/**
	 * senroflux/read-front-page — Tier 0.
	 */
	private static function registerReadFrontPage(): void {
		wp_register_ability(
			'senroflux/read-front-page',
			array(
				'label'               => __( 'Read front page settings', 'senroflux' ),
				'description'         => __( 'Read the current front-page and posts-page settings, with titles.', 'senroflux' ),
				'category'            => Navigation::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'default'              => array(),
					'properties'           => array(),
				),
				'output_schema'       => self::frontPageOutputSchema(),
				'execute_callback'    => static function () {
					return self::executeReadFrontPage();
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
	 * senroflux/set-front-page — Tier 2, one write.
	 */
	private static function registerSetFrontPage(): void {
		wp_register_ability(
			'senroflux/set-front-page',
			array(
				'label'               => __( 'Set front page', 'senroflux' ),
				'description'         => __( 'Set the site\'s front page (a static page or the latest posts), optionally setting the posts page in the same write.', 'senroflux' ),
				'category'            => Navigation::CATEGORY,
				'input_schema'        => self::setFrontPageSchema(),
				'output_schema'       => self::frontPageOutputSchema(),
				'execute_callback'    => static function ( $input = array() ) {
					return self::executeSetFrontPage( is_array( $input ) ? $input : array() );
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
	private static function frontPageOutputSchema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'show_on_front' ),
			'additionalProperties' => false,
			'properties'           => array(
				'show_on_front'  => array(
					'type' => 'string',
					'enum' => array( 'page', 'posts' ),
				),
				'page_on_front'  => self::namedPageSchema(),
				'page_for_posts' => self::namedPageSchema(),
			),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function namedPageSchema(): array {
		return array(
			'type'       => array( 'object', 'null' ),
			'properties' => array(
				'id'    => array( 'type' => 'integer' ),
				'title' => array( 'type' => 'string' ),
			),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function setFrontPageSchema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'show_on_front' ),
			'additionalProperties' => false,
			'properties'           => array(
				'show_on_front'     => array(
					'type' => 'string',
					'enum' => array( 'page', 'posts' ),
				),
				'page_id'           => array( 'type' => 'integer' ),
				'page_for_posts_id' => array( 'type' => 'integer' ),
			),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function executeReadFrontPage(): array {
		$settings = self::currentSettings();
		self::recordReadMarker( self::marker( $settings ) );

		return $settings;
	}

	/**
	 * @param array<string,mixed> $input Call input.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function executeSetFrontPage( array $input ): array|WP_Error {
		// 0.3 S8: fail closed on a mismatch or an unread front page. Compared
		// against the settings as they stand RIGHT NOW, before this write —
		// an external Reading Settings change between the read and this call
		// must be caught.
		if ( self::isStaleWrite( self::marker( self::currentSettings() ) ) ) {
			return new WP_Error(
				'stale_write',
				__( 'The front page settings changed since this run last read them. Re-read them before writing again.', 'senroflux' ),
				array( 'status' => 409 )
			);
		}

		$show_on_front = (string) ( $input['show_on_front'] ?? '' );
		if ( ! in_array( $show_on_front, array( 'page', 'posts' ), true ) ) {
			return new WP_Error(
				'front_page_bad_request',
				__( 'show_on_front must be "page" or "posts".', 'senroflux' ),
				array( 'status' => 400 )
			);
		}

		if ( 'page' === $show_on_front ) {
			if ( ! isset( $input['page_id'] ) || ! is_numeric( $input['page_id'] ) ) {
				return new WP_Error(
					'front_page_bad_request',
					__( 'page_id is required when show_on_front is "page".', 'senroflux' ),
					array( 'status' => 400 )
				);
			}

			$page_id = (int) $input['page_id'];
			$post    = function_exists( 'get_post' ) ? get_post( $page_id ) : null;
			if ( null === $post || 'page' !== ( $post->post_type ?? '' ) ) {
				return new WP_Error(
					'not_found',
					__( 'Page not found.', 'senroflux' ),
					array( 'status' => 400 )
				);
			}

			// ONE write: show_on_front, page_on_front and (when given, S7:
			// "sets page_for_posts in the same write") page_for_posts all land
			// together. The OLD front page's post is never touched.
			update_option( 'show_on_front', 'page' );
			update_option( 'page_on_front', $page_id );

			if ( isset( $input['page_for_posts_id'] ) && is_numeric( $input['page_for_posts_id'] ) ) {
				$posts_page_id = (int) $input['page_for_posts_id'];
				$posts_page    = function_exists( 'get_post' ) ? get_post( $posts_page_id ) : null;
				if ( null === $posts_page || 'page' !== ( $posts_page->post_type ?? '' ) ) {
					return new WP_Error(
						'not_found',
						__( 'Posts page not found.', 'senroflux' ),
						array( 'status' => 400 )
					);
				}
				update_option( 'page_for_posts', $posts_page_id );
			}
		} else {
			update_option( 'show_on_front', 'posts' );
		}

		$settings = self::currentSettings();

		// The write's own after-write re-record, so this run may keep editing
		// without re-reading (same discipline as Navigation).
		self::recordReadMarker( self::marker( $settings ) );

		return $settings;
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function currentSettings(): array {
		$show_on_front = function_exists( 'get_option' ) ? (string) get_option( 'show_on_front', 'posts' ) : 'posts';
		if ( ! in_array( $show_on_front, array( 'page', 'posts' ), true ) ) {
			$show_on_front = 'posts';
		}

		return array(
			'show_on_front'  => $show_on_front,
			'page_on_front'  => self::namedPage( 'page_on_front' ),
			'page_for_posts' => self::namedPage( 'page_for_posts' ),
		);
	}

	/**
	 * @param string $option The option name (`page_on_front` or `page_for_posts`).
	 * @return array{id:int,title:string}|null
	 */
	private static function namedPage( string $option ): ?array {
		$id = function_exists( 'get_option' ) ? (int) get_option( $option, 0 ) : 0;
		if ( 0 === $id ) {
			return null;
		}

		$title = function_exists( 'get_the_title' ) ? (string) get_the_title( $id ) : '';

		return array(
			'id'    => $id,
			'title' => $title,
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
}
