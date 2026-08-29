<?php
/**
 * Registers the five pages-pack polyfill abilities (S10) on the Abilities API.
 *
 * TARGET REPO PATH: src/Packs/Pages/Abilities.php
 *
 * Categories are registered on `wp_abilities_api_categories_init` (the API
 * requires the category to exist BEFORE an ability references it — registry
 * returns null otherwise), then the five abilities on `wp_abilities_api_init`.
 * Both are guarded with `function_exists` so a bare-PHPUnit run (or a site
 * without the Abilities API) is a clean no-op.
 *
 * All WRITE abilities (`create-post`, `update-post`) route their `content`
 * through {@see Validator::clean()} BEFORE `wp_insert_post`/`wp_update_post`:
 * a validation failure returns the WP_Error whole-write — nothing is persisted.
 *
 * Every ability that returns an id follows the S12 contract (the run tracker
 * keys its verify-nudge on `id`).
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Packs\Pages;

use WP_Error;
use WP_Post;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Registers the five abilities for the pages pack.
 */
final class Abilities {

	/**
	 * The ability category for all five polyfills.
	 */
	public const CATEGORY = 'senroflux-pages-content';

	/**
	 * The default `fields` list for read-content (content_rendered is OFF).
	 *
	 * @var list<string>
	 */
	private const DEFAULT_FIELDS = array(
		'id',
		'post_type',
		'status',
		'date',
		'slug',
		'link',
		'title_raw',
		'title_rendered',
		'excerpt_raw',
		'excerpt_rendered',
		'content_raw',
		'author',
		'parent',
	);

	/**
	 * Whether {@see register()} has run for this request.
	 */
	private static bool $registered = false;

	/**
	 * Wire the category + ability registration hooks (call once, from the
	 * pack's own bootstrap).
	 */
	public static function boot(): void {
		if ( ! function_exists( 'add_action' ) ) {
			return;
		}

		add_action( 'wp_abilities_api_categories_init', array( self::class, 'registerCategory' ) );
		add_action( 'wp_abilities_api_init', array( self::class, 'register' ) );
	}

	/**
	 * Forget the per-request registered flag so {@see register()} can run again
	 * (test-only; mirrors `Plugin::reset()`).
	 */
	public static function reset(): void {
		self::$registered = false;
	}

	/**
	 * Register the category (must run before `wp_abilities_api_init`, or the
	 * abilities that reference it return null).
	 */
	public static function registerCategory(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'SenroFlux pages content', 'senroflux' ),
				'description' => __( 'Content abilities for the SenroFlux pages pack.', 'senroflux' ),
			)
		);
	}

	/**
	 * Register the five abilities. Idempotent per request (the API rejects a
	 * duplicate name anyway, but we avoid the duplicate-registration noise).
	 */
	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		$vocabulary = new Vocabulary();
		$validator  = new Validator( $vocabulary );

		self::registerReadContent();
		self::registerCreatePost( $validator );
		self::registerUpdatePost( $validator );
		self::registerGetPreviewUrl();
		self::registerListPatterns( $vocabulary );
	}

	/**
	 * senroflux/read-content — three-mode oneOf (by id / by slug / query).
	 */
	private static function registerReadContent(): void {
		wp_register_ability(
			'senroflux/read-content',
			array(
				'label'               => __( 'Read content', 'senroflux' ),
				'description'         => __( 'Read one page/post by id or slug, or query a list.', 'senroflux' ),
				'category'            => self::CATEGORY,
				'input_schema'        => self::readContentSchema(),
				'output_schema'       => self::readContentOutputSchema(),
				'execute_callback'    => static function ( $input = array() ) {
					return self::executeReadContent( is_array( $input ) ? $input : array() );
				},
				'permission_callback' => static function ( $input = array() ) {
					$input = is_array( $input ) ? $input : array();
					$pt    = $input['post_type'] ?? 'page';

					return current_user_can( self::postTypeCap( (string) $pt ) );
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
	 * senroflux/create-post — draft-only create; content validated before insert.
	 */
	private static function registerCreatePost( Validator $validator ): void {
		wp_register_ability(
			'senroflux/create-post',
			array(
				'label'               => __( 'Create post', 'senroflux' ),
				'description'         => __( 'Create a draft page or post from block markup.', 'senroflux' ),
				'category'            => self::CATEGORY,
				'input_schema'        => self::createPostSchema(),
				'output_schema'       => self::createPostOutputSchema(),
				'execute_callback'    => static function ( $input = array() ) use ( $validator ) {
					return self::executeCreatePost( is_array( $input ) ? $input : array(), $validator );
				},
				'permission_callback' => static function ( $input = array() ) {
					$input = is_array( $input ) ? $input : array();
					$pt    = $input['post_type'] ?? 'post';

					return current_user_can( self::postTypeCap( (string) $pt ) );
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
	 * senroflux/update-post — id + same fields; status draft|pending|publish;
	 * publish is a status transition on THIS ability.
	 */
	private static function registerUpdatePost( Validator $validator ): void {
		wp_register_ability(
			'senroflux/update-post',
			array(
				'label'               => __( 'Update post', 'senroflux' ),
				'description'         => __( 'Update a page or post; publishing is a status transition.', 'senroflux' ),
				'category'            => self::CATEGORY,
				'input_schema'        => self::updatePostSchema(),
				'output_schema'       => self::updatePostOutputSchema(),
				'execute_callback'    => static function ( $input = array() ) use ( $validator ) {
					return self::executeUpdatePost( is_array( $input ) ? $input : array(), $validator );
				},
				'permission_callback' => static function ( $input = array() ) {
					$input = is_array( $input ) ? $input : array();
					$post  = isset( $input['id'] ) && is_numeric( $input['id'] ) ? get_post( (int) $input['id'] ) : null;
					$pt    = is_object( $post ) ? $post->post_type : 'page';

					return current_user_can( self::postTypeCap( (string) $pt ) );
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
	 * senroflux/get-preview-url — {id} → {preview_url}.
	 */
	private static function registerGetPreviewUrl(): void {
		wp_register_ability(
			'senroflux/get-preview-url',
			array(
				'label'               => __( 'Get preview URL', 'senroflux' ),
				'description'         => __( 'Build the preview URL for a page or post.', 'senroflux' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'id' ),
					'additionalProperties' => false,
					'properties'           => array(
						'id' => array( 'type' => 'integer' ),
					),
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'required'             => array( 'preview_url' ),
					'additionalProperties' => false,
					'properties'           => array(
						'preview_url' => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => static function ( $input = array() ) {
					$input = is_array( $input ) ? $input : array();
					$url   = get_preview_post_link( (int) ( $input['id'] ?? 0 ) );

					return $url
						? array( 'preview_url' => $url )
						: new WP_Error( 'not_found', __( 'Post not found.', 'senroflux' ), array( 'status' => 400 ) );
				},
				'permission_callback' => static function ( $input = array() ) {
					$input = is_array( $input ) ? $input : array();

					return current_user_can( 'edit_post', (int) ( $input['id'] ?? 0 ) );
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
	 * senroflux/list-patterns — {} → {patterns: [...]} (S11).
	 */
	private static function registerListPatterns( Vocabulary $vocabulary ): void {
		wp_register_ability(
			'senroflux/list-patterns',
			array(
				'label'               => __( 'List patterns', 'senroflux' ),
				'description'         => __( 'List the available page patterns and their copy constraints.', 'senroflux' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'default'              => array(),
					'properties'           => array(),
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'required'             => array( 'patterns' ),
					'additionalProperties' => false,
					'properties'           => array(
						'patterns' => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'name'        => array( 'type' => 'string' ),
									'title'       => array( 'type' => 'string' ),
									'description' => array( 'type' => 'string' ),
									'constraints' => array(
										'type'       => 'object',
										'properties' => array(
											'slots'  => array( 'type' => 'object' ),
											'stated' => array(
												'type'  => 'array',
												'items' => array( 'type' => 'string' ),
											),
										),
									),
								),
							),
						),
					),
				),
				'execute_callback'    => static function () use ( $vocabulary ) {
					return $vocabulary->listPayload();
				},
				'permission_callback' => static fn (): bool => current_user_can( 'edit_pages' ),
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
	 * read-content input schema (three-mode oneOf, post_type page|post).
	 *
	 * @return array<string,mixed>
	 */
	private static function readContentSchema(): array {
		return array(
			'oneOf' => array(
				array(
					'type'                 => 'object',
					'required'             => array( 'id' ),
					'additionalProperties' => false,
					'properties'           => array(
						'id'        => array( 'type' => 'integer' ),
						'post_type' => array(
							'type' => 'string',
							'enum' => array( 'page', 'post' ),
						),
						'fields'    => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
					),
				),
				array(
					'type'                 => 'object',
					'required'             => array( 'post_type', 'slug' ),
					'additionalProperties' => false,
					'properties'           => array(
						'post_type' => array(
							'type' => 'string',
							'enum' => array( 'page', 'post' ),
						),
						'slug'      => array( 'type' => 'string' ),
						'fields'    => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
					),
				),
				array(
					'type'                 => 'object',
					'required'             => array( 'post_type' ),
					'additionalProperties' => false,
					'properties'           => array(
						'post_type' => array(
							'type' => 'string',
							'enum' => array( 'page', 'post' ),
						),
						'status'    => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'author'    => array( 'type' => 'integer' ),
						'parent'    => array( 'type' => 'integer' ),
						'include'   => array(
							'type'     => 'array',
							'items'    => array( 'type' => 'integer' ),
							'maxItems' => 100,
						),
						'fields'    => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'page'      => array( 'type' => 'integer' ),
						'per_page'  => array(
							'type'    => 'integer',
							'maximum' => 100,
						),
					),
				),
			),
		);
	}

	/**
	 * read-content output schema (single post vs list).
	 *
	 * @return array<string,mixed>
	 */
	private static function readContentOutputSchema(): array {
		return array(
			'oneOf' => array(
				array(
					'type'                 => 'object',
					'required'             => array( 'id', 'content_raw' ),
					'additionalProperties' => false,
					'properties'           => array(
						'id'                => array( 'type' => 'integer' ),
						'post_type'         => array( 'type' => 'string' ),
						'status'            => array( 'type' => 'string' ),
						'date'              => array( 'type' => 'string' ),
						'date_gmt'          => array( 'type' => 'string' ),
						'modified'          => array( 'type' => 'string' ),
						'modified_gmt'      => array( 'type' => 'string' ),
						'slug'              => array( 'type' => 'string' ),
						'link'              => array( 'type' => 'string' ),
						'title_raw'         => array( 'type' => 'string' ),
						'title_rendered'    => array( 'type' => 'string' ),
						'excerpt_raw'       => array( 'type' => 'string' ),
						'excerpt_rendered'  => array( 'type' => 'string' ),
						'excerpt_protected' => array( 'type' => 'boolean' ),
						'content_raw'       => array( 'type' => 'string' ),
						'content_rendered'  => array( 'type' => 'string' ),
						'content_protected' => array( 'type' => 'boolean' ),
						'author'            => array(
							'type'       => 'object',
							'properties' => array(
								'id'   => array( 'type' => 'integer' ),
								'name' => array( 'type' => 'string' ),
							),
						),
						'parent'            => array( 'type' => 'integer' ),
					),
				),
				array(
					'type'                 => 'object',
					'required'             => array( 'posts' ),
					'additionalProperties' => false,
					'properties'           => array(
						'posts'       => array(
							'type'  => 'array',
							'items' => array( 'type' => 'object' ),
						),
						'total'       => array( 'type' => 'integer' ),
						'total_pages' => array( 'type' => 'integer' ),
					),
				),
			),
		);
	}

	/**
	 * create-post input schema.
	 *
	 * @return array<string,mixed>
	 */
	private static function createPostSchema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'post_type', 'title' ),
			'additionalProperties' => false,
			'default'              => array(),
			'properties'           => array(
				'post_type' => array(
					'type' => 'string',
					'enum' => array( 'page', 'post' ),
				),
				'title'     => array( 'type' => 'string' ),
				'content'   => array( 'type' => 'string' ),
				'status'    => array(
					'type' => 'string',
					'enum' => array( 'draft' ),
				),
				'slug'      => array( 'type' => 'string' ),
				'parent'    => array( 'type' => 'integer' ),
				'excerpt'   => array( 'type' => 'string' ),
			),
		);
	}

	/**
	 * create-post output schema.
	 *
	 * @return array<string,mixed>
	 */
	private static function createPostOutputSchema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'id', 'status' ),
			'additionalProperties' => false,
			'properties'           => array(
				'id'     => array( 'type' => 'integer' ),
				'status' => array(
					'type' => 'string',
					'enum' => array( 'draft' ),
				),
			),
		);
	}

	/**
	 * update-post input schema (id + same fields; status draft|pending|publish).
	 *
	 * @return array<string,mixed>
	 */
	private static function updatePostSchema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'id' ),
			'additionalProperties' => false,
			'properties'           => array(
				'id'        => array( 'type' => 'integer' ),
				'post_type' => array(
					'type' => 'string',
					'enum' => array( 'page', 'post' ),
				),
				'title'     => array( 'type' => 'string' ),
				'content'   => array( 'type' => 'string' ),
				'status'    => array(
					'type' => 'string',
					'enum' => array( 'draft', 'pending', 'publish' ),
				),
				'slug'      => array( 'type' => 'string' ),
				'parent'    => array( 'type' => 'integer' ),
				'excerpt'   => array( 'type' => 'string' ),
			),
		);
	}

	/**
	 * update-post output schema.
	 *
	 * @return array<string,mixed>
	 */
	private static function updatePostOutputSchema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'id', 'status' ),
			'additionalProperties' => false,
			'properties'           => array(
				'id'     => array( 'type' => 'integer' ),
				'status' => array( 'type' => 'string' ),
			),
		);
	}

	/**
	 * The shared meta block: annotations + `meta.senroflux.hidden === false`
	 * (these abilities MUST be exposed as tools — the harness lists them).
	 *
	 * @param array<string,mixed> $annotations annotations.
	 * @return array<string,mixed>
	 */
	private static function meta( array $annotations ): array {
		return array(
			'annotations' => $annotations,
			'senroflux'   => array( 'hidden' => false ),
		);
	}

	/**
	 * The post-type capability (page → edit_pages, else edit_posts).
	 */
	private static function postTypeCap( string $post_type ): string {
		return 'page' === $post_type ? 'edit_pages' : 'edit_posts';
	}

	/**
	 * read-content execute: by id → a single post; by slug → a single post; else
	 * a WP_Query list. `content_rendered` only when requested.
	 *
	 * @param array<string,mixed> $input Call input.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function executeReadContent( array $input ): array|WP_Error {
		$fields = $input['fields'] ?? self::DEFAULT_FIELDS;

		if ( isset( $input['id'] ) ) {
			$post = function_exists( 'get_post' ) ? get_post( (int) $input['id'] ) : null;

			return is_object( $post )
				? self::shapePost( $post, $fields )
				: new WP_Error( 'not_found', __( 'Post not found.', 'senroflux' ), array( 'status' => 400 ) );
		}

		if ( isset( $input['slug'] ) && function_exists( 'get_page_by_path' ) ) {
			$post = get_page_by_path( (string) $input['slug'], 'OBJECT', (string) ( $input['post_type'] ?? 'page' ) );

			return is_object( $post )
				? self::shapePost( $post, $fields )
				: new WP_Error( 'not_found', __( 'Post not found.', 'senroflux' ), array( 'status' => 400 ) );
		}

		if ( ! class_exists( '\WP_Query' ) || ! function_exists( 'apply_filters' ) ) {
			return new WP_Error( 'not_found', __( 'Query unavailable.', 'senroflux' ), array( 'status' => 400 ) );
		}

		$query = new \WP_Query( self::queryArgs( $input ) );
		$posts = $query->posts ?? array();

		/** @var list<WP_Post> $posts */
		return array(
			'posts'       => array_map( static fn ( $p ) => self::shapePost( $p, $fields ), $posts ),
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
		);
	}

	/**
	 * create-post execute: draft-only, content validated, insert as draft.
	 *
	 * @param array<string,mixed> $input     Call input.
	 * @param Validator           $validator Write validator.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function executeCreatePost( array $input, Validator $validator ): array|WP_Error {
		if ( ( $input['status'] ?? 'draft' ) !== 'draft' ) {
			return new WP_Error( 'status_not_allowed', __( 'Only draft is allowed on create.', 'senroflux' ), array( 'status' => 400 ) );
		}

		$content = (string) ( $input['content'] ?? '' );
		$clean   = $validator->clean( $content, array( 'post_type' => (string) ( $input['post_type'] ?? 'page' ) ) );
		if ( ! $clean['ok'] ) {
			/** @var WP_Error $error */
			$error = $clean['wp_error'];

			return $error;
		}

		$id = wp_insert_post(
			array(
				'post_type'    => $input['post_type'] ?? 'page',
				'post_title'   => (string) ( $input['title'] ?? '' ),
				'post_content' => $clean['content'],
				'post_status'  => 'draft',
				'post_name'    => (string) ( $input['slug'] ?? '' ),
				'post_parent'  => (int) ( $input['parent'] ?? 0 ),
				'post_excerpt' => (string) ( $input['excerpt'] ?? '' ),
			),
			true
		);

		if ( is_wp_error( $id ) ) {
			return $id;
		}

		return array(
			'id'     => (int) $id,
			'status' => 'draft',
		);
	}

	/**
	 * update-post execute: target post must exist; content validated when
	 * present; status allowlist draft|pending|publish.
	 *
	 * @param array<string,mixed> $input     Call input.
	 * @param Validator           $validator Write validator.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function executeUpdatePost( array $input, Validator $validator ): array|WP_Error {
		if ( ! isset( $input['id'] ) || ! is_numeric( $input['id'] ) ) {
			return new WP_Error( 'not_found', __( 'Post not found.', 'senroflux' ), array( 'status' => 400 ) );
		}

		$post = function_exists( 'get_post' ) ? get_post( (int) $input['id'] ) : null;
		if ( ! $post ) {
			return new WP_Error( 'not_found', __( 'Post not found.', 'senroflux' ), array( 'status' => 400 ) );
		}

		$args = array( 'ID' => (int) ( $post->ID ?? 0 ) );

		if ( isset( $input['title'] ) ) {
			$args['post_title'] = (string) $input['title'];
		}

		if ( isset( $input['content'] ) ) {
			$clean = $validator->clean( (string) $input['content'], array( 'post_type' => (string) ( $post->post_type ?? 'page' ) ) );
			if ( ! $clean['ok'] ) {
				/** @var WP_Error $error */
				$error = $clean['wp_error'];

				return $error;
			}
			$args['post_content'] = $clean['content'];
		}

		if ( isset( $input['slug'] ) ) {
			$args['post_name'] = (string) $input['slug'];
		}
		if ( isset( $input['parent'] ) ) {
			$args['post_parent'] = (int) $input['parent'];
		}
		if ( isset( $input['excerpt'] ) ) {
			$args['post_excerpt'] = (string) $input['excerpt'];
		}

		$status = $input['status'] ?? null;
		if ( null !== $status && ! in_array( $status, array( 'draft', 'pending', 'publish' ), true ) ) {
			return new WP_Error( 'status_not_allowed', __( 'That status is not allowed on update.', 'senroflux' ), array( 'status' => 400 ) );
		}
		if ( null !== $status ) {
			$args['post_status'] = (string) $status;
		}

		$updated = wp_update_post( $args, true );
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		return array(
			'id'     => (int) ( $post->ID ?? 0 ),
			'status' => is_string( $status ) ? $status : (string) ( $post->post_status ?? '' ),
		);
	}

	/**
	 * Build the WP_Query args for read-content mode 3.
	 *
	 * @param array<string,mixed> $input Call input.
	 * @return array<string,mixed>
	 */
	private static function queryArgs( array $input ): array {
		$args = array(
			'post_type'      => (string) ( $input['post_type'] ?? 'page' ),
			'post_status'    => (array) ( $input['status'] ?? array( 'publish' ) ),
			'paged'          => (int) ( $input['page'] ?? 1 ),
			'posts_per_page' => (int) ( $input['per_page'] ?? 10 ),
		);

		if ( isset( $input['author'] ) ) {
			$args['author'] = (int) $input['author'];
		}
		if ( isset( $input['parent'] ) ) {
			$args['post_parent'] = (int) $input['parent'];
		}
		if ( isset( $input['include'] ) && is_array( $input['include'] ) ) {
			$args['post__in'] = array_map( 'intval', $input['include'] );
		}

		return $args;
	}

	/**
	 * Shape a WP_Post into the output schema, honouring the requested fields.
	 *
	 * @param object           $post   A WP_Post.
	 * @param list<string>     $fields Requested fields.
	 * @return array<string,mixed>
	 */
	private static function shapePost( object $post, array $fields ): array {
		$field_set = array_flip( $fields );
		$want      = static fn ( string $key ): bool => isset( $field_set[ $key ] );

		$out              = array();
		$out['id']        = (int) ( $post->ID ?? 0 );
		$out['post_type'] = (string) ( $post->post_type ?? '' );
		$out['status']    = (string) ( $post->post_status ?? 'publish' );
		$out['slug']      = (string) ( $post->post_name ?? '' );
		$out['parent']    = (int) ( $post->post_parent ?? 0 );

		if ( $want( 'date' ) ) {
			$out['date'] = (string) ( $post->post_date ?? '' );
		}
		if ( $want( 'date_gmt' ) ) {
			$out['date_gmt'] = (string) ( $post->post_date_gmt ?? '' );
		}
		if ( $want( 'modified' ) ) {
			$out['modified'] = (string) ( $post->post_modified ?? '' );
		}
		if ( $want( 'modified_gmt' ) ) {
			$out['modified_gmt'] = (string) ( $post->post_modified_gmt ?? '' );
		}
		if ( $want( 'link' ) && function_exists( 'get_permalink' ) ) {
			$out['link'] = (string) get_permalink( (int) ( $post->ID ?? 0 ) );
		}
		if ( $want( 'title_raw' ) ) {
			$out['title_raw'] = (string) ( $post->post_title ?? '' );
		}
		if ( $want( 'title_rendered' ) ) {
			$out['title_rendered'] = (string) ( $post->post_title ?? '' );
		}
		if ( $want( 'excerpt_raw' ) ) {
			$out['excerpt_raw'] = (string) ( $post->post_excerpt ?? '' );
		}
		if ( $want( 'excerpt_rendered' ) ) {
			$out['excerpt_rendered'] = (string) ( $post->post_excerpt ?? '' );
		}
		if ( $want( 'content_raw' ) ) {
			$out['content_raw'] = (string) ( $post->post_content ?? '' );
		}
		if ( $want( 'content_rendered' ) && function_exists( 'apply_filters' ) ) {
			$out['content_rendered'] = (string) apply_filters( 'the_content', (string) ( $post->post_content ?? '' ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- consuming a core hook
		}

		return $out;
	}
}
