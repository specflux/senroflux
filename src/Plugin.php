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
use Specflux\SenroFlux\Skills\Skill;
use Specflux\SenroFlux\Skills\SkillSet;
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

	/** The one pages-pack instance this request shares. */
	private ?\Specflux\SenroFlux\Packs\Pages\PagesPack $pages_pack = null;

	/** Whether {@see govern()} has already wired its filters this request. */
	private bool $governed = false;

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
	 * Register the packs and hand their governance data to Agent Safety.
	 *
	 * Split out of {@see boot()} and run EARLIER for one reason: Agent Safety
	 * reads `agent_safety_governed_namespaces` and `agent_safety_verb_map`
	 * inside its own `plugins_loaded` priority-0 bootstrap, while SenroFlux
	 * boots at priority 5. A pack registered in `boot()` would therefore be
	 * ungoverned — the gate would return early for every `senroflux/*` ability
	 * and no write would produce a verdict, an approval park or an audit row.
	 * senroflux.php calls this on `plugins_loaded` priority -1.
	 *
	 * Idempotent, and deliberately NOT gated on the Agent Safety dependency
	 * check: with Agent Safety absent both filters are inert, and `start()`
	 * still refuses every run as `senroflux_ungoverned`.
	 */
	public function govern(): void {
		if ( $this->governed ) {
			return;
		}
		$this->governed = true;

		$pages_pack = $this->pages_pack();
		add_filter(
			'senroflux_packs',
			static fn ( array $packs ): array => $packs + array( 'pages' => $pages_pack ),
			10,
			1
		);

		\Specflux\SenroFlux\Packs\PackRegistry::contributeToAgentSafety();
	}

	/**
	 * The request's one pages-pack instance (S10/S11).
	 */
	private function pages_pack(): \Specflux\SenroFlux\Packs\Pages\PagesPack {
		if ( null === $this->pages_pack ) {
			$content_locale   = function_exists( 'get_locale' ) ? get_locale() : '';
			$this->pages_pack = new \Specflux\SenroFlux\Packs\Pages\PagesPack( $content_locale );
		}

		return $this->pages_pack;
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

		// Schema v2 (0.2 S4): idempotent dbDelta, stamped by version option.
		global $wpdb;
		if ( isset( $wpdb ) ) {
			Schema::maybe_upgrade( $wpdb );
		}

		// Pages pack (S10/S11): its polyfill abilities, its pattern vocabulary
		// and its approval-summary builder. All wiring lives in this
		// composition root — nothing under src/Run knows packs exist. The pack
		// itself and its Agent Safety governance were registered earlier, by
		// govern(); calling it again here is a no-op that keeps boot() correct
		// on a host that never fired the early hook.
		$this->govern();
		$pages_pack = $this->pages_pack();
		// The AS pack resolves the ability allow-list, which touches the
		// Abilities registry — that must not happen before `init`, so the
		// registration is deferred with the pack captured by value.
		add_action(
			'init',
			static function () use ( $pages_pack ): void {
				$as_pack = $pages_pack->agentSafetyPack();
				if ( null === $as_pack ) {
					return;
				}
				add_filter(
					'agent_safety_pack_registry',
					static function ( $registry ) use ( $as_pack ) {
						if ( is_object( $registry ) && method_exists( $registry, 'register' ) ) {
							$registry->register( $as_pack );
						}

						return $registry;
					},
					10,
					1
				);
			},
			5
		);
		\Specflux\SenroFlux\Packs\Pages\Abilities::boot();
		\Specflux\SenroFlux\Packs\Pages\PublishSummary::boot();
		add_action(
			'init',
			static function () use ( $pages_pack ): void {
				// Pattern registration rides the pack's vocabulary; failures
				// must never break the site — the Validator refuses unknown
				// markup at write time regardless (fail closed there).
				$pages_pack->registerPatterns();
			},
			20
		);

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
	 * @param string            $consumer       Consumer identifier (e.g. 'specflux-mac').
	 * @param string            $goal           Goal text (becomes the first user step).
	 * @param list<string>      $allow          Ability allow-list: exact ids or globs.
	 *                                          IGNORED when $pack is given (S9).
	 * @param array<string,int> $budget         Optional per-run overrides.
	 * @param string|null       $pack           Pack name (S9); derives the allow-list.
	 * @param list<string>|null $skills_disable Non-required skill ids to drop (S8).
	 * @return array<string,mixed>|WP_Error RunState or senroflux_ungoverned /
	 *                                      senroflux_bad_request / pack_unknown /
	 *                                      pack_unbound / skills_too_large.
	 */
	public function start(
		string $consumer,
		string $goal,
		array $allow = array(),
		array $budget = array(),
		?string $pack = null,
		?array $skills_disable = null
	): array|WP_Error {
		if ( ! $this->ready() ) {
			return $this->ungoverned_error();
		}

		$user_id      = (int) get_current_user_id();
		$caller_allow = $allow; // Captured BEFORE the pack derives it (S9).

		// S9: pack resolution first — an unknown pack is a 400 before any DB
		// write. A caller-supplied $allow is IGNORED when a pack is given: the
		// pack is the single source of the allow-list (the direct-allow path
		// keeps working with $pack = null).
		$pack_obj = null;
		if ( null !== $pack ) {
			$pack_obj = $this->packRegistry()->get( $pack );
			if ( null === $pack_obj ) {
				return new WP_Error(
					'pack_unknown',
					__( 'Unknown pack.', 'senroflux' ),
					array( 'status' => 400 )
				);
			}
			$allow = $pack_obj->allowList();

			// S13: preflight — skills ceiling plus Capability-Packs binding
			// (the binding check itself is completed with the pages pack).
			$preflight = $pack_obj->preflight( $user_id, $consumer, $goal, $skills_disable );
			if ( is_wp_error( $preflight ) ) {
				// Refused: skills_too_large (400) or pack_unbound (400).
				return $preflight;
			}
		}

		// The "non-empty allow" guard applies only to the DIRECT path: with a
		// pack the allow is derived, so it is never empty here.
		if ( '' === trim( $consumer ) || '' === trim( $goal ) || ( array() === $allow && null === $pack_obj ) ) {
			return new WP_Error(
				'senroflux_bad_request',
				__( 'A run needs a consumer, a goal, and a non-empty allow-list.', 'senroflux' ),
				array( 'status' => 400 )
			);
		}

		// S8: collect skills WITH the pack's skills and the disable list; the
		// ceiling is a start-time gate — refused, never truncated.
		$skills  = SkillSet::collect( $consumer, $goal, $pack_obj, $skills_disable );
		$ceiling = SkillSet::ceilingError( $skills );
		if ( null !== $ceiling ) {
			return $ceiling;
		}

		// S15: capture the two best-effort locales at start so a DIFFERENT
		// admin answering a park never switches them.
		$conversation_locale = function_exists( 'get_user_locale' ) ? get_user_locale( $user_id ) : '';
		if ( '' === $conversation_locale && function_exists( 'get_locale' ) ) {
			$conversation_locale = get_locale();
		}
		$content_locale = function_exists( 'get_locale' ) ? get_locale() : '';

		$store  = $this->runner()->store();
		$run_id = $store->createRun(
			$user_id,
			$consumer,
			$goal,
			$allow,
			Budget::sanitize( $budget ),
			$pack,
			$conversation_locale,
			$content_locale
		);

		// S9: when a pack drove the allow-list, record that a caller-supplied
		// $allow was ignored (a seq-1 system note; the first real step lands
		// after it).
		if ( null !== $pack_obj && array() !== $caller_allow ) {
			$store->appendSystemNote(
				$run_id,
				array(
					'note'          => 'allow_from_pack',
					'pack'          => $pack,
					'ignored_allow' => array_values( $caller_allow ),
				)
			);
		}

		// S8: snapshot the skill set at start (skills_json) — the audit trail
		// of what the instruction was assembled from.
		$store->updateRun(
			$run_id,
			array(
				// S8: the disable list rides the RUN, not just the start call —
				// every tick re-collects the same set the ceiling was checked
				// against, so a dropped skill stays dropped.
				'skills_disable_json' => null !== $skills_disable ? array_values( array_filter( $skills_disable, 'is_string' ) ) : null,
				'skills_json'         => array_map(
					static fn ( Skill $skill ): array => array(
						'id'      => $skill->id,
						'sha256'  => hash( 'sha256', $skill->body ),
						'source'  => $skill->source->value,
						'version' => $skill->version,
					),
					$skills
				),
			)
		);

		return $this->get( $run_id );
	}

	/**
	 * The request-scoped pack registry (S9). Boots from the `senroflux_packs`
	 * filter; the pages pack registers through it (stage 8).
	 */
	private function packRegistry(): \Specflux\SenroFlux\Packs\PackRegistry {
		return \Specflux\SenroFlux\Packs\PackRegistry::fromFilters();
	}

	/**
	 * Advance one run by at most one model turn.
	 *
	 * @param int                $run_id              Run id.
	 * @param int                $expected_step_count Caller's last-known step_count.
	 * @param array<string,mixed>|null $resume Park resolution (S5): shape must
	 *                                          match the run's park kind, else
	 *                                          `resume_mismatch`. The 0.1
	 *                                          `?string $approval_action`
	 *                                          parameter is REMOVED (breaking,
	 *                                          S5) — a string here is a type
	 *                                          error by contract.
	 * @return array<string,mixed>|WP_Error RunState or a protocol error.
	 */
	public function tick( int $run_id, int $expected_step_count, ?array $resume = null ): array|WP_Error {
		if ( ! $this->ready() ) {
			return $this->ungoverned_error();
		}

		// S10: a Tier-2 call parked inside this tick renders an approval row
		// naming the run that drafted the page. That provenance is read from
		// the RUN ROW here, never from the tool call's arguments — and the
		// scope is one tick, because several ticks share one PHP process under
		// PHPUnit, WP-CLI and cron.
		$run = $this->runner()->store()->getRun( $run_id );
		\Specflux\SenroFlux\Packs\Pages\PublishSummary::useRunContext( null !== $run ? $run->goal : null );

		try {
			return $this->runner()->tick( $run_id, $expected_step_count, $resume );
		} finally {
			\Specflux\SenroFlux\Packs\Pages\PublishSummary::forgetRunContext();
		}
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
		if ( ! $this->maySee( $run ) ) {
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

		// S12: cancel is a terminal transition — build + persist a partial
		// report. The Runner never sees the cancel, so call into it directly.
		$this->runner()->report( $run_id );

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
		if ( ! $this->maySee( $run ) ) {
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
				'id'                  => $run->id,
				'user_id'             => $run->userId,
				'consumer'            => $run->consumer,
				'goal'                => $run->goal,
				'status'              => $run->status->value,
				'allow'               => $run->allow,
				'budget'              => $run->budget,
				'step_count'          => $run->stepCount,
				'tokens_in'           => $run->tokensIn,
				'tokens_out'          => $run->tokensOut,
				'error'               => $run->error,
				// 0.2 S4/S17: the pack name and the two captured locales ride
				// on every read; remaining/skills/report land with the
				// features that fill them (S8, S12, S17).
				'pack'                => $run->pack,
				'conversation_locale' => $run->conversationLocale,
				'content_locale'      => $run->contentLocale,
				// 0.2 S12: the harness-built report (result_json), surfaced on
				// every read so a terminal run carries its changes list.
				'report'              => $run->result,
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
	 * May the current user SEE (and cancel) this run? S13: the run's owner,
	 * or a holder of the Runs-screen capability — the screen must render and
	 * act on delegated runs without impersonating the owner.
	 */
	private function maySee( \Specflux\SenroFlux\Run\Run $run ): bool {
		if ( (int) get_current_user_id() === $run->userId ) {
			return true;
		}

		$capability = apply_filters( 'senroflux_runs_capability', 'manage_options' );

		return function_exists( 'current_user_can' ) && current_user_can( (string) $capability );
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
			new ApprovalBridge(),
			null,
			// S9: a run started with a pack is fenced/annotated by the PACK's
			// verb map; direct-allow runs keep the site-wide filter seam. The
			// composition root is the one place that may reference Packs.
			static function ( \Specflux\SenroFlux\Run\Run $run ): ?array {
				$pack = self::pack_for_run( $run );

				return null !== $pack ? $pack->verbMap() : null;
			},
			// S7/S10: the fence tiers a call by its PACK VERB, not by the
			// ability that carries it — `senroflux/update-post` is
			// `pages/update-draft` or `pages/publish` depending on the args,
			// and only the pack can tell them apart. A direct-allow run has no
			// pack, so its verb stays the ability id (S9).
			static function ( \Specflux\SenroFlux\Run\Run $run, string $ability, array $args ): string {
				$pack = self::pack_for_run( $run );

				return null !== $pack ? $pack->verbFor( $ability, $args ) : $ability;
			},
			// S8: the pack whose skills ride every tick's instruction. The
			// Runner passes it straight to SkillSet without knowing the type —
			// the pack seam stays a composition-root concern.
			static fn ( \Specflux\SenroFlux\Run\Run $run ): ?\Specflux\SenroFlux\Packs\Pack => self::pack_for_run( $run )
		);

		return $this->runner;
	}

	/**
	 * The pack a run was started with, or null for a direct-allow run (and for
	 * a pack name no longer registered — fail closed to the ability-name verb
	 * space, where nothing is mapped and the fence treats every call as tier 2).
	 *
	 * @param \Specflux\SenroFlux\Run\Run $run The run.
	 */
	private static function pack_for_run( \Specflux\SenroFlux\Run\Run $run ): ?\Specflux\SenroFlux\Packs\Pack {
		if ( null === $run->pack || '' === $run->pack ) {
			return null;
		}

		return \Specflux\SenroFlux\Packs\PackRegistry::fromFilters()->get( $run->pack );
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
