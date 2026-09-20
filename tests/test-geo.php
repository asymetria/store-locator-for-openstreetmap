<?php
/**
 * Pins the distance and bounding-box maths.
 *
 * Two kinds of case live here. The distance cases check numbers against figures
 * arrived at independently of this implementation — Vincenty's inverse formula
 * on the WGS-84 ellipsoid, which shares neither algorithm nor earth model with
 * haversine, agrees on 252.16 km for the Warsaw–Kraków pair against haversine's
 * 251.98, and published city-to-city figures say about 252–253 km — so an
 * expectation here cannot be a transcription of whatever the code happens to
 * return. The bounding-box cases are about the two places the box stops being a
 * simple rectangle: the poles, where meridians converge, and the antimeridian,
 * where a naive box turns inside out.
 *
 * Nothing here touches stub state and nothing here calls WordPress, because Geo
 * does neither; no before_each() is needed.
 *
 * @package Store_Locator_For_OpenStreetMap
 */

require_once dirname( __DIR__ ) . '/includes/class-geo.php';

use Asymetria\StoreLocator\Geo;

describe(
	'geo distance',
	function () {

		it(
			'measures Warsaw to Krakow in kilometres',
			function () {
				$km = Geo::distance( 52.2297, 21.0122, 50.0647, 19.9450, 'km' );

				assert_close( 252.0, $km, 2.0 );
			}
		);

		it(
			'measures the same pair in miles',
			function () {
				$mi = Geo::distance( 52.2297, 21.0122, 50.0647, 19.9450, 'mi' );

				assert_close( 156.6, $mi, 2.0 );
			}
		);

		it(
			'returns zero for a point against itself',
			function () {
				assert_close( 0.0, Geo::distance( 52.0, 21.0, 52.0, 21.0, 'km' ), 0.0001 );
			}
		);

		it(
			'measures half the world between antipodes',
			function () {
				// An ordinary antipodal pair: half the circumference, and no
				// overshoot. 45°N against 45°S half a world away is an exact
				// antipode, and exact antipodes do not reach the clamp — see
				// the case below, which is the one that does.
				assert_close( 20015.09, Geo::distance( 45.0, 21.0122, -45.0, -158.9878, 'km' ), 0.01 );
			}
		);

		it(
			'refuses to hand asin() a value above 1',
			function () {
				// The case the clamp inside asin() exists for, and it took a
				// correction to find. This suite used to assert only the exact
				// antipode above and record, in its own comment, that the clamp
				// could be deleted with the suite staying green. That was true
				// of the pair being tested and false about the code: exact
				// antipodes give zero overshoots on both binaries, and the
				// sweep that "proved" the clamp unreachable had walked exact
				// antipodes and called them near-antipodal.
				//
				// Perturb an antipode by about 1e-13 degrees and the overshoot
				// is ordinary — 230 pairs per 400,000 on both 8.2 and 8.5. This
				// is one of them. Measured here, identically on both:
				//
				//   $a         = 1.00000000000000044409
				//   sqrt( $a ) = 1.00000000000000022204
				//   clamped    = 20015.086796020572
				//   unclamped  = NAN
				//
				// NAN is the failure that matters, because it is silent: it
				// compares false against every radius filter, so the location
				// disappears from the results with no error anywhere.
				$lat1 = 66.285632120578384;
				$lng1 = -161.37757434573842;
				$lat2 = -66.285632120578427;
				$lng2 = 18.622425654261534;

				assert_close( 20015.086796, Geo::distance( $lat1, $lng1, $lat2, $lng2, 'km' ), 0.000001 );

				// The control. The assertion above is only meaningful while
				// this pair really does overshoot on this libm, and the pair is
				// platform-specific — it comes out to exactly 1 under V8, which
				// is why tests/js/geo-crosscheck.test.js carries a different
				// one. If a future binary rounds this back to 1, this line
				// fails and says to find a new pair, rather than leaving a case
				// that asserts nothing and looks like it does.
				$a = sin( deg2rad( $lat2 - $lat1 ) / 2 ) ** 2
					+ cos( deg2rad( $lat1 ) ) * cos( deg2rad( $lat2 ) ) * sin( deg2rad( $lng2 - $lng1 ) / 2 ) ** 2;

				assert_true(
					sqrt( $a ) > 1.0,
					'this libm no longer overshoots on this pair, so the assertion above proves nothing: find a new one'
				);
			}
		);

		it(
			'measures the same distance in both directions',
			function () {
				$there = Geo::distance( 52.2297, 21.0122, 50.0647, 19.9450, 'km' );
				$back  = Geo::distance( 50.0647, 19.9450, 52.2297, 21.0122, 'km' );

				// Identical, not merely close: every term of the formula is
				// symmetric in the pair, so a difference of one bit would mean
				// an argument used where its partner belongs.
				assert_same( $there, $back );
			}
		);

		it(
			'shrinks a degree of longitude as latitude rises',
			function () {
				$equator = Geo::distance( 0.0, 0.0, 0.0, 1.0, 'km' );
				$warsaw  = Geo::distance( 52.2297, 21.0122, 52.2297, 22.0122, 'km' );

				// A degree of longitude is 111.19 km at the equator and 68.11 km
				// at Warsaw's latitude; a degree of latitude is 111.19 km
				// everywhere. So this pins two things at once: that the cos(lat)
				// term is really there, and that the signature is (lat, lng) and
				// not (lng, lat) — swapping the pairs makes both of these 111.19.
				assert_close( 111.19, $equator, 0.01 );
				assert_close( 68.11, $warsaw, 0.01 );
			}
		);

		it(
			'names the units a caller may hand it',
			function () {
				// Geo rejects nothing, so this list is what the settings
				// sanitiser, the shortcode handler and the REST
				// validate_callback check against instead of each remembering
				// that 'mi' is the only accepted spelling.
				assert_same( array( 'km', 'mi' ), Geo::UNITS );

				// And the list is not decorative: each entry measures something
				// different from the other.
				$measured = array();

				foreach ( Geo::UNITS as $unit ) {
					$measured[] = Geo::distance( 52.2297, 21.0122, 50.0647, 19.9450, $unit );
				}

				assert_same( 2, count( array_unique( $measured ) ) );
			}
		);

		it(
			'falls back to kilometres for a unit it does not know',
			function () {
				$km      = Geo::distance( 52.2297, 21.0122, 50.0647, 19.9450, 'km' );
				$unknown = Geo::distance( 52.2297, 21.0122, 50.0647, 19.9450, 'parsecs' );

				// Kilometres exactly, not "something plausible": an unreadable
				// unit must not silently pick a radius of its own.
				assert_same( $km, $unknown );
			}
		);
	}
);

describe(
	'geo bounding box',
	function () {

		it(
			'holds every point that is inside the search radius',
			function () {
				// The contract the other box cases cannot check. They assert
				// numbers, and a number can only ever agree with the constant it
				// was computed from — the first version of this file used a
				// latitude degree 0.11% too long, and every numeric case passed
				// while the box sat *inside* the circle, cutting real locations
				// at the edge of the radius before anything could measure them.
				//
				// This case asks the only question that matters to Task 7: take
				// a point the search is supposed to find, and is it in the box?
				// The offsets come from the haversine identity, not from
				// bounding_box() — due north is the angular radius along a
				// meridian, and due east on the same parallel is
				// 2·asin( sin( r/2R ) / cos( lat ) ) — so the two are derived
				// independently and only the sphere's radius is shared.
				$cases = array(
					array( 0.0, 21.0, 25.0 ),
					array( 52.2297, 21.0122, 25.0 ),
					array( 70.0, 21.0, 50.0 ),
					array( -70.0, 21.0, 50.0 ),
					array( 89.0, 21.0, 100.0 ),
				);

				foreach ( $cases as $case ) {
					list( $lat, $lng, $radius ) = $case;

					$box = Geo::bounding_box( $lat, $lng, $radius, 'km' );

					// Exactly the radius, not a shade inside it. A point at
					// 0.999 × radius does not test this: the 1.001 margin is
					// wider than that, so it covers a box built from a latitude
					// degree 0.11% too long and the wrong constant goes
					// unnoticed — measured, not guessed. Exactly the radius is
					// also not a knife-edge comparison precisely because of that
					// margin: the box clears the point by a tenth of a percent,
					// thousands of times any rounding step.
					$edge = $radius;
					$rho  = $edge / Geo::EARTH_RADIUS_KM;

					$north = rad2deg( $rho );
					$east  = rad2deg( 2 * asin( sin( $rho / 2 ) / cos( deg2rad( $lat ) ) ) );

					$points = array(
						array( $lat + $north, $lng ),
						array( $lat - $north, $lng ),
						array( $lat, $lng + $east ),
						array( $lat, $lng - $east ),
					);

					foreach ( $points as $point ) {
						$where = sprintf( '%.4f, %.4f near %.4f, %.4f at %.1f km', $point[0], $point[1], $lat, $lng, $radius );

						// First prove the point is where this case thinks it is,
						// so a mistake in the offsets above fails as itself
						// rather than as a bad box.
						assert_close( $edge, Geo::distance( $lat, $lng, $point[0], $point[1], 'km' ), 0.001, 'test point is not at the edge: ' . $where );

						assert_true( $point[0] >= $box['min_lat'] && $point[0] <= $box['max_lat'], 'box cuts it off in latitude: ' . $where );
						assert_true( $point[1] >= $box['min_lng'] && $point[1] <= $box['max_lng'], 'box cuts it off in longitude: ' . $where );
					}
				}
			}
		);

		it(
			'spans every meridian once the circle reaches over the pole',
			function () {
				// At a 100 km radius the circle touches the pole at latitude
				// 89.1007, and from there it spans every longitude. Above that
				// line the box has to be the full range.
				$over = Geo::bounding_box( 89.2, 21.0, 100.0, 'km' );

				assert_same( -180.0, $over['min_lng'] );
				assert_same( 180.0, $over['max_lng'] );

				// Just below it, the box must NOT give up: still a real,
				// narrower box, or "near the pole" becomes an excuse to return
				// the whole world and the prefilter stops filtering.
				$under = Geo::bounding_box( 89.0, 21.0, 100.0, 'km' );

				assert_true( $under['min_lng'] > -180.0 );
				assert_true( $under['max_lng'] < 180.0 );
			}
		);

		it(
			'spans every meridian for a radius that reaches round the globe',
			function () {
				// A quarter circumference is 10007.5 km, and past it the circle
				// contains a pole from any centre. The trap is that sin() turns
				// over there: taken at face value, 20000 km asks for a box
				// 0.14 degrees wide — narrower than a 10 km search, for a radius
				// covering the planet, and silently so. The equator is the worst
				// case, since a cosine of 1 cannot rescue it.
				foreach ( array( 10100.0, 20000.0, 40000.0 ) as $radius ) {
					$box = Geo::bounding_box( 0.0, 21.0, $radius, 'km' );

					assert_same( -180.0, $box['min_lng'], sprintf( 'min_lng at %.0f km', $radius ) );
					assert_same( 180.0, $box['max_lng'], sprintf( 'max_lng at %.0f km', $radius ) );
					assert_same( -90.0, $box['min_lat'], sprintf( 'min_lat at %.0f km', $radius ) );
					assert_same( 90.0, $box['max_lat'], sprintf( 'max_lat at %.0f km', $radius ) );
				}
			}
		);

		it(
			'builds a bounding box that widens with latitude',
			function () {
				$equator = Geo::bounding_box( 0.0, 0.0, 100.0, 'km' );
				$north   = Geo::bounding_box( 60.0, 0.0, 100.0, 'km' );

				$equator_width = $equator['max_lng'] - $equator['min_lng'];
				$north_width   = $north['max_lng'] - $north['min_lng'];

				// cos( 60 ) is 0.5, so the box at 60 degrees is twice as wide in
				// longitude as the one on the equator.
				assert_true( $north_width > $equator_width * 1.9 );
			}
		);

		it(
			'sizes a box in the unit it was given',
			function () {
				$km = Geo::bounding_box( 0.0, 0.0, 100.0, 'km' );
				$mi = Geo::bounding_box( 0.0, 0.0, 100.0, 'mi' );

				$ratio = ( $mi['max_lat'] - $mi['min_lat'] ) / ( $km['max_lat'] - $km['min_lat'] );

				// 100 miles is 1.6093 times 100 kilometres, that being
				// 6371.0 / 3958.8 — the class's own two radii, so the box can
				// never disagree with distance() about how long a mile is. A
				// bounding_box() that ignored $unit would score 1.0 here.
				assert_close( 1.6093, $ratio, 0.001 );
			}
		);

		it(
			'clamps latitude at the poles',
			function () {
				$box = Geo::bounding_box( 89.5, 0.0, 500.0, 'km' );

				assert_true( $box['max_lat'] <= 90.0 );
				assert_true( $box['min_lat'] >= -90.0 );
			}
		);

		it(
			'gives up on longitude at the pole rather than dividing by a cosine of nothing',
			function () {
				// Radius zero is the one input that tells the epsilon guard apart
				// from every other fallback. cos( deg2rad( 90 ) ) is 6.1e-17, so
				// the usual polar rescue — a longitude delta so wide it runs past
				// ±180 — never triggers here: 0 / 6.1e-17 is 0, and an unguarded
				// implementation would hand back a box one point wide at the
				// north pole. Only a guard that looks at the divisor itself
				// returns the full range.
				$box = Geo::bounding_box( 90.0, 21.0, 0.0, 'km' );

				assert_same( -180.0, $box['min_lng'] );
				assert_same( 180.0, $box['max_lng'] );
			}
		);

		it(
			'widens to the whole world rather than crossing the antimeridian',
			function () {
				$box = Geo::bounding_box( 0.0, 179.9, 100.0, 'km' );

				// A 100 km box at 179.9°E would reach 180.8°E, which is not a
				// longitude. Wrapping it would need two ranges and an OR in the
				// query Task 7 writes; emitting min_lng 180.8 and max_lng -179.2
				// would need Task 7 to notice. The full range is neither: it
				// over-fetches candidates in the Pacific and lets the exact
				// distance in PHP throw them out.
				assert_same( -180.0, $box['min_lng'] );
				assert_same( 180.0, $box['max_lng'] );
			}
		);

		it(
			'never hands back a box that reads backwards',
			function () {
				$boxes = array(
					Geo::bounding_box( 52.2297, 21.0122, 25.0, 'km' ),
					Geo::bounding_box( 0.0, -179.95, 50.0, 'km' ),
					Geo::bounding_box( -89.9, 0.0, 400.0, 'km' ),
					Geo::bounding_box( 90.0, 0.0, 0.0, 'km' ),
					Geo::bounding_box( 0.0, 0.0, 40000.0, 'km' ),
					// Past the pole, which is not a latitude. Unclamped, 95
					// degrees gives a minimum of 94.1 against a maximum of 90 —
					// and a negative cosine besides.
					Geo::bounding_box( 95.0, 0.0, 100.0, 'km' ),
					Geo::bounding_box( -95.0, 0.0, 100.0, 'km' ),
					// A radius from a shortcode attribute or a REST parameter.
					// Unguarded, -25 inverts the box and NAN gives it NaN edges,
					// and NAN is the one that needs its own check: max( 0.0, NAN )
					// is NAN, because max() picks by comparison and nothing
					// compares true against NAN.
					Geo::bounding_box( 52.2297, 21.0122, -25.0, 'km' ),
					Geo::bounding_box( 52.2297, 21.0122, NAN, 'km' ),
				);

				foreach ( $boxes as $box ) {
					assert_true( $box['min_lat'] <= $box['max_lat'] );
					assert_true( $box['min_lng'] <= $box['max_lng'] );
				}
			}
		);

		it(
			'boxes a normal search around the point it was given',
			function () {
				// The ordinary case, where the numbers are arithmetic rather
				// than a fallback. 25 km on a sphere of radius 6371 is
				// 25/6371 radians = 0.224831 degrees of latitude, and
				// asin( sin( 25/6371 ) / cos( 52.2297 ) ) = 0.367073 degrees of
				// longitude, each widened by the 1.001 margin to 0.225055 and
				// 0.367440.
				$box = Geo::bounding_box( 52.2297, 21.0122, 25.0, 'km' );

				assert_close( 52.2297 - 0.225055, $box['min_lat'], 0.00002 );
				assert_close( 52.2297 + 0.225055, $box['max_lat'], 0.00002 );
				assert_close( 21.0122 - 0.367440, $box['min_lng'], 0.00002 );
				assert_close( 21.0122 + 0.367440, $box['max_lng'], 0.00002 );
			}
		);
	}
);
