<?php
/**
 * The site owner's standing notes (0.3 S20).
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Run;

use WP_Error;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Owns the `senroflux_site_brief` option: plain text, autoload off, capped
 * at {@see self::MAX_CHARS} characters (`mb_strlen`). The harness treats the
 * brief as opaque text — it never inspects or rewrites its content, only
 * bounds its length. Refused writes NEVER truncate: the caller keeps the
 * text it tried to save.
 */
final class SiteBrief {

	/** The option name (autoload off — read only when a run is instructed). */
	public const OPTION = 'senroflux_site_brief';

	/** S20: over 2,000 characters (`mb_strlen`) is refused, never truncated. */
	public const MAX_CHARS = 2000;

	/** S20 refusal code. */
	public const ERROR_TOO_LONG = 'brief_too_long';

	/** The current brief text, or '' when unset/WP is absent. */
	public static function get(): string {
		if ( ! function_exists( 'get_option' ) ) {
			return '';
		}

		$value = get_option( self::OPTION, '' );

		return is_string( $value ) ? $value : '';
	}

	/**
	 * Validate a candidate brief text against the length cap.
	 *
	 * @return true|WP_Error True when valid; a `brief_too_long` WP_Error otherwise.
	 */
	public static function validate( string $text ): true|WP_Error {
		if ( mb_strlen( $text ) > self::MAX_CHARS ) {
			return new WP_Error(
				self::ERROR_TOO_LONG,
				sprintf(
					/* translators: %d is the character cap. */
					__( 'The site brief may be at most %d characters.', 'senroflux' ),
					self::MAX_CHARS
				),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * Save the brief. Refuses (without writing) when the text is too long;
	 * the caller must keep the text in its own form — this never truncates.
	 *
	 * @return true|WP_Error
	 */
	public static function set( string $text ): true|WP_Error {
		$valid = self::validate( $text );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		if ( function_exists( 'update_option' ) ) {
			// Autoload off (S20): the brief is read only when a run needs it,
			// never on every page load.
			update_option( self::OPTION, $text, false );
		}

		return true;
	}

	/**
	 * Append one line to `$current` under the same cap. Never truncates: a
	 * result over the cap is refused and `$current` is returned unchanged by
	 * the caller (this method only computes the candidate).
	 *
	 * @return string The candidate brief (caller must still {@see validate()} it).
	 */
	public static function withAppendedLine( string $current, string $line ): string {
		$trimmed = trim( $current );

		return '' === $trimmed ? $line : $current . "\n" . $line;
	}

	/**
	 * The brief's fingerprint for the seq-0 system step, next to the skills
	 * hash (S20). Null for an empty brief, so an unset brief leaves no trace.
	 */
	public static function hash( string $text ): ?string {
		return '' === $text ? null : hash( 'sha256', $text );
	}
}
