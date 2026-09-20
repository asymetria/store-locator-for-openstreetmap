/**
 * Store Locator for OpenStreetMap: the map under the coordinate fields.
 *
 * A classic script, like the front end and for the same reason: the floor is
 * WordPress 6.0, which has no script-modules API. Assets registers it with
 * `strategy => defer` behind slosm-leaflet — and, until Task 21, behind
 * slosm-locator as well — and enqueues it on one screen, the location edit
 * form, and on no other.
 *
 * WHAT IT IS FOR
 * ==============
 * Two text inputs are a workable way to set a coordinate and a terrible way to
 * check one: 52.2297 and 52.2997 look alike and are eight kilometres apart.
 * The map is the check. Dragging the pin writes the fields, typing in the
 * fields moves the pin, and a button fills both from the address.
 *
 * THE MAP IS BORN WITH NO SIZE, AND THAT IS THE NORMAL CASE
 * =========================================================
 * `Post_Type` registers this post type with `show_in_rest` and `editor`
 * support, so the **block editor is the default screen here** — and
 * wp-admin/edit-form-blocks.php line 382 prints every metabox inside
 * `<div id="metaboxes" class="hidden">`, which is `display: none`. Gutenberg's
 * MetaBoxesArea moves `.metabox-location-normal` into view from a `useEffect`,
 * which runs after React mounts, which is after DOMContentLoaded and therefore
 * long after this deferred script has already called `L.map()`.
 *
 * Leaflet reads the container once and caches it:
 *
 *     getSize:function(){return this._size&&!this._sizeChanged||(this._size=
 *     new p(this._container.clientWidth||0,this._container.clientHeight||0),
 *     this._sizeChanged=!1),this._size.clone()}
 *
 * `clientWidth` inside `display:none` is 0. So the map is built 0 by 0,
 * `_sizeChanged` is cleared, and the only thing in the vendored library that
 * would ever re-measure is `trackResize`'s window `resize` listener — there is
 * no ResizeObserver and no IntersectionObserver anywhere in it. Left alone,
 * the map is blank, or one tile in a corner, until the editor happens to
 * resize the browser. On the screen most people will use.
 *
 * The same root cause, from two other directions: a Location box that is
 * *collapsed* when the classic editor loads (a per-user persisted state), and
 * one switched off in Screen Options and switched back on.
 *
 * One mechanism covers all three, and it is watchVisibility(): an
 * IntersectionObserver on the map container, calling `invalidateSize()` every
 * time it becomes visible. Not a `postbox-toggled` listener, which was the
 * first idea and does not work — wp-admin/js/postbox.js fires that with
 * `$document.trigger()`, and a jQuery custom event is not a DOM event, so
 * `addEventListener` never sees it. The observer needs no jQuery, and it
 * answers "is this on screen" rather than "did somebody click the thing that
 * usually puts it on screen".
 *
 * What the suite proves about this, and what it does not
 * -----------------------------------------------------
 * It proves the mechanism: that an observer is created on the map node, that
 * an intersecting entry calls invalidateSize(), that a non-intersecting one
 * does not, that it fires on every transition rather than only the first, and
 * that a browser without IntersectionObserver still gets a working map. It
 * cannot prove the *pixel*. tests/js/harness.js has no layout, no
 * clientWidth and no viewport, so "the map ends up with a size" is a manual
 * check and is recorded as one for Task 26.
 *
 * WHERE THE TILE LAYER COMES FROM, AND THE DEPENDENCY TASK 21 RETIRED
 * ===================================================================
 * The OpenStreetMap tile usage policy and the ODbL both require the
 * attribution line to be on the map. A second copy of that line in this file
 * is a copy that can be edited, minified or "tidied" on its own, and the first
 * anybody would know is a licence complaint.
 *
 * Until Task 21 the answer was to read the tile url, its options and the zoom
 * range out of `window.SLOSM` — the locator's frozen namespace — which meant
 * declaring `slosm-locator` as a dependency of this handle. The cost was
 * measured rather than waved at: **150 KB of unminified locator.js**, loaded
 * and initAll()-ed on an edit screen, to read one frozen three-line constant.
 * This header said Task 21 was where that should be retired deliberately
 * rather than by accident, and it is.
 *
 * It is retired because the reason went away, not because the cost was
 * re-weighed. The tile url is a setting now, so the server has to send it to
 * this screen in any case, and `Admin::picker_config()` carries it in the same
 * data- attribute as everything else. The header guessed `slosmAdminL10n`
 * would be the carrier and was wrong about that: admin_strings() is a flat
 * table of sentences whose key list tests/js/admin-picker.test.js
 * cross-checks against the fallback table below, so an object in there would
 * break that check or have to be excused from it by name.
 *
 * The no-duplication argument survives intact. There is still exactly one
 * place the attribution is written, and it is now `Settings` rather than
 * `locator.js`: this file never composes a credit line, it is handed one.
 *
 * WHAT DID HAVE TO BE DUPLICATED, AND WHAT KEEPS IT HONEST
 * -------------------------------------------------------
 * `url()`. It was the second thing this file read out of the locator
 * namespace, and Shortcode's four finished routes depend on it being built by
 * the url parser rather than by concatenation. It is copied below, verbatim,
 * and tests/js/admin-picker.test.js asserts the two copies are byte for byte
 * the same — the arrangement Geo's duplication in locator.js already set as
 * the precedent for what a copy costs.
 *
 * A picker whose config carries no usable tile layer draws no map and says so;
 * see init(). That is deliberate rather than defensive: a map without its
 * attribution is a licence problem, and the coordinate fields work without it.
 *
 * WHAT IT DOES NOT DO
 * ===================
 * It does not save anything, decide anything or write any meta. Everything
 * here ends in the value of an input, and admin/class-admin.php decides what
 * that value means. In particular the *lock* is not set here and cannot be:
 * Admin::clean() derives it from what was submitted against what was stored.
 * What this file does is leave the two coordinate fields — and one hidden
 * field — saying the truth about where the pair came from.
 *
 * THE HIDDEN FIELD, AND WHY IT CARRIES A PAIR RATHER THAN A FLAG
 * =============================================================
 * `slosm_lookup` is how the save can tell a pin somebody placed from a pin the
 * geocoder found. It holds the exact pair the lookup button wrote, formatted
 * the way the save formats coordinates, and it is emptied by every drag and
 * every keystroke in either field.
 *
 * A flag would have been shorter and wrong. "A lookup happened on this screen"
 * stays true after the editor drags the pin two streets away, so a flag would
 * leave that hand-placed pin unlocked and the geocoder free to overwrite it on
 * the next save. The pair is checkable: the save compares what was posted with
 * what the lookup claims to have produced, and a pair that is not it is a pair
 * somebody moved.
 *
 * SERVER DATA NEVER MEETS A PARSER
 * ================================
 * `textContent` everywhere, `innerHTML` nowhere, exactly as in locator.js. The
 * strings here come from a .po file somebody may have edited, and the geocoder
 * answer comes off the network. Neither is markup.
 *
 * WHAT TASK 24 HAS TO DEAL WITH
 * =============================
 * Recorded here rather than fixed, because the accessibility pass is its own
 * task and half of these need decisions about the whole box rather than about
 * this file:
 *
 * - Leaflet gives the map container `tabIndex="0"` (`t.keyboard&&(i.tabIndex=
 *   "0",…)` in _initIcon, and the same for the map pane), so it is focusable
 *   and has no accessible name. It needs one.
 * - `say()` creates its `role="status"` node at the moment it first has
 *   something to announce. NVDA and JAWS commonly do not announce a live
 *   region that did not exist when the page settled; the node should be in the
 *   markup from the start, empty.
 * - The notice is not tied to the two inputs with `aria-describedby`, so
 *   somebody in the Latitude field never hears the sentence about the Latitude
 *   field.
 * - The lookup button is `disabled` while its request is in flight, and a
 *   browser blurs a control it disables — so a keyboard user loses focus to
 *   `<body>` on every lookup. `aria-disabled` plus the pending guard that is
 *   already here would keep the focus.
 * - Dragging the pin is pointer-only. There is no keyboard path to it and
 *   there does not need to be, because the two text inputs *are* the keyboard
 *   path and they move the pin; that is worth stating rather than assuming,
 *   because "the map is the check" is only true for people who can see it.
 *
 * NO REGULAR EXPRESSIONS
 * ======================
 * Deliberate, and not an aesthetic. tests/js/harness.js strips comments with a
 * five-state scanner that does not know a regex literal from a division, and
 * every "this file never writes markup" assertion runs on its output. A regex
 * literal in here would quietly make those assertions unreliable. The two
 * places one would be natural — reading a number and reading a comma decimal —
 * are hand-written scanners instead, and each mirrors a named PHP function.
 */

( function ( window ) {
	'use strict';

	var document = window.document;

	/**
	 * How long the coordinate fields wait after a keystroke before the map moves.
	 *
	 * 300 milliseconds, the same number the front end's suggestion field uses,
	 * and here it buys something different. Typing "52.2297" is six valid
	 * latitudes on the way to one: 5, 52, 52.2, and so on. Without the wait the
	 * map pans to the Gulf of Guinea, then to Poland, then twice more, on every
	 * coordinate anybody types. Nothing upstream is being protected — this
	 * costs no request — so the only question is when the map should move, and
	 * the answer is "once the field has stopped changing".
	 *
	 * @var {number}
	 */
	var DELAY = 300;

	/**
	 * The English of every string this screen says.
	 *
	 * A fallback, not a translation: on a working screen slosmAdminL10n is an
	 * inline script printed before this deferred one. It is not there when
	 * another plugin dequeues the handle, or when an optimiser moves inline
	 * scripts about, and English is a degradation where a message reading
	 * "undefined" is a broken screen.
	 *
	 * The keys are asserted against Assets::admin_strings() in
	 * tests/js/admin-picker.test.js, so neither list can grow alone.
	 *
	 * Latitude and Longitude are deliberately the same source strings
	 * Admin::label() uses. gettext keys on the source string, so the two call
	 * sites are one entry in the .po file and a translator sees them once.
	 */
	var FALLBACK_STRINGS = Object.freeze( {
		configError: 'This map could not start: its settings are missing or unreadable.',
		mapFailed: 'The map could not be started, so the coordinates have to be typed by hand.',
		markerTitle: 'Drag this pin to move the location',
		pairNeeded: 'A location needs both a latitude and a longitude, so the previous pair will be kept.',
		willLookUp: 'Both coordinates are empty, so the address will be looked up when this is saved.',
		comma: '%1$s “%2$s” will be read as %3$s. Use a dot for the decimal point.',
		notANumber: '%1$s “%2$s” is not a single number, so the previous value will be kept.',
		outOfRange: '%1$s “%2$s” is outside −%3$s to %3$s, so the previous value will be kept.',
		latitude: 'Latitude',
		longitude: 'Longitude',
		looking: 'Looking the address up…',
		lookupNoAddress: 'Fill in the address first, then look it up.',
		lookupDone: 'The address was found, so these coordinates will be looked up again if the address changes.',
		lookupNoMatch: 'No place matched that address.',
		lookupBusy: 'The address lookup is busy right now. Try again in a moment.',
		lookupFailed: 'That address could not be looked up right now.',
	} );

	/**
	 * The class names this file reads and writes.
	 *
	 * One table, so a rename in admin/class-admin.php is one line here and a
	 * failing cross-check case rather than four silent no-ops.
	 */
	var CLASSES = Object.freeze( {
		picker: 'slosm-metabox__picker',
		map: 'slosm-metabox__map',
		lookup: 'slosm-metabox__lookup',
		notice: 'slosm-metabox__notice',
	} );

	/**
	 * A route plus query parameters, built by the url parser rather than by
	 * string concatenation.
	 *
	 * A verbatim copy of locator.js's url(), and the duplication is deliberate:
	 * Task 21 retired this file's dependency on slosm-locator, which is where
	 * this function used to be read from. The file header has the trade.
	 * tests/js/admin-picker.test.js asserts the two copies are identical byte
	 * for byte, so one of them changing on its own is a failing case rather
	 * than a divergence nobody notices.
	 *
	 * This is the whole reason Shortcode ships four finished routes instead of
	 * one base to append to. rest_url() has two shapes: with a permalink
	 * structure it is https://site/wp-json/slosm/v1/stores, and with plain
	 * permalinks get_rest_url() returns
	 * https://site/index.php?rest_route=/slosm/v1/stores. Appending
	 * '?limit=500' to the second produces a url with two question marks, on
	 * every site that has never visited Settings > Permalinks. URLSearchParams
	 * appends correctly to either, and re-encodes the rest_route value to
	 * %2Fslosm%2Fv1%2Fstores — which WordPress decodes back on the way in,
	 * because rest_route is an ordinary registered query var read out of $_GET.
	 *
	 * Returns null rather than throwing for a route that will not parse, so
	 * readConfig() can use it as its own validity check.
	 *
	 * @param {*}      route  The route from the config.
	 * @param {object} params Query parameters; undefined and '' are skipped.
	 * @returns {string|null} The url, or null.
	 */
	function url( route, params ) {
		var built;

		if ( 'string' !== typeof route || '' === route ) {
			return null;
		}

		try {
			built = new URL( route, window.location && window.location.href );
		} catch ( error ) {
			return null;
		}

		Object.keys( params ).forEach( function ( key ) {
			var value = params[ key ];

			if ( undefined === value || null === value || '' === value ) {
				return;
			}

			built.searchParams.set( key, String( value ) );
		} );

		return built.toString();
	}

	/**
	 * The tile layer out of a config, or null when there is not one.
	 *
	 * Null rather than a built-in fallback, and that is the licence decision
	 * this file makes rather than a defensive habit. A fallback would be a
	 * second copy of the attribution line living in this file, which is exactly
	 * what the header says must not exist; and a map drawn with no attribution
	 * at all is a licence breach that looks like a working map. The coordinate
	 * fields do not need a map, so the honest answer to "the server sent no
	 * tile layer" is to say so and leave them to be typed.
	 *
	 * The url is checked for the three placeholders as well as for being a
	 * string. Settings::sanitise() refuses a url without them, so a url that
	 * arrives here without them did not come through the settings screen —
	 * and a tile layer pointed at a url with no placeholders draws one image as
	 * every tile, which reads as a broken map.
	 *
	 * @param {object} config The picker config.
	 * @returns {object|null} `{ url, options }` for L.tileLayer, or null.
	 */
	function tileFor( config ) {
		var tile = config && config.tile;
		var zoom;

		if ( ! tile || 'object' !== typeof tile || 'string' !== typeof tile.url ) {
			return null;
		}

		if (
			-1 === tile.url.indexOf( '{z}' ) ||
			-1 === tile.url.indexOf( '{x}' ) ||
			-1 === tile.url.indexOf( '{y}' )
		) {
			return null;
		}

		zoom = 'number' === typeof tile.maxZoom && window.isFinite( tile.maxZoom ) ? tile.maxZoom : 19;

		return {
			url: tile.url,
			options: {
				attribution: 'string' === typeof tile.attribution ? tile.attribution : '',
				maxZoom: zoom,
			},
		};
	}

	/**
	 * Pickers already brought to life, so a second initAll() is a no-op.
	 *
	 * A WeakSet rather than an attribute, for the reason locator.js gives: an
	 * attribute survives cloneNode, and a container cloned by anything would
	 * arrive carrying a claim that it was already alive.
	 *
	 * A container is added before its config is read, so a picker that failed
	 * to start stays failed rather than rewriting the same message.
	 */
	var started = new window.WeakSet();

	/**
	 * One interface string, translated if the server sent one.
	 *
	 * The type check is not ceremony: wp_localize_script() runs every value
	 * through a json encode, so a filter returning an array puts a non-string
	 * here and `node.textContent = {}` writes "[object Object]" onto the screen.
	 *
	 * @param {string} key Key from FALLBACK_STRINGS.
	 * @returns {string} The string to show.
	 */
	function text( key ) {
		var payload = window.slosmAdminL10n;

		if ( payload && 'string' === typeof payload[ key ] && '' !== payload[ key ] ) {
			return payload[ key ];
		}

		return FALLBACK_STRINGS[ key ];
	}

	/**
	 * A translated template with its positional placeholders filled in.
	 *
	 * `%1$s`, `%2$s`, `%3$s` — PHP's shape, because these strings are written
	 * for the same translators as the ones on the server, and because a
	 * translator has to be able to reorder them. split/join rather than a
	 * regular expression; see the file header for why there are none here.
	 *
	 * A placeholder used twice is filled twice, which is what the out-of-range
	 * sentence needs: "outside −90 to 90" is one number in two places.
	 *
	 * @param {string} template  Already-translated text.
	 * @param {Array}  positions Values, in order.
	 * @returns {string} The finished sentence.
	 */
	function format( template, positions ) {
		var out = String( template );
		var index;

		for ( index = 0; index < positions.length; index++ ) {
			out = out.split( '%' + ( index + 1 ) + '$s' ).join( String( positions[ index ] ) );
		}

		return out;
	}

	/**
	 * Whether every character of a string is an ASCII digit.
	 *
	 * Hand-written for the reason the file header gives, and ASCII on purpose:
	 * PHP's is_numeric() refuses Eastern Arabic digits too, so "٣" is not a
	 * latitude on either side.
	 *
	 * @param {string} value Candidate.
	 * @returns {boolean} True when it is one or more digits and nothing else.
	 */
	function digits( value ) {
		var index;
		var character;

		if ( '' === value ) {
			return false;
		}

		for ( index = 0; index < value.length; index++ ) {
			character = value.charAt( index );

			if ( character < '0' || character > '9' ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * PHP's is_numeric(), for a string that has already been trimmed.
	 *
	 * Not `Number( value )`, and the difference is not theoretical. JavaScript
	 * reads '0x1A' as 26 and '' as 0; PHP reads both as "not a number", and the
	 * save is the one that decides. A field this file called valid and the save
	 * then refused would move the pin to a place no save will ever keep.
	 *
	 * The grammar is PHP 8's, minus the leading and trailing whitespace the
	 * caller has already removed: an optional sign, digits with at most one
	 * decimal point and at least one digit, and an optional exponent.
	 *
	 * @param {string} value Trimmed candidate.
	 * @returns {boolean} Whether PHP would call it a number.
	 */
	function numeric( value ) {
		var index = 0;
		var seen = 0;
		var dot = false;
		var character;

		if ( '+' === value.charAt( 0 ) || '-' === value.charAt( 0 ) ) {
			index = 1;
		}

		for ( ; index < value.length; index++ ) {
			character = value.charAt( index );

			if ( character >= '0' && character <= '9' ) {
				seen++;
				continue;
			}

			if ( '.' === character && ! dot ) {
				dot = true;
				continue;
			}

			if ( ( 'e' === character || 'E' === character ) && 0 < seen ) {
				return exponent( value.slice( index + 1 ) );
			}

			return false;
		}

		return 0 < seen;
	}

	/**
	 * The part of a number after its `e`: an optional sign and some digits.
	 *
	 * @param {string} value Everything after the exponent marker.
	 * @returns {boolean} Whether it is a valid exponent.
	 */
	function exponent( value ) {
		var rest = value;

		if ( '+' === rest.charAt( 0 ) || '-' === rest.charAt( 0 ) ) {
			rest = rest.slice( 1 );
		}

		return digits( rest );
	}

	/**
	 * A comma decimal read as a dot, or null.
	 *
	 * The same narrow rule Admin::parse_coordinate() applies with
	 * `/^[+-]?[0-9]+,[0-9]+$/`: digits, one comma, digits, and nothing else. A
	 * comma cannot be a thousands separator in a number bounded by 180, so in
	 * this field it has exactly one reading — and the one other thing an editor
	 * pastes, '52.2297, 21.0122', does not match and is refused.
	 *
	 * @param {string} typed Trimmed text.
	 * @returns {string|null} The same value with a dot, or null.
	 */
	function commaDecimal( typed ) {
		var at = typed.indexOf( ',' );
		var head;
		var tail;

		// One comma is not tested for separately, and that is not an omission:
		// a second comma is inside `tail`, and a tail with a comma in it is not
		// all digits. A mutation removing an explicit "exactly one comma" check
		// survived every case in the suite, which is what a redundant condition
		// looks like from the outside.
		if ( -1 === at ) {
			return null;
		}

		head = typed.slice( 0, at );
		tail = typed.slice( at + 1 );

		if ( '+' === head.charAt( 0 ) || '-' === head.charAt( 0 ) ) {
			head = head.slice( 1 );
		}

		if ( ! digits( head ) || ! digits( tail ) ) {
			return null;
		}

		return typed.slice( 0, at ) + '.' + tail;
	}

	/**
	 * One coordinate, read the way the save reads it.
	 *
	 * The same five statuses Admin::parse_coordinate() returns, with the same
	 * meanings, because the whole point of reading it here is to say in advance
	 * what the save is going to do. 'empty' is not an error: it is how an
	 * editor asks for the address to be looked up again.
	 *
	 * @param {string} raw   What is in the field.
	 * @param {number} limit Largest absolute value that is on the earth.
	 * @returns {object} `{ value, status, typed }`.
	 */
	function coordinate( raw, limit ) {
		var typed = String( undefined === raw || null === raw ? '' : raw ).trim();
		var status = 'ok';
		var value = typed;
		var normalised;
		var number;

		if ( '' === typed ) {
			return { value: null, status: 'empty', typed: typed };
		}

		normalised = commaDecimal( typed );

		if ( null !== normalised ) {
			value = normalised;
			status = 'normalised';
		}

		if ( ! numeric( value ) ) {
			return { value: null, status: 'not_a_number', typed: typed };
		}

		number = Number( value );

		if ( ! window.isFinite( number ) || Math.abs( number ) > limit ) {
			return { value: null, status: 'out_of_range', typed: typed };
		}

		return { value: number, status: status, typed: typed };
	}

	/**
	 * A coordinate as the save will store it.
	 *
	 * Admin::coordinate_string() in JavaScript: fixed to the configured number
	 * of decimals, then the trailing zeros and any bare point come off. The
	 * decimals arrive in the config rather than being written here, so there is
	 * one number in the plugin and not two.
	 *
	 * That agreement is what makes a drag safe. Admin::same_coordinate()
	 * compares at this precision, so a pin dragged back where it started posts
	 * the identical string and the save sees no change — and therefore does not
	 * lock a location nobody meant to pin.
	 *
	 * toFixed() and sprintf( '%.7F' ) can differ on an exact tie at the eighth
	 * decimal, which a coordinate arriving as a binary float essentially never
	 * is; the divergence is recorded rather than papered over.
	 *
	 * @param {number} value    Coordinate.
	 * @param {number} decimals How many decimals to keep.
	 * @returns {string} The value as text.
	 */
	function fixed( value, decimals ) {
		var out = Number( value ).toFixed( Math.max( 0, decimals ) );
		var end = out.length;

		if ( -1 === out.indexOf( '.' ) ) {
			return out;
		}

		while ( 0 < end && '0' === out.charAt( end - 1 ) ) {
			end--;
		}

		if ( 0 < end && '.' === out.charAt( end - 1 ) ) {
			end--;
		}

		return out.slice( 0, end );
	}

	/**
	 * Address components joined into one query.
	 *
	 * Admin::address_query()'s three rules and no fourth: trim each component,
	 * skip the ones that are empty, join the rest with ', '. A component
	 * holding only spaces is a component somebody cleared, and ", , , , , " is
	 * a string an emptiness check calls an address and Nominatim calls nothing.
	 *
	 * @param {Array} values Component values, in the order the server joins them.
	 * @returns {string} The query.
	 */
	function query( values ) {
		var parts = [];

		Array.prototype.forEach.call( values, function ( value ) {
			var trimmed = String( undefined === value || null === value ? '' : value ).trim();

			if ( '' !== trimmed ) {
				parts.push( trimmed );
			}
		} );

		return parts.join( ', ' );
	}

	/**
	 * Says one thing inside the picker, replacing whatever was there.
	 *
	 * One node, reused, and removed again when there is nothing to say — so a
	 * refusal does not sit on screen beside a field that no longer holds one.
	 * `role="status"` because the sentence appears without anybody moving
	 * focus, and a screen reader would otherwise never mention it.
	 *
	 * textContent, never innerHTML. These strings come out of a .po file.
	 *
	 * @param {Element} container The picker element.
	 * @param {string}  sentence  Already-translated text, or '' to say nothing.
	 * @returns {void}
	 */
	function say( container, sentence ) {
		var node = container.querySelector( '.' + CLASSES.notice );

		if ( '' === sentence ) {
			if ( node ) {
				container.removeChild( node );
			}

			return;
		}

		if ( ! node ) {
			node = container.ownerDocument.createElement( 'p' );

			node.className = CLASSES.notice;
			node.setAttribute( 'role', 'status' );

			container.appendChild( node );
		}

		node.textContent = sentence;
	}

	/**
	 * Reports a picker that cannot come alive, and says so on the screen.
	 *
	 * @param {Element} container The picker element.
	 * @param {string}  key       Key from FALLBACK_STRINGS.
	 * @returns {null} Always null, so callers can `return fail( … )`.
	 */
	function fail( container, key ) {
		say( container, text( key ) );

		return null;
	}

	/**
	 * The config off one picker's data- attribute, or null.
	 *
	 * Every member is checked, because every one of them is used without a
	 * second thought afterwards: an id that is not a string resolves to no
	 * element, a limit that is not a number makes every comparison false, and a
	 * decimals of null makes toFixed() throw. A picker that cannot trust its
	 * config says so once rather than failing four ways later.
	 *
	 * @param {Element} container The picker element.
	 * @returns {object|null} The config, or null.
	 */
	function readConfig( container ) {
		var raw = container.getAttribute( 'data-slosm-picker' );
		var config;
		var ok = true;

		if ( 'string' !== typeof raw || '' === raw ) {
			return null;
		}

		try {
			config = JSON.parse( raw );
		} catch ( error ) {
			return null;
		}

		if ( ! config || 'object' !== typeof config || Array.isArray( config ) ) {
			return null;
		}

		[ 'geocode', 'nonce', 'lat', 'lng', 'lookup' ].forEach( function ( key ) {
			if ( 'string' !== typeof config[ key ] || '' === config[ key ] ) {
				ok = false;
			}
		} );

		[ 'zoom', 'decimals', 'latLimit', 'lngLimit' ].forEach( function ( key ) {
			if ( 'number' !== typeof config[ key ] || ! window.isFinite( config[ key ] ) ) {
				ok = false;
			}
		} );

		if ( ! Array.isArray( config.address ) ) {
			ok = false;
		}

		return ok ? config : null;
	}

	/**
	 * The pair the two fields currently hold, and what is wrong with it.
	 *
	 * @param {object} instance The picker.
	 * @returns {object} `{ lat, lng, place }` where place is null unless both are usable.
	 */
	function readPair( instance ) {
		var lat = coordinate( instance.lat.value, instance.config.latLimit );
		var lng = coordinate( instance.lng.value, instance.config.lngLimit );
		var place = null;

		if ( null !== lat.value && null !== lng.value ) {
			place = { lat: lat.value, lng: lng.value };
		}

		return { lat: lat, lng: lng, place: place };
	}

	/**
	 * What to say about one coordinate, or ''.
	 *
	 * The three sentences Admin::coordinate_message() produces, in the future
	 * tense, because here they describe what the save is about to do rather
	 * than what it did.
	 *
	 * @param {object} instance The picker.
	 * @param {string} field    'lat' or 'lng'.
	 * @param {object} parsed   What coordinate() made of it.
	 * @returns {string} The sentence, or ''.
	 */
	function coordinateMessage( instance, field, parsed ) {
		var label = 'lat' === field ? text( 'latitude' ) : text( 'longitude' );
		var limit = 'lat' === field ? instance.config.latLimit : instance.config.lngLimit;

		if ( 'normalised' === parsed.status ) {
			return format( text( 'comma' ), [ label, parsed.typed, fixed( parsed.value, instance.config.decimals ) ] );
		}

		if ( 'not_a_number' === parsed.status ) {
			return format( text( 'notANumber' ), [ label, parsed.typed ] );
		}

		if ( 'out_of_range' === parsed.status ) {
			return format( text( 'outOfRange' ), [ label, parsed.typed, fixed( limit, instance.config.decimals ) ] );
		}

		return '';
	}

	/**
	 * Writes a pair into the two fields, and tells the page it changed.
	 *
	 * The `change` event is the point of this function. Something else on an
	 * edit screen is usually listening — the classic editor's unsaved-changes
	 * watcher, for one — and a field written by script fires nothing on its
	 * own, so an editor who only dragged the pin could leave without being
	 * warned.
	 *
	 * `input` is deliberately not fired, and that is the one line here that
	 * would be expensive to get wrong: `input` is the event this file listens
	 * for, so firing it would make every drag re-run the typing path and fight
	 * the pin it had just moved. There is no flag guarding against that,
	 * because a guard for a path nothing can take is a claim no case can
	 * falsify; the case that fires `input` instead of `change` is what holds
	 * this, and it fails loudly.
	 *
	 * @param {object} instance The picker.
	 * @param {number} lat      Latitude.
	 * @param {number} lng      Longitude.
	 * @returns {void}
	 */
	function write( instance, lat, lng ) {
		instance.lat.value = fixed( lat, instance.config.decimals );
		instance.lng.value = fixed( lng, instance.config.decimals );

		[ instance.lat, instance.lng ].forEach( function ( element ) {
			element.dispatchEvent( new window.Event( 'change', { bubbles: true } ) );
		} );
	}

	/**
	 * Puts the pin at a place, building it the first time.
	 *
	 * The view is set again on every move, which resets a zoom the editor had
	 * changed. That is right for the two callers it has: typing a coordinate
	 * and looking an address up are both "the location is somewhere else now".
	 * A drag does not come through here at all — the pin is already where the
	 * pointer left it — so dragging never moves the map under the hand doing
	 * the dragging.
	 *
	 * @param {object} instance The picker.
	 * @param {object} point    `{ lat, lng }`.
	 * @returns {void}
	 */
	function place( instance, point ) {
		if ( instance.marker ) {
			instance.marker.setLatLng( [ point.lat, point.lng ] );
			instance.map.setView( [ point.lat, point.lng ], instance.config.zoom );

			return;
		}

		instance.marker = window.L.marker( [ point.lat, point.lng ], {
			draggable: true,
			// Leaflet's default is false, which on a pin dragged to the edge of
			// a 320-pixel map means the drag simply stops there.
			autoPan: true,
			title: text( 'markerTitle' ),
			// Leaflet's own default is the untranslated word "Marker".
			alt: text( 'markerTitle' ),
		} );

		instance.map.setView( [ point.lat, point.lng ], instance.config.zoom );
		instance.marker.addTo( instance.map );

		instance.marker.on( 'dragend', function () {
			var moved = instance.marker.getLatLng();

			if ( ! moved ) {
				return;
			}

			// A pin somebody moved is a pin somebody placed, whatever put it
			// there first. Clearing this is what lets the save lock it.
			instance.lookup.value = '';

			write( instance, moved.lat, moved.lng );
			say( instance.element, '' );
		} );
	}

	/**
	 * Tells the map to measure itself again whenever it becomes visible.
	 *
	 * The whole of the fix for a map built inside a hidden container; the file
	 * header has the trace and the three screens it covers. `invalidateSize()`
	 * with no argument is `{ animate: false, pan: true }` in the vendored
	 * Leaflet, which re-reads the container and keeps the centre where it was
	 * rather than sliding it.
	 *
	 * Every transition, not only the first: a Location box can be collapsed and
	 * expanded as often as anybody likes, and each expansion is a container
	 * that just went from no box to a box.
	 *
	 * A browser with no IntersectionObserver gets nothing here and a working
	 * map everywhere the container was visible to begin with — which is the
	 * classic editor with the box open, and is also what every browser got
	 * before this function existed. Safari 12.1 is the floor for the API and
	 * WordPress 6.0's own browser support is above it, so this is a guard
	 * against an environment rather than against a browser.
	 *
	 * @param {object} instance The picker.
	 * @returns {object|null} The observer, or null.
	 */
	function watchVisibility( instance ) {
		var observer;

		if ( 'function' !== typeof window.IntersectionObserver ) {
			return null;
		}

		observer = new window.IntersectionObserver( function ( entries ) {
			Array.prototype.forEach.call( entries, function ( entry ) {
				if ( entry.isIntersecting ) {
					instance.map.invalidateSize();
				}
			} );
		} );

		observer.observe( instance.node );

		return observer;
	}

	/**
	 * Takes the pin off the map.
	 *
	 * @param {object} instance The picker.
	 * @returns {void}
	 */
	function clear( instance ) {
		if ( ! instance.marker ) {
			return;
		}

		instance.map.removeLayer( instance.marker );

		instance.marker = null;
	}

	/**
	 * What the fields now say, applied to the map and to the editor.
	 *
	 * Called from the debounce and from nowhere else.
	 *
	 * @param {object} instance The picker.
	 * @returns {void}
	 */
	function apply( instance ) {
		var pair = readPair( instance );
		// Both, joined with a space, the way print_messages() joins what the
		// save has to say. Two bad fields are two things to fix, and reporting
		// the first alone means finding the second by fixing the first and
		// pressing a key.
		var message = [
			coordinateMessage( instance, 'lat', pair.lat ),
			coordinateMessage( instance, 'lng', pair.lng ),
		]
			.filter( function ( sentence ) {
				return '' !== sentence;
			} )
			.join( ' ' );

		if ( null !== pair.place ) {
			place( instance, pair.place );
			say( instance.element, message );

			return;
		}

		if ( 'empty' === pair.lat.status && 'empty' === pair.lng.status ) {
			// Both empty is not a mistake: it is how an editor asks for the
			// address to be looked up again, and Admin::locked() reads it as
			// clearing the lock.
			clear( instance );
			say( instance.element, text( 'willLookUp' ) );

			return;
		}

		if ( 'empty' === pair.lat.status || 'empty' === pair.lng.status ) {
			// Half a pair. The save puts both values back and says so; saying
			// it here means the person who pressed Backspace hears it while
			// they are still looking at the field.
			say( instance.element, message || text( 'pairNeeded' ) );

			return;
		}

		say( instance.element, message );
	}

	/**
	 * Which sentence a failed lookup gets.
	 *
	 * Three answers rather than one, because the three are the only things an
	 * editor can act on differently: nothing matched what you typed, the
	 * service is busy so try again, and it broke. Rest_Controller::ERROR_STATUS
	 * already draws these lines; 400 is a query that normalised to nothing,
	 * which is the same thing as no match said about the query instead of the
	 * answer.
	 *
	 * @param {number} status HTTP status, or undefined for a network failure.
	 * @returns {string} Key from FALLBACK_STRINGS.
	 */
	function lookupFailure( status ) {
		if ( 404 === status || 400 === status ) {
			return 'lookupNoMatch';
		}

		if ( 429 === status ) {
			return 'lookupBusy';
		}

		return 'lookupFailed';
	}

	/**
	 * A place from a geocoder answer, or null.
	 *
	 * Strings are refused rather than coerced, the way the front end refuses
	 * them: the route declares lat and lng as numbers, and a '52.2297' arriving
	 * as text is a bug on the server worth seeing. Out of range is refused for
	 * the reason Admin gives — Leaflet clamps 91 to 85.05 and draws a pin at
	 * the edge of the world rather than complaining.
	 *
	 * @param {object} instance The picker.
	 * @param {*}      body     The decoded response.
	 * @returns {object|null} `{ lat, lng }`, or null.
	 */
	function answered( instance, body ) {
		if ( ! body || 'object' !== typeof body ) {
			return null;
		}

		if ( 'number' !== typeof body.lat || 'number' !== typeof body.lng ) {
			return null;
		}

		if ( ! window.isFinite( body.lat ) || ! window.isFinite( body.lng ) ) {
			return null;
		}

		if ( Math.abs( body.lat ) > instance.config.latLimit || Math.abs( body.lng ) > instance.config.lngLimit ) {
			return null;
		}

		return { lat: body.lat, lng: body.lng };
	}

	/**
	 * Asks the site where the address is, and puts the pin there.
	 *
	 * Through this site's own /geocode route, never a service directly: the
	 * route is where the courtesy limiting, the caching and the User-Agent
	 * live, and a browser calling Nominatim from an admin screen would be this
	 * plugin's name on somebody else's rate limit.
	 *
	 * What the request carries is the joined address and nothing else. Not the
	 * post id, not the coordinates, not the other fields, and not the `country`
	 * parameter — that one becomes Nominatim's countrycodes, which takes ISO
	 * 3166-1 alpha-2 codes, and this form's country field holds whatever an
	 * editor typed. "Polska" as a country code restricts the search to a
	 * country that does not exist; in the query string it is simply part of the
	 * address, which is what it is. Admin::address_query() makes the same
	 * decision for the same reason.
	 *
	 * The REST nonce is sent because the screen is authenticated and a site
	 * that has closed its REST API to anonymous requests is an ordinary
	 * configuration, not a broken one. Without it, rest_cookie_check_errors()
	 * sets the current user to 0 for a cookie with no nonce
	 * (wp-includes/rest-api.php lines 1134-1138 of 6.9.1) and such a site
	 * answers 401 to a button the editor cannot fix.
	 *
	 * @param {object} instance The picker.
	 * @returns {void}
	 */
	function lookUp( instance ) {
		var address = query(
			instance.config.address.map( function ( id ) {
				var element = document.getElementById( id );

				return element ? element.value : '';
			} )
		);
		var target;

		if ( instance.pending ) {
			return;
		}

		if ( '' === address ) {
			say( instance.element, text( 'lookupNoAddress' ) );

			return;
		}

		target = url( instance.config.geocode, { q: address } );

		if ( null === target ) {
			say( instance.element, text( 'lookupFailed' ) );

			return;
		}

		instance.pending = true;

		if ( instance.button ) {
			instance.button.setAttribute( 'disabled', 'disabled' );
		}

		say( instance.element, text( 'looking' ) );

		window
			.fetch( target, {
				credentials: 'same-origin',
				headers: { 'X-WP-Nonce': instance.config.nonce },
			} )
			.then( function ( response ) {
				var error;

				if ( ! response.ok ) {
					error = new window.Error( 'GET /geocode answered ' + response.status );
					error.status = response.status;

					throw error;
				}

				return response.json();
			} )
			.then( function ( body ) {
				var found = answered( instance, body );

				if ( null === found ) {
					throw new window.Error( 'GET /geocode did not answer with a place' );
				}

				write( instance, found.lat, found.lng );

				// The pair this lookup produced, in the form the save will
				// receive it. Read back off the fields rather than formatted a
				// second time, so the two can never disagree.
				instance.lookup.value = instance.lat.value + ',' + instance.lng.value;

				place( instance, found );
				say( instance.element, text( 'lookupDone' ) );
			} )
			.catch( function ( error ) {
				say( instance.element, text( lookupFailure( error.status ) ) );

				if ( window.console && window.console.warn ) {
					window.console.warn( 'Store Locator: ' + error.message );
				}
			} )
			.then( function () {
				instance.pending = false;

				if ( instance.button ) {
					instance.button.removeAttribute( 'disabled' );
				}
			} );
	}

	/**
	 * Brings one picker to life.
	 *
	 * @param {Element} container The `.slosm-metabox__picker` element.
	 * @returns {object|null} The picker, or null.
	 */
	function init( container ) {
		var config;
		var instance;
		var pair;
		var tile;

		if ( started.has( container ) ) {
			return null;
		}

		started.add( container );

		config = readConfig( container );

		if ( null === config ) {
			return fail( container, 'configError' );
		}

		instance = {
			element: container,
			config: config,
			map: null,
			marker: null,
			lat: document.getElementById( config.lat ),
			lng: document.getElementById( config.lng ),
			lookup: document.getElementById( config.lookup ),
			button: container.querySelector( '.' + CLASSES.lookup ),
			node: container.querySelector( '.' + CLASSES.map ),
			observer: null,
			timer: null,
			pending: false,
		};

		// The button is the one piece that may be missing: a picker without it
		// still drags and still types. A picker without the hidden field would
		// look like it worked and would leave every lookup locking the
		// location it was supposed to unlock, so that one is fatal.
		if ( ! instance.node || ! instance.lat || ! instance.lng || ! instance.lookup ) {
			return fail( container, 'configError' );
		}

		// Leaflet, and a tile layer to draw with. window.SLOSM used to be the
		// second half of this condition, because the tile layer was read out of
		// it; Task 21 sends the tile layer in the config instead, so what is
		// checked here is the config. Both failures are the same message and
		// the same outcome: no map, and two fields to type into.
		tile = tileFor( config );

		if ( ! window.L || null === tile ) {
			return fail( container, 'mapFailed' );
		}

		instance.map = window.L.map( instance.node, {
			// Off, because this map sits in the middle of a long form. Leaflet's
			// default swallows the page scroll of anybody who happens to be over
			// it, and the zoom control is right there.
			scrollWheelZoom: false,
		} );

		pair = readPair( instance );

		// The view before any layer, so the attachment happens at a point this
		// file chose. A location with no usable pair is shown the world rather
		// than a country somebody would have had to pick.
		if ( null === pair.place ) {
			instance.map.fitWorld();
		} else {
			instance.map.setView( [ pair.place.lat, pair.place.lng ], config.zoom );
		}

		// tileFor() built a fresh object out of the config, so there is nothing
		// shared left to copy — which is the one thing that got simpler when the
		// frozen constant every other map on the site shares stopped being the
		// source. Leaflet is still handed an object it may write to freely.
		window.L.tileLayer( tile.url, tile.options ).addTo( instance.map );

		if ( null !== pair.place ) {
			place( instance, pair.place );
		}

		[ instance.lat, instance.lng ].forEach( function ( element ) {
			element.addEventListener( 'input', function () {
				// There is deliberately no "am I the one writing" flag here.
				// write() fires `change` and never `input`, so this handler
				// cannot re-enter itself, and a flag guarding a path nothing
				// can take is a claim no case can falsify — a mutation
				// removing one survived the whole suite, which is the
				// definition of dead code. The case that fires `input` instead
				// of `change` is what actually holds this, and it fails loudly.
				//
				// Typing in either field is a hand placing a pin, whatever a
				// lookup put there a moment ago.
				instance.lookup.value = '';

				window.clearTimeout( instance.timer );

				instance.timer = window.setTimeout( function () {
					apply( instance );
				}, DELAY );
			} );
		} );

		if ( instance.button ) {
			instance.button.addEventListener( 'click', function () {
				lookUp( instance );
			} );
		}

		// Last, because it needs the map, and because everything above it has
		// to have happened before the first entry can arrive.
		instance.observer = watchVisibility( instance );

		return instance;
	}

	/**
	 * Brings every picker under `root` to life, each on its own.
	 *
	 * There is one per screen today. The loop is still a loop, and still
	 * catches per container, for the reason locator.js gives: init() turns its
	 * own known failures into a message and cannot know every way Leaflet can
	 * refuse — 'Map container not found.' and 'Map container is already
	 * initialized.' both come out of Map._initContainer as throws.
	 *
	 * @param {Document|Element} root Where to look; the document by default.
	 * @returns {object[]} The pickers that came up.
	 */
	function initAll( root ) {
		var instances = [];

		Array.prototype.forEach.call(
			( root || document ).querySelectorAll( '.' + CLASSES.picker ),
			function ( container ) {
				var instance = null;

				try {
					instance = init( container );
				} catch ( error ) {
					fail( container, 'mapFailed' );

					if ( window.console && window.console.error ) {
						window.console.error( 'Store Locator: ' + error.message );
					}
				}

				if ( instance ) {
					instances.push( instance );
				}
			}
		);

		return instances;
	}

	window.SLOSM_ADMIN = Object.freeze( {
		DELAY: DELAY,
		STRINGS: FALLBACK_STRINGS,
		CLASSES: CLASSES,
		url: url,
		tileFor: tileFor,
		text: text,
		format: format,
		coordinate: coordinate,
		fixed: fixed,
		query: query,
		init: init,
		initAll: initAll,
	} );

	// The same two branches locator.js has, and for the same reason: a deferred
	// script runs after the parser has finished, so there is nothing to wait
	// for — and a listener added after DOMContentLoaded has already gone past
	// never runs at all.
	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			initAll();
		} );
	} else {
		initAll();
	}
} )( window );
