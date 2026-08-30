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

use Specflux\SenroFlux\Packs\Pack;
use Specflux\SenroFlux\Run\Tail;
use Specflux\SenroFlux\Skills\Skill;
use Specflux\SenroFlux\Skills\SkillSource;
use WP_Error;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * The pages pack.
 */
final class PagesPack extends Pack {

	/**
	 * @param string|null $content_locale The site content locale (S15); null → the
	 *                                    content-language skill says "the site language".
	 */
	public function __construct( private readonly ?string $content_locale = null ) {
		parent::__construct(
			array(
				'read'     => 'read-content',
				'create'   => 'create-post',
				'update'   => 'update-post',
				'preview'  => 'get-preview-url',
				'patterns' => 'list-patterns',
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
			'create-post'     => array( 'post_type', 'title', 'content', 'status', 'slug', 'parent', 'excerpt' ),
			'update-post'     => array( 'id', 'post_type', 'title', 'content', 'status', 'slug', 'parent', 'excerpt' ),
			'get-preview-url' => array( 'id' ),
			'list-patterns'   => array(),
			default           => array(),
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
	 * @param string              $ability The concrete ability id.
	 * @param array<string,mixed> $input   Call input.
	 */
	public function verbFor( string $ability, array $input ): string {
		return match ( $this->baseName( $ability ) ) {
			'read-content'    => 'pages/read',
			'list-patterns'   => 'pages/list-patterns',
			'get-preview-url' => 'pages/preview',
			'create-post'     => 'pages/create-draft',
			'update-post'     => $this->updateVerb( $input ),
			// S9: an ability this pack does not name keeps the ability id as
			// its verb, which no entry of verbMap() answers — the fence then
			// fails closed on it.
			default           => $ability,
		};
	}

	/**
	 * The update-post predicate (S10): a transition to publish is
	 * `pages/publish` (tier 2); a publish target with the status unchanged is
	 * `pages/update-live` (tier 2); a draft|pending target (or no status at
	 * all) is `pages/update-draft` (tier 1).
	 *
	 * @param array<string,mixed> $input Call input.
	 */
	private function updateVerb( array $input ): string {
		$desired = $input['status'] ?? null;
		if ( ! is_string( $desired ) || '' === $desired ) {
			return 'pages/update-draft';
		}

		$current = $this->currentStatus( $input );

		if ( 'publish' === $desired && $desired !== $current ) {
			return 'pages/publish';
		}

		if ( 'publish' === $desired ) {
			return 'pages/update-live';
		}

		return 'pages/update-draft';
	}

	/**
	 * The S10 verb => tier table — the authoritative source for the plan fence,
	 * the plan's tier annotation and the approval-summary hook.
	 *
	 * @return array<string,int>
	 */
	public function verbMap(): array {
		return array(
			'pages/read'          => 0,
			'pages/list-patterns' => 0,
			'pages/preview'       => 0,
			'pages/create-draft'  => 1,
			'pages/update-draft'  => 1,
			'pages/update-live'   => 2,
			'pages/publish'       => 2,
		);
	}

	/**
	 * The S10 role => pack-verb split. `update` is the one role that spans more
	 * than one verb, which is exactly what {@see Pack::agentSafetyVerbMap()}
	 * collapses (upwards) into the single tier Agent Safety can carry for
	 * `senroflux/update-post`.
	 *
	 * @return array<string,list<string>>
	 */
	public function roleVerbs(): array {
		// Same order as roles(), so the two stay readable side by side.
		return array(
			'read'     => array( 'pages/read' ),
			'create'   => array( 'pages/create-draft' ),
			'update'   => array( 'pages/update-draft', 'pages/update-live', 'pages/publish' ),
			'preview'  => array( 'pages/preview' ),
			'patterns' => array( 'pages/list-patterns' ),
		);
	}

	/**
	 * The three pack skills, in render order (source Pack, version '1').
	 *
	 * @return list<Skill>
	 */
	public function skills(): array {
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
				$this->copyRulesBody( $vocabulary->all() ),
				false,
				SkillSource::Pack,
				'1'
			),
			new Skill(
				'pages/content-language',
				'Content language',
				$this->contentLanguageBody(),
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
		return implode(
			"\n",
			array(
				'Compose pages ONLY from the pattern vocabulary: hero, text-section, feature-grid, pricing-table, faq, testimonials and cta. Put the hero first. Use at most one cta. A page is 2–8 patterns. No pattern more than twice except text-section. No core/image anywhere. No colour attributes. Set spacing and typography only through the standard preset slugs. Re-read every object after writing.',
				'A pattern is NOT a block: it is a core/group you write yourself out of core blocks. Never write a block whose name starts with senroflux/. Use only these blocks: core/group, core/heading, core/paragraph, core/buttons, core/button, core/columns, core/column, core/list, core/details, core/quote.',
				'Write each block comment with compact JSON (no spaces after : or ,). Give every top-level group `{"metadata":{"name":"senroflux/<slug>"},"layout":{"type":"constrained"}}`. Write list items as plain <li> inside one core/list block; never core/list-item. In an faq, the question is the <summary> element inside the core/details block and the answer is a core/paragraph block inside it.',
				'To publish a page you already created, call the update ability with the id and status only and OMIT content entirely — do not send an empty content either. Omitted or empty content means "content unchanged": the stored markup is kept as it is. Never resend content you have not changed; re-sending it risks a whole-write refusal on markup that is already stored and accepted.',
				'Close everything you open: every `<!-- wp:x -->` needs its matching `<!-- /wp:x -->`, and every wrapper element a block opens (a group\'s <div>, a details, a list) must be closed before that block ends. Markup that does not survive a parse-and-reserialise round trip is refused whole as invalid_markup.',
				'When you propose a plan, spell each step\'s verbs exactly as one of: pages/read, pages/list-patterns, pages/preview, pages/create-draft, pages/update-draft, pages/update-live, pages/publish. Creating the page as a draft is pages/create-draft; making a draft live is pages/publish. Any other word is refused as unknown_verb.',
				'Give a block ONLY the attributes its shape names below. An attribute the shape does not name — an extra align, an extra layout — changes the pattern\'s identity and the write is refused as unknown_pattern. In particular only the hero and the cta give their buttons block `{"layout":{"type":"flex"}}`; a buttons block anywhere else carries no layout at all.',
				'Shapes (">" = child, "(n–m)" = how many of that child):',
				'hero: group align=full > heading level 1, paragraph align=center, buttons layout=flex > button (1–2)',
				'text-section: group > heading level 2, paragraph (1–4)',
				'feature-grid: group > heading level 2, columns > column (2–3) each > heading level 3, paragraph',
				'pricing-table: group > heading level 2, columns > column (1–3) each > heading level 3, paragraph, list (3–6 items), buttons > button',
				'faq: group > heading level 2, details (2–8) each > paragraph',
				'testimonials: group > heading level 2, quote (1–3)',
				'cta: group align=full > heading level 2, paragraph align=center, buttons layout=flex > button (1)',
			)
		);
	}

	/**
	 * The `pages/copy-rules` body (S11) — RENDERED from the vocabulary's
	 * `constraints.stated` lines so the tool payload and the instruction can
	 * never drift (asserted by the Vocabulary/Validator single-source test). It
	 * appends the global copy limits.
	 *
	 * @param list<array<string,mixed>> $vocabulary {@see Vocabulary::all()}.
	 */
	public function copyRulesBody( array $vocabulary ): string {
		$lines = array();
		foreach ( $vocabulary as $pattern ) {
			$stated = $pattern['constraints']['stated'] ?? array();
			foreach ( $stated as $line ) {
				$lines[] = $line;
			}
		}

		$lines[] = 'Card bodies are at most 18 words.';
		$lines[] = 'Buttons are verb-first (for example "Get started").';
		$lines[] = 'Give prices as "$—/month (price TBC)" unless the user supplied a price.';

		return implode( "\n", $lines );
	}

	/**
	 * The `pages/content-language` body (S15): names the site content language,
	 * or "the site language" when unknown.
	 */
	private function contentLanguageBody(): string {
		$name = 'the site language';
		if ( null !== $this->content_locale ) {
			$resolved = Tail::languageName( $this->content_locale );
			if ( null !== $resolved && '' !== $resolved ) {
				$name = $resolved;
			}
		}

		return sprintf( 'Write page content in %s unless the goal says otherwise.', $name );
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
