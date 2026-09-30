/**
 * Small helpers for the design object.
 */
import { applyFilters } from '@wordpress/hooks';

/**
 * Read a value by path ("form.width").
 *
 * @param {Object} obj  Object.
 * @param {string} path Dotted path.
 * @return {*} Value.
 */
export function getIn( obj, path ) {
	return path.split( '.' ).reduce( ( acc, key ) => ( acc && typeof acc === 'object' ? acc[ key ] : undefined ), obj );
}

/**
 * Immutable set by path.
 *
 * @param {Object} obj   Object.
 * @param {string} path  Dotted path.
 * @param {*}      value Value.
 * @return {Object} New object.
 */
export function setIn( obj, path, value ) {
	const [ head, ...rest ] = path.split( '.' );
	const copy = Array.isArray( obj ) ? [ ...obj ] : { ...( obj || {} ) };
	copy[ head ] = rest.length ? setIn( copy[ head ], rest.join( '.' ), value ) : value;
	return copy;
}

/**
 * Deep-merge a partial design over a base (one level of sections).
 *
 * @param {Object} base    Base design.
 * @param {Object} partial Partial.
 * @return {Object} Merged.
 */
export function mergeDesign( base, partial ) {
	const out = { ...base };
	Object.keys( partial || {} ).forEach( ( key ) => {
		const value = partial[ key ];
		if ( value && typeof value === 'object' && ! Array.isArray( value ) && base[ key ] && typeof base[ key ] === 'object' ) {
			out[ key ] = { ...base[ key ], ...value };
		} else {
			out[ key ] = value;
		}
	} );
	return out;
}

/**
 * Resolve a stored image reference (plugin:images/x.svg) to a URL.
 *
 * @param {string} value Stored value.
 * @return {string} URL.
 */
export function imageUrl( value ) {
	if ( typeof value === 'string' && value.indexOf( 'plugin:images/' ) === 0 ) {
		return ( window.authlifyDesigner?.imagesBase || '' ) + value.slice( 14 );
	}
	return value || '';
}

/**
 * The fields that change the login page markup (need a reload, not just new CSS).
 *
 * @param {Object} d Design.
 * @return {string} Signature.
 */
export function structure( d ) {
	/**
	 * Filters the design values that change the login page markup, so the
	 * preview reloads (not just restyles) when they change.
	 *
	 * @param {Array}  values Values.
	 * @param {Object} d      Design.
	 */
	return JSON.stringify( applyFilters( 'authlify.designer.structure', [
		d.layout,
		d.logo?.type,
		d.logo?.text,
		d.logo?.title,
		d.logo?.link,
		d.text?.message,
		d.text?.button_label,
		d.text?.footer,
		d.links?.hide_backtoblog,
		d.links?.hide_language,
		d.links?.hide_privacy,
		d.template,
	], d ) );
}
