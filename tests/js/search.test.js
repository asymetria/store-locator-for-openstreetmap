/**
 * Task 14: the search field, the suggestions, the geocode and the results list.
 *
 * What is covered here and not in locator.test.js is everything that happens
 * after somebody types: the debounce that keeps a keystroke from becoming an
 * upstream request, the cancellation that keeps a slow answer from overwriting
 * a fast one, the 204 that means "ask again" rather than "no matches", the
 * combobox key handling, and the list of rows cloned from the <template>.
 *
 * Three things these cases deliberately do NOT do. The popup belongs to Task
 * 16 and is still unwritten. "Near me" and the category filter belong to Task
 * 15 and are covered in tests/js/nearby.test.js — the cases here stay as they
 * were written, against a locator whose only way in is the text field, which is
 * what keeps them cases about the search rather than about the controls beside
 * it. The radius and result-count selects were wired to the search by Task 29a
 * and are covered in tests/js/filters.test.js; the cases here still never touch
 * them, which is what keeps a search case a search case.
 *
 * Task 29c added one thing to this file's subject and it is at the bottom: the
 * submit control beside the address field. It is here rather than in
 * filters.test.js because it runs this field's search — the same code path
 * Enter runs, through runSearch() — and not because it is a control. What it
 * is not is a form, and Shortcode's comment beside the button says why.
 *
 * On time: nothing here waits. The sandbox's setTimeout is the harness's fake
 * clock and `harness.clock.tick( ms )` is the only thing that makes time pass,
 * so a debounce case is exact rather than slow-and-flaky. On races: a slow
 * answer is a `deferredResponse()` the case settles by hand, which is the only
 * way to write "the first answer arrived after the second".
 */

'use strict';

const { test } = require( 'node:test' );
const assert = require( 'node:assert' );

const {
	brokenJsonResponse,
	codeOnly,
	defaultConfig,
	defaultStrings,
	deferredResponse,
	emptyBodyResponse,
	fire,
	jsonResponse,
	loadLocator,
	pluginSource,
	plain,
} = require( './harness.js' );

/**
 * The debounce, in milliseconds, as `assets/js/locator.js` sets it.
 *
 * A literal rather than something read out of the source, which would make
 * every case below agree with whatever the constant happened to be. It is
 * pinned against the source by one case — "the debounce is the 600 ms the
 * server's skip needs" — and that case is the only place the two are compared.
 */
const DEBOUNCE = 600;

/** Warsaw, and the two other cities every fixture here uses. */
const WARSAW = { lat: 52.2297, lng: 21.0122 };
const LODZ = { lat: 51.7592, lng: 19.456 };
const KRAKOW = { lat: 50.0647, lng: 19.945 };

/**
 * One store, lean-shaped, as GET /stores returns it.
 *
 * @param {object} overrides Keys to change.
 * @returns {object} One item.
 */
function store( overrides ) {
	return Object.assign(
		{
			id: 1,
			name: 'Warsaw',
			lat: WARSAW.lat,
			lng: WARSAW.lng,
			address: 'Nowy Świat 1',
			city: 'Warszawa',
			categories: [ 'Shops' ],
			distance: null,
		},
		overrides || {}
	);
}

/**
 * A locator with a search field, already preloaded with `payload`.
 *
 * A radius of 500 by default, because the fixtures are Polish cities two to
 * three hundred kilometres apart and the shortcode's own default of 50 would
 * filter every case about *ordering* down to one row. The radius itself has
 * its own case.
 *
 * @param {object} options `config`, `payload`, plus anything loadLocator takes.
 * @returns {Promise<object>} The harness, plus `container` and `instance`.
 */
async function locator( options ) {
	const settings = options || {};
	const harness = loadLocator( settings );

	if ( 'payload' in settings ) {
		harness.fetchQueue.push(
			Array.isArray( settings.payload ) ? jsonResponse( settings.payload ) : settings.payload
		);
	}

	const container = harness.locatorMarkup( {
		config: 'config' in settings ? settings.config : defaultConfig( { radius: 500 } ),
		withSearch: settings.withSearch,
		withSubmit: settings.withSubmit,
		withLabel: settings.withLabel,
		withTemplate: settings.withTemplate,
		withResults: settings.withResults,
		search: settings.search,
	} );

	const instances = harness.SLOSM.initAll();

	if ( instances[ 0 ] && instances[ 0 ].ready ) {
		await instances[ 0 ].ready;
	}

	return Object.assign( harness, { container, instance: instances[ 0 ] || null } );
}

/** @returns {object} The search input of this locator. */
function field( container ) {
	return container.querySelector( '.slosm__search' );
}

/** @returns {object|null} The submit button Task 29c added, if this fixture has one. */
function submitButton( container ) {
	return container.querySelector( '.slosm__submit' );
}

/**
 * A press of the search button, as a browser delivers it.
 *
 * Two orderings, because a pointer press on a button is not one event sequence
 * everywhere and the difference is visible to this code. Chrome and Edge focus
 * the button on mousedown, which blurs the field, which closes the suggestion
 * popup before the click lands. Safari and Firefox on macOS do not focus
 * buttons on click at all, so the popup is still open and an option may still
 * be highlighted when the handler runs.
 *
 * `focusFirst` is that difference, spelled out rather than assumed: the
 * harness's click is an event and nothing else, so a case that wants the
 * focus-moving browsers has to say so. Both orderings have a case below, and
 * they are two cases rather than one because they have two right answers.
 *
 * @param {object}  container  The locator.
 * @param {boolean} focusFirst Whether the press moves the focus off the field first.
 * @returns {object} The event object the handlers saw.
 */
function pressButton( container, focusFirst ) {
	if ( focusFirst ) {
		field( container ).blur();
	}

	return fire( submitButton( container ), 'click' );
}

/** @returns {object|null} The suggestion listbox, if the script built one. */
function popup( container ) {
	return container.querySelector( '.slosm__suggestions' );
}

/** @returns {Array} The suggestion options on screen. */
function choices( container ) {
	return container.querySelectorAll( '.slosm__suggestion' );
}

/** @returns {Array} The result rows on screen. */
function rows( container ) {
	return container.querySelectorAll( '.slosm__result' );
}

/** @returns {Array<string>} The name cell of every result row, in order. */
function names( container ) {
	return rows( container ).map( ( row ) => row.querySelector( '.slosm__result-name' ).textContent );
}

/**
 * Every id attribute in the document, in tree order, duplicates included.
 *
 * @param {object} doc The document.
 * @returns {Array<string>} The ids.
 */
function allIds( doc ) {
	const found = [];

	const walk = ( node ) => {
		node.children.forEach( ( child ) => {
			const id = child.getAttribute( 'id' );

			if ( id ) {
				found.push( id );
			}

			walk( child );
		} );
	};

	walk( doc.documentElement );

	return found;
}

/** @returns {string|null} The message in front of the visitor, if any. */
function message( container ) {
	const node = container.querySelector( '.slosm__message' );

	// Empty reads as null, and that is not a convenience. Task 24c made the
	// status line part of the server's markup, so the element is on every
	// locator whether or not it has anything to say; "is this locator saying
	// something" is the question every case here is asking, and an empty
	// status line is not. A case that needs to tell an empty element from a
	// missing one queries for it directly.
	return node && '' !== node.textContent ? node.textContent : null;
}

/**
 * The sentence a draw of `n` results leaves in the status line.
 *
 * Task 24d: every draw a person asked for says how many it found, so "nothing
 * was said" is no longer what a finished search looks like. A case that used to
 * assert null against an error it did not want now asserts this, which is the
 * stronger statement: not merely that nothing bad was said, but that what was
 * said is the count.
 *
 * @param {number} n How many rows the draw ended with.
 * @returns {string} The sentence.
 */
function found( n ) {
	return defaultStrings().resultsFound.replace( '%s', String( n ) );
}

/**
 * Types into the search field the way a person does: value, then `input`.
 *
 * @param {object} container The locator.
 * @param {string} text      What is now in the field.
 * @returns {object} The event object the handlers saw.
 */
function type( container, text ) {
	const input = field( container );

	input.value = text;

	return fire( input, 'input' );
}

/**
 * A key press on the search field.
 *
 * @param {object} container The locator.
 * @param {string} key       The `key` value, e.g. 'ArrowDown'.
 * @returns {object} The event object the handlers saw.
 */
function press( container, key ) {
	return fire( field( container ), 'keydown', { key } );
}

/**
 * Types, lets the debounce expire, and waits for the request to settle.
 *
 * @param {object} harness The harness.
 * @param {string} text    What to type.
 * @returns {Promise<void>} When the suggestion round trip is over.
 */
async function suggest( harness, text ) {
	type( harness.container, text );
	harness.clock.tick( DEBOUNCE );

	await harness.instance.pendingSuggest;
}

/**
 * Waits for something the microtask queue is about to do, and no longer.
 *
 * Every promise in this suite settles on the real microtask queue, so "let the
 * chain get one step further" is a number of turns rather than a length of
 * time — and a number of turns written as a literal is a guess that hangs the
 * whole run when it is one too few. This drains turns until the condition
 * holds and throws with a name when it never does. No timer, no sleeping, and
 * a failure that says which step never happened.
 *
 * @param {Function} condition What has to become true.
 * @param {string}   what      Named in the failure.
 * @returns {Promise<void>} When the condition holds.
 */
async function until( condition, what ) {
	for ( let turn = 0; turn < 100; turn++ ) {
		if ( condition() ) {
			return;
		}

		await Promise.resolve();
	}

	throw new Error( 'the microtask queue drained and this never happened: ' + what );
}

/**
 * One suggestion, as GET /suggest returns it.
 *
 * @param {string} label The line shown in the dropdown.
 * @param {object} point Coordinates.
 * @returns {object} The suggestion.
 */
function suggestion( label, point ) {
	return Object.assign( { label }, point || WARSAW );
}

/* -------------------------------------------------------------------------
 * The debounce, which is the server's protection and not a nicety
 * ---------------------------------------------------------------------- */

test( 'a keystroke asks nothing until the debounce expires', async () => {
	const harness = await locator( { payload: [ store() ] } );

	harness.fetchQueue.push( jsonResponse( [ suggestion( 'Warsaw' ) ] ) );

	const before = harness.fetchCalls.length;

	type( harness.container, 'war' );
	harness.clock.tick( DEBOUNCE - 1 );

	assert.strictEqual( harness.fetchCalls.length, before, '/suggest was asked before the debounce expired' );

	// The control. Without it this case is satisfied by a script that never
	// asks at all, which is exactly what a skeleton does.
	harness.clock.tick( 1 );

	await harness.instance.pendingSuggest;

	assert.strictEqual( harness.fetchCalls.length, before + 1, '/suggest was never asked' );
	assert.ok( harness.fetchCalls[ before ].url.indexOf( '/suggest' ) > -1 );
	assert.strictEqual( new URL( harness.fetchCalls[ before ].url ).searchParams.get( 'q' ), 'war' );
} );

test( 'a burst of keystrokes inside one window is one request, carrying the last of them', async () => {
	// The whole point. Every /suggest cache miss can hold a PHP worker behind
	// the geocoder's one-request-per-second courtesy limiter, so an
	// undebounced field is an attack on the site from its own visitors.
	const harness = await locator( { payload: [ store() ] } );

	harness.fetchQueue.push( jsonResponse( [ suggestion( 'Warszawa' ) ] ) );

	const before = harness.fetchCalls.length;

	type( harness.container, 'w' );
	harness.clock.tick( 100 );
	type( harness.container, 'wa' );
	harness.clock.tick( 100 );
	type( harness.container, 'war' );
	harness.clock.tick( 100 );

	assert.strictEqual( harness.fetchCalls.length, before, 'a keystroke restarted nothing' );

	// The rest of one window, measured from the last keystroke rather than
	// from the first: 100 ms of it has already gone above.
	harness.clock.tick( DEBOUNCE - 100 );

	await harness.instance.pendingSuggest;

	assert.strictEqual( harness.fetchCalls.length, before + 1, 'the field asked once per keystroke' );
	assert.strictEqual( new URL( harness.fetchCalls[ before ].url ).searchParams.get( 'q' ), 'war' );
} );

test( 'emptying the field asks nothing and closes what is open', async () => {
	const harness = await locator( { payload: [ store() ] } );

	harness.fetchQueue.push( jsonResponse( [ suggestion( 'Warsaw' ) ] ) );

	await suggest( harness, 'war' );

	assert.strictEqual( choices( harness.container ).length, 1 );

	const before = harness.fetchCalls.length;

	type( harness.container, '   ' );
	harness.clock.tick( DEBOUNCE );

	assert.strictEqual( harness.fetchCalls.length, before, 'whitespace was sent upstream as a query' );
	assert.strictEqual( choices( harness.container ).length, 0 );
	assert.strictEqual( field( harness.container ).getAttribute( 'aria-expanded' ), 'false' );
} );

/* -------------------------------------------------------------------------
 * A slow answer must not beat a fast one
 * ---------------------------------------------------------------------- */

test( 'a new keystroke aborts the request in flight', async () => {
	const harness = await locator( { payload: [ store() ] } );
	const slow = deferredResponse();

	harness.fetchQueue.push( slow );
	harness.fetchQueue.push( jsonResponse( [ suggestion( 'Warszawa' ) ] ) );

	type( harness.container, 'war' );
	harness.clock.tick( DEBOUNCE );

	const first = harness.fetchCalls[ harness.fetchCalls.length - 1 ];
	// The first request's whole chain, held so that the end of this case can
	// wait for it rather than for a couple of microtask turns that may or may
	// not be enough to drain a then/then/catch.
	const firstChain = harness.instance.pendingSuggest;

	assert.ok( first.signal, '/suggest was sent with no AbortSignal at all' );
	assert.strictEqual( first.signal.aborted, false );

	type( harness.container, 'warsa' );
	harness.clock.tick( DEBOUNCE );

	assert.strictEqual( first.signal.aborted, true, 'the request in flight was never cancelled' );

	await harness.instance.pendingSuggest;

	// And the late answer, when it finally turns up, changes nothing.
	slow.resolve( jsonResponse( [ suggestion( 'Warrington' ), suggestion( 'Warwick' ) ] ) );

	await firstChain;

	assert.deepStrictEqual(
		choices( harness.container ).map( ( option ) => option.textContent ),
		[ 'Warszawa' ],
		'a cancelled request still replaced the list'
	);
} );

test( 'without an AbortController a slow first answer still does not win', async () => {
	// The browsers this plugin supports all have AbortController; the case is
	// not about them. It is the control that proves the sequence guard does
	// its own work rather than passing because the abort got there first. Take
	// the guard out and this case fails while the one above still passes.
	const harness = await locator( { payload: [ store() ], withAbortController: false } );
	const slow = deferredResponse();

	harness.fetchQueue.push( slow );
	harness.fetchQueue.push( jsonResponse( [ suggestion( 'Warszawa' ) ] ) );

	type( harness.container, 'war' );
	harness.clock.tick( DEBOUNCE );

	assert.strictEqual( harness.fetchCalls[ harness.fetchCalls.length - 1 ].signal, null );

	const firstChain = harness.instance.pendingSuggest;

	type( harness.container, 'warsa' );
	harness.clock.tick( DEBOUNCE );

	await harness.instance.pendingSuggest;

	assert.deepStrictEqual(
		choices( harness.container ).map( ( option ) => option.textContent ),
		[ 'Warszawa' ]
	);

	slow.resolve( jsonResponse( [ suggestion( 'Warrington' ) ] ) );

	await firstChain;

	assert.deepStrictEqual(
		choices( harness.container ).map( ( option ) => option.textContent ),
		[ 'Warszawa' ],
		'the stale answer overwrote the list it arrived behind'
	);
} );

test( 'a stale request that fails late does not close a popup that is already answering', async () => {
	// The other end of the same guard. A request the field has moved past
	// cannot be allowed to *close* the popup either — and it is a 500 arriving
	// late rather than a list, so the abort is not what stops it. Without an
	// AbortController there is nothing but the sequence number here.
	const harness = await locator( { payload: [ store() ], withAbortController: false } );
	const slow = deferredResponse();

	harness.fetchQueue.push( slow );
	harness.fetchQueue.push( jsonResponse( [ suggestion( 'Warszawa' ) ] ) );

	type( harness.container, 'war' );
	harness.clock.tick( DEBOUNCE );

	const firstChain = harness.instance.pendingSuggest;

	type( harness.container, 'warsa' );
	harness.clock.tick( DEBOUNCE );

	await harness.instance.pendingSuggest;

	assert.strictEqual( choices( harness.container ).length, 1 );

	slow.resolve( jsonResponse( { code: 'slosm_suggest_http' }, { ok: false, status: 502 } ) );

	await firstChain;

	assert.strictEqual(
		choices( harness.container ).length,
		1,
		'a failure from a request the field had moved past closed the popup'
	);
} );

/* -------------------------------------------------------------------------
 * The 204, which means "ask again" and never "no matches"
 * ---------------------------------------------------------------------- */

test( 'a 204 never reads the body, and never leaves an answer to another question up', async () => {
	// /suggest answers 204 when it declined to ask upstream rather than when
	// it asked and found nothing. Two things follow and both are here.
	//
	// The body is never touched: the response queued below has a json() that
	// REJECTS, the way an empty body really does, so a script that reads the
	// body before the status lands in the catch instead.
	//
	// And the options that were on screen come down. They were found for
	// `wars`; the field now reads `warszawa`; leaving them up means Enter on a
	// highlighted one moves the map to a place that does not match what was
	// typed. Under sustained throttling that is the normal state, not an edge
	// case. Coming down is not "no matches" — nothing says that, and the case
	// below proves the field asks again.
	const harness = await locator( { payload: [ store() ] } );

	harness.fetchQueue.push( jsonResponse( [ suggestion( 'Warszawa' ), suggestion( 'Warsaw, IN' ) ] ) );

	await suggest( harness, 'wars' );

	assert.strictEqual( choices( harness.container ).length, 2 );

	harness.fetchQueue.push( emptyBodyResponse( { ok: true, status: 204 } ) );

	await suggest( harness, 'warszawa' );

	assert.strictEqual( choices( harness.container ).length, 0, 'options found for another query were left up' );
	assert.strictEqual( field( harness.container ).getAttribute( 'aria-expanded' ), 'false' );
	assert.strictEqual( message( harness.container ), null, 'a 204 put a message in front of the visitor' );
	assert.strictEqual( rows( harness.container ).length, 1, 'a 204 emptied the results list' );

	// And nothing was reported anywhere. This is the assertion that makes the
	// status check load-bearing rather than decorative: a script that reached
	// for the body first would land in the catch, which closes the popup too —
	// the same visible outcome — and writes a line to the console. A skipped
	// suggestion is ordinary operation, and under sustained throttling it is
	// most of them; logging one per keystroke would be noise in every
	// developer's console on a busy site.
	assert.deepStrictEqual( harness.consoleCalls.warn, [], 'a skipped suggestion was reported as an error' );

	// The control: a real failure still says so, so the silence above is about
	// the 204 rather than about a script that reports nothing at all.
	harness.fetchQueue.push( jsonResponse( { code: 'slosm_suggest_http' }, { ok: false, status: 502 } ) );

	await suggest( harness, 'warszawaa' );

	assert.strictEqual( harness.consoleCalls.warn.length, 1, 'a failed suggestion was not reported anywhere' );
} );

test( 'a 204 means ask again, and a 200 means do not', async () => {
	// This is what keeps "no information, ask again" from being a comment. The
	// two statuses look the same on screen — both leave no options up — so the
	// difference has to be visible somewhere, and it is here: a query the
	// service *answered* is not asked twice, and a query it *declined* is.
	//
	// Written as one case because it is one comparison; splitting it would
	// leave each half asserting a number with nothing to compare it to.
	const harness = await locator( { payload: [ store() ] } );

	// Declined. Nothing is remembered.
	harness.fetchQueue.push( emptyBodyResponse( { ok: true, status: 204 } ) );

	await suggest( harness, 'krak' );

	const afterSkip = harness.fetchCalls.length;

	// Away and back to the same text.
	await suggest( harness, 'kra' );

	harness.fetchQueue.push( jsonResponse( [ suggestion( 'Kraków', KRAKOW ) ] ) );

	await suggest( harness, 'krak' );

	assert.strictEqual(
		harness.fetchCalls.length,
		afterSkip + 2,
		'a query the service declined to answer was not asked again'
	);
	assert.strictEqual( choices( harness.container ).length, 1 );

	// Answered. Now a typo corrected inside the debounce window costs nothing:
	// 'krakx' never became a request, so what is remembered is still the
	// answer for 'krak', and putting it back needs no round trip.
	const afterAnswer = harness.fetchCalls.length;

	type( harness.container, 'krakx' );
	harness.clock.tick( 100 );
	type( harness.container, 'krak' );
	harness.clock.tick( DEBOUNCE );

	await harness.instance.pendingSuggest;

	assert.strictEqual(
		harness.fetchCalls.length,
		afterAnswer,
		'a query the service had already answered was sent upstream again'
	);
	assert.deepStrictEqual(
		choices( harness.container ).map( ( option ) => option.textContent ),
		[ 'Kraków' ],
		'the remembered answer was not the one put back on screen'
	);

	// And the limit of one entry, stated rather than left to be discovered: a
	// query answered two requests ago is gone, and asking for it asks again.
	harness.fetchQueue.push( jsonResponse( [ suggestion( 'Łódź', LODZ ) ] ) );

	await suggest( harness, 'lodz' );

	harness.fetchQueue.push( jsonResponse( [ suggestion( 'Kraków', KRAKOW ) ] ) );

	await suggest( harness, 'krak' );

	assert.strictEqual( harness.fetchCalls.length, afterAnswer + 2 );
} );

test( 'a 200 with an empty list closes the popup too, and is remembered', async () => {
	// An empty 200 is the service saying it looked and found nothing, which is
	// information worth keeping: the same text typed again asks nothing.
	const harness = await locator( { payload: [ store() ] } );

	harness.fetchQueue.push( jsonResponse( [ suggestion( 'Warszawa' ) ] ) );

	await suggest( harness, 'wars' );

	assert.strictEqual( choices( harness.container ).length, 1 );

	harness.fetchQueue.push( jsonResponse( [] ) );

	await suggest( harness, 'warszz' );

	assert.strictEqual( choices( harness.container ).length, 0 );
	assert.strictEqual( field( harness.container ).getAttribute( 'aria-expanded' ), 'false' );

	const asked = harness.fetchCalls.length;

	// The same text again, straight away: nothing found is an answer, and an
	// answer is not worth a second request.
	await suggest( harness, 'warszz' );

	assert.strictEqual( harness.fetchCalls.length, asked, 'an answered query was asked again' );
	assert.strictEqual( choices( harness.container ).length, 0 );
} );

test( 'an answer that arrives after a remembered one does not overwrite it', async () => {
	// The memo is a shortcut past the request, so it has to disown the request
	// already in flight as well. Type `krak` (answered and remembered), type
	// `krakx` (asked, still out there), cut back to `krak` (answered from the
	// memo, no request) — and the `krakx` answer must not land on top of the
	// options that are now on screen.
	const harness = await locator( { payload: [ store() ], withAbortController: false } );

	harness.fetchQueue.push( jsonResponse( [ suggestion( 'Kraków', KRAKOW ) ] ) );

	await suggest( harness, 'krak' );

	const slow = deferredResponse();

	harness.fetchQueue.push( slow );

	type( harness.container, 'krakx' );
	harness.clock.tick( DEBOUNCE );

	const inFlight = harness.instance.pendingSuggest;

	type( harness.container, 'krak' );
	harness.clock.tick( DEBOUNCE );

	assert.deepStrictEqual(
		choices( harness.container ).map( ( option ) => option.textContent ),
		[ 'Kraków' ],
		'the remembered answer was not put back on screen'
	);

	slow.resolve( jsonResponse( [ suggestion( 'Łódź', LODZ ) ] ) );

	await inFlight;

	assert.deepStrictEqual(
		choices( harness.container ).map( ( option ) => option.textContent ),
		[ 'Kraków' ],
		'a request the memo overtook still replaced the options'
	);
} );

test( 'a single character is not worth a worker', async () => {
	// Every /suggest cache miss can hold a PHP worker behind the geocoder's
	// courtesy limiter, and one character cannot disambiguate an address
	// anywhere. Two can — Ås, Ho, every British postcode prefix — so that is
	// where the floor is.
	const harness = await locator( { payload: [ store() ] } );
	const before = harness.fetchCalls.length;

	type( harness.container, 'w' );
	harness.clock.tick( DEBOUNCE );

	assert.strictEqual( harness.fetchCalls.length, before, 'one character was sent upstream' );

	harness.fetchQueue.push( jsonResponse( [ suggestion( 'Warszawa' ) ] ) );

	await suggest( harness, 'wa' );

	assert.strictEqual( harness.fetchCalls.length, before + 1, 'two characters were not enough to ask' );

	// And Enter still looks up whatever is in the field: the floor is about
	// suggestions, not about what somebody may search for.
	harness.fetchQueue.push( jsonResponse( { lat: WARSAW.lat, lng: WARSAW.lng, label: 'W' } ) );

	field( harness.container ).value = 'W';
	press( harness.container, 'Enter' );

	await harness.instance.pendingSearch;

	assert.strictEqual( harness.fetchCalls.length, before + 2, 'a one-character search was refused' );
} );

test( 'a failed /suggest closes the popup quietly rather than shouting at the visitor', async () => {
	// Suggestions are a convenience. A 500 from them is not worth a sentence
	// in the results list, where it would sit on top of the locations that did
	// load; the visitor can still press Enter and search.
	const harness = await locator( { payload: [ store() ] } );

	harness.fetchQueue.push( jsonResponse( [ suggestion( 'Warszawa' ) ] ) );

	await suggest( harness, 'wars' );

	harness.fetchQueue.push( jsonResponse( { code: 'slosm_suggest_http' }, { ok: false, status: 502 } ) );

	await suggest( harness, 'warsz' );

	assert.strictEqual( choices( harness.container ).length, 0 );
	assert.strictEqual( message( harness.container ), null, 'a failed suggestion wrote a message into the results' );
	assert.strictEqual( rows( harness.container ).length, 1, 'a failed suggestion emptied the results list' );
} );

/* -------------------------------------------------------------------------
 * The combobox
 * ---------------------------------------------------------------------- */

test( 'the search field is upgraded to a combobox, and only by the script', async () => {
	const harness = loadLocator();
	const container = harness.locatorMarkup( { config: defaultConfig( { mode: 'query', count: 900 } ) } );

	// Before init: a plain search input. The markup makes no promise the
	// JavaScript has not arrived to keep — a combobox with no popup is a lie
	// told to a screen reader.
	assert.strictEqual( field( container ).getAttribute( 'role' ), null );
	assert.strictEqual( popup( container ), null );

	harness.SLOSM.initAll();

	const input = field( container );
	const list = popup( container );

	assert.strictEqual( input.getAttribute( 'role' ), 'combobox' );
	assert.strictEqual( input.getAttribute( 'aria-expanded' ), 'false' );
	assert.strictEqual( input.getAttribute( 'aria-autocomplete' ), 'list' );
	assert.ok( list, 'the script built no listbox' );
	assert.strictEqual( list.getAttribute( 'role' ), 'listbox' );

	// aria-controls resolves to the listbox rather than merely matching a
	// string this same code wrote, which is what getElementById is for.
	assert.strictEqual(
		harness.document.getElementById( input.getAttribute( 'aria-controls' ) ),
		list,
		'aria-controls does not resolve to the listbox'
	);

	// The popup is a sibling of the label, not inside it: a click on an option
	// inside a <label> is a click on the label, which moves focus to the input
	// and can undo the choice being made.
	assert.strictEqual( list.parentNode.classList.contains( 'slosm__filters' ), true );
} );

test( 'the ids it mints are unique across two locators on one page', async () => {
	// Task 11 uses no ids anywhere, precisely so that two locators rendered by
	// two different PHP objects cannot collide. aria-activedescendant needs
	// one, so the front end mints them — which is safe for the reason the PHP
	// could not rely on: every locator on the page is initialised by one
	// evaluation of one script, holding one counter.
	const harness = loadLocator();

	harness.fetchQueue.push( jsonResponse( [ store() ] ) );
	harness.fetchQueue.push( jsonResponse( [ store() ] ) );

	const first = harness.locatorMarkup();
	const second = harness.locatorMarkup();
	const instances = harness.SLOSM.initAll();

	await Promise.all( instances.map( ( instance ) => instance.ready ) );

	harness.fetchQueue.push( jsonResponse( [ suggestion( 'Warszawa' ) ] ) );
	harness.fetchQueue.push( jsonResponse( [ suggestion( 'Warszawa' ) ] ) );

	type( first, 'war' );
	type( second, 'war' );
	harness.clock.tick( DEBOUNCE );

	await Promise.all( instances.map( ( instance ) => instance.pendingSuggest ) );

	const ids = [ first, second ].map( ( container ) => field( container ).getAttribute( 'aria-controls' ) );

	assert.notStrictEqual( ids[ 0 ], ids[ 1 ], 'two locators minted the same listbox id' );

	const optionIds = choices( first )
		.concat( choices( second ) )
		.map( ( option ) => option.getAttribute( 'id' ) );

	assert.strictEqual( new Set( optionIds ).size, optionIds.length, 'two options share an id' );
	assert.strictEqual( optionIds.filter( ( id ) => ! id ).length, 0, 'an option has no id to be pointed at' );
} );

test( 'a second evaluation of the file does not mint the ids the first one did', async () => {
	// The counter is per evaluation, and a caching or concatenating plugin
	// that emits locator.js twice on one page builds a second counter starting
	// at zero — which is the one case the counter cannot see by itself. The
	// serial is probed against the document for exactly this.
	const harness = loadLocator();

	harness.fetchQueue.push( jsonResponse( [ store() ] ) );

	const first = harness.locatorMarkup();
	const started = harness.SLOSM.initAll();

	await started[ 0 ].ready;

	const firstId = field( first ).getAttribute( 'aria-controls' );

	assert.ok( firstId, 'the first locator minted no listbox id' );

	// A second locator arrives after the first script ran — markup loaded into
	// the page, a builder adding a block — and then the file is evaluated
	// again, with a fresh closure, a fresh counter and a fresh WeakSet.
	//
	// Leaflet refuses the container it has already initialised, which is what
	// really happens and is queued here as the error the vendored library
	// throws by name. That is why the *first* locator never re-mints anything:
	// `map: window.L.map( canvas )` is evaluated before `serial:` in the same
	// object literal, so L.map throws before a serial is asked for. The
	// collision this guards against is the new locator's, whose counter has
	// started again at zero.
	const second = harness.locatorMarkup();

	harness.leafletMapErrors.push( new Error( 'Map container is already initialized.' ) );
	harness.fetchQueue.push( jsonResponse( [ store() ] ) );

	harness.evaluate();

	const again = harness.SLOSM.initAll();

	await Promise.all( again.map( ( instance ) => instance.ready ) );

	assert.notStrictEqual(
		field( second ).getAttribute( 'aria-controls' ),
		firstId,
		'a second evaluation of the file minted an id that was already on the page'
	);

	// And the claim itself, rather than one visible consequence of it: no id
	// in this document appears twice. aria-activedescendant and aria-controls
	// are IDREFs, and a duplicate makes both point somewhere undefined.
	const ids = allIds( harness.document );

	assert.ok( ids.length > 1, 'no ids were minted at all, so this proves nothing' );
	assert.strictEqual( new Set( ids ).size, ids.length, 'an id was minted twice into one document: ' + ids.join( ', ' ) );
} );

test( 'an error does not throw away results that are still good', async () => {
	// The same double evaluation, seen from the first locator. Leaflet refuses
	// the container it already owns, initAll() catches it and reports it — and
	// before this, say() wiped the list on the way: a locator that was showing
	// its branch over a working map became "The locations could not be loaded."
	// and nothing else. The map was fine. The row was fine. Only the second
	// <script> tag was wrong.
	const harness = loadLocator();

	harness.fetchQueue.push( jsonResponse( [ store( { name: 'Warsaw shop' } ) ] ) );

	const container = harness.locatorMarkup();
	const started = harness.SLOSM.initAll();

	await started[ 0 ].ready;

	assert.deepStrictEqual( names( container ), [ 'Warsaw shop' ] );

	harness.leafletMapErrors.push( new Error( 'Map container is already initialized.' ) );
	harness.evaluate();

	assert.deepStrictEqual( names( container ), [ 'Warsaw shop' ], 'the error threw away a working result list' );
	assert.strictEqual( message( container ), defaultStrings().loadFailed, 'the error was not reported at all' );

	// Above the results, because it is the reason they may be out of date —
	// and since Task 24c it is above everything, being the status line
	// Shortcode::render() puts between the search row and the map. What it may
	// not be is inside the list, which is no longer a live region and is
	// emptied on every draw.
	const kids = Array.prototype.slice.call( container.childNodes );
	const status = container.querySelector( '.slosm__message' );
	const list = container.querySelector( '.slosm__results' );

	assert.ok( kids.indexOf( status ) > -1 && kids.indexOf( list ) > -1, 'the status line or the list is not a child of the locator' );
	assert.ok( kids.indexOf( status ) < kids.indexOf( list ), 'the message is not read before the results it is about' );
	assert.strictEqual( list.querySelector( '.slosm__message' ), null );

	// And a second failure replaces the sentence rather than stacking another
	// one on top of it.
	harness.leafletMapErrors.push( new Error( 'Map container is already initialized.' ) );
	harness.evaluate();

	assert.strictEqual( container.querySelectorAll( '.slosm__message' ).length, 1 );
	assert.deepStrictEqual( names( container ), [ 'Warsaw shop' ] );
} );

test( 'a highlight left behind by the pointer does not put out the one that took over', async () => {
	// Moving from one row to the next fires the leaving event of the first and
	// the entering event of the second, and nothing guarantees a handler sees
	// them in that order once a browser is busy. Clearing unconditionally puts
	// out a highlight somebody else has just lit.
	const harness = await locator( {
		payload: [
			store( { id: 1, name: 'Warsaw', lat: WARSAW.lat, lng: WARSAW.lng } ),
			store( { id: 2, name: 'Kraków', lat: KRAKOW.lat, lng: KRAKOW.lng } ),
		],
	} );

	fire( rows( harness.container )[ 0 ], 'mouseenter' );
	fire( rows( harness.container )[ 1 ], 'mouseenter' );
	fire( rows( harness.container )[ 0 ], 'mouseleave' );

	assert.deepStrictEqual(
		rows( harness.container ).map( ( row ) => row.classList.contains( 'slosm__result--active' ) ),
		[ false, true ],
		'a late mouseleave put out the row the pointer had moved to'
	);
} );

test( 'arrow keys walk the suggestions and say which one is active', async () => {
	const harness = await locator( { payload: [ store() ] } );

	harness.fetchQueue.push(
		jsonResponse( [ suggestion( 'Warszawa' ), suggestion( 'Warsaw, IN' ), suggestion( 'Warwick' ) ] )
	);

	await suggest( harness, 'war' );

	const input = field( harness.container );

	assert.strictEqual( input.getAttribute( 'aria-expanded' ), 'true' );
	assert.strictEqual( input.getAttribute( 'aria-activedescendant' ), null, 'an option was active before any key' );

	press( harness.container, 'ArrowDown' );

	const active = harness.document.getElementById( input.getAttribute( 'aria-activedescendant' ) );

	assert.strictEqual( active, choices( harness.container )[ 0 ] );
	assert.strictEqual( active.getAttribute( 'aria-selected' ), 'true' );
	assert.ok( active.classList.contains( 'slosm__suggestion--active' ) );

	press( harness.container, 'ArrowDown' );

	assert.strictEqual(
		harness.document.getElementById( input.getAttribute( 'aria-activedescendant' ) ),
		choices( harness.container )[ 1 ]
	);

	// One at a time: the first option gave the state back when it lost it.
	assert.strictEqual( choices( harness.container )[ 0 ].getAttribute( 'aria-selected' ), 'false' );
	assert.strictEqual( choices( harness.container )[ 0 ].classList.contains( 'slosm__suggestion--active' ), false );
} );

test( 'the walk wraps at both ends', async () => {
	const harness = await locator( { payload: [ store() ] } );

	harness.fetchQueue.push( jsonResponse( [ suggestion( 'Warszawa' ), suggestion( 'Warsaw, IN' ) ] ) );

	await suggest( harness, 'war' );

	const input = field( harness.container );
	const at = () => choices( harness.container ).indexOf( harness.document.getElementById( input.getAttribute( 'aria-activedescendant' ) ) );

	press( harness.container, 'ArrowUp' );
	assert.strictEqual( at(), 1, 'ArrowUp from nothing should land on the last option' );

	press( harness.container, 'ArrowDown' );
	assert.strictEqual( at(), 0, 'ArrowDown from the last option should wrap to the first' );

	press( harness.container, 'ArrowUp' );
	assert.strictEqual( at(), 1 );
} );

test( 'the arrow keys and Enter do not also do whatever the browser would have done', async () => {
	// ArrowDown scrolls the page and Enter submits the form the input may be
	// sitting in. Both are wrong here and both are prevented.
	const harness = await locator( { payload: [ store() ] } );

	harness.fetchQueue.push( jsonResponse( [ suggestion( 'Warszawa' ) ] ) );

	await suggest( harness, 'war' );

	assert.strictEqual( press( harness.container, 'ArrowDown' ).defaultPrevented, true );
	assert.strictEqual( press( harness.container, 'ArrowUp' ).defaultPrevented, true );
	assert.strictEqual( press( harness.container, 'Enter' ).defaultPrevented, true );

	// And a key it has no business touching is left alone, so the assertions
	// above are about these keys rather than about a handler that prevents
	// everything.
	assert.strictEqual( press( harness.container, 'a' ).defaultPrevented, false );
} );

test( 'Escape closes the popup and keeps what was typed', async () => {
	const harness = await locator( { payload: [ store() ] } );

	harness.fetchQueue.push( jsonResponse( [ suggestion( 'Warszawa' ) ] ) );

	await suggest( harness, 'war' );
	press( harness.container, 'ArrowDown' );

	const closed = press( harness.container, 'Escape' );

	assert.strictEqual( closed.defaultPrevented, true );
	assert.strictEqual( choices( harness.container ).length, 0 );
	assert.strictEqual( field( harness.container ).getAttribute( 'aria-expanded' ), 'false' );
	assert.strictEqual( field( harness.container ).getAttribute( 'aria-activedescendant' ), null );
	assert.strictEqual( field( harness.container ).value, 'war', 'Escape threw away what was typed' );
} );

test( 'leaving the field closes the popup', async () => {
	const harness = await locator( { payload: [ store() ] } );

	harness.fetchQueue.push( jsonResponse( [ suggestion( 'Warszawa' ) ] ) );

	await suggest( harness, 'war' );

	fire( field( harness.container ), 'blur' );

	assert.strictEqual( choices( harness.container ).length, 0 );
	assert.strictEqual( field( harness.container ).getAttribute( 'aria-expanded' ), 'false' );
} );

test( 'closing the popup inside the debounce window leaks no request', async () => {
	// The hole this closes: type three letters, press Escape before the timer
	// fires, and an unguarded field still sends the lookup a moment later —
	// and reopens the popup over a locator somebody has already dismissed.
	// One leaked upstream request per Escape, blur, Tab or Enter that lands
	// inside the debounce window, on the one path this whole task is built to
	// keep quiet.
	const dismissals = {
		Escape: ( one ) => press( one.container, 'Escape' ),
		blur: ( one ) => fire( field( one.container ), 'blur' ),
		Tab: ( one ) => press( one.container, 'Tab' ),
	};

	for ( const [ name, dismiss ] of Object.entries( dismissals ) ) {
		const harness = await locator( { payload: [ store() ] } );
		const before = harness.fetchCalls.length;

		type( harness.container, 'war' );
		harness.clock.tick( DEBOUNCE - 1 );

		dismiss( harness );

		harness.clock.tick( DEBOUNCE );

		assert.strictEqual( harness.fetchCalls.length, before, name + ' left a request to be sent' );
		assert.strictEqual( harness.clock.pending(), 0, name + ' left the debounce timer armed' );
		assert.strictEqual( choices( harness.container ).length, 0 );
		assert.strictEqual( field( harness.container ).getAttribute( 'aria-expanded' ), 'false' );
	}

	// The control: the same field, not dismissed, does ask.
	const live = await locator( { payload: [ store() ] } );

	live.fetchQueue.push( jsonResponse( [ suggestion( 'Warszawa' ) ] ) );

	const asked = live.fetchCalls.length;

	await suggest( live, 'war' );

	assert.strictEqual( live.fetchCalls.length, asked + 1 );
} );

test( 'an answer that lands after the popup was closed does not reopen it', async () => {
	// The other half of the same close. Escape cannot un-send a request that
	// is already gone, and on a browser with no AbortController nothing
	// cancels it either — so closing has to disown the answer as well as the
	// request, or the popup comes back up on its own a moment after somebody
	// dismissed it.
	const harness = await locator( { payload: [ store() ], withAbortController: false } );
	const slow = deferredResponse();

	harness.fetchQueue.push( slow );

	type( harness.container, 'war' );
	harness.clock.tick( DEBOUNCE );

	const inFlight = harness.instance.pendingSuggest;

	press( harness.container, 'Escape' );

	assert.strictEqual( choices( harness.container ).length, 0 );

	slow.resolve( jsonResponse( [ suggestion( 'Warszawa' ), suggestion( 'Warsaw, IN' ) ] ) );

	await inFlight;

	assert.strictEqual( choices( harness.container ).length, 0, 'the answer reopened a dismissed popup' );
	assert.strictEqual( field( harness.container ).getAttribute( 'aria-expanded' ), 'false' );
} );

test( 'a suggestion label full of markup arrives as text', async () => {
	const hostile = '<img src=x onerror="alert(1)">';
	const harness = await locator( { payload: [ store() ] } );

	harness.fetchQueue.push( jsonResponse( [ suggestion( hostile ) ] ) );

	await suggest( harness, 'war' );

	const option = choices( harness.container )[ 0 ];

	assert.strictEqual( option.textContent, hostile );
	assert.strictEqual( option.children.length, 0, 'the suggestion was parsed as markup' );
} );

/* -------------------------------------------------------------------------
 * Taking a suggestion
 * ---------------------------------------------------------------------- */

test( 'Enter takes the highlighted suggestion without asking /geocode', async () => {
	// The suggestion already carries coordinates. Geocoding its label again
	// would be a second upstream request for an answer already in hand, and
	// the two can disagree.
	const harness = await locator( { payload: [ store( { name: 'Warsaw' } ), store( { id: 2, name: 'Kraków', lat: KRAKOW.lat, lng: KRAKOW.lng } ) ] } );

	harness.fetchQueue.push( jsonResponse( [ suggestion( 'Kraków, Poland', KRAKOW ) ] ) );

	await suggest( harness, 'kra' );

	const before = harness.fetchCalls.length;

	press( harness.container, 'ArrowDown' );
	press( harness.container, 'Enter' );

	await harness.instance.pendingSearch;

	assert.strictEqual( harness.fetchCalls.length, before, 'taking a suggestion asked the server again' );
	assert.strictEqual( field( harness.container ).value, 'Kraków, Poland' );
	assert.strictEqual( choices( harness.container ).length, 0 );

	const view = harness.leafletCalls.setView[ harness.leafletCalls.setView.length - 1 ];

	assert.deepStrictEqual( plain( view.center ), [ KRAKOW.lat, KRAKOW.lng ] );
	assert.deepStrictEqual( names( harness.container ), [ 'Kraków', 'Warsaw' ] );
} );

test( 'clicking a suggestion takes it, and the mousedown does not steal the focus first', async () => {
	const harness = await locator( { payload: [ store() ] } );

	harness.fetchQueue.push( jsonResponse( [ suggestion( 'Kraków, Poland', KRAKOW ) ] ) );

	await suggest( harness, 'kra' );

	const option = choices( harness.container )[ 0 ];

	// A mousedown that is not prevented blurs the input, which closes the
	// popup, which removes the option before the click can land on it.
	assert.strictEqual( fire( option, 'mousedown' ).defaultPrevented, true );

	fire( option, 'click' );

	await harness.instance.pendingSearch;

	assert.strictEqual( field( harness.container ).value, 'Kraków, Poland' );
	assert.deepStrictEqual(
		plain( harness.leafletCalls.setView[ harness.leafletCalls.setView.length - 1 ].center ),
		[ KRAKOW.lat, KRAKOW.lng ]
	);
} );

/* -------------------------------------------------------------------------
 * Submitting: /geocode, then the list
 * ---------------------------------------------------------------------- */

test( 'Enter with nothing highlighted geocodes what was typed', async () => {
	const harness = await locator( { payload: [ store() ] } );

	harness.fetchQueue.push( jsonResponse( { lat: KRAKOW.lat, lng: KRAKOW.lng, label: 'Kraków' } ) );

	field( harness.container ).value = 'Kraków';

	press( harness.container, 'Enter' );

	// The sentence goes up before the request comes back, because the request
	// is the part that takes time.
	assert.strictEqual( message( harness.container ), defaultStrings().searching );

	await harness.instance.pendingSearch;

	const call = harness.fetchCalls[ harness.fetchCalls.length - 1 ];

	assert.ok( call.url.indexOf( '/geocode' ) > -1 );
	assert.strictEqual( new URL( call.url ).searchParams.get( 'q' ), 'Kraków' );
	assert.deepStrictEqual(
		plain( harness.leafletCalls.setView[ harness.leafletCalls.setView.length - 1 ].center ),
		[ KRAKOW.lat, KRAKOW.lng ]
	);
} );

test( 'an empty field asks nothing at all', async () => {
	const harness = await locator( { payload: [ store() ] } );
	const before = harness.fetchCalls.length;

	field( harness.container ).value = '   ';
	press( harness.container, 'Enter' );

	assert.strictEqual( harness.fetchCalls.length, before, 'whitespace was geocoded' );
	assert.strictEqual( message( harness.container ), null );

	// The control. "Enter sent nothing" is true of a script with no key
	// handling at all, which is exactly what this looked like before Task 14;
	// the same field with something in it has to send something.
	harness.fetchQueue.push( jsonResponse( { lat: WARSAW.lat, lng: WARSAW.lng, label: 'Warszawa' } ) );

	field( harness.container ).value = 'Warszawa';
	press( harness.container, 'Enter' );

	await harness.instance.pendingSearch;

	assert.strictEqual( harness.fetchCalls.length, before + 1, 'Enter is not wired to anything at all' );
} );

/* -------------------------------------------------------------------------
 * Task 29c: the submit control
 *
 * Until this task the address field had no visible way to run it: a search
 * happened on Enter or on picking a suggestion, and nothing on screen said so.
 * The button is not a second way of searching — it is the same one, reached by
 * a pointer, and that is what most of these cases are about. runSearch() is
 * the one place that decides what "search now" means, and the button and the
 * key both go through it.
 *
 * What is deliberately NOT here: a <form>. See the mutation document for the
 * argument; the assertions that it stayed out are in tests/test-shortcode.php
 * and in the markup pin in tests/js/harness.test.js, because "no form" is a
 * claim about the server's markup and nothing the front end does can show it.
 * ---------------------------------------------------------------------- */

test( 'the button runs the search the field\'s Enter runs, request for request', async () => {
	// The claim is not "the button searches" — a second code path that also
	// geocoded would satisfy that — but that it is the *same* search. So the
	// same locator, the same typing and the same answer are driven twice, once
	// from the keyboard and once from the button, and everything either one
	// can be seen to do is compared: what went over the wire and in what
	// order, what was said while it was in flight, where the map ended up,
	// what is in the list and what is left in the field.
	const run = async ( go ) => {
		const harness = await locator( {
			payload: [ store(), store( { id: 2, name: 'Kraków', lat: KRAKOW.lat, lng: KRAKOW.lng } ) ],
		} );

		harness.fetchQueue.push( jsonResponse( { lat: KRAKOW.lat, lng: KRAKOW.lng, label: 'Kraków' } ) );

		field( harness.container ).value = 'Kraków';

		go( harness );

		// Read before the await, because "Searching…" is up while the request
		// is in flight and gone by the time the rows are drawn.
		const said = message( harness.container );

		await harness.instance.pendingSearch;

		return {
			said,
			urls: harness.fetchCalls.map( ( call ) => call.url ),
			centre: plain( harness.leafletCalls.setView[ harness.leafletCalls.setView.length - 1 ].center ),
			listed: names( harness.container ),
			left: field( harness.container ).value,
		};
	};

	const typed = await run( ( harness ) => press( harness.container, 'Enter' ) );
	const pressed = await run( ( harness ) => pressButton( harness.container, true ) );

	assert.deepStrictEqual( pressed, typed, 'the button and Enter are two code paths, not one' );

	// The control, and it is the half that makes the comparison mean anything:
	// two locators that both did nothing are also deeply equal. This is what
	// they both did.
	assert.strictEqual( typed.urls.filter( ( url ) => url.indexOf( '/geocode' ) > -1 ).length, 1 );
	assert.strictEqual( typed.said, defaultStrings().searching );
	assert.deepStrictEqual( typed.centre, [ KRAKOW.lat, KRAKOW.lng ] );
	assert.deepStrictEqual( typed.listed, [ 'Kraków', 'Warsaw' ] );
} );

test( 'the button takes the highlighted suggestion, without asking /geocode for it', async () => {
	// The ordering Safari and Firefox on macOS deliver: a button takes no
	// focus when it is clicked, so the field never blurs, the popup never
	// closes, and the click arrives with an option still highlighted. What the
	// visitor is looking at is that option, so that option is the search — and
	// it carries coordinates already, which is why this is also the ordering
	// that spends nothing upstream.
	//
	// Two suggestions on offer and the *second* one highlighted, which is Task
	// 29a's lesson written into the setup: a mutant that took `places[ 0 ]`
	// instead of `places[ active ]` lives for ever in a suite whose every
	// popup has exactly one option in it.
	const harness = await locator( {
		payload: [ store( { name: 'Warsaw' } ), store( { id: 2, name: 'Kraków', lat: KRAKOW.lat, lng: KRAKOW.lng } ) ],
	} );

	harness.fetchQueue.push(
		jsonResponse( [ suggestion( 'Warszawa, Poland', WARSAW ), suggestion( 'Kraków, Poland', KRAKOW ) ] )
	);

	await suggest( harness, 'ra' );

	const before = harness.fetchCalls.length;

	press( harness.container, 'ArrowDown' );
	press( harness.container, 'ArrowDown' );
	pressButton( harness.container, false );

	await harness.instance.pendingSearch;

	assert.strictEqual(
		harness.fetchCalls.length,
		before,
		'the button looked up a suggestion that already had coordinates'
	);

	// The control for that absence: a search really did run, and it ran on the
	// suggestion. "Nothing was asked" is also true of a button wired to
	// nothing at all.
	assert.strictEqual( field( harness.container ).value, 'Kraków, Poland' );
	assert.strictEqual( choices( harness.container ).length, 0 );
	assert.deepStrictEqual(
		plain( harness.leafletCalls.setView[ harness.leafletCalls.setView.length - 1 ].center ),
		[ KRAKOW.lat, KRAKOW.lng ]
	);
	assert.deepStrictEqual( names( harness.container ), [ 'Kraków', 'Warsaw' ] );
} );

test( 'a press that moves the focus first closes the list, and the button searches what was typed', async () => {
	// The other ordering, which is Chrome's and Edge's: mousedown focuses the
	// button, the field blurs, the blur handler closes the popup, and the
	// click lands with nothing highlighted. The answer is then what is in the
	// field, which is the half-typed prefix and not the suggestion that was
	// under the pointer a moment ago.
	//
	// The geocoder is made to answer with Łódź rather than with Kraków on
	// purpose: if this went through the suggestion after all, the map would
	// end up at the suggestion's coordinates and the assertion below would not
	// be able to tell.
	const harness = await locator( { payload: [ store(), store( { id: 2, name: 'Łódź', lat: LODZ.lat, lng: LODZ.lng } ) ] } );

	harness.fetchQueue.push( jsonResponse( [ suggestion( 'Kraków, Poland', KRAKOW ) ] ) );

	await suggest( harness, 'Kra' );

	press( harness.container, 'ArrowDown' );

	harness.fetchQueue.push( jsonResponse( { lat: LODZ.lat, lng: LODZ.lng, label: 'Łódź' } ) );

	const before = harness.fetchCalls.length;

	pressButton( harness.container, true );

	await harness.instance.pendingSearch;

	const sent = harness.fetchCalls.slice( before );

	assert.strictEqual( sent.length, 1 );
	assert.ok( sent[ 0 ].url.indexOf( '/geocode' ) > -1 );
	assert.strictEqual( new URL( sent[ 0 ].url ).searchParams.get( 'q' ), 'Kra' );

	assert.strictEqual( choices( harness.container ).length, 0 );
	assert.strictEqual( field( harness.container ).value, 'Kra', 'the blur took the suggestion anyway' );
	assert.deepStrictEqual(
		plain( harness.leafletCalls.setView[ harness.leafletCalls.setView.length - 1 ].center ),
		[ LODZ.lat, LODZ.lng ]
	);
} );

test( 'the button with the list open and nothing highlighted searches what was typed', async () => {
	// The commonest pointer press of all: somebody types, the popup opens by
	// itself, they ignore it and press the button. Nothing is highlighted —
	// arrowing is what highlights — so there is no chosen place, and the only
	// thing anybody has expressed is the text. It goes to /geocode.
	//
	// This is the third state the popup can be in when a press lands, and it
	// is separate from the two above because it is the one a guard written as
	// "is the popup open" rather than "is an option highlighted" gets wrong.
	const harness = await locator( { payload: [ store(), store( { id: 2, name: 'Łódź', lat: LODZ.lat, lng: LODZ.lng } ) ] } );

	harness.fetchQueue.push( jsonResponse( [ suggestion( 'Kraków, Poland', KRAKOW ) ] ) );

	await suggest( harness, 'Lod' );

	// The control that this is the open-popup state and not the closed one.
	assert.strictEqual( choices( harness.container ).length, 1 );

	harness.fetchQueue.push( jsonResponse( { lat: LODZ.lat, lng: LODZ.lng, label: 'Łódź' } ) );

	const before = harness.fetchCalls.length;

	pressButton( harness.container, false );

	await harness.instance.pendingSearch;

	const sent = harness.fetchCalls.slice( before );

	assert.strictEqual( sent.length, 1 );
	assert.strictEqual( new URL( sent[ 0 ].url ).searchParams.get( 'q' ), 'Lod' );
	assert.deepStrictEqual(
		plain( harness.leafletCalls.setView[ harness.leafletCalls.setView.length - 1 ].center ),
		[ LODZ.lat, LODZ.lng ],
		'the button took a suggestion nobody had highlighted'
	);
	assert.strictEqual( choices( harness.container ).length, 0 );
} );

test( 'Enter with the suggestion list open takes the suggestion, and no second search follows it', async () => {
	// The trap, as a case. Enter in this field was taken before this task
	// existed: with the popup open on a highlighted option it takes that
	// option. Anything that makes the field submittable — a <form> and its
	// implicit submission, or a shared handler that forgot to look — does both
	// things at once: the suggestion is taken and then the text in the field
	// is geocoded on top of it, which is a second upstream lookup and a map
	// that can land somewhere other than what was chosen.
	//
	// Query mode, so that every search this locator runs is a request and
	// "how many searches ran" is a number rather than an inference. In preload
	// mode a second search would be a silent re-filter.
	const query = defaultConfig( { mode: 'query', count: 900, radius: 500, limit: 25 } );

	const harness = await locator( { config: query } );

	harness.fetchQueue.push( jsonResponse( [ suggestion( 'Kraków, Poland', KRAKOW ) ] ) );

	await suggest( harness, 'kra' );

	harness.fetchQueue.push( jsonResponse( [ store( { id: 2, name: 'Kraków', lat: KRAKOW.lat, lng: KRAKOW.lng } ) ] ) );

	const before = harness.fetchCalls.length;

	press( harness.container, 'ArrowDown' );
	press( harness.container, 'Enter' );

	await harness.instance.pendingSearch;

	const sent = harness.fetchCalls.slice( before ).map( ( call ) => call.url );

	assert.strictEqual( sent.length, 1, 'the suggestion was taken and then a second search ran on top of it' );
	assert.ok( sent[ 0 ].indexOf( '/stores' ) > -1 );
	assert.strictEqual( sent.filter( ( url ) => url.indexOf( '/geocode' ) > -1 ).length, 0 );

	assert.strictEqual( field( harness.container ).value, 'Kraków, Poland' );
	assert.strictEqual( choices( harness.container ).length, 0 );
	assert.deepStrictEqual( names( harness.container ), [ 'Kraków' ] );

	// The control, and it is the whole reason the count above is worth
	// asserting: the same locator, the same key, with the list closed, sends
	// two requests and the first of them is the /geocode this one never made.
	const closed = await locator( { config: query } );

	closed.fetchQueue.push( jsonResponse( { lat: KRAKOW.lat, lng: KRAKOW.lng, label: 'Kraków' } ) );
	closed.fetchQueue.push( jsonResponse( [ store( { id: 2, name: 'Kraków', lat: KRAKOW.lat, lng: KRAKOW.lng } ) ] ) );

	const was = closed.fetchCalls.length;

	field( closed.container ).value = 'Kraków';
	press( closed.container, 'Enter' );

	await closed.instance.pendingSearch;

	const also = closed.fetchCalls.slice( was ).map( ( call ) => call.url );

	assert.strictEqual( also.length, 2 );
	assert.ok( also[ 0 ].indexOf( '/geocode' ) > -1 );
	assert.ok( also[ 1 ].indexOf( '/stores' ) > -1 );
} );

test( 'the button belongs to its own locator and not to the first one on the page', async () => {
	// The defect Task 29a's M6 was: a selector run against the document
	// instead of against this locator's element binds every locator's handler
	// to the first control on the page. With one locator per case it is
	// invisible.
	const harness = loadLocator();

	harness.fetchQueue.push( jsonResponse( [ store() ] ) );
	harness.fetchQueue.push( jsonResponse( [ store() ] ) );

	const first = harness.locatorMarkup();
	const second = harness.locatorMarkup();
	const instances = harness.SLOSM.initAll();

	await Promise.all( instances.map( ( instance ) => instance.ready ) );

	harness.fetchQueue.push( jsonResponse( { lat: KRAKOW.lat, lng: KRAKOW.lng, label: 'Kraków' } ) );

	field( first ).value = 'Warszawa';
	field( second ).value = 'Kraków';

	pressButton( second, true );

	await instances[ 1 ].pendingSearch;

	const asked = harness.fetchCalls.filter( ( call ) => call.url.indexOf( '/geocode' ) > -1 );

	assert.strictEqual( asked.length, 1, 'one press ran two searches' );
	assert.strictEqual( new URL( asked[ 0 ].url ).searchParams.get( 'q' ), 'Kraków' );

	// And the control for the absence: the other locator's own button works,
	// so "the first one did not search" is about which button was pressed and
	// not about a page where no button does anything.
	harness.fetchQueue.push( jsonResponse( { lat: WARSAW.lat, lng: WARSAW.lng, label: 'Warszawa' } ) );

	pressButton( first, true );

	await instances[ 0 ].pendingSearch;

	const then = harness.fetchCalls.filter( ( call ) => call.url.indexOf( '/geocode' ) > -1 );

	assert.strictEqual( then.length, 2 );
	assert.strictEqual( new URL( then[ 1 ].url ).searchParams.get( 'q' ), 'Warszawa' );
} );

test( 'the button over an empty field asks for nothing and says nothing', async () => {
	const harness = await locator( { payload: [ store() ] } );
	const before = harness.fetchCalls.length;

	field( harness.container ).value = '   ';

	pressButton( harness.container, true );

	assert.strictEqual( harness.fetchCalls.length, before, 'whitespace was geocoded' );
	assert.strictEqual( message( harness.container ), null, 'the button said "Searching…" over a search it never made' );

	// The control, which is the one this file already keeps for Enter: the
	// same button with something in the field has to send something.
	harness.fetchQueue.push( jsonResponse( { lat: WARSAW.lat, lng: WARSAW.lng, label: 'Warszawa' } ) );

	field( harness.container ).value = 'Warszawa';

	pressButton( harness.container, true );

	await harness.instance.pendingSearch;

	assert.strictEqual( harness.fetchCalls.length, before + 1, 'the button is not wired to anything at all' );
} );

test( 'a button over no search field is pressed without taking the locator down', async () => {
	// Shortcode::filters() emits the two together, so this is markup this
	// plugin does not write — a theme or a page builder that rebuilt the
	// filter bar and kept the button. It is worth a case because the cost of
	// getting it wrong is not a dead button: it is a TypeError inside the
	// click handler of a locator whose map is otherwise fine.
	const blind = await locator( { withSearch: false, payload: [ store() ] } );
	const before = blind.fetchCalls.length;

	assert.ok( blind.instance, 'the locator did not start, so nothing below is about the button' );
	assert.ok( submitButton( blind.container ), 'the fixture built no button to press' );
	assert.strictEqual( field( blind.container ), null );

	// Nothing catches for this: the harness dispatches straight into the
	// handler, so a TypeError in there comes back out of fire() and fails the
	// case here rather than being reported as a quiet no-op.
	fire( submitButton( blind.container ), 'click' );

	assert.strictEqual( blind.fetchCalls.length, before );
	assert.strictEqual( message( blind.container ), null );
	assert.deepStrictEqual( blind.consoleCalls.error, [], 'the press was survived by way of an error' );

	// The control: the same press, on the markup this plugin does write.
	const whole = await locator( { payload: [ store() ] } );

	whole.fetchQueue.push( jsonResponse( { lat: KRAKOW.lat, lng: KRAKOW.lng, label: 'Kraków' } ) );

	const was = whole.fetchCalls.length;

	field( whole.container ).value = 'Kraków';
	pressButton( whole.container, true );

	await whole.instance.pendingSearch;

	assert.strictEqual( whole.fetchCalls.length, was + 1 );
} );

test( 'a locator rendered with no button at all still searches on Enter', async () => {
	// The other half of the guard above, and the release this plugin shipped
	// before Task 29c: no button, and the field is driven from the keyboard.
	// Nothing about the button may be allowed to become a requirement.
	const harness = await locator( { withSubmit: false, payload: [ store() ] } );

	assert.strictEqual( submitButton( harness.container ), null, 'the fixture built a button, so this proves nothing' );

	harness.fetchQueue.push( jsonResponse( { lat: KRAKOW.lat, lng: KRAKOW.lng, label: 'Kraków' } ) );

	field( harness.container ).value = 'Kraków';
	press( harness.container, 'Enter' );

	await harness.instance.pendingSearch;

	const call = harness.fetchCalls[ harness.fetchCalls.length - 1 ];

	assert.ok( call.url.indexOf( '/geocode' ) > -1 );
	assert.deepStrictEqual(
		plain( harness.leafletCalls.setView[ harness.leafletCalls.setView.length - 1 ].center ),
		[ KRAKOW.lat, KRAKOW.lng ]
	);
} );

test( 'a search cancels the suggestion request it overtook', async () => {
	// Otherwise the suggestions for a half-typed prefix land on top of the
	// results a moment after the search has finished.
	const harness = await locator( { payload: [ store() ] } );
	const slow = deferredResponse();

	harness.fetchQueue.push( slow );
	harness.fetchQueue.push( jsonResponse( { lat: KRAKOW.lat, lng: KRAKOW.lng, label: 'Kraków' } ) );

	type( harness.container, 'Kraków' );
	harness.clock.tick( DEBOUNCE );

	const pending = harness.fetchCalls[ harness.fetchCalls.length - 1 ];
	const suggestChain = harness.instance.pendingSuggest;

	press( harness.container, 'Enter' );

	await harness.instance.pendingSearch;

	assert.strictEqual( pending.signal.aborted, true, 'the suggestion request outlived the search' );

	slow.resolve( jsonResponse( [ suggestion( 'Kraków, Poland', KRAKOW ) ] ) );

	await suggestChain;

	assert.strictEqual( choices( harness.container ).length, 0, 'the popup reopened after the search' );
} );

test( 'a search that has been replaced does not come back and take the map', async () => {
	// Somebody searches Warszawa, waits, gives up and searches Kraków. The
	// Warszawa answer lands last. Without this, the list re-sorts around
	// Warszawa and the map ends up there — on the search they abandoned.
	const harness = await locator( {
		payload: [
			store( { id: 1, name: 'Warsaw shop', lat: WARSAW.lat, lng: WARSAW.lng } ),
			store( { id: 2, name: 'Kraków shop', lat: KRAKOW.lat, lng: KRAKOW.lng } ),
		],
	} );

	const slow = deferredResponse();

	harness.fetchQueue.push( slow );
	harness.fetchQueue.push( jsonResponse( { lat: KRAKOW.lat, lng: KRAKOW.lng, label: 'Kraków' } ) );

	field( harness.container ).value = 'Warszawa';
	press( harness.container, 'Enter' );

	const abandoned = harness.instance.pendingSearch;
	const firstCall = harness.fetchCalls[ harness.fetchCalls.length - 1 ];

	assert.ok( firstCall.signal, '/geocode was sent with no AbortSignal at all' );

	field( harness.container ).value = 'Kraków';
	press( harness.container, 'Enter' );

	await harness.instance.pendingSearch;

	assert.strictEqual( firstCall.signal.aborted, true, 'the abandoned lookup was never cancelled' );
	assert.deepStrictEqual( names( harness.container ), [ 'Kraków shop', 'Warsaw shop' ] );

	const views = harness.leafletCalls.setView.length;

	slow.resolve( jsonResponse( { lat: WARSAW.lat, lng: WARSAW.lng, label: 'Warszawa' } ) );

	await abandoned;

	assert.deepStrictEqual(
		names( harness.container ),
		[ 'Kraków shop', 'Warsaw shop' ],
		'the abandoned search re-sorted the list after the one that replaced it'
	);
	assert.strictEqual( harness.leafletCalls.setView.length, views, 'the abandoned search moved the map' );

	// Nothing was reported. A search this locator cancelled itself is not a
	// failure, and the traces a failure leaves that nothing later clears are
	// the error class and the console.
	assert.strictEqual( harness.container.classList.contains( 'slosm--error' ), false );
	assert.deepStrictEqual( harness.consoleCalls.warn, [], 'a cancelled lookup was written to the console' );
} );

test( 'without an AbortController the abandoned search still loses', async () => {
	// The token on its own, with nothing cancelling the request for it. Same
	// split as the suggestion path: take the token out and this fails while
	// the case above still passes.
	const harness = await locator( {
		withAbortController: false,
		payload: [
			store( { id: 1, name: 'Warsaw shop', lat: WARSAW.lat, lng: WARSAW.lng } ),
			store( { id: 2, name: 'Kraków shop', lat: KRAKOW.lat, lng: KRAKOW.lng } ),
		],
	} );

	const slow = deferredResponse();

	harness.fetchQueue.push( slow );
	harness.fetchQueue.push( jsonResponse( { lat: KRAKOW.lat, lng: KRAKOW.lng, label: 'Kraków' } ) );

	field( harness.container ).value = 'Warszawa';
	press( harness.container, 'Enter' );

	const abandoned = harness.instance.pendingSearch;

	field( harness.container ).value = 'Kraków';
	press( harness.container, 'Enter' );

	await harness.instance.pendingSearch;

	const views = harness.leafletCalls.setView.length;

	slow.resolve( jsonResponse( { lat: WARSAW.lat, lng: WARSAW.lng, label: 'Warszawa' } ) );

	await abandoned;

	assert.deepStrictEqual( names( harness.container ), [ 'Kraków shop', 'Warsaw shop' ] );
	assert.strictEqual( harness.leafletCalls.setView.length, views );
} );

test( 'a query-mode search abandoned between its two requests says nothing', async () => {
	// Query mode is two requests per search, so there are two windows rather
	// than one. This is the second: /geocode has answered, /stores is in
	// flight, and a new search starts. The dropped /stores request must not
	// leave "The locations could not be loaded." on a locator that is busy
	// answering something else.
	const harness = await locator( { config: defaultConfig( { mode: 'query', count: 900 } ) } );
	const slowStores = deferredResponse();

	harness.fetchQueue.push( jsonResponse( { lat: WARSAW.lat, lng: WARSAW.lng, label: 'Warszawa' } ) );
	harness.fetchQueue.push( slowStores );
	harness.fetchQueue.push( jsonResponse( { lat: KRAKOW.lat, lng: KRAKOW.lng, label: 'Kraków' } ) );
	harness.fetchQueue.push( jsonResponse( [ store( { id: 2, name: 'Kraków shop', lat: KRAKOW.lat, lng: KRAKOW.lng, distance: 0.2 } ) ] ) );

	field( harness.container ).value = 'Warszawa';
	press( harness.container, 'Enter' );

	const abandoned = harness.instance.pendingSearch;

	// Let /geocode settle, so that the request now in flight is the /stores
	// one. Waited for by what it does rather than by a count of turns.
	await until( () => 2 === harness.fetchCalls.length, 'the first search reached its /stores request' );

	const storesCall = harness.fetchCalls[ 1 ];

	assert.ok( storesCall.signal, '/stores was sent with no AbortSignal at all' );

	field( harness.container ).value = 'Kraków';
	press( harness.container, 'Enter' );

	await harness.instance.pendingSearch;

	assert.deepStrictEqual( names( harness.container ), [ 'Kraków shop' ] );

	slowStores.resolve( jsonResponse( [ store( { id: 1, name: 'Warsaw shop', lat: WARSAW.lat, lng: WARSAW.lng, distance: 0.1 } ) ] ) );

	await abandoned;

	assert.deepStrictEqual(
		names( harness.container ),
		[ 'Kraków shop' ],
		'the abandoned search replaced the list of the one that overtook it'
	);

	// The message a failure would have left is *transient* — the next search
	// says "searching…" over it and then draws its rows — so asserting on it
	// here would pass against code that reported the cancellation loudly and
	// was simply overtaken again. What a failure leaves behind that nothing
	// clears is the error class and the console line, so those are what this
	// checks.
	assert.strictEqual( message( harness.container ), found( 1 ) );
	assert.strictEqual(
		harness.container.classList.contains( 'slosm--error' ),
		false,
		'a request this locator cancelled itself was reported as a failure'
	);
	assert.deepStrictEqual( harness.consoleCalls.warn, [], 'a cancellation was written to the console as an error' );

	// And the request really was cancelled on the wire, rather than merely
	// disowned when it came back. A dropped /stores answer that was never
	// cancelled is a worker the site spent on nobody.
	assert.strictEqual( storesCall.signal.aborted, true, '/stores was left running after its search was abandoned' );
} );

test( 'without an AbortController a stale /stores answer is still dropped', async () => {
	// Query mode, the fallback browser, and the window the token exists for:
	// nothing cancelled the first search's /stores request, so it comes back
	// successfully — with a list for the place somebody stopped looking at.
	const harness = await locator( {
		config: defaultConfig( { mode: 'query', count: 900 } ),
		withAbortController: false,
	} );

	const slowStores = deferredResponse();

	harness.fetchQueue.push( jsonResponse( { lat: WARSAW.lat, lng: WARSAW.lng, label: 'Warszawa' } ) );
	harness.fetchQueue.push( slowStores );
	harness.fetchQueue.push( jsonResponse( { lat: KRAKOW.lat, lng: KRAKOW.lng, label: 'Kraków' } ) );
	harness.fetchQueue.push( jsonResponse( [ store( { id: 2, name: 'Kraków shop', lat: KRAKOW.lat, lng: KRAKOW.lng, distance: 0.2 } ) ] ) );

	field( harness.container ).value = 'Warszawa';
	press( harness.container, 'Enter' );

	const abandoned = harness.instance.pendingSearch;

	await until( () => 2 === harness.fetchCalls.length, 'the first search reached its /stores request' );

	assert.strictEqual( harness.fetchCalls[ 1 ].signal, null );

	field( harness.container ).value = 'Kraków';
	press( harness.container, 'Enter' );

	await harness.instance.pendingSearch;

	assert.deepStrictEqual( names( harness.container ), [ 'Kraków shop' ] );

	slowStores.resolve( jsonResponse( [ store( { id: 1, name: 'Warsaw shop', lat: WARSAW.lat, lng: WARSAW.lng, distance: 0.1 } ) ] ) );

	await abandoned;

	assert.deepStrictEqual(
		names( harness.container ),
		[ 'Kraków shop' ],
		'a list fetched for an abandoned search was drawn over the current one'
	);
} );

test( 'an abandoned search that fails late says nothing about it', async () => {
	// The half of the catch that the abort does not cover. On the fallback
	// browser nothing cancels the first lookup, so it is still out there and
	// can come back a *failure* rather than an answer — and a failure for a
	// question nobody is asking any more must not put an error on a locator
	// that is happily showing the answer to the next one.
	const harness = await locator( {
		withAbortController: false,
		payload: [
			store( { id: 1, name: 'Warsaw shop', lat: WARSAW.lat, lng: WARSAW.lng } ),
			store( { id: 2, name: 'Kraków shop', lat: KRAKOW.lat, lng: KRAKOW.lng } ),
		],
	} );

	const slow = deferredResponse();

	harness.fetchQueue.push( slow );
	harness.fetchQueue.push( jsonResponse( { lat: KRAKOW.lat, lng: KRAKOW.lng, label: 'Kraków' } ) );

	field( harness.container ).value = 'Warszawa';
	press( harness.container, 'Enter' );

	const abandoned = harness.instance.pendingSearch;

	field( harness.container ).value = 'Kraków';
	press( harness.container, 'Enter' );

	await harness.instance.pendingSearch;

	// The connection drops, long after this lookup stopped mattering. A
	// rejection rather than a 502 on purpose: a 502 is a *response*, and a
	// response is dropped one step earlier by the check before the body is
	// read. Only a rejection reaches the catch, which is where the other half
	// of this guard lives — so this is the input that tells the two apart.
	slow.reject( new Error( 'network down' ) );

	await abandoned;

	assert.strictEqual( message( harness.container ), found( 2 ), 'an abandoned lookup reported its failure' );
	assert.deepStrictEqual( names( harness.container ), [ 'Kraków shop', 'Warsaw shop' ] );
	assert.deepStrictEqual( harness.consoleCalls.warn, [] );
} );

test( 'an abandoned query-mode list that fails late says nothing either', async () => {
	// The same again one request further in: /geocode answered, /stores was
	// still out when the next search started, and it fails rather than
	// arriving. Nothing cancelled it, so only the token can tell.
	const harness = await locator( {
		config: defaultConfig( { mode: 'query', count: 900 } ),
		withAbortController: false,
	} );

	const slowStores = deferredResponse();

	harness.fetchQueue.push( jsonResponse( { lat: WARSAW.lat, lng: WARSAW.lng, label: 'Warszawa' } ) );
	harness.fetchQueue.push( slowStores );
	harness.fetchQueue.push( jsonResponse( { lat: KRAKOW.lat, lng: KRAKOW.lng, label: 'Kraków' } ) );
	harness.fetchQueue.push( jsonResponse( [ store( { id: 2, name: 'Kraków shop', lat: KRAKOW.lat, lng: KRAKOW.lng, distance: 0.2 } ) ] ) );

	field( harness.container ).value = 'Warszawa';
	press( harness.container, 'Enter' );

	const abandoned = harness.instance.pendingSearch;

	await until( () => 2 === harness.fetchCalls.length, 'the first search reached its /stores request' );

	field( harness.container ).value = 'Kraków';
	press( harness.container, 'Enter' );

	await harness.instance.pendingSearch;

	slowStores.reject( new Error( 'network down' ) );

	await abandoned;

	assert.strictEqual(
		harness.container.classList.contains( 'slosm--error' ),
		false,
		'an abandoned list request marked the locator broken'
	);
	assert.strictEqual( message( harness.container ), found( 1 ) );
	assert.deepStrictEqual( names( harness.container ), [ 'Kraków shop' ] );
	assert.deepStrictEqual( harness.consoleCalls.warn, [] );
} );

test( 'an address that matches nothing says so, and does not move the map', async () => {
	const harness = await locator( { payload: [ store() ] } );
	const views = harness.leafletCalls.setView.length;

	harness.fetchQueue.push(
		jsonResponse( { code: 'slosm_geocode_no_results' }, { ok: false, status: 404 } )
	);

	field( harness.container ).value = 'nowhere at all';
	press( harness.container, 'Enter' );

	await harness.instance.pendingSearch;

	assert.strictEqual( message( harness.container ), defaultStrings().searchNoMatch );
	assert.strictEqual( harness.leafletCalls.setView.length, views, 'the map was recentred on nothing' );
} );

test( 'each geocode failure the REST controller distinguishes says something different', async () => {
	// Rest_Controller::ERROR_STATUS maps seven geocoder failures onto four
	// statuses, and the difference is the only thing a visitor can act on.
	const cases = [
		{ status: 400, expected: 'searchNoMatch' },
		{ status: 404, expected: 'searchNoMatch' },
		{ status: 429, expected: 'searchBusy' },
		{ status: 502, expected: 'searchFailed' },
	];

	for ( const one of cases ) {
		const harness = await locator( { payload: [ store() ] } );

		harness.fetchQueue.push( jsonResponse( { code: 'x' }, { ok: false, status: one.status } ) );

		field( harness.container ).value = 'Kraków';
		press( harness.container, 'Enter' );

		await harness.instance.pendingSearch;

		assert.strictEqual(
			message( harness.container ),
			defaultStrings()[ one.expected ],
			'a ' + one.status + ' did not read as ' + one.expected
		);
	}
} );

test( 'the statuses those messages are keyed to are the ones the REST controller sends', () => {
	// The front end maps 400, 404, 429 and everything else. That is a claim
	// about includes/class-rest-controller.php, so it is read rather than
	// remembered: a status added to ERROR_STATUS that is none of those falls
	// into "and everything else", which is the safe direction, but a change to
	// one of these three would silently change what a visitor is told.
	const source = pluginSource( 'includes', 'class-rest-controller.php' );
	const front = pluginSource( 'assets', 'js', 'locator.js' );

	assert.ok( source.includes( "'empty_query'     => 400," ) );
	assert.ok( source.includes( "'no_results'      => 404," ) );
	assert.ok( source.includes( "'rate_limited'    => 429," ) );

	// Both halves, or this pins one side of an agreement to itself. The front
	// end has to carry the same three numbers; a skeleton that maps nothing
	// fails here rather than passing on the PHP alone.
	[ '400', '404', '429' ].forEach( ( status ) => {
		assert.ok(
			front.includes( status ),
			'assets/js/locator.js knows nothing about ' + status + ', so the two sides are not pinned to each other'
		);
	} );
} );

test( 'a network failure and a broken body both read as a failed lookup', async () => {
	for ( const payload of [ new Error( 'network down' ), brokenJsonResponse() ] ) {
		const harness = await locator( { payload: [ store() ] } );

		harness.fetchQueue.push( payload );

		field( harness.container ).value = 'Kraków';
		press( harness.container, 'Enter' );

		await harness.instance.pendingSearch;

		assert.strictEqual( message( harness.container ), defaultStrings().searchFailed );
	}
} );

test( 'a geocode answer with unusable coordinates is a failure, not a map at NaN', async () => {
	const harness = await locator( { payload: [ store() ] } );
	const views = harness.leafletCalls.setView.length;

	harness.fetchQueue.push( jsonResponse( { lat: '52.2', lng: null, label: 'Somewhere' } ) );

	field( harness.container ).value = 'Somewhere';
	press( harness.container, 'Enter' );

	await harness.instance.pendingSearch;

	assert.strictEqual( message( harness.container ), defaultStrings().searchFailed );
	assert.strictEqual( harness.leafletCalls.setView.length, views );
} );

/* -------------------------------------------------------------------------
 * Sorting: preload in the browser, query on the server
 * ---------------------------------------------------------------------- */

test( 'a preloaded list is sorted in the browser with no second request', async () => {
	const harness = await locator( {
		payload: [
			store( { id: 1, name: 'Warsaw', lat: WARSAW.lat, lng: WARSAW.lng } ),
			store( { id: 2, name: 'Kraków', lat: KRAKOW.lat, lng: KRAKOW.lng } ),
			store( { id: 3, name: 'Łódź', lat: LODZ.lat, lng: LODZ.lng } ),
		],
	} );

	const before = harness.fetchCalls.length;

	harness.fetchQueue.push( jsonResponse( { lat: KRAKOW.lat, lng: KRAKOW.lng, label: 'Kraków' } ) );

	field( harness.container ).value = 'Kraków';
	press( harness.container, 'Enter' );

	await harness.instance.pendingSearch;

	assert.strictEqual( harness.fetchCalls.length, before + 1, 'preload mode asked /stores for what it already had' );
	assert.deepStrictEqual( names( harness.container ), [ 'Kraków', 'Łódź', 'Warsaw' ] );
} );

test( 'the browser sorts with the same arithmetic the server would have used', async () => {
	// The distance shown is Geo's, and Geo is pinned against class-geo.php by
	// geo-crosscheck.test.js. This case is the other half: that the front end
	// really calls it rather than approximating with its own formula.
	const harness = await locator( {
		payload: [ store( { id: 2, name: 'Kraków', lat: KRAKOW.lat, lng: KRAKOW.lng } ) ],
	} );

	harness.fetchQueue.push( jsonResponse( { lat: WARSAW.lat, lng: WARSAW.lng, label: 'Warszawa' } ) );

	field( harness.container ).value = 'Warszawa';
	press( harness.container, 'Enter' );

	await harness.instance.pendingSearch;

	const expected = harness.SLOSM.Geo.distance( WARSAW.lat, WARSAW.lng, KRAKOW.lat, KRAKOW.lng, 'km' );
	const shown = rows( harness.container )[ 0 ].querySelector( '.slosm__result-distance' ).textContent;

	assert.strictEqual( shown, expected.toFixed( 1 ) + ' km' );
} );

test( 'a preloaded list drops what is outside the radius, the way /stores would have', async () => {
	// The same site above the preload threshold would ask /stores, which
	// filters by radius on the server. A locator that showed every location on
	// the site below the threshold and only the near ones above it would
	// behave differently either side of a number nobody can see.
	const harness = await locator( {
		config: defaultConfig( { radius: 50 } ),
		payload: [
			store( { id: 1, name: 'Warsaw', lat: WARSAW.lat, lng: WARSAW.lng } ),
			store( { id: 2, name: 'Kraków', lat: KRAKOW.lat, lng: KRAKOW.lng } ),
		],
	} );

	harness.fetchQueue.push( jsonResponse( { lat: WARSAW.lat, lng: WARSAW.lng, label: 'Warszawa' } ) );

	field( harness.container ).value = 'Warszawa';
	press( harness.container, 'Enter' );

	await harness.instance.pendingSearch;

	assert.deepStrictEqual( names( harness.container ), [ 'Warsaw' ] );

	// The map shows what the list shows. A pin for a location that is not in
	// the results is a pin somebody can click that answers to no row.
	const onMap = harness.leafletCalls.map[ 0 ].map.layers.filter( ( layer ) => 'marker' === layer.kind );

	assert.strictEqual( onMap.length, 1, 'a location outside the radius is still pinned to the map' );
	assert.deepStrictEqual( plain( onMap[ 0 ].latlng ), [ WARSAW.lat, WARSAW.lng ] );
} );

test( 'the limit caps the rows shown and never what the search can see', async () => {
	// This is the bug that made the limit a fetch limit, written as a case.
	//
	// /stores returns the whole list in title order — find_all() sorts by
	// title ASC — and this locator shows two rows. The nearest branch to
	// Kraków is 'Gimel', which is *third* alphabetically and therefore not in
	// the two rows on screen before the search. A locator that had only
	// fetched `limit` rows could not possibly find it: on a real site that is
	// the shop two streets away, missing because its name begins with M.
	const harness = await locator( {
		config: defaultConfig( { radius: 500, limit: 2 } ),
		payload: [
			store( { id: 1, name: 'Aleph', lat: WARSAW.lat, lng: WARSAW.lng } ),
			store( { id: 2, name: 'Beth', lat: LODZ.lat, lng: LODZ.lng } ),
			store( { id: 3, name: 'Gimel', lat: KRAKOW.lat, lng: KRAKOW.lng } ),
		],
	} );

	// Two rows, as asked, and the third is not among them.
	assert.deepStrictEqual( names( harness.container ), [ 'Aleph', 'Beth' ] );

	harness.fetchQueue.push( jsonResponse( { lat: KRAKOW.lat, lng: KRAKOW.lng, label: 'Kraków' } ) );

	field( harness.container ).value = 'Kraków';
	press( harness.container, 'Enter' );

	await harness.instance.pendingSearch;

	// Still two rows, and the nearest one is the row that was not on screen a
	// moment ago.
	assert.deepStrictEqual( names( harness.container ), [ 'Gimel', 'Beth' ] );
} );

test( 'a preloaded search that matches nothing nearby says so rather than showing an empty list', async () => {
	const harness = await locator( {
		config: defaultConfig( { radius: 10 } ),
		payload: [ store( { id: 2, name: 'Kraków', lat: KRAKOW.lat, lng: KRAKOW.lng } ) ],
	} );

	harness.fetchQueue.push( jsonResponse( { lat: WARSAW.lat, lng: WARSAW.lng, label: 'Warszawa' } ) );

	field( harness.container ).value = 'Warszawa';
	press( harness.container, 'Enter' );

	await harness.instance.pendingSearch;

	assert.strictEqual( message( harness.container ), defaultStrings().noResults );
	assert.strictEqual( rows( harness.container ).length, 0 );

	// And the map went where it was asked to go, which is the difference
	// between "nothing here" and "the search did nothing".
	assert.deepStrictEqual(
		plain( harness.leafletCalls.setView[ harness.leafletCalls.setView.length - 1 ].center ),
		[ WARSAW.lat, WARSAW.lng ]
	);
} );

test( 'query mode asks /stores for the neighbourhood, with every bound it was given', async () => {
	const harness = await locator( {
		config: defaultConfig( { mode: 'query', count: 900, radius: 25, limit: 10, units: 'mi', category: 'Bakeries' } ),
	} );

	harness.fetchQueue.push( jsonResponse( { lat: WARSAW.lat, lng: WARSAW.lng, label: 'Warszawa' } ) );
	harness.fetchQueue.push(
		jsonResponse( [
			store( { id: 2, name: 'Kraków', lat: KRAKOW.lat, lng: KRAKOW.lng, distance: 157.2 } ),
			store( { id: 1, name: 'Warsaw', lat: WARSAW.lat, lng: WARSAW.lng, distance: 0.4 } ),
		] )
	);

	field( harness.container ).value = 'Warszawa';
	press( harness.container, 'Enter' );

	await harness.instance.pendingSearch;

	const call = harness.fetchCalls[ harness.fetchCalls.length - 1 ];
	const params = new URL( call.url ).searchParams;

	assert.ok( call.url.indexOf( '/stores' ) > -1 );
	assert.strictEqual( params.get( 'lat' ), String( WARSAW.lat ) );
	assert.strictEqual( params.get( 'lng' ), String( WARSAW.lng ) );
	assert.strictEqual( params.get( 'radius' ), '25' );
	assert.strictEqual( params.get( 'limit' ), '10' );
	assert.strictEqual( params.get( 'unit' ), 'mi' );
	assert.strictEqual( params.get( 'category' ), 'Bakeries' );

	// Sorted here too, rather than trusted to arrive in order: the two modes
	// must present the same list for the same data.
	assert.deepStrictEqual( names( harness.container ), [ 'Warsaw', 'Kraków' ] );
	assert.strictEqual(
		rows( harness.container )[ 0 ].querySelector( '.slosm__result-distance' ).textContent,
		'0.4 mi',
		'the unit the search was made in was not the unit it was shown in'
	);
} );

test( 'a query-mode search whose /stores request fails says the locations did not arrive', async () => {
	const harness = await locator( { config: defaultConfig( { mode: 'query', count: 900 } ) } );

	harness.fetchQueue.push( jsonResponse( { lat: WARSAW.lat, lng: WARSAW.lng, label: 'Warszawa' } ) );
	harness.fetchQueue.push( new Error( 'network down' ) );

	field( harness.container ).value = 'Warszawa';
	press( harness.container, 'Enter' );

	await harness.instance.pendingSearch;

	assert.strictEqual( message( harness.container ), defaultStrings().loadFailed );
} );

/* -------------------------------------------------------------------------
 * The results list itself
 * ---------------------------------------------------------------------- */

test( 'every result is a clone of the template, filled in as text', async () => {
	const harness = await locator( {
		payload: [ store( { name: 'Warsaw', address: 'Nowy Świat 1', city: 'Warszawa', categories: [ 'Shops', 'Cafés' ] } ) ],
	} );

	const row = rows( harness.container )[ 0 ];

	assert.ok( row, 'the preloaded list rendered no rows at all' );
	assert.strictEqual( row.querySelector( '.slosm__result-name' ).textContent, 'Warsaw' );
	assert.strictEqual( row.querySelector( '.slosm__result-address' ).textContent, 'Nowy Świat 1' );
	assert.strictEqual( row.querySelector( '.slosm__result-city' ).textContent, 'Warszawa' );
	assert.ok( row.querySelector( '.slosm__result-open' ), 'the row was not cloned from the template' );

	assert.deepStrictEqual(
		row.querySelector( '.slosm__result-categories' ).children.map( ( item ) => item.textContent ),
		[ 'Shops', 'Cafés' ]
	);

	// No distance before a search: there is nothing to be far from, and a
	// zero would be a claim about a point nobody named.
	assert.strictEqual( row.querySelector( '.slosm__result-distance' ).textContent, '' );
} );

test( 'a store title full of markup reaches the list as text', async () => {
	// A title written by a user with unfiltered_html legitimately contains
	// html. PHP escaped it for an attribute on the way into the page and none
	// of that reaches json, so the browser gets the raw characters. The
	// harness throws on innerHTML in either direction, so a row built by
	// parsing would fail with that error rather than this assertion.
	const hostile = '<img src=x onerror="alert(1)"><script>alert(2)</script>';
	const harness = await locator( { payload: [ store( { name: hostile, city: hostile, categories: [ hostile ] } ) ] } );

	const row = rows( harness.container )[ 0 ];

	assert.strictEqual( row.querySelector( '.slosm__result-name' ).textContent, hostile );
	assert.strictEqual( row.querySelector( '.slosm__result-name' ).children.length, 0 );
	assert.strictEqual( row.querySelector( '.slosm__result-city' ).children.length, 0 );
	assert.strictEqual(
		row.querySelector( '.slosm__result-categories' ).children[ 0 ].children.length,
		0,
		'a category name was parsed as markup'
	);
} );

test( 'a payload field that is not a string leaves its cell empty', async () => {
	// Store::to_lean_array() emits strings, so this is not the route
	// misbehaving: it is a filter on the way out, or a cached payload written
	// by an older version of this plugin. `node.textContent = {}` writes
	// "[object Object]" into somebody's results list, which is worse than an
	// empty cell because it looks like data.
	const harness = await locator( {
		payload: [ store( { name: 42, city: { nope: true }, address: null, categories: [ 7, '', 'Shops' ] } ) ],
	} );

	const row = rows( harness.container )[ 0 ];

	assert.ok( row, 'the row was not rendered at all' );
	assert.strictEqual( row.querySelector( '.slosm__result-name' ).textContent, '' );
	assert.strictEqual( row.querySelector( '.slosm__result-city' ).textContent, '' );
	assert.strictEqual( row.querySelector( '.slosm__result-address' ).textContent, '' );
	assert.deepStrictEqual(
		row.querySelector( '.slosm__result-categories' ).children.map( ( tag ) => tag.textContent ),
		[ 'Shops' ],
		'a category that was not a string was rendered anyway'
	);

	// A categories field that is not a list at all, which is the other shape a
	// filter produces.
	const odd = await locator( { payload: [ store( { categories: 'Shops' } ) ] } );

	assert.strictEqual( rows( odd.container )[ 0 ].querySelector( '.slosm__result-categories' ).children.length, 0 );

	// The control: the same cells do carry a string when one arrives, so the
	// emptiness above is about the type check and not about a row that never
	// gets filled in.
	const fine = await locator( { payload: [ store( { name: 'Warsaw' } ) ] } );

	assert.strictEqual( rows( fine.container )[ 0 ].querySelector( '.slosm__result-name' ).textContent, 'Warsaw' );
} );

test( 'a suggestion with no usable coordinates is not offered', async () => {
	// An option that cannot move the map is an option that does nothing when
	// it is chosen, which is worse than one that is not there.
	const harness = await locator( { payload: [ store() ] } );

	harness.fetchQueue.push(
		jsonResponse( [
			{ label: 'Nowhere', lat: null, lng: null },
			{ label: 'Somewhere else', lat: '52.2', lng: 21.0 },
			// And the other half of the same check: a row with coordinates and
			// nothing to show for them. An option with no text is an option
			// nobody can read or choose.
			{ lat: KRAKOW.lat, lng: KRAKOW.lng },
			{ label: '', lat: KRAKOW.lat, lng: KRAKOW.lng },
			suggestion( 'Kraków, Poland', KRAKOW ),
		] )
	);

	await suggest( harness, 'kra' );

	assert.deepStrictEqual(
		choices( harness.container ).map( ( option ) => option.textContent ),
		[ 'Kraków, Poland' ]
	);
} );

/* -------------------------------------------------------------------------
 * Rows a person cannot tell apart, which are one row
 *
 * Task 26's first real install typed `Kraków` and got five rows, three of them
 * the identical string. Photon's index holds a city, an administrative area
 * and a relation, and Geocoder::suggestion_label() renders all three out of the
 * same name/city/state/country parts. The records differ; the text does not;
 * and the text is what a person chooses from.
 *
 * Every case here is about the *text*. None of them asserts anything about the
 * places behind it, because the front end deliberately claims nothing about
 * them — see showSuggestions()'s docblock.
 * ---------------------------------------------------------------------- */

/** The three coordinates Photon returns for one Polish city, near but distinct. */
const KRAKOW_CITY = { lat: 50.0614, lng: 19.9366 };
const KRAKOW_ADMIN = { lat: 50.0647, lng: 19.945 };
const KRAKOW_RELATION = { lat: 50.0521, lng: 19.9448 };

test( 'three records that render one string are one row', async () => {
	// The defect, as it was seen: five rows, three of them indistinguishable,
	// and the two genuinely different answers pushed below the fold.
	const harness = await locator( { payload: [ store() ] } );

	harness.fetchQueue.push(
		jsonResponse( [
			suggestion( 'Kraków, województwo małopolskie, Polska', KRAKOW_CITY ),
			suggestion( 'Kraków, województwo małopolskie, Polska', KRAKOW_ADMIN ),
			suggestion( 'Kraków, województwo małopolskie, Polska', KRAKOW_RELATION ),
			suggestion( 'Karkowo, województwo pomorskie, Polska', LODZ ),
			suggestion( 'Krąków, województwo łódzkie, Polska', WARSAW ),
		] )
	);

	await suggest( harness, 'Kraków' );

	assert.deepStrictEqual(
		choices( harness.container ).map( ( option ) => option.textContent ),
		[
			'Kraków, województwo małopolskie, Polska',
			'Karkowo, województwo pomorskie, Polska',
			'Krąków, województwo łódzkie, Polska',
		],
		'the identical rows were not folded, or folding took the different ones with them'
	);

	// Kept the *first*, which is the half a fold that kept the last would pass
	// without: the surviving row carries the city record's point, not the
	// relation's. Nothing here says the first record is the better place —
	// only that "keep the first" is what the code does.
	press( harness.container, 'ArrowDown' );
	press( harness.container, 'Enter' );

	await harness.instance.pendingSearch;

	assert.deepStrictEqual(
		plain( harness.leafletCalls.setView[ harness.leafletCalls.setView.length - 1 ].center ),
		[ KRAKOW_CITY.lat, KRAKOW_CITY.lng ],
		'the row that survived the fold was not the first of the three'
	);
} );

test( 'two records that render two strings are two rows', async () => {
	// The control for the case above, and the reason the fold is allowed to be
	// exact string equality. These two differ by one letter and these two by a
	// capital; all four are four different lines to somebody reading them, and
	// a fold that normalised anything would answer with fewer.
	const harness = await locator( { payload: [ store() ] } );

	harness.fetchQueue.push(
		jsonResponse( [
			suggestion( 'Kraków, województwo małopolskie, Polska', KRAKOW_CITY ),
			suggestion( 'Krąków, województwo małopolskie, Polska', KRAKOW_ADMIN ),
			suggestion( 'kraków, województwo małopolskie, polska', KRAKOW_RELATION ),
			suggestion( 'Kraków, Województwo Małopolskie, Polska', LODZ ),
		] )
	);

	await suggest( harness, 'Kraków' );

	assert.deepStrictEqual(
		choices( harness.container ).map( ( option ) => option.textContent ),
		[
			'Kraków, województwo małopolskie, Polska',
			'Krąków, województwo małopolskie, Polska',
			'kraków, województwo małopolskie, polska',
			'Kraków, Województwo Małopolskie, Polska',
		],
		'rows a person can tell apart were folded together'
	);
} );

test( 'a repeat with a different row between it is folded too', async () => {
	// Not only *consecutive* duplicates, which is what the Task 28 plan entry
	// said. Nothing documents an ordering guarantee from Photon, and a second
	// identical line is the same defect wherever it lands.
	const harness = await locator( { payload: [ store() ] } );

	harness.fetchQueue.push(
		jsonResponse( [
			suggestion( 'Kraków, Polska', KRAKOW_CITY ),
			suggestion( 'Karkowo, Polska', LODZ ),
			suggestion( 'Kraków, Polska', KRAKOW_RELATION ),
		] )
	);

	await suggest( harness, 'Kraków' );

	assert.deepStrictEqual(
		choices( harness.container ).map( ( option ) => option.textContent ),
		[ 'Kraków, Polska', 'Karkowo, Polska' ],
		'a duplicate that was not adjacent to its twin was left on screen'
	);
} );

test( 'a label that happens to name a JavaScript built-in folds like any other', async () => {
	// Why the labels already on screen are held in an array rather than in an
	// object used as a lookup. A label is whatever the geocoding service sent,
	// and `{}[ 'constructor' ]` answers with a function whether or not anything
	// ever put one there — so an object would fold the *first* of these rows
	// away and show a list with a hole in it.
	//
	// Nothing says a place is called this. What the case pins is that the
	// membership test answers about the rows this function has shown and about
	// nothing else.
	const harness = await locator( { payload: [ store() ] } );

	harness.fetchQueue.push(
		jsonResponse( [
			suggestion( 'constructor', KRAKOW_CITY ),
			suggestion( 'constructor', KRAKOW_ADMIN ),
			suggestion( '__proto__', KRAKOW_RELATION ),
			suggestion( '__proto__', LODZ ),
			suggestion( 'Warszawa', WARSAW ),
		] )
	);

	await suggest( harness, 'con' );

	assert.deepStrictEqual(
		choices( harness.container ).map( ( option ) => option.textContent ),
		[ 'constructor', '__proto__', 'Warszawa' ]
	);
} );

test( 'the first row folding keeps is the first one that could be shown', async () => {
	// The fold runs after the coordinate check rather than before it, so "the
	// first" means the first row that would have reached the screen. A record
	// that was never going to be offered cannot take the place of one that
	// was: that would fold a list down to nothing visible.
	const harness = await locator( { payload: [ store() ] } );

	harness.fetchQueue.push(
		jsonResponse( [
			{ label: 'Kraków, Polska', lat: null, lng: null },
			suggestion( 'Kraków, Polska', KRAKOW_ADMIN ),
		] )
	);

	await suggest( harness, 'Kraków' );

	assert.deepStrictEqual(
		choices( harness.container ).map( ( option ) => option.textContent ),
		[ 'Kraków, Polska' ],
		'the showable row was folded away by one that could not be shown'
	);

	press( harness.container, 'ArrowDown' );
	press( harness.container, 'Enter' );

	await harness.instance.pendingSearch;

	assert.deepStrictEqual(
		plain( harness.leafletCalls.setView[ harness.leafletCalls.setView.length - 1 ].center ),
		[ KRAKOW_ADMIN.lat, KRAKOW_ADMIN.lng ]
	);
} );

test( 'folding never empties a popup that had something to show', async () => {
	// The question the fold has to answer for itself: can it leave nothing?
	// It cannot. Keeping the first of every distinct label means a list with
	// anything showable in it still shows something, however many of the rows
	// repeat.
	const harness = await locator( { payload: [ store() ] } );

	harness.fetchQueue.push(
		jsonResponse( [
			suggestion( 'Kraków, Polska', KRAKOW_CITY ),
			suggestion( 'Kraków, Polska', KRAKOW_ADMIN ),
			suggestion( 'Kraków, Polska', KRAKOW_RELATION ),
		] )
	);

	await suggest( harness, 'Kraków' );

	assert.strictEqual( choices( harness.container ).length, 1, 'three identical rows did not fold to one' );
	assert.strictEqual(
		field( harness.container ).getAttribute( 'aria-expanded' ),
		'true',
		'the popup closed over a list it had a row for'
	);

	// The control, because "one option, popup open" is not worth much without
	// the state that really is empty: a list nothing in it could be shown from
	// still closes the popup, and that path is the old one rather than the fold.
	harness.fetchQueue.push(
		jsonResponse( [
			{ label: 'Kraków, Polska', lat: null, lng: null },
			{ label: 'Kraków, Polska', lat: null, lng: null },
		] )
	);

	await suggest( harness, 'Krakow' );

	assert.strictEqual( choices( harness.container ).length, 0 );
	assert.strictEqual( field( harness.container ).getAttribute( 'aria-expanded' ), 'false' );
} );

test( 'a remembered answer is folded when it is put back', async () => {
	// Where the fold lives, asserted rather than described. It is in what puts
	// the rows on screen, not in what fetches them — so the one-entry memo,
	// which replays a list that never goes near the server again, is folded by
	// the same code. A fold done on the way in from fetch() would pass every
	// case above and fail this one.
	const harness = await locator( { payload: [ store() ] } );

	harness.fetchQueue.push(
		jsonResponse( [
			suggestion( 'Kraków, Polska', KRAKOW_CITY ),
			suggestion( 'Kraków, Polska', KRAKOW_ADMIN ),
			suggestion( 'Karkowo, Polska', LODZ ),
		] )
	);

	await suggest( harness, 'krak' );

	assert.strictEqual( choices( harness.container ).length, 2 );

	const before = harness.fetchCalls.length;

	// A typo corrected inside one window: `krakx` never becomes a request, so
	// `krak` comes back off the memo rather than off the wire.
	type( harness.container, 'krakx' );
	harness.clock.tick( 100 );
	type( harness.container, 'krak' );
	harness.clock.tick( DEBOUNCE );

	await harness.instance.pendingSuggest;

	assert.strictEqual( harness.fetchCalls.length, before, 'the memo was not the thing that answered' );
	assert.deepStrictEqual(
		choices( harness.container ).map( ( option ) => option.textContent ),
		[ 'Kraków, Polska', 'Karkowo, Polska' ],
		'the remembered list was put back unfolded'
	);
} );

test( "the debounce is the 600 ms the server's skip needs", async () => {
	// The number itself, pinned against the source, because every other case
	// in this file measures time in DEBOUNCE and would follow the constant
	// wherever it went.
	//
	// Why 600 and not the 300 this shipped with is on the constant, and it is
	// not the reason the plan gave. Rest_Controller::get_suggest() does not
	// queue behind the rate limiter: on a cache miss it asks
	// Geocoder::would_throttle() and answers 204 without requesting anything.
	// So an extra request does not slow the next one down, it makes the next
	// one return nothing — and a debounce that fires once per pause longer
	// than itself is how the gap between two requests is pushed past the
	// second the server measures.
	const source = pluginSource( 'assets', 'js', 'locator.js' );
	const found = source.match( /\n\tvar SUGGEST_DELAY = (\d+);\n/ );

	assert.ok( found, 'SUGGEST_DELAY is not declared where this case can read it' );
	assert.strictEqual( Number( found[ 1 ] ), DEBOUNCE, 'the file and this suite disagree about the debounce' );
	assert.strictEqual( DEBOUNCE, 600 );

	// And the server's half of the same number, so the comment there cannot
	// go on describing a client that has moved.
	assert.ok(
		pluginSource( 'includes', 'class-rest-controller.php' ).indexOf( '600 ms since Task 28a' ) > -1,
		'get_suggest() still describes the debounce it was written against'
	);
} );

test( 'a second search replaces the rows rather than piling on top of them', async () => {
	const harness = await locator( {
		payload: [
			store( { id: 1, name: 'Warsaw', lat: WARSAW.lat, lng: WARSAW.lng } ),
			store( { id: 2, name: 'Kraków', lat: KRAKOW.lat, lng: KRAKOW.lng } ),
		],
	} );

	assert.strictEqual( rows( harness.container ).length, 2 );

	harness.fetchQueue.push( jsonResponse( { lat: KRAKOW.lat, lng: KRAKOW.lng, label: 'Kraków' } ) );

	field( harness.container ).value = 'Kraków';
	press( harness.container, 'Enter' );

	await harness.instance.pendingSearch;

	assert.strictEqual( rows( harness.container ).length, 2 );
	assert.deepStrictEqual( names( harness.container ), [ 'Kraków', 'Warsaw' ] );
} );

test( 'the row template survives a message being written over the list', async () => {
	// say() clears the list with textContent = '', and the <template> is a
	// sibling of the <ol> rather than a child of it. If it ever moves inside,
	// the first "searching…" deletes the thing every later row is cloned from
	// — and the symptom is an empty list with no error anywhere.
	const harness = await locator( { payload: [] } );

	assert.strictEqual( message( harness.container ), defaultStrings().noResults );

	harness.fetchQueue.push( jsonResponse( { lat: WARSAW.lat, lng: WARSAW.lng, label: 'Warszawa' } ) );

	field( harness.container ).value = 'Warszawa';
	press( harness.container, 'Enter' );

	assert.strictEqual( message( harness.container ), defaultStrings().searching );

	await harness.instance.pendingSearch;

	assert.ok( harness.container.querySelector( '.slosm__row' ), 'the row template was destroyed' );
} );

test( 'a locator whose markup lost its template keeps its map and its pins', async () => {
	// A page builder that re-emits the markup, or a content filter that strips
	// an element it does not know. There is nothing to clone, so there are no
	// rows — the map, the pins and the search all still work.
	//
	// What this case does NOT assert, because the code does not do it: that
	// anything is said. A map with pins beside an empty panel is a locator
	// half working, and the honest options were a message keyed to a string
	// that does not exist ("this map's markup is incomplete") or the one that
	// does and would be a lie ("its settings are missing or unreadable" — they
	// were read, and the map started). Neither is worth a translated string
	// for markup no shortcode of this plugin emits, so the case is named after
	// what it checks rather than after something nobody wrote. The earlier
	// name said "says so", and nothing said anything.
	const harness = await locator( { payload: [ store() ], withTemplate: false } );

	assert.strictEqual( harness.leafletCalls.marker.length, 1 );
	assert.strictEqual( rows( harness.container ).length, 0 );
	assert.strictEqual( harness.consoleCalls.error.length, 0 );

	// The control: the same fixture WITH its template renders a row. Without
	// this, "no rows" is satisfied by a script that never renders one.
	const working = await locator( { payload: [ store() ] } );

	assert.strictEqual( rows( working.container ).length, 1, 'nothing renders a row even when the template is there' );
} );

test( 'a field that is no longer inside its label keeps its popup inside the locator', async () => {
	// Not markup this plugin emits: it is what a page builder or a content
	// filter can leave behind. Walking up to a grandparent would put the popup
	// outside the locator entirely — on the shortcode's own markup, in the
	// page — where no stylesheet of this plugin's reaches it.
	const harness = await locator( { payload: [ store() ], withLabel: false } );

	harness.fetchQueue.push( jsonResponse( [ suggestion( 'Warszawa' ) ] ) );

	await suggest( harness, 'war' );

	const list = popup( harness.container );

	assert.ok( list, 'no popup was built at all' );
	assert.strictEqual( list.parentNode.classList.contains( 'slosm__filters' ), true );
	assert.strictEqual( choices( harness.container ).length, 1 );

	// And it is still the element aria-controls names.
	assert.strictEqual( harness.document.getElementById( field( harness.container ).getAttribute( 'aria-controls' ) ), list );
} );

test( 'a locator with no search field at all still draws its map', async () => {
	const harness = await locator( { payload: [ store() ], withSearch: false } );

	assert.strictEqual( harness.leafletCalls.map.length, 1 );
	assert.strictEqual( popup( harness.container ), null );
	assert.strictEqual( rows( harness.container ).length, 1 );
} );

/* -------------------------------------------------------------------------
 * The two-way highlight
 * ---------------------------------------------------------------------- */

test( 'hovering a row highlights its marker, and leaving clears it', async () => {
	const harness = await locator( {
		payload: [
			store( { id: 1, name: 'Warsaw', lat: WARSAW.lat, lng: WARSAW.lng } ),
			store( { id: 2, name: 'Kraków', lat: KRAKOW.lat, lng: KRAKOW.lng } ),
		],
	} );

	const row = rows( harness.container )[ 1 ];
	const marker = harness.leafletCalls.marker[ 1 ].layer;

	fire( row, 'mouseenter' );

	assert.ok( row.classList.contains( 'slosm__result--active' ) );
	assert.ok( marker.getElement().classList.contains( 'slosm__marker--active' ) );

	// And only that one: the first row's marker was left alone.
	assert.strictEqual(
		harness.leafletCalls.marker[ 0 ].layer.getElement().classList.contains( 'slosm__marker--active' ),
		false
	);

	fire( row, 'mouseleave' );

	assert.strictEqual( row.classList.contains( 'slosm__result--active' ), false );
	assert.strictEqual( marker.getElement().classList.contains( 'slosm__marker--active' ), false );
} );

test( 'focusing a row highlights it too, which is the keyboard half of the same thing', async () => {
	const harness = await locator( { payload: [ store() ] } );
	const row = rows( harness.container )[ 0 ];

	row.querySelector( '.slosm__result-open' ).focus();

	assert.ok( row.classList.contains( 'slosm__result--active' ) );
	assert.ok( harness.leafletCalls.marker[ 0 ].layer.getElement().classList.contains( 'slosm__marker--active' ) );

	row.querySelector( '.slosm__result-open' ).blur();

	assert.strictEqual( row.classList.contains( 'slosm__result--active' ), false );
} );

test( 'hovering a marker highlights its row', async () => {
	const harness = await locator( {
		payload: [
			store( { id: 1, name: 'Warsaw', lat: WARSAW.lat, lng: WARSAW.lng } ),
			store( { id: 2, name: 'Kraków', lat: KRAKOW.lat, lng: KRAKOW.lng } ),
		],
	} );

	harness.leafletCalls.marker[ 1 ].layer.fire( 'mouseover' );

	assert.ok( rows( harness.container )[ 1 ].classList.contains( 'slosm__result--active' ) );
	assert.strictEqual( rows( harness.container )[ 0 ].classList.contains( 'slosm__result--active' ), false );

	harness.leafletCalls.marker[ 1 ].layer.fire( 'mouseout' );

	assert.strictEqual( rows( harness.container )[ 1 ].classList.contains( 'slosm__result--active' ), false );
} );

test( 'focusing a marker highlights its row, the way Leaflet makes a marker focusable', async () => {
	const harness = await locator( { payload: [ store() ] } );

	fire( harness.leafletCalls.marker[ 0 ].layer.getElement(), 'focus' );

	assert.ok( rows( harness.container )[ 0 ].classList.contains( 'slosm__result--active' ) );

	fire( harness.leafletCalls.marker[ 0 ].layer.getElement(), 'blur' );

	assert.strictEqual( rows( harness.container )[ 0 ].classList.contains( 'slosm__result--active' ), false );
} );

test( 'only one thing is highlighted at a time', async () => {
	const harness = await locator( {
		payload: [
			store( { id: 1, name: 'Warsaw', lat: WARSAW.lat, lng: WARSAW.lng } ),
			store( { id: 2, name: 'Kraków', lat: KRAKOW.lat, lng: KRAKOW.lng } ),
		],
	} );

	fire( rows( harness.container )[ 0 ], 'mouseenter' );
	fire( rows( harness.container )[ 1 ], 'mouseenter' );

	assert.deepStrictEqual(
		rows( harness.container ).map( ( row ) => row.classList.contains( 'slosm__result--active' ) ),
		[ false, true ]
	);
} );

test( 'the highlight follows the rows across a search rather than pointing at a marker that is gone', async () => {
	const harness = await locator( {
		payload: [
			store( { id: 1, name: 'Warsaw', lat: WARSAW.lat, lng: WARSAW.lng } ),
			store( { id: 2, name: 'Kraków', lat: KRAKOW.lat, lng: KRAKOW.lng } ),
		],
	} );

	harness.fetchQueue.push( jsonResponse( { lat: KRAKOW.lat, lng: KRAKOW.lng, label: 'Kraków' } ) );

	field( harness.container ).value = 'Kraków';
	press( harness.container, 'Enter' );

	await harness.instance.pendingSearch;

	// Row 0 is now Kraków, and the marker it highlights has to be Kraków's.
	const row = rows( harness.container )[ 0 ];

	fire( row, 'mouseenter' );

	const lit = harness.leafletCalls.marker.filter(
		( call ) => call.layer.getElement() && call.layer.getElement().classList.contains( 'slosm__marker--active' )
	);

	assert.strictEqual( lit.length, 1 );
	assert.deepStrictEqual( plain( lit[ 0 ].latlng ), [ KRAKOW.lat, KRAKOW.lng ] );
} );

/* -------------------------------------------------------------------------
 * Discipline: what Task 14 must NOT have built
 * ---------------------------------------------------------------------- */

test( 'every name that has left the "still nobody\'s" list is pinned somewhere else', async () => {
	// This case used to be a list of names locator.js was forbidden to contain,
	// and Task 29a took the last two off it: the radius and limit selects are
	// wired to the search now. An empty forbidden list asserts nothing, and
	// deleting the case would lose the rule the list was kept by — "names leave
	// this list when the task they belong to builds them, and are re-pinned from
	// the other direction in that task's own file rather than deleted". So the
	// case now asserts the rule instead of the list.
	//
	// Code, not prose, for the reason the scan always had: locator.js warns Task
	// 16 that Leaflet's own bindPopup and divIcon write their arguments with
	// innerHTML, and a scan of the raw text would read that warning as a use.
	const source = codeOnly( pluginSource( 'assets', 'js', 'locator.js' ) );

	// The control first, so this is not an assertion about an empty file: the
	// surface Task 14 does own is really there, in code.
	[ 'AbortController', 'aria-activedescendant', 'suggest', 'geocode' ].forEach( ( expected ) => {
		assert.ok( source.includes( expected ), 'locator.js does not contain ' + expected + ', so a scan for what is absent proves nothing' );
	} );

	// Every name that has ever left, and the file that took it on. Each has to
	// be in locator.js — it was let out because the front end started using it
	// — and in the file that owns it, because a name nobody re-pinned is a name
	// the scan quietly stopped watching.
	//
	// `navigator.geolocation` is pinned in harness.js rather than in a case
	// file, and that is accurate rather than a shortcut: the cases reach the
	// API through the stub, and harness.js is the one place that names the
	// global the locator asks for.
	const departed = {
		markerClusterGroup: 'nearby.test.js',
		'navigator.geolocation': 'harness.js',
		getCurrentPosition: 'nearby.test.js',
		slosm__locate: 'nearby.test.js',
		slosm__category: 'nearby.test.js',
		bindPopup: 'popup.test.js',
		openPopup: 'popup.test.js',
		'result-directions': 'popup.test.js',
		slosm__radius: 'filters.test.js',
		slosm__limit: 'filters.test.js',
	};

	Object.keys( departed ).forEach( ( name ) => {
		assert.ok(
			source.includes( name ),
			'locator.js no longer contains ' + name + ', so the file that pins it is testing a name nothing uses'
		);
		assert.ok(
			pluginSource( 'tests', 'js', departed[ name ] ).includes( name ),
			name + ' left the forbidden list without being pinned in tests/js/' + departed[ name ]
		);
	} );
} );

test( 'a theme class on the buttons does not take them out of the script\'s reach', async () => {
	// Task 30b2 lets a site add its own class to both buttons, so the class
	// attribute the server writes is no longer the single literal this
	// fixture builds. Nothing in locator.js compares a className — both
	// lookups are querySelector( '.slosm__submit' ) and '.slosm__locate' —
	// and this is that claim driven rather than read, with the extra class in
	// place *before* initAll() so the wiring sees what a real page would.
	const harness = loadLocator( {} );

	harness.fetchQueue.push( jsonResponse( [ store() ] ) );

	const container = harness.locatorMarkup( { config: defaultConfig( { radius: 500 } ) } );
	const submit = container.querySelector( '.slosm__submit' );
	const locate = container.querySelector( '.slosm__locate' );

	assert.ok( submit && locate, 'the fixture built no buttons, so this case proves nothing' );

	submit.className += ' bricks-button';
	locate.className += ' bricks-button';

	const instance = harness.SLOSM.initAll()[ 0 ];

	await instance.ready;

	harness.fetchQueue.push( jsonResponse( { lat: KRAKOW.lat, lng: KRAKOW.lng, label: 'Kraków' } ) );

	field( container ).value = 'Kraków';
	fire( submit, 'click' );

	await instance.pendingSearch;

	assert.strictEqual(
		harness.fetchCalls.filter( ( call ) => call.url.indexOf( '/geocode' ) > -1 ).length,
		1,
		'the search button stopped searching once it carried a second class'
	);

	// The other button, which asks the browser rather than the network. One
	// call to the geolocation stub is the whole of what a press does before
	// permission comes back.
	fire( locate, 'click' );

	assert.strictEqual(
		harness.geolocationCalls.length,
		1,
		'the "use my location" button stopped asking once it carried a second class'
	);
} );

test( 'a search frames what it found, not only the address it was handed', async () => {
	// Task 32, the second of the three places the map was left pointing at
	// something other than what it was showing. locate() sets the view on the
	// geocoded point at the *configured* zoom, and the configured zoom knows
	// nothing about the radius the search ran at: measured on a real page,
	// zoom 12 over a 480px map is about ±5.6km, while the radius select was on
	// 50km. "Locations found: 2", at 8.9km and 18.7km, and neither pin inside
	// the viewport.
	//
	// The address still leads — the map goes there first, while the list is in
	// flight, and it stays inside the frame afterwards, because a frame drawn
	// around the results alone would move the map off the address somebody
	// typed. It is the zoom that stops being a guess.
	const harness = await locator( {
		payload: [ store( { id: 2, name: 'Łódź', lat: LODZ.lat, lng: LODZ.lng } ) ],
	} );

	const framed = harness.leafletCalls.fitBounds.length;

	harness.fetchQueue.push( jsonResponse( { lat: WARSAW.lat, lng: WARSAW.lng, label: 'Warszawa' } ) );

	field( harness.container ).value = 'Warszawa';
	press( harness.container, 'Enter' );

	await harness.instance.pendingSearch;

	assert.deepStrictEqual(
		plain( harness.leafletCalls.setView[ harness.leafletCalls.setView.length - 1 ].center ),
		[ WARSAW.lat, WARSAW.lng ],
		'the map stopped going to the address first'
	);

	assert.strictEqual(
		harness.leafletCalls.fitBounds.length,
		framed + 1,
		'the search left the map at the configured zoom, with its own results off the edge'
	);

	assert.deepStrictEqual(
		plain( harness.leafletCalls.fitBounds[ harness.leafletCalls.fitBounds.length - 1 ].bounds ),
		[
			[ WARSAW.lat, WARSAW.lng ],
			[ LODZ.lat, LODZ.lng ],
		]
	);
} );
