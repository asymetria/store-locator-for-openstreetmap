<?php
/**
 * The four tabs, the save, and the one button that has a side effect.
 *
 * @package Store_Locator_For_OpenStreetMap
 */

namespace Asymetria\StoreLocator\Admin;

use Asymetria\StoreLocator\Assets;
use Asymetria\StoreLocator\Geocoder;
use Asymetria\StoreLocator\Post_Type;
use Asymetria\StoreLocator\Rest_Controller;
use Asymetria\StoreLocator\Settings;
use Asymetria\StoreLocator\Shortcode;
use Asymetria\StoreLocator\Store_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( __NAMESPACE__ . '\\Settings_Screen' ) ) {

	/**
	 * Store Locator > Settings.
	 *
	 * WHERE THIS SCREEN IS, AND WHY IT IS NOT UNDER SETTINGS
	 * ======================================================
	 * A submenu of the locations post type, so the whole plugin is one menu:
	 * Locations, Add Location, Categories, Settings. Three reasons, in the order
	 * they mattered.
	 *
	 * Nothing is gained by the other placement. `add_options_page()` would put
	 * it under Settings and would still have to ask for `manage_options`,
	 * because that is what `wp-admin/options.php` demands — see CAPABILITY — so
	 * the choice is about where a person looks, not about who may look.
	 *
	 * Settings is a menu this plugin does not own. On a site with a dozen
	 * plugins it already has a dozen rows, each named after a product rather
	 * than after a job, and "Store Locator" in that list is one more thing to
	 * scan past. The locations menu is where somebody who is thinking about
	 * locations already is.
	 *
	 * And Task 22 brings a shortcode generator, which is a second screen that
	 * belongs beside this one. A post type's menu has room for it; Settings
	 * does not.
	 *
	 * WHAT THE SETTINGS API DOES FOR THIS SCREEN, AND WHAT IT DOES NOT
	 * ================================================================
	 * It does the save, completely. `settings_fields()` prints the option page
	 * and the nonce `{$group}-options`; `wp-admin/options.php` checks that nonce
	 * with `check_admin_referer()` (line 244 of WordPress 6.9.1), refuses any
	 * option name not in the allowed list `register_setting()` built, runs the
	 * registered sanitiser, writes, and redirects back. None of that is here and
	 * none of it can be got wrong here.
	 *
	 * It does nothing for three things that are here:
	 *
	 * - *Who may open the page.* That is `add_submenu_page()`'s capability,
	 *   enforced by core's `user_can_access_admin_page()` before the callback
	 *   runs (wp-admin/includes/menu.php line 371). render() checks it again,
	 *   because a page callback is a public method on a hook and defence in
	 *   depth costs one line.
	 * - *The clear-cache button.* It is a different request with a side effect,
	 *   it goes to admin-post.php, and admin-post.php checks nothing beyond the
	 *   user being logged in. Its nonce and its capability check are this
	 *   class's own; see handle_clear_cache().
	 * - *An unchecked checkbox.* An unchecked box posts nothing at all, and
	 *   "nothing" is how Settings::sanitise() spells "this tab did not ask" —
	 *   so without a companion field an option could be switched on and never
	 *   off again. checkbox() prints the companion.
	 */
	final class Settings_Screen {

		/**
		 * The capability this screen needs.
		 *
		 * manage_options, and it is not a free choice. `wp-admin/options.php`
		 * sets `$capability = 'manage_options'` at line 29 of WordPress 6.9.1
		 * and only the `option_page_capability_{$option_page}` filter moves it;
		 * without that filter, a page registered at `edit_posts` would open for
		 * an editor and answer their save with a 403 page they can do nothing
		 * about. The filter is deliberately not used: "who may change site-wide
		 * settings" is a question WordPress has already answered, and answering
		 * it differently for one plugin is how a site ends up with an editor
		 * who can repoint the geocoding endpoint.
		 *
		 * @var string
		 */
		public const CAPABILITY = 'manage_options';

		/**
		 * The admin-post action the clear-cache button posts.
		 *
		 * @var string
		 */
		public const CLEAR_ACTION = 'slosm_clear_cache';

		/**
		 * The field the clear-cache nonce travels in.
		 *
		 * Not `_wpnonce`. admin-post.php is a shared front door and several
		 * plugins' forms reach it; a name of this plugin's own cannot be
		 * confused with somebody else's credential by a form that posts both.
		 *
		 * @var string
		 */
		public const NONCE_FIELD = 'slosm_clear_nonce';

		/**
		 * The argument that says a cache was just cleared.
		 *
		 * @var string
		 */
		public const CLEARED_ARG = 'slosm-cleared';

		/**
		 * The repository whose payload generation the button moves.
		 *
		 * @var Store_Repository|null
		 */
		private ?Store_Repository $repository;

		/**
		 * The geocoder whose lookup generation the button moves.
		 *
		 * Built on first use, so an admin request that never presses the button
		 * never constructs one — the same arrangement Admin and Bulk_Geocode
		 * use.
		 *
		 * @var Geocoder|null
		 */
		private ?Geocoder $geocoder;

		/**
		 * What ends the request after a redirect.
		 *
		 * A seam, for the reason Geocoder takes one for its clock and its
		 * sleeper: the production behaviour is `exit`, and a test process that
		 * exits has stopped being a test process. The default is the real
		 * thing, so nothing on a site depends on a caller passing one.
		 *
		 * @var callable
		 */
		private $leave;

		/**
		 * Constructor.
		 *
		 * @param Store_Repository|null $repository Repository, or null to build one.
		 * @param Geocoder|null         $geocoder   Geocoder, or null to build one on use.
		 * @param callable|null         $leave      Ends the request, or null for exit.
		 */
		public function __construct( ?Store_Repository $repository = null, ?Geocoder $geocoder = null, ?callable $leave = null ) {
			$this->repository = $repository;
			$this->geocoder   = $geocoder;
			$this->leave      = null !== $leave ? $leave : static function (): void {
				exit;
			};
		}

		/**
		 * Puts the screen in the menu. Hooked to admin_menu.
		 *
		 * Returns the hook suffix rather than nothing, because that string is
		 * how a stylesheet reaches this screen and a test can check the shape.
		 * Assets::enqueue_admin() does not call this: it recognises the screen
		 * from Settings::PAGE, so nothing in includes/ *refers* to this class.
		 * Not so that nothing loads it — Plugin::boot() constructs this screen
		 * on every request there is, front end included, and this sentence said
		 * otherwise until review.
		 *
		 * @return string The hook suffix core answered with.
		 */
		public function add_page(): string {
			return (string) add_submenu_page(
				'edit.php?post_type=' . Post_Type::POST_TYPE,
				__( 'Store Locator Settings', 'store-locator-for-openstreetmap' ),
				__( 'Settings', 'store-locator-for-openstreetmap' ),
				self::CAPABILITY,
				Settings::PAGE,
				array( $this, 'render' )
			);
		}

		/**
		 * The option's own registration is NOT here, and was until review.
		 *
		 * It is Settings::register(), hooked to init by Plugin::boot(). Nothing
		 * in it is about a screen: register_setting() lives in
		 * wp-includes/option.php, the option and the sanitiser are both the other
		 * class's, and the hook has to be init rather than admin_init so that the
		 * gate exists on a front-end write too. Leaving it here meant boot()
		 * naming an admin/ class in the one callback that must run on every
		 * request — which is exactly what two comments elsewhere claimed did not
		 * happen. See Settings::register().
		 */

		/**
		 * Builds one section per tab and one field per setting. Hooked to admin_init.
		 *
		 * Driven entirely by Settings::TABS, so a setting added to the closed
		 * list and forgotten here is impossible rather than invisible: the tab
		 * lists are what this walks, and a case asserts they cover the defaults
		 * exactly.
		 *
		 * Each tab is its own Settings API "page", which is what lets
		 * do_settings_sections() print one tab without knowing tabs exist.
		 *
		 * @return void
		 */
		public function add_fields(): void {
			foreach ( Settings::TABS as $tab => $keys ) {
				$page    = self::page_for( $tab );
				$section = 'slosm-section-' . $tab;

				add_settings_section( $section, '', array( $this, 'render_section' ), $page );

				foreach ( $keys as $key ) {
					$control = self::control( $key );

					add_settings_field(
						$key,
						esc_html( $control['label'] ),
						array( $this, 'render_field' ),
						$page,
						$section,
						array(
							'key' => $key,

							/*
							 * Only for a control that is a single focusable
							 * element. A group of checkboxes has several, and a
							 * label pointing at the first of them tells a screen
							 * reader that "Shown in results" is the name of the
							 * "Name" box — which is worse than no label element
							 * at all. core's do_settings_fields() prints a plain
							 * <th> when this is absent.
							 */
							'label_for' => 'checkboxes' === $control['type'] ? '' : self::field_id( $key ),
						)
					);
				}
			}
		}

		/**
		 * Prints one tab's introduction.
		 *
		 * A section callback rather than four blocks inside render(), so the
		 * sentence sits next to the fields it is about in the output even
		 * though core prints it above the table.
		 *
		 * @param array $section The section, as core passes it.
		 * @return void
		 */
		public function render_section( array $section ): void {
			$intros = array(
				'slosm-section-map'      => __( 'The map every locator on this site starts from. A shortcode can still overrule any of it.', 'store-locator-for-openstreetmap' ),
				'slosm-section-search'   => __( 'What a visitor can search by, and what the controls offer.', 'store-locator-for-openstreetmap' ),
				'slosm-section-results'  => __( 'What a visitor is shown once a search has found something.', 'store-locator-for-openstreetmap' ),
				'slosm-section-advanced' => __( 'Whether the plugin brings its own styling, where geocoding goes, and how long its answers are kept. Leave the two endpoints empty unless you run your own service.', 'store-locator-for-openstreetmap' ),
			);

			$id = isset( $section['id'] ) && is_scalar( $section['id'] ) ? (string) $section['id'] : '';

			if ( ! isset( $intros[ $id ] ) ) {
				return;
			}

			echo '<p class="description">' . esc_html( $intros[ $id ] ) . '</p>';
		}

		/**
		 * Prints the screen. The page callback.
		 *
		 * The capability is checked here as well as by core's
		 * user_can_access_admin_page(), and the duplication is the point: this
		 * is a public method registered on a dynamic action hook, and "core
		 * will have checked" is true of the one caller that exists today.
		 *
		 * @return void
		 */
		public function render(): void {
			if ( ! current_user_can( self::CAPABILITY ) ) {
				// 403 as the third argument, which really does become the status:
				// wp_die() itself turns an int $args into array( 'response' => $args )
				// (wp-includes/functions.php lines 3772-3777 of WordPress 6.9.1).
				// Worth the citation because the obvious reading is wrong —
				// _wp_die_process_input() runs wp_parse_args() over it, which would
				// make an int into array( '403' => '' ) and leave the status at its
				// default 500 — and that is the branch core's own
				// `wp_die( $message, 403 )` calls in options.php rely on.
				wp_die(
					esc_html__( 'Sorry, you are not allowed to manage settings for this site.', 'store-locator-for-openstreetmap' ),
					'',
					403
				);
			}

			$tab = self::current_tab();

			echo '<div class="wrap slosm-settings">';
			echo '<h1>' . esc_html( __( 'Store Locator Settings', 'store-locator-for-openstreetmap' ) ) . '</h1>';

			$this->notices();
			$this->tabs( $tab );

			echo '<form action="options.php" method="post">';

			settings_fields( Settings::GROUP );
			do_settings_sections( self::page_for( $tab ) );

			// submit_button()'s markup, written out rather than called. The
			// function lives in wp-admin/includes/template.php and is not part
			// of the Settings API; spelling the four attributes here is one
			// fewer function whose defaults have to be known to read this.
			echo '<p class="submit">'
				. '<input type="submit" name="submit" id="submit" class="button button-primary" value="'
				. esc_attr( __( 'Save Changes', 'store-locator-for-openstreetmap' ) ) . '" />'
				. '</p>';

			echo '</form>';

			if ( 'advanced' === $tab ) {
				$this->clear_cache_form();
			}

			echo '</div>';
		}

		/**
		 * Empties both caches and sends the person back. Hooked to admin_post_slosm_clear_cache.
		 *
		 * WHAT IT CLEARS, AND WHY NOTHING IS DELETED
		 * ==========================================
		 * Two generations and nothing else. Store_Repository::CACHE_PREFIX and
		 * Geocoder::CACHE_PREFIX both carry the same warning: a sweep of
		 * wp_options for `option_name LIKE '_transient_slosm_%'` finds nothing
		 * at all on a site with a persistent object cache, because the
		 * transients are in Redis or Memcached under keys no SQL can see. A
		 * button built on that sweep would do nothing on exactly the
		 * better-hosted sites, and would say it had worked.
		 *
		 * Moving a generation makes every key on the site unreachable in one
		 * write, with nothing enumerated and nothing for an object cache to
		 * hide. The old entries stay where they are until their own ttl runs
		 * out; that is the cost, and it is space a cache is built to reclaim.
		 *
		 * The failure memory goes with them, and that is why Admin::failure_key()
		 * now carries the geocode generation: an address this plugin has already
		 * failed on is remembered for an hour, and "clear the cache" pressed
		 * after fixing an address upstream has to mean that memory too.
		 *
		 * The repository flush is forced past its once-per-request guard. That
		 * guard exists so one post save does not spend three generations on
		 * three hooks; this is a person pressing a button, and a guard tripped
		 * by something earlier in the request would make the button do nothing
		 * and report success.
		 *
		 * WHAT GUARDS IT
		 * ==============
		 * A nonce and a capability, both checked here, because admin-post.php
		 * checks neither — it fires `admin_post_{$action}` for any logged-in
		 * user who can reach wp-admin.
		 *
		 * wp_verify_nonce() rather than check_admin_referer(), which is the
		 * house rule Admin::save() and Bulk_Geocode::verified() already set: the
		 * three-valued return is what has to be read, since int 2 means a nonce
		 * from the previous twelve-hour tick and a settings page left open
		 * overnight is the ordinary way that happens. `! wp_verify_nonce()` is
		 * false for both 1 and 2 and true only for false, which is exactly the
		 * reading wanted.
		 *
		 * @return void
		 */
		public function handle_clear_cache(): void {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read, unslashed and sanitised on the next line; the nonce is verified before anything is done with it.
			$raw   = $_POST[ self::NONCE_FIELD ] ?? '';
			$nonce = is_scalar( $raw ) ? sanitize_text_field( wp_unslash( (string) $raw ) ) : '';

			if ( ! wp_verify_nonce( $nonce, self::CLEAR_ACTION ) || ! current_user_can( self::CAPABILITY ) ) {
				wp_die(
					esc_html__( 'Sorry, you are not allowed to clear this cache.', 'store-locator-for-openstreetmap' ),
					'',
					403
				);
			}

			$this->repository()->flush_cache( true );
			$this->geocoder()->flush_cache();

			$this->go( add_query_arg( self::CLEARED_ARG, '1', self::url( 'advanced' ) ) );
		}

		/**
		 * The url of this screen, or of one of its tabs.
		 *
		 * admin_url( 'edit.php' ) with the post type added rather than a
		 * hand-built string, because the post type argument is what keeps the
		 * Locations menu open and highlighted while this screen is being looked
		 * at — core matches the parent by `edit.php?post_type=…`.
		 *
		 * @param string $tab Tab to open, or '' for the screen's own url.
		 * @return string
		 */
		public static function url( string $tab = '' ): string {
			$url = add_query_arg( 'post_type', Post_Type::POST_TYPE, admin_url( 'edit.php' ) );
			$url = add_query_arg( 'page', Settings::PAGE, $url );

			return '' === $tab ? $url : add_query_arg( Settings::TAB_ARG, $tab, $url );
		}

		/**
		 * Which tab is being looked at.
		 *
		 * The first tab for anything unrecognised, which covers both "no
		 * argument" and "an argument naming a tab this plugin has not got".
		 * Neither is an error worth a message: a settings screen opened at a
		 * tab that is not there should show a settings screen.
		 *
		 * @return string
		 */
		public static function current_tab(): string {
			$tabs = array_keys( Settings::TABS );

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- reading which tab to draw on a screen already behind manage_options; nothing is written, and the value is compared against a closed list.
			$asked = $_GET[ Settings::TAB_ARG ] ?? null;

			if ( ! is_scalar( $asked ) ) {
				return $tabs[0];
			}

			return in_array( (string) $asked, $tabs, true ) ? (string) $asked : $tabs[0];
		}

		/**
		 * The Settings API "page" one tab's fields are registered against.
		 *
		 * @param string $tab Tab.
		 * @return string
		 */
		public static function page_for( string $tab ): string {
			return Settings::PAGE . '-' . $tab;
		}

		/**
		 * Prints one setting's control.
		 *
		 * @param array $args What add_settings_field() was given.
		 * @return void
		 */
		public function render_field( array $args ): void {
			$key = isset( $args['key'] ) && is_scalar( $args['key'] ) ? (string) $args['key'] : '';

			if ( '' === $key || ! array_key_exists( $key, Settings::defaults() ) ) {
				return;
			}

			$control = self::control( $key );
			$value   = Settings::get( $key );

			switch ( $control['type'] ) {
				case 'checkbox':
					$this->checkbox( $key, (bool) $value, $control );
					break;

				case 'checkboxes':
					$this->checkboxes( $key, is_array( $value ) ? $value : array(), $control );
					break;

				case 'select':
					$this->select( $key, (string) $value, $control );
					break;

				case 'numbers':
					$this->input( $key, self::number_list( is_array( $value ) ? $value : array() ), $control );
					break;

				case 'number':
					$this->input( $key, self::number_text( $value ), $control );
					break;

				default:
					$this->input( $key, null === $value ? '' : (string) $value, $control );
			}

			if ( '' !== $control['help'] ) {
				echo '<p class="description">' . esc_html( $control['help'] ) . '</p>';
			}
		}

		/**
		 * One text, url, colour or number input.
		 *
		 * @param string $key     Setting name.
		 * @param string $value   Current value, as text.
		 * @param array  $control Its description.
		 * @return void
		 */
		private function input( string $key, string $value, array $control ): void {
			$type = in_array( $control['type'], array( 'url', 'color', 'number' ), true ) ? $control['type'] : 'text';

			/*
			 * Built key by key, which is what the comment that used to be here
			 * said would have to happen if this ever stopped being safe.
			 *
			 * It never did stop being safe: `attributes` held a literal from
			 * controls() below — `min="1" max="19" step="1"` and six more like
			 * it, the only computed part an (int) cast — with nothing from a
			 * request, an option or a translation anywhere in it. What changed
			 * is who is reading. Plugin Check reports the unescaped echo as an
			 * ERROR, the reviewer of a submission to the plugin directory runs
			 * Plugin Check, and "it is a literal, go and read controls()" is
			 * not an argument a static analyser can accept or a reviewer
			 * should have to check by hand.
			 *
			 * So the shape changed rather than an annotation being added: an
			 * array of name => value, each value through esc_attr, and no way
			 * left to put markup in an attribute list even by mistake. The
			 * value is cast to string first because these are integers.
			 */
			$attributes = '';

			foreach ( $control['attributes'] as $name => $attribute ) {
				$attributes .= ' ' . esc_attr( (string) $name ) . '="' . esc_attr( (string) $attribute ) . '"';
			}

			echo '<input type="' . esc_attr( $type ) . '"'
				. ' id="' . esc_attr( self::field_id( $key ) ) . '"'
				. ' name="' . esc_attr( self::field_name( $key ) ) . '"'
				. ' value="' . esc_attr( $value ) . '"'
				. ' class="' . esc_attr( $control['class'] ) . '"'
				. $attributes // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built directly above, every name and every value through esc_attr.
				. ' />';
		}

		/**
		 * One checkbox, and the hidden field that makes unticking it possible.
		 *
		 * The companion is the whole of this method's reason for existing. An
		 * unchecked box posts nothing, and Settings::sanitise() reads an absent
		 * key as "this tab did not ask about it, keep what is stored" — which is
		 * correct for the other three tabs and fatal here: without the hidden
		 * zero, every switch on this screen could be turned on and never off.
		 *
		 * The hidden field comes first, so the browser sends 0 and then, only if
		 * the box is ticked, 1 — and PHP keeps the last value for a repeated
		 * name. Core prints the pair in this order for the same reason.
		 *
		 * @param string $key     Setting name.
		 * @param bool   $on      Whether it is on.
		 * @param array  $control Its description.
		 * @return void
		 */
		private function checkbox( string $key, bool $on, array $control ): void {
			$name = self::field_name( $key );

			echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="0" />';
			echo '<label><input type="checkbox"'
				. ' id="' . esc_attr( self::field_id( $key ) ) . '"'
				. ' name="' . esc_attr( $name ) . '"'
				. ' value="1"';
			checked( $on, true );
			echo ' /> ' . esc_html( $control['checkbox_label'] ) . '</label>';
		}

		/**
		 * A group of checkboxes that post one list.
		 *
		 * No hidden companion, and the absence is deliberate rather than an
		 * oversight of the rule above. Settings::field_list() answers an empty
		 * choice with the whole list, so "none of them" is not a state this
		 * setting has; a companion would post an empty string that the same
		 * method would then read as no choice at all. Unticking everything
		 * therefore restores the default, which is what the description says.
		 *
		 * @param string   $key     Setting name.
		 * @param string[] $chosen  What is ticked.
		 * @param array    $control Its description.
		 * @return void
		 */
		private function checkboxes( string $key, array $chosen, array $control ): void {
			echo '<fieldset>';
			echo '<legend class="screen-reader-text">' . esc_html( $control['label'] ) . '</legend>';

			foreach ( $control['choices'] as $value => $label ) {
				echo '<label class="slosm-settings__choice">'
					. '<input type="checkbox" name="' . esc_attr( self::field_name( $key ) ) . '[]"'
					. ' value="' . esc_attr( (string) $value ) . '"';
				checked( in_array( (string) $value, $chosen, true ), true );
				echo ' /> ' . esc_html( $label ) . '</label>';
			}

			echo '</fieldset>';
		}

		/**
		 * One select.
		 *
		 * @param string $key     Setting name.
		 * @param string $value   Current value.
		 * @param array  $control Its description.
		 * @return void
		 */
		private function select( string $key, string $value, array $control ): void {
			echo '<select id="' . esc_attr( self::field_id( $key ) ) . '"'
				. ' name="' . esc_attr( self::field_name( $key ) ) . '">';

			foreach ( $control['choices'] as $option => $label ) {
				echo '<option value="' . esc_attr( (string) $option ) . '"';
				selected( (string) $option, $value );
				echo '>' . esc_html( $label ) . '</option>';
			}

			echo '</select>';
		}

		/**
		 * The four tab links.
		 *
		 * core's nav-tab markup, so the screen looks like every other tabbed
		 * admin page rather than like this plugin having opinions about tabs.
		 * aria-current is added on top of nav-tab-active, because the class is
		 * a colour and the attribute is the statement.
		 *
		 * All four are _x() with one shared context, and the fourth of them is
		 * why. Task 29c needed 'Search' as the imperative on the locator's
		 * submit button, found this array already holding it as a noun, and
		 * closed the collision by putting a context on the *button* — leaving
		 * the bare msgid here. That is only half a fix. A translator opening a
		 * .pot and meeting an uncontexted 'Search' in a plugin with a search
		 * box reads it as the button and writes "Szukaj", which is the wrong
		 * word over a section of a settings screen: the noun is
		 * "Wyszukiwanie". The context has to be on both sides or the default
		 * reading of the bare one is wrong.
		 *
		 * The other three come along because the hazard is the set rather than
		 * the one word. 'Advanced' is an adjective with no noun in sight, and
		 * Polish adjectives inflect for the gender of what they modify —
		 * "Zaawansowane" agreeing with "ustawienia" is not the same word as
		 * "Zaawansowany". 'Map' is a noun here and a verb in English. Leaving
		 * them bare inside an array whose other member is contexted would be
		 * an inconsistency a later reader has to re-derive.
		 *
		 * @param string $current The tab being looked at.
		 * @return void
		 */
		private function tabs( string $current ): void {
			$labels = array(
				'map'      => _x( 'Map', 'settings screen tab', 'store-locator-for-openstreetmap' ),
				'search'   => _x( 'Search', 'settings screen tab', 'store-locator-for-openstreetmap' ),
				'results'  => _x( 'Results', 'settings screen tab', 'store-locator-for-openstreetmap' ),
				'advanced' => _x( 'Advanced', 'settings screen tab', 'store-locator-for-openstreetmap' ),
			);

			echo '<nav class="nav-tab-wrapper" aria-label="'
				. esc_attr( __( 'Settings sections', 'store-locator-for-openstreetmap' ) ) . '">';

			foreach ( array_keys( Settings::TABS ) as $tab ) {
				$is_current = $tab === $current;

				echo '<a class="nav-tab' . ( $is_current ? ' nav-tab-active' : '' ) . '"'
					. ( $is_current ? ' aria-current="page"' : '' )
					. ' href="' . esc_url( self::url( $tab ) ) . '">'
					. esc_html( $labels[ $tab ] ?? $tab )
					. '</a>';
			}

			echo '</nav>';
		}

		/**
		 * Whatever this request has to say at the top of the screen.
		 *
		 * settings_errors() is deliberately not called, and here is exactly
		 * what that costs, because the answer is not "nothing".
		 *
		 * options.php adds a default "Settings saved." and writes the
		 * settings_errors transient on **every** save (lines 367-371 of
		 * WordPress 6.9.1); settings_errors() is what merges that transient into
		 * the page and deletes it. On a page outside options-general.php it has
		 * to be called by hand, so calling it and *also* printing the notice
		 * below would be the same sentence twice. One of the two had to go.
		 *
		 * The one that went is settings_errors(), and the price is this: a third
		 * party filtering `sanitize_option_slosm_settings` and calling
		 * add_settings_error() to complain about a value has its message dropped
		 * on the floor. Nothing in this plugin does that — Settings::sanitise()
		 * corrects rather than refuses and never registers an error, which is
		 * the whole of its contract — so the message channel has exactly one
		 * writer today and it is the line below. If that ever stops being true,
		 * this is the method to change, and settings_errors() is the call to
		 * make instead of the notice rather than beside it.
		 *
		 * @return void
		 */
		private function notices(): void {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading which message to print after core's own redirect; nothing is written.
			if ( isset( $_GET['settings-updated'] ) ) {
				$this->notice( __( 'Settings saved.', 'store-locator-for-openstreetmap' ) );
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading which message to print after this screen's own redirect; nothing is written.
			if ( isset( $_GET[ self::CLEARED_ARG ] ) ) {
				$this->notice(
					__( 'The caches were cleared. Maps and address lookups will be rebuilt as they are asked for.', 'store-locator-for-openstreetmap' )
				);
			}
		}

		/**
		 * One notice.
		 *
		 * @param string $message What it says.
		 * @return void
		 */
		private function notice( string $message ): void {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
		}

		/**
		 * The clear-cache button, in a form of its own.
		 *
		 * Of its own because html has no nested forms and because this is a
		 * different request to a different endpoint with a different
		 * credential: the settings form goes to options.php with the Settings
		 * API's nonce, and this goes to admin-post.php with one of this
		 * plugin's.
		 *
		 * @return void
		 */
		private function clear_cache_form(): void {
			echo '<h2>' . esc_html( __( 'Caches', 'store-locator-for-openstreetmap' ) ) . '</h2>';
			echo '<p class="description">' . esc_html(
				__(
					'Clears the map payload and the stored address lookups, including addresses that failed. Use this after correcting an address somewhere else. Nothing about your locations is deleted.',
					'store-locator-for-openstreetmap'
				)
			) . '</p>';

			echo '<form action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post">';
			echo '<input type="hidden" name="action" value="' . esc_attr( self::CLEAR_ACTION ) . '" />';

			wp_nonce_field( self::CLEAR_ACTION, self::NONCE_FIELD );

			echo '<p><input type="submit" class="button" value="'
				. esc_attr( __( 'Clear caches', 'store-locator-for-openstreetmap' ) ) . '" /></p>';
			echo '</form>';
		}

		/**
		 * Sends the browser somewhere and stops.
		 *
		 * wp_safe_redirect() rather than wp_redirect(): the url is built by
		 * url() out of admin_url(), so it is already this site's, and the safe
		 * variant costs nothing and is what keeps that true if the url ever
		 * starts carrying something a request supplied.
		 *
		 * @param string $url Where to.
		 * @return void
		 */
		private function go( string $url ): void {
			wp_safe_redirect( $url );

			( $this->leave )();
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

		/**
		 * The name one setting's control posts under.
		 *
		 * One array under the option's own name, which is what makes
		 * options.php hand the whole thing to Settings::sanitise() in one
		 * piece: the loop there reads `$_POST[ $option ]` for each *registered
		 * option name*, and there is one of those.
		 *
		 * @param string $key Setting name.
		 * @return string
		 */
		private static function field_name( string $key ): string {
			return Settings::OPTION . '[' . $key . ']';
		}

		/**
		 * The id one setting's control carries, for its label.
		 *
		 * @param string $key Setting name.
		 * @return string
		 */
		private static function field_id( string $key ): string {
			return 'slosm-' . str_replace( '_', '-', $key );
		}

		/**
		 * A list of numbers as the text somebody would have typed.
		 *
		 * Trailing zeros come off, so a radius list reads "5, 10, 25" rather
		 * than "5.0000, 10.0000, 25.0000" — the same rule
		 * Admin::coordinate_string() applies to a coordinate, and for the same
		 * reason: what is printed back into a field has to be what a person
		 * would write in it.
		 *
		 * @param array $values Numbers.
		 * @return string
		 */
		private static function number_list( array $values ): string {
			$text = array();

			foreach ( $values as $value ) {
				$text[] = self::number_text( $value );
			}

			return implode( ', ', $text );
		}

		/**
		 * One number as text, or '' when there is not one.
		 *
		 * @param mixed $value Number, or null.
		 * @return string
		 */
		private static function number_text( $value ): string {
			if ( ! is_int( $value ) && ! is_float( $value ) ) {
				return '';
			}

			if ( is_int( $value ) ) {
				return (string) $value;
			}

			return rtrim( rtrim( sprintf( '%.7F', $value ), '0' ), '.' );
		}

		/**
		 * How one setting is controlled, labelled and explained.
		 *
		 * One table rather than a method per field, because what a reader comes
		 * here for is the list — which control, which words — and a method per
		 * field would put twenty-nine of those between them.
		 *
		 * Every 'help' is one sentence, and that is the rule the closed list is
		 * enforced by: a setting whose purpose cannot be said in one sentence to
		 * a site owner does not belong on this screen. Settings' class docblock
		 * names the three the plan asked for that failed it.
		 *
		 * @param string $key Setting name.
		 * @return array{type: string, label: string, help: string, class: string, attributes: string, checkbox_label: string, choices: array}
		 */
		private static function control( string $key ): array {
			$controls = self::controls();

			$control = $controls[ $key ] ?? array();

			return array(
				'type'           => $control['type'] ?? 'text',
				'label'          => $control['label'] ?? $key,
				'help'           => $control['help'] ?? '',
				'class'          => $control['class'] ?? 'regular-text',
				'attributes'     => is_array( $control['attributes'] ?? null ) ? $control['attributes'] : array(),
				'checkbox_label' => $control['checkbox_label'] ?? '',
				'choices'        => $control['choices'] ?? array(),
			);
		}

		/**
		 * The whole control table.
		 *
		 * A method rather than a constant because every label is translated and
		 * a constant cannot call __().
		 *
		 * @return array<string, array>
		 */
		private static function controls(): array {
			$units = array(
				'km' => __( 'Kilometres', 'store-locator-for-openstreetmap' ),
				'mi' => __( 'Miles', 'store-locator-for-openstreetmap' ),
			);

			return array(
				'tile_url'             => array(
					'type'       => 'url',
					'label'      => __( 'Tile URL', 'store-locator-for-openstreetmap' ),
					'help'       => __( 'Where the map images come from; it must contain {z}, {x} and {y}, which is how a tile server is told which square to send.', 'store-locator-for-openstreetmap' ),
					'class'      => 'large-text code',
				),
				'tile_attribution'     => array(
					'label' => __( 'Attribution', 'store-locator-for-openstreetmap' ),
					'help'  => __( 'The credit line under the map, which almost every tile provider requires you to show.', 'store-locator-for-openstreetmap' ),
					'class' => 'large-text',
				),
				'tile_attribution_url' => array(
					'type'  => 'url',
					'label' => __( 'Attribution link', 'store-locator-for-openstreetmap' ),
					'help'  => __( 'The licence page that credit line points at; leave it empty for plain text.', 'store-locator-for-openstreetmap' ),
					'class' => 'large-text code',
				),
				'tile_max_zoom'        => array(
					'type'       => 'number',
					'label'      => __( 'Closest zoom', 'store-locator-for-openstreetmap' ),
					'help'       => __( 'How far in this tile provider draws; go past it and every tile comes back missing.', 'store-locator-for-openstreetmap' ),
					'class'      => 'small-text',
					'attributes' => array( 'min' => (int) Shortcode::MIN_ZOOM, 'max' => (int) Shortcode::MAX_ZOOM, 'step' => 1 ),
				),
				'default_lat'          => array(
					'type'       => 'text',
					'label'      => __( 'Default centre: latitude', 'store-locator-for-openstreetmap' ),
					'help'       => __( 'Where a map opens before anybody searches; leave both empty to frame it around your locations instead.', 'store-locator-for-openstreetmap' ),
					'class'      => 'small-text',
				),
				'default_lng'          => array(
					'type'  => 'text',
					'label' => __( 'Default centre: longitude', 'store-locator-for-openstreetmap' ),
					'class' => 'small-text',
				),
				'default_zoom'         => array(
					'type'       => 'number',
					'label'      => __( 'Default zoom', 'store-locator-for-openstreetmap' ),
					'help'       => __( 'How close a map starts: 1 is the whole world, 12 is a city, 16 is a street.', 'store-locator-for-openstreetmap' ),
					'class'      => 'small-text',
					'attributes' => array( 'min' => (int) Shortcode::MIN_ZOOM, 'max' => (int) Shortcode::MAX_ZOOM, 'step' => 1 ),
				),
				'map_height'           => array(
					'type'       => 'number',
					'label'      => __( 'Map height', 'store-locator-for-openstreetmap' ),
					'help'       => __( 'How tall the map is, in pixels.', 'store-locator-for-openstreetmap' ),
					'class'      => 'small-text',
					'attributes' => array( 'min' => (int) Shortcode::MIN_HEIGHT, 'max' => (int) Shortcode::MAX_HEIGHT, 'step' => 1 ),
				),
				'marker_style'         => array(
					'type'    => 'select',
					'label'   => __( 'Marker', 'store-locator-for-openstreetmap' ),
					'help'    => __( 'The standard pin is an image and cannot be restyled; the dot can be given a colour of your own.', 'store-locator-for-openstreetmap' ),
					'choices' => array(
						'pin' => __( 'Standard pin', 'store-locator-for-openstreetmap' ),
						'dot' => __( 'Coloured dot', 'store-locator-for-openstreetmap' ),
					),
				),
				'marker_colour'        => array(
					'type'  => 'color',
					'label' => __( 'Marker colour', 'store-locator-for-openstreetmap' ),
					'help'  => __( 'Used by the coloured dot, and ignored by the standard pin.', 'store-locator-for-openstreetmap' ),
					'class' => '',
				),
				'cluster'              => array(
					'type'    => 'select',
					'label'   => __( 'Grouping', 'store-locator-for-openstreetmap' ),
					'help'    => __( 'Whether nearby pins collapse into a numbered bubble; on automatic, they do once there are enough of them to overlap.', 'store-locator-for-openstreetmap' ),
					'choices' => array(
						'auto' => __( 'Automatic', 'store-locator-for-openstreetmap' ),
						'yes'  => __( 'Always group', 'store-locator-for-openstreetmap' ),
						'no'   => __( 'Never group', 'store-locator-for-openstreetmap' ),
					),
				),
				'units'                => array(
					'type'    => 'select',
					'label'   => __( 'Distance in', 'store-locator-for-openstreetmap' ),
					'help'    => __( 'The unit distances are measured and shown in.', 'store-locator-for-openstreetmap' ),
					'choices' => $units,
				),
				'radius_choices'       => array(
					'type'  => 'numbers',
					'label' => __( 'Search radii', 'store-locator-for-openstreetmap' ),
					'help'  => __( 'The distances the "within" menu offers, separated by commas.', 'store-locator-for-openstreetmap' ),
					'class' => 'large-text code',
				),
				/*
				 * min="0" and not min="1", which is what this row said until
				 * review. Settings::sanitise() takes any radius above zero —
				 * 0.5 km is a real setting for a locator inside one city — so a
				 * floor of 1 was a browser refusing a value the gate accepts.
				 * Zero itself is the one value a number input cannot exclude
				 * while allowing everything above it, and the sanitiser answers
				 * it with the default for the reason Shortcode::radius() gives:
				 * a radius of nothing is a search that can never match.
				 *
				 * Every other bound on this screen is interpolated from the
				 * constant the sanitiser clamps to, rather than retyped. Three
				 * of them were retyped and two were not, which is the state a
				 * reviewer found.
				 */
				'default_radius'       => array(
					'type'       => 'number',
					'label'      => __( 'Default radius', 'store-locator-for-openstreetmap' ),
					'help'       => __( 'Which of those distances a visitor starts on.', 'store-locator-for-openstreetmap' ),
					'class'      => 'small-text',
					'attributes' => array( 'min' => 0, 'max' => (int) Rest_Controller::MAX_RADIUS, 'step' => 'any' ),
				),
				'limit_choices'        => array(
					'type'  => 'numbers',
					'label' => __( 'Result counts', 'store-locator-for-openstreetmap' ),
					'help'  => __( 'The counts the "show at most" menu offers, separated by commas.', 'store-locator-for-openstreetmap' ),
					'class' => 'large-text code',
				),
				'default_limit'        => array(
					'type'       => 'number',
					'label'      => __( 'Default result count', 'store-locator-for-openstreetmap' ),
					'help'       => __( 'Which of those counts a visitor starts on.', 'store-locator-for-openstreetmap' ),
					'class'      => 'small-text',
					'attributes' => array( 'min' => 1, 'max' => (int) Rest_Controller::MAX_LIMIT, 'step' => 1 ),
				),
				'autocomplete'         => array(
					'type'           => 'checkbox',
					'label'          => __( 'Address suggestions', 'store-locator-for-openstreetmap' ),
					'checkbox_label' => __( 'Suggest addresses as a visitor types', 'store-locator-for-openstreetmap' ),
					'help'           => __( 'Sends each few keystrokes to the suggestion service, so turn it off if you are using a shared public one.', 'store-locator-for-openstreetmap' ),
				),
				'country'              => array(
					'label' => __( 'Restrict lookups to', 'store-locator-for-openstreetmap' ),
					'help'  => __( 'Two-letter country codes, separated by commas — "pl, de" — so a search for "Springfield" cannot land on another continent. Leave empty for the whole world.', 'store-locator-for-openstreetmap' ),
					'class' => 'regular-text code',
				),
				'near_me'              => array(
					'type'           => 'checkbox',
					'label'          => __( 'Use my location', 'store-locator-for-openstreetmap' ),
					'checkbox_label' => __( 'Offer a button that searches from the visitor’s own position', 'store-locator-for-openstreetmap' ),
					'help'           => __( 'The browser asks the visitor before anything is read, and nothing is stored.', 'store-locator-for-openstreetmap' ),
				),
				'results_position'     => array(
					'type'    => 'select',
					'label'   => __( 'List position', 'store-locator-for-openstreetmap' ),
					'help'    => __( 'Where the list of results sits on a wide screen; on a phone it always goes below the map, because there is no room beside it.', 'store-locator-for-openstreetmap' ),
					'choices' => array(
						'right' => __( 'Right of the map', 'store-locator-for-openstreetmap' ),
						'left'  => __( 'Left of the map', 'store-locator-for-openstreetmap' ),
						'below' => __( 'Below the map', 'store-locator-for-openstreetmap' ),
					),
				),
				'result_fields'        => array(
					'type'    => 'checkboxes',
					'label'   => __( 'Shown in the list', 'store-locator-for-openstreetmap' ),
					'help'    => __( 'What each row of results says; tick nothing to go back to all of them.', 'store-locator-for-openstreetmap' ),
					'choices' => array(
						'name'       => __( 'Name', 'store-locator-for-openstreetmap' ),
						'address'    => __( 'Address', 'store-locator-for-openstreetmap' ),
						'city'       => __( 'City', 'store-locator-for-openstreetmap' ),
						'distance'   => __( 'Distance', 'store-locator-for-openstreetmap' ),
						'categories' => __( 'Categories', 'store-locator-for-openstreetmap' ),
					),
				),
				'popup_fields'         => array(
					'type'    => 'checkboxes',
					'label'   => __( 'Shown in a pin’s bubble', 'store-locator-for-openstreetmap' ),
					'help'    => __( 'What a visitor sees when they open a pin — which is also what you are publishing, so a phone number you keep for staff belongs off this list.', 'store-locator-for-openstreetmap' ),
					'choices' => array(
						'name'        => __( 'Name', 'store-locator-for-openstreetmap' ),
						'address'     => __( 'Address', 'store-locator-for-openstreetmap' ),
						'categories'  => __( 'Categories', 'store-locator-for-openstreetmap' ),
						'phone'       => __( 'Phone', 'store-locator-for-openstreetmap' ),
						'email'       => __( 'Email', 'store-locator-for-openstreetmap' ),
						'url'         => __( 'Website', 'store-locator-for-openstreetmap' ),
						'hours'       => __( 'Opening hours', 'store-locator-for-openstreetmap' ),
						'description' => __( 'Description', 'store-locator-for-openstreetmap' ),
					),
				),
				'directions'           => array(
					'type'    => 'select',
					'label'   => __( 'Directions link', 'store-locator-for-openstreetmap' ),
					'help'    => __( 'Where "Directions" takes a visitor; their position is only ever sent when they click it.', 'store-locator-for-openstreetmap' ),
					'choices' => array(
						'osm'    => __( 'OpenStreetMap', 'store-locator-for-openstreetmap' ),
						'google' => __( 'Google Maps', 'store-locator-for-openstreetmap' ),
						'none'   => __( 'No link', 'store-locator-for-openstreetmap' ),
					),
				),
				'skin'                 => array(
					'type'           => 'checkbox',
					'label'          => __( 'Default styles', 'store-locator-for-openstreetmap' ),
					'checkbox_label' => __( 'Give the search controls the plugin’s own spacing and borders', 'store-locator-for-openstreetmap' ),
					'help'           => __( 'Untick to style the form yourself. The map, the results list and the markers keep their own stylesheet either way, so turning this off cannot break the map.', 'store-locator-for-openstreetmap' ),
				),
				'button_class'         => array(
					'label' => __( 'Button class', 'store-locator-for-openstreetmap' ),
					'help'  => __( 'Added to the Search and “Use my location” buttons on every locator, so they can take your theme’s own button styling. On Bricks that is bricks-button. Leave empty for the plugin’s own look; one locator can override this in the shortcode.', 'store-locator-for-openstreetmap' ),
					'class' => 'regular-text code',
				),
				'geocode_endpoint'     => array(
					'type'  => 'url',
					'label' => __( 'Geocoding endpoint', 'store-locator-for-openstreetmap' ),
					'help'  => __( 'Your own Nominatim, if you run one; empty means the public instance, which is rate limited and shared.', 'store-locator-for-openstreetmap' ),
					'class' => 'large-text code',
				),
				'suggest_endpoint'     => array(
					'type'  => 'url',
					'label' => __( 'Suggestions endpoint', 'store-locator-for-openstreetmap' ),
					'help'  => __( 'Your own Photon, if you run one; empty means the public instance.', 'store-locator-for-openstreetmap' ),
					'class' => 'large-text code',
				),
				'geocode_user_agent'   => array(
					'label' => __( 'User-Agent', 'store-locator-for-openstreetmap' ),
					'help'  => __( 'How this site identifies itself to the geocoding service, which its usage policy asks for; empty sends the plugin name and your site address.', 'store-locator-for-openstreetmap' ),
					'class' => 'large-text code',
				),
				'geocode_cache_ttl'    => array(
					'type'       => 'number',
					'label'      => __( 'Keep addresses for', 'store-locator-for-openstreetmap' ),
					'help'       => __( 'How long a looked-up address is remembered, in seconds; 0 looks every address up every time, which the public service will block you for.', 'store-locator-for-openstreetmap' ),
					'class'      => 'regular-text',
					'attributes' => array( 'min' => 0, 'step' => 1 ),
				),
				'suggest_cache_ttl'    => array(
					'type'       => 'number',
					'label'      => __( 'Keep suggestions for', 'store-locator-for-openstreetmap' ),
					'help'       => __( 'How long a suggestion list is remembered, in seconds; shorter than addresses because every visitor types something different.', 'store-locator-for-openstreetmap' ),
					'class'      => 'regular-text',
					'attributes' => array( 'min' => 0, 'step' => 1 ),
				),
				/*
				 * The label is written in the present tense and nothing reads
				 * this setting yet: uninstall.php is Task 25's, and until it
				 * lands the switch is stored and acted on by nobody. That is not
				 * a lie a site can be told, because nothing ships before Task 25
				 * — but it would become one the moment anything did, so **Task 25
				 * has to land the uninstaller or this row has to go**. It is here
				 * ahead of its reader for two reasons: it is the one setting that
				 * has to be set *before* an uninstall rather than during one, and
				 * a case pins the option name now so that Task 25 cannot invent a
				 * second one.
				 */
				'remove_data'          => array(
					'type'           => 'checkbox',
					'label'          => __( 'On uninstall', 'store-locator-for-openstreetmap' ),
					'checkbox_label' => __( 'Delete every location, category and setting when this plugin is deleted', 'store-locator-for-openstreetmap' ),
					'help'           => __( 'Off by default, because deleting the plugin to try something else should not take the addresses with it.', 'store-locator-for-openstreetmap' ),
				),
			);
		}
	}
}
