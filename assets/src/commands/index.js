/**
 * The command palette entry point (0.3 S10). Built by `@wordpress/scripts`
 * into `build/commands/index.js`, enqueued site-wide (every wp-admin screen,
 * not just the Runs screen) by `src/Admin/RunsScreen.php`'s
 * `commandPaletteAssets()` — the global command palette (Cmd/Ctrl+K) is
 * available on every wp-admin page since WP 6.9
 * (`wp_enqueue_command_palette_assets()`), so this script only needs to
 * REGISTER a command loader into the existing `core/commands` store; the
 * palette's own `<CommandMenu>` (already mounted by core) renders it.
 *
 * Dynamic label, confirmed against `@wordpress/commands` source rather than
 * assumed: `useCommandLoader()`'s hook is called as `hook( { search } )` with
 * the RAW text currently typed into the palette input
 * (`node_modules/@wordpress/commands/src/hooks/use-command-loader.js`'s own
 * doc example builds a per-record label the exact same way), so "SenroFlux:
 * run "<typed text>"" is not a guess — it is what the documented loader
 * contract supports. No static fallback command is registered.
 *
 * This command ONLY navigates. It sends the browser to the Runs screen with
 * the typed text in the `goal` query arg, which `RunsScreen::assets()` reads
 * back out as `initialGoal` to pre-fill the message box — nothing here (or
 * on that read-only GET request) ever starts a run. Starting one still takes
 * a human looking at the box and clicking "Start run".
 */

import { __, sprintf } from '@wordpress/i18n';
import { dispatch } from '@wordpress/data';
import { store as commandsStore } from '@wordpress/commands';
import { addQueryArgs } from '@wordpress/url';

/**
 * Exported (not just used below) so a test can call it directly and assert,
 * behaviorally, that its command's `callback` only ever navigates — there is
 * no `startRun`/`tickRun` import anywhere in this file for it to reach.
 *
 * @param {Object} args
 * @param {string} args.search The text currently typed into the command palette.
 */
export function useSenrofluxRunCommandLoader( { search } ) {
	const goal = ( search || '' ).trim();

	if ( '' === goal ) {
		return { commands: [], isLoading: false };
	}

	const config = window.senrofluxCommandsConfig || {};
	if ( ! config.runsUrl ) {
		return { commands: [], isLoading: false };
	}

	return {
		commands: [
			{
				name: 'senroflux/run-typed-goal',
				label: sprintf(
					/* translators: %s: the text currently typed into the command palette. */
					__( 'SenroFlux: run "%s"', 'senroflux' ),
					goal
				),
				// Never used as the palette's search value — the label above
				// (which already contains the typed text) is what renders,
				// this only keeps the item matchable while typing continues.
				searchLabel: sprintf( 'senroflux run %s', goal ),
				callback: ( { close } ) => {
					close();
					// A NEW TAB, not `window.location.href` — closing the
					// palette must never navigate the admin page the user
					// was already on out from under them for what is only a
					// prefill, not a submission.
					window.open( addQueryArgs( config.runsUrl, { goal } ), '_blank', 'noopener' );
				},
			},
		],
		isLoading: false,
	};
}

dispatch( commandsStore ).registerCommandLoader( {
	name: 'senroflux/run-goal',
	hook: useSenrofluxRunCommandLoader,
} );
