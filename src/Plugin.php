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
use Specflux\SenroFlux\Run\GateMode;
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

	/** The one posts-pack instance this request shares (S5). */
	private ?\Specflux\SenroFlux\Packs\Posts\PostsPack $posts_pack = null;

	/** The one site-pack instance this request shares (S7). */
	private ?\Specflux\SenroFlux\Packs\Site\SitePack $site_pack = null;

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
	 * The gate mode a run started right now would resolve to (0.3 S3):
	 * {@see GateMode::AgentSafety} when its gate class is loaded, otherwise
	 * {@see GateMode::BuiltIn}. Static so composition-root-adjacent code that
	 * has no natural instance in hand (a pack's `preflight()`) can ask the
	 * exact question `start()` asks, without duplicating the dependency probe.
	 */
	public static function currentGateMode(): GateMode {
		return self::instance()->available() ? GateMode::AgentSafety : GateMode::BuiltIn;
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
		$posts_pack = $this->posts_pack();
		$site_pack  = $this->site_pack();
		add_filter(
			'senroflux_packs',
			static fn ( array $packs ): array => $packs + array(
				'pages' => $pages_pack,
				'posts' => $posts_pack,
				'site'  => $site_pack,
			),
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
			$this->pages_pack = new \Specflux\SenroFlux\Packs\Pages\PagesPack();
		}

		return $this->pages_pack;
	}

	/**
	 * The request's one posts-pack instance (S5).
	 */
	private function posts_pack(): \Specflux\SenroFlux\Packs\Posts\PostsPack {
		if ( null === $this->posts_pack ) {
			$this->posts_pack = new \Specflux\SenroFlux\Packs\Posts\PostsPack();
		}

		return $this->posts_pack;
	}

	/**
	 * The request's one site-pack instance (S7).
	 */
	private function site_pack(): \Specflux\SenroFlux\Packs\Site\SitePack {
		if ( null === $this->site_pack ) {
			$this->site_pack = new \Specflux\SenroFlux\Packs\Site\SitePack();
		}

		return $this->site_pack;
	}

	/**
	 * Wire runtime seams. Called once from plugins_loaded (priority 5, after
	 * Agent Safety's own priority-0 bootstrap so its classes exist).
	 */
	public function boot(): void {
		// 0.3 S3: Agent Safety is no longer a hard dependency — its absence
		// only decides the gate mode a run starts in (GateMode::BuiltIn), so
		// boot wires the runtime either way. `available()` still reports
		// whether AS is present, for the advisory notice below and for
		// GateMode resolution at run start.
		$this->available = $this->dependency_present();

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
		$posts_pack = $this->posts_pack();
		$site_pack  = $this->site_pack();
		// The AS pack resolves the ability allow-list, which touches the
		// Abilities registry — that must not happen before `init`, so the
		// registration is deferred with the pack captured by value.
		add_action(
			'init',
			static function () use ( $pages_pack, $posts_pack, $site_pack ): void {
				foreach ( array( $pages_pack, $posts_pack, $site_pack ) as $pack ) {
					$as_pack = $pack->agentSafetyPack();
					if ( null === $as_pack ) {
						continue;
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
				}
			},
			5
		);
		\Specflux\SenroFlux\Packs\Content\Abilities::boot();
		\Specflux\SenroFlux\Packs\Content\Media::boot();
		\Specflux\SenroFlux\Packs\Pages\PublishSummary::boot();
		// S7: site navigation + front-page abilities. Navigation::boot() also
		// registers the shared `senroflux-site` ability category.
		\Specflux\SenroFlux\Packs\Site\Navigation::boot();
		\Specflux\SenroFlux\Packs\Site\FrontPage::boot();
		// 0.3 S4: the pages pack's vocabulary/validator plug into the shared
		// content registrar under its own slug — `list-patterns` and the write
		// abilities resolve THIS pair only while a 'pages' run is ticking (see
		// the useRunPack()/forgetRunPack() scoping in tick() below).
		$pages_vocabulary = new \Specflux\SenroFlux\Packs\Pages\Vocabulary();
		\Specflux\SenroFlux\Packs\Content\Abilities::registerSource(
			'pages',
			new \Specflux\SenroFlux\Packs\Pages\Validator( $pages_vocabulary ),
			$pages_vocabulary,
			'edit_pages'
		);
		// S5: same registration for the posts pack, under its own slug.
		$posts_vocabulary = new \Specflux\SenroFlux\Packs\Posts\Vocabulary();
		\Specflux\SenroFlux\Packs\Content\Abilities::registerSource(
			'posts',
			new \Specflux\SenroFlux\Packs\Posts\Validator( $posts_vocabulary ),
			$posts_vocabulary,
			'edit_posts'
		);
		// S7: same registration for the site pack, under its own slug — its
		// Vocabulary/Validator extend the pages pack's with the two
		// homepage-only patterns (page-links, intro).
		$site_vocabulary = new \Specflux\SenroFlux\Packs\Site\Vocabulary();
		\Specflux\SenroFlux\Packs\Content\Abilities::registerSource(
			'site',
			new \Specflux\SenroFlux\Packs\Site\Validator( $site_vocabulary ),
			$site_vocabulary,
			'manage_options'
		);
		// S14: object binding for pre-approval grants. Registered
		// unconditionally and answering FALSE until a tick opens a run context
		// — a missing hook would mean "no grant applies", never "every grant
		// applies", so the harness always speaks for itself.
		\Specflux\SenroFlux\Run\GrantEligibility::boot();
		add_action(
			'init',
			static function () use ( $pages_pack, $posts_pack, $site_pack ): void {
				// Pattern registration rides each pack's vocabulary; failures
				// must never break the site — the Validator refuses unknown
				// markup at write time regardless (fail closed there).
				$pages_pack->registerPatterns();
				$posts_pack->registerPatterns();
				$site_pack->registerPatterns();
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

		// 0.3 S3/S11: advisory only — runs still start and tick without
		// Agent Safety, in GateMode::BuiltIn.
		if ( ! $this->available ) {
			add_action( 'admin_notices', array( $this, 'render_missing_notice' ) );
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
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html__(
				'SenroFlux is running without Agent Safety: every change-making call stops for your approval on the SenroFlux Runs screen instead. Install and activate Agent Safety for governance by another plugin on the site.',
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

		// 0.3 S3: a third-party consumer may not drive a built-in-mode run —
		// its approval park can only be resolved on SenroFlux's own Runs
		// screen, which is the one consumer this refusal exempts.
		if ( GateMode::BuiltIn === self::currentGateMode() && \Specflux\SenroFlux\Admin\RunsScreen::CONSUMER !== $consumer ) {
			return $this->ungoverned_error();
		}

		$user_id      = (int) get_current_user_id();
		$caller_allow = $allow; // Captured BEFORE the pack derives it (S9).

		// S9: pack resolution first — an unknown pack is a 400 before any DB
		// write. A caller-supplied $allow is IGNORED when a pack is given: the
		// pack is the single source of the allow-list (the direct-allow path
		// keeps working with $pack = null).
		$pack_obj       = null;
		$withheld_roles = array();
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

			// 0.3 S6: roles whose declared capability the starting user lacks
			// are WITHHELD — decided once, here, from who is starting the run
			// (never per call, which is the gate's job). Their resolved
			// abilities are dropped from the tool set the same way (S6: "the
			// run's tool set"), never merely hidden by a later filter.
			$withheld_roles = self::withheldRolesFor( $pack_obj, $user_id );
			if ( array() !== $withheld_roles ) {
				$resolved           = $pack_obj->resolveAbilities();
				$withheld_abilities = array();
				foreach ( $withheld_roles as $role ) {
					if ( isset( $resolved[ $role ] ) ) {
						$withheld_abilities[] = $resolved[ $role ];
					}
				}
				$allow = array_values( array_diff( $allow, $withheld_abilities ) );
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

		// S15: capture the two best-effort locales at start so a DIFFERENT
		// admin answering a park never switches them. Resolved BEFORE
		// collecting skills: S5 promotes `harness/content-language` off the
		// pack and onto every run, so the ceiling must be checked against the
		// same locale-rendered body `instructionFor()` will render later.
		$conversation_locale = function_exists( 'get_user_locale' ) ? get_user_locale( $user_id ) : '';
		if ( '' === $conversation_locale && function_exists( 'get_locale' ) ) {
			$conversation_locale = get_locale();
		}
		$content_locale = function_exists( 'get_locale' ) ? get_locale() : '';

		// S8: collect skills WITH the pack's skills and the disable list; the
		// ceiling is a start-time gate — refused, never truncated.
		$skills  = SkillSet::collect( $consumer, $goal, $pack_obj, $skills_disable, $content_locale );
		$ceiling = SkillSet::ceilingError( $skills );
		if ( null !== $ceiling ) {
			return $ceiling;
		}

		// 0.3 S3: resolve the gate mode ONCE, here, and pin it on the run row.
		// It never changes afterwards, even if Agent Safety is later
		// installed/removed while the run is in flight (S3's mismatch check).
		$gate_mode = self::currentGateMode();

		$store  = $this->runner()->store();
		$run_id = $store->createRun(
			$user_id,
			$consumer,
			$goal,
			$allow,
			Budget::sanitize( $budget, null !== $pack_obj ? $pack_obj->defaultBudget() : array() ),
			$pack,
			$conversation_locale,
			$content_locale,
			$gate_mode,
			$withheld_roles
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

		// 0.3 S3: same third-party-consumer refusal as start(), re-checked on
		// every tick — a run's consumer never changes after start(), but a
		// consumer could still poll a run it never started (its own bug, but
		// one that must not resolve a built-in park it cannot answer).
		if ( null !== $run && GateMode::BuiltIn === $run->gateMode && \Specflux\SenroFlux\Admin\RunsScreen::CONSUMER !== $run->consumer ) {
			return $this->ungoverned_error();
		}

		\Specflux\SenroFlux\Packs\Pages\PublishSummary::useRunContext( null !== $run ? $run->goal : null );
		// 0.3 S4: vocabulary-bearing content abilities resolve THIS run's pack
		// for the scope of one tick — never a model-supplied `pack` argument.
		\Specflux\SenroFlux\Packs\Content\Abilities::useRunPack( null !== $run ? $run->pack : null );
		// 0.3 S8: the shared content registrar's stale-write compare reads
		// and updates THIS run's tracker — never a model-supplied run id.
		// Same discipline as useRunPack() above; scoped for one tick only.
		\Specflux\SenroFlux\Packs\Content\Abilities::useRunContext( $run_id, $this->runner()->store() );
		// 0.3 S5: the media registrar's images-budget spend count and
		// attachment cap are both derived from THIS run's row/steps.
		\Specflux\SenroFlux\Packs\Content\Media::useRunContext( $run_id, $this->runner()->store() );
		// 0.3 S7/S8: the site navigation registrar's stale-write compare reads
		// and updates THIS run's tracker — same discipline as Content\Abilities.
		\Specflux\SenroFlux\Packs\Site\Navigation::useRunContext( $run_id, $this->runner()->store() );

		try {
			return $this->runner()->tick( $run_id, $expected_step_count, $resume );
		} finally {
			\Specflux\SenroFlux\Packs\Pages\PublishSummary::forgetRunContext();
			\Specflux\SenroFlux\Packs\Content\Abilities::forgetRunPack();
			\Specflux\SenroFlux\Packs\Content\Abilities::forgetRunContext();
			\Specflux\SenroFlux\Packs\Content\Media::forgetRunContext();
			\Specflux\SenroFlux\Packs\Site\Navigation::forgetRunContext();
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

		// 0.3 S3: the same mismatch check tick() runs, at the top of cancel
		// too — a run whose gate mode no longer matches the environment fails
		// with a partial report (gate_mode_changed) instead of a plain cancel.
		if ( null !== $this->runner()->gateModeMismatch( $run ) ) {
			return $this->get( $run_id );
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
		// S14: and for the same reason, this is where a cancelled run's
		// pre-approval grants are withdrawn.
		$this->runner()->revokeGrants( $run_id );
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
				// 0.3 S3: pinned at start(), rendered once by the run header.
				'gate_mode'           => $run->gateMode->value,
				// 0.3 S6: pinned at start(), rendered once by the run header.
				'withheld_roles'      => $run->withheldRoles,
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
	 * Is the plugin backed by a database handle and the Runner class loaded?
	 *
	 * 0.3 S3: this used to also require {@see available()} (Agent Safety
	 * present) — SenroFlux's hard dependency on Agent Safety is retired.
	 * Agent Safety's absence now only decides the gate mode a run starts in
	 * ({@see GateMode::BuiltIn}), never whether the plugin may run at all.
	 */
	private function ready(): bool {
		global $wpdb;

		return isset( $wpdb ) && class_exists( Runner::class );
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
	 * The `senroflux_ungoverned` (409) error every entry point returns when
	 * this specific request cannot be governed at all — fail closed, never
	 * half-run. 0.3 S3 narrows WHEN this fires to two cases: the plugin isn't
	 * backed by a database/Runner ({@see ready()}), or a third-party consumer
	 * tried to start or tick a built-in-mode run (its approval park can only
	 * be resolved on SenroFlux's own Runs screen).
	 */
	private function ungoverned_error(): WP_Error {
		return new WP_Error(
			'senroflux_ungoverned',
			__( 'SenroFlux cannot govern this run: either it is not fully installed, or a built-in-mode run may only be driven from the SenroFlux Runs screen.', 'senroflux' ),
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
			// ability that carries it — `senroflux/publish-post` is
			// `pages/update-live` or `pages/publish` depending on the args,
			// and only the pack can tell them apart. A direct-allow run has no
			// pack, so its verb stays the ability id (S9).
			static function ( \Specflux\SenroFlux\Run\Run $run, string $ability, array $args ): string {
				$pack = self::pack_for_run( $run );

				return null !== $pack ? $pack->verbFor( $ability, $args ) : $ability;
			},
			// S8: the pack whose skills ride every tick's instruction. The
			// Runner passes it straight to SkillSet without knowing the type —
			// the pack seam stays a composition-root concern.
			static fn ( \Specflux\SenroFlux\Run\Run $run ): ?\Specflux\SenroFlux\Packs\Pack => self::pack_for_run( $run ),
			// S12: which argument carries an object's id is the pack's
			// knowledge; the harness only knows the id is opaque.
			static function ( \Specflux\SenroFlux\Run\Run $run, string $verb ): string {
				$pack = self::pack_for_run( $run );

				return null !== $pack ? $pack->objectIdKey( $verb ) : 'id';
			},
			new \Specflux\SenroFlux\Approval\GrantBridge(),
			// S14: a grant is issued against the verb AGENT SAFETY sees — the
			// resolved ability id — never the pack verb a plan step names.
			// Only the pack can map one to the other; a direct-allow run has
			// no pack and its verbs already are ability ids.
			static function ( \Specflux\SenroFlux\Run\Run $run, string $pack_verb ): ?string {
				$pack = self::pack_for_run( $run );

				return null !== $pack ? $pack->gateVerbFor( $pack_verb ) : $pack_verb;
			},
			// 0.3 S3: the environment's CURRENT gate mode, asked fresh at the
			// top of every tick/cancel/park resolution and compared with the
			// one pinned on the run at start() -- a mismatch fails the run
			// (gate_mode_changed) instead of silently switching enforcement.
			static fn (): GateMode => self::currentGateMode(),
			// S6: withheld roles' RESOLVED abilities, for the execution-time
			// defence in depth — only the pack can map a role name back to
			// its ability id.
			static function ( \Specflux\SenroFlux\Run\Run $run ): array {
				$pack = self::pack_for_run( $run );
				if ( null === $pack || array() === $run->withheldRoles ) {
					return array();
				}

				$resolved  = $pack->resolveAbilities();
				$abilities = array();
				foreach ( $run->withheldRoles as $role ) {
					if ( isset( $resolved[ $role ] ) ) {
						$abilities[] = $resolved[ $role ];
					}
				}

				return $abilities;
			}
		);

		return $this->runner;
	}

	/**
	 * 0.3 S6: the role names a pack withholds from a starting user — those
	 * whose declared {@see \Specflux\SenroFlux\Packs\Pack::roleCapabilities()}
	 * capability `$user_id` lacks. A role the pack declares no capability for
	 * is never withheld (the base's empty map means "nothing to check").
	 *
	 * @param \Specflux\SenroFlux\Packs\Pack $pack    The pack a run is starting with.
	 * @param int                            $user_id The starting user.
	 * @return list<string>
	 */
	private static function withheldRolesFor( \Specflux\SenroFlux\Packs\Pack $pack, int $user_id ): array {
		$withheld = array();
		foreach ( $pack->roleCapabilities() as $role => $capability ) {
			if ( '' === $capability ) {
				continue;
			}
			if ( ! function_exists( 'user_can' ) || ! user_can( $user_id, $capability ) ) {
				$withheld[] = $role;
			}
		}

		return $withheld;
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
