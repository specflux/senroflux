const { request } = require( '@playwright/test' );
const path = require( 'path' );
const { activateAgentSafety, deactivateAgentSafety, resetRuns, wpCli } = require( './wp-cli' );

const BASE_URL = process.env.SENROFLUX_E2E_BASE_URL || 'http://localhost:8895';

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
	if ( 'agent_safety' === gateMode ) {
		activateAgentSafety();
		wpCli( [ 'option', 'update', 'agsafe_pack_bindings', '{"role:administrator":"senroflux-e2e-pack","role:editor":"senroflux-e2e-pack"}', '--format=json' ] );
	} else {
		deactivateAgentSafety();
	}
	resetRuns();

	const target = storageStatePath || path.join( __dirname, `storage-state.${ gateMode }.json` );
	await loginAndSaveState( target );
	return target;
}

module.exports = { setupFor, BASE_URL, ADMIN_USER, ADMIN_PASS };
