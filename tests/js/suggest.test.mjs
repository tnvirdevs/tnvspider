/**
 * Language-suggestion decision table (plan §17): browser languages ×
 * current language × cookie × bot × mode, plus pages without an
 * equivalent (mode "off"). Run: npm run test:js
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const { decide } = createRequire( import.meta.url )(
	'../../assets/suggest.js'
);

const base = {
	languages: [ 'bn-BD', 'en-US' ],
	current: 'en',
	other: 'bn',
	choice: '',
	bot: false,
	mode: 'bar',
	noRedirect: false,
};

const cases = [
	[ 'prefers the other language: bar', {}, 'bar' ],
	[ 'prefers the page language', { languages: [ 'en-GB', 'bn' ] }, 'none' ],
	[ 'only unrelated languages', { languages: [ 'fr-FR', 'de' ] }, 'none' ],
	[
		'first known language decides',
		{ languages: [ 'fr', 'bn', 'en' ] },
		'bar',
	],
	[ 'case and region ignored', { languages: [ 'BN-IN' ] }, 'bar' ],
	[
		'on the target page, English browser',
		{ current: 'bn', other: 'en', languages: [ 'en-US' ] },
		'bar',
	],
	[ 'cookie set (dismissed or switched)', { choice: 'en' }, 'none' ],
	[ 'cookie for the other language', { choice: 'bn' }, 'none' ],
	[ 'bot, bar mode', { bot: true }, 'none' ],
	[ 'redirect mode', { mode: 'redirect' }, 'redirect' ],
	[ 'redirect mode, bot', { mode: 'redirect', bot: true }, 'none' ],
	[
		'redirect mode, cookie (never twice)',
		{ mode: 'redirect', choice: 'bn' },
		'none',
	],
	[
		'redirect mode, ?wst_no_redirect=1',
		{ mode: 'redirect', noRedirect: true },
		'none',
	],
	[
		'redirect mode, page language preferred',
		{ mode: 'redirect', languages: [ 'en' ] },
		'none',
	],
	[ 'no equivalent page (off)', { other: '' }, 'none' ],
	[ 'no browser languages', { languages: [] }, 'none' ],
];

for ( const [ name, change, expected ] of cases ) {
	test( name, () => {
		assert.equal( decide( { ...base, ...change } ), expected );
	} );
}
