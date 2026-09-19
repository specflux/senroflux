<?php
/**
 * The ONE setup-check evaluator (0.3 S11).
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Setup;

use Specflux\SenroFlux\Packs\Pack;
use Specflux\SenroFlux\Plugin;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Evaluates the harness's own setup checks and, given a pack, folds in the
 * pack's contributed checks (`Pack::setupChecks()`).
 *
 * THE SAME evaluator backs BOTH the setup panel and `Pack::preflight()`
 * (called from {@see \Specflux\SenroFlux\Packs\Pack::preflight()}) — there is
 * exactly one rule set. The user-meta dismissal for the advisory Agent Safety
 * check is decided here too, so the panel and any other caller agree on it.
 */
final class Checks {

	/** User-meta key: 1 once dismissed, per user, never re-armed (S11). */
	public const AGENT_SAFETY_DISMISSED_META = 'senroflux_agent_safety_check_dismissed';

	/** Test seam: force the provider-configured probe. Null restores reality. */
	private static ?bool $provider_probe = null;

	/** Test seam: force the provider probe outcome (tests only). */
	public static function setProviderProbe( ?bool $configured ): void {
		self::$provider_probe = $configured;
	}

	/**
	 * The harness's own checks, in render order: the provider check, then (for
	 * a viewer who hasn't dismissed it) the Agent Safety advisory.
	 *
	 * @param int $user_id The viewer.
	 * @return list<SetupCheck>
	 */
	public static function harnessChecks( int $user_id ): array {
		$checks   = array();
		$checks[] = self::providerCheck();

		$agent_safety = self::agentSafetyAdvisory( $user_id );
		if ( null !== $agent_safety ) {
			$checks[] = $agent_safety;
		}

		return $checks;
	}

	/**
	 * Every check relevant to starting `$pack`: the harness checks plus the
	 * pack's own ({@see Pack::setupChecks()}).
	 *
	 * @param Pack $pack    The chosen pack.
	 * @param int  $user_id The viewer/would-be starter.
	 * @return list<SetupCheck>
	 */
	public static function forPack( Pack $pack, int $user_id ): array {
		return array_merge( self::harnessChecks( $user_id ), $pack->setupChecks( $user_id ) );
	}

	/**
	 * The first BLOCKING failing check, in order, or null when every blocking
	 * check passes. Advisory checks never block (S11).
	 *
	 * @param list<SetupCheck> $checks Checks to scan, in order.
	 */
	public static function firstBlockingFailure( array $checks ): ?SetupCheck {
		foreach ( $checks as $check ) {
			if ( $check->isBlocking() && ! $check->passed() ) {
				return $check;
			}
		}

		return null;
	}

	/**
	 * `senroflux/provider` (blocking, harness): passes when the AI Client
	 * reports a configured provider.
	 *
	 * Below WordPress 7.0 the AI Client classes are absent entirely, so the
	 * same id carries "needs WordPress 7.0 or newer" instead (S11).
	 */
	public static function providerCheck(): SetupCheck {
		if ( ! self::wordPressSupportsAi() ) {
			return new SetupCheck(
				'senroflux/provider',
				SetupCheck::BLOCKING,
				false,
				__( 'SenroFlux needs WordPress 7.0 or newer.', 'senroflux' ),
				function_exists( 'admin_url' ) ? admin_url( 'update-core.php' ) : null,
				'update_core',
				__( 'Ask an administrator to update WordPress to 7.0 or newer.', 'senroflux' ),
				'senroflux_wordpress_outdated'
			);
		}

		return new SetupCheck(
			'senroflux/provider',
			SetupCheck::BLOCKING,
			self::providerConfigured(),
			__( 'Configure a model provider in Settings → Connectors.', 'senroflux' ),
			function_exists( 'admin_url' ) ? admin_url( 'options-connectors.php' ) : null,
			'manage_options',
			__( 'Ask an administrator to configure a model provider in Settings → Connectors.', 'senroflux' ),
			'senroflux_provider_unconfigured'
		);
	}

	/**
	 * Whether the AI Client is present at all — the WordPress-version proxy
	 * this check uses (S11: "below WordPress 7.0").
	 */
	private static function wordPressSupportsAi(): bool {
		return class_exists( \WordPress\AiClient\AiClient::class )
			&& class_exists( \WordPress\AiClient\Providers\ProviderRegistry::class );
	}

	/**
	 * Whether the AI Client's default registry reports at least one
	 * configured provider.
	 *
	 * `wp_supports_ai()` (core `wp-includes/ai-client.php`) only answers
	 * whether AI features are switched on for the request at all — it
	 * defaults to `true` and says nothing about whether any provider has
	 * credentials (it is a kill-switch filter, not a configuration probe).
	 * `ProviderRegistry::isProviderConfigured( $id )` answers the real
	 * question, but only for ONE named provider, so "a provider is
	 * configured" is answered by checking every registered id.
	 */
	private static function providerConfigured(): bool {
		if ( null !== self::$provider_probe ) {
			return self::$provider_probe;
		}

		if ( ! self::wordPressSupportsAi() ) {
			return false;
		}

		$registry = \WordPress\AiClient\AiClient::defaultRegistry();
		foreach ( $registry->getRegisteredProviderIds() as $id ) {
			if ( $registry->isProviderConfigured( $id ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * `senroflux/agent-safety` (advisory, harness): null once Agent Safety is
	 * present, or once this viewer has dismissed it (per-user, never re-armed).
	 *
	 * @param int $user_id The viewer.
	 */
	public static function agentSafetyAdvisory( int $user_id ): ?SetupCheck {
		if ( Plugin::instance()->available() ) {
			return null;
		}

		if ( self::agentSafetyDismissedBy( $user_id ) ) {
			return null;
		}

		return new SetupCheck(
			'senroflux/agent-safety',
			SetupCheck::ADVISORY,
			false,
			__( 'Install Agent Safety for approval and audit governance on every write.', 'senroflux' ),
			function_exists( 'admin_url' )
				? admin_url( 'plugin-install.php?tab=plugin-information&plugin=agent-safety' )
				: null,
			'install_plugins',
			__( 'Ask an administrator to install the Agent Safety plugin.', 'senroflux' ),
			'senroflux_agent_safety_missing'
		);
	}

	/** Has this user dismissed the Agent Safety advisory? Never re-arms. */
	public static function agentSafetyDismissedBy( int $user_id ): bool {
		if ( ! function_exists( 'get_user_meta' ) ) {
			return false;
		}

		return (bool) get_user_meta( $user_id, self::AGENT_SAFETY_DISMISSED_META, true );
	}

	/** Record this user's dismissal of the Agent Safety advisory. */
	public static function dismissAgentSafetyFor( int $user_id ): void {
		if ( function_exists( 'update_user_meta' ) ) {
			update_user_meta( $user_id, self::AGENT_SAFETY_DISMISSED_META, 1 );
		}
	}
}
