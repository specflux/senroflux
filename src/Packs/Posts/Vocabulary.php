<?php
/**
 * The posts pack's vocabulary (S5): unlimited prose plus two capped feature
 * patterns.
 *
 * TARGET REPO PATH: src/Packs/Posts/Vocabulary.php
 *
 * Unlike the pages pack's seven group-wrapped patterns, a post is mostly
 * PROSE written directly at the top level — paragraph, heading, list, quote,
 * image, code — and S5 says these "repeat without limit". There is
 * deliberately no page-shape rule bounding how many of them a post may carry;
 * the only hard number is a runaway GUARD (200 total instances), documented
 * as a guard against a broken loop, never as editorial policy.
 *
 * Two FEATURE patterns keep the pages-style cap instead: `closing-cta` (max
 * 1, a group-wrapped call to action structurally identical in spirit to the
 * pages pack's `cta`) and `pull-quote` (a `core/pullquote`, capped so a post
 * cannot be built entirely out of pulled quotes). Both are registered as real
 * block patterns (`senroflux/<slug>`) so an editor can insert them by hand;
 * prose entries are NOT — there is no `senroflux/paragraph` pattern, only the
 * bare core block.
 *
 * `constraints.stated` is the single source the `posts/copy-rules` skill body
 * is rendered from (mirrors the pages pack's copy-rules single-source test).
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Packs\Posts;

use Specflux\SenroFlux\Packs\Content\Vocabulary as ContentVocabulary;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Implements the S4 {@see ContentVocabulary} seam so `Packs\Content\Abilities`
 * can answer `list-patterns` and validate a write without knowing any pack's
 * concrete pattern set.
 */
final class Vocabulary implements ContentVocabulary {

	/**
	 * The pattern category registered with the block editor, for the two
	 * feature patterns only.
	 */
	public const CATEGORY = 'senroflux-posts';

	/**
	 * A post needs at least one counted instance (prose or feature) — S5.
	 */
	public const RULES_MIN_PATTERNS = 1;

	/**
	 * At most one closing call to action per post.
	 */
	public const RULES_MAX_CLOSING_CTA = 1;

	/**
	 * At most two pull quotes per post. Not stated by S5's text; assumed on
	 * the same "pages-style cap" reading applied to `closing-cta`, so a post
	 * cannot be built almost entirely out of pulled quotes. _Overturn: any
	 * number the owner prefers._
	 */
	public const RULES_MAX_PULL_QUOTE = 2;

	/**
	 * A guard against a runaway loop, NOT an editorial rule (S5: prose
	 * "repeat without limit"). Refused with a distinct code so the model
	 * reads it as a technical ceiling, never as a content judgement.
	 */
	public const RUNAWAY_GUARD = 200;

	/**
	 * Prose block names admitted anywhere, in any order, any number of times
	 * (up to the runaway guard).
	 *
	 * @return list<string>
	 */
	public function proseBlockNames(): array {
		return array(
			'core/paragraph',
			'core/heading',
			'core/list',
			'core/quote',
			'core/image',
			'core/code',
		);
	}

	/**
	 * Every block name the vocabulary admits: prose plus the feature
	 * patterns' own constituent blocks (Validator step 2).
	 *
	 * @return list<string>
	 */
	public function blockNames(): array {
		return array_values(
			array_unique(
				array_merge(
					$this->proseBlockNames(),
					array( 'core/group', 'core/buttons', 'core/button', 'core/pullquote' )
				)
			)
		);
	}

	/**
	 * The prose entries plus the two feature patterns, in authoring order.
	 * Each: { slug, name, title, description, kind, constraints }. Feature
	 * entries additionally carry `markup` and `repeatable` (Validator/pattern
	 * registration).
	 *
	 * @return list<array<string,mixed>>
	 */
	public function all(): array {
		return array(
			$this->paragraph(),
			$this->heading(),
			$this->list(),
			$this->quote(),
			$this->image(),
			$this->code(),
			$this->closingCta(),
			$this->pullQuote(),
		);
	}

	/**
	 * The `senroflux/list-patterns` payload (S5), same shape as the pages
	 * pack's (0.3 S7 gap fix): metadata, constraints AND sample markup where
	 * a pattern has any — the exact input a model can copy verbatim instead
	 * of reconstructing structure from a prose summary. A prose entry's
	 * `name` is the bare core block name (there is no wrapper pattern, so no
	 * fixed markup either — `markup` is omitted for it); a feature entry's
	 * `name` is `senroflux/<slug>` and always carries its shipped markup.
	 * Posts do NOT run the pages pack's `BlockShells` editor-parity check
	 * (S5 scope decision, {@see \Specflux\SenroFlux\Packs\Posts\Validator}),
	 * so unlike the pages/site packs there is no optional-attribute rule to
	 * document here.
	 *
	 * @return array<string,mixed> { patterns: list<array<string,mixed>> }
	 */
	public function listPayload(): array {
		$patterns = array();
		foreach ( $this->all() as $pattern ) {
			$entry = array(
				'name'        => $pattern['name'],
				'title'       => $pattern['title'],
				'description' => $pattern['description'],
				'constraints' => $pattern['constraints'],
			);
			if ( isset( $pattern['markup'] ) && '' !== $pattern['markup'] ) {
				$entry['markup'] = $pattern['markup'];
			}
			$patterns[] = $entry;
		}

		return array( 'patterns' => $patterns );
	}

	/**
	 * Register the two feature patterns (S5). Guarded so a bare-PHPUnit run
	 * (or a pre-Gutenberg load) is a no-op. Returns the count registered.
	 */
	public function register(): int {
		if ( ! function_exists( 'register_block_pattern_category' ) ) {
			return 0;
		}

		register_block_pattern_category(
			self::CATEGORY,
			array(
				'label'       => __( 'SenroFlux posts', 'senroflux' ),
				'description' => __( 'Feature blocks for SenroFlux posts.', 'senroflux' ),
			)
		);

		if ( ! function_exists( 'register_block_pattern' ) ) {
			return 0;
		}

		$registered = 0;
		foreach ( $this->all() as $pattern ) {
			if ( 'feature' !== $pattern['kind'] ) {
				continue;
			}
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
	 * @return array<string,mixed>
	 */
	private function paragraph(): array {
		return array(
			'slug'        => 'paragraph',
			'name'        => 'core/paragraph',
			'title'       => 'Paragraph',
			'description' => __( 'A prose paragraph. Repeats without limit.', 'senroflux' ),
			'kind'        => 'prose',
			'constraints' => array(
				'slots'  => array(),
				'stated' => array( 'Paragraphs: concrete, one idea each, most under 60 words.' ),
			),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function heading(): array {
		return array(
			'slug'        => 'heading',
			'name'        => 'core/heading',
			'title'       => 'Heading',
			'description' => __( 'A section heading. Repeats without limit.', 'senroflux' ),
			'kind'        => 'prose',
			'constraints' => array(
				'slots'  => array(),
				'stated' => array( 'Headings: state what the section covers, at most 9 words.' ),
			),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function list(): array {
		return array(
			'slug'        => 'list',
			'name'        => 'core/list',
			'title'       => 'List',
			'description' => __( 'A bulleted or numbered list. Repeats without limit.', 'senroflux' ),
			'kind'        => 'prose',
			'constraints' => array(
				'slots'  => array(),
				'stated' => array( 'Lists: use for scannable steps or items, 2 to 8 items.' ),
			),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function quote(): array {
		return array(
			'slug'        => 'quote',
			'name'        => 'core/quote',
			'title'       => 'Quote',
			'description' => __( 'An inline quotation. Repeats without limit.', 'senroflux' ),
			'kind'        => 'prose',
			'constraints' => array(
				'slots'  => array(),
				'stated' => array( 'Quotes: attribute every quotation to its source.' ),
			),
		);
	}

	/**
	 * `core/image` is admitted ONLY with non-empty alt text (S5) — the
	 * Validator refuses a missing alt as a shape violation, not a stripped
	 * attribute.
	 *
	 * @return array<string,mixed>
	 */
	private function image(): array {
		return array(
			'slug'        => 'image',
			'name'        => 'core/image',
			'title'       => 'Image',
			'description' => __( 'An inline image. Requires non-empty alt text. Repeats without limit.', 'senroflux' ),
			'kind'        => 'prose',
			'constraints' => array(
				'slots'  => array(),
				'stated' => array( 'Images: always give descriptive, non-empty alt text.' ),
			),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function code(): array {
		return array(
			'slug'        => 'code',
			'name'        => 'core/code',
			'title'       => 'Code',
			'description' => __( 'A preformatted code block. Repeats without limit.', 'senroflux' ),
			'kind'        => 'prose',
			'constraints' => array(
				'slots'  => array(),
				'stated' => array( 'Code: only for real code or commands, verbatim.' ),
			),
		);
	}

	/**
	 * closing-cta — group[align=full] > heading(h2) > paragraph > buttons >
	 * button(1). Structurally the same shape as the pages pack's `cta`.
	 *
	 * @return array<string,mixed>
	 */
	private function closingCta(): array {
		return array(
			'slug'        => 'closing-cta',
			'name'        => 'senroflux/closing-cta',
			'title'       => 'Closing call to action',
			'description' => __( 'A full-width closing call to action. At most one per post.', 'senroflux' ),
			'kind'        => 'feature',
			'markup'      => <<<'HTML'
<!-- wp:group {"metadata":{"name":"senroflux/closing-cta"},"align":"full","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull">
<!-- wp:heading {"textAlign":"center"} --><h2 class="wp-block-heading has-text-align-center">Keep reading</h2><!-- /wp:heading -->
<!-- wp:paragraph {"align":"center"} --><p class="has-text-align-center">One line of supporting benefit before the button.</p><!-- /wp:paragraph -->
<!-- wp:buttons {"layout":{"type":"flex","justifyContent":"center"}} --><div class="wp-block-buttons"><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="#">Read more</a></div><!-- /wp:button --></div><!-- /wp:buttons -->
</div><!-- /wp:group -->
HTML
			,
			'repeatable'  => array(),
			'constraints' => array(
				'slots'  => array(),
				'stated' => array(
					'Closing call to action: at most one per post, at the end.',
					'Button: verb-first label, at most 4 words.',
				),
			),
		);
	}

	/**
	 * pull-quote — a bare `core/pullquote`: blockquote > paragraph, cite
	 * optional. At most {@see RULES_MAX_PULL_QUOTE} per post.
	 *
	 * @return array<string,mixed>
	 */
	private function pullQuote(): array {
		return array(
			'slug'        => 'pull-quote',
			'name'        => 'senroflux/pull-quote',
			'title'       => 'Pull quote',
			'description' => __( 'A pulled-out quotation, visually distinct from an inline quote.', 'senroflux' ),
			'kind'        => 'feature',
			'markup'      => <<<'HTML'
<!-- wp:pullquote {"metadata":{"name":"senroflux/pull-quote"}} -->
<figure class="wp-block-pullquote"><blockquote><p>A short line worth pulling out.</p><cite>Attribution, optional</cite></blockquote></figure>
<!-- /wp:pullquote -->
HTML
			,
			'repeatable'  => array(),
			'constraints' => array(
				'slots'  => array(),
				'stated' => array(
					'Pull quote: a single striking line already present in the body, at most 2 per post.',
				),
			),
		);
	}
}
