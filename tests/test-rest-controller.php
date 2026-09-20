<?php
/**
 * Pins the four public routes: their shape, their clamps and what they refuse
 * to say.
 *
 * The callbacks are called directly. Nothing here goes through WP_REST_Server,
 * so nothing here validates or sanitises a parameter — in production
 * has_valid_params() and then sanitize_params() run against the args schema
 * before a callback is reached, verified in WordPress 6.9.1's
 * WP_REST_Server::respond_to_request(). That has one consequence this file has
 * to keep saying out loud: handing a callback a radius of one million proves
 * nothing about what a visitor can send, because a visitor's radius never gets
 * that far. So the clamping cases assert the *declared schema*, which is what
 * WordPress enforces, and the callback cases assert what the callback does with
 * values that already passed it.
 *
 * Fakes
 * -----
 * Store_Repository and Geocoder are both final, so there are no hand-written
 * doubles here and that is the better outcome rather than a workaround. A
 * hand-written repository would return whatever Stores a test built, which
 * means it could not reproduce the one trap this controller exists to avoid:
 * find_all() and find_near() return *partial* Stores, ten of whose seventeen
 * fields are empty strings, and such a Store answers to_full_array() with a
 * complete-looking record. Only a real Store_Repository over a fake loader
 * produces that. The same goes for the geocoder: the seven error codes per
 * method are raised in six different places, and a fake returning
 * new WP_Error( 'slosm_geocode_http' ) would agree with a mapping that read the
 * wrong prefix.
 *
 * So the seams used are the ones those classes already have — a loader and a
 * bounded loader, a clock and a sleeper — plus the queued-response stub behind
 * wp_remote_get(), which throws when nothing is queued. That throw is what
 * makes "no HTTP call was made" provable rather than assumed.
 *
 * @package Store_Locator_For_OpenStreetMap
 */

require_once dirname( __DIR__ ) . '/includes/class-geo.php';
require_once dirname( __DIR__ ) . '/includes/class-store.php';
require_once dirname( __DIR__ ) . '/includes/class-post-type.php';
require_once dirname( __DIR__ ) . '/includes/class-store-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-geocoder.php';
require_once dirname( __DIR__ ) . '/includes/class-rest-controller.php';
require_once dirname( __DIR__ ) . '/includes/class-settings.php';
require_once dirname( __DIR__ ) . '/includes/class-plugin.php';
require_once dirname( __DIR__ ) . '/admin/class-settings-screen.php';
require_once dirname( __DIR__ ) . '/admin/class-shortcode-generator.php';
// Plugin::boot() constructs an Admin for the location metabox, and this suite
// runs without the autoloader.
require_once dirname( __DIR__ ) . '/admin/class-admin.php';
require_once dirname( __DIR__ ) . '/admin/class-locations-list.php';
require_once dirname( __DIR__ ) . '/admin/class-bulk-geocode.php';

use Asymetria\StoreLocator\Geocoder;
use Asymetria\StoreLocator\Plugin;
use Asymetria\StoreLocator\Rest_Controller;
use Asymetria\StoreLocator\Store_Repository;

if ( ! function_exists( 'slosm_rest_row' ) ) {
	/**
	 * One row shaped the way get_posts() and get_post() hand them over.
	 *
	 * post_type and post_status are set to what a published location has,
	 * because find_by_id() checks both and a row missing either is indistinct
	 * from "no such location".
	 *
	 * @param int    $id      Post id.
	 * @param string $title   Post title.
	 * @param string $content Post content.
	 * @return object
	 */
	function slosm_rest_row( int $id, string $title = '', string $content = '' ): object {
		return (object) array(
			'ID'           => $id,
			'post_title'   => $title,
			'post_content' => $content,
			'post_type'    => 'slosm_store',
			'post_status'  => 'publish',
		);
	}
}

if ( ! function_exists( 'slosm_rest_seed' ) ) {
	/**
	 * Stages one whole location: its row, its meta and its categories.
	 *
	 * Meta keys are written out by the caller, never derived from
	 * Store_Repository::META_KEYS, so a typo in that table cannot make a fixture
	 * agree with it.
	 *
	 * @param object   $row        Row, as get_posts() hands it over.
	 * @param array    $meta       Meta keys to values.
	 * @param string[] $categories Term names.
	 * @return void
	 */
	function slosm_rest_seed( object $row, array $meta, array $categories = array() ): void {
		$id = (int) $row->ID;

		$GLOBALS['slosm_stub']['posts'][]             = $row;
		$GLOBALS['slosm_stub']['posts_by_id'][ $id ] = $row;
		$GLOBALS['slosm_stub']['object_terms'][ $id ] = $categories;

		foreach ( $meta as $key => $value ) {
			update_post_meta( $id, $key, $value );
		}
	}
}

if ( ! function_exists( 'slosm_rest_warsaw' ) ) {
	/**
	 * The fixture location every case builds on: one whole record, every field
	 * filled, including the ten the lean payload does not carry.
	 *
	 * Every field being non-empty is what makes the partial-Store case provable.
	 * A fixture with a blank phone number would make "the response does not
	 * carry a blank phone number" true for the wrong reason.
	 *
	 * @return void
	 */
	function slosm_rest_warsaw(): void {
		slosm_rest_seed(
			slosm_rest_row( 7, 'Kawiarnia Nowy Świat', 'Kawa i ciastka.' ),
			array(
				'_slosm_address'    => 'Nowy Świat 1',
				'_slosm_address2'   => 'lokal 3',
				'_slosm_city'       => 'Warszawa',
				'_slosm_state'      => 'mazowieckie',
				'_slosm_zip'        => '00-001',
				'_slosm_country'    => 'Polska',
				'_slosm_lat'        => '52.2297',
				'_slosm_lng'        => '21.0122',
				'_slosm_lat_locked' => '1',
				'_slosm_phone'      => '+48 22 000 00 00',
				'_slosm_email'      => 'kawa@example.test',
				'_slosm_url'        => 'https://example.test/kawiarnia',
				'_slosm_hours'      => "Mon 9-17\nTue 9-17",
			),
			array( 'Kawiarnia' )
		);
	}
}

if ( ! function_exists( 'slosm_rest_krakow' ) ) {
	/**
	 * A second location, far enough away to fall outside a small radius.
	 *
	 * @return void
	 */
	function slosm_rest_krakow(): void {
		slosm_rest_seed(
			slosm_rest_row( 8, 'Kawiarnia Rynek' ),
			array(
				'_slosm_address' => 'Rynek Główny 1',
				'_slosm_city'    => 'Kraków',
				'_slosm_lat'     => '50.0647',
				'_slosm_lng'     => '19.9450',
			),
			array( 'Piekarnia' )
		);
	}
}

if ( ! function_exists( 'slosm_rest_seed_many' ) ) {
	/**
	 * Stages enough locations to see a limit bite.
	 *
	 * Ids start well above the hand-written fixtures so the two cannot collide.
	 * Coordinates are the same for all of them — a limit is about how many come
	 * back, not about where they are — and they are only there because
	 * build_payload() drops a location that cannot be placed on a map.
	 *
	 * @param int $count How many locations to stage.
	 * @return void
	 */
	function slosm_rest_seed_many( int $count ): void {
		for ( $i = 0; $i < $count; $i++ ) {
			slosm_rest_seed(
				slosm_rest_row( 1000 + $i, 'Oddział ' . $i ),
				array(
					'_slosm_lat' => '52.2297',
					'_slosm_lng' => '21.0122',
				)
			);
		}
	}
}

if ( ! function_exists( 'slosm_rest_repository' ) ) {
	/**
	 * A repository over the rows a case staged.
	 *
	 * Both loaders answer with the same rows: which one runs depends on whether
	 * a payload happens to be cached, and a fixture that differed between them
	 * would make a case's result depend on cache state rather than on the
	 * controller.
	 *
	 * @return Store_Repository
	 */
	function slosm_rest_repository(): Store_Repository {
		$rows = static function (): array {
			return $GLOBALS['slosm_stub']['posts'];
		};

		return new Store_Repository(
			$rows,
			static function ( $box ) use ( $rows ): array {
				return $rows();
			}
		);
	}
}

if ( ! function_exists( 'slosm_rest_geocoder' ) ) {
	/**
	 * A geocoder on the stub clock and the recording sleeper.
	 *
	 * The sleeper matters: the real one is usleep(), so a case that reached the
	 * limiter would block this suite for a second instead of recording that it
	 * was asked to.
	 *
	 * @return Geocoder
	 */
	function slosm_rest_geocoder(): Geocoder {
		return new Geocoder( 'slosm_stub_time', 'slosm_stub_sleep' );
	}
}

if ( ! function_exists( 'slosm_rest_controller' ) ) {
	/**
	 * A controller with its routes already registered.
	 *
	 * Registering here rather than per case is what lets slosm_rest_request()
	 * read the declared defaults back off the stub, the way the server reads
	 * them off the handler.
	 *
	 * @param Store_Repository|null $repository Repository; built over the staged rows when omitted.
	 * @param Geocoder|null         $geocoder   Geocoder; built on the stub clock when omitted.
	 * @return Rest_Controller
	 */
	function slosm_rest_controller( ?Store_Repository $repository = null, ?Geocoder $geocoder = null ): Rest_Controller {
		// No clock: the controller has none. The one subtraction it used to do
		// itself is Geocoder::would_throttle(), which measures on the geocoder's
		// own seam — so a case stages contention by moving that clock, not a
		// second one.
		$controller = new Rest_Controller(
			null !== $repository ? $repository : slosm_rest_repository(),
			null !== $geocoder ? $geocoder : slosm_rest_geocoder()
		);

		$controller->register_routes();

		return $controller;
	}
}

if ( ! function_exists( 'slosm_rest_endpoint' ) ) {
	/**
	 * The one endpoint definition registered for a route pattern.
	 *
	 * @param string $pattern Route pattern, exactly as registered.
	 * @return array The endpoint definition, or an empty array when there is none.
	 */
	function slosm_rest_endpoint( string $pattern ): array {
		foreach ( $GLOBALS['slosm_stub']['rest_routes'] as $route ) {
			if ( $pattern === $route['route'] ) {
				return $route['args'][0] ?? array();
			}
		}

		return array();
	}
}

if ( ! function_exists( 'slosm_rest_args' ) ) {
	/**
	 * The args schema declared for a route pattern.
	 *
	 * @param string $pattern Route pattern.
	 * @return array
	 */
	function slosm_rest_args( string $pattern ): array {
		return slosm_rest_endpoint( $pattern )['args'] ?? array();
	}
}

if ( ! function_exists( 'slosm_rest_schema' ) ) {
	/**
	 * The schema callback declared for a route pattern.
	 *
	 * It sits on the route rather than on the endpoint — register_rest_route()
	 * takes a list of endpoint definitions with 'schema' alongside them — which
	 * is a distinction worth keeping in the helper rather than in each case.
	 *
	 * @param string $pattern Route pattern.
	 * @return mixed The callback, or null when the route declares none.
	 */
	function slosm_rest_schema( string $pattern ) {
		foreach ( $GLOBALS['slosm_stub']['rest_routes'] as $route ) {
			if ( $pattern === $route['route'] ) {
				return $route['args']['schema'] ?? null;
			}
		}

		return null;
	}
}

if ( ! function_exists( 'slosm_rest_request' ) ) {
	/**
	 * A request carrying a case's parameters over the route's declared defaults.
	 *
	 * This is WP_REST_Server::match_request_to_handler() copied down to the one
	 * thing a callback can see: every arg with a 'default' is put on the request
	 * before the callback runs, and a real parameter wins over it. Verified
	 * against WordPress 6.9.1. Writing the defaults out in each case instead
	 * would let a declared default be deleted with every case still green.
	 *
	 * @param string $pattern Route pattern the request is for.
	 * @param array  $params  Parameters as a caller sent them.
	 * @return WP_REST_Request
	 */
	function slosm_rest_request( string $pattern, array $params = array() ): WP_REST_Request {
		$request  = new WP_REST_Request( $params );
		$defaults = array();

		foreach ( slosm_rest_args( $pattern ) as $name => $options ) {
			if ( isset( $options['default'] ) ) {
				$defaults[ $name ] = $options['default'];
			}
		}

		$request->set_default_params( $defaults );

		return $request;
	}
}

if ( ! function_exists( 'slosm_rest_queue' ) ) {
	/**
	 * Queues one response for the next wp_remote_get().
	 *
	 * @param int    $code HTTP status code.
	 * @param string $body Response body.
	 * @return void
	 */
	function slosm_rest_queue( int $code, string $body ): void {
		$GLOBALS['slosm_stub']['http_queue'][] = array(
			'response' => array( 'code' => $code ),
			'body'     => $body,
		);
	}
}

if ( ! function_exists( 'slosm_rest_requests' ) ) {
	/**
	 * How many requests actually went out.
	 *
	 * @return int
	 */
	function slosm_rest_requests(): int {
		return count( $GLOBALS['slosm_stub']['http_requests'] );
	}
}

if ( ! function_exists( 'slosm_rest_busy' ) ) {
	/**
	 * Stages a service as having been asked a moment ago.
	 *
	 * The option is the one the limiter itself reads, named through the
	 * geocoder rather than rebuilt here, so a case cannot agree with a
	 * misspelling.
	 *
	 * @param Geocoder $geocoder Geocoder whose option to write.
	 * @param string   $service  Service name.
	 * @param float    $ago      Seconds ago the service was asked.
	 * @return void
	 */
	function slosm_rest_busy( Geocoder $geocoder, string $service, float $ago ): void {
		update_option( $geocoder->last_request_option( $service ), slosm_stub_time() - $ago, false );
	}
}

if ( ! function_exists( 'slosm_rest_status' ) ) {
	/**
	 * The status a WP_Error carries, or 0 when it carries none.
	 *
	 * @param mixed $error Value returned by a callback.
	 * @return int
	 */
	function slosm_rest_status( $error ): int {
		if ( ! is_wp_error( $error ) ) {
			return 0;
		}

		$data = $error->get_error_data();

		return is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;
	}
}

describe(
	'rest controller routes',
	function () {

		it(
			'registers the four public routes under slosm/v1',
			function () {
				slosm_rest_controller();

				$routes = array();

				foreach ( $GLOBALS['slosm_stub']['rest_routes'] as $route ) {
					assert_same( 'slosm/v1', $route['namespace'] );

					$routes[] = $route['route'];
				}

				assert_same(
					array( '/stores', '/stores/(?P<id>\d+)', '/geocode', '/suggest' ),
					$routes
				);
			}
		);

		it(
			'registers routes only when asked, and hooks nothing itself',
			function () {
				// The controller does not hook itself; Plugin does. What this
				// pins is that register_routes() is the whole of the surface, so
				// a route cannot appear before rest_api_init has fired.
				slosm_rest_controller();

				assert_same( 4, count( $GLOBALS['slosm_stub']['rest_routes'] ) );
				assert_same( array(), $GLOBALS['slosm_stub']['actions'] );
			}
		);

		it(
			'makes every route read-only and open to anyone',
			function () {
				slosm_rest_controller();

				// The control. Every assertion below is inside a loop, and a
				// loop over nothing asserts nothing: without this line a
				// controller that registered no routes at all would pass.
				assert_same( 4, count( $GLOBALS['slosm_stub']['rest_routes'] ) );

				foreach ( $GLOBALS['slosm_stub']['rest_routes'] as $route ) {
					$endpoint = $route['args'][0];

					assert_same( 'GET', $endpoint['methods'] );
					assert_true( is_callable( $endpoint['permission_callback'] ) );
					assert_true( call_user_func( $endpoint['permission_callback'] ) );
				}
			}
		);

		it(
			'declares a type, a sanitiser and a validator for every argument',
			function () {
				slosm_rest_controller();

				$checked = 0;

				foreach ( $GLOBALS['slosm_stub']['rest_routes'] as $route ) {
					foreach ( $route['args'][0]['args'] as $name => $options ) {
						assert_true( isset( $options['type'] ), $name . ' declares no type' );
						assert_true( isset( $options['sanitize_callback'] ), $name . ' declares no sanitize_callback' );
						assert_true( isset( $options['validate_callback'] ), $name . ' declares no validate_callback' );

						++$checked;
					}
				}

				// Without this, a controller that declared no arguments at all
				// would satisfy every assertion above by never running one.
				// Six on /stores, one on /stores/<id>, two on each lookup.
				assert_same( 11, $checked );
			}
		);

		it(
			'clamps radius and limit in the schema, where WordPress does the rejecting',
			function () {
				slosm_rest_controller();

				$args = slosm_rest_args( '/stores' );

				assert_same( 'number', $args['radius']['type'] );
				assert_same( 0.0, $args['radius']['minimum'] );
				assert_same( Rest_Controller::MAX_RADIUS, $args['radius']['maximum'] );

				assert_same( 'integer', $args['limit']['type'] );
				assert_same( 1, $args['limit']['minimum'] );
				assert_same( Rest_Controller::MAX_LIMIT, $args['limit']['maximum'] );

				// rest_validate_request_arg() is what applies minimum and
				// maximum; a bespoke validator here would silently drop both.
				assert_same( 'rest_validate_request_arg', $args['radius']['validate_callback'] );
				assert_same( 'rest_validate_request_arg', $args['limit']['validate_callback'] );

				// Coordinates are clamped to the earth for the same reason.
				assert_same( -90.0, $args['lat']['minimum'] );
				assert_same( 90.0, $args['lat']['maximum'] );
				assert_same( -180.0, $args['lng']['minimum'] );
				assert_same( 180.0, $args['lng']['maximum'] );

				assert_same( array( 'km', 'mi' ), $args['unit']['enum'] );
			}
		);

		it(
			'requires a non-empty q on the two proxied routes',
			function () {
				slosm_rest_controller();

				foreach ( array( '/geocode', '/suggest' ) as $pattern ) {
					$args = slosm_rest_args( $pattern );

					assert_true( $args['q']['required'], $pattern . ' does not require q' );
					assert_same( 1, $args['q']['minLength'], $pattern . ' does not refuse an empty q' );
					assert_same( 'rest_validate_request_arg', $args['q']['validate_callback'] );

					// The other end of the same bound, and the one that is about
					// the site rather than about the caller: the query goes into
					// a url and into a cache key, and an unbounded one is an
					// unbounded request made in the site's name to a volunteer
					// service. It sits one line from minLength and was the
					// easier of the two to delete unnoticed.
					assert_same( 200, $args['q']['maxLength'], $pattern . ' takes a q of any length' );
					assert_same( 64, $args['country']['maxLength'], $pattern . ' takes a country of any length' );
				}
			}
		);

		it(
			'declares a response schema for all four routes, so the index can describe them',
			function () {
				slosm_rest_controller();

				$schemas = array();

				foreach ( array( '/stores', '/stores/(?P<id>\d+)', '/geocode', '/suggest' ) as $pattern ) {
					$callback = slosm_rest_schema( $pattern );

					assert_true( is_callable( $callback ), $pattern . ' declares no schema' );

					$schema = call_user_func( $callback );

					assert_true( isset( $schema['title'] ), $pattern . ' has a schema with no title' );

					$schemas[] = $schema['title'];
				}

				// Four distinct shapes, not one schema declared four times.
				assert_same(
					array( 'slosm_stores', 'slosm_store', 'slosm_point', 'slosm_suggestions' ),
					$schemas
				);
			}
		);

		it(
			'types a geocoded point as a pair of plain numbers, not nullable ones',
			function () {
				$controller = slosm_rest_controller();
				$point      = $controller->get_point_schema();

				// A location can be unplaced; a geocode result cannot. The
				// geocoder raises bad_coordinates rather than returning a
				// success with nulls in it, so nullable here would describe a
				// response this route never sends.
				assert_same( 'number', $point['properties']['lat']['type'] );
				assert_same( 'number', $point['properties']['lng']['type'] );
				assert_same( 'string', $point['properties']['label']['type'] );

				// The control: the location schema, which really is nullable,
				// so the two assertions above are a contrast and not a habit.
				assert_same(
					array( 'number', 'null' ),
					$controller->get_item_schema()['properties']['lat']['type']
				);

				$suggestions = $controller->get_suggestions_schema();

				assert_same( 'array', $suggestions['type'] );
				assert_same(
					array( 'label', 'lat', 'lng' ),
					array_keys( $suggestions['items']['properties'] )
				);
			}
		);

		it(
			'types a coordinate in the item schema as nullable, because an unplaced location has none',
			function () {
				$controller = slosm_rest_controller();
				$schema     = $controller->get_item_schema();

				assert_same( array( 'number', 'null' ), $schema['properties']['lat']['type'] );
				assert_same( array( 'number', 'null' ), $schema['properties']['lng']['type'] );

				// The control: a plain 'number' somewhere else in the same
				// schema, so the two assertions above cannot be passing because
				// every type in it happens to be a list.
				assert_same( 'integer', $schema['properties']['id']['type'] );

				// And the schema is the one the route actually declares.
				assert_same( $schema, call_user_func( slosm_rest_schema( '/stores/(?P<id>\d+)' ) ) );

				// lat_locked is not in it, because get_store() does not send it.
				assert_false( isset( $schema['properties']['lat_locked'] ) );

				// The list has a schema of its own, and it is the list shape
				// rather than the record: seven fields and a distance.
				$collection = call_user_func( slosm_rest_schema( '/stores' ) );

				assert_same( 'array', $collection['type'] );
				assert_same(
					array( 'id', 'name', 'lat', 'lng', 'address', 'city', 'categories', 'distance' ),
					array_keys( $collection['items']['properties'] )
				);
				assert_same( array( 'number', 'null' ), $collection['items']['properties']['lat']['type'] );
				assert_same( array( 'number', 'null' ), $collection['items']['properties']['distance']['type'] );
			}
		);
	}
);

describe(
	'rest controller store list',
	function () {

		before_each( 'slosm_rest_warsaw' );

		it(
			'builds the list from the lean payload, so a full-only field cannot come back blank',
			function () {
				$repository = slosm_rest_repository();
				$controller = slosm_rest_controller( $repository );

				$response = $controller->get_stores( slosm_rest_request( '/stores' ) );
				$items    = $response->get_data();

				assert_same( 1, count( $items ) );
				assert_same(
					array( 'id', 'name', 'lat', 'lng', 'address', 'city', 'categories', 'distance' ),
					array_keys( $items[0] )
				);

				// The control. Without it "the response has no phone number" is
				// true of a fixture that never had one, and of a controller that
				// returns nothing at all. The record does have one, and the
				// partial Store this list was built from would have reported it
				// as '' through to_full_array().
				$whole = $repository->find_by_id( 7 );

				assert_same( '+48 22 000 00 00', $whole->phone );
				assert_same( "Mon 9-17\nTue 9-17", $whole->hours );

				// And the seven lean fields are the real values, not empties, so
				// the shape above is a projection rather than a blank row.
				assert_same( 7, $items[0]['id'] );
				assert_same( 'Kawiarnia Nowy Świat', $items[0]['name'] );
				assert_same( 'Nowy Świat 1', $items[0]['address'] );
				assert_same( 'Warszawa', $items[0]['city'] );
				assert_same( array( 'Kawiarnia' ), $items[0]['categories'] );
				assert_same( 52.2297, $items[0]['lat'] );
			}
		);

		it(
			'carries no distance when nobody searched from anywhere',
			function () {
				$response = slosm_rest_controller()->get_stores( slosm_rest_request( '/stores' ) );
				$items    = $response->get_data();

				assert_same( null, $items[0]['distance'] );
			}
		);

		it(
			'reads the distance off the object, which neither array shape carries',
			function () {
				slosm_rest_krakow();

				$response = slosm_rest_controller()->get_stores(
					slosm_rest_request(
						'/stores',
						array(
							'lat'    => 52.2297,
							'lng'    => 21.0122,
							'radius' => 300.0,
						)
					)
				);

				$items = $response->get_data();

				assert_same( 2, count( $items ) );

				// Nearest first, and the distance is a real measurement rather
				// than a key that happens to exist.
				assert_same( 7, $items[0]['id'] );
				assert_close( 0.0, (float) $items[0]['distance'], 0.001 );

				assert_same( 8, $items[1]['id'] );
				assert_close( 252.0, (float) $items[1]['distance'], 5.0 );

				// The control for "distance is not in to_lean_array()": the same
				// list without coordinates carries null, so a distance here can
				// only have come off the object.
				$plain = slosm_rest_controller()->get_stores( slosm_rest_request( '/stores' ) )->get_data();

				assert_same( null, $plain[0]['distance'] );
			}
		);

		it(
			'measures in the unit the request asked for',
			function () {
				slosm_rest_krakow();

				$miles = slosm_rest_controller()->get_stores(
					slosm_rest_request(
						'/stores',
						array(
							'lat'    => 52.2297,
							'lng'    => 21.0122,
							'radius' => 300.0,
							'unit'   => 'mi',
						)
					)
				)->get_data();

				assert_close( 156.6, (float) $miles[1]['distance'], 2.0 );
			}
		);

		it(
			'takes the declared default unit when the request names none',
			function () {
				slosm_rest_krakow();

				// The controller first: slosm_rest_request() reads the declared
				// defaults back off the routes, so there have to be routes.
				$controller = slosm_rest_controller();

				// slosm_rest_request() copies the schema's defaults onto the
				// request exactly as the server does, so this case fails if the
				// 'default' is deleted from the schema *or* if the callback stops
				// reading it.
				$request = slosm_rest_request(
					'/stores',
					array(
						'lat'    => 52.2297,
						'lng'    => 21.0122,
						'radius' => 300.0,
					)
				);

				assert_same( 'km', $request->get_param( 'unit' ) );

				$items = $controller->get_stores( $request )->get_data();

				assert_close( 252.0, (float) $items[1]['distance'], 5.0 );
			}
		);

		it(
			'filters by category name, and filters before it limits',
			function () {
				slosm_rest_krakow();

				$all = slosm_rest_controller()->get_stores( slosm_rest_request( '/stores' ) )->get_data();

				// The control: both locations are there to be filtered out.
				assert_same( 2, count( $all ) );

				$filtered = slosm_rest_controller()->get_stores(
					slosm_rest_request( '/stores', array( 'category' => 'Piekarnia' ) )
				)->get_data();

				assert_same( 1, count( $filtered ) );
				assert_same( 8, $filtered[0]['id'] );

				// Limit one, and the category is not the first location in the
				// list: a limit applied before the filter would return nothing.
				//
				// On the plain list this is true by construction — the limit
				// counts what has been emitted, and a filtered-out location
				// emits nothing — so the case that can actually break is the
				// proximity one below, where the limit could be handed to
				// find_near() and cut the list before this loop ever sees it.
				$both = slosm_rest_controller()->get_stores(
					slosm_rest_request(
						'/stores',
						array(
							'category' => 'Piekarnia',
							'limit'    => 1,
						)
					)
				)->get_data();

				assert_same( 1, count( $both ) );
				assert_same( 8, $both[0]['id'] );

				// The proximity path, which is where the order is a real
				// decision. Kraków is the farther of the two and the only
				// Piekarnia, so a limit of one passed down to find_near() would
				// cut the list to Warszawa before the filter ran and answer an
				// empty list for a category that has a match.
				$near = slosm_rest_controller()->get_stores(
					slosm_rest_request(
						'/stores',
						array(
							'lat'      => 52.2297,
							'lng'      => 21.0122,
							'radius'   => 300.0,
							'category' => 'Piekarnia',
							'limit'    => 1,
						)
					)
				)->get_data();

				assert_same( 1, count( $near ) );
				assert_same( 8, $near[0]['id'] );

				// A whole name, not a prefix. A prefix match would make
				// category=K return every category beginning with a K, and the
				// front end's filter is built from whole names anyway.
				$prefix = slosm_rest_controller()->get_stores(
					slosm_rest_request( '/stores', array( 'category' => 'Piek' ) )
				)->get_data();

				assert_same( array(), $prefix );

				// Case, on the other hand, is not significant: a filter chip and
				// a hand-typed url should not disagree over one capital.
				$lower = slosm_rest_controller()->get_stores(
					slosm_rest_request( '/stores', array( 'category' => 'piekarnia' ) )
				)->get_data();

				assert_same( 1, count( $lower ) );
				assert_same( 8, $lower[0]['id'] );
			}
		);

		it(
			'folds a category name the way the browser folds it',
			function () {
				// Two implementations of one comparison, and they have to agree
				// for the same reason Geo and its JavaScript copy do: below the
				// preload threshold the browser filters a list it already has,
				// above it this method filters and the browser does not. A site
				// that showed two different maps either side of a number no
				// visitor can see is the exact failure load()'s docblock says
				// the local radius filter exists to prevent.
				//
				// strcasecmp(), which this used until Task 15, folds ASCII bytes
				// and nothing else. JavaScript's toLowerCase() folds the whole of
				// Unicode. They agreed on 'Piekarnia' and disagreed on every
				// alphabet this plugin was written for.
				slosm_rest_seed(
					slosm_rest_row( 41, 'Żłobek Wola' ),
					array(
						'_slosm_lat' => '52.2297',
						'_slosm_lng' => '21.0122',
					),
					array( 'Żłobki' )
				);

				// Both fixtures before the first request, because the payload is
				// built once and cached: a location seeded after it has been
				// built is a location the filter never sees.
				slosm_rest_krakow();

				$asked = static function ( string $category ): array {
					return slosm_rest_controller()->get_stores(
						slosm_rest_request( '/stores', array( 'category' => $category ) )
					)->get_data();
				};

				// The control: spelled as it is stored, it matches on every
				// host, so an empty answer below is about the fold rather than
				// about a fixture that never had a category on it.
				assert_same( 1, count( $asked( 'Żłobki' ) ) );

				// And an ASCII pair, which both folds have always agreed on.
				// Without this, a fold that had stopped folding altogether —
				// a plain === — would satisfy the fallback branch below and
				// look like a host without mbstring.
				assert_same( 1, count( $asked( 'PIEKARNIA' ) ), 'the filter no longer folds case at all' );

				/*
				 * The pair the two folds used to disagree about, asserted
				 * against the host rather than against a constant — because the
				 * answer really is a fact about the host, and a case that
				 * pretended otherwise would be wrong on one kind of machine.
				 *
				 * function_exists( 'mb_strtolower' ) is false on the binaries
				 * this suite runs on, because the test command passes -n. So on
				 * this machine the line below pins the documented fallback, and
				 * on a host with mbstring it pins the agreement with the
				 * browser. It fails either way if has_category() stops asking.
				 */
				$folds = function_exists( 'mb_strtolower' );

				assert_same(
					$folds ? 1 : 0,
					count( $asked( 'ŻŁOBKI' ) ),
					$folds
						? 'mbstring is here and the filter still folds ASCII only, so the browser and the server disagree'
						: 'mbstring is absent and the filter folded non-ASCII anyway, which cannot be what it did'
				);

				// Whichever branch this host is on, it is the one the source
				// says it is on. Pinned here rather than left to the docblock,
				// because an unguarded mb_strtolower() is a fatal error on a
				// host without mbstring and nothing else in this suite would
				// reach it.
				$source = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-rest-controller.php' );

				assert_contains( "function_exists( 'mb_strtolower' ) ? mb_strtolower( \$name, 'UTF-8' ) : strtolower( \$name )", $source );

				/*
				 * And that nothing in this file still calls strcasecmp. On a
				 * host with no mbstring the fold *is* strtolower, so strcasecmp
				 * and the fold agree on every input this machine can produce —
				 * which means no behavioural assertion here can tell a route
				 * that went back to strcasecmp from one that did not. Only the
				 * source can, and only in code: the docblock above
				 * has_category() names strcasecmp four times explaining why it
				 * is gone, so a text search would read the explanation as the
				 * offence.
				 *
				 * token_get_all() is the exact tool rather than a stripper that
				 * approximates one. T_STRING is what a function name is.
				 */
				$called = array();

				foreach ( token_get_all( $source ) as $token ) {
					if ( is_array( $token ) && T_STRING === $token[0] ) {
						$called[] = $token[1];
					}
				}

				assert_true( in_array( 'fold', $called, true ), 'the fold this case is about is not called anywhere' );
				assert_false(
					in_array( 'strcasecmp', $called, true ),
					'something in the REST controller folds ASCII only again, so the browser and the server disagree'
				);
			}
		);

		it(
			'applies the limit it was given',
			function () {
				slosm_rest_krakow();

				$items = slosm_rest_controller()->get_stores(
					slosm_rest_request( '/stores', array( 'limit' => 1 ) )
				)->get_data();

				assert_same( 1, count( $items ) );
			}
		);

		it(
			'lets a shared cache hold the plain list and forbids it for a proximity search',
			function () {
				$plain = slosm_rest_controller()->get_stores( slosm_rest_request( '/stores' ) );

				assert_same(
					'public, max-age=' . Rest_Controller::LIST_MAX_AGE,
					$plain->get_headers()['Cache-Control']
				);

				// The url of a proximity search carries where the visitor is, so
				// no shared cache may keep it.
				$near = slosm_rest_controller()->get_stores(
					slosm_rest_request(
						'/stores',
						array(
							'lat'    => 52.2297,
							'lng'    => 21.0122,
							'radius' => 10.0,
						)
					)
				);

				assert_same(
					'private, max-age=' . Rest_Controller::SEARCH_MAX_AGE,
					$near->get_headers()['Cache-Control']
				);
			}
		);

		it(
			'searches only when it was given both coordinates',
			function () {
				slosm_rest_krakow();

				// One coordinate is not a point. Read with || instead of &&,
				// lat=52 alone runs find_near( 52.0, 0.0, … ) — a silent
				// proximity search from a spot in the North Sea, which on a
				// Polish site quietly returns nothing and looks like an empty
				// database.
				foreach ( array( 'lat' => 52.2297, 'lng' => 21.0122 ) as $name => $value ) {
					$response = slosm_rest_controller()->get_stores(
						slosm_rest_request( '/stores', array( $name => $value ) )
					);

					$items = $response->get_data();

					assert_same( 2, count( $items ), $name . ' alone did not return the plain list' );
					assert_same( null, $items[0]['distance'], $name . ' alone measured a distance' );

					// And it is the public list, not a private search result.
					assert_same(
						'public, max-age=' . Rest_Controller::LIST_MAX_AGE,
						$response->get_headers()['Cache-Control']
					);
				}

				// The control: both together do search.
				$near = slosm_rest_controller()->get_stores(
					slosm_rest_request(
						'/stores',
						array(
							'lat'    => 52.2297,
							'lng'    => 21.0122,
							'radius' => 10.0,
						)
					)
				);

				assert_same( 1, count( $near->get_data() ) );
			}
		);

		it(
			'answers 200 with an empty list when a search matches nothing',
			function () {
				$response = slosm_rest_controller()->get_stores(
					slosm_rest_request(
						'/stores',
						array(
							'lat'    => 0.0,
							'lng'    => 0.0,
							'radius' => 1.0,
						)
					)
				);

				assert_same( 200, $response->get_status() );
				assert_same( array(), $response->get_data() );
			}
		);
	}
);

describe(
	'rest controller backstops',
	function () {

		/*
		 * The callbacks' own defaults, reached with a bare WP_REST_Request that
		 * carries no schema defaults at all.
		 *
		 * This is not the same question as the clamping cases and the difference
		 * is worth stating, because conflating the two left every one of these
		 * uncovered the first time round. The clamps are WordPress's: no visitor
		 * reaches a callback with a radius of a million, because the schema
		 * rejected it two steps earlier, so handing a callback such a value
		 * proves nothing about a visitor. The backstops are for the caller that
		 * never went through a schema at all — the shortcode of Task 11, the map
		 * initialiser of Task 13, the popup fetch of Task 16, and every test —
		 * and for that caller the permissive request stub is exactly the right
		 * instrument, because it is the only way to hand a callback a value
		 * WordPress would have refused.
		 *
		 * So these cases build `new WP_REST_Request( … )` directly rather than
		 * going through slosm_rest_request(), which would helpfully supply the
		 * very defaults under test.
		 */

		before_each( 'slosm_rest_warsaw' );

		it(
			'fills in every list argument the caller left out',
			function () {
				slosm_rest_krakow();

				// No parameters and no defaults: the whole list, measured from
				// nowhere, in one piece.
				$response = slosm_rest_controller()->get_stores( new WP_REST_Request() );
				$items    = $response->get_data();

				assert_same( 2, count( $items ) );
				assert_same( null, $items[0]['distance'] );
			}
		);

		it(
			'defaults the limit to MAX_LIMIT rather than to nothing',
			function () {
				slosm_rest_seed_many( Rest_Controller::MAX_LIMIT + 1 );

				// 501 locations, no limit anywhere. A default of zero or of "no
				// limit" both pass with two locations staged and both are wrong;
				// only a list of exactly MAX_LIMIT distinguishes them.
				$items = slosm_rest_controller()->get_stores( new WP_REST_Request() )->get_data();

				assert_same( Rest_Controller::MAX_LIMIT, count( $items ) );
			}
		);

		it(
			'caps the list at MAX_LIMIT however large a limit reaches the callback',
			function () {
				slosm_rest_seed_many( Rest_Controller::MAX_LIMIT + 1 );

				// The schema refuses this for a visitor. Nothing refuses it for
				// an internal caller, so the callback has to.
				$items = slosm_rest_controller()->get_stores(
					new WP_REST_Request( array( 'limit' => 100000 ) )
				)->get_data();

				assert_same( Rest_Controller::MAX_LIMIT, count( $items ) );

				// The control: the cap is a cap and not a fixed size — a smaller
				// limit is still honoured.
				$fewer = slosm_rest_controller()->get_stores(
					new WP_REST_Request( array( 'limit' => 3 ) )
				)->get_data();

				assert_same( 3, count( $fewer ) );
			}
		);

		it(
			'returns nothing for a limit below one rather than reading it as everything',
			function () {
				slosm_rest_krakow();

				$items = slosm_rest_controller()->get_stores(
					new WP_REST_Request( array( 'limit' => 0 ) )
				)->get_data();

				assert_same( array(), $items );
			}
		);

		it(
			'searches the default radius when the caller named none',
			function () {
				slosm_rest_krakow();

				// Ursynów, about ten kilometres south of the fixture: inside
				// DEFAULT_RADIUS and — this is the point — outside a radius of
				// zero. Searching from the point the fixture itself stands on,
				// a radius that arrived as null and cast to 0.0 would still
				// return the fixture, at a distance of zero, and look right. It
				// takes a second location at a real distance to tell a default
				// of fifty from a default of nothing.
				slosm_rest_seed(
					slosm_rest_row( 9, 'Kawiarnia Ursynów' ),
					array(
						'_slosm_lat' => '52.1400',
						'_slosm_lng' => '21.0500',
					)
				);

				$items = slosm_rest_controller()->get_stores(
					new WP_REST_Request(
						array(
							'lat' => 52.2297,
							'lng' => 21.0122,
						)
					)
				)->get_data();

				// Both Warsaw locations, and not Kraków at 252 km.
				assert_same( 2, count( $items ) );
				assert_same( 7, $items[0]['id'] );
				assert_close( 0.0, (float) $items[0]['distance'], 0.001 );
				assert_same( 9, $items[1]['id'] );
				assert_close( 10.0, (float) $items[1]['distance'], 1.5 );
			}
		);

		it(
			'measures in kilometres when no unit arrived',
			function () {
				slosm_rest_krakow();

				// 200 of something, and which something decides the answer:
				// Kraków is 252 km away and 157 miles away, so it is outside
				// this radius in kilometres and inside it in miles. A unit that
				// arrived as null would not get this far at all — find_near()
				// declares a string parameter — so this case covers both the
				// fallback and the type.
				$items = slosm_rest_controller()->get_stores(
					new WP_REST_Request(
						array(
							'lat'    => 52.2297,
							'lng'    => 21.0122,
							'radius' => 200.0,
						)
					)
				)->get_data();

				assert_same( 1, count( $items ) );
				assert_same( 7, $items[0]['id'] );

				// The control: asked in miles, the same radius reaches Kraków.
				$miles = slosm_rest_controller()->get_stores(
					new WP_REST_Request(
						array(
							'lat'    => 52.2297,
							'lng'    => 21.0122,
							'radius' => 200.0,
							'unit'   => 'mi',
						)
					)
				)->get_data();

				assert_same( 2, count( $miles ) );
			}
		);

		it(
			'falls back to kilometres for a unit Geo does not know',
			function () {
				slosm_rest_krakow();

				$items = slosm_rest_controller()->get_stores(
					new WP_REST_Request(
						array(
							'lat'    => 52.2297,
							'lng'    => 21.0122,
							'radius' => 200.0,
							'unit'   => 'furlongs',
						)
					)
				)->get_data();

				assert_same( 1, count( $items ) );
			}
		);
	}
);

describe(
	'rest controller single store',
	function () {

		before_each( 'slosm_rest_warsaw' );

		it(
			'reads the whole record even when the lean payload is already cached',
			function () {
				$repository = slosm_rest_repository();
				$controller = slosm_rest_controller( $repository );

				// Warm the payload first. A controller reaching into the list
				// would find this location there, with ten empty fields.
				$controller->get_stores( slosm_rest_request( '/stores' ) );

				$record = $controller->get_store( slosm_rest_request( '/stores/(?P<id>\d+)', array( 'id' => 7 ) ) )->get_data();

				assert_same( '+48 22 000 00 00', $record['phone'] );
				assert_same( "Mon 9-17\nTue 9-17", $record['hours'] );
				assert_same( 'Kawa i ciastka.', $record['description'] );
				assert_same( 'lokal 3', $record['address2'] );
				assert_same( 'kawa@example.test', $record['email'] );
			}
		);

		it(
			'does not ship lat_locked, which is an admin concern',
			function () {
				$record = slosm_rest_controller()->get_store(
					slosm_rest_request( '/stores/(?P<id>\d+)', array( 'id' => 7 ) )
				)->get_data();

				assert_same(
					array(
						'id',
						'name',
						'description',
						'address',
						'address2',
						'city',
						'state',
						'zip',
						'country',
						'lat',
						'lng',
						'phone',
						'email',
						'url',
						'hours',
						'categories',
					),
					array_keys( $record )
				);

				// The control: the record really does carry lat_locked, and it is
				// true, so its absence above is a projection rather than a
				// fixture that never set it.
				assert_true( slosm_rest_repository()->find_by_id( 7 )->lat_locked );
			}
		);

		it(
			'gives a 404 for an id that is not a published location',
			function () {
				$controller = slosm_rest_controller();

				$missing = $controller->get_store(
					slosm_rest_request( '/stores/(?P<id>\d+)', array( 'id' => 4242 ) )
				);

				assert_true( is_wp_error( $missing ) );
				assert_same( 404, slosm_rest_status( $missing ) );

				// The control: the same callback, the same request shape, a real
				// id — so the 404 is about the id and not about the callback
				// being broken for everything.
				$found = $controller->get_store(
					slosm_rest_request( '/stores/(?P<id>\d+)', array( 'id' => 7 ) )
				);

				assert_false( is_wp_error( $found ) );
				assert_same( 200, $found->get_status() );
			}
		);

		it(
			'gives a 404 for a password-protected location rather than its body',
			function () {
				$GLOBALS['slosm_stub']['posts_by_id'][7]->post_password = 'sekret';

				$controller = slosm_rest_controller();

				$protected = $controller->get_store(
					slosm_rest_request( '/stores/(?P<id>\d+)', array( 'id' => 7 ) )
				);

				assert_true( is_wp_error( $protected ) );
				assert_same( 404, slosm_rest_status( $protected ) );

				// The description is post_content, which is the field core puts
				// behind the password form, and it must not be anywhere in the
				// answer.
				assert_false( strpos( wp_json_encode( $protected ), 'Kawa i ciastka' ) );

				// The control: the identical fixture without the password is a
				// 200 carrying exactly that content, so the 404 above is the
				// password and not a broken route.
				$GLOBALS['slosm_stub']['posts_by_id'][7]->post_password = '';

				$open = $controller->get_store(
					slosm_rest_request( '/stores/(?P<id>\d+)', array( 'id' => 7 ) )
				);

				assert_same( 200, $open->get_status() );
				assert_same( 'Kawa i ciastka.', $open->get_data()['description'] );
			}
		);

		it(
			'gives a 404 for a post of another type behind a numeric id',
			function () {
				$GLOBALS['slosm_stub']['posts_by_id'][99] = (object) array(
					'ID'          => 99,
					'post_title'  => 'Privacy policy',
					'post_type'   => 'page',
					'post_status' => 'publish',
				);

				$missing = slosm_rest_controller()->get_store(
					slosm_rest_request( '/stores/(?P<id>\d+)', array( 'id' => 99 ) )
				);

				assert_same( 404, slosm_rest_status( $missing ) );
			}
		);

		it(
			'lets a shared cache hold one location',
			function () {
				$response = slosm_rest_controller()->get_store(
					slosm_rest_request( '/stores/(?P<id>\d+)', array( 'id' => 7 ) )
				);

				assert_same(
					'public, max-age=' . Rest_Controller::LIST_MAX_AGE,
					$response->get_headers()['Cache-Control']
				);
			}
		);

		it(
			'answers with a null coordinate rather than a point in the Gulf of Guinea',
			function () {
				slosm_rest_seed(
					slosm_rest_row( 12, 'Punkt bez współrzędnych' ),
					array( '_slosm_city' => 'Radom' )
				);

				$record = slosm_rest_controller()->get_store(
					slosm_rest_request( '/stores/(?P<id>\d+)', array( 'id' => 12 ) )
				)->get_data();

				assert_same( null, $record['lat'] );
				assert_same( null, $record['lng'] );
				assert_same( 'Radom', $record['city'] );
			}
		);
	}
);

describe(
	'rest controller geocode',
	function () {

		it(
			'returns the point and lets the browser, but not a shared cache, keep it',
			function () {
				slosm_rest_queue( 200, '[{"lat":"52.2297","lon":"21.0122","display_name":"Warszawa"}]' );

				$response = slosm_rest_controller()->get_geocode(
					slosm_rest_request( '/geocode', array( 'q' => 'Warszawa' ) )
				);

				assert_same( 200, $response->get_status() );
				assert_same(
					array(
						'lat'   => 52.2297,
						'lng'   => 21.0122,
						'label' => 'Warszawa',
					),
					$response->get_data()
				);

				// The address a person typed is in the url, so this is the
				// visitor's cache and nobody else's.
				assert_same(
					'private, max-age=' . Rest_Controller::GEOCODE_MAX_AGE,
					$response->get_headers()['Cache-Control']
				);
			}
		);

		it(
			'refuses an empty q before anything is asked of the service',
			function () {
				// Queued, and it must still be queued afterwards. wp_remote_get()
				// throws when nothing is queued, so an empty queue would make
				// "no request" indistinguishable from "the stub blew up", and a
				// full queue makes the absence positive rather than negative.
				slosm_rest_queue( 200, '[{"lat":"52.2297","lon":"21.0122"}]' );

				$controller = slosm_rest_controller();

				$refused = $controller->get_geocode( slosm_rest_request( '/geocode', array( 'q' => '' ) ) );

				assert_true( is_wp_error( $refused ) );
				assert_same( 400, slosm_rest_status( $refused ) );
				assert_same( 0, slosm_rest_requests() );
				assert_same( 1, count( $GLOBALS['slosm_stub']['http_queue'] ) );

				// The control: the identical call with a real query does spend
				// that queued response, so the queue staying full above is the
				// controller's doing and not the fixture's.
				$controller->get_geocode( slosm_rest_request( '/geocode', array( 'q' => 'Warszawa' ) ) );

				assert_same( 1, slosm_rest_requests() );
				assert_same( 0, count( $GLOBALS['slosm_stub']['http_queue'] ) );
			}
		);

		it(
			'refuses a q that is only whitespace, and still asks nothing',
			function () {
				slosm_rest_queue( 200, '[]' );

				$refused = slosm_rest_controller()->get_geocode(
					slosm_rest_request( '/geocode', array( 'q' => '   ' ) )
				);

				assert_same( 400, slosm_rest_status( $refused ) );
				assert_same( 0, slosm_rest_requests() );
			}
		);

		it(
			'answers 404 when no place matched',
			function () {
				slosm_rest_queue( 200, '[]' );

				$response = slosm_rest_controller()->get_geocode(
					slosm_rest_request( '/geocode', array( 'q' => 'Nigdzie' ) )
				);

				assert_same( 404, slosm_rest_status( $response ) );
				assert_same( 'slosm_geocode_no_results', $response->get_error_code() );

				// The control: the request did go out, so this 404 is the
				// service's answer rather than a refusal on the way.
				assert_same( 1, slosm_rest_requests() );
			}
		);

		it(
			'answers 429 when the service asks for fewer requests',
			function () {
				slosm_rest_queue( 429, '' );

				$response = slosm_rest_controller()->get_geocode(
					slosm_rest_request( '/geocode', array( 'q' => 'Warszawa' ) )
				);

				assert_same( 429, slosm_rest_status( $response ) );
			}
		);

		it(
			'answers 502 for every way the service can be broken',
			function () {
				$cases = array(
					// A status nobody can act on.
					'bad_status'      => array( 500, 'Internal Server Error' ),
					// Valid json that is not a list of places; the error data is
					// array( 'upstream_status' => 200 ).
					'bad_json_status' => array( 200, 'not json at all' ),
					// An object with an 'error' key, which is what Nominatim
					// sends for a rejected request. Raised inside geocode()
					// rather than request(), with no data at all — the null
					// upstream_status the reading has to tolerate.
					'bad_json_plain'  => array( 200, '{"error":"Bad request"}' ),
					// A place with coordinates that are not coordinates.
					'bad_coordinates' => array( 200, '[{"lat":"abc","lon":"def"}]' ),
				);

				foreach ( $cases as $name => $queued ) {
					slosm_rest_queue( $queued[0], $queued[1] );

					$response = slosm_rest_controller()->get_geocode(
						slosm_rest_request( '/geocode', array( 'q' => 'Warszawa ' . $name ) )
					);

					assert_same( 502, slosm_rest_status( $response ), $name . ' did not map to 502' );
				}

				// Four queries, four requests: none of them was served out of a
				// cache, so all four really reached the mapping.
				assert_same( 4, slosm_rest_requests() );
			}
		);

		it(
			'never lets an upstream hostname or message reach the caller',
			function () {
				$GLOBALS['slosm_stub']['http_queue'][] = new WP_Error(
					'http_request_failed',
					'cURL error 6: Could not resolve host: nominatim.internal.client.lan'
				);

				$response = slosm_rest_controller()->get_geocode(
					slosm_rest_request( '/geocode', array( 'q' => 'Warszawa' ) )
				);

				assert_same( 502, slosm_rest_status( $response ) );

				$exposed = wp_json_encode(
					array(
						$response->get_error_code(),
						$response->get_error_message(),
						$response->get_error_data(),
					)
				);

				assert_false( strpos( $exposed, 'internal.client.lan' ) );
				assert_false( strpos( $exposed, 'cURL' ) );
				assert_false( strpos( $exposed, 'upstream_message' ) );
				assert_false( strpos( $exposed, 'upstream_code' ) );

				// The error data is exactly the status and nothing else.
				assert_same( array( 'status' => 502 ), $response->get_error_data() );

				// The control: the geocoder really did hand the controller that
				// hostname, so the four assertions above are about what the
				// controller dropped and not about a fixture that never carried
				// it. Asked again, with the same queue, of the geocoder itself.
				$GLOBALS['slosm_stub']['http_queue'][] = new WP_Error(
					'http_request_failed',
					'cURL error 6: Could not resolve host: nominatim.internal.client.lan'
				);

				$raw = slosm_rest_geocoder()->geocode( 'Kraków' );

				assert_contains( 'internal.client.lan', wp_json_encode( $raw->get_error_data() ) );
			}
		);

		it(
			'keeps what it dropped, by announcing it',
			function () {
				$seen = array();

				add_action(
					'slosm_rest_upstream_failed',
					static function ( $code, $data ) use ( &$seen ): void {
						$seen[] = array( $code, $data );
					}
				);

				$GLOBALS['slosm_stub']['http_queue'][] = new WP_Error(
					'http_request_failed',
					'cURL error 6: Could not resolve host: nominatim.internal.client.lan'
				);

				slosm_rest_controller()->get_geocode(
					slosm_rest_request( '/geocode', array( 'q' => 'Warszawa' ) )
				);

				assert_same( 1, count( $seen ) );
				assert_same( 'slosm_geocode_http', $seen[0][0] );
				assert_contains( 'internal.client.lan', wp_json_encode( $seen[0][1] ) );
			}
		);

		it(
			'announces nothing for a failure that is the caller\'s own or a plain no-match',
			function () {
				// Without this boundary a site that hooks the announcement for
				// alerting gets one event per unmatched address — which on a
				// search box is every second visitor, and which trains whoever
				// reads that log to stop reading it.
				$seen = array();

				add_action(
					'slosm_rest_upstream_failed',
					static function ( $code, $data ) use ( &$seen ): void {
						$seen[] = $code;
					}
				);

				$controller = slosm_rest_controller();

				// 400: nothing was asked of anybody.
				$controller->get_geocode( slosm_rest_request( '/geocode', array( 'q' => '' ) ) );

				// 404: asked and answered, and the answer was no.
				slosm_rest_queue( 200, '[]' );
				$controller->get_geocode( slosm_rest_request( '/geocode', array( 'q' => 'Nigdzie' ) ) );

				assert_same( array(), $seen );

				// The control: the same hook, the same controller, an upstream
				// failure — so the silence above is about the status and not
				// about the hook never firing.
				slosm_rest_queue( 500, 'Internal Server Error' );
				$controller->get_geocode( slosm_rest_request( '/geocode', array( 'q' => 'Warszawa' ) ) );

				assert_same( array( 'slosm_geocode_bad_status' ), $seen );
			}
		);

		it(
			'passes the country through to the service',
			function () {
				slosm_rest_queue( 200, '[{"lat":"52.2297","lon":"21.0122"}]' );

				slosm_rest_controller()->get_geocode(
					slosm_rest_request(
						'/geocode',
						array(
							'q'       => 'Warszawa',
							'country' => 'PL',
						)
					)
				);

				assert_contains( 'countrycodes=pl', $GLOBALS['slosm_stub']['http_requests'][0]['url'] );
			}
		);
	}
);

describe(
	'rest controller suggest',
	function () {

		it(
			'returns the suggestions and keeps them off a shared cache',
			function () {
				slosm_rest_queue(
					200,
					'{"features":[{"geometry":{"coordinates":[21.0122,52.2297]},"properties":{"name":"Warszawa"}}]}'
				);

				$response = slosm_rest_controller()->get_suggest(
					slosm_rest_request( '/suggest', array( 'q' => 'warsz' ) )
				);

				assert_same( 200, $response->get_status() );

				$data = $response->get_data();

				assert_same( 1, count( $data ) );
				assert_same( 52.2297, $data[0]['lat'] );
				assert_same( 21.0122, $data[0]['lng'] );
				assert_contains( 'Warszawa', $data[0]['label'] );

				assert_same(
					'private, max-age=' . Rest_Controller::SUGGEST_MAX_AGE,
					$response->get_headers()['Cache-Control']
				);
			}
		);

		it(
			'answers nothing matched with an empty list, not an error',
			function () {
				slosm_rest_queue( 200, '{"features":[]}' );

				$response = slosm_rest_controller()->get_suggest(
					slosm_rest_request( '/suggest', array( 'q' => 'zzzzz' ) )
				);

				assert_false( is_wp_error( $response ) );
				assert_same( 200, $response->get_status() );
				assert_same( array(), $response->get_data() );
				assert_same( 'no-store', $response->get_headers()['Cache-Control'] );

				// The control: the request did go out, so the empty list is the
				// service's answer rather than a skip.
				assert_same( 1, slosm_rest_requests() );
			}
		);

		it(
			'skips rather than waits when the limiter would hold the worker',
			function () {
				$geocoder = slosm_rest_geocoder();

				// Half a second ago, so suggest() on a miss would sleep for the
				// other half.
				slosm_rest_busy( $geocoder, Geocoder::SERVICE_PHOTON, 0.5 );

				// Queued and it must stay queued.
				slosm_rest_queue( 200, '{"features":[]}' );

				$response = slosm_rest_controller( null, $geocoder )->get_suggest(
					slosm_rest_request( '/suggest', array( 'q' => 'warsz' ) )
				);

				assert_same( 204, $response->get_status() );
				assert_same( null, $response->get_data() );
				assert_same( 'no-store', $response->get_headers()['Cache-Control'] );

				assert_same( 0, slosm_rest_requests() );
				assert_same( array(), $GLOBALS['slosm_stub']['sleeps'] );
				assert_same( 1, count( $GLOBALS['slosm_stub']['http_queue'] ) );
			}
		);

		it(
			'answers a skip and a genuine miss differently, by status alone',
			function () {
				// The distinction Task 14 needs: a skip is retried on the next
				// keystroke, a miss shows "no matches" and stops. It has to be
				// carried by the status, because WP_REST_Server replaces
				// Cache-Control with wp_get_nocache_headers() for a logged-in
				// request — so the header the two used to differ by is gone for
				// exactly the people testing the feature.
				$geocoder = slosm_rest_geocoder();

				slosm_rest_busy( $geocoder, Geocoder::SERVICE_PHOTON, 0.2 );

				$skip = slosm_rest_controller( null, $geocoder )->get_suggest(
					slosm_rest_request( '/suggest', array( 'q' => 'warsz' ) )
				);

				// A genuine miss: the interval has passed, the service is asked,
				// and it matches nothing.
				slosm_rest_busy( $geocoder, Geocoder::SERVICE_PHOTON, Geocoder::MIN_INTERVAL + 1.0 );
				slosm_rest_queue( 200, '{"features":[]}' );

				$miss = slosm_rest_controller( null, $geocoder )->get_suggest(
					slosm_rest_request( '/suggest', array( 'q' => 'warsz' ) )
				);

				assert_same( 204, $skip->get_status() );
				assert_same( 200, $miss->get_status() );

				// The control for "by status alone": every other observable is
				// the same, so nothing else could be carrying the difference.
				assert_same( $skip->get_headers(), $miss->get_headers() );
				assert_same( 1, slosm_rest_requests() );
			}
		);

		it(
			'sends no body at all with a skip, because core sends none for a 204',
			function () {
				// WP_REST_Server returns before wp_json_encode() for a 204 —
				// "The 204 response shouldn't have a body" — so the data must be
				// null rather than an empty list. An empty list here would read
				// as [] to anyone inspecting the response object and as nothing
				// at all over the wire, which is two answers to one question.
				$geocoder = slosm_rest_geocoder();

				slosm_rest_busy( $geocoder, Geocoder::SERVICE_PHOTON, 0.2 );

				$skip = slosm_rest_controller( null, $geocoder )->get_suggest(
					slosm_rest_request( '/suggest', array( 'q' => 'warsz' ) )
				);

				assert_same( null, $skip->get_data() );

				// The control: a real answer does carry a body.
				slosm_rest_busy( $geocoder, Geocoder::SERVICE_PHOTON, Geocoder::MIN_INTERVAL + 1.0 );
				slosm_rest_queue(
					200,
					'{"features":[{"geometry":{"coordinates":[21.0122,52.2297]},"properties":{"name":"Warszawa"}}]}'
				);

				$answer = slosm_rest_controller( null, $geocoder )->get_suggest(
					slosm_rest_request( '/suggest', array( 'q' => 'warsz' ) )
				);

				assert_same( 1, count( $answer->get_data() ) );
			}
		);

		it(
			'asks, and does not sleep, once the courtesy interval has passed',
			function () {
				// The control for the case above. Same fixture, same queue, the
				// only difference being how long ago the service was asked.
				$geocoder = slosm_rest_geocoder();

				slosm_rest_busy( $geocoder, Geocoder::SERVICE_PHOTON, Geocoder::MIN_INTERVAL + 1.0 );
				slosm_rest_queue(
					200,
					'{"features":[{"geometry":{"coordinates":[21.0122,52.2297]},"properties":{"name":"Warszawa"}}]}'
				);

				$response = slosm_rest_controller( null, $geocoder )->get_suggest(
					slosm_rest_request( '/suggest', array( 'q' => 'warsz' ) )
				);

				assert_same( 1, count( $response->get_data() ) );
				assert_same( 1, slosm_rest_requests() );
				assert_same( array(), $GLOBALS['slosm_stub']['sleeps'] );
			}
		);

		it(
			'still serves a cached suggestion while the limiter is busy',
			function () {
				$geocoder = slosm_rest_geocoder();

				slosm_rest_busy( $geocoder, Geocoder::SERVICE_PHOTON, 0.1 );

				set_transient(
					$geocoder->cache_key( Geocoder::SERVICE_PHOTON, 'warsz' ),
					array(
						array(
							'label' => 'Warszawa, Polska',
							'lat'   => 52.2297,
							'lng'   => 21.0122,
						),
					),
					Geocoder::SUGGEST_CACHE_TTL
				);

				$response = slosm_rest_controller( null, $geocoder )->get_suggest(
					slosm_rest_request( '/suggest', array( 'q' => 'Warsz' ) )
				);

				$data = $response->get_data();

				assert_same( 1, count( $data ) );
				assert_same( 'Warszawa, Polska', $data[0]['label'] );

				// A cache hit never reaches the limiter, so nothing was asked and
				// nothing slept — and the skip did not swallow a free answer.
				assert_same( 0, slosm_rest_requests() );
				assert_same( array(), $GLOBALS['slosm_stub']['sleeps'] );
			}
		);

		it(
			'gives an autocomplete failure a 502 and no upstream detail',
			function () {
				$GLOBALS['slosm_stub']['http_queue'][] = new WP_Error(
					'http_request_failed',
					'cURL error 6: Could not resolve host: photon.internal.client.lan'
				);

				$response = slosm_rest_controller()->get_suggest(
					slosm_rest_request( '/suggest', array( 'q' => 'warsz' ) )
				);

				assert_same( 502, slosm_rest_status( $response ) );
				assert_same( array( 'status' => 502 ), $response->get_error_data() );
				assert_false( strpos( wp_json_encode( $response->get_error_message() ), 'internal.client.lan' ) );
			}
		);

		it(
			'refuses a blank q when it is free to ask, and says nothing when it is not',
			function () {
				// The one place the skip-before-ask order is visible. Both halves
				// refuse without a request; they disagree only about which
				// refusal, and both are reachable in production. minLength
				// validates the *raw* value and sanitize_text_field() runs
				// afterwards, so q=%20 passes the schema and arrives here as ''
				// — the schema does not close this, which an earlier version of
				// this comment wrongly claimed.
				slosm_rest_queue( 200, '{"features":[]}' );

				$refused = slosm_rest_controller()->get_suggest(
					slosm_rest_request( '/suggest', array( 'q' => '   ' ) )
				);

				assert_same( 400, slosm_rest_status( $refused ) );
				assert_same( 0, slosm_rest_requests() );

				$geocoder = slosm_rest_geocoder();

				slosm_rest_busy( $geocoder, Geocoder::SERVICE_PHOTON, 0.2 );

				$skipped = slosm_rest_controller( null, $geocoder )->get_suggest(
					slosm_rest_request( '/suggest', array( 'q' => '   ' ) )
				);

				assert_false( is_wp_error( $skipped ) );
				assert_same( 204, $skipped->get_status() );
				assert_same( 0, slosm_rest_requests() );
				assert_same( array(), $GLOBALS['slosm_stub']['sleeps'] );
			}
		);

		it(
			'answers 429 when the suggestion service asks for fewer requests',
			function () {
				slosm_rest_queue( 429, '' );

				$response = slosm_rest_controller()->get_suggest(
					slosm_rest_request( '/suggest', array( 'q' => 'warsz' ) )
				);

				assert_same( 429, slosm_rest_status( $response ) );
			}
		);
	}
);

describe(
	'rest controller wiring',
	function () {

		it(
			'is hung on rest_api_init by the plugin, and on nothing earlier',
			function () {
				// register_rest_route() before rest_api_init registers into a
				// server that has not been built yet, so the hook is not a
				// detail. Asserted through boot() rather than by reading the
				// source, because the composition root is the only place that
				// decides it.
				$construct = Closure::bind(
					static function () {
						return new Plugin();
					},
					null,
					Plugin::class
				);

				$construct()->boot();

				$registered = $GLOBALS['slosm_stub']['actions']['rest_api_init'] ?? array();

				assert_same( 1, count( $registered ) );
				assert_same( 'register_routes', $registered[0]['callback'][1] );
				assert_true( $registered[0]['callback'][0] instanceof Rest_Controller );

				// The control: boot() alone registers nothing with WordPress's
				// REST server, so a route can only appear once that hook fires.
				assert_same( array(), $GLOBALS['slosm_stub']['rest_routes'] );

				call_user_func( $registered[0]['callback'] );

				assert_same( 4, count( $GLOBALS['slosm_stub']['rest_routes'] ) );
			}
		);
	}
);
