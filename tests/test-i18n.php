<?php
/**
 * Task 24a: keeps the plugin's own PHP extractable.
 *
 * WHAT THIS FILE IS
 * =================
 * A scan of `store-locator-for-openstreetmap.php`, `uninstall.php`, `admin/*.php`
 * and `includes/*.php` for the mechanical half of internationalisation — the
 * half a `.pot` generator and Plugin Check can both see, and the half that is
 * invisible in a diff because a wrong call looks exactly like a right one until
 * somebody runs the extractor.
 *
 * It tokenises. It does not grep, and the difference is load-bearing: `__(` in
 * a docblock, `'__'` in a string and `$this->__()` are all things this plugin
 * contains, and a regex counts them. `token_get_all()` does not, and the same
 * pass is what makes "the second argument is a single string literal" a
 * question that can be asked at all.
 *
 * THE ONE THING THAT MAKES IT NOT A RESTATEMENT
 * =============================================
 * The expected text domain is **read out of the plugin header**, not typed
 * here. `Text Domain: store-locator-for-openstreetmap` in
 * store-locator-for-openstreetmap.php is the only statement of it in this
 * project, and every case below compares against that. A test holding its own
 * copy of the domain would pass a rename of the header and fail nothing — it
 * would assert that the source says what the source says. This one fails a
 * rename of the header against 380-odd call sites, which is the failure that
 * is actually worth having.
 *
 * WHAT EACH CASE CATCHES
 * ======================
 * - a domain that is a variable, a constant, a concatenation, a different
 *   string, or absent — all of which make the string unextractable or extract
 *   it into somebody else's .pot;
 * - a msgid, plural or context built by concatenation or interpolation, which
 *   the extractor reads as an empty string or skips outright;
 * - a msgid carrying a printf placeholder with no `/* translators: *​/` comment
 *   on the line above it, which is a translator guessing what `%s` holds;
 * - an `_n()` whose plural does not use the same placeholders as its singular,
 *   which is a fatal `sprintf()` in whichever locale reaches the other form;
 * - an empty context, which is a `_x()` that has the cost of one and none of
 *   the benefit;
 * - **one msgid translated with a context in one place and without one in
 *   another.** That is not a style rule. It is the exact state Task 29c left
 *   'Search' in: the button was given a context, the settings tab was not, and
 *   the bare msgid a translator then meets reads as the imperative it is not.
 *   Half a disambiguation is worse than none, because it looks finished.
 * - the header's Text Domain not matching the plugin's own file name, which is
 *   what WordPress.org requires of a submitted slug.
 *
 * WHAT IT CANNOT CATCH
 * ====================
 * Everything about *meaning*, and the list is not short:
 *
 * - **A user-facing string that never reaches a translation function at all.**
 *   Nothing mechanical tells `'Not on the map'` from `'slosm-list__unplaced'`;
 *   both are string literals in the same `echo`. The audit for that was done
 *   by reading the files, and it has to be done by reading them again.
 * - **Whether a context is right, or useful.** `'settings screen tab'` and
 *   `'xyzzy'` are the same to this file.
 * - **Whether a translators comment describes the placeholders it sits above.**
 *   Presence is checkable; accuracy is not.
 * - **Whether two msgids ought to be one, or one ought to be two.** The
 *   context-consistency case catches the half-done version of that decision,
 *   never the decision.
 * - **Whether a plural is needed at all.** A ternary over two singular `__()`
 *   calls is indistinguishable from a ternary over two different sentences,
 *   and this plugin has several of the second kind.
 * - **Anything outside the plugin's own PHP**: the JavaScript (pinned instead
 *   by the key-list cases in tests/js/), readme.txt, the .pot that does not
 *   exist yet, and a call reached through a variable function name or
 *   call_user_func().
 *
 * @package Store_Locator_For_OpenStreetMap
 */

if ( ! function_exists( 'slosm_i18n_root' ) ) {

	/**
	 * The plugin directory, with forward slashes.
	 *
	 * @return string
	 */
	function slosm_i18n_root(): string {
		return str_replace( '\\', '/', dirname( __DIR__ ) );
	}

	/**
	 * Every PHP file this plugin ships, excluding its own tests.
	 *
	 * The root glob picks up store-locator-for-openstreetmap.php and
	 * uninstall.php, which is the whole of the root; tests/ is not globbed at
	 * all. A test file is not shipped and its strings are not translated, so a
	 * scan that included this directory would be scanning itself.
	 *
	 * @return string[] Absolute paths, sorted.
	 */
	function slosm_i18n_files(): array {
		$files = array();

		foreach ( array( '', '/admin', '/includes' ) as $dir ) {
			$found = glob( slosm_i18n_root() . $dir . '/*.php' );

			if ( is_array( $found ) ) {
				foreach ( $found as $file ) {
					$files[] = str_replace( '\\', '/', $file );
				}
			}
		}

		sort( $files );

		return $files;
	}

	/**
	 * A path as it reads in a failure message.
	 *
	 * @param string $file Absolute path.
	 * @return string
	 */
	function slosm_i18n_short( string $file ): string {
		return str_replace( slosm_i18n_root() . '/', '', $file );
	}

	/**
	 * The plugin's main file.
	 *
	 * @return string Absolute path.
	 */
	function slosm_i18n_main_file(): string {
		return slosm_i18n_root() . '/store-locator-for-openstreetmap.php';
	}

	/**
	 * The text domain, read out of the plugin header.
	 *
	 * Deliberately not a constant in this file. See the file docblock.
	 *
	 * @return string The header's value, or '' when the header is gone.
	 */
	function slosm_i18n_declared_domain(): string {
		$header = (string) file_get_contents( slosm_i18n_main_file() );

		if ( ! preg_match( '/^[ \t\/*#@]*Text Domain:(.*)$/mi', $header, $match ) ) {
			return '';
		}

		return trim( $match[1] );
	}

	/**
	 * Which argument of each gettext-family function is which.
	 *
	 * Positions verified against wp-includes/l10n.php rather than remembered:
	 * _x( $text, $context, $domain ) at line 409, _n( $single, $plural,
	 * $number, $domain ) at 483, _nx( $single, $plural, $number, $context,
	 * $domain ) at 542, _n_noop( $singular, $plural, $domain ) at 608 and
	 * _nx_noop( $singular, $plural, $context, $domain ) at 654, with _ex,
	 * esc_attr_x and esc_html_x sharing _x's shape at 423, 441 and 459.
	 *
	 * The three `_..._noop` entries are here although this plugin uses none of
	 * them today. A list that only knew the functions already in use would
	 * silently ignore the first call of a new one, which is the moment a guard
	 * is worth the most.
	 *
	 * @return array<string, array<string, int|null>>
	 */
	function slosm_i18n_signatures(): array {
		return array(
			'__'         => array( 'text' => 0, 'plural' => null, 'context' => null, 'domain' => 1 ),
			'_e'         => array( 'text' => 0, 'plural' => null, 'context' => null, 'domain' => 1 ),
			'esc_html__' => array( 'text' => 0, 'plural' => null, 'context' => null, 'domain' => 1 ),
			'esc_html_e' => array( 'text' => 0, 'plural' => null, 'context' => null, 'domain' => 1 ),
			'esc_attr__' => array( 'text' => 0, 'plural' => null, 'context' => null, 'domain' => 1 ),
			'esc_attr_e' => array( 'text' => 0, 'plural' => null, 'context' => null, 'domain' => 1 ),
			'_x'         => array( 'text' => 0, 'plural' => null, 'context' => 1, 'domain' => 2 ),
			'_ex'        => array( 'text' => 0, 'plural' => null, 'context' => 1, 'domain' => 2 ),
			'esc_html_x' => array( 'text' => 0, 'plural' => null, 'context' => 1, 'domain' => 2 ),
			'esc_attr_x' => array( 'text' => 0, 'plural' => null, 'context' => 1, 'domain' => 2 ),
			'_n'         => array( 'text' => 0, 'plural' => 1, 'context' => null, 'domain' => 3 ),
			'_nx'        => array( 'text' => 0, 'plural' => 1, 'context' => 3, 'domain' => 4 ),
			'_n_noop'    => array( 'text' => 0, 'plural' => 1, 'context' => null, 'domain' => 2 ),
			'_nx_noop'   => array( 'text' => 0, 'plural' => 1, 'context' => 2, 'domain' => 3 ),
		);
	}

	/**
	 * Every gettext-family call in the plugin's own PHP.
	 *
	 * Three things the tokeniser is here for, each of which a regex gets wrong:
	 *
	 * - `$obj->__()` and `Class::_x()` are skipped, by looking at the token
	 *   before the name. This plugin has no such method today; the check costs
	 *   one comparison and stops the first one from being read as a gettext
	 *   call with nonsense arguments.
	 * - a `function __(` declaration is skipped the same way, which is what
	 *   keeps this file usable if the plugin ever ships a shim.
	 * - arguments are split at commas *at depth one*, so
	 *   `sprintf( __( 'a %s', 'domain' ), $x )` and
	 *   `__( 'a', 'domain' ) . implode( ',', $y )` both come apart correctly.
	 *
	 * Each record carries, per argument position, either the raw literal token
	 * text (quotes included) or null, where null means "this argument is not a
	 * single string literal" — a variable, a constant, a concatenation, a
	 * double-quoted string with a variable in it, or nothing at all.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	function slosm_i18n_calls(): array {
		static $calls = null;

		if ( null !== $calls ) {
			return $calls;
		}

		$signatures = slosm_i18n_signatures();
		$calls      = array();

		foreach ( slosm_i18n_files() as $file ) {
			$source = (string) file_get_contents( $file );
			$tokens = token_get_all( $source );
			$count  = count( $tokens );

			// Every comment in the file, by the line it ends on. A // comment
			// carries its own newline in the token text, so the trailing one is
			// trimmed before the lines in it are counted.
			$comments = array();

			foreach ( $tokens as $token ) {
				if ( ! is_array( $token ) || ! in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
					continue;
				}

				$end              = $token[2] + substr_count( rtrim( $token[1], "\r\n" ), "\n" );
				$comments[ $end ] = $token[1];
			}

			for ( $i = 0; $i < $count; $i++ ) {
				$token = $tokens[ $i ];

				if ( ! is_array( $token ) || T_STRING !== $token[0] || ! isset( $signatures[ $token[1] ] ) ) {
					continue;
				}

				$previous = slosm_i18n_previous_code( $tokens, $i );

				if ( is_array( $previous ) && in_array(
					$previous[0],
					array( T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW ),
					true
				) ) {
					continue;
				}

				$open = $i + 1;

				while ( $open < $count && is_array( $tokens[ $open ] ) && T_WHITESPACE === $tokens[ $open ][0] ) {
					$open++;
				}

				if ( $open >= $count || '(' !== $tokens[ $open ] ) {
					continue;
				}

				$arguments = slosm_i18n_arguments( $tokens, $open );
				$signature = $signatures[ $token[1] ];
				$record    = array(
					'file'     => $file,
					'line'     => $token[2],
					'function' => $token[1],
					'count'    => count( $arguments ),
					'comment'  => $comments[ $token[2] ] ?? ( $comments[ $token[2] - 1 ] ?? '' ),
				);

				foreach ( array( 'text', 'plural', 'context', 'domain' ) as $role ) {
					$position         = $signature[ $role ];
					$record[ $role ]  = null === $position ? false : slosm_i18n_literal( $arguments, $position );
					$record[ $role . '_raw' ] = null === $position ? '' : slosm_i18n_raw( $arguments, $position );
				}

				$calls[] = $record;
			}
		}

		return $calls;
	}

	/**
	 * The nearest token before $index that is not whitespace or a comment.
	 *
	 * @param array $tokens Output of token_get_all().
	 * @param int   $index  Index to walk back from.
	 * @return array|string|null
	 */
	function slosm_i18n_previous_code( array $tokens, int $index ) {
		for ( $i = $index - 1; $i >= 0; $i-- ) {
			if ( is_array( $tokens[ $i ] ) && in_array( $tokens[ $i ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}

			return $tokens[ $i ];
		}

		return null;
	}

	/**
	 * The top-level arguments of the call whose '(' is at $open.
	 *
	 * @param array $tokens Output of token_get_all().
	 * @param int   $open   Index of the opening parenthesis.
	 * @return array<int, array> One token list per argument.
	 */
	function slosm_i18n_arguments( array $tokens, int $open ): array {
		$count     = count( $tokens );
		$depth     = 0;
		$arguments = array();
		$current   = array();

		for ( $i = $open; $i < $count; $i++ ) {
			$token = $tokens[ $i ];

			if ( '(' === $token || '[' === $token || '{' === $token ) {
				$depth++;

				if ( 1 === $depth ) {
					continue;
				}
			} elseif ( ')' === $token || ']' === $token || '}' === $token ) {
				$depth--;

				if ( 0 === $depth ) {
					$arguments[] = $current;

					return $arguments;
				}
			} elseif ( ',' === $token && 1 === $depth ) {
				$arguments[] = $current;
				$current     = array();

				continue;
			}

			$current[] = $token;
		}

		// Unbalanced: the file would not parse, so this is unreachable on a
		// file PHP has already loaded. Returned rather than thrown so the
		// caller's own assertions are what report it.
		$arguments[] = $current;

		return $arguments;
	}

	/**
	 * Argument $position, if it is exactly one string literal.
	 *
	 * @param array $arguments Output of slosm_i18n_arguments().
	 * @param int   $position  Zero-based argument index.
	 * @return string|null The literal with its quotes, or null.
	 */
	function slosm_i18n_literal( array $arguments, int $position ): ?string {
		if ( ! isset( $arguments[ $position ] ) ) {
			return null;
		}

		$significant = array();

		foreach ( $arguments[ $position ] as $token ) {
			if ( is_array( $token ) && in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}

			$significant[] = $token;
		}

		if ( 1 !== count( $significant ) ) {
			return null;
		}

		if ( ! is_array( $significant[0] ) || T_CONSTANT_ENCAPSED_STRING !== $significant[0][0] ) {
			return null;
		}

		return $significant[0][1];
	}

	/**
	 * Argument $position as it is written, for a failure message.
	 *
	 * @param array $arguments Output of slosm_i18n_arguments().
	 * @param int   $position  Zero-based argument index.
	 * @return string
	 */
	function slosm_i18n_raw( array $arguments, int $position ): string {
		if ( ! isset( $arguments[ $position ] ) ) {
			return '(no such argument)';
		}

		$text = '';

		foreach ( $arguments[ $position ] as $token ) {
			$text .= is_array( $token ) ? $token[1] : $token;
		}

		return trim( preg_replace( '/\s+/', ' ', $text ) );
	}

	/**
	 * The value of a literal token, as gettext would key on it.
	 *
	 * Only the two escapes a single-quoted PHP string has. Double-quoted
	 * literals reach here too — `"Copy"` is a T_CONSTANT_ENCAPSED_STRING when
	 * nothing is interpolated into it — and this plugin has none, so the
	 * simplification is stated rather than hidden: a `"\n"` would come back as
	 * a backslash and an n. Nothing below compares a msgid with anything but
	 * another msgid read the same way, so that is a wrong *display*, never a
	 * wrong verdict.
	 *
	 * @param string $literal A literal token, quotes included.
	 * @return string
	 */
	function slosm_i18n_value( string $literal ): string {
		$body = substr( $literal, 1, -1 );

		if ( "'" === $literal[0] ) {
			return str_replace( array( "\\'", '\\\\' ), array( "'", '\\' ), $body );
		}

		return str_replace( array( '\\"', '\\\\' ), array( '"', '\\' ), $body );
	}

	/**
	 * The printf conversions in a msgid, `%%` excluded.
	 *
	 * `%%` is an escaped percent and carries nothing, so it is removed before
	 * anything else is looked for. Every `%` in this plugin's msgids is a real
	 * conversion — checked, not assumed — but a prose `%` would otherwise be
	 * read as one, since `%` followed by a space and an `o` matches the flag
	 * and conversion syntax exactly.
	 *
	 * @param string $msgid The source string.
	 * @return string[] One entry per conversion, in the order they appear.
	 */
	function slosm_i18n_placeholders( string $msgid ): array {
		$msgid = str_replace( '%%', '', $msgid );

		preg_match_all( '/%(?:\d+\$)?[-+ 0#\']*[\d.]*[bcdeEfFgGosuxX]/', $msgid, $matches );

		return $matches[0];
	}
}

describe( 'the plugin header declares the text domain everything else uses', function () {

	it( 'has a Text Domain header at all', function () {
		// The control for every case below: they all compare against this
		// value, and an empty one would make "the domain matches the header"
		// a comparison of nothing with nothing.
		assert_true(
			'' !== slosm_i18n_declared_domain(),
			'store-locator-for-openstreetmap.php has no Text Domain header, so nothing below is checking anything'
		);
	} );

	it( 'declares a domain that is the plugin\'s own slug', function () {
		// What WordPress.org requires of a submission, and what lets a language
		// pack land in the right file. Both sides are read: the header, and the
		// name of the file the header is in.
		assert_same(
			basename( slosm_i18n_main_file(), '.php' ),
			slosm_i18n_declared_domain(),
			'the Text Domain header and the plugin file name have to agree for a language pack to load'
		);
	} );
} );

describe( 'every translatable string is extractable', function () {

	it( 'finds gettext calls to check', function () {
		// The control. Every case below iterates this list, so an empty one
		// would make all of them pass without looking at anything — which is
		// what a renamed directory or a broken glob produces.
		assert_true(
			count( slosm_i18n_calls() ) > 300,
			'read ' . count( slosm_i18n_calls() ) . ' gettext calls out of the plugin; the scan has stopped seeing the source'
		);
	} );

	it( 'passes a literal text domain, and it is the declared one', function () {
		$domain  = slosm_i18n_declared_domain();
		$wrong   = array();

		foreach ( slosm_i18n_calls() as $call ) {
			if ( null !== $call['domain'] && slosm_i18n_value( $call['domain'] ) === $domain ) {
				continue;
			}

			$wrong[] = slosm_i18n_short( $call['file'] ) . ':' . $call['line']
				. ' ' . $call['function'] . '() was passed ' . $call['domain_raw'];
		}

		assert_same(
			array(),
			$wrong,
			"a text domain that is not the literal '" . $domain . "' is unextractable, so the string ships untranslatable:\n        "
				. implode( "\n        ", $wrong )
		);
	} );

	it( 'passes msgids, plurals and contexts as single string literals', function () {
		$built = array();

		foreach ( slosm_i18n_calls() as $call ) {
			foreach ( array( 'text', 'plural', 'context' ) as $role ) {
				if ( false === $call[ $role ] || null !== $call[ $role ] ) {
					continue;
				}

				$built[] = slosm_i18n_short( $call['file'] ) . ':' . $call['line']
					. ' ' . $call['function'] . '() ' . $role . ' is ' . $call[ $role . '_raw' ];
			}
		}

		assert_same(
			array(),
			$built,
			"a msgid built by concatenation or interpolation is not in the .pot, whatever it says at run time:\n        "
				. implode( "\n        ", $built )
		);
	} );

	it( 'never leaves a context empty', function () {
		$empty = array();

		foreach ( slosm_i18n_calls() as $call ) {
			if ( false === $call['context'] || null === $call['context'] ) {
				continue;
			}

			if ( '' !== trim( slosm_i18n_value( $call['context'] ) ) ) {
				continue;
			}

			$empty[] = slosm_i18n_short( $call['file'] ) . ':' . $call['line'] . ' ' . $call['function'] . '()';
		}

		assert_same(
			array(),
			$empty,
			"an empty context splits the msgid in two and tells a translator nothing:\n        " . implode( "\n        ", $empty )
		);
	} );
} );

describe( 'every placeholder is explained to whoever translates it', function () {

	it( 'finds msgids with placeholders to check', function () {
		// The control for the case below, which passes trivially if nothing in
		// the plugin has a placeholder any more.
		$with = 0;

		foreach ( slosm_i18n_calls() as $call ) {
			if ( is_string( $call['text'] ) && array() !== slosm_i18n_placeholders( slosm_i18n_value( $call['text'] ) ) ) {
				$with++;
			}
		}

		assert_true( $with > 20, 'found only ' . $with . ' msgids with placeholders; the scan is not reading them' );
	} );

	it( 'puts a translators comment immediately above every one of them', function () {
		// Immediately above, or on the same line. Not "somewhere above": a
		// comment two lines up is a comment about something else as far as an
		// extractor is concerned, and every one of this plugin's existing
		// comments already sits on the line directly above the call — measured
		// across all of them, not assumed.
		$bare = array();

		foreach ( slosm_i18n_calls() as $call ) {
			if ( ! is_string( $call['text'] ) ) {
				continue;
			}

			$placeholders = slosm_i18n_placeholders( slosm_i18n_value( $call['text'] ) );

			if ( array() === $placeholders ) {
				continue;
			}

			if ( false !== stripos( $call['comment'], 'translators:' ) ) {
				continue;
			}

			$bare[] = slosm_i18n_short( $call['file'] ) . ':' . $call['line']
				. ' ' . implode( ' ', $placeholders ) . ' in ' . $call['text'];
		}

		assert_same(
			array(),
			$bare,
			"a placeholder with no translators comment on the line above is a translator guessing what it holds:\n        "
				. implode( "\n        ", $bare )
		);
	} );
} );

describe( 'plurals hold together', function () {

	it( 'finds plural calls to check', function () {
		$plurals = 0;

		foreach ( slosm_i18n_calls() as $call ) {
			if ( false !== $call['plural'] ) {
				$plurals++;
			}
		}

		assert_true( $plurals > 10, 'found only ' . $plurals . ' plural calls; the scan is not reading them' );
	} );

	it( 'gives the plural the same placeholders as the singular', function () {
		// Not cosmetic. Both forms are handed to the same sprintf() by the
		// caller, so a plural that dropped the %d is a missing argument in
		// every locale whose rule reaches it — and English never does, because
		// English reaches the plural only when the number is not one and the
		// number is what the placeholder holds. It would ship green.
		$mismatched = array();

		foreach ( slosm_i18n_calls() as $call ) {
			if ( ! is_string( $call['text'] ) || ! is_string( $call['plural'] ) ) {
				continue;
			}

			$single = slosm_i18n_placeholders( slosm_i18n_value( $call['text'] ) );
			$many   = slosm_i18n_placeholders( slosm_i18n_value( $call['plural'] ) );

			sort( $single );
			sort( $many );

			if ( $single === $many ) {
				continue;
			}

			$mismatched[] = slosm_i18n_short( $call['file'] ) . ':' . $call['line']
				. ' singular has [' . implode( ' ', $single ) . '], plural has [' . implode( ' ', $many ) . ']';
		}

		assert_same(
			array(),
			$mismatched,
			"the two forms go to one sprintf(), so they need the same placeholders:\n        " . implode( "\n        ", $mismatched )
		);
	} );
} );

describe( 'a disambiguation is finished or not started', function () {

	it( 'never translates one msgid with a context in one place and without one in another', function () {
		// Task 29c is why this case exists. 'Search' was a settings tab, the
		// locator needed it again as the label of a submit button, and one
		// msgid cannot be "Wyszukiwanie" over a section and "Szukaj" on a
		// button. That was closed with _x() on the button — and the tab was
		// left bare, which is the worse half of the two: a translator meeting
		// an uncontexted 'Search' in a plugin that has a search box reads it as
		// the button and writes the imperative over the section heading.
		//
		// So the rule is not "use _x()" and cannot be; it is that a msgid the
		// plugin has already decided is ambiguous must not also appear
		// undecided. Two *different* contexts on one msgid are fine and are
		// what disambiguation looks like; a context and a bare one are a job
		// half done.
		$contexted = array();
		$bare      = array();

		foreach ( slosm_i18n_calls() as $call ) {
			if ( ! is_string( $call['text'] ) ) {
				continue;
			}

			$msgid = slosm_i18n_value( $call['text'] );
			$where = slosm_i18n_short( $call['file'] ) . ':' . $call['line'];

			if ( is_string( $call['context'] ) ) {
				$contexted[ $msgid ][] = $where . " (context: " . slosm_i18n_value( $call['context'] ) . ')';

				continue;
			}

			$bare[ $msgid ][] = $where;
		}

		$half = array();

		foreach ( $contexted as $msgid => $sites ) {
			if ( ! isset( $bare[ $msgid ] ) ) {
				continue;
			}

			$half[] = "'" . $msgid . "' has a context at " . implode( ', ', $sites )
				. ' but not at ' . implode( ', ', $bare[ $msgid ] );
		}

		assert_same(
			array(),
			$half,
			"half a disambiguation reads as a finished one:\n        " . implode( "\n        ", $half )
		);
	} );

	it( 'has contexted msgids to compare against bare ones', function () {
		// The control for the case above, which is an absence assertion and
		// passes on its own if _x() is never used at all.
		$contexted = 0;

		foreach ( slosm_i18n_calls() as $call ) {
			if ( is_string( $call['context'] ) ) {
				$contexted++;
			}
		}

		assert_true( $contexted > 0, 'no call in the plugin passes a context, so the case above compares nothing' );
	} );
} );
