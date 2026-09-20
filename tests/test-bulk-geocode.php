<?php
/**
 * Proves the bulk action that looks up a page of addresses.
 *
 * Four things are pinned here and they fail in different ways.
 *
 * **The limiter.** Geocoder sleeps a second between upstream requests by
 * policy, so a selection of fifty is fifty seconds in one admin request. The
 * cases below hold the budget from both ends — a run that stops and names what
 * it did not reach, and a run that never stops because the clock never moved —
 * plus the arithmetic that fits the budget to the host's own
 * max_execution_time. A limiter that quietly stopped limiting looks exactly
 * like a fast site until the service blocks the IP.
 *
 * **The lock.** A pin an editor dragged into the right doorway is the one thing
 * this run can destroy, and there is no error state and no way back. The case
 * counts requests rather than comparing coordinates, because a second lookup of
 * the same address returns the same point and a comparison would pass with the
 * guard deleted.
 *
 * **The report.** An earlier plugin by this author shipped a bulk importer that
 * said "done" while silently skipping rows. So there is a case that runs every
 * outcome at once and reads the rendered notices back, and it asserts the
 * sentences are *distinct* — a report that folded two of them together would
 * count the right number of locations and tell the editor the wrong thing to do
 * about them.
 *
 * **The nonce that must not be involved.** Admin::save() writes every field it
 * knows about and reads an absent input as empty, which is safe only because
 * its nonce and its twelve inputs always travel together. A case posts the
 * metabox nonce at this action and reads every field of a complete location
 * back afterwards.
 *
 * @package Store_Locator_For_OpenStreetMap
 */

require_once dirname( __DIR__ ) . '/includes/class-store.php';
require_once dirname( __DIR__ ) . '/includes/class-post-type.php';
require_once dirname( __DIR__ ) . '/includes/class-store-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-geo.php';
require_once dirname( __DIR__ ) . '/includes/class-geocoder.php';
require_once dirname( __DIR__ ) . '/includes/class-rest-controller.php';
require_once dirname( __DIR__ ) . '/includes/class-assets.php';
require_once dirname( __DIR__ ) . '/includes/class-shortcode.php';
require_once dirname( __DIR__ ) . '/includes/class-settings.php';
require_once dirname( __DIR__ ) . '/includes/class-plugin.php';
require_once dirname( __DIR__ ) . '/admin/class-settings-screen.php';
require_once dirname( __DIR__ ) . '/admin/class-shortcode-generator.php';
require_once dirname( __DIR__ ) . '/admin/class-admin.php';
require_once dirname( __DIR__ ) . '/admin/class-locations-list.php';
require_once dirname( __DIR__ ) . '/admin/class-bulk-geocode.php';

use Asymetria\StoreLocator\Admin\Admin;
use Asymetria\StoreLocator\Admin\Bulk_Geocode;
use Asymetria\StoreLocator\Admin\Locations_List;
use Asymetria\StoreLocator\Geocoder;
use Asymetria\StoreLocator\Plugin;
use Asymetria\StoreLocator\Post_Type;
use Asymetria\StoreLocator\Store_Repository;

if ( ! defined( 'SLOSM_BULK_SENDBACK' ) ) {
	/**
	 * The url wp-admin/edit.php hands the filter.
	 *
	 * What core builds out of wp_get_referer() when an editor presses Apply on
	 * the plain list: the screen they came from, with core's own bulk arguments
	 * stripped off afterwards.
	 */
	define( 'SLOSM_BULK_SENDBACK', 'https://example.test/wp-admin/edit.php?post_type=slosm_store' );
}

if ( ! function_exists( 'slosm_bulk_action' ) ) {
	/**
	 * A bulk action over a real repository and a geocoder on the stub clock.
	 *
	 * Real rather than doubles, for the reason the whole suite gives: both
	 * classes are final and their seams are the callables they are built with.
	 * Production defaults to microtime( true ) and usleep(), and a case using
	 * those would block for a real second the second time a run looked anything
	 * up.
	 *
	 * The run's own clock is the stub clock too unless a case passes one, so
	 * that the budget is measured against the same time the limiter's sleeps
	 * advance.
	 *
	 * @param Store_Repository|null $repository Repository to use; a fresh one unless given.
	 * @param callable|null         $clock      The run's clock; the stub clock unless given.
	 * @return Bulk_Geocode
	 */
	function slosm_bulk_action( ?Store_Repository $repository = null, ?callable $clock = null ): Bulk_Geocode {
		return new Bulk_Geocode(
			$repository ?? new Store_Repository(),
			new Geocoder( 'slosm_stub_time', 'slosm_stub_sleep' ),
			$clock ?? 'slosm_stub_time'
		);
	}
}

if ( ! function_exists( 'slosm_bulk_stage' ) ) {
	/**
	 * Stages one location: the post row, and whatever meta it has.
	 *
	 * Meta goes through Store_Repository::META_KEYS rather than literal keys,
	 * because where a field lives is that class's knowledge. A field absent from
	 * $fields gets no meta row at all, which is what an import leaves behind and
	 * is a different state from an empty string.
	 *
	 * Stub state is written, so this may only be called from inside it().
	 *
	 * @param int    $id     Post id.
	 * @param array  $fields Field name to stored value.
	 * @param string $title  Post title.
	 * @param string $status Post status; the list table lists drafts too.
	 * @return object The post row.
	 */
	function slosm_bulk_stage( int $id, array $fields = array(), string $title = 'Warszawa', string $status = 'publish' ): object {
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

if ( ! function_exists( 'slosm_bulk_stored' ) ) {
	/**
	 * What is in the database for one location now, as a full record.
	 *
	 * Read back through the repository, so a case asserting on a written value
	 * is asserting that the front end will read it, not merely that some row
	 * landed in some meta key.
	 *
	 * @param int $id Post id.
	 * @return array
	 */
	function slosm_bulk_stored( int $id ): array {
		return ( new Store_Repository() )->to_store( $GLOBALS['slosm_stub']['posts_by_id'][ $id ] )->to_full_array();
	}
}

if ( ! function_exists( 'slosm_bulk_run' ) ) {
	/**
	 * Fills the request the way edit.php would and runs the handler.
	 *
	 * The nonce is core's bulk one and it is slashed on the way in, because
	 * WordPress slashes $_REQUEST before any plugin sees it — wp_magic_quotes()
	 * in wp-includes/load.php — and a fixture that did not would make a handler
	 * that forgot wp_unslash() look correct.
	 *
	 * Both superglobals are emptied again in a finally, so no case can leak a
	 * request into the next one or into another file.
	 *
	 * Options: 'nonce' (null omits the field), 'capability' (false withholds the
	 * screen one), 'items' (an array of ids this user may edit, true for all,
	 * false for none), 'user', 'unplaced' (puts the view's argument in $_GET),
	 * 'action', 'sendback', 'bulk' and 'extra' (more $_REQUEST fields, such as
	 * the metabox nonce).
	 *
	 * Stub state is written, so this may only be called from inside it().
	 *
	 * @param array $ids     The selected ids, as core hands them over.
	 * @param array $options See above.
	 * @return string What the handler wants core to redirect to.
	 */
	function slosm_bulk_run( array $ids, array $options = array() ): string {
		$GLOBALS['slosm_stub']['current_user_id'] = $options['user'] ?? 1;

		if ( ! array_key_exists( 'capability', $options ) || false !== $options['capability'] ) {
			$GLOBALS['slosm_stub']['capabilities'][ Bulk_Geocode::SCREEN_CAPABILITY ] = true;
		}

		$items = $options['items'] ?? true;

		if ( false !== $items ) {
			$GLOBALS['slosm_stub']['capabilities'][ Bulk_Geocode::ITEM_CAPABILITY ] = $items;
		}

		$_REQUEST = array();
		$_GET     = array();

		if ( ! array_key_exists( 'nonce', $options ) ) {
			// The literal, not Bulk_Geocode::NONCE_ACTION. 'bulk-posts' is
			// core's string — WP_Posts_List_Table is constructed with
			// 'plural' => 'posts' and WP_List_Table::display_tablenav() prints
			// wp_nonce_field( 'bulk-' . $plural ) — so a fixture that built it
			// out of the constant would follow the constant anywhere it was
			// moved, and every case here would keep passing against a nonce
			// action no form on the site ever issues.
			$_REQUEST['_wpnonce'] = wp_slash( 'nonce:bulk-posts' );
		} elseif ( null !== $options['nonce'] ) {
			$_REQUEST['_wpnonce'] = $options['nonce'];
		}

		foreach ( $options['extra'] ?? array() as $key => $value ) {
			$_REQUEST[ $key ] = $value;
		}

		if ( ! empty( $options['unplaced'] ) ) {
			$_GET[ Locations_List::UNPLACED_ARG ] = wp_slash( Locations_List::UNPLACED_VALUE );
		}

		$bulk = $options['bulk'] ?? slosm_bulk_action();

		try {
			return (string) $bulk->handle(
				$options['sendback'] ?? SLOSM_BULK_SENDBACK,
				$options['action'] ?? Bulk_Geocode::ACTION,
				$ids
			);
		} finally {
			$_REQUEST = array();
			$_GET     = array();
		}
	}
}

if ( ! function_exists( 'slosm_bulk_report' ) ) {
	/**
	 * The report the last run left for the editor.
	 *
	 * Read out of the transient rather than out of rendered html, so a case
	 * about what happened does not also depend on the markup. Other cases read
	 * the markup instead, which is what proves the two ends are connected.
	 *
	 * @param int $user User id.
	 * @return array[]
	 */
	function slosm_bulk_report( int $user = 1 ): array {
		$report = get_transient( Bulk_Geocode::REPORT_PREFIX . $user );

		return is_array( $report ) ? $report : array();
	}
}

if ( ! function_exists( 'slosm_bulk_outcomes' ) ) {
	/**
	 * What the last run said about each location, keyed by post id.
	 *
	 * @param int $user User id.
	 * @return array<int, string>
	 */
	function slosm_bulk_outcomes( int $user = 1 ): array {
		$outcomes = array();

		foreach ( slosm_bulk_report( $user ) as $row ) {
			$outcomes[ (int) $row['id'] ] = (string) $row['outcome'];
		}

		return $outcomes;
	}
}

if ( ! function_exists( 'slosm_bulk_detail' ) ) {
	/**
	 * What the last run said beside one location's name.
	 *
	 * @param int $id   Post id.
	 * @param int $user User id.
	 * @return string
	 */
	function slosm_bulk_detail( int $id, int $user = 1 ): string {
		foreach ( slosm_bulk_report( $user ) as $row ) {
			if ( (int) $row['id'] === $id ) {
				return (string) $row['detail'];
			}
		}

		return '';
	}
}

if ( ! function_exists( 'slosm_bulk_notice' ) ) {
	/**
	 * The html the notice prints on the screen the redirect led to.
	 *
	 * The query argument is staged here rather than by every caller, because a
	 * notice with nothing in $_GET is a different case and has one of its own.
	 *
	 * @param Bulk_Geocode|null $bulk The object to print with; a fresh one unless given.
	 * @param mixed             $arg  True for the value the redirect carries, false to leave it out, anything else for that value verbatim.
	 * @return string
	 */
	function slosm_bulk_notice( ?Bulk_Geocode $bulk = null, $arg = true ): string {
		$_GET = array();

		if ( true === $arg ) {
			$_GET[ Bulk_Geocode::NOTICE_ARG ] = wp_slash( Bulk_Geocode::NOTICE_VALUE );
		} elseif ( false !== $arg ) {
			$_GET[ Bulk_Geocode::NOTICE_ARG ] = $arg;
		}

		$bulk = $bulk ?? slosm_bulk_action();

		try {
			ob_start();
			$bulk->notice();

			return (string) ob_get_clean();
		} finally {
			$_GET = array();
		}
	}
}

if ( ! function_exists( 'slosm_bulk_queue_point' ) ) {
	/**
	 * Stages one Nominatim answer for the next lookup.
	 *
	 * The coordinates go out as strings under 'lon', because that is what the
	 * service sends; a fixture using floats and 'lng' would make a parser that
	 * only handles floats and 'lng' look correct.
	 *
	 * Stub state is written, so this may only be called from inside it().
	 *
	 * @param string $lat   Latitude, as the service sends it.
	 * @param string $lon   Longitude, as the service sends it.
	 * @param string $label Display name.
	 * @return void
	 */
	function slosm_bulk_queue_point( string $lat = '52.2297', string $lon = '21.0122', string $label = 'Warszawa' ): void {
		$GLOBALS['slosm_stub']['http_queue'][] = array(
			'response' => array( 'code' => 200 ),
			'body'     => (string) wp_json_encode(
				array(
					array(
						'lat'          => $lat,
						'lon'          => $lon,
						'display_name' => $label,
					),
				)
			),
		);
	}
}

if ( ! function_exists( 'slosm_bulk_queue_nothing' ) ) {
	/**
	 * Stages the answer a service gives for an address it does not know.
	 *
	 * A 200 with an empty list, which is what "worked, no matches" looks like on
	 * the wire and is the one failure that is about the address rather than
	 * about the service.
	 *
	 * @return void
	 */
	function slosm_bulk_queue_nothing(): void {
		$GLOBALS['slosm_stub']['http_queue'][] = array(
			'response' => array( 'code' => 200 ),
			'body'     => '[]',
		);
	}
}

if ( ! function_exists( 'slosm_bulk_queue_failure' ) ) {
	/**
	 * Stages a service that refused the request.
	 *
	 * @param int $status Status code.
	 * @return void
	 */
	function slosm_bulk_queue_failure( int $status = 429 ): void {
		$GLOBALS['slosm_stub']['http_queue'][] = array(
			'response' => array( 'code' => $status ),
			'body'     => '',
		);
	}
}

if ( ! function_exists( 'slosm_bulk_requests' ) ) {
	/**
	 * How many times anything asked a geocoding service a question.
	 *
	 * The number that matters for the lock and for the cache. A "did not
	 * re-geocode" case that compared coordinates would pass perfectly with the
	 * guard deleted, because a second lookup of the same address returns the
	 * same point.
	 *
	 * @return int
	 */
	function slosm_bulk_requests(): int {
		return count( $GLOBALS['slosm_stub']['http_requests'] );
	}
}

if ( ! function_exists( 'slosm_bulk_generation' ) ) {
	/**
	 * The map payload's cache generation, as the option holds it.
	 *
	 * Counted rather than asserted present, because "the cache was flushed" and
	 * "the cache was flushed once per run" are different claims and only the
	 * number can tell them apart.
	 *
	 * @return int
	 */
	function slosm_bulk_generation(): int {
		return (int) ( $GLOBALS['slosm_stub']['options'][ Store_Repository::GENERATION_OPTION ] ?? 0 );
	}
}

if ( ! function_exists( 'slosm_bulk_jumping_clock' ) ) {
	/**
	 * A clock that jumps forward a fixed amount on every reading.
	 *
	 * The run reads the clock once at the start and then once before each lookup
	 * it is about to make, so a clock jumping further than Bulk_Geocode::BUDGET
	 * makes the budget run out after exactly one lookup, whatever the host's
	 * max_execution_time happens to be. That independence is the point: the real
	 * budget is the smaller of the plugin's ceiling and the host's limit, and a
	 * case that depended on which one won would be a case about this machine's
	 * php.ini.
	 *
	 * @param float $step Seconds to add per reading.
	 * @return callable
	 */
	function slosm_bulk_jumping_clock( float $step = 20.0 ): callable {
		$readings = 0;

		return static function () use ( &$readings, $step ): float {
			$now = SLOSM_STUB_EPOCH + ( $readings * $step );
			++$readings;

			return $now;
		};
	}
}

describe(
	'the bulk action in the dropdown',
	function () {

		it(
			'adds one entry of its own, beside the ones core already offers',
			function () {
				$actions = slosm_bulk_action()->actions(
					array(
						'edit'  => 'Edit',
						'trash' => 'Move to Trash',
					)
				);

				assert_same(
					array( 'edit', 'trash', Bulk_Geocode::ACTION ),
					array_keys( $actions ),
					'the action is missing, or it displaced one of core\'s'
				);

				assert_same( 'Edit', $actions['edit'] );

				// And the name it files itself under. The value lands in
				// $_REQUEST['action'] on edit.php beside core's 'edit', 'trash',
				// 'untrash' and 'delete' and beside every other plugin's, so an
				// unprefixed 'geocode' is a name the next locator plugin would
				// also claim and the two would run each other's action.
				assert_same( 'slosm_geocode', Bulk_Geocode::ACTION );
			}
		);

		it(
			'escapes its label, because core prints it into the option unescaped',
			function () {
				// WP_List_Table::bulk_actions() line 622 of WordPress 6.9.1:
				// '<option value="' . esc_attr( $key ) . '"' . $class . '>' .
				// $value . "</option>\n". The key is escaped by core and the
				// label is not.
				$label = slosm_bulk_action()->actions( array() )[ Bulk_Geocode::ACTION ];

				// The apostrophe is what makes this falsifiable: a label of
				// plain words is the same bytes escaped or not, so a case over
				// one would pass with the esc_html() deleted.
				assert_contains( '&#039;', $label );

				assert_false(
					strpos( $label, "'" ) !== false,
					'the label is not escaped, so core would print whatever is in it as markup'
				);
			}
		);

		it(
			'offers nothing on the Trash view',
			function () {
				// WP_Posts_List_Table::get_bulk_actions() swaps its own entries
				// for Restore and Delete permanently when the trash is being
				// looked at (lines 432-453 of WordPress 6.9.1), but this filter
				// runs over the result either way — so an unguarded entry sits
				// in the Trash dropdown. Running it there is a real request, a
				// real second of the courtesy limit, a meta write and a flush
				// per row, for locations that are not on the map and are not
				// going to be; find_for_admin() gates on the post type and
				// deliberately not on the status, so nothing further down would
				// have stopped it.
				//
				// post_status in $_REQUEST is core's own test for which view
				// this is, and the posts-filter form always submits it.
				$_REQUEST['post_status'] = 'trash';

				try {
					$actions = slosm_bulk_action()->actions( array( 'untrash' => 'Restore' ) );
				} finally {
					$_REQUEST = array();
				}

				assert_same( array( 'untrash' => 'Restore' ), $actions );

				// The control: the same call on any other view does offer it.
				$_REQUEST['post_status'] = 'all';

				try {
					$offered = slosm_bulk_action()->actions( array( 'edit' => 'Edit' ) );
				} finally {
					$_REQUEST = array();
				}

				assert_true( isset( $offered[ Bulk_Geocode::ACTION ] ) );

				// And `post_status[]=trash`, which arrives as an array. (string)
				// on one is a warning and the word "Array", which is neither
				// 'trash' nor a reason to stop offering the action.
				$_REQUEST['post_status'] = array( 'trash' );

				try {
					$odd = slosm_bulk_action()->actions( array( 'edit' => 'Edit' ) );
				} finally {
					$_REQUEST = array();
				}

				assert_true( isset( $odd[ Bulk_Geocode::ACTION ] ) );
			}
		);

		it(
			'runs nothing from a Trash url somebody built by hand',
			function () {
				// The dropdown does not offer it there, so the only way to
				// arrive is a hand-built url. One predicate for both, so what an
				// editor can see and what actually runs cannot come apart.
				slosm_bulk_stage( 1, array( 'address' => 'A 1' ), 'Trashed', 'trash' );

				slosm_bulk_queue_point();

				$sendback = slosm_bulk_run(
					array( 1 ),
					array( 'extra' => array( 'post_status' => 'trash' ) )
				);

				assert_same( 0, slosm_bulk_requests() );
				assert_same( array(), slosm_bulk_report() );
				assert_same( SLOSM_BULK_SENDBACK, $sendback );

				// The control, in the same case and over the same fixture: drop
				// the one argument and the selection is geocoded. Nothing else
				// about the run changed, so the refusal above is about the view
				// rather than about a run that was never going to happen.
				slosm_bulk_run( array( 1 ) );

				assert_same( 1, slosm_bulk_requests() );
				assert_same( array( 1 => Bulk_Geocode::GEOCODED ), slosm_bulk_outcomes() );
			}
		);

		it(
			'hands back a filtered value that is not a list of actions',
			function () {
				// The control: an array really is added to, so the two values
				// below are being declined rather than ignored.
				assert_true( isset( slosm_bulk_action()->actions( array() )[ Bulk_Geocode::ACTION ] ) );

				// Another plugin on the same filter returning null. Casting it
				// would turn that plugin's bug into a fatal on this screen.
				assert_same( null, slosm_bulk_action()->actions( null ) );
				assert_same( 'no', slosm_bulk_action()->actions( 'no' ) );
			}
		);
	}
);

describe(
	'what the bulk action refuses to do',
	function () {

		before_each(
			function () {
				slosm_bulk_stage( 1, array( 'address' => 'Nowy Świat 1', 'city' => 'Warszawa' ) );
			}
		);

		it(
			'runs at all, so the refusals below are refusals',
			function () {
				slosm_bulk_queue_point();

				$sendback = slosm_bulk_run( array( 1 ) );

				assert_same( 1, slosm_bulk_requests() );
				assert_same( array( 1 => Bulk_Geocode::GEOCODED ), slosm_bulk_outcomes() );
				assert_contains( Bulk_Geocode::NOTICE_ARG, $sendback );
			}
		);

		it(
			'does nothing for a bulk action that is not its own',
			function () {
				// This filter fires for every bulk action on this screen that
				// core does not handle itself, so anything else on the site that
				// adds one arrives here. Acting on it would geocode a selection
				// somebody made for another plugin's action.
				$sendback = slosm_bulk_run( array( 1 ), array( 'action' => 'somebody_elses' ) );

				assert_same( SLOSM_BULK_SENDBACK, $sendback, 'the url was changed for an action this class does not own' );
				assert_same( 0, slosm_bulk_requests() );
				assert_same( array(), slosm_bulk_report() );
			}
		);

		it(
			'does nothing without core\'s bulk nonce',
			function () {
				slosm_bulk_queue_point();

				$sendback = slosm_bulk_run( array( 1 ), array( 'nonce' => null ) );

				assert_same( 0, slosm_bulk_requests(), 'a request with no nonce geocoded a location' );
				assert_same( array(), slosm_bulk_report() );
				assert_same( SLOSM_BULK_SENDBACK, $sendback );
			}
		);

		it(
			'does nothing for a nonce made out for something else',
			function () {
				slosm_bulk_queue_point();

				// The metabox's own nonce, which is the one thing on this screen
				// that must never be accepted here: Admin::save() is safe only
				// because that nonce and its twelve inputs travel together.
				slosm_bulk_run( array( 1 ), array( 'nonce' => 'nonce:' . Admin::NONCE_ACTION . '1' ) );

				assert_same( 0, slosm_bulk_requests() );
				assert_same( array(), slosm_bulk_report() );
			}
		);

		it(
			'accepts a nonce from the tick before, so a list left open overnight still works',
			function () {
				// wp_verify_nonce() returns int 2 for that, never true. A handler
				// comparing against true refuses the run in silence.
				slosm_bulk_queue_point();

				slosm_bulk_run( array( 1 ), array( 'nonce' => 'stale:bulk-posts' ) );

				assert_same( 1, slosm_bulk_requests() );
			}
		);

		it(
			'verifies against the nonce action core actually issues',
			function () {
				// Not a free choice: WP_List_Table::display_tablenav() prints
				// wp_nonce_field( 'bulk-' . $this->_args['plural'] ) at line
				// 1675 of WordPress 6.9.1, and WP_Posts_List_Table is built with
				// 'plural' => 'posts' (line 78 of class-wp-posts-list-table.php).
				// Any other string here is a handler that refuses every real
				// Apply, in silence, on every screen.
				assert_same( 'bulk-posts', Bulk_Geocode::NONCE_ACTION );
			}
		);

		it(
			'does nothing for a nonce that is not a string at all',
			function () {
				// `_wpnonce[]=x` in a hand-built url. wp_verify_nonce() casts
				// what it is given to a string, and array-to-string is a warning
				// and the word "Array" — so the sanitiser in front of it is
				// load-bearing rather than ceremony.
				slosm_bulk_queue_point();

				slosm_bulk_run( array( 1 ), array( 'nonce' => array( 'nonce:bulk-posts' ) ) );

				assert_same( 0, slosm_bulk_requests() );
				assert_same( array(), slosm_bulk_report() );
			}
		);

		it(
			'hands back whatever it was given when that is not a url at all',
			function () {
				// handle() is a filter, and the value on a filter is whatever
				// every plugin above it returned. Casting it would turn another
				// plugin's bug into a redirect to the string "Array".
				slosm_bulk_queue_point();

				$_REQUEST['_wpnonce'] = wp_slash( 'nonce:bulk-posts' );
				$GLOBALS['slosm_stub']['capabilities'][ Bulk_Geocode::SCREEN_CAPABILITY ] = true;
				$GLOBALS['slosm_stub']['capabilities'][ Bulk_Geocode::ITEM_CAPABILITY ]   = true;

				try {
					assert_same( null, slosm_bulk_action()->handle( null, Bulk_Geocode::ACTION, array( 1 ) ) );
				} finally {
					$_REQUEST = array();
				}

				assert_same( 0, slosm_bulk_requests() );
				assert_same( array(), slosm_bulk_report() );
			}
		);

		it(
			'does nothing for a user without the capability this screen needs',
			function () {
				slosm_bulk_queue_point();

				$sendback = slosm_bulk_run( array( 1 ), array( 'capability' => false ) );

				assert_same( 0, slosm_bulk_requests(), 'a user who cannot edit locations geocoded one' );
				assert_same( array(), slosm_bulk_report() );
				assert_same( SLOSM_BULK_SENDBACK, $sendback );
			}
		);

		it(
			'leaves alone the locations in a selection this user may not edit, and says so',
			function () {
				slosm_bulk_stage( 2, array( 'address' => 'Floriańska 1', 'city' => 'Kraków' ), 'Kraków' );

				slosm_bulk_queue_point();

				// The meta capability with the post id, not the plain one. A
				// contributor holds edit_posts and may not touch a location
				// somebody else owns.
				slosm_bulk_run( array( 1, 2 ), array( 'items' => array( 2 ) ) );

				assert_same(
					array(
						1 => Bulk_Geocode::DENIED,
						2 => Bulk_Geocode::GEOCODED,
					),
					slosm_bulk_outcomes()
				);

				assert_same( 1, slosm_bulk_requests(), 'the location this user may not edit was looked up anyway' );

				// Asked about the location, not merely asked.
				$asked = array();

				foreach ( $GLOBALS['slosm_stub']['cap_checks'] as $check ) {
					if ( Bulk_Geocode::ITEM_CAPABILITY === $check['capability'] ) {
						$asked[] = $check['args'][0] ?? null;
					}
				}

				assert_same( array( 1, 2 ), $asked );
			}
		);

		it(
			'reads no field of the metabox, so a selection cannot be blanked',
			function () {
				// The finding Task 17 recorded and Task 19 inherited: a partial
				// form carrying Admin::NONCE_FIELD would blank every field it
				// omitted, on every location in the selection. This request
				// carries that nonce *and* core's, which is the worst case, and
				// the location is complete.
				slosm_bulk_stage(
					3,
					array(
						'address' => 'Nowy Świat 1',
						'city'    => 'Warszawa',
						'phone'   => '+48 22 000 00 00',
						'email'   => 'sklep@example.test',
						'hours'   => "Mon 9-17\nTue 9-17",
						'url'     => 'https://example.test',
						'zip'     => '00-001',
					),
					'Complete'
				);

				$before = slosm_bulk_stored( 3 );

				slosm_bulk_queue_point();

				slosm_bulk_run(
					array( 3 ),
					array(
						'extra' => array( Admin::NONCE_FIELD => 'nonce:' . Admin::NONCE_ACTION . '3' ),
					)
				);

				$after = slosm_bulk_stored( 3 );

				foreach ( array( 'address', 'city', 'phone', 'email', 'hours', 'url', 'zip', 'name' ) as $field ) {
					assert_same( $before[ $field ], $after[ $field ], 'the run blanked ' . $field );
				}

				// And the one thing it is supposed to have changed did change,
				// so the case above is not passing because nothing ran.
				assert_same( 52.2297, $after['lat'] );
			}
		);

		it(
			'hands the url back untouched when nothing usable was selected',
			function () {
				$sendback = slosm_bulk_run( array() );

				assert_same( SLOSM_BULK_SENDBACK, $sendback );
				assert_same( array(), slosm_bulk_report() );
			}
		);

		it(
			'ignores an id that is not a positive number',
			function () {
				// edit.php intvals only the `post` branch: `ids` is exploded
				// from a comma-separated string with no cast and `media` is
				// taken as-is (lines 101-108 of WordPress 6.9.1), and both are
				// reachable with a hand-built url.
				slosm_bulk_stage( 5, array( 'phone' => '+48 1' ), 'No address' );

				slosm_bulk_run( array( 0, -4, 'nope', array( 1 ), null, '5' ) );

				// The control is the last id in that list: a numeric string is
				// what a url actually carries, so it has to survive while the
				// five before it do not.
				assert_same( array( 5 => Bulk_Geocode::NO_ADDRESS ), slosm_bulk_outcomes(), 'an id that is not a location id produced a row' );
				assert_same( 0, slosm_bulk_requests() );
			}
		);

		it(
			'looks a location up once however many times it was selected',
			function () {
				slosm_bulk_queue_point();

				// post[]=1&post[]=1 in a hand-built url. Two lookups is two
				// seconds for one location, and the report would name it twice
				// under two outcomes.
				slosm_bulk_run( array( 1, '1', 1 ) );

				assert_same( 1, slosm_bulk_requests() );
				assert_same( 1, count( slosm_bulk_report() ) );
			}
		);

		it(
			'says so for a selected id that is not a location',
			function () {
				$GLOBALS['slosm_stub']['posts_by_id'][9] = (object) array(
					'ID'          => 9,
					'post_title'  => 'A page',
					'post_type'   => 'page',
					'post_status' => 'publish',
				);

				slosm_bulk_run( array( 9, 77 ) );

				assert_same(
					array(
						9  => Bulk_Geocode::MISSING,
						77 => Bulk_Geocode::MISSING,
					),
					slosm_bulk_outcomes()
				);

				assert_same( 0, slosm_bulk_requests() );
			}
		);

		it(
			'calls an id with no post behind it missing rather than forbidden',
			function () {
				// The order of the first two checks in run(), and it is not
				// cosmetic. map_meta_cap() answers 'do_not_allow' for
				// 'edit_post' whenever get_post() finds nothing
				// (wp-includes/capabilities.php lines 208-212 of WordPress
				// 6.9.1), so asking the capability first reports every id that
				// is not a post as "you are not allowed to edit it" — one of
				// the ten outcomes wrong about a whole class of input, on the
				// task whose entire point is that the outcomes are distinct.
				//
				// The fixture says it the way this suite can: a user granted
				// edit_post for nothing at all, and an id with no post behind
				// it. Under the wrong order both ids report denied; under the
				// right one the missing post is missing and the real location
				// this user cannot touch is denied.
				slosm_bulk_stage( 4, array( 'address' => 'A 4' ), 'Somebody else\'s' );

				slosm_bulk_run( array( 77, 4 ), array( 'items' => array() ) );

				assert_same(
					array(
						77 => Bulk_Geocode::MISSING,
						4  => Bulk_Geocode::DENIED,
					),
					slosm_bulk_outcomes()
				);
			}
		);

		it(
			'tells nothing about a location to somebody who may not edit it',
			function () {
				// The record is read before the capability is asked about, so
				// this is worth pinning rather than assuming: nothing off that
				// record reaches the report for a denied row.
				slosm_bulk_stage( 4, array( 'address' => 'A 4' ), 'Secret branch' );

				slosm_bulk_run( array( 4 ), array( 'items' => array() ) );

				$report = slosm_bulk_report();

				assert_same( 1, count( $report ) );
				assert_same( '', $report[0]['name'] );
				assert_same( '', $report[0]['detail'] );
			}
		);
	}
);

describe(
	'what happens to each location',
	function () {

		it(
			'writes the coordinates it found, formatted the way the edit screen writes them',
			function () {
				slosm_bulk_stage( 1, array( 'address' => 'Nowy Świat 1', 'city' => 'Warszawa' ) );

				// More decimals than the form renders. Task 17's F2: a stored
				// coordinate at a precision the form cannot show reads back
				// different from what the field holds, so the next untouched
				// save sees a change and locks the location against every future
				// lookup. Admin::coordinate_string() is what makes that state
				// unreachable from here.
				slosm_bulk_queue_point( '52.229675512345', '21.012228912345' );

				slosm_bulk_run( array( 1 ) );

				$stored = slosm_bulk_stored( 1 );

				assert_same( '52.2296755', get_post_meta( 1, Store_Repository::META_KEYS['lat'], true ) );
				assert_same( '21.0122289', get_post_meta( 1, Store_Repository::META_KEYS['lng'], true ) );
				assert_same( 52.2296755, $stored['lat'] );
				assert_same( array( 1 => Bulk_Geocode::GEOCODED ), slosm_bulk_outcomes() );
			}
		);

		it(
			'does not mark what it wrote as placed by hand',
			function () {
				// A lookup is not a person placing a pin. Marking it as one
				// would stop every later lookup on a location nobody has ever
				// touched.
				slosm_bulk_stage( 1, array( 'address' => 'Nowy Świat 1' ) );
				slosm_bulk_queue_point();

				slosm_bulk_run( array( 1 ) );

				$stored = slosm_bulk_stored( 1 );

				// The control travels with the claim: a run that wrote nothing
				// would leave lat_locked false as well, so the absence below
				// only means something beside a lookup that happened.
				assert_same( 52.2297, $stored['lat'] );
				assert_false( $stored['lat_locked'] );
			}
		);

		it(
			'leaves a location that is already on the map alone',
			function () {
				slosm_bulk_stage(
					1,
					array(
						'address' => 'Nowy Świat 1',
						'lat'     => '52.2297',
						'lng'     => '21.0122',
					)
				);

				slosm_bulk_run( array( 1 ) );

				assert_same( 0, slosm_bulk_requests(), 'a location already on the map was looked up' );
				assert_same( array( 1 => Bulk_Geocode::PLACED ), slosm_bulk_outcomes() );
			}
		);

		it(
			'looks up a location whose coordinates are the 0,0 a failed lookup leaves behind',
			function () {
				// has_coordinates() calls that pair unplaced, and should_geocode()
				// asks it rather than re-implementing it. A run that took "there
				// are two numbers there" for placed would leave every failed
				// geocode in the Gulf of Guinea for ever.
				slosm_bulk_stage(
					1,
					array(
						'address' => 'Nowy Świat 1',
						'lat'     => '0',
						'lng'     => '0',
					)
				);

				slosm_bulk_queue_point();

				slosm_bulk_run( array( 1 ) );

				assert_same( 1, slosm_bulk_requests() );
				assert_same( array( 1 => Bulk_Geocode::GEOCODED ), slosm_bulk_outcomes() );
			}
		);

		it(
			'looks up a location that has half a pair',
			function () {
				slosm_bulk_stage(
					1,
					array(
						'address' => 'Nowy Świat 1',
						'lat'     => '52.2297',
						'lng'     => '',
					)
				);

				slosm_bulk_queue_point();

				slosm_bulk_run( array( 1 ) );

				assert_same( array( 1 => Bulk_Geocode::GEOCODED ), slosm_bulk_outcomes() );
			}
		);

		it(
			'never overwrites a pin somebody placed by hand',
			function () {
				// The one outcome of this run that destroys work, with no error
				// state and no way back. Counted in requests rather than in
				// coordinates: a second lookup of the same address returns the
				// same point, so a comparison would pass with the guard deleted.
				slosm_bulk_stage(
					1,
					array(
						'address'    => 'Nowy Świat 1',
						'lat'        => '52.1',
						'lng'        => '21.1',
						'lat_locked' => '1',
					)
				);

				slosm_bulk_run( array( 1 ) );

				assert_same( 0, slosm_bulk_requests(), 'a hand-placed location was sent to the geocoder' );
				assert_same( array( 1 => Bulk_Geocode::LOCKED ), slosm_bulk_outcomes() );
				assert_same( 52.1, slosm_bulk_stored( 1 )['lat'] );
			}
		);

		it(
			'respects the lock even when the location is not on the map at all',
			function () {
				// Rule one of should_geocode() beats rule three. A person who
				// cleared the coordinates and locked them has said something,
				// and "it has no coordinates" is not a reason to overrule it.
				slosm_bulk_stage(
					1,
					array(
						'address'    => 'Nowy Świat 1',
						'lat'        => '',
						'lng'        => '',
						'lat_locked' => '1',
					)
				);

				slosm_bulk_run( array( 1 ) );

				assert_same( 0, slosm_bulk_requests() );
				assert_same( array( 1 => Bulk_Geocode::LOCKED ), slosm_bulk_outcomes() );
			}
		);

		it(
			'says a locked location is locked rather than that it has no address',
			function () {
				// Both are true of this one, and the order the reasons are read
				// off in has to be should_geocode()'s own — otherwise the report
				// tells the editor to type an address when what actually stopped
				// the lookup was the lock, and typing one would change nothing.
				slosm_bulk_stage( 1, array( 'lat_locked' => '1' ) );

				slosm_bulk_run( array( 1 ) );

				assert_same( array( 1 => Bulk_Geocode::LOCKED ), slosm_bulk_outcomes() );
			}
		);

		it(
			'says a location with nothing to look up has no address',
			function () {
				slosm_bulk_stage( 1, array( 'phone' => '+48 22 000 00 00' ) );

				slosm_bulk_run( array( 1 ) );

				assert_same( 0, slosm_bulk_requests(), 'an empty address was sent to the geocoder' );
				assert_same( array( 1 => Bulk_Geocode::NO_ADDRESS ), slosm_bulk_outcomes() );
			}
		);

		it(
			'says a location whose address is only whitespace has no address',
			function () {
				slosm_bulk_stage( 1, array( 'address' => '   ', 'city' => '' ) );

				slosm_bulk_run( array( 1 ) );

				assert_same( 0, slosm_bulk_requests() );
				assert_same( array( 1 => Bulk_Geocode::NO_ADDRESS ), slosm_bulk_outcomes() );
			}
		);

		it(
			'tells the service knowing of no such place apart from the service failing',
			function () {
				// Two different things to do next: one is a sentence about the
				// address and the other is a sentence about the service. A
				// report that folded them together would send an editor to
				// re-check a perfectly good address during an outage.
				slosm_bulk_stage( 1, array( 'address' => 'Nigdzie 1' ), 'Nowhere' );
				slosm_bulk_stage( 2, array( 'address' => 'Floriańska 1' ), 'Kraków' );

				slosm_bulk_queue_nothing();
				slosm_bulk_queue_failure( 429 );

				slosm_bulk_run( array( 1, 2 ) );

				assert_same(
					array(
						1 => Bulk_Geocode::NO_MATCH,
						2 => Bulk_Geocode::SERVICE_FAILED,
					),
					slosm_bulk_outcomes()
				);
			}
		);

		it(
			'quotes the geocoder\'s own words rather than inventing a house style',
			function () {
				slosm_bulk_stage( 1, array( 'address' => 'Nigdzie 1' ), 'Nowhere' );

				slosm_bulk_queue_nothing();

				slosm_bulk_run( array( 1 ) );

				assert_same( 'No place matched that address.', slosm_bulk_detail( 1 ) );
			}
		);

		it(
			'changes nothing about a location whose lookup failed',
			function () {
				// The service being unreachable is not evidence about where the
				// shop is.
				slosm_bulk_stage(
					1,
					array(
						'address' => 'Nowy Świat 1',
						'lat'     => '0',
						'lng'     => '0',
					)
				);

				slosm_bulk_queue_failure( 500 );

				slosm_bulk_run( array( 1 ) );

				// The control first: the lookup really was made and really
				// failed, so the two absences below are about a failure rather
				// than about nothing having happened.
				assert_same( 1, slosm_bulk_requests() );
				assert_same( array( 1 => Bulk_Geocode::SERVICE_FAILED ), slosm_bulk_outcomes() );

				assert_same( '0', get_post_meta( 1, Store_Repository::META_KEYS['lat'], true ) );
				assert_same( 0, slosm_bulk_generation(), 'a failed run spent a cache generation' );
			}
		);

		it(
			'tells every outcome apart in one run',
			function () {
				// The whole claim of this task in one case: six states that an
				// editor has to act on differently, in one selection, each
				// reported as itself.
				slosm_bulk_stage( 1, array( 'address' => 'Nowy Świat 1' ), 'Geocoded' );
				slosm_bulk_stage( 2, array( 'address' => 'Długa 2', 'lat' => '52.1', 'lng' => '21.1' ), 'Placed' );
				slosm_bulk_stage( 3, array( 'address' => 'Krótka 3', 'lat_locked' => '1' ), 'Locked' );
				slosm_bulk_stage( 4, array( 'phone' => '+48 1' ), 'No address' );
				slosm_bulk_stage( 5, array( 'address' => 'Nigdzie 5' ), 'No match' );
				slosm_bulk_stage( 6, array( 'address' => 'Awaria 6' ), 'Service down' );

				slosm_bulk_queue_point();
				slosm_bulk_queue_nothing();
				slosm_bulk_queue_failure( 503 );

				slosm_bulk_run( array( 1, 2, 3, 4, 5, 6 ) );

				$outcomes = slosm_bulk_outcomes();

				assert_same(
					array(
						1 => Bulk_Geocode::GEOCODED,
						2 => Bulk_Geocode::PLACED,
						3 => Bulk_Geocode::LOCKED,
						4 => Bulk_Geocode::NO_ADDRESS,
						5 => Bulk_Geocode::NO_MATCH,
						6 => Bulk_Geocode::SERVICE_FAILED,
					),
					$outcomes
				);

				// Six locations, six outcomes, six distinct values. Without this
				// the assertion above would still hold for a class that had
				// collapsed two of the constants onto one string.
				assert_same( 6, count( array_unique( $outcomes ) ) );

				// Three lookups and three not, which is the other half of the
				// claim: the three that were not looked up cost nothing.
				assert_same( 3, slosm_bulk_requests() );
			}
		);

		it(
			'reports every selected location, in the order they were selected',
			function () {
				slosm_bulk_stage( 1, array( 'address' => 'A 1', 'lat' => '1', 'lng' => '1' ) );
				slosm_bulk_stage( 2, array( 'address' => 'B 2', 'lat' => '1', 'lng' => '1' ) );
				slosm_bulk_stage( 3, array( 'address' => 'C 3', 'lat' => '1', 'lng' => '1' ) );

				slosm_bulk_run( array( 3, 1, 2 ) );

				assert_same( array( 3, 1, 2 ), array_keys( slosm_bulk_outcomes() ) );
			}
		);
	}
);

describe(
	'the failure this run remembers',
	function () {

		before_each(
			function () {
				slosm_bulk_stage( 1, array( 'address' => 'Nigdzie 1' ), 'Nowhere' );
			}
		);

		it(
			'does not ask again about an address that failed recently',
			function () {
				// Geocoder caches successes for thirty days and deliberately
				// never caches a failure, and a location with no coordinates is
				// looked up on every run. Without this memory a page of
				// unresolvable addresses costs a second each, every Apply, for
				// ever — which is exactly the loop the second Apply of a batched
				// run would land in.
				slosm_bulk_queue_nothing();

				slosm_bulk_run( array( 1 ) );

				assert_same( 1, slosm_bulk_requests() );

				slosm_bulk_run( array( 1 ) );

				assert_same( 1, slosm_bulk_requests(), 'the second run asked about a known-bad address again' );
				assert_same( array( 1 => Bulk_Geocode::REMEMBERED ), slosm_bulk_outcomes() );
			}
		);

		it(
			'repeats why, rather than going quiet about a location that is still not on the map',
			function () {
				slosm_bulk_queue_nothing();

				slosm_bulk_run( array( 1 ) );
				slosm_bulk_run( array( 1 ) );

				assert_contains( 'No place matched that address.', slosm_bulk_detail( 1 ) );
			}
		);

		it(
			'shares that memory with a single save, in both directions',
			function () {
				// Admin::failure_key() is one key and one lifetime, so a bulk
				// run and a save cannot re-ask each other's known-bad addresses.
				// A second key under a second name would look identical until
				// somebody counted the requests.
				slosm_bulk_queue_nothing();

				slosm_bulk_run( array( 1 ) );

				$key = Admin::failure_key( 'Nigdzie 1' );

				assert_true( is_string( get_transient( $key ) ), 'the run recorded no failure a save could read' );

				// And the other direction: a failure a save recorded stops this
				// run from asking.
				slosm_stub_reset();
				slosm_bulk_stage( 2, array( 'address' => 'Nigdzie 2' ), 'Nowhere too' );
				set_transient( Admin::failure_key( 'Nigdzie 2' ), 'the service said no', Admin::FAILURE_TTL );

				slosm_bulk_run( array( 2 ) );

				assert_same( 0, slosm_bulk_requests() );
				assert_same( array( 2 => Bulk_Geocode::REMEMBERED ), slosm_bulk_outcomes() );
				assert_same( 'the service said no', slosm_bulk_detail( 2 ) );
			}
		);

		it(
			'asks about a different address on the same run',
			function () {
				// The key is the query, not the location, so five branches on
				// one unresolvable street share one answer and a different
				// street is a different question.
				slosm_bulk_stage( 2, array( 'address' => 'Floriańska 2' ), 'Kraków' );

				slosm_bulk_queue_nothing();
				slosm_bulk_queue_point();

				slosm_bulk_run( array( 1, 2 ) );

				assert_same( 2, slosm_bulk_requests() );
				assert_same(
					array(
						1 => Bulk_Geocode::NO_MATCH,
						2 => Bulk_Geocode::GEOCODED,
					),
					slosm_bulk_outcomes()
				);
			}
		);
	}
);

describe(
	'the limiter',
	function () {

		it(
			'fits the budget to the host\'s own limit',
			function () {
				// Zero is what max_execution_time is on the CLI and what
				// ini_get() reports for an empty directive; it means no limit,
				// so the plugin's own ceiling applies.
				assert_same( Bulk_Geocode::BUDGET, Bulk_Geocode::budget( 0 ) );
				assert_same( Bulk_Geocode::BUDGET, Bulk_Geocode::budget( -1 ) );

				// A generous host: the ceiling wins.
				assert_same( Bulk_Geocode::BUDGET, Bulk_Geocode::budget( 120 ) );
				assert_same( Bulk_Geocode::BUDGET, Bulk_Geocode::budget( 30 ) );

				// A tight one: the host wins, less the reserve — one lookup that
				// may already be in flight plus the rest of the request.
				assert_same( 10.0, Bulk_Geocode::budget( 20 ) );
				assert_same( 2.0, Bulk_Geocode::budget( 12 ) );

				// And one too tight for even the reserve. Zero, not a negative
				// number: a negative budget and a zero one behave the same in
				// the run, and a negative one printed anywhere reads as a bug.
				assert_same( 0.0, Bulk_Geocode::budget( 10 ) );
				assert_same( 0.0, Bulk_Geocode::budget( 3 ) );
			}
		);

		it(
			'waits between lookups rather than firing them one after another',
			function () {
				// The courtesy limit is the reason this task is hard, so a case
				// has to fail if it stops being paid. The first lookup of a
				// request has nothing to wait for; every one after it waits a
				// whole interval.
				slosm_bulk_stage( 1, array( 'address' => 'A 1' ), 'One' );
				slosm_bulk_stage( 2, array( 'address' => 'B 2' ), 'Two' );
				slosm_bulk_stage( 3, array( 'address' => 'C 3' ), 'Three' );

				slosm_bulk_queue_point();
				slosm_bulk_queue_point();
				slosm_bulk_queue_point();

				slosm_bulk_run( array( 1, 2, 3 ) );

				assert_same( 3, slosm_bulk_requests() );
				assert_same( array( 1.0, 1.0 ), $GLOBALS['slosm_stub']['sleeps'] );
			}
		);

		it(
			'stops when the budget is spent, and names every location it did not reach',
			function () {
				// A clock jumping further than the whole budget per reading, so
				// this case is about the run's own arithmetic and not about this
				// machine's php.ini.
				slosm_bulk_stage( 1, array( 'address' => 'A 1' ), 'One' );
				slosm_bulk_stage( 2, array( 'address' => 'B 2' ), 'Two' );
				slosm_bulk_stage( 3, array( 'address' => 'C 3' ), 'Three' );

				slosm_bulk_queue_point();

				slosm_bulk_run(
					array( 1, 2, 3 ),
					array( 'bulk' => slosm_bulk_action( null, slosm_bulk_jumping_clock( Bulk_Geocode::BUDGET + 1.0 ) ) )
				);

				assert_same( 1, slosm_bulk_requests(), 'the run spent more than its budget' );
				assert_same(
					array(
						1 => Bulk_Geocode::GEOCODED,
						2 => Bulk_Geocode::DEFERRED,
						3 => Bulk_Geocode::DEFERRED,
					),
					slosm_bulk_outcomes(),
					'a location the run did not reach was left out of the report'
				);
			}
		);

		it(
			'attempts one lookup even on a host whose budget works out to nothing',
			function () {
				// budget() returns 0.0 for a max_execution_time at or below the
				// reserve. A run that then did nothing at all would be a screen
				// that reports having done nothing, every time, for ever.
				slosm_bulk_stage( 1, array( 'address' => 'A 1' ), 'One' );
				slosm_bulk_stage( 2, array( 'address' => 'B 2' ), 'Two' );

				slosm_bulk_queue_point();

				// A clock that has already moved past any budget by the first
				// check, which is the state a zero budget is in from the start.
				slosm_bulk_run(
					array( 1, 2 ),
					array( 'bulk' => slosm_bulk_action( null, slosm_bulk_jumping_clock( 10000.0 ) ) )
				);

				assert_same( 1, slosm_bulk_requests() );
				assert_same(
					array(
						1 => Bulk_Geocode::GEOCODED,
						2 => Bulk_Geocode::DEFERRED,
					),
					slosm_bulk_outcomes()
				);
			}
		);

		it(
			'treats a budget exactly spent as spent',
			function () {
				// The boundary, which is a decision rather than a tie: a run
				// that let one more lookup through at exactly the budget would
				// be a run whose stated ceiling is the one number it does not
				// hold to.
				//
				// The suite runs under `php -n`, so no php.ini is loaded and
				// ini_get( 'max_execution_time' ) is the CLI's own 0 — no limit
				// — which makes the run's budget BUDGET itself. The assertion
				// below states that premise rather than leaving the case
				// depending on it silently.
				assert_same( Bulk_Geocode::BUDGET, Bulk_Geocode::budget( 0 ) );

				slosm_bulk_stage( 1, array( 'address' => 'A 1' ), 'One' );
				slosm_bulk_stage( 2, array( 'address' => 'B 2' ), 'Two' );

				slosm_bulk_queue_point();

				slosm_bulk_run(
					array( 1, 2 ),
					array( 'bulk' => slosm_bulk_action( null, slosm_bulk_jumping_clock( Bulk_Geocode::BUDGET ) ) )
				);

				assert_same( 1, slosm_bulk_requests() );
				assert_same( Bulk_Geocode::DEFERRED, slosm_bulk_outcomes()[2] );
			}
		);

		it(
			'runs on while the clock does not move',
			function () {
				// The control for the two cases above. With a clock that stands
				// still nothing is ever deferred, so "everything was deferred"
				// cannot be what those cases are really measuring.
				slosm_bulk_stage( 1, array( 'address' => 'A 1' ), 'One' );
				slosm_bulk_stage( 2, array( 'address' => 'B 2' ), 'Two' );
				slosm_bulk_stage( 3, array( 'address' => 'C 3' ), 'Three' );

				slosm_bulk_queue_point();
				slosm_bulk_queue_point();
				slosm_bulk_queue_point();

				slosm_bulk_run(
					array( 1, 2, 3 ),
					array(
						'bulk' => slosm_bulk_action(
							null,
							static function (): float {
								return SLOSM_STUB_EPOCH;
							}
						),
					)
				);

				assert_same( 3, slosm_bulk_requests() );
				assert_same( array(), array_filter( slosm_bulk_outcomes(), static function ( $o ) { return Bulk_Geocode::DEFERRED === $o; } ) );
			}
		);

		it(
			'spends neither a request nor a wait on an address it has already resolved',
			function () {
				// Geocoder's thirty-day cache is read before the limiter, so a
				// cache hit neither waits nor moves the timestamp. Two branches
				// on one street therefore cost one request, which is what keeps
				// a run over a site's repeated addresses from being a run over
				// its locations.
				slosm_bulk_stage( 1, array( 'address' => 'Nowy Świat 1' ), 'One' );
				slosm_bulk_stage( 2, array( 'address' => 'Nowy Świat 1' ), 'Two' );

				slosm_bulk_queue_point();

				slosm_bulk_run( array( 1, 2 ) );

				assert_same( 1, slosm_bulk_requests() );
				assert_same( array(), $GLOBALS['slosm_stub']['sleeps'] );
				assert_same(
					array(
						1 => Bulk_Geocode::GEOCODED,
						2 => Bulk_Geocode::GEOCODED,
					),
					slosm_bulk_outcomes()
				);
			}
		);
	}
);

describe(
	'the cache nothing else would invalidate',
	function () {

		it(
			'throws the map payload away twice for a run that wrote coordinates, whatever the run wrote',
			function () {
				// Two, and the number is the whole case. None of
				// Plugin::boot()'s six invalidation hooks sees a meta-only
				// write, so one flush is needed at all; and one is not enough,
				// because Store_Repository::flush_cache() spends a generation
				// only on its first call in a request. Locations two and three
				// are therefore written *after* the only bump the per-write
				// flushes make, and a front-end request landing in that gap —
				// which this class is designed to let last twenty seconds —
				// caches a payload without their coordinates under the
				// generation that has just moved. Nothing invalidates it
				// afterwards. The closing flush_cache( true ) is what closes it,
				// and the guard is why it has to be forced.
				//
				// Counted rather than asserted present, because "flushed",
				// "flushed once" and "flushed at both ends" are three different
				// claims and only the number tells them apart. A flush per
				// write would read 4 here: a rewrite of the whole alloptions
				// blob per location, which is the cost the guard exists to
				// avoid.
				slosm_bulk_stage( 1, array( 'address' => 'A 1' ), 'One' );
				slosm_bulk_stage( 2, array( 'address' => 'B 2' ), 'Two' );
				slosm_bulk_stage( 3, array( 'address' => 'C 3' ), 'Three' );

				slosm_bulk_queue_point();
				slosm_bulk_queue_point();
				slosm_bulk_queue_point();

				assert_same( 0, slosm_bulk_generation() );

				slosm_bulk_run( array( 1, 2, 3 ) );

				assert_same( 3, slosm_bulk_requests() );
				assert_same(
					2,
					slosm_bulk_generation(),
					'three writes did not cost exactly two generations: one at the first write, one after the last'
				);
			}
		);

		it(
			'still spends two on a run that wrote exactly one location',
			function () {
				// There is no window to close when only one location was
				// written — nothing is written after that flush — so the second
				// increment is redundant here. It happens anyway, because a
				// branch on "how many did we write" is a second rule about the
				// same race for the sake of one option write, and the run that
				// the rule would have to get right is the one that crashed
				// half way.
				slosm_bulk_stage( 1, array( 'address' => 'A 1' ), 'One' );

				slosm_bulk_queue_point();

				slosm_bulk_run( array( 1 ) );

				assert_same( 2, slosm_bulk_generation() );
			}
		);

		it(
			'spends no generation for a run that wrote nothing',
			function () {
				slosm_bulk_stage( 1, array( 'address' => 'A 1', 'lat' => '52.1', 'lng' => '21.1' ), 'One' );
				slosm_bulk_stage( 2, array( 'lat_locked' => '1' ), 'Two' );

				slosm_bulk_run( array( 1, 2 ) );

				// The control: the run did happen and did look at both
				// locations. Without it this case would pass for a run that
				// never started.
				assert_same(
					array(
						1 => Bulk_Geocode::PLACED,
						2 => Bulk_Geocode::LOCKED,
					),
					slosm_bulk_outcomes()
				);

				assert_same( 0, slosm_bulk_generation() );
			}
		);

		it(
			'leaves the half it finished written and reachable when a run dies part way',
			function () {
				// The timeout this whole design is about, from the other side: a
				// worker killed after thirty of fifty. Writing each location as
				// it resolves and flushing on the first write is what makes that
				// half real rather than invisible until the ttl expires. The
				// stub throws on an unqueued request, which is a run dying
				// exactly where a timeout would.
				slosm_bulk_stage( 1, array( 'address' => 'A 1' ), 'One' );
				slosm_bulk_stage( 2, array( 'address' => 'B 2' ), 'Two' );

				slosm_bulk_queue_point();

				$died = false;

				try {
					slosm_bulk_run( array( 1, 2 ) );
				} catch ( RuntimeException $e ) {
					$died = true;
				}

				assert_true( $died, 'the run did not die, so this case proves nothing about one that does' );

				assert_same( 52.2297, slosm_bulk_stored( 1 )['lat'], 'the location the run had finished was not written' );
				assert_same( 1, slosm_bulk_generation(), 'the finished half is written and the map still serves the old pins' );

				// One, not two: the closing flush never ran, which is exactly
				// why the per-write one is not redundant.
				assert_same( array( 1 => Bulk_Geocode::GEOCODED ), slosm_bulk_outcomes(), 'the run that died left no report of the half it finished' );
			}
		);

		it(
			'leaves a readable report behind when a run dies, on the screen the editor goes back to',
			function () {
				// The other half of the same failure. The budget exists because
				// a run can be killed mid-flight; a report written only after
				// the loop returns is a report that does not exist in exactly
				// that case, and one gated only on a redirect argument is a
				// report nobody can reach, because the redirect never happened.
				slosm_bulk_stage( 1, array( 'address' => 'A 1' ), 'Finished' );
				slosm_bulk_stage( 2, array( 'address' => 'B 2' ), 'Never reached' );

				slosm_bulk_queue_point();

				$bulk = slosm_bulk_action();

				try {
					slosm_bulk_run( array( 1, 2 ), array( 'bulk' => $bulk ) );
				} catch ( RuntimeException $e ) {
					$died = true;
				}

				assert_true( isset( $died ), 'the run did not die' );

				// No redirect happened, so nothing put the argument in the url.
				// The editor navigates back to the locations list by hand.
				slosm_stub_screen( 'edit', Post_Type::POST_TYPE );

				$html = slosm_bulk_notice( $bulk, false );

				assert_contains( 'Finished', $html );

				// And not on some other admin screen, which is what the gate is
				// for. The transient is gone by now, so this is staged again.
				set_transient(
					Bulk_Geocode::REPORT_PREFIX . 1,
					array(
						array(
							'id'      => 1,
							'name'    => 'Finished',
							'outcome' => Bulk_Geocode::GEOCODED,
							'detail'  => '',
						),
					),
					Bulk_Geocode::REPORT_TTL
				);

				slosm_stub_screen( 'dashboard', '' );

				assert_same( '', slosm_bulk_notice( $bulk, false ), 'a report about locations printed over the Dashboard' );
			}
		);
	}
);

describe(
	'the report an editor reads',
	function () {

		it(
			'names every location rather than folding any of them into a total',
			function () {
				slosm_bulk_stage( 1, array( 'address' => 'Nigdzie 1' ), 'Zakopane' );
				slosm_bulk_stage( 2, array( 'address' => 'Awaria 2' ), 'Sopot' );
				slosm_bulk_stage( 3, array( 'address' => 'Dobra 3' ), 'Gdynia' );

				slosm_bulk_queue_nothing();
				slosm_bulk_queue_failure( 500 );
				slosm_bulk_queue_point();

				$bulk = slosm_bulk_action();

				slosm_bulk_run( array( 1, 2, 3 ), array( 'bulk' => $bulk ) );

				$html = slosm_bulk_notice( $bulk );

				foreach ( array( 'Zakopane', 'Sopot', 'Gdynia' ) as $name ) {
					assert_contains( $name, $html, $name . ' was not named in the report' );
				}

				// And the reason beside the name, which is the difference
				// between a report an editor can act on and a list of titles.
				assert_contains( 'No place matched that address.', $html );
				assert_contains( '52.2297, 21.0122', $html );
			}
		);

		it(
			'links each name to that location\'s own edit screen',
			function () {
				// Two branches can share a title, and a report that only printed
				// names would leave an editor searching a list of forty for the
				// one that failed.
				slosm_bulk_stage( 1, array( 'address' => 'Nigdzie 1' ), 'Zakopane' );

				slosm_bulk_queue_nothing();

				$bulk = slosm_bulk_action();

				slosm_bulk_run( array( 1 ), array( 'bulk' => $bulk ) );

				assert_contains(
					'post.php?post=1&#038;action=edit',
					slosm_bulk_notice( $bulk )
				);
			}
		);

		it(
			'names a location that has no title at all by its id',
			function () {
				slosm_bulk_stage( 7, array( 'address' => 'Nigdzie 7' ), '' );

				slosm_bulk_queue_nothing();

				$bulk = slosm_bulk_action();

				slosm_bulk_run( array( 7 ), array( 'bulk' => $bulk ) );

				$html = slosm_bulk_notice( $bulk );

				assert_contains( 'Location #7', $html );
				assert_false( strpos( $html, '<a href="' ) !== false, 'an empty link is not a link' );
			}
		);

		it(
			'prints the failures above the successes',
			function () {
				// The one lesson carried over from an earlier plugin by this
				// author whose bulk importer said "done" while silently
				// skipping: make the failure visible first and diagnose second.
				slosm_bulk_stage( 1, array( 'address' => 'Dobra 1' ), 'Worked' );
				slosm_bulk_stage( 2, array( 'address' => 'Nigdzie 2' ), 'Failed' );

				slosm_bulk_queue_point();
				slosm_bulk_queue_nothing();

				$bulk = slosm_bulk_action();

				slosm_bulk_run( array( 1, 2 ), array( 'bulk' => $bulk ) );

				$html = slosm_bulk_notice( $bulk );

				assert_true(
					strpos( $html, 'Failed' ) < strpos( $html, 'Worked' ),
					'the successes were printed above the failures'
				);

				// Two outcomes, two notices. An outcome with nothing under it
				// must print no box at all, or a run that geocoded two
				// locations would hand the editor ten notices and eight of them
				// would be about nothing.
				assert_same( 2, substr_count( $html, '<div class="notice ' ) );
			}
		);

		it(
			'says something different, in a notice of its own, about every outcome it can produce',
			function () {
				// Written out rather than derived from the class, so an outcome
				// added without a sentence to go with it is a decision taken
				// here as well. The set has to match OUTCOME_ORDER exactly:
				// anything missing from that list would be recorded by the run
				// and never shown to anybody.
				$outcomes = array(
					Bulk_Geocode::GEOCODED,
					Bulk_Geocode::PLACED,
					Bulk_Geocode::LOCKED,
					Bulk_Geocode::NO_ADDRESS,
					Bulk_Geocode::NO_MATCH,
					Bulk_Geocode::SERVICE_FAILED,
					Bulk_Geocode::REMEMBERED,
					Bulk_Geocode::DEFERRED,
					Bulk_Geocode::DENIED,
					Bulk_Geocode::MISSING,
				);

				sort( $outcomes );

				$ordered = Bulk_Geocode::OUTCOME_ORDER;

				sort( $ordered );

				assert_same( $outcomes, $ordered, 'an outcome would be recorded and never printed' );

				$report = array();
				$id     = 0;

				foreach ( Bulk_Geocode::OUTCOME_ORDER as $outcome ) {
					++$id;

					$report[] = array(
						'id'      => $id,
						'name'    => 'Location ' . $id,
						'outcome' => $outcome,
						'detail'  => '',
					);
				}

				$GLOBALS['slosm_stub']['current_user_id'] = 1;

				set_transient( Bulk_Geocode::REPORT_PREFIX . 1, $report, Bulk_Geocode::REPORT_TTL );

				$html = slosm_bulk_notice();

				preg_match_all( '#<p>(.*?)</p>#', $html, $matches );

				assert_same(
					count( Bulk_Geocode::OUTCOME_ORDER ),
					count( $matches[1] ),
					'one of the outcomes printed no sentence of its own'
				);

				assert_same(
					count( $matches[1] ),
					count( array_unique( $matches[1] ) ),
					'two outcomes say the same thing, so an editor cannot tell them apart'
				);

				foreach ( $matches[1] as $sentence ) {
					assert_true( '' !== trim( $sentence ) );
				}

				// And the severities, because a failure printed in the same grey
				// box as "nothing needed doing" is a failure nobody reads. Four
				// of core's classes, each used at least once.
				foreach ( array( 'notice-error', 'notice-warning', 'notice-info', 'notice-success' ) as $class ) {
					assert_contains( $class, $html );
				}
			}
		);

		it(
			'escapes a title and a service message rather than printing them as markup',
			function () {
				slosm_bulk_stage( 1, array(), '<script>alert(1)</script>' );

				set_transient(
					Bulk_Geocode::REPORT_PREFIX . 1,
					array(
						array(
							'id'      => 1,
							'name'    => '<script>alert(1)</script>',
							'outcome' => Bulk_Geocode::SERVICE_FAILED,
							'detail'  => '<img src=x onerror=alert(2)>',
						),
					),
					Bulk_Geocode::REPORT_TTL
				);

				$GLOBALS['slosm_stub']['current_user_id'] = 1;

				$html = slosm_bulk_notice();

				assert_false( strpos( $html, '<script>' ) !== false, 'a title was printed as markup' );
				assert_false( strpos( $html, '<img ' ) !== false, 'a service message was printed as markup' );
				assert_contains( '&lt;script&gt;', $html );
			}
		);

		it(
			'reads the report once',
			function () {
				// A report that survived its own rendering would reappear on
				// every later page load, describing a run nobody can remember
				// starting — and this screen's own paging links carry the
				// argument that lets it print.
				slosm_bulk_stage( 1, array( 'address' => 'Dobra 1' ), 'One' );

				slosm_bulk_queue_point();

				$bulk = slosm_bulk_action();

				slosm_bulk_run( array( 1 ), array( 'bulk' => $bulk ) );

				assert_contains( 'One', slosm_bulk_notice( $bulk ) );
				assert_same( '', slosm_bulk_notice( $bulk ), 'the report printed itself twice' );
			}
		);

		it(
			'prints nothing on a screen the redirect did not lead to',
			function () {
				// admin_notices fires on every screen in wp-admin. Without the
				// argument, a report left behind by a run whose redirect never
				// arrived would print on the Dashboard.
				slosm_bulk_stage( 1, array( 'address' => 'Dobra 1' ), 'One' );

				slosm_bulk_queue_point();

				$bulk = slosm_bulk_action();

				slosm_bulk_run( array( 1 ), array( 'bulk' => $bulk ) );

				assert_same( '', slosm_bulk_notice( $bulk, false ) );

				// The argument carrying anything other than the one value this
				// class writes is the same thing as it not being there. It is a
				// switch with one position, and an argument somebody else left
				// in a url is not a run of this action.
				assert_same( '', slosm_bulk_notice( $bulk, '0' ) );
				assert_same( '', slosm_bulk_notice( $bulk, 'yes' ) );

				// And `slosm_geocoded[]=1`, which arrives as an array. (string)
				// on an array is a warning and the word "Array".
				assert_same( '', slosm_bulk_notice( $bulk, array( '1' ) ) );

				// And the report is still there afterwards, so the screen the
				// redirect does lead to can still show it.
				assert_contains( 'One', slosm_bulk_notice( $bulk ) );
			}
		);

		it(
			'prints nothing when there is no report to print',
			function () {
				$GLOBALS['slosm_stub']['current_user_id'] = 1;

				assert_same( '', slosm_bulk_notice() );

				// The control, in the same case: the same screen with a report
				// waiting does print one, so the emptiness above is about there
				// being nothing to say.
				set_transient(
					Bulk_Geocode::REPORT_PREFIX . 1,
					array(
						array(
							'id'      => 1,
							'name'    => 'Sopot',
							'outcome' => Bulk_Geocode::GEOCODED,
							'detail'  => '',
						),
					),
					Bulk_Geocode::REPORT_TTL
				);

				assert_contains( 'Sopot', slosm_bulk_notice() );
			}
		);

		it(
			'keeps one editor\'s report out of another\'s screen',
			function () {
				slosm_bulk_stage( 1, array( 'address' => 'Dobra 1' ), 'One' );

				slosm_bulk_queue_point();

				$bulk = slosm_bulk_action();

				slosm_bulk_run( array( 1 ), array( 'bulk' => $bulk, 'user' => 4 ) );

				assert_same( array( 1 => Bulk_Geocode::GEOCODED ), slosm_bulk_outcomes( 4 ) );

				$GLOBALS['slosm_stub']['current_user_id'] = 9;

				assert_same( '', slosm_bulk_notice( $bulk ) );

				$GLOBALS['slosm_stub']['current_user_id'] = 4;

				assert_contains( 'One', slosm_bulk_notice( $bulk ) );
			}
		);
	}
);

describe(
	'where the editor lands afterwards',
	function () {

		before_each(
			function () {
				slosm_bulk_stage( 1, array( 'address' => 'Dobra 1' ), 'One' );
			}
		);

		it(
			'marks the redirect so the screen it leads to knows to print the report',
			function () {
				slosm_bulk_queue_point();

				$sendback = slosm_bulk_run( array( 1 ) );

				assert_contains( Bulk_Geocode::NOTICE_ARG . '=' . Bulk_Geocode::NOTICE_VALUE, $sendback );
				assert_contains( SLOSM_BULK_SENDBACK, $sendback, 'the url core built was replaced rather than added to' );
			}
		);

		it(
			'returns the editor to the unplaced view when that is where they ran it from',
			function () {
				// Core builds the redirect out of wp_get_referer(), which does
				// carry the view — but only while _wp_http_referer survives, and
				// it falls back to a bare edit.php when it does not. This is the
				// case where it did not: the url handed over has no view on it.
				slosm_bulk_queue_point();

				$sendback = slosm_bulk_run( array( 1 ), array( 'unplaced' => true ) );

				assert_contains(
					Locations_List::UNPLACED_ARG . '=' . Locations_List::UNPLACED_VALUE,
					$sendback,
					'the editor is sent back to All after geocoding a batch of unplaced locations'
				);
			}
		);

		it(
			'does not invent the view for an editor who ran it from the full list',
			function () {
				slosm_bulk_queue_point();

				$sendback = slosm_bulk_run( array( 1 ) );

				// The control: the run did happen and did mark the redirect, so
				// the absence below is a decision rather than a url nobody
				// touched.
				assert_contains( Bulk_Geocode::NOTICE_ARG . '=', $sendback );

				assert_false(
					strpos( $sendback, Locations_List::UNPLACED_ARG ) !== false,
					'an editor on All was sent to a filtered list they did not ask for'
				);
			}
		);

		it(
			'does not carry the view twice when core\'s url already has it',
			function () {
				slosm_bulk_queue_point();

				$sendback = slosm_bulk_run(
					array( 1 ),
					array(
						'unplaced' => true,
						'sendback' => SLOSM_BULK_SENDBACK . '&' . Locations_List::UNPLACED_ARG . '=' . Locations_List::UNPLACED_VALUE,
					)
				);

				// The control: the run happened, so the count below is about a
				// url this class handled rather than one it handed straight
				// back.
				assert_contains( Bulk_Geocode::NOTICE_ARG . '=', $sendback );

				assert_same(
					1,
					substr_count( $sendback, Locations_List::UNPLACED_ARG . '=' ),
					'the view argument was added to a url that already carried it'
				);
			}
		);
	}
);

describe(
	'how boot() builds it',
	function () {

		it(
			'hands it the repository the rest of the request shares',
			function () {
				// Not thrift. Store_Repository::flush_cache() carries a
				// once-per-request guard *per instance*, and this run leans on
				// it: it flushes after every successful write and expects all
				// but the first to cost nothing. A repository of its own would
				// still work — and would spend a generation, and an alloptions
				// rewrite, that the rest of the request has already spent.
				//
				// Closures bound to the class scope rather than reflection:
				// ReflectionProperty::setAccessible() is deprecated in PHP 8.5.
				$construct = Closure::bind(
					static function () {
						return new Plugin();
					},
					null,
					Plugin::class
				);

				$plugin = $construct();
				$plugin->boot();

				$bulk = null;

				foreach ( $GLOBALS['slosm_stub']['filters'] as $registrations ) {
					foreach ( $registrations as $registered ) {
						if ( is_array( $registered['callback'] ) && $registered['callback'][0] instanceof Bulk_Geocode ) {
							$bulk = $registered['callback'][0];
						}
					}
				}

				assert_true( $bulk instanceof Bulk_Geocode, 'boot() hooked no bulk action at all' );

				$plugin_repository = Closure::bind(
					static function ( Plugin $p ) {
						return $p->repository;
					},
					null,
					Plugin::class
				);

				$bulk_repository = Closure::bind(
					static function ( Bulk_Geocode $b ) {
						return $b->repository;
					},
					null,
					Bulk_Geocode::class
				);

				assert_true( $plugin_repository( $plugin ) instanceof Store_Repository );
				assert_same(
					$plugin_repository( $plugin ),
					$bulk_repository( $bulk ),
					'the bulk action reads and flushes through a repository of its own'
				);

				// And no geocoder until something needs one, so an admin
				// request that never runs the action never constructs one.
				$bulk_geocoder = Closure::bind(
					static function ( Bulk_Geocode $b ) {
						return $b->geocoder;
					},
					null,
					Bulk_Geocode::class
				);

				assert_same( null, $bulk_geocoder( $bulk ) );
			}
		);
	}
);

describe(
	'keeping the unplaced view across the screen\'s own form',
	function () {

		it(
			'prints the hidden input on the unplaced view',
			function () {
				// `<form id="posts-filter" method="get">` carries only
				// post_status, post_type, author and show_sticky (wp-admin/edit.php
				// lines 488-500 of WordPress 6.9.1), and a GET form replaces the
				// whole query string with its own fields. Without this, pressing
				// Search from this view lands the editor back on All.
				$_GET[ Locations_List::UNPLACED_ARG ] = wp_slash( Locations_List::UNPLACED_VALUE );

				try {
					ob_start();
					( new Locations_List( new Store_Repository() ) )->keep_view( Post_Type::POST_TYPE, 'top' );
					$html = (string) ob_get_clean();
				} finally {
					$_GET = array();
				}

				assert_contains( 'type="hidden"', $html );
				assert_contains( 'name="' . Locations_List::UNPLACED_ARG . '"', $html );
				assert_contains( 'value="' . Locations_List::UNPLACED_VALUE . '"', $html );
			}
		);

		it(
			'prints nothing on the full list, on another post type\'s screen, or at the foot of the table',
			function () {
				$render = static function ( $post_type, $which, bool $unplaced ): string {
					$_GET = array();

					if ( $unplaced ) {
						$_GET[ Locations_List::UNPLACED_ARG ] = wp_slash( Locations_List::UNPLACED_VALUE );
					}

					try {
						ob_start();
						( new Locations_List( new Store_Repository() ) )->keep_view( $post_type, $which );

						return (string) ob_get_clean();
					} finally {
						$_GET = array();
					}
				};

				// The control: the same call with the view asked for prints the
				// input, so the three empties below are about the arguments.
				assert_contains( 'type="hidden"', $render( Post_Type::POST_TYPE, 'top', true ) );

				// On All the argument would be submitted from a view nobody
				// asked for.
				assert_same( '', $render( Post_Type::POST_TYPE, 'top', false ) );

				// Another post type's list table, which fires the same hook.
				assert_same( '', $render( 'post', 'top', true ) );

				// The foot of the table. WP_Posts_List_Table fires this inside
				// its 'top' branch only; a list table that fires it at both ends
				// would otherwise put the argument in the url twice.
				assert_same( '', $render( Post_Type::POST_TYPE, 'bottom', true ) );

				// And a post type argument that is not a string at all.
				assert_same( '', $render( array( Post_Type::POST_TYPE ), 'top', true ) );
			}
		);
	}
);
