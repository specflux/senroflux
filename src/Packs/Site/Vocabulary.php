<?php
/**
 * The site pack's pattern vocabulary (0.3 S7): the pages pack's seven
 * patterns plus two homepage-only feature patterns.
 *
 * TARGET REPO PATH: src/Packs/Site/Vocabulary.php
 *
 * Extends {@see \Specflux\SenroFlux\Packs\Pages\Vocabulary} rather than
 * re-authoring the seven shared patterns (S7: "extends the pages vocabulary
 * for site runs only"). Both new patterns use ONLY blocks already in the
 * pages allow-list (`blockNames()` is inherited unchanged) — `core/columns`,
 * `core/column`, `core/heading`, `core/paragraph`, `core/buttons`,
 * `core/button` — so nothing about block-safety or markup-safety validation
 * needs touching for them.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Packs\Site;

use Specflux\SenroFlux\Packs\Pages\Vocabulary as PagesVocabulary;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * The site pack's nine-pattern vocabulary.
 */
final class Vocabulary extends PagesVocabulary {

	/**
	 * A `page-links` pattern may appear at most once per page (S7: "repeatable
	 * once") — the cards WITHIN it are what repeats (2..6), the pattern itself
	 * does not.
	 */
	public const RULES_MAX_PAGE_LINKS = 1;

	/**
	 * The nine pattern definitions: the inherited seven, then the two
	 * homepage-only ones.
	 *
	 * @return list<array<string,mixed>>
	 */
	public function all(): array {
		return array_merge(
			parent::all(),
			array(
				$this->pageLinks(),
				$this->intro(),
			)
		);
	}

	/**
	 * page-links — group[align=full] › heading(h2) › columns › column(2–6)
	 * each › (heading(h3) › paragraph › buttons › button linking one skeleton
	 * page). Structurally identical to `feature-grid`'s column shape, which is
	 * why {@see \Specflux\SenroFlux\Packs\Site\Validator::countSlots()} reuses
	 * the SAME `columns` slot-counting logic.
	 *
	 * @return array<string,mixed>
	 */
	private function pageLinks(): array {
		return array(
			'slug'        => 'page-links',
			'name'        => 'senroflux/page-links',
			'title'       => 'Page links',
			'description' => __( 'A homepage card grid linking to the site\'s other pages: an H2 heading and two-to-six cards, each with a title, a line of copy and a button to one page.', 'senroflux' ),
			'markup'      => <<<'HTML'
<!-- wp:group {"metadata":{"name":"senroflux/page-links"},"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|50","bottom":"var:preset|spacing|50"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull" style="padding-top:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--50)">
<!-- wp:heading {"textAlign":"center"} --><h2 class="wp-block-heading has-text-align-center">Explore the site</h2><!-- /wp:heading -->
<!-- wp:columns --><div class="wp-block-columns"><!-- wp:column --><div class="wp-block-column"><!-- wp:heading {"level":3} --><h3 class="wp-block-heading">First page</h3><!-- /wp:heading --><!-- wp:paragraph --><p>One sentence on what this page covers.</p><!-- /wp:paragraph --><!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="#">Visit page</a></div><!-- /wp:button --></div><!-- /wp:buttons --></div><!-- /wp:column --><!-- wp:column --><div class="wp-block-column"><!-- wp:heading {"level":3} --><h3 class="wp-block-heading">Second page</h3><!-- /wp:heading --><!-- wp:paragraph --><p>One sentence on what this page covers.</p><!-- /wp:paragraph --><!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="#">Visit page</a></div><!-- /wp:button --></div><!-- /wp:buttons --></div><!-- /wp:column --></div><!-- /wp:columns -->
</div><!-- /wp:group -->
HTML
			,
			'repeatable'  => array( 'core/column' ),
			'constraints' => array(
				'slots'  => array(
					'columns' => array(
						'min' => 2,
						'max' => 6,
					),
				),
				'stated' => array(
					'Card title: the linked page\'s own title, at most 6 words.',
					'Card body: one sentence on what that page covers, at most 18 words.',
					'Card button: verb-first, at most 4 words, links to exactly one skeleton page.',
					'A page-links section appears at most once per page.',
				),
			),
		);
	}

	/**
	 * intro — group › heading(h2) › paragraph › buttons › button. Fixed shape
	 * (`repeatable: []`): exactly one of everything, so no new slot-counting
	 * case is needed — the generic structural match already enforces it.
	 *
	 * @return array<string,mixed>
	 */
	private function intro(): array {
		return array(
			'slug'        => 'intro',
			'name'        => 'senroflux/intro',
			'title'       => 'Intro',
			'description' => __( 'A short homepage introduction: an H2 heading, one paragraph and one button.', 'senroflux' ),
			'markup'      => <<<'HTML'
<!-- wp:group {"metadata":{"name":"senroflux/intro"},"style":{"spacing":{"padding":{"top":"var:preset|spacing|50","bottom":"var:preset|spacing|50"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group" style="padding-top:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--50)">
<!-- wp:heading --><h2 class="wp-block-heading">A short introduction</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>One paragraph introducing the site or the business.</p><!-- /wp:paragraph -->
<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="#">Learn more</a></div><!-- /wp:button --></div><!-- /wp:buttons -->
</div><!-- /wp:group -->
HTML
			,
			'repeatable'  => array(),
			'constraints' => array(
				'slots'  => array(),
				'stated' => array(
					'Heading: states the introduction\'s subject, at most 9 words.',
					'Paragraph: one short paragraph, at most 40 words.',
					'Button: verb-first label, at most 4 words.',
				),
			),
		);
	}
}
