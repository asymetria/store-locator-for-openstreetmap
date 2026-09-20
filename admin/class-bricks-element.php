<?php
/**
 * The Bricks Builder element, which is the shortcode wearing a panel.
 *
 * The long header this file used to open with — what was verified against a
 * real copy of Bricks, and where — is below the guard now rather than above
 * it. See the note beside that guard for why a comment had to move for a
 * reader that is not human.
 *
 * @package Store_Locator_For_OpenStreetMap
 */

namespace Asymetria\StoreLocator\Admin;

/*
 * The direct-access guard, as near the top of the file as PHP allows.
 *
 * Plugin Check's `missing_direct_file_access_protection` reported this file as
 * unprotected while the guard sat at line 224, under two hundred lines of
 * verification notes. The guard was there and worked: the check looks near the
 * beginning of a file and gave up long before reaching it. The reviewer who
 * reads a submission to the plugin directory runs that same tool, so a guard
 * the check cannot find is — for the purpose of getting this plugin listed —
 * a guard that is not there.
 *
 * Moving it *down* to sit directly under the namespace was tried first and
 * made it worse, at line 237. What the check wants is the top of the file, so
 * the notes went below it instead. They are a comment; a comment can be
 * anywhere, and `namespace` has to be the first statement, which makes the
 * line under it the earliest place a guard can stand.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Asymetria\StoreLocator\Assets;
use Asymetria\StoreLocator\Shortcode;

/*
 * The Bricks Builder element, which is the shortcode wearing a panel.
 *
 * WHAT WAS VERIFIED, AND WHERE
 * ============================
 * Everything this file asserts about Bricks was **read off a real copy** of
 * Bricks **2.4** — an unpacked tree, the directory that holds `style.css`,
 * `functions.php` and `includes/`. Every line number below is that copy's. No claim is from documentation, memory or
 * inference unless it says so in as many words. The six that matter:
 *
 * - **What an element is.** A class extending `\Bricks\Element`
 *   (`includes/elements/base.php` line 6, `abstract class Element`), declaring
 *   `$category`, `$name` and `$icon`, and overriding `get_label()`,
 *   `set_controls()` and `render()`. `Bricks\Elements::load_element()`
 *   (`includes/elements.php` lines 304-395) constructs the class, calls
 *   `load()`, and copies `name`, `icon`, `category`, `label`, `keywords`,
 *   `tag`, `controls`, `controlGroups`, `scripts`, `draggable` and the rest
 *   straight off the instance into the builder's element definition (lines
 *   336-360). `set_control_groups()` is optional — base.php line 260 declares
 *   it empty — and is deliberately not overridden here; see set_controls().
 *
 * - **How a plugin registers one.** `Bricks\Elements::register_element( $file,
 *   $name = '', $class = '' )` is public and static (`includes/elements.php`
 *   lines 253-284). It returns at once unless the file `is_readable()`, then
 *   `require_once`s it, then — only if the class name it was handed is empty or
 *   undeclared — guesses at `end( get_declared_classes() )`, and then, only if
 *   the element name is empty, constructs the class and reads
 *   `$instance->name`, `$instance->label` and `$instance->description` off it.
 *   `Plugin::register_bricks_element()` passes the class and no name, so the
 *   guess is never reached and the name is never written down twice.
 *
 * - **When.** `Bricks\Elements::__construct()` hooks `init_elements()` on
 *   `init` at the default priority (`includes/elements.php` lines 11-17), and
 *   `init_elements()` is what `require_once`s `includes/elements/base.php`
 *   (line 21). So `\Bricks\Element` does not exist until `init` priority 10 has
 *   run, and this plugin registers at `init` priority **11**. Anything earlier
 *   is a `require` of a file whose parent class does not exist, which is a
 *   fatal.
 *
 * - **Why every control says `rerender` out loud, and what that is really
 *   worth.** A control change re-renders the element **on the server by
 *   default**, and the flag is an opt-*out* rather than an opt-in. Read off the
 *   builder bundle's `rerender` computed property — `assets/js/main.min.js`,
 *   byte offset 2679068 — which for an ordinary element ends in a conjunction
 *   of four terms: the parent's flag, the control group's, the control's own,
 *   and the *absence* of a `css` key on the control, each of the first three
 *   compared against `false` rather than tested for truth. So a content
 *   control with no `css` key re-renders, `'rerender' => false` stops it, and a
 *   truthy flag short-circuits an earlier clause of the same property, which is
 *   how a control that *does* have `css` is forced to re-render (`base.php`
 *   line 953 uses it that way). Both spellings are real: 78 occurrences of
 *   `'rerender'` across `includes/elements/*.php`, including `=> true` on the
 *   built-in Shortcode element's textarea (`includes/elements/shortcode.php`
 *   line 21) and `=> false` on the custom attribute repeater's fields
 *   (`base.php` lines 1677 and 1682).
 *
 *   So writing `'rerender' => true` on all thirteen controls below is **not**
 *   what makes the map redraw; the default would already do that. It is what
 *   pins the behaviour against a control of this element later growing a `css`
 *   key, and against the default changing. That is a smaller claim than "the
 *   panel would move and the map would not", which is what this file used to
 *   say and is false.
 *
 * - **How a re-rendered map is brought back to life.** This is the one that
 *   decides whether the element works at all, and it is the Bricks answer to
 *   the failure Task 18 hit in the block editor. Every offset below is a byte
 *   offset into `assets/js/iframe.min.js` unless it says otherwise, because
 *   that is the bundle that runs inside the canvas and every step below runs
 *   there. `main.min.js` is the panel's half — it draws the controls and it is
 *   where a keystroke starts — and the two bundles share a great deal of code
 *   under different minified names, which is how an offset in one comes to
 *   look like an offset in the other. Each one below was checked in the file
 *   it is attributed to.
 *
 *   There are **two** Vue components that draw an element on the canvas, and
 *   telling them apart is the whole of understanding this. One renders the
 *   element's tag itself and ends at offset 1201674; it is for elements the
 *   builder can draw without asking the server. The other ends at 1246646 and
 *   renders *the node name of whatever the server sent*; it is the one that
 *   owns `getHTML()` and `setHTML()`, and it is this element's, because
 *   everything this element shows is markup PHP built.
 *
 *   In that second component, in order:
 *
 *   1. A control changes. The component's `$_state.forceRender` watcher
 *      (offset 1243131) decides the change is this element's and ends by
 *      calling `getHTML()`.
 *   2. `getHTML()` (1232371) wraps its whole body in a **100ms** timer and
 *      then fires the `bricks_render_element` request.
 *   3. The request's `success` handler (1235531) calls `setHTML()` with the
 *      response's html. What it does *not* do is run this element's scripts:
 *      it re-runs scripts only for a `template` element, and calls
 *      `bricksRunAllFunctions()` only for a `shortcode` element whose
 *      shortcode text mentions a Bricks template. Neither is this element.
 *   4. `setHTML()` (1229302) parses the response with `DOMParser`, takes the
 *      body's first element child, and assigns it to the component's
 *      `rootNode` (the assignment is at 1231104). **The node is a new
 *      object every time**, so the assignment is always a change.
 *   5. Which is what makes the `rootNode` **watcher** (1241366) the thing
 *      that matters. It moves the parsed node's children into the component's
 *      own root and then, after a chain of `$nextTick`s, calls
 *      `$_runElementScripts( name, root )` — at 1241549 on the linked-icon
 *      branch and 1242099 on the ordinary one. This is the generic trigger,
 *      it runs for every element this component draws, and it runs **after**
 *      the new markup is in the document.
 *   6. `$_runElementScripts` is Bricks' `runElementScripts` (853065), reached
 *      through the `$_`-prefixed global properties the app installs at
 *      1259360. It reads the element definition's `scripts` array — copied
 *      off the `$scripts` property below by `Elements::load_element()`,
 *      `elements.php` line 347 — waits a further **200ms**, and calls each
 *      name as a function on the canvas iframe's window. The window is
 *      resolved by a helper at 662186 that hands back `window` when it is
 *      already inside the iframe, and the definition is looked up by a helper
 *      at 790349 that indexes `bricksData.elements` by name.
 *
 *   So `window.slosmBricksInit()` is called roughly 200ms after the new node
 *   is in the canvas, and the node it is called about is a **new** one: a
 *   Leaflet map bound to the old node is not moved across. That is why the
 *   global below calls into `locator.js` rather than doing anything itself,
 *   and why what it calls sweeps rather than merely initialises.
 *
 *   **This corrects what this file used to say, which was wrong twice over.**
 *   It claimed the scripts run "from the `$nextTick` after the `render_element`
 *   request succeeds". There is such a handler — offset 717128 — but it
 *   belongs to the *component* rendering path, not to a plain element, and a
 *   reviewer reading only that one concluded the revival was a race between a
 *   fixed timer and the AJAX round trip. It is not: step 5 is driven by the
 *   `rootNode` assignment in step 4, so the ordering is guaranteed rather than
 *   raced. The per-control `reloadScripts` flag (`main.min.js` 2692110) fires
 *   *earlier* than any of this and is not part of the path either.
 *
 *   The observer `init_script()` installs is therefore **belt and braces
 *   rather than a fix for a live fault**, and it says so at more length there.
 *
 * - **Who calls `enqueue_scripts()`, which is not only the builder.** Three
 *   places, and the third is the one this file was wrong about until the
 *   mutation sweep's separating input was written down:
 *
 *   1. `Elements::load_element()`, on the `wp` hook, guarded by
 *      `bricks_is_builder_iframe()` (`elements.php` lines 392-393). The
 *      builder iframe's one chance to be handed anything.
 *   2. `Element::init()` (`base.php` lines 2955-2957), which
 *      `Ajax::render_element()` calls inside an output buffer on every builder
 *      re-render (`ajax.php` lines 1505-1507).
 *   3. `Element::init()` again, from `Frontend::render_element()`
 *      (`frontend.php` lines 770-772) — **the front end**, on every page that
 *      has this element on it.
 *
 *   Nothing in Bricks makes the method builder-only. `enqueue_scripts()` below
 *   gates itself, and says at length why.
 *
 * WHAT IS **NOT** VERIFIED
 * ========================
 * Nothing in this file has been run inside Bricks. The theme is on disk and
 * readable; the WordPress it belongs to was never started. So the element has
 * not been seen in the panel, no control has been seen to draw, and no
 * re-render has been seen to bring a map back.
 *
 * That is a different and much weaker claim than "verified", and the difference
 * is worth keeping sharp: reading `register_element()` tells you what it does
 * with the arguments it is given, and tells you nothing about whether the
 * builder then draws what this file describes. **Eight** checks only a browser
 * with Bricks in it can settle are in Task 26's list, added by this task, each
 * with the source line it is a claim about.
 *
 * The copy read is 2.4. The site this plugin's author actually runs Bricks on
 * is **1.12.4**, which is not on this machine and was not read. So nothing
 * here is a citation of 1.12.4, and the only thing said about it is the thing
 * that has to be said: **every line number and byte offset in this file is
 * 2.4's and none of them will be 1.12.4's.** Whether every mechanism named
 * here is present in 1.12.4 unchanged is a claim this file is not in a
 * position to make; it is on Task 26's list, to be settled by opening a
 * builder rather than by remembering.
 *
 * WHY THERE IS NO CONTROL LIST IN THIS FILE
 * =========================================
 * `Shortcode::raw_defaults()` owns the attribute names.
 * `Shortcode_Generator::controls()` holds the labels, the help text, the bounds
 * and the closed lists, and a case asserts its keys are `raw_defaults()`' keys
 * in order. A third list here would be the only one with no tripwire on it, so
 * there is no third list: `controls()` below is a **translation** of the
 * generator's, one control at a time, and the only thing this file knows that
 * the generator does not is how Bricks spells a select.
 *
 * That leaves exactly one gap, and it is named rather than hoped about: a
 * control *type* added to the generator that `TYPES` has no mapping for. It
 * degrades to a visible `info` control saying so, and a case asserts the
 * mapping is complete today.
 *
 * The generator is reachable from here because `Plugin::boot()` constructs it
 * with no `is_admin()` gate — and `controls()` is static anyway, so this file
 * needs no instance of it.
 *
 * WHAT DERIVING THE LIST COSTS, SAID OUT LOUD
 * -------------------------------------------
 * `Shortcode_Generator::controls()` is built afresh on **every render of this
 * element, including every front-end one**. `Element::load()` calls
 * `set_controls()` (`base.php` line 153), and `Frontend::render_element()`
 * constructs the element and calls `load()` on it (`frontend.php` lines
 * 766-767) before rendering anything — there is no "builder only" branch in
 * front of it. So a visitor loading a Bricks page with a locator on it pays
 * for thirteen control definitions, thirteen `__()` calls and thirteen
 * `esc_html()` calls that nothing will ever draw.
 *
 * Recorded rather than fixed, and deliberately: **Bricks does exactly the same
 * for its own eighty-odd elements**, every one of which builds its whole
 * control list on every front-end render of it — `base.php`'s
 * `set_controls_before()` alone is over a hundred entries, all of them
 * translated. Caching this element's thirteen would be an optimisation
 * measured against a cost that is already three orders of magnitude larger
 * and out of this plugin's hands, and it would put a cache between two lists
 * whose whole point is that they cannot drift.
 *
 * @package Store_Locator_For_OpenStreetMap
 */


/*
 * Two conditions, and the file is worth nothing without either.
 *
 * `\Bricks\Element` is the parent. A class declaration whose parent is
 * undeclared is a fatal, and this file is reachable through the autoloader as
 * well as through Bricks' own `require_once` — so it has to be able to be
 * loaded on a site with no Bricks and quietly declare nothing.
 *
 * The declaration is inside the `if` rather than after an early `return` on
 * purpose. PHP early-binds a class whose parent is already known at compile
 * time, which means the declaration would happen *before* a top-level `return`
 * ever ran; a conditional declaration is never early-bound, so this is the only
 * form that is conditional in both directions.
 *
 * The second condition is the guard every other class in this plugin carries,
 * for the reason Autoloader's docblock gives: a second copy of this file —
 * pasted into a theme, shipped inside another plugin — is a fatal that an early
 * return cannot prevent. Bricks itself `require_once`s this file, and this
 * plugin's autoloader may have loaded it already.
 */
if ( class_exists( '\\Bricks\\Element' ) && ! class_exists( __NAMESPACE__ . '\\Bricks_Element' ) ) {

	/**
	 * One locator, placed in Bricks, rendered by the shortcode.
	 */
	class Bricks_Element extends \Bricks\Element {

		/**
		 * The element's machine name, unique across every element on the site.
		 *
		 * Prefixed, because this is a global namespace shared with Bricks' own
		 * eighty-odd elements and with every other plugin's. A bare
		 * `store-locator` is exactly the kind of name two plugins pick.
		 *
		 * @var string
		 */
		public const NAME = 'slosm-store-locator';

		/**
		 * The global function Bricks calls to restart this element.
		 *
		 * Named here and nowhere else: `$scripts` below is what Bricks reads,
		 * and `init_script()` is what defines it. A name in one and not the
		 * other is a silent no-op — `runElementScripts()` checks
		 * `"function" == typeof n[t]` and simply does not call what is not
		 * there — so the two are one constant.
		 *
		 * @var string
		 */
		public const INIT_FUNCTION = 'slosmBricksInit';

		/**
		 * How the generator's control types are spelled in Bricks.
		 *
		 * Both sides are real vocabularies, and the map is the only place this
		 * file is allowed to know anything the generator does not. Bricks' side
		 * was counted in 2.4's `includes/elements/*.php`: `'type' => 'text'`
		 * 332 occurrences, `'type' => 'number'` 348, `'type' => 'select'` 301.
		 * All three are spelled the same on both sides, which is luck rather
		 * than design and is exactly why the map is written out.
		 *
		 * A type the generator grows that is missing from here is the one drift
		 * that deriving a control list cannot prevent by itself, which is why
		 * there is a case asserting this covers everything `controls()`
		 * produces — and why `control()` degrades visibly rather than quietly.
		 *
		 * @var array<string, string>
		 */
		public const TYPES = array(
			'number' => 'number',
			'text'   => 'text',
			'select' => 'select',
		);

		/**
		 * Which section of the builder's element panel this appears in.
		 *
		 * `general` is the built-in Map element's own category
		 * (`includes/elements/map.php` line 7), which makes it the closest
		 * precedent there is. Bricks 2.4 has eight, counted off the
		 * `public $category` line of every `includes/elements/*.php`: basic,
		 * filter, general, layout, media, query, single, wordpress.
		 *
		 * @var string
		 */
		public $category = 'general';

		/**
		 * The element's machine name.
		 *
		 * @var string
		 */
		public $name = self::NAME;

		/**
		 * The panel icon.
		 *
		 * A Themify class — Bricks bundles that icon font for its panel and
		 * every built-in element names one. `ti-location-pin` is the built-in
		 * Map element's (`includes/elements/map.php` line 9), which is the
		 * right picture and a class the font certainly has.
		 *
		 * @var string
		 */
		public $icon = 'ti-location-pin';

		/**
		 * The globals Bricks calls after every render of this element.
		 *
		 * The file's docblock traces what reads this, from the control change
		 * to the call, with the byte offset of each step.
		 *
		 * @var string[]
		 */
		public $scripts = array( self::INIT_FUNCTION );

		/**
		 * Whether the whole element can be dragged by its own surface.
		 *
		 * False, and copied from the built-in Map element
		 * (`includes/elements/map.php` line 11), which is the only other
		 * element in Bricks that puts a map on the canvas. A Leaflet map
		 * swallows pointer events to pan itself, so an element draggable by its
		 * own surface is one an editor cannot move and cannot pan either.
		 *
		 * Unverified in the sense that matters: this is Bricks' own answer to
		 * the problem copied across, not something observed in a builder. It is
		 * on Task 26's list.
		 *
		 * @var bool
		 */
		public $draggable = false;

		/**
		 * The element's name in the panel.
		 *
		 * `esc_html__()` rather than `__()`, for the reason `control()` gives
		 * about the control labels beside it, one level up and on the same
		 * evidence. `Elements::load_element()` copies `$instance->label` into
		 * the builder's element definition (`elements.php` line 341) — the
		 * constructor having set it from this method (`base.php` line 74) —
		 * and the panel's element list draws it as **html**:
		 * `assets/js/main.min.js` byte offset 1103235 binds the caption's
		 * `innerHTML` to a helper at 867553, which falls back to the element
		 * definition's `label` through the lookup at 807340. The builder's
		 * element-target picker does the same at 2460046.
		 *
		 * The string is the return of `__()`, so on a site with a language
		 * pack it is a string out of a `.mo` file the site owner may not have
		 * written. That is the whole argument, and it is the same one the
		 * control labels make.
		 *
		 * @return string
		 */
		public function get_label(): string {
			return esc_html__( 'Store Locator', 'store-locator-for-openstreetmap' );
		}

		/**
		 * What finds this element in the panel's search box.
		 *
		 * Not translated, and that is deliberate rather than an oversight: an
		 * editor searching a Bricks panel types what the thing is, and on a
		 * site in any language "map" and "locator" are as likely as the
		 * translated words. The label above is what is translated.
		 *
		 * @return string[]
		 */
		public function get_keywords(): array {
			return array( 'store', 'shop', 'locator', 'map', 'openstreetmap', 'leaflet', 'branch', 'dealer' );
		}

		/**
		 * The whole control panel, translated from the generator's.
		 *
		 * No control groups, and the omission is a decision. Bricks makes
		 * `set_control_groups()` optional (base.php line 260 declares it
		 * empty), and grouping thirteen controls would mean inventing a
		 * grouping — which is a fourth opinion about the shape of the attribute
		 * list, held here, asserted nowhere, and free to drift from the
		 * generator's form, which has no groups either. Thirteen controls in
		 * one flat Content tab is what the generator's own screen looks like.
		 *
		 * THE LOOP IS NOT A LONG WAY OF WRITING AN ASSIGNMENT
		 * ---------------------------------------------------
		 * `$this->controls` is **already full** when this is called. `load()`
		 * calls `set_controls_before()`, then this, then
		 * `set_controls_after()` (base.php lines 152-154), and the first of
		 * those writes something over a hundred entries — spacing, sizing,
		 * typography, background, borders, position — into the property
		 * (base.php lines 337-1686). So `$this->controls = self::controls();`
		 * would compile, run, produce a working panel with thirteen controls
		 * in it, and **delete every style control the element has**.
		 *
		 * Written down because for a while nothing could catch it:
		 * `tests/bricks-stub.php` had empty bodies for both hooks, so there
		 * was nothing to destroy and the two spellings were the same
		 * function. Each hook contributes one sentinel now, and a case
		 * asserts the whole key list — Bricks' own first, these in the
		 * shortcode's order, Bricks' own last.
		 *
		 * @return void
		 */
		public function set_controls(): void {
			foreach ( self::controls() as $key => $control ) {
				$this->controls[ $key ] = $control;
			}
		}

		/**
		 * The controls, as data, without needing Bricks to collect them.
		 *
		 * Separate from `set_controls()` because `set_controls()` can only
		 * write to a property, and a property is a poor thing to assert the
		 * derivation against.
		 *
		 * @return array<string, array<string, mixed>>
		 */
		public static function controls(): array {
			$controls = array();

			foreach ( Shortcode_Generator::controls() as $key => $control ) {
				$controls[ $key ] = self::control( $control );
			}

			return $controls;
		}

		/**
		 * One of the generator's controls, spelled the way Bricks spells it.
		 *
		 * Public because the `info` branch is unreachable through `controls()`
		 * — every type the generator produces today is in `TYPES` — so a mutant
		 * deleting it would survive a whole sweep with nothing to catch it.
		 * `Shortcode_Generator::choices()` is public for the same reason and
		 * says so at more length.
		 *
		 * EVERY HUMAN-READABLE STRING IS ESCAPED ON THE WAY IN
		 * ----------------------------------------------------
		 * The label, the description, the placeholder and every option's label,
		 * and the reason is Bricks' own: the builder writes a control's label
		 * and its description into the panel as **html rather than as text**.
		 * Both were read off `assets/js/main.min.js` of Bricks 2.4. The label
		 * is bound to the `innerHTML` of the control's caption span at byte
		 * offset 2703545; the description is bound to the `innerHTML` of a
		 * `description` div at 2705955, and the value it is bound to is a
		 * computed property at 2678389 that answers the control's
		 * `description` key or, failing that, its `desc` — which is the key
		 * this method writes.
		 *
		 * (An earlier version of this paragraph cited two other offsets for
		 * the same conclusion. They exist, and they are the wrong ones: one is
		 * the builder's element-target picker and the other is a notification
		 * banner. The conclusion was right and the evidence was not, which is
		 * worse than being wrong out loud.)
		 *
		 * Bricks' own built-in elements pass `esc_html__()` rather than `__()`
		 * into all four of these fields — `includes/elements/alert.php` lines
		 * 22-35 does it for a select's label, its options and its placeholder
		 * at once.
		 *
		 * The generator does not escape, and is right not to: it prints its own
		 * help text through `esc_html()` at the point of printing
		 * (`admin/class-shortcode-generator.php` line 769), so the value it holds
		 * is deliberately raw. This method is the boundary, so this is where the
		 * escaping belongs.
		 *
		 * What is being defended against is a `.mo` file in the site's languages
		 * directory, since every one of these strings is the return of `__()` and
		 * therefore content from a file the site owner may not have written. It is
		 * the same argument the `info` branch below already made for its label,
		 * applied to the rest of them rather than to one.
		 *
		 * What is dropped on the way across, and why:
		 *
		 * - `suffix`. The generator prints "px" beside the height field; Bricks
		 *   number controls have no such affordance, and the height's own help
		 *   text already says "In pixels." Adding a unit picker instead would
		 *   be inventing a control the shortcode has no attribute for.
		 * - The `''` key of a select's choices. The generator's empty option is
		 *   "say nothing and let the site decide"; Bricks spells that as
		 *   nothing selected, so the label becomes the control's `placeholder`
		 *   and the option itself goes. Read from `includes/elements/alert.php`
		 *   lines 22-35, which is `'type' => 'select'` with `options`,
		 *   `placeholder` and `inline` together.
		 *
		 * @param array $control One entry of Shortcode_Generator::controls().
		 * @return array<string, mixed>
		 */
		public static function control( array $control ): array {
			$type  = (string) ( $control['type'] ?? '' );
			$label = (string) ( $control['label'] ?? '' );

			if ( ! isset( self::TYPES[ $type ] ) ) {
				/*
				 * A control the generator grew and this file cannot spell.
				 * Visible, because the alternative is a shortcode attribute an
				 * editor can never set and no sign anywhere that it exists.
				 * esc_html() on a label that came from __(): a translation file
				 * is content, and a .mo from a site's languages directory is
				 * not a thing to print into a builder panel unescaped.
				 */
				return array(
					'tab'     => 'content',
					'type'    => 'info',
					'content' => sprintf(
						/* translators: %s: the name of a setting. */
						esc_html__( '%s cannot be set here yet. Use the shortcode.', 'store-locator-for-openstreetmap' ),
						esc_html( $label )
					),
				);
			}

			$bricks = array(
				'tab'      => 'content',
				'type'     => self::TYPES[ $type ],
				'label'    => esc_html( $label ),
				'rerender' => true,
			);

			if ( isset( $control['help'] ) ) {
				$bricks['desc'] = esc_html( (string) $control['help'] );
			}

			if ( 'number' === $type ) {
				foreach ( array( 'min', 'max', 'step' ) as $bound ) {
					if ( ! isset( $control[ $bound ] ) ) {
						continue;
					}

					// The generator keeps its bounds as strings, because they
					// go straight into an html attribute. Bricks' own number
					// controls are written with numbers, so numeric ones cross
					// as numbers -- and `step => 'any'`, which is a legal step
					// and not a number, crosses as itself. + 0 rather than a
					// cast, so an integer bound stays an integer and a
					// fractional one stays a float.
					$bricks[ $bound ] = is_numeric( $control[ $bound ] )
						? $control[ $bound ] + 0
						: $control[ $bound ];
				}
			}

			if ( 'select' === $type ) {
				$options = $control['choices'] ?? array();

				if ( isset( $options[''] ) ) {
					$bricks['placeholder'] = esc_html( (string) $options[''] );

					unset( $options[''] );
				}

				$labelled = array();

				foreach ( $options as $value => $option ) {
					// The key is the value the shortcode will be given and comes
					// from a closed list -- Geo::UNITS, Settings::CLUSTER_CHOICES --
					// so it is not a string anybody can write. The label beside it
					// is, and is escaped with the rest.
					$labelled[ $value ] = esc_html( (string) $option );
				}

				$bricks['options'] = $labelled;
				$bricks['inline']  = true;
			}

			return $bricks;
		}

		/**
		 * Puts this plugin's front-end assets inside the builder iframe.
		 *
		 * WHY THE FIRST LINE IS A GATE, WHICH IT DID NOT USED TO BE
		 * --------------------------------------------------------
		 * Bricks calls this method from three places, not one, and the file's
		 * own docblock lists them with line numbers. The one that matters here
		 * is the third: `Frontend::render_element()` calls `Element::init()`
		 * (`frontend.php` lines 770-772) and `init()` calls `enqueue_scripts()`
		 * (`base.php` lines 2955-2957) — **on the front end**, on every page
		 * with this element on it.
		 *
		 * This method was written believing the opposite, on the strength of
		 * the `bricks_is_builder_iframe()` gate in `Elements::load_element()`
		 * (`elements.php` lines 392-393), which is only one of the three call
		 * sites. Ungated, the body below hands every front-end Bricks page the
		 * marker-cluster bundle whether or not its locator wants it — silently
		 * overruling the per-locator decision `Shortcode::render()` makes from
		 * a count, and undoing Task 12's conditional-loading contract for
		 * exactly the pages this plugin is meant to look best on.
		 *
		 * `$this->is_frontend` is the separating input, and it is Bricks' own:
		 * `base.php` line 77 sets it from `$element['is_frontend']` when the
		 * caller passed one and from `bricks_is_frontend()` when it did not.
		 * That makes it **true** on a real page and **false** for all three
		 * builder paths — `load_element()` constructs with no argument at all
		 * (`elements.php` line 313) and `Ajax::render_element()` sets it to
		 * `false` outright (`ajax.php` line 1494).
		 *
		 * WHY THE BUILDER NEEDS THIS AT ALL
		 * ---------------------------------
		 * `Shortcode::render()` enqueues from inside itself, which is enough on
		 * the front end and is left to do its job there. In the builder it is
		 * not enough. The iframe loads once; every edit after that re-renders
		 * the element through a `render_element` call whose response is
		 * `[ 'html' => … ]` and nothing else (`ajax.php` lines 1528-1530), into
		 * a request whose footer is never printed. A `wp_enqueue_script()`
		 * during that render adds a handle to a queue nobody reads. Whatever
		 * the iframe is going to have, it has to have on the first load.
		 *
		 * Which is also why the cluster bundle is enqueued unconditionally
		 * here. `Shortcode::render()` decides per locator whether to load it,
		 * from a count; in the iframe that decision happens during a re-render,
		 * far too late to add anything to the page. So the builder carries
		 * roughly thirty kilobytes it may not use and the front end carries it
		 * only when it does — an editing context paying for an editing
		 * convenience, which is the trade only as long as the gate above holds.
		 *
		 * `register()` is called first, and it is a no-op on any real site:
		 * `Plugin::boot()` hooks it on `init`, and this runs on `wp`.
		 * `wp_register_script()` on a known handle returns false and changes
		 * nothing. It is here because of what happens if that ordering is ever
		 * untrue — `WP_Dependencies::add_data()` returns false for a handle it
		 * does not know, so the inline script below would be discarded in
		 * silence, and the symptom would be a builder whose map goes grey after
		 * the first edit with nothing in the console to say why.
		 *
		 * @return void
		 */
		public function enqueue_scripts(): void {
			if ( $this->is_frontend ) {
				return;
			}

			$assets = new Assets();

			$assets->register();
			$assets->enqueue();
			$assets->enqueue_cluster();

			wp_add_inline_script( Assets::SCRIPT_LOCATOR, self::init_script() );
		}

		/**
		 * The global Bricks calls after a re-render, and the observer beside it.
		 *
		 * Two halves, and only the first is Bricks' business.
		 *
		 * THE GLOBAL
		 * ----------
		 * It is attached to the locator handle rather than printed into
		 * `locator.js`, because that file's own docblock says `window.SLOSM` is
		 * its whole surface and a case in tests/js/locator.test.js asserts it.
		 * A second bare global on every front-end page, for the benefit of one
		 * builder, is not a trade worth making.
		 *
		 * It is guarded, because Bricks calls it 200ms after a render and a
		 * script that failed to load is exactly the case where something would
		 * otherwise throw into the builder's own console.
		 *
		 * And it calls `sweep()` rather than `init()` or `initAll()`. A
		 * re-render does two things at once and both have to be answered:
		 * the container Bricks just parsed is one `locator.js` has never seen,
		 * and the container it replaced is a detached node with a live
		 * `L.Map` on it, still holding the `resize` listener Leaflet put on
		 * `window`. `initAll()` answers only the first, which is what this
		 * used to do, and an editing session's worth of edits left an editing
		 * session's worth of live maps behind. `sweep()` is `forget()` then
		 * `initAll()`, in that order, and `locator.js` says why.
		 *
		 * THE OBSERVER, AND WHY IT IS BRACES AND NOT THE BELT
		 * ---------------------------------------------------
		 * A `MutationObserver` on the canvas document that does the same
		 * sweep, coalesced to at most one pass per animation frame.
		 *
		 * It exists because the whole revival depends on Bricks continuing to
		 * call the `scripts` array for a server-rendered element, and this
		 * plugin's evidence for that is a **read** of the 2.4 bundle rather
		 * than an observation — the file's docblock traces it step by step,
		 * which is the most that can honestly be said. If a Bricks version
		 * changes that path, the observer notices the same DOM change from
		 * the other side and the map still comes back. Both triggers reach
		 * the same idempotent `sweep()`, so a re-render that fires both costs
		 * one extra `querySelectorAll` and nothing else.
		 *
		 * What it is **not** is a fix for a race. An earlier review traced the
		 * plain-element path through the wrong Vue component, concluded the
		 * scripts ran on a fixed timer started before the request, and
		 * inferred that any round trip over 100ms would leave the map dead.
		 * That is not what happens: the call is chained off the assignment of
		 * the new node, not off a timer. The file's docblock has the offsets.
		 *
		 * Four constraints, each one in the code below:
		 *
		 * - **Builder only.** Not by a runtime check but by construction:
		 *   `enqueue_scripts()` above returns before this on the front end, so
		 *   this string is never printed on a page a visitor sees.
		 * - **No busy loop.** The callback does nothing but set a flag and ask
		 *   for one animation frame; every mutation until that frame runs is
		 *   free. A hidden tab runs no frames, which is the right answer: a
		 *   canvas nobody is looking at has no map to bring back.
		 * - **Disconnected.** On `pagehide`, which is the event the canvas
		 *   iframe gets when the builder navigates it.
		 * - **Absent rather than broken** where the browser cannot do it.
		 *   Both APIs are checked, and a browser missing either simply leaves
		 *   `$scripts` to do the work alone.
		 *
		 * @return string
		 */
		private static function init_script(): string {
			return 'window.' . self::INIT_FUNCTION . ' = function () {'
				. ' if ( window.SLOSM && typeof window.SLOSM.sweep === "function" ) { window.SLOSM.sweep(); }'
				. ' };'
				. ' ( function ( d ) {'
				. ' if ( typeof window.MutationObserver !== "function" ) { return; }'
				. ' if ( typeof window.requestAnimationFrame !== "function" ) { return; }'
				. ' var root = d && d.documentElement;'
				. ' if ( ! root ) { return; }'
				. ' var queued = false;'
				. ' var observer = new window.MutationObserver( function () {'
				. ' if ( queued ) { return; }'
				. ' queued = true;'
				. ' window.requestAnimationFrame( function () { queued = false; window.' . self::INIT_FUNCTION . '(); } );'
				. ' } );'
				. ' observer.observe( root, { childList: true, subtree: true } );'
				. ' window.addEventListener( "pagehide", function () { observer.disconnect(); } );'
				. ' } )( window.document );';
		}

		/**
		 * What the builder saved, as shortcode attributes.
		 *
		 * The gate between a builder and a sanitiser, and it is written as a
		 * walk over `Shortcode::raw_defaults()` rather than over
		 * `$this->settings` on purpose. Bricks saves a great deal more than the
		 * controls an element declares — `_cssClasses`, `_margin`,
		 * `_typography`, `_attributes`, and whatever the next version adds —
		 * and a walk over what was saved would hand all of it to
		 * `Shortcode::attributes()`. `shortcode_atts()` would drop the extras
		 * anyway, but "something downstream will catch it" is not a reason to
		 * pass it on, and this way the list of things that can reach the
		 * shortcode is the shortcode's own list by construction.
		 *
		 * Three transformations, in order:
		 *
		 * - A boolean becomes `yes` or `no`. Bricks stores a `checkbox` control
		 *   as a real boolean, and the three tri-state controls here are selects
		 *   today, so nothing reaches this branch through the panel yet.
		 * - Anything that is not a scalar is dropped entirely. A repeater
		 *   control, a Bricks dynamic-data structure, a corrupted meta row.
		 * - An empty string is dropped. That is how a builder spells "say
		 *   nothing", and it has to arrive at the shortcode as an absent
		 *   attribute so that `attributes()` falls back to the site setting.
		 *
		 * HOW MUCH OF THAT IS LOAD-BEARING, MEASURED RATHER THAN ASSUMED
		 * -------------------------------------------------------------
		 * Less than it looks, and the mutation sweep is what said so. Every
		 * sanitiser in `Shortcode` is already total: `text()`, `number()`,
		 * `units()`, `cluster()` and `category()` all open with the same
		 * `is_bool( $value ) || ! is_scalar( $value )` refusal, and `boolean()`
		 * answers a real `true` with `true`. So for twelve of the thirteen
		 * attributes, handing the whole of `$this->settings` to the shortcode
		 * would produce byte-identical markup, and a mutant that did exactly
		 * that survived a whole sweep.
		 *
		 * `cluster` is the thirteenth and the one that separates them.
		 * `Shortcode::cluster()` answers a boolean with the *site setting*,
		 * because the words it is looking for are `yes` and `no` and a checkbox
		 * produces neither — so a builder checkbox saying "always cluster"
		 * becomes "whatever the site says" without the mapping above.
		 *
		 * The other two transformations are therefore depth rather than the
		 * only line of defence, and they stay on that footing: the list of what
		 * can reach the shortcode from a builder is worth having written down
		 * whether or not anything downstream currently needs it, and the next
		 * attribute added may well have a sanitiser that is not total. That
		 * survival was measured with a mutation sweep, not assumed.
		 *
		 * Public so a case can read it without parsing markup.
		 *
		 * @return array<string, string>
		 */
		public function attributes_for_shortcode(): array {
			$atts     = array();
			$settings = is_array( $this->settings ) ? $this->settings : array();

			foreach ( array_keys( Shortcode::raw_defaults() ) as $key ) {
				if ( ! array_key_exists( $key, $settings ) ) {
					continue;
				}

				$value = $settings[ $key ];

				if ( is_bool( $value ) ) {
					$value = $value ? 'yes' : 'no';
				}

				if ( ! is_scalar( $value ) ) {
					continue;
				}

				$value = (string) $value;

				if ( '' === $value ) {
					continue;
				}

				$atts[ $key ] = $value;
			}

			return $atts;
		}

		/**
		 * Prints the locator.
		 *
		 * Bricks elements echo rather than return — `base.php` line 2872
		 * declares `render()` with no return value, every built-in element
		 * echoes, and both callers wrap the call in `ob_start()`
		 * (`frontend.php` lines 770-772, `ajax.php` lines 1503-1505) — which
		 * is the opposite of `Shortcode::render()`, whose own
		 * docblock explains at length why a shortcode callback must return. So
		 * this method is the adapter between the two conventions and contains
		 * no markup decisions of its own.
		 *
		 * The wrapper is a `div` whose attributes come from Bricks' own
		 * `render_attributes( '_root' )`, which is the call the built-in
		 * Shortcode element makes (`includes/elements/shortcode.php` line
		 * 106; it spells the interpolation differently and the effect is the
		 * same). It is not decoration: it is what carries the element id,
		 * the CSS classes an editor typed into the panel, and everything
		 * Bricks' own styling controls generate selectors against. Without it
		 * the element is unstyleable from the builder.
		 *
		 * A `div` rather than `$this->tag`, for the same reason the built-in
		 * one hardcodes it: `get_tag()` is not overridden, so the tag is always
		 * `div`, and reading a property to print a constant would suggest an
		 * option that does not exist.
		 *
		 * No escaping here, and that is a statement rather than an omission.
		 * `render_attributes()` is Bricks' own, and escapes its own output.
		 * `Shortcode::render()` returns markup this plugin built, with
		 * `esc_attr()` on every value that came from outside and a json encode
		 * with `JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT`
		 * on the config. Escaping it again here would double-encode a page
		 * that is already correct.
		 *
		 * A new `Shortcode` rather than the one `Plugin::boot()` built, because
		 * Bricks constructs elements itself and there is nowhere to inject one.
		 * It costs an `Assets` object; both enqueue by handle, and
		 * `WP_Dependencies::enqueue()` ignores a handle already in the queue,
		 * so two locators on one page still cost what one does.
		 *
		 * @return void
		 */
		public function render(): void {
			$shortcode = new Shortcode();

			/*
			 * Built first, echoed second, and that is about the annotation
			 * rather than about style. `phpcs:ignore` covers the *next line*
			 * only, so on a three-line echo it excused the first line and left
			 * the second — the one naming `$shortcode` — reported. Plugin
			 * Check said so, and it was right: the annotation was in the file
			 * but not over the thing it was written for.
			 */
			$markup = '<div ' . $this->render_attributes( '_root' ) . '>'
				. $shortcode->render( $this->attributes_for_shortcode() )
				. '</div>';

			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- both halves are escaped by what produced them; the docblock above says which and how.
			echo $markup;
		}
	}
}
