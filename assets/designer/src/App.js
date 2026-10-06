/**
 * The login designer app.
 */
import { __, sprintf } from '@wordpress/i18n';
import { useState, useEffect, useRef, useCallback, useMemo } from '@wordpress/element';
import { addAction, removeAction } from '@wordpress/hooks';
import apiFetch from '@wordpress/api-fetch';
import { Button, ToggleControl, Notice, SelectControl, Panel, DropdownMenu, MenuGroup, MenuItem } from '@wordpress/components';
import { setIn, mergeDesign, structure } from './utils';
import { Gallery, Sections, ContrastNotice } from './sections';
import Preview from './Preview';

const data = window.authlifyDesigner || {};
const MAX_HISTORY = 100;

/**
 * Screens the preview can show: key => query args.
 *
 * @return {Array} Screens.
 */
function screens() {
	return [
		{ value: 'login', label: __( 'Log in', 'modify-login' ), args: {} },
		{ value: 'lostpassword', label: __( 'Lost password', 'modify-login' ), args: { action: 'lostpassword' } },
		{ value: 'register', label: __( 'Register', 'modify-login' ), args: { action: 'register', authlify_screen: 'register' } },
		{ value: 'resetpass', label: __( 'Reset password', 'modify-login' ), args: { action: 'rp', authlify_screen: 'resetpass' } },
		{ value: '2fa', label: __( 'Two-factor step', 'modify-login' ), args: { authlify_screen: '2fa' } },
		{ value: 'lockout', label: __( 'Lockout message', 'modify-login' ), args: { authlify_screen: 'lockout' } },
		{ value: 'confirm', label: __( 'Confirm admin email', 'modify-login' ), args: { action: 'confirm_admin_email' } },
		{ value: 'logout', label: __( 'Log out confirmation', 'modify-login' ), args: { action: 'logout' } },
		{ value: 'interim', label: __( 'Session expired (modal)', 'modify-login' ), args: { 'interim-login': '1' } },
	];
}

/**
 * Hosts of images or videos in a design that live on another site.
 *
 * @param {Object} d Design.
 * @return {string[]} Hosts.
 */
function remoteImages( d ) {
	const local = [ window.location.host ];
	try {
		local.push( new URL( data.homeUrl ).host );
	} catch ( e ) {}
	const out = [];
	const walk = ( v ) => {
		if ( typeof v === 'string' ) {
			if ( /^(https?:)?\/\//i.test( v ) ) {
				try {
					const host = new URL( v, window.location.href ).host;
					if ( local.indexOf( host ) < 0 && out.indexOf( host ) < 0 ) {
						out.push( host );
					}
				} catch ( e ) {}
			}
		} else if ( v && typeof v === 'object' ) {
			Object.keys( v ).forEach( ( k ) => {
				// Links are fine; only files the login page loads matter.
				if ( k !== 'link' ) {
					walk( v[ k ] );
				}
			} );
		}
	};
	walk( d );
	return out;
}

export default function App() {
	// Unsaved changes from an earlier session are restored straight away (they
	// stay a draft until saved); the notice offers to discard them.
	const [ history, setHistory ] = useState( () => ( { past: [], present: data.draft || data.design, future: [] } ) );
	const [ saved, setSaved ] = useState( data.design );
	const [ css, setCss ] = useState( '' );
	const [ reloadKey, setReloadKey ] = useState( 0 );
	const [ screen, setScreen ] = useState( 'login' );
	const [ device, setDevice ] = useState( 'desktop' );
	const [ zoom, setZoom ] = useState( 'fit' );
	const [ busy, setBusy ] = useState( false );
	const [ matching, setMatching ] = useState( false );
	const [ notice, setNotice ] = useState( null );
	const [ restored, setRestored ] = useState( !! data.draft );
	const [ , setAddons ] = useState( 0 );
	const lastEdit = useRef( { path: '', time: 0 } );
	const lastStructure = useRef( structure( data.draft || data.design ) );
	const warnedCss = useRef( ( data.draft || data.design ).custom_css || '' );
	const fileInput = useRef( null );
	const noticesRef = useRef( null );
	// What the page opened with (a restored draft is already stored on the server).
	const loaded = useRef( JSON.stringify( data.draft || data.design ) );

	const design = history.present;
	const dirty = useMemo( () => JSON.stringify( design ) !== JSON.stringify( saved ), [ design, saved ] );

	// A message about an action (saved, template applied) is about that action
	// only: the next edit clears it.
	const noticeDesign = useRef( null );
	useEffect( () => {
		if ( notice && notice.status !== 'error' && noticeDesign.current && noticeDesign.current !== design ) {
			setNotice( null );
		}
	}, [ design ] ); // eslint-disable-line react-hooks/exhaustive-deps
	const showNotice = ( next, forDesign ) => {
		noticeDesign.current = forDesign || null;
		setNotice( next );
	};

	// Add-ons that register their panels after the first render still show up.
	useEffect( () => {
		addAction( 'hookAdded', 'authlify/designer', ( hookName ) => {
			if ( typeof hookName === 'string' && hookName.indexOf( 'authlify.designer.' ) === 0 ) {
				setAddons( ( n ) => n + 1 );
			}
		} );
		return () => removeAction( 'hookAdded', 'authlify/designer' );
	}, [] );

	/**
	 * Replace the design as one undo step.
	 */
	const commit = useCallback( ( next ) => {
		lastEdit.current = { path: '', time: 0 };
		setHistory( ( h ) => ( { past: [ ...h.past, h.present ].slice( -MAX_HISTORY ), present: next, future: [] } ) );
	}, [] );

	/**
	 * Change one field. Rapid edits of the same field (dragging a slider or colour) are one undo step.
	 */
	const set = useCallback( ( path, value, extra ) => {
		setHistory( ( h ) => {
			let next = setIn( h.present, path, value );
			if ( extra ) {
				Object.keys( extra ).forEach( ( k ) => {
					next = setIn( next, k, extra[ k ] );
				} );
			}
			const now = Date.now();
			const coalesce = lastEdit.current.path === path && now - lastEdit.current.time < 800;
			lastEdit.current = { path, time: now };
			if ( coalesce ) {
				return { ...h, present: next };
			}
			return { past: [ ...h.past, h.present ].slice( -MAX_HISTORY ), present: next, future: [] };
		} );
	}, [] );

	const undo = useCallback( () => {
		lastEdit.current = { path: '', time: 0 };
		setHistory( ( h ) => ( h.past.length ? { past: h.past.slice( 0, -1 ), present: h.past[ h.past.length - 1 ], future: [ h.present, ...h.future ] } : h ) );
	}, [] );

	const redo = useCallback( () => {
		lastEdit.current = { path: '', time: 0 };
		setHistory( ( h ) => ( h.future.length ? { past: [ ...h.past, h.present ], present: h.future[ 0 ], future: h.future.slice( 1 ) } : h ) );
	}, [] );

	// Keyboard: Ctrl/Cmd+Z, Ctrl/Cmd+Shift+Z, Ctrl/Cmd+Y (not while typing in a text box).
	useEffect( () => {
		const onKey = ( e ) => {
			const mod = e.metaKey || e.ctrlKey;
			if ( ! mod ) {
				return;
			}
			const tag = ( e.target && e.target.tagName ) || '';
			if ( tag === 'TEXTAREA' || ( tag === 'INPUT' && e.target.type === 'text' ) ) {
				return;
			}
			const key = e.key.toLowerCase();
			if ( key === 'z' && ! e.shiftKey ) {
				e.preventDefault();
				undo();
			} else if ( ( key === 'z' && e.shiftKey ) || key === 'y' ) {
				e.preventDefault();
				redo();
			} else if ( key === 's' ) {
				e.preventDefault();
				document.getElementById( 'authlify-designer-save' )?.click();
			}
		};
		window.addEventListener( 'keydown', onKey );
		return () => window.removeEventListener( 'keydown', onKey );
	}, [ undo, redo ] );

	// Warn before leaving with changes made on this visit. A draft restored
	// from an earlier session is already stored, so it alone is no reason to ask.
	useEffect( () => {
		const onLeave = ( e ) => {
			if ( dirty && JSON.stringify( design ) !== loaded.current ) {
				e.preventDefault();
				e.returnValue = '';
			}
		};
		window.addEventListener( 'beforeunload', onLeave );
		return () => window.removeEventListener( 'beforeunload', onLeave );
	}, [ dirty, design ] );

	// Push the draft to the server (debounced); CSS goes to the preview by postMessage,
	// markup changes (layout, logo text, custom texts) reload the preview.
	useEffect( () => {
		const timer = setTimeout( () => {
			apiFetch( { path: data.restPath + '/draft', method: 'POST', data: { design } } )
				.then( ( res ) => {
					setCss( res.css );
					const sig = structure( design );
					if ( sig !== lastStructure.current ) {
						lastStructure.current = sig;
						setReloadKey( ( k ) => k + 1 );
					}
					// CSS warnings only when the custom CSS itself changed.
					const cssNow = design.custom_css || '';
					if ( res.warnings && res.warnings.length && cssNow !== warnedCss.current ) {
						setNotice( { status: 'warning', text: res.warnings.join( ' ' ) } );
					}
					warnedCss.current = cssNow;
				} )
				.catch( ( err ) => setNotice( { status: 'error', text: ( err && err.message ) || __( 'The preview could not be updated.', 'modify-login' ) } ) );
		}, 300 );
		return () => clearTimeout( timer );
	}, [ design ] );

	const save = () => {
		setBusy( true );
		apiFetch( { path: data.restPath, method: 'POST', data: { design } } )
			.then( ( res ) => {
				setSaved( res.design );
				setHistory( ( h ) => ( { ...h, present: res.design } ) );
				setRestored( false );
				const text = res.design.enabled
					? __( 'Design saved. It is live on your login page.', 'modify-login' )
					: __( 'Design saved. It is switched off, so visitors still see the standard WordPress login page.', 'modify-login' );
				showNotice( { status: res.warnings && res.warnings.length ? 'warning' : 'success', text: [ text, ...( res.warnings || [] ) ].join( ' ' ) }, res.design );
			} )
			.catch( ( err ) => setNotice( { status: 'error', text: err.message || __( 'Saving failed.', 'modify-login' ) } ) )
			.finally( () => {
				setBusy( false );
				// The Save button is disabled once nothing is left to save, which
				// drops keyboard focus to the page: move it to the result instead.
				setTimeout( () => {
					const active = document.activeElement;
					if ( ! active || active === document.body || active.disabled || active.id === 'authlify-designer-save' ) {
						noticesRef.current?.focus();
					}
				}, 0 );
			} );
	};

	const applyTemplate = ( tpl ) => {
		const next = { ...tpl.design, enabled: true, custom_css: tpl.design.custom_css || '' };
		commit( next );
		setRestored( false );
		/* translators: %s: template name. */
		showNotice( { status: 'info', text: sprintf( __( 'Template "%s" applied. Save to publish it.', 'modify-login' ), tpl.name ) }, next );
	};

	const matchSite = () => {
		setMatching( true );
		apiFetch( { path: data.restPath + '/match-site' } )
			.then( ( res ) => {
				const next = { ...res.design, enabled: true };
				commit( next );
				const found = res.found || [];
				const missing = res.missing || [];
				const parts = [];
				if ( found.length ) {
					/* translators: %s: list of things taken from the theme. */
					parts.push( sprintf( __( 'From your theme: %s.', 'modify-login' ), found.join( ', ' ) ) );
				}
				if ( missing.length ) {
					/* translators: %s: list of things the theme does not define. */
					parts.push( sprintf( __( 'Not found, so neutral defaults are used: %s.', 'modify-login' ), missing.join( ', ' ) ) );
				}
				if ( ! found.length ) {
					parts.push( __( 'Your theme does not describe its colours or font.', 'modify-login' ) );
				}
				parts.push( __( 'Review it, then save.', 'modify-login' ) );
				showNotice( {
					status: missing.length && ! found.length ? 'warning' : 'info',
					text: parts.join( ' ' ),
					actions: [ { label: __( 'Undo', 'modify-login' ), onClick: () => { undo(); setNotice( null ); } } ],
				}, next );
			} )
			.catch( ( err ) => setNotice( { status: 'error', text: ( err && err.message ) || __( 'Your theme could not be read.', 'modify-login' ) } ) )
			.finally( () => setMatching( false ) );
	};

	const revert = () => commit( saved );

	// Resetting keeps the current on/off state: it changes the look, not whether it is live.
	const resetDefault = () => {
		const tpl = ( data.templates || [] ).find( ( t ) => t.key === 'default' );
		if ( tpl ) {
			commit( { ...tpl.design, enabled: !! design.enabled, custom_css: '' } );
			setNotice( null );
		}
	};

	const discardRestored = () => {
		commit( saved );
		setRestored( false );
	};

	const plainWordPress = () => commit( { ...data.defaults, enabled: false } );

	const exportJson = () => {
		const blob = new Blob( [ JSON.stringify( { plugin: 'authlify', type: 'login-design', version: design.version, design }, null, 2 ) ], { type: 'application/json' } );
		const url = URL.createObjectURL( blob );
		const a = document.createElement( 'a' );
		a.href = url;
		a.download = 'authlify-login-design.json';
		document.body.appendChild( a );
		a.click();
		a.remove();
		setTimeout( () => URL.revokeObjectURL( url ), 1000 );
	};

	const importJson = ( e ) => {
		const file = e.target.files && e.target.files[ 0 ];
		e.target.value = '';
		if ( ! file ) {
			return;
		}
		let remote = [];
		file.text()
			.then( ( text ) => {
				let parsed;
				try {
					parsed = JSON.parse( text );
				} catch ( e ) {
					throw new Error( __( 'That file is not a valid design file (JSON). Export a design from this screen and import that file.', 'modify-login' ) );
				}
				const incoming = parsed && parsed.design ? parsed.design : parsed;
				if ( ! incoming || typeof incoming !== 'object' || Array.isArray( incoming ) || ! ( 'tokens' in incoming || 'form' in incoming || 'layout' in incoming ) ) {
					throw new Error( __( 'That file does not contain a login design.', 'modify-login' ) );
				}
				const version = Number( ( parsed && parsed.version ) || incoming.version || 1 );
				if ( version > Number( data.defaults.version || 1 ) ) {
					throw new Error( __( 'That design was made with a newer version of Authlify. Update the plugin, then import it again.', 'modify-login' ) );
				}
				remote = remoteImages( incoming );
				// The server sanitizes it (and upgrades older versions).
				return apiFetch( { path: data.restPath + '/draft', method: 'POST', data: { design: mergeDesign( data.defaults, incoming ) } } );
			} )
			.then( ( res ) => {
				commit( res.design );
				if ( remote.length ) {
					showNotice( {
						status: 'warning',
						/* translators: %s: list of web addresses. */
						text: sprintf( __( 'Design imported. It loads images from another site (%s), so every visitor\'s browser will contact that site. Replace them with images from your Media Library before you save.', 'modify-login' ), remote.join( ', ' ) ),
					} );
				} else {
					showNotice( { status: 'success', text: __( 'Design imported. Review it, then save.', 'modify-login' ) }, res.design );
				}
			} )
			.catch( ( err ) => setNotice( { status: 'error', text: ( err && err.message ) || __( 'Import failed.', 'modify-login' ) } ) );
	};

	const screenList = screens();
	const current = screenList.find( ( s ) => s.value === screen ) || screenList[ 0 ];

	return (
		<div className="al-designer">
			<div className="al-bar" role="region" aria-label={ __( 'Designer toolbar', 'modify-login' ) }>
				<div className="al-bar__title">
					<h2>{ __( 'Login page design', 'modify-login' ) }</h2>
					<span className={ 'authlify-pill authlify-pill--' + ( saved.enabled ? 'ok' : 'neutral' ) }>
						{ saved.enabled ? __( 'Live', 'modify-login' ) : __( 'Off: WordPress default', 'modify-login' ) }
					</span>
					{ dirty ? <span className="al-bar__dirty">{ __( 'Unsaved changes', 'modify-login' ) }</span> : null }
				</div>
				<div className="al-bar__actions">
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Use this design', 'modify-login' ) }
						checked={ !! design.enabled }
						onChange={ ( v ) => set( 'enabled', v ) }
					/>
					<Button icon="undo" label={ __( 'Undo', 'modify-login' ) } onClick={ undo } disabled={ ! history.past.length } size="compact" />
					<Button icon="redo" label={ __( 'Redo', 'modify-login' ) } onClick={ redo } disabled={ ! history.future.length } size="compact" />
					<DropdownMenu icon="ellipsis" label={ __( 'More actions', 'modify-login' ) } toggleProps={ { size: 'compact' } }>
						{ ( { onClose } ) => (
							<>
								<MenuGroup>
									<MenuItem onClick={ () => { revert(); onClose(); } } disabled={ ! dirty }>{ __( 'Revert to saved', 'modify-login' ) }</MenuItem>
									<MenuItem onClick={ () => { resetDefault(); onClose(); } }>{ __( 'Reset to Default template', 'modify-login' ) }</MenuItem>
									<MenuItem onClick={ () => { plainWordPress(); onClose(); } }>{ __( 'Start from plain WordPress', 'modify-login' ) }</MenuItem>
								</MenuGroup>
								<MenuGroup>
									<MenuItem onClick={ () => { exportJson(); onClose(); } }>{ __( 'Export design (JSON)', 'modify-login' ) }</MenuItem>
									<MenuItem onClick={ () => { fileInput.current?.click(); onClose(); } }>{ __( 'Import design…', 'modify-login' ) }</MenuItem>
								</MenuGroup>
							</>
						) }
					</DropdownMenu>
					<input ref={ fileInput } type="file" accept="application/json,.json" hidden onChange={ importJson } />
					<Button id="authlify-designer-save" variant="primary" onClick={ save } isBusy={ busy } disabled={ busy || ! dirty }>
						{ __( 'Save', 'modify-login' ) }
					</Button>
				</div>
			</div>

			<div className="al-notices" aria-live="polite" tabIndex={ -1 } ref={ noticesRef }>
				{ notice ? (
					<Notice status={ notice.status } onRemove={ () => setNotice( null ) } className="al-notice" actions={ notice.actions || [] }>
						{ notice.text }
					</Notice>
				) : null }
				{ restored && dirty && ! history.past.length ? (
					<Notice
						status="info"
						className="al-notice"
						onRemove={ () => setRestored( false ) }
						actions={ [ { label: __( 'Discard them', 'modify-login' ), onClick: discardRestored } ] }
					>
						{ __( 'Your unsaved changes from an earlier session are back. Save to publish them, or discard them.', 'modify-login' ) }
					</Notice>
				) : null }
				{ data.legacy ? (
					<Notice status="info" isDismissible={ false } className="al-notice">
						{ __( 'This design was carried over from Modify Login 2.x and keeps WordPress\'s placement. Pick a template for a fresh look.', 'modify-login' ) }
					</Notice>
				) : null }
			</div>

			<div className="al-main">
				<aside className="al-side" aria-label={ __( 'Design settings', 'modify-login' ) }>
					<Panel>
						<Gallery design={ design } applyTemplate={ applyTemplate } matchSite={ matchSite } matching={ matching } />
						<Sections design={ design } set={ set } />
					</Panel>
				</aside>
				<section className="al-stage" aria-label={ __( 'Preview', 'modify-login' ) }>
					<div className="al-stage__bar">
						<SelectControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize={ false }
							size="compact"
							label={ __( 'Screen', 'modify-login' ) }
							hideLabelFromVision
							value={ screen }
							options={ screenList.map( ( s ) => ( { value: s.value, label: s.label } ) ) }
							onChange={ setScreen }
						/>
						<div className="al-devices" role="group" aria-label={ __( 'Device', 'modify-login' ) }>
							{ [
								[ 'desktop', 'desktop', __( 'Desktop', 'modify-login' ) ],
								[ 'tablet', 'tablet', __( 'Tablet', 'modify-login' ) ],
								[ 'phone', 'smartphone', __( 'Phone', 'modify-login' ) ],
							].map( ( [ key, icon, label ] ) => (
								<Button key={ key } icon={ icon } label={ label } isPressed={ device === key } onClick={ () => setDevice( key ) } size="compact" />
							) ) }
						</div>
						<Button
							className="al-zoom"
							size="compact"
							variant="tertiary"
							isPressed={ zoom === '100' }
							aria-pressed={ zoom === '100' }
							label={ zoom === '100' ? __( '100%: fit the preview to the screen', 'modify-login' ) : __( '100%: show the preview at actual size', 'modify-login' ) }
							showTooltip
							onClick={ () => setZoom( zoom === '100' ? 'fit' : '100' ) }
						>
							100%
						</Button>
						{ ! design.enabled ? <span className="al-stage__off">{ __( 'The design is off, so visitors see the WordPress default.', 'modify-login' ) }</span> : null }
					</div>
					<ContrastNotice design={ design } />
					<Preview args={ current.args } device={ device } css={ css } reloadKey={ reloadKey } screen={ screen } zoom={ zoom } />
				</section>
			</div>
		</div>
	);
}
