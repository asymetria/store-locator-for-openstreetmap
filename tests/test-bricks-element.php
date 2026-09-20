<?php
/**
 * The Bricks Builder element, and the two things it must never become.
 *
 * WHAT THIS FILE CAN AND CANNOT MEASURE
 * ====================================
 * Bricks is a commercial theme. It is not a dependency of this plugin, it is
 * not in this repository, and no case below runs a line of it. What the cases
 * run against is a **stub** of `\Bricks\Element` in `tests/bricks-stub.php`,
 * whose shape was read off the real thing — Bricks 2.4, at
 * `the unpacked Bricks tree, includes/elements/base.php`. Every
 * property and method the stub declares is one the real base class declares,
 * and the file's docblock says where each was read.
 *
 * So these cases prove that this plugin's element **fits the shape** Bricks
 * asks for and produces the markup and the control list it is supposed to.
 * They cannot prove Bricks accepts it, draws it, or re-runs its script, because
 * proving that needs a browser with Bricks in it. Those checks are in Task 26.
 *
 * THE TWO THINGS IT MUST NEVER BECOME
 * ===================================
 * 1. **A third copy of the attribute list.** `Shortcode::raw_defaults()` owns
 *    the names; `Shortcode_Generator::controls()` is asserted against it, keys
 *    and order. This element derives from the generator, so it cannot hold an
 *    opinion of its own — and the cases below pin that derivation rather than
 *    trusting it, because a derivation that quietly drops a key is exactly as
 *    bad as a hand-written list that is missing one.
 * 2. **A second rendering path.** The element renders through
 *    `Shortcode::render()` and nothing else, so a locator placed in Bricks and
 *    a locator placed with a shortcode are the same object answering twice.
 *
 * @package Store_Locator_For_OpenStreetMap
 */

use Asymetria\StoreLocator\Assets;
use Asymetria\StoreLocator\Autoloader;
use Asymetria\StoreLocator\Shortcode;
use Asymetria\StoreLocator\Plugin;
use Asymetria\StoreLocator\Admin\Bricks_Element;
use Asymetria\StoreLocator\Admin\Shortcode_Generator;

require_once dirname( __DIR__ ) . '/includes/class-autoloader.php';
require_once dirname( __DIR__ ) . '/includes/class-geo.php';
require_once dirname( __DIR__ ) . '/includes/class-store.php';
require_once dirname( __DIR__ ) . '/includes/class-post-type.php';
require_once dirname( __DIR__ ) . '/includes/class-store-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-geocoder.php';
require_once dirname( __DIR__ ) . '/includes/class-rest-controller.php';
require_once dirname( __DIR__ ) . '/includes/class-assets.php';
require_once dirname( __DIR__ ) . '/includes/class-settings.php';
require_once dirname( __DIR__ ) . '/includes/class-shortcode.php';
require_once dirname( __DIR__ ) . '/includes/class-plugin.php';
require_once dirname( __DIR__ ) . '/admin/class-admin.php';
require_once dirname( __DIR__ ) . '/admin/class-locations-list.php';
require_once dirname( __DIR__ ) . '/admin/class-bulk-geocode.php';
require_once dirname( __DIR__ ) . '/admin/class-settings-screen.php';
require_once dirname( __DIR__ ) . '/admin/class-shortcode-generator.php';

// The stub goes first, because the file after it declares a class extending
// what the stub declares -- and declines to declare anything at all without it.
require_once __DIR__ . '/bricks-stub.php';
require_once dirname( __DIR__ ) . '/admin/class-bricks-element.php';

if ( ! function_exists( 'slosm_bricks_element' ) ) {
	/**
	 * Builds one element the way Bricks builds one: settings in, nothing else.
	 *
	 * @param array $settings What the builder saved for this element.
	 * @return Bricks_Element
	 */
	function slosm_bricks_element( array $settings = array() ): Bricks_Element {
		return new Bricks_Element(
			array(
				'id'       => 'abc123',
				'name'     => Bricks_Element::NAME,
				'settings' => $settings,
			)
		);
	}
}

if ( ! function_exists( 'slosm_bricks_controls' ) ) {
	/**
	 * The controls the element declares, the way Bricks collects them.
	 *
	 * Bricks calls `load()` and then reads `$element->controls`; it never calls
	 * `set_controls()` and reads a return value. Going through `load()` here is
	 * what makes the cases below measure the property Bricks actually reads.
	 *
	 * @return array
	 */
	function slosm_bricks_controls(): array {
		$element = slosm_bricks_element();
		$element->load();

		return $element->controls;
	}
}

if ( ! function_exists( 'slosm_bricks_own_controls' ) ) {
	/**
	 * The controls this element contributed, with Bricks' own taken back out.
	 *
	 * `load()` is three calls, not one: Bricks fills `$this->controls` with
	 * its own list, lets the element add to it, and then adds more. So
	 * `slosm_bricks_controls()` is *everything the panel will draw*, which is
	 * the right thing to measure for "did Bricks' controls survive" and the
	 * wrong thing to measure for "is the element's list the shortcode's list".
	 *
	 * The two keys removed here are the stub's sentinels, named by constants
	 * on the stub rather than typed again — so this filter is exact rather
	 * than a guess at a prefix, and a stub that grows a third sentinel
	 * without saying so breaks the case that pins the whole key list rather
	 * than quietly slipping through this one.
	 *
	 * @return array
	 */
	function slosm_bricks_own_controls(): array {
		$controls = slosm_bricks_controls();

		unset(
			$controls[ \Bricks\Element::STUB_CONTROL_BEFORE ],
			$controls[ \Bricks\Element::STUB_CONTROL_AFTER ]
		);

		return $controls;
	}
}

if ( ! function_exists( 'slosm_bricks_boot' ) ) {
	/**
	 * A freshly booted plugin, standing for one request.
	 *
	 * A fresh instance rather than `Plugin::instance()`, for the reason
	 * `slosm_inval_boot()` in tests/test-cache-invalidation.php gives: the
	 * singleton boots at most once per process, so a case that asked it for hooks
	 * after another file had already booted would be handed none.
	 *
	 * @return Plugin
	 */
	function slosm_bricks_boot(): Plugin {
		$construct = Closure::bind(
			static function () {
				return new Plugin();
			},
			null,
			Plugin::class
		);

		$plugin = $construct();
		$plugin->boot();

		return $plugin;
	}
}

if ( ! function_exists( 'slosm_bricks_child' ) ) {
	/**
	 * Runs Plugin::register_bricks_element() in a bare child process and reports.
	 *
	 * Three cases need this and none of them can run here, because what each one
	 * varies is a thing this process cannot un-set: a declared class, or a defined
	 * constant. The child gets only what it is handed — so "Bricks' registry
	 * without Bricks' base class" is expressible, and so is "a plugin root with no
	 * element file in it".
	 *
	 * The child script goes into a temporary file rather than into `php -r`, for
	 * the reason tests/test-double-load.php gives: on Windows escapeshellarg()
	 * replaces double quotes with spaces, which mangles any inlined PHP containing
	 * a string literal.
	 *
	 * @param string[]    $stubs   Absolute paths to require before the plugin.
	 * @param string      $did     Marker printed when a registration happened.
	 * @param string      $refused Marker printed when none did.
	 * @param string|null $dir     What to define SLOSM_DIR as; the real root by default.
	 * @return string The child's whole output.
	 */
	function slosm_bricks_child( array $stubs, string $did, string $refused, ?string $dir = null ): string {
		$root = dirname( __DIR__ );

		$child = '<?php' . "\n"
			. 'define( ' . var_export( 'ABSPATH', true ) . ', ' . var_export( $root . '/', true ) . ' );' . "\n"
			. 'define( ' . var_export( 'SLOSM_DIR', true ) . ', ' . var_export( $dir ?? ( $root . '/' ), true ) . ' );' . "\n"
			. '$GLOBALS[' . var_export( 'slosm_stub', true ) . '][' . var_export( 'bricks_elements', true ) . '] = array();' . "\n"
			// boot() is never called here, so none of the hook functions are
			// needed -- but requiring class-plugin.php compiles constants whose
			// values are WordPress time constants, which wp-settings.php defines
			// on a real site and a bare process does not.
			. 'define( ' . var_export( 'MINUTE_IN_SECONDS', true ) . ', 60 );' . "\n"
			. 'define( ' . var_export( 'HOUR_IN_SECONDS', true ) . ', 3600 );' . "\n"
			. 'define( ' . var_export( 'DAY_IN_SECONDS', true ) . ', 86400 );' . "\n"
			. 'define( ' . var_export( 'MONTH_IN_SECONDS', true ) . ', 2592000 );' . "\n";

		foreach ( $stubs as $stub ) {
			$child .= 'require ' . var_export( $stub, true ) . ';' . "\n";
		}

		$child .= 'require ' . var_export( $root . '/includes/class-autoloader.php', true ) . ';' . "\n"
			. 'require ' . var_export( $root . '/includes/class-plugin.php', true ) . ';' . "\n"
			. 'Asymetria\\StoreLocator\\Plugin::register_bricks_element();' . "\n"
			. 'echo count( $GLOBALS[' . var_export( 'slosm_stub', true ) . '][' . var_export( 'bricks_elements', true ) . '] )'
			. ' ? ' . var_export( $did, true ) . ' : ' . var_export( $refused, true ) . ';' . "\n";

		$script  = tempnam( sys_get_temp_dir(), 'slosm' );
		$command = escapeshellarg( PHP_BINARY )
			. ' -d display_errors=1 -d error_reporting=-1 '
			. escapeshellarg( $script ) . ' 2>&1';

		file_put_contents( $script, $child );

		try {
			return (string) shell_exec( $command );
		} finally {
			unlink( $script );
		}
	}
}

describe(
	'bricks element',
	function () {

		before_each(
			function () {
				slosm_stub_reset();

				$GLOBALS['slosm_stub']['post_counts']['slosm_store'] = array( 'publish' => 3 );
			}
		);

		// --- the shape Bricks asks for -----------------------------------

		it(
			'extends the base class Bricks requires, and is not declared without it',
			function () {
				assert_true( class_exists( Bricks_Element::class ) );
				assert_true( is_subclass_of( Bricks_Element::class, '\\Bricks\\Element' ) );
			}
		);

		it(
			'declares the four properties Bricks reads off an element instance',
			function () {
				$element = slosm_bricks_element();

				// Read from Bricks\Elements::load_element(), which copies
				// exactly these off the instance into the builder's element
				// definition. A category Bricks does not have puts the element
				// in no panel section at all, which reads as "not installed".
				assert_same( 'slosm-store-locator', $element->name );
				assert_same( 'general', $element->category );
				assert_same( 'ti-location-pin', $element->icon );
				assert_true( '' !== $element->get_label() );
			}
		);

		it(
			'names its element after the constant the registration uses, so the two cannot disagree',
			function () {
				assert_same( Bricks_Element::NAME, slosm_bricks_element()->name );
			}
		);

		it(
			'escapes its own panel label, which Bricks writes with innerHTML too',
			function () {
				// The same threat model as the control labels, one level up,
				// and it was unpinned until a reviewer said so: esc_html() is
				// the identity on "Store Locator", so esc_html__() and __()
				// produced byte-identical output here and a mutant swapping
				// one for the other survived a whole sweep.
				//
				// The path, read off Bricks 2.4 rather than assumed.
				// Elements::load_element() copies $element_instance->label
				// into the builder's element definition
				// (includes/elements.php line 341), and the constructor sets
				// that property from get_label() (base.php line 74). The
				// element picker draws the definition's label with innerHTML
				// rather than textContent -- assets/js/main.min.js byte
				// offset 1103235, through the helper at 867553, which falls
				// back to bricksData.elements[ name ].label (the resolver at
				// 807340); the target picker at offset 2460046 does the same
				// thing with the same value.
				//
				// And the string is the return of __(), so on a site with a
				// language pack it is whatever a .mo file in the languages
				// directory says -- a file the site owner may not have
				// written. Hence the translation, which is the separating
				// input and exists for no other reason.
				$GLOBALS['slosm_stub']['translations']['Store Locator'] = '<b>Locator</b>';

				assert_same( '&lt;b&gt;Locator&lt;/b&gt;', slosm_bricks_element()->get_label() );

				// Two controls, and both are needed.
				//
				// The first says the translation really reached the element:
				// without it, a get_label() that ignored __() altogether --
				// or a stub that never translated -- would satisfy the line
				// above by returning something escaped for other reasons.
				assert_same( '<b>Locator</b>', __( 'Store Locator', 'store-locator-for-openstreetmap' ) );

				// The second says the escaping is the only difference. With
				// no translation installed the label is the English string
				// unchanged, so this case cannot pass by escaping everything
				// into mush.
				$GLOBALS['slosm_stub']['translations'] = array();

				assert_same( 'Store Locator', slosm_bricks_element()->get_label() );
			}
		);

		it(
			'is not draggable by its own surface, because a map swallows the pointer',
			function () {
				// Copied from the built-in Map element, which is the only other
				// element in Bricks that puts a map on the canvas
				// (includes/elements/map.php line 11 of Bricks 2.4). Leaflet takes pointer
				// events to pan itself, so an element draggable by its own
				// surface is one an editor can neither move nor pan.
				//
				// Unverified in the sense that matters — no builder has been
				// opened — which is why it is on Task 26's list as well as
				// here. What this case holds is that the decision was taken.
				assert_false( slosm_bricks_element()->draggable );
			}
		);

		it(
			'is in a category Bricks actually has',
			function () {
				// The eight Bricks 2.4 ships, counted off
				// includes/elements/*.php. 'general' is the built-in Map
				// element's own category, which is the closest precedent
				// there is.
				assert_true(
					in_array(
						slosm_bricks_element()->category,
						array( 'basic', 'filter', 'general', 'layout', 'media', 'query', 'single', 'wordpress' ),
						true
					)
				);
			}
		);

		// --- the attribute list is the shortcode's, through the generator ---

		it(
			'declares one control per shortcode attribute, in the shortcode’s order',
			function () {
				// The tripwire, and the reason this element is allowed to
				// exist at all. An attribute added to Shortcode and not
				// carried through fails here as well as in the generator's
				// own file.
				assert_same(
					array_keys( Shortcode::raw_defaults() ),
					array_keys( slosm_bricks_own_controls() )
				);
			}
		);

		it(
			'declares as many controls as there are attributes, and there are some',
			function () {
				// The control for the case above: two empty arrays are also
				// equal, and a derivation that answered nothing would pass it.
				$controls = slosm_bricks_own_controls();

				assert_same( count( Shortcode::raw_defaults() ), count( $controls ) );
				assert_true( count( $controls ) > 0 );
			}
		);

		it(
			'adds to the control list Bricks has already filled rather than replacing it',
			function () {
				// The fault this catches destroys every style control the
				// element has, on a real site, silently.
				//
				// Bricks calls set_controls_before(), then the element's
				// set_controls(), then set_controls_after()
				// (includes/elements/base.php lines 152-154 of Bricks 2.4).
				// The first of those writes something over a hundred entries
				// into $this->controls -- layout, spacing, sizing, typography
				// -- before the element is reached. So `$this->controls =
				// self::controls();` and `$this->controls[ $key ] = $control;`
				// differ by everything Bricks put there first, and for a long
				// while nothing here could tell them apart: the stub's two
				// hooks had empty bodies, so there was nothing to destroy.
				// They each contribute one sentinel now, and this is the case
				// that reads them.
				//
				// The whole key list rather than two isset() checks, because
				// the order is a claim too: Bricks' own first, this element's
				// in the shortcode's order, Bricks' own last.
				$expected = array_merge(
					array( \Bricks\Element::STUB_CONTROL_BEFORE ),
					array_keys( Shortcode::raw_defaults() ),
					array( \Bricks\Element::STUB_CONTROL_AFTER )
				);

				assert_same( $expected, array_keys( slosm_bricks_controls() ) );

				// And that the sentinel arrived whole rather than as a key
				// with something of this element's under it -- the control for
				// the assertion above, which array_keys() alone cannot give.
				$controls = slosm_bricks_controls();

				assert_same( 'text', $controls[ \Bricks\Element::STUB_CONTROL_BEFORE ]['type'] );
				assert_true( $controls[ \Bricks\Element::STUB_CONTROL_BEFORE ]['hidden'] );
			}
		);

		it(
			'takes every label and every description from the generator rather than writing its own',
			function () {
				$controls = slosm_bricks_controls();
				$source   = Shortcode_Generator::controls();

				foreach ( $source as $key => $control ) {
					// esc_html() because that is what this element applies at the
					// boundary, and it is the identity on every one of these strings
					// today -- which is exactly why the escaping needs the case below
					// as well as this one. What this line pins is that the text is the
					// generator's rather than one written here.
					assert_same( esc_html( $control['label'] ), $controls[ $key ]['label'], $key . ' has a label of its own' );

					if ( isset( $control['help'] ) ) {
						assert_same( esc_html( $control['help'] ), $controls[ $key ]['desc'], $key . ' has a description of its own' );
					} else {
						assert_false( isset( $controls[ $key ]['desc'] ), $key . ' invented a description' );
					}
				}

				// The control: the loop above is vacuous if the source is
				// empty, and at least one of these really does carry help.
				assert_true( count( $source ) > 0 );
				assert_true( '' !== ( $source['height']['help'] ?? '' ) );
			}
		);

		it(
			'takes every bound from the generator rather than retyping one',
			function () {
				$controls = slosm_bricks_controls();

				// Numbers rather than the generator's strings, because these
				// land on an <input type="number"> and Bricks' own number
				// controls are written with numbers. 'any' is a legal step and
				// is not a number, so it survives as itself.
				assert_same( Shortcode::MIN_HEIGHT, $controls['height']['min'] );
				assert_same( Shortcode::MAX_HEIGHT, $controls['height']['max'] );
				assert_same( Shortcode::MIN_ZOOM, $controls['zoom']['min'] );
				assert_same( Shortcode::MAX_ZOOM, $controls['zoom']['max'] );
				assert_same( 'any', $controls['radius']['step'] );
			}
		);

		it(
			'knows every control type the generator can produce',
			function () {
				// This is the one gap derivation opens that a hand-written
				// list does not have: a control type added to the generator
				// that this element has no mapping for. It degrades to a
				// visible 'info' control rather than silence — and this case
				// is what says the degradation is not happening today.
				$types = array();

				foreach ( Shortcode_Generator::controls() as $control ) {
					$types[ $control['type'] ] = true;
				}

				assert_true( count( $types ) > 0 );

				foreach ( array_keys( $types ) as $type ) {
					assert_true(
						isset( Bricks_Element::TYPES[ $type ] ),
						'the generator produces a "' . $type . '" control and this element cannot map it'
					);
				}
			}
		);

		it(
			'maps a control type it does not know to something visible rather than to nothing',
			function () {
				// Reached directly, because every type the generator produces
				// today is mapped — so the branch is unreachable from
				// controls() and a mutant deleting it would survive a whole
				// sweep. The same argument Shortcode_Generator::choices()
				// makes for being public.
				$mapped = Bricks_Element::control(
					array(
						'type'  => 'colour-wheel',
						'label' => 'Something new',
					)
				);

				assert_same( 'info', $mapped['type'] );
				assert_contains( 'Something new', $mapped['content'] );

				// And escaped on the way in. The label reaching this branch has
				// been through __(), so it is whatever a .mo file in the site's
				// languages directory says it is — content, from a file the
				// site owner may not have written, printed straight into the
				// builder panel.
				$markup = Bricks_Element::control(
					array(
						'type'  => 'colour-wheel',
						'label' => '<b>bold</b>',
					)
				);

				assert_contains( '&lt;b&gt;bold&lt;/b&gt;', $markup['content'] );
				assert_false( false !== strpos( $markup['content'], '<b>' ) );
			}
		);

		it(
			'escapes every string it hands the panel, because Bricks writes them with innerHTML',
			function () {
				// The separating input for the escaping, and it has to be a direct
				// call: esc_html() is the identity on all thirteen of the
				// generator's labels and every one of its help strings and option
				// labels, so nothing reachable through controls() can tell an
				// escaped build from an unescaped one. The same argument
				// Shortcode_Generator::choices() makes for being public.
				//
				// Why it matters: Bricks draws a control's label and its
				// description into the panel as html rather than as text. Read off
				// assets/js/main.min.js of Bricks 2.4 -- the label is bound to the
				// innerHTML of the caption span at byte offset 2703545, the
				// description to the innerHTML of a description div at 2705955,
				// and the value the second is bound to is a computed property at
				// 2678389 that answers the control's `description` key or its
				// `desc`, which is the key this element writes. Bricks' own
				// built-in elements pass esc_html__() into all of these fields
				// rather than __(); includes/elements/alert.php lines 22-35 does it
				// for a select's label, options and placeholder together. Every
				// string here is the return of __(), so it is whatever a .mo file in
				// the site's languages directory says it is.
				$mapped = Bricks_Element::control(
					array(
						'type'    => 'select',
						'label'   => '<b>Label</b>',
						'help'    => '<i>Help</i>',
						'choices' => array(
							''   => '<em>Inherit</em>',
							'km' => '<span>Kilometres</span>',
						),
					)
				);

				assert_same( '&lt;b&gt;Label&lt;/b&gt;', $mapped['label'] );
				assert_same( '&lt;i&gt;Help&lt;/i&gt;', $mapped['desc'] );
				assert_same( '&lt;em&gt;Inherit&lt;/em&gt;', $mapped['placeholder'] );
				assert_same( array( 'km' => '&lt;span&gt;Kilometres&lt;/span&gt;' ), $mapped['options'] );

				// And the option's key is left alone, which is not an oversight:
				// it is the value the shortcode will be handed, it comes from a
				// closed list this plugin owns, and escaping it would change the
				// value rather than how it is displayed.
				assert_same( array( 'km' ), array_keys( $mapped['options'] ) );

				// That line above is not enough by itself, and a surviving mutant
				// said so: esc_html() is the identity on 'km', 'mi', 'auto', 'yes'
				// and 'no', which is every value in Geo::UNITS and
				// Settings::CLUSTER_CHOICES, so a build that escaped the keys as
				// well would pass it. A key with a character esc_html() changes is
				// the only separating input there is, and it exists nowhere in the
				// plugin -- which is exactly why control() is called directly here
				// rather than reached through controls().
				$odd = Bricks_Element::control(
					array(
						'type'    => 'select',
						'label'   => 'Anything',
						'choices' => array( 'a&b' => 'A & B' ),
					)
				);

				assert_same( array( 'a&b' => 'A &amp; B' ), $odd['options'] );
			}
		);

		it(
			'turns the generator’s selects into Bricks selects, with the empty choice as the placeholder',
			function () {
				$controls = slosm_bricks_controls();
				$source   = Shortcode_Generator::controls();

				assert_same( 'select', $controls['units']['type'] );

				// Bricks selects are keyed 'options'; 'choices' is the
				// generator's word and means nothing to Bricks. Counted in
				// Bricks 2.4: 298 'options' => in includes/elements, 0
				// 'choices' =>. The shape a select takes is
				// includes/elements/alert.php lines 22-35, which is
				// 'type' => 'select' with 'options', 'placeholder' and
				// 'inline' together.
				assert_true( isset( $controls['units']['options'] ) );
				assert_false( isset( $controls['units']['choices'] ) );

				// The generator's '' choice is "say nothing and let the site
				// decide". Bricks spells that as nothing selected, so it
				// becomes the placeholder and leaves the options list.
				assert_same( $source['units']['choices'][''], $controls['units']['placeholder'] );
				assert_false( array_key_exists( '', $controls['units']['options'] ) );

				$expected = $source['units']['choices'];
				unset( $expected[''] );

				assert_same( $expected, $controls['units']['options'] );
			}
		);

		it(
			'puts every control in the Content tab, where a shortcode attribute belongs',
			function () {
				// Not decoration, and not covered by the key list either: these
				// thirteen are content, not styling, and a control declaring
				// 'style' would draw in the wrong panel tab with nothing
				// anywhere to say it had moved.
				//
				// What `load_element()` does with an *empty* tab is default it
				// to 'content' (includes/elements.php lines 320-325 of Bricks
				// 2.4), so deleting the key is harmless. Writing the wrong one
				// is not, and Bricks does not correct it. This holds both.
				$controls = slosm_bricks_own_controls();

				foreach ( $controls as $key => $control ) {
					assert_same( 'content', $control['tab'] ?? '', $key . ' is not in the Content tab' );
				}

				assert_true( count( $controls ) > 0 );

				// And the branch for a type this file cannot spell says the
				// same, which is the one place the key is written twice.
				$mapped = Bricks_Element::control( array( 'type' => 'colour-wheel', 'label' => 'x' ) );

				assert_same( 'content', $mapped['tab'] );
			}
		);

		it(
			'turns the generator’s numbers and texts into Bricks numbers and texts',
			function () {
				$controls = slosm_bricks_controls();

				assert_same( 'number', $controls['height']['type'] );
				assert_same( 'text', $controls['category']['type'] );
			}
		);

		it(
			'says “re-render” on every control rather than relying on the default',
			function () {
				// Every visible thing this element produces is in a data
				// attribute the server wrote, so a control that did not
				// re-render would move the panel and not the map.
				//
				// What this case does NOT claim is that the flag is what
				// prevents that. Read off Bricks 2.4's builder bundle
				// (assets/js/main.min.js, the `rerender` computed), a content
				// control with no `css` key re-renders by **default**, and
				// 'rerender' => false is the opt-out. So the flag is a pin, not
				// a mechanism: it holds if one of these controls ever grows a
				// `css` key, and it holds if the default changes. The element's
				// docblock says the same at more length, because an earlier
				// version of this file claimed the mechanism and was wrong.
				//
				// The element's own controls, because Bricks' own are not
				// this element's to have an opinion about: `_content` is a
				// `css` control and does not carry the flag.
				foreach ( slosm_bricks_own_controls() as $key => $control ) {
					assert_true( $control['rerender'] ?? false, $key . ' does not ask for a re-render' );
				}

				assert_true( count( slosm_bricks_own_controls() ) > 0 );
			}
		);

		// --- rendering is the shortcode's, and only the shortcode's --------

		it(
			'renders the same markup the shortcode does, inside a Bricks root',
			function () {
				$element = slosm_bricks_element( array( 'height' => '640' ) );

				ob_start();
				$element->render();
				$printed = (string) ob_get_clean();

				$shortcode = new Shortcode();
				$expected  = $shortcode->render( array( 'height' => '640' ) );

				assert_contains( $expected, $printed );
				assert_contains( '<div data-slosm-root="abc123">', $printed );
				assert_true( str_ends_with( $printed, '</div>' ) );
			}
		);

		it(
			'passes a setting through to the shortcode, so a control does something',
			function () {
				$element = slosm_bricks_element( array( 'height' => '640' ) );

				ob_start();
				$element->render();
				$printed = (string) ob_get_clean();

				assert_contains( 'height:640px', $printed );
			}
		);

		it(
			'leaves an empty setting out, so the site default decides',
			function () {
				// An empty control is how a builder spells "say nothing", and
				// it has to reach the shortcode as an absent attribute rather
				// than as an empty one — the two take different paths through
				// attributes() only if the empty one is dropped first.
				$element = slosm_bricks_element( array( 'height' => '', 'zoom' => '9' ) );

				assert_same( array( 'zoom' => '9' ), $element->attributes_for_shortcode() );
			}
		);

		it(
			'reads only the attributes the shortcode has, and nothing else the builder saved',
			function () {
				// Bricks saves a great deal beside the controls declared here —
				// _cssClasses, _margin, _typography, and anything a future
				// Bricks version adds. None of it is a shortcode attribute and
				// none of it may be handed to one.
				$element = slosm_bricks_element(
					array(
						'zoom'        => '9',
						'_cssClasses' => 'x',
						'onclick'     => 'alert(1)',
						'tag'         => 'script',
					)
				);

				assert_same( array( 'zoom' => '9' ), $element->attributes_for_shortcode() );
			}
		);

		it(
			'refuses a setting that is not a scalar rather than handing an array to a sanitiser',
			function () {
				// A repeater control, a Bricks dynamic-data structure, or a
				// corrupted post meta row. Shortcode::attributes() reads every
				// value as a string; an array reaching sanitize_text_field()
				// is a PHP notice on a front-end page.
				$element = slosm_bricks_element(
					array(
						'zoom'   => '9',
						'label'  => array( 'nested' => 'thing' ),
						'search' => new stdClass(),
					)
				);

				assert_same( array( 'zoom' => '9' ), $element->attributes_for_shortcode() );
			}
		);

		it(
			'spells a builder checkbox as the word the shortcode reads',
			function () {
				// The three tri-state controls are selects and hand back
				// strings, so this is not reachable through them today. It is
				// here because Bricks stores a checkbox as a real boolean, and
				// `true` reaching Shortcode::boolean() as the string '1' is a
				// different answer from 'yes' on exactly one of its branches.
				$element = slosm_bricks_element( array( 'near_me' => true, 'auto_locate' => false ) );

				assert_same(
					array(
						'near_me'     => 'yes',
						'auto_locate' => 'no',
					),
					$element->attributes_for_shortcode()
				);
			}
		);

		it(
			'renders through that gate, and not around it',
			function () {
				// The gate is only a gate if render() goes through it, and
				// every case above calls attributes_for_shortcode() directly —
				// which a render() handing $this->settings straight to the
				// shortcode would satisfy just as well. shortcode_atts() would
				// drop the unknown keys anyway; what it would not drop is the
				// array under a key it *does* know, and sanitize_text_field()
				// meeting an array is a notice on a front-end page.
				//
				// The case therefore asserts the whole render, not the gate:
				// the framework turns a notice into a failure, and the markup
				// has to be the one a shortcode with the valid attributes alone
				// would have produced.
				//
				// `cluster` is in here because it is the one attribute that can
				// tell the difference, and it took a surviving mutant to find
				// it. Every other sanitiser in Shortcode is total against
				// anything a builder can save, and boolean() answers a real
				// `true` with `true` — so routing round this gate changes
				// nothing for them. cluster() does not: its first line answers
				// a boolean with the *site setting*, because the word it is
				// looking for is 'yes' and a checkbox does not produce words.
				// So `cluster => true` means "always cluster" through the gate
				// and "whatever the site says" around it, and on this fixture
				// those are different answers.
				$element = slosm_bricks_element(
					array(
						'zoom'        => '9',
						'cluster'     => true,
						'label'       => array( 'nested' => 'thing' ),
						'_cssClasses' => 'x',
					)
				);

				ob_start();
				$element->render();
				$printed = (string) ob_get_clean();

				$shortcode = new Shortcode();

				assert_contains(
					$shortcode->render(
						array(
							'zoom'    => '9',
							'cluster' => 'yes',
						)
					),
					$printed
				);

				// Spelled out as well as compared, so the case says what it
				// means rather than only that two strings match: three
				// locations is far below the count that turns clustering on by
				// itself, so this true came from the setting and nowhere else.
				assert_contains( '&quot;cluster&quot;:true', $printed );
			}
		);

		it(
			'escapes a builder value on its way into the markup',
			function () {
				$element = slosm_bricks_element( array( 'label' => '"><script>alert(1)</script>' ) );

				ob_start();
				$element->render();
				$printed = (string) ob_get_clean();

				assert_false( false !== strpos( $printed, '<script>alert(1)</script>' ) );
				assert_false( false !== strpos( $printed, '">: map of locations' ) );

				// The control, and it is not optional: an element that rendered
				// nothing at all satisfies both lines above. This one says the
				// value really did travel — sanitize_text_field() took the tag
				// off and esc_attr() took the quote and the bracket with it,
				// which is Shortcode's work and is asserted at length in its own
				// file. What is asserted here is that this element handed the
				// value over rather than writing markup of its own around it.
				assert_contains( 'aria-label="&quot;&gt;: map of locations"', $printed );
			}
		);

		it(
			'renders nothing of its own when the shortcode renders nothing',
			function () {
				// The element adds a wrapper and a payload and no third thing.
				// Whatever is between the wrapper's tags is the shortcode's
				// answer byte for byte.
				$element = slosm_bricks_element();

				ob_start();
				$element->render();
				$printed = (string) ob_get_clean();

				$shortcode = new Shortcode();
				$inner     = substr( $printed, strlen( '<div data-slosm-root="abc123">' ), -strlen( '</div>' ) );

				assert_same( $shortcode->render( array() ), $inner );
			}
		);

		// --- assets, which is the part the builder changes ----------------

		it(
			'puts the locator’s script and stylesheet in the builder iframe up front',
			function () {
				// Bricks calls enqueue_scripts() from Elements::load_element(),
				// on the 'wp' hook and only when bricks_is_builder_iframe()
				// (includes/elements.php lines 392-393 of Bricks 2.4). That is
				// the only chance this element gets: every later re-render is
				// an ajax call into a page that has already printed its footer,
				// where wp_enqueue_script() adds a handle to a queue nobody
				// will print.
				slosm_bricks_element()->enqueue_scripts();

				assert_true( in_array( Assets::SCRIPT_LOCATOR, $GLOBALS['slosm_stub']['script_queue'], true ) );
				assert_true( in_array( Assets::STYLE_LOCATOR, $GLOBALS['slosm_stub']['style_queue'], true ) );
			}
		);

		it(
			'puts the cluster library in the builder iframe too, whatever this site’s count is',
			function () {
				// On the front end the cluster bundle is conditional, and
				// Shortcode::render() makes that decision per locator. In the
				// iframe it cannot be: the decision is made during an ajax
				// re-render, by which time nothing more can be added to the
				// page. So the builder carries it and the front end does not —
				// an editing context paying for an editing convenience, which
				// is only true because of the gate the next case measures.
				slosm_bricks_element()->enqueue_scripts();

				assert_true( in_array( Assets::SCRIPT_CLUSTER, $GLOBALS['slosm_stub']['script_queue'], true ) );
			}
		);

		it(
			'enqueues none of that on the front end, where the shortcode already decides',
			function () {
				// The separating input for the gate on enqueue_scripts(), and
				// the reason the gate exists at all. Bricks does NOT call this
				// method only in the builder: Frontend::render_element() calls
				// Element::init() (frontend.php lines 770-772 of Bricks 2.4)
				// and init() calls enqueue_scripts() (base.php lines 2955-2957).
				// So on every front-end page carrying this element the body of
				// that method runs too.
				//
				// Ungated, that hands the marker-cluster bundle -- some thirty
				// kilobytes of JavaScript and two stylesheets -- to every such
				// page whatever its location count is, overruling the decision
				// Shortcode::render() makes per locator and undoing Task 12's
				// conditional-loading contract. This element was written
				// believing the method was builder-only, on the strength of the
				// one call site that is gated.
				//
				// is_frontend is Bricks' own signal, set in the constructor at
				// base.php line 77 from $element['is_frontend'] when a caller
				// passed one and from bricks_is_frontend() when none did.
				//
				// The handles are registered first because that is what Plugin's
				// init hook does on a real site, and because wp_enqueue_script()
				// on a handle core has never been told about queues nothing --
				// which would make the two absence assertions below pass for a
				// reason that has nothing to do with this element.
				( new Assets() )->register();

				$GLOBALS['slosm_stub']['script_queue'] = array();
				$GLOBALS['slosm_stub']['style_queue']  = array();

				$element = new Bricks_Element(
					array(
						'id'          => 'abc123',
						'name'        => Bricks_Element::NAME,
						'is_frontend' => true,
						'settings'    => array(),
					)
				);

				$element->enqueue_scripts();

				assert_same( array(), $GLOBALS['slosm_stub']['script_queue'], 'the front end was handed scripts it did not ask for' );
				assert_same( array(), $GLOBALS['slosm_stub']['style_queue'], 'the front end was handed styles it did not ask for' );

				// And the render still works there, which is the control: an
				// enqueue_scripts() that returned early for everybody would
				// satisfy the two lines above, and the two cases before this one
				// are what refuse that. What this adds is that the front-end path
				// is not broken by the gate -- the locator still renders, and
				// Shortcode::render() still enqueues from inside itself, exactly
				// as it does for a shortcode, and still decides the cluster
				// bundle from the count rather than carrying it regardless.
				ob_start();
				$element->render();
				$printed = (string) ob_get_clean();

				assert_contains( 'class="slosm" data-slosm=', $printed );
				assert_true( in_array( Assets::SCRIPT_LOCATOR, $GLOBALS['slosm_stub']['script_queue'], true ), 'the front-end render enqueued nothing at all' );
				assert_false( in_array( Assets::SCRIPT_CLUSTER, $GLOBALS['slosm_stub']['script_queue'], true ), 'the cluster bundle reached a front-end page of three locations' );
			}
		);

		it(
			'defines the global Bricks will call to bring a re-rendered map back',
			function () {
				slosm_bricks_element()->enqueue_scripts();

				$after = $GLOBALS['slosm_stub']['scripts'][ Assets::SCRIPT_LOCATOR ]['extra']['after'] ?? array();

				assert_same( 1, count( $after ) );
				assert_contains( 'window.' . Bricks_Element::INIT_FUNCTION . ' =', $after[0] );
				assert_contains( 'SLOSM', $after[0] );
				assert_contains( 'sweep', $after[0] );
			}
		);

		it(
			'watches the canvas as well, so the revival does not rest on one mechanism',
			function () {
				// The braces to the $scripts array's belt, and the honest
				// reason for it: everything this plugin knows about Bricks
				// calling that array was read out of a minified bundle, never
				// watched. A MutationObserver on the canvas document reaches
				// the same sweep from the other side, so a Bricks version that
				// changes the script path leaves a map that still comes back.
				//
				// It is builder-only by construction rather than by a runtime
				// check -- enqueue_scripts() returns before this on the front
				// end -- which is what the "enqueues none of that on the front
				// end" case above already pins.
				slosm_bricks_element()->enqueue_scripts();

				$after = $GLOBALS['slosm_stub']['scripts'][ Assets::SCRIPT_LOCATOR ]['extra']['after'][0] ?? '';

				assert_contains( 'new window.MutationObserver(', $after );
				assert_contains( 'observer.observe( root, { childList: true, subtree: true } );', $after );

				// Coalesced rather than run per mutation. A builder canvas
				// mutates constantly -- this plugin's own result list does it
				// -- and a sweep per mutation is a querySelectorAll over the
				// whole document per mutation.
				assert_contains( 'window.requestAnimationFrame(', $after );
				assert_contains( 'if ( queued ) { return; }', $after );

				// Disconnected when the canvas goes away.
				assert_contains( 'observer.disconnect();', $after );
				assert_contains( '"pagehide"', $after );

				// And absent rather than broken where the browser cannot do
				// it. Both, because either one missing is a TypeError thrown
				// into the builder's console on load.
				assert_contains( 'typeof window.MutationObserver !== "function"', $after );
				assert_contains( 'typeof window.requestAnimationFrame !== "function"', $after );
			}
		);

		it(
			'guards that global, because Bricks calls it whether the script loaded or not',
			function () {
				// An assertion about a string, and it is here because nothing
				// else in either suite can hold this. Bricks calls the global
				// 200ms after a render with no idea whether locator.js arrived;
				// on a builder page where it did not, an unguarded
				// window.SLOSM.initAll() is a TypeError thrown into the
				// builder's own console on every edit.
				//
				// What this cannot prove is that the guard works, only that it
				// was not removed. The browser half is Task 26's.
				slosm_bricks_element()->enqueue_scripts();

				$after = $GLOBALS['slosm_stub']['scripts'][ Assets::SCRIPT_LOCATOR ]['extra']['after'][0] ?? '';

				assert_contains( 'window.SLOSM &&', $after );
				assert_contains( 'typeof window.SLOSM.sweep === "function"', $after );

				// And that what it guards is what it then calls. The guard
				// naming one function while the body calls another is a shape
				// no assertion about substrings can see on its own: "initAll"
				// contains "init", so a guard and a body that disagree can
				// both be present in the same string.
				assert_contains( 'window.SLOSM.sweep();', $after );

				// sweep() and not initAll(), and the difference is a map.
				// initAll() starts the node Bricks just parsed and leaves the
				// one it replaced alive -- a detached container with an L.Map
				// on it, still holding Leaflet's `resize` listener on window,
				// one more of them after every edit in the session. sweep() is
				// the teardown and the start, in that order.
				assert_false( false !== strpos( $after, 'SLOSM.initAll' ), 'the builder still calls initAll(), which never takes a map apart' );

				// The control for that absence: the string really does call
				// something on SLOSM, so the line above is not satisfied by an
				// empty inline script.
				assert_contains( 'window.SLOSM.sweep', $after );
			}
		);

		it(
			'names that global in the scripts array, which is the only place Bricks looks',
			function () {
				// Read off Bricks 2.4's builder bundle: runElementScripts()
				// reads the element definition's 'scripts' array — copied
				// straight off this property in Elements::load_element(),
				// includes/elements.php line 347 — and calls
				// iframeWindow[name]() for each entry, 200ms after a
				// re-render. A name here that the inline script above does not
				// define is a silent no-op, and a map that never comes back:
				// the bundle tests "function" == typeof before calling.
				assert_same( array( Bricks_Element::INIT_FUNCTION ), slosm_bricks_element()->scripts );
			}
		);

		it(
			'attaches that global to a handle that is registered, or core drops it',
			function () {
				// WP_Dependencies::add_data() returns false for a handle it
				// does not know, so an inline script attached before
				// Assets::register() has run is discarded without a word.
				// enqueue_scripts() runs on 'wp', which is after 'init'; this
				// case pins the ordering that makes that true.
				$GLOBALS['slosm_stub']['scripts'] = array();

				slosm_bricks_element()->enqueue_scripts();

				assert_true( isset( $GLOBALS['slosm_stub']['scripts'][ Assets::SCRIPT_LOCATOR ] ) );
			}
		);

		// --- registration -------------------------------------------------

		it(
			'registers nothing with Bricks when Bricks is not there',
			function () {
				// The stub declares \Bricks\Element, so this case removes the
				// other half: Plugin::register_bricks_element() needs
				// \Bricks\Elements, the registry, and that one has not been
				// declared yet in this process.
				assert_false( class_exists( '\\Bricks\\Elements', false ) );

				Plugin::register_bricks_element();

				assert_same( array(), $GLOBALS['slosm_stub']['bricks_elements'] );

				// The control, inside the case rather than leaning on the next
				// one. "Nothing was registered" is also what a method with an
				// empty body says, and what a method that threw and was caught
				// somewhere would say. Declaring the registry and calling again
				// is the same call in the same case with one condition changed,
				// which is the only version of this that measures the condition.
				require_once __DIR__ . '/bricks-registry-stub.php';

				Plugin::register_bricks_element();

				assert_same( 1, count( $GLOBALS['slosm_stub']['bricks_elements'] ) );
			}
		);

		it(
			'registers the element with Bricks, by the path the autoloader would take to it',
			function () {
				require_once __DIR__ . '/bricks-registry-stub.php';

				Plugin::register_bricks_element();

				$registered = $GLOBALS['slosm_stub']['bricks_elements'];

				assert_same( 1, count( $registered ) );
				assert_same( SLOSM_DIR . 'admin/class-bricks-element.php', $registered[0]['file'] );
				assert_same( Bricks_Element::class, $registered[0]['class'] );

				// Empty, so Bricks reads the name off the instance rather than
				// off a second copy of it here. Read from
				// Bricks\Elements::register_element(): an empty name is what
				// makes it construct the class and take $instance->name.
				assert_same( '', $registered[0]['name'] );
			}
		);

		it(
			'points at a file that is really there, by a path nobody typed',
			function () {
				// The path comes out of Autoloader::path_for(), so moving the
				// file or renaming the class moves the registration with it —
				// and this case is what notices when it moves somewhere that
				// does not exist.
				//
				// Plugin names the class as a string, because naming it as a
				// class would put the autoloader on a file that cannot be
				// compiled without Bricks. A string is a thing that can be
				// wrong silently, so it is asserted to be the class it is
				// supposed to be, here, where both are in scope.
				assert_same( Bricks_Element::class, Plugin::BRICKS_ELEMENT );

				assert_same(
					'admin/class-bricks-element.php',
					Autoloader::path_for( Plugin::BRICKS_ELEMENT )
				);
				assert_true( is_readable( SLOSM_DIR . 'admin/class-bricks-element.php' ) );
			}
		);

		it(
			'registers on init, after Bricks has loaded its own base class',
			function () {
				// Bricks\Elements hooks init_elements() on 'init' at the
				// default priority, and init_elements() is what requires
				// includes/elements/base.php. A registration at 10 or earlier
				// is a require of a file whose parent class does not exist yet.
				slosm_bricks_boot();

				$found = array();

				foreach ( $GLOBALS['slosm_stub']['actions']['init'] ?? array() as $registered ) {
					$target = $registered['callback'][0];

					if ( ( is_object( $target ) ? get_class( $target ) : (string) $target ) === Plugin::class
						&& 'register_bricks_element' === $registered['callback'][1] ) {
						$found[] = $registered['priority'];
					}
				}

				assert_same( array( 11 ), $found );
			}
		);

		it(
			'registers nothing when the registry is there and the base class is not yet',
			function () {
				// The separating input for the second half of that guard, and
				// it does not exist in this process: tests/bricks-stub.php
				// declares \Bricks\Element at file scope and a declared class
				// cannot be undeclared, so "the registry exists, the base class
				// does not" is only reachable in a child.
				//
				// That state is a real moment on a real site, not a
				// contrivance. Bricks\Elements is findable by Bricks' own
				// autoloader from the instant the theme loads; \Bricks\Element
				// is require_once'd by Elements::init_elements() on init at the
				// default priority. So between those two points the registry
				// answers and the base class does not — which is exactly what
				// this plugin registering at init 10 instead of 11 would walk
				// into, and what the second class_exists() refuses.
				$output = slosm_bricks_child(
					array( dirname( __DIR__ ) . '/tests/bricks-registry-stub.php' ),
					'REGISTERED-TOO-EARLY',
					'DECLINED-UNTIL-BRICKS-IS-READY'
				);

				assert_contains(
					'DECLINED-UNTIL-BRICKS-IS-READY',
					$output,
					"the registration ran before Bricks had loaded its base class; the child process said:\n" . trim( $output )
				);
			}
		);

		it(
			'registers when both halves of Bricks are there, which is the control for that',
			function () {
				// Without this, "declined" is also what a method that returns
				// on its first line says, and deleting the whole body would
				// keep the case above green.
				$output = slosm_bricks_child(
					array(
						dirname( __DIR__ ) . '/tests/bricks-registry-stub.php',
						dirname( __DIR__ ) . '/tests/bricks-stub.php',
					),
					'REGISTERED-AS-INTENDED',
					'DECLINED-WITH-BRICKS-PRESENT'
				);

				assert_contains(
					'REGISTERED-AS-INTENDED',
					$output,
					"the registration declined with the whole of Bricks present; the child process said:\n" . trim( $output )
				);
			}
		);

		it(
			'hands Bricks no path to a file that is not there',
			function () {
				// The separating input for the is_readable() check, and it is
				// a child process for the reason SLOSM_DIR is a constant: the
				// only way to ask "what if the file is missing" is to point the
				// plugin root somewhere it is.
				//
				// The state is a broken install — a partial upload, a
				// half-finished update, a security scanner that quarantined one
				// file. Bricks' register_element() `require_once`s whatever it
				// is handed, so a path that is right in shape and wrong on disk
				// is a fatal raised from inside somebody else's theme, on every
				// page of the site. An element that quietly does not appear is
				// the better failure by a wide margin.
				$output = slosm_bricks_child(
					array(
						dirname( __DIR__ ) . '/tests/bricks-registry-stub.php',
						dirname( __DIR__ ) . '/tests/bricks-stub.php',
					),
					'HANDED-OVER-A-MISSING-FILE',
					'REFUSED-A-MISSING-FILE',
					sys_get_temp_dir() . '/slosm-not-a-plugin-root/'
				);

				assert_contains(
					'REFUSED-A-MISSING-FILE',
					$output,
					"the registration handed Bricks a path with no file at it; the child process said:\n" . trim( $output )
				);
			}
		);

		it(
			'survives the element file being loaded twice',
			function () {
				// The same guard every other class in this plugin carries, and
				// the same reason: a theme or another plugin that pastes this
				// file in a second time is a compile-time fatal without it.
				// The class is already loaded in this process, so requiring it
				// again is the second compile.
				require dirname( __DIR__ ) . '/admin/class-bricks-element.php';

				assert_true( class_exists( Bricks_Element::class ) );
			}
		);

		it(
			'declares no class at all in a process where Bricks is absent',
			function () {
				// This has to be a child process. \Bricks\Element is declared
				// in this one by the stub above and cannot be undeclared, so
				// the absence being asserted is unreachable from here — and a
				// file that fatals on `extends` would take the suite with it
				// rather than report.
				$root  = dirname( __DIR__ );
				$child = sprintf(
					'<?php' . "\n"
					. 'define( %s, %s );' . "\n"
					. 'require %s;' . "\n"
					. 'echo class_exists( %s, false ) ? %s : %s;' . "\n",
					var_export( 'ABSPATH', true ),
					var_export( $root . '/', true ),
					var_export( $root . '/admin/class-bricks-element.php', true ),
					var_export( Bricks_Element::class, true ),
					var_export( 'DECLARED-WITHOUT-BRICKS', true ),
					var_export( 'ABSENT-AS-INTENDED', true )
				);

				$script  = tempnam( sys_get_temp_dir(), 'slosm' );
				$command = escapeshellarg( PHP_BINARY )
					. ' -d display_errors=1 -d error_reporting=-1 '
					. escapeshellarg( $script ) . ' 2>&1';

				file_put_contents( $script, $child );

				try {
					$output = (string) shell_exec( $command );
				} finally {
					unlink( $script );
				}

				assert_contains(
					'ABSENT-AS-INTENDED',
					$output,
					"loading the element file without Bricks did not end quietly; the child process said:\n" . trim( $output )
				);
			}
		);

		it(
			'declares the class in a child process that does have Bricks',
			function () {
				// The control for the case above. Without it, "ABSENT" is also
				// what a file that fails to load for any other reason prints,
				// and deleting the whole class body would keep that case green.
				$root  = dirname( __DIR__ );
				$child = sprintf(
					'<?php' . "\n"
					. 'define( %s, %s );' . "\n"
					. 'require %s;' . "\n"
					. 'require %s;' . "\n"
					. 'echo class_exists( %s, false ) ? %s : %s;' . "\n",
					var_export( 'ABSPATH', true ),
					var_export( $root . '/', true ),
					var_export( $root . '/tests/bricks-stub.php', true ),
					var_export( $root . '/admin/class-bricks-element.php', true ),
					var_export( Bricks_Element::class, true ),
					var_export( 'DECLARED-WITH-BRICKS', true ),
					var_export( 'MISSING-WITH-BRICKS', true )
				);

				$script  = tempnam( sys_get_temp_dir(), 'slosm' );
				$command = escapeshellarg( PHP_BINARY )
					. ' -d display_errors=1 -d error_reporting=-1 '
					. escapeshellarg( $script ) . ' 2>&1';

				file_put_contents( $script, $child );

				try {
					$output = (string) shell_exec( $command );
				} finally {
					unlink( $script );
				}

				assert_contains(
					'DECLARED-WITH-BRICKS',
					$output,
					"loading the element file with Bricks present did not declare it; the child process said:\n" . trim( $output )
				);
			}
		);
	}
);
