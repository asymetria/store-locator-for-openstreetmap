<?php
/**
 * Proves every setting changes something, which is the whole of Task 21.
 *
 * A settings screen is easy to build and easy to lie with. The sanitiser can be
 * perfect, the four tabs can render, the option can save — and a value nothing
 * reads is worse than no setting at all: it is a promise on a screen that a site
 * owner will spend an afternoon believing. So there is a case here for every key
 * in the closed list, on the far side of the class that reads it.
 *
 * The cases are here rather than spread across the six files of the classes they
 * touch, and that is deliberate. The subject is not "does the shortcode clamp a
 * zoom" — tests/test-shortcode.php already owns that — but "does the setting
 * arrive", and a reader checking whether the closed list is honest should be
 * able to read one file and count.
 *
 * WHAT EACH CASE IS SHAPED LIKE
 * =============================
 * Stage the option, ask the consumer, assert the answer moved. Every one of them
 * also asserts the *unset* answer, because a case that only ever set a value
 * cannot tell "the setting is read" from "the value happens to be what the
 * constant already said". The defaults are deliberately the values this plugin
 * already behaved as, which makes that trap the normal case rather than an
 * unusual one.
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
require_once dirname( __DIR__ ) . '/admin/class-admin.php';
require_once dirname( __DIR__ ) . '/admin/class-locations-list.php';
require_once dirname( __DIR__ ) . '/admin/class-bulk-geocode.php';
require_once dirname( __DIR__ ) . '/admin/class-settings-screen.php';
require_once dirname( __DIR__ ) . '/admin/class-shortcode-generator.php';

use Asymetria\StoreLocator\Assets;
use Asymetria\StoreLocator\Geocoder;
use Asymetria\StoreLocator\Post_Type;
use Asymetria\StoreLocator\Rest_Controller;
use Asymetria\StoreLocator\Settings;
use Asymetria\StoreLocator\Shortcode;

if ( ! function_exists( 'slosm_effect_set' ) ) {
	/**
	 * Stages the settings option.
	 *
	 * @param array $settings Keys to set; everything else stays default.
	 * @return void
	 */
	function slosm_effect_set( array $settings ): void {
		$GLOBALS['slosm_stub']['options'][ Settings::OPTION ] = $settings;
	}
}

if ( ! function_exists( 'slosm_effect_config' ) ) {
	/**
	 * The config one locator would ship, with the settings as staged.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return array
	 */
	function slosm_effect_config( array $atts = array() ): array {
		$shortcode = new Shortcode();

		return $shortcode->config( $shortcode->attributes( $atts ) );
	}
}

if ( ! function_exists( 'slosm_effect_request' ) ) {
	/**
	 * A stand-in for WP_REST_Request, answering get_param() out of an array.
	 *
	 * The suite has no WP_REST_Request and the controller asks it for two
	 * parameters, so the smallest honest double is an object with get_param().
	 * tests/test-rest-controller.php builds the same shape.
	 *
	 * @param array $params Parameters.
	 * @return object
	 */
	function slosm_effect_request( array $params ): object {
		return new class( $params ) {
			/**
			 * The parameters.
			 *
			 * @var array
			 */
			private array $params;

			/**
			 * Constructor.
			 *
			 * @param array $params Parameters.
			 */
			public function __construct( array $params ) {
				$this->params = $params;
			}

			/**
			 * One parameter.
			 *
			 * @param string $name Name.
			 * @return mixed
			 */
			public function get_param( $name ) {
				return $this->params[ $name ] ?? null;
			}
		};
	}
}

describe(
	'the Map tab reaches the front end',
	function () {
		it(
			'ships the configured tile url, its attribution and its ceiling',
			function () {
				slosm_effect_set(
					array(
						'tile_url'             => 'https://tiles.example.test/{z}/{x}/{y}@2x.png',
						'tile_attribution'     => 'Tiles by Example',
						'tile_attribution_url' => 'https://example.test/licence',
						'tile_max_zoom'        => 17,
					)
				);

				$tile = slosm_effect_config()['tile'];

				assert_same( 'https://tiles.example.test/{z}/{x}/{y}@2x.png', $tile['url'] );
				assert_same( 17, $tile['maxZoom'] );
				assert_contains( 'href="https://example.test/licence"', $tile['attribution'] );
				assert_contains( '>Tiles by Example</a>', $tile['attribution'] );
			}
		);

		it(
			'ships the OpenStreetMap tile layer when nothing was configured',
			function () {
				$tile = slosm_effect_config()['tile'];

				assert_contains( 'https://tile.openstreetmap.org/', $tile['url'] );
				assert_contains( 'openstreetmap.org/copyright', $tile['attribution'] );
				assert_same( Shortcode::MAX_ZOOM, $tile['maxZoom'] );
			}
		);

		it(
			'cannot be made to put a tag in the attribution Leaflet writes with innerHTML',
			function () {
				slosm_effect_set(
					array(
						'tile_attribution'     => '<img src=x onerror=alert(1)> Tiles',
						'tile_attribution_url' => 'javascript:alert(1)',
					)
				);

				$attribution = slosm_effect_config()['tile']['attribution'];

				assert_same( 'Tiles', $attribution );
				assert_false( false !== strpos( $attribution, '<' ), $attribution );
				assert_false( false !== strpos( $attribution, 'javascript' ), $attribution );
			}
		);

		it(
			'caps a zoom that is above what the configured tile provider draws',
			function () {
				slosm_effect_set(
					array(
						'tile_max_zoom' => 15,
						'default_zoom'  => 19,
					)
				);

				// Both the default and a shortcode that asks for more: a map
				// opened at 19 on a provider that stops at 15 is a screen of
				// missing tiles, which reads as a broken plugin.
				assert_same( 15, slosm_effect_config()['zoom'] );
				assert_same( 15, slosm_effect_config( array( 'zoom' => '19' ) )['zoom'] );

				// And a zoom below the cap is left alone, which is the control.
				assert_same( 9, slosm_effect_config( array( 'zoom' => '9' ) )['zoom'] );
			}
		);

		it(
			'opens a map at the configured centre and zoom, and lets a shortcode overrule both',
			function () {
				slosm_effect_set(
					array(
						'default_lat'  => 52.2297,
						'default_lng'  => 21.0122,
						'default_zoom' => 14,
					)
				);

				$config = slosm_effect_config();

				assert_same( 52.2297, $config['lat'] );
				assert_same( 21.0122, $config['lng'] );
				assert_same( 14, $config['zoom'] );

				$named = slosm_effect_config(
					array(
						'lat'  => '50.0647',
						'lng'  => '19.9450',
						'zoom' => '11',
					)
				);

				assert_same( 50.0647, $named['lat'] );
				assert_same( 19.945, $named['lng'] );
				assert_same( 11, $named['zoom'] );
			}
		);

		it(
			'still frames the map around its locations when no centre was configured',
			function () {
				$config = slosm_effect_config();

				// Null and not 0,0. The Gulf of Guinea is the fabricated point
				// Store::has_coordinates() refuses to treat as a place, and the
				// front end reads null as "fit the world, then the results".
				assert_same( null, $config['lat'] );
				assert_same( null, $config['lng'] );
			}
		);

		it(
			'ships the configured height, and lets a shortcode overrule it',
			function () {
				slosm_effect_set( array( 'map_height' => 600 ) );

				assert_same( 600, slosm_effect_config()['height'] );
				assert_same( 320, slosm_effect_config( array( 'height' => '320' ) )['height'] );
				assert_contains( 'style="height:600px"', ( new Shortcode() )->render() );
			}
		);

		it(
			'ships the marker style and its colour, whichever style was chosen',
			function () {
				assert_same(
					array(
						'style'  => 'pin',
						'colour' => Settings::defaults()['marker_colour'],
					),
					slosm_effect_config()['marker']
				);

				slosm_effect_set(
					array(
						'marker_style'  => 'dot',
						'marker_colour' => '#c0392b',
					)
				);

				assert_same(
					array(
						'style'  => 'dot',
						'colour' => '#c0392b',
					),
					slosm_effect_config()['marker']
				);
			}
		);

		it(
			'lets the site decide clustering, and lets a shortcode overrule the site',
			function () {
				$GLOBALS['slosm_stub']['post_counts'][ Post_Type::POST_TYPE ] = array( 'publish' => 3 );

				// Three locations, so the count alone would never cluster.
				assert_false( slosm_effect_config()['cluster'] );

				slosm_effect_set( array( 'cluster' => 'yes' ) );
				assert_true( slosm_effect_config()['cluster'] );

				// And the shortcode still wins, in both directions.
				assert_false( slosm_effect_config( array( 'cluster' => 'no' ) )['cluster'] );

				slosm_effect_set( array( 'cluster' => 'no' ) );
				assert_true( slosm_effect_config( array( 'cluster' => 'yes' ) )['cluster'] );
			}
		);
	}
);

describe(
	'the Search tab reaches the front end',
	function () {
		it(
			'ships the configured units, and lets a shortcode overrule them',
			function () {
				assert_same( 'km', slosm_effect_config()['units'] );

				slosm_effect_set( array( 'units' => 'mi' ) );

				assert_same( 'mi', slosm_effect_config()['units'] );
				assert_same( 'km', slosm_effect_config( array( 'units' => 'km' ) )['units'] );
			}
		);

		it(
			'offers the configured radii in the control, and starts on the configured one',
			function () {
				slosm_effect_set(
					array(
						'radius_choices' => array( 3.0, 8.0, 20.0 ),
						'default_radius' => 8.0,
					)
				);

				$html = ( new Shortcode() )->render();

				assert_contains( '<option value="3">3 km</option>', $html );
				assert_contains( '<option value="8" selected>8 km</option>', $html );
				assert_contains( '<option value="20">20 km</option>', $html );

				// And the steps this plugin used to hard-code are gone, which is
				// the half a case asserting only the new ones would miss.
				assert_false( false !== strpos( $html, '>250 km<' ), 'an unconfigured radius is still offered' );

				assert_same( 8.0, slosm_effect_config()['radius'] );
			}
		);

		it(
			'offers the configured result counts, and starts on the configured one',
			function () {
				slosm_effect_set(
					array(
						'limit_choices' => array( 5, 15 ),
						'default_limit' => 15,
					)
				);

				$html = ( new Shortcode() )->render();

				assert_contains( '<option value="5">5 results</option>', $html );
				assert_contains( '<option value="15" selected>15 results</option>', $html );
				assert_false( false !== strpos( $html, '>500 results<' ) );

				assert_same( 15, slosm_effect_config()['limit'] );
			}
		);

		it(
			'ships whether addresses are suggested at all',
			function () {
				assert_true( slosm_effect_config()['autocomplete'] );

				slosm_effect_set( array( 'autocomplete' => false ) );

				assert_false( slosm_effect_config()['autocomplete'] );
			}
		);

		it(
			'ships whether "use my location" is offered, and prints the button to match',
			function () {
				assert_contains( 'slosm__locate', ( new Shortcode() )->render() );
				assert_true( slosm_effect_config()['nearMe'] );

				slosm_effect_set( array( 'near_me' => false ) );

				assert_false( false !== strpos( ( new Shortcode() )->render(), 'slosm__locate' ) );
				assert_false( slosm_effect_config()['nearMe'] );

				// A shortcode can still ask for it on one page.
				assert_true( slosm_effect_config( array( 'near_me' => 'yes' ) )['nearMe'] );
			}
		);
	}
);

describe(
	'the Results tab reaches the front end',
	function () {
		it(
			'puts the list where the site asked, and says nothing when it is where it already was',
			function () {
				// 'right' is the default and is what assets/css/locator.css does
				// with no modifier at all, so the class is absent rather than
				// spelled out — a site that changed nothing gets the markup it
				// had before this task.
				assert_contains( '<div class="slosm" data-slosm=', ( new Shortcode() )->render() );

				slosm_effect_set( array( 'results_position' => 'left' ) );
				assert_contains( '<div class="slosm slosm--list-left"', ( new Shortcode() )->render() );

				slosm_effect_set( array( 'results_position' => 'below' ) );
				assert_contains( '<div class="slosm slosm--list-below"', ( new Shortcode() )->render() );
			}
		);

		it(
			'ships the two field lists, in this plugin’s order rather than the chooser’s',
			function () {
				assert_same( Settings::RESULT_FIELDS, slosm_effect_config()['fields'] );
				assert_same( Settings::POPUP_FIELDS, slosm_effect_config()['popup'] );

				slosm_effect_set(
					array(
						'result_fields' => array( 'distance', 'name' ),
						'popup_fields'  => array( 'hours', 'name' ),
					)
				);

				$config = slosm_effect_config();

				assert_same( array( 'name', 'distance' ), $config['fields'] );
				assert_same( array( 'name', 'hours' ), $config['popup'] );
			}
		);

		it(
			'ships where the directions link goes',
			function () {
				assert_same( 'osm', slosm_effect_config()['directions'] );

				slosm_effect_set( array( 'directions' => 'google' ) );
				assert_same( 'google', slosm_effect_config()['directions'] );

				slosm_effect_set( array( 'directions' => 'none' ) );
				assert_same( 'none', slosm_effect_config()['directions'] );
			}
		);

		it(
			'still ships the row template with every field in it, whatever the site chose',
			function () {
				slosm_effect_set( array( 'result_fields' => array( 'name' ) ) );

				$html = ( new Shortcode() )->render();

				// The template is markup a full-page cache may hold for hours,
				// and the front end is what drops the fields — so a setting
				// changed after that page was cached still takes effect. A
				// server that pruned the template would make the setting depend
				// on somebody else's cache.
				assert_contains( 'slosm__result-city', $html );
				assert_contains( 'slosm__result-distance', $html );
			}
		);
	}
);

describe(
	'the Advanced tab reaches the geocoder',
	function () {
		before_each(
			function () {
				$GLOBALS['slosm_stub']['http_queue'][] = array(
					'response' => array( 'code' => 200 ),
					'body'     => '[]',
				);
			}
		);

		it(
			'asks the configured endpoint, with the configured User-Agent',
			function () {
				slosm_effect_set(
					array(
						'geocode_endpoint'   => 'https://nominatim.internal:8080/search',
						'geocode_user_agent' => 'Sklep/2.0 (+https://sklep.example.test)',
					)
				);

				( new Geocoder() )->geocode( 'Krucza 1, Warszawa' );

				$request = $GLOBALS['slosm_stub']['http_requests'][0];

				assert_contains( 'https://nominatim.internal:8080/search?', $request['url'] );
				assert_same( 'Sklep/2.0 (+https://sklep.example.test)', $request['args']['user-agent'] );
			}
		);

		it(
			'restricts a lookup to the configured countries, through the route as well as the admin',
			function () {
				slosm_effect_set( array( 'country' => 'pl,de' ) );

				$controller = new Rest_Controller();
				$controller->get_geocode( slosm_effect_request( array( 'q' => 'Krucza 1' ) ) );

				$request = $GLOBALS['slosm_stub']['http_requests'][0];

				assert_contains( 'countrycodes=pl%2Cde', $request['url'] );
			}
		);

		it(
			'lets a request name its own countries, which is what makes the setting a default',
			function () {
				slosm_effect_set( array( 'country' => 'pl' ) );

				$controller = new Rest_Controller();
				$controller->get_geocode(
					slosm_effect_request(
						array(
							'q'       => 'Krucza 1',
							'country' => 'de',
						)
					)
				);

				$request = $GLOBALS['slosm_stub']['http_requests'][0];

				assert_contains( 'countrycodes=de', $request['url'] );
				assert_false( false !== strpos( $request['url'], 'countrycodes=pl' ) );
			}
		);

		it(
			'narrows a suggestion as well as a lookup, which for Photon is the cache key',
			function () {
				slosm_effect_set( array( 'country' => 'pl' ) );

				$GLOBALS['slosm_stub']['http_queue'] = array(
					array(
						'response' => array( 'code' => 200 ),
						'body'     => '{"features":[{"geometry":{"coordinates":[21.0122,52.2297]},"properties":{"name":"Krucza"}}]}',
					),
				);

				$controller = new Rest_Controller();
				$controller->get_suggest( slosm_effect_request( array( 'q' => 'Kruc' ) ) );

				// Not the url. Geocoder::suggest() records why: Photon has no
				// country parameter at all — its filters are a bounding box and
				// a location bias — so the restriction goes into the key and not
				// into the request, and the answer is cached under the country
				// it was asked for. This case had to be corrected from asserting
				// countrycodes= in the url, which is Nominatim's parameter.
				$geocoder = new Geocoder();

				assert_true(
					array_key_exists(
						$geocoder->cache_key( Geocoder::SERVICE_PHOTON, 'Kruc', 'pl' ),
						$GLOBALS['slosm_stub']['transients']
					),
					'the suggestion was cached without the restriction it was made under'
				);
				assert_false(
					array_key_exists(
						$geocoder->cache_key( Geocoder::SERVICE_PHOTON, 'Kruc' ),
						$GLOBALS['slosm_stub']['transients']
					)
				);
			}
		);

		it(
			'asks for the whole world when nothing is configured and nothing was asked',
			function () {
				$controller = new Rest_Controller();
				$controller->get_geocode( slosm_effect_request( array( 'q' => 'Krucza 1' ) ) );

				assert_false(
					false !== strpos( $GLOBALS['slosm_stub']['http_requests'][0]['url'], 'countrycodes' ),
					'an unconfigured site restricted a lookup'
				);
			}
		);

		it(
			'restricts a save’s lookup too, and remembers its failure under the same key',
			function () {
				slosm_effect_set( array( 'country' => 'pl' ) );

				$GLOBALS['slosm_stub']['capabilities']['edit_post'] = true;
				$GLOBALS['slosm_stub']['posts_by_id'][4] = (object) array(
					'ID'         => 4,
					'post_type'  => Post_Type::POST_TYPE,
					'post_title' => 'Warszawa',
				);

				$GLOBALS['slosm_stub']['http_queue'] = array(
					array(
						'response' => array( 'code' => 200 ),
						'body'     => '[]',
					),
				);

				$_POST = array(
					\Asymetria\StoreLocator\Admin\Admin::NONCE_FIELD => 'nonce:' . \Asymetria\StoreLocator\Admin\Admin::NONCE_ACTION . '4',
					'slosm_address' => 'Krucza 1',
				);

				( new \Asymetria\StoreLocator\Admin\Admin() )->save( 4, $GLOBALS['slosm_stub']['posts_by_id'][4] );

				$_POST = array();

				assert_contains( 'countrycodes=pl', $GLOBALS['slosm_stub']['http_requests'][0]['url'] );

				assert_true(
					array_key_exists(
						\Asymetria\StoreLocator\Admin\Admin::failure_key( 'Krucza 1', 'pl' ),
						$GLOBALS['slosm_stub']['transients']
					),
					'the save remembered its failure under some other key'
				);
			}
		);

		it(
			'restricts a bulk run’s lookups too, and remembers a failure under the same key',
			function () {
				slosm_effect_set( array( 'country' => 'pl' ) );

				$GLOBALS['slosm_stub']['current_user_id'] = 1;
				$GLOBALS['slosm_stub']['capabilities']['edit_posts'] = true;
				$GLOBALS['slosm_stub']['capabilities']['edit_post'] = true;
				$_REQUEST['_wpnonce'] = 'nonce:bulk-posts';

				$GLOBALS['slosm_stub']['posts_by_id'][3] = (object) array(
					'ID'         => 3,
					'post_type'  => Post_Type::POST_TYPE,
					'post_title' => 'Warszawa',
				);
				update_post_meta( 3, '_slosm_address', 'Krucza 1' );

				$GLOBALS['slosm_stub']['http_queue'] = array(
					array(
						'response' => array( 'code' => 200 ),
						'body'     => '[]',
					),
				);

				$bulk = new \Asymetria\StoreLocator\Admin\Bulk_Geocode();
				$bulk->handle( 'https://example.test/wp-admin/edit.php', 'slosm_geocode', array( 3 ) );

				unset( $_REQUEST['_wpnonce'] );

				assert_contains( 'countrycodes=pl', $GLOBALS['slosm_stub']['http_requests'][0]['url'] );

				// And the failure it remembered is under the key a later save
				// with the same restriction will read — which is the whole of
				// why Admin::failure_key() grew a second argument.
				assert_true(
					array_key_exists(
						\Asymetria\StoreLocator\Admin\Admin::failure_key( 'Krucza 1', 'pl' ),
						$GLOBALS['slosm_stub']['transients']
					),
					'the run remembered its failure under some other key'
				);
			}
		);

		it(
			'keeps an answer for the configured lifetime',
			function () {
				slosm_effect_set( array( 'geocode_cache_ttl' => 3600 ) );

				$GLOBALS['slosm_stub']['http_queue'] = array(
					array(
						'response' => array( 'code' => 200 ),
						'body'     => '[{"lat":"52.2297","lon":"21.0122"}]',
					),
				);

				$geocoder = new Geocoder();
				$geocoder->geocode( 'Krucza 1, Warszawa' );

				$key = $geocoder->cache_key( Geocoder::SERVICE_NOMINATIM, 'Krucza 1, Warszawa' );

				assert_same( 3600, $GLOBALS['slosm_stub']['transients'][ $key ]['ttl'] );
			}
		);

		it(
			'caches nothing at all when the lifetime is zero',
			function () {
				slosm_effect_set( array( 'geocode_cache_ttl' => 0 ) );

				$GLOBALS['slosm_stub']['http_queue'] = array(
					array(
						'response' => array( 'code' => 200 ),
						'body'     => '[{"lat":"52.2297","lon":"21.0122"}]',
					),
				);

				$geocoder = new Geocoder();
				$geocoder->geocode( 'Krucza 1, Warszawa' );

				// array_key_exists, never get_transient: that returns false for
				// a miss and for a stored false alike, which is the one thing
				// tests/bootstrap.php's header says it will not pretend about.
				assert_false(
					array_key_exists(
						$geocoder->cache_key( Geocoder::SERVICE_NOMINATIM, 'Krucza 1, Warszawa' ),
						$GLOBALS['slosm_stub']['transients']
					)
				);
			}
		);

		it(
			'asks the configured suggestions endpoint, with its own lifetime',
			function () {
				slosm_effect_set(
					array(
						'suggest_endpoint'  => 'https://photon.internal/api',
						'suggest_cache_ttl' => 120,
					)
				);

				$GLOBALS['slosm_stub']['http_queue'] = array(
					array(
						'response' => array( 'code' => 200 ),
						'body'     => '{"features":[{"geometry":{"coordinates":[21.0122,52.2297]},"properties":{"name":"Krucza"}}]}',
					),
				);

				$geocoder = new Geocoder();
				$geocoder->suggest( 'Krucza' );

				$key = $geocoder->cache_key( Geocoder::SERVICE_PHOTON, 'Krucza' );

				assert_contains( 'https://photon.internal/api?', $GLOBALS['slosm_stub']['http_requests'][0]['url'] );
				assert_same( 120, $GLOBALS['slosm_stub']['transients'][ $key ]['ttl'] );
			}
		);
	}
);

describe(
	'the admin screens the settings changed',
	function () {
		it(
			'puts one stylesheet on the three screens that need it, and on no others',
			function () {
				$assets = new Assets();

				foreach (
					array(
						array( 'post.php', 'post', true ),
						array( 'post-new.php', 'post', true ),
						array( 'edit.php', 'edit', true ),
						array( 'slosm_store_page_slosm-settings', 'slosm_store_page_slosm-settings', true ),
						array( 'options-general.php', 'options-general', false ),
						array( 'index.php', 'dashboard', false ),
					) as $screen
				) {
					slosm_stub_reset();
					slosm_stub_screen( $screen[1], Post_Type::POST_TYPE );

					$assets->enqueue_admin( $screen[0] );

					assert_same(
						$screen[2],
						in_array( Assets::STYLE_ADMIN, $GLOBALS['slosm_stub']['style_queue'], true ),
						$screen[0] . ' got the stylesheet wrong'
					);
				}
			}
		);

		it(
			'does not put the picker on the list table or on the settings screen',
			function () {
				$assets = new Assets();

				foreach ( array( 'edit.php', 'slosm_store_page_slosm-settings' ) as $hook ) {
					slosm_stub_reset();
					slosm_stub_screen( 'edit', Post_Type::POST_TYPE );

					$assets->enqueue_admin( $hook );

					assert_false(
						in_array( Assets::SCRIPT_ADMIN, $GLOBALS['slosm_stub']['script_queue'], true ),
						$hook . ' got the map picker'
					);
					assert_false(
						in_array( Assets::STYLE_LEAFLET, $GLOBALS['slosm_stub']['style_queue'], true ),
						$hook . ' got Leaflet'
					);
				}
			}
		);

		it(
			'recognises the settings screen by the end of its hook suffix, whatever the parent menu is',
			function () {
				$assets = new Assets();

				// core builds the suffix as {page_type}_page_{slug} out of
				// $admin_page_hooks at runtime, so the beginning is not this
				// plugin's to predict and the slug is.
				foreach ( array( 'slosm_store_page_slosm-settings', 'settings_page_slosm-settings', 'toplevel_page_slosm-settings' ) as $hook ) {
					slosm_stub_reset();
					slosm_stub_screen( 'x', 'some_other_type' );

					$assets->enqueue_admin( $hook );

					assert_true(
						in_array( Assets::STYLE_ADMIN, $GLOBALS['slosm_stub']['style_queue'], true ),
						$hook . ' was not recognised'
					);
				}

				// And a page of somebody else's with a similar name is not ours.
				slosm_stub_reset();
				slosm_stub_screen( 'x', 'some_other_type' );
				$assets->enqueue_admin( 'toplevel_page_slosm-settings-pro' );

				assert_false( in_array( Assets::STYLE_ADMIN, $GLOBALS['slosm_stub']['style_queue'], true ) );
			}
		);

		it(
			'prints no style block on the location edit screen any more',
			function () {
				$GLOBALS['slosm_stub']['capabilities']['edit_post'] = true;
				$GLOBALS['slosm_stub']['posts_by_id'][7] = (object) array(
					'ID'         => 7,
					'post_type'  => Post_Type::POST_TYPE,
					'post_title' => 'Warszawa',
				);

				$admin = new \Asymetria\StoreLocator\Admin\Admin();

				ob_start();
				$admin->render( $GLOBALS['slosm_stub']['posts_by_id'][7] );
				$html = (string) ob_get_clean();

				assert_false( false !== strpos( $html, '<style' ), 'the metabox is still printing css' );

				// The control: the classes the stylesheet styles are still on the
				// markup, so this is not passing because the box stopped
				// rendering.
				assert_contains( 'slosm-metabox__heading', $html );
			}
		);
	}
);
