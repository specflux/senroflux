const { request } = require( '@playwright/test' );
const path = require( 'path' );
const {
	activateAgentSafety,
	deactivateAgentSafety,
	activatePlugin,
	isPluginActive,
	tableExists,
	resetRuns,
	wpCli,
} = require( './wp-cli' );

const BASE_URL = process.env.SENROFLUX_E2E_BASE_URL || 'http://localhost:8895';

/**
 * Every plugin the suite needs active regardless of gate mode. A cold
 * wp-env mounts these but leaves them inactive, so senroflux's activation
 * hook (which creates wp_senroflux_runs/steps) never runs and the first
 * spec dies deep inside an unrelated SQL error instead of at setup.
 */
const ALWAYS_ACTIVE_PLUGINS = [ 'abilities-api', 'mcp-adapter', 'senroflux' ];

/** Fail setup itself, with a clear cause, instead of leaving it to a spec. */
function assertEnvironmentReady() {
	for ( const slug of ALWAYS_ACTIVE_PLUGINS ) {
		if ( ! isPluginActive( slug ) ) {
			throw new Error( `e2e setup: plugin "${ slug }" did not report active after activation` );
		}
	}
	if ( ! tableExists( 'wp_senroflux_runs' ) ) {
		throw new Error( 'e2e setup: wp_senroflux_runs table is missing; senroflux activation hook did not run' );
	}
}

/** wp-env's default admin credentials. */
const ADMIN_USER = 'admin';
const ADMIN_PASS = 'password';

async function loginAndSaveState( storageStatePath ) {
	const ctx = await request.newContext( { baseURL: BASE_URL } );
	await ctx.post( '/wp-login.php', {
		form: {
			log: ADMIN_USER,
			pwd: ADMIN_PASS,
			'wp-submit': 'Log In',
			redirect_to: `${ BASE_URL }/wp-admin/`,
			testcookie: '1',
		},
	} );
	await ctx.storageState( { path: storageStatePath } );
	await ctx.dispose();
}

/**
 * Shared setup for both gate-mode projects: bind the e2e admin user to the
 * e2e fixture pack (needed only when Agent Safety is active, harmless
 * otherwise), reset run/step state, and log in once so specs reuse the
 * saved storage state instead of re-authenticating per test.
 *
 * @param {'built_in'|'agent_safety'} gateMode
 */
async function setupFor( gateMode, storageStatePath ) {
	for ( const slug of ALWAYS_ACTIVE_PLUGINS ) {
		activatePlugin( slug );
	}

	if ( 'agent_safety' === gateMode ) {
		activateAgentSafety();
		wpCli( [ 'option', 'update', 'agsafe_pack_bindings', '{"role:administrator":"senroflux-e2e-pack","role:editor":"senroflux-e2e-pack"}', '--format=json' ] );
	} else {
		deactivateAgentSafety();
	}

	assertEnvironmentReady();
	resetRuns();

	const target = storageStatePath || path.join( __dirname, `storage-state.${ gateMode }.json` );
	await loginAndSaveState( target );
	return target;
}

module.exports = { setupFor, BASE_URL, ADMIN_USER, ADMIN_PASS };
