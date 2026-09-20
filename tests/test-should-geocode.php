<?php
/**
 * Proves the one rule that decides whether a save costs a Nominatim request.
 *
 * The rule is small and every way of getting it wrong is expensive in a
 * different direction:
 *
 * - Geocoding on every save spends the public service's one-request-per-second
 *   courtesy limit on saves that changed a phone number, and overwrites the pin
 *   an editor dragged into the right doorway with whatever the street address
 *   resolves to.
 * - Never geocoding leaves a location with no coordinates off the map with
 *   nothing to say so. It is not an error state anywhere: the payload simply
 *   skips it.
 * - Geocoding a locked location is the worst of the three, because it is the
 *   only one that destroys work. The coordinates an editor placed by hand are
 *   gone, there is no error, and nothing at all announces it.
 *
 * So the function is pure, it is separate from the save handler, and it is
 * tested before either exists. The save handler's own cases then count http
 * requests, because a pure function agreeing with a table proves only that the
 * table and the function agree.
 *
 * @package Store_Locator_For_OpenStreetMap
 */

require_once dirname( __DIR__ ) . '/includes/class-store.php';
require_once dirname( __DIR__ ) . '/includes/class-store-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-geo.php';
require_once dirname( __DIR__ ) . '/includes/class-geocoder.php';
require_once dirname( __DIR__ ) . '/includes/class-post-type.php';
require_once dirname( __DIR__ ) . '/admin/class-admin.php';

use Asymetria\StoreLocator\Admin\Admin;

if ( ! function_exists( 'slosm_geo_address' ) ) {
	/**
	 * One complete address, with every component filled in.
	 *
	 * Every component is non-empty on purpose: a case that changes one of them
	 * is then changing a value rather than filling in a blank, and the "nothing
	 * to look up" rule cannot be what makes it pass.
	 *
	 * Touches no stub state, so it is safe to call from anywhere.
	 *
	 * @param array $overrides Components to replace.
	 * @return array
	 */
	function slosm_geo_address( array $overrides = array() ): array {
		return array_merge(
			array(
				'address'  => 'Nowy Świat 1',
				'address2' => 'lokal 3',
				'city'     => 'Warszawa',
				'state'    => 'mazowieckie',
				'zip'      => '00-001',
				'country'  => 'Polska',
			),
			$overrides
		);
	}
}

describe(
	'should_geocode',
	function () {

		it(
			'does not re-geocode when the address is unchanged',
			function () {
				// The ordinary save: somebody fixed a typo in the opening hours.
				assert_false(
					Admin::should_geocode(
						slosm_geo_address(),
						slosm_geo_address(),
						52.2297,
						21.0122,
						false
					)
				);
			}
		);

		it(
			'geocodes when any address component changed',
			function () {
				// Every component, one at a time, so that dropping one from the
				// comparison fails here rather than costing a site one address
				// that silently never moves its pin again.
				$changes = array(
					'address'  => 'Marszałkowska 2',
					'address2' => 'lokal 4',
					'city'     => 'Kraków',
					'state'    => 'małopolskie',
					'zip'      => '31-001',
					'country'  => 'Poland',
				);

				foreach ( $changes as $field => $value ) {
					assert_true(
						Admin::should_geocode(
							slosm_geo_address(),
							slosm_geo_address( array( $field => $value ) ),
							52.2297,
							21.0122,
							false
						),
						'a changed ' . $field . ' did not reach the geocoder'
					);
				}
			}
		);

		it(
			'geocodes when coordinates are missing even if the address is unchanged',
			function () {
				// All three shapes of missing, because a guard written as
				// ! $lat is a guard that also refuses the equator.
				assert_true( Admin::should_geocode( slosm_geo_address(), slosm_geo_address(), null, null, false ) );
				assert_true( Admin::should_geocode( slosm_geo_address(), slosm_geo_address(), 52.2297, null, false ) );
				assert_true( Admin::should_geocode( slosm_geo_address(), slosm_geo_address(), null, 21.0122, false ) );
			}
		);

		it(
			'geocodes a location sitting on the pair a failed lookup leaves behind',
			function () {
				// 0,0 is not a location this plugin serves; Store::has_coordinates()
				// says so, and this asks that same question rather than asking a
				// second one that could drift from it. A location really at 0,21
				// or 52,0 is placed, and must not be re-looked-up on every save.
				assert_true( Admin::should_geocode( slosm_geo_address(), slosm_geo_address(), 0.0, 0.0, false ) );
				assert_false( Admin::should_geocode( slosm_geo_address(), slosm_geo_address(), 0.0, 21.0122, false ) );
				assert_false( Admin::should_geocode( slosm_geo_address(), slosm_geo_address(), 52.2297, 0.0, false ) );
			}
		);

		it(
			'never geocodes a location whose coordinates are locked',
			function () {
				// The strongest form: everything else in this call says yes.
				// The address changed, there are no coordinates at all, and the
				// lock still wins — because the only thing that sets the lock is
				// a person placing the pin, and a person beats an address.
				assert_false(
					Admin::should_geocode(
						slosm_geo_address(),
						slosm_geo_address( array( 'city' => 'Kraków' ) ),
						null,
						null,
						true
					)
				);

				// The control: the same call with the lock off. Without it the
				// case above would pass just as well for a function that always
				// says no.
				assert_true(
					Admin::should_geocode(
						slosm_geo_address(),
						slosm_geo_address( array( 'city' => 'Kraków' ) ),
						null,
						null,
						false
					)
				);
			}
		);

		it(
			'does not look up an address that is not there',
			function () {
				$empty = slosm_geo_address(
					array(
						'address'  => '',
						'address2' => '',
						'city'     => '',
						'state'    => '',
						'zip'      => '',
						'country'  => '',
					)
				);

				// Opening Add New Location and pressing Publish. There is no
				// address, so there is nothing a lookup could answer; asking
				// anyway would spend a request and hand the editor an error
				// about a field they have not filled in yet.
				assert_false( Admin::should_geocode( $empty, $empty, null, null, false ) );

				// One component is enough to be worth asking about. A city on
				// its own is a real, if vague, geocoding query.
				assert_true( Admin::should_geocode( $empty, array_merge( $empty, array( 'city' => 'Kraków' ) ), null, null, false ) );
			}
		);

		it(
			'reads the address this save leaves, not the one it found',
			function () {
				$empty = slosm_geo_address(
					array(
						'address'  => '',
						'address2' => '',
						'city'     => '',
						'state'    => '',
						'zip'      => '',
						'country'  => '',
					)
				);

				// An editor emptying the address fields has changed something,
				// and there is still nothing to look up. Reading $before for the
				// emptiness check would send the old address to Nominatim and
				// write its answer over a location whose address was just
				// deleted.
				assert_false( Admin::should_geocode( slosm_geo_address(), $empty, 52.2297, 21.0122, false ) );

				// And the other way round, which is the control: filling the
				// address in for the first time is exactly when a lookup is
				// wanted.
				assert_true( Admin::should_geocode( $empty, slosm_geo_address(), 52.2297, 21.0122, false ) );
			}
		);

		it(
			'treats a missing component as an empty one rather than as a change',
			function () {
				// Task 18 and Task 20 will both call this with arrays they built
				// themselves. A key one of them forgets must not read as "this
				// component changed", or every save from that path re-geocodes.
				$without_state = slosm_geo_address();
				unset( $without_state['state'] );

				assert_true(
					Admin::should_geocode( slosm_geo_address(), $without_state, 52.2297, 21.0122, false ),
					'a component that really did go from a value to nothing is a change'
				);

				$neither = $without_state;
				$neither['state'] = '';

				assert_false(
					Admin::should_geocode( $without_state, $neither, 52.2297, 21.0122, false ),
					'absent and empty are the same component, not two different ones'
				);
			}
		);
	}
);

describe(
	'the query an address becomes',
	function () {

		it(
			'joins the components it has, in address order, and skips the ones it has not',
			function () {
				assert_same(
					'Nowy Świat 1, lokal 3, Warszawa, mazowieckie, 00-001, Polska',
					Admin::address_query( slosm_geo_address() )
				);

				assert_same(
					'Warszawa, Polska',
					Admin::address_query(
						slosm_geo_address(
							array(
								'address'  => '',
								'address2' => '',
								'state'    => '',
								'zip'      => '',
							)
						)
					)
				);
			}
		);

		it(
			'is empty when every component is, and whitespace is not a component',
			function () {
				assert_same( '', Admin::address_query( array() ) );

				// A field holding a space is a field an editor cleared, not an
				// address consisting of a space. Without the trim the query is
				// ", , , , , " and should_geocode() calls it an address.
				assert_same(
					'',
					Admin::address_query(
						array(
							'address' => '   ',
							'city'    => "\t",
						)
					)
				);
			}
		);

		it(
			'ignores anything that is not an address component',
			function () {
				// The save handler hands this the whole submitted row on the way
				// through, and a phone number in the geocoding query is a query
				// Nominatim cannot answer.
				assert_same(
					'Warszawa',
					Admin::address_query(
						array(
							'city'  => 'Warszawa',
							'phone' => '+48 22 000 00 00',
							'email' => 'kontakt@example.com',
							'hours' => "pn-pt 9-17\nsb 10-14",
						)
					)
				);
			}
		);
	}
);
