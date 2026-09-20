/**
 * Extends the @wordpress/scripts default webpack config with two entries
 * (S10/S17): the Runs screen (`assets/src/runs/index.js` -> `build/runs/`)
 * and the command palette registration (`assets/src/commands/index.js` ->
 * `build/commands/`, 17d). Each gets its OWN output directory (a webpack
 * multi-config array, not a single config with a combined entry map) so
 * `build/runs/index.js` and `build/commands/index.js` keep the exact paths
 * `RunsScreen.php` already enqueues from.
 *
 * The default config's `entry`/`output` are replaced (not merged) so the
 * dev-only editor-parity harness (`tests/editor/`, run through plain
 * `node --test`, never webpack) is untouched.
 */

const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const path = require( 'path' );

const runsConfig = {
	...defaultConfig,
	entry: {
		index: path.resolve( __dirname, 'assets/src/runs/index.js' ),
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, 'build/runs' ),
	},
};

const commandsConfig = {
	...defaultConfig,
	entry: {
		index: path.resolve( __dirname, 'assets/src/commands/index.js' ),
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, 'build/commands' ),
	},
};

module.exports = [ runsConfig, commandsConfig ];
