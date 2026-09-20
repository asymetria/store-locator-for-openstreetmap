/**
 * Task 15: "near me", the category filter and the marker clustering.
 *
 * Three features that share one seam — they all end up drawing the same map
 * from the same list — and the cases below are grouped by which end of that
 * seam they hold.
 *
 * WHAT "PROPORTIONATE" MEANS HERE, AND WHY IT IS A CASE RATHER THAN A COMMENT
 * ==========================================================================
 * Most visitors decline a location prompt. That is not a failure, it is the
 * ordinary answer, and a locator that turns red and throws away a perfectly
 * good list of branches over it is a locator that looks broken to the majority
 * of the people who press the button. So a denial and a failure are two
 * different outcomes here and the difference is asserted three ways: a
 * different sentence, a different class on the container, and — the one that
 * actually matters — whether the results that were on screen are still there
 * afterwards.
 *
 * WHAT THESE CASES CANNOT REACH
 * =============================
 * tests/js/harness.js states it in full and two lines of it are worth
 * repeating, because a green run here says less than it looks.
 *
 * The cluster group in the harness clusters nothing. There is no grid, no
 * zoom and no projection anywhere in this suite, so "these three pins really
 * did collapse into one bubble" is a manual check. What is testable, and what
 * is tested, is every decision the plugin makes: whether a group is built at
 * all, what goes into it, what comes out of it, and what the label on a bubble
 * is made of.
 *
 * The geolocation stub answers synchronously where a browser answers later,
 * and it has no permission model at all — no prompt, no remembered decision,
 * no secure-context rule. It can say "granted", "denied" or "nothing yet", and
 * that is the whole of it.
 */

'use strict';

const { test } = require( 'node:test' );
const assert = require( 'node:assert' );

const {
	codeOnly,
	defaultConfig,
	defaultStrings,
	deferredResponse,
	fire,
	jsonResponse,
	loadLocator,
	pluginSource,
	plain,
	position,
	positionError,
} = require( './harness.js' );

/** Warsaw, Łódź and Kraków, as everywhere else in this suite. */
const WARSAW = { lat: 52.2297, lng: 21.0122 };
const LODZ = { lat: 51.7592, lng: 19.456 };
const KRAKOW = { lat: 50.0647, lng: 19.945 };

/** The Geolocation API's own error codes. */
const PERMISSION_DENIED = 1;
const POSITION_UNAVAILABLE = 2;
const TIMEOUT = 3;

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
 * A locator, already preloaded with `payload`.
 *
 * A radius of 500 by default, for the reason search.test.js gives: the
 * fixtures are Polish cities two to three hundred kilometres apart and the
 * shortcode's own default of 50 would filter every case about ordering down to
 * one row.
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
		withCategory: settings.withCategory,
		withLocate: settings.withLocate,
		withResults: settings.withResults,
		withSearch: settings.withSearch,
	} );

	const instances = harness.SLOSM.initAll();

	if ( instances[ 0 ] && instances[ 0 ].ready ) {
		await instances[ 0 ].ready;
	}

	return Object.assign( harness, { container, instance: instances[ 0 ] || null } );
}

/** @returns {object|null} The "use my location" button. */
function button( container ) {
	return container.querySelector( '.slosm__locate' );
}

/** @returns {object|null} The category select. */
function select( container ) {
	return container.querySelector( '.slosm__category' );
}

/** @returns {Array<string>} Every option's text, in order. */
function optionLabels( container ) {
	return select( container ).children.map( ( option ) => option.textContent );
}

/** @returns {Array<string>} Every option's value, in order. */
function optionValues( container ) {
	return select( container ).children.map( ( option ) => option.value );
}

/** @returns {Array} The result rows on screen. */
function rows( container ) {
	return container.querySelectorAll( '.slosm__result' );
}

/** @returns {Array<string>} The name cell of every result row, in order. */
function names( container ) {
	return rows( container ).map( ( row ) => row.querySelector( '.slosm__result-name' ).textContent );
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
 * Chooses a category the way a pointer would: value, then `change`.
 *
 * The harness models no relationship between a select's value and its options
 * — see its header — so this is a case saying what the browser would have
 * done, not a case proving the browser would have allowed it.
 *
 * @param {object} container The locator.
 * @param {string} value     The category name, or '' for all of them.
 * @returns {object} The event object the handlers saw.
 */
function choose( container, value ) {
	const control = select( container );

	control.value = value;

	return fire( control, 'change' );
}

/**
 * Presses the "use my location" button.
 *
 * @param {object} container The locator.
 * @returns {object} The event object the handlers saw.
 */
function press( container ) {
	return fire( button( container ), 'click' );
}

/**
 * Waits for something the microtask queue is about to do, and no longer.
 *
 * Same helper as search.test.js, and for the same reason: a literal number of
 * turns is a guess that hangs the whole run when it is one too few.
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

/* -------------------------------------------------------------------------
 * "Near me": asking
 * ---------------------------------------------------------------------- */

test( 'nothing is asked of the browser until the button is pressed', async () => {
	const harness = await locator( { payload: [ store() ] } );

	// The default is auto_locate off, and Task 11's reasoning for that default
	// is that a map which demands a location on page load is a map most people
	// dismiss before they have read anything on the page.
	assert.strictEqual( harness.geolocationCalls.length, 0, 'the locator prompted for a position unasked' );

	// The control: the button is wired, so the silence above is a decision
	// rather than a locator that never listened.
	harness.geolocationQueue.push( position( KRAKOW.lat, KRAKOW.lng ) );
	press( harness.container );

	assert.strictEqual( harness.geolocationCalls.length, 1 );
} );

test( 'auto_locate asks exactly once, and only when it is on', async () => {
	const off = await locator( { payload: [ store() ] } );

	assert.strictEqual( off.geolocationCalls.length, 0 );

	const on = await locator( {
		payload: [ store() ],
		config: defaultConfig( { radius: 500, autoLocate: true } ),
	} );

	assert.strictEqual( on.geolocationCalls.length, 1, 'auto_locate never asked' );
} );

test( 'the request carries a timeout, so an answer that never comes is not forever', async () => {
	const harness = await locator( { payload: [ store() ] } );

	press( harness.container );

	const options = harness.geolocationCalls[ 0 ].options;

	assert.ok( options, 'the position was requested with no options at all' );
	assert.strictEqual( typeof options.timeout, 'number' );
	assert.ok( 0 < options.timeout && options.timeout <= 30000, 'the timeout is not a usable number of milliseconds' );

	// Nothing in this suite can make that timeout fire — it is the browser's
	// timer, not the page's — so what it buys is asserted where it can be: the
	// error path below, which a timeout arrives through.
} );

test( 'a prompt still open says what it is waiting for, and destroys nothing', async () => {
	const harness = await locator( {
		payload: [ store( { id: 1, name: 'Warsaw' } ), store( { id: 2, name: 'Kraków', lat: KRAKOW.lat, lng: KRAKOW.lng } ) ],
	} );

	// Nothing queued: the permission prompt is on screen and the visitor has
	// not decided. That is where most of them are for a second or two.
	press( harness.container );

	assert.strictEqual( message( harness.container ), defaultStrings().locating );

	// And the two branches they could already see are still there. A status
	// that emptied the list would mean every declined prompt costs somebody
	// the results they were reading.
	assert.deepStrictEqual( names( harness.container ), [ 'Warsaw', 'Kraków' ] );
	assert.strictEqual( harness.container.classList.contains( 'slosm--error' ), false );
} );

test( 'a browser with no geolocation says so and asks nothing', async () => {
	const harness = await locator( { payload: [ store() ], withGeolocation: false } );

	press( harness.container );

	assert.strictEqual( message( harness.container ), defaultStrings().locationUnsupported );

	// Not an error. The browser cannot do it; nothing is broken and nothing is
	// anybody's fault, so the locator does not go red over it.
	assert.strictEqual( harness.container.classList.contains( 'slosm--error' ), false );
	assert.deepStrictEqual( names( harness.container ), [ 'Warsaw' ] );
} );

/* -------------------------------------------------------------------------
 * "Near me": a denial is not a failure
 * ---------------------------------------------------------------------- */

test( 'a denied permission says something proportionate and keeps every result', async () => {
	const harness = await locator( {
		payload: [ store( { id: 1, name: 'Warsaw' } ), store( { id: 2, name: 'Kraków', lat: KRAKOW.lat, lng: KRAKOW.lng } ) ],
	} );

	harness.geolocationQueue.push( positionError( PERMISSION_DENIED ) );
	press( harness.container );

	assert.strictEqual( message( harness.container ), defaultStrings().locationDenied );

	// The three assertions this whole feature turns on.
	assert.strictEqual(
		harness.container.classList.contains( 'slosm--error' ),
		false,
		'declining a location prompt turned the locator into an error state'
	);
	assert.deepStrictEqual( names( harness.container ), [ 'Warsaw', 'Kraków' ], 'a denial threw the results away' );
	assert.strictEqual( harness.leafletCalls.setView.length, 0, 'a denial moved the map anyway' );
} );

test( 'a position that could not be worked out is a failure, and says a different thing', async () => {
	// The other side of the same button, and the reason the denial case above
	// proves anything: a locator that said one sentence for every outcome would
	// pass the denial case by saying the failure sentence.
	const unavailable = await locator( { payload: [ store() ] } );

	unavailable.geolocationQueue.push( positionError( POSITION_UNAVAILABLE ) );
	press( unavailable.container );

	assert.strictEqual( message( unavailable.container ), defaultStrings().locationFailed );
	assert.ok( unavailable.container.classList.contains( 'slosm--error' ) );

	const timedOut = await locator( { payload: [ store() ] } );

	timedOut.geolocationQueue.push( positionError( TIMEOUT ) );
	press( timedOut.container );

	assert.strictEqual( timedOut.container.classList.contains( 'slosm--error' ), true );
	assert.strictEqual( message( timedOut.container ), defaultStrings().locationFailed );

	// Even a real failure does not throw away a list somebody can still use.
	assert.deepStrictEqual( names( timedOut.container ), [ 'Warsaw' ] );
} );

test( 'the four outcomes are four different sentences, in the shipped table too', async () => {
	// Pinned here rather than left to the three cases above, each of which
	// would pass against a table where two of the values are the same string.
	//
	// Both tables, and the shipped one is the half that matters: the fixture is
	// a copy of Assets::strings() and could carry four distinct sentences while
	// the English fallback the front end uses when slosmL10n never arrived
	// carried one repeated four times.
	const { SLOSM } = loadLocator();
	const keys = [ 'locating', 'locationDenied', 'locationFailed', 'locationUnsupported' ];

	[ defaultStrings(), SLOSM.STRINGS ].forEach( ( table, at ) => {
		const said = keys.map( ( key ) => table[ key ] );

		said.forEach( ( sentence, key ) => {
			assert.strictEqual(
				typeof sentence,
				'string',
				( 0 === at ? 'the fixture' : 'locator.js' ) + ' has no ' + keys[ key ]
			);
			assert.ok( '' !== sentence );
		} );

		assert.strictEqual(
			new Set( said ).size,
			said.length,
			'two of the location messages in ' + ( 0 === at ? 'the fixture' : 'locator.js' ) + ' are the same sentence'
		);
	} );
} );

test( 'a position with unusable coordinates is a failure rather than a map at NaN', async () => {
	const harness = await locator( { payload: [ store() ] } );

	harness.geolocationQueue.push( { position: { coords: { latitude: 'here', longitude: null } } } );
	press( harness.container );

	assert.strictEqual( message( harness.container ), defaultStrings().locationFailed );
	assert.strictEqual( harness.leafletCalls.setView.length, 0, 'the map was moved to a place that is not one' );
} );

test( 'a position outside the world is refused too', async () => {
	const harness = await locator( { payload: [ store() ] } );

	harness.geolocationQueue.push( position( 91, 21 ) );
	press( harness.container );

	assert.strictEqual( message( harness.container ), defaultStrings().locationFailed );
	assert.strictEqual( harness.leafletCalls.setView.length, 0 );
} );

/* -------------------------------------------------------------------------
 * "Near me": success is a search, not a second path
 * ---------------------------------------------------------------------- */

test( 'a granted position recentres, sorts and renders, exactly as a search does', async () => {
	const harness = await locator( {
		payload: [
			store( { id: 1, name: 'Warsaw', lat: WARSAW.lat, lng: WARSAW.lng } ),
			store( { id: 2, name: 'Kraków', lat: KRAKOW.lat, lng: KRAKOW.lng } ),
			store( { id: 3, name: 'Łódź', lat: LODZ.lat, lng: LODZ.lng } ),
		],
	} );

	harness.geolocationQueue.push( position( KRAKOW.lat, KRAKOW.lng ) );
	press( harness.container );

	await harness.instance.pendingSearch;

	// Recentred on the visitor, at the configured zoom.
	assert.deepStrictEqual( plain( harness.leafletCalls.setView[ 0 ].center ), [ KRAKOW.lat, KRAKOW.lng ] );
	assert.strictEqual( harness.leafletCalls.setView[ 0 ].zoom, 12 );

	// Sorted from there, nearest first, by the same arithmetic the server uses.
	assert.deepStrictEqual( names( harness.container ), [ 'Kraków', 'Łódź', 'Warsaw' ] );

	// And in preload mode it asked the server for nothing: the list is already
	// in the browser and that is what the mode means.
	assert.strictEqual( harness.fetchCalls.length, 1, 'a preloaded locator asked /stores again for a position' );

	// The message it was showing while the prompt was open is gone, replaced by
	// what the draw found rather than left above it.
	assert.strictEqual( message( harness.container ), found( 3 ) );
} );

test( 'a granted position in query mode asks /stores for the neighbourhood', async () => {
	const harness = await locator( {
		config: defaultConfig( { mode: 'query', count: 900, radius: 25, limit: 10, units: 'mi' } ),
	} );

	harness.fetchQueue.push( jsonResponse( [ store( { distance: 3.5 } ) ] ) );
	harness.geolocationQueue.push( position( WARSAW.lat, WARSAW.lng ) );

	press( harness.container );

	await harness.instance.pendingSearch;

	const params = new URL( harness.fetchCalls[ 0 ].url ).searchParams;

	assert.strictEqual( Number( params.get( 'lat' ) ), WARSAW.lat );
	assert.strictEqual( Number( params.get( 'lng' ) ), WARSAW.lng );
	assert.strictEqual( params.get( 'radius' ), '25' );
	assert.strictEqual( params.get( 'limit' ), '10' );
	assert.strictEqual( params.get( 'unit' ), 'mi' );

	assert.deepStrictEqual( names( harness.container ), [ 'Warsaw' ] );
} );

test( 'a visitor\'s own coordinate is coarsened before it goes anywhere', async () => {
	// Cache-Control: private keeps a proximity url out of shared HTTP caches,
	// and Rest_Controller::get_stores() sets it for exactly that reason. It
	// does nothing about logs: the url lands verbatim in the site's own access
	// log and in every WAF, proxy and CDN log in front of it, kept for weeks.
	// A device reports ten or more digits; four decimals is about eleven
	// metres, which is a street rather than a doorway and far finer than "which
	// branch is nearest" needs.
	const harness = await locator( {
		config: defaultConfig( { mode: 'query', count: 900, radius: 25 } ),
	} );

	harness.fetchQueue.push( jsonResponse( [ store( { distance: 1.2 } ) ] ) );
	harness.geolocationQueue.push( position( 52.229675635456, 21.012228749999 ) );

	press( harness.container );

	await harness.instance.pendingSearch;

	const params = new URL( harness.fetchCalls[ 0 ].url ).searchParams;

	assert.strictEqual( params.get( 'lat' ), '52.2297' );
	assert.strictEqual( params.get( 'lng' ), '21.0122' );

	// The map is put at the same coarsened point, not at the precise one. The
	// two have to be one point: in preload mode the browser sorts the list from
	// it with Geo.distance() while the server sorts from whatever the url said,
	// and geo-crosscheck.test.js's guarantee is that those two agree exactly.
	assert.deepStrictEqual( plain( harness.leafletCalls.setView[ 0 ].center ), [ 52.2297, 21.0122 ] );
} );

test( 'a geocoded address is not coarsened, because it is not a fact about a person', async () => {
	// The control for the case above, and the line it draws. A place somebody
	// typed is a public place and the geocoder's own answer for it; rounding
	// that would be cost with no privacy on the other side of it.
	const harness = await locator( { payload: [ store() ] } );

	harness.fetchQueue.push( jsonResponse( { lat: 50.06465390123, lng: 19.94497890123, label: 'Kraków' } ) );

	harness.container.querySelector( '.slosm__search' ).value = 'Kraków';
	fire( harness.container.querySelector( '.slosm__search' ), 'keydown', { key: 'Enter' } );

	await harness.instance.pendingSearch;

	assert.deepStrictEqual( plain( harness.leafletCalls.setView[ 0 ].center ), [ 50.06465390123, 19.94497890123 ] );
} );

test( 'a position that arrives after a later search has begun is dropped', async () => {
	// The same staleness this file's searches have had since Task 14, arriving
	// through a button instead of a keystroke: somebody presses "use my
	// location", waits, gives up, types an address, and then grants the
	// permission. The answer they asked for last is the one that wins.
	const harness = await locator( {
		payload: [
			store( { id: 1, name: 'Warsaw', lat: WARSAW.lat, lng: WARSAW.lng } ),
			store( { id: 2, name: 'Kraków', lat: KRAKOW.lat, lng: KRAKOW.lng } ),
		],
	} );

	press( harness.container );

	assert.strictEqual( harness.geolocationCalls.length, 1 );

	harness.fetchQueue.push( jsonResponse( { lat: WARSAW.lat, lng: WARSAW.lng, label: 'Warszawa' } ) );

	harness.container.querySelector( '.slosm__search' ).value = 'Warszawa';
	fire( harness.container.querySelector( '.slosm__search' ), 'keydown', { key: 'Enter' } );

	await harness.instance.pendingSearch;

	assert.deepStrictEqual( names( harness.container ), [ 'Warsaw', 'Kraków' ] );

	// And only now does the prompt come back granted, from Kraków.
	harness.geolocationCalls[ 0 ].success( position( KRAKOW.lat, KRAKOW.lng ).position );

	await Promise.resolve();

	assert.deepStrictEqual(
		names( harness.container ),
		[ 'Warsaw', 'Kraków' ],
		'an abandoned position request took the map from the search that replaced it'
	);
	assert.strictEqual( harness.leafletCalls.setView.length, 1, 'the map was moved twice' );
} );

test( 'a denial that arrives after a later search has begun says nothing', async () => {
	const harness = await locator( { payload: [ store() ] } );

	press( harness.container );

	harness.fetchQueue.push( jsonResponse( { lat: KRAKOW.lat, lng: KRAKOW.lng, label: 'Kraków' } ) );

	harness.container.querySelector( '.slosm__search' ).value = 'Kraków';
	fire( harness.container.querySelector( '.slosm__search' ), 'keydown', { key: 'Enter' } );

	await harness.instance.pendingSearch;

	harness.geolocationCalls[ 0 ].failure( positionError( PERMISSION_DENIED ).error );

	// A sentence about a prompt the visitor has already moved past would land
	// on top of results that have nothing to do with it. What is up is the
	// count from the search that overtook it.
	assert.strictEqual( message( harness.container ), found( 1 ) );
} );

test( 'a position that beats the preload fetch is not reframed away by it', async () => {
	// The one ordering a preloaded locator can really hit: /stores is slow, the
	// visitor presses the button, the position arrives first. Without a guard
	// the payload lands afterwards and reframes the map around every location,
	// undoing the recentring and the distance sort.
	const harness = loadLocator();
	const slow = deferredResponse();

	harness.fetchQueue.push( slow );

	const container = harness.locatorMarkup( { config: defaultConfig( { radius: 500 } ) } );
	const instance = harness.SLOSM.initAll()[ 0 ];

	harness.geolocationQueue.push( position( KRAKOW.lat, KRAKOW.lng ) );
	press( container );

	slow.resolve(
		jsonResponse( [
			store( { id: 1, name: 'Warsaw', lat: WARSAW.lat, lng: WARSAW.lng } ),
			store( { id: 2, name: 'Kraków', lat: KRAKOW.lat, lng: KRAKOW.lng } ),
		] )
	);

	await instance.ready;
	await until( () => 0 < rows( container ).length, 'the preloaded list was never drawn' );

	assert.deepStrictEqual( names( container ), [ 'Kraków', 'Warsaw' ], 'the late payload dropped the sort' );

	// The guard this case was written for was 'the late payload must not
	// reframe at all', and Task 32 narrowed it to the half that was really
	// being protected: the visitor is not moved off the point they asked to
	// look from. The late draw does frame now — every draw does — but it
	// frames the position *with* the locations, so Kraków is still in the
	// picture rather than replaced by it.
	//
	// What this does cost, said out loud because it is a real trade: with a
	// radius of 500km and a branch 250km away, that frame is most of Poland.
	// It is the honest picture of where the nearest one is, and it is not the
	// close-up the old rule accidentally gave.
	const frame = plain(
		harness.leafletCalls.fitBounds[ harness.leafletCalls.fitBounds.length - 1 ].bounds
	);

	assert.strictEqual( harness.leafletCalls.fitBounds.length, 1, 'the late payload framed more than once' );
	assert.deepStrictEqual( frame[ 0 ], [ KRAKOW.lat, KRAKOW.lng ], 'the frame dropped the position it was asked to look from' );
	assert.strictEqual( frame.length, 3, 'the frame is the position and the two locations' );
} );

test( 'a locator with no near-me button still comes up', async () => {
	// near_me="no" is an ordinary configuration, not broken markup.
	const harness = await locator( { payload: [ store() ], withLocate: false } );

	assert.strictEqual( button( harness.container ), null );
	assert.strictEqual( message( harness.container ), null );
	assert.strictEqual( harness.leafletCalls.map.length, 1 );
	assert.deepStrictEqual( names( harness.container ), [ 'Warsaw' ] );

	// The control, without which this case is equally true of a front end that
	// never looked for the button at all: the same markup with one does wire it.
	const withButton = await locator( { payload: [ store() ] } );

	withButton.geolocationQueue.push( position( KRAKOW.lat, KRAKOW.lng ) );
	press( withButton.container );

	assert.strictEqual( withButton.geolocationCalls.length, 1 );
} );

/* -------------------------------------------------------------------------
 * The category filter
 * ---------------------------------------------------------------------- */

test( 'the select is filled from what is on the map, sorted and deduplicated', async () => {
	const harness = await locator( {
		payload: [
			store( { id: 1, name: 'Warsaw', categories: [ 'Shops', 'Cafés' ] } ),
			store( { id: 2, name: 'Kraków', lat: KRAKOW.lat, lng: KRAKOW.lng, categories: [ 'Shops' ] } ),
			store( { id: 3, name: 'Łódź', lat: LODZ.lat, lng: LODZ.lng, categories: [] } ),
		],
	} );

	// "All categories" is the option the shortcode rendered, kept rather than
	// rebuilt: it carries a translated label the front end has no copy of.
	assert.deepStrictEqual( optionLabels( harness.container ), [ 'All categories', 'Cafés', 'Shops' ] );
	assert.deepStrictEqual( optionValues( harness.container ), [ '', 'Cafés', 'Shops' ] );

	// And nothing was asked of the server to find that out. get_terms() in the
	// shortcode would have been a second storage query for a list the payload
	// already carries.
	assert.strictEqual( harness.fetchCalls.length, 1 );
} );

test( 'a category that is not a usable name is not offered', async () => {
	const harness = await locator( {
		payload: [ store( { categories: [ 'Shops', '', 7, null, { name: 'Cafés' }, 'Shops' ] } ) ],
	} );

	assert.deepStrictEqual( optionValues( harness.container ), [ '', 'Shops' ] );
} );

test( 'a category name full of markup reaches the select as text', async () => {
	// Term names come out of the database unescaped in json, and a user with
	// unfiltered_html can legitimately put html in one. The harness throws on
	// innerHTML in either direction, so a path that parsed this would fail with
	// that error rather than with this assertion.
	const hostile = '<img src=x onerror="alert(1)"></option><script>alert(2)</script>';
	const harness = await locator( { payload: [ store( { categories: [ hostile ] } ) ] } );

	const option = select( harness.container ).children[ 1 ];

	assert.strictEqual( option.textContent, hostile );
	assert.strictEqual( option.value, hostile );
	assert.strictEqual( option.children.length, 0, 'the category name was parsed as markup' );
} );

test( 'choosing a category filters a preloaded list in the browser, with no request', async () => {
	const harness = await locator( {
		payload: [
			store( { id: 1, name: 'Warsaw', categories: [ 'Shops' ] } ),
			store( { id: 2, name: 'Kraków', lat: KRAKOW.lat, lng: KRAKOW.lng, categories: [ 'Cafés' ] } ),
			store( { id: 3, name: 'Łódź', lat: LODZ.lat, lng: LODZ.lng, categories: [ 'Cafés', 'Shops' ] } ),
		],
	} );

	const before = harness.fetchCalls.length;

	choose( harness.container, 'Cafés' );

	assert.deepStrictEqual( names( harness.container ), [ 'Kraków', 'Łódź' ] );
	assert.strictEqual( harness.leafletCalls.marker.length, 5, 'the map still shows a pin the list does not' );
	assert.strictEqual(
		harness.fetchCalls.length,
		before,
		'a preloaded locator asked the server to do a filter it can do itself'
	);

	// Back to everything, which is the half a filter that only ever narrows
	// would fail.
	choose( harness.container, '' );

	assert.deepStrictEqual( names( harness.container ), [ 'Warsaw', 'Kraków', 'Łódź' ] );
} );

test( 'the category filter matches the way the route matches, not more narrowly', async () => {
	// Two implementations of one comparison, and the case has to exercise the
	// pair they can disagree about rather than the pair they always agreed on.
	// An earlier version of this case used Bakeries/bakeries, which is ASCII —
	// where PHP's strcasecmp() and JavaScript's toLowerCase() have never
	// differed — so it pinned the coupling everywhere except the place it broke.
	//
	// 'Żłobki' is the pair that breaks it: strcasecmp( 'ŻŁOBKI', 'żłobki' ) is
	// not 0, and 'ŻŁOBKI'.toLowerCase() === 'żłobki'.toLowerCase() is true.
	const harness = await locator( {
		payload: [ store( { categories: [ 'Żłobki' ] } ) ],
		config: defaultConfig( { radius: 500, category: 'ŻŁOBKI' } ),
	} );

	assert.deepStrictEqual( names( harness.container ), [ 'Warsaw' ] );

	// The ASCII pair too, because a browser fold that had stopped folding at
	// all would fail the case above and a browser fold that only handled
	// non-ASCII would pass it.
	const ascii = await locator( {
		payload: [ store( { categories: [ 'Bakeries' ] } ) ],
		config: defaultConfig( { radius: 500, category: 'bakeries' } ),
	} );

	assert.deepStrictEqual( names( ascii.container ), [ 'Warsaw' ] );

	// The control, and the half that makes the assertions above mean anything:
	// a locator that filtered nothing at all would show that row too. A
	// category this location really is not in takes it away.
	const elsewhere = await locator( {
		payload: [ store( { categories: [ 'Żłobki' ] } ) ],
		config: defaultConfig( { radius: 500, category: 'Cafés' } ),
	} );

	assert.deepStrictEqual( names( elsewhere.container ), [] );
	assert.strictEqual( message( elsewhere.container ), defaultStrings().noResults );

	// And the other side of the coupling, in source, the way
	// geo-crosscheck.test.js pins the distance formula. No case in this suite
	// can run the PHP, so what is checked is that the route folds the same way
	// — behind function_exists(), because an unguarded mb_strtolower() is a
	// fatal error on a host without mbstring.
	// codeOnly(), so that the docblock explaining why the route no longer uses
	// strcasecmp does not read as the route using it. That stripper is a
	// JavaScript one and PHP's block and line comments are the same two shapes,
	// which is the whole of what it needs here.
	const rest = codeOnly( pluginSource( 'includes', 'class-rest-controller.php' ) );

	assert.ok(
		rest.includes( "function_exists( 'mb_strtolower' ) ? mb_strtolower( $name, 'UTF-8' ) : strtolower( $name )" ),
		'the route no longer folds category names the way toLowerCase() does'
	);

	// And that has_category() actually goes through it. Asserting the fold
	// exists is not enough: a route that kept the method and went back to
	// comparing with strcasecmp would satisfy the line above while disagreeing
	// with this side for every alphabet but one.
	assert.ok(
		rest.includes( '$this->fold( $name ) === $wanted' ),
		'the category filter no longer compares folded names'
	);
	assert.ok(
		! rest.includes( 'strcasecmp' ),
		'the route is back to an ASCII-only fold somewhere, which disagrees with this one for every other alphabet'
	);
} );

test( 'the filter survives a search rather than being reset by it', async () => {
	const harness = await locator( {
		payload: [
			store( { id: 1, name: 'Warsaw', categories: [ 'Shops' ] } ),
			store( { id: 2, name: 'Kraków', lat: KRAKOW.lat, lng: KRAKOW.lng, categories: [ 'Cafés' ] } ),
			store( { id: 3, name: 'Łódź', lat: LODZ.lat, lng: LODZ.lng, categories: [ 'Cafés' ] } ),
		],
	} );

	choose( harness.container, 'Cafés' );

	harness.fetchQueue.push( jsonResponse( { lat: KRAKOW.lat, lng: KRAKOW.lng, label: 'Kraków' } ) );
	harness.container.querySelector( '.slosm__search' ).value = 'Kraków';
	fire( harness.container.querySelector( '.slosm__search' ), 'keydown', { key: 'Enter' } );

	await harness.instance.pendingSearch;

	assert.deepStrictEqual( names( harness.container ), [ 'Kraków', 'Łódź' ], 'the search undid the category filter' );
} );

test( 'choosing a category in query mode asks /stores for it', async () => {
	const harness = await locator( {
		config: defaultConfig( { mode: 'query', count: 900 } ),
	} );

	// A search first, because query mode has nothing to filter until somebody
	// has said where to look.
	harness.fetchQueue.push( jsonResponse( { lat: WARSAW.lat, lng: WARSAW.lng, label: 'Warszawa' } ) );
	harness.fetchQueue.push(
		jsonResponse( [
			store( { id: 1, name: 'Warsaw', categories: [ 'Shops' ] } ),
			store( { id: 2, name: 'Second', lat: LODZ.lat, lng: LODZ.lng, categories: [ 'Cafés' ] } ),
		] )
	);

	harness.container.querySelector( '.slosm__search' ).value = 'Warszawa';
	fire( harness.container.querySelector( '.slosm__search' ), 'keydown', { key: 'Enter' } );

	await harness.instance.pendingSearch;

	assert.deepStrictEqual( optionValues( harness.container ), [ '', 'Cafés', 'Shops' ] );

	harness.fetchQueue.push( jsonResponse( [ store( { id: 2, name: 'Second', lat: LODZ.lat, lng: LODZ.lng, categories: [ 'Cafés' ] } ) ] ) );

	choose( harness.container, 'Cafés' );

	await harness.instance.pendingSearch;

	const last = new URL( harness.fetchCalls[ harness.fetchCalls.length - 1 ].url );

	assert.strictEqual( last.searchParams.get( 'category' ), 'Cafés' );
	assert.strictEqual( Number( last.searchParams.get( 'lat' ) ), WARSAW.lat );
	assert.deepStrictEqual( names( harness.container ), [ 'Second' ] );
} );

test( 'a query-mode filter does not shrink the list of categories it offers', async () => {
	// The trap in taking the options from "what is on the map" in query mode:
	// once a category is chosen, every later response carries only that
	// category, so a list rebuilt from it would leave the visitor with one
	// option and no way back.
	const harness = await locator( { config: defaultConfig( { mode: 'query', count: 900 } ) } );

	harness.fetchQueue.push( jsonResponse( { lat: WARSAW.lat, lng: WARSAW.lng, label: 'Warszawa' } ) );
	harness.fetchQueue.push(
		jsonResponse( [
			store( { id: 1, categories: [ 'Shops' ] } ),
			store( { id: 2, lat: LODZ.lat, lng: LODZ.lng, categories: [ 'Cafés' ] } ),
		] )
	);

	harness.container.querySelector( '.slosm__search' ).value = 'Warszawa';
	fire( harness.container.querySelector( '.slosm__search' ), 'keydown', { key: 'Enter' } );

	await harness.instance.pendingSearch;

	harness.fetchQueue.push( jsonResponse( [ store( { id: 1, categories: [ 'Shops' ] } ) ] ) );

	choose( harness.container, 'Shops' );

	await harness.instance.pendingSearch;

	assert.deepStrictEqual( optionValues( harness.container ), [ '', 'Cafés', 'Shops' ] );
	assert.strictEqual( select( harness.container ).value, 'Shops', 'the selection was lost when the options were rebuilt' );
} );

test( 'the options are left alone when a payload brings nothing new', async () => {
	// The guard that makes this a merge rather than a rebuild-every-time. In a
	// browser, replacing a select's options closes it if it is open and moves
	// the focus if it was on it — so a search finishing while somebody is
	// halfway through choosing a category would shut the dropdown in their
	// face. Nothing here models an open dropdown, so what is asserted instead
	// is the thing underneath it: the option nodes are the same objects.
	const harness = await locator( {
		payload: [ store( { categories: [ 'Shops' ] } ), store( { id: 2, lat: LODZ.lat, lng: LODZ.lng, categories: [ 'Cafés' ] } ) ],
	} );

	const built = select( harness.container ).children.slice();

	assert.strictEqual( built.length, 3 );

	harness.fetchQueue.push( jsonResponse( { lat: KRAKOW.lat, lng: KRAKOW.lng, label: 'Kraków' } ) );
	harness.container.querySelector( '.slosm__search' ).value = 'Kraków';
	fire( harness.container.querySelector( '.slosm__search' ), 'keydown', { key: 'Enter' } );

	await harness.instance.pendingSearch;

	const now = select( harness.container ).children;

	// Identity, one element at a time, and never deepStrictEqual on a pair of
	// nodes. This assertion used to be a deepStrictEqual of the two arrays,
	// which passes instantly when the references match — and takes the whole
	// process out when they do not: a StubElement reaches its ownerDocument,
	// its parentNode and its listener map, so structurally comparing two of
	// them walks the entire document, the locator instance and every closure
	// held by every handler on it. The mutant that rebuilds the options was
	// caught, but as a heap abort with no case name rather than a named
	// failure. assert.ok on a === keeps nothing large anywhere near an
	// inspector.
	assert.strictEqual( now.length, built.length, 'the category options changed in number' );

	built.forEach( ( option, at ) => {
		assert.ok(
			now[ at ] === option,
			'option ' + at + ' was rebuilt although the search learned no new category'
		);
	} );
} );

test( 'the control is made to agree with the map, not the other way round', async () => {
	// A select can arrive carrying a value the config knows nothing about: a
	// page builder that wrote one into the markup, a content filter, or a
	// browser restoring form state across a reload. Leaving it there would mean
	// a control saying "Shops" over a map showing everything — a filter that
	// lies about what is on screen, which is worse than no filter.
	const harness = loadLocator();

	harness.fetchQueue.push(
		jsonResponse( [
			store( { id: 1, name: 'Warsaw', categories: [ 'Shops' ] } ),
			store( { id: 2, name: 'Kraków', lat: KRAKOW.lat, lng: KRAKOW.lng, categories: [ 'Cafés' ] } ),
		] )
	);

	const container = harness.locatorMarkup( { config: defaultConfig( { radius: 500 } ) } );

	// Before the script runs, the way the parser and the browser would have
	// left it.
	container.querySelector( '.slosm__category' ).value = 'Shops';

	const instance = harness.SLOSM.initAll()[ 0 ];

	await instance.ready;

	assert.strictEqual(
		select( container ).value,
		'',
		'the select was left claiming a filter the map is not applying'
	);
	assert.deepStrictEqual( names( container ), [ 'Warsaw', 'Kraków' ] );

	// And on a site whose locations carry no categories at all, where nothing
	// ever rebuilds the options: the control still has to be honest, so it is
	// settled once when it is wired rather than only when it is filled.
	const bare = loadLocator();

	bare.fetchQueue.push( jsonResponse( [ store( { categories: [] } ) ] ) );

	const plainOne = bare.locatorMarkup( { config: defaultConfig( { radius: 500 } ) } );

	plainOne.querySelector( '.slosm__category' ).value = 'Shops';

	const only = bare.SLOSM.initAll()[ 0 ];

	await only.ready;

	assert.deepStrictEqual( optionValues( plainOne ), [ '' ], 'options appeared for a payload with no categories' );
	assert.strictEqual( select( plainOne ).value, '' );
	assert.deepStrictEqual( names( plainOne ), [ 'Warsaw' ] );
} );

test( 'a category chosen after a search keeps the place that was searched for', async () => {
	// The filter redraws the list, and a redraw that forgot where the search
	// was made from would put the rows back in payload order and lose every
	// distance — silently, on a list that still looks plausible.
	const harness = await locator( {
		payload: [
			store( { id: 1, name: 'Warsaw', lat: WARSAW.lat, lng: WARSAW.lng, categories: [ 'Shops' ] } ),
			store( { id: 2, name: 'Kraków', lat: KRAKOW.lat, lng: KRAKOW.lng, categories: [ 'Shops' ] } ),
			store( { id: 3, name: 'Łódź', lat: LODZ.lat, lng: LODZ.lng, categories: [ 'Cafés' ] } ),
		],
	} );

	harness.fetchQueue.push( jsonResponse( { lat: KRAKOW.lat, lng: KRAKOW.lng, label: 'Kraków' } ) );
	harness.container.querySelector( '.slosm__search' ).value = 'Kraków';
	fire( harness.container.querySelector( '.slosm__search' ), 'keydown', { key: 'Enter' } );

	await harness.instance.pendingSearch;

	assert.deepStrictEqual( names( harness.container ), [ 'Kraków', 'Łódź', 'Warsaw' ] );

	choose( harness.container, 'Shops' );

	assert.deepStrictEqual( names( harness.container ), [ 'Kraków', 'Warsaw' ], 'the filter put the list back in payload order' );

	// And the distances are still measured from the place that was searched
	// for, which is the half an order alone could agree with by luck.
	assert.strictEqual( rows( harness.container )[ 0 ].querySelector( '.slosm__result-distance' ).textContent, '0.0 km' );
} );

test( 'a query-mode category chosen before any search asks for nothing', async () => {
	// There is no point to search from yet, and "every bakery on the site" is
	// the whole-list request query mode exists to avoid making. The choice is
	// remembered and spent on the next search.
	const harness = await locator( { config: defaultConfig( { mode: 'query', count: 900 } ) } );

	harness.instance.categories.push( 'Bakeries' );

	choose( harness.container, 'Bakeries' );

	assert.strictEqual( harness.fetchCalls.length, 0, 'a query-mode locator asked /stores with nowhere to search from' );

	// The control: the choice was kept, and the next search spends it.
	harness.fetchQueue.push( jsonResponse( { lat: WARSAW.lat, lng: WARSAW.lng, label: 'Warszawa' } ) );
	harness.fetchQueue.push( jsonResponse( [ store( { categories: [ 'Bakeries' ] } ) ] ) );

	harness.container.querySelector( '.slosm__search' ).value = 'Warszawa';
	fire( harness.container.querySelector( '.slosm__search' ), 'keydown', { key: 'Enter' } );

	await harness.instance.pendingSearch;

	assert.strictEqual(
		new URL( harness.fetchCalls[ 1 ].url ).searchParams.get( 'category' ),
		'Bakeries'
	);
} );

test( 'a pinned category is not a control the visitor can overrule', async () => {
	const harness = await locator( {
		payload: [
			store( { id: 1, name: 'Warsaw', categories: [ 'Bakeries' ] } ),
			store( { id: 2, name: 'Kraków', lat: KRAKOW.lat, lng: KRAKOW.lng, categories: [ 'Bakeries' ] } ),
		],
		config: defaultConfig( { radius: 500, category: 'Bakeries' } ),
	} );

	// The site owner wrote category="bakeries" in the shortcode. Offering a
	// control that silently shows everything would be this plugin overruling
	// that decision on a visitor's click.
	//
	// aria-disabled, not disabled: this element is the only thing on the page
	// that says what the map is filtered to, and `disabled` takes it out of the
	// tab order — which stops somebody *reading* a label rather than stopping
	// them operating a control.
	assert.strictEqual( select( harness.container ).getAttribute( 'aria-disabled' ), 'true' );
	assert.strictEqual(
		select( harness.container ).getAttribute( 'disabled' ),
		null,
		'the one element naming this filter was taken out of the tab order'
	);

	// And it was left exactly as the shortcode rendered it: the two options
	// Shortcode::category_options() writes, no more.
	assert.deepStrictEqual( optionValues( harness.container ), [ '', 'Bakeries' ] );
	assert.strictEqual( select( harness.container ).value, 'Bakeries' );

	// aria-disabled stops nothing by itself, so a change is put back rather
	// than acted on. A select reading "All categories" over a map still
	// filtered to Bakeries would be the same lie from the other direction.
	choose( harness.container, '' );

	assert.strictEqual( select( harness.container ).value, 'Bakeries' );
	assert.deepStrictEqual( names( harness.container ), [ 'Warsaw', 'Kraków' ] );
	assert.strictEqual( harness.fetchCalls.length, 1, 'the pinned control made a request of its own' );

	// The control: the same payload with nothing pinned does get a filled,
	// live select, so the assertions above are about the pin rather than about
	// a select nothing ever touches.
	const open = await locator( {
		payload: [ store( { categories: [ 'Bakeries' ] } ) ],
	} );

	assert.strictEqual( select( open.container ).getAttribute( 'aria-disabled' ), null );
	assert.deepStrictEqual( optionValues( open.container ), [ '', 'Bakeries' ] );
} );

test( 'the limit still caps the rows when a category is chosen', async () => {
	const harness = await locator( {
		config: defaultConfig( { radius: 500, limit: 2 } ),
		payload: [
			store( { id: 1, name: 'Aleph', categories: [ 'Shops' ] } ),
			store( { id: 2, name: 'Beth', lat: LODZ.lat, lng: LODZ.lng, categories: [ 'Shops' ] } ),
			store( { id: 3, name: 'Gimel', lat: KRAKOW.lat, lng: KRAKOW.lng, categories: [ 'Shops' ] } ),
			store( { id: 4, name: 'Daleth', lat: 54.352, lng: 18.6466, categories: [ 'Cafés' ] } ),
		],
	} );

	choose( harness.container, 'Shops' );

	assert.deepStrictEqual( names( harness.container ), [ 'Aleph', 'Beth' ] );

	// The control: the limit is not the only thing doing work here. Two rows
	// out of four is what a locator that ignored the filter entirely would also
	// show, and the fourth location is the one that tells them apart.
	choose( harness.container, 'Cafés' );

	assert.deepStrictEqual( names( harness.container ), [ 'Daleth' ] );
} );

test( 'a category that matches nothing says so rather than showing an empty list', async () => {
	const harness = await locator( {
		payload: [ store( { categories: [ 'Shops' ] } ), store( { id: 2, lat: LODZ.lat, lng: LODZ.lng, categories: [ 'Cafés' ] } ) ],
	} );

	// A value no option carries. A browser would not let this be chosen; a
	// hand-driven `value` can, and what matters is that the locator answers it
	// with a sentence rather than with a blank list.
	choose( harness.container, 'Florists' );

	assert.strictEqual( message( harness.container ), defaultStrings().noResults );
	assert.ok( harness.container.classList.contains( 'slosm--empty' ) );
} );

test( 'the empty class comes off again when the next draw has rows', async () => {
	const harness = await locator( {
		payload: [ store( { categories: [ 'Shops' ] } ), store( { id: 2, lat: LODZ.lat, lng: LODZ.lng, categories: [ 'Cafés' ] } ) ],
	} );

	choose( harness.container, 'Florists' );

	assert.ok( harness.container.classList.contains( 'slosm--empty' ) );

	choose( harness.container, 'Shops' );

	// A state, not a latch, and that is the whole case. `.slosm--empty` is a
	// hook: nothing in this plugin styles it, tests/test-stylesheet.php
	// records that as a decision, so a site is the only thing that ever reads
	// it — and a class that goes on and stays on tells that site the locator
	// is empty for the rest of the page's life. The removal in show() is one
	// line with no other witness anywhere in either suite.
	assert.deepStrictEqual( names( harness.container ), [ 'Warsaw' ] );
	assert.ok( ! harness.container.classList.contains( 'slosm--empty' ) );
	assert.strictEqual( message( harness.container ), found( 1 ) );
} );

test( 'a locator with no category select still draws its map', async () => {
	const harness = await locator( {
		payload: [ store( { categories: [ 'Shops' ] } ) ],
		withCategory: false,
	} );

	assert.strictEqual( select( harness.container ), null );
	assert.strictEqual( message( harness.container ), null );
	assert.deepStrictEqual( names( harness.container ), [ 'Warsaw' ] );

	// The control: the same payload through a locator that has a select does
	// fill it, so "nothing went wrong" above is about the missing control
	// rather than about a front end that never fills one.
	const withSelect = await locator( { payload: [ store( { categories: [ 'Shops' ] } ) ] } );

	assert.deepStrictEqual( optionValues( withSelect.container ), [ '', 'Shops' ] );
} );

/* -------------------------------------------------------------------------
 * Clustering
 * ---------------------------------------------------------------------- */

test( 'a locator that was not told to cluster builds no group at all', async () => {
	const plainMap = await locator( { payload: [ store() ] } );

	assert.strictEqual( plainMap.leafletCalls.markerClusterGroup.length, 0 );
	assert.strictEqual( plainMap.leafletCalls.addTo.filter( ( call ) => 'marker' === call.kind ).length, 1 );

	// The control, in the same case: the same file does build one when the
	// config says so, so the absence above is a decision rather than a feature
	// nobody wrote.
	const clustered = await locator( {
		payload: [ store() ],
		config: defaultConfig( { radius: 500, cluster: true } ),
	} );

	assert.strictEqual( clustered.leafletCalls.markerClusterGroup.length, 1 );
} );

test( 'only a real true clusters, because only the shortcode writes one', async () => {
	// Shortcode::clusters() returns a boolean and says why in as many words:
	// the *attribute* legitimately carries the word "yes", and a string
	// arriving in the config would be truthy by accident. Anything that is not
	// `true` here came from a hand-written data-slosm, and guessing at it is
	// how a config comes to mean two things.
	const harness = await locator( {
		payload: [ store() ],
		config: defaultConfig( { radius: 500, cluster: 'yes' } ),
	} );

	assert.strictEqual( harness.leafletCalls.markerClusterGroup.length, 0 );

	// And it is not a silent refusal of something that would have worked: the
	// same config with a real boolean does cluster.
	const real = await locator( {
		payload: [ store() ],
		config: defaultConfig( { radius: 500, cluster: true } ),
	} );

	assert.strictEqual( real.leafletCalls.markerClusterGroup.length, 1 );
} );

test( 'a clustered locator puts its pins in the group and never on the map', async () => {
	const harness = await locator( {
		payload: [ store(), store( { id: 2, lat: KRAKOW.lat, lng: KRAKOW.lng } ) ],
		config: defaultConfig( { radius: 500, cluster: true } ),
	} );

	const group = harness.leafletCalls.markerClusterGroup[ 0 ].group;

	assert.strictEqual( group.markers.length, 2 );
	assert.strictEqual( harness.leafletCalls.clusterAdd.length, 2 );

	// The group is on the map, and the markers are not. A marker added to both
	// would be drawn twice and would never be clustered.
	assert.ok( harness.instance.map.layers.indexOf( group ) > -1, 'the cluster group was never added to the map' );
	harness.leafletCalls.marker.forEach( ( call ) => {
		assert.strictEqual(
			harness.instance.map.layers.indexOf( call.layer ),
			-1,
			'a pin was added straight to the map as well as to the cluster'
		);
	} );
} );

test( 'a redraw empties the group in one call rather than one removal per pin', async () => {
	const harness = await locator( {
		payload: [
			store( { id: 1, name: 'Warsaw' } ),
			store( { id: 2, name: 'Kraków', lat: KRAKOW.lat, lng: KRAKOW.lng } ),
		],
		config: defaultConfig( { radius: 500, cluster: true } ),
	} );

	// The first draw cleared an empty group, which is a no-op; what this case
	// is about is the second one, so it is counted from here.
	const cleared = harness.leafletCalls.clusterClear.length;

	harness.fetchQueue.push( jsonResponse( { lat: KRAKOW.lat, lng: KRAKOW.lng, label: 'Kraków' } ) );
	harness.container.querySelector( '.slosm__search' ).value = 'Kraków';
	fire( harness.container.querySelector( '.slosm__search' ), 'keydown', { key: 'Enter' } );

	await harness.instance.pendingSearch;

	const group = harness.leafletCalls.markerClusterGroup[ 0 ].group;

	assert.strictEqual( harness.leafletCalls.clusterClear.length - cleared, 1 );
	assert.strictEqual( harness.leafletCalls.removeLayer.length, 0, 'a clustered pin was removed from the map instead' );

	// And the redraw really happened: two fresh markers in the group, not four.
	assert.strictEqual( group.markers.length, 2 );
	assert.strictEqual( harness.leafletCalls.marker.length, 4 );

	// One group for the life of the locator. Building a second per search would
	// leave the first on the map with every pin still in it.
	assert.strictEqual( harness.leafletCalls.markerClusterGroup.length, 1 );
} );

test( 'the cluster label is a number this file computed, in an element nothing parses', async () => {
	// The hazard the vendored README names: L.DivIcon sets its content with
	// innerHTML, and the cluster icon is a DivIcon. Anything derived from a
	// location handed to iconCreateFunction is parsed as markup inside Leaflet,
	// where this repository's own source scan cannot see it.
	const hostile = '<img src=x onerror="alert(1)">';
	const harness = await locator( {
		payload: [ store( { name: hostile, categories: [ hostile ], address: hostile, city: hostile } ) ],
		config: defaultConfig( { radius: 500, cluster: true } ),
	} );

	const group = harness.leafletCalls.markerClusterGroup[ 0 ].group;
	const make = group.options.iconCreateFunction;

	assert.strictEqual( typeof make, 'function', 'the cluster icon is Leaflet.markercluster\'s own, not this plugin\'s' );

	// A cluster holding that location, shaped the way markercluster shapes one.
	const icon = make( {
		getChildCount: () => 3,
		getAllChildMarkers: () => harness.leafletCalls.marker.map( ( call ) => call.layer ),
	} );

	const options = harness.leafletCalls.divIcon[ 0 ].options;

	assert.ok( icon, 'iconCreateFunction returned nothing' );

	// An element, not a string. Leaflet appends an Element and parses anything
	// else — `e.html instanceof Element?(me(t),t.appendChild(e.html)):
	// t.innerHTML=…` in the vendored file — so this is the whole of the
	// defence, and it is asserted rather than described.
	assert.strictEqual( typeof options.html, 'object', 'the cluster label was handed to Leaflet as a string' );
	assert.strictEqual( options.html.nodeType, 1 );
	assert.strictEqual( options.html.textContent, '3' );

	// And nothing from the payload is anywhere in what Leaflet was given —
	// not in the label, not in the class, not in any other option.
	assert.strictEqual(
		JSON.stringify( Object.keys( options ).map( ( key ) => ( 'html' === key ? options.html.textContent : options[ key ] ) ) ).indexOf(
			'img src'
		),
		-1,
		'a payload string reached the cluster icon'
	);
} );

test( 'a cluster count that is not a number does not become one in the label', async () => {
	// iconCreateFunction is handed whatever markercluster hands it, and the
	// label has to be a number this file is sure of rather than a value it
	// merely received. A count of undefined would otherwise be the string
	// "undefined" going through L.DivIcon.
	const harness = await locator( {
		payload: [ store() ],
		config: defaultConfig( { radius: 500, cluster: true } ),
	} );

	const make = harness.leafletCalls.markerClusterGroup[ 0 ].group.options.iconCreateFunction;

	make( { getChildCount: () => undefined } );

	assert.strictEqual( harness.leafletCalls.divIcon[ 0 ].options.html.textContent, '0' );

	make( {} );

	assert.strictEqual( harness.leafletCalls.divIcon[ 1 ].options.html.textContent, '0' );
} );

test( 'the cluster icon carries the classes the vendored stylesheet styles', async () => {
	const harness = await locator( {
		payload: [ store() ],
		config: defaultConfig( { radius: 500, cluster: true } ),
	} );

	const make = harness.leafletCalls.markerClusterGroup[ 0 ].group.options.iconCreateFunction;

	[
		[ 1, 'marker-cluster-small' ],
		[ 9, 'marker-cluster-small' ],
		[ 10, 'marker-cluster-medium' ],
		[ 99, 'marker-cluster-medium' ],
		[ 100, 'marker-cluster-large' ],
	].forEach( ( [ count, wanted ], at ) => {
		make( { getChildCount: () => count } );

		const className = harness.leafletCalls.divIcon[ at ].options.className;

		assert.ok( className.indexOf( 'marker-cluster' ) > -1, 'the base class is missing' );
		assert.ok( className.indexOf( wanted ) > -1, count + ' pins produced ' + className );
	} );

	// The classes are the vendored stylesheet's, not names this plugin
	// invented: a bubble with a class nothing styles is an unstyled square.
	const css = pluginSource( 'assets', 'markercluster', 'MarkerCluster.Default.css' );

	[ 'marker-cluster-small', 'marker-cluster-medium', 'marker-cluster-large' ].forEach( ( name ) => {
		assert.ok( css.includes( '.' + name ), 'MarkerCluster.Default.css no longer styles .' + name );
	} );
} );

test( 'clustering asked for without the library on the page degrades to plain pins', async () => {
	// The conditional load can fail: a site dequeues the handle, an optimiser
	// drops it, a CDN 404s. A map with every pin on it is a worse map than a
	// clustered one and an infinitely better one than a TypeError.
	const harness = loadLocator();

	delete harness.sandbox.L.markerClusterGroup;
	harness.fetchQueue.push( jsonResponse( [ store(), store( { id: 2, lat: KRAKOW.lat, lng: KRAKOW.lng } ) ] ) );

	const container = harness.locatorMarkup( { config: defaultConfig( { radius: 500, cluster: true } ) } );
	const instance = harness.SLOSM.initAll()[ 0 ];

	await instance.ready;

	assert.strictEqual( rows( container ).length, 2 );
	assert.strictEqual( harness.leafletCalls.addTo.filter( ( call ) => 'marker' === call.kind ).length, 2 );

	// Nothing a visitor should be told about, and everything a developer
	// should: the site is paying for a feature that is not arriving.
	assert.strictEqual( message( container ), null );
	assert.ok(
		harness.consoleCalls.warn.some( ( args ) => /markercluster/i.test( String( args[ 0 ] ) ) ),
		'a missing cluster library was swallowed without a word'
	);
} );

/* -------------------------------------------------------------------------
 * Clustering and the two-way highlight
 * ---------------------------------------------------------------------- */

test( 'a row still highlights when its pin is inside a cluster and has no icon', async () => {
	const harness = await locator( {
		payload: [ store(), store( { id: 2, lat: KRAKOW.lat, lng: KRAKOW.lng } ) ],
		config: defaultConfig( { radius: 500, cluster: true } ),
	} );

	const row = rows( harness.container )[ 0 ];
	const marker = harness.leafletCalls.marker[ 0 ].layer;

	// The premise, asserted rather than assumed: a clustered marker's
	// getElement() is null, which is what Task 14's paint() has to tolerate.
	assert.strictEqual( marker.getElement(), null );

	fire( row, 'mouseenter' );

	assert.ok( row.classList.contains( 'slosm__result--active' ) );

	fire( row, 'mouseleave' );

	assert.strictEqual( row.classList.contains( 'slosm__result--active' ), false );
} );

test( 'a pin that leaves its cluster while its row is lit arrives already lit', async () => {
	// The half that a null-tolerant paint() does not buy on its own. The icon
	// the highlight was meant for did not exist when the row was hovered, and
	// Leaflet builds a brand new one when the marker joins the map — with none
	// of the class on it.
	const harness = await locator( {
		payload: [ store(), store( { id: 2, lat: KRAKOW.lat, lng: KRAKOW.lng } ) ],
		config: defaultConfig( { radius: 500, cluster: true } ),
	} );

	const group = harness.leafletCalls.markerClusterGroup[ 0 ].group;
	const marker = harness.leafletCalls.marker[ 0 ].layer;

	fire( rows( harness.container )[ 0 ], 'mouseenter' );

	group.uncluster( marker );

	assert.ok(
		marker.getElement().classList.contains( 'slosm__marker--active' ),
		'the pin came out of its cluster with the highlight missing'
	);

	// And the other marker, whose row was never hovered, did not.
	group.uncluster( harness.leafletCalls.marker[ 1 ].layer );

	assert.strictEqual(
		harness.leafletCalls.marker[ 1 ].layer.getElement().classList.contains( 'slosm__marker--active' ),
		false
	);
} );

test( 'a pin that has left its cluster answers the keyboard like any other', async () => {
	const harness = await locator( {
		payload: [ store(), store( { id: 2, name: 'Kraków', lat: KRAKOW.lat, lng: KRAKOW.lng } ) ],
		config: defaultConfig( { radius: 500, cluster: true } ),
	} );

	const group = harness.leafletCalls.markerClusterGroup[ 0 ].group;
	const marker = harness.leafletCalls.marker[ 1 ].layer;

	group.uncluster( marker );

	// Leaflet gives a marker's icon tabIndex 0 and role="button", so focus is a
	// DOM event on an element that did not exist until this moment.
	fire( marker.getElement(), 'focus' );

	assert.ok( rows( harness.container )[ 1 ].classList.contains( 'slosm__result--active' ) );

	fire( marker.getElement(), 'blur' );

	assert.strictEqual( rows( harness.container )[ 1 ].classList.contains( 'slosm__result--active' ), false );
} );

test( 'hovering a clustered pin still highlights its row', async () => {
	// The pointer half needs no icon: mouseover and mouseout are layer events,
	// and markercluster leaves a child marker's own handlers in place.
	const harness = await locator( {
		payload: [ store(), store( { id: 2, name: 'Kraków', lat: KRAKOW.lat, lng: KRAKOW.lng } ) ],
		config: defaultConfig( { radius: 500, cluster: true } ),
	} );

	// The premise first. Without it this case says nothing about clustering: a
	// locator that ignored the config and put every pin on the map would answer
	// a mouseover exactly the same way, which is what search.test.js already
	// covers.
	const group = harness.leafletCalls.markerClusterGroup[ 0 ].group;

	assert.strictEqual( group.markers.length, 2 );
	assert.strictEqual( harness.leafletCalls.marker[ 1 ].layer.getElement(), null );

	harness.leafletCalls.marker[ 1 ].layer.fire( 'mouseover' );

	assert.ok( rows( harness.container )[ 1 ].classList.contains( 'slosm__result--active' ) );
	assert.strictEqual( rows( harness.container )[ 0 ].classList.contains( 'slosm__result--active' ), false );
} );

test( 'a pin does not collect a new pair of listeners every time it leaves a cluster', async () => {
	// A marker goes in and out of a bubble on every zoom, and each trip gives
	// it a fresh icon. Binding the same handlers to the same element twice
	// would be a slow leak that nothing on screen would ever show.
	const harness = await locator( {
		payload: [ store() ],
		config: defaultConfig( { radius: 500, cluster: true } ),
	} );

	const group = harness.leafletCalls.markerClusterGroup[ 0 ].group;
	const marker = harness.leafletCalls.marker[ 0 ].layer;

	group.uncluster( marker );

	const icon = marker.getElement();

	// The same element, handed back a second time. Leaflet only rebuilds the
	// icon when the marker really left the map, so an 'add' on an icon that is
	// already bound must not bind it again.
	group.uncluster( marker );

	assert.strictEqual( marker.getElement(), icon );
	assert.strictEqual( icon.listeners.get( 'focus' ).length, 1, 'the focus handler was bound twice' );
	assert.strictEqual( icon.listeners.get( 'blur' ).length, 1, 'the blur handler was bound twice' );
} );

/* -------------------------------------------------------------------------
 * Discipline: what Task 15 must NOT have built
 * ---------------------------------------------------------------------- */

test( 'Task 15 built its own three things and none of Task 16\'s', async () => {
	const source = codeOnly( pluginSource( 'assets', 'js', 'locator.js' ) );

	// The control, and the other half of the list search.test.js used to
	// carry: every name Task 15 took over is asserted present, in code, so the
	// absences below are about restraint rather than about an empty file.
	[
		'markerClusterGroup',
		'getCurrentPosition',
		'navigator',
		'slosm__locate',
		'slosm__category',
		'divIcon',
	].forEach( ( expected ) => {
		assert.ok( source.includes( expected ), 'locator.js does not contain ' + expected + ', so a scan for what is absent proves nothing' );
	} );

	// 'google.com/maps' was in this list and was guarding nothing: this plugin
	// exists so that a locator needs no Google Maps key, so a scan for it
	// forbids a thing nobody was going to write. What Task 16 actually needed
	// was a directions link on openstreetmap.org, and the host was no use as a
	// pattern — locator.js already contains tile.openstreetmap.org and always
	// will, because the tile layer is built from it — so the path was what was
	// forbidden.
	//
	// Four of the six names below left this list when Task 16 built them:
	// bindPopup, openPopup, result-directions and '/directions'. They are
	// asserted PRESENT in tests/js/popup.test.js, which is where the scan now
	// does its work; deleting them here without that would have turned a
	// restraint into an absence nobody checks. What is still nobody's is a
	// tooltip — a second overlay with the same innerHTML hazard and no task
	// asking for it — and a routing engine, which would mean this plugin
	// calculating a route rather than handing the question to a site that
	// already does.
	[ 'bindTooltip', 'routing' ].forEach( ( forbidden ) => {
		assert.ok( ! source.includes( forbidden ), 'locator.js reaches for ' + forbidden + ', which is no task\'s' );
	} );

	// And the control: the scan is over real code with real names in it, so a
	// forbidden name that is absent is absent from something rather than from
	// nothing.
	assert.ok( source.includes( 'openstreetmap.org' ), 'locator.js no longer contains the tile host' );
	assert.ok( source.includes( 'bindPopup' ), 'locator.js lost the popup Task 16 built' );
} );
