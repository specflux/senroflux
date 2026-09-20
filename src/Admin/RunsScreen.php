<?php
/**
 * Tools → SenroFlux Runs: the observation + standalone-start screen (S10/S13).
 *
 * TARGET REPO PATH: src/Admin/RunsScreen.php
 *
 * 0.2 stage 9. The screen is now the FULL human seam for a run:
 *   - a "New run" form (S13) that starts through the ONE start path
 *     `senroflux()->start( 'senroflux-admin', … )`, gated by the chosen pack's
 *     S13 preflight (a preflight failure replaces the form with a notice
 *     linking to Agent Capability Packs; SenroFlux never auto-binds);
 *   - the run detail, which renders the three park cards INLINE (question /
 *     plan / approval) server-side and submits their resolutions back through
 *     the ONE tick path `senroflux()->tick()` (S5 resume objects);
 *   - the existing list, cancel, and complete-without-JS behaviour.
 *
 * Progressive enhancement: every mutation is a plain admin-post form that works
 * with JS disabled; `assets/runs.js` only polls for new steps, swaps the status
 * badge, and announces status transitions in an aria-live region.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Admin;

use Specflux\SenroFlux\Plugin;
use Specflux\SenroFlux\Approval\GrantBridge;
use Specflux\SenroFlux\Http\ConsumerPolicy;
use Specflux\SenroFlux\Packs\Pack;
use Specflux\SenroFlux\Packs\PackRegistry;
use Specflux\SenroFlux\Run\Budget;
use Specflux\SenroFlux\Run\Report;
use Specflux\SenroFlux\Run\RunStatus;
use Specflux\SenroFlux\Run\StepKind;
use Specflux\SenroFlux\Setup\Checks;
use Specflux\SenroFlux\Setup\SetupCheck;
use Specflux\SenroFlux\Tools\VerbTier;
use WP_Error;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Render + act on SenroFlux runs.
 *
 * The one consumer id the screen starts runs for is `senroflux-admin` (S13),
 * and it goes through the SAME server-side policy seam as every other
 * consumer. `senroflux-admin` is registered in `senroflux_http_consumers`
 * ONLY while the current user holds the screen capability (see
 * {@see self::registerAdminConsumer()}), and the New-run handler resolves the
 * request through {@see ConsumerPolicy} before it ever reaches `start()`. So:
 *
 *   - one start path, one allow-list authority, one budget ceiling;
 *   - a user without the capability resolves to `senroflux_unknown_consumer`
 *     (403) at the policy seam — fail closed — as well as being stopped by the
 *     handler's own `current_user_can()` check;
 *   - a JS consumer POSTing `wp_ajax_senroflux_start` with `senroflux-admin`
 *     gets exactly the same treatment, because it is the same filter entry.
 *
 * The chosen pack still narrows the allow-list inside `start()` (S9: with a
 * pack, the pack is the single source of the allow-list), so the policy
 * allow-list is the OUTER bound, not the effective one.
 */
class RunsScreen {

	private const SLUG = 'senroflux-runs';

	/**
	 * The consumer id label for admin-started runs (S13). Public (0.3 S3): the
	 * only consumer allowed to drive a built-in-mode run — its approval park
	 * can only be resolved on this screen, so a third-party consumer starting
	 * or ticking one is refused (`senroflux_ungoverned`, Plugin::start/tick).
	 */
	public const CONSUMER = 'senroflux-admin';

	/** Goal length cap (S13: required, ≤ 1000 chars). */
	private const MAX_GOAL = 1000;

	/** Transient set on activation, cleared the first time the Plugins-screen notice renders (S10). */
	public const ACTIVATION_NOTICE_TRANSIENT = 'senroflux_activation_notice';

	/** Register on admin_menu (+ posts + assets). */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'redirectOldToolsUrl' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'commandPaletteAssets' ) );
		add_action( 'admin_post_senroflux_cancel_run', array( $this, 'handleCancel' ) );
		add_action( 'admin_post_senroflux_new_run', array( $this, 'handleNewRun' ) );
		add_action( 'admin_post_senroflux_answer', array( $this, 'handleAnswer' ) );
		add_action( 'admin_post_senroflux_plan_decision', array( $this, 'handlePlanDecision' ) );
		add_action( 'admin_post_senroflux_approval_decision', array( $this, 'handleApprovalDecision' ) );
		add_action( 'admin_post_senroflux_suggestion_decision', array( $this, 'handleSuggestionDecision' ) );
		add_action( 'wp_ajax_senroflux_setup_panel', array( $this, 'handleSetupPanel' ) );
		add_action( 'wp_ajax_senroflux_dismiss_agent_safety_check', array( $this, 'handleDismissAgentSafetyCheck' ) );
		add_action( 'admin_notices', array( $this, 'maybeRenderActivationNotice' ) );
		add_filter( ConsumerPolicy::FILTER, array( $this, 'registerAdminConsumer' ) );
	}

	/**
	 * The capability that unlocks the screen; filterable per S10/S13.
	 *
	 * The same capability gates see/start/answer/act/cancel. Writes STILL
	 * require `edit_pages` at the ability (S13) — this only controls who may
	 * drive a run from this screen.
	 */
	public function capability(): string {
		return ScreenCapability::current();
	}

	/**
	 * Register `senroflux-admin` as an HTTP consumer — ONLY for a user who
	 * holds the screen capability (S13).
	 *
	 * This is what makes the Runs screen's start "one start path": the New-run
	 * form and any `wp_ajax_senroflux_start` call resolve through the SAME
	 * {@see ConsumerPolicy} entry, so the allow-list and the budget ceiling are
	 * server-owned in both. A visitor without the capability never sees the
	 * consumer registered at all, so `ConsumerPolicy::resolve()` refuses with
	 * `senroflux_unknown_consumer` (403) — fail closed.
	 *
	 * The allow-list here is the union of the registered packs' allow-lists:
	 * the OUTER bound of what an admin-started run may ever touch. `start()`
	 * then narrows it to the chosen pack (S9), which is always given from this
	 * screen. The ceiling is the site's `Budget::defaults()`, which is exactly
	 * the "lower-only" rule S13 asks for.
	 *
	 * @param mixed $consumers The consumer map so far.
	 * @return array<string,array{allow?:list<string>,budget?:array<string,int>}>
	 */
	public function registerAdminConsumer( mixed $consumers ): array {
		$consumers = is_array( $consumers ) ? $consumers : array();

		if ( ! ScreenCapability::held() ) {
			return $consumers;
		}

		$allow = array();
		foreach ( PackRegistry::fromFilters()->all() as $pack ) {
			foreach ( $pack->allowList() as $ability ) {
				if ( is_string( $ability ) && '' !== $ability ) {
					$allow[ $ability ] = true;
				}
			}
		}

		if ( array() === $allow ) {
			// No pack, nothing to allow: leave the consumer UNregistered rather
			// than registering an empty (and therefore refused) entry.
			return $consumers;
		}

		$consumers[ self::CONSUMER ] = array(
			'allow'  => array_keys( $allow ),
			'budget' => Budget::defaults(),
		);

		return $consumers;
	}

	/**
	 * The top-level "SenroFlux" menu (0.3 S10 — moved out of Tools).
	 *
	 * The capability is computed fresh on every `admin_menu` call
	 * ({@see ScreenCapability::current()}: the first registered pack's run
	 * capability the CURRENT viewer holds, else `do_not_allow`), so a
	 * Subscriber never sees the menu item at all while an editor who can run
	 * one pack does.
	 */
	public function menu(): void {
		add_menu_page(
			__( 'SenroFlux', 'senroflux' ),
			__( 'SenroFlux', 'senroflux' ),
			$this->capability(),
			self::SLUG,
			array( $this, 'render' ),
			'dashicons-format-chat'
		);
	}

	/**
	 * 0.3 S10 `[assumed]`: the old Tools submenu URL
	 * (`tools.php?page=senroflux-runs`) redirects to the new top-level one,
	 * preserving `run_id`/`senroflux_filter` so a bookmarked review link keeps
	 * working.
	 */
	public function redirectOldToolsUrl(): void {
		global $pagenow;

		if ( 'tools.php' !== $pagenow ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only redirect, no state change.
		$page = sanitize_key( wp_unslash( $_GET['page'] ?? '' ) );
		if ( self::SLUG !== $page ) {
			return;
		}

		$query = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only redirect.
		unset( $query['page'] );
		$query = array_map( 'sanitize_text_field', wp_unslash( $query ) );
		$query = array( 'page' => self::SLUG ) + $query;

		$this->redirectAndExit( add_query_arg( $query, admin_url( 'admin.php' ) ) );
	}

	/**
	 * The one place every redirect-then-stop goes through — a test subclass
	 * overrides THIS, not `wp_safe_redirect()` + `exit` directly, so a test
	 * can observe the destination without killing the PHPUnit process.
	 */
	protected function redirectAndExit( string $url ): void {
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Enqueue the React screen's build output only on this page (0.3 S10/S17).
	 *
	 * `assets/runs.js`/`assets/runs.css` (0.2's server-rendered screen) are no
	 * longer enqueued here — the cards they drove are retired. The build's
	 * own `index.asset.php` (generated by `@wordpress/scripts`) supplies the
	 * dependency list and a content-hash version, so this never hand-lists
	 * `@wordpress/*` handles that could drift from what actually got bundled.
	 */
	public function assets( string $hook ): void {
		if ( ! str_contains( $hook, self::SLUG ) ) {
			return;
		}

		$asset_file = SENROFLUX_PATH . 'build/runs/index.asset.php';
		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		/** @var array{dependencies:list<string>,version:string} $asset */
		$asset = require $asset_file;

		// `wp_enqueue_script()` wants `array<non-empty-string>`; the build's
		// own manifest already only ever lists real handles, but this is
		// re-checked (fail closed) rather than trusted blindly from disk.
		$dependencies = array_values( array_filter( $asset['dependencies'], static fn ( string $handle ): bool => '' !== $handle ) );

		wp_enqueue_style( 'senroflux-runs', SENROFLUX_URL . 'build/runs/style-index.css', array(), $asset['version'] );
		wp_enqueue_script( 'senroflux-runs', SENROFLUX_URL . 'build/runs/index.js', $dependencies, $asset['version'], true );

		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( 'senroflux-runs', 'senroflux' );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, decides which run opens first.
		$run_id = absint( $_GET['run_id'] ?? ( $_GET['run'] ?? 0 ) );

		// 0.3 S10: the command palette's "SenroFlux: run "<text>"" command
		// (see `commandPaletteAssets()`) links here with `goal` set — it only
		// ever PRE-FILLS the message box, never starts a run itself, so this
		// is read-only same as `run_id` above. Capped at the same length the
		// no-JS New-run form enforces (`MAX_GOAL`) so a very long deep link
		// can't paste something the form would itself have refused.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only prefill, no state change.
		$goal = sanitize_textarea_field( wp_unslash( $_GET['goal'] ?? '' ) );
		if ( $this->strLen( $goal ) > self::MAX_GOAL ) {
			$goal = function_exists( 'mb_substr' ) ? mb_substr( $goal, 0, self::MAX_GOAL ) : substr( $goal, 0, self::MAX_GOAL );
		}

		wp_localize_script(
			'senroflux-runs',
			'senrofluxRunsConfig',
			array(
				'initialRunId'       => $run_id > 0 ? $run_id : null,
				'initialGoal'        => '' !== $goal ? $goal : null,
				// 0.3 S3: the SITE-WIDE mode a NEW run would start under —
				// the empty state's promise sentence and the tier badge both
				// read this, never a per-run value, since no run may be
				// selected yet.
				'gateMode'           => Plugin::currentGateMode()->value,
				'examples'           => $this->exampleGoals(),
				// 0.3 S10 (17c): the admin-ajax surface's tick/cancel/start
				// actions carry the SAME `senroflux_run` nonce + `read`
				// capability check the admin-post handlers use, RE-CHECKED
				// server-side in `Ajax` — this is only the browser's half.
				// admin-ajax (not REST) is the interaction layer's surface:
				// {@see \Specflux\SenroFlux\Admin\ScreenCapability::tickAsScreen()}
				// only wraps the ajax tick handler, so it is the one path
				// that lets a screen-capability holder resolve a run they do
				// not own; REST's tick route has no such wrapper.
				'nonce'              => wp_create_nonce( 'senroflux_run' ),
				// The one HTTP consumer this screen is allowed to start as
				// (S13); registered only for a holder of the screen
				// capability ({@see registerAdminConsumer()}).
				'consumer'           => self::CONSUMER,
				// 0.3 S20: gates the React suggestion card's Save/Dismiss —
				// server-computed, never re-derived from a role guess on the
				// client, since `manage_options` (not the screen capability)
				// is what the REST route itself checks.
				'canManageSiteBrief' => current_user_can( 'manage_options' ),
			)
		);
	}

	/**
	 * Register the "SenroFlux: run "<text>"" command palette command,
	 * site-wide (0.3 S10) — Cmd/Ctrl+K is available on every wp-admin screen
	 * since WP 6.9 (`wp_enqueue_command_palette_assets()`, hooked on this
	 * same `admin_enqueue_scripts` action), not only the Runs screen, so this
	 * runs unconditionally rather than being gated by `$hook` like
	 * {@see self::assets()}.
	 *
	 * Fail closed: a user who does not hold the screen capability never gets
	 * the script at all, so there is nothing for them to see or invoke.
	 *
	 * This command is a NAVIGATION only. It opens the Runs screen with the
	 * typed text already in the message box (via the SAME read-only `goal`
	 * query arg `assets()` reads above) and stops there — it never calls
	 * `senroflux()->start()` or anything that ticks a run. A human still has
	 * to look at the pre-filled box and click "Start run" themselves.
	 */
	public function commandPaletteAssets(): void {
		if ( ! current_user_can( $this->capability() ) ) {
			return;
		}

		$asset_file = SENROFLUX_PATH . 'build/commands/index.asset.php';
		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		/** @var array{dependencies:list<string>,version:string} $asset */
		$asset = require $asset_file;

		$dependencies = array_values( array_filter( $asset['dependencies'], static fn ( string $handle ): bool => '' !== $handle ) );

		wp_enqueue_script( 'senroflux-commands', SENROFLUX_URL . 'build/commands/index.js', $dependencies, $asset['version'], true );

		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( 'senroflux-commands', 'senroflux' );
		}

		wp_localize_script(
			'senroflux-commands',
			'senrofluxCommandsConfig',
			array(
				'runsUrl' => admin_url( 'admin.php?page=' . self::SLUG ),
			)
		);
	}

	/**
	 * Example goals for the empty state (S10), one per pack the CURRENT
	 * viewer's preflight allows. `[assumed]`: `Pack` declares no example-goal
	 * text of its own, so this names the pack rather than inventing a
	 * plausible-sounding goal it never asked to run — flagged in the
	 * stage-17a report.
	 *
	 * @return list<string>
	 */
	protected function exampleGoals(): array {
		$user_id  = get_current_user_id();
		$examples = array();

		foreach ( PackRegistry::fromFilters()->all() as $pack ) {
			if ( true === $pack->preflight( $user_id ) ) {
				$examples[] = sprintf(
					/* translators: %s: a capability pack's name, e.g. "pages". */
					__( 'Try something with the %s pack', 'senroflux' ),
					$pack->name()
				);
			}
		}

		return $examples;
	}

	// ------------------------------------------------------------------
	// admin-post handlers (all no-JS capable)
	// ------------------------------------------------------------------

	/** admin-post endpoint backing the Cancel button: nonce + capability + ownership + redirect. */
	public function handleCancel(): void {
		$run_id = isset( $_GET['run_id'] ) ? absint( $_GET['run_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified below.
		if ( 0 === $run_id ) {
			return;
		}

		check_admin_referer( 'senroflux_cancel_' . $run_id );

		if ( ! current_user_can( $this->capability() ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'senroflux' ) );
		}

		senroflux()->cancel( $run_id );

		$this->redirectBack( $run_id );
	}

	/**
	 * admin-post endpoint backing the "New run" form (S13).
	 *
	 * ONE start path (S13): verify nonce + capability, validate the goal
	 * (required, ≤ 1000), then resolve the request through the SAME
	 * {@see ConsumerPolicy} seam every HTTP consumer goes through. The policy
	 * owns the allow-list and the budget CEILING; the request may only lower
	 * the budget. Only then does this call
	 * `senroflux()->start( 'senroflux-admin', $goal, $allow, $budget, $pack )`.
	 *
	 * `senroflux-admin` is only in the consumer map while the current user
	 * holds the screen capability, so a request that slips past the capability
	 * check would still be refused at the policy seam (fail closed, twice).
	 *
	 * The pack's own S13 preflight is re-run inside `start()` (fail closed);
	 * a preflight failure surfaces as the preflight notice on the list view.
	 */
	public function handleNewRun(): void {
		check_admin_referer( 'senroflux_new_run' );

		if ( ! current_user_can( $this->capability() ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'senroflux' ) );
		}

		$goal = sanitize_textarea_field( wp_unslash( $_POST['goal'] ?? '' ) );
		if ( '' === $goal ) {
			$this->redirectList( 'senroflux_bad_request' );

			return;
		}

		$goal_len = $this->strLen( $goal );
		if ( $goal_len > self::MAX_GOAL ) {
			$this->redirectList( 'senroflux_bad_request' );

			return;
		}

		$pack = sanitize_text_field( wp_unslash( $_POST['pack'] ?? '' ) );
		if ( '' === $pack ) {
			$this->redirectList( 'senroflux_bad_request' );

			return;
		}

		// 0.3 S20: a follow-up run. start() forces the pack to the source
		// run's own pack regardless of the $pack chosen above (fail closed —
		// the form's pack choice is ignored, never trusted, once a source is
		// named).
		$follow_up_of = absint( $_POST['follow_up_of'] ?? 0 );

		// S7: the chosen pack's own default-budget overrides become the
		// ceiling ConsumerPolicy clamps against, instead of the generic
		// registered-consumer table — otherwise a pack asking for a flat,
		// high budget (the site pack) would be clamped straight back down.
		$pack_obj              = PackRegistry::fromFilters()->get( $pack );
		$pack_budget_overrides = null !== $pack_obj ? $pack_obj->defaultBudget() : array();

		// The one policy seam: the server owns the allow-list and the ceiling,
		// the request may only lower the budget (S13 "lower-only" is exactly
		// `Budget::clamp( $requested, $ceiling )` inside ConsumerPolicy).
		$policy = ConsumerPolicy::resolve( self::CONSUMER, $this->rawBudgetInput(), $pack_budget_overrides );
		if ( is_wp_error( $policy ) ) {
			$this->redirectList( (string) $policy->get_error_code() );

			return;
		}

		$result = senroflux()->start(
			self::CONSUMER,
			$goal,
			$policy['allow'],   // Outer bound; start() narrows it to the pack (S9).
			$policy['budget'],
			$pack,              // The pack is the single source of the allow-list.
			null,
			0 !== $follow_up_of ? $follow_up_of : null
		);

		if ( is_wp_error( $result ) ) {
			$this->redirectList( (string) $result->get_error_code() );

			return;
		}

		$this->redirectBack( (int) ( $result['run']['id'] ?? 0 ) );
	}

	/** admin-post endpoint backing the question park's Answer/Skip (S5/S6). */
	public function handleAnswer(): void {
		$run_id = absint( $_POST['run_id'] ?? 0 );
		check_admin_referer( 'senroflux_answer_' . $run_id );

		$resume = $this->assembleAnswerResume();
		if ( is_wp_error( $resume ) ) {
			$this->redirectBack( $run_id, (string) $resume->get_error_code() );

			return;
		}

		$this->redirectBack( $run_id, $this->tickErrorCode( $this->tickThroughScreen( $run_id, absint( $_POST['step_count'] ?? 0 ), $resume ) ) );
	}

	/** admin-post endpoint backing the plan park's Submit (S5/S7). */
	public function handlePlanDecision(): void {
		$run_id = absint( $_POST['run_id'] ?? 0 );
		check_admin_referer( 'senroflux_plan_' . $run_id );

		$resume = $this->assemblePlanResume();
		if ( is_wp_error( $resume ) ) {
			$this->redirectBack( $run_id, (string) $resume->get_error_code() );

			return;
		}

		$this->redirectBack( $run_id, $this->tickErrorCode( $this->tickThroughScreen( $run_id, absint( $_POST['step_count'] ?? 0 ), $resume ) ) );
	}

	/** admin-post endpoint backing the approval park's Approve/Reject (S5/S6). */
	public function handleApprovalDecision(): void {
		$run_id = absint( $_POST['run_id'] ?? 0 );
		check_admin_referer( 'senroflux_approval_' . $run_id );

		$action = sanitize_text_field( wp_unslash( $_POST['senroflux_approval_action'] ?? '' ) );
		if ( ! in_array( $action, array( 'approve', 'reject' ), true ) ) {
			$this->redirectBack( $run_id, 'resume_mismatch' );

			return;
		}

		// Approval parks resume with exactly { "action": "approve" | "reject" }.
		$this->redirectBack(
			$run_id,
			$this->tickErrorCode(
				$this->tickThroughScreen( $run_id, absint( $_POST['step_count'] ?? 0 ), array( 'action' => $action ) )
			)
		);
	}

	/**
	 * admin-post endpoint backing a suggestion's Save/Dismiss (0.3 S20).
	 *
	 * `manage_options` and the nonce are BOTH checked here — RE-CHECKED
	 * inside {@see \Specflux\SenroFlux\Plugin::resolveSuggestion()}'s callee
	 * is not the point; this is the one human-click seam, and it fails
	 * closed on its own, same as {@see \Specflux\SenroFlux\Http\Rest::routeSuggestionDecision()}.
	 */
	public function handleSuggestionDecision(): void {
		$run_id = absint( $_POST['run_id'] ?? 0 );
		check_admin_referer( 'senroflux_suggestion_' . $run_id );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'senroflux' ) );
		}

		$seq    = absint( $_POST['seq'] ?? 0 );
		$action = sanitize_text_field( wp_unslash( $_POST['senroflux_suggestion_action'] ?? '' ) );
		$text   = isset( $_POST['text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['text'] ) ) : null;

		$result = senroflux()->resolveSuggestion( $run_id, $seq, $action, $text );

		$this->redirectBack( $run_id, is_wp_error( $result ) ? (string) $result->get_error_code() : null );
	}

	/**
	 * The error code to carry back in the redirect, or null on success.
	 *
	 * A park handler used to DISCARD the tick result, so a 403 (or a
	 * `senroflux_conflict`) redirected silently back to a card that looked
	 * unchanged. Surfacing the code lets `renderRunErrorFlash()` say what
	 * happened.
	 *
	 * @param array<string,mixed>|WP_Error $result The tick outcome.
	 */
	private function tickErrorCode( array|WP_Error $result ): ?string {
		if ( ! is_wp_error( $result ) ) {
			return null;
		}

		$code = (string) $result->get_error_code();

		return '' !== $code ? $code : 'senroflux_bad_request';
	}

	// ------------------------------------------------------------------
	// Resume assembly (the S5 shapes, assembled on the screen)
	// ------------------------------------------------------------------

	/**
	 * Assemble an awaiting_user resume: { "answer": { "text"?, "choice"? } } or
	 * { "skip": true } (S5). Invalid shapes surface as WP_Error so the park card
	 * re-renders rather than being half-resolved.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	protected function assembleAnswerResume(): array|WP_Error {
		if ( 'skip' === $this->param( 'senroflux_answer_action' ) ) {
			return array( 'skip' => true );
		}

		$choice = (string) $this->param( 'senroflux_answer_choice' );
		$other  = (string) $this->param( 'senroflux_answer_other' );
		$text   = (string) $this->param( 'senroflux_answer_text' );

		// The "Other" affordance (or a textarea-only card) wins: a typed answer
		// is a text answer, never a choice.
		if ( '__other__' === $choice ) {
			if ( '' === trim( $other ) ) {
				return new WP_Error(
					'resume_mismatch',
					__( 'Type your answer, or pick one of the offered choices.', 'senroflux' ),
					array( 'status' => 400 )
				);
			}

			return array( 'answer' => array( 'text' => $other ) );
		}

		if ( '' !== $choice ) {
			// `choice_not_offered` is enforced by the Runner against the stored
			// payload (S5): the screen sends what the user picked.
			return array( 'answer' => array( 'choice' => $choice ) );
		}

		if ( '' !== trim( $text ) ) {
			return array( 'answer' => array( 'text' => $text ) );
		}

		return new WP_Error(
			'resume_mismatch',
			__( 'Choose a response to continue.', 'senroflux' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Assemble an awaiting_plan resume: { "plan": { "action", "note"? } } (S5).
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	protected function assemblePlanResume(): array|WP_Error {
		$action = (string) $this->param( 'senroflux_plan_action' );
		$note   = (string) $this->param( 'senroflux_plan_note' );

		if ( ! in_array( $action, array( 'accept', 'accept_preapprove', 'veto' ), true ) ) {
			return new WP_Error(
				'resume_mismatch',
				__( 'Choose Accept or Veto.', 'senroflux' ),
				array( 'status' => 400 )
			);
		}

		if ( 'veto' === $action && '' === trim( $note ) ) {
			return new WP_Error(
				'resume_mismatch',
				__( 'A veto needs a note saying why.', 'senroflux' ),
				array( 'status' => 400 )
			);
		}

		$plan = array( 'action' => $action );
		if ( '' !== $note ) {
			$plan['note'] = $note;
		}

		return array( 'plan' => $plan );
	}

	// ------------------------------------------------------------------
	// Acting-user rule (S6/S7): a screen-capability holder may act on ANY run
	// ------------------------------------------------------------------

	/**
	 * Advance a run through `senroflux()->tick()` from the screen context.
	 *
	 * The Runner's `mayTick()` defaults to owner-only but is gated by the
	 * `senroflux_can_tick` filter (the delegation seam). A holder of the screen
	 * capability may answer/act on a run even when they are not its owner; the
	 * Runner itself records the `answered_by` system step (S6/S7).
	 *
	 * The capability check happens HERE, inside
	 * {@see ScreenCapability::tickAsScreen()} — the park handlers above verify
	 * the nonce, not the capability, so this method must never assume they did.
	 * The allowance is scoped to this ONE run id and removed in a `finally`, so
	 * it never leaks outside this call. The exact same helper backs the
	 * admin-ajax poll ({@see \Specflux\SenroFlux\Http\Ajax::handleTick()}), so
	 * polling a delegated run behaves like submitting its form.
	 *
	 * @param array<string,mixed>|null $resume Park resolution.
	 * @return array<string,mixed>|WP_Error RunState or an error.
	 */
	private function tickThroughScreen( int $run_id, int $step_count, ?array $resume ): array|WP_Error {
		return ScreenCapability::tickAsScreen(
			$run_id,
			static fn (): array|WP_Error => senroflux()->tick( $run_id, $step_count, $resume )
		);
	}

	// ------------------------------------------------------------------
	// Rendering
	// ------------------------------------------------------------------

	/** Redirect to the detail view and stop processing (test-observable). */
	protected function redirectBack( int $run_id, ?string $error_code = null ): void {
		$url = admin_url( 'tools.php?page=' . self::SLUG . '&run_id=' . $run_id );
		if ( null !== $error_code && '' !== $error_code ) {
			$url .= '&senroflux_run_error=' . rawurlencode( $error_code );
		}
		wp_safe_redirect( $url );

		exit;
	}

	/** Redirect back to the list (used by start-failure / missing input). */
	protected function redirectList( string $error_code ): void {
		wp_safe_redirect(
			admin_url( 'tools.php?page=' . self::SLUG . '&senroflux_start_error=' . rawurlencode( $error_code ) )
		);

		exit;
	}

	/**
	 * Render list or detail.
	 *
	 * 0.3 S11: a blocked setup state still renders the FULL screen (the setup
	 * panel, then the empty state / example goals / palette command below it)
	 * — the panel names what to fix, it never replaces the screen.
	 */
	/**
	 * 0.3 S10: the screen is now the React app's mount point. The server
	 * still renders the S11 setup panel (its OWN evaluator, independent of
	 * the run list/detail this replaces) and a `<noscript>` fallback; every
	 * run-list row, park card and report table that 0.2 rendered here is
	 * RETIRED in favour of `assets/src/runs/` (S10, a breaking change named
	 * in the readme changelog). The admin-post handlers below are UNCHANGED
	 * — 17b still needs them (stage 16's suggestion Save/Dismiss UI, in
	 * particular) even though their no-JS forms no longer render.
	 */
	public function render(): void {
		if ( ! current_user_can( $this->capability() ) ) {
			return;
		}

		echo '<div class="wrap">';
		printf( '<h1>%s</h1>', esc_html__( 'SenroFlux', 'senroflux' ) );

		echo $this->renderSetupPanel( get_current_user_id() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped fragment.

		echo '<noscript><div class="notice notice-warning"><p>'
			. esc_html__( 'The SenroFlux Runs screen needs JavaScript to be enabled in your browser.', 'senroflux' )
			. '</p></div></noscript>';

		echo '<div id="senroflux-runs-root"></div>';

		echo '</div>';
	}

	// ------------------------------------------------------------------
	// Setup panel (0.3 S11): the harness's own checks (provider, Agent
	// Safety advisory). Pack-level checks (`<pack>/capability`,
	// `<pack>/binding`) render inline in the new-run form's pack picker
	// (stage 8's per-pack preflight notice) — this panel is the harness's
	// half of the SAME evaluator ({@see Checks}).
	// ------------------------------------------------------------------

	/**
	 * The setup panel markup: one notice per harness check that is either
	 * failing (blocking) or showing (advisory, undismissed). Empty string
	 * when everything is clear.
	 */
	private function renderSetupPanel( int $user_id ): string {
		$checks = Checks::harnessChecks( $user_id );
		if ( array() === $checks ) {
			return '';
		}

		$html = '<div id="senroflux-setup-panel" class="senroflux-setup-panel">';
		foreach ( $checks as $check ) {
			if ( $check->isBlocking() && $check->passed() ) {
				continue;
			}
			$html .= $this->renderSetupCheckNotice( $check, $user_id );
		}
		$html .= '</div>';

		return $html;
	}

	/** One check's notice: an error for a failing blocking check, a dismissible warning for the advisory. */
	private function renderSetupCheckNotice( SetupCheck $check, int $user_id ): string {
		$css_class = $check->isBlocking() ? 'notice-error' : 'notice-warning';
		$message   = $check->messageFor( $user_id );
		$link      = '';

		if ( $check->fixVisibleFor( $user_id ) && null !== $check->fixUrl() ) {
			$link = sprintf(
				' <a href="%s">%s</a>',
				esc_url( $check->fixUrl() ),
				esc_html__( 'Fix this', 'senroflux' )
			);
		}

		// Only the Agent Safety advisory carries a dismissal (S11: "per-user
		// dismissal in user meta"); no other check gets a Dismiss button.
		$dismiss = 'senroflux/agent-safety' === $check->id() ? $this->renderDismissButton() : '';

		// The "inline" class matters as much as "notice" does: it keeps the
		// warning/error look core's own CSS gives `.notice`, but core's own
		// admin JS skips `.notice.inline` when it relocates every other
		// `.wrap .notice` below the page heading (0.3 defect 3) — without
		// it, one copy of every check ends up duplicated outside this panel,
		// and a stale copy can linger there after the panel re-renders.
		return sprintf(
			'<div class="notice %1$s inline senroflux-setup-check" data-check-id="%2$s"><p>%3$s%4$s</p>%5$s</div>',
			esc_attr( $css_class ),
			esc_attr( $check->id() ),
			esc_html( $message ),
			$link, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_url()/esc_html() above.
			$dismiss // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_attr()/esc_html() below.
		);
	}

	/** The Agent Safety advisory's own "Dismiss" button (nonce-protected, current user only). */
	private function renderDismissButton(): string {
		return sprintf(
			'<p><button type="button" class="button senroflux-dismiss-check" data-nonce="%s">%s</button></p>',
			esc_attr( wp_create_nonce( 'senroflux_dismiss_agent_safety_check' ) ),
			esc_html__( 'Dismiss', 'senroflux' )
		);
	}

	/**
	 * admin-ajax: re-render the setup panel (S11 "refresh on window focus").
	 * Nonce-protected; capability = the computed screen capability (the same
	 * one that gates the whole screen).
	 */
	public function handleSetupPanel(): void {
		check_ajax_referer( 'senroflux_run', 'nonce' );

		if ( ! current_user_can( $this->capability() ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'senroflux' ) ), 403 );
		}

		$user_id  = get_current_user_id();
		$pack     = sanitize_text_field( wp_unslash( $_POST['pack'] ?? '' ) );
		$pack_obj = '' !== $pack ? PackRegistry::fromFilters()->get( $pack ) : null;

		$checks         = null !== $pack_obj ? Checks::forPack( $pack_obj, $user_id ) : Checks::harnessChecks( $user_id );
		$start_disabled = null !== Checks::firstBlockingFailure( $checks );

		wp_send_json_success(
			array(
				'html'          => $this->renderSetupPanel( $user_id ),
				'start_enabled' => ! $start_disabled,
			)
		);
	}

	/**
	 * admin-ajax: dismiss the Agent Safety advisory for the CURRENT user only
	 * (S11: per-user dismissal, never re-arms).
	 */
	public function handleDismissAgentSafetyCheck(): void {
		check_ajax_referer( 'senroflux_dismiss_agent_safety_check', 'nonce' );

		if ( ! current_user_can( $this->capability() ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'senroflux' ) ), 403 );
		}

		Checks::dismissAgentSafetyFor( get_current_user_id() );

		wp_send_json_success( array( 'html' => $this->renderSetupPanel( get_current_user_id() ) ) );
	}

	/**
	 * One-time notice on the Plugins screen only (S10 activation: no
	 * redirect, no site-wide notice). The transient is set on activation
	 * ({@see \Specflux\SenroFlux\senroflux_activate()}) and deleted the first
	 * time this renders, so it never shows twice.
	 */
	public function maybeRenderActivationNotice(): void {
		global $pagenow;

		if ( 'plugins.php' !== $pagenow || ! function_exists( 'get_transient' ) ) {
			return;
		}

		if ( ! get_transient( self::ACTIVATION_NOTICE_TRANSIENT ) ) {
			return;
		}

		delete_transient( self::ACTIVATION_NOTICE_TRANSIENT );

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s <a href="%s">%s</a></p></div>',
			esc_html__( 'SenroFlux is active.', 'senroflux' ),
			esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ),
			esc_html__( 'Start a run', 'senroflux' )
		);
	}

	/**
	 * 0.3 S9: a run "needs" the viewer when it is parked AND the viewer may
	 * tick it (the same `senroflux_can_tick` delegation seam the Runner
	 * itself gates on) — a stalled `running` run is NOT a park (S9 names
	 * park kinds and the stalled state as two different things), so it never
	 * qualifies here even though it also wants a human to reopen the tab.
	 *
	 * @param array<string,mixed> $run {@see \Specflux\SenroFlux\Plugin::listRecent()} row shape.
	 */
	public static function needsYou( array $run ): bool {
		return self::isParkedStatus( (string) $run['status'] ) && (bool) ( $run['viewer_may_tick'] ?? false );
	}

	/**
	 * 0.3 S20: eligible for "Follow up" — the run is `completed`, `failed` or
	 * `cancelled`, it has a pack (a direct-allow run has no "source pack's
	 * run capability" to check, so it is never eligible — fail closed), and
	 * the CURRENT viewer holds that pack's run capability.
	 *
	 * @param array<string,mixed> $run {@see \Specflux\SenroFlux\Plugin::listRecent()} row shape.
	 */
	public static function eligibleForFollowUp( array $run ): bool {
		if ( ! in_array(
			(string) $run['status'],
			array( RunStatus::Completed->value, RunStatus::Failed->value, RunStatus::Cancelled->value ),
			true
		) ) {
			return false;
		}

		$pack_name = is_string( $run['pack'] ?? null ) ? $run['pack'] : '';
		if ( '' === $pack_name ) {
			return false;
		}

		$pack = PackRegistry::fromFilters()->get( $pack_name );
		if ( null === $pack ) {
			return false;
		}

		$capability = $pack->runCapability();

		return '' !== $capability && function_exists( 'current_user_can' ) && current_user_can( $capability );
	}

	/** Whether `$status` is one of the three park statuses (S9). */
	public static function isParkedStatus( string $status ): bool {
		return in_array(
			$status,
			array( RunStatus::AwaitingApproval->value, RunStatus::AwaitingUser->value, RunStatus::AwaitingPlan->value ),
			true
		);
	}

	/**
	 * The list row's status text (0.3 S9): the park kind for a parked run,
	 * "paused while closed — open to continue" for a stalled `running` run,
	 * or the ordinary status label otherwise.
	 *
	 * @param array<string,mixed> $run {@see \Specflux\SenroFlux\Plugin::listRecent()} row shape.
	 */
	public static function listRowLabel( array $run ): string {
		$status = (string) $run['status'];

		$park_kind = self::parkKindLabel( $status );
		if ( null !== $park_kind ) {
			return $park_kind;
		}

		if ( RunStatus::Running->value === $status && (bool) ( $run['stalled'] ?? false ) ) {
			return __( 'paused while closed — open to continue', 'senroflux' );
		}

		return self::statusLabel( $status );
	}

	/**
	 * The S9 park-kind phrasing for the Runs LIST row — distinct wording from
	 * {@see self::statusLabel()}'s detail-screen badge, which S9 leaves
	 * unchanged. Null for a non-parked status.
	 */
	public static function parkKindLabel( string $status ): ?string {
		return match ( $status ) {
			RunStatus::AwaitingUser->value => __( 'waiting for your answer', 'senroflux' ),
			RunStatus::AwaitingApproval->value => __( 'waiting for approval', 'senroflux' ),
			RunStatus::AwaitingPlan->value => __( 'waiting on the plan', 'senroflux' ),
			default => null,
		};
	}

	/**
	 * status value => translated label (S15 harness chrome).
	 *
	 * The enum VALUES stay machine-stable everywhere they matter (the CSS
	 * class, `data-status`, the poll payload); only what a human READS is
	 * translated. The same map is handed to `runs.js` so a JS-swapped badge
	 * reads identically to a server-rendered one.
	 *
	 * @return array<string,string>
	 */
	public static function statusLabels(): array {
		return array(
			RunStatus::Pending->value          => __( 'pending', 'senroflux' ),
			RunStatus::Running->value          => __( 'running', 'senroflux' ),
			RunStatus::AwaitingApproval->value => __( 'awaiting approval', 'senroflux' ),
			RunStatus::AwaitingUser->value     => __( 'awaiting your answer', 'senroflux' ),
			RunStatus::AwaitingPlan->value     => __( 'awaiting plan decision', 'senroflux' ),
			RunStatus::Completed->value        => __( 'completed', 'senroflux' ),
			RunStatus::Failed->value           => __( 'failed', 'senroflux' ),
			RunStatus::Cancelled->value        => __( 'cancelled', 'senroflux' ),
		);
	}

	/** One translated status label; an unknown value falls back to itself. */
	public static function statusLabel( string $status ): string {
		return self::statusLabels()[ $status ] ?? $status;
	}

	/** A POST value, unslashed + trimmed (textarea-safe), or ''. */
	private function param( string $key ): string {
		// sanitize_textarea_field preserves newlines; it is safe for a textarea
		// answer/note and harmless for a short radio/button value. The nonce is
		// verified by the handler's check_admin_referer before this runs.
		return sanitize_textarea_field( wp_unslash( isset( $_POST[ $key ] ) ? $_POST[ $key ] : '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
	}

	/**
	 * The five ceiling inputs as raw ints (for Budget::clamp).
	 *
	 * @return array<string,int>
	 */
	private function rawBudgetInput(): array {
		$out = array();
		foreach ( array( 'max_steps', 'max_tool_calls', 'max_tokens', 'max_questions', 'max_plans' ) as $key ) {
			if ( isset( $_POST[ $key ] ) && '' !== $_POST[ $key ] ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by the handler's check_admin_referer.
				$out[ $key ] = absint( $_POST[ $key ] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by the handler's check_admin_referer.
			}
		}

		return $out;
	}

	/** mb_strlen with a strlen fallback (mbstring may be absent). */
	private function strLen( string $value ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $value ) : strlen( $value );
	}
}
