/**
 * The front end: config, url building, the map, the markers, the framing.
 *
 * Task 13's milestone and nothing past it. There is no search here, no popup,
 * no cluster, no geolocation and no category filter; Tasks 14 to 16 own those,
 * and a case for one of them would be a case for code nobody has written.
 *
 * What these cases cannot reach is stated in tests/js/harness.js and is worth
 * repeating in one line: Leaflet is a recorder, so "the map renders" and "the
 * markers are where they should be on screen" are manual checks. What is
 * covered is every decision made before Leaflet is called and every decision
 * made about what to do with what came back.
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
	fire,
	jsonResponse,
	loadLocator,
	pluginSource,
	plain,
} = require( './harness.js' );

/**
 * One store, lean-shaped, as GET /stores returns it.
 *
 * The seven fields of Store::to_lean_array() plus the distance the REST
 * controller adds. No more: a fixture richer than the payload would let a case
 * read a field the browser will never be sent.
 *
 * @param {object} overrides Keys to change.
 * @returns {object} One item.
 */
function store( overrides ) {
	return Object.assign(
		{
			id: 1,
			name: 'Warsaw',
			lat: 52.2297,
			lng: 21.0122,
			address: 'Nowy Świat 1',
			city: 'Warszawa',
			categories: [ 'Shops' ],
			distance: null,
		},
		overrides || {}
	);
}

/**
 * Loads the file, renders one locator, initialises it, waits for its fetch.
 *
 * @param {object} options `config`, `payload` (an array, a Response or an
 *                         Error), plus anything loadLocator() takes.
 * @returns {Promise<object>} The harness, plus `container` and `instance`.
 */
async function oneLocator( options ) {
	const settings = options || {};
	const harness = loadLocator( settings );

	if ( 'payload' in settings ) {
		harness.fetchQueue.push(
			Array.isArray( settings.payload ) ? jsonResponse( settings.payload ) : settings.payload
		);
	}

	// Every markup option forwarded, not just config. Forwarding one was the
	// same shape as the Object.assign bug this harness already fixed once:
	// oneLocator( { withMap: false } ) silently built a complete locator, so a
	// case could name a markup variation and test the default. No case did it
	// yet; Tasks 14 to 16 will.
	const container = harness.locatorMarkup( {
		config: settings.config,
		withMap: settings.withMap,
		withStatus: settings.withStatus,
		withResults: settings.withResults,
	} );
	const instances = harness.SLOSM.initAll();

	if ( instances[ 0 ] && instances[ 0 ].ready ) {
		await instances[ 0 ].ready;
	}

	return Object.assign( harness, { container, instance: instances[ 0 ] || null } );
}

/**
 * The text of the message the locator put in front of the visitor, or null.
 *
 * @param {object} container The `.slosm` element.
 * @returns {string|null} The message.
 */
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

/* -------------------------------------------------------------------------
 * The global
 * ---------------------------------------------------------------------- */

test( 'defines exactly one global, and it is namespaced', () => {
	const harness = loadLocator();

	assert.deepStrictEqual( harness.newGlobals, [ 'SLOSM' ] );
} );

test( 'the namespace is frozen, so a plugin cannot quietly replace a piece of it', () => {
	const { SLOSM } = loadLocator();

	// The existence check is not padding. Object.isFrozen( undefined ) is
	// true — ES2015 made the Object statics coerce rather than throw — so
	// without this line an empty locator.js passes this case.
	assert.ok( SLOSM, 'assets/js/locator.js defined no window.SLOSM' );
	assert.ok( Object.isFrozen( SLOSM ) );

	assert.throws( () => {
		SLOSM.initAll = () => [];
	}, TypeError );
} );

test( 'the namespace is exactly this and nothing else', () => {
	const { SLOSM } = loadLocator();

	// The whole key list, because "one global" and "frozen" are both true of
	// a namespace that has quietly grown a dozen internals. Task 23's builder
	// element is the only caller outside this file, and what it may reach for
	// is this list — so a key added here is a decision, and a key removed is
	// a break in something that is not in this repository.
	assert.deepStrictEqual( Object.keys( SLOSM ), [
		'Geo',
		'tileFor',
		'ZOOM',
		'STRINGS',
		'text',
		'url',
		'init',
		'initAll',
		'sweep',
	] );
} );

/**
 * The shipped file, having first proved there is a shipped file.
 *
 * Every assertion below this line is about something the source does NOT
 * contain, and an empty file contains nothing. So the source is first checked
 * to carry the surface the rest of this file exercises; without that, the two
 * cases that follow are green against a one-line comment.
 *
 * @returns {string} assets/js/locator.js.
 */
function locatorSource() {
	const source = pluginSource( 'assets', 'js', 'locator.js' );

	// Every token here must be in CODE, not prose. 'URLSearchParams' was in
	// this list and appears in locator.js only inside a comment — the code
	// uses `URL#searchParams`, which is a URLSearchParams, so the requirement
	// was met and the guard was not: a file with nothing but that comment
	// would have satisfied the entry. 'searchParams.set' is the call itself.
	[ 'window.SLOSM', 'initAll(', 'textContent =', 'searchParams.set', 'tileLayer(' ].forEach( ( expected ) => {
		assert.ok(
			source.includes( expected ),
			'assets/js/locator.js does not contain ' + expected + ', so a scan of it for what is absent proves nothing'
		);
	} );

	return source;
}

/*
 * codeOnly() used to live here and now lives in harness.js, because
 * search.test.js needs it too: its discipline scan reads this same file for
 * the names of things Task 14 must not have built, and locator.js cites two of
 * them — Leaflet's own bindPopup and divIcon — in a warning written to keep a
 * later task from using them. A scan that cannot tell a warning from a call
 * teaches people to delete the warning.
 */

/**
 * The patterns that mean "this code reached for an HTML-writing API".
 *
 * Property-access position — `node.innerHTML`, `node['innerHTML']`,
 * `document . write` — rather than bare words.
 *
 * @param {string} name Property name.
 * @returns {RegExp} The pattern.
 */
function reachesFor( name ) {
	return new RegExp( '[.\\[]\\s*[\'"]?' + name + '\\b' );
}

const FORBIDDEN = [ 'innerHTML', 'outerHTML', 'insertAdjacentHTML', 'write', 'createContextualFragment' ];

test( 'the file writes no markup anywhere: no innerHTML, no HTML parsing at all', () => {
	// A source scan, not a behaviour check, and deliberately so. The harness
	// throws on innerHTML, which proves no *executed* path reached it; this
	// proves no path exists to reach, including the ones no case here takes.
	const code = codeOnly( locatorSource() );

	FORBIDDEN.forEach( ( forbidden ) => {
		assert.ok(
			! reachesFor( forbidden ).test( code ),
			'assets/js/locator.js reaches for ' + forbidden + ': server data must be written with textContent'
		);
	} );
} );

test( 'the markup scan would catch a violation, and only a real one', () => {
	// The control for the case above, which is an assertion about absence and
	// would therefore be satisfied by an empty file. Two halves: the patterns
	// catch the forbidden thing, and the comment stripper does not hand them a
	// comment to catch it in.
	const guilty = 'node.innerHTML = item.name; other[ "outerHTML" ] = x; document . write( y );';

	[ 'innerHTML', 'outerHTML', 'write' ].forEach( ( forbidden ) => {
		assert.ok( reachesFor( forbidden ).test( codeOnly( guilty ) ) );
	} );

	assert.ok( ! reachesFor( 'innerHTML' ).test( codeOnly( '/* Leaflet does x.innerHTML = y */' ) ) );
	assert.ok( ! reachesFor( 'innerHTML' ).test( codeOnly( '// Leaflet does x.innerHTML = y' ) ) );

	// And the stripper does not eat a url, which is the whole reason it is not
	// a regex: locator.js's tile url has a '//' in it, and the forbidden call
	// below sits on the line after.
	const withUrl = "var u = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';\nnode.innerHTML = x;";

	assert.ok( codeOnly( withUrl ).includes( 'openstreetmap.org' ), 'the stripper ate a url' );
	assert.ok( reachesFor( 'innerHTML' ).test( codeOnly( withUrl ) ) );
} );

test( 'the file contains no regex literal, which is what the comment stripper assumes', () => {
	// codeOnly() cannot tell `/foo/` from division. That shortcut is safe only
	// for as long as this holds, so it is asserted rather than hoped.
	const code = codeOnly( locatorSource() ).replace( /\*\*/g, '' );

	assert.ok(
		! /[=(,:[]\s*\/[^/*\s]/.test( code ),
		'assets/js/locator.js now contains a regex literal; codeOnly() has to learn about them'
	);
} );

test( 'uses no jQuery', () => {
	assert.ok( ! /jQuery|\$\(/.test( locatorSource() ), 'assets/js/locator.js reaches for jQuery' );
} );

/* -------------------------------------------------------------------------
 * Config
 * ---------------------------------------------------------------------- */

test( 'reads the config off the element it was handed', async () => {
	const harness = await oneLocator( { payload: [] } );

	assert.strictEqual( harness.instance.config.mode, 'preload' );
	assert.strictEqual( harness.instance.config.zoom, 12 );
	assert.strictEqual( harness.instance.config.units, 'km' );
	assert.strictEqual( harness.instance.config.routes.stores, defaultConfig().routes.stores );
} );

test( 'a missing data-slosm leaves a readable message rather than throwing', () => {
	const harness = loadLocator();
	const container = harness.locatorMarkup( { config: null } );

	// No assert.doesNotThrow wrapper: initAll() is called for real, so a throw
	// fails the case with the throw itself in the output.
	const instances = harness.SLOSM.initAll();

	assert.strictEqual( instances.length, 0 );
	assert.strictEqual( message( container ), defaultStrings().configError );
	assert.ok( container.classList.contains( 'slosm--error' ) );
	assert.strictEqual( harness.leafletCalls.map.length, 0, 'a locator with no config must not build a map' );
} );

test( 'a malformed data-slosm leaves a readable message rather than throwing', () => {
	const harness = loadLocator();
	const container = harness.locatorMarkup( { config: '{"routes":' } );

	const instances = harness.SLOSM.initAll();

	assert.strictEqual( instances.length, 0 );
	assert.strictEqual( message( container ), defaultStrings().configError );
} );

test( 'an empty config object is a failure, not an empty map', () => {
	// Shortcode::encoded_config() emits '{}' when wp_json_encode() returns
	// false — invalid utf-8 in a term name is the realistic way. It is the
	// shortcode saying "I could not tell you anything", so it has to read as
	// a failure here rather than as a locator with default settings.
	const harness = loadLocator();
	const container = harness.locatorMarkup( { config: '{}' } );

	harness.SLOSM.initAll();

	assert.strictEqual( message( container ), defaultStrings().configError );
} );

test( 'a config that is not an object is a failure', () => {
	[ '[]', '"preload"', '4', 'null', 'true' ].forEach( ( raw ) => {
		const harness = loadLocator();
		const container = harness.locatorMarkup( { config: raw } );

		harness.SLOSM.initAll();

		assert.strictEqual( message( container ), defaultStrings().configError, 'accepted ' + raw );
	} );
} );

test( 'a stores route that is missing, empty or unparseable is a failure', () => {
	// 'http://' and 'https://[' are the two shapes the url parser genuinely
	// refuses. A route that merely looks odd — 'not a url' — is a *relative*
	// url and resolves against the page, which is the next case down and is
	// deliberate rather than an oversight.
	[ {}, { stores: '' }, { stores: 42 }, { stores: null }, { stores: 'http://' }, { stores: 'https://[' } ].forEach( ( routes ) => {
		const harness = loadLocator();
		const container = harness.locatorMarkup( { config: defaultConfig( { routes } ) } );

		harness.SLOSM.initAll();

		assert.strictEqual(
			message( container ),
			defaultStrings().configError,
			'accepted routes: ' + JSON.stringify( routes )
		);
		assert.strictEqual( harness.fetchCalls.length, 0 );
	} );
} );

test( 'a root-relative route is resolved against the page, not refused', async () => {
	// rest_url() returns an absolute url, but a site can filter it — a
	// multisite mapping plugin, a reverse proxy, a staging domain — and the
	// root-relative form is a legitimate answer. Resolving it against the
	// current page is what a browser would do with it in an href.
	const harness = await oneLocator( {
		payload: [],
		config: defaultConfig( {
			routes: Object.assign( defaultConfig().routes, { stores: '/wp-json/slosm/v1/stores' } ),
		} ),
	} );

	assert.strictEqual( message( harness.container ), defaultStrings().noResults );
	assert.strictEqual(
		new URL( harness.fetchCalls[ 0 ].url ).origin + new URL( harness.fetchCalls[ 0 ].url ).pathname,
		'https://example.test/wp-json/slosm/v1/stores'
	);
} );

test( 'a locator whose map div is missing says so rather than throwing', () => {
	const harness = loadLocator();
	const container = harness.locatorMarkup( { withMap: false } );

	harness.SLOSM.initAll();

	assert.strictEqual( message( container ), defaultStrings().configError );
	assert.strictEqual( harness.leafletCalls.map.length, 0 );
} );

test( 'a locator with neither a map div nor a results list still shows its message', () => {
	// The message has nowhere structured to go, and going nowhere is the one
	// outcome that must not happen: that is a blank rectangle with no
	// explanation, which is the failure mode this whole path exists to avoid.
	const harness = loadLocator();
	const container = harness.locatorMarkup( { withMap: false, withResults: false } );

	harness.SLOSM.initAll();

	assert.strictEqual( message( container ), defaultStrings().configError );
} );

test( 'Leaflet failing to load is a known failure, not a crash in the log', () => {
	const harness = loadLocator( { withLeaflet: false } );
	const container = harness.locatorMarkup();

	harness.SLOSM.initAll();

	assert.strictEqual( message( container ), defaultStrings().loadFailed );
	assert.strictEqual( harness.fetchCalls.length, 0 );

	// The console assertion is what gives the explicit check in init() its
	// reason to exist. initAll()'s try/catch would produce the same message on
	// its own — a missing L is a TypeError like any other — so without this
	// the check is an equivalent mutation. The difference it buys is in the
	// log: a dequeued library is a thing a site owner did, reported as a
	// sentence, not as "Cannot read properties of undefined" under a stack
	// trace that sends whoever reads it hunting for a bug in this plugin.
	assert.deepStrictEqual(
		harness.consoleCalls.error,
		[],
		'a missing Leaflet was logged as an unexpected crash rather than handled'
	);
} );

/* -------------------------------------------------------------------------
 * The url
 * ---------------------------------------------------------------------- */

test( 'builds the stores url with URLSearchParams on a pretty permalink', async () => {
	// A category rather than a limit, since the preload fetch stopped sending
	// one: the point of the case is that a parameter is appended by the url
	// parser rather than by string concatenation, and any parameter shows it.
	const harness = await oneLocator( { payload: [], config: defaultConfig( { category: 'Bakeries' } ) } );
	const url = new URL( harness.fetchCalls[ 0 ].url );

	assert.strictEqual( url.origin + url.pathname, 'https://example.test/wp-json/slosm/v1/stores' );
	assert.strictEqual( url.searchParams.get( 'category' ), 'Bakeries' );
} );

test( 'builds it on plain permalinks without producing a second question mark', async () => {
	// rest_url() has two shapes. With plain permalinks get_rest_url() returns
	// https://site/index.php?rest_route=/slosm/v1/stores, and a front end that
	// appended '?limit=500' to that would produce a url with two '?' in it on
	// every site that has never visited Settings > Permalinks.
	const harness = await oneLocator( {
		payload: [],
		config: defaultConfig( {
			category: 'Bakeries',
			routes: Object.assign( defaultConfig().routes, {
				stores: 'https://example.test/index.php?rest_route=/slosm/v1/stores',
			} ),
		} ),
	} );

	const requested = harness.fetchCalls[ 0 ].url;

	assert.strictEqual( requested.split( '?' ).length - 1, 1, 'two question marks: ' + requested );

	const url = new URL( requested );

	assert.strictEqual( url.searchParams.get( 'rest_route' ), '/slosm/v1/stores' );
	assert.strictEqual( url.searchParams.get( 'category' ), 'Bakeries' );
} );

test( 'sends the category when one is pinned and omits it when none is', async () => {
	const withCategory = await oneLocator( {
		payload: [],
		config: defaultConfig( { category: 'Bakeries & Cafés' } ),
	} );

	assert.strictEqual(
		new URL( withCategory.fetchCalls[ 0 ].url ).searchParams.get( 'category' ),
		'Bakeries & Cafés'
	);

	const without = await oneLocator( { payload: [] } );

	assert.strictEqual( new URL( without.fetchCalls[ 0 ].url ).searchParams.has( 'category' ), false );
} );

test( 'preloads the whole list rather than the configured limit of it', async () => {
	// The limit is a *display* limit — its control says "Show at most" — and
	// sending it here made it a fetch limit instead. find_all() orders by
	// title ASC, so a limit of 25 preloaded the 25 alphabetically first
	// locations and every in-memory search then ranked only those: the nearest
	// branch was missing because its name began with M. The route's own
	// default is MAX_LIMIT, and Shortcode::PRELOAD_THRESHOLD is MAX_LIMIT, so
	// a preload-mode locator is by construction one whose whole list fits in
	// this one request.
	const harness = await oneLocator( { payload: [], config: defaultConfig( { limit: 25 } ) } );
	const params = new URL( harness.fetchCalls[ 0 ].url ).searchParams;

	assert.strictEqual( params.has( 'limit' ), false, 'the preload fetch is capped at the display limit' );

	// The control: the request is still built and still carries what it should
	// — this is not a case that passes because nothing was fetched at all.
	assert.strictEqual( harness.fetchCalls.length, 1 );
	assert.ok( harness.fetchCalls[ 0 ].url.indexOf( '/stores' ) > -1 );
} );

test( 'shows no more rows and no more pins than the limit, whatever arrived', async () => {
	// The other half of the same decision. The limit still does its job; it
	// does it where a search can still see past it.
	const harness = await oneLocator( {
		config: defaultConfig( { limit: 2 } ),
		payload: [
			store( { id: 1, name: 'Aleph' } ),
			store( { id: 2, name: 'Beth', lat: 51.7592, lng: 19.456 } ),
			store( { id: 3, name: 'Gimel', lat: 50.0647, lng: 19.945 } ),
		],
	} );

	assert.strictEqual( harness.container.querySelectorAll( '.slosm__result' ).length, 2 );
	assert.strictEqual( harness.leafletCalls.marker.length, 2, 'the map showed a pin the list did not' );
} );

/* -------------------------------------------------------------------------
 * The map
 * ---------------------------------------------------------------------- */

test( 'adds the OpenStreetMap tile layer with its attribution', async () => {
	const harness = await oneLocator( { payload: [ store() ] } );
	const tile = harness.leafletCalls.tileLayer[ 0 ];

	assert.ok( tile, 'no tile layer was added' );
	assert.ok( /openstreetmap\.org/.test( tile.url ), 'the tile url is not an OpenStreetMap one' );
	assert.ok( tile.url.startsWith( 'https://' ), 'tiles must be fetched over https' );

	// The licence requires it. A plugin that ships a map with the attribution
	// stripped puts its users in breach of the ODbL, which is a worse outcome
	// than a map that does not draw.
	assert.ok( /openstreetmap\.org\/copyright/.test( tile.options.attribution ) );
	assert.ok( /OpenStreetMap/.test( tile.options.attribution ) );

	assert.ok( harness.leafletCalls.addTo.some( ( call ) => 'tileLayer' === call.kind ) );
} );

test( 'a tile layer with no placeholders is refused, and the map is drawn without one', async () => {
	// The frozen TILE constant this used to assert about is gone: Task 21 made
	// the tile url a setting, so what there is to check is the function that
	// reads one. A url with no {z}/{x}/{y} in it fetches one image and draws it
	// as every tile at every zoom, which reads as a broken map rather than as a
	// setting typed wrong; Settings::sanitise() refuses one on the way in and
	// this refuses one that got past it.
	const { SLOSM } = loadLocator();

	assert.strictEqual( SLOSM.tileFor( { tile: { url: 'https://t.example/t.png' } } ), null );
	assert.strictEqual( SLOSM.tileFor( { tile: { url: 'https://t.example/{z}/{x}/y.png' } } ), null );
	assert.strictEqual( SLOSM.tileFor( { tile: null } ), null );
	assert.strictEqual( SLOSM.tileFor( {} ), null );

	// The control: a url with all three is taken, with its attribution and its
	// ceiling carried through.
	const taken = SLOSM.tileFor( {
		tile: { url: 'https://t.example/{z}/{x}/{y}.png', attribution: 'Tiles', maxZoom: 17 },
	} );

	assert.strictEqual( taken.url, 'https://t.example/{z}/{x}/{y}.png' );
	assert.strictEqual( taken.options.attribution, 'Tiles' );
	assert.strictEqual( taken.options.maxZoom, 17 );

	// And a locator whose config carries an unusable one still gets a map, its
	// pins and its list — everything but a basemap nobody may attribute.
	const harness = await oneLocator( {
		payload: [ store() ],
		config: defaultConfig( { tile: { url: 'https://t.example/t.png' } } ),
	} );

	assert.strictEqual( harness.leafletCalls.tileLayer.length, 0 );
	assert.strictEqual( harness.leafletCalls.map.length, 1 );
	assert.strictEqual( harness.container.querySelectorAll( '.slosm__result' ).length, 1 );
} );

test( 'every locator gets its own tile options object, not one shared between them', async () => {
	// Leaflet's own `setOptions` writes onto the object it is handed
	// (leaflet.js, the `c` helper: `for(i in e)t.options[i]=e[i]`), so a shared
	// object would be one map's options being written by another. tileFor()
	// builds a fresh one per call, which is what removed the copy this case
	// used to be about: there is no frozen constant left to copy from.
	const { SLOSM } = loadLocator();
	const first = SLOSM.tileFor( defaultConfig() );
	const second = SLOSM.tileFor( defaultConfig() );

	assert.notStrictEqual( first, second );
	assert.notStrictEqual( first.options, second.options );
	assert.strictEqual( first.options.attribution, second.options.attribution );

	// The one on a real map is one of these rather than something shared, which
	// is the assertion that would fail if init() ever went back to a constant.
	const harness = await oneLocator( { payload: [ store() ] } );

	assert.notStrictEqual( harness.leafletCalls.tileLayer[ 0 ].options, first.options );
	assert.strictEqual(
		harness.leafletCalls.tileLayer[ 0 ].options.attribution,
		defaultConfig().tile.attribution
	);
} );

test( 'caps the tile layer at the zoom the tile server actually renders', async () => {
	const harness = await oneLocator( { payload: [ store() ] } );

	// Shortcode::MAX_ZOOM is 19 and says why: Leaflet will accept 22 happily
	// and the tile server answers 404 for every tile above 19, which looks
	// like a broken plugin rather than a zoom nobody renders.
	assert.strictEqual( harness.leafletCalls.tileLayer[ 0 ].options.maxZoom, 19 );
} );

test( 'the zoom range is the one Shortcode declares', () => {
	// The same drift the Geo cross-check exists for, one class over. A
	// JavaScript that clamped at 22 while the shortcode clamped at 19 would
	// render 404 tiles for a locator whose config was perfectly valid.
	const shortcode = pluginSource( 'includes', 'class-shortcode.php' );
	const { SLOSM } = loadLocator();

	[
		[ 'MIN_ZOOM', SLOSM.ZOOM.min ],
		[ 'MAX_ZOOM', SLOSM.ZOOM.max ],
		[ 'DEFAULT_ZOOM', SLOSM.ZOOM.fallback ],
	].forEach( ( [ name, value ] ) => {
		const match = shortcode.match( new RegExp( 'public const ' + name + ' = (\\d+);' ) );

		assert.ok( match, 'Shortcode no longer declares ' + name );
		assert.strictEqual( Number( match[ 1 ] ), value, name + ' and the JavaScript disagree' );
	} );
} );

test( 'a config with no usable zoom still puts the map somewhere', async () => {
	// Only a hand-written data-slosm gets here — Shortcode::bounded_int()
	// guarantees an integer in range — but Leaflet takes an undefined zoom
	// without complaint and computes NaN from it, and a map at NaN is the
	// blank rectangle this whole file is written against.
	const harness = await oneLocator( {
		payload: [],
		config: defaultConfig( { lat: 52.2297, lng: 21.0122, zoom: 'a lot' } ),
	} );

	assert.strictEqual( harness.leafletCalls.setView[ 0 ].zoom, 12 );

	const tooFar = await oneLocator( {
		payload: [],
		config: defaultConfig( { lat: 52.2297, lng: 21.0122, zoom: 30 } ),
	} );

	assert.strictEqual( tooFar.leafletCalls.setView[ 0 ].zoom, 19 );
} );

test( 'a centre outside the world is refused rather than clamped into it', async () => {
	// Clamping 200 to 90 would frame a map on the north pole and call it the
	// centre the shortcode asked for. Refusing it falls through to framing the
	// markers, which is at least a place somebody chose.
	const harness = await oneLocator( {
		payload: [ store() ],
		config: defaultConfig( { lat: 200, lng: 21.0122 } ),
	} );

	assert.strictEqual( harness.leafletCalls.setView.length, 0 );
	assert.strictEqual( harness.leafletCalls.fitBounds.length, 1 );
} );

// The case that used to sit here, 'sets the view from the config when the
// shortcode named a centre', asserted the opening view and then asserted that
// no fitBounds followed it. Task 32 reversed the second half, and the first
// half is asserted — with the framing that now follows it — by 'a named centre
// is where the map opens, not where it stays', at the end of this file. Two
// cases over one opening view would have been one case and an echo.

test( 'frames the markers when the shortcode named no centre', async () => {
	const harness = await oneLocator( {
		payload: [ store(), store( { id: 2, name: 'Krakow', lat: 50.0647, lng: 19.945 } ) ],
	} );

	assert.strictEqual( harness.leafletCalls.fitBounds.length, 1 );
	assert.deepStrictEqual( plain( harness.leafletCalls.fitBounds[ 0 ].bounds ), [
		[ 52.2297, 21.0122 ],
		[ 50.0647, 19.945 ],
	] );
} );

test( 'shows the world rather than calling fitBounds with nothing in it', async () => {
	// Leaflet's fitBounds throws Error( 'Bounds are not valid.' ) on empty
	// bounds — assets/leaflet/leaflet.js, the `fitBounds:` property of the Map
	// prototype. A locator with no centre and no locations must not take that
	// path, and the harness's stub throws exactly the same way so this case
	// would fail loudly if it did.
	const harness = await oneLocator( { payload: [] } );

	assert.strictEqual( harness.leafletCalls.fitBounds.length, 0 );
	assert.strictEqual( harness.leafletCalls.fitWorld.length, 1 );
} );

test( 'a marker per location, carrying the name as a title', async () => {
	const harness = await oneLocator( {
		payload: [ store(), store( { id: 2, name: 'Krakow', lat: 50.0647, lng: 19.945 } ) ],
	} );

	assert.strictEqual( harness.leafletCalls.marker.length, 2 );
	assert.deepStrictEqual( plain( harness.leafletCalls.marker[ 0 ].latlng ), [ 52.2297, 21.0122 ] );
	assert.strictEqual( harness.leafletCalls.marker[ 0 ].options.title, 'Warsaw' );
	assert.strictEqual( harness.leafletCalls.marker[ 1 ].options.title, 'Krakow' );

	assert.strictEqual( harness.instance.markers.length, 2 );
} );

test( 'a nameless location gets no title rather than an empty one', async () => {
	// Leaflet's Marker defaults are title:"" and alt:"Marker", and alt="" on
	// an <img> means "decorative, skip me" — so passing an empty string would
	// take a nameless pin out of the accessibility tree entirely.
	const harness = await oneLocator( { payload: [ store( { name: '' } ), store( { id: 2, name: 7 } ) ] } );

	assert.strictEqual( harness.leafletCalls.marker.length, 2 );
	assert.deepStrictEqual( plain( harness.leafletCalls.marker[ 0 ].options ), {} );
	assert.deepStrictEqual( plain( harness.leafletCalls.marker[ 1 ].options ), {} );

	const leaflet = pluginSource( 'assets', 'leaflet', 'leaflet.js' );

	assert.ok( leaflet.includes( 'alt:"Marker"' ), 'Leaflet no longer defaults a marker alt' );
} );

test( 'skips a location with no usable coordinates instead of inventing one', async () => {
	// Store::has_coordinates() refuses to treat 0,0 as a place, and the
	// repository drops unplaced locations before they reach the payload — but
	// a marker at null,null or at the Gulf of Guinea is a worse answer than no
	// marker, so the browser does not rely on that.
	const harness = await oneLocator( {
		payload: [
			store(),
			store( { id: 2, lat: null, lng: null } ),
			store( { id: 3, lat: 'x', lng: 'y' } ),
			store( { id: 4, lat: 91, lng: 0 } ),
			store( { id: 5, lat: 0, lng: 181 } ),
		],
	} );

	assert.strictEqual( harness.leafletCalls.marker.length, 1 );
	assert.deepStrictEqual( plain( harness.leafletCalls.marker[ 0 ].latlng ), [ 52.2297, 21.0122 ] );
} );

test( 'a coordinate that arrived as a string or a boolean is refused, not coerced', async () => {
	// The payload declares lat and lng as number-or-null, so a '52.2297' is a
	// bug on the server worth seeing rather than papering over — and a
	// boolean is the Shortcode::number() lesson one file over: Number( true )
	// is 1, and 1 is a latitude in the Gulf of Guinea.
	//
	// The existing 'x' case does not cover this: 'x' is refused by any
	// coercion at all, so it passes against a finiteNumber() that merely
	// called Number().
	const harness = await oneLocator( {
		payload: [
			store(),
			store( { id: 2, lat: '50.0647', lng: '19.945' } ),
			store( { id: 3, lat: true, lng: true } ),
			store( { id: 4, lat: [ 50.0647 ], lng: [ 19.945 ] } ),
		],
	} );

	assert.strictEqual( harness.leafletCalls.marker.length, 1 );
	assert.deepStrictEqual( plain( harness.leafletCalls.marker[ 0 ].latlng ), [ 52.2297, 21.0122 ] );
} );

test( 'a centre that arrived as a string is refused too', async () => {
	const harness = await oneLocator( {
		payload: [ store() ],
		config: defaultConfig( { lat: '52.2297', lng: '21.0122' } ),
	} );

	assert.strictEqual( harness.leafletCalls.setView.length, 0 );
	assert.strictEqual( harness.leafletCalls.fitBounds.length, 1 );
} );

test( 'a payload of locations that are all unplaced reads as empty, not as a map of one', async () => {
	const harness = await oneLocator( { payload: [ store( { lat: null, lng: null } ) ] } );

	assert.strictEqual( harness.leafletCalls.marker.length, 0 );
	assert.strictEqual( harness.leafletCalls.fitBounds.length, 0 );
	assert.strictEqual( message( harness.container ), defaultStrings().noResults );
} );

/* -------------------------------------------------------------------------
 * Two locators on one page
 * ---------------------------------------------------------------------- */

test( 'initialises every locator on the page independently', async () => {
	const harness = loadLocator();

	harness.fetchQueue.push( jsonResponse( [ store() ] ) );
	harness.fetchQueue.push( jsonResponse( [] ) );

	const first = harness.locatorMarkup( { config: defaultConfig( { lat: 52.2297, lng: 21.0122, zoom: 14 } ) } );
	const second = harness.locatorMarkup( {
		config: defaultConfig( { lat: 50.0647, lng: 19.945, zoom: 6, category: 'Bakeries' } ),
	} );

	const instances = harness.SLOSM.initAll();

	await Promise.all( instances.map( ( instance ) => instance.ready ) );

	assert.strictEqual( instances.length, 2 );
	assert.strictEqual( harness.leafletCalls.map.length, 2 );

	// Each map was built into its own container's map div, and each took its
	// own view. Nothing about the first is visible in the second.
	assert.strictEqual( harness.leafletCalls.map[ 0 ].container, first.querySelector( '.slosm__map' ) );
	assert.strictEqual( harness.leafletCalls.map[ 1 ].container, second.querySelector( '.slosm__map' ) );
	assert.strictEqual( harness.leafletCalls.setView[ 0 ].zoom, 14 );
	assert.strictEqual( harness.leafletCalls.setView[ 1 ].zoom, 6 );

	// Neither preload fetch carries a limit — it is a display limit now — so
	// what tells the two requests apart is the category each was configured
	// with.
	assert.strictEqual( new URL( harness.fetchCalls[ 0 ].url ).searchParams.has( 'limit' ), false );
	assert.strictEqual( new URL( harness.fetchCalls[ 1 ].url ).searchParams.get( 'category' ), 'Bakeries' );

	// And the one that found nothing said so without touching the one that
	// found something.
	assert.strictEqual( message( first ), null );
	assert.strictEqual( message( second ), defaultStrings().noResults );
} );

test( 'a locator whose map Leaflet refuses to build does not take the page with it', async () => {
	// init() converts its own known failures into a message, but it cannot
	// know every way Leaflet can refuse. Without a guard in initAll() the
	// throw escapes the loop and every locator after it is never touched: no
	// map, no message, nothing in the markup to say why — the exact failure
	// the message path exists to prevent, arriving through the loop rather
	// than through a config.
	//
	// 'Map container is already initialized.' is Map._initContainer's own
	// wording and is reachable in production: the WeakSet in locator.js lives
	// for one evaluation, so a caching or concatenating plugin that runs the
	// file twice calls L.map() on a container that already has a _leaflet_id.
	const harness = loadLocator();

	harness.leafletMapErrors.push( new Error( 'Map container is already initialized.' ) );
	harness.fetchQueue.push( jsonResponse( [ store() ] ) );

	const refused = harness.locatorMarkup();
	const working = harness.locatorMarkup();

	const instances = harness.SLOSM.initAll();

	await Promise.all( instances.map( ( instance ) => instance.ready ) );

	assert.strictEqual( instances.length, 1, 'the second locator never came up' );
	assert.strictEqual( message( refused ), defaultStrings().loadFailed );
	assert.ok( refused.classList.contains( 'slosm--error' ) );
	assert.strictEqual( message( working ), null );
	assert.strictEqual( harness.leafletCalls.marker.length, 1 );

	// And the real reason went where a developer will find it.
	assert.ok(
		harness.consoleCalls.error.some( ( args ) => /already initialized/.test( String( args[ 0 ] ) ) ),
		'the underlying Leaflet error was swallowed entirely'
	);
} );

test( "the two throws Leaflet's own container setup can produce are still in the vendored source", () => {
	const leaflet = pluginSource( 'assets', 'leaflet', 'leaflet.js' );

	assert.ok( leaflet.includes( 'throw new Error("Map container not found.")' ) );
	assert.ok( leaflet.includes( 'throw new Error("Map container is already initialized.")' ) );
} );

test( 'a broken locator does not stop the working one beside it', async () => {
	const harness = loadLocator();

	harness.fetchQueue.push( jsonResponse( [ store() ] ) );

	const broken = harness.locatorMarkup( { config: 'not json' } );
	const working = harness.locatorMarkup();

	const instances = harness.SLOSM.initAll();

	await Promise.all( instances.map( ( instance ) => instance.ready ) );

	assert.strictEqual( instances.length, 1, 'the broken locator should not produce an instance' );
	assert.strictEqual( message( broken ), defaultStrings().configError );
	assert.strictEqual( message( working ), null );
	assert.strictEqual( harness.leafletCalls.marker.length, 1 );
} );

test( 'initialising twice does not build a second map on the same container', async () => {
	const harness = loadLocator();

	harness.fetchQueue.push( jsonResponse( [ store() ] ) );

	harness.locatorMarkup();

	const first = harness.SLOSM.initAll();

	await first[ 0 ].ready;

	const second = harness.SLOSM.initAll();

	assert.strictEqual( second.length, 0 );
	assert.strictEqual( harness.leafletCalls.map.length, 1 );
	assert.strictEqual( harness.fetchCalls.length, 1 );
} );

test( 'a locator that came up is marked ready, and one that did not is not', async () => {
	const harness = loadLocator();

	harness.fetchQueue.push( jsonResponse( [ store() ] ) );

	const broken = harness.locatorMarkup( { config: 'not json' } );
	const working = harness.locatorMarkup();

	const instances = harness.SLOSM.initAll();

	await Promise.all( instances.map( ( instance ) => instance.ready ) );

	// `.slosm--ready` is a hook for a site and nothing in this plugin styles
	// it — tests/test-stylesheet.php records that as a decision and
	// assets/css/locator.css argues it. That decision is exactly what makes
	// this case worth having: with no rule and no assertion anywhere, the one
	// line in init() that sets the class could be deleted and every suite
	// would stay green, which is a hook that is not there.
	assert.ok( working.classList.contains( 'slosm--ready' ) );

	// The control, and the half that makes the line above mean something: an
	// unreadable config returns through fail() near the top of init(), long
	// before the class, so a locator that never came up is not "ready". A
	// class added unconditionally at the first line would pass without it.
	assert.ok( ! broken.classList.contains( 'slosm--ready' ) );
	assert.ok( broken.classList.contains( 'slosm--error' ) );
} );

/* -------------------------------------------------------------------------
 * Nothing found, and nothing arrived
 * ---------------------------------------------------------------------- */

test( 'an empty list says so, in the live region, and leaves the map alone', async () => {
	const harness = await oneLocator( { payload: [] } );

	assert.strictEqual( message( harness.container ), defaultStrings().noResults );
	assert.ok( harness.container.classList.contains( 'slosm--empty' ) );

	// The sentence is in the status line Shortcode::render() emits, which is
	// the locator's one live region since Task 24c. Where exactly, and what
	// the list no longer carries, is the case above; this one is about the
	// map, which an empty answer does not touch.
	assert.strictEqual( harness.container.querySelector( '.slosm__message' ).textContent, defaultStrings().noResults );
	assert.ok( harness.container.querySelector( '.slosm__map' ) );
} );

/* -------------------------------------------------------------------------
 * The status line: one live region, and the list is not it
 * ---------------------------------------------------------------------- */

test( 'a sentence goes to the status line and not into the results list', async () => {
	const harness = await oneLocator( { payload: [] } );
	const status = harness.container.querySelector( '.slosm__message' );
	const list = harness.container.querySelector( '.slosm__results' );

	assert.strictEqual( status.getAttribute( 'role' ), 'status' );
	assert.strictEqual( status.getAttribute( 'aria-live' ), 'polite' );
	assert.strictEqual( status.textContent, defaultStrings().noResults );

	// The half that is the point of the task: the list is not a live region
	// any more, so a draw is not read out row by row, and the sentence is not
	// inside the element that gets emptied on every draw.
	assert.strictEqual( list.getAttribute( 'aria-live' ), null );
	assert.strictEqual( list.querySelector( '.slosm__message' ), null );
} );

test( 'a locator whose status line was dropped still says what happened', async () => {
	// Markup a page builder can produce. "Nowhere" is the one place a sentence
	// must not go: a blank rectangle with no explanation is the failure this
	// whole path exists to prevent, and it is why say() still has a fallback
	// now that the element is normally the server's.
	const harness = await oneLocator( { payload: [], withStatus: false } );

	assert.strictEqual( message( harness.container ), defaultStrings().noResults );
} );

test( 'a page that has only just drawn itself announces nothing', async () => {
	// The other half of Task 24d, and the half a count is easy to get wrong.
	// A live region that speaks while a page is still being read talks over
	// whatever somebody was reading, and nothing has happened on load that
	// they do not already know about: the locator drew itself, which is what
	// locators do. The count is for a draw somebody asked for.
	const harness = await oneLocator( { payload: [ store(), store( { id: 2, name: 'Second' } ) ] } );

	assert.strictEqual( harness.container.querySelectorAll( '.slosm__result' ).length, 2 );
	assert.strictEqual( harness.container.querySelector( '.slosm__message' ).textContent, '' );
} );

/* -------------------------------------------------------------------------
 * "No results" is a claim, and a locator with no data yet has no right to it
 * ---------------------------------------------------------------------- */

test( 'a locator whose list has not arrived says nothing about having none', async () => {
	// Found on a live site rather than by reading: GET /stores there takes
	// about 1.4 seconds, and anybody who touches a control inside that window
	// was told "No results" — which is not true, the list simply had not
	// arrived. In preload mode every draw comes from one payload held on the
	// instance, so before it lands there is nothing to draw from and every
	// filter draws nothing.
	const harness = loadLocator( {} );
	const slow = deferredResponse();

	harness.fetchQueue.push( slow );

	const container = harness.locatorMarkup( {
		config: defaultConfig( { mode: 'preload', count: 3, radius: 10, limit: 5 } ),
	} );
	const instance = harness.SLOSM.initAll()[ 0 ];

	// A visitor widening the radius while the page is still loading.
	const radius = container.querySelector( '.slosm__radius' );

	radius.value = '500';
	fire( radius, 'change' );

	assert.strictEqual( message( container ), null, 'a locator with no data yet claimed it found nothing' );

	// And the hook says nothing either, for the same reason: a site that
	// styles `.slosm--empty` would otherwise put its own "nothing here"
	// message on a locator that is still loading.
	assert.ok( ! container.classList.contains( 'slosm--empty' ), 'the empty hook went on before there was anything to be empty of' );

	slow.resolve( jsonResponse( [ store( { id: 1, name: 'Warsaw shop', lat: 52.2297, lng: 21.0122 } ) ] ) );

	await instance.ready;

	// And the moment it lands, the locator draws — with the radius the
	// visitor had already chosen.
	assert.deepStrictEqual(
		container.querySelectorAll( '.slosm__result-name' ).map( ( node ) => node.textContent ),
		[ 'Warsaw shop' ]
	);
} );

test( 'a locator whose list really is empty still says so', async () => {
	// The other side, and the reason the guard is about *having data* rather
	// than about being empty: a site with no published locations has a true
	// "No results" to tell, and gating too much would leave it silent with a
	// blank list.
	const harness = await oneLocator( { payload: [] } );

	assert.strictEqual( message( harness.container ), defaultStrings().noResults );
	assert.ok( harness.container.classList.contains( 'slosm--empty' ) );
} );

test( 'a query-mode search that finds nothing says so, payload or no payload', async () => {
	// Query mode never holds a payload, so the guard must not reach it: there
	// the empty answer *is* the answer, and it has already been fetched.
	const harness = loadLocator( {} );

	const container = harness.locatorMarkup( {
		config: defaultConfig( { mode: 'query', count: 900, radius: 500, limit: 25 } ),
	} );
	const instance = harness.SLOSM.initAll()[ 0 ];

	await instance.ready;

	harness.fetchQueue.push( jsonResponse( { lat: 51.7592, lng: 19.456, label: 'Łódź' } ) );
	harness.fetchQueue.push( jsonResponse( [] ) );

	const field = container.querySelector( '.slosm__search' );

	field.value = 'Łódź';
	fire( field, 'keydown', { key: 'Enter' } );

	await instance.pendingSearch;

	assert.strictEqual( message( container ), defaultStrings().noResults );
	assert.ok( container.classList.contains( 'slosm--empty' ) );
} );

test( 'a fetch that never arrives says so', async () => {
	const harness = await oneLocator( { payload: new Error( 'network down' ) } );

	assert.strictEqual( message( harness.container ), defaultStrings().loadFailed );
	assert.ok( harness.container.classList.contains( 'slosm--error' ) );
} );

test( 'an http error says so rather than drawing an empty map', async () => {
	// The body is a *list*, and that is the whole point of this case. With an
	// object body the response.ok check is redundant — Array.isArray catches
	// it one step later and produces the same message — so a case written that
	// way passes with the status check deleted. A 500 that happens to carry a
	// valid json array is the shape that tells the two apart, and a caching
	// layer serving a stale body under an error status is how it happens.
	const harness = await oneLocator( {
		payload: jsonResponse( [ store() ], { ok: false, status: 500 } ),
	} );

	assert.strictEqual( message( harness.container ), defaultStrings().loadFailed );
	assert.strictEqual( harness.leafletCalls.marker.length, 0, 'a 500 was drawn on the map anyway' );
	assert.ok( harness.consoleCalls.warn.some( ( args ) => /answered 500/.test( String( args[ 0 ] ) ) ) );
} );

test( 'an error status carrying an error object also says so', async () => {
	const harness = await oneLocator( {
		payload: jsonResponse( { code: 'rest_no_route' }, { ok: false, status: 404 } ),
	} );

	assert.strictEqual( message( harness.container ), defaultStrings().loadFailed );
} );

test( 'fetch missing from the page is reported, not thrown', async () => {
	// Not a browser this plugin supports — every one of them has fetch. It is
	// the site whose service worker or polyfill kit leaves the global
	// uncallable. Without the guard this is an uncaught TypeError halfway
	// through init, on a container that already has a map in it and will never
	// get a message.
	const harness = loadLocator( { withFetch: false } );
	const container = harness.locatorMarkup();

	harness.SLOSM.initAll();

	assert.strictEqual( message( container ), defaultStrings().loadFailed );
	assert.strictEqual( harness.leafletCalls.map.length, 0 );
} );

test( 'a query-mode locator does not need fetch at all', async () => {
	// The guard is conditional on preload mode, because query mode asks for
	// nothing. A map is better than a message here.
	const harness = loadLocator( { withFetch: false } );
	const container = harness.locatorMarkup( { config: defaultConfig( { mode: 'query', count: 900 } ) } );

	harness.SLOSM.initAll();

	assert.strictEqual( message( container ), null );
	assert.strictEqual( harness.leafletCalls.map.length, 1 );
} );

test( 'a body that is not json says so', async () => {
	// A security plugin, a maintenance page or a 500 from PHP all arrive as
	// html with a 200 on plenty of hosts.
	const harness = await oneLocator( { payload: brokenJsonResponse() } );

	assert.strictEqual( message( harness.container ), defaultStrings().loadFailed );
} );

test( 'a json body that is not a list says so, and says which', async () => {
	const harness = await oneLocator( { payload: jsonResponse( { code: 'rest_forbidden' } ) } );

	assert.strictEqual( message( harness.container ), defaultStrings().loadFailed );
	assert.strictEqual( harness.leafletCalls.marker.length, 0 );

	// The console text, not just the message, and that is what gives the
	// Array.isArray check teeth. Deleting it leaves the visible outcome
	// identical — `({}).forEach` is a TypeError, so the catch produces the
	// same sentence — and changes the developer-facing line from a diagnosis
	// to a stack trace. Verified: ({}).forEach throws
	// "{}.forEach is not a function", it is not a silent no-op, and an earlier
	// version of locator.js's comment said otherwise.
	assert.ok(
		harness.consoleCalls.warn.some( ( args ) => /did not answer with a list/.test( String( args[ 0 ] ) ) ),
		'the console said nothing about the body not being a list'
	);
} );

test( 'a failure still leaves a map on the page', async () => {
	// The map is built before the request goes out, so a site whose REST api
	// is blocked shows tiles and a message rather than a grey box. It is also
	// what Task 14's search will recentre.
	const harness = await oneLocator( { payload: new Error( 'network down' ) } );

	assert.strictEqual( harness.leafletCalls.map.length, 1 );
	assert.strictEqual( harness.leafletCalls.tileLayer.length, 1 );
} );

/* -------------------------------------------------------------------------
 * Query mode
 * ---------------------------------------------------------------------- */

test( 'query mode draws the map and asks for nothing, because the search is Task 14', async () => {
	// Above the preload threshold the whole list must not be fetched; that is
	// the entire point of the mode. Until Task 14 ships a search there is
	// nothing to ask for, so this locator is a map at its configured view and
	// an empty list — not a "no results" message, which would be a claim
	// nobody has checked.
	const harness = await oneLocator( {
		config: defaultConfig( { mode: 'query', count: 900, lat: 52.2297, lng: 21.0122 } ),
	} );

	assert.strictEqual( harness.fetchCalls.length, 0 );
	assert.strictEqual( harness.leafletCalls.map.length, 1 );
	assert.strictEqual( harness.leafletCalls.tileLayer.length, 1 );
	assert.deepStrictEqual( plain( harness.leafletCalls.setView[ 0 ].center ), [ 52.2297, 21.0122 ] );
	assert.strictEqual( message( harness.container ), null );
} );

test( 'query mode with no centre shows the world rather than nothing', async () => {
	const harness = await oneLocator( { config: defaultConfig( { mode: 'query', count: 900 } ) } );

	assert.strictEqual( harness.leafletCalls.fitWorld.length, 1 );
	assert.strictEqual( harness.leafletCalls.fitBounds.length, 0 );
} );

/* -------------------------------------------------------------------------
 * Server data meeting the DOM
 * ---------------------------------------------------------------------- */

test( 'a location name full of markup stays text', async () => {
	// A post title written by a user with unfiltered_html legitimately
	// contains html. PHP escaped it for an html attribute on the way into the
	// page; none of that reaches json, so the browser gets the raw characters
	// and innerHTML here would be stored xss. The harness throws on innerHTML
	// in either direction, so had any of this run through it the case would
	// fail with that error rather than this assertion.
	const hostile = '<img src=x onerror="alert(1)"><script>alert(2)</script>';
	const harness = await oneLocator( { payload: [ store( { name: hostile } ) ] } );

	assert.strictEqual( harness.leafletCalls.marker[ 0 ].options.title, hostile );
	assert.strictEqual( harness.leafletCalls.marker[ 0 ].options.alt, hostile );
} );

test( 'a translated string full of markup stays text', async () => {
	// The other direction: slosmL10n is server data too. It comes from a .po
	// file a site owner may have edited, and it reaches the DOM directly.
	const hostile = '<img src=x onerror="alert(1)">';
	const harness = await oneLocator( {
		payload: [],
		strings: defaultStrings( { noResults: hostile } ),
	} );

	const node = harness.container.querySelector( '.slosm__message' );

	assert.strictEqual( node.textContent, hostile );
	assert.strictEqual( node.children.length, 0, 'the message was parsed as markup' );
} );

/* -------------------------------------------------------------------------
 * Strings
 * ---------------------------------------------------------------------- */

test( 'uses the translated strings when wp_localize_script delivered them', async () => {
	const harness = await oneLocator( {
		payload: [],
		strings: defaultStrings( { noResults: 'Brak wyników' } ),
	} );

	assert.strictEqual( message( harness.container ), 'Brak wyników' );
} );

test( 'falls back to English when slosmL10n never arrived', () => {
	// The l10n is an inline script printed before the deferred file, so on a
	// working site it is always there. It is not there when a plugin dequeues
	// the handle, or when an optimiser moves the inline script. English is a
	// degradation; a blank message or a TypeError is a broken page.
	const harness = loadLocator( { strings: null } );
	const container = harness.locatorMarkup( { config: null } );

	harness.SLOSM.initAll();

	assert.strictEqual( message( container ), defaultStrings().configError );
} );

test( 'falls back per key, not all or nothing', () => {
	const harness = loadLocator( { strings: { noResults: 'Brak wyników' } } );
	const container = harness.locatorMarkup( { config: null } );

	harness.SLOSM.initAll();

	assert.strictEqual( message( container ), defaultStrings().configError );
} );

test( 'a non-string in the l10n payload does not become the message', () => {
	const harness = loadLocator( { strings: { configError: { toString: () => 'nope' } } } );
	const container = harness.locatorMarkup( { config: null } );

	harness.SLOSM.initAll();

	assert.strictEqual( message( container ), defaultStrings().configError );
} );

/* -------------------------------------------------------------------------
 * Booting
 * ---------------------------------------------------------------------- */

test( 'initialises what is already on the page the moment it runs', () => {
	// A deferred script runs after the parser has finished, when readyState is
	// 'interactive' and before DOMContentLoaded fires — so the locators are
	// all there and there is nothing to wait for. `prepare` puts the markup in
	// place before the file is evaluated, which is the only way to tell "the
	// file booted" from "the test called initAll()".
	let container = null;

	const harness = loadLocator( {
		readyState: 'interactive',
		prepare: ( world ) => {
			world.fetchQueue.push( jsonResponse( [ store() ] ) );
			container = world.locatorMarkup();
		},
	} );

	assert.strictEqual( harness.leafletCalls.map.length, 1 );
	assert.strictEqual( harness.leafletCalls.map[ 0 ].container, container.querySelector( '.slosm__map' ) );

	// And it did not also queue a listener for an event it has no reason to
	// wait for. The positive assertion above is what gives this one teeth: a
	// file that does nothing at all would register no listener either.
	assert.strictEqual(
		harness.document.listeners.has( 'DOMContentLoaded' ),
		false,
		'the file waited for DOMContentLoaded on a document that had already finished parsing'
	);
} );

test( 'waits for the parser when it is still running', () => {
	let container = null;

	const harness = loadLocator( {
		readyState: 'loading',
		prepare: ( world ) => {
			world.fetchQueue.push( jsonResponse( [ store() ] ) );
			container = world.locatorMarkup();
		},
	} );

	// 'loading' means the parser has not finished, so half the locators may
	// not be in the tree yet.
	assert.strictEqual( harness.leafletCalls.map.length, 0 );

	const ran = harness.document.dispatchEvent( 'DOMContentLoaded' );

	assert.strictEqual( ran, 1, 'the file registered no DOMContentLoaded listener' );
	assert.strictEqual( harness.leafletCalls.map.length, 1 );
	assert.strictEqual( harness.leafletCalls.map[ 0 ].container, container.querySelector( '.slosm__map' ) );
} );

/* -------------------------------------------------------------------------
 * Task 21: the site-wide settings the front end reads
 * ---------------------------------------------------------------------- */

test( 'draws the standard pin by default, and a coloured dot when the site asked for one', async () => {
	const pinned = await oneLocator( { payload: [ store() ] } );

	// No icon option at all for the pin: Leaflet's own default marker is what
	// a marker gets when nothing says otherwise, and passing one would be this
	// plugin restating a default it does not own.
	assert.strictEqual( pinned.leafletCalls.marker[ 0 ].options.icon, undefined );
	assert.strictEqual( pinned.leafletCalls.divIcon.length, 0 );

	const dotted = await oneLocator( {
		payload: [ store() ],
		config: defaultConfig( { marker: { style: 'dot', colour: '#c0392b' } } ),
	} );

	assert.strictEqual( dotted.leafletCalls.divIcon.length, 1 );
	assert.ok( dotted.leafletCalls.marker[ 0 ].options.icon, 'the dot was built and not used' );

	const icon = dotted.leafletCalls.divIcon[ 0 ].options;

	// An Element and never a string. Leaflet's DivIcon.createIcon is
	// `e.html instanceof Element?(me(t),t.appendChild(e.html)):t.innerHTML=…`,
	// so a string would be parsed as markup — the rule tileFor()'s docblock
	// states and clusterIcon() already relies on.
	assert.strictEqual( typeof icon.html, 'object' );
	assert.strictEqual( icon.html.tagName, 'SPAN' );
	assert.strictEqual( icon.html.className, 'slosm__dot' );
	assert.strictEqual( icon.html.style.backgroundColor, '#c0392b' );

	// Centred on the location rather than standing above it, which is what a
	// dot means and a pin does not.
	assert.strictEqual( icon.iconSize.join( ',' ), '16,16' );
	assert.strictEqual( icon.iconAnchor.join( ',' ), '8,8' );
} );

test( 'a dot with no colour is still a dot, and an unknown style is still a pin', async () => {
	const uncoloured = await oneLocator( {
		payload: [ store() ],
		config: defaultConfig( { marker: { style: 'dot', colour: '' } } ),
	} );

	// The stylesheet's own #3388ff is what it gets; nothing is written into the
	// style attribute at all, so there is no `background-color:` with nothing
	// after it for a browser to ignore.
	assert.strictEqual( uncoloured.leafletCalls.divIcon.length, 1 );
	assert.strictEqual( uncoloured.leafletCalls.divIcon[ 0 ].options.html.style.backgroundColor, undefined );

	for ( const marker of [ { style: 'teardrop' }, null, 'dot' ] ) {
		const odd = await oneLocator( {
			payload: [ store() ],
			config: defaultConfig( { marker } ),
		} );

		assert.strictEqual( odd.leafletCalls.divIcon.length, 0, JSON.stringify( marker ) );
		assert.strictEqual( odd.leafletCalls.marker[ 0 ].options.icon, undefined, JSON.stringify( marker ) );
	}
} );

test( 'a row shows the fields the site chose, and has no element for the others', async () => {
	const all = await oneLocator( { payload: [ store() ] } );
	const row = all.container.querySelector( '.slosm__result' );

	[ 'name', 'address', 'city', 'distance' ].forEach( ( field ) => {
		assert.ok( row.querySelector( '.slosm__result-' + field ), field + ' is missing by default' );
	} );
	assert.ok( row.querySelector( '.slosm__result-categories' ) );

	const narrowed = await oneLocator( {
		payload: [ store() ],
		config: defaultConfig( { fields: [ 'name', 'distance' ] } ),
	} );
	const thin = narrowed.container.querySelector( '.slosm__result' );

	assert.ok( thin.querySelector( '.slosm__result-name' ) );
	assert.ok( thin.querySelector( '.slosm__result-distance' ) );

	// Taken out rather than left empty or hidden: an empty span still occupies
	// a line in the accessibility tree, and display:none is a rule a theme can
	// override. drop() in assets/js/locator.js has the argument.
	assert.strictEqual( thin.querySelector( '.slosm__result-address' ), null );
	assert.strictEqual( thin.querySelector( '.slosm__result-city' ), null );
	assert.strictEqual( thin.querySelector( '.slosm__result-categories' ), null );
} );

test( 'a config with no field list shows everything, which is what a hand-written one gets', async () => {
	// Missing, not a list, and a list of things this file has never heard of.
	// All three mean "show it": each is a config this plugin did not write, and
	// the failure that matters is a locator quietly dropping the name of every
	// shop on it. Settings::field_list() makes the same choice on the server.
	for ( const fields of [ undefined, 'name', 42, [ 'nothing' ] ] ) {
		const harness = await oneLocator( {
			payload: [ store() ],
			config: defaultConfig( { fields } ),
		} );
		const row = harness.container.querySelector( '.slosm__result' );

		if ( Array.isArray( fields ) ) {
			// A list this file can read, with nothing on it that it knows:
			// that one really is "show none", because the server would have
			// replaced an empty choice with the whole list before it got here.
			assert.strictEqual( row.querySelector( '.slosm__result-name' ), null, JSON.stringify( fields ) );

			continue;
		}

		assert.ok( row.querySelector( '.slosm__result-name' ), JSON.stringify( fields ) );
		assert.ok( row.querySelector( '.slosm__result-city' ), JSON.stringify( fields ) );
	}
} );

test( 'autocomplete off means nothing asks /suggest, and Enter still searches', async () => {
	const off = await oneLocator( {
		payload: [ store() ],
		config: defaultConfig( { autocomplete: false } ),
	} );
	const field = off.container.querySelector( '.slosm__search' );

	// Three things are not done, and each is separately visible: no combobox
	// role, no listbox in the document, and no listener for typing.
	assert.strictEqual( field.getAttribute( 'role' ), null );
	assert.strictEqual( off.container.querySelector( '.slosm__suggestions' ), null );

	field.value = 'warsz';
	field.dispatchEvent( { type: 'input' } );

	assert.strictEqual( off.clock.pending(), 0, 'a keystroke was queued for /suggest' );
	assert.strictEqual(
		off.fetchCalls.filter( ( call ) => call.url.indexOf( '/suggest' ) > -1 ).length,
		0
	);

	// The control: the same fixture with the setting on does all three.
	const on = await oneLocator( { payload: [ store() ] } );
	const wired = on.container.querySelector( '.slosm__search' );

	assert.strictEqual( wired.getAttribute( 'role' ), 'combobox' );
	assert.ok( on.container.querySelector( '.slosm__suggestions' ) );

	wired.value = 'warsz';
	wired.dispatchEvent( { type: 'input' } );

	assert.strictEqual( on.clock.pending(), 1 );
} );

/* -------------------------------------------------------------------------
 * sweep(): what a page builder needs and an ordinary page does not
 * ---------------------------------------------------------------------- */

test( 'sweep() takes apart the map of a locator whose node has left the page', async () => {
	// The defect this exists for, in one sentence: a Bricks re-render parses
	// the server's HTML into a NEW node and drops the old one, and an L.Map
	// keeps a `resize` listener on `window` — so without a teardown, every
	// edit in an editing session left another live map bound to a container
	// that is no longer in the document.
	const harness = await oneLocator( { payload: [] } );
	const gone = harness.container;
	const removed = harness.instance.map;

	assert.strictEqual( harness.leafletCalls.mapRemove.length, 0, 'something removed a map before it was asked to' );

	gone.parentNode.removeChild( gone );

	const arrived = harness.locatorMarkup( {} );
	const started = harness.SLOSM.sweep();

	// Both halves of the sweep, in one call: the detached one is taken apart
	// and the new one is started.
	assert.strictEqual( harness.leafletCalls.mapRemove.length, 1 );
	assert.strictEqual( harness.leafletCalls.mapRemove[ 0 ].map, removed );
	assert.strictEqual( started.length, 1 );
	assert.strictEqual( started[ 0 ].element, arrived );

	// The control, and it is the assertion that matters most: the locator
	// that is still on the page was not taken apart with it.
	assert.notStrictEqual( harness.leafletCalls.mapRemove[ 0 ].map, started[ 0 ].map );

	// And it is taken apart once. The entry leaves the registry with the map,
	// so a second sweep has nothing to find — without that, a builder session
	// would call remove() on the same dead map once per edit forever.
	harness.SLOSM.sweep();

	assert.strictEqual( harness.leafletCalls.mapRemove.length, 1 );
} );

test( 'a browser that cannot answer "is this still on the page" loses no maps', () => {
	// `Node.isConnected` is universal in everything WordPress 6.0 supports, so
	// this is not about a browser anybody has. It is about the comparison:
	// the code asks whether the answer is `false`, not whether it is falsy,
	// and `! undefined` is true — so the loose form would read "I cannot tell"
	// as "it has gone" and destroy every map on the page.
	//
	// An own property shadowing the harness's getter is the separating input,
	// and there is no other: the harness always knows the answer.
	const harness = loadLocator();
	const container = harness.locatorMarkup( {} );

	harness.SLOSM.initAll();

	Object.defineProperty( container, 'isConnected', { value: undefined, configurable: true } );

	harness.SLOSM.sweep();

	assert.strictEqual( harness.leafletCalls.mapRemove.length, 0, 'a map was destroyed on a guess' );

	// The control: the same container, with the same shadowing property set to
	// the answer that really does mean "gone", is destroyed.
	Object.defineProperty( container, 'isConnected', { value: false, configurable: true } );

	harness.SLOSM.sweep();

	assert.strictEqual( harness.leafletCalls.mapRemove.length, 1 );
} );

test( 'sweep() leaves a locator that is still on the page alone, however often it runs', async () => {
	// The control for the case above. "One map was removed" is also what a
	// sweep that removes everything says on a page with one locator, and a
	// sweep that ran on a page where nothing had gone would show up here as
	// a map disappearing from under a visitor who had done nothing.
	const harness = await oneLocator( { payload: [] } );

	harness.SLOSM.sweep();
	harness.SLOSM.sweep();

	assert.strictEqual( harness.leafletCalls.mapRemove.length, 0 );

	// And nothing was started twice: the WeakSet still holds the container,
	// so the second and third passes found nothing to do.
	assert.strictEqual( harness.leafletCalls.map.length, 1 );
} );

test( 'initAll() is not a sweep, which is why the builder calls sweep()', async () => {
	// The separating input between the two entry points. Without this case
	// `sweep` could be an alias for `initAll` and every assertion above would
	// still need a detached node to be taken apart by *something*.
	const harness = await oneLocator( { payload: [] } );

	harness.container.parentNode.removeChild( harness.container );
	harness.SLOSM.initAll();

	assert.strictEqual( harness.leafletCalls.mapRemove.length, 0 );

	harness.SLOSM.sweep();

	assert.strictEqual( harness.leafletCalls.mapRemove.length, 1 );
} );

test( 'sweep() forgets a swept container, so the WeakSet keeps meaning what it says', async () => {
	// The WeakSet means "this container has a live map on it". After the map
	// has been taken apart that is no longer true, so the entry goes with it
	// — otherwise a container that came back would be a permanently dead one.
	//
	// Not a shape Bricks produces: it parses a fresh node every time. It is
	// here because the alternative is a WeakSet whose stated meaning and real
	// meaning have quietly diverged, which is how the next reader gets it
	// wrong.
	const harness = await oneLocator( { payload: [] } );
	const container = harness.container;

	container.parentNode.removeChild( container );
	harness.SLOSM.sweep();

	assert.strictEqual( harness.leafletCalls.mapRemove.length, 1 );

	harness.document.body.appendChild( container );

	const again = harness.SLOSM.sweep();

	assert.strictEqual( again.length, 1 );
	assert.strictEqual( again[ 0 ].element, container );
	assert.strictEqual( harness.leafletCalls.map.length, 2, 'the container was never started a second time' );
} );

test( 'a map that throws on the way out does not take the rest of the sweep with it', async () => {
	// Leaflet's remove() touches panes, handlers and the container's own
	// _leaflet_id, and this file cannot promise none of that throws on a node
	// a page builder has already mangled. One throw escaping would leave the
	// rest of the list unswept and would land in whatever called sweep() —
	// which, in the builder, is a MutationObserver callback.
	const harness = await oneLocator( { payload: [] } );
	const first = harness.container;

	harness.locatorMarkup( {} );

	const second = harness.SLOSM.initAll()[ 0 ];

	first.parentNode.removeChild( first );
	second.element.parentNode.removeChild( second.element );

	harness.instance.map.remove = () => {
		throw new Error( 'pane already gone' );
	};

	harness.SLOSM.sweep();

	// The second one was still taken apart, and the throw was reported rather
	// than swallowed.
	assert.strictEqual( harness.leafletCalls.mapRemove.length, 1 );
	assert.strictEqual( harness.leafletCalls.mapRemove[ 0 ].map, second.map );
	assert.ok(
		harness.consoleCalls.warn.some( ( args ) => -1 < String( args[ 0 ] ).indexOf( 'pane already gone' ) ),
		'the throw went nowhere anybody can read'
	);
} );

test( 'a named centre is where the map opens, not where it stays', async () => {
	// Task 32, and it reverses a rule Task 13 wrote down. The old one read "an
	// explicit lat/lng is an instruction and markers do not get to overrule
	// it", which sounds right and is wrong in the one way that matters: the
	// centre is a *site setting* with no idea where the locations are, so a
	// site that sets one — Settings' default_lat/default_lng, which is the
	// ordinary thing to do — gets a map that is never again allowed to show a
	// pin. Measured on a real page: three locations, a centre on Warsaw at
	// zoom 12, and all three pins outside the 629x480 viewport, one of them
	// 10239px below it.
	//
	// So the centre keeps the job it can do and loses the one it cannot. It is
	// the view the map opens on, before any data has arrived and while the
	// list is still in flight; the draw that follows frames what it drew.
	const harness = await oneLocator( {
		payload: [ store(), store( { id: 2, name: 'Krakow', lat: 50.0647, lng: 19.945 } ) ],
		config: defaultConfig( { lat: 52.2297, lng: 21.0122, zoom: 9 } ),
	} );

	// The opening view is still the centre that was named, at the zoom that
	// was named. Nothing about that changed: a map that opened on the markers
	// would have nothing to open on until the payload landed.
	assert.deepStrictEqual( plain( harness.leafletCalls.setView[ 0 ].center ), [ 52.2297, 21.0122 ] );
	assert.strictEqual( harness.leafletCalls.setView[ 0 ].zoom, 9 );

	// And then the draw frames what it drew.
	assert.strictEqual( harness.leafletCalls.fitBounds.length, 1, 'the draw left the map on the centre, with the pins off it' );
	assert.deepStrictEqual( plain( harness.leafletCalls.fitBounds[ 0 ].bounds ), [
		[ 52.2297, 21.0122 ],
		[ 50.0647, 19.945 ],
	] );
} );

test( 'framing never zooms in closer than the zoom the shortcode asked for', async () => {
	// A frame around one point has no size, and Leaflet answers a zero-sized
	// bounds with the closest zoom it is allowed — `getBoundsZoom` returns the
	// map's maxZoom, which here is the tile server's 19. So a site with one
	// location gets a doorstep-level close-up on page load, and a search whose
	// address happens to sit on a branch gets the same.
	//
	// This was reachable before Task 32 and is now ordinary: framing used to
	// be off for every site that had named a centre, which is every site that
	// filled in Settings' default centre, and it is on for all of them now.
	//
	// The cap is the shortcode's own zoom, because that number is already the
	// site's answer to "how close should this map be". maxZoom only bounds
	// zooming *in*: a frame around locations spread across a country still
	// pulls back as far as it needs to.
	const harness = await oneLocator( {
		payload: [ store() ],
		config: defaultConfig( { zoom: 11 } ),
	} );

	assert.strictEqual( harness.leafletCalls.fitBounds.length, 1 );
	assert.deepStrictEqual( plain( harness.leafletCalls.fitBounds[ 0 ].bounds ), [ [ 52.2297, 21.0122 ] ] );
	// Read off the object rather than deep-compared with a literal: the
	// options are built inside the vm sandbox, so they carry the sandbox's
	// Object.prototype and deepStrictEqual calls that "same structure, not
	// reference-equal". The same cross-realm trap plain() exists for.
	assert.strictEqual( harness.leafletCalls.fitBounds[ 0 ].options.maxZoom, 11 );
} );

test( 'the frame leaves room for a pin, which stands above the point it marks', async () => {
	// Seen on a live page the moment framing started working: a search framed
	// both results, and the further one landed at y = -33 in a 629x480 map —
	// inside the frame by its coordinate, and clipped to about eight visible
	// pixels by the top edge. fitBounds fits *coordinates*; Leaflet's default
	// icon is 25x41 with its anchor on the tip, so a marker whose point sits
	// exactly on the edge has its whole head outside the map.
	//
	// Asymmetric, because the icons are. A pin stands 41px above its point and
	// nothing below it; a dot (DOT_SIZE 16, centred) and a cluster bubble
	// (CLUSTER_SIZE 40, centred) reach 8 and 20 in every direction. So the top
	// clears the tallest thing above an anchor and the other three clear the
	// widest thing around one, with a few pixels over so a pin is not flush
	// against the edge it just cleared.
	const harness = await oneLocator( {
		payload: [ store(), store( { id: 2, name: 'Krakow', lat: 50.0647, lng: 19.945 } ) ],
	} );

	assert.strictEqual( harness.leafletCalls.fitBounds.length, 1 );

	const options = harness.leafletCalls.fitBounds[ 0 ].options;

	assert.deepStrictEqual( plain( options.paddingTopLeft ), [ 24, 48 ] );
	assert.deepStrictEqual( plain( options.paddingBottomRight ), [ 24, 24 ] );

	// And the cap is still there: padding and maxZoom are two different
	// questions about the same frame, and an option object that replaced one
	// with the other would pass a case about either on its own.
	assert.strictEqual( options.maxZoom, 12 );
} );

test( 'a map whose container changes size is told to measure itself again', async () => {
	// Task 35, found by switching the skin off on a live page: the map drew
	// its tiles across part of its box and left the rest as the flat grey
	// --slosm-map-background, in a clean vertical edge. Reproduced in the
	// browser by widening the container after init, and the numbers are the
	// whole argument:
	//
	//     container 629 -> 900,  tiles stayed 768,  154px of grey
	//     after 2.5s                                154px of grey
	//     after a window 'resize' event             154px of grey
	//
	// Leaflet measures its container once at construction and again on window
	// resize — `trackResize` binds that — and a container that changes size
	// without the window changing size is invisible to both. A page builder's
	// canvas, a tab, an accordion, a sidebar collapsing and, as here, a
	// stylesheet being switched off all do exactly that.
	//
	// What this cannot assert, and the harness header says so at length: that
	// the tiles then covered the box. There is no layout here. It asserts the
	// call, which is the part this plugin is responsible for.
	const harness = await oneLocator( { payload: [ store() ] } );
	const observers = harness.resizeObservers;

	assert.strictEqual( observers.length, 1, 'nothing watched the map for a change of size' );

	const watched = observers[ 0 ].targets;

	assert.strictEqual( watched.length, 1, 'the observer was built and given nothing to watch' );
	assert.ok(
		watched[ 0 ] === harness.container.querySelector( '.slosm__map' ),
		'something other than the map element is being watched'
	);

	const before = harness.leafletCalls.invalidateSize.length;

	observers[ 0 ].deliver();

	assert.strictEqual(
		harness.leafletCalls.invalidateSize.length,
		before + 1,
		'the container changed size and the map was never told'
	);
} );

test( 'a locator taken apart stops watching its own container', async () => {
	// The other half, and the half that a teardown gets wrong silently: an
	// observer left connected holds the element, the map and every closure
	// around them, and goes on calling invalidateSize on a map that Leaflet
	// has already removed.
	const harness = await oneLocator( { payload: [ store() ] } );
	const observer = harness.resizeObservers[ 0 ];

	// Taken out of the document first, because that is what forget() looks
	// for and what really happens: Bricks replaces a locator's whole node on
	// every control change, and the old one is detached before the sweep runs.
	harness.container.parentNode.removeChild( harness.container );

	// sweep() rather than forget(): forget() is internal, and sweep() is the
	// entry point a page builder calls — forget the gone, start the arrived —
	// which is exactly the moment this has to happen.
	harness.SLOSM.sweep();

	assert.strictEqual( observer.disconnected, true, 'the observer outlived the locator' );

	const after = harness.leafletCalls.invalidateSize.length;

	observer.deliver();

	assert.strictEqual(
		harness.leafletCalls.invalidateSize.length,
		after,
		'a disconnected observer still reached a map that is gone'
	);
} );
