<?php
/**
 * SenroFlux → Settings: the site brief (0.3 S20).
 *
 * TARGET REPO PATH: src/Admin/SettingsScreen.php
 *
 * A submenu page under the existing top-level "SenroFlux" menu
 * ({@see RunsScreen}), capability `manage_options`. `[assumed]` (S20): a
 * plain admin-post form with a nonce, matching every other mutation on the
 * Runs screen, rather than the full `register_setting()`/`options.php`
 * machinery — the load-bearing behaviour S20 asks for (submenu placement,
 * the capability, a nonce, the length cap refusing rather than truncating,
 * and clearing on empty) is identical either way, and this keeps one human
 * mutation pattern across the plugin. _Overturn: move to `register_setting()`
 * if a stranger walkthrough expects the native Settings UI chrome._
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Admin;

use Specflux\SenroFlux\Run\SiteBrief;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Renders + saves the site brief.
 */
final class SettingsScreen {

	private const SLUG = 'senroflux-settings';

	/** Transient holding a REFUSED submission's text, keyed by user id (never truncated, never lost). */
	private const PENDING_TRANSIENT_PREFIX = 'senroflux_brief_pending_';

	/** Register on admin_menu + admin_post. */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_senroflux_save_brief', array( $this, 'handleSave' ) );
	}

	/** The capability required for this screen (S20: fixed at manage_options). */
	public function capability(): string {
		return 'manage_options';
	}

	/** The "Settings" submenu under the existing top-level "SenroFlux" menu. */
	public function menu(): void {
		add_submenu_page(
			'senroflux-runs',
			__( 'SenroFlux Settings', 'senroflux' ),
			__( 'Settings', 'senroflux' ),
			$this->capability(),
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/** admin-post handler: save (or clear) the site brief. */
	public function handleSave(): void {
		check_admin_referer( 'senroflux_save_brief' );

		if ( ! current_user_can( $this->capability() ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'senroflux' ) );
		}

		// Plain text (S20): stripped of markup, but newlines kept — a
		// textarea field, not a single-line input.
		$text = sanitize_textarea_field( wp_unslash( $_POST['senroflux_site_brief'] ?? '' ) );

		$saved = SiteBrief::set( $text );
		$user  = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;

		if ( is_wp_error( $saved ) ) {
			// Refused: the text is kept in the form, never truncated, never
			// silently reverted to the last-saved value.
			set_transient( self::PENDING_TRANSIENT_PREFIX . $user, $text, MINUTE_IN_SECONDS );
			$this->redirect( (string) $saved->get_error_code() );
			return;
		}

		delete_transient( self::PENDING_TRANSIENT_PREFIX . $user );
		$this->redirect( null );
	}

	/** The one place a redirect-then-stop goes through (test override seam). */
	protected function redirect( ?string $error_code ): void {
		$url = admin_url( 'admin.php?page=' . self::SLUG );
		if ( null !== $error_code ) {
			$url = add_query_arg( array( 'senroflux_brief_error' => $error_code ), $url );
		} else {
			$url = add_query_arg( array( 'senroflux_brief_saved' => '1' ), $url );
		}
		wp_safe_redirect( $url );
		exit;
	}

	/** The text this form should show: a pending refused submission, else the saved brief. */
	private function formText(): string {
		$user    = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		$pending = get_transient( self::PENDING_TRANSIENT_PREFIX . $user );

		return is_string( $pending ) ? $pending : SiteBrief::get();
	}

	/** Render the settings page. */
	public function render(): void {
		if ( ! current_user_can( $this->capability() ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'senroflux' ) );
		}

		echo '<div class="wrap"><h1>' . esc_html__( 'SenroFlux Settings', 'senroflux' ) . '</h1>';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flash.
		$error = isset( $_GET['senroflux_brief_error'] ) ? sanitize_text_field( wp_unslash( $_GET['senroflux_brief_error'] ) ) : '';
		if ( SiteBrief::ERROR_TOO_LONG === $error ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %d is the character cap. */
						__( 'The site brief may be at most %d characters. Nothing was saved — shorten it and try again.', 'senroflux' ),
						SiteBrief::MAX_CHARS
					)
				)
			);
		} elseif ( '' !== $error ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'The site brief could not be saved.', 'senroflux' ) . '</p></div>';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flash.
		if ( isset( $_GET['senroflux_brief_saved'] ) ) {
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Site brief saved.', 'senroflux' ) . '</p></div>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="senroflux_save_brief">';
		wp_nonce_field( 'senroflux_save_brief' );

		echo '<p>' . esc_html__( 'The site owner\'s standing notes for SenroFlux: tone and content guidance every run reads. It never changes what needs approval, budgets or the plan.', 'senroflux' ) . '</p>';
		echo '<p><label for="senroflux-site-brief" class="screen-reader-text">' . esc_html__( 'Site brief', 'senroflux' ) . '</label>';
		printf(
			'<textarea id="senroflux-site-brief" name="senroflux_site_brief" rows="10" class="large-text code" maxlength="%d">%s</textarea></p>',
			esc_attr( (string) ( SiteBrief::MAX_CHARS * 4 ) ), // A generous client-side ceiling; the server refusal is authoritative (multi-byte chars).
			esc_textarea( $this->formText() )
		);

		printf(
			'<p><button type="submit" class="button button-primary">%s</button></p>',
			esc_html__( 'Save brief', 'senroflux' )
		);

		echo '</form></div>';
	}
}
