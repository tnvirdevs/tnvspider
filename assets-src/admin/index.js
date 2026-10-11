/**
 * Translator admin app (plan §10).
 */
import { createRoot } from '@wordpress/element';
import App from './App';
import './admin.scss';

const root = document.getElementById( 'wst-admin-app' );
if ( root ) {
	createRoot( root ).render( <App /> );
}
