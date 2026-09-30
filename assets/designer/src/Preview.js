/**
 * Live preview of the real login URL in an iframe.
 */
import { __ } from '@wordpress/i18n';
import { useEffect, useRef, useState } from '@wordpress/element';
import { Spinner } from '@wordpress/components';

const data = window.authlifyDesigner || {};
const WIDTHS = { desktop: 1280, wide: 1440, tablet: 820, phone: 390 };

function previewUrl( args, reloadKey ) {
	const url = new URL( data.previewUrl, window.location.href );
	url.searchParams.set( 'authlify_preview', data.previewToken );
	// An explicit action: the bare login URL sends logged-in users to the dashboard.
	url.searchParams.set( 'action', 'login' );
	Object.keys( args ).forEach( ( key ) => url.searchParams.set( key, args[ key ] ) );
	url.searchParams.set( '_al', String( reloadKey ) );
	return url.toString();
}

export default function Preview( { args, device, css, reloadKey, screen, zoom = 'fit' } ) {
	const frame = useRef( null );
	const wrap = useRef( null );
	const ready = useRef( false );
	const latestCss = useRef( css );
	const [ size, setSize ] = useState( { w: 900, h: 700 } );
	const [ loading, setLoading ] = useState( true );
	const origin = new URL( data.previewUrl, window.location.href ).origin;
	const src = previewUrl( args, reloadKey );

	latestCss.current = css;

	// Measure the stage.
	useEffect( () => {
		if ( ! wrap.current ) {
			return undefined;
		}
		const observer = new window.ResizeObserver( ( entries ) => {
			const box = entries[ 0 ].contentRect;
			setSize( { w: box.width, h: box.height } );
		} );
		observer.observe( wrap.current );
		return () => observer.disconnect();
	}, [] );

	// The preview page announces itself; then CSS updates are sent without reloading.
	useEffect( () => {
		const onMessage = ( e ) => {
			if ( e.origin !== origin || ! e.data || e.data.type !== 'authlify-preview-ready' ) {
				return;
			}
			if ( frame.current && e.source === frame.current.contentWindow ) {
				ready.current = true;
				setLoading( false );
				if ( latestCss.current ) {
					e.source.postMessage( { type: 'authlify-css', css: latestCss.current }, origin );
				}
			}
		};
		window.addEventListener( 'message', onMessage );
		return () => window.removeEventListener( 'message', onMessage );
	}, [ origin ] );

	useEffect( () => {
		if ( ready.current && css && frame.current && frame.current.contentWindow ) {
			frame.current.contentWindow.postMessage( { type: 'authlify-css', css }, origin );
		}
	}, [ css, origin ] );

	useEffect( () => {
		ready.current = false;
		setLoading( true );
	}, [ src ] );

	// The session-expired screen opens in a small modal inside wp-admin.
	const interim = screen === 'interim';
	// Desktop: a 1024–1440px window, as wide as the stage allows while staying legible,
	// so the preview fills the stage on wide screens.
	const desktop = Math.round( Math.max( 1024, Math.min( WIDTHS.wide, ( size.w - 32 ) / 0.6 ) ) );
	const width = interim ? 400 : ( device === 'desktop' ? desktop : WIDTHS[ device ] || desktop );
	const scale = zoom === '100' ? 1 : Math.min( 1, ( size.w - 32 ) / width );
	const height = interim ? Math.max( 480, Math.min( 620, size.h - 48 ) ) : Math.max( 400, ( size.h - 32 ) / scale );

	return (
		<div className={ 'al-preview al-preview--' + ( interim ? 'interim' : device ) + ( zoom === '100' ? ' is-actual' : '' ) } ref={ wrap }>
			<div
				className="al-preview__device"
				style={ { width: width * scale, height: height * scale } }
			>
				<iframe
					ref={ frame }
					key={ src }
					src={ src }
					title={ __( 'Login page preview', 'modify-login' ) }
					/* The preview is for looking; keyboard focus stays in the settings. */
					tabIndex={ -1 }
					data-screen={ screen }
					style={ { width, height, transform: `scale(${ scale })` } }
					onLoad={ () => setLoading( false ) }
				/>
			</div>
			{ loading ? (
				<div className="al-preview__loading">
					<Spinner />
				</div>
			) : null }
		</div>
	);
}
