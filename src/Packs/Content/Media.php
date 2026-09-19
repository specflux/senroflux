<?php
/**
 * The media/attachment/term registrar shared by every content pack (0.3 S5,
 * stage 6): `media-search`, `media-upload`, `generate-image`,
 * `generate-alt-text`, `set-featured-image`, `list-missing-alt`,
 * `update-alt`, `set-terms`, `create-term`.
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
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Packs\Content;

use Specflux\SenroFlux\Model\AiClientMediaGateway;
use Specflux\SenroFlux\Model\MediaGatewayInterface;
use Specflux\SenroFlux\Run\Budget;
use Specflux\SenroFlux\Run\RunStore;
use WP_Error;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Registers the shared media/attachment/term abilities.
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
	 * for `posts/update-alt` names this, so the generic
	 * {@see \Specflux\SenroFlux\Run\Runner} write/verify tracking never
	 * collides an attachment id with a post id sharing the same number. The
	 * composition root's report lookup (wired in Plugin.php) strips it back
	 * off before calling {@see attachmentLookup()} — the harness itself
	 * (`src/Run`) never parses it.
	 */
	public const OBJECT_ID_PREFIX = 'attachment:';

	/**
	 * Whether {@see register()} has run for this request.
	 */
	private static bool $registered = false;

	/**
	 * Test seam: overrides the real {@see AiClientMediaGateway}.
	 */
	private static ?MediaGatewayInterface $gateway = null;

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
		self::$registered = false;
		self::$gateway    = null;
	}

	/**
	 * Test seam: override the media gateway. Null restores the real one.
	 */
	public static function setGateway( ?MediaGatewayInterface $gateway ): void {
		self::$gateway = $gateway;
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
	 * Register the nine abilities. Idempotent per request.
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
		self::registerSetTerms();
		self::registerCreateTerm();
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
									'id'    => array( 'type' => 'integer' ),
									'url'   => array( 'type' => 'string' ),
									'title' => array( 'type' => 'string' ),
									'alt'   => array( 'type' => 'string' ),
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

		$results = array();
		foreach ( $attachments as $attachment ) {
			$id        = (int) $attachment->ID;
			$results[] = array(
				'id'    => $id,
				'url'   => self::attachmentUrl( $id ),
				'title' => function_exists( 'get_the_title' ) ? (string) get_the_title( $id ) : '',
				'alt'   => self::altText( $id ),
			);
		}

		return array( 'results' => $results );
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
				__( 'This run has used up its image-generation budget.', 'senroflux' ),
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
	private static function executeGenerateAltText( array $input ): array|WP_Error {
		$id = (int) ( $input['attachment_id'] ?? 0 );
		if ( 'attachment' !== self::postType( $id ) ) {
			return new WP_Error( 'not_found', __( 'Attachment not found.', 'senroflux' ), array( 'status' => 400 ) );
		}

		$alt = self::gateway()->generateAltText( self::attachmentUrl( $id ) );
		if ( $alt instanceof WP_Error ) {
			return $alt;
		}

		return array( 'alt' => $alt );
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
		if ( ! function_exists( 'get_post' ) || null === get_post( $post_id ) ) {
			return new WP_Error( 'not_found', __( 'Post not found.', 'senroflux' ), array( 'status' => 400 ) );
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
	 * The S12 report lookup for one attachment (defect fix): the shape
	 * {@see \Specflux\SenroFlux\Run\Report::LOOKUP_KEYS} needs — never taught
	 * to the harness by name (wired through the composition root's
	 * `$post_lookup`, Plugin.php, which strips {@see OBJECT_ID_PREFIX}
	 * before calling this).
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
		$objects[ self::ALT_KEY_PREFIX . $attachment_id ] = true;
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
