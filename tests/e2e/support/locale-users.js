/**
 * S22 (RTL + pseudo-locale specs): both specs need a dedicated user whose
 * USER-level locale differs from the site's, so the site locale never
 * changes and no other spec is affected. Shared here so both specs create
 * users the same idempotent way, safe to run repeatedly against a wp-env
 * instance that persists across local test runs (unlike CI's cold boot).
 */
const { request } = require( '@playwright/test' );
const { wpCli } = require( './wp-cli' );

const BASE_URL = process.env.SENROFLUX_E2E_BASE_URL || 'http://localhost:8895';
const USER_PASSWORD = 'password';

/** Create the user if it does not already exist; return its numeric ID. */
function ensureUser( login, email, role ) {
	try {
		return wpCli( [ 'user', 'get', login, '--field=ID' ] ).trim();
	} catch {
		return wpCli( [
			'user', 'create', login, email,
			`--role=${ role }`,
			`--user_pass=${ USER_PASSWORD }`,
			'--porcelain',
		] ).trim();
	}
}

/** `wp language core install` is already idempotent (skips if present). */
function ensureLanguageInstalled( locale ) {
	wpCli( [ 'language', 'core', 'install', locale ] );
}

/** Set only this user's own locale meta — never the site locale. */
function setUserLocale( userId, locale ) {
	wpCli( [ 'user', 'meta', 'update', userId, 'locale', locale ] );
}

/**
 * Ensure a dedicated user exists with the given locale as its OWN
 * `locale` user-meta, and return its login/password for logging in.
 *
 * @param {string} login  wp-cli username.
 * @param {string} email  wp-cli email.
 * @param {string} locale Locale code, e.g. 'ar' or 'en_XA'.
 * @param {string} [role] Defaults to 'administrator'.
 */
/**
 * @param {string}  login
 * @param {string}  email
 * @param {string}  locale             Locale code, e.g. 'ar' or 'en_XA'.
 * @param {string}  [role]             Defaults to 'administrator'.
 * @param {boolean} [installCoreLanguage] Defaults to true. `en_XA` is a
 *                                     pseudo-locale, not a real WordPress
 *                                     core translation — `wp language core
 *                                     install` would fail for it, and it is
 *                                     not needed anyway: `determine_locale()`
 *                                     returns whatever raw string is in the
 *                                     user's `locale` meta, valid installed
 *                                     translation or not.
 */
function ensureLocaleUser( login, email, locale, role = 'administrator', installCoreLanguage = true ) {
	if ( installCoreLanguage && 'en_US' !== locale ) {
		ensureLanguageInstalled( locale );
	}
	const userId = ensureUser( login, email, role );
	setUserLocale( userId, locale );
	return { userId, login, password: USER_PASSWORD };
}

/** Log the given user in and persist storage state to `storageStatePath`. */
async function loginAs( login, password, storageStatePath ) {
	const ctx = await request.newContext( { baseURL: BASE_URL } );
	await ctx.post( '/wp-login.php', {
		form: {
			log: login,
			pwd: password,
			'wp-submit': 'Log In',
			redirect_to: `${ BASE_URL }/wp-admin/`,
			testcookie: '1',
		},
	} );
	await ctx.storageState( { path: storageStatePath } );
	await ctx.dispose();
}

module.exports = { ensureLocaleUser, loginAs, BASE_URL };
