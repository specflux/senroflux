/**
 * SenroFlux Runs screen (S10): progressive enhancement only — the timeline is
 * fully readable without JS. Confirms destructive actions and lets the JSON
 * blocks collapse by keyboard.
 */
( function () {
	document.addEventListener( 'click', function ( event ) {
		var link = event.target.closest && event.target.closest( 'a[href*="admin-post.php?action=senroflux_cancel_run"]' );
		if ( link && ! window.confirm( senrofluxRuns.cancelConfirm || 'Cancel this run?' ) ) {
			event.preventDefault();
		}
	} );
}() );
