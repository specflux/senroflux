<?php
/**
 * Editor-parity check for the pages pack (Validator step 4b).
 *
 * TARGET REPO PATH: src/Packs/Pages/BlockShells.php
 *
 * The block editor re-runs every block's save() from its comment attributes
 * and compares the result with the stored HTML; any difference is "Block
 * contains unexpected or invalid content". save() cannot run in PHP, and its
 * output shifts between WordPress versions, so this check does not reproduce
 * it. Instead the shipped Vocabulary markup is the reference — CI proves it
 * opens cleanly in the editor (`npm run test:editor`) — and every block a run
 * writes must match one of the vocabulary's blocks of the same name:
 *
 *   - the same comment attributes, ignoring `metadata`, an explicit default
 *     heading level, key order, and the SLUG of a preset (spacing
 *     `var:preset|spacing|<slug>`, `fontSize`), which may vary as long as
 *     slugs the vocabulary repeats stay equal;
 *   - the same HTML shell: tag sequence, inline style, attribute names and
 *     every class, with the preset slug substituted where the vocabulary has
 *     it. Rich-text content (inside p, h1–h6, li, summary, cite, a) is free.
 *     Extra classes are allowed: the editor keeps them as a custom class.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Packs\Pages;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Reference shells per block, derived from the vocabulary.
 */
final class BlockShells {

	/** Elements whose content is rich text the editor reads back, not structure. */
	private const RICH_TEXT = array( 'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'li', 'summary', 'cite', 'figcaption', 'a' );

	/** Attributes sourced from the HTML itself, so any value is consistent. */
	private const SOURCED = array( 'href', 'title', 'rel', 'target', 'id' );

	/** A preset slug this check will substitute; anything else must match verbatim. */
	private const SLUG = '#^(?:[a-z]+(?:-[a-z]+)*|[0-9]+)$#';

	/**
	 * Block name => attribute key => list of shells.
	 *
	 * @var array<string, array<string, list<list<array<string,mixed>>>>>|null
	 */
	private ?array $shells = null;

	public function __construct( private readonly Vocabulary $vocabulary ) {
	}

	/**
	 * The first block (depth-first) that matches no vocabulary shell.
	 *
	 * @param array<string,mixed> $block One parsed block.
	 * @return array{name:string, reason:string, found:string, expected:string}|null
	 */
	public function mismatch( array $block ): ?array {
		$name = (string) ( $block['blockName'] ?? '' );
		if ( '' !== $name ) {
			$found = $this->check( $name, $block );
			if ( null !== $found ) {
				return $found;
			}
		}

		$children = $block['innerBlocks'] ?? array();
		/** @var list<array<string,mixed>> $children */
		foreach ( $children as $child ) {
			$found = $this->mismatch( $child );
			if ( null !== $found ) {
				return $found;
			}
		}

		return null;
	}

	/**
	 * @param array<string,mixed> $block One parsed block.
	 * @return array{name:string, reason:string, found:string, expected:string}|null
	 */
	private function check( string $name, array $block ): ?array {
		$shells = $this->shells()[ $name ] ?? array();
		$slugs  = array();
		$key    = $this->attributeKey( $block, $slugs );

		if ( ! isset( $shells[ $key ] ) ) {
			return array(
				'name'     => $name,
				'reason'   => 'attributes',
				'found'    => $key,
				'expected' => implode( ' | ', array_keys( $shells ) ),
			);
		}

		$found = $this->shell( (string) ( $block['innerHTML'] ?? '' ), $slugs );
		foreach ( $shells[ $key ] as $expected ) {
			if ( $this->matches( $found, $expected ) ) {
				return null;
			}
		}

		return array(
			'name'     => $name,
			'reason'   => 'html',
			'found'    => $this->render( $found ),
			'expected' => $this->render( $shells[ $key ][0] ),
		);
	}

	/**
	 * @return array<string, array<string, list<list<array<string,mixed>>>>>
	 */
	private function shells(): array {
		if ( null !== $this->shells ) {
			return $this->shells;
		}

		$shells = array();
		foreach ( $this->vocabulary->all() as $pattern ) {
			foreach ( parse_blocks( (string) $pattern['markup'] ) as $block ) {
				$this->collect( $block, $shells );
			}
		}
		$this->shells = $shells;

		return $shells;
	}

	/**
	 * @param array<string,mixed>                                           $block  One parsed vocabulary block.
	 * @param array<string, array<string, list<list<array<string,mixed>>>>> $shells Out: shells collected so far.
	 */
	private function collect( array $block, array &$shells ): void {
		$name = (string) ( $block['blockName'] ?? '' );
		if ( '' !== $name ) {
			$slugs = array();
			$key   = $this->attributeKey( $block, $slugs );
			$shell = $this->shell( (string) ( $block['innerHTML'] ?? '' ), $slugs );
			if ( ! in_array( $shell, $shells[ $name ][ $key ] ?? array(), true ) ) {
				$shells[ $name ][ $key ][] = $shell;
			}
		}

		$children = $block['innerBlocks'] ?? array();
		/** @var list<array<string,mixed>> $children */
		foreach ( $children as $child ) {
			$this->collect( $child, $shells );
		}
	}

	/**
	 * Canonical JSON of the comment attributes with preset slugs replaced by
	 * `{{pN}}` tokens. Each replaced slug is recorded in `$slugs` under the
	 * HTML form the editor derives from it.
	 *
	 * @param array<string,mixed>  $block One parsed block.
	 * @param array<string,string> $slugs Out: HTML form => `{{pN}}` token.
	 */
	private function attributeKey( array $block, array &$slugs ): string {
		$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
		unset( $attrs['metadata'] );
		if ( 'core/heading' === ( $block['blockName'] ?? '' ) && 2 === ( $attrs['level'] ?? null ) ) {
			unset( $attrs['level'] );
		}

		$attrs = $this->tokenize( $attrs, $slugs );

		return array() === $attrs ? '{}' : (string) wp_json_encode( $attrs );
	}

	/**
	 * @param array<string,mixed>  $attrs Attributes (recursively).
	 * @param array<string,string> $slugs Out: HTML form => token.
	 * @return array<string,mixed>
	 */
	private function tokenize( array $attrs, array &$slugs ): array {
		ksort( $attrs );
		foreach ( $attrs as $key => $value ) {
			if ( is_array( $value ) ) {
				/** @var array<string,mixed> $value */
				$attrs[ $key ] = $this->tokenize( $value, $slugs );
				continue;
			}
			if ( ! is_string( $value ) ) {
				continue;
			}

			$preset = array();
			if ( preg_match( '#^var:preset\|([a-z]+(?:-[a-z]+)*)\|(.+)$#', $value, $preset ) && preg_match( self::SLUG, $preset[2] ) ) {
				$token         = $this->slugToken( $slugs, 'var(--wp--preset--' . $preset[1] . '--', $preset[2], ')' );
				$attrs[ $key ] = 'var:preset|' . $preset[1] . '|' . $token;
			} elseif ( 'fontSize' === $key && preg_match( self::SLUG, $value ) ) {
				$attrs[ $key ] = $this->slugToken( $slugs, 'has-', $value, '-font-size' );
			}
		}

		return $attrs;
	}

	/**
	 * One token per distinct slug HTML form: the same slug twice is the same
	 * token, so the key also records which presets must be equal — otherwise
	 * a slug changed on one side only would hide behind its twin.
	 *
	 * @param array<string,string> $slugs Out: HTML form => token.
	 */
	private function slugToken( array &$slugs, string $prefix, string $slug, string $suffix ): string {
		$form = $prefix . $slug . $suffix;
		if ( ! isset( $slugs[ $form ] ) ) {
			$slugs[ $form ] = $prefix . '{{p' . count( $slugs ) . '}}' . $suffix;
		}

		return (string) preg_replace( '#^.*(\{\{p\d+\}\}).*$#', '$1', $slugs[ $form ] );
	}

	/**
	 * The block's own HTML reduced to its shell: one entry per structural tag
	 * (rich-text content skipped), plus any stray text outside rich text.
	 *
	 * @param string               $html  The block's innerHTML (children excluded).
	 * @param array<string,string> $slugs HTML form => token, from attributeKey().
	 * @return list<array<string,mixed>>
	 */
	private function shell( string $html, array $slugs ): array {
		$html  = strtr( $html, $slugs );
		$parts = preg_split( '#(<\s*/?\s*[a-zA-Z][a-zA-Z0-9]*[^>]*>)#', $html, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );
		$parts = false === $parts ? array() : $parts;
		$shell = array();
		$skip  = null;
		$depth = 0;

		foreach ( $parts as $part ) {
			$tag = array();
			if ( ! preg_match( '#^<\s*(/?)\s*([a-zA-Z][a-zA-Z0-9]*)([^>]*)>$#', $part, $tag ) ) {
				if ( null === $skip && '' !== trim( $part ) ) {
					$shell[] = array( 'text' => true );
				}
				continue;
			}

			$name    = strtolower( $tag[2] );
			$closing = '/' === $tag[1];

			if ( null !== $skip ) {
				if ( $name === $skip ) {
					$depth += $closing ? -1 : 1;
				}
				if ( 0 === $depth ) {
					$skip    = null;
					$shell[] = array( 'close' => $name );
				}
				continue;
			}

			if ( $closing ) {
				$shell[] = array( 'close' => $name );
				continue;
			}

			$shell[] = $this->element( $name, $tag[3] );
			if ( in_array( $name, self::RICH_TEXT, true ) && ! str_ends_with( rtrim( $tag[3] ), '/' ) ) {
				$skip  = $name;
				$depth = 1;
			}
		}

		return $shell;
	}

	/**
	 * @return array{tag:string, class:list<string>, style:string, attrs:list<string>}
	 */
	private function element( string $tag, string $attribute_text ): array {
		$found = array();
		preg_match_all(
			'#([a-zA-Z_:][a-zA-Z0-9_:.\-]*)\s*(?:=\s*("[^"]*"|\'[^\']*\'|[^\s"\'>]+))?#',
			$attribute_text,
			$found,
			PREG_SET_ORDER
		);

		$classes = array();
		$style   = array();
		$names   = array();
		foreach ( $found as $attr ) {
			$name  = strtolower( $attr[1] );
			$value = trim( $attr[2] ?? '', "\"'" );
			if ( 'class' === $name ) {
				$classes = array_values( array_filter( explode( ' ', (string) preg_replace( '#\s+#', ' ', $value ) ) ) );
			} elseif ( 'style' === $name ) {
				foreach ( explode( ';', $value ) as $declaration ) {
					if ( '' !== trim( $declaration ) ) {
						$style[] = (string) preg_replace( '#\s*:\s*#', ':', strtolower( trim( $declaration ) ), 1 );
					}
				}
			} elseif ( ! in_array( $name, self::SOURCED, true ) ) {
				$names[] = $name;
			}
		}

		sort( $classes );
		sort( $style );
		sort( $names );

		return array(
			'tag'   => $tag,
			'class' => $classes,
			'style' => implode( ';', $style ),
			'attrs' => $names,
		);
	}

	/**
	 * Same tags, styles and attribute names in the same order; every expected
	 * class present (extra classes are allowed).
	 *
	 * @param list<array<string,mixed>> $found    Shell of the written block.
	 * @param list<array<string,mixed>> $expected A vocabulary shell.
	 */
	private function matches( array $found, array $expected ): bool {
		if ( count( $found ) !== count( $expected ) ) {
			return false;
		}

		foreach ( $expected as $i => $want ) {
			$have = $found[ $i ];
			if ( ! isset( $want['tag'] ) ) {
				if ( $have !== $want ) {
					return false;
				}
				continue;
			}
			/** @var array{tag:string, class:list<string>, style:string, attrs:list<string>} $want */
			if ( ( $have['tag'] ?? null ) !== $want['tag']
				|| ( $have['style'] ?? null ) !== $want['style']
				|| ( $have['attrs'] ?? null ) !== $want['attrs']
				|| array() !== array_diff( $want['class'], (array) ( $have['class'] ?? array() ) )
			) {
				return false;
			}
		}

		return true;
	}

	/**
	 * A shell as readable HTML, rich text shown as `…`, for the tool error.
	 *
	 * @param list<array<string,mixed>> $shell A shell.
	 */
	private function render( array $shell ): string {
		$out = '';
		foreach ( $shell as $i => $node ) {
			if ( isset( $node['text'] ) ) {
				$out .= 'text';
				continue;
			}
			if ( isset( $node['close'] ) ) {
				$out .= '</' . $node['close'] . '>';
				continue;
			}

			/** @var array{tag:string, class:list<string>, style:string, attrs:list<string>} $node */
			$out .= '<' . $node['tag'];
			if ( array() !== $node['class'] ) {
				$out .= ' class="' . implode( ' ', $node['class'] ) . '"';
			}
			if ( '' !== $node['style'] ) {
				$out .= ' style="' . $node['style'] . '"';
			}
			foreach ( $node['attrs'] as $name ) {
				$out .= ' ' . $name;
			}
			$out .= '>';
			if ( in_array( $node['tag'], self::RICH_TEXT, true ) && ( $shell[ $i + 1 ]['close'] ?? null ) === $node['tag'] ) {
				$out .= '…';
			}
		}

		return $out;
	}
}
