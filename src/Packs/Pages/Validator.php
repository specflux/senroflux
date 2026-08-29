<?php
/**
 * Pack-owned write validation for the pages pack (S10).
 *
 * TARGET REPO PATH: src/Packs/Pages/Validator.php
 *
 * Whole-write refusal — validation failure returns a WP_Error and NOTHING is
 * persisted. The checks run in the S10 order:
 *   1. `serialize_blocks(parse_blocks($content)) === $content` after
 *      normalisation, else `invalid_markup`.
 *   2. every block `core/*` and inside the vocabulary's block set; `core/freeform`
 *      (null blockName), unknown or non-core → `unknown_block` (index + name).
 *   3. unresolved `{{placeholder}}` text → `unresolved_placeholder`.
 *   4. pattern identity is STRUCTURAL (blockName tree + layout-defining attrs +
 *      slot counts in min..max) → `unknown_pattern` (index + nearest name),
 *      `slot_count` (slot + allowed range), `page_shape` (rule broken).
 *   5. MUTATIONS, not refusals — strip decorative colour attributes and
 *      normalise `metadata.name`. Exposed only through {@see clean()}.
 *
 * Return shape (documented): {@see validate()} is the refusal gateway returning
 * `true|WP_Error`; {@see clean()} is the callers' single entry point returning
 * `{ok:bool, content:string, wp_error:?WP_Error}` — when `ok` is false the
 * `content` is the untouched input and `wp_error` carries the refusal; when
 * `ok` is true the `content` is the cleaned (mutated) markup to persist.
 *
 * The block parser / serializer are the WordPress core functions
 * (`parse_blocks` / `serialize_blocks`), guarded with `function_exists` so a
 * bare run fails closed rather than calling a missing function.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Packs\Pages;

use WP_Error;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Validates and cleans pages-pack block markup.
 */
final class Validator {

	/**
	 * @param Vocabulary $vocabulary The pattern vocabulary (identity + constraints).
	 */
	public function __construct( private readonly Vocabulary $vocabulary ) {
	}

	/**
	 * Step-1..4 refusal gateway.
	 *
	 * @param string              $content Serialized block markup.
	 * @param array<string,mixed> $ctx     Context (e.g. post_type); used for messaging.
	 * @return true|WP_Error true when the markup passes, else a refusal WP_Error.
	 */
	public function validate( string $content, array $ctx = array() ): true|WP_Error {
		$res = $this->run( $content, $ctx );
		if ( ! $res['ok'] ) {
			/** @var WP_Error $error */
			$error = $res['wp_error'];

			return $error;
		}

		return true;
	}

	/**
	 * The single entry point for create/update: validates, then applies the
	 * step-5 mutations. Callers persist ONLY `$result['content']` when
	 * `$result['ok']` is true.
	 *
	 * @param string              $content Serialized block markup.
	 * @param array<string,mixed> $ctx     Context (e.g. post_type).
	 * @return array{ok:bool, content:string, wp_error:WP_Error|null}
	 */
	public function clean( string $content, array $ctx = array() ): array {
		$res = $this->run( $content, $ctx );
		if ( ! $res['ok'] ) {
			return array(
				'ok'       => false,
				'content'  => $content,
				'wp_error' => $res['wp_error'],
			);
		}

		return array(
			'ok'       => true,
			'content'  => $this->mutate( $res['blocks'], $res['identities'] ),
			'wp_error' => null,
		);
	}

	/**
	 * The full pipeline. Returns the parsed blocks + the per-top-level pattern
	 * identity so {@see clean()} can re-serialise the mutated tree.
	 *
	 * @param string              $content Serialized block markup.
	 * @param array<string,mixed> $ctx     Context.
	 * @return array{ok:bool, content:string, blocks:list<array<string,mixed>>, identities:array<int,string>, wp_error:WP_Error|null}
	 */
	private function run( string $content, array $ctx ): array {
		// Step 1 — round-trip after normalisation.
		$blocks = $this->parse( $content );
		if ( null === $blocks ) {
			return $this->refuse(
				new WP_Error( 'invalid_markup', $this->message( 'invalid_markup', $ctx ), array( 'status' => 400 ) )
			);
		}

		$reserialized = $this->serialize( $blocks );
		if ( $this->normalize( $content ) !== $this->normalize( $reserialized ) ) {
			return $this->refuse(
				new WP_Error( 'invalid_markup', $this->message( 'invalid_markup', $ctx ), array( 'status' => 400 ) )
			);
		}

		// Step 2 — block-name allow-list.
		$block_error = $this->checkBlockNames( $blocks );
		if ( null !== $block_error ) {
			return $this->refuse( $block_error );
		}

		// Step 3 — unresolved `{{placeholder}}`.
		$placeholder = $this->findPlaceholder( $content );
		if ( null !== $placeholder ) {
			return $this->refuse(
				new WP_Error(
					'unresolved_placeholder',
					$this->message( 'unresolved_placeholder', $ctx ),
					array(
						'status'      => 400,
						'placeholder' => $placeholder,
					)
				)
			);
		}

		// Step 4 — structural pattern identity, slot counts, page shape.
		$identities = array();
		foreach ( $blocks as $index => $block ) {
			$slug = $this->matchPatternSchema( $block );
			if ( null === $slug ) {
				$nearest = $this->nearestPattern( $block );

				return $this->refuse(
					new WP_Error(
						'unknown_pattern',
						$this->message(
							'unknown_pattern',
							$ctx,
							array(
								'index' => $index,
								'name'  => $nearest,
							)
						),
						array(
							'status' => 400,
							'index'  => $index,
							'name'   => $nearest,
						)
					)
				);
			}

			$slot_error = $this->checkSlots( $slug, $block );
			if ( null !== $slot_error ) {
				return $this->refuse( $slot_error );
			}

			$identities[ $index ] = $slug;
		}

		$shape_error = $this->checkPageShape( $blocks, $identities );
		if ( null !== $shape_error ) {
			return $this->refuse( $shape_error );
		}

		return array(
			'ok'         => true,
			'content'    => $content,
			'blocks'     => $blocks,
			'identities' => $identities,
			'wp_error'   => null,
		);
	}

	/**
	 * @return array{ok:bool, content:string, blocks:list<array<string,mixed>>, identities:array<int,string>, wp_error:WP_Error}
	 */
	private function refuse( WP_Error $error ): array {
		return array(
			'ok'         => false,
			'content'    => '',
			'blocks'     => array(),
			'identities' => array(),
			'wp_error'   => $error,
		);
	}

	/**
	 * Collapse all whitespace runs to a single space and trim — the documented
	 * normalisation the round-trip compare uses (the prototype's `$norm`).
	 */
	private function normalize( string $text ): string {
		return (string) preg_replace( '/\s+/', ' ', trim( $text ) );
	}

	/**
	 * @return list<array<string,mixed>>|null Null when the functions are absent
	 *                                        (fail closed → invalid_markup).
	 */
	private function parse( string $content ): ?array {
		if ( ! function_exists( 'parse_blocks' ) ) {
			return null;
		}

		$blocks = parse_blocks( $content );
		if ( ! is_array( $blocks ) ) {
			return array();
		}

		/** @var list<array<string,mixed>> $blocks */
		return $blocks;
	}

	/**
	 * @param list<array<string,mixed>> $blocks Parsed top-level blocks.
	 */
	private function serialize( array $blocks ): string {
		if ( ! function_exists( 'serialize_blocks' ) ) {
			// Absent here after a successful parse means a half-loaded WP; refuse.
			return '';
		}

		/** @var list<array{blockName: string|null, attrs: array<string,mixed>, innerBlocks: list<array<string,mixed>>, innerHTML: string, innerContent: array<string,mixed>}> $blocks */
		return (string) serialize_blocks( $blocks );
	}

	/**
	 * Step 2 — every blockName must be non-null, `core/*`, and within the
	 * vocabulary's block set. `core/freeform`, unknown or non-core → the first
	 * offending block, with its depth-first index + name.
	 *
	 * @param list<array<string,mixed>> $blocks Parsed top-level blocks.
	 */
	private function checkBlockNames( array $blocks ): ?WP_Error {
		$allowed = array_flip( $this->vocabulary->blockNames() );
		$index   = 0;

		$walk = static function ( array $node, callable $recurse ) use ( &$allowed, &$index ): ?array {
			$name = $node['blockName'] ?? null;
			if ( null === $name || ! is_string( $name ) ) {
				// Freeform: parse_blocks leaves blockName null for un-attributable HTML.
				return array(
					'index' => $index,
					'name'  => 'core/freeform',
				);
			}
			if ( ! str_starts_with( $name, 'core/' ) || ! isset( $allowed[ $name ] ) ) {
				return array(
					'index' => $index,
					'name'  => $name,
				);
			}

			$children = $node['innerBlocks'] ?? array();
			/** @var list<array<string,mixed>> $children */
			foreach ( $children as $child ) {
				$found = $recurse( $child, $recurse );
				if ( null !== $found ) {
					return $found;
				}
			}

			return null;
		};

		$found = null;
		foreach ( $blocks as $block ) {
			$found = $walk( $block, $walk );
			if ( null !== $found ) {
				break;
			}
			++$index;
		}

		if ( null === $found ) {
			return null;
		}

		return new WP_Error(
			'unknown_block',
			$this->message(
				'unknown_block',
				array(),
				array(
					'index' => $found['index'],
					'name'  => $found['name'],
				)
			),
			array(
				'status' => 400,
				'index'  => $found['index'],
				'name'   => $found['name'],
			)
		);
	}

	/**
	 * Step 3 — unresolved `{{placeholder}}`. The strict negative lookarounds
	 * avoid matching the inner pair of a triple-brace `{{{x}}}` (the brief's
	 * blind spot), so a stray leading/trailing brace is NOT stored.
	 *
	 * @return string|null The captured placeholder, or null when clean.
	 */
	private function findPlaceholder( string $content ): ?string {
		if ( preg_match_all( '/(?<!\{)\{\{([a-z_]+)\}\}(?!\})/', $content, $matches ) ) {
			return $matches[1][0];
		}

		return null;
	}

	/**
	 * Structural signature for one parsed block: blockName + layout-defining
	 * attrs (align, layout.type, heading level) + recursive child signatures.
	 * Decorative attrs (backgroundColor/textColor/style.color) are excluded so
	 * identity never depends on them.
	 *
	 * @param array<string,mixed> $block One parsed block.
	 */
	private function signatureOf( array $block ): string {
		$name  = (string) ( $block['blockName'] ?? '' );
		$attrs = $block['attrs'] ?? array();
		$label = $name;
		$parts = array();

		if ( isset( $attrs['align'] ) && is_string( $attrs['align'] ) ) {
			$parts[] = 'align=' . $attrs['align'];
		}
		if ( isset( $attrs['layout']['type'] ) && is_string( $attrs['layout']['type'] ) ) {
			$parts[] = 'layout=' . $attrs['layout']['type'];
		}
		if ( isset( $attrs['level'] ) && is_numeric( $attrs['level'] ) ) {
			$parts[] = 'level=' . $attrs['level'];
		}

		if ( array() !== $parts ) {
			$label .= '[' . implode( ',', $parts ) . ']';
		}

		$children = $block['innerBlocks'] ?? array();
		/** @var list<array<string,mixed>> $children */
		if ( array() !== $children ) {
			$child_sigs = array();
			foreach ( $children as $child ) {
				$child_sigs[] = $this->signatureOf( $child );
			}
			// Collapse repeated identical children so slot COUNT never affects
			// identity — a text-section with 5 paragraphs still identifies as
			// text-section, and the count is validated separately (slot_count).
			$child_sigs = array_values( array_unique( $child_sigs ) );
			$label     .= '{' . implode( ',', $child_sigs ) . '}';
		}

		return $label;
	}

	/**
	 * The expected structural signature for a pattern's TOP-level block, cached.
	 *
	 * @var array<string,string> slug => signature.
	 */
	private array $pattern_signatures = array();

	/**
	 * Match a top-level block against the vocabulary. Returns the slug when the
	 * block's signature equals a pattern's expected signature, else null.
	 */
	/**
	 * Match a top-level block against the vocabulary. Returns the slug when the
	 * block's signature equals a pattern's expected signature, else null.
	 *
	 * @param array<string,mixed> $block One parsed block.
	 */
	private function matchPatternSchema( array $block ): ?string {
		$candidate = $this->signatureOf( $block );
		foreach ( $this->vocabulary->all() as $pattern ) {
			if ( $this->expectedSignature( $pattern ) === $candidate ) {
				return $pattern['slug'];
			}
		}

		return null;
	}

	/**
	 * @param array<string,mixed> $pattern One pattern definition.
	 */
	private function expectedSignature( array $pattern ): string {
		$slug = $pattern['slug'];
		if ( ! isset( $this->pattern_signatures[ $slug ] ) ) {
			$blocks                            = $this->parse( (string) $pattern['markup'] );
			$this->pattern_signatures[ $slug ] = ( $blocks && isset( $blocks[0] ) )
				? $this->signatureOf( $blocks[0] )
				: '';
		}

		return $this->pattern_signatures[ $slug ];
	}

	/**
	 * The closest pattern for an `unknown_pattern` message: the pattern whose
	 * expected signature shares the longest leading run with the candidate.
	 *
	 * @param array<string,mixed> $block One parsed block.
	 */
	private function nearestPattern( array $block ): string {
		$candidate = $this->signatureOf( $block );
		$best      = '';
		$best_len  = -1;

		foreach ( $this->vocabulary->all() as $pattern ) {
			$expected = $this->expectedSignature( $pattern );
			$len      = 0;
			$max      = min( strlen( $candidate ), strlen( $expected ) );
			while ( $len < $max && $candidate[ $len ] === $expected[ $len ] ) {
				++$len;
			}
			if ( $len > $best_len ) {
				$best_len = $len;
				$best     = $pattern['name'];
			}
		}

		return '' !== $best ? $best : 'unknown';
	}

	/**
	 * Compare the counted slots of a top-level block against its pattern's
	 * min..max constraints. Returns a WP_Error (slot_count) or null.
	 */
	/**
	 * Compare the counted slots of a top-level block against its pattern's
	 * min..max constraints. Returns a WP_Error (slot_count) or null.
	 *
	 * @param array<string,mixed> $block One parsed block.
	 */
	private function checkSlots( string $slug, array $block ): ?WP_Error {
		$pattern = $this->patternBySlug( $slug );
		if ( null === $pattern ) {
			return null;
		}

		$slots = $pattern['constraints']['slots'] ?? array();
		$count = $this->countSlots( $slug, $block );

		foreach ( $slots as $slot => $range ) {
			$actual = $count[ $slot ] ?? 0;
			if ( $actual < $range['min'] || $actual > $range['max'] ) {
				return new WP_Error(
					'slot_count',
					$this->message(
						'slot_count',
						array(),
						array(
							'slot' => $slot,
							'min'  => $range['min'],
							'max'  => $range['max'],
						)
					),
					array(
						'status' => 400,
						'slot'   => $slot,
						'min'    => $range['min'],
						'max'    => $range['max'],
						'actual' => $actual,
					)
				);
			}
		}

		return null;
	}

	/**
	 * Count the repeated-slot children of a top-level block.
	 *
	 * @param array<string,mixed> $block One parsed block.
	 * @return array<string,int>
	 */
	private function countSlots( string $slug, array $block ): array {
		$children = $block['innerBlocks'] ?? array();
		/** @var list<array<string,mixed>> $children */

		switch ( $slug ) {
			case 'hero':
			case 'cta':
				$buttons = $this->firstByBlockName( $children, 'core/buttons' );
				$count   = 0;
				if ( null !== $buttons ) {
					$count = count( $buttons['innerBlocks'] ?? array() );
				}

				return array( 'buttons' => $count );

			case 'text-section':
				// Direct core/paragraph children (after the heading).
				$count = 0;
				foreach ( $children as $child ) {
					if ( 'core/paragraph' === ( $child['blockName'] ?? '' ) ) {
						++$count;
					}
				}

				return array( 'paragraphs' => $count );

			case 'feature-grid':
				return array( 'columns' => $this->columnCount( $children ) );

			case 'pricing-table':
				$columns = $this->columnCount( $children );
				$items   = $this->listItemCount( $children );

				return array(
					'columns'    => $columns,
					'list_items' => $items,
				);

			case 'faq':
				return array( 'details' => $this->countByBlockName( $children, 'core/details' ) );

			case 'testimonials':
				return array( 'quotes' => $this->countByBlockName( $children, 'core/quote' ) );
		}

		return array();
	}

	/**
	 * @param list<array<string,mixed>> $children Parsed child blocks.
	 */
	private function columnCount( array $children ): int {
		$columns = $this->firstByBlockName( $children, 'core/columns' );
		if ( null === $columns ) {
			return 0;
		}

		$inner = $columns['innerBlocks'] ?? array();
		/** @var list<array<string,mixed>> $inner */

		return $this->countByBlockName( $inner, 'core/column' );
	}

	/**
	 * @param list<array<string,mixed>> $children Parsed child blocks.
	 */
	private function countByBlockName( array $children, string $name ): int {
		$count = 0;
		foreach ( $children as $child ) {
			if ( ( $child['blockName'] ?? '' ) === $name ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * @param list<array<string,mixed>> $children Parsed child blocks.
	 * @return array<string,mixed>|null The first matching block, else null.
	 */
	private function firstByBlockName( array $children, string $name ): ?array {
		foreach ( $children as $child ) {
			if ( ( $child['blockName'] ?? '' ) === $name ) {
				return $child;
			}
		}

		return null;
	}

	/**
	 * Total list-item count across every `core/list` block (items are `<li>`
	 * inner content, not child blocks).
	 */
	/**
	 * Total list-item count across every `core/list` block (items are `<li>`
	 * inner content, not child blocks).
	 *
	 * @param list<array<string,mixed>> $children Parsed child blocks.
	 */
	private function listItemCount( array $children ): int {
		$lists = $this->collectByBlockName( $children, 'core/list' );
		$count = 0;
		foreach ( $lists as $list ) {
			$count += substr_count( (string) ( $list['innerHTML'] ?? '' ), '<li' );
		}

		return $count;
	}

	/**
	 * @param list<array<string,mixed>> $children Parsed child blocks.
	 * @return list<array<string,mixed>>
	 */
	private function collectByBlockName( array $children, string $name ): array {
		$out = array();
		foreach ( $children as $child ) {
			if ( ( $child['blockName'] ?? '' ) === $name ) {
				$out[] = $child;
			}
			$nested = $child['innerBlocks'] ?? array();
			/** @var list<array<string,mixed>> $nested */
			foreach ( $nested as $grandchild ) {
				$out = array_merge( $out, $this->collectByBlockName( array( $grandchild ), $name ) );
			}
		}

		return $out;
	}

	/**
	 * The S11 page-shape rules over the whole, already-identified sequence.
	 *
	 * @param list<array<string,mixed>> $blocks     Top-level blocks (one per pattern).
	 * @param array<int,string>         $identities index => slug.
	 */
	private function checkPageShape( array $blocks, array $identities ): ?WP_Error {
		$count = count( $blocks );
		$slugs = array_values( $identities );

		if ( $count < Vocabulary::RULES_MIN_PATTERNS || $count > Vocabulary::RULES_MAX_PATTERNS ) {
			return new WP_Error(
				'page_shape',
				$this->message(
					'page_shape',
					array(),
					array(
						'rule'  => 'pattern_count',
						'count' => $count,
					)
				),
				array(
					'status' => 400,
					'rule'   => 'pattern_count',
					'count'  => $count,
				)
			);
		}

		// Hero first.
		if ( 'hero' !== ( $slugs[0] ?? '' ) ) {
			return new WP_Error(
				'page_shape',
				$this->message( 'page_shape', array(), array( 'rule' => 'hero_first' ) ),
				array(
					'status' => 400,
					'rule'   => 'hero_first',
				)
			);
		}

		// At most one CTA.
		$cta_count = count( array_filter( $slugs, static fn ( string $s ): bool => 'cta' === $s ) );
		if ( $cta_count > Vocabulary::RULES_MAX_CTA ) {
			return new WP_Error(
				'page_shape',
				$this->message( 'page_shape', array(), array( 'rule' => 'max_cta' ) ),
				array(
					'status' => 400,
					'rule'   => 'max_cta',
					'count'  => $cta_count,
				)
			);
		}

		// No pattern more than twice, except text-section.
		$freq = array();
		foreach ( $slugs as $slug ) {
			$freq[ $slug ] = ( $freq[ $slug ] ?? 0 ) + 1;
		}
		foreach ( $freq as $slug => $seen ) {
			if ( 'text-section' !== $slug && $seen > Vocabulary::RULES_MAX_REPEAT ) {
				return new WP_Error(
					'page_shape',
					$this->message(
						'page_shape',
						array(),
						array(
							'rule' => 'max_repeat',
							'slug' => $slug,
							'seen' => $seen,
						)
					),
					array(
						'status' => 400,
						'rule'   => 'max_repeat',
						'slug'   => $slug,
						'seen'   => $seen,
					)
				);
			}
		}

		return null;
	}

	/**
	 * Step 5 — the mutation pass: strip decorative colour attrs and normalise
	 * `metadata.name` to `senroflux/<slug>` on each top-level pattern block.
	 *
	 * @param list<array<string,mixed>> $blocks     Parsed top-level blocks.
	 * @param array<int,string>         $identities index => slug.
	 */
	private function mutate( array $blocks, array $identities ): string {
		$mutated = $blocks;
		foreach ( $mutated as $i => $block ) {
			if ( isset( $identities[ $i ] ) ) {
				$mutated[ $i ] = $this->mutateBlock( $block, $identities[ $i ] );
			}
		}

		return $this->serialize( $mutated );
	}

	/**
	 * Recursively strip decorative attrs and (for a top-level pattern block)
	 * set the canonical metadata.name.
	 */
	/**
	 * Recursively strip decorative attrs and (for a top-level pattern block)
	 * set the canonical metadata.name.
	 *
	 * @param array<string,mixed> $block One parsed block.
	 * @return array<string,mixed>
	 */
	private function mutateBlock( array $block, ?string $slug = null ): array {
		$attrs = $block['attrs'] ?? array();
		unset( $attrs['backgroundColor'], $attrs['textColor'] );

		if ( isset( $attrs['style']['color'] ) ) {
			unset( $attrs['style']['color'] );
			if ( empty( $attrs['style'] ) ) {
				unset( $attrs['style'] );
			}
		}

		if ( null !== $slug ) {
			$attrs['metadata'] = array( 'name' => 'senroflux/' . $slug );
		}

		$children = $block['innerBlocks'] ?? array();
		/** @var list<array<string,mixed>> $children */
		foreach ( $children as $j => $child ) {
			$children[ $j ] = $this->mutateBlock( $child );
		}
		$block['attrs']       = $attrs;
		$block['innerBlocks'] = $children;

		return $block;
	}

	/**
	 * One pattern definition by slug.
	 *
	 * @return array<string,mixed>|null
	 */
	private function patternBySlug( string $slug ): ?array {
		foreach ( $this->vocabulary->all() as $pattern ) {
			if ( $slug === $pattern['slug'] ) {
				return $pattern;
			}
		}

		return null;
	}

	/**
	 * Human message for a refusal code (S15: harness/pack error codes via `__()`
	 * text domain `senroflux`; the code string itself stays machine-stable).
	 *
	 * @param string              $code Refusal code.
	 * @param array<string,mixed> $ctx  Context.
	 * @param array<string,mixed> $data Per-code data.
	 */
	private function message( string $code, array $ctx, array $data = array() ): string {
		$context = $ctx['post_type'] ?? 'page';

		return match ( $code ) {
			'invalid_markup'        => sprintf(
				/* translators: %s: post type. */
				__( 'The %1$s content is not well-formed block markup.', 'senroflux' ),
				$context
			),
			'unknown_block'         => sprintf(
				/* translators: %1$d: block index, %2$s: block name. */
				__( 'Block %1$d "%2$s" is not a page-vocabulary block.', 'senroflux' ),
				$data['index'] ?? 0,
				$data['name'] ?? 'unknown'
			),
			'unresolved_placeholder' => sprintf(
				/* translators: %s: placeholder. */
				__( 'Unresolved placeholder "{{%s}}" must be filled before writing.', 'senroflux' ),
				$data['placeholder'] ?? ''
			),
			'unknown_pattern'       => sprintf(
				/* translators: %1$d: pattern index, %2$s: nearest pattern name. */
				__( 'Pattern %1$d does not match any page pattern (nearest: %2$s).', 'senroflux' ),
				$data['index'] ?? 0,
				$data['name'] ?? 'unknown'
			),
			'slot_count'            => sprintf(
				/* translators: %1$s: slot, %2$d: min, %3$d: max. */
				__( 'Slot "%1$s" must have %2$d to %3$d items.', 'senroflux' ),
				$data['slot'] ?? '',
				$data['min'] ?? 0,
				$data['max'] ?? 0
			),
			'page_shape'            => $this->pageShapeMessage( $data ),
			default                 => __( 'Invalid page content.', 'senroflux' ),
		};
	}

	/**
	 * @param array<string,mixed> $data Per-code message data.
	 */
	private function pageShapeMessage( array $data ): string {
		return match ( $data['rule'] ?? '' ) {
			'pattern_count' => sprintf(
				/* translators: %d: pattern count. */
				__( 'A page needs 2 to 8 patterns; %d given.', 'senroflux' ),
				$data['count'] ?? 0
			),
			'hero_first'    => __( 'The first pattern on a page must be a hero.', 'senroflux' ),
			'max_cta'       => __( 'A page may contain at most one call-to-action.', 'senroflux' ),
			'max_repeat'    => sprintf(
				/* translators: %1$s: pattern, %2$d: occurrences. */
				__( 'No pattern may repeat more than twice; "%1$s" was used %2$d times.', 'senroflux' ),
				$data['slug'] ?? '',
				$data['seen'] ?? 0
			),
			default         => __( 'The page does not satisfy the page-shape rules.', 'senroflux' ),
		};
	}
}
