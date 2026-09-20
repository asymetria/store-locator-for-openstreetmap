<?php
/**
 * The screen an editor scans forty branches on, and the one place a location
 * that is not on the map can be seen at all.
 *
 * @package Store_Locator_For_OpenStreetMap
 */

namespace Asymetria\StoreLocator\Admin;

use Asymetria\StoreLocator\Post_Type;
use Asymetria\StoreLocator\Store;
use Asymetria\StoreLocator\Store_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( __NAMESPACE__ . '\\Locations_List' ) ) {

	/**
	 * Three columns, one warning, one view and one sortable heading on
	 * edit.php?post_type=slosm_store.
	 *
	 * What this screen is for
	 * -----------------------
	 * A location with no usable coordinates is not on the map, and there is no
	 * error state anywhere that says so. Store_Repository::build_payload()
	 * skips it, the browser draws one fewer marker, the REST response is a
	 * perfectly good 200, and the site looks exactly as it does when everything
	 * is right. The whole plugin is shaped around that failure — Store returns
	 * null rather than 0.0 for a coordinate it cannot read, has_coordinates()
	 * refuses the 0,0 a failed geocode leaves behind, the metabox refuses half a
	 * pair — and every one of those decisions ends here, on the one screen where
	 * a person can see the consequence.
	 *
	 * So the warning is the feature and the columns are the context around it.
	 *
	 * Where the columns sit, and why
	 * ------------------------------
	 * cb, title, on-the-map, address, city, categories, date.
	 *
	 * The title is what the row *is*, so nothing goes above it. The warning
	 * comes straight after it because it is the only thing on the row that can
	 * be wrong in a way nothing else on the site reports; putting it at the far
	 * right, past three columns of address, is putting it where a person
	 * scanning a list of forty branches does not look. The address and the city
	 * follow because they are what tells two branches in one town apart, and
	 * because they are the fields the warning is usually about — a location is
	 * unplaced because its address did not resolve.
	 *
	 * The categories column is core's, and it is moved rather than added. The
	 * taxonomy is registered with show_admin_column, so
	 * WP_Posts_List_Table::get_columns() has already built a `taxonomy-`
	 * column for it and already renders it from the term cache the list query
	 * primed. A column of this plugin's own would be a second Categories
	 * heading on the same screen and a second get_the_terms() per row to fill
	 * it. What is wrong with core's placement is only the position: it lands
	 * immediately after the title, between the name of a branch and the address
	 * of it.
	 *
	 * The date goes last, where core puts it and where it belongs for a
	 * different reason than on a blog. A location is not a thing anybody reads
	 * in the order it was created; the date is there because core's row actions
	 * and the month filter hang off it, not because anybody scans it.
	 *
	 * Why the filter is a view and not a dropdown
	 * -------------------------------------------
	 * A link in the subsubsub row, beside All / Published / Draft, through the
	 * `views_edit-slosm_store` filter.
	 *
	 * The alternative was a dropdown on `restrict_manage_posts`. An earlier
	 * version of this comment justified the choice with "this post type has no
	 * Filter button at all today, so a select would add one", and that was
	 * false — traced and corrected rather than left, because a decision resting
	 * on a wrong fact is a decision nobody can re-examine.
	 * WP_Posts_List_Table::extra_tablenav() buffers months_dropdown(),
	 * categories_dropdown(), formats_dropdown() and the restrict_manage_posts
	 * action together and prints the Filter submit whenever that buffer is
	 * non-empty (lines 595-600). categories_dropdown() really does only fire for
	 * the built-in `category` taxonomy — `is_object_in_taxonomy( $post_type,
	 * 'category' )` — but months_dropdown() returns early only when the months
	 * query comes back empty (class-wp-list-table.php, `if ( ! $month_count ||
	 * ... )`), so one location in one month is enough. **This screen has a
	 * Filter button as soon as it has a single location**, and a select would
	 * not have introduced it.
	 *
	 * The view still wins, on the grounds that survive: it is one click rather
	 * than open-choose-submit, for a question an editor has before they open
	 * anything — is anything on this site invisible; it is where WordPress has
	 * trained people to look for "show me a subset"; and it is linkable and
	 * bookmarkable, which a select's state is not.
	 *
	 * Where the argument does and does not survive
	 * --------------------------------------------
	 * It survives paging and sorting, because WP_List_Table builds both from
	 * REQUEST_URI. It does **not** survive the screen's own form.
	 * wp-admin/edit.php line 488 is `<form id="posts-filter" method="get">`, and
	 * the only hidden inputs it carries are post_status, post_type, author and
	 * show_sticky (lines 492-500). A GET form replaces the whole query string
	 * with its own fields, so pressing Search — or Apply with no bulk action
	 * chosen — drops `slosm_unplaced` and lands the editor back on All.
	 *
	 * An earlier version of this paragraph said the bulk-action Apply dropped it
	 * as well, and Task 20 traced that and found it false: core prints a
	 * `_wp_http_referer` beside the bulk nonce and builds the redirect out of
	 * it, so a handled bulk action already comes back here. The full trace is in
	 * keep_view(), which is the one hidden input that closes the two cases that
	 * remain. It is kept as a correction rather than silently rewritten, because
	 * a decision resting on a wrong fact is a decision nobody can re-examine.
	 *
	 * Note the ordering that makes the nuisance small in any case: edit.php
	 * calls views() at line 486, above the form, so the link back to the view is
	 * always on screen.
	 *
	 * It deliberately carries no count. A count is its own query — the same up-
	 * to-four LEFT JOINs on wp_postmeta that the filter itself uses, none of
	 * which an index can serve — run on every render of this screen whether or
	 * not anybody is looking for an unplaced location. And the number is
	 * available one click later for nothing: the view's own page shows it in
	 * core's "N items" pagination, counted by the query that was going to run
	 * anyway. The row warning already answers the question for the page in front
	 * of the editor. Paying a query on every page load to answer it one click
	 * earlier is the wrong side of that trade.
	 *
	 * What this screen costs
	 * ----------------------
	 * Nothing beyond the list table's own query, on the ordinary page.
	 *
	 * WP_Query primes both caches for the whole result set before any column
	 * renders: `update_post_meta_cache` and `update_post_term_cache` both
	 * default to true and wp_edit_posts_query() overrides neither, so
	 * update_post_caches() runs update_postmeta_cache() and
	 * update_object_term_cache() in one query each. get_post(),
	 * get_post_meta() and get_the_terms() then all read those caches.
	 * Store_Repository::to_store() has the same note and the same caveat: it is
	 * read out of WordPress 6.9.1's source rather than measured, and it does not
	 * hold for a path that never ran a WP_Query.
	 *
	 * The one thing this class has to get right itself is not reading the same
	 * location three times. `manage_{$post_type}_posts_custom_column` fires once
	 * per column per row, and three columns building three Stores would be
	 * thirteen meta reads and one term read each, three times over, on twenty
	 * rows. The record is memoised per post id for the life of the request, and
	 * a case counts the term reads for a whole page.
	 *
	 * Nothing is enqueued *here*, and that is still true after Task 21. The
	 * three declarations this screen needs were printed inline beside the first
	 * warning until a settings screen made an admin stylesheet worth its handle;
	 * they are now in assets/css/admin.css, and Assets::enqueue_admin() puts
	 * that one stylesheet — and nothing else — on this screen. Assets::PICKER_HOOKS
	 * is still named for the picker rather than for the plugin, precisely so
	 * that nothing here reaches for it: the picker's handles would put Leaflet
	 * and a map script on a screen with neither a map nor a coordinate field on
	 * it. The dashicon costs nothing either way, because core's own `wp-admin`
	 * stylesheet declares `dashicons` as a dependency
	 * (wp-includes/script-loader.php line 1630 of WordPress 6.9.1) on every
	 * admin page there is.
	 *
	 * What this class does not do, and why that is the whole point
	 * ------------------------------------------------------------
	 * It adds nothing to Quick Edit and nothing to Bulk Edit.
	 *
	 * Admin::save() writes every field it knows about and reads an absent input
	 * as an empty value. Its docblock states the assumption that makes that
	 * correct — the nonce field and the twelve inputs always travel together,
	 * because both editors post the whole box or none of it — and names this
	 * screen as where it breaks. A Quick Edit form that reused
	 * Admin::NONCE_ACTION with three fields in it would blank the address, the
	 * city, the phone number, the opening hours and the coordinates of every
	 * location in a bulk selection, silently, and would then explain that the
	 * coordinates had been set by hand.
	 *
	 * Core's own Quick Edit posts `_inline_edit` and none of this plugin's
	 * fields, so Admin::save() returns on its first guard and a Quick Edit save
	 * leaves a location exactly as it found it. There is a case that runs one
	 * over a complete location and reads every field back, because "we added
	 * nothing" is a claim about this file and "nothing is blanked" is a claim
	 * about the two together.
	 *
	 * The day somebody does want a city in Quick Edit, it needs its own nonce
	 * action and its own handler; NONCE_ACTION is a constant with the post id
	 * appended rather than a string typed twice for exactly that reason.
	 */
	final class Locations_List {

		/**
		 * The column that says whether this location is on the map.
		 *
		 * @var string
		 */
		public const COLUMN_PLACEMENT = 'slosm_placement';

		/**
		 * The street address column.
		 *
		 * @var string
		 */
		public const COLUMN_ADDRESS = 'slosm_address';

		/**
		 * The city column.
		 *
		 * @var string
		 */
		public const COLUMN_CITY = 'slosm_city';

		/**
		 * The column core builds for the category taxonomy.
		 *
		 * Not this class's column and never registered here; the class docblock
		 * has why it is moved rather than added. The name is core's own
		 * construction from WP_Posts_List_Table::get_columns() — `taxonomy-`
		 * plus the taxonomy key — and it is written out from Post_Type::TAXONOMY
		 * rather than typed, so a rename of the taxonomy cannot leave this
		 * pointing at a column that no longer exists.
		 *
		 * @var string
		 */
		public const CATEGORY_COLUMN = 'taxonomy-' . Post_Type::TAXONOMY;

		/**
		 * The query argument the unplaced view carries.
		 *
		 * This plugin's own prefix, because it lands in $_GET on edit.php, which
		 * is a screen every plugin on the site is entitled to add arguments to.
		 *
		 * @var string
		 */
		public const UNPLACED_ARG = 'slosm_unplaced';

		/**
		 * The one value that argument may have.
		 *
		 * One literal, compared as a string, and that is the whole of the
		 * validation: nothing an editor or anybody else can put in the url ever
		 * reaches a query, a meta key or any markup. The argument is a switch
		 * with one position, so anything that is not exactly this is off.
		 *
		 * @var string
		 */
		public const UNPLACED_VALUE = '1';

		/**
		 * The key the view is filed under in the subsubsub list.
		 *
		 * WP_List_Table::views() prints it straight into `class='$class'` with
		 * no escaping of its own, so it is a literal here and is never built
		 * from anything.
		 *
		 * @var string
		 */
		public const VIEW_KEY = 'slosm_unplaced';

		/**
		 * The name the city sort answers to.
		 *
		 * One string doing two jobs, deliberately: it is the `orderby` value the
		 * column heading puts in the url, and it is the name of the meta_query
		 * clause the sort orders by. WP_Query::parse_orderby() allows an orderby
		 * that is the key of a meta clause, so the two being the same string is
		 * what makes the heading work without this class rewriting the orderby
		 * at all.
		 *
		 * Not 'slosm_city', which is the column's own name. Keeping them
		 * distinct is what lets a case tell "the column is sortable" from "the
		 * clause is named right".
		 *
		 * @var string
		 */
		public const SORT_CITY = 'slosm_city_sort';

		/**
		 * The field each of this class's text columns shows.
		 *
		 * Field names, not meta keys. Where a field lives is
		 * Store_Repository's knowledge and nothing here has any business
		 * knowing it.
		 *
		 * @var array<string, string>
		 */
		private const COLUMN_FIELDS = array(
			self::COLUMN_ADDRESS => 'address',
			self::COLUMN_CITY    => 'city',
		);

		/**
		 * Where locations are read from.
		 *
		 * @var Store_Repository|null
		 */
		private ?Store_Repository $repository;

		/**
		 * The locations this request has already read, keyed by post id.
		 *
		 * @var array<int, Store|null>
		 */
		private array $records = array();

		/**
		 * Builds the screen over a repository.
		 *
		 * Passed in by Plugin::boot(), the same arrangement Admin uses, so that
		 * the object this screen reads through is the object whose memo and
		 * whose once-per-request flush guard everything else on the request
		 * shares.
		 *
		 * @param Store_Repository|null $repository Where locations are read from; built on first use when omitted.
		 */
		public function __construct( ?Store_Repository $repository = null ) {
			$this->repository = $repository;
		}

		/**
		 * The columns, reordered. Filters manage_slosm_store_posts_columns.
		 *
		 * Rebuilt rather than appended to, because a filter that only appends
		 * can only put things after the date. The class docblock has the order
		 * and the reasoning; the mechanics are that the category and date
		 * columns are lifted out, this class's three are inserted after the
		 * title, and the two lifted ones go back on the end in that order.
		 *
		 * A screen with no title column still gets the three, on the end rather
		 * than nowhere. That is not a state WordPress produces — get_columns()
		 * always builds one — but this is a filter, and the value it is handed
		 * is whatever every plugin above it returned.
		 *
		 * A value that is not an array is handed straight back for the same
		 * reason. Casting it would turn another plugin's bug into a fatal in
		 * this file, on a screen that would otherwise merely have looked wrong.
		 *
		 * @param mixed $columns Column headings, keyed by column name.
		 * @return mixed
		 */
		public function columns( $columns = array() ) {
			if ( ! is_array( $columns ) ) {
				return $columns;
			}

			$mine = array(
				self::COLUMN_PLACEMENT => __( 'On the map', 'store-locator-for-openstreetmap' ),
				self::COLUMN_ADDRESS   => __( 'Address', 'store-locator-for-openstreetmap' ),
				self::COLUMN_CITY      => __( 'City', 'store-locator-for-openstreetmap' ),
			);

			$tail = array();

			foreach ( array( self::CATEGORY_COLUMN, 'date' ) as $key ) {
				if ( array_key_exists( $key, $columns ) ) {
					$tail[ $key ] = $columns[ $key ];

					unset( $columns[ $key ] );
				}
			}

			$ordered = array();

			foreach ( $columns as $key => $label ) {
				$ordered[ $key ] = $label;

				if ( 'title' === $key ) {
					$ordered += $mine;
				}
			}

			// A no-op when the title was there, since + keeps the left side's
			// keys; the three columns' home when it was not.
			$ordered += $mine;

			return $ordered + $tail;
		}

		/**
		 * The sortable columns. Filters manage_edit-slosm_store_sortable_columns.
		 *
		 * One of this class's three, and the other two are left alone on
		 * purpose.
		 *
		 * The city is the sort an editor actually wants: a locator is a list of
		 * branches and the question is which town each one is in. The address is
		 * not — sorting forty branches by street name puts Aleje beside Armii
		 * and groups nothing anybody cares about. The coordinates are not
		 * either: a numeric sort on a latitude is a sort by how far north a shop
		 * is, which is a question nobody asks, and it would additionally need its
		 * own numeric cast to avoid ordering 9 after 10 as strings.
		 *
		 * The categories cannot be sorted at all, and that is worth saying
		 * rather than leaving as an omission. Core does not sort a taxonomy
		 * column: the terms are in wp_term_relationships, so an ORDER BY would
		 * need a join through two more tables and a GROUP_CONCAT to give a
		 * location with three categories one sortable value. WP_Query has no
		 * query var for it. A site that wants it wants the filter core already
		 * provides — clicking a category in the column narrows the list — which
		 * is a better answer to the same need.
		 *
		 * The two-element form rather than the five-element one that arrived in
		 * 6.3, because this plugin's floor is WordPress 6.0. False is
		 * "ascending on the first click", which for a list of towns is the
		 * direction anybody means.
		 *
		 * @param mixed $columns Sortable columns.
		 * @return mixed
		 */
		public function sortable_columns( $columns = array() ) {
			if ( ! is_array( $columns ) ) {
				return $columns;
			}

			$columns[ self::COLUMN_CITY ] = array( self::SORT_CITY, false );

			return $columns;
		}

		/**
		 * Prints one cell. Hooked to manage_slosm_store_posts_custom_column.
		 *
		 * The hook fires once per column per row, for every column on the screen
		 * including core's own, so the first thing here is to decline the ones
		 * that are not this class's. A callback that printed for all of them
		 * would put the city into the Date cell.
		 *
		 * Everything printed is escaped at the point it is printed. The address
		 * and the city are fields an editor types into and an importer writes,
		 * so this is a path from a form to an admin screen's html; esc_html() is
		 * the second line of defence rather than the first, and it has to hold
		 * on its own because a row can arrive from an import that never met
		 * Admin::clean().
		 *
		 * An empty field prints an em dash rather than nothing. A blank cell in
		 * a column headed Address reads as the screen being broken; a dash reads
		 * as the location being incomplete, which is what it is.
		 *
		 * @param mixed $column  Column name.
		 * @param mixed $post_id Post id.
		 * @return void
		 */
		public function render_column( $column = '', $post_id = 0 ): void {
			$column  = is_scalar( $column ) ? (string) $column : '';
			$post_id = is_scalar( $post_id ) ? (int) $post_id : 0;

			if ( self::COLUMN_PLACEMENT !== $column && ! isset( self::COLUMN_FIELDS[ $column ] ) ) {
				return;
			}

			$store = $this->record( $post_id );

			if ( null === $store ) {
				return;
			}

			if ( self::COLUMN_PLACEMENT === $column ) {
				$this->print_placement( $store );

				return;
			}

			$record = $store->to_full_array();
			$field  = self::COLUMN_FIELDS[ $column ];
			$value  = isset( $record[ $field ] ) && is_scalar( $record[ $field ] ) ? (string) $record[ $field ] : '';

			if ( '' === trim( $value ) ) {
				echo '&#8212;';

				return;
			}

			echo esc_html( $value );
		}

		/**
		 * Adds the unplaced view. Filters views_edit-slosm_store.
		 *
		 * The link is built rather than borrowed: WP_List_Table::get_views_links()
		 * is protected, so this repeats its markup — an anchor, and
		 * `class="current" aria-current="page"` when it is the view being looked
		 * at. The aria-current is not decoration; it is how a screen reader is
		 * told which of the four links is the one whose list is on screen, and
		 * core's own views carry it.
		 *
		 * WP_List_Table::views() prints these strings with no escaping of its
		 * own — `"\t<li class='$class'>$view"` — so everything in here is
		 * escaped at the point it is built. There is nothing an editor typed in
		 * it, and it is escaped anyway, because that argument stops being true
		 * the day somebody puts a count or a term name in the label.
		 *
		 * Core's All link does not have to be un-marked. WP_Posts_List_Table
		 * decides that one with is_base_request(), which is false as soon as
		 * $_GET carries anything besides post_type — so the moment this view's
		 * argument is in the url, All stops calling itself current on its own.
		 *
		 * @param mixed $views The views, keyed by class name.
		 * @return mixed
		 */
		public function views( $views = array() ) {
			if ( ! is_array( $views ) ) {
				return $views;
			}

			$url = add_query_arg(
				self::UNPLACED_ARG,
				self::UNPLACED_VALUE,
				add_query_arg( 'post_type', Post_Type::POST_TYPE, admin_url( 'edit.php' ) )
			);

			$views[ self::VIEW_KEY ] = sprintf(
				'<a href="%s"%s>%s</a>',
				esc_url( $url ),
				self::unplaced_requested() ? ' class="current" aria-current="page"' : '',
				esc_html( __( 'Not on the map', 'store-locator-for-openstreetmap' ) )
			);

			return $views;
		}

		/**
		 * Keeps the unplaced view across the screen's own form. Hooked to
		 * restrict_manage_posts.
		 *
		 * One hidden input, and it is here because `<form id="posts-filter"
		 * method="get">` (wp-admin/edit.php line 488 of WordPress 6.9.1) carries
		 * only post_status, post_type, author and show_sticky — lines 492-500 —
		 * and a GET form replaces the whole query string with its own fields.
		 * Without this, pressing Search, or Apply with no action chosen, lands
		 * the editor back on All.
		 *
		 * Where the note this method was written from turned out to be wrong
		 * ------------------------------------------------------------------
		 * The paragraph above used to end "and the bulk-action Apply drops it
		 * too". Traced for Task 20 and found to be false, which is worth keeping
		 * rather than quietly fixing, because the decision it justified is a
		 * different one once the fact is right.
		 *
		 * WP_List_Table::display_tablenav() prints wp_nonce_field( 'bulk-posts' )
		 * at line 1675, and wp_nonce_field()'s third parameter defaults to true,
		 * so it also prints wp_referer_field() — a hidden `_wp_http_referer`
		 * holding the *current* url, this view's argument included
		 * (wp-includes/functions.php lines 1899-1934). edit.php then builds the
		 * redirect for a handled bulk action out of wp_get_referer(), which
		 * prefers that field (lines 79 and 1975-2011). So a bulk action already
		 * returns the editor to this view, with nothing from this plugin
		 * involved.
		 *
		 * What is left is still worth the input, and it is **one** narrower
		 * reason rather than the two an earlier version of this paragraph
		 * claimed. The one that holds: the search box and the no-action Apply
		 * take the other branch — line 230's `elseif ( ! empty(
		 * $_REQUEST['_wp_http_referer'] ) )`, which redirects to REQUEST_URI
		 * minus the referer and the nonce, so the view is lost unless something
		 * has put it into REQUEST_URI. This input is that something.
		 *
		 * The second reason was also false, and it is recorded rather than
		 * deleted for the same reason the first is. It said that
		 * wp_get_referer() answering false leaves $sendback falling back to a
		 * bare edit.php. Neither half is true. Line 79 is `remove_query_arg(
		 * array( ... ), wp_get_referer() )`, and remove_query_arg() over a
		 * false query reduces to `add_query_arg( 'trashed', false, false )`,
		 * whose three-argument branch tests `false === $args[2]` and
		 * substitutes `$_SERVER['REQUEST_URI']` (wp-includes/functions.php
		 * lines 1139-1152 and 1220-1228). So $sendback is a non-empty string,
		 * `! $sendback` is false and admin_url( $parent_file ) is never
		 * reached — and because REQUEST_URI is the bulk request's own url,
		 * which this input has put the view into, the view is not lost there
		 * either. (The fallback would not have been a bare edit.php in any
		 * case: $parent_file is "edit.php?post_type=$post_type" for every type
		 * but 'post', lines 64-72.)
		 *
		 * The trade, which is real and small
		 * ----------------------------------
		 * WP_Posts_List_Table::extra_tablenav() buffers this hook's output with
		 * three dropdowns and prints core's Filter submit whenever the buffer is
		 * non-empty (lines 594-600). A hidden input is output, so on a screen
		 * where none of the three dropdowns printed anything this adds a Filter
		 * button that filters nothing. That screen is a site with no locations
		 * at all: months_dropdown() queries the whole post type rather than the
		 * current view and returns early only on an empty result
		 * (class-wp-list-table.php lines 738-764), so one location in one month
		 * is already enough for the button. Nobody is on the unplaced view of a
		 * site with no locations.
		 *
		 * Printed only on this post type's screen, only at the top of the table,
		 * and only when this view is the one being looked at. Unconditionally
		 * would put the argument into every form submission from All, which is
		 * the opposite of what it is for.
		 *
		 * @param mixed $post_type The post type whose screen this is.
		 * @param mixed $which     Where in the table the hook fired: 'top' or 'bottom'.
		 * @return void
		 */
		public function keep_view( $post_type = '', $which = 'top' ): void {
			if ( ! is_scalar( $post_type ) || Post_Type::POST_TYPE !== (string) $post_type ) {
				return;
			}

			// WP_Posts_List_Table fires this inside its 'top' branch only, so
			// the check cannot fail there. WP_Media_List_Table fires it with
			// 'bar', and a list table somebody else wrote can fire it twice; two
			// identical hidden inputs in one GET form is a url carrying the same
			// argument twice.
			if ( ! is_scalar( $which ) || 'top' !== (string) $which ) {
				return;
			}

			if ( ! self::unplaced_requested() ) {
				return;
			}

			echo '<input type="hidden" name="' . esc_attr( self::UNPLACED_ARG ) . '" value="' . esc_attr( self::UNPLACED_VALUE ) . '" />';
		}

		/**
		 * Narrows and orders the list. Hooked to pre_get_posts.
		 *
		 * This hook fires for every query on the site — every front-end page,
		 * every feed, every REST list — so almost every time it runs the right
		 * answer is to do nothing, and it has to reach that answer before it
		 * reads anything. Four gates in order of cheapness: the argument has to
		 * be a query at all, it has to be the main one, the request has to be an
		 * admin one, and the query has to be about this post type.
		 *
		 * is_admin() rather than a screen check, and the difference matters on
		 * exactly one path: get_current_screen() does not exist on a front-end
		 * request, and admin_enqueue_scripts is not the only hook a page builder
		 * fires out of context. is_admin() is a constant read, always defined.
		 *
		 * Two things can be asked for, and both can be asked for at once. The
		 * two clause groups are ANDed rather than one overwriting the other, and
		 * any meta_query that was already on the query is nested in beside them
		 * rather than replaced — another plugin narrowing this screen, or a
		 * site's own pre_get_posts, must not be silently widened. On a
		 * membership plugin that would be a list of locations an editor is not
		 * supposed to see.
		 *
		 * The orderby is deliberately left exactly as it arrived.
		 * Store_Repository::sortable_meta_query() names its EXISTS clause with
		 * the string the column heading put in the url, and
		 * WP_Query::parse_orderby() allows an orderby that is the key of a meta
		 * clause — so the sort works by the two agreeing on a name rather than
		 * by this method rewriting anything. Which also means that if this
		 * callback never runs, `orderby=slosm_city_sort` is not in
		 * parse_orderby()'s allowed keys, the method returns false, and the
		 * query falls back to its default order. A broken sort is a list in the
		 * wrong order, never a SQL error.
		 *
		 * @param mixed $query The query, as pre_get_posts hands it over.
		 * @return void
		 */
		public function filter_query( $query = null ): void {
			if ( ! $query instanceof \WP_Query ) {
				return;
			}

			if ( ! $query->is_main_query() || ! is_admin() ) {
				return;
			}

			if ( Post_Type::POST_TYPE !== $query->get( 'post_type' ) ) {
				return;
			}

			$sorting  = self::SORT_CITY === $query->get( 'orderby' );
			$unplaced = self::unplaced_requested();

			if ( ! $sorting && ! $unplaced ) {
				return;
			}

			$groups   = array();
			$existing = $query->get( 'meta_query' );

			if ( is_array( $existing ) && array() !== $existing ) {
				$groups[] = $existing;
			}

			if ( $sorting ) {
				$groups[] = $this->repository()->sortable_meta_query( 'city', self::SORT_CITY );
			}

			if ( $unplaced ) {
				$groups[] = $this->repository()->unplaced_meta_query();
			}

			$query->set(
				'meta_query',
				1 === count( $groups ) ? $groups[0] : array_merge( array( 'relation' => 'AND' ), $groups )
			);
		}

		/**
		 * Prints the on-the-map cell for one location.
		 *
		 * Either the pair or the warning, and the warning is the reason the
		 * column exists. It is a <strong> with a dashicon and a colour rather
		 * than a grey note, because the failure it reports is one nothing else
		 * on the site mentions, and because this is a screen people scan rather
		 * than read. The dashicon is aria-hidden and the sentence beside it is
		 * the accessible text; an icon alone would say nothing to a screen
		 * reader and nothing at all with a stylesheet that failed to load.
		 *
		 * A placed location shows its coordinates, formatted by
		 * Admin::coordinate_string() — the same formatter the edit screen writes
		 * the fields with, so the two screens cannot disagree about how many
		 * decimals a coordinate has.
		 *
		 * @param Store $store The location.
		 * @return void
		 */
		private function print_placement( Store $store ): void {
			if ( $store->has_coordinates() ) {
				echo '<span class="slosm-list__placed">'
					. esc_html(
						Admin::coordinate_string( (float) $store->lat )
						. ', '
						. Admin::coordinate_string( (float) $store->lng )
					)
					. '</span>';

				return;
			}

			echo '<strong class="slosm-list__unplaced">'
				. '<span class="dashicons dashicons-warning" aria-hidden="true"></span> '
				. esc_html( __( 'Not on the map', 'store-locator-for-openstreetmap' ) )
				. '</strong>';
		}

		/**
		 * One location, read once however many columns ask for it.
		 *
		 * The memo is what keeps a three-column row to one record read rather
		 * than three; the class docblock has what that is worth. Null is
		 * memoised too — a row that is not a location of this plugin's is not a
		 * row worth asking about twice — which is why the check is
		 * array_key_exists() and not isset().
		 *
		 * @param int $post_id Post id.
		 * @return Store|null
		 */
		private function record( int $post_id ): ?Store {
			if ( ! array_key_exists( $post_id, $this->records ) ) {
				$this->records[ $post_id ] = $this->repository()->find_for_admin( $post_id );
			}

			return $this->records[ $post_id ];
		}

		/**
		 * Whether the unplaced view is the one being looked at.
		 *
		 * Read off $_GET, because this is not a query var WordPress carries and
		 * registering one would put it in the rewrite rules of a post type that
		 * has none.
		 *
		 * Compared against one literal, and nothing read here ever reaches a
		 * query, a meta key or any markup: the value's only effect is to choose
		 * between two clause groups this class wrote itself. That is the whole
		 * of the validation, and it is why the argument is a switch with one
		 * position rather than a value.
		 *
		 * There is deliberately no wp_unslash(), which is the opposite of what
		 * every other read of a superglobal in this plugin does, so it is worth
		 * one paragraph rather than a surprise. WordPress does slash $_GET —
		 * wp_magic_quotes() at wp-includes/load.php line 1287 of WordPress 6.9.1
		 * — re-checked after review called it 1286; 1286 is the
		 * `// Escape with wpdb.` comment, 1287 is $_GET and 1288 is the $_POST
		 * line Admin::submitted() already cites —
		 * runs add_magic_quotes() over it exactly as over $_POST — but
		 * addslashes() only ever *adds* characters, so addslashes( $v ) is '1'
		 * if and only if $v is '1'. Unslashing first and comparing, or comparing
		 * the slashed value, are true for the same single input and false for
		 * every other one. There is no string that separates them, so the call
		 * would be a line no case could ever fail on, which this project's
		 * standard says to delete rather than to keep for the look of it. The
		 * argument does not survive a comparison against anything containing a
		 * backslash or a quote, and it is the comparison that makes it safe.
		 *
		 * The is_scalar() check is not decoration in the same way. A query
		 * string can carry `slosm_unplaced[]=1`, which arrives as an array, and
		 * (string) on an array is a warning and the word "Array".
		 *
		 * Public since Task 20. Two things outside this class now need the same
		 * answer — keep_view(), which puts the argument back into the screen's
		 * own form, and Bulk_Geocode::handle(), which puts it back onto core's
		 * redirect — and a second reader spelling the comparison out again is
		 * how one of them ends up answering to a value the other does not.
		 *
		 * @return bool
		 */
		public static function unplaced_requested(): bool {
			// The ignore names three sniffs and not one, which is the convention
			// Admin::submitted() already set for a superglobal whose value is
			// read rather than merely tested. MissingUnslash and
			// InputNotSanitized both fire on an assignment out of $_GET that is
			// then used, and the reasoning above is why neither an unslash nor a
			// sanitiser belongs here — a suppressed sniff with the reason beside
			// it beats a call that no case could ever fail on.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- reading which view an editor asked for, on a screen already behind edit_posts; nothing is written, and the value is compared against one literal rather than used.
			$asked = $_GET[ self::UNPLACED_ARG ] ?? null;

			if ( ! is_scalar( $asked ) ) {
				return false;
			}

			return self::UNPLACED_VALUE === (string) $asked;
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
	}
}
