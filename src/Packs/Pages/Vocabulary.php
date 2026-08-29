<?php
/**
 * The pages pack's seven pattern vocabulary (S11).
 *
 * TARGET REPO PATH: src/Packs/Pages/Vocabulary.php
 *
 * The vocabulary is the single authoring source for:
 *   - the patterns registered on `register_block_pattern` (names
 *     `senroflux/<slug>`, category `senroflux-pages`),
 *   - the structural identity the Validator matches against (blockName tree +
 *     layout-defining attrs + slot min/max),
 *   - the `constraints.stated` copy lines the `pages/copy-rules` skill body is
 *     rendered from (so the tool payload and the instruction can never drift),
 *   - the `list-patterns` payload (S11 — only this category, no theme patterns
 *     in 0.2; documented gap).
 *
 * The registry cannot carry the constraint data (S11), so this class holds the
 * data and `register()` wires it into WordPress. Pattern markups that have a
 * verbatim prototype (hero, feature-grid, cta) are reproduced from
 * stage8-research.md §3.2 — completed only where that source abbreviated them
 * (the group wrapper `<div>` and the closing `<!-- /wp:group -->`). The other
 * four markups are authored to their blockName-tree + slot spec.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Packs\Pages;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * The seven-page vocabulary.
 */
final class Vocabulary {

	/**
	 * The pattern category registered with the block editor.
	 */
	public const CATEGORY = 'senroflux-pages';

	/**
	 * The page-shape rules (S11), consumed by the Validator and the layout skill.
	 */
	public const RULES_HERO_FIRST   = 'hero-first';
	public const RULES_MAX_CTA      = 1;
	public const RULES_MIN_PATTERNS = 2;
	public const RULES_MAX_PATTERNS = 8;
	public const RULES_MAX_REPEAT   = 2;

	/**
	 * The core block names the seven patterns may use (Validator step 2). A
	 * block outside this set — `core/image` included — is `unknown_block`.
	 *
	 * `core/cite` is intentionally absent: the testimonials `cite` is inner
	 * content of the quote block, never a standalone block.
	 *
	 * @return list<string>
	 */
	public function blockNames(): array {
		return array(
			'core/group',
			'core/heading',
			'core/paragraph',
			'core/buttons',
			'core/button',
			'core/columns',
			'core/column',
			'core/list',
			'core/details',
			'core/summary',
			'core/quote',
		);
	}

	/**
	 * All seven pattern definitions, in authoring order.
	 *
	 * Each definition: { slug, name, title, description, markup, constraints }.
	 * `constraints`: { slots: {<slot>:{min,max}}, stated: list<string> }.
	 *
	 * @return list<array<string,mixed>>
	 */
	public function all(): array {
		return array(
			$this->hero(),
			$this->textSection(),
			$this->featureGrid(),
			$this->pricingTable(),
			$this->faq(),
			$this->testimonials(),
			$this->cta(),
		);
	}

	/**
	 * The `senroflux/list-patterns` payload (S11): metadata + constraints only,
	 * no markup. Only this category is returned in 0.2 (theme patterns are a
	 * documented gap).
	 *
	 * @return array<string,mixed> { patterns: list<array<string,mixed>> }
	 */
	public function listPayload(): array {
		$patterns = array();
		foreach ( $this->all() as $pattern ) {
			$patterns[] = array(
				'name'        => $pattern['name'],
				'title'       => $pattern['title'],
				'description' => $pattern['description'],
				'constraints' => $pattern['constraints'],
			);
		}

		return array( 'patterns' => $patterns );
	}

	/**
	 * Register all seven on WordPress (S11), guarded so a bare-PHPUnit run (or
	 * a pre-Gutenberg load) is a no-op. Returns the count registered.
	 */
	public function register(): int {
		if ( ! function_exists( 'register_block_pattern_category' ) ) {
			return 0;
		}

		register_block_pattern_category(
			self::CATEGORY,
			array(
				'label'       => __( 'SenroFlux pages', 'senroflux' ),
				'description' => __( 'Page building blocks for SenroFlux content.', 'senroflux' ),
			)
		);

		if ( ! function_exists( 'register_block_pattern' ) ) {
			return 0;
		}

		$registered = 0;
		foreach ( $this->all() as $pattern ) {
			register_block_pattern(
				$pattern['name'],
				array(
					'title'       => $pattern['title'],
					'description' => $pattern['description'],
					'categories'  => array( self::CATEGORY ),
					'content'     => $pattern['markup'],
				)
			);
			++$registered;
		}

		return $registered;
	}

	/**
	 * hero — group[align=full, layout=constrained] › heading(h1) › paragraph ›
	 * buttons › button. Markup verbatim from stage8-research.md §3.2.
	 *
	 * @return array<string,mixed>
	 */
	private function hero(): array {
		return array(
			'slug'        => 'hero',
			'name'        => 'senroflux/hero',
			'title'       => 'Hero',
			'description' => __( 'A full-width hero: one headline, one subheadline and up to two calls to action.', 'senroflux' ),
			'markup'      => <<<'HTML'
<!-- wp:group {"metadata":{"name":"sf/hero"},"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|60","bottom":"var:preset|spacing|60"}}},"backgroundColor":"base-2","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull has-base-2-background-color has-background" style="...">
<!-- wp:heading {"textAlign":"center","level":1} --><h1 class="wp-block-heading has-text-align-center">{{headline}}</h1><!-- /wp:heading -->
<!-- wp:paragraph {"align":"center","fontSize":"large"} --><p class="has-text-align-center has-large-font-size">{{subheadline}}</p><!-- /wp:paragraph -->
<!-- wp:buttons {"layout":{"type":"flex","justifyContent":"center"}} --><div class="wp-block-buttons"><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="{{cta_url}}">{{cta_label}}</a></div><!-- /wp:button --></div><!-- /wp:buttons -->
</div><!-- /wp:group -->
HTML
			,
			'constraints' => array(
				'slots'  => array(
					'buttons' => array(
						'min' => 1,
						'max' => 2,
					),
				),
				'stated' => array(
					'Headline: one clear promise, at most 12 words.',
					'Subheadline: one supporting sentence, at most 24 words.',
					'Buttons: verb-first labels, at most 4 words each.',
				),
			),
		);
	}

	/**
	 * text-section — group › heading(h2) › paragraph*. Markup authored to spec.
	 *
	 * @return array<string,mixed>
	 */
	private function textSection(): array {
		return array(
			'slug'        => 'text-section',
			'name'        => 'senroflux/text-section',
			'title'       => 'Text section',
			'description' => __( 'A plain prose section: an H2 heading followed by one to four paragraphs.', 'senroflux' ),
			'markup'      => <<<'HTML'
<!-- wp:group {"metadata":{"name":"sf/text-section"},"style":{"spacing":{"padding":{"top":"var:preset|spacing|50","bottom":"var:preset|spacing|50"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group" style="...">
<!-- wp:heading --><h2 class="wp-block-heading">{{section_title}}</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>{{body}}</p><!-- /wp:paragraph -->
<!-- wp:paragraph --><p>{{body}}</p><!-- /wp:paragraph -->
</div><!-- /wp:group -->
HTML
			,
			'constraints' => array(
				'slots'  => array(
					'paragraphs' => array(
						'min' => 1,
						'max' => 4,
					),
				),
				'stated' => array(
					'Heading: states the section subject, at most 9 words.',
					'Paragraphs: short, concrete, one idea each.',
				),
			),
		);
	}

	/**
	 * feature-grid — group › heading(h2) › columns › column* › (heading(h3) ›
	 * paragraph). Markup from stage8-research.md §3.2 (columns 2–3 expanded;
	 * the source abbreviated them with `...`).
	 *
	 * @return array<string,mixed>
	 */
	private function featureGrid(): array {
		return array(
			'slug'        => 'feature-grid',
			'name'        => 'senroflux/feature-grid',
			'title'       => 'Feature grid',
			'description' => __( 'A two-to-three-column feature grid: an H2 heading and one H3 + paragraph per column.', 'senroflux' ),
			'markup'      => <<<'HTML'
<!-- wp:group {"metadata":{"name":"sf/feature-grid"},"style":{"spacing":{"padding":{"top":"var:preset|spacing|50","bottom":"var:preset|spacing|50"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group" style="...">
<!-- wp:heading {"textAlign":"center"} --><h2 class="wp-block-heading has-text-align-center">{{section_title}}</h2><!-- /wp:heading -->
<!-- wp:columns --><div class="wp-block-columns"><!-- wp:column --><div class="wp-block-column"><!-- wp:heading {"level":3} --><h3 class="wp-block-heading">{{item_title}}</h3><!-- /wp:heading --><!-- wp:paragraph --><p>{{item_body}}</p><!-- /wp:paragraph --></div><!-- /wp:column --><!-- wp:column --><div class="wp-block-column"><!-- wp:heading {"level":3} --><h3 class="wp-block-heading">{{item_title_2}}</h3><!-- /wp:heading --><!-- wp:paragraph --><p>{{item_body_2}}</p><!-- /wp:paragraph --></div><!-- /wp:column --><!-- wp:column --><div class="wp-block-column"><!-- wp:heading {"level":3} --><h3 class="wp-block-heading">{{item_title_3}}</h3><!-- /wp:heading --><!-- wp:paragraph --><p>{{item_body_3}}</p><!-- /wp:paragraph --></div><!-- /wp:column --></div><!-- /wp:columns -->
</div><!-- /wp:group -->
HTML
			,
			'constraints' => array(
				'slots'  => array(
					'columns' => array(
						'min' => 2,
						'max' => 3,
					),
				),
				'stated' => array(
					'Heading: the shared benefit of the features, at most 9 words.',
					'Each column title: at most 6 words.',
					'Each column body: at most 18 words.',
				),
			),
		);
	}

	/**
	 * pricing-table — group › heading(h2) › columns › column* › (heading(h3) ›
	 * paragraph › list › buttons › button). Markup authored to spec.
	 *
	 * @return array<string,mixed>
	 */
	private function pricingTable(): array {
		return array(
			'slug'        => 'pricing-table',
			'name'        => 'senroflux/pricing-table',
			'title'       => 'Pricing table',
			'description' => __( 'A pricing table: an H2 heading and one-to-three plan columns, each with a plan, price, feature list and a call to action.', 'senroflux' ),
			'markup'      => <<<'HTML'
<!-- wp:group {"metadata":{"name":"sf/pricing-table"},"style":{"spacing":{"padding":{"top":"var:preset|spacing|50","bottom":"var:preset|spacing|50"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group" style="...">
<!-- wp:heading {"textAlign":"center"} --><h2 class="wp-block-heading has-text-align-center">{{section_title}}</h2><!-- /wp:heading -->
<!-- wp:columns --><div class="wp-block-columns"><!-- wp:column --><div class="wp-block-column"><!-- wp:heading {"level":3} --><h3 class="wp-block-heading">{{plan_name}}</h3><!-- /wp:heading --><!-- wp:paragraph --><p>{{price}}</p><!-- /wp:paragraph --><!-- wp:list --><ul class="wp-block-list"><li>{{feature}}</li><li>{{feature}}</li><li>{{feature}}</li></ul><!-- /wp:list --><!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="{{cta_url}}">{{cta_label}}</a></div><!-- /wp:button --></div><!-- /wp:buttons --></div><!-- /wp:column --><!-- wp:column --><div class="wp-block-column"><!-- wp:heading {"level":3} --><h3 class="wp-block-heading">{{plan_name}}</h3><!-- /wp:heading --><!-- wp:paragraph --><p>{{price}}</p><!-- /wp:paragraph --><!-- wp:list --><ul class="wp-block-list"><li>{{feature}}</li><li>{{feature}}</li><li>{{feature}}</li></ul><!-- /wp:list --><!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="{{cta_url}}">{{cta_label}}</a></div><!-- /wp:button --></div><!-- /wp:buttons --></div><!-- /wp:column --></div><!-- /wp:columns -->
</div><!-- /wp:group -->
HTML
			,
			'constraints' => array(
				'slots'  => array(
					'columns'    => array(
						'min' => 1,
						'max' => 3,
					),
					'list_items' => array(
						'min' => 3,
						'max' => 6,
					),
				),
				'stated' => array(
					'Heading: the pricing question answered, at most 9 words.',
					'Price: "$—/month (price TBC)" unless a price was given.',
					'Feature list: 3 to 6 items, each at most 12 words.',
					'Buttons: verb-first labels, at most 4 words each.',
				),
			),
		);
	}

	/**
	 * faq — group › heading(h2) › details* › (summary › paragraph). Markup
	 * authored to spec.
	 *
	 * @return array<string,mixed>
	 */
	private function faq(): array {
		return array(
			'slug'        => 'faq',
			'name'        => 'senroflux/faq',
			'title'       => 'FAQ',
			'description' => __( 'An FAQ: an H2 heading and two-to-eight collapsible details blocks.', 'senroflux' ),
			'markup'      => <<<'HTML'
<!-- wp:group {"metadata":{"name":"sf/faq"},"style":{"spacing":{"padding":{"top":"var:preset|spacing|50","bottom":"var:preset|spacing|50"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group" style="...">
<!-- wp:heading {"textAlign":"center"} --><h2 class="wp-block-heading has-text-align-center">{{section_title}}</h2><!-- /wp:heading -->
<!-- wp:details --><details class="wp-block-details"><summary>{{question}}</summary><!-- wp:paragraph --><p>{{answer}}</p><!-- /wp:paragraph --></details><!-- /wp:details -->
<!-- wp:details --><details class="wp-block-details"><summary>{{question}}</summary><!-- wp:paragraph --><p>{{answer}}</p><!-- /wp:paragraph --></details><!-- /wp:details -->
</div><!-- /wp:group -->
HTML
			,
			'constraints' => array(
				'slots'  => array(
					'details' => array(
						'min' => 2,
						'max' => 8,
					),
				),
				'stated' => array(
					'Question: asks what a reader actually asks, at most 11 words.',
					'Answer: direct and short, at most 40 words.',
				),
			),
		);
	}

	/**
	 * testimonials — group › heading(h2) › quote* (with cite). Markup authored
	 * to spec (the cite is inner content of the quote block).
	 *
	 * @return array<string,mixed>
	 */
	private function testimonials(): array {
		return array(
			'slug'        => 'testimonials',
			'name'        => 'senroflux/testimonials',
			'title'       => 'Testimonials',
			'description' => __( 'A social-proof section: an H2 heading and one-to-three quotes with attribution.', 'senroflux' ),
			'markup'      => <<<'HTML'
<!-- wp:group {"metadata":{"name":"sf/testimonials"},"style":{"spacing":{"padding":{"top":"var:preset|spacing|50","bottom":"var:preset|spacing|50"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group" style="...">
<!-- wp:heading {"textAlign":"center"} --><h2 class="wp-block-heading has-text-align-center">{{section_title}}</h2><!-- /wp:heading -->
<!-- wp:quote --><blockquote class="wp-block-quote"><!-- wp:paragraph --><p>{{quote}}</p><!-- /wp:paragraph --><cite>{{attribution}}</cite></blockquote><!-- /wp:quote -->
</div><!-- /wp:group -->
HTML
			,
			'constraints' => array(
				'slots'  => array(
					'quotes' => array(
						'min' => 1,
						'max' => 3,
					),
				),
				'stated' => array(
					'Quote: a real-sounding outcome in the customer\'s voice, at most 30 words.',
					'Attribution: name and role, at most 8 words.',
				),
			),
		);
	}

	/**
	 * cta — group[align=full] › heading(h2) › paragraph › buttons › button.
	 * Markup verbatim from stage8-research.md §3.2, completed with the group
	 * wrapper `<div>` and the closing `<!-- /wp:group -->` the source trimmed.
	 *
	 * @return array<string,mixed>
	 */
	private function cta(): array {
		return array(
			'slug'        => 'cta',
			'name'        => 'senroflux/cta',
			'title'       => 'Call to action',
			'description' => __( 'A full-width closing call to action: an H2 heading, one supporting line and one button.', 'senroflux' ),
			'markup'      => <<<'HTML'
<!-- wp:group {"metadata":{"name":"sf/cta"},"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|50","bottom":"var:preset|spacing|50"}}},"backgroundColor":"contrast","textColor":"base","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull has-contrast-background-color has-background has-base-color has-text-color" style="...">
<!-- wp:heading {"textAlign":"center"} --><h2 class="wp-block-heading has-text-align-center">{{headline}}</h2><!-- /wp:heading -->
<!-- wp:paragraph {"align":"center"} --><p class="has-text-align-center">{{body}}</p><!-- /wp:paragraph -->
<!-- wp:buttons {"layout":{"type":"flex","justifyContent":"center"}} --><div class="wp-block-buttons"><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="{{cta_url}}">{{cta_label}}</a></div><!-- /wp:button --></div><!-- /wp:buttons -->
</div><!-- /wp:group -->
HTML
			,
			'constraints' => array(
				'slots'  => array(
					'buttons' => array(
						'min' => 1,
						'max' => 1,
					),
				),
				'stated' => array(
					'Headline: an imperative that states exactly what the reader should do, at most 9 words.',
					'Body: one line of supporting benefit, at most 18 words.',
					'Button: verb-first label, at most 4 words.',
				),
			),
		);
	}
}
