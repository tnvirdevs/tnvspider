/**
 * Choose the page to translate: search posts and pages, or type an address.
 */
import { Button, Notice, Spinner, TextControl } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { addQueryArgs } from '@wordpress/url';
import { __ } from '@wordpress/i18n';
import { errorText, getWithTotal } from '../admin/api';
import { Section } from '../admin/fields';

const editorUrl = ( args ) =>
	addQueryArgs( window.location.pathname, { page: 'wst-editor', ...args } );

export default function PagePicker() {
	const [ search, setSearch ] = useState( '' );
	const [ query, setQuery ] = useState( '' );
	const [ result, setResult ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ path, setPath ] = useState( '/' );

	useEffect( () => {
		setResult( null );
		setError( null );
		getWithTotal( '/pages', { search: query, per_page: 20 } )
			.then( setResult )
			.catch( ( e ) => setError( errorText( e ) ) );
	}, [ query ] );

	return (
		<Section
			title={ __( 'Choose a page to translate', 'wp-site-translator' ) }
			description={ __(
				'You can also open the editor from the Pages screen, the post editor sidebar, or “Translate this page” in the toolbar while viewing the site.',
				'wp-site-translator'
			) }
		>
			<form
				className="wst-toolbar"
				role="search"
				onSubmit={ ( event ) => {
					event.preventDefault();
					setQuery( search );
				} }
			>
				<TextControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __(
						'Search posts and pages',
						'wp-site-translator'
					) }
					value={ search }
					onChange={ setSearch }
				/>
				<Button variant="secondary" type="submit">
					{ __( 'Search', 'wp-site-translator' ) }
				</Button>
			</form>
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }
			{ ! result && ! error && <Spinner /> }
			{ result && result.items.length === 0 && (
				<p className="wst-empty">
					{ __( 'No posts or pages found.', 'wp-site-translator' ) }
				</p>
			) }
			{ result && result.items.length > 0 && (
				<ul className="wst-picker">
					{ result.items.map( ( item ) => (
						<li key={ item.id }>
							<a href={ editorUrl( { post: item.id } ) }>
								{ item.title ||
									__( '(no title)', 'wp-site-translator' ) }
							</a>{ ' ' }
							<span className="wst-muted">
								{ item.type }
								{ item.coverage.total > 0 &&
									` · ${ item.coverage.percent } %` }
							</span>
						</li>
					) ) }
				</ul>
			) }
			<form
				className="wst-toolbar"
				onSubmit={ ( event ) => {
					event.preventDefault();
					window.location.href = editorUrl( { path } );
				} }
			>
				<TextControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __(
						'Or an address of this site (home page, archive, shop…)',
						'wp-site-translator'
					) }
					help={ __(
						'For example / for the home page or /shop/.',
						'wp-site-translator'
					) }
					value={ path }
					onChange={ setPath }
				/>
				<Button variant="secondary" type="submit">
					{ __( 'Open', 'wp-site-translator' ) }
				</Button>
			</form>
		</Section>
	);
}
