<?php
/**
 * Pins [store_locator]: what it accepts, what it refuses, and what it escapes.
 *
 * Three things are being tested here and they have different stakes.
 *
 * Attribute sanitisation is ordinary input handling, and the interesting part
 * is that the numeric bounds are read back out of the REST schema rather than
 * retyped. A shortcode that clamps the radius at 1000 while /stores refuses
 * anything over 500 is not a bug anybody notices until a visitor's search comes
 * back empty for no reason, so the cases below ask the controller what it
 * declared and assert the shortcode agrees.
 *
 * The preload decision is a branch nothing downstream can see. Both modes
 * render the same markup and the same controls, so a threshold comparison the
 * wrong way round produces a page that looks perfect and makes 500 locations
 * arrive one search at a time — or, in the other direction, a page that asks
 * /stores for 900 locations and silently draws the 500 it will actually get.
 * Hence a case on each side of the boundary and one on the boundary itself.
 *
 * The escaping is the whole defence. Store::from_array() does not sanitise, the
 * repository does not sanitise, and the REST controller hands values out as
 * JSON where escaping is not a question. Output is the first and only place
 * anything is made safe, and a category name is the string that reaches this
 * markup from the database. So the escaping cases do not assert "esc_attr was
 * called" — they assert that a name full of quotes, angle brackets and a
 * </script> survives the attribute intact, byte for byte, and that nothing in
 * it can end the attribute, the option element or the html comment early.
 *
 * Nothing here enqueues anything or asserts anything about scripts: Task 12
 * owns asset loading and Task 13 owns the JavaScript.
 *
 * @package Store_Locator_For_OpenStreetMap
 */

require_once dirname( __DIR__ ) . '/includes/class-geo.php';
require_once dirname( __DIR__ ) . '/includes/class-store.php';
require_once dirname( __DIR__ ) . '/includes/class-post-type.php';
require_once dirname( __DIR__ ) . '/includes/class-store-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-geocoder.php';
require_once dirname( __DIR__ ) . '/includes/class-rest-controller.php';
require_once dirname( __DIR__ ) . '/includes/class-assets.php';
require_once dirname( __DIR__ ) . '/includes/class-shortcode.php';
require_once dirname( __DIR__ ) . '/includes/class-settings.php';
require_once dirname( __DIR__ ) . '/includes/class-plugin.php';
require_once dirname( __DIR__ ) . '/admin/class-settings-screen.php';
require_once dirname( __DIR__ ) . '/admin/class-shortcode-generator.php';
// Plugin::boot() constructs an Admin for the location metabox, and this suite
// runs without the autoloader.
require_once dirname( __DIR__ ) . '/admin/class-admin.php';
require_once dirname( __DIR__ ) . '/admin/class-locations-list.php';
require_once dirname( __DIR__ ) . '/admin/class-bulk-geocode.php';

use Asymetria\StoreLocator\Geo;
use Asymetria\StoreLocator\Plugin;
use Asymetria\StoreLocator\Post_Type;
use Asymetria\StoreLocator\Rest_Controller;
use Asymetria\StoreLocator\Shortcode;

if ( ! function_exists( 'slosm_sc_count' ) ) {
	/**
	 * Stages the published-location count wp_count_posts() will report.
	 *
	 * A second post type is always staged with a wildly different number, so a
	 * shortcode that asked wp_count_posts() for 'post' — or for nothing in
	 * particular — cannot accidentally agree with the expected answer.
	 *
	 * @param int   $publish Published locations.
	 * @param array $other   Other statuses on the same post type.
	 * @return void
	 */
	function slosm_sc_count( int $publish, array $other = array() ): void {
		$GLOBALS['slosm_stub']['post_counts'][ Post_Type::POST_TYPE ] = array_merge(
			array( 'publish' => $publish ),
			$other
		);

		$GLOBALS['slosm_stub']['post_counts']['post'] = array( 'publish' => 987654 );
	}
}

if ( ! function_exists( 'slosm_sc_term' ) ) {
	/**
	 * Stages one term in the locator's own taxonomy.
	 *
	 * @param int    $term_id Term id.
	 * @param string $name    Term name.
	 * @param string $slug    Term slug.
	 * @return void
	 */
	function slosm_sc_term( int $term_id, string $name, string $slug ): void {
		$GLOBALS['slosm_stub']['terms'][ Post_Type::TAXONOMY ][] = array(
			'term_id' => $term_id,
			'name'    => $name,
			'slug'    => $slug,
		);
	}
}

if ( ! function_exists( 'slosm_sc_attr' ) ) {
	/**
	 * The raw, still-escaped value of the container's data-slosm attribute.
	 *
	 * Cut at the first double quote after the opening one, which is exactly how
	 * a browser's tokeniser ends an attribute. That is not a shortcut, it is the
	 * property under test: if anything in the config reached the markup with a
	 * live double quote in it, this helper stops there too and the json_decode
	 * in slosm_sc_config_from_html() fails — which is the same failure a browser
	 * would suffer, reported as a test failure instead of as an injected
	 * attribute.
	 *
	 * @param string $html Rendered markup.
	 * @return string Raw attribute value, or '' when there is no such attribute.
	 */
	function slosm_sc_attr( string $html ): string {
		$needle = ' data-slosm="';
		$open   = strpos( $html, $needle );

		if ( false === $open ) {
			return '';
		}

		$start = $open + strlen( $needle );
		$end   = strpos( $html, '"', $start );

		if ( false === $end ) {
			return '';
		}

		return substr( $html, $start, $end - $start );
	}
}

if ( ! function_exists( 'slosm_sc_config_from_html' ) ) {
	/**
	 * The config a browser would get, read back out of the rendered markup.
	 *
	 * Entity-decoded and then json_decoded, in that order, because that is the
	 * order a browser does it in: the html parser hands the attribute's decoded
	 * text to JSON.parse.
	 *
	 * @param string $html Rendered markup.
	 * @return mixed Decoded config, or null when it could not be read.
	 */
	function slosm_sc_config_from_html( string $html ) {
		$raw = slosm_sc_attr( $html );

		return json_decode( html_entity_decode( $raw, ENT_QUOTES, 'UTF-8' ), true );
	}
}

if ( ! function_exists( 'slosm_sc_template' ) ) {
	/**
	 * The row template element, from its opening tag to its closing one.
	 *
	 * @param string $html Rendered markup.
	 * @return string
	 */
	function slosm_sc_template( string $html ): string {
		$open  = strpos( $html, '<template class="slosm__row">' );
		$close = strpos( $html, '</template>' );

		if ( false === $open || false === $close ) {
			return '';
		}

		return substr( $html, $open, $close - $open + strlen( '</template>' ) );
	}
}

if ( ! function_exists( 'slosm_sc_route_args' ) ) {
	/**
	 * The args schema the REST controller declared for one route.
	 *
	 * Read back from the registration rather than from the controller's
	 * constants, so a bound that moved in the schema and not in the shortcode
	 * fails here. register_routes() passes one endpoint definition per route, so
	 * the args live at [0]['args'].
	 *
	 * @param string $route Route pattern, as registered.
	 * @return array
	 */
	function slosm_sc_route_args( string $route ): array {
		$controller = new Rest_Controller();

		$controller->register_routes();

		foreach ( $GLOBALS['slosm_stub']['rest_routes'] as $registered ) {
			if ( $route === $registered['route'] ) {
				return $registered['args'][0]['args'];
			}
		}

		return array();
	}
}

describe(
	'shortcode registration',
	function () {

		it(
			'registers the tag on init, not while booting',
			function () {
				$construct = Closure::bind(
					static function () {
						return new Plugin();
					},
					null,
					Plugin::class
				);

				$plugin = $construct();

				$plugin->boot();

				// The absence half. On its own it is satisfied by a boot() that
				// registers no shortcode anywhere, which is why the control
				// below has to fire the hook and find one.
				assert_same(
					array(),
					$GLOBALS['slosm_stub']['shortcodes'],
					'boot() called add_shortcode() directly; it must go through the init callback'
				);

				do_action( 'init' );

				assert_same(
					1,
					count( $GLOBALS['slosm_stub']['shortcodes'][ Shortcode::TAG ] ?? array() ),
					'init did not register [' . Shortcode::TAG . '] exactly once'
				);
			}
		);

		it(
			'registers the render method itself, so the tag renders a locator',
			function () {
				slosm_sc_count( 3 );

				$shortcode = new Shortcode();

				$shortcode->register();

				$callback = $GLOBALS['slosm_stub']['shortcodes'][ Shortcode::TAG ][0]['callback'];
				$html     = $callback( array( 'zoom' => '9' ) );

				assert_contains( '<div class="slosm" data-slosm="', $html );

				$config = slosm_sc_config_from_html( $html );

				assert_same( 9, $config['zoom'] );
			}
		);
	}
);

describe(
	'shortcode attributes',
	function () {

		before_each(
			function () {
				slosm_sc_count( 3 );
			}
		);

		it(
			'drops attributes it does not declare',
			function () {
				$attributes = ( new Shortcode() )->attributes(
					array(
						'zoom'          => '7',
						'onclick'       => 'alert(1)',
						'data-anything' => 'x',
					)
				);

				assert_false(
					array_key_exists( 'onclick', $attributes ),
					'an undeclared attribute reached the attribute list'
				);
				assert_false( array_key_exists( 'data-anything', $attributes ) );

				// Without this, both assertions above hold just as well for a
				// method that returns an empty array and accepts nothing at all.
				assert_same( 7, $attributes['zoom'] );

				// What is actually keeping them out is the literal return array
				// in attributes(), which names its thirteen keys and copies
				// nothing else — and that is worth saying plainly, because
				// replacing shortcode_atts() with a plain array_merge() does not
				// fail this case. shortcode_atts() earns its place for a
				// different reason: it is what runs the documented
				// shortcode_atts_store_locator filter, and the suite's stub does
				// not run filters, so nothing here can see that happen.
			}
		);

		it(
			'falls back to the default height when there is no number to read',
			function () {
				$shortcode = new Shortcode();

				assert_same( Shortcode::DEFAULT_HEIGHT, $shortcode->attributes( array() )['height'] );
				assert_same( Shortcode::DEFAULT_HEIGHT, $shortcode->attributes( array( 'height' => '' ) )['height'] );
				assert_same( Shortcode::DEFAULT_HEIGHT, $shortcode->attributes( array( 'height' => 'tall' ) )['height'] );
			}
		);

		it(
			'reads a height with or without its unit and clamps it at both ends',
			function () {
				$shortcode = new Shortcode();

				assert_same( 500, $shortcode->attributes( array( 'height' => '500' ) )['height'] );
				assert_same( 500, $shortcode->attributes( array( 'height' => '500px' ) )['height'] );
				assert_same( 500, $shortcode->attributes( array( 'height' => '  500 px ' ) )['height'] );
				assert_same( 500, $shortcode->attributes( array( 'height' => 500 ) )['height'] );

				assert_same( Shortcode::MIN_HEIGHT, $shortcode->attributes( array( 'height' => '1' ) )['height'] );
				assert_same( Shortcode::MIN_HEIGHT, $shortcode->attributes( array( 'height' => '0' ) )['height'] );
				assert_same( Shortcode::MIN_HEIGHT, $shortcode->attributes( array( 'height' => '-400' ) )['height'] );
				assert_same( Shortcode::MAX_HEIGHT, $shortcode->attributes( array( 'height' => '99999' ) )['height'] );
			}
		);

		it(
			'clamps zoom to the range Leaflet and the tile server both have',
			function () {
				$shortcode = new Shortcode();

				assert_same( Shortcode::DEFAULT_ZOOM, $shortcode->attributes( array() )['zoom'] );
				assert_same( Shortcode::DEFAULT_ZOOM, $shortcode->attributes( array( 'zoom' => 'near' ) )['zoom'] );

				assert_same( 1, $shortcode->attributes( array( 'zoom' => '1' ) )['zoom'] );
				assert_same( 19, $shortcode->attributes( array( 'zoom' => '19' ) )['zoom'] );

				assert_same( Shortcode::MIN_ZOOM, $shortcode->attributes( array( 'zoom' => '0' ) )['zoom'] );
				assert_same( Shortcode::MIN_ZOOM, $shortcode->attributes( array( 'zoom' => '-4' ) )['zoom'] );
				assert_same( Shortcode::MAX_ZOOM, $shortcode->attributes( array( 'zoom' => '25' ) )['zoom'] );

				assert_same( 1, Shortcode::MIN_ZOOM );
				assert_same( 19, Shortcode::MAX_ZOOM );
			}
		);

		it(
			'accepts only the units Geo measures in, however they were typed',
			function () {
				$shortcode = new Shortcode();

				assert_same( 'mi', $shortcode->attributes( array( 'units' => 'mi' ) )['units'] );
				assert_same( 'mi', $shortcode->attributes( array( 'units' => 'MI' ) )['units'] );
				assert_same( 'mi', $shortcode->attributes( array( 'units' => ' Mi ' ) )['units'] );

				assert_same( 'km', $shortcode->attributes( array() )['units'] );
				assert_same( 'km', $shortcode->attributes( array( 'units' => 'miles' ) )['units'] );
				assert_same( 'km', $shortcode->attributes( array( 'units' => 'furlongs' ) )['units'] );

				// The list is Geo's, not a second copy of it: a unit added there
				// and not here would make this fail rather than silently measure
				// in kilometres.
				foreach ( Geo::UNITS as $unit ) {
					assert_same( $unit, $shortcode->attributes( array( 'units' => $unit ) )['units'] );
				}
			}
		);

		it(
			'reads yes, no, true, false, 1 and 0 as booleans in either case',
			function () {
				$shortcode = new Shortcode();

				foreach ( array( 'yes', 'YES', 'Yes', 'true', 'TRUE', 'True', '1', 1, true, 'on' ) as $truthy ) {
					assert_true(
						$shortcode->attributes( array( 'near_me' => $truthy ) )['near_me'],
						var_export( $truthy, true ) . ' did not read as true'
					);
				}

				foreach ( array( 'no', 'NO', 'No', 'false', 'FALSE', 'False', '0', 0, false, 'off' ) as $falsy ) {
					assert_false(
						$shortcode->attributes( array( 'near_me' => $falsy ) )['near_me'],
						var_export( $falsy, true ) . ' did not read as false'
					);
				}
			}
		);

		it(
			'keeps the default when a boolean is neither yes nor no',
			function () {
				$shortcode = new Shortcode();

				// The two booleans default differently on purpose: offering to
				// find the visitor costs nothing, asking the browser for their
				// position before they asked for anything is hostile.
				assert_true( $shortcode->attributes( array() )['near_me'] );
				assert_false( $shortcode->attributes( array() )['auto_locate'] );

				assert_true( $shortcode->attributes( array( 'near_me' => 'maybe' ) )['near_me'] );
				assert_false( $shortcode->attributes( array( 'auto_locate' => 'maybe' ) )['auto_locate'] );
			}
		);

		it(
			'clamps every number to exactly the bound the REST schema declares',
			function () {
				$args      = slosm_sc_route_args( '/stores' );
				$shortcode = new Shortcode();

				// Proves the schema was read at all. Without it every assertion
				// below would compare against null and pass for a shortcode that
				// clamped to nothing.
				assert_true( isset( $args['radius']['maximum'] ), '/stores declared no radius bound to agree with' );

				assert_same(
					(float) $args['radius']['maximum'],
					$shortcode->attributes( array( 'radius' => '100000' ) )['radius']
				);
				assert_same(
					(float) $args['radius']['default'],
					$shortcode->attributes( array( 'radius' => 'far' ) )['radius']
				);

				// The shortcode is deliberately stricter than the schema at the
				// bottom end — see the case below — so what agrees here is that
				// everything it can produce is inside the schema's range.
				foreach ( array( '-40', '0', '1', '250', '100000', 'far' ) as $typed ) {
					$radius = $shortcode->attributes( array( 'radius' => $typed ) )['radius'];

					assert_true(
						(float) $args['radius']['minimum'] <= $radius && $radius <= (float) $args['radius']['maximum'],
						'radius="' . $typed . '" produced ' . $radius . ', which /stores would refuse'
					);
				}

				assert_same(
					(int) $args['limit']['maximum'],
					$shortcode->attributes( array( 'limit' => '100000' ) )['limit']
				);
				assert_same(
					(int) $args['limit']['minimum'],
					$shortcode->attributes( array( 'limit' => '0' ) )['limit']
				);

				assert_same(
					(float) $args['lat']['maximum'],
					$shortcode->attributes( array( 'lat' => '120' ) )['lat']
				);
				assert_same(
					(float) $args['lat']['minimum'],
					$shortcode->attributes( array( 'lat' => '-120' ) )['lat']
				);
				assert_same(
					(float) $args['lng']['maximum'],
					$shortcode->attributes( array( 'lng' => '200' ) )['lng']
				);
				assert_same(
					(float) $args['lng']['minimum'],
					$shortcode->attributes( array( 'lng' => '-200' ) )['lng']
				);

				// A centre nobody named is not 0,0 in the Gulf of Guinea.
				assert_same( null, $shortcode->attributes( array() )['lat'] );
				assert_same( null, $shortcode->attributes( array() )['lng'] );
				assert_same( 52.2297, $shortcode->attributes( array( 'lat' => '52.2297' ) )['lat'] );
			}
		);

		it(
			'never accepts a radius of nothing, but leaves a small one alone',
			function () {
				$shortcode = new Shortcode();

				// /stores declares minimum 0.0, so clamping to the schema would
				// be "correct" and would render <option value="0" selected>0 km
				// </option> — a search that can never match anything, with
				// nothing on screen to say why.
				assert_same( Rest_Controller::DEFAULT_RADIUS, $shortcode->attributes( array( 'radius' => '0' ) )['radius'] );
				assert_same( Rest_Controller::DEFAULT_RADIUS, $shortcode->attributes( array( 'radius' => '0.0' ) )['radius'] );
				assert_same( Rest_Controller::DEFAULT_RADIUS, $shortcode->attributes( array( 'radius' => '-40' ) )['radius'] );

				// A deliberate small radius is not a mistake to be corrected. A
				// floor of the smallest offered step would have overridden this.
				assert_same( 1.0, $shortcode->attributes( array( 'radius' => '1' ) )['radius'] );
				assert_same( 0.5, $shortcode->attributes( array( 'radius' => '0.5' ) )['radius'] );

				// And it reaches the control rather than being rounded into the
				// nearest offered step.
				assert_contains( '<option value="0.5" selected>', $shortcode->render( array( 'radius' => '0.5' ) ) );
			}
		);

		it(
			'survives a shortcode_atts filter that hands back a short array',
			function () {
				// Core returns whatever shortcode_atts_{$tag} returned, so a
				// filter that unsets a key makes every direct read of that key
				// an "Undefined array key" warning on a front-end page —
				// which framework.php fails the case for, so this case is its
				// own assertion.
				add_filter(
					'shortcode_atts_' . Shortcode::TAG,
					static function ( $out ) {
						unset( $out['height'], $out['near_me'], $out['category'] );

						$out['zoom'] = '17';

						return $out;
					}
				);

				$attributes = ( new Shortcode() )->attributes( array( 'height' => '600' ) );

				assert_same( Shortcode::DEFAULT_HEIGHT, $attributes['height'] );
				assert_true( $attributes['near_me'] );
				assert_same( '', $attributes['category'] );

				// The control. Without it every assertion above holds for a
				// shortcode that never called shortcode_atts() — and therefore
				// never ran the documented filter — at all.
				assert_same( 17, $attributes['zoom'], 'the shortcode_atts_ filter did not run' );
			}
		);

		it(
			'survives a shortcode_atts filter that hands back something else entirely',
			function () {
				add_filter(
					'shortcode_atts_' . Shortcode::TAG,
					static function () {
						return null;
					}
				);

				$attributes = ( new Shortcode() )->attributes( array( 'zoom' => '17' ) );

				assert_same( Shortcode::DEFAULT_ZOOM, $attributes['zoom'] );
				assert_same( Shortcode::DEFAULT_HEIGHT, $attributes['height'] );
			}
		);

		it(
			'cuts the prefilled search to the length /geocode will accept',
			function () {
				$args = slosm_sc_route_args( '/geocode' );

				assert_true( isset( $args['q']['maxLength'] ), '/geocode declared no maxLength to agree with' );

				$max   = (int) $args['q']['maxLength'];
				$given = str_repeat( 'a', $max + 50 );

				$search = ( new Shortcode() )->attributes( array( 'search' => $given ) )['search'];

				assert_same( $max, strlen( $search ) );
				assert_same( '', ( new Shortcode() )->attributes( array() )['search'] );
				assert_same( 'Kraków', ( new Shortcode() )->attributes( array( 'search' => '  Kraków  ' ) )['search'] );

				// Characters, not bytes, and the difference is not pedantry.
				// maxLength in a json schema counts characters, so a byte cut is
				// both short — 66 characters where 200 were allowed — and
				// broken: 200 bytes of a three-byte character ends mid
				// character, which is invalid utf-8, which json_encode() answers
				// with false.
				//
				// What that costs differs between here and a real site, and the
				// neighbouring invalid-utf-8 case makes the same distinction.
				// The stub's wp_json_encode() is bare json_encode(), so here it
				// is false and the config falls back to {}. On a real site
				// wp_json_encode() retries through _wp_json_sanity_check() and
				// _wp_json_convert_string(), so the likely outcome is a mangled
				// prefill rather than an empty config. Either way it is a search
				// box nobody asked to break; the cut below is what prevents both.
				$wide = ( new Shortcode() )->attributes( array( 'search' => str_repeat( '€', $max + 50 ) ) )['search'];

				assert_same( $max, preg_match_all( '/./us', $wide ), 'the search was cut to bytes rather than characters' );
				assert_true( false !== json_encode( $wide ), 'the cut search is no longer valid utf-8' );
			}
		);

		it(
			'refuses an attribute that is not text, without raising a warning',
			function () {
				// A real boolean, which a shortcode never produces but a Bricks
				// control or a direct caller will. It matters because
				// (string) true is '1' and is_numeric( '1' ) is true, so without
				// a guard radius="yes" arrives as a one-kilometre search and
				// height="yes" as a one-pixel map.
				$booleans = ( new Shortcode() )->attributes(
					array(
						'radius' => true,
						'height' => true,
						'zoom'   => true,
						'lat'    => true,
						'search' => true,
						'units'  => true,
					)
				);

				assert_same( Rest_Controller::DEFAULT_RADIUS, $booleans['radius'] );
				assert_same( Shortcode::DEFAULT_HEIGHT, $booleans['height'] );
				assert_same( Shortcode::DEFAULT_ZOOM, $booleans['zoom'] );
				assert_same( null, $booleans['lat'] );
				assert_same( '', $booleans['search'] );
				assert_same( 'km', $booleans['units'] );

				// framework.php turns a PHP warning into a failed case, so this
				// case is its own assertion: (int) on an array, (string) on an
				// array and strtolower( array ) are all warnings or errors.
				$attributes = ( new Shortcode() )->attributes(
					array(
						'height'   => array( 500 ),
						'zoom'     => array(),
						'units'    => array( 'mi' ),
						'near_me'  => array( 'yes' ),
						'category' => array( 'bakeries' ),
						'search'   => array( 'x' ),
						'radius'   => array( 10 ),
					)
				);

				assert_same( Shortcode::DEFAULT_HEIGHT, $attributes['height'] );
				assert_same( Shortcode::DEFAULT_ZOOM, $attributes['zoom'] );
				assert_same( 'km', $attributes['units'] );
				assert_true( $attributes['near_me'] );
				assert_same( '', $attributes['category'] );
				assert_same( '', $attributes['search'] );
			}
		);
	}
);

describe(
	'shortcode category',
	function () {

		before_each(
			function () {
				slosm_sc_count( 3 );
				slosm_sc_term( 12, 'Bakeries & cafés', 'bakeries' );
			}
		);

		it(
			'resolves a category by slug, by name or by id, and carries the name',
			function () {
				$shortcode = new Shortcode();

				// The name is what travels, because /stores filters on category
				// names — Rest_Controller::has_category() compares them with
				// strcasecmp — so a slug or an id in the config would filter
				// nothing at all.
				assert_same( 'Bakeries & cafés', $shortcode->attributes( array( 'category' => 'bakeries' ) )['category'] );
				assert_same( 'Bakeries & cafés', $shortcode->attributes( array( 'category' => 'Bakeries & cafés' ) )['category'] );
				assert_same( 'Bakeries & cafés', $shortcode->attributes( array( 'category' => '12' ) )['category'] );

				assert_same( '', $shortcode->attributes( array() )['category'] );
				assert_same( '', $shortcode->attributes( array() )['category_missing'] );
			}
		);

		it(
			'looks the term up in the locator taxonomy and no other',
			function () {
				( new Shortcode() )->attributes( array( 'category' => 'bakeries' ) );

				$lookups = $GLOBALS['slosm_stub']['term_lookups'];

				assert_true( 0 < count( $lookups ), 'no term lookup was made at all' );

				foreach ( $lookups as $lookup ) {
					assert_same( Post_Type::TAXONOMY, $lookup['taxonomy'] );
				}
			}
		);

		it(
			'says so in a comment when the category does not resolve',
			function () {
				$html = ( new Shortcode() )->render( array( 'category' => 'flrists' ) );

				assert_contains( '<!--', $html );
				assert_contains( 'flrists', $html, 'the comment does not name the category that was asked for' );

				$config = slosm_sc_config_from_html( $html );

				// It shows everything, but not silently: the filter is off and
				// the markup says why.
				assert_same( '', $config['category'] );
				assert_same( 'flrists', ( new Shortcode() )->attributes( array( 'category' => 'flrists' ) )['category_missing'] );
			}
		);

		it(
			'stays quiet when the category does resolve',
			function () {
				$html = ( new Shortcode() )->render( array( 'category' => 'bakeries' ) );

				assert_same(
					false,
					strpos( $html, '<!--' ),
					'a resolved category still produced a comment'
				);

				// The control. Without it, "no comment" is satisfied by a render
				// that produced no markup at all.
				$config = slosm_sc_config_from_html( $html );

				assert_same( 'Bakeries & cafés', $config['category'] );
			}
		);
	}
);

describe(
	'shortcode preload decision',
	function () {

		it(
			'preloads up to the threshold and queries above it',
			function () {
				$shortcode = new Shortcode();

				slosm_sc_count( 0 );
				assert_same( 'preload', $shortcode->config( $shortcode->attributes( array() ) )['mode'] );

				slosm_sc_count( Shortcode::PRELOAD_THRESHOLD - 1 );
				assert_same( 'preload', $shortcode->config( $shortcode->attributes( array() ) )['mode'] );

				slosm_sc_count( Shortcode::PRELOAD_THRESHOLD );
				assert_same( 'preload', $shortcode->config( $shortcode->attributes( array() ) )['mode'], 'the boundary itself must preload' );

				slosm_sc_count( Shortcode::PRELOAD_THRESHOLD + 1 );
				assert_same( 'query', $shortcode->config( $shortcode->attributes( array() ) )['mode'], 'one past the boundary must query' );
			}
		);

		it(
			'counts published locations of this post type and nothing else',
			function () {
				// 'post' is staged with 987654 published by slosm_sc_count(), so
				// a count taken of the wrong post type cannot pass here.
				slosm_sc_count( 4, array( 'draft' => 900, 'trash' => 900, 'auto-draft' => 900 ) );

				$shortcode = new Shortcode();
				$config    = $shortcode->config( $shortcode->attributes( array() ) );

				assert_same( 4, $config['count'] );
				assert_same( 'preload', $config['mode'] );
			}
		);

		it(
			'reads a count WordPress hands back as a string',
			function () {
				// $wpdb->get_results( ..., ARRAY_A ) returns every column as a
				// string, and wp_count_posts() casts the row to an object
				// without touching the values. Only the statuses that had no
				// rows are real integers.
				$GLOBALS['slosm_stub']['post_counts'][ Post_Type::POST_TYPE ] = array(
					'publish' => (string) ( Shortcode::PRELOAD_THRESHOLD + 1 ),
					'draft'   => 0,
				);

				$shortcode = new Shortcode();
				$config    = $shortcode->config( $shortcode->attributes( array() ) );

				assert_same( Shortcode::PRELOAD_THRESHOLD + 1, $config['count'] );
				assert_same( 'query', $config['mode'] );
			}
		);

		it(
			'survives a post type WordPress has no counts for',
			function () {
				// wp_count_posts() returns a bare stdClass when the post type is
				// not registered — no publish property at all. Reading it
				// unguarded is an "Undefined property" warning, which
				// framework.php now fails the case for, so this case proves the
				// guard by not tripping it.
				$shortcode = new Shortcode();
				$config    = $shortcode->config( $shortcode->attributes( array() ) );

				assert_same( 0, $config['count'] );
				assert_same( 'preload', $config['mode'] );
			}
		);

		it(
			'lets slosm_preload_threshold move the line down',
			function () {
				add_filter(
					'slosm_preload_threshold',
					static function () {
						return 2;
					}
				);

				$shortcode = new Shortcode();

				slosm_sc_count( 2 );
				$config = $shortcode->config( $shortcode->attributes( array() ) );

				assert_same( 2, $config['threshold'] );
				assert_same( 'preload', $config['mode'] );

				slosm_sc_count( 3 );
				assert_same( 'query', $shortcode->config( $shortcode->attributes( array() ) )['mode'] );
			}
		);

		it(
			'will not let the filter raise the threshold past what /stores returns',
			function () {
				// Preload mode means "fetch the whole list from /stores once",
				// and /stores caps at MAX_LIMIT. A threshold above that cap is a
				// promise the route cannot keep: the map would draw 500 of 900
				// locations and say nothing about the other 400.
				$args = slosm_sc_route_args( '/stores' );

				assert_true( isset( $args['limit']['maximum'] ), '/stores declared no limit to cap the threshold at' );

				add_filter(
					'slosm_preload_threshold',
					static function () {
						return 100000;
					}
				);

				$shortcode = new Shortcode();

				slosm_sc_count( (int) $args['limit']['maximum'] + 1 );

				$config = $shortcode->config( $shortcode->attributes( array() ) );

				assert_same( (int) $args['limit']['maximum'], $config['threshold'] );
				assert_same( 'query', $config['mode'] );
			}
		);
	}
);

describe(
	'shortcode clustering decision',
	function () {

		it(
			'clusters by itself above the threshold and leaves a small site alone',
			function () {
				// The count decides, for the reason preload_threshold() gives
				// about its own line: a site owner asked "should your map
				// cluster?" has no way to know — the answer depends on how the
				// pins fall at the zoom each visitor happens to be at, which is
				// not on the screen when the shortcode is written. PHP knows the
				// count and can simply decide.
				$shortcode = new Shortcode();

				slosm_sc_count( 20 );
				assert_false(
					$shortcode->config( $shortcode->attributes( array() ) )['cluster'],
					'the twenty-branch site the vendored README names is clustering'
				);

				slosm_sc_count( Shortcode::CLUSTER_THRESHOLD - 1 );
				assert_false( $shortcode->config( $shortcode->attributes( array() ) )['cluster'] );

				slosm_sc_count( Shortcode::CLUSTER_THRESHOLD );
				assert_true(
					$shortcode->config( $shortcode->attributes( array() ) )['cluster'],
					'the threshold itself is the first count that clusters'
				);

				// And every query-mode site clusters, which is not a second rule
				// but the same one: query mode starts above PRELOAD_THRESHOLD,
				// and that is far above this line.
				slosm_sc_count( Shortcode::PRELOAD_THRESHOLD + 1 );
				assert_true( $shortcode->config( $shortcode->attributes( array() ) )['cluster'] );
			}
		);

		it(
			'counts the pins that can be on the map, not the ones on the site',
			function () {
				// What clusters is the number of pins on screen, and the display
				// limit is a hard ceiling on that: at most `limit` rows and
				// `limit` pins in preload mode, at most `limit` items back from
				// /stores in query mode. A nine-hundred-branch site showing ten
				// of them would otherwise download 34 KB of library to cluster
				// ten pins — the twenty-branch cost the threshold exists to
				// avoid, arriving through the other door.
				$shortcode = new Shortcode();

				slosm_sc_count( 900 );

				assert_false(
					$shortcode->config( $shortcode->attributes( array( 'limit' => '10' ) ) )['cluster'],
					'a locator that can show ten pins downloaded the cluster library'
				);

				// The control, and the two halves that make the line the right
				// one: a limit at the threshold clusters, one below it does not.
				assert_true(
					$shortcode->config( $shortcode->attributes( array( 'limit' => (string) Shortcode::CLUSTER_THRESHOLD ) ) )['cluster']
				);
				assert_false(
					$shortcode->config( $shortcode->attributes( array( 'limit' => (string) ( Shortcode::CLUSTER_THRESHOLD - 1 ) ) ) )['cluster']
				);

				// And the default is unaffected: a shortcode that names no limit
				// gets MAX_LIMIT, which is far above the threshold, so the site
				// count is what decides.
				assert_true( $shortcode->config( $shortcode->attributes( array() ) )['cluster'] );

				// The min works in the other direction too — a small site with a
				// huge limit still does not cluster.
				slosm_sc_count( 12 );

				assert_false( $shortcode->config( $shortcode->attributes( array( 'limit' => '500' ) ) )['cluster'] );
			}
		);

		it(
			'lets a shortcode overrule the count in either direction',
			function () {
				// The two shapes a count cannot see: a dozen shops on one
				// street, which overlap at every zoom a visitor will use, and
				// hundreds spread thinly, which never do.
				$shortcode = new Shortcode();

				slosm_sc_count( 12 );
				assert_true( $shortcode->config( $shortcode->attributes( array( 'cluster' => 'yes' ) ) )['cluster'] );

				slosm_sc_count( 900 );
				assert_false( $shortcode->config( $shortcode->attributes( array( 'cluster' => 'no' ) ) )['cluster'] );
			}
		);

		it(
			'reads the attribute however somebody writes it, and keeps auto for nonsense',
			function () {
				$shortcode = new Shortcode();

				slosm_sc_count( 900 );

				foreach ( array( 'no', 'No', ' FALSE ', '0', 'off' ) as $written ) {
					assert_same(
						'no',
						$shortcode->attributes( array( 'cluster' => $written ) )['cluster'],
						'cluster="' . $written . '" was not read as off'
					);
				}

				foreach ( array( 'yes', 'YES', 'true', '1', 'on' ) as $written ) {
					assert_same( 'yes', $shortcode->attributes( array( 'cluster' => $written ) )['cluster'] );
				}

				// Anything else keeps the decision with the count rather than
				// being read as off — the same rule boolean() applies, and for
				// the same reason: "maybe" is a mistake, and silently turning a
				// feature off is a bad answer to a mistake.
				foreach ( array( 'auto', 'maybe', '', 'perhaps', array(), true ) as $written ) {
					assert_same( 'auto', $shortcode->attributes( array( 'cluster' => $written ) )['cluster'] );
				}

				assert_true( $shortcode->config( $shortcode->attributes( array( 'cluster' => 'maybe' ) ) )['cluster'] );
			}
		);

		it(
			'tells the browser a boolean and never a word',
			function () {
				// The front end reads config.cluster and compares it with true.
				// A string 'yes' arriving there would be truthy in a way that
				// only works by accident, and the same string is what the
				// *attribute* legitimately carries — so the two have to be
				// different shapes at the boundary.
				$shortcode = new Shortcode();

				slosm_sc_count( 900 );

				$html   = $shortcode->render( array( 'cluster' => 'yes' ) );
				$config = slosm_sc_config_from_html( $html );

				assert_same( true, $config['cluster'] );
				assert_true( is_bool( $shortcode->config( $shortcode->attributes( array() ) )['cluster'] ) );
			}
		);

		it(
			'stays below the preload threshold, so a preloading site can still cluster',
			function () {
				// Two independent decisions about the same count, and the order
				// of the two lines is the whole of it: a clustering threshold
				// above the preload one would mean no preloading site ever
				// clusters, which is most of the sites this plugin is for.
				assert_true(
					Shortcode::CLUSTER_THRESHOLD < Shortcode::PRELOAD_THRESHOLD,
					'nothing in preload mode can cluster any more'
				);

				// And above the twenty the vendored README promises never
				// downloads the library.
				assert_true( 20 < Shortcode::CLUSTER_THRESHOLD );
			}
		);
	}
);

describe(
	'shortcode routes',
	function () {

		before_each(
			function () {
				slosm_sc_count( 3 );
			}
		);

		it(
			'ships four finished urls, so the front end only ever adds parameters',
			function () {
				$shortcode = new Shortcode();
				$routes    = $shortcode->config( $shortcode->attributes( array() ) )['routes'];

				assert_same(
					array( 'stores', 'store', 'geocode', 'suggest' ),
					array_keys( $routes )
				);

				foreach ( $routes as $name => $url ) {
					assert_contains( 'slosm/v1', $url, $name . ' does not point at this plugin' );
				}

				assert_contains( '/stores', $routes['stores'] );
				assert_contains( '/geocode', $routes['geocode'] );
				assert_contains( '/suggest', $routes['suggest'] );
			}
		);

		it(
			'builds a url a query string can be appended to, with plain permalinks',
			function () {
				// The suite's default, and the branch that used to be
				// unreachable: get_rest_url() puts the route in a query
				// parameter when permalink_structure is empty, so a front end
				// that appended '/stores?limit=500' to a namespace root would
				// produce a url with two question marks. Nothing here appends a
				// path, which is the whole point of shipping finished routes.
				assert_same( '', get_option( 'permalink_structure', '' ), 'this case needs plain permalinks' );

				$shortcode = new Shortcode();
				$routes    = $shortcode->config( $shortcode->attributes( array() ) )['routes'];

				assert_contains( 'index.php?rest_route=/slosm/v1/stores', $routes['stores'] );

				// One question mark, so the first parameter the front end adds
				// is an & and every url builder agrees where the query starts.
				assert_same( 1, substr_count( $routes['stores'], '?' ) );
			}
		);

		it(
			'builds a path under wp-json when the site has permalinks',
			function () {
				update_option( 'permalink_structure', '/%postname%/' );

				$shortcode = new Shortcode();
				$routes    = $shortcode->config( $shortcode->attributes( array() ) )['routes'];

				assert_contains( '/wp-json/slosm/v1/stores', $routes['stores'] );
				assert_same( 0, substr_count( $routes['stores'], '?' ) );
			}
		);

		it(
			'marks the one route with a path segment with a token, not a percent escape',
			function () {
				$shortcode = new Shortcode();

				foreach ( array( '', '/%postname%/' ) as $structure ) {
					update_option( 'permalink_structure', $structure );

					$routes = $shortcode->config( $shortcode->attributes( array() ) )['routes'];

					assert_contains( '__ID__', $routes['store'], 'the item route carries no id placeholder' );

					// add_query_arg() places the route verbatim — build_query()
					// calls _http_build_query() with $urlencode false — and the
					// pretty branch is plain concatenation, so a %d would reach
					// the browser as a literal, malformed percent escape that
					// decodeURIComponent() answers with a URIError. A token with
					// no percent sign cannot.
					assert_same( false, strpos( $routes['store'], '%' ), 'the item route contains a percent sign' );
				}
			}
		);
	}
);

describe(
	'shortcode markup',
	function () {

		before_each(
			function () {
				slosm_sc_count( 3 );
			}
		);

		it(
			'renders the container, the filters, the map, the list and the row template',
			function () {
				// '' rather than array(): WordPress hands a shortcode with no
				// attributes an empty string, not an empty array, and that is
				// the commonest call this code will ever get.
				$html = ( new Shortcode() )->render( '' );

				assert_contains( '<div class="slosm" data-slosm="', $html );
				assert_contains( '<div class="slosm__filters"', $html );
				assert_contains( '<div class="slosm__map"', $html );
				assert_contains( '<ol class="slosm__results"', $html );
				assert_contains( '<template class="slosm__row">', $html );

				assert_contains( 'class="slosm__search"', $html );
				assert_contains( 'class="slosm__radius"', $html );
				assert_contains( 'class="slosm__limit"', $html );
				assert_contains( 'class="slosm__category"', $html );
				assert_contains( 'class="slosm__locate"', $html );

				// Flat markup: no tables, and no script tag of any kind, since
				// the config travels in an attribute and Task 12 owns enqueuing.
				assert_same( false, strpos( $html, '<table' ) );
				assert_same( false, strpos( $html, '<script' ) );
			}
		);

		it(
			'gives the address field a submit control, beside the label and not inside it',
			function () {
				$html = ( new Shortcode() )->render( '' );

				// The whole element, in one assertion, because every part of it
				// is load-bearing: the class locator.js binds by, the type that
				// keeps it from submitting a form this markup did not open, and
				// the text somebody reads.
				assert_contains( '<button type="button" class="slosm__submit">Search</button>', $html );

				// A sibling of the <label>, never a child of it. A click inside
				// a label is a click on the label, which moves the focus to the
				// control the label wraps — so a button in there would drag the
				// focus back into the field on every press. This is the one
				// assertion that can tell those two apart.
				assert_contains(
					'</label><button type="button" class="slosm__submit">',
					$html,
					'the submit button moved inside the search label'
				);

				// And it is inside the search landmark, which is what makes it
				// the landmark's submit control rather than a button near it.
				$landmark = substr( $html, (int) strpos( $html, '<div class="slosm__filters"' ) );

				assert_true(
					strpos( $landmark, 'class="slosm__submit"' ) < strpos( $landmark, '<div class="slosm__map"' ),
					'the submit button is outside the role="search" container'
				);

				// Directly after the field it runs and before the filters that
				// narrow it, which is the reading order Shortcode::filters()
				// claims in its docblock.
				assert_true(
					strpos( $html, 'class="slosm__search"' )
						< strpos( $html, 'class="slosm__submit"' ),
					'the submit button is rendered before the field it submits'
				);
				assert_true(
					strpos( $html, 'class="slosm__submit"' )
						< strpos( $html, 'class="slosm__radius"' ),
					'the submit button is rendered after the filters rather than before them'
				);
			}
		);

		it(
			'asks a touch keyboard for a search key on the address field',
			function () {
				// enterkeyhint is the whole of what a phone gets out of this
				// task's markup, and it is a separate thing from the button: it
				// labels the on-screen keyboard's action key. type="search"
				// alone does not settle it, because a user agent picks that
				// key's label from the control's context — chiefly whether it
				// is in a form with something to submit — and this markup
				// deliberately has no form. The attribute says it outright.
				$html = ( new Shortcode() )->render( '' );

				assert_contains(
					'<input type="search" class="slosm__search" value="" maxlength="200" enterkeyhint="search"'
						. ' autocomplete="off" autocapitalize="off" spellcheck="false" />',
					$html
				);
			}
		);

		it(
			'renders no form, so nothing here can submit a page or be swallowed by one',
			function () {
				$html = ( new Shortcode() )->render( '' );

				// The absence, and then the two controls that would have been
				// in it — without which "no <form>" is equally true of markup
				// with no controls at all.
				assert_same( false, strpos( $html, '<form' ), 'render() opened a form' );
				assert_same( false, strpos( $html, 'type="submit"' ), 'render() emitted a submit button' );

				assert_contains( 'class="slosm__submit"', $html );
				assert_contains( 'class="slosm__search"', $html );
			}
		);

		it(
			'gives the submit button a msgid of its own, not the settings tab\'s',
			function () {
				// 'Search' is already a msgid in this plugin: the settings
				// screen's tab that holds the search options. That is a noun
				// and this is an imperative, and a translator handed one entry
				// has to choose. _x() with a context makes them two, and this
				// is the case that says so — a site with a language pack that
				// translates the tab must not find its word on the button.
				$GLOBALS['slosm_stub']['translations']['Search'] = 'Wyszukiwanie';

				$html = ( new Shortcode() )->render( '' );

				assert_contains( '<button type="button" class="slosm__submit">Search</button>', $html );

				// The control, and it is the half that proves the assertion
				// above is about the context rather than about a stub that
				// translates nothing: the button's own entry does reach it.
				$GLOBALS['slosm_stub']['translations'][ 'submit button beside the locator address field' . "\4" . 'Search' ] = 'Szukaj';

				$translated = ( new Shortcode() )->render( '' );

				assert_contains( '<button type="button" class="slosm__submit">Szukaj</button>', $translated );

				// And the translation is escaped on the way out, for the reason
				// missing_category_comment() gives about the same thing: a
				// translator's text is not hostile, but it is not this file's
				// to vouch for either, and a language pack is a file the site
				// owner did not write.
				$GLOBALS['slosm_stub']['translations'][ 'submit button beside the locator address field' . "\4" . 'Search' ] = '<b>Szukaj</b>';

				$markup = ( new Shortcode() )->render( '' );

				assert_contains( 'class="slosm__submit">&lt;b&gt;Szukaj&lt;/b&gt;</button>', $markup );
				assert_same( false, strpos( $markup, '<b>Szukaj</b>' ), 'the button label reached the page as markup' );

				$GLOBALS['slosm_stub']['translations'] = array();
			}
		);

		it(
			'renders the submit button whatever near_me says, and it answers no attribute of its own',
			function () {
				$shortcode = new Shortcode();

				$without = $shortcode->render( array( 'near_me' => 'no' ) );

				assert_same( false, strpos( $without, 'class="slosm__locate"' ), 'near_me="no" still rendered the locate button' );
				assert_contains( 'class="slosm__submit"', $without );

				// The control for that absence: the same render with near_me on
				// has both, so "the locate button is gone" is a statement about
				// the attribute and not about a locator with no buttons at all.
				$with = $shortcode->render( array( 'near_me' => 'yes' ) );

				assert_contains( 'class="slosm__locate"', $with );
				assert_contains( 'class="slosm__submit"', $with );

				// And no attribute switches the submit control off, which is
				// the decision rather than an oversight: a search whose only
				// visible way to run it is optional is the gap this closes.
				$attributes = $shortcode->attributes( array() );

				assert_false( array_key_exists( 'submit', $attributes ), 'a submit attribute was added to the shortcode' );

				// The control for that absence: attributes() really does answer
				// with the attribute list, so a key missing from it is missing
				// from something.
				assert_true( array_key_exists( 'near_me', $attributes ) );
			}
		);

		it(
			'returns its markup rather than echoing it',
			function () {
				ob_start();
				$html = ( new Shortcode() )->render( array() );
				$echoed = ob_get_clean();

				assert_same( '', $echoed, 'render() echoed, which puts the locator at the top of the page' );

				// The control: "echoed nothing" is also true of a render that
				// returns nothing.
				assert_true( 100 < strlen( $html ) );
			}
		);

		it(
			'puts the height on the map element',
			function () {
				$html = ( new Shortcode() )->render( array( 'height' => '640' ) );

				assert_contains( '<div class="slosm__map" style="height:640px"', $html );

				$config = slosm_sc_config_from_html( $html );

				assert_same( 640, $config['height'] );
			}
		);

		it(
			'gives two locators on one page their own config and no shared global',
			function () {
				$shortcode = new Shortcode();

				$page = $shortcode->render( array( 'zoom' => '8', 'units' => 'km' ) )
					. $shortcode->render( array( 'zoom' => '15', 'units' => 'mi' ) );

				$first  = slosm_sc_attr( $page );
				$second = slosm_sc_attr( substr( $page, strpos( $page, $first ) + strlen( $first ) ) );

				assert_true( '' !== $first && '' !== $second, 'the page does not carry two configs' );
				assert_false( $first === $second, 'both locators carry the same config' );

				assert_same( 8, json_decode( html_entity_decode( $first, ENT_QUOTES, 'UTF-8' ), true )['zoom'] );
				assert_same( 15, json_decode( html_entity_decode( $second, ENT_QUOTES, 'UTF-8' ), true )['zoom'] );

				// Nothing indexed by instance, and nothing for a second locator
				// to collide with.
				assert_same( false, strpos( $page, 'slosmConfig' ) );
				assert_same( false, strpos( $page, 'window.' ) );
			}
		);

		it(
			'gives two locators landmark names a screen reader can tell apart',
			function () {
				$shortcode = new Shortcode();

				// Without a label both landmarks are generic, which is the
				// status quo and is only ambiguous for somebody who did not name
				// them.
				$plain = $shortcode->render( array() );

				assert_contains( 'role="search" aria-label="Find a location"', $plain );
				assert_contains( 'role="region" aria-label="Map of locations"', $plain );

				$page = $shortcode->render( array( 'label' => 'Warehouses' ) )
					. $shortcode->render( array( 'label' => 'Showrooms' ) );

				assert_contains( 'aria-label="Warehouses: find a location"', $page );
				assert_contains( 'aria-label="Warehouses: map of locations"', $page );
				assert_contains( 'aria-label="Showrooms: find a location"', $page );
				assert_contains( 'aria-label="Showrooms: map of locations"', $page );

				// It is user text in two attributes like any other, and both are
				// counted rather than merely looked for: one landmark still
				// escaping is enough to satisfy assert_contains while the other
				// hands the page an onmouseover handler.
				$nasty = $shortcode->render( array( 'label' => 'A" onmouseover="alert(1)' ) );

				assert_same( 2, substr_count( $nasty, 'A&quot; onmouseover=&quot;alert(1)' ), 'a landmark name reached the page unescaped' );
				assert_same( 0, substr_count( $nasty, 'A" onmouseover=' ) );
			}
		);

		it(
			'ships a row template with no location data in it',
			function () {
				slosm_sc_term( 12, 'Bakeries', 'bakeries' );

				$shortcode = new Shortcode();

				$plain  = slosm_sc_template( $shortcode->render( array() ) );
				$loaded = slosm_sc_template(
					$shortcode->render(
						array(
							'zoom'     => '18',
							'units'    => 'mi',
							'category' => 'bakeries',
							'height'   => '900',
							'search'   => 'Kraków',
						)
					)
				);

				assert_true( '' !== $plain, 'there is no row template to inspect' );
				assert_contains( 'slosm__result', $plain );

				// Byte for byte the same whatever the locator was configured
				// with, which is what "the JS clones it and fills it in" means.
				assert_same( $plain, $loaded );
			}
		);
	}
);

describe(
	'shortcode escaping',
	function () {

		before_each(
			function () {
				slosm_sc_count( 3 );
			}
		);

		it(
			'carries a category name full of html through the attribute intact',
			function () {
				$nasty = 'Ba"ke<r>y & \'x\' </script><img src=x onerror=alert(1)> <!-- --> \\ /';

				slosm_sc_term( 12, $nasty, 'nasty' );

				$shortcode = new Shortcode();
				$html      = $shortcode->render( array( 'category' => 'nasty' ) );
				$raw       = slosm_sc_attr( $html );

				assert_true( '' !== $raw, 'there is no data-slosm attribute to read' );

				// What a browser would end up with. If anything had ended the
				// attribute early this decode would fail or come back short.
				$config = slosm_sc_config_from_html( $html );

				assert_same( $nasty, $config['category'] );

				// Why it survives, stated as two facts rather than as an
				// argument.
				//
				// First: the attribute decodes back to exactly the json that was
				// encoded. That is the property esc_attr could have broken, and
				// nearly does — it does not double-encode, so a payload reaching
				// it with the literal text "&amp;" in it comes back unchanged
				// and the browser hands the script a bare "&". Encoding with the
				// hex flags first is what makes that impossible.
				$json = json_encode( $shortcode->config( $shortcode->attributes( array( 'category' => 'nasty' ) ) ), Shortcode::JSON_FLAGS );

				assert_same( $json, html_entity_decode( $raw, ENT_QUOTES, 'UTF-8' ), 'the attribute does not decode back to the json that was encoded' );

				// Second: the only thing esc_attr had to change was json's own
				// structural quotes. Every `"` in the json is a delimiter
				// json_encode wrote, never a character from a location name, and
				// json has no `<`, `>`, `'` or `&` of its own at all — so after
				// escaping there is exactly one `&` per structural quote and
				// nothing else for a browser to decode.
				assert_same( false, strpos( $raw, '"' ) );
				assert_same( false, strpos( $raw, "'" ) );
				assert_same( false, strpos( $raw, '<' ) );
				assert_same( false, strpos( $raw, '>' ) );
				assert_same( substr_count( $json, '"' ), substr_count( $raw, '&quot;' ) );
				assert_same( substr_count( $raw, '&quot;' ), substr_count( $raw, '&' ) );

				// And nothing anywhere in the page is a live tag from that name.
				// Only the angle brackets are the danger: `onerror=alert(1)` as
				// inert text inside an option is a string, not a handler, so
				// asserting on that substring would be asserting on esc_html()
				// doing something it does not do.
				assert_same( false, strpos( $html, '<img' ) );
				assert_same( false, strpos( $html, '<!-- -->' ) );
			}
		);

		it(
			'cannot be broken out of the option element by a category name',
			function () {
				$nasty = 'Ba"ke<r>y</option><script>alert(1)</script>';

				slosm_sc_term( 12, $nasty, 'nasty' );

				$html = ( new Shortcode() )->render( array( 'category' => 'nasty' ) );

				assert_same( false, strpos( $html, '<script' ) );
				assert_same( false, strpos( $html, '</option><script' ) );

				// Any live tag from that name, not just the script: the `<r>` is
				// the cheap half of the same break-out.
				assert_same( false, strpos( $html, '<r>' ) );

				// The control: the name did reach the option, escaped. Without
				// it every assertion above holds for a select that lists
				// nothing.
				assert_contains( '&lt;/option&gt;', $html );
				assert_contains( 'Ba&quot;ke&lt;r&gt;y', $html );
			}
		);

		it(
			'cannot be broken out of the html comment by a category nobody has',
			function () {
				// An html comment ends at the first literal --> or --!>, and
				// nothing inside one is entity-decoded — so what keeps this shut
				// is esc_html() turning every > into &gt;, not anything about
				// the dashes. sanitize_text_field() has already eaten the script
				// element by the time this is escaped, which is a second layer
				// and not the one under test: the `-->` it leaves alone is.
				$nasty = 'x --><script>alert(1)</script><!--';

				$html = ( new Shortcode() )->render( array( 'category' => $nasty ) );

				$open = strpos( $html, '<!--' );

				assert_true( is_int( $open ), 'no comment was rendered at all' );

				$close = strpos( $html, '-->', $open );

				assert_true( is_int( $close ), 'the comment was never closed' );

				$body = substr( $html, $open + 4, $close - $open - 4 );

				// Nothing in the body can end the comment early, because nothing
				// in the body is an angle bracket.
				assert_same( false, strpos( $body, '<' ) );
				assert_same( false, strpos( $body, '>' ) );

				// The control: the value did reach the comment, with the `>` of
				// its `-->` escaped. Without this, the two assertions above hold
				// for a comment that says nothing.
				assert_contains( 'x --&gt;', $body );

				// And the page has no live script anywhere, before or after it.
				assert_same( false, strpos( $html, '<script' ) );
			}
		);

		it(
			'falls back to an empty config rather than an unterminated attribute',
			function () {
				// An invalid utf-8 byte makes json_encode() return false. On a
				// real site wp_json_encode() would usually rescue this by
				// re-encoding through _wp_json_sanity_check(), and esc_attr()
				// would have stripped the byte before that — the stub models
				// neither, so this case pins our fallback rather than
				// WordPress's rescue. Printing `data-slosm=""` with a raw false
				// in it is the outcome being refused.
				slosm_sc_term( 12, "Bad\xB1name", 'nasty' );

				$html = ( new Shortcode() )->render( array( 'category' => 'nasty' ) );

				assert_contains( 'data-slosm="{}"', $html );

				// The control: the locator still rendered, so the fallback is a
				// fallback and not a bail-out.
				assert_contains( '<div class="slosm__map"', $html );
				assert_contains( '<template class="slosm__row">', $html );
			}
		);
	}
);

describe(
	'the class a site can put on the locator’s two buttons',
	function () {

		it(
			'adds nothing at all when neither the setting nor the attribute says anything',
			function () {
				$html = ( new Shortcode() )->render( '' );

				// Exactly the class it had before this field existed. A trailing
				// space inside the attribute would be harmless to a browser and
				// is still wrong: every other case in this file pins these two
				// class attributes whole, and an empty field that changed the
				// markup would be a default that is not the state before it.
				assert_contains( 'class="slosm__submit"', $html );
				assert_contains( 'class="slosm__locate"', $html );
			}
		);

		it(
			'puts the site setting on both buttons',
			function () {
				$GLOBALS['slosm_stub']['options'][ \Asymetria\StoreLocator\Settings::OPTION ] = array(
					'button_class' => 'bricks-button',
				);

				$html = ( new Shortcode() )->render( '' );

				// Both, and one field rather than two. A row where Search
				// inherits the theme's buttons and "Use my location" stays a
				// raw browser widget beside it is worse than either state
				// applied to both; a site that wants them apart has
				// `.slosm__locate` to aim at.
				assert_contains( 'class="slosm__submit bricks-button"', $html );
				assert_contains( 'class="slosm__locate bricks-button"', $html );

				// And nowhere else. The result row's button is a button that
				// has to read as a row of text, so a theme's button class on it
				// would undo the one rule the layout layer keeps for it.
				assert_same( false, strpos( slosm_sc_template( $html ), 'bricks-button' ), 'the class reached the result row template' );
			}
		);

		it(
			'lets one locator name a class of its own, and the attribute wins',
			function () {
				$GLOBALS['slosm_stub']['options'][ \Asymetria\StoreLocator\Settings::OPTION ] = array(
					'button_class' => 'bricks-button',
				);

				$html = ( new Shortcode() )->render( array( 'button_class' => 'btn btn-ghost' ) );

				assert_contains( 'class="slosm__submit btn btn-ghost"', $html );
				assert_contains( 'class="slosm__locate btn btn-ghost"', $html );
				assert_same( false, strpos( $html, 'bricks-button' ), 'the site setting was added to the class the shortcode named' );
			}
		);

		it(
			'reads an empty attribute as “say nothing”, which is what every other attribute here means by it',
			function () {
				$GLOBALS['slosm_stub']['options'][ \Asymetria\StoreLocator\Settings::OPTION ] = array(
					'button_class' => 'bricks-button',
				);

				$html = ( new Shortcode() )->render( array( 'button_class' => '   ' ) );

				assert_contains( 'class="slosm__submit bricks-button"', $html );
			}
		);

		it(
			'cannot be used to break out of the attribute it is written into',
			function () {
				$html = ( new Shortcode() )->render( array( 'button_class' => 'x" onclick="alert(1)' ) );

				assert_same( false, strpos( $html, 'onclick="alert(1)"' ), 'an event handler reached the markup' );
				assert_contains( 'class="slosm__submit x onclickalert1"', $html );
			}
		);
	}
);

describe(
	'the status line, which is where a sentence is announced from',
	function () {

		it(
			'renders one empty status element between the filter row and the map',
			function () {
				$html = ( new Shortcode() )->render( '' );

				// Empty, with no whitespace between the tags, because the
				// layout layer hides it with :empty — an empty grid item would
				// otherwise hold a row and the grid's gap open on every locator
				// that has nothing to say.
				assert_contains( '<p class="slosm__message" role="status" aria-live="polite"></p>', $html );

				// Before the map rather than after it. A person who has just
				// searched should not have to scroll past a map to find out
				// that nothing matched, and on a wide screen the list is in
				// the other column.
				$status = strpos( $html, 'class="slosm__message"' );
				$map    = strpos( $html, 'class="slosm__map"' );
				$row    = strpos( $html, 'class="slosm__filters"' );

				assert_true( false !== $status && false !== $map && false !== $row, 'one of the three landmarks is not rendered at all' );
				assert_true( $row < $status, 'the status line is above the search row' );
				assert_true( $status < $map, 'the status line is below the map' );
			}
		);

		it(
			'leaves the results list out of the live region, so a redraw is not read out row by row',
			function () {
				$html = ( new Shortcode() )->render( '' );

				/*
				 * The change this task is: the list used to carry
				 * aria-live="polite" itself, so replacing it with seven results
				 * announced seven names, addresses, cities and distances. The
				 * sentence above it is what a person needs; the rows are what
				 * they will read afterwards, at their own pace, by moving
				 * through the list.
				 */
				assert_contains( '<ol class="slosm__results"></ol>', $html );
				assert_same( false, strpos( $html, '<ol class="slosm__results" aria-live' ), 'the results list is still a live region' );
			}
		);
	}
);
