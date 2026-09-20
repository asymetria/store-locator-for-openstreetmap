<?php
/**
 * Proves the screen an editor scans forty branches on.
 *
 * Three things are being pinned here, and they fail in different ways.
 *
 * The warning. A location with no usable coordinates is not on the map, and
 * there is no error state anywhere that says so: the payload simply skips it,
 * the browser draws one fewer marker, and the site looks fine. This list is the
 * only screen in the plugin where that fact can be seen at all, so every case
 * about the warning is a case about a silent failure. 0,0 counts as missing,
 * and so does half a pair — both of those are what a failed lookup and one
 * Backspace leave behind.
 *
 * The filter. "Find every location that is not on the map" is a question about
 * absent and empty meta, which is the one shape a meta_query gets wrong
 * quietly: the obvious spelling drops the rows that have no meta at all, which
 * is exactly the set being looked for. The fixture below holds all four
 * coordinate states and the two that sit on the boundary — a location on the
 * equator and one on the Greenwich meridian, both of which are real places and
 * neither of which is 0,0.
 *
 * Quick Edit. Admin::save() writes every field it knows about and takes an
 * absent input as an empty value, which is correct only because the metabox
 * nonce and the twelve inputs always travel together. This screen is where that
 * stops being true, so there is a case here that runs a Quick Edit save over a
 * complete location and reads every field back.
 *
 * @package Store_Locator_For_OpenStreetMap
 */

require_once dirname( __DIR__ ) . '/includes/class-store.php';
require_once dirname( __DIR__ ) . '/includes/class-post-type.php';
require_once dirname( __DIR__ ) . '/includes/class-store-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-geo.php';
require_once dirname( __DIR__ ) . '/includes/class-geocoder.php';
require_once dirname( __DIR__ ) . '/includes/class-settings.php';
require_once dirname( __DIR__ ) . '/includes/class-plugin.php';
require_once dirname( __DIR__ ) . '/admin/class-settings-screen.php';
require_once dirname( __DIR__ ) . '/admin/class-shortcode-generator.php';
require_once dirname( __DIR__ ) . '/includes/class-rest-controller.php';
require_once dirname( __DIR__ ) . '/includes/class-assets.php';
require_once dirname( __DIR__ ) . '/includes/class-shortcode.php';
require_once dirname( __DIR__ ) . '/admin/class-admin.php';
require_once dirname( __DIR__ ) . '/admin/class-locations-list.php';
require_once dirname( __DIR__ ) . '/admin/class-bulk-geocode.php';

use Asymetria\StoreLocator\Admin\Admin;
use Asymetria\StoreLocator\Admin\Locations_List;
use Asymetria\StoreLocator\Plugin;
use Asymetria\StoreLocator\Post_Type;
use Asymetria\StoreLocator\Store_Repository;

if ( ! function_exists( 'slosm_list_list' ) ) {
	/**
	 * A list-table object over a real repository.
	 *
	 * Real rather than a double, for the reason the whole suite gives:
	 * Store_Repository is final, and its seam is the loader it is built with.
	 * Nothing here reaches that loader — every read is by post id.
	 *
	 * @return Locations_List
	 */
	function slosm_list_list(): Locations_List {
		return new Locations_List( new Store_Repository() );
	}
}

if ( ! function_exists( 'slosm_list_stage' ) ) {
	/**
	 * Stages one location: the post row, and whatever meta it has.
	 *
	 * Meta is written through Store_Repository::META_KEYS rather than through
	 * literal keys, because the mapping is that class's knowledge. A field
	 * absent from $fields gets no meta row at all, which is what an import that
	 * never wrote one leaves behind and is a different state from an empty
	 * string. The filter has to tell those two apart, so the fixture has to be
	 * able to make both.
	 *
	 * Stub state is written, so this may only be called from inside it().
	 *
	 * @param int    $id     Post id.
	 * @param array  $fields Field name to stored value.
	 * @param string $status Post status; the list table lists drafts too.
	 * @param string $title  Post title.
	 * @return object The post row, so a case can pass it on.
	 */
	function slosm_list_stage( int $id, array $fields = array(), string $status = 'publish', string $title = 'Warszawa' ): object {
		$post = (object) array(
			'ID'           => $id,
			'post_title'   => $title,
			'post_content' => '',
			'post_type'    => Post_Type::POST_TYPE,
			'post_status'  => $status,
		);

		$GLOBALS['slosm_stub']['posts_by_id'][ $id ] = $post;

		foreach ( $fields as $field => $value ) {
			update_post_meta( $id, Store_Repository::META_KEYS[ $field ], $value );
		}

		return $post;
	}
}

if ( ! function_exists( 'slosm_list_cell' ) ) {
	/**
	 * The html one column prints for one row.
	 *
	 * @param string          $column Column name.
	 * @param int             $id     Post id.
	 * @param Locations_List  $list   The object to render with; a fresh one unless given.
	 * @return string
	 */
	function slosm_list_cell( string $column, int $id, ?Locations_List $list = null ): string {
		$list = $list ?? slosm_list_list();

		ob_start();
		$list->render_column( $column, $id );

		return (string) ob_get_clean();
	}
}

if ( ! function_exists( 'slosm_list_ask_unplaced' ) ) {
	/**
	 * Puts the unplaced view's argument in the query string, as a browser would.
	 *
	 * Slashed, because WordPress slashes $_GET before a plugin sees it exactly
	 * as it slashes $_POST — wp_magic_quotes() handles all four superglobals —
	 * so a reader that forgot wp_unslash() would look correct against a fixture
	 * that did not slash.
	 *
	 * @param mixed $value What the argument carries; null removes it.
	 * @return void
	 */
	function slosm_list_ask_unplaced( $value = '1' ): void {
		unset( $_GET[ Locations_List::UNPLACED_ARG ] );

		if ( null !== $value ) {
			$_GET[ Locations_List::UNPLACED_ARG ] = is_string( $value ) ? wp_slash( $value ) : $value;
		}
	}
}

if ( ! function_exists( 'slosm_list_query' ) ) {
	/**
	 * An admin main query for locations, of the shape wp_edit_posts_query() builds.
	 *
	 * @param array $vars  Query vars to start from.
	 * @param bool  $admin Whether the request is an admin one.
	 * @param bool  $main  Whether this is the main query.
	 * @return WP_Query
	 */
	function slosm_list_query( array $vars = array(), bool $admin = true, bool $main = true ): WP_Query {
		$GLOBALS['slosm_stub']['is_admin'] = $admin;

		return new WP_Query(
			array_merge( array( 'post_type' => Post_Type::POST_TYPE ), $vars ),
			$main
		);
	}
}

if ( ! function_exists( 'slosm_list_matches' ) ) {
	/**
	 * Whether one location's meta satisfies a meta_query.
	 *
	 * A model of MySQL, and it is worth being plain that it is one: this suite
	 * has no database, so nothing here can *measure* what the filter returns.
	 * What it can do is make the correspondence between the clauses and the
	 * predicate falsifiable — change a clause's key from lat to lng, or its
	 * compare from NOT EXISTS to EXISTS, and the four-state fixture below gives
	 * a different answer and the case fails.
	 *
	 * Three compares are modelled, which are the three the filter uses, and each
	 * is modelled the way WordPress makes MySQL evaluate it:
	 *
	 * - NOT EXISTS is `$alias.post_id IS NULL` behind a LEFT JOIN
	 *   (class-wp-meta-query.php of WordPress 6.9.1), so it is true exactly when
	 *   there is no row for that key.
	 * - '=' against '' is a string comparison against a joined row. WP_Meta_Query
	 *   turns every INNER JOIN into a LEFT JOIN as soon as one clause is a NOT
	 *   EXISTS, and `NULL = ''` is NULL rather than true, so a missing row does
	 *   not match.
	 * - '=' against 0 with type DECIMAL is `CAST($alias.meta_value AS
	 *   DECIMAL(10,6)) = 0`. MySQL casts a non-numeric string to 0, and
	 *   CAST(NULL) is NULL, so again a missing row does not match.
	 *
	 * Two things it does not represent, named so that a later clause set is not
	 * assumed covered by it
	 * ----------------------------------------------------------------------
	 * - **Table aliases.** In the real query, clauses joined by an OR share one
	 *   join whenever both compares are positive, whatever their keys —
	 *   find_compatible_table_alias() in class-wp-meta-query.php — so the two
	 *   empty-string clauses land on one alias and each names its own key in the
	 *   WHERE. This model evaluates every clause against the whole meta row set
	 *   independently, which gives the same answer here and would not for a
	 *   clause set that depended on two conditions meeting on one row. Nothing
	 *   in it does today; a future one might.
	 * - **Rounding.** DECIMAL(10,6) rounds to six decimal places and the PHP
	 *   comparison below does not, so a coordinate pair within about 5e-7
	 *   degrees of 0,0 — roughly four centimetres — is unplaced to the filter
	 *   and placed to the row warning. Nobody's shop is four centimetres off
	 *   Null Island, so this is recorded rather than fixed, but the model is not
	 *   byte-faithful to the generated SQL and should not be read as if it were.
	 *
	 * @param array $clauses A meta_query, or one nested group of one.
	 * @param array $meta    Meta key to value, as the stub holds it.
	 * @return bool
	 */
	function slosm_list_matches( array $clauses, array $meta ): bool {
		$relation = 'AND';
		$results  = array();

		foreach ( $clauses as $key => $clause ) {
			if ( 'relation' === $key ) {
				$relation = strtoupper( (string) $clause );
				continue;
			}

			if ( ! is_array( $clause ) ) {
				continue;
			}

			if ( ! isset( $clause['key'] ) ) {
				$results[] = slosm_list_matches( $clause, $meta );
				continue;
			}

			$results[] = slosm_list_matches_clause( $clause, $meta );
		}

		if ( array() === $results ) {
			return true;
		}

		foreach ( $results as $result ) {
			if ( 'OR' === $relation && $result ) {
				return true;
			}

			if ( 'OR' !== $relation && ! $result ) {
				return false;
			}
		}

		return 'OR' !== $relation;
	}
}

if ( ! function_exists( 'slosm_list_matches_clause' ) ) {
	/**
	 * One first-order meta clause against one location's meta.
	 *
	 * @param array $clause  The clause.
	 * @param array $meta    Meta key to value.
	 * @return bool
	 * @throws Exception When the clause uses a compare this model does not know.
	 */
	function slosm_list_matches_clause( array $clause, array $meta ): bool {
		$key     = (string) $clause['key'];
		$compare = strtoupper( (string) ( $clause['compare'] ?? '=' ) );
		$present = array_key_exists( $key, $meta );

		if ( 'NOT EXISTS' === $compare ) {
			return ! $present;
		}

		if ( 'EXISTS' === $compare ) {
			return $present;
		}

		if ( '=' !== $compare ) {
			throw new Exception( 'the model of MySQL here does not know the compare ' . $compare );
		}

		if ( ! $present ) {
			return false;
		}

		$value = (string) $meta[ $key ];

		if ( isset( $clause['type'] ) && 0 === strpos( strtoupper( (string) $clause['type'] ), 'DECIMAL' ) ) {
			return (float) $value === (float) $clause['value'];
		}

		return $value === (string) $clause['value'];
	}
}

describe(
	'the columns on the locations list',
	function () {

		it(
			'puts the warning, the address and the city between the title and the date',
			function () {
				$columns = slosm_list_list()->columns(
					array(
						'cb'                                 => '<input type="checkbox" />',
						'title'                              => 'Title',
						'taxonomy-' . Post_Type::TAXONOMY    => 'Location Categories',
						'date'                               => 'Date',
					)
				);

				// The whole order, not a handful of isset() checks. Where these
				// sit is the decision this case exists to hold: the name, then
				// whether the location is on the map at all, then what
				// identifies the branch, then how it is grouped, and the date
				// last because a location is not a thing anybody reads in the
				// order it was created.
				assert_same(
					array(
						'cb',
						'title',
						Locations_List::COLUMN_PLACEMENT,
						Locations_List::COLUMN_ADDRESS,
						Locations_List::COLUMN_CITY,
						'taxonomy-' . Post_Type::TAXONOMY,
						'date',
					),
					array_keys( $columns )
				);
			}
		);

		it(
			'moves the category column core already made rather than adding a second one',
			function () {
				$columns = slosm_list_list()->columns(
					array(
						'cb'                              => '<input type="checkbox" />',
						'title'                           => 'Title',
						'taxonomy-' . Post_Type::TAXONOMY => 'Location Categories',
						'date'                            => 'Date',
					)
				);

				// Post_Type registers the taxonomy with show_admin_column, so
				// WP_Posts_List_Table::get_columns() has already added this
				// column and rendered it from the primed term cache. A column of
				// this plugin's own would be a second Categories heading on the
				// same screen, and a second get_the_terms() per row to fill it.
				assert_same( 'Location Categories', $columns[ 'taxonomy-' . Post_Type::TAXONOMY ] );

				// Moved rather than left where core put it: core inserts the
				// taxonomy column straight after the title, which would separate
				// the name of the branch from the address of it.
				$keys = array_keys( $columns );
				$city = array_search( Locations_List::COLUMN_CITY, $keys, true );
				$term = array_search( 'taxonomy-' . Post_Type::TAXONOMY, $keys, true );

				assert_true( is_int( $city ) && is_int( $term ), 'one of the two columns is not on the screen at all' );
				assert_true( $city < $term, 'the category column was left where core put it, above the address' );

				$categories = 0;

				foreach ( array_keys( $columns ) as $key ) {
					if ( false !== stripos( $key, 'categor' ) || 'taxonomy-' . Post_Type::TAXONOMY === $key ) {
						++$categories;
					}
				}

				assert_same( 1, $categories, 'the list grew a second category column' );
			}
		);

		it(
			'puts them in its own order whatever order it was handed',
			function () {
				// The mutation sweep is what asked for this case. Against core's
				// own column list the reordering is invisible: core puts the
				// taxonomy column immediately after the title, which is where
				// this class's three go, so lifting it out and putting it back
				// lands it in the same place either way — and a mutation that
				// stopped lifting it, or that stopped inserting after the title
				// and let the columns fall on the end, changed nothing that a
				// case could see.
				//
				// What separates them is a column list somebody else has already
				// touched, which is the state this method is written for:
				// manage_{$post_type}_posts_columns is a filter, and what it is
				// handed is whatever every plugin above it returned. WPML's
				// language column and an SEO plugin's two are the ordinary case.
				$columns = slosm_list_list()->columns(
					array(
						'cb'                              => '<input type="checkbox" />',
						'taxonomy-' . Post_Type::TAXONOMY => 'Location Categories',
						'title'                           => 'Title',
						'other_plugin'                    => 'Language',
						'date'                            => 'Date',
					)
				);

				assert_same(
					array(
						'cb',
						'title',
						Locations_List::COLUMN_PLACEMENT,
						Locations_List::COLUMN_ADDRESS,
						Locations_List::COLUMN_CITY,
						'other_plugin',
						'taxonomy-' . Post_Type::TAXONOMY,
						'date',
					),
					array_keys( $columns )
				);

				// And nobody else's column is lost on the way.
				assert_same( 'Language', $columns['other_plugin'] );
			}
		);

		it(
			'keeps its three columns on a screen somebody took the title off',
			function () {
				$columns = slosm_list_list()->columns( array( 'cb' => '', 'date' => 'Date' ) );

				assert_same(
					array( 'cb', Locations_List::COLUMN_PLACEMENT, Locations_List::COLUMN_ADDRESS, Locations_List::COLUMN_CITY, 'date' ),
					array_keys( $columns )
				);
			}
		);

		it(
			'hands back a filtered value that is not a list of columns',
			function () {
				// manage_{$post_type}_posts_columns is a filter, and a filter
				// takes whatever the one above it returned. Casting a string to
				// an array here would put this plugin's three columns on a
				// screen whose column list another plugin had just broken, which
				// turns somebody else's bug into a fatal in this file.
				assert_same( 'not a list', slosm_list_list()->columns( 'not a list' ) );

				// The control: the same method does add columns to a real list.
				assert_true( isset( slosm_list_list()->columns( array( 'title' => 'Title' ) )[ Locations_List::COLUMN_CITY ] ) );
			}
		);
	}
);

describe(
	'what one row says',
	function () {

		it(
			'prints the address and the city of the location in that row',
			function () {
				slosm_list_stage( 7, array( 'address' => 'Nowy Świat 1', 'city' => 'Warszawa' ) );

				assert_contains( 'Nowy Świat 1', slosm_list_cell( Locations_List::COLUMN_ADDRESS, 7 ) );
				assert_contains( 'Warszawa', slosm_list_cell( Locations_List::COLUMN_CITY, 7 ) );
			}
		);

		it(
			'escapes what an editor typed rather than printing it as markup',
			function () {
				// A city is a text field an editor fills in, so it is a path from
				// a form to this screen's html. sanitize_text_field() on the way
				// in strips tags, which is why the escaping here is the second
				// line rather than the first — and why it has to hold on its own:
				// this row can also come from an import that never met the save
				// handler.
				slosm_list_stage(
					7,
					array(
						'city'    => '<script>alert(1)</script>',
						'address' => 'Bar "Pod Wierzbą" & <Pub>',
					)
				);

				$city = slosm_list_cell( Locations_List::COLUMN_CITY, 7 );

				assert_contains( '&lt;script&gt;', $city );
				assert_false( false !== strpos( $city, '<script>alert(1)</script>' ), 'a city was printed as markup' );

				$address = slosm_list_cell( Locations_List::COLUMN_ADDRESS, 7 );

				assert_contains( '&lt;Pub&gt;', $address );
				assert_contains( '&amp;', $address );
				assert_false( false !== strpos( $address, '<Pub>' ), 'an address was printed as markup' );
			}
		);

		it(
			'prints a dash for a field the location has not got',
			function () {
				slosm_list_stage( 7, array( 'city' => 'Warszawa' ) );

				// An empty cell in a column headed Address reads as the screen
				// being broken rather than as the location being incomplete.
				assert_same( '&#8212;', slosm_list_cell( Locations_List::COLUMN_ADDRESS, 7 ) );
				assert_same( 'Warszawa', slosm_list_cell( Locations_List::COLUMN_CITY, 7 ) );
			}
		);

		it(
			'says nothing at all for a column that is not one of its own',
			function () {
				slosm_list_stage( 7, array( 'city' => 'Warszawa' ) );

				// manage_{$post_type}_posts_custom_column fires for every column
				// on the screen, core's included. A callback that printed for all
				// of them would put the city into the Date cell.
				assert_same( '', slosm_list_cell( 'date', 7 ) );
				assert_same( '', slosm_list_cell( 'taxonomy-' . Post_Type::TAXONOMY, 7 ) );

				// The control, so this cannot be satisfied by printing nothing
				// anywhere.
				assert_same( 'Warszawa', slosm_list_cell( Locations_List::COLUMN_CITY, 7 ) );
			}
		);

		it(
			'prints nothing for a post of another type with the same id',
			function () {
				$GLOBALS['slosm_stub']['posts_by_id'][7] = (object) array(
					'ID'          => 7,
					'post_title'  => 'O nas',
					'post_type'   => 'page',
					'post_status' => 'publish',
				);
				update_post_meta( 7, Store_Repository::META_KEYS['city'], 'Warszawa' );

				// The hook is type-specific, so this cannot happen through
				// WordPress. It can happen through anything that calls the
				// callback itself, and the type check is one line against a
				// column that would otherwise read any post on the site.
				assert_same( '', slosm_list_cell( Locations_List::COLUMN_CITY, 7 ) );

				// The control: the same column, the same meta, a real location.
				slosm_list_stage( 8, array( 'city' => 'Warszawa' ) );

				assert_same( 'Warszawa', slosm_list_cell( Locations_List::COLUMN_CITY, 8 ) );
			}
		);

		it(
			'shows a draft location, because the list table lists drafts',
			function () {
				slosm_list_stage( 7, array( 'city' => 'Kraków' ), 'draft' );

				// Store_Repository::find_by_id() answers null for anything that
				// is not published, which is right for a public popup endpoint
				// and wrong for this screen: a draft location is the one an
				// editor is most likely to be working on, and its row would show
				// three empty cells.
				assert_same( 'Kraków', slosm_list_cell( Locations_List::COLUMN_CITY, 7 ) );
			}
		);

		it(
			'reads each location once, however many of its columns are on the screen',
			function () {
				slosm_list_stage( 7, array( 'address' => 'Nowy Świat 1', 'city' => 'Warszawa', 'lat' => '52.2297', 'lng' => '21.0122' ) );

				$list = slosm_list_list();

				foreach ( array( Locations_List::COLUMN_PLACEMENT, Locations_List::COLUMN_ADDRESS, Locations_List::COLUMN_CITY ) as $column ) {
					slosm_list_cell( $column, 7, $list );
				}

				// Building a Store reads the categories, and that read is
				// recorded. Three columns reading the record three times is
				// three times the work per row on a screen whose whole job is to
				// render twenty of them; on a site with an injected loader, or
				// any path core has not primed the term cache for, it is also
				// three queries.
				assert_same( 1, count( $GLOBALS['slosm_stub']['term_requests'] ), 'the row was read once per column rather than once' );
				assert_same( Post_Type::TAXONOMY, $GLOBALS['slosm_stub']['term_requests'][0]['taxonomy'] );
			}
		);

		it(
			'prints no styles of its own at all, now that there is a stylesheet',
			function () {
				$list = slosm_list_list();
				$html = '';

				for ( $id = 1; $id <= 20; $id++ ) {
					slosm_list_stage( $id, array( 'city' => 'Warszawa' ) );

					$html .= slosm_list_cell( Locations_List::COLUMN_PLACEMENT, $id, $list );
				}

				// This case used to assert *one* <style> block for a page of
				// rows, which was the once-per-request guard on
				// Locations_List::print_styles(). Task 21 moved those three
				// declarations into assets/css/admin.css and gave them a handle,
				// so the right number is now none: a screen that prints a style
				// block as well as enqueuing the stylesheet is a screen with two
				// places to change a colour.
				assert_same( 0, substr_count( $html, '<style' ), 'the list is still printing css into its cells' );
				assert_contains( 'slosm-list__unplaced', $html, 'the class the stylesheet styles is still on the cell' );

				// And twenty rows are twenty reads, not four hundred.
				assert_same( 20, count( $GLOBALS['slosm_stub']['term_requests'] ) );
			}
		);
	}
);

describe(
	'the warning that a location is not on the map',
	function () {

		it(
			'marks a location that has no coordinates at all',
			function () {
				slosm_list_stage( 7, array( 'city' => 'Warszawa' ) );

				$cell = slosm_list_cell( Locations_List::COLUMN_PLACEMENT, 7 );

				assert_contains( 'Not on the map', $cell );
				assert_contains( 'dashicons-warning', $cell );

				// The icon is decoration and the sentence is the message. An
				// icon a screen reader announces is an icon read out as its font
				// glyph, and an icon without a sentence beside it says nothing
				// at all on a page whose stylesheet failed to load.
				assert_contains( 'aria-hidden="true"', $cell );
			}
		);

		it(
			'marks half a pair, whichever half is missing',
			function () {
				slosm_list_stage( 7, array( 'lat' => '52.2297' ) );
				slosm_list_stage( 8, array( 'lng' => '21.0122' ) );

				// One Backspace in one coordinate field. The location cannot be
				// drawn, and nothing anywhere else on the site says so.
				assert_contains( 'Not on the map', slosm_list_cell( Locations_List::COLUMN_PLACEMENT, 7 ) );
				assert_contains( 'Not on the map', slosm_list_cell( Locations_List::COLUMN_PLACEMENT, 8 ) );
			}
		);

		it(
			'marks the 0,0 a failed lookup leaves behind',
			function () {
				slosm_list_stage( 7, array( 'lat' => '0', 'lng' => '0' ) );

				// A point in the Gulf of Guinea, about 700 km off the coast of
				// Ghana, and the one coordinate pair a location can hold that is
				// a real place and never the right one.
				assert_contains( 'Not on the map', slosm_list_cell( Locations_List::COLUMN_PLACEMENT, 7 ) );
			}
		);

		it(
			'marks a coordinate that is not a number',
			function () {
				slosm_list_stage( 7, array( 'lat' => '52,2297', 'lng' => '21,0122' ) );

				// What a Polish or German spreadsheet exports. Store reads it as
				// no coordinate rather than as 52, which is why this row is
				// unplaced rather than twenty-five kilometres out.
				assert_contains( 'Not on the map', slosm_list_cell( Locations_List::COLUMN_PLACEMENT, 7 ) );
			}
		);

		it(
			'leaves a placed location unmarked, and says where it is',
			function () {
				slosm_list_stage( 7, array( 'lat' => '52.2297', 'lng' => '21.0122' ) );

				$cell = slosm_list_cell( Locations_List::COLUMN_PLACEMENT, 7 );

				assert_false( false !== strpos( $cell, 'Not on the map' ), 'a placed location was marked unplaced' );
				assert_contains( '52.2297', $cell );
				assert_contains( '21.0122', $cell );
			}
		);

		it(
			'leaves the equator and the Greenwich meridian alone',
			function () {
				slosm_list_stage( 7, array( 'lat' => '0', 'lng' => '21.0122' ) );
				slosm_list_stage( 8, array( 'lat' => '52.2297', 'lng' => '0' ) );

				// Only the pair is refused. A location at 0,21 is in the Gulf of
				// Guinea and a location at 52,0 is in Cambridgeshire, and both
				// are places a shop can be; a warning keyed on either coordinate
				// being zero would mark every branch on the meridian.
				assert_false( false !== strpos( slosm_list_cell( Locations_List::COLUMN_PLACEMENT, 7 ), 'Not on the map' ), 'a location on the equator was marked unplaced' );
				assert_false( false !== strpos( slosm_list_cell( Locations_List::COLUMN_PLACEMENT, 8 ), 'Not on the map' ), 'a location on the meridian was marked unplaced' );

				// The control for both: an empty cell also contains no warning,
				// so the two assertions above have to be read beside a cell that
				// actually says where the location is.
				assert_contains( '0, 21.0122', slosm_list_cell( Locations_List::COLUMN_PLACEMENT, 7 ) );
				assert_contains( '52.2297, 0', slosm_list_cell( Locations_List::COLUMN_PLACEMENT, 8 ) );
			}
		);

		it(
			'writes the coordinates the way the edit screen writes them',
			function () {
				slosm_list_stage( 7, array( 'lat' => '52.22970000', 'lng' => '21.01220000' ) );

				// One formatter, not two. A list showing 52.2297000 beside an
				// edit screen showing 52.2297 reads as the list being wrong, and
				// a second formatter is a second thing to keep in step with
				// COORDINATE_DECIMALS.
				$cell = slosm_list_cell( Locations_List::COLUMN_PLACEMENT, 7 );

				assert_contains( '52.2297, 21.0122', $cell );
				assert_false( false !== strpos( $cell, '52.2297000' ), 'the list formats a coordinate its own way' );
			}
		);
	}
);

describe(
	'the view for the locations that are not on the map',
	function () {

		before_each(
			function () {
				$_GET = array();
			}
		);

		it(
			'adds a link beside the ones core already offers',
			function () {
				$views = slosm_list_list()->views( array( 'all' => '<a href="edit.php">All</a>' ) );

				// A view rather than a dropdown, so that the question "is
				// anything on this site invisible" is answerable by looking
				// rather than by opening a select and pressing Filter.
				assert_true( isset( $views['all'] ), 'the views core built were thrown away' );
				assert_true( isset( $views[ Locations_List::VIEW_KEY ] ) );

				$link = $views[ Locations_List::VIEW_KEY ];

				assert_contains( 'edit.php?post_type=slosm_store&#038;slosm_unplaced=1', $link );
				assert_contains( '>Not on the map</a>', $link );
			}
		);

		it(
			'marks itself current only when it is the view being looked at',
			function () {
				assert_false(
					false !== strpos( slosm_list_list()->views( array() )[ Locations_List::VIEW_KEY ], 'aria-current' ),
					'the view called itself current on the All screen'
				);

				slosm_list_ask_unplaced();

				assert_contains( 'aria-current="page"', slosm_list_list()->views( array() )[ Locations_List::VIEW_KEY ] );
			}
		);

		it(
			'hands back a filtered value that is not a list of views',
			function () {
				assert_same( 'not a list', slosm_list_list()->views( 'not a list' ) );

				// The control.
				assert_true( isset( slosm_list_list()->views( array() )[ Locations_List::VIEW_KEY ] ) );
			}
		);
	}
);

describe(
	'narrowing the list to the unplaced locations',
	function () {

		before_each(
			function () {
				$_GET = array();
			}
		);

		it(
			'narrows the main admin query when the view is asked for',
			function () {
				slosm_list_ask_unplaced();

				$query = slosm_list_query();

				slosm_list_list()->filter_query( $query );

				$meta = $query->get( 'meta_query' );

				assert_true( is_array( $meta ) && array() !== $meta, 'the query was not narrowed at all' );
			}
		);

		it(
			'finds exactly the unplaced ones, against a fixture of every state',
			function () {
				slosm_list_ask_unplaced();

				$query = slosm_list_query();

				slosm_list_list()->filter_query( $query );

				$meta = $query->get( 'meta_query' );

				$lat = Store_Repository::META_KEYS['lat'];
				$lng = Store_Repository::META_KEYS['lng'];

				// Six locations. Four states of a coordinate pair, plus the two
				// that sit on the boundary of the 0,0 rule and are real places.
				$fixture = array(
					'placed'       => array( array( $lat => '52.2297', $lng => '21.0122' ), false ),
					'no meta'      => array( array(), true ),
					// Both halves, and both are needed. A fixture with only the
					// lat-only row cannot tell a clause that looks for a
					// missing latitude from one that looks for a missing
					// longitude twice; the sweep found exactly that.
					'half a pair'  => array( array( $lat => '52.2297' ), true ),
					'the other half' => array( array( $lng => '21.0122' ), true ),
					'0,0'          => array( array( $lat => '0', $lng => '0' ), true ),
					// The same place, written the way an import writes it. The
					// clause compares as DECIMAL rather than as a string for
					// exactly this: '0' is what Admin::coordinate_string()
					// produces, and '0.0000000' and '-0' are what everything
					// else produces, and a string comparison would find only the
					// first of the three.
					'0,0 spelled out' => array( array( $lat => '0.0000000', $lng => '-0' ), true ),
					'the equator'  => array( array( $lat => '0', $lng => '21.0122' ), false ),
					'the meridian' => array( array( $lat => '52.2297', $lng => '0' ), false ),
				);

				foreach ( $fixture as $name => $case ) {
					list( $stored, $expected ) = $case;

					assert_same(
						$expected,
						slosm_list_matches( $meta, $stored ),
						$name . ' was ' . ( $expected ? 'not found' : 'found' ) . ' by the unplaced filter'
					);
				}
			}
		);

		it(
			'finds a location whose coordinates were emptied rather than deleted',
			function () {
				slosm_list_ask_unplaced();

				$query = slosm_list_query();

				slosm_list_list()->filter_query( $query );

				$lat = Store_Repository::META_KEYS['lat'];
				$lng = Store_Repository::META_KEYS['lng'];

				// This is what the metabox itself writes: storable() turns a null
				// coordinate into '' rather than deleting the row, so an editor
				// who cleared both fields leaves two empty strings and not two
				// missing keys. A filter written only around NOT EXISTS would
				// miss every location this plugin has ever unplaced.
				assert_true( slosm_list_matches( $query->get( 'meta_query' ), array( $lat => '', $lng => '' ) ) );

				// Both ways round, for the reason the four-state fixture has
				// both halves of a pair: one of these alone cannot tell the
				// latitude's clause from the longitude's.
				assert_true( slosm_list_matches( $query->get( 'meta_query' ), array( $lat => '', $lng => '21.0122' ) ) );
				assert_true( slosm_list_matches( $query->get( 'meta_query' ), array( $lat => '52.2297', $lng => '' ) ) );
			}
		);

		it(
			'finds a pair of coordinates that are text casting to zero',
			function () {
				slosm_list_ask_unplaced();

				$query = slosm_list_query();

				slosm_list_list()->filter_query( $query );

				$lat = Store_Repository::META_KEYS['lat'];
				$lng = Store_Repository::META_KEYS['lng'];

				// The edge below is narrower than "a coordinate holding text",
				// and this case is the half that says so. MySQL casts a
				// non-numeric string to 0, so a pair of words is caught by the
				// same nested AND that catches 0,0 — and so is a word beside an
				// empty string, twice over.
				assert_true( slosm_list_matches( $query->get( 'meta_query' ), array( $lat => 'brak', $lng => 'brak' ) ) );
				assert_true( slosm_list_matches( $query->get( 'meta_query' ), array( $lat => 'brak', $lng => '0' ) ) );
			}
		);

		it(
			'does not find a coordinate whose text casts to something other than zero, and that is the known edge',
			function () {
				slosm_list_ask_unplaced();

				$query = slosm_list_query();

				slosm_list_list()->filter_query( $query );

				$lat = Store_Repository::META_KEYS['lat'];
				$lng = Store_Repository::META_KEYS['lng'];

				// The other half of the line, and the exact shape of it. CAST
				// takes a leading numeric prefix, so '52,2297' casts to 52 rather
				// than to 0, the nested AND fails, and this pair is missed —
				// while Store calls it unplaced and the row is marked with the
				// warning. So the escaping set is not "text" but "text casting to
				// something other than zero in at least one half", which is this
				// one shape and only it. Closing it means a REGEXP over
				// meta_value, a second full scan per coordinate, and still not
				// is_numeric(). Written down as a decision rather than left to be
				// discovered.
				assert_false( slosm_list_matches( $query->get( 'meta_query' ), array( $lat => '52,2297', $lng => '21,0122' ) ) );

				// Even one half is enough to escape, which is what makes "at
				// least one" the right words.
				assert_false( slosm_list_matches( $query->get( 'meta_query' ), array( $lat => '52,2297', $lng => '0' ) ) );

				// The control: the row itself does say so.
				slosm_list_stage( 7, array( 'lat' => '52,2297', 'lng' => '21,0122' ) );
				assert_contains( 'Not on the map', slosm_list_cell( Locations_List::COLUMN_PLACEMENT, 7 ) );
			}
		);

		it(
			'takes the one value it wrote and nothing else',
			function () {
				foreach ( array( '0', 'yes', '', '1 ', '<script>alert(1)</script>', array( '1' ) ) as $value ) {
					slosm_list_ask_unplaced( $value );

					$query = slosm_list_query();

					slosm_list_list()->filter_query( $query );

					assert_same(
						'',
						$query->get( 'meta_query' ),
						'the filter answered to ' . ( is_array( $value ) ? 'an array' : '"' . $value . '"' )
					);
				}

				// The control, with the same fixture and the one value that is
				// the filter's own.
				slosm_list_ask_unplaced( '1' );

				$query = slosm_list_query();

				slosm_list_list()->filter_query( $query );

				assert_true( is_array( $query->get( 'meta_query' ) ) );
			}
		);

		it(
			'leaves every query that is not this screen alone',
			function () {
				slosm_list_ask_unplaced();

				// Built one at a time rather than into an array, because
				// slosm_list_query() sets the is_admin stub as it goes: three
				// queries built up front would all be answered by whatever the
				// last of them said.
				$cases = array(
					'a front-end query'          => array( array(), false, true ),
					'a secondary admin query'    => array( array(), true, false ),
					"another post type's screen" => array( array( 'post_type' => 'post' ), true, true ),
				);

				foreach ( $cases as $name => $arguments ) {
					$query = slosm_list_query( $arguments[0], $arguments[1], $arguments[2] );

					slosm_list_list()->filter_query( $query );

					assert_same( '', $query->get( 'meta_query' ), $name . ' was narrowed' );
				}

				// The control: the same argument, on the query this screen runs.
				$query = slosm_list_query();

				slosm_list_list()->filter_query( $query );

				assert_true( is_array( $query->get( 'meta_query' ) ) );
			}
		);

		it(
			'leaves the query alone when nobody asked for the view',
			function () {
				$query = slosm_list_query();

				slosm_list_list()->filter_query( $query );

				// pre_get_posts fires on every query on the site. Doing nothing
				// is what this callback does almost every time it runs, and it
				// has to do it before it looks anything up.
				assert_same( '', $query->get( 'meta_query' ) );
				assert_same( array( 'post_type' => Post_Type::POST_TYPE ), $query->query_vars );

				// The control: the same query, with the argument in the url.
				slosm_list_ask_unplaced();

				slosm_list_list()->filter_query( $query );

				assert_true( is_array( $query->get( 'meta_query' ) ) );
			}
		);

		it(
			'keeps a meta query that was already on the query',
			function () {
				slosm_list_ask_unplaced();

				$existing = array( array( 'key' => '_other_plugin', 'compare' => 'EXISTS' ) );

				$query = slosm_list_query( array( 'meta_query' => $existing ) );

				slosm_list_list()->filter_query( $query );

				$meta = $query->get( 'meta_query' );

				// Another plugin narrowing this screen, or a site's own
				// pre_get_posts. Overwriting it would widen somebody else's
				// filter silently, which on a membership plugin is a list of
				// locations an editor is not supposed to see.
				assert_same( 'AND', $meta['relation'] );
				assert_same( $existing, $meta[0] );

				assert_true(
					slosm_list_matches( $meta, array( '_other_plugin' => 'x' ) ),
					'a location the other filter keeps and this one keeps was dropped'
				);
				assert_false(
					slosm_list_matches( $meta, array() ),
					'a location the other filter drops survived'
				);
			}
		);

		it(
			'is handed something that is not a query and does nothing',
			function () {
				slosm_list_ask_unplaced();

				// pre_get_posts is an action, and an action's argument is
				// whatever fired it. Nothing here may be a fatal inside a hook
				// that runs on every query on the site.
				slosm_list_list()->filter_query( null );
				slosm_list_list()->filter_query( 'edit.php' );
				slosm_list_list()->filter_query( (object) array( 'query_vars' => array() ) );

				// The control.
				$query = slosm_list_query();

				slosm_list_list()->filter_query( $query );

				assert_true( is_array( $query->get( 'meta_query' ) ) );
			}
		);
	}
);

describe(
	'sorting the locations list',
	function () {

		before_each(
			function () {
				$_GET = array();
			}
		);

		it(
			'offers the city column, and nothing else of its own',
			function () {
				$sortable = slosm_list_list()->sortable_columns( array( 'title' => array( 'title', false ) ) );

				assert_same( array( Locations_List::SORT_CITY, false ), $sortable[ Locations_List::COLUMN_CITY ] );

				// The address is deliberately not here. Sorting forty branches
				// by street name puts Aleje beside Armii and tells nobody
				// anything, and every meta sort costs the join and the filesort
				// below.
				assert_false( isset( $sortable[ Locations_List::COLUMN_ADDRESS ] ), 'the address was made sortable' );
				assert_false( isset( $sortable[ Locations_List::COLUMN_PLACEMENT ] ), 'the coordinates were made sortable' );

				// Core's own stay.
				assert_same( array( 'title', false ), $sortable['title'] );
			}
		);

		it(
			'sorts by the city without dropping the locations that have none',
			function () {
				$query = slosm_list_query( array( 'orderby' => Locations_List::SORT_CITY ) );

				slosm_list_list()->filter_query( $query );

				$meta = $query->get( 'meta_query' );
				$key  = Store_Repository::META_KEYS['city'];

				// WP_Query sorts by meta through meta_key plus
				// orderby => meta_value, and that spelling is an INNER JOIN:
				// WP_Meta_Query's own comment at wp-includes/class-wp-meta-query.php
				// says "Otherwise posts with no metadata will be excluded from
				// results". On this screen that is every location an import
				// never gave a city, disappearing from the list the moment
				// somebody clicks the column heading, with nothing to say so.
				// The EXISTS/NOT EXISTS pair is what turns every join in the
				// query LEFT, which is the same comment's other half.
				assert_same( 'OR', $meta['relation'] );
				assert_same( array( 'key' => $key, 'compare' => 'EXISTS' ), $meta[ Locations_List::SORT_CITY ] );
				assert_same( array( 'key' => $key, 'compare' => 'NOT EXISTS' ), $meta[0] );

				assert_true( slosm_list_matches( $meta, array( $key => 'Warszawa' ) ) );
				assert_true( slosm_list_matches( $meta, array() ), 'a location with no city was dropped from the sorted list' );
			}
		);

		it(
			'names the clause the same thing the column heading asks for',
			function () {
				$query = slosm_list_query( array( 'orderby' => Locations_List::SORT_CITY ) );

				slosm_list_list()->filter_query( $query );

				// WP_Query::parse_orderby() allows an orderby that is the key of
				// a meta_query clause and turns it into
				// CAST(alias.meta_value AS CHAR). That is the whole mechanism,
				// and it works only because the name the column heading puts in
				// the url is the name the clause carries — so the orderby is
				// deliberately left as it arrived rather than rewritten.
				assert_same( Locations_List::SORT_CITY, $query->get( 'orderby' ) );
				assert_true( array_key_exists( Locations_List::SORT_CITY, $query->get( 'meta_query' ) ) );
			}
		);

		it(
			'leaves an orderby that is not its own alone',
			function () {
				foreach ( array( 'title', 'date', '', 'meta_value', Store_Repository::META_KEYS['city'] ) as $orderby ) {
					$query = slosm_list_query( array( 'orderby' => $orderby ) );

					slosm_list_list()->filter_query( $query );

					assert_same( '', $query->get( 'meta_query' ), 'ordering by ' . $orderby . ' grew a meta query' );
					assert_same( $orderby, $query->get( 'orderby' ) );
				}

				// The control.
				$query = slosm_list_query( array( 'orderby' => Locations_List::SORT_CITY ) );

				slosm_list_list()->filter_query( $query );

				assert_true( is_array( $query->get( 'meta_query' ) ) );
			}
		);

		it(
			'gives no clauses for a field the mapping has never heard of',
			function () {
				$repository = new Store_Repository();

				// A public method's contract, made falsifiable. Nothing in the
				// plugin passes anything but 'city' today, so the guard is
				// unreachable from the screen — which is exactly what the
				// mutation sweep reported, and the honest answer to it is a case
				// against the method rather than a guard nothing can check.
				// Without it, self::META_KEYS['town'] is an undefined array key
				// and a clause whose key is null.
				assert_same( array(), $repository->sortable_meta_query( 'town', Locations_List::SORT_CITY ) );
				assert_same( array(), $repository->sortable_meta_query( 'city', '' ) );

				// The control.
				assert_true( array_key_exists( Locations_List::SORT_CITY, $repository->sortable_meta_query( 'city', Locations_List::SORT_CITY ) ) );
			}
		);

		it(
			'sorts and filters at once without either losing the other',
			function () {
				slosm_list_ask_unplaced();

				$query = slosm_list_query( array( 'orderby' => Locations_List::SORT_CITY ) );

				slosm_list_list()->filter_query( $query );

				$meta = $query->get( 'meta_query' );
				$city = Store_Repository::META_KEYS['city'];
				$lat  = Store_Repository::META_KEYS['lat'];
				$lng  = Store_Repository::META_KEYS['lng'];

				assert_same( 'AND', $meta['relation'] );

				// The sort clause has to stay a clause of its own with its own
				// name, or parse_orderby() has nothing to order by and the
				// heading silently does nothing.
				assert_true( array_key_exists( Locations_List::SORT_CITY, $meta[0] ) );

				// An unplaced location with no city is in both halves.
				assert_true( slosm_list_matches( $meta, array() ) );

				// A placed location is out, whatever its city.
				assert_false( slosm_list_matches( $meta, array( $city => 'Warszawa', $lat => '52.2297', $lng => '21.0122' ) ) );

				// An unplaced one with a city is in.
				assert_true( slosm_list_matches( $meta, array( $city => 'Warszawa', $lat => '', $lng => '' ) ) );
			}
		);
	}
);

describe(
	'Quick Edit and Bulk Edit, which this screen carries and does not touch',
	function () {

		before_each(
			function () {
				$_GET  = array();
				$_POST = array();
			}
		);

		it(
			'prints no field into Quick Edit and no save nonce anywhere',
			function () {
				$list = slosm_list_list();

				$html = '';

				foreach ( array( Locations_List::COLUMN_PLACEMENT, Locations_List::COLUMN_ADDRESS, Locations_List::COLUMN_CITY ) as $column ) {
					slosm_list_stage( 7, array( 'city' => 'Warszawa' ) );

					$html .= slosm_list_cell( $column, 7, $list );
				}

				$html .= (string) wp_json_encode( $list->columns( array( 'title' => 'Title' ) ) );
				$html .= (string) wp_json_encode( $list->views( array() ) );

				// The screen rendered something, so the two absences below are
				// about what it prints rather than about it printing nothing.
				assert_contains( 'Warszawa', $html );
				assert_contains( Locations_List::UNPLACED_ARG, $html );

				// Admin::save() writes every field it knows about and reads an
				// absent input as an empty value. That is safe only because the
				// metabox nonce and the twelve inputs always travel together, so
				// a partial form carrying this nonce would blank the address, the
				// city and the phone number and clear the coordinates — on every
				// location in a bulk selection, silently. Nothing this screen
				// prints carries it.
				assert_false( false !== strpos( $html, Admin::NONCE_FIELD ), 'the list table printed the metabox save nonce' );
				assert_false( false !== strpos( $html, Admin::NONCE_ACTION ), 'the list table printed the metabox nonce action' );

				// The control: the metabox does print it, so the assertion above
				// is about this screen rather than about the string not existing.
				ob_start();
				( new Admin( new Store_Repository() ) )->render( $GLOBALS['slosm_stub']['posts_by_id'][7] );
				$box = (string) ob_get_clean();

				assert_contains( Admin::NONCE_FIELD, $box );
			}
		);

		it(
			'leaves every field of a location alone when Quick Edit saves it',
			function () {
				$fields = array(
					'address' => 'Nowy Świat 1',
					'city'    => 'Warszawa',
					'phone'   => '+48 22 000 00 00',
					'lat'     => '52.2297',
					'lng'     => '21.0122',
				);

				$post = slosm_list_stage( 7, $fields );

				// What core's Quick Edit posts: its own nonce, its own fields,
				// and not one of this plugin's. inline-save then calls
				// edit_post(), which fires save_post_slosm_store, which is the
				// hook Admin::save() is on.
				$GLOBALS['slosm_stub']['capabilities']['edit_post'] = true;
				$GLOBALS['slosm_stub']['current_user_id']          = 1;

				$_POST = array(
					'_inline_edit' => 'nonce:inlineeditnonce',
					'action'       => 'inline-save',
					'post_ID'      => 7,
					'post_title'   => 'Warszawa',
					'post_status'  => 'publish',
				);

				try {
					( new Admin( new Store_Repository() ) )->save( 7, $post );
				} finally {
					$_POST = array();
				}

				$stored = ( new Store_Repository() )->to_store( $post )->to_full_array();

				foreach ( array( 'address', 'city', 'phone' ) as $field ) {
					assert_same( $fields[ $field ], $stored[ $field ], $field . ' was blanked by a Quick Edit save' );
				}

				assert_same( 52.2297, $stored['lat'] );
				assert_same( 21.0122, $stored['lng'] );
			}
		);
	}
);

describe(
	'the locations list wiring',
	function () {

		it(
			'is wired from boot(), on the list table hooks and no others',
			function () {
				$construct = Closure::bind(
					static function () {
						return new Plugin();
					},
					null,
					Plugin::class
				);

				$plugin = $construct();
				$plugin->boot();

				$registered = array();

				foreach ( array( 'actions', 'filters' ) as $kind ) {
					foreach ( $GLOBALS['slosm_stub'][ $kind ] as $hook => $callbacks ) {
						foreach ( $callbacks as $callback ) {
							if ( is_array( $callback['callback'] ) && $callback['callback'][0] instanceof Locations_List ) {
								$registered[ $hook ] = $callback;
							}
						}
					}
				}

				$hooks = array_keys( $registered );

				sort( $hooks );

				// Six, and exactly these six. Two of the names are the screen
				// id rather than the post type — WP_List_Table::get_column_info()
				// fires manage_{$this->screen->id}_sortable_columns and
				// WP_List_Table::views() fires views_{$this->screen->id}, and a
				// list table's screen id is 'edit-' plus the post type. Written
				// with the post type alone they are filters that never fire and
				// nothing anywhere says so.
				//
				// The sixth is Task 20's: restrict_manage_posts, which is where
				// the hidden input that keeps the unplaced view across this
				// screen's own GET form is printed. It is this class's hook
				// rather than Bulk_Geocode's because the argument is this
				// class's; keep_view() has the trade it carries.
				assert_same(
					array(
						'manage_edit-slosm_store_sortable_columns',
						'manage_slosm_store_posts_columns',
						'manage_slosm_store_posts_custom_column',
						'pre_get_posts',
						'restrict_manage_posts',
						'views_edit-slosm_store',
					),
					$hooks,
					'the list table is hooked somewhere else, or not everywhere it needs to be'
				);

				// Two arguments, because the column callback is handed the column
				// name and the post id and one of them alone is useless.
				assert_same( 2, $registered['manage_slosm_store_posts_custom_column']['accepted_args'] );
				assert_same( 'render_column', $registered['manage_slosm_store_posts_custom_column']['callback'][1] );

				// The column filters are type-specific, so no other post type on
				// the site grows an Address column.
				assert_same( 'columns', $registered['manage_slosm_store_posts_columns']['callback'][1] );
				assert_same( 'sortable_columns', $registered['manage_edit-slosm_store_sortable_columns']['callback'][1] );
				assert_same( 'views', $registered['views_edit-slosm_store']['callback'][1] );
				assert_same( 'filter_query', $registered['pre_get_posts']['callback'][1] );

				// Two arguments on restrict_manage_posts: the post type says
				// whether this screen is ours at all, and 'top' or 'bottom' is
				// what stops the hidden input being printed twice into one form.
				assert_same( 'keep_view', $registered['restrict_manage_posts']['callback'][1] );
				assert_same( 2, $registered['restrict_manage_posts']['accepted_args'] );
			}
		);

		it(
			'asks for nothing to be loaded on the list table',
			function () {
				// Assets::PICKER_HOOKS is named for the picker and not for the
				// plugin, because this is the task that wants assets on edit.php.
				// It does not: three declarations of CSS go inline beside the
				// first warning, and there is no script at all. A handle here
				// would put Leaflet and the metabox picker on a screen with
				// neither a map nor a coordinate field on it.
				assert_false(
					in_array( 'edit.php', \Asymetria\StoreLocator\Assets::PICKER_HOOKS, true ),
					'the list table was added to the picker screens'
				);

				slosm_list_stage( 7, array( 'city' => 'Warszawa' ) );

				assert_contains( 'Not on the map', slosm_list_cell( Locations_List::COLUMN_PLACEMENT, 7 ) );

				assert_same( array(), $GLOBALS['slosm_stub']['script_queue'] );
				assert_same( array(), $GLOBALS['slosm_stub']['style_queue'] );

				// The control: the queues are recorded, and the picker screen
				// fills them. Without this the two assertions above would pass
				// against a stub that recorded nothing at all.
				slosm_stub_screen( 'post', Post_Type::POST_TYPE );

				( new \Asymetria\StoreLocator\Assets() )->enqueue_admin( 'post.php' );

				assert_true( in_array( \Asymetria\StoreLocator\Assets::SCRIPT_ADMIN, $GLOBALS['slosm_stub']['script_queue'], true ) );
			}
		);
	}
);
