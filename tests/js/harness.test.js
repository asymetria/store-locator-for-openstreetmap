/**
 * Tests of the harness itself: the controls that keep its stubs honest.
 *
 * Every case here exists because a case in locator.test.js would otherwise be
 * satisfied by code that does nothing. "No innerHTML was assigned" is true of
 * an empty file; "the map div was found" is true of a selector that matches
 * everything. These prove the tripwires fire and the fixtures match the PHP
 * they claim to copy.
 */

'use strict';

const { test } = require( 'node:test' );
const assert = require( 'node:assert' );

const {
	LIMIT_CHOICES,
	RADIUS_CHOICES,
	StubDocument,
	classFromSelector,
	defaultConfig,
	defaultStrings,
	deferredResponse,
	fire,
	jsonResponse,
	loadLocator,
	makeClock,
	makeGeolocation,
	pluginSource,
	position,
	positionError,
} = require( './harness.js' );

test( 'the innerHTML tripwire fires on write', () => {
	const doc = new StubDocument();
	const el = doc.createElement( 'div' );

	assert.throws(
		() => {
			el.innerHTML = '<b>x</b>';
		},
		/innerHTML is not available/
	);
} );

test( 'the innerHTML tripwire fires on read', () => {
	const doc = new StubDocument();
	const el = doc.createElement( 'div' );

	assert.throws( () => el.innerHTML, /innerHTML is not available/ );
} );

test( 'textContent keeps markup as text and never parses it', () => {
	const doc = new StubDocument();
	const el = doc.createElement( 'div' );

	el.textContent = '<img src=x onerror=alert(1)>';

	assert.strictEqual( el.textContent, '<img src=x onerror=alert(1)>' );
	assert.strictEqual( el.children.length, 0, 'textContent must not create elements' );
} );

test( 'textContent replaces every child, as the real one does', () => {
	const doc = new StubDocument();
	const el = doc.createElement( 'ol' );

	el.appendChild( doc.createElement( 'li' ) );
	el.appendChild( doc.createElement( 'li' ) );
	assert.strictEqual( el.children.length, 2 );

	el.textContent = '';

	assert.strictEqual( el.children.length, 0 );
	assert.strictEqual( el.childNodes.length, 0 );
} );

test( 'a class selector matches whole tokens, not prefixes', () => {
	const doc = new StubDocument();
	const outer = doc.createElement( 'div' );
	const inner = doc.createElement( 'div' );

	outer.className = 'slosm';
	inner.className = 'slosm__map';
	outer.appendChild( inner );
	doc.body.appendChild( outer );

	// The real classList is token-based. A stub matching by substring would
	// make document.querySelectorAll( '.slosm' ) return the map div too, and
	// "initialise every locator on the page" would quietly initialise twice.
	//
	// A length and an identity, never deepStrictEqual against an array holding
	// a node. This file states that rule in its own header and these two lines
	// broke it; they are cheap here — bare stubs with no listeners on them —
	// and the cheapness is exactly what makes the habit spread to a case where
	// the nodes are live and the failure is an out-of-memory abort with no case
	// name in it. tests/js/popup.test.js has that story, from the day it
	// happened.
	const outers = doc.querySelectorAll( '.slosm' );
	const inners = doc.querySelectorAll( '.slosm__map' );

	assert.strictEqual( outers.length, 1 );
	assert.ok( outers[ 0 ] === outer, '.slosm matched something other than the container' );
	assert.strictEqual( inners.length, 1 );
	assert.ok( inners[ 0 ] === inner, '.slosm__map matched something other than the map' );
} );

test( 'the selector engine refuses anything but a single class', () => {
	assert.strictEqual( classFromSelector( '.slosm__map' ), 'slosm__map' );
	assert.throws( () => classFromSelector( 'div.slosm > span' ), /single-class selectors/ );
	assert.throws( () => classFromSelector( '[data-slosm]' ), /single-class selectors/ );
} );

test( 'a missing attribute reads back as null, not empty string', () => {
	const doc = new StubDocument();
	const el = doc.createElement( 'div' );

	assert.strictEqual( el.getAttribute( 'data-slosm' ), null );

	el.setAttribute( 'data-slosm', '' );

	assert.strictEqual( el.getAttribute( 'data-slosm' ), '' );
} );

test( 'the Leaflet stub throws on invalid bounds, as the real one does', () => {
	const harness = loadLocator();
	const map = harness.L.map( harness.document.createElement( 'div' ), {} );

	map.setView( [ 0, 0 ], 2 );

	assert.throws( () => map.fitBounds( [] ), /Bounds are not valid\./ );
} );

test( 'the Leaflet stub throws when a layer is added before a view is set', () => {
	const harness = loadLocator();
	const map = harness.L.map( harness.document.createElement( 'div' ), {} );

	assert.throws( () => harness.L.tileLayer( 'u', {} ).addTo( map ), /Set map center and zoom first\./ );
} );

test( 'the one Leaflet behaviour the stub reproduces is in the vendored source', () => {
	// Standing rule: a claim about Leaflet cites the source it was read in.
	// The string is unique in the minified file, so its presence is the whole
	// of the citation.
	const leaflet = pluginSource( 'assets', 'leaflet', 'leaflet.js' );

	assert.ok(
		leaflet.includes( 'throw new Error("Bounds are not valid.")' ),
		'Leaflet no longer throws on invalid bounds; the harness stub now lies'
	);
} );

test( 'the stub\'s other strictness is invented, and Leaflet still disagrees with it', () => {
	// The harness throws when a layer is added to a map with no view. Leaflet
	// does not. This case used to assert that
	// `throw new Error("Set map center and zoom first.")` is in the file and
	// call that a citation — which it is, of a function that has nothing to do
	// with addLayer. A reviewer caught it.
	//
	// What is asserted instead is the real path, so the harness header's
	// "this is invented" note is itself pinned: if a future Leaflet starts
	// refusing an early layer add, this fails and the note becomes a
	// reproduction rather than a house rule.
	const leaflet = pluginSource( 'assets', 'leaflet', 'leaflet.js' );

	assert.ok(
		leaflet.includes( 'this.whenReady(t._layerAdd,t)' ),
		'Map.addLayer no longer defers through whenReady; re-read what it does now'
	);
	assert.ok(
		leaflet.includes( 'whenReady:function(t,e){return this._loaded?t.call(e||this,{target:this}):this.on("load",t,e),this}' ),
		'whenReady no longer queues on "load"; the harness note about deferral is stale'
	);

	// And the message the stub borrows belongs to _checkIfLoaded, which is
	// reached from getCenter and getPixelOrigin and from nowhere else. Three
	// occurrences: the definition and those two calls.
	assert.strictEqual( leaflet.split( '_checkIfLoaded' ).length - 1, 3 );
	assert.ok( leaflet.includes( 'getCenter:function(){return this._checkIfLoaded()' ) );
	assert.ok( leaflet.includes( 'getPixelOrigin:function(){return this._checkIfLoaded()' ) );
} );

test( 'Leaflet still makes the map and its markers keyboard-reachable on its own', () => {
	// Shortcode::render() puts no tabindex on the map div and no role="button"
	// on a marker, on the strength of Leaflet doing both. That was recorded as
	// an unverified claim until Task 13 put the library on disk; this is the
	// verification, and it fails if a future Leaflet stops doing it — at which
	// point the attributes belong in the PHP.
	const leaflet = pluginSource( 'assets', 'leaflet', 'leaflet.js' );

	assert.ok(
		leaflet.includes( 't.tabIndex<=0&&(t.tabIndex="0")' ),
		'Leaflet no longer gives the map container a tabindex; the shortcode now needs one'
	);
	assert.ok(
		leaflet.includes( 'keyboard:!0' ),
		'Leaflet no longer defaults its keyboard handling on'
	);
	assert.ok(
		leaflet.includes( 'i.tabIndex="0",i.setAttribute("role","button")' ),
		'Leaflet no longer makes markers focusable buttons'
	);
} );

/* -------------------------------------------------------------------------
 * What Task 14 grew: the template, the clock, events, abort
 * ---------------------------------------------------------------------- */

test( 'a template\'s contents are out of the tree, so a selector does not reach them', () => {
	const harness = loadLocator();
	const container = harness.locatorMarkup();

	// The control first: there really is a row in there. Without it, "the
	// selector finds nothing" is equally true of a fixture that forgot to
	// build the template at all.
	const template = container.querySelector( '.slosm__row' );

	assert.ok( template, 'the fixture built no <template class="slosm__row">' );
	assert.ok( template.content.querySelector( '.slosm__result' ), 'the template is empty' );

	// And now the thing that matters: nothing inside it answers a query over
	// the container, exactly as a real template's inert contents do not.
	assert.strictEqual( container.querySelector( '.slosm__result' ), null );
	assert.strictEqual( template.children.length, 0 );
} );

test( 'cloneNode copies attributes and children, and carries no listener over', () => {
	const doc = new StubDocument();
	const original = doc.createElement( 'li' );
	const child = doc.createElement( 'span' );
	let ran = 0;

	original.className = 'slosm__result';
	original.setAttribute( 'data-x', '1' );
	child.className = 'slosm__result-name';
	child.textContent = 'Warsaw';
	original.appendChild( child );
	original.addEventListener( 'click', () => ran++ );

	const copy = original.cloneNode( true );

	assert.strictEqual( copy.getAttribute( 'data-x' ), '1' );
	assert.ok( copy.classList.contains( 'slosm__result' ) );
	assert.strictEqual( copy.querySelector( '.slosm__result-name' ).textContent, 'Warsaw' );
	assert.notStrictEqual( copy.querySelector( '.slosm__result-name' ), child, 'the clone shares a child node' );

	// The control for the listener half: the original still fires, so "the
	// copy did not" is about the copy rather than about a dead dispatcher.
	fire( original, 'click' );
	assert.strictEqual( ran, 1 );

	fire( copy, 'click' );
	assert.strictEqual( ran, 1, 'cloneNode carried an event listener over; the real one does not' );
} );

test( 'a fragment refuses the attributes a DocumentFragment does not have', () => {
	const doc = new StubDocument();
	const template = doc.createElement( 'template' );

	assert.strictEqual( template.content.nodeType, 11 );
	assert.throws( () => template.content.setAttribute( 'id', 'x' ), /no attributes/ );
} );

test( 'the clock runs nothing before its delay and runs it at it', () => {
	const clock = makeClock();
	const ran = [];

	clock.setTimeout( () => ran.push( 'a' ), 300 );

	clock.tick( 299 );
	assert.deepStrictEqual( ran, [], 'the clock ran a callback early' );
	assert.strictEqual( clock.pending(), 1 );

	clock.tick( 1 );
	assert.deepStrictEqual( ran, [ 'a' ], 'the clock never ran the callback' );
	assert.strictEqual( clock.pending(), 0 );
	assert.strictEqual( clock.now, 300 );
} );

test( 'the clock runs callbacks in scheduled order, including ones scheduled inside one', () => {
	const clock = makeClock();
	const ran = [];

	clock.setTimeout( () => {
		ran.push( 'first' );
		clock.setTimeout( () => ran.push( 'nested' ), 10 );
	}, 100 );
	clock.setTimeout( () => ran.push( 'second' ), 200 );

	clock.tick( 500 );

	assert.deepStrictEqual( ran, [ 'first', 'nested', 'second' ] );
} );

test( 'clearTimeout cancels', () => {
	const clock = makeClock();
	const ran = [];
	const id = clock.setTimeout( () => ran.push( 'a' ), 300 );

	clock.setTimeout( () => ran.push( 'b' ), 300 );
	clock.clearTimeout( id );
	clock.tick( 300 );

	// The second timer is the control: the clock still runs what was not
	// cancelled, so the empty half is about clearTimeout and not about a
	// clock that stopped.
	assert.deepStrictEqual( ran, [ 'b' ] );
} );

test( 'the sandbox has a clock and no way to read real time', () => {
	const harness = loadLocator();

	assert.strictEqual( typeof harness.sandbox.setTimeout, 'function' );
	assert.strictEqual( typeof harness.sandbox.clearTimeout, 'function' );
	assert.strictEqual( harness.sandbox.Date, undefined, 'the sandbox grew a Date; a debounce could now read real time' );
} );

test( 'events bubble to ancestors, and focus does not', () => {
	const doc = new StubDocument();
	const outer = doc.createElement( 'div' );
	const inner = doc.createElement( 'button' );
	const seen = [];

	outer.appendChild( inner );
	outer.addEventListener( 'click', ( event ) => seen.push( 'click:' + event.target.tagName ) );
	outer.addEventListener( 'focus', () => seen.push( 'focus' ) );

	// change is here because Task 29b turns on it: a select the visitor moves
	// re-runs the search, and every case for that drives the control with
	// fire( select, 'change' ). A browser's change event bubbles — the HTML
	// spec fires it at the control with bubbles set — and NON_BUBBLING is a
	// hand-written table this harness could get wrong for it as easily as for
	// any other type. Nothing in this plugin listens for change on an
	// ancestor, so no case in locator.js's suite can see the difference; this
	// one can.
	outer.addEventListener( 'change', ( event ) => seen.push( 'change:' + event.target.tagName ) );

	fire( inner, 'click' );
	fire( inner, 'focus' );
	fire( inner, 'change' );

	assert.deepStrictEqual( seen, [ 'click:BUTTON', 'change:BUTTON' ] );
} );

test( 'preventDefault is visible to whoever dispatched the event', () => {
	const doc = new StubDocument();
	const input = doc.createElement( 'input' );

	input.addEventListener( 'keydown', ( event ) => {
		if ( 'Enter' === event.key ) {
			event.preventDefault();
		}
	} );

	assert.strictEqual( fire( input, 'keydown', { key: 'Enter' } ).defaultPrevented, true );
	assert.strictEqual( fire( input, 'keydown', { key: 'a' } ).defaultPrevented, false );
} );

test( 'getElementById finds an element by its id and nothing by a missing one', () => {
	const harness = loadLocator();
	const container = harness.locatorMarkup();
	const node = harness.document.createElement( 'li' );

	node.setAttribute( 'id', 'slosm-1-option-0' );
	container.appendChild( node );

	assert.strictEqual( harness.document.getElementById( 'slosm-1-option-0' ), node );
	assert.strictEqual( harness.document.getElementById( 'slosm-1-option-1' ), null );
} );

test( 'an aborted signal makes the fetch reject rather than answer', async () => {
	const harness = loadLocator();
	const controller = new harness.sandbox.AbortController();

	// The control: the same queued response resolves when nothing aborted it.
	harness.fetchQueue.push( jsonResponse( [ 1 ] ) );

	const fine = await harness.sandbox.fetch( 'https://example.test/x', { signal: controller.signal } );

	assert.strictEqual( fine.status, 200 );

	harness.fetchQueue.push( jsonResponse( [ 1 ] ) );
	controller.abort();

	await assert.rejects( harness.sandbox.fetch( 'https://example.test/x', { signal: controller.signal } ), ( error ) => {
		assert.strictEqual( error.name, 'AbortError' );

		return true;
	} );
} );

test( 'a deferred response waits for the test, and an abort beats a late answer', async () => {
	const harness = loadLocator();
	const controller = new harness.sandbox.AbortController();
	const slow = deferredResponse();
	let settled = null;

	harness.fetchQueue.push( slow );

	const pending = harness.sandbox
		.fetch( 'https://example.test/x', { signal: controller.signal } )
		.then( () => {
			settled = 'resolved';
		} )
		.catch( ( error ) => {
			settled = error.name;
		} );

	// Nothing has happened yet, which is the point of a deferred response.
	await Promise.resolve();
	assert.strictEqual( settled, null );

	controller.abort();
	slow.resolve( jsonResponse( [ 1 ] ) );

	await pending;

	assert.strictEqual( settled, 'AbortError', 'the late answer won a race it had already lost' );
} );

test( 'an abort after the response was handed over does nothing, as in a browser', () => {
	// The other side of the line, and the harness got this wrong at first: a
	// real fetch promise, once resolved with a Response, cannot be
	// un-resolved. Modelling it the other way would let a case prove a guard
	// against something no browser does — and the window it sits in is exactly
	// the one locator.js's sequence numbers are written for.
	const harness = loadLocator();
	const controller = new harness.sandbox.AbortController();
	const slow = deferredResponse();
	let settled = null;

	harness.fetchQueue.push( slow );

	const pending = harness.sandbox
		.fetch( 'https://example.test/x', { signal: controller.signal } )
		.then( ( response ) => {
			settled = 'status ' + response.status;
		} )
		.catch( ( error ) => {
			settled = error.name;
		} );

	// Delivered, and only then abandoned — the two in the same turn, which is
	// the window that made this wrong before.
	slow.resolve( jsonResponse( [ 1 ] ) );
	controller.abort();

	return pending.then( () => {
		assert.strictEqual( settled, 'status 200', 'an abort un-delivered a response the browser had already handed over' );
	} );
} );

test( 'a marker has no element before it is on a map, and none after it leaves', () => {
	const harness = loadLocator();
	const map = harness.L.map( harness.document.createElement( 'div' ), {} );
	const marker = harness.L.marker( [ 52, 21 ], {} );

	map.setView( [ 52, 21 ], 12 );

	assert.strictEqual( marker.getElement(), null, 'a marker had an icon before it was added' );

	marker.addTo( map );

	assert.ok( marker.getElement(), 'a marker on the map has no icon' );
	assert.ok( marker.getElement().classList.contains( 'leaflet-marker-icon' ) );

	map.removeLayer( marker );

	assert.strictEqual( marker.getElement(), null );
	assert.strictEqual( map.layers.indexOf( marker ), -1 );
} );

test( 'a marker delivers the events Leaflet really delivers to a layer', () => {
	// The stub fires nothing by itself; a test fires by hand where a pointer
	// would have been. What is asserted here is that the *types* are the ones
	// the vendored library dispatches to a layer, so the production code is
	// not listening for an event that never arrives.
	const leaflet = pluginSource( 'assets', 'leaflet', 'leaflet.js' );

	assert.ok(
		leaflet.includes( '_mouseEvents:["click","dblclick","mouseover","mouseout","contextmenu"]' ),
		'Leaflet no longer delivers mouseover and mouseout to a layer'
	);
	assert.ok(
		leaflet.includes( 'getElement:function(){return this._icon}' ),
		'Marker.getElement no longer hands back the icon element'
	);
	assert.ok(
		leaflet.includes( '_removeIcon:function()' ) && leaflet.includes( 'this._icon=null' ),
		'Marker no longer drops its icon on removal'
	);

	const harness = loadLocator();
	const map = harness.L.map( harness.document.createElement( 'div' ), {} );
	const marker = harness.L.marker( [ 52, 21 ], {} );
	const seen = [];

	map.setView( [ 52, 21 ], 12 );
	marker.addTo( map );
	marker.on( 'mouseover', ( event ) => seen.push( event.type ) );
	marker.fire( 'mouseover' );
	marker.fire( 'mouseout' );

	assert.deepStrictEqual( seen, [ 'mouseover' ] );
} );

/* -------------------------------------------------------------------------
 * What Task 15 grew: the cluster group, divIcon, and geolocation
 * ---------------------------------------------------------------------- */

test( 'a marker in a cluster group has no icon, and gets one when it comes out', () => {
	// The one behaviour of the real library the production code has to survive,
	// and the reason it is modelled at all. Everything else about clustering —
	// the grid, the zoom, the bubble — is absent, and the header says so.
	const harness = loadLocator();
	const map = harness.L.map( harness.document.createElement( 'div' ), {} );
	const group = harness.L.markerClusterGroup( {} );
	const marker = harness.L.marker( [ 52, 21 ], {} );
	const seen = [];

	map.setView( [ 52, 21 ], 12 );
	group.addTo( map );
	marker.on( 'add', () => seen.push( 'add' ) );
	marker.on( 'remove', () => seen.push( 'remove' ) );
	marker.addTo( group );

	// The control that makes the null mean something: the same marker added to
	// the map itself does get an element, so this is the group's doing.
	assert.strictEqual( marker.getElement(), null, 'a marker inside a cluster had an icon' );
	assert.strictEqual( harness.L.marker( [ 52, 21 ], {} ).addTo( map ).getElement() !== null, true );

	group.uncluster( marker );

	assert.ok( marker.getElement(), 'a marker that left the cluster still has no icon' );
	assert.deepStrictEqual( seen, [ 'add' ] );

	group.recluster( marker );

	assert.strictEqual( marker.getElement(), null );
	assert.deepStrictEqual( seen, [ 'add', 'remove' ] );
} );

test( 'the events the cluster stub fires are the ones Leaflet really fires', () => {
	// Standing rule: a claim about Leaflet cites the source it was read in.
	// uncluster()/recluster() stand in for a marker being added to and removed
	// from the group's feature group, which is on the map — so what fires is
	// Map._layerAdd's 'add' and removeLayer's 'remove', on the layer itself.
	const leaflet = pluginSource( 'assets', 'leaflet', 'leaflet.js' );

	assert.ok(
		leaflet.includes( 'this.onAdd(i),this.fire("add"),i.fire("layeradd",{layer:this})' ),
		'Leaflet no longer fires "add" on a layer when it joins a map'
	);
	assert.ok(
		leaflet.includes( 'this.fire("layerremove",{layer:t}),t.fire("remove")' ),
		'Leaflet no longer fires "remove" on a layer when it leaves a map'
	);
} );

test( 'the cluster group records what was added, removed and cleared', () => {
	const harness = loadLocator();
	const map = harness.L.map( harness.document.createElement( 'div' ), {} );
	const group = harness.L.markerClusterGroup( {} );
	const first = harness.L.marker( [ 52, 21 ], {} );
	const second = harness.L.marker( [ 50, 19 ], {} );

	map.setView( [ 52, 21 ], 12 );
	group.addTo( map );
	first.addTo( group );
	second.addTo( group );

	assert.strictEqual( group.markers.length, 2 );
	assert.strictEqual( harness.leafletCalls.clusterAdd.length, 2 );

	group.removeLayer( first );

	// Identity rather than deepStrictEqual, for the reason nearby.test.js
	// records at length: a stub node or layer reaches its document and its
	// listener map, so structurally comparing two of them walks everything
	// either one can see. It is instant when the references match and it takes
	// the process out when they do not, which is the wrong way round for a
	// failing assertion.
	assert.strictEqual( group.markers.length, 1 );
	assert.ok( group.markers[ 0 ] === second, 'removeLayer dropped the wrong marker' );

	group.clearLayers();

	assert.strictEqual( group.markers.length, 0 );
	assert.strictEqual( harness.leafletCalls.clusterClear.length, 1 );
	assert.strictEqual( harness.leafletCalls.clusterClear[ 0 ].markers.length, 1 );
} );

test( 'the DivIcon branch this plugin relies on is still in the vendored Leaflet', () => {
	// The harness cannot execute it — there is no Element constructor in the
	// sandbox and no parser behind innerHTML — so the only honest control is
	// that the branch exists in the library the plugin ships. An Element is
	// appended; anything else is parsed as markup.
	const leaflet = pluginSource( 'assets', 'leaflet', 'leaflet.js' );

	assert.ok(
		leaflet.includes( 'instanceof Element?(me(t),t.appendChild(e.html)):t.innerHTML=' ),
		'L.DivIcon no longer appends an Element; handing it one is no longer a defence'
	);

	// And the cluster icon really is a DivIcon, which is what makes that
	// branch the one a cluster label goes through.
	const cluster = pluginSource( 'assets', 'markercluster', 'leaflet.markercluster.js' );

	assert.ok(
		cluster.includes( 'new L.DivIcon({html:"<div><span>"+t+"</span></div>"' ),
		'the default cluster icon is no longer a DivIcon built from a string'
	);
} );

test( 'geolocation answers from the queue and otherwise not at all', () => {
	const geo = makeGeolocation();
	const seen = [];

	// Nothing queued: the prompt is open and nothing has been decided. This is
	// the state most visitors are in for a second or two, and a stub that
	// invented an answer here would make that state untestable.
	geo.geolocation.getCurrentPosition(
		() => seen.push( 'granted' ),
		() => seen.push( 'refused' )
	);

	assert.deepStrictEqual( seen, [] );
	assert.strictEqual( geo.calls.length, 1 );

	// And the case can settle it by hand, which is what an unqueued call is for.
	geo.calls[ 0 ].success( position( 52, 21 ).position );

	assert.deepStrictEqual( seen, [ 'granted' ] );

	geo.queue.push( positionError( 1 ) );
	geo.geolocation.getCurrentPosition(
		() => seen.push( 'granted' ),
		( error ) => seen.push( 'refused:' + error.code )
	);

	assert.deepStrictEqual( seen, [ 'granted', 'refused:1' ] );
} );

test( 'the options a position request was made with are recorded and never honoured', () => {
	const geo = makeGeolocation();

	geo.geolocation.getCurrentPosition(
		() => {},
		() => {},
		{ timeout: 10000, maximumAge: 60000 }
	);

	assert.strictEqual( geo.calls[ 0 ].options.timeout, 10000 );
	assert.strictEqual( geo.calls[ 0 ].options.maximumAge, 60000 );

	// Nothing here can make that timeout fire, and the clock cannot either: the
	// timer belongs to the browser, not to the page. A case about a timeout
	// delivers the error itself.
	assert.strictEqual( geo.queue.length, 0 );
} );

test( 'the sandbox has a navigator, and withGeolocation false leaves it without one', () => {
	assert.strictEqual( typeof loadLocator().sandbox.navigator.geolocation.getCurrentPosition, 'function' );
	assert.strictEqual( loadLocator( { withGeolocation: false } ).sandbox.navigator.geolocation, undefined );

	// A navigator is there either way. A browser without one does not exist,
	// and a case for that guard could not be reached from a page.
	assert.strictEqual( typeof loadLocator( { withGeolocation: false } ).sandbox.navigator, 'object' );
} );

test( 'an unqueued fetch rejects rather than answering something', async () => {
	const harness = loadLocator();

	await assert.rejects( harness.sandbox.fetch( 'https://example.test/x' ), /nothing queued/ );
	assert.strictEqual( harness.fetchCalls.length, 1 );
} );

test( 'the fixture markup uses the class names the shortcode really emits', () => {
	const source = pluginSource( 'includes', 'class-shortcode.php' );

	// The container, the map div and the results list, exactly as PHP writes
	// them. If Task 11's markup is renamed, this fails here rather than
	// letting locator.test.js go on passing against markup nobody ships.
	// Task 21 put the list's position on the container as a modifier class, so
	// the literal is no longer the whole attribute. What is checked is the two
	// halves that matter: the base class the script looks the container up by,
	// and the one place the modifier is appended.
	assert.ok(
		source.includes( "'<div class=\"slosm' . esc_attr( $position ) . '\" data-slosm=\"'" ),
		'the container markup changed'
	);
	assert.ok(
		source.includes( "' slosm--list-' . $config['position']" ),
		'the list-position modifier changed; assets/css/locator.css still styles slosm--list-left'
	);
	assert.ok( source.includes( "'<div class=\"slosm__map\"" ), 'the map div markup changed' );
	// Task 24c took aria-live off the list and gave the locator a status line
	// of its own, so what is pinned here is both halves of that: the list is
	// plain, and the element that carries every sentence is emitted empty,
	// with no whitespace inside it for the layout layer's `:empty` to miss.
	assert.ok( source.includes( "'<ol class=\"slosm__results\"></ol>'" ), 'the results list markup changed' );
	assert.ok(
		source.includes( "'<p class=\"slosm__message\" role=\"status\" aria-live=\"polite\"></p>'" ),
		'the status line markup changed; tests/js/harness.js builds the same element'
	);

	// Task 14's half: the search field and the row template. Every class the
	// front end now looks up by name, checked against the PHP that emits it.
	assert.ok( source.includes( "'<div class=\"slosm__filters\"" ), 'the filters markup changed' );
	assert.ok(
		source.includes( "'<label class=\"slosm__field slosm__field--search\">'" ),
		'the search field wrapper changed'
	);
	assert.ok(
		source.includes( "'<input type=\"search\" class=\"slosm__search\" value=\"'" ),
		'the search input changed'
	);
	assert.ok( source.includes( "'<template class=\"slosm__row\">'" ), 'the row template changed' );

	// Task 15's half: the category select and the "use my location" button.
	// The select's class is built by concatenation — `'slosm__' . $name` — so
	// what is checked is the shape plus the call that passes 'category' into
	// it, rather than a literal that is not in the file.
	assert.ok(
		source.includes( "'<select class=\"slosm__' . $name . '\">'" ),
		'the select markup changed; the fixture still writes class="slosm__category"'
	);
	assert.ok(
		source.includes( "__( 'Category', 'store-locator-for-openstreetmap' )" ),
		'the category select is no longer one of the fields'
	);
	assert.ok(
		// Task 30b2 made the class attribute a concatenation, the way Task 21
		// did to the container's: a site can add its own class to both
		// buttons. The fixture still writes the bare class, which is what the
		// server emits when that field is empty, and what is pinned here is
		// the shape plus the base class inside it.
		source.includes( "class=\"' . esc_attr( 'slosm__locate' . $button_class ) . '\">'" ),
		'the "use my location" button changed'
	);

	// Task 29a's half: the radius and limit selects, which the fixture builds
	// now that locator.js reads them. Same shape as the category select — the
	// class is `'slosm__' . $name` — so what is pinned is the two calls that
	// pass the names in, plus the two option builders the fixture is a copy of.
	// A rename on either side fails here rather than leaving
	// tests/js/filters.test.js driving a control nothing ships.
	// Task 24a put a gettext context on both labels — a bare "Within" is a
	// preposition a translator cannot place — so what is pinned is the msgid
	// and the call, not the whole call including the domain argument.
	assert.ok(
		source.includes( "_x( 'Within', 'label on the search-radius select'" ),
		'the radius select is no longer one of the fields'
	);
	assert.ok(
		source.includes( "_x( 'Show at most', 'label on the result-count select'" ),
		'the result-count select is no longer one of the fields'
	);
	assert.ok(
		source.includes( "$this->radius_options( $attributes )" ),
		'the radius options are no longer built where the fixture copied them from'
	);
	assert.ok(
		source.includes( "$this->limit_options( $attributes )" ),
		'the limit options are no longer built where the fixture copied them from'
	);

	// And the rule the fixture's option lists are a copy of: the configured
	// value is merged into the site's steps rather than replacing them, which
	// is what lets a case choose a value other than the one it started at.
	assert.ok(
		source.includes( '$choices = $this->with_current( $choices, $attributes[\'radius\'] );' ),
		'radius_options() no longer merges the configured radius into the steps'
	);
	assert.ok(
		source.includes( '$choices = $this->with_current( $choices, (float) $attributes[\'limit\'] );' ),
		'limit_options() no longer merges the configured limit into the steps'
	);

	// Task 29c's half: the submit control, and the attribute that is not a
	// control at all. Both are pinned as whole literals rather than by class
	// alone, because both carry a word the fixture copies — the button's
	// `type`, which is what keeps it from submitting a form this markup did
	// not open, and the field's `enterkeyhint`, which nothing in locator.js
	// reads and which therefore no behaviour case can miss the loss of.
	assert.ok(
		source.includes( "class=\"' . esc_attr( 'slosm__submit' . $button_class ) . '\">'" ),
		'the submit button changed; tests/js/search.test.js drives .slosm__submit'
	);
	assert.ok(
		source.includes( 'enterkeyhint="search"' ),
		'the address field lost enterkeyhint, so a phone keyboard is back to guessing its action key'
	);

	// And the absence that is the decision: no form opened anywhere in the
	// markup, and no submit button to be implicitly submitted. Both are
	// written as the literal a PHP file emits markup with — `'<form` — rather
	// than as the tag, because the tag is in this file's own reasoning two
	// screens up and a scan that cannot tell a warning from a use teaches
	// people to delete the warning. tests/test-shortcode.php makes the same
	// two assertions against the rendered html, which is the stronger half;
	// this one is what a reader of the source sees fail.
	assert.ok( ! source.includes( "'<form" ), 'Shortcode::render() now opens a form; implicit submission is back' );
	assert.ok(
		! source.includes( '<button type="submit"' ),
		'Shortcode::render() now emits a submit button, which a page builder\'s own form would adopt'
	);

	[
		'<li class="slosm__result">',
		'<button type="button" class="slosm__result-open">',
		'<span class="slosm__result-name"></span>',
		'<span class="slosm__result-address"></span>',
		'<span class="slosm__result-city"></span>',
		'<span class="slosm__result-distance"></span>',
		'<ul class="slosm__result-categories"></ul>',
	].forEach( ( markup ) => {
		assert.ok( source.includes( "'" + markup + "'" ), 'the row template no longer emits ' + markup );
	} );
} );

test( 'the server markup still has no ids for the minted ones to collide with', () => {
	// Task 11's decision, and the premise of minting ids in JavaScript: the
	// PHP writes no id anywhere, so the only ids on a locator are the ones
	// the front end made, and it can guarantee those are unique by itself.
	// An id added to the shortcode later would break that guarantee silently.
	const source = pluginSource( 'includes', 'class-shortcode.php' );

	assert.ok( ! source.includes( ' id="' ), 'Shortcode::render() now emits an id; the minted ids can collide with it' );
} );

/**
 * The array keys one PHP method's `return array( ... );` names.
 *
 * The parentheses are balanced rather than searched for, because the bodies
 * being read contain `$this->routes(),` and `$this->published_count();` and
 * the first `);` in the file is nowhere near the end of the literal. An
 * earlier version of this helper took that shortcut and answered with an empty
 * list — which deepStrictEqual would have accepted against an empty fixture,
 * so the control would have had no teeth at all.
 *
 * @param {string} file      Source text.
 * @param {string} signature Text that starts the declaration.
 * @returns {string[]} The keys, sorted.
 */
function phpArrayLiteral( file, signature ) {
	const start = file.indexOf( signature );

	assert.notStrictEqual( start, -1, 'no longer declares ' + signature );

	const open = file.indexOf( 'return array(', start ) + 'return array'.length;

	assert.ok( open > 'return array'.length, signature + ' no longer returns an array literal' );

	let depth = 0;
	let end = open;

	for ( let i = open; i < file.length; i++ ) {
		if ( '(' === file[ i ] ) {
			depth++;
		} else if ( ')' === file[ i ] ) {
			depth--;

			if ( 0 === depth ) {
				end = i;
				break;
			}
		}
	}

	return file.slice( open, end );
}

/**
 * The keys of the array literal one PHP method returns.
 *
 * @param {string} file      PHP source.
 * @param {string} signature The method's signature, as far as its opening paren.
 * @returns {string[]} The keys, sorted.
 */
function phpReturnedKeys( file, signature ) {
	const literal = phpArrayLiteral( file, signature );
	const keys = Array.from( literal.matchAll( /'([A-Za-z_]+)'\s*=>/g ) ).map( ( match ) => match[ 1 ] );

	assert.ok( keys.length > 0, 'read no keys out of ' + signature + ', so this control proves nothing' );

	return keys.sort();
}

test( 'the fixture config has exactly the keys Shortcode::config() returns', () => {
	assert.deepStrictEqual(
		phpReturnedKeys( pluginSource( 'includes', 'class-shortcode.php' ), 'public function config(' ),
		Object.keys( defaultConfig() ).sort(),
		'the fixture config and Shortcode::config() no longer carry the same keys'
	);
} );

test( 'the fixture tile layer is the one Settings ships, not one this file invented', () => {
	// The whole config is a hand-copy, and every other key in it is a literal
	// this suite chose. The tile layer is not: it is what Settings::tile_config()
	// builds on a site that has configured nothing, and a fixture that drifted
	// from it would let every case about the default map pass against a url no
	// site is served.
	const settings = pluginSource( 'includes', 'class-settings.php' );
	const { tile } = defaultConfig();

	assert.ok( settings.includes( "'tile_url'             => '" + tile.url + "'" ), tile.url );
	assert.ok( settings.includes( "'tile_attribution'     => '© OpenStreetMap contributors'" ) );
	assert.ok( settings.includes( "'tile_attribution_url' => 'https://www.openstreetmap.org/copyright'" ) );

	// And the assembled line is the two of those, in the shape
	// attribution_html() builds: the href, rel="noreferrer", the text.
	assert.strictEqual(
		tile.attribution,
		'<a href="https://www.openstreetmap.org/copyright" rel="noreferrer">© OpenStreetMap contributors</a>'
	);
} );

test( 'the fixture radius and limit steps are the ones Settings ships', () => {
	// Same argument as the tile layer above, and it matters more here because
	// tests/js/filters.test.js *chooses* one of these values and then asserts on
	// what the search did with it. A fixture offering steps no site is served
	// would let those cases drive a control nobody has.
	const settings = pluginSource( 'includes', 'class-settings.php' );

	assert.ok(
		settings.includes(
			"'radius_choices'       => array( " + RADIUS_CHOICES.map( ( n ) => n + '.0' ).join( ', ' ) + ' ),'
		),
		'the fixture radius steps drifted from Settings::defaults()'
	);
	assert.ok(
		settings.includes( "'limit_choices'        => array( " + LIMIT_CHOICES.join( ', ' ) + ' ),' ),
		'the fixture limit steps drifted from Settings::defaults()'
	);
} );

test( 'the fixture routes have exactly the keys Shortcode::routes() returns', () => {
	assert.deepStrictEqual(
		phpReturnedKeys( pluginSource( 'includes', 'class-shortcode.php' ), 'private function routes(' ),
		Object.keys( defaultConfig().routes ).sort()
	);
} );

test( 'the fixture strings have exactly the keys Assets::strings() returns', () => {
	assert.deepStrictEqual(
		phpReturnedKeys( pluginSource( 'includes', 'class-assets.php' ), 'public function strings(' ),
		Object.keys( defaultStrings() ).sort(),
		'the front end and Assets::strings() no longer agree on the shared strings'
	);
} );

test( 'the fixture strings are the sentences Assets::strings() ships, not only the same keys', () => {
	// Task 29b's sweep is why this exists. M33 changed the *fixture's* copy of
	// one sentence and survived every case in the suite, and it was right to:
	// the cases all assert defaultStrings().x rather than a literal, on purpose,
	// so that what they test is the wire and not the wording. That leaves the
	// fixture as the only statement of what a site is really served, and until
	// now an unchecked one — a hand-copy that could drift a word at a time.
	//
	// Same argument as "the fixture tile layer is the one Settings ships" and
	// "the fixture radius and limit steps are the ones Settings ships", which
	// are two tables over and pin values for exactly this reason.
	const literal = phpArrayLiteral( pluginSource( 'includes', 'class-assets.php' ), 'public function strings(' );
	const shipped = {};

	// __( 'text', … ) or _x( 'text', 'context', … ): the msgid is the first
	// literal either way, and Task 24a made 'directions' the second kind. The
	// alternation is not cosmetic — a pattern that only knew __() would drop
	// that one key, and the deepStrictEqual below would then report a missing
	// entry, which reads as the fixture having drifted rather than as this
	// pattern having stopped matching. Every key in the literal is claimed
	// here, so the two failures are told apart by the diff naming a key that
	// is plainly still in the PHP.
	Array.from( literal.matchAll( /'([A-Za-z_]+)'\s*=>\s*(?:__|_x)\(\s*'((?:[^'\\]|\\.)*)'/g ) ).forEach( ( match ) => {
		shipped[ match[ 1 ] ] = match[ 2 ].replace( /\\'/g, "'" ).replace( /\\\\/g, '\\' );
	} );

	assert.ok(
		Object.keys( shipped ).length > 0,
		'read no strings out of Assets::strings(), so this control proves nothing'
	);
	assert.deepStrictEqual(
		shipped,
		defaultStrings(),
		'the fixture and Assets::strings() no longer ship the same sentences'
	);
} );

test( 'the fixture submit button says what Shortcode::filters() renders, word for word', () => {
	// The same argument as the case above, applied to the one visible string
	// this task adds. It is not in Assets::strings() and must not be: the
	// button is rendered on the server, so its text reaches the page in the
	// markup and the front end never writes it. That leaves two hand-copies
	// of one sentence — the PHP and this fixture — and M33's lesson is that
	// an unpinned hand-copy drifts.
	const source = pluginSource( 'includes', 'class-shortcode.php' );
	const match = source.match( /'slosm__submit'[\s\S]*?_x\(\s*'((?:[^'\\]|\\.)*)'/ );

	assert.ok( match, 'read no label out of Shortcode::filters(), so this proves nothing' );

	const label = match[ 1 ].replace( /\\'/g, "'" ).replace( /\\\\/g, '\\' );
	const container = loadLocator( {} ).locatorMarkup( {} );
	const button = container.querySelector( '.slosm__submit' );

	assert.ok( button, 'the fixture builds no submit button for the cases that press it' );
	assert.strictEqual( button.textContent, label, 'the fixture and the shortcode disagree on what the button says' );
	assert.strictEqual( button.getAttribute( 'type' ), 'button' );

	// Beside the label, not inside it, which is where the PHP puts it and is a
	// difference no behaviour case in this suite can see: querySelector finds
	// the button either way. In a browser it is the difference between a press
	// that runs the search and a press that also drags the focus back into the
	// field, because a click inside a <label> is a click on the label.
	assert.strictEqual(
		button.parentNode.className,
		'slosm__filters',
		'the fixture moved the submit button inside the search label'
	);

	// And the attribute that no behaviour case can see either, because nothing
	// in locator.js reads it: the field's enterkeyhint, compared with the
	// server's rather than asserted as a literal on both sides.
	const hint = source.match( /enterkeyhint="([a-z]+)"/ );

	assert.ok( hint, 'read no enterkeyhint out of Shortcode::filters()' );
	assert.strictEqual(
		container.querySelector( '.slosm__search' ).getAttribute( 'enterkeyhint' ),
		hint[ 1 ],
		'the fixture and the shortcode disagree on the touch keyboard action key'
	);
} );

test( 'the front end\'s own fallback table has exactly the keys Assets::strings() returns', () => {
	// The case above pins the *fixture* against the PHP. This pins
	// assets/js/locator.js, which is a different claim, and until Task 29b
	// nothing made it — FALLBACK_STRINGS' own docblock said it was asserted
	// here and it was not.
	//
	// This comment used to end "admin.js has had this case since Task 22",
	// and that was wrong in exactly the way the sentence before it describes.
	// Task 24a probed it: one key added to admin.js's FALLBACK_STRINGS alone
	// left the whole suite at 413 passed, 0 failed. The case admin.js needed
	// is now in tests/js/admin-picker.test.js, written rather than assumed.
	//
	// What it prevents: text() returns window.slosmL10n[ key ] when the inline
	// l10n arrived and FALLBACK_STRINGS[ key ] when it did not, so a key
	// Assets localises and locator.js has no English for is undefined on any
	// site where a plugin dequeued the handle or an optimiser moved the inline
	// script — and `node.textContent = undefined` writes the word "undefined"
	// into the results list rather than a sentence.
	const harness = loadLocator( { strings: null } );

	assert.deepStrictEqual(
		Object.keys( harness.SLOSM.STRINGS ).sort(),
		phpReturnedKeys( pluginSource( 'includes', 'class-assets.php' ), 'public function strings(' ),
		'locator.js and Assets::strings() no longer agree on the shared strings'
	);

	// And the sentences, which Task 24d's sweep found nothing was checking.
	// A mutant that changed one fallback value — 'Locations found: %s' to
	// 'Found: %s' — survived the whole suite, because every case asserts
	// defaultStrings().x and that table is the fixture's rather than this one.
	// The keys being right is what stops the word "undefined" reaching the
	// page; the values being right is what a site with a dequeued handle or a
	// moved inline script actually reads, and it was a hand-copy nothing held.
	const literal = phpArrayLiteral( pluginSource( 'includes', 'class-assets.php' ), 'public function strings(' );
	const shipped = {};

	Array.from( literal.matchAll( /'([A-Za-z_]+)'\s*=>\s*(?:__|_x)\(\s*'((?:[^'\\]|\\.)*)'/g ) ).forEach( ( match ) => {
		shipped[ match[ 1 ] ] = match[ 2 ].replace( /\\'/g, "'" ).replace( /\\\\/g, '\\' );
	} );

	assert.ok( Object.keys( shipped ).length > 0, 'read no strings out of Assets::strings(), so this proves nothing' );
	assert.deepStrictEqual(
		// A copy made in this realm: the table is the sandbox's, and
		// deepStrictEqual compares prototypes.
		Object.assign( {}, harness.SLOSM.STRINGS ),
		shipped,
		'locator.js ships a different English sentence from the one Assets::strings() does'
	);
} );

test( 'the l10n variable name is the one Assets localises to', () => {
	const source = pluginSource( 'includes', 'class-assets.php' );

	assert.ok(
		source.includes( "public const L10N_OBJECT = 'slosmL10n'" ),
		'Assets renamed the l10n global; the harness and locator.js still say slosmL10n'
	);
} );
