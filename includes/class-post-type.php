<?php
/**
 * The content types a store locator needs: a location, and a way to group them.
 *
 * @package Store_Locator_For_OpenStreetMap
 */

namespace Asymetria\StoreLocator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( __NAMESPACE__ . '\\Post_Type' ) ) {

	/**
	 * Registers the slosm_store post type and its category taxonomy.
	 *
	 * Why the post type is closed (public => false)
	 * ---------------------------------------------
	 * A location is data for a map, not a page. It is a name, an address, a pair
	 * of coordinates and an opening-hours table — the same handful of fields as
	 * every other location on the site. Give each one a permalink and a site
	 * with forty branches gains forty thin, near-identical pages that nobody
	 * asked for: pages that then have to be kept out of the sitemap, out of
	 * search results and out of the site's internal search, each exclusion being
	 * one more thing to remember and one more thing to get wrong. Starting
	 * closed means none of that work exists. The locator itself does not need
	 * the permalinks either; it reads the locations and draws them on a map.
	 *
	 * Some clients genuinely do want a page per location — a chain whose
	 * branches each rank locally, say. That is what the slosm_store_public
	 * filter is for, and it is a deliberate opt-in rather than the default. A
	 * site that flips it has to flush rewrite rules once afterwards, because
	 * WordPress caches the rewrite table and the new permalink structure will
	 * not resolve until it is rebuilt: visiting Settings > Permalinks is enough.
	 * This class does not flush anything. Flushing on every init is a documented
	 * way to make a site slow, and flushing here would run on a request that has
	 * no idea whether anything changed. The categories move with the post type
	 * rather than having a switch of their own; taxonomy_args() has the reason.
	 *
	 * Note that closed means "not on the front end under its own url". It is not
	 * a privacy control: show_in_rest is on, so published locations are readable
	 * through the REST API, which is what the block editor and the map both
	 * want.
	 */
	final class Post_Type {

		/**
		 * The post type key.
		 *
		 * @var string
		 */
		public const POST_TYPE = 'slosm_store';

		/**
		 * The taxonomy key.
		 *
		 * @var string
		 */
		public const TAXONOMY = 'slosm_store_category';

		/**
		 * Registers the post type and the taxonomy.
		 *
		 * Hooked to init by Plugin::boot(); see that method for why it is a hook
		 * and not a direct call.
		 *
		 * The filter is read once, here, and handed to both registrations. The
		 * post type and its categories have to open and close together: a closed
		 * post type behind open category archives is the worst of both, and it
		 * is what the taxonomy defaults would have given us. Reading the filter
		 * once also means a site that hooks it sees one call per init, not one
		 * per registration.
		 *
		 * @return void
		 */
		public function register(): void {
			/**
			 * Filters whether locations get their own front-end pages.
			 *
			 * False, the default, keeps a location as map data. Returning true
			 * gives every location a permalink and gives the categories their
			 * term archives; the site then has to flush rewrite rules once for
			 * those permalinks to resolve.
			 *
			 * @param bool $public Whether locations are public.
			 */
			$public = (bool) apply_filters( 'slosm_store_public', false );

			register_post_type( self::POST_TYPE, $this->post_type_args( $public ) );
			register_taxonomy( self::TAXONOMY, self::POST_TYPE, $this->taxonomy_args( $public ) );
		}

		/**
		 * The arguments slosm_store is registered with.
		 *
		 * The cast on the filter result is not decoration, though its value is
		 * narrower than it looks. WP_Post_Type::set_props() derives
		 * publicly_queryable, embeddable, show_in_nav_menus and
		 * exclude_from_search from public when they are not given — not show_ui,
		 * which this registration sets explicitly, so that branch never runs
		 * here. Those derivations go through wp_list_filter(), which compares
		 * loosely, so a truthy string would in fact derive the same values. What
		 * the cast buys is the property itself: public stays a real boolean on
		 * the WP_Post_Type object, so a === true comparison somewhere else does
		 * not quietly fail, and /wp/v2/types/slosm_store reports a JSON boolean
		 * rather than a string.
		 *
		 * rest_base is set to the post type key it would have defaulted to.
		 * Nothing changes today; the point is that /wp/v2/slosm_store becomes a
		 * contract this file states rather than one inferred from the key, so
		 * the key and the route can move independently once anything consumes
		 * the url. The prefix stays in the route on purpose: wp/v2 is shared
		 * with every other plugin on the site, and a bare "stores" is exactly
		 * the sort of name the next locator plugin would also claim.
		 *
		 * @param bool $public Whether locations get their own front-end pages.
		 * @return array
		 */
		private function post_type_args( bool $public ): array {
			return array(
				'labels'       => $this->post_type_labels(),
				'public'       => $public,
				'show_ui'      => true,
				'show_in_rest' => true,
				'rest_base'    => self::POST_TYPE,
				'menu_icon'    => 'dashicons-location',
				'supports'     => array( 'title', 'editor', 'thumbnail' ),
				'has_archive'  => false,
			);
		}

		/**
		 * Every label WordPress puts in front of an editor working with locations.
		 *
		 * The full set rather than name and singular_name, because WordPress
		 * falls back to the Post labels for whatever is missing, and an editor
		 * who deletes a branch should not be told "No posts found in Trash".
		 *
		 * @return array
		 */
		private function post_type_labels(): array {
			return array(
				'name'                    => __( 'Locations', 'store-locator-for-openstreetmap' ),
				'singular_name'           => __( 'Location', 'store-locator-for-openstreetmap' ),
				'menu_name'               => __( 'Store Locator', 'store-locator-for-openstreetmap' ),
				'add_new'                 => __( 'Add Location', 'store-locator-for-openstreetmap' ),
				'add_new_item'            => __( 'Add New Location', 'store-locator-for-openstreetmap' ),
				'edit_item'               => __( 'Edit Location', 'store-locator-for-openstreetmap' ),
				'new_item'                => __( 'New Location', 'store-locator-for-openstreetmap' ),
				'view_item'               => __( 'View Location', 'store-locator-for-openstreetmap' ),
				'view_items'              => __( 'View Locations', 'store-locator-for-openstreetmap' ),
				'search_items'            => __( 'Search Locations', 'store-locator-for-openstreetmap' ),
				'not_found'               => __( 'No locations found.', 'store-locator-for-openstreetmap' ),
				'not_found_in_trash'      => __( 'No locations found in Trash.', 'store-locator-for-openstreetmap' ),
				'all_items'               => __( 'All Locations', 'store-locator-for-openstreetmap' ),
				'featured_image'          => __( 'Location photo', 'store-locator-for-openstreetmap' ),
				'set_featured_image'      => __( 'Set location photo', 'store-locator-for-openstreetmap' ),
				'remove_featured_image'   => __( 'Remove location photo', 'store-locator-for-openstreetmap' ),
				'use_featured_image'      => __( 'Use as location photo', 'store-locator-for-openstreetmap' ),
				'insert_into_item'        => __( 'Insert into location', 'store-locator-for-openstreetmap' ),
				'uploaded_to_this_item'   => __( 'Uploaded to this location', 'store-locator-for-openstreetmap' ),
				'filter_items_list'       => __( 'Filter locations list', 'store-locator-for-openstreetmap' ),
				'items_list_navigation'   => __( 'Locations list navigation', 'store-locator-for-openstreetmap' ),
				'items_list'              => __( 'Locations list', 'store-locator-for-openstreetmap' ),

				// The block editor reads these six for its save snackbar. Miss
				// them and every save on the Locations screen says "Post
				// published." — the Add New Post screen showing through the
				// moment an editor does the most ordinary thing there is.
				'item_published'          => __( 'Location published.', 'store-locator-for-openstreetmap' ),
				'item_published_privately'=> __( 'Location published privately.', 'store-locator-for-openstreetmap' ),
				'item_reverted_to_draft'  => __( 'Location reverted to draft.', 'store-locator-for-openstreetmap' ),
				'item_trashed'            => __( 'Location moved to the Trash.', 'store-locator-for-openstreetmap' ),
				'item_scheduled'          => __( 'Location scheduled.', 'store-locator-for-openstreetmap' ),
				'item_updated'            => __( 'Location updated.', 'store-locator-for-openstreetmap' ),
			);
		}

		/**
		 * The arguments slosm_store_category is registered with.
		 *
		 * Hierarchical because a locator groups locations the way a shop groups
		 * products — Showrooms above Warsaw and Kraków — and a flat tag list
		 * cannot express that. show_admin_column puts the grouping on the
		 * locations list, where someone scanning forty branches will look for it.
		 *
		 * The closing here is deliberate and has to be spelled out, because
		 * register_taxonomy() defaults public to true — the opposite of the post
		 * type above. Left alone, a closed post type would have shipped with
		 * open category archives: a query var, a slot in Appearance > Menus, and
		 * an entry per category in core's xml sitemap, which selects taxonomies
		 * by exactly this argument.
		 *
		 * Those archives would not even work. A term archive with no post type
		 * of its own infers one from the post types that are not excluded from
		 * search; slosm_store derives exclude_from_search from its own closed
		 * public, so it is excluded, the query falls back to "any", which
		 * excludes it again — and WP::handle_404() still answers 200 because
		 * there is a queried object. That is an indexable, sitemap-listed,
		 * permanently empty page per category: precisely the thin-page problem
		 * the post type above is closed to avoid.
		 *
		 * Four arguments here are written out rather than left to WP_Taxonomy,
		 * for three different reasons:
		 *
		 * - show_ui is pinned true. It would otherwise derive from public, and
		 *   closing the front end must not take the category screens away from
		 *   the editors who fill them in. This is the one deliberate divergence.
		 * - show_in_nav_menus would derive from public correctly; it is written
		 *   out only so this array says what it means.
		 * - rewrite is the trap. It does not derive from public at all: it
		 *   defaults to true on its own, so a non-public taxonomy still gets a
		 *   rewrite tag and a permastruct unless something says otherwise.
		 *   Closing public without this line leaves the rules behind.
		 * - public itself carries the filter.
		 *
		 * The last three follow $public rather than being pinned false, so the
		 * two states are both coherent. Pinned false, a site that opened
		 * locations would get category archives that are publicly queryable but
		 * have no pretty permalink and cannot be linked from a menu — a
		 * half-open state nobody asked for. show_in_rest and show_admin_column
		 * are independent of public and stay on in both.
		 *
		 * @param bool $public Whether categories get front-end term archives.
		 * @return array
		 */
		private function taxonomy_args( bool $public ): array {
			return array(
				'labels'            => $this->taxonomy_labels(),
				'hierarchical'      => true,
				'public'            => $public,
				'show_ui'           => true,
				'show_in_nav_menus' => $public,
				'rewrite'           => $public,
				'show_in_rest'      => true,
				'show_admin_column' => true,
			);
		}

		/**
		 * Every label the taxonomy screens use.
		 *
		 * @return array
		 */
		private function taxonomy_labels(): array {
			return array(
				'name'                  => __( 'Location Categories', 'store-locator-for-openstreetmap' ),
				'singular_name'         => __( 'Location Category', 'store-locator-for-openstreetmap' ),
				'menu_name'             => __( 'Categories', 'store-locator-for-openstreetmap' ),
				'search_items'          => __( 'Search Location Categories', 'store-locator-for-openstreetmap' ),
				'all_items'             => __( 'All Location Categories', 'store-locator-for-openstreetmap' ),
				'parent_item'           => __( 'Parent Location Category', 'store-locator-for-openstreetmap' ),
				'parent_item_colon'     => __( 'Parent Location Category:', 'store-locator-for-openstreetmap' ),
				'edit_item'             => __( 'Edit Location Category', 'store-locator-for-openstreetmap' ),
				'view_item'             => __( 'View Location Category', 'store-locator-for-openstreetmap' ),
				'update_item'           => __( 'Update Location Category', 'store-locator-for-openstreetmap' ),
				'add_new_item'          => __( 'Add New Location Category', 'store-locator-for-openstreetmap' ),
				'new_item_name'         => __( 'New Location Category Name', 'store-locator-for-openstreetmap' ),
				'not_found'             => __( 'No location categories found.', 'store-locator-for-openstreetmap' ),
				'no_terms'              => __( 'No location categories', 'store-locator-for-openstreetmap' ),
				'back_to_items'         => __( 'Back to Location Categories', 'store-locator-for-openstreetmap' ),
				'items_list_navigation' => __( 'Location categories list navigation', 'store-locator-for-openstreetmap' ),
				'items_list'            => __( 'Location categories list', 'store-locator-for-openstreetmap' ),
			);
		}
	}
}
