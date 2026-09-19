<?php
/**
 * `save-store-report` markup validator (S19, D6, stage 13).
 *
 * TARGET REPO PATH: src/Packs/Commerce/ReportMarkupValidator.php
 *
 * The report's markup is HARNESS-BUILT (a table SenroFlux itself renders
 * from `store-report`'s output) but `save-store-report`'s `markup` argument
 * is still model-authored text the model may edit before saving, so it is
 * validated the same way {@see DescriptionValidator} validates a product
 * description: any tag outside the allowed set REFUSES the call whole,
 * never stripped (S19: "refuses ... never strip").
 *
 * Allowed tags (S19): `p`, `table`, `thead`, `tbody`, `tr`, `th`, `td`,
 * `h2`, `ul`, `li`. Deliberately NO `a` — a store report never needs a link,
 * so there is no href-safety rule to apply here (unlike
 * {@see DescriptionValidator}, which allows `a`).
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Packs\Commerce;

use WP_Error;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Validates one report `markup` string.
 */
final class ReportMarkupValidator {

	/** The allowed tag names, lower-case, no namespace prefix. */
	private const ALLOWED_TAGS = array( 'p', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'h2', 'ul', 'li' );

	/**
	 * Validate one markup string. Null = accepted; a WP_Error names the
	 * first disallowed tag found.
	 */
	public function validate( string $html ): ?WP_Error {
		$tag = $this->firstDisallowedTag( $html );
		if ( null !== $tag ) {
			return new WP_Error(
				'disallowed_tag',
				sprintf(
					/* translators: %s: the disallowed HTML tag name. */
					__( 'A store report may not use <%s>. Allowed tags: p, table, thead, tbody, tr, th, td, h2, ul, li.', 'senroflux' ),
					$tag
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
}
