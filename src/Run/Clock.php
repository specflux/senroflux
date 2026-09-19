<?php
/**
 * An injectable seam over "now" (0.3 S9).
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Run;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * The harness's one place that asks "what time is it": everything that needs
 * to compare against wall-clock time (the S9 elapsed-gap sentence, the AS
 * mode grant-expiry wording) goes through here instead of calling `time()`
 * directly, so tests can move the clock without a real sleep. Defaults to the
 * real current time; {@see self::useFixed()} pins it for a test, and
 * {@see self::reset()} restores the default.
 */
final class Clock {

	/** The fixed timestamp a test has pinned, or null for the real clock. */
	private static ?int $fixed = null;

	/** The current time, as a Unix timestamp (UTC). */
	public static function now(): int {
		return null !== self::$fixed ? self::$fixed : time();
	}

	/**
	 * Pin the clock to an exact timestamp (test-only).
	 *
	 * @param int $timestamp Unix timestamp (UTC) to report from {@see now()}.
	 */
	public static function useFixed( int $timestamp ): void {
		self::$fixed = $timestamp;
	}

	/** Restore the real clock (test-only; call from tearDown()). */
	public static function reset(): void {
		self::$fixed = null;
	}
}
