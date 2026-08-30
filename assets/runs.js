/**
 * SenroFlux Runs screen (S10/S13/S15): progressive enhancement.
 *
 * The whole page (list, detail, the three park cards, the new-run form) is
 * complete without JS — every mutation is a plain admin-post form. This
 * script only:
 *   1. confirms the destructive Cancel action (existing behaviour);
 *   2. polls `wp_ajax_senroflux_tick` (POST {action, nonce, run_id, step_count}
 *      — NO `resume`) every 3s while a run is `running`/`pending`, appends any
 *      `new_steps` to the timeline, swaps the status badge, and announces STATUs
 *      TRANSITIONS ONLY in the aria-live region;
 *   3. stops polling on any park or terminal status; on a park it ANNOUNCES
 *      first, then reloads after a short delay so the SERVER renders the park
 *      card and the announcement is not cut off mid-sentence;
 *   4. focuses the park-card heading when the page loads already parked;
 *   5. announces a failed poll in the same aria-live region instead of
 *      stopping silently.
 *
 * Every human-readable string comes from the server through `senrofluxRuns.i18n`
 * (S15: nothing user-facing is hardcoded English here), falling back to English
 * only if the localization did not load.
 *
 * All interactions stay keyboard-reachable; nothing here is required for the
 * page to work.
 */
( function () {
	'use strict';

	var settings = window.senrofluxRuns || {};
	var strings = settings.i18n || {};

	/** A translated status label, falling back to the machine value. */
	function statusLabel( status ) {
		var map = strings.statuses || {};
		return ( Object.prototype.hasOwnProperty.call( map, status ) && map[ status ] ) || status;
	}

	/** sprintf-lite for the single-placeholder strings the server sends. */
	function format( template, value ) {
		return String( template ).replace( '%s', value );
	}

	// ------------------------------------------------------------------
	// Cancel confirm (kept from the original screen).
	// ------------------------------------------------------------------
	document.addEventListener( 'click', function ( event ) {
		var link = event.target.closest && event.target.closest( 'a[href*="admin-post.php?action=senroflux_cancel_run"]' );
		// The ONLY cancel confirmation: the markup carries no inline onclick,
		// so JS-off users simply follow the nonce-checked link.
		if ( link && ! window.confirm( settings.cancelConfirm || 'Cancel this run?' ) ) {
			event.preventDefault();
		}
	} );

	// ------------------------------------------------------------------
	// Detail polling — only present on the run detail view.
	// ------------------------------------------------------------------
	var detail = document.getElementById( 'senroflux-run-detail' );
	if ( ! detail ) {
		return; // List view: no polling.
	}

	var runId = parseInt( detail.getAttribute( 'data-run-id' ), 10 );
	var stepCount = parseInt( detail.getAttribute( 'data-step-count' ), 10 );
	var initialStatus = ( detail.getAttribute( 'data-status' ) || '' ).toLowerCase();
	var pollInterval = settings.pollInterval || 3000;
	var parkAnnounceMs = settings.parkAnnounceMs || 1500;
	var timer = null;
	var lastStatus = initialStatus;

	var badge = document.getElementById( 'senroflux-status-badge' );
	var live = document.getElementById( 'senroflux-live-status' );
	var timeline = document.querySelector( '.senroflux-steps' );
	var page = document.querySelector( 'div.wrap' );

	function isRunning( status ) {
		return status === 'running' || status === 'pending';
	}

	function isParked( status ) {
		return status === 'awaiting_user' || status === 'awaiting_plan' || status === 'awaiting_approval';
	}

	function isTerminal( status ) {
		return status === 'completed' || status === 'failed' || status === 'cancelled';
	}

	function announce( status ) {
		if ( ! live || status === lastStatus ) {
			return; // STATUS TRANSITIONS ONLY.
		}
		live.textContent = format( strings.statusAnnounce || 'Status: %s', statusLabel( status ) );
		lastStatus = status;
	}

	/**
	 * Announce a poll failure. Not a status transition, so it bypasses the
	 * transitions-only rule deliberately: silence here would leave a screen
	 * reader user believing the run is still being watched (S15).
	 */
	function announcePollFailure() {
		if ( ! live ) {
			return;
		}
		live.textContent = strings.pollError || 'Live updates stopped. Use Refresh to see the latest state.';
	}

	function setBadge( status ) {
		if ( ! badge ) {
			return;
		}
		// The machine value stays in the class and data-status; only the TEXT
		// is the translated label, matching the server render exactly.
		badge.className = 'senroflux-badge senroflux-badge-' + status;
		badge.setAttribute( 'data-status', status );
		badge.textContent = statusLabel( status );
	}

	// Build one timeline <li> mirroring the server-rendered shape (S15: the
	// polled steps must look identical to a server render).
	function buildStepLi( step ) {
		var li = document.createElement( 'li' );
		li.className = 'senroflux-step senroflux-step-' + ( step.kind || '' );
		li.setAttribute( 'data-seq', String( step.seq || '' ) );
		li.setAttribute( 'data-kind', step.kind || '' );
		li.setAttribute( 'data-tool-name', step.tool_name || '' );
		li.setAttribute( 'data-status', step.status || '' );

		var label = '#' + ( step.seq || '' ) + ' ' + ( step.kind || '' );
		if ( step.tool_name ) {
			label += ' · ' + step.tool_name;
		}
		if ( step.status && step.status !== 'ok' ) {
			label += ' · ' + step.status;
		}

		var strong = document.createElement( 'strong' );
		strong.textContent = label;
		li.appendChild( strong );

		if ( step.message && step.message !== null ) {
			var details = document.createElement( 'details' );
			details.className = 'senroflux-json-toggle';

			var summary = document.createElement( 'summary' );
			summary.textContent = strings.json || 'JSON';
			details.appendChild( summary );

			var pre = document.createElement( 'pre' );
			pre.textContent = JSON.stringify( step.message, null, 2 );
			details.appendChild( pre );

			li.appendChild( details );
		}

		return li;
	}

	function appendSteps( steps ) {
		if ( ! timeline || ! Array.isArray( steps ) || steps.length === 0 ) {
			return;
		}
		steps.forEach( function ( step ) {
			timeline.appendChild( buildStepLi( step ) );
		} );
	}

	function stopPolling() {
		if ( timer ) {
			window.clearTimeout( timer );
			timer = null;
		}
	}

	function schedulePoll() {
		stopPolling();
		if ( isRunning( lastStatus ) ) {
			timer = window.setTimeout( pollOnce, pollInterval );
		}
	}

	function pollOnce() {
		if ( ! window.fetch || ! settings.ajaxUrl || ! settings.nonce ) {
			// No transport at all: the server render plus the Refresh link is
			// the whole experience. Nothing failed, so nothing is announced.
			stopPolling();
			return;
		}

		var body = new URLSearchParams();
		body.append( 'action', 'senroflux_tick' );
		body.append( 'nonce', settings.nonce );
		body.append( 'run_id', String( runId ) );
		body.append( 'step_count', String( stepCount ) );

		window.fetch( settings.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		} )
			.then( function ( res ) { return res.json(); } )
			.then( function ( json ) {
				if ( ! json || ! json.success || ! json.data || ! json.data.run ) {
					// A refusal (403/400) used to stop the poll SILENTLY, so the
					// page just froze. Say so in the aria-live region instead.
					stopPolling();
					announcePollFailure();
					return;
				}

				var state = json.data;
				var status = ( state.run.status || '' ).toLowerCase();

				appendSteps( state.new_steps );
				stepCount = parseInt( state.run.step_count, 10 );
				detail.setAttribute( 'data-step-count', String( stepCount ) );
				setBadge( status );
				announce( status );

				if ( isTerminal( status ) ) {
					// Terminal: stop and let the server render the report.
					stopPolling();
					return;
				}
				if ( isParked( status ) ) {
					// Park: the SERVER has to render the park card, so a reload
					// is still the honest move — but reloading IMMEDIATELY tore
					// down the aria-live region before the announcement above
					// was read out. Wait for it, then reload; on the new page
					// `focusParkHeading()` takes over (S15).
					stopPolling();
					window.setTimeout( function () {
						window.location.reload();
					}, parkAnnounceMs );
					return;
				}
				schedulePoll();
			} )
			.catch( function () {
				stopPolling();
				announcePollFailure();
			} );
	}

	// ------------------------------------------------------------------
	// A11y helpers: make the "Other" answer and the "Veto" note fields
	// `required` only while their controlling radio is checked (S15). The
	// server re-validates regardless, so this is purely a keyboard/screen
	// reader affordance.
	// ------------------------------------------------------------------
	function bindConditionalRequired() {
		var otherRadio = document.querySelector( 'input[name="senroflux_answer_choice"][value="__other__"]' );
		var otherField = document.getElementById( 'senroflux_answer_other' );
		if ( otherRadio && otherField ) {
			var sync = function () {
				otherField.required = otherRadio.checked;
			};
			otherRadio.addEventListener( 'change', sync );
			sync();
		}

		var vetoRadio = document.querySelector( 'input[name="senroflux_plan_action"][value="veto"]' );
		var noteField = document.getElementById( 'senroflux_plan_note' );
		if ( vetoRadio && noteField ) {
			var syncNote = function () {
				noteField.required = vetoRadio.checked;
			};
			vetoRadio.addEventListener( 'change', syncNote );
			syncNote();
		}
	}

	bindConditionalRequired();

	// ------------------------------------------------------------------
	// Focus the park-card heading when the page loads already parked, so a
	// screen reader announcement and the in-page document order agree.
	// ------------------------------------------------------------------
	function focusParkHeading() {
		var heading = document.querySelector( '.senroflux-park-card-heading' );
		if ( heading && typeof heading.focus === 'function' ) {
			heading.focus();
		}
	}

	if ( isParked( initialStatus ) ) {
		// Reload would be pointless (we are already parked server-side): focus.
		focusParkHeading();
	} else if ( isRunning( initialStatus ) ) {
		schedulePoll();
	}
}() );
