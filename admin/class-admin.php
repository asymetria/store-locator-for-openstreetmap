<?php
/**
 * The screen an editor types a location into, and the save that believes them.
 *
 * @package Store_Locator_For_OpenStreetMap
 */

namespace Asymetria\StoreLocator\Admin;

use Asymetria\StoreLocator\Assets;
use Asymetria\StoreLocator\Geocoder;
use Asymetria\StoreLocator\Post_Type;
use Asymetria\StoreLocator\Rest_Controller;
use Asymetria\StoreLocator\Settings;
use Asymetria\StoreLocator\Store;
use Asymetria\StoreLocator\Store_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( __NAMESPACE__ . '\\Admin' ) ) {

	/**
	 * One metabox on the location screen, and the handler that saves it.
	 *
	 * Where the sanitising lives, and why it lives here
	 * -------------------------------------------------
	 * Store::from_array() casts and defaults and never sanitises; its docblock
	 * says the contract out loud — whoever builds a Store from untrusted input
	 * sanitises first. This class is that place for everything an editor types.
	 * Each field gets the sanitiser its own shape needs, and the choices are not
	 * interchangeable:
	 *
	 * - The address, the contact fields and the phone number go through
	 *   sanitize_text_field().
	 * - The opening hours go through sanitize_textarea_field(), because
	 *   sanitize_text_field() collapses every newline in a week of opening
	 *   hours onto one line. Verified in WordPress 6.9.1: both call
	 *   _sanitize_text_fields(), and the only difference is the $keep_newlines
	 *   flag guarding one preg_replace.
	 * - The website goes through esc_url_raw() and through nothing else. Not
	 *   "as well as": _sanitize_text_fields() deletes every %xx sequence it
	 *   finds, so running a url through it turns /o%20nas into /onas.
	 * - The email goes through is_email(), which answers string|false rather
	 *   than a boolean, and which refuses a domain with no dot.
	 *
	 * The website is an allowlist of two, and that is a decision
	 * ----------------------------------------------------------
	 * esc_url_raw( $url ) with no second argument allows twenty-two protocols,
	 * not two. It is sanitize_url( $url, null ), which is esc_url( $url, null,
	 * 'db' ), and a null protocol list means wp_allowed_protocols() — http,
	 * https, ftp, ftps, mailto, news, irc, irc6, ircs, gopher, nntp, feed,
	 * telnet, mms, rtsp, sms, svn, tel, fax, xmpp, webcal, urn. Verified in
	 * wp-includes/functions.php line 7207 of WordPress 6.9.1. This plugin has
	 * been caught believing otherwise once already, in the Geocoder's endpoint
	 * setting.
	 *
	 * A location's website is a page a visitor opens in a browser, so the list
	 * here is http and https. A mailto: in the website field is an address in
	 * the wrong box and the contact column already has one; an ftp: is a link
	 * no modern browser will follow. A relative reference — '/path', '#anchor',
	 * '?a=b', '//host' — is refused as well, because esc_url() returns those
	 * untouched without consulting the protocol list at all, and an allowlist
	 * with a hole that size is not one. clean_url() has the detail.
	 *
	 * A schemeless url gets https:// prepended here, for the plain reason that
	 * https is the right default for a bare domain. It is not to bridge a
	 * difference between WordPress versions: esc_url() picks the scheme with
	 * `array_first( $protocols )`, and the list handed to it here starts with
	 * 'http', so 6.9 and 6.0 would both prepend http://. An earlier version of
	 * this comment claimed the version difference was the reason, which was a
	 * citation used to support a conclusion that did not follow from it.
	 *
	 * Coordinates: the comma, and what this class does about it
	 * ---------------------------------------------------------
	 * Store refuses to guess: '52,2297' becomes null, never 52.0. That is right
	 * for a value object reading the database, and it is not enough for a field
	 * somebody just typed into, because null there is a location that silently
	 * is not on the map.
	 *
	 * So a comma decimal is normalised, and the editor is told. Not refused, and
	 * the reason is not kindness — it is that in the block editor the message
	 * cannot be shown at all.
	 *
	 * That is worth tracing once, because the obvious guess ("it appears on the
	 * next page load") is wrong, and because Task 18 inherits the consequence.
	 * The block editor's metabox form is posted to
	 * post.php?...&meta-box-loader=1, and the merged FormData comes from
	 * .metabox-base-form, which carries action=editpost — so post.php:17, which
	 * reads $_REQUEST, takes editpost over the edit in the query string. There
	 * is no meta-box-loader branch anywhere in post.php. The request therefore
	 * runs edit_post(), fires save_post, reaches this class, sets the transient,
	 * and ends in redirect_post() — a 302 and exit. fetch follows that 302,
	 * because it must: an unfollowed redirect would make response.ok false and
	 * every metabox save would dispatch metaBoxUpdatesFailure(). The followed
	 * GET renders a whole edit screen, which calls do_meta_boxes(), which calls
	 * render(), which reads the transient and deletes it. And
	 * wp-includes/js/dist/edit-post.js fetches with parse:false and throws that
	 * HTML away.
	 *
	 * So every block-editor save performs an entire extra server-side render
	 * whose only lasting effect is to consume the message before anybody can
	 * see it. The classic editor is unaffected: its save redirects the browser,
	 * and the page that arrives prints the box.
	 *
	 * What Task 18 found, and what it could and could not do about it
	 * ---------------------------------------------------------------
	 * Two more facts, read out of WordPress 6.9.1 rather than guessed:
	 *
	 * - The followed GET does *not* carry meta-box-loader. redirect_post()
	 *   builds its location from add_query_arg( 'message', N,
	 *   get_edit_post_link( $post_id, 'url' ) ) — wp-admin/includes/post.php
	 *   lines 2215 and 2225 — so it is built from scratch and the discarded
	 *   render has nothing in it to recognise itself by. The
	 *   redirect_post_location filter fires inside the request that still has
	 *   the parameter, and is therefore the only moment both facts exist at
	 *   once; mark_discarded_render() is what attaches a marker there, under
	 *   this plugin's own name, because using core's would make the render call
	 *   check_admin_referer( 'meta-box-loader', … ) against a nonce the url
	 *   does not carry. That is wp_nonce_ays() — a 403 "The link you followed
	 *   has expired" page — and not wp_die( -1 ), which is what
	 *   check_ajax_referer() does; an earlier version of this comment named the
	 *   wrong function for the right outcome.
	 * - There is no channel in the response. edit-post.js posts with
	 *   parse:false, awaits it, and dispatches metaBoxUpdatesSuccess — or
	 *   metaBoxUpdatesFailure from the catch — and both of those are one
	 *   reducer case that sets isSaving to false
	 *   (wp-includes/js/dist/edit-post.js lines 455-461 and 632-687). Nothing
	 *   reads the body, the status or a header, so no answer this class could
	 *   give would reach anybody. A metabox save that *fails outright* is
	 *   exactly as silent as one that succeeds.
	 *
	 * What follows is a split, and it is worth being plain about which half is
	 * which:
	 *
	 * - print_messages() now leaves the message alone on the marked render, so
	 *   the next real page load of the edit screen shows it rather than finding
	 *   it already eaten. That is a fix, and it is a *late* one: nothing here
	 *   makes the message appear at the moment of the save.
	 * - The things an editor most needs to hear — the comma, the value that is
	 *   not a number, the range, and half a pair — are said before the save
	 *   instead, by assets/js/admin.js, in both editors, while the person who
	 *   typed the value is still looking at the field. The picker reads a
	 *   coordinate with the same rules parse_coordinate() applies, which is why
	 *   the limits and the decimals travel in its config rather than being
	 *   restated in JavaScript.
	 * - What is left undone is telling a block-editor user, at the moment of
	 *   the save, what the *server* decided — the automatic geocode result
	 *   above all, which is the one message no client can predict.
	 *
	 * That last one is undone rather than impossible, and the difference
	 * matters because an earlier version of this comment claimed there was "no
	 * remaining path but a new REST endpoint and a poll". There is one, it is
	 * already running on this exact screen, and not looking for it was the
	 * mistake: wp-admin/edit-form-blocks.php line 49 enqueues 'heartbeat' and
	 * lines 185-191 set wp.heartbeat.interval( 10 ), so an authenticated tick
	 * every ten seconds is a fact about the block editor's post screen.
	 * heartbeat_received is the server-side filter and heartbeat-tick the
	 * client-side event. Carrying "this save geocoded to X, Y" on it needs one
	 * filter and one listener, no new route and no poll of this plugin's own.
	 *
	 * It is still not built here, and that is a scope decision rather than a
	 * technical one: heartbeat's client side is jQuery, which nothing in this
	 * plugin currently loads, and a channel that ticks every ten seconds wants
	 * its own thinking about what it carries and to whom. It belongs to its own
	 * task, and the path is written down so that task does not have to
	 * rediscover it.
	 *
	 * Under that constraint the two options are not symmetric. Normalising, with
	 * a message that may never arrive, leaves the marker in the right doorway
	 * and the field showing a dot next time anybody looks. Refusing, with a
	 * message that may never arrive, throws the editor's value away and hands
	 * the location to the geocoder, which places a pin from the street address —
	 * a different coordinate, silently, which is exactly the failure the refusal
	 * was supposed to prevent.
	 *
	 * The normalisation is deliberately narrow: digits, one comma, digits, and
	 * nothing else. In a coordinate field that has exactly one reading. A comma
	 * cannot be a thousands separator in a number bounded by 180, and the one
	 * other thing an editor pastes — '52.2297, 21.0122', a whole pair in one
	 * field — does not match, is refused, and says so. Anything else with a
	 * comma in it is refused too.
	 *
	 * Out of range is refused rather than clamped, and the previous value is
	 * kept. Nothing downstream crashes on a latitude of 91 — Leaflet clamps to
	 * 85.05 — which is precisely why it has to be caught here: one row at 91
	 * drags fitBounds() to the pole and zooms every other marker on the map into
	 * invisibility, and nothing on the site reports it.
	 *
	 * The lock
	 * --------
	 * _slosm_lat_locked means "a person put this pin here". It is set when the
	 * coordinates this save leaves differ from the ones it found, cleared when
	 * both fields are emptied, and otherwise left as it was. Every save posts
	 * the coordinate fields whether or not anybody touched them, so locking on
	 * every save would lock every geocoded location the first time somebody
	 * fixed a typo in the opening hours — and the automatic geocoder would then
	 * never touch that site again.
	 *
	 * Two readings of "differ" would set the lock on a location nobody touched,
	 * and both are silent and permanent, which is why each has a guard and a
	 * case of its own:
	 *
	 * - Half a pair. Clearing one of the two fields makes the pair differ, sets
	 *   the lock, and takes the location off the map for ever — one Backspace,
	 *   no message, and a box that then explains the coordinates were set by
	 *   hand. clean() puts both values back instead and says so.
	 * - Float identity. A stored coordinate with more decimals than
	 *   COORDINATE_DECIMALS is rendered rounded, so the next save of any field
	 *   posts a value that differs from the stored float while being the same
	 *   place. Not reachable through this box's own writes, which are always
	 *   formatted here — reachable from any import, and from Task 20's bulk
	 *   writer if it ever formats differently. same_coordinate() compares at
	 *   the precision that is actually stored.
	 *
	 * Where this runs, and why the priority is 9
	 * -------------------------------------------
	 * On save_post_slosm_store at priority 9, which is before Task 8's cache
	 * flush at 10. The order matters because this handler writes coordinates:
	 * flush first and geocode second would throw the map payload away and then
	 * immediately make it stale again, and nothing would invalidate the second
	 * version for up to a day. Plugin::boot() has the companion note; the
	 * wp_after_insert_post flush would cover it as well, and the two together
	 * are cheap.
	 *
	 * The geocoder writing _slosm_lat inside this hook is also what keeps it out
	 * of the gap the repository documents: meta written with no post save around
	 * it fires none of the six invalidation hooks. That gap is Task 20's problem
	 * — a bulk run writes meta from an admin action, not from a save — and it is
	 * not this class's, because everything here happens inside a save.
	 *
	 * What it costs is a blocking request. A save that geocodes waits on
	 * Nominatim, up to the geocoder's five-second timeout plus up to a second of
	 * courtesy delay, while the editor watches a spinner. That is the price of
	 * having coordinates at all without a background job.
	 *
	 * should_geocode() is what keeps it rare, and on its own it does not: a
	 * location whose address the service cannot resolve has no coordinates, so
	 * every save of every field asks again for ever — measured at four saves,
	 * four requests and three courtesy sleeps, on exactly the location whose
	 * saves are already the slowest. The geocoder deliberately never writes a
	 * failure down, which is right for a thirty-day cache and wrong for this
	 * loop, so geocode_into() remembers one for an hour, keyed on the address.
	 *
	 * What this class does *not* do is hook save_post for the whole site or run
	 * anything on a schedule. A save it has no business in returns before it
	 * reads a thing.
	 *
	 * The picker, and the one control it adds
	 * ---------------------------------------
	 * Task 18 put a map under the coordinate fields. Everything the map does
	 * happens in assets/js/admin.js and ends in the value of an input; this
	 * class decides what those values mean, exactly as it did before.
	 *
	 * One new control, and it is hidden: LOOKUP_FIELDS. It carries the pair the
	 * "look up from the address" button wrote, and it is the second way out of
	 * a lock — the only one that does not require emptying both fields. locked()
	 * has why it is a pair rather than a flag, and print_picker() has why it is
	 * rendered empty every time.
	 */
	final class Admin {

		/**
		 * The address column, in the order it is asked for and looked up.
		 *
		 * One list, used three times: the column is rendered from it, the
		 * geocoding query is joined from it, and should_geocode() compares it
		 * component by component. Three copies would be three chances for a
		 * field to be rendered, saved and then quietly left out of the lookup.
		 *
		 * @var string[]
		 */
		public const ADDRESS_FIELDS = array( 'address', 'address2', 'city', 'state', 'zip', 'country' );

		/**
		 * The contact column.
		 *
		 * @var string[]
		 */
		public const CONTACT_FIELDS = array( 'phone', 'email', 'url' );

		/**
		 * The opening-hours column.
		 *
		 * A list of one, rather than a bare constant, so that the test checking
		 * these lists against Store_Repository::META_KEYS can merge four arrays
		 * rather than three arrays and a special case.
		 *
		 * @var string[]
		 */
		public const HOURS_FIELDS = array( 'hours' );

		/**
		 * The one control the picker owns, and the only one that is not stored.
		 *
		 * A hidden field carrying the exact coordinate pair the "look up from
		 * the address" button wrote, formatted the way this class formats a
		 * coordinate. It is read by every save, stored by none: there is no
		 * meta key for it in Store_Repository, and save_fields() skips a field
		 * it does not know.
		 *
		 * A pair rather than a flag, and the difference is the whole of what
		 * this field is worth. "A lookup happened on this screen" stays true
		 * after the editor drags the pin two streets away, so a flag would
		 * leave a hand-placed pin unlocked and the geocoder free to overwrite
		 * it on the next save. A pair can be checked: locked() compares what
		 * was submitted against what the lookup claims to have produced, and a
		 * pair that is not it is a pair somebody moved afterwards.
		 *
		 * A list of one, like HOURS_FIELDS, so that a test comparing the lists
		 * can merge arrays rather than merge arrays and a special case.
		 *
		 * @var string[]
		 */
		public const LOOKUP_FIELDS = array( 'lookup' );

		/**
		 * The query argument that marks a render nobody will ever see.
		 *
		 * This plugin's own name and never core's `meta-box-loader`;
		 * mark_discarded_render() has why re-using core's would be a wp_die.
		 *
		 * @var string
		 */
		public const DISCARDED_ARG = 'slosm_metabox_loader';

		/**
		 * The zoom the picker opens a placed location at.
		 *
		 * Fifteen is a street: close enough to see which side of the road a
		 * doorway is on, which is the only question a person checking a pin is
		 * asking. The front end's own default of 12 is a city, which is right
		 * for "where are your branches" and useless for "is this the right
		 * building". It is inside the tile server's range either way; the
		 * locator's ZOOM constant has the ceiling.
		 *
		 * @var int
		 */
		public const PICKER_ZOOM = 15;

		/**
		 * The coordinate fields, below the three columns.
		 *
		 * lat_locked is not here. It has no control of its own: it is derived
		 * from what an editor did to these two.
		 *
		 * @var string[]
		 */
		public const COORDINATE_FIELDS = array( 'lat', 'lng' );

		/**
		 * The priority the save handler is hooked at.
		 *
		 * Public and a constant because it is a claim about the order two
		 * different files' hooks run in, and a test asserts it against Task 8's
		 * flush rather than against a number written out twice.
		 *
		 * @var int
		 */
		public const SAVE_PRIORITY = 9;

		/**
		 * The metabox id.
		 *
		 * @var string
		 */
		public const META_BOX_ID = 'slosm-location';

		/**
		 * The prefix every one of this box's form controls carries.
		 *
		 * @var string
		 */
		public const FIELD_PREFIX = 'slosm_';

		/**
		 * The name of the nonce field.
		 *
		 * @var string
		 */
		public const NONCE_FIELD = 'slosm_location_nonce';

		/**
		 * The nonce action, which the post id is appended to.
		 *
		 * Appended rather than shared: a nonce for one location must not be a
		 * licence to write to another, and one open form is otherwise exactly
		 * that.
		 *
		 * @var string
		 */
		public const NONCE_ACTION = 'slosm_save_location_';

		/**
		 * The prefix of the transient holding what to tell the editor.
		 *
		 * Keyed by post and by user. Two editors saving the same location at the
		 * same moment would otherwise read each other's messages, which is a
		 * small thing that reads as the screen having gone mad.
		 *
		 * @var string
		 */
		public const NOTICE_PREFIX = 'slosm_location_notices_';

		/**
		 * How long a message waits to be read.
		 *
		 * Long enough to survive the redirect and a slow page, short enough that
		 * a message nobody came back for does not describe a save from last
		 * week.
		 *
		 * @var int
		 */
		public const NOTICE_TTL = 5 * MINUTE_IN_SECONDS;

		/**
		 * The prefix of the transient remembering that an address did not resolve.
		 *
		 * @var string
		 */
		public const FAILURE_PREFIX = 'slosm_geocode_failed_';

		/**
		 * How long a failed lookup is remembered.
		 *
		 * An hour, and geocode_into() has the reasoning: long enough that four
		 * saves of an unresolvable location cost one request rather than four,
		 * short enough that an outage or a newly-mapped street heals without
		 * anybody having to know this cache exists.
		 *
		 * @var int
		 */
		public const FAILURE_TTL = HOUR_IN_SECONDS;

		/**
		 * The protocols a location's website may use.
		 *
		 * Two, not the twenty-two esc_url_raw() allows by default. The class
		 * docblock has the reasoning.
		 *
		 * @var string[]
		 */
		public const URL_PROTOCOLS = array( 'http', 'https' );

		/**
		 * Largest absolute latitude that is on the earth.
		 *
		 * @var float
		 */
		public const LAT_LIMIT = 90.0;

		/**
		 * Largest absolute longitude that is on the earth.
		 *
		 * Its own limit, and not the latitude's. One shared limit of 90 refuses
		 * half the planet; one shared limit of 180 accepts a latitude that
		 * cannot exist.
		 *
		 * @var float
		 */
		public const LNG_LIMIT = 180.0;

		/**
		 * How many decimals a stored coordinate keeps.
		 *
		 * Seven is about a centimetre, which is more than a shop front needs and
		 * less than a float can be trusted to hold. The point of formatting at
		 * all is that the stored string does not depend on the precision ini
		 * setting of whatever host the plugin lands on.
		 *
		 * @var int
		 */
		public const COORDINATE_DECIMALS = 7;

		/**
		 * Where locations are stored; the only class that knows where that is.
		 *
		 * @var Store_Repository|null
		 */
		private ?Store_Repository $repository;

		/**
		 * Where addresses become coordinates.
		 *
		 * Built on first use, so a save that does not geocode never constructs
		 * one — and, more to the point, a front-end request that never saves
		 * anything does not either.
		 *
		 * @var Geocoder|null
		 */
		private ?Geocoder $geocoder;

		/**
		 * Builds the admin screen over a repository and a geocoder.
		 *
		 * Both default to the production ones, the same arrangement
		 * Rest_Controller uses and for the same reason: Store_Repository and
		 * Geocoder are final, so a test passes a real one built over its own
		 * seams — a stub clock and sleeper — rather than a double.
		 *
		 * The repository is passed in by Plugin::boot() rather than built here,
		 * so that the object this class saves through and the object whose cache
		 * the invalidation hooks flush are one object, with one memo and one
		 * once-per-request guard between them.
		 *
		 * @param Store_Repository|null $repository Where locations are stored.
		 * @param Geocoder|null         $geocoder   Where addresses come from; built on first use when omitted.
		 */
		public function __construct( ?Store_Repository $repository = null, ?Geocoder $geocoder = null ) {
			$this->repository = $repository;
			$this->geocoder   = $geocoder;
		}

		/**
		 * Declares the box. Hooked to add_meta_boxes_slosm_store.
		 *
		 * The type-specific hook rather than the generic add_meta_boxes, so no
		 * other post type on the site grows a Location box and no callback here
		 * has to ask what screen it is on.
		 *
		 * 'normal' and 'high': an address is what a location mostly is, so it
		 * belongs above the editor rather than in the sidebar, where a
		 * twelve-field form in a 280-pixel column is unusable.
		 *
		 * @param mixed $post The location being edited; unused, the box is the same for all of them.
		 * @return void
		 */
		public function add_meta_box( $post = null ): void {
			add_meta_box(
				self::META_BOX_ID,
				__( 'Location', 'store-locator-for-openstreetmap' ),
				array( $this, 'render' ),
				Post_Type::POST_TYPE,
				'normal',
				'high'
			);
		}

		/**
		 * Prints the box: three columns, the coordinates, and the nonce.
		 *
		 * Everything printed is escaped at the point it is printed — esc_attr()
		 * inside an attribute, esc_textarea() inside the textarea, esc_html()
		 * for text — which is the other half of the contract Store states: this
		 * class sanitises on the way in and escapes on the way out, and Store
		 * does neither in between.
		 *
		 * The stored values are read through the repository rather than with
		 * get_post_meta(). Where a field lives is the repository's knowledge and
		 * a second copy of the mapping here would be a second thing to keep in
		 * step; the class docblock of Store_Repository states the rule.
		 *
		 * @param mixed $post The location being edited.
		 * @return void
		 */
		public function render( $post = null ): void {
			if ( ! is_object( $post ) ) {
				return;
			}

			$post_id = isset( $post->ID ) && is_scalar( $post->ID ) ? (int) $post->ID : 0;
			$record  = $this->repository()->to_store( $post )->to_full_array();

			$this->print_messages( $post_id );

			wp_nonce_field( self::NONCE_ACTION . $post_id, self::NONCE_FIELD, false, true );

			echo '<div class="slosm-metabox">';

			$this->print_column( __( 'Address', 'store-locator-for-openstreetmap' ), self::ADDRESS_FIELDS, $record );
			$this->print_column( __( 'Contact', 'store-locator-for-openstreetmap' ), self::CONTACT_FIELDS, $record );
			$this->print_hours( $record );

			echo '</div>';

			$this->print_coordinates( $post_id, $record );
		}

		/**
		 * Saves the box. Hooked to save_post_slosm_store at SAVE_PRIORITY.
		 *
		 * Five guards before anything is written, and only the first two are
		 * about security in the usual sense.
		 *
		 * The nonce field's *presence* is the one that matters most often, and
		 * it is not a security check at all — it is what tells this handler that
		 * the request it is running in contains this form. save_post fires for
		 * the block editor's own REST save, for WP-CLI, for importers and for
		 * every plugin that calls wp_update_post(). On all of those $_POST holds
		 * none of these fields, and a handler without this guard writes twelve
		 * empty strings over a complete location, every time, with nothing to
		 * announce it. That is the fastest way to empty a site's locator that
		 * this plugin contains.
		 *
		 * The verification is a truthiness check and not `true === `.
		 * wp_verify_nonce() returns int 1, int 2 or false — never true — and 2
		 * means a nonce from the previous twelve-hour tick. A handler comparing
		 * against true silently refuses every save from a form that has been
		 * open overnight, and the editor sees their changes vanish with no
		 * message. Verified in wp-includes/pluggable.php lines 2493-2501 of
		 * WordPress 6.9.1.
		 *
		 * DOING_AUTOSAVE is turned away, and it is defence in depth rather than
		 * a known path: the classic editor's autosave posts the title, the
		 * content and the excerpt and not this form, and the block editor's goes
		 * to the REST autosave route, so neither carries the nonce this handler
		 * needs. What the guard buys is the site whose editor plugin posts the
		 * whole form on autosave — one Nominatim request per minute while
		 * somebody types an address, against a service that allows one per
		 * second and bans for less.
		 *
		 * There is deliberately no wp_is_post_revision() check. An autosave of a
		 * published location is stored as a post of type 'revision', so the
		 * type-specific hook this is registered on cannot fire for it; the same
		 * reasoning is written out in Plugin::invalidate_on_save().
		 *
		 * The assumption this handler rests on, stated so that the next task
		 * does not break it by accident
		 * ------------------------------------------------------------------
		 * Past the guards, this method writes *every* field it knows about,
		 * taking an absent input as an empty value. That is correct for this box
		 * and for nothing else, and it is correct here only because the nonce
		 * field and the twelve inputs always travel together: both editors post
		 * the whole box or none of it.
		 *
		 * Any later screen that reuses this nonce with a partial form breaks the
		 * assumption silently and expensively. Quick Edit and Bulk Edit are the
		 * obvious candidates — Task 19 adds the list table they live on, and
		 * Task 20 a bulk action over selected locations — and a partial form
		 * carrying this nonce would blank every field it did not include, on
		 * every location in the selection, with nothing to announce it.
		 *
		 * Such a screen needs its own nonce action and its own handler, or this
		 * one needs to learn to write only the keys that are present. The first
		 * is cheaper and is the reason NONCE_ACTION is a constant with the post
		 * id appended rather than a string typed twice.
		 *
		 * @param mixed $post_id Post id.
		 * @param mixed $post    The location, as save_post hands it over.
		 * @return void
		 */
		public function save( $post_id, $post = null ): void {
			$post_id = is_scalar( $post_id ) ? (int) $post_id : 0;

			if ( 1 > $post_id ) {
				return;
			}

			// Unslashed and sanitised in the same expression that reads it,
			// which is what the sniff asks for and is not ceremony: a nonce is
			// a short alphanumeric string, so both are no-ops on a real one
			// and a refusal on anything else. An array arrives as '' and is
			// turned away by the check below.
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- this is the nonce check.
			$submitted_nonce = isset( $_POST[ self::NONCE_FIELD ] )
				? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) )
				: '';

			if ( '' === $submitted_nonce ) {
				return;
			}

			if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $submitted_nonce ) ), self::NONCE_ACTION . $post_id ) ) {
				return;
			}

			if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
				return;
			}

			// The meta capability, with the post id. 'edit_post' without it is
			// 'edit_posts' in disguise: it answers "may this user edit
			// something", which a contributor can, about a location somebody
			// else owns.
			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				return;
			}

			// save_post always passes it; a caller that fired the hook by hand
			// might not, and to_store() needs the row rather than the id.
			if ( ! is_object( $post ) ) {
				return;
			}

			$stored = $this->repository()->to_store( $post )->to_full_array();
			$clean  = self::clean( $this->submitted(), $stored );

			$this->repository()->save_fields( $post_id, $this->storable( $clean ) );

			$messages = $clean['messages'];

			if ( self::should_geocode( $stored, $clean['fields'], $clean['lat'], $clean['lng'], $clean['lat_locked'] ) ) {
				$messages[] = $this->geocode_into( $post_id, self::address_query( $clean['fields'] ) );
			}

			$this->remember( $post_id, $messages );
		}

		/**
		 * Whether this save should ask a geocoding service where the shop is.
		 *
		 * Pure, static and tested on its own, because every way of getting it
		 * wrong is expensive in a different direction and none of them shows up
		 * as an error anywhere:
		 *
		 * - Asking on every save spends the public service's courtesy limit on
		 *   saves that changed a phone number, and replaces the pin an editor
		 *   dragged into the right doorway with whatever the street address
		 *   resolves to.
		 * - Never asking leaves a location with no coordinates off the map. The
		 *   payload simply skips it; there is no error state.
		 * - Asking about a locked location is the only one that destroys work.
		 *
		 * The order of the four rules is itself the specification:
		 *
		 * 1. A lock beats everything, including missing coordinates and a
		 *    changed address. The only thing that sets it is a person placing a
		 *    pin, and a person beats an address.
		 * 2. An address that is not there is nothing to look up. Opening Add New
		 *    Location and pressing Publish would otherwise spend a request and
		 *    answer with an error about a field the editor has not reached.
		 * 3. No coordinates means look them up, whatever the address did. This
		 *    is the case a "did the address change" test on its own misses
		 *    entirely, and it is the common one: a location imported without
		 *    coordinates never changes its address again.
		 * 4. Otherwise, only a changed component. has_coordinates() is asked
		 *    rather than re-implemented, so that 0,0 — what a failed lookup
		 *    leaves behind, and a point in the Gulf of Guinea — counts as
		 *    unplaced here exactly as it does on the map.
		 *
		 * The address is read from $after for rules 2 and 4, not from $before.
		 * An editor emptying the address fields has changed something and still
		 * has nothing to look up; reading $before would send the deleted address
		 * to Nominatim and write its answer over the location.
		 *
		 * @param array      $before Address components as this save found them.
		 * @param array      $after  Address components as this save leaves them.
		 * @param float|null $lat    Latitude as this save leaves it.
		 * @param float|null $lng    Longitude as this save leaves it.
		 * @param bool       $locked Whether the coordinates were placed by hand.
		 * @return bool
		 */
		public static function should_geocode( array $before, array $after, ?float $lat, ?float $lng, bool $locked ): bool {
			if ( $locked ) {
				return false;
			}

			if ( '' === self::address_query( $after ) ) {
				return false;
			}

			if ( ! Store::from_array(
				array(
					'lat' => $lat,
					'lng' => $lng,
				)
			)->has_coordinates() ) {
				return true;
			}

			foreach ( self::ADDRESS_FIELDS as $field ) {
				if ( self::text( $before, $field ) !== self::text( $after, $field ) ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * The address components, joined into one query.
		 *
		 * Empty components are skipped rather than joined, because ", , , , , "
		 * is a string an emptiness check calls an address and Nominatim calls
		 * nothing at all. A component holding only whitespace is a component an
		 * editor cleared.
		 *
		 * Anything that is not an address component is ignored, so the whole
		 * submitted row can be handed here without a phone number ending up in
		 * a geocoding query.
		 *
		 * The country goes into the query rather than into geocode()'s $country
		 * argument. That argument becomes Nominatim's countrycodes parameter,
		 * which takes ISO 3166-1 alpha-2 codes; this field holds whatever an
		 * editor typed, and "Polska" as a country code restricts the search to
		 * a country that does not exist. In the query string it is simply part
		 * of the address, which is what it is.
		 *
		 * @param array $fields Field values, keyed by field name.
		 * @return string
		 */
		public static function address_query( array $fields ): string {
			$parts = array();

			foreach ( self::ADDRESS_FIELDS as $field ) {
				$value = trim( self::text( $fields, $field ) );

				if ( '' !== $value ) {
					$parts[] = $value;
				}
			}

			return implode( ', ', $parts );
		}

		/**
		 * One coordinate, read the way a person meant it.
		 *
		 * Returns the value, a status and what was typed, because the caller
		 * needs all three: the value to store, the status to decide whether to
		 * keep the old one, and the typed string to quote back.
		 *
		 * The statuses:
		 *
		 * - 'empty'        — nothing was typed. Not an error; it is how an
		 *                    editor asks for the address to be looked up again.
		 * - 'ok'           — a number, on the earth.
		 * - 'normalised'   — a comma decimal, read as a dot. The class docblock
		 *                    has why this is normalised rather than refused, and
		 *                    why the pattern is as narrow as it is.
		 * - 'not_a_number' — refused.
		 * - 'out_of_range' — a number, not on the earth. Refused rather than
		 *                    clamped: clamping invents a point at the pole and
		 *                    calls it the shop.
		 *
		 * is_numeric() rather than a cast, for the reason Store gives: a cast
		 * answers "what number is this closest to", which for a non-number is
		 * always a real place on the map and never the right one.
		 *
		 * @param string $raw   What was typed.
		 * @param float  $limit Largest absolute value that is on the earth.
		 * @return array With 'value', 'status' and 'typed'.
		 */
		public static function parse_coordinate( string $raw, float $limit ): array {
			$typed = trim( $raw );

			if ( '' === $typed ) {
				return array(
					'value'  => null,
					'status' => 'empty',
					'typed'  => $typed,
				);
			}

			$status = 'ok';
			$value  = $typed;

			// Digits, one comma, digits. A comma cannot be a thousands
			// separator in a number bounded by 180, so in this field that has
			// exactly one reading. '52.2297, 21.0122' — a whole pair pasted into
			// one box — does not match, and falls through to be refused.
			if ( preg_match( '/^[+-]?[0-9]+,[0-9]+$/', $typed ) ) {
				$value  = str_replace( ',', '.', $typed );
				$status = 'normalised';
			}

			if ( ! is_numeric( $value ) ) {
				return array(
					'value'  => null,
					'status' => 'not_a_number',
					'typed'  => $typed,
				);
			}

			$number = (float) $value;

			if ( abs( $number ) > $limit ) {
				return array(
					'value'  => null,
					'status' => 'out_of_range',
					'typed'  => $typed,
				);
			}

			return array(
				'value'  => $number,
				'status' => $status,
				'typed'  => $typed,
			);
		}

		/**
		 * Everything a submitted row becomes: clean fields, coordinates, a lock
		 * and whatever the editor has to be told.
		 *
		 * Static and given both sides — what was submitted and what was already
		 * there — so the whole decision is one function with no hooks, no
		 * superglobals and no database in it.
		 *
		 * A refused coordinate keeps the stored one rather than clearing it. One
		 * bad keystroke should not throw away a pin that was right, and clearing
		 * would additionally hand the location to the geocoder, which is a
		 * second silent change on top of the first.
		 *
		 * Half a pair is refused the same way, and that one is not a nicety. A
		 * latitude with no longitude cannot be drawn, so the location leaves the
		 * map; it also differs from what was stored, so it would set the lock;
		 * and a locked location is never looked up again. One Backspace in one
		 * field would make a location silently unmappable for ever, and the box
		 * would then explain that its coordinates were set by hand. Both values
		 * go back to what they were, and the editor is told that a location
		 * needs both.
		 *
		 * The exception is a pair that was already half — an import that wrote a
		 * latitude and no longitude. Submitting it unchanged is not a change, so
		 * it is left alone silently rather than complained about on every save.
		 *
		 * @param array $raw    Submitted values, unslashed, keyed by field name.
		 * @param array $stored The location as it is now; a Store record.
		 * @return array With 'fields', 'lat', 'lng', 'lat_locked' and 'messages'.
		 */
		public static function clean( array $raw, array $stored ): array {
			$messages = array();
			$fields   = array();

			foreach ( self::ADDRESS_FIELDS as $field ) {
				$fields[ $field ] = sanitize_text_field( self::text( $raw, $field ) );
			}

			foreach ( self::HOURS_FIELDS as $field ) {
				$fields[ $field ] = sanitize_textarea_field( self::text( $raw, $field ) );
			}

			// Over the list, not over three field names written out. The three
			// this plugin has today need three different sanitisers, so the loop
			// still branches — but a fourth added to CONTACT_FIELDS is rendered,
			// submitted and *saved*, rather than rendered, submitted and
			// silently dropped by a clean() that had never heard of it.
			foreach ( self::CONTACT_FIELDS as $field ) {
				list( $value, $message ) = self::clean_contact( $field, self::text( $raw, $field ) );

				$fields[ $field ] = $value;

				if ( null !== $message ) {
					$messages[] = $message;
				}
			}

			$coordinates = array();

			foreach ( array(
				'lat' => self::LAT_LIMIT,
				'lng' => self::LNG_LIMIT,
			) as $field => $limit ) {
				$parsed = self::parse_coordinate( self::text( $raw, $field ), $limit );
				$kept   = $parsed['value'];

				if ( 'not_a_number' === $parsed['status'] || 'out_of_range' === $parsed['status'] ) {
					$kept = self::stored_coordinate( $stored, $field );
				}

				$coordinates[ $field ] = $kept;

				$message = self::coordinate_message( $field, $parsed );

				if ( null !== $message ) {
					$messages[] = $message;
				}
			}

			$was = array(
				'lat' => self::stored_coordinate( $stored, 'lat' ),
				'lng' => self::stored_coordinate( $stored, 'lng' ),
			);

			if ( self::is_half_a_pair( $coordinates ) && ! self::same_pair( $coordinates, $was ) ) {
				$coordinates = $was;

				$messages[] = self::message(
					__( 'A location needs both a latitude and a longitude, so the previous pair was kept. Empty both fields to look the address up again.', 'store-locator-for-openstreetmap' )
				);
			}

			return array(
				'fields'     => $fields,
				'lat'        => $coordinates['lat'],
				'lng'        => $coordinates['lng'],
				'lat_locked' => self::locked(
					$coordinates,
					$was,
					$stored,
					self::lookup_pair( self::text( $raw, self::LOOKUP_FIELDS[0] ) )
				),
				'messages'   => $messages,
			);
		}

		/**
		 * One contact field, cleaned the way its own shape needs.
		 *
		 * Three shapes, three sanitisers, and none of them is interchangeable
		 * with another: an email has to be validated rather than stripped, a url
		 * must never meet sanitize_text_field() because that deletes every
		 * percent escape in it, and a phone number is ordinary text.
		 *
		 * A field this method does not recognise is treated as text rather than
		 * dropped. That is the fallback that makes adding a contact field a
		 * one-line change in CONTACT_FIELDS instead of a change in two places
		 * with a silent data loss between them.
		 *
		 * @param string $field Field name.
		 * @param string $raw   What was typed, unslashed.
		 * @return array The value, and a message or null.
		 */
		private static function clean_contact( string $field, string $raw ): array {
			if ( 'email' === $field ) {
				return self::clean_email( $raw );
			}

			if ( 'url' === $field ) {
				return self::clean_url( $raw );
			}

			return array( sanitize_text_field( $raw ), null );
		}

		/**
		 * An email address, or an empty string and a reason.
		 *
		 * is_email() answers string|false and never true, so the check below is
		 * a truthiness check; wp-includes/formatting.php line 3633 of WordPress
		 * 6.9.1 returns the address itself on the way out. It also refuses a
		 * domain with no dot, so "kontakt@sklep" is not an email address to
		 * WordPress however willing a mail server might be.
		 *
		 * A stored non-address becomes a mailto: link that goes nowhere, which
		 * is worse than an empty field, so it is emptied and said out loud.
		 *
		 * @param string $raw What was typed.
		 * @return array The address, and a message or null.
		 */
		private static function clean_email( string $raw ): array {
			$email = sanitize_text_field( $raw );

			if ( '' === $email || is_email( $email ) ) {
				return array( $email, null );
			}

			return array(
				'',
				self::message(
					sprintf(
						/* translators: %s: the address an editor typed. */
						__( '“%s” is not an email address, so the email was left empty.', 'store-locator-for-openstreetmap' ),
						$email
					)
				),
			);
		}

		/**
		 * One stored coordinate as a float, or null.
		 *
		 * Store has already turned anything unreadable into null, so this is a
		 * shape check rather than a parse.
		 *
		 * @param array  $stored The location as it is now.
		 * @param string $field  'lat' or 'lng'.
		 * @return float|null
		 */
		private static function stored_coordinate( array $stored, string $field ): ?float {
			return isset( $stored[ $field ] ) && is_float( $stored[ $field ] ) ? $stored[ $field ] : null;
		}

		/**
		 * Whether exactly one of the two coordinates is missing.
		 *
		 * @param array $pair Keyed lat and lng.
		 * @return bool
		 */
		private static function is_half_a_pair( array $pair ): bool {
			return ( null === $pair['lat'] ) !== ( null === $pair['lng'] );
		}

		/**
		 * Whether two coordinate pairs are the same place.
		 *
		 * @param array $one   Keyed lat and lng.
		 * @param array $other Keyed lat and lng.
		 * @return bool
		 */
		private static function same_pair( array $one, array $other ): bool {
			return self::same_coordinate( $one['lat'], $other['lat'] )
				&& self::same_coordinate( $one['lng'], $other['lng'] );
		}

		/**
		 * Whether two coordinates are the same to the precision this class stores.
		 *
		 * Not float identity, and the difference is a bug rather than a
		 * subtlety. The box renders a stored coordinate through
		 * coordinate_string(), which rounds to COORDINATE_DECIMALS, so a record
		 * holding 52.22970123456 — from an import, or from Task 20's bulk writer
		 * if it ever formats differently — is *shown* as 52.2297012. The next
		 * save of any field posts that rounded value back, float identity calls
		 * it a change, the location locks itself, and no lookup ever touches it
		 * again. Silently, and permanently.
		 *
		 * Comparing at the precision that is actually stored makes the round
		 * trip through the form a no-op, which is what it looks like to the
		 * person doing it.
		 *
		 * @param float|null $one   One coordinate.
		 * @param float|null $other The other.
		 * @return bool
		 */
		private static function same_coordinate( ?float $one, ?float $other ): bool {
			if ( null === $one || null === $other ) {
				return $one === $other;
			}

			return self::coordinate_string( $one ) === self::coordinate_string( $other );
		}

		/**
		 * One thing to tell the editor, and how loudly.
		 *
		 * A warning by default, because most of these are a value this class
		 * refused. The lookup result is the exception and says so.
		 *
		 * @param string $text    What to say.
		 * @param bool   $warning Whether it is a refusal rather than a note.
		 * @return array
		 */
		private static function message( string $text, bool $warning = true ): array {
			return array(
				'text'    => $text,
				'warning' => $warning,
			);
		}

		/**
		 * Whether the coordinates this save leaves were put there by a person.
		 *
		 * Three states, and the middle one is the one that is easy to get wrong.
		 * Every save posts the coordinate fields whether or not anybody touched
		 * them, so "the form carried coordinates" is not evidence of anything.
		 * What is evidence is that they differ from the ones that were there.
		 *
		 * Emptying both clears the lock. It is how an editor asks for the
		 * address to be looked up again without a button, and a lock left behind
		 * would pin the location to coordinates that are no longer there.
		 * Emptying only one never reaches here as a change, because clean() has
		 * already put the pair back; see its docblock for what that guard is
		 * worth.
		 *
		 * "Differ" is same_pair(), which compares at the precision this class
		 * stores rather than by float identity. Float identity here is a lock
		 * that sets itself on a location nobody touched.
		 *
		 * The fourth argument is Task 18's, and it is the second way out of a
		 * lock. $lookup is the pair the picker's "look up from the address"
		 * button wrote, carried back in a hidden field; a submitted pair that
		 * *is* that pair was put there by the geocoder and not by a hand, so it
		 * does not lock and it clears a lock that was already set. That is what
		 * makes the button the recovery path for a pin placed by mistake —
		 * including the one clean()'s half-pair guard leaves behind.
		 *
		 * It is checked before "differ", which is the only order that works: a
		 * looked-up pair differs from the stored one by definition, so testing
		 * it afterwards would never be reached. And it is checked before the
		 * fallthrough, which is what lets it clear a lock rather than merely
		 * decline to set one.
		 *
		 * A forged or stale value costs nothing, because it has to *match* the
		 * pair being submitted to mean anything. The worst an editor can do
		 * with it is ask for their own location to be geocoded again, which is
		 * what the button in front of them does anyway.
		 *
		 * @param array      $coordinates The pair this save leaves, keyed lat and lng.
		 * @param array      $was         The pair it found, keyed lat and lng.
		 * @param array      $stored      The location as it was, for its lock.
		 * @param array|null $lookup      The pair a lookup produced, keyed lat and lng, or null.
		 * @return bool
		 */
		private static function locked( array $coordinates, array $was, array $stored, ?array $lookup = null ): bool {
			if ( null === $coordinates['lat'] && null === $coordinates['lng'] ) {
				return false;
			}

			if ( null !== $lookup && self::same_pair( $coordinates, $lookup ) ) {
				return false;
			}

			if ( ! self::same_pair( $coordinates, $was ) ) {
				return true;
			}

			return ! empty( $stored['lat_locked'] );
		}

		/**
		 * The pair the lookup button says it wrote, or null.
		 *
		 * "lat,lng", and nothing else is entertained. Both halves go through
		 * parse_coordinate() against their own limits, so the value is read by
		 * exactly the code that reads the visible fields — which is what makes
		 * "the submitted pair is the pair the lookup produced" a comparison
		 * between two things of the same kind rather than between a float and a
		 * string.
		 *
		 * Anything else is null and the lock behaves as it did before Task 18:
		 * a lone number, three numbers, an empty string, a half that is not a
		 * number, a half outside its own limit.
		 *
		 * A comma decimal is not on that list and the earlier version of this
		 * docblock said it was, which was a reason that sounded right and was
		 * not the operative one. The split is on commas, so '52,4' does not
		 * reach parse_coordinate() as one value at all — it arrives as the pair
		 * ( 52, 4 ), which is a real place in the Gulf of Guinea. '52,4064' is
		 * refused, and by the longitude limit rather than by anything about
		 * commas.
		 *
		 * That is harmless, and it is worth saying why rather than tightening
		 * it: this value does nothing unless it *equals the pair being
		 * submitted*. An editor who gets ( 52, 4 ) into both the hidden field
		 * and the two visible ones has asked for their own location to be
		 * geocoded again, which is what the button in front of them does.
		 *
		 * @param string $raw What the hidden field carried.
		 * @return array|null Keyed lat and lng, or null.
		 */
		private static function lookup_pair( string $raw ): ?array {
			$parts = explode( ',', trim( $raw ) );

			if ( 2 !== count( $parts ) ) {
				return null;
			}

			$lat = self::parse_coordinate( $parts[0], self::LAT_LIMIT );
			$lng = self::parse_coordinate( $parts[1], self::LNG_LIMIT );

			if ( null === $lat['value'] || null === $lng['value'] ) {
				return null;
			}

			return array(
				'lat' => $lat['value'],
				'lng' => $lng['value'],
			);
		}

		/**
		 * What to tell the editor about one coordinate, or nothing.
		 *
		 * @param string $field  'lat' or 'lng'.
		 * @param array  $parsed What parse_coordinate() made of it.
		 * @return array|null
		 */
		private static function coordinate_message( string $field, array $parsed ): ?array {
			$label = 'lat' === $field
				? __( 'Latitude', 'store-locator-for-openstreetmap' )
				: __( 'Longitude', 'store-locator-for-openstreetmap' );

			$limit = 'lat' === $field ? self::LAT_LIMIT : self::LNG_LIMIT;

			if ( 'normalised' === $parsed['status'] ) {
				return self::message( sprintf(
					/* translators: 1: field name, 2: the value an editor typed, 3: the value it was read as. */
					__( '%1$s “%2$s” was read as %3$s. Use a dot for the decimal point.', 'store-locator-for-openstreetmap' ),
					$label,
					$parsed['typed'],
					self::coordinate_string( (float) $parsed['value'] )
				) );
			}

			if ( 'not_a_number' === $parsed['status'] ) {
				return self::message( sprintf(
					/* translators: 1: field name, 2: the value an editor typed. */
					__( '%1$s “%2$s” is not a single number, so the previous value was kept.', 'store-locator-for-openstreetmap' ),
					$label,
					$parsed['typed']
				) );
			}

			if ( 'out_of_range' === $parsed['status'] ) {
				return self::message( sprintf(
					/* translators: 1: field name, 2: the value an editor typed, 3: the largest value that is on the earth. */
					__( '%1$s “%2$s” is outside −%3$s to %3$s, so the previous value was kept.', 'store-locator-for-openstreetmap' ),
					$label,
					$parsed['typed'],
					self::coordinate_string( $limit )
				) );
			}

			return null;
		}

		/**
		 * A website, or an empty string and a reason.
		 *
		 * esc_url_raw() is given an explicit protocol list, because its default
		 * is twenty-two protocols and not two; the class docblock has the list
		 * and the citation.
		 *
		 * A value with no scheme gets https:// here, and the reason is simply
		 * that https is the right default for a bare domain in 2026. An earlier
		 * version of this comment said it was to paper over a difference between
		 * WordPress versions, and that was a plausible-sounding rationale that
		 * does not survive being traced: esc_url() picks the scheme with
		 * `array_first( $protocols )`, and the list handed to it here starts
		 * with 'http', so 6.9 would prepend http:// exactly as 6.0 does. There
		 * is no divergence to paper over. Doing it here is how the field means
		 * https rather than how it means the same thing everywhere.
		 *
		 * A relative reference is refused rather than stored. esc_url() returns
		 * '/path', '#anchor', '?a=b' and '//host' untouched without consulting
		 * the protocol list at all — `if ( '/' === $url[0] )` short-circuits the
		 * check — so leaving them alone would make "an allowlist of two" a claim
		 * about half the values this field accepts. A location's website is
		 * somewhere a visitor goes, which is an absolute address.
		 *
		 * Nothing else touches the value. sanitize_text_field() would delete
		 * every %xx in it.
		 *
		 * @param string $raw What was typed.
		 * @return array The url, and a message or null.
		 */
		private static function clean_url( string $raw ): array {
			$typed = trim( $raw );

			if ( '' === $typed ) {
				return array( '', null );
			}

			$candidate = $typed;
			$relative  = in_array( $candidate[0], array( '/', '#', '?' ), true );

			if ( ! $relative && false === strpos( $candidate, ':' ) ) {
				$candidate = 'https://' . $candidate;
			}

			$url = $relative ? '' : esc_url_raw( $candidate, self::URL_PROTOCOLS );

			if ( '' === $url ) {
				return array(
					'',
					self::message(
						sprintf(
							/* translators: %s: the address an editor typed. */
							__( '“%s” is not a web address this plugin will link to, so the website was left empty. Use http:// or https://.', 'store-locator-for-openstreetmap' ),
							$typed
						)
					),
				);
			}

			return array( $url, null );
		}

		/**
		 * The submitted row, unslashed and nothing more.
		 *
		 * Unslashed because WordPress slashes $_POST before a plugin sees it —
		 * wp_magic_quotes() at wp-includes/load.php line 1288 — so the text an
		 * editor actually typed is one stripslashes() away. Sanitising happens
		 * in clean(), on the real text; doing it in the other order sanitises a
		 * string nobody typed.
		 *
		 * Anything that is not a scalar becomes an empty string. A field posted
		 * as an array — trivially arranged by hand — would otherwise reach
		 * sanitize_text_field(), which returns '' for one anyway, and
		 * esc_url_raw(), which casts it and warns.
		 *
		 * @return array Field name to raw string.
		 */
		private function submitted(): array {
			$raw = array();

			foreach ( self::fields() as $field ) {
				// wp_unslash in the same expression as the read. It was on the
				// line below before, which does the same thing and which the
				// sniff cannot see: MissingUnslash looks at the statement that
				// touches $_POST, not at what happens to the value afterwards.
				// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- save() verified the nonce, and clean() sanitises per field on the unslashed text; sanitising here would sanitise a string nobody typed.
				$value = isset( $_POST[ self::FIELD_PREFIX . $field ] ) ? wp_unslash( $_POST[ self::FIELD_PREFIX . $field ] ) : '';

				$raw[ $field ] = is_scalar( $value ) ? (string) $value : '';
			}

			return $raw;
		}

		/**
		 * What clean() produced, in the shape storage wants.
		 *
		 * Coordinates become strings, and an absent one becomes '' rather than a
		 * deleted row: Store::from_array() reads '' as null, and one write per
		 * field is one code path rather than two.
		 *
		 * @param array $clean What clean() returned.
		 * @return array Field name to value.
		 */
		private function storable( array $clean ): array {
			$storable = $clean['fields'];

			$storable['lat']        = null === $clean['lat'] ? '' : self::coordinate_string( $clean['lat'] );
			$storable['lng']        = null === $clean['lng'] ? '' : self::coordinate_string( $clean['lng'] );
			$storable['lat_locked'] = $clean['lat_locked'] ? '1' : '';

			return $storable;
		}

		/**
		 * Looks an address up and writes what came back — or repeats why it did
		 * not, without asking again.
		 *
		 * Returns what to tell the editor in every case. A failure changes
		 * nothing: the coordinates that were there stay, because the service
		 * being unreachable is not evidence about where the shop is.
		 *
		 * The remembered failure is the part that took a measurement to justify.
		 * Geocoder::geocode() caches successful points for thirty days and
		 * deliberately never caches a failure — a 429 or a timeout written down
		 * for a month would be a plugin that stopped working — and
		 * should_geocode() returns true whenever a location has no coordinates.
		 * Put together, an address the service cannot resolve is looked up again
		 * on *every save of every field, for ever*: four saves measured as four
		 * blocking requests and three courtesy sleeps, on the one location whose
		 * saves are already the slowest. The same loop catches the 0,0 that a
		 * failed lookup leaves behind, which has_coordinates() rightly calls
		 * unplaced.
		 *
		 * So a failure is remembered here rather than in the geocoder, keyed on
		 * the query and not on the location: five branches on one unresolvable
		 * street share one answer, and an edited address asks again immediately
		 * because it is a different key. The hour is the compromise between the
		 * two ways of being wrong — long enough that an editor fixing the phone
		 * number four times does not spend four requests, short enough that a
		 * service outage, a rate-limit block or a street that has just been added
		 * to OpenStreetMap heals without anybody being told to wait a month.
		 *
		 * The editor is told the same thing either way. A save that skipped the
		 * lookup still leaves a location unplaced, and silence about it would be
		 * the cache paying for itself with the one thing this box exists to
		 * provide.
		 *
		 * The geocoder's own message is quoted rather than translated into a
		 * house style. It already distinguishes "no place matched that address"
		 * from "the service refused the request", and an editor who can see the
		 * difference can act on it.
		 *
		 * @param int    $post_id Post id.
		 * @param string $query   The address, joined.
		 * @return array What to tell the editor.
		 */
		private function geocode_into( int $post_id, string $query ): array {
			// The site's country restriction, read once and used three times: the
			// lookup, the failure memory it reads, and the failure memory it
			// writes. Reading it three times would be three chances for a
			// mid-request change to make a failure be remembered under a key the
			// next save looks for somewhere else.
			$country = (string) Settings::get( 'country' );
			$failed  = get_transient( self::failure_key( $query, $country ) );

			if ( is_string( $failed ) && '' !== $failed ) {
				return self::message( $failed );
			}

			$point = $this->geocoder()->geocode( $query, $country );

			if ( is_wp_error( $point ) ) {
				$message = sprintf(
					/* translators: %s: why the lookup failed. */
					__( 'The address could not be looked up: %s', 'store-locator-for-openstreetmap' ),
					$point->get_error_message()
				);

				set_transient( self::failure_key( $query, $country ), $message, self::FAILURE_TTL );

				return self::message( $message );
			}

			$lat = self::coordinate_string( (float) $point['lat'] );
			$lng = self::coordinate_string( (float) $point['lng'] );

			// lat_locked is deliberately not written here. A lookup is not a
			// person placing a pin, and marking it as one would stop every later
			// lookup on a location nobody has ever touched by hand.
			$this->repository()->save_fields(
				$post_id,
				array(
					'lat' => $lat,
					'lng' => $lng,
				)
			);

			return self::message(
				sprintf(
					/* translators: 1: latitude, 2: longitude. */
					__( 'Coordinates were looked up from the address: %1$s, %2$s.', 'store-locator-for-openstreetmap' ),
					$lat,
					$lng
				),
				false
			);
		}

		/**
		 * Where a failed lookup for one address is remembered.
		 *
		 * Hashed, because the query is an address of any length and a transient
		 * name has to fit in an option name. Keyed on the address rather than on
		 * the location, so that several branches on one unresolvable street
		 * share the answer and an edited address asks again at once.
		 *
		 * Public since Task 20, and the widening is the point rather than a
		 * convenience — the same argument coordinate_string()'s docblock makes.
		 * Bulk_Geocode looks up a page of addresses at a second each, and an
		 * address this class has already found unresolvable must cost that run
		 * nothing; a second failure memory under a second key would mean a bulk
		 * run and a save re-asking each other's known-bad addresses for ever.
		 * One memory, keyed one way, in the class that owns the lifetime.
		 *
		 * **Task 21 is where the query alone stopped being enough, and this is
		 * the key it became.** The note that stood here said it: the query is
		 * the whole of what was asked only for as long as nothing else narrows
		 * the lookup, Geocoder::geocode() already takes a $country and builds
		 * its own cache key from the query *and* that restriction, and the two
		 * agreed by accident because nothing passed one. The settings screen
		 * passes one. A failure remembered under "pl" would otherwise suppress,
		 * for a whole FAILURE_TTL, the lookup that succeeds under "de", and
		 * tell the editor it failed for a reason no longer in force.
		 *
		 * The generation is the second half, and it is what makes the
		 * clear-caches button honest. A remembered failure is a cached answer
		 * like any other; an editor who has just corrected an address at the
		 * far end and pressed the button expects this plugin to try again, and
		 * a key that ignored the generation would go on refusing for the rest
		 * of the hour. Geocoder::generation() is the same counter its own cache
		 * keys carry, so one write clears both.
		 *
		 * Both parts go into the hash rather than into the name. A transient
		 * name has to fit in an option name, the query is an address of any
		 * length, and a generation appended after the md5 would be a second
		 * variable-length segment for no gain.
		 *
		 * @param string $query   The address, joined.
		 * @param string $country ISO 3166-1 alpha-2 codes the lookup was restricted to, or ''.
		 * @return string
		 */
		public static function failure_key( string $query, string $country = '' ): string {
			return self::FAILURE_PREFIX . md5( $query . '|' . $country . '|' . Geocoder::generation() );
		}

		/**
		 * A coordinate as a string that does not depend on the host's php.ini.
		 *
		 * (string) $float uses the precision setting, which is 14 by default and
		 * is not on every host. %.7F is explicit, and uppercase F is the
		 * locale-independent conversion — a locale using a comma for the decimal
		 * separator would otherwise write back exactly the value this class
		 * exists to reject.
		 *
		 * The trailing zeros come off, so 180 is stored as "180" rather than
		 * "180.0000000". The two rtrim() calls cannot eat a significant zero:
		 * the first stops at the decimal point.
		 *
		 * Public since Task 19, and the widening is the point rather than a
		 * convenience. The locations list prints a stored coordinate beside the
		 * warning that a location has none, and a second formatter there would
		 * show 52.2297000 on the list beside 52.2297 in the field — which reads
		 * as the list being wrong, and would be one more thing to keep in step
		 * with COORDINATE_DECIMALS the day that number moves. One formatter, in
		 * the class that owns the decimals.
		 *
		 * @param float $value Coordinate.
		 * @return string
		 */
		public static function coordinate_string( float $value ): string {
			$formatted = sprintf( '%.' . self::COORDINATE_DECIMALS . 'F', $value );

			return rtrim( rtrim( $formatted, '0' ), '.' );
		}

		/**
		 * Every field with a control in this box.
		 *
		 * Built from the five lists rather than written out a sixth time.
		 * lat_locked is not among them: it has no control, it is derived.
		 *
		 * LOOKUP_FIELDS is among them and is the one field here that storable()
		 * never writes. It is read so that clean() can tell a pin the geocoder
		 * found from a pin a person placed, and it is not stored because it
		 * describes one browser's last few seconds and nothing about the shop.
		 *
		 * @return string[]
		 */
		private static function fields(): array {
			return array_merge(
				self::ADDRESS_FIELDS,
				self::CONTACT_FIELDS,
				self::HOURS_FIELDS,
				self::COORDINATE_FIELDS,
				self::LOOKUP_FIELDS
			);
		}

		/**
		 * One value out of an array, as a string, whatever was in there.
		 *
		 * The same rule Store::text() follows: (string) on an array is a warning
		 * and the word "Array", and on most objects it is an Error.
		 *
		 * @param array  $data Values.
		 * @param string $key  Key.
		 * @return string
		 */
		private static function text( array $data, string $key ): string {
			return isset( $data[ $key ] ) && is_scalar( $data[ $key ] ) ? (string) $data[ $key ] : '';
		}

		/**
		 * The label an editor sees above one field.
		 *
		 * A closed list rather than a prettified field name, because "Address2"
		 * and "Zip" are not what anybody calls those, and because a label built
		 * from a key cannot be translated.
		 *
		 * @param string $field Field name.
		 * @return string
		 */
		private static function label( string $field ): string {
			$labels = array(
				'address'  => __( 'Street address', 'store-locator-for-openstreetmap' ),
				'address2' => __( 'Address line 2', 'store-locator-for-openstreetmap' ),
				'city'     => __( 'City', 'store-locator-for-openstreetmap' ),
				'state'    => __( 'Region', 'store-locator-for-openstreetmap' ),
				'zip'      => __( 'Postal code', 'store-locator-for-openstreetmap' ),
				'country'  => __( 'Country', 'store-locator-for-openstreetmap' ),
				'phone'    => __( 'Phone', 'store-locator-for-openstreetmap' ),
				'email'    => __( 'Email', 'store-locator-for-openstreetmap' ),
				'url'      => __( 'Website', 'store-locator-for-openstreetmap' ),
				'hours'    => __( 'Opening hours', 'store-locator-for-openstreetmap' ),
				'lat'      => __( 'Latitude', 'store-locator-for-openstreetmap' ),
				'lng'      => __( 'Longitude', 'store-locator-for-openstreetmap' ),
			);

			return $labels[ $field ] ?? $field;
		}

		/**
		 * Prints one of the three columns.
		 *
		 * @param string $heading Column heading.
		 * @param array  $fields  Fields in it.
		 * @param array  $record  The location as it is stored.
		 * @return void
		 */
		private function print_column( string $heading, array $fields, array $record ): void {
			echo '<div class="slosm-metabox__column">';
			echo '<h3 class="slosm-metabox__heading">' . esc_html( $heading ) . '</h3>';

			foreach ( $fields as $field ) {
				$this->print_text_field( $field, self::text( $record, $field ) );
			}

			echo '</div>';
		}

		/**
		 * Prints one labelled text input.
		 *
		 * type="text" for every one of them, including the email and the website,
		 * and that is a correction rather than laziness. type="url" carries
		 * HTML5 constraint validation, which requires an absolute url — so the
		 * classic editor's browser would refuse to submit the whole post for
		 * "sklep.pl", which is precisely the value clean_url() exists to accept
		 * and complete. type="email" is the same gate in front of the email
		 * check this class makes itself, and that check is the one that can
		 * explain what it did.
		 *
		 * The failure is worse than a refusal, because a metabox can be closed.
		 * A collapsed box makes its controls display:none, and a browser will
		 * not focus an invalid control it cannot show: Chrome logs "An invalid
		 * form control with name='slosm_url' is not focusable" and cancels the
		 * submit with no bubble anywhere. Update appears to do nothing at all.
		 *
		 * What is given up is the phone-keyboard hint on a mobile browser, on an
		 * admin screen almost nobody fills in from a phone. What is kept is that
		 * the server decides, once, and can say why.
		 *
		 * @param string $field Field name.
		 * @param string $value Stored value.
		 * @return void
		 */
		private function print_text_field( string $field, string $value ): void {
			$name = self::FIELD_PREFIX . $field;
			$type = 'text';

			echo '<p class="slosm-metabox__field">';
			echo '<label for="' . esc_attr( $name ) . '">' . esc_html( self::label( $field ) ) . '</label>';
			echo '<input type="' . esc_attr( $type ) . '" class="widefat" id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" />';
			echo '</p>';
		}

		/**
		 * Prints the opening-hours column.
		 *
		 * A textarea, because a week of opening hours is several lines and an
		 * input cannot hold one. The newlines are what sanitize_textarea_field()
		 * exists to keep and what esc_textarea() exists to print.
		 *
		 * @param array $record The location as it is stored.
		 * @return void
		 */
		private function print_hours( array $record ): void {
			$name = self::FIELD_PREFIX . 'hours';

			echo '<div class="slosm-metabox__column">';
			echo '<h3 class="slosm-metabox__heading">' . esc_html( __( 'Opening hours', 'store-locator-for-openstreetmap' ) ) . '</h3>';
			echo '<p class="slosm-metabox__field">';
			echo '<label for="' . esc_attr( $name ) . '">' . esc_html( __( 'One line per day', 'store-locator-for-openstreetmap' ) ) . '</label>';
			echo '<textarea class="widefat" rows="7" id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '">' . esc_textarea( self::text( $record, 'hours' ) ) . '</textarea>';
			echo '</p>';
			echo '</div>';
		}

		/**
		 * Prints the coordinate pair and what the lock currently says.
		 *
		 * The lock is shown and not editable. It is a consequence of what an
		 * editor does to the two fields above it, and a checkbox would be a
		 * second way to set it that has to agree with the first. The picker's
		 * "look up from the address" button is the deliberate way to clear it,
		 * and emptying both fields still does.
		 *
		 * @param int   $post_id The location being edited.
		 * @param array $record  The location as it is stored.
		 * @return void
		 */
		private function print_coordinates( int $post_id, array $record ): void {
			echo '<div class="slosm-metabox__coordinates">';
			echo '<h3 class="slosm-metabox__heading">' . esc_html( __( 'Coordinates', 'store-locator-for-openstreetmap' ) ) . '</h3>';

			foreach ( self::COORDINATE_FIELDS as $field ) {
				$value = null === $record[ $field ] ? '' : self::coordinate_string( (float) $record[ $field ] );

				$this->print_text_field( $field, $value );
			}

			$this->print_picker( $post_id );

			echo '<p class="description">';

			if ( ! empty( $record['lat_locked'] ) ) {
				echo esc_html( __( 'These coordinates were set by hand, so the address is not looked up again.', 'store-locator-for-openstreetmap' ) );
			} else {
				echo esc_html( __( 'Leave both empty to look the address up again. Use a dot for the decimal point.', 'store-locator-for-openstreetmap' ) );
			}

			echo '</p>';
			echo '</div>';
		}

		/**
		 * Prints the map, the lookup button and the field that ties them to the
		 * save — but only when the script that brings them to life is going to
		 * be on the page.
		 *
		 * The guard is not caution, it is the difference between a feature and
		 * a bordered empty rectangle with a button in it that does nothing.
		 * Metaboxes render inside the body, long after admin_enqueue_scripts
		 * has fired, so by the time this runs the question "is the picker
		 * script on this page" has a definite answer. On a screen where
		 * something dequeued the handle, the box is the twelve fields it was
		 * before Task 18 — which still work, because the coordinates are two
		 * text inputs and the picker is a convenience on top of them.
		 *
		 * The hidden field is printed with an empty value on every render, and
		 * never with what the last save saw. A lookup is something that
		 * happened in a browser, once; a value carried back into the form would
		 * tell the next save that a lookup it never made had placed the pin,
		 * which is precisely the one thing that silently unlocks a location
		 * somebody pinned by hand.
		 *
		 * The config is escaped like every other attribute in this class. There
		 * is nothing an editor typed in it — picker_config() says so and a case
		 * asserts it — and it is escaped anyway, because that argument stops
		 * being true the day somebody adds a key.
		 *
		 * @param int $post_id The location being edited.
		 * @return void
		 */
		private function print_picker( int $post_id ): void {
			if ( ! wp_script_is( Assets::SCRIPT_ADMIN, 'enqueued' ) ) {
				return;
			}

			$lookup = self::FIELD_PREFIX . self::LOOKUP_FIELDS[0];

			echo '<div class="slosm-metabox__picker" data-slosm-picker="'
				. esc_attr( (string) wp_json_encode( self::picker_config( $post_id ) ) ) . '">';

			echo '<div class="slosm-metabox__map"></div>';

			echo '<p class="slosm-metabox__actions">';
			echo '<button type="button" class="button slosm-metabox__lookup">'
				. esc_html( __( 'Look up from the address', 'store-locator-for-openstreetmap' ) )
				. '</button>';
			echo '</p>';

			echo '<input type="hidden" id="' . esc_attr( $lookup ) . '" name="' . esc_attr( $lookup ) . '" value="" />';

			echo '</div>';
		}

		/**
		 * Prints what the last save left to say, and forgets it.
		 *
		 * Inside the box rather than through admin_notices, which is where a
		 * message about these fields belongs and is also the only place both
		 * editors show it. Read once: a message that survived its own rendering
		 * would reappear on every later visit, describing a save nobody can
		 * remember making.
		 *
		 * Read once, except on the render nobody will ever see. Task 18 put a
		 * marker on the redirect of a block-editor metabox save so that the
		 * render it leads to can recognise itself; see mark_discarded_render()
		 * for why the marker has to be attached rather than detected, and the
		 * class docblock for what the whole arrangement does and does not
		 * achieve.
		 *
		 * Escaped, because these strings quote back what an editor typed.
		 *
		 * @param int $post_id Post id.
		 * @return void
		 */
		private function print_messages( int $post_id ): void {
			$key      = $this->notice_key( $post_id );
			$messages = get_transient( $key );

			if ( ! is_array( $messages ) || array() === $messages ) {
				return;
			}

			// The render the block editor is about to throw away. Printing
			// into it is free and harmless; *consuming* the message in it is
			// the bug, because the html this ends up in is discarded unread
			// and the next real page load finds nothing. Returning before both
			// leaves the message for a render somebody will see.
			if ( self::is_discarded_render() ) {
				return;
			}

			delete_transient( $key );

			// Two notices, not one, because these are two different things.
			// "Latitude 91 is outside −90 to 90" is a value this class refused;
			// "coordinates were looked up from the address" is a note about work
			// it did. Printing the second in a warning box tells an editor that
			// something went wrong when nothing did, which is the kind of noise
			// that makes people stop reading the box at all.
			foreach ( array( true, false ) as $warning ) {
				$texts = array();

				foreach ( $messages as $message ) {
					if ( ! is_array( $message ) || ! isset( $message['text'] ) || ! is_scalar( $message['text'] ) ) {
						continue;
					}

					if ( ! empty( $message['warning'] ) === $warning ) {
						$texts[] = (string) $message['text'];
					}
				}

				if ( array() === $texts ) {
					continue;
				}

				echo '<div class="notice ' . ( $warning ? 'notice-warning' : 'notice-info' ) . ' inline"><p>';
				echo esc_html( implode( ' ', $texts ) );
				echo '</p></div>';
			}
		}

		/**
		 * Leaves the messages where the next render of this box will find them.
		 *
		 * A transient rather than a query argument on the redirect, because the
		 * classic editor is the only one of the two where a redirect reaches a
		 * browser. In the block editor nothing reaches an editor at all: the
		 * class docblock traces why, and the short version is that the metabox
		 * save's own 302 is followed by fetch, renders this box server-side, and
		 * is discarded. Task 18 marks that render so it declines to consume the
		 * message, and says the coordinate messages on the screen before the
		 * save instead.
		 *
		 * An empty list deletes, and since Task 18 that has a consequence worth
		 * stating rather than leaving to be discovered
		 * ------------------------------------------------------------------
		 * A message can now outlive the save that wrote it: the marked render
		 * leaves it, and it waits for a page load. If the editor saves again
		 * before opening the screen, and that save has nothing to say, this
		 * deletes the earlier note unseen. The automatic geocode result is the
		 * message most exposed to it, which is the one that matters most.
		 *
		 * It is still deleted, and the alternative is worse in the case that is
		 * at least as common: save one refuses "52,2297" and says so, save two
		 * fixes it and says nothing, and a kept message would then warn about a
		 * value the field no longer holds. A save that has nothing to say has
		 * superseded whatever the one before it said — that is what "nothing to
		 * say" means about the state of the location now — so the last save
		 * wins, and the promise this task can honestly make is "the next page
		 * load after a save that had something to say", not "the next page
		 * load". A case pins it so it is a decision rather than a leak.
		 *
		 * @param int   $post_id  Post id.
		 * @param array $messages What to say.
		 * @return void
		 */
		private function remember( int $post_id, array $messages ): void {
			$key = $this->notice_key( $post_id );

			if ( array() === $messages ) {
				delete_transient( $key );

				return;
			}

			set_transient( $key, $messages, self::NOTICE_TTL );
		}

		/**
		 * Where one editor's messages about one location live.
		 *
		 * @param int $post_id Post id.
		 * @return string
		 */
		private function notice_key( int $post_id ): string {
			return self::NOTICE_PREFIX . $post_id . '_' . get_current_user_id();
		}

		/**
		 * Everything the picker script needs, and nothing it does not.
		 *
		 * WHAT THIS METHOD USED TO CLAIM, AND WHAT TASK 21 DID TO THE CLAIM
		 * =================================================================
		 * It said: static and taking no stored value, which is the security
		 * property this method is written to have rather than to happen to
		 * have — there is nothing an editor typed in here to escape. And it
		 * added that the escaping is done anyway, because "there is nothing
		 * dangerous in here today" is an argument that disappears the moment
		 * somebody adds a key.
		 *
		 * Task 21 is that moment. The tile url and its attribution are settings
		 * now, and this screen needs both: the picker draws a real map, and the
		 * ODbL wants the attribution on it. So there are three lines of defence
		 * rather than the sentence that used to be here, and they are worth
		 * naming separately because they fail differently:
		 *
		 * - Settings::sanitise() is what the values passed through on the way
		 *   in. The url is narrowed to http or https and to a character set
		 *   that cannot carry a quote or an angle bracket; the attribution is
		 *   assembled by Settings::attribution_html(), which escapes both of
		 *   its halves itself.
		 * - Only manage_options can write them, which on a single site is an
		 *   administrator who has unfiltered_html anyway. On multisite it is a
		 *   site administrator who does not, which is why the point above is
		 *   not merely belt and braces.
		 * - esc_attr() at the point this is printed, which a case asserts, and
		 *   which is now doing real work rather than standing by.
		 *
		 * What is in it: a route, a nonce, three field ids, a list of six more,
		 * four numbers, and the tile layer.
		 *
		 * The three numbers that are not the zoom are sent rather than restated
		 * in JavaScript, and each of them is a rule the *save* enforces:
		 *
		 * - decimals is COORDINATE_DECIMALS, which same_coordinate() compares
		 *   at. A picker writing more decimals than the save keeps would make
		 *   every drag differ from what comes back, and lock every location it
		 *   touched.
		 * - latLimit and lngLimit are the two limits, which are two numbers for
		 *   the reason LNG_LIMIT's docblock gives: one shared limit of 90
		 *   refuses half the planet and one of 180 accepts a latitude that
		 *   cannot exist.
		 *
		 * The nonce is the REST one, and it is the only credential here. The
		 * /geocode route is public, so on most sites the button would work
		 * without it; on a site that has closed the REST API to anonymous
		 * requests it would not, because rest_cookie_check_errors() sets the
		 * current user to 0 for a cookie that arrives without a nonce
		 * (wp-includes/rest-api.php lines 1134-1138 of WordPress 6.9.1). The
		 * screen is authenticated already, so sending it costs nothing and buys
		 * a button that works on a hardened site.
		 *
		 * The tile layer travels here rather than in Assets::admin_strings(),
		 * and assets/js/admin.js guessed the other way — it named `slosmAdminL10n`
		 * as the obvious carrier. It is not, for a reason that only shows up in
		 * the test suite: admin_strings() is a flat table of sentences, and
		 * tests/js/admin-picker.test.js reads it and asserts its key list
		 * against the script's own English fallback table, so a nested object in
		 * there would either break that cross-check or have to be excluded from
		 * it by name. This method is already the picker's data channel — a
		 * route, a nonce, ids and numbers — and the tile layer is data.
		 *
		 * The post id is deliberately absent. The picker never asks the server
		 * about this location, only about an address, so there is nothing for
		 * it to identify.
		 *
		 * @param int $post_id Post id; unused, and the docblock says why.
		 * @return array
		 */
		public static function picker_config( int $post_id ): array {
			$address = array();

			foreach ( self::ADDRESS_FIELDS as $field ) {
				$address[] = self::FIELD_PREFIX . $field;
			}

			$settings = Settings::all();

			return array(
				'geocode'  => rest_url( Rest_Controller::REST_NAMESPACE . '/geocode' ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'lat'      => self::FIELD_PREFIX . 'lat',
				'lng'      => self::FIELD_PREFIX . 'lng',
				'lookup'   => self::FIELD_PREFIX . self::LOOKUP_FIELDS[0],
				'address'  => $address,
				'zoom'     => self::PICKER_ZOOM,
				'decimals' => self::COORDINATE_DECIMALS,
				'latLimit' => self::LAT_LIMIT,
				'lngLimit' => self::LNG_LIMIT,

				/*
				 * The same three keys the front end is given, built the same
				 * way — Settings::tile_config() is the one place either side
				 * reads them from, so the edit screen and the public map cannot
				 * end up on different tile servers or under different credit
				 * lines.
				 */
				'tile'     => Settings::tile_config( $settings ),
			);
		}

		/**
		 * Marks the redirect of a block-editor metabox save, so the render it
		 * leads to can tell that nobody will ever read it.
		 *
		 * Hooked to redirect_post_location. This is the whole of what Task 18
		 * can do about the channel, and it is worth stating exactly what it is
		 * and is not.
		 *
		 * The two facts it rests on, both read out of WordPress 6.9.1:
		 *
		 * - redirect_post() builds its location with
		 *   `add_query_arg( 'message', N, get_edit_post_link( $post_id, 'url' ) )`
		 *   — wp-admin/includes/post.php lines 2215 and 2225 — so the url the
		 *   browser is sent to is built from scratch. `meta-box-loader` is in
		 *   the *request's* query string and is not in the redirect's. The
		 *   followed GET therefore cannot recognise itself, and the answer to
		 *   "does redirect_post() drop it" is yes, completely.
		 * - This filter runs inside that request, where $_GET still has it. It
		 *   is the one moment both facts are in hand, which is why the marker
		 *   has to be attached here and cannot be worked out later.
		 *
		 * The argument is this plugin's own and never core's name.
		 * use_block_editor_for_post() answers `meta-box-loader` with
		 * `check_admin_referer( 'meta-box-loader', 'meta-box-loader-nonce' )`
		 * (wp-includes/post.php lines 8561-8563), and that nonce is not in this
		 * url. check_admin_referer() ends in wp_nonce_ays(), which is a 403
		 * "The link you followed has expired" page — not wp_die( -1 ), which
		 * belongs to check_ajax_referer().
		 *
		 * And the consequence is worse than an error, not better: apiFetch
		 * throws on a 403, so metaBoxUpdatesFailure would dispatch — and this
		 * class's own trace, six lines up, is that failure and success are the
		 * same reducer case. Nothing would be reported to anybody. Re-using
		 * core's name would 403 the discarded render, invisibly, on every
		 * block-editor save.
		 *
		 * What this buys: print_messages() declines to consume the message on
		 * that render, so the next real page load of the edit screen shows it
		 * instead of finding it already eaten. What it does not buy is telling
		 * the editor at the moment of the save. Nothing can, from here — see
		 * the class docblock.
		 *
		 * A location carrying a fragment is left alone. redirect_post()'s
		 * addmeta and deletemeta branches end on '#postcustom' (lines
		 * 2216-2223), and add_query_arg() would put the argument after the
		 * fragment, where nothing reads it.
		 *
		 * @param mixed $location Where the save redirects to.
		 * @param mixed $post_id  The post that was saved.
		 * @return mixed The location, marked or untouched.
		 */
		public function mark_discarded_render( $location, $post_id = 0 ) {
			if ( ! is_string( $location ) || false !== strpos( $location, '#' ) ) {
				return $location;
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading which screen posted this, not acting on it; post.php has already run check_admin_referer( 'update-post_' . $post_id ).
			if ( ! isset( $_GET['meta-box-loader'] ) ) {
				return $location;
			}

			$post = get_post( is_scalar( $post_id ) ? (int) $post_id : 0 );

			// `?? ''` and no is_object() in front of it, for the reason
			// Assets::enqueue_admin() measured and then deleted the same
			// construct: the null-coalescing operator has isset() semantics, so
			// get_post()'s null answers '' here and raises nothing. A condition
			// no input can reach is a claim no case can check, and this file had
			// one of them left behind in the twin of the line that was fixed.
			if ( Post_Type::POST_TYPE !== ( $post->post_type ?? '' ) ) {
				return $location;
			}

			return add_query_arg( self::DISCARDED_ARG, '1', $location );
		}

		/**
		 * Whether this render is the one the block editor is going to discard.
		 *
		 * The marker mark_discarded_render() put on the redirect. Its absence
		 * is the ordinary case — every classic-editor save, and every time
		 * anybody opens the screen — so a request with no marker is a request
		 * whose render somebody will see.
		 *
		 * @return bool
		 */
		private static function is_discarded_render(): bool {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading which render this is, in order to print less; nothing is written and nothing is acted on.
			return isset( $_GET[ self::DISCARDED_ARG ] );
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
