<?php
/**
 * A stand-in for the Bricks base class this plugin's element extends.
 *
 * WHERE THE SHAPE CAME FROM
 * =========================
 * Bricks is a commercial theme and is not vendored here. Every declaration in
 * this file was read off a real copy of **Bricks 2.4**, unpacked, and each is
 * annotated with the file, the line and the member it stands for. Nothing
 * below is guessed. What is *omitted* is most of the real base class: it is
 * about four thousand lines, and an element that only declares controls and
 * prints a string touches almost none of it.
 *
 * WHAT A STUB CAN AND CANNOT SAY
 * ==============================
 * Cases written against this file prove the plugin's element has the members
 * Bricks reads, in the types Bricks reads them as, and that its own logic does
 * what it claims. They prove nothing at all about Bricks: not that the element
 * appears in the panel, not that a control draws, not that a re-render brings
 * the map back. Those need a browser with Bricks in it and are Task 26's.
 *
 * THE ONE PLACE THIS DELIBERATELY DIFFERS
 * =======================================
 * `render_attributes()` answers a fixed, readable string rather than
 * reproducing Bricks' real attribute builder. A test asserting the real one's
 * output would be asserting Bricks' behaviour through a copy of it, which is
 * the worst of both: it would break when Bricks changed and prove nothing when
 * it did not. What the cases need from it is that the element **calls** it and
 * puts the result on its root, and a marker string says that better than a
 * reproduction would.
 *
 * NOTHING IN THIS FILE MAY DEPEND ON THE REST OF THE SUITE. Two cases load it
 * in a bare child process, with no bootstrap and no WordPress stubs, to watch
 * the element file declare a class and then watch it decline to.
 *
 * @package Store_Locator_For_OpenStreetMap
 */

namespace Bricks;

if ( ! class_exists( '\\Bricks\\Element' ) ) {

	/**
	 * The abstract class every Bricks element extends.
	 *
	 * Read from `includes/elements/base.php`. The property list is that file's
	 * lines 6-69 with everything this plugin does not touch left out; the
	 * methods are the ones `Bricks\Elements::load_element()` and
	 * `Bricks\Frontend` call on an instance.
	 */
	abstract class Element {

		/**
		 * The one control this stub's `set_controls_before()` contributes.
		 *
		 * A **sentinel**, not a reproduction. The real `set_controls_before()`
		 * fills `$this->controls` with something over a hundred entries —
		 * layout, spacing, sizing, typography, background, borders — before
		 * an element's own `set_controls()` is reached
		 * (`includes/elements/base.php` lines 337-1686 of Bricks 2.4). This
		 * file will not copy that list: it is somebody else's product, it
		 * changes every release, and a case asserting it would be asserting
		 * Bricks rather than this plugin.
		 *
		 * What the list's *existence* means, on the other hand, is
		 * load-bearing and used to be invisible here. `set_controls()` is
		 * handed a `$this->controls` that is **already populated**, so an
		 * element that assigns to the property rather than adding to it
		 * deletes every style control it has. With both hooks empty, that
		 * mutation was indistinguishable from the correct code and the whole
		 * suite stayed green.
		 *
		 * `_content` is a real key of the real hook — base.php line 339, the
		 * first thing it writes — so a case naming it is naming something
		 * that is really there rather than a fiction of this file's.
		 *
		 * @var string
		 */
		public const STUB_CONTROL_BEFORE = '_content';

		/**
		 * The one control this stub's `set_controls_after()` contributes.
		 *
		 * The same sentinel argument as the constant above, on the other
		 * hook. `_animation` is a real key of the real
		 * `set_controls_after()` — base.php line 1716 — and the hook runs
		 * *after* an element's own `set_controls()`, which is why the two
		 * sentinels catch different faults: an element that overwrites the
		 * property destroys `_content` and leaves `_animation` untouched.
		 *
		 * @var string
		 */
		public const STUB_CONTROL_AFTER = '_animation';

		/**
		 * Whatever Bricks saved for this element; the constructor's argument.
		 *
		 * @var array|null
		 */
		public $element;

		/**
		 * Which section of the builder's element panel this appears in.
		 *
		 * @var string
		 */
		public $category;

		/**
		 * The element's machine name, unique across the site.
		 *
		 * @var string
		 */
		public $name;

		/**
		 * Filled from get_label() by the constructor.
		 *
		 * @var string
		 */
		public $label;

		/**
		 * Filled from get_keywords() by the constructor.
		 *
		 * @var array
		 */
		public $keywords;

		/**
		 * A themify icon class; the panel draws it beside the label.
		 *
		 * @var string
		 */
		public $icon;

		/**
		 * Filled by load(); what the builder's panel is built from.
		 *
		 * @var array
		 */
		public $controls;

		/**
		 * Filled by load(); the collapsible sections the controls sit in.
		 *
		 * @var array
		 */
		public $control_groups;

		/**
		 * Global JavaScript function names Bricks calls to (re)start this
		 * element.
		 *
		 * @var array
		 */
		public $scripts = array();

		/**
		 * Whether the whole element can be dragged in the builder canvas.
		 *
		 * @var bool
		 */
		public $draggable = true;

		/**
		 * The element's id in the page's Bricks data.
		 *
		 * @var string
		 */
		public $id;

		/**
		 * What the builder saved for this element's controls.
		 *
		 * @var array
		 */
		public $settings;

		/**
		 * The root tag; 'div' unless an element says otherwise.
		 *
		 * @var string
		 */
		public $tag = 'div';

		/**
		 * False inside the builder, true on a rendered page.
		 *
		 * The real one is a single line of `base.php` — line 77 — which reads
		 * `is_frontend` out of the constructor's argument when the caller
		 * passed one and falls back to Bricks' own `bricks_is_frontend()`
		 * when it did not.
		 *
		 * The constructor below is that line with the fallback spelled `false`
		 * rather than calling `bricks_is_frontend()`, which is Bricks' own and
		 * not modelled here. `false` is the right constant to stand in for it,
		 * because the two places that construct an element **without** passing
		 * `is_frontend` and then call `enqueue_scripts()` are both inside the
		 * builder: `Elements::load_element()`, which constructs with no
		 * argument at all (`elements.php` line 313), and `Ajax::render_element()`,
		 * which sets it to `false` outright (`ajax.php` line 1494).
		 *
		 * A case that wants the front end passes `'is_frontend' => true`, which
		 * is what `Frontend::render_element()` produces by falling through to
		 * `bricks_is_frontend()` on a page nobody is editing.
		 *
		 * @var bool
		 */
		public $is_frontend = false;

		/**
		 * Builds an element, the way Bricks builds one.
		 *
		 * The real constructor does a great deal more — component instances,
		 * nestable blueprints, theme styles. None of it is reachable from an
		 * element that declares no children and no theme-style key.
		 *
		 * @param array|null $element Element data: id, name, settings.
		 */
		public function __construct( $element = null ) {
			$this->element     = $element;
			$this->label       = $this->get_label();
			$this->keywords    = $this->get_keywords();
			$this->is_frontend = isset( $element['is_frontend'] ) ? (bool) $element['is_frontend'] : false;
			$this->id          = ! empty( $element['id'] ) ? (string) $element['id'] : 'stubid';
			$this->settings    = ! empty( $element['settings'] ) ? $element['settings'] : array();
		}

		/**
		 * Collects the control list, the way Bricks collects it.
		 *
		 * `Bricks\Elements::load_element()` calls this and then reads
		 * `$element->controls`; it never reads a return value from
		 * `set_controls()`. The three calls and their order are base.php's.
		 *
		 * @return void
		 */
		public function load() {
			$this->control_groups = array();
			$this->set_control_groups();

			$this->controls = array();
			$this->set_controls_before();
			$this->set_controls();
			$this->set_controls_after();
		}

		/**
		 * The element's human name.
		 *
		 * @return string
		 */
		public function get_label() {
			return '';
		}

		/**
		 * Words that find this element in the panel's search box.
		 *
		 * @return array
		 */
		public function get_keywords() {
			return array();
		}

		/**
		 * Declares the collapsible sections. Optional; empty in base.php.
		 *
		 * @return void
		 */
		public function set_control_groups() {}

		/**
		 * Declares the controls. Optional; empty in base.php.
		 *
		 * @return void
		 */
		public function set_controls() {}

		/**
		 * Bricks' own controls that come before an element's.
		 *
		 * One entry rather than the real hook's hundred-odd, and rather than
		 * the empty body this used to have. `STUB_CONTROL_BEFORE` says at
		 * length why one and not none, and why one and not all of them.
		 *
		 * @return void
		 */
		public function set_controls_before() {
			$this->controls[ self::STUB_CONTROL_BEFORE ] = array(
				'tab'    => 'content',
				'label'  => 'Content',
				'type'   => 'text',
				'hidden' => true,
			);
		}

		/**
		 * Bricks' own controls that come after an element's.
		 *
		 * The second sentinel, for the reason `STUB_CONTROL_AFTER` gives:
		 * this hook runs after the element has had its say, so it survives a
		 * fault that destroys the one before it.
		 *
		 * @return void
		 */
		public function set_controls_after() {
			$this->controls[ self::STUB_CONTROL_AFTER ] = array(
				'tab'   => 'style',
				'label' => 'Entry animation',
				'type'  => 'select',
			);
		}

		/**
		 * Where an element puts its wp_enqueue_script() calls.
		 *
		 * Called from **three** places in Bricks 2.4, and the third is the one
		 * an element is easy to get wrong about:
		 *
		 * - `Elements::load_element()`, on the `wp` hook, and only when
		 *   `bricks_is_builder_iframe()` — `elements.php` lines 392-393. This
		 *   is the builder iframe's one chance to be given anything.
		 * - `Element::init()`, unconditionally except for `_hideElementFrontend`
		 *   — `base.php` lines 2955-2957 — which `Ajax::render_element()` calls
		 *   inside an output buffer on every builder re-render (`ajax.php`
		 *   lines 1505-1507).
		 * - and `Element::init()` again from `Frontend::render_element()`,
		 *   `frontend.php` lines 770-772, which is **the front end**, on every
		 *   page that has the element on it.
		 *
		 * @return void
		 */
		public function enqueue_scripts() {}

		/**
		 * Prints the element. Bricks echoes; it does not return.
		 *
		 * @return void
		 */
		public function render() {}

		/**
		 * The attributes Bricks puts on an element's root.
		 *
		 * A marker rather than a reproduction; the file's docblock says why.
		 *
		 * @param string $key Which attribute set; '_root' is the element's own.
		 * @return string
		 */
		public function render_attributes( $key = '_root' ) {
			return 'data-slosm-' . str_replace( '_', '', $key ) . '="' . $this->id . '"';
		}

		/**
		 * Draws the grey "nothing to show" box the builder uses.
		 *
		 * `final` on the real one, and it prints rather than returns.
		 *
		 * @param array  $data Title, icon class, inline styles.
		 * @param string $type Placeholder flavour.
		 * @return void
		 */
		final public function render_element_placeholder( $data = array(), $type = 'info' ) {
			if ( $this->is_frontend ) {
				return;
			}

			echo '<div class="bricks-element-placeholder">'
				. htmlspecialchars( (string) ( $data['title'] ?? '' ), ENT_QUOTES )
				. '</div>';
		}
	}
}
