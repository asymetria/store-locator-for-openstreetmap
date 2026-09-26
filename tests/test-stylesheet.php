<?php
/**
 * Pins the stylesheet against the markup, in both directions.
 *
 * WHAT THIS FILE IS AND IS NOT
 * ============================
 * It is not a test of how the locator looks. Nothing in this project can see a
 * rendered page, and a case that read `font: inherit` back out of
 * assets/css/locator.css and asserted it was there would be a case that cannot
 * fail on any defect a visitor could have — the file restated in terms of
 * itself. Everything that only a browser can settle is on Task 26's manual
 * list instead.
 *
 * What is testable, and was invisible until this file existed, is the
 * *relationship* between three files that are edited separately and have no
 * other tie:
 *
 * - `assets/css/locator.css` styles class names,
 * - `includes/class-shortcode.php` renders half of them,
 * - `assets/js/locator.js` creates the other half at run time.
 *
 * Two things go wrong there and neither is visible in a diff. A rule whose
 * class nothing emits any more is dead weight that reads as working code — the
 * next person to restyle the locator edits it and nothing happens. And an
 * element that nothing styles is what Task 29 shipped: `.slosm__submit` was
 * added to the markup, the stylesheet said nothing about it, and the button
 * rendered as stray text for a day. That is the case below, written so it
 * would have failed the moment the button landed.
 *
 * HOW THE TWO SIDES ARE COLLECTED
 * ===============================
 * The markup side is *rendered*, not scanned: Shortcode::render() is called
 * four times — the default, the two list positions, and preload off — and the
 * class attributes are read out of the html. A scan would have to understand
 * that `'slosm__' . $name` becomes three classes and that
 * `' slosm--list-' . $config['position']` becomes two more; rendering knows.
 *
 * The front end's half cannot be rendered by PHP, so it is scanned, but only
 * at the three places that actually put a class on a node — `className =`,
 * `className:` and `classList.add(` — never at a mention in a comment or a
 * `querySelector` argument. A comment can name anything; a comment is not
 * markup. `'slosm__popup-' + name` is recorded as a prefix rather than as a
 * class, which is what lets `.slosm__popup-hours` count as emitted without
 * this file keeping a copy of popupLine()'s call sites.
 *
 * WHAT THE SECOND CASE CANNOT SEE, SAID BEFORE IT IS TRUSTED
 * ==========================================================
 * A prefix works in one direction only. `.slosm__popup-hours` can be shown to
 * be emitted, because the class is in hand and the prefix matches it; but the
 * set of names the prefix produces is a list of arguments spread over
 * popup(), and nothing here enumerates it. So the "emitted but unstyled" case
 * covers the server's markup and the front end's *literal* class names, and a
 * new `slosm__popup-something` that nobody styles will not be caught by it.
 * It would be caught by the first case only if it were styled and never
 * built. The honest boundary is written here rather than discovered later.
 *
 * @package Store_Locator_For_OpenStreetMap
 */

require_once dirname( __DIR__ ) . '/includes/class-geo.php';
require_once dirname( __DIR__ ) . '/includes/class-store.php';
require_once dirname( __DIR__ ) . '/includes/class-post-type.php';
require_once dirname( __DIR__ ) . '/includes/class-store-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-geocoder.php';
require_once dirname( __DIR__ ) . '/includes/class-rest-controller.php';
require_once dirname( __DIR__ ) . '/includes/class-assets.php';
require_once dirname( __DIR__ ) . '/includes/class-shortcode.php';
require_once dirname( __DIR__ ) . '/includes/class-settings.php';

use Asymetria\StoreLocator\Post_Type;
use Asymetria\StoreLocator\Settings;
use Asymetria\StoreLocator\Shortcode;

if ( ! function_exists( 'slosm_css_source' ) ) {
	/**
	 * One of the plugin's own files, as text.
	 *
	 * @param string ...$parts Path parts below the plugin root.
	 * @return string
	 */
	function slosm_css_source( string ...$parts ): string {
		$path = dirname( __DIR__ ) . '/' . implode( '/', $parts );
		$text = file_get_contents( $path );

		if ( false === $text ) {
			throw new RuntimeException( 'could not read ' . $path );
		}

		return $text;
	}
}

if ( ! function_exists( 'slosm_css_styled_classes' ) ) {
	/**
	 * Every class name the locator stylesheet targets.
	 *
	 * Comments are removed first, and that is not tidiness: this stylesheet
	 * argues with itself in prose and names `.slosm__map`, `.slosm--list-left`
	 * and `.leaflet-container img` inside comments. A scan that counted those
	 * would report classes as styled that no rule reaches, which is the exact
	 * failure this file exists to catch.
	 *
	 * Both layers, as one list. The two files are a loading decision rather
	 * than two stylesheets with different jobs — a class styled in either is a
	 * class somebody has looked at — and the parity this function feeds is
	 * about whether anybody looked. Which of the two a rule belongs in is a
	 * separate question with a case of its own further down.
	 *
	 * @return array Sorted, unique class names.
	 */
	function slosm_css_styled_classes(): array {
		$classes = array_merge(
			slosm_css_classes_in( slosm_css_source( 'assets', 'css', 'locator.css' ) ),
			slosm_css_classes_in( slosm_css_source( 'assets', 'css', 'locator-skin.css' ) )
		);

		$classes = array_values( array_unique( $classes ) );
		sort( $classes );

		return $classes;
	}
}

if ( ! function_exists( 'slosm_css_classes_in' ) ) {
	/**
	 * The class names a stylesheet's selectors target.
	 *
	 * Text in, list out, so that the scanner has a case of its own a few
	 * hundred lines down. A scanner only ever run against the file it was
	 * written for is a scanner whose bugs and that file's contents are the
	 * same fact.
	 *
	 * @param string $css Stylesheet text.
	 * @return array Sorted, unique class names.
	 */
	function slosm_css_classes_in( string $css ): array {
		// Non-greedy, and `s` so a comment can span lines. Every comment in
		// this stylesheet is a /* */ one; css has no line comment.
		$css = (string) preg_replace( '#/\*.*?\*/#s', ' ', $css );

		// Declarations are dropped before class names are looked for, so that
		// a custom property named after a class — or a `content: '.slosm'` —
		// cannot be mistaken for a selector.
		$css = (string) preg_replace( '#\{[^{}]*\}#s', ' ', $css );

		preg_match_all( '/\.(slosm[A-Za-z0-9_-]*)/', $css, $matches );

		$classes = array_values( array_unique( $matches[1] ) );
		sort( $classes );

		return $classes;
	}
}

if ( ! function_exists( 'slosm_css_rendered_classes' ) ) {
	/**
	 * Every class the server really writes, taken out of rendered markup.
	 *
	 * Four renders, because three classes exist only in a configuration:
	 * `slosm--list-left` and `slosm--list-below` answer the Results setting,
	 * and nothing else here changes with it.
	 *
	 * @return array Sorted, unique class names.
	 */
	function slosm_css_rendered_classes(): array {
		$html = '';

		foreach ( array( 'right', 'left', 'below' ) as $position ) {
			$GLOBALS['slosm_stub']['options'][ Settings::OPTION ] = array( 'results_position' => $position );
			$GLOBALS['slosm_stub']['post_counts'][ Post_Type::POST_TYPE ] = array( 'publish' => 3 );

			$html .= ( new Shortcode() )->render( '' );
		}

		// And once with the locate button off, which is the one control an
		// attribute can remove: a class that only appears when it is *absent*
		// would otherwise be invisible here.
		$html .= ( new Shortcode() )->render( array( 'near_me' => 'no' ) );

		return slosm_css_classes_of_markup( $html );
	}
}

if ( ! function_exists( 'slosm_css_classes_of_markup' ) ) {
	/**
	 * This plugin's class names, out of a class attribute.
	 *
	 * @param string $html Rendered markup.
	 * @return array Sorted, unique class names.
	 */
	function slosm_css_classes_of_markup( string $html ): array {
		preg_match_all( '/class="([^"]*)"/', $html, $matches );

		$classes = array();

		foreach ( $matches[1] as $attribute ) {
			foreach ( preg_split( '/\s+/', trim( $attribute ) ) as $class ) {
				if ( '' !== $class && 0 === strpos( $class, 'slosm' ) ) {
					$classes[ $class ] = true;
				}
			}
		}

		$classes = array_keys( $classes );
		sort( $classes );

		return $classes;
	}
}

if ( ! function_exists( 'slosm_css_scripted_classes' ) ) {
	/**
	 * Every class assets/css/locator.js puts on a node, and every prefix it
	 * builds one from.
	 *
	 * @return array Two sorted lists: 'classes' and 'prefixes'.
	 */
	function slosm_css_scripted_classes(): array {
		return slosm_css_classes_in_script( slosm_css_source( 'assets', 'js', 'locator.js' ) );
	}
}

if ( ! function_exists( 'slosm_css_classes_in_script' ) ) {
	/**
	 * The class names a script puts on a node, and the prefixes it builds some
	 * from.
	 *
	 * @param string $js Script text.
	 * @return array Two sorted lists: 'classes' and 'prefixes'.
	 */
	function slosm_css_classes_in_script( string $js ): array {
		/*
		 * Comments first, and only two shapes of them: a block comment, and a
		 * line whose first characters are `//` — which is what a
		 * commented-out assignment looks like in this codebase and in most.
		 * A `//` comment *after* code on the same line is deliberately left
		 * alone, because stripping it means deciding whether the `//` is
		 * inside a string, and `'https://…'` appears in locator.js a dozen
		 * times. The cost of that choice is bounded and is in the safe
		 * direction: an unstripped trailing comment can only add a name to
		 * the set of classes the front end is believed to set, which makes
		 * the checks below more permissive, never falsely red.
		 */
		$js = (string) preg_replace( '#/\*.*?\*/#s', ' ', $js );
		$js = (string) preg_replace( '/^[ \t]*\/\/.*$/m', ' ', $js );

		$classes  = array();
		$prefixes = array();

		// `node.className = 'a b'`, `className: 'a b'` in an options object,
		// and `classList.add( 'a' )`. A trailing + means the literal is a
		// prefix and the rest of the name is computed.
		$patterns = array(
			'/className\s*[:=]\s*\'(slosm[^\']*)\'(\s*\+)?/',
			'/classList\.add\(\s*\'(slosm[^\']*)\'/',
		);

		foreach ( $patterns as $pattern ) {
			preg_match_all( $pattern, $js, $matches, PREG_SET_ORDER );

			foreach ( $matches as $match ) {
				if ( isset( $match[2] ) && '' !== trim( $match[2] ) ) {
					$prefixes[ $match[1] ] = true;

					continue;
				}

				foreach ( preg_split( '/\s+/', trim( $match[1] ) ) as $class ) {
					if ( '' !== $class ) {
						$classes[ $class ] = true;
					}
				}
			}
		}

		$classes  = array_keys( $classes );
		$prefixes = array_keys( $prefixes );

		sort( $classes );
		sort( $prefixes );

		return array(
			'classes'  => $classes,
			'prefixes' => $prefixes,
		);
	}
}

describe(
	'the scanners the two cases below stand on',
	function () {

		it(
			'reads selectors and class assignments, not prose and not lookups',
			function () {
				/*
				 * Synthetic input, and that is the point: run only against the
				 * three real files, a scanner's mistakes and those files'
				 * contents are one fact. Every line here is something one of
				 * those files could contain tomorrow — the stylesheet argues
				 * with itself in comments, and locator.js looks elements up by
				 * the same class names it sets.
				 */
				$css = "/* .slosm__ghost is discussed here and styled nowhere */\n"
					. ".slosm__real,\n.slosm--mod .slosm__inner {\n\t--slosm-tuning: 1px;\n\tcontent: '.slosm__quoted';\n}\n"
					. ".slosm__state[aria-disabled='true'] { opacity: 0.6 }\n";

				assert_same(
					array( 'slosm--mod', 'slosm__inner', 'slosm__real', 'slosm__state' ),
					slosm_css_classes_in( $css ),
					'the stylesheet scanner counted prose, a declaration or a custom property as a selector'
				);

				$js = "// node.className = 'slosm__commented';\n"
					. "var box = el.querySelector( '.slosm__looked-up' );\n"
					. "node.className = 'slosm__set';\n"
					. "icon.className = 'slosm__two slosm__three';\n"
					. "row.classList.add( 'slosm__added' );\n"
					. "line.className = 'slosm__built-' + key;\n"
					. "var options = { className: 'slosm__option' };\n";

				$found = slosm_css_classes_in_script( $js );

				assert_same(
					array( 'slosm__added', 'slosm__option', 'slosm__set', 'slosm__three', 'slosm__two' ),
					$found['classes'],
					'the script scanner counted a lookup or a comment as a class something is given'
				);
				assert_same( array( 'slosm__built-' ), $found['prefixes'], 'the composed class name was not read as a prefix' );

				// A comment is the one of those that is genuinely ambiguous —
				// it is a line that *was* code — so it is worth saying out
				// loud that neither of these is in the answer above.
				assert_false( in_array( 'slosm__commented', $found['classes'], true ) );
				assert_false( in_array( 'slosm__looked-up', $found['classes'], true ) );

				assert_same(
					array( 'slosm', 'slosm--list-left', 'slosm__thing' ),
					slosm_css_classes_of_markup( '<div class="slosm slosm--list-left"><span class="theme-box slosm__thing"></span></div>' ),
					'the markup scanner lost a class, or took one that is not this plugin\'s'
				);
			}
		);
	}
);

describe(
	'the stylesheet and the markup describe the same locator',
	function () {

		it(
			'styles nothing the plugin does not emit',
			function () {
				$styled   = slosm_css_styled_classes();
				$rendered = slosm_css_rendered_classes();
				$script   = slosm_css_scripted_classes();

				// The control, first: an extraction that found nothing would
				// make every assertion below vacuous, and "no dead rules" is
				// exactly what an empty list says.
				assert_true( count( $styled ) > 20, 'no class selectors were found in the stylesheet at all' );
				assert_true( count( $rendered ) > 20, 'the shortcode rendered no classes at all' );
				assert_true( count( $script['classes'] ) > 5, 'no class assignments were found in locator.js' );
				assert_true( in_array( 'slosm__popup-', $script['prefixes'], true ), 'the composed popup class name is gone from locator.js' );

				$dead = array();

				foreach ( $styled as $class ) {
					if ( in_array( $class, $rendered, true ) || in_array( $class, $script['classes'], true ) ) {
						continue;
					}

					foreach ( $script['prefixes'] as $prefix ) {
						if ( 0 === strpos( $class, $prefix ) && $class !== $prefix ) {
							continue 2;
						}
					}

					$dead[] = $class;
				}

				assert_same(
					array(),
					$dead,
					'assets/css/locator.css styles classes nothing emits: ' . implode( ', ', $dead )
				);
			}
		);

		it(
			'leaves nothing it emits unstyled without a reason written down',
			function () {
				$styled = slosm_css_styled_classes();
				$script = slosm_css_scripted_classes();

				/*
				 * Emitted on purpose and deliberately not styled. Each entry is
				 * a decision, and the value is the reason it was made; a class
				 * that is not here and not in the stylesheet is an element
				 * nobody looked at, which is what happened to `.slosm__submit`
				 * between Task 29 and Task 30.
				 *
				 * A reason short enough to fit on one line is the *index* of
				 * the decision, not the whole of it. Three of these are argued
				 * at length in assets/css/locator.css, beside the rules they
				 * are about, because the person the reason is for is the one
				 * restyling the locator and that person does not open a PHP
				 * test. This array is what keeps the decision from being
				 * forgotten; the stylesheet is where it is explained.
				 */
				$unstyled = array(
					'slosm__row'              => 'a <template>. No user agent paints one, and Shortcode::row_template() exists to be cloned.',
					'slosm__field--radius'    => 'a per-field hook for a site. The four fields share one rule; the modifier exists so one of them can be picked out.',
					'slosm__field--limit'     => 'the same hook.',
					'slosm__field--category'  => 'the same hook.',
					'slosm__search'           => 'styled as `.slosm__field input`, structurally, so a fifth field added later inherits the rhythm instead of needing a rule of its own.',
					'slosm__radius'           => 'styled as `.slosm__field select`.',
					'slosm__limit'            => 'styled as `.slosm__field select`.',
					'slosm__result-directions'=> 'the directions link in a result row, left as the theme\'s link. `.slosm__popup-directions` is not the same link styled differently: its `display: inline-block` exists so that the popup\'s own `> *` margin reaches an inline child, and a result row has no such rhythm rule for one to unlock. Argued above that rule in assets/css/locator.css.',
					'slosm__result-category'  => 'one tag inside `.slosm__result-categories`, which is the rule that lays them out.',
					'slosm__popup-category'   => 'the same, inside `.slosm__popup-categories`.',
					'slosm__popup-url'        => 'a link in a popup, left as the theme\'s link.',
					'slosm__marker'           => 'the base class on a Leaflet divIcon. Only the states are styled; the icon itself is Leaflet\'s box.',

					/*
					 * The two state classes the front end sets on the
					 * container, found by this case rather than by reading
					 * anything: `.slosm--error` beside them has a rule and
					 * these two do not. That is on purpose — a drawn-empty
					 * list and a started map are states a site may want to
					 * react to, and neither is a defect this stylesheet should
					 * be painting over. They are hooks, and the argument for
					 * leaving them as hooks is beside `.slosm--error` in
					 * assets/css/locator.css. Both are also pinned as
					 * behaviour in tests/js — a hook nothing sets, or one that
					 * latches on and never comes off, is not a hook.
					 */
					'slosm--empty'            => 'added by show() when a draw ends with no entries and the locator is in a position to say so, and removed on the next draw that has some. Not only a search: a category matching nothing does it with nothing searched. Deliberately not set while a preloaded payload is still on its way, or a site hanging its own "nothing here" on this hook would say it before the data arrived. say() has already put the words in `.slosm__message`, so this class is what a site does beyond the sentence.',
					'slosm--ready'            => 'added by init() once the map has a view and its layers, and never removed. Deliberately not used here to hide the locator until then: that rule hides every page the script never ran on, which is the case `.slosm--error` exists to keep visible.',
				);

				$emitted = array_merge( slosm_css_rendered_classes(), $script['classes'] );

				$orphans = array();

				foreach ( $emitted as $class ) {
					if ( in_array( $class, $styled, true ) || isset( $unstyled[ $class ] ) ) {
						continue;
					}

					$orphans[] = $class;
				}

				assert_same(
					array(),
					$orphans,
					'the markup emits classes the stylesheet says nothing about, and no reason is recorded: ' . implode( ', ', $orphans )
				);

				// And the list itself cannot rot unnoticed: an entry for a
				// class nobody emits any more is a reason for a decision that
				// is no longer being made.
				$stale = array();

				foreach ( array_keys( $unstyled ) as $class ) {
					if ( ! in_array( $class, $emitted, true ) ) {
						$stale[] = $class;
					}
				}

				assert_same(
					array(),
					$stale,
					'the deliberately-unstyled list names classes nothing emits: ' . implode( ', ', $stale )
				);
			}
		);
	}
);

if ( ! function_exists( 'slosm_css_declared_properties' ) ) {
	/**
	 * The `--slosm-*` properties a stylesheet declares, and what it declares them as.
	 *
	 * Comments go first for the reason slosm_css_classes_in() strips them: both
	 * of these files argue with themselves in prose and name properties inside
	 * the argument.
	 *
	 * @param string $css Stylesheet text.
	 * @return array<string, string> Property name to declared value, trimmed.
	 */
	function slosm_css_declared_properties( string $css ): array {
		$css = (string) preg_replace( '#/\*.*?\*/#s', ' ', $css );

		// A colon directly after the name is what separates a declaration from
		// the use inside `var( --slosm-x, 1px )`, which carries a comma there.
		preg_match_all( '/(--slosm-[a-z0-9-]+)\s*:\s*([^;]+);/', $css, $matches, PREG_SET_ORDER );

		$declared = array();

		foreach ( $matches as $match ) {
			$declared[ $match[1] ] = trim( $match[2] );
		}

		return $declared;
	}
}

if ( ! function_exists( 'slosm_css_property_uses' ) ) {
	/**
	 * Every `var( --slosm-*, … )` a stylesheet reads, with its fallback or null.
	 *
	 * The boundary, written down rather than discovered: a fallback may contain
	 * one level of brackets of its own — `var( --x, rgba(0, 0, 0, 0.1) )`, which
	 * the layout layer has — and no more. A `var()` nested inside a fallback
	 * would not be read, and there is none; the alternative is a css parser,
	 * which is a great deal of machinery for a file that holds thirteen values.
	 *
	 * @param string $css Stylesheet text.
	 * @return array<int, array{name: string, fallback: ?string}>
	 */
	function slosm_css_property_uses( string $css ): array {
		$css = (string) preg_replace( '#/\*.*?\*/#s', ' ', $css );

		preg_match_all( '/var\(\s*(--slosm-[a-z0-9-]+)\s*(,((?:[^()]|\([^()]*\))*))?\)/', $css, $matches, PREG_SET_ORDER );

		$uses = array();

		foreach ( $matches as $match ) {
			$uses[] = array(
				'name'     => $match[1],
				'fallback' => isset( $match[2] ) && '' !== $match[2] ? trim( $match[3] ) : null,
			);
		}

		return $uses;
	}
}

if ( ! function_exists( 'slosm_css_rule_sets' ) ) {
	/**
	 * Whether a stylesheet has a rule for exactly this selector that sets this property.
	 *
	 * Three narrowings, and each of them was bought by a mutant that survived
	 * the version before it:
	 *
	 * - **Selector and declaration together**, because either alone answers a
	 *   different question. "Is the class mentioned" stays true when the rule
	 *   that does the work has moved out and a second rule naming the same
	 *   class stayed behind; "is the declaration in the file" finds it under
	 *   any selector at all.
	 * - **The whole selector rather than a class inside it.** `.slosm__map`
	 *   losing its `min-height` to the skin was not caught while
	 *   `.slosm--error .slosm__map { min-height: 0 }` was still here, because
	 *   that rule contains the class and the property both.
	 * - **A comma-separated part, compared entire.** Two of these rules are
	 *   written as a list of selectors, so a part is what there is to compare;
	 *   comparing the whole list would make the case depend on the order they
	 *   happen to be written in.
	 *
	 * Rules inside `@media` are found the same way as rules outside one: a
	 * selector here contains no brace, so the at-rule's own header never
	 * matches and the rules nested in it do.
	 *
	 * @param string $css      Stylesheet text.
	 * @param string $selector One selector, exactly as a rule spells it.
	 * @param string $property Property name, without the colon.
	 * @return bool
	 */
	function slosm_css_rule_sets( string $css, string $selector, string $property ): bool {
		$css = (string) preg_replace( '#/\*.*?\*/#s', ' ', $css );

		preg_match_all( '/([^{}]+)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER );

		foreach ( $rules as $rule ) {
			$parts = array_map(
				static function ( string $part ): string {
					return (string) preg_replace( '/\s+/', ' ', trim( $part ) );
				},
				explode( ',', $rule[1] )
			);

			if ( ! in_array( $selector, $parts, true ) ) {
				continue;
			}

			// Anchored at the start of a declaration, so that `min-height` is
			// not found inside `--slosm-map-min-height` and `background` is not
			// found inside `background-color`.
			if ( preg_match( '/(?:^|[;{])\s*' . preg_quote( $property, '/' ) . '\s*:/', $rule[2] ) ) {
				return true;
			}
		}

		return false;
	}
}

if ( ! function_exists( 'slosm_css_foreign_classes_in' ) ) {
	/**
	 * Every class a stylesheet reaches for that this plugin does not own.
	 *
	 * The hole this fills, said plainly: slosm_css_classes_in() matches
	 * `/\.(slosm[A-Za-z0-9_-]*)/`, so the case that asserts the stylesheet
	 * "styles nothing the plugin does not emit" has never been able to see a
	 * rule under somebody else's class. `.leaflet-popup-content { display:
	 * none }` would have passed it in silence.
	 *
	 * Text in, list out, for the reason the scanner beside it gives: a scanner
	 * only ever run against the file it was written for is a scanner whose
	 * bugs and that file's contents are the same fact. The case below feeds it
	 * css this repository does not contain.
	 *
	 * @param string $css Stylesheet text.
	 * @return array Sorted, unique class names, without their leading dot.
	 */
	function slosm_css_foreign_classes_in( string $css ): array {
		$css = (string) preg_replace( '#/\*.*?\*/#s', ' ', $css );
		$css = (string) preg_replace( '#\{[^{}]*\}#s', ' ', $css );

		preg_match_all( '/\.([A-Za-z_-][A-Za-z0-9_-]*)/', $css, $matches );

		$foreign = array();

		foreach ( $matches[1] as $class ) {
			if ( 0 === strpos( $class, 'slosm' ) ) {
				continue;
			}

			$foreign[ $class ] = true;
		}

		$foreign = array_keys( $foreign );
		sort( $foreign );

		return $foreign;
	}
}

describe(
	'the stylesheet is two layers, because the halves fail differently',
	function () {

		it(
			'keeps in the always-loaded layer everything a missing skin must not be able to delete',
			function () {
				$source = slosm_css_source( 'assets', 'css', 'locator.css' );

				/*
				 * The list is the split itself, written as assertions. Each
				 * entry is a declaration whose move into the switchable layer
				 * would let "I would rather write my own css" produce something
				 * a visitor reads as a broken plugin rather than an unstyled
				 * one. That is the whole distinction the two files exist to
				 * draw, and nothing else in this project can check it.
				 *
				 * Reading a declaration back out of a stylesheet is what this
				 * file's header refuses to do, and this is not that. The
				 * objection there is to a case that asserts a *look* — no
				 * defect a visitor could have makes `font: inherit` absent —
				 * whereas which of two files a declaration sits in has a
				 * failure anybody can see: the map is gone, or the markers are,
				 * or the popup pushes the page around as somebody types.
				 *
				 * The value's second half is why the declaration may not move,
				 * which is the part a reader cannot get from the file.
				 */
				$load_bearing = array(
					'.slosm__map'                       => array( 'min-height', 'the height floor. A Leaflet container of zero height renders as nothing at all, and that is the commonest "the map does not work" report in this category.' ),
					'.slosm--error .slosm__map'         => array( 'min-height', 'the box a locator that could not start collapses to, so that 240 pixels of grey do not sit above the message explaining why.' ),
					'.slosm--list-left'                 => array( 'grid-template-columns', 'the Results setting decides this class. A skin that owned it would be a settings control that does nothing on a site which switched the skin off.' ),
					'.slosm--list-below'                => array( 'display', 'the same setting, the other choice: the grid switched off so that the map and the list stack at every width.' ),
					'.slosm__message'                   => array( 'grid-column', 'the status line spans both columns. Without the span it is a grid item in a track, so it takes the cell the map wants and pushes the map and the list onto rows of their own.' ),
					'.slosm__message:empty'             => array( 'display', 'the status line is rendered on every locator whether or not it has anything to say, and an empty grid item holds a row and the grid gap open. Without this, every silent locator carries an em of nothing between the search row and the map.' ),
					'.slosm .slosm__results'            => array( 'overflow-y', 'the scroll that keeps a hundred results level with the map instead of running down the page past it.' ),
					'.slosm__result-open'               => array( 'background', 'a <button> that has to read as a row of text. Without the reset a result list is a column of native grey boxes, which is not the theme showing through.' ),
					'.slosm .slosm__suggestions'        => array( 'position', 'absolutely positioned, or the popup pushes the map down four times a second as somebody types.' ),
					'.slosm__suggestions[hidden]'       => array( 'display', 'one rule of our own at class specificity, because the hidden attribute is only a user-agent rule and any theme rule with a class in it reopens a closed popup.' ),
					'.slosm__suggestion--active'        => array( 'background', 'the only thing on screen that says where the arrow keys have got to.' ),
					'.slosm__result--active'            => array( 'background', 'the same state, in the results list.' ),
					'.slosm__marker--dot'               => array( 'background', 'Leaflet gives a divIcon a white box and a grey border of its own, so without this the dot is a dot inside somebody else\'s square.' ),
					'.slosm__dot'                       => array( 'width', 'dotIcon() builds an empty node and hands Leaflet a size; every pixel of this marker is drawn here, so a site that chose the dot would have no markers at all.' ),
					'.slosm__category[aria-disabled=\'true\']' => array( 'opacity', 'aria-disabled is the one state a browser paints nothing for, so without this a control that cannot be used looks exactly like one that can.' ),
					'.slosm__popup-address'             => array( 'white-space', 'pre-line. The address is one text node with newlines in it, and the default collapses an editor\'s three lines into one.' ),
					'.slosm .slosm__map'                => array( 'font-size', 'the floor under everything Leaflet renders inside the map, and the attribution is the reason it is not optional. leaflet.css sets `.leaflet-container { font-size: 12px; font-size: 0.75rem }`, and on the very common theme that puts `font-size: 62.5%` on the root that second declaration is 7.5px — measured on a live site. The ODbL asks for the OpenStreetMap credit to be legible, so a site that switches the skin off may not lose this.' ),
				);

				// The control: a scan that found nothing would pass every
				// assertion below without reading a rule.
				assert_true(
					count( slosm_css_classes_in( $source ) ) > 10,
					'no class selectors were found in the layout layer at all'
				);

				$moved = array();

				foreach ( $load_bearing as $selector => $entry ) {
					if ( ! slosm_css_rule_sets( $source, $selector, $entry[0] ) ) {
						$moved[] = $selector . ' { ' . $entry[0] . ' }';
					}
				}

				assert_same(
					array(),
					$moved,
					'assets/css/locator.css no longer carries what a site switching the skin off must keep: ' . implode( ', ', $moved )
				);
			}
		);

		it(
			'names no colour of its own in the switchable layer',
			function () {
				/*
				 * The rule the split rests on. The skin is the layer a dark
				 * theme keeps, so a hex in it is a plugin painting white text
				 * on white or dragging a foreign hue onto a light site —
				 * exactly the thing the file header has refused since the
				 * beginning, applied to the half that is now allowed to look
				 * finished. `currentColor` and `inherit` carry it instead.
				 *
				 * The layout layer is not held to this: the empty-tile grey,
				 * the suggestion popup's own background and the dot marker are
				 * colours that something would be unreadable or invisible
				 * without, and each is argued beside its rule.
				 */
				$skin = (string) preg_replace( '#/\*.*?\*/#s', ' ', slosm_css_source( 'assets', 'css', 'locator-skin.css' ) );

				preg_match_all( '/#[0-9A-Fa-f]{3,8}\b|\b(?:rgba?|hsla?|color-mix)\s*\(/', $skin, $matches );

				assert_same(
					array(),
					$matches[0],
					'the skin names a colour of its own: ' . implode( ', ', $matches[0] )
				);
			}
		);

		it(
			'reaches under no class it does not own, except the one written down',
			function () {
				/*
				 * The scanner first, against css this repository does not
				 * contain — because a scanner that has only ever run on the
				 * files it passes proves nothing about the files it would
				 * fail.
				 */
				assert_same(
					array( 'leaflet-popup-content', 'wp-block-button' ),
					slosm_css_foreign_classes_in(
						'.slosm__result { color: red } .wp-block-button { margin: 0 } ' .
						'.leaflet-popup-content { margin: 0 } /* .never-seen {} */ ' .
						'.slosm__popup { content: ".also-not-a-selector" }'
					),
					'the foreign-class scanner does not see what it was written to see'
				);

				/*
				 * And now the rule. locator.css's header says "Nothing at all
				 * under a .leaflet- class", and until this case that sentence
				 * was enforced by nobody: slosm_css_classes_in() only ever
				 * matches classes beginning `slosm`, so every one of the four
				 * hundred class names Leaflet ships was invisible to the case
				 * that checks for dead rules.
				 *
				 * The always-loaded layer owns nothing foreign, full stop. It
				 * is the half that must keep working when the other is gone,
				 * and a rule in it that depends on a class Leaflet might
				 * rename is a locator that breaks on a dependency upgrade.
				 *
				 * The skin owns exactly one, and its rule says why at length:
				 * Leaflet's pixel margins around the popup content, tightened
				 * to the type size the layout layer sets. It is in the
				 * switchable half precisely so that the failure mode — a
				 * Leaflet that renamed the class — is Leaflet's own spacing
				 * coming back, not a popup that stops working.
				 *
				 * Adding to this list is allowed. Adding to it silently is
				 * what this case is for.
				 */
				assert_same(
					array(),
					slosm_css_foreign_classes_in( slosm_css_source( 'assets', 'css', 'locator.css' ) ),
					'the always-loaded layer reaches under a class it does not own'
				);

				assert_same(
					array( 'leaflet-popup-content' ),
					slosm_css_foreign_classes_in( slosm_css_source( 'assets', 'css', 'locator-skin.css' ) ),
					'the skin reaches under a foreign class that is not the one written down'
				);
			}
		);

		it(
			'reads no tuning value without saying what the rule does when it is not there',
			function () {
				/*
				 * The hazard the split created, in one case. A layout rule
				 * reading a property the *skin* declares is a rule that changes
				 * the moment somebody switches the skin off — the one state
				 * this whole arrangement exists to keep safe — and the fallback
				 * is what makes it survive. A fallback that no longer matches
				 * the declaration beside it is the same rot from the other end:
				 * the file would then behave one way with the skin and another
				 * without it, silently.
				 */
				$files = array(
					'assets/css/locator.css'      => slosm_css_source( 'assets', 'css', 'locator.css' ),
					'assets/css/locator-skin.css' => slosm_css_source( 'assets', 'css', 'locator-skin.css' ),
				);

				$declared   = array();
				$everywhere = array();

				foreach ( $files as $path => $css ) {
					$declared[ $path ] = slosm_css_declared_properties( $css );
					$everywhere        = array_merge( $everywhere, $declared[ $path ] );
				}

				assert_true( count( $everywhere ) > 5, 'no --slosm-* properties were declared in either layer' );

				$faults = 0;
				$report = array();

				foreach ( $files as $path => $css ) {
					$uses = slosm_css_property_uses( $css );

					assert_true( count( $uses ) > 0, $path . ' declares a tuning surface and reads none of it' );

					foreach ( $uses as $use ) {
						if ( null === $use['fallback'] ) {
							++$faults;
							$report[] = $path . ' reads ' . $use['name'] . ' with no fallback';
							continue;
						}

						if ( ! isset( $everywhere[ $use['name'] ] ) ) {
							++$faults;
							$report[] = $path . ' reads ' . $use['name'] . ', which neither layer declares';
							continue;
						}

						if ( isset( $declared[ $path ][ $use['name'] ] ) && $declared[ $path ][ $use['name'] ] !== $use['fallback'] ) {
							++$faults;
							$report[] = $path . ' reads ' . $use['name'] . ' with the fallback "' . $use['fallback']
								. '" while declaring it "' . $declared[ $path ][ $use['name'] ] . '"';
						}
					}
				}

				assert_same( 0, $faults, implode( '; ', $report ) );
			}
		);
	}
);
