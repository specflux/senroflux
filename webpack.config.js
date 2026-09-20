/**
 * Extends the @wordpress/scripts default webpack config with a single entry
 * for the Runs screen (S10/S17): `assets/src/runs/index.js` -> `build/runs/`.
 *
 * The default config's `entry`/`output` are replaced (not merged) so the
 * dev-only editor-parity harness (`tests/editor/`, run through plain
 * `node --test`, never webpack) is untouched.
 */

const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const path = require( 'path' );

module.exports = {
	...defaultConfig,
	entry: {
		index: path.resolve( __dirname, 'assets/src/runs/index.js' ),
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, 'build/runs' ),
	},
};
