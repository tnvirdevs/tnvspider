/**
 * Translation editor (plan §11).
 */
import { createRoot } from '@wordpress/element';
import Editor from './Editor';
import './editor.scss';

const root = document.getElementById( 'wst-editor-app' );
if ( root ) {
	createRoot( root ).render( <Editor /> );
}
