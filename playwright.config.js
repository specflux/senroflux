// @ts-check
const { defineConfig, devices } = require( '@playwright/test' );
const path = require( 'path' );

/**
 * 0.3 S10/S12/S22: end-to-end suite against this worktree's OWN wp-env
 * instance (see .wp-env.json — its own ports, never the shared instance on
 * :8888). Driven entirely by the deterministic fake provider in
 * tests/e2e/fake-provider/, never a live model.
 *
 * Two "setup" projects (Playwright's project-dependency pattern — a
 * per-project `globalSetup` is NOT a thing Playwright supports) each
 * activate/deactivate the Agent Safety plugin and log in BEFORE their
 * dependent project's specs run, so a run started under a project is pinned
 * to that project's gate mode for its whole life (gate mode is stored per
 * run — S3).
 */
const BASE_URL = process.env.SENROFLUX_E2E_BASE_URL || 'http://localhost:8895';

module.exports = defineConfig( {
	testDir: './tests/e2e',
	fullyParallel: false,
	workers: 1,
	forbidOnly: !! process.env.CI,
	retries: 0,
	reporter: [ [ 'list' ] ],
	timeout: 45000,
	use: {
		baseURL: BASE_URL,
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
	},
	projects: [
		{
			name: 'setup-built-in',
			testMatch: /built-in\.setup\.js/,
		},
		{
			name: 'setup-agent-safety',
			testMatch: /agent-safety\.setup\.js/,
			// Chained AFTER the built-in project, not merely before its own.
			// Both gate modes share one WordPress install, and each setup
			// activates or deactivates Agent Safety globally. Without this,
			// Playwright runs both setup projects back to back, the Agent
			// Safety one wins, and every built-in spec then runs against the
			// wrong gate — each project passes alone and the full suite is
			// red. This forces: setup-built-in → built-in → setup-agent-safety
			// → agent-safety.
			dependencies: [ 'built-in' ],
		},
		{
			name: 'built-in',
			testDir: './tests/e2e/specs',
			testMatch: /.*\.spec\.js/,
			testIgnore: /.*\.as-mode\.spec\.js/,
			dependencies: [ 'setup-built-in' ],
			use: {
				...devices[ 'Desktop Chrome' ],
				storageState: path.join( __dirname, 'tests/e2e/support/storage-state.built_in.json' ),
			},
		},
		{
			name: 'agent-safety',
			testDir: './tests/e2e/specs',
			testMatch: /.*\.as-mode\.spec\.js/,
			dependencies: [ 'setup-agent-safety' ],
			use: {
				...devices[ 'Desktop Chrome' ],
				storageState: path.join( __dirname, 'tests/e2e/support/storage-state.agent_safety.json' ),
			},
		},
	],
} );
