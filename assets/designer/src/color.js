/**
 * Colour maths for contrast warnings (mirrors inc/Designer/Color.php).
 */

export function parse( color ) {
	if ( typeof color !== 'string' ) {
		return null;
	}
	const c = color.trim().toLowerCase();
	if ( c === 'transparent' ) {
		return [ 0, 0, 0, 0 ];
	}
	let m = c.match( /^#([0-9a-f]{3,8})$/ );
	if ( m ) {
		let hex = m[ 1 ];
		if ( hex.length === 3 || hex.length === 4 ) {
			hex = hex.split( '' ).map( ( ch ) => ch + ch ).join( '' );
		}
		if ( hex.length !== 6 && hex.length !== 8 ) {
			return null;
		}
		return [
			parseInt( hex.slice( 0, 2 ), 16 ),
			parseInt( hex.slice( 2, 4 ), 16 ),
			parseInt( hex.slice( 4, 6 ), 16 ),
			hex.length === 8 ? parseInt( hex.slice( 6, 8 ), 16 ) / 255 : 1,
		];
	}
	m = c.match( /^rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*(?:,\s*([\d.]+)\s*)?\)$/ );
	if ( m ) {
		return [ +m[ 1 ], +m[ 2 ], +m[ 3 ], m[ 4 ] === undefined ? 1 : +m[ 4 ] ];
	}
	return null;
}

export function over( top, bottom ) {
	const t = parse( top );
	const b = parse( bottom ) || [ 255, 255, 255, 1 ];
	if ( ! t ) {
		return b;
	}
	const a = t[ 3 ];
	return [ 0, 1, 2 ].map( ( i ) => t[ i ] * a + b[ i ] * ( 1 - a ) ).concat( 1 );
}

function lum( rgb ) {
	const [ r, g, b ] = rgb.slice( 0, 3 ).map( ( v ) => {
		const c = v / 255;
		return c <= 0.03928 ? c / 12.92 : Math.pow( ( c + 0.055 ) / 1.055, 2.4 );
	} );
	return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

export function toCss( rgb ) {
	return 'rgb(' + rgb.slice( 0, 3 ).map( Math.round ).join( ',' ) + ')';
}

/**
 * Contrast ratio of a (possibly translucent) foreground over an opaque background.
 *
 * @param {string}       fg Foreground.
 * @param {string|Array} bg Background (css string or rgb array).
 * @return {number} Ratio.
 */
export function contrast( fg, bg ) {
	const back = Array.isArray( bg ) ? bg : over( bg, '#ffffff' );
	const front = over( fg, toCss( back ) );
	const l1 = lum( front );
	const l2 = lum( back );
	return ( Math.max( l1, l2 ) + 0.05 ) / ( Math.min( l1, l2 ) + 0.05 );
}
