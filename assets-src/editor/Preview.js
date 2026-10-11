/**
 * Optional visual preview (plan §11): the translated page in a sandboxed
 * frame, without the page's own scripts unless asked. If the frame does not
 * report ready in time, a message replaces it; the list never waits for it.
 */
import {
	Button,
	CheckboxControl,
	Notice,
	Spinner,
} from '@wordpress/components';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { errorText, post } from '../admin/api';
import { plainText } from './text';

/** How long the frame may take to report ready. */
const READY_TIMEOUT_MS = 8000;

export default function Preview( {
	pageRef,
	items,
	selectedId,
	onSelect,
	reloadKey,
} ) {
	const frame = useRef( null );
	const [ scripts, setScripts ] = useState( false );
	const [ url, setUrl ] = useState( null );
	const [ status, setStatus ] = useState( 'loading' );
	const [ error, setError ] = useState( null );
	const [ attempt, setAttempt ] = useState( 0 );

	useEffect( () => {
		let cancelled = false;
		setStatus( 'loading' );
		setUrl( null );
		setError( null );
		post( '/preview/register', { ...pageRef, scripts } )
			.then( ( response ) => ! cancelled && setUrl( response.url ) )
			.catch( ( e ) => {
				if ( ! cancelled ) {
					setStatus( 'failed' );
					setError( errorText( e ) );
				}
			} );
		return () => {
			cancelled = true;
		};
	}, [ pageRef, scripts, attempt, reloadKey ] );

	useEffect( () => {
		if ( ! url ) {
			return undefined;
		}
		const timer = window.setTimeout( () => {
			setStatus( ( current ) =>
				current === 'ready' ? current : 'failed'
			);
		}, READY_TIMEOUT_MS );
		const onMessage = ( event ) => {
			if (
				! frame.current ||
				event.source !== frame.current.contentWindow ||
				! event.data
			) {
				return;
			}
			if ( event.data.type === 'wst-preview-ready' ) {
				window.clearTimeout( timer );
				setStatus( 'ready' );
			} else if (
				event.data.type === 'wst-select' &&
				Number.isInteger( event.data.id )
			) {
				onSelect( event.data.id );
			}
		};
		window.addEventListener( 'message', onMessage );
		return () => {
			window.clearTimeout( timer );
			window.removeEventListener( 'message', onMessage );
		};
	}, [ url, onSelect ] );

	useEffect( () => {
		if ( status === 'ready' && frame.current ) {
			frame.current.contentWindow.postMessage(
				{
					type: 'wst-strings',
					items: items.map( ( item ) => ( {
						id: item.id,
						text: plainText( item.translation || item.original ),
					} ) ),
				},
				'*'
			);
		}
	}, [ status, items ] );

	useEffect( () => {
		if ( status === 'ready' && frame.current && selectedId ) {
			frame.current.contentWindow.postMessage(
				{ type: 'wst-highlight', id: selectedId },
				'*'
			);
		}
	}, [ status, selectedId ] );

	return (
		<section
			className="wst-preview-pane"
			aria-label={ __( 'Preview', 'wp-site-translator' ) }
		>
			<div className="wst-preview-pane__bar">
				<CheckboxControl
					__nextHasNoMarginBottom
					label={ __( 'Run page scripts', 'wp-site-translator' ) }
					help={ __(
						'Off: other plugins’ scripts are removed from the preview so they cannot interfere.',
						'wp-site-translator'
					) }
					checked={ scripts }
					onChange={ setScripts }
				/>
				{ status === 'loading' && <Spinner /> }
			</div>
			{ status === 'failed' ? (
				<Notice status="warning" isDismissible={ false }>
					<p>
						{ error ||
							__(
								'The preview did not load (the page may refuse to be framed, or it failed). The string list works without it.',
								'wp-site-translator'
							) }
					</p>
					<Button
						variant="secondary"
						onClick={ () => setAttempt( attempt + 1 ) }
					>
						{ __( 'Try the preview again', 'wp-site-translator' ) }
					</Button>
				</Notice>
			) : (
				url && (
					<iframe
						ref={ frame }
						className="wst-preview-pane__frame"
						title={ __(
							'Translated page preview',
							'wp-site-translator'
						) }
						src={ url }
						sandbox="allow-scripts"
					/>
				)
			) }
		</section>
	);
}
