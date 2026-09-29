<?php
/**
 * S22 gate robustness suite: "a forged approval nonce" and "a replayed
 * approval nonce".
 *
 * Both cases are marked INCOMPLETE, not skipped and not vacuously passed:
 * this repo's bare-PHPUnit harness cannot exercise WordPress's actual nonce
 * verification at all, forged or replayed.
 *
 *  - `check_admin_referer()` (every admin-post approval/plan/answer/cancel/
 *    suggestion handler in {@see \Specflux\SenroFlux\Admin\RunsScreen} and
 *    {@see \Specflux\SenroFlux\Admin\SettingsScreen}) is a hard no-op that
 *    unconditionally returns true — see tests/bootstrap.php's
 *    `check_admin_referer()` shim.
 *  - `check_ajax_referer()` (every `Ajax::handle*()` method) is likewise a
 *    hard no-op returning true — see tests/stubs/http.php's
 *    `check_ajax_referer()` shim.
 *  - `wp_create_nonce()`/`wp_nonce_field()` (tests/stubs/admin.php) are
 *    deterministic (`'test-' . md5($action)`), which would make a "forged"
 *    nonce trivially reproducible in-process, but since verification is a
 *    no-op the forged/genuine distinction is never actually checked either
 *    way — the shim always says "valid".
 *
 * None of these shims record what action string or token they were called
 * with, and the guard `function_exists()` around each one in the shared
 * bootstrap means a test file cannot redeclare them either (PHP does not
 * allow redeclaring a function). There is therefore no seam in this test
 * harness — short of adding a REAL nonce implementation to the bootstrap,
 * which is shared, production-adjacent test infrastructure and out of scope
 * for one adversarial-suite stage — through which a forged or replayed
 * nonce could be told apart from a genuine one.
 *
 * The nonce-VERIFICATION behaviour itself is WordPress core's, not this
 * plugin's, and is exercised by WordPress's own test suite; what this repo
 * owns and could still prove — that every mutating admin-post/admin-ajax
 * handler CALLS a referer check before acting — is already asserted
 * structurally by reading the handler bodies (see the docblocks on
 * {@see \Specflux\SenroFlux\Admin\RunsScreen::handleApprovalDecision()} and
 * {@see \Specflux\SenroFlux\Http\Ajax::handleTick()}), not by a PHPUnit
 * assertion, since the call site itself is unconditional and syntactic,
 * not data-driven.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Adversarial;

use PHPUnit\Framework\TestCase;

final class ApprovalNonceTest extends TestCase {

	public function test_a_forged_approval_nonce_is_refused(): void {
		$this->markTestIncomplete(
			'Cannot be exercised at the unit level: check_admin_referer() (RunsScreen ' .
			'admin-post handlers) and check_ajax_referer() (Ajax handlers) are hard ' .
			'no-ops that unconditionally return true in this suite\'s shared bootstrap ' .
			'(tests/bootstrap.php, tests/stubs/http.php) — real nonce verification, ' .
			'forged or genuine, is never actually performed under bare PHPUnit. ' .
			'Requires either a real WordPress nonce implementation in the harness or ' .
			'an integration-level test (e.g. wp-env) to exercise for real.'
		);
	}

	public function test_a_replayed_approval_nonce_is_refused(): void {
		$this->markTestIncomplete(
			'Same limitation as the forged-nonce case: check_admin_referer()/' .
			'check_ajax_referer() are unconditional no-ops in this suite\'s shared ' .
			'bootstrap, so a nonce\'s one-time-use semantics (WordPress core\'s own ' .
			'`_wp_nonce_tick` + option/user-meta bookkeeping) are not modelled at all ' .
			'— there is nothing here that could distinguish a first use from a replay. ' .
			'Requires an integration-level test (e.g. wp-env) against real WordPress.'
		);
	}
}
