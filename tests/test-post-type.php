<?php
/**
 * Proves what the plugin asks WordPress to register, and with which arguments.
 *
 * Every case here asserts on the arguments handed to register_post_type() and
 * register_taxonomy(), never on WordPress behaviour — the stubs record the call
 * and do nothing else, so a test that checked "the post type is not queryable"
 * would only be testing the stub. What the plugin controls is the argument
 * array, so that is what is pinned.
 *
 * @package Store_Locator_For_OpenStreetMap
 */

require_once dirname( __DIR__ ) . '/includes/class-post-type.php';
require_once dirname( __DIR__ ) . '/includes/class-settings.php';
require_once dirname( __DIR__ ) . '/includes/class-plugin.php';
require_once dirname( __DIR__ ) . '/admin/class-settings-screen.php';
require_once dirname( __DIR__ ) . '/admin/class-shortcode-generator.php';
// Plugin::boot() constructs an Admin for the location metabox, and this suite
// runs without the autoloader.
require_once dirname( __DIR__ ) . '/admin/class-admin.php';
require_once dirname( __DIR__ ) . '/admin/class-locations-list.php';
require_once dirname( __DIR__ ) . '/admin/class-bulk-geocode.php';

use Asymetria\StoreLocator\Plugin;
use Asymetria\StoreLocator\Post_Type;

describe(
	'post type',
	function () {

		// Plain closures, not stub state: nothing here touches
		// $GLOBALS['slosm_stub'] until a case runs, so the group-scope dirty
		// check stays happy and no before_each() is needed. register() is
		// called inside each case instead of from a fixture because the filter
		// case has to register its filter first.
		$post_type = static function ( string $name ) {
			foreach ( $GLOBALS['slosm_stub']['post_types'] as $record ) {
				if ( $name === $record['name'] ) {
					return $record;
				}
			}

			return null;
		};

		$taxonomy = static function ( string $name ) {
			foreach ( $GLOBALS['slosm_stub']['taxonomies'] as $record ) {
				if ( $name === $record['name'] ) {
					return $record;
				}
			}

			return null;
		};

		it(
			'registers slosm_store',
			function () use ( $post_type ) {
				( new Post_Type() )->register();

				assert_same( 1, count( $GLOBALS['slosm_stub']['post_types'] ), 'expected exactly one post type' );
				assert_same( 'slosm_store', $GLOBALS['slosm_stub']['post_types'][0]['name'] );
				assert_true( is_array( $post_type( 'slosm_store' ) ) );
			}
		);

		it(
			'is not publicly queryable on its own',
			function () use ( $post_type ) {
				( new Post_Type() )->register();

				$args = $post_type( 'slosm_store' )['args'];

				assert_false( $args['public'], 'a location is map data, not a page' );
				assert_true( $args['show_ui'], 'editors still need the admin screens' );
				assert_true( $args['show_in_rest'], 'the block editor and the map both read through REST' );
			}
		);

		it(
			'registers the category taxonomy for stores',
			function () use ( $taxonomy ) {
				( new Post_Type() )->register();

				assert_same( 1, count( $GLOBALS['slosm_stub']['taxonomies'] ), 'expected exactly one taxonomy' );

				$record = $taxonomy( 'slosm_store_category' );

				assert_same( 'slosm_store_category', $record['name'] );
				assert_same( 'slosm_store', $record['object_type'] );
				assert_true( $record['args']['hierarchical'], 'categories nest; tags do not' );
				assert_true( $record['args']['show_in_rest'] );
				assert_true( $record['args']['show_admin_column'] );
			}
		);

		it(
			'lets a filter open the post type up',
			function () use ( $post_type ) {
				add_filter(
					'slosm_store_public',
					static function () {
						return true;
					}
				);

				( new Post_Type() )->register();

				assert_true( $post_type( 'slosm_store' )['args']['public'], 'the slosm_store_public filter did not reach register_post_type()' );
			}
		);

		it(
			'hands the filter the closed default to work from',
			function () {
				$seen  = 'nothing';
				$calls = 0;

				add_filter(
					'slosm_store_public',
					static function ( $value ) use ( &$seen, &$calls ) {
						$seen = $value;
						++$calls;

						return $value;
					}
				);

				( new Post_Type() )->register();

				assert_false( $seen, 'the filter was handed something other than the closed default' );

				// Once for both registrations, not once each. Two calls would
				// mean the post type and the taxonomy could disagree if a site
				// hooked the filter with anything stateful.
				assert_same( 1, $calls, 'the filter ran once per registration instead of once per init' );
			}
		);

		it(
			'keeps the categories closed alongside the locations',
			function () use ( $taxonomy ) {
				( new Post_Type() )->register();

				$args = $taxonomy( 'slosm_store_category' )['args'];

				// register_taxonomy() defaults public to true, so this has to be
				// said out loud. Left alone it would put empty term archives in
				// core's sitemap, which selects taxonomies on exactly this.
				assert_false( $args['public'], 'the category archives were left open' );

				// Not derived from public — it defaults to true on its own, so
				// closing public alone leaves the rewrite rules behind.
				assert_false( $args['rewrite'], 'the taxonomy kept its rewrite rules' );

				assert_false( $args['show_in_nav_menus'] );

				// Closed on the front end, still fully present in the admin.
				assert_true( $args['show_ui'], 'closing the front end took the category screens away' );
				assert_true( $args['show_in_rest'] );
				assert_true( $args['show_admin_column'] );
			}
		);

		it(
			'opens the categories with the same filter that opens the locations',
			function () use ( $post_type, $taxonomy ) {
				add_filter(
					'slosm_store_public',
					static function () {
						return true;
					}
				);

				( new Post_Type() )->register();

				$args = $taxonomy( 'slosm_store_category' )['args'];

				assert_true( $post_type( 'slosm_store' )['args']['public'] );
				assert_true( $args['public'], 'locations opened but their categories did not' );

				// An open taxonomy with rewrite still off would be publicly
				// queryable with no pretty permalink and no way into a menu.
				assert_true( $args['rewrite'], 'the opened categories got no rewrite rules' );
				assert_true( $args['show_in_nav_menus'] );

				// The one argument that does not follow the filter either way.
				assert_true( $args['show_ui'] );
			}
		);

		it(
			'pins its REST route to the prefixed key',
			function () use ( $post_type ) {
				( new Post_Type() )->register();

				// Identical to the default today. Stated anyway, because
				// /wp/v2/slosm_store is a public contract, and wp/v2 is a shared
				// namespace where a bare "stores" would be a collision waiting
				// for the next locator plugin.
				assert_same( 'slosm_store', $post_type( 'slosm_store' )['args']['rest_base'] );
			}
		);

		it(
			'keeps the filtered value a boolean',
			function () use ( $post_type ) {
				// register_post_type() documents 'public' as a bool and derives
				// three other arguments from it, so a filter returning a truthy
				// string must not travel any further than this cast.
				add_filter(
					'slosm_store_public',
					static function () {
						return '1';
					}
				);

				( new Post_Type() )->register();

				assert_true( $post_type( 'slosm_store' )['args']['public'] );
			}
		);

		it(
			'carries the editing arguments the admin screens need',
			function () use ( $post_type ) {
				( new Post_Type() )->register();

				$args = $post_type( 'slosm_store' )['args'];

				assert_same( 'dashicons-location', $args['menu_icon'] );
				assert_same( array( 'title', 'editor', 'thumbnail' ), $args['supports'] );
				assert_false( $args['has_archive'], 'a closed post type has nothing to archive' );
			}
		);

		it(
			'labels every admin screen it puts in front of an editor',
			function () use ( $post_type ) {
				( new Post_Type() )->register();

				$labels = $post_type( 'slosm_store' )['args']['labels'];

				$expected = array(
					'name',
					'singular_name',
					'menu_name',
					'add_new',
					'add_new_item',
					'edit_item',
					'new_item',
					'view_item',
					'view_items',
					'search_items',
					'not_found',
					'not_found_in_trash',
					'all_items',
					'featured_image',
					'set_featured_image',
					'remove_featured_image',
					'use_featured_image',
					'insert_into_item',
					'uploaded_to_this_item',
					'filter_items_list',
					'items_list_navigation',
					'items_list',
					// The block editor's save snackbar reads these six. Without
					// them an editor saving a location is told "Post published."
					'item_published',
					'item_published_privately',
					'item_reverted_to_draft',
					'item_trashed',
					'item_scheduled',
					'item_updated',
				);

				foreach ( $expected as $key ) {
					assert_true( isset( $labels[ $key ] ), 'missing post type label: ' . $key );
					assert_true( '' !== $labels[ $key ], 'empty post type label: ' . $key );
				}

				// Counting as well as listing. Without this a label added to the
				// implementation and forgotten here would sit unpinned, and
				// deleting it again would go unnoticed.
				assert_same( count( $expected ), count( $labels ), 'a post type label is not pinned by this case' );
			}
		);

		it(
			'labels the taxonomy screens too',
			function () use ( $taxonomy ) {
				( new Post_Type() )->register();

				$labels = $taxonomy( 'slosm_store_category' )['args']['labels'];

				$expected = array(
					'name',
					'singular_name',
					'menu_name',
					'search_items',
					'all_items',
					'parent_item',
					'parent_item_colon',
					'edit_item',
					'view_item',
					'update_item',
					'add_new_item',
					'new_item_name',
					'not_found',
					'no_terms',
					'back_to_items',
					'items_list_navigation',
					'items_list',
				);

				foreach ( $expected as $key ) {
					assert_true( isset( $labels[ $key ] ), 'missing taxonomy label: ' . $key );
					assert_true( '' !== $labels[ $key ], 'empty taxonomy label: ' . $key );
				}

				assert_same( count( $expected ), count( $labels ), 'a taxonomy label is not pinned by this case' );
			}
		);
	}
);

describe(
	'post type wiring',
	function () {

		it(
			'registers nothing until init fires',
			function () {
				$construct = Closure::bind(
					static function () {
						return new Plugin();
					},
					null,
					Plugin::class
				);

				$construct()->boot();

				// boot() must hook, not register. Registering at load time is
				// too early for WordPress and would fire again on every
				// subsequent load of this file.
				assert_same( 0, count( $GLOBALS['slosm_stub']['post_types'] ), 'boot() registered the post type directly' );

				// Five callbacks on init since Task 23: the settings option's
				// sanitiser, this registration, the asset handles, the
				// shortcode, and the Bricks element — that last one at
				// priority 11 rather than 10, which is why it is a count here
				// and a named list elsewhere. Pinned rather than relaxed to
				// "at least one", because the value of this assertion is that
				// a registration appearing or disappearing has to be noticed
				// somewhere. tests/test-cache-invalidation.php names which
				// five they are and asserts the order they run in.
				assert_same( 5, count( $GLOBALS['slosm_stub']['actions']['init'] ?? array() ), 'boot() did not hook init exactly five times' );

				do_action( 'init' );

				assert_same( 'slosm_store', $GLOBALS['slosm_stub']['post_types'][0]['name'] );
				assert_same( 'slosm_store_category', $GLOBALS['slosm_stub']['taxonomies'][0]['name'] );
			}
		);
	}
);
