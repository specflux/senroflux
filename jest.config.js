/**
 * Extends @wordpress/scripts' default jest-unit config with jest-dom's
 * matchers (`toBeInTheDocument()` etc.), used by the Runs screen's tests
 * under `assets/src/runs/__tests__/` (S10/S17). `test:editor`
 * (`tests/editor/*.test.cjs`) runs under plain `node --test`, never jest, so
 * this file has no effect on it.
 */

const defaultConfig = require( '@wordpress/scripts/config/jest-unit.config' );

module.exports = {
	...defaultConfig,
	setupFilesAfterEnv: [
		...( defaultConfig.setupFilesAfterEnv || [] ),
		'<rootDir>/assets/src/runs/jest.setup.js',
	],
};
