<?php
/**
 * The pages capability pack (S9/S10/S11/S13/S15).
 *
 * TARGET REPO PATH: src/Packs/Pages/PagesPack.php
 *
 * Role → ability template map (resolved `core/*` vs `senroflux/*` by the
 * base), the S10 verb map + verb predicate, the three pack skills, the AS pack
 * descriptor, and the S13 preflight with the REAL Capability-Packs binding
 * check (stage 8 — the base leaves it as a guarded no-op).
 *
 * ISOLATION RULE (harness contract): this pack feeds the Runner through the
 * base's explicit seams only (allow-list / skills / verb map). It never
 * touches the run loop.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Packs\Pages;

use Specflux\SenroFlux\Packs\Pack;
use Specflux\SenroFlux\Run\Tail;
use Specflux\SenroFlux\Skills\Skill;
use Specflux\SenroFlux\Skills\SkillSet;
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
	 * @return string 'pages'.
	 */
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
	protected function currentStatus( array $input ): string {
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
	 * S10 verb predicate. The base already encodes the exact rules, keyed on
	 * {@see currentStatus()} (overridden above) — this override exists to make
	 * the contract explicit and to document that the predicate is exactly the
	 * S10 table and never drifts from it.
	 *
	 * @param string              $ability The concrete ability id.
	 * @param array<string,mixed> $input   Call input.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UselessOverridingMethod.Found -- documents the S10 verb contract explicitly; the override must stay to pin that the rule set never drifts.
	public function verbFor( string $ability, array $input ): string {
		return parent::verbFor( $ability, $input );
	}

	/**
	 * The S10 verb => tier map (unchanged from the base; documented here as the
	 * authoritative source for the stage-8 fence and the approval-summary hook).
	 *
	 * @return array<string,int>
	 */
	// phpcs:ignore Generic.CodeAnalysis.UselessOverridingMethod.Found -- documents the S10 verb=>tier map as the authoritative source for the stage-8 fence and approval-summary hook.
	public function verbMap(): array {
		return parent::verbMap();
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
	 * The `pages/layout-rules` body (S11). Plain English, at most ~90 words.
	 * This is content, never translated (S15 — skill bodies stay English).
	 */
	private function layoutRulesBody(): string {
		return 'Compose pages ONLY from the pattern vocabulary: hero, text-section, feature-grid, pricing-table, faq, testimonials and cta. Put the hero first. Use at most one cta. A page is 2–8 patterns. No pattern more than twice except text-section. No core/image anywhere. No colour attributes. Set spacing and typography only through the standard preset slugs. Re-read every object after writing. ';
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
	 * The Agent Safety pack descriptor (S10). Per the fixture in
	 * dev/smoke/senroflux-spike-fixture.php the AS pack allows ABILITY NAMES,
	 * so `allow` is the resolved allow-list; Tier-2 verbs are approval-gated
	 * (`approvalByClass: ['tier2' => true]`); nothing is denied (everything
	 * outside the map fails closed in Agent Safety anyway).
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
	 * S13 preflight — skills ceiling first, then the REAL fail-closed binding
	 * check. Unlike the base (which only checks the binding when `agent_safety`
	 * exists), the pages pack fails closed when Agent Safety is absent: the
	 * Capability Pack is the governance seam, and a missing seam is not a gated
	 * pass.
	 *
	 * @param int $user_id The user the run would be started for.
	 * @return true|WP_Error
	 */
	public function preflight( int $user_id ): true|WP_Error {
		// S8 ceiling over the COMBINED harness + pack skill set.
		$skills  = SkillSet::collect( '', '', $this->skills() );
		$ceiling = SkillSet::ceilingError( $skills );
		if ( null !== $ceiling ) {
			return $ceiling;
		}

		$unbound = $this->agentSafetyBindingError( $user_id );
		if ( null !== $unbound ) {
			return $unbound;
		}

		return true;
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
	 * every resolved ability (Tier 0/1) and approval-gates Tier 2. Any other
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
