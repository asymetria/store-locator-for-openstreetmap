/**
 * Store Locator for OpenStreetMap: the front end.
 *
 * A classic script, not a module, and that is a floor rather than a taste: the
 * plugin supports WordPress 6.0, which has no script-modules API, so `type`
 * would have to be patched onto the tag by hand. Assets registers this file
 * with `strategy => defer`, which gets the same "runs after the parser, does
 * not block it" behaviour with none of that.
 *
 * ONE GLOBAL, AND WHY IT IS CODE RATHER THAN DATA
 * ==============================================
 * `window.SLOSM` is the whole surface: constants, the distance maths, the
 * config reader, the url builder and the two entry points. Settings are not in
 * it and must not be. Shortcode's docblock has the argument in full — two
 * locators on one page cannot share a global without inventing an index, an id
 * or a registry — and it holds here unchanged. What is in this object is the
 * same for every locator on every page, which is the exact test a global has
 * to pass.
 *
 * It is frozen, top to bottom. A page with a dozen plugins on it is a page
 * where `window.SLOSM.TILE.options.attribution = ''` is one careless
 * minifier away, and the OpenStreetMap tile usage policy and the ODbL both
 * require that line to be on the map.
 *
 * WHAT THIS FILE DOES NOT DO
 * ==========================
 * Task 13 drew the map, the markers and the framing. Task 14 added the search:
 * the field, the suggestions behind a debounce, the address lookup, the
 * results list and the highlight that ties a row to its pin. Task 15 added
 * "near me", the category filter and the clustering. Task 16 added the popup
 * on a pin, the record fetch behind it and the way out to openstreetmap.org,
 * which is what the button on each row and the link beside it now do.
 *
 * The radius and result-count selects the shortcode renders are still inert,
 * and belong to no task yet — a front end that started reading them would be
 * shipping a control whose behaviour nobody has decided. Everything admin-side
 * is Stage 4 and nothing here knows about it.
 *
 * WHAT A DENIED LOCATION IS, AND IS NOT
 * =====================================
 * Most visitors decline a location prompt. That is the ordinary answer to the
 * question, not a failure, and a locator that turns red and throws away a
 * perfectly good list of branches over it is a locator that looks broken to
 * most of the people who press the button. So the four outcomes of asking are
 * four different things here — waiting, declined, could not be worked out, and
 * this browser cannot — with four sentences, and only the third of them is an
 * error. None of them destroys results that are already on screen; see
 * locateMe().
 *
 * THE DEBOUNCE, AND WHAT IT IS ACTUALLY WORTH
 * ===========================================
 * It is not about flicker. Every /suggest cache miss can hold a PHP worker
 * behind the geocoder's one-request-per-second courtesy limiter —
 * Rest_Controller::get_suggest() has the whole argument — and a worker pool is
 * a small number, so a field that asked on every keystroke would be an attack
 * on the site mounted by its own visitors.
 *
 * But it is not "the protection", and calling it that would be a claim this
 * file cannot keep. A debounce bounds how *bursty* one field can be inside one
 * window; it bounds nothing about the sustained rate, and ten locators on a
 * page are ten independent timers with no idea of one another. What actually
 * bounds the upstream work is on the server, where it has to be:
 * Geocoder::would_throttle() refuses to queue behind the limiter and answers
 * 204. The client's job is to be cheap and honest — debounce the burst, cancel
 * what has been overtaken, refuse a one-character query, and never turn a 204
 * into a retry storm — and the server's job is the ceiling.
 *
 * And /suggest answers 204 when it declined to ask upstream at all. A 204
 * means "no information, ask again"; it never means "no matches", and it
 * carries no body, so `response.json()` on one rejects. The status is read
 * before the body is touched.
 *
 * SERVER DATA NEVER MEETS A PARSER
 * ================================
 * `textContent` everywhere, `innerHTML` nowhere. The sanitisation this plugin
 * does is escaping at output in PHP, and none of that reaches here: json
 * carries the raw characters, and a post title written by a user with
 * unfiltered_html legitimately contains html. So `innerHTML` with a location
 * name in it is stored xss with extra steps. The same goes for slosmL10n,
 * which is a .po file somebody may have edited.
 */

( function ( window ) {
	'use strict';

	var document = window.document;

	/**
	 * Geo, as a copy of includes/class-geo.php.
	 *
	 * A copy, not an interface: when the list is preloaded the browser sorts
	 * by distance itself, so this arithmetic and the server's have to be the
	 * same arithmetic. tests/js/geo-crosscheck.test.js reads the PHP source
	 * and fails if the two drift, including if somebody edits the formula on
	 * one side and not the other.
	 *
	 * The mile here is 6371.0 / 3958.8 = 1.6093261, not the statute 1.609344.
	 * That is 18 metres per mile wrong on purpose: Geo's docblock explains
	 * that the box and the distance agreeing with each other matters more than
	 * either agreeing with a standards body, and a browser that "corrected" it
	 * would sort a preloaded list into a different order from the one /stores
	 * returns.
	 *
	 * Nothing in this namespace is called from the front end yet — not
	 * BOX_MARGIN, not MIN_COSINE, and not distance() either. The whole of Geo
	 * is dead weight at Task 13, and it is here anyway for one reason: Task 14
	 * sorts a preloaded list in the browser, and that is the moment the two
	 * implementations have to already agree. Writing it now means the
	 * cross-check exists before the first sort does, rather than after the
	 * first bug report. A *partial* copy would be worse than none — somebody
	 * retypes the missing constant from memory, which is exactly how
	 * KM_PER_DEGREE became a table value on the PHP side — so the copy is
	 * whole and the cross-check covers all of it.
	 */
	var Geo = Object.freeze( {
		EARTH_RADIUS_KM: 6371.0,
		EARTH_RADIUS_MI: 3958.8,
		KM_PER_DEGREE: ( 6371.0 * Math.PI ) / 180.0,
		BOX_MARGIN: 1.001,
		MIN_COSINE: 1.0e-6,
		UNITS: Object.freeze( [ 'km', 'mi' ] ),

		/**
		 * Great-circle distance by the haversine formula.
		 *
		 * Line for line what Geo::distance() computes, clamp included, and the
		 * clamp is load-bearing rather than insurance. Math.asin() of anything
		 * above 1 is NaN, and NaN is the silent failure: it compares false
		 * against every radius filter, so a location vanishes from the results
		 * with no error anywhere.
		 *
		 * An earlier version of this docblock claimed the clamp was never
		 * reached on this engine, on the strength of a sweep of 400,000
		 * "near-antipodal" pairs that found no Math.sqrt( a ) above 1. That
		 * sweep was wrong, and the way it was wrong is worth keeping: it walked
		 * *exact* antipodes — lat2 = -lat1, lng2 = lng1 ± 180 — and exact
		 * antipodes produce zero hits, because the two terms cancel to
		 * something the rounding brings back to 1. Perturb the antipode by
		 * about 1e-13 degrees and the hits appear: 305 per 400,000 on V8, 230
		 * on both PHP binaries, all of them NaN without the clamp.
		 *
		 * So there is a real input, and each suite pins one. This engine's:
		 *
		 *   -61.408951158847145, 168.867989163019,
		 *    61.40895115884711,  -11.132010836981044
		 *
		 * gives a = 1.0000000000000004, sqrt( a ) = 1.0000000000000002, and
		 * 20015.086796020572 km clamped against NaN unclamped. The literal is
		 * engine-specific — it comes out to exactly 1 under PHP's libm, and
		 * PHP's own literal comes out to exactly 1 here — so tests/test-geo.php
		 * carries a different pair for the same reason.
		 *
		 * @param {number} lat1 Latitude of the first point, in degrees.
		 * @param {number} lng1 Longitude of the first point, in degrees.
		 * @param {number} lat2 Latitude of the second point, in degrees.
		 * @param {number} lng2 Longitude of the second point, in degrees.
		 * @param {string} unit 'mi' for miles; anything else means kilometres.
		 * @returns {number} Distance in the requested unit.
		 */
		distance: function ( lat1, lng1, lat2, lng2, unit ) {
			var radius = 'mi' === unit ? Geo.EARTH_RADIUS_MI : Geo.EARTH_RADIUS_KM;
			var dLat = ( ( lat2 - lat1 ) * Math.PI ) / 180;
			var dLng = ( ( lng2 - lng1 ) * Math.PI ) / 180;

			var a =
				Math.sin( dLat / 2 ) ** 2 +
				Math.cos( ( lat1 * Math.PI ) / 180 ) *
					Math.cos( ( lat2 * Math.PI ) / 180 ) *
					Math.sin( dLng / 2 ) ** 2;

			return radius * 2 * Math.asin( Math.min( 1.0, Math.sqrt( a ) ) );
		},
	} );

	/**
	 * The zoom range, as Shortcode declares it.
	 *
	 * Three numbers that exist on both sides of the wire, so they are pinned
	 * against Shortcode::MIN_ZOOM, MAX_ZOOM and DEFAULT_ZOOM in
	 * tests/js/locator.test.js rather than merely commented.
	 *
	 * `fallback` is reached only by a config somebody wrote by hand, since
	 * Shortcode::bounded_int() guarantees an integer in range. It is here
	 * because Leaflet takes an undefined zoom without complaint and then
	 * computes NaN out of it, and a map at NaN is a blank rectangle — the one
	 * outcome this file is written to prevent.
	 *
	 * `max` is 19 because that is as far as the standard OpenStreetMap tile
	 * layer renders. Leaflet will accept 22 and the tile server answers 404
	 * for every tile, which reads as a broken plugin.
	 */
	var ZOOM = Object.freeze( { min: 1, max: 19, fallback: 12 } );

	/**
	 * How long the field waits after a keystroke before asking /suggest.
	 *
	 * 600 milliseconds, and **waiting longer to start is how the answer arrives
	 * at all**, which reads as a mistake until the server side is in view. It
	 * was 300 until Task 28a.
	 *
	 * What is on the server, read rather than remembered
	 * --------------------------------------------------
	 * `Rest_Controller::get_suggest()` does *not* queue. On a cache miss it asks
	 * `Geocoder::would_throttle( SERVICE_PHOTON )` — "was Photon asked less than
	 * `MIN_INTERVAL` (1.0 s) ago" — and if the answer is yes it returns **204
	 * and makes no request at all**. `askSuggestions()` treats that 204 by
	 * closing the popup, correctly, because a 204 is "no information" rather
	 * than "no matches". So the cost of an extra request is not a second added
	 * to the next one's latency. It is the next one getting nothing.
	 *
	 * That timestamp is one option per service and it is site-wide, so it is not
	 * only this visitor's own earlier keystroke that silences the one they care
	 * about. (It is genuinely per service: `last_request_option()` appends the
	 * name, so /geocode's Nominatim clock and /suggest's Photon clock never
	 * touch each other, and a search does not silence a suggestion or the
	 * reverse.)
	 *
	 * Why 600 rather than 300
	 * -----------------------
	 * A debounce restarts on every keystroke, so it fires once per *pause*
	 * longer than the delay. Two requests therefore land at least one pause plus
	 * the intervening typing apart — which at 300 ms is routinely under the 1.0 s
	 * the server measures against, and at 600 ms is routinely over it. Doubling
	 * the delay both removes mid-word pauses from the count and widens the gap
	 * between the ones that remain, and the gap is the thing `would_throttle()`
	 * actually tests. The request whose answer the visitor wanted is the last
	 * one, and this is what stops it being the one that gets a 204.
	 *
	 * What it costs, stated because it is not free
	 * --------------------------------------------
	 * 300 ms more before an answer that was going to arrive anyway. This does
	 * *not* make a cold lookup faster — `MIN_INTERVAL` is never waited out on
	 * this route, so there is no queue to shorten, and the 3–4 s a first install
	 * measured is the REST bootstrap plus the upstream round trip, neither of
	 * which a debounce can touch. What it buys is answered keystrokes instead of
	 * silently dropped ones, and fewer requests made in the site's name against
	 * a free service.
	 *
	 * The file header has why this number is a wall rather than a polish step,
	 * and why it is not *the* wall.
	 *
	 * @var {number}
	 */
	var SUGGEST_DELAY = 600;

	/**
	 * The shortest thing worth asking a geocoding service about.
	 *
	 * Two characters, not three. One character cannot disambiguate an address
	 * anywhere and is a worker held for nothing; two can — Ås in Norway, Ho in
	 * Ghana, and every postcode prefix in Britain — so this is the lowest
	 * threshold that throws away only useless requests.
	 *
	 * It applies to the *suggestions* and to nothing else. Enter still looks up
	 * whatever is in the field, because /geocode's own minLength is 1 and a
	 * person who typed one character and pressed Enter has asked a question
	 * rather than paused mid-word.
	 *
	 * @var {number}
	 */
	var MIN_QUERY = 2;

	/**
	 * How long to let the browser look for a position before giving up.
	 *
	 * Ten seconds. Without a timeout getCurrentPosition can wait indefinitely —
	 * a device with no fix indoors does exactly that — and "Finding your
	 * location…" would sit above the results forever with nothing to clear it.
	 * Ten is long enough for a cold GPS fix on a phone and short enough that
	 * somebody who has been staring at a status line gets an answer they can
	 * act on.
	 *
	 * @var {number}
	 */
	var GEO_TIMEOUT = 10000;

	/**
	 * How old a position the browser already has may be before it is re-taken.
	 *
	 * A minute. A visitor has not moved far enough in a minute to change which
	 * branch is nearest, and accepting a cached fix is the difference between
	 * an instant answer and several seconds of radio.
	 *
	 * @var {number}
	 */
	var GEO_MAX_AGE = 60000;

	/**
	 * The Geolocation API's code for "the visitor said no".
	 *
	 * GeolocationPositionError.PERMISSION_DENIED is 1, POSITION_UNAVAILABLE 2
	 * and TIMEOUT 3. The number is read off `error.code` rather than off the
	 * constant on the error object, which is the same choice the AbortError
	 * handling makes one path over: branching on a value works against a real
	 * DOMException and against anything shaped like one, while branching on the
	 * class would work in a browser and nowhere else.
	 *
	 * @var {number}
	 */
	var PERMISSION_DENIED = 1;

	/**
	 * How much of a visitor's own coordinate this locator is willing to handle.
	 *
	 * Four decimal places, which is about eleven metres of latitude. A device
	 * reports far more than that — a GPS fix arrives with ten or more digits —
	 * and every one of them would go into a /stores url as a query parameter.
	 *
	 * Cache-Control: private keeps that url out of shared HTTP caches, and
	 * Rest_Controller::get_stores() sets it deliberately for this reason. What
	 * it does not do is keep the url out of a *log*: it lands verbatim in the
	 * site's own access log and in every WAF, reverse proxy and CDN log in front
	 * of it, where it is typically kept for weeks. A rooftop-accurate position
	 * of a named visitor, retained by three parties, to answer a question that
	 * turns on which branch is nearest.
	 *
	 * Eleven metres is finer than that question needs by a wide margin and
	 * coarse enough that the log line is a street rather than a doorway.
	 *
	 * The rounding is here, at the one place a device's own coordinate enters
	 * this file, and deliberately not in url(): the browser sorts a preloaded
	 * list itself with Geo.distance(), so the point it measures from and the
	 * point the server measures from have to be the same point. Rounding in the
	 * url builder would leave the two working from different numbers and put
	 * tests/js/geo-crosscheck.test.js's guarantee — that the two
	 * implementations agree exactly — quietly out of reach.
	 *
	 * Nothing else is rounded. A geocoded address is a public place and a
	 * configured centre is the site owner's own decision; neither is a fact
	 * about a person.
	 *
	 * @var {number}
	 */
	var COORD_PLACES = 10000;

	/**
	 * Where Leaflet.markercluster's own stylesheet changes a bubble's colour.
	 *
	 * Under ten is green, under a hundred yellow, and anything else orange —
	 * `t<10?"small":t<100?"medium":"large"` in
	 * assets/markercluster/leaflet.markercluster.js. These are here because
	 * this file builds its own cluster icon and has to put the same class on it;
	 * a bubble carrying a class nothing styles is an unstyled square.
	 *
	 * @var {number}
	 */
	var CLUSTER_SMALL = 10;
	var CLUSTER_MEDIUM = 100;

	/**
	 * The cluster bubble's size in pixels, which is the vendored skin's.
	 *
	 * 40, because MarkerCluster.Default.css lays out a 30-pixel inner div with a
	 * 5-pixel margin on each side and nothing recomputes that from the icon.
	 *
	 * @var {number}
	 */
	var CLUSTER_SIZE = 40;

	/**
	 * Where a "directions" link goes, and in what shape.
	 *
	 * The host is the one this file already trusts: the tile url above is
	 * tile.openstreetmap.org and the attribution points at
	 * www.openstreetmap.org/copyright. This plugin exists so that a locator
	 * needs no Google Maps key, and a way out that needed an account somewhere
	 * else would give that back for the sake of one link.
	 *
	 * THE MEASUREMENT — RE-RUN THIS RATHER THAN TRUSTING IT
	 * =====================================================
	 * Checked against the live site on 2026-09-17, in a browser, both shapes:
	 *
	 *   ?route=%3B52.2297%2C21.0122
	 *     route_from = ""
	 *     route_to   = "Aleje Jerozolimskie, Krucza, ... Warszawa ... Polska"
	 *
	 *   ?route=52.4064%2C16.9252%3B52.2297%2C21.0122
	 *     route_from = "52.4064, 16.9252"
	 *     route_to   = the same address, and a route was computed
	 *
	 * So `route` is the parameter, `from;to` is the format, each endpoint is
	 * `lat,lng`, and the leading semicolon really is how the site spells "no
	 * origin". It reverse-geocodes the destination into a readable address by
	 * itself, which is why nothing here sends a name.
	 *
	 * One parameter in one format, for both cases: a destination-only link is
	 * the same parameter with the first half blank rather than a second url
	 * shape with a branch in front of it.
	 *
	 * No `engine` parameter, and that is a decision rather than an omission.
	 * Choosing car, bike or foot on somebody's behalf is a judgement this
	 * plugin has no basis for; leaving it off lets the site pick its own
	 * default.
	 *
	 * The history, because it is the part worth keeping: this was written as a
	 * reasoned guess under a rule that forbade the network request which would
	 * settle it, and it was carried as an explicit guess until somebody could
	 * open a browser. It is a measurement now. openstreetmap.org is still a
	 * website rather than a vendored dependency, so nothing in this repository
	 * can notice it changing — no test here can, and none pretends to. The
	 * block above is what to re-run when a link stops working.
	 *
	 * The url is built through url() rather than by concatenation, so the
	 * comma and the semicolon are percent-encoded by URLSearchParams and not
	 * by hand.
	 *
	 * @var {string}
	 */
	var DIRECTIONS = 'https://www.openstreetmap.org/directions';

	/**
	 * Where a "directions" link goes when the site chose Google Maps.
	 *
	 * THIS ONE IS A GUESS AND IS LABELLED AS ONE
	 * ==========================================
	 * DIRECTIONS above carries a measurement: somebody opened the live site in
	 * a browser on 2026-09-17 and wrote down what each parameter did. This url
	 * has had none of that. Nothing in this repository may make a network
	 * request, and no Google Maps url has been opened to check this one, so it
	 * is what the documented Maps URLs api is understood to be and nothing
	 * more:
	 *
	 *     https://www.google.com/maps/dir/?api=1&origin=LAT,LNG&destination=LAT,LNG
	 *
	 * with `api=1` naming the versioned form, `origin` omitted when there has
	 * been no search, and each endpoint `lat,lng`. **Re-run DIRECTIONS's
	 * measurement block against this url before trusting it**, the same way
	 * that one was, and correct this constant rather than adding a second.
	 *
	 * It is offered at all because a great many visitors are already in Google
	 * Maps and a link that opens the app they have beats one that does not;
	 * refusing to send them there is a decision for the site rather than for
	 * this file, which is why it is a setting. Settings::DIRECTIONS has why
	 * there are three choices and not four.
	 *
	 * The privacy rule is unchanged and applies to both: the origin is rounded
	 * to COORD_PLACES on the way into the url, the link carries
	 * rel="noreferrer", and nothing travels until somebody clicks.
	 *
	 * @var {string}
	 */
	var GOOGLE_DIRECTIONS = 'https://www.google.com/maps/dir/';

	/**
	 * The coloured dot's size in pixels, when a site has asked for one.
	 *
	 * Sixteen, against Leaflet's default pin of 25 by 41. A dot is read as a
	 * point rather than as a pin pointing at one, so it is centred on the
	 * location instead of standing above it — which is what the iconAnchor of
	 * half its size in dotIcon() does — and a 41-pixel dot centred on a shop
	 * would cover the street it is on.
	 *
	 * @var {number}
	 */
	var DOT_SIZE = 16;

	/**
	 * How much room a frame leaves around the outermost markers, in pixels.
	 *
	 * `fitBounds` fits coordinates, and an icon is not its coordinate: a
	 * marker whose point lands exactly on an edge has everything above that
	 * point outside the map. Measured on a live page before this existed — a
	 * pin at y = -33 in a 480-tall map, about eight visible pixels of it.
	 *
	 * Two pairs because the icons are not symmetric. Leaflet's default pin is
	 * 25 by 41 and *stands on* its point, so it needs 41 above and nothing
	 * below; a dot is DOT_SIZE centred and a cluster bubble is CLUSTER_SIZE
	 * centred, so both need half their size in every direction. 48 clears the
	 * pin with a little over, and 24 clears the widest centred icon — the
	 * cluster's 20 — with the same.
	 *
	 * Frozen, like every other shared constant here: these are handed straight
	 * to Leaflet, which is welcome to read them and has no business keeping
	 * them.
	 *
	 * @var {number[]}
	 */
	var PAD_TOP_LEFT = Object.freeze( [ 24, 48 ] );

	/**
	 * The other two edges, where nothing stands above a point.
	 *
	 * @var {number[]}
	 */
	var PAD_BOTTOM_RIGHT = Object.freeze( [ 24, 24 ] );

	/**
	 * How many locators this evaluation of the file has minted ids for.
	 *
	 * Task 11 put no id on anything, deliberately: ids have to be unique across
	 * a whole page, and two locators rendered by two different PHP objects — a
	 * widget and the content, a page builder constructing its own — cannot
	 * agree on a counter. So labels wrap their controls and nothing needs one.
	 *
	 * aria-activedescendant is the one thing that cannot be done that way: it
	 * is an IDREF, and there is no attribute that points at an element by any
	 * other means. Minting the ids here is safe for the exact reason the PHP
	 * could not do it: every locator on the page is initialised by one
	 * evaluation of one script, so there is one counter and it is shared by
	 * construction, which is the coordination the server side did not have.
	 *
	 * Two things keep that true rather than merely likely. The ids are
	 * namespaced with the plugin's own prefix, so they cannot collide with a
	 * theme's. And the serial is probed against the document before it is used
	 * — see serialFor() — which covers the one case the counter cannot see: a
	 * caching or concatenating plugin that causes this file to be evaluated
	 * twice on one page builds a second counter that starts at zero again.
	 *
	 * @var {number}
	 */
	var minted = 0;

	/**
	 * The tile layer out of a config, or null when there is not one.
	 *
	 * A VERBATIM COPY OF assets/js/admin.js's tileFor(), AND WHY
	 * ==========================================================
	 * The tile url was a frozen constant in this file until Task 21, and
	 * admin.js read it through window.SLOSM rather than carrying a second copy
	 * of a line the ODbL requires to be on the map — at the cost of declaring
	 * this whole 150 KB file as a dependency of an edit screen. The url is a
	 * setting now, so both files are handed one by the server and neither
	 * composes a credit line; what they share instead is this function, and
	 * tests/js/admin-picker.test.js asserts the two copies are byte for byte
	 * identical. The no-duplication rule that mattered is intact: there is
	 * still exactly one place the attribution is *written*, and it is
	 * Settings::attribution_html().
	 *
	 * Null rather than a built-in fallback, because a fallback would be exactly
	 * the second copy of the attribution that is not allowed to exist, and a
	 * basemap drawn with no attribution is a licence breach that looks like a
	 * working map. init() draws the map without tiles and says so in the
	 * console; the pins and the list do not need a basemap.
	 *
	 * The three placeholders are required, not optional. Settings::sanitise()
	 * refuses a url without them, so one that arrives here without them did not
	 * come through the settings screen — and a tile layer pointed at a url with
	 * no placeholders fetches one image and draws it as every tile at every
	 * zoom, which reads as a broken map rather than as a setting typed wrong.
	 *
	 * maxZoom is carried rather than taken from ZOOM, because the ceiling is a
	 * fact about the provider now: one that stops at 17 answers 404 for every
	 * tile above it. Shortcode clamps the *config's* zoom to the same number,
	 * so the two cannot disagree; the 19 below is the fallback for a config that
	 * names none, and is ZOOM.max's value rather than a second opinion.
	 *
	 * WHAT LEAFLET DOES WITH THE ATTRIBUTION, WHICH IS WHY IT IS ESCAPED IN PHP
	 * ========================================================================
	 * Leaflet adds the credit line on its own once the layer carries it: Map defaults include `attributionControl: true` and an init hook
	 * that adds the control, and the control reads every layer's
	 * `getAttribution()`, which returns `this.options.attribution`. All three
	 * read in assets/leaflet/leaflet.js — `A.mergeOptions({attributionControl:!0})`,
	 * `A.addInitHook(function(){this.options.attributionControl&&(new Ke).addTo(this)})`
	 * and `getAttribution:function(){return this.options.attribution}`.
	 *
	 * One thing to know before a later task touches a layer's attribution: the
	 * control writes it with innerHTML. `Ke._update` builds
	 * `this._container.innerHTML=i.join(' <span aria-hidden="true">|</span> ')`
	 * out of its prefix and every layer's `getAttribution()`. Until Task 21 the
	 * only thing this plugin ever put in an `attribution` was a frozen constant
	 * in this file, and this paragraph ended "a server-derived value in any
	 * layer's attribution would be markup injection inside Leaflet".
	 *
	 * It is a server-derived value now, so that sentence is the specification
	 * for what had to happen on the other side rather than a warning that was
	 * ignored. Settings::attribution_html() assembles the line out of two plain
	 * fields — text and a url — and escapes both itself, so the string that
	 * arrives here can carry no tag at all; the field is two fields rather than
	 * one html one precisely so that no kses call stands between an
	 * administrator and Leaflet's innerHTML. Only manage_options can write it,
	 * which on multisite is a site administrator who has no unfiltered_html —
	 * which is why that is not merely belt and braces.
	 *
	 * The no-innerHTML rule is still enforced by *what goes in* rather than by
	 * anything the source scan in tests/js/locator.test.js can see.
	 *
	 * TWO NEARER ONES, WHICH TASKS 15 AND 16 HAD TO GET RIGHT
	 * -------------------------------------------------------
	 * The attribution is the *least* likely of the three, because nothing is
	 * ever tempted to put a location name in it. These two are what a popup
	 * and a custom pin are made of, both are read in the vendored 1.9.4, and
	 * both are now live in this file — see fillPopup() and clusterIcon():
	 *
	 * - `Popup.setContent()` with a string. `_updateContent` is
	 *   `if("string"==typeof e)t.innerHTML=e;else{for(;t.hasChildNodes();)
	 *   t.removeChild(t.firstChild);t.appendChild(e)}` — so a string is parsed
	 *   as markup and an *element* is appended as a node. `bindPopup( name )`
	 *   with a title from a user with unfiltered_html is stored xss;
	 *   `bindPopup( element )` built with createElement and textContent is not.
	 *   The same `_updateContent` serves Tooltip.
	 * - `L.divIcon( { html: … } )`. `createIcon` is
	 *   `e.html instanceof Element?(me(t),t.appendChild(e.html)):t.innerHTML=
	 *   !1!==e.html?e.html:""` — same shape, same rule: an Element is safe, a
	 *   string is parsed.
	 *
	 * Neither is visible to any scan in this repository. The forbidden-property
	 * scan in tests/js/locator.test.js reads *this* file, and the innerHTML in
	 * both of those is Leaflet's own — so the only thing standing between a
	 * location name and script execution is whoever writes the call. Build the
	 * node, not the string.
	 *
	 * No `{s}` subdomain placeholder is *offered* anywhere: the default url
	 * Settings ships has none, because OpenStreetMap's own tile usage policy
	 * asks for the single `tile.openstreetmap.org` host now that browsers speak
	 * HTTP/2 and Leaflet warns about `{s}` being deprecated. A site that types
	 * one into the setting gets it, because it is their tile server and their
	 * provider's policy.
	 *
	 * @param {object} config The locator config.
	 * @returns {object|null} `{ url, options }` for L.tileLayer, or null.
	 */
	function tileFor( config ) {
		var tile = config && config.tile;
		var zoom;

		if ( ! tile || 'object' !== typeof tile || 'string' !== typeof tile.url ) {
			return null;
		}

		if (
			-1 === tile.url.indexOf( '{z}' ) ||
			-1 === tile.url.indexOf( '{x}' ) ||
			-1 === tile.url.indexOf( '{y}' )
		) {
			return null;
		}

		zoom = 'number' === typeof tile.maxZoom && window.isFinite( tile.maxZoom ) ? tile.maxZoom : 19;

		return {
			url: tile.url,
			options: {
				attribution: 'string' === typeof tile.attribution ? tile.attribution : '',
				maxZoom: zoom,
			},
		};
	}

	/**
	 * The English of every string the interface says.
	 *
	 * A fallback, not a translation: on a working site slosmL10n is an inline
	 * script printed before this deferred one, so it is always there. It is
	 * not there when another plugin dequeues the handle, or when an optimiser
	 * moves inline scripts about. English is a degradation; a message reading
	 * "undefined", or a TypeError halfway through init, is a broken page.
	 *
	 * The keys are asserted against Assets::strings() in
	 * tests/js/harness.test.js, so one list cannot grow without the other.
	 */
	var FALLBACK_STRINGS = Object.freeze( {
		noResults: 'No results',
		closePopup: 'Close popup',
		resultsFound: 'Locations found: %s',
		searching: 'Searching…',
		locating: 'Finding your location…',
		locationDenied: 'No location shared. Search for an address instead.',
		locationFailed: 'Your location could not be worked out. Search for an address instead.',
		locationUnsupported: 'This browser cannot share a location. Search for an address instead.',
		loadFailed: 'The locations could not be loaded.',
		configError: 'This map could not start: its settings are missing or unreadable.',
		searchNoMatch: 'No place matched that search.',
		searchBusy: 'The address lookup is busy right now. Try again in a moment.',
		searchFailed: 'That address could not be looked up right now.',
		distanceKm: '%s km',
		distanceMi: '%s mi',
		directions: 'Directions',
	} );

	/**
	 * Containers already initialised, so a second initAll() is a no-op.
	 *
	 * A WeakSet rather than an attribute on the element. An attribute survives
	 * `cloneNode`, and a page builder that clones a locator would hand the
	 * copy a marker saying it was already alive — a map that never appears,
	 * with a plausible-looking attribute in the html explaining why not.
	 *
	 * A container is added to it before its config is read, not after, so a
	 * locator that failed to start stays failed. Retrying on the next
	 * initAll() would clear the results list and write the same message into
	 * it again, which is a flicker with no new information in it.
	 *
	 * What it does NOT protect against, and this is the limit worth stating:
	 * it lives exactly as long as this evaluation of the file does. A caching
	 * or concatenating plugin that causes locator.js to run twice on one page
	 * builds a second WeakSet that has never seen anything, so the second run
	 * calls L.map() on containers that already carry a _leaflet_id and Leaflet
	 * throws 'Map container is already initialized.' initAll() catches that
	 * per container, which is what keeps the page from going blank, but the
	 * right fix is not to load the file twice.
	 */
	var initialised = new WeakSet();

	/**
	 * Every locator started in this evaluation, so one can be taken apart.
	 *
	 * A plain array beside the WeakSet, and the pairing is the point: the
	 * WeakSet answers "has this container been started", which is all
	 * `initAll()` needs and is the only question the front end ever asks. It
	 * cannot be walked, and taking a map apart means walking.
	 *
	 * WHY THERE IS ANYTHING TO TAKE APART
	 * -----------------------------------
	 * `L.Map` binds a `resize` listener to `window` when `trackResize` is on,
	 * which it is by default, and keeps handlers on the document besides. None
	 * of that goes away when the container leaves the page: the container is
	 * garbage, the map is not, because the listener on `window` is a live
	 * reference to it. On a normal page nothing ever removes a locator, so
	 * this cost nothing and was invisible.
	 *
	 * A page builder is where it stops being invisible. Bricks re-renders an
	 * element by parsing the server's HTML into a **new** node and dropping
	 * the old one, so every edit in an editing session detaches one locator
	 * and creates another. Nothing called `map.remove()`, so a session's worth
	 * of edits left a session's worth of live maps bound to detached
	 * containers, each still listening for resizes.
	 *
	 * WHAT THIS COSTS ON A PAGE THAT NEVER REMOVES ANYTHING
	 * -----------------------------------------------------
	 * One object per locator, holding a reference the map already holds. The
	 * array is never swept unless something calls `sweep()`, and nothing on
	 * the front end does — `initAll()` is called once, at load, and does not
	 * touch it. So the front end pays for one array of thirteen-ish bytes per
	 * map and no work at all.
	 *
	 * @var {{element: Element, map: object}[]}
	 */
	var started = [];

	/**
	 * One interface string, translated if the server sent one.
	 *
	 * The type check is not ceremony. wp_localize_script() runs every value
	 * through a json encode, so a filter returning an array or a number puts a
	 * non-string here, and `node.textContent = {}` writes "[object Object]"
	 * into the page.
	 *
	 * @param {string} key Key from FALLBACK_STRINGS.
	 * @returns {string} The string to show.
	 */
	function text( key ) {
		var payload = window.slosmL10n;

		if ( payload && 'string' === typeof payload[ key ] && '' !== payload[ key ] ) {
			return payload[ key ];
		}

		return FALLBACK_STRINGS[ key ];
	}

	/**
	 * A finite number, or null.
	 *
	 * Strings are refused rather than coerced: the payload declares lat and
	 * lng as number-or-null, and a '52.2297' arriving as text is a bug on the
	 * server worth seeing rather than papering over. Booleans are refused for
	 * the same reason Shortcode::number() refuses them — Number( true ) is 1,
	 * and 1 is a latitude.
	 *
	 * @param {*} value Candidate.
	 * @returns {number|null} The number, or null.
	 */
	function finiteNumber( value ) {
		return 'number' === typeof value && isFinite( value ) ? value : null;
	}

	/**
	 * A latitude and longitude pair, or null when there is no place here.
	 *
	 * One helper for a location's coordinates and for the centre the shortcode
	 * named, because the two have the same question to answer and answering it
	 * twice is how they come to differ.
	 *
	 * Out-of-range values are refused rather than clamped. Clamping 91 to 90
	 * invents a location in the Arctic; refusing it leaves the location off the
	 * map, which is what an unplaced location already does.
	 *
	 * @param {*} lat Candidate latitude.
	 * @param {*} lng Candidate longitude.
	 * @returns {number[]|null} `[ lat, lng ]`, or null.
	 */
	function point( lat, lng ) {
		var y = finiteNumber( lat );
		var x = finiteNumber( lng );

		if ( null === y || null === x ) {
			return null;
		}

		if ( 90 < Math.abs( y ) || 180 < Math.abs( x ) ) {
			return null;
		}

		return [ y, x ];
	}

	/**
	 * A zoom Leaflet can use, whatever arrived in the config.
	 *
	 * @param {*} value Candidate zoom.
	 * @returns {number} A zoom inside the range the tile layer renders.
	 */
	function zoomLevel( value ) {
		var zoom = finiteNumber( value );

		if ( null === zoom ) {
			return ZOOM.fallback;
		}

		return Math.max( ZOOM.min, Math.min( ZOOM.max, zoom ) );
	}

	/**
	 * Puts one sentence in front of whoever is looking at this locator.
	 *
	 * Into `.slosm__message`, the status line Shortcode::render() emits between
	 * the search row and the map. It is the locator's one live region and it is
	 * deliberately *outside* the results list: the list used to carry
	 * aria-live itself, which meant replacing it with seven results announced
	 * seven names, addresses, cities and distances in a row, and the sentence
	 * about them was one more list item in the middle of it.
	 *
	 * That arrangement also fixed something this docblock used to record as
	 * unfixable here. A live region only announces changes it *observes*, so a
	 * region built and filled in the same tick was not being watched when it
	 * changed — and the config-error message, written while this script is
	 * still running, most likely was never announced at all. An element the
	 * server rendered has been watched since the page parsed. The fallback
	 * below builds one when the markup has none, and that first sentence has
	 * the old problem; every one after it does not.
	 *
	 * Clearing the list clears the list and nothing else. Shortcode::render()
	 * emits the <template class="slosm__row"> as a *sibling* of the <ol>, not
	 * a child of it, so `list.textContent = ''` cannot destroy the row
	 * template Task 14 clones from. That is load-bearing and not obvious from
	 * reading this function; if the template ever moves inside the list, this
	 * line starts deleting it on the first empty result.
	 *
	 * An empty sentence is how a caller takes the last one down — show() does
	 * it on every draw that found something, because the rows no longer remove
	 * the message by being written over it.
	 *
	 * `keep` is what an error says instead of what a status says, and the
	 * difference is whose content is being thrown away. "Searching…" and "no
	 * results" replace the list because the list is about to be replaced
	 * anyway — they are that replacement, announced. An error is not: a
	 * locator showing eight branches that then fails at something else has no
	 * business turning eight useful rows into one sentence. The case that made
	 * this concrete is a second evaluation of this file, where Leaflet refuses
	 * the already-initialised container and the catch in initAll() reports it —
	 * on a locator whose map and list are both fine.
	 *
	 * @param {Element} container The .slosm element.
	 * @param {string}  sentence  Already-translated text.
	 * @param {boolean} keep      True to leave any results in place.
	 * @returns {void}
	 */
	function say( container, sentence, keep ) {
		var doc = container.ownerDocument;
		var list = container.querySelector( '.slosm__results' );
		var status = container.querySelector( '.slosm__message' );

		if ( list && ! keep ) {
			// textContent = '' drops every child, which is how the sentence
			// replaces whatever was there without parsing anything.
			list.textContent = '';
		}

		if ( ! status ) {
			/*
			 * Markup with no status line in it: a page builder that re-emitted
			 * the locator, a filter that ate a paragraph. One is built rather
			 * than giving up, because "nowhere" is the one place a sentence must
			 * not go.
			 *
			 * It is the weaker half of the arrangement and worth naming as such:
			 * a region created and filled in the same tick was not being watched
			 * when it changed, so this first sentence most likely is not
			 * announced. It is in the accessibility tree and reachable by
			 * reading the page, and every sentence after it lands in an element
			 * that has been there a while. The server's own element has none of
			 * that problem, which is why it is the server's.
			 */
			status = doc.createElement( 'p' );

			status.className = 'slosm__message';
			status.setAttribute( 'role', 'status' );
			status.setAttribute( 'aria-live', 'polite' );
			container.appendChild( status );
		}

		// One element, replaced entire. role="status" is atomic, so what is
		// announced is this sentence and not the difference between it and the
		// last one — which is also why nothing here has to remove an old message
		// first, the way this function did while the sentences were list items
		// that could stack up into a column.
		status.textContent = sentence;
	}

	/**
	 * Reports a locator that cannot come alive, and says so on the page.
	 *
	 * @param {Element} container The .slosm element.
	 * @param {string}  key       Key from FALLBACK_STRINGS.
	 * @returns {null} Always null, so callers can `return fail( ... )`.
	 */
	function fail( container, key ) {
		container.classList.add( 'slosm--error' );
		// keep: an error never destroys results somebody can still use.
		say( container, text( key ), true );

		return null;
	}

	/**
	 * The config off one container's data-slosm attribute.
	 *
	 * Returns null for anything it cannot use, and the caller shows a message.
	 * Four things count as unusable and all four are real:
	 *
	 * - no attribute at all: markup somebody hand-wrote, or a page builder
	 *   that stripped data- attributes.
	 * - not json: a security plugin or an optimiser rewriting attributes.
	 * - not an object: as above, or a filter gone wrong.
	 * - `{}`: Shortcode::encoded_config() emits exactly that when
	 *   wp_json_encode() returns false, which invalid utf-8 in a term name
	 *   will do. It is the shortcode saying "I could not tell you anything",
	 *   so it must not read as a locator with default settings.
	 *
	 * The stores route is checked here rather than at fetch time because it is
	 * the one value without which nothing can happen, and a message before the
	 * map is drawn is more useful than one after.
	 *
	 * @param {Element} container The .slosm element.
	 * @returns {object|null} The config, or null.
	 */
	function readConfig( container ) {
		var raw = container.getAttribute( 'data-slosm' );
		var config;

		if ( 'string' !== typeof raw || '' === raw ) {
			return null;
		}

		try {
			config = JSON.parse( raw );
		} catch ( error ) {
			return null;
		}

		if ( ! config || 'object' !== typeof config || Array.isArray( config ) ) {
			return null;
		}

		if ( ! config.routes || 'object' !== typeof config.routes ) {
			return null;
		}

		if ( null === url( config.routes.stores, {} ) ) {
			return null;
		}

		return config;
	}

	/**
	 * A route plus query parameters, built by the url parser rather than by
	 * string concatenation.
	 *
	 * This is the whole reason Shortcode ships four finished routes instead of
	 * one base to append to. rest_url() has two shapes: with a permalink
	 * structure it is https://site/wp-json/slosm/v1/stores, and with plain
	 * permalinks get_rest_url() returns
	 * https://site/index.php?rest_route=/slosm/v1/stores. Appending
	 * '?limit=500' to the second produces a url with two question marks, on
	 * every site that has never visited Settings > Permalinks. URLSearchParams
	 * appends correctly to either, and re-encodes the rest_route value to
	 * %2Fslosm%2Fv1%2Fstores — which WordPress decodes back on the way in,
	 * because rest_route is an ordinary registered query var read out of $_GET.
	 *
	 * Returns null rather than throwing for a route that will not parse, so
	 * readConfig() can use it as its own validity check.
	 *
	 * @param {*}      route  The route from the config.
	 * @param {object} params Query parameters; undefined and '' are skipped.
	 * @returns {string|null} The url, or null.
	 */
	function url( route, params ) {
		var built;

		if ( 'string' !== typeof route || '' === route ) {
			return null;
		}

		try {
			built = new URL( route, window.location && window.location.href );
		} catch ( error ) {
			return null;
		}

		Object.keys( params ).forEach( function ( key ) {
			var value = params[ key ];

			if ( undefined === value || null === value || '' === value ) {
				return;
			}

			built.searchParams.set( key, String( value ) );
		} );

		return built.toString();
	}

	/**
	 * A line for a developer, never for a visitor.
	 *
	 * @param {Error} error What went wrong.
	 * @returns {void}
	 */
	function warn( error ) {
		if ( window.console && window.console.warn ) {
			window.console.warn( 'Store Locator: ' + error.message );
		}
	}

	/**
	 * The unit this locator measures in.
	 *
	 * Anything other than 'mi' is kilometres, which is the same rule
	 * Geo::distance() and the REST route apply. A config carrying nonsense
	 * gets the default rather than NaN.
	 *
	 * @param {object} config The config.
	 * @returns {string} 'km' or 'mi'.
	 */
	function unitOf( config ) {
		return 'mi' === config.units ? 'mi' : 'km';
	}

	/**
	 * A distance as a person reads it, in the site's language.
	 *
	 * The format is a translated string with a %s in it rather than a
	 * concatenation, because "12.4 km" is not the shape every language writes:
	 * French typography wants a non-breaking space, and some locales put the
	 * unit first. Assets::strings() has the two formats.
	 *
	 * One decimal place, always. Two is a claim the geocoder cannot support —
	 * a rooftop coordinate and a town-centre coordinate differ by more than
	 * ten metres — and none turns every location inside a city into "0 km".
	 *
	 * @param {number|null} distance The distance, or null when there is none.
	 * @param {string}      unit     'km' or 'mi'.
	 * @returns {string} The line for the cell, or '' when nothing is known.
	 */
	function formatDistance( distance, unit ) {
		if ( null === distance ) {
			return '';
		}

		return text( 'mi' === unit ? 'distanceMi' : 'distanceKm' ).replace( '%s', distance.toFixed( 1 ) );
	}

	/**
	 * A serial no other locator on this page is using.
	 *
	 * The counter is enough within one evaluation of this file. The probe is
	 * for the case it cannot see: a plugin that concatenates or caches scripts
	 * badly enough to run this file twice builds a second counter starting at
	 * zero, and the second run would otherwise mint the same ids as the first.
	 * One getElementById per locator is not a cost worth measuring.
	 *
	 * @param {Document} doc The document.
	 * @returns {number} The serial.
	 */
	function serialFor( doc ) {
		var serial;

		do {
			minted++;
			serial = minted;
		} while ( doc.getElementById && doc.getElementById( 'slosm-' + serial + '-listbox' ) );

		return serial;
	}

	/**
	 * Empties an element without parsing anything.
	 *
	 * @param {Element} node The element.
	 * @returns {void}
	 */
	function empty( node ) {
		node.textContent = '';
	}

	/**
	 * Writes one cell of a result row, as text.
	 *
	 * Never markup, and the type check is the reason rather than the ceremony:
	 * a payload field that arrived as a number or an object would otherwise be
	 * written as "[object Object]" into somebody's results list.
	 *
	 * @param {Element} row      The cloned row.
	 * @param {string}  selector Which cell.
	 * @param {*}       value    What the payload carried.
	 * @returns {void}
	 */
	function cell( row, selector, value ) {
		var node = row.querySelector( selector );

		if ( node ) {
			node.textContent = 'string' === typeof value ? value : '';
		}
	}

	/**
	 * Whether one location carries a category, the way /stores decides it.
	 *
	 * Case-folded, not exact, and that is about agreeing with the route rather
	 * than about being generous. Rest_Controller::has_category() compares with
	 * strcasecmp, so /stores matches `Bakeries` against `bakeries`; a browser
	 * filter comparing byte for byte would show zero rows for a hand-written
	 * data-slosm carrying a differently-cased name, on a payload the server had
	 * already filtered correctly.
	 *
	 * An exact comparison in front of this one would be redundant rather than
	 * belt-and-braces — two equal strings lowercase to two equal strings, always
	 * — and a guard no input can distinguish is a guard nobody can maintain.
	 *
	 * The two folds are not identical and this one is the wider of the two:
	 * toLowerCase() is Unicode-aware and strcasecmp is not, so `Ä` and `ä` match
	 * here and would not on the server. That direction is harmless and the
	 * other would not be. Everything this compares either came from the same
	 * payload on both sides — the select is built from the names in it — or was
	 * pinned in the config, in which case /stores has already done the filtering
	 * and this pass can only ever drop a row the server meant to send.
	 *
	 * @param {object} item     One item from the payload.
	 * @param {string} category The category name to match.
	 * @returns {boolean} True when this location is in it.
	 */
	function inCategory( item, category ) {
		var names = Array.isArray( item.categories ) ? item.categories : [];
		var wanted = category.toLowerCase();

		return names.some( function ( name ) {
			return 'string' === typeof name && name.toLowerCase() === wanted;
		} );
	}

	/**
	 * Every usable category name in a payload, without duplicates.
	 *
	 * Anything that is not a non-empty string is dropped rather than coerced,
	 * for the reason cell() gives about the row: a number or an object in that
	 * array is a bug on the server, and `String( {} )` in a select would put
	 * "[object Object]" in front of somebody as a thing to filter by.
	 *
	 * @param {Array} items Items from GET /stores.
	 * @returns {string[]} The names.
	 */
	function categoriesIn( items ) {
		var found = [];

		items.forEach( function ( item ) {
			if ( ! item || 'object' !== typeof item || ! Array.isArray( item.categories ) ) {
				return;
			}

			item.categories.forEach( function ( name ) {
				if ( 'string' === typeof name && '' !== name && -1 === found.indexOf( name ) ) {
					found.push( name );
				}
			} );
		} );

		return found;
	}

	/**
	 * Teaches the select about any category it has not seen, and no more.
	 *
	 * Added to, never replaced, and that is the whole of what makes the control
	 * usable in query mode. There the options come from each answer /stores
	 * gives, and once a category is chosen every later answer carries only that
	 * category — so a list rebuilt from the latest payload would collapse to the
	 * one option the visitor already picked, with no way back to the others. In
	 * preload mode the question does not arise: the payload is the whole list
	 * and the first merge is complete.
	 *
	 * The cost of never forgetting is bounded by the number of categories the
	 * site has, which is a taxonomy rather than a stream.
	 *
	 * @param {object} instance The locator.
	 * @param {Array}  items    Items from GET /stores.
	 * @returns {void}
	 */
	function learnCategories( instance, items ) {
		var added = false;

		if ( ! instance.categorySelect ) {
			return;
		}

		categoriesIn( items ).forEach( function ( name ) {
			if ( -1 === instance.categories.indexOf( name ) ) {
				instance.categories.push( name );
				added = true;
			}
		} );

		if ( ! added ) {
			return;
		}

		// By code unit rather than by the site's collation. A locale-aware sort
		// would mean assuming Intl, and the alternative to a slightly odd order
		// for a Polish Ł is a dependency this file does not otherwise have.
		instance.categories.sort();

		fillCategories( instance );
	}

	/**
	 * Rebuilds the select's options, keeping the one the shortcode rendered.
	 *
	 * "All categories" is kept rather than recreated, and that is why there is
	 * no string for it in Assets::strings(): Shortcode::category_options()
	 * already rendered it, translated, and the option element carrying that text
	 * is sitting in the select. Rebuilding it would mean shipping a second copy
	 * of the same sentence to every page in the site's language.
	 *
	 * Every name goes in with textContent and with `value`, which is a DOM
	 * property and not markup. A term name is server data like any other — a
	 * user with unfiltered_html can legitimately put html in one — and this is
	 * the same rule the rows and the suggestions follow.
	 *
	 * @param {object} instance The locator.
	 * @returns {void}
	 */
	function fillCategories( instance ) {
		var select = instance.categorySelect;
		var doc = instance.element.ownerDocument;
		var kept = Array.prototype.filter.call( select.children, function ( option ) {
			return '' === option.value;
		} );

		empty( select );

		kept.forEach( function ( option ) {
			select.appendChild( option );
		} );

		instance.categories.forEach( function ( name ) {
			var option = doc.createElement( 'option' );

			option.value = name;
			option.textContent = name;

			if ( name === instance.category ) {
				// The attribute as well as the property below, because these
				// options are being built rather than parsed: an option carrying
				// `selected` is the one a browser shows as chosen, and the
				// harness can see an attribute where it cannot see selectedness.
				option.setAttribute( 'selected', 'selected' );
			}

			select.appendChild( option );
		} );

		// After the options, and a mutation deleting this line survives the
		// whole suite — so it is worth saying what keeps it here rather than
		// leaving the next reader to find that out with a sweep.
		//
		// The option carrying `selected` above is what a browser chooses when
		// it parses or builds a select that nobody has touched. Once somebody
		// *has* chosen something the select's selectedness is dirty, and the
		// rules for whether a `selected` attribute on a freshly appended option
		// re-selects it then are the kind of DOM detail this project has been
		// wrong about before. An explicit assignment is deterministic under
		// either reading.
		//
		// The input that separates the two is real and simply out of this
		// harness's reach: a browser rebuilding the options of a select the
		// visitor has already used. tests/js/harness.js states plainly that
		// there is no relationship at all between a stub select's value and its
		// options, dirty or otherwise — so nothing here can watch a rebuild
		// move a selection, and the line stays on the strength of the browser
		// rather than of a case.
		select.value = instance.category;
	}

	/**
	 * Puts the results back in step with the controls, in whichever mode.
	 *
	 * This is the shape applyCategory() had to itself until Task 29b, lifted
	 * out rather than copied: the three filter controls are asking one
	 * question — the filter moved, now what — and a second copy of the answer
	 * is a second place for the two modes to drift apart.
	 *
	 * The two modes part company here for the same reason they do everywhere
	 * else in this file: preload has the whole list in memory and can filter it
	 * without asking anybody, while query mode has only the neighbourhood it
	 * last asked about and has to ask again.
	 *
	 * NO GEOCODER ON THIS PATH, WHICH IS WHAT THE ORIGIN IS FOR
	 * --------------------------------------------------------
	 * locate() takes a point and asks /stores. It never asks /geocode — the
	 * only place in this file that reads `routes.geocode` is submitSearch(),
	 * where somebody typed an address — so a visitor stepping the radius select
	 * through four values spends four requests on this plugin's own route and
	 * none upstream. Geocoder::MIN_INTERVAL, the one-request-per-second
	 * courtesy gap, is applied inside Geocoder and per upstream service, which
	 * means on the two routes that talk to one: /geocode and /suggest. A
	 * re-run asks neither, so it cannot queue behind that gap and cannot spend
	 * a site's share of it. instance.origin is what makes that true: the
	 * address was resolved once, and every re-run searches from the answer
	 * rather than from the text.
	 *
	 * WHEN THERE IS NO ORIGIN, WHICH IS A DIFFERENT QUESTION IN EACH MODE
	 * ------------------------------------------------------------------
	 * In preload the redraw happens anyway, and not for symmetry: the limit
	 * does not need an origin to mean anything. entriesFor() applies it to an
	 * unsorted list too, and its docblock has why — "Show at most" is how many
	 * rows this locator was told to show rather than a fact about a search. So
	 * somebody who changes the count on a map nobody has searched sees the list
	 * grow, and somebody who changes the radius sees nothing move, which is
	 * right: a radius with no centre is not a filter.
	 *
	 * In query mode it returns. There is nothing on screen to redraw, nowhere
	 * to search from, and asking /stores for everything anywhere is the
	 * whole-list request that mode exists to avoid making. The choice is not
	 * lost — the controls are read at the moment a search runs, so the next
	 * search spends it.
	 *
	 * SUPERSEDING, WHICH NEEDS NOTHING NEW
	 * ------------------------------------
	 * Two changes in a second is one visitor moving two controls, and the query
	 * path already answers it: beginSearch() aborts the run in flight and mints
	 * a token, and the check after the fetch in locate() drops an answer whose
	 * token has moved on. The preloaded path cannot have the problem at all —
	 * the redraw is synchronous and there is no request to overtake.
	 *
	 * @param {object} instance The locator.
	 * @returns {void}
	 */
	function refilter( instance ) {
		if ( 'preload' === instance.config.mode ) {
			show( instance, instance.items, instance.origin, true, true );

			return;
		}

		if ( null === instance.origin ) {
			return;
		}

		instance.pendingSearch = locate( instance, instance.origin, beginSearch( instance ) );
	}

	/**
	 * Applies whatever the category select now says.
	 *
	 * The category is recorded on the instance first, because unlike the two
	 * number controls it is read from there rather than from the DOM — see
	 * radiusNow() for the difference and why it is not an inconsistency.
	 *
	 * @param {object} instance The locator.
	 * @returns {void}
	 */
	function applyCategory( instance ) {
		instance.category = 'string' === typeof instance.categorySelect.value ? instance.categorySelect.value : '';

		refilter( instance );
	}

	/**
	 * Applies whatever the radius or the result-count select now says.
	 *
	 * Nothing is read off the event and nothing is recorded. radiusNow() and
	 * limitNow() read the controls at the moment they are used, which is Task
	 * 29a's decision and the one that survives a browser restoring form state;
	 * this handler is a trigger and nothing else, which is why one function
	 * serves both controls and why it takes no argument saying which moved.
	 *
	 * THE SENTENCE IS NOT SAID HERE, AND USED TO BE
	 * ---------------------------------------------
	 * Task 29b put one here, because a list that redraws under somebody who
	 * cannot see it needs a word for *why*, and a change nobody pressed a
	 * button for is exactly the case. Task 24d moved it: show() now says how
	 * many results a draw found, on every draw a person asked for, and this is
	 * one of those — so a sentence here would be a second one landing on top of
	 * a better one.
	 *
	 * What was lost with it is worth naming. The old rule spoke only when the
	 * count had *moved*, on the argument that an equal count is the same rows;
	 * show() speaks whether or not it moved, so a select change that changes
	 * nothing now says the same number again. That is a true sentence rather
	 * than a silent control, and a live region reading identical text twice is
	 * something screen readers already handle. The rule it replaces was two
	 * conditions that had to stay in step with another file.
	 *
	 * @param {object} instance The locator.
	 * @returns {void}
	 */
	function applyAmount( instance ) {
		refilter( instance );
	}

	/**
	 * Wires the category select, or takes it out of play.
	 *
	 * A pinned category is not a default this control gets to override. The
	 * site owner wrote category="bakeries" in the shortcode, and a select still
	 * offering "All categories" would undo that on a click, silently, on a map
	 * whose whole purpose was to show one category. So the control is left
	 * exactly as the shortcode rendered it — two options, the pinned one chosen
	 * — and taken out of play.
	 *
	 * aria-disabled RATHER THAN disabled
	 * ----------------------------------
	 * This was `disabled` first, and that was the wrong trade. A disabled
	 * control is out of the tab order, so the one element on the page that says
	 * what this map is filtered to becomes unreachable by keyboard — and it is
	 * not a control somebody is being stopped from *using*, it is a label they
	 * are being stopped from *reading*. The text stays in the accessibility
	 * tree either way, so this was never a loss of information; it was a loss of
	 * the ordinary way of getting to it.
	 *
	 * aria-disabled says "this is not operable" to assistive technology and
	 * leaves the element focusable, which is why the pattern exists. What it
	 * does not do is stop a browser changing the value, so the change handler
	 * below puts it back. That is a strange thing for a control to do and it is
	 * the honest one: the alternative is a select showing "All categories" over
	 * a map that is still filtered.
	 *
	 * Not removed, either. Removing it would move everything beside it and
	 * leave nothing on the page to say why this map shows only some of the
	 * locations. Task 24 owns the accessibility pass; this much of it is being
	 * decided here because the decision cannot be deferred.
	 *
	 * @param {object} instance The locator.
	 * @returns {void}
	 */
	function wireCategory( instance ) {
		var select = instance.element.querySelector( '.slosm__category' );

		if ( ! select ) {
			return;
		}

		if ( '' !== instance.category ) {
			select.setAttribute( 'aria-disabled', 'true' );

			select.addEventListener( 'change', function () {
				select.value = instance.category;
			} );

			return;
		}

		instance.categorySelect = select;

		// Settled once here as well as on every rebuild, because a select can
		// arrive carrying a value nothing in this config knows about: a page
		// builder that wrote one into the markup, or a browser restoring form
		// state. fillCategories() would fix it, but only on a payload that
		// taught this locator a category it had not seen — so a site whose
		// locations carry no categories at all would be left with a control
		// claiming a filter the map is not applying.
		select.value = instance.category;

		select.addEventListener( 'change', function () {
			applyCategory( instance );
		} );
	}

	/**
	 * Wires the radius and the result-count selects to the results.
	 *
	 * Both in one loop, because the handler is the same and neither control
	 * needs a word of its own. That is what makes this shorter than
	 * wireCategory(): the shortcode's radius and limit are *starting* values,
	 * rendered as the selected option of the site's whole list of steps, so
	 * there is no pin for a control to undo, nothing to take out of play and no
	 * state to keep in step. radiusNow() has that argument in full.
	 *
	 * A locator with no such select is an ordinary page rather than broken
	 * markup — Shortcode::filters() emits both today, but a theme or a page
	 * builder can have rebuilt the filter bar — and there is then no change to
	 * listen for. chosenAmount() already searches on the starting value in that
	 * case, so nothing here has to say anything about it.
	 *
	 * @param {object} instance The locator.
	 * @returns {void}
	 */
	function wireAmounts( instance ) {
		[ '.slosm__radius', '.slosm__limit' ].forEach( function ( selector ) {
			var select = instance.element.querySelector( selector );

			if ( ! select ) {
				return;
			}

			select.addEventListener( 'change', function () {
				applyAmount( instance );
			} );
		} );
	}

	/**
	 * What a number select is showing, or the value the locator started at.
	 *
	 * The two halves of the guard are different questions and both are needed.
	 *
	 * `Number( select.value )` rather than the value itself, because a select's
	 * value is a string — '500', not 500 — and finiteNumber() refuses strings
	 * on purpose. Handing '500' to the two read sites unconverted would be
	 * worse than handing them nothing: the local filter would see null and stop
	 * filtering by radius at all, silently, on the one control whose whole job
	 * is to filter. The conversion happens here, once, rather than at four call
	 * sites that would drift.
	 *
	 * `0 <` because a radius of nothing is a search that can never match and a
	 * count of nothing is a list with no rows in it, and falling back beats
	 * showing an empty map over a site full of locations. This is the same rule
	 * the server applies on the way out — Shortcode::radius() replaces a
	 * non-positive radius with the default, Settings::radius_list() and
	 * limit_list() drop non-positive choices before they can ever be rendered
	 * as options — so nothing this plugin emits can take this branch. What can
	 * is markup a page builder or a content filter rewrote, which is the same
	 * class of input wireCategory() settles the category select against.
	 *
	 * Infinity is refused by finiteNumber() and not by the comparison, which is
	 * why the null check is not redundant: `Number( 'Infinity' )` is greater
	 * than zero, and a radius of Infinity would quietly disable the filter.
	 *
	 * @param {object|null} select   The control, or null when there is none.
	 * @param {*}           fallback The value the shortcode or the element started this locator at.
	 * @returns {number|null} A usable number, or null when neither is usable.
	 */
	function chosenAmount( select, fallback ) {
		var chosen = select ? finiteNumber( Number( select.value ) ) : null;

		return null !== chosen && 0 < chosen ? chosen : finiteNumber( fallback );
	}

	/**
	 * The radius in force, read at the moment somebody asks for it.
	 *
	 * Read rather than remembered, which is where this parts company with
	 * wireCategory(). The category is held on the instance because the instance
	 * is the one place that knows what the map is filtered to: the control can
	 * be pinned out of play by the shortcode, the options are rebuilt from
	 * payloads as they arrive, and a redraw has to filter by the same name the
	 * last one did. None of that is true here. There is nothing to rebuild,
	 * nothing to keep in step, and — the difference that decides it — the
	 * shortcode's `radius` is a *starting* value rather than a pinned one:
	 * Shortcode::radius_options() renders the site's whole list of steps with
	 * the configured one merely `selected`, and the panel calls the control
	 * "Starting radius". There is no pin here for a control to undo, so there
	 * is nothing to disable and no state to hold.
	 *
	 * The select is therefore the truth and this reads it. A browser restoring
	 * form state across a reload fires no `change` event; a design that
	 * recorded the value on `change` would search with the starting radius
	 * while the control on screen said something else, which is the defect this
	 * is fixing wearing a different hat.
	 *
	 * @param {object} instance The locator.
	 * @returns {number|null} The radius, or null when nothing usable is known.
	 */
	function radiusNow( instance ) {
		return chosenAmount( instance.element.querySelector( '.slosm__radius' ), instance.config.radius );
	}

	/**
	 * How many rows to show, read at the moment somebody asks for it.
	 *
	 * radiusNow() has the reasoning; this is the same control with a different
	 * class and a different starting value.
	 *
	 * @param {object} instance The locator.
	 * @returns {number|null} The limit, or null when nothing usable is known.
	 */
	function limitNow( instance ) {
		return chosenAmount( instance.element.querySelector( '.slosm__limit' ), instance.config.limit );
	}

	/**
	 * Turns a payload into the rows and pins this locator will show.
	 *
	 * Locations with no usable coordinates are dropped: they cannot be put on
	 * the map, they cannot be sorted by distance, and a row for one would be a
	 * result that does not answer the question that was asked. The admin
	 * warning is where an unplaced location gets fixed.
	 *
	 * `local` is the difference between the two modes and it is worth stating
	 * plainly, because getting it wrong is invisible:
	 *
	 * - In preload the browser holds the whole list and nothing has filtered
	 *   it, so the radius and the limit are applied here. Skipping that would
	 *   make the same site behave differently either side of the preload
	 *   threshold — every location below it, only the near ones above — across
	 *   a number no visitor can see.
	 * - In query mode /stores has already applied both, against the same
	 *   radius, and its answer is authoritative. Re-filtering it here could
	 *   only ever drop a borderline row the server meant to send. "The same
	 *   radius" is literal since Task 29a: locate() builds its query out of
	 *   radiusNow() and limitNow(), which is what the branch below reads, so
	 *   the two modes cannot ask different questions of the same control.
	 *
	 * The sort is Geo's arithmetic, never a private copy of it: the same list
	 * ordered by the server and by the browser has to come out in the same
	 * order, and no PHP test can see a number this file computed.
	 * tests/js/geo-crosscheck.test.js is what keeps the two in step.
	 *
	 * The category filter is here too, in the `local` branch and next to the
	 * radius, for the reason the radius is there: in query mode /stores has
	 * already applied it and its answer is authoritative, while a preloaded list
	 * has had nothing applied to it since it left the server with whatever
	 * category the *shortcode* pinned. A filter applied anywhere else would be a
	 * second place where the two modes can disagree.
	 *
	 * @param {object}      instance The locator.
	 * @param {Array}       items    Items from GET /stores.
	 * @param {number[]|null} origin The point searched from, or null.
	 * @param {boolean}     local    Whether the radius, limit and category still have to be applied.
	 * @returns {Array} The entries, nearest first when there is an origin.
	 */
	function entriesFor( instance, items, origin, local ) {
		var unit = unitOf( instance.config );
		var radius = local ? radiusNow( instance ) : null;
		var limit = local ? limitNow( instance ) : null;
		var category = local ? instance.category : '';
		var entries = [];

		items.forEach( function ( item ) {
			var place;
			var distance = null;

			if ( ! item || 'object' !== typeof item ) {
				return;
			}

			if ( '' !== category && ! inCategory( item, category ) ) {
				return;
			}

			place = point( item.lat, item.lng );

			if ( null === place ) {
				return;
			}

			if ( null !== origin ) {
				// The server's own number when it sent one, so that the row
				// says what the route said. Only a preloaded list has none.
				distance = finiteNumber( item.distance );

				if ( null === distance ) {
					distance = Geo.distance( origin[ 0 ], origin[ 1 ], place[ 0 ], place[ 1 ], unit );
				}

				if ( null !== radius && distance > radius ) {
					return;
				}
			}

			entries.push( {
				item: item,
				place: place,
				distance: distance,
				marker: null,
				row: null,
				icon: null,
				// The element this location's popup is made of, once it has a
				// pin. Held on the entry rather than looked up off the marker
				// so that a refill writes into the node Leaflet already has.
				popup: null,
			} );
		} );

		if ( null !== origin ) {
			entries.sort( function ( first, second ) {
				return first.distance - second.distance;
			} );
		}

		// Outside the sort, because the limit is not a fact about a search: it
		// is how many rows this locator was told to show, and a preloaded
		// locator that has never been searched has to honour it too. Before
		// the search existed this was the server's job — load() sent a limit —
		// and load()'s docblock has why that was the wrong place for it.
		if ( null !== limit && entries.length > limit ) {
			entries = entries.slice( 0, limit );
		}

		return entries;
	}

	/**
	 * Lights up one entry, or nothing, and makes sure only one is lit.
	 *
	 * The row and the pin are two views of one location, so hovering either
	 * has to say which. What is deliberately NOT done here is opening
	 * anything. Hovering is not asking, and a popup that opened under the
	 * pointer on the way past a pin would cover the four pins behind it;
	 * openEntry() and Leaflet's own click handler are where opening happens.
	 *
	 * @param {object}      instance The locator.
	 * @param {object|null} entry    The entry to light, or null for none.
	 * @returns {void}
	 */
	function highlight( instance, entry ) {
		var wanted = entry || null;

		if ( instance.lit === wanted ) {
			return;
		}

		if ( instance.lit ) {
			paint( instance.lit, false );
		}

		instance.lit = wanted;

		if ( wanted ) {
			paint( wanted, true );
		}
	}

	/**
	 * Puts the highlight out, but only if this entry is the one holding it.
	 *
	 * Moving a pointer from one row to the next fires the leaving event of the
	 * first and the entering event of the second, and nothing guarantees a
	 * handler sees them in that order once a browser is under load. Clearing
	 * unconditionally would put out a highlight somebody else had just lit.
	 *
	 * @param {object} instance The locator.
	 * @param {object} entry    The entry that was left.
	 * @returns {void}
	 */
	function unhighlight( instance, entry ) {
		if ( instance.lit === entry ) {
			highlight( instance, null );
		}
	}

	/**
	 * The class on the row and on the pin, on or off.
	 *
	 * The pin's element can be null — Leaflet's Marker.getElement() returns
	 * this._icon, which does not exist before the marker is added to a map and
	 * is set back to null when it is removed — so it is checked rather than
	 * assumed.
	 *
	 * @param {object}  entry The entry.
	 * @param {boolean} on    Whether to light it.
	 * @returns {void}
	 */
	function paint( entry, on ) {
		var icon = entry.marker && entry.marker.getElement ? entry.marker.getElement() : null;

		if ( entry.row ) {
			if ( on ) {
				entry.row.classList.add( 'slosm__result--active' );
			} else {
				entry.row.classList.remove( 'slosm__result--active' );
			}
		}

		if ( icon && icon.classList ) {
			if ( on ) {
				icon.classList.add( 'slosm__marker--active' );
			} else {
				icon.classList.remove( 'slosm__marker--active' );
			}
		}
	}

	/**
	 * Takes every pin off the map.
	 *
	 * @param {object} instance The locator.
	 * @returns {void}
	 */
	function clearMarkers( instance ) {
		// Two branches rather than one call on instance.layer, and the reason is
		// cost rather than correctness: markerClusterGroup.removeLayer() does
		// grid surgery per marker — it splices the marker out of its cluster,
		// may collapse the cluster it left, and re-indexes — so five hundred
		// removals is five hundred of those, on the very locator clustering
		// exists for. clearLayers() is the library's own bulk path and throws
		// the whole index away in one go.
		if ( instance.cluster ) {
			instance.cluster.clearLayers();
		} else {
			instance.markers.forEach( function ( marker ) {
				instance.map.removeLayer( marker );
			} );
		}

		instance.markers = [];
		instance.lit = null;
	}

	/**
	 * Gives one pin's icon the keyboard half of the highlight.
	 *
	 * Called once when the marker is created and again every time Leaflet fires
	 * `add` on it, which is what makes this a function rather than four lines
	 * inside pin(). An unclustered marker gets its icon once and is done. A
	 * clustered one has no icon at all while it is inside a bubble — Marker's
	 * `getElement:function(){return this._icon}`, and _icon is null until
	 * _initIcon runs — and gets a brand new one every time the zoom lets it out
	 * again. Binding only at creation time would mean a pin that has just
	 * appeared out of a cluster is not a focus stop that says anything.
	 *
	 * `entry.icon` is what keeps this idempotent. Leaflet hands back the same
	 * element for a marker that never really left the map, and a handler bound
	 * twice to one element is a leak that nothing on screen would ever show.
	 *
	 * The repaint at the end is the case a null-tolerant paint() does not cover
	 * on its own: a row can be hovered while its pin is inside a cluster, and
	 * paint() will have found no icon to mark. The icon that appears afterwards
	 * is new and carries none of that, so the highlight is put on it here.
	 *
	 * @param {object} instance The locator.
	 * @param {object} entry    The entry.
	 * @param {object} marker   The entry's marker.
	 * @returns {void}
	 */
	function bindIcon( instance, entry, marker ) {
		var icon = 'function' === typeof marker.getElement ? marker.getElement() : null;

		if ( ! icon || ! icon.addEventListener || entry.icon === icon ) {
			return;
		}

		entry.icon = icon;

		// Leaflet gives a marker's icon tabIndex 0 and role="button" —
		// `i.tabIndex="0",i.setAttribute("role","button")` — so it is a real
		// focus stop, and focus does not arrive as a layer event: it is a DOM
		// event on the icon.
		icon.addEventListener( 'focus', function () {
			highlight( instance, entry );
		} );
		icon.addEventListener( 'blur', function () {
			unhighlight( instance, entry );
		} );

		if ( instance.lit === entry ) {
			paint( entry, true );
		}
	}

	/**
	 * Marks one anchor as going somewhere that is not this site.
	 *
	 * Both attributes are decisions rather than boilerplate, and they are the
	 * same two Shortcode::row_template() writes onto the row's own directions
	 * link — this function exists so the two cannot drift.
	 *
	 * target="_blank": somebody reading a popup is in the middle of something.
	 * They have typed an address, or shared a location, or scrolled a list, and
	 * none of that survives a navigation away and a press of Back. A new tab
	 * costs them one close; replacing the page costs them the search.
	 *
	 * rel="noopener": a _blank target hands the opened page a live
	 * `window.opener` pointing at this one, which is enough to navigate it
	 * somewhere else. Modern browsers imply noopener for _blank, and WordPress
	 * 6.0's supported browsers are not all modern; writing it costs nothing and
	 * the assumption is not this plugin's to make.
	 *
	 * rel="noreferrer": no Referer header, so the address of the page the
	 * locator is embedded in — which on a real site is a page about a person's
	 * business, sometimes behind a login — is not sent to a third party. It
	 * also implies noopener, and both are written anyway: two names that mean
	 * one thing here are cheaper than a browser that honours only one of them.
	 *
	 * @param {Element} node The anchor.
	 * @returns {void}
	 */
	function outward( node ) {
		node.setAttribute( 'target', '_blank' );
		node.setAttribute( 'rel', 'noopener noreferrer' );
	}

	/**
	 * The post id of one item, or null when it is not one.
	 *
	 * A post id is a positive integer, and each of the three ways this can be
	 * something else is real rather than defensive. A hand-written data-slosm
	 * or a filtered payload can carry '3' as a string, which would build
	 * /stores/3 that happens to work and hide the bug. A 1.5 would build
	 * /stores/1.5, which the route's `\d+` never matches and which answers 404.
	 * A 0 is what a payload missing the field turns into under any coercion,
	 * and /stores/0 is a request nobody meant to make.
	 *
	 * Returning null means "do not ask", not "this location is broken": the
	 * popup is still built from the lean payload, which is where a name and an
	 * address come from anyway.
	 *
	 * @param {object} item One item from the payload.
	 * @returns {number|null} The id, or null.
	 */
	function storeId( item ) {
		var id = finiteNumber( item.id );

		if ( null === id || id < 1 || id !== Math.floor( id ) ) {
			return null;
		}

		return id;
	}

	/**
	 * One coordinate, at the precision this plugin is willing to hand over.
	 *
	 * The same COORD_PLACES onLocated() uses, applied at the other end of the
	 * journey. Two places need it and they need it for opposite reasons:
	 * onLocated() rounds so that a device position is never *held* at full
	 * precision, and this rounds so that a point held at full precision — a
	 * geocoded address, which has every right to be exact on the server — is
	 * never *published* at it. directionsUrl() has which is which.
	 *
	 * Rounding an already-rounded number is a no-op, so the device path costs
	 * nothing and the two cannot disagree about what "coarse" means.
	 *
	 * @param {number} value A latitude or a longitude.
	 * @returns {number} The same, to COORD_PLACES.
	 */
	function coarse( value ) {
		return Math.round( value * COORD_PLACES ) / COORD_PLACES;
	}

	/**
	 * The url of a route out to openstreetmap.org for one destination.
	 *
	 * The `from` half is the point the last search was made from — the
	 * visitor's own position after "use my location", or the address they
	 * typed — and is left empty when there has been no search. One parameter
	 * either way; DIRECTIONS has the measurement that says so.
	 *
	 * ON PUTTING A VISITOR'S OWN COORDINATE IN A THIRD PARTY'S URL
	 * ============================================================
	 * This is the one place in the plugin where a point about a *person* leaves
	 * the site at all, and there are two of those points rather than one. An
	 * earlier version of this paragraph named only the first, and the omission
	 * was the more dangerous of the two.
	 *
	 * The first is a device position. onLocated() rounds it to COORD_PLACES
	 * before this file will handle it at all, so it arrives here already about
	 * eleven metres coarse.
	 *
	 * The second is a typed address, geocoded. That one arrives at whatever
	 * precision Nominatim answered with — ten or more digits for a rooftop
	 * match — because Geocoder runs on the server and the rounding that covers
	 * the device path is nowhere near it. And it is the *more* sensitive of the
	 * two, not the less: until this link is clicked the visitor's browser has
	 * told openstreetmap.org nothing at all, the lookup having gone through the
	 * site; and an address somebody types into a store locator is very often
	 * their own home. The claim "this is already coarsened" was true of the
	 * path it was written about and false of this one.
	 *
	 * So the rounding is here, on the way into the url, and applies to both.
	 * Deliberately NOT on the way into instance.origin: the browser sorts a
	 * preloaded list itself with Geo.distance(), so the point it measures from
	 * has to be the same point the server measured from, and rounding at the
	 * source would put tests/js/geo-crosscheck.test.js's guarantee quietly out
	 * of reach. COORD_PLACES has that argument in full. The destination is not
	 * rounded and must not be: it is a shop's published address, and eleven
	 * metres of slack on the end of a route is a pin in the road outside.
	 *
	 * Two things beside the rounding, and all three are needed. It only travels
	 * when the link is *clicked*, which is somebody asking to be routed from
	 * where they are — the href sits unvisited in the markup until then, and
	 * nothing here requests it. And the anchor carries rel="noreferrer", so the
	 * page they were on does not travel with it.
	 *
	 * url() cannot fail here and is not guarded. Every other caller hands it a
	 * route out of the config, which is why those check for null; this one
	 * hands it a literal constant in this file, and the input that would
	 * separate a guarded version from this one — a route somebody configured —
	 * does not exist on this path.
	 *
	 * @param {object}   instance The locator.
	 * @param {number[]} place    The destination, already validated by point().
	 * @returns {string} The url.
	 */
	function directionsUrl( instance, place ) {
		var from = instance.origin;
		var target = instance.config && instance.config.directions;

		if ( 'none' === target ) {
			return null;
		}

		if ( 'google' === target ) {
			return url( GOOGLE_DIRECTIONS, {
				api: '1',
				origin: null === from ? '' : coarse( from[ 0 ] ) + ',' + coarse( from[ 1 ] ),
				destination: place[ 0 ] + ',' + place[ 1 ],
			} );
		}

		return url( DIRECTIONS, {
			route:
				( null === from ? '' : coarse( from[ 0 ] ) + ',' + coarse( from[ 1 ] ) ) +
				';' +
				place[ 0 ] +
				',' +
				place[ 1 ],
		} );
	}

	/**
	 * The first of two values that is a non-empty string.
	 *
	 * Which is how the full record and the lean payload are reconciled: both
	 * carry a name, an address and a city, the full one is authoritative, and
	 * an empty string in it must not blank out what is already on screen.
	 *
	 * @param {*} first  Preferred value.
	 * @param {*} second Fallback.
	 * @returns {*} One of them.
	 */
	function pick( first, second ) {
		return 'string' === typeof first && '' !== first ? first : second;
	}

	/**
	 * One line of a popup, as text, or nothing when there is nothing to say.
	 *
	 * The type check is cell()'s, for cell()'s reason: a field that arrived as
	 * a number or an object would otherwise be "[object Object]" in front of
	 * somebody. Nothing here is ever a string handed to a parser — see
	 * fillPopup().
	 *
	 * @param {Document} doc    The document.
	 * @param {Element}  parent Where it goes.
	 * @param {string}   name   Becomes slosm__popup-{name}.
	 * @param {*}        value  What the record carried.
	 * @returns {Element|null} The line, or null.
	 */
	function popupLine( doc, parent, name, value ) {
		var node;

		if ( 'string' !== typeof value || '' === value ) {
			return null;
		}

		node = doc.createElement( 'p' );
		node.className = 'slosm__popup-' + name;
		node.textContent = value;
		parent.appendChild( node );

		return node;
	}

	/**
	 * The postal address as a block, newline separated.
	 *
	 * One element rather than six, with the newlines kept by the stylesheet's
	 * `white-space: pre-line` on this class. The alternative — a node per
	 * field — would need this file to decide where a comma goes between a city
	 * and a postcode, and that order is different in half the countries this
	 * plugin will be installed in. A line per line is the only ordering an
	 * editor can control, and they already control it by what they type.
	 *
	 * The lean payload has two of the six, so this block grows when the full
	 * record lands. That is the popup filling in rather than a redraw.
	 *
	 * @param {object} item One item from the payload.
	 * @param {object} full The full record, or `{}`.
	 * @returns {string} The block, or '' when nothing is known.
	 */
	function addressBlock( item, full ) {
		var lines = [];

		[
			pick( full.address, item.address ),
			full.address2,
			pick( full.city, item.city ),
			full.state,
			full.zip,
			full.country,
		].forEach( function ( value ) {
			if ( 'string' === typeof value && '' !== value ) {
				lines.push( value );
			}
		} );

		return lines.join( '\n' );
	}

	/**
	 * A location's website as a link, or null when it must not be one.
	 *
	 * The scheme check is the whole function. `href` is a script sink that the
	 * no-innerHTML rule says nothing about: `javascript:alert(1)` in an href is
	 * one click from execution, and the url field is free text somebody typed
	 * into a metabox — on a multi-author site, not necessarily somebody the
	 * site owner would trust with unfiltered_html. data: and vbscript: are the
	 * same shape of problem.
	 *
	 * So only http and https become links, and everything else is handed back
	 * as null and rendered as plain text by the caller. Shown rather than
	 * dropped: an editor who typed something odd should be able to see what
	 * they typed.
	 *
	 * No base is passed to URL, which makes a relative value throw and become
	 * text as well. That is deliberate. Resolving 'example.com' against the
	 * page would produce a link to a path on *this* site that does not exist,
	 * which looks like the plugin mangling the field rather than the field
	 * being incomplete.
	 *
	 * @param {Document} doc   The document.
	 * @param {*}        value What the record carried.
	 * @returns {Element|null} The anchor, or null.
	 */
	function website( doc, value ) {
		var parsed;
		var node;

		if ( 'string' !== typeof value || '' === value ) {
			return null;
		}

		try {
			parsed = new URL( value );
		} catch ( error ) {
			return null;
		}

		if ( 'http:' !== parsed.protocol && 'https:' !== parsed.protocol ) {
			return null;
		}

		node = doc.createElement( 'a' );
		node.setAttribute( 'href', parsed.toString() );
		node.textContent = value;
		outward( node );

		return node;
	}

	/**
	 * Builds one popup's contents into a node this file owns.
	 *
	 * THE REASON THIS BUILDS A NODE AND NEVER A STRING
	 * ================================================
	 * Leaflet's `Popup.setContent()` puts a string through innerHTML:
	 * `_updateContent` is `if("string"==typeof e)t.innerHTML=e;else{for(;
	 * t.hasChildNodes();)t.removeChild(t.firstChild);t.appendChild(e)}`, at
	 * byte offset 95938 of assets/leaflet/leaflet.js. So `bindPopup( '<p>' +
	 * name + '</p>' )` with a title from a user with unfiltered_html is stored
	 * xss — and it is xss the forbidden-property scan in
	 * tests/js/locator.test.js cannot see, because that scan reads this file
	 * and the innerHTML in question is Leaflet's.
	 *
	 * Handing over an Element takes the other branch, where the node is
	 * appended and no parser runs at all. It is the same defence Task 15 built
	 * for the cluster icon against L.DivIcon, and the same reason: a rule about
	 * what *can* be put in beats a rule about what is put in. Every value below
	 * goes in with textContent or through setAttribute on an href this file has
	 * already checked the scheme of.
	 *
	 * Called twice per popup on the path that matters — once from
	 * attachPopup() with whatever the lean payload carried, once more when
	 * /stores/<id> answers — and into the same node both times, so Leaflet is
	 * handed the node it already has rather than a replacement.
	 *
	 * The destination of the directions link is entry.place and never the
	 * record's own lat/lng. Rest_Controller::get_item_schema() types those as
	 * number-or-null because a location an editor has not geocoded yet is an
	 * ordinary state, and a popup exists only where a marker does, so
	 * entry.place is a point that has already been through point(). Reading the
	 * record instead would put "null,null" in a url on exactly the records the
	 * schema warns about.
	 *
	 * @param {object}      instance The locator.
	 * @param {object}      entry    The entry.
	 * @param {Element}     node     The popup's own element.
	 * @param {object|null} full     The record from /stores/<id>, or null.
	 * @returns {void}
	 */
	function fillPopup( instance, entry, node, full ) {
		var doc = instance.element.ownerDocument;
		var item = entry.item;
		var known = full && 'object' === typeof full && ! Array.isArray( full ) ? full : {};
		var names = Array.isArray( known.categories ) ? known.categories : item.categories;
		var site;
		var box;
		var go;
		var route;

		empty( node );

		if ( shows( instance.config, 'popup', 'name' ) ) {
			popupLine( doc, node, 'name', pick( known.name, item.name ) );
		}

		if ( shows( instance.config, 'popup', 'address' ) ) {
			popupLine( doc, node, 'address', addressBlock( item, known ) );
		}

		if ( ! shows( instance.config, 'popup', 'categories' ) ) {
			names = null;
		}

		if ( Array.isArray( names ) ) {
			box = doc.createElement( 'ul' );
			box.className = 'slosm__popup-categories';

			names.forEach( function ( name ) {
				var tag;

				if ( 'string' !== typeof name || '' === name ) {
					return;
				}

				tag = doc.createElement( 'li' );
				tag.className = 'slosm__popup-category';
				tag.textContent = name;
				box.appendChild( tag );
			} );

			if ( box.firstChild ) {
				node.appendChild( box );
			}
		}

		if ( shows( instance.config, 'popup', 'phone' ) ) {
			popupLine( doc, node, 'phone', known.phone );
		}

		if ( shows( instance.config, 'popup', 'email' ) ) {
			popupLine( doc, node, 'email', known.email );
		}

		// A phone number and an email address as text rather than tel: and
		// mailto: links. Both would be another href built out of a field
		// somebody typed, for a gain that is a long press on a phone and
		// nothing at all on a desktop with no mail client configured. Task 24
		// owns the accessibility pass and can revisit it with the scheme check
		// website() already has.
		if ( shows( instance.config, 'popup', 'url' ) ) {
			site = website( doc, known.url );

			if ( null === site ) {
				popupLine( doc, node, 'url', known.url );
			} else {
				box = doc.createElement( 'p' );
				box.className = 'slosm__popup-url';
				box.appendChild( site );
				node.appendChild( box );
			}
		}

		// The opening hours are the one field whose newlines mean something —
		// Admin::print_hours() renders a textarea and the value is stored with
		// its line breaks — and `white-space: pre-line` on .slosm__popup-hours
		// in assets/css/locator.css is what keeps them, as it has since Task 16.
		//
		// That is recorded here because the plan asked Task 21 for an
		// "opening-hours format" setting and there is not one. There is no
		// structured time here to format: the field is free text, so the only
		// real decision is whether the newlines survive — which has one right
		// answer and was already made — and a site whose hours are a single
		// "Mon-Fri 9-17" cannot tell the two settings apart at all. An option
		// nobody can be given a reason to change is the kind the closed list
		// exists to keep out. What a site *can* decide is whether the field is
		// published at all, which is the line below.
		if ( shows( instance.config, 'popup', 'hours' ) ) {
			popupLine( doc, node, 'hours', known.hours );
		}

		if ( shows( instance.config, 'popup', 'description' ) ) {
			popupLine( doc, node, 'description', known.description );
		}

		// The way out, unless the site turned it off. Nothing is appended at
		// all in that case: an anchor with no href is a link to the page it is
		// on, and the "no link" choice means no link rather than a dead one.
		route = directionsUrl( instance, entry.place );

		if ( null !== route ) {
			go = doc.createElement( 'a' );
			go.className = 'slosm__popup-directions';
			go.setAttribute( 'href', route );
			go.textContent = text( 'directions' );
			outward( go );
			node.appendChild( go );
		}
	}

	/**
	 * What a popup does to the page around it once Leaflet has drawn it.
	 *
	 * Four things Task 24b answers, all of them read out of
	 * assets/leaflet/leaflet.js rather than assumed:
	 *
	 * - **Nothing moves the focus.** The only `focus()` in the vendored build
	 *   is `_refocusOnMap`, on the zoom and layers controls, guarded on a real
	 *   pointer. So pressing a row's button left the focus on the button while
	 *   the bubble appeared inside the map — and Shortcode::render() emits the
	 *   map *before* the results list, so reaching it meant shift-tabbing
	 *   backwards past the whole map. The content node is given `tabindex=-1`
	 *   and the focus: programmatically focusable, not a stop in the tab
	 *   sequence, which is what a thing the focus is *sent* to should be.
	 * - **The close button is labelled in English, always.**
	 *   `i.setAttribute("aria-label","Close popup")` in `_initLayout`, with no
	 *   translation reaching it and no option to change it. The only answer is
	 *   to write over it once the popup exists, which is a decision rather
	 *   than a tidy-up: it is this plugin reaching into a vendored library's
	 *   DOM, and it is narrow on purpose — one attribute, on one element, that
	 *   Leaflet sets once when it builds the popup.
	 * - **The opener said nothing about what it had done.** aria-expanded and
	 *   aria-controls go on while the popup is open and come off when it
	 *   closes, because the element they name does not exist until Leaflet
	 *   builds it: aria-controls pointing at an id that is not in the document
	 *   is worse than no aria-controls at all.
	 * - **And a pin is the other door.** A popup opened by clicking the marker
	 *   has no opener, so nothing may claim to be expanded — which is why
	 *   `instance.opener` is cleared on every close rather than only reassigned
	 *   on every open.
	 *
	 * What is deliberately not done here: the popup's name is a styled <p> and
	 * not a heading, because a heading needs a level and the level depends on
	 * the page the shortcode was dropped into, which nothing here can see.
	 *
	 * @param {object} instance The locator.
	 * @param {object} marker   The marker whose popup this is.
	 * @returns {void}
	 */
	function wirePopup( instance, marker ) {
		/*
		 * The button that opened *this* marker's popup, remembered where only
		 * this marker's two handlers can see it.
		 *
		 * It used to be read straight off `instance.opener` in both, and that
		 * is the defect Task 34 fixes. One shared slot cannot answer "which
		 * button does this popup belong to" once two popups are involved,
		 * because Leaflet fires **popupclose for the old one before popupopen
		 * for the new one**, both out of a single openPopup() call —
		 * `Map.openPopup` begins with `this.closePopup()` and
		 * `Popup.options.autoClose` is true. So by the time the close handler
		 * ran, the row that was about to open had already overwritten the
		 * slot: the close cleared the *new* button, the open then found the
		 * slot empty and set nothing, and the row pressed first kept an
		 * aria-expanded="true" and an aria-controls naming a popup that had
		 * gone. Measured on a live page as "from the second click on, nothing
		 * moves".
		 *
		 * wirePopup() runs once per marker, so this closure is per popup,
		 * which is exactly the granularity the question has. `instance.opener`
		 * survives as what it always should have been: a handoff from the
		 * click to the open that follows it, consumed on arrival and never
		 * read again.
		 */
		var mine = null;

		if ( 'function' !== typeof marker.on ) {
			return;
		}

		marker.on( 'popupopen', function ( event ) {
			var popup = event && event.popup ? event.popup : null;
			var element = popup && 'function' === typeof popup.getElement ? popup.getElement() : null;
			var content;
			var close;

			if ( ! element ) {
				return;
			}

			if ( ! element.getAttribute( 'id' ) ) {
				element.setAttribute( 'id', 'slosm-' + instance.serial + '-popup' );
			}

			close = element.querySelector( '.leaflet-popup-close-button' );

			if ( close ) {
				close.setAttribute( 'aria-label', text( 'closePopup' ) );
			}

			content = element.querySelector( '.leaflet-popup-content' );

			if ( content ) {
				content.setAttribute( 'tabindex', '-1' );

				if ( 'function' === typeof content.focus ) {
					content.focus();
				}
			}

			// Taken, not borrowed. Anything still in the slot after this line
			// would be read by the next popup to open, which is how one stale
			// click could light a row that had nothing to do with it.
			mine = instance.opener;
			instance.opener = null;

			if ( mine ) {
				mine.setAttribute( 'aria-expanded', 'true' );
				mine.setAttribute( 'aria-controls', element.getAttribute( 'id' ) );
			}

			/*
			 * And the way out, which is this plugin's to own because the line
			 * above it is.
			 *
			 * Leaflet has an Escape of its own and it is unreachable here.
			 * Its Keyboard handler binds the keydown on *focus* of the map
			 * container and unbinds it on blur — `S(t,{focus:this._onFocus,
			 * blur:this._onBlur,…})` with `this._map.on({focus:this._addHooks,
			 * blur:this._removeHooks})` — so the moment the focus goes into
			 * the popup, the container blurs and Leaflet's own key handling
			 * switches itself off. Taking the focus and leaving the key to
			 * somebody else is a keyboard trap with a mouse-only door.
			 *
			 * Bound on the popup element rather than the document, so this
			 * listens where it has business listening: one locator's open
			 * popup, not every Escape on the page. Two locators are two
			 * elements and two handlers, and neither can close the other's.
			 *
			 * Not prevented: Escape has no default action in any browser this
			 * supports, and a preventDefault here would be a claim over a key
			 * the rest of the page may also want.
			 */
			if ( instance.escape ) {
				element.removeEventListener( 'keydown', instance.escape );
			}

			instance.escape = function ( keyed ) {
				if ( ! keyed || 'Escape' !== keyed.key ) {
					return;
				}

				marker.closePopup();
			};

			element.addEventListener( 'keydown', instance.escape );
		} );

		marker.on( 'popupclose', function ( event ) {
			var popup = event && event.popup ? event.popup : null;
			var element = popup && 'function' === typeof popup.getElement ? popup.getElement() : null;
			var opener = mine;

			// This popup's own, cleared here rather than anywhere else: a
			// second close on an already-closed popup must not reach back and
			// clear a button that now belongs to a different one.
			mine = null;

			// Off again, and before the early return below: a popup opened
			// from a pin has no opener, and a handler left bound to a closed
			// popup would still be there when the next open bound another.
			if ( instance.escape && element ) {
				element.removeEventListener( 'keydown', instance.escape );
				instance.escape = null;
			}

			if ( ! opener ) {
				return;
			}

			opener.setAttribute( 'aria-expanded', 'false' );
			opener.removeAttribute( 'aria-controls' );

			/*
			 * And the focus comes back, because it was taken. A popup whose
			 * element is removed from the document with the focus inside it
			 * leaves the focus on <body>, which is the far end of the page
			 * from the row somebody was reading.
			 *
			 * Only when the focus is still in the popup that just closed:
			 * a visitor who clicked away, or tabbed on, has moved on
			 * deliberately and pulling them back would be this script taking
			 * the focus off whatever they chose.
			 */
			if ( element && inside( element, opener.ownerDocument.activeElement ) && 'function' === typeof opener.focus ) {
				opener.focus();
			}
		} );
	}

	/**
	 * Whether a node is the given element or inside it.
	 *
	 * Walked rather than `element.contains( node )`, because `contains` is a
	 * Node method this file would then be relying on across the whole range of
	 * browsers a WordPress plugin meets, and the walk is four lines.
	 *
	 * @param {object} element The ancestor to test against.
	 * @param {object} node    The node in question, or null.
	 * @returns {boolean}
	 */
	function inside( element, node ) {
		var walk = node;

		while ( walk ) {
			if ( walk === element ) {
				return true;
			}

			walk = walk.parentNode;
		}

		return false;
	}

	/**
	 * Gives one pin a popup, already filled with everything known so far.
	 *
	 * Bound at pin time rather than at click time, and that is the whole of
	 * "open immediately". Leaflet's own bindPopup registers the click handler
	 * that opens it — `this.on({click:this._openPopup,…})` — so by the time
	 * somebody presses a pin there is nothing left to build: the bubble opens
	 * with the name and the address that were already in the browser, and the
	 * fetch below fills in the rest underneath it. An empty popup that appears
	 * a second later is worse than a partial one that appears at once.
	 *
	 * The cache is consulted here as well as on click, which is what makes a
	 * redraw cheap. Every entry, marker and popup node is thrown away and
	 * rebuilt whenever show() runs — a category change, a new search — and a
	 * location whose record is already in memory gets a complete popup on the
	 * first frame rather than a partial one and a request.
	 *
	 * WHAT THE REFILL COSTS, WHICH IS NOT NOTHING
	 * ===========================================
	 * "Open at once and fill in later" is the right trade and it is not a free
	 * one, so the price is written next to the argument for it rather than
	 * discovered on a slow connection.
	 *
	 * The popup pans the map. Leaflet's Popup defaults include `autoPan:!0`
	 * and `DivOverlay.update` ends `...this._updatePosition(),this._container.
	 * style.visibility="",this._adjustPan()` — both read in the vendored
	 * assets/leaflet/leaflet.js. So a popup that grows when the record lands
	 * moves the map under the pointer, a few hundred milliseconds after it
	 * opened. That is the visible price of not waiting, and it is paid once per
	 * location: the second open of the same pin is served from the cache and
	 * does not resize.
	 *
	 * And fillPopup() empties the node before rebuilding it, so a focus that
	 * was inside the popup is lost when the record arrives. Narrow today —
	 * nothing in a partial popup is focusable except the directions link, and
	 * reaching it inside the fetch window takes a deliberate tab — but it is
	 * real, and it is Task 24's to decide about along with the rest of the
	 * focus story below.
	 *
	 * WHAT TASK 24 INHERITED FROM HERE, AND WHAT IS LEFT OF IT
	 * ========================================================
	 * Four things were recorded here rather than fixed, because the
	 * accessibility pass is one task and guessing at half of it here would have
	 * been worse than leaving it whole. Task 24b answered three, in wirePopup()
	 * above: the focus moves into the popup and comes back when it closes, the
	 * opener carries aria-expanded and aria-controls while there is something to
	 * point at, and the close button's English aria-label is written over with
	 * this plugin's own translated string.
	 *
	 * The fourth stands, and is the one that cannot be answered from here: the
	 * popup's name is a styled `<p>` rather than a heading. A heading needs a
	 * level, the level depends on the page the shortcode was dropped into, and
	 * nothing in this file can see that page. A site that knows its own
	 * templates can style or replace it; a plugin that cannot see them should
	 * not pick an h-level for everybody.
	 *
	 * @param {object} instance The locator.
	 * @param {object} entry    The entry.
	 * @param {object} marker   The entry's marker.
	 * @returns {void}
	 */
	function attachPopup( instance, entry, marker ) {
		var doc = instance.element.ownerDocument;
		var id = storeId( entry.item );
		var node;

		// Guarded because `bindPopup` belongs to Leaflet's Layer, and a site
		// may have registered something else under the slosm-leaflet handle.
		// A locator with no popups is a worse locator than one with them and a
		// far better one than a TypeError halfway through drawing the map.
		if ( 'function' !== typeof marker.bindPopup ) {
			return;
		}

		node = doc.createElement( 'div' );
		node.className = 'slosm__popup';

		entry.popup = node;

		fillPopup( instance, entry, node, null === id ? null : instance.records[ id ] || null );

		marker.bindPopup( node );

		wirePopup( instance, marker );

		if ( 'function' === typeof marker.on ) {
			// Leaflet has already bound its own click handler, which opens the
			// bubble. This one does not open anything; it asks for what the
			// bubble does not have yet.
			marker.on( 'click', function () {
				askRecord( instance, entry );
			} );
		}
	}

	/**
	 * The full record for one location, from memory or from the route.
	 *
	 * Two maps rather than one, and the difference between them is the
	 * difference between "answered" and "being answered". `records` is the
	 * cache and only ever holds a successful answer; `recordRequests` holds the
	 * promise while it is in flight, so three clicks on one pin before the
	 * first answer lands are one request and not three. A cache of promises
	 * alone would have to decide what to do with a rejected one, and the answer
	 * is the one thing a cache must not do: remember it. A transient 503 that
	 * poisoned the entry would leave that pin partial for as long as the page
	 * is open, so the in-flight entry is dropped on failure and the next open
	 * asks again.
	 *
	 * No AbortController and no search token. This request belongs to a popup
	 * rather than to a search run: somebody who opens a pin and then types an
	 * address has not withdrawn the question, and cancelling would throw away
	 * an answer that is about to be cached anyway. Nothing here can be stale,
	 * because a record is a fact about one location and not about one run.
	 *
	 * `window.fetch` is not checked for being callable, unlike init(), and the
	 * reason is that this function is unreachable without it: a popup exists
	 * only on a marker, a marker only on a payload, and a payload only through
	 * a fetch that already worked. A guard here would be one no input can
	 * reach.
	 *
	 * @param {object} instance The locator.
	 * @param {number} id       The post id, already through storeId().
	 * @returns {Promise} Resolves with the record, or with null on any failure.
	 */
	function recordFor( instance, id ) {
		var route = instance.config.routes.store;
		var target;

		if ( instance.records[ id ] ) {
			return Promise.resolve( instance.records[ id ] );
		}

		if ( instance.recordRequests[ id ] ) {
			return instance.recordRequests[ id ];
		}

		// Shortcode::routes() ships this route with __ID__ in it rather than
		// %d, and its docblock has why: add_query_arg() places the route
		// verbatim on the plain-permalink branch, so a %d would survive into
		// the url as a malformed percent escape. Replacing a plain token is
		// what makes both url shapes work with one line.
		target = 'string' === typeof route ? url( route.replace( '__ID__', String( id ) ), {} ) : null;

		if ( null === target ) {
			// A hand-written data-slosm, or a filter that dropped the route.
			// readConfig() only insists on `stores`, because that is the one
			// without which nothing can happen at all; this one costs a popup
			// some extra fields and nothing else.
			return Promise.resolve( null );
		}

		instance.recordRequests[ id ] = window
			.fetch( target, { credentials: 'same-origin' } )
			.then( function ( response ) {
				if ( ! response.ok ) {
					throw new Error( 'GET /stores/' + id + ' answered ' + response.status );
				}

				return response.json();
			} )
			.then( function ( full ) {
				if ( ! full || 'object' !== typeof full || Array.isArray( full ) ) {
					throw new Error( 'GET /stores/' + id + ' did not answer with a location' );
				}

				delete instance.recordRequests[ id ];
				instance.records[ id ] = full;

				return full;
			} )
			.catch( function ( error ) {
				delete instance.recordRequests[ id ];

				// A line for a developer and nothing for the visitor. Unlike a
				// failed /stores, this failure destroys nothing: the popup is
				// open, it has a name and an address in it, and the map and the
				// results list behind it are untouched. fail() here would paint
				// a locator red over a phone number that did not arrive.
				warn( error );

				return null;
			} );

		return instance.recordRequests[ id ];
	}

	/**
	 * Asks for what this popup does not have yet, once.
	 *
	 * The early return on a cached record is not merely an optimisation of
	 * recordFor()'s own cache lookup: it also skips the refill and the
	 * setPopupContent that would follow it, so reopening a pin whose record is
	 * already in the node does not hand Leaflet the same node again and make it
	 * measure and reposition a bubble that has not changed.
	 *
	 * `instance.pendingRecord` is last-write-wins, and that is a seam rather
	 * than a bug. It exists so a test can wait for this work instead of for a
	 * number of microtask turns, the way pendingSearch and pendingSuggest do,
	 * and two pins opened in one turn leave only the second promise on it.
	 * Nothing is wrong today because nothing chains anything to it and every
	 * case settles one popup before opening the next — but the first case that
	 * clicks two pins without awaiting in between will be waiting for the
	 * wrong one, and will look like a flaky test rather than like this line.
	 * If that day comes, this wants to be a list or a count, not a promise.
	 *
	 * @param {object} instance The locator.
	 * @param {object} entry    The entry.
	 * @returns {void}
	 */
	function askRecord( instance, entry ) {
		var id = storeId( entry.item );
		var node = entry.popup;

		if ( null === id || ! node || instance.records[ id ] ) {
			return;
		}

		instance.pendingRecord = recordFor( instance, id ).then( function ( full ) {
			if ( null === full ) {
				return;
			}

			fillPopup( instance, entry, node, full );

			// The same node Leaflet already holds, handed back so that it
			// re-measures: `setPopupContent` is `this._popup&&this._popup.
			// setContent(t)`, and `setContent` ends in `this.update()`, which
			// is a no-op on a closed popup and a relayout on an open one.
			// Mutating the node without this would change what is in the
			// bubble and leave it the size it was.
			//
			// This entry may be gone by now — a category change or a new
			// search throws every entry, marker and popup away while a record
			// is still in flight — and that is safe rather than merely
			// unlikely. The node written into is this closure's own, the
			// marker written to has been removed from the map, and Leaflet
			// closes a removed layer's popup (`remove:this.closePopup` among
			// the handlers bindPopup adds), which leaves `update()` with no
			// `_map` and nothing to do. The record is in the cache either way,
			// which is what the entry that replaced this one will read.
			if ( entry.marker && 'function' === typeof entry.marker.setPopupContent ) {
				entry.marker.setPopupContent( node );
			}
		} );
	}

	/**
	 * Opens one entry's popup from somewhere that is not the pin itself.
	 *
	 * The row's button, today. Leaflet opens a popup on a click on its own
	 * marker and this is the other door into the same bubble, which is why it
	 * asks for the record as well: a row and a pin are two views of one
	 * location and pressing either has to produce the same thing.
	 *
	 * A clustered pin is not on the map — a marker inside a bubble has no icon,
	 * which is the behaviour Task 15's cases pin — so openPopup() on one would
	 * open nothing at all. Leaflet.markercluster's own zoomToShowLayer gets it
	 * out first and then calls back: `zoomToShowLayer:function(e,t){…e._icon&&
	 * this._map.getBounds().contains(e.getLatLng())?t():…}` at byte offset 6695
	 * of assets/markercluster/leaflet.markercluster.js, where the other
	 * branches pan or zoom and call back on the map's 'moveend'. A marker that
	 * is already visible is called back synchronously, so the ordinary case
	 * costs nothing.
	 *
	 * @param {object} instance The locator.
	 * @param {object} entry    The entry.
	 * @returns {void}
	 */
	function openEntry( instance, entry ) {
		var marker = entry.marker;
		var here;
		var floor;

		askRecord( instance, entry );

		if ( ! marker || 'function' !== typeof marker.openPopup ) {
			return;
		}

		if ( instance.cluster && 'function' === typeof instance.cluster.zoomToShowLayer ) {
			instance.cluster.zoomToShowLayer( marker, function () {
				marker.openPopup();
			} );

			return;
		}

		/*
		 * And the map goes to the pin, which nothing did before Task 32.
		 * Leaflet's autoPan runs on open and moves the map by the least it can
		 * to fit the *bubble*, so a pin near an edge stays near the edge with
		 * its bubble hanging off it. autoPan is not wrong; it is looking after
		 * a different thing.
		 *
		 * One setView rather than a pan and a zoom, because centring and
		 * closeness are one answer to one press and two calls would be two
		 * animations over the same map.
		 *
		 * THE ZOOM IS A FLOOR, NOT A JUMP
		 * ===============================
		 * `max` of what is on screen and what the shortcode asked for, which
		 * has both halves of the argument in it:
		 *
		 * - A visitor pulled back to zoom 8 gets a pin that is a dot in a
		 *   region, under a bubble naming a street the map cannot show. The
		 *   floor lifts them to something the address means.
		 * - A visitor who zoomed to 17 to read a street is reading it. A press
		 *   that says "show me this one" may not also say "forget how close
		 *   you were looking", so the floor never pulls anybody back.
		 *
		 * The number is the shortcode's own zoom and no new setting, which is
		 * the same one renderResults() caps framing with — one number holding
		 * two bounds: a frame never goes closer than it, and opening one
		 * location never leaves you further out than it.
		 *
		 * Guarded for a map with no zoom to report. initLocator() always sets
		 * a view before anything can reach here, so this is a floor under an
		 * unreachable state rather than a real one — but `Math.max` with an
		 * undefined is NaN, and a NaN zoom is a blank map.
		 *
		 * Not in the clustered branch above, and that is deliberate rather
		 * than forgotten: zoomToShowLayer pans and zooms on its own to get the
		 * marker out of its bubble, and a move of ours racing it would be two
		 * hands on the same map.
		 */
		if ( 'function' === typeof instance.map.setView && entry.place ) {
			here = 'function' === typeof instance.map.getZoom ? finiteNumber( instance.map.getZoom() ) : null;
			floor = zoomLevel( instance.config.zoom );

			instance.map.setView( entry.place, null === here ? floor : Math.max( here, floor ) );
		}

		marker.openPopup();
	}

	/**
	 * Whether one field is on one of the two lists a site chose.
	 *
	 * Missing, not an array, or an entry that is not a string all mean "show
	 * it": every one of those is a config this plugin did not write, and the
	 * failure that matters is a locator that silently stops showing the name of
	 * every shop on it. Settings::field_list() makes the same choice on the
	 * other side, where an empty choice takes the whole list.
	 *
	 * @param {object} config The locator config.
	 * @param {string} list   'fields' for a row, 'popup' for a bubble.
	 * @param {string} field  Field name.
	 * @returns {boolean}
	 */
	function shows( config, list, field ) {
		var chosen = config && config[ list ];

		if ( ! Array.isArray( chosen ) ) {
			return true;
		}

		return -1 !== chosen.indexOf( field );
	}

	/**
	 * Takes one element out of the tree, if it is there.
	 *
	 * Removed rather than emptied or hidden, and the difference is what a
	 * screen reader does with it: an empty <span> still occupies a line in the
	 * accessibility tree, and `display:none` is a rule a theme can override.
	 * A row somebody chose not to have a city in has no city element.
	 *
	 * @param {Element|null} node The element, or null.
	 * @returns {void}
	 */
	function drop( node ) {
		if ( node && node.parentNode ) {
			node.parentNode.removeChild( node );
		}
	}

	/**
	 * The coloured dot a site asked for, or null for the standard pin.
	 *
	 * WHY THERE IS A SECOND MARKER STYLE AT ALL
	 * =========================================
	 * Leaflet's default marker is a 25 by 41 PNG, vendored in
	 * assets/leaflet/images/. It is an image, so a site cannot restyle it with
	 * css — which makes it the one thing on the map that a dark theme or a
	 * brand palette cannot reach. That is the sentence the setting is justified
	 * by; everything else about a marker is already a stylesheet's business.
	 *
	 * A DivIcon and not an image, so there is no second file to ship and the
	 * colour is a css declaration rather than a generated sprite. The node is
	 * built with createElement and handed to L.divIcon as an **Element**, never
	 * as a string: `createIcon` is
	 * `e.html instanceof Element?(me(t),t.appendChild(e.html)):t.innerHTML=…`,
	 * so an Element is appended and a string is parsed. tileFor()'s docblock has
	 * that reading in full, and clusterIcon() already relies on it.
	 *
	 * The colour is validated here as well as in Settings::sanitise(), and the
	 * check is cheap rather than paranoid: it is assigned to `style.color`,
	 * where an unrecognised value is simply ignored by the browser rather than
	 * parsed as anything — so what this guards is a dot with no colour at all,
	 * not an injection.
	 *
	 * ONE THING TASK 24 INHERITS
	 * ==========================
	 * The accessible name moves. Marker._initIcon does `t.title&&(i.title=
	 * t.title)` unconditionally and `i.alt=t.alt||""` only `"IMG"===i.tagName`,
	 * so a pin carries its name in an alt and a dot carries it in a title.
	 * Both are accessible names for the role=button Leaflet's keyboard handler
	 * puts on the element; a title is the weaker of the two and is not the
	 * choice a11y guidance would make on its own. Recorded rather than fixed,
	 * because the popup, the row and the pin want one decision between them and
	 * that decision is Task 24's.
	 *
	 * @param {object} instance The locator.
	 * @returns {object|null} A DivIcon, or null to leave Leaflet's default.
	 */
	function dotIcon( instance ) {
		var marker = instance.config && instance.config.marker;
		var doc = instance.element.ownerDocument;
		var colour;
		var box;

		if ( ! marker || 'object' !== typeof marker || 'dot' !== marker.style ) {
			return null;
		}

		if ( ! window.L || 'function' !== typeof window.L.divIcon ) {
			return null;
		}

		colour = 'string' === typeof marker.colour ? marker.colour.trim() : '';
		box = doc.createElement( 'span' );
		box.className = 'slosm__dot';

		if ( '' !== colour ) {
			box.style.backgroundColor = colour;
		}

		return window.L.divIcon( {
			html: box,
			className: 'slosm__marker slosm__marker--dot',
			iconSize: [ DOT_SIZE, DOT_SIZE ],
			iconAnchor: [ DOT_SIZE / 2, DOT_SIZE / 2 ],
			popupAnchor: [ 0, -DOT_SIZE / 2 ],
		} );
	}

	/**
	 * One pin, wired to its row.
	 *
	 * @param {object} instance The locator.
	 * @param {object} entry    The entry.
	 * @returns {object} The marker.
	 */
	function pin( instance, entry ) {
		var options = {};
		var marker;
		var icon;

		// title and alt are DOM properties on Leaflet's side, never markup:
		// `t.title&&(i.title=t.title)` and, guarded by `"IMG"===i.tagName`,
		// `i.alt=t.alt||""` — both in assets/leaflet/leaflet.js's
		// Marker._initIcon. (The alt is set only when the icon really is an
		// <img>, which the default icon is; a div-based custom icon would take
		// the title and drop the alt.) A name is written as a string into a
		// property either way, which is the same guarantee textContent gives.
		//
		// Left out entirely when there is no name, rather than set to an empty
		// string. Leaflet's Marker defaults are `title:""` and `alt:"Marker"`,
		// and an alt of "" on an <img> means "decorative, skip me" — so a
		// nameless pin would vanish from a screen reader instead of being
		// announced as a marker.
		if ( 'string' === typeof entry.item.name && '' !== entry.item.name ) {
			options.title = entry.item.name;
			options.alt = entry.item.name;
		}

		// The site's marker style, when it is not the standard pin. Null for
		// the pin, which is Leaflet's own default icon and needs no option at
		// all.
		icon = dotIcon( instance );

		if ( null !== icon ) {
			options.icon = icon;
		}

		// Into whatever this locator is putting pins into: the cluster group
		// when there is one, the map itself when there is not. `addTo` is
		// `addTo:function(t){return t.addLayer(this),this}` and asks nothing of
		// its argument but addLayer, which is exactly what a MarkerClusterGroup
		// provides. A marker added to both would be drawn twice and clustered
		// never.
		marker = window.L.marker( entry.place, options ).addTo( instance.layer );

		// mouseover and mouseout are two of the five events Leaflet delivers
		// to a layer — `_mouseEvents:["click","dblclick","mouseover","mouseout",
		// "contextmenu"]` in the vendored file. Guarded because `on` belongs to
		// Leaflet's Evented and a site may have registered its own library
		// under the slosm-leaflet handle.
		//
		// These survive clustering untouched. markercluster attaches its own
		// handlers to a child marker — `_childMarkerEventHandlers` — and leaves
		// everything else on it alone, so a pin that is visible answers a
		// pointer whether or not it is in a group.
		if ( 'function' === typeof marker.on ) {
			marker.on( 'mouseover', function () {
				highlight( instance, entry );
			} );
			marker.on( 'mouseout', function () {
				unhighlight( instance, entry );
			} );

			// Every later arrival on the map, which for a clustered pin is
			// every time the zoom lets it out of its bubble. Leaflet's
			// Map._layerAdd ends `this.onAdd(i),this.fire("add"),
			// i.fire("layeradd",{layer:this})`, so the layer hears about it.
			marker.on( 'add', function () {
				bindIcon( instance, entry, marker );
			} );
		}

		// And the first arrival, which has already happened: the icon exists by
		// now if this marker went straight onto the map, because the map has a
		// view. Map.addLayer ends in `this.whenReady(t._layerAdd,t)`, which runs
		// immediately when the map is loaded and queues on 'load' when it is
		// not — so on a map that had never been given a view, _initIcon would
		// not have run and there would be nothing to bind. init() sets the view
		// before any layer is added, which is what makes that ordering
		// load-bearing rather than tidy. A marker that went into a cluster
		// group has no icon here and is bound by the 'add' above instead.
		bindIcon( instance, entry, marker );

		// After the icon, and it does not matter which way round: the popup is
		// bound to the layer and the icon binding is about the element. It is
		// last because it is the only one of the three that reads the record
		// cache, and reading it after the marker is on the map keeps the order
		// of this function "make it, wire it, fill it".
		attachPopup( instance, entry, marker );

		instance.markers.push( marker );

		return marker;
	}

	/**
	 * Fills the results list from the entries, one clone of the row template
	 * per entry.
	 *
	 * A <template> and never a string of markup. The markup was parsed once by
	 * the html parser when the page loaded, and cloning it is a native call
	 * with no parser in it at all — which is the difference between a location
	 * name being data and a location name being code.
	 *
	 * Every value goes in with textContent. A post title written by a user with
	 * unfiltered_html legitimately contains html, and none of the escaping PHP
	 * did on the way into the attribute is in the json this reads.
	 *
	 * A locator whose template is gone — a content filter, a page builder
	 * re-emitting the markup — gets no list and keeps its map. There is nothing
	 * to clone and nothing this can do about it, and a thrown error would take
	 * the map with it.
	 *
	 * @param {object} instance The locator.
	 * @param {Array}  entries  The entries to show.
	 * @returns {void}
	 */
	function renderRows( instance, entries ) {
		var doc = instance.element.ownerDocument;
		var list = instance.element.querySelector( '.slosm__results' );
		var template = instance.element.querySelector( '.slosm__row' );
		var unit = unitOf( instance.config );
		var source = template && template.content ? template.content.firstElementChild : null;

		if ( ! list ) {
			return;
		}

		empty( list );

		if ( ! source ) {
			return;
		}

		entries.forEach( function ( entry ) {
			// A clone of a node whose owner is the template's own contents
			// document, appended into this one. No importNode is needed and
			// none is missing: the DOM standard's pre-insert steps adopt a
			// node from another document on the way in, which is why every
			// browser accepts this. The harness cannot see that difference —
			// its fragment belongs to the same document — so it is recorded
			// here rather than pinned by a case.
			var row = source.cloneNode( true );
			var opener = row.querySelector( '.slosm__result-open' );
			var categories = row.querySelector( '.slosm__result-categories' );
			var goes = row.querySelector( '.slosm__result-directions' );
			var route;

			// Four fields and four questions, because a site says which of them
			// a row shows. A field that is not shown has its element taken out
			// rather than left empty; drop() has why.
			//
			// Shortcode::row_template() prints all five every time, which is
			// what keeps this decision on this side of the wire: the template is
			// in html a full-page cache may hold for hours, and a setting changed
			// after that page was cached would otherwise have no way to take
			// effect until it was regenerated.
			[
				[ 'name', '.slosm__result-name', entry.item.name ],
				[ 'address', '.slosm__result-address', entry.item.address ],
				[ 'city', '.slosm__result-city', entry.item.city ],
				[ 'distance', '.slosm__result-distance', formatDistance( entry.distance, unit ) ],
			].forEach( function ( field ) {
				if ( shows( instance.config, 'fields', field[ 0 ] ) ) {
					cell( row, field[ 1 ], field[ 2 ] );
				} else {
					drop( row.querySelector( field[ 1 ] ) );
				}
			} );

			if ( categories && ! shows( instance.config, 'fields', 'categories' ) ) {
				drop( categories );
				categories = null;
			}

			if ( categories ) {
				empty( categories );

				if ( Array.isArray( entry.item.categories ) ) {
					entry.item.categories.forEach( function ( name ) {
						var tag;

						if ( 'string' !== typeof name || '' === name ) {
							return;
						}

						tag = doc.createElement( 'li' );
						tag.className = 'slosm__result-category';
						tag.textContent = name;
						categories.appendChild( tag );
					} );
				}
			}

			entry.row = row;

			row.addEventListener( 'mouseenter', function () {
				highlight( instance, entry );
			} );
			row.addEventListener( 'mouseleave', function () {
				unhighlight( instance, entry );
			} );

			// The button is the row's focus stop, it says which pin this row
			// is, and pressing it opens that pin's popup.
			if ( opener ) {
				opener.addEventListener( 'focus', function () {
					highlight( instance, entry );
				} );
				opener.addEventListener( 'blur', function () {
					unhighlight( instance, entry );
				} );
				// Closed, and saying so. The attribute goes on here rather
				// than in Shortcode::row_template(), because a row cloned from
				// that template is markup a full-page cache may hold for
				// hours: a locator whose script never arrives should not
				// advertise a state nothing can change.
				opener.setAttribute( 'aria-expanded', 'false' );

				opener.addEventListener( 'click', function () {
					// Which button opened it, for wirePopup(). A pin clicked
					// directly leaves this null, which is how nothing claims
					// to have opened a popup nobody pressed a button for.
					instance.opener = opener;

					openEntry( instance, entry );
				} );
			}

			// Shortcode::row_template() emits this anchor with its rel and
			// target already on it and with no href at all, so a row cloned
			// before this line is a link to nowhere rather than a link to the
			// page it is on. This is the line that gives it somewhere to go.
			//
			// Rebuilt on every draw rather than once, which is what keeps the
			// "from" half honest: the origin changes when somebody presses
			// "use my location" or searches an address, and every draw follows
			// one of those.
			//
			// A null means the site turned the link off, and the anchor is taken
			// out rather than left without an href — which is what it arrives as
			// and is exactly the trap row_template() has a comment about: href=""
			// resolves to the current page, so a link left unfilled reloads the
			// page and loses the search.
			if ( goes ) {
				route = directionsUrl( instance, entry.place );

				if ( null === route ) {
					drop( goes );
				} else {
					goes.setAttribute( 'href', route );
				}
			}

			list.appendChild( row );
		} );
	}

	/**
	 * Draws one payload: the pins, the rows, and the framing when it is owed.
	 *
	 * @param {object}        instance The locator.
	 * @param {Array}         items    Items from GET /stores.
	 * @param {number[]|null} origin   The point searched from, or null.
	 * @param {boolean}       local    Whether the radius and limit still apply here.
	 * @param {boolean}       announce Whether a person asked for this draw and is owed a count.
	 * @returns {void}
	 */
	function show( instance, items, origin, local, announce ) {
		var entries;

		// Before the filter, never after it. The select offers what the payload
		// carried; taking it from the entries instead would mean choosing a
		// category deletes every other option, which is the one thing a filter
		// control must not do.
		learnCategories( instance, items );

		entries = entriesFor( instance, items, origin, local );

		clearMarkers( instance );

		instance.entries = entries;

		entries.forEach( function ( entry ) {
			entry.marker = pin( instance, entry );
		} );

		if ( 0 === entries.length ) {
			/*
			 * "No results" is a claim about the world, and a locator whose
			 * list has not arrived is not in a position to make it.
			 *
			 * Found on a live site rather than by reading. GET /stores there
			 * takes about 1.4 seconds; in preload mode every draw comes from
			 * the one payload held on the instance, so until it lands there is
			 * nothing to draw from and *any* filter draws nothing. A visitor
			 * who widened the radius or pressed Enter inside that window was
			 * told the site has no locations near them, which was false — and
			 * on a slow connection the window is longer than the patience of
			 * somebody who has just typed their postcode.
			 *
			 * Silence rather than a sentence of its own. The list is empty and
			 * the map is there, the state lasts about a second, and a "loading"
			 * message would be a fourth thing to translate for a moment nobody
			 * is reading. When the payload lands, load() draws again with
			 * whatever origin and radius the visitor has chosen meanwhile.
			 *
			 * The class is gated with it, not left on. `.slosm--empty` is a
			 * hook a site may hang its own "nothing here" on, and a hook that
			 * fires while the data is still coming reproduces this defect one
			 * stylesheet further out.
			 *
			 * Query mode is deliberately not gated: it holds no payload, so an
			 * empty draw there is a request that came back empty, which is an
			 * answer and not an absence.
			 */
			if ( 'preload' === instance.config.mode && ! instance.loaded ) {
				return;
			}

			instance.element.classList.add( 'slosm--empty' );
			say( instance.element, text( 'noResults' ) );

			return;
		}

		instance.element.classList.remove( 'slosm--empty' );

		renderRows( instance, entries );

		/*
		 * What the status line says about this draw, which is either how many
		 * it found or nothing at all.
		 *
		 * Nothing is not silence being lazy: the sentence that was up has to
		 * come down either way, because this draw is the answer to it.
		 * "Searching…" is the obvious one; "No results" from an earlier draw
		 * is the one that matters, since it would otherwise sit above a list
		 * that now has rows in it and contradict them. While the sentences
		 * were list items renderRows() took them down as a side effect of
		 * emptying the list; Task 24c moved them into a status line of their
		 * own, so nothing removes them by accident any more.
		 *
		 * `announce` is "a person asked for this draw". A search, a press of
		 * "use my location", a radius or result-count change: yes. The payload
		 * arriving on page load: no — a live region that speaks while a page is
		 * still being read talks over whatever somebody was reading, and
		 * nothing has happened that they do not already know about. The two
		 * exceptions are a shortcode carrying search="…" or auto_locate, where
		 * the page starts a lookup by itself; those announce, and they are also
		 * the two paths that already say "Searching…" out loud, so a locator
		 * that says it is looking finishes the sentence.
		 *
		 * keep, or this would empty the rows it was called to announce.
		 */
		say(
			instance.element,
			announce ? text( 'resultsFound' ).replace( '%s', String( entries.length ) ) : '',
			true
		);

		// A draw frames what it drew.
		//
		// This used to be "only when the shortcode named no centre", on the
		// reasoning that an explicit lat/lng is an instruction markers do not
		// get to overrule. The reasoning was sound and the rule was wrong,
		// because the commonest centre by far is not a per-locator instruction
		// at all — it is Settings' default_lat/default_lng, one pair for the
		// whole site, set once by somebody who has no idea which locations a
		// given page will list. Under the old rule that pair silently turned
		// framing off forever, and the symptom is a map that never shows a
		// pin. See tests/js/locator.test.js, 'a named centre is where the map
		// opens, not where it stays', for the measurements off a real page.
		//
		// The centre still opens the map — initLocator() sets that view before
		// anything is fetched, and it is what a visitor looks at while the
		// list is in flight. It just does not outlive the data.
		//
		// The emptiness check above is not merely tidy: Leaflet's fitBounds
		// throws Error( 'Bounds are not valid.' ) on bounds with nothing in
		// them — assets/leaflet/leaflet.js, the `fitBounds:` property of the
		// Map prototype — so this branch has to be unreachable with an empty
		// array rather than merely unlikely.
		// The origin goes into the frame with them when there is one. A frame
		// drawn around the results alone would walk the map off the address
		// somebody typed whenever the nearest location is some way from it,
		// and the address is the half of the picture they can name.
		instance.map.fitBounds(
			( null === origin ? [] : [ origin ] ).concat(
				entries.map( function ( entry ) {
					return entry.place;
				} )
			),
			{
				// A frame around a single point has no size, and Leaflet
				// answers a zero-sized bounds with the closest zoom it is
				// allowed to use — the tile server's 19, which is a doorstep.
				// The shortcode's zoom is already the site's answer to how
				// close this map should be, so it is the floor on closeness
				// here. maxZoom bounds zooming *in* only; a frame around
				// locations spread across a country still pulls back as far
				// as that takes.
				maxZoom: zoomLevel( instance.config.zoom ),
				// And room for the icons, because fitBounds fits coordinates
				// and an icon is not its coordinate. Asymmetric, because the
				// icons are: Leaflet's default pin is 25x41 standing *above*
				// its point with nothing below it, while a dot (DOT_SIZE,
				// centred) and a cluster bubble (CLUSTER_SIZE, centred) reach
				// half their size in every direction. So the top clears the
				// tallest thing above an anchor and the rest clears the widest
				// thing around one.
				//
				// Constants rather than a reading of config.marker.style: the
				// worst case is 48 pixels on one edge of a map that is at
				// least 200 tall, three branches to save half of it would be
				// three branches to maintain, and a frame slightly roomier
				// than it had to be is not a defect.
				paddingTopLeft: PAD_TOP_LEFT,
				paddingBottomRight: PAD_BOTTOM_RIGHT,
			}
		);
	}

	/**
	 * Which of the vendored stylesheet's three bubble colours a count gets.
	 *
	 * @param {number} count How many pins are in the cluster.
	 * @returns {string} 'small', 'medium' or 'large'.
	 */
	function clusterSize( count ) {
		if ( count < CLUSTER_SMALL ) {
			return 'small';
		}

		if ( count < CLUSTER_MEDIUM ) {
			return 'medium';
		}

		return 'large';
	}

	/**
	 * The icon on one cluster bubble.
	 *
	 * This is the function assets/markercluster/README.md warns about, and the
	 * warning is worth restating where the code is. `L.DivIcon` sets its content
	 * with innerHTML — `e.html instanceof Element?(me(t),t.appendChild(e.html)):
	 * t.innerHTML=!1!==e.html?e.html:""` in the vendored leaflet.js — and the
	 * cluster icon is a DivIcon. So anything derived from a location that
	 * reaches here is parsed as markup, inside Leaflet, where the
	 * forbidden-property scan in tests/js/locator.test.js cannot see it: that
	 * scan reads *this* file, and the innerHTML in question is somebody else's.
	 *
	 * Two things keep that shut, and they are belt and braces on purpose.
	 *
	 * The label is a number this file computed. getChildCount() is
	 * markercluster's own tally and nothing from the payload is consulted, but
	 * it is still put through finiteNumber() rather than trusted — a count of
	 * undefined would otherwise become the string "undefined" going through a
	 * parser, which is a small thing that says the rule was not being followed.
	 *
	 * And `html` is an Element, not a string. That takes the other branch of the
	 * line above entirely: the node is appended, and no parser runs at all. It
	 * is the difference between a rule about what is put in and a rule about
	 * what can be put in.
	 *
	 * @param {object} instance The locator.
	 * @param {object} cluster  The cluster Leaflet.markercluster is drawing.
	 * @returns {object} A DivIcon.
	 */
	function clusterIcon( instance, cluster ) {
		var doc = instance.element.ownerDocument;
		var counted = cluster && 'function' === typeof cluster.getChildCount ? finiteNumber( cluster.getChildCount() ) : null;
		var count = null === counted ? 0 : Math.max( 0, Math.round( counted ) );
		var box = doc.createElement( 'div' );
		var label = doc.createElement( 'span' );

		// The shape MarkerCluster.Default.css styles: a div inside the icon and
		// a span inside that. Reproduced rather than invented, because the
		// stylesheet is the vendored one and this plugin ships no cluster CSS of
		// its own.
		label.textContent = String( count );
		box.appendChild( label );

		return window.L.divIcon( {
			html: box,
			className: 'marker-cluster marker-cluster-' + clusterSize( count ),
			iconSize: [ CLUSTER_SIZE, CLUSTER_SIZE ],
		} );
	}

	/**
	 * This locator's cluster group, or null when it is not clustering.
	 *
	 * Null covers two different situations and only one of them is a decision.
	 * A config that says false is a site below Shortcode::CLUSTER_THRESHOLD, or
	 * one whose shortcode said cluster="no", and there is nothing to report. A
	 * config that says true on a page with no Leaflet.markercluster on it is a
	 * conditional load that did not arrive — a dequeued handle, an optimiser, a
	 * failed request — and that is worth a line in the console and nothing on
	 * the page: every location is still drawn, still searchable and still in the
	 * list, which is a worse map than a clustered one and an immeasurably better
	 * one than a TypeError halfway through init.
	 *
	 * `true !==` rather than a truthiness test, for the reason finiteNumber()
	 * refuses a string: Shortcode::config() emits a real boolean, so anything
	 * else arrived from a hand-written data-slosm and guessing at it is how a
	 * config comes to mean two things.
	 *
	 * The library is on the page before this runs, and on the two versions this
	 * plugin supports it gets there by two different routes. Assets registers
	 * the cluster handle blocking and enqueues it from render(), after the
	 * locator handle — so on 6.3 and up the locator tag carries defer and waits
	 * for the parser, and on 6.0 to 6.2 there is no defer at all and the
	 * bottom of this file takes its `'loading' === document.readyState` branch
	 * instead. Either way the blocking cluster tag below has executed first.
	 * Assets::register() has the whole argument, and the readyState branch is
	 * not redundant with the defer.
	 *
	 * @param {object} instance The locator.
	 * @returns {object|null} The group, already on the map, or null.
	 */
	function clusterFor( instance ) {
		if ( true !== instance.config.cluster ) {
			return null;
		}

		if ( 'function' !== typeof window.L.markerClusterGroup ) {
			warn( new Error( 'clustering is on for this locator but leaflet.markercluster is not on the page' ) );

			return null;
		}

		// One option, and every other default left alone. maxClusterRadius,
		// spiderfyOnMaxZoom, showCoverageOnHover and the rest are the library's
		// to choose; this plugin has measured none of them and a value invented
		// here would be a claim it cannot support.
		return window.L
			.markerClusterGroup( {
				iconCreateFunction: function ( cluster ) {
					return clusterIcon( instance, cluster );
				},
			} )
			.addTo( instance.map );
	}

	/**
	 * Asks the browser where the visitor is, and answers whatever comes back.
	 *
	 * Four outcomes, four sentences, and one of them is an error. The file
	 * header has the argument; what it comes down to in code is that nothing
	 * here calls fail() except the branch where something really did fail, and
	 * that every say() is a keep — so the branches somebody is still reading are
	 * still on screen underneath whatever this has to tell them.
	 *
	 * The status goes up before the request rather than after it, because on a
	 * browser that has to warm up a radio the gap is seconds long and a button
	 * that does nothing visible for three seconds is a button people press
	 * again.
	 *
	 * The token is minted here, and it is the same run counter a typed search
	 * uses. Somebody who presses this button, waits, gives up and types an
	 * address has asked two questions, and the answer to the first must not land
	 * on the answer to the second — which for a permission prompt is a window
	 * measured in however long it takes to read a dialog.
	 *
	 * Nothing happens after the getCurrentPosition call, deliberately: the
	 * harness's stub answers synchronously where a browser answers later, so a
	 * line below it would run before the callback in a browser and after it in
	 * the suite.
	 *
	 * @param {object} instance The locator.
	 * @returns {void}
	 */
	function locateMe( instance ) {
		var geolocation = window.navigator && window.navigator.geolocation;
		var token;

		if ( ! geolocation || 'function' !== typeof geolocation.getCurrentPosition ) {
			// Not an error and not a failure: this browser cannot do it, which
			// is nobody's fault and nothing to fix. The locator does not go red
			// over it.
			say( instance.element, text( 'locationUnsupported' ), true );

			return;
		}

		token = beginSearch( instance );

		say( instance.element, text( 'locating' ), true );

		geolocation.getCurrentPosition(
			function ( found ) {
				onLocated( instance, token, found );
			},
			function ( error ) {
				onLocateFailed( instance, token, error );
			},
			{
				// enableHighAccuracy is deliberately left at its default of
				// false. "Which of these branches is nearest" does not need a
				// GPS fix — a network-derived position is accurate to a few
				// hundred metres, which is finer than the difference this
				// question turns on — and asking for high accuracy costs a
				// visitor battery and several seconds for nothing.
				timeout: GEO_TIMEOUT,
				maximumAge: GEO_MAX_AGE,
			}
		);
	}

	/**
	 * A position came back: from here on it is an ordinary search.
	 *
	 * locate() and nothing else, which is the whole point. Recentring, sorting,
	 * the two modes, the staleness check after the fetch and the message while
	 * it is in flight are all written once, and "near me" is one more way of
	 * arriving at them rather than a second implementation of them.
	 *
	 * @param {object} instance The locator.
	 * @param {number} token    The run this belongs to.
	 * @param {object} found    A GeolocationPosition.
	 * @returns {void}
	 */
	function onLocated( instance, token, found ) {
		var coords = found && 'object' === typeof found ? found.coords : null;
		var place = coords && 'object' === typeof coords ? point( coords.latitude, coords.longitude ) : null;

		if ( stale( instance, token ) ) {
			return;
		}

		if ( null !== place ) {
			// Rounded after the range check rather than before it, so that a
			// coordinate the browser reports just outside the world is refused
			// rather than rounded back into it. COORD_PLACES has why this
			// happens at all, and why it happens here and not in url().
			place = [
				Math.round( place[ 0 ] * COORD_PLACES ) / COORD_PLACES,
				Math.round( place[ 1 ] * COORD_PLACES ) / COORD_PLACES,
			];
		}

		if ( null === place ) {
			// A browser that answered success with coordinates that are not a
			// place. Leaflet takes NaN without complaint and draws a blank
			// rectangle, which is the one outcome this file exists to prevent.
			fail( instance.element, 'locationFailed' );

			return;
		}

		instance.pendingSearch = locate( instance, place, token );
	}

	/**
	 * The browser said no, or could not say.
	 *
	 * The one function in this file where the difference between two failures
	 * is the whole feature. A denial is the ordinary answer and gets a plain
	 * sentence; everything else is a failure and gets the error treatment. Both
	 * keep whatever is already in the results list, because neither has anything
	 * to say about the locations that are in it.
	 *
	 * @param {object} instance The locator.
	 * @param {number} token    The run this belongs to.
	 * @param {object} error    A GeolocationPositionError.
	 * @returns {void}
	 */
	function onLocateFailed( instance, token, error ) {
		var code = error && 'object' === typeof error ? finiteNumber( error.code ) : null;

		if ( stale( instance, token ) ) {
			// Somebody stopped waiting and searched for an address instead. A
			// sentence about the prompt they walked away from would land on top
			// of results that have nothing to do with it.
			return;
		}

		if ( PERMISSION_DENIED === code ) {
			say( instance.element, text( 'locationDenied' ), true );

			return;
		}

		// POSITION_UNAVAILABLE and TIMEOUT, and anything a browser invents. All
		// of them mean the same thing to whoever is looking at the page — it did
		// not work and it was not your doing — and all of them are worth the
		// error state, because unlike a denial they may work on a second press.
		fail( instance.element, 'locationFailed' );
	}

	/**
	 * Wires the "use my location" button, when the shortcode rendered one.
	 *
	 * near_me="no" is an ordinary configuration rather than broken markup, so
	 * there being no button is not worth a word anywhere.
	 *
	 * @param {object} instance The locator.
	 * @returns {void}
	 */
	function wireLocate( instance ) {
		var control = instance.element.querySelector( '.slosm__locate' );

		if ( ! control ) {
			return;
		}

		control.addEventListener( 'click', function () {
			locateMe( instance );
		} );
	}

	/**
	 * Asks a /stores url for a list and hands it back, or null on any failure.
	 *
	 * Every failure lands in one place and says the same thing, because from
	 * the visitor's side they are one thing: the locations did not arrive.
	 * Distinguishing a 500 from a security plugin's html error page from a
	 * dropped connection would be four messages nobody can act on.
	 *
	 * The response is checked for being an array before anything reads it. A
	 * WP_Error comes back as a json *object* with a 4xx, and an earlier
	 * version of this comment claimed forEach on one would be a silent no-op
	 * leaving an empty map claiming "no results". That is wrong: `({}).forEach`
	 * is a TypeError, so the catch below would have turned it into the same
	 * message anyway. The check earns its place for a smaller and truer
	 * reason — it names what went wrong in the console line, where
	 * "GET /stores did not answer with a list" is a diagnosis and
	 * "items.forEach is not a function" is a stack trace — and for the shape
	 * that would not throw: anything array-like enough to iterate and not be a
	 * list of locations. A case asserts the console text, so the check cannot
	 * be deleted quietly.
	 *
	 * A cancelled request is not a failure and must not say one. Nothing
	 * aborted a /stores request until the search did, and the moment Task 15
	 * gives "near me" or a filter control its own AbortController, an ordinary
	 * cancellation would otherwise render "The locations could not be loaded."
	 * over a perfectly good map. Whatever cancels a request has to be the thing
	 * that owns the signal, and it has to pass a token so a late answer can be
	 * recognised as stale; see beginSearch().
	 *
	 * @param {object}      instance The locator.
	 * @param {string}      target   The url.
	 * @param {number|null} token    The search run this belongs to, or null for a request nothing cancels.
	 * @returns {Promise} Resolves with the items, or with null once the message is up or the request was dropped.
	 */
	function fetchList( instance, target, token ) {
		var init = { credentials: 'same-origin' };

		if ( null !== token && instance.searchController ) {
			init.signal = instance.searchController.signal;
		}

		return window
			.fetch( target, init )
			.then( function ( response ) {
				if ( ! response.ok ) {
					throw new Error( 'GET /stores answered ' + response.status );
				}

				return response.json();
			} )
			.then( function ( items ) {
				if ( ! Array.isArray( items ) ) {
					throw new Error( 'GET /stores did not answer with a list' );
				}

				return items;
			} )
			.catch( function ( error ) {
				// Dropped rather than failed: this locator has moved on to a
				// later search, or this request's own signal was aborted by
				// one. Neither is something to tell a visitor about, and the
				// answer that replaced it is already on its way.
				if ( 'AbortError' === error.name || stale( instance, token ) ) {
					return null;
				}

				fail( instance.element, 'loadFailed' );
				warn( error );

				return null;
			} );
	}

	/**
	 * Whether a search has been overtaken by a later one.
	 *
	 * A token of null is a request nothing cancels — the one preload fetch —
	 * and is never stale.
	 *
	 * @param {object}      instance The locator.
	 * @param {number|null} token    The run this work belongs to.
	 * @returns {boolean} True when the answer should be dropped.
	 */
	function stale( instance, token ) {
		// Anything that is not a run number means "nothing cancels this", not
		// "this belongs to run undefined". The distinction is not tidiness: a
		// caller that forgot the argument would otherwise have every one of
		// its answers silently dropped — no rows, no message, nothing in the
		// console — because undefined matches no run that will ever exist. The
		// preload fetch did exactly that for the length of one test run.
		//
		// Both call sites pass explicitly today, so a mutation narrowing this
		// to `null === token` survives the suite, and it is equivalent for the
		// code as written rather than merely uncaught: the input that would
		// separate them is a call with the argument left off, and there is no
		// such call. It is kept because the failure it prevents is silent, and
		// the next person to add a caller is the one it is written for.
		if ( 'number' !== typeof token ) {
			return false;
		}

		return token !== instance.searchRun;
	}

	/**
	 * Starts a search run, and abandons whatever the last one was doing.
	 *
	 * The suggestion path was guarded twice from the beginning and this one was
	 * not guarded at all, which is the same bug one path over: somebody
	 * searches Warszawa, then Kraków, and the abandoned Warszawa answer lands
	 * last and takes the map with it. Query mode makes the window wider rather
	 * than narrower, because a search there is two requests — /geocode and
	 * then /stores — and either of them can be the slow one.
	 *
	 * The abort is the first line of it and the token is the second, for the
	 * same reason as in askSuggestions(): a browser without an AbortController
	 * still has to get this right, and an abort called after a response has
	 * been handed over does not un-deliver it.
	 *
	 * @param {object} instance The locator.
	 * @returns {number} The token for this run.
	 */
	function beginSearch( instance ) {
		if ( instance.searchController ) {
			instance.searchController.abort();
			instance.searchController = null;
		}

		if ( 'function' === typeof window.AbortController ) {
			instance.searchController = new window.AbortController();
		}

		instance.searchRun++;

		return instance.searchRun;
	}

	/**
	 * Fetches the whole list once and draws it. Preload mode only.
	 *
	 * The payload is kept, because in preload mode it is the only copy: every
	 * later search sorts this array rather than asking the server again.
	 *
	 * @param {object} instance The locator.
	 * @returns {Promise} Settles when the map is drawn or the message is up.
	 */
	function load( instance ) {
		// No limit on this request, and that is the whole of preload working.
		//
		// `limit` is a display limit — the control that sets it says "Show at
		// most" — and sending it here made it a *fetch* limit, which is a
		// different and wrong thing: Store_Repository::find_all() orders by
		// title ASC, so `limit="10"` on a 120-location site preloaded the ten
		// alphabetically first locations and every search then ranked only
		// those. The shop two streets away never appeared because its name
		// began with M. The same shortcode on a site above the threshold asked
		// /stores for the nearest ten out of all 120 and answered correctly —
		// the same site giving two different answers either side of a number
		// no visitor can see, which is the failure the local radius filter
		// exists to prevent, arriving through the fetch instead.
		//
		// Leaving it off means the route's own default, which is
		// Rest_Controller::MAX_LIMIT. Shortcode::PRELOAD_THRESHOLD *is*
		// MAX_LIMIT — its docblock says a threshold above the cap would be a
		// promise the route cannot keep — so a locator in preload mode is by
		// construction a locator whose whole list fits in one request. The
		// limit is then applied where it belongs, to the rows rendered.
		var target = url( instance.config.routes.stores, {
			category: instance.category,
		} );

		// null: nothing cancels this one. It is the page loading, not a search.
		return fetchList( instance, target, null ).then( function ( items ) {
			if ( null === items ) {
				return;
			}

			instance.items = items;
			instance.loaded = true;

			// Somebody got ahead of this request. "Use my location" on a slow
			// /stores is the way it happens: the position arrives first, the map
			// is already centred on the visitor and the list is already sorted
			// from there — and drawing this payload with no origin would reframe
			// the map around every location and throw the sort away. The point
			// they asked to look from is the instruction; this is only the data
			// catching up with it.
			//
			// local in both branches: nothing on the server applied this
			// locator's limit or category to this payload, because nothing asked
			// it to. See above.
			// The page drawing itself, so nothing is announced: a live region
			// that speaks on load talks over whatever somebody was reading.
			show( instance, items, instance.origin, true, false );
		} );
	}

	/**
	 * Puts the map and the list around one point.
	 *
	 * The two modes differ in where the list comes from and in nothing else.
	 * Preload has it already — that is what the mode means — so asking the
	 * server would be a round trip for an answer in memory. Query mode asks
	 * /stores for the neighbourhood, which is the request preload exists to
	 * avoid making on every page load.
	 *
	 * The map is recentred before either, so a slow list does not leave the map
	 * pointing at where the visitor used to be looking.
	 *
	 * setView here, fitBounds later, and the two are not rivals. This one
	 * happens before the list exists: it is what somebody looks at while the
	 * request is in flight, and there is nothing to frame yet. The draw that
	 * follows frames the address together with what was found near it, which
	 * is renderResults()' job. Until Task 32 there was no second half, and the
	 * zoom this line sets is the shortcode's — a number that knows nothing
	 * about the radius the search ran at. A 50km radius under a zoom-12 map is
	 * a list of results almost none of which are on screen.
	 *
	 * @param {object}   instance The locator.
	 * @param {number[]} place    Where to look from.
	 * @param {number}   token    The search run this belongs to.
	 * @returns {Promise} Settles when the list is up.
	 */
	function locate( instance, place, token ) {
		// No staleness check here, deliberately, and it is worth saying why
		// rather than leaving the next reader to wonder. There were two, and a
		// mutation sweep could not kill this one: every caller either passes a
		// token minted a moment earlier on the same synchronous path
		// (takeSuggestion) or has already dropped out twice on the same
		// question before getting here (submitSearch checks the response and
		// the body). Nothing can call this with a token that is already stale,
		// so the check could be deleted with every case still green — which is
		// the definition of a guard nobody can maintain. The check that earns
		// its place is the one after the fetch below, where time really has
		// passed.
		instance.origin = place;
		instance.map.setView( place, zoomLevel( instance.config.zoom ) );

		if ( 'preload' === instance.config.mode ) {
			show( instance, instance.items, place, true, true );

			return Promise.resolve();
		}

		say( instance.element, text( 'searching' ) );

		return fetchList(
			instance,
			url( instance.config.routes.stores, {
				lat: place[ 0 ],
				lng: place[ 1 ],
				// All three from the controls rather than from the config, and
				// for one reason: what the visitor is looking at is what the
				// search is for. The radius and the limit are read off their
				// selects here and in entriesFor(), so the two modes ask the
				// same question of the same numbers; the category comes off the
				// instance because that is where its control puts it, and when
				// the shortcode pinned one there is no control to use —
				// wireCategory() disables it.
				radius: radiusNow( instance ),
				limit: limitNow( instance ),
				unit: unitOf( instance.config ),
				category: instance.category,
			} ),
			token
		).then( function ( items ) {
			if ( null !== items && ! stale( instance, token ) ) {
				show( instance, items, place, false, true );
			}
		} );
	}

	/**
	 * What a failed address lookup tells the person who typed it.
	 *
	 * Rest_Controller::ERROR_STATUS maps seven geocoder failures onto four
	 * statuses, and the difference is the only part a visitor can act on: fix
	 * what you typed, wait a moment, or it is not your fault. A single "search
	 * failed" would leave somebody retyping a postcode that was never the
	 * problem. 400 joins 404 because the only way to reach it from here is a
	 * query that normalised to nothing, which is the same thing said about the
	 * text in the field.
	 *
	 * @param {number|undefined} status The status, when the failure had one.
	 * @returns {string} A key from FALLBACK_STRINGS.
	 */
	function searchFailure( status ) {
		if ( 400 === status || 404 === status ) {
			return 'searchNoMatch';
		}

		if ( 429 === status ) {
			return 'searchBusy';
		}

		return 'searchFailed';
	}

	/**
	 * Closes the suggestion popup and stops anything it had in flight.
	 *
	 * Three halves, which is one more than it sounds.
	 *
	 * The timer, because a keystroke that has not become a request yet would
	 * otherwise reopen the popup a moment after it was closed — and would send
	 * the request too, which is one upstream lookup leaked per Escape, blur,
	 * Tab or Enter that lands inside a debounce window. That is a hole in the
	 * wall this whole path is built around.
	 *
	 * The controller, because the same is true of a request already on its way.
	 *
	 * And the sequence number, which is the part that was missing. Closing is
	 * as much a reason to disown an answer as typing is: without the bump, the
	 * token still matches when the in-flight answer lands, so on a browser with
	 * no AbortController — the fallback this file explicitly supports — Escape
	 * would close the popup and the answer would reopen it a moment later.
	 *
	 * @param {object} instance The locator.
	 * @returns {void}
	 */
	/**
	 * Disowns the suggestion request in flight, whatever happens to it next.
	 *
	 * The abort stops it where the browser can; the sequence number handles
	 * the two cases the abort cannot — a browser without an AbortController,
	 * and an answer already handed over when abort() was called.
	 *
	 * @param {object} instance The locator.
	 * @returns {void}
	 */
	function dropSuggest( instance ) {
		var search = instance.search;

		if ( search.controller ) {
			search.controller.abort();
			search.controller = null;
		}

		search.sequence++;
	}

	function closeSuggestions( instance ) {
		var search = instance.search;

		if ( ! search ) {
			return;
		}

		if ( null !== search.timer ) {
			window.clearTimeout( search.timer );
			search.timer = null;
		}

		dropSuggest( instance );

		empty( search.listbox );
		search.listbox.setAttribute( 'hidden', 'hidden' );
		search.options = [];
		search.places = [];
		search.active = -1;
		search.input.setAttribute( 'aria-expanded', 'false' );
		search.input.removeAttribute( 'aria-activedescendant' );
	}

	/**
	 * Marks one option as the active one, and the others as not.
	 *
	 * aria-activedescendant is what tells a screen reader which option is
	 * current while the focus stays in the text field — which is the whole
	 * shape of a combobox: the focus never moves into the list.
	 *
	 * @param {object} instance The locator.
	 * @param {number} index    Which option, or -1 for none.
	 * @returns {void}
	 */
	function setActive( instance, index ) {
		var search = instance.search;

		search.options.forEach( function ( option, at ) {
			var current = at === index;

			option.setAttribute( 'aria-selected', current ? 'true' : 'false' );

			if ( current ) {
				option.classList.add( 'slosm__suggestion--active' );
			} else {
				option.classList.remove( 'slosm__suggestion--active' );
			}
		} );

		search.active = index;

		if ( -1 === index ) {
			search.input.removeAttribute( 'aria-activedescendant' );

			return;
		}

		search.input.setAttribute( 'aria-activedescendant', search.options[ index ].getAttribute( 'id' ) );
	}

	/**
	 * Moves the active option by one, wrapping at both ends.
	 *
	 * Wrapping rather than stopping, and from nothing the two directions land
	 * at opposite ends: ArrowDown on a closed-over list means "the first one"
	 * and ArrowUp means "the last one", which is what every list somebody has
	 * used before does.
	 *
	 * @param {object} instance The locator.
	 * @param {number} step     1 or -1.
	 * @returns {void}
	 */
	function moveActive( instance, step ) {
		var search = instance.search;
		var count = search.options.length;

		if ( 0 === count ) {
			return;
		}

		if ( -1 === search.active ) {
			setActive( instance, 0 < step ? 0 : count - 1 );

			return;
		}

		setActive( instance, ( search.active + step + count ) % count );
	}

	/**
	 * Puts a list of suggestions in the popup.
	 *
	 * Every label goes in with textContent, and every suggestion without
	 * usable coordinates is dropped rather than shown: an option that cannot
	 * move the map is an option that does nothing when it is chosen.
	 *
	 * TWO ROWS THAT READ THE SAME ARE ONE ROW
	 * ---------------------------------------
	 * Task 26's first real install typed `Kraków` and got five rows, three of
	 * them the identical string "Kraków, województwo małopolskie, Polska".
	 * Three rows a person cannot tell apart are not three choices; they are one
	 * choice taking three fifths of the list, and the genuinely different
	 * answers below them — Karkowo, Krąków — are pushed off the end of it. So
	 * the first row carrying a given label is kept and any later row carrying
	 * the same label is dropped.
	 *
	 * **This is a decision about text on a screen and nothing else.** It is
	 * emphatically *not* a claim that the records behind two identical labels
	 * are the same place, and no coordinate is compared here — anyone reading
	 * `shown` as a de-duplication of places will be wrong in both directions.
	 * The upstream records really are different: Photon's index holds a city, an
	 * administrative area and a relation for one Polish city, three distinct
	 * features with three distinct geometries, and `suggestion_label()` renders
	 * all three from the same name/city/state/country parts. Two of them are
	 * discarded unexamined, which is right for a dropdown — a person offered two
	 * indistinguishable rows has no way to choose the "better" one anyway — and
	 * would be quite wrong for anything that cared which record it had.
	 *
	 * Equality is the exact string, because the exact string is what is on the
	 * screen. Two labels differing by a capital or a space are two different
	 * lines to the person reading them, and folding those would be this function
	 * deciding they meant the same thing, which is the claim it is refusing to
	 * make.
	 *
	 * Deliberately not only *consecutive* duplicates, which is what the Task 28
	 * plan entry says. Nothing documents an ordering guarantee from Photon, and
	 * a repeat that arrives with one different row between two identical ones is
	 * the same defect to the person reading the list. Keeping the first
	 * occurrence preserves the order of everything kept either way.
	 *
	 * The fold comes after the coordinate check, so "the first" means the first
	 * row that would have been *shown*, not the first the service sent: a
	 * duplicate label whose twin had no usable point is the one that gets to be
	 * on screen.
	 *
	 * It can leave fewer rows than the server sent, and that is the point of it
	 * — `Geocoder::SUGGEST_LIMIT` asks for five and this can render one. Nothing
	 * backfills, because there is nothing to backfill from: the five are the
	 * whole answer, and asking for more is a second upstream request for a list
	 * the visitor is about to stop reading. It cannot leave *none*: folding
	 * keeps the first of every distinct label, so a list with anything showable
	 * in it still shows something. The empty popup below is reached only by a
	 * list that was empty or unusable before folding ever looked at it.
	 *
	 * @param {object} instance The locator.
	 * @param {Array}  list     What GET /suggest answered.
	 * @returns {void}
	 */
	function showSuggestions( instance, list ) {
		var search = instance.search;
		var doc = instance.element.ownerDocument;

		// The labels already on screen, in the order they went up. An array
		// rather than a map because this list is five rows long by
		// construction, and because a label is a visitor's arbitrary text: an
		// object used as a lookup answers for 'constructor' and '__proto__'
		// whether or not anything put them there.
		var shown = [];

		empty( search.listbox );
		search.options = [];
		search.places = [];
		search.active = -1;
		search.input.removeAttribute( 'aria-activedescendant' );

		list.forEach( function ( raw ) {
			var place;
			var option;
			var chosen;

			if ( ! raw || 'object' !== typeof raw || 'string' !== typeof raw.label || '' === raw.label ) {
				return;
			}

			place = point( raw.lat, raw.lng );

			if ( null === place ) {
				return;
			}

			// The fold. See the docblock: same string, same row, and no claim
			// about the places behind them.
			if ( -1 !== shown.indexOf( raw.label ) ) {
				return;
			}

			shown.push( raw.label );

			chosen = { label: raw.label, place: place };

			option = doc.createElement( 'li' );
			option.className = 'slosm__suggestion';
			option.setAttribute( 'role', 'option' );
			option.setAttribute( 'aria-selected', 'false' );
			option.setAttribute( 'id', 'slosm-' + instance.serial + '-option-' + search.options.length );
			option.textContent = raw.label;

			// A mousedown that runs its default action blurs the field, and a
			// blurred field closes the popup — so the element this click was
			// aimed at is gone before the click lands on it. Every combobox
			// that takes a pointer has to do this.
			option.addEventListener( 'mousedown', function ( event ) {
				event.preventDefault();
			} );

			option.addEventListener( 'click', function () {
				takeSuggestion( instance, chosen );
			} );

			search.listbox.appendChild( option );
			search.options.push( option );
			search.places.push( chosen );
		} );

		if ( 0 === search.options.length ) {
			closeSuggestions( instance );

			return;
		}

		search.listbox.removeAttribute( 'hidden' );
		search.input.setAttribute( 'aria-expanded', 'true' );
	}

	/**
	 * Asks /suggest for one query, cancelling whatever was already in flight.
	 *
	 * Two guards, and they are not the same guard twice.
	 *
	 * The AbortController stops the earlier request, which is what keeps a
	 * slow answer for "war" from landing on top of the list for "warsaw". It
	 * is checked for rather than assumed: every browser WordPress 6.0 supports
	 * has had it since 2017, but a site whose polyfill kit or service worker
	 * leaves the global missing should get a working field rather than a
	 * TypeError.
	 *
	 * The sequence number is what holds when the abort does not — because
	 * there is no AbortController, or because the response had already been
	 * handed over when abort() was called. It costs a comparison and it is the
	 * only thing standing between a stale answer and the list.
	 *
	 * It is checked in two places and not three. An earlier version also
	 * checked before reading the body, and a mutation sweep showed the check
	 * could be deleted with every case still passing — which was true and not a
	 * gap in the cases: a stale response that gets as far as being parsed is
	 * stopped one step later, and a stale one that throws is stopped in the
	 * catch. All the third check bought was skipping a parse whose result is
	 * discarded either way, and a guard that cannot be observed is a guard
	 * nobody can maintain.
	 *
	 * The 204 is read off the status before anything touches the body: core
	 * returns before wp_json_encode() for a 204, so there is no body to parse
	 * and .json() on one rejects.
	 *
	 * What a 204 then does is close the popup, and that is not the same as
	 * treating it as "no matches" — nothing says "no matches" anywhere, and
	 * the memo below is deliberately not written, so the very next keystroke
	 * asks again. It is the "ask again" half of the contract taken seriously.
	 * Leaving the options up instead was worse than it looked: the field would
	 * read `warszawa` while the popup offered places found for `wars`, and
	 * Enter on one of them moves the map to somewhere that does not match what
	 * was typed. Under sustained throttling — the contended case
	 * Geocoder::would_throttle() exists for — that is the normal state rather
	 * than an edge case. Nothing is lost by closing: the list was an answer to
	 * a different question.
	 *
	 * @param {object} instance The locator.
	 * @param {string} query    What is in the field.
	 * @returns {void}
	 */
	function askSuggestions( instance, query ) {
		var search = instance.search;
		var target = url( instance.config.routes.suggest, { q: query } );
		var init = { credentials: 'same-origin' };
		var token;

		if ( null === target ) {
			return;
		}

		// One entry, and what it catches is worth stating exactly, because it
		// is less than it sounds: a query repeated straight away, and a typo
		// corrected inside a debounce window — `warx` typed and cut back to
		// `war` before the timer fires, where the intermediate text never
		// became a request at all. Going back to something answered two
		// requests ago is a miss and asks again.
		//
		// It is deliberately not a cache keyed by every query somebody typed.
		// That would hold a growing table of a visitor's keystrokes to save a
		// request the *server* already answers cheaply — Rest_Controller asks
		// suggestions_are_cached() before it throttles, so a repeat the site
		// has seen is a transient read rather than an upstream call. The one
		// entry pays for the common correction and nothing more.
		//
		// A 204 never fills it, so a query the service declined to answer is
		// asked again rather than answered from nothing.
		if ( search.memo && search.memo.query === query ) {
			dropSuggest( instance );
			showSuggestions( instance, search.memo.list );

			return;
		}

		if ( search.controller ) {
			search.controller.abort();
			search.controller = null;
		}

		if ( 'function' === typeof window.AbortController ) {
			search.controller = new window.AbortController();
			init.signal = search.controller.signal;
		}

		search.sequence++;
		token = search.sequence;

		instance.pendingSuggest = window
			.fetch( target, init )
			.then( function ( response ) {
				if ( 204 === response.status ) {
					return null;
				}

				if ( ! response.ok ) {
					throw new Error( 'GET /suggest answered ' + response.status );
				}

				return response.json();
			} )
			.then( function ( list ) {
				if ( token !== search.sequence ) {
					return;
				}

				if ( null === list ) {
					// The 204. Closed, and nothing remembered, so the same
					// query typed again is asked again rather than answered
					// from a memo that was never filled.
					closeSuggestions( instance );

					return;
				}

				if ( ! Array.isArray( list ) ) {
					throw new Error( 'GET /suggest did not answer with a list' );
				}

				// Remembered before it is shown, and only for an answer the
				// service actually gave. An empty list is an answer.
				search.memo = { query: query, list: list };

				showSuggestions( instance, list );
			} )
			.catch( function ( error ) {
				// A cancelled request is not a failure, it is this code's own
				// doing. Neither is one the field has already moved past.
				if ( 'AbortError' === error.name || token !== search.sequence ) {
					return;
				}

				// Quietly. Suggestions are a convenience, and a sentence about
				// them would land in the results list on top of the locations
				// that did load — while the field still works if it is used.
				closeSuggestions( instance );
				warn( error );
			} );
	}

	/**
	 * Restarts the debounce after a keystroke.
	 *
	 * @param {object} instance The locator.
	 * @returns {void}
	 */
	function queueSuggestions( instance ) {
		var search = instance.search;
		var query = search.input.value.trim();

		if ( null !== search.timer ) {
			window.clearTimeout( search.timer );
			search.timer = null;
		}

		// Covers the empty field as well as the one-character one: a field
		// somebody has just cleared has nothing to suggest for, and neither
		// has a field holding a single letter. MIN_QUERY has the reasoning.
		if ( query.length < MIN_QUERY ) {
			closeSuggestions( instance );

			return;
		}

		search.timer = window.setTimeout( function () {
			search.timer = null;
			askSuggestions( instance, query );
		}, SUGGEST_DELAY );
	}

	/**
	 * Takes one suggestion: its text into the field, its point onto the map.
	 *
	 * No /geocode. The suggestion already carries coordinates, so looking its
	 * own label up again would be a second upstream request for an answer
	 * already in hand — and the two can disagree, which would move the map
	 * somewhere other than the place that was chosen.
	 *
	 * @param {object} instance The locator.
	 * @param {object} chosen   `{ label, place }`.
	 * @returns {void}
	 */
	function takeSuggestion( instance, chosen ) {
		instance.search.input.value = chosen.label;

		closeSuggestions( instance );

		instance.pendingSearch = locate( instance, chosen.place, beginSearch( instance ) );
	}

	/**
	 * Looks up whatever is in the field and moves the locator to it.
	 *
	 * @param {object} instance The locator.
	 * @returns {void}
	 */
	function submitSearch( instance ) {
		var search = instance.search;
		var query = search.input.value.trim();
		var target;
		var token;
		var init;

		// Before the request rather than after it: a popup left open over a
		// list that is about to be replaced is the stale suggestion this whole
		// path exists to avoid.
		closeSuggestions( instance );

		if ( '' === query ) {
			return;
		}

		target = url( instance.config.routes.geocode, { q: query } );

		if ( null === target ) {
			say( instance.element, text( 'searchFailed' ) );

			return;
		}

		say( instance.element, text( 'searching' ) );

		token = beginSearch( instance );
		init = { credentials: 'same-origin' };

		if ( instance.searchController ) {
			init.signal = instance.searchController.signal;
		}

		instance.pendingSearch = window
			.fetch( target, init )
			.then( function ( response ) {
				var error;

				if ( stale( instance, token ) ) {
					return null;
				}

				if ( ! response.ok ) {
					error = new Error( 'GET /geocode answered ' + response.status );
					// Carried on the error so the catch can say something the
					// visitor can act on rather than one sentence for all four.
					error.status = response.status;

					throw error;
				}

				return response.json();
			} )
			.then( function ( body ) {
				var place;

				// null is what the step above hands over when this search has
				// been replaced. There is no second staleness check here: the
				// two were mutually redundant — either one alone stopped the
				// chain — and a sweep could delete either with every case
				// still green. The one kept is the earlier one, which also
				// saves parsing a body nobody will read.
				if ( null === body ) {
					return null;
				}

				place = 'object' === typeof body ? point( body.lat, body.lng ) : null;

				if ( null === place ) {
					// A 200 whose coordinates are unusable. Leaflet takes NaN
					// without complaint and draws a blank rectangle, which is
					// the one outcome this file exists to prevent.
					throw new Error( 'GET /geocode did not answer with a place' );
				}

				return locate( instance, place, token );
			} )
			.catch( function ( error ) {
				// Same rule as fetchList(): a search this locator has already
				// replaced says nothing, and neither does one it cancelled
				// itself. The message belongs to the search that is still the
				// current one.
				if ( 'AbortError' === error.name || stale( instance, token ) ) {
					return;
				}

				say( instance.element, text( searchFailure( error.status ) ) );
				warn( error );
			} );
	}

	/**
	 * Runs the search the field describes, however it was asked for.
	 *
	 * The one place that decides what "search now" means, so that the button
	 * and the Enter key cannot drift into two answers. What it decides is the
	 * suggestion state: with the popup open on a highlighted option, that
	 * option is the search — it already carries coordinates, so taking it is
	 * both what the visitor can see themselves choosing and the path that
	 * spends no upstream lookup. With nothing highlighted there is only what
	 * was typed, and that has to be geocoded.
	 *
	 * The order matters more for the button than for the key, and for a reason
	 * worth writing down. A pointer press on a button is not the same event
	 * sequence in every browser: Chrome and Edge focus the button on mousedown,
	 * which blurs the field, which closes the popup, so the click arrives with
	 * nothing highlighted. Safari and Firefox on macOS do not focus buttons on
	 * click at all, so the popup is still open and an option may still be
	 * active when the click lands. Both orderings end in the search the
	 * visitor was looking at, because both go through here.
	 *
	 * Reachable with no field at all: Shortcode::filters() emits the two
	 * together, but a theme or page builder that rebuilt the filter bar can
	 * leave a button over nothing, and wireSubmit() binds whatever button it
	 * finds. instance.search is read here rather than captured at wiring time
	 * so that the guard is about the locator's state and not about the order
	 * init() happens to call the wiring in.
	 *
	 * @param {object} instance The locator.
	 * @returns {void}
	 */
	function runSearch( instance ) {
		var search = instance.search;

		if ( ! search ) {
			return;
		}

		if ( -1 !== search.active && search.places[ search.active ] ) {
			takeSuggestion( instance, search.places[ search.active ] );

			return;
		}

		submitSearch( instance );
	}

	/**
	 * The key handling, which is the combobox.
	 *
	 * @param {object} instance The locator.
	 * @param {object} event    The keydown.
	 * @returns {void}
	 */
	function onKey( instance, event ) {
		var key = event.key;

		if ( 'ArrowDown' === key || 'ArrowUp' === key ) {
			// Otherwise the page scrolls under the popup.
			event.preventDefault();
			moveActive( instance, 'ArrowDown' === key ? 1 : -1 );

			return;
		}

		if ( 'Escape' === key ) {
			// The text stays. Escape closes the popup; it does not undo the
			// typing, which is somebody's work.
			event.preventDefault();
			closeSuggestions( instance );

			return;
		}

		if ( 'Enter' === key ) {
			// Prevented whether or not there is a suggestion to take. This
			// locator emits no <form> of its own, so there is nothing of ours
			// to submit; what this guards against is the markup sitting inside
			// somebody else's form, where Enter in a text field is implicit
			// submission and reloading the page is never what was meant.
			event.preventDefault();

			runSearch( instance );

			return;
		}

		if ( 'Tab' === key ) {
			// Not prevented: Tab moves on, and the popup goes with it.
			closeSuggestions( instance );
		}
	}

	/**
	 * Turns the plain search field into a combobox, and builds its popup.
	 *
	 * All of it in JavaScript, and none of it in Shortcode::render(), because
	 * markup that says role="combobox" before this file has run is a promise
	 * to a screen reader that nothing is there to keep: a combobox with no
	 * popup, no keys and no suggestions. Without the script the markup is a
	 * plain search field, which is exactly what it then is.
	 *
	 * The popup goes beside the <label> rather than inside it. A click inside
	 * a label is a click on the label, which moves the focus to the control it
	 * wraps — so an option inside one would fight the choice being made.
	 *
	 * @param {object} instance The locator.
	 * @returns {object|null} The search state, or null when there is no field.
	 */
	function wireSearch( instance ) {
		var doc = instance.element.ownerDocument;
		var input = instance.element.querySelector( '.slosm__search' );
		var listbox;
		var anchor;
		var suggesting;

		if ( ! input || ! input.parentNode ) {
			return null;
		}

		// Beside the <label>, never inside it. When the markup has been
		// rearranged and the field is not in a label at all — a page builder
		// re-emitting it, a content filter — the popup goes next to the field
		// itself rather than climbing to a grandparent that may be outside
		// this locator entirely, which on the shortcode's own markup would be
		// the page.
		anchor = 'LABEL' === input.parentNode.tagName && input.parentNode.parentNode ? input.parentNode : input;

		listbox = doc.createElement( 'ul' );
		listbox.className = 'slosm__suggestions';
		listbox.setAttribute( 'role', 'listbox' );
		listbox.setAttribute( 'id', 'slosm-' + instance.serial + '-listbox' );
		listbox.setAttribute( 'hidden', 'hidden' );

		// Whether this site suggests addresses at all. A site pointed at the
		// public Photon instance may well not want to: its usage policy is the
		// same one Geocoder's class docblock quotes, and autocomplete is the one
		// feature that turns one visitor into a request per few keystrokes.
		//
		// Off means three things are not done, and they are three rather than
		// one because each is separately visible: the listbox is never put in
		// the document, the field is never told it is a combobox, and nothing
		// listens for typing. The node is still *made*, detached, so every later
		// read of instance.search.listbox is total — the alternative is a null
		// check in six places for a state that is now reachable.
		//
		// What is unaffected: Enter still searches. The field is a search field
		// before it is a combobox, and onKey() sends what was typed to /geocode
		// whether or not anything suggested it.
		suggesting = false !== instance.config.autocomplete;

		if ( suggesting ) {
			anchor.parentNode.insertBefore( listbox, anchor.nextSibling );

			input.setAttribute( 'role', 'combobox' );
			input.setAttribute( 'aria-autocomplete', 'list' );
			input.setAttribute( 'aria-expanded', 'false' );
			input.setAttribute( 'aria-controls', 'slosm-' + instance.serial + '-listbox' );
		}

		instance.search = {
			input: input,
			listbox: listbox,
			options: [],
			places: [],
			active: -1,
			timer: null,
			controller: null,
			sequence: 0,
			// The last query the service actually answered, and what it said.
			// One entry, because the shape this catches is a correction.
			memo: null,
		};

		if ( suggesting ) {
			input.addEventListener( 'input', function () {
				queueSuggestions( instance );
			} );
		}

		input.addEventListener( 'keydown', function ( event ) {
			onKey( instance, event );
		} );

		input.addEventListener( 'blur', function () {
			closeSuggestions( instance );
		} );

		return instance.search;
	}

	/**
	 * Wires the search button to the search the field describes.
	 *
	 * Three lines, and every one of them is a decision the alternative was
	 * available for.
	 *
	 * **No <form>, so no submit event to listen for.** Shortcode::filters()
	 * renders `role="search"` on the container and an ordinary
	 * `type="button"` here, and this listens for the click. A <form> would
	 * have brought implicit submission with it — Enter in a text field inside
	 * a form activates the default button — which is a page reload for every
	 * visitor this file has not reached yet, and this file is at the end of
	 * the footer. It would also be a form a page builder can swallow: HTML
	 * forbids nested forms, so a locator dropped inside somebody's form
	 * parses with ours discarded and these controls adopted by theirs, at
	 * which point a submit button submits a stranger's form. None of that
	 * buys anything, because the thing a form would trigger is the function
	 * below, which the click reaches directly.
	 *
	 * **runSearch(), not submitSearch().** The button and the Enter key are
	 * one code path on purpose; see runSearch() for what that path decides
	 * and why a click can arrive with the popup either open or closed.
	 *
	 * **No word anywhere when there is no button.** Shortcode::filters()
	 * emits one unconditionally, but a theme or a page builder can have
	 * rebuilt the filter bar, and a locator that is driven by Enter alone is
	 * the locator every version of this plugin before this one shipped.
	 *
	 * @param {object} instance The locator.
	 * @returns {void}
	 */
	function wireSubmit( instance ) {
		var control = instance.element.querySelector( '.slosm__submit' );

		if ( ! control ) {
			return;
		}

		control.addEventListener( 'click', function () {
			runSearch( instance );
		} );
	}

	/**
	 * Brings one .slosm container to life.
	 *
	 * @param {Element} container The .slosm element.
	 * @returns {object|null} The instance, or null when it could not start.
	 */
	function init( container ) {
		var config;
		var canvas;
		var observer;
		var centre;
		var instance;
		var tile;

		if ( initialised.has( container ) ) {
			return null;
		}

		initialised.add( container );

		config = readConfig( container );

		if ( null === config ) {
			return fail( container, 'configError' );
		}

		canvas = container.querySelector( '.slosm__map' );

		if ( ! canvas ) {
			return fail( container, 'configError' );
		}

		// Leaflet is this script's declared dependency, so it is on the page
		// before this runs unless something dequeued it or the request for it
		// failed. Either way there is no map to build, and saying so beats a
		// TypeError in the console that nobody but a developer will read.
		if ( ! window.L || 'function' !== typeof window.L.map ) {
			return fail( container, 'loadFailed' );
		}

		// The same treatment for fetch. Every browser WordPress 6.0 supports
		// has it, so this is not about a browser; it is about the site that
		// loads a service worker or a polyfill kit that leaves the global in a
		// state it cannot be called in. Checking costs a line and the
		// alternative is an uncaught TypeError halfway through init, with a
		// container that already has a map in it and never gets a message.
		if ( 'function' !== typeof window.fetch && 'preload' === config.mode ) {
			return fail( container, 'loadFailed' );
		}

		centre = point( config.lat, config.lng );

		// No `centre` on the instance. It was here so that renderResults()
		// could refuse to frame when the shortcode had named one, and Task 32
		// took that refusal out; the centre's remaining job — the view the map
		// opens on — is done a few lines below and needs no field. A
		// write-only property is a fact nobody reads, and the next reader
		// would have had to prove that before daring to touch it.
		instance = {
			element: container,
			config: config,
			map: window.L.map( canvas ),
			markers: [],
			// The cluster group when this locator clusters, and null when it
			// does not; and the thing pins are added to, which is one or the
			// other. Both are filled in below, once the map has a view.
			cluster: null,
			layer: null,
			// The preloaded payload, kept because in preload mode it is the
			// only copy: a search sorts this rather than asking again.
			items: [],
			// What is on screen now: the rows, their pins and their distances.
			entries: [],
			// The point the last search was made from, or null.
			origin: null,
			// The entry the pointer or the focus is on, or null.
			lit: null,
			// The combobox state; null when this locator has no search field.
			search: null,
			// The category this locator is filtered to, which starts as the one
			// the shortcode pinned — '' when it pinned none. The select moves
			// it; every request and every local filter reads it rather than the
			// config, so there is one answer to "what is this map showing".
			category: 'string' === typeof config.category ? config.category : '',
			// Every category name seen in any payload so far, sorted, and the
			// select they are rendered into. Null when this locator has no
			// select, or when the shortcode pinned a category and the control
			// was taken out of play.
			categories: [],
			categorySelect: null,
			// Which search run is the current one, and what is cancelling the
			// last one. Two searches in flight is the ordinary case — somebody
			// types, waits, and types again — and the answer that arrives last
			// is not necessarily the one that was asked last.
			searchRun: 0,
			searchController: null,
			// Every full record this locator has been told, by post id, and
			// every request for one that has not answered yet. Per instance
			// rather than per entry: entries are thrown away and rebuilt on
			// every draw, and a cache that died with them would be empty
			// exactly when somebody comes back to a pin they just read.
			//
			// Null-prototype, so that a payload carrying an id of
			// "constructor" or "__proto__" — which storeId() already refuses,
			// and which is one filter away from arriving anyway — cannot find
			// an inherited property here and be mistaken for a cached record.
			records: Object.create( null ),
			recordRequests: Object.create( null ),
			// This locator's own number, which its ids are built from.
			serial: serialFor( container.ownerDocument ),
			// Whether this locator's own list has arrived. Preload mode only:
			// query mode never holds one, and every draw there is already the
			// answer to a request. show() reads it to tell "nothing found" apart
			// from "nothing yet", which are the same empty list and very different
			// sentences.
			loaded: false,
			ready: null,
			// The work in flight, so a test can wait for it rather than for a
			// length of time. Task 13 opened this seam with `ready`.
			pendingSuggest: null,
			pendingSearch: null,
			pendingRecord: null,
		};

		// Registered the moment the map exists, and before anything can throw
		// past this point. A map that is built and then abandoned by a later
		// failure is exactly the one worth being able to take apart; the
		// branches above this line return before there is anything to record.
		/*
		 * And something to notice the container changing size, which Leaflet
		 * does not.
		 *
		 * Leaflet measures its container twice: once when the map is built,
		 * and again on a window resize, which is what `trackResize` binds. A
		 * container that changes size *without the window changing size* is
		 * invisible to both, and the map goes on drawing tiles across the box
		 * it measured at the start. Found by switching this plugin's own skin
		 * off on a live page and reproduced in the browser: container widened
		 * from 629 to 900, tiles stayed at 768, 154 pixels of the flat
		 * --slosm-map-background down the right-hand side — still there after
		 * two and a half seconds, and still there after a window 'resize'.
		 *
		 * It is not a rare shape. A page builder's canvas, a tab, an
		 * accordion, a sidebar collapsing and a stylesheet being switched off
		 * all resize a container while the window sits still, and the report
		 * that started this was a Bricks element whose wrapper settles its
		 * width after the element inside it has been initialised.
		 *
		 * `invalidateSize()` and no arguments: Leaflet re-reads the container
		 * and redraws what it owes. The observer is given the canvas rather
		 * than `container`, because the canvas is the box Leaflet measures —
		 * a locator whose *list* grows a row has not changed the map's size
		 * and has nothing to redraw.
		 *
		 * Guarded, and the guard is not decoration: ResizeObserver is from
		 * 2020 — Chrome 64, Firefox 69, Safari 13.1 — which is comfortably
		 * inside this plugin's WordPress 6.0 floor, but a plugin that throws
		 * on a constructor that is not there takes the whole locator down
		 * with it on the one browser that is older.
		 */
		observer = null;

		if ( 'function' === typeof window.ResizeObserver ) {
			observer = new window.ResizeObserver( function () {
				if ( instance.map && 'function' === typeof instance.map.invalidateSize ) {
					instance.map.invalidateSize();
				}
			} );

			observer.observe( canvas );
		}

		started.push( { element: container, map: instance.map, observer: observer } );

		// The view is set before any layer is added. This used to claim
		// Leaflet throws otherwise, citing _checkIfLoaded; that was a
		// misreading and a reviewer caught it. Map.addLayer ends in
		// `this.whenReady(t._layerAdd,t)` and whenReady is
		// `this._loaded?t.call(...):this.on("load",t,e)` — so a layer added
		// first is *deferred* to the 'load' event, which the first setView
		// fires. It would work.
		//
		// The ordering stays because it is the deterministic one: the layer is
		// attached at a point this file chose, not at whatever point Leaflet's
		// event queue gets to it, and a map that is never given a view at all
		// would silently keep its tile layer in that queue forever. It is
		// hygiene, not a crash guard, and the harness enforces it as a house
		// rule that says so.
		//
		// fitWorld() rather than a centre of 0,0 when nothing is known. 0,0 is
		// the Gulf of Guinea, which is the fabricated point
		// Store::has_coordinates() refuses to treat as a place; the whole world
		// is at least true.
		if ( null !== centre ) {
			instance.map.setView( centre, zoomLevel( config.zoom ) );
		} else {
			instance.map.fitWorld();
		}

		// tileFor() builds a fresh object out of the config every time, so
		// there is nothing shared left to copy — which is the one thing that got
		// simpler when the frozen constant stopped being the source. Leaflet is
		// still handed an object it may write to freely.
		//
		// A null means the config carried no usable tile layer, which after
		// Settings::sanitise() means a config that did not come from this
		// plugin's settings screen. The map is drawn anyway and the pins and the
		// list still work; what is not drawn is an unattributed basemap, which
		// is a licence problem rather than a cosmetic one. A line in the console
		// for the developer, and nothing on the page, because nobody else can
		// act on it.
		tile = tileFor( config );

		if ( null === tile ) {
			warn( new window.Error( 'no usable tile layer in the config, so the map has no basemap' ) );
		} else {
			window.L.tileLayer( tile.url, tile.options ).addTo( instance.map );
		}

		// After the view, like every other layer, and for the same reason: the
		// map is given a view before anything is attached to it so that the
		// attachment happens at a point this file chose.
		instance.cluster = clusterFor( instance );
		instance.layer = instance.cluster || instance.map;

		container.classList.add( 'slosm--ready' );

		wireSearch( instance );
		wireSubmit( instance );
		wireCategory( instance );
		wireAmounts( instance );
		wireLocate( instance );

		// Preload mode only. Above the threshold the whole list must not be
		// fetched — that is what the mode means — and the request that replaces
		// it is the search, which only runs once somebody has typed an
		// address. Until then a query-mode locator is a map at its configured
		// view and an empty list, with no message: "no results" here would be
		// a claim nobody has checked.
		//
		// The `search` attribute prefills the field and is deliberately not
		// run on load. A search that ran by itself would put a /geocode round
		// trip on every page view of every page carrying a locator, for an
		// answer nobody has asked for yet.
		if ( 'preload' === config.mode ) {
			instance.ready = load( instance );
		} else {
			instance.ready = Promise.resolve();
		}

		// auto_locate, which is off by default and stays that way. Task 11's
		// reasoning for the default is that a map demanding a location before
		// somebody has read anything on the page is a map most people dismiss,
		// and dismissing it is a decision browsers remember — so an unasked
		// prompt does not merely fail, it takes the button with it.
		//
		// After the preload rather than beside it. The two would otherwise race,
		// and load() would win about as often as not: the payload landing after
		// the position would draw a list the visitor sees reorder itself. load()
		// guards against the harm of that ordering; this avoids the flicker.
		if ( true === config.autoLocate ) {
			instance.ready = instance.ready.then( function () {
				locateMe( instance );
			} );
		}

		return instance;
	}

	/**
	 * Brings every locator under `root` to life, each on its own.
	 *
	 * Nothing is shared between them: no registry, no index, no ordering.
	 * One that cannot start leaves a message on itself and the others carry
	 * on, which is why the loop collects rather than short-circuits.
	 *
	 * @param {Document|Element} root Where to look; the document by default.
	 * @returns {object[]} The instances that came up.
	 */
	function initAll( root ) {
		var instances = [];

		Array.prototype.forEach.call( ( root || document ).querySelectorAll( '.slosm' ), function ( container ) {
			var instance = null;

			/*
			 * init() turns its own known failures into a message, but it
			 * cannot know every way Leaflet can refuse. Without this, one
			 * throw escapes initAll() and every locator after it on the page
			 * is never touched — no map, no message, nothing in the markup to
			 * say why — which is the exact failure the message path exists to
			 * prevent, arriving through the loop instead of through a config.
			 *
			 * Not hypothetical. Map._initContainer throws
			 * 'Map container not found.' and 'Map container is already
			 * initialized.', and the second is reachable in production: the
			 * WeakSet above lives for as long as this evaluation does, so a
			 * caching or concatenating plugin that causes this file to run
			 * twice on one page builds a second, empty WeakSet and calls
			 * L.map() on a container that already carries a _leaflet_id.
			 *
			 * The container that threw gets the same message a failed fetch
			 * gets, and the loop carries on. The real error goes to the
			 * console, because the person who can act on 'already initialized'
			 * is a developer and the sentence on the page is for everybody
			 * else.
			 */
			try {
				instance = init( container );
			} catch ( error ) {
				fail( container, 'loadFailed' );

				if ( window.console && window.console.error ) {
					window.console.error( 'Store Locator: ' + error.message );
				}
			}

			if ( instance ) {
				instances.push( instance );
			}
		} );

		return instances;
	}

	/**
	 * Takes apart every locator whose container has left the document.
	 *
	 * `Node.isConnected` rather than a walk up `parentNode`, because it is
	 * what the DOM has for this question and it answers it for a node inside
	 * a detached subtree as well as for a node with no parent at all — which
	 * is the shape a page builder produces, since it drops a whole element
	 * wrapper rather than unparenting the locator inside it.
	 *
	 * The comparison is against `false` on purpose, and the asymmetry is the
	 * safety. Somewhere without `isConnected` answers `undefined`, and
	 * `! undefined` is true — which would make an environment that cannot
	 * answer the question destroy every map on the page. `false ===` makes
	 * the unanswerable case "leave it alone", which is what this file did
	 * before there was a teardown at all.
	 *
	 * `map.remove()` is Leaflet's own teardown: it is what takes the `resize`
	 * listener off `window` and clears the container's `_leaflet_id`. The
	 * container is forgotten from the WeakSet in the same breath, so the
	 * WeakSet's claim stays true — it means "has a live map", and after this
	 * the container has not.
	 *
	 * Wrapped, for the same reason `initAll()` wraps `init()`: one throw out
	 * of Leaflet here would leave the rest of the list unswept and, worse,
	 * would escape into whatever called this.
	 *
	 * @returns {number} How many were taken apart.
	 */
	function forget() {
		var gone = 0;
		var kept = [];

		started.forEach( function ( entry ) {
			if ( false !== entry.element.isConnected ) {
				kept.push( entry );

				return;
			}

			// Before the map goes, and in its own try: an observer left
			// connected holds the element, the map and every closure around
			// them, and goes on asking a removed map to measure itself.
			if ( entry.observer && 'function' === typeof entry.observer.disconnect ) {
				try {
					entry.observer.disconnect();
				} catch ( error ) {
					warn( error );
				}
			}

			try {
				entry.map.remove();
			} catch ( error ) {
				warn( error );
			}

			initialised.delete( entry.element );
			gone += 1;
		} );

		started = kept;

		return gone;
	}

	/**
	 * Forgets what has gone, then starts what has arrived.
	 *
	 * The one entry point a page builder needs, and the only reason it is on
	 * the public surface. Editing a page in Bricks replaces a locator's whole
	 * node on every control change, which is two events at once — a map that
	 * must be taken apart and a container that must be started — and they
	 * have to happen in that order: `initAll()` first would start the new
	 * node and then `forget()` would still find the old one, which works, but
	 * a throw between the two would leave a map nothing can reach.
	 *
	 * Deliberately not what `initAll()` does. `initAll()` is what the front
	 * end runs, once, at load; making it sweep would give every ordinary page
	 * a walk it has no use for and would change what a function two hundred
	 * cases already call means.
	 *
	 * @param {Document|Element} root Where to look; the document by default.
	 * @returns {object[]} The instances that came up.
	 */
	function sweep( root ) {
		forget();

		return initAll( root );
	}

	window.SLOSM = Object.freeze( {
		Geo: Geo,
		tileFor: tileFor,
		ZOOM: ZOOM,
		STRINGS: FALLBACK_STRINGS,
		text: text,
		url: url,
		init: init,
		initAll: initAll,
		sweep: sweep,
	} );

	// A deferred script runs after the parser has finished and before
	// DOMContentLoaded fires, so readyState is 'interactive' and every locator
	// is already in the tree: there is nothing to wait for. The other branch is
	// for the site that loads this file some other way — an optimiser that
	// strips defer, a theme that prints it in the head — where the parser may
	// still be halfway down the page. Adding the listener unconditionally would
	// be the worse bug of the two: on a page where DOMContentLoaded has already
	// gone past, a listener added afterwards never runs at all.
	//
	// Task 15 gave the second branch a second job, and it is worth knowing
	// before anyone deletes it as belt-and-braces. WordPress 6.0 to 6.2 have no
	// delayed-strategy feature, so on the floor version this file is a plain
	// blocking tag in the footer and the cluster library's tag is printed below
	// it. Taking this branch is what lets that tag execute first; without it,
	// clustering would silently never start on exactly the versions the plugin
	// declares support for. clusterFor() and Assets::register() have the rest.
	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			initAll();
		} );
	} else {
		initAll();
	}
} )( window );
