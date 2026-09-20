/**
 * Regression gate for the S10 stylesheet gap: every `senroflux-*` class
 * name referenced by the Runs screen components must have at least one
 * rule with a non-empty declaration block in `style.css`. This test
 * extracts class names directly from the component source (not from a
 * hand-maintained list) so a future component that introduces a new class
 * without styling it fails here, not in a live-review pass.
 *
 * Template-interpolated class names (e.g. `senroflux-pill-${ modifier }`)
 * are extracted as a trailing-dash PREFIX (`senroflux-pill-`) and matched
 * against any stylesheet class that starts with that prefix.
 */

import fs from 'fs';
import path from 'path';

const COMPONENTS_DIR = path.join( __dirname, '..', 'components' );
const CSS_PATH = path.join( __dirname, '..', 'style.css' );

/** Every `senroflux-*` token referenced anywhere in the component source. */
function extractUsedClassNames() {
	const files = fs
		.readdirSync( COMPONENTS_DIR )
		.filter( ( f ) => f.endsWith( '.js' ) )
		.map( ( f ) => path.join( COMPONENTS_DIR, f ) );

	const names = new Set();
	for ( const file of files ) {
		const source = fs.readFileSync( file, 'utf8' );
		const matches = source.match( /senroflux-[a-zA-Z0-9-]+/g ) || [];
		matches.forEach( ( m ) => names.add( m ) );
	}
	return names;
}

/**
 * Parse `style.css` into a flat list of { selector, body } leaf rules,
 * ignoring at-rule containers (`@media`, `@keyframes`) themselves while
 * still descending into them for their nested rules.
 */
function extractRules( css ) {
	const stripped = css.replace( /\/\*[\s\S]*?\*\//g, '' );
	const rules = [];
	const stack = [];
	let buffer = '';

	for ( let i = 0; i < stripped.length; i++ ) {
		const ch = stripped[ i ];
		if ( '{' === ch ) {
			stack.push( { selector: buffer.trim(), bodyStart: i + 1 } );
			buffer = '';
		} else if ( '}' === ch ) {
			const top = stack.pop();
			if ( ! top ) {
				continue;
			}
			const body = stripped.slice( top.bodyStart, i );
			if ( top.selector && ! top.selector.startsWith( '@' ) ) {
				rules.push( { selector: top.selector, body } );
			}
			buffer = '';
		} else {
			buffer += ch;
		}
	}
	return rules;
}

/** Set of class names (without the leading dot) that have a non-empty rule. */
function extractStyledClassNames( css ) {
	const styled = new Set();
	for ( const rule of extractRules( css ) ) {
		if ( ! rule.body.trim() ) {
			continue;
		}
		const classTokens = rule.selector.match( /\.senroflux-[a-zA-Z0-9-]+/g ) || [];
		classTokens.forEach( ( token ) => styled.add( token.slice( 1 ) ) );
	}
	return styled;
}

function isCovered( name, styledClassNames ) {
	if ( name.endsWith( '-' ) ) {
		for ( const styled of styledClassNames ) {
			if ( styled.startsWith( name ) ) {
				return true;
			}
		}
		return false;
	}
	return styledClassNames.has( name );
}

describe( 'every senroflux-* class used by the Runs screen has a styled rule', () => {
	it( 'has no class name referenced in JS but missing from style.css', () => {
		const used = extractUsedClassNames();
		const css = fs.readFileSync( CSS_PATH, 'utf8' );
		const styled = extractStyledClassNames( css );

		const missing = [ ...used ].filter( ( name ) => ! isCovered( name, styled ) ).sort();

		if ( missing.length > 0 ) {
			throw new Error(
				`${ missing.length } class name(s) used in Runs screen components have no rule (with a non-empty declaration block) in style.css:\n` +
					missing.map( ( m ) => `  - ${ m }` ).join( '\n' )
			);
		}
	} );
} );
