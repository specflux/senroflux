#!/usr/bin/env node
/**
 * S22 pseudo-locale pipeline (a "Node script under tests/e2e/pseudo-locale/
 * [that] takes a .pot, and writes a .po where every msgstr is `⟦` + msgid +
 * `⟧`"). A small hand parser for the gettext PO/POT format `wp i18n
 * make-pot` emits — no new npm dependency.
 *
 * Usage: node pot-to-pseudo-po.js <input.pot> <output.po> <locale>
 *
 * Handles:
 *  - line-continuation strings (`msgid "a" \n "b"` -> "ab"),
 *  - plurals (`msgid_plural`/`msgstr[0]`/`msgstr[1]` — English plural rules,
 *    nplurals=2), each form wrapped independently,
 *  - placeholders (`%s`, `%1$s`, …) preserved EXACTLY inside the wrap, since
 *    the wrap only prepends/appends `⟦`/`⟧` around the untouched msgid text —
 *    it never rewrites the string body,
 *  - the header entry (empty msgid) is replaced wholesale with a minimal,
 *    valid PO header for the target locale (not wrapped — it is not a
 *    translatable string).
 */
const fs = require( 'fs' );

/** Unescape a quoted PO string body (already had its surrounding quotes stripped). */
function unescapePoString( body ) {
	let out = '';
	for ( let i = 0; i < body.length; i++ ) {
		if ( '\\' === body[ i ] && i + 1 < body.length ) {
			const next = body[ i + 1 ];
			if ( 'n' === next ) {
				out += '\n';
			} else if ( 't' === next ) {
				out += '\t';
			} else if ( '"' === next || '\\' === next ) {
				out += next;
			} else {
				out += next;
			}
			i++;
		} else {
			out += body[ i ];
		}
	}
	return out;
}

/** Escape a raw string for PO output (inverse of unescapePoString, minus \t). */
function escapePoString( raw ) {
	return raw.replace( /\\/g, '\\\\' ).replace( /"/g, '\\"' ).replace( /\n/g, '\\n' );
}

/** Parse one or more consecutive `"..."` lines starting at `lines[i]` into one string. */
function parseQuotedRun( lines, i ) {
	let value = '';
	let consumed = 0;
	while ( i + consumed < lines.length ) {
		const line = lines[ i + consumed ].trim();
		const match = line.match( /^"((?:[^"\\]|\\.)*)"$/ );
		if ( ! match ) {
			break;
		}
		value += unescapePoString( match[ 1 ] );
		consumed++;
	}
	return { value, consumed };
}

/**
 * Parse a .pot/.po file into an ordered list of entries.
 * Each entry: { comments: string[], msgctxt?: string, msgid: string,
 *               msgidPlural?: string, msgstrs: string[] (index = plural form) }
 */
function parsePo( content ) {
	const lines = content.split( /\r?\n/ );
	const entries = [];
	let i = 0;

	while ( i < lines.length ) {
		// Skip blank lines between entries.
		while ( i < lines.length && '' === lines[ i ].trim() ) {
			i++;
		}
		if ( i >= lines.length ) {
			break;
		}

		const comments = [];
		while ( i < lines.length && lines[ i ].trim().startsWith( '#' ) ) {
			comments.push( lines[ i ] );
			i++;
		}
		if ( i >= lines.length ) {
			// Trailing comment block with nothing after it — preserve as a
			// comment-only pseudo-entry so it round-trips.
			entries.push( { comments, msgid: null, msgstrs: [] } );
			break;
		}

		let msgctxt;
		if ( lines[ i ].trim().startsWith( 'msgctxt' ) ) {
			const m = lines[ i ].trim().match( /^msgctxt\s+"((?:[^"\\]|\\.)*)"$/ );
			const first = m ? unescapePoString( m[ 1 ] ) : '';
			i++;
			const run = parseQuotedRun( lines, i );
			msgctxt = first + run.value;
			i += run.consumed;
		}

		if ( ! lines[ i ] || ! lines[ i ].trim().startsWith( 'msgid ' ) ) {
			// Not a well-formed entry start — skip defensively.
			i++;
			continue;
		}
		const msgidFirst = lines[ i ].trim().match( /^msgid\s+"((?:[^"\\]|\\.)*)"$/ );
		let msgid = msgidFirst ? unescapePoString( msgidFirst[ 1 ] ) : '';
		i++;
		const msgidRun = parseQuotedRun( lines, i );
		msgid += msgidRun.value;
		i += msgidRun.consumed;

		let msgidPlural;
		if ( lines[ i ] && lines[ i ].trim().startsWith( 'msgid_plural' ) ) {
			const m = lines[ i ].trim().match( /^msgid_plural\s+"((?:[^"\\]|\\.)*)"$/ );
			msgidPlural = m ? unescapePoString( m[ 1 ] ) : '';
			i++;
			const run = parseQuotedRun( lines, i );
			msgidPlural += run.value;
			i += run.consumed;
		}

		const msgstrs = [];
		if ( undefined !== msgidPlural ) {
			while ( lines[ i ] && /^msgstr\[\d+\]/.test( lines[ i ].trim() ) ) {
				const m = lines[ i ].trim().match( /^msgstr\[(\d+)\]\s+"((?:[^"\\]|\\.)*)"$/ );
				const idx = m ? parseInt( m[ 1 ], 10 ) : msgstrs.length;
				let value = m ? unescapePoString( m[ 2 ] ) : '';
				i++;
				const run = parseQuotedRun( lines, i );
				value += run.value;
				i += run.consumed;
				msgstrs[ idx ] = value;
			}
		} else if ( lines[ i ] && lines[ i ].trim().startsWith( 'msgstr ' ) ) {
			const m = lines[ i ].trim().match( /^msgstr\s+"((?:[^"\\]|\\.)*)"$/ );
			let value = m ? unescapePoString( m[ 1 ] ) : '';
			i++;
			const run = parseQuotedRun( lines, i );
			value += run.value;
			i += run.consumed;
			msgstrs[ 0 ] = value;
		}

		entries.push( { comments, msgctxt, msgid, msgidPlural, msgstrs } );
	}

	return entries;
}

/** Wrap a translatable string in the pseudo-locale markers, verbatim otherwise. */
function pseudoWrap( text ) {
	return `⟦${ text }⟧`; // ⟦ … ⟧
}

const PSEUDO_HEADER = ( locale ) =>
	[
		'msgid ""',
		'msgstr ""',
		'"Project-Id-Version: SenroFlux pseudo-locale\\n"',
		'"Report-Msgid-Bugs-To: https://wordpress.org/support/plugin/senroflux\\n"',
		'"MIME-Version: 1.0\\n"',
		'"Content-Type: text/plain; charset=UTF-8\\n"',
		'"Content-Transfer-Encoding: 8bit\\n"',
		`"Language: ${ locale }\\n"`,
		'"Plural-Forms: nplurals=2; plural=(n != 1);\\n"',
		'"X-Domain: senroflux\\n"',
	].join( '\n' );

function serializeEntry( entry ) {
	const out = [];
	out.push( ...entry.comments );
	if ( entry.msgctxt ) {
		out.push( `msgctxt "${ escapePoString( entry.msgctxt ) }"` );
	}
	out.push( `msgid "${ escapePoString( entry.msgid ) }"` );
	if ( undefined !== entry.msgidPlural ) {
		out.push( `msgid_plural "${ escapePoString( entry.msgidPlural ) }"` );
		out.push( `msgstr[0] "${ escapePoString( pseudoWrap( entry.msgid ) ) }"` );
		out.push( `msgstr[1] "${ escapePoString( pseudoWrap( entry.msgidPlural ) ) }"` );
	} else {
		out.push( `msgstr "${ escapePoString( pseudoWrap( entry.msgid ) ) }"` );
	}
	return out.join( '\n' );
}

/** Convert POT file content to pseudo-locale PO file content. Exported for reuse by Playwright setup. */
function potToPseudoPo( potContent, locale ) {
	const entries = parsePo( potContent );
	const blocks = [ PSEUDO_HEADER( locale ) ];
	let count = 0;
	for ( const entry of entries ) {
		if ( null === entry.msgid ) {
			continue; // Trailing comment-only fragment, nothing to translate.
		}
		if ( '' === entry.msgid ) {
			continue; // The .pot's own header entry — replaced above.
		}
		blocks.push( serializeEntry( entry ) );
		count++;
	}
	return { content: blocks.join( '\n\n' ) + '\n', count };
}

function main() {
	const [ , , inputPath, outputPath, locale ] = process.argv;
	if ( ! inputPath || ! outputPath || ! locale ) {
		process.stderr.write( 'Usage: node pot-to-pseudo-po.js <input.pot> <output.po> <locale>\n' );
		process.exit( 1 );
	}

	const potContent = fs.readFileSync( inputPath, 'utf8' );
	const { content, count } = potToPseudoPo( potContent, locale );

	fs.writeFileSync( outputPath, content, 'utf8' );
	process.stdout.write( `Wrote ${ count } pseudo-locale entries to ${ outputPath }\n` );
}

if ( require.main === module ) {
	main();
}

module.exports = { potToPseudoPo, pseudoWrap };
