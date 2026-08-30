<?php
/**
 * Test-only stand-in for the Agent Safety plugin's host surface (S14 AS-12).
 *
 * Agent Safety is a separate plugin and is NOT a composer dependency, so the
 * suite has to supply the two symbols SenroFlux feature-detects: the global
 * `agent_safety()` locator and `RequestContext`'s static correlation scope.
 * Both are recording doubles with knobs — none of Agent Safety's own logic is
 * reproduced here, only the shape of its contract:
 *
 *   $GLOBALS['senroflux_test_agent_safety'] — the container (null by default,
 *   which is the "Agent Safety inactive" state every pre-stage-11 test assumes).
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

$GLOBALS['senroflux_test_agent_safety'] = $GLOBALS['senroflux_test_agent_safety'] ?? null;

if ( ! function_exists( 'agent_safety' ) ) {
	/**
	 * The plugin locator Agent Safety exposes. Null = plugin absent.
	 */
	function agent_safety(): ?object {
		return $GLOBALS['senroflux_test_agent_safety'] ?? null;
	}
}

if ( ! class_exists( 'SenroFlux_Test_Grants' ) ) {
	/**
	 * Stands in for `agent_safety()->grants()`: records what the harness asked
	 * for, and refuses everything the real service refuses.
	 */
	class SenroFlux_Test_Grants {

		/** Mirrors the `agent_safety_enable_grants` switch. */
		public bool $enabled = true;

		/** @var list<array<string,mixed>> Every issue() call that was accepted. */
		public array $issued = array();

		/** @var list<string> Every revokeAll() correlation id, in order. */
		public array $revoked = array();

		private int $next_id = 0;

		public function enabled(): bool {
			return $this->enabled;
		}

		/**
		 * Record one grant. Returns null on exactly the inputs the real service
		 * refuses (feature off, non-positive count, empty verb/subject/scope),
		 * so a test can prove the harness never leans on a refused issue.
		 *
		 * @param string  $verb           Agent Safety verb (the ability id).
		 * @param int     $count          Calls authorised.
		 * @param ?string $subject        Identity token the grant binds to.
		 * @param string  $correlation_id Run scope.
		 * @param ?int    $granted_by     The human who accepted.
		 * @param ?string $plan_step_id   Accepted plan step.
		 */
		public function issue(
			string $verb,
			int $count,
			?string $subject,
			string $correlation_id,
			?int $granted_by = null,
			?string $plan_step_id = null
		): ?string {
			if ( ! $this->enabled || '' === $verb || $count < 1 || '' === $correlation_id ) {
				return null;
			}
			if ( null === $subject || '' === $subject ) {
				return null;
			}

			++$this->next_id;
			$grant_id = 'gnt_' . $this->next_id;

			$this->issued[] = array(
				'grant_id'       => $grant_id,
				'verb'           => $verb,
				'count'          => $count,
				'subject'        => $subject,
				'correlation_id' => $correlation_id,
				'granted_by'     => $granted_by,
				'plan_step_id'   => $plan_step_id,
			);

			return $grant_id;
		}

		/**
		 * Withdraw a scope's grants; returns how many this call hit. Works with
		 * the feature off, exactly as the real service does.
		 *
		 * @param string $correlation_id Run scope.
		 */
		public function revokeAll( string $correlation_id ): int {
			$this->revoked[] = $correlation_id;

			$hit = 0;
			foreach ( $this->issued as $grant ) {
				if ( $grant['correlation_id'] === $correlation_id ) {
					++$hit;
				}
			}

			return $hit;
		}

		/**
		 * Every grant issued in one scope.
		 *
		 * @param string $correlation_id Run scope.
		 * @return list<array<string,mixed>>
		 */
		public function forCorrelation( string $correlation_id ): array {
			$out = array();
			foreach ( $this->issued as $grant ) {
				if ( $grant['correlation_id'] === $correlation_id ) {
					$out[] = $grant;
				}
			}

			return $out;
		}
	}
}

if ( ! class_exists( 'SenroFlux_Test_AgentSafety' ) ) {
	/**
	 * Stands in for Agent Safety's container: the two services SenroFlux
	 * feature-detects. `grants` may be null — an Agent Safety built without
	 * AS-12.
	 */
	class SenroFlux_Test_AgentSafety {

		public function __construct( private ?SenroFlux_Test_Grants $grants = null ) {
			$this->grants = $grants ?? new SenroFlux_Test_Grants();
		}

		public function grants(): ?SenroFlux_Test_Grants {
			return $this->grants;
		}

		public function approvals(): ?object {
			return null;
		}
	}
}

if ( ! function_exists( 'senroflux_test_grants' ) ) {
	/**
	 * Switch Agent Safety "on" for a test and return its grants double.
	 *
	 * @param bool $enabled Whether the AS-12 feature switch is on.
	 */
	function senroflux_test_grants( bool $enabled = true ): SenroFlux_Test_Grants {
		$grants                                 = new SenroFlux_Test_Grants();
		$grants->enabled                        = $enabled;
		$GLOBALS['senroflux_test_agent_safety'] = new SenroFlux_Test_AgentSafety( $grants );

		return $grants;
	}
}

if ( ! function_exists( 'senroflux_test_no_agent_safety' ) ) {
	/** Return to the default state: Agent Safety inactive. */
	function senroflux_test_no_agent_safety(): void {
		$GLOBALS['senroflux_test_agent_safety'] = null;
	}
}
