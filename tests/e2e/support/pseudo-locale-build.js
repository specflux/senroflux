/**
 * S22 pseudo-locale pipeline, run from Playwright setup so local and CI take
 * the same path: `wp i18n make-pot` inside the wp-env cli container, the
 * `.pot` -> pseudo `.po` conversion on the HOST (no container round trip
 * needed for plain Node string work), then `wp i18n make-mo` +
 * `wp i18n make-json --no-purge` back inside the container. Everything
 * lands in `tests/e2e/pseudo-locale/build/` (gitignored, visible inside the
 * container through the existing `wp-content/plugins/senroflux` mount —
 * this whole path IS that mount, so no extra `.wp-env.json` entry is needed
 * for the build artifacts themselves, only for the mu-plugin below).
 */
const fs = require( 'fs' );
const path = require( 'path' );
const { wpCli, ROOT } = require( './wp-cli' );
const { potToPseudoPo } = require( '../pseudo-locale/pot-to-pseudo-po' );

const LOCALE = 'en_XA';
const CONTAINER_PLUGIN_DIR = '/var/www/html/wp-content/plugins/senroflux';
const BUILD_DIR = path.join( __dirname, '..', 'pseudo-locale', 'build' );
const CONTAINER_BUILD_DIR = `${ CONTAINER_PLUGIN_DIR }/tests/e2e/pseudo-locale/build`;

const POT_PATH = path.join( BUILD_DIR, 'senroflux.pot' );
const PO_PATH = path.join( BUILD_DIR, `senroflux-${ LOCALE }.po` );
const MERGED_JSON_PATH = path.join( BUILD_DIR, `senroflux-${ LOCALE }-merged.json` );

/** Merge every per-source-file Jed JSON `make-json` emitted into one file the mu-plugin always serves. */
function mergeJedJsonFiles() {
	const files = fs
		.readdirSync( BUILD_DIR )
		.filter( ( name ) => new RegExp( `^senroflux-${ LOCALE }-[0-9a-f]{32}\\.json$` ).test( name ) );

	if ( 0 === files.length ) {
		throw new Error( 'pseudo-locale setup: wp i18n make-json produced no per-source JSON files to merge' );
	}

	let header = null;
	const messages = {};
	for ( const name of files ) {
		const data = JSON.parse( fs.readFileSync( path.join( BUILD_DIR, name ), 'utf8' ) );
		const localeData = data.locale_data && data.locale_data.messages ? data.locale_data.messages : {};
		for ( const [ key, value ] of Object.entries( localeData ) ) {
			if ( '' === key ) {
				header = header || value;
				continue;
			}
			messages[ key ] = value;
		}
	}

	const merged = {
		'translation-revision-date': new Date().toISOString(),
		generator: 'senroflux-pseudo-locale-build',
		domain: 'messages',
		locale_data: {
			messages: {
				'': header || { domain: 'messages', lang: LOCALE, 'plural-forms': 'nplurals=2; plural=(n != 1);' },
				...messages,
			},
		},
	};

	fs.writeFileSync( MERGED_JSON_PATH, JSON.stringify( merged ), 'utf8' );
	return MERGED_JSON_PATH;
}

/**
 * Build (or rebuild) the full pseudo-locale artifact set. Idempotent: safe
 * to call at the start of every pseudo-locale spec run.
 */
function buildPseudoLocale() {
	fs.mkdirSync( BUILD_DIR, { recursive: true } );

	// 1. wp-cli make-pot audit stays ON (S22 translation gate, POT half) —
	// this is a plain generation call for the pseudo-locale build, not the
	// CI audit step, so no --skip-audit flag either way.
	wpCli( [
		'i18n', 'make-pot', CONTAINER_PLUGIN_DIR, `${ CONTAINER_BUILD_DIR }/senroflux.pot`,
		'--exclude=tests,vendor,node_modules,build,research',
	] );

	// 2. Host-side conversion — no container round trip needed for plain
	// string transformation.
	const potContent = fs.readFileSync( POT_PATH, 'utf8' );
	const { content, count } = potToPseudoPo( potContent, LOCALE );
	fs.writeFileSync( PO_PATH, content, 'utf8' );

	// 3 + 4. Compile to .mo (PHP) and per-file .json (JS), back in the container.
	wpCli( [ 'i18n', 'make-mo', `${ CONTAINER_BUILD_DIR }/senroflux-${ LOCALE }.po`, CONTAINER_BUILD_DIR ] );
	wpCli( [ 'i18n', 'make-json', `${ CONTAINER_BUILD_DIR }/senroflux-${ LOCALE }.po`, CONTAINER_BUILD_DIR, '--no-purge' ] );

	const mergedPath = mergeJedJsonFiles();

	return { entryCount: count, poPath: PO_PATH, moPath: path.join( BUILD_DIR, `senroflux-${ LOCALE }.mo` ), jsonPath: mergedPath };
}

module.exports = { buildPseudoLocale, LOCALE, BUILD_DIR, ROOT };
