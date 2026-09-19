<?php
/**
 * The shared content registrar (0.3 S4): registers the six content-pack
 * abilities on the Abilities API, and owns the permission predicates + post
 * shaping every content pack shares.
 *
 * TARGET REPO PATH: src/Packs/Content/Abilities.php
 *
 * Moved out of `Packs\Pages\Abilities` (0.2/0.3-stage-2): the pages pack now
 * keeps only its OWN concerns (Vocabulary, Validator, PublishSummary,
 * PagesPack); this class is pack-agnostic and every content pack (pages
 * today, posts and site later) registers a {@see Vocabulary}/{@see Validator}
 * pair here under its own slug.
 *
 * 0.3 S4 CHANGES SHIPPED 0.2 BEHAVIOUR:
 *   - `senroflux/update-post` is now DRAFT-STATE EDITS ONLY (Tier 1). It
 *     refuses a target post that is already public (publish/future/private)
 *     and refuses a requested transition to publish/future.
 *   - NEW `senroflux/publish-post` carries every publish/future transition
 *     AND any edit to an already-public post (Tier 2).
 *   - `senroflux/create-post` refuses `publish`/`future` outright (already
 *     true in 0.2 by construction: `status_not_allowed` fires for anything
 *     but `draft`).
 *   - NEW slug-collision refusal on `create-post`: `slug_collision` (409)
 *     when a non-trashed object of the same post type already holds the
 *     requested slug, or matches the title case-insensitively.
 *     `wp_unique_post_slug()` skips drafts, so 0.2's pages pack could create
 *     a second page at the same slug silently; this closes that gap for
 *     every content pack.
 *
 * VOCABULARY BY PACK, NEVER BY ARGUMENT (S4 point 4). `list-patterns` and the
 * write abilities' content validation resolve the RUNNING pack's
 * {@see Vocabulary}/{@see Validator} through {@see useRunPack()}, which the
 * composition root sets from `Run::$pack` for the scope of one tick — never
 * from the model. A model-supplied `pack` argument is refused outright
 * (`pack_arg_refused`), and a call with no resolvable pack context fails
 * closed (`pack_unresolved`) rather than falling back to any one pack's
 * rules.
 *
 * S8 STALE WRITE: {@see executeUpdateLike()} compares the target's CURRENT
 * `post_modified_gmt` against the marker {@see useRunContext()}'s run last
 * recorded for that id (via {@see Tracker::staleWrite()}) and refuses
 * `stale_write` (409) on a mismatch or an unread object. `read-content`
 * records the marker at read time ({@see recordReadMarker()}); a successful
 * write updates it to the post's NEW `post_modified_gmt` so the same run may
 * keep editing its own writes without re-reading. `create-post` has nothing
 * to compare against, but records its new object as read-at-creation.
 *
 * CAPABILITIES. Every gate is applied in BOTH the `permission_callback` and
 * the execute callback — execute is reachable on its own, and a write must
 * never assume an earlier gate ran. The primitive `edit_posts` / `edit_pages`
 * check is not sufficient on its own:
 *   - create → the post type's `create_posts` capability;
 *   - a draft-state update → the PER-POST `edit_post` capability for the
 *     target id;
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

namespace Specflux\SenroFlux\Packs\Content;

use Specflux\SenroFlux\Run\RunStore;
use Specflux\SenroFlux\Run\Tracker;
use WP_Error;
use WP_Post;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Registers the shared content abilities and their permission predicates.
 */
final class Abilities {

	/**
	 * The ability category for the content abilities.
	 */
	public const CATEGORY = 'senroflux-content';

	/**
	 * The post types these abilities will touch at all (S10 allow-list). Every
	 * read/write path re-checks this — the input schema's `enum` is enforced by
	 * the Abilities API, not by this class, and the pack fails closed on its own.
	 *
	 * @var list<string>
	 */
	private const POST_TYPES = array( 'page', 'post' );

	/**
	 * Statuses a non-trashed object may hold and still collide on slug/title
	 * (S4 slug collision). Trash is deliberately absent.
	 *
	 * @var list<string>
	 */
	private const COLLISION_STATUSES = array( 'publish', 'draft', 'pending', 'private', 'future' );

	/**
	 * Statuses that make a post's CURRENT state "already public" (S4): a
	 * draft-state update must refuse these; a publish-post call is routed to
	 * them.
	 *
	 * @var list<string>
	 */
	private const PUBLIC_STATUSES = array( 'publish', 'future', 'private' );

	/**
	 * Statuses `update-post` (Tier 1) may be asked to move a post TO.
	 *
	 * @var list<string>
	 */
	private const DRAFT_STATUSES = array( 'draft', 'pending' );

	/**
	 * Statuses `publish-post` (Tier 2) may be asked to move a post TO, on top
	 * of every draft-state status (S4: "any edit to an already-public post",
	 * including moving it back to draft or pending).
	 *
	 * @var list<string>
	 */
	private const PUBLISH_STATUSES = array( 'draft', 'pending', 'publish', 'future' );

	/**
	 * Statuses that count as "requesting a publish/future transition".
	 *
	 * @var list<string>
	 */
	private const TRANSITION_STATUSES = array( 'publish', 'future' );

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
	 * Registered content-pack sources, keyed by pack slug.
	 *
	 * @var array<string, array{validator: Validator, vocabulary: Vocabulary, capability: string}>
	 */
	private static array $sources = array();

	/**
	 * The pack slug executing the current tick, or null outside one. Scoped
	 * per tick (never per-request) by {@see useRunPack()} / {@see forgetRunPack()}
	 * — the same discipline `PublishSummary::useRunContext()` uses, and for
	 * the same reason: several ticks share one PHP process under PHPUnit,
	 * WP-CLI and cron.
	 */
	private static ?string $current_pack = null;

	/**
	 * The ticking run's id, or null outside one (0.3 S8). Scoped per tick by
	 * {@see useRunContext()} / {@see forgetRunContext()}, same discipline as
	 * {@see $current_pack} — never a model-supplied argument.
	 */
	private static ?int $current_run_id = null;

	/**
	 * The store used to read/persist the current run's `objects_json` (S8).
	 * Set together with {@see $current_run_id}; both null outside a tick.
	 */
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

	/**
	 * Forget the per-request registered flag so {@see register()} can run again
	 * (test-only; mirrors `Plugin::reset()`).
	 */
	public static function reset(): void {
		self::$registered = false;
	}

	/**
	 * Test-only: forget every registered content-pack source.
	 */
	public static function resetSources(): void {
		self::$sources      = array();
		self::$current_pack = null;
	}

	/**
	 * Register a content pack's vocabulary + validator under its slug. Safe to
	 * call more than once — a later call for the same slug replaces the
	 * earlier one.
	 *
	 * @param string     $pack_slug        The pack's `name()`.
	 * @param Validator  $validator        The pack's write validator.
	 * @param Vocabulary $vocabulary       The pack's pattern vocabulary.
	 * @param string     $list_capability  The capability `list-patterns` requires for this pack.
	 */
	public static function registerSource( string $pack_slug, Validator $validator, Vocabulary $vocabulary, string $list_capability ): void {
		self::$sources[ $pack_slug ] = array(
			'validator'  => $validator,
			'vocabulary' => $vocabulary,
			'capability' => $list_capability,
		);
	}

	/**
	 * Enter the run context for one tick: which pack's vocabulary/validator
	 * governs vocabulary-bearing calls. Set by the composition root from
	 * `Run::$pack`, NEVER from the model (S4 point 4).
	 *
	 * @param string|null $pack_slug The running run's pack, or null (a
	 *                                direct-allow run has none).
	 */
	public static function useRunPack( ?string $pack_slug ): void {
		self::$current_pack = ( null !== $pack_slug && '' !== $pack_slug ) ? $pack_slug : null;
	}

	/**
	 * Leave the run context (mirrors `PublishSummary::forgetRunContext()`).
	 */
	public static function forgetRunPack(): void {
		self::$current_pack = null;
	}

	/**
	 * Enter the run context for one tick (0.3 S8): which run's tracker the
	 * stale-write compare reads/updates. Set by the composition root from the
	 * ticking run's id, NEVER from the model — mirrors {@see useRunPack()}.
	 * A call reached with no run context (no tick in flight, e.g. an ability
	 * invoked directly by another Abilities API consumer) fails CLOSED: every
	 * object looks unread, so every write is a stale write (§0 fail-closed).
	 *
	 * @param int|null    $run_id The ticking run's id, or null.
	 * @param RunStore|null $store  The store backing that run, or null.
	 */
	public static function useRunContext( ?int $run_id, ?RunStore $store ): void {
		self::$current_run_id = $run_id;
		self::$store          = $store;
	}

	/**
	 * Leave the run context (mirrors {@see forgetRunPack()}).
	 */
	public static function forgetRunContext(): void {
		self::$current_run_id = null;
		self::$store          = null;
	}

	/**
	 * The current run's `objects_json` map, or an empty set with no run
	 * context resolved (fail closed — see {@see useRunContext()}).
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
	 * Record a read/write's modified marker on the current run's tracker
	 * (0.3 S8). A no-op with no run context resolved.
	 *
	 * @param int    $object_id Object id (a post id).
	 * @param string $marker    The object's `post_modified_gmt`.
	 */
	private static function recordReadMarker( int $object_id, string $marker ): void {
		if ( null === self::$current_run_id || null === self::$store ) {
			return;
		}

		$objects = Tracker::recordRead( self::currentObjects(), $object_id, $marker );
		self::$store->updateRun( self::$current_run_id, array( 'objects_json' => $objects ) );
	}

	/**
	 * Whether a write to `$object_id` must be refused as a stale write (0.3
	 * S8): delegates to {@see Tracker::staleWrite()} over the current run's
	 * tracker. Fails CLOSED (true, i.e. refuse) with no run context.
	 *
	 * @param int    $object_id      Object id (a post id).
	 * @param string $current_marker The post's CURRENT `post_modified_gmt`.
	 */
	private static function isStaleWrite( int $object_id, string $current_marker ): bool {
		if ( null === self::$current_run_id || null === self::$store ) {
			return true;
		}

		return Tracker::staleWrite( self::currentObjects(), $object_id, $current_marker );
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
				'label'       => __( 'SenroFlux content', 'senroflux' ),
				'description' => __( 'Content abilities shared by SenroFlux capability packs.', 'senroflux' ),
			)
		);
	}

	/**
	 * Register the six abilities. Idempotent per request (the API rejects a
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

		self::registerReadContent();
		self::registerCreatePost();
		self::registerUpdatePost();
		self::registerPublishPost();
		self::registerGetPreviewUrl();
		self::registerListPatterns();
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
	 * senroflux/create-post — draft-only create; content validated before
	 * insert; slug/title collision refused (S4).
	 */
	private static function registerCreatePost(): void {
		wp_register_ability(
			'senroflux/create-post',
			array(
				'label'               => __( 'Create post', 'senroflux' ),
				'description'         => __( 'Create a draft page or post from block markup.', 'senroflux' ),
				'category'            => self::CATEGORY,
				'input_schema'        => self::createPostSchema(),
				'output_schema'       => self::createPostOutputSchema(),
				'execute_callback'    => static function ( $input = array() ) {
					return self::executeCreatePost( is_array( $input ) ? $input : array() );
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
	 * senroflux/update-post — draft-state edits ONLY (Tier 1, 0.3 S4). Refuses
	 * a target that is already public, or a requested transition to
	 * publish/future — both point the caller at `publish-post` instead.
	 *
	 * NOT destructive: unlike 0.2's combined ability, this one can never
	 * publish anything, so the `destructive` hint is dropped — carrying it
	 * would elevate every Tier-1 draft edit to Agent Safety's irreversible
	 * classification and park it for approval (the same SF-BUG-2 reasoning
	 * `create-post` already documents).
	 */
	private static function registerUpdatePost(): void {
		wp_register_ability(
			'senroflux/update-post',
			array(
				'label'               => __( 'Update post', 'senroflux' ),
				'description'         => __( 'Update a draft-state page or post. Refuses an already-public target or a publish/future status; use publish-post for those.', 'senroflux' ),
				'category'            => self::CATEGORY,
				'input_schema'        => self::updatePostSchema( self::DRAFT_STATUSES ),
				'output_schema'       => self::updatePostOutputSchema(),
				'execute_callback'    => static function ( $input = array() ) {
					return self::executeUpdateLike( is_array( $input ) ? $input : array(), false );
				},
				'permission_callback' => static function ( $input = array() ) {
					return self::mayUpdate( is_array( $input ) ? $input : array(), false );
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
	 * senroflux/publish-post — NEW (0.3 S4). Every transition to publish or
	 * future, AND any edit to an already-public post (Tier 2, "any change
	 * with public effect").
	 */
	private static function registerPublishPost(): void {
		wp_register_ability(
			'senroflux/publish-post',
			array(
				'label'               => __( 'Publish post', 'senroflux' ),
				'description'         => __( 'Publish, schedule, or edit an already-public page or post.', 'senroflux' ),
				'category'            => self::CATEGORY,
				'input_schema'        => self::updatePostSchema( self::PUBLISH_STATUSES ),
				'output_schema'       => self::updatePostOutputSchema(),
				'execute_callback'    => static function ( $input = array() ) {
					return self::executeUpdateLike( is_array( $input ) ? $input : array(), true );
				},
				'permission_callback' => static function ( $input = array() ) {
					return self::mayUpdate( is_array( $input ) ? $input : array(), true );
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
	 * senroflux/list-patterns — {} → {patterns: [...]}. Answers with the
	 * RUNNING pack's vocabulary (S4 point 4) — never a model-supplied `pack`.
	 */
	private static function registerListPatterns(): void {
		wp_register_ability(
			'senroflux/list-patterns',
			array(
				'label'               => __( 'List patterns', 'senroflux' ),
				'description'         => __( 'List the available content patterns and their copy constraints for the running pack.', 'senroflux' ),
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
									'markup'      => array( 'type' => 'string' ),
								),
							),
						),
					),
				),
				'execute_callback'    => static function ( $input = array() ) {
					$input = is_array( $input ) ? $input : array();
					if ( array_key_exists( 'pack', $input ) ) {
						return self::packArgRefused();
					}

					$vocabulary = self::currentVocabulary();
					if ( null === $vocabulary ) {
						return self::packUnresolved();
					}

					return $vocabulary->listPayload();
				},
				'permission_callback' => static function () {
					$capability = self::currentListCapability();

					return null !== $capability && current_user_can( $capability );
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
	 * update-post / publish-post input schema (id + same fields); the
	 * requested-status enum is the only thing that differs between the two
	 * abilities.
	 *
	 * @param list<string> $status_enum Allowed requested statuses for this ability.
	 * @return array<string,mixed>
	 */
	private static function updatePostSchema( array $status_enum ): array {
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
					'enum' => $status_enum,
				),
				'slug'      => array( 'type' => 'string' ),
				'parent'    => array( 'type' => 'integer' ),
				'excerpt'   => array( 'type' => 'string' ),
			),
		);
	}

	/**
	 * update-post / publish-post output schema.
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
	 * The single refusal for every capability/routing failure. Deliberately
	 * one code and one message: which capability was missing — or whether the
	 * call should have gone to the other ability — is a detail the model
	 * cannot act on beyond retrying with the right one, and spelling it out
	 * narrates the site's permission map to a caller that just failed a check.
	 */
	private static function forbidden(): WP_Error {
		return new WP_Error(
			'forbidden',
			__( 'You are not allowed to do that.', 'senroflux' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * The refusal for a model-supplied `pack` argument (S4 point 4): the pack
	 * is the harness's business, never the model's.
	 */
	private static function packArgRefused(): WP_Error {
		return new WP_Error(
			'pack_arg_refused',
			__( 'The pack is determined by the run, not by the call.', 'senroflux' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * The fail-closed refusal when a vocabulary-bearing call has no resolved
	 * pack context (S4 point 4) — never a silent fallback to any one pack.
	 */
	private static function packUnresolved(): WP_Error {
		return new WP_Error(
			'pack_unresolved',
			__( 'This call is not running inside a pack that provides content rules.', 'senroflux' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Whether a post type is one this pack will touch at all.
	 */
	private static function allowedPostType( string $post_type ): bool {
		return in_array( $post_type, self::POST_TYPES, true );
	}

	/**
	 * The running tick's Validator, or null when no pack is resolved (S4 point 4).
	 */
	private static function currentValidator(): ?Validator {
		$source = self::currentSource();

		return null !== $source ? $source['validator'] : null;
	}

	/**
	 * The running tick's Vocabulary, or null when no pack is resolved (S4 point 4).
	 */
	private static function currentVocabulary(): ?Vocabulary {
		$source = self::currentSource();

		return null !== $source ? $source['vocabulary'] : null;
	}

	/**
	 * The capability `list-patterns` requires for the running pack, or null
	 * when no pack is resolved.
	 */
	private static function currentListCapability(): ?string {
		$source = self::currentSource();

		return null !== $source ? $source['capability'] : null;
	}

	/**
	 * @return array{validator: Validator, vocabulary: Vocabulary, capability: string}|null
	 */
	private static function currentSource(): ?array {
		if ( null === self::$current_pack ) {
			return null;
		}

		return self::$sources[ self::$current_pack ] ?? null;
	}

	/**
	 * The create capability gate, shared by `permission_callback` and the
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
	 * Whether a post's CURRENT status counts as "already public" (S4).
	 */
	private static function isPublicStatus( string $status ): bool {
		return in_array( $status, self::PUBLIC_STATUSES, true );
	}

	/**
	 * Whether a REQUESTED status counts as "a publish/future transition" (S4).
	 */
	private static function isTransitionStatus( mixed $status ): bool {
		return is_string( $status ) && in_array( $status, self::TRANSITION_STATUSES, true );
	}

	/**
	 * The shared update/publish routing + capability gate (S4).
	 *
	 * `$publish_tier` false (update-post, Tier 1): refuses when the target is
	 * already public, or the call requests a publish/future transition — both
	 * belong to `publish-post`.
	 *
	 * `$publish_tier` true (publish-post, Tier 2): only reachable when the
	 * call is genuinely a Tier-2 change — the target is already public, or the
	 * call requests a publish/future transition. A transition additionally
	 * needs the post type's publish capability, on top of the PER-POST
	 * `edit_post` capability every update needs.
	 *
	 * @param array<string,mixed> $input        Call input.
	 * @param bool                $publish_tier Whether this is the publish-post gate.
	 */
	private static function mayUpdate( array $input, bool $publish_tier ): bool {
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

		$current_public = self::isPublicStatus( (string) $post->post_status );
		$desired_public = self::isTransitionStatus( $input['status'] ?? null );

		if ( $publish_tier ) {
			if ( ! $current_public && ! $desired_public ) {
				return false;
			}

			return ! $desired_public || current_user_can( self::publishCap( $post_type ) );
		}

		return ! $current_public && ! $desired_public;
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
			if ( true !== $readable ) {
				return $readable;
			}

			// S8: a single-object read is the moment the run's tracker learns
			// this object's CURRENT marker — never for the list-query mode
			// below, which names no one object the run intends to write.
			self::recordReadMarker( (int) $post->ID, (string) $post->post_modified_gmt );

			return self::shapePost( $post, $fields );
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
			if ( true !== $readable ) {
				return $readable;
			}

			self::recordReadMarker( (int) $post->ID, (string) $post->post_modified_gmt );

			return self::shapePost( $post, $fields );
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
	 * create-post execute: draft-only, slug/title collision checked, content
	 * validated, insert as draft.
	 *
	 * @param array<string,mixed> $input Call input.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function executeCreatePost( array $input ): array|WP_Error {
		if ( array_key_exists( 'pack', $input ) ) {
			return self::packArgRefused();
		}

		// Re-checked here, not only in permission_callback: execute is reachable
		// on its own and a write must never rely on an earlier gate having run.
		if ( ! self::mayCreate( $input ) ) {
			return self::forbidden();
		}

		if ( ( $input['status'] ?? 'draft' ) !== 'draft' ) {
			return new WP_Error( 'status_not_allowed', __( 'Only draft is allowed on create.', 'senroflux' ), array( 'status' => 400 ) );
		}

		$validator = self::currentValidator();
		if ( null === $validator ) {
			return self::packUnresolved();
		}

		$post_type = (string) ( $input['post_type'] ?? 'page' );
		$slug      = isset( $input['slug'] ) ? (string) $input['slug'] : '';
		$title     = (string) ( $input['title'] ?? '' );

		$collision = self::slugCollision( $post_type, $slug, $title );
		if ( null !== $collision ) {
			return $collision;
		}

		$content = (string) ( $input['content'] ?? '' );
		$clean   = $validator->clean( $content, array( 'post_type' => $post_type ) );
		if ( ! $clean['ok'] ) {
			/** @var WP_Error $error */
			$error = $clean['wp_error'];

			return $error;
		}

		$id = wp_insert_post(
			array(
				'post_type'    => $post_type,
				'post_title'   => $title,
				'post_content' => $clean['content'],
				'post_status'  => 'draft',
				'post_name'    => $slug,
				'post_parent'  => (int) ( $input['parent'] ?? 0 ),
				'post_excerpt' => (string) ( $input['excerpt'] ?? '' ),
			),
			true
		);

		if ( is_wp_error( $id ) ) {
			return $id;
		}

		// S8: "an object the run CREATED counts as read at creation" — the
		// run may update it in the same run without ever calling read-content.
		$created = function_exists( 'get_post' ) ? get_post( (int) $id ) : null;
		self::recordReadMarker( (int) $id, is_object( $created ) ? (string) $created->post_modified_gmt : '' );

		return array(
			'id'     => (int) $id,
			'status' => 'draft',
		);
	}

	/**
	 * S4 slug collision: refuses when any non-trashed object of the same post
	 * type already holds the requested slug, or matches the title
	 * case-insensitively. `wp_unique_post_slug()` skips drafts, so this is the
	 * only place a draft-vs-draft (or draft-vs-published) collision is ever
	 * caught before it would otherwise surface as a silent `-2` at publish.
	 *
	 * Fails CLOSED, like `executeReadContent()`'s query branch, when the query
	 * surface itself is unavailable — never a silent pass on an unverifiable
	 * check.
	 *
	 * @param string $post_type The post type being created.
	 * @param string $slug      The requested slug, or '' when none was given.
	 * @param string $title     The requested title.
	 */
	private static function slugCollision( string $post_type, string $slug, string $title ): ?WP_Error {
		$slug  = trim( $slug );
		$title = trim( $title );
		if ( '' === $slug && '' === $title ) {
			return null;
		}

		if ( ! class_exists( '\WP_Query' ) ) {
			return new WP_Error(
				'collision_check_unavailable',
				__( 'Could not verify the slug is unique.', 'senroflux' ),
				array( 'status' => 500 )
			);
		}

		$query = new \WP_Query(
			array(
				'post_type'      => $post_type,
				'post_status'    => self::COLLISION_STATUSES,
				'posts_per_page' => -1,
			)
		);

		foreach ( (array) ( $query->posts ?? array() ) as $candidate ) {
			if ( ! is_object( $candidate ) ) {
				continue;
			}

			$candidate_slug  = (string) $candidate->post_name;
			$candidate_title = (string) $candidate->post_title;

			if ( '' !== $slug && $candidate_slug === $slug ) {
				return self::slugCollisionError();
			}
			if ( '' !== $title && 0 === strcasecmp( $candidate_title, $title ) ) {
				return self::slugCollisionError();
			}
		}

		return null;
	}

	/**
	 * @return WP_Error stale_write (409) — S8.
	 */
	private static function staleWriteError(): WP_Error {
		return new WP_Error(
			'stale_write',
			__( 'This content changed since the run last read it. Re-read it before writing again.', 'senroflux' ),
			array( 'status' => 409 )
		);
	}

	/**
	 * @return WP_Error slug_collision (409).
	 */
	private static function slugCollisionError(): WP_Error {
		return new WP_Error(
			'slug_collision',
			__( 'Another page or post already uses that slug or title.', 'senroflux' ),
			array( 'status' => 409 )
		);
	}

	/**
	 * update-post / publish-post execute (S4 split). `$publish_tier` selects
	 * which ability's status allow-list and routing gate applies; everything
	 * else — content validation, the omitted/empty-content contract, the
	 * publish-time stored-content re-check — is shared.
	 *
	 * @param array<string,mixed> $input        Call input.
	 * @param bool                $publish_tier Whether this is publish-post.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function executeUpdateLike( array $input, bool $publish_tier ): array|WP_Error {
		if ( array_key_exists( 'pack', $input ) ) {
			return self::packArgRefused();
		}

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

		// The status allow-list runs BEFORE the capability/routing gate so a
		// refused status never reveals whether the caller could have published.
		$status         = $input['status'] ?? null;
		$allowed_status = $publish_tier ? self::PUBLISH_STATUSES : self::DRAFT_STATUSES;
		if ( null !== $status && ! in_array( $status, $allowed_status, true ) ) {
			return new WP_Error( 'status_not_allowed', __( 'That status is not allowed on this ability.', 'senroflux' ), array( 'status' => 400 ) );
		}

		// Per-post `edit_post`, the S4 public/transition routing, and the
		// type's publish cap on a publish/future transition. Re-checked here
		// for the same reason as create.
		if ( ! self::mayUpdate( $input, $publish_tier ) ) {
			return self::forbidden();
		}

		// S8: refuse when $post's CURRENT marker differs from what this run's
		// tracker last recorded for it, or when the run never read it at all.
		// Checked BEFORE content validation — a stale write is refused on
		// staleness alone, never masked by an unrelated shape refusal.
		if ( self::isStaleWrite( (int) ( $post->ID ?? 0 ), (string) ( $post->post_modified_gmt ?? '' ) ) ) {
			return self::staleWriteError();
		}

		$validator = self::currentValidator();
		if ( null === $validator ) {
			return self::packUnresolved();
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
		} elseif ( self::isTransitionStatus( $status ) ) {
			// Fail closed (§0.2): "content unchanged" must never be a way to
			// put unvalidated markup live. On a transition to publish/future
			// the STORED content is validated instead — and left untouched
			// either way, so a page that goes live is markup the validator
			// has accepted.
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

		// S8: update the recorded marker to the post's NEW modified time —
		// re-fetched fresh, never the stale `$post` in hand — so this run may
		// keep editing its own writes without an intervening read-content
		// call. A run parked mid-write and resumed still compares against the
		// marker THIS write left, exactly as if it had re-read.
		$fresh = function_exists( 'get_post' ) ? get_post( (int) ( $post->ID ?? 0 ) ) : null;
		self::recordReadMarker( (int) ( $post->ID ?? 0 ), is_object( $fresh ) ? (string) $fresh->post_modified_gmt : '' );

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
