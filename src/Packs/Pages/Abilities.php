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
 * `Validator` also owns the tag/attribute allow-list: these runs execute as an
 * administrator, who holds `unfiltered_html`, so WordPress installs no kses of
 * its own on this path.
 *
 * CAPABILITIES. Every gate is applied in BOTH the `permission_callback` and the
 * execute callback — execute is reachable on its own, and a write must never
 * assume an earlier gate ran. The primitive `edit_posts` / `edit_pages` check is
 * not sufficient on its own:
 *   - create → the post type's `create_posts` capability;
 *   - update → the PER-POST `edit_post` capability for the target id;
 *   - a publish transition → additionally the type's `publish_posts` /
 *     `publish_pages`;
 *   - read by id or slug → the PER-POST `read_post` capability, and the post
 *     type must be on the `page|post` allow-list.
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
	 * The post types these abilities will touch at all (S10 allow-list). Every
	 * read/write path re-checks this — the input schema's `enum` is enforced by
	 * the Abilities API, not by this class, and the pack fails closed on its own.
	 *
	 * @var list<string>
	 */
	private const POST_TYPES = array( 'page', 'post' );

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
					$pt    = (string) ( $input['post_type'] ?? 'page' );
					if ( ! self::allowedPostType( $pt ) || ! current_user_can( self::postTypeCap( $pt ) ) ) {
						return false;
					}

					// The by-id mode carries no post_type, so the per-object
					// read check happens here as well as in the execute path.
					if ( isset( $input['id'] ) && is_numeric( $input['id'] ) ) {
						return current_user_can( 'read_post', (int) $input['id'] );
					}

					return true;
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
					return self::mayCreate( is_array( $input ) ? $input : array() );
				},
				// NOT destructive. `create-post` only ever makes a NEW draft
				// (`status_not_allowed` refuses anything else), so it destroys
				// nothing. The hint is safety-critical, not decorative: Agent
				// Safety's VerdictPipeline::elevateForDestructiveHint() treats
				// `destructive => true` as an irreversible classification and
				// parked every Tier-1 draft creation for approval (live run 43).
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
					return self::mayUpdate( is_array( $input ) ? $input : array() );
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
					// Only `id` is required: `shapePost()` emits the rest only
					// when `fields` asks for it, and a narrow `fields` list must
					// not fail the ability's own output validation.
					'type'                 => 'object',
					'required'             => array( 'id' ),
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
	 * The post-type EDIT capability (page → edit_pages, else edit_posts).
	 */
	private static function postTypeCap( string $post_type ): string {
		return 'page' === $post_type ? 'edit_pages' : 'edit_posts';
	}

	/**
	 * The post-type CREATE capability. WordPress derives `create_posts` from
	 * the registered post type (it defaults to the type's `edit_posts` cap), so
	 * ask the type object when it is available and fall back to the same
	 * default when it is not.
	 */
	private static function createCap( string $post_type ): string {
		if ( function_exists( 'get_post_type_object' ) ) {
			$object = get_post_type_object( $post_type );
			if ( is_object( $object ) && isset( $object->cap->create_posts ) && is_string( $object->cap->create_posts ) ) {
				return $object->cap->create_posts;
			}
		}

		return self::postTypeCap( $post_type );
	}

	/**
	 * The post-type PUBLISH capability (page → publish_pages, else publish_posts).
	 * A publish transition needs this on top of `edit_post` for the target.
	 */
	private static function publishCap( string $post_type ): string {
		if ( function_exists( 'get_post_type_object' ) ) {
			$object = get_post_type_object( $post_type );
			if ( is_object( $object ) && isset( $object->cap->publish_posts ) && is_string( $object->cap->publish_posts ) ) {
				return $object->cap->publish_posts;
			}
		}

		return 'page' === $post_type ? 'publish_pages' : 'publish_posts';
	}

	/**
	 * The single refusal for every capability failure. Deliberately one code and
	 * one message: which capability was missing is a detail the model cannot act
	 * on, and spelling it out narrates the site's permission map to a caller
	 * that just failed a permission check.
	 */
	private static function forbidden(): WP_Error {
		return new WP_Error(
			'forbidden',
			__( 'You are not allowed to do that.', 'senroflux' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Whether a post type is one this pack will touch at all.
	 */
	private static function allowedPostType( string $post_type ): bool {
		return in_array( $post_type, self::POST_TYPES, true );
	}

	/**
	 * The create/update capability gate, shared by `permission_callback` and the
	 * execute callback so a caller that reaches execute by another route (a
	 * direct `Ability::execute()`, a future transport) is checked too.
	 *
	 * @param array<string,mixed> $input Call input.
	 */
	private static function mayCreate( array $input ): bool {
		$post_type = (string) ( $input['post_type'] ?? 'page' );

		return self::allowedPostType( $post_type ) && current_user_can( self::createCap( $post_type ) );
	}

	/**
	 * The update gate: the PER-POST `edit_post` capability, plus the post type's
	 * publish capability when the call is a publish transition.
	 *
	 * @param array<string,mixed> $input Call input.
	 */
	private static function mayUpdate( array $input ): bool {
		if ( ! isset( $input['id'] ) || ! is_numeric( $input['id'] ) ) {
			return false;
		}

		$id   = (int) $input['id'];
		$post = function_exists( 'get_post' ) ? get_post( $id ) : null;
		if ( ! is_object( $post ) ) {
			return false;
		}

		$post_type = (string) $post->post_type;
		if ( ! self::allowedPostType( $post_type ) ) {
			return false;
		}

		if ( ! current_user_can( 'edit_post', $id ) ) {
			return false;
		}

		if ( 'publish' === ( $input['status'] ?? null ) && ! current_user_can( self::publishCap( $post_type ) ) ) {
			return false;
		}

		return true;
	}

	/**
	 * The single-post read gate: the type must be on the allow-list and the
	 * user must hold `read_post` for that exact post.
	 *
	 * @param object              $post  The resolved WP_Post.
	 * @param array<string,mixed> $input Call input.
	 * @return true|WP_Error true when readable, else the refusal.
	 */
	private static function readablePost( object $post, array $input ): true|WP_Error {
		$post_type = (string) ( $post->post_type ?? '' );

		if ( ! self::allowedPostType( $post_type ) ) {
			return new WP_Error( 'not_found', __( 'Post not found.', 'senroflux' ), array( 'status' => 400 ) );
		}

		$requested = $input['post_type'] ?? null;
		if ( is_string( $requested ) && $requested !== $post_type ) {
			return new WP_Error( 'not_found', __( 'Post not found.', 'senroflux' ), array( 'status' => 400 ) );
		}

		if ( ! current_user_can( 'read_post', (int) ( $post->ID ?? 0 ) ) ) {
			return self::forbidden();
		}

		return true;
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
			if ( ! is_object( $post ) ) {
				return new WP_Error( 'not_found', __( 'Post not found.', 'senroflux' ), array( 'status' => 400 ) );
			}

			$readable = self::readablePost( $post, $input );

			return true === $readable ? self::shapePost( $post, $fields ) : $readable;
		}

		if ( isset( $input['slug'] ) && function_exists( 'get_page_by_path' ) ) {
			$post_type = (string) ( $input['post_type'] ?? 'page' );
			if ( ! self::allowedPostType( $post_type ) ) {
				return new WP_Error( 'not_found', __( 'Post not found.', 'senroflux' ), array( 'status' => 400 ) );
			}

			$post = get_page_by_path( (string) $input['slug'], 'OBJECT', $post_type );
			if ( ! is_object( $post ) ) {
				return new WP_Error( 'not_found', __( 'Post not found.', 'senroflux' ), array( 'status' => 400 ) );
			}

			$readable = self::readablePost( $post, $input );

			return true === $readable ? self::shapePost( $post, $fields ) : $readable;
		}

		if ( ! self::allowedPostType( (string) ( $input['post_type'] ?? 'page' ) ) ) {
			return new WP_Error( 'not_found', __( 'Post not found.', 'senroflux' ), array( 'status' => 400 ) );
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
		// Re-checked here, not only in permission_callback: execute is reachable
		// on its own and a write must never rely on an earlier gate having run.
		if ( ! self::mayCreate( $input ) ) {
			return self::forbidden();
		}

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
	 * update-post execute: target post must exist; non-empty content is
	 * validated, omitted/empty content leaves `post_content` untouched (and a
	 * publish then validates the STORED content); status allowlist
	 * draft|pending|publish.
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

		if ( ! self::allowedPostType( (string) ( $post->post_type ?? '' ) ) ) {
			return new WP_Error( 'not_found', __( 'Post not found.', 'senroflux' ), array( 'status' => 400 ) );
		}

		// The status allow-list runs BEFORE the capability gate so a refused
		// status never reveals whether the caller could have published.
		$status = $input['status'] ?? null;
		if ( null !== $status && ! in_array( $status, array( 'draft', 'pending', 'publish' ), true ) ) {
			return new WP_Error( 'status_not_allowed', __( 'That status is not allowed on update.', 'senroflux' ), array( 'status' => 400 ) );
		}

		// Per-post `edit_post`, plus the type's publish cap on a publish
		// transition. Re-checked here for the same reason as create.
		if ( ! self::mayUpdate( $input ) ) {
			return self::forbidden();
		}

		$args = array( 'ID' => (int) ( $post->ID ?? 0 ) );

		if ( isset( $input['title'] ) ) {
			$args['post_title'] = (string) $input['title'];
		}

		// `content` omitted OR an empty string means "content unchanged": the
		// stored markup is left exactly as it is and the validator is not
		// asked about markup the caller never sent. Observed live (run 51): a
		// model publishing with `{id, status:"publish", content:""}` was
		// refused "A page needs 2 to 8 patterns; 0 given" four calls running.
		// Any NON-empty content stays fully validated, whole-write refusal.
		$new_content = isset( $input['content'] ) ? (string) $input['content'] : '';
		$post_type   = array( 'post_type' => (string) ( $post->post_type ?? 'page' ) );

		if ( '' !== $new_content ) {
			$clean = $validator->clean( $new_content, $post_type );
			if ( ! $clean['ok'] ) {
				/** @var WP_Error $error */
				$error = $clean['wp_error'];

				return $error;
			}
			$args['post_content'] = $clean['content'];
		} elseif ( 'publish' === $status ) {
			// Fail closed (§0.2): "content unchanged" must never be a way to
			// put unvalidated markup live. On a publish the STORED content is
			// validated instead — and left untouched either way, so a page
			// that goes live is markup the validator has accepted.
			$stored = $validator->clean( (string) ( $post->post_content ?? '' ), $post_type );
			if ( ! $stored['ok'] ) {
				/** @var WP_Error $error */
				$error = $stored['wp_error'];

				return $error;
			}
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
		if ( $want( 'author' ) ) {
			$author_id     = (int) ( $post->post_author ?? 0 );
			$user          = function_exists( 'get_userdata' ) ? get_userdata( $author_id ) : false;
			$out['author'] = array(
				'id'   => $author_id,
				'name' => is_object( $user ) ? (string) ( $user->display_name ?? '' ) : '',
			);
		}
		if ( $want( 'content_rendered' ) && function_exists( 'apply_filters' ) ) {
			$out['content_rendered'] = (string) apply_filters( 'the_content', (string) ( $post->post_content ?? '' ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- consuming a core hook
		}

		return $out;
	}
}
