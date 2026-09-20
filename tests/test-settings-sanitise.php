<?php
/**
 * Proves the one function that stands between a settings form and the option.
 *
 * Everything else in Task 21 is a screen. This is the part that has to be right
 * whether or not anybody ever looks at the screen, because
 * `register_setting()`'s sanitize callback is added as a filter on
 * `sanitize_option_slosm_settings` (wp-includes/option.php line 3073 of
 * WordPress 6.9.1) and `update_option()` runs `sanitize_option()` on every
 * write (line 887). So this function is the gate for the form, for WP-CLI, for
 * an importer and for this plugin's own code alike — there is no second door.
 *
 * FOUR THINGS IT HAS TO GET RIGHT, AND WHAT EACH ONE COSTS WHEN IT DOES NOT
 * =========================================================================
 *
 * A tile url with no `{z}`, `{x}` and `{y}` in it. Leaflet substitutes those
 * three and asks for whatever is left, so a url without them fetches one image
 * and draws it as every tile at every zoom — a map that looks like a bug in
 * Leaflet rather than like a setting somebody typed wrong.
 *
 * An endpoint that is not http or https. `esc_url_raw( $url )` allows
 * twenty-two protocols, not two: its default protocol list is
 * `wp_allowed_protocols()`, which carries ftp, telnet, svn and eighteen more.
 * This project has already been caught believing otherwise once —
 * tests/bootstrap.php's `esc_url_raw()` docblock has the incident — and an
 * accepted `ftp://` endpoint is a site whose geocoding is permanently broken by
 * a value that looked accepted.
 *
 * A key that is absent. `wp-admin/options.php` reads `$_POST['slosm_settings']`
 * and passes **null** when the form did not carry it (line 337), and each of
 * the four tabs posts only its own fields. A sanitiser that built its answer
 * out of the input alone would wipe the other three tabs every time somebody
 * saved one — and wipe the whole option on a form that carried nothing.
 *
 * A key that is not one of ours. The option is a closed list; anything else in
 * it is an importer's leftovers or another plugin that took the same name, and
 * carrying it forward is how a closed list stops being closed.
 *
 * THE CONTROL FOR EVERY ABSENCE ASSERTION
 * ---------------------------------------
 * "X is dropped" is worthless without "X would have been kept had it been
 * valid", because a sanitiser that dropped *everything* passes the first and
 * fails the second. Every case below that asserts a value was refused has a
 * partner asserting the same field accepts a good value, and the partner is
 * named in the case title.
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

use Asymetria\StoreLocator\Geo;
use Asymetria\StoreLocator\Geocoder;
use Asymetria\StoreLocator\Rest_Controller;
use Asymetria\StoreLocator\Settings;
use Asymetria\StoreLocator\Shortcode;

if ( ! function_exists( 'slosm_sanitise' ) ) {
	/**
	 * Sanitises one field, with everything else left alone.
	 *
	 * @param string $key   Setting name.
	 * @param mixed  $value Raw value.
	 * @return mixed The sanitised value of that one field.
	 */
	function slosm_sanitise( string $key, $value ) {
		$clean = Settings::sanitise( array( $key => $value ) );

		return $clean[ $key ] ?? null;
	}
}

describe(
	'Settings::defaults() — the closed list itself',
	function () {
		it(
			'writes the option name the geocoder already reads, rather than a second one',
			function () {
				assert_same( Geocoder::SETTINGS_OPTION, Settings::OPTION );
			}
		);

		it(
			'puts every setting in exactly one tab, and every tab key in the defaults',
			function () {
				$seen = array();

				foreach ( Settings::TABS as $tab => $keys ) {
					assert_true( count( $keys ) > 0, $tab . ' has no settings on it' );

					foreach ( $keys as $key ) {
						assert_false( in_array( $key, $seen, true ), $key . ' is in two tabs' );
						$seen[] = $key;
					}
				}

				sort( $seen );
				$defaults = array_keys( Settings::defaults() );
				sort( $defaults );

				assert_same( $defaults, $seen );
			}
		);

		it(
			'has four tabs and no more',
			function () {
				assert_same( array( 'map', 'search', 'results', 'advanced' ), array_keys( Settings::TABS ) );
			}
		);

		it(
			'defaults to the values the rest of the plugin already used, so an unconfigured site does not move',
			function () {
				$defaults = Settings::defaults();

				assert_same( Shortcode::DEFAULT_ZOOM, $defaults['default_zoom'] );
				assert_same( Shortcode::DEFAULT_HEIGHT, $defaults['map_height'] );
				assert_same( Shortcode::MAX_ZOOM, $defaults['tile_max_zoom'] );
				assert_same( Rest_Controller::DEFAULT_RADIUS, $defaults['default_radius'] );
				assert_same( Rest_Controller::MAX_LIMIT, $defaults['default_limit'] );
				assert_same( Geocoder::CACHE_TTL, $defaults['geocode_cache_ttl'] );
				assert_same( Geocoder::SUGGEST_CACHE_TTL, $defaults['suggest_cache_ttl'] );
				assert_same( 'km', $defaults['units'] );
				assert_same( 'auto', $defaults['cluster'] );
				assert_same( true, $defaults['near_me'] );
			}
		);

		it(
			'leaves both endpoints empty, so the geocoder falls back to its own defaults',
			function () {
				$defaults = Settings::defaults();

				assert_same( '', $defaults['geocode_endpoint'] );
				assert_same( '', $defaults['suggest_endpoint'] );
				assert_same( '', $defaults['geocode_user_agent'] );
			}
		);

		it(
			'builds the same tile layer for a caller that hands it nothing',
			function () {
				// tile_config() is called with Settings::all() by the shortcode
				// and by the picker, and with nothing by a test; a short array is
				// neither, which is why the defaults are unioned in rather than
				// defended key by key. This is the call that makes that line
				// falsifiable.
				assert_same( Settings::tile_config(), Settings::tile_config( array() ) );
				assert_contains( '{z}', Settings::tile_config( array() )['url'] );
			}
		);

		it(
			'ships a default tile url that carries all three placeholders',
			function () {
				$url = Settings::defaults()['tile_url'];

				foreach ( Settings::TILE_PLACEHOLDERS as $placeholder ) {
					assert_contains( $placeholder, $url );
				}
			}
		);
	}
);

describe(
	'Settings::sanitise() — the shape of what comes out',
	function () {
		it(
			'answers with exactly the keys of the closed list, whatever went in',
			function () {
				$clean = Settings::sanitise( array( 'units' => 'mi' ) );

				assert_same( array_keys( Settings::defaults() ), array_keys( $clean ) );
				assert_true( count( $clean ) > 20, 'the closed list is not empty' );
			}
		);

		it(
			'drops a key that is not one of its own',
			function () {
				$clean = Settings::sanitise(
					array(
						'units'          => 'mi',
						'wp_capability'  => 'manage_options',
						'anything_else'  => 'kept?',
					)
				);

				assert_false( array_key_exists( 'wp_capability', $clean ) );
				assert_false( array_key_exists( 'anything_else', $clean ) );

				// The control: the key beside the dropped ones was kept.
				assert_same( 'mi', $clean['units'] );
			}
		);

		it(
			'keeps a stored value for a key the form did not carry, which is what a tab is',
			function () {
				$GLOBALS['slosm_stub']['options'][ Settings::OPTION ] = array(
					'units'   => 'mi',
					'near_me' => false,
				);

				// The Search tab was not the tab that was posted.
				$clean = Settings::sanitise( array( 'default_zoom' => '7' ) );

				assert_same( 'mi', $clean['units'] );
				assert_same( false, $clean['near_me'] );
				assert_same( 7, $clean['default_zoom'] );
			}
		);

		it(
			'keeps everything stored when options.php passes null, which is a form that carried nothing',
			function () {
				$GLOBALS['slosm_stub']['options'][ Settings::OPTION ] = array( 'units' => 'mi' );

				$clean = Settings::sanitise( null );

				assert_same( 'mi', $clean['units'] );
			}
		);

		it(
			'keeps everything stored when handed something that is not an array at all',
			function () {
				$GLOBALS['slosm_stub']['options'][ Settings::OPTION ] = array( 'units' => 'mi' );

				assert_same( 'mi', Settings::sanitise( 'units=mi' )['units'] );
			}
		);

		it(
			'falls back to the default rather than to the stored value when a posted value is unusable',
			function () {
				$GLOBALS['slosm_stub']['options'][ Settings::OPTION ] = array( 'units' => 'mi' );

				// Posted and wrong is a person saying something; absent is a form
				// not asking. The two must not answer the same way.
				assert_same( 'km', Settings::sanitise( array( 'units' => 'furlongs' ) )['units'] );
				assert_same( 'mi', Settings::sanitise( array() )['units'] );
			}
		);

		it(
			'takes the defaults when the stored option is not an array',
			function () {
				$GLOBALS['slosm_stub']['options'][ Settings::OPTION ] = 'a string somebody put there';

				assert_same( Settings::defaults(), Settings::sanitise( array() ) );
				assert_same( 'km', Settings::sanitise( array() )['units'] );
			}
		);

		it(
			'takes the defaults when the stored option is an object, which a cast would not',
			function () {
				// The object is the separating input, and a string is not: a cast
				// turns 'x' into array( 0 => 'x' ) and key 0 is never one of ours,
				// so `(array) $stored` and `is_array( $stored ) ? … : array()`
				// agree about every string. They do not agree about an object,
				// whose properties become keys — and get_option() really can
				// answer with one, because anything may have written this option.
				$GLOBALS['slosm_stub']['options'][ Settings::OPTION ] = (object) array( 'units' => 'mi' );

				assert_same( 'km', Settings::sanitise( array() )['units'] );
			}
		);

		it(
			're-sanitises a stored value rather than trusting it',
			function () {
				// Written before this screen existed, or by a direct database
				// edit, which never passes through the filter.
				$GLOBALS['slosm_stub']['options'][ Settings::OPTION ] = array(
					'units'    => 'parsecs',
					'map_height' => 999999,
				);

				$clean = Settings::sanitise( array() );

				assert_same( 'km', $clean['units'] );
				assert_same( Shortcode::MAX_HEIGHT, $clean['map_height'] );
			}
		);
	}
);

describe(
	'Settings::sanitise() — the tile url',
	function () {
		it(
			'keeps a tile url that carries all three placeholders',
			function () {
				$url = 'https://tiles.example.test/{z}/{x}/{y}@2x.png';

				assert_same( $url, slosm_sanitise( 'tile_url', $url ) );
			}
		);

		it(
			'refuses a tile url with no placeholders at all, which draws one image as every tile',
			function () {
				assert_same(
					Settings::defaults()['tile_url'],
					slosm_sanitise( 'tile_url', 'https://tiles.example.test/tile.png' )
				);
			}
		);

		it(
			'refuses a tile url missing {z}',
			function () {
				assert_same(
					Settings::defaults()['tile_url'],
					slosm_sanitise( 'tile_url', 'https://tiles.example.test/7/{x}/{y}.png' )
				);
			}
		);

		it(
			'refuses a tile url missing {x}',
			function () {
				assert_same(
					Settings::defaults()['tile_url'],
					slosm_sanitise( 'tile_url', 'https://tiles.example.test/{z}/0/{y}.png' )
				);
			}
		);

		it(
			'refuses a tile url missing {y}',
			function () {
				assert_same(
					Settings::defaults()['tile_url'],
					slosm_sanitise( 'tile_url', 'https://tiles.example.test/{z}/{x}/0.png' )
				);
			}
		);

		it(
			'refuses a tile url that is not http or https, however many placeholders it has',
			function () {
				assert_same(
					Settings::defaults()['tile_url'],
					slosm_sanitise( 'tile_url', 'ftp://tiles.example.test/{z}/{x}/{y}.png' )
				);
				assert_same(
					Settings::defaults()['tile_url'],
					slosm_sanitise( 'tile_url', 'javascript:alert(1)/{z}/{x}/{y}' )
				);
			}
		);

		it(
			'keeps a plain http tile url, because a tile server on the same network has no certificate',
			function () {
				$url = 'http://tiles.internal/{z}/{x}/{y}.png';

				assert_same( $url, slosm_sanitise( 'tile_url', $url ) );
			}
		);

		it(
			'keeps a tile url that carries an api key in its query string',
			function () {
				$url = 'https://tiles.example.test/{z}/{x}/{y}.png?key=abc123';

				assert_same( $url, slosm_sanitise( 'tile_url', $url ) );
			}
		);

		it(
			'strips the characters that would end the attribute the url is printed into',
			function () {
				// The url goes into data-slosm= as json and into the picker's
				// own data- attribute, and the json flags plus esc_attr() are
				// what stand between it and the markup. This is the layer under
				// those: core's own character class, which has no quote, no
				// angle bracket and no backtick in it. The braces are the only
				// two characters added to it, and TILE_URL_CHARACTERS says why.
				$clean = slosm_sanitise( 'tile_url', 'https://tiles.example.test/{z}/{x}/{y}.png"><script>' );

				// The letters survive — core's class removes characters rather
				// than tokens, so what is left is a nonsense url that fetches
				// nothing. The three characters that could end an attribute do
				// not survive, and that is the whole claim. A first version of
				// this case expected the whole tail to be gone and was wrong
				// about what core's expression does.
				foreach ( array( '"', '<', '>' ) as $dangerous ) {
					assert_false( false !== strpos( $clean, $dangerous ), $clean );
				}

				assert_contains( '{z}/{x}/{y}', $clean );

				assert_same(
					'https://tiles.example.test/{z}/{x}/{y}.png',
					slosm_sanitise( 'tile_url', "https://tiles.example.test/{z}/{x}/{y}.png`\n" )
				);
			}
		);

		it(
			'takes the default for a tile url that is empty, and for one that is not a string',
			function () {
				assert_same( Settings::defaults()['tile_url'], slosm_sanitise( 'tile_url', '' ) );
				assert_same( Settings::defaults()['tile_url'], slosm_sanitise( 'tile_url', array( 'x' ) ) );
			}
		);
	}
);

describe(
	'Settings::sanitise() — the attribution, which is a licence and not decoration',
	function () {
		it(
			'keeps attribution text as text',
			function () {
				assert_same( 'Tiles by Example', slosm_sanitise( 'tile_attribution', '  Tiles by Example  ' ) );
			}
		);

		it(
			'strips markup out of the attribution text, because Leaflet writes it with innerHTML',
			function () {
				// A script element goes body and all: sanitize_text_field() runs
				// wp_strip_all_tags(), which removes <script> and <style> blocks
				// whole before stripping the remaining tags
				// (wp-includes/formatting.php of WordPress 6.9.1). A case here
				// first expected 'alert(1) Tiles' and was wrong about core.
				assert_same(
					'Tiles',
					slosm_sanitise( 'tile_attribution', '<script>alert(1)</script> Tiles' )
				);

				// An ordinary tag loses the tag and keeps the words, which is
				// the control: the sanitiser is not simply blanking the field.
				assert_same(
					'Tiles by Example',
					slosm_sanitise( 'tile_attribution', 'Tiles by <b>Example</b>' )
				);
			}
		);

		it(
			'keeps an http or https attribution link',
			function () {
				assert_same(
					'https://example.test/licence',
					slosm_sanitise( 'tile_attribution_url', 'https://example.test/licence' )
				);
			}
		);

		it(
			'refuses an attribution link that is not http or https',
			function () {
				assert_same( '', slosm_sanitise( 'tile_attribution_url', 'javascript:alert(1)' ) );
				assert_same( '', slosm_sanitise( 'tile_attribution_url', 'ftp://example.test/licence' ) );
			}
		);

		it(
			'allows an empty attribution link, which is an attribution with nothing to link to',
			function () {
				assert_same( '', slosm_sanitise( 'tile_attribution_url', '' ) );
			}
		);

		it(
			'builds the attribution into an anchor when there is a link, escaping both halves',
			function () {
				$html = Settings::attribution_html(
					array(
						'tile_attribution'     => 'Tiles & maps',
						'tile_attribution_url' => 'https://example.test/l?a=1&b=2',
					)
				);

				assert_contains( 'href="https://example.test/l?a=1&amp;b=2"', $html );
				assert_contains( '>Tiles &amp; maps</a>', $html );
				assert_contains( 'rel="noreferrer"', $html );
			}
		);

		it(
			'builds the attribution as escaped text when there is no link',
			function () {
				$html = Settings::attribution_html(
					array(
						'tile_attribution'     => 'Tiles <b>&</b> maps',
						'tile_attribution_url' => '',
					)
				);

				// The whole string, not the absence of a literal '<b>'. The
				// first version of this case asserted the latter and held with
				// the sanitiser deleted, because esc_html() removes the literal
				// on its own — the tag came out as &lt;b&gt; and the assertion
				// could not tell that from the tag being gone.
				assert_same( 'Tiles &amp; maps', $html );
				assert_false( false !== strpos( $html, '<a ' ), 'no anchor without a url' );
			}
		);

		it(
			'sanitises both halves itself rather than trusting whoever called it',
			function () {
				// attribution_html() is public and the string it builds is handed
				// to Leaflet, which writes a layer's attribution with innerHTML.
				// "The caller will have sanitised it" is the assumption that
				// makes these bugs, so the two halves are narrowed again here —
				// and these are raw values, never through Settings::sanitise().
				$html = Settings::attribution_html(
					array(
						'tile_attribution'     => 'Tiles',
						'tile_attribution_url' => 'javascript:alert(1)',
					)
				);

				assert_same( 'Tiles', $html );
				assert_false( false !== strpos( $html, 'javascript' ), $html );
			}
		);

		it(
			'says nothing at all when the attribution text is empty, and something when it is not',
			function () {
				assert_same(
					'',
					Settings::attribution_html(
						array(
							'tile_attribution'     => '',
							'tile_attribution_url' => 'https://example.test/licence',
						)
					)
				);

				// The control, in the same case: the only thing that changed is
				// the text, so an implementation that always says nothing fails
				// here rather than passing on the line above.
				assert_contains(
					'Tiles',
					Settings::attribution_html(
						array(
							'tile_attribution'     => 'Tiles',
							'tile_attribution_url' => 'https://example.test/licence',
						)
					)
				);
			}
		);
	}
);

describe(
	'Settings::sanitise() — units, and the lists of numbers',
	function () {
		it(
			'takes a unit that is on Geo::UNITS, in any case and with any whitespace',
			function () {
				assert_same( 'mi', slosm_sanitise( 'units', ' MI ' ) );
				assert_same( 'km', slosm_sanitise( 'units', 'km' ) );
			}
		);

		it(
			'refuses a unit that is not on Geo::UNITS',
			function () {
				assert_same( 'km', slosm_sanitise( 'units', 'furlongs' ) );
				assert_same( 'km', slosm_sanitise( 'units', true ) );

				// The control is above: 'mi' is taken.
				assert_same( 2, count( Geo::UNITS ) );
			}
		);

		it(
			'coerces the radius list to sorted, positive, deduplicated numbers',
			function () {
				assert_same(
					array( 2.5, 5.0, 10.0, 50.0 ),
					slosm_sanitise( 'radius_choices', '50, 5, 10, 10, 2.5, 0, -3, banana' )
				);
			}
		);

		it(
			'clamps a radius choice to what the route will actually accept',
			function () {
				assert_same(
					array( Rest_Controller::MAX_RADIUS ),
					slosm_sanitise( 'radius_choices', '99999' )
				);
			}
		);

		it(
			'takes the default radius list when nothing usable was given',
			function () {
				assert_same( Settings::defaults()['radius_choices'], slosm_sanitise( 'radius_choices', '  , 0, -1, ' ) );
				assert_same( Settings::defaults()['radius_choices'], slosm_sanitise( 'radius_choices', '' ) );
			}
		);

		it(
			'takes a radius list posted as an array as well as one typed into a field',
			function () {
				assert_same( array( 5.0, 25.0 ), slosm_sanitise( 'radius_choices', array( '25', 5 ) ) );
			}
		);

		it(
			'stops the radius list at a length somebody can read',
			function () {
				$typed = implode( ',', range( 1, Settings::MAX_CHOICES + 20 ) );
				$clean = slosm_sanitise( 'radius_choices', $typed );

				assert_same( Settings::MAX_CHOICES, count( $clean ) );
				assert_same( 1.0, $clean[0] );
			}
		);

		it(
			'coerces the result-count list to sorted, positive, deduplicated whole numbers',
			function () {
				assert_same(
					array( 5, 10, 25 ),
					slosm_sanitise( 'limit_choices', '25, 10, 10, 5, 0, -4, none' )
				);
			}
		);

		it(
			'clamps a result count to the most the route will return',
			function () {
				assert_same( array( Rest_Controller::MAX_LIMIT ), slosm_sanitise( 'limit_choices', '4000' ) );
			}
		);

		it(
			'takes the default result-count list when nothing usable was given',
			function () {
				assert_same( Settings::defaults()['limit_choices'], slosm_sanitise( 'limit_choices', 'all of them' ) );
			}
		);
	}
);

describe(
	'Settings::sanitise() — the numbers with bounds the rest of the plugin already fixed',
	function () {
		it(
			'clamps the default radius to what the route accepts, and never to nothing',
			function () {
				assert_same( 25.0, slosm_sanitise( 'default_radius', '25' ) );
				assert_same( Rest_Controller::MAX_RADIUS, slosm_sanitise( 'default_radius', '9000' ) );
				assert_same( Rest_Controller::DEFAULT_RADIUS, slosm_sanitise( 'default_radius', '0' ) );
				assert_same( Rest_Controller::DEFAULT_RADIUS, slosm_sanitise( 'default_radius', '-5' ) );
			}
		);

		it(
			'clamps the default result count between one and the route’s ceiling',
			function () {
				assert_same( 20, slosm_sanitise( 'default_limit', '20' ) );
				assert_same( Rest_Controller::MAX_LIMIT, slosm_sanitise( 'default_limit', '9000' ) );
				assert_same( 1, slosm_sanitise( 'default_limit', '0' ) );
			}
		);

		it(
			'clamps the default zoom to the range the shortcode already clamps to',
			function () {
				assert_same( 7, slosm_sanitise( 'default_zoom', '7' ) );
				assert_same( Shortcode::MAX_ZOOM, slosm_sanitise( 'default_zoom', '30' ) );
				assert_same( Shortcode::MIN_ZOOM, slosm_sanitise( 'default_zoom', '-4' ) );
			}
		);

		it(
			'clamps the tile layer’s top zoom to the same range',
			function () {
				assert_same( 17, slosm_sanitise( 'tile_max_zoom', '17' ) );
				assert_same( Shortcode::MAX_ZOOM, slosm_sanitise( 'tile_max_zoom', '22' ) );
				assert_same( Shortcode::MIN_ZOOM, slosm_sanitise( 'tile_max_zoom', '0' ) );
			}
		);

		it(
			'clamps the map height to the range the shortcode already clamps to',
			function () {
				assert_same( 600, slosm_sanitise( 'map_height', '600px' ) );
				assert_same( Shortcode::MAX_HEIGHT, slosm_sanitise( 'map_height', '50000' ) );
				assert_same( Shortcode::MIN_HEIGHT, slosm_sanitise( 'map_height', '10' ) );
				assert_same( Shortcode::DEFAULT_HEIGHT, slosm_sanitise( 'map_height', 'tall' ) );
			}
		);

		it(
			'reads a default centre, and calls an empty one no centre rather than the Gulf of Guinea',
			function () {
				assert_same( 52.2297, slosm_sanitise( 'default_lat', '52.2297' ) );
				assert_same( 21.0122, slosm_sanitise( 'default_lng', '21.0122' ) );
				assert_same( null, slosm_sanitise( 'default_lat', '' ) );
				assert_same( null, slosm_sanitise( 'default_lng', '   ' ) );
			}
		);

		it(
			'refuses a centre that is not on the earth rather than clamping it to the pole',
			function () {
				assert_same( null, slosm_sanitise( 'default_lat', '95' ) );
				assert_same( null, slosm_sanitise( 'default_lng', '-181' ) );
				assert_same( null, slosm_sanitise( 'default_lat', 'north' ) );

				// The control is above: 52.2297 is taken.
				assert_same( 0.0, slosm_sanitise( 'default_lat', '0' ) );
			}
		);
	}
);

describe(
	'Settings::sanitise() — the closed choices',
	function () {
		it(
			'takes a clustering choice this plugin knows, and auto for anything else',
			function () {
				assert_same( 'yes', slosm_sanitise( 'cluster', 'yes' ) );
				assert_same( 'no', slosm_sanitise( 'cluster', 'no' ) );
				assert_same( 'auto', slosm_sanitise( 'cluster', 'maybe' ) );
			}
		);

		it(
			'takes a marker style this plugin can draw, and the pin for anything else',
			function () {
				assert_same( 'dot', slosm_sanitise( 'marker_style', 'dot' ) );
				assert_same( 'pin', slosm_sanitise( 'marker_style', 'teardrop' ) );
			}
		);

		it(
			'takes a hex colour and refuses anything that is not one',
			function () {
				assert_same( '#c0392b', slosm_sanitise( 'marker_colour', '#c0392b' ) );
				assert_same( '#abc', slosm_sanitise( 'marker_colour', '#abc' ) );
				assert_same( Settings::defaults()['marker_colour'], slosm_sanitise( 'marker_colour', 'red' ) );

				// The empty string is its own case, because core answers '' for
				// an empty colour and null for an invalid one — so a check of
				// is_string() alone stores '' and the dot then carries
				// `background-color:` with nothing after it.
				assert_same( Settings::defaults()['marker_colour'], slosm_sanitise( 'marker_colour', '' ) );
				assert_same( Settings::defaults()['marker_colour'], slosm_sanitise( 'marker_colour', 'c0392b' ) );
				assert_same(
					Settings::defaults()['marker_colour'],
					slosm_sanitise( 'marker_colour', '#fff;background:url(javascript:alert(1))' )
				);
			}
		);

		it(
			'agrees with core’s own colour rule wherever core has one to agree with',
			function () {
				// Settings::colour() does not call sanitize_hex_color(). Its
				// docblock has why — the function is in wp-includes, which puts
				// it on a front-end request on WordPress 6.9.1, and this plugin's
				// header promises 6.0, which there is no copy of in this tree to
				// check and no way to check without a network request this
				// repository forbids. A fatal there is a white page on every page
				// with a map on it.
				//
				// So the rule is owned, and this is what stops the copy drifting
				// from the original on every version that *does* have one. It is
				// skipped rather than failed where the function is absent, which
				// is the honest thing for a case about somebody else's code —
				// and the assertion below it is what keeps the skip from being a
				// free pass.
				if ( function_exists( 'sanitize_hex_color' ) ) {
					foreach (
						array(
							'#fff',
							'#FFF',
							'#c0392b',
							'#C0392B',
							'',
							'  ',
							'fff',
							'c0392b',
							'#ffff',
							'#fffffff',
							// Nine digits, which is what catches a quantifier
							// widened from {1,2} to {1,3}: without a value this
							// long the table cannot see that change at all, and
							// a probe said so.
							'#abcdef123',
							'#ggg',
							'red',
							'#fff;background:url(javascript:alert(1))',
						) as $value
					) {
						$core = sanitize_hex_color( trim( $value ) );
						$core = is_string( $core ) && '' !== $core ? $core : Settings::defaults()['marker_colour'];

						assert_same(
							$core,
							slosm_sanitise( 'marker_colour', $value ),
							'core and this plugin disagree about ' . var_export( $value, true )
						);
					}
				}

				// The control, which runs whether or not core's function is
				// there: the rule really is being applied.
				assert_same( '#abc', slosm_sanitise( 'marker_colour', ' #abc ' ) );
				assert_same( Settings::defaults()['marker_colour'], slosm_sanitise( 'marker_colour', '#abcd' ) );
			}
		);

		it(
			'takes a list position this plugin has a layout for',
			function () {
				assert_same( 'left', slosm_sanitise( 'results_position', 'left' ) );
				assert_same( 'below', slosm_sanitise( 'results_position', 'below' ) );

				// 'right' is the default rather than 'below', because a list to
				// the right of the map above 48em is what assets/css/locator.css
				// already did before there was a setting — and a default that
				// rearranged an existing site's locator would be a settings
				// screen moving something nobody touched.
				assert_same( 'right', slosm_sanitise( 'results_position', 'above' ) );
				assert_same( 'right', Settings::defaults()['results_position'] );
			}
		);

		it(
			'takes a directions target this plugin can build a url for',
			function () {
				assert_same( 'google', slosm_sanitise( 'directions', 'google' ) );
				assert_same( 'none', slosm_sanitise( 'directions', 'none' ) );
				assert_same( 'osm', slosm_sanitise( 'directions', 'waze' ) );
			}
		);

		it(
			'keeps the fields it knows, in its own order, and drops the rest',
			function () {
				assert_same(
					array( 'name', 'city', 'distance' ),
					slosm_sanitise( 'result_fields', array( 'distance', 'city', 'name', 'password' ) )
				);
			}
		);

		it(
			'takes the default field list when nothing it knows was chosen',
			function () {
				assert_same( Settings::defaults()['result_fields'], slosm_sanitise( 'result_fields', array( 'password' ) ) );
				assert_same( Settings::defaults()['popup_fields'], slosm_sanitise( 'popup_fields', array() ) );
			}
		);

		it(
			'keeps the popup fields it knows, in its own order',
			function () {
				assert_same(
					array( 'name', 'address', 'phone' ),
					slosm_sanitise( 'popup_fields', array( 'phone', 'name', 'address' ) )
				);
			}
		);
	}
);

describe(
	'Settings::sanitise() — the booleans',
	function () {
		it(
			'reads every spelling of yes as true',
			function () {
				foreach ( array( '1', 'on', 'yes', 'true', 1, true ) as $value ) {
					assert_true( slosm_sanitise( 'near_me', $value ), var_export( $value, true ) . ' should be true' );
				}
			}
		);

		it(
			'reads every spelling of no as false, including the hidden zero a checkbox travels with',
			function () {
				foreach ( array( '0', 'off', 'no', 'false', 0, false, '' ) as $value ) {
					assert_false( slosm_sanitise( 'near_me', $value ), var_export( $value, true ) . ' should be false' );
				}
			}
		);

		it(
			'reads an unchecked box as off rather than as the default, because that is what the hidden field is for',
			function () {
				$GLOBALS['slosm_stub']['options'][ Settings::OPTION ] = array( 'autocomplete' => true );

				assert_false( Settings::sanitise( array( 'autocomplete' => '0' ) )['autocomplete'] );
			}
		);
	}
);

describe(
	'Settings::sanitise() — the country restriction',
	function () {
		it(
			'keeps two-letter codes, lowercased and deduplicated, in the order they were typed',
			function () {
				assert_same( 'pl,de', slosm_sanitise( 'country', 'PL, de , pl' ) );
			}
		);

		it(
			'drops anything that is not a two-letter code',
			function () {
				assert_same( 'pl', slosm_sanitise( 'country', 'Polska, pl, 48, p' ) );

				// The control: the same call keeps the code beside them.
				assert_same( '', slosm_sanitise( 'country', 'Polska' ) );
			}
		);

		it(
			'allows no restriction at all',
			function () {
				assert_same( '', slosm_sanitise( 'country', '' ) );
				assert_same( '', slosm_sanitise( 'country', array( 'pl' ) ) );
			}
		);

		it(
			'stops at a sensible number of countries',
			function () {
				$typed = 'pl,de,fr,es,it,nl,be,cz,sk,at,hu,se,no,dk';
				$clean = slosm_sanitise( 'country', $typed );

				assert_same( Settings::MAX_COUNTRIES, count( explode( ',', $clean ) ) );
			}
		);
	}
);

describe(
	'Settings::sanitise() — the advanced tab',
	function () {
		it(
			'keeps an https endpoint',
			function () {
				assert_same(
					'https://nominatim.example.test/search',
					slosm_sanitise( 'geocode_endpoint', 'https://nominatim.example.test/search' )
				);
			}
		);

		it(
			'keeps an http endpoint, because a Nominatim on the same network has no certificate',
			function () {
				assert_same(
					'http://nominatim.internal:8080/search',
					slosm_sanitise( 'geocode_endpoint', 'http://nominatim.internal:8080/search' )
				);
			}
		);

		it(
			'refuses an endpoint on one of the other twenty protocols esc_url_raw() allows',
			function () {
				// Every one of these is on wp_allowed_protocols(), so a bare
				// esc_url_raw() hands all four back unchanged.
				foreach ( array( 'ftp', 'telnet', 'svn', 'webcal' ) as $scheme ) {
					assert_same(
						'',
						slosm_sanitise( 'geocode_endpoint', $scheme . '://geo.example.test/search' ),
						$scheme . ' should not be an endpoint'
					);
				}
			}
		);

		it(
			'refuses a javascript endpoint',
			function () {
				assert_same( '', slosm_sanitise( 'suggest_endpoint', 'javascript:alert(1)' ) );
			}
		);

		it(
			'allows an empty endpoint, which is what asks for the built-in one',
			function () {
				assert_same( '', slosm_sanitise( 'suggest_endpoint', '' ) );
			}
		);

		it(
			'refuses an endpoint posted as an array rather than reading the first of it',
			function () {
				// One text input cannot post an array, so this is an importer, a
				// WP-CLI call or a filter — and reading the first entry of one
				// would be inventing a choice between values that arrived
				// without an order. The control is every case above: a string is
				// taken.
				assert_same( '', slosm_sanitise( 'geocode_endpoint', array( 'https://a.test/search' ) ) );
				assert_same( '', slosm_sanitise( 'suggest_endpoint', array() ) );
			}
		);

		it(
			'takes a User-Agent and strips the line breaks that would make it a second header',
			function () {
				assert_same(
					'MyShop/1.0 X-Injected: yes',
					slosm_sanitise( 'geocode_user_agent', "MyShop/1.0\r\nX-Injected: yes" )
				);
			}
		);

		it(
			'cuts a User-Agent to a length a header can carry',
			function () {
				$clean = slosm_sanitise( 'geocode_user_agent', str_repeat( 'a', Settings::MAX_TEXT + 50 ) );

				assert_same( Settings::MAX_TEXT, strlen( $clean ) );
			}
		);

		it(
			'takes a cache lifetime in seconds, and zero for do not cache',
			function () {
				assert_same( 3600, slosm_sanitise( 'geocode_cache_ttl', '3600' ) );
				assert_same( 0, slosm_sanitise( 'geocode_cache_ttl', '0' ) );
				assert_same( 0, slosm_sanitise( 'suggest_cache_ttl', '-1' ) );
			}
		);

		it(
			'clamps a cache lifetime rather than accepting a number that overflows a transient',
			function () {
				assert_same( Settings::MAX_CACHE_TTL, slosm_sanitise( 'geocode_cache_ttl', '99999999999' ) );
			}
		);

		it(
			'takes the default cache lifetime when what arrived is not a number',
			function () {
				assert_same( Geocoder::CACHE_TTL, slosm_sanitise( 'geocode_cache_ttl', 'forever' ) );
			}
		);

		it(
			'reads the uninstall switch as the destructive thing it is: off unless it says on',
			function () {
				assert_true( slosm_sanitise( 'remove_data', '1' ) );
				assert_false( slosm_sanitise( 'remove_data', 'maybe' ) );
				assert_false( Settings::defaults()['remove_data'] );
			}
		);
	}
);

describe(
	'Settings::all() and ::get() — what the rest of the plugin reads',
	function () {
		it(
			'answers with the defaults on a site that has never saved anything',
			function () {
				assert_same( Settings::defaults(), Settings::all() );
				assert_same( 'km', Settings::all()['units'] );
			}
		);

		it(
			'answers with the stored value over the defaults',
			function () {
				$GLOBALS['slosm_stub']['options'][ Settings::OPTION ] = array( 'units' => 'mi' );

				assert_same( 'mi', Settings::get( 'units' ) );
				assert_same( Settings::defaults()['default_zoom'], Settings::get( 'default_zoom' ) );
			}
		);

		it(
			'answers with a sanitised value for an option somebody wrote round the back',
			function () {
				$GLOBALS['slosm_stub']['options'][ Settings::OPTION ] = array( 'tile_url' => 'https://tiles.example.test/one.png' );

				assert_same( Settings::defaults()['tile_url'], Settings::get( 'tile_url' ) );
			}
		);

		it(
			'answers with null for a setting that is not on the closed list',
			function () {
				assert_same( null, Settings::get( 'wp_capability' ) );

				// The control: a setting that is on the list answers with a value.
				assert_same( 'km', Settings::get( 'units' ) );
			}
		);

		it(
			'reads the option once per call rather than holding it, so a save takes effect at once',
			function () {
				assert_same( 'km', Settings::get( 'units' ) );

				$GLOBALS['slosm_stub']['options'][ Settings::OPTION ] = array( 'units' => 'mi' );

				assert_same( 'mi', Settings::get( 'units' ) );
			}
		);
	}
);

describe(
	'the button class, which is a list rather than a class',
	function () {

		it(
			'ships empty, because another theme’s button class is a decision about one site',
			function () {
				assert_same( '', Settings::defaults()['button_class'] );
			}
		);

		it(
			'keeps one class, and several however they were spaced',
			function () {
				assert_same( 'bricks-button', slosm_sanitise( 'button_class', 'bricks-button' ) );
				assert_same( 'btn btn-primary', slosm_sanitise( 'button_class', '  btn   btn-primary ' ) );
				assert_same( 'a b', slosm_sanitise( 'button_class', "a\n\tb" ) );
			}
		);

		it(
			'cleans each class rather than the whole string, which is the difference between a list and a class',
			function () {
				/*
				 * The trap this is written against, and the reason this is not
				 * one call to core's sanitize_html_class(): that function
				 * deletes a space along with everything else it refuses, so
				 * over "btn primary" it answers "btnprimary" — one class
				 * nobody has ever styled, silently. Splitting first is what
				 * makes this field a list.
				 */
				assert_same( 'btn primary', slosm_sanitise( 'button_class', 'btn primary' ) );

				// Nothing that could end the attribute survives, and the
				// remains of the attempt stay visible as classes rather than
				// being quietly dropped: a field that swallowed this would be
				// a field somebody keeps retyping.
				assert_same( 'safe onclickalert1', slosm_sanitise( 'button_class', 'safe" onclick="alert(1)' ) );
				assert_same( 'scriptalert1script', slosm_sanitise( 'button_class', '<script>alert(1)</script>' ) );

				// Percent-encoded characters go before the character class
				// does its work, which is core's own first step: without it
				// "%3Cscript%3E" would leave "3Cscript3E" behind.
				assert_same( 'script', slosm_sanitise( 'button_class', '%3Cscript%3E' ) );

				assert_same( '', slosm_sanitise( 'button_class', '!!! @@@' ) );
				assert_same( '', slosm_sanitise( 'button_class', array( 'bricks-button' ) ) );
				assert_same( '', slosm_sanitise( 'button_class', true ) );
			}
		);

		it(
			'drops whole classes at the cap rather than cutting one in half',
			function () {
				// A class attribute is not a place for a paragraph, and the cap
				// is the same MAX_TEXT the rest of the free-text fields use.
				// What matters is *where* it cuts: half a class name is a class
				// that matches nothing and reads in the page source like a
				// typo somebody made.
				$long  = str_repeat( 'abcdefghij ', 30 );
				$clean = slosm_sanitise( 'button_class', $long );

				assert_true( strlen( $clean ) <= Settings::MAX_TEXT, 'the class list is longer than the cap' );
				assert_true( strlen( $clean ) > 0, 'the whole list was thrown away instead of being cut' );

				foreach ( explode( ' ', $clean ) as $class ) {
					assert_same( 'abcdefghij', $class, 'a class was cut in half at the cap' );
				}

				// And it stops rather than packing shorter classes in behind the
				// one that did not fit. Both are defensible and the docblock says
				// so; this is which one was chosen, and it is here because a
				// decision nothing asserts is a decision the next edit reverses by
				// accident. The list of equal-length classes above cannot see the
				// difference, which is why this one is uneven.
				$mixed = slosm_sanitise( 'button_class', str_repeat( 'abcdefghij ', 19 ) . 'x' );

				assert_same( false, strpos( ' ' . $mixed . ' ', ' x ' ), 'a class after the cap was let through because it happened to fit' );
			}
		);

		it(
			'agrees with core’s own class rule wherever core has one to agree with',
			function () {
				/*
				 * The same arrangement as the colour case above, for the same
				 * reason: Settings::class_list() does not call
				 * sanitize_html_class(). The function is in wp-includes, which
				 * puts it on a front-end request on WordPress 6.9.1, and this
				 * plugin's header promises 6.0 — a version there is no copy of
				 * in this tree and no way to check without a network request
				 * this repository forbids. A fatal inside Settings::all() is a
				 * white page on every page carrying a map.
				 *
				 * So the rule is owned, and this is what stops the copy
				 * drifting. Every value in the table is one class with no
				 * whitespace in it, because that is the level at which the two
				 * rules are meant to be the same one. Whitespace is where they
				 * deliberately differ, and the assertion under the table says
				 * so rather than leaving it to be noticed.
				 */
				if ( function_exists( 'sanitize_html_class' ) ) {
					foreach (
						array(
							'bricks-button',
							'btn_primary',
							'Button2',
							'--custom',
							'',
							'quote"here',
							'<b>',
							'%3Cscript%3E',
							'%zz-kept',
							'ąćę',
							'emoji😀class',
						) as $value
					) {
						assert_same(
							sanitize_html_class( $value ),
							slosm_sanitise( 'button_class', $value ),
							'core and this plugin disagree about ' . var_export( $value, true )
						);
					}
				}

				// The one place the two are not the same rule, stated rather
				// than implied. Core answers 'btnprimary'; this answers two
				// classes, because the field holds a list.
				if ( function_exists( 'sanitize_html_class' ) ) {
					assert_same( 'btnprimary', sanitize_html_class( 'btn primary' ) );
				}

				// The control, which runs whether or not core's function is
				// there: the rule really is being applied.
				assert_same( 'kept-1', slosm_sanitise( 'button_class', ' kept-1 ' ) );
				assert_same( '', slosm_sanitise( 'button_class', '<<>>' ) );
			}
		);
	}
);
