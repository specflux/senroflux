<?php
/**
 * The site capability pack (0.3 S7).
 *
 * TARGET REPO PATH: src/Packs/Site/SitePack.php
 *
 * Supersets the pages pack: the same six content-registrar roles (under the
 * `site` slug's own vocabulary/validator, S4 point 4) plus site navigation
 * and front-page roles. The pages pack itself is UNTOUCHED and stays at
 * `edit_pages` — this is a second, separate pack, run capability
 * `manage_options`.
 *
 * ISOLATION RULE (harness contract): this pack feeds the Runner through the
 * base's explicit seams only. It never touches the run loop.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Packs\Site;

use Specflux\SenroFlux\Packs\Pack;
use Specflux\SenroFlux\Run\Budget;
use Specflux\SenroFlux\Skills\Skill;
use Specflux\SenroFlux\Skills\SkillSource;
use WP_Error;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * The site pack.
 */
final class SitePack extends Pack {

	public function __construct() {
		parent::__construct(
			array(
				'read'       => 'read-content',
				'create'     => 'create-post',
				'update'     => 'update-post',
				'publish'    => 'publish-post',
				'preview'    => 'get-preview-url',
				'patterns'   => 'list-patterns',
				'read-nav'   => 'read-navigation',
				'update-nav' => 'update-navigation',
				'read-front' => 'read-front-page',
				'set-front'  => 'set-front-page',
			)
		);
	}

	/**
	 * Register the pack's pattern vocabulary (the pages seven plus the two
	 * homepage-only patterns). Called from the composition root on `init`;
	 * `Vocabulary::register()` is idempotent.
	 *
	 * @return int Number of patterns registered this call.
	 */
	public function registerPatterns(): int {
		return ( new Vocabulary() )->register();
	}

	/**
	 * @return string 'site'.
	 */
	public function name(): string {
		return 'site';
	}

	/**
	 * @return string 'manage_options' (S7: the site pack's run capability).
	 */
	public function runCapability(): string {
		return 'manage_options';
	}

	/**
	 * The input-property keys this pack's client sends, per ability template
	 * (S9 shape-compat seam). The six content-registrar templates are shared
	 * with the pages/posts packs; navigation/front-page are this pack's own.
	 *
	 * @param string $template Ability template.
	 * @return list<string>
	 */
	protected function inputProperties( string $template ): array {
		return match ( $template ) {
			'read-content'      => array( 'id', 'post_type', 'slug', 'status', 'author', 'parent', 'fields' ),
			'create-post'       => array( 'post_type', 'title', 'content', 'status', 'slug', 'parent', 'excerpt' ),
			'update-post'       => array( 'id', 'post_type', 'title', 'content', 'status', 'slug', 'parent', 'excerpt' ),
			'publish-post'      => array( 'id', 'post_type', 'title', 'content', 'status', 'slug', 'parent', 'excerpt' ),
			'get-preview-url'   => array( 'id' ),
			'list-patterns'     => array(),
			'read-navigation'   => array(),
			'update-navigation' => array( 'items' ),
			'read-front-page'   => array(),
			'set-front-page'    => array( 'show_on_front', 'page_id', 'page_for_posts_id' ),
			default             => array(),
		};
	}

	/**
	 * The target post's CURRENT status, for the update/publish predicate.
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
	 * The S7 verb predicate: ability + input => PACK verb.
	 *
	 * @param string              $ability The concrete ability id the model called.
	 * @param array<string,mixed> $input   Call input.
	 */
	public function verbFor( string $ability, array $input ): string {
		return match ( $this->baseName( $ability ) ) {
			'read-content'       => 'site/read',
			'list-patterns'      => 'site/list-patterns',
			'get-preview-url'    => 'site/preview',
			'create-post'        => 'site/create-draft',
			'update-post'        => 'site/update-draft',
			'publish-post'       => $this->publishVerb( $input ),
			'read-navigation'    => 'site/read-navigation',
			'update-navigation'  => 'site/update-navigation',
			'read-front-page'    => 'site/read-front-page',
			'set-front-page'     => 'site/set-front-page',
			default              => $ability,
		};
	}

	/**
	 * The publish-post predicate (S4/S7): identical reasoning to the pages
	 * pack's.
	 *
	 * @param array<string,mixed> $input Call input.
	 */
	private function publishVerb( array $input ): string {
		$desired = $input['status'] ?? null;
		$current = $this->currentStatus( $input );

		$transitioning = is_string( $desired )
			&& in_array( $desired, array( 'publish', 'future' ), true )
			&& $desired !== $current;

		return $transitioning ? 'site/publish' : 'site/update-live';
	}

	/**
	 * The S7 verb => tier table. `site/update-navigation` is ALWAYS Tier 2
	 * (S7: "no create, no assign-location, no delete" — replacing a
	 * navigation's items is treated exactly like any other public-effect
	 * change, never argument-dependent).
	 *
	 * @return array<string,int>
	 */
	public function verbMap(): array {
		return array(
			'site/read'              => 0,
			'site/list-patterns'     => 0,
			'site/preview'           => 0,
			'site/create-draft'      => 1,
			'site/update-draft'      => 1,
			'site/update-live'       => 2,
			'site/publish'           => 2,
			'site/read-navigation'   => 0,
			'site/update-navigation' => 2,
			'site/read-front-page'   => 0,
			'site/set-front-page'    => 2,
		);
	}

	/**
	 * The S7 role => pack-verb split.
	 *
	 * @return array<string,list<string>>
	 */
	public function roleVerbs(): array {
		return array(
			'read'       => array( 'site/read' ),
			'create'     => array( 'site/create-draft' ),
			'update'     => array( 'site/update-draft' ),
			'publish'    => array( 'site/update-live', 'site/publish' ),
			'preview'    => array( 'site/preview' ),
			'patterns'   => array( 'site/list-patterns' ),
			'read-nav'   => array( 'site/read-navigation' ),
			'update-nav' => array( 'site/update-navigation' ),
			'read-front' => array( 'site/read-front-page' ),
			'set-front'  => array( 'site/set-front-page' ),
		);
	}

	/**
	 * S7: the site pack's own flat-and-high budget, applied over the shipped
	 * table BEFORE `senroflux_default_budget` runs (see
	 * {@see \Specflux\SenroFlux\Run\Budget::defaults()}). Never derived from
	 * the plan — sized for a full skeleton run (clarify + several pages +
	 * navigation + front page), not from measured runs the way the shipped
	 * table is.
	 *
	 * @return array<string,int>
	 */
	public function defaultBudget(): array {
		return array(
			Budget::MAX_STEPS      => 200,
			Budget::MAX_TOOL_CALLS => 120,
			Budget::MAX_TOKENS     => 1000000,
			Budget::MAX_QUESTIONS  => 8,
			Budget::MAX_PLANS      => 3,
			Budget::IMAGES         => 0,
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
				'site/layout-rules',
				'Layout rules',
				$this->layoutRulesBody(),
				false,
				SkillSource::Pack,
				'1'
			),
			new Skill(
				'site/copy-rules',
				'Copy rules',
				$this->copyRulesBody( $vocabulary->all() ),
				false,
				SkillSource::Pack,
				'1'
			),
			new Skill(
				'site/structure-rules',
				'Structure rules',
				$this->structureRulesBody(),
				false,
				SkillSource::Pack,
				'1'
			),
		);
	}

	/**
	 * The `site/layout-rules` body — the pages layout rules, extended with the
	 * two homepage-only patterns and the site pack's own (longer) verb list.
	 */
	private function layoutRulesBody(): string {
		return implode(
			"\n",
			array(
				'Compose pages ONLY from the pattern vocabulary: hero, text-section, feature-grid, pricing-table, faq, testimonials, cta, page-links and intro. Put the hero first. Use at most one cta and at most one page-links. A page is 2–8 patterns. No pattern more than twice except text-section. No core/image anywhere. No colour attributes. Set spacing and typography only through the standard preset slugs. Re-read every object after writing.',
				'A pattern is NOT a block: it is a core/group you write yourself out of core blocks. Never write a block whose name starts with senroflux/. Use only these blocks: core/group, core/heading, core/paragraph, core/buttons, core/button, core/columns, core/column, core/list, core/details, core/quote.',
				'Write each block comment with compact JSON (no spaces after : or ,). Give every top-level group `{"metadata":{"name":"senroflux/<slug>"},"layout":{"type":"constrained"}}`. Close everything you open. Markup that does not survive a parse-and-reserialise round trip is refused whole as invalid_markup.',
				'page-links: a homepage grid of 2–6 cards, each a heading, a paragraph and a button linking to one of the site\'s other skeleton pages. intro: one heading, one paragraph and one button.',
				'When you propose a plan, spell each step\'s verbs exactly as one of: site/read, site/list-patterns, site/preview, site/create-draft, site/update-draft, site/update-live, site/publish, site/read-navigation, site/update-navigation, site/read-front-page, site/set-front-page. Any other word is refused as unknown_verb.',
			)
		);
	}

	/**
	 * The `site/copy-rules` body — RENDERED from the vocabulary's
	 * `constraints.stated` lines (the nine patterns), same single-source
	 * discipline the pages/posts packs use.
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
	 * The `site/structure-rules` body (S7 adoption + navigation ordering).
	 */
	private function structureRulesBody(): string {
		return implode(
			"\n",
			array(
				'At clarify, read the existing pages, the site navigation and the front-page settings before asking anything else.',
				'Once clarify is done, your very next call is `senroflux/propose-plan` — never a plain-text reply describing what you intend to do. The plan must list every page you will create, every adopted object, and the publish and navigation steps that follow.',
				'Your plan must list every EXISTING object it will touch — title, id and status — beside any new ones. Match an existing page by slug or title and ADOPT it as-is; never create a second page for something that already exists.',
				'Rewrite an adopted page only when the human asked for a rewrite at clarify; a plain rewrite step still goes through the update/publish verbs. Publish an adopted draft only when the plan names it "publish existing draft".',
				'If the navigation reports kind "page_list", say in plain words that publishing pages already changes the header automatically; only call update-navigation when the goal needs a specific order or a specific subset of pages.',
				'Nothing existing is deleted. A leftover default-install object you do not need is left alone and named in your final summary as left for the human to remove.',
				'Write the navigation update AFTER every page in the plan has been published, never before — a link to a page that is not yet published is refused.',
			)
		);
	}

	/**
	 * The Agent Safety pack descriptor (allow/deny/approvalByTier).
	 *
	 * @return object|null null when the Agent Safety pack class is absent.
	 */
	public function agentSafetyPack(): ?object {
		if ( ! class_exists( \Specflux\AgentSafety\Packs\Pack::class ) ) {
			return null;
		}

		return new \Specflux\AgentSafety\Packs\Pack(
			name: 'site',
			allow: $this->allowList(),
			approvalByClass: array( 'tier2' => true ),
		);
	}

	/**
	 * S13 real binding check — identical reasoning to the pages/posts packs'.
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
			__( 'This pack is not bound to your user. Ask an administrator to bind `user:N` or `role:administrator` to the site pack.', 'senroflux' ),
			array( 'status' => 400 )
		);
	}
}
