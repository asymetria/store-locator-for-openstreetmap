<?php
/**
 * A stand-in for `Bricks\Elements`, the registry a plugin hands an element to.
 *
 * In its own file, and loaded by one case rather than at file scope, because
 * another case has to watch this plugin **decline** to register when the
 * registry is not there — and a class declared in a PHP process cannot be
 * undeclared afterwards. Splitting the two stubs is the only way both cases can
 * run in the same process.
 *
 * The signature is `Bricks\Elements::register_element()`'s, read from
 * `includes/elements.php` lines 253-284 of Bricks 2.4. What the real one
 * does with the arguments is worth knowing, because this plugin depends on one
 * branch of it: it returns at once unless the file `is_readable()`, then
 * `require_once`s it, then — if the class name it was handed is empty or not
 * declared — falls back to `end( get_declared_classes() )`, and then, **if the
 * element name is empty**, constructs the class and takes `$instance->name`,
 * `$instance->label` and `$instance->description` off it. This plugin passes a
 * class name and no element name precisely so that the fallback is never
 * reached and the name is never written down twice.
 *
 * The `is_readable()` on the real one's first line is worth naming, because
 * `Plugin::register_bricks_element()` carries the same check and the comment
 * there used to justify it by a fatal that cannot happen: Bricks 2.4 declines
 * an unreadable path quietly. The check stays, and that file now says why on
 * the grounds that survive reading this one.
 *
 * What is not modelled: the `$elements` array the real one builds, the
 * `enqueue_scripts()` call in `load_element()`, and the `wp` hook that triggers
 * it. Nothing here needs them, and modelling them would be asserting Bricks.
 *
 * @package Store_Locator_For_OpenStreetMap
 */

namespace Bricks;

if ( ! class_exists( '\\Bricks\\Elements' ) ) {

	/**
	 * Records what it was handed, and does nothing with it.
	 */
	class Elements {

		/**
		 * Records one registration.
		 *
		 * @param string $file               Absolute path to the element's file.
		 * @param string $element_name       The element's machine name, or '' to read it off the instance.
		 * @param string $element_class_name The element's class, or '' to guess at it.
		 * @return void
		 */
		public static function register_element( $file, $element_name = '', $element_class_name = '' ) {
			$GLOBALS['slosm_stub']['bricks_elements'][] = array(
				'file'  => $file,
				'name'  => $element_name,
				'class' => $element_class_name,
			);
		}
	}
}
