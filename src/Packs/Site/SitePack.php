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

use Specflux\SenroFlux\Packs\Content\Media;
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
				'read'         => 'read-content',
				'create'       => 'create-post',
				'update'       => 'update-post',
				'publish'      => 'publish-post',
				'preview'      => 'get-preview-url',
				'patterns'     => 'list-patterns',
				'read-nav'     => 'read-navigation',
				'update-nav'   => 'update-navigation',
				'read-front'   => 'read-front-page',
				'set-front'    => 'set-front-page',
				// 0.3 quality feature 4: the same media roles the pages pack
				// gained, mirrored verbatim.
				'search'       => 'media-search',
				'missing-alt'  => 'list-missing-alt',
				'upload'       => 'media-upload',
				'generate'     => 'generate-image',
				'alt-text'     => 'generate-alt-text',
				'featured'     => 'set-featured-image',
				'alt'          => 'update-alt',
				'read-media'   => 'read-media',
				'stock-search' => 'stock-image-search',
				'stock-import' => 'stock-image-import',
				// 0.3 quality feature 5: the style-variation pair.
				'read-style'   => 'read-style',
				'set-style'    => 'set-style',
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
			'create-post'       => array( 'post_type', 'title', 'content', 'sections', 'status', 'slug', 'parent', 'excerpt', 'no_image_reason' ),
			'update-post'       => array( 'id', 'post_type', 'title', 'content', 'sections', 'status', 'slug', 'parent', 'excerpt', 'no_image_reason' ),
			'publish-post'      => array( 'id', 'post_type', 'title', 'content', 'sections', 'status', 'slug', 'parent', 'excerpt', 'no_image_reason' ),
			'get-preview-url'   => array( 'id' ),
			'list-patterns'     => array(),
			'read-navigation'    => array(),
			'update-navigation'  => array( 'items' ),
			'read-front-page'    => array(),
			'set-front-page'     => array( 'show_on_front', 'page_id', 'page_for_posts_id' ),
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
			'read-style'         => array(),
			'set-style'          => array( 'slug' ),
			default              => array(),
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
			'media-search'       => 'site/media-search',
			'list-missing-alt'   => 'site/list-missing-alt',
			'media-upload'       => 'site/media-upload',
			'generate-image'     => 'site/media-generate',
			// Documented deviation (mirrors PostsPack/PagesPack): a
			// suggestion changes nothing on the site, so this is Tier 0.
			'generate-alt-text'  => 'site/generate-alt-text',
			'set-featured-image' => 'site/set-featured-image',
			'update-alt'         => 'site/update-alt',
			'read-media'         => 'site/read-media',
			'stock-image-search' => 'site/media-stock-search',
			'stock-image-import' => 'site/media-stock-import',
			'read-style'         => 'site/read-style',
			'set-style'          => 'site/set-style',
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
			'site/read'               => 0,
			'site/list-patterns'      => 0,
			'site/preview'            => 0,
			'site/create-draft'       => 1,
			'site/update-draft'       => 1,
			'site/update-live'        => 2,
			'site/publish'            => 2,
			'site/read-navigation'    => 0,
			'site/update-navigation'  => 2,
			'site/read-front-page'    => 0,
			'site/set-front-page'     => 2,
			'site/media-search'       => 0,
			'site/list-missing-alt'   => 0,
			'site/generate-alt-text'  => 0,
			'site/read-media'         => 0,
			'site/media-upload'       => 1,
			'site/media-generate'     => 1,
			'site/set-featured-image' => 1,
			'site/update-alt'         => 1,
			'site/media-stock-search' => 0,
			'site/media-stock-import' => 1,
			'site/read-style'         => 0,
			'site/set-style'          => 2,
		);
	}

	/**
	 * S12 (defect fix, live run 56): `update-navigation` and `set-front-page`
	 * write a SINGLETON object that carries no natural id in its own output
	 * (unlike a post/page write, and unlike {@see objectIdKey()}'s
	 * output[key] extraction, which would need the ability's model-visible,
	 * schema-validated response to carry tracker-only plumbing). Both verbs
	 * always write exactly one fixed object, so the id is a constant the
	 * pack names directly rather than extracts.
	 *
	 * @param string               $verb   The pack verb.
	 * @param array<string,mixed>  $args   The call's args (unused: both ids are fixed).
	 * @param array<string,mixed>  $output The call's output (unused: both ids are fixed).
	 */
	public function objectIdForWrite( string $verb, array $args, array $output ): ?string {
		unset( $args, $output );

		return match ( $verb ) {
			'site/update-navigation' => Navigation::OBJECT_ID,
			'site/set-front-page' => FrontPage::OBJECT_ID,
			// 0.3 quality feature 5: the style variation is a third
			// singleton, same reasoning as the other two.
			'site/set-style' => Style::OBJECT_ID,
			default => null,
		};
	}

	/**
	 * `read-navigation` and `read-front-page` read the same singletons
	 * {@see objectIdForWrite()} names, so they verify those writes.
	 *
	 * @param string              $verb The pack verb.
	 * @param array<string,mixed> $args The call's args (unused: both ids are fixed).
	 */
	public function objectIdForRead( string $verb, array $args ): ?string {
		unset( $args );

		return match ( $verb ) {
			'site/read-navigation' => Navigation::OBJECT_ID,
			'site/read-front-page' => FrontPage::OBJECT_ID,
			'site/read-style' => Style::OBJECT_ID,
			default => null,
		};
	}

	/**
	 * S12 (defect fix, mirrors PostsPack/PagesPack): `update-alt`'s output
	 * and `read-media`'s input both carry the attachment id as
	 * `attachment_id`, never `id`.
	 *
	 * @param string $verb The pack verb.
	 */
	public function objectIdKey( string $verb ): string {
		return match ( $verb ) {
			'site/update-alt', 'site/read-media' => 'attachment_id',
			default => parent::objectIdKey( $verb ),
		};
	}

	/**
	 * S12 (defect fix, mirrors PostsPack/PagesPack): an attachment and a post
	 * can share the same numeric id, so the two verbs above qualify it with
	 * {@see Media::OBJECT_ID_PREFIX}.
	 *
	 * @param string $verb The pack verb.
	 */
	public function objectIdPrefix( string $verb ): string {
		return match ( $verb ) {
			'site/update-alt', 'site/read-media' => Media::OBJECT_ID_PREFIX,
			default => parent::objectIdPrefix( $verb ),
		};
	}

	/**
	 * S6: `media-upload` and `generate-image` require `upload_files`.
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
	 * The S7 role => pack-verb split.
	 *
	 * @return array<string,list<string>>
	 */
	public function roleVerbs(): array {
		return array(
			'read'         => array( 'site/read' ),
			'create'       => array( 'site/create-draft' ),
			'update'       => array( 'site/update-draft' ),
			'publish'      => array( 'site/update-live', 'site/publish' ),
			'preview'      => array( 'site/preview' ),
			'patterns'     => array( 'site/list-patterns' ),
			'read-nav'     => array( 'site/read-navigation' ),
			'update-nav'   => array( 'site/update-navigation' ),
			'read-front'   => array( 'site/read-front-page' ),
			'set-front'    => array( 'site/set-front-page' ),
			'search'       => array( 'site/media-search' ),
			'missing-alt'  => array( 'site/list-missing-alt' ),
			'upload'       => array( 'site/media-upload' ),
			'generate'     => array( 'site/media-generate' ),
			'alt-text'     => array( 'site/generate-alt-text' ),
			'featured'     => array( 'site/set-featured-image' ),
			'alt'          => array( 'site/update-alt' ),
			'read-media'   => array( 'site/read-media' ),
			'stock-search' => array( 'site/media-stock-search' ),
			'stock-import' => array( 'site/media-stock-import' ),
			'read-style'   => array( 'site/read-style' ),
			'set-style'    => array( 'site/set-style' ),
		);
	}

	/**
	 * S7: the site pack's own flat-and-high budget, applied over the shipped
	 * table BEFORE `senroflux_default_budget` runs (see
	 * {@see \Specflux\SenroFlux\Run\Budget::defaults()}). Never derived from
	 * the plan — sized for a full skeleton run (clarify + several pages +
	 * navigation + front page), not from measured runs the way the shipped
	 * table is. Tokens went 1000000 -> 1200000 after space-bunny live runs
	 * (2026-09-28 bunny3/bunny4) spent 894k and 905k.
	 *
	 * @return array<string,int>
	 */
	public function defaultBudget(): array {
		return array(
			Budget::MAX_STEPS      => 200,
			Budget::MAX_TOOL_CALLS => 120,
			Budget::MAX_TOKENS     => 1200000,
			Budget::MAX_QUESTIONS  => 8,
			Budget::MAX_PLANS      => 3,
			// 0.3 quality feature 4: was 0 (site had no image-generating
			// verb); matches the shipped default {@see Budget::defaults()}
			// now that a site run can generate/upload images too.
			Budget::IMAGES         => 6,
		);
	}

	/**
	 * The three pack skills, in render order (source Pack, version '1').
	 *
	 * @return list<Skill>
	 */
	public function skills( bool $images_available = true ): array {
		unset( $images_available );
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
				$this->copyRulesBody( $vocabulary->curated() ),
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
			new Skill(
				'site/media-rules',
				'Media rules',
				$this->mediaRulesBody(),
				false,
				SkillSource::Pack,
				'1'
			),
		);
	}

	/**
	 * The `site/layout-rules` body — the pages layout rules, extended with the
	 * two homepage-only patterns and the site pack's own (longer) verb list.
	 *
	 * BUG FIX (live run 57): this used to describe the nine patterns by NAME
	 * only and never restated the Validator's structural identity (the
	 * `pages/layout-rules` "Shapes" lines a pages run gets), so a site run's
	 * model had to guess each pattern's heading level / align / buttons
	 * layout. Guessing the hero's headline as a plain h2 (the text-section
	 * shape) produced a markup the shipped hero pattern's h1 could never
	 * match, refused three times as `unknown_pattern` before the run gave up.
	 * The shape lines below are the SAME content `pages/layout-rules` renders
	 * for the shared seven, plus the two homepage-only ones, so a site run is
	 * never worse-informed than a pages run.
	 */
	private function layoutRulesBody(): string {
		return implode(
			"\n",
			array(
				'Write each page as `sections` items, each naming a `layout` (hero, text, text-with-image, services, faq, cta) with its fields; the theme builds the design. Hero first. Give each image slot its own image, except services (all or none). Write `markup` only if no layout fits, or for homepage page-links or intro.',
				'To rewrite an existing page, send new `sections` layouts with update-post or publish-post, keeping its facts; never edit the markup read-content returns.',
				'Tell a visitor, per service, who it is for and what happens; what the first visit is; how to book. Name every service the brief lists on Home and Services. Use services, text and faq layouts; most pages need 5–7 sections. Never put two text sections back to back; add faq, services or text-with-image between. A Contact page states every contact detail given.',
				'If the brief gives a phone number or email, the cta button links to it (tel: or mailto:) and the text states it.',
				'Markup patterns: hero, cover-hero, text-section, media-text, feature-grid, pricing-table, faq, testimonials, cta, page-links, intro. At most one cta, page-links. A page is 2–8 sections, none twice except text-section. Images belong only in a layout\'s slot, cover-hero or media-text. No colour except cover-hero\'s overlayColor. Spacing/typography: standard preset slugs only. Re-read every object after writing.',
				'A pattern is NOT a block: it is a core/group (or core/cover, core/media-text) you write yourself out of core blocks. Never write a block whose name starts with senroflux/. Allowed blocks: core/group, core/heading, core/paragraph, core/buttons, core/button, core/columns, core/column, core/list, core/details, core/quote, core/cover, core/media-text.',
				'Write each block comment with compact JSON (no spaces after : or ,). Give every top-level group `{"metadata":{"name":"senroflux/<slug>"},"layout":{"type":"constrained"}}`. Write list items as plain <li> inside one core/list block; never core/list-item. Close everything you open; markup that fails a parse-and-reserialise round trip is refused as invalid_markup.',
				'Give a block ONLY the attributes its shape names below; any other is refused as unknown_pattern. Only hero, cover-hero, cta and page-links give their buttons block `{"layout":{"type":"flex"}}`. For exact sample markup, call site/list-patterns.',
				'Shapes (">" = child, "(n–m)" = how many of that child):',
				'hero: group align=full > heading level 1, paragraph align=center, buttons layout=flex > button (1–2)',
				'cover-hero: cover align=full > heading level 1, paragraph align=center, buttons layout=flex > button (1–2)',
				'text-section: group > heading level 2, paragraph (2–4)',
				'media-text: media-text > heading level 2, paragraph (1–3), buttons > button (0–1)',
				'feature-grid: group > heading level 2, columns > column (2–3) each > heading level 3, paragraph',
				'pricing-table: group > heading level 2, columns > column (1–3) each > heading level 3, paragraph, list (3–6 items), buttons > button',
				'faq: group > heading level 2, details (2–8) each > paragraph',
				'testimonials: group > heading level 2, quote (1–3)',
				'cta: group align=full > heading level 2, paragraph align=center, buttons layout=flex > button (1)',
				'page-links: group align=full > heading level 2, columns > column (2–6) each > heading level 3, paragraph, buttons > button (links to other skeleton pages; at most once per page)',
				'intro: group > heading level 2, paragraph, buttons > button',
				// 0.3 quality fix (instruction ceiling): see the matching note
				// in PagesPack::layoutRulesBody() — the full verb list now
				// travels on the propose-plan tool's own declaration instead.
				'Spell each plan step\'s verbs exactly as the propose-plan tool lists them; any other word is refused as unknown_verb.',
			)
		);
	}

	/**
	 * The `site/copy-rules` body: a pointer to `site/list-patterns` for each
	 * pattern's copy limits ({@see Pack::copyRulesLines()}), plus the global
	 * copy limits.
	 *
	 * @param list<array<string,mixed>> $vocabulary {@see Vocabulary::all()}.
	 */
	public function copyRulesBody( array $vocabulary ): string {
		$lines = self::copyRulesLines( $vocabulary, 'site/list-patterns' );

		$lines[] = 'Say plainly what the client gets and what happens; no hedges like "may help" or "can be discussed" unless the brief itself hedges.';
		$lines[] = 'Card bodies are at most 40 words.';
		$lines[] = 'Buttons are verb-first (for example "Get started").';
		$lines[] = 'Use a pricing pattern only when the user gave the prices; never write a placeholder price.';

		return implode( "\n", $lines );
	}

	/**
	 * The `site/media-rules` body (0.3 quality feature 4, mirrors
	 * PagesPack::mediaRulesBody()).
	 */
	private function mediaRulesBody(): string {
		return implode(
			"\n",
			array(
				'An image lives only in a layout\'s image slot, each slot a different image, with its alt text in image.alt. Search before generating.',
				'Generated images cost money; when out, use stock-image-search then stock-image-import, else no_image_reason. Re-read media after changing it.',
			)
		);
	}

	/**
	 * The `site/structure-rules` body (S7 adoption + navigation ordering).
	 */
	private function structureRulesBody(): string {
		return implode(
			"\n",
			array(
				'At clarify, also read the site navigation and the front-page settings.',
				'After clarify, your next call is `senroflux/propose-plan`. It lists every page you will create, every adopted object (title, id, status) and the publish and navigation steps. Match an existing page by slug or title and ADOPT it; never create a second page for it.',
				'Rewrite an adopted page only when the human asked for a rewrite. Publish an adopted draft only when the plan names it "publish existing draft".',
				'If navigation kind is "page_list", call update-navigation to list the real pages when stock_sample_page is not null; otherwise page_list is fine.',
				'Delete nothing. Name leftover default-install objects in `left_for_you` for the human to remove.',
				'Update the navigation only after every planned page is published; a link to an unpublished page is refused.',
				'Only call set-style when asked for a different look; read-style first, even if read earlier this run.',
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
