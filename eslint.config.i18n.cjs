/**
 * Translation gate (S22): checks ONLY the `@wordpress/i18n-*` rules against
 * the plugin's own text domain. Deliberately does not extend any of
 * `@wordpress/eslint-plugin`'s style/recommended presets — pulling those in
 * here would turn this gate into a codebase-wide JS style cleanup instead of
 * the translation check it is meant to be.
 */
const wpPlugin = require( '@wordpress/eslint-plugin' );
const reactHooksPlugin = require( 'eslint-plugin-react-hooks' );

module.exports = [
	{
		files: [ 'assets/src/**/*.js' ],
		plugins: {
			'@wordpress': wpPlugin,
			// Registered (with no rules enabled) only so this file's existing
			// `// eslint-disable-line react-hooks/exhaustive-deps` comments
			// resolve to a real rule instead of erroring as unknown — this
			// gate does not enable react-hooks/exhaustive-deps itself.
			'react-hooks': reactHooksPlugin,
		},
		languageOptions: {
			ecmaVersion: 'latest',
			sourceType: 'module',
			parserOptions: {
				ecmaFeatures: { jsx: true },
			},
		},
		rules: {
			'@wordpress/i18n-translator-comments': 'error',
			'@wordpress/i18n-text-domain': [ 'error', { allowedTextDomain: 'senroflux' } ],
			'@wordpress/i18n-no-collapsible-whitespace': 'error',
			'@wordpress/i18n-no-placeholders-only': 'error',
			'@wordpress/i18n-no-variables': 'error',
			'@wordpress/i18n-ellipsis': 'error',
			'@wordpress/i18n-no-flanking-whitespace': 'error',
			'@wordpress/i18n-hyphenated-range': 'error',
		},
	},
];
