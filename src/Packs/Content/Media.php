<?php
/**
 * The media/attachment/term registrar shared by every content pack (0.3 S5,
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Packs\Content;

use Specflux\SenroFlux\Model\AiClientMediaGateway;
use Specflux\SenroFlux\Model\MediaGatewayInterface;
use Specflux\SenroFlux\Model\OpenverseStockImageGateway;
use Specflux\SenroFlux\Model\StockImageGatewayInterface;
use Specflux\SenroFlux\Run\Budget;
use Specflux\SenroFlux\Run\RunStore;
use WP_Error;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Registers the shared media/attachment/term abilities.
 *
 * stage 6; `read-media` added by the report defect fix; `stock-image-search`/
 * `stock-image-import` added by the stock-photo-fallback build plan):
 * `media-search`, `media-upload`, `generate-image`, `generate-alt-text`,
 * `set-featured-image`, `list-missing-alt`, `update-alt`, `read-media`,
 * `set-terms`, `create-term`, `stock-image-search`, `stock-image-import`.
 *
 * TARGET REPO PATH: src/Packs/Content/Media.php
 *
 * Pack-agnostic like {@see Abilities}: any content pack that declares these
 * roles gets the same abilities. Only the posts pack does, at 0.3.
 *
 * MEDIA-UPLOAD SHAPE DECISION (S5 point of ambiguity, flagged and resolved
 * per the build plan rather than guessed dangerously): the model never
 * supplies image BYTES. `media-upload` registers a file that ALREADY exists
 * on disk under the uploads directory — typically one `generate-image` just
 * saved there — as a real attachment. It never accepts base64/data the model
 * invents, and refuses a path that resolves outside the uploads base dir.
 *
 * IMAGES BUDGET (S5): `generate-image` is the only spender. The count is
 * DERIVED from the run's own step history (no new schema): every prior
 * `tool_result` step in this run whose ability base name is `generate-image`
 * and whose status is `ok` counts as one spend. This keeps {@see Budget}
 * itself domain-agnostic — it never learns what "images" means — while still
 * giving the ability a real, persistent count across ticks.
 *
 * ATTACHMENT CAP (S5): `update-alt` refuses a 26th DISTINCT attachment in one
 * run. Tracked in the SAME `objects_json` map {@see \Specflux\SenroFlux\Run\Tracker}
 * already uses for the S12 re-read nudge, under keys prefixed `media-alt:` so
 * an attachment id can never collide with a post/page id recorded by
 * {@see Abilities}.
 *
 * STALE WRITE (S8) — DECISION: `update-alt`, `set-featured-image` and
 * `set-terms` do NOT go through {@see Abilities}'s create/update/publish
 * path (they touch post meta, the featured-image pointer, or a taxonomy
 * relationship — never `post_content`), so S8's `post_modified_gmt` compare
 * has nothing to compare against here. This class does not reproduce it;
 * documented rather than silently skipped, per the build plan.
 *
 * CAPABILITIES. `upload_files` gates the two abilities that CREATE an
 * attachment (`media-upload`, `generate-image` — S6's withheld roles);
 * every other write here gates on the WordPress capability for the object it
 * actually touches (`edit_post` for a target post/attachment,
 * `manage_terms`/`assign_terms` for a taxonomy) — never on `upload_files`,
 * which is not what those calls do.
 */
final class Media {

	/**
	 * The ability category for the media abilities.
	 */
	public const CATEGORY = 'senroflux-media';

	/**
	 * `list-missing-alt` never returns more than this many attachments, and
	 * `update-alt` never lets one run touch more than this many DISTINCT
	 * attachments (S5).
	 */
	public const ATTACHMENT_CAP = 25;

	/**
	 * The `media-alt:` key prefix inside a run's `objects_json` map — kept
	 * distinct from a post/page id recorded by {@see Abilities}. Pack-internal
	 * bookkeeping ONLY, for the S5 25-attachment cap; never surfaced in the
	 * S12 report (contrast {@see OBJECT_ID_PREFIX}).
	 */
	private const ALT_KEY_PREFIX = 'media-alt:';

	/**
	 * The type-qualified id prefix an attachment carries in the S12
	 * written-object set (defect fix): {@see \Specflux\SenroFlux\Packs\Pack::objectIdPrefix()}
	 * for `posts/update-alt` and `posts/read-media` names this, so the
	 * generic {@see \Specflux\SenroFlux\Run\Runner} write/verify tracking
	 * never collides an attachment id with a post id sharing the same
	 * number. The composition root's report lookup (wired in Plugin.php)
	 * strips it back off before calling {@see attachmentLookup()} — the
	 * harness itself (`src/Run`) never parses it.
	 */
	public const OBJECT_ID_PREFIX = 'attachment:';

	/**
	 * Cap on the file `generateAltText()` will inline as base64 — well above
	 * anything a WordPress intermediate size produces, but an explicit fail
	 * closed rather than an unbounded request body should a site's
	 * intermediate sizes be disabled and the ORIGINAL file used instead.
	 */
	private const ALT_TEXT_MAX_FILE_BYTES = 10 * 1024 * 1024;

	/**
	 * `stock-image-import` refuses a 4th call in one run — the escape hatch
	 * this pairs with ({@see noImageSourceLeft()}) is meant to unblock a run
	 * that genuinely cannot get an image, not to substitute stock search for
	 * unbounded free image sourcing.
	 */
	public const STOCK_IMPORT_CAP = 3;

	/**
	 * 0.3 quality fix (stock image choice): live runs picked 3D renders,
	 * illustrations and engravings for pages that needed a real photo — the
	 * model only ever sees what {@see executeStockImageSearch()} returns, so
	 * this filters the PROVIDER's results rather than relying on the model
	 * to judge a title/tag itself. Matched as whole words/phrases (never a
	 * bare substring — "illustrated guide" is a real photo's title, not a
	 * drawing) against a result's title and tags, case-insensitively.
	 *
	 * @var list<string>
	 */
	private const NON_PHOTO_KEYWORDS = array(
		'3d render',
		'3d rendering',
		'3d',
		'illustration',
		'engraving',
		'drawing',
		'vector',
		'clip art',
		'clipart',
		'cartoon',
		'painting',
		'sculpture',
		'statue',
		'mannequin',
		'cgi',
		// Dated photos: 1918 Walter Reed shots reached a clinic's pages
		// (live batch 2026-09-29-seed2).
		'vintage',
		'retro',
		'antique',
		'history',
		'historical',
		'black and white',
	);

	/**
	 * `stock-image-import` records the provider id it downloaded under this
	 * key (a plain string, alongside the full `_senroflux_stock_source`
	 * record) so {@see usedStockImageIds()} can find every already-imported
	 * image with one `get_posts()` meta lookup, without decoding the full
	 * record for every attachment on the site.
	 *
	 * @var string
	 */
	private const STOCK_SOURCE_ID_META_KEY = '_senroflux_stock_source_id';

	/**
	 * Whether {@see register()} has run for this request.
	 */
	private static bool $registered = false;

	/**
	 * Test seam: overrides the real {@see AiClientMediaGateway}.
	 */
	private static ?MediaGatewayInterface $gateway = null;

	/**
	 * Test seam: overrides the real {@see OpenverseStockImageGateway}.
	 */
	private static ?StockImageGatewayInterface $stock_gateway = null;

	/**
	 * The ticking run's id, or null outside one. Scoped per tick by
	 * {@see useRunContext()} / {@see forgetRunContext()} — same discipline as
	 * {@see Abilities::useRunContext()}.
	 */
	private static ?int $current_run_id = null;

	/**
	 * The store used to read/persist the current run's row (0.3 S5).
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
	 * Forget the per-request registered flag (test-only).
	 */
	public static function reset(): void {
		self::$registered    = false;
		self::$gateway       = null;
		self::$stock_gateway = null;
	}

	/**
	 * Test seam: override the media gateway. Null restores the real one.
	 */
	public static function setGateway( ?MediaGatewayInterface $gateway ): void {
		self::$gateway = $gateway;
	}

	/**
	 * Test seam: override the stock-image gateway. Null restores the real one.
	 */
	public static function setStockGateway( ?StockImageGatewayInterface $gateway ): void {
		self::$stock_gateway = $gateway;
	}

	/**
	 * Enter the run context for one tick: which run's row backs the `images`
	 * spend count and the attachment cap. Set by the composition root from
	 * the ticking run's id, NEVER from the model.
	 *
	 * @param int|null      $run_id The ticking run's id, or null.
	 * @param RunStore|null $store  The store backing that run, or null.
	 */
	public static function useRunContext( ?int $run_id, ?RunStore $store ): void {
		self::$current_run_id = $run_id;
		self::$store          = $store;
	}

	/**
	 * Leave the run context.
	 */
	public static function forgetRunContext(): void {
		self::$current_run_id = null;
		self::$store          = null;
	}

	/**
	 * Register the category.
	 */
	public static function registerCategory(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'SenroFlux media', 'senroflux' ),
				'description' => __( 'Media, attachment and taxonomy abilities shared by SenroFlux capability packs.', 'senroflux' ),
			)
		);
	}

	/**
	 * Register the twelve abilities. Idempotent per request.
	 */
	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		self::registerMediaSearch();
		self::registerListMissingAlt();
		self::registerMediaUpload();
		self::registerGenerateImage();
		self::registerGenerateAltText();
		self::registerSetFeaturedImage();
		self::registerUpdateAlt();
		self::registerReadMedia();
		self::registerSetTerms();
		self::registerCreateTerm();
		self::registerStockImageSearch();
		self::registerStockImageImport();
	}

	// ------------------------------------------------------------------
	// Registration
	// ------------------------------------------------------------------

	private static function registerMediaSearch(): void {
		wp_register_ability(
			'senroflux/media-search',
			array(
				'label'               => __( 'Search media', 'senroflux' ),
				'description'         => __( 'Search the media library for an existing image before generating a new one.', 'senroflux' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'query' ),
					'additionalProperties' => false,
					'properties'           => array(
						'query' => array( 'type' => 'string' ),
					),
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'required'             => array( 'results' ),
					'additionalProperties' => false,
					'properties'           => array(
						'results' => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'id'           => array( 'type' => 'integer' ),
									'url'          => array( 'type' => 'string' ),
									'title'        => array( 'type' => 'string' ),
									'alt'          => array( 'type' => 'string' ),
									'already_used' => array( 'type' => 'boolean' ),
								),
							),
						),
					),
				),
				'execute_callback'    => static function ( $input = array() ) {
					return self::executeMediaSearch( is_array( $input ) ? $input : array() );
				},
				'permission_callback' => static function ( $input = array() ) {
					unset( $input );

					return function_exists( 'current_user_can' ) && current_user_can( 'edit_posts' );
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

	private static function registerListMissingAlt(): void {
		wp_register_ability(
			'senroflux/list-missing-alt',
			array(
				'label'               => __( 'List images missing alt text', 'senroflux' ),
				'description'         => __( 'List up to 25 image attachments that have no alt text.', 'senroflux' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'default'              => array(),
					'properties'           => array(),
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'required'             => array( 'attachments' ),
					'additionalProperties' => false,
					'properties'           => array(
						'attachments' => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'id'       => array( 'type' => 'integer' ),
									'url'      => array( 'type' => 'string' ),
									'filename' => array( 'type' => 'string' ),
								),
							),
						),
					),
				),
				'execute_callback'    => static function ( $input = array() ) {
					unset( $input );

					return self::executeListMissingAlt();
				},
				'permission_callback' => static function ( $input = array() ) {
					unset( $input );

					return function_exists( 'current_user_can' ) && current_user_can( 'edit_posts' );
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

	private static function registerMediaUpload(): void {
		wp_register_ability(
			'senroflux/media-upload',
			array(
				'label'               => __( 'Register an uploaded image', 'senroflux' ),
				'description'         => __( 'Register a file already present in the uploads directory (e.g. a generated image) as a real attachment.', 'senroflux' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'file_path' ),
					'additionalProperties' => false,
					'properties'           => array(
						'file_path' => array( 'type' => 'string' ),
					),
				),
				'output_schema'       => self::attachmentOutputSchema(),
				'execute_callback'    => static function ( $input = array() ) {
					return self::executeMediaUpload( is_array( $input ) ? $input : array() );
				},
				'permission_callback' => static function ( $input = array() ) {
					unset( $input );

					return function_exists( 'current_user_can' ) && current_user_can( 'upload_files' );
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

	private static function registerGenerateImage(): void {
		wp_register_ability(
			'senroflux/generate-image',
			array(
				'label'               => __( 'Generate an image', 'senroflux' ),
				'description'         => __( 'Generate a new image from a prompt and register it as an attachment. Spends the images budget.', 'senroflux' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'prompt' ),
					'additionalProperties' => false,
					'properties'           => array(
						'prompt' => array( 'type' => 'string' ),
					),
				),
				'output_schema'       => self::attachmentOutputSchema(),
				'execute_callback'    => static function ( $input = array() ) {
					return self::executeGenerateImage( is_array( $input ) ? $input : array() );
				},
				'permission_callback' => static function ( $input = array() ) {
					unset( $input );

					return function_exists( 'current_user_can' ) && current_user_can( 'upload_files' );
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

	private static function registerGenerateAltText(): void {
		wp_register_ability(
			'senroflux/generate-alt-text',
			array(
				'label'               => __( 'Draft alt text', 'senroflux' ),
				'description'         => __( 'Ask the model to draft alt text for an attachment. Does not save it — call update-alt to persist it.', 'senroflux' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'attachment_id' ),
					'additionalProperties' => false,
					'properties'           => array(
						'attachment_id' => array( 'type' => 'integer' ),
					),
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'required'             => array( 'alt' ),
					'additionalProperties' => false,
					'properties'           => array(
						'alt' => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => static function ( $input = array() ) {
					return self::executeGenerateAltText( is_array( $input ) ? $input : array() );
				},
				'permission_callback' => static function ( $input = array() ) {
					$input = is_array( $input ) ? $input : array();

					return self::mayEditAttachment( (int) ( $input['attachment_id'] ?? 0 ) );
				},
				'meta'                => self::meta(
					array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => false,
					)
				),
			)
		);
	}

	private static function registerSetFeaturedImage(): void {
		wp_register_ability(
			'senroflux/set-featured-image',
			array(
				'label'               => __( 'Set featured image', 'senroflux' ),
				'description'         => __( 'Set an existing attachment as a post\'s featured image.', 'senroflux' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'post_id', 'attachment_id' ),
					'additionalProperties' => false,
					'properties'           => array(
						'post_id'       => array( 'type' => 'integer' ),
						'attachment_id' => array( 'type' => 'integer' ),
					),
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'required'             => array( 'post_id' ),
					'additionalProperties' => false,
					'properties'           => array(
						'post_id' => array( 'type' => 'integer' ),
					),
				),
				'execute_callback'    => static function ( $input = array() ) {
					return self::executeSetFeaturedImage( is_array( $input ) ? $input : array() );
				},
				'permission_callback' => static function ( $input = array() ) {
					$input = is_array( $input ) ? $input : array();

					return function_exists( 'current_user_can' ) && current_user_can( 'edit_post', (int) ( $input['post_id'] ?? 0 ) );
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

	private static function registerUpdateAlt(): void {
		wp_register_ability(
			'senroflux/update-alt',
			array(
				'label'               => __( 'Update alt text', 'senroflux' ),
				'description'         => __( 'Save alt text for an attachment. Refuses a 26th distinct attachment in one run.', 'senroflux' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'attachment_id', 'alt' ),
					'additionalProperties' => false,
					'properties'           => array(
						'attachment_id' => array( 'type' => 'integer' ),
						'alt'           => array( 'type' => 'string' ),
					),
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'required'             => array( 'attachment_id' ),
					'additionalProperties' => false,
					'properties'           => array(
						'attachment_id' => array( 'type' => 'integer' ),
					),
				),
				'execute_callback'    => static function ( $input = array() ) {
					return self::executeUpdateAlt( is_array( $input ) ? $input : array() );
				},
				'permission_callback' => static function ( $input = array() ) {
					$input = is_array( $input ) ? $input : array();

					return self::mayEditAttachment( (int) ( $input['attachment_id'] ?? 0 ) );
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
	 * `read-media` (defect fix, S12/S8): the Tier-0 read that lets the model
	 * verify a write it just made through `update-alt`/`media-upload`/
	 * `generate-image` — none of those abilities can be independently
	 * re-confirmed today, which is exactly what left the live run's report
	 * showing an attachment as "unchecked after the change" with no way to
	 * clear it. A NEW ability (rather than overloading `media-search` with
	 * an `id` branch) keeps that ability's `query`-only shape simple and
	 * gives this one a clear, single-purpose name and schema.
	 */
	private static function registerReadMedia(): void {
		wp_register_ability(
			'senroflux/read-media',
			array(
				'label'               => __( 'Read attachment details', 'senroflux' ),
				'description'         => __( 'Re-read one attachment by id: its alt text, title, file, dimensions, and which posts use it as a featured image. Use this to confirm an alt-text or upload change actually saved.', 'senroflux' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'attachment_id' ),
					'additionalProperties' => false,
					'properties'           => array(
						'attachment_id' => array( 'type' => 'integer' ),
					),
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'required'             => array( 'attachment_id' ),
					'additionalProperties' => false,
					'properties'           => array(
						'attachment_id' => array( 'type' => 'integer' ),
						'alt'           => array( 'type' => 'string' ),
						'title'         => array( 'type' => 'string' ),
						'mime_type'     => array( 'type' => 'string' ),
						'url'           => array( 'type' => 'string' ),
						'width'         => array( 'type' => array( 'integer', 'null' ) ),
						'height'        => array( 'type' => array( 'integer', 'null' ) ),
						'featured_on'   => array(
							'type'  => 'array',
							'items' => array( 'type' => 'integer' ),
						),
					),
				),
				'execute_callback'    => static function ( $input = array() ) {
					return self::executeReadMedia( is_array( $input ) ? $input : array() );
				},
				'permission_callback' => static function ( $input = array() ) {
					$input = is_array( $input ) ? $input : array();

					return self::mayEditAttachment( (int) ( $input['attachment_id'] ?? 0 ) );
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

	private static function registerSetTerms(): void {
		wp_register_ability(
			'senroflux/set-terms',
			array(
				'label'               => __( 'Set terms', 'senroflux' ),
				'description'         => __( 'Replace a post\'s terms in one taxonomy.', 'senroflux' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'post_id', 'taxonomy', 'term_ids' ),
					'additionalProperties' => false,
					'properties'           => array(
						'post_id'  => array( 'type' => 'integer' ),
						'taxonomy' => array( 'type' => 'string' ),
						'term_ids' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'integer' ),
						),
					),
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'required'             => array( 'post_id', 'term_ids' ),
					'additionalProperties' => false,
					'properties'           => array(
						'post_id'  => array( 'type' => 'integer' ),
						'term_ids' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'integer' ),
						),
					),
				),
				'execute_callback'    => static function ( $input = array() ) {
					return self::executeSetTerms( is_array( $input ) ? $input : array() );
				},
				'permission_callback' => static function ( $input = array() ) {
					return self::maySetTerms( is_array( $input ) ? $input : array() );
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

	private static function registerCreateTerm(): void {
		wp_register_ability(
			'senroflux/create-term',
			array(
				'label'               => __( 'Create term', 'senroflux' ),
				'description'         => __( 'Create a taxonomy term, or return an existing one of the same name.', 'senroflux' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'taxonomy', 'name' ),
					'additionalProperties' => false,
					'properties'           => array(
						'taxonomy' => array( 'type' => 'string' ),
						'name'     => array( 'type' => 'string' ),
					),
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'required'             => array( 'term_id' ),
					'additionalProperties' => false,
					'properties'           => array(
						'term_id' => array( 'type' => 'integer' ),
					),
				),
				'execute_callback'    => static function ( $input = array() ) {
					return self::executeCreateTerm( is_array( $input ) ? $input : array() );
				},
				'permission_callback' => static function ( $input = array() ) {
					return self::mayCreateTerm( is_array( $input ) ? $input : array() );
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
	 * Read-only stock-photo search — the escape hatch this repo's build plan
	 * added between `generate-image` (spends the `images` budget) and giving
	 * up: media library -> generate-image (budget allowing) -> THIS ->
	 * publish with `no_image_reason`. Same permission as `media-search`
	 * (`edit_posts`): it never writes anything.
	 */
	private static function registerStockImageSearch(): void {
		wp_register_ability(
			'senroflux/stock-image-search',
			array(
				'label'               => __( 'Search stock photos', 'senroflux' ),
				'description'         => __( 'Search Openverse for a CC0/public-domain stock photo when the media library has nothing suitable and the image-generation budget is exhausted. Use one or two plain words about the people or the work itself — not rooms, desks, corridors or buildings, which return off-topic photos. Skip results whose title does not match the page.', 'senroflux' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'query' ),
					'additionalProperties' => false,
					'properties'           => array(
						'query' => array( 'type' => 'string' ),
					),
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'required'             => array( 'results' ),
					'additionalProperties' => false,
					'properties'           => array(
						'results' => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'id'      => array( 'type' => 'string' ),
									'title'   => array( 'type' => 'string' ),
									'tags'    => array(
										'type'  => 'array',
										'items' => array( 'type' => 'string' ),
									),
									'creator' => array( 'type' => 'string' ),
									'source'  => array( 'type' => 'string' ),
									'license' => array( 'type' => 'string' ),
									'width'   => array( 'type' => 'integer' ),
									'height'  => array( 'type' => 'integer' ),
								),
							),
						),
					),
				),
				'execute_callback'    => static function ( $input = array() ) {
					return self::executeStockImageSearch( is_array( $input ) ? $input : array() );
				},
				'permission_callback' => static function ( $input = array() ) {
					unset( $input );

					return function_exists( 'current_user_can' ) && current_user_can( 'edit_posts' );
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
	 * Import (download + register as an attachment) one stock photo by id.
	 * SECURITY (build plan): the model supplies only the provider id — never
	 * a URL — and this ability re-fetches the detail record itself before
	 * trusting the license/source/mature/url fields (see
	 * {@see stockImageIneligibilityReason()}). Requires `upload_files`, same
	 * as `media-upload`/`generate-image` (it creates an attachment).
	 */
	private static function registerStockImageImport(): void {
		wp_register_ability(
			'senroflux/stock-image-import',
			array(
				'label'               => __( 'Import a stock photo', 'senroflux' ),
				'description'         => __( 'Download a stock photo previously found with stock-image-search and register it as an attachment. Does not spend the images budget; capped at 3 imports per run.', 'senroflux' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'id', 'alt' ),
					'additionalProperties' => false,
					'properties'           => array(
						'id'  => array( 'type' => 'string' ),
						'alt' => array( 'type' => 'string' ),
					),
				),
				'output_schema'       => self::attachmentOutputSchema(),
				'execute_callback'    => static function ( $input = array() ) {
					return self::executeStockImageImport( is_array( $input ) ? $input : array() );
				},
				'permission_callback' => static function ( $input = array() ) {
					unset( $input );

					return function_exists( 'current_user_can' ) && current_user_can( 'upload_files' );
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
	 * The shared `{attachment_id, url}` output shape.
	 *
	 * @return array<string,mixed>
	 */
	private static function attachmentOutputSchema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'attachment_id', 'url' ),
			'additionalProperties' => false,
			'properties'           => array(
				'attachment_id' => array( 'type' => 'integer' ),
				'url'           => array( 'type' => 'string' ),
			),
		);
	}

	// ------------------------------------------------------------------
	// Execute
	// ------------------------------------------------------------------

	/**
	 * @param array<string,mixed> $input Call input.
	 * @return array<string,mixed>
	 */
	private static function executeMediaSearch( array $input ): array {
		$query = is_string( $input['query'] ?? null ) ? trim( $input['query'] ) : '';
		if ( '' === $query || ! function_exists( 'get_posts' ) ) {
			return array( 'results' => array() );
		}

		$attachments = self::asAttachmentList(
			get_posts(
				array(
					'post_type'      => 'attachment',
					'post_mime_type' => 'image',
					'post_status'    => 'inherit',
					's'              => $query,
					'posts_per_page' => 20,
				)
			)
		);

		$used    = self::usedInPageContent();
		$results = array();
		foreach ( $attachments as $attachment ) {
			$id        = (int) $attachment->ID;
			$url       = self::attachmentUrl( $id );
			$results[] = array(
				'id'           => $id,
				'url'          => $url,
				'title'        => function_exists( 'get_the_title' ) ? (string) get_the_title( $id ) : '',
				'alt'          => self::altText( $id ),
				'already_used' => '' !== $url && isset( $used[ $url ] ),
			);
		}

		// Bug 1 (live run: the same hero + media-text images on every page)
		// — mirrors {@see executeStockImageSearch()}'s ranking: a stable
		// sort moves every already-used image to the end without removing
		// it, so the model still has it as a fallback.
		usort(
			$results,
			static fn ( array $a, array $b ): int => ( $a['already_used'] ? 1 : 0 ) <=> ( $b['already_used'] ? 1 : 0 )
		);

		return array( 'results' => $results );
	}

	/**
	 * Every image URL already used in ANY page's stored content (0.3
	 * quality fix, bug 1 live run: the same hero + media-text images on
	 * every page) — so {@see executeMediaSearch()} can rank an
	 * already-spoken-for result last instead of steering the model at an
	 * image another page is already using.
	 *
	 * @return array<string,true> URL => true (a set, not a list).
	 */
	private static function usedInPageContent(): array {
		if ( ! function_exists( 'get_posts' ) ) {
			return array();
		}

		$urls = array();
		foreach (
			get_posts(
				array(
					'post_type'      => 'page',
					'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
					'posts_per_page' => -1,
				)
			) as $page
		) {
			if ( ! is_object( $page ) ) {
				continue;
			}
			foreach ( ContentImages::urls( (string) $page->post_content ) as $url ) {
				$urls[ $url ] = true;
			}
		}

		return $urls;
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function executeListMissingAlt(): array {
		if ( ! function_exists( 'get_posts' ) ) {
			return array( 'attachments' => array() );
		}

		$attachments = self::asAttachmentList(
			get_posts(
				array(
					'post_type'      => 'attachment',
					'post_mime_type' => 'image',
					'post_status'    => 'inherit',
					'posts_per_page' => -1,
				)
			)
		);

		$missing = array();
		foreach ( $attachments as $attachment ) {
			$id = (int) $attachment->ID;
			if ( '' !== self::altText( $id ) ) {
				continue;
			}
			$missing[] = array(
				'id'       => $id,
				'url'      => self::attachmentUrl( $id ),
				'filename' => basename( (string) $attachment->guid ),
			);
			if ( count( $missing ) >= self::ATTACHMENT_CAP ) {
				break;
			}
		}

		return array( 'attachments' => $missing );
	}

	/**
	 * @param array<string,mixed> $input Call input.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function executeMediaUpload( array $input ): array|WP_Error {
		$relative = is_string( $input['file_path'] ?? null ) ? $input['file_path'] : '';
		$absolute = self::resolveUploadsPath( $relative );
		if ( null === $absolute ) {
			return new WP_Error(
				'file_not_found',
				__( 'That file was not found under the uploads directory.', 'senroflux' ),
				array( 'status' => 400 )
			);
		}

		return self::insertAttachmentFromFile( $absolute );
	}

	/**
	 * @param array<string,mixed> $input Call input.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function executeGenerateImage( array $input ): array|WP_Error {
		$prompt = is_string( $input['prompt'] ?? null ) ? trim( $input['prompt'] ) : '';
		if ( '' === $prompt ) {
			return new WP_Error( 'invalid_input', __( 'A prompt is required.', 'senroflux' ), array( 'status' => 400 ) );
		}

		if ( self::imagesExhausted() ) {
			return new WP_Error(
				'budget_exhausted',
				__( 'This run has used up its image-generation budget. Try stock-image-search next; if that finds nothing either, publish without an image using `no_image_reason`.', 'senroflux' ),
				array( 'status' => 409 )
			);
		}

		$generated = self::gateway()->generateImage( $prompt );
		if ( $generated instanceof WP_Error ) {
			return $generated;
		}

		return self::insertAttachmentFromFile( $generated['path'], $prompt );
	}

	/**
	 * @param array<string,mixed> $input Call input.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function executeStockImageSearch( array $input ): array|WP_Error {
		if ( ! self::stockImagesEnabled() ) {
			return new WP_Error(
				'stock_images_disabled',
				__( 'Stock image search is disabled on this site. Publish without an image using `no_image_reason` instead.', 'senroflux' ),
				array( 'status' => 409 )
			);
		}

		$query = is_string( $input['query'] ?? null ) ? trim( $input['query'] ) : '';
		if ( '' === $query ) {
			return new WP_Error( 'invalid_input', __( 'A query is required.', 'senroflux' ), array( 'status' => 400 ) );
		}

		$raw = self::stockGateway()->search( $query );
		if ( $raw instanceof WP_Error ) {
			return new WP_Error(
				'stock_images_unavailable',
				$raw->get_error_message() . ' ' . __( 'Publish without an image using `no_image_reason` instead.', 'senroflux' ),
				array( 'status' => 502 )
			);
		}

		$used    = self::usedStockImageIds();
		$results = array();
		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$title = is_string( $row['title'] ?? null ) ? $row['title'] : '';
			$tags  = self::stockTagNames( $row['tags'] ?? array() );
			if ( self::isNonPhotoStockResult( $title, self::stockTagNames( $row['tags'] ?? array(), PHP_INT_MAX ) ) || self::isUntitledStockResult( $title ) || self::isWatermarkedStockResult( $row ) ) {
				continue;
			}

			$id        = (string) ( $row['id'] ?? '' );
			$results[] = array(
				'id'           => $id,
				'title'        => $title,
				'tags'         => $tags,
				'creator'      => is_string( $row['creator'] ?? null ) ? $row['creator'] : '',
				'source'       => is_string( $row['source'] ?? null ) ? $row['source'] : '',
				'license'      => is_string( $row['license'] ?? null ) ? $row['license'] : '',
				'width'        => (int) ( $row['width'] ?? 0 ),
				'height'       => (int) ( $row['height'] ?? 0 ),
				'already_used' => isset( $used[ $id ] ),
			);
		}

		// A stable sort (guaranteed since PHP 8.0): every already-used image
		// moves to the end, in the SAME relative order the provider returned
		// them and each other in — never removed, just ranked last, so the
		// model still has it as a fallback rather than losing it outright.
		usort(
			$results,
			static fn ( array $a, array $b ): int => ( $a['already_used'] ? 1 : 0 ) <=> ( $b['already_used'] ? 1 : 0 )
		);

		return array( 'results' => $results );
	}

	/**
	 * Whether a result's title/tags mark it as something other than a real
	 * photo (0.3 quality fix, stock image choice) — {@see NON_PHOTO_KEYWORDS}.
	 * Word/phrase-boundary matched, case-insensitively, against the title and
	 * every tag joined together.
	 *
	 * @param string       $title The result's title.
	 * @param list<string> $tags  The result's tags (already extracted, at
	 *                            most 6, by {@see stockTagNames()}).
	 */
	private static function isNonPhotoStockResult( string $title, array $tags ): bool {
		$haystack = strtolower( trim( $title . ' ' . implode( ' ', $tags ) ) );
		if ( '' === $haystack ) {
			return false;
		}

		foreach ( self::NON_PHOTO_KEYWORDS as $keyword ) {
			if ( 1 === preg_match( '/\b' . preg_quote( $keyword, '/' ) . '\b/', $haystack ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * An untitled result: every one live runs imported (2026-09-29-final to
	 * final5) was a 3D render, a tag-stuffed interior or a building exterior,
	 * and none of their tags said so. A heuristic from that evidence, not a
	 * rule of the provider.
	 */
	private static function isUntitledStockResult( string $title ): bool {
		return '' === trim( $title );
	}

	/**
	 * A rawpixel preview served from `images.rawpixel.com/image_*` carries a
	 * tiled watermark (live batch 2026-09-29-cards2); its clean photos are
	 * served from `/editor_*`.
	 *
	 * @param array<string,mixed> $row One raw search result.
	 */
	private static function isWatermarkedStockResult( array $row ): bool {
		$url = is_string( $row['url'] ?? null ) ? $row['url'] : '';

		return str_contains( $url, '//images.rawpixel.com/image_' );
	}

	/**
	 * Every stock-provider image id already imported as an attachment on
	 * this site ({@see STOCK_SOURCE_ID_META_KEY}), so
	 * {@see executeStockImageSearch()} can rank a re-offered image last
	 * instead of letting a run re-import (and so duplicate) a photo it
	 * already used (0.3 quality fix, stock image choice).
	 *
	 * @return array<string,true> Provider id => true (a set, not a list).
	 */
	private static function usedStockImageIds(): array {
		if ( ! function_exists( 'get_posts' ) || ! function_exists( 'get_post_meta' ) ) {
			return array();
		}

		$used = array();
		foreach ( get_posts(
			array(
				'post_type'      => 'attachment',
				'meta_key'       => self::STOCK_SOURCE_ID_META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- a bounded, site-local lookup (attachments only), not a hot path.
				'posts_per_page' => -1,
			)
		) as $post ) {
			$id = get_post_meta( (int) $post->ID, self::STOCK_SOURCE_ID_META_KEY, true );
			if ( is_string( $id ) && '' !== $id ) {
				$used[ $id ] = true;
			}
		}

		return $used;
	}

	/**
	 * The attachment already imported from stock photo `$source_id`, or null.
	 */
	private static function attachmentForStockSource( string $source_id ): ?int {
		if ( ! function_exists( 'get_posts' ) ) {
			return null;
		}

		$posts = get_posts(
			array(
				'post_type'      => 'attachment',
				'meta_key'       => self::STOCK_SOURCE_ID_META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- same bounded attachment lookup as usedStockImageIds().
				'meta_value'     => $source_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- as above.
				'posts_per_page' => 1,
			)
		);

		return array() !== $posts ? (int) $posts[0]->ID : null;
	}

	/**
	 * Up to `$limit` tag names (6 for the result the model sees), accepting either Openverse's `{name: string}` shape
	 * or a plain string list (so a fake test gateway can use whichever is
	 * convenient).
	 *
	 * @return list<string>
	 */
	private static function stockTagNames( mixed $raw, int $limit = 6 ): array {
		$names = array();
		foreach ( is_array( $raw ) ? $raw : array() as $tag ) {
			if ( is_string( $tag ) && '' !== $tag ) {
				$names[] = $tag;
			} elseif ( is_array( $tag ) && is_string( $tag['name'] ?? null ) && '' !== $tag['name'] ) {
				$names[] = $tag['name'];
			}
			if ( count( $names ) >= $limit ) {
				break;
			}
		}

		return $names;
	}

	/**
	 * @param array<string,mixed> $input Call input.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function executeStockImageImport( array $input ): array|WP_Error {
		if ( ! self::stockImagesEnabled() ) {
			return new WP_Error(
				'stock_images_disabled',
				__( 'Stock image import is disabled on this site. Publish without an image using `no_image_reason` instead.', 'senroflux' ),
				array( 'status' => 409 )
			);
		}

		$id  = is_string( $input['id'] ?? null ) ? trim( $input['id'] ) : '';
		$alt = is_string( $input['alt'] ?? null ) ? trim( $input['alt'] ) : '';

		if ( ! self::isValidStockImageId( $id ) ) {
			return new WP_Error( 'invalid_input', __( 'A valid stock image id is required.', 'senroflux' ), array( 'status' => 400 ) );
		}
		if ( '' === $alt ) {
			return new WP_Error( 'invalid_input', __( 'Alt text is required.', 'senroflux' ), array( 'status' => 400 ) );
		}
		// Same photo, same attachment: a second copy would carry a new url
		// and slip past the hero-reuse check (live batch 2026-09-29-final).
		$existing = self::attachmentForStockSource( $id );
		if ( null !== $existing ) {
			return array(
				'attachment_id' => $existing,
				'url'           => (string) wp_get_attachment_url( $existing ),
			);
		}

		if ( self::stockImportsExhausted() ) {
			return new WP_Error(
				'stock_import_cap',
				sprintf(
					/* translators: %d: the most stock images one run may import. */
					__( 'This run has already imported %d stock images — the most one run may.', 'senroflux' ),
					self::STOCK_IMPORT_CAP
				),
				array( 'status' => 409 )
			);
		}

		// SECURITY: never trust a URL the model supplies — re-fetch the
		// detail record by id server-side, then validate license/source/
		// mature/scheme before anything is downloaded.
		$detail = self::stockGateway()->fetch( $id );
		if ( $detail instanceof WP_Error ) {
			return new WP_Error( 'stock_images_unavailable', $detail->get_error_message(), array( 'status' => 502 ) );
		}

		$ineligible = self::stockImageIneligibilityReason( $detail );
		if ( null !== $ineligible ) {
			return new WP_Error( 'stock_image_ineligible', $ineligible, array( 'status' => 400 ) );
		}

		$downloaded = self::stockGateway()->download( $detail );
		if ( $downloaded instanceof WP_Error ) {
			return $downloaded;
		}

		$title    = is_string( $detail['title'] ?? null ) && '' !== $detail['title'] ? $detail['title'] : null;
		$inserted = self::insertAttachmentFromFile( $downloaded['path'], $title );
		if ( $inserted instanceof WP_Error ) {
			return $inserted;
		}

		$attachment_id = (int) $inserted['attachment_id'];

		if ( function_exists( 'update_post_meta' ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt );
		}

		if ( function_exists( 'wp_update_post' ) ) {
			wp_update_post(
				array(
					'ID'           => $attachment_id,
					'post_excerpt' => self::stockAttributionLine( $detail ),
				)
			);
		}

		if ( function_exists( 'update_post_meta' ) ) {
			update_post_meta(
				$attachment_id,
				'_senroflux_stock_source',
				array(
					'provider'        => 'openverse',
					'id'              => $id,
					'source'          => (string) ( $detail['source'] ?? '' ),
					'license'         => (string) ( $detail['license'] ?? '' ),
					'license_version' => (string) ( $detail['license_version'] ?? '' ),
					'creator'         => (string) ( $detail['creator'] ?? '' ),
					'landing_url'     => (string) ( $detail['foreign_landing_url'] ?? '' ),
				)
			);
			// 0.3 quality fix (stock image choice): a plain-string companion
			// to the record above — {@see usedStockImageIds()} reads this
			// with one `get_posts()` meta lookup so `stock-image-search` can
			// rank an already-imported image last instead of the run
			// re-importing (and so duplicating) a photo it already used.
			update_post_meta( $attachment_id, self::STOCK_SOURCE_ID_META_KEY, $id );
		}

		return $inserted;
	}

	/**
	 * The re-fetched-by-id detail record's eligibility check (build plan
	 * SECURITY note): license must be CC0/public-domain, source must be one
	 * of the allowed stock sources, the image must not be flagged mature, and
	 * its file URL must be https. Null means eligible.
	 *
	 * @param array<string,mixed> $detail The provider's own detail record.
	 */
	private static function stockImageIneligibilityReason( array $detail ): ?string {
		$license = strtolower( (string) ( $detail['license'] ?? '' ) );
		if ( ! in_array( $license, array( 'cc0', 'pdm' ), true ) ) {
			return __( "That image's license is not CC0 or public domain.", 'senroflux' );
		}

		$source = (string) ( $detail['source'] ?? '' );
		if ( ! in_array( $source, OpenverseStockImageGateway::defaultSources(), true ) ) {
			return __( 'That image is not from an allowed stock source.', 'senroflux' );
		}

		if ( true === ( $detail['mature'] ?? false ) ) {
			return __( 'That image is flagged as mature content.', 'senroflux' );
		}

		$url = is_string( $detail['url'] ?? null ) ? $detail['url'] : '';
		if ( ! str_starts_with( $url, 'https://' ) ) {
			return __( "That image's file URL is not https.", 'senroflux' );
		}

		return null;
	}

	/**
	 * A human-readable attribution line, stored as the imported attachment's
	 * caption (`post_excerpt`).
	 *
	 * @param array<string,mixed> $detail The provider's own detail record.
	 */
	private static function stockAttributionLine( array $detail ): string {
		$title   = is_string( $detail['title'] ?? null ) ? $detail['title'] : '';
		$creator = is_string( $detail['creator'] ?? null ) ? $detail['creator'] : '';
		$license = strtoupper( (string) ( $detail['license'] ?? '' ) );
		$source  = (string) ( $detail['source'] ?? '' );
		$landing = is_string( $detail['foreign_landing_url'] ?? null ) ? $detail['foreign_landing_url'] : '';

		$parts = array_values(
			array_filter(
				array(
					'' !== $title ? '"' . $title . '"' : null,
					/* translators: %s: the image's creator/photographer. */
					'' !== $creator ? sprintf( __( 'by %s', 'senroflux' ), $creator ) : null,
					'' !== $license ? $license : null,
					'' !== $source ? $source : null,
				)
			)
		);

		$line = implode( ', ', $parts );

		return '' !== $landing ? $line . ' — ' . $landing : $line;
	}

	private static function isValidStockImageId( string $id ): bool {
		return 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id );
	}

	/**
	 * @param array<string,mixed> $input Call input.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function executeGenerateAltText( array $input ): array|WP_Error {
		$id = (int) ( $input['attachment_id'] ?? 0 );
		if ( 'attachment' !== self::postType( $id ) ) {
			return new WP_Error( 'not_found', __( 'Attachment not found.', 'senroflux' ), array( 'status' => 400 ) );
		}

		// 0.3 quality fix 3 (live run): the gateway used to send the
		// attachment's PUBLIC URL, which OpenAI cannot fetch from
		// `localhost`/staging/password-protected/intranet sites ("Bad
		// Request (400) - Error while downloading file. Upstream status
		// code: 407."). A local file path always works — the gateway
		// inlines it as base64, so the request never depends on the site
		// being reachable from the outside at all.
		$path = self::attachmentFilePath( $id );
		if ( $path instanceof WP_Error ) {
			return $path;
		}

		$alt = self::gateway()->generateAltText( $path );
		if ( $alt instanceof WP_Error ) {
			return $alt;
		}

		return array( 'alt' => $alt );
	}

	/**
	 * 0.3 quality fix 3: an absolute, on-disk path for `$id`, preferring the
	 * `large` intermediate size (falls back to `medium_large`, then the
	 * original file) to keep the alt-text request small. A missing file on
	 * disk (any size registered in the DB but never generated, or since
	 * deleted) is a clear, distinct refusal rather than the gateway silently
	 * treating a bad path as literal text.
	 */
	private static function attachmentFilePath( int $id ): string|WP_Error {
		$path = self::intermediateFilePath( $id, 'large' )
			?? self::intermediateFilePath( $id, 'medium_large' )
			?? ( function_exists( 'get_attached_file' ) ? get_attached_file( $id ) : false );

		if ( ! is_string( $path ) || '' === $path || ! file_exists( $path ) ) {
			return new WP_Error(
				'attachment_file_missing',
				__( 'The attachment file could not be found on disk.', 'senroflux' ),
				array( 'status' => 404 )
			);
		}

		$size = filesize( $path );
		if ( is_int( $size ) && $size > self::ALT_TEXT_MAX_FILE_BYTES ) {
			return new WP_Error(
				'attachment_file_too_large',
				__( 'The attachment file is too large to send for alt-text generation.', 'senroflux' ),
				array( 'status' => 400 )
			);
		}

		return $path;
	}

	/**
	 * The on-disk path of one registered intermediate size, or null when that
	 * size does not exist for this attachment (never generated, or too small
	 * a source image to need it).
	 */
	private static function intermediateFilePath( int $id, string $size ): ?string {
		if ( ! function_exists( 'image_get_intermediate_size' ) || ! function_exists( 'wp_upload_dir' ) ) {
			return null;
		}

		$intermediate = image_get_intermediate_size( $id, $size );
		if ( ! is_array( $intermediate ) || ! isset( $intermediate['path'] ) || ! is_string( $intermediate['path'] ) ) {
			return null;
		}

		$dirs = wp_upload_dir();
		if ( ! is_array( $dirs ) || ! isset( $dirs['basedir'] ) || ! is_string( $dirs['basedir'] ) ) {
			return null;
		}

		return trailingslashit( $dirs['basedir'] ) . $intermediate['path'];
	}

	/**
	 * @param array<string,mixed> $input Call input.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function executeSetFeaturedImage( array $input ): array|WP_Error {
		$post_id       = (int) ( $input['post_id'] ?? 0 );
		$attachment_id = (int) ( $input['attachment_id'] ?? 0 );

		if ( 'attachment' !== self::postType( $attachment_id ) ) {
			return new WP_Error( 'not_found', __( 'Attachment not found.', 'senroflux' ), array( 'status' => 400 ) );
		}
		$post = function_exists( 'get_post' ) ? get_post( $post_id ) : null;
		if ( null === $post ) {
			return new WP_Error( 'not_found', __( 'Post not found.', 'senroflux' ), array( 'status' => 400 ) );
		}

		// 0.3 quality fix (bug 2, live run: a café photo repeated at the top
		// of a post): TT25's `single.html` prints the featured image ABOVE
		// the content, so setting this attachment as featured when it is
		// already an image block in the post's own content would show it
		// twice. Posts only — a page's own template never doubles the image
		// this way.
		if ( 'post' === (string) ( $post->post_type ?? '' ) ) {
			$url = self::attachmentUrl( $attachment_id );
			if ( '' !== $url && ContentImages::containsUrl( (string) ( $post->post_content ?? '' ), $url ) ) {
				return new WP_Error(
					'featured_image_duplicated',
					__( 'This image is already in the post\'s content, and the theme shows the featured image above the post, so it would appear twice. Remove it from the content with update-draft, or pick a different featured image.', 'senroflux' ),
					array( 'status' => 400 )
				);
			}
		}

		if ( function_exists( 'set_post_thumbnail' ) ) {
			set_post_thumbnail( $post_id, $attachment_id );
		}

		return array( 'post_id' => $post_id );
	}

	/**
	 * @param array<string,mixed> $input Call input.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function executeUpdateAlt( array $input ): array|WP_Error {
		$id  = (int) ( $input['attachment_id'] ?? 0 );
		$alt = is_string( $input['alt'] ?? null ) ? trim( $input['alt'] ) : '';

		if ( 'attachment' !== self::postType( $id ) ) {
			return new WP_Error( 'not_found', __( 'Attachment not found.', 'senroflux' ), array( 'status' => 400 ) );
		}
		if ( '' === $alt ) {
			return new WP_Error( 'invalid_alt', __( 'Alt text may not be empty.', 'senroflux' ), array( 'status' => 400 ) );
		}
		if ( self::isOverAttachmentCap( $id ) ) {
			return new WP_Error(
				'attachment_cap',
				__( 'This run has already touched 25 attachments\' alt text — the most one run may.', 'senroflux' ),
				array( 'status' => 409 )
			);
		}

		if ( function_exists( 'update_post_meta' ) ) {
			update_post_meta( $id, '_wp_attachment_image_alt', $alt );
		}
		self::recordAltWrite( $id );

		return array( 'attachment_id' => $id );
	}

	/**
	 * `read-media` (defect fix): re-read one attachment. The generic S12
	 * write/verify tracking (wired through {@see \Specflux\SenroFlux\Packs\Posts\PostsPack::objectIdKey()}
	 * / `objectIdPrefix()`) marks the attachment verified automatically once
	 * this succeeds — this method only has to return the fresh snapshot.
	 *
	 * @param array<string,mixed> $input Call input.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function executeReadMedia( array $input ): array|WP_Error {
		$id = (int) ( $input['attachment_id'] ?? 0 );
		if ( 'attachment' !== self::postType( $id ) ) {
			return new WP_Error( 'not_found', __( 'Attachment not found.', 'senroflux' ), array( 'status' => 400 ) );
		}

		// Optional S8-style marker (defect fix): cheap to record, not yet
		// enforced anywhere — update-alt/media-upload/set-featured-image
		// deliberately do NOT compare against it (see the class docblock:
		// none of them touch post_content, so there is nothing to reproduce
		// S8's stale_write refusal against). Recording it here only makes a
		// future refusal possible without another schema change.
		if ( null !== self::$current_run_id && null !== self::$store ) {
			$objects = \Specflux\SenroFlux\Run\Tracker::recordRead(
				self::currentObjects(),
				self::OBJECT_ID_PREFIX . $id,
				self::altText( $id )
			);
			self::$store->updateRun( self::$current_run_id, array( 'objects_json' => $objects ) );
		}

		return self::attachmentSnapshot( $id );
	}

	/**
	 * The `read-media` output shape, also reused (minus the harness-opaque
	 * `attachment_id` framing) by {@see attachmentLookup()} for the S12
	 * report.
	 *
	 * @return array<string,mixed>
	 */
	private static function attachmentSnapshot( int $id ): array {
		$post = function_exists( 'get_post' ) ? get_post( $id ) : null;

		return array(
			'attachment_id' => $id,
			'alt'           => self::altText( $id ),
			'title'         => function_exists( 'get_the_title' ) ? (string) get_the_title( $id ) : '',
			'mime_type'     => is_object( $post ) ? (string) $post->post_mime_type : '',
			'url'           => self::attachmentUrl( $id ),
			'width'         => self::attachmentDimension( $id, 'width' ),
			'height'        => self::attachmentDimension( $id, 'height' ),
			'featured_on'   => self::featuredOnPostIds( $id ),
		);
	}

	/**
	 * One dimension from `wp_get_attachment_metadata()`; null when the
	 * function or the metadata is absent (fail closed to "unknown", never a
	 * guessed 0).
	 */
	private static function attachmentDimension( int $id, string $dimension ): ?int {
		if ( ! function_exists( 'wp_get_attachment_metadata' ) ) {
			return null;
		}
		$metadata = wp_get_attachment_metadata( $id );

		return ( is_array( $metadata ) && isset( $metadata[ $dimension ] ) && is_numeric( $metadata[ $dimension ] ) )
			? (int) $metadata[ $dimension ]
			: null;
	}

	/**
	 * Every post id whose `_thumbnail_id` meta points at this attachment.
	 *
	 * @return list<int>
	 */
	private static function featuredOnPostIds( int $attachment_id ): array {
		if ( ! function_exists( 'get_posts' ) ) {
			return array();
		}

		$posts = get_posts(
			array(
				'post_type'      => 'any',
				'posts_per_page' => -1,
				'meta_key'       => '_thumbnail_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- the only way to find posts featuring this attachment.
				'meta_value'     => (string) $attachment_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- as above.
			)
		);

		return array_values(
			array_map(
				static fn ( $post ): int => (int) $post->ID,
				self::asAttachmentList( $posts )
			)
		);
	}

	/**
	 * The S12 report lookup for one attachment (defect fix): the shape
	 * {@see \Specflux\SenroFlux\Run\Report::LOOKUP_KEYS} needs, resolved from
	 * the SAME attachment data `read-media` returns — never taught to the
	 * harness by name (wired through the composition root's `$post_lookup`,
	 * Plugin.php, which strips {@see OBJECT_ID_PREFIX} before calling this).
	 *
	 * @return array{object_type:string,title:string,status:string,edit_url:?string,preview_url:?string}
	 */
	public static function attachmentLookup( int $attachment_id ): array {
		if ( 'attachment' !== self::postType( $attachment_id ) ) {
			return array(
				'object_type' => 'unknown',
				'title'       => '',
				'status'      => '',
				'edit_url'    => null,
				'preview_url' => null,
			);
		}

		$title = function_exists( 'get_the_title' ) ? (string) get_the_title( $attachment_id ) : '';
		if ( '' === $title ) {
			// Fall back to the filename when the attachment has no title.
			$title = basename( self::attachmentUrl( $attachment_id ) );
		}

		$status = function_exists( 'get_post_status' ) ? (string) get_post_status( $attachment_id ) : '';
		$edit   = function_exists( 'get_edit_post_link' ) ? get_edit_post_link( $attachment_id, 'raw' ) : '';
		$file   = self::attachmentUrl( $attachment_id );

		return array(
			'object_type' => 'attachment',
			'title'       => $title,
			'status'      => '' !== $status ? $status : '',
			'edit_url'    => ( is_string( $edit ) && '' !== $edit ) ? $edit : null,
			// S12: "preview_url" for an attachment is its own file, not a
			// post preview — there is nothing to draft-preview.
			'preview_url' => '' !== $file ? $file : null,
		);
	}

	/**
	 * @param array<string,mixed> $input Call input.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function executeSetTerms( array $input ): array|WP_Error {
		$post_id  = (int) ( $input['post_id'] ?? 0 );
		$taxonomy = is_string( $input['taxonomy'] ?? null ) ? $input['taxonomy'] : '';
		$term_ids = is_array( $input['term_ids'] ?? null ) ? array_map( 'intval', $input['term_ids'] ) : array();

		if ( ! function_exists( 'get_post' ) || null === get_post( $post_id ) ) {
			return new WP_Error( 'not_found', __( 'Post not found.', 'senroflux' ), array( 'status' => 400 ) );
		}

		if ( function_exists( 'wp_set_post_terms' ) ) {
			$result = wp_set_post_terms( $post_id, $term_ids, $taxonomy, false );
			if ( $result instanceof WP_Error ) {
				return $result;
			}
		}

		return array(
			'post_id'  => $post_id,
			'term_ids' => $term_ids,
		);
	}

	/**
	 * @param array<string,mixed> $input Call input.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function executeCreateTerm( array $input ): array|WP_Error {
		$taxonomy = is_string( $input['taxonomy'] ?? null ) ? $input['taxonomy'] : '';
		$name     = is_string( $input['name'] ?? null ) ? trim( $input['name'] ) : '';

		if ( '' === $name ) {
			return new WP_Error( 'invalid_input', __( 'A term name is required.', 'senroflux' ), array( 'status' => 400 ) );
		}

		if ( function_exists( 'term_exists' ) ) {
			$existing = term_exists( $name, $taxonomy );
			if ( is_array( $existing ) && isset( $existing['term_id'] ) ) {
				return array( 'term_id' => (int) $existing['term_id'] );
			}
		}

		if ( ! function_exists( 'wp_insert_term' ) ) {
			return new WP_Error( 'gateway_unavailable', __( 'Terms are not available.', 'senroflux' ), array( 'status' => 400 ) );
		}

		$inserted = wp_insert_term( $name, $taxonomy );
		if ( $inserted instanceof WP_Error ) {
			return $inserted;
		}

		return array( 'term_id' => (int) ( $inserted['term_id'] ?? 0 ) );
	}

	// ------------------------------------------------------------------
	// Permissions
	// ------------------------------------------------------------------

	private static function mayEditAttachment( int $attachment_id ): bool {
		if ( 'attachment' !== self::postType( $attachment_id ) ) {
			return false;
		}

		return function_exists( 'current_user_can' ) && current_user_can( 'edit_post', $attachment_id );
	}

	/**
	 * @param array<string,mixed> $input Call input.
	 */
	private static function maySetTerms( array $input ): bool {
		$post_id  = (int) ( $input['post_id'] ?? 0 );
		$taxonomy = is_string( $input['taxonomy'] ?? null ) ? $input['taxonomy'] : '';

		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return false;
		}

		return current_user_can( self::assignTermsCap( $taxonomy ) );
	}

	/**
	 * @param array<string,mixed> $input Call input.
	 */
	private static function mayCreateTerm( array $input ): bool {
		$taxonomy = is_string( $input['taxonomy'] ?? null ) ? $input['taxonomy'] : '';

		return function_exists( 'current_user_can' ) && current_user_can( self::manageTermsCap( $taxonomy ) );
	}

	private static function assignTermsCap( string $taxonomy ): string {
		if ( function_exists( 'get_taxonomy' ) ) {
			$object = get_taxonomy( $taxonomy );
			if ( is_object( $object ) && isset( $object->cap->assign_terms ) && is_string( $object->cap->assign_terms ) ) {
				return $object->cap->assign_terms;
			}
		}

		return 'assign_categories';
	}

	private static function manageTermsCap( string $taxonomy ): string {
		if ( function_exists( 'get_taxonomy' ) ) {
			$object = get_taxonomy( $taxonomy );
			if ( is_object( $object ) && isset( $object->cap->manage_terms ) && is_string( $object->cap->manage_terms ) ) {
				return $object->cap->manage_terms;
			}
		}

		return 'manage_categories';
	}

	// ------------------------------------------------------------------
	// Shared object helpers
	// ------------------------------------------------------------------

	private static function postType( int $id ): string {
		if ( ! function_exists( 'get_post' ) ) {
			return '';
		}
		$post = get_post( $id );

		return is_object( $post ) ? (string) $post->post_type : '';
	}

	/**
	 * Normalises `get_posts()`'s return (typed `array<WP_Post>` by the
	 * WordPress stubs, but a real/duck-typed call could answer anything) to a
	 * safe list of attachment-shaped objects (`ID`, `guid`).
	 *
	 * @param mixed $raw The raw `get_posts()` return.
	 * @return list<\WP_Post>
	 */
	private static function asAttachmentList( mixed $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		/** @var list<\WP_Post> $filtered */
		$filtered = array_values( array_filter( $raw, static fn ( $item ): bool => is_object( $item ) ) );

		return $filtered;
	}

	private static function attachmentUrl( int $id ): string {
		if ( ! function_exists( 'wp_get_attachment_url' ) ) {
			return '';
		}
		$url = wp_get_attachment_url( $id );

		return is_string( $url ) ? $url : '';
	}

	private static function altText( int $id ): string {
		if ( ! function_exists( 'get_post_meta' ) ) {
			return '';
		}
		$alt = get_post_meta( $id, '_wp_attachment_image_alt', true );

		return is_string( $alt ) ? trim( $alt ) : '';
	}

	/**
	 * Resolve a model-supplied relative path to a real file strictly inside
	 * the uploads base directory. Null when the file is missing or the path
	 * escapes the base dir (e.g. `../../wp-config.php`).
	 */
	private static function resolveUploadsPath( string $relative ): ?string {
		if ( '' === $relative || ! function_exists( 'wp_upload_dir' ) ) {
			return null;
		}

		$dirs = wp_upload_dir();
		if ( ! is_array( $dirs ) || ! isset( $dirs['basedir'] ) || ! is_string( $dirs['basedir'] ) ) {
			return null;
		}

		$base = rtrim( $dirs['basedir'], '/' );
		$path = $base . '/' . ltrim( $relative, '/' );

		$real_base = realpath( $base );
		$real_path = realpath( $path );

		if ( false === $real_base || false === $real_path ) {
			return null;
		}

		if ( ! str_starts_with( $real_path, $real_base . DIRECTORY_SEPARATOR ) && $real_path !== $real_base ) {
			return null;
		}

		return $real_path;
	}

	/**
	 * Insert (or reuse) an attachment pointing at a real file on disk.
	 *
	 * @param string      $absolute_path Real, validated file path.
	 * @param string|null $title         Attachment title; the filename when omitted.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function insertAttachmentFromFile( string $absolute_path, ?string $title = null ): array|WP_Error {
		if ( ! function_exists( 'wp_insert_attachment' ) ) {
			return new WP_Error( 'gateway_unavailable', __( 'Attachments are not available.', 'senroflux' ), array( 'status' => 400 ) );
		}

		$filetype = function_exists( 'wp_check_filetype' ) ? wp_check_filetype( basename( $absolute_path ) ) : array( 'type' => 'image/jpeg' );

		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => is_array( $filetype ) ? ( $filetype['type'] ?? 'image/jpeg' ) : 'image/jpeg',
				'post_title'     => $title ?? basename( $absolute_path ),
				'post_status'    => 'inherit',
			),
			$absolute_path
		);

		if ( $attachment_id instanceof WP_Error ) {
			return $attachment_id;
		}

		if ( function_exists( 'wp_generate_attachment_metadata' ) && function_exists( 'wp_update_attachment_metadata' ) ) {
			$metadata = wp_generate_attachment_metadata( (int) $attachment_id, $absolute_path );
			wp_update_attachment_metadata( (int) $attachment_id, $metadata );
		}

		return array(
			'attachment_id' => (int) $attachment_id,
			'url'           => self::attachmentUrl( (int) $attachment_id ),
		);
	}

	// ------------------------------------------------------------------
	// Images budget (S5) + attachment cap
	// ------------------------------------------------------------------

	private static function gateway(): MediaGatewayInterface {
		if ( null === self::$gateway ) {
			self::$gateway = new AiClientMediaGateway();
		}

		return self::$gateway;
	}

	private static function stockGateway(): StockImageGatewayInterface {
		if ( null === self::$stock_gateway ) {
			self::$stock_gateway = new OpenverseStockImageGateway();
		}

		return self::$stock_gateway;
	}

	/**
	 * `senroflux_stock_images_enabled` (build plan): a site that forbids
	 * outbound requests turns the whole stock-photo feature off.
	 */
	private static function stockImagesEnabled(): bool {
		return function_exists( 'apply_filters' ) ? (bool) apply_filters( 'senroflux_stock_images_enabled', true ) : true;
	}

	/**
	 * The number of PRIOR successful `stock-image-import` calls in this run
	 * (same derivation as {@see spentImages()}), capped at {@see STOCK_IMPORT_CAP}.
	 */
	private static function stockImportsExhausted(): bool {
		if ( null === self::$current_run_id || null === self::$store ) {
			return false;
		}

		return Budget::spentCount( self::$store, self::$current_run_id, 'stock-image-import' ) >= self::STOCK_IMPORT_CAP;
	}

	/**
	 * The pageImageCheck() escape hatch (build plan point 4): true only when
	 * a run genuinely has no image source left — the `images` budget is
	 * exhausted AND either stock images are disabled site-wide, or this run
	 * already tried `stock-image-search` and it came back empty/erroring.
	 * Fails closed (false) with no run context resolved, same reasoning as
	 * {@see imagesExhausted()}: outside a run there is no budget to have
	 * exhausted, so the ordinary (stricter) refusal stands.
	 */
	public static function noImageSourceLeft(): bool {
		if ( null === self::$current_run_id || null === self::$store ) {
			return false;
		}

		if ( ! self::imagesExhausted() ) {
			return false;
		}

		if ( ! self::stockImagesEnabled() ) {
			return true;
		}

		return self::stockSearchTriedAndFailed();
	}

	/**
	 * Whether this run already called `stock-image-search` and it either
	 * errored or came back with zero results — read from the persisted step
	 * history (no new schema), the same derivation style {@see spentImages()}
	 * uses.
	 */
	private static function stockSearchTriedAndFailed(): bool {
		if ( null === self::$current_run_id || null === self::$store ) {
			return false;
		}

		foreach ( self::$store->getSteps( self::$current_run_id ) as $step ) {
			if ( null === $step->toolName || 'stock-image-search' !== self::baseAbilityName( $step->toolName ) ) {
				continue;
			}

			if ( 'error' === $step->status ) {
				return true;
			}

			if ( 'ok' === $step->status ) {
				$response = self::stepFunctionResponse( $step );
				$results  = is_array( $response ) ? ( $response['results'] ?? null ) : null;
				if ( is_array( $results ) && array() === $results ) {
					return true;
				}
			}
		}

		return false;
	}

	/** The ability name's final segment (namespace stripped). */
	private static function baseAbilityName( string $ability ): string {
		$pos = strrpos( $ability, '/' );

		return false === $pos ? $ability : substr( $ability, $pos + 1 );
	}

	/**
	 * Extract the `functionResponse.response` payload a tool_result step's
	 * `message_json` carries (see {@see \Specflux\SenroFlux\Run\Runner::appendToolResult()}),
	 * or null when the step carries no such part.
	 *
	 * @return array<string,mixed>|null
	 */
	private static function stepFunctionResponse( \Specflux\SenroFlux\Run\Step $step ): ?array {
		$parts = is_array( $step->messageArray ) ? ( $step->messageArray['parts'] ?? null ) : null;
		if ( ! is_array( $parts ) ) {
			return null;
		}

		foreach ( $parts as $part ) {
			$response = is_array( $part ) ? ( $part['functionResponse']['response'] ?? null ) : null;
			if ( is_array( $response ) ) {
				return $response;
			}
		}

		return null;
	}

	/**
	 * Whether the run's `images` budget key is already spent. Fails OPEN
	 * (never exhausted) with no run context resolved — this is a resource
	 * cap, not a security gate, and a call reached with no run in flight
	 * (a direct API consumer, a unit test) has no meaningful budget to check.
	 */
	private static function imagesExhausted(): bool {
		if ( null === self::$current_run_id || null === self::$store ) {
			return false;
		}

		$run = self::$store->getRun( self::$current_run_id );
		if ( null === $run ) {
			return false;
		}

		$limit = (int) ( $run->budget[ Budget::IMAGES ] ?? Budget::defaults()[ Budget::IMAGES ] );

		return self::spentImages() >= $limit;
	}

	/**
	 * The number of PRIOR successful `generate-image` calls in this run,
	 * derived from the persisted step history — no new schema (see the class
	 * docblock). Delegates the counting rule to {@see Budget::spentCount()},
	 * the shared SPEND-style helper `images` and `refunds` (0.3 S19) both use.
	 */
	private static function spentImages(): int {
		if ( null === self::$current_run_id || null === self::$store ) {
			return 0;
		}

		return Budget::spentCount( self::$store, self::$current_run_id, 'generate-image' );
	}

	/**
	 * The current run's `objects_json` map, or an empty set with no run
	 * context resolved.
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
	 * Whether writing `$attachment_id`'s alt text would be the 26th DISTINCT
	 * attachment this run has touched. An id already recorded is never over
	 * the cap (idempotent re-writes are free).
	 */
	private static function isOverAttachmentCap( int $attachment_id ): bool {
		$objects = self::currentObjects();
		$key     = self::ALT_KEY_PREFIX . $attachment_id;
		if ( array_key_exists( $key, $objects ) ) {
			return false;
		}

		return self::distinctAltCount( $objects ) >= self::ATTACHMENT_CAP;
	}

	/**
	 * @param array<string,mixed> $objects The objects_json map.
	 */
	private static function distinctAltCount( array $objects ): int {
		$count = 0;
		foreach ( array_keys( $objects ) as $key ) {
			if ( is_string( $key ) && str_starts_with( $key, self::ALT_KEY_PREFIX ) ) {
				++$count;
			}
		}

		return $count;
	}

	private static function recordAltWrite( int $attachment_id ): void {
		if ( null === self::$current_run_id || null === self::$store ) {
			return;
		}

		$objects = self::currentObjects();
		// Defect fix (live run, 2026-09-27 baseline): this entry must stay
		// invisible to {@see \Specflux\SenroFlux\Run\Tracker} and
		// {@see \Specflux\SenroFlux\Run\Report} — both fail-close a
		// non-array `objects_json` value to "permanently unverified", and
		// this id is never qualified with {@see OBJECT_ID_PREFIX} so no read
		// could ever clear it. Deliberately omitting `last_write_seq` makes
		// this a READ-ONLY-shaped entry (Tracker::unverified()'s existing
		// rule), so it never opens a verify nudge or a phantom report row.
		$objects[ self::ALT_KEY_PREFIX . $attachment_id ] = array( 'cap_marker' => true );
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
