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
use Specflux\SenroFlux\Packs\Pack;
use Specflux\SenroFlux\Packs\PackRegistry;
use Specflux\SenroFlux\Run\Budget;
use Specflux\SenroFlux\Run\RunStatus;
use Specflux\SenroFlux\Run\StepKind;
use Specflux\SenroFlux\Tools\VerbTier;
use WP_Error;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Render + act on SenroFlux runs.
 *
 * The one consumer id the screen starts runs for is `senroflux-admin` (S13).
 * Because a pack is always chosen, `start()` derives the allow-list from the
 * pack and ignores any `$allow`, so the screen is the "one start path" and
 * never needs to consult `ConsumerPolicy` (that seam is for arbitrary HTTP
 * consumers, not the logged-in admin).
 */
class RunsScreen {

	private const SLUG = 'senroflux-runs';

	/** The consumer id label for admin-started runs (S13). */
	private const CONSUMER = 'senroflux-admin';

	/** Goal length cap (S13: required, ≤ 1000 chars). */
	private const MAX_GOAL = 1000;

	/** Register on admin_menu (+ posts + assets). */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_post_senroflux_cancel_run', array( $this, 'handleCancel' ) );
		add_action( 'admin_post_senroflux_new_run', array( $this, 'handleNewRun' ) );
		add_action( 'admin_post_senroflux_answer', array( $this, 'handleAnswer' ) );
		add_action( 'admin_post_senroflux_plan_decision', array( $this, 'handlePlanDecision' ) );
		add_action( 'admin_post_senroflux_approval_decision', array( $this, 'handleApprovalDecision' ) );
	}

	/**
	 * The capability that unlocks the screen; filterable per S10/S13.
	 *
	 * The same capability gates see/start/answer/act/cancel. Writes STILL
	 * require `edit_pages` at the ability (S13) — this only controls who may
	 * drive a run from this screen.
	 */
	public function capability(): string {
		/** Filters the capability required for the Runs screen. */
		return (string) apply_filters( 'senroflux_runs_capability', 'manage_options' );
	}

	/** Add the Tools submenu. */
	public function menu(): void {
		add_management_page(
			__( 'SenroFlux Runs', 'senroflux' ),
			__( 'SenroFlux Runs', 'senroflux' ),
			$this->capability(),
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/** Enqueue the screen assets only on this page. */
	public function assets( string $hook ): void {
		if ( ! str_contains( $hook, self::SLUG ) ) {
			return;
		}

		wp_enqueue_style( 'senroflux-runs', SENROFLUX_URL . 'assets/runs.css', array(), '0.2.0' );
		wp_enqueue_script( 'senroflux-runs', SENROFLUX_URL . 'assets/runs.js', array(), '0.2.0', true );

		wp_localize_script(
			'senroflux-runs',
			'senrofluxRuns',
			array(
				'cancelConfirm' => __( 'Cancel this run?', 'senroflux' ),
				'pollInterval'  => 3000,
				// The poll uses the SAME admin-ajax tick surface as any other
				// logged-in consumer; a screen-capability holder answers by
				// submitting the park-card form, not by polling (polling drives
				// running pendings only).
				'ajaxUrl'       => function_exists( 'admin_url' ) ? admin_url( 'admin-ajax.php' ) : '',
				'nonce'         => function_exists( 'wp_create_nonce' ) ? wp_create_nonce( 'senroflux_run' ) : '',
			)
		);
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
	 * ONE start path: verify nonce + capability, validate the goal (required,
	 * ≤ 1000) and clamp the five ceilings LOWER-ONLY against the site defaults,
	 * then call `senroflux()->start( 'senroflux-admin', $goal, [], $budget, $pack )`.
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

		// Lower-only: an admin may only reduce a ceiling below the site default,
		// never raise it. `Budget::clamp( $requested, Budget::defaults() )` does
		// exactly that (S13).
		$budget = Budget::clamp( $this->rawBudgetInput(), Budget::defaults() );

		$result = senroflux()->start(
			self::CONSUMER,
			$goal,
			array(),   // A pack is always chosen: start() ignores this (S9).
			$budget,
			$pack      // The pack is the single source of the allow-list.
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

		$this->tickThroughScreen( $run_id, absint( $_POST['step_count'] ?? 0 ), $resume );
		$this->redirectBack( $run_id );
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

		$this->tickThroughScreen( $run_id, absint( $_POST['step_count'] ?? 0 ), $resume );
		$this->redirectBack( $run_id );
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
		$this->tickThroughScreen( $run_id, absint( $_POST['step_count'] ?? 0 ), array( 'action' => $action ) );
		$this->redirectBack( $run_id );
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
	 * Runner itself records the `answered_by` system step (S6/S7). The filter
	 * allowance is added ONLY for the duration of this handler's single tick
	 * call — it never leaks outside the screen context — and the handler has
	 * ALREADY verified `current_user_can($capability)` at its top.
	 *
	 * @param array<string,mixed>|null $resume Park resolution.
	 * @return array<string,mixed>|WP_Error RunState or an error.
	 */
	private function tickThroughScreen( int $run_id, int $step_count, ?array $resume ): array|WP_Error {
		if ( ! current_user_can( $this->capability() ) ) {
			return new WP_Error(
				'senroflux_forbidden',
				__( 'Insufficient permissions.', 'senroflux' ),
				array( 'status' => 403 )
			);
		}

		// Delegation allowance, scoped to this handler's own tick.
		$callback = static fn (): bool => true;
		add_filter( 'senroflux_can_tick', $callback, 10, 0 );

		try {
			return senroflux()->tick( $run_id, $step_count, $resume );
		} finally {
			remove_filter( 'senroflux_can_tick', $callback, 10 );
		}
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

	/** Render list or detail. */
	public function render(): void {
		if ( ! current_user_can( $this->capability() ) ) {
			return;
		}

		// The canonical GET key is `run_id`; the UI-generated review URL in the
		// parked steps uses `&run=<id>`, so accept both (read-only alias).
		$run_id = absint( $_GET['run_id'] ?? ( $_GET['run'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list navigation.

		echo '<div class="wrap">';
		printf( '<h1>%s</h1>', esc_html__( 'SenroFlux Runs', 'senroflux' ) );

		if ( $run_id > 0 ) {
			$this->renderDetail( $run_id );
		} else {
			$this->renderList();
		}

		echo '</div>';
	}

	/** The run list: New-run form (with preflight gate) + the runs table. */
	private function renderList(): void {
		echo '<h2>' . esc_html__( 'Start a new run', 'senroflux' ) . '</h2>';
		$this->renderNewRunForm();

		$runs = senroflux()->available() ? senroflux()->listRecent() : array();

		echo '<h2>' . esc_html__( 'Recent runs', 'senroflux' ) . '</h2>';

		echo '<table class="widefat striped senroflux-runs-table"><thead><tr>';
		foreach ( array( 'ID', 'User', 'Consumer', 'Goal', 'Status', 'Steps', 'Tokens', 'Updated', '' ) as $col ) {
			echo '<th>' . esc_html( $col ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		if ( array() === $runs ) {
			echo '<tr><td colspan="9">' . esc_html__( 'No runs yet.', 'senroflux' ) . '</td></tr>';
		}

		foreach ( $runs as $run ) {
			printf(
				'<tr><td>%1$d</td><td>%2$d</td><td>%3$s</td><td>%4$s</td><td><span class="senroflux-badge senroflux-badge-%5$s">%5$s</span></td><td>%6$d</td><td>%7$d/%8$d</td><td>%9$s</td><td><a href="%10$s">%11$s</a></td></tr>',
				(int) $run['id'],
				(int) $run['user_id'],
				esc_html( (string) $run['consumer'] ),
				esc_html( wp_trim_words( (string) $run['goal'], 8, '…' ) ),
				esc_attr( (string) $run['status'] ),
				(int) $run['step_count'],
				(int) $run['tokens_in'],
				(int) $run['tokens_out'],
				esc_html( (string) $run['updated_at'] ),
				esc_url( admin_url( 'tools.php?page=' . self::SLUG . '&run_id=' . (int) $run['id'] ) ),
				esc_html__( 'View steps', 'senroflux' )
			);
		}

		echo '</tbody></table>';
	}

	/**
	 * The "New run" form, or the S13 preflight notice instead.
	 *
	 * Preflight blocks the ENTIRE form (S13): resolve the pages pack (the
	 * stage-9 default) and call `preflight( get_current_user_id() )`. On a
	 * WP_Error render the notice — with a link to Agent Capability Packs — and
	 * RENDER NO FORM. SenroFlux never auto-binds, so an unbound user must go
	 * bind, not start.
	 */
	private function renderNewRunForm(): void {
		$registry = PackRegistry::fromFilters();

		// Preflight the DEFAULT pack (the pages pack); if it is missing there is
		// nothing to gate (stage 9 always ships it).
		$gate_pack = $registry->get( 'pages' );
		if ( null === $gate_pack ) {
			$first = $registry->all();
			if ( array() !== $first ) {
				$gate_pack = reset( $first );
			}
		}

		if ( null !== $gate_pack ) {
			$preflight = $gate_pack->preflight( get_current_user_id() );
			if ( is_wp_error( $preflight ) ) {
				$this->renderPreflightNotice( $preflight );

				return;
			}
		}

		$packs = $registry->all();
		if ( array() === $packs ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'No capability pack is registered, so no run can be started from here.', 'senroflux' ) . '</p></div>';

			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="senroflux-new-run-form">';
		echo '<input type="hidden" name="action" value="senroflux_new_run">';
		wp_nonce_field( 'senroflux_new_run' );

		echo '<p>';
		echo '<label for="senroflux-goal">' . esc_html__( 'Goal', 'senroflux' ) . '</label>';
		echo '<textarea id="senroflux-goal" name="goal" rows="3" required maxlength="' . esc_attr( (string) self::MAX_GOAL ) . '" class="large-text code"></textarea>';
		echo '</p>';

		echo '<p>';
		echo '<label for="senroflux-pack">' . esc_html__( 'Capability pack', 'senroflux' ) . '</label>';
		echo '<select id="senroflux-pack" name="pack">';
		foreach ( $packs as $pack ) {
			$label = $this->packLabel( $pack );
			printf(
				'<option value="%s">%s</option>',
				esc_attr( $pack->name() ),
				esc_html( $label )
			);
		}
		echo '</select>';
		echo '</p>';

		echo '<details class="senroflux-advanced">';
		echo '<summary>' . esc_html__( 'Advanced', 'senroflux' ) . '</summary>';

		$labels = array(
			'max_steps'      => __( 'Max steps', 'senroflux' ),
			'max_tool_calls' => __( 'Max tool calls', 'senroflux' ),
			'max_tokens'     => __( 'Max tokens', 'senroflux' ),
			'max_questions'  => __( 'Max questions', 'senroflux' ),
			'max_plans'      => __( 'Max plans', 'senroflux' ),
		);

		foreach ( $labels as $key => $label ) {
			printf(
				'<p class="senroflux-ceiling"><label for="senroflux-%1$s">%2$s</label><input id="senroflux-%1$s" name="%1$s" type="number" min="1" step="1" inputmode="numeric"></p>',
				esc_attr( $key ),
				esc_html( $label )
			);
		}

		echo '</details>';

		printf(
			'<p><button type="submit" class="button button-primary">%s</button></p>',
			esc_html__( 'Start run', 'senroflux' )
		);

		echo '</form>';
	}

	/** The preflight notice: message + a link to Agent Capability Packs. */
	private function renderPreflightNotice( WP_Error $error ): void {
		// `agent-safety-packs` is registered via add_management_page in the
		// Agent Capability Packs screen, so it lives under the Tools menu.
		$packs_url = admin_url( 'tools.php?page=agent-safety-packs' );

		printf(
			'<div class="notice notice-error"><p>%s <a href="%s">%s</a></p></div>',
			esc_html( $error->get_error_message() ),
			esc_url( $packs_url ),
			esc_html__( 'Open Agent Capability Packs', 'senroflux' )
		);
	}

	/** A human pack label for the <select> (name => label). */
	private function packLabel( Pack $pack ): string {
		// A pack may supply a translation-ready label; fall back to a readable
		// form of the machine name. Either way it is harness chrome (translated).
		if ( method_exists( $pack, 'label' ) && is_string( $pack->label() ) && '' !== $pack->label() ) {
			return $pack->label();
		}

		/* translators: %s is the pack machine name. */
		return sprintf( __( '%s pack', 'senroflux' ), ucwords( str_replace( array( '_', '-' ), ' ', $pack->name() ) ) );
	}

	/** One run: report/park cards + timeline + cancel. */
	private function renderDetail( int $run_id ): void {
		$state = senroflux()->get( $run_id );
		if ( is_wp_error( $state ) ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html( $state->get_error_message() )
			);

			return;
		}

		$run = $state['run'];

		printf(
			'<p><a href="%s">← %s</a></p>',
			esc_url( admin_url( 'tools.php?page=' . self::SLUG ) ),
			esc_html__( 'Back to runs', 'senroflux' )
		);

		// The detail container carries the polling preconditions (read by JS).
		printf(
			'<div id="senroflux-run-detail" class="senroflux-run-detail" data-run-id="%1$d" data-step-count="%2$d" data-status="%3$s">',
			(int) $run['id'],
			(int) $run['step_count'],
			esc_attr( (string) $run['status'] )
		);

		// S15 aria-live region: status TRANSITIONS only, driven by JS.
		echo '<div id="senroflux-live-status" class="senroflux-sr-only" aria-live="polite"></div>';

		printf(
			'<h2>%s <span id="senroflux-status-badge" class="senroflux-badge senroflux-badge-%s">%s</span></h2>',
			esc_html( (string) $run['goal'] ),
			esc_attr( (string) $run['status'] ),
			esc_html( (string) $run['status'] )
		);

		$this->renderRunErrorFlash();

		if ( ! empty( $run['error'] ) && is_array( $run['error'] ) ) {
			printf(
				'<div class="notice notice-error inline"><p><strong>%s</strong> %s</p></div>',
				esc_html( (string) ( $run['error']['code'] ?? '' ) ),
				esc_html( (string) ( $run['error']['message'] ?? '' ) )
			);
		}

		$status = RunStatus::tryFrom( (string) $run['status'] );
		if ( null !== $status && $status->isParked() ) {
			// The three park cards; each is a plain admin-post form (no JS).
			match ( $status ) {
				RunStatus::AwaitingUser     => $this->renderQuestionCard( $state ),
				RunStatus::AwaitingPlan     => $this->renderPlanCard( $state ),
				RunStatus::AwaitingApproval => $this->renderApprovalCard( $state ),
				default                     => null,
			};

			// The review link for approvals still lives with Agent Safety.
			if ( RunStatus::AwaitingApproval === $status ) {
				$this->renderApprovalReviewLinks( $state );
			}
		}

		// "Refresh" link for the complete-without-JS experience.
		printf(
			'<p class="senroflux-refresh"><a href="%s">%s</a></p>',
			esc_url( admin_url( 'tools.php?page=' . self::SLUG . '&run_id=' . $run_id ) ),
			esc_html__( 'Refresh', 'senroflux' )
		);

		// Cancel for anything still in flight.
		if ( null !== $status && ! $status->isTerminal() ) {
			$cancel_url = wp_nonce_url(
				admin_url( 'admin-post.php?action=senroflux_cancel_run&run_id=' . $run_id ),
				'senroflux_cancel_' . $run_id
			);
			printf(
				'<p><a class="button button-secondary" href="%s" onclick="return confirm(\'%s\');">%s</a></p>',
				esc_url( $cancel_url ),
				esc_attr__( 'Cancel this run?', 'senroflux' ),
				esc_html__( 'Cancel run', 'senroflux' )
			);
		}

		// Terminal runs carry the harness-built report (S12) plus the timeline.
		if ( null !== $status && $status->isTerminal() ) {
			$this->renderReport( $run );
		}

		$this->renderStepsTimeline( $state );

		echo '</div>'; // #senroflux-run-detail
	}

	/** A flash notice for a failed park resolution (redirected back here). */
	private function renderRunErrorFlash(): void {
		$code = isset( $_GET['senroflux_run_error'] ) ? sanitize_text_field( wp_unslash( $_GET['senroflux_run_error'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flash.
		if ( '' === $code ) {
			return;
		}

		$messages = array(
			'resume_mismatch'      => __( 'That response did not match what the run was waiting for.', 'senroflux' ),
			'choice_not_offered'   => __( 'That choice was not one of the offered options.', 'senroflux' ),
			'preapproval_disabled' => __( 'Pre-approval is not available for this run.', 'senroflux' ),
			'senroflux_conflict'   => __( 'The run advanced since your last action; refresh and try again.', 'senroflux' ),
		);

		$message = $messages[ $code ] ?? __( 'The run could not be updated.', 'senroflux' );

		printf( '<div class="notice notice-error inline"><p>%s</p></div>', esc_html( $message ) );
	}

	/**
	 * The S6 question park card.
	 *
	 * @param array<string,mixed> $state The run state.
	 */
	private function renderQuestionCard( array $state ): void {
		$run      = $state['run'];
		$question = $this->newestStepOfKind( $state, StepKind::Question->value );
		if ( null === $question || ! is_array( $question['message'] ?? null ) ) {
			return;
		}

		$payload   = $question['message'];
		$text      = (string) ( $payload['text'] ?? '' );
		$choices   = is_array( $payload['choices'] ?? null ) ? array_values( $payload['choices'] ) : array();
		$other     = (bool) ( $payload['allow_other'] ?? true );
		$rationale = (string) ( $payload['rationale'] ?? '' );

		$action = admin_url( 'admin-post.php' );
		echo '<section class="senroflux-park-card" aria-labelledby="senroflux-park-question-heading">';

		// CONTENT BOUNDARY — question text is MODEL-AUTHORED (S15): stored and
		// rendered verbatim, NEVER wrapped in __()/esc_html__(). It is content,
		// not harness chrome. (esc_html is applied for safety, not i18n.)
		printf(
			'<h3 id="senroflux-park-question-heading" class="senroflux-park-card-heading" tabindex="-1">%s</h3>',
			esc_html( $text )
		);

		echo '<form method="post" action="' . esc_url( $action ) . '">';
		echo '<input type="hidden" name="action" value="senroflux_answer">';
		echo '<input type="hidden" name="run_id" value="' . esc_attr( (string) $run['id'] ) . '">';
		echo '<input type="hidden" name="step_count" value="' . esc_attr( (string) $run['step_count'] ) . '">';
		wp_nonce_field( 'senroflux_answer_' . (int) $run['id'] );

		// The rationale describes the question; link it via aria-describedby (S15).
		$described_by = ( '' !== $rationale ) ? ' aria-describedby="senroflux-rationale"' : '';
		echo '<fieldset' . esc_attr( $described_by ) . '>';
		// CONTENT BOUNDARY — the legend is the question text, verbatim (S15).
		echo '<legend>' . esc_html( $text ) . '</legend>';

		if ( '' !== $rationale ) {
			// CONTENT BOUNDARY — rationale is MODEL-AUTHORED prose (S15), verbatim.
			printf( '<p id="senroflux-rationale" class="senroflux-rationale">%s</p>', esc_html( $rationale ) );
		}

		if ( array() !== $choices ) {
			foreach ( $choices as $choice ) {
				$value = is_scalar( $choice ) ? (string) $choice : '';
				// CONTENT BOUNDARY — each option label is MODEL-AUTHORED (S15), verbatim.
				printf(
					'<label class="senroflux-choice"><input type="radio" name="senroflux_answer_choice" value="%s"> %s</label>',
					esc_attr( $value ),
					esc_html( $value )
				);
			}

			if ( $other ) {
				echo '<label class="senroflux-choice"><input type="radio" name="senroflux_answer_choice" value="__other__" aria-controls="senroflux_answer_other">';
				echo esc_html__( 'Other', 'senroflux' );
				echo '</label>';
				// The "Other" textarea is harness chrome; it is required ONLY while
				// the Other radio is checked (enforced by JS; server re-validates).
				echo '<label class="senroflux-other-wrap"><span class="screen-reader-text">';
				echo esc_html__( 'Your answer', 'senroflux' );
				echo '</span>';
				echo '<textarea id="senroflux_answer_other" name="senroflux_answer_other" rows="2" placeholder="' . esc_attr__( 'Type your answer…', 'senroflux' ) . '"></textarea></label>';
			}
		} else {
			// No choices: textarea-only (S15).
			echo '<label for="senroflux_answer_text">' . esc_html__( 'Your answer', 'senroflux' ) . '</label>';
			echo '<textarea id="senroflux_answer_text" name="senroflux_answer_text" rows="3" required></textarea>';
		}

		echo '</fieldset>';

		echo '<p class="senroflux-card-actions">';
		printf(
			'<button type="submit" name="senroflux_answer_action" value="answer" class="button button-primary">%s</button>',
			esc_html__( 'Answer', 'senroflux' )
		);
		printf(
			'<button type="submit" name="senroflux_answer_action" value="skip" class="button">%s</button>',
			esc_html__( 'Skip', 'senroflux' )
		);
		echo '</p>';

		echo '</form>';
		echo '</section>';
	}

	/**
	 * The S7 plan park card.
	 *
	 * @param array<string,mixed> $state The run state.
	 */
	private function renderPlanCard( array $state ): void {
		$run  = $state['run'];
		$plan = $this->newestStepOfKind( $state, StepKind::Plan->value );
		if ( null === $plan || ! is_array( $plan['message'] ?? null ) ) {
			return;
		}

		$payload     = $plan['message'];
		$steps       = is_array( $payload['steps'] ?? null ) ? $payload['steps'] : array();
		$assumptions = is_array( $payload['assumptions'] ?? null ) ? $payload['assumptions'] : array();
		// The Runner computes S15 preapprove_availability from the filter + the
		// Agent Safety grants service; `Plugin::get()` does not carry ui, so the
		// screen recomputes the SAME condition (S14/S15).
		$preapprove = $this->preapprovalAvailable();

		$action = admin_url( 'admin-post.php' );
		echo '<section class="senroflux-park-card" aria-labelledby="senroflux-park-plan-heading">';

		echo '<h3 id="senroflux-park-plan-heading" class="senroflux-park-card-heading" tabindex="-1">';
		echo esc_html__( 'Approve plan', 'senroflux' );
		echo '</h3>';

		echo '<form method="post" action="' . esc_url( $action ) . '">';
		echo '<input type="hidden" name="action" value="senroflux_plan_decision">';
		echo '<input type="hidden" name="run_id" value="' . esc_attr( (string) $run['id'] ) . '">';
		echo '<input type="hidden" name="step_count" value="' . esc_attr( (string) $run['step_count'] ) . '">';
		wp_nonce_field( 'senroflux_plan_' . (int) $run['id'] );

		echo '<ol class="senroflux-plan-steps">';
		foreach ( $steps as $step ) {
			// CONTENT BOUNDARY — step text + verbs are MODEL-AUTHORED (S15),
			// verbatim. The "needs approval" marker is harness chrome (translated).
			$text  = (string) ( $step['text'] ?? '' );
			$verbs = is_array( $step['verbs'] ?? null ) ? $step['verbs'] : array();
			$needs = $this->stepNeedsApproval( $step );

			echo '<li>';
			echo '<span class="senroflux-plan-text">' . esc_html( $text ) . '</span>';
			if ( array() !== $verbs ) {
				echo '<span class="senroflux-plan-verbs">(' . esc_html( implode( ', ', array_map( 'strval', $verbs ) ) ) . ')</span>';
			}
			if ( $needs ) {
				echo ' <span class="senroflux-plan-approval">' . esc_html__( 'needs approval', 'senroflux' ) . '</span>';
			}
			echo '</li>';
		}
		echo '</ol>';

		if ( array() !== $assumptions ) {
			echo '<h4>' . esc_html__( 'Assumptions', 'senroflux' ) . '</h4>';
			echo '<ul class="senroflux-plan-assumptions">';
			foreach ( $assumptions as $assumption ) {
				// CONTENT BOUNDARY — assumption text is MODEL-AUTHORED (S15).
				echo '<li>' . esc_html( (string) $assumption ) . '</li>';
			}
			echo '</ul>';
		}

		echo '<fieldset class="senroflux-plan-decision">';
		echo '<legend>' . esc_html__( 'Decision', 'senroflux' ) . '</legend>';
		echo '<label class="senroflux-choice"><input type="radio" name="senroflux_plan_action" value="accept"> ' . esc_html__( 'Accept', 'senroflux' ) . '</label>';

		if ( $preapprove ) {
			echo '<label class="senroflux-choice"><input type="radio" name="senroflux_plan_action" value="accept_preapprove"> ' . esc_html__( 'Accept and pre-approve', 'senroflux' ) . '</label>';
			echo '<p class="description">' . esc_html__( 'Approve this plan and any irreversible steps in it without asking again.', 'senroflux' ) . '</p>';
		}

		echo '<label class="senroflux-choice"><input type="radio" name="senroflux_plan_action" value="veto"> ' . esc_html__( 'Veto', 'senroflux' ) . '</label>';
		echo '</fieldset>';

		echo '<label for="senroflux_plan_note">' . esc_html__( 'Note', 'senroflux' ) . '</label>';
		echo '<textarea id="senroflux_plan_note" name="senroflux_plan_note" rows="2"></textarea>';

		echo '<p class="senroflux-card-actions">';
		printf(
			'<button type="submit" class="button button-primary">%s</button>',
			esc_html__( 'Submit', 'senroflux' )
		);
		echo '</p>';

		echo '</form>';
		echo '</section>';
	}

	/**
	 * The S6 approval park card (inline approve/reject through the same tick).
	 *
	 * @param array<string,mixed> $state The run state.
	 */
	private function renderApprovalCard( array $state ): void {
		$run      = $state['run'];
		$approval = $this->newestStepOfKind( $state, StepKind::Approval->value );
		if ( null === $approval || ! is_array( $approval['message'] ?? null ) ) {
			return;
		}

		$payload = $approval['message'];
		$verb    = (string) ( $payload['verb'] ?? '' );
		$tier    = (int) ( $payload['tier'] ?? 0 );
		$args    = is_array( $payload['args'] ?? null ) ? $payload['args'] : array();

		$action = admin_url( 'admin-post.php' );
		echo '<section class="senroflux-park-card" aria-labelledby="senroflux-park-approval-heading">';

		echo '<h3 id="senroflux-park-approval-heading" class="senroflux-park-card-heading" tabindex="-1">';
		echo esc_html__( 'Approval needed', 'senroflux' );
		echo '</h3>';

		echo '<form method="post" action="' . esc_url( $action ) . '">';
		echo '<input type="hidden" name="action" value="senroflux_approval_decision">';
		echo '<input type="hidden" name="run_id" value="' . esc_attr( (string) $run['id'] ) . '">';
		echo '<input type="hidden" name="step_count" value="' . esc_attr( (string) $run['step_count'] ) . '">';
		wp_nonce_field( 'senroflux_approval_' . (int) $run['id'] );

		echo '<p><strong>' . esc_html__( 'Requested action', 'senroflux' ) . ':</strong> ';
		// CONTENT BOUNDARY — a verb is a machine-stable code string, NOT prose
		// and NOT model-authored in the i18n sense: render verbatim, never __().
		echo '<code>' . esc_html( $verb ) . '</code></p>';

		echo '<p><strong>' . esc_html__( 'Tier', 'senroflux' ) . ':</strong> ';
		echo esc_html( (string) $tier );
		echo '</p>';

		if ( array() !== $args ) {
			echo '<p><strong>' . esc_html__( 'Arguments', 'senroflux' ) . ':</strong></p>';
			echo '<pre class="senroflux-args">';
			// CONTENT BOUNDARY — args are tool payload data, rendered verbatim.
			echo esc_html( (string) wp_json_encode( $args, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			echo '</pre>';
		}

		echo '<p class="senroflux-card-actions">';
		printf(
			'<button type="submit" name="senroflux_approval_action" value="approve" class="button button-primary">%s</button>',
			esc_html__( 'Approve', 'senroflux' )
		);
		printf(
			'<button type="submit" name="senroflux_approval_action" value="reject" class="button">%s</button>',
			esc_html__( 'Reject', 'senroflux' )
		);
		echo '</p>';

		echo '</form>';
		echo '</section>';
	}

	/**
	 * The Agent Safety "Review pending" link for approval parks (S10).
	 *
	 * @param array<string,mixed> $state The run state.
	 */
	private function renderApprovalReviewLinks( array $state ): void {
		foreach ( $state['steps'] as $step ) {
			if ( StepKind::Approval->value === $step['kind'] && ! empty( $step['approval_id'] ) ) {
				printf(
					'<p class="senroflux-parked">%s <a href="%s">%s →</a></p>',
					esc_html__( 'This run awaits human approval.', 'senroflux' ),
					esc_url( admin_url( 'tools.php?page=agent-safety-pending' ) ),
					esc_html__( 'Review in Agent Safety', 'senroflux' )
				);

				return;
			}
		}
	}

	/**
	 * The harness-built terminal report (S12).
	 *
	 * @param array<string,mixed> $run The run state.
	 */
	private function renderReport( array $run ): void {
		$report = $run['report'] ?? null;
		if ( ! is_array( $report ) ) {
			return;
		}

		echo '<h3>' . esc_html__( 'Report', 'senroflux' ) . '</h3>';

		$summary = (string) ( $report['summary'] ?? '' );
		if ( '' !== $summary ) {
			// CONTENT BOUNDARY — report summary prose is MODEL-AUTHORED (S12/S15),
			// rendered verbatim, never __().
			echo '<div class="senroflux-report-summary"><p>' . esc_html( $summary ) . '</p></div>';
		}

		$changes = is_array( $report['changes'] ?? null ) ? $report['changes'] : array();
		if ( array() === $changes ) {
			echo '<p>' . esc_html__( 'No objects were written.', 'senroflux' ) . '</p>';

			return;
		}

		echo '<table class="widefat striped senroflux-report"><thead><tr>';
		foreach ( array( 'Type', 'Title', 'Status', 'Verified', 'Links' ) as $col ) {
			echo '<th>' . esc_html( $col ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $changes as $change ) {
			printf(
				'<tr><td>%1$s</td><td>%2$s</td><td>%3$s</td><td>%4$s</td><td>%5$s %6$s</td></tr>',
				esc_html( (string) ( $change['object_type'] ?? '' ) ),
				esc_html( (string) ( $change['title'] ?? '' ) ),
				esc_html( (string) ( $change['status'] ?? '' ) ),
				$this->renderVerifiedBadge( (bool) ( $change['verified'] ?? false ) ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the method escapes internally (esc_html__ text, hardcoded class names).
				( ! empty( $change['edit_url'] ) ) ? sprintf( '<a href="%s">%s</a>', esc_url( (string) $change['edit_url'] ), esc_html__( 'Edit', 'senroflux' ) ) : '',
				( ! empty( $change['preview_url'] ) ) ? sprintf( '<a href="%s">%s</a>', esc_url( (string) $change['preview_url'] ), esc_html__( 'Preview', 'senroflux' ) ) : ''
			);
		}

		echo '</tbody></table>';
	}

	/** A verified/unverified badge (harness chrome, translated). */
	private function renderVerifiedBadge( bool $verified ): string {
		if ( $verified ) {
			return '<span class="senroflux-badge senroflux-badge-verified">' . esc_html__( 'verified', 'senroflux' ) . '</span>';
		}

		return '<span class="senroflux-badge senroflux-badge-unverified">' . esc_html__( 'unverified', 'senroflux' ) . '</span>';
	}

	/**
	 * The step timeline (existing shape; data-attributes for the poller).
	 *
	 * @param array<string,mixed> $state The run state.
	 */
	private function renderStepsTimeline( array $state ): void {
		echo '<h3>' . esc_html__( 'Steps', 'senroflux' ) . '</h3>';
		echo '<ol class="senroflux-steps">';

		foreach ( $state['steps'] as $step ) {
			$label = sprintf(
				'#%d %s%s%s',
				$step['seq'],
				(string) $step['kind'],
				null !== $step['tool_name'] ? ' · ' . (string) $step['tool_name'] : '',
				'' !== (string) $step['status'] && 'ok' !== $step['status'] ? ' · ' . (string) $step['status'] : ''
			);

			$attrs = ' data-seq="' . esc_attr( (string) $step['seq'] ) . '"'
				. ' data-kind="' . esc_attr( (string) $step['kind'] ) . '"'
				. ' data-tool-name="' . esc_attr( (string) ( $step['tool_name'] ?? '' ) ) . '"'
				. ' data-status="' . esc_attr( (string) $step['status'] ) . '"';

			echo '<li class="senroflux-step senroflux-step-' . esc_attr( (string) $step['kind'] ) . '"' . $attrs . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $attrs is an attribute string whose every value is esc_attr'd at build time.
			echo '<strong>' . esc_html( $label ) . '</strong>';

			if ( null !== $step['message'] ) {
				echo '<details class="senroflux-json-toggle"><summary>' . esc_html__( 'JSON', 'senroflux' ) . '</summary><pre>';
				echo esc_html( (string) wp_json_encode( $step['message'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
				echo '</pre></details>';
			}

			echo '</li>';
		}

		echo '</ol>';
	}

	// ------------------------------------------------------------------
	// Small helpers
	// ------------------------------------------------------------------

	/**
	 * The newest step of a given kind (down the stored seq order).
	 *
	 * @param array<string,mixed> $state The run state.
	 * @param string              $kind  The step kind to match.
	 * @return array<string,mixed>|null The newest matching step, or null.
	 */
	private function newestStepOfKind( array $state, string $kind ): ?array {
		$steps = is_array( $state['steps'] ?? null ) ? $state['steps'] : array();
		for ( $i = count( $steps ) - 1; $i >= 0; --$i ) {
			if ( ( $steps[ $i ]['kind'] ?? null ) === $kind ) {
				return $steps[ $i ];
			}
		}

		return null;
	}

	/**
	 * Does a plan step need approval (any Tier-2 verb)?
	 *
	 * @param array<string,mixed> $step The plan step.
	 */
	private function stepNeedsApproval( array $step ): bool {
		$verbs = $step['verbs'] ?? array();
		if ( ! is_array( $verbs ) ) {
			return false;
		}

		// Fail closed: a verb whose tier is unknown is Tier 2, so a step with a
		// tiered verb is checked against the annotated tier first, then the map.
		foreach ( $verbs as $verb ) {
			if ( ! is_string( $verb ) ) {
				continue;
			}
			// `(int)` cast makes `is_int((int)$step['tier'])` always true, so the
			// guard is simply whether the tier is present (unchanged behaviour).
			$tier = isset( $step['tier'] ) ? (int) $step['tier'] : VerbTier::tierFor( $verb );
			if ( $tier >= VerbTier::TIER_2 ) {
				return true;
			}
		}

		return false;
	}

	/** Is pre-approval offerable on this screen? Mirrors Runner::preapprovalEnabled (S14). */
	private function preapprovalAvailable(): bool {
		if ( ! (bool) apply_filters( 'senroflux_enable_preapproval', false ) ) {
			return false;
		}

		return function_exists( 'agent_safety' ) && method_exists( agent_safety(), 'grants' );
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
