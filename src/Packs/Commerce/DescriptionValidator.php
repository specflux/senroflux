<?php
/**
 * Product description/short_description validator (S19, D3, stage 12).
 *
 * TARGET REPO PATH: src/Packs/Commerce/DescriptionValidator.php
 *
 * `woocommerce/product-create` and `woocommerce/product-update` are Woo-owned
 * abilities: this pack never registers them and can never add its own
 * validation inside their `execute_callback`. The enforcement seam is
 * {@see \Specflux\SenroFlux\Packs\Pack::validateCall()}, which
 * {@see \Specflux\SenroFlux\Run\Runner::executeCall()} calls BEFORE the
 * ability's own `check_permissions()`/`execute()` — see
 * {@see \Specflux\SenroFlux\Packs\Commerce\CommercePack::validateCall()}.
 *
 * Allowed tags (S19): `p`, `ul`, `ol`, `li`, `strong`, `em`, `a` (href
 * checked by the SAME rule the pages pack's Validator uses — reused via
 * {@see \Specflux\SenroFlux\Packs\Pages\Validator::urlIsSafe()}, never
 * copied) and `h3`. At most 1,500 words. Anything outside the allow-list is
 * REFUSED, never silently stripped (S19: "never stripped").
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Packs\Commerce;

use Specflux\SenroFlux\Packs\Pages\Validator as PagesValidator;
use WP_Error;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Validates one product description/short_description string.
 */
final class DescriptionValidator {

	/** The allowed tag names, lower-case, no namespace prefix. */
	private const ALLOWED_TAGS = array( 'p', 'ul', 'ol', 'li', 'strong', 'em', 'a', 'h3' );

	/** S19 `[assumed]`: at most this many words. */
	public const MAX_WORDS = 1500;

	/**
	 * Validate one description string. Null = accepted; a WP_Error names the
	 * first violation found, checked in a fixed order (tag, then href, then
	 * length) so the same input always refuses for the same reason.
	 */
	public function validate( string $html ): ?WP_Error {
		$tag = $this->firstDisallowedTag( $html );
		if ( null !== $tag ) {
			return new WP_Error(
				'disallowed_tag',
				sprintf(
					/* translators: %s: the disallowed HTML tag name. */
					__( 'Product descriptions may not use <%s>. Allowed tags: p, ul, ol, li, strong, em, a, h3.', 'senroflux' ),
					$tag
				),
				array( 'status' => 400 )
			);
		}

		if ( $this->hasUnsafeHref( $html ) ) {
			return new WP_Error(
				'unsafe_url',
				__( 'A link in this description uses a URL scheme that is not allowed.', 'senroflux' ),
				array( 'status' => 400 )
			);
		}

		$words = $this->wordCount( $html );
		if ( $words > self::MAX_WORDS ) {
			return new WP_Error(
				'description_too_long',
				sprintf(
					/* translators: 1: word count sent, 2: the maximum allowed. */
					__( 'This description is %1$d words; the maximum is %2$d.', 'senroflux' ),
					$words,
					self::MAX_WORDS
				),
				array( 'status' => 400 )
			);
		}

		return null;
	}

	/**
	 * The first tag name (lower-case, no attributes) outside
	 * {@see self::ALLOWED_TAGS}, opening or closing, or null when every tag
	 * in `$html` is allowed.
	 */
	private function firstDisallowedTag( string $html ): ?string {
		if ( ! preg_match_all( '/<\s*\/?\s*([a-zA-Z][a-zA-Z0-9]*)/', $html, $matches ) ) {
			return null;
		}

		foreach ( $matches[1] as $tag ) {
			$lower = strtolower( $tag );
			if ( ! in_array( $lower, self::ALLOWED_TAGS, true ) ) {
				return $lower;
			}
		}

		return null;
	}

	/**
	 * Whether any `<a href="...">` in `$html` fails
	 * {@see PagesValidator::urlIsSafe()} — reused verbatim, never copied.
	 */
	private function hasUnsafeHref( string $html ): bool {
		if ( ! preg_match_all( '/<a\b[^>]*\bhref\s*=\s*(["\'])(.*?)\1/i', $html, $matches ) ) {
			return false;
		}

		foreach ( $matches[2] as $href ) {
			if ( ! PagesValidator::urlIsSafe( trim( $href ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * A whitespace-delimited word count over the tag-stripped text.
	 */
	private function wordCount( string $html ): int {
		$text = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $html ) : strip_tags( $html ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
		$text = trim( (string) preg_replace( '/\s+/', ' ', $text ) );

		if ( '' === $text ) {
			return 0;
		}

		return count( explode( ' ', $text ) );
	}
}
