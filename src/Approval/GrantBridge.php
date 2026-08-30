<?php
/**
 * Bridge to Agent Safety's pre-approval grants API (S14 AS-12).
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Approval;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * S7/S14: the one place SenroFlux talks to `agent_safety()->grants()` and to
 * `RequestContext::withCorrelation()`.
 *
 * Everything here is feature-detected, because AS-12 ships behind a filter and
 * an older Agent Safety has neither service. Absence is never an error: a
 * missing grants service means no grant is ever issued and every Tier-2 call
 * parks for a human, which is the 0.1 behaviour and the fail-closed one.
 *
 * The Agent Safety symbols are referenced BY NAME (class-string constants)
 * rather than imported: they live in a plugin this package does not depend on
 * at composer level, so a `use` statement would be a lie to static analysis and
 * a fatal on a site running an older Agent Safety.
 */
class GrantBridge {

	/**
	 * Agent Safety's per-request context. `withCorrelation()` arrived with
	 * AS-12; an older build has the class but not the method, so both are
	 * probed.
	 */
	private const REQUEST_CONTEXT = 'Specflux\\AgentSafety\\Plugin\\Support\\RequestContext';

	/**
	 * The correlation id one run's grants, approvals and audit rows share.
	 *
	 * Derived from the run row's own id and NOTHING else: Agent Safety matches
	 * grants on this string, which makes it a security key rather than a log
	 * tag (S14). It must never be built from model output or an HTTP parameter.
	 *
	 * @param int $run_id The run.
	 */
	public function correlationFor( int $run_id ): string {
		return 'senroflux:run:' . $run_id;
	}

	/**
	 * Is the grants API reachable at all (Agent Safety active, new enough to
	 * expose `grants()`, and that service actually built)?
	 */
	public function isAvailable(): bool {
		return null !== $this->service();
	}

	/**
	 * Is pre-approval switched on for this site — the API is present AND
	 * `agent_safety_enable_grants` is true? A grant issued while the feature is
	 * off is never written, so asking first keeps the harness from offering a
	 * choice that cannot take effect.
	 */
	public function enabled(): bool {
		$service = $this->service();
		if ( null === $service || ! method_exists( $service, 'enabled' ) ) {
			return false;
		}

		return true === $service->enabled();
	}

	/**
	 * The principal a grant must be issued to: the identity token Agent Safety
	 * will present at the gate for THIS request (`RequestContext::tokenId()`).
	 *
	 * Null when Agent Safety cannot name one — an unauthenticated caller, or a
	 * build without the class. A null subject makes {@see issue()} a no-op,
	 * which is the fail-closed answer: a grant with no principal would be a
	 * grant to anyone (Agent Safety refuses it too).
	 */
	public function subject(): ?string {
		$context = self::REQUEST_CONTEXT;
		if ( ! class_exists( $context ) || ! is_callable( array( $context, 'tokenId' ) ) ) {
			return null;
		}

		$token = call_user_func( array( $context, 'tokenId' ) );

		return ( is_string( $token ) && '' !== $token ) ? $token : null;
	}

	/**
	 * Record a human's pre-approval of up to $count calls of $verb.
	 *
	 * @param string  $verb           The AGENT SAFETY verb (= the resolved ability id).
	 * @param int     $count          How many calls the human authorised.
	 * @param ?string $subject        The principal ({@see subject()}).
	 * @param string  $correlation_id The run scope ({@see correlationFor()}).
	 * @param ?int    $granted_by     The human who accepted the plan.
	 * @param ?string $plan_step_id   The accepted plan step's id.
	 * @return string|null The grant id, or null when nothing was issued.
	 */
	public function issue(
		string $verb,
		int $count,
		?string $subject,
		string $correlation_id,
		?int $granted_by = null,
		?string $plan_step_id = null
	): ?string {
		$service = $this->service();
		if ( null === $service || ! method_exists( $service, 'issue' ) ) {
			return null;
		}

		$granted = $service->issue( $verb, $count, $subject, $correlation_id, $granted_by, $plan_step_id );

		return is_string( $granted ) && '' !== $granted ? $granted : null;
	}

	/**
	 * Withdraw every live grant in one run's scope; returns how many this call
	 * hit. Safe to call on a run that never had any, and safe to call twice.
	 *
	 * @param string $correlation_id The run scope.
	 */
	public function revokeAll( string $correlation_id ): int {
		$service = $this->service();
		if ( null === $service || ! method_exists( $service, 'revokeAll' ) ) {
			return 0;
		}

		return (int) $service->revokeAll( $correlation_id );
	}

	/**
	 * Run the body with Agent Safety's correlation id pinned to $id, restoring
	 * the previous value afterwards (S14).
	 *
	 * Throws — from Agent Safety, before the body is entered — when a different
	 * correlation id has already been memoized in this request. That is a hard
	 * stop, not a notice: continuing would run the tick under another scope's
	 * key. See {@see \Specflux\SenroFlux\Run\Runner::tick()}.
	 *
	 * When the method is absent (Agent Safety older than AS-12) the body
	 * simply runs unscoped. Nothing is loosened by that: grants are matched on the
	 * correlation id, so an unpinned request matches none of this run's.
	 *
	 * @template TReturn
	 * @param string             $id   The correlation id.
	 * @param callable():TReturn $body The tick body.
	 * @return TReturn Whatever the body returned.
	 */
	public function withCorrelation( string $id, callable $body ): mixed {
		$context = self::REQUEST_CONTEXT;
		if ( ! class_exists( $context ) || ! is_callable( array( $context, 'withCorrelation' ) ) ) {
			return $body();
		}

		return call_user_func( array( $context, 'withCorrelation' ), $id, $body );
	}

	/**
	 * `agent_safety()->grants()`, or null when Agent Safety is absent, too old
	 * to expose the service, or built without it.
	 */
	private function service(): ?object {
		if ( ! function_exists( 'agent_safety' ) ) {
			return null;
		}

		$container = agent_safety();
		if ( ! is_object( $container ) || ! method_exists( $container, 'grants' ) ) {
			return null;
		}

		$grants = $container->grants();

		return is_object( $grants ) ? $grants : null;
	}
}
