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
 * No stale-write check here (0.3 S8 names `read-navigation`/`update-navigation`
 * explicitly for the run-context marker mechanism; the front-page options are
 * a single small settings write with no vocabulary/markup race to protect
 * against, so this pack does not add one — documented deviation from S8's
 * more general "(and read-navigation, read-front-page)" phrasing).
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Packs\Site;

use WP_Error;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Registers the two front-page abilities.
 */
final class FrontPage {

	/** The capability that gates both abilities. */
	private const CAPABILITY = 'manage_options';

	/** Whether {@see register()} has run for this request. */
	private static bool $registered = false;

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
		return self::currentSettings();
	}

	/**
	 * @param array<string,mixed> $input Call input.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function executeSetFrontPage( array $input ): array|WP_Error {
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

		return self::currentSettings();
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
