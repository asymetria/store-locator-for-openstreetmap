<?php
/**
 * The [store_locator] shortcode: attributes in, markup and a config out.
 *
 * @package Store_Locator_For_OpenStreetMap
 */

namespace Asymetria\StoreLocator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( __NAMESPACE__ . '\\Shortcode' ) ) {

	/**
	 * Renders one locator, and decides how it will get its locations.
	 *
	 * This class writes no JavaScript. It produces a flat block of markup with
	 * one machine-readable attribute on it, and asks Assets for the script and
	 * stylesheet that will read it; Task 13 onwards fills those files in.
	 *
	 * The enqueue lives in render() rather than on a hook, and that is the
	 * conditional-loading design rather than a convenience: a hook fires on
	 * every page of the site and would have to guess whether this one has a
	 * locator on it, while render() is called if and only if one does. See
	 * Assets for what enqueuing after wp_enqueue_scripts has already fired does,
	 * and why the answer is "prints in the footer" rather than "prints nowhere".
	 *
	 * Why the config is one data- attribute and not a global
	 * ------------------------------------------------------
	 * Two locators on one page is an ordinary request — a "find a branch" map in
	 * the content and a smaller one in a footer widget — and every arrangement
	 * that puts configuration in a global has to invent a way for the two to
	 * tell their settings apart: an index, an id, a registry the script has to
	 * be enqueued after. wp_localize_script() is the WordPress-shaped version of
	 * that mistake, since it prints one variable per *handle*, not per instance,
	 * so the second locator overwrites the first.
	 *
	 * An attribute on the container has none of that. The element the script is
	 * initialising is the element carrying its settings, so the script reads
	 * them off the node it was handed and there is nothing to coordinate. It
	 * also survives being moved: a page builder that clones or reorders the
	 * block moves the settings with it.
	 *
	 * What is deliberately *not* in it
	 * --------------------------------
	 * - The locations. Even at the preload ceiling that is about 121 KB of json
	 *   inlined into an html page that no browser and no edge cache can reuse,
	 *   while the same list from GET /stores is one cacheable request shared by
	 *   every page on the site. The config carries the *decision*; the data
	 *   comes from the route.
	 * - A nonce. All four routes are public — permission_callback is
	 *   __return_true — so there is nothing for a nonce to authorise, and a
	 *   nonce in the html would make every page carrying a locator vary per
	 *   session and drop out of full-page caching. A nonce that buys nothing and
	 *   costs the cache is worse than no nonce.
	 * - The interface's own strings. "No results", "Searching…", "Location
	 *   permission denied" are identical for every locator on the site, so a
	 *   per-instance attribute would pay for them once per locator for nothing.
	 *   Their home is wp_localize_script() keyed to the script handle, which has
	 *   no collision problem precisely because the values do not vary by
	 *   instance — the opposite of the settings above. Task 12 built that
	 *   payload; it is Assets::strings(), and a string the front end needs in
	 *   every locator belongs there rather than here.
	 *
	 * What a page cache does to it
	 * ----------------------------
	 * mode and count are facts about the site at render time, baked into html
	 * that a full-page cache may then serve for hours. A site that crosses the
	 * threshold goes on serving pages that say "preload" until those pages are
	 * regenerated, and the map draws MAX_LIMIT locations believing it has all of
	 * them — the same failure preload_threshold()'s cap exists to prevent,
	 * arriving through the page cache instead of through the filter. Nothing
	 * here can fix that: a shortcode cannot invalidate somebody else's cache,
	 * and the only honest alternative is to stop deciding on the server, which
	 * costs every visitor a round trip before the map can start. It is recorded
	 * rather than solved, and the front end should treat a preload response of
	 * exactly MAX_LIMIT items as "there may be more" rather than as the whole
	 * list.
	 *
	 * Escaping is the only defence this pipeline has
	 * ----------------------------------------------
	 * Store::from_array() states that it does not sanitise, the repository
	 * stores what it was given, and the REST controller emits json where html
	 * escaping is not a question. Here the values meet an html attribute, an
	 * option element and an html comment, so here is where they are made safe.
	 *
	 * The json is encoded with JSON_HEX_TAG, JSON_HEX_AMP, JSON_HEX_APOS and
	 * JSON_HEX_QUOT, which is what core's own Interactivity API does for exactly
	 * this situation (see wp_interactivity_data_wp_context()). After those flags
	 * the only `<`, `>`, `&`, `'` or `"` left anywhere in the string are json's
	 * own structural quotes — json has no other character html escaping cares
	 * about — so nothing from a location name can reach esc_attr() as something
	 * for it to act on.
	 *
	 * That is the point rather than a curiosity, because esc_attr() does not
	 * double-encode. A payload arriving with the literal text `&amp;` in it
	 * comes back unchanged and the browser hands the script `&`: not an
	 * injection, but a location name silently corrupted with no error anywhere.
	 * Hexing first means that text can never be there. esc_attr() still runs on
	 * top, and has real work to do — the structural quotes would end the
	 * attribute on the first one — because a defence that rests on a flags
	 * constant staying correct in one expression is not a defence.
	 *
	 * Translated strings go through esc_html( __() ) rather than esc_html__().
	 * They are the same thing on a real site, but the test suite's esc_html__()
	 * stub returns its argument untouched, so the nested form is the one whose
	 * escaping the suite can actually see.
	 */
	final class Shortcode {

		/**
		 * The shortcode tag.
		 *
		 * @var string
		 */
		public const TAG = 'store_locator';

		/**
		 * At or below this many published locations, ship the whole list once.
		 *
		 * Rest_Controller::MAX_LIMIT rather than a second 500, and the tie is
		 * not cosmetic. Preloading means "fetch everything from /stores in one
		 * request", and /stores will not return more than MAX_LIMIT items. A
		 * threshold above that cap is a promise the route cannot keep: the map
		 * would draw MAX_LIMIT locations, believe it had them all, and say
		 * nothing about the rest. preload_threshold() enforces the cap on the
		 * filtered value for the same reason.
		 *
		 * @var int
		 */
		public const PRELOAD_THRESHOLD = Rest_Controller::MAX_LIMIT;

		/**
		 * At or above this many published locations, collapse pins into clusters.
		 *
		 * Fifty, and the number is a judgement rather than a measurement — so
		 * what is worth recording is the two ends it sits between rather than
		 * the middle.
		 *
		 * The floor is twenty. assets/markercluster/README.md promises in as
		 * many words that a site with twenty branches never downloads the
		 * library, and 34 KB of JavaScript plus two stylesheets to tidy up
		 * twenty pins is a cost with nothing on the other side of it. The
		 * ceiling is PRELOAD_THRESHOLD: a line above that would mean no
		 * preloading site ever clusters, and most sites this plugin is for
		 * preload. Fifty is comfortably inside both, at roughly the count where
		 * Leaflet's default 25×41 pin starts overlapping its neighbours at a
		 * country-wide zoom.
		 *
		 * Why a count decides this at all, rather than a setting: it is the
		 * argument preload_threshold() makes about its own line. A site owner
		 * asked "should your map cluster?" has no way to answer — it depends on
		 * how their pins fall at whatever zoom each visitor happens to be at,
		 * which is not on the screen when the shortcode is being written. PHP
		 * knows the count and can simply decide.
		 *
		 * And why there is an attribute anyway: a count cannot see the two
		 * shapes that really need overruling. A dozen shops on one street
		 * overlap at every zoom somebody will use, and four hundred spread
		 * across a continent never do. Both are a person looking at their own
		 * map and knowing something this number does not; that is a different
		 * act from guessing, and it belongs in the shortcode.
		 *
		 * THE NUMBER ITSELF IS DELIBERATELY NOT PINNED
		 * --------------------------------------------
		 * The suite asserts the two bounds — above twenty, below
		 * PRELOAD_THRESHOLD — and the behaviour at the boundary, whatever the
		 * boundary is. It does not assert that the boundary is 50, and a
		 * mutation moving it to 51 survives the whole suite on purpose. Fifty is
		 * a judgement about where pins start to crowd, nobody here has measured
		 * it, and a case pinning it would be this file marking its own homework:
		 * it would fail for a person who tuned the number with a real map in
		 * front of them, which is the only way it will ever be improved.
		 *
		 * @var int
		 */
		public const CLUSTER_THRESHOLD = 50;

		/**
		 * Map height in pixels when the shortcode names none.
		 *
		 * Since Task 21 the fallback is the site's map_height setting, and this
		 * is that setting's own default — so an unconfigured site still gets 480
		 * and the number is written once. pixels() keeps it as its argument
		 * default, which is what a caller with no settings to hand still gets.
		 *
		 * @var int
		 */
		public const DEFAULT_HEIGHT = 480;

		/**
		 * The shortest map worth drawing, in pixels.
		 *
		 * A map below this is not a small map, it is a broken one: Leaflet's own
		 * zoom control is 60 px tall and the attribution line sits on top of the
		 * tiles.
		 *
		 * @var int
		 */
		public const MIN_HEIGHT = 120;

		/**
		 * The tallest map this will render, in pixels.
		 *
		 * A ceiling rather than "whatever was typed", because height goes into
		 * an inline style and a typo of 50000 is a page whose content is pushed
		 * off the bottom of the world with no obvious cause.
		 *
		 * @var int
		 */
		public const MAX_HEIGHT = 2000;

		/**
		 * Initial zoom when the shortcode names none.
		 *
		 * The site's default_zoom setting is the fallback since Task 21, and this
		 * is that setting's default. The *ceiling* moved as well and is the more
		 * interesting half: attributes() clamps to min( MAX_ZOOM, tile_max_zoom ),
		 * because the tile provider is configurable now and one that stops at 17
		 * answers 404 for every tile above it.
		 *
		 * @var int
		 */
		public const DEFAULT_ZOOM = 12;

		/**
		 * The whole world in one tile.
		 *
		 * @var int
		 */
		public const MIN_ZOOM = 1;

		/**
		 * As close as the standard OpenStreetMap tile layer goes.
		 *
		 * Leaflet will happily accept 22 and the tile server will answer 404 for
		 * every tile, which looks like a broken plugin rather than a zoom level
		 * nobody renders.
		 *
		 * @var int
		 */
		public const MAX_ZOOM = 19;

		/**
		 * The longest search string worth prefilling, in characters.
		 *
		 * The same 200 that /geocode and /suggest declare as maxLength for q. A
		 * prefill longer than the route will accept is a search box that fails
		 * on submit for a reason nobody can see; the suite asserts the two
		 * numbers against each other rather than trusting this comment.
		 *
		 * @var int
		 */
		public const MAX_SEARCH = 200;

		/**
		 * The radii offered in the filter, in whichever unit is in use.
		 *
		 * A record of what this plugin offered before Task 21 rather than the
		 * live list: radius_options() reads Settings::get( 'radius_choices' )
		 * now, and Settings::defaults() ships these same seven numbers, so an
		 * unconfigured site is unchanged. The constant is kept because it is what
		 * the suite pins the default against — a case comparing the two is how a
		 * silent change to either is caught — and because a filter list clamped
		 * to Rest_Controller::MAX_RADIUS in two places wants one written source.
		 *
		 * Anything above Rest_Controller::MAX_RADIUS is dropped rather than
		 * shown and refused, wherever the list came from.
		 *
		 * @var int[]
		 */
		public const RADIUS_CHOICES = array( 5, 10, 25, 50, 100, 250, 500 );

		/**
		 * The result counts offered in the filter.
		 *
		 * A default since Task 21, on the same footing as RADIUS_CHOICES above.
		 *
		 * @var int[]
		 */
		public const LIMIT_CHOICES = array( 10, 25, 50, 100, 250, 500 );

		/**
		 * How the config is encoded before it meets esc_attr().
		 *
		 * The four hex flags leave the json with none of the five characters
		 * html escaping is about; see the class docblock for why that matters
		 * more than it looks. The two unescaped flags are readability only:
		 * slashes and non-ASCII survive as themselves, so "Kraków" is legible in
		 * view-source and a `/` in a url is not `\/`. Neither weakens the first
		 * four — a `<` inside a url is still hexed.
		 *
		 * @var int
		 */
		public const JSON_FLAGS = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

		/**
		 * The handles this locator needs on the page it is rendering into.
		 *
		 * Held rather than looked up, so that the object Plugin::boot() hooked
		 * onto wp_enqueue_scripts is the object render() enqueues through.
		 *
		 * @var Assets
		 */
		private Assets $assets;

		/**
		 * Constructor.
		 *
		 * The argument is optional because a caller that only wants attributes
		 * sanitised should not have to construct an asset registry to get them,
		 * and because every test in this suite that predates Task 12 writes
		 * `new Shortcode()`. The default is a real Assets rather than null: a
		 * locator rendered by an object that was built without one still needs
		 * its script, and a null here would be a locator that renders and never
		 * comes alive.
		 *
		 * @param Assets|null $assets The asset registry, or null to build one.
		 */
		public function __construct( ?Assets $assets = null ) {
			$this->assets = $assets ?? new Assets();
		}

		/**
		 * Registers the tag. Call on init, not before.
		 *
		 * add_shortcode() itself would work earlier, but this runs on init
		 * because the render path asks the taxonomy to resolve a category, and
		 * a taxonomy is not registered until init has run. Hooking it there also
		 * keeps Plugin::boot() a list of add_action() calls and nothing else,
		 * which is what makes the suite able to see every registration this
		 * plugin makes.
		 *
		 * @return void
		 */
		public function register(): void {
			add_shortcode( self::TAG, array( $this, 'render' ) );
		}

		/**
		 * The attributes this shortcode accepts, and what they arrive as.
		 *
		 * Every default is the empty string, and the sanitisers below decide
		 * what an empty one means. That is deliberate: it makes "the attribute
		 * was not given" and "the attribute was given as nonsense" travel the
		 * same path, so there is one place per attribute that decides its
		 * default rather than two that can disagree.
		 *
		 * @return array<string, string>
		 */
		public static function raw_defaults(): array {
			return array(
				'height'       => '',
				'zoom'         => '',
				'lat'          => '',
				'lng'          => '',
				'radius'       => '',
				'limit'        => '',
				'units'        => '',
				'category'     => '',
				'search'       => '',
				'label'        => '',
				'near_me'      => '',
				'auto_locate'  => '',
				'cluster'      => '',
				'button_class' => '',
			);
		}

		/**
		 * Reads the shortcode's attributes and makes every one of them safe.
		 *
		 * shortcode_atts() is what drops everything undeclared: it copies the
		 * keys of its first argument and nothing else, so `onclick="alert(1)"`
		 * in a shortcode never reaches this class at all. WordPress has already
		 * lowercased the names by then — shortcode_parse_atts() does it — so
		 * `HEIGHT="500"` and `height="500"` are the same attribute and this
		 * method never sees the difference.
		 *
		 * The numeric bounds are Rest_Controller's, taken from its constants
		 * rather than retyped. A shortcode that clamped the radius at 1000 while
		 * /stores refused anything over 500 would produce a search that comes
		 * back empty with no error anywhere; the suite asserts these against the
		 * route's declared schema so the two cannot drift.
		 *
		 * @param mixed $atts Attributes; WordPress passes '' when there are none.
		 * @return array
		 */
		public function attributes( $atts ): array {
			$settings = Settings::all();
			$given    = shortcode_atts( self::raw_defaults(), $atts, self::TAG );

			/*
			 * shortcode_atts() returns whatever the shortcode_atts_store_locator
			 * filter returned, and a filter may return an array missing keys, or
			 * something that is not an array at all. Eleven direct reads against
			 * that is "Undefined array key" on a front-end page — the exact
			 * class of failure framework.php was changed to fail a case for, and
			 * the same one published_count() guards against on the other side of
			 * this class. Re-laying the defaults underneath costs one array
			 * merge and makes every read below total.
			 *
			 * A cast — (array) $given — would be equally total and no test can
			 * tell the two apart, since a scalar becomes key 0 and key 0 is
			 * never read. is_array() is kept because discarding a filter's
			 * wrong-typed return outright is a clearer statement than quietly
			 * reshaping it into something that happens to work.
			 *
			 * Keys a filter *added* are left in place and simply never read: the
			 * literal return array below names what this method answers with,
			 * and that is what keeps an undeclared attribute out, rather than
			 * anything shortcode_atts() does.
			 */
			$given = array_merge( self::raw_defaults(), is_array( $given ) ? $given : array() );

			$category = $this->category( $given['category'] );

			/*
			 * The tile layer's own ceiling. A provider that stops at 17 answers
			 * 404 for every tile above it, which reads as a broken plugin rather
			 * than as a zoom nobody renders — the argument MAX_ZOOM's docblock
			 * makes about 19, applied to a number that is now a setting.
			 *
			 * The setting alone, with no min( self::MAX_ZOOM, … ) over it. There
			 * was one, and no input could reach it: Settings::sanitise() clamps
			 * tile_max_zoom to MIN_ZOOM..MAX_ZOOM and Settings::all() sanitises
			 * everything it answers with, including a value a direct database
			 * edit wrote. It is one of six guards this task removed for that
			 * reason; radius() has the list.
			 */
			$max_zoom = (int) $settings['tile_max_zoom'];

			return array(
				'height'           => $this->pixels( $given['height'], (int) $settings['map_height'] ),
				'zoom'             => $this->bounded_int( $given['zoom'], self::MIN_ZOOM, $max_zoom, min( $max_zoom, (int) $settings['default_zoom'] ) ),
				// A centre named in the shortcode wins; the site default is
				// what an unnamed one falls back to; and null — no default
				// either — is still what makes the map frame itself around the
				// locations it found rather than around the Gulf of Guinea.
				'lat'              => $this->coordinate( $given['lat'], -90.0, 90.0 ) ?? $settings['default_lat'],
				'lng'              => $this->coordinate( $given['lng'], -180.0, 180.0 ) ?? $settings['default_lng'],
				'radius'           => $this->radius( $given['radius'], (float) $settings['default_radius'] ),
				'limit'            => $this->bounded_int( $given['limit'], 1, Rest_Controller::MAX_LIMIT, (int) $settings['default_limit'] ),
				'units'            => $this->units( $given['units'], (string) $settings['units'] ),
				'category'         => $category['name'],
				'category_missing' => $category['missing'],
				'search'           => $this->text( $given['search'], self::MAX_SEARCH ),
				'label'            => $this->text( $given['label'], self::MAX_SEARCH ),
				'near_me'          => $this->boolean( $given['near_me'], (bool) $settings['near_me'] ),
				// auto_locate has no setting, and that is the closed list doing
				// its job rather than an omission: asking a visitor's browser
				// for their position before they have asked for anything is a
				// decision about one page, and a site-wide switch for it would
				// be a permission prompt on every locator at once.
				'auto_locate'      => $this->boolean( $given['auto_locate'], false ),
				// 'auto' here means "nobody wrote an attribute", so the site
				// setting gets the next word — and only if that is 'auto' too
				// does the count decide. clusters() has the rest.
				'cluster'          => $this->cluster( $given['cluster'], (string) $settings['cluster'] ),
				// The site setting is the fallback and needs no second
				// cleaning: Settings::all() sanitises everything it answers
				// with, including a value a direct database edit wrote.
				'button_class'     => $this->classes( $given['button_class'], (string) $settings['button_class'] ),
			);
		}

		/**
		 * What the browser is told about this locator.
		 *
		 * Takes the output of attributes() and adds the two things only the
		 * server knows: how many locations there are, and therefore how the
		 * front end should go about getting them.
		 *
		 * @param array $attributes Output of attributes().
		 * @return array
		 */
		public function config( array $attributes ): array {
			$count     = $this->published_count();
			$threshold = $this->preload_threshold();
			$settings  = Settings::all();

			return array(
				'routes'       => $this->routes(),
				'mode'         => $count <= $threshold ? 'preload' : 'query',
				'count'        => $count,
				'threshold'    => $threshold,
				'height'       => $attributes['height'],
				'zoom'         => $attributes['zoom'],
				'lat'          => $attributes['lat'],
				'lng'          => $attributes['lng'],
				'units'        => $attributes['units'],
				'radius'       => $attributes['radius'],
				'limit'        => $attributes['limit'],
				'category'     => $attributes['category'],
				'search'       => $attributes['search'],
				'nearMe'       => $attributes['near_me'],
				'autoLocate'   => $attributes['auto_locate'],
				'cluster'      => $this->clusters( $attributes['cluster'], $count, $attributes['limit'] ),

				/*
				 * The seven site-wide keys, added by Task 21. Every one of them
				 * is a fact about the whole site rather than about this locator,
				 * so none of them has a shortcode attribute — which is the line
				 * this class draws between the two: an attribute is for the
				 * thing that differs between two locators on one page, and a
				 * setting is for the thing that does not.
				 *
				 * The tile layer is the one that replaces something rather than
				 * adding it. It was a frozen constant in assets/js/locator.js,
				 * and it is a setting now; Settings::tile_config() is the single
				 * place it is assembled, so the front end and the admin picker
				 * are handed the same object.
				 */
				'tile'         => Settings::tile_config( $settings ),
				'marker'       => $this->marker( $settings ),
				'position'     => (string) $settings['results_position'],
				'fields'       => (array) $settings['result_fields'],
				'popup'        => (array) $settings['popup_fields'],
				'directions'   => (string) $settings['directions'],

				/*
				 * The country restriction is deliberately absent. The front end
				 * would only pass it straight back to /geocode and /suggest, and
				 * Rest_Controller applies the setting itself when a request
				 * names none — so putting it here would be a second copy of the
				 * rule, in the one place a visitor could edit it out.
				 */
				'autocomplete' => (bool) $settings['autocomplete'],
			);
		}

		/**
		 * How the pins are drawn.
		 *
		 * A method of its own rather than two lines inside config(), for a
		 * reason that is about the suite rather than about the code:
		 * tests/js/harness.test.js reads config()'s body and asserts the keys it
		 * returns against the fixture's, by scanning for `'name' =>` lines — so
		 * a nested array literal in there puts its own keys into that list and
		 * the cross-check starts comparing the wrong two things. A call keeps
		 * the body one key per line. Settings::tile_config() is outside this
		 * class for the same reason among others.
		 *
		 * The colour travels even when the style is the standard pin, which
		 * cannot use it. Leaving it out would mean the front end could not tell
		 * "no colour chosen" from "pin chosen", and the setting is there either
		 * way; dotIcon() in assets/js/locator.js reads one and ignores the
		 * other.
		 *
		 * @param array $settings Output of Settings::all().
		 * @return array{style: string, colour: string}
		 */
		private function marker( array $settings ): array {
			return array(
				'style'  => (string) $settings['marker_style'],
				'colour' => (string) $settings['marker_colour'],
			);
		}

		/**
		 * Each route's url, built by WordPress rather than assembled by hand.
		 *
		 * Four finished urls and not one base to concatenate onto, because
		 * rest_url() has two shapes and only one of them can be appended to.
		 * With a permalink structure set it is
		 * https://site/wp-json/slosm/v1/stores; with plain permalinks
		 * get_rest_url() takes its other branch — verified in WordPress 6.9.1 —
		 * and returns https://site/index.php?rest_route=/slosm/v1/stores. A
		 * front end handed the namespace root and told to append '/stores?limit=500'
		 * produces a working url in the first case and a url with two question
		 * marks in the second, on every site that has never visited Settings >
		 * Permalinks. Building each route here means the front end only ever
		 * adds query *parameters*, which URLSearchParams appends correctly to
		 * either shape.
		 *
		 * The one route with a path segment carries __ID__ rather than %d, and
		 * that is a decision about percent signs rather than taste. Following
		 * add_query_arg() into build_query() in 6.9.1: build_query() calls
		 * _http_build_query() with $urlencode false, and add_query_arg()
		 * urlencode_deep()s only the query string it parsed out of the url
		 * before overwriting it with the new argument — so the route value is
		 * placed verbatim, percent signs included. The pretty branch is plain
		 * string concatenation and does the same. A %d therefore survives into
		 * the url as a literal, malformed percent escape, which
		 * decodeURIComponent() answers with a URIError and every url normaliser
		 * treats differently. __ID__ has no percent sign, is unreserved in a
		 * url, and survives both branches unchanged.
		 *
		 * @return array<string, string>
		 */
		private function routes(): array {
			$namespace = Rest_Controller::REST_NAMESPACE;

			return array(
				'stores'  => rest_url( $namespace . '/stores' ),
				'store'   => rest_url( $namespace . '/stores/__ID__' ),
				'geocode' => rest_url( $namespace . '/geocode' ),
				'suggest' => rest_url( $namespace . '/suggest' ),
			);
		}

		/**
		 * How many published locations this site has.
		 *
		 * wp_count_posts() rather than a query of this plugin's own, and the
		 * reason is not that the answer is already lying about. An earlier
		 * version of this docblock said WordPress had "already done this one and
		 * cached it in the 'counts' group", and that is wrong: wp-includes/load.php
		 * registers 'counts' through wp_cache_add_non_persistent_groups(), so a
		 * persistent object cache does not keep it, and nothing else on a
		 * front-end request populates it. Every page carrying a locator runs the
		 * grouped count once.
		 *
		 * The choice is still right, for the reason that survives checking: it
		 * is one COUNT(*) grouped by post_status over an indexed post_type, core
		 * invalidates it on every status transition (wp_transition_post_status()
		 * deletes both count keys), and the same request reuses it in memory. A
		 * bespoke "count the published ones" query would be the same work
		 * without the invalidation, which this plugin would then have to own.
		 *
		 * Three things about what it hands back, all verified against WordPress
		 * 6.9.1 rather than assumed, and all of which this method has to survive:
		 *
		 * - It is an object, and for a post type that is not registered it is an
		 *   *empty* stdClass with no publish property at all. Reading ->publish
		 *   off that is an "Undefined property" warning in PHP 8, printed on a
		 *   front-end page. Hence isset() rather than a bare read.
		 * - The counts come from $wpdb->get_results( ..., ARRAY_A ), so every
		 *   status that has rows arrives as a numeric *string*; only the
		 *   statuses zero-filled from get_post_stati() are integers. Hence the
		 *   cast.
		 * - 'publish' is the right property because that is the status
		 *   Store_Repository's loader queries. Drafts, pending, private and
		 *   trashed locations are not on the map and must not move the
		 *   threshold.
		 *
		 * It is an upper bound on what /stores will actually return, not an
		 * exact figure: the payload also drops any published location with no
		 * usable coordinates. That errs in the safe direction — an over-count
		 * can only send a site to query mode earlier than strictly necessary,
		 * never the other way, and query mode is correct at any size.
		 *
		 * @return int
		 */
		public function published_count(): int {
			$counts = wp_count_posts( Post_Type::POST_TYPE );

			if ( ! is_object( $counts ) || ! isset( $counts->publish ) ) {
				return 0;
			}

			return is_numeric( $counts->publish ) ? max( 0, (int) $counts->publish ) : 0;
		}

		/**
		 * The location count at or below which the whole list is preloaded.
		 *
		 * Why this is a filter and not a setting: the two modes are a transfer
		 * trade, not a preference. Below the line, one cacheable request buys
		 * every subsequent search for free; above it, the transfer stops paying
		 * for itself. A site owner asked to choose has no way to know which side
		 * they are on — the answer depends on the size of their payload and the
		 * number of searches per visit, neither of which is on the screen — so a
		 * setting would be a question that produces a wrong answer and then
		 * blames the person who gave it. PHP knows the count at render time and
		 * can simply decide. The filter exists for the site that has measured
		 * its own traffic and wants to move the line; that is a different act
		 * from guessing, and it belongs in code.
		 *
		 * The filtered value is capped at Rest_Controller::MAX_LIMIT, which is
		 * the constant's own docblock. A threshold of 900 would put a 900-branch
		 * site into preload mode, and /stores would answer with 500 locations
		 * and no indication that 400 were missing.
		 *
		 * @return int
		 */
		public function preload_threshold(): int {
			/**
			 * Filters how many locations may be preloaded in one request.
			 *
			 * At or below this count the front end fetches the whole lean list
			 * from /stores once and does all its searching in the browser; above
			 * it, every search is a request. Values above
			 * Rest_Controller::MAX_LIMIT are capped to it, since that is the
			 * most /stores will return.
			 *
			 * @param int $threshold Locations that may be preloaded.
			 */
			$threshold = apply_filters( 'slosm_preload_threshold', self::PRELOAD_THRESHOLD );

			if ( ! is_numeric( $threshold ) ) {
				$threshold = self::PRELOAD_THRESHOLD;
			}

			return (int) max( 0, min( Rest_Controller::MAX_LIMIT, (int) $threshold ) );
		}

		/**
		 * The clustering attribute: 'yes', 'no', or 'auto' to let the count say.
		 *
		 * Three states rather than a boolean, because "off" and "decide for me"
		 * are different answers and a boolean has nowhere to put the second.
		 * cluster="no" on a nine-hundred-branch site is a decision somebody made
		 * and this class has no business overriding it with a count; no
		 * attribute at all is nobody having decided anything, which is what
		 * CLUSTER_THRESHOLD is for.
		 *
		 * Anything unrecognised is 'auto' rather than 'no', the same rule
		 * boolean() applies and for the same reason: "maybe" is a typo, and
		 * silently turning a feature off is a worse answer to a typo than
		 * leaving the decision where it was.
		 *
		 * A REAL BOOLEAN, WHICH THIS TREATS DIFFERENTLY FROM boolean()
		 * ------------------------------------------------------------
		 * boolean() honours one — `is_bool( $value )` returns it as given —
		 * and this refuses one, answering 'auto'. That is deliberate, and the
		 * difference is that boolean() answers a two-state question while this
		 * answers a three-state one. A caller handing this `false` has said
		 * something ambiguous: PHP's false is what an unchecked checkbox, an
		 * empty field and "let the site decide" all arrive as, and reading it
		 * as cluster="no" would turn clustering off on a nine-hundred-branch
		 * site because a control somewhere defaulted to unchecked.
		 *
		 * This matters for a caller that does not exist yet. A shortcode cannot
		 * produce a real boolean — shortcode_parse_atts() yields strings — so
		 * the only way one arrives is a direct call, and Task 23's Bricks
		 * element is the direct caller this plugin is going to have. A three-way
		 * control there passes 'yes', 'no' or 'auto' as text and is read exactly
		 * as written; a two-way one passes true or false and gets 'auto' for
		 * false, which is the honest reading of "this control cannot express the
		 * third state".
		 *
		 * WHAT 'auto' MEANS SINCE TASK 21, WHICH IS ONE WORD MORE THAN BEFORE
		 * -------------------------------------------------------------------
		 * The default is taken as given, for the reason radius() lists: it comes
		 * from Settings::all(), which has already narrowed it to one of the three.
		 * It used to mean "let the count decide". It now means "nobody wrote an
		 * attribute", and the next word goes to the site's cluster setting —
		 * which is itself one of these three, so a site that left it on automatic
		 * ends up exactly where this method used to: at the count. The three
		 * states are the same three at both levels on purpose; a fourth here
		 * would be a default no shortcode could express.
		 *
		 * @param mixed  $value   Raw attribute.
		 * @param string $default What no attribute at all means; the site's setting.
		 * @return string 'yes', 'no' or 'auto'.
		 */
		private function cluster( $value, string $default = 'auto' ): string {
			if ( is_bool( $value ) || ! is_scalar( $value ) ) {
				return $default;
			}

			$value = strtolower( trim( (string) $value ) );

			if ( in_array( $value, array( 'yes', 'true', '1', 'on' ), true ) ) {
				return 'yes';
			}

			if ( in_array( $value, array( 'no', 'false', '0', 'off' ), true ) ) {
				return 'no';
			}

			return $default;
		}

		/**
		 * Whether this locator clusters, which is what the browser is told.
		 *
		 * A boolean, never the word. The front end compares config.cluster with
		 * true, and a string 'yes' arriving there would be truthy by accident —
		 * while 'yes' is exactly what the *attribute* legitimately carries. The
		 * two are different shapes at this boundary on purpose.
		 *
		 * WHAT IS COUNTED, AND WHY IT IS NOT JUST THE SITE'S TOTAL
		 * --------------------------------------------------------
		 * The smaller of the site's published count and this locator's display
		 * limit, because what clusters is the number of pins on the map and the
		 * limit is a hard ceiling on that: the front end renders at most `limit`
		 * rows and at most `limit` pins in preload mode, and /stores returns at
		 * most `limit` items in query mode.
		 *
		 * Without the min, [store_locator limit="10"] on a nine-hundred-branch
		 * site downloads 34 KB of library to cluster ten pins — which is the
		 * exact cost CLUSTER_THRESHOLD's twenty-branch floor exists to avoid,
		 * arriving through the other door. The default limit is
		 * Rest_Controller::MAX_LIMIT, which is well above the threshold, so a
		 * shortcode that names no limit is unaffected.
		 *
		 * @param string $choice Output of cluster().
		 * @param int    $count  Published locations on this site.
		 * @param int    $limit  Most rows and pins this locator will show.
		 * @return bool
		 */
		private function clusters( string $choice, int $count, int $limit ): bool {
			if ( 'yes' === $choice ) {
				return true;
			}

			if ( 'no' === $choice ) {
				return false;
			}

			return min( $count, $limit ) >= self::CLUSTER_THRESHOLD;
		}

		/**
		 * Renders one locator.
		 *
		 * Returns its markup and prints nothing, which is not a style point: a
		 * shortcode callback that echoes writes its output during do_shortcode()
		 * — before the_content() has returned anything — so the locator appears
		 * above the post rather than where the shortcode was.
		 *
		 * The signature takes all three arguments WordPress passes even though
		 * two are unused, because a callback declaring fewer would be handed
		 * them anyway and PHP would not mind, while a reader would have to go
		 * and check.
		 *
		 * @param mixed  $atts    Attributes; '' when the shortcode had none.
		 * @param mixed  $content Enclosed content; this shortcode is not enclosing.
		 * @param string $tag     The tag that matched.
		 * @return string
		 */
		public function render( $atts = array(), $content = null, $tag = self::TAG ): string {
			/*
			 * This line is the whole of the conditional loading: nothing on the
			 * site enqueues Leaflet, the stylesheet or the script until a
			 * locator is actually being rendered into a page, and this is the
			 * only place that knows one is. Assets::enqueue() is idempotent
			 * across the request, so two locators cost what one does.
			 *
			 * First rather than last, because it must not be skippable. There is
			 * no early return below today, and a future one added above an
			 * enqueue at the bottom would be a locator whose markup is on the
			 * page and whose script is not — which looks like a broken map with
			 * nothing in the html to say why.
			 *
			 * It runs for content that is rendered and never shown, too: a REST
			 * request asking for content.rendered, and a feed — the_content is
			 * filtered there as well, so this whole block of markup, data-
			 * attribute and all, goes into the RSS item. No asset ships with it,
			 * so Task 12's contract holds, but the markup in a feed reader is a
			 * Task 11 decision nobody has revisited. Those requests print no
			 * footer, so the enqueue costs an array entry and emits nothing.
			 * Excerpts are not among them — wp_trim_excerpt() calls
			 * strip_shortcodes() before it filters the_content
			 * (wp-includes/formatting.php line 3974 of 6.9.1), so an archive of
			 * excerpts never reaches this method at all.
			 */
			$in_time = $this->assets->enqueue();

			$attributes = $this->attributes( $atts );

			/*
			 * The config is built once and passed down, rather than rebuilt
			 * inside encoded_config(). It is read twice now — once for the
			 * attribute and once for the clustering decision below.
			 *
			 * What that saves is small and worth stating accurately rather than
			 * overstating, because published_count()'s own docblock 250 lines up
			 * already corrects the overstatement: wp_count_posts() caches in the
			 * 'counts' group, which load.php registers as non-persistent, so an
			 * object cache does not keep it *between* requests — but it is
			 * reused within one. A second config() call here would have cost a
			 * wp_cache_get() hit and a second pass through the filter on
			 * slosm_preload_threshold, not a second grouped COUNT(*).
			 *
			 * It is built once anyway, for the reason that does not depend on
			 * the cost: two calls are two places the same decision is made, and
			 * a filter on slosm_preload_threshold that answered differently the
			 * second time would put a config in the attribute that disagrees
			 * with the assets this method enqueued.
			 */
			$config = $this->config( $attributes );

			/*
			 * The second half of the conditional loading, and the only one that
			 * is conditional on anything but a locator existing. A site below
			 * CLUSTER_THRESHOLD never downloads Leaflet.markercluster; a site
			 * above it does, on the pages that have a locator and on no others.
			 *
			 * It cannot go where the enqueue above is, because what it needs to
			 * know does not exist yet. That is also why the enqueue above stays
			 * the first line: it is the one that must never be skippable, and it
			 * still is not.
			 */
			if ( $config['cluster'] ) {
				$this->assets->enqueue_cluster();
			}

			/*
			 * The list's position is a class rather than anything in the config,
			 * because it is a layout and the stylesheet is what lays things out.
			 * The front end never reads it; assets/css/locator.css has the two
			 * side-by-side rules and the media query that gives up on them at
			 * phone width, where there is no room for a column beside a map.
			 *
			 * esc_attr() on a value out of a closed list, which is belt and
			 * braces today and is here for the reason picker_config()'s docblock
			 * gives: "there is nothing dangerous in here today" is an argument
			 * that disappears the moment somebody widens the list.
			 */
			$position = 'right' === $config['position'] ? '' : ' slosm--list-' . $config['position'];

			$html  = '<div class="slosm' . esc_attr( $position ) . '" data-slosm="' . esc_attr( $this->encoded_config( $config ) ) . '">';
			$html .= $this->late_render_comment( $in_time );
			$html .= $this->missing_category_comment( $attributes['category_missing'] );
			$html .= $this->filters( $attributes );

			/*
			 * THE STATUS LINE, AND WHY IT IS SERVER-RENDERED AND EMPTY
			 * =======================================================
			 * Every sentence this locator says — "Searching…", "No results",
			 * the reason a lookup failed — is written here by say(), and this
			 * is the only live region on a locator. The results list used to be
			 * one, which meant replacing it with seven results announced seven
			 * names, addresses, cities and distances in a row. The sentence is
			 * what somebody needs; the rows are what they will then read at
			 * their own pace by moving through the list.
			 *
			 * Rendered by the server rather than created by the script, and
			 * that is the fix for something assets/js/locator.js had recorded
			 * as Task 24's problem: a live region only announces changes it
			 * *observes*, so a region created and filled in the same tick is a
			 * region that was not being watched when it changed. The
			 * config-error message is written while the script is still
			 * running, and it was most likely never announced at all. An empty
			 * element in the markup has been watched since the page parsed.
			 *
			 * role="status" and aria-live together: the role carries polite and
			 * atomic on its own, and the attribute is what older assistive
			 * technology reads. Admin\Shortcode_Generator writes the same pair
			 * on its own status line, for the same reason.
			 *
			 * Above the map rather than below it. A person who has just
			 * searched should not have to scroll past a map to be told that
			 * nothing matched, and on a wide screen the results are in the
			 * other column entirely. It spans both grid columns and is hidden
			 * while empty — assets/css/locator.css, where `:empty` is in the
			 * layer a site cannot switch off, because an empty grid item still
			 * holds a row and the grid's gap open.
			 *
			 * No whitespace inside it, for that same `:empty`.
			 */
			$html .= '<p class="slosm__message" role="status" aria-live="polite"></p>';

			// role="region" with a label rather than role="application": the
			// latter tells a screen reader to stop interpreting keystrokes and
			// hand them all to the page, which is a promise this markup cannot
			// keep until Task 13 has written the key handling.
			//
			// No tabindex either, and Task 13 has now verified the claim that
			// was recorded here as UNVERIFIED. Leaflet does put one on the
			// container it is handed: A.mergeOptions({keyboard:!0,...}) makes
			// the keyboard handler a default, and its addHooks() runs
			// `t.tabIndex<=0&&(t.tabIndex="0")` against this._map._container.
			// Both read in assets/leaflet/leaflet.js, the vendored 1.9.4.
			// tests/js/harness.test.js asserts the second of those strings is
			// still in the file, so a future Leaflet that drops it fails there
			// rather than leaving the map silently keyboard-unreachable.
			//
			// Markers get the same treatment — the Marker defaults include
			// keyboard:!0, and _initIcon does `t.keyboard&&(i.tabIndex="0",
			// i.setAttribute("role","button"))` — which is why Task 13 does not
			// put a tabindex on them either.
			$html .= '<div class="slosm__map" style="height:' . (int) $attributes['height'] . 'px" role="region" aria-label="'
				. esc_attr(
					'' === $attributes['label']
						? __( 'Map of locations', 'store-locator-for-openstreetmap' )
						/* translators: %s: the name this locator was given with the label attribute. */
						: sprintf( __( '%s: map of locations', 'store-locator-for-openstreetmap' ), $attributes['label'] )
				) . '"></div>';
			// No aria-live. The status line above carries every sentence; a
			// list that announced itself would read seven results out loud on
			// every draw, which is the defect Task 24c closed.
			$html .= '<ol class="slosm__results"></ol>';
			$html .= $this->row_template();
			$html .= '</div>';

			return $html;
		}

		/**
		 * The config as it will sit inside the attribute.
		 *
		 * wp_json_encode() can return false — invalid utf-8 in a term name is
		 * the realistic way — and printing false into an attribute gives
		 * data-slosm="" plus a front end that cannot tell an empty config from a
		 * missing one. An empty object is the honest answer: the script finds no
		 * settings, and the rest of the markup is still there to be looked at.
		 *
		 * @param array $config Output of config().
		 * @return string
		 */
		private function encoded_config( array $config ): string {
			$json = wp_json_encode( $config, self::JSON_FLAGS );

			return is_string( $json ) && '' !== $json ? $json : '{}';
		}

		/**
		 * Says, in the markup, that the assets could not reach this page.
		 *
		 * The same reasoning as the missing-category comment below, applied to a
		 * different silent failure. A locator rendered after the footer scripts
		 * have been printed produces a blank rectangle and nothing else: no
		 * error, no console message, no clue. Assets::enqueue() already reports
		 * it through _doing_it_wrong(), which reaches a developer who has
		 * WP_DEBUG on and an error log they read; this reaches whoever is asked
		 * to look at the page, with view-source and nothing else.
		 *
		 * @param bool $in_time Whether the assets can still reach the page.
		 * @return string
		 */
		private function late_render_comment( bool $in_time ): string {
			if ( $in_time ) {
				return '';
			}

			return '<!-- ' . esc_html(
				sprintf(
					/* translators: %s: the wp_footer template tag. */
					__(
						'Store Locator: this locator was rendered after %s had already printed the page scripts, so its map cannot load. It has to be rendered earlier in the template.',
						'store-locator-for-openstreetmap'
					),
					'wp_footer()'
				)
			) . ' -->';
		}

		/**
		 * Says, in the markup, that a category filter was asked for and missed.
		 *
		 * The alternative is what makes this worth the code: a client sets
		 * category="bakeries", renames the category, and the map goes on looking
		 * perfect while quietly showing every location on the site. Nobody
		 * reports that as a bug, because nothing looks wrong. A comment in the
		 * markup is visible to whoever is asked to look and invisible to
		 * everybody else.
		 *
		 * An html comment ends at the first literal `-->` or `--!>`, and nothing
		 * inside one is entity-decoded — so what keeps a category named
		 * `--><script>` from escaping is esc_html() turning every `>` into
		 * `&gt;`, not anything about the dashes. The whole sentence is escaped,
		 * translation included, rather than only the value: a translator's text
		 * is not hostile, but it is not this file's to vouch for either.
		 *
		 * @param string $given The category as typed, or '' when nothing is wrong.
		 * @return string
		 */
		private function missing_category_comment( string $given ): string {
			if ( '' === $given ) {
				return '';
			}

			return '<!-- ' . esc_html(
				sprintf(
					/* translators: %s: category slug, name or id as typed in the shortcode. */
					__(
						'Store Locator: no category matched %s, so this map is showing every location. Check the category slug, name or id in the shortcode.',
						'store-locator-for-openstreetmap'
					),
					$given
				)
			) . ' -->';
		}

		/**
		 * The controls: search, submit, radius, result count, category, near me.
		 *
		 * The order is the order they are read in, and the submit button sits
		 * where it does for that reason: immediately after the field it runs,
		 * before the three filters that narrow what it finds.
		 *
		 * Every control is wrapped in its own label rather than paired with one
		 * by id. Ids would have to be unique across the page, which means a
		 * counter, which means two locators rendered by two different objects
		 * can still collide — and the whole point of the data- attribute is that
		 * instances need no coordination. A label that contains its control
		 * needs no id at all.
		 *
		 * @param array $attributes Output of attributes().
		 * @return string
		 */
		private function filters( array $attributes ): string {
			/*
			 * Both landmarks carry a name, and the label attribute is what makes
			 * two locators on one page tell themselves apart for somebody
			 * navigating by landmark. The data- attribute buys instances
			 * independence from each other's JavaScript; it does nothing for a
			 * screen reader reading out "search, search, region, region". Task
			 * 24 owns the full accessibility pass, but the markup decision is
			 * being made here, so the hook for it is here.
			 */
			$html = '<div class="slosm__filters" role="search" aria-label="'
				. esc_attr(
					'' === $attributes['label']
						? __( 'Find a location', 'store-locator-for-openstreetmap' )
						/* translators: %s: the name this locator was given with the label attribute. */
						: sprintf( __( '%s: find a location', 'store-locator-for-openstreetmap' ), $attributes['label'] )
				) . '">';

			$html .= '<label class="slosm__field slosm__field--search">'
				. '<span class="slosm__field-label">'
				. esc_html( __( 'Address, postcode or city', 'store-locator-for-openstreetmap' ) )
				. '</span>'
				. '<input type="search" class="slosm__search" value="' . esc_attr( $attributes['search'] ) . '"'
				. ' maxlength="' . (int) self::MAX_SEARCH . '" enterkeyhint="search"'
				. ' autocomplete="off" autocapitalize="off" spellcheck="false" />'
				. '</label>';

			/*
			 * The submit control, and it is a sibling of the label rather than
			 * inside it. A click anywhere in a <label> is a click on the label,
			 * which moves the focus to the control the label wraps — the same
			 * fact wireSearch() puts the suggestion popup beside the label for
			 * — so a button inside this one would fight the press that ran it.
			 *
			 * type="button", not type="submit", and there is no <form> here at
			 * all. Both halves of that are deliberate: a <form> would turn on
			 * implicit submission and reload the page for anybody whose
			 * JavaScript has not arrived, and a page builder that renders a
			 * locator inside its own form leaves the parser dropping this one
			 * and adopting these controls — at which point a submit button
			 * submits somebody else's form. The button runs the same code path
			 * Enter runs, which needs no submission machinery.
			 *
			 * Not gated on anything. Every other control here is either always
			 * emitted or answers an attribute that already existed; this one
			 * gets no attribute of its own, because a search whose only submit
			 * control can be switched off is the gap this task is closing.
			 *
			 * _x() rather than __(), because 'Search' is already a msgid in
			 * this plugin: admin/class-settings-screen.php uses it for the
			 * settings tab that holds the search options. That is a noun and
			 * this is an imperative, and one msgid would force a translator to
			 * pick one word for both — "Wyszukiwanie" over a button that ought
			 * to say "Szukaj". The context makes them two entries.
			 */
			/*
			 * The class a site can add to both buttons, and the space before it
			 * is inside the conditional on purpose: the default is empty, and
			 * `class="slosm__submit "` would be a default that changed the
			 * markup of every locator already out there.
			 *
			 * Both buttons get the same value from one field. A row where
			 * Search inherits the theme's buttons and "Use my location" stays a
			 * raw browser widget beside it is worse than either state applied
			 * to both, and a site that wants them apart has `.slosm__locate` to
			 * aim at. `.slosm__result-open` deliberately gets nothing: it is a
			 * button that has to read as a row of text, which is the one rule
			 * the layout layer keeps for it.
			 *
			 * esc_attr() over a value Settings::class_list() has already
			 * narrowed to `[A-Za-z0-9_- ]`. That is belt and braces and stays:
			 * this method escapes everything it writes, and a reader checking
			 * whether an attribute is safe should not have to go and read
			 * another class to find out.
			 */
			$button_class = '' === $attributes['button_class'] ? '' : ' ' . $attributes['button_class'];

			$html .= '<button type="button" class="' . esc_attr( 'slosm__submit' . $button_class ) . '">'
				. esc_html(
					_x(
						'Search',
						'submit button beside the locator address field',
						'store-locator-for-openstreetmap'
					)
				)
				. '</button>';

			/*
			 * Both labels are _x(), and for the reason the button above is:
			 * neither is a sentence a translator can place from the msgid
			 * alone. 'Within' is a bare preposition — Polish has no standalone
			 * word for it here and needs "W promieniu", which a translator
			 * cannot arrive at without being told the select holds a radius.
			 * 'Show at most' is a fragment whose object is the number in the
			 * select beside it, and a translator guessing at what is being
			 * counted can put the wrong case on it.
			 *
			 * 'Category' below stays __(): it is a whole noun and the select
			 * under it is the only thing it can mean.
			 */
			$html .= $this->field(
				'radius',
				_x( 'Within', 'label on the search-radius select', 'store-locator-for-openstreetmap' ),
				$this->radius_options( $attributes )
			);

			$html .= $this->field(
				'limit',
				_x( 'Show at most', 'label on the result-count select', 'store-locator-for-openstreetmap' ),
				$this->limit_options( $attributes )
			);

			$html .= $this->field(
				'category',
				__( 'Category', 'store-locator-for-openstreetmap' ),
				$this->category_options( $attributes )
			);

			if ( $attributes['near_me'] ) {
				$html .= '<button type="button" class="' . esc_attr( 'slosm__locate' . $button_class ) . '">'
					. esc_html( __( 'Use my location', 'store-locator-for-openstreetmap' ) )
					. '</button>';
			}

			return $html . '</div>';
		}

		/**
		 * One labelled select.
		 *
		 * @param string $name    Short name; becomes slosm__{name} and the modifier class.
		 * @param string $label   Label text, unescaped.
		 * @param array  $options Rows of value, label and selected.
		 * @return string
		 */
		private function field( string $name, string $label, array $options ): string {
			// $name is a literal at all three call sites today, so escaping it
			// fixes nothing that is broken. It is here because the next call
			// site is one edit away from being the one that passes something
			// else, and a class attribute built by concatenation is not a place
			// to rely on every future caller having read this method.
			$name = esc_attr( $name );

			$html = '<label class="slosm__field slosm__field--' . $name . '">'
				. '<span class="slosm__field-label">' . esc_html( $label ) . '</span>'
				. '<select class="slosm__' . $name . '">';

			foreach ( $options as $option ) {
				$html .= '<option value="' . esc_attr( $option['value'] ) . '"'
					. ( $option['selected'] ? ' selected' : '' ) . '>'
					. esc_html( $option['label'] ) . '</option>';
			}

			return $html . '</select></label>';
		}

		/**
		 * The radius choices, with the configured one selected.
		 *
		 * A radius the shortcode named that is not one of the offered steps is
		 * added rather than ignored, so radius="7" does not silently become 10.
		 *
		 * @param array $attributes Output of attributes().
		 * @return array
		 */
		private function radius_options( array $attributes ): array {
			$choices = array();

			// Taken as given, sorted and positive and clamped to
			// Rest_Controller::MAX_RADIUS, because Settings::get() answers with
			// Settings::all(), which sanitises everything it hands back —
			// including a list a direct database edit wrote. A comment here once
			// claimed the opposite and justified a second bound on it; the claim
			// was false and the bound was a branch no input could take.
			foreach ( (array) Settings::get( 'radius_choices' ) as $choice ) {
				$choices[] = (float) $choice;
			}

			$choices = $this->with_current( $choices, $attributes['radius'] );
			$options = array();

			foreach ( $choices as $choice ) {
				$number = $this->number_label( $choice );

				$options[] = array(
					'value'    => $number,
					'label'    => 'mi' === $attributes['units']
						/* translators: %s: a distance. */
						? sprintf( __( '%s mi', 'store-locator-for-openstreetmap' ), $number )
						/* translators: %s: a distance. */
						: sprintf( __( '%s km', 'store-locator-for-openstreetmap' ), $number ),
					'selected' => abs( $choice - $attributes['radius'] ) < 0.000001,
				);
			}

			return $options;
		}

		/**
		 * The result-count choices, with the configured one selected.
		 *
		 * @param array $attributes Output of attributes().
		 * @return array
		 */
		private function limit_options( array $attributes ): array {
			$choices = array();

			foreach ( (array) Settings::get( 'limit_choices' ) as $choice ) {
				$choices[] = (float) $choice;
			}

			$choices = $this->with_current( $choices, (float) $attributes['limit'] );
			$options = array();

			foreach ( $choices as $choice ) {
				$count = (int) $choice;

				$options[] = array(
					'value'    => (string) $count,
					/* translators: %s: a number of results. */
					'label'    => sprintf( _n( '%s result', '%s results', $count, 'store-locator-for-openstreetmap' ), (string) $count ),
					'selected' => $count === $attributes['limit'],
				);
			}

			return $options;
		}

		/**
		 * The category choices.
		 *
		 * Only two entries, and that is the design rather than a stub. The full
		 * list belongs to the front end: in preload mode every category name is
		 * already in the lean payload the browser has, and in query mode the
		 * list changes with the results. Rendering the taxonomy here would put a
		 * second storage query in a class that has no business making one, and
		 * would inline into an uncacheable page a list that /stores already
		 * carries in a cacheable one. Task 15 filled the select in, in the
		 * browser, from the payload — and kept this first option rather than
		 * rebuilding it, so its translated label is paid for once here and
		 * never again in a JavaScript string table.
		 *
		 * The pinned category is rendered because it is a decision the page made
		 * rather than data the browser is about to fetch: with JavaScript still
		 * loading, the control already says what this map is filtered to. The
		 * front end then disables the select rather than filling it, because a
		 * control still offering "All categories" on a locator whose category
		 * the site owner pinned would silently overrule what they wrote.
		 *
		 * @param array $attributes Output of attributes().
		 * @return array
		 */
		private function category_options( array $attributes ): array {
			$options = array(
				array(
					'value'    => '',
					'label'    => __( 'All categories', 'store-locator-for-openstreetmap' ),
					'selected' => '' === $attributes['category'],
				),
			);

			if ( '' !== $attributes['category'] ) {
				$options[] = array(
					'value'    => $attributes['category'],
					'label'    => $attributes['category'],
					'selected' => true,
				);
			}

			return $options;
		}

		/**
		 * A choice list with the current value in it, in order, once.
		 *
		 * @param float[] $choices Offered values.
		 * @param float   $current The configured value.
		 * @return float[]
		 */
		private function with_current( array $choices, float $current ): array {
			foreach ( $choices as $choice ) {
				if ( abs( $choice - $current ) < 0.000001 ) {
					return $choices;
				}
			}

			$choices[] = $current;

			sort( $choices );

			return $choices;
		}

		/**
		 * A number as a person would write it: no trailing zeros, no exponent.
		 *
		 * PHP 8 casts floats to strings independently of LC_NUMERIC, so this is
		 * '12.5' on a site that has called setlocale( LC_NUMERIC, 'pl_PL' ) as
		 * well as on one that has not. Before the 8.0 floor this plugin declares
		 * it would have been '12,5' on the first, in a select's value attribute.
		 *
		 * @param float $value Value.
		 * @return string
		 */
		private function number_label( float $value ): string {
			return (string) ( floor( $value ) === $value ? (int) $value : round( $value, 3 ) );
		}

		/**
		 * The empty row the JavaScript clones once per result.
		 *
		 * Not a <script type="text/template"> and not a string in the
		 * JavaScript. A <template> is parsed by the html parser and left inert,
		 * so the markup is validated once at page load and cloning it is a
		 * native call rather than a parse per row; a template in a script tag is
		 * a string that has to be parsed with innerHTML for every result, which
		 * is the habit that turns one unescaped field into stored xss.
		 *
		 * Every element in it is empty on purpose: nothing derived from a
		 * location is rendered by PHP anywhere in this file, so there is no path
		 * from a location's title to this markup at all. Task 14 fills these in
		 * with textContent.
		 *
		 * @return string
		 */
		private function row_template(): string {
			return '<template class="slosm__row">'
				. '<li class="slosm__result">'
				. '<button type="button" class="slosm__result-open">'
				. '<span class="slosm__result-name"></span>'
				. '<span class="slosm__result-address"></span>'
				. '<span class="slosm__result-city"></span>'
				. '<span class="slosm__result-distance"></span>'
				. '</button>'
				. '<ul class="slosm__result-categories"></ul>'
				// No href at all rather than an empty one. href="" resolves to
				// the current page, so a template cloned before its link is
				// filled in would reload the page and lose the search.
				. '<a class="slosm__result-directions" rel="noopener noreferrer" target="_blank">'
				// _x(), and with the same context string Assets::strings()
				// passes, which is what keeps the two call sites one msgid.
				// gettext keys on context *and* text, so two different contexts
				// here would split a word that is deliberately shared into two
				// .po entries the row and the popup could disagree about.
				//
				// The context exists because a bare 'Directions' is a noun
				// with three readings and only one of them is this one: a route
				// to somewhere ("Dojazd"), instructions ("Wskazówki") and
				// compass bearings ("Kierunki") are all "directions" in
				// English and all different words in Polish.
				. esc_html( _x( 'Directions', 'link to route directions for one location', 'store-locator-for-openstreetmap' ) )
				. '</a>'
				. '</li>'
				. '</template>';
		}

		/**
		 * A height in pixels, whatever the unit was written as.
		 *
		 * "500", "500px", " 500 px " and 500 are the same thing. Anything with
		 * no leading number at all — "auto", "tall", "" — is the default rather
		 * than zero, because a map of zero pixels is indistinguishable from a
		 * plugin that did not load.
		 *
		 * Only px is honoured. A percentage or a vh would have to be passed
		 * through to css, and a percentage height on a map whose parent has no
		 * height computes to zero — which is the single commonest way a Leaflet
		 * map renders as a blank strip.
		 *
		 * The default is an argument since Task 21, so that the fallback is the
		 * site's setting rather than this class's constant. It still *defaults*
		 * to the constant, which is what a caller that has no settings to hand
		 * gets — and is what keeps DEFAULT_HEIGHT the one written source of the
		 * number.
		 *
		 * @param mixed $value   Raw attribute.
		 * @param int   $default Height when there is no number to read.
		 * @return int
		 */
		private function pixels( $value, int $default = self::DEFAULT_HEIGHT ): int {
			if ( is_bool( $value ) || ! is_scalar( $value ) ) {
				return $default;
			}

			if ( ! preg_match( '/^-?\d+(?:\.\d+)?/', trim( (string) $value ), $match ) ) {
				return $default;
			}

			return (int) max( self::MIN_HEIGHT, min( self::MAX_HEIGHT, (int) round( (float) $match[0] ) ) );
		}

		/**
		 * A number, or null when there is no number to read.
		 *
		 * Booleans are refused rather than cast. (string) true is '1', which
		 * is_numeric() accepts, so without this radius="yes" would arrive as a
		 * one-kilometre search.
		 *
		 * @param mixed $value Raw attribute.
		 * @return float|null
		 */
		private function number( $value ): ?float {
			if ( is_bool( $value ) || ! is_scalar( $value ) ) {
				return null;
			}

			$value = trim( (string) $value );

			return is_numeric( $value ) ? (float) $value : null;
		}

		/**
		 * An integer inside a range, or the default when there is no number.
		 *
		 * @param mixed $value   Raw attribute.
		 * @param int   $min     Lowest accepted value.
		 * @param int   $max     Highest accepted value.
		 * @param int   $default Value when nothing numeric arrived.
		 * @return int
		 */
		private function bounded_int( $value, int $min, int $max, int $default ): int {
			$number = $this->number( $value );

			if ( null === $number || is_nan( $number ) ) {
				return $default;
			}

			return (int) max( $min, min( $max, (int) $number ) );
		}

		/**
		 * A float inside a range, or the default when there is no number.
		 *
		 * @param mixed $value   Raw attribute.
		 * @param float $min     Lowest accepted value.
		 * @param float $max     Highest accepted value.
		 * @param float $default Value when nothing numeric arrived.
		 * @return float
		 */
		private function bounded_float( $value, float $min, float $max, float $default ): float {
			$number = $this->number( $value );

			if ( null === $number || is_nan( $number ) ) {
				return $default;
			}

			return max( $min, min( $max, $number ) );
		}

		/**
		 * The search radius, which is never zero.
		 *
		 * /stores declares minimum 0.0, and clamping to that would agree with
		 * the schema and be useless: radius="0" renders
		 * <option value="0" selected>0 km</option> and a search that can never
		 * match anything, with no error and nothing on screen to explain it.
		 *
		 * A non-positive radius falls back to the default rather than to a
		 * floor, because bounded_float() already has exactly one rule for a
		 * value it cannot use — take the default — and radius="0" is such a
		 * value for the same reason radius="banana" is. A floor of the smallest
		 * offered step would be a second rule, and it would have to be chosen:
		 * too high and it overrides a deliberate radius="1" in a dense city,
		 * too low and it is the useless option again with a different number.
		 *
		 * Small positive radii are left exactly as typed. radius="1" stays 1 and
		 * with_current() adds it to the select, so a locator for a single
		 * neighbourhood works without this method having an opinion about it.
		 *
		 * The site's default_radius is the fallback since Task 21, and it is
		 * **not** re-checked here. It was, briefly: a `0.0 < $default` guard, on
		 * the argument that a default of nothing would be the useless search on
		 * every locator on the site. No input can reach it — this method is
		 * private, attributes() is its only caller, and attributes() takes the
		 * value from Settings::all(). Settings is where a non-positive default is
		 * refused, once.
		 *
		 * SIX GUARDS WENT FOR THIS REASON, AND HERE IS THE LIST
		 * -----------------------------------------------------
		 * Every one of them re-checked a value that had already come out of
		 * Settings::all(), which sanitises everything it answers with —
		 * including a value a direct database edit wrote — so no input could
		 * take the branch, and a condition no case can falsify is a claim rather
		 * than a defence. Three separate documents gave three different counts
		 * of these before review; the union is six and this is it:
		 *
		 * 1. radius()      — $default re-checked for being positive.
		 * 2. units()       — $default re-checked against Geo::UNITS.
		 * 3. cluster()     — $default re-checked against CLUSTER_CHOICES.
		 * 4. attributes()  — min( self::MAX_ZOOM, … ) over the tile ceiling.
		 * 5. radius_options() — is_numeric() and <= MAX_RADIUS per choice.
		 * 6. limit_options()  — is_numeric() and <= MAX_LIMIT per choice.
		 *
		 * Four of the six were found by a mutation surviving — M104, M113, M114
		 * and M117 — and the other two are the same edit applied to the same
		 * shape by hand. Assets::enqueue_admin() has the two this file removed
		 * before Task 21, for the same reason.
		 *
		 *
		 * @param mixed $value   Raw attribute.
		 * @param float $default Radius when there is no usable number.
		 * @return float
		 */
		private function radius( $value, float $default = Rest_Controller::DEFAULT_RADIUS ): float {
			$radius = $this->bounded_float( $value, 0.0, Rest_Controller::MAX_RADIUS, $default );

			return 0.0 < $radius ? $radius : $default;
		}

		/**
		 * A coordinate inside its range, or null when none was given.
		 *
		 * Null rather than a default, and that is the whole of it: a map with no
		 * centre named frames itself around the locations it found, while a
		 * default of 0,0 is the Gulf of Guinea — the same fabricated point
		 * Store::has_coordinates() refuses to treat as a place.
		 *
		 * @param mixed $value Raw attribute.
		 * @param float $min   Lowest accepted value.
		 * @param float $max   Highest accepted value.
		 * @return float|null
		 */
		private function coordinate( $value, float $min, float $max ): ?float {
			$number = $this->number( $value );

			if ( null === $number || is_nan( $number ) ) {
				return null;
			}

			return max( $min, min( $max, $number ) );
		}

		/**
		 * The class this locator puts on its buttons, or the site's.
		 *
		 * The rule itself is Settings::class_list(), called rather than
		 * reimplemented, because an attribute and a setting disagreeing about
		 * what a class is would be two gates with one field behind them — and
		 * the attribute is the side a shortcode in a post can reach, so it is
		 * the side that must not be the looser of the two.
		 *
		 * Empty means "say nothing", which is what every attribute in
		 * raw_defaults() means by it, and it is why whitespace is not a value:
		 * `button_class="   "` cleans to '' and falls back to the setting
		 * rather than clearing it. A locator that wants no class on a site that
		 * set one is a locator whose theme classes are the site's business;
		 * spelling that as an attribute would need a third state this field
		 * does not have.
		 *
		 * @param mixed  $value   Whatever the shortcode carried.
		 * @param string $default The site setting, already sanitised.
		 * @return string
		 */
		private function classes( $value, string $default ): string {
			$given = Settings::class_list( $value );

			return '' === $given ? $default : $given;
		}

		/**
		 * The unit to measure in.
		 *
		 * Trimmed and lowercased before the membership test, because this is
		 * where a person typed it: "Miles" in a shortcode is a typo to be
		 * corrected, not an attack to be refused. Geo::UNITS is the list, so a
		 * unit added there is accepted here without this file being edited —
		 * and anything not on it becomes kilometres rather than being passed on,
		 * since Geo treats an unknown unit as kilometres in the bounding box and
		 * in the distance alike and nothing downstream could tell.
		 *
		 * The site's units setting is the fallback since Task 21, and — like
		 * radius()'s — it is not re-checked here. radius() has the list of the
		 * six guards that went for that reason and the argument behind them.
		 *
		 * @param mixed  $value   Raw attribute.
		 * @param string $default Unit when the attribute names none this plugin knows.
		 * @return string
		 */
		private function units( $value, string $default = 'km' ): string {
			if ( is_bool( $value ) || ! is_scalar( $value ) ) {
				return $default;
			}

			$value = strtolower( trim( (string) $value ) );

			return in_array( $value, Geo::UNITS, true ) ? $value : $default;
		}

		/**
		 * A boolean attribute, written however somebody writes booleans.
		 *
		 * yes/no, true/false, 1/0 and on/off, in any case. Anything else keeps
		 * the default rather than being read as false, because "maybe" is a
		 * mistake and turning a feature off silently is a worse answer to a
		 * mistake than leaving it as it was.
		 *
		 * @param mixed $value   Raw attribute.
		 * @param bool  $default Value when nothing recognisable arrived.
		 * @return bool
		 */
		private function boolean( $value, bool $default ): bool {
			if ( is_bool( $value ) ) {
				return $value;
			}

			if ( ! is_scalar( $value ) ) {
				return $default;
			}

			$value = strtolower( trim( (string) $value ) );

			if ( in_array( $value, array( 'yes', 'true', '1', 'on' ), true ) ) {
				return true;
			}

			if ( in_array( $value, array( 'no', 'false', '0', 'off' ), true ) ) {
				return false;
			}

			return $default;
		}

		/**
		 * A short piece of text, cut to a character count.
		 *
		 * sanitize_text_field() first, which is the same sanitiser /geocode
		 * declares for q, so what is prefilled here is what that route would
		 * accept. Then the cut, and the cut is on characters rather than bytes:
		 * substr() would split a multi-byte character, and a string ending in
		 * half a "ó" is invalid utf-8 — which json_encode() answers with false,
		 * taking the whole config down to {} over a prefilled search box. The
		 * /u pattern does the same work as mb_substr() on a host that has no
		 * mbstring, which the binaries this suite runs on do not.
		 *
		 * @param mixed $value Raw attribute.
		 * @param int   $max   Most characters to keep.
		 * @return string
		 */
		private function text( $value, int $max ): string {
			if ( is_bool( $value ) || ! is_scalar( $value ) ) {
				return '';
			}

			$value = sanitize_text_field( (string) $value );

			if ( '' === $value ) {
				return '';
			}

			if ( preg_match( '/^.{0,' . $max . '}/us', $value, $match ) ) {
				return $match[0];
			}

			return substr( $value, 0, $max );
		}

		/**
		 * Resolves the category attribute to a term name, or reports the miss.
		 *
		 * The *name* is what travels onwards, not the slug and not the id, and
		 * that is not arbitrary: /stores filters on category names, folding
		 * them in Rest_Controller::has_category(), because the lean payload
		 * caches names rather than term ids. A slug in the config would filter
		 * nothing and look like it was working.
		 *
		 * Three fields are tried, in the order a person is most likely to have
		 * meant. A value of nothing but digits is tried as a term id first,
		 * because a category literally named "12" is far less likely than a
		 * shortcode generator writing out an id.
		 *
		 * @param mixed $value Raw attribute.
		 * @return array{name: string, missing: string}
		 */
		private function category( $value ): array {
			$none = array(
				'name'    => '',
				'missing' => '',
			);

			if ( is_bool( $value ) || ! is_scalar( $value ) ) {
				return $none;
			}

			$given = sanitize_text_field( (string) $value );

			if ( '' === $given ) {
				return $none;
			}

			$fields = ctype_digit( $given ) ? array( 'term_id', 'slug', 'name' ) : array( 'slug', 'name' );

			foreach ( $fields as $field ) {
				$term = get_term_by( $field, $given, Post_Type::TAXONOMY );

				// A plain property read, not a WP_Term type check: get_term_by()
				// can return an array when a caller asks for one, and this class
				// only ever needs the name off whatever came back.
				if ( is_object( $term ) && isset( $term->name ) && is_string( $term->name ) && '' !== $term->name ) {
					return array(
						'name'    => $term->name,
						'missing' => '',
					);
				}
			}

			return array(
				'name'    => '',
				'missing' => $given,
			);
		}
	}
}
