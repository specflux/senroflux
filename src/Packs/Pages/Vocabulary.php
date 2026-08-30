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
 * data and `register()` wires it into WordPress.
 *
 * ROUND-TRIP DECISION. The shipped markup carries REAL default copy, never a
 * `{{placeholder}}`, and its `metadata.name` is already the canonical
 * `senroflux/<slug>`. A pattern a human inserts from the editor therefore
 * survives `create-post` / `update-post` untouched: placeholders would trip the
 * `unresolved_placeholder` refusal on the very first write-back. The model
 * never receives markup — it receives `list-patterns`' `constraints.stated`
 * prose — so nothing is lost by keeping the markup placeholder-free.
 *
 * S11 COMPLIANCE. No colour attribute appears anywhere (`backgroundColor`,
 * `textColor`, `style.color`); spacing is expressed only as the standard
 * `var:preset|spacing|NN` slugs plus the matching CSS custom properties the
 * editor itself emits, and typography only as `fontSize` preset slugs.
 *
 * `repeatable` names the child block a pattern may repeat. The Validator uses
 * it to allow variation in exactly that one slot and to hold every OTHER child
 * to the count the pattern ships — without it, extra headings or paragraphs
 * ride along inside an otherwise-matching pattern.
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
	 * "Hero first" needs no constant — it is a position, not a bound.
	 */
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
	 * Each definition: { slug, name, title, description, markup, repeatable,
	 * constraints }. `repeatable`: list<string> of the child block names this
	 * pattern may repeat. `constraints`: { slots: {<slot>:{min,max}}, stated:
	 * list<string> }.
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
	 * buttons › button.
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
<!-- wp:group {"metadata":{"name":"senroflux/hero"},"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|60","bottom":"var:preset|spacing|60"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull" style="padding-top:var(--wp--preset--spacing--60);padding-bottom:var(--wp--preset--spacing--60)">
<!-- wp:heading {"textAlign":"center","level":1} --><h1 class="wp-block-heading has-text-align-center">A headline that states the promise</h1><!-- /wp:heading -->
<!-- wp:paragraph {"align":"center","fontSize":"large"} --><p class="has-text-align-center has-large-font-size">One supporting sentence saying who this is for and what they get.</p><!-- /wp:paragraph -->
<!-- wp:buttons {"layout":{"type":"flex","justifyContent":"center"}} --><div class="wp-block-buttons"><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="#">Get started</a></div><!-- /wp:button --></div><!-- /wp:buttons -->
</div><!-- /wp:group -->
HTML
			,
			'repeatable'  => array( 'core/button' ),
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
	 * text-section — group › heading(h2) › paragraph*.
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
<!-- wp:group {"metadata":{"name":"senroflux/text-section"},"style":{"spacing":{"padding":{"top":"var:preset|spacing|50","bottom":"var:preset|spacing|50"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group" style="padding-top:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--50)">
<!-- wp:heading --><h2 class="wp-block-heading">What this section covers</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>One short paragraph that makes a single concrete point.</p><!-- /wp:paragraph -->
<!-- wp:paragraph --><p>A second paragraph, only when it adds something new.</p><!-- /wp:paragraph -->
</div><!-- /wp:group -->
HTML
			,
			'repeatable'  => array( 'core/paragraph' ),
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
	 * paragraph).
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
<!-- wp:group {"metadata":{"name":"senroflux/feature-grid"},"style":{"spacing":{"padding":{"top":"var:preset|spacing|50","bottom":"var:preset|spacing|50"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group" style="padding-top:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--50)">
<!-- wp:heading {"textAlign":"center"} --><h2 class="wp-block-heading has-text-align-center">What you get</h2><!-- /wp:heading -->
<!-- wp:columns --><div class="wp-block-columns"><!-- wp:column --><div class="wp-block-column"><!-- wp:heading {"level":3} --><h3 class="wp-block-heading">First feature</h3><!-- /wp:heading --><!-- wp:paragraph --><p>One sentence on what this feature does for the reader.</p><!-- /wp:paragraph --></div><!-- /wp:column --><!-- wp:column --><div class="wp-block-column"><!-- wp:heading {"level":3} --><h3 class="wp-block-heading">Second feature</h3><!-- /wp:heading --><!-- wp:paragraph --><p>One sentence on what this feature does for the reader.</p><!-- /wp:paragraph --></div><!-- /wp:column --></div><!-- /wp:columns -->
</div><!-- /wp:group -->
HTML
			,
			'repeatable'  => array( 'core/column' ),
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
	 * paragraph › list › buttons › button). `list_items` is counted PER COLUMN,
	 * not across the table.
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
<!-- wp:group {"metadata":{"name":"senroflux/pricing-table"},"style":{"spacing":{"padding":{"top":"var:preset|spacing|50","bottom":"var:preset|spacing|50"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group" style="padding-top:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--50)">
<!-- wp:heading {"textAlign":"center"} --><h2 class="wp-block-heading has-text-align-center">Pricing</h2><!-- /wp:heading -->
<!-- wp:columns --><div class="wp-block-columns"><!-- wp:column --><div class="wp-block-column"><!-- wp:heading {"level":3} --><h3 class="wp-block-heading">Starter</h3><!-- /wp:heading --><!-- wp:paragraph --><p>$&mdash;/month (price TBC)</p><!-- /wp:paragraph --><!-- wp:list --><ul class="wp-block-list"><li>First thing this plan includes</li><li>Second thing this plan includes</li><li>Third thing this plan includes</li></ul><!-- /wp:list --><!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="#">Choose plan</a></div><!-- /wp:button --></div><!-- /wp:buttons --></div><!-- /wp:column --><!-- wp:column --><div class="wp-block-column"><!-- wp:heading {"level":3} --><h3 class="wp-block-heading">Standard</h3><!-- /wp:heading --><!-- wp:paragraph --><p>$&mdash;/month (price TBC)</p><!-- /wp:paragraph --><!-- wp:list --><ul class="wp-block-list"><li>First thing this plan includes</li><li>Second thing this plan includes</li><li>Third thing this plan includes</li></ul><!-- /wp:list --><!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="#">Choose plan</a></div><!-- /wp:button --></div><!-- /wp:buttons --></div><!-- /wp:column --></div><!-- /wp:columns -->
</div><!-- /wp:group -->
HTML
			,
			'repeatable'  => array( 'core/column' ),
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
	 * faq — group › heading(h2) › details* › (summary › paragraph); the
	 * `<summary>` is inner content of the details block, not a block.
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
<!-- wp:group {"metadata":{"name":"senroflux/faq"},"style":{"spacing":{"padding":{"top":"var:preset|spacing|50","bottom":"var:preset|spacing|50"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group" style="padding-top:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--50)">
<!-- wp:heading {"textAlign":"center"} --><h2 class="wp-block-heading has-text-align-center">Questions people ask</h2><!-- /wp:heading -->
<!-- wp:details --><details class="wp-block-details"><summary>The first question a reader actually asks</summary><!-- wp:paragraph --><p>A direct answer in one or two short sentences.</p><!-- /wp:paragraph --></details><!-- /wp:details -->
<!-- wp:details --><details class="wp-block-details"><summary>The second question a reader actually asks</summary><!-- wp:paragraph --><p>A direct answer in one or two short sentences.</p><!-- /wp:paragraph --></details><!-- /wp:details -->
</div><!-- /wp:group -->
HTML
			,
			'repeatable'  => array( 'core/details' ),
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
	 * testimonials — group › heading(h2) › quote* (with cite); the `<cite>` is
	 * inner content of the quote block.
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
<!-- wp:group {"metadata":{"name":"senroflux/testimonials"},"style":{"spacing":{"padding":{"top":"var:preset|spacing|50","bottom":"var:preset|spacing|50"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group" style="padding-top:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--50)">
<!-- wp:heading {"textAlign":"center"} --><h2 class="wp-block-heading has-text-align-center">What customers say</h2><!-- /wp:heading -->
<!-- wp:quote --><blockquote class="wp-block-quote"><!-- wp:paragraph --><p>A short outcome in the customer&#8217;s own words.</p><!-- /wp:paragraph --><cite>Customer name, role</cite></blockquote><!-- /wp:quote -->
</div><!-- /wp:group -->
HTML
			,
			'repeatable'  => array( 'core/quote' ),
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
<!-- wp:group {"metadata":{"name":"senroflux/cta"},"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|50","bottom":"var:preset|spacing|50"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull" style="padding-top:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--50)">
<!-- wp:heading {"textAlign":"center"} --><h2 class="wp-block-heading has-text-align-center">Start today</h2><!-- /wp:heading -->
<!-- wp:paragraph {"align":"center"} --><p class="has-text-align-center">One line of supporting benefit before the button.</p><!-- /wp:paragraph -->
<!-- wp:buttons {"layout":{"type":"flex","justifyContent":"center"}} --><div class="wp-block-buttons"><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="#">Get started</a></div><!-- /wp:button --></div><!-- /wp:buttons -->
</div><!-- /wp:group -->
HTML
			,
			'repeatable'  => array( 'core/button' ),
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
