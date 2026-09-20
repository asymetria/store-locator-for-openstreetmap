/**
 * Task 16: the popup on a pin, and the way out to openstreetmap.org.
 *
 * WHY HALF OF THIS FILE IS ABOUT ONE LINE OF SOMEBODY ELSE'S CODE
 * ==============================================================
 * Leaflet's `Popup._updateContent` is
 * `if("string"==typeof e)t.innerHTML=e;else{for(;t.hasChildNodes();)
 * t.removeChild(t.firstChild);t.appendChild(e)}` — byte offset 95938 of
 * assets/leaflet/leaflet.js, and a case below pins those bytes. A location
 * name handed to `bindPopup()` as a *string* is therefore parsed as markup,
 * and a post title written by a user with unfiltered_html legitimately
 * contains markup. That is stored xss, sitting inside a dependency where the
 * forbidden-property scan in tests/js/locator.test.js cannot see it: that scan
 * reads locator.js, and the innerHTML in question is Leaflet's.
 *
 * So the defence is not "escape it on the way in". It is "never hand over a
 * string at all", which takes Leaflet's other branch — `appendChild` — and
 * removes the parser from the path entirely. Task 15 did the same for the
 * cluster icon and `L.divIcon`. Two kinds of case hold it shut:
 *
 * - every call recorded on `bindPopup` and `setPopupContent` is asserted to be
 *   a node rather than a string, and
 * - the bytes above are asserted to still be in the vendored file, so a
 *   Leaflet upgrade that dropped the node branch fails here rather than on
 *   somebody's site.
 *
 * Neither is provable by rendering, because nothing in this suite renders. The
 * harness header says so at length and it is worth repeating: `L` here records
 * calls, a `StubPopup` holds a content value and an open flag, and whether a
 * bubble appeared over a pin is a manual check.
 *
 * WHAT A "PARTIAL" POPUP IS
 * =========================
 * GET /stores carries seven fields per location; the phone number, the
 * opening hours and the description are only on GET /stores/<id>. A popup that
 * waited for that request would be an empty rectangle for as long as the
 * round trip takes, so the popup is built from what is already in hand, bound
 * before anything is asked for, and refilled when the answer lands. The cases
 * below assert both halves of that separately, because a file that only ever
 * rendered the full record would pass every assertion about the full record.
 *
 * ONE THING THIS FILE GOT WRONG FIRST, RECORDED SO IT IS NOT GOT WRONG AGAIN
 * =========================================================================
 * Two of the cases here asserted `deepStrictEqual( someElements, [] )`. Both
 * passed, and both caught their mutation — as `FATAL ERROR: JavaScript heap
 * out of memory`, after two and a half seconds, with no case name anywhere in
 * the output. That is the failure the harness header warns about in full: a
 * StubElement reaches its listeners, every one of which closes over the
 * locator, so structurally comparing an element against anything it does not
 * match walks the whole document. The assertion is only ever slow and silent
 * when it is the assertion that matters. Count nodes and compare identity with
 * `===`; never compare one structurally.
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
	plain,
	pluginSource,
	position,
} = require( './harness.js' );

/** Warsaw and Kraków, as everywhere else in this suite. */
const WARSAW = { lat: 52.2297, lng: 21.0122 };
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
 * One store, whole, as GET /stores/<id> returns it.
 *
 * Every key Rest_Controller::get_item_schema() declares and no others —
 * lat_locked included, which get_store() unsets on the way out.
 *
 * @param {object} overrides Keys to change.
 * @returns {object} The record.
 */
function record( overrides ) {
	return Object.assign(
		{
			id: 1,
			name: 'Warsaw',
			description: 'The one by the roundabout.',
			address: 'Nowy Świat 1',
			address2: 'Second floor',
			city: 'Warszawa',
			state: 'Mazowieckie',
			zip: '00-001',
			country: 'Poland',
			lat: WARSAW.lat,
			lng: WARSAW.lng,
			phone: '+48 22 000 00 00',
			email: 'hello@example.test',
			url: 'https://example.test/warsaw',
			hours: 'Mon-Fri 9-17\nSat 10-14',
			categories: [ 'Shops' ],
		},
		overrides || {}
	);
}

/**
 * A locator, already preloaded with `payload`.
 *
 * A radius of 500 by default, for the reason the other two front-end files
 * give: the fixtures are Polish cities hundreds of kilometres apart and the
 * shortcode's own default of 50 would filter most of them out of every case.
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
		withTemplate: settings.withTemplate,
	} );

	const instances = harness.SLOSM.initAll();

	if ( instances[ 0 ] && instances[ 0 ].ready ) {
		await instances[ 0 ].ready;
	}

	return Object.assign( harness, { container, instance: instances[ 0 ] || null } );
}

/** @returns {object} The nth marker layer. */
function marker( harness, at ) {
	return harness.leafletCalls.marker[ at || 0 ].layer;
}

/**
 * Clicks the nth pin, the way a pointer on an icon would.
 *
 * Not `fire()` from the harness, which dispatches a DOM event at an element:
 * a marker is a Leaflet layer and a click reaches it through Evented. The
 * handler that opens the bubble is Leaflet's own, bound by bindPopup; the one
 * that asks for the record is this plugin's.
 *
 * @param {object} harness The harness.
 * @param {number} at      Which marker.
 * @returns {object} The marker.
 */
function clickPin( harness, at ) {
	return marker( harness, at ).fire( 'click' );
}

/** @returns {object|null} The nth marker's popup content node. */
function popup( harness, at ) {
	const bound = marker( harness, at ).getPopup();

	return bound ? bound.getContent() : null;
}

/** @returns {string} The text of one part of a popup, or '' when it is absent. */
function part( node, name ) {
	const found = node ? node.querySelector( '.slosm__popup-' + name ) : null;

	return found ? found.textContent : '';
}

/**
 * The anchors inside one part of a popup.
 *
 * Handed back so a case can count them and read a tag name off them, and
 * deliberately never compared structurally: see the harness header, and the
 * comment in the javascript: case, which is where that rule earned itself.
 *
 * @param {object} node The popup's element.
 * @param {string} name Which part.
 * @returns {Array} The `<a>` children, possibly none.
 */
function anchors( node, name ) {
	const found = node ? node.querySelector( '.slosm__popup-' + name ) : null;

	return found ? found.children.filter( ( child ) => 'A' === child.tagName ) : [];
}

/** @returns {Array<string>} The category tags in a popup, in order. */
function categories( node ) {
	return node.querySelectorAll( '.slosm__popup-category' ).map( ( tag ) => tag.textContent );
}

/** @returns {Array} The result rows on screen. */
function rows( container ) {
	return container.querySelectorAll( '.slosm__result' );
}

/** @returns {Array<object>} Every fetch of a single location, in order. */
function storeRequests( harness ) {
	return harness.fetchCalls.filter( ( call ) => call.url.indexOf( '/v1/stores/' ) > -1 );
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

/** A name, an address and an opening-hours block that are all markup. */
const MARKUP_NAME = '<img src=x onerror=alert(1)>Warsaw';
const MARKUP_ADDRESS = '<script>alert(2)</script>Nowy Świat 1';
const MARKUP_HOURS = 'Mon-Fri 9-17<script>alert(3)</script>';

/* -------------------------------------------------------------------------
 * The hazard: nothing reaches Leaflet as a string
 * ---------------------------------------------------------------------- */

test( 'the vendored Leaflet still appends a node instead of parsing it', () => {
	// The whole of Task 16's defence rests on Popup._updateContent taking its
	// else branch for anything that is not a string. If an upgrade ever drops
	// that branch, every popup in this plugin becomes an injection point and
	// no case about locator.js would notice. So the bytes are pinned here.
	const leaflet = pluginSource( 'assets', 'leaflet', 'leaflet.js' );

	assert.ok(
		leaflet.includes(
			'if("string"==typeof e)t.innerHTML=e;else{for(;t.hasChildNodes();)t.removeChild(t.firstChild);t.appendChild(e)}'
		),
		'assets/leaflet/leaflet.js no longer appends popup content as a node: re-read Popup._updateContent before trusting any case in this file'
	);

	// And the control for the scan itself: the file really is the vendored
	// Leaflet and not an empty placeholder, so the assertion above is about a
	// present-but-changed branch rather than about a missing file.
	assert.ok( leaflet.includes( '_updateContent:function()' ), 'that is not Leaflet' );
	assert.ok( leaflet.includes( 'setContent:function(t){return this._content=t' ), 'Popup.setContent changed shape' );
} );

test( 'every popup Leaflet is handed is a node, never a string', async () => {
	const harness = await locator( {
		payload: [ store( { name: MARKUP_NAME, address: MARKUP_ADDRESS } ) ],
	} );

	harness.fetchQueue.push( jsonResponse( record( { name: MARKUP_NAME, hours: MARKUP_HOURS } ) ) );

	clickPin( harness );

	await harness.instance.pendingRecord;

	// The control first: both calls really happened, so the type assertions
	// below are about what was passed and not about an empty log.
	assert.ok( harness.leafletCalls.bindPopup.length > 0, 'nothing was ever bound to a popup' );
	assert.ok( harness.leafletCalls.setPopupContent.length > 0, 'the popup was never refilled' );

	harness.leafletCalls.bindPopup.concat( harness.leafletCalls.setPopupContent ).forEach( ( call ) => {
		assert.notStrictEqual(
			typeof call.content,
			'string',
			'popup content was handed to Leaflet as a string, which Leaflet writes with innerHTML'
		);
		assert.strictEqual( call.content.nodeType, 1, 'popup content is not an element' );
	} );
} );

test( 'a name, an address and an opening-hours block that are markup arrive as text', async () => {
	const harness = await locator( {
		payload: [ store( { name: MARKUP_NAME, address: MARKUP_ADDRESS } ) ],
	} );

	harness.fetchQueue.push(
		jsonResponse(
			record( {
				name: MARKUP_NAME,
				address: MARKUP_ADDRESS,
				hours: MARKUP_HOURS,
				description: '<b>bold</b>',
			} )
		)
	);

	clickPin( harness );

	await harness.instance.pendingRecord;

	const node = popup( harness );

	// Character for character, in a text node. The harness throws on
	// innerHTML in either direction, so a path that parsed any of these would
	// have failed before reaching this line rather than passing quietly.
	assert.strictEqual( part( node, 'name' ), MARKUP_NAME );
	assert.ok( part( node, 'address' ).indexOf( MARKUP_ADDRESS ) > -1 );
	assert.strictEqual( part( node, 'hours' ), MARKUP_HOURS );
	assert.strictEqual( part( node, 'description' ), '<b>bold</b>' );

	// And no element was ever built out of any of it: the only children of the
	// popup are the ones this plugin's own code created, which are P, UL, LI
	// and A. An IMG, a SCRIPT or a B here would mean a parser ran.
	const tags = [];
	const walk = ( element ) => {
		element.children.forEach( ( child ) => {
			tags.push( child.tagName );
			walk( child );
		} );
	};

	walk( node );

	assert.ok( tags.length > 0, 'the popup has no elements at all, so this scan proves nothing' );
	assert.deepStrictEqual(
		tags.filter( ( tag ) => -1 === [ 'P', 'UL', 'LI', 'A' ].indexOf( tag ) ),
		[],
		'the popup contains an element nothing in locator.js builds: ' + tags.join( ', ' )
	);
} );

test( 'a javascript: website is shown as text and never becomes a link', async () => {
	// href is the other parser-free way to run script, and it is not covered
	// by the no-innerHTML rule at all: `a.href = 'javascript:…'` is one click
	// from execution. The url field is free text an editor typed.
	const harness = await locator( { payload: [ store() ] } );

	harness.fetchQueue.push( jsonResponse( record( { url: 'javascript:alert(1)' } ) ) );

	clickPin( harness );

	await harness.instance.pendingRecord;

	const node = popup( harness );

	assert.strictEqual( part( node, 'url' ), 'javascript:alert(1)', 'the url was dropped instead of shown' );

	// A count and a tag name, never deepStrictEqual against an empty array.
	// The harness header says why and this case is where it was proved: a
	// StubElement reaches its listeners, which reach the whole locator, so
	// structurally comparing one against `[]` walks that graph until the heap
	// is gone — and node reports "JavaScript heap out of memory" with no case
	// name in it, which is the same thing as nobody catching the mutation.
	assert.strictEqual(
		anchors( node, 'url' ).length,
		0,
		'a javascript: url was turned into a link'
	);
} );

test( 'the website check is an allowlist, not a hunt for javascript:', async () => {
	// The case above names one scheme, and a file that refused that one scheme
	// and passed everything else would satisfy it. This is the difference
	// between the rule the code implements — two schemes are allowed, the rest
	// are not — and the weaker rule one example can prove.
	//
	// `data:` is the sharp one: a data: url carrying text/html runs script in
	// the opener's origin on some browsers, and nothing about it looks like the
	// word this suite was scanning for. The protocol-relative one is a
	// different failure — it is a perfectly valid link to somebody else's
	// site, which a store's "website" field has no business being turned into
	// without a scheme somebody typed.
	const hostile = [
		'data:text/html,<script>alert(1)</script>',
		'vbscript:msgbox(1)',
		'//evil.test/x',
		'blob:https://example.test/1234',
		'JAVASCRIPT:alert(1)',
	];

	for ( const value of hostile ) {
		const harness = await locator( { payload: [ store() ] } );

		harness.fetchQueue.push( jsonResponse( record( { url: value } ) ) );

		clickPin( harness );

		await harness.instance.pendingRecord;

		const node = popup( harness );

		assert.strictEqual( part( node, 'url' ), value, value + ' was dropped instead of shown as text' );
		assert.strictEqual( anchors( node, 'url' ).length, 0, value + ' was turned into a link' );
	}
} );

test( 'an http website does become a link, which is the control for the case above', async () => {
	const harness = await locator( { payload: [ store() ] } );

	harness.fetchQueue.push( jsonResponse( record( { url: 'https://example.test/warsaw' } ) ) );

	clickPin( harness );

	await harness.instance.pendingRecord;

	const link = popup( harness ).querySelector( '.slosm__popup-url' ).children[ 0 ];

	assert.ok( link, 'the website was not rendered at all' );
	assert.strictEqual( link.tagName, 'A' );
	assert.strictEqual( link.getAttribute( 'href' ), 'https://example.test/warsaw' );
	assert.strictEqual( link.getAttribute( 'target' ), '_blank' );
	assert.strictEqual( link.getAttribute( 'rel' ), 'noopener noreferrer' );
} );

/* -------------------------------------------------------------------------
 * Open at once, fill in later
 * ---------------------------------------------------------------------- */

test( 'the popup is bound from the lean payload before anything is fetched', async () => {
	const harness = await locator( { payload: [ store() ] } );

	// No click yet, and no /stores/<id> queued. The popup exists anyway,
	// because binding it is what pinning a marker does.
	assert.strictEqual( storeRequests( harness ).length, 0 );

	const node = popup( harness );

	assert.ok( node, 'the marker has no popup' );
	assert.strictEqual( part( node, 'name' ), 'Warsaw' );
	assert.ok( part( node, 'address' ).indexOf( 'Nowy Świat 1' ) > -1 );
	assert.ok( part( node, 'address' ).indexOf( 'Warszawa' ) > -1 );

	// And nothing the lean payload does not carry is invented.
	assert.strictEqual( part( node, 'phone' ), '' );
	assert.strictEqual( part( node, 'hours' ), '' );
	assert.strictEqual( part( node, 'description' ), '' );
} );

test( 'the popup opens with what is known while the full record is still in flight', async () => {
	const harness = await locator( { payload: [ store() ] } );
	const slow = deferredResponse();

	harness.fetchQueue.push( slow );

	clickPin( harness );

	// Leaflet opened it on the click it bound itself; nothing has answered.
	assert.strictEqual( marker( harness ).isPopupOpen(), true, 'the popup did not open' );
	assert.strictEqual( storeRequests( harness ).length, 1 );
	assert.strictEqual( part( popup( harness ), 'name' ), 'Warsaw' );
	assert.strictEqual( part( popup( harness ), 'phone' ), '' );

	slow.resolve( jsonResponse( record() ) );

	await harness.instance.pendingRecord;

	assert.strictEqual( part( popup( harness ), 'phone' ), '+48 22 000 00 00' );
	assert.strictEqual( part( popup( harness ), 'hours' ), 'Mon-Fri 9-17\nSat 10-14' );
	assert.strictEqual( part( popup( harness ), 'description' ), 'The one by the roundabout.' );

	// The same node, refilled. A second node would have left Leaflet holding
	// the first one, which is the bug that looks like "the popup never
	// updates" on a site and like nothing at all in a test that only reads
	// what the plugin built.
	assert.ok(
		harness.leafletCalls.setPopupContent[ 0 ].content === harness.leafletCalls.bindPopup[ 0 ].content,
		'the refill built a second node instead of refilling the bound one'
	);
} );

test( 'the full record fills the address block out rather than replacing the lean one', async () => {
	const harness = await locator( { payload: [ store() ] } );

	harness.fetchQueue.push( jsonResponse( record() ) );

	clickPin( harness );

	await harness.instance.pendingRecord;

	const address = part( popup( harness ), 'address' );

	[ 'Nowy Świat 1', 'Second floor', 'Warszawa', 'Mazowieckie', '00-001', 'Poland' ].forEach( ( line ) => {
		assert.ok( address.indexOf( line ) > -1, 'the address block lost ' + line );
	} );
} );

test( 'the record wins over the list, because the list is the one that is cached', async () => {
	// Rest_Controller's own docblock is the reason this is not a hypothetical:
	// GET /stores is served from a cached payload and GET /stores/<id> always
	// reads storage. So a location renamed since that cache was written is
	// two different names in one browser, and the popup has to show the fresher
	// of the two rather than the one the marker was built from.
	const harness = await locator( { payload: [ store() ] } );

	harness.fetchQueue.push(
		jsonResponse( record( { name: 'Warsaw Central', address: 'Krucza 2', city: 'Warszawa-Śródmieście' } ) )
	);

	clickPin( harness );

	await harness.instance.pendingRecord;

	const node = popup( harness );

	assert.strictEqual( part( node, 'name' ), 'Warsaw Central' );
	assert.ok( part( node, 'address' ).indexOf( 'Krucza 2' ) > -1, part( node, 'address' ) );
	assert.ok( part( node, 'address' ).indexOf( 'Nowy Świat 1' ) === -1, 'the stale address is still there' );
	assert.ok( part( node, 'address' ).indexOf( 'Warszawa-Śródmieście' ) > -1 );
} );

test( 'an empty field in the record does not blank out what the list already said', async () => {
	// The other direction, and the reason the merge is a pick() rather than an
	// assignment: Store gives every absent field an empty string of its own
	// type, so a record with no address is '' and not undefined. Letting that
	// win would delete an address that is on screen and correct.
	const harness = await locator( { payload: [ store() ] } );

	harness.fetchQueue.push( jsonResponse( record( { name: '', address: '', city: '' } ) ) );

	clickPin( harness );

	await harness.instance.pendingRecord;

	assert.strictEqual( part( popup( harness ), 'name' ), 'Warsaw' );
	assert.ok( part( popup( harness ), 'address' ).indexOf( 'Nowy Świat 1' ) > -1 );
} );

test( 'the record\'s categories win over the list\'s, like its name and address', async () => {
	// The same staleness argument one field over, and the field that had no
	// case: a location recategorised since the list cache was written shows the
	// old tag on the row and has to show the new one in the popup.
	const harness = await locator( { payload: [ store( { categories: [ 'Shops' ] } ) ] } );

	assert.deepStrictEqual( categories( popup( harness ) ), [ 'Shops' ] );

	harness.fetchQueue.push( jsonResponse( record( { categories: [ 'Bakeries' ] } ) ) );

	clickPin( harness );

	await harness.instance.pendingRecord;

	assert.deepStrictEqual( categories( popup( harness ) ), [ 'Bakeries' ] );
} );

test( 'a category that is not a string is left out of the popup too', async () => {
	// renderRows() has had this check since Task 14 and a case to go with it —
	// "a payload field that is not a string leaves its cell empty". The popup
	// carries its own copy of the same loop, and a copy of a rule with no case
	// on it is a rule that holds in one of the two places it is written.
	const harness = await locator( { payload: [ store() ] } );

	harness.fetchQueue.push( jsonResponse( record( { categories: [ 'Shops', 5, {}, '', null, 'Bakeries' ] } ) ) );

	clickPin( harness );

	await harness.instance.pendingRecord;

	assert.deepStrictEqual( categories( popup( harness ) ), [ 'Shops', 'Bakeries' ] );
} );

test( 'a field that is not a string is left out rather than coerced', async () => {
	// cell()'s rule, one element over: a field that arrived as a number or an
	// object is a bug on the server, and `node.textContent = {}` writes
	// "[object Object]" into a popup in front of a visitor.
	const harness = await locator( { payload: [ store() ] } );

	harness.fetchQueue.push(
		jsonResponse( record( { phone: 12345, hours: {}, description: [ 'a', 'b' ] } ) )
	);

	clickPin( harness );

	await harness.instance.pendingRecord;

	const node = popup( harness );

	assert.strictEqual( part( node, 'phone' ), '' );
	assert.strictEqual( part( node, 'hours' ), '' );
	assert.strictEqual( part( node, 'description' ), '' );

	// The control: a record of the right shape does show all three, so the
	// three assertions above are about the type check and not about a popup
	// that never renders anything.
	assert.strictEqual( part( popup( harness ), 'email' ), 'hello@example.test' );
} );

test( 'a bare domain is shown as typed, not turned into a link to this site', async () => {
	// "example.com" in a url field is the commonest thing an editor types, and
	// resolving it against the page would produce a link to
	// https://example.test/find-us/example.com — a 404 on the site's own
	// domain, which reads as the plugin mangling the field rather than as the
	// field being incomplete.
	const harness = await locator( { payload: [ store() ] } );

	harness.fetchQueue.push( jsonResponse( record( { url: 'example.com' } ) ) );

	clickPin( harness );

	await harness.instance.pendingRecord;

	const box = popup( harness ).querySelector( '.slosm__popup-url' );

	assert.strictEqual( box.textContent, 'example.com' );
	assert.strictEqual(
		anchors( popup( harness ), 'url' ).length,
		0,
		'a relative url was resolved against the page and linked'
	);
} );

/* -------------------------------------------------------------------------
 * One request per location, counted
 * ---------------------------------------------------------------------- */

test( 'opening the same popup twice is one request, not two', async () => {
	const harness = await locator( { payload: [ store() ] } );

	harness.fetchQueue.push( jsonResponse( record() ) );

	clickPin( harness );
	await harness.instance.pendingRecord;

	assert.strictEqual( storeRequests( harness ).length, 1 );

	// Closed by the second click — Leaflet's own toggle — and opened by the
	// third. Nothing is queued for a second request, so a file that made one
	// would reject and this case would fail loudly rather than silently.
	clickPin( harness );
	clickPin( harness );

	await harness.instance.pendingRecord;

	assert.strictEqual( storeRequests( harness ).length, 1, 'the popup asked for the same location twice' );
	assert.strictEqual( part( popup( harness ), 'phone' ), '+48 22 000 00 00' );

	// And nothing was re-handed to Leaflet either: a cached record was already
	// in the node, so there is nothing to relayout.
	assert.strictEqual( harness.leafletCalls.setPopupContent.length, 1 );
} );

test( 'two different locations are two requests, which is the control for the cache', async () => {
	// Without this, "one request" is satisfied by a file that never requests
	// anything at all.
	const harness = await locator( {
		payload: [ store(), store( { id: 7, name: 'Kraków', lat: KRAKOW.lat, lng: KRAKOW.lng } ) ],
	} );

	harness.fetchQueue.push( jsonResponse( record() ) );
	harness.fetchQueue.push( jsonResponse( record( { id: 7, name: 'Kraków', phone: '+48 12 000 00 00' } ) ) );

	clickPin( harness, 0 );
	await harness.instance.pendingRecord;

	clickPin( harness, 1 );
	await harness.instance.pendingRecord;

	const asked = storeRequests( harness ).map( ( call ) => call.url );

	assert.strictEqual( asked.length, 2 );
	assert.ok( asked[ 0 ].endsWith( '/v1/stores/1' ), asked[ 0 ] );
	assert.ok( asked[ 1 ].endsWith( '/v1/stores/7' ), asked[ 1 ] );
	assert.strictEqual( part( popup( harness, 1 ), 'phone' ), '+48 12 000 00 00' );
} );

test( 'the cache survives a redraw, so a category change does not re-ask', async () => {
	// The cache is on the instance and not on the entry, and this is the
	// difference: every entry, marker and popup node is thrown away and
	// rebuilt on each draw. A cache hanging off an entry would be a cache
	// that is empty exactly when somebody comes back to a pin they just read.
	const harness = await locator( {
		payload: [ store() ],
		config: defaultConfig( { radius: 500 } ),
	} );

	harness.fetchQueue.push( jsonResponse( record() ) );

	clickPin( harness, 0 );
	await harness.instance.pendingRecord;

	const select = harness.container.querySelector( '.slosm__category' );

	select.value = 'Shops';
	fire( select, 'change' );

	await harness.instance.pendingSearch;

	// A second marker: the redraw built a new one for the same location.
	assert.ok( harness.leafletCalls.marker.length > 1, 'the locator never redrew' );

	// And its popup already carries the full record, without asking again.
	assert.strictEqual( part( popup( harness, harness.leafletCalls.marker.length - 1 ), 'phone' ), '+48 22 000 00 00' );
	assert.strictEqual( storeRequests( harness ).length, 1 );
} );

test( 'two opens while one request is in flight are still one request', async () => {
	const harness = await locator( { payload: [ store() ] } );
	const slow = deferredResponse();

	harness.fetchQueue.push( slow );

	clickPin( harness );
	clickPin( harness );
	clickPin( harness );

	assert.strictEqual( storeRequests( harness ).length, 1, 'the popup asked again before the first answer landed' );

	slow.resolve( jsonResponse( record() ) );

	await harness.instance.pendingRecord;

	assert.strictEqual( part( popup( harness ), 'phone' ), '+48 22 000 00 00' );
} );

/* -------------------------------------------------------------------------
 * A failure takes nothing away
 * ---------------------------------------------------------------------- */

test( 'a failed record fetch leaves the partial popup, the rows and the map alone', async () => {
	const harness = await locator( { payload: [ store() ] } );

	harness.fetchQueue.push( jsonResponse( { code: 'slosm_store_not_found' }, { ok: false, status: 404 } ) );

	clickPin( harness );

	await harness.instance.pendingRecord;

	const node = popup( harness );

	// Everything that was on screen before the click is still on screen.
	assert.strictEqual( part( node, 'name' ), 'Warsaw' );
	assert.ok( part( node, 'address' ).indexOf( 'Nowy Świat 1' ) > -1 );
	assert.strictEqual( rows( harness.container ).length, 1 );
	assert.strictEqual( message( harness.container ), null, 'a failed popup fetch put a sentence on the page' );
	assert.strictEqual( harness.container.classList.contains( 'slosm--error' ), false );

	// The developer hears about it and the visitor does not.
	assert.strictEqual( harness.consoleCalls.warn.length, 1 );
	assert.ok( String( harness.consoleCalls.warn[ 0 ][ 0 ] ).indexOf( '404' ) > -1, harness.consoleCalls.warn[ 0 ][ 0 ] );
} );

test( 'a record that is not an object is a failure rather than a popup full of nothing', async () => {
	const harness = await locator( { payload: [ store() ] } );

	harness.fetchQueue.push( jsonResponse( [ 'not', 'a', 'location' ] ) );

	clickPin( harness );

	await harness.instance.pendingRecord;

	assert.strictEqual( part( popup( harness ), 'name' ), 'Warsaw' );
	assert.strictEqual( harness.consoleCalls.warn.length, 1 );
	assert.ok( String( harness.consoleCalls.warn[ 0 ][ 0 ] ).indexOf( 'did not answer with a location' ) > -1 );
} );

test( 'a failure is not cached: the next open asks again', async () => {
	// The other half of the cache, and the one that is easy to get wrong. A
	// transient 503 that poisoned the cache would leave that pin partial for
	// as long as the page is open.
	const harness = await locator( { payload: [ store() ] } );

	harness.fetchQueue.push( jsonResponse( {}, { ok: false, status: 503 } ) );
	harness.fetchQueue.push( jsonResponse( record() ) );

	clickPin( harness );
	await harness.instance.pendingRecord;

	assert.strictEqual( part( popup( harness ), 'phone' ), '' );

	// Closed, then opened again.
	clickPin( harness );
	clickPin( harness );
	await harness.instance.pendingRecord;

	assert.strictEqual( storeRequests( harness ).length, 2 );
	assert.strictEqual( part( popup( harness ), 'phone' ), '+48 22 000 00 00' );
} );

test( 'a record with no coordinates is still a popup, and still has a way to it', async () => {
	// Rest_Controller::get_item_schema() types lat and lng as number-or-null
	// because an address an editor has not geocoded yet is an ordinary state.
	// The marker cannot be one of those — a location with no coordinates never
	// gets a pin — but the record behind it can come back that way, and code
	// that rebuilt the destination out of the record would produce a link to
	// "null,null".
	const harness = await locator( { payload: [ store() ] } );

	harness.fetchQueue.push( jsonResponse( record( { lat: null, lng: null } ) ) );

	clickPin( harness );

	await harness.instance.pendingRecord;

	const node = popup( harness );

	assert.strictEqual( part( node, 'phone' ), '+48 22 000 00 00' );

	const href = node.querySelector( '.slosm__popup-directions' ).getAttribute( 'href' );

	assert.ok( href.indexOf( 'null' ) === -1, href );
	assert.ok( href.indexOf( String( WARSAW.lat ) ) > -1, href );
} );

/* -------------------------------------------------------------------------
 * Which id, and whether to ask at all
 * ---------------------------------------------------------------------- */

test( 'the id goes into the route where __ID__ was', async () => {
	const harness = await locator( { payload: [ store( { id: 42 } ) ] } );

	harness.fetchQueue.push( jsonResponse( record( { id: 42 } ) ) );

	clickPin( harness );

	await harness.instance.pendingRecord;

	assert.strictEqual( storeRequests( harness )[ 0 ].url, 'https://example.test/wp-json/slosm/v1/stores/42' );
	assert.strictEqual( storeRequests( harness )[ 0 ].init.credentials, 'same-origin' );

	// And the placeholder really was a placeholder: the route the config
	// carries still has it in, so this case is about a substitution rather
	// than about a url that happened to be right.
	assert.ok( harness.instance.config.routes.store.indexOf( '__ID__' ) > -1 );
} );

test( 'the route survives plain permalinks, where it is a query parameter', async () => {
	// get_rest_url() takes its other branch on a site that has never visited
	// Settings > Permalinks, and Shortcode::routes() ships __ID__ rather than
	// %d precisely so that this substitution is a string replacement and not a
	// percent escape. A front end that built '/stores/' + id by hand would
	// produce nonsense here.
	const harness = await locator( {
		payload: [ store( { id: 9 } ) ],
		config: defaultConfig( {
			radius: 500,
			routes: {
				stores: 'https://example.test/index.php?rest_route=/slosm/v1/stores',
				store: 'https://example.test/index.php?rest_route=/slosm/v1/stores/__ID__',
				geocode: 'https://example.test/index.php?rest_route=/slosm/v1/geocode',
				suggest: 'https://example.test/index.php?rest_route=/slosm/v1/suggest',
			},
		} ),
	} );

	harness.fetchQueue.push( jsonResponse( record( { id: 9 } ) ) );

	clickPin( harness );

	await harness.instance.pendingRecord;

	const asked = harness.fetchCalls[ harness.fetchCalls.length - 1 ].url;

	assert.ok( asked.indexOf( '__ID__' ) === -1, asked );
	assert.ok( asked.indexOf( 'stores%2F9' ) > -1 || asked.indexOf( 'stores/9' ) > -1, asked );
} );

test( 'an id that is not a post id is never asked for', async () => {
	// Nothing is queued, so a request made here rejects with the harness's own
	// "nothing queued" error and the case fails.
	const harness = await locator( {
		payload: [
			store( { id: 0 } ),
			store( { id: 1.5, lat: KRAKOW.lat, lng: KRAKOW.lng } ),
			store( { id: '3', lat: 51.7592, lng: 19.456 } ),
			store( { id: null, lat: 54.352, lng: 18.6466 } ),
		],
	} );

	[ 0, 1, 2, 3 ].forEach( ( at ) => clickPin( harness, at ) );

	await harness.instance.pendingRecord;

	assert.strictEqual( storeRequests( harness ).length, 0 );

	// The popups are still there and still carry what the payload had, which
	// is the point: an unusable id costs the extra fields, not the popup.
	assert.strictEqual( part( popup( harness, 0 ), 'name' ), 'Warsaw' );

	// The *last* pin pressed is the one still open, not the first. Four
	// presses are four opens, and opening a popup closes whichever one was
	// open — `Popup.options.autoClose` is true by default and `Map.openPopup`
	// begins with `this.closePopup()`. The harness models that ordering as of
	// Task 34; before it, this line asserted `marker( harness, 0 )` and passed,
	// which was a fact about the stub rather than about a browser.
	assert.strictEqual( marker( harness, 3 ).isPopupOpen(), true );
	assert.strictEqual( marker( harness, 0 ).isPopupOpen(), false );
} );

test( 'an id json overflowed into Infinity is not a post id either', async () => {
	// The case that separates storeId()'s finiteNumber() from the two checks
	// beside it, and it is a real value rather than a contrivance: JSON has no
	// bound on a number literal, so `{"id":1e999}` comes out of JSON.parse as
	// Infinity. Without finiteNumber, `Infinity < 1` is false and
	// `Infinity !== Math.floor( Infinity )` is false too, so the guard would
	// pass it through and this locator would request /stores/Infinity.
	const harness = await locator( { payload: [ store( { id: Infinity } ) ] } );

	clickPin( harness );

	await harness.instance.pendingRecord;

	assert.strictEqual( storeRequests( harness ).length, 0 );
	assert.strictEqual( part( popup( harness ), 'name' ), 'Warsaw' );

	// The control on the fixture rather than on the code: a payload that really
	// does carry Infinity is what JSON.parse produces here, so the case is
	// about a value that can arrive and not about one invented for it.
	assert.strictEqual( JSON.parse( '{"id":1e999}' ).id, Infinity );
} );

test( 'a config with no store route asks for nothing and throws nothing', async () => {
	const config = defaultConfig( { radius: 500 } );

	delete config.routes.store;

	const harness = await locator( { payload: [ store() ], config } );

	clickPin( harness );

	await harness.instance.pendingRecord;

	assert.strictEqual( storeRequests( harness ).length, 0 );
	assert.strictEqual( part( popup( harness ), 'name' ), 'Warsaw' );
} );

/* -------------------------------------------------------------------------
 * The row's open button
 * ---------------------------------------------------------------------- */

test( 'the row\'s open button opens that row\'s pin, and asks for its record', async () => {
	const harness = await locator( {
		payload: [ store(), store( { id: 7, name: 'Kraków', lat: KRAKOW.lat, lng: KRAKOW.lng } ) ],
	} );

	harness.fetchQueue.push( jsonResponse( record( { id: 7, name: 'Kraków', phone: '+48 12 000 00 00' } ) ) );

	const opener = rows( harness.container )[ 1 ].querySelector( '.slosm__result-open' );

	fire( opener, 'click' );

	await harness.instance.pendingRecord;

	assert.strictEqual( marker( harness, 1 ).isPopupOpen(), true, 'the second row opened nothing' );
	assert.strictEqual( marker( harness, 0 ).isPopupOpen(), false, 'the wrong pin opened' );
	assert.strictEqual( part( popup( harness, 1 ), 'phone' ), '+48 12 000 00 00' );
} );

test( 'a clustered pin is let out of its bubble before its popup opens', async () => {
	// markercluster's own zoomToShowLayer, verified in the vendored file:
	// a marker inside a bubble is not on the map, so openPopup() on it would
	// open nothing. The harness reproduces only the synchronous branch and a
	// case drives the rest; see its header.
	const harness = await locator( {
		payload: [ store() ],
		config: defaultConfig( { radius: 500, cluster: true } ),
	} );

	harness.fetchQueue.push( jsonResponse( record() ) );

	const opener = rows( harness.container )[ 0 ].querySelector( '.slosm__result-open' );

	fire( opener, 'click' );

	assert.strictEqual( harness.leafletCalls.clusterZoom.length, 1, 'the row opened a clustered pin directly' );
	assert.ok( harness.leafletCalls.clusterZoom[ 0 ].marker === marker( harness ), 'the wrong marker was revealed' );

	// Nothing has come out of the bubble, so the callback has not run.
	assert.strictEqual( marker( harness ).isPopupOpen(), false );

	harness.leafletCalls.clusterZoom[ 0 ].group.uncluster( marker( harness ) );
	harness.leafletCalls.clusterZoom[ 0 ].callback();

	assert.strictEqual( marker( harness ).isPopupOpen(), true );

	await harness.instance.pendingRecord;

	assert.strictEqual( part( popup( harness ), 'phone' ), '+48 22 000 00 00' );
} );

/* -------------------------------------------------------------------------
 * Directions
 * ---------------------------------------------------------------------- */

test( 'the directions link points at openstreetmap.org with the destination in it', async () => {
	const harness = await locator( { payload: [ store() ] } );

	const href = rows( harness.container )[ 0 ]
		.querySelector( '.slosm__result-directions' )
		.getAttribute( 'href' );

	const parsed = new URL( href );

	assert.strictEqual( parsed.origin, 'https://www.openstreetmap.org' );
	assert.strictEqual( parsed.pathname, '/directions' );

	// The one parameter, in the one format, with an empty "from": nobody has
	// said where the visitor is.
	assert.strictEqual( parsed.searchParams.get( 'route' ), ';' + WARSAW.lat + ',' + WARSAW.lng );
} );

test( 'the same link is in the popup, and it is the same url', async () => {
	const harness = await locator( { payload: [ store() ] } );

	const inRow = rows( harness.container )[ 0 ]
		.querySelector( '.slosm__result-directions' )
		.getAttribute( 'href' );
	const link = popup( harness ).querySelector( '.slosm__popup-directions' );

	assert.ok( link, 'the popup has no way out to a map' );
	assert.strictEqual( link.getAttribute( 'href' ), inRow );

	// A third-party site, opened beside the locator rather than on top of it,
	// with no window.opener and no Referer. The row's anchor carries the same
	// two attributes from Shortcode::row_template(); this one is built here
	// and has to say so itself.
	assert.strictEqual( link.getAttribute( 'target' ), '_blank' );
	assert.strictEqual( link.getAttribute( 'rel' ), 'noopener noreferrer' );
	assert.strictEqual( link.textContent, 'Directions' );
} );

test( 'the label is the translated one when the site sent a translation', async () => {
	const harness = await locator( {
		payload: [ store() ],
		strings: defaultStrings( { directions: 'Dojazd' } ),
	} );

	assert.strictEqual( popup( harness ).querySelector( '.slosm__popup-directions' ).textContent, 'Dojazd' );
} );

test( 'once the visitor has shared a location the link routes from it', async () => {
	const harness = await locator( { payload: [ store() ] } );

	harness.geolocationQueue.push( position( 52.4, 20.9 ) );

	fire( harness.container.querySelector( '.slosm__locate' ), 'click' );

	await harness.instance.pendingSearch;

	const href = rows( harness.container )[ 0 ]
		.querySelector( '.slosm__result-directions' )
		.getAttribute( 'href' );

	assert.strictEqual(
		new URL( href ).searchParams.get( 'route' ),
		'52.4,20.9;' + WARSAW.lat + ',' + WARSAW.lng
	);
} );

test( 'the coordinate in the link is the coarsened one, not the device\'s own', async () => {
	// COORD_PLACES rounds a device position to four decimals — about eleven
	// metres — before this file will handle it at all. A directions url is
	// handed to a third party, so this is the one place where that rounding
	// leaves the site entirely, and a link carrying the raw fix would be a
	// rooftop-accurate position in openstreetmap.org's logs.
	const harness = await locator( { payload: [ store() ] } );

	harness.geolocationQueue.push( position( 52.40008888888, 20.90001234567 ) );

	fire( harness.container.querySelector( '.slosm__locate' ), 'click' );

	await harness.instance.pendingSearch;

	const route = new URL(
		rows( harness.container )[ 0 ].querySelector( '.slosm__result-directions' ).getAttribute( 'href' )
	).searchParams.get( 'route' );

	assert.strictEqual( route.split( ';' )[ 0 ], '52.4001,20.9' );
	assert.ok( route.indexOf( '8888' ) === -1, route );
	assert.ok( route.indexOf( '1234' ) === -1, route );
} );

test( 'a searched address is a from as well, because it is where they are looking', async () => {
	const harness = await locator( { payload: [ store() ] } );

	harness.fetchQueue.push( jsonResponse( { lat: 52.4, lng: 20.9 } ) );

	const input = harness.container.querySelector( '.slosm__search' );

	input.value = 'Warszawa';
	fire( input, 'keydown', { key: 'Enter' } );

	await harness.instance.pendingSearch;

	assert.strictEqual(
		new URL(
			rows( harness.container )[ 0 ].querySelector( '.slosm__result-directions' ).getAttribute( 'href' )
		).searchParams.get( 'route' ),
		'52.4,20.9;' + WARSAW.lat + ',' + WARSAW.lng
	);
} );

test( 'a geocoded address is coarsened on the way into the link, not on the way in', async () => {
	// The second origin, and the one the first version of this file missed.
	// Geocoder runs on the server, so Nominatim's answer reaches the browser at
	// whatever precision it was given — ten or more digits for a rooftop match
	// — and COORD_PLACES, which covers the device path, is nowhere near it.
	//
	// It is the more sensitive of the two disclosures rather than the less:
	// until this link is clicked, the visitor's browser has told
	// openstreetmap.org nothing at all, and an address typed into a store
	// locator is very often the visitor's own home.
	//
	// The destination carries more than four decimals on purpose. Every other
	// fixture in this file uses city coordinates that already have exactly
	// four, so a file that coarsened the destination too would pass every one
	// of them — which it did, until a mutation sweep said so.
	const harness = await locator( { payload: [ store( { lat: 52.2297123, lng: 21.0122456 } ) ] } );

	harness.fetchQueue.push( jsonResponse( { lat: 52.40641234567, lng: 16.92518888888 } ) );

	const input = harness.container.querySelector( '.slosm__search' );

	input.value = 'Poznań';
	fire( input, 'keydown', { key: 'Enter' } );

	await harness.instance.pendingSearch;

	const route = new URL(
		rows( harness.container )[ 0 ].querySelector( '.slosm__result-directions' ).getAttribute( 'href' )
	).searchParams.get( 'route' );

	assert.strictEqual( route.split( ';' )[ 0 ], '52.4064,16.9252' );
	assert.ok( route.indexOf( '1234' ) === -1, route );
	assert.ok( route.indexOf( '8888' ) === -1, route );

	// And the other half, which is why the rounding is at the url and not at
	// the source: instance.origin keeps every digit. The browser sorts a
	// preloaded list with Geo.distance() from this point, and the server sorts
	// from the same one, and tests/js/geo-crosscheck.test.js guarantees the two
	// arithmetics agree exactly. Rounding on the way in would put that out of
	// reach for the sake of a url.
	assert.strictEqual( harness.instance.origin[ 0 ], 52.40641234567 );
	assert.strictEqual( harness.instance.origin[ 1 ], 16.92518888888 );

	// The destination is not rounded, and must not be: it is a shop's own
	// published address, not a fact about a person, and eleven metres of slack
	// on the end of a route is a pin in the road outside.
	assert.strictEqual( route.split( ';' )[ 1 ], '52.2297123,21.0122456' );
} );

test( 'a locator with no row template still gets its popups and its links', async () => {
	// A page builder or a content filter that ate the <template>. renderRows()
	// already gives up quietly on that; the popup must not depend on it.
	const harness = await locator( { payload: [ store() ], withTemplate: false } );

	assert.strictEqual( rows( harness.container ).length, 0 );
	assert.ok( popup( harness ).querySelector( '.slosm__popup-directions' ).getAttribute( 'href' ) );
} );

/* -------------------------------------------------------------------------
 * Discipline: Task 16's own surface, and nothing from Stage 4
 * ---------------------------------------------------------------------- */

test( 'Task 16 built its own things and none of Stage 4\'s', async () => {
	const source = codeOnly( pluginSource( 'assets', 'js', 'locator.js' ) );

	// The present half of the swap. tests/js/search.test.js and
	// tests/js/nearby.test.js forbade every name below while it belonged to
	// this task; they are pinned from this direction now, so deleting the
	// popup does not silently satisfy a scan somewhere else.
	[
		'bindPopup',
		'openPopup',
		'setPopupContent',
		'result-directions',
		'/directions',
		'zoomToShowLayer',
		'__ID__',
	].forEach( ( expected ) => {
		assert.ok( source.includes( expected ), 'locator.js does not contain ' + expected + ', so a scan for what is absent proves nothing' );
	} );

	// Still nobody's. Everything admin-side is Stage 4: a front end that read a
	// setting, a nonce or an edit link would be shipping half of it early.
	//
	// slosm__radius and slosm__limit were on this list until Task 29a wired the
	// two selects to the search. They are pinned from the other direction in
	// tests/js/filters.test.js, which drives both controls and asserts on what
	// the search then does, rather than deleted from the record here.
	[
		'bindTooltip',
		'routing',
		'wp-admin',
		'_wpnonce',
		'slosm_settings',
	].forEach( ( forbidden ) => {
		assert.ok( ! source.includes( forbidden ), 'locator.js reaches for ' + forbidden + ', which is not this task\'s' );
	} );

	// The control for the forbidden list, which is otherwise a set of strings
	// nobody has checked can match anything: the scan is over real code, and
	// these two names are the shape the forbidden ones are written in.
	assert.ok( source.includes( 'slosm__category' ) );
	assert.ok( source.includes( 'slosm__result-open' ) );
} );

test( 'the default directions host is one this plugin already trusted, and carries no key', async () => {
	// The whole reason this plugin exists is that a locator should need no
	// Google Maps key, and the *default* link out keeps that true: same
	// project, same host family as the tiles, no account, no third party.
	//
	// This case used to assert that the word "google" was nowhere in the file
	// at all. Task 21 made the target a setting, because a great many visitors
	// are already in Google Maps and a link that opens the app they have beats
	// one that does not — and refusing to send them there is a decision for the
	// site rather than for this file. What survives, and is what the assertion
	// was really for, is that no key and no account are needed either way and
	// that the default is unchanged.
	const source = pluginSource( 'assets', 'js', 'locator.js' );
	const harness = await locator( { payload: [ store() ] } );

	assert.ok( source.includes( 'https://www.openstreetmap.org/directions' ) );
	assert.ok( ! source.includes( 'apiKey' ) && ! source.includes( 'api_key' ) );

	// A key is what a *url* would carry, and the urls this file builds are the
	// place to check for one. `api=1` is Google's own version marker and is
	// not a credential; anything named key, token or client would be.
	const href = popup( harness ).querySelector( '.slosm__popup-directions' ).getAttribute( 'href' );

	assert.ok( href.startsWith( 'https://www.openstreetmap.org/directions?' ), href );
	assert.ok( ! /[?&](key|token|client|appid)=/i.test( href ), href );
} );

test( 'a site can send visitors to Google Maps, or nowhere at all', async () => {
	const google = await locator( {
		payload: [ store() ],
		config: defaultConfig( { radius: 500, directions: 'google' } ),
	} );

	const href = popup( google ).querySelector( '.slosm__popup-directions' ).getAttribute( 'href' );

	// GOOGLE_DIRECTIONS is labelled in locator.js as a guess rather than a
	// measurement, and this case cannot promote it: it asserts the shape this
	// plugin builds, not that Google honours it. The url has to be opened in a
	// browser once, the way DIRECTIONS was, before anybody believes it.
	assert.ok( href.startsWith( 'https://www.google.com/maps/dir/?' ), href );
	assert.ok( href.includes( 'api=1' ), href );
	assert.strictEqual(
		new URL( href ).searchParams.get( 'destination' ),
		WARSAW.lat + ',' + WARSAW.lng
	);
	assert.ok( ! /[?&](key|token|client|appid)=/i.test( href ), href );

	// No origin until there has been a search, the same rule the OpenStreetMap
	// url follows: url() skips an empty parameter, so nothing about a visitor
	// is in a url they have not asked to be routed from.
	assert.strictEqual( new URL( href ).searchParams.get( 'origin' ), null );

	// And once there has been one, the origin is rounded to COORD_PLACES before
	// it goes into a third party's url — the same rounding the OpenStreetMap
	// branch applies, and the only place in this plugin where a point about a
	// *person* leaves the site at all. directionsUrl() has the whole argument.
	//
	// A *geocoded* origin and not a device one, which is the difference between
	// this case and the first version of it: onLocated() already rounds a device
	// position at the source, so a "use my location" fixture cannot tell the
	// rounding here from no rounding at all — a mutation sweep said so. An
	// address typed into the field arrives at whatever precision Nominatim
	// answered with, and is the more sensitive of the two anyway.
	const typed = await locator( {
		payload: [ store() ],
		config: defaultConfig( { radius: 500, directions: 'google' } ),
	} );

	typed.fetchQueue.push( jsonResponse( { lat: 52.40641234567, lng: 16.92518888888 } ) );

	const field = typed.container.querySelector( '.slosm__search' );

	field.value = 'Poznań';
	fire( field, 'keydown', { key: 'Enter' } );

	await typed.instance.pendingSearch;

	const routed = rows( typed.container )[ 0 ]
		.querySelector( '.slosm__result-directions' )
		.getAttribute( 'href' );

	assert.strictEqual( new URL( routed ).searchParams.get( 'origin' ), '52.4064,16.9252' );

	// And "no link" means no anchor rather than an anchor with no href, which
	// would resolve to the page it is on and lose the search.
	const none = await locator( {
		payload: [ store() ],
		config: defaultConfig( { radius: 500, directions: 'none' } ),
	} );

	assert.strictEqual( popup( none ).querySelector( '.slosm__popup-directions' ), null );
	assert.strictEqual( rows( none.container )[ 0 ].querySelector( '.slosm__result-directions' ), null );

	// The control: the same fixture with the default target has both.
	const both = await locator( { payload: [ store() ] } );

	assert.ok( popup( both ).querySelector( '.slosm__popup-directions' ) );
	assert.ok( rows( both.container )[ 0 ].querySelector( '.slosm__result-directions' ) );
} );

/* -------------------------------------------------------------------------
 * Task 21: what a site publishes in a bubble
 * ---------------------------------------------------------------------- */

test( 'a popup shows the fields the site chose, and nothing it left off', async () => {
	// The point of the list is the absence rather than the presence: a
	// location's phone number and email are stored whether or not a site wants
	// them published on a public map, and empty values are skipped already —
	// so this is not a tidiness control, it is what the site is publishing.
	const harness = await locator( {
		payload: [ store() ],
		config: defaultConfig( {
			radius: 500,
			popup: [ 'name', 'address', 'phone' ],
		} ),
	} );

	harness.fetchQueue.push( jsonResponse( record() ) );

	clickPin( harness );

	await harness.instance.pendingRecord;

	const node = popup( harness );

	assert.strictEqual( part( node, 'name' ), 'Warsaw' );
	assert.ok( part( node, 'address' ).indexOf( 'Nowy Świat 1' ) > -1 );
	assert.strictEqual( part( node, 'phone' ), '+48 22 000 00 00' );

	// Every one of these is in the record that just arrived, so a case that
	// found them absent for any other reason would be finding the wrong thing.
	// The opening hours are among them deliberately: a list that left them ON
	// could not tell the gate on them from no gate at all.
	assert.strictEqual( node.querySelector( '.slosm__popup-hours' ), null );
	assert.strictEqual( node.querySelector( '.slosm__popup-email' ), null );
	assert.strictEqual( node.querySelector( '.slosm__popup-url' ), null );
	assert.strictEqual( node.querySelector( '.slosm__popup-description' ), null );
	assert.strictEqual( node.querySelector( '.slosm__popup-categories' ), null );

	// The way out is not one of the eight and is not affected by the list.
	assert.ok( node.querySelector( '.slosm__popup-directions' ) );
} );

test( 'the control: the same record with the default list shows all eight', async () => {
	const harness = await locator( { payload: [ store() ] } );

	harness.fetchQueue.push( jsonResponse( record() ) );

	clickPin( harness );

	await harness.instance.pendingRecord;

	const node = popup( harness );

	[ 'name', 'address', 'phone', 'email', 'url', 'hours', 'description' ].forEach( ( field ) => {
		assert.ok( node.querySelector( '.slosm__popup-' + field ), field + ' is missing by default' );
	} );

	assert.ok( node.querySelector( '.slosm__popup-categories' ) );

	// And the newlines in the hours survive as newlines, which is what
	// `white-space: pre-line` on .slosm__popup-hours is for and is the reason
	// there is no "opening-hours format" setting: the only decision free text
	// offers has one right answer, and it was already made in Task 16.
	assert.ok( part( node, 'hours' ).indexOf( '\n' ) > -1, 'the line break was collapsed' );
} );

/* -------------------------------------------------------------------------
 * Task 24b: the popup a keyboard can reach, and a close button that speaks
 * the site's language
 * ---------------------------------------------------------------------- */

test( 'opening a popup from a result row moves the focus into it', async () => {
	// The defect, from the file's own notes: Leaflet calls focus() nowhere —
	// the only one in the vendored build is _refocusOnMap, on the zoom and
	// layers controls — and Shortcode::render() emits the map *before* the
	// results list, so pressing a row's button left the focus on the button
	// with the popup somewhere behind it in the tab order. A keyboard user had
	// to shift-tab backwards past the whole map to read what they opened.
	const harness = await locator( { payload: [ store() ] } );
	const opener = harness.container.querySelector( '.slosm__result-open' );

	opener.focus();
	fire( opener, 'click' );

	const bubble = marker( harness ).getPopup();
	const doc = harness.container.ownerDocument;

	assert.ok( bubble.isOpen(), 'the row button opened nothing' );

	// assert.ok over an identity check rather than assert.strictEqual, and
	// this is a trap worth leaving written down: two *different* stub nodes
	// handed to strictEqual make node build a diff of both object graphs, and
	// a StubElement's graph is cyclic and reaches the whole document — the
	// runner dies of an out-of-memory in the failure path rather than printing
	// a failure. Every node comparison in this file is an identity check with
	// a sentence of its own.
	assert.ok(
		doc.activeElement === bubble.getElement().querySelector( '.leaflet-popup-content' ),
		'the focus stayed outside the popup that had just opened'
	);

	// Programmatically focusable and not in the tab sequence: the popup is
	// somewhere the focus is *sent*, not a stop on the way through the page.
	assert.strictEqual(
		bubble.getElement().querySelector( '.leaflet-popup-content' ).getAttribute( 'tabindex' ),
		'-1'
	);
} );

test( 'the close button says what it does in the language the site was built in', async () => {
	// Leaflet hardcodes it: `i.setAttribute("aria-label","Close popup")` in
	// assets/leaflet/leaflet.js, in English, with no translation reaching it.
	// The only way to answer that is to reach into its DOM once the popup has
	// been built, which is a decision rather than a tidy-up and is why this
	// case names the string it expects.
	// Driven with a translated payload rather than the default one, because on
	// an English site this plugin's own string and the one Leaflet hardcodes
	// are the same three words — a case against the default could not tell a
	// replacement from a no-op.
	const harness = await locator( {
		payload: [ store() ],
		strings: Object.assign( {}, defaultStrings(), { closePopup: 'Zamknij dymek' } ),
	} );
	const opener = harness.container.querySelector( '.slosm__result-open' );

	fire( opener, 'click' );

	const button = marker( harness ).getPopup().getElement().querySelector( '.leaflet-popup-close-button' );

	assert.strictEqual( button.getAttribute( 'aria-label' ), 'Zamknij dymek' );

	// And the role Leaflet put there is left alone: this reaches in for one
	// attribute, not for the element.
	assert.strictEqual( button.getAttribute( 'role' ), 'button' );
} );

test( 'the row button says that it opened something, and what', async () => {
	const harness = await locator( { payload: [ store() ] } );
	const opener = harness.container.querySelector( '.slosm__result-open' );

	// Closed: it says so, and points at nothing. aria-controls naming an id
	// that is not in the document is worse than no aria-controls at all, and
	// Leaflet builds the popup's element when it opens.
	assert.strictEqual( opener.getAttribute( 'aria-expanded' ), 'false' );
	assert.strictEqual( opener.getAttribute( 'aria-controls' ), null );

	fire( opener, 'click' );

	const element = marker( harness ).getPopup().getElement();

	assert.strictEqual( opener.getAttribute( 'aria-expanded' ), 'true' );
	assert.ok( element.getAttribute( 'id' ), 'the popup has no id for aria-controls to name' );
	assert.strictEqual( opener.getAttribute( 'aria-controls' ), element.getAttribute( 'id' ) );
} );

test( 'closing the popup gives the focus back to the button that opened it', async () => {
	const harness = await locator( { payload: [ store() ] } );
	const opener = harness.container.querySelector( '.slosm__result-open' );
	const doc = harness.container.ownerDocument;

	fire( opener, 'click' );
	marker( harness ).closePopup();

	assert.strictEqual( opener.getAttribute( 'aria-expanded' ), 'false' );
	assert.strictEqual( opener.getAttribute( 'aria-controls' ), null, 'the button still names an element that is gone' );
	assert.ok( doc.activeElement === opener, 'the focus was left on a popup that is no longer there' );
} );

test( 'a popup opened from the pin itself leaves every row button saying it opened nothing', async () => {
	// The other door into the same bubble. No button was pressed, so none of
	// them may claim to be expanded — and a stale aria-expanded="true" from an
	// earlier row press is exactly what a naive implementation leaves behind.
	const harness = await locator( { payload: [ store() ] } );
	const opener = harness.container.querySelector( '.slosm__result-open' );
	const pin = marker( harness );

	fire( opener, 'click' );
	pin.closePopup();
	pin.fire( 'click' );

	assert.ok( pin.getPopup().isOpen(), 'the pin opened nothing' );
	assert.strictEqual( opener.getAttribute( 'aria-expanded' ), 'false' );
	assert.strictEqual( opener.getAttribute( 'aria-controls' ), null );
} );

test( 'a visitor who moved on keeps the focus they moved to', async () => {
	// The other half of giving the focus back, and the half a sweep found
	// missing. Pulling it back is right when it is still in the popup that has
	// just closed; it is wrong when somebody has clicked or tabbed somewhere
	// deliberately, because then this script is taking the focus off whatever
	// they chose.
	const harness = await locator( { payload: [ store() ] } );
	const opener = harness.container.querySelector( '.slosm__result-open' );
	const field = harness.container.querySelector( '.slosm__search' );
	const doc = harness.container.ownerDocument;

	fire( opener, 'click' );

	field.focus();

	marker( harness ).closePopup();

	assert.ok( doc.activeElement === field, 'the close dragged the focus off what the visitor had moved to' );

	// And the button still stops claiming to be expanded: where the focus is
	// and what the button says are two questions.
	assert.strictEqual( opener.getAttribute( 'aria-expanded' ), 'false' );
} );

test( 'the vendored Leaflet still hardcodes the English close label this replaces', () => {
	// wirePopup() writes over an attribute somebody else set, which is only
	// worth doing while they are still setting it. A Leaflet that starts
	// translating its own label, or renames the class the button carries,
	// turns this plugin's replacement into either a no-op or a lie — and
	// nothing in a behaviour case would notice, because the replacement would
	// go on succeeding against a stub that models the old Leaflet.
	//
	// So both are pinned: the library and the fixture that stands in for it.
	const leaflet = pluginSource( 'assets', 'leaflet', 'leaflet.js' );

	assert.ok(
		leaflet.includes( 'setAttribute("aria-label","Close popup")' ),
		'Leaflet no longer hardcodes the close label; wirePopup() is writing over something else now'
	);
	assert.ok(
		leaflet.includes( '-close-button' ),
		'the close button class changed; the selector wirePopup() uses is stale'
	);

	const harness = pluginSource( 'tests', 'js', 'harness.js' );

	assert.ok(
		harness.includes( "this.closeButton.setAttribute( 'aria-label', 'Close popup' );" ),
		'the fixture stopped modelling the English label, so every case about replacing it proves nothing'
	);
} );

test( 'Escape inside the popup closes it', async () => {
	// Found on a real page, not here: the Escape the list promised does
	// nothing, and the reason is the focus this plugin itself moves. Leaflet's
	// Escape lives in its Keyboard handler, which binds its keydown on *focus*
	// of the map container and unbinds it on blur —
	// `t.tabIndex<=0&&(t.tabIndex="0"),S(t,{focus:this._onFocus,blur:this._onBlur…})`
	// in assets/leaflet/leaflet.js, with `this._map.on({focus:this._addHooks,
	// blur:this._removeHooks})`. Sending the focus into the popup blurs the
	// container, so Task 24b's focus move switched Leaflet's own Escape off.
	//
	// Which makes the way out this plugin's to own: it took the focus, so it
	// answers for the key that gives it back.
	const harness = await locator( { payload: [ store() ] } );
	const opener = harness.container.querySelector( '.slosm__result-open' );
	const doc = harness.container.ownerDocument;

	fire( opener, 'click' );

	const bubble = marker( harness ).getPopup();

	assert.ok( bubble.isOpen(), 'the row button opened nothing, so there is no Escape to test' );

	fire( bubble.getElement().querySelector( '.leaflet-popup-content' ), 'keydown', { key: 'Escape' } );

	assert.ok( ! bubble.isOpen(), 'Escape left the popup open' );

	// And everything a close already owed is still owed: Escape is another
	// door into the same close, not a second kind of closing.
	assert.strictEqual( opener.getAttribute( 'aria-expanded' ), 'false' );
	assert.strictEqual( opener.getAttribute( 'aria-controls' ), null );
	assert.ok( doc.activeElement === opener, 'Escape closed the popup and left the focus in it' );
} );

test( 'opening a location from its row centres its pin and zooms in to meet the floor', async () => {
	// Task 32 moved the map to the pin and left the zoom alone; Task 32b makes
	// it one call that does both, because a pin centred at zoom 8 is a dot in
	// a region and the popup over it says a street address nothing on the map
	// can show.
	//
	// The floor is the shortcode's own zoom, and it is the same number Task 32
	// capped framing with — one number, two bounds: a frame never goes closer
	// than it, and opening one location never leaves you further out than it.
	const harness = await locator( {
		payload: [ store() ],
		config: defaultConfig( { zoom: 13 } ),
	} );
	const opener = harness.container.querySelector( '.slosm__result-open' );

	// The visitor pulls back to look at the region. Driven through the map
	// rather than faked on the instance, so what the code under test reads is
	// what a real zoom-out would have left there.
	harness.instance.map.setView( [ WARSAW.lat, WARSAW.lng ], 8 );

	const views = harness.leafletCalls.setView.length;

	fire( opener, 'click' );

	assert.strictEqual(
		harness.leafletCalls.setView.length,
		views + 1,
		'the row opened a popup over a pin the map never moved to'
	);

	const view = harness.leafletCalls.setView[ harness.leafletCalls.setView.length - 1 ];

	assert.deepStrictEqual( plain( view.center ), [ WARSAW.lat, WARSAW.lng ], 'the pin was not centred' );
	assert.strictEqual( view.zoom, 13, 'the map stayed pulled back with a street address in the bubble' );
} );

test( 'opening a location never pulls back from a closer look the visitor chose', async () => {
	// The other half of the floor, and the half that makes it a floor rather
	// than a jump. Somebody who has zoomed in on a street is reading something;
	// a row press means "show me this one", never "forget how close I was".
	const harness = await locator( {
		payload: [ store() ],
		config: defaultConfig( { zoom: 13 } ),
	} );
	const opener = harness.container.querySelector( '.slosm__result-open' );

	harness.instance.map.setView( [ WARSAW.lat, WARSAW.lng ], 17 );

	// Counted before the press, and the count is asserted after it. Without
	// that the last setView on the list is the line above — this case's own —
	// and it would pass against a front end that did nothing at all.
	const views = harness.leafletCalls.setView.length;

	fire( opener, 'click' );

	assert.strictEqual( harness.leafletCalls.setView.length, views + 1, 'the press moved nothing' );

	const view = harness.leafletCalls.setView[ harness.leafletCalls.setView.length - 1 ];

	assert.deepStrictEqual( plain( view.center ), [ WARSAW.lat, WARSAW.lng ] );
	assert.strictEqual( view.zoom, 17, 'the press zoomed the visitor out' );
} );

test( 'opening a second location moves the claim rather than leaving two of them', async () => {
	// Task 34, reported off a live page and reproduced there before it was
	// reproduced here: from the *second* row press onwards the aria state
	// stopped moving. Measured on the site, three rows, pressed in order:
	//
	//     after row 0   0:true/controls  1:false  2:false
	//     after row 1   0:true/controls  1:false  2:false   <- nothing moved
	//     after close   0:true/controls                     <- with no popup open
	//
	// The cause is one shared slot meeting an ordering nobody wrote down.
	// `instance.opener` is set by the row's click handler and read by the
	// popupopen handler, and Leaflet fires **popupclose for the old popup
	// before popupopen for the new one** out of a single openPopup() call. So
	// the close handler read the slot the *next* open had already filled,
	// cleared that button instead of its own, and left the slot empty for the
	// open that was about to need it.
	//
	// The fix is to stop asking a shared slot a question only the popup can
	// answer: wirePopup() runs once per marker, so each popup's own closure
	// remembers the button that opened it, and the slot is a handoff consumed
	// at popupopen rather than a place anything is stored.
	const harness = await locator( {
		payload: [ store(), store( { id: 2, name: 'Kraków', lat: KRAKOW.lat, lng: KRAKOW.lng } ) ],
	} );
	const openers = harness.container.querySelectorAll( '.slosm__result-open' );

	assert.strictEqual( openers.length, 2, 'the fixture did not render two rows' );

	fire( openers[ 0 ], 'click' );

	assert.strictEqual( openers[ 0 ].getAttribute( 'aria-expanded' ), 'true' );
	assert.strictEqual( openers[ 1 ].getAttribute( 'aria-expanded' ), 'false' );

	fire( openers[ 1 ], 'click' );

	// The claim moved. Both halves are asserted, because each fails on its own
	// in a different way: the first row keeping it is a button pointing at a
	// popup that closed, and the second not taking it is a button that opened
	// something and says it did not.
	assert.strictEqual( openers[ 0 ].getAttribute( 'aria-expanded' ), 'false', 'the first row still claims a popup that was closed' );
	assert.strictEqual( openers[ 0 ].getAttribute( 'aria-controls' ), null, 'the first row still names an element that is gone' );
	assert.strictEqual( openers[ 1 ].getAttribute( 'aria-expanded' ), 'true', 'the row that opened the popup says it did not' );
	assert.ok( openers[ 1 ].getAttribute( 'aria-controls' ), 'the open row names nothing' );
} );

test( 'closing the second popup leaves no row claiming anything', async () => {
	// The other end of the same defect: after the slot had been consumed by
	// the wrong handler, closing left a button with aria-expanded="true" and
	// an aria-controls naming an element no longer in the document — which
	// wirePopup()'s own docblock calls worse than no aria-controls at all.
	const harness = await locator( {
		payload: [ store(), store( { id: 2, name: 'Kraków', lat: KRAKOW.lat, lng: KRAKOW.lng } ) ],
	} );
	const openers = harness.container.querySelectorAll( '.slosm__result-open' );

	fire( openers[ 0 ], 'click' );
	fire( openers[ 1 ], 'click' );

	marker( harness, 1 ).closePopup();

	Array.prototype.forEach.call( openers, function ( opener, at ) {
		assert.strictEqual( opener.getAttribute( 'aria-expanded' ), 'false', 'row ' + at + ' still claims to be expanded' );
		assert.strictEqual( opener.getAttribute( 'aria-controls' ), null, 'row ' + at + ' still names a popup' );
	} );
} );
