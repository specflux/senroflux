<?php
/**
 * Plugin bootstrap and service container.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * The one plugin instance. Owns the dependency check against Agent Safety and,
 * in later stages, the run store / runner / tool registry services.
 */
final class Plugin {

	/**
	 * Marker class for the hard dependency check: Agent Safety's gate hook.
	 *
	 * Checking a PLUGIN-side class (not the core lib) means "the whole Agent
	 * Safety host is loaded and its seams are wired", which is the property
	 * runs actually depend on.
	 */
	public const AGENT_SAFETY_MARKER = 'Specflux\\AgentSafety\\Plugin\\Hooks\\AbilityPermissionGate';

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Whether the Agent Safety dependency was satisfied at boot time.
	 *
	 * @var bool|null
	 */
	private ?bool $available = null;

	/**
	 * Get (and lazily create) the plugin instance. Non-nullable by design:
	 * consumers feature-detect the FUNCTION (function_exists('senroflux')),
	 * then ask this instance whether the Agent Safety dependency holds.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Reset the singleton (tests only).
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$instance = null;
		self::set_dependency_probe( null );
	}

	/**
	 * Wire runtime seams. Called once from plugins_loaded (priority 5, after
	 * Agent Safety's own priority-0 bootstrap so its classes exist).
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( ! $this->dependency_present() ) {
			$this->register_missing_notice();
			return;
		}

		$this->available = true;

		// Later stages wire the run store, runner, HTTP surface and admin
		// screen here. Stage 2 ships the dependency gate only: an ungoverned
		// agent harness must never half-start.
	}

	/**
	 * Can this plugin run? False until boot() found Agent Safety active.
	 *
	 * Consumers MUST treat false as "SenroFlux absent" and keep their existing
	 * single-round behaviour (S2): every start() would otherwise return
	 * senroflux_ungoverned.
	 *
	 * @return bool
	 */
	public function available(): bool {
		if ( null === $this->available ) {
			$this->available = $this->dependency_present();
		}

		return $this->available;
	}

	/**
	 * Does Agent Safety's gate class exist right now?
	 *
	 * Overridable in tests via {@see set_dependency_probe()} because PHP can
	 * never undefine a real class mid-process.
	 *
	 * @return bool
	 */
	private function dependency_present(): bool {
		$probe = self::$dependency_probe;
		if ( null !== $probe ) {
			return $probe;
		}

		return class_exists( self::AGENT_SAFETY_MARKER );
	}

	/**
	 * Injected probe result for tests; null restores the real class_exists.
	 *
	 * @var bool|null
	 */
	private static ?bool $dependency_probe = null;

	/**
	 * Test seam: force the dependency check outcome.
	 *
	 * @param bool|null $present Forced outcome, or null to restore reality.
	 * @return void
	 */
	public static function set_dependency_probe( ?bool $present ): void {
		self::$dependency_probe = $present;
	}

	/**
	 * Register the admin notice shown while the dependency is missing.
	 *
	 * @return void
	 */
	private function register_missing_notice(): void {
		add_action( 'admin_notices', array( $this, 'render_missing_notice' ) );
	}

	/**
	 * Render the missing-dependency notice.
	 *
	 * @return void
	 */
	public function render_missing_notice(): void {
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__(
				'SenroFlux is inactive: it requires the Agent Safety plugin to govern every tool call. Install and activate Agent Safety, then reactivate SenroFlux.',
				'senroflux'
			)
		);
	}
}
