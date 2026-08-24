<?php
/**
 * Plugin bootstrap, dependency gate, service container + PHP API.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux;

use Specflux\SenroFlux\Approval\ApprovalBridge;
use Specflux\SenroFlux\Http\Ajax;
use Specflux\SenroFlux\Http\Rest;
use Specflux\SenroFlux\Model\AiClientGateway;
use Specflux\SenroFlux\Model\ModelGatewayInterface;
use Specflux\SenroFlux\Run\Budget;
use Specflux\SenroFlux\Run\Runner;
use Specflux\SenroFlux\Run\RunStatus;
use Specflux\SenroFlux\Run\WpdbRunStore;
use Specflux\SenroFlux\Tools\ToolExecutor;
use WP_Error;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * The one plugin instance. Owns the Agent Safety dependency check, the lazy
 * service graph, and the PHP API mirroring the HTTP surface (S9).
 */
final class Plugin {

	/**
	 * Marker class for the hard dependency check: Agent Safety's gate hook.
	 *
	 * Checking a PLUGIN-side class means "the whole Agent Safety host is
	 * loaded and its seams are wired", which is the property runs actually
	 * depend on.
	 */
	public const AGENT_SAFETY_MARKER = 'Specflux\AgentSafety\Plugin\Hooks\AbilityPermissionGate';

	/** Singleton instance. */
	private static ?self $instance = null;

	/** Injected dependency probe for tests; null restores the real check. */
	private static ?bool $dependency_probe = null;

	/** Whether the Agent Safety dependency was satisfied at boot time. */
	private ?bool $available = null;

	/** Lazily built runner. */
	private ?Runner $runner = null;

	/**
	 * Get (and lazily create) the plugin instance. Non-nullable by design:
	 * consumers feature-detect the FUNCTION (function_exists('senroflux')),
	 * then ask this instance whether the Agent Safety dependency holds.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/** Reset the singleton (tests only). */
	public static function reset(): void {
		self::$instance         = null;
		self::$dependency_probe = null;
	}

	/**
	 * Test seam: force the dependency check outcome.
	 *
	 * @param bool|null $present Forced outcome, or null to restore reality.
	 */
	public static function set_dependency_probe( ?bool $present ): void {
		self::$dependency_probe = $present;
	}

	/**
	 * Wire runtime seams. Called once from plugins_loaded (priority 5, after
	 * Agent Safety's own priority-0 bootstrap so its classes exist).
	 */
	public function boot(): void {
		if ( ! $this->dependency_present() ) {
			add_action( 'admin_notices', array( $this, 'render_missing_notice' ) );

			return;
		}

		$this->available = true;

		// HTTP surface (S9). Both transports delegate to the PHP API below.
		( new Ajax() )->register();
		add_action( 'rest_api_init', array( new Rest(), 'register' ) );

		// Observation screen (S10).
		if ( function_exists( 'is_admin' ) && is_admin() ) {
			( new \Specflux\SenroFlux\Admin\RunsScreen() )->register();
		}
	}

	/**
	 * Can this plugin start runs? Consumers MUST treat false as "SenroFlux
	 * absent" and keep their existing behaviour.
	 */
	public function available(): bool {
		if ( null === $this->available ) {
			$this->available = $this->dependency_present();
		}

		return $this->available;
	}

	/**
	 * Render the missing-dependency notice.
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

	// ------------------------------------------------------------------
	// PHP API (S9)
	// ------------------------------------------------------------------

	/**
	 * Create a run for the CURRENT user; returns its initial RunState
	 * (status pending). The consumer drives it with tick().
	 *
	 * @param string            $consumer Consumer identifier (e.g. 'specflux-mac').
	 * @param string            $goal     Goal text (becomes the first user step).
	 * @param list<string>      $allow    Ability allow-list: exact ids or globs.
	 * @param array<string,int> $budget   Optional per-run overrides.
	 * @return array<string,mixed>|WP_Error RunState or senroflux_ungoverned /
	 *                                      senroflux_bad_request / senroflux_no_database.
	 */
	public function start( string $consumer, string $goal, array $allow = array(), array $budget = array() ): array|WP_Error {
		if ( ! $this->ready() ) {
			return $this->ungoverned_error();
		}

		if ( '' === trim( $consumer ) || '' === trim( $goal ) || array() === $allow ) {
			return new WP_Error(
				'senroflux_bad_request',
				__( 'A run needs a consumer, a goal, and a non-empty allow-list.', 'senroflux' ),
				array( 'status' => 400 )
			);
		}

		$run_id = $this->runner()->store()->createRun(
			(int) get_current_user_id(),
			$consumer,
			$goal,
			$allow,
			Budget::sanitize( $budget )
		);

		return $this->get( $run_id );
	}

	/**
	 * Advance one run by at most one model turn.
	 *
	 * @param int         $run_id              Run id.
	 * @param int         $expected_step_count Caller's last-known step_count.
	 * @param string|null $approval_action     'approve'|'reject' when parked.
	 * @return array<string,mixed>|WP_Error RunState or a protocol error.
	 */
	public function tick( int $run_id, int $expected_step_count, ?string $approval_action = null ): array|WP_Error {
		if ( ! $this->ready() ) {
			return $this->ungoverned_error();
		}

		return $this->runner()->tick( $run_id, $expected_step_count, $approval_action );
	}

	/**
	 * Cancel an owned, non-terminal run.
	 *
	 * @param int $run_id Run id.
	 * @return array<string,mixed>|WP_Error Fresh RunState or an error.
	 */
	public function cancel( int $run_id ): array|WP_Error {
		if ( ! $this->ready() ) {
			return $this->ungoverned_error();
		}

		$store = $this->runner()->store();
		$run   = $store->getRun( $run_id );
		if ( null === $run ) {
			return new WP_Error( 'senroflux_not_found', __( 'Run not found.', 'senroflux' ), array( 'status' => 404 ) );
		}
		if ( (int) get_current_user_id() !== $run->userId ) {
			return new WP_Error( 'senroflux_forbidden', __( 'This run belongs to another user.', 'senroflux' ), array( 'status' => 403 ) );
		}
		if ( $run->status->isTerminal() ) {
			return $this->get( $run_id ); // Already finished: state unchanged.
		}

		$store->updateRun(
			$run_id,
			array(
				'status'      => RunStatus::Cancelled->value,
				'finished_at' => gmdate( 'Y-m-d H:i:s' ),
			)
		);

		return $this->get( $run_id );
	}

	/**
	 * Read one run's current state (no lock, no model calls).
	 *
	 * @param int $run_id Run id.
	 * @return array<string,mixed>|WP_Error
	 */
	public function get( int $run_id ): array|WP_Error {
		if ( ! $this->ready() ) {
			return $this->ungoverned_error();
		}

		$store = $this->runner()->store();
		$run   = $store->getRun( $run_id );
		if ( null === $run ) {
			return new WP_Error( 'senroflux_not_found', __( 'Run not found.', 'senroflux' ), array( 'status' => 404 ) );
		}
		if ( (int) get_current_user_id() !== $run->userId ) {
			return new WP_Error( 'senroflux_forbidden', __( 'This run belongs to another user.', 'senroflux' ), array( 'status' => 403 ) );
		}

		$steps = array();
		foreach ( $store->getSteps( $run_id ) as $step ) {
			$steps[] = array(
				'seq'         => $step->seq,
				'kind'        => $step->kind->value,
				'message'     => $step->messageArray,
				'tool_name'   => $step->toolName,
				'approval_id' => $step->approvalId,
				'status'      => $step->status,
				'tokens_in'   => $step->tokensIn,
				'tokens_out'  => $step->tokensOut,
				'duration_ms' => $step->durationMs,
			);
		}

		return array(
			'run'   => array(
				'id'         => $run->id,
				'user_id'    => $run->userId,
				'consumer'   => $run->consumer,
				'goal'       => $run->goal,
				'status'     => $run->status->value,
				'allow'      => $run->allow,
				'budget'     => $run->budget,
				'step_count' => $run->stepCount,
				'tokens_in'  => $run->tokensIn,
				'tokens_out' => $run->tokensOut,
				'error'      => $run->error,
			),
			'steps' => $steps,
			'ui'    => array(),
		);
	}

	/**
	 * Most recent runs for the Runs screen list.
	 *
	 * @param int $limit Max rows.
	 * @return list<array<string,mixed>> Lightweight run summaries.
	 */
	public function listRecent( int $limit = 50 ): array {
		if ( ! $this->ready() ) {
			return array();
		}

		return array_map(
			static fn ( \Specflux\SenroFlux\Run\Run $run ): array => array(
				'id'         => $run->id,
				'user_id'    => $run->userId,
				'consumer'   => $run->consumer,
				'goal'       => $run->goal,
				'status'     => $run->status->value,
				'step_count' => $run->stepCount,
				'tokens_in'  => $run->tokensIn,
				'tokens_out' => $run->tokensOut,
				'updated_at' => $run->updatedAtUtc,
			),
			$this->runner()->store()->listRecent( $limit )
		);
	}

	// ------------------------------------------------------------------
	// Internals
	// ------------------------------------------------------------------

	/**
	 * Is the plugin both available AND backed by a database handle?
	 */
	private function ready(): bool {
		global $wpdb;

		return $this->available() && isset( $wpdb ) && class_exists( Runner::class );
	}

	/**
	 * The one ungoverned error every entry point returns while the hard
	 * dependency is missing — fail closed, never half-run.
	 */
	private function ungoverned_error(): WP_Error {
		return new WP_Error(
			'senroflux_ungoverned',
			__( 'SenroFlux requires the Agent Safety plugin to be active before any run can start.', 'senroflux' ),
			array( 'status' => 409 )
		);
	}

	/**
	 * Build the runner graph lazily (per request). The gateway is filterable
	 * so hosts/tests can swap the model seam wholesale.
	 */
	private function runner(): Runner {
		if ( null !== $this->runner ) {
			return $this->runner;
		}

		global $wpdb;

		$gateway_class = apply_filters( 'senroflux_model_gateway', AiClientGateway::class );
		if ( ! is_string( $gateway_class ) || ! class_exists( $gateway_class ) ) {
			$gateway_class = AiClientGateway::class;
		}

		/** @var ModelGatewayInterface $gateway */
		$gateway = new $gateway_class();

		$this->runner = new Runner(
			new WpdbRunStore( $wpdb ),
			new ToolExecutor(),
			$gateway,
			new ApprovalBridge()
		);

		return $this->runner;
	}

	/**
	 * Does Agent Safety's gate class exist right now? Overridable in tests
	 * because PHP can never undefine a real class mid-process.
	 */
	private function dependency_present(): bool {
		if ( null !== self::$dependency_probe ) {
			return self::$dependency_probe;
		}

		return class_exists( self::AGENT_SAFETY_MARKER );
	}
}
