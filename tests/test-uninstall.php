<?php
/**
 * Proves what deleting this plugin deletes, and — harder — what it does not.
 *
 * WHY THIS FILE IS SHAPED THE WAY IT IS
 * ====================================
 * Every other file in this suite tests something that, when wrong, shows a
 * visitor the wrong map. This one tests the only code in the plugin that can
 * destroy a client's work, and it runs at the one moment nobody is watching:
 * after the admin has already pressed Delete, with no undo behind it and no
 * screen left to report to.
 *
 * So the cases come in pairs. For every "this is gone" there is a "this is
 * still here", and the second half is the half that matters. A sweep that
 * deletes too much passes every case that only counts what it removed.
 *
 * THE LOCATIONS GO ONLY WHEN THE SITE SAYS SO
 * ===========================================
 * A location is a published post with an address on it, typed by whoever runs
 * the site. Deleting the plugin is a decision about a plugin, and on a real
 * site it is routinely a reversible one — try it, remove it, put it back next
 * week. So the default is to keep them, and one checkbox — `remove_data` in
 * Settings::defaults(), off unless somebody ticked it — is what changes that.
 *
 * The flag lives inside `slosm_settings`, which the uninstaller deletes, and
 * the order decides whether the feature works at all: read it after the delete
 * and get_option() answers the default, so the flag is false on every site and
 * the checkbox quietly stops meaning anything. Both orders leave identical
 * wreckage — the option is gone either way — so no case about either branch
 * can see the difference. That is why the ordering has cases of its own, and
 * why tests/bootstrap.php's get_option() records its reads.
 *
 * WHAT A TRANSIENT IS, FOR THE PURPOSES OF DELETING ONE
 * =====================================================
 * Two different things, depending on the site, and the uninstaller has to be
 * right on both — which is why the stub keeps two stores rather than one:
 *
 * - On a site with no persistent object cache, a transient is two rows in
 *   `wp_options`: `_transient_<key>` and `_transient_timeout_<key>`
 *   (wp-includes/option.php lines 1544-1545 of WordPress 6.9.1). Those rows
 *   are `db_options` here, and a `DELETE … LIKE` can reach them.
 * - On a site with Redis or Memcached in front of it, the same transient is
 *   not in the database at all and no statement can touch it. That is the
 *   warning Store_Repository::CACHE_PREFIX and Geocoder::CACHE_PREFIX both
 *   carry. What reaches those entries is deleting the two generation options,
 *   after which every key the plugin would compute on reinstall is a key
 *   nothing was ever written under.
 *
 * Both halves have a case. Neither is sufficient alone.
 *
 * THE KEYS ARE THE PLUGIN'S OWN, NOT THIS FILE'S
 * ==============================================
 * Every row name below is built by calling the method that builds it in
 * production — Store_Repository::cache_key(), Geocoder::cache_key(),
 * Admin::failure_key() — and prefixing core's documented `_transient_`. A case
 * that spelled the key itself would agree with any mistake the uninstaller and
 * the key builder made together, which is the failure mode this project has
 * already paid for once.
 *
 * @package Store_Locator_For_OpenStreetMap
 */

require_once dirname( __DIR__ ) . '/includes/class-store.php';
require_once dirname( __DIR__ ) . '/includes/class-geo.php';
require_once dirname( __DIR__ ) . '/includes/class-post-type.php';
require_once dirname( __DIR__ ) . '/includes/class-store-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-geocoder.php';
require_once dirname( __DIR__ ) . '/includes/class-settings.php';
require_once dirname( __DIR__ ) . '/admin/class-admin.php';
require_once dirname( __DIR__ ) . '/admin/class-bulk-geocode.php';

use Asymetria\StoreLocator\Admin\Admin;
use Asymetria\StoreLocator\Admin\Bulk_Geocode;
use Asymetria\StoreLocator\Geocoder;
use Asymetria\StoreLocator\Post_Type;
use Asymetria\StoreLocator\Settings;
use Asymetria\StoreLocator\Store_Repository;

/**
 * Runs uninstall.php the way WordPress runs it.
 *
 * `include` rather than `include_once`, which is what core uses at
 * wp-admin/includes/plugin.php line 1327. Core only ever does it once per
 * request, so the difference costs nothing there; here it is what lets a case
 * run the uninstaller twice, and uninstall.php is written to survive that.
 *
 * Function scope is not an accident either: core's include sits inside
 * uninstall_plugin(), so anything in that file reaching for a global without
 * saying `global` is broken on a real site and has to be broken here too.
 *
 * @return void
 */
function slosm_uninstall_run(): void {
	if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
		define( 'WP_UNINSTALL_PLUGIN', 'store-locator-for-openstreetmap/store-locator-for-openstreetmap.php' );
	}

	include dirname( __DIR__ ) . '/uninstall.php';
}

/**
 * The raw `wp_options` rows a busy site would have, keyed by option name.
 *
 * Six of this plugin's transients, each under the key its own builder
 * produces, each with the timeout row core writes beside it — and four rows
 * that belong to somebody else, three of which are near misses on purpose.
 *
 * @return array<string, string>
 */
function slosm_uninstall_rows(): array {
	$repository = new Store_Repository(
		static function (): array {
			return array();
		}
	);

	$geocoder = new Geocoder(
		static function (): float {
			return slosm_stub_time();
		},
		'slosm_stub_sleep'
	);

	$keys = array(
		$repository->cache_key(),
		$geocoder->cache_key( Geocoder::SERVICE_NOMINATIM, 'Rynek Główny 1, Kraków', 'pl' ),
		$geocoder->cache_key( Geocoder::SERVICE_PHOTON, 'Krak', '' ),
		Admin::failure_key( 'Nowhere At All', 'pl' ),
		Admin::NOTICE_PREFIX . '412_3',
		Bulk_Geocode::REPORT_PREFIX . '3',
	);

	$rows = array();

	foreach ( $keys as $key ) {
		$rows[ '_transient_' . $key ]         = 'a:0:{}';
		$rows[ '_transient_timeout_' . $key ] = '1790000000';
	}

	// Somebody else's rows. The last two are near misses that a sweep which
	// forgot to escape its own underscores would take with it, because an
	// unescaped `_` in a LIKE pattern is any single character.
	$rows['_transient_wc_products_onsale']         = 'a:0:{}';
	$rows['_transient_timeout_wc_products_onsale'] = '1790000000';
	$rows['Xtransient_slosm_geo_v1_g0_abc']        = 'not ours';
	$rows['_transientXslosm_geo_v1_g0_abc']        = 'not ours either';

	return $rows;
}

/**
 * The row names of slosm_uninstall_rows() that this plugin owns.
 *
 * @return string[]
 */
function slosm_uninstall_own_rows(): array {
	return array_values(
		array_filter(
			array_keys( slosm_uninstall_rows() ),
			static function ( string $name ): bool {
				return str_starts_with( $name, '_transient_slosm' )
					|| str_starts_with( $name, '_transient_timeout_slosm' );
			}
		)
	);
}

/**
 * Puts a site in front of the uninstaller: options, transients, raw rows, content.
 *
 * @return void
 */
function slosm_uninstall_site_fixture(): void {
	$stub = &$GLOBALS['slosm_stub'];

	$stub['options'] = array(
		Settings::OPTION                     => array( 'units' => 'km' ),
		Store_Repository::GENERATION_OPTION  => 4,
		Geocoder::GENERATION_OPTION          => 7,
		'slosm_geocode_last_request_nominatim' => 1767225600,
		'slosm_geocode_last_request_photon'    => 1767225601,

		// Not this plugin's, and one of them only looks like it.
		'woocommerce_currency'               => 'PLN',
		'slosmish_other_plugin'              => 'keep me',
	);

	$stub['db_options'] = slosm_uninstall_rows();

	// The content. A location is a post with meta and a term; none of it is
	// this file's to delete.
	$stub['posts'] = array(
		array(
			'ID'         => 412,
			'post_title' => 'Kraków — Rynek',
			'post_type'  => 'slosm_store',
		),
	);

	$stub['post_meta'] = array(
		412 => array(
			'slosm_lat'     => '50.0616',
			'slosm_lng'     => '19.9373',
			'slosm_address' => 'Rynek Główny 1',
		),
	);

	$stub['object_terms'] = array(
		412 => array( 'Showrooms' ),
	);
}

/**
 * Asserts that the uninstaller did run and did something.
 *
 * The control every "left alone" case here needs. Without it, a case that
 * proves the locations survived is a case that passes against a file with
 * nothing in it, which is the shape this project has been caught by before:
 * an assertion about an absence is only worth the assertion beside it that
 * something was present.
 *
 * @return void
 * @throws Assertion_Failed When nothing was deleted or no statement ran.
 */
function slosm_uninstall_did_something(): void {
	assert_false(
		isset( $GLOBALS['slosm_stub']['options'][ Settings::OPTION ] ),
		'the uninstaller did not remove the settings option, so nothing below is a control'
	);

	assert_true(
		0 < count( $GLOBALS['slosm_stub']['db_queries'] ),
		'the uninstaller ran no statement at all, so nothing below is a control'
	);
}

/**
 * Every table named by a statement the uninstaller ran.
 *
 * @return string[]
 */
function slosm_uninstall_tables(): array {
	$tables = array();

	foreach ( $GLOBALS['slosm_stub']['db_queries'] as $sql ) {
		if ( preg_match( '/DELETE\s+FROM\s+(\S+)/i', (string) $sql, $match ) ) {
			$tables[] = $match[1];
		}
	}

	return array_values( array_unique( $tables ) );
}

/**
 * Runs one child php process over a script and hands back what it printed.
 *
 * The guard cannot be tested in this process: WP_UNINSTALL_PLUGIN is a
 * constant, every other case here has to define it, and a constant cannot be
 * undefined. So the guard gets a process with nothing defined in it, and a
 * control process that differs by exactly that one line.
 *
 * @param bool $define Whether the child defines WP_UNINSTALL_PLUGIN.
 * @return string Everything the child printed, stdout and stderr.
 */
function slosm_uninstall_child( bool $define ): string {
	$root = str_replace( '\\', '/', dirname( __DIR__ ) );

	$child = "<?php\n"
		. "\$GLOBALS['deletes'] = array();\n"
		. "\$GLOBALS['queries'] = array();\n"
		. "function delete_option( \$name ) { \$GLOBALS['deletes'][] = \$name; return true; }\n"
		// A site that has never saved a setting, so remove_data is false and
		// this child stays a test of the guard rather than of the content
		// branch. The content branch has cases of its own, in this process.
		. "function get_option( \$name, \$default = false ) { return \$default; }\n"
		. "function is_multisite() { return false; }\n"
		. "class Child_Wpdb {\n"
		. "\tpublic \$options = 'wp_options';\n"
		. "\tpublic function esc_like( \$t ) { return addcslashes( \$t, '_%\\\\' ); }\n"
		. "\tpublic function prepare( \$q, ...\$a ) { return \$q . ' [' . implode( '|', \$a ) . ']'; }\n"
		. "\tpublic function query( \$s ) { \$GLOBALS['queries'][] = \$s; return 0; }\n"
		. "}\n"
		. "\$GLOBALS['wpdb'] = new Child_Wpdb();\n"
		// A shutdown function, because the guard's whole behaviour is to exit:
		// anything printed after the include would never run on the path the
		// case is about.
		. "register_shutdown_function( function () {\n"
		. "\techo \"\\nRESULT deletes=\" . count( \$GLOBALS['deletes'] ) . ' queries=' . count( \$GLOBALS['queries'] ) . \" END\\n\";\n"
		. "} );\n";

	if ( $define ) {
		$child .= "define( 'WP_UNINSTALL_PLUGIN', 'store-locator-for-openstreetmap/store-locator-for-openstreetmap.php' );\n";
	}

	$child .= 'include ' . var_export( $root . '/uninstall.php', true ) . ";\n";

	// No .php suffix: the CLI binary runs a file whatever it is called, and
	// appending one would orphan the file tempnam() itself created.
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

	return $output;
}

describe(
	'uninstall.php — the guard',
	function () {
		it(
			'does nothing at all when WP_UNINSTALL_PLUGIN is not defined',
			function () {
				$output = slosm_uninstall_child( false );

				assert_contains(
					'RESULT deletes=0 queries=0 END',
					$output,
					"a process that never defined WP_UNINSTALL_PLUGIN still changed something; it said:\n" . trim( $output )
				);

				// The control, in the same case, because "nothing happened" is
				// what a file with nothing in it also reports. The two children
				// differ by one line — the define — and nothing else.
				$control = slosm_uninstall_child( true );

				assert_false(
					false !== strpos( $control, 'RESULT deletes=0 queries=0 END' ),
					"the child that DID define the constant changed nothing either, so the assertion above is vacuous; it said:\n" . trim( $control )
				);
			}
		);

		it(
			'deletes when the constant is there, so the case above is a guard and not a broken child',
			function () {
				$output = slosm_uninstall_child( true );

				assert_contains(
					'RESULT deletes=5 queries=5 END',
					$output,
					"the control child was supposed to run the uninstaller; it said:\n" . trim( $output )
				);
			}
		);
	}
);

describe(
	'uninstall.php — the options it removes',
	function () {
		before_each( 'slosm_uninstall_site_fixture' );

		it(
			'removes the settings option, by the name Settings publishes',
			function () {
				assert_true( isset( $GLOBALS['slosm_stub']['options'][ Settings::OPTION ] ) );

				slosm_uninstall_run();

				assert_false( isset( $GLOBALS['slosm_stub']['options'][ Settings::OPTION ] ) );
				assert_true( in_array( Settings::OPTION, $GLOBALS['slosm_stub']['options_deleted'], true ) );
			}
		);

		it(
			'removes the map cache generation, by the name Store_Repository publishes',
			function () {
				assert_true( isset( $GLOBALS['slosm_stub']['options'][ Store_Repository::GENERATION_OPTION ] ) );

				slosm_uninstall_run();

				assert_false( isset( $GLOBALS['slosm_stub']['options'][ Store_Repository::GENERATION_OPTION ] ) );
			}
		);

		it(
			'removes the geocode cache generation, by the name Geocoder publishes',
			function () {
				assert_true( isset( $GLOBALS['slosm_stub']['options'][ Geocoder::GENERATION_OPTION ] ) );

				slosm_uninstall_run();

				assert_false( isset( $GLOBALS['slosm_stub']['options'][ Geocoder::GENERATION_OPTION ] ) );
			}
		);

		it(
			'removes the throttle timestamp of both geocoding services',
			function () {
				$geocoder = new Geocoder(
					static function (): float {
						return slosm_stub_time();
					},
					'slosm_stub_sleep'
				);

				$nominatim = $geocoder->last_request_option( Geocoder::SERVICE_NOMINATIM );
				$photon    = $geocoder->last_request_option( Geocoder::SERVICE_PHOTON );

				assert_true( isset( $GLOBALS['slosm_stub']['options'][ $nominatim ] ) );
				assert_true( isset( $GLOBALS['slosm_stub']['options'][ $photon ] ) );

				slosm_uninstall_run();

				assert_false( isset( $GLOBALS['slosm_stub']['options'][ $nominatim ] ) );
				assert_false( isset( $GLOBALS['slosm_stub']['options'][ $photon ] ) );
			}
		);

		it(
			'removes those five options and no others',
			function () {
				slosm_uninstall_run();

				$deleted = $GLOBALS['slosm_stub']['options_deleted'];
				sort( $deleted );

				assert_same(
					array(
						'slosm_cache_generation',
						'slosm_geocode_generation',
						'slosm_geocode_last_request_nominatim',
						'slosm_geocode_last_request_photon',
						'slosm_settings',
					),
					$deleted
				);
			}
		);

		it(
			"leaves another plugin's options where they are",
			function () {
				slosm_uninstall_run();

				$left = $GLOBALS['slosm_stub']['options'];
				ksort( $left );

				assert_same(
					array(
						'slosmish_other_plugin' => 'keep me',
						'woocommerce_currency'  => 'PLN',
					),
					$left
				);
			}
		);

		it(
			'runs twice without asking for anything different the second time',
			function () {
				slosm_uninstall_run();

				$first = $GLOBALS['slosm_stub']['options_deleted'];

				assert_true( 0 < count( $first ), 'the first run deleted nothing, so the comparison below is vacuous' );

				$GLOBALS['slosm_stub']['options_deleted'] = array();

				slosm_uninstall_run();

				assert_same( $first, $GLOBALS['slosm_stub']['options_deleted'] );
			}
		);
	}
);

describe(
	'uninstall.php — the transient rows it sweeps',
	function () {
		before_each( 'slosm_uninstall_site_fixture' );

		it(
			'sweeps the map payload row a real cache key produces',
			function () {
				$repository = new Store_Repository(
					static function (): array {
						return array();
					}
				);

				$row = '_transient_' . $repository->cache_key();

				assert_true( isset( $GLOBALS['slosm_stub']['db_options'][ $row ] ), 'fixture did not seed ' . $row );

				slosm_uninstall_run();

				assert_false( isset( $GLOBALS['slosm_stub']['db_options'][ $row ] ) );
			}
		);

		it(
			'sweeps the geocode row a real cache key produces',
			function () {
				$geocoder = new Geocoder(
					static function (): float {
						return slosm_stub_time();
					},
					'slosm_stub_sleep'
				);

				$row = '_transient_' . $geocoder->cache_key( Geocoder::SERVICE_NOMINATIM, 'Rynek Główny 1, Kraków', 'pl' );

				assert_true( isset( $GLOBALS['slosm_stub']['db_options'][ $row ] ), 'fixture did not seed ' . $row );

				slosm_uninstall_run();

				assert_false( isset( $GLOBALS['slosm_stub']['db_options'][ $row ] ) );
			}
		);

		it(
			'sweeps the suggestion row a real cache key produces',
			function () {
				$geocoder = new Geocoder(
					static function (): float {
						return slosm_stub_time();
					},
					'slosm_stub_sleep'
				);

				$row = '_transient_' . $geocoder->cache_key( Geocoder::SERVICE_PHOTON, 'Krak', '' );

				assert_true( isset( $GLOBALS['slosm_stub']['db_options'][ $row ] ), 'fixture did not seed ' . $row );

				slosm_uninstall_run();

				assert_false( isset( $GLOBALS['slosm_stub']['db_options'][ $row ] ) );
			}
		);

		it(
			'sweeps the geocode-failure row Admin produces',
			function () {
				$row = '_transient_' . Admin::failure_key( 'Nowhere At All', 'pl' );

				assert_true( isset( $GLOBALS['slosm_stub']['db_options'][ $row ] ), 'fixture did not seed ' . $row );

				slosm_uninstall_run();

				assert_false( isset( $GLOBALS['slosm_stub']['db_options'][ $row ] ) );
			}
		);

		it(
			'sweeps the location-notice row Admin produces',
			function () {
				$row = '_transient_' . Admin::NOTICE_PREFIX . '412_3';

				assert_true( isset( $GLOBALS['slosm_stub']['db_options'][ $row ] ), 'fixture did not seed ' . $row );

				slosm_uninstall_run();

				assert_false( isset( $GLOBALS['slosm_stub']['db_options'][ $row ] ) );
			}
		);

		it(
			'sweeps the bulk-geocode report row',
			function () {
				$row = '_transient_' . Bulk_Geocode::REPORT_PREFIX . '3';

				assert_true( isset( $GLOBALS['slosm_stub']['db_options'][ $row ] ), 'fixture did not seed ' . $row );

				slosm_uninstall_run();

				assert_false( isset( $GLOBALS['slosm_stub']['db_options'][ $row ] ) );
			}
		);

		it(
			'sweeps the timeout row beside every one of them',
			function () {
				$own = slosm_uninstall_own_rows();

				// Twelve: six transients, each with its timeout row. A fixture
				// that had drifted would make the next assertion vacuous.
				assert_same( 12, count( $own ) );

				slosm_uninstall_run();

				$left = array_values(
					array_filter(
						$own,
						static function ( string $row ): bool {
							return isset( $GLOBALS['slosm_stub']['db_options'][ $row ] );
						}
					)
				);

				assert_same( array(), $left );
			}
		);

		it(
			"leaves another plugin's transient rows alone",
			function () {
				slosm_uninstall_run();
				slosm_uninstall_did_something();

				assert_true( isset( $GLOBALS['slosm_stub']['db_options']['_transient_wc_products_onsale'] ) );
				assert_true( isset( $GLOBALS['slosm_stub']['db_options']['_transient_timeout_wc_products_onsale'] ) );
			}
		);

		it(
			'treats its own underscores as characters rather than as wildcards',
			function () {
				slosm_uninstall_run();

				// The control: the row that differs from these two by exactly
				// the characters under test is gone.
				assert_false(
					isset( $GLOBALS['slosm_stub']['db_options'][ '_transient_' . Admin::NOTICE_PREFIX . '412_3' ] ),
					'nothing was swept at all, so the two rows below prove nothing'
				);

				assert_true(
					isset( $GLOBALS['slosm_stub']['db_options']['Xtransient_slosm_geo_v1_g0_abc'] ),
					'an unescaped underscore in the LIKE pattern matched a row that is not a transient at all'
				);
				assert_true(
					isset( $GLOBALS['slosm_stub']['db_options']['_transientXslosm_geo_v1_g0_abc'] ),
					'an unescaped underscore in the LIKE pattern matched a row that is not a transient at all'
				);
			}
		);

		it(
			'names the options table and nothing else',
			function () {
				slosm_uninstall_run();

				assert_same( array( 'wp_options' ), slosm_uninstall_tables() );
			}
		);

		it(
			'still deletes both generations on a site whose transients SQL cannot see',
			function () {
				// Redis in front of the site: the payloads are reachable
				// through the options API and invisible to every statement.
				$GLOBALS['slosm_stub']['db_options'] = array();
				$GLOBALS['slosm_stub']['transients'] = array(
					'slosm_stores_lean_v1_g4_en' => array(
						'value'   => array(),
						'ttl'     => DAY_IN_SECONDS,
						'expires' => slosm_stub_time() + DAY_IN_SECONDS,
					),
				);

				slosm_uninstall_run();

				$deleted = $GLOBALS['slosm_stub']['options_deleted'];

				assert_true( in_array( Store_Repository::GENERATION_OPTION, $deleted, true ) );
				assert_true( in_array( Geocoder::GENERATION_OPTION, $deleted, true ) );

				// And the payload is left exactly where it is, unreachable
				// rather than deleted — which is all any code can do here.
				assert_true( isset( $GLOBALS['slosm_stub']['transients']['slosm_stores_lean_v1_g4_en'] ) );
			}
		);
	}
);

describe(
	'uninstall.php — the locations',
	function () {
		before_each( 'slosm_uninstall_site_fixture' );

		it(
			'leaves the location posts alone',
			function () {
				$before = $GLOBALS['slosm_stub']['posts'];

				slosm_uninstall_run();
				slosm_uninstall_did_something();

				assert_same( $before, $GLOBALS['slosm_stub']['posts'] );
			}
		);

		it(
			'leaves the location meta alone',
			function () {
				$before = $GLOBALS['slosm_stub']['post_meta'];

				slosm_uninstall_run();
				slosm_uninstall_did_something();

				assert_same( $before, $GLOBALS['slosm_stub']['post_meta'] );
			}
		);

		it(
			'leaves the categories alone',
			function () {
				$before = $GLOBALS['slosm_stub']['object_terms'];

				slosm_uninstall_run();
				slosm_uninstall_did_something();

				assert_same( $before, $GLOBALS['slosm_stub']['object_terms'] );
			}
		);

		it(
			'issues no statement against posts, postmeta or the term tables',
			function () {
				slosm_uninstall_run();
				slosm_uninstall_did_something();

				$content = array( 'wp_posts', 'wp_postmeta', 'wp_terms', 'wp_term_taxonomy', 'wp_term_relationships', 'wp_termmeta' );

				foreach ( slosm_uninstall_tables() as $table ) {
					assert_false(
						in_array( $table, $content, true ),
						'a statement named ' . $table . ', which holds the site owner\'s content'
					);
				}
			}
		);

		it(
			'asks for no location, so there is nothing for a later edit to start deleting from',
			function () {
				slosm_uninstall_run();
				slosm_uninstall_did_something();

				assert_same( array(), $GLOBALS['slosm_stub']['post_queries'] );
			}
		);
	}
);

describe(
	'uninstall.php — the inventory is exhaustive',
	function () {
		it(
			'accounts for every option and prefix constant the plugin declares',
			function () {
				$found = array();

				foreach ( array_merge(
					(array) glob( dirname( __DIR__ ) . '/includes/*.php' ),
					(array) glob( dirname( __DIR__ ) . '/admin/*.php' )
				) as $file ) {
					$source = (string) file_get_contents( $file );
					$name   = basename( dirname( $file ) ) . '/' . basename( $file );

					if ( preg_match_all(
						'/(?:public|private|protected)\s+const\s+([A-Z_]*(?:OPTION|PREFIX)[A-Z_]*)\s*=/',
						$source,
						$matches
					) ) {
						foreach ( $matches[1] as $constant ) {
							$found[] = $name . '::' . $constant;
						}
					}
				}

				sort( $found );

				/*
				 * The whole inventory, with what each one is. A new entry here
				 * is a new thing the plugin names, and this case failing is
				 * the only mechanism that makes somebody ask whether an
				 * uninstall has to know about it.
				 *
				 * swept   — a transient prefix uninstall.php deletes rows for
				 * deleted — an option uninstall.php deletes
				 * content — a post-meta prefix; the site owner's, left alone
				 * neither — not storage at all
				 */
				assert_same(
					array(
						'admin/class-admin.php::FAILURE_PREFIX',          // swept
						'admin/class-admin.php::FIELD_PREFIX',            // content
						'admin/class-admin.php::NOTICE_PREFIX',           // swept
						'admin/class-bulk-geocode.php::REPORT_PREFIX',    // swept
						'includes/class-autoloader.php::PREFIX',          // neither
						'includes/class-geocoder.php::CACHE_PREFIX',      // swept
						'includes/class-geocoder.php::GENERATION_OPTION', // deleted
						'includes/class-geocoder.php::LAST_REQUEST_PREFIX', // deleted, both services
						'includes/class-geocoder.php::SETTINGS_OPTION',   // deleted
						'includes/class-settings.php::OPTION',            // deleted, same option
						'includes/class-store-repository.php::CACHE_PREFIX', // swept
						'includes/class-store-repository.php::GENERATION_OPTION', // deleted
					),
					$found
				);
			}
		);

		it(
			'spells every one of those names in uninstall.php itself',
			function () {
				$source = (string) file_get_contents( dirname( __DIR__ ) . '/uninstall.php' );

				$geocoder = new Geocoder(
					static function (): float {
						return slosm_stub_time();
					},
					'slosm_stub_sleep'
				);

				// The five options, and the five transient prefixes, each
				// taken off the class that publishes it rather than retyped.
				$names = array(
					Settings::OPTION,
					Store_Repository::GENERATION_OPTION,
					Geocoder::GENERATION_OPTION,
					$geocoder->last_request_option( Geocoder::SERVICE_NOMINATIM ),
					$geocoder->last_request_option( Geocoder::SERVICE_PHOTON ),
					Store_Repository::CACHE_PREFIX,
					Geocoder::CACHE_PREFIX,
					Admin::NOTICE_PREFIX,
					Admin::FAILURE_PREFIX,
					Bulk_Geocode::REPORT_PREFIX,
				);

				foreach ( $names as $name ) {
					assert_contains(
						"'" . $name . "'",
						$source,
						$name . ' is a name this plugin stores under and uninstall.php does not mention'
					);
				}

				// One option, two constants. Deleting it once is enough, and
				// this is the line that says so.
				assert_same( Settings::OPTION, Geocoder::SETTINGS_OPTION );

				// The post-meta prefix is the location's own, and the one name
				// on the inventory that must NOT appear as something deleted.
				assert_false(
					false !== strpos( $source, "'" . Admin::FIELD_PREFIX . "'" ),
					'uninstall.php names the post-meta prefix, which belongs to the site owner'
				);
			}
		);
	}
);

describe(
	'uninstall.php — one site or a network',
	function () {
		before_each( 'slosm_uninstall_site_fixture' );

		it(
			'switches to no other site on a single-site install',
			function () {
				slosm_uninstall_run();
				slosm_uninstall_did_something();

				assert_same( array(), $GLOBALS['slosm_stub']['blog_switches'] );
			}
		);

		it(
			'asks for no site list on a single-site install',
			function () {
				slosm_uninstall_run();
				slosm_uninstall_did_something();

				assert_same( array(), $GLOBALS['slosm_stub']['site_queries'] );
			}
		);

		it(
			'cleans the site it was run on, on a network',
			function () {
				$GLOBALS['slosm_stub']['is_multisite']    = true;
				$GLOBALS['slosm_stub']['current_blog_id'] = 1;
				$GLOBALS['slosm_stub']['sites']           = array( 1, 7 );

				slosm_uninstall_run();

				assert_false( isset( $GLOBALS['slosm_stub']['options'][ Settings::OPTION ] ) );
			}
		);

		it(
			'cleans every other site on the network',
			function () {
				$GLOBALS['slosm_stub']['is_multisite']    = true;
				$GLOBALS['slosm_stub']['current_blog_id'] = 1;
				$GLOBALS['slosm_stub']['sites']           = array( 1, 7, 9 );
				$GLOBALS['slosm_stub']['blogs']           = array(
					7 => array(
						'options'    => array( Settings::OPTION => array( 'units' => 'mi' ) ),
						'transients' => array(),
						'db_options' => array( '_transient_slosm_geo_v1_g0_abc' => 'a:0:{}' ),
					),
					9 => array(
						'options'    => array( Geocoder::GENERATION_OPTION => 2 ),
						'transients' => array(),
						'db_options' => array(),
					),
				);

				slosm_uninstall_run();

				assert_same( array(), $GLOBALS['slosm_stub']['blogs'][7]['options'] );
				assert_same( array(), $GLOBALS['slosm_stub']['blogs'][7]['db_options'] );
				assert_same( array(), $GLOBALS['slosm_stub']['blogs'][9]['options'] );
			}
		);

		it(
			'restores the current site after every switch',
			function () {
				$GLOBALS['slosm_stub']['is_multisite']    = true;
				$GLOBALS['slosm_stub']['current_blog_id'] = 1;
				$GLOBALS['slosm_stub']['sites']           = array( 1, 7, 9 );

				slosm_uninstall_run();

				assert_same( 2, count( $GLOBALS['slosm_stub']['blog_switches'] ) );
				assert_same( 2, $GLOBALS['slosm_stub']['blog_restores'] );
				assert_same( 1, $GLOBALS['slosm_stub']['current_blog_id'] );
				assert_same( array(), $GLOBALS['slosm_stub']['blog_stack'] );
			}
		);

		it(
			'does not switch to the site it is already on',
			function () {
				$GLOBALS['slosm_stub']['is_multisite']    = true;
				$GLOBALS['slosm_stub']['current_blog_id'] = 7;
				$GLOBALS['slosm_stub']['sites']           = array( 1, 7, 9 );

				slosm_uninstall_run();

				assert_same( array( 1, 9 ), $GLOBALS['slosm_stub']['blog_switches'] );
			}
		);

		it(
			'pages the site list rather than asking for all of it at once',
			function () {
				$GLOBALS['slosm_stub']['is_multisite']    = true;
				$GLOBALS['slosm_stub']['current_blog_id'] = 1;
				$GLOBALS['slosm_stub']['sites']           = range( 1, 250 );

				slosm_uninstall_run();

				$queries = $GLOBALS['slosm_stub']['site_queries'];

				assert_true( 1 < count( $queries ), 'the whole network was asked for in one query' );

				foreach ( $queries as $args ) {
					assert_same( 'ids', $args['fields'] ?? null );
					assert_true( 0 < (int) ( $args['number'] ?? 0 ), 'a page with no number is every site' );
				}

				// Each page starts where the last one ended. A run of pages
				// that all start in the same place is a loop that never
				// finishes on a real network, which is a thing no case can be
				// allowed to discover by running it.
				$offsets = array();
				$number  = (int) $queries[0]['number'];

				foreach ( $queries as $index => $args ) {
					$offsets[] = (int) ( $args['offset'] ?? 0 );

					assert_same( $number, (int) $args['number'], 'the page size changed between pages' );
					assert_same( $index * $number, (int) ( $args['offset'] ?? 0 ) );
				}

				assert_same( count( $offsets ), count( array_unique( $offsets ) ) );

				// Every site but the one it started on.
				assert_same( 249, count( $GLOBALS['slosm_stub']['blog_switches'] ) );
			}
		);

		it(
			"names the switched site's own options table",
			function () {
				$GLOBALS['slosm_stub']['is_multisite']    = true;
				$GLOBALS['slosm_stub']['current_blog_id'] = 1;
				$GLOBALS['slosm_stub']['sites']           = array( 1, 7 );

				slosm_uninstall_run();

				$tables = slosm_uninstall_tables();
				sort( $tables );

				assert_same( array( 'wp_7_options', 'wp_options' ), $tables );
			}
		);

		it(
			'cleans only the current site when the network is large',
			function () {
				$GLOBALS['slosm_stub']['is_multisite']    = true;
				$GLOBALS['slosm_stub']['large_network']   = true;
				$GLOBALS['slosm_stub']['current_blog_id'] = 1;
				$GLOBALS['slosm_stub']['sites']           = array( 1, 7, 9 );

				slosm_uninstall_run();

				assert_same( array(), $GLOBALS['slosm_stub']['site_queries'] );
				assert_same( array(), $GLOBALS['slosm_stub']['blog_switches'] );

				// The site it ran on is still cleaned; "large" degrades to the
				// single-site answer rather than to doing nothing.
				assert_false( isset( $GLOBALS['slosm_stub']['options'][ Settings::OPTION ] ) );
			}
		);

		it(
			'asks wp_is_large_network about sites rather than about users',
			function () {
				$GLOBALS['slosm_stub']['is_multisite']    = true;
				$GLOBALS['slosm_stub']['current_blog_id'] = 1;
				$GLOBALS['slosm_stub']['sites']           = array( 1 );

				slosm_uninstall_run();

				assert_same( array( 'sites' ), $GLOBALS['slosm_stub']['large_network_checks'] );
			}
		);
	}
);

/**
 * Adds content to the fixture: locations, their meta and their categories,
 * plus one post and one term belonging to somebody else entirely.
 *
 * Three locations rather than one, because the deletions are paged and a
 * fixture of one cannot tell a loop that pages from a loop that happens to
 * work once. Two statuses, because a location in the trash is still a
 * location and `'post_status' => 'any'` would leave it behind.
 *
 * @param bool $remove Whether the site has asked for its content to go.
 * @return void
 */
function slosm_uninstall_content_fixture( bool $remove ): void {
	slosm_uninstall_site_fixture();

	$stub = &$GLOBALS['slosm_stub'];

	$stub['options'][ Settings::OPTION ] = array(
		'units'       => 'km',
		'remove_data' => $remove,
	);

	$stub['posts'] = array(
		array(
			'ID'          => 412,
			'post_title'  => 'Kraków — Rynek',
			'post_type'   => Post_Type::POST_TYPE,
			'post_status' => 'publish',
		),
		array(
			'ID'          => 413,
			'post_title'  => 'Kraków — Kazimierz',
			'post_type'   => Post_Type::POST_TYPE,
			'post_status' => 'draft',
		),
		array(
			'ID'          => 414,
			'post_title'  => 'Closed branch',
			'post_type'   => Post_Type::POST_TYPE,
			'post_status' => 'trash',
		),

		// Somebody else's, and it outlives this plugin either way.
		array(
			'ID'          => 900,
			'post_title'  => 'About us',
			'post_type'   => 'page',
			'post_status' => 'publish',
		),
	);

	$stub['post_meta'] = array(
		412 => array(
			'slosm_lat'     => '50.0616',
			'slosm_lng'     => '19.9373',
			'slosm_address' => 'Rynek Główny 1',
		),
		413 => array( 'slosm_lat' => '50.0510' ),
		900 => array( '_wp_page_template' => 'default' ),
	);

	$stub['object_terms'] = array(
		412 => array( 'Showrooms' ),
		900 => array( 'Company' ),
	);

	$stub['terms'] = array(
		Post_Type::TAXONOMY => array(
			array(
				'term_id' => 31,
				'name'    => 'Showrooms',
				'slug'    => 'showrooms',
			),
			array(
				'term_id' => 32,
				'name'    => 'Pickup points',
				'slug'    => 'pickup-points',
			),
		),

		// Another plugin's taxonomy, or the site's own.
		'product_cat'       => array(
			array(
				'term_id' => 77,
				'name'    => 'Wine',
				'slug'    => 'wine',
			),
		),
	);
}

/**
 * The ids of the posts still staged, whatever their type.
 *
 * @return int[]
 */
function slosm_uninstall_post_ids(): array {
	return array_map(
		static function ( array $row ): int {
			return (int) $row['ID'];
		},
		$GLOBALS['slosm_stub']['posts']
	);
}

describe(
	'uninstall.php — the remove_data option, and the order it has to be read in',
	function () {
		it(
			'reads the flag out of the settings before deleting the option that holds it',
			function () {
				slosm_uninstall_content_fixture( true );

				slosm_uninstall_run();

				$log    = $GLOBALS['slosm_stub']['option_log'];
				$read   = array_search( 'read:' . Settings::OPTION, $log, true );
				$delete = array_search( 'delete:' . Settings::OPTION, $log, true );

				assert_true( false !== $read, 'the settings option was never read at all' );
				assert_true( false !== $delete, 'the settings option was never deleted' );
				assert_true(
					$read < $delete,
					'the settings option was deleted before the flag inside it was read, so the flag can only ever read false'
				);
			}
		);

		it(
			'reads it once, and never goes back to an option it has deleted',
			function () {
				slosm_uninstall_content_fixture( true );

				slosm_uninstall_run();

				$reads = array_filter(
					$GLOBALS['slosm_stub']['option_log'],
					static function ( string $entry ): bool {
						return 'read:' . Settings::OPTION === $entry;
					}
				);

				assert_same( 1, count( $reads ) );
			}
		);

		it(
			'deletes the locations when the option is on',
			function () {
				slosm_uninstall_content_fixture( true );

				slosm_uninstall_run();

				assert_same( array( 900 ), slosm_uninstall_post_ids() );
			}
		);

		it(
			'deletes a location that is only in the trash',
			function () {
				slosm_uninstall_content_fixture( true );

				slosm_uninstall_run();

				assert_false(
					in_array( 414, slosm_uninstall_post_ids(), true ),
					"a trashed location survived, which is what 'post_status' => 'any' does"
				);
			}
		);

		it(
			'takes the location meta with them',
			function () {
				slosm_uninstall_content_fixture( true );

				slosm_uninstall_run();

				assert_same( array( 900 ), array_keys( $GLOBALS['slosm_stub']['post_meta'] ) );
			}
		);

		it(
			'takes the term relationships with them, which needs the post type registered first',
			function () {
				slosm_uninstall_content_fixture( true );

				slosm_uninstall_run();

				assert_same( array( 900 ), array_keys( $GLOBALS['slosm_stub']['object_terms'] ) );
			}
		);

		it(
			'deletes the location categories when the option is on',
			function () {
				slosm_uninstall_content_fixture( true );

				slosm_uninstall_run();

				assert_same( array(), $GLOBALS['slosm_stub']['terms'][ Post_Type::TAXONOMY ] );
			}
		);

		it(
			'registers the taxonomy before asking for its terms, or it is handed an error',
			function () {
				slosm_uninstall_content_fixture( true );

				slosm_uninstall_run();

				$queries = $GLOBALS['slosm_stub']['term_queries'];

				assert_true( 0 < count( $queries ), 'the terms were never asked for' );
				assert_same( Post_Type::TAXONOMY, $queries[0]['taxonomy'] ?? null );

				// The control for the assertion above: an unregistered
				// taxonomy answers WP_Error and nothing would have gone.
				assert_true(
					slosm_stub_is_registered( 'taxonomies', Post_Type::TAXONOMY ),
					'the taxonomy was never registered, so get_terms() answered WP_Error'
				);
				assert_true(
					slosm_stub_is_registered( 'post_types', Post_Type::POST_TYPE ),
					'the post type was never registered, so the term relationships stayed'
				);
			}
		);

		it(
			'forces the delete rather than moving the locations to the trash',
			function () {
				slosm_uninstall_content_fixture( true );

				slosm_uninstall_run();

				$calls = $GLOBALS['slosm_stub']['deleted_posts'];

				assert_true( 0 < count( $calls ), 'nothing was deleted at all' );

				foreach ( $calls as $call ) {
					assert_true(
						$call['force'],
						'post ' . $call['id'] . ' was trashed rather than deleted, and a trashed location is still a location'
					);
				}
			}
		);

		it(
			'asks for the locations in pages rather than for every one at once',
			function () {
				slosm_uninstall_content_fixture( true );

				slosm_uninstall_run();

				$queries = array_values(
					array_filter(
						$GLOBALS['slosm_stub']['post_queries'],
						static function ( array $args ): bool {
							return isset( $args['fields'] ) && 'ids' === $args['fields'];
						}
					)
				);

				assert_true( 0 < count( $queries ), 'the locations were never asked for by id' );

				foreach ( $queries as $args ) {
					assert_true(
						0 < (int) ( $args['numberposts'] ?? 0 ),
						'a query with no limit is every location on the site in one array'
					);
					assert_same( Post_Type::POST_TYPE, $args['post_type'] ?? null );
				}
			}
		);

		it(
			'asks for the terms in pages too',
			function () {
				slosm_uninstall_content_fixture( true );

				slosm_uninstall_run();

				assert_true(
					0 < count( $GLOBALS['slosm_stub']['term_queries'] ),
					'the terms were never asked for, so there is nothing here to be paged'
				);

				foreach ( $GLOBALS['slosm_stub']['term_queries'] as $args ) {
					assert_true( 0 < (int) ( $args['number'] ?? 0 ), 'a term query with no limit is every term' );
				}
			}
		);

		it(
			'stops rather than looping when a location refuses to be deleted',
			function () {
				slosm_uninstall_content_fixture( true );

				// A location another plugin will not let go: wp_delete_post()
				// answers false and the row stays, so every page of the query
				// comes back exactly as it went. A loop that waits for an
				// empty answer never gets one.
				$GLOBALS['slosm_stub']['undeletable_posts'] = array( 412, 413, 414 );

				slosm_uninstall_run();

				assert_true(
					count( $GLOBALS['slosm_stub']['post_queries'] ) < 20,
					'the uninstaller asked for the same page over and over, which on a real site is a request that never ends'
				);

				// The control: it did try. Without this the case passes for a
				// run that never reached the content branch at all.
				assert_same( 3, count( $GLOBALS['slosm_stub']['deleted_posts'] ) );
				assert_same( array( 412, 413, 414, 900 ), slosm_uninstall_post_ids() );
			}
		);
	}
);

describe(
	'uninstall.php — what remove_data still may not touch',
	function () {
		it(
			"leaves another post type alone with the option on",
			function () {
				slosm_uninstall_content_fixture( true );

				slosm_uninstall_run();

				// The control: this plugin's own content did go, so the page
				// surviving is a decision rather than an uninstaller that did
				// nothing at all.
				assert_false( in_array( 412, slosm_uninstall_post_ids(), true ), 'the locations were not deleted, so nothing below is a control' );

				assert_true( in_array( 900, slosm_uninstall_post_ids(), true ), 'a page was deleted' );
				assert_true( isset( $GLOBALS['slosm_stub']['post_meta'][900] ) );
				assert_true( isset( $GLOBALS['slosm_stub']['object_terms'][900] ) );
			}
		);

		it(
			"leaves another taxonomy's terms alone with the option on",
			function () {
				slosm_uninstall_content_fixture( true );

				slosm_uninstall_run();

				assert_same( array(), $GLOBALS['slosm_stub']['terms'][ Post_Type::TAXONOMY ], 'this plugin\x27s own terms were not deleted, so nothing below is a control' );

				assert_same( 1, count( $GLOBALS['slosm_stub']['terms']['product_cat'] ) );

				foreach ( $GLOBALS['slosm_stub']['deleted_terms'] as $call ) {
					assert_same( Post_Type::TAXONOMY, $call['taxonomy'] );
				}
			}
		);

		it(
			'registers nothing but its own post type and taxonomy',
			function () {
				slosm_uninstall_content_fixture( true );

				slosm_uninstall_run();

				$types = array_map(
					static function ( array $row ): string {
						return (string) $row['name'];
					},
					$GLOBALS['slosm_stub']['post_types']
				);

				$taxonomies = array_map(
					static function ( array $row ): string {
						return (string) $row['name'];
					},
					$GLOBALS['slosm_stub']['taxonomies']
				);

				assert_same( array( Post_Type::POST_TYPE ), array_values( array_unique( $types ) ) );
				assert_same( array( Post_Type::TAXONOMY ), array_values( array_unique( $taxonomies ) ) );
			}
		);
	}
);

describe(
	'uninstall.php — with remove_data off, which is what silence means',
	function () {
		it(
			'keeps every location when the option is off',
			function () {
				slosm_uninstall_content_fixture( false );

				slosm_uninstall_run();
				slosm_uninstall_did_something();

				assert_same( array( 412, 413, 414, 900 ), slosm_uninstall_post_ids() );
			}
		);

		it(
			'keeps the meta and the categories when the option is off',
			function () {
				slosm_uninstall_content_fixture( false );

				slosm_uninstall_run();
				slosm_uninstall_did_something();

				assert_same( array( 412, 413, 900 ), array_keys( $GLOBALS['slosm_stub']['post_meta'] ) );
				assert_same( 2, count( $GLOBALS['slosm_stub']['terms'][ Post_Type::TAXONOMY ] ) );
			}
		);

		it(
			'deletes nothing and registers nothing when the option is off',
			function () {
				slosm_uninstall_content_fixture( false );

				slosm_uninstall_run();
				slosm_uninstall_did_something();

				assert_same( array(), $GLOBALS['slosm_stub']['deleted_posts'] );
				assert_same( array(), $GLOBALS['slosm_stub']['deleted_terms'] );
				assert_same( array(), $GLOBALS['slosm_stub']['post_types'] );
				assert_same( array(), $GLOBALS['slosm_stub']['taxonomies'] );
			}
		);

		it(
			'keeps every location on a site that has never saved a setting',
			function () {
				slosm_uninstall_content_fixture( false );
				unset( $GLOBALS['slosm_stub']['options'][ Settings::OPTION ] );

				slosm_uninstall_run();
				slosm_uninstall_did_something();

				assert_same( array( 412, 413, 414, 900 ), slosm_uninstall_post_ids() );
			}
		);

		it(
			'keeps every location when the settings option holds something that is not an array',
			function () {
				slosm_uninstall_content_fixture( false );
				$GLOBALS['slosm_stub']['options'][ Settings::OPTION ] = 'a restored backup left this here';

				slosm_uninstall_run();
				slosm_uninstall_did_something();

				assert_same( array( 412, 413, 414, 900 ), slosm_uninstall_post_ids() );
			}
		);

		it(
			'keeps every location when the flag is a falsy value rather than absent',
			function () {
				slosm_uninstall_content_fixture( false );
				$GLOBALS['slosm_stub']['options'][ Settings::OPTION ] = array( 'remove_data' => '0' );

				slosm_uninstall_run();
				slosm_uninstall_did_something();

				assert_same( array( 412, 413, 414, 900 ), slosm_uninstall_post_ids() );
			}
		);
	}
);

describe(
	'uninstall.php — the flag is per site on a network',
	function () {
		it(
			'reads and acts on each site and not on the first one twice',
			function () {
				slosm_uninstall_content_fixture( false );

				$GLOBALS['slosm_stub']['is_multisite']    = true;
				$GLOBALS['slosm_stub']['current_blog_id'] = 1;
				$GLOBALS['slosm_stub']['sites']           = array( 1, 7, 9 );

				$GLOBALS['slosm_stub']['blogs'] = array(
					// Site 7 asked for its content to go.
					7 => array(
						'options'    => array(
							Settings::OPTION => array( 'remove_data' => true ),
						),
						'transients' => array(),
						'db_options' => array(),
						'posts'      => array(
							array(
								'ID'          => 700,
								'post_title'  => 'Branch on site 7',
								'post_type'   => Post_Type::POST_TYPE,
								'post_status' => 'publish',
							),
						),
					),
					// Site 9 did not.
					9 => array(
						'options'    => array(
							Settings::OPTION => array( 'remove_data' => false ),
						),
						'transients' => array(),
						'db_options' => array(),
						'posts'      => array(
							array(
								'ID'          => 901,
								'post_title'  => 'Branch on site 9',
								'post_type'   => Post_Type::POST_TYPE,
								'post_status' => 'publish',
							),
						),
					),
				);

				slosm_uninstall_run();

				assert_same( array(), $GLOBALS['slosm_stub']['blogs'][7]['posts'] );
				assert_same( 1, count( $GLOBALS['slosm_stub']['blogs'][9]['posts'] ) );

				// And the site it started on, which said no, still has its own.
				assert_same( array( 412, 413, 414, 900 ), slosm_uninstall_post_ids() );
			}
		);
	}
);
