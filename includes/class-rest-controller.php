<?php
/**
 * The four public routes: the map's data, one location, and two proxied lookups.
 *
 * @package Store_Locator_For_OpenStreetMap
 */

namespace Asymetria\StoreLocator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( __NAMESPACE__ . '\\Rest_Controller' ) ) {

	/**
	 * Everything this plugin exposes over HTTP.
	 *
	 * register_rest_route(), not admin-ajax.php. admin-ajax boots half the admin
	 * on every call, sends its own no-cache headers so nothing can be held at the
	 * edge, and has no argument schema — every parameter would be sanitised by
	 * hand in the callback, which is where a missed one becomes a bug rather than
	 * a rejection.
	 *
	 * All four routes are read-only and their permission callback is
	 * __return_true. That is a decision rather than a default: every field they
	 * return is already drawn on a public map, and the two lookups are proxies
	 * for services a browser could call itself — the proxy exists so the site,
	 * not the visitor, is the one identifying itself to OpenStreetMap, and so the
	 * answers can be cached once for everybody.
	 *
	 * Four things in here are easy to get wrong in a way nothing reports.
	 *
	 * The list is built from to_lean_array(), never to_full_array()
	 * ------------------------------------------------------------
	 * find_all() and find_near() both return *partial* Stores: they are rebuilt
	 * from the seven-field payload, so ten of the seventeen fields are empty
	 * strings. A partial Store still answers to_full_array() with a
	 * seventeen-key record that looks complete, and nothing in the type can tell
	 * the two apart. A list response built from it is an HTTP 200 in which every
	 * location's phone number, opening hours and description are blank — on every
	 * site, for every visitor, with no error anywhere. So the list is projected
	 * from to_lean_array(), and anything wanting the whole record asks
	 * /stores/<id>, which goes through find_by_id() and always reads storage.
	 *
	 * The distance is in neither array shape
	 * --------------------------------------
	 * with_distance() writes a property, deliberately not a field: a distance is
	 * a fact about one search from one point, and the lean array is what gets
	 * cached under a key shared by every visitor in a language. So the list reads
	 * $store->distance off the object and adds it as an eighth key, null when
	 * nobody searched from anywhere.
	 *
	 * lat_locked does not leave the building
	 * --------------------------------------
	 * to_full_array() is a storage shape. It carries lat_locked, which exists so
	 * the bulk geocoder knows whether it may overwrite a pin an editor placed by
	 * hand — an admin concern with no meaning to a map. /stores/<id> projects a
	 * response view from that record rather than returning it, so that the first
	 * field that is admin-only *in a way that matters* is not shipped by
	 * accident.
	 *
	 * Nothing the geocoder says about the upstream service is repeated
	 * ---------------------------------------------------------------
	 * A transport failure arrives carrying upstream_code and upstream_message
	 * verbatim, and that message can read "cURL error 6: Could not resolve host:
	 * nominatim.internal.client.lan" — the name of a machine inside the client's
	 * network, handed to an anonymous caller who asked about a coffee shop. The
	 * response gets a code, a generic sentence and a status; the detail goes to
	 * slosm_rest_upstream_failed, whole. See error_response().
	 *
	 * Autocomplete is the one route that can take the site down
	 * --------------------------------------------------------
	 * See get_suggest(). The short version is that /suggest declines to answer
	 * rather than wait, because the alternative is a worker asleep in usleep().
	 */
	final class Rest_Controller {

		/**
		 * The namespace every route lives under.
		 *
		 * Not called NAMESPACE, which would be a class constant spelled like a
		 * reserved word and a tripwire for no benefit.
		 *
		 * @var string
		 */
		public const REST_NAMESPACE = 'slosm/v1';

		/**
		 * The largest radius a search may ask for, in whichever unit it asked in.
		 *
		 * Five hundred, which is a national-scale search in kilometres and rather
		 * more in miles, and which is well past anything a store locator is for.
		 * The number is in the schema rather than in a callback so that a request
		 * for ten thousand is refused by WordPress with a reason a caller can
		 * read, instead of being quietly changed into something else.
		 *
		 * @var float
		 */
		public const MAX_RADIUS = 500.0;

		/**
		 * The radius a proximity search uses when it names none.
		 *
		 * @var float
		 */
		public const DEFAULT_RADIUS = 50.0;

		/**
		 * The most locations one response will carry.
		 *
		 * Five hundred, which is the preload ceiling the design document names:
		 * the point at which shipping the whole list stops paying for itself and
		 * the front end switches to searching. A caller wanting fewer says so;
		 * this is both the clamp and the default, so /stores without arguments
		 * still returns the map payload rather than a truncated page of it.
		 *
		 * @var int
		 */
		public const MAX_LIMIT = 500;

		/**
		 * How long a list or a single location may be held, in seconds.
		 *
		 * Five minutes. The payload behind it has a day-long ttl and is
		 * invalidated on save, so this is the extra staleness a shared cache can
		 * add on top — short enough that an editor who publishes a branch sees it
		 * within a coffee break, long enough to absorb a visitor reloading and
		 * panning.
		 *
		 * @var int
		 */
		public const LIST_MAX_AGE = 300;

		/**
		 * How long a proximity search may be held, in seconds.
		 *
		 * @var int
		 */
		public const SEARCH_MAX_AGE = 60;

		/**
		 * How long a geocoded address may be held, in seconds.
		 *
		 * @var int
		 */
		public const GEOCODE_MAX_AGE = 3600;

		/**
		 * How long a suggestion list may be held, in seconds.
		 *
		 * @var int
		 */
		public const SUGGEST_MAX_AGE = 60;

		/**
		 * What each geocoder failure means over HTTP.
		 *
		 * Keyed on the part of the error code after the per-method prefix, since
		 * geocode() and suggest() raise the same seven failures under
		 * slosm_geocode_* and slosm_suggest_* respectively.
		 *
		 * The three that are decisions rather than transcription:
		 *
		 * - empty_query is 400 and not 422. WordPress answers a schema violation
		 *   with 400, and this is the same class of thing reached by a caller
		 *   that got past the schema — a query of nothing but whitespace, which
		 *   passes minLength and normalises to empty.
		 * - no_results is 404 on /geocode: the caller asked for one place by
		 *   name and there is no such place, which is what a 404 says. On
		 *   /suggest it is not an error at all; see get_suggest().
		 * - everything else is 502. The site is the gateway here, the upstream
		 *   service is what failed, and a 500 would say this plugin broke. It
		 *   also keeps a broken endpoint out of every heuristic cache: 404 is
		 *   heuristically cacheable under RFC 9111 and 502 is not.
		 *
		 * @var array<string, int>
		 */
		public const ERROR_STATUS = array(
			'empty_query'     => 400,
			'no_results'      => 404,
			'rate_limited'    => 429,
			'http'            => 502,
			'bad_status'      => 502,
			'bad_json'        => 502,
			'bad_coordinates' => 502,
		);

		/**
		 * Where locations come from.
		 *
		 * @var Store_Repository|null
		 */
		private ?Store_Repository $repository;

		/**
		 * Where addresses come from.
		 *
		 * Built on first use rather than in the constructor, so that a request
		 * that only ever reads /stores does not construct one.
		 *
		 * @var Geocoder|null
		 */
		private ?Geocoder $geocoder;

		/**
		 * Builds a controller over a repository and a geocoder.
		 *
		 * Both default to the production ones. Store_Repository and Geocoder are
		 * both final, so tests do not double them: they pass a real one built
		 * over its own seams — a fake post loader, a fake clock and sleeper — and
		 * that is the stronger arrangement rather than a workaround, because a
		 * hand-written repository would hand back whatever Stores a test built and
		 * so could never reproduce the partial-Store trap this class exists to
		 * avoid.
		 *
		 * There is deliberately no clock here. An earlier version took one,
		 * because get_suggest() had to answer "would the limiter make me wait"
		 * and did the subtraction itself. That was a second implementation of the
		 * limiter's own reading living in another file, and it had already
		 * drifted: it missed the geocoder's per-instance fallback timestamp, and
		 * it measured against a different seam from the one the limiter uses.
		 * Geocoder::would_throttle() is that question asked of the thing that
		 * knows the answer, and it took the clock with it.
		 *
		 * @param Store_Repository|null $repository Where locations come from.
		 * @param Geocoder|null         $geocoder   Where addresses come from; built on first use when omitted.
		 */
		public function __construct( ?Store_Repository $repository = null, ?Geocoder $geocoder = null ) {
			$this->repository = $repository;
			$this->geocoder   = $geocoder;
		}

		/**
		 * Declares the four routes. Call on rest_api_init and nowhere else.
		 *
		 * Every argument carries a type, a sanitize_callback and a
		 * validate_callback, and the order those run in is worth stating because
		 * it decides what a callback can see. WP_REST_Server::respond_to_request()
		 * calls has_valid_params() and *then* sanitize_params() — verified in
		 * WordPress 6.9.1 — so validation sees the raw value and sanitising
		 * happens afterwards. That is why the validators here are
		 * rest_validate_request_arg rather than anything bespoke: it is the one
		 * that runs the declared schema, minimum and maximum and enum included,
		 * and a hand-written validator would silently drop all three while still
		 * looking like it was validating something.
		 *
		 * @return void
		 */
		public function register_routes(): void {
			register_rest_route(
				self::REST_NAMESPACE,
				'/stores',
				array(
					array(
						'methods'             => 'GET',
						'callback'            => array( $this, 'get_stores' ),
						'permission_callback' => '__return_true',
						'args'                => $this->list_args(),
					),
					'schema' => array( $this, 'get_collection_schema' ),
				)
			);

			register_rest_route(
				self::REST_NAMESPACE,
				'/stores/(?P<id>\d+)',
				array(
					array(
						'methods'             => 'GET',
						'callback'            => array( $this, 'get_store' ),
						'permission_callback' => '__return_true',
						'args'                => array(
							'id' => array(
								'type'              => 'integer',
								'minimum'           => 1,
								'required'          => true,
								'description'       => __( 'Post id of the location.', 'store-locator-for-openstreetmap' ),
								'sanitize_callback' => 'absint',
								'validate_callback' => 'rest_validate_request_arg',
							),
						),
					),
					'schema' => array( $this, 'get_item_schema' ),
				)
			);

			register_rest_route(
				self::REST_NAMESPACE,
				'/geocode',
				array(
					array(
						'methods'             => 'GET',
						'callback'            => array( $this, 'get_geocode' ),
						'permission_callback' => '__return_true',
						'args'                => $this->query_args(
							__( 'Address to look up.', 'store-locator-for-openstreetmap' )
						),
					),
					'schema' => array( $this, 'get_point_schema' ),
				)
			);

			register_rest_route(
				self::REST_NAMESPACE,
				'/suggest',
				array(
					array(
						'methods'             => 'GET',
						'callback'            => array( $this, 'get_suggest' ),
						'permission_callback' => '__return_true',
						'args'                => $this->query_args(
							__( 'Whatever has been typed so far.', 'store-locator-for-openstreetmap' )
						),
					),
					'schema' => array( $this, 'get_suggestions_schema' ),
				)
			);
		}

		/**
		 * The map payload, or the locations near a point.
		 *
		 * Which of the two depends on whether lat and lng were both given.
		 * Neither path returns a whole record: every item is the seven lean
		 * fields plus a distance, because that is what the repository can answer
		 * without reading storage per location, and because the same shape has to
		 * come back whether or not a payload happened to be cached. The class
		 * docblock has what goes wrong when this is built from to_full_array()
		 * instead.
		 *
		 * The limit is applied here rather than passed to find_near(), and the
		 * order matters: a category filter has to run before the list is cut, or
		 * asking for one category with a limit of one returns nothing whenever
		 * the nearest location is in a different category. find_near() is asked
		 * for MAX_LIMIT and costs the same either way — it sorts the whole
		 * candidate set regardless of the limit, and only the final array_slice
		 * is a different size.
		 *
		 * This route cannot be made expensive, and that is worth a sentence
		 * because the same class of problem is live one route over: /suggest
		 * really can be pushed into 86,400 upstream requests a day. The
		 * difference is the cache key. Store_Repository::cache_key() is built
		 * from the prefix, the payload version, the generation and the language
		 * — and from nothing else. lat, lng, radius, limit, category and unit are
		 * all absent from it, so no combination of them can multiply the cached
		 * payloads: a thousand distinct crafted URLs read the one entry every
		 * visitor reads. What varies per request is a filter and a sort over a
		 * list already in memory, with no query and no write. The one path that
		 * touches the database is a proximity search on a cold cache, which is a
		 * single bounded meta_query and is what a genuine first visitor costs
		 * too.
		 *
		 * @param mixed $request Request; a WP_REST_Request in production.
		 * @return \WP_REST_Response
		 */
		public function get_stores( $request ) {
			$lat = $request->get_param( 'lat' );
			$lng = $request->get_param( 'lng' );

			$searching = is_numeric( $lat ) && is_numeric( $lng );

			$limit    = $this->bounded_limit( $request->get_param( 'limit' ) );
			$unit     = $this->unit( $request->get_param( 'unit' ) );
			$category = trim( (string) $request->get_param( 'category' ) );

			if ( $searching ) {
				$radius = is_numeric( $request->get_param( 'radius' ) )
					? (float) $request->get_param( 'radius' )
					: self::DEFAULT_RADIUS;

				$stores = $this->repository()->find_near( (float) $lat, (float) $lng, $radius, self::MAX_LIMIT, $unit );
			} else {
				$stores = $this->repository()->find_all();
			}

			$items = array();

			foreach ( $stores as $store ) {
				// The break comes first, and the two orders are equivalent in
				// result — a filtered-out location emits nothing, so it cannot
				// move the count — but only this one stops. The other folds and
				// compares every remaining location's categories after the
				// answer is already full: 499 wasted iterations at the preload
				// ceiling with limit=1.
				if ( count( $items ) >= $limit ) {
					break;
				}

				if ( '' !== $category && ! $this->has_category( $store, $category ) ) {
					continue;
				}

				// to_lean_array() plus the distance read off the object. The
				// distance is in neither array shape by design; see Store.
				$item = $store->to_lean_array();

				$item['distance'] = null !== $store->distance ? (float) $store->distance : null;

				$items[] = $item;
			}

			$response = new \WP_REST_Response( $items, 200 );

			/*
			 * A plain list is the same bytes for every visitor in a language, so
			 * a shared cache is welcome to hold it. A proximity search is not:
			 * its url carries where the visitor is, to whatever precision their
			 * browser reported, and that does not belong in a cache anybody else
			 * can read. So the search is private — the visitor's own browser,
			 * nobody else's proxy.
			 *
			 * s-maxage is deliberately absent even from the public case. A
			 * multilingual site that decides its language from a cookie rather
			 * than from the url serves two different payloads at one address, and
			 * this plugin cannot know which kind of site it is on; max-age alone
			 * keeps the mistake inside one browser.
			 *
			 * None of this survives a logged-in request, and that is correct:
			 * WP_REST_Server sends wp_get_nocache_headers() whenever
			 * is_user_logged_in(), after the response's own headers, so an
			 * editor checking a change always gets a fresh answer.
			 */
			$response->header(
				'Cache-Control',
				$searching
					? 'private, max-age=' . self::SEARCH_MAX_AGE
					: 'public, max-age=' . self::LIST_MAX_AGE
			);

			return $response;
		}

		/**
		 * One location, whole.
		 *
		 * find_by_id(), which always reads storage. Never the cached payload:
		 * that holds the lean seven fields, so a Store rebuilt from it answers
		 * to_full_array() with a record whose description, phone number, email
		 * and opening hours are empty strings and which looks complete.
		 *
		 * lat_locked is dropped on the way out. It is how the bulk geocoder knows
		 * whether it may overwrite coordinates an editor placed by hand, which is
		 * an admin fact with no meaning to a popup.
		 *
		 * @param mixed $request Request; a WP_REST_Request in production.
		 * @return \WP_REST_Response|\WP_Error
		 */
		public function get_store( $request ) {
			$store = $this->repository()->find_by_id( (int) $request->get_param( 'id' ) );

			if ( null === $store ) {
				/*
				 * One answer for "no such post", "not a location" and "not
				 * published", and they are deliberately not distinguished: a
				 * caller that could tell a draft location from a missing one
				 * could enumerate unpublished content through this route.
				 */
				return new \WP_Error(
					'slosm_store_not_found',
					__( 'No such location.', 'store-locator-for-openstreetmap' ),
					array( 'status' => 404 )
				);
			}

			$record = $store->to_full_array();

			unset( $record['lat_locked'] );

			$response = new \WP_REST_Response( $record, 200 );

			$response->header( 'Cache-Control', 'public, max-age=' . self::LIST_MAX_AGE );

			return $response;
		}

		/**
		 * The coordinates of an address, through the site rather than the browser.
		 *
		 * @param mixed $request Request; a WP_REST_Request in production.
		 * @return \WP_REST_Response|\WP_Error
		 */
		public function get_geocode( $request ) {
			$point = $this->geocoder()->geocode(
				(string) $request->get_param( 'q' ),
				$this->country( $request )
			);

			if ( is_wp_error( $point ) ) {
				return $this->error_response( $point, 'slosm_geocode_' );
			}

			$response = new \WP_REST_Response( $point, 200 );

			// Private, because the url carries an address somebody typed.
			$response->header( 'Cache-Control', 'private, max-age=' . self::GEOCODE_MAX_AGE );

			return $response;
		}

		/**
		 * Address suggestions, and the one route that declines to answer.
		 *
		 * The problem
		 * -----------
		 * Every suggest() cache miss goes through the geocoder's courtesy
		 * limiter, which holds a service to one request per second by calling
		 * usleep(). That is right for a bulk geocode run, where the alternative
		 * is an IP block. It is wrong for an endpoint a browser hits on a
		 * keystroke: ten visitors typing at once is ten PHP-FPM workers asleep
		 * for up to a second each, and a worker pool is a small number. The map
		 * would not be what goes down; the site would.
		 *
		 * The decision
		 * ------------
		 * Skip rather than wait. Before a miss is allowed upstream this asks
		 * Geocoder::would_throttle() the question the limiter is about to answer
		 * for itself — "was this service asked less than MIN_INTERVAL ago" — and
		 * if the answer is yes it returns 204 immediately. Nothing is requested,
		 * nothing sleeps, no worker is held. A missing suggestion is a keystroke
		 * the visitor does not notice; a stalled worker pool is not.
		 *
		 * Why it is decided here rather than given to the geocoder
		 * -------------------------------------------------------
		 * The geocoder has no non-blocking mode and is not being given one. It
		 * could be handed a sleeper that returns without sleeping, and that would
		 * be the wrong fix twice over: the request would still go out, so the
		 * courtesy limit would be broken rather than respected, and the class
		 * whose entire job is to be a good citizen would have been quietly told
		 * not to be. What is needed is not a shorter wait but no request, and the
		 * caller is the only one who knows that this particular caller would
		 * rather have nothing than wait. A bulk geocode run would not.
		 *
		 * would_throttle() is not that mode either. It offers no way to make a
		 * request without waiting its turn; it reports whether a turn is due.
		 * What moved into the geocoder is only the knowledge of how the limiter
		 * reads its own state — which belongs there, and which this class had
		 * already got subtly wrong by reading the option without the geocoder's
		 * per-instance fallback and by measuring against a second clock.
		 *
		 * Why the cache is probed first
		 * ----------------------------
		 * A cache hit never reaches the limiter, so it never blocks and must not
		 * be skipped. Without the probe, every cached prefix typed within a
		 * second of any upstream request would be thrown away too — which is most
		 * of them, since backspacing is how people use an autocomplete. The key
		 * is asked of the geocoder rather than rebuilt, which is what
		 * cache_key() is public for.
		 *
		 * 204 for a skip, 200 with a list for an answer
		 * --------------------------------------------
		 * These are two different things and the client has to be able to tell
		 * them apart: a skip should be retried on the next keystroke, a genuine
		 * miss should show "no matches" and stop asking. An earlier version
		 * answered both with 200 and an empty list, distinguished only by a
		 * Cache-Control of no-store — which does not survive, because
		 * WP_REST_Server *replaces* Cache-Control with wp_get_nocache_headers()
		 * for any logged-in request. For an editor testing the feature the two
		 * answers were byte-identical.
		 *
		 * So a skip is 204 No Content. It survives header replacement, it is the
		 * standard way to say "nothing for you right now", and it leaves the
		 * success shape a bare list, consistent with /stores.
		 *
		 * Two things a client must know, both verified against WordPress 6.9.1:
		 *
		 * - A 204 means "no information, ask again". It never means "no matches".
		 *   Treating it as a miss is the one misreading that turns this from a
		 *   dropped keystroke into a wrong answer on screen.
		 * - The body really is empty. WP_REST_Server returns before
		 *   wp_json_encode() for a 204 — "The 204 response shouldn't have a body"
		 *   — so fetch(...).json() throws on it. Task 14 must branch on
		 *   response.status before touching the body.
		 *
		 * What this does not do
		 * ---------------------
		 * It is not a lock. Two workers can read the option in the same
		 * microsecond, both find the interval elapsed and both go upstream, and
		 * one of them may then sleep; the options API has no compare-and-set to
		 * close that with, and the limiter itself has the same race for the same
		 * reason. What it removes is the systematic case — the tenth visitor in
		 * one second — and leaves a narrow one whose cost is a single worker for
		 * under a second. A corrupt cache entry is the other narrow case: the
		 * probe sees a value, the geocoder rejects it as the wrong shape and goes
		 * upstream after all.
		 *
		 * It does not fix /geocode, which is just as anonymous and can still hold
		 * a worker for up to six seconds — MIN_INTERVAL asleep in usleep() plus
		 * HTTP_TIMEOUT waiting on the outbound request. The asymmetry is
		 * deliberate rather than an oversight: a suggestion nobody gets is a
		 * keystroke nobody notices, while an address lookup that returns nothing
		 * is the whole feature failing in front of somebody who pressed a button
		 * and is watching. /geocode is also one request per search rather than
		 * one per keystroke. But the worker-pool problem is narrowed here, not
		 * solved, and anyone reading this should know which half is which.
		 *
		 * And the skip is a trade, not a solution. It converts a
		 * worker-exhaustion denial into a feature denial: someone pacing distinct
		 * queries at exactly one per second always finds the limiter free, so
		 * their requests go upstream, the option stays continuously fresh, and
		 * every legitimate visitor's uncached prefix is skipped site-wide for as
		 * long as it continues. A degraded autocomplete is plainly better than a
		 * stalled worker pool, so the trade is the right way round — but it is a
		 * trade, and it should be read as one.
		 *
		 * What that costs, measured rather than guessed
		 * ---------------------------------------------
		 * The limiter's ceiling is one request per second, so 86,400 upstream
		 * requests a day, every one of them made in the site's name with the
		 * site's URL in the User-Agent. That is the real damage: it is how an IP
		 * gets blocked by the very service this plugin is at such pains to be a
		 * good citizen towards, and no amount of skipping here prevents it,
		 * because the skip is what lets those requests through unimpeded.
		 *
		 * The database is not the problem, which is worth writing down because it
		 * looks as though it should be. suggest() caches successes only —
		 * verified by running every one of its seven failure modes against the
		 * stubs, all of which write nothing — so a query that matches no place
		 * leaves no row at all. Growing wp_options takes *real addresses*, at two
		 * non-autoloaded rows per distinct one (core's set_transient() with an
		 * expiry is two add_option() calls), which caps at roughly 172,800 rows a
		 * day and expires within a day of the traffic stopping. Non-autoloaded
		 * means alloptions is untouched, so the cost is table size and the daily
		 * expired-transient sweep, not memory on every page load.
		 *
		 * The client's half of this is Task 14's debounce and AbortController —
		 * 600 ms since Task 28a, raised from 300 because the skip above is what
		 * an extra request costs: not a slower answer, but a 204 instead of one.
		 * Neither half is sufficient alone: a debounce is advice to a browser,
		 * and this is the server declining.
		 *
		 * no_results is not an error here
		 * ------------------------------
		 * On /geocode a caller named one place and there is no such place, which
		 * is a 404. On /suggest nothing matching a half-typed string is the
		 * ordinary case, and an autocomplete asking a browser to handle an error
		 * for it is an autocomplete that flickers red while somebody types. It is
		 * 200 with an empty list — a real answer, which is exactly why it is not
		 * the 204 a skip gets.
		 *
		 * A query that is blank after normalising is the one case where the order
		 * above shows: free to ask, it comes back 400 from the geocoder; under
		 * contention it comes back as a 204, because the skip happens first.
		 * Both refuse without a request. Note that the schema does *not* prevent
		 * this in production, which an earlier version of this comment claimed:
		 * minLength validates the raw value and sanitize_text_field() runs
		 * afterwards, so q=%20 passes validation and arrives here as ''. It is
		 * pinned by a test so it cannot change without somebody meaning it.
		 *
		 * @param mixed $request Request; a WP_REST_Request in production.
		 * @return \WP_REST_Response|\WP_Error
		 */
		public function get_suggest( $request ) {
			$query    = (string) $request->get_param( 'q' );
			$country  = $this->country( $request );
			$geocoder = $this->geocoder();

			if ( ! $this->suggestions_are_cached( $geocoder, $query, $country )
				&& $geocoder->would_throttle( Geocoder::SERVICE_PHOTON )
			) {
				return $this->skipped();
			}

			$suggestions = $geocoder->suggest( $query, $country );

			if ( is_wp_error( $suggestions ) ) {
				if ( 'slosm_suggest_no_results' === $suggestions->get_error_code() ) {
					return $this->no_suggestions();
				}

				return $this->error_response( $suggestions, 'slosm_suggest_' );
			}

			$response = new \WP_REST_Response( array_values( $suggestions ), 200 );

			// Private, because the url carries what somebody was typing.
			$response->header( 'Cache-Control', 'private, max-age=' . self::SUGGEST_MAX_AGE );

			return $response;
		}

		/**
		 * The response schema for one location.
		 *
		 * lat and lng are array( 'number', 'null' ), not 'number', and that is the
		 * whole reason this method is worth its length. A location with no
		 * coordinates is a normal state — an address an editor has typed but not
		 * geocoded yet — and it is the state the admin warning exists to point
		 * at. A schema saying 'number' would describe a record this route
		 * genuinely returns as invalid.
		 *
		 * lat_locked is absent because get_store() does not send it.
		 *
		 * @return array
		 */
		public function get_item_schema(): array {
			return array(
				'$schema'    => 'http://json-schema.org/draft-04/schema#',
				'title'      => 'slosm_store',
				'type'       => 'object',
				'properties' => array(
					'id'          => array(
						'type'        => 'integer',
						'description' => __( 'Post id of the location.', 'store-locator-for-openstreetmap' ),
					),
					'name'        => array(
						'type'        => 'string',
						'description' => __( 'Location name.', 'store-locator-for-openstreetmap' ),
					),
					'description' => array(
						'type'        => 'string',
						'description' => __( 'Description.', 'store-locator-for-openstreetmap' ),
					),
					'address'     => array(
						'type'        => 'string',
						'description' => __( 'Street address.', 'store-locator-for-openstreetmap' ),
					),
					'address2'    => array(
						'type'        => 'string',
						'description' => __( 'Second address line.', 'store-locator-for-openstreetmap' ),
					),
					'city'        => array(
						'type'        => 'string',
						'description' => __( 'City.', 'store-locator-for-openstreetmap' ),
					),
					'state'       => array(
						'type'        => 'string',
						'description' => __( 'State, region or voivodeship.', 'store-locator-for-openstreetmap' ),
					),
					'zip'         => array(
						'type'        => 'string',
						'description' => __( 'Postal code.', 'store-locator-for-openstreetmap' ),
					),
					'country'     => array(
						'type'        => 'string',
						'description' => __( 'Country.', 'store-locator-for-openstreetmap' ),
					),
					'lat'         => array(
						'type'        => array( 'number', 'null' ),
						'description' => __( 'Latitude, or null when the location has not been placed.', 'store-locator-for-openstreetmap' ),
					),
					'lng'         => array(
						'type'        => array( 'number', 'null' ),
						'description' => __( 'Longitude, or null when the location has not been placed.', 'store-locator-for-openstreetmap' ),
					),
					'phone'       => array(
						'type'        => 'string',
						'description' => __( 'Phone number.', 'store-locator-for-openstreetmap' ),
					),
					'email'       => array(
						'type'        => 'string',
						'description' => __( 'Email address.', 'store-locator-for-openstreetmap' ),
					),
					'url'         => array(
						'type'        => 'string',
						'description' => __( 'Website url.', 'store-locator-for-openstreetmap' ),
					),
					'hours'       => array(
						'type'        => 'string',
						'description' => __( 'Opening hours, free text, newlines significant.', 'store-locator-for-openstreetmap' ),
					),
					'categories'  => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => __( 'Category names.', 'store-locator-for-openstreetmap' ),
					),
				),
			);
		}

		/**
		 * The response schema for the list.
		 *
		 * Seven fields and a distance, which is what a marker and a result row
		 * need. It is a different shape from the single location on purpose; the
		 * class docblock has why the list is not the whole record.
		 *
		 * @return array
		 */
		public function get_collection_schema(): array {
			$item = $this->get_item_schema();

			return array(
				'$schema' => 'http://json-schema.org/draft-04/schema#',
				'title'   => 'slosm_stores',
				'type'    => 'array',
				'items'   => array(
					'type'       => 'object',
					'properties' => array(
						'id'         => $item['properties']['id'],
						'name'       => $item['properties']['name'],
						'lat'        => $item['properties']['lat'],
						'lng'        => $item['properties']['lng'],
						'address'    => $item['properties']['address'],
						'city'       => $item['properties']['city'],
						'categories' => $item['properties']['categories'],
						'distance'   => array(
							'type'        => array( 'number', 'null' ),
							'description' => __( 'Distance from the point searched from, in the unit that search used; null when no point was given.', 'store-locator-for-openstreetmap' ),
						),
					),
				),
			);
		}

		/**
		 * The response schema for a geocoded address.
		 *
		 * Declared so that /wp-json/slosm/v1 can describe this route like the
		 * other two. lat and lng are plain numbers here, not nullable as they are
		 * on a location: a point that could not be read is an error, never a
		 * success carrying nulls — the geocoder raises bad_coordinates rather
		 * than returning a half-placed answer.
		 *
		 * @return array
		 */
		public function get_point_schema(): array {
			return array(
				'$schema'    => 'http://json-schema.org/draft-04/schema#',
				'title'      => 'slosm_point',
				'type'       => 'object',
				'properties' => array(
					'lat'   => array(
						'type'        => 'number',
						'description' => __( 'Latitude of the matched place.', 'store-locator-for-openstreetmap' ),
					),
					'lng'   => array(
						'type'        => 'number',
						'description' => __( 'Longitude of the matched place.', 'store-locator-for-openstreetmap' ),
					),
					'label' => array(
						'type'        => 'string',
						'description' => __( 'What the service calls the matched place, or the query when it gave no name.', 'store-locator-for-openstreetmap' ),
					),
				),
			);
		}

		/**
		 * The response schema for an address suggestion list.
		 *
		 * Describes the 200 only. A skip is a 204 with no body at all, which is
		 * not a shape a schema can carry; get_suggest() has what a client must do
		 * about that.
		 *
		 * @return array
		 */
		public function get_suggestions_schema(): array {
			$point = $this->get_point_schema();

			return array(
				'$schema' => 'http://json-schema.org/draft-04/schema#',
				'title'   => 'slosm_suggestions',
				'type'    => 'array',
				'items'   => array(
					'type'       => 'object',
					'properties' => array(
						'label' => array(
							'type'        => 'string',
							'description' => __( 'The line shown in the dropdown.', 'store-locator-for-openstreetmap' ),
						),
						'lat'   => $point['properties']['lat'],
						'lng'   => $point['properties']['lng'],
					),
				),
			);
		}

		/**
		 * The arguments /stores accepts.
		 *
		 * Every bound is here rather than in the callback, so a request outside
		 * one is refused by WordPress with a message naming the parameter and the
		 * limit — rather than silently turned into something else, which is how a
		 * visitor ends up wondering why a 3,000 km radius found four shops.
		 *
		 * @return array
		 */
		private function list_args(): array {
			return array(
				'lat'      => array(
					'type'              => 'number',
					'minimum'           => -90.0,
					'maximum'           => 90.0,
					'description'       => __( 'Latitude to search from.', 'store-locator-for-openstreetmap' ),
					'sanitize_callback' => 'rest_sanitize_request_arg',
					'validate_callback' => 'rest_validate_request_arg',
				),
				'lng'      => array(
					'type'              => 'number',
					'minimum'           => -180.0,
					'maximum'           => 180.0,
					'description'       => __( 'Longitude to search from.', 'store-locator-for-openstreetmap' ),
					'sanitize_callback' => 'rest_sanitize_request_arg',
					'validate_callback' => 'rest_validate_request_arg',
				),
				'radius'   => array(
					'type'              => 'number',
					'minimum'           => 0.0,
					'maximum'           => self::MAX_RADIUS,
					'default'           => self::DEFAULT_RADIUS,
					'description'       => __( 'Search radius, in the requested unit.', 'store-locator-for-openstreetmap' ),
					'sanitize_callback' => 'rest_sanitize_request_arg',
					'validate_callback' => 'rest_validate_request_arg',
				),
				'limit'    => array(
					'type'              => 'integer',
					'minimum'           => 1,
					'maximum'           => self::MAX_LIMIT,
					'default'           => self::MAX_LIMIT,
					'description'       => __( 'How many locations at most.', 'store-locator-for-openstreetmap' ),
					'sanitize_callback' => 'rest_sanitize_request_arg',
					'validate_callback' => 'rest_validate_request_arg',
				),
				'category' => array(
					'type'              => 'string',
					'default'           => '',
					'description'       => __( 'Category name to filter by.', 'store-locator-for-openstreetmap' ),
					'sanitize_callback' => 'sanitize_text_field',
					'validate_callback' => 'rest_validate_request_arg',
				),
				'unit'     => array(
					'type'              => 'string',
					'enum'              => Geo::UNITS,
					'default'           => 'km',
					'description'       => __( 'Unit distances are measured in.', 'store-locator-for-openstreetmap' ),
					'sanitize_callback' => 'sanitize_text_field',
					'validate_callback' => 'rest_validate_request_arg',
				),
			);
		}

		/**
		 * The arguments the two proxied lookups accept.
		 *
		 * q is required and minLength 1, so WordPress refuses an empty query
		 * before this class is reached and long before anything is asked of
		 * OpenStreetMap. maxLength is not politeness either: the query goes into
		 * a url and into a cache key, and an unbounded one is an unbounded
		 * request made in the site's name.
		 *
		 * @param string $description What q means on this route.
		 * @return array
		 */
		private function query_args( string $description ): array {
			return array(
				'q'       => array(
					'type'              => 'string',
					'required'          => true,
					'minLength'         => 1,
					'maxLength'         => 200,
					'description'       => $description,
					'sanitize_callback' => 'sanitize_text_field',
					'validate_callback' => 'rest_validate_request_arg',
				),
				'country' => array(
					'type'              => 'string',
					'default'           => '',
					'maxLength'         => 64,
					'description'       => __( 'ISO 3166-1 alpha-2 country code, or several comma separated.', 'store-locator-for-openstreetmap' ),
					'sanitize_callback' => 'sanitize_text_field',
					'validate_callback' => 'rest_validate_request_arg',
				),
			);
		}

		/**
		 * Which countries this lookup is restricted to.
		 *
		 * The request first, then the site setting, and that order is the whole
		 * of it: a caller that names a country means it, and a caller that names
		 * none gets whatever the site has decided rather than the whole world.
		 *
		 * The fallback is applied here, on the server, rather than sent to the
		 * front end and passed back. Shortcode::config() says why: a restriction
		 * that travelled through the browser would be a restriction a visitor
		 * could edit out of a query string, and a second copy of the rule in the
		 * one place nobody controls.
		 *
		 * Settings::sanitise() has already reduced the setting to lowercase
		 * two-letter codes, which is the countrycodes format Nominatim wants;
		 * the request's own value has been through sanitize_text_field() and
		 * maxLength 64 and nothing more, exactly as before, because Geocoder
		 * normalises it again on the way to the wire.
		 *
		 * @param mixed $request Request; a WP_REST_Request in production.
		 * @return string
		 */
		private function country( $request ): string {
			$asked = $request->get_param( 'country' );
			$asked = is_scalar( $asked ) ? trim( (string) $asked ) : '';

			return '' === $asked ? (string) Settings::get( 'country' ) : $asked;
		}
		/**
		 * "Nothing for you right now" — a skip, not an answer.
		 *
		 * 204, and the status is what carries the meaning, because it is the only
		 * part of this that a logged-in request cannot lose: WP_REST_Server
		 * replaces Cache-Control with wp_get_nocache_headers() whenever
		 * is_user_logged_in(), so a header could not have told a skip from a
		 * miss for exactly the people most likely to be testing the feature.
		 *
		 * no-store is still sent for the anonymous case it does survive. A skip
		 * cached even briefly is a browser that keeps showing nothing for a
		 * prefix that would have matched.
		 *
		 * The body is genuinely empty: core returns before wp_json_encode() for a
		 * 204. See get_suggest() for what that obliges a client to do.
		 *
		 * @return \WP_REST_Response
		 */
		private function skipped(): \WP_REST_Response {
			$response = new \WP_REST_Response( null, 204 );

			$response->header( 'Cache-Control', 'no-store' );

			return $response;
		}

		/**
		 * An empty suggestion list: a real answer, and the answer is none.
		 *
		 * 200 rather than the skip's 204, because this is information — the
		 * service was asked and nothing matched — and a client should stop asking
		 * rather than retry on the next keystroke.
		 *
		 * no-store, because the geocoder does not cache a failure either: nothing
		 * matching a prefix today is not a fact worth keeping for a minute, and a
		 * browser holding it would go on showing nothing after the location it
		 * was looking for was added.
		 *
		 * @return \WP_REST_Response
		 */
		private function no_suggestions(): \WP_REST_Response {
			$response = new \WP_REST_Response( array(), 200 );

			$response->header( 'Cache-Control', 'no-store' );

			return $response;
		}

		/**
		 * Whether a suggestion list for this query is already in the cache.
		 *
		 * A loose question on purpose: this only decides whether the limiter is
		 * worth consulting, and the geocoder still checks the shape of whatever
		 * is under the key before trusting it. The key comes from the geocoder
		 * rather than being rebuilt here, so a change to how queries are
		 * normalised cannot leave this asking about a different entry.
		 *
		 * @param Geocoder $geocoder Geocoder the lookup would go through.
		 * @param string   $query    Raw query.
		 * @param string   $country  Raw country.
		 * @return bool
		 */
		private function suggestions_are_cached( Geocoder $geocoder, string $query, string $country ): bool {
			return false !== get_transient( $geocoder->cache_key( Geocoder::SERVICE_PHOTON, $query, $country ) );
		}

		/**
		 * One geocoder failure as a response, with nothing of the upstream in it.
		 *
		 * Three things leave this method and one thing does not. The code goes
		 * out, because it names which of seven things went wrong and none of the
		 * seven names anything about the site. A generic message goes out, written
		 * here rather than forwarded: the geocoder's own messages are literals
		 * today and carry nothing, and writing our own is what keeps that true the
		 * day one of them starts interpolating an upstream string. A status goes
		 * out. The error *data* does not — that is where upstream_code and
		 * upstream_message live, and upstream_message can name a host inside the
		 * client's own network.
		 *
		 * The data is not thrown away, it is announced, because a dns failure
		 * nobody can see is a support ticket that says "the map does not work".
		 *
		 * Reading it has to tolerate null: bad_json is raised from two places,
		 * once by request() with an upstream_status attached and once by geocode()
		 * itself with no data at all, and so are bad_coordinates, empty_query and
		 * no_results.
		 *
		 * @param \WP_Error $error  Error the geocoder returned.
		 * @param string    $prefix Per-method code prefix, 'slosm_geocode_' or 'slosm_suggest_'.
		 * @return \WP_Error A new error carrying only a code, a generic message and a status.
		 */
		private function error_response( \WP_Error $error, string $prefix ): \WP_Error {
			$code = (string) $error->get_error_code();

			$suffix = 0 === strpos( $code, $prefix ) ? substr( $code, strlen( $prefix ) ) : '';

			// Anything unrecognised is 502 rather than 500: an unexpected code is
			// still a failure of the service this route is a gateway to.
			$status = self::ERROR_STATUS[ $suffix ] ?? 502;

			// 400 is the caller's own doing and 404 is a normal negative answer;
			// neither is worth a line in a log. Everything else is the upstream
			// service failing, and that is exactly what a site owner needs to see.
			if ( 400 !== $status && 404 !== $status ) {
				$this->log_upstream_failure( $code, $error->get_error_data() );
			}

			return new \WP_Error( $code, $this->message_for( $status ), array( 'status' => $status ) );
		}

		/**
		 * The one sentence a caller is told.
		 *
		 * @param int $status Status the failure mapped to.
		 * @return string
		 */
		private function message_for( int $status ): string {
			if ( 400 === $status ) {
				return __( 'There is no address to look up.', 'store-locator-for-openstreetmap' );
			}

			if ( 404 === $status ) {
				return __( 'No place matched that address.', 'store-locator-for-openstreetmap' );
			}

			if ( 429 === $status ) {
				return __( 'The address service is asking for fewer requests. Try again in a moment.', 'store-locator-for-openstreetmap' );
			}

			return __( 'The address service could not answer. Try again later.', 'store-locator-for-openstreetmap' );
		}

		/**
		 * Records what the caller was not told.
		 *
		 * The hook is the whole of it, and an error_log() call behind WP_DEBUG
		 * was removed rather than kept: it carried nothing the hook does not
		 * already carry unconditionally, it needed a phpcs:ignore for a sniff
		 * WordPress.org's Plugin Check raises, and it was a branch no test in
		 * this suite could reach. Whoever wants a file gets one by hooking this
		 * and calling their own logger; readme.txt is where that is documented
		 * at Task 25.
		 *
		 * Note that the data is deliberately passed whole, upstream hostname and
		 * all. Withholding it from an anonymous HTTP caller is the point;
		 * withholding it from the site owner debugging their own installation
		 * would make the failure invisible instead of private.
		 *
		 * @param string $code Error code the geocoder raised.
		 * @param mixed  $data Error data; null for the failures that carry none.
		 * @return void
		 */
		private function log_upstream_failure( string $code, $data ): void {
			/**
			 * Fires when a lookup failed and the detail was withheld from the response.
			 *
			 * The data can name the upstream host, which is why it does not reach
			 * an anonymous caller. It is passed here whole, so a site's own
			 * logger can have it.
			 *
			 * @param string $code Error code the geocoder raised.
			 * @param mixed  $data Error data; null for the failures that carry none.
			 */
			do_action( 'slosm_rest_upstream_failed', $code, $data );
		}

		/**
		 * Whether a location carries a category, by name.
		 *
		 * Names, because names are what the payload carries — the repository
		 * plucks 'name' off each term — and what the front end's filter is built
		 * from.
		 *
		 * WHY THIS IS NOT strcasecmp(), WHICH IT WAS UNTIL TASK 15
		 * -------------------------------------------------------
		 * There are two implementations of this comparison now: this one, and
		 * inCategory() in assets/js/locator.js, which filters a preloaded list
		 * in the browser. They have to give the same answer for the same reason
		 * Geo and its JavaScript copy do — a site whose locations are filtered
		 * by the server below the preload threshold and by the browser above it
		 * must not show two different maps across a number no visitor can see.
		 *
		 * strcasecmp() folds ASCII bytes and nothing else, measured on the
		 * binaries this suite runs on: strcasecmp( 'BAKERY', 'bakery' ) is 0 and
		 * strcasecmp( 'ŻŁOBKI', 'żłobki' ) is not. JavaScript's toLowerCase()
		 * folds the whole of Unicode. So the two agreed on ASCII names and
		 * disagreed on every other alphabet — which is most of the ones this
		 * plugin is written for.
		 *
		 * mb_strtolower() is what JavaScript does, so it is what this does.
		 *
		 * WHAT THE FALLBACK COSTS, EXACTLY
		 * --------------------------------
		 * Behind function_exists(), for the reason Geocoder::normalise() gives
		 * in full: mbstring is not a guaranteed extension on a WordPress host,
		 * and an unguarded call on one without it is a fatal error rather than a
		 * notice. The CLI binaries this suite runs on are such a host — the test
		 * command passes -n, which is why this branch is reachable from a test
		 * at all rather than being a line nobody has ever executed.
		 *
		 * On a host with no mbstring the two implementations part company again
		 * for non-ASCII names, and pretending otherwise would be worse than
		 * saying so. Folding non-ASCII by hand is a table of locale rules this
		 * plugin has no business carrying, and the alternative — narrowing the
		 * *browser* to ASCII so the two agree everywhere — buys agreement by
		 * making the filter wrong for Polish, Greek and Turkish names on every
		 * host rather than on the few without mbstring.
		 *
		 * @param Store  $store    Location.
		 * @param string $category Category name asked for.
		 * @return bool
		 */
		private function has_category( Store $store, string $category ): bool {
			$wanted = $this->fold( $category );

			foreach ( $store->categories as $name ) {
				if ( $this->fold( $name ) === $wanted ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * One category name, folded the way the browser folds it.
		 *
		 * Its own method so that the branch is one decision in one place rather
		 * than one per comparison, and so a case can state which of the two
		 * contracts the host it is running on is under.
		 *
		 * @param string $name A category name.
		 * @return string
		 */
		private function fold( string $name ): string {
			return function_exists( 'mb_strtolower' ) ? mb_strtolower( $name, 'UTF-8' ) : strtolower( $name );
		}

		/**
		 * The limit to apply, whatever arrived.
		 *
		 * The schema is what refuses an out-of-range value, and this is what
		 * happens when there was no schema — a callback called directly, by a
		 * test or by something inside the plugin. It defaults to MAX_LIMIT rather
		 * than to something smaller so that /stores with no arguments is still the
		 * map payload.
		 *
		 * @param mixed $limit Raw value.
		 * @return int
		 */
		private function bounded_limit( $limit ): int {
			if ( ! is_numeric( $limit ) ) {
				return self::MAX_LIMIT;
			}

			$limit = (int) $limit;

			if ( 1 > $limit ) {
				return 0;
			}

			return min( $limit, self::MAX_LIMIT );
		}

		/**
		 * The unit to measure in.
		 *
		 * The is_string() half is load-bearing and the in_array() half is not,
		 * and saying so is better than implying otherwise. find_near() declares
		 * a string parameter, so a unit that arrived as null — which is what a
		 * caller outside the schema gives, and the schema's default is the only
		 * thing that stops it — is a TypeError on a front-end search rather than
		 * a wrong answer. That is what the type check prevents.
		 *
		 * The membership check prevents nothing that can be observed today: Geo
		 * treats anything that is not 'mi' as kilometres, in the bounding box and
		 * in the distance alike, so 'furlongs' and 'km' already travel the same
		 * path and no test can tell a version without this check from one with
		 * it. It is kept because it is the statement, at the boundary, of which
		 * units this route accepts, and because the alternative reads as though
		 * the route forwarded whatever it was handed and got lucky. The copy
		 * that does the enforcing is the schema's enum, which is asserted; this
		 * one is documentation with a return value, and a mutation deleting it
		 * is correctly recorded as equivalent rather than as a gap.
		 *
		 * @param mixed $unit Raw value.
		 * @return string
		 */
		private function unit( $unit ): string {
			return is_string( $unit ) && in_array( $unit, Geo::UNITS, true ) ? $unit : 'km';
		}

		/**
		 * The repository, built on first use.
		 *
		 * @return Store_Repository
		 */
		private function repository(): Store_Repository {
			if ( null === $this->repository ) {
				$this->repository = new Store_Repository();
			}

			return $this->repository;
		}

		/**
		 * The geocoder, built on first use.
		 *
		 * @return Geocoder
		 */
		private function geocoder(): Geocoder {
			if ( null === $this->geocoder ) {
				$this->geocoder = new Geocoder();
			}

			return $this->geocoder;
		}
	}
}
