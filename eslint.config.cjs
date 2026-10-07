/**
 * ESLint: the @wordpress/scripts defaults, plus the WordPress packages that
 * are runtime externals (provided by WordPress, not installed with npm).
 */
const defaults = require( '@wordpress/scripts/config/eslint.config.cjs' );

module.exports = [
	...defaults,
	{
		settings: {
			'import/core-modules': [
				'@wordpress/api-fetch',
				'@wordpress/components',
				'@wordpress/core-data',
				'@wordpress/data',
				'@wordpress/editor',
				'@wordpress/element',
				'@wordpress/i18n',
				'@wordpress/plugins',
			],
		},
	},
];
