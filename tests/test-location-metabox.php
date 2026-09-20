<?php
/**
 * Proves the one screen an editor actually types a location into.
 *
 * Three things are being pinned here, and they fail in different ways.
 *
 * Security. A save handler on save_post runs on somebody else's request as
 * often as on the editor's own: the block editor saves the post itself over
 * REST and posts the metabox separately, WP-CLI saves without any $_POST at
 * all, and an importer saves in a loop. A handler with no nonce check writes
 * twelve empty strings over a complete location every time one of those
 * happens, which is not only a security hole but the most effective way to
 * empty a site's locator that this plugin contains.
 *
 * Coordinates. Store refuses to guess, deliberately, and this is the only place
 * in the plugin where the refusal can be shown to a person. A comma decimal and
 * a latitude of 91 are the two shapes that arrive from real keyboards and real
 * spreadsheets, and both are silent everywhere else: the comma becomes an
 * unplaced location, the 91 becomes one row that drags fitBounds() to the pole
 * and zooms every other marker off the screen.
 *
 * The rate limit. Geocoding on save is correct only if it is rare. Every case
 * below that says a save did not geocode counts http requests rather than
 * comparing coordinates, because coordinates that did not change are exactly
 * what a needless second lookup returns.
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

use Asymetria\StoreLocator\Admin\Admin;
use Asymetria\StoreLocator\Geocoder;
use Asymetria\StoreLocator\Plugin;
use Asymetria\StoreLocator\Store_Repository;

if ( ! defined( 'SLOSM_MB_POST_ID' ) ) {
	/**
	 * The post id every case here works on.
	 *
	 * Not 1. The nonce action carries the post id, so a fixture on post 1 would
	 * agree with a handler that built the action out of anything truthy.
	 */
	define( 'SLOSM_MB_POST_ID', 7 );
}

if ( ! function_exists( 'slosm_mb_post' ) ) {
	/**
	 * One location post, shaped the way save_post hands them over.
	 *
	 * Touches no stub state, so it is safe to call from anywhere.
	 *
	 * @param int $id Post id.
	 * @return object
	 */
	function slosm_mb_post( int $id = SLOSM_MB_POST_ID ): object {
		return (object) array(
			'ID'           => $id,
			'post_title'   => 'Warszawa',
			'post_content' => 'Nasza pierwsza kawiarnia.',
			'post_type'    => 'slosm_store',
			'post_status'  => 'publish',
		);
	}
}

if ( ! function_exists( 'slosm_mb_fields' ) ) {
	/**
	 * A complete submitted row, as an editor would leave the form.
	 *
	 * Every field is filled in, so a case that changes one is changing a value
	 * rather than filling a blank.
	 *
	 * @param array $overrides Fields to replace.
	 * @return array
	 */
	function slosm_mb_fields( array $overrides = array() ): array {
		return array_merge(
			array(
				'address'  => 'Nowy Świat 1',
				'address2' => 'lokal 3',
				'city'     => 'Warszawa',
				'state'    => 'mazowieckie',
				'zip'      => '00-001',
				'country'  => 'Polska',
				'phone'    => '+48 22 000 00 00',
				'email'    => 'kontakt@example.com',
				'url'      => 'https://example.com',
				'hours'    => "pn-pt 9-17\nsb 10-14",
				'lat'      => '52.2297',
				'lng'      => '21.0122',
			),
			$overrides
		);
	}
}

if ( ! function_exists( 'slosm_mb_stage' ) ) {
	/**
	 * Stages what is already in the database for a location.
	 *
	 * Written through Store_Repository::META_KEYS rather than through literal
	 * meta keys, because the mapping is that class's knowledge and a fixture
	 * that restated it would be a second copy to keep in step. One case below
	 * uses the literals on purpose, and says why.
	 *
	 * Stub state is written, so this may only be called from inside it().
	 *
	 * @param array $fields Field name to stored value.
	 * @param int   $id     Post id.
	 * @return void
	 */
	function slosm_mb_stage( array $fields, int $id = SLOSM_MB_POST_ID ): void {
		foreach ( $fields as $field => $value ) {
			update_post_meta( $id, Store_Repository::META_KEYS[ $field ], $value );
		}
	}
}

if ( ! function_exists( 'slosm_mb_stored' ) ) {
	/**
	 * What is in the database for a location now, as a full record.
	 *
	 * Read back through the repository, which is the only class allowed to know
	 * where a field lives — so a case asserting on a saved value is asserting
	 * that the value can be read back the way the front end will read it, not
	 * merely that some row landed in some meta key.
	 *
	 * @param int $id Post id.
	 * @return array
	 */
	function slosm_mb_stored( int $id = SLOSM_MB_POST_ID ): array {
		return ( new Store_Repository() )->to_store( slosm_mb_post( $id ) )->to_full_array();
	}
}

if ( ! function_exists( 'slosm_mb_admin' ) ) {
	/**
	 * An admin object over a geocoder wired to the stub clock and sleeper.
	 *
	 * Production defaults to microtime( true ) and usleep(), and a case using
	 * those would block for a real second the moment a save geocoded twice.
	 * The repository is a real one; its default loader is never reached here,
	 * because to_store() is handed a post object directly.
	 *
	 * @return Admin
	 */
	function slosm_mb_admin(): Admin {
		return new Admin( new Store_Repository(), new Geocoder( 'slosm_stub_time', 'slosm_stub_sleep' ) );
	}
}

if ( ! function_exists( 'slosm_mb_save' ) ) {
	/**
	 * Fills $_POST the way a browser would and runs the save handler.
	 *
	 * The values are slashed on the way in, because WordPress slashes $_POST
	 * before any plugin sees it — wp_magic_quotes() in wp-includes/load.php —
	 * and a fixture that did not would make a handler that forgot wp_unslash()
	 * look correct.
	 *
	 * $_POST is emptied again in a finally, so no case can leak a submission
	 * into the next one or into another file.
	 *
	 * Options: 'id', 'nonce' (null omits the field), 'capability' (false
	 * withholds it), 'admin', 'post' (null passes null, rather than defaulting)
	 * and 'user'.
	 *
	 * Stub state is written, so this may only be called from inside it().
	 *
	 * @param array $fields  Submitted field values, unslashed.
	 * @param array $options See above.
	 * @return Admin The admin object that ran, so a case can reuse it.
	 */
	function slosm_mb_save( array $fields, array $options = array() ): Admin {
		$id = $options['id'] ?? SLOSM_MB_POST_ID;

		$GLOBALS['slosm_stub']['current_user_id'] = $options['user'] ?? 1;

		if ( ! array_key_exists( 'capability', $options ) || false !== $options['capability'] ) {
			$GLOBALS['slosm_stub']['capabilities']['edit_post'] = true;
		}

		$_POST = array();

		foreach ( $fields as $field => $value ) {
			$_POST[ 'slosm_' . $field ] = wp_slash( $value );
		}

		if ( array_key_exists( 'nonce', $options ) ) {
			if ( null !== $options['nonce'] ) {
				$_POST['slosm_location_nonce'] = $options['nonce'];
			}
		} else {
			$_POST['slosm_location_nonce'] = 'nonce:slosm_save_location_' . $id;
		}

		$admin = $options['admin'] ?? slosm_mb_admin();

		try {
			$admin->save( $id, array_key_exists( 'post', $options ) ? $options['post'] : slosm_mb_post( $id ) );
		} finally {
			$_POST = array();
		}

		return $admin;
	}
}

if ( ! function_exists( 'slosm_mb_messages' ) ) {
	/**
	 * The messages the last save left for the editor.
	 *
	 * Read out of the transient rather than out of rendered html, so a case
	 * about what an editor is told does not also depend on the markup. One
	 * case below reads the markup instead, which is what proves the two ends
	 * are connected.
	 *
	 * @param int $id   Post id.
	 * @param int $user User id.
	 * @return array
	 */
	function slosm_mb_messages( int $id = SLOSM_MB_POST_ID, int $user = 1 ): array {
		$texts = array();

		foreach ( slosm_mb_raw_messages( $id, $user ) as $message ) {
			$texts[] = (string) ( $message['text'] ?? '' );
		}

		return $texts;
	}
}

if ( ! function_exists( 'slosm_mb_raw_messages' ) ) {
	/**
	 * The same, with the severity each message carries.
	 *
	 * A value this class refused and a note about work it did are two different
	 * things, and the box prints them in two different notices. One case below
	 * reads this; everything else only cares what was said.
	 *
	 * @param int $id   Post id.
	 * @param int $user User id.
	 * @return array
	 */
	function slosm_mb_raw_messages( int $id = SLOSM_MB_POST_ID, int $user = 1 ): array {
		$messages = get_transient( 'slosm_location_notices_' . $id . '_' . $user );

		return is_array( $messages ) ? $messages : array();
	}
}

if ( ! function_exists( 'slosm_mb_said' ) ) {
	/**
	 * Whether any message the save left contains a substring.
	 *
	 * @param string $needle Substring.
	 * @param int    $id     Post id.
	 * @return bool
	 */
	function slosm_mb_said( string $needle, int $id = SLOSM_MB_POST_ID ): bool {
		foreach ( slosm_mb_messages( $id ) as $message ) {
			if ( false !== strpos( (string) $message, $needle ) ) {
				return true;
			}
		}

		return false;
	}
}

if ( ! function_exists( 'slosm_mb_queue_point' ) ) {
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
	function slosm_mb_queue_point( string $lat = '52.2297', string $lon = '21.0122', string $label = 'Nowy Świat 1, Warszawa' ): void {
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

if ( ! function_exists( 'slosm_mb_lookups' ) ) {
	/**
	 * How many times anything asked a geocoding service a question.
	 *
	 * The number that matters. A "did not re-geocode" case that compared
	 * coordinates would pass perfectly with the guard deleted, because a second
	 * lookup of the same address returns the same point.
	 *
	 * @return int
	 */
	function slosm_mb_lookups(): int {
		return count( $GLOBALS['slosm_stub']['http_requests'] );
	}
}

if ( ! function_exists( 'slosm_mb_child' ) ) {
	/**
	 * Runs one save in a child process, with or without DOING_AUTOSAVE.
	 *
	 * A child because the flag is a constant: defining it in this process would
	 * define it for every case after this one. The same device, and the same
	 * reason, as the autosave case in tests/test-cache-invalidation.php.
	 *
	 * The child stages a phone number, posts a different one with a valid nonce
	 * and echoes what is stored afterwards. Nothing about the address changes,
	 * so neither child geocodes and neither needs a staged http response — which
	 * matters, because an unqueued request in a child is a fatal nobody reads.
	 *
	 * The paths go through var_export() rather than into the string, so a
	 * directory with a quote in it cannot write the child's source for us.
	 *
	 * @param bool $autosave Whether the child defines DOING_AUTOSAVE.
	 * @return string Whatever the child printed, including any error output.
	 */
	function slosm_mb_child( bool $autosave ): string {
		$root = dirname( __DIR__ );

		$requires = '';

		foreach (
			array(
				__DIR__ . '/bootstrap.php',
				$root . '/includes/class-store.php',
				$root . '/includes/class-post-type.php',
				$root . '/includes/class-store-repository.php',
				$root . '/includes/class-geo.php',
				$root . '/includes/class-geocoder.php',
				$root . '/admin/class-admin.php',
			) as $file
		) {
			$requires .= 'require ' . var_export( $file, true ) . ';' . "\n";
		}

		$child = '<?php' . "\n"
			. ( $autosave ? 'define( ' . var_export( 'DOING_AUTOSAVE', true ) . ', true );' . "\n" : '' )
			. $requires
			. '$GLOBALS[' . var_export( 'slosm_stub', true ) . '][' . var_export( 'capabilities', true ) . '][' . var_export( 'edit_post', true ) . '] = true;' . "\n"
			. '$GLOBALS[' . var_export( 'slosm_stub', true ) . '][' . var_export( 'current_user_id', true ) . '] = 1;' . "\n"
			. 'update_post_meta( 7, ' . var_export( '_slosm_phone', true ) . ', ' . var_export( 'stary', true ) . ' );' . "\n"
			. '$_POST = array( ' . var_export( 'slosm_location_nonce', true ) . ' => ' . var_export( 'nonce:slosm_save_location_7', true ) . ', ' . var_export( 'slosm_phone', true ) . ' => ' . var_export( 'nowy', true ) . ' );' . "\n"
			. '$post = (object) array( ' . var_export( 'ID', true ) . ' => 7, ' . var_export( 'post_title', true ) . ' => ' . var_export( 'Warszawa', true ) . ', ' . var_export( 'post_content', true ) . ' => ' . var_export( '', true ) . ', ' . var_export( 'post_type', true ) . ' => ' . var_export( 'slosm_store', true ) . ', ' . var_export( 'post_status', true ) . ' => ' . var_export( 'publish', true ) . ' );' . "\n"
			. '( new \Asymetria\StoreLocator\Admin\Admin() )->save( 7, $post );' . "\n"
			. 'echo ' . var_export( 'PHONE=', true ) . ', var_export( get_post_meta( 7, ' . var_export( '_slosm_phone', true ) . ', true ), true );' . "\n";

		// No .php suffix: the CLI binary runs a file whatever it is called, and
		// appending one would orphan the file tempnam() itself created.
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
	'the fields the metabox owns',
	function () {

		it(
			'covers every stored field and invents none',
			function () {
				// The metabox states the field list for the fifth time in this
				// plugin — after Store::FIELDS, the constructor, from_array() and
				// to_full_array() — and a field left out of it is a field an
				// editor can never fill in, with nothing anywhere to report it.
				// Store::from_array() ignores keys it does not know, so a typo is
				// not an error either: it is one field that is empty on every
				// location on every site, for ever.
				$handled = array_merge(
					Admin::ADDRESS_FIELDS,
					Admin::CONTACT_FIELDS,
					Admin::HOURS_FIELDS,
					Admin::COORDINATE_FIELDS,
					array( 'lat_locked' )
				);

				$stored = array_keys( Store_Repository::META_KEYS );

				sort( $handled );
				sort( $stored );

				assert_same( $stored, $handled );
			}
		);

		it(
			'keeps its groups apart',
			function () {
				// The three columns and the coordinates below them are four
				// disjoint lists, not four views of one. A field in two of them
				// is rendered twice and saved twice, with the second write
				// deciding.
				$groups = array(
					Admin::ADDRESS_FIELDS,
					Admin::CONTACT_FIELDS,
					Admin::HOURS_FIELDS,
					Admin::COORDINATE_FIELDS,
				);

				$seen = array();

				foreach ( $groups as $group ) {
					foreach ( $group as $field ) {
						assert_false( in_array( $field, $seen, true ), $field . ' is in two groups' );
						$seen[] = $field;
					}
				}

				// And the coordinates are the pair, in the order the rest of the
				// plugin names them.
				assert_same( array( 'lat', 'lng' ), Admin::COORDINATE_FIELDS );
			}
		);
	}
);

describe(
	'the metabox on the location screen',
	function () {

		before_each(
			function () {
				$_POST = array();
			}
		);

		it(
			'registers one box, on the locations screen, above the editor',
			function () {
				$admin = slosm_mb_admin();

				$admin->add_meta_box( slosm_mb_post() );

				$boxes = $GLOBALS['slosm_stub']['meta_boxes'];

				assert_same( 1, count( $boxes ) );
				assert_same( 'slosm-location', $boxes[0]['id'] );
				assert_same( 'slosm_store', $boxes[0]['screen'] );
				assert_same( 'normal', $boxes[0]['context'] );
				assert_same( 'high', $boxes[0]['priority'] );
				assert_same( array( $admin, 'render' ), $boxes[0]['callback'] );
			}
		);

		it(
			'renders a nonce tied to this location, and one control per field',
			function () {
				$admin = slosm_mb_admin();

				ob_start();
				$admin->render( slosm_mb_post() );
				$html = (string) ob_get_clean();

				assert_contains( 'name="slosm_location_nonce"', $html );
				assert_contains( 'value="nonce:slosm_save_location_7"', $html );

				foreach ( array( 'address', 'address2', 'city', 'state', 'zip', 'country', 'phone', 'email', 'url', 'lat', 'lng' ) as $field ) {
					assert_contains( 'name="slosm_' . $field . '"', $html );
				}

				// The one control that is not an input, because a week of
				// opening hours is several lines and an <input> cannot hold one.
				assert_contains( '<textarea', $html );
				assert_contains( 'name="slosm_hours"', $html );
			}
		);

		it(
			'shows what is stored, escaped for the attribute it sits in',
			function () {
				slosm_mb_stage(
					array(
						'address' => 'Bar "Pod Wierzbą" <Pub>',
						'city'    => 'Łódź',
						'lat'     => '52.2297',
					)
				);

				ob_start();
				slosm_mb_admin()->render( slosm_mb_post() );
				$html = (string) ob_get_clean();

				assert_contains( 'value="Bar &quot;Pod Wierzbą&quot; &lt;Pub&gt;"', $html );
				assert_contains( 'value="Łódź"', $html );
				assert_contains( 'value="52.2297"', $html );

				// The control for the two above: an unescaped render would put
				// this exact run of characters into the markup and end the
				// value attribute two words early.
				assert_false(
					false !== strpos( $html, 'value="Bar "Pod' ),
					'the address was written into the attribute unescaped'
				);
			}
		);

		it(
			'keeps the newlines in the opening hours',
			function () {
				slosm_mb_stage( array( 'hours' => "pn-pt 9-17\nsb 10-14" ) );

				ob_start();
				slosm_mb_admin()->render( slosm_mb_post() );
				$html = (string) ob_get_clean();

				assert_contains( "pn-pt 9-17\nsb 10-14", $html );
			}
		);

		it(
			'tells the editor what the last save did, once',
			function () {
				set_transient(
					'slosm_location_notices_7_1',
					array( array( 'text' => 'Szerokość 52,2297 odczytano jako 52.2297.', 'warning' => true ) ),
					300
				);
				$GLOBALS['slosm_stub']['current_user_id'] = 1;

				ob_start();
				slosm_mb_admin()->render( slosm_mb_post() );
				$first = (string) ob_get_clean();

				assert_contains( 'Szerokość 52,2297 odczytano jako 52.2297.', $first );

				// Read once and gone. A message that survived its own rendering
				// would reappear on every later visit to the screen, describing
				// a save nobody can remember making.
				ob_start();
				slosm_mb_admin()->render( slosm_mb_post() );
				$second = (string) ob_get_clean();

				assert_false(
					false !== strpos( $second, 'Szerokość 52,2297 odczytano jako 52.2297.' ),
					'the message was shown a second time'
				);
			}
		);

		it(
			'escapes a message rather than trusting it',
			function () {
				// The messages carry values an editor typed. The comma message
				// quotes the latitude back, so a message is a path from a form
				// field to the admin screen's html.
				set_transient(
					'slosm_location_notices_7_1',
					array( array( 'text' => '<script>alert(1)</script>', 'warning' => true ) ),
					300
				);
				$GLOBALS['slosm_stub']['current_user_id'] = 1;

				ob_start();
				slosm_mb_admin()->render( slosm_mb_post() );
				$html = (string) ob_get_clean();

				assert_contains( '&lt;script&gt;', $html );
				assert_false( false !== strpos( $html, '<script>alert(1)</script>' ), 'a message was printed as markup' );
			}
		);
	}
);

describe(
	'who may save a location',
	function () {

		before_each(
			function () {
				$_POST = array();
			}
		);

		it(
			'saves the whole row when the nonce and the capability are both there',
			function () {
				// The control every case in this group is measured against.
				// Without it, "nothing was written" proves nothing at all.
				slosm_mb_save( slosm_mb_fields() );

				$stored = slosm_mb_stored();

				assert_same( 'Nowy Świat 1', $stored['address'] );
				assert_same( 'Warszawa', $stored['city'] );
				assert_same( '+48 22 000 00 00', $stored['phone'] );
				assert_same( 52.2297, $stored['lat'] );
			}
		);

		it(
			'writes nothing when the form carried no nonce at all',
			function () {
				slosm_mb_stage( array( 'address' => 'Nowy Świat 1', 'city' => 'Warszawa' ) );

				// This is not only the security case. The block editor saves the
				// post itself over REST, and WP-CLI and importers save with no
				// $_POST whatever: on every one of those, save_post fires with
				// none of these fields present. A handler without this guard
				// writes twelve empty strings over a complete location.
				slosm_mb_save( array(), array( 'nonce' => null ) );

				$stored = slosm_mb_stored();

				assert_same( 'Nowy Świat 1', $stored['address'] );
				assert_same( 'Warszawa', $stored['city'] );
			}
		);

		it(
			'writes nothing when the nonce is not the one for this location',
			function () {
				slosm_mb_stage( array( 'city' => 'Warszawa' ) );

				// A valid nonce, for post 8. If the action did not carry the post
				// id this would be accepted, and one open form would be a licence
				// to write to any location on the site.
				slosm_mb_save(
					slosm_mb_fields( array( 'city' => 'Kraków' ) ),
					array( 'nonce' => 'nonce:slosm_save_location_8' )
				);

				assert_same( 'Warszawa', slosm_mb_stored()['city'] );
			}
		);

		it(
			'writes nothing when the nonce is rubbish',
			function () {
				slosm_mb_stage( array( 'city' => 'Warszawa' ) );

				slosm_mb_save( slosm_mb_fields( array( 'city' => 'Kraków' ) ), array( 'nonce' => 'not-a-nonce' ) );

				assert_same( 'Warszawa', slosm_mb_stored()['city'] );
			}
		);

		it(
			'still accepts a form that was open overnight',
			function () {
				// wp_verify_nonce() returns int 2 for a nonce from the previous
				// twelve-hour tick, never true. A handler written as
				// `true === wp_verify_nonce( ... )` refuses this save, silently,
				// and the editor sees their changes vanish with no message.
				slosm_mb_save(
					slosm_mb_fields( array( 'city' => 'Kraków' ) ),
					array( 'nonce' => 'stale:slosm_save_location_7' )
				);

				assert_same( 'Kraków', slosm_mb_stored()['city'] );
			}
		);

		it(
			'writes nothing when the user may not edit this location',
			function () {
				slosm_mb_stage( array( 'city' => 'Warszawa' ) );

				slosm_mb_save( slosm_mb_fields( array( 'city' => 'Kraków' ) ), array( 'capability' => false ) );

				assert_same( 'Warszawa', slosm_mb_stored()['city'] );
			}
		);

		it(
			'asks about this location rather than about locations in general',
			function () {
				slosm_mb_save( slosm_mb_fields() );

				$asked = false;

				foreach ( $GLOBALS['slosm_stub']['cap_checks'] as $check ) {
					if ( 'edit_post' === $check['capability'] && array( SLOSM_MB_POST_ID ) === $check['args'] ) {
						$asked = true;
					}
				}

				// edit_post without the post id is edit_posts in disguise: it
				// answers "may this user edit something" and lets a contributor
				// write to a location somebody else owns.
				assert_true( $asked, 'the capability was never checked against this post id' );
			}
		);

		it(
			'writes nothing for a post id that is not one',
			function () {
				slosm_mb_stage( array( 'city' => 'Warszawa' ) );

				$admin = slosm_mb_admin();

				$_POST = array(
					'slosm_location_nonce' => 'nonce:slosm_save_location_0',
					'slosm_city'           => 'Kraków',
				);

				$GLOBALS['slosm_stub']['capabilities']['edit_post'] = true;

				try {
					$admin->save( 0, slosm_mb_post( 0 ) );
				} finally {
					$_POST = array();
				}

				// Post 0 is not a post. Writing meta to it is writing rows nobody
				// will ever read back, under an id update_metadata() itself
				// refuses, so the handler has to stop rather than try.
				assert_same( array(), $GLOBALS['slosm_stub']['post_meta'][0] ?? array() );
				assert_same( 'Warszawa', slosm_mb_stored()['city'] );
			}
		);

		it(
			'does nothing when the hook was fired without the location',
			function () {
				slosm_mb_stage( slosm_mb_fields() );

				// save_post always passes the post, and something that fired the
				// hook by hand may not. to_store() needs the row rather than the
				// id, so the alternative to this guard is a TypeError inside a
				// save — which takes the editor's save with it.
				slosm_mb_save( slosm_mb_fields( array( 'city' => 'Kraków' ) ), array( 'post' => null ) );

				assert_same( 'Warszawa', slosm_mb_stored()['city'] );
			}
		);

		it(
			'stores nothing for a field posted as an array',
			function () {
				slosm_mb_stage( slosm_mb_fields() );

				$admin = slosm_mb_admin();

				$GLOBALS['slosm_stub']['capabilities']['edit_post'] = true;
				$GLOBALS['slosm_stub']['current_user_id']           = 1;

				$_POST = array( 'slosm_location_nonce' => 'nonce:slosm_save_location_7' );

				foreach ( slosm_mb_fields() as $field => $value ) {
					$_POST[ 'slosm_' . $field ] = wp_slash( $value );
				}

				// Trivially arranged by hand: name="slosm_phone[]". (string) on an
				// array is a warning and the word "Array" in PHP 8, and this
				// suite's framework turns a warning into a failed case — so this
				// is a case about the guard rather than about the value.
				$_POST['slosm_phone'] = array( '+48 22 000 00 00' );

				try {
					$admin->save( SLOSM_MB_POST_ID, slosm_mb_post() );
				} finally {
					$_POST = array();
				}

				assert_same( '', slosm_mb_stored()['phone'] );
			}
		);

		it(
			'ignores an autosave, even one carrying the whole form',
			function () {
				// A child process, because DOING_AUTOSAVE is a constant: defining
				// it here would define it for every case that runs afterwards and
				// quietly turn every save above into a no-op. The same reason
				// tests/test-cache-invalidation.php runs one.
				//
				// The guard is defence in depth rather than a path anybody has
				// seen: the classic editor's autosave posts the title, the
				// content and the excerpt, and the block editor's goes to the
				// REST autosave route, so neither carries this box's nonce. What
				// it buys is the site whose editor plugin posts the whole form —
				// one Nominatim request a minute while somebody types an address,
				// against a service that allows one a second.
				//
				// Only the phone number changes, so neither child geocodes and
				// neither needs a staged http response.
				$autosaved = slosm_mb_child( true );
				$saved     = slosm_mb_child( false );

				assert_contains(
					"PHONE='stary'",
					$autosaved,
					"an autosave wrote the form; the child process said:\n" . trim( $autosaved )
				);

				// The control, and it is not optional: without it this case passes
				// when the child dies on line one, when the nonce is wrong, and
				// when the handler saves nothing at all under any conditions.
				assert_contains(
					"PHONE='nowy'",
					$saved,
					"the control child did not save either, so the case above proves nothing; it said:\n" . trim( $saved )
				);
			}
		);
	}
);

describe(
	'what a saved field goes through',
	function () {

		before_each(
			function () {
				$_POST = array();
			}
		);

		it(
			'strips markup out of the text fields',
			function () {
				slosm_mb_save(
					slosm_mb_fields(
						array(
							'address' => 'Nowy <script>alert(1)</script> Świat 1',
							'city'    => 'Bar <Pub',
						)
					)
				);

				$stored = slosm_mb_stored();

				assert_same( 'Nowy Świat 1', $stored['address'] );

				// A "<" with no ">" is text, not a tag. strip_tags() alone eats
				// it and everything after it, which is how "Bar <Pub" becomes
				// "Bar".
				assert_same( 'Bar &lt;Pub', $stored['city'] );
			}
		);

		it(
			'keeps the newlines in the opening hours and collapses them nowhere else',
			function () {
				slosm_mb_save(
					slosm_mb_fields(
						array(
							'hours'   => "pn-pt 9-17\nsb 10-14\nnd nieczynne",
							'address' => "Nowy\nŚwiat 1",
						)
					)
				);

				$stored = slosm_mb_stored();

				// sanitize_textarea_field() rather than sanitize_text_field():
				// the second collapses a week of opening hours onto one line.
				assert_same( "pn-pt 9-17\nsb 10-14\nnd nieczynne", $stored['hours'] );

				// And the control, so that "keeps newlines" cannot be satisfied
				// by never collapsing anything.
				assert_same( 'Nowy Świat 1', $stored['address'] );
			}
		);

		it(
			'stores an apostrophe and a backslash exactly as typed',
			function () {
				// $_POST is slashed before any plugin sees it, and
				// update_post_meta() unslashes what it is given. Getting either
				// half wrong is invisible until somebody names a shop O'Brien's.
				slosm_mb_save( slosm_mb_fields( array( 'address' => "O'Brien's \\ Deli" ) ) );

				assert_same( "O'Brien's \\ Deli", slosm_mb_stored()['address'] );
			}
		);

		it(
			'keeps an email that is one',
			function () {
				slosm_mb_save( slosm_mb_fields( array( 'email' => 'kontakt@example.com' ) ) );

				assert_same( 'kontakt@example.com', slosm_mb_stored()['email'] );
				assert_same( array(), slosm_mb_messages() );
			}
		);

		it(
			'empties an email that is not one, and says so',
			function () {
				slosm_mb_stage( array( 'email' => 'kontakt@example.com' ) );

				// A domain with no dot. WordPress refuses it, however willing a
				// mail server might be, and a stored non-address becomes a
				// mailto: link that goes nowhere.
				slosm_mb_save( slosm_mb_fields( array( 'email' => 'kontakt@sklep' ) ) );

				assert_same( '', slosm_mb_stored()['email'] );
				assert_true( slosm_mb_said( 'kontakt@sklep' ), 'the editor was not told the email was dropped' );
			}
		);

		it(
			'leaves an empty email empty without complaining',
			function () {
				slosm_mb_save( slosm_mb_fields( array( 'email' => '' ) ) );

				assert_same( '', slosm_mb_stored()['email'] );
				assert_same( array(), slosm_mb_messages() );
			}
		);

		it(
			'keeps a website that is http or https',
			function () {
				slosm_mb_save( slosm_mb_fields( array( 'url' => 'https://example.com/o-nas' ) ) );

				assert_same( 'https://example.com/o-nas', slosm_mb_stored()['url'] );
			}
		);

		it(
			'gives a bare domain a scheme, and the same one on every WordPress',
			function () {
				slosm_mb_save( slosm_mb_fields( array( 'url' => 'example.com' ) ) );

				// https, decided here rather than left to esc_url(), because
				// https is the right default for a bare domain. Not because the
				// two supported WordPress versions disagree: esc_url() takes the
				// scheme from array_first( $protocols ), and the list this plugin
				// hands it starts with 'http', so 6.9 would prepend http:// just
				// as 6.0 does. There is no divergence — only a preference, and
				// this is where it is expressed.
				assert_same( 'https://example.com', slosm_mb_stored()['url'] );
			}
		);

		it(
			'refuses a website whose scheme is not one a browser should follow',
			function () {
				// esc_url_raw() allows twenty-two protocols, not two:
				// wp_allowed_protocols() in wp-includes/functions.php of 6.9.1
				// lists ftp, mailto, telnet, svn, webcal and the rest. A website
				// field is for a website.
				foreach ( array( 'ftp://example.com', 'mailto:kontakt@example.com', 'javascript:alert(1)', 'tel:+48220000000' ) as $url ) {
					slosm_mb_stage( array( 'url' => 'https://example.com' ) );

					slosm_mb_save( slosm_mb_fields( array( 'url' => $url ) ) );

					assert_same( '', slosm_mb_stored()['url'], $url . ' was stored as a website' );
					assert_true( slosm_mb_said( $url ), 'the editor was not told about ' . $url );
				}
			}
		);

		it(
			'keeps a percent escape in a website',
			function () {
				// sanitize_text_field() deletes every %xx sequence it finds —
				// core's _sanitize_text_fields() does, and so does the stub — so
				// running a url through it turns /o%20nas into /onas. The
				// website field is sanitised by esc_url_raw() and nothing else.
				slosm_mb_save( slosm_mb_fields( array( 'url' => 'https://example.com/o%20nas' ) ) );

				assert_same( 'https://example.com/o%20nas', slosm_mb_stored()['url'] );
			}
		);

		it(
			'writes through the repository mapping rather than meta keys of its own',
			function () {
				// The literal keys, on purpose and only here. Everything else in
				// this file reads back through the repository, which would agree
				// with the metabox about a wrong key just as happily as about a
				// right one.
				slosm_mb_save( slosm_mb_fields() );

				assert_same( 'Warszawa', get_post_meta( SLOSM_MB_POST_ID, '_slosm_city', true ) );
				assert_same( '00-001', get_post_meta( SLOSM_MB_POST_ID, '_slosm_zip', true ) );
				assert_same( '52.2297', get_post_meta( SLOSM_MB_POST_ID, '_slosm_lat', true ) );
			}
		);

		it(
			'saves every field it renders, not only the ones the cleaner names',
			function () {
				// The covers-every-field case above compares key *sets*, which a
				// field that is rendered, submitted and then silently dropped
				// passes perfectly. This one puts a distinguishable value in
				// every text control and reads all of them back, so a contact
				// field added to the constant and forgotten in clean() fails
				// here instead of shipping as a box with a dead input in it.
				$submitted = slosm_mb_fields();
				$expected  = array();
				$index     = 0;

				foreach ( array_merge( Admin::ADDRESS_FIELDS, Admin::CONTACT_FIELDS, Admin::HOURS_FIELDS ) as $field ) {
					$index++;

					if ( 'email' === $field ) {
						$value = 'pole' . $index . '@example.com';
					} elseif ( 'url' === $field ) {
						$value = 'https://example.com/pole' . $index;
					} else {
						$value = 'wartość ' . $index;
					}

					$submitted[ $field ] = $value;
					$expected[ $field ]  = $value;
				}

				slosm_mb_save( $submitted );

				$stored = slosm_mb_stored();

				foreach ( $expected as $field => $value ) {
					assert_same( $value, $stored[ $field ], $field . ' was rendered and submitted but not saved' );
				}
			}
		);

		it(
			'refuses a website that is only part of one',
			function () {
				// esc_url() returns '/path', '#anchor', '?a=b' and '//host'
				// untouched without ever consulting the protocol list — the
				// `'/' === $url[0]` branch short-circuits it — so leaving these
				// alone would make "an allowlist of two" a claim about half the
				// values this field accepts. A location's website is somewhere a
				// visitor goes.
				foreach ( array( '/wp-admin/', '#kontakt', '?a=b', '//example.com' ) as $url ) {
					slosm_mb_stage( slosm_mb_fields( array( 'url' => 'https://example.com' ) ) );

					slosm_mb_save( slosm_mb_fields( array( 'url' => $url ) ) );

					assert_same( '', slosm_mb_stored()['url'], $url . ' was stored as a website' );
					assert_true( slosm_mb_said( $url ), 'the editor was not told about ' . $url );
				}
			}
		);

		it(
			'puts no browser validation in front of the check it makes itself',
			function () {
				ob_start();
				slosm_mb_admin()->render( slosm_mb_post() );
				$html = (string) ob_get_clean();

				// type="url" carries HTML5 constraint validation, which wants an
				// absolute url — so the classic editor's browser refuses to
				// submit the whole post for "sklep.pl", the exact value
				// clean_url() exists to accept and complete. Worse when the box
				// is collapsed: the invalid control is display:none, Chrome will
				// not focus it, and Update silently does nothing at all.
				assert_false( false !== strpos( $html, 'type="url"' ), 'the website field validates in the browser' );
				assert_false( false !== strpos( $html, 'type="email"' ), 'the email field validates in the browser' );

				// The control, so this cannot be satisfied by rendering no
				// controls at all.
				assert_contains( 'type="text" class="widefat" id="slosm_url"', $html );
				assert_contains( 'type="text" class="widefat" id="slosm_email"', $html );
			}
		);

		it(
			'separates what it refused from what it merely did',
			function () {
				slosm_mb_stage( slosm_mb_fields( array( 'lat' => '', 'lng' => '' ) ) );
				slosm_mb_queue_point();

				slosm_mb_save( slosm_mb_fields( array( 'lat' => '', 'lng' => '', 'email' => 'kontakt@sklep' ) ) );

				$levels = array();

				foreach ( slosm_mb_raw_messages() as $message ) {
					$levels[] = ( ! empty( $message['warning'] ) ? 'warning:' : 'info:' ) . $message['text'];
				}

				assert_same( 2, count( $levels ), 'expected one refusal and one note' );
				assert_contains( 'warning:', $levels[0] );
				assert_contains( 'info:', $levels[1] );

				$GLOBALS['slosm_stub']['current_user_id'] = 1;

				ob_start();
				slosm_mb_admin()->render( slosm_mb_post() );
				$html = (string) ob_get_clean();

				// Two notices, not one. A note about work that succeeded printed
				// in a warning box is how an editor learns to stop reading the
				// box at all.
				assert_contains( 'notice-warning', $html );
				assert_contains( 'notice-info', $html );
			}
		);
	}
);

describe(
	'coordinates an editor typed',
	function () {

		before_each(
			function () {
				$_POST = array();
			}
		);

		it(
			'reads a comma decimal as a dot, and tells the editor it did',
			function () {
				// What a Polish or German keyboard and every spreadsheet on one
				// produce. A (float) cast makes it 52.0, twenty-five kilometres
				// away; Store makes it null, which is a location that silently
				// is not on the map. Neither is acceptable from a field somebody
				// just typed into, and in a coordinate field there is exactly one
				// other thing 52,2297 could mean and it is not a number either.
				slosm_mb_save( slosm_mb_fields( array( 'lat' => '52,2297', 'lng' => '21,0122' ) ) );

				$stored = slosm_mb_stored();

				assert_same( 52.2297, $stored['lat'] );
				assert_same( 21.0122, $stored['lng'] );

				assert_true( slosm_mb_said( '52,2297' ), 'the message does not quote what was typed' );
				assert_true( slosm_mb_said( '52.2297' ), 'the message does not say what it was read as' );
			}
		);

		it(
			'refuses a coordinate field holding more than one number',
			function () {
				slosm_mb_stage( slosm_mb_fields() );

				// A pasted pair. Read as a comma decimal it would be 52.2297 and
				// 21.0122 with the second half of each thrown away — which is
				// right by luck here and wrong the moment the pair is pasted into
				// one field only. Refusing it is the only answer that cannot
				// place a marker somewhere nobody asked for.
				slosm_mb_save( slosm_mb_fields( array( 'lat' => '52.2297, 21.0122' ) ) );

				$stored = slosm_mb_stored();

				assert_same( 52.2297, $stored['lat'], 'the coordinate that was already there was thrown away' );
				assert_true( slosm_mb_said( '52.2297, 21.0122' ), 'the editor was not told the value was refused' );
			}
		);

		it(
			'refuses a coordinate that is not a number at all',
			function () {
				slosm_mb_stage( slosm_mb_fields() );

				slosm_mb_save( slosm_mb_fields( array( 'lat' => 'gdzieś w Warszawie' ) ) );

				assert_same( 52.2297, slosm_mb_stored()['lat'] );
				assert_true( slosm_mb_said( 'gdzieś w Warszawie' ) );
			}
		);

		it(
			'refuses a latitude off the earth, and keeps the one it had',
			function () {
				slosm_mb_stage( slosm_mb_fields() );

				// Nothing downstream crashes on 91: Leaflet clamps to 85.05. What
				// happens instead is that one row drags fitBounds() to the pole
				// and zooms every other marker on the map into invisibility.
				slosm_mb_save( slosm_mb_fields( array( 'lat' => '91' ) ) );

				assert_same( 52.2297, slosm_mb_stored()['lat'] );
				assert_true( slosm_mb_said( '91' ) );

				// The edge itself is on the earth and must be accepted, or a
				// station on the south pole cannot be entered.
				slosm_mb_save( slosm_mb_fields( array( 'lat' => '-90', 'lng' => '180' ) ) );

				assert_same( -90.0, slosm_mb_stored()['lat'] );
				assert_same( 180.0, slosm_mb_stored()['lng'] );
			}
		);

		it(
			"holds longitude to its own range, not to the latitude's",
			function () {
				slosm_mb_stage( slosm_mb_fields() );

				// 100 is a perfectly good longitude and a hopeless latitude. One
				// shared limit of 90 would refuse half the planet; one shared
				// limit of 180 would accept a latitude that cannot exist.
				slosm_mb_save( slosm_mb_fields( array( 'lat' => '52.2297', 'lng' => '100.5' ) ) );

				assert_same( 100.5, slosm_mb_stored()['lng'] );

				slosm_mb_save( slosm_mb_fields( array( 'lat' => '100.5', 'lng' => '21.0122' ) ) );

				assert_same( 52.2297, slosm_mb_stored()['lat'], '100.5 was accepted as a latitude' );

				slosm_mb_save( slosm_mb_fields( array( 'lat' => '52.2297', 'lng' => '181' ) ) );

				assert_same( 21.0122, slosm_mb_stored()['lng'], '181 was accepted as a longitude' );
			}
		);

		it(
			'locks the coordinates when the editor moves them by hand',
			function () {
				slosm_mb_stage( slosm_mb_fields( array( 'lat_locked' => '' ) ) );

				slosm_mb_save( slosm_mb_fields( array( 'lat' => '52.2300', 'lng' => '21.0122' ) ) );

				assert_true( slosm_mb_stored()['lat_locked'] );
			}
		);

		it(
			'does not lock coordinates the form merely handed back unchanged',
			function () {
				slosm_mb_stage( slosm_mb_fields( array( 'lat_locked' => '' ) ) );

				// Every save posts the coordinate fields, whether or not anybody
				// touched them. A handler that locked on every save would lock
				// every geocoded location the first time somebody fixed a typo in
				// the opening hours, and the automatic geocoder would then never
				// touch that site again.
				slosm_mb_save( slosm_mb_fields( array( 'lat' => '52.2297', 'lng' => '21.0122' ) ) );

				assert_false( slosm_mb_stored()['lat_locked'] );
			}
		);

		it(
			'keeps a lock a previous save set',
			function () {
				slosm_mb_stage( slosm_mb_fields( array( 'lat_locked' => '1' ) ) );

				slosm_mb_save( slosm_mb_fields( array( 'lat' => '52.2297', 'lng' => '21.0122' ) ) );

				assert_true( slosm_mb_stored()['lat_locked'] );
			}
		);

		it(
			'clears both coordinates and the lock when both fields are emptied',
			function () {
				slosm_mb_stage( slosm_mb_fields( array( 'lat_locked' => '1' ) ) );

				// Emptying the fields is how an editor says "work it out from the
				// address again" without a button, which is Task 18's job. It has
				// to clear the lock, or the location stays pinned to coordinates
				// that are no longer there.
				slosm_mb_queue_point();

				slosm_mb_save( slosm_mb_fields( array( 'lat' => '', 'lng' => '' ) ) );

				assert_false( slosm_mb_stored()['lat_locked'] );
			}
		);

		it(
			'puts both coordinates back when only one of them was cleared',
			function () {
				slosm_mb_stage( slosm_mb_fields( array( 'lat_locked' => '' ) ) );

				// One Backspace in one field. Half a pair cannot be drawn, so
				// the location leaves the map; the pair differs from what was
				// stored, so the lock goes on; and a locked location is never
				// looked up again. Unguarded, that is a location made silently
				// unmappable for ever by one keystroke — and the box would then
				// explain that its coordinates had been set by hand.
				slosm_mb_save( slosm_mb_fields( array( 'lng' => '' ) ) );

				$stored = slosm_mb_stored();

				assert_same( 52.2297, $stored['lat'] );
				assert_same( 21.0122, $stored['lng'], 'half a pair was stored' );
				assert_false( $stored['lat_locked'], 'clearing one field locked the location' );
				assert_true(
					slosm_mb_said( 'needs both' ),
					'the editor was not told why the field came back'
				);
			}
		);

		it(
			'still lets a later save move a location that lost half a pair',
			function () {
				slosm_mb_stage( slosm_mb_fields( array( 'lat_locked' => '' ) ) );

				slosm_mb_save( slosm_mb_fields( array( 'lng' => '' ) ) );

				// The half-pair save must leave the location exactly as it found
				// it, lock included — so a later address change still reaches the
				// geocoder. This is the assertion that fails if the guard puts
				// the values back but leaves the lock on.
				slosm_mb_queue_point( '50.0614', '19.9366' );

				slosm_mb_save( slosm_mb_fields( array( 'city' => 'Kraków' ) ) );

				assert_same( 1, slosm_mb_lookups() );
				assert_same( 50.0614, slosm_mb_stored()['lat'] );
			}
		);

		it(
			'leaves a pair that was already half exactly as it found it, quietly',
			function () {
				// An import that wrote a latitude and no longitude. Submitting
				// that back unchanged is not a change, so there is nothing to
				// complain about — and complaining on every save of a record
				// nobody can fix from this box would be noise.
				slosm_mb_stage( slosm_mb_fields( array( 'lng' => '', 'lat_locked' => '' ) ) );

				// It has no usable pair, so the address is looked up; that is the
				// separate rule and it still applies.
				slosm_mb_queue_point( '52.2297', '21.0122' );

				slosm_mb_save( slosm_mb_fields( array( 'lng' => '' ) ) );

				assert_false(
					slosm_mb_said( 'needs both' ),
					'a record that was already half was complained about'
				);
			}
		);

		it(
			'does not lock a coordinate the form rounded on its way through',
			function () {
				// Not reachable through this box's own writes, which are always
				// formatted to COORDINATE_DECIMALS. Reachable from any import,
				// and from Task 20's bulk writer if it ever formats differently.
				// The box renders 52.2297012, the next save of any field posts
				// 52.2297012, and float identity calls that a change — so the
				// location locks itself and no lookup ever touches it again.
				slosm_mb_stage( slosm_mb_fields( array( 'lat' => '52.22970123456', 'lat_locked' => '' ) ) );

				slosm_mb_save( slosm_mb_fields( array( 'lat' => '52.2297012' ) ) );

				assert_false( slosm_mb_stored()['lat_locked'], 'a rounded round trip locked the location' );

				// The control: a coordinate that really is a different place
				// still locks, so the comparison has not simply been widened
				// into never noticing anything.
				slosm_mb_save( slosm_mb_fields( array( 'lat' => '52.2300000' ) ) );

				assert_true( slosm_mb_stored()['lat_locked'] );
			}
		);
	}
);

describe(
	'looking an address up on save',
	function () {

		before_each(
			function () {
				$_POST = array();
			}
		);

		it(
			'asks nobody anything when the address is unchanged and the pin is there',
			function () {
				slosm_mb_stage( slosm_mb_fields() );

				slosm_mb_save( slosm_mb_fields( array( 'phone' => '+48 22 111 11 11' ) ) );

				assert_same( 0, slosm_mb_lookups() );
			}
		);

		it(
			'looks the address up when a component of it changed',
			function () {
				slosm_mb_stage( slosm_mb_fields() );
				slosm_mb_queue_point( '50.0614', '19.9366', 'Rynek Główny, Kraków' );

				slosm_mb_save( slosm_mb_fields( array( 'city' => 'Kraków' ) ) );

				assert_same( 1, slosm_mb_lookups() );

				$stored = slosm_mb_stored();

				assert_same( 50.0614, $stored['lat'] );
				assert_same( 19.9366, $stored['lng'] );

				// And the lookup did not pretend the editor placed the pin.
				assert_false( $stored['lat_locked'] );
			}
		);

		it(
			'sends the address this save left, with the components joined',
			function () {
				slosm_mb_stage( slosm_mb_fields() );
				slosm_mb_queue_point();

				slosm_mb_save( slosm_mb_fields( array( 'city' => 'Kraków' ) ) );

				$requested = $GLOBALS['slosm_stub']['http_requests'][0]['url'] ?? '';

				assert_contains( 'nominatim', $requested );

				// rawurlencode(), and of the lowercased form: the geocoder
				// normalises a query before it asks, and build_url() encodes with
				// rawurlencode(), so this is the exact byte sequence on the wire.
				assert_contains( rawurlencode( 'kraków' ), $requested );

				// The phone number is not part of an address, and a query with
				// one in it is a query Nominatim answers with nothing.
				assert_false(
					false !== strpos( $requested, rawurlencode( '+48' ) ),
					'the contact fields went into the geocoding query'
				);
			}
		);

		it(
			'looks the address up when there are no coordinates, unchanged address or not',
			function () {
				slosm_mb_stage( slosm_mb_fields( array( 'lat' => '', 'lng' => '' ) ) );
				slosm_mb_queue_point();

				slosm_mb_save( slosm_mb_fields( array( 'lat' => '', 'lng' => '' ) ) );

				assert_same( 1, slosm_mb_lookups() );
				assert_same( 52.2297, slosm_mb_stored()['lat'] );
			}
		);

		it(
			'leaves a locked location alone, and the same save unlocked does not',
			function () {
				slosm_mb_stage( slosm_mb_fields( array( 'lat_locked' => '1' ) ) );

				// The address changed, which is the one thing that otherwise
				// forces a lookup.
				slosm_mb_save( slosm_mb_fields( array( 'city' => 'Kraków', 'lat' => '52.2297', 'lng' => '21.0122' ) ) );

				assert_same( 0, slosm_mb_lookups() );
				assert_same( 52.2297, slosm_mb_stored()['lat'], 'a locked pin was moved' );

				// The control. Identical fixture, lock off, and now it asks —
				// so the zero above is the lock and not the wiring.
				slosm_stub_reset();

				slosm_mb_stage( slosm_mb_fields( array( 'lat_locked' => '' ) ) );
				slosm_mb_queue_point( '50.0614', '19.9366' );

				slosm_mb_save( slosm_mb_fields( array( 'city' => 'Kraków', 'lat' => '52.2297', 'lng' => '21.0122' ) ) );

				assert_same( 1, slosm_mb_lookups() );
			}
		);

		it(
			'does not look up an address the editor placed a pin on in the same save',
			function () {
				slosm_mb_stage( slosm_mb_fields() );

				// Both changed at once: a new address and a pin typed by hand.
				// The person wins, and the lock they just earned is what says so
				// on every save after this one.
				slosm_mb_save( slosm_mb_fields( array( 'city' => 'Kraków', 'lat' => '50.0614', 'lng' => '19.9366' ) ) );

				assert_same( 0, slosm_mb_lookups() );
				assert_same( 50.0614, slosm_mb_stored()['lat'] );
				assert_true( slosm_mb_stored()['lat_locked'] );
			}
		);

		it(
			'says why a lookup failed and leaves the coordinates where they were',
			function () {
				slosm_mb_stage( slosm_mb_fields() );

				$GLOBALS['slosm_stub']['http_queue'][] = array(
					'response' => array( 'code' => 200 ),
					'body'     => '[]',
				);

				slosm_mb_save( slosm_mb_fields( array( 'city' => 'Atlantyda' ) ) );

				assert_same( 1, slosm_mb_lookups() );
				assert_same( 52.2297, slosm_mb_stored()['lat'] );
				assert_true(
					slosm_mb_said( 'No place matched that address.' ),
					'the editor was told nothing about a lookup that failed'
				);
			}
		);

		it(
			'asks nothing for a location with no address yet',
			function () {
				// Add New Location, a name, Publish. There is nothing to look up,
				// and asking anyway spends a request and answers with an error
				// about a field the editor has not reached.
				slosm_mb_save(
					slosm_mb_fields(
						array(
							'address'  => '',
							'address2' => '',
							'city'     => '',
							'state'    => '',
							'zip'      => '',
							'country'  => '',
							'lat'      => '',
							'lng'      => '',
						)
					)
				);

				assert_same( 0, slosm_mb_lookups() );
				assert_same( array(), slosm_mb_messages() );
			}
		);

		it(
			'writes what it found where the cache flush can still see it',
			function () {
				// Task 8 flushes the map payload on save_post_slosm_store at
				// priority 10. A geocoder that wrote coordinates after that —
				// at priority 10 registered later, or on a hook of its own — would
				// leave every visitor a payload built from the coordinates the
				// save replaced, for up to a day, with nothing to invalidate it.
				//
				// So the probe below is registered at 10 and asserts the write has
				// already happened when it runs. It fails if the handler moves to
				// 10 or later, which is the whole claim.
				slosm_mb_stage( slosm_mb_fields() );
				slosm_mb_queue_point( '50.0614', '19.9366' );

				$admin = slosm_mb_admin();

				add_action( 'save_post_slosm_store', array( $admin, 'save' ), Admin::SAVE_PRIORITY, 2 );

				$seen = null;

				add_action(
					'save_post_slosm_store',
					static function () use ( &$seen ) {
						$seen = get_post_meta( SLOSM_MB_POST_ID, '_slosm_lat', true );
					},
					10,
					2
				);

				$GLOBALS['slosm_stub']['current_user_id']          = 1;
				$GLOBALS['slosm_stub']['capabilities']['edit_post'] = true;

				$_POST = array( 'slosm_location_nonce' => 'nonce:slosm_save_location_7' );

				foreach ( slosm_mb_fields( array( 'city' => 'Kraków' ) ) as $field => $value ) {
					$_POST[ 'slosm_' . $field ] = wp_slash( $value );
				}

				try {
					do_action( 'save_post_slosm_store', SLOSM_MB_POST_ID, slosm_mb_post(), true );
				} finally {
					$_POST = array();
				}

				assert_same( '50.0614', $seen, 'the lookup had not been written when the cache was flushed' );
			}
		);

		it(
			'asks once about an address the service cannot resolve, not once per save',
			function () {
				slosm_mb_stage( slosm_mb_fields( array( 'lat' => '', 'lng' => '' ) ) );

				// Geocoder::geocode() caches successes for thirty days and never
				// caches a failure, and should_geocode() says yes whenever there
				// are no coordinates. Together that is one blocking request per
				// save of any field, for ever, on the one location whose saves
				// are already the slowest. Four saves used to be four requests.
				$GLOBALS['slosm_stub']['http_queue'][] = array(
					'response' => array( 'code' => 200 ),
					'body'     => '[]',
				);

				for ( $save = 0; $save < 4; $save++ ) {
					slosm_mb_save( slosm_mb_fields( array( 'lat' => '', 'lng' => '', 'phone' => '+48 22 000 00 0' . $save ) ) );
				}

				assert_same( 1, slosm_mb_lookups(), 'an unresolvable address was looked up more than once' );

				// And the editor is told every time, because the location is
				// still unplaced every time. A cache that bought its saving with
				// silence would be buying it with the one thing this box is for.
				assert_true(
					slosm_mb_said( 'No place matched that address.' ),
					'the save that skipped the lookup said nothing about it'
				);

				// No sleeps either: the courtesy delay is per request, and three
				// of the four requests are the ones that no longer happen.
				assert_same( array(), $GLOBALS['slosm_stub']['sleeps'] );
			}
		);

		it(
			'asks again as soon as the address changes',
			function () {
				slosm_mb_stage( slosm_mb_fields( array( 'lat' => '', 'lng' => '' ) ) );

				$GLOBALS['slosm_stub']['http_queue'][] = array(
					'response' => array( 'code' => 200 ),
					'body'     => '[]',
				);

				slosm_mb_save( slosm_mb_fields( array( 'lat' => '', 'lng' => '' ) ) );

				assert_same( 1, slosm_mb_lookups() );

				// The remembered failure is keyed on the address, not on the
				// location, so correcting a misspelt street is asked about at
				// once rather than in an hour. Without that key this case reads
				// one request and the editor has to wait out the cache.
				slosm_mb_queue_point( '50.0614', '19.9366' );

				slosm_mb_save( slosm_mb_fields( array( 'lat' => '', 'lng' => '', 'city' => 'Kraków' ) ) );

				assert_same( 2, slosm_mb_lookups() );
				assert_same( 50.0614, slosm_mb_stored()['lat'] );
			}
		);

		it(
			'does not remember a lookup that worked as a failure',
			function () {
				slosm_mb_stage( slosm_mb_fields( array( 'lat' => '', 'lng' => '' ) ) );
				slosm_mb_queue_point();

				slosm_mb_save( slosm_mb_fields( array( 'lat' => '', 'lng' => '' ) ) );

				assert_false(
					false !== get_transient( 'slosm_geocode_failed_' . md5( Admin::address_query( slosm_mb_fields() ) ) ),
					'a successful lookup was written down as a failure'
				);
			}
		);
	}
);

describe(
	"the metabox in the plugin's hook list",
	function () {

		before_each(
			function () {
				$_POST = array();
			}
		);

		it(
			'is wired from boot(), and saves before the flush',
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

				$saves   = $GLOBALS['slosm_stub']['actions']['save_post_slosm_store'] ?? array();
				$flushes = array();
				$handler = array();

				foreach ( $saves as $registered ) {
					if ( is_array( $registered['callback'] ) && $registered['callback'][0] instanceof Admin ) {
						$handler[] = $registered;
					} else {
						$flushes[] = $registered;
					}
				}

				assert_same( 1, count( $handler ), 'boot() did not hook the metabox save exactly once' );
				assert_same( 1, count( $flushes ), 'boot() did not hook the cache flush exactly once' );
				assert_same( 'save', $handler[0]['callback'][1] );
				assert_same( 2, $handler[0]['accepted_args'] );

				assert_true(
					$handler[0]['priority'] < $flushes[0]['priority'],
					'the metabox save runs at or after the cache flush, so the flush invalidates a payload that is about to go stale again'
				);

				// And the box itself, on the type-specific hook so that no other
				// post type on the site grows a Location metabox.
				$boxes = $GLOBALS['slosm_stub']['actions']['add_meta_boxes_slosm_store'] ?? array();

				assert_same( 1, count( $boxes ) );
				assert_same( 'add_meta_box', $boxes[0]['callback'][1] );
			}
		);
	}
);

describe(
	"storing a location's fields",
	function () {

		it(
			'writes each field to the meta key the mapping names, and nothing else',
			function () {
				( new Store_Repository() )->save_fields(
					SLOSM_MB_POST_ID,
					array(
						'city'    => 'Warszawa',
						'unknown' => 'nonsense',
					)
				);

				assert_same( 'Warszawa', get_post_meta( SLOSM_MB_POST_ID, '_slosm_city', true ) );

				// A field the mapping does not know is dropped rather than
				// invented as a meta key. Store::from_array() would ignore it on
				// the way back anyway, so the row would be write-only.
				assert_same( array(), $GLOBALS['slosm_stub']['post_meta'][ SLOSM_MB_POST_ID ]['_slosm_unknown'] ?? array() );
				assert_same( array( '_slosm_city' ), array_keys( $GLOBALS['slosm_stub']['post_meta'][ SLOSM_MB_POST_ID ] ) );
			}
		);

		it(
			'slashes on the way to update_post_meta, which unslashes again',
			function () {
				// update_post_meta() unslashes what it is handed — core's
				// update_metadata() at wp-includes/meta.php line 222 — so a
				// caller passing clean text loses every backslash in it. The
				// repository owns the storage call, so it owns that convention.
				( new Store_Repository() )->save_fields( SLOSM_MB_POST_ID, array( 'address' => "O'Brien's \\ Deli" ) );

				assert_same( "O'Brien's \\ Deli", get_post_meta( SLOSM_MB_POST_ID, '_slosm_address', true ) );
			}
		);
	}
);
