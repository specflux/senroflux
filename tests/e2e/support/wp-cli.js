/**
 * Thin wrapper around `npx @wordpress/env run cli wp ...` so specs and
 * fixtures can drive the isolated wp-env instance this worktree owns
 * (its own .wp-env.json, its own ports — never the shared instance on
 * :8888/:8889 mapped from the parent directory's .wp-env.override.json).
 *
 * IMPORTANT: every call here MUST run with cwd = the plugin root (this
 * worktree), never the parent `automattic-hire-plan/` directory, or wp-env
 * would resolve the SHARED project instead of this one.
 */
const { execFileSync } = require( 'child_process' );
const path = require( 'path' );

const ROOT = path.resolve( __dirname, '..', '..', '..' );

/** Run one `wp` command inside this worktree's own wp-env container. */
function wpCli( args, { env = 'cli' } = {} ) {
	return execFileSync(
		path.join( ROOT, 'node_modules', '.bin', 'wp-env' ),
		[ 'run', env, 'wp', ...args ],
		{ cwd: ROOT, encoding: 'utf8', stdio: [ 'ignore', 'pipe', 'pipe' ] }
	);
}

/** Replace the fake provider's script queue and reset its call log. */
function setScript( steps ) {
	const json = JSON.stringify( steps );
	wpCli( [ 'option', 'update', 'senroflux_e2e_script', json ] );
	wpCli( [ 'option', 'delete', 'senroflux_e2e_calls' ] );
}

/** Wipe every run/step row and the e2e-only options so each spec starts clean. */
function resetRuns() {
	wpCli( [ 'db', 'query', 'TRUNCATE TABLE wp_senroflux_runs' ] );
	wpCli( [ 'db', 'query', 'TRUNCATE TABLE wp_senroflux_steps' ] );
	wpCli( [ 'option', 'delete', 'senroflux_e2e_script' ] );
	wpCli( [ 'option', 'delete', 'senroflux_e2e_calls' ] );
	wpCli( [ 'option', 'delete', 'senroflux_e2e_things_created' ] );
	wpCli( [ 'option', 'delete', 'senroflux_site_brief' ] );
}

function activateAgentSafety() {
	wpCli( [ 'plugin', 'activate', 'agent-safety' ] );
}

function deactivateAgentSafety() {
	wpCli( [ 'plugin', 'deactivate', 'agent-safety' ] );
}

/** Idempotent: `wp plugin activate` on an already-active plugin is a no-op. */
function activatePlugin( slug ) {
	wpCli( [ 'plugin', 'activate', slug ] );
}

/** @return {boolean} */
function isPluginActive( slug ) {
	try {
		wpCli( [ 'plugin', 'is-active', slug ] );
		return true;
	} catch {
		return false;
	}
}

/** @return {boolean} */
function tableExists( name ) {
	const rows = wpCli( [ 'db', 'query', `SHOW TABLES LIKE '${ name }'`, '--skip-column-names' ] ).trim();
	return rows === name;
}

module.exports = {
	wpCli,
	setScript,
	resetRuns,
	activateAgentSafety,
	deactivateAgentSafety,
	activatePlugin,
	isPluginActive,
	tableExists,
	ROOT,
};
