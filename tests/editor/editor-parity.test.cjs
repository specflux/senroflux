/**
 * Editor-parity check for the pages pack.
 *
 * The PHP Validator proves the markup parses and matches the pattern shapes;
 * it cannot prove the block editor will open it cleanly. The editor re-runs
 * every block's save() from the comment attributes and compares the result
 * with the stored HTML; a mismatch is "Block contains unexpected or invalid
 * content". This runs that same comparison (`parse()` from @wordpress/blocks
 * with the core block library registered) over the shipped patterns and over
 * a full page after Validator::clean().
 *
 * Run: `npm run test:editor` (needs `composer install` for the PHP exporter).
 */

const { test } = require( 'node:test' );
const assert = require( 'node:assert/strict' );
const { execFileSync } = require( 'node:child_process' );
const path = require( 'node:path' );
const { JSDOM, VirtualConsole } = require( 'jsdom' );

// The block library expects a browser; jsdom provides enough of one.
const dom = new JSDOM( '<!doctype html><html><body></body></html>', {
	url: 'https://senroflux.invalid/',
	virtualConsole: new VirtualConsole(),
} );
for ( const key of [
	'window',
	'document',
	'navigator',
	'Node',
	'Element',
	'HTMLElement',
	'DOMParser',
	'MutationObserver',
] ) {
	globalThis[ key ] = dom.window[ key ];
}
globalThis.self = dom.window;
globalThis.getComputedStyle = dom.window.getComputedStyle.bind( dom.window );
globalThis.requestAnimationFrame = ( cb ) => setTimeout( cb, 0 );
globalThis.cancelAnimationFrame = clearTimeout;
dom.window.matchMedia = () => ( {
	matches: false,
	addEventListener() {},
	removeEventListener() {},
} );

const { parse } = require( '@wordpress/blocks' );
const { registerCoreBlocks } = require( '@wordpress/block-library' );

registerCoreBlocks();

/**
 * Every block the editor would flag, with the path to it and the validator's
 * own explanation. Validation logs are silenced; the issues are returned.
 */
function invalidBlocks( markup ) {
	const quiet = [ 'log', 'info', 'warn', 'error', 'groupCollapsed', 'groupEnd' ];
	const saved = quiet.map( ( m ) => console[ m ] );
	quiet.forEach( ( m ) => ( console[ m ] = () => {} ) );
	let blocks;
	try {
		blocks = parse( markup, { __unstableSkipMigrationLogs: true } );
	} finally {
		quiet.forEach( ( m, i ) => ( console[ m ] = saved[ i ] ) );
	}

	const found = [];
	const walk = ( list, trail ) =>
		list.forEach( ( block, i ) => {
			const at = [ ...trail, `${ block.name }[${ i }]` ];
			if ( ! block.isValid || block.name === 'core/missing' ) {
				found.push( {
					path: at.join( ' > ' ),
					issues: ( block.validationIssues || [] ).map( ( issue ) =>
						issue.args.map( String ).join( ' ' )
					),
				} );
			}
			walk( block.innerBlocks, at );
		} );
	walk( blocks, [] );

	return found;
}

const fixtures = JSON.parse(
	execFileSync( 'php', [ path.join( __dirname, 'export-fixtures.php' ) ], {
		encoding: 'utf8',
	} )
);

for ( const [ slug, markup ] of Object.entries( fixtures.patterns ) ) {
	test( `shipped pattern "${ slug }" opens in the editor without recovery`, () => {
		assert.deepEqual( invalidBlocks( markup ), [] );
	} );
}

test( 'a full page after Validator::clean() opens in the editor without recovery', () => {
	assert.ok( fixtures.page.includes( 'wp:group' ) );
	assert.deepEqual( invalidBlocks( fixtures.page ), [] );
} );

// Negative control: the check must be able to fail. Removing a colour from the
// block comment while the HTML keeps its inline style — what the old step-5
// strip did — has to be flagged.
test( 'attrs stripped from the comment but left in the HTML are flagged', () => {
	const hero = fixtures.patterns.hero
		.replace(
			'"style":{"spacing"',
			'"style":{"color":{"background":"#5140a5"},"spacing"'
		)
		.replace(
			'wp-block-group alignfull" style="',
			'wp-block-group alignfull has-background" style="background-color:#5140a5;'
		);
	assert.deepEqual( invalidBlocks( hero ), [], 'consistent colour markup is valid' );

	const stripped = hero.replace( '"color":{"background":"#5140a5"},', '' );
	const found = invalidBlocks( stripped );
	assert.equal( found.length, 1 );
	assert.match( found[ 0 ].path, /^core\/group\[0\]$/ );
} );

/**
 * Deliberately broken variants of the cleaned page, one defect each: a class
 * or inline-style declaration dropped, an attribute changed in the comment
 * only or in the HTML only, a preset slug changed on one side.
 */
function mutations( page ) {
	const out = new Map();
	const add = ( label, markup ) => {
		if ( markup !== page && ! out.has( markup ) ) {
			out.set( markup, label );
		}
	};
	const replaceAt = ( str, index, from, to ) =>
		str.slice( 0, index ) + to + str.slice( index + from.length );

	for ( const m of page.matchAll( /class="([^"]*)"/g ) ) {
		const classes = m[ 1 ].split( ' ' );
		classes.forEach( ( cls, i ) => {
			const rest = classes.filter( ( _, j ) => j !== i ).join( ' ' );
			add( `drop class ${ cls }`, replaceAt( page, m.index, m[ 0 ], `class="${ rest }"` ) );
		} );
	}
	for ( const m of page.matchAll( /style="([^"]*)"/g ) ) {
		const decls = m[ 1 ].split( ';' ).filter( Boolean );
		decls.forEach( ( decl, i ) => {
			const rest = decls.filter( ( _, j ) => j !== i ).join( ';' );
			add( `drop style ${ decl }`, replaceAt( page, m.index, m[ 0 ], `style="${ rest }"` ) );
		} );
	}
	const pairs = [
		[ 'heading level in comment only', '<!-- wp:heading {"level":3} -->', '<!-- wp:heading {"level":4} -->' ],
		[ 'heading level in HTML only', '<h3 class="wp-block-heading">', '<h4 class="wp-block-heading">' ],
		[ 'default heading given h3 tag', '<!-- wp:heading --><h2 class="wp-block-heading">', '<!-- wp:heading --><h3 class="wp-block-heading">' ],
		[ 'textAlign in comment only', '<!-- wp:heading {"textAlign":"center"} -->', '<!-- wp:heading {"textAlign":"right"} -->' ],
		[ 'textAlign added in comment only', '<!-- wp:heading {"level":3} -->', '<!-- wp:heading {"textAlign":"center","level":3} -->' ],
		[ 'fontSize in comment only', '"fontSize":"large"', '"fontSize":"x-large"' ],
		[ 'fontSize in HTML only', 'has-large-font-size', 'has-x-large-font-size' ],
		[ 'fontSize changed on both sides', 'large', 'x-large' ],
		[ 'fontSize added in comment only', '<!-- wp:paragraph {"align":"center"} -->', '<!-- wp:paragraph {"align":"center","fontSize":"small"} -->' ],
		[ 'spacing preset in comment only', 'spacing|60', 'spacing|40' ],
		[ 'spacing preset in HTML only', 'spacing--60', 'spacing--40' ],
		[ 'spacing preset changed on both sides', '60', '40' ],
		[ 'group align in comment only', '"align":"full",', '"align":"wide",' ],
		[ 'group align dropped in comment only', '"align":"full",', '' ],
		[ 'button width in comment only', '<!-- wp:button -->', '<!-- wp:button {"width":50} -->' ],
		[ 'button link class dropped', 'wp-block-button__link wp-element-button', 'wp-block-button__link' ],
		[ 'list made ordered in comment only', '<!-- wp:list -->', '<!-- wp:list {"ordered":true} -->' ],
		[ 'list made ordered in HTML only', '<ul class="wp-block-list">', '<ol class="wp-block-list">' ],
		[ 'details opened in HTML only', '<details class="wp-block-details">', '<details class="wp-block-details" open>' ],
		[ 'paragraph dropCap in comment only', '<!-- wp:paragraph -->', '<!-- wp:paragraph {"dropCap":true} -->' ],
		[ 'className in comment only', '<!-- wp:paragraph -->', '<!-- wp:paragraph {"className":"lead"} -->' ],
		[ 'extra class in HTML only', '<p class="has-text-align-center">', '<p class="has-text-align-center lead">' ],
		[ 'column width in comment only', '<!-- wp:column -->', '<!-- wp:column {"width":"50%"} -->' ],
		[ 'columns not stacked in comment only', '<!-- wp:columns -->', '<!-- wp:columns {"isStackedOnMobile":false} -->' ],
	];
	for ( const [ label, from, to ] of pairs ) {
		let at = page.indexOf( from );
		while ( at !== -1 ) {
			add( label, replaceAt( page, at, from, to ) );
			at = page.indexOf( from, at + from.length );
		}
		add( `${ label } (everywhere)`, page.split( from ).join( to ) );
	}

	return out;
}

test( 'the PHP validator never accepts markup the editor would flag', () => {
	const variants = mutations( fixtures.page );
	const inputs = [ ...variants.keys() ];
	const verdicts = JSON.parse(
		execFileSync( 'php', [ path.join( __dirname, 'validate-batch.php' ) ], {
			input: JSON.stringify( inputs ),
			encoding: 'utf8',
			maxBuffer: 256 * 1024 * 1024,
		} )
	);

	const unsound = [];
	let refused = 0;
	inputs.forEach( ( markup, i ) => {
		if ( ! verdicts[ i ].ok ) {
			refused++;
			return;
		}
		// What the write persists is clean()'s output, so that is what the
		// editor will open.
		const found = invalidBlocks( verdicts[ i ].content );
		if ( found.length ) {
			unsound.push( `${ variants.get( markup ) }: ${ found[ 0 ].path }` );
		}
	} );

	assert.ok( inputs.length > 50, `only ${ inputs.length } variants generated` );
	assert.ok( refused > 0, 'no variant was refused; the check is not exercising the validator' );
	assert.deepEqual( unsound, [], `${ unsound.length } of ${ inputs.length } variants passed PHP but fail in the editor` );
} );
