<?php
/**
 * Pack-owned write validation for the posts pack (S5).
 *
 * TARGET REPO PATH: src/Packs/Posts/Validator.php
 *
 * Adapted from the pages pack's Validator (same round-trip / block-name /
 * markup-safety / decorative-color / placeholder steps), but the PATTERN step
 * is different in shape because a post's content is mostly prose written
 * directly at the top level, not group-wrapped patterns:
 *   1. round-trip after normalisation → `invalid_markup`.
 *   2. every block `core/*` and inside the vocabulary's block set →
 *      `unknown_block`.
 *   2b. tag/attribute allow-list (the XSS gate) → `disallowed_markup`.
 *   2c. no colour attribute → `decorative_color`.
 *   3. unresolved `{{placeholder}}` → `unresolved_placeholder`.
 *   4. `core/image` with empty/missing alt → `missing_alt` (a shape
 *      violation, refused like any other — S5).
 *   4b. a `core/group`/`core/pullquote` at the top level must match one of
 *       the two feature-pattern shapes, else `unknown_pattern`.
 *   4c. post shape: at least one counted instance (`post_shape` /
 *       `too_few_patterns`), the runaway guard (`too_many_blocks`), at most
 *       one closing CTA and at most {@see Vocabulary::RULES_MAX_PULL_QUOTE}
 *       pull quotes (`post_shape` / `max_closing_cta` / `max_pull_quote`).
 *   5. mutation — normalise a feature pattern's `metadata.name`.
 *
 * SCOPE DECISION: unlike the pages pack, this Validator does NOT reproduce
 * the pages pack's `BlockShells` editor-parity check (S11 is a pages-only
 * requirement; S5 does not ask for it). Prose blocks carry no fixed HTML
 * shell to match against — the block-name allow-list, the XSS gate and the
 * decorative-color check already bound what a prose block may contain.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Packs\Posts;

use Specflux\SenroFlux\Packs\Content\Validator as ContentValidator;
use WP_Error;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Validates and cleans posts-pack block markup. Implements the S4
 * {@see ContentValidator} seam.
 */
final class Validator implements ContentValidator {

	/**
	 * The HTML tags a post's prose and feature patterns may legitimately
	 * contain, mapped to their allowed attributes. `img` is present (unlike
	 * the pages pack) because `core/image` is admitted here; `script`,
	 * `iframe`, `style`, `form`, `svg`, an `on*` handler are refusals.
	 *
	 * @var array<string, list<string>>
	 */
	private const ALLOWED_TAGS = array(
		'div'        => array( 'class', 'style', 'id' ),
		'p'          => array( 'class', 'style', 'id' ),
		'h1'         => array( 'class', 'style', 'id' ),
		'h2'         => array( 'class', 'style', 'id' ),
		'h3'         => array( 'class', 'style', 'id' ),
		'h4'         => array( 'class', 'style', 'id' ),
		'h5'         => array( 'class', 'style', 'id' ),
		'h6'         => array( 'class', 'style', 'id' ),
		'ul'         => array( 'class', 'style', 'id' ),
		'ol'         => array( 'class', 'style', 'id', 'start', 'reversed', 'type' ),
		'li'         => array( 'class', 'style', 'id', 'value' ),
		'blockquote' => array( 'class', 'style', 'id', 'cite' ),
		'cite'       => array( 'class', 'style', 'id' ),
		'figure'     => array( 'class', 'style', 'id' ),
		'figcaption' => array( 'class', 'style', 'id' ),
		'img'        => array( 'class', 'style', 'id', 'src', 'alt', 'width', 'height', 'loading', 'decoding' ),
		'pre'        => array( 'class', 'style', 'id' ),
		'code'       => array( 'class', 'style', 'id' ),
		'a'          => array( 'class', 'style', 'id', 'href', 'rel', 'target', 'title' ),
		'span'       => array( 'class', 'style', 'id' ),
		'strong'     => array( 'class', 'style', 'id' ),
		'em'         => array( 'class', 'style', 'id' ),
		'b'          => array( 'class', 'style', 'id' ),
		'i'          => array( 'class', 'style', 'id' ),
		's'          => array( 'class', 'style', 'id' ),
		'u'          => array( 'class', 'style', 'id' ),
		'del'        => array( 'class', 'style', 'id' ),
		'ins'        => array( 'class', 'style', 'id' ),
		'sub'        => array( 'class', 'style', 'id' ),
		'sup'        => array( 'class', 'style', 'id' ),
		'mark'       => array( 'class', 'style', 'id' ),
		'br'         => array( 'class', 'style', 'id' ),
	);

	/**
	 * Attributes whose value is a URL and therefore gets a scheme check.
	 *
	 * @var list<string>
	 */
	private const URL_ATTRIBUTES = array( 'href', 'cite', 'src' );

	/**
	 * The only URL schemes a post may link/embed. `data:` is refused, so an
	 * image's `src` must be a real upload URL, not an inline payload.
	 *
	 * @var list<string>
	 */
	private const ALLOWED_SCHEMES = array( 'http', 'https', 'mailto', 'tel' );

	/**
	 * Substrings that make a `style` attribute value a refusal.
	 *
	 * @var list<string>
	 */
	private const STYLE_DENY = array( 'expression(', 'url(', 'javascript', '@import', '\\', '<', '&#' );

	public function __construct( private readonly Vocabulary $vocabulary ) {
	}

	/**
	 * @param string              $content Serialized block markup.
	 * @param array<string,mixed> $ctx     Context (e.g. post_type).
	 * @return true|WP_Error
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
	 * @param string              $content Serialized block markup.
	 * @param array<string,mixed> $ctx     Context.
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
	 * @param string              $content Serialized block markup.
	 * @param array<string,mixed> $ctx     Context.
	 * @return array{ok:bool, content:string, blocks:list<array<string,mixed>>, identities:array<int,string>, wp_error:WP_Error|null}
	 */
	private function run( string $content, array $ctx ): array {
		$blocks = $this->parse( $content );
		if ( null === $blocks ) {
			return $this->refuse( new WP_Error( 'invalid_markup', $this->message( 'invalid_markup', $ctx ), array( 'status' => 400 ) ) );
		}

		$reserialized = $this->serialize( $blocks );
		if ( $this->normalize( $content ) !== $this->normalize( $reserialized ) ) {
			return $this->refuse( new WP_Error( 'invalid_markup', $this->message( 'invalid_markup', $ctx ), array( 'status' => 400 ) ) );
		}

		$block_error = $this->checkBlockNames( $blocks );
		if ( null !== $block_error ) {
			return $this->refuse( $block_error );
		}

		$markup_error = $this->checkMarkupSafety( $blocks );
		if ( null !== $markup_error ) {
			return $this->refuse( $markup_error );
		}

		$color_error = $this->checkDecorativeColor( $blocks );
		if ( null !== $color_error ) {
			return $this->refuse( $color_error );
		}

		$placeholder = $this->findPlaceholder( $content );
		if ( null !== $placeholder ) {
			return $this->refuse(
				new WP_Error(
					'unresolved_placeholder',
					$this->message( 'unresolved_placeholder', $ctx, array( 'placeholder' => $placeholder ) ),
					array(
						'status'      => 400,
						'placeholder' => $placeholder,
					)
				)
			);
		}

		$alt_error = $this->checkImageAlt( $blocks );
		if ( null !== $alt_error ) {
			return $this->refuse( $alt_error );
		}

		$identity_result = $this->identifyTopLevel( $blocks );
		if ( $identity_result['error'] instanceof WP_Error ) {
			return $this->refuse( $identity_result['error'] );
		}
		/** @var array<int,string> $identities */
		$identities = $identity_result['identities'];
		/** @var array<string,int> $counts */
		$counts = $identity_result['counts'];

		$shape_error = $this->checkPostShape( $counts );
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
	 * @param array<string,mixed> $block One parsed block.
	 */
	private function isPatternBlock( array $block ): bool {
		return is_string( $block['blockName'] ?? null );
	}

	private function normalize( string $text ): string {
		return (string) preg_replace( '/\s+/', ' ', trim( $text ) );
	}

	/**
	 * @return list<array<string,mixed>>|null
	 */
	private function parse( string $content ): ?array {
		if ( ! function_exists( 'parse_blocks' ) || ! function_exists( 'serialize_blocks' ) ) {
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
			return '';
		}

		/** @var list<array{blockName: string|null, attrs: array<string,mixed>, innerBlocks: list<array<string,mixed>>, innerHTML: string, innerContent: array<string,mixed>}> $blocks */
		return (string) serialize_blocks( $blocks );
	}

	/**
	 * Step 2 — every blockName must be non-null, `core/*`, and within the
	 * vocabulary's block set.
	 *
	 * @param list<array<string,mixed>> $blocks Parsed top-level blocks.
	 */
	private function checkBlockNames( array $blocks ): ?WP_Error {
		$allowed = array_flip( $this->vocabulary->blockNames() );
		$index   = 0;

		$walk = static function ( array $node, callable $recurse, bool $top ) use ( &$allowed, &$index ): ?array {
			$name = $node['blockName'] ?? null;
			if ( ! is_string( $name ) ) {
				if ( $top && '' === trim( (string) ( $node['innerHTML'] ?? '' ) ) ) {
					return null;
				}

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
				$found = $recurse( $child, $recurse, false );
				if ( null !== $found ) {
					return $found;
				}
			}

			return null;
		};

		$found = null;
		foreach ( $blocks as $block ) {
			$found = $walk( $block, $walk, true );
			if ( null !== $found ) {
				break;
			}
			if ( $this->isPatternBlock( $block ) ) {
				++$index;
			}
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
	 * @param list<array<string,mixed>> $blocks Parsed top-level blocks.
	 */
	private function checkMarkupSafety( array $blocks ): ?WP_Error {
		$index = 0;
		foreach ( $blocks as $block ) {
			$found = $this->findDisallowedMarkup( $block );
			if ( null !== $found ) {
				$data = array(
					'index'  => $index,
					'reason' => $found['reason'],
					'tag'    => $found['tag'],
					'attr'   => $found['attr'],
				);

				return new WP_Error(
					'disallowed_markup',
					$this->message( 'disallowed_markup', array(), $data ),
					array_merge( array( 'status' => 400 ), $data )
				);
			}
			if ( $this->isPatternBlock( $block ) ) {
				++$index;
			}
		}

		return null;
	}

	/**
	 * @param array<string,mixed> $block One parsed block.
	 * @return array{reason:string, tag:string, attr:string}|null
	 */
	private function findDisallowedMarkup( array $block ): ?array {
		$found = $this->scanHtml( (string) ( $block['innerHTML'] ?? '' ) );
		if ( null !== $found ) {
			return $found;
		}

		$children = $block['innerBlocks'] ?? array();
		/** @var list<array<string,mixed>> $children */
		foreach ( $children as $child ) {
			$found = $this->findDisallowedMarkup( $child );
			if ( null !== $found ) {
				return $found;
			}
		}

		return null;
	}

	/**
	 * @return array{reason:string, tag:string, attr:string}|null
	 */
	private function scanHtml( string $html ): ?array {
		if ( ! str_contains( $html, '<' ) ) {
			return null;
		}

		$matches = array();
		if ( ! preg_match_all( '#<\s*(/?)\s*([a-zA-Z][a-zA-Z0-9]*)([^>]*)>?#', $html, $matches, PREG_SET_ORDER ) ) {
			return null;
		}

		foreach ( $matches as $match ) {
			$tag = strtolower( $match[2] );
			if ( ! isset( self::ALLOWED_TAGS[ $tag ] ) ) {
				return array(
					'reason' => 'tag',
					'tag'    => $tag,
					'attr'   => '',
				);
			}

			if ( '/' === $match[1] ) {
				continue;
			}

			$found = $this->scanAttributes( $tag, $match[3] );
			if ( null !== $found ) {
				return $found;
			}
		}

		return null;
	}

	/**
	 * @return array{reason:string, tag:string, attr:string}|null
	 */
	private function scanAttributes( string $tag, string $attribute_text ): ?array {
		$allowed = array_flip( self::ALLOWED_TAGS[ $tag ] );
		$attrs   = array();
		preg_match_all(
			'#([a-zA-Z_:][a-zA-Z0-9_:.\-]*)\s*(?:=\s*("[^"]*"|\'[^\']*\'|[^\s"\'>]+))?#',
			$attribute_text,
			$attrs,
			PREG_SET_ORDER
		);

		foreach ( $attrs as $attr ) {
			$name = strtolower( $attr[1] );
			if ( ! isset( $allowed[ $name ] ) ) {
				return array(
					'reason' => 'attr',
					'tag'    => $tag,
					'attr'   => $name,
				);
			}

			$value = trim( $attr[2] ?? '', "\"'" );

			if ( 'style' === $name && $this->styleIsUnsafe( $value ) ) {
				return array(
					'reason' => 'style',
					'tag'    => $tag,
					'attr'   => $name,
				);
			}

			if ( in_array( $name, self::URL_ATTRIBUTES, true ) && ! $this->urlIsSafe( $value ) ) {
				return array(
					'reason' => 'url',
					'tag'    => $tag,
					'attr'   => $name,
				);
			}
		}

		return null;
	}

	private function styleIsUnsafe( string $value ): bool {
		$flat = strtolower( $this->flatten( $value ) );
		foreach ( self::STYLE_DENY as $needle ) {
			if ( str_contains( $flat, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	private function urlIsSafe( string $value ): bool {
		$flat = strtolower( $this->flatten( $value ) );
		if ( '' === $flat ) {
			return true;
		}

		$scheme = array();
		if ( ! preg_match( '#^([a-z][a-z0-9+.\-]*):#', $flat, $scheme ) ) {
			$colon = strpos( $flat, ':' );
			$slash = strpos( $flat, '/' );

			return false === $colon || ( false !== $slash && $slash < $colon );
		}

		return in_array( $scheme[1], self::ALLOWED_SCHEMES, true );
	}

	private function flatten( string $value ): string {
		$decoded = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		return (string) preg_replace( '/[\x00-\x20\x7f]+/', '', $decoded );
	}

	/**
	 * @return string|null The captured placeholder, or null when clean.
	 */
	private function findPlaceholder( string $content ): ?string {
		if ( preg_match_all( '/(?<!\{)\{\{([^{}]{0,200})\}\}(?!\})/', $content, $matches ) ) {
			return trim( $matches[1][0] );
		}

		return null;
	}

	/**
	 * @param array<string,mixed> $block One parsed block.
	 * @return array{name:string, attr:string}|null
	 */
	private function findDecorativeColor( array $block ): ?array {
		$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
		foreach ( array( 'backgroundColor', 'textColor', 'gradient' ) as $key ) {
			if ( array_key_exists( $key, $attrs ) ) {
				return array(
					'name' => (string) ( $block['blockName'] ?? '' ),
					'attr' => $key,
				);
			}
		}
		if ( is_array( $attrs['style'] ?? null ) && array_key_exists( 'color', $attrs['style'] ) ) {
			return array(
				'name' => (string) ( $block['blockName'] ?? '' ),
				'attr' => 'style.color',
			);
		}

		$children = $block['innerBlocks'] ?? array();
		/** @var list<array<string,mixed>> $children */
		foreach ( $children as $child ) {
			$found = $this->findDecorativeColor( $child );
			if ( null !== $found ) {
				return $found;
			}
		}

		return null;
	}

	/**
	 * @param list<array<string,mixed>> $blocks Parsed top-level blocks.
	 */
	private function checkDecorativeColor( array $blocks ): ?WP_Error {
		$index = 0;
		foreach ( $blocks as $block ) {
			$found = $this->findDecorativeColor( $block );
			if ( null !== $found ) {
				$data = array(
					'index' => $index,
					'name'  => $found['name'],
					'attr'  => $found['attr'],
				);

				return new WP_Error(
					'decorative_color',
					$this->message( 'decorative_color', array(), $data ),
					array_merge( array( 'status' => 400 ), $data )
				);
			}
			if ( $this->isPatternBlock( $block ) ) {
				++$index;
			}
		}

		return null;
	}

	/**
	 * Step 4 (S5): `core/image` admitted only with non-empty alt. Walks the
	 * whole tree — an image nested inside a feature pattern is checked too.
	 *
	 * @param list<array<string,mixed>> $blocks Parsed top-level blocks.
	 */
	private function checkImageAlt( array $blocks ): ?WP_Error {
		$index = 0;
		foreach ( $blocks as $block ) {
			if ( $this->findMissingAlt( $block ) ) {
				return new WP_Error(
					'missing_alt',
					$this->message( 'missing_alt', array(), array( 'index' => $index ) ),
					array(
						'status' => 400,
						'index'  => $index,
					)
				);
			}
			if ( $this->isPatternBlock( $block ) ) {
				++$index;
			}
		}

		return null;
	}

	/**
	 * @param array<string,mixed> $block One parsed block.
	 */
	private function findMissingAlt( array $block ): bool {
		if ( 'core/image' === ( $block['blockName'] ?? null ) ) {
			$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
			$alt   = isset( $attrs['alt'] ) && is_string( $attrs['alt'] ) ? trim( $attrs['alt'] ) : '';
			if ( '' === $alt ) {
				return true;
			}
		}

		$children = $block['innerBlocks'] ?? array();
		/** @var list<array<string,mixed>> $children */
		foreach ( $children as $child ) {
			if ( $this->findMissingAlt( $child ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Step 4b/4c — identify every top-level block as prose, `closing-cta`,
	 * `pull-quote`, or refuse it as `unknown_pattern`; count occurrences for
	 * the post-shape check.
	 *
	 * @param list<array<string,mixed>> $blocks Parsed top-level blocks.
	 * @return array{identities:array<int,string>, counts:array<string,int>, error:WP_Error|null}
	 */
	private function identifyTopLevel( array $blocks ): array {
		$identities = array();
		$counts     = array();
		$prose      = array_flip( $this->vocabulary->proseBlockNames() );

		foreach ( $blocks as $offset => $block ) {
			if ( ! $this->isPatternBlock( $block ) ) {
				continue;
			}

			$name = (string) ( $block['blockName'] ?? '' );

			if ( isset( $prose[ $name ] ) ) {
				$identities[ $offset ] = $this->proseSlug( $name );
				$counts['__prose__']   = ( $counts['__prose__'] ?? 0 ) + 1;
				continue;
			}

			if ( 'core/group' === $name && $this->matchesClosingCta( $block ) ) {
				$identities[ $offset ] = 'closing-cta';
				$counts['closing-cta'] = ( $counts['closing-cta'] ?? 0 ) + 1;
				continue;
			}

			if ( 'core/pullquote' === $name ) {
				$identities[ $offset ] = 'pull-quote';
				$counts['pull-quote']  = ( $counts['pull-quote'] ?? 0 ) + 1;
				continue;
			}

			// A structural block (group) that does not match the one shape
			// this vocabulary recognises is an unknown pattern, not silently
			// admitted prose.
			return array(
				'identities' => array(),
				'counts'     => array(),
				'error'      => new WP_Error(
					'unknown_pattern',
					$this->message(
						'unknown_pattern',
						array(),
						array(
							'index' => $offset,
							'name'  => $name,
						)
					),
					array(
						'status' => 400,
						'index'  => $offset,
						'name'   => $name,
					)
				),
			);
		}

		return array(
			'identities' => $identities,
			'counts'     => $counts,
			'error'      => null,
		);
	}

	private function proseSlug( string $block_name ): string {
		$pos = strrpos( $block_name, '/' );

		return false === $pos ? $block_name : substr( $block_name, $pos + 1 );
	}

	/**
	 * closing-cta — group[align=full] > heading > paragraph > buttons >
	 * button(1). A fixed shape check (there is only one structural pattern to
	 * match, so the pages pack's general recursive matcher is not needed).
	 *
	 * @param array<string,mixed> $block One parsed `core/group` block.
	 */
	private function matchesClosingCta( array $block ): bool {
		$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
		if ( 'full' !== ( $attrs['align'] ?? '' ) ) {
			return false;
		}

		$children = array_values( $block['innerBlocks'] ?? array() );
		if ( 3 !== count( $children ) ) {
			return false;
		}

		if ( 'core/heading' !== ( $children[0]['blockName'] ?? '' ) ) {
			return false;
		}
		if ( 'core/paragraph' !== ( $children[1]['blockName'] ?? '' ) ) {
			return false;
		}
		if ( 'core/buttons' !== ( $children[2]['blockName'] ?? '' ) ) {
			return false;
		}

		$buttons = array_values( $children[2]['innerBlocks'] ?? array() );

		return 1 === count( $buttons ) && 'core/button' === ( $buttons[0]['blockName'] ?? '' );
	}

	/**
	 * The S5 post-shape rules: at least one counted instance, the runaway
	 * guard, at most one closing CTA, at most
	 * {@see Vocabulary::RULES_MAX_PULL_QUOTE} pull quotes.
	 *
	 * @param array<string,int> $counts Slug (or `__prose__`) => count.
	 */
	private function checkPostShape( array $counts ): ?WP_Error {
		$total = array_sum( $counts );

		if ( $total < Vocabulary::RULES_MIN_PATTERNS ) {
			return new WP_Error(
				'post_shape',
				$this->message(
					'post_shape',
					array(),
					array(
						'rule'  => 'too_few_patterns',
						'count' => $total,
					)
				),
				array(
					'status' => 400,
					'rule'   => 'too_few_patterns',
					'count'  => $total,
				)
			);
		}

		if ( $total > Vocabulary::RUNAWAY_GUARD ) {
			return new WP_Error(
				'too_many_blocks',
				$this->message( 'too_many_blocks', array(), array( 'count' => $total ) ),
				array(
					'status' => 400,
					'count'  => $total,
				)
			);
		}

		$cta_count = $counts['closing-cta'] ?? 0;
		if ( $cta_count > Vocabulary::RULES_MAX_CLOSING_CTA ) {
			return new WP_Error(
				'post_shape',
				$this->message(
					'post_shape',
					array(),
					array(
						'rule'  => 'max_closing_cta',
						'count' => $cta_count,
					)
				),
				array(
					'status' => 400,
					'rule'   => 'max_closing_cta',
					'count'  => $cta_count,
				)
			);
		}

		$pull_quote_count = $counts['pull-quote'] ?? 0;
		if ( $pull_quote_count > Vocabulary::RULES_MAX_PULL_QUOTE ) {
			return new WP_Error(
				'post_shape',
				$this->message(
					'post_shape',
					array(),
					array(
						'rule'  => 'max_pull_quote',
						'count' => $pull_quote_count,
					)
				),
				array(
					'status' => 400,
					'rule'   => 'max_pull_quote',
					'count'  => $pull_quote_count,
				)
			);
		}

		return null;
	}

	/**
	 * Step 5 — normalise `metadata.name` on the two feature patterns.
	 *
	 * @param list<array<string,mixed>> $blocks     Parsed top-level blocks.
	 * @param array<int,string>         $identities parse offset => slug.
	 */
	private function mutate( array $blocks, array $identities ): string {
		$mutated = $blocks;
		foreach ( $mutated as $i => $block ) {
			$slug = $identities[ $i ] ?? null;
			if ( 'closing-cta' === $slug || 'pull-quote' === $slug ) {
				$attrs                  = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
				$attrs['metadata']      = array( 'name' => 'senroflux/' . $slug );
				$mutated[ $i ]['attrs'] = $attrs;
			}
		}

		return $this->serialize( $mutated );
	}

	/**
	 * @param array<string,mixed> $ctx  Context (unused; kept for call-site symmetry with the pages pack).
	 * @param array<string,mixed> $data Per-code message data.
	 */
	private function message( string $code, array $ctx, array $data = array() ): string {
		unset( $ctx );

		return match ( $code ) {
			'invalid_markup'         => __( 'The post content is not well-formed block markup.', 'senroflux' ),
			'unknown_block'          => sprintf(
				/* translators: %1$d: block index, %2$s: block name. */
				__( 'Block %1$d "%2$s" is not a posts-vocabulary block.', 'senroflux' ),
				$data['index'] ?? 0,
				$data['name'] ?? 'unknown'
			),
			'disallowed_markup'      => $this->disallowedMarkupMessage( $data ),
			'decorative_color'       => sprintf(
				/* translators: %1$d: block index, %2$s: block name, %3$s: attribute. */
				__( 'Block %1$d: "%2$s" sets the colour attribute "%3$s". Remove it; posts take their colours from the theme.', 'senroflux' ),
				$data['index'] ?? 0,
				$data['name'] ?? '',
				$data['attr'] ?? ''
			),
			'unresolved_placeholder' => sprintf(
				/* translators: %s: placeholder. */
				__( 'Unresolved placeholder "{{%s}}" must be filled before writing.', 'senroflux' ),
				$data['placeholder'] ?? ''
			),
			'missing_alt'            => sprintf(
				/* translators: %d: block index. */
				__( 'Block %d is an image with no alt text; every image needs non-empty, descriptive alt text.', 'senroflux' ),
				$data['index'] ?? 0
			),
			'unknown_pattern'        => sprintf(
				/* translators: %1$d: block index, %2$s: block name. */
				__( 'Block %1$d ("%2$s") does not match a posts feature pattern.', 'senroflux' ),
				$data['index'] ?? 0,
				$data['name'] ?? 'unknown'
			),
			'too_many_blocks'        => sprintf(
				/* translators: %1$d: block count, %2$d: runaway guard. */
				__( 'This post has %1$d blocks, over the runaway guard of %2$d.', 'senroflux' ),
				$data['count'] ?? 0,
				Vocabulary::RUNAWAY_GUARD
			),
			'post_shape'             => $this->postShapeMessage( $data ),
			default                  => __( 'Invalid post content.', 'senroflux' ),
		};
	}

	/**
	 * @param array<string,mixed> $data Per-code message data.
	 */
	private function disallowedMarkupMessage( array $data ): string {
		return match ( $data['reason'] ?? '' ) {
			'tag'   => sprintf(
				/* translators: %1$d: block index, %2$s: HTML tag. */
				__( 'Block %1$d contains the disallowed HTML tag "<%2$s>".', 'senroflux' ),
				$data['index'] ?? 0,
				$data['tag'] ?? ''
			),
			'attr'  => sprintf(
				/* translators: %1$d: block index, %2$s: attribute, %3$s: HTML tag. */
				__( 'Block %1$d contains the disallowed attribute "%2$s" on "<%3$s>".', 'senroflux' ),
				$data['index'] ?? 0,
				$data['attr'] ?? '',
				$data['tag'] ?? ''
			),
			'style' => sprintf(
				/* translators: %1$d: block index, %2$s: HTML tag. */
				__( 'Block %1$d has an unsafe style attribute on "<%2$s>".', 'senroflux' ),
				$data['index'] ?? 0,
				$data['tag'] ?? ''
			),
			'url'   => sprintf(
				/* translators: %1$d: block index, %2$s: attribute, %3$s: HTML tag. */
				__( 'Block %1$d has an unsafe URL in "%2$s" on "<%3$s>"; use http, https, mailto or tel.', 'senroflux' ),
				$data['index'] ?? 0,
				$data['attr'] ?? '',
				$data['tag'] ?? ''
			),
			default => __( 'The post contains markup that is not allowed.', 'senroflux' ),
		};
	}

	/**
	 * @param array<string,mixed> $data Per-code message data.
	 */
	private function postShapeMessage( array $data ): string {
		return match ( $data['rule'] ?? '' ) {
			'too_few_patterns' => __( 'A post needs at least one paragraph, heading or other content block.', 'senroflux' ),
			'max_closing_cta'  => __( 'A post may contain at most one closing call to action.', 'senroflux' ),
			'max_pull_quote'   => sprintf(
				/* translators: %d: allowed pull quotes. */
				__( 'A post may contain at most %d pull quotes.', 'senroflux' ),
				Vocabulary::RULES_MAX_PULL_QUOTE
			),
			default            => __( 'The post does not satisfy the post-shape rules.', 'senroflux' ),
		};
	}
}
