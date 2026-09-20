/**
 * Task 22: the copy button on the shortcode generator.
 *
 * The whole of the JavaScript this task ships, and it does one thing: put the
 * generated shortcode on the clipboard, by whichever of three routes this
 * browser has. Everything else on that screen — what the shortcode says, what
 * the preview says, whether the two agree — is PHP and lives in
 * tests/test-shortcode-generator.php.
 *
 * WHAT THIS FILE CANNOT ASK
 * =========================
 * Restated because it bounds every case below: tests/js/harness.js has no
 * clipboard, no selection model, no user activation and no permissions. What
 * is proved here is which API the script reached for in which world, what it
 * handed that API, and what it then told the person. Whether anything arrived
 * on the system clipboard is a manual check — on https and on plain http both,
 * because the second is the branch most likely to be shipped untested.
 *
 * THE THREE WORLDS
 * ================
 * - `https`, modern browser: `isSecureContext` true, `navigator.clipboard`
 *   present. The first route.
 * - `http`, any browser: `isSecureContext` false and **no clipboard object at
 *   all**, because the Clipboard API is `[SecureContext]`. The second route,
 *   `document.execCommand( 'copy' )`, which is the one an intranet install
 *   lands on every single time.
 * - Neither: the third route is a sentence and a selection.
 */

'use strict';

const { test } = require( 'node:test' );
const assert = require( 'node:assert' );

const {
	defaultCopyStrings,
	fire,
	loadShortcode,
	pluginSource,
} = require( './harness.js' );

/** The shortcode the fixture's textarea holds unless a case says otherwise. */
const TAG = '[store_locator height="600" category="Cafés"]';

/**
 * A generator screen with the script already run over it.
 *
 * @param {object} options Anything loadShortcode takes, plus `tag` and `withStatus`.
 * @returns {object} The harness, plus `container`, `field`, `status` and `button`.
 */
function screen( options ) {
	const settings = Object.assign( {}, options || {} );
	const markup = { tag: settings.tag || TAG, withStatus: settings.withStatus };

	delete settings.tag;
	delete settings.withStatus;

	const harness = loadShortcode(
		Object.assign( settings, {
			prepare: ( pieces ) => {
				pieces.generatorMarkup( markup );
			},
		} )
	);

	const container = harness.document.querySelector( '.slosm-shortcode__output' );

	return Object.assign( harness, {
		container,
		field: container ? container.querySelector( '.slosm-shortcode__text' ) : null,
		status: container ? container.querySelector( '.slosm-shortcode__status' ) : null,
		button: container ? container.querySelector( '.slosm-shortcode__copy' ) : null,
	} );
}

/**
 * The keys Assets::copy_strings() returns, read out of the PHP.
 *
 * @returns {string[]} The keys, sorted.
 */
function copyStringKeys() {
	const php = pluginSource( 'includes', 'class-assets.php' );
	const body = php.slice( php.indexOf( 'public function copy_strings' ) );
	const keys = [];
	const end = body.indexOf( '\n\t\t}' );

	body
		.slice( 0, end )
		.split( '\n' )
		.forEach( ( line ) => {
			const match = line.match( /^\t+'([A-Za-z]+)' +=>/ );

			if ( match ) {
				keys.push( match[ 1 ] );
			}
		} );

	return keys.sort();
}

/**
 * Lets the promise the clipboard handed back settle.
 *
 * `await null` is one microtask; the script's then() is another, and the
 * rejection path adds a third when it falls through to execCommand. Three
 * covers every branch and costs nothing.
 *
 * @returns {Promise<void>} Resolved once the queue has drained.
 */
async function settle() {
	await null;
	await null;
	await null;
}

/* -------------------------------------------------------------------------
 * The fixture, before anything it says can be believed.
 * ---------------------------------------------------------------------- */

test( 'the fixture markup uses the class names the generator really emits', () => {
	const php = pluginSource( 'admin', 'class-shortcode-generator.php' );

	// The same control the locator fixture has in harness.test.js, and for the
	// same reason: generatorMarkup() is a hand-copy of PHP, and a hand-copy
	// that drifts turns every case below into a test of the harness.
	assert.ok( php.includes( "'<div class=\"slosm-shortcode__output\">'" ), 'the output container markup changed' );
	assert.ok(
		php.includes( "'<textarea class=\"slosm-shortcode__text large-text code\" rows=\"2\" readonly'" ),
		'the textarea markup changed'
	);
	assert.ok(
		php.includes( "'<p class=\"slosm-shortcode__status\" role=\"status\" aria-live=\"polite\"></p>'" ),
		'the live region markup changed'
	);

	// And the one class the fixture must NOT contain, because the script is
	// what creates it. A fixture carrying a button would make "it adds a
	// button" pass with the script deleted.
	assert.strictEqual( screen().container.querySelectorAll( '.slosm-shortcode__copy' ).length, 1 );
} );

test( 'PHP prints the textarea and no copy button of its own', () => {
	const php = pluginSource( 'admin', 'class-shortcode-generator.php' );

	// The control first, because "PHP does not print X" is satisfied by PHP
	// that prints nothing at all: it does print the thing the button is added
	// beside.
	assert.ok( php.includes( 'slosm-shortcode__text' ), 'PHP no longer prints the textarea' );

	// And then the degradation story in one assertion: a button in the markup
	// is a promise the page cannot keep when the script does not load.
	assert.ok( php.includes( 'slosm-shortcode__copy' ) === false, 'PHP now prints the copy button' );
} );

test( 'the fixture strings have exactly the keys Assets::copy_strings() returns', () => {
	assert.deepStrictEqual( copyStringKeys(), Object.keys( defaultCopyStrings() ).sort() );
} );

test( 'the script\'s fallback table has exactly the keys the PHP localises', () => {
	// Against the PHP directly and not against the fixture, so that a string
	// added on one side and not the other fails here rather than shipping
	// English on a translated site.
	const harness = loadShortcode( { strings: null } );

	assert.deepStrictEqual( Object.keys( harness.SLOSM_COPY.STRINGS ).sort(), copyStringKeys() );
} );

test( 'the l10n variable name is the one Assets localises to', () => {
	const php = pluginSource( 'includes', 'class-assets.php' );

	assert.ok( php.includes( "L10N_SHORTCODE_OBJECT = 'slosmCopyL10n'" ) );
} );

/* -------------------------------------------------------------------------
 * The button.
 * ---------------------------------------------------------------------- */

test( 'it adds one copy button to the output container', () => {
	const harness = screen();

	assert.ok( harness.button, 'no button was added' );
	assert.strictEqual( harness.button.tagName, 'BUTTON' );
	assert.strictEqual( harness.button.getAttribute( 'type' ), 'button' );
	assert.ok( harness.button.classList.contains( 'button' ), 'it is not styled as a WordPress button' );
} );

test( 'the button sits in front of the live region, not after it', () => {
	const harness = screen();
	const children = harness.container.children;

	assert.strictEqual( children[ 0 ], harness.field );
	assert.strictEqual( children[ 1 ], harness.button );
	assert.strictEqual( children[ 2 ], harness.status );
} );

test( 'the button says what the site localised', () => {
	assert.strictEqual( screen().button.textContent, defaultCopyStrings().copy );
} );

test( 'the button says the English fallback when nothing localised it', () => {
	const harness = screen( { strings: null } );

	assert.strictEqual( harness.button.textContent, 'Copy' );
	assert.notStrictEqual( harness.button.textContent, defaultCopyStrings().copy );
} );

test( 'it adds no button when there is no textarea to copy', () => {
	// An empty container and a real one in the same document. The real one is
	// the control: without it this passes for a script that does nothing.
	const harness = loadShortcode( {
		prepare: ( pieces ) => {
			const stray = pieces.document.createElement( 'div' );

			stray.className = 'slosm-shortcode__output';
			pieces.document.body.appendChild( stray );
			pieces.generatorMarkup( {} );
		},
	} );

	const containers = harness.document.querySelectorAll( '.slosm-shortcode__output' );

	assert.strictEqual( containers[ 0 ].querySelector( '.slosm-shortcode__copy' ), null );
	assert.ok( containers[ 1 ].querySelector( '.slosm-shortcode__copy' ), 'the control container got no button' );
} );

test( 'it adds no button when the screen is not the generator at all', () => {
	const harness = loadShortcode();

	assert.strictEqual( harness.document.querySelectorAll( '.slosm-shortcode__copy' ).length, 0 );
	assert.strictEqual( harness.SLOSM_COPY.initAll().length, 0 );

	// The control: the same call, on the same document, once there is a
	// generator on it.
	harness.generatorMarkup( {} );

	assert.strictEqual( harness.SLOSM_COPY.initAll().length, 1 );
} );

test( 'a second run adds no second button', () => {
	const harness = screen();

	harness.evaluate();
	harness.SLOSM_COPY.initAll();

	assert.strictEqual( harness.container.querySelectorAll( '.slosm-shortcode__copy' ).length, 1 );
} );

test( 'it works on a screen whose markup has no live region', () => {
	const harness = screen( { withStatus: false } );

	assert.ok( harness.button, 'no button was added' );

	fire( harness.button, 'click' );

	assert.deepStrictEqual( harness.clipboardCalls, [ TAG ] );
} );

/* -------------------------------------------------------------------------
 * A secure context, where there is a clipboard.
 * ---------------------------------------------------------------------- */

test( 'it writes the textarea\'s text, and says so', async () => {
	const harness = screen();

	fire( harness.button, 'click' );

	assert.deepStrictEqual( harness.clipboardCalls, [ TAG ] );

	await settle();

	assert.strictEqual( harness.status.textContent, defaultCopyStrings().copied );
} );

test( 'it says nothing until the write has actually settled', () => {
	const harness = screen();

	fire( harness.button, 'click' );

	// The control for the case above: if the script reported success
	// synchronously, that case would pass for a promise that later rejected.
	assert.strictEqual( harness.status.textContent, '' );
} );

test( 'it selects the text as well as writing it', () => {
	const harness = screen();

	fire( harness.button, 'click' );

	assert.strictEqual( harness.field.selections, 1 );
} );

test( 'it does not reach for execCommand when the clipboard took it', async () => {
	const harness = screen();

	fire( harness.button, 'click' );
	await settle();

	assert.deepStrictEqual( harness.commandCalls, [] );
} );

test( 'a refused write falls through to execCommand rather than reporting failure', async () => {
	const harness = screen();

	harness.clipboardQueue.push( { reject: new Error( 'Document is not focused.' ) } );

	fire( harness.button, 'click' );
	await settle();

	assert.deepStrictEqual( harness.commandCalls, [ 'copy' ] );
	assert.strictEqual( harness.status.textContent, defaultCopyStrings().copied );
} );

test( 'a refused write with nothing behind it says what to press', async () => {
	const harness = screen( { withExecCommand: false } );

	harness.clipboardQueue.push( { reject: new Error( 'NotAllowedError' ) } );

	fire( harness.button, 'click' );
	await settle();

	assert.strictEqual( harness.status.textContent, defaultCopyStrings().manual );
} );

test( 'a refused write over a field that cannot be selected claims nothing', async () => {
	// The mirror of "a field that cannot be selected is not handed to
	// execCommand", on the path the clipboard rejection takes. It was missing,
	// and without it this branch said "Copied." for a clipboard that refused
	// and a selection that never happened — execCommand( 'copy' ) with nothing
	// selected copies nothing and still answers true.
	const harness = screen();

	harness.field.select = null;
	harness.clipboardQueue.push( { reject: new Error( 'Document is not focused.' ) } );

	fire( harness.button, 'click' );
	await settle();

	assert.deepStrictEqual( harness.commandCalls, [] );
	assert.strictEqual( harness.status.textContent, defaultCopyStrings().manual );
} );

test( 'a refused write and a refused execCommand say what to press', async () => {
	const harness = screen();

	harness.clipboardQueue.push( { reject: new Error( 'NotAllowedError' ) } );
	harness.commandQueue.push( false );

	fire( harness.button, 'click' );
	await settle();

	assert.strictEqual( harness.status.textContent, defaultCopyStrings().manual );
} );

/* -------------------------------------------------------------------------
 * Plain http, where there is not.
 * ---------------------------------------------------------------------- */

test( 'on plain http it uses execCommand and never touches the clipboard', () => {
	const harness = screen( { secureContext: false, withClipboard: false } );

	fire( harness.button, 'click' );

	assert.deepStrictEqual( harness.commandCalls, [ 'copy' ] );
	assert.deepStrictEqual( harness.clipboardCalls, [] );
	assert.strictEqual( harness.status.textContent, defaultCopyStrings().copied );
} );

test( 'it leaves a clipboard alone even when one is somehow there on http', () => {
	// isSecureContext is the flag the spec gates the API on, so a page that
	// says it is not secure does not get to use it — whatever is hanging off
	// navigator. This is the half of hasClipboard() the case above cannot
	// reach, because that world has no clipboard object at all.
	const harness = screen( { secureContext: false, withClipboard: true } );

	fire( harness.button, 'click' );

	assert.deepStrictEqual( harness.clipboardCalls, [] );
	assert.deepStrictEqual( harness.commandCalls, [ 'copy' ] );
} );

test( 'a browser too old to have isSecureContext still uses the clipboard it has', async () => {
	const harness = screen( { secureContext: null } );

	assert.strictEqual( harness.sandbox.isSecureContext, undefined );

	fire( harness.button, 'click' );
	await settle();

	assert.deepStrictEqual( harness.clipboardCalls, [ TAG ] );
} );

test( 'a false from execCommand is believed', () => {
	const harness = screen( { secureContext: false, withClipboard: false } );

	harness.commandQueue.push( false );

	fire( harness.button, 'click' );

	assert.strictEqual( harness.status.textContent, defaultCopyStrings().manual );
} );

test( 'a browser with neither API says what to press, having selected the text', () => {
	const harness = screen( { secureContext: false, withClipboard: false, withExecCommand: false } );

	fire( harness.button, 'click' );

	assert.strictEqual( harness.field.selections, 1 );
	assert.strictEqual( harness.status.textContent, defaultCopyStrings().manual );
} );

test( 'a field that cannot be selected is not handed to execCommand', () => {
	const harness = screen( { secureContext: false, withClipboard: false } );

	harness.field.select = null;

	fire( harness.button, 'click' );

	// execCommand( 'copy' ) with nothing selected copies nothing and still
	// answers true, which would be this screen claiming a copy that did not
	// happen.
	assert.deepStrictEqual( harness.commandCalls, [] );
	assert.strictEqual( harness.status.textContent, defaultCopyStrings().manual );
} );

test( 'an execCommand that throws is a refusal, not a crash', () => {
	const harness = screen( { secureContext: false, withClipboard: false } );

	harness.document.execCommand = () => {
		throw new Error( 'SecurityError' );
	};

	fire( harness.button, 'click' );

	assert.strictEqual( harness.status.textContent, defaultCopyStrings().manual );
} );

/* -------------------------------------------------------------------------
 * The namespace.
 * ---------------------------------------------------------------------- */

test( 'it adds one global and freezes it', () => {
	const harness = screen();

	assert.deepStrictEqual( harness.newGlobals, [ 'SLOSM_COPY' ] );
	assert.ok( Object.isFrozen( harness.SLOSM_COPY ) );
} );

test( 'hasClipboard() answers for the world it is in', () => {
	assert.strictEqual( loadShortcode().SLOSM_COPY.hasClipboard(), true );
	assert.strictEqual( loadShortcode( { withClipboard: false } ).SLOSM_COPY.hasClipboard(), false );
	assert.strictEqual( loadShortcode( { secureContext: false } ).SLOSM_COPY.hasClipboard(), false );
} );

test( 'it runs on DOMContentLoaded when the parser has not finished', () => {
	const harness = loadShortcode( {
		readyState: 'loading',
		prepare: ( pieces ) => {
			pieces.generatorMarkup( {} );
		},
	} );

	const container = harness.document.querySelector( '.slosm-shortcode__output' );

	assert.strictEqual( container.querySelector( '.slosm-shortcode__copy' ), null );

	harness.document.dispatchEvent( 'DOMContentLoaded' );

	assert.ok( container.querySelector( '.slosm-shortcode__copy' ), 'nothing wired on DOMContentLoaded' );
} );
