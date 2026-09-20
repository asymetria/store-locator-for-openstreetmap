<?php
/**
 * Registers the front-end assets, and enqueues them only where a locator is.
 *
 * @package Store_Locator_For_OpenStreetMap
 */

namespace Asymetria\StoreLocator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( __NAMESPACE__ . '\\Assets' ) ) {

	/**
	 * Seven handles, registered on every front-end request and enqueued on
	 * almost none of them.
	 *
	 * The whole design in one line
	 * ----------------------------
	 * Registering is free — it writes seven entries into an array WordPress is
	 * holding anyway and emits nothing — and enqueuing is what puts bytes on a
	 * page. So registration happens on init, and enqueuing happens inside
	 * Shortcode::render(), which is the only place in this plugin that knows a
	 * locator exists. A site with one locator on one contact page ships Leaflet
	 * on that page and on no other.
	 *
	 * An eighth handle exists and is deliberately not one of the seven. The
	 * admin map picker is registered *and* enqueued inside enqueue_admin(), on
	 * the location edit screen, because nothing else on a site can ever ask for
	 * it: there is no shortcode that renders it and no replacement story worth
	 * keeping a slot open for. Registering it on init would write two array
	 * entries into every front-end request on the site for a file only the
	 * admin can use, and it would quietly make the sentence above this one
	 * false. See enqueue_admin() for the screen check and why it is two
	 * questions rather than one.
	 *
	 * Three of the seven go one step further. Leaflet.markercluster is not
	 * enqueued by rendering a locator at all — only by rendering one that is
	 * going to cluster, which is a decision about how many locations the site
	 * has. See enqueue_cluster(), and Shortcode::CLUSTER_THRESHOLD for where
	 * the line is and why it is drawn by a count rather than by a question.
	 *
	 * Why init and not wp_enqueue_scripts
	 * -----------------------------------
	 * wp_enqueue_scripts is the hook the handbook names, and it is the wrong one
	 * here. On a block theme it fires *after* the content has rendered:
	 * wp-includes/template-canvas.php calls get_the_block_template_html() above
	 * the doctype, because — core's own comment — "This needs to run before
	 * <head> so that blocks can add scripts and styles in wp_head()". So
	 * the_content, do_shortcode and this plugin's render() have all run by the
	 * time wp_head fires wp_enqueue_scripts at priority 1.
	 *
	 * The failure that caused was not a missing script. WP_Dependencies::enqueue()
	 * files an enqueue of an unregistered handle under $queued_before_register
	 * (class-wp-dependencies.php lines 391-394) and
	 * WP_Dependencies::add() replays it into the queue when registration
	 * eventually happens (lines 287-295), so the script and stylesheet did load,
	 * in the footer, versioned and deferred. What was lost was the strings:
	 * wp_localize_script() is WP_Scripts::add_data() underneath, which returns
	 * false on its first line for a handle that is not registered yet
	 * (class-wp-scripts.php lines 843-845), and nothing replays that.
	 *
	 * init is not too early. do_action() raises the hook's count before running
	 * any callback (wp-includes/plugin.php lines 490-494), so inside an init
	 * callback did_action( 'init' ) is already 1 and
	 * _wp_scripts_maybe_doing_it_wrong() returns on its first condition
	 * (functions.wp-scripts.php lines 41-45). The registration is silent, and
	 * core's own block registration depends on the same fact.
	 *
	 * Enqueuing after wp_enqueue_scripts has already fired
	 * ----------------------------------------------------
	 * That is not a workaround, and it is not a race. Verified against WordPress
	 * 6.9.1 rather than assumed:
	 *
	 * - wp_enqueue_scripts is fired from wp_head at priority 1, and the head is
	 *   printed at priority 8 (styles) and 9 (scripts) —
	 *   wp-includes/default-filters.php lines 345, 355 and 356. A shortcode in
	 *   post content runs during the_content(), inside the body, so all three
	 *   have already happened.
	 * - The footer pass is what catches anything queued since.
	 *   wp_print_footer_scripts runs on wp_footer at priority 20
	 *   (default-filters.php line 363) and calls _wp_footer_scripts(), which is
	 *   print_late_styles() followed by print_footer_scripts()
	 *   (wp-includes/script-loader.php line 2278).
	 * - Neither pass is choosy in the direction that would hurt.
	 *   WP_Scripts::do_item() skips a handle only when the *head* pass meets a
	 *   footer-group item — `if ( 0 === $group && $this->groups[ $handle ] > 0 )`
	 *   at class-wp-scripts.php line 285 — and there is no matching test the
	 *   other way, so the footer pass prints head-group handles too.
	 *   WP_Styles::do_item() ignores the group argument entirely
	 *   (class-wp-styles.php line 151), which is why print_late_styles() exists
	 *   at all.
	 *
	 * So a script enqueued from a shortcode is printed in the footer whatever
	 * its group says, and on a classic theme $in_footer is not what makes that
	 * work. Where it earns its place is the case above: on a block theme the
	 * content has rendered and the enqueue has happened *before* the head pass
	 * runs, so without the footer group the locator script would be printed in
	 * the head, above the element it initialises. That is not a hypothetical
	 * theme doing something unusual — it is every block theme. On WordPress 6.0
	 * there is no defer to fall back on either, so the group is the only thing
	 * standing between that arrangement and a script that runs before its
	 * container.
	 *
	 * The stylesheet arriving in the footer is a real cost and is not solvable
	 * from here: the page has already been sent by the time this plugin learns a
	 * locator is on it, and the alternative is a stylesheet on every page of the
	 * site. A map that paints one frame unstyled is the cheaper of the two.
	 *
	 * Why defer is set with wp_script_add_data() and not in an args array
	 * -------------------------------------------------------------------
	 * 'strategy' is a key of the $args array that 6.3.0 overloaded onto
	 * wp_register_script()'s fifth parameter, which until then was a plain
	 * boolean $in_footer — core says so itself, in the @since line at
	 * wp-includes/functions.wp-scripts.php line 160. This plugin's floor is 6.0.
	 *
	 * Passing the array anyway very probably works: pre-6.3 core would take the
	 * array as $in_footer, find it truthy, and put the handle in the footer
	 * group while ignoring the strategy. But "very probably" is a claim about a
	 * line of 6.0 that is not on this machine — there is no 6.0 tree here and no
	 * network to fetch one — and this project has been wrong about core eight
	 * times by reasoning that way. So the two things are asked for separately,
	 * and both halves are read out of code that is on disk:
	 *
	 * - A boolean fifth argument is the shape every version from 2.1 to 6.9.1
	 *   accepts. 6.9.1 normalises it to array( 'in_footer' => (bool) $args ) on
	 *   arrival (functions.wp-scripts.php lines 182-186), so nothing is given up
	 *   by passing it on a modern site.
	 * - wp_script_add_data() has existed since 4.2.0 (functions.wp-scripts.php
	 *   line 440) and is exactly what wp_register_script() itself calls for the
	 *   strategy key on 6.9.1 (line 195-197), so the registered object ends up
	 *   identical. A version that has no delayed-strategy feature cannot read
	 *   the key, which makes the no-op an absence rather than a guess: the data
	 *   sits in the handle's extra array and nothing ever looks at it.
	 *
	 * Versions come from SLOSM_VERSION, never from false
	 * --------------------------------------------------
	 * false is the default and it is a trap. WP_Scripts::do_item() turns a falsy
	 * version into $this->default_version — the WordPress version
	 * (class-wp-scripts.php line 302) — so the query string would move when
	 * WordPress is updated and stand still when this plugin is. A visitor who
	 * cached locator.js before an update keeps it after one.
	 *
	 * The strings, and where they are not
	 * -----------------------------------
	 * Shortcode's docblock records the split and this is the other half of it.
	 * Settings differ per locator and travel in a data- attribute on the
	 * container; interface strings are identical for every locator on the site
	 * and travel once, in one wp_localize_script() payload keyed to the script
	 * handle. The collision that makes wp_localize_script() wrong for settings —
	 * one variable per handle, not per instance — is precisely what makes it
	 * right for these.
	 *
	 * wp_set_script_translations() is deliberately not called. It would append
	 * 'wp-i18n' to this script's dependencies — WP_Scripts::set_translations(),
	 * class-wp-scripts.php line 698 — so every page carrying a locator would
	 * load wp-i18n and whatever it pulls in, to read a languages/*.json file
	 * this plugin does not ship. The strings below are translated on the server
	 * by __() before they are ever encoded, which is enough for as long as no
	 * JavaScript in this plugin calls __() itself. The task that ships such
	 * JavaScript is the task that should revisit this.
	 *
	 * Every return value here is read
	 * -------------------------------
	 * Three functions this class calls report failure with a bare false, and all
	 * three are called for effect, which is how a payload went missing for a
	 * whole class of themes without a single test noticing. So:
	 * wp_register_script()'s return decides whether the defer is applied, since
	 * a false means some other plugin already owns that handle and the script it
	 * registered is not ours to change; and wp_localize_script() is only reached
	 * once the handle is known to be registered and the payload is known not to
	 * be attached yet, so a render that could not attach leaves the next locator
	 * on the page free to try again. It is the same lesson the repository
	 * learned from update_option() and the geocoder from set_transient().
	 *
	 * What this class does not decide
	 * -------------------------------
	 * It writes no JavaScript and no CSS. The two Leaflet handles point at
	 * assets/leaflet/, which Task 13 vendored at 1.9.4; before that they pointed
	 * at files that were not there yet, which was deliberate — an empty
	 * placeholder would have answered 200 with no Leaflet in it, and the failure
	 * would have read as "L is not defined" in a console rather than as a
	 * missing file. No CDN url is invented for the same reason a CDN is not used
	 * at all: the library ships with the plugin.
	 */
	final class Assets {

		/**
		 * The Leaflet library.
		 *
		 * Registered under its own handle rather than bundled into one file, so
		 * a site that already has Leaflet can deregister this and register its
		 * own against the same handle without touching the locator script.
		 *
		 * @var string
		 */
		public const SCRIPT_LEAFLET = 'slosm-leaflet';

		/**
		 * Leaflet's stylesheet.
		 *
		 * @var string
		 */
		public const STYLE_LEAFLET = 'slosm-leaflet-css';

		/**
		 * This plugin's own front-end script.
		 *
		 * @var string
		 */
		public const SCRIPT_LOCATOR = 'slosm-locator';

		/**
		 * This plugin's own stylesheet.
		 *
		 * @var string
		 */
		public const STYLE_LOCATOR = 'slosm-locator-css';

		/**
		 * The half of that stylesheet a site can switch off.
		 *
		 * A separate handle rather than a flag on the one above, and the split
		 * is the same kind as STYLE_CLUSTER against STYLE_CLUSTER_DEFAULT: the
		 * base is the thing working at all, and this is a skin. What is
		 * different here is what happens when the skin is missing — an
		 * unstyled form, rather than a map that renders as nothing — which is
		 * why only this half answers a setting. assets/css/locator.css has the
		 * whole argument in its header.
		 *
		 * Registered on every front-end request whatever the setting says, so
		 * that a site which switched it off globally can still enqueue it by
		 * name on one template.
		 *
		 * @var string
		 */
		public const STYLE_LOCATOR_SKIN = 'slosm-locator-skin-css';

		/**
		 * Leaflet.markercluster, which is on almost no page that has a locator.
		 *
		 * Registered on every front-end request and enqueued only from
		 * enqueue_cluster(), which Shortcode::render() calls when and only when
		 * the locator it is rendering is going to cluster. That is 34 KB of
		 * JavaScript and two stylesheets a twenty-branch site never downloads —
		 * the promise assets/markercluster/README.md makes in as many words.
		 *
		 * @var string
		 */
		public const SCRIPT_CLUSTER = 'slosm-markercluster';

		/**
		 * The cluster library's positioning and spiderfy stylesheet.
		 *
		 * @var string
		 */
		public const STYLE_CLUSTER = 'slosm-markercluster-css';

		/**
		 * The cluster library's default green/yellow/orange bubbles.
		 *
		 * A separate handle from STYLE_CLUSTER rather than one of two enqueued
		 * together, because the two files are different kinds of thing: the base
		 * is the library working at all, and this is a skin. A site that wants
		 * its own bubble colours dequeues this one and keeps the other. It is
		 * also the only cluster stylesheet ever enqueued — the base arrives as
		 * its declared dependency, the way Leaflet arrives behind the locator.
		 *
		 * @var string
		 */
		public const STYLE_CLUSTER_DEFAULT = 'slosm-markercluster-default-css';

		/**
		 * The JavaScript variable the shared strings arrive in.
		 *
		 * @var string
		 */
		public const L10N_OBJECT = 'slosmL10n';

		/**
		 * The admin map picker's script.
		 *
		 * @var string
		 */
		public const SCRIPT_ADMIN = 'slosm-admin';

		/**
		 * The JavaScript variable the admin screen's strings arrive in.
		 *
		 * @var string
		 */
		public const L10N_ADMIN_OBJECT = 'slosmAdminL10n';

		/**
		 * The admin script, relative to the plugin root.
		 *
		 * @var string
		 */
		public const SRC_ADMIN = 'assets/js/admin.js';

		/**
		 * The menu slug of the shortcode generator screen.
		 *
		 * A page slug in includes/ looks misplaced until you ask who needs it:
		 * enqueue_admin() below has to recognise that screen by its hook
		 * suffix, and includes/ may not refer to a class in admin/. Settings
		 * has the same shape for the same reason, and its file header argues
		 * the rule. Admin\Shortcode_Generator::PAGE reads this constant, which
		 * is the allowed direction.
		 *
		 * @var string
		 */
		public const SCREEN_SHORTCODE = 'slosm-shortcode';

		/**
		 * The shortcode generator's script.
		 *
		 * @var string
		 */
		public const SCRIPT_SHORTCODE = 'slosm-shortcode';

		/**
		 * The generator script, relative to the plugin root.
		 *
		 * @var string
		 */
		public const SRC_SHORTCODE = 'assets/js/shortcode.js';

		/**
		 * The JavaScript variable the generator's strings arrive in.
		 *
		 * @var string
		 */
		public const L10N_SHORTCODE_OBJECT = 'slosmCopyL10n';

		/**
		 * The one stylesheet this plugin's admin screens share.
		 *
		 * One handle for three screens — the location edit form, the locations
		 * list and the settings page — rather than three, because the file is a
		 * couple of hundred bytes and a separate request per screen would cost
		 * more than every declaration in it put together. assets/css/admin.css
		 * has what is in it and what deliberately is not.
		 *
		 * @var string
		 */
		public const STYLE_ADMIN = 'slosm-admin-css';

		/**
		 * The admin stylesheet, relative to the plugin root.
		 *
		 * @var string
		 */
		public const SRC_ADMIN_STYLE = 'assets/css/admin.css';

		/**
		 * The admin screens the shared stylesheet belongs on.
		 *
		 * The two picker hooks plus the list table. The settings screen is not
		 * here and cannot be: its hook suffix is built by core out of the parent
		 * menu at runtime, so it is recognised by its shape instead — see
		 * is_settings_screen().
		 *
		 * @var string[]
		 */
		public const STYLE_HOOKS = array( 'post.php', 'post-new.php', 'edit.php' );

		/**
		 * The admin screens *the picker* belongs on.
		 *
		 * Named for the feature and not for the class, because the obvious
		 * shorter name is a trap with a date on it. Tasks 19 and 20 both want
		 * assets on edit.php — the list table and its bulk action — and a
		 * constant called ADMIN_HOOKS reads like "the admin screens this plugin
		 * uses", so whoever writes them adds edit.php to it and ships a Leaflet
		 * map and a metabox script to a list table that has neither. There is a
		 * mutation for exactly that addition, and it is killed by a case; the
		 * name is what stops somebody having to learn it from a red suite.
		 *
		 * @var string[]
		 */
		public const PICKER_HOOKS = array( 'post.php', 'post-new.php' );

		/**
		 * Where each handle's file lives, relative to the plugin root.
		 *
		 * @var string
		 */
		public const SRC_LEAFLET = 'assets/leaflet/leaflet.js';

		/**
		 * Leaflet's stylesheet, relative to the plugin root.
		 *
		 * @var string
		 */
		public const SRC_LEAFLET_STYLE = 'assets/leaflet/leaflet.css';

		/**
		 * The locator script, relative to the plugin root.
		 *
		 * @var string
		 */
		public const SRC_LOCATOR = 'assets/js/locator.js';

		/**
		 * The locator stylesheet, relative to the plugin root.
		 *
		 * @var string
		 */
		public const SRC_LOCATOR_STYLE = 'assets/css/locator.css';

		/**
		 * The skin, relative to the plugin root.
		 *
		 * @var string
		 */
		public const SRC_LOCATOR_SKIN_STYLE = 'assets/css/locator-skin.css';

		/**
		 * The cluster library, relative to the plugin root.
		 *
		 * @var string
		 */
		public const SRC_CLUSTER = 'assets/markercluster/leaflet.markercluster.js';

		/**
		 * The cluster library's base stylesheet, relative to the plugin root.
		 *
		 * @var string
		 */
		public const SRC_CLUSTER_STYLE = 'assets/markercluster/MarkerCluster.css';

		/**
		 * The cluster library's default skin, relative to the plugin root.
		 *
		 * @var string
		 */
		public const SRC_CLUSTER_DEFAULT_STYLE = 'assets/markercluster/MarkerCluster.Default.css';

		/**
		 * Declares the four handles. Call on init, not before.
		 *
		 * Nothing here emits a byte. Registration is a note to WordPress saying
		 * what these handles mean if something asks for them, and on the great
		 * majority of requests nothing ever does. The class docblock has why the
		 * hook is init rather than wp_enqueue_scripts, and it is a fact about
		 * block themes rather than a preference.
		 *
		 * A site that wants to supply its own Leaflet registers
		 * slosm-leaflet on init at a priority below this plugin's, and its
		 * registration wins: WP_Dependencies::add() returns false and keeps the
		 * first registration rather than overwriting it
		 * (class-wp-dependencies.php lines 283-286). That is a supported
		 * arrangement, not a collision, which is why nothing here complains
		 * about it.
		 *
		 * @return void
		 */
		public function register(): void {
			$version = $this->version();

			wp_register_style( self::STYLE_LEAFLET, $this->url( self::SRC_LEAFLET_STYLE ), array(), $version );
			wp_register_style( self::STYLE_LOCATOR, $this->url( self::SRC_LOCATOR_STYLE ), array( self::STYLE_LEAFLET ), $version );

			/*
			 * The layout layer is declared as the skin's dependency, and that
			 * is the ordering rather than a note: all_deps() appends a handle's
			 * dependencies to the to-do list before the handle itself, so the
			 * skin is printed after the file it is allowed to override. Two
			 * handles enqueued side by side would come out in queue order,
			 * which is the order of two lines in enqueue() rather than a fact
			 * anything holds to.
			 */
			wp_register_style( self::STYLE_LOCATOR_SKIN, $this->url( self::SRC_LOCATOR_SKIN_STYLE ), array( self::STYLE_LOCATOR ), $version );

			// A boolean fifth argument, not an args array; the class docblock
			// has why, and it is about the 6.0 floor rather than about style.
			wp_register_script( self::SCRIPT_LEAFLET, $this->url( self::SRC_LEAFLET ), array(), $version, true );

			/*
			 * The defer is applied only if this call is what registered the
			 * handle. A false means somebody else got there first, and stamping
			 * a loading strategy onto another plugin's script — one this plugin
			 * knows nothing about, which may have inline code after it that
			 * cannot be delayed — is not ours to do.
			 *
			 * Only the locator script is deferred. Leaflet would in fact be
			 * eligible for it: WP_Scripts::filter_eligible_strategies() narrows
			 * a handle's strategies by its *dependents*, not its dependencies,
			 * and the only dependent here is slosm-locator, whose own eligible
			 * set is exactly array( 'defer' ). An earlier version of this
			 * comment claimed core would filter it away, which was wrong. It is
			 * left blocking on purpose instead: Leaflet is printed first and
			 * executes immediately, so window.L exists for anything else on the
			 * page that wants it — a theme's inline script, another plugin —
			 * rather than appearing at some point during the deferred queue.
			 * Deferring it would work too; blocking is the smaller claim to make
			 * about a library this plugin only vendors.
			 */
			if ( wp_register_script( self::SCRIPT_LOCATOR, $this->url( self::SRC_LOCATOR ), array( self::SCRIPT_LEAFLET ), $version, true ) ) {
				wp_script_add_data( self::SCRIPT_LOCATOR, 'strategy', 'defer' );
			}

			/*
			 * The cluster library, declared and not enqueued. Registering costs
			 * three array entries and emits nothing; what a visitor pays for is
			 * the enqueue, and that happens in enqueue_cluster() on the small
			 * number of pages whose locator is actually going to cluster.
			 *
			 * Blocking, with no strategy, and that is load-bearing rather than a
			 * preference. enqueue_cluster() runs *after* enqueue(), because
			 * whether a locator clusters is not known until its config has been
			 * built — so the queue is [ locator, markercluster ] and the html
			 * carries the locator tag above the cluster tag.
			 *
			 * Two different things make that order safe, and the second is the
			 * one the floor depends on.
			 *
			 * On 6.3 and up the locator tag carries defer, and a deferred script
			 * runs only once the parser has finished — by which point every
			 * blocking script in the document has executed, including one below
			 * it. Deferring this handle as well would put the two in document
			 * order and locator.js would run first.
			 *
			 * On 6.0 to 6.2 there is no delayed-strategy feature at all, so the
			 * data this class attaches is a key nothing reads and the locator is
			 * an ordinary blocking tag in the footer — which would run *before*
			 * the cluster tag below it. What saves it is a decision Task 13 made
			 * for a different reason: locator.js ends on
			 * `if ( 'loading' === document.readyState )`, and a blocking script
			 * in the footer runs while the parser is still working, so it takes
			 * that branch and waits for DOMContentLoaded. The cluster tag has
			 * executed long before that fires.
			 *
			 * So the readyState branch in locator.js is not redundant with the
			 * defer, and deleting it as belt-and-braces would break clustering
			 * on precisely the versions this plugin declares as its floor.
			 *
			 * That ordering is not the only thing standing between a site and a
			 * broken map: locator.js checks for L.markerClusterGroup and falls
			 * back to plain pins with a console warning, which also covers the
			 * site that dequeued this handle or whose request for it failed.
			 */
			wp_register_script( self::SCRIPT_CLUSTER, $this->url( self::SRC_CLUSTER ), array( self::SCRIPT_LEAFLET ), $version, true );

			wp_register_style( self::STYLE_CLUSTER, $this->url( self::SRC_CLUSTER_STYLE ), array( self::STYLE_LEAFLET ), $version );
			wp_register_style( self::STYLE_CLUSTER_DEFAULT, $this->url( self::SRC_CLUSTER_DEFAULT_STYLE ), array( self::STYLE_CLUSTER ), $version );
		}

		/**
		 * Puts the locator on this page. Called from Shortcode::render().
		 *
		 * The two enqueues are unguarded because WordPress already deduplicates
		 * them: WP_Dependencies::enqueue() adds a handle to the queue only if it
		 * is not in it already. The payload is the one that needs a guard, and
		 * it has its own; see localize().
		 *
		 * Leaflet is not enqueued here. It is a declared dependency of both
		 * enqueued handles, and WP_Dependencies::all_deps() walks a handle's
		 * dependencies and appends them to the to-do list before the handle
		 * itself (class-wp-dependencies.php lines 216-251), so the library is
		 * printed first without being queued in its own right. That also means a
		 * site dequeuing slosm-locator gets rid of Leaflet with it, which would
		 * not be true of a library enqueued separately.
		 *
		 * On a request where nothing registered the handles — a REST call
		 * rendering content.rendered, where init runs but this plugin's
		 * registration may have been unhooked, or a bare process — this degrades
		 * rather than fails. An enqueue of an unregistered handle is held in
		 * $queued_before_register and never printed unless something registers
		 * it, and localize() declines to spend three translations on a payload
		 * it cannot attach. Nothing prints, nothing warns, and the markup is
		 * unaffected.
		 *
		 * @return bool Whether the assets can still reach the page.
		 */
		public function enqueue(): bool {
			wp_enqueue_style( self::STYLE_LOCATOR );

			/*
			 * The one conditional here, and it is a site-wide fact rather than
			 * a per-locator one — which is why it is read here and not passed
			 * in the way the cluster decision is. A stylesheet is a property of
			 * the page, and two locators on one page disagreeing about whether
			 * the skin is loaded is not a state that can exist.
			 */
			if ( Settings::get( 'skin' ) ) {
				wp_enqueue_style( self::STYLE_LOCATOR_SKIN );
			}

			wp_enqueue_script( self::SCRIPT_LOCATOR );

			$this->localize();

			if ( ! $this->in_time() ) {
				_doing_it_wrong(
					__METHOD__,
					sprintf(
						// esc_html__ rather than __: _doing_it_wrong() prints its
						// message without escaping it, and a translation is a
						// .po file somebody may have edited. The translators
						// note stays directly above the string, where
						// tests/test-i18n.php requires it.
						/* translators: %s: the wp_footer template tag. */
						esc_html__(
							'A store locator was rendered after the footer scripts had already been printed, so its script and stylesheet cannot reach the page. Render [store_locator] before %s.',
							'store-locator-for-openstreetmap'
						),
						'wp_footer()'
					),
					'1.0.0'
				);

				return false;
			}

			return true;
		}

		/**
		 * Adds the cluster library to this page. Called from Shortcode::render().
		 *
		 * Separate from enqueue() rather than a flag on it, and the separation
		 * is about an invariant rather than about tidiness. enqueue() is the
		 * first line of render() so that it cannot be skipped by an early return
		 * somebody adds later; the cluster decision cannot be made there,
		 * because it is a fact about the config, which does not exist yet. Two
		 * methods keep the unskippable one unskippable and let the conditional
		 * one be conditional.
		 *
		 * One stylesheet and one script. The base cluster stylesheet is not
		 * enqueued here: it is STYLE_CLUSTER_DEFAULT's declared dependency, and
		 * WP_Dependencies::all_deps() appends a handle's dependencies to the
		 * to-do list before the handle itself — so it is printed first without
		 * being queued, and a site dequeuing the skin does not lose it.
		 *
		 * Unguarded, like the two in enqueue() and for the same reason:
		 * WP_Dependencies::enqueue() adds a handle to the queue only if it is
		 * not in it already, so two clustering locators on one page cost what
		 * one does.
		 *
		 * @return void
		 */
		public function enqueue_cluster(): void {
			wp_enqueue_style( self::STYLE_CLUSTER_DEFAULT );
			wp_enqueue_script( self::SCRIPT_CLUSTER );
		}

		/**
		 * Puts this plugin's admin assets on the screens that want them.
		 *
		 * Two different answers in one method, because there is one hook and one
		 * screen question:
		 *
		 * - The **stylesheet** goes on three screens: the location edit form,
		 *   the locations list and the settings page. Task 21 moved two inline
		 *   `<style>` blocks into assets/css/admin.css, and one of the two
		 *   belongs to the list table.
		 * - The **picker** — Leaflet, its stylesheet, admin.js and the strings —
		 *   goes on the edit form and nowhere else, exactly as before.
		 *
		 * Hooked to admin_enqueue_scripts, which WordPress fires from
		 * wp-admin/admin-header.php line 123 with the page's hook suffix — and
		 * fires *after* $current_screen exists, which the two lines above it
		 * printing `pagenow` and `typenow` out of it make plain.
		 *
		 * Two questions are asked and both are needed. The hook suffix narrows
		 * it to an edit form, because the screen's post type is `slosm_store`
		 * on the list table too and there is no metabox there to bind to. The
		 * screen's post type narrows it to *this* post type, because post.php
		 * is every post type's edit form. Either check on its own ships Leaflet
		 * to a screen that has no use for it.
		 *
		 * get_current_screen() is asked whether it is there at all first. It
		 * really does answer null before set_current_screen() has run — its own
		 * signature says WP_Screen|null — and reading ->post_type off null is a
		 * fatal inside an admin request rather than a map that did not appear.
		 *
		 * Registered here rather than on init with the other seven, and that is
		 * the one place this class breaks its own rule on purpose. The seven are
		 * registered on init because something else may want to ask for them: a
		 * shortcode renders them, and a site swapping the bundled Leaflet
		 * registers its own against the same handle before this plugin can. The
		 * picker has neither story — nothing on the site can enqueue it, and
		 * there is no render path that could — so the handle exists on the one
		 * screen that uses it and on no other request on the site. A case in
		 * tests/test-assets.php counts the front-end handles and would fail if
		 * this became an eighth.
		 *
		 * Leaflet's stylesheet is enqueued by name. It is not a dependency of a
		 * script, and a script cannot pull a stylesheet in behind it.
		 *
		 * @param mixed $hook_suffix Admin page, as WordPress names it.
		 * @return void
		 */
		public function enqueue_admin( $hook_suffix = '' ): void {
			$hook = is_scalar( $hook_suffix ) ? (string) $hook_suffix : '';

			// Not ceremony, and not reachable from the unit suite either: a
			// page builder or an optimiser firing admin_enqueue_scripts from a
			// front-end request has no wp-admin/includes/screen.php loaded, and
			// an undefined function is a fatal that takes the page with it.
			// Assets::url() guards SLOSM_URL for the same reason and says so.
			// There is a child-process case for this, because a function cannot
			// be un-defined inside a running process.
			if ( ! function_exists( 'get_current_screen' ) ) {
				return;
			}

			$screen = get_current_screen();

			// `?? ''` and no is_object() in front of it. The guard was there
			// and a mutation removing it survived the whole suite, correctly:
			// the null-coalescing operator has isset() semantics, so a null, a
			// string and an array all answer '' here and none of them raises a
			// diagnostic — measured on PHP 8.5 with error_reporting( E_ALL ).
			// A condition no input can reach is a claim no case can check.
			$mine = Post_Type::POST_TYPE === ( $screen->post_type ?? '' );

			/*
			 * The shared stylesheet, on three screens, and before the early
			 * return below. Task 21 moved two inline <style> blocks into
			 * assets/css/admin.css, and one of the two belongs to the locations
			 * list — a screen the picker deliberately does not load on.
			 *
			 * The settings page is recognised by the *shape* of its hook suffix
			 * rather than by a constant, because there is no constant to write:
			 * core builds it at runtime as `{page_type}_page_{slug}` in
			 * get_plugin_page_hookname() (wp-admin/includes/plugin.php lines
			 * 2158-2177 of WordPress 6.9.1), and the page type is whatever
			 * $admin_page_hooks holds for the parent menu. The slug is this
			 * plugin's, so the ending is exact; and the check lives here, asking
			 * Settings::PAGE rather than Admin\Settings_Screen, so that nothing in
			 * includes/ *refers* to a class in admin/. Not so that nothing loads
			 * one — Plugin::boot() constructs four admin objects on every request,
			 * this one included, and an earlier version of this sentence implied
			 * otherwise. It is a layering rule, and it is worth keeping for the
			 * layering reason alone: this method runs on admin_enqueue_scripts,
			 * where the admin classes are loaded anyway.
			 *
			 * The post-type check still applies to the two list-and-edit
			 * screens — post.php is every post type's edit form — and does not
			 * apply to the settings page, whose hook suffix nothing else on the
			 * site can produce.
			 */
			if ( ( $mine && in_array( $hook, self::STYLE_HOOKS, true ) )
				|| self::is_settings_screen( $hook )
				|| self::is_shortcode_screen( $hook ) ) {
				wp_enqueue_style( self::STYLE_ADMIN, $this->url( self::SRC_ADMIN_STYLE ), array(), $this->version() );
			}

			/*
			 * The generator's copy button, and only on the generator. It has no
			 * dependency at all — no Leaflet, no jQuery, no front-end script —
			 * because the whole of it is one button over a textarea, and the
			 * screen it is on works without it. That is the point of Task 22's
			 * preview being text: this admin screen costs a stylesheet and
			 * about a kilobyte of JavaScript, where a real map on it would have
			 * cost Leaflet, the locator, a REST round trip and the marker
			 * cluster bundle.
			 *
			 * Registered inside the branch rather than in register(), the way
			 * the picker is: register() runs on every request there is, and a
			 * handle nothing will ever enqueue on the front end has no business
			 * being declared there.
			 */
			if ( self::is_shortcode_screen( $hook ) ) {
				if ( wp_register_script(
					self::SCRIPT_SHORTCODE,
					$this->url( self::SRC_SHORTCODE ),
					array(),
					$this->version(),
					true
				) ) {
					wp_script_add_data( self::SCRIPT_SHORTCODE, 'strategy', 'defer' );
					wp_enqueue_script( self::SCRIPT_SHORTCODE );
					wp_localize_script( self::SCRIPT_SHORTCODE, self::L10N_SHORTCODE_OBJECT, $this->copy_strings() );
				}
			}

			// And from here down it is the picker, on the edit form only. The
			// hook suffix narrows it to an edit form, because the screen's post
			// type is slosm_store on the list table too and there is no metabox
			// there to bind to.
			if ( ! $mine || ! in_array( $hook, self::PICKER_HOOKS, true ) ) {
				return;
			}

			$version = $this->version();

			/*
			 * Leaflet, and nothing else. Until Task 21 this handle also declared
			 * slosm-locator, so that admin.js could read the tile url and its
			 * attribution out of the locator's frozen namespace rather than
			 * carrying a second copy of a line the ODbL requires to be on the
			 * map. That cost 150 KB of unminified front-end JavaScript on an
			 * edit screen, and assets/js/admin.js recorded the cost and named
			 * this task as where it should be retired deliberately.
			 *
			 * It is retired because the reason went away rather than because the
			 * cost was re-weighed. The tile url is a setting now, so it has to
			 * be localised to this screen in any case — it is in
			 * admin_strings(), beside the sentences — and there is nothing left
			 * for the dependency to carry. The duplication argument still holds:
			 * there is still exactly one place the attribution is written, and
			 * it is now Settings rather than locator.js.
			 */
			if ( wp_register_script(
				self::SCRIPT_ADMIN,
				$this->url( self::SRC_ADMIN ),
				array( self::SCRIPT_LEAFLET ),
				$version,
				true
			) ) {
				wp_script_add_data( self::SCRIPT_ADMIN, 'strategy', 'defer' );
			} else {
				/*
				 * Somebody else got here first. Enqueuing would put a file this
				 * plugin knows nothing about on the screen in the picker's
				 * name, and the payload below would then attach slosmAdminL10n
				 * to it — a payload of this plugin's strings hanging off a
				 * stranger's script, which the "is the data already there"
				 * guard cannot catch, because a stranger carries no data under
				 * that key either. So this stands down entirely, the same
				 * answer register() gives for the locator handle and for the
				 * same reason.
				 *
				 * "Somebody else" includes this method, one call ago: core
				 * fires admin_enqueue_scripts again from iframe_header() and
				 * from media_upload_header(), and the second firing gets a
				 * false because the first firing registered the handle. That is
				 * harmless and standing down on it is correct — everything
				 * below has already happened, and a case fires the hook twice
				 * to say so.
				 *
				 * It did not look harmless, and there was a src comparison here
				 * to tell our own registration from a stranger's. A mutation
				 * replacing the whole condition with `true` — stand down on
				 * every false, ours included — survived the entire suite,
				 * because there is nothing left for the second call to do. The
				 * comparison was distinguishing two cases with identical
				 * outcomes, which is a claim no case can check.
				 */
				return;
			}

			wp_enqueue_style( self::STYLE_LEAFLET );
			wp_enqueue_script( self::SCRIPT_ADMIN );

			/*
			 * Straight through, with none of the guards localize() carries for
			 * the front end, and the absence is measured rather than assumed.
			 *
			 * Those two guards exist because enqueue() is called once per
			 * locator rendered on a page, with no registration gate in front of
			 * it: the handle may not be registered, and the payload may already
			 * be attached from the locator before this one. Neither can happen
			 * here. This line is reached only when wp_register_script() has
			 * just returned true, which is once per request, and a handle that
			 * was registered a statement ago is registered.
			 *
			 * The guards were here, copied across. A mutation deleting the
			 * "is the payload already attached" one survived the whole suite,
			 * which is what an unreachable condition looks like from outside.
			 */
			wp_localize_script( self::SCRIPT_ADMIN, self::L10N_ADMIN_OBJECT, $this->admin_strings() );
		}

		/**
		 * Whether a hook suffix is this plugin's settings page.
		 *
		 * The ending and not the whole string, because the beginning is core's
		 * to build: `{page_type}_page_{slug}`, where the page type comes from
		 * $admin_page_hooks for the parent menu — 'slosm_store' today, and
		 * whatever a future parent is tomorrow. The slug is this plugin's own,
		 * so the ending is unambiguous.
		 *
		 * @param string $hook Hook suffix.
		 * @return bool
		 */
		private static function is_settings_screen( string $hook ): bool {
			return '' !== $hook && str_ends_with( $hook, '_page_' . Settings::PAGE );
		}

		/**
		 * Whether a hook suffix is this plugin's shortcode generator.
		 *
		 * The same shape, and the same reasoning, as is_settings_screen().
		 *
		 * @param string $hook Hook suffix.
		 * @return bool
		 */
		private static function is_shortcode_screen( string $hook ): bool {
			return '' !== $hook && str_ends_with( $hook, '_page_' . self::SCREEN_SHORTCODE );
		}

		/**
		 * The three sentences the copy button can say.
		 *
		 * Three rather than one, on the rule strings() and admin_strings()
		 * follow: a sentence exists when it is the only thing a person can act
		 * on differently. "Copied" and "press Ctrl+C" are the difference
		 * between being finished and having something left to do, and a site
		 * served over plain http — which is most of them on a local network,
		 * and where navigator.clipboard is not there at all — reaches the
		 * second one on every click.
		 *
		 * There is deliberately no fourth string for "it failed". Every path
		 * that cannot copy for the person has already *selected* the text for
		 * them, so there is exactly one thing left to say and it is the same
		 * sentence either way. A message that only reports a failure would be a
		 * dead end where this one is an instruction.
		 *
		 * tests/js/shortcode-copy.test.js reads this method and asserts the key
		 * list against the script's own fallback table, the way
		 * admin-picker.test.js does for admin_strings().
		 *
		 * @return array<string, string>
		 */
		public function copy_strings(): array {
			return array(
				/*
				 * _x(), because 'Copy' alone is a verb and a noun and the two
				 * are different words in most languages that inflect: the
				 * imperative on a button is "Kopiuj", a copy of something is
				 * "Kopia". A translator handed four letters and no context
				 * has a one-in-two chance of putting a noun on a button.
				 */
				'copy'   => _x( 'Copy', 'button that copies the generated shortcode', 'store-locator-for-openstreetmap' ),
				'copied' => __( 'Copied.', 'store-locator-for-openstreetmap' ),
				'manual' => __( 'It is selected — press Ctrl+C, or ⌘C on a Mac, to copy it.', 'store-locator-for-openstreetmap' ),
			);
		}

		/**
		 * The sixteen things the picker says.
		 *
		 * Sixteen rather than one, on the same rule strings() follows: a
		 * sentence exists here when it is the only thing an editor can act on
		 * differently. "That address could not be looked up" and "no place
		 * matched that address" are a broken service and a wrong address, and
		 * somebody retyping a postcode that was never the problem is what one
		 * sentence for both produces.
		 *
		 * The four about the coordinate fields are the second half of Task 18
		 * and the reason they are here rather than only on the server. Admin's
		 * class docblock traces why a message set during a block-editor save
		 * never reaches anybody; these say the same things before the save, in
		 * both editors, while the person who typed the value is still looking
		 * at the field. They are in the future tense for exactly that reason —
		 * they describe what the save is going to do, not what it did.
		 *
		 * Latitude and Longitude are deliberately the same source strings
		 * Admin::label() passes to __(). gettext keys on the source string, so
		 * the two call sites are one entry in the .po file.
		 *
		 * tests/js/admin-picker.test.js reads this method and asserts the key
		 * list against the script's own fallback table, so a string added here
		 * and not there — or there and not here — fails rather than shipping a
		 * sentence in English on a translated site.
		 *
		 * @return array<string, string>
		 */
		public function admin_strings(): array {
			return array(
				'configError'     => __( 'This map could not start: its settings are missing or unreadable.', 'store-locator-for-openstreetmap' ),
				'mapFailed'       => __( 'The map could not be started, so the coordinates have to be typed by hand.', 'store-locator-for-openstreetmap' ),
				'markerTitle'     => __( 'Drag this pin to move the location', 'store-locator-for-openstreetmap' ),
				'pairNeeded'      => __( 'A location needs both a latitude and a longitude, so the previous pair will be kept.', 'store-locator-for-openstreetmap' ),
				'willLookUp'      => __( 'Both coordinates are empty, so the address will be looked up when this is saved.', 'store-locator-for-openstreetmap' ),
				/* translators: 1: field name, 2: the value an editor typed, 3: the value it will be read as. */
				'comma'           => __( '%1$s “%2$s” will be read as %3$s. Use a dot for the decimal point.', 'store-locator-for-openstreetmap' ),
				/* translators: 1: field name, 2: the value an editor typed. */
				'notANumber'      => __( '%1$s “%2$s” is not a single number, so the previous value will be kept.', 'store-locator-for-openstreetmap' ),
				/* translators: 1: field name, 2: the value an editor typed, 3: the largest value that is on the earth. */
				'outOfRange'      => __( '%1$s “%2$s” is outside −%3$s to %3$s, so the previous value will be kept.', 'store-locator-for-openstreetmap' ),
				'latitude'        => __( 'Latitude', 'store-locator-for-openstreetmap' ),
				'longitude'       => __( 'Longitude', 'store-locator-for-openstreetmap' ),
				'looking'         => __( 'Looking the address up…', 'store-locator-for-openstreetmap' ),
				'lookupNoAddress' => __( 'Fill in the address first, then look it up.', 'store-locator-for-openstreetmap' ),
				'lookupDone'      => __( 'The address was found, so these coordinates will be looked up again if the address changes.', 'store-locator-for-openstreetmap' ),
				'lookupNoMatch'   => __( 'No place matched that address.', 'store-locator-for-openstreetmap' ),
				'lookupBusy'      => __( 'The address lookup is busy right now. Try again in a moment.', 'store-locator-for-openstreetmap' ),
				'lookupFailed'    => __( 'That address could not be looked up right now.', 'store-locator-for-openstreetmap' ),
			);
		}

		/**
		 * Whether the footer pass still lies ahead of this request.
		 *
		 * The whole of wp_print_footer_scripts() is
		 * do_action( 'wp_print_footer_scripts' ) — script-loader.php lines
		 * 2288-2295 — so did_action() on that hook is an exact answer to "have
		 * the footer scripts already been printed", not an approximation of one.
		 *
		 * A locator rendered after that point is rare and is somebody else's
		 * doing: content emitted on shutdown, a theme hooking wp_footer at a
		 * priority above 20. It is worth detecting because the symptom is a
		 * blank rectangle with nothing in the html to explain it.
		 *
		 * It is detected and reported rather than repaired, and that is a
		 * judgement rather than a limit. It could be repaired —
		 * wp_scripts()->do_items( 'slosm-locator' ) resolves Leaflet, prints
		 * both tags and prints the localized block through print_extra_script()
		 * — at the cost of driving the global WP_Scripts object by hand from
		 * inside a shortcode, on a path this plugin cannot test against real
		 * core. A notice a developer can act on is the better trade; a plugin
		 * that quietly reaches into core's printer to paper over somebody else's
		 * template order is not.
		 *
		 * @return bool
		 */
		public function in_time(): bool {
			return 0 === did_action( 'wp_print_footer_scripts' );
		}

		/**
		 * Attaches the shared strings to the locator handle, at most once.
		 *
		 * Both conditions are the point, and each of them is a false this code
		 * used to ignore.
		 *
		 * The handle must be registered, because wp_localize_script() is
		 * WP_Scripts::add_data() underneath and that returns false on its first
		 * line for a handle it does not know (class-wp-scripts.php lines
		 * 843-845). Asking first means the three __() calls — each of which can
		 * pull this plugin's .mo file in through just-in-time translation
		 * loading — are never spent on a request that cannot use them.
		 *
		 * The payload must not be attached already, because WP_Scripts::localize()
		 * does not replace what a handle carries, it concatenates onto it. Two
		 * locators on a page would otherwise print two `var slosmL10n = {...}`
		 * statements and ten would print ten.
		 *
		 * The second question is asked of WordPress rather than remembered in a
		 * property here, and that is deliberate twice over. A property is per
		 * instance, and two locators on a page can be rendered by two Shortcode
		 * objects — a widget and the content, a page builder constructing its
		 * own — while the data is per handle, per request. And a property set
		 * before the attachment succeeded is exactly the bug that made this
		 * method necessary: on a render where the handle was not registered yet,
		 * a remembered "done" would stop the next locator on the same page from
		 * getting it right.
		 *
		 * WP_Dependencies::get_data() is the public reader for what add_data()
		 * wrote, so this asks the same store wp_localize_script() writes to. If
		 * another plugin has attached its own data to this plugin's handle, this
		 * stands down — a plugin that has taken over the handle has taken over
		 * what is printed with it.
		 *
		 * @return void
		 */
		private function localize(): void {
			if ( ! wp_script_is( self::SCRIPT_LOCATOR, 'registered' ) ) {
				return;
			}

			$scripts = wp_scripts();

			if ( ! is_object( $scripts ) || $scripts->get_data( self::SCRIPT_LOCATOR, 'data' ) ) {
				return;
			}

			wp_localize_script( self::SCRIPT_LOCATOR, self::L10N_OBJECT, $this->strings() );
		}

		/**
		 * The strings the interface says the same way for every locator.
		 *
		 * Not one more than the front end says today. Everything else belongs
		 * to the task that writes the code that says it; a list invented here
		 * would be a list of guesses nobody can delete later without checking
		 * every file in the plugin first. Task 12 opened with three, and Task
		 * 13 added the two the map itself says — a locator whose config it
		 * could not read, and a list of locations that never arrived. Both
		 * replace the failure mode the whole front end is written against: a
		 * blank rectangle with nothing anywhere to say why.
		 *
		 * Task 14 added five, and they are two different kinds of thing.
		 *
		 * Three are what a failed search says, and there are three rather than
		 * one because Rest_Controller::ERROR_STATUS already distinguishes them
		 * and the difference is the only thing a visitor can act on: nothing
		 * matched what you typed (404, and 400, which is the same thing said
		 * about a query that normalised to nothing), the address service is
		 * busy so try again (429), and it broke and this is not your fault
		 * (502 and every network failure). A single "search failed" would
		 * throw that away and leave somebody retyping a postcode that was
		 * never the problem.
		 *
		 * Two are distance formats, and they are strings rather than a
		 * concatenation in JavaScript because "12.4 km" is not the shape every
		 * language writes it in — the space is non-breaking in French
		 * typography and the unit precedes the number in some locales. %s is
		 * the number, already formatted. They are the reason this method is
		 * the right home for them at all: a format for a number is the same
		 * for every locator on the site, which is the test this payload
		 * applies to everything in it.
		 *
		 * Task 15 added four, which is the whole of what "near me" says, and
		 * there are four rather than one because the four outcomes are not the
		 * same kind of thing. Waiting is a status. Declining is what most
		 * visitors do and is not a failure at all — the message says what
		 * happened and what to do instead, the locator does not go red, and the
		 * results already on screen stay where they are. A position the browser
		 * could not work out, whether it gave up or timed out, is a real
		 * failure and reads like one. A browser with no geolocation at all is
		 * neither: nothing is broken and nothing is anybody's fault.
		 *
		 * One sentence for all of them was the alternative, and it fails in
		 * both directions at once: it paints an error over the ordinary answer,
		 * and it leaves somebody pressing a button that was never going to work
		 * on their browser.
		 *
		 * There is no string for the category filter or for the cluster label,
		 * and both absences are deliberate. The filter's own "All categories"
		 * is rendered by Shortcode::category_options(), already translated, and
		 * the front end keeps that option rather than rebuilding it — so the
		 * label is paid for once, in the markup, where it already was. A cluster
		 * label is a number and nothing else; see the hazard in
		 * assets/markercluster/README.md for why putting a translated string
		 * anywhere near one would be a mistake.
		 *
		 * tests/js/harness.test.js reads this method and asserts the key list
		 * against the front end's own fallback table, so a string added here
		 * and not there — or there and not here — fails rather than shipping a
		 * message in English on a translated site.
		 *
		 * Translated here rather than in JavaScript. See the class docblock for
		 * what wp_set_script_translations() would cost to do it the other way.
		 *
		 * @return array<string, string>
		 */
		public function strings(): array {
			return array(
				'noResults'           => __( 'No results', 'store-locator-for-openstreetmap' ),
				/*
				 * Written over the one Leaflet hardcodes. `_initLayout` does
				 * `i.setAttribute("aria-label","Close popup")` in English, with no
				 * translation reaching it and no option to change it, so the only
				 * way a Polish site's map says "zamknij" is this plugin replacing
				 * the attribute once the popup exists. wirePopup() does it.
				 */
				'closePopup'          => __( 'Close popup', 'store-locator-for-openstreetmap' ),
				/*
				 * Said after every draw a person asked for: a search, "use my
				 * location", a radius or result-count change. It replaces Task
				 * 29b's 'Results updated.', which said that something had
				 * changed without saying what — a number is the same sentence
				 * with the useful part in it.
				 *
				 * A LABEL AND A VALUE, NOT A SENTENCE, AND THAT IS THE WHOLE
				 * DECISION HERE
				 * ==========================================================
				 * "7 locations found" needs a plural form, and gettext plurals
				 * do not survive wp_localize_script() in any shape a browser
				 * can apply: the rule differs per language and there is nothing
				 * on the page to apply it with. The usual way out is
				 * wp.i18n._n() with wp_set_script_translations(), which reads
				 * .json files a build step makes out of a .po — and this plugin
				 * has no build step, which is a design decision rather than an
				 * omission.
				 *
				 * Two forms would be the easy answer and would be *wrong*: this
				 * plugin's own author writes Polish, which has three (1, 2-4,
				 * 5+), and shipping a plugin that is ungrammatical in the
				 * translator's own language is not a trade worth making for
				 * prose.
				 *
				 * So the number is a value after a label. "Locations found: 1"
				 * and "Locations found: 7" are both correct English, the shape
				 * survives every plural rule there is because nothing agrees
				 * with the number, and a translator gets one string to place
				 * rather than a rule to encode. It reads like a status line
				 * because that is what it is.
				 */
				/* translators: %s: how many locations the search found. */
				'resultsFound'        => _x( 'Locations found: %s', 'result count announced after a search', 'store-locator-for-openstreetmap' ),
				'searching'           => __( 'Searching…', 'store-locator-for-openstreetmap' ),
				'locating'            => __( 'Finding your location…', 'store-locator-for-openstreetmap' ),
				'locationDenied'      => __( 'No location shared. Search for an address instead.', 'store-locator-for-openstreetmap' ),
				'locationFailed'      => __( 'Your location could not be worked out. Search for an address instead.', 'store-locator-for-openstreetmap' ),
				'locationUnsupported' => __( 'This browser cannot share a location. Search for an address instead.', 'store-locator-for-openstreetmap' ),
				'loadFailed'          => __( 'The locations could not be loaded.', 'store-locator-for-openstreetmap' ),
				'configError'         => __( 'This map could not start: its settings are missing or unreadable.', 'store-locator-for-openstreetmap' ),
				'searchNoMatch'       => __( 'No place matched that search.', 'store-locator-for-openstreetmap' ),
				'searchBusy'          => __( 'The address lookup is busy right now. Try again in a moment.', 'store-locator-for-openstreetmap' ),
				'searchFailed'        => __( 'That address could not be looked up right now.', 'store-locator-for-openstreetmap' ),
				/* translators: %s: a distance, already formatted as a number. */
				'distanceKm'          => __( '%s km', 'store-locator-for-openstreetmap' ),
				/* translators: %s: a distance, already formatted as a number. */
				'distanceMi'          => __( '%s mi', 'store-locator-for-openstreetmap' ),
				/*
				 * The same word Shortcode::row_template() puts on the row's own
				 * anchor, and deliberately the same argument rather than a
				 * synonym: gettext keys on the source string, so the two call
				 * sites are one entry in the .po file and a translator sees it
				 * once. The row's link is rendered by PHP and the popup's is
				 * built in the browser, which is why the word has to exist in
				 * both places at all.
				 *
				 * _x() on both, with the same context spelled the same way.
				 * gettext keys on the pair, so a context here and none there —
				 * or two wordings of the same idea — would split the one entry
				 * back into two. The context is there because "Directions" is
				 * three different words in Polish depending on whether it means
				 * a route, instructions or compass bearings.
				 */
				'directions'          => _x( 'Directions', 'link to route directions for one location', 'store-locator-for-openstreetmap' ),
			);
		}

		/**
		 * The url of a file inside this plugin.
		 *
		 * SLOSM_URL is checked rather than assumed for the same reason the
		 * autoloader checks SLOSM_DIR: both come from the main plugin file, and
		 * a process that requires this class directly — the unit suite's own
		 * child processes, for one — has not run it. An undefined constant is a
		 * fatal in PHP 8, and a fatal in a render path takes the page with it.
		 *
		 * @param string $relative Path relative to the plugin root.
		 * @return string
		 */
		private function url( string $relative ): string {
			return ( defined( 'SLOSM_URL' ) ? (string) SLOSM_URL : '' ) . $relative;
		}

		/**
		 * The version every handle is stamped with.
		 *
		 * Null rather than false in the branch that cannot happen on a real
		 * site. WordPress reads false as "use the WordPress version", which is
		 * the cache-busting bug this method exists to avoid, and null as "add no
		 * version at all" (class-wp-scripts.php line 300). Of the two wrong
		 * answers, the one that omits a version is at least honest about not
		 * knowing it.
		 *
		 * @return string|null
		 */
		private function version(): ?string {
			return defined( 'SLOSM_VERSION' ) ? (string) SLOSM_VERSION : null;
		}
	}
}
