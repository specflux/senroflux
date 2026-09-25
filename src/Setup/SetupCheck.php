<?php
/**
 * One setup-check result (0.3 S11).
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Setup;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * A namespaced setup check, resolved at evaluation time.
 *
 * The SAME object serves both the setup panel (which renders a fix link or a
 * blocked sentence, chosen by testing {@see fixCapability()} against the
 * viewer — S11) and the harness's own preflight refusal (which needs an error
 * code + a WP_Error-shaped message — S3). Immutable value object: a contributor
 * builds one per check per evaluation, never mutates one afterwards.
 */
final class SetupCheck {

	/** A check that must pass before a run may start. */
	public const BLOCKING = 'blocking';

	/** A check that is informative only and never blocks a start. */
	public const ADVISORY = 'advisory';

	/**
	 * @param string      $id               Namespaced id, e.g. `pages/capability`.
	 * @param string      $severity         {@see self::BLOCKING} or {@see self::ADVISORY}.
	 * @param bool        $passed           The resolved status for THIS evaluation.
	 * @param string      $fix_message      Shown to a viewer who can act on the fix.
	 * @param string|null $fix_url          Where the fix lives, or null when there is none to link.
	 * @param string|null $fix_capability   The capability {@see $fix_url} needs; null means
	 *                                      "nobody but SenroFlux itself can fix this" (no viewer
	 *                                      ever qualifies for the fix message).
	 * @param string      $blocked_message  Shown to a viewer who cannot act on the fix.
	 * @param string      $error_code       The WP_Error code {@see \Specflux\SenroFlux\Packs\Pack::preflight()}
	 *                                      raises when this check is the reason a run refused to start.
	 */
	public function __construct(
		private readonly string $id,
		private readonly string $severity,
		private readonly bool $passed,
		private readonly string $fix_message,
		private readonly ?string $fix_url = null,
		private readonly ?string $fix_capability = null,
		private readonly string $blocked_message = '',
		private readonly string $error_code = ''
	) {
	}

	public function id(): string {
		return $this->id;
	}

	public function severity(): string {
		return $this->severity;
	}

	public function isBlocking(): bool {
		return self::BLOCKING === $this->severity;
	}

	public function passed(): bool {
		return $this->passed;
	}

	public function fixMessage(): string {
		return $this->fix_message;
	}

	public function fixUrl(): ?string {
		return $this->fix_url;
	}

	public function fixCapability(): ?string {
		return $this->fix_capability;
	}

	public function blockedMessage(): string {
		return '' !== $this->blocked_message ? $this->blocked_message : $this->fix_message;
	}

	public function errorCode(): string {
		return '' !== $this->error_code ? $this->error_code : 'senroflux_setup_check_failed';
	}

	/**
	 * The message to show ONE viewer: the fix message when they hold
	 * {@see fixCapability()}, otherwise the blocked message. A null
	 * `fix_capability` means no viewer ever qualifies for the fix message
	 * (S11: "SenroFlux writes no other plugin's or core's options").
	 *
	 * @param int $user_id The viewer.
	 */
	public function messageFor( int $user_id ): string {
		if ( null !== $this->fix_capability
			&& function_exists( 'user_can' )
			&& user_can( $user_id, $this->fix_capability )
		) {
			return $this->fix_message;
		}

		return $this->blockedMessage();
	}

	/**
	 * Whether the fix link/message should render for this viewer at all — the
	 * same test {@see messageFor()} uses, exposed for panel rendering that
	 * needs the URL too (a blocked viewer never gets one, S11: "no link").
	 *
	 * @param int $user_id The viewer.
	 */
	public function fixVisibleFor( int $user_id ): bool {
		return null !== $this->fix_capability
			&& function_exists( 'user_can' )
			&& user_can( $user_id, $this->fix_capability );
	}
}
