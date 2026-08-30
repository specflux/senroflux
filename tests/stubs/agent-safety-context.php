<?php
/**
 * Test-only stand-in for Agent Safety's per-request context (S14 AS-12).
 *
 * Declared in Agent Safety's OWN namespace on purpose: the harness reaches
 * `RequestContext` by name behind a `class_exists()` probe, so this is the only
 * way to exercise that seam in a checkout where the Agent Safety plugin is not
 * installed. Knobs only — none of the real implementation is reproduced.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\AgentSafety\Plugin\Support;

if ( ! class_exists( CorrelationConflict::class ) ) {
	/**
	 * Agent Safety throws this when a DIFFERENT correlation id has already been
	 * memoized in the request (S14). Same name and namespace as the real one so
	 * the harness is exercised against the shape it will meet in production.
	 */
	class CorrelationConflict extends \RuntimeException {
	}
}

if ( ! class_exists( RequestContext::class ) ) {
	/**
	 * Stands in for Agent Safety's per-request context: the correlation scope
	 * and the identity token a grant binds to.
	 */
	class RequestContext {

		/** @var list<string> Every correlation id a tick scoped itself to, in order. */
		public static array $scoped = array();

		/** Set true to make withCorrelation() throw BEFORE running the body. */
		public static bool $conflict = false;

		/** The token id the gate would present; null = unauthenticated. */
		public static ?string $token = 'user:1';

		/** The id currently in effect ('' = none). */
		public static string $current = '';

		/** Reset every knob between tests. */
		public static function reset(): void {
			self::$scoped   = array();
			self::$conflict = false;
			self::$token    = 'user:1';
			self::$current  = '';
		}

		public static function tokenId(): ?string {
			return self::$token;
		}

		public static function correlation(): string {
			return self::$current;
		}

		/**
		 * Run the body with the correlation id pinned to $id, restoring the previous
		 * value in a `finally` — and refusing outright when a different id is
		 * already in effect.
		 *
		 * @param string   $id Correlation id.
		 * @param callable $body Body.
		 * @return mixed
		 */
		public static function withCorrelation( string $id, callable $body ) {
			if ( '' === $id ) {
				throw new CorrelationConflict( 'An empty correlation id is not a scope.' );
			}
			if ( self::$conflict ) {
				throw new CorrelationConflict( 'A different correlation id is already memoized.' );
			}

			self::$scoped[] = $id;
			$previous       = self::$current;
			self::$current  = $id;

			try {
				return $body();
			} finally {
				self::$current = $previous;
			}
		}
	}
}
