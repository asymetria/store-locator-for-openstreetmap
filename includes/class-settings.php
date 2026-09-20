<?php
/**
 * The closed list of site-wide settings, and the one gate every write passes.
 *
 * @package Store_Locator_For_OpenStreetMap
 */

namespace Asymetria\StoreLocator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( __NAMESPACE__ . '\\Settings' ) ) {

	/**
	 * What a site may configure, what each value may be, and what it falls back to.
	 *
	 * This class is in includes/ rather than beside the screen in admin/, and
	 * the reason is what *reads* it: Shortcode::attributes() and ::config() ask
	 * for the map and search defaults on every front-end page carrying a
	 * locator, Assets asks for the tile url, and Rest_Controller asks for the
	 * country restriction on a public route. None of those may depend on a
	 * class whose subject is an admin screen. The screen —
	 * Admin\Settings_Screen — writes this option and does nothing else with it.
	 *
	 * That is a statement about *dependency* and not about loading, and the
	 * distinction is worth making because the looser version of this sentence
	 * was here and was false. Plugin::boot() has no is_admin() gate anywhere in
	 * it — a deliberate arrangement its own comments argue for, one rule in one
	 * place rather than a runtime condition in two — so it constructs Admin,
	 * Locations_List, Bulk_Geocode and Settings_Screen on every request there
	 * is, front end included, and the autoloader pulls all four files in. About
	 * five and a half thousand lines, measured at roughly 3.6 ms to tokenise
	 * with no OPcache, which is the cost that arrangement was accepted at. What
	 * this file's placement buys is therefore not "admin/ is not loaded here";
	 * it is that the front end's reads do not *point* at admin/, so the day
	 * boot() does grow a gate, nothing on a page with a map on it breaks.
	 *
	 * WHY THE OPTION LIST IS CLOSED, AND WHAT CLOSING IT COSTS
	 * ========================================================
	 * The design document's reason is that WP Store Locator has hundreds of
	 * settings not because they were needed but because a decade of user
	 * requests each landed as one more checkbox. The rule this file applies is
	 * the operational form of that: **a setting exists here only if what it is
	 * for can be said in one sentence to a site owner**, and three things the
	 * plan named are deliberately not here because they could not be:
	 *
	 * - *The preload threshold.* Shortcode::preload_threshold() is a filter and
	 *   its docblock argues at length why: the two modes are a transfer trade
	 *   rather than a preference, and a site owner asked to choose has no way to
	 *   know which side of the line they are on, so the setting would be a
	 *   question that produces a wrong answer and then blames the person who
	 *   gave it. Nothing about a settings screen changes that argument. A site
	 *   that has measured its own traffic still has the filter.
	 * - *An opening-hours format.* The hours field is free text with significant
	 *   newlines — Admin::print_hours() renders a textarea, Store::$hours is a
	 *   string — so there is no structured time to format. The only real
	 *   decision is whether the newlines survive into the popup, and that has
	 *   one right answer, which is now simply what the front end does.
	 * - *A tile provider picker.* "OSM / Carto / custom" is three names for one
	 *   field. The url is the setting; a dropdown of two providers this plugin
	 *   does not vendor and cannot promise the licence terms of is a list that
	 *   goes stale in public.
	 *
	 * THE MERGE, WHICH IS WHAT MAKES FOUR TABS SAFE
	 * =============================================
	 * Each tab posts one form and that form carries one tab's fields. Core's
	 * wp-admin/options.php reads `$_POST['slosm_settings']` and passes **null**
	 * when it is not there at all (line 337 of WordPress 6.9.1), then calls
	 * `update_option()` with whatever it got. So a sanitiser that built its
	 * answer out of the posted array alone would erase the other three tabs on
	 * every save, and erase the whole option on a form that carried nothing.
	 *
	 * sanitise() therefore resolves every key in the closed list from three
	 * places in order — the input, then the stored option, then the default —
	 * and runs the field's own sanitiser over whichever it found. Two
	 * consequences are worth stating because cases pin both:
	 *
	 * - A key that is *absent* keeps what is stored. That is a tab that was not
	 *   the tab being saved.
	 * - A key that is *present and unusable* takes the **default**, not the
	 *   stored value. Absent is a form not asking; present and wrong is a person
	 *   saying something, and answering both the same way would mean a typo
	 *   silently kept the old value while the field showed the typo.
	 *
	 * A checkbox is the one control that cannot rely on this, because an
	 * unchecked box posts nothing and "absent" here means "keep". Every checkbox
	 * on the screen is therefore printed with a hidden companion carrying 0, the
	 * pattern core itself uses; Settings_Screen::checkbox() has it.
	 *
	 * THERE IS NO SECOND DOOR
	 * =======================
	 * register_setting() adds the sanitize callback as a filter on
	 * `sanitize_option_slosm_settings` (wp-includes/option.php line 3073), and
	 * update_option() runs sanitize_option() on every write (line 887). So this
	 * function is the gate for the form, for WP-CLI, for an importer and for
	 * this plugin's own code alike — provided the registration has happened,
	 * which is why register() below is hooked to `init` and not to `admin_init`.
	 * See that method.
	 */
	final class Settings {

		/**
		 * The option every setting lives in.
		 *
		 * Geocoder::SETTINGS_OPTION by value rather than by reference, because
		 * that class must not have to load this one to read four of its own
		 * keys on a front-end request. A case asserts the two are equal, which
		 * is the check a shared constant would have been.
		 *
		 * @var string
		 */
		public const OPTION = 'slosm_settings';

		/**
		 * The option group settings_fields() names and options.php looks up.
		 *
		 * The same string as the option itself, which is allowed — a group is a
		 * list of option names — and is one fewer name to keep straight.
		 *
		 * @var string
		 */
		public const GROUP = 'slosm_settings';

		/**
		 * The screen's menu slug.
		 *
		 * Public and here rather than on the screen class because Assets reads
		 * it: an admin page's hook suffix is always `{type}_page_{slug}` —
		 * get_plugin_page_hookname(), wp-admin/includes/plugin.php lines
		 * 2158-2177 of WordPress 6.9.1 — so a handle can be put on this screen
		 * without an includes/ class *referring* to an admin/ one. Referring,
		 * not loading: the class docblock above has why the difference matters
		 * and why the looser word was wrong here.
		 *
		 * @var string
		 */
		public const PAGE = 'slosm-settings';

		/**
		 * The argument naming which tab is being looked at.
		 *
		 * @var string
		 */
		public const TAB_ARG = 'tab';

		/**
		 * The three things Leaflet substitutes into a tile url.
		 *
		 * A tile layer with none of them fetches one image and draws it as
		 * every tile at every zoom, which reads as a broken map rather than as
		 * a setting typed wrong. All three are required, not any of them: a url
		 * with `{z}` and no `{x}` is a column of identical tiles.
		 *
		 * @var string[]
		 */
		public const TILE_PLACEHOLDERS = array( '{z}', '{x}', '{y}' );

		/**
		 * The only protocols a url in this option may use.
		 *
		 * The same two Geocoder::ENDPOINT_PROTOCOLS names, and for the reason
		 * that docblock gives: esc_url_raw()'s default list is
		 * wp_allowed_protocols(), which has twenty-two entries.
		 *
		 * @var string[]
		 */
		public const ENDPOINT_PROTOCOLS = array( 'http', 'https' );

		/**
		 * The characters a tile url may contain.
		 *
		 * Core's own set from esc_url() — wp-includes/formatting.php of
		 * WordPress 6.9.1 — **plus the two braces**, and the braces are the
		 * whole reason this constant exists instead of a call to esc_url_raw().
		 *
		 * `{` and `}` are not in core's class, so esc_url_raw() strips them:
		 * `https://tile.example/{z}/{x}/{y}.png` comes back as
		 * `https://tile.example/z/x/y.png`. A tile url validated with core's
		 * sanitiser therefore arrives here with its placeholders already
		 * removed, fails the placeholder test below, and is refused — so the
		 * one url shape this field exists to accept would be the one shape it
		 * rejected, on every site, silently.
		 *
		 * tests/bootstrap.php's esc_url_raw() stub models that stripping, which
		 * it did not before this task; without it the mistake passes the suite.
		 *
		 * @var string
		 */
		public const TILE_URL_CHARACTERS = '|[^a-z0-9\-~+_.?#=!&;,/:%@$\|*\'()\[\]{}\x80-\xff]|i';

		/**
		 * The three answers to "should this map cluster".
		 *
		 * The same three Shortcode::cluster() reads, in the same order, because
		 * this is the site-wide default for that attribute and a fourth word
		 * here would be a default no shortcode could express.
		 *
		 * @var string[]
		 */
		public const CLUSTER_CHOICES = array( 'auto', 'yes', 'no' );

		/**
		 * The marker shapes the front end can draw.
		 *
		 * @var string[]
		 */
		public const MARKER_STYLES = array( 'pin', 'dot' );

		/**
		 * Where the result list sits relative to the map.
		 *
		 * Three, because a locator in a narrow column and one in a full-width
		 * section want different answers and the page cannot ask.
		 *
		 * @var string[]
		 */
		public const POSITIONS = array( 'right', 'left', 'below' );

		/**
		 * Where a "directions" link goes.
		 *
		 * Three and not four. openstreetmap.org is the default and the one this
		 * plugin's whole premise points at; Google Maps is where a great many
		 * visitors already are, and refusing to send them there is a decision
		 * for the site rather than for this file; and "none" is for a site that
		 * would rather send nobody anywhere. Apple Maps is left out because its
		 * links only deep-link on Apple hardware and a fourth entry has to earn
		 * a sentence of its own.
		 *
		 * @var string[]
		 */
		public const DIRECTIONS = array( 'osm', 'google', 'none' );

		/**
		 * What a result row may show, in the order the row prints them.
		 *
		 * The order is this constant's and not the chooser's: a site ticking
		 * "city" before "name" has said which fields, not which sequence, and a
		 * row whose name is under its city is not a layout anybody asked for.
		 *
		 * @var string[]
		 */
		public const RESULT_FIELDS = array( 'name', 'address', 'city', 'distance', 'categories' );

		/**
		 * What a popup may show, in the order the popup prints them.
		 *
		 * The point of the list is the *absence* of a field rather than its
		 * presence: a location's phone number and email are stored whether or
		 * not a site wants them published on a public map, and this is where a
		 * site says so. Empty values are skipped by the front end already, so
		 * this is not a tidiness control.
		 *
		 * @var string[]
		 */
		public const POPUP_FIELDS = array( 'name', 'address', 'categories', 'phone', 'email', 'url', 'hours', 'description' );

		/**
		 * How many steps a radius or result-count list may offer.
		 *
		 * A select somebody reads, not a data set — the same argument
		 * Geocoder::SUGGEST_LIMIT makes about a suggestion dropdown.
		 *
		 * @var int
		 */
		public const MAX_CHOICES = 12;

		/**
		 * How many countries a lookup may be restricted to.
		 *
		 * @var int
		 */
		public const MAX_COUNTRIES = 10;

		/**
		 * The longest single line of text this option stores, in characters.
		 *
		 * The User-Agent is a header value and the attribution is a line under
		 * a map; neither is a place for a paragraph.
		 *
		 * @var int
		 */
		public const MAX_TEXT = 200;

		/**
		 * The longest url this option stores, in characters.
		 *
		 * @var int
		 */
		public const MAX_URL = 600;

		/**
		 * The longest cache lifetime this option stores, in seconds.
		 *
		 * A year. Not because a year is right — thirty days is, and is the
		 * default — but because the field takes a number of seconds and a
		 * mis-typed one is a cache entry that outlives the business.
		 *
		 * @var int
		 */
		public const MAX_CACHE_TTL = 365 * DAY_IN_SECONDS;

		/**
		 * Which tab each setting is printed on.
		 *
		 * The one place the four tabs are described. The screen builds its
		 * sections and fields from this, and a case asserts every key appears
		 * on exactly one tab and that the tabs between them cover the whole
		 * closed list — so a setting added to defaults() and forgotten here is
		 * a failing case rather than a field nobody can reach.
		 *
		 * @var array<string, string[]>
		 */
		public const TABS = array(
			'map'      => array(
				'tile_url',
				'tile_attribution',
				'tile_attribution_url',
				'tile_max_zoom',
				'default_lat',
				'default_lng',
				'default_zoom',
				'map_height',
				'marker_style',
				'marker_colour',
				'cluster',
			),
			'search'   => array(
				'units',
				'radius_choices',
				'default_radius',
				'limit_choices',
				'default_limit',
				'autocomplete',
				'country',
				'near_me',
			),
			'results'  => array(
				'results_position',
				'result_fields',
				'popup_fields',
				'directions',
			),
			'advanced' => array(
				'skin',
				'button_class',
				'geocode_endpoint',
				'suggest_endpoint',
				'geocode_user_agent',
				'geocode_cache_ttl',
				'suggest_cache_ttl',
				'remove_data',
			),
		);

		/**
		 * The closed list, with the value each setting has when nothing says otherwise.
		 *
		 * A method and not a constant because several of the values are other
		 * classes' constants and one of them is null: a constant expression
		 * would work in PHP 8.0 and would tie the evaluation order of four
		 * class bodies together for no gain.
		 *
		 * **Every default is the value this plugin already behaved as before
		 * there was a screen.** That is what makes the screen safe to ship: an
		 * existing site that never opens it sees no change at all, and a case
		 * asserts the numbers against the constants they came from rather than
		 * against retyped copies.
		 *
		 * The attribution is plain text and a url rather than one field of
		 * html, and that is a security decision with a licence attached.
		 * Leaflet's attribution control writes its contents with innerHTML —
		 * `Ke._update` is `this._container.innerHTML=i.join(…)` in the vendored
		 * assets/leaflet/leaflet.js — so an html attribution field would be
		 * markup injection inside Leaflet by a user with manage_options, which
		 * on multisite is a site administrator who has no unfiltered_html. Two
		 * plain fields assembled and escaped by attribution_html() need no
		 * kses, cannot carry a tag, and still produce the link the ODbL wants.
		 * The visible difference from the frozen constant this replaces is that
		 * the whole line is the link rather than one word of it.
		 *
		 * @return array<string, mixed>
		 */
		public static function defaults(): array {
			return array(
				// Map.
				'tile_url'             => 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
				'tile_attribution'     => '© OpenStreetMap contributors',
				'tile_attribution_url' => 'https://www.openstreetmap.org/copyright',
				'tile_max_zoom'        => Shortcode::MAX_ZOOM,
				'default_lat'          => null,
				'default_lng'          => null,
				'default_zoom'         => Shortcode::DEFAULT_ZOOM,
				'map_height'           => Shortcode::DEFAULT_HEIGHT,
				'marker_style'         => 'pin',
				'marker_colour'        => '#3388ff',
				'cluster'              => 'auto',

				// Search.
				'units'                => 'km',
				'radius_choices'       => array( 5.0, 10.0, 25.0, 50.0, 100.0, 250.0, 500.0 ),
				'default_radius'       => Rest_Controller::DEFAULT_RADIUS,
				'limit_choices'        => array( 10, 25, 50, 100, 250, 500 ),
				'default_limit'        => Rest_Controller::MAX_LIMIT,
				'autocomplete'         => true,
				'country'              => '',
				'near_me'              => true,

				// Results.
				'results_position'     => 'right',
				'result_fields'        => self::RESULT_FIELDS,
				'popup_fields'         => self::POPUP_FIELDS,
				'directions'           => 'osm',

				// Advanced.
				//
				// True, so that a site that never opens this tab looks the way
				// it did before there was a switch. What it turns off is
				// assets/css/locator-skin.css and nothing else: the layout
				// layer is enqueued whatever this says, because a Leaflet
				// container with no height renders as nothing and "I preferred
				// to write my own css" must not be a way to delete the map.
				'skin'                 => true,

				// Empty, and that is the decision rather than the absence of one.
				// A class here is added to the two buttons so they can take the
				// theme's own button styling — on Bricks that is `bricks-button` —
				// and naming a default would be this plugin writing another
				// vendor's class into its own source, which is how you inherit
				// somebody else's renames as your own regressions. It would also be
				// wrong on its merits: a primary-button class on a secondary
				// control is rarely what a page wants.
				'button_class'         => '',
				'geocode_endpoint'     => '',
				'suggest_endpoint'     => '',
				'geocode_user_agent'   => '',
				'geocode_cache_ttl'    => Geocoder::CACHE_TTL,
				'suggest_cache_ttl'    => Geocoder::SUGGEST_CACHE_TTL,
				'remove_data'          => false,
			);
		}

		/**
		 * Registers this option and its sanitiser with WordPress. Hooked to init.
		 *
		 * **init, not admin_init**, and that is the decision in this method.
		 * The convention is admin_init, and the convention is wrong for an
		 * option with a sanitiser that matters: register_setting() adds the
		 * callback to `sanitize_option_slosm_settings`
		 * (wp-includes/option.php line 3073), and update_option() runs
		 * sanitize_option() on every write (line 887) — so on admin_init the
		 * gate exists on admin requests and nowhere else. A WP-CLI run, a
		 * migration or another plugin writing this option on a front-end
		 * request would then store whatever it liked, and the front end reads
		 * this option on every page carrying a locator.
		 *
		 * Nothing is lost by moving it. options.php builds its allowed list
		 * from the `allowed_options` filter, which reads the `$new_allowed_options`
		 * global this call fills (wp-admin/includes/plugin.php line 2281), and
		 * init fires before admin_init on every admin request —
		 * wp-settings.php line 742 against wp-admin/admin.php line 180. And
		 * register_setting() itself lives in wp-includes/option.php, so it is
		 * defined on a front-end request; add_settings_section() and
		 * add_settings_field() are not, which is why add_fields() is a separate
		 * method on a separate hook.
		 *
		 * It lives on this class rather than on the screen, which is where it
		 * was written first. Nothing in it is about a screen: the option and the
		 * sanitiser are both this class's, register_setting() is in
		 * wp-includes/option.php, and the hook is the one callback of this
		 * plugin's that has to run on a front-end request. Hanging it off
		 * Admin\Settings_Screen meant Plugin::boot() naming an admin/ class in
		 * that callback — harmless, since boot() constructs four admin objects
		 * unconditionally anyway, but it made two comments in this plugin read as
		 * though it did not.
		 *
		 * Static because it registers a global fact about an option and has
		 * nothing to say to an instance.
		 *
		 * @return void
		 */
		public static function register(): void {
			register_setting(
				self::GROUP,
				self::OPTION,
				array(
					'type'              => 'array',
					'sanitize_callback' => array( self::class, 'sanitise' ),

					/*
					 * Off, and not by omission. An array setting shown in REST
					 * has to declare an item schema or register_setting()
					 * answers with _doing_it_wrong() (line 3036) — but the real
					 * reason is that there is nothing here anything should read
					 * over REST. The front end is handed what it needs by the
					 * shortcode, already resolved; the endpoints and the
					 * User-Agent are site configuration, not content.
					 */
					'show_in_rest'      => false,
				)
			);
		}

		/**
		 * Every setting, sanitised, with the stored values over the defaults.
		 *
		 * sanitise() with nothing posted, which is exactly what "read the
		 * option" means here: no key is present in the input, so every one
		 * resolves to the stored value or the default and every one goes
		 * through its own sanitiser on the way out.
		 *
		 * That re-sanitising is not paranoia about this plugin's own writes. It
		 * is about the writes that never met the filter: an option restored
		 * from a backup taken before this screen existed, a direct database
		 * edit, a migration, another plugin that took the same option name. The
		 * front end reads this on every page carrying a locator, and a tile url
		 * or a height from any of those sources would otherwise go straight out
		 * to the browser.
		 *
		 * Read fresh on every call rather than held in a static, for the reason
		 * Geocoder::setting() gives: a value changed mid-request — by a save,
		 * by a test, by WP-CLI — has to take effect, and a memo here would be a
		 * memo nothing invalidates.
		 *
		 * @return array<string, mixed>
		 */
		public static function all(): array {
			return self::sanitise( array() );
		}

		/**
		 * One setting.
		 *
		 * @param string $key Setting name.
		 * @return mixed Null when the name is not on the closed list.
		 */
		public static function get( string $key ) {
			$all = self::all();

			return array_key_exists( $key, $all ) ? $all[ $key ] : null;
		}

		/**
		 * Makes a posted, imported or restored settings array safe to store.
		 *
		 * Registered as register_setting()'s sanitize_callback, so it runs on
		 * every update_option( 'slosm_settings', … ) anywhere on the site. The
		 * class docblock has the three-place resolution and why the two
		 * fallbacks differ.
		 *
		 * The stored option is read with get_option() rather than taken as an
		 * argument. That is safe inside the sanitize_option filter because
		 * get_option() applies `option_{$name}` and `default_option_{$name}`
		 * and never `sanitize_option_{$name}` — there is no recursion — and it
		 * keeps the signature the one WordPress will call it with.
		 *
		 * @param mixed $input Whatever arrived: a posted array, null, or worse.
		 * @return array<string, mixed> Exactly the keys of the closed list.
		 */
		public static function sanitise( $input ): array {
			$given    = is_array( $input ) ? $input : array();
			$stored   = get_option( self::OPTION, array() );
			$stored   = is_array( $stored ) ? $stored : array();
			$defaults = self::defaults();
			$clean    = array();

			foreach ( $defaults as $key => $default ) {
				if ( array_key_exists( $key, $given ) ) {
					$raw = $given[ $key ];
				} elseif ( array_key_exists( $key, $stored ) ) {
					$raw = $stored[ $key ];
				} else {
					$raw = $default;
				}

				$clean[ $key ] = self::field( $key, $raw, $default );
			}

			return $clean;
		}

		/**
		 * The attribution line as the markup Leaflet will be handed.
		 *
		 * Built here, in PHP, and never in JavaScript, because this is the one
		 * string in the plugin that a third-party library puts through
		 * innerHTML. Both halves are sanitised again on the way in rather than
		 * trusted to have come from sanitise(): this method is public, the
		 * front end is where the result lands, and "the caller will have
		 * sanitised it" is the assumption that makes these bugs.
		 *
		 * esc_attr() on the url rather than esc_url(). The url has already been
		 * narrowed to http or https above, and esc_url()'s display context
		 * rewrites `&` to `&#038;` and `'` to `&#039;` — correct for a href in
		 * html, and this string is also read by a test and by a person looking
		 * at the page source. esc_attr() gives the same protection inside the
		 * quotes with the plainer spelling.
		 *
		 * An empty attribution is an empty string and not an empty anchor. A
		 * site that clears the field has taken the line off its map, which is
		 * its own business and its own licence problem; an anchor with no text
		 * would be an invisible link in the corner of the map.
		 *
		 * @param array $settings Settings, or at least the two attribution keys.
		 * @return string Html, escaped, or '' when there is nothing to say.
		 */
		public static function attribution_html( array $settings ): string {
			$text = self::text_line( $settings['tile_attribution'] ?? '', self::MAX_TEXT, '' );

			if ( '' === $text ) {
				return '';
			}

			$url = self::url_field( $settings['tile_attribution_url'] ?? '', '' );

			if ( '' === $url ) {
				return esc_html( $text );
			}

			return '<a href="' . esc_attr( $url ) . '" rel="noreferrer">' . esc_html( $text ) . '</a>';
		}

		/**
		 * The tile layer, as both the front end and the picker are handed it.
		 *
		 * One method rather than two call sites assembling three keys, so the
		 * public map and the edit screen cannot end up on different tile servers
		 * or under different credit lines. The shape is Leaflet's own
		 * `L.tileLayer( url, options )` split in two, which is what the frozen
		 * TILE constant in assets/js/locator.js was — this replaces it.
		 *
		 * maxZoom is in the options rather than beside them because that is
		 * where Leaflet reads it, and it is here at all because the tile url is
		 * configurable: a provider that stops at 17 answers 404 for every tile
		 * at 18, which reads as a broken plugin rather than as a zoom nobody
		 * renders.
		 *
		 * @param array|null $settings Settings, or null to read them.
		 * @return array{url: string, attribution: string, maxZoom: int}
		 */
		public static function tile_config( ?array $settings = null ): array {
			/*
			 * The defaults unioned in rather than a `??` per key. Both are total,
			 * and the difference is that a mutation deleting this line is
			 * killable: tile_config( array() ) is a call a case can make, where
			 * "the key was missing" per key is a branch no caller can reach,
			 * since every one of them passes Settings::all() or nothing at all.
			 * `+` and not array_merge(), because the left side wins.
			 */
			$settings = ( null === $settings ? self::all() : $settings ) + self::defaults();

			return array(
				'url'         => (string) $settings['tile_url'],
				'attribution' => self::attribution_html( $settings ),
				'maxZoom'     => (int) $settings['tile_max_zoom'],
			);
		}

		/**
		 * One setting, made safe.
		 *
		 * A switch rather than a table of callables, because a table would put
		 * every bound one indirection away from the name it belongs to, and the
		 * bounds are the part a reader comes here to check.
		 *
		 * @param string $key     Setting name.
		 * @param mixed  $raw     Whatever arrived.
		 * @param mixed  $default What this setting is when the value cannot be used.
		 * @return mixed
		 */
		private static function field( string $key, $raw, $default ) {
			switch ( $key ) {
				case 'tile_url':
					return self::tile_url( $raw, (string) $default );

				case 'tile_attribution':
					return self::text_line( $raw, self::MAX_TEXT, (string) $default );

				case 'tile_attribution_url':
				case 'geocode_endpoint':
				case 'suggest_endpoint':
					// '' is a legitimate value for all three and means "use the
					// built-in one", so the default is '' rather than $default
					// — which is also '' today, and would stop being so the day
					// somebody gives one of them a non-empty default.
					return self::url_field( $raw, '' );

				case 'button_class':
					return self::class_list( $raw );

				case 'geocode_user_agent':
					return self::text_line( $raw, self::MAX_TEXT, '' );

				case 'tile_max_zoom':
				case 'default_zoom':
					return self::bounded_int( $raw, Shortcode::MIN_ZOOM, Shortcode::MAX_ZOOM, (int) $default );

				case 'map_height':
					return self::bounded_int( $raw, Shortcode::MIN_HEIGHT, Shortcode::MAX_HEIGHT, (int) $default );

				case 'default_lat':
					return self::coordinate( $raw, 90.0 );

				case 'default_lng':
					return self::coordinate( $raw, 180.0 );

				case 'marker_style':
					return self::choice( $raw, self::MARKER_STYLES, (string) $default );

				case 'marker_colour':
					return self::colour( $raw, (string) $default );

				case 'cluster':
					return self::choice( $raw, self::CLUSTER_CHOICES, (string) $default );

				case 'units':
					return self::choice( $raw, Geo::UNITS, (string) $default );

				case 'radius_choices':
					return self::radius_list( $raw, (array) $default );

				case 'limit_choices':
					return self::limit_list( $raw, (array) $default );

				case 'default_radius':
					$radius = self::bounded_float( $raw, 0.0, Rest_Controller::MAX_RADIUS, (float) $default );

					// The same rule Shortcode::radius() applies, and for the
					// same reason: a radius of zero is a search that can never
					// match anything, with nothing on screen to explain it.
					return 0.0 < $radius ? $radius : (float) $default;

				case 'default_limit':
					return self::bounded_int( $raw, 1, Rest_Controller::MAX_LIMIT, (int) $default );

				case 'country':
					return self::countries( $raw );

				case 'results_position':
					return self::choice( $raw, self::POSITIONS, (string) $default );

				case 'directions':
					return self::choice( $raw, self::DIRECTIONS, (string) $default );

				case 'result_fields':
					return self::field_list( $raw, self::RESULT_FIELDS );

				case 'popup_fields':
					return self::field_list( $raw, self::POPUP_FIELDS );

				case 'geocode_cache_ttl':
				case 'suggest_cache_ttl':
					return self::bounded_int( $raw, 0, self::MAX_CACHE_TTL, (int) $default );

				case 'autocomplete':
				case 'near_me':
				case 'skin':
				case 'remove_data':
					return self::boolean( $raw );
			}

			// Unreachable while TABS, defaults() and this switch agree, which a
			// case asserts. Returning the default rather than the raw value is
			// the safe half of "unreachable": a setting added to defaults() and
			// not to this switch is a value that never reaches storage
			// unsanitised.
			return $default;
		}

		/**
		 * A tile url, or the default when it is not one.
		 *
		 * Not esc_url_raw(). TILE_URL_CHARACTERS has the whole argument: core's
		 * sanitiser strips the braces, so it would hand back a url whose
		 * placeholders are gone and this method would then refuse the one shape
		 * the field exists for.
		 *
		 * What replaces it is core's own character class with the braces added,
		 * then an explicit scheme test. The scheme test is a regex against the
		 * start of the string rather than wp_parse_url(): a url this method has
		 * just stripped every unexpected character out of has no interesting
		 * parse left to do, and wp_parse_url() answers null for several strings
		 * that this has to refuse rather than pass on.
		 *
		 * @param mixed  $raw     Whatever arrived.
		 * @param string $default The url when this one cannot be used.
		 * @return string
		 */
		private static function tile_url( $raw, string $default ): string {
			if ( is_bool( $raw ) || ! is_scalar( $raw ) ) {
				return $default;
			}

			$url = trim( (string) $raw );
			$url = (string) preg_replace( self::TILE_URL_CHARACTERS, '', $url );

			if ( '' === $url || self::MAX_URL < strlen( $url ) ) {
				return $default;
			}

			if ( ! preg_match( '#^(https?)://#i', $url ) ) {
				return $default;
			}

			foreach ( self::TILE_PLACEHOLDERS as $placeholder ) {
				if ( false === strpos( $url, $placeholder ) ) {
					return $default;
				}
			}

			return $url;
		}

		/**
		 * A url that is http or https, or the fallback.
		 *
		 * esc_url_raw() with the protocol list passed explicitly, which is the
		 * bug Geocoder::endpoint() records: the bare call allows twenty-two
		 * protocols, among them ftp, telnet and svn, so an ftp:// endpoint
		 * would sail through and then fail forever at the transport layer.
		 *
		 * @param mixed  $raw      Whatever arrived.
		 * @param string $fallback Value when the url cannot be used.
		 * @return string
		 */
		private static function url_field( $raw, string $fallback ): string {
			if ( is_bool( $raw ) || ! is_scalar( $raw ) ) {
				return $fallback;
			}

			$url = esc_url_raw( trim( (string) $raw ), self::ENDPOINT_PROTOCOLS );

			if ( '' === $url || self::MAX_URL < strlen( $url ) ) {
				return $fallback;
			}

			return $url;
		}

		/**
		 * One line of text, cut to a character count.
		 *
		 * sanitize_text_field() does the work, and the line breaks are what
		 * matters most: it replaces every run of `[\r\n\t ]` with one space
		 * (wp-includes/formatting.php, _sanitize_text_fields() of WordPress
		 * 6.9.1), so a User-Agent pasted with a newline in it cannot become a
		 * second header.
		 *
		 * The cut is on characters rather than bytes, the same /u pattern
		 * Shortcode::text() uses and for the same reason: substr() splits a
		 * multi-byte character and leaves a string that is not valid utf-8,
		 * which wp_json_encode() answers with false.
		 *
		 * @param mixed  $raw      Whatever arrived.
		 * @param int    $max      Most characters to keep.
		 * @param string $fallback Value when there is no text at all.
		 * @return string
		 */
		private static function text_line( $raw, int $max, string $fallback ): string {
			if ( is_bool( $raw ) || ! is_scalar( $raw ) ) {
				return $fallback;
			}

			$text = sanitize_text_field( (string) $raw );

			if ( '' === $text ) {
				return $fallback;
			}

			if ( preg_match( '/^.{0,' . $max . '}/us', $text, $match ) ) {
				return $match[0];
			}

			return substr( $text, 0, $max );
		}

		/**
		 * A value from a closed list, or the default.
		 *
		 * Trimmed and lowercased first, for the reason Shortcode::units()
		 * gives: this is where a person typed it, so "MI" is a typo to correct
		 * rather than an attack to refuse.
		 *
		 * @param mixed    $raw     Whatever arrived.
		 * @param string[] $allowed The list.
		 * @param string   $default Value when it is not on the list.
		 * @return string
		 */
		private static function choice( $raw, array $allowed, string $default ): string {
			if ( is_bool( $raw ) || ! is_scalar( $raw ) ) {
				return $default;
			}

			$value = strtolower( trim( (string) $raw ) );

			return in_array( $value, $allowed, true ) ? $value : $default;
		}

		/**
		 * A list of css classes, cleaned one class at a time.
		 *
		 * WHY THIS IS NOT ONE CALL TO sanitize_html_class()
		 * =================================================
		 * Two reasons, and the second one is the interesting one.
		 *
		 * The first is colour()'s, in full: core's function is in
		 * wp-includes/formatting.php, which puts it on a front-end request on
		 * WordPress 6.9.1, and this plugin's header promises 6.0 — a version
		 * there is no copy of in this tree and no way to check without a
		 * network request this repository forbids. Settings::all() runs every
		 * sanitiser on every page carrying a locator, and an undefined function
		 * there is a white page. The rule is small, so it is owned outright,
		 * and a case cross-checks it against core's wherever core's exists.
		 *
		 * The second is that **core's function is about one class and this
		 * field holds a list**. `sanitize_html_class()` deletes a space along
		 * with everything else outside `[A-Za-z0-9_-]`, so run over
		 * "btn primary" it answers "btnprimary" — one class nobody has ever
		 * styled, arrived at silently. Splitting on whitespace first is the
		 * whole difference, and it is why calling core's function once would be
		 * wrong even on a version that has it.
		 *
		 * The two steps inside a class are core's own and in core's order:
		 * percent-encoded characters go before the character class, or
		 * `%3Cscript%3E` would leave `3Cscript3E` behind rather than `script`.
		 *
		 * The cap stops at the first class that would cross it rather than
		 * packing shorter ones in behind it. Both are defensible; stopping is
		 * the one whose result a person can predict from what they typed, and
		 * neither ever cuts a class in half — half a class name matches nothing
		 * and reads in the page source like a mistake somebody made.
		 *
		 * @param mixed $raw Whatever arrived.
		 * @param int   $max Longest the whole list may be.
		 * @return string Space-separated classes, or '' when none survived.
		 */
		public static function class_list( $raw, int $max = self::MAX_TEXT ): string {
			if ( is_bool( $raw ) || ! is_scalar( $raw ) ) {
				return '';
			}

			$classes = array();
			$length  = 0;

			foreach ( (array) preg_split( '/\s+/', trim( (string) $raw ) ) as $word ) {
				$class = (string) preg_replace( '|%[a-fA-F0-9][a-fA-F0-9]|', '', (string) $word );
				$class = (string) preg_replace( '/[^A-Za-z0-9_-]/', '', $class );

				if ( '' === $class ) {
					continue;
				}

				// The space before it counts, because the space is in the
				// attribute too.
				$next = $length + strlen( $class ) + ( array() === $classes ? 0 : 1 );

				if ( $next > $max ) {
					break;
				}

				$classes[] = $class;
				$length    = $next;
			}

			return implode( ' ', $classes );
		}

		/**
		 * A hex colour, or the default.
		 *
		 * WHY THIS IS SIX LINES RATHER THAN A CALL TO sanitize_hex_color()
		 * ===============================================================
		 * It was that call. Core's function is in wp-includes/formatting.php —
		 * line 6249 of WordPress 6.9.1, required unconditionally from
		 * wp-settings.php line 115 — so it is there on a front-end request, on
		 * **6.9.1**. This plugin's header promises 6.0, and there is no 6.0 in
		 * this tree to check against: every install here is 6.9.1, and nothing
		 * in this repository may make a network request to go and look.
		 *
		 * The failure that buys is the worst one this plugin has. Settings::all()
		 * runs every field's sanitiser, Shortcode::attributes() calls it on every
		 * front-end page carrying a locator, and an undefined function is a fatal
		 * — so a 6.0 site without it serves a white page on every page with a map
		 * on it, for a colour nobody has to have configured.
		 *
		 * The rule is fifteen lines of regex, so it is written out. That is the
		 * same trade Geocoder makes about mb_strtolower() from the other side: it
		 * guards the call because the fallback loses something real (a cache hit
		 * on "Łódź"), where here the rule is small enough to own outright and
		 * nothing is lost by owning it.
		 *
		 * The behaviour is core's, deliberately, because the field is a
		 * `type="color"` input and a browser posts `#rrggbb`:
		 *
		 * - `''` is valid and means "nothing chosen", which is why the check at
		 *   the end is `'' !== ` as well as a length test. It is the value that
		 *   would otherwise slip through as valid and become `background-color:`
		 *   with nothing after it.
		 * - three or six hex digits, and the `#` is required, which is what
		 *   separates sanitize_hex_color() from sanitize_hex_color_no_hash().
		 * - four and eight digits are refused, as core refuses them.
		 *
		 * Anything this accepts, core's function accepts, and anything it
		 * refuses, core refuses: the pattern below is core's own,
		 * `|^#([A-Fa-f0-9]{3}){1,2}$|`, copied rather than re-derived. A case
		 * asserts the two agree on a table of values wherever core's function is
		 * defined at all, so the copy cannot drift on the version that does have
		 * it.
		 *
		 * @param mixed  $raw     Whatever arrived.
		 * @param string $default Value when it is not a colour.
		 * @return string
		 */
		private static function colour( $raw, string $default ): string {
			if ( is_bool( $raw ) || ! is_scalar( $raw ) ) {
				return $default;
			}

			$colour = trim( (string) $raw );

			return 1 === preg_match( '|^#([A-Fa-f0-9]{3}){1,2}$|', $colour ) ? $colour : $default;
		}

		/**
		 * An integer inside a range, or the default.
		 *
		 * A leading number is read out of whatever arrived, so "600px" is 600,
		 * which is what somebody types into a height field. That is
		 * Shortcode::pixels()'s rule applied to every bounded integer here
		 * rather than to one of them.
		 *
		 * @param mixed $raw     Whatever arrived.
		 * @param int   $min     Lowest accepted value.
		 * @param int   $max     Highest accepted value.
		 * @param int   $default Value when there is no number.
		 * @return int
		 */
		private static function bounded_int( $raw, int $min, int $max, int $default ): int {
			$number = self::number( $raw );

			if ( null === $number ) {
				return $default;
			}

			return (int) max( $min, min( $max, (int) round( $number ) ) );
		}

		/**
		 * A float inside a range, or the default.
		 *
		 * @param mixed $raw     Whatever arrived.
		 * @param float $min     Lowest accepted value.
		 * @param float $max     Highest accepted value.
		 * @param float $default Value when there is no number.
		 * @return float
		 */
		private static function bounded_float( $raw, float $min, float $max, float $default ): float {
			$number = self::number( $raw );

			if ( null === $number ) {
				return $default;
			}

			return max( $min, min( $max, $number ) );
		}

		/**
		 * A coordinate, or null when there is not one.
		 *
		 * Null rather than a default and out-of-range refused rather than
		 * clamped, both for Shortcode's reasons: a default centre of 0,0 is the
		 * Gulf of Guinea, which Store::has_coordinates() refuses to treat as a
		 * place, and clamping a mis-typed latitude puts the map at the pole and
		 * calls that what somebody meant.
		 *
		 * @param mixed $raw   Whatever arrived.
		 * @param float $limit The largest magnitude that is on the earth.
		 * @return float|null
		 */
		private static function coordinate( $raw, float $limit ): ?float {
			$number = self::number( $raw );

			if ( null === $number || abs( $number ) > $limit ) {
				return null;
			}

			return $number;
		}

		/**
		 * A number out of whatever arrived, or null.
		 *
		 * Booleans are refused rather than cast, the same rule
		 * Shortcode::number() states: (string) true is '1', so without this a
		 * checkbox posted into a number field would be a one-kilometre radius.
		 *
		 * A leading number is accepted — "600px" is 600 — and a string with no
		 * leading number at all is null rather than zero, because (int) 'tall'
		 * is 0 and 0 is a real value for several of these fields.
		 *
		 * @param mixed $raw Whatever arrived.
		 * @return float|null
		 */
		private static function number( $raw ): ?float {
			if ( is_bool( $raw ) || ! is_scalar( $raw ) ) {
				return null;
			}

			if ( ! preg_match( '/^\s*[+-]?(?:\d+(?:\.\d*)?|\.\d+)/', (string) $raw, $match ) ) {
				return null;
			}

			$number = (float) $match[0];

			return is_nan( $number ) || is_infinite( $number ) ? null : $number;
		}

		/**
		 * A boolean, written however a form writes booleans.
		 *
		 * No default argument, unlike Shortcode::boolean(). That method keeps
		 * the default for anything it does not recognise, because a shortcode
		 * attribute of "maybe" is a typo and turning a feature off silently is
		 * a worse answer to a typo than leaving it alone. A checkbox is the
		 * opposite case: the only values it can post are the ones listed here
		 * and the hidden companion's 0, so anything else is not a typo, and
		 * off is the safe reading — particularly for remove_data, where the
		 * two answers are "keep the locations" and "delete them".
		 *
		 * @param mixed $raw Whatever arrived.
		 * @return bool
		 */
		private static function boolean( $raw ): bool {
			if ( is_bool( $raw ) ) {
				return $raw;
			}

			if ( ! is_scalar( $raw ) ) {
				return false;
			}

			return in_array( strtolower( trim( (string) $raw ) ), array( '1', 'on', 'yes', 'true' ), true );
		}

		/**
		 * The radii a locator offers: sorted, positive, deduplicated.
		 *
		 * Takes a comma-separated string, which is what the field posts, and an
		 * array, which is what storage holds and what a WP-CLI call would pass.
		 *
		 * Everything above Rest_Controller::MAX_RADIUS is clamped to it rather
		 * than dropped, because a site that typed 1000 wants the largest search
		 * the route will do and not one fewer option than it asked for.
		 *
		 * @param mixed   $raw     Whatever arrived.
		 * @param float[] $default List when nothing usable was given.
		 * @return float[]
		 */
		private static function radius_list( $raw, array $default ): array {
			$values = array();

			foreach ( self::to_list( $raw ) as $item ) {
				$number = self::number( $item );

				if ( null === $number || 0.0 >= $number ) {
					continue;
				}

				$values[] = min( Rest_Controller::MAX_RADIUS, $number );
			}

			$values = array_values( array_unique( $values, SORT_NUMERIC ) );
			sort( $values, SORT_NUMERIC );

			if ( array() === $values ) {
				return array_map( 'floatval', $default );
			}

			return array_map( 'floatval', array_slice( $values, 0, self::MAX_CHOICES ) );
		}

		/**
		 * The result counts a locator offers: sorted, positive, deduplicated.
		 *
		 * @param mixed $raw     Whatever arrived.
		 * @param int[] $default List when nothing usable was given.
		 * @return int[]
		 */
		private static function limit_list( $raw, array $default ): array {
			$values = array();

			foreach ( self::to_list( $raw ) as $item ) {
				$number = self::number( $item );

				if ( null === $number || 1.0 > $number ) {
					continue;
				}

				$values[] = (int) min( (float) Rest_Controller::MAX_LIMIT, round( $number ) );
			}

			$values = array_values( array_unique( $values, SORT_NUMERIC ) );
			sort( $values, SORT_NUMERIC );

			if ( array() === $values ) {
				return array_map( 'intval', $default );
			}

			return array_map( 'intval', array_slice( $values, 0, self::MAX_CHOICES ) );
		}

		/**
		 * The fields a row or a popup shows, in this class's order.
		 *
		 * The order is the constant's, not the chooser's; RESULT_FIELDS has
		 * why. An empty result takes the whole list rather than staying empty,
		 * on the same rule every other field here follows: a value that cannot
		 * be used takes the default, and a row with no fields at all is a list
		 * of blank buttons.
		 *
		 * @param mixed    $raw     Whatever arrived.
		 * @param string[] $allowed The fields there are.
		 * @return string[]
		 */
		private static function field_list( $raw, array $allowed ): array {
			$asked = array();

			foreach ( self::to_list( $raw ) as $item ) {
				if ( is_scalar( $item ) && ! is_bool( $item ) ) {
					$asked[] = strtolower( trim( (string) $item ) );
				}
			}

			$kept = array();

			foreach ( $allowed as $field ) {
				if ( in_array( $field, $asked, true ) ) {
					$kept[] = $field;
				}
			}

			return array() === $kept ? $allowed : $kept;
		}

		/**
		 * The countries a lookup is restricted to, as Nominatim wants them.
		 *
		 * Two-letter codes, lowercased, comma separated and deduplicated, which
		 * is the countrycodes parameter's own format. Anything that is not two
		 * ascii letters is dropped rather than corrected: "Polska" is not a
		 * country code, and passing it on restricts every lookup on the site to
		 * a country that does not exist — which looks exactly like a geocoder
		 * that has stopped working. Admin::address_query() makes the same
		 * distinction from the other side.
		 *
		 * An array is refused rather than joined. The field is one text input,
		 * so an array is not a shape a person can produce here, and joining one
		 * would be inventing an order for values that arrived without one.
		 *
		 * @param mixed $raw Whatever arrived.
		 * @return string
		 */
		private static function countries( $raw ): string {
			if ( is_bool( $raw ) || ! is_scalar( $raw ) ) {
				return '';
			}

			$codes = array();

			foreach ( explode( ',', strtolower( (string) $raw ) ) as $code ) {
				$code = trim( $code );

				if ( 1 === preg_match( '/^[a-z]{2}$/', $code ) && ! in_array( $code, $codes, true ) ) {
					$codes[] = $code;
				}
			}

			return implode( ',', array_slice( $codes, 0, self::MAX_COUNTRIES ) );
		}

		/**
		 * Whatever arrived, as a list of items to look at.
		 *
		 * One place, because three fields take either a comma-separated string
		 * from a text input or an array from storage, a checkbox group or a
		 * WP-CLI call, and three copies of that branch is three chances for one
		 * of them to forget a case.
		 *
		 * @param mixed $raw Whatever arrived.
		 * @return array
		 */
		private static function to_list( $raw ): array {
			if ( is_array( $raw ) ) {
				return $raw;
			}

			if ( is_bool( $raw ) || ! is_scalar( $raw ) ) {
				return array();
			}

			return explode( ',', (string) $raw );
		}
	}
}
