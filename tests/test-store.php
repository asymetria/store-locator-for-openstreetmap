<?php
/**
 * Pins the contract of the Store value object.
 *
 * Every case here is about what a caller may rely on: the types that come out
 * of from_array(), what counts as a mappable location, and which keys the two
 * array shapes carry. Nothing here touches stub state, so no before_each() is
 * needed.
 *
 * The coordinate cases carry the most weight. A store locator's one
 * unrecoverable failure is a marker in the wrong place, and a wrong marker is
 * always born the same way: something that was not a number was quietly turned
 * into one.
 *
 * @package Store_Locator_For_OpenStreetMap
 */

require_once dirname( __DIR__ ) . '/includes/class-store.php';

use Asymetria\StoreLocator\Store;

describe(
	'store',
	function () {

		it(
			'builds from raw meta and casts coordinates',
			function () {
				$store = Store::from_array(
					array(
						'id'   => '12',
						'name' => 'Kawiarnia',
						'lat'  => '52.2297',
						'lng'  => '21.0122',
						'city' => 'Warszawa',
					)
				);

				assert_same( 12, $store->id );
				assert_same( 52.2297, $store->lat );
				assert_same( 21.0122, $store->lng );
				assert_same( 'Kawiarnia', $store->name );
				assert_same( 'Warszawa', $store->city );
			}
		);

		it(
			'reports whether it can appear on a map',
			function () {
				$located = Store::from_array(
					array(
						'id'  => 1,
						'lat' => '52.0',
						'lng' => '21.0',
					)
				);
				$missing = Store::from_array(
					array(
						'id'  => 2,
						'lat' => '',
						'lng' => '',
					)
				);

				assert_true( $located->has_coordinates() );
				assert_false( $missing->has_coordinates() );
			}
		);

		it(
			'treats 0,0 as missing coordinates',
			function () {
				$store = Store::from_array(
					array(
						'id'  => 3,
						'lat' => '0',
						'lng' => '0',
					)
				);

				// The Gulf of Guinea. A geocode that failed and wrote zeros must
				// not put a marker there, and the admin warning that tells an
				// editor a location is unplaced reads exactly this method.
				assert_false( $store->has_coordinates() );
			}
		);

		it(
			'still places a location that is genuinely on a meridian',
			function () {
				// Only the exact pair 0,0 is refused, not either coordinate
				// being zero: Accra sits on the prime meridian, Quito on the
				// equator, and both are real places on a real map.
				$greenwich = Store::from_array(
					array(
						'id'  => 4,
						'lat' => '5.6037',
						'lng' => '0',
					)
				);
				$equator   = Store::from_array(
					array(
						'id'  => 5,
						'lat' => '0',
						'lng' => '-78.4678',
					)
				);

				assert_true( $greenwich->has_coordinates() );
				assert_true( $equator->has_coordinates() );
			}
		);

		it(
			'refuses a coordinate it cannot read as a number',
			function () {
				// Null rather than 0.0 for every one of these. 0.0 would be a
				// lie with a location attached; null is the truth, and
				// has_coordinates() can then say so.
				foreach ( array( 'abc', '', null, array(), '52,2297', 'NaN' ) as $value ) {
					$store = Store::from_array(
						array(
							'id'  => 6,
							'lat' => $value,
							'lng' => '21.0122',
						)
					);

					assert_same( null, $store->lat, 'expected null for ' . var_export( $value, true ) );
					assert_false( $store->has_coordinates(), 'expected unmappable for ' . var_export( $value, true ) );
				}
			}
		);

		it(
			'will not map a half-geocoded location',
			function () {
				$store = Store::from_array(
					array(
						'id'  => 7,
						'lat' => '52.2297',
					)
				);

				assert_same( 52.2297, $store->lat );
				assert_same( null, $store->lng );
				assert_false( $store->has_coordinates() );
			}
		);

		it(
			'gives every absent field an empty of its own type',
			function () {
				$store = Store::from_array( array() );

				assert_same( 0, $store->id );
				assert_same( '', $store->name );
				assert_same( '', $store->hours );
				assert_same( null, $store->lat );
				assert_same( null, $store->lng );
				assert_false( $store->lat_locked );
				assert_same( array(), $store->categories );
			}
		);

		it(
			'always hands back an array of categories',
			function () {
				$none   = Store::from_array( array( 'id' => 8 ) );
				$some   = Store::from_array(
					array(
						'id'         => 9,
						'categories' => array( 'showroom', 'warszawa' ),
					)
				);
				$scalar = Store::from_array(
					array(
						'id'         => 9,
						'categories' => 'showroom',
					)
				);

				// A caller writing foreach ( $store->categories ... ) must never
				// have to check first. A bare string is not a list and is not
				// guessed at either.
				assert_same( array(), $none->categories );
				assert_same( array( 'showroom', 'warszawa' ), $some->categories );
				assert_same( array(), $scalar->categories );
			}
		);

		it(
			'drops a category it cannot read instead of blanking it',
			function () {
				// A term object stands in for WP_Term, which has no __toString():
				// casting one yields '', and a blank with the right count is
				// worse than a short list, because it survives ! empty(), it
				// survives a foreach, and it renders as an empty filter chip. The
				// repository has to pluck the names out of what get_the_terms()
				// returns; when it does not, this is what arrives.
				$term  = (object) array( 'name' => 'kawiarnia' );
				$store = Store::from_array(
					array(
						'id'         => 10,
						'categories' => array( $term, 'showroom', array( 'nested' ), null, '', '0' ),
					)
				);

				// '0' stays: an unlikely slug, but a real one.
				assert_same( array( 'showroom', '0' ), $store->categories );
			}
		);

		it(
			'reads lat_locked as a boolean whatever post meta stored',
			function () {
				// Post meta round-trips booleans as '1' and ''.
				$locked   = Store::from_array(
					array(
						'id'         => 10,
						'lat_locked' => '1',
					)
				);
				$unlocked = Store::from_array(
					array(
						'id'         => 11,
						'lat_locked' => '',
					)
				);
				$zero     = Store::from_array(
					array(
						'id'         => 12,
						'lat_locked' => '0',
					)
				);

				assert_true( $locked->lat_locked );
				assert_false( $unlocked->lat_locked );
				assert_false( $zero->lat_locked, "'0' out of post meta is not locked" );
			}
		);

		it(
			'takes its input as already clean and changes no text',
			function () {
				// The documented decision: from_array() casts, it does not
				// sanitise. Sanitising happens once on write and escaping
				// happens at output; doing it again here would mangle honest
				// content and still not make anything safe to print.
				$store = Store::from_array(
					array(
						'id'    => 13,
						'name'  => 'Bar <Pub',
						'hours' => "Mon 9-17\nTue 9-17",
					)
				);

				assert_same( 'Bar <Pub', $store->name );
				assert_same( "Mon 9-17\nTue 9-17", $store->hours );
			}
		);

		it(
			'produces a lean array with only what the map needs',
			function () {
				$store = Store::from_array(
					array(
						'id'    => 1,
						'name'  => 'A',
						'lat'   => '52.0',
						'lng'   => '21.0',
						'city'  => 'Warszawa',
						'phone' => '123',
						'hours' => 'Mon 9-17',
					)
				);

				$lean = $store->to_lean_array();

				assert_true( isset( $lean['lat'] ) );
				assert_false( isset( $lean['hours'] ), 'hours belong in the full record, not the map payload' );
				assert_false( isset( $lean['phone'] ), 'a phone number is popup content, not marker content' );
			}
		);

		it(
			'ships exactly seven keys to the browser',
			function () {
				// The lean payload is sent for every location at once, so a key
				// added here costs bytes on every page load. Sorted rather than
				// compared in order: the set is the contract, the order is not.
				$store = Store::from_array( array( 'id' => 1 ) );
				$keys  = array_keys( $store->to_lean_array() );
				sort( $keys );

				assert_same(
					array( 'address', 'categories', 'city', 'id', 'lat', 'lng', 'name' ),
					$keys
				);
			}
		);

		it(
			'carries every field in the full record',
			function () {
				// The key set, pinned against a literal rather than against the
				// object, because this array is the lossless record shape: it is
				// what find_by_id() hands back and what the bulk geocoder reads
				// per location, so a key that quietly stops being written is
				// data loss every time a location is read whole. (The map cache
				// holds the lean shape, not this one.) Sorted — the set is the
				// contract, the order is not.
				$keys = array_keys( Store::from_array( array( 'id' => 1 ) )->to_full_array() );
				sort( $keys );

				assert_same(
					array(
						'address',
						'address2',
						'categories',
						'city',
						'country',
						'description',
						'email',
						'hours',
						'id',
						'lat',
						'lat_locked',
						'lng',
						'name',
						'phone',
						'state',
						'url',
						'zip',
					),
					$keys
				);
			}
		);

		it(
			'round-trips the full record without losing a field',
			function () {
				// Compared against the input array, not against a second call to
				// to_full_array(). Comparing the method with itself is symmetric
				// under any change to it: drop a key and it disappears from both
				// sides at once, and the test stays green while the cache quietly
				// stops carrying a field.
				$record = array(
					'id'          => 14,
					'name'        => 'Kawiarnia',
					'description' => 'Palarnia i kawiarnia.',
					'address'     => 'Nowy Świat 1',
					'address2'    => 'lokal 3',
					'city'        => 'Warszawa',
					'state'       => 'mazowieckie',
					'zip'         => '00-001',
					'country'     => 'Polska',
					'lat'         => 52.2297,
					'lng'         => 21.0122,
					'lat_locked'  => true,
					'phone'       => '+48 22 000 00 00',
					'email'       => 'kawa@example.test',
					'url'         => 'https://example.test/kawiarnia',
					'hours'       => "Mon 9-17\nTue 9-17",
					'categories'  => array( 'kawiarnia' ),
				);

				$full = Store::from_array( $record )->to_full_array();

				ksort( $record );
				ksort( $full );

				assert_same( $record, $full );
			}
		);

		it(
			'states its field list once and keeps the three copies agreeing',
			function () {
				// The same seventeen names are written out in three places in the
				// class — the constructor signature, FIELDS, and to_full_array().
				// from_array() ignores a key it does not recognise, so a name that
				// disagrees is a permanently blank field on every location with
				// nothing to announce it. This is the check that makes FIELDS
				// worth having, and the one a future mapping should be tested
				// against too.
				$parameters = array_map(
					static function ( ReflectionParameter $parameter ): string {
						return $parameter->getName();
					},
					( new ReflectionClass( Store::class ) )->getConstructor()->getParameters()
				);

				// distance is excluded by name rather than by loosening the
				// comparison to "FIELDS is a subset of the parameters", because
				// the loose version would accept any future parameter and this
				// check exists to make an eighteenth field impossible to add by
				// accident. distance is not a field: it is what a proximity
				// search attaches to its own results with with_distance(), it is
				// never stored, and it is in neither array shape — least of all
				// the lean one, which is cached and shared between every visitor.
				// A new name appearing here fails this case, which is the
				// intended way to have that conversation.
				$parameters = array_values(
					array_filter(
						$parameters,
						static function ( string $name ): bool {
							return 'distance' !== $name;
						}
					)
				);

				$fields = Store::FIELDS;
				$keys   = array_keys( Store::from_array( array() )->to_full_array() );

				sort( $parameters );
				sort( $fields );
				sort( $keys );

				assert_same( $fields, $parameters, 'the constructor and FIELDS disagree' );
				assert_same( $fields, $keys, 'to_full_array() and FIELDS disagree' );
			}
		);

		it(
			'casts the whole record on the way in',
			function () {
				// The same record as post meta actually hands it over: everything
				// a string, booleans as '1'.
				$full = Store::from_array(
					array(
						'id'          => '14',
						'description' => 'Palarnia i kawiarnia.',
						'lat'         => '52.2297',
						'lat_locked'  => '1',
						'hours'       => "Mon 9-17\nTue 9-17",
					)
				)->to_full_array();

				assert_same( 14, $full['id'] );
				assert_same( 52.2297, $full['lat'] );
				assert_true( $full['lat_locked'] );
				assert_same( 'Palarnia i kawiarnia.', $full['description'] );
				assert_same( "Mon 9-17\nTue 9-17", $full['hours'] );
			}
		);
	}
);
