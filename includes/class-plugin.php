<?php
/**
 * Wiring. The one place that decides what gets hooked into WordPress.
 *
 * @package Store_Locator_For_OpenStreetMap
 */

namespace Asymetria\StoreLocator;

use Asymetria\StoreLocator\Admin\Admin;
use Asymetria\StoreLocator\Admin\Bulk_Geocode;
use Asymetria\StoreLocator\Admin\Locations_List;
use Asymetria\StoreLocator\Admin\Settings_Screen;
use Asymetria\StoreLocator\Admin\Shortcode_Generator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( __NAMESPACE__ . '\\Plugin' ) ) {

	/**
	 * The plugin object. One instance, booted at most once.
	 *
	 * Guarded declaration for the reason given in the main plugin file: a second
	 * compile of an unguarded class body is a fatal error, not a warning.
	 */
	final class Plugin {

		/**
		 * The Bricks element's class, as a string.
		 *
		 * A string and not `Bricks_Element::class`, and the difference is the
		 * whole point. `::class` on a class that has not been loaded is only a
		 * compile-time string and would be harmless — but anything that
		 * *touched* the class would put the autoloader on it, and the
		 * autoloader would load a file whose `extends \Bricks\Element` cannot
		 * be satisfied on a site with no Bricks. Naming it as a string means
		 * this class never mentions the element in a way PHP can act on, and
		 * register_bricks_element() checks for Bricks before anything reaches
		 * the autoloader.
		 *
		 * The path is not written down beside it: Autoloader::path_for() turns
		 * this into `admin/class-bricks-element.php`, so moving the file or
		 * renaming the class moves the registration with it, and a case
		 * asserts the path it comes out as is a file that exists.
		 *
		 * @var string
		 */
		public const BRICKS_ELEMENT = 'Asymetria\\StoreLocator\\Admin\\Bricks_Element';

		/**
		 * The single instance.
		 *
		 * @var self|null
		 */
		private static ?self $instance = null;

		/**
		 * Whether boot() has already run.
		 *
		 * @var bool
		 */
		private bool $booted = false;

		/**
		 * The repository whose cache the invalidation hooks flush.
		 *
		 * Held rather than built per callback, and the reason is not thrift.
		 * flush_cache() is an instance method by design — the day locations move
		 * into a custom table, the seam that has to survive is "ask the
		 * repository to invalidate itself", not "delete this transient" — and it
		 * keeps its own once-per-request guard, which a fresh instance per
		 * callback would reset on every hook and defeat entirely.
		 *
		 * Null until boot() runs. Nothing can call the callbacks before then,
		 * since boot() is what registers them, but the property is checked rather
		 * than assumed: a fatal inside a save hook takes the editor's save with
		 * it.
		 *
		 * @var Store_Repository|null
		 */
		private ?Store_Repository $repository = null;

		/**
		 * Private, so the singleton is the only way in.
		 */
		private function __construct() {
		}

		/**
		 * Returns the single instance.
		 *
		 * @return self
		 */
		public static function instance(): self {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}

			return self::$instance;
		}

		/**
		 * Registers the plugin's hooks. Safe to call twice.
		 *
		 * The guard is the point. If this plugin's file is loaded twice — the
		 * theme-paste case the main file describes — bootstrapping runs twice
		 * too, and without the flag every hook here would be registered twice:
		 * a post type registered twice, a shortcode rendered twice, a REST
		 * route fighting itself. Returning early is cheaper than making every
		 * future callback idempotent on its own.
		 *
		 * Everything here goes through add_action(). Nothing is registered
		 * directly: at the moment this runs, WordPress has not fired init yet,
		 * and register_post_type() before init is explicitly unsupported —
		 * taxonomies, capabilities and rewrite rules are not in place, so the
		 * registration would either be ignored or half-applied.
		 *
		 * @return void
		 */
		public function boot(): void {
			if ( $this->booted ) {
				return;
			}

			$this->booted = true;

			/*
			 * The settings option, before anything else on init.
			 *
			 * register_setting() is two array writes and an add_filter(), and
			 * the add_filter() is the one that matters: it hangs
			 * Settings::sanitise() on sanitize_option_slosm_settings, which
			 * update_option() runs on every write of that option anywhere on the
			 * site. First on the hook, because the gate has to be up before
			 * anything on init could write through the door — an importer, a
			 * migration, another plugin's init callback.
			 *
			 * On init rather than on admin_init, which is the convention. The
			 * convention would put the sanitiser on admin requests and nowhere
			 * else, leaving WP-CLI and any front-end write ungated, and the
			 * front end reads this option on every page carrying a locator.
			 * Settings::register() has the core trace, including why options.php
			 * still finds the registration.
			 *
			 * Settings and not Admin\Settings_Screen, which is where this was
			 * written first. Nothing in it is about a screen, and naming the
			 * screen class here meant the one callback that has to run on a
			 * front-end request was the one that named a class from admin/.
			 * Everything else this method builds from admin/ is constructed
			 * further down and unconditionally — see the settings-screen block at
			 * the foot of this method, which says what that costs.
			 *
			 * Static, so this registration costs nothing but the array writes on
			 * a request that never touches settings.
			 */
			add_action( 'init', array( Settings::class, 'register' ), 10, 0 );

			$post_type = new Post_Type();

			add_action( 'init', array( $post_type, 'register' ) );

			/*
			 * The front-end assets. Registered here and enqueued nowhere —
			 * Shortcode::render() is the only thing on the site that knows a
			 * locator exists, so it is the only thing that enqueues. A page with
			 * no locator on it carries none of this, which is the whole of Task
			 * 12.
			 *
			 * On init, and not on wp_enqueue_scripts. That was the first
			 * arrangement and it was wrong on every block theme, for a reason
			 * this comment exists to keep anybody from re-introducing:
			 * wp-includes/template-canvas.php calls
			 * get_the_block_template_html() *above* the doctype — core's own
			 * comment there says it must, "so that blocks can add scripts and
			 * styles in wp_head()" — so core/post-content, the_content and every
			 * shortcode in the page have all already run by the time wp_head
			 * fires wp_enqueue_scripts at priority 1. Registration on that hook
			 * therefore happens *after* the render that is supposed to depend on
			 * it. The script still loads, because WP_Dependencies holds an
			 * enqueue of an unregistered handle in $queued_before_register and
			 * replays it on registration; wp_localize_script() has no such
			 * mercy and returns false, so the interface strings were dropped in
			 * silence.
			 *
			 * init is early enough for every render path and is not "too early":
			 * do_action() raises the hook's count before it runs a single
			 * callback (wp-includes/plugin.php lines 490-494), so inside an init
			 * callback did_action( 'init' ) is already 1 and
			 * _wp_scripts_maybe_doing_it_wrong() returns on its first condition.
			 * Registering scripts on init is silent, and core's own block
			 * registration relies on exactly that.
			 *
			 * What it costs is four array writes on admin and REST requests,
			 * where init fires and nothing prints. Nothing is enqueued there,
			 * and no string is translated there, because both of those still
			 * wait for a locator to actually render.
			 */
			$assets = new Assets();

			add_action( 'init', array( $assets, 'register' ) );

			/*
			 * The admin map picker, on the one screen it belongs on.
			 *
			 * A second hook on the same object rather than a second object,
			 * because the picker's handle names the locator's as a dependency
			 * and the two decisions belong in one place. Nothing is registered
			 * here either: admin_enqueue_scripts fires on every admin page
			 * there is, and Assets::enqueue_admin() is what asks which one this
			 * is — two questions, because the hook suffix alone is every post
			 * type's edit form and the screen's post type alone includes the
			 * list table.
			 *
			 * Unconditionally, without an is_admin() gate, for the reason the
			 * metabox registration below gives: admin_enqueue_scripts cannot
			 * fire outside the admin, so the gate would buy one array write on
			 * a front-end request and one more runtime condition to reason
			 * about.
			 */
			add_action( 'admin_enqueue_scripts', array( $assets, 'enqueue_admin' ), 10, 1 );

			/*
			 * The shortcode, on init and after both of the above, because its
			 * render path asks the taxonomy to resolve a category attribute and
			 * there is no taxonomy to ask before init has run.
			 *
			 * Third on the hook rather than anywhere on it, and that ordering is
			 * load-bearing on exactly one path: a theme or plugin that renders
			 * content from inside its own init callback. All three registrations
			 * are hooked at priority 10 and run in the order they were added, so
			 * the handles exist before anything this plugin registers can render
			 * a locator.
			 *
			 * It is handed the same Assets object that was just hooked, rather
			 * than building its own, so that the object registering the handles
			 * and the object enqueuing them are one thing. Two objects would
			 * work today — the handles are constants and the payload guard asks
			 * WordPress rather than itself — and would be two things to keep
			 * agreeing tomorrow.
			 *
			 * add_shortcode() is called inside Shortcode::register(), never
			 * here. That is not ceremony: this method registering anything
			 * directly is invisible to the suite's hook inventory, which reads
			 * what boot() handed to add_action() and add_filter() and nothing
			 * else. A direct add_shortcode() here would be a registration no
			 * test could see, in the one method whose whole job is to be the
			 * list of them.
			 */
			$shortcode = new Shortcode( $assets );

			add_action( 'init', array( $shortcode, 'register' ) );

			/*
			 * Task 23's Bricks element, and the priority is the whole of what
			 * this line has to get right.
			 *
			 * Bricks\Elements::__construct() hooks its own init_elements() on
			 * init at the default priority, and init_elements() is what
			 * require_once's includes/elements/base.php — so \Bricks\Element,
			 * the class the element extends, does not exist until init
			 * priority 10 has finished. Read from Bricks 2.4's
			 * includes/elements.php lines 11-21, not from documentation.
			 *
			 * Registering at 11 is therefore not a preference. At 10 the order
			 * within the priority depends on which plugin was loaded first, and
			 * losing that race is a `require` of a file whose parent class is
			 * undeclared: a fatal on every request, admin included.
			 *
			 * Unconditionally, with no is_admin() gate, and that is the same
			 * decision as the generator below rather than a separate one — the
			 * Bricks builder canvas *is* the front end, in an iframe, so an
			 * admin-only registration would be an element that exists
			 * everywhere except where it is used.
			 *
			 * Static, so a site with no Bricks pays one array write on init and
			 * a class_exists() that answers false.
			 */
			add_action( 'init', array( self::class, 'register_bricks_element' ), 11, 0 );

			$this->repository = new Store_Repository();

			/*
			 * Cache invalidation. Six hooks, and each one is here because it
			 * catches something none of the others do. Verified against
			 * WordPress 6.9.1's source rather than assumed, since two of them
			 * are hooks people commonly expect to need and do not.
			 *
			 * Two of them are about the same save, and that is deliberate rather
			 * than redundant. A location's post row, its terms and its meta are
			 * written in that order, and save_post fires after the first of the
			 * three: a concurrent front-end request landing between the save and
			 * the meta write caches pre-save coordinates under the generation
			 * that save has just moved to, and nothing afterwards invalidates it.
			 * wp_after_insert_post is what closes that window, and
			 * Store_Repository::flush_cache() has the full reasoning.
			 *
			 * save_post_slosm_store — a location published, updated,
			 *   unpublished, trashed or untrashed, and a scheduled one going
			 *   live. Trash and untrash need no hook of their own:
			 *   wp_trash_post() and wp_untrash_post() both set the new status
			 *   with wp_update_post(), which is wp_insert_post(), which fires
			 *   this. wp_publish_post() fires it directly for the cron path.
			 *   What it does not catch is a permanent delete, which never goes
			 *   through wp_insert_post() — nor the case where a site has set
			 *   EMPTY_TRASH_DAYS to 0, since wp_trash_post() then hands straight
			 *   over to wp_delete_post(). Both land on deleted_post below.
			 *   Type-specific on purpose: the generic save_post fires for every
			 *   post and page on the site.
			 *
			 * wp_after_insert_post — the same save, after its terms and its
			 *   registered meta are in the database. Verified against WordPress
			 *   6.9.1 rather than assumed, because the REST path is not the
			 *   obvious one: WP_REST_Posts_Controller::update_item() calls
			 *   wp_update_post() with $fire_after_hooks false, so this does not
			 *   fire from inside wp_insert_post() there at all — the controller
			 *   calls wp_after_insert_post() itself, after handle_terms() and
			 *   after the meta update. On the classic path wp_insert_post() fires
			 *   it at the end, again after tax_input and meta_input. Generic, so
			 *   the post type is checked; _wp_put_post_revision() leaves
			 *   $fire_after_hooks at its default, so this fires for every revision
			 *   WordPress writes and the check is load-bearing, not tidiness.
			 *   It does not fire at all when a caller passes $fire_after_hooks
			 *   false and does not call it afterwards, which is why
			 *   save_post_slosm_store stays.
			 *
			 * deleted_post — a location deleted for good, from the trash or from
			 *   a site with the trash switched off. Generic, because
			 *   deleted_post_{$post_type} only arrived in WordPress 6.6 and this
			 *   plugin supports 6.0, so the post type is checked in the callback
			 *   instead. That check is load-bearing beyond tidiness: WordPress
			 *   prunes revisions through wp_delete_post(), so an unguarded
			 *   callback would flush once per revision on every save.
			 *
			 * set_object_terms — categories set on a location, from the editor,
			 *   Quick Edit, Bulk Edit or wp_set_post_terms(). It fires for every
			 *   taxonomy on the site, so the callback checks which. It does not
			 *   fire for wp_remove_object_terms().
			 *
			 * deleted_term_relationships — which is what does, and is how an
			 *   importer or another plugin takes a category off a location.
			 *   wp_set_object_terms() also fires it for the categories it
			 *   removes, so the two overlap on the ordinary path; the
			 *   once-per-request guard in flush_cache() is what makes that
			 *   overlap free.
			 *
			 * edited_slosm_store_category — a category renamed. This is the one
			 *   that is easy to leave out and expensive to leave out: the payload
			 *   caches category names, not term ids, so without it every map on
			 *   the site shows the old name until the payload expires. Taxonomy-
			 *   specific, so renaming a post category costs nothing.
			 *
			 * delete_slosm_store_category — a category deleted, which strips it
			 *   from every location's chip list at once.
			 *
			 * Deliberately absent: created_slosm_store_category. A category with
			 *   no locations on it does not appear in the payload, and the first
			 *   location assigned to it fires set_object_terms.
			 *
			 * Two gaps are accepted rather than closed, and both are recorded so
			 * that nobody has to rediscover them:
			 *
			 * - Meta written on its own, with no post save around it. An importer
			 *   using update_post_meta() directly, a WP-CLI run, or Task 9's bulk
			 *   geocoder writing _slosm_lat outside a save fires none of these
			 *   six. Such a caller has to flush explicitly; CACHE_TTL is what
			 *   heals the ones that do not.
			 * - A location whose post_type is changed away by wp_update_post().
			 *   That fires save_post_{the new type} and never deleted_post, so
			 *   the location leaves the map with nothing to say so. Rare enough
			 *   that a hook on the generic save_post — which would then run for
			 *   every post and page on the site — is the worse trade; the ttl
			 *   heals it within a day.
			 */
			add_action( 'save_post_' . Post_Type::POST_TYPE, array( $this, 'invalidate_on_save' ), 10, 2 );
			add_action( 'wp_after_insert_post', array( $this, 'invalidate_after_insert' ), 10, 2 );
			add_action( 'deleted_post', array( $this, 'invalidate_on_delete' ), 10, 2 );
			add_action( 'set_object_terms', array( $this, 'invalidate_on_terms_set' ), 10, 4 );
			add_action( 'deleted_term_relationships', array( $this, 'invalidate_on_terms_removed' ), 10, 3 );

			// Zero arguments, not one: invalidate() declares no parameters, and
			// add_action() with 0 is both accurate and the case core answers with
			// a bare call_user_func().
			add_action( 'edited_' . Post_Type::TAXONOMY, array( $this, 'invalidate' ), 10, 0 );
			add_action( 'delete_' . Post_Type::TAXONOMY, array( $this, 'invalidate' ), 10, 0 );

			/*
			 * The four public routes, on rest_api_init and nowhere else.
			 * register_rest_route() before that hook is registration into a
			 * server that does not exist yet: rest_api_init is fired by
			 * rest_get_server(), which is what builds the WP_REST_Server the
			 * routes go into, so an earlier call has nothing to register with.
			 *
			 * The same repository the invalidation hooks hold, on purpose. It
			 * memoises the map payload for the life of the instance, so a page
			 * that renders a locator and then answers a REST call in the same
			 * request reads the transient once rather than twice — and the
			 * once-per-request flush guard is per instance too.
			 *
			 * The controller is not held in a property. The callback array holds
			 * the object, which is all that keeps it alive, and nothing else in
			 * this class has anything to say to it. Its geocoder is built on
			 * first use, so a request that never reaches a lookup never
			 * constructs one.
			 */
			$controller = new Rest_Controller( $this->repository );

			add_action( 'rest_api_init', array( $controller, 'register_routes' ) );

			/*
			 * The location metabox and its save handler.
			 *
			 * Registered unconditionally rather than behind is_admin(), and that
			 * is a decision rather than an omission. Two of these hooks only
			 * ever fire in the admin anyway — add_meta_boxes_slosm_store does
			 * not exist outside an edit screen — so the gate would save three
			 * array writes on a front-end request and buy nothing else. The
			 * third, save_post_slosm_store, genuinely can fire anywhere: a
			 * front-end form, WP-CLI, an importer. Gating it would make the
			 * plugin's behaviour on those paths depend on a runtime condition;
			 * not gating it leaves one rule in one place, which is that the
			 * handler does nothing at all unless the request carries this box's
			 * nonce field. Admin::save() has the reasoning, and a case pins it.
			 *
			 * The priority is Admin::SAVE_PRIORITY, which is 9, and the 9 is
			 * load-bearing in a way nothing here can see on its own. The flush
			 * registered above runs at 10, and this handler writes coordinates:
			 * at 10 or later it would write them *after* the flush, so a
			 * concurrent front-end request could cache the pre-geocode payload
			 * under the new generation and nothing would ever invalidate it. The
			 * wp_after_insert_post flush would close that window as well — it is
			 * there for the same class of problem — and two guards on a silent
			 * failure of that shape are worth their cost. There is a case in
			 * tests/test-location-metabox.php that fails if this number moves.
			 *
			 * The same repository the invalidation hooks and the REST controller
			 * hold, so a request that saves and then renders reads one memo.
			 * The admin object is not held in a property; the callback arrays
			 * hold it, and nothing else in this class has anything to say to it.
			 * Its geocoder is built on first use, so a save that does not need
			 * one never constructs one.
			 */
			$admin = new Admin( $this->repository );

			add_action( 'add_meta_boxes_' . Post_Type::POST_TYPE, array( $admin, 'add_meta_box' ), 10, 1 );
			add_action( 'save_post_' . Post_Type::POST_TYPE, array( $admin, 'save' ), Admin::SAVE_PRIORITY, 2 );

			/*
			 * The one filter this plugin adds to a core redirect, and it is
			 * Task 18's half of the message channel.
			 *
			 * A block-editor metabox save ends in redirect_post(), whose 302 is
			 * followed by fetch, whose response is discarded unread — and the
			 * render on the far side of that redirect is what reads and deletes
			 * the message the save left. This marks that redirect so the render
			 * can tell it is the discarded one and leave the message alone.
			 *
			 * Two arguments, because the post id is what says whether the
			 * redirect is a location's at all: redirect_post_location fires for
			 * every post type on the site, and marking somebody else's redirect
			 * would be adding a query argument to a url that is none of this
			 * plugin's business. Admin::mark_discarded_render() has the trace,
			 * including why the marker cannot be worked out later and why it
			 * must not re-use core's own parameter name.
			 */
			add_filter( 'redirect_post_location', array( $admin, 'mark_discarded_render' ), 10, 2 );

			/*
			 * The locations list table.
			 *
			 * Five hooks, and four of them cannot fire outside the admin at all
			 * — the two column filters and the sortable one are built by
			 * WP_Posts_List_Table, and views_edit-slosm_store by
			 * WP_List_Table::views(). The fifth, pre_get_posts, fires for every
			 * query on the site, and it is not gated here for the same reason
			 * save_post_slosm_store is not: one rule in one place beats a
			 * runtime condition in two. Locations_List::filter_query() turns
			 * away anything that is not this screen's main admin query before it
			 * reads a thing.
			 *
			 * Three of the five names are dynamic and each is built from
			 * Post_Type::POST_TYPE rather than typed, because the generic
			 * versions — manage_posts_columns, manage_posts_custom_column —
			 * would put an Address column on every post type on the site.
			 *
			 * The sortable filter's name is the *screen* id and not the post
			 * type: WP_List_Table::get_column_info() fires
			 * manage_{$this->screen->id}_sortable_columns, and the screen id for
			 * a list table is 'edit-' plus the post type. The same is true of
			 * views_{$this->screen->id}. Using the post type alone there is a
			 * filter that never fires, silently.
			 *
			 * It is handed the same repository as everything else, so that a
			 * request which renders this screen shares one memo and one
			 * once-per-request flush guard with the rest of the plugin.
			 *
			 * Nothing here enqueues anything; the screen's few declarations of
			 * CSS are printed inline. Assets::PICKER_HOOKS is named for the
			 * picker rather than for the plugin precisely so that this
			 * registration does not reach for it.
			 */
			$locations = new Locations_List( $this->repository );

			add_filter( 'manage_' . Post_Type::POST_TYPE . '_posts_columns', array( $locations, 'columns' ), 10, 1 );
			add_action( 'manage_' . Post_Type::POST_TYPE . '_posts_custom_column', array( $locations, 'render_column' ), 10, 2 );
			add_filter( 'manage_edit-' . Post_Type::POST_TYPE . '_sortable_columns', array( $locations, 'sortable_columns' ), 10, 1 );
			add_filter( 'views_edit-' . Post_Type::POST_TYPE, array( $locations, 'views' ), 10, 1 );
			add_action( 'pre_get_posts', array( $locations, 'filter_query' ), 10, 1 );

			/*
			 * Task 20's bulk geocode, and the one hook of Task 19's class that
			 * only exists because of it.
			 *
			 * restrict_manage_posts is registered here rather than beside the
			 * other four above because it is not about the columns: it prints a
			 * hidden input that keeps the unplaced view across the screen's own
			 * GET form, which is a thing the screen needs only once there is a
			 * bulk action to run from that view. Two arguments, because the hook
			 * passes the post type and which end of the table it is printing —
			 * the first says whether this screen is ours at all, and the second
			 * stops the input being printed twice on a list table that fires the
			 * hook at both ends. Locations_List::keep_view() has the trade it
			 * carries and the core trace behind it.
			 *
			 * The two bulk hooks are named after the *screen* id and not the
			 * post type, for the reason the sortable and views filters above
			 * give: WP_List_Table::bulk_actions() fires
			 * bulk_actions-{$this->screen->id} (line 591 of WordPress 6.9.1) and
			 * wp-admin/edit.php fires handle_bulk_actions-{$screen} off
			 * get_current_screen()->id (line 222), and a list table's screen id
			 * is 'edit-' plus the post type. Written with the post type alone
			 * they are filters that never fire, silently — which for the handler
			 * would be an Apply that redirects having done nothing.
			 *
			 * Three arguments on the handler, because all three matter and the
			 * first two are what keep it from acting: the url to hand back, the
			 * action that was actually chosen — this filter fires for every bulk
			 * action core does not handle itself — and the ids.
			 *
			 * admin_notices carries no arguments, and is registered
			 * unconditionally for the reason the metabox registration gives: it
			 * cannot fire outside the admin, so a gate would buy one array write
			 * on a front-end request and one more runtime condition.
			 * Bulk_Geocode::notice() turns away any screen that is not the one a
			 * run has just redirected to, before it reads anything.
			 *
			 * It is handed the same repository as everything else, so that the
			 * once-per-request flush guard the run relies on is the one the rest
			 * of the request shares. Its geocoder is built on first use, so an
			 * admin request that never runs the action never constructs one.
			 */
			add_action( 'restrict_manage_posts', array( $locations, 'keep_view' ), 10, 2 );

			$bulk = new Bulk_Geocode( $this->repository );

			add_filter( 'bulk_actions-edit-' . Post_Type::POST_TYPE, array( $bulk, 'actions' ), 10, 1 );
			add_filter( 'handle_bulk_actions-edit-' . Post_Type::POST_TYPE, array( $bulk, 'handle' ), 10, 3 );
			add_action( 'admin_notices', array( $bulk, 'notice' ), 10, 0 );

			/*
			 * Task 21's settings screen. Three hooks and no arguments on any of
			 * them, because none of the three is told anything it needs.
			 *
			 * admin_menu and admin_init cannot fire outside the admin, so they
			 * are registered without a gate for the reason the metabox
			 * registration above gives. They are two hooks rather than one
			 * because the two calls need different things to exist:
			 * add_submenu_page() is in wp-admin/includes/plugin.php, and
			 * add_settings_section() and add_settings_field() are in
			 * wp-admin/includes/template.php — neither of which is loaded on a
			 * front-end request, which is also why Settings::register() had to go
			 * on init on its own, up at the top of this method.
			 *
			 * admin_post_slosm_clear_cache is the third, and it is the one that
			 * has a side effect. wp-admin/admin-post.php fires
			 * admin_post_{$action} for any logged-in user who can reach
			 * wp-admin and checks nothing else at all — no nonce, no capability
			 * — so both of those are Settings_Screen::handle_clear_cache()'s own
			 * and there are cases for each. The nopriv variant is deliberately
			 * not registered: a logged-out POST then reaches core's
			 * `do_action( 'admin_post_nopriv' )` with nothing listening and dies
			 * there, which is the right answer and costs no code here.
			 *
			 * It is handed the same repository as everything else, so that the
			 * once-per-request flush guard the button forces past is the one the
			 * rest of the request shares. Its geocoder is built on first use, so
			 * an admin request that never presses the button never constructs
			 * one.
			 */
			$settings = new Settings_Screen( $this->repository );

			add_action( 'admin_menu', array( $settings, 'add_page' ), 10, 0 );
			add_action( 'admin_init', array( $settings, 'add_fields' ), 10, 0 );
			add_action( 'admin_post_' . Settings_Screen::CLEAR_ACTION, array( $settings, 'handle_clear_cache' ), 10, 0 );

			/*
			 * Task 22's screen, and one hook is the whole of it. It writes
			 * nothing, so there is no admin_post_ handler and no admin_init;
			 * it renders itself from the query string and hands back a string
			 * an editor pastes somewhere else.
			 *
			 * It is handed the same Shortcode instance the front end will use
			 * rather than one of its own, which is the point of the screen: the
			 * preview it prints is that object's own answer, not a second
			 * implementation of it.
			 */
			$generator = new Shortcode_Generator( $shortcode );

			add_action( 'admin_menu', array( $generator, 'add_page' ), 10, 0 );
		}

		/**
		 * Hands the locator element to Bricks, if Bricks is there to take it.
		 *
		 * Registered on `init` at priority 11 by boot(); that block says why 11
		 * and not 10.
		 *
		 * WHAT THIS CALL DOES, READ RATHER THAN ASSUMED
		 * ---------------------------------------------
		 * `Bricks\Elements::register_element( $file, $name, $class )` is public
		 * and static — Bricks 2.4, `includes/elements.php` lines 253-284. It
		 * returns at once unless the file `is_readable()`; then it
		 * `require_once`s it; then, **only if** the class name it was
		 * handed is empty or not declared, it guesses at
		 * `end( get_declared_classes() )`; then, **only if** the element name
		 * is empty, it constructs the class and reads `$instance->name`,
		 * `$instance->label` and `$instance->description` off it.
		 *
		 * So the arguments below are chosen, not copied from an example. The
		 * class is passed, because the fallback guess is "whatever class PHP
		 * happened to declare last" and this plugin has an autoloader that can
		 * declare a class at any moment. The name is left empty, because the
		 * alternative is writing `slosm-store-locator` down a second time in a
		 * file that has no way of noticing when the element's own copy changes.
		 *
		 * Both class_exists() calls are needed and neither is redundant.
		 * `\Bricks\Elements` is the registry this calls into; `\Bricks\Element`
		 * is the base class the file about to be required extends, and it is
		 * loaded by `init_elements()` rather than by Bricks' autoloader, so it
		 * is the one that says the `init` race was won rather than merely that
		 * the theme is installed.
		 *
		 * The path is derived from the class rather than typed, so that the two
		 * cannot disagree, and is checked for being readable.
		 *
		 * That check is **redundant against Bricks 2.4**, and the earlier claim
		 * that it prevented "a fatal on every page of the site" was wrong:
		 * `register_element()`'s own first two lines are the same `is_readable()`
		 * and the same quiet `return`. It is kept for two reasons that survive
		 * reading them. The behaviour of somebody else's registry is not this
		 * plugin's to depend on — a version that dropped the check would turn a
		 * broken install into a fatal raised from inside a theme. And the case
		 * that pins it is what notices if `Autoloader::path_for()` ever starts
		 * answering with a path to nothing, which is a fault in this plugin
		 * whether or not Bricks would survive it.
		 *
		 * @return void
		 */
		public static function register_bricks_element(): void {
			if ( ! class_exists( '\\Bricks\\Elements' ) || ! class_exists( '\\Bricks\\Element' ) ) {
				return;
			}

			if ( ! defined( 'SLOSM_DIR' ) ) {
				return;
			}

			$relative = Autoloader::path_for( self::BRICKS_ELEMENT );

			if ( null === $relative ) {
				return;
			}

			$file = SLOSM_DIR . $relative;

			if ( ! is_readable( $file ) ) {
				return;
			}

			\Bricks\Elements::register_element( $file, '', self::BRICKS_ELEMENT );
		}

		/**
		 * Throws the map payload away after a location was saved.
		 *
		 * Two saves are turned away, and both are an editor working rather than
		 * a map changing:
		 *
		 * - An autosave. WordPress fires a save for every one of them, and for a
		 *   draft owned by the current user wp_autosave() calls edit_post(), so
		 *   the location itself is updated and this hook does fire. Unguarded,
		 *   the shared payload is thrown away every few seconds while somebody
		 *   types, and every visitor in that window pays for a rebuild.
		 *   DOING_AUTOSAVE is read directly because WordPress has no
		 *   wp_doing_autosave() to ask — there is a wp_doing_ajax() and a
		 *   wp_doing_cron(), and this is the gap between them.
		 * - An auto-draft. Opening Add New Location inserts one, which fires this
		 *   hook for a post that is not on the map, never was, and cannot get
		 *   there without a second save that fires this hook again.
		 *
		 * Revisions need no guard here. An autosave of a published location is
		 * stored as a post of type 'revision', so the type-specific hook this is
		 * registered on cannot fire for it; wp_is_post_revision() would be a
		 * check against something that cannot happen. The revisions that do have
		 * to be turned away are the ones being deleted, and that guard is in
		 * invalidate_on_delete().
		 *
		 * @param int   $post_id Post id; unused, since the whole payload goes.
		 * @param mixed $post    Post object, or whatever fired the hook.
		 * @return void
		 */
		public function invalidate_on_save( $post_id, $post = null ): void {
			if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
				return;
			}

			if ( is_object( $post ) && 'auto-draft' === ( $post->post_status ?? '' ) ) {
				return;
			}

			$this->invalidate();
		}

		/**
		 * Throws the map payload away again, once the whole location is written.
		 *
		 * This is the flush that matters for correctness, and the one before it
		 * is the flush that opens the window it closes. Between
		 * save_post_slosm_store and here, WordPress writes the location's terms
		 * and its registered meta — its coordinates among them — while the
		 * generation has already moved, so a concurrent front-end request in that
		 * window caches pre-save coordinates under the new generation and nothing
		 * would ever invalidate them. Store_Repository::flush_cache() has the
		 * reasoning; the second generation is the cheap half of it.
		 *
		 * Forced past the once-per-request guard for that exact reason: the guard
		 * exists to stop one save spending three generations on three hooks that
		 * all fire before the data lands, and this is the one hook that fires
		 * after it. Guarded and it would be the flush the guard swallows, which
		 * is the whole bug.
		 *
		 * The same two saves are turned away as in invalidate_on_save(), and for
		 * the same reasons. The post type is checked as well, because this hook
		 * is not type-specific and _wp_put_post_revision() leaves
		 * $fire_after_hooks at its default: without the check, every revision
		 * WordPress writes would spend a generation.
		 *
		 * It also closes a second-order problem worth naming, so that nobody
		 * later decides this hook is redundant. The classic-editor path is safe
		 * today only because core writes tax_input and meta_input before
		 * save_post — and Task 17's metabox handler hooks save_post too, writing
		 * the coordinates a geocode just found. That handler is registered at
		 * priority 9 precisely so it runs before the flush above; this hook is
		 * what makes the ordering survive somebody changing that number.
		 *
		 * @param int   $post_id Post id; unused, since the whole payload goes.
		 * @param mixed $post    Post object, or whatever fired the hook.
		 * @return void
		 */
		public function invalidate_after_insert( $post_id, $post = null ): void {
			if ( ! is_object( $post ) || Post_Type::POST_TYPE !== ( $post->post_type ?? '' ) ) {
				return;
			}

			if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
				return;
			}

			if ( 'auto-draft' === ( $post->post_status ?? '' ) ) {
				return;
			}

			if ( null === $this->repository ) {
				return;
			}

			$this->repository->flush_cache( true );
		}

		/**
		 * Throws the map payload away after a location was deleted for good.
		 *
		 * The post type is checked rather than the hook being type-specific,
		 * because deleted_post_{$post_type} only exists from WordPress 6.6 and
		 * this plugin supports 6.0. The $post parameter has been passed since
		 * 5.5, so reading it is safe across the whole supported range.
		 *
		 * Without the check this is the worst hook of the six. WordPress deletes
		 * a post's revisions through wp_delete_post() before deleting the post
		 * itself, and prunes old revisions on every save, so an unguarded
		 * callback would throw the shared payload away several times per save —
		 * for posts and pages that have nothing to do with this plugin.
		 *
		 * @param int   $post_id Post id; unused, since the whole payload goes.
		 * @param mixed $post    Post object, or whatever fired the hook.
		 * @return void
		 */
		public function invalidate_on_delete( $post_id, $post = null ): void {
			if ( ! is_object( $post ) || Post_Type::POST_TYPE !== ( $post->post_type ?? '' ) ) {
				return;
			}

			$this->invalidate();
		}

		/**
		 * Throws the map payload away after categories were set on a location.
		 *
		 * set_object_terms fires for every taxonomy on the site — post
		 * categories, tags, product attributes — so the taxonomy is what decides
		 * whether this is about a location at all. The object id is not checked:
		 * this taxonomy is registered to one post type, and an id lookup here
		 * would be a query inside a save for an answer the taxonomy already gave.
		 *
		 * @param int    $object_id Object the terms were set on; unused.
		 * @param mixed  $terms     Terms passed to wp_set_object_terms(); unused.
		 * @param mixed  $tt_ids    Term taxonomy ids; unused.
		 * @param string $taxonomy  Taxonomy the terms belong to.
		 * @return void
		 */
		public function invalidate_on_terms_set( $object_id, $terms = null, $tt_ids = null, $taxonomy = '' ): void {
			if ( Post_Type::TAXONOMY !== $taxonomy ) {
				return;
			}

			$this->invalidate();
		}

		/**
		 * Throws the map payload away after categories were taken off a location.
		 *
		 * wp_remove_object_terms() fires this and not set_object_terms, which is
		 * how a bulk edit, an importer or another plugin removes a category.
		 * wp_set_object_terms() fires it too, for the terms it removes on its way
		 * to firing set_object_terms; the once-per-request guard in flush_cache()
		 * is what keeps that from costing a second generation.
		 *
		 * @param int    $object_id Object the terms were removed from; unused.
		 * @param mixed  $tt_ids    Term taxonomy ids; unused.
		 * @param string $taxonomy  Taxonomy the terms belonged to.
		 * @return void
		 */
		public function invalidate_on_terms_removed( $object_id, $tt_ids = null, $taxonomy = '' ): void {
			if ( Post_Type::TAXONOMY !== $taxonomy ) {
				return;
			}

			$this->invalidate();
		}

		/**
		 * Throws the map payload away, in every language.
		 *
		 * Public because it is an action callback: the taxonomy-specific hooks
		 * carry no argument worth inspecting, so they land here directly.
		 *
		 * Nothing is enumerated and nothing is deleted; Store_Repository's own
		 * docblock has why a generation beats both a delete and a sweep. Several
		 * hooks firing for one save cost one generation between them, which is
		 * the repository's guard rather than this class's.
		 *
		 * @return void
		 */
		public function invalidate(): void {
			if ( null === $this->repository ) {
				return;
			}

			$this->repository->flush_cache();
		}
	}
}
