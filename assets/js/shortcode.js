/**
 * Store Locator for OpenStreetMap: the copy button on the shortcode screen.
 *
 * A classic script, like the other two and for the same reason: the floor is
 * WordPress 6.0, which has no script-modules API. Assets registers it with
 * `strategy => defer`, with no dependency of any kind, and enqueues it on one
 * screen — the shortcode generator — and on no other.
 *
 * THE BUTTON IS CREATED HERE AND NOT PRINTED BY PHP
 * ================================================
 * On purpose, and it is the whole degradation story. A `<button>Copy</button>`
 * in the markup is a promise the page cannot keep when the script is blocked,
 * fails to parse, or is stripped by an optimisation plugin: the editor clicks
 * it, nothing happens, and there is no way to tell that from a clipboard that
 * silently refused. What PHP prints is the `<textarea readonly>` holding the
 * shortcode, which can be selected and copied by hand on any browser ever
 * made. This file adds a shortcut to something that already works.
 *
 * THREE WAYS TO COPY, AND WHY THERE HAVE TO BE THREE
 * =================================================
 * `navigator.clipboard` is defined `[SecureContext]` by the Clipboard API, so
 * on `http://` it is not merely refused — the object is not there. That is not
 * an exotic case for WordPress: an intranet install on a local network, a site
 * reached by IP, a staging box with no certificate. Assuming it exists is how a
 * copy button becomes a button that does nothing on exactly the installs least
 * able to diagnose it.
 *
 * So, in order, and each one only when the one before it did not work:
 *
 * 1. `navigator.clipboard.writeText()`, when there is one. It returns a
 *    promise, and a rejected promise is an ordinary outcome rather than an
 *    error — a document that is not focused, a permission refused by policy —
 *    so the rejection falls through to 2 rather than being reported.
 * 2. `document.execCommand( 'copy' )` over the selected textarea. Deprecated,
 *    and still the only thing that works on `http://` in every current
 *    browser. It returns a boolean and the boolean is believed: `false` falls
 *    through to 3.
 * 3. Telling the person. The text has been *selected* before any of this
 *    started, so what is left is one keystroke and a sentence saying so. This
 *    is the branch a browser with neither API lands in, and it is also the
 *    only branch that is guaranteed to work.
 *
 * The selection happens first, for every path, and that is deliberate: it is
 * what makes 3 an instruction rather than an apology, and it is a visible
 * acknowledgement that the click was received even when the clipboard write is
 * still in flight.
 *
 * WHAT THE SUITE PROVES ABOUT THIS, AND WHAT IT DOES NOT
 * =====================================================
 * It proves the branching: which API is reached in which world, that a
 * rejected write falls through rather than reporting success, that a `false`
 * from execCommand is believed, that the text is selected in every path, and
 * that the strings come from the localised object when there is one. It cannot
 * prove that anything reached the system clipboard. tests/js/harness.js has no
 * clipboard, no selection model and no user activation, so "the text is really
 * on the clipboard" stays a manual check — on https and on http, and Task 26
 * is where it is written down.
 */

( function ( window ) {
	'use strict';

	var document = window.document;

	/**
	 * The class names this script looks for, all printed by
	 * Admin\Shortcode_Generator.
	 */
	var CLASSES = {
		output: 'slosm-shortcode__output',
		text: 'slosm-shortcode__text',
		status: 'slosm-shortcode__status',
		button: 'slosm-shortcode__copy',
	};

	/**
	 * What to say when nothing localised the strings.
	 *
	 * English, and only reached when wp_localize_script() did not run — a
	 * mis-registered handle, a caching plugin that inlined the file without its
	 * data, a direct script tag. The keys are asserted against
	 * Assets::copy_strings() by the suite, so the two lists cannot drift.
	 */
	var FALLBACK_STRINGS = {
		copy: 'Copy',
		copied: 'Copied.',
		manual: 'It is selected — press Ctrl+C, or ⌘C on a Mac, to copy it.',
	};

	/**
	 * One string, localised when it can be and in English when it cannot.
	 *
	 * @param {string} key Which sentence.
	 * @return {string} The sentence.
	 */
	function text( key ) {
		var supplied = window.slosmCopyL10n;

		if ( supplied && 'string' === typeof supplied[ key ] && '' !== supplied[ key ] ) {
			return supplied[ key ];
		}

		return FALLBACK_STRINGS[ key ] || '';
	}

	/**
	 * Selects the whole of a textarea's contents.
	 *
	 * Wrapped because `select()` is not on every element the DOM can hand back
	 * — a container whose markup somebody changed, an element replaced by a
	 * page builder — and because a throw here would take the copy with it.
	 *
	 * @param {Element} field The textarea.
	 * @return {boolean} Whether anything was selected.
	 */
	function selectAll( field ) {
		if ( ! field || 'function' !== typeof field.select ) {
			return false;
		}

		try {
			field.select();
		} catch ( error ) {
			return false;
		}

		return true;
	}

	/**
	 * Whether this page may use the asynchronous clipboard at all.
	 *
	 * Both halves are load-bearing. `isSecureContext` is the flag the spec
	 * gates the API on, and a browser old enough not to have the flag is a
	 * browser that will not have the API either; the second half is the one
	 * that actually decides, and the first stops the object being touched on a
	 * page where reading it is pointless.
	 *
	 * @return {boolean} Whether writeText() is worth calling.
	 */
	function hasClipboard() {
		if ( false === window.isSecureContext ) {
			return false;
		}

		return !! (
			window.navigator &&
			window.navigator.clipboard &&
			'function' === typeof window.navigator.clipboard.writeText
		);
	}

	/**
	 * Copies with execCommand, the way a browser on plain http has to.
	 *
	 * @return {boolean} What execCommand said, and false when there is none.
	 */
	function copyWithCommand() {
		if ( 'function' !== typeof document.execCommand ) {
			return false;
		}

		try {
			return true === document.execCommand( 'copy' );
		} catch ( error ) {
			return false;
		}
	}

	/**
	 * Says how it went, in the live region PHP printed.
	 *
	 * textContent, never innerHTML: the strings are translatable and a
	 * translation is somebody else's text.
	 *
	 * @param {Element} status Live region.
	 * @param {string}  key    Which sentence.
	 * @return {void}
	 */
	function report( status, key ) {
		if ( status ) {
			status.textContent = text( key );
		}
	}

	/**
	 * One click.
	 *
	 * @param {Element} field  The textarea holding the shortcode.
	 * @param {Element} status The live region.
	 * @return {void}
	 */
	function copy( field, status ) {
		var selected = selectAll( field );

		if ( hasClipboard() ) {
			window.navigator.clipboard.writeText( String( field.value ) ).then(
				function () {
					report( status, 'copied' );
				},
				function () {
					// A rejected write is an ordinary outcome — an unfocused
					// document, a policy that says no — so it falls through to
					// the next way rather than being reported as a failure.
					//
					// `selected &&` is the same guard the synchronous path
					// below has, and it was missing here: execCommand( 'copy' )
					// with nothing selected copies nothing and still answers
					// true, so without it this branch could say "Copied" for a
					// clipboard that refused and a selection that never
					// happened.
					report( status, selected && copyWithCommand() ? 'copied' : 'manual' );
				}
			);

			return;
		}

		if ( selected && copyWithCommand() ) {
			report( status, 'copied' );

			return;
		}

		report( status, 'manual' );
	}

	/**
	 * Wires one generator screen.
	 *
	 * @param {Element} container The element carrying the textarea.
	 * @return {Element|null} The button it added, or null when it added none.
	 */
	function init( container ) {
		if ( ! container || 'function' !== typeof container.querySelector ) {
			return null;
		}

		var field = container.querySelector( '.' + CLASSES.text );

		if ( ! field ) {
			return null;
		}

		// Running twice is a page that loaded the script twice, and two buttons
		// is worse than none.
		if ( container.querySelector( '.' + CLASSES.button ) ) {
			return null;
		}

		var status = container.querySelector( '.' + CLASSES.status );
		var button = document.createElement( 'button' );

		button.setAttribute( 'type', 'button' );
		button.className = 'button ' + CLASSES.button;
		button.textContent = text( 'copy' );

		button.addEventListener( 'click', function () {
			copy( field, status );
		} );

		// Before the live region rather than after it, so that the reading
		// order is "Copy" and then what happened when it was pressed. PHP
		// cannot print the button in the right place because PHP does not print
		// the button at all.
		if ( status ) {
			container.insertBefore( button, status );
		} else {
			container.appendChild( button );
		}

		return button;
	}

	/**
	 * Wires every generator screen on the page, which is one of them.
	 *
	 * @return {Element[]} The buttons added.
	 */
	function initAll() {
		var buttons = [];
		var containers = document.querySelectorAll( '.' + CLASSES.output );
		var index;

		for ( index = 0; index < containers.length; index++ ) {
			var button = init( containers[ index ] );

			if ( button ) {
				buttons.push( button );
			}
		}

		return buttons;
	}

	window.SLOSM_COPY = Object.freeze( {
		CLASSES: CLASSES,
		STRINGS: FALLBACK_STRINGS,
		text: text,
		hasClipboard: hasClipboard,
		copy: copy,
		init: init,
		initAll: initAll,
	} );

	// The same two branches the other two scripts have, and for the same
	// reason: a deferred script runs after the parser has finished, so there is
	// nothing to wait for — and a listener added after DOMContentLoaded has
	// already gone past never runs at all.
	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			initAll();
		} );
	} else {
		initAll();
	}
} )( window );
