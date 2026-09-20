/**
 * Pins the JavaScript's distance maths against includes/class-geo.php.
 *
 * This cross-check belongs here and nowhere else. When the list is preloaded
 * the browser sorts by distance itself, so the JavaScript repeats Geo's
 * arithmetic. If the two drift, client and server order the same list
 * differently and every PHP test stays green: nothing on the server can see a
 * number the browser computed. So these cases read the PHP source as text and
 * assert the JavaScript agrees — with the constants, with the derivation, and
 * with the shape of the formula.
 *
 * It is deliberately brittle. Editing includes/class-geo.php and not
 * assets/js/locator.js must fail here; that is the entire job. Reformatting
 * the PHP will also fail it, and the fix in that case is to re-read both files
 * and update this pin on purpose, which is cheaper than a silently divergent
 * sort.
 *
 * On the mile: the plugin's own is 6371.0 / 3958.8 = 1.6093261, not the true
 * 1.609344. That is deliberate and documented in Geo — the box and the
 * distance must agree with each other more than either must agree with a
 * standards body — so the JavaScript has to agree with *that*, and a case
 * below pins the true figure as the wrong answer.
 */

'use strict';

const { test } = require( 'node:test' );
const assert = require( 'node:assert' );

const { loadLocator, pluginSource, plain } = require( './harness.js' );

const GEO_SOURCE = pluginSource( 'includes', 'class-geo.php' );

/**
 * The right-hand side of one `public const NAME = ...;` in class-geo.php.
 *
 * @param {string} name Constant name.
 * @returns {string} The expression, trimmed, without its semicolon.
 */
function phpConst( name ) {
	const match = GEO_SOURCE.match( new RegExp( 'public const ' + name + '\\s*=\\s*([^;]+);' ) );

	assert.ok( match, 'class-geo.php no longer declares ' + name );

	return match[ 1 ].trim();
}

/**
 * A PHP method body with every run of whitespace collapsed to one space.
 *
 * @param {string} signature Text that starts the declaration.
 * @returns {string} The body between the opening brace and its match.
 */
function phpBody( signature ) {
	const start = GEO_SOURCE.indexOf( signature );

	assert.notStrictEqual( start, -1, 'class-geo.php no longer declares ' + signature );

	const open = GEO_SOURCE.indexOf( '{', start );
	let depth = 0;
	let end = open;

	for ( let i = open; i < GEO_SOURCE.length; i++ ) {
		if ( '{' === GEO_SOURCE[ i ] ) {
			depth++;
		} else if ( '}' === GEO_SOURCE[ i ] ) {
			depth--;

			if ( 0 === depth ) {
				end = i;
				break;
			}
		}
	}

	return GEO_SOURCE.slice( open + 1, end ).replace( /\s+/g, ' ' ).trim();
}

/**
 * SLOSM.Geo out of a sandbox.
 *
 * Called from inside each case rather than at file scope: against a skeleton
 * locator.js there is no SLOSM, and a throw at file scope would collapse
 * eleven independent failures into one unloadable file.
 *
 * @returns {object} The frozen Geo namespace.
 */
function loadGeo() {
	const { SLOSM } = loadLocator();

	assert.ok( SLOSM, 'assets/js/locator.js defined no window.SLOSM' );
	assert.ok( SLOSM.Geo, 'window.SLOSM has no Geo' );

	return SLOSM.Geo;
}

test( 'the earth radii are the two numbers class-geo.php declares', () => {
	const geo = loadGeo();

	assert.strictEqual( phpConst( 'EARTH_RADIUS_KM' ), '6371.0' );
	assert.strictEqual( phpConst( 'EARTH_RADIUS_MI' ), '3958.8' );

	assert.strictEqual( geo.EARTH_RADIUS_KM, 6371.0 );
	assert.strictEqual( geo.EARTH_RADIUS_MI, 3958.8 );
} );

test( 'the latitude degree is derived from the radius, not quoted from a table', () => {
	const geo = loadGeo();

	// The derivation is the point, not the number. Geo's own docblock records
	// that a quoted 111.32 made the bounding box 0.11% narrower than the
	// circle distance() measures, which dropped locations at the edge of a
	// search with no error anywhere.
	assert.strictEqual( phpConst( 'KM_PER_DEGREE' ), 'self::EARTH_RADIUS_KM * M_PI / 180.0' );

	assert.strictEqual( geo.KM_PER_DEGREE, ( 6371.0 * Math.PI ) / 180.0 );
	assert.strictEqual( geo.KM_PER_DEGREE, ( geo.EARTH_RADIUS_KM * Math.PI ) / 180.0 );

	// And it is the figure class-geo.php states in prose: 111.194927 km.
	assert.ok( Math.abs( geo.KM_PER_DEGREE - 111.194927 ) < 0.000001 );
} );

test( 'the box margin and the cosine epsilon are the ones class-geo.php declares', () => {
	const geo = loadGeo();

	assert.strictEqual( phpConst( 'BOX_MARGIN' ), '1.001' );
	assert.strictEqual( phpConst( 'MIN_COSINE' ), '1.0e-6' );

	assert.strictEqual( geo.BOX_MARGIN, 1.001 );
	assert.strictEqual( geo.MIN_COSINE, 1.0e-6 );
} );

test( 'the unit list is the one class-geo.php declares', () => {
	const geo = loadGeo();

	assert.strictEqual( phpConst( 'UNITS' ), "array( 'km', 'mi' )" );

	assert.deepStrictEqual( plain( geo.UNITS ), [ 'km', 'mi' ] );
	assert.ok( Object.isFrozen( geo.UNITS ) );
} );

test( 'the haversine expression is the one class-geo.php computes', () => {
	loadGeo();

	// A constants check alone would pass a JavaScript that used the spherical
	// law of cosines or swapped a lat for a lng. This pins the PHP body token
	// for token, so an edit to the maths on one side has to be answered on
	// the other. The clamp inside asin() has its own case below.
	assert.strictEqual(
		phpBody( 'public static function distance(' ),
		'$radius = self::earth_radius( $unit ); ' +
			'$d_lat = deg2rad( $lat2 - $lat1 ); ' +
			'$d_lng = deg2rad( $lng2 - $lng1 ); ' +
			'$a = sin( $d_lat / 2 ) ** 2 + cos( deg2rad( $lat1 ) ) * cos( deg2rad( $lat2 ) ) * sin( $d_lng / 2 ) ** 2; ' +
			'return $radius * 2 * asin( min( 1.0, sqrt( $a ) ) );'
	);

	assert.strictEqual(
		phpBody( 'private static function earth_radius(' ),
		"return 'mi' === $unit ? self::EARTH_RADIUS_MI : self::EARTH_RADIUS_KM;"
	);
} );

test( 'the JavaScript measures the same figures the PHP suite asserts', () => {
	const geo = loadGeo();

	// The same points and the same expectations as tests/test-geo.php, so both
	// implementations are checked against one set of independently computed
	// figures rather than against each other.
	assert.ok( Math.abs( geo.distance( 52.2297, 21.0122, 50.0647, 19.945, 'km' ) - 252.0 ) < 2.0 );
	assert.ok( Math.abs( geo.distance( 52.2297, 21.0122, 50.0647, 19.945, 'mi' ) - 156.6 ) < 2.0 );
	assert.strictEqual( geo.distance( 52.0, 21.0, 52.0, 21.0, 'km' ), 0 );
	assert.ok( Math.abs( geo.distance( 45.0, 21.0122, -45.0, -158.9878, 'km' ) - 20015.09 ) < 0.01 );

	// A degree of longitude is 111.19 km at the equator and 68.11 km at
	// Warsaw's latitude. Swapping the lat/lng argument order makes both
	// 111.19, so this pins the signature as well as the cos(lat) term.
	assert.ok( Math.abs( geo.distance( 0.0, 0.0, 0.0, 1.0, 'km' ) - 111.19 ) < 0.01 );
	assert.ok( Math.abs( geo.distance( 52.2297, 21.0122, 52.2297, 22.0122, 'km' ) - 68.11 ) < 0.01 );
} );

test( 'the clamp inside asin keeps a near-antipodal pair off NaN', () => {
	const geo = loadGeo();

	// This case exists because the claim it replaces was wrong. An earlier
	// version of this file, of locator.js, of class-geo.php and of the commit
	// message all said a sweep of 400,000 near-antipodal pairs found no
	// sqrt( a ) above 1, and concluded that "neither suite can buy that
	// insurance". The sweep walked *exact* antipodes — lat2 = -lat1,
	// lng2 = lng1 ± 180 — which really do give zero hits, on every engine
	// here, at full precision and rounded to 6, 8, 10 and 12 decimal places.
	// The hits need a genuine perturbation of about 1e-13 degrees, and then
	// there are 305 per 400,000 on this V8 and 230 on both PHP binaries.
	//
	// So the clamp is reachable, and deleting it is a mutation both suites now
	// kill. Measured on this engine for the pair below:
	//
	//   a         = 1.0000000000000004
	//   sqrt( a ) = 1.0000000000000002
	//   clamped   = 20015.086796020572
	//   unclamped = NaN
	//
	// The literal is engine-specific and has to be. V8's libm and PHP's round
	// `a` differently, so this pair comes out to exactly 1 under PHP — checked
	// — and PHP's own pair comes out to exactly 1 here. tests/test-geo.php
	// carries its own, for the same reason and with the same comment.
	const km = geo.distance(
		-61.408951158847145,
		168.867989163019,
		61.40895115884711,
		-11.132010836981044
	);

	assert.ok( ! Number.isNaN( km ), 'asin() was handed a value above 1: the clamp is gone' );
	assert.ok( Math.abs( km - 20015.086796020572 ) < 1e-6 );

	// And the assertion above is only meaningful if this pair really does
	// overshoot on this engine. Recomputing the haversine term here is the
	// control: if a future V8 rounds it back to 1, this fails and says the
	// literal needs replacing rather than quietly testing nothing.
	const dLat = ( ( 61.40895115884711 - -61.408951158847145 ) * Math.PI ) / 180;
	const dLng = ( ( -11.132010836981044 - 168.867989163019 ) * Math.PI ) / 180;
	const a =
		Math.sin( dLat / 2 ) ** 2 +
		Math.cos( ( -61.408951158847145 * Math.PI ) / 180 ) *
			Math.cos( ( 61.40895115884711 * Math.PI ) / 180 ) *
			Math.sin( dLng / 2 ) ** 2;

	assert.ok(
		Math.sqrt( a ) > 1,
		'this engine no longer overshoots on this pair, so the case above proves nothing: find a new one'
	);
} );

test( 'the same pair measures identically in both directions', () => {
	const geo = loadGeo();

	// Identical, not close: every term is symmetric in the pair, so one bit of
	// difference would mean an argument used where its partner belongs.
	assert.strictEqual(
		geo.distance( 52.2297, 21.0122, 50.0647, 19.945, 'km' ),
		geo.distance( 50.0647, 19.945, 52.2297, 21.0122, 'km' )
	);
} );

test( "a mile here is the plugin's mile, not the true one", () => {
	const geo = loadGeo();
	const km = geo.distance( 52.2297, 21.0122, 50.0647, 19.945, 'km' );
	const mi = geo.distance( 52.2297, 21.0122, 50.0647, 19.945, 'mi' );
	const ratio = km / mi;

	// 6371.0 / 3958.8 = 1.60932606, which is 18 metres per mile away from the
	// statute 1.609344. Agreeing with the server matters more than agreeing
	// with the standard: a browser using 1.609344 would sort a preloaded list
	// into a different order from the one /stores returns.
	assert.ok( Math.abs( ratio - 6371.0 / 3958.8 ) < 1e-12, "the ratio is not the plugin's own" );
	assert.ok( Math.abs( ratio - 1.609344 ) > 1e-6, 'the JavaScript has quietly adopted the statute mile' );
} );

test( 'an unrecognised unit measures in kilometres, as Geo does', () => {
	const geo = loadGeo();
	const km = geo.distance( 52.2297, 21.0122, 50.0647, 19.945, 'km' );

	assert.strictEqual( geo.distance( 52.2297, 21.0122, 50.0647, 19.945, 'miles' ), km );
	assert.strictEqual( geo.distance( 52.2297, 21.0122, 50.0647, 19.945, '' ), km );
	assert.strictEqual( geo.distance( 52.2297, 21.0122, 50.0647, 19.945 ), km );
} );

test( 'the constants cannot be reassigned by accident', () => {
	const geo = loadGeo();

	assert.ok( Object.isFrozen( geo ), 'SLOSM.Geo must be frozen: it is a copy of a PHP class' );
	assert.throws( () => {
		geo.EARTH_RADIUS_KM = 1;
	}, TypeError );
} );
