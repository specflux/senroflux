<?php
/**
 * Tools → SenroFlux Runs: the observation screen (S10).
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Admin;

use Specflux\SenroFlux\Plugin;
use Specflux\SenroFlux\Run\RunStatus;
use Specflux\SenroFlux\Run\StepKind;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Read-mostly: the list + step timeline exist to SEE what a run did; approval
 * decisions live with Agent Safety (S10 — never duplicate them here). The one
 * mutation offered is Cancel, for runs still in flight.
 */
class RunsScreen {

	private const SLUG = 'senroflux-runs';

	/** Register on admin_menu (+ assets). */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_post_senroflux_cancel_run', array( $this, 'handleCancel' ) );
	}

	/**
	 * The capability that unlocks the screen; filterable per S10.
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

	/** Enqueue the (minimal) screen assets only on this page. */
	public function assets( string $hook ): void {
		if ( ! str_contains( $hook, self::SLUG ) ) {
			return;
		}

		wp_enqueue_style( 'senroflux-runs', SENROFLUX_URL . 'assets/runs.css', array(), '0.1.0' );
		wp_enqueue_script( 'senroflux-runs', SENROFLUX_URL . 'assets/runs.js', array(), '0.1.0', true );
		wp_localize_script(
			'senroflux-runs',
			'senrofluxRuns',
			array(
				'cancelConfirm' => __( 'Cancel this run?', 'senroflux' ),
			)
		);
	}

	/**
	 * admin-post endpoint backing the Cancel button: nonce + capability +
	 * ownership all re-checked through the PHP API, then redirect back.
	 */
	public function handleCancel(): void {
		$run_id = isset( $_GET['run_id'] ) ? absint( $_GET['run_id'] ) : 0;
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
	 * Redirect to the detail view and stop processing. Split out so tests can
	 * observe the target WITHOUT killing the PHPUnit process (exit).
	 */
	protected function redirectBack( int $run_id ): void {
		wp_safe_redirect( admin_url( 'tools.php?page=' . self::SLUG . '&run_id=' . $run_id ) );

		exit;
	}

	/** Render list or detail. */
	public function render(): void {
		if ( ! current_user_can( $this->capability() ) ) {
			return;
		}

		$run_id = isset( $_GET['run_id'] ) ? absint( wp_unslash( $_GET['run_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list navigation.

		echo '<div class="wrap">';
		printf( '<h1>%s</h1>', esc_html__( 'SenroFlux Runs', 'senroflux' ) );

		if ( $run_id > 0 ) {
			$this->renderDetail( $run_id );
		} else {
			$this->renderList();
		}

		echo '</div>';
	}

	/** The run list table. */
	private function renderList(): void {
		$runs = senroflux()->available() ? senroflux()->listRecent() : array();

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

	/** One run: timeline + cancel. */
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

		printf(
			'<h2>%s <span class="senroflux-badge senroflux-badge-%s">%s</span></h2>',
			esc_html( (string) $run['goal'] ),
			esc_attr( (string) $run['status'] ),
			esc_html( (string) $run['status'] )
		);

		if ( ! empty( $run['error'] ) && is_array( $run['error'] ) ) {
			printf(
				'<div class="notice notice-error inline"><p><strong>%s</strong> %s</p></div>',
				esc_html( (string) ( $run['error']['code'] ?? '' ) ),
				esc_html( (string) ( $run['error']['message'] ?? '' ) )
			);
		}

		// Review link for parked runs: approvals live with Agent Safety (S10).
		if ( RunStatus::AwaitingApproval === RunStatus::tryFrom( (string) $run['status'] ) ) {
			foreach ( $state['steps'] as $step ) {
				if ( StepKind::Approval->value === $step['kind'] && ! empty( $step['message']['approval_id'] ) ) {
					printf(
						'<p class="senroflux-parked">%s <a href="%s">%s →</a></p>',
						esc_html__( 'This run awaits human approval.', 'senroflux' ),
						esc_url( admin_url( 'tools.php?page=agent-safety-pending' ) ),
						esc_html__( 'Review in Agent Safety', 'senroflux' )
					);

					break;
				}
			}
		}

		// Cancel for anything still in flight.
		$status = RunStatus::tryFrom( (string) $run['status'] );
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

			echo '<li class="senroflux-step senroflux-step-' . esc_attr( (string) $step['kind'] ) . '">';
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
}
