<?php
/**
 * The pages capability pack (S9/S10/S11/S13/S15).
 *
 * TARGET REPO PATH: src/Packs/Pages/PagesPack.php
 *
 * Role → ability template map (resolved `core/*` vs `senroflux/*` by the
 * base), the S10 verb map + verb predicate + role→verb split, the three pack
 * skills, the AS pack descriptor, and the S13 Capability-Packs binding check
 * the abstract base requires of every pack.
 *
 * ISOLATION RULE (harness contract): this pack feeds the Runner through the
 * base's explicit seams only (allow-list / skills / verb map / verb
 * predicate). It never touches the run loop.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Packs\Pages;

use Specflux\SenroFlux\Packs\Content\Media;
use Specflux\SenroFlux\Packs\Pack;
use Specflux\SenroFlux\Run\Budget;
use Specflux\SenroFlux\Skills\Skill;
use Specflux\SenroFlux\Skills\SkillSource;
use WP_Error;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * The pages pack.
 */
final class PagesPack extends Pack {

	public function __construct() {
		parent::__construct(
			array(
				'read'         => 'read-content',
				'create'       => 'create-post',
				'update'       => 'update-post',
				'publish'      => 'publish-post',
				'preview'      => 'get-preview-url',
				'patterns'     => 'list-patterns',
				// 0.3 quality feature 4: the pages pack's own images (a
				// featured image, or an existing/generated attachment for a
				// theme pattern's image slot), mirroring the posts pack's
				// media roles verbatim so tracking/verification/report rows
				// work the same way.
				'search'       => 'media-search',
				'missing-alt'  => 'list-missing-alt',
				'upload'       => 'media-upload',
				'generate'     => 'generate-image',
				'alt-text'     => 'generate-alt-text',
				'featured'     => 'set-featured-image',
				'alt'          => 'update-alt',
				'read-media'   => 'read-media',
				// Stock-photo-fallback build plan: the escape hatch between
				// `generate` (spends the images budget) and `no_image_reason`.
				'stock-search' => 'stock-image-search',
				'stock-import' => 'stock-image-import',
			)
		);
	}

	/**
	 * Register the pack's pattern vocabulary (S11). Called from the
	 * composition root on `init`; Vocabulary::register() is idempotent.
	 *
	 * @return int Number of patterns registered this call.
	 */
	public function registerPatterns(): int {
		$vocabulary = new Vocabulary();

		return $vocabulary->register();
	}

	/**
	 * @return string 'pages'.
	 */
	public function name(): string {
		return 'pages';
	}

	/**
	 * @return string 'edit_pages' (S3/S7: the pages pack's run capability).
	 */
	public function runCapability(): string {
		return 'edit_pages';
	}

	/**
	 * The input-property keys this pack's client actually sends, per ability
	 * template (S9 shape-compat seam). A core ability is adopted only when its
	 * schema accepts every one of these.
	 *
	 * @param string $template Ability template.
	 * @return list<string>
	 */
	protected function inputProperties( string $template ): array {
		return match ( $template ) {
			'read-content'    => array( 'id', 'post_type', 'slug', 'status', 'author', 'parent', 'fields' ),
			'create-post'     => array( 'post_type', 'title', 'content', 'sections', 'status', 'slug', 'parent', 'excerpt', 'no_image_reason' ),
			'update-post'     => array( 'id', 'post_type', 'title', 'content', 'sections', 'status', 'slug', 'parent', 'excerpt', 'no_image_reason' ),
			'publish-post'    => array( 'id', 'post_type', 'title', 'content', 'sections', 'status', 'slug', 'parent', 'excerpt', 'no_image_reason' ),
			'get-preview-url'    => array( 'id' ),
			'list-patterns'      => array(),
			'media-search'       => array( 'query' ),
			'list-missing-alt'   => array(),
			'media-upload'       => array( 'file_path' ),
			'generate-image'     => array( 'prompt' ),
			'generate-alt-text'  => array( 'attachment_id' ),
			'set-featured-image' => array( 'post_id', 'attachment_id' ),
			'update-alt'         => array( 'attachment_id', 'alt' ),
			'read-media'         => array( 'attachment_id' ),
			'stock-image-search' => array( 'query' ),
			'stock-image-import' => array( 'id', 'alt' ),
			default              => array(),
		};
	}

	/**
	 * The target post's CURRENT status, for the update predicate (S10). Reads
	 * the real post so a publish-to-publish edit is `pages/update-live`, not
	 * `pages/publish`.
	 *
	 * @param array<string,mixed> $input Call input.
	 */
	private function currentStatus( array $input ): string {
		if ( ! isset( $input['id'] ) || ! is_numeric( $input['id'] ) ) {
			return '';
		}
		if ( ! function_exists( 'get_post_status' ) ) {
			return '';
		}

		$status = get_post_status( (int) $input['id'] );

		return is_string( $status ) ? $status : '';
	}

	/**
	 * The S10 verb predicate: ability + input => PACK verb. This is the seam the
	 * S7 plan fence resolves every ability call through, so a call is tiered by
	 * what it actually does, not by which ability carries it.
	 *
	 * Dispatch is on the ability's name SEGMENT, so it holds whether the role
	 * resolved to `senroflux/update-post` or a shape-compatible `core/update-post`
	 * (S9: for a core-filled role the pack still names the verb).
	 *
	 * 0.3 S4: `update-post` is draft-state edits only, so it is always
	 * `pages/update-draft` regardless of args — the publish/update-live split
	 * that used to live on ONE ability now lives on `publish-post`.
	 *
	 * @param string              $ability The concrete ability id.
	 * @param array<string,mixed> $input   Call input.
	 */
	public function verbFor( string $ability, array $input ): string {
		return match ( $this->baseName( $ability ) ) {
			'read-content'    => 'pages/read',
			'list-patterns'   => 'pages/list-patterns',
			'get-preview-url' => 'pages/preview',
			'create-post'     => 'pages/create-draft',
			'update-post'     => 'pages/update-draft',
			'publish-post'    => $this->publishVerb( $input ),
			'media-search'       => 'pages/media-search',
			'list-missing-alt'   => 'pages/list-missing-alt',
			'media-upload'       => 'pages/media-upload',
			'generate-image'     => 'pages/media-generate',
			// Not in S5's table (see PostsPack::verbFor()'s identical
			// deviation note): a suggestion changes nothing on the site, so
			// this is a Tier-0 read-alike, not the Tier-1 write `generate-image`
			// itself.
			'generate-alt-text'  => 'pages/generate-alt-text',
			'set-featured-image' => 'pages/set-featured-image',
			'update-alt'         => 'pages/update-alt',
			'read-media'         => 'pages/read-media',
			'stock-image-search' => 'pages/media-stock-search',
			'stock-image-import' => 'pages/media-stock-import',
			// S9: an ability this pack does not name keeps the ability id as
			// its verb, which no entry of verbMap() answers — the fence then
			// fails closed on it.
			default           => $ability,
		};
	}

	/**
	 * The publish-post predicate (0.3 S4): a transition to publish or future is
	 * `pages/publish` (tier 2); any other call reaching this ability — editing
	 * an already-public target, or re-asserting its current public status — is
	 * `pages/update-live` (tier 2).
	 *
	 * @param array<string,mixed> $input Call input.
	 */
	private function publishVerb( array $input ): string {
		$desired = $input['status'] ?? null;
		$current = $this->currentStatus( $input );

		$transitioning = is_string( $desired )
			&& in_array( $desired, array( 'publish', 'future' ), true )
			&& $desired !== $current;

		return $transitioning ? 'pages/publish' : 'pages/update-live';
	}

	/**
	 * The S10 verb => tier table — the authoritative source for the plan fence,
	 * the plan's tier annotation and the approval-summary hook.
	 *
	 * @return array<string,int>
	 */
	public function verbMap(): array {
		return array(
			'pages/read'               => 0,
			'pages/list-patterns'      => 0,
			'pages/preview'            => 0,
			'pages/media-search'       => 0,
			'pages/list-missing-alt'   => 0,
			'pages/generate-alt-text'  => 0,
			'pages/read-media'         => 0,
			'pages/media-stock-search' => 0,
			'pages/create-draft'       => 1,
			'pages/update-draft'       => 1,
			'pages/media-upload'       => 1,
			'pages/media-generate'     => 1,
			'pages/set-featured-image' => 1,
			'pages/update-alt'         => 1,
			'pages/media-stock-import' => 1,
			'pages/update-live'        => 2,
			'pages/publish'            => 2,
		);
	}

	/**
	 * The S10 role => pack-verb split. 0.3 S4: `update` and `publish` are now
	 * SEPARATE roles/abilities, each spanning the verbs its own ability can
	 * produce — `update` (Tier 1, `update-post`) never spans a Tier-2 verb any
	 * more, which is exactly what keeps {@see Pack::agentSafetyVerbMap()} from
	 * collapsing a draft edit up to Tier 2 (0.2's bug: one ability spanning
	 * both draft and publish verbs forced every draft edit to Agent Safety's
	 * irreversible classification).
	 *
	 * @return array<string,list<string>>
	 */
	public function roleVerbs(): array {
		// Same order as roles(), so the two stay readable side by side.
		return array(
			'read'         => array( 'pages/read' ),
			'create'       => array( 'pages/create-draft' ),
			'update'       => array( 'pages/update-draft' ),
			'publish'      => array( 'pages/update-live', 'pages/publish' ),
			'preview'      => array( 'pages/preview' ),
			'patterns'     => array( 'pages/list-patterns' ),
			'search'       => array( 'pages/media-search' ),
			'missing-alt'  => array( 'pages/list-missing-alt' ),
			'upload'       => array( 'pages/media-upload' ),
			'generate'     => array( 'pages/media-generate' ),
			'alt-text'     => array( 'pages/generate-alt-text' ),
			'featured'     => array( 'pages/set-featured-image' ),
			'alt'          => array( 'pages/update-alt' ),
			'read-media'   => array( 'pages/read-media' ),
			'stock-search' => array( 'pages/media-stock-search' ),
			'stock-import' => array( 'pages/media-stock-import' ),
		);
	}

	/**
	 * S12 (defect fix, mirrors PostsPack): `update-alt`, `read-media` and the
	 * three attachment-producing media verbs carry the attachment id as
	 * `attachment_id`, never `id`; `set-featured-image` answers the `post_id`
	 * it changed. The base's default would silently track/verify nothing for
	 * any of them.
	 *
	 * @param string $verb The pack verb.
	 */
	public function objectIdKey( string $verb ): string {
		return match ( $verb ) {
			'pages/update-alt',
			'pages/read-media',
			'pages/media-upload',
			'pages/media-generate',
			'pages/media-stock-import' => 'attachment_id',
			'pages/set-featured-image' => 'post_id',
			default => parent::objectIdKey( $verb ),
		};
	}

	/**
	 * S12 (defect fix, mirrors PostsPack): an attachment and a page can share
	 * the same numeric id, so the verbs above qualify it with
	 * {@see Media::OBJECT_ID_PREFIX} before the harness ever sees it.
	 *
	 * @param string $verb The pack verb.
	 */
	public function objectIdPrefix( string $verb ): string {
		return match ( $verb ) {
			'pages/update-alt',
			'pages/read-media',
			'pages/media-upload',
			'pages/media-generate',
			'pages/media-stock-import' => Media::OBJECT_ID_PREFIX,
			default => parent::objectIdPrefix( $verb ),
		};
	}

	/**
	 * S6: `media-upload` and `generate-image` require `upload_files` — a role
	 * that can `edit_pages` but not `upload_files` holds neither in stock
	 * WordPress, but a custom role could, so this is withheld the same way
	 * the posts pack withholds it.
	 *
	 * @return array<string,string>
	 */
	public function roleCapabilities(): array {
		return array(
			'upload'       => 'upload_files',
			'generate'     => 'upload_files',
			'stock-import' => 'upload_files',
		);
	}

	/**
	 * S6: one line, in the pack's own words, when the image roles are
	 * withheld.
	 *
	 * @param list<string> $withheld The role names withheld from this run's start().
	 */
	public function withheldRoleNotice( array $withheld ): ?string {
		if ( in_array( 'upload', $withheld, true ) || in_array( 'generate', $withheld, true ) || in_array( 'stock-import', $withheld, true ) ) {
			return __( 'Images are off for this run — your account can\'t upload files.', 'senroflux' );
		}

		return null;
	}

	/**
	 * 0.3 quality fix: the pages pack's own budget override, applied over the
	 * shipped table before `senroflux_default_budget` runs (same seam as
	 * {@see \Specflux\SenroFlux\Packs\Site\SitePack::defaultBudget()}). Before
	 * this override existed, a pages run used the SHIPPED table unchanged
	 * (60/30/250000) — sized from runs with no image work at all. A live run
	 * that searched for and inserted an image (cover-hero/media-text, 0.3
	 * quality features 2/3) hit `max_steps` at 61: image tool calls plus their
	 * own retries were never in the sample the shipped table was sized from.
	 * `images` stays at the shipped default (6) — image COUNT didn't move,
	 * only the steps/calls/tokens spent finding and placing them. Tokens went
	 * 400000 -> 600000 after space-bunny live runs (2026-09-28 bunny1-4) spent
	 * 315k-396k and six of eight died of `max_tokens`; 600000 -> 800000 after
	 * "make my site better" spent 592k, 592k and 617k (2026-09-28/29);
	 * 800000 -> 1000000 after it spent 630k, 639k, 799k and 815k (failed)
	 * in 2026-09-29 final6, cards1, cards2 and seed1.
	 *
	 * @return array<string,int>
	 */
	public function defaultBudget(): array {
		return array(
			Budget::MAX_STEPS      => 120,
			Budget::MAX_TOOL_CALLS => 60,
			Budget::MAX_TOKENS     => 1000000,
			Budget::IMAGES         => 6,
		);
	}

	/**
	 * The two pack skills, in render order (source Pack, version '1').
	 * `pages/content-language` used to be a third (0.2 S15); 0.3 S5 promotes
	 * it to the harness's own `harness/content-language`, shared with every
	 * pack (and no pack), so it is no longer declared here.
	 *
	 * @return list<Skill>
	 */
	public function skills( bool $images_available = true ): array {
		$vocabulary = new Vocabulary();

		return array(
			new Skill(
				'pages/layout-rules',
				'Layout rules',
				$this->layoutRulesBody(),
				false,
				SkillSource::Pack,
				'1'
			),
			new Skill(
				'pages/copy-rules',
				'Copy rules',
				$this->copyRulesBody( $vocabulary->curated() ),
				false,
				SkillSource::Pack,
				'1'
			),
			new Skill(
				'pages/media-rules',
				'Media rules',
				$this->mediaRulesBody( $images_available ),
				false,
				SkillSource::Pack,
				'1'
			),
		);
	}

	/**
	 * The `pages/layout-rules` body (S11). Plain English; the shape lines are
	 * the Validator's structural identity restated for the model (blockName
	 * tree + the layout-defining attributes it keys on), because the pack sends
	 * the model constraints, never markup (S11) — a model told only the prose
	 * constraints writes `<!-- wp:senroflux/hero -->` and is refused
	 * `unknown_block` on its first write (observed live, stage 10).
	 * This is content, never translated (S15 — skill bodies stay English).
	 */
	private function layoutRulesBody(): string {
		// S23 (F3): the lines themselves moved to Layouts::rulesLines() so
		// Api\LayoutVocabulary can expose them to a third party without
		// depending on this pack; this stays the single call site that
		// joins them into a skill body.
		return implode( "\n", Layouts::rulesLines() );
	}

	/**
	 * The `pages/copy-rules` body (S11): a pointer to `pages/list-patterns`
	 * for each pattern's copy limits ({@see Pack::copyRulesLines()}), plus the
	 * global copy limits.
	 *
	 * @param list<array<string,mixed>> $vocabulary {@see Vocabulary::all()}.
	 */
	public function copyRulesBody( array $vocabulary ): string {
		$lines = self::copyRulesLines( $vocabulary, 'pages/list-patterns' );

		$lines[] = 'Say plainly what the client gets and what happens; no hedges like "may help" or "can be discussed" unless the brief itself hedges.';
		$lines[] = 'Card bodies are at most 40 words.';
		$lines[] = 'Buttons are verb-first (for example "Get started").';
		$lines[] = 'Use a pricing pattern only when the user gave the prices; never write a placeholder price.';

		return implode( "\n", $lines );
	}

	/**
	 * The `pages/media-rules` body (0.3 quality feature 4, mirrors
	 * PostsPack::mediaRulesBody()): search before generating, alt text is
	 * mandatory for a theme pattern's image slot, generation costs real
	 * money, and re-read after any change — nothing else re-reads an
	 * attachment for you.
	 */
	private function mediaRulesBody( bool $images_available = true ): string {
		if ( ! $images_available ) {
			// 0.3 quality fix (images budget 0): no mention of the withheld
			// media-generate ability — search then stock is the only path
			// this run's tool surface actually offers.
			return implode(
				"\n",
				array(
					'A page image lives ONLY in a layout\'s image slot (or a cover-hero or media-text you write), and each slot needs a different image. Image generation is not available in this run: search the media library first, then stock-image-search and stock-image-import; else no_image_reason.',
					'Write each image\'s alt text yourself and put it in the slot with the URL (a layout\'s image.alt). No update-alt call is needed for that.',
					'After update-alt, media-upload or stock-image-import, call read-media on that attachment id to confirm the change saved — nothing else re-reads it for you.',
				)
			);
		}

		return implode(
			"\n",
			array(
				'A page image lives ONLY in a layout\'s image slot (or a cover-hero or media-text you write), and each slot needs a different image. Search the media library first; generate only what is missing.',
				'Write each image\'s alt text yourself and put it in the slot with the URL (a layout\'s image.alt). No update-alt call is needed for that.',
				'Generating an image costs real money and a limited run budget; do not generate more than the goal actually needs.',
				'No image budget left? stock-image-search, then stock-image-import; else no_image_reason.',
				'After update-alt, media-upload or generate-image, call read-media on that attachment id to confirm the change saved — nothing else re-reads it for you.',
			)
		);
	}

	/**
	 * The Agent Safety pack descriptor (S10), registered on
	 * `agent_safety_pack_registry`.
	 *
	 * `allow` is the RESOLVED ABILITY LIST, not the `pages/*` verb list, and
	 * that is not a category error — at the gate seam that governs this plugin
	 * the Agent Safety verb IS the ability id: `AbilityPermissionGate::wrap()`
	 * hands the registered ability name to `VerdictPipeline::judge()`, which
	 * passes it to `Gate::evaluate()`, which tests it with `Pack::allows()`
	 * (agent-safety plugin/src/Hooks/AbilityPermissionGate.php:113,
	 * plugin/src/Verdict/VerdictPipeline.php:70, src/Gate/Gate.php:39). Agent
	 * Safety's own `CorePacks` allows `core/read-content` for the same reason.
	 * An allow-list of `pages/*` here would deny every call as `not_in_pack`.
	 *
	 * Tier-2 abilities are approval-gated (`approvalByClass: ['tier2' => true]`);
	 * nothing is denied — everything outside the verb map fails closed in Agent
	 * Safety anyway.
	 *
	 * @return object|null null when the Agent Safety pack class is absent.
	 */
	public function agentSafetyPack(): ?object {
		if ( ! class_exists( \Specflux\AgentSafety\Packs\Pack::class ) ) {
			return null;
		}

		return new \Specflux\AgentSafety\Packs\Pack(
			name: 'pages',
			allow: $this->allowList(),
			approvalByClass: array( 'tier2' => true ),
		);
	}

	/**
	 * S13 real binding check. Uses the Agent Safety plugin's `PackResolver` and
	 * `PackRegistry` (plugin/src/Support/PackResolver.php + core
	 * src/Packs/PackRegistry.php):
	 *   - identity tokens resolve like `RequestContext::currentTokens()` —
	 *     `user:{$id}` then `role:{$role}` (UserRoleIdentity::currentTokens());
	 *   - a credential→pack binding lives in the `agsafe_pack_bindings` option
	 *     (`PackResolver::BINDINGS_OPTION`), exposed by `registry()->bindings()`;
	 *   - `registry()->resolve($subject)` returns the resolved pack, falling back
	 *     to the `default-agent` pack (`allow: []`) — fail closed.
	 * A user is bound when the FIRST bound token resolves to a pack that allows
	 * every resolved ABILITY (the Agent Safety verb at this seam — see
	 * {@see agentSafetyPack()}) and approval-gates Tier 2. Any other
	 * outcome — Agent Safety absent, the pack classes missing, no binding, a
	 * binding to the empty default pack, an allow gap, or missing Tier-2
	 * approval — is `pack_unbound` (400).
	 *
	 * @return WP_Error|null null when bound, else pack_unbound.
	 */
	protected function agentSafetyBindingError( int $user_id ): ?WP_Error {
		if ( ! function_exists( 'agent_safety' ) || null === agent_safety() ) {
			return $this->packUnboundError();
		}

		if ( ! class_exists( \Specflux\AgentSafety\Plugin\Support\PackResolver::class )
			|| ! class_exists( \Specflux\AgentSafety\Packs\Pack::class )
			|| ! class_exists( \Specflux\AgentSafety\Policy\Tier::class )
		) {
			return $this->packUnboundError();
		}

		$resolved = $this->resolveAsPackForUser( $user_id );
		if ( null === $resolved ) {
			return $this->packUnboundError();
		}

		foreach ( $this->allowList() as $ability ) {
			if ( ! $resolved->allows( $ability ) ) {
				return $this->packUnboundError();
			}
		}

		if ( ! $resolved->requiresApproval( \Specflux\AgentSafety\Policy\Tier::Irreversible ) ) {
			return $this->packUnboundError();
		}

		return null;
	}

	/**
	 * Resolve the AS pack for a SPECIFIC user (not the current request): the
	 * first bound token (`user:N` then `role:<slug>`) resolves to a pack, else
	 * the registry default (fail-closed `default-agent`).
	 *
	 * @return \Specflux\AgentSafety\Packs\Pack|null
	 */
	private function resolveAsPackForUser( int $user_id ): ?\Specflux\AgentSafety\Packs\Pack {
		$resolver = new \Specflux\AgentSafety\Plugin\Support\PackResolver();
		$registry = $resolver->registry();
		$bindings = $registry->bindings();

		foreach ( $this->userTokens( $user_id ) as $token ) {
			if ( isset( $bindings[ $token ] ) ) {
				$pack = $registry->get( $bindings[ $token ] );
				if ( null !== $pack ) {
					return $pack;
				}
			}
		}

		return $registry->resolve( null );
	}

	/**
	 * Identity tokens for a user id, in binding priority order (mirrors
	 * UserRoleIdentity::currentTokens but for an arbitrary user).
	 *
	 * @return list<string>
	 */
	private function userTokens( int $user_id ): array {
		$tokens = array( 'user:' . $user_id );

		if ( function_exists( 'get_userdata' ) ) {
			$user = get_userdata( $user_id );
			if ( $user && is_array( $user->roles ) ) {
				foreach ( $user->roles as $role ) {
					if ( is_string( $role ) && '' !== $role ) {
						$tokens[] = 'role:' . $role;
					}
				}
			}
		}

		return $tokens;
	}

	/**
	 * @return WP_Error pack_unbound (400).
	 */
	private function packUnboundError(): WP_Error {
		return new WP_Error(
			'pack_unbound',
			__( 'This pack is not bound to your user. Ask an administrator to bind `user:N` or `role:administrator` to the pages pack.', 'senroflux' ),
			array( 'status' => 400 )
		);
	}
}
