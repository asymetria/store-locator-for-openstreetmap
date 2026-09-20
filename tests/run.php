<?php
/**
 * Test runner. Usage: php tests/run.php
 *
 * @package Store_Locator_For_OpenStreetMap
 */

require_once __DIR__ . '/framework.php';
require_once __DIR__ . '/bootstrap.php';

if ( ! function_exists( 'slosm_load_test_file' ) ) {
	/**
	 * Loads one test file, recording a file that cannot load as one failure.
	 *
	 * Without this, a single unloadable test file takes the whole suite with it:
	 * the process dies on the spot with exit 255, the summary never prints, and
	 * the other files' passing cases are blanked along with it. That is not a
	 * hypothetical. Test-first means every new test file spends its first minutes
	 * requiring a class file that has not been written yet, and a blank 255 at
	 * that moment says only "something died somewhere" — not which file, not
	 * whether the rest of the suite still passes.
	 *
	 * What the catch does, and what the handler adds
	 * ----------------------------------------------
	 * The try/catch carries this on its own. Measured, not assumed: a parse error
	 * throws ParseError, a missing class at file scope throws Error, and a
	 * require of a file that is not there throws a plain Error too — "Failed
	 * opening required" — on both 8.2 and 8.5. Neutralising the handler below and
	 * re-running gives the identical named failure, the identical count and the
	 * identical exit 1.
	 *
	 * So the handler is not what makes this work, and an earlier version of this
	 * comment claiming otherwise was describing a test that had never been run:
	 * the require-with-handler case was verified to *work*, never verified to
	 * *break* without it. What the handler actually buys is the raw
	 * "Warning: require_once(...): Failed to open stream" line that PHP would
	 * otherwise print above the failure, and a message that names the missing
	 * path rather than the requiring file. Whether some older PHP needs it for
	 * more than that is untested, because neither binary here is below 8.2.
	 *
	 * The handler stays installed while the file's cases run, since require_once
	 * is what runs them, so its scope is narrowed three ways: the @ operator is
	 * honoured, only E_WARNING is converted, and only the one message. A
	 * suppressed @file_get_contents() inside a case must stay suppressed, and a
	 * test that triggers its own E_USER_WARNING with matching text must not be
	 * hijacked by wording alone.
	 *
	 * @param string $file Absolute path to a test file.
	 * @return void
	 */
	function slosm_load_test_file( string $file ): void {
		set_error_handler(
			static function ( $errno, $message, $errfile, $errline ) {
				// Under @, error_reporting() returns a mask with this bit
				// cleared. A handler is still called, and one that ignores the
				// mask makes @ mean nothing.
				if ( ! ( error_reporting() & $errno ) ) {
					return false;
				}

				// E_USER_WARNING and E_DEPRECATED can carry any text a test
				// chooses, including this one.
				if ( E_WARNING !== $errno ) {
					return false;
				}

				if ( false === stripos( $message, 'Failed to open stream' ) ) {
					return false;
				}

				throw new ErrorException( $message, 0, $errno, $errfile, $errline );
			}
		);

		try {
			require_once $file;
		} catch ( Throwable $e ) {
			$GLOBALS['slosm_results']['fail']++;

			// Shaped like framework.php's own output so a load failure reads as
			// one more failing case rather than as debris from the runner.
			//
			// "stopped while loading", not "could not load": the throw can come
			// from anywhere in the file, and cases above it may already have run
			// and been counted. What is always true is that everything below it
			// did not.
			echo "\n" . basename( $file ) . "\n";
			echo '  FAIL  stopped while loading ' . basename( $file ) . ', so any cases below that point did not run' . "\n";
			echo '        ' . get_class( $e ) . ': ' . $e->getMessage() . "\n";
			echo '        at ' . $e->getFile() . ':' . $e->getLine() . "\n";
		} finally {
			restore_error_handler();
		}
	}
}

$files = glob( __DIR__ . '/test-*.php' );

if ( false === $files || array() === $files ) {
	echo "No test files found. Test files must match tests/test-*.php\n";
	exit( 1 );
}

foreach ( $files as $file ) {
	slosm_load_test_file( $file );
}

$results = $GLOBALS['slosm_results'];

echo "\n" . str_repeat( '-', 40 ) . "\n";
printf( "%d passed, %d failed\n", $results['pass'], $results['fail'] );

if ( 0 === $results['pass'] + $results['fail'] ) {
	echo "No test cases ran. Test files must match tests/test-*.php\n";
	exit( 1 );
}

exit( $results['fail'] > 0 ? 1 : 0 );
