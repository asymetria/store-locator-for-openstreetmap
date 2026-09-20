<?php
/**
 * The screen that writes a [store_locator] tag, and says what it will do.
 *
 * @package Store_Locator_For_OpenStreetMap
 */

namespace Asymetria\StoreLocator\Admin;

use Asymetria\StoreLocator\Assets;
use Asymetria\StoreLocator\Geo;
use Asymetria\StoreLocator\Post_Type;
use Asymetria\StoreLocator\Rest_Controller;
use Asymetria\StoreLocator\Settings;
use Asymetria\StoreLocator\Shortcode;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( __NAMESPACE__ . '\\Shortcode_Generator' ) ) {

	/**
	 * A form that composes one shortcode, and a preview that is its own parse.
	 *
	 * WHAT "PREVIEW" MEANS HERE, AND WHY IT IS NOT A MAP
	 * ==================================================
	 * The honest answer is smaller than the word. This screen does not draw a
	 * map. It prints the composed tag, then **parses that tag the way WordPress
	 * will** and runs the result through `Shortcode::attributes()` and
	 * `Shortcode::config()` — the same two methods the front end calls — and
	 * shows what came back.
	 *
	 * The alternative was a real Leaflet map on this screen, and it was refused
	 * for two reasons, one of cost and one of truth.
	 *
	 * The cost is the one Task 21 had just finished paying off: a real preview
	 * needs `assets/js/locator.js`, the vendored Leaflet, the marker-cluster
	 * bundle when clustering is on, and a REST round trip — on an admin screen
	 * whose entire job is to produce eleven characters of text. Task 18's
	 * picker carries Leaflet because a coordinate cannot be checked without a
	 * map; a shortcode can.
	 *
	 * The truth is the part that decided it. A map drawn *here* is a second
	 * rendering path: a different container, a different stylesheet cascade, a
	 * different screen width, an admin page rather than post content, and
	 * `the_content` filters that never run. It would be a picture that is
	 * *nearly* the front end, and every gap between the two would be invisible
	 * — which is exactly the failure the brief named. What is printed below
	 * cannot drift from the front end, because it is not a reproduction of the
	 * front end: it is the front end's own resolver, run on the exact bytes the
	 * editor is about to paste.
	 *
	 * So the preview answers "what settings will this locator start with, and
	 * where did each one come from", and it says in as many words that it is
	 * not a picture of the map. A locator whose pins are in the wrong place is
	 * a geocoding problem, and the location edit screen already has the map
	 * that shows it.
	 *
	 * THE SHORTCODE CARRIES ONLY WHAT DIFFERS, AND "DIFFERS" IS MEASURED
	 * =================================================================
	 * Task 21 turned almost every attribute into a site-wide setting, so a
	 * generator that printed all thirteen would produce
	 * `[store_locator height="480" zoom="12" ... cluster="auto"]` — thirteen
	 * pieces of noise that say nothing, that an editor will paste into a page
	 * and never understand, and that freeze the site's defaults into post
	 * content so that changing a setting stops changing that page.
	 *
	 * The rule here is therefore not "drop anything that equals a default".
	 * There is no table of defaults in this file to compare against; a second
	 * copy of the defaults is the drift this task exists to avoid. The rule is
	 * an **omission test**:
	 *
	 *     an attribute is kept if and only if removing it would change
	 *     what `Shortcode::attributes()` resolves the shortcode to.
	 *
	 * `minimise()` does exactly that, one attribute at a time, and it needs to
	 * know nothing at all about what any attribute means. Three things fall out
	 * of it for free, and each of them is a case below:
	 *
	 * - A value that equals the site setting is dropped, because the site
	 *   setting is what `attributes()` falls back to.
	 * - A value that is *clamped* to the same thing is dropped too. `zoom="25"`
	 *   on a site whose tile server stops at 19, with a default zoom of 19,
	 *   resolves to 19 either way — so it says nothing and goes. A default
	 *   table would have kept it, because 25 is not 19.
	 * - A category that matches no term is **kept**, because dropping it would
	 *   change `category_missing`, which is how `render()` decides to print the
	 *   "no category matched" comment. The editor asked for a filter; they are
	 *   told it is broken rather than having it silently deleted.
	 *
	 * What minimising does *not* do is rewrite a value it kept. `zoom="25"`
	 * on a site whose tile server stops at 19 but whose default zoom is 12
	 * stays `zoom="25"`, and the preview says 19. Printing the resolved 19
	 * instead would be this screen silently editing what somebody typed, and
	 * it would freeze today’s tile limit into post content as well: the day
	 * that setting goes to 20, `zoom="25"` means 20 and `zoom="19"` still
	 * means 19. The screen never changes an answer it kept. It says what the
	 * answer will do, which is what the preview is for.
	 *
	 * WHAT A VALUE MAY CONTAIN, WHICH IS LESS THAN AN EDITOR WILL TYPE
	 * ===============================================================
	 * The output of this screen is pasted into post content and parsed by
	 * `do_shortcode()`. Two characters cannot survive that, and neither of them
	 * can be escaped:
	 *
	 * - `"` ends the value. `get_shortcode_atts_regex()`
	 *   (wp-includes/shortcodes.php line 594 of WordPress 6.9.1) matches a
	 *   double-quoted value as `"([^"]*)"`, so the first inner quote closes it.
	 *   Backslash-escaping does not help: the regex never sees the backslash
	 *   rule, and `shortcode_parse_atts()` only applies `stripcslashes()` to
	 *   what the regex already captured (line 620).
	 * - `]` ends the *tag*. Group 3 of `get_shortcode_regex()` — the whole
	 *   inside of the opening tag — is `[^\]\/]*` with one narrow escape hatch
	 *   for a forward slash (lines 342-348), so a `]` in a label truncates the
	 *   shortcode and leaves the rest of it as visible text on the page.
	 *
	 * `[` is removed with them. It is not fatal on its own, but it is what
	 * `[[tag]]` escaping, `shortcode_unautop()` and the block editor's own
	 * shortcode handling all key on, and a label containing one is a bug report
	 * waiting to happen. `\` goes because `stripcslashes()` would eat it.
	 *
	 * `<` is deliberately *not* on that list, and the reason is **not** that
	 * nothing raw survives. That was the stated reason here and it is false:
	 * `sanitize_text_field( '< >' )` is `'< >'`, measured, and a case asserts
	 * it. `wp_pre_kses_less_than()` (wp-includes/formatting.php line 5197 of
	 * WordPress 6.9.1) matches `%<[^>]*?((?=<)|>|$)%` and its callback escapes
	 * a run only when that run contains no `>`, so `< >` is left exactly as it
	 * was typed and `strip_tags()` does not treat `< ` as a tag start either.
	 *
	 * What makes it safe is the *pairing*, and that falls out of the same
	 * regex. A run is kept raw only when it ends in `>`, and the run is
	 * `<[^>]*?>` — a `<`, no `<` in between, then its `>`. So every raw `<`
	 * that survives has a `>` before the next one, which is character for
	 * character what core's unclosed-element check accepts:
	 * `^[^<]*+(?:<[^>]*+>[^<]*+)*+$` (shortcodes.php lines 634-640). Stripping
	 * a whole `<…>` run afterwards cannot unpair the rest, and nothing later in
	 * the sanitiser removes a `<` or a `>` on its own.
	 *
	 * That is an argument plus evidence rather than a proof: every string up to
	 * five characters over `< > a space " /` — 9,330 of them — was run through
	 * `sanitize_text_field()` and checked against that pattern, and none came
	 * back unpaired. The rule it is standing in front of is brutal if it ever
	 * does — a value containing an unclosed `<` is blanked outright — so if a
	 * counterexample turns up, the answer is to put `<` on the list rather than
	 * to argue with it.
	 *
	 * What makes this safe rather than hopeful is that the preview is computed
	 * from the composed string and not from the form: `parse()` runs core's own
	 * regex over the tag this screen printed. If a value ever did smuggle a
	 * bracket through, the preview would show the truncated shortcode rather
	 * than the intended one, and the case *the preview is the parse of the
	 * string, not of the form* would fail.
	 *
	 * WHY THIS SCREEN HAS NO NONCE
	 * ============================
	 * Because it writes nothing. The form is a `GET` — it re-renders itself
	 * with different numbers in it and touches no option, no post and no
	 * transient — and a nonce on a request with no side effect protects
	 * nothing while costing the one property a GET form has worth keeping: the
	 * url is a bookmark, and an editor can send a colleague the exact locator
	 * they are describing.
	 *
	 * The capability check is still here and still matters, because
	 * `user_can_access_admin_page()` (wp-admin/includes/menu.php line 371 of
	 * WordPress 6.9.1) is what enforces the capability given to
	 * `add_submenu_page()`, and a callback reachable another way has to check
	 * for itself.
	 *
	 * The worst a crafted link can do is show somebody a locator configured
	 * differently from the one they meant to build — in their own site's
	 * shortcode, with every value already run through the sanitiser the front
	 * end uses. They still have to copy it and paste it themselves.
	 */
	final class Shortcode_Generator {

		/**
		 * The menu slug, which is also the tail of the hook suffix.
		 *
		 * It lives on Assets rather than here for the reason the file header of
		 * Settings gives: `Assets::enqueue_admin()` has to recognise this
		 * screen, Assets is in includes/, and includes/ may not depend on
		 * admin/. `Settings::PAGE` sits on the other side of the same rule.
		 *
		 * @var string
		 */
		public const PAGE = Assets::SCREEN_SHORTCODE;

		/**
		 * The capability this screen asks for.
		 *
		 * `edit_posts`, not `manage_options`. This screen writes nothing, and
		 * the person who needs it is whoever puts the locator on a page — an
		 * author or an editor, who will never have `manage_options` on a site
		 * that takes roles seriously. Locking the shortcode builder to
		 * administrators means the people expected to use the shortcode cannot
		 * see how to write one.
		 *
		 * It is also the capability that gets them here at all: the parent menu
		 * is `edit.php?post_type=slosm_store`, and Post_Type registers with
		 * core's default `capability_type`, so the locations list is already an
		 * `edit_posts` screen.
		 *
		 * @var string
		 */
		public const CAPABILITY = 'edit_posts';

		/**
		 * The request argument the form's fields are nested under.
		 *
		 * One array rather than thirteen loose arguments, so the loop that
		 * reads them is the loop that writes them and neither can list an
		 * attribute the other does not.
		 *
		 * @var string
		 */
		public const FIELD = 'slosm_atts';

		/**
		 * Characters removed from every value, because a shortcode cannot carry them.
		 *
		 * The class docblock says what each one does. This is a list of single
		 * characters and is applied with str_replace(), not a pattern.
		 *
		 * Four and not six. `<` and `>` are off the list because
		 * `sanitize_text_field()` handles the dangerous shapes and core's own
		 * parser accepts what it leaves; the class docblock has the mechanism,
		 * and it is the pairing rather than removal. A bare `>` is harmless on
		 * its own: core's unclosed-element rule keys on `<`, and group 3 of the
		 * shortcode regex admits `>` freely.
		 *
		 * @var string[]
		 */
		public const FORBIDDEN = array( '"', '[', ']', '\\' );

		/**
		 * The two code points core folds to a space before it parses anything.
		 *
		 * `shortcode_parse_atts()` runs
		 * `preg_replace( "/[\x{00a0}\x{200b}]+/u", ' ', $text )` over the inside
		 * of the tag *before* the attribute regex sees it —
		 * wp-includes/shortcodes.php line 616 of WordPress 6.9.1 — and neither
		 * `sanitize_text_field()` nor anything else in this file used to touch
		 * them. That made the invariant `value()` is written for false: a label
		 * pasted out of Word or Google Docs, which is the ordinary way that
		 * field gets filled in, went into the tag with a non-breaking space in
		 * it and came back out with an ordinary one.
		 *
		 * Folding them here rather than apologising for it later is what keeps
		 * the invariant true, and it costs nothing an editor can want: core is
		 * going to do it anyway, so a non-breaking space in a label can never
		 * reach the front end. Doing it first means the textarea shows the
		 * value that will actually be used, and `minimise()` compares the value
		 * core will compare rather than one that only looks different.
		 *
		 * @var string
		 */
		public const PARSER_SPACES = '/[\x{00a0}\x{200b}]+/u';

		/**
		 * The resolver every answer on this screen comes from.
		 *
		 * @var Shortcode
		 */
		private Shortcode $shortcode;

		/**
		 * Builds the screen.
		 *
		 * @param Shortcode|null $shortcode Resolver to use; one is built when omitted.
		 */
		public function __construct( ?Shortcode $shortcode = null ) {
			$this->shortcode = $shortcode ?? new Shortcode();
		}

		/**
		 * Adds the screen under the locations menu, beside Settings.
		 *
		 * @return string The hook suffix core hands back.
		 */
		public function add_page(): string {
			return (string) add_submenu_page(
				'edit.php?post_type=' . Post_Type::POST_TYPE,
				__( 'Store Locator Shortcode', 'store-locator-for-openstreetmap' ),
				__( 'Shortcode', 'store-locator-for-openstreetmap' ),
				self::CAPABILITY,
				self::PAGE,
				array( $this, 'render' )
			);
		}

		/**
		 * The url of this screen.
		 *
		 * @return string
		 */
		public static function url(): string {
			$url = add_query_arg( 'post_type', Post_Type::POST_TYPE, admin_url( 'edit.php' ) );

			return add_query_arg( 'page', self::PAGE, $url );
		}

		/**
		 * One control per shortcode attribute, in the order the shortcode lists them.
		 *
		 * The keys here are asserted to be `array_keys( Shortcode::raw_defaults() )`
		 * exactly, and that assertion is the tripwire this task was asked for:
		 * an attribute added to `Shortcode` and not to this list fails a case
		 * rather than quietly becoming an attribute the generator cannot write.
		 *
		 * What is *not* restated here is anything Shortcode already knows. Every
		 * bound is a constant read from the class that enforces it, and every
		 * closed list of choices is read from the class that validates against
		 * it. A number typed into one of these inputs that the browser's own
		 * `min`/`max` would have refused is clamped by `attributes()` anyway and
		 * then dropped by `minimise()` if the clamp lands on the default, so the
		 * bounds here are a courtesy rather than a gate.
		 *
		 * Three of these are tri-state selects rather than checkboxes, and that
		 * is the whole reason they are selects: a checkbox has two states and
		 * this screen needs three — yes, no, and "say nothing and let the site
		 * decide". A checkbox would force every generated shortcode to carry an
		 * opinion about clustering.
		 *
		 * @return array<string, array<string, mixed>>
		 */
		public static function controls(): array {
			$inherit = __( 'Use the site setting', 'store-locator-for-openstreetmap' );

			$yes_no = array(
				''    => $inherit,
				'yes' => __( 'Yes', 'store-locator-for-openstreetmap' ),
				'no'  => __( 'No', 'store-locator-for-openstreetmap' ),
			);

			return array(
				'height'      => array(
					'type'   => 'number',
					'label'  => __( 'Map height', 'store-locator-for-openstreetmap' ),
					'help'   => __( 'In pixels.', 'store-locator-for-openstreetmap' ),
					'min'    => (string) Shortcode::MIN_HEIGHT,
					'max'    => (string) Shortcode::MAX_HEIGHT,
					'step'   => '1',
					'suffix' => __( 'px', 'store-locator-for-openstreetmap' ),
				),
				'zoom'        => array(
					'type'  => 'number',
					'label' => __( 'Starting zoom', 'store-locator-for-openstreetmap' ),
					'help'  => __( '1 is the whole world, 12 is a city, 16 is a street. A site whose tile server stops short of the number you type will be pulled back to where it stops.', 'store-locator-for-openstreetmap' ),
					'min'   => (string) Shortcode::MIN_ZOOM,
					'max'   => (string) Shortcode::MAX_ZOOM,
					'step'  => '1',
				),
				'lat'         => array(
					'type'  => 'text',
					'label' => __( 'Centre: latitude', 'store-locator-for-openstreetmap' ),
					'help'  => __( 'Where this map opens before anybody searches. Fill both in or neither.', 'store-locator-for-openstreetmap' ),
				),
				'lng'         => array(
					'type'  => 'text',
					'label' => __( 'Centre: longitude', 'store-locator-for-openstreetmap' ),
				),
				'radius'      => array(
					'type'  => 'number',
					'label' => __( 'Starting radius', 'store-locator-for-openstreetmap' ),
					'help'  => __( 'In whichever unit is chosen below.', 'store-locator-for-openstreetmap' ),
					'min'   => '0',
					'max'   => (string) Rest_Controller::MAX_RADIUS,
					'step'  => 'any',
				),
				'limit'       => array(
					'type'  => 'number',
					'label' => __( 'Starting result count', 'store-locator-for-openstreetmap' ),
					'min'   => '1',
					'max'   => (string) Rest_Controller::MAX_LIMIT,
					'step'  => '1',
				),
				'units'       => array(
					'type'    => 'select',
					'label'   => __( 'Distance in', 'store-locator-for-openstreetmap' ),
					'choices' => self::choices(
						Geo::UNITS,
						array(
							'km' => __( 'Kilometres', 'store-locator-for-openstreetmap' ),
							'mi' => __( 'Miles', 'store-locator-for-openstreetmap' ),
						),
						$inherit
					),
				),
				'category'    => array(
					'type'  => 'text',
					'label' => __( 'Only this category', 'store-locator-for-openstreetmap' ),
					'help'  => __( 'A category slug, name or id. Leave empty to show every location.', 'store-locator-for-openstreetmap' ),
				),
				'search'      => array(
					'type'  => 'text',
					'label' => __( 'Search this on load', 'store-locator-for-openstreetmap' ),
					'help'  => __( 'An address or postcode the locator looks up as soon as the page opens.', 'store-locator-for-openstreetmap' ),
				),
				'label'       => array(
					'type'  => 'text',
					'label' => __( 'Name for screen readers', 'store-locator-for-openstreetmap' ),
					'help'  => __( 'Tells one locator from another when a page has two. It is not printed on the page.', 'store-locator-for-openstreetmap' ),
				),
				'near_me'     => array(
					'type'    => 'select',
					'label'   => __( '“Use my location” button', 'store-locator-for-openstreetmap' ),
					'choices' => $yes_no,
				),
				'auto_locate' => array(
					'type'    => 'select',
					'label'   => __( 'Ask for the visitor’s position on load', 'store-locator-for-openstreetmap' ),
					'help'    => __( 'Off unless you say otherwise, on every site. The browser still asks the visitor first, and a permission prompt nobody triggered is a good way to be refused.', 'store-locator-for-openstreetmap' ),
					'choices' => $yes_no,
				),
				'cluster'     => array(
					'type'    => 'select',
					'label'   => __( 'Group nearby pins', 'store-locator-for-openstreetmap' ),
					'choices' => self::choices(
						Settings::CLUSTER_CHOICES,
						array(
							'auto' => __( 'Automatically', 'store-locator-for-openstreetmap' ),
							'yes'  => __( 'Always', 'store-locator-for-openstreetmap' ),
							'no'   => __( 'Never', 'store-locator-for-openstreetmap' ),
						),
						$inherit
					),
				),
				/*
				 * The help text names another theme's class, and that is
				 * documentation rather than a dependency. Nothing in this
				 * plugin's markup carries `bricks-button`: the field is empty
				 * until somebody types it, the renderer adds whatever they
				 * typed, and a theme that renames its own class leaves this
				 * sentence stale rather than leaving a locator broken. What
				 * would be a dependency is the class hardcoded into
				 * Shortcode::filters(), which is exactly what this control
				 * exists instead of.
				 */
				'button_class' => array(
					'type'  => 'text',
					'label' => __( 'Extra class on the buttons', 'store-locator-for-openstreetmap' ),
					'help'  => __( 'Added to the Search and “Use my location” buttons so they can take your theme’s own button styling. On Bricks that is bricks-button. Leave empty for the plugin’s own look.', 'store-locator-for-openstreetmap' ),
				),
			);
		}

		/**
		 * A select's options, built from the list the value will be validated against.
		 *
		 * The *allowed values* come from whichever class enforces them —
		 * `Geo::UNITS`, `Settings::CLUSTER_CHOICES` — and the labels are a
		 * lookup beside them. A value with no label here still gets an option,
		 * spelled as itself, so that a unit added to `Geo` appears in this form
		 * unlabelled rather than silently missing; the case that pins the two
		 * lists together will then ask for a label.
		 *
		 * Public because it is the only part of this file with a rule in it that
		 * cannot be reached through `controls()`: every value in `Geo::UNITS`
		 * and `Settings::CLUSTER_CHOICES` has a label today, so the unlabelled
		 * branch is unreachable from the outside and a mutant deleting it
		 * survived a whole sweep. A case calls this directly instead.
		 *
		 * @param string[]              $allowed The closed list, in its own order.
		 * @param array<string, string> $labels  Labels for the values that have one.
		 * @param string                $inherit Label for the empty "say nothing" option.
		 * @return array<string, string>
		 */
		public static function choices( array $allowed, array $labels, string $inherit ): array {
			$choices = array( '' => $inherit );

			foreach ( $allowed as $value ) {
				$choices[ (string) $value ] = $labels[ (string) $value ] ?? (string) $value;
			}

			return $choices;
		}

		/**
		 * The values this request asked for, cleaned and stripped of the empty ones.
		 *
		 * Reads one request argument, keeps only the keys `Shortcode` knows
		 * about, and drops everything that came back empty — an empty field is
		 * how this form spells "say nothing about this", and it is also what
		 * every one of `Shortcode`'s own sanitisers reads as "fall back".
		 *
		 * @return array<string, string>
		 */
		public function chosen(): array {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- a read-only screen behind edit_posts, so there is no nonce and the class docblock says why; the unslash and the sanitiser are on the two lines below.
			$raw = $_GET[ self::FIELD ] ?? array();

			if ( ! is_array( $raw ) ) {
				return array();
			}

			// Unslash the whole array and hand it to clean(), which is already
			// the thing that decides which keys are attributes and which values
			// are usable. An earlier version filtered here as well, and a
			// mutation sweep showed the filter for what it was: two mutants
			// gutting it changed nothing observable, because clean() catches
			// the same two cases immediately afterwards.
			$unslashed = wp_unslash( $raw );

			return self::clean( is_array( $unslashed ) ? $unslashed : array() );
		}

		/**
		 * A set of values, cleaned, ordered, and with the empty ones gone.
		 *
		 * Both public entry points run this: `chosen()` so that the form shows
		 * back what it will actually write, and `compose()` so that a caller
		 * handing it raw text — every case below does — cannot get a tag out
		 * that the request path would never have produced. Running it twice is
		 * the same as running it once, which is what lets both do it.
		 *
		 * The order is `raw_defaults()`' order and not the caller's, because a
		 * greedy minimisation is order-dependent and a generated shortcode that
		 * depended on the order PHP happened to receive the query string in
		 * would be a different string from one request to the next.
		 *
		 * @param array<string, mixed> $given Whatever was asked for.
		 * @return array<string, string>
		 */
		private static function clean( array $given ): array {
			$clean = array();

			foreach ( array_keys( Shortcode::raw_defaults() ) as $key ) {
				if ( ! isset( $given[ $key ] ) || ! is_scalar( $given[ $key ] ) ) {
					continue;
				}

				$value = self::value( (string) $given[ $key ] );

				if ( '' === $value ) {
					continue;
				}

				$clean[ $key ] = $value;
			}

			return $clean;
		}

		/**
		 * One value, cleaned until a shortcode parser can carry it.
		 *
		 * The four characters a shortcode attribute cannot hold go first, named
		 * in the class docblock, and `sanitize_text_field()` goes last.
		 *
		 * That order is the whole point, and it took a mutation sweep to get it
		 * right. This method used to sanitise first, on a stated reason that was
		 * simply false — that removing the characters first would leave
		 * "scriptalert" behind out of `<script>alert(1)</script>`. It does not:
		 * none of the four is inside those tags, both orders produce "Shops",
		 * and a mutant swapping them survived. The safety argument was empty
		 * too. `strip_tags()` is not fooled either way; `<b]r>` is stripped
		 * exactly as `<br>` is.
		 *
		 * What the order really buys is a **fixed point**. Sanitising last means
		 * the value handed back is one `sanitize_text_field()` can no longer
		 * change — and `sanitize_text_field()` is the first thing
		 * `Shortcode::text()` does to it on the far side. Sanitising first does
		 * not have that property: `a ] b` comes out as `a  b`, with a double
		 * space the resolver then collapses, and the tag and the page disagree.
		 *
		 * The fixed point is only half of the round trip, and the other half is
		 * what the PARSER_SPACES fold above is for. Before `Shortcode::text()`
		 * ever runs, `shortcode_parse_atts()` folds two code points to a space,
		 * and a claim that this method's output survives the round trip
		 * "character for character" was false until those were folded here as
		 * well. With both halves in place the claim is true and there is a case
		 * that composes, parses and compares to prove it.
		 *
		 * There is no trim() here. `sanitize_text_field()` trims, so one would
		 * be dead code — and a mutant deleting dead code survives every sweep
		 * there is.
		 *
		 * @param string $raw Whatever the form posted.
		 * @return string
		 */
		public static function value( string $raw ): string {
			$folded = (string) preg_replace( self::PARSER_SPACES, ' ', $raw );

			return sanitize_text_field( str_replace( self::FORBIDDEN, '', $folded ) );
		}

		/**
		 * The smallest set of attributes that still resolves to the same locator.
		 *
		 * The omission test, and the whole of this class's answer to "what
		 * differs from the site defaults". See the class docblock.
		 *
		 * The walk is in `raw_defaults()` order so that the result is the same
		 * every time for the same input — a greedy minimisation is
		 * order-dependent in general, and an unstable order would make the
		 * generated shortcode depend on how PHP happened to order the request.
		 *
		 * @param array<string, mixed> $chosen What the editor asked for; cleaned on the way in.
		 * @return array<string, string>
		 */
		public function minimise( array $chosen ): array {
			$kept = self::clean( $chosen );

			// The resolution of $kept, hoisted out of the walk. Without it this
			// resolves the same unchanged set once per attribute — about
			// twenty-seven attributes() calls per render, each one a full
			// Settings::all() with no memo behind it and a get_term_by() on
			// top.
			//
			// It never has to be refreshed, and that is worth saying rather
			// than leaving as a coincidence: $kept only changes on the branch
			// below, and that branch is taken only when the candidate resolved
			// to the same thing. So the resolution of $kept is an invariant of
			// this loop. Re-assigning it there would be dead code, and dead
			// code survives every mutation sweep there is.
			$resolved = $this->shortcode->attributes( $kept );

			foreach ( array_keys( $kept ) as $key ) {
				$without = $kept;

				unset( $without[ $key ] );

				if ( $this->shortcode->attributes( $without ) === $resolved ) {
					$kept = $without;
				}
			}

			return $kept;
		}

		/**
		 * The tag itself.
		 *
		 * @param array<string, mixed> $chosen What the editor asked for; cleaned on the way in.
		 * @return string
		 */
		public function compose( array $chosen ): string {
			$tag = '[' . Shortcode::TAG;

			foreach ( $this->minimise( $chosen ) as $key => $value ) {
				$tag .= ' ' . $key . '="' . $value . '"';
			}

			return $tag . ']';
		}

		/**
		 * The attributes a composed tag actually carries, read the way core reads them.
		 *
		 * Core's own regex, core's own attribute parser, core's own order of
		 * operations — `do_shortcode()` line 273 and `do_shortcode_tag()` line
		 * 401 of wp-includes/shortcodes.php, WordPress 6.9.1. Nothing here
		 * trusts what `compose()` meant to write.
		 *
		 * @param string $tag A shortcode as text.
		 * @return array<string, string>|null Null when it is not a locator tag at all.
		 */
		public static function parse( string $tag ): ?array {
			$pattern = get_shortcode_regex( array( Shortcode::TAG ) );

			if ( 1 !== preg_match( "/$pattern/", $tag, $match ) ) {
				return null;
			}

			// [[store_locator]] is core's escape for printing a shortcode
			// rather than running it (do_shortcode_tag() line 396). Nothing
			// here writes one, and a preview that resolved one would be
			// describing a locator that will never exist.
			if ( '[' === ( $match[1] ?? '' ) && ']' === ( $match[6] ?? '' ) ) {
				return null;
			}

			$atts = shortcode_parse_atts( $match[3] ?? '' );

			return is_array( $atts ) ? $atts : array();
		}

		/**
		 * What the composed tag will do, worked out by the front end's own code.
		 *
		 * @param string $tag A shortcode as text.
		 * @return array<string, mixed>|null Null when the tag does not parse.
		 */
		public function preview( string $tag ): ?array {
			$given = self::parse( $tag );

			if ( null === $given ) {
				return null;
			}

			$resolved = $this->shortcode->attributes( $given );
			$config   = $this->shortcode->config( $resolved );
			$rows     = array();

			foreach ( array_keys( Shortcode::raw_defaults() ) as $key ) {
				$rows[] = array(
					'key' => $key,
					// array_key_exists and not ??, because two of these
					// resolve to null on a site that has not set a default
					// centre, and ?? would quietly turn that null into an
					// empty string — a preview that disagreed with
					// attributes() about the one value the map is built from.
					'value'     => array_key_exists( $key, $resolved ) ? $resolved[ $key ] : '',
					'shortcode' => array_key_exists( $key, $given ),
				);
			}

			return array(
				'rows'    => $rows,
				'missing' => (string) $resolved['category_missing'],
				'config'  => $config,
			);
		}

		/**
		 * Prints the screen.
		 *
		 * @return void
		 */
		public function render(): void {
			if ( ! current_user_can( self::CAPABILITY ) ) {
				wp_die(
					esc_html__( 'Sorry, you are not allowed to build shortcodes for this site.', 'store-locator-for-openstreetmap' ),
					'',
					403
				);
			}

			$chosen = $this->chosen();
			$tag    = $this->compose( $chosen );

			echo '<div class="wrap slosm-shortcode">';
			echo '<h1>' . esc_html( __( 'Store Locator Shortcode', 'store-locator-for-openstreetmap' ) ) . '</h1>';
			echo '<p class="description">' . esc_html(
				__(
					'Fill in only what this locator should do differently from the site settings. Everything left empty stays with the settings, so changing them later changes this locator too.',
					'store-locator-for-openstreetmap'
				)
			) . '</p>';

			$this->form( $chosen );
			$this->output( $tag );
			$this->preview_html( $tag );

			echo '</div>';
		}

		/**
		 * The form.
		 *
		 * @param array<string, string> $chosen Cleaned values from this request.
		 * @return void
		 */
		private function form( array $chosen ): void {
			echo '<form method="get" action="' . esc_url( admin_url( 'edit.php' ) ) . '">';

			// A GET form replaces the query string wholesale, so the two
			// arguments that say which screen this is have to be carried by the
			// form itself or the submit lands on the posts list.
			echo '<input type="hidden" name="post_type" value="' . esc_attr( Post_Type::POST_TYPE ) . '" />';
			echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '" />';

			echo '<table class="form-table" role="presentation"><tbody>';

			foreach ( self::controls() as $key => $control ) {
				$id = self::field_id( $key );

				echo '<tr>';
				echo '<th scope="row"><label for="' . esc_attr( $id ) . '">'
					. esc_html( (string) ( $control['label'] ?? $key ) ) . '</label></th>';
				echo '<td>';

				if ( 'select' === ( $control['type'] ?? 'text' ) ) {
					$this->select( $key, $chosen[ $key ] ?? '', $control );
				} else {
					$this->input( $key, $chosen[ $key ] ?? '', $control );
				}

				if ( '' !== (string) ( $control['suffix'] ?? '' ) ) {
					echo ' ' . esc_html( (string) $control['suffix'] );
				}

				if ( '' !== (string) ( $control['help'] ?? '' ) ) {
					echo '<p class="description">' . esc_html( (string) $control['help'] ) . '</p>';
				}

				echo '</td></tr>';
			}

			echo '</tbody></table>';

			echo '<p class="submit">'
				. '<input type="submit" class="button button-primary" value="'
				. esc_attr( __( 'Build the shortcode', 'store-locator-for-openstreetmap' ) ) . '" /> '
				. '<a class="button" href="' . esc_url( self::url() ) . '">'
				. esc_html( __( 'Start again', 'store-locator-for-openstreetmap' ) ) . '</a>'
				. '</p>';

			echo '</form>';
		}

		/**
		 * One text or number input.
		 *
		 * @param string               $key     Attribute name.
		 * @param string               $value   Current value.
		 * @param array<string, mixed> $control Control description.
		 * @return void
		 */
		private function input( string $key, string $value, array $control ): void {
			$type = 'number' === ( $control['type'] ?? 'text' ) ? 'number' : 'text';

			echo '<input type="' . esc_attr( $type ) . '"'
				. ' id="' . esc_attr( self::field_id( $key ) ) . '"'
				. ' name="' . esc_attr( self::field_name( $key ) ) . '"'
				. ' value="' . esc_attr( $value ) . '"'
				. ' class="' . esc_attr( 'number' === $type ? 'small-text' : 'regular-text' ) . '"';

			foreach ( array( 'min', 'max', 'step' ) as $bound ) {
				if ( isset( $control[ $bound ] ) ) {
					echo ' ' . esc_attr( $bound ) . '="' . esc_attr( (string) $control[ $bound ] ) . '"';
				}
			}

			echo ' />';
		}

		/**
		 * One select.
		 *
		 * @param string               $key     Attribute name.
		 * @param string               $value   Current value.
		 * @param array<string, mixed> $control Control description.
		 * @return void
		 */
		private function select( string $key, string $value, array $control ): void {
			echo '<select id="' . esc_attr( self::field_id( $key ) ) . '"'
				. ' name="' . esc_attr( self::field_name( $key ) ) . '">';

			foreach ( (array) ( $control['choices'] ?? array() ) as $option => $label ) {
				echo '<option value="' . esc_attr( (string) $option ) . '"';
				selected( (string) $option, $value );
				echo '>' . esc_html( (string) $label ) . '</option>';
			}

			echo '</select>';
		}

		/**
		 * The composed tag, in something an editor can select and copy.
		 *
		 * The textarea is the whole of the no-JavaScript answer: it is
		 * readonly, it holds the text, and it can be selected with a pointer or
		 * with the keyboard. The copy *button* is not printed here at all —
		 * `assets/js/shortcode.js` creates it — because a button that does
		 * nothing when a script did not load is worse than no button, and this
		 * way there is nothing to hide.
		 *
		 * It carries no `name`, so submitting the form does not send the
		 * generated tag back as a request argument.
		 *
		 * @param string $tag The composed shortcode.
		 * @return void
		 */
		private function output( string $tag ): void {
			echo '<h2>' . esc_html( __( 'Your shortcode', 'store-locator-for-openstreetmap' ) ) . '</h2>';

			echo '<div class="slosm-shortcode__output">';
			echo '<textarea class="slosm-shortcode__text large-text code" rows="2" readonly'
				. ' aria-label="' . esc_attr( __( 'The generated shortcode', 'store-locator-for-openstreetmap' ) ) . '">'
				. esc_textarea( $tag ) . '</textarea>';

			// Printed empty and printed by PHP rather than by the script: a
			// live region has to be in the document before its text changes, or
			// a screen reader has nothing to watch.
			echo '<p class="slosm-shortcode__status" role="status" aria-live="polite"></p>';
			echo '</div>';

			echo '<p class="description">' . esc_html(
				__(
					'Paste it into a page, a post, or a shortcode block. Nothing you leave empty is written into it, so the site settings keep control of the rest.',
					'store-locator-for-openstreetmap'
				)
			) . '</p>';
		}

		/**
		 * The preview: what the tag above resolves to, and what that will do.
		 *
		 * @param string $tag The composed shortcode.
		 * @return void
		 */
		private function preview_html( string $tag ): void {
			$preview = $this->preview( $tag );

			echo '<h2>' . esc_html( __( 'What this shortcode will do', 'store-locator-for-openstreetmap' ) ) . '</h2>';

			if ( null === $preview ) {
				echo '<div class="notice notice-error inline"><p>' . esc_html(
					__( 'That shortcode could not be read back, so nothing below can be trusted. Please report this.', 'store-locator-for-openstreetmap' )
				) . '</p></div>';

				return;
			}

			echo '<p class="description">' . esc_html(
				__(
					'This is not a picture of the map. It is the shortcode above, read back with the same code the front end uses, so what it says is what a visitor will get.',
					'store-locator-for-openstreetmap'
				)
			) . '</p>';

			if ( '' !== $preview['missing'] ) {
				echo '<div class="notice notice-warning inline"><p>' . esc_html(
					sprintf(
						/* translators: %s: the category slug, name or id that was typed. */
						__( 'No category matches “%s”, so this locator will show every location. Check the slug, name or id.', 'store-locator-for-openstreetmap' ),
						$preview['missing']
					)
				) . '</p></div>';
			}

			$this->sentences( $preview['config'] );

			echo '<table class="widefat striped slosm-shortcode__preview"><thead><tr>';
			echo '<th scope="col">' . esc_html( __( 'Setting', 'store-locator-for-openstreetmap' ) ) . '</th>';
			echo '<th scope="col">' . esc_html( __( 'Value', 'store-locator-for-openstreetmap' ) ) . '</th>';
			echo '<th scope="col">' . esc_html( __( 'Where it comes from', 'store-locator-for-openstreetmap' ) ) . '</th>';
			echo '</tr></thead><tbody>';

			$controls = self::controls();

			foreach ( $preview['rows'] as $row ) {
				$key = (string) $row['key'];

				echo '<tr><th scope="row">'
					. esc_html( (string) ( $controls[ $key ]['label'] ?? $key ) )
					. '</th>';
				echo '<td><code>' . esc_html( self::readable( $row['value'] ) ) . '</code></td>';
				echo '<td>' . esc_html(
					$row['shortcode']
						? __( 'This shortcode', 'store-locator-for-openstreetmap' )
						: __( 'The site settings', 'store-locator-for-openstreetmap' )
				) . '</td></tr>';
			}

			echo '</tbody></table>';
		}

		/**
		 * The few things about a locator that a table of attributes does not say.
		 *
		 * Every one of these is read out of `Shortcode::config()` — the array
		 * the front-end script is actually handed — rather than worked out
		 * again here.
		 *
		 * @param array<string, mixed> $config What config() returned.
		 * @return void
		 */
		private function sentences( array $config ): void {
			$count = (int) ( $config['count'] ?? 0 );

			$lines = array(
				'preload' === ( $config['mode'] ?? '' )
					? sprintf(
						/* translators: %s: number of published locations. */
						_n(
							'This site has %s published location, and the whole of it is sent with the page.',
							'This site has %s published locations, and the whole of them are sent with the page.',
							$count,
							'store-locator-for-openstreetmap'
						),
						number_format_i18n( $count )
					)
					: sprintf(
						/* translators: %s: number of published locations. */
						_n(
							'This site has %s published location, which is too many to send at once, so the locator asks the server as the visitor searches.',
							'This site has %s published locations, which is too many to send at once, so the locator asks the server as the visitor searches.',
							$count,
							'store-locator-for-openstreetmap'
						),
						number_format_i18n( $count )
					),
				( $config['cluster'] ?? false )
					? __( 'Nearby pins are grouped into a numbered bubble.', 'store-locator-for-openstreetmap' )
					: __( 'Every pin is drawn on its own.', 'store-locator-for-openstreetmap' ),
			);

			echo '<ul class="slosm-shortcode__summary">';

			foreach ( $lines as $line ) {
				echo '<li>' . esc_html( $line ) . '</li>';
			}

			echo '</ul>';
		}

		/**
		 * One resolved value, as text.
		 *
		 * Booleans get words because `var_export()`-shaped output in a column
		 * headed "Value" reads as a bug, and an empty string gets a dash
		 * because an empty cell reads as a missing row.
		 *
		 * @param mixed $value A value out of attributes().
		 * @return string
		 */
		public static function readable( $value ): string {
			if ( is_bool( $value ) ) {
				return $value
					? __( 'Yes', 'store-locator-for-openstreetmap' )
					: __( 'No', 'store-locator-for-openstreetmap' );
			}

			if ( null === $value || '' === $value ) {
				return '—';
			}

			if ( is_float( $value ) ) {
				return rtrim( rtrim( sprintf( '%.7F', $value ), '0' ), '.' );
			}

			return is_scalar( $value ) ? (string) $value : '';
		}

		/**
		 * The request name of one field.
		 *
		 * @param string $key Attribute name.
		 * @return string
		 */
		public static function field_name( string $key ): string {
			return self::FIELD . '[' . $key . ']';
		}

		/**
		 * The id of one field.
		 *
		 * @param string $key Attribute name.
		 * @return string
		 */
		public static function field_id( string $key ): string {
			return 'slosm-att-' . str_replace( '_', '-', $key );
		}
	}
}
