import { createElement, Fragment } from '@wordpress/element';

/** Elements whose text is never shown (it is code, not prose). */
const DROPPED = [ 'SCRIPT', 'STYLE', 'TEMPLATE', 'NOSCRIPT' ];

/** Only plain web links (or site-relative paths) survive; never `javascript:` and friends. */
function isSafeHref( href ) {
	return /^https?:\/\//i.test( href ) || /^\/(?!\/)/.test( href );
}

function toNodes( parent, keyPrefix ) {
	return Array.from( parent.childNodes ).map( ( node, index ) => {
		const key = `${ keyPrefix }-${ index }`;
		if ( node.nodeType === 3 ) {
			return node.nodeValue;
		}
		if ( node.nodeType !== 1 || DROPPED.includes( node.tagName ) ) {
			return null;
		}
		const children = toNodes( node, key );
		if ( node.tagName === 'A' && isSafeHref( node.getAttribute( 'href' ) || '' ) ) {
			return createElement(
				'a',
				{ key, href: node.getAttribute( 'href' ), target: '_blank', rel: 'noopener noreferrer' },
				children
			);
		}
		// Any other element is flattened to its text: no tag, no attribute.
		return createElement( Fragment, { key }, children );
	} );
}

/**
 * The pack's approval summary as React nodes. The server already reduced it
 * to text plus `<a href>`; this is the second, independent gate. The string
 * is parsed into an INERT document (`DOMParser` never runs scripts or loads
 * images) and rebuilt element by element, so no HTML string ever reaches
 * `innerHTML`/`dangerouslySetInnerHTML` and nothing but text and vetted links
 * can render, whatever the string holds.
 *
 * @param {Object} props
 * @param {string} props.html Server-sanitised summary markup.
 */
export default function ApprovalSummary( { html } ) {
	const doc = new window.DOMParser().parseFromString( `<!doctype html><body>${ html }`, 'text/html' );

	return <>{ toNodes( doc.body, 's' ) }</>;
}
