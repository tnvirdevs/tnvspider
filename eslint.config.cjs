/**
 * ESLint: the `@wordpress/scripts` defaults, plus the WordPress packages that
 * are runtime externals (provided by WordPress, not installed with npm).
 */
const defaults = require( '@wordpress/scripts/config/eslint.config.cjs' );

module.exports = [
	...defaults,
	{
		// Third-party reference source (gitignored), never linted.
		ignores: [ 'reference/**' ],
	},
	{
		settings: {
			'import/core-modules': [
				'@wordpress/api-fetch',
				'@wordpress/block-editor',
				'@wordpress/blocks',
				'@wordpress/components',
				'@wordpress/core-data',
				'@wordpress/data',
				'@wordpress/date',
				'@wordpress/editor',
				'@wordpress/element',
				'@wordpress/i18n',
				'@wordpress/plugins',
				'@wordpress/server-side-render',
				'@wordpress/url',
			],
		},
	},
	{
		// JS unit tests run on Node's built-in runner (npm run test:js); vitest is not installed.
		files: [ 'tests/js/**' ],
		rules: {
			'vitest/no-import-node-test': 'off',
		},
	},
];
