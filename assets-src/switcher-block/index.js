/**
 * Editor side of the wst/switcher block: a server-rendered preview.
 */
import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps } from '@wordpress/block-editor';
import ServerSideRender from '@wordpress/server-side-render';
import { __ } from '@wordpress/i18n';

registerBlockType( 'wst/switcher', {
	apiVersion: 3,
	title: __( 'Language switcher', 'wp-site-translator' ),
	description: __( 'Links to this page in each language.', 'wp-site-translator' ),
	category: 'widgets',
	icon: 'translation',
	supports: { html: false },
	edit: function Edit() {
		return (
			<div { ...useBlockProps() }>
				<ServerSideRender block="wst/switcher" />
			</div>
		);
	},
	save: () => null,
} );
