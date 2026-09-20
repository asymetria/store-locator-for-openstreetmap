<?php
/**
 * The bulk action that looks up a batch of addresses, and the report that says
 * what happened to each of them.
 *
 * @package Store_Locator_For_OpenStreetMap
 */

namespace Asymetria\StoreLocator\Admin;

use Asymetria\StoreLocator\Geocoder;
use Asymetria\StoreLocator\Post_Type;
use Asymetria\StoreLocator\Settings;
use Asymetria\StoreLocator\Store;
use Asymetria\StoreLocator\Store_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( __NAMESPACE__ . '\\Bulk_Geocode' ) ) {

	/**
	 * One entry in the bulk-actions dropdown on edit.php?post_type=slosm_store,
	 * the run behind it, and the notice on the far side of core's redirect.
	 *
	 * What this is for
	 * ----------------
	 * A site arrives with locations and no coordinates — an import, a
	 * spreadsheet, a migration from a plugin that stored them somewhere else.
	 * Task 19's list table is where an editor can finally *see* that those
	 * locations are not on the map; this is the one screen action that fixes a
	 * page of them without opening forty edit screens and pressing Update on
	 * each.
	 *
	 * The limiter, which is the whole difficulty
	 * ------------------------------------------
	 * Geocoder::MIN_INTERVAL is one second and Geocoder::throttle() blocks in
	 * usleep() to honour it. That is not a tunable: it is the public Nominatim
	 * instance's absolute limit, and the punishment for exceeding it is an IP
	 * block that takes the whole site's lookups with it. So a bulk run is a
	 * sequence of one-second waits by construction, and fifty selected rows is
	 * fifty seconds in one admin request — past most max_execution_time
	 * settings, with no feedback on the way and a half-finished job if the
	 * process is killed.
	 *
	 * Four shapes were available and this is the one chosen, with the reasons
	 * the others were not:
	 *
	 * - **A time budget per Apply, which is what this does.** The run spends up
	 *   to self::BUDGET seconds on lookups, checks the clock before each one,
	 *   and reports everything it did not reach as "not attempted" by name. The
	 *   editor presses Apply again to continue.
	 *
	 * What "fifty seconds is past most max_execution_time settings" is and is
	 * not
	 * ------------------------------------------------------------------------
	 * It is the reason this task is shaped the way it is, and it is not a
	 * measurement. Nothing here has been run against a real host, and the one
	 * thing that would make the sentence precise cuts the other way: on Unix,
	 * PHP counts max_execution_time as CPU time and excludes time spent in
	 * usleep() and waiting on a socket, which is almost all of what a run of
	 * this spends. On such a host the real ceiling is the FPM
	 * request_terminate_timeout or the web server's own, both wall clock and
	 * both invisible from inside PHP. On Windows it is wall clock and the
	 * directive does mean what the sentence says.
	 *
	 * So budget() reading that directive is a floor on a host where it binds
	 * and a conservative guess where it does not, and the budget is measured in
	 * wall clock either way because wall clock is what the editor and the
	 * terminating web server are both counting. What is honestly claimed is
	 * that a run is bounded in wall clock and says what it did not reach; what
	 * is not claimed is that the bound is the host's real limit.
	 * - A fixed cap on the number of locations. Simpler to state and worse in
	 *   both directions: ten locations is four seconds against a fast service
	 *   and sixty against one that is timing out, so the same number is either
	 *   needlessly small or still over the limit. The budget is the same idea
	 *   measured in the unit that actually runs out.
	 * - A scheduled follow-up on wp-cron. A locator's coordinates would then
	 *   appear at some unspecified later time, on a site whose cron may be
	 *   disabled, with the report — the part of this task that matters — arriving
	 *   nowhere. Task 21 did not add it, and the note here said it might: a
	 *   settings screen is not where a background sweep belongs, and nothing has
	 *   asked for one. It is still available to a later task with its own status
	 *   surface; it is not a way of avoiding this decision.
	 * - An admin-side loop in JavaScript, one ajax request per location. It
	 *   genuinely solves the time limit and it is the right answer for a plugin
	 *   that has an admin build step, a progress bar and a REST route to spend
	 *   on it. Here it would be a second lookup path with its own capability
	 *   check, its own nonce, its own failure handling and no test coverage of
	 *   the browser half, to save an editor pressing Apply three times.
	 *
	 * Why pressing Apply again is a real continuation and not a shrug
	 * --------------------------------------------------------------
	 * Two things make the second press cheap, and both are properties this
	 * plugin already has rather than promises made here:
	 *
	 * - A location that was geocoded leaves the "Not on the map" view, because
	 *   that view is a live meta query. So the editor selects the page again and
	 *   presses Apply, and the set is exactly what is left.
	 * - An address the service could not resolve is remembered for
	 *   Admin::FAILURE_TTL under Admin::failure_key(), the same memory a single
	 *   save uses. The second Apply therefore costs nothing for the ones that
	 *   already failed, instead of spending a second each on them again. This
	 *   run reads that memory and writes to it, which is why those two members
	 *   of Admin are public.
	 *
	 * That is also why keeping the unplaced view across core's redirect is part
	 * of this task rather than a nicety: without it the editor lands on All
	 * after every batch and has to find the view again to continue.
	 *
	 * What the budget is measured against
	 * -----------------------------------
	 * self::BUDGET, lowered to fit the host's own max_execution_time when it
	 * reports one; see budget() for the arithmetic and self::RESERVE for what is
	 * held back. The clock is only ever read *between* lookups, and a lookup
	 * answered from Geocoder's thirty-day cache costs nothing and moves neither
	 * the clock nor the limiter — Geocoder::throttle()'s docblock states that,
	 * and it is what keeps a run over already-known addresses fast.
	 *
	 * The first lookup of a run always happens, whatever the budget works out
	 * to. A host with max_execution_time at 10 would otherwise compute a budget
	 * of zero and the run would do nothing at all, for ever, with a report
	 * explaining that it had done nothing — which is worse than one request's
	 * overrun.
	 *
	 * What the editor is told
	 * -----------------------
	 * Every selected location appears in the report under exactly one outcome,
	 * and the outcomes are distinct because the thing to do next is different
	 * for each:
	 *
	 * - geocoded — coordinates were written. Nothing to do.
	 * - already on the map — it had usable coordinates. Nothing to do.
	 * - placed by hand — a person dragged this pin, so it was left alone. To
	 *   re-geocode it, clear the coordinates on the edit screen.
	 * - no address — there is nothing to look up. Type an address.
	 * - no match — the service answered and knew of no such place. Check the
	 *   address.
	 * - the service failed — a timeout, a 429, an unreadable answer. Try again
	 *   later; the address is probably fine.
	 * - looked up before and failed — the remembered answer, quoted, not asked
	 *   again yet.
	 * - not attempted — the budget ran out. Press Apply again.
	 * - not yours to edit — the capability check said no for that location.
	 * - no such location — the id did not resolve to a location of this type.
	 *
	 * The failures are printed first and the successes last. That ordering is
	 * the one lesson carried over from an earlier plugin by this author whose
	 * bulk importer reported "done" while silently skipping rows: make the
	 * failure visible first and diagnose second. Nothing is folded into a
	 * total — every location that did not get coordinates is named, and linked
	 * to its own edit screen so the editor can act on it.
	 *
	 * Security, and the nonce this must not touch
	 * -------------------------------------------
	 * wp-admin/edit.php has already done two checks before this class is
	 * reached: current_user_can( $post_type_object->cap->edit_posts ) at line 43
	 * of WordPress 6.9.1, and check_admin_referer( 'bulk-posts' ) at line 77.
	 * Both are repeated here, because handle() is a public method on a filter
	 * and "the only caller does it" is a property of today's WordPress rather
	 * than of this class. The per-location capability is the meta one with the
	 * id — current_user_can( 'edit_post', $id ) — for the reason Admin::save()
	 * gives: the plain edit_posts a contributor holds is not permission to touch
	 * a location somebody else owns.
	 *
	 * The nonce is core's own bulk-action nonce and **never** Admin::NONCE_FIELD.
	 * Admin::save() writes every field it knows about and reads an absent input
	 * as empty, which is safe only because that nonce and its twelve inputs
	 * always travel together; a screen that carried it with a partial form would
	 * blank the address, the hours and the phone number of every location in a
	 * selection. Nothing on this screen prints that field, nothing here reads
	 * $_POST at all, and there is a case for each half of that.
	 *
	 * The cache, which nothing else will invalidate
	 * ---------------------------------------------
	 * This run writes _slosm_lat and _slosm_lng outside a post save, and none of
	 * Plugin::boot()'s six invalidation hooks fires for a meta-only write. So
	 * the map payload would keep its old pins for a full
	 * Store_Repository::CACHE_TTL — a day — while the list table showed the new
	 * coordinates. Store_Repository::save_fields() names this as the caller's
	 * job, and flush_cache() is called here.
	 *
	 * It is flushed twice, and both are load-bearing.
	 *
	 * The first is after *each* successful write. flush_cache() carries a
	 * once-per-request guard, so only the first of those actually spends a
	 * generation and the rest clear the memo and return. What that placement
	 * buys is the run that does not finish — a timeout, a fatal, a killed
	 * worker after thirty of fifty — where a flush at the end never runs and
	 * every pin written so far stays invisible until the ttl expires.
	 *
	 * The second is one flush_cache( true ) after the loop, and it exists
	 * because the first is not enough. Since the guard swallows every flush
	 * after the first, locations two onwards are written *after* the only bump
	 * the run makes. A concurrent front-end request landing in that gap builds
	 * the payload without their coordinates and caches it under the generation
	 * the first flush moved to; nothing invalidates it afterwards, because the
	 * run is over. That is exactly the race flush_cache()'s own docblock
	 * describes and closes in the save path — "milliseconds of window, a full
	 * CACHE_TTL of wrong pins" — except that here the window is not
	 * milliseconds. It is the length of the run, which this class is designed
	 * to let reach twenty seconds. $force is what lets the closing flush past
	 * the guard, which is what that parameter is for.
	 *
	 * Two option writes per run, against up to fifteen network requests. A case
	 * counts the generation, so the shape is measured rather than asserted, and
	 * a run that wrote nothing still spends nothing.
	 *
	 * What this class does not do
	 * ---------------------------
	 * It adds nothing to Quick Edit and nothing to Bulk Edit, for the reason
	 * Locations_List's docblock gives at length. A bulk action is a button and a
	 * list of ids; it posts none of this plugin's fields and cannot blank one.
	 */
	final class Bulk_Geocode {

		/**
		 * The value the bulk-action dropdown carries for this action.
		 *
		 * Prefixed, because this lands in $_REQUEST['action'] on edit.php beside
		 * core's own 'edit', 'trash', 'untrash' and 'delete' and beside whatever
		 * every other plugin on the site has added. An unprefixed 'geocode'
		 * would be a name the next locator plugin would also want.
		 *
		 * @var string
		 */
		public const ACTION = 'slosm_geocode';

		/**
		 * Core's nonce action for a bulk action on a posts list table.
		 *
		 * Not this plugin's, and that is the point of writing it out.
		 * WP_List_Table::display_tablenav() prints wp_nonce_field( 'bulk-' .
		 * $this->_args['plural'] ) at line 1675 of WordPress 6.9.1, and
		 * WP_Posts_List_Table is constructed with 'plural' => 'posts' (line 78 of
		 * class-wp-posts-list-table.php), so the action is exactly this string
		 * for every post type on the site. edit.php verifies it at line 77 with
		 * check_admin_referer( 'bulk-posts' ).
		 *
		 * @var string
		 */
		public const NONCE_ACTION = 'bulk-posts';

		/**
		 * The capability that gets a person onto this screen at all.
		 *
		 * The post type is registered without a capability_type, so WordPress
		 * derives its capabilities from 'post' and cap->edit_posts is this
		 * string. It is the same check edit.php makes before anything else, and
		 * it is repeated here as the cheap early gate; the expensive and
		 * authoritative one is 'edit_post' with a post id, per location.
		 *
		 * @var string
		 */
		public const SCREEN_CAPABILITY = 'edit_posts';

		/**
		 * The capability asked about each location.
		 *
		 * A meta capability, which is why it is never asked without an id.
		 * 'edit_post' alone maps to 'edit_posts' and answers "may this person
		 * edit something", which a contributor can.
		 *
		 * @var string
		 */
		public const ITEM_CAPABILITY = 'edit_post';

		/**
		 * The argument core's redirect carries so the notice knows to print.
		 *
		 * This plugin's own prefix: it lands in $_GET on edit.php, which every
		 * plugin on the site may add arguments to.
		 *
		 * @var string
		 */
		public const NOTICE_ARG = 'slosm_geocoded';

		/**
		 * The one value that argument may have.
		 *
		 * A switch with one position, compared as a string, exactly as
		 * Locations_List::UNPLACED_VALUE is and for the same reason: nothing
		 * read out of it ever reaches a query, a meta key or any markup.
		 *
		 * @var string
		 */
		public const NOTICE_VALUE = '1';

		/**
		 * The screen id of the locations list.
		 *
		 * 'edit-' plus the post type, which is what WordPress calls a list
		 * table's screen. Built from Post_Type::POST_TYPE rather than typed, for
		 * the same reason Plugin::boot() builds the two bulk filter names that
		 * way: a renamed post type must not leave this pointing at a screen that
		 * no longer exists.
		 *
		 * @var string
		 */
		public const LIST_SCREEN = 'edit-' . Post_Type::POST_TYPE;

		/**
		 * Where one editor's report waits for the page after the redirect.
		 *
		 * The user id is appended; see report_key(). Per user because two
		 * editors can be working the same list, and a report is about the batch
		 * one of them selected.
		 *
		 * @var string
		 */
		public const REPORT_PREFIX = 'slosm_bulk_geocode_';

		/**
		 * How long the report waits to be read.
		 *
		 * Admin::NOTICE_TTL's five minutes, for the same reason: the redirect it
		 * is waiting for is immediate, so this is a lifetime for the case where
		 * the browser never arrives rather than a window anybody uses.
		 *
		 * @var int
		 */
		public const REPORT_TTL = 5 * MINUTE_IN_SECONDS;

		/**
		 * Seconds of lookups one Apply may spend.
		 *
		 * Fifteen, which against the one-second courtesy limit is about fourteen
		 * addresses per press on a service answering promptly, and fewer when it
		 * is slow — which is the behaviour wanted, since "slow" is exactly when
		 * a fixed count would overrun.
		 *
		 * It is a ceiling on the *waiting*, not on the request: the check
		 * happens between lookups, so a run can exceed this by one lookup's
		 * worth — up to Geocoder::MIN_INTERVAL plus Geocoder::HTTP_TIMEOUT, six
		 * seconds. self::RESERVE is what keeps that overshoot inside the host's
		 * limit.
		 *
		 * @var float
		 */
		public const BUDGET = 15.0;

		/**
		 * Seconds held back from the host's own max_execution_time.
		 *
		 * Six of them are the lookup that may already be in flight when the
		 * budget is checked — Geocoder::MIN_INTERVAL of courtesy wait plus
		 * Geocoder::HTTP_TIMEOUT of request — and the remaining four are the
		 * rest of the admin request: bootstrap, the meta reads, the redirect.
		 *
		 * @var int
		 */
		public const RESERVE = 10;

		/**
		 * Coordinates were looked up and written.
		 *
		 * @var string
		 */
		public const GEOCODED = 'geocoded';

		/**
		 * It was already on the map.
		 *
		 * @var string
		 */
		public const PLACED = 'placed';

		/**
		 * A person put this pin here, so it was left alone.
		 *
		 * @var string
		 */
		public const LOCKED = 'locked';

		/**
		 * There is no address to look up.
		 *
		 * @var string
		 */
		public const NO_ADDRESS = 'no_address';

		/**
		 * The service answered, and knew of no such place.
		 *
		 * @var string
		 */
		public const NO_MATCH = 'no_match';

		/**
		 * The service could not be asked, or answered with something unusable.
		 *
		 * @var string
		 */
		public const SERVICE_FAILED = 'service_failed';

		/**
		 * This address failed recently, and was not asked about again.
		 *
		 * @var string
		 */
		public const REMEMBERED = 'remembered';

		/**
		 * The run's budget ran out before reaching this one.
		 *
		 * @var string
		 */
		public const DEFERRED = 'deferred';

		/**
		 * This person may not edit this location.
		 *
		 * @var string
		 */
		public const DENIED = 'denied';

		/**
		 * That id is not a location.
		 *
		 * @var string
		 */
		public const MISSING = 'missing';

		/**
		 * Every outcome, in the order the report prints them.
		 *
		 * Failures first and the success last, which is the opposite of the
		 * order a summary would naturally take and is the point: an editor who
		 * reads only the first line has to be reading about the locations that
		 * are still not on the map.
		 *
		 * The class docblock has what each one means and what to do about it.
		 * This list is also what the notice iterates, so an outcome missing from
		 * it is an outcome that would be recorded and never shown — which is why
		 * a case asserts every outcome this class can produce is in it.
		 *
		 * @var string[]
		 */
		public const OUTCOME_ORDER = array(
			self::SERVICE_FAILED,
			self::NO_MATCH,
			self::REMEMBERED,
			self::NO_ADDRESS,
			self::DENIED,
			self::MISSING,
			self::DEFERRED,
			self::LOCKED,
			self::PLACED,
			self::GEOCODED,
		);

		/**
		 * Which notice class each outcome is printed in.
		 *
		 * Four of core's, and the split is by what the editor has to do rather
		 * than by how the run feels about it: error is "this location is not on
		 * the map and something is wrong", warning is "this location is not on
		 * the map and you have to act", info is "nothing was needed", success is
		 * "done".
		 *
		 * @var array<string, string>
		 */
		private const OUTCOME_CLASSES = array(
			self::SERVICE_FAILED => 'notice-error',
			self::NO_MATCH       => 'notice-error',
			self::REMEMBERED     => 'notice-error',
			self::DENIED         => 'notice-error',
			self::MISSING        => 'notice-error',
			self::NO_ADDRESS     => 'notice-warning',
			self::DEFERRED       => 'notice-warning',
			self::LOCKED         => 'notice-info',
			self::PLACED         => 'notice-info',
			self::GEOCODED       => 'notice-success',
		);

		/**
		 * Where locations are read and written.
		 *
		 * @var Store_Repository|null
		 */
		private ?Store_Repository $repository;

		/**
		 * Where addresses are looked up.
		 *
		 * @var Geocoder|null
		 */
		private ?Geocoder $geocoder;

		/**
		 * Returns the current time as a float number of seconds.
		 *
		 * @var callable
		 */
		private $clock;

		/**
		 * Builds the action over a repository, a geocoder and a clock.
		 *
		 * The repository is passed in by Plugin::boot() so that the once-per-
		 * request flush guard this run depends on is the one the rest of the
		 * request shares. The geocoder is built on first use, so an Apply that
		 * turns out to need no lookup never constructs one.
		 *
		 * The clock is a seam for the same reason Geocoder's is: the budget is a
		 * subtraction of two readings, and a test that could not move time
		 * between them could only prove the budget by actually waiting.
		 * microtime( true ) rather than time(), so the two ends of the run are
		 * measured the same way the limiter measures its own interval.
		 *
		 * @param Store_Repository|null $repository Where locations are read and written; built on first use when omitted.
		 * @param Geocoder|null         $geocoder   Where addresses are looked up; built on first use when omitted.
		 * @param callable|null         $clock      Returns the current time in seconds; defaults to microtime( true ).
		 */
		public function __construct( ?Store_Repository $repository = null, ?Geocoder $geocoder = null, ?callable $clock = null ) {
			$this->repository = $repository;
			$this->geocoder   = $geocoder;
			$this->clock      = null !== $clock ? $clock : static function (): float {
				return microtime( true );
			};
		}

		/**
		 * Adds the entry to the dropdown. Filters bulk_actions-edit-slosm_store.
		 *
		 * Appended rather than inserted, so Edit, Move to Trash and whatever
		 * another plugin added keep their places.
		 *
		 * The label is escaped here, which is unusual enough to say why:
		 * WP_List_Table::bulk_actions() prints the value of each entry straight
		 * into the option — `'<option value="' . esc_attr( $key ) . '"' . $class
		 * . '>' . $value . "</option>\n"`, line 622 of WordPress 6.9.1 — with no
		 * escaping of its own. The key is escaped by core and the label is not.
		 * There is nothing an editor typed in this string today; it is escaped
		 * anyway, because that stops being true the first time somebody puts a
		 * count or a site name in it, and because a translator's string is not
		 * this plugin's to vouch for.
		 *
		 * The apostrophe in the label is what makes that claim falsifiable
		 * rather than decorative. A label of plain words is byte-identical
		 * whether or not it went through esc_html(), so a case over one would
		 * pass with the call deleted — which is exactly what the first mutation
		 * sweep of this task reported.
		 *
		 * A value that is not an array is handed straight back, for the reason
		 * Locations_List::columns() gives: casting it would turn another
		 * plugin's bug into a fatal on this screen.
		 *
		 * Nothing is offered on the Trash view, and that is not tidiness.
		 * WP_Posts_List_Table::get_bulk_actions() swaps Edit for Restore and
		 * Move to Trash for Delete permanently when it is the trash being
		 * looked at (lines 432-453 of WordPress 6.9.1), but this filter runs
		 * over whatever that returned either way — so an unguarded entry sits
		 * in the Trash dropdown, and running it there is a real request, a real
		 * second of the courtesy limit, a meta write and a cache flush per row,
		 * for locations that are not on the map and are not going to be.
		 * find_for_admin() gates on the post type and deliberately not on the
		 * status, so nothing further down would have stopped it.
		 *
		 * @param mixed $actions The bulk actions, keyed by action name.
		 * @return mixed
		 */
		public function actions( $actions = array() ) {
			if ( ! is_array( $actions ) || self::on_trash() ) {
				return $actions;
			}

			$actions[ self::ACTION ] = esc_html( __( 'Look up coordinates from the location\'s address', 'store-locator-for-openstreetmap' ) );

			return $actions;
		}

		/**
		 * Runs the action. Filters handle_bulk_actions-edit-slosm_store.
		 *
		 * wp-admin/edit.php reaches this through the default arm of its bulk
		 * switch (line 222 of WordPress 6.9.1), having already built $sendback
		 * out of wp_get_referer() and collected the ids. What it expects back is
		 * a url; it then strips its own arguments from it and redirects.
		 *
		 * Four guards, and the first is the one that runs most often: this
		 * filter fires for **every** bulk action on this screen that core does
		 * not handle itself, so anything that is not this action has to leave
		 * with the url untouched. A handler that acted on the name it was not
		 * given would geocode a selection somebody made for another plugin's
		 * action.
		 *
		 * The nonce and the capability are re-checked rather than inherited. The
		 * argument against is that edit.php has done both already; the argument
		 * for is that this is a public method on a public filter and the sentence
		 * "the only caller checks" is a fact about wp-admin/edit.php in this
		 * version of WordPress, not about this class. Both checks are cheap, and
		 * each has a case proving a run is refused without it.
		 *
		 * The ids are validated even though core intvals the ordinary path. It
		 * intvals only the `post` branch: `$_REQUEST['ids']` arrives as a
		 * comma-separated string and is exploded without a cast, and
		 * `$_REQUEST['media']` is taken as-is (lines 101-108). Both are
		 * reachable with a hand-built url, so what this method is handed is not
		 * guaranteed to be a list of positive integers.
		 *
		 * @param mixed $sendback Where core will redirect afterwards.
		 * @param mixed $action   The bulk action that was chosen.
		 * @param mixed $ids      The selected post ids.
		 * @return mixed The url to redirect to.
		 */
		public function handle( $sendback = '', $action = '', $ids = array() ) {
			if ( ! is_string( $sendback ) || ! is_scalar( $action ) || self::ACTION !== (string) $action ) {
				return $sendback;
			}

			if ( ! self::verified() || ! current_user_can( self::SCREEN_CAPABILITY ) ) {
				return $sendback;
			}

			// The same predicate the dropdown uses, so the entry an editor can
			// see and the run that happens cannot disagree. Nothing offers this
			// action on the Trash view, so reaching here from it means a url
			// somebody built by hand.
			if ( self::on_trash() ) {
				return $sendback;
			}

			$report = $this->run( self::ids( $ids ) );

			if ( array() === $report ) {
				return $sendback;
			}

			$this->remember( $report );

			// The view, and this line is defence in depth rather than a fix for
			// a path anybody has traced.
			//
			// An earlier version of this comment said core falls back to a bare
			// edit.php when the referer field does not survive, and that is
			// false. wp-admin/edit.php line 79 is remove_query_arg( array(...),
			// wp_get_referer() ), and remove_query_arg() over a false query
			// ends in add_query_arg( 'trashed', false, false ), whose
			// three-argument branch tests `false === $args[2]` and substitutes
			// $_SERVER['REQUEST_URI'] (wp-includes/functions.php lines
			// 1139-1152 and 1220-1228 of WordPress 6.9.1). So $sendback is
			// never falsy, admin_url( $parent_file ) is not reached — and since
			// REQUEST_URI is this request's own url, and
			// Locations_List::keep_view() put the argument into it, the view is
			// not lost on that path either.
			//
			// Every path traced from core therefore has $sendback already
			// carrying the argument whenever unplaced_requested() is true. The
			// line stays because the two facts are produced by two different
			// mechanisms — core's _wp_http_referer and this plugin's hidden
			// input — and nothing enforces that they agree; add_query_arg()
			// replaces rather than appends, so a redundant call costs nothing
			// and a disagreement costs the editor their place. That is what
			// defence in depth means here, and it is worth naming as such: the
			// case that holds it pairs a $_GET and a $sendback that core has
			// not been shown to produce together.
			if ( Locations_List::unplaced_requested() ) {
				$sendback = add_query_arg( Locations_List::UNPLACED_ARG, Locations_List::UNPLACED_VALUE, $sendback );
			}

			return add_query_arg( self::NOTICE_ARG, self::NOTICE_VALUE, $sendback );
		}

		/**
		 * Prints the report. Hooked to admin_notices.
		 *
		 * Read once and then deleted, exactly as Admin::print_messages() does: a
		 * report that survived its own rendering would reappear on every later
		 * page load, describing a run nobody can remember starting.
		 *
		 * admin_notices fires on every screen in wp-admin, so notice_requested()
		 * is what keeps a report about locations off somebody's Dashboard: the
		 * argument core's redirect carries, or this plugin's own list screen —
		 * the second being how a run killed part way still reaches the person
		 * who started it. The argument also survives into this screen's paging
		 * links, which is harmless in the only way that matters: the transient
		 * is gone by then, so page two prints nothing rather than the report
		 * again.
		 *
		 * Everything is escaped at the point it is printed. Half of what is in
		 * here is a location's title and the other half is a geocoding service's
		 * error message, and neither has been through this plugin's own
		 * sanitisers on the way.
		 *
		 * @return void
		 */
		public function notice(): void {
			if ( ! self::notice_requested() ) {
				return;
			}

			$key    = self::report_key();
			$report = get_transient( $key );

			if ( ! is_array( $report ) || array() === $report ) {
				return;
			}

			delete_transient( $key );

			foreach ( self::OUTCOME_ORDER as $outcome ) {
				$rows = array();

				foreach ( $report as $row ) {
					if ( is_array( $row ) && $outcome === ( $row['outcome'] ?? '' ) ) {
						$rows[] = $row;
					}
				}

				if ( array() === $rows ) {
					continue;
				}

				$this->print_group( $outcome, $rows );
			}
		}

		/**
		 * The seconds of lookups one Apply may spend on this host.
		 *
		 * Pure and static so that the arithmetic is testable without an ini
		 * setting, which is not something a case can move.
		 *
		 * Zero or less means "no limit" — that is what max_execution_time is on
		 * the CLI, and what ini_get() reports when the directive is empty — so
		 * the plugin's own ceiling applies. Otherwise the smaller of the two
		 * wins, with self::RESERVE held back for the lookup already in flight
		 * and the rest of the request.
		 *
		 * The class docblock has the caveat that goes with reading that
		 * directive at all: on Unix it counts CPU time and excludes the sleeps
		 * and socket waits this run is almost entirely made of, so where it
		 * binds this is a floor and where it does not this is conservative.
		 * Neither makes the run unbounded, which is the property the budget is
		 * for.
		 *
		 * A host whose limit is at or below the reserve gets zero, and zero does
		 * not mean "do nothing": run() always attempts the first lookup of a
		 * run. The alternative is a screen that reports having done nothing,
		 * every time, on a host where one lookup per press would have worked.
		 *
		 * @param int $limit The host's max_execution_time in seconds; 0 or less for no limit.
		 * @return float
		 */
		public static function budget( int $limit ): float {
			if ( 0 >= $limit ) {
				return self::BUDGET;
			}

			$allowed = (float) ( $limit - self::RESERVE );

			if ( 0.0 > $allowed ) {
				$allowed = 0.0;
			}

			return $allowed < self::BUDGET ? $allowed : self::BUDGET;
		}

		/**
		 * Looks up as many of these locations as the budget allows.
		 *
		 * The decision to look one up is Admin::should_geocode()'s and not this
		 * method's, and the two arguments it is handed are the *same* record on
		 * purpose. Its fourth rule is about an address that changed during a
		 * save; there is no save here, so passing the record as both before and
		 * after leaves exactly the rules that are about the location's present
		 * state: a lock beats everything, an absent address is nothing to look
		 * up, and no usable coordinates means look them up. That is the whole of
		 * what a bulk run should ask.
		 *
		 * The three "no" outcomes are named afterwards rather than decided
		 * beforehand, and the ordering matters: the labels are read off in
		 * should_geocode()'s own order, so a location that is both locked and
		 * addressless is reported as locked, which is the rule that actually
		 * stopped it. Deciding beforehand would be a second copy of the rules,
		 * and the copy that drifted would be the one that overwrote a pin.
		 *
		 * Each location is written as it is resolved, never batched to the end.
		 * A run that dies half way — the timeout this whole design is about —
		 * leaves the half it finished on the map rather than nothing at all,
		 * and the report is written down after every lookup for the same
		 * reason: a fatal in the middle of a run leaves the editor a report of
		 * the part that finished instead of nothing at all. One set_transient()
		 * against a lookup that has just spent a second on the network is not a
		 * cost worth optimising, and notice() is what makes the partial report
		 * reachable on a request that never got its redirect.
		 *
		 * Why the order of the first two checks is the way round it is
		 * ------------------------------------------------------------
		 * The record is read *before* the capability is asked about, which
		 * looks backwards and is not. map_meta_cap() answers 'do_not_allow' for
		 * 'edit_post' whenever get_post() finds nothing
		 * (wp-includes/capabilities.php lines 208-212 of WordPress 6.9.1), so a
		 * selected id that is not a post at all fails the capability check —
		 * and asking that first would report every non-existent id as "you are
		 * not allowed to edit it". Both are named failures and neither is
		 * silently skipped, but one of the ten outcomes would be wrong about
		 * every id of that shape, which is the exact failure this whole task is
		 * built around.
		 *
		 * Nothing read before the capability check is reported. A denied row
		 * carries no name, so the record fetched a line earlier tells the
		 * person nothing they did not already select; the read is a wasted
		 * get_post() on a row they cannot edit and nothing more.
		 *
		 * @param int[] $ids Post ids, already validated.
		 * @return array[] One row per location, in the order they were selected.
		 */
		private function run( array $ids ): array {
			$report  = array();
			$budget  = self::budget( self::execution_limit() );
			$started = (float) call_user_func( $this->clock );

			// A flag and not a count, because the only question asked of it is
			// whether the run has already spent a lookup — the budget is in
			// seconds, and a second variable counting locations would be a
			// second limiter nobody reads. A counter here is also a mutation
			// nothing can kill: every increment past the first is the same
			// answer to `> 0`.
			$asked = false;

			// Whether anything at all reached the database, which is what the
			// closing flush below is conditional on.
			$written = false;

			// The site's country restriction, read once for the whole run rather
			// than per location. Every lookup in one run is narrowed the same
			// way, and it is what Admin::failure_key() is keyed on as well as
			// what the lookup carries — so a value re-read halfway through would
			// remember a failure under a key the rest of the run does not look
			// for.
			$country = (string) Settings::get( 'country' );

			foreach ( $ids as $id ) {
				$store = $this->repository()->find_for_admin( $id );

				if ( ! $store instanceof Store ) {
					$report[] = self::row( $id, '', self::MISSING );

					continue;
				}

				if ( ! current_user_can( self::ITEM_CAPABILITY, $id ) ) {
					$report[] = self::row( $id, '', self::DENIED );

					continue;
				}

				$record = $store->to_full_array();
				$query  = Admin::address_query( $record );

				if ( ! Admin::should_geocode( $record, $record, $store->lat, $store->lng, $store->lat_locked ) ) {
					$report[] = self::row( $id, $store->name, self::refusal( $store, $query ) );

					continue;
				}

				$failed = get_transient( Admin::failure_key( $query, $country ) );

				if ( is_string( $failed ) && '' !== $failed ) {
					$report[] = self::row( $id, $store->name, self::REMEMBERED, $failed );

					continue;
				}

				if ( $asked && ( (float) call_user_func( $this->clock ) ) - $started >= $budget ) {
					$report[] = self::row( $id, $store->name, self::DEFERRED );

					continue;
				}

				$asked = true;
				$row   = $this->look_up( $id, $store->name, $query, $country );

				$report[] = $row;

				if ( self::GEOCODED === $row['outcome'] ) {
					$written = true;
				}

				// After every lookup, so that a run killed by the very timeout
				// this budget exists to avoid still leaves the editor a report
				// of the half it finished. The alternative is the failure this
				// task is named after: work done, nothing said.
				$this->remember( $report );
			}

			if ( $written ) {
				// The second flush, and it is not a belt on top of a brace.
				// The per-write flushes above move the generation exactly once
				// — the once-per-request guard swallows the rest — so every
				// location after the first is written *after* the only bump the
				// run makes. A concurrent front-end request landing in that gap
				// builds the payload from the coordinates those locations have
				// not got yet and caches it under the generation the first
				// flush moved to, and nothing afterwards invalidates it: the
				// run is over. That is the window
				// Store_Repository::flush_cache() describes and closes in the
				// save path with $force, except that here it is not
				// milliseconds wide — it is as wide as the run, which this
				// class is designed to let reach twenty seconds.
				//
				// $force, because the guard would otherwise swallow this one
				// too, which is the whole point of that parameter. Two option
				// writes per run against up to fifteen network requests.
				$this->repository()->flush_cache( true );
			}

			return $report;
		}

		/**
		 * Why should_geocode() said no, for a location it said no about.
		 *
		 * Only ever called on that branch, so it is a label and not a second
		 * decision; the order is should_geocode()'s own, rules one and two, with
		 * everything else meaning the location is already on the map.
		 *
		 * @param Store  $store The location.
		 * @param string $query Its address, joined.
		 * @return string One of the outcome constants.
		 */
		private static function refusal( Store $store, string $query ): string {
			if ( $store->lat_locked ) {
				return self::LOCKED;
			}

			if ( '' === $query ) {
				return self::NO_ADDRESS;
			}

			return self::PLACED;
		}

		/**
		 * Asks the service about one address and writes what came back.
		 *
		 * A failure changes nothing: the coordinates that were there stay,
		 * because the service being unreachable is not evidence about where the
		 * shop is. It is remembered under Admin::failure_key() for
		 * Admin::FAILURE_TTL — the same memory a single save keeps and reads —
		 * so that the next Apply, and the next save of the same location, do not
		 * spend a request and a second's wait on an answer that is already
		 * known.
		 *
		 * The two failures are told apart by the geocoder's own error code,
		 * because they are two different things to do next: "no place matched
		 * that address" is a sentence about the address, and everything else is
		 * a sentence about the service.
		 *
		 * The coordinates go through Admin::coordinate_string(), which is not a
		 * formatting preference. The metabox renders its fields at
		 * Admin::COORDINATE_DECIMALS, so a stored value with more decimals than
		 * that reads back differently from what the form shows, the next
		 * untouched save sees a change, and the location locks itself against
		 * every future lookup. Writing through the same formatter the form
		 * renders with is what makes that state unreachable from here.
		 *
		 * lat_locked is deliberately not written, for the reason
		 * Admin::geocode_into() gives: a lookup is not a person placing a pin,
		 * and marking it as one would stop every later lookup on a location
		 * nobody has ever touched.
		 *
		 * @param int    $id    Post id.
		 * @param string $name  Location title, for the report.
		 * @param string $query The address, joined.
		 * @return array One report row.
		 */
		private function look_up( int $id, string $name, string $query, string $country = '' ): array {
			$point = $this->geocoder()->geocode( $query, $country );

			if ( is_wp_error( $point ) ) {
				$message = sprintf(
					/* translators: %s: why the lookup failed. */
					__( 'The address could not be looked up: %s', 'store-locator-for-openstreetmap' ),
					$point->get_error_message()
				);

				set_transient( Admin::failure_key( $query, $country ), $message, Admin::FAILURE_TTL );

				return self::row(
					$id,
					$name,
					'slosm_geocode_no_results' === $point->get_error_code() ? self::NO_MATCH : self::SERVICE_FAILED,
					$point->get_error_message()
				);
			}

			$lat = Admin::coordinate_string( (float) $point['lat'] );
			$lng = Admin::coordinate_string( (float) $point['lng'] );

			$this->repository()->save_fields(
				$id,
				array(
					'lat' => $lat,
					'lng' => $lng,
				)
			);

			// Meta written outside a post save, which none of Plugin::boot()'s
			// six invalidation hooks can see. The class docblock has why this is
			// here rather than after the loop, and why it still costs one
			// generation for the whole run.
			$this->repository()->flush_cache();

			return self::row( $id, $name, self::GEOCODED, $lat . ', ' . $lng );
		}

		/**
		 * One row of the report.
		 *
		 * @param int    $id      Post id.
		 * @param string $name    Location title; empty when there is none, or when there is no location.
		 * @param string $outcome One of the outcome constants.
		 * @param string $detail  What to add after the name, if anything.
		 * @return array
		 */
		private static function row( int $id, string $name, string $outcome, string $detail = '' ): array {
			return array(
				'id'      => $id,
				'name'    => $name,
				'outcome' => $outcome,
				'detail'  => $detail,
			);
		}

		/**
		 * The ids to act on, out of whatever the hook was handed.
		 *
		 * Cast, filtered to the positive and deduplicated. The duplicate check
		 * is not tidiness: `post[]=7&post[]=7` in a hand-built url would
		 * otherwise be two lookups and two seconds for one location, and the
		 * report would name it twice under two different outcomes.
		 *
		 * Order is preserved, because the report reads in the order the editor
		 * sees the rows.
		 *
		 * @param mixed $ids Whatever the filter was handed.
		 * @return int[]
		 */
		private static function ids( $ids ): array {
			if ( ! is_array( $ids ) ) {
				return array();
			}

			$clean = array();

			foreach ( $ids as $id ) {
				if ( ! is_scalar( $id ) ) {
					continue;
				}

				$id = (int) $id;

				if ( 1 > $id || in_array( $id, $clean, true ) ) {
					continue;
				}

				$clean[] = $id;
			}

			return $clean;
		}

		/**
		 * Whether this request carries core's bulk-action nonce.
		 *
		 * check_admin_referer() is not called, on purpose: it dies with -1 and a
		 * 403 when the check fails, which is right for a front controller and
		 * wrong for a filter that is expected to hand a url back. wp_verify_nonce()
		 * is what that function uses underneath.
		 *
		 * The truthiness check rather than `true ===` is the same point
		 * Admin::save() makes: wp_verify_nonce() returns int 1, int 2 or false,
		 * and 2 means a nonce from the previous twelve-hour tick — a list table
		 * left open overnight. Verified in wp-includes/pluggable.php lines
		 * 2493-2501 of WordPress 6.9.1.
		 *
		 * There is no is_string() guard, which Admin::save() does have, and the
		 * difference is deliberate. That one uses the guard to mean "this
		 * request does not contain my form", which is a decision it goes on to
		 * make; here there is nothing left for one to do.
		 * _sanitize_text_fields() answers '' for an array or an object on its
		 * first line (wp-includes/formatting.php line 5643 of WordPress 6.9.1),
		 * (string) null is '', and wp_verify_nonce( '' ) is false — so a guard
		 * would be a line no case could ever fail on, which this project deletes
		 * rather than keeps for the look of it. The sanitiser itself is
		 * load-bearing and not decoration: without it an array reaches
		 * wp_verify_nonce(), and array-to-string is a warning and the word
		 * "Array".
		 *
		 * The wp_unslash() is the one line here that no case can fail on, and it
		 * stays. addslashes() only ever adds characters and a nonce that
		 * verifies is hexadecimal, so no input separates unslashing from not —
		 * but it is the same read Admin::save() makes of the same superglobal,
		 * and two spellings of one convention is how the one that matters gets
		 * dropped. It is a known surviving mutant, named here as one rather
		 * than dressed up as coverage.
		 *
		 * @return bool
		 */
		private static function verified(): bool {
			// Three sniffs and not one, which is the convention
			// Locations_List::unplaced_requested() already set for a
			// superglobal whose value is assigned and then used: MissingUnslash
			// and InputNotSanitized both fire on the assignment rather than on
			// the call below, so naming only NonceVerification leaves two that
			// would be reported at packaging. Neither is a real finding — the
			// unslash and the sanitiser are both on the next line — and PHPCS
			// could not be run here to confirm the exact codes: there is no
			// phpcs.xml and no vendor/ in this tree yet, and Task 25 is where
			// both arrive.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- this is the nonce check; the unslash and the sanitiser are on the line below.
			$nonce = $_REQUEST['_wpnonce'] ?? null;

			return (bool) wp_verify_nonce( sanitize_text_field( wp_unslash( $nonce ) ), self::NONCE_ACTION );
		}

		/**
		 * Whether this request is looking at the Trash.
		 *
		 * Core's own test, spelled core's way:
		 * WP_Posts_List_Table::__construct() sets $this->is_trash from
		 * `isset( $_REQUEST['post_status'] ) && 'trash' === $_REQUEST['post_status']`
		 * (line 201 of WordPress 6.9.1), and the posts-filter form always
		 * submits post_status as a hidden input. Reading the same superglobal
		 * the same way is what stops the entry an editor can see and the run
		 * that happens from disagreeing about which screen this is.
		 *
		 * Compared against one literal, so nothing read here reaches a query, a
		 * meta key or any markup; the is_scalar() check is what keeps
		 * `post_status[]=trash` from being cast.
		 *
		 * What this does not close, said plainly: a trashed location selected
		 * by id from a url that does not say post_status=trash is still looked
		 * up. Closing that needs the status of each row, which find_for_admin()
		 * deliberately does not gate on and this class has no business asking
		 * post meta for. The screen is where the action is offered and the
		 * screen is where it is refused.
		 *
		 * @return bool
		 */
		private static function on_trash(): bool {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- reading which view this is, on a screen already behind edit_posts; the value is compared against one literal rather than used.
			$status = $_REQUEST['post_status'] ?? null;

			if ( ! is_scalar( $status ) ) {
				return false;
			}

			return 'trash' === (string) $status;
		}

		/**
		 * Whether this screen is one the report belongs on.
		 *
		 * Two ways of being one, and the second is not a convenience.
		 *
		 * The argument core's redirect carries is the ordinary path, and it is
		 * read off $_GET and compared against one literal — the whole of the
		 * validation, for the reason Locations_List::unplaced_requested()
		 * states at length: the value's only effect is to choose between
		 * printing a notice and not printing one. The is_scalar() check is not
		 * decoration; `slosm_geocoded[]=1` arrives as an array, and (string) on
		 * an array is a warning and the word "Array".
		 *
		 * The locations list itself is the other, and it exists for the run
		 * that never reached its redirect. run() writes the report down after
		 * every lookup precisely so that a fatal in the middle of one leaves
		 * the editor the half that finished — and a report gated only on an
		 * argument that only a redirect can carry would be a report nobody
		 * could ever read in exactly that case. So the next time that editor
		 * opens the locations list, the partial report is waiting.
		 *
		 * It is still not every screen in wp-admin. A report about locations
		 * printed over somebody's Dashboard is noise, and the screen check is
		 * what keeps it off. get_current_screen() can be null — on a request
		 * with no screen loaded, which is a real state — and null is not this
		 * screen.
		 *
		 * @return bool
		 */
		private static function notice_requested(): bool {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- reading whether this render follows a run of this action; the value is compared against one literal rather than used.
			$asked = $_GET[ self::NOTICE_ARG ] ?? null;

			if ( is_scalar( $asked ) && self::NOTICE_VALUE === (string) $asked ) {
				return true;
			}

			return self::LIST_SCREEN === self::screen_id();
		}

		/**
		 * The screen this request is rendering, as WordPress names it.
		 *
		 * get_current_screen() answers null before set_current_screen() has
		 * run and on any request that never loads a screen at all, so the
		 * property is reached for rather than assumed.
		 *
		 * @return string The screen id, or '' when there is no screen.
		 */
		private static function screen_id(): string {
			if ( ! function_exists( 'get_current_screen' ) ) {
				return '';
			}

			$screen = get_current_screen();

			return is_object( $screen ) && isset( $screen->id ) && is_scalar( $screen->id )
				? (string) $screen->id
				: '';
		}

		/**
		 * Prints one outcome's notice.
		 *
		 * A list rather than a sentence with names in it, because the detail
		 * differs per location — the service's own words for a failure, the
		 * coordinates for a success — and a comma-separated run of ten of those
		 * is not something anybody reads.
		 *
		 * Each name links to that location's edit screen. That is what turns
		 * "these four failed" into something an editor can act on without
		 * searching a list of forty branches for a title, and it is why the
		 * report does not bother printing ids: two branches can share a name,
		 * and the link tells them apart.
		 *
		 * A location with no title at all — an auto-draft, an import that never
		 * set one — is named by its id, because an empty link is not a link.
		 *
		 * @param string  $outcome One of the outcome constants.
		 * @param array[] $rows    The rows under it.
		 * @return void
		 */
		private function print_group( string $outcome, array $rows ): void {
			$class = self::OUTCOME_CLASSES[ $outcome ] ?? 'notice-info';

			echo '<div class="notice ' . esc_attr( $class ) . '"><p>'
				. esc_html( self::heading( $outcome, count( $rows ) ) )
				. '</p><ul style="list-style:disc;margin-left:2em;">';

			foreach ( $rows as $row ) {
				$id     = isset( $row['id'] ) && is_scalar( $row['id'] ) ? (int) $row['id'] : 0;
				$name   = isset( $row['name'] ) && is_scalar( $row['name'] ) ? trim( (string) $row['name'] ) : '';
				$detail = isset( $row['detail'] ) && is_scalar( $row['detail'] ) ? (string) $row['detail'] : '';

				echo '<li>';

				if ( '' === $name ) {
					/* translators: %d: post id of a location with no title. */
					echo esc_html( sprintf( __( 'Location #%d', 'store-locator-for-openstreetmap' ), $id ) );
				} else {
					echo '<a href="' . esc_url( self::edit_url( $id ) ) . '">' . esc_html( $name ) . '</a>';
				}

				if ( '' !== $detail ) {
					echo ' &#8212; ' . esc_html( $detail );
				}

				echo '</li>';
			}

			echo '</ul></div>';
		}

		/**
		 * The sentence at the top of one outcome's notice.
		 *
		 * Written out one by one rather than built from a shared template,
		 * because each one says what to do next and that is the whole value of
		 * splitting the report up at all. A shared "%d locations: %s" would be
		 * shorter and would tell an editor nothing they could act on.
		 *
		 * _n() throughout, because a locator is as often one branch as forty and
		 * "1 locations" on an admin screen reads as a plugin nobody finished.
		 *
		 * @param string $outcome One of the outcome constants.
		 * @param int    $count   How many locations are under it.
		 * @return string
		 */
		private static function heading( string $outcome, int $count ): string {
			switch ( $outcome ) {
				case self::GEOCODED:
					/* translators: %d: number of locations. */
					return sprintf( _n( '%d location was put on the map:', '%d locations were put on the map:', $count, 'store-locator-for-openstreetmap' ), $count );

				case self::PLACED:
					/* translators: %d: number of locations. */
					return sprintf( _n( '%d location was already on the map, so it was left alone:', '%d locations were already on the map, so they were left alone:', $count, 'store-locator-for-openstreetmap' ), $count );

				case self::LOCKED:
					/* translators: %d: number of locations. */
					return sprintf( _n( '%d location has coordinates placed by hand and was not changed. Clear the coordinates on its edit screen to look them up again:', '%d locations have coordinates placed by hand and were not changed. Clear the coordinates on their edit screens to look them up again:', $count, 'store-locator-for-openstreetmap' ), $count );

				case self::NO_ADDRESS:
					/* translators: %d: number of locations. */
					return sprintf( _n( '%d location has no address to look up, so it is still not on the map:', '%d locations have no address to look up, so they are still not on the map:', $count, 'store-locator-for-openstreetmap' ), $count );

				case self::NO_MATCH:
					/* translators: %d: number of locations. */
					return sprintf( _n( 'The geocoding service knew of no such place for %d location. Check its address:', 'The geocoding service knew of no such place for %d locations. Check their addresses:', $count, 'store-locator-for-openstreetmap' ), $count );

				case self::SERVICE_FAILED:
					/* translators: %d: number of locations. */
					return sprintf( _n( 'The geocoding service could not answer for %d location. The address is probably fine; try again later:', 'The geocoding service could not answer for %d locations. The addresses are probably fine; try again later:', $count, 'store-locator-for-openstreetmap' ), $count );

				case self::REMEMBERED:
					/* translators: %d: number of locations. */
					return sprintf( _n( '%d location failed a lookup recently and was not asked about again:', '%d locations failed a lookup recently and were not asked about again:', $count, 'store-locator-for-openstreetmap' ), $count );

				case self::DEFERRED:
					/* translators: %d: number of locations. */
					return sprintf( _n( '%d location was not attempted, to keep this request inside the time the server allows. Select it again and press Apply to carry on:', '%d locations were not attempted, to keep this request inside the time the server allows. Select them again and press Apply to carry on:', $count, 'store-locator-for-openstreetmap' ), $count );

				case self::DENIED:
					/* translators: %d: number of locations. */
					return sprintf( _n( '%d location was skipped because you are not allowed to edit it:', '%d locations were skipped because you are not allowed to edit them:', $count, 'store-locator-for-openstreetmap' ), $count );

				case self::MISSING:
					/* translators: %d: number of locations. */
					return sprintf( _n( '%d selected item is not a location:', '%d selected items are not locations:', $count, 'store-locator-for-openstreetmap' ), $count );
			}

			/* translators: %d: number of locations. */
			return sprintf( _n( '%d location:', '%d locations:', $count, 'store-locator-for-openstreetmap' ), $count );
		}

		/**
		 * The edit screen of one location.
		 *
		 * Built rather than borrowed, and get_edit_post_link() is the idiomatic
		 * alternative that was not taken. Two reasons, and neither is strong
		 * enough to call this the better choice for ever. It applies the
		 * 'get_edit_post_link' filter, which a site could legitimately use to
		 * move the edit screen — this does not, so such a site would get a link
		 * to the wrong place. Against that: its default context is 'display',
		 * which returns the url with its ampersands already encoded, and
		 * running esc_url() over that afterwards is a double encode; and it
		 * answers null for a post the current user cannot edit, which is a
		 * second branch here for a case that cannot arise, since a denied row
		 * carries no name and therefore no link. It is correct today because
		 * the post type is registered without an _edit_link, so core's default
		 * is exactly this string. Worth revisiting the day this plugin has a
		 * reason to respect that filter.
		 *
		 * @param int $id Post id.
		 * @return string
		 */
		private static function edit_url( int $id ): string {
			return add_query_arg( 'action', 'edit', add_query_arg( 'post', (string) $id, admin_url( 'post.php' ) ) );
		}

		/**
		 * Leaves the report where the page after the redirect will find it.
		 *
		 * A transient rather than the redirect url, and the url is not a close
		 * call: this carries a title and a service's error message per location,
		 * for up to a page of them, and a query string is neither long enough
		 * nor a safe place to put text somebody else wrote.
		 *
		 * @param array[] $report The rows.
		 * @return void
		 */
		private function remember( array $report ): void {
			set_transient( self::report_key(), $report, self::REPORT_TTL );
		}

		/**
		 * Where this editor's report lives.
		 *
		 * @return string
		 */
		private static function report_key(): string {
			return self::REPORT_PREFIX . get_current_user_id();
		}

		/**
		 * The host's max_execution_time, in seconds.
		 *
		 * ini_get() returns a string, or false for a directive that does not
		 * exist. (int) makes both of those and an empty value zero, which
		 * budget() reads as "no limit" — which is what zero means to PHP as
		 * well.
		 *
		 * Nothing here calls set_time_limit(). A plugin raising the host's own
		 * limit to fit its work is how a shared host ends up with a worker stuck
		 * for five minutes, and the function is disabled on many of them anyway.
		 * The budget fits the work to the limit rather than the other way round.
		 *
		 * @return int
		 */
		private static function execution_limit(): int {
			return (int) ini_get( 'max_execution_time' );
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
