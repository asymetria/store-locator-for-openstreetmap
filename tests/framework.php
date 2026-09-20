<?php
/**
 * Minimal test framework. No dependencies, no autoloader, no config.
 *
 * @package Store_Locator_For_OpenStreetMap
 */

$GLOBALS['slosm_results'] = array(
	'pass' => 0,
	'fail' => 0,
);

/**
 * Setup closures registered by before_each() for the current group.
 *
 * @var callable[]
 */
$GLOBALS['slosm_before_each'] = array();

/**
 * Whether the next it() is the first case of its group.
 *
 * @var bool
 */
$GLOBALS['slosm_group_first_case'] = true;

/**
 * Thrown by the assert_* helpers.
 *
 * A failed assertion is self-describing, so it() prints its message alone;
 * any other Throwable is a surprise and gets class, file and line as well.
 */
class Assertion_Failed extends Exception {
}

/**
 * Declares a group of test cases.
 *
 * @param string   $group Group name.
 * @param callable $fn    Closure containing the cases.
 * @return void
 */
function describe( string $group, callable $fn ): void {
	$GLOBALS['slosm_before_each']      = array();
	$GLOBALS['slosm_group_first_case'] = true;

	echo "\n" . $group . "\n";
	$fn();
}

/**
 * Registers a setup closure to run before every case in the current group.
 *
 * This is where group fixtures belong. Setting stub state directly in the
 * describe() body does not work: it() resets before each case, so the fixture
 * is gone by the time the case runs.
 *
 * @param callable $fn Setup closure.
 * @return void
 */
function before_each( callable $fn ): void {
	$GLOBALS['slosm_before_each'][] = $fn;
}

/**
 * Runs a single test case and records the result.
 *
 * Stub state is reset both before and after every case, so no test can leak into
 * the next one and no test has to remember to reset, then the group's
 * before_each() closures run. The function_exists guards keep this file usable
 * without bootstrap.php.
 *
 * Resetting on the way out is what makes the next check trustworthy: state
 * dirty at the first case of a group cannot be a previous case's leftovers, so
 * it can only have been set up at file or group scope, where it would be
 * silently discarded — and a case asserting that something is *gone* would then
 * pass without the code under test doing anything. That is a failure, not a
 * warning.
 *
 * @param string   $name Case name.
 * @param callable $fn   Closure containing the assertions.
 * @return void
 */
function it( string $name, callable $fn ): void {
	$dirty = false;

	if ( $GLOBALS['slosm_group_first_case'] && isset( $GLOBALS['slosm_stub'] ) && function_exists( 'slosm_stub_defaults' ) ) {
		$dirty = slosm_stub_defaults() !== $GLOBALS['slosm_stub'];
	}

	$GLOBALS['slosm_group_first_case'] = false;

	if ( function_exists( 'slosm_stub_reset' ) ) {
		slosm_stub_reset();
	}

	if ( $dirty ) {
		$GLOBALS['slosm_results']['fail']++;
		echo '  FAIL  ' . $name . "\n";
		echo "        stub state was set up outside a test case, at file or group scope\n";
		echo "        it() resets before every case, so that setup was discarded and never reached this test\n";
		echo "        move it into before_each()\n";
		return;
	}

	/*
	 * A PHP warning is not a Throwable, so without this a case can raise
	 * "Undefined array key" and still be counted as a pass -- the warning
	 * prints above the "ok" line and the exit code stays 0. That was proved,
	 * not assumed: the same probe case passes without this handler and fails
	 * with it. Cases that are *about* a warning install their own handler and
	 * return false for anything else, which bypasses this one on purpose.
	 */
	set_error_handler(
		static function ( $errno, $message, $file, $line ) {
			if ( ! ( error_reporting() & $errno ) ) {
				return false;
			}

			throw new ErrorException( $message, 0, $errno, $file, $line );
		}
	);

	try {
		foreach ( $GLOBALS['slosm_before_each'] as $setup ) {
			$setup();
		}

		$fn();
		$GLOBALS['slosm_results']['pass']++;
		echo '  ok    ' . $name . "\n";
	} catch ( Assertion_Failed $e ) {
		$GLOBALS['slosm_results']['fail']++;
		echo '  FAIL  ' . $name . "\n";
		echo '        ' . $e->getMessage() . "\n";
	} catch ( Throwable $e ) {
		$GLOBALS['slosm_results']['fail']++;
		echo '  FAIL  ' . $name . "\n";
		echo '        ' . get_class( $e ) . ': ' . $e->getMessage() . "\n";
		echo '        at ' . $e->getFile() . ':' . $e->getLine() . "\n";
	} finally {
		restore_error_handler();

		if ( function_exists( 'slosm_stub_reset' ) ) {
			slosm_stub_reset();
		}
	}
}

/**
 * Asserts that two values are identical.
 *
 * @param mixed  $expected Expected value.
 * @param mixed  $actual   Actual value.
 * @param string $message  Optional failure message.
 * @return void
 * @throws Assertion_Failed When the values differ.
 */
function assert_same( $expected, $actual, string $message = '' ): void {
	if ( $expected !== $actual ) {
		throw new Assertion_Failed(
			$message ?: sprintf(
				'expected %s, got %s',
				var_export( $expected, true ),
				var_export( $actual, true )
			)
		);
	}
}

/**
 * Asserts that a value is boolean true.
 *
 * @param mixed  $value   Value to check.
 * @param string $message Optional failure message.
 * @return void
 * @throws Assertion_Failed When the value is not true.
 */
function assert_true( $value, string $message = '' ): void {
	if ( true !== $value ) {
		throw new Assertion_Failed( $message ?: 'expected true, got ' . var_export( $value, true ) );
	}
}

/**
 * Asserts that a value is boolean false.
 *
 * @param mixed  $value   Value to check.
 * @param string $message Optional failure message.
 * @return void
 * @throws Assertion_Failed When the value is not false.
 */
function assert_false( $value, string $message = '' ): void {
	if ( false !== $value ) {
		throw new Assertion_Failed( $message ?: 'expected false, got ' . var_export( $value, true ) );
	}
}

/**
 * Asserts that two floats are equal within a tolerance.
 *
 * NAN fails outright: every comparison against NAN is false, so the tolerance
 * test alone would pass a NAN silently. The distance maths can produce one from
 * acos(), asin(), sqrt() or a division near the poles, which is exactly the
 * failure this assertion exists to catch.
 *
 * @param float  $expected  Expected value.
 * @param float  $actual    Actual value.
 * @param float  $tolerance Maximum allowed difference.
 * @param string $message   Optional failure message.
 * @return void
 * @throws Assertion_Failed When either value is NAN or the difference exceeds the tolerance.
 */
function assert_close( float $expected, float $actual, float $tolerance, string $message = '' ): void {
	if ( is_nan( $expected ) || is_nan( $actual ) ) {
		$detail = sprintf(
			'NAN in float comparison (NAN is never close to anything): expected %s, got %s',
			is_nan( $expected ) ? 'NAN' : sprintf( '%F', $expected ),
			is_nan( $actual ) ? 'NAN' : sprintf( '%F', $actual )
		);

		throw new Assertion_Failed( '' === $message ? $detail : $message . ' — ' . $detail );
	}

	if ( abs( $expected - $actual ) > $tolerance ) {
		throw new Assertion_Failed(
			$message ?: sprintf(
				'expected %F (tolerance %F), got %F',
				$expected,
				$tolerance,
				$actual
			)
		);
	}
}

/**
 * Asserts that a string contains a substring.
 *
 * @param string $needle   Substring to look for.
 * @param string $haystack String to search.
 * @param string $message  Optional failure message.
 * @return void
 * @throws Assertion_Failed When the substring is missing.
 */
function assert_contains( string $needle, string $haystack, string $message = '' ): void {
	if ( false === strpos( $haystack, $needle ) ) {
		throw new Assertion_Failed( $message ?: sprintf( '"%s" not found in "%s"', $needle, $haystack ) );
	}
}
