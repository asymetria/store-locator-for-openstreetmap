/**
 * Tasks 29a and 29b: the "Within" and "Show at most" selects, wired to the
 * search, and then wired to re-run it.
 *
 * WHY THESE CASES EXIST, WHICH IS THE WHOLE POINT OF THE FILE
 * ==========================================================
 * Both selects were decorative. `entriesFor()` read `config.radius` and
 * `config.limit` to filter a preloaded list, `locate()` read the same two to
 * build the /stores query, and nothing anywhere wrote them — so every search
 * used whatever the shortcode or the Bricks element had pinned as the
 * *starting* value, whatever the control on screen said.
 *
 * It was found on a live site rather than here: a search for Łódź with the
 * radius select showing 500 km answered "No results" over two locations about
 * 120 km away, because the radius in force was the element's starting 10.
 *
 * 369 cases were quiet about it, and the reason is the lesson. The filter had
 * cases and the query building had cases, and both passed, because every one of
 * them handed the filter a radius *by hand*. A case that calls the thing under
 * test with a number cannot fail on a defect in how that number is chosen, no
 * matter what the code does. So every case below drives the control and then
 * asserts on an outcome — the rows on screen, or the request that went out —
 * and none of them names a radius anywhere except on the select.
 *
 * WHAT `choose()` REFUSES TO DO, AND WHY IT MATTERS MORE THAN IT LOOKS
 * ===================================================================
 * tests/js/harness.js says plainly that a stub `<select>` has no relationship
 * between its `value` and its options: setting `.value` stores any string. A
 * browser does not work that way — its `value` is always one of the options, or
 * `''`. So a case that set `.value = '250'` on a select offering nothing of the
 * kind would be proving the wire against a selection no visitor could have
 * made, and a locator whose select rendered the wrong options would still look
 * fixed. `choose()` below asserts the value is really on offer before it sets
 * it, and `locatorMarkup()` now builds both selects the way
 * Shortcode::radius_options() and limit_options() build them, so what is on
 * offer is what the plugin emits.
 *
 * TASK 29b: AND NOW THE CHANGE ITSELF RE-RUNS
 * ===========================================
 * 29a left one thing on the table and said so in both readmes and in a case:
 * the two selects reached the *next* search, and changing one redrew nothing.
 * A visitor moved the radius from 10 km to 500 km and watched the same "No
 * results" sit there. 29b removes that, and the case that pinned the old
 * boundary — "changing a select does not redraw anything by itself" — was
 * deliberately replaced by its opposite rather than deleted.
 *
 * The two paths answer the same change differently and both are below. Under
 * the preload threshold the whole list is in memory, so the answer is a filter
 * and **no request at all**; above it the answer is one request to this
 * plugin's own /stores route, from the origin the last search already
 * resolved. Neither reaches /geocode, and neither reaches /suggest, which are
 * the two routes Geocoder applies its one-request-per-second gap on — "a re-run
 * never reaches the geocoder" is that, as a case, with the typed search beside
 * it as the control that proves the fixture can reach it.
 *
 * WHAT THIS SLICE IS STILL NOT
 * ============================
 * There is no search button and no `<form>`: the address field still needs
 * Enter, and that is the next slice. Nothing below asserts otherwise.
 *
 * TASK 29c, WHICH BUILT HALF OF THAT SENTENCE AND REFUSED THE OTHER HALF
 * =====================================================================
 * The button exists now, and it is in tests/js/search.test.js because it
 * belongs to the address field rather than to these two selects — the field is
 * the only control here whose use costs an upstream lookup, and the only one
 * where somebody decides "this, now". The `<form>` was decided against rather
 * than deferred; the argument is in Shortcode's comment beside the button.
 *
 * Nothing in this file changed for it. The fixture builds the button by
 * default from that task on, and none of the cases below presses it: a filter
 * case that reached for the search control would stop being a filter case.
 */

'use strict';

const { test } = require( 'node:test' );
const assert = require( 'node:assert' );

const {
	defaultConfig,
	defaultStrings,
	deferredResponse,
	fire,
	jsonResponse,
	loadLocator,
} = require( './harness.js' );

/** Warsaw, Łódź and Kraków, as everywhere else in this suite. */
const WARSAW = { lat: 52.2297, lng: 21.0122 };
const LODZ = { lat: 51.7592, lng: 19.456 };
const KRAKOW = { lat: 50.0647, lng: 19.945 };

/**
 * The two distances every radius case below turns on, to three decimals, as
 * Geo.distance() computes them with EARTH_RADIUS_KM = 6371.
 *
 * Łódź → Warsaw  118.696 km
 * Łódź → Kraków  191.512 km
 *
 * So from Łódź: 100 reaches neither, 150 reaches Warsaw alone, and 250 reaches
 * both. Three real options with three different answers, which is what makes
 * these cases about *which* number was used rather than about "a bigger one".
 * 150 is on offer because Shortcode::with_current() merges the locator's own
 * starting value into the site's steps; 100 and 250 are two of the steps.
 */
const RADIUS_START = 150;

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

/** The three cities as a preload payload, in an order no assertion below expects. */
function cities() {
	return [
		store( { id: 1, name: 'Kraków', lat: KRAKOW.lat, lng: KRAKOW.lng } ),
		store( { id: 2, name: 'Warsaw' } ),
	];
}

/**
 * A locator, started.
 *
 * The starting radius is 150 and the starting limit is 500 unless a case says
 * otherwise, and both are *starting* values in the sense the panel means: the
 * select still offers every step the site has, with that one selected.
 *
 * @param {object} options `config`, `payload`, and the `with*` markup switches.
 * @returns {Promise<object>} The harness, plus `container` and `instance`.
 */
async function locator( options ) {
	const settings = options || {};
	const harness = loadLocator( settings );
	const config =
		'config' in settings ? settings.config : defaultConfig( { radius: RADIUS_START } );

	if ( 'payload' in settings ) {
		harness.fetchQueue.push(
			Array.isArray( settings.payload ) ? jsonResponse( settings.payload ) : settings.payload
		);
	}

	const container = harness.locatorMarkup( {
		config,
		withRadius: settings.withRadius,
		withRadiusOptions: settings.withRadiusOptions,
		withLimit: settings.withLimit,
		withLimitOptions: settings.withLimitOptions,
	} );

	const instances = harness.SLOSM.initAll();

	if ( instances[ 0 ] && instances[ 0 ].ready ) {
		await instances[ 0 ].ready;
	}

	return Object.assign( harness, { container, instance: instances[ 0 ] || null } );
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

/** @returns {Array<string>} Every option value on one of the two selects. */
function offered( container, name ) {
	return container.querySelector( '.slosm__' + name ).children.map( ( option ) => option.value );
}

/**
 * Chooses a value on one of the two selects, the way a pointer would.
 *
 * The assertion is the point of the helper. See the file header.
 *
 * @param {object} container The locator.
 * @param {string} name      'radius' or 'limit'.
 * @param {*}      value     The value to choose.
 * @returns {object} The event object the handlers saw.
 */
function choose( container, name, value ) {
	const control = container.querySelector( '.slosm__' + name );
	const options = offered( container, name );

	assert.ok(
		options.indexOf( String( value ) ) > -1,
		'the ' + name + ' select does not offer "' + value + '", so choosing it is a selection no browser would let anybody make; on offer: ' + options.join( ', ' )
	);

	control.value = String( value );

	return fire( control, 'change' );
}

/**
 * Types an address and presses Enter, and waits for the whole search.
 *
 * @param {object}      harness The harness.
 * @param {string}      text    What to type.
 * @param {object}      place   What /geocode answers with.
 * @param {Array|undefined} items What /stores answers with, in query mode.
 * @returns {Promise<void>} When the search has settled.
 */
async function search( harness, text, place, items ) {
	return submit( harness, harness.container, harness.instance, text, place, items );
}

/**
 * The same, for a page carrying more than one locator.
 *
 * @param {object}      harness   The harness.
 * @param {object}      container The locator to type into.
 * @param {object}      instance  Its instance.
 * @param {string}      text      What to type.
 * @param {object}      place     What /geocode answers with.
 * @param {Array|undefined} items What /stores answers with, in query mode.
 * @returns {Promise<void>} When the search has settled.
 */
async function submit( harness, container, instance, text, place, items ) {
	harness.fetchQueue.push( jsonResponse( { lat: place.lat, lng: place.lng, label: text } ) );

	if ( undefined !== items ) {
		harness.fetchQueue.push( jsonResponse( items ) );
	}

	const field = container.querySelector( '.slosm__search' );

	field.value = text;
	fire( field, 'keydown', { key: 'Enter' } );

	await instance.pendingSearch;
}

/**
 * Moves one of the two number controls and waits for whatever it set off.
 *
 * The wait is the only reason this exists beside choose(). On the preloaded
 * path there is nothing to wait for — the redraw is synchronous and every case
 * below that uses that path calls choose() directly, so that "no request" is
 * asserted against a list that is already drawn. In query mode the change
 * makes a request, which needs an answer queued for it and a turn to land.
 *
 * @param {object} harness The harness.
 * @param {string} name    'radius' or 'limit'.
 * @param {*}      value   The value to choose.
 * @param {Array}  items   What /stores answers the re-run with.
 * @returns {Promise<void>} When the re-run has settled.
 */
async function change( harness, name, value, items ) {
	harness.fetchQueue.push( jsonResponse( items ) );

	choose( harness.container, name, value );

	await harness.instance.pendingSearch;
}

/** @returns {URLSearchParams} The query of the last request that went out. */
function lastQuery( harness ) {
	return new URL( harness.fetchCalls[ harness.fetchCalls.length - 1 ].url ).searchParams;
}

/* -------------------------------------------------------------------------
 * The preloaded path: the radius filters a list already in memory
 * ---------------------------------------------------------------------- */

test( 'a radius chosen before a search is the radius the preloaded list is filtered by', async () => {
	// The live defect, as a case. Every number here comes off the select; the
	// only radius this file names is the starting one, and that is named so
	// that changing it can be seen to change nothing.
	const harness = await locator( { payload: cities() } );

	await search( harness, 'Łódź', LODZ );

	assert.deepStrictEqual(
		names( harness.container ),
		[ 'Warsaw' ],
		'the starting radius of 150 should reach Warsaw at 118.7 km and not Kraków at 191.5 km'
	);

	// Wider. Both cities are inside 250 km of Łódź.
	choose( harness.container, 'radius', 250 );

	await search( harness, 'Łódź', LODZ );

	assert.deepStrictEqual(
		names( harness.container ),
		[ 'Warsaw', 'Kraków' ],
		'the search used something other than the 250 the control was showing'
	);

	// Narrower, which is the half a fix that simply stopped filtering would
	// pass the first half of and fail here.
	choose( harness.container, 'radius', 100 );

	await search( harness, 'Łódź', LODZ );

	assert.deepStrictEqual( names( harness.container ), [] );
	assert.strictEqual( message( harness.container ), defaultStrings().noResults );
} );

test( 'a radius chosen before a search is the radius the /stores query carries', async () => {
	// Query mode has no list to filter, so the same choice has to reach the
	// server instead. Both paths, one control.
	const harness = await locator( {
		config: defaultConfig( { mode: 'query', count: 900, radius: RADIUS_START, limit: 25 } ),
	} );

	await search( harness, 'Łódź', LODZ, [] );

	assert.strictEqual( lastQuery( harness ).get( 'radius' ), '150' );

	choose( harness.container, 'radius', 250 );

	await search( harness, 'Łódź', LODZ, [] );

	assert.strictEqual(
		lastQuery( harness ).get( 'radius' ),
		'250',
		'the request asked for the starting radius rather than the one on screen'
	);

	// A number, not the string the select holds. The url builder stringifies
	// whatever it is given, so this half of the wire cannot be seen here — it
	// is seen in the preloaded case above, where a string reaches
	// finiteNumber(), comes back null, and turns the radius filter off
	// altogether.
	assert.strictEqual( lastQuery( harness ).get( 'limit' ), '25' );
} );

/* -------------------------------------------------------------------------
 * The preloaded path: the count decides how many rows are drawn
 * ---------------------------------------------------------------------- */

test( 'a result count chosen before a search is how many rows the preloaded list draws', async () => {
	// A starting count of 2 over a payload of three, so the limit is doing
	// something before anybody touches anything.
	const harness = await locator( {
		config: defaultConfig( { radius: 500, limit: 2 } ),
		payload: [
			store( { id: 1, name: 'Warsaw' } ),
			store( { id: 2, name: 'Łódź', lat: LODZ.lat, lng: LODZ.lng } ),
			store( { id: 3, name: 'Kraków', lat: KRAKOW.lat, lng: KRAKOW.lng } ),
		],
	} );

	assert.strictEqual( rows( harness.container ).length, 2, 'the starting count was not applied on load' );

	choose( harness.container, 'limit', 10 );

	await search( harness, 'Warszawa', WARSAW );

	assert.deepStrictEqual(
		names( harness.container ),
		[ 'Warsaw', 'Łódź', 'Kraków' ],
		'the search drew the starting count rather than the one on screen'
	);

	// And back down, so this is not a case about a limit that stopped being
	// applied. 10 is an option; 2 is the starting value merged into the steps.
	choose( harness.container, 'limit', 2 );

	await search( harness, 'Warszawa', WARSAW );

	assert.deepStrictEqual( names( harness.container ), [ 'Warsaw', 'Łódź' ] );
} );

test( 'a result count chosen before a search is the limit the /stores query carries', async () => {
	const harness = await locator( {
		config: defaultConfig( { mode: 'query', count: 900, radius: 500, limit: 2 } ),
	} );

	await search( harness, 'Łódź', LODZ, [] );

	assert.strictEqual( lastQuery( harness ).get( 'limit' ), '2' );

	choose( harness.container, 'limit', 25 );

	await search( harness, 'Łódź', LODZ, [] );

	assert.strictEqual(
		lastQuery( harness ).get( 'limit' ),
		'25',
		'the request asked for the starting count rather than the one on screen'
	);
	assert.strictEqual( lastQuery( harness ).get( 'radius' ), '500', 'the radius moved when only the count was touched' );
} );

/* -------------------------------------------------------------------------
 * Task 29b: the change itself re-runs
 * ---------------------------------------------------------------------- */

test( 'changing the radius alone updates the preloaded results, with no request at all', async () => {
	// This case replaces 29a's "changing a select does not redraw anything by
	// itself", deliberately and with its opposite. That case existed so that
	// the old boundary could not move by accident; moving it is this task.
	//
	// One control, and nothing else: no Enter, no second address, no button.
	// Below the preload threshold the whole list is already in the browser, so
	// the right answer to a filter change is a filter and not a round trip.
	const harness = await locator( { payload: cities() } );

	await search( harness, 'Łódź', LODZ );

	assert.deepStrictEqual( names( harness.container ), [ 'Warsaw' ] );

	const spent = harness.fetchCalls.length;

	choose( harness.container, 'radius', 250 );

	assert.deepStrictEqual(
		names( harness.container ),
		[ 'Warsaw', 'Kraków' ],
		'widening the radius left the visitor looking at the list they already had'
	);
	assert.strictEqual(
		harness.fetchCalls.length,
		spent,
		'a preloaded locator went to the server for a list it was holding in memory'
	);

	// Narrower, which is the half that a re-run which merely stopped filtering
	// would pass the first of and fail here.
	choose( harness.container, 'radius', 100 );

	assert.deepStrictEqual( names( harness.container ), [] );
	assert.strictEqual( message( harness.container ), defaultStrings().noResults );
	assert.strictEqual( harness.fetchCalls.length, spent );

	// And back out of empty, because "no results" is a state a re-run has to be
	// able to leave as well as to reach.
	choose( harness.container, 'radius', 250 );

	assert.deepStrictEqual( names( harness.container ), [ 'Warsaw', 'Kraków' ] );
	assert.strictEqual( harness.fetchCalls.length, spent );
} );

test( 'changing the radius alone re-asks /stores from the origin already found', async () => {
	// The other path. Query mode holds no list, so the same change has to
	// become a request — one request, to this plugin's own route, carrying the
	// point the last search resolved rather than the text somebody typed.
	const harness = await locator( {
		config: defaultConfig( { mode: 'query', count: 900, radius: RADIUS_START, limit: 25 } ),
	} );

	await search( harness, 'Łódź', LODZ, [ store( { id: 1, name: 'Warsaw', distance: 118.696 } ) ] );

	assert.deepStrictEqual( names( harness.container ), [ 'Warsaw' ] );
	assert.strictEqual( lastQuery( harness ).get( 'radius' ), '150' );

	const first = lastQuery( harness );
	const spent = harness.fetchCalls.length;

	await change( harness, 'radius', 250, [
		store( { id: 1, name: 'Warsaw', distance: 118.696 } ),
		store( { id: 2, name: 'Kraków', lat: KRAKOW.lat, lng: KRAKOW.lng, distance: 191.512 } ),
	] );

	assert.strictEqual(
		harness.fetchCalls.length,
		spent + 1,
		'one control moved and the number of requests was not one more'
	);
	assert.strictEqual(
		lastQuery( harness ).get( 'radius' ),
		'250',
		'the re-run asked for the radius the last search used rather than the one on screen'
	);
	assert.deepStrictEqual( names( harness.container ), [ 'Warsaw', 'Kraków' ] );

	// Searched from the same point, which is what makes the re-run free of the
	// geocoder: the origin was resolved once and is reused. Asserted against
	// the first search's own query rather than against a literal, so this
	// cannot drift if the url builder ever rounds a coordinate.
	assert.ok( first.get( 'lat' ), 'the first /stores request carried no lat, so comparing to it proves nothing' );
	assert.strictEqual( lastQuery( harness ).get( 'lat' ), first.get( 'lat' ) );
	assert.strictEqual( lastQuery( harness ).get( 'lng' ), first.get( 'lng' ) );
} );

test( 'changing the result count alone updates the results on both paths', async () => {
	// The same again for the other control, because a wire soldered for one of
	// two selects is exactly the defect 29a was fixing, one slice on.
	const preload = await locator( {
		config: defaultConfig( { radius: 500, limit: 2 } ),
		payload: [
			store( { id: 1, name: 'Warsaw' } ),
			store( { id: 2, name: 'Łódź', lat: LODZ.lat, lng: LODZ.lng } ),
			store( { id: 3, name: 'Kraków', lat: KRAKOW.lat, lng: KRAKOW.lng } ),
		],
	} );

	await search( preload, 'Warszawa', WARSAW );

	assert.deepStrictEqual( names( preload.container ), [ 'Warsaw', 'Łódź' ] );

	const spent = preload.fetchCalls.length;

	choose( preload.container, 'limit', 10 );

	assert.deepStrictEqual(
		names( preload.container ),
		[ 'Warsaw', 'Łódź', 'Kraków' ],
		'raising the count left the rows the visitor already had'
	);
	assert.strictEqual( preload.fetchCalls.length, spent );

	// And down again, so this is not a case about a limit that stopped being
	// applied at all.
	choose( preload.container, 'limit', 2 );

	assert.deepStrictEqual( names( preload.container ), [ 'Warsaw', 'Łódź' ] );

	// Query mode: one request, the new limit on it, and the radius untouched.
	const query = await locator( {
		config: defaultConfig( { mode: 'query', count: 900, radius: 500, limit: 2 } ),
	} );

	await search( query, 'Łódź', LODZ, [] );

	assert.strictEqual( lastQuery( query ).get( 'limit' ), '2' );

	await change( query, 'limit', 25, [] );

	assert.strictEqual( lastQuery( query ).get( 'limit' ), '25' );
	assert.strictEqual(
		lastQuery( query ).get( 'radius' ),
		'500',
		'the radius moved on a re-run that only the count set off'
	);
} );

test( 'a re-run never reaches the geocoder, on either path', async () => {
	// The claim worth pinning rather than asserting in a commit message. The
	// only place in locator.js that reads routes.geocode is submitSearch(),
	// where somebody typed an address, and Geocoder applies its
	// one-request-per-second courtesy gap per upstream service, on the two
	// routes that talk to one. So a visitor stepping a select through four
	// values queues behind neither, and that is a property of the code rather
	// than a hope about it.
	const routes = defaultConfig().routes;

	// Preload: the whole re-run happens in memory, so there is no request at
	// all to check the route of. Asserted as a count, twice over.
	const preload = await locator( { payload: cities() } );

	await search( preload, 'Łódź', LODZ );

	const spent = preload.fetchCalls.length;

	choose( preload.container, 'radius', 250 );
	choose( preload.container, 'limit', 10 );

	assert.strictEqual( preload.fetchCalls.length, spent, 'a preloaded re-run went to the network' );

	// Query mode, where there really are requests to look at.
	const query = await locator( {
		config: defaultConfig( { mode: 'query', count: 900, radius: RADIUS_START, limit: 25 } ),
	} );

	await search( query, 'Łódź', LODZ, [] );

	const geocoded = ( harness ) =>
		harness.fetchCalls.filter( ( call ) => call.url.startsWith( routes.geocode ) ).length;

	// The control for the absence below, and it is not optional: without it
	// "no /geocode" is also what a fixture that cannot reach the geocoder at
	// all would report. The typed search did reach it, exactly once.
	assert.strictEqual( geocoded( query ), 1, 'the typed search never asked /geocode, so this fixture proves nothing' );

	const after = query.fetchCalls.length;

	await change( query, 'radius', 250, [] );
	await change( query, 'limit', 50, [] );

	const since = query.fetchCalls.slice( after );

	assert.strictEqual( since.length, 2, 'two changes did not make two requests' );

	since.forEach( ( call ) => {
		assert.ok(
			call.url.startsWith( routes.stores ),
			'a re-run asked ' + call.url + ' rather than this plugin\'s /stores route'
		);
	} );

	assert.strictEqual( geocoded( query ), 1, 'a re-run looked the address up a second time' );
} );

test( 'a change before any search does what each mode can do with it, and no more', async () => {
	// The deliberate answer to "there is no origin yet", which is a different
	// question in each mode rather than one rule applied twice.
	//
	// PRELOAD. The count is not a fact about a search — entriesFor() applies it
	// to an unsorted list too, and says so — so a locator nobody has searched
	// still honours it, and changing it still redraws. The radius is a fact
	// about a search: with no centre there is nothing to measure from, so
	// nothing moves and nothing claims to have moved.
	const preload = await locator( {
		config: defaultConfig( { radius: 500, limit: 2 } ),
		payload: [
			store( { id: 1, name: 'Warsaw' } ),
			store( { id: 2, name: 'Łódź', lat: LODZ.lat, lng: LODZ.lng } ),
			store( { id: 3, name: 'Kraków', lat: KRAKOW.lat, lng: KRAKOW.lng } ),
		],
	} );

	assert.strictEqual( preload.instance.origin, null, 'the fixture searched something before the case did' );
	assert.strictEqual( rows( preload.container ).length, 2 );

	const spent = preload.fetchCalls.length;

	choose( preload.container, 'limit', 10 );

	assert.strictEqual(
		rows( preload.container ).length,
		3,
		'"Show at most" did nothing on a locator nobody had searched, where it is the only filter that can do anything'
	);
	assert.strictEqual( preload.fetchCalls.length, spent );
	assert.strictEqual( message( preload.container ), found( 3 ) );

	choose( preload.container, 'radius', 5 );

	assert.strictEqual( rows( preload.container ).length, 3, 'a radius with no centre filtered something' );
	assert.strictEqual(
		message( preload.container ),
		found( 3 ),
		'a redraw said something other than what it found'
	);
	assert.strictEqual( preload.instance.origin, null );

	// The control for that absence: the 5 km was kept, and it bites the moment
	// there is somewhere to measure from.
	await search( preload, 'Warszawa', WARSAW );

	assert.deepStrictEqual( names( preload.container ), [ 'Warsaw' ] );

	// QUERY MODE. Nothing on screen to redraw and nowhere to search from, and
	// asking /stores for everything anywhere is the whole-list request this
	// mode exists to avoid making. So: nothing.
	const query = await locator( {
		config: defaultConfig( { mode: 'query', count: 900, radius: RADIUS_START, limit: 25 } ),
	} );

	assert.strictEqual( query.instance.origin, null );
	assert.strictEqual( query.fetchCalls.length, 0, 'a query-mode locator fetched a list on load' );

	choose( query.container, 'radius', 250 );
	choose( query.container, 'limit', 50 );

	assert.strictEqual(
		query.fetchCalls.length,
		0,
		'a locator with no origin asked /stores for every location on the site'
	);
	assert.strictEqual( rows( query.container ).length, 0 );
	assert.strictEqual( message( query.container ), null );

	// The control: the two choices were not thrown away, they were waiting for
	// somewhere to spend them.
	await search( query, 'Łódź', LODZ, [] );

	assert.strictEqual( lastQuery( query ).get( 'radius' ), '250' );
	assert.strictEqual( lastQuery( query ).get( 'limit' ), '50' );
} );

test( 'two changes in a second leave the second answer on screen and cancel the first', async () => {
	// A visitor can move two controls faster than a network answers one. The
	// machinery for it was already here and is deliberately not duplicated:
	// beginSearch() aborts the run in flight and mints a token, and locate()
	// drops an answer whose token has moved on. This is the case that says the
	// re-run goes through that machinery rather than around it.
	const harness = await locator( {
		config: defaultConfig( { mode: 'query', count: 900, radius: RADIUS_START, limit: 25 } ),
	} );

	await search( harness, 'Łódź', LODZ, [] );

	const spent = harness.fetchCalls.length;
	const slow = deferredResponse();

	harness.fetchQueue.push( slow );

	choose( harness.container, 'radius', 250 );

	assert.strictEqual( harness.fetchCalls.length, spent + 1 );

	const abandoned = harness.instance.pendingSearch;
	const overtaken = harness.fetchCalls[ spent ];

	assert.ok( overtaken.signal, 'a re-run was sent with no AbortSignal at all' );
	assert.strictEqual( overtaken.signal.aborted, false );

	// The second control moves before the first answer lands. Not through
	// change(), because change() awaits instance.pendingSearch and this case is
	// the one place where that can be a promise nobody will ever settle: a
	// locator that failed to wire the second control leaves pendingSearch
	// pointing at the first re-run, which is held open by `slow`. The
	// assertion below comes first for exactly that reason — under a defect the
	// case fails here in milliseconds rather than hanging the suite, which is
	// how M2 was found.
	harness.fetchQueue.push(
		jsonResponse( [ store( { id: 2, name: 'Kraków', lat: KRAKOW.lat, lng: KRAKOW.lng, distance: 191.512 } ) ] )
	);

	choose( harness.container, 'limit', 50 );

	assert.strictEqual(
		harness.fetchCalls.length,
		spent + 2,
		'the second control moved and no second request went out, so the await below would be a wait on the first'
	);

	await harness.instance.pendingSearch;

	assert.deepStrictEqual( names( harness.container ), [ 'Kraków' ] );
	assert.strictEqual(
		overtaken.signal.aborted,
		true,
		'the superseded re-run was left running, which is a request the site spent on nobody'
	);

	// And the late answer does not take the list back when it arrives.
	slow.resolve( jsonResponse( [ store( { id: 1, name: 'Warsaw', distance: 118.696 } ) ] ) );

	await abandoned;

	assert.deepStrictEqual(
		names( harness.container ),
		[ 'Kraków' ],
		'the abandoned re-run redrew the list of the one that overtook it'
	);
	assert.strictEqual( lastQuery( harness ).get( 'limit' ), '50' );
	assert.strictEqual(
		harness.container.classList.contains( 'slosm--error' ),
		false,
		'a request this locator cancelled itself was reported to the visitor as a failure'
	);
} );

test( 'a change on one locator redraws that locator and no other', async () => {
	// Instances share nothing by design — no registry, no index — and Task 29a
	// has two cases saying so about *reading* the selects. This is the same
	// separation for *listening* to them, and it needs its own page for the
	// same reason those did: on a page with one locator,
	// document.querySelector and element.querySelector cannot disagree, so no
	// other case here can tell a handler bound to this locator's select from
	// one bound to the first select on the page.
	const harness = loadLocator();

	harness.fetchQueue.push( jsonResponse( cities() ) );
	harness.fetchQueue.push( jsonResponse( cities() ) );

	const config = defaultConfig( { radius: RADIUS_START } );
	const first = harness.locatorMarkup( { config } );
	const second = harness.locatorMarkup( { config } );
	const instances = harness.SLOSM.initAll();

	await Promise.all( instances.map( ( instance ) => instance.ready ) );

	await submit( harness, first, instances[ 0 ], 'Łódź', LODZ );
	await submit( harness, second, instances[ 1 ], 'Łódź', LODZ );

	assert.deepStrictEqual( names( first ), [ 'Warsaw' ] );
	assert.deepStrictEqual( names( second ), [ 'Warsaw' ] );

	// Only the second is widened, and only the second moves.
	choose( second, 'radius', 250 );

	assert.deepStrictEqual( names( second ), [ 'Warsaw', 'Kraków' ] );
	assert.deepStrictEqual( names( first ), [ 'Warsaw' ], 'a change on one locator redrew another one' );

	// And the other way round, so this is not a case about a second locator
	// whose select simply does nothing.
	choose( first, 'radius', 100 );

	assert.deepStrictEqual( names( first ), [] );
	assert.deepStrictEqual(
		names( second ),
		[ 'Warsaw', 'Kraków' ],
		'a change on the first locator emptied the second one'
	);
} );

test( 'the count that is announced is a number, not the placeholder it was written with', async () => {
	// `resultsFound` is 'Locations found: %s' and the substitution is one
	// .replace() in show(). A case that only checked the sentence was there
	// would pass against a locator announcing "Locations found: %s" to
	// everybody who ever searched it.
	const harness = await locator( { payload: cities() } );

	await search( harness, 'Łódź', LODZ );

	const said = harness.container.querySelector( '.slosm__message' ).textContent;

	assert.ok( said.indexOf( '%s' ) === -1, 'the placeholder reached the page' );
	assert.ok( /\d/.test( said ), 'the sentence carries no number at all' );
	assert.strictEqual( said, found( 1 ) );
} );

test( 'a redraw nobody pressed a button for says how many it found, and never talks over "No results"', async () => {
	// A list that replaces itself changes under somebody without saying why,
	// and a change nobody clicked into is exactly the one that needs saying.
	// Since Task 24c the saying is the status line's job: the list is not a
	// live region, so silence here really is silence. Since Task 24d what is
	// said is the count, which answers "what changed" instead of asserting
	// that something did.
	const harness = await locator( { payload: cities() } );

	await search( harness, 'Łódź', LODZ );

	assert.deepStrictEqual( names( harness.container ), [ 'Warsaw' ] );
	assert.strictEqual( message( harness.container ), found( 1 ), 'a finished search said something other than its count' );

	choose( harness.container, 'radius', 250 );

	assert.strictEqual(
		message( harness.container ),
		found( 2 ),
		'the list changed under somebody who cannot see it and nothing said so'
	);

	// Beside the rows rather than instead of them, and in the status line
	// Shortcode::render() emits — which since Task 24c is the locator's one
	// live region, and is outside the list precisely so that a sentence about
	// the rows is not read out as part of them.
	assert.deepStrictEqual( names( harness.container ), [ 'Warsaw', 'Kraków' ] );
	assert.ok( harness.container.querySelector( '.slosm__message' ) );
	assert.strictEqual( harness.container.querySelector( '.slosm__results' ).querySelector( '.slosm__message' ), null );

	// A change that empties the list keeps show()'s own sentence, which is the
	// one with something in it. One message, not two stacked up.
	choose( harness.container, 'radius', 100 );

	assert.deepStrictEqual( names( harness.container ), [] );
	assert.strictEqual( message( harness.container ), defaultStrings().noResults );
	assert.strictEqual( harness.container.querySelectorAll( '.slosm__message' ).length, 1 );

	// And a redraw that moved nothing says the same number again rather than
	// falling silent. From Łódź, 250 km and 500 km reach the same two cities,
	// and "Locations found: 2" is true both times — which is the trade Task
	// 24d made deliberately: the sentence it replaced had to know whether
	// anything had changed, and this one only has to be able to count.
	choose( harness.container, 'radius', 250 );

	assert.strictEqual( message( harness.container ), found( 2 ) );

	choose( harness.container, 'radius', 500 );

	assert.deepStrictEqual( names( harness.container ), [ 'Warsaw', 'Kraków' ] );
	assert.strictEqual( message( harness.container ), found( 2 ), 'a redraw said something other than what it found' );

	// The query path gets there the long way and arrives at the same sentence:
	// the rows are a request away, so it says "Searching…" first and the count
	// only once the answer is in. locate() says what a typed search says.
	const query = await locator( {
		config: defaultConfig( { mode: 'query', count: 900, radius: RADIUS_START, limit: 25 } ),
	} );

	await search( query, 'Łódź', LODZ, [] );

	const slow = deferredResponse();

	query.fetchQueue.push( slow );

	choose( query.container, 'radius', 250 );

	assert.strictEqual( message( query.container ), defaultStrings().searching );

	slow.resolve( jsonResponse( [ store( { id: 1, name: 'Warsaw', distance: 118.696 } ) ] ) );

	await query.instance.pendingSearch;

	assert.deepStrictEqual( names( query.container ), [ 'Warsaw' ] );
	assert.strictEqual( message( query.container ), found( 1 ), 'the answer arrived and "Searching…" was still up' );
} );

test( 'a value a browser restored without a change event is still the one the search uses', async () => {
	// A reload with form restoration, or a page builder writing a value into
	// the markup: the select comes up showing one thing and no change event was
	// ever fired. Reading the control when the search runs is what makes that
	// work; recording it on change would search with the starting radius under
	// a control saying something else, which is this defect wearing a hat.
	const harness = await locator( { payload: cities() } );
	const control = harness.container.querySelector( '.slosm__radius' );

	assert.ok( offered( harness.container, 'radius' ).indexOf( '250' ) > -1 );

	control.value = '250';

	await search( harness, 'Łódź', LODZ );

	assert.deepStrictEqual( names( harness.container ), [ 'Warsaw', 'Kraków' ] );
} );

test( 'two locators on one page each read their own select', async () => {
	// Instances share nothing by design — no registry, no index — and a lookup
	// that went to the document rather than to the container would quietly
	// undo that: whichever locator was rendered first would set the radius for
	// every locator on the page. This is the case that separates
	// `instance.element.querySelector` from `document.querySelector`, and no
	// other case here can, because each of them builds a page with one locator
	// on it.
	const harness = loadLocator();

	harness.fetchQueue.push( jsonResponse( cities() ) );
	harness.fetchQueue.push( jsonResponse( cities() ) );

	const config = defaultConfig( { radius: RADIUS_START } );
	const first = harness.locatorMarkup( { config } );
	const second = harness.locatorMarkup( { config } );
	const instances = harness.SLOSM.initAll();

	await Promise.all( instances.map( ( instance ) => instance.ready ) );

	// Only the second is widened.
	choose( second, 'radius', 250 );

	await submit( harness, first, instances[ 0 ], 'Łódź', LODZ );
	await submit( harness, second, instances[ 1 ], 'Łódź', LODZ );

	assert.deepStrictEqual( names( first ), [ 'Warsaw' ], 'the first locator took the second one\'s radius' );
	assert.deepStrictEqual( names( second ), [ 'Warsaw', 'Kraków' ] );

	// And the other way round, so this is not an assertion that the second
	// select simply does nothing.
	choose( first, 'radius', 250 );
	choose( second, 'radius', 100 );

	await submit( harness, first, instances[ 0 ], 'Łódź', LODZ );
	await submit( harness, second, instances[ 1 ], 'Łódź', LODZ );

	assert.deepStrictEqual( names( first ), [ 'Warsaw', 'Kraków' ] );
	assert.deepStrictEqual( names( second ), [] );
} );

test( 'two locators on one page each read their own result count', async () => {
	// The same separation for the other control, and it needs its own page
	// because the radius case above has to hold the two counts equal to say
	// anything about radius at all. Here the radius is held wide and the two
	// counts differ — from the configs alone, before anybody touches anything,
	// which is the shape a site with a "top 1 nearest" map beside a full list
	// actually has.
	const harness = loadLocator();

	harness.fetchQueue.push( jsonResponse( cities() ) );
	harness.fetchQueue.push( jsonResponse( cities() ) );

	const many = harness.locatorMarkup( { config: defaultConfig( { radius: 500, limit: 10 } ) } );
	const one = harness.locatorMarkup( { config: defaultConfig( { radius: 500, limit: 1 } ) } );
	const instances = harness.SLOSM.initAll();

	await Promise.all( instances.map( ( instance ) => instance.ready ) );

	await submit( harness, many, instances[ 0 ], 'Łódź', LODZ );
	await submit( harness, one, instances[ 1 ], 'Łódź', LODZ );

	assert.deepStrictEqual( names( many ), [ 'Warsaw', 'Kraków' ] );
	assert.deepStrictEqual( names( one ), [ 'Warsaw' ], 'the second locator took the first one\'s count' );

	// And the restricted one is restricted by its own control rather than by
	// its config: widening it widens that locator and nothing else.
	choose( one, 'limit', 10 );

	await submit( harness, one, instances[ 1 ], 'Łódź', LODZ );

	assert.deepStrictEqual( names( one ), [ 'Warsaw', 'Kraków' ] );
	assert.deepStrictEqual( names( many ), [ 'Warsaw', 'Kraków' ] );
} );

/* -------------------------------------------------------------------------
 * No control at all: the starting value still applies
 * ---------------------------------------------------------------------- */

test( 'a locator rendered without the two selects searches on its starting values', async () => {
	// Shortcode::filters() always emits both today, but the markup a locator
	// runs on is whatever is on the page — a content filter, a page builder, or
	// a theme that rebuilt the filter bar. A missing control must not mean a
	// missing radius: that would turn a 150 km locator into one that lists
	// every location on the site.
	const harness = await locator( {
		payload: cities(),
		withRadius: false,
		withLimit: false,
	} );

	assert.strictEqual( harness.container.querySelector( '.slosm__radius' ), null );
	assert.strictEqual( harness.container.querySelector( '.slosm__limit' ), null );

	await search( harness, 'Łódź', LODZ );

	assert.deepStrictEqual(
		names( harness.container ),
		[ 'Warsaw' ],
		'the starting radius stopped applying when there was no control to read'
	);

	// The control for that absence, which is the half that makes it mean
	// something: the same locator WITH the selects answers the same way until
	// somebody uses one, and differently once they do.
	const withControls = await locator( { payload: cities() } );

	await search( withControls, 'Łódź', LODZ );

	assert.deepStrictEqual( names( withControls.container ), [ 'Warsaw' ] );

	choose( withControls.container, 'radius', 250 );

	await search( withControls, 'Łódź', LODZ );

	assert.deepStrictEqual( names( withControls.container ), [ 'Warsaw', 'Kraków' ] );
} );

test( 'a query-mode locator with no selects asks for its starting numbers', async () => {
	const harness = await locator( {
		config: defaultConfig( { mode: 'query', count: 900, radius: RADIUS_START, limit: 25 } ),
		withRadius: false,
		withLimit: false,
	} );

	await search( harness, 'Łódź', LODZ, [] );

	assert.strictEqual( lastQuery( harness ).get( 'radius' ), '150' );
	assert.strictEqual( lastQuery( harness ).get( 'limit' ), '25' );

	// The control. With the selects there and untouched the request is the
	// same, and one choice later it is not.
	const withControls = await locator( {
		config: defaultConfig( { mode: 'query', count: 900, radius: RADIUS_START, limit: 25 } ),
	} );

	await search( withControls, 'Łódź', LODZ, [] );

	assert.strictEqual( lastQuery( withControls ).get( 'radius' ), '150' );

	choose( withControls.container, 'radius', 250 );
	choose( withControls.container, 'limit', 50 );

	await search( withControls, 'Łódź', LODZ, [] );

	assert.strictEqual( lastQuery( withControls ).get( 'radius' ), '250' );
	assert.strictEqual( lastQuery( withControls ).get( 'limit' ), '50' );
} );

/* -------------------------------------------------------------------------
 * A value that is not a usable number
 * ---------------------------------------------------------------------- */

test( 'a select showing something that is not a usable radius falls back rather than filtering by it', async () => {
	// Nothing this plugin emits can produce any of these. Settings::radius_list()
	// drops every non-positive and non-numeric choice before it can be rendered
	// and refuses to hand back an empty list at all, and Shortcode::radius()
	// replaces a non-positive radius with the site default. What can produce
	// them is markup somebody else rewrote, which is the same class of input
	// wireCategory() already settles the category select against.
	//
	// Each of the four has a different way of going wrong if it is not caught:
	// '' and 'banana' become NaN or 0 and would turn the filter off, '0' would
	// filter every location away, and 'Infinity' is greater than zero and would
	// quietly reach the whole world.
	const junk = [ [], [ '0' ], [ 'banana' ], [ 'Infinity' ] ];

	for ( const options of junk ) {
		const harness = await locator( { payload: cities(), withRadiusOptions: options } );

		await search( harness, 'Łódź', LODZ );

		assert.deepStrictEqual(
			names( harness.container ),
			[ 'Warsaw' ],
			'a radius select offering [' + options.join( ', ' ) + '] did not fall back to the starting 150'
		);
	}

	// The control, and it is not optional: "[ 'Warsaw' ]" is also what a
	// locator that ignored the select entirely would answer, which is the
	// defect. A well-formed select at a value the starting radius does not
	// reach has to answer differently.
	const sound = await locator( { payload: cities(), withRadiusOptions: [ '250' ] } );

	await search( sound, 'Łódź', LODZ );

	assert.deepStrictEqual( names( sound.container ), [ 'Warsaw', 'Kraków' ] );
} );

test( 'a radius that is not a usable number never reaches the query string', async () => {
	// The other half of the same guard, on the other path. `radius=banana` is
	// a parameter Rest_Controller would sanitise on the way in, so this is not
	// a security claim — it is that a locator whose markup was mangled searches
	// at the radius it was configured with rather than at the route's default.
	const harness = await locator( {
		config: defaultConfig( { mode: 'query', count: 900, radius: RADIUS_START, limit: 25 } ),
		withRadiusOptions: [ 'banana' ],
		withLimitOptions: [ '0' ],
	} );

	await search( harness, 'Łódź', LODZ, [] );

	assert.strictEqual( lastQuery( harness ).get( 'radius' ), '150' );
	assert.strictEqual( lastQuery( harness ).get( 'limit' ), '25' );

	// The control, and without it this case is one a locator that never read a
	// select at all would pass: a sound select at a different value has to
	// change the request.
	const sound = await locator( {
		config: defaultConfig( { mode: 'query', count: 900, radius: RADIUS_START, limit: 25 } ),
	} );

	choose( sound.container, 'radius', 250 );
	choose( sound.container, 'limit', 50 );

	await search( sound, 'Łódź', LODZ, [] );

	assert.strictEqual( lastQuery( sound ).get( 'radius' ), '250' );
	assert.strictEqual( lastQuery( sound ).get( 'limit' ), '50' );
} );

test( 'a config with no usable number in it leaves the parameter off rather than sending nonsense', async () => {
	// A config carrying `radius: "150"` as a string, or nothing at all, is a
	// config that did not come from this plugin. There is no number to fall
	// back to, so the request carries no radius and the route applies its own
	// default — which is the behaviour finiteNumber() has always given the
	// local filter, now given to the query as well.
	const harness = await locator( {
		config: defaultConfig( { mode: 'query', count: 900, radius: '150', limit: null } ),
		withRadiusOptions: [],
		withLimitOptions: [],
	} );

	await search( harness, 'Łódź', LODZ, [] );

	assert.strictEqual( lastQuery( harness ).has( 'radius' ), false );
	assert.strictEqual( lastQuery( harness ).has( 'limit' ), false );

	// The control: the same config with usable selects sends what they say, so
	// this is a case about the fallback and not about a request that never
	// carries either.
	const sound = await locator( {
		config: defaultConfig( { mode: 'query', count: 900, radius: '150', limit: null } ),
	} );

	choose( sound.container, 'radius', 250 );
	choose( sound.container, 'limit', 50 );

	await search( sound, 'Łódź', LODZ, [] );

	assert.strictEqual( lastQuery( sound ).get( 'radius' ), '250' );
	assert.strictEqual( lastQuery( sound ).get( 'limit' ), '50' );
} );

/* -------------------------------------------------------------------------
 * The fixture itself, because a case is only as honest as its markup
 * ---------------------------------------------------------------------- */

test( 'the two selects offer what Shortcode renders, and start on the configured value', async () => {
	// Rule 4 of this project, learned the hard way: a stub that agrees with the
	// code under test proves nothing. Everything above depends on the fixture
	// offering a real option list and reporting a `value` a browser would
	// report, so that is asserted here rather than assumed by nine cases.
	const harness = await locator( { config: defaultConfig( { radius: RADIUS_START, limit: 25 } ), payload: [] } );

	// Settings::defaults()' radius_choices, with the locator's own 150 merged
	// in by Shortcode::with_current() — between 100 and 250, in order.
	assert.deepStrictEqual( offered( harness.container, 'radius' ), [ '5', '10', '25', '50', '100', '150', '250', '500' ] );
	assert.deepStrictEqual( offered( harness.container, 'limit' ), [ '10', '25', '50', '100', '250', '500' ] );

	// And the value is the option carrying `selected`, which is what a browser
	// reports and what the locator therefore reads on its first search.
	assert.strictEqual( harness.container.querySelector( '.slosm__radius' ).value, '150' );
	assert.strictEqual( harness.container.querySelector( '.slosm__limit' ).value, '25' );

	// A limit that is one of the steps is not merged in twice.
	const onStep = await locator( { config: defaultConfig( { radius: 50, limit: 50 } ), payload: [] } );

	assert.deepStrictEqual( offered( onStep.container, 'radius' ), [ '5', '10', '25', '50', '100', '250', '500' ] );
	assert.strictEqual( onStep.container.querySelector( '.slosm__radius' ).value, '50' );

	// With no options at all a browser reports '', not the last thing anybody
	// assigned. That is the input the empty-list fallback above rests on.
	const bare = await locator( { payload: [], withRadiusOptions: [] } );

	assert.strictEqual( bare.container.querySelector( '.slosm__radius' ).value, '' );

	// And with options but none marked — which is the shape the junk-markup
	// fixtures above are built in — a browser reports the FIRST option, not the
	// last one and not the largest. Every one of those fixtures offers a single
	// option, so without this assertion nothing here separates "the first" from
	// "the last" and the fixture could quietly report either.
	const unmarked = await locator( { payload: [], withRadiusOptions: [ '250', '500' ] } );

	assert.strictEqual( unmarked.container.querySelector( '.slosm__radius' ).value, '250' );
} );
