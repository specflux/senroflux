/**
 * Entry point for the Runs screen (S10/S17). Built by `@wordpress/scripts`
 * into `build/runs/index.js`, enqueued by `src/Admin/RunsScreen.php`.
 */

import domReady from '@wordpress/dom-ready';
import { render } from '@wordpress/element';
import App from './components/App';

import './style.css';

domReady( () => {
	const root = document.getElementById( 'senroflux-runs-root' );
	if ( ! root ) {
		return;
	}

	const config = window.senrofluxRunsConfig || {};
	render( <App config={ config } />, root );
} );
