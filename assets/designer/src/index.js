/**
 * Authlify login designer: entry point.
 */
import { createRoot } from '@wordpress/element';
import App from './App';
import './style.scss';

function mount() {
	const root = document.getElementById( 'authlify-designer-root' );
	if ( root && window.authlifyDesigner ) {
		createRoot( root ).render( <App /> );
	}
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', mount );
} else {
	mount();
}
