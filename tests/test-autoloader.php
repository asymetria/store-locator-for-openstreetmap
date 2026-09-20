<?php
/**
 * Proves the class-name to file-path mapping, without touching the filesystem.
 *
 * path_for() is deliberately separate from load() so these cases can assert on
 * the mapping rule itself. None of the files named here has to exist: a case
 * that passed only because a file was present would go red the moment a class
 * was renamed, and stay green if the rule was wrong for a file not yet written.
 *
 * @package Store_Locator_For_OpenStreetMap
 */

require_once dirname( __DIR__ ) . '/includes/class-autoloader.php';

use Asymetria\StoreLocator\Autoloader;

describe(
	'autoloader',
	function () {

		it(
			'maps a namespaced class to includes/',
			function () {
				assert_same(
					'includes/class-store-repository.php',
					Autoloader::path_for( 'Asymetria\\StoreLocator\\Store_Repository' )
				);
			}
		);

		it(
			'maps an admin class to admin/',
			function () {
				assert_same(
					'admin/class-settings.php',
					Autoloader::path_for( 'Asymetria\\StoreLocator\\Admin\\Settings' )
				);
			}
		);

		it(
			'hyphenates and nests directory segments',
			function () {
				// The same lowercase-and-hyphenate rule applies to directories
				// as to filenames, so an underscored sub-namespace cannot leave
				// a rest_api/ sitting next to a hyphenated admin/.
				assert_same(
					'rest-api/class-controller.php',
					Autoloader::path_for( 'Asymetria\\StoreLocator\\Rest_Api\\Controller' )
				);

				assert_same(
					'admin/fields/class-map-field.php',
					Autoloader::path_for( 'Asymetria\\StoreLocator\\Admin\\Fields\\Map_Field' )
				);
			}
		);

		it(
			'ignores classes from other namespaces',
			function () {
				assert_same( null, Autoloader::path_for( 'Other\\Thing' ) );
			}
		);
	}
);
