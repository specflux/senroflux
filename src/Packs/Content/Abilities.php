<?php
/**
 * The shared content registrar (0.3 S4): registers the six content-pack
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Packs\Content;

use Specflux\SenroFlux\Packs\Pages\HeroTemplate;
use Specflux\SenroFlux\Packs\Pages\Layouts;
use Specflux\SenroFlux\Packs\Pages\Vocabulary as PagesVocabulary;
use Specflux\SenroFlux\Packs\Pages\ThemePatterns;
use Specflux\SenroFlux\Packs\Pages\Tone;
use Specflux\SenroFlux\Run\Budget;
use Specflux\SenroFlux\Run\RunStore;
use Specflux\SenroFlux\Run\Tracker;
use WP_Error;
use WP_Post;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Registers the shared content abilities and their permission predicates.
 *
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
	 * Every read-content field name, including the plain aliases models
	 * reach for (a live run asked for `content`, got nothing back, and
	 * rewrote a page it had never seen).
	 *
	 * @var list<string>
	 */
	private const READ_FIELDS = array(
		'id',
		'post_type',
		'status',
		'date',
		'date_gmt',
		'modified',
		'modified_gmt',
		'slug',
		'link',
		'title',
		'title_raw',
		'title_rendered',
		'excerpt',
		'excerpt_raw',
		'excerpt_rendered',
		'content',
		'content_raw',
		'content_rendered',
		'author',
		'parent',
	);

	/** A page with more top-level sections than this must carry an image. */
	private const NO_IMAGE_MAX_SECTIONS = 2;

	/**
	 * Plain field name => the field it reads.
	 *
	 * @var array<string,string>
	 */
	private const FIELD_ALIASES = array(
		'title'   => 'title_raw',
		'content' => 'content_raw',
		'excerpt' => 'excerpt_raw',
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
	 * 0.3 quality fix (images budget 0): whether the CURRENT run's `images`
	 * budget key is 0 — a plainer signal than {@see \Specflux\SenroFlux\Packs\Content\Media::noImageSourceLeft()}
	 * (spend-aware, and out of scope for this fix), used only to decide
	 * whether a refusal message may tell the model to call `media-generate`.
	 * Fails OPEN (false, i.e. assume available) with no run context resolved
	 * — same reasoning as {@see currentObjects()}: this is guidance wording,
	 * never a security gate.
	 */
	private static function currentImagesBudgetIsZero(): bool {
		if ( null === self::$current_run_id || null === self::$store ) {
			return false;
		}

		$run = self::$store->getRun( self::$current_run_id );
		if ( null === $run ) {
			return false;
		}

		return 0 === (int) ( $run->budget[ Budget::IMAGES ] ?? Budget::defaults()[ Budget::IMAGES ] );
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
				'description'         => __( 'Read one page/post by id or slug, or query a list. Use it after writing, too: re-read what you just wrote against its plan-step brief and fix any thin or generic section before finishing.', 'senroflux' ),
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
				'description'         => __( 'Create a draft page or post from block markup. Send content OR sections, never a plan/summary in content alongside sections — if both arrive, sections is used and the result reports content_ignored: true. Write to the plan step\'s brief: who it is for, what it must achieve, its sections in order, and the visitor\'s next step. Write copy a visitor can act on — explain each service (who it helps, what happens, what to expect) using general professional knowledge. Never invent business facts: no names, prices, credentials, results, testimonials, history or addresses that were not supplied. Where a fact was left to you, write around it (for example "our team") instead of inventing one, and never use a placeholder like "to be confirmed". A section should be more than one sentence unless it is a heading or a call to action. After writing, re-read the page or post against its brief and fix any thin or generic section before finishing.', 'senroflux' ),
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
				'description'         => __( 'Update a draft-state page or post. Refuses an already-public target or a publish/future status; use publish-post for those. Send content OR sections (sections only for a page) — if both arrive, sections is used and the result reports content_ignored: true. Same substance rules as create-post: write to the plan step\'s brief, write copy a visitor can act on, never invent business facts, no placeholders, and re-read the result against its brief before finishing.', 'senroflux' ),
				'category'            => self::CATEGORY,
				'input_schema'        => self::updatePostSchema( self::DRAFT_STATUSES ),
				'output_schema'       => self::updatePostOutputSchema(),
				'execute_callback'    => static function ( $input = array() ) {
					return self::executeUpdateLike( is_array( $input ) ? $input : array(), false );
				},
				'permission_callback' => static function ( $input = array() ) {
					return self::updatePermission( is_array( $input ) ? $input : array(), false );
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
					return self::updatePermission( is_array( $input ) ? $input : array(), true );
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
	 * senroflux/list-patterns — {} → compact index; {names} → full entries
	 * for those names only. Answers with the RUNNING
	 * pack's vocabulary (S4 point 4) — never a model-supplied `pack`.
	 */
	private static function registerListPatterns(): void {
		wp_register_ability(
			'senroflux/list-patterns',
			array(
				'label'               => __( 'List patterns', 'senroflux' ),
				'description'         => __( 'Call with no input for a compact index (name, title, description, and — for a theme_derived entry — slots_summary) of the available content patterns. Prefer the active theme\'s own patterns (theme_derived: true) over the vocabulary ones named in the pack\'s layout rules; use a vocabulary pattern only where no theme pattern fits the page. Call again with `names` set to the pattern names you will use to get their full copy constraints (and sample markup/slots) — never guess constraints from the index alone.', 'senroflux' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'default'              => array(),
					'properties'           => array(
						'names' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
					),
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'required'             => array( 'patterns' ),
					'additionalProperties' => false,
					'properties'           => array(
						'patterns'               => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'name'          => array( 'type' => 'string' ),
									'title'         => array( 'type' => 'string' ),
									'description'   => array( 'type' => 'string' ),
									// 0.3 quality fix (theme patterns first):
									// present in the compact index (no `names`)
									// for a theme-derived entry only — "3 text,
									// 1 image" — so the model can pick one with
									// an image slot without a second round trip.
									'slots_summary' => array( 'type' => 'string' ),
									'constraints'   => array(
										'type'       => 'object',
										'properties' => array(
											'slots'  => array( 'type' => 'object' ),
											'stated' => array(
												'type'  => 'array',
												'items' => array( 'type' => 'string' ),
											),
										),
									),
									'markup'        => array( 'type' => 'string' ),
									// 0.3 S21: present INSTEAD of `markup` for a
									// theme-derived entry — the model sends
									// `{pattern, slots}`, never markup, for these.
									'theme_derived' => array( 'type' => 'boolean' ),
									'slots'         => array(
										'type'  => 'array',
										'items' => array( 'type' => 'object' ),
									),
								),
							),
						),
						// 0.3 S21: the count of the active theme's own
						// patterns that did NOT qualify (never named).
						'theme_patterns_skipped' => array( 'type' => 'integer' ),
						// Names passed in `names` that this vocabulary
						// does not recognise — never fails the whole call.
						'not_found'              => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
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

					$names = array();
					if ( isset( $input['names'] ) && is_array( $input['names'] ) ) {
						$names = array_values(
							array_map(
								static function ( $name ) {
									return (string) $name;
								},
								$input['names']
							)
						);
					}

					return $vocabulary->listPayload( $names );
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
							'items' => array(
								'type' => 'string',
								'enum' => self::READ_FIELDS,
							),
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
							'items' => array(
								'type' => 'string',
								'enum' => self::READ_FIELDS,
							),
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
							'items' => array(
								'type' => 'string',
								'enum' => self::READ_FIELDS,
							),
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
		// 0.3 quality fix (content+sections refusal, 3/11 live runs on
		// posts): `post_type` is REQUIRED here (unlike update-post, where the
		// target post already has one), so a oneOf keyed on it can actually
		// leave `sections` off the `post` branch — the posts pack's own
		// vocabulary never carries a theme pattern, and never offers the
		// `sections` composition form at all (see `sectionsAllowed()`). A
		// model reading this schema for `post_type: "post"` never sees
		// `sections` as a valid property, instead of discovering the refusal
		// only after sending it.
		$shared = array(
			'title'   => array( 'type' => 'string' ),
			'content' => array( 'type' => 'string' ),
			'status'  => array(
				'type' => 'string',
				'enum' => array( 'draft' ),
			),
			'slug'    => array( 'type' => 'string' ),
			'parent'  => array( 'type' => 'integer' ),
			'excerpt' => array( 'type' => 'string' ),
		);

		return array(
			'type'     => 'object',
			'required' => array( 'post_type', 'title' ),
			'default'  => array(),
			'oneOf'    => array(
				array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => array_merge(
						array(
							'post_type' => array(
								'type' => 'string',
								'enum' => array( 'post' ),
							),
						),
						$shared
					),
				),
				array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => array_merge(
						array(
							'post_type' => array(
								'type' => 'string',
								'enum' => array( 'page' ),
							),
						),
						$shared,
						array(
							'sections'        => self::sectionsSchema(),
							'no_image_reason' => self::noImageReasonSchema(),
						)
					),
				),
			),
		);
	}

	/**
	 * 0.3 quality fix 2: a non-empty reason a page write may skip the image
	 * rule ({@see pageImageCheck()}) — a legal page or a short utility page,
	 * say. Shared by create-post (page branch only) and update-post/publish-post.
	 *
	 * @return array<string,mixed>
	 */
	private static function noImageReasonSchema(): array {
		return array(
			'type'        => 'string',
			'description' => __( 'Why this page write skips the image rule (e.g. a legal or short utility page). Accepted only on a page of two sections or fewer; a longer page needs an image, generated when none exists.', 'senroflux' ),
		);
	}

	/**
	 * 0.3 S21: the `sections` array shared by `create-post`/`update-post`/
	 * `publish-post` — a model composes a write from a list of items, each
	 * either curated markup or a theme pattern's name + filled slots. Only
	 * offered to the running pack when {@see sectionsAllowed()} says so, and
	 * refused at execute time for a pack that may not use it (S21: "the
	 * posts pack never sees theme patterns"). 0.3 quality fix: `create-post`
	 * now leaves this OFF the `post_type: "post"` branch of its schema
	 * entirely (see {@see createPostSchema()}'s oneOf), so the posts pack is
	 * never even offered it; `update-post`/`publish-post` still declare it
	 * universally, because their target's post type isn't known from the
	 * input alone (see `sections`'s own description below) — the execute-time
	 * refusal is the only gate left for those two.
	 *
	 * `$describe_layouts` false points at create-post's layout description
	 * instead of repeating it: every tool schema is resent on every turn.
	 *
	 * @return array<string,mixed>
	 */
	private static function sectionsSchema( bool $describe_layouts = true ): array {
		return array(
			'type'        => 'array',
			'description' => __( 'Only accepted for a page (this pack\'s content pack, or when the target is a page). Refused with sections_not_supported for a post.', 'senroflux' ),
			'items'       => array(
				'type'                 => 'object',
				// 0.3 quality fix (readable section refusals, live evidence
				// 2026-09-29-cards1/scenario-1-1): `false` here made a
				// misshapen item (a stray `{label, url}` with no `layout`, or
				// an invented layout whose items carried a `button`) fail
				// core's OWN schema check before {@see Layouts::render()}
				// ever ran, surfacing a generic "X is not a valid property of
				// Object" instead of this pack's own specific message. `true`
				// defers every shape question to `Layouts::render()`, the
				// SAME validation every well-formed section already goes
				// through.
				'additionalProperties' => true,
				'properties'           => array(
					'layout'     => array(
						'type'        => 'string',
						'description' => $describe_layouts ? __( 'Preferred. Name the layout and give its fields; the page is designed from the theme for you. hero: image, heading (≤5 words), text (≤40 words), button — put it first. text: heading (≤9 words), paragraphs (2 to 4, each 40 to 90 words). text-with-image: heading (≤5 words), text (one paragraph, ≤119 words), image. services: heading (≤8 words), items — exactly 3 of {title (≤6 words), text (≤60 words), image}; give every item an image or leave image off every item (no mix) — text-only cards are fine when there are not three distinct, relevant photos. faq: heading (≤5 words), items — exactly 4 of {question (≤12 words), answer (≤60 words)}. cta: heading (≤5 words), text (≤40 words), button — at most one. Outside services, each image slot needs its own image; the alt text goes in image.alt.', 'senroflux' ) . ' Use when, ' . Layouts::useWhenText() . '.' : __( 'The same layouts, fields and limits as create-post.', 'senroflux' ),
					),
					'heading'    => array( 'type' => 'string' ),
					'text'       => array( 'type' => 'string' ),
					'paragraphs' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
					'image'      => self::layoutImageSchema(),
					'button'     => array(
						'type'                 => 'object',
						'additionalProperties' => false,
						'properties'           => array(
							'label' => array( 'type' => 'string' ),
							'url'   => array( 'type' => 'string' ),
						),
					),
					'items'      => array(
						'type'  => 'array',
						'items' => array(
							'type'                 => 'object',
							// Same reasoning as the section item's own
							// `additionalProperties` above — a `button`
							// mistakenly nested inside `items` must reach
							// `Layouts::render()`, not core's schema gate.
							'additionalProperties' => true,
							'properties'           => array(
								'title'    => array( 'type' => 'string' ),
								'text'     => array( 'type' => 'string' ),
								'image'    => self::layoutImageSchema(),
								'question' => array( 'type' => 'string' ),
								'answer'   => array( 'type' => 'string' ),
							),
						),
					),
					'tone'       => array(
						'type'        => 'string',
						'description' => __( 'default, contrast or accent: the section\'s band colour, set from the theme palette. At most 2 per page, never side by side. An image-less hero is contrast unless you set it.', 'senroflux' ),
					),
					'markup'     => array( 'type' => 'string' ),
					'pattern'    => array( 'type' => 'string' ),
					'slots'      => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
				),
			),
		);
	}

	/**
	 * A layout's image: an attachment URL from media-search or
	 * media-generate, plus its alt text.
	 *
	 * @return array<string,mixed>
	 */
	private static function layoutImageSchema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(
				'url' => array( 'type' => 'string' ),
				'alt' => array( 'type' => 'string' ),
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
				'id'              => array( 'type' => 'integer' ),
				'status'          => array(
					'type' => 'string',
					'enum' => array( 'draft' ),
				),
				// 0.3 quality fix: present (true) only when the call sent a
				// non-empty `content` ALONGSIDE `sections` — `sections` was
				// used, `content` was dropped.
				'content_ignored' => array( 'type' => 'boolean' ),
				// 0.3 quality fix 2: echoes the caller's `no_image_reason` back
				// when it was the reason a page write with no image was accepted.
				'no_image_reason' => array( 'type' => 'string' ),
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
				'id'              => array( 'type' => 'integer' ),
				'post_type'       => array(
					'type' => 'string',
					'enum' => array( 'page', 'post' ),
				),
				'title'           => array( 'type' => 'string' ),
				'content'         => array(
					'type'        => 'string',
					'description' => __( 'Block markup. To rewrite a page, send `sections` instead: never edit the markup read-content returned.', 'senroflux' ),
				),
				'sections'        => self::sectionsSchema( false ),
				'status'          => array(
					'type' => 'string',
					'enum' => $status_enum,
				),
				'slug'            => array( 'type' => 'string' ),
				'parent'          => array( 'type' => 'integer' ),
				'excerpt'         => array( 'type' => 'string' ),
				'no_image_reason' => self::noImageReasonSchema(),
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
				'id'              => array( 'type' => 'integer' ),
				'status'          => array( 'type' => 'string' ),
				// 0.3 quality fix: present (true) only when the call sent a
				// non-empty `content` ALONGSIDE `sections` — `sections` was
				// used, `content` was dropped.
				'content_ignored' => array( 'type' => 'boolean' ),
				// 0.3 quality fix 2: echoes the caller's `no_image_reason` back
				// when it was the reason a page write with no image was accepted.
				'no_image_reason' => array( 'type' => 'string' ),
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
	 * 0.3 S21: whether the RUNNING pack may accept a `sections` write item —
	 * only pages and site ("the posts pack never sees theme patterns", and
	 * more generally never offers the `sections` composition form at all).
	 */
	private static function sectionsAllowed(): bool {
		return null !== self::$current_pack && in_array( self::$current_pack, array( 'pages', 'site' ), true );
	}

	/**
	 * 0.3 S21: resolve a call's `content`, honouring the new `sections` form.
	 * Returns null when the call sent no `sections` at all (the caller keeps
	 * its own `content`-only handling unchanged); a WP_Error on any `sections`
	 * refusal; else `{content, content_ignored}` — the joined markup to
	 * validate exactly like hand-authored `content` (S21 decision: one
	 * validation path), plus whether a non-empty `content` was also sent
	 * alongside it and dropped.
	 *
	 * 0.3 quality fix (content+sections refusal, 9/11 live runs): a model's
	 * FIRST write often sends a non-empty `content` summary ALONGSIDE a real
	 * `sections` array — refusing the whole call as `sections_and_content`
	 * taught it nothing about which to keep, so the next turn just resent
	 * the same shape and cost a whole extra round trip. `sections` is the
	 * one form the pack itself renders and validates end to end (theme
	 * pattern slots included); `content` next to it reads as the model's own
	 * prose plan, not markup meant to be stored. Using `sections` and
	 * reporting `content_ignored: true` in the result is the honest,
	 * self-correcting choice: the write still succeeds, and the model sees
	 * plainly which of its two inputs won.
	 *
	 * @param array<string,mixed> $input Call input.
	 * @return array{content:string,content_ignored:bool}|WP_Error|null
	 */
	private static function resolveWriteContent( array $input ): array|WP_Error|null {
		$sections = $input['sections'] ?? null;
		if ( ! is_array( $sections ) || array() === $sections ) {
			return null;
		}

		if ( ! self::sectionsAllowed() ) {
			return new WP_Error(
				'sections_not_supported',
				__( 'This pack does not support the sections form of content.', 'senroflux' ),
				array( 'status' => 400 )
			);
		}

		$rendered = self::renderSections( array_values( $sections ) );
		if ( is_wp_error( $rendered ) ) {
			return $rendered;
		}

		return array(
			'content'         => $rendered,
			'content_ignored' => isset( $input['content'] ) && '' !== (string) $input['content'],
		);
	}

	/** Most `text` layouts allowed back to back before a page reads as a wall of text. */
	private const MAX_TEXT_LAYOUTS_IN_A_ROW = 2;

	/**
	 * Refuse a run of more than {@see MAX_TEXT_LAYOUTS_IN_A_ROW} `text`
	 * layouts: live Services pages built as a hero plus five text sections
	 * scored Visual 2-3 (batches 2026-09-28-fix5, 2026-09-29-final).
	 *
	 * @param list<mixed> $sections The call's `sections` input.
	 */
	private static function wallOfTextCheck( array $sections ): ?WP_Error {
		$run_start = null;
		$run_count = 0;
		foreach ( array_values( $sections ) as $index => $section ) {
			if ( is_array( $section ) && 'text' === ( $section['layout'] ?? null ) ) {
				$run_start = 0 === $run_count ? $index : $run_start;
				++$run_count;
				continue;
			}
			if ( $run_count > self::MAX_TEXT_LAYOUTS_IN_A_ROW ) {
				break;
			}
			$run_count = 0;
		}

		if ( $run_count <= self::MAX_TEXT_LAYOUTS_IN_A_ROW ) {
			return null;
		}

		return new WP_Error(
			'layout_wall_of_text',
			sprintf(
				/* translators: 1: first section number, 2: last section number. */
				__( 'Sections %1$d to %2$d are all `text` layouts in a row, so the page reads as a wall of text. Make one of them `text-with-image` with its own image, or `services` or `faq` where the content fits, or merge two of them.', 'senroflux' ),
				(int) $run_start + 1,
				(int) $run_start + $run_count
			),
			array( 'status' => 400 )
		);
	}

	/**
	 * 0.3 S21: render each `sections` item to markup, in array order, and
	 * join with a blank line. A `markup` item is used verbatim; a
	 * `pattern`/`slots` item is resolved against the running pack's
	 * {@see ThemePatternSource} and filled via {@see ThemePatterns::fill()}.
	 *
	 * @param list<mixed> $sections The call's `sections` input.
	 */
	private static function renderSections( array $sections ): string|WP_Error {
		$wall = self::wallOfTextCheck( $sections );
		if ( null !== $wall ) {
			return $wall;
		}

		$sections   = Tone::withHeroDefaultYielding( $sections );
		$vocabulary = self::currentVocabulary();
		$parts      = array();
		$images     = array();

		foreach ( $sections as $index => $section ) {
			if ( ! is_array( $section ) ) {
				return new WP_Error(
					'section_invalid',
					__( 'Each section must be an object.', 'senroflux' ),
					array(
						'status' => 400,
						'index'  => $index,
					)
				);
			}

			if ( isset( $section['markup'] ) ) {
				$parts[] = (string) $section['markup'];
				continue;
			}

			if ( isset( $section['layout'] ) ) {
				if ( ! $vocabulary instanceof PagesVocabulary ) {
					return new WP_Error(
						'layout_unavailable',
						__( 'Layouts are not available for this pack.', 'senroflux' ),
						array(
							'status' => 400,
							'index'  => $index,
						)
					);
				}

				foreach ( Layouts::imageUrls( $section ) as $url ) {
					if ( isset( $images[ $url ] ) ) {
						// 0.3 quality fix (images budget 0): never point the
						// model at media-generate when the run's images
						// budget is 0 — that ability is withheld from its
						// tool surface entirely (see ToolRegistry::forRun()).
						$reuse_message = self::currentImagesBudgetIsZero()
							? sprintf(
								/* translators: 1: section number, 2: earlier section number. */
								__( 'Section %1$d reuses an image already used in section %2$d. Give each image slot its own image from media-search or stock-image-search/stock-image-import.', 'senroflux' ),
								(int) $index + 1,
								$images[ $url ] + 1
							)
							: sprintf(
								/* translators: 1: section number, 2: earlier section number. */
								__( 'Section %1$d reuses an image already used in section %2$d. Give each image slot its own image: another from media-search, or a new one from media-generate.', 'senroflux' ),
								(int) $index + 1,
								$images[ $url ] + 1
							);

						return new WP_Error(
							'layout_image_reused',
							$reuse_message,
							array(
								'status' => 400,
								'index'  => $index,
							)
						);
					}
					$images[ $url ] = (int) $index;
				}

				$built = Layouts::render( $section, (int) $index, $vocabulary, self::currentImagesBudgetIsZero() );
				if ( $built instanceof WP_Error ) {
					return $built;
				}

				$parts[] = $built;
				continue;
			}

			if ( isset( $section['pattern'] ) ) {
				if ( ! $vocabulary instanceof ThemePatternSource ) {
					return new WP_Error(
						'theme_pattern_unavailable',
						__( 'Theme patterns are not available for this pack.', 'senroflux' ),
						array(
							'status' => 400,
							'index'  => $index,
						)
					);
				}

				if ( $vocabulary instanceof PagesVocabulary && ! $vocabulary->offersThemeSlots() ) {
					return new WP_Error(
						'theme_pattern_unavailable',
						sprintf(
							/* translators: 1: section number, 2: the layout names. */
							__( 'Section %1$d: this pack builds theme patterns for you. Send `layout` (%2$s) with its fields instead of `pattern` and `slots`.', 'senroflux' ),
							(int) $index + 1,
							implode( ', ', Layouts::names() )
						),
						array(
							'status' => 400,
							'index'  => $index,
						)
					);
				}

				$pattern = $vocabulary->resolveThemePattern( (string) $section['pattern'] );
				if ( null === $pattern ) {
					return self::themePatternUnknownError( $vocabulary, (string) $section['pattern'], $index );
				}

				$slots  = array_values( array_map( 'strval', (array) ( $section['slots'] ?? array() ) ) );
				$filled = ThemePatterns::fill( (string) $pattern['markup'], $slots );
				if ( ! $filled['ok'] ) {
					/** @var WP_Error $error */
					$error = $filled['wp_error'];

					return $error;
				}

				$parts[] = $filled['content'];
				continue;
			}

			// 0.3 quality fix (readable section refusals, live evidence
			// 2026-09-29-cards1/scenario-1-1): a stray `{label, url}` object
			// with no `layout` — a button that escaped its section — used to
			// be schema-refused as "label is not a valid property of
			// Object" before this code ever ran. Name the actual mistake
			// instead of falling through to the generic "must supply a
			// layout" message, which says nothing about WHY this shape
			// exists.
			if ( isset( $section['label'] ) || isset( $section['url'] ) ) {
				return new WP_Error(
					'section_invalid',
					sprintf(
						/* translators: %d: section number. */
						__( 'Section %d has no layout; a button belongs inside its section as `button: {label, url}`.', 'senroflux' ),
						(int) $index + 1
					),
					array(
						'status' => 400,
						'index'  => $index,
					)
				);
			}

			return new WP_Error(
				'section_invalid',
				__( 'Each section must supply a layout, markup or a pattern.', 'senroflux' ),
				array(
					'status' => 400,
					'index'  => $index,
				)
			);
		}

		// D3b: read each section's tone back from what was built, so the image-less
		// hero's default and a tone dropped for want of an AA pair both count as
		// they render.
		$tone_error = Tone::pageCheck( array_map( Tone::of( ... ), $parts ) );
		if ( null !== $tone_error ) {
			return $tone_error;
		}

		return implode( "\n\n", $parts );
	}

	/**
	 * 0.3 quality fix (live run, services page): a `sections` item naming a
	 * pattern {@see \Specflux\SenroFlux\Packs\Pages\Vocabulary::resolveThemePattern()}
	 * cannot resolve used to get the SAME generic "not available" message
	 * whether the name was a typo OR one of the pack's own curated
	 * (`senroflux/*`) patterns sent with `{pattern, slots}` — the numbered-slot
	 * form ONLY theme-derived patterns accept (S21). A live run sent
	 * `{"pattern":"senroflux/hero","slots":[...]}` for the curated hero (which
	 * ships full markup and takes no numbered slots at all) and, on refusal,
	 * abandoned every theme pattern in the same call rather than fixing the
	 * one wrong section — because {@see \Specflux\SenroFlux\Tools\ToolExecutor}
	 * only ever surfaces `get_error_message()` to the model, never a WP_Error's
	 * `data` (`index`/`pattern`), the message ITSELF has to name the pattern
	 * and say what is actually wrong with it.
	 *
	 * @param ThemePatternSource $vocabulary The running pack's vocabulary
	 *                                       (already `instanceof
	 *                                       ThemePatternSource` at the call
	 *                                       site — see {@see renderSections()}).
	 * @param string             $name       The section's `pattern` value.
	 * @param int                $index      The section's position, for the WP_Error data.
	 */
	private static function themePatternUnknownError( ThemePatternSource $vocabulary, string $name, int $index ): WP_Error {
		if ( $vocabulary->isCuratedPatternName( $name ) ) {
			return new WP_Error(
				'theme_pattern_unknown',
				sprintf(
					/* translators: %s: the curated pattern's name. */
					__( '"%s" is one of this pack\'s own hand-authored patterns, not a theme one — write its block markup yourself and send it as `markup`, not `slots`.', 'senroflux' ),
					$name
				),
				array(
					'status'  => 400,
					'index'   => $index,
					'pattern' => $name,
				)
			);
		}

		$available = $vocabulary->themePatternNames();

		return new WP_Error(
			'theme_pattern_unknown',
			array() === $available
				? sprintf(
					/* translators: %s: the requested pattern name. */
					__( '"%s" is not an available theme pattern. Call pages/list-patterns for the current names.', 'senroflux' ),
					$name
				)
				: sprintf(
					/* translators: 1: the requested pattern name, 2: comma-separated available theme pattern names. */
					__( '"%1$s" is not an available theme pattern. Available theme patterns: %2$s.', 'senroflux' ),
					$name,
					implode( ', ', $available )
				),
			array(
				'status'  => 400,
				'index'   => $index,
				'pattern' => $name,
			)
		);
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
	 * The permission callback for update-post/publish-post: {@see mayUpdate()},
	 * plus a reason the model can act on when the only thing wrong is which
	 * of the two abilities it picked. A bare `false` reached the model as an
	 * empty error (live run 2026-09-28), and it retried the same call.
	 *
	 * @param array<string,mixed> $input        Call input.
	 * @param bool                $publish_tier True for publish-post.
	 */
	private static function updatePermission( array $input, bool $publish_tier ): bool|WP_Error {
		if ( self::mayUpdate( $input, $publish_tier ) ) {
			return true;
		}

		$post = is_numeric( $input['id'] ?? null ) && function_exists( 'get_post' ) ? get_post( (int) $input['id'] ) : null;
		if ( ! is_object( $post ) || ! self::allowedPostType( (string) $post->post_type ) || ! current_user_can( 'edit_post', (int) $post->ID ) ) {
			return false;
		}

		$current_public = self::isPublicStatus( (string) $post->post_status );
		$desired_public = self::isTransitionStatus( $input['status'] ?? null );

		if ( $publish_tier && ! $current_public && ! $desired_public ) {
			return new WP_Error(
				'publish_needs_status',
				__( 'This is still a draft. To publish it, call publish-post again with "status": "publish" (or "future" with a date to schedule it). To edit the draft, use update-post.', 'senroflux' ),
				array( 'status' => 403 )
			);
		}

		if ( ! $publish_tier && ( $current_public || $desired_public ) ) {
			return new WP_Error(
				'use_publish_post',
				__( 'This change is public: the target is already published, or the call publishes it. Use publish-post instead of update-post.', 'senroflux' ),
				array( 'status' => 403 )
			);
		}

		return false;
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
	private static function readablePost( object $post, array $input ): bool|WP_Error {
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

		$resolved = self::resolveWriteContent( $input );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}

		$content         = null !== $resolved ? $resolved['content'] : (string) ( $input['content'] ?? '' );
		$content_ignored = null !== $resolved && $resolved['content_ignored'];
		$clean           = $validator->clean( $content, array( 'post_type' => $post_type ) );
		if ( ! $clean['ok'] ) {
			/** @var WP_Error $error */
			$error = $clean['wp_error'];

			return $error;
		}

		// 0.3 quality fix: a newly created PAGE must carry at least one image
		// (the run 5/6 baseline had none — Visual capped at 1), UNLESS the
		// caller states `no_image_reason` (0.3 quality fix 2 — see
		// self::pageImageCheck()). Never enforced on a post (the Posts pack
		// already asks for images on its own terms). Filterable per site
		// (`senroflux_require_page_image`, default true): a host with no media
		// library worth using can turn it off outright.
		$image_check = self::pageImageCheck( $post_type, $clean['content'], $input );
		if ( $image_check instanceof WP_Error ) {
			return $image_check;
		}

		$copy_check = self::pageCopyCheck( $post_type, $clean['content'] );
		if ( null !== $copy_check ) {
			return $copy_check;
		}

		$featured_check = self::featuredImageInContentCheck( $post_type, 0, $clean['content'] );
		if ( null !== $featured_check ) {
			return $featured_check;
		}

		$hero_check = self::heroReuseCheck( $post_type, $clean['content'], 0 );
		if ( null !== $hero_check ) {
			return $hero_check;
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

		// One-H1 fix (0.3 quality feature 2): a hero pattern's own H1 plus
		// the theme's `page.html` printing the post title as a second H1 —
		// assign the theme's `page-no-title` template instead, when it ships
		// one, so the hero's H1 stays the only one. Never for a post: only a
		// PAGE template ever prints the title as an H1 in the first place.
		if ( function_exists( 'update_post_meta' ) && HeroTemplate::shouldAssign( $post_type, $clean['content'] ) ) {
			update_post_meta( (int) $id, '_wp_page_template', HeroTemplate::NO_TITLE_TEMPLATE );
		}

		// S8: "an object the run CREATED counts as read at creation" — the
		// run may update it in the same run without ever calling read-content.
		$created = function_exists( 'get_post' ) ? get_post( (int) $id ) : null;
		self::recordReadMarker( (int) $id, is_object( $created ) ? (string) $created->post_modified_gmt : '' );

		$result = array(
			'id'     => (int) $id,
			'status' => 'draft',
		);
		if ( $content_ignored ) {
			$result['content_ignored'] = true;
		}
		if ( is_string( $image_check ) ) {
			$result['no_image_reason'] = $image_check;
		}

		return $result;
	}

	/**
	 * 0.3 quality fix 2 (live runs: an update-post that fully replaces a
	 * page's content — including turning it into the front page — is exempt
	 * from create's image rule, so a run's most important page could end up
	 * with none). Shared by create-post (always "full content") and
	 * update-post (only when the call supplies `content`/`sections` — a
	 * metadata-only edit, or one that leaves content untouched, is never a
	 * page write in the sense this rule means).
	 *
	 * Returns:
	 *  - WP_Error `page_needs_image` — refuse the write.
	 *  - string   the caller's `no_image_reason` — accepted; the caller
	 *             echoes it in the tool result so it is visible in the
	 *             transcript.
	 *  - null     no image is required (not a page, the filter is off, or an
	 *             image is already present — nothing to record).
	 *
	 * @param string               $post_type The post being written.
	 * @param string               $content   The CLEANED markup the write is about to store.
	 * @param array<string,mixed>  $input     Call input (for `no_image_reason`).
	 */
	private static function pageImageCheck( string $post_type, string $content, array $input ): WP_Error|string|null {
		/** Filters whether a page write must include an image step. `@internal`. */
		if ( 'page' !== $post_type || ! apply_filters( 'senroflux_require_page_image', true ) ) {
			return null;
		}

		$blocks = array_values( parse_blocks( $content ) );
		if ( HasImage::present( $blocks ) ) {
			return null;
		}

		$sections = count( array_filter( $blocks, static fn ( array $block ): bool => null !== ( $block['blockName'] ?? null ) ) );
		$reason   = $input['no_image_reason'] ?? null;
		if ( is_string( $reason ) && '' !== trim( $reason ) ) {
			if ( $sections <= self::NO_IMAGE_MAX_SECTIONS ) {
				return $reason;
			}

			// Stock-photo-fallback build plan, point 4 (the escape hatch): a
			// long page is normally still refused with a bare `no_image_reason`
			// — but when this run genuinely has no image source left (the
			// `images` budget is exhausted AND stock search is either
			// disabled or already tried and came back empty/erroring, per
			// {@see Media::noImageSourceLeft()}), the reason is accepted at
			// ANY length rather than dead-ending a run whose model cannot
			// generate images at all.
			if ( Media::noImageSourceLeft() ) {
				return $reason;
			}

			$images_budget_zero = self::currentImagesBudgetIsZero();

			return new WP_Error(
				'page_needs_image',
				$images_budget_zero
					? sprintf(
						/* translators: %1$d: most sections a page may have without an image, %2$d: sections on this page. */
						__( '`no_image_reason` is only for short pages of %1$d sections or fewer; this page has %2$d. Add an image: this run has no image-generation budget left, so use `media-search` first, then `stock-image-search`.', 'senroflux' ),
						self::NO_IMAGE_MAX_SECTIONS,
						$sections
					)
					: sprintf(
						/* translators: %1$d: most sections a page may have without an image, %2$d: sections on this page. */
						__( '`no_image_reason` is only for short pages of %1$d sections or fewer; this page has %2$d. Add an image: when `media-search` finds nothing suitable, call `media-generate`; if the budget is exhausted, try `stock-image-search`.', 'senroflux' ),
						self::NO_IMAGE_MAX_SECTIONS,
						$sections
					),
				array( 'status' => 400 )
			);
		}

		return new WP_Error(
			'page_needs_image',
			self::currentImagesBudgetIsZero()
				? __( 'A page with content needs at least one image, or a non-empty `no_image_reason` explaining why not (a legal page or a short utility page, say). Add a `cover-hero` or `media-text` section (see `list-patterns`), or a theme pattern with an image slot. This run has no image-generation budget left: use `media-search` first, then `stock-image-search` before giving up. Every image needs alt text.', 'senroflux' )
				: __( 'A page with content needs at least one image, or a non-empty `no_image_reason` explaining why not (a legal page or a short utility page, say). Add a `cover-hero` or `media-text` section (see `list-patterns`), or a theme pattern with an image slot. Use `media-search` first; only call `media-generate` when nothing suitable exists; if the budget is exhausted, try `stock-image-search` before giving up. Every image needs alt text.', 'senroflux' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Refuses a page whose curated text sections are too thin to be useful
	 * (0.3 quality fix: copy depth). Same moments as {@see pageImageCheck()}:
	 * a new page, or an update that rewrites the content. Filterable per site
	 * (`senroflux_min_section_words`; return an empty array to turn it off).
	 *
	 * @param string $content The validated page content.
	 */
	private static function pageCopyCheck( string $post_type, string $content ): ?WP_Error {
		if ( 'page' !== $post_type ) {
			return null;
		}

		/**
		 * Filters the per-section minimum word counts. `@internal`.
		 *
		 * @var array<string, array{0:int, 1:bool}> $minimums
		 */
		$minimums = (array) apply_filters( 'senroflux_min_section_words', ThinCopy::MINIMUMS );
		$thin     = ThinCopy::first( array_values( parse_blocks( $content ) ), $minimums );
		if ( null === $thin ) {
			return null;
		}

		return new WP_Error(
			'section_too_thin',
			sprintf(
				/* translators: %1$d: section index, %2$s: pattern name, %3$d: words written, %4$d: minimum words, %5$d: words to add. */
				__( 'Section %1$d (%2$s) has %3$d words of body copy (headings do not count); write at least %4$d (per column for a feature-grid), so add at least %5$d more. Say who it is for, what happens, and why it matters to the visitor, using the facts you were given and general expertise — never invented prices, credentials or results.', 'senroflux' ),
				$thin['index'],
				$thin['pattern'],
				$thin['words'],
				$thin['minimum'],
				$thin['minimum'] - $thin['words']
			),
			array(
				'status'  => 400,
				'index'   => $thin['index'],
				'words'   => $thin['words'],
				'minimum' => $thin['minimum'],
			)
		);
	}

	/**
	 * Bug 2 (live run: a café photo repeated at the top of a post) — the
	 * mirrored half of {@see \Specflux\SenroFlux\Packs\Content\Media::executeSetFeaturedImage()}'s
	 * refusal: content being written to a POST that already carries this
	 * same image as its `_thumbnail_id` would show it twice, since TT25's
	 * `single.html` prints the featured image above the content. `$post_id`
	 * is 0 on create (a brand-new post never has a featured image yet), so
	 * this is a no-op there — the check exists on both write paths only so
	 * neither ever drifts out of sync with the other.
	 *
	 * @param string $post_type The post type being written.
	 * @param int    $post_id   The post's id, or 0 on create.
	 * @param string $content   The CLEANED markup the write is about to store.
	 */
	private static function featuredImageInContentCheck( string $post_type, int $post_id, string $content ): ?WP_Error {
		if ( 'post' !== $post_type || 0 === $post_id || ! function_exists( 'get_post_meta' ) || ! function_exists( 'wp_get_attachment_url' ) ) {
			return null;
		}

		$thumbnail_id = (int) get_post_meta( $post_id, '_thumbnail_id', true );
		if ( 0 === $thumbnail_id ) {
			return null;
		}

		$url = wp_get_attachment_url( $thumbnail_id );
		if ( ! is_string( $url ) || '' === $url || ! ContentImages::containsUrl( $content, $url ) ) {
			return null;
		}

		return new WP_Error(
			'featured_image_duplicated',
			__( 'This post\'s content includes the image that is already its featured image, and the theme shows the featured image above the post, so it would appear twice. Remove it from the content, or set-featured-image to a different attachment.', 'senroflux' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Bug 1 (live run: the same hero image on every page) — refuses a
	 * page's hero (its FIRST cover section, {@see ContentImages::firstCoverImage()})
	 * when the SAME attachment (matched by url and/or id) is already the
	 * hero of ANOTHER published or draft page. `$exclude_id` is the page
	 * being written itself (0 on create, where it can never match anything).
	 * Only the hero is refused here — a non-hero image slot reused across
	 * pages is untouched (the in-page `layout_image_reused` check above is
	 * the only cross-slot rule for those).
	 *
	 * @param string $post_type  The post type being written.
	 * @param string $content    The CLEANED markup the write is about to store.
	 * @param int    $exclude_id The page's own id (0 on create).
	 */
	private static function heroReuseCheck( string $post_type, string $content, int $exclude_id ): ?WP_Error {
		if ( 'page' !== $post_type ) {
			return null;
		}

		$hero   = ContentImages::firstCoverImage( $content );
		$images = ContentImages::urls( $content );
		if ( null === $hero && array() === $images ) {
			return null;
		}

		if ( ! class_exists( '\WP_Query' ) ) {
			return null;
		}

		$query = new \WP_Query(
			array(
				'post_type'      => 'page',
				'post_status'    => self::COLLISION_STATUSES,
				'posts_per_page' => -1,
			)
		);

		foreach ( (array) ( $query->posts ?? array() ) as $other ) {
			if ( ! is_object( $other ) ) {
				continue;
			}

			$other_id = (int) $other->ID;
			if ( $other_id === $exclude_id ) {
				continue;
			}

			$title      = (string) $other->post_title;
			$other_hero = ContentImages::firstCoverImage( (string) $other->post_content );
			$same_url   = null !== $hero && null !== $other_hero && '' !== $hero['url'] && $hero['url'] === $other_hero['url'];
			$same_id    = null !== $hero && null !== $other_hero && 0 !== $hero['id'] && $hero['id'] === $other_hero['id'];
			if ( ! $same_url && ! $same_id ) {
				$shared = array_values( array_intersect( $images, ContentImages::urls( (string) $other->post_content ) ) );
				if ( array() === $shared ) {
					continue;
				}

				return new WP_Error(
					'image_reused_across_pages',
					sprintf(
						/* translators: 1: image file name, 2: the other page's title, 3: the other page's id, 4: where to find another image. */
						__( 'The image %1$s is already on "%2$s" (page %3$d). Give each page its own photos: %4$s.', 'senroflux' ),
						basename( (string) $shared[0] ),
						$title,
						$other_id,
						self::currentImagesBudgetIsZero()
							? __( 'pick another from media-search, or stock-image-search then stock-image-import', 'senroflux' )
							: __( 'pick another from media-search, try media-generate for a new one, or stock-image-search then stock-image-import', 'senroflux' )
					),
					array( 'status' => 400 )
				);
			}

			return new WP_Error(
				'hero_image_reused',
				self::currentImagesBudgetIsZero()
					? sprintf(
						/* translators: 1: the other page's title, 2: the other page's id. */
						__( 'The hero image is already the hero on "%1$s" (page %2$d). Give this page its own hero: pick another from media-search, or stock-image-search then stock-image-import.', 'senroflux' ),
						$title,
						$other_id
					)
					: sprintf(
						/* translators: 1: the other page's title, 2: the other page's id. */
						__( 'The hero image is already the hero on "%1$s" (page %2$d). Give this page its own hero: pick another from media-search, try media-generate for a new one, or stock-image-search then stock-image-import.', 'senroflux' ),
						$title,
						$other_id
					),
				array( 'status' => 400 )
			);
		}

		return null;
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
	 * The same S8 refusal for an object this run never read (a list query
	 * records nothing), worded so the model knows the fix.
	 *
	 * @return WP_Error stale_write (409).
	 */
	private static function unreadWriteError(): WP_Error {
		return new WP_Error(
			'stale_write',
			__( 'This run has not read this item yet. Read it with read-content by its id, then write again.', 'senroflux' ),
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
			return array_key_exists( (string) ( $post->ID ?? 0 ), self::currentObjects() )
				? self::staleWriteError()
				: self::unreadWriteError();
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
		$resolved = self::resolveWriteContent( $input );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}

		$new_content     = null !== $resolved ? $resolved['content'] : ( isset( $input['content'] ) ? (string) $input['content'] : '' );
		$content_ignored = null !== $resolved && $resolved['content_ignored'];
		$post_type       = array( 'post_type' => (string) ( $post->post_type ?? 'page' ) );

		// One-H1 fix (0.3 quality feature 2): tracks whichever content ends
		// up stored — the newly validated markup, or (unchanged) the
		// post's existing content — so the template check below always
		// looks at what the page will actually render, never stale input.
		$final_content = (string) ( $post->post_content ?? '' );

		// 0.3 quality fix 2: an update that supplies FULL content is the same
		// "what this run produced" moment create-post's image rule already
		// covers — a page turned into the front page via update-post (a live
		// run did exactly this) is otherwise exempt and can end up with no
		// image at all. A metadata-only update, or one that leaves content
		// untouched, never reaches this branch.
		$no_image_reason = null;

		if ( '' !== $new_content ) {
			$clean = $validator->clean( $new_content, $post_type );
			if ( ! $clean['ok'] ) {
				/** @var WP_Error $error */
				$error = $clean['wp_error'];

				return $error;
			}

			$image_check = self::pageImageCheck( (string) ( $post->post_type ?? '' ), $clean['content'], $input );
			if ( $image_check instanceof WP_Error ) {
				return $image_check;
			}

			$copy_check = self::pageCopyCheck( (string) ( $post->post_type ?? '' ), $clean['content'] );
			if ( null !== $copy_check ) {
				return $copy_check;
			}

			$featured_check = self::featuredImageInContentCheck( (string) ( $post->post_type ?? '' ), (int) ( $post->ID ?? 0 ), $clean['content'] );
			if ( null !== $featured_check ) {
				return $featured_check;
			}

			$hero_check = self::heroReuseCheck( (string) ( $post->post_type ?? '' ), $clean['content'], (int) ( $post->ID ?? 0 ) );
			if ( null !== $hero_check ) {
				return $hero_check;
			}
			if ( is_string( $image_check ) ) {
				$no_image_reason = $image_check;
			}

			$args['post_content'] = $clean['content'];
			$final_content        = $clean['content'];
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

		if ( function_exists( 'update_post_meta' ) && HeroTemplate::shouldAssign( (string) ( $post->post_type ?? '' ), $final_content ) ) {
			update_post_meta( (int) ( $post->ID ?? 0 ), '_wp_page_template', HeroTemplate::NO_TITLE_TEMPLATE );
		}

		// S8: update the recorded marker to the post's NEW modified time —
		// re-fetched fresh, never the stale `$post` in hand — so this run may
		// keep editing its own writes without an intervening read-content
		// call. A run parked mid-write and resumed still compares against the
		// marker THIS write left, exactly as if it had re-read.
		$fresh = function_exists( 'get_post' ) ? get_post( (int) ( $post->ID ?? 0 ) ) : null;
		self::recordReadMarker( (int) ( $post->ID ?? 0 ), is_object( $fresh ) ? (string) $fresh->post_modified_gmt : '' );

		$result = array(
			'id'     => (int) ( $post->ID ?? 0 ),
			'status' => is_string( $status ) ? $status : (string) ( $post->post_status ?? '' ),
		);
		if ( $content_ignored ) {
			$result['content_ignored'] = true;
		}
		if ( null !== $no_image_reason ) {
			$result['no_image_reason'] = $no_image_reason;
		}

		return $result;
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
		$field_set = array_flip( array_map( static fn ( string $field ): string => self::FIELD_ALIASES[ $field ] ?? $field, $fields ) );
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
