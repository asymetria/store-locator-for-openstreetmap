<?php
/**
 * Proves the harness itself works: assertions, stub state, transients, http queue.
 *
 * Nothing here calls slosm_stub_reset(); it() does that before every case.
 *
 * @package Store_Locator_For_OpenStreetMap
 */

describe(
	'test harness',
	function () {

		it(
			'reports equality',
			function () {
				assert_same( 3, 1 + 2 );
			}
		);

		it(
			'compares floats within a tolerance',
			function () {
				assert_close( 1.0, 1.0001, 0.001 );
			}
		);

		it(
			'fails a float comparison against NAN',
			function () {
				$failed = false;

				try {
					assert_close( 252.0, NAN, 2.0 );
				} catch ( Assertion_Failed $e ) {
					$failed = true;
					assert_contains( 'NAN', $e->getMessage() );
				}

				assert_true( $failed, 'assert_close accepted NAN' );
			}
		);

		it(
			'stubs transients and records their lifetime',
			function () {
				assert_false( get_transient( 'nothing' ) );

				set_transient( 'thing', 'value', 60 );

				assert_same( 'value', get_transient( 'thing' ) );
				assert_same( 60, $GLOBALS['slosm_stub']['transients']['thing']['ttl'] );

				slosm_stub_advance_time( 61 );

				assert_false( get_transient( 'thing' ) );
			}
		);

		it(
			'queues http responses',
			function () {
				$GLOBALS['slosm_stub']['http_queue'][] = array(
					'response' => array( 'code' => 200 ),
					'body'     => '{"ok":true}',
				);

				$response = wp_remote_get( 'https://example.test/' );

				assert_same( '{"ok":true}', wp_remote_retrieve_body( $response ) );
				assert_same( 1, count( $GLOBALS['slosm_stub']['http_requests'] ) );
			}
		);

		it(
			'fails an http request that was never queued',
			function () {
				$failed = false;

				try {
					wp_remote_get( 'https://unqueued.test/' );
				} catch ( RuntimeException $e ) {
					$failed = true;
					assert_contains( 'https://unqueued.test/', $e->getMessage() );
				}

				assert_true( $failed, 'wp_remote_get invented a response' );
				assert_same( 1, count( $GLOBALS['slosm_stub']['http_requests'] ) );
			}
		);

		it(
			'keeps text after a "<" that never closes',
			function () {
				assert_same( 'Bar &lt;Pub', sanitize_text_field( 'Bar <Pub' ) );
				assert_same( 'Zielona Gora', sanitize_text_field( 'Zielona <> Gora' ) );
			}
		);

		it(
			'fails a case whose group set stub state outside it()',
			function () {
				// Runs a group of its own to watch what it() does with it, so the
				// counters and group bookkeeping are saved and put back afterwards.
				$results    = $GLOBALS['slosm_results'];
				$fixtures   = $GLOBALS['slosm_before_each'];
				$first_case = $GLOBALS['slosm_group_first_case'];

				ob_start();
				describe(
					'group-scope setup probe',
					function () {
						set_transient( 'flushed_by_nobody', 'value', 60 );

						it(
							'inner case',
							function () {
								assert_false( get_transient( 'flushed_by_nobody' ) );
							}
						);
					}
				);
				$output = ob_get_clean();

				$failures = $GLOBALS['slosm_results']['fail'] - $results['fail'];
				$passes   = $GLOBALS['slosm_results']['pass'] - $results['pass'];

				$GLOBALS['slosm_results']          = $results;
				$GLOBALS['slosm_before_each']      = $fixtures;
				$GLOBALS['slosm_group_first_case'] = $first_case;

				assert_same( 1, $failures, 'group-scope setup did not fail the case' );
				assert_same( 0, $passes, 'the vacuous assertion was counted as a pass' );
				assert_contains( 'FAIL  inner case', $output );
				assert_contains( 'outside a test case', $output );
			}
		);

		before_each(
			function () {
				set_transient( 'fixture', 'ready', 60 );
			}
		);

		it(
			'runs before_each fixtures after the reset',
			function () {
				assert_same( 'ready', get_transient( 'fixture' ) );
			}
		);
	}
);
