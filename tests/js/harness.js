/**
 * Builds the world around assets/js/locator.js so Node can run it.
 *
 * The shipped file is a classic script, not an ES module: the floor is
 * WordPress 6.0, which has no script-modules API, and Assets registers it with
 * `defer`. So nothing here can `import` it. It is read off disk and evaluated
 * with node:vm inside a sandbox this file constructs — a window, a document, a
 * Leaflet, a fetch and a slosmL10n — exactly the way tests/bootstrap.php
 * constructs enough of WordPress for the PHP suite. Afterwards the sandbox's
 * own globals are handed back, and `window.SLOSM` is the surface under test.
 *
 * WHAT THIS FILE DOES NOT MODEL
 * =============================
 * Read this before believing a green run. Every stub below is deliberately
 * dumb: it exists so the code under test can run, not to emulate a browser.
 * Where the real thing would differ, the difference is named, and where there
 * was a choice the stub is made STRICTER than the browser, so a mistake fails
 * here rather than on somebody's site.
 *
 * The DOM
 * -------
 * - There is no HTML parser. Fixtures are built node by node by
 *   `locatorMarkup()`, which is a hand-copy of what Shortcode::render()
 *   emits. A hand-copy can drift from the PHP, so harness.test.js reads
 *   includes/class-shortcode.php and asserts every class name used here is
 *   really in it. Without that control this file could encode a false fact
 *   about the markup and hide a real bug, which is precisely how a stub lies.
 * - Selectors support one form and one form only: a single class, `.foo`.
 *   Anything else throws. The browser would accept `div.foo > span`; if the
 *   code under test ever needs that, this stub must grow first, visibly.
 * - `innerHTML` throws on both read and write, on every element. There is no
 *   such thing as an innerHTML in this harness. That is the no-innerHTML rule
 *   turned into a tripwire rather than a promise, and harness.test.js proves
 *   the tripwire fires — an assertion that nothing was assigned to innerHTML
 *   is otherwise satisfied by code that does nothing at all.
 * - No layout, no geometry, no computed style, no `getBoundingClientRect`.
 *   Whether the map div has a height is a CSS question this harness cannot
 *   ask; it stays a manual check.
 * - `textContent` concatenates descendant text in document order, as the real
 *   one does, and setting it replaces every child, as the real one does. That
 *   is the whole of the text model: no normalisation, no whitespace rules.
 *
 * The DOM, as Task 14 grew it
 * ---------------------------
 * Task 13 recorded that there was no event system, no `<template>` and no
 * clock, and that the task which needed them had to grow them visibly. This
 * is that growth, and what each piece does NOT do is the part worth reading.
 *
 * - `<template>` has a `.content` fragment, built node by node by
 *   `locatorMarkup()` the way the rest of the fixture is. It is inert in the
 *   one way that matters: the content is not in `childNodes`, so a
 *   `querySelectorAll` over the container does not descend into it — which is
 *   exactly why a real template's contents do not answer a selector either.
 *   The fragment is a StubElement subclass with its attribute methods turned
 *   into throws, because a DocumentFragment has no attributes and a stub that
 *   quietly accepted one would be a stub that lies.
 * - `cloneNode( deep )` copies tag, attributes, classes and the `value`
 *   property, and — as the real one does — copies NO event listeners. A case
 *   asserts that, because a clone that carried listeners would let a row's
 *   handlers appear to work without anything ever binding them.
 * - Events: `addEventListener`, `removeEventListener` and `dispatchEvent` on
 *   elements, with propagation up the `parentNode` chain and
 *   `preventDefault()` / `stopPropagation()`. What it does not model: capture
 *   phase, `relatedTarget`, the default action of anything at all (a
 *   `preventDefault()` on Enter is observable here only as a flag; whether a
 *   browser would have submitted a form is not a question this can ask), and
 *   the document and window ends of the bubble path — `documentElement` has no
 *   parent here, so an event never reaches `document`. Which events bubble is
 *   a small table (`NON_BUBBLING`) rather than a per-interface fact, and it is
 *   right for the handful of types used here and untested for the rest.
 * - `focus()` sets `document.activeElement` and fires a `focus` event. There
 *   is no tab order, no focusability rule and no scrolling: calling `focus()`
 *   on a `<div>` works here and would do nothing in a browser.
 * - `value` is a plain property on every element, not the `value` IDL
 *   attribute of an `<input>`: nothing ties it to the `value` *content*
 *   attribute the way a real input does before its first edit. `locatorMarkup()`
 *   seeds it from the config's `search` the way the parser would, and that is
 *   the whole of the connection.
 * - `getElementById` walks the tree comparing the `id` attribute. No id cache,
 *   so it is O(n) and — unlike the browser — it does not return the *first* in
 *   tree order faster than it finds a duplicate: it returns the first it walks
 *   into, which is the same thing for a tree with no duplicates and undefined
 *   behaviour to rely on for one with them.
 *
 * The DOM, as Task 15 grew it
 * ---------------------------
 * - `<select>` has no relationship between its `value` and its options. Setting
 *   `.value` here stores a string whatever the options say; a real select
 *   resets `value` to '' when no option matches, and updates it when an option
 *   is chosen. Nothing here chooses an option, either: a case sets `.value` and
 *   fires `change`, which is what a pointer would have caused. So a case can
 *   prove which options were built and what the code did with a selection, and
 *   cannot prove that a browser would have let that selection be made.
 *
 *   Task 29a narrowed the first half of that gap without closing it.
 *   `locatorMarkup()` now seeds a select's `value` the way a browser computes
 *   it — the option carrying `selected`, or the first option, or `''` when
 *   there are none — instead of assigning whatever the config said, so a
 *   fixture cannot start out showing a value it does not offer. What a case
 *   does *after* that is still unchecked here: `tests/js/filters.test.js` has
 *   its own `choose()` that refuses a value the select is not offering, and
 *   that assertion lives in the case file because it is a claim about the case,
 *   not a rule this DOM enforces.
 * - `disabled` is nothing but an attribute here. Setting it stops no event and
 *   greys nothing out; a case asserting a control is disabled is asserting
 *   about the markup, and whether the browser then refuses the interaction is
 *   the browser's rule rather than this file's.
 *
 * Leaflet
 * -------
 * - `L` here records calls and returns recorders. It draws nothing, requests
 *   no tile, projects no coordinate, validates no option and has no icon-path
 *   heuristic. A map that renders is a manual check.
 * - ONE behaviour is a faithful reproduction, cited to the vendored source:
 *   `fitBounds` on invalid (here: empty) bounds throws
 *   `Error( 'Bounds are not valid.' )` — leaflet.js, the `fitBounds:` property
 *   of the Map prototype, byte offset 29241 of the minified file:
 *   `if((t=g(t)).isValid())return ...;throw new Error("Bounds are not
 *   valid.")`.
 * - ONE behaviour is INVENTED, and was wrongly described as a reproduction
 *   until a reviewer checked it: adding a layer to a map whose view has never
 *   been set throws `Error( 'Set map center and zoom first.' )` here, and does
 *   NOT throw in Leaflet. What Leaflet really does, read in the vendored file:
 *   `Map.addLayer` ends in `this.whenReady(t._layerAdd,t)` and `whenReady` is
 *   `this._loaded?t.call(e||this,{target:this}):this.on("load",t,e)` — so an
 *   early layer add is *deferred* until the first setView fires 'load', not
 *   refused. `_checkIfLoaded`, which does carry that message, appears three
 *   times in the file: its own definition and calls from `getCenter` and
 *   `getPixelOrigin`. None is reachable from `addLayer`.
 *   The invention is kept because it is stricter in the safe direction and
 *   this harness's stated policy is to err that way: setting the view first is
 *   deterministic, while relying on the deferred queue makes the order in
 *   which a layer attaches depend on something no test here models. But it is
 *   a house rule, not a fact about Leaflet, and a case that fails on it is
 *   reporting a style violation rather than a browser crash.
 * - Task 14 added two more pieces, both cited:
 *   `marker.on( type, handler )` / `fire( type )` is Evented, and the types
 *   the two-way highlight uses are the ones Leaflet really delivers to a
 *   layer — `_mouseEvents:["click","dblclick","mouseover","mouseout","contextmenu"]`
 *   in the vendored file. Nothing here *causes* a fire: a test fires by hand,
 *   where a browser would have had a pointer over an icon.
 *   `marker.getElement()` is `getElement:function(){return this._icon}`, and
 *   the null-before-add, null-after-remove behaviour is the vendored
 *   `_initIcon` / `_removeIcon:function(){...this._icon=null}` pair. The
 *   element it hands back is a bare stub `<img>` this file makes, not
 *   anything resembling the icon Leaflet builds: no class beyond
 *   `leaflet-marker-icon`, no transform, no pane, no size.
 * - `map.removeLayer( layer )` drops the layer from the map's list, which is
 *   as much of `removeLayer:function(t){...delete this._layers[e]...}` as this
 *   can honestly claim. It fires no `layerremove`, calls no `onRemove` and
 *   detaches nothing from a DOM that was never built.
 * - Everything else about Leaflet is absent.
 *
 * Leaflet.markercluster, as Task 15 grew it
 * -----------------------------------------
 * - `L.markerClusterGroup()` here CLUSTERS NOTHING. There is no grid, no zoom,
 *   no distance and no cluster: it is a bag that records addLayer, removeLayer
 *   and clearLayers, and which of its markers are currently on screen is
 *   decided by the test rather than by any arithmetic. That is the honest
 *   shape, because every clustering decision the real library makes depends on
 *   projected pixel positions at a zoom level, and nothing here projects
 *   anything. Whether a group of markers really collapses into one bubble is a
 *   manual check.
 * - What IS reproduced, and cited, is the one behaviour the production code
 *   has to survive: a marker inside a cluster has no icon. `addLayer` gives a
 *   marker no element at all — unlike `StubMap.addLayer`, which does — and
 *   `group.uncluster( marker )` is what gives it one and fires `add` on it,
 *   after `Map._layerAdd`, which ends `...this.onAdd(i),this.fire("add"),
 *   i.fire("layeradd",{layer:this})` in assets/leaflet/leaflet.js.
 *   `group.recluster( marker )` is the other direction: the element goes back
 *   to null and `remove` fires, after the same function's
 *   `this.fire("layerremove",{layer:t}),t.fire("remove")`. Both names are this
 *   harness's; the events and the null icon are Leaflet's.
 * - `L.divIcon()` records the options it was handed and builds no icon. The
 *   branch that matters — `e.html instanceof Element?(me(t),t.appendChild(
 *   e.html)):t.innerHTML=!1!==e.html?e.html:""` in the vendored leaflet.js — is
 *   NOT executed here, and cannot be: there is no Element constructor in the
 *   sandbox and no parser behind innerHTML. So a case can prove what was passed
 *   to divIcon and cannot prove what Leaflet then did with it. Passing an
 *   element is the whole of the defence, which is why the cases assert the
 *   type of `options.html` rather than its rendering.
 *
 * Leaflet's popups, as Task 16 grew them
 * --------------------------------------
 * - `layer.bindPopup( content )` records the call and hangs a `StubPopup` on
 *   the layer. The popup has a content value, an open flag and a count of how
 *   many times its content was set, and that is the whole of it: it builds no
 *   container, no tip, no close button and no pane, measures nothing, and pans
 *   no map. Whether a bubble is visible, or fits on screen, is a manual check.
 * - ONE behaviour is a reproduction rather than an invention, and it is the
 *   one a case would otherwise have to fake: binding a popup binds a click
 *   handler, and clicking toggles. `bindPopup` in assets/leaflet/leaflet.js is
 *   `this._popup=this._initOverlay(Bi,this._popup,t,e),this._popupHandlersAdded
 *   ||(this.on({click:this._openPopup,keypress:this._onKeyPress,remove:this.
 *   closePopup,move:this._movePopup}),this._popupHandlersAdded=!0)`, and
 *   `_openPopup` ends `this._map.hasLayer(this._popup)?this.closePopup():
 *   this.openPopup(t.latlng)`. So `marker.fire( 'click' )` here opens a bound
 *   popup and a second click closes it, which is what a pointer on a pin does.
 *   What is NOT modelled from those two lines: `keypress`, `remove` and `move`
 *   bind nothing; `_openPopup`'s `_source` bookkeeping and its `_prepareOpen`
 *   are absent, so a popup here opens on a layer that was never added to a map
 *   and a real one would not; and there is no `latlng` on the event.
 * - `setPopupContent` is `this._popup&&this._popup.setContent(t)`, and
 *   `setContent` is `this._content=t,this.update(),this` — so the content is
 *   stored whether or not the popup is open, and the relayout is the part this
 *   cannot claim. `update()` is `this._map&&(...)`: a closed popup re-renders
 *   on its next open, an open one at once. `popup.updates` counts the sets,
 *   not the renders.
 * - The branch the whole of Task 16 turns on is Leaflet's and is NOT executed
 *   here, exactly as with `divIcon`: `_updateContent` is
 *   `if("string"==typeof e)t.innerHTML=e;else{for(;t.hasChildNodes();)
 *   t.removeChild(t.firstChild);t.appendChild(e)}` — byte offset 95938 of
 *   assets/leaflet/leaflet.js — and there is no parser behind innerHTML in
 *   this file and no Element constructor in the sandbox. So a case can prove
 *   what was handed to `bindPopup` and `setPopupContent`, and cannot prove
 *   what Leaflet did with it. Handing over a node instead of a string is the
 *   whole of the defence, which is why the cases assert `nodeType` on the
 *   content rather than its rendering, and why one case pins that byte
 *   sequence in the vendored file so a Leaflet that dropped the node branch
 *   fails here rather than on somebody's site.
 * - `popupopen` and `popupclose` fire nowhere. Nothing under test listens for
 *   them; a case that needed one would have to grow this file first.
 *
 * Leaflet's markers, as Task 18 grew them
 * ----------------------------------------
 * - `marker.getLatLng()` normalises, because Leaflet's does: `_latlng` is
 *   `w(t)` — toLatLng — and `2===t.length?new v(t[0],t[1])` turns the array
 *   `[ 52, 21 ]` this plugin passes into an object with `.lat` and `.lng`
 *   (byte offset 7498 of assets/leaflet/leaflet.js). A stub that handed the
 *   array back would let code index `[0]` and `[1]` and pass here while
 *   reading `undefined` in a browser. `calls.marker[ n ].latlng` is still the
 *   argument exactly as it was passed.
 * - `marker.setLatLng()` records and fires `move`, which is as much of
 *   `this._latlng=w(t),this.update(),this.fire("move",…)` as this can claim:
 *   `update()` repositions an icon in a DOM that was never built.
 * - NOTHING here drags anything. `draggable: true` is an option this file
 *   records and does not honour, there is no Draggable, no pointer and no
 *   pixel. A case fires `dragend` by hand after setting the position, which is
 *   what Leaflet's own MarkerDrag does in the other order: `_onDrag` assigns
 *   `e._latlng=o` and fires `move`/`drag`, and `_onDragEnd` then fires
 *   `moveend` and `dragend` — so by the time a `dragend` handler runs,
 *   `getLatLng()` already reports the new place. Whether a pointer could have
 *   produced that drag is a manual check.
 *
 * The admin screen, as Task 18 grew it
 * ------------------------------------
 * - `loadAdmin()` is a second entry point, not a second harness: the document,
 *   the Leaflet, the fetch and the clock are the same stubs, and `makeSandbox()`
 *   is one function so that the two worlds cannot quietly differ in what a
 *   script may reach for.
 * - It evaluates locator.js before admin.js, because that is what the enqueue
 *   does. So `window.SLOSM` in these cases is the real frozen namespace and not
 *   a hand-made one, and a case can prove that admin.js takes the tile url from
 *   it rather than carrying a second copy of the attribution.
 * - `metaboxMarkup()` is the hand-copy of Admin::render(), checked against the
 *   PHP by admin-picker.test.js exactly as `locatorMarkup()` is checked against
 *   the shortcode.
 * - `IntersectionObserver` is in the admin world and not in the front end's,
 *   because the picker is the only thing that asks whether it is on screen. It
 *   observes nothing by itself — a case delivers entries by hand — and the one
 *   browser rule it does model is that a disconnected observer delivers
 *   nothing, which is what separates "re-measures on every expansion" from
 *   "re-measures once and unhooks".
 * - What it still cannot ask, and this bounds the whole of Task 18's fix for a
 *   map built inside a hidden container: whether the map div ends up with a
 *   height. There is no layout here, no `clientWidth` and no viewport, so a
 *   case can prove that `invalidateSize()` was called at the right moment and
 *   can never prove that the pixels arrived. Nor whether a marker is visible,
 *   nor whether a browser would have let a pointer reach the pin. All of those
 *   stay manual checks.
 *
 * navigator.geolocation
 * ---------------------
 * - A stub with one method. `getCurrentPosition` records the call and answers
 *   from a queue the test fills; with nothing queued it answers never, which is
 *   the permission prompt sitting open on screen and is the state most visitors
 *   are in for a second or two. A test settles such a call by hand through
 *   `geolocationCalls[ n ].success( … )` or `.failure( … )`.
 * - A queued answer is delivered SYNCHRONOUSLY, inside the getCurrentPosition
 *   call. A browser always calls back later. Nothing under test may therefore
 *   do work after its own getCurrentPosition call — locateMe() deliberately
 *   ends on that line — and a case whose outcome depends on that ordering is
 *   testing this file rather than the code.
 * - There is no permission model, no prompt, no remembered decision, no secure
 *   context rule (a real browser refuses geolocation outright on http:), and no
 *   watchPosition. PositionOptions are recorded and never honoured: the
 *   `timeout` a case can read off the call is a number that was passed, and
 *   nothing here will ever fire it.
 * - The error a test delivers is a plain object with a `code`, where a browser
 *   passes a GeolocationPositionError. The numbering is the API's — 1 is
 *   PERMISSION_DENIED, 2 POSITION_UNAVAILABLE, 3 TIMEOUT — and code branching on
 *   `error.code` cannot tell the two apart, while code branching on the class
 *   would pass here and fail in a browser. Same shape of divergence as the
 *   AbortError below, recorded for the same reason.
 *
 * fetch
 * -----
 * - Responses come from a queue the test fills. An unqueued call rejects with
 *   a named error rather than hanging or returning a default, because a test
 *   that reaches an unplanned request should say so.
 * - A queued `deferredResponse()` does not settle until the test says so,
 *   which is the only way to write "the slow answer arrives after the fast
 *   one". A plain queued response settles immediately, so it cannot lose a
 *   race it was never in.
 * - `init.signal` is honoured, and this is the whole of the abort model: a
 *   call made with an already-aborted signal rejects at once, and a deferred
 *   response whose signal aborts *before the test resolves it* rejects
 *   instead. Aborting after that does nothing, which is the browser's rule
 *   and was not always this file's: it used to reject a response it had
 *   already handed over, because the wrapper promise was still pending for
 *   one microtask turn after the inner one resolved. That divergence sat
 *   exactly where locator.js's sequence numbers are supposed to earn their
 *   keep — the window between "answered" and "delivered" — so a case could
 *   have proved a guard against something no browser does. `delivered` is set
 *   synchronously by the test's own resolve() to close it.
 *   What is still not modelled: a real abort after delivery rejects the
 *   *body* promise, so `response.json()` on an aborted-but-delivered response
 *   throws where here it resolves. Nothing under test reads a body it has
 *   disowned, so that gap is recorded rather than filled.
 *   The rejection is an ordinary Error with `name` set to 'AbortError', where
 *   a browser throws a DOMException; code that branches on `error.name`
 *   cannot tell them apart, and code that branches on `instanceof
 *   DOMException` would pass here and fail in a browser. Nothing models the
 *   request actually being cancelled on the wire, because there is no wire.
 * - No redirects, no CORS, no streaming, no headers beyond what a test puts
 *   in the object. `Response` here is `{ ok, status, json(), text() }` and
 *   nothing more.
 *
 * Realms
 * ------
 * - node:vm gives the sandbox its own set of intrinsics, so an array or a
 *   plain object created inside locator.js does not share this file's
 *   Array.prototype or Object.prototype. assert.deepStrictEqual compares
 *   prototypes and reports "same structure but not reference-equal" on values
 *   that are equal in every sense a test cares about. `plain()` crosses that
 *   boundary. A browser has no such seam, so this is a fact about the harness
 *   and not about the code under test.
 *
 * NEVER deepStrictEqual A NODE OR A LAYER
 * ---------------------------------------
 * A StubElement reaches its `ownerDocument`, its `parentNode` and its map of
 * listeners; a StubLayer reaches its handlers. Every one of those closures
 * holds the locator instance, which holds the map, the entries, the payload
 * and the config. So structurally comparing two nodes walks the whole document
 * and everything the front end built on it.
 *
 * The failure mode is the wrong way round. When the references match,
 * deepStrictEqual short-circuits and the assertion is instant; when they do
 * not — which is the only time the assertion matters — it walks that graph and
 * takes the process out with "JavaScript heap out of memory", with no case
 * name anywhere in the output. A mutation was caught that way once, as an
 * abort rather than as a named failure, which is the same thing as not being
 * caught by anybody reading the log.
 *
 * So: `assert.ok( a === b, 'message' )` for node identity, and
 * deepStrictEqual only for strings, numbers and the arrays of them that
 * `plain()` produces.
 *
 * Timing
 * ------
 * - Promises settle on the real microtask queue. Task 13 said that when a
 *   later task debounced, this harness would need a clock and would have to
 *   grow one rather than sleep. It has: `setTimeout` and `clearTimeout` in the
 *   sandbox are a fake clock, and `harness.clock.tick( ms )` is the only thing
 *   that makes time pass. No test here waits for a real millisecond.
 * - The two queues do not interleave. `tick()` runs every callback that came
 *   due, synchronously, in scheduled order, including ones scheduled by a
 *   callback it just ran; a promise chained inside one of them settles after
 *   `tick()` has returned, so a case that needs the chain awaits afterwards.
 *   A browser interleaves microtasks between timer callbacks. Any test whose
 *   outcome depends on that ordering is testing the harness, not the code.
 * - There is no `Date` in the sandbox at all, and that is deliberate rather
 *   than an omission: a debounce measured against a clock the test cannot
 *   move would be a slow test when it passed and a flaky one when it failed.
 *   `clock.now` is the fake time, and nothing under test can read it.
 */

'use strict';

const fs = require( 'node:fs' );
const path = require( 'node:path' );
const vm = require( 'node:vm' );

const ROOT = path.resolve( __dirname, '..', '..' );
const LOCATOR_PATH = path.join( ROOT, 'assets', 'js', 'locator.js' );
const ADMIN_PATH = path.join( ROOT, 'assets', 'js', 'admin.js' );
const SHORTCODE_PATH = path.join( ROOT, 'assets', 'js', 'shortcode.js' );

/**
 * Object.assign, except that an explicit `undefined` does not overwrite.
 *
 * Object.assign copies undefined like any other value, so
 * `{ config: settings.config }` built from an options object that never
 * mentioned `config` wipes the default out. That is not a hypothetical: it
 * silently turned every fixture in locator.test.js into the string
 * "undefined" in a data- attribute, and every case then asserted against a
 * malformed-config path instead of the one it named.
 *
 * @param {object} base      Defaults.
 * @param {object} overrides Values to apply; undefined ones are skipped.
 * @returns {object} A new object.
 */
function assign( base, overrides ) {
	const result = Object.assign( {}, base );

	Object.keys( overrides || {} ).forEach( ( key ) => {
		if ( undefined !== overrides[ key ] ) {
			result[ key ] = overrides[ key ];
		}
	} );

	return result;
}

/**
 * A value from inside the sandbox, rebuilt with this realm's intrinsics.
 *
 * node:vm gives the sandbox its own realm, so an array literal evaluated
 * inside locator.js has the sandbox's Array.prototype and not this file's.
 * assert.deepStrictEqual compares prototypes, so it reports "same structure
 * but not reference-equal" on values that are in every meaningful sense
 * equal. Passing them through json is the cheapest honest crossing.
 *
 * What it cannot carry: functions, undefined, NaN, Infinity and cycles. A
 * case comparing any of those has to reach for strictEqual on the pieces
 * instead.
 *
 * @param {*} value Anything json can represent.
 * @returns {*} The same value, in this realm.
 */
function plain( value ) {
	return JSON.parse( JSON.stringify( value ) );
}

/**
 * A text node. The only node type besides an element.
 */
class StubText {
	constructor( data ) {
		this.nodeType = 3;
		this.data = String( data );
		this.parentNode = null;
	}

	get textContent() {
		return this.data;
	}

	set textContent( value ) {
		this.data = String( value );
	}

	cloneNode() {
		return new StubText( this.data );
	}
}

/**
 * Event types that do not bubble.
 *
 * A table rather than a per-interface fact, and right only for the types this
 * suite uses. focus and blur do not bubble (focusin and focusout do);
 * mouseenter and mouseleave do not (mouseover and mouseout do). Everything
 * else here is treated as bubbling, which is true of input, keydown, click,
 * mousedown and the rest of what the locator listens for.
 */
const NON_BUBBLING = new Set( [ 'focus', 'blur', 'mouseenter', 'mouseleave' ] );

/**
 * Splits a selector into the one shape this harness understands.
 *
 * Stricter than a browser on purpose: `.foo` and nothing else. A selector the
 * production code starts to rely on has to be added here deliberately.
 *
 * @param {string} selector Selector.
 * @returns {string} The class name.
 */
function classFromSelector( selector ) {
	if ( typeof selector !== 'string' || ! /^\.[A-Za-z0-9_-]+$/.test( selector ) ) {
		throw new Error(
			'the harness DOM understands only single-class selectors like ".slosm__map", got: ' +
				String( selector )
		);
	}

	return selector.slice( 1 );
}

/**
 * An element.
 */
class StubElement {
	constructor( tagName, ownerDocument ) {
		this.tagName = String( tagName ).toUpperCase();
		this.ownerDocument = ownerDocument || null;
		this.nodeType = 1;
		this.parentNode = null;
		this.childNodes = [];
		this.attributes = new Map();
		// Not a real CSSStyleDeclaration: a plain bag, so a test can read back
		// what was set. Nothing computes, nothing cascades, nothing renders.
		this.style = {};
		// Every element has one, because this stub has no interfaces. On an
		// <input> it stands in for the value IDL property; on a <div> a browser
		// would have no such thing at all.
		this.value = '';
		// How many times select() was called on this element. See select().
		this.selections = 0;
		this.listeners = new Map();

		// A <template>'s contents live outside childNodes, which is the whole
		// of its inertness here: a walk of the tree does not reach them, so a
		// selector does not match them — the same outcome a real template's
		// separate contents-owner document produces.
		if ( 'TEMPLATE' === this.tagName ) {
			this.content = new StubFragment( ownerDocument || null );
		}

		const classes = new Set();

		this.classList = {
			add: ( ...names ) => names.forEach( ( name ) => classes.add( name ) ),
			remove: ( ...names ) => names.forEach( ( name ) => classes.delete( name ) ),
			contains: ( name ) => classes.has( name ),
			get length() {
				return classes.size;
			},
		};

		Object.defineProperty( this, 'className', {
			get: () => Array.from( classes ).join( ' ' ),
			set: ( value ) => {
				classes.clear();
				String( value )
					.split( /\s+/ )
					.filter( Boolean )
					.forEach( ( name ) => classes.add( name ) );
			},
		} );

		// The tripwire. There is no innerHTML in this harness, in either
		// direction, so any path from server data to markup parsing is a
		// thrown error in the case that took it rather than a silent pass.
		Object.defineProperty( this, 'innerHTML', {
			get() {
				throw new Error( 'innerHTML is not available in the harness DOM: read textContent' );
			},
			set() {
				throw new Error(
					'innerHTML is not available in the harness DOM: server data must be written with textContent'
				);
			},
		} );
	}

	get children() {
		return this.childNodes.filter( ( node ) => 1 === node.nodeType );
	}

	get firstChild() {
		return this.childNodes[ 0 ] || null;
	}

	get firstElementChild() {
		return this.children[ 0 ] || null;
	}

	get nextSibling() {
		if ( ! this.parentNode ) {
			return null;
		}

		return this.parentNode.childNodes[ this.parentNode.childNodes.indexOf( this ) + 1 ] || null;
	}

	get textContent() {
		return this.childNodes.map( ( node ) => node.textContent ).join( '' );
	}

	set textContent( value ) {
		this.childNodes.forEach( ( node ) => {
			node.parentNode = null;
		} );
		this.childNodes = [];

		if ( '' !== String( value ) ) {
			this.appendChild( new StubText( value ) );
		}
	}

	appendChild( node ) {
		if ( node.parentNode ) {
			node.parentNode.removeChild( node );
		}

		node.parentNode = this;
		this.childNodes.push( node );

		return node;
	}

	/**
	 * Puts a node in front of one of this element's children.
	 *
	 * A null reference appends, as the real one does. A reference that is not
	 * a child throws, as the real one does — with a plain Error rather than a
	 * NotFoundError DOMException, which is the same distinction the abort
	 * error makes: the shape is not the browser's, the refusal is.
	 *
	 * @param {object}      node      Node to insert.
	 * @param {object|null} reference Child to insert in front of, or null.
	 * @returns {object} The inserted node.
	 */
	insertBefore( node, reference ) {
		if ( null === reference || undefined === reference ) {
			return this.appendChild( node );
		}

		const at = this.childNodes.indexOf( reference );

		if ( -1 === at ) {
			throw new Error( 'insertBefore: the reference node is not a child of this element' );
		}

		if ( node.parentNode ) {
			node.parentNode.removeChild( node );
		}

		node.parentNode = this;
		this.childNodes.splice( this.childNodes.indexOf( reference ), 0, node );

		return node;
	}

	/**
	 * Counts a select() and models nothing else about selection.
	 *
	 * There is no selection in this harness — no range, no anchor, no
	 * `document.getSelection()`, and no relationship between this call and
	 * what `execCommand( 'copy' )` would have copied. A case asserting the
	 * text was selected is asserting that the code called this method, which
	 * is the most it can honestly claim; whether a browser then had something
	 * on the clipboard is a manual check.
	 *
	 * It is on every element, like `value` and for the same reason: there is
	 * no per-interface table here. In a browser, calling select() on a `<div>`
	 * does nothing at all.
	 *
	 * @returns {void}
	 */
	select() {
		this.selections++;
	}

	removeChild( node ) {
		const at = this.childNodes.indexOf( node );

		if ( -1 === at ) {
			throw new Error( 'removeChild: the node is not a child of this element' );
		}

		this.childNodes.splice( at, 1 );
		node.parentNode = null;

		return node;
	}

	/**
	 * A copy of this element, deep or shallow.
	 *
	 * Tag, attributes, classes, the `value` property and — when deep — every
	 * descendant. Event listeners are deliberately NOT copied, because the
	 * real cloneNode does not copy them either: a row cloned from the template
	 * arrives inert and something has to bind it, and a stub that carried the
	 * listeners over would let a test pass against code that binds nothing.
	 *
	 * @param {boolean} deep Whether to copy descendants.
	 * @returns {StubElement} The copy.
	 */
	cloneNode( deep ) {
		const copy = new StubElement( this.tagName, this.ownerDocument );

		this.attributes.forEach( ( value, name ) => copy.setAttribute( name, value ) );
		copy.className = this.className;
		copy.value = this.value;

		if ( this.content ) {
			copy.content = this.content.cloneNode( true );
		}

		if ( deep ) {
			this.childNodes.forEach( ( child ) => copy.appendChild( child.cloneNode( true ) ) );
		}

		return copy;
	}

	addEventListener( type, handler ) {
		if ( ! this.listeners.has( type ) ) {
			this.listeners.set( type, [] );
		}

		this.listeners.get( type ).push( handler );
	}

	removeEventListener( type, handler ) {
		const list = this.listeners.get( type ) || [];
		const at = list.indexOf( handler );

		if ( -1 !== at ) {
			list.splice( at, 1 );
		}
	}

	/**
	 * Fires one event at this element and up its ancestors.
	 *
	 * Bubble phase only: there is no capture here, and nothing has a default
	 * action for `preventDefault()` to cancel — the flag is observable and
	 * nothing else. The path ends at the outermost element, so an event never
	 * reaches the document or the window.
	 *
	 * @param {object} event `{ type }` plus whatever the handler reads.
	 * @returns {boolean} False when a handler called preventDefault().
	 */
	dispatchEvent( event ) {
		const detail = Object.assign( {}, event );
		let stopped = false;

		// Read off the object rather than copied with it. A plain `{ type }` —
		// which is what most cases dispatch — copies fine, but a real `Event`
		// keeps `type` on its prototype, so Object.assign takes nothing and the
		// listener lookup would miss every event a production file constructed
		// properly. admin.js fires `new window.Event( 'change' )`, which is the
		// only way a script can tell a page that it wrote into a field.
		detail.type = event.type;
		detail.target = this;
		detail.defaultPrevented = false;
		detail.bubbles = NON_BUBBLING.has( detail.type ) ? false : true;
		detail.preventDefault = () => {
			detail.defaultPrevented = true;
		};
		detail.stopPropagation = () => {
			stopped = true;
		};

		let node = this;

		while ( node ) {
			detail.currentTarget = node;

			( node.listeners.get( detail.type ) || [] ).slice().forEach( ( handler ) => {
				handler.call( node, detail );
			} );

			if ( stopped || ! detail.bubbles ) {
				break;
			}

			node = node.parentNode;
		}

		// Handed back so a test can read defaultPrevented off the very object
		// the handlers saw, rather than a copy of it.
		event.detail = detail;

		return ! detail.defaultPrevented;
	}

	/**
	 * Whether this node is still in its document, the way the DOM means it.
	 *
	 * `Node.isConnected` is true when the node's root is the document —
	 * which is a stronger statement than "has a parent". A node deep inside
	 * a subtree that was detached whole still has a parent, and every one of
	 * its ancestors does too; what it does not have is a path to the
	 * document. That is exactly the shape a page builder produces when it
	 * drops an element wrapper, so the walk is the thing worth modelling and
	 * `null === parentNode` is not.
	 *
	 * The root is compared with `documentElement` rather than with the
	 * document, because in this harness `documentElement.parentNode` is null:
	 * the document owns it as a property and is not itself a node in the
	 * chain.
	 *
	 * @returns {boolean} True when the document can be reached from here.
	 */
	get isConnected() {
		let node = this;

		while ( node.parentNode ) {
			node = node.parentNode;
		}

		return !! this.ownerDocument && node === this.ownerDocument.documentElement;
	}

	/**
	 * Focus, as far as this harness has any.
	 *
	 * Records the element on the document and fires `focus`. No focusability
	 * rule, no tab order, no scrolling into view: this works on a <div>, and
	 * a browser would ignore it.
	 *
	 * @returns {void}
	 */
	focus() {
		if ( this.ownerDocument ) {
			this.ownerDocument.activeElement = this;
		}

		this.dispatchEvent( { type: 'focus' } );
	}

	blur() {
		if ( this.ownerDocument && this.ownerDocument.activeElement === this ) {
			this.ownerDocument.activeElement = null;
		}

		this.dispatchEvent( { type: 'blur' } );
	}

	setAttribute( name, value ) {
		this.attributes.set( String( name ), String( value ) );
	}

	getAttribute( name ) {
		// null for a missing attribute, as the real one does. Not undefined,
		// not '' — the difference is the whole of "there is no config here".
		return this.attributes.has( String( name ) ) ? this.attributes.get( String( name ) ) : null;
	}

	hasAttribute( name ) {
		return this.attributes.has( String( name ) );
	}

	removeAttribute( name ) {
		this.attributes.delete( String( name ) );
	}

	querySelectorAll( selector ) {
		const wanted = classFromSelector( selector );
		const found = [];

		const walk = ( node ) => {
			node.children.forEach( ( child ) => {
				if ( child.classList.contains( wanted ) ) {
					found.push( child );
				}

				walk( child );
			} );
		};

		walk( this );

		return found;
	}

	querySelector( selector ) {
		return this.querySelectorAll( selector )[ 0 ] || null;
	}
}

/**
 * A `<template>`'s contents.
 *
 * A StubElement underneath, because the tree walking and the selector engine
 * are the same code and duplicating them is how two implementations come to
 * disagree. What a DocumentFragment does not have is taken away again rather
 * than left lying about: attributes and classes throw here, so code that
 * treats the fragment as an element fails in the case that did it instead of
 * passing against a stub that was more generous than a browser.
 */
class StubFragment extends StubElement {
	constructor( ownerDocument ) {
		super( '#document-fragment', ownerDocument );

		this.nodeType = 11;
	}

	setAttribute() {
		throw new Error( 'a DocumentFragment has no attributes' );
	}

	getAttribute() {
		throw new Error( 'a DocumentFragment has no attributes' );
	}

	cloneNode( deep ) {
		const copy = new StubFragment( this.ownerDocument );

		if ( deep ) {
			this.childNodes.forEach( ( child ) => copy.appendChild( child.cloneNode( true ) ) );
		}

		return copy;
	}
}

/**
 * A document: a root element plus the two hooks the locator script uses.
 */
class StubDocument {
	constructor() {
		this.nodeType = 9;
		// 'interactive' is what a deferred script sees: the HTML spec sets
		// readyState to "interactive" when parsing ends, then runs deferred
		// scripts, then fires DOMContentLoaded. A test that wants the other
		// branch sets this to 'loading' before loading the file.
		this.readyState = 'interactive';
		this.documentElement = new StubElement( 'html', this );
		this.body = new StubElement( 'body', this );
		this.documentElement.appendChild( this.body );
		this.listeners = new Map();
		// Whatever focus() was called on last, and nothing more: no initial
		// body, no focusability rule, no tab order.
		this.activeElement = null;
	}

	/**
	 * The element carrying this id attribute, or null.
	 *
	 * A walk, not an index. It is what makes an `aria-activedescendant`
	 * assertion mean something: a case can resolve the id the code wrote and
	 * check it lands on the option it names, rather than comparing two strings
	 * the same code produced.
	 *
	 * @param {string} id The id.
	 * @returns {StubElement|null} The element.
	 */
	getElementById( id ) {
		const wanted = String( id );
		let found = null;

		const walk = ( node ) => {
			node.children.forEach( ( child ) => {
				if ( null === found && child.getAttribute( 'id' ) === wanted ) {
					found = child;
				}

				walk( child );
			} );
		};

		walk( this.documentElement );

		return found;
	}

	createElement( tagName ) {
		return new StubElement( tagName, this );
	}

	createTextNode( data ) {
		return new StubText( data );
	}

	addEventListener( type, handler ) {
		if ( ! this.listeners.has( type ) ) {
			this.listeners.set( type, [] );
		}

		this.listeners.get( type ).push( handler );
	}

	removeEventListener( type, handler ) {
		const list = this.listeners.get( type ) || [];
		const at = list.indexOf( handler );

		if ( -1 !== at ) {
			list.splice( at, 1 );
		}
	}

	/**
	 * Fires one event. No bubbling, no capture, no Event object worth the
	 * name — a `{ type }` and nothing else, because that is all the code
	 * under test reads.
	 *
	 * @param {string} type Event type.
	 * @returns {number} How many listeners ran.
	 */
	dispatchEvent( type ) {
		const list = ( this.listeners.get( type ) || [] ).slice();

		list.forEach( ( handler ) => handler( { type } ) );

		return list.length;
	}

	querySelectorAll( selector ) {
		return this.documentElement.querySelectorAll( selector );
	}

	querySelector( selector ) {
		return this.documentElement.querySelector( selector );
	}
}

/**
 * The Leaflet stand-in, plus the log of everything asked of it.
 *
 * @returns {{L: object, calls: object, mapErrors: Array}} The library, its call log and its error queue.
 */
function makeLeaflet( ownerDocument ) {
	/**
	 * Errors L.map() throws instead of returning a map, oldest first.
	 *
	 * Queue-shaped like the fetch queue, and for the same reason: Leaflet
	 * really does throw out of L.map(). Map._initContainer throws
	 * 'Map container not found.' and 'Map container is already initialized.',
	 * both read in assets/leaflet/leaflet.js. Until a reviewer went looking,
	 * nothing here could produce either, and one of them took every locator
	 * after it on the page down with it.
	 *
	 * @var {Array<Error|null>}
	 */
	const mapErrors = [];

	/**
	 * The layer whose popup is currently open, or null.
	 *
	 * One per sandbox, which is what a map is: Leaflet keeps `map._popup` and
	 * closes it before opening another. See StubLayer.openPopup().
	 */
	let openPopupHolder = null;

	const calls = {
		map: [],
		tileLayer: [],
		marker: [],
		setView: [],
		fitBounds: [],
		fitWorld: [],
		addTo: [],
		removeLayer: [],
		markerClusterGroup: [],
		divIcon: [],
		clusterAdd: [],
		clusterRemove: [],
		clusterClear: [],
		clusterZoom: [],
		bindPopup: [],
		openPopup: [],
		closePopup: [],
		setPopupContent: [],
		setLatLng: [],
		invalidateSize: [],
		mapRemove: [],
	};

	/**
	 * A Popup: a content value, an open flag and a count of the sets.
	 *
	 * Nothing here renders. `setContent` is Leaflet's
	 * `this._content=t,this.update(),this` with the update left out, because
	 * the update is the part that builds a container and measures it and this
	 * file has neither a layout nor a parser. See the header.
	 */
	class StubPopup {
		constructor( content ) {
			this.open = false;
			// How many times the content was set, including the bind. A case
			// that counts renders would be counting something this file does
			// not do; a case that counts sets is counting the calls the code
			// under test really made.
			this.updates = 0;
			this.content = null;

			/*
			 * The DOM Leaflet builds, modelled because Task 24b reaches into
			 * it. _initLayout in assets/leaflet/leaflet.js puts a
			 * `leaflet-popup-content-wrapper` inside `this._container`, a
			 * `leaflet-popup-content` inside that, and — when
			 * `options.closeButton`, which is the default — an
			 * `<a class="leaflet-popup-close-button">` carrying
			 * `role="button"` and `aria-label="Close popup"`, hardcoded in
			 * English with no translation reaching it. Read there rather than
			 * remembered, because that English label is the thing Task 24b
			 * replaces and a stub that invented it would be testing itself.
			 *
			 * What is deliberately not modelled: the tip and its container,
			 * the wrapper's other classes, the pane the container is put in,
			 * and the fact that a real popup's container is removed from the
			 * document when it closes. Nothing under test reads any of those.
			 * The last one is worth naming: a case about where the focus lands
			 * *after* a close cannot be written against this stub, because
			 * here the container stays in hand.
			 */
			this.element = null;
			this.contentNode = null;
			this.closeButton = null;

			if ( ownerDocument ) {
				const wrapper = ownerDocument.createElement( 'div' );

				this.element = ownerDocument.createElement( 'div' );
				this.element.className = 'leaflet-popup';

				wrapper.className = 'leaflet-popup-content-wrapper';

				this.contentNode = ownerDocument.createElement( 'div' );
				this.contentNode.className = 'leaflet-popup-content';

				wrapper.appendChild( this.contentNode );
				this.element.appendChild( wrapper );

				this.closeButton = ownerDocument.createElement( 'a' );
				this.closeButton.className = 'leaflet-popup-close-button';
				this.closeButton.setAttribute( 'role', 'button' );
				this.closeButton.setAttribute( 'aria-label', 'Close popup' );
				this.element.appendChild( this.closeButton );
			}

			this.setContent( content );
		}

		setContent( content ) {
			this.content = content;
			this.updates++;

			// _updateContent empties the content node and appends what it was
			// given. A string takes another path, which this plugin never
			// takes and popup.test.js has a case about.
			if ( this.contentNode && content && 'object' === typeof content ) {
				while ( this.contentNode.firstChild ) {
					this.contentNode.removeChild( this.contentNode.firstChild );
				}

				this.contentNode.appendChild( content );
			}

			return this;
		}

		getContent() {
			return this.content;
		}

		/**
		 * The popup's own container, the way Leaflet hands it over.
		 *
		 * `getElement:function(){return this._container}` on the DivOverlay a
		 * Popup extends.
		 *
		 * @returns {object|null} The container, or null with no document.
		 */
		getElement() {
			return this.element;
		}

		isOpen() {
			return this.open;
		}
	}

	class StubMap {
		constructor( container, options ) {
			this.container = container;
			this.options = options || {};
			// Named after Leaflet's own flag, but the rule attached to it is
			// this harness's, not Leaflet's: real Leaflet defers an early
			// layer add rather than refusing it. See the file header.
			this._loaded = false;
			// Not Leaflet's `_zoom`, which is a projected internal. This one
			// holds only what setView was handed; getZoom() says what that
			// does and does not mean.
			this._zoom = undefined;
			this.layers = [];
		}

		setView( center, zoom, options ) {
			calls.setView.push( { center, zoom, options } );
			this._loaded = true;
			this._zoom = zoom;

			return this;
		}

		/**
		 * The zoom this map was last explicitly given.
		 *
		 * Grown for Task 32b, and the one thing worth reading before trusting
		 * it: **fitBounds does not change it here, and in a browser it would.**
		 * A real fitBounds picks the closest zoom that fits the bounds in the
		 * viewport, and this harness has no viewport, no geometry and no
		 * projection — the file header says so at length. So after a
		 * fitBounds this answers with the zoom of the last setView, which is
		 * not what Leaflet would answer.
		 *
		 * What that costs is worth naming rather than discovering: a case that
		 * turns on the zoom *after* a frame cannot be written here at all. It
		 * is a manual check. What can be written is every case that turns on
		 * the zoom somebody set — which is the whole of what the code under
		 * test reads it for.
		 *
		 * null before any view has been set, and the code under test guards
		 * for it: a map is always given a view in initLocator(), so this is a
		 * floor under a case nobody can reach rather than a real state.
		 *
		 * @returns {number|null} The zoom, or null.
		 */
		getZoom() {
			return undefined === this._zoom ? null : this._zoom;
		}

		fitBounds( bounds, options ) {
			calls.fitBounds.push( { bounds, options } );

			if ( ! Array.isArray( bounds ) || 0 === bounds.length ) {
				throw new Error( 'Bounds are not valid.' );
			}

			this._loaded = true;

			return this;
		}

		fitWorld( options ) {
			calls.fitWorld.push( { options } );
			this._loaded = true;

			return this;
		}

		addLayer( layer ) {
			// A house rule, not Leaflet's. Leaflet would queue this layer for
			// the 'load' event instead. The message is Leaflet's wording so a
			// failure is searchable, which is also how the mistake survived
			// review the first time; the header now says plainly that it is
			// invented.
			if ( ! this._loaded ) {
				throw new Error( 'Set map center and zoom first. (harness house rule, not Leaflet)' );
			}

			this.layers.push( layer );

			// Leaflet's Marker._initIcon builds the icon when the layer is
			// added, and getElement() hands that element back. Before this
			// point there is no element, which is the real behaviour and not a
			// house rule: `getElement:function(){return this._icon}` and
			// `this._icon` is assigned in _initIcon.
			if ( 'marker' === layer.kind && null === layer.element && ownerDocument ) {
				layer.element = ownerDocument.createElement( 'img' );
				layer.element.className = 'leaflet-marker-icon';
			}

			return this;
		}

		/**
		 * Drops a layer from this map.
		 *
		 * As much of Leaflet's `removeLayer` as this can honestly claim: the
		 * layer is off the map and its icon is gone —
		 * `_removeIcon:function(){...this._icon=null}`. No layerremove event,
		 * no onRemove, no DOM detach.
		 *
		 * @param {object} layer The layer.
		 * @returns {StubMap} This map.
		 */
		removeLayer( layer ) {
			const at = this.layers.indexOf( layer );

			calls.removeLayer.push( { layer, map: this } );

			if ( -1 !== at ) {
				this.layers.splice( at, 1 );
			}

			layer.element = null;

			return this;
		}

		/**
		 * Re-measures the container, as far as this can claim to.
		 *
		 * Recorded, not performed: there is no layout here, so nothing can be
		 * measured. The one behaviour reproduced is the guard the real method
		 * opens with — `invalidateSize:function(t){if(!this._loaded)return
		 * this;…}`, byte offset 32500 of assets/leaflet/leaflet.js — because a
		 * call before the first setView does nothing in a browser and must do
		 * nothing here, or a case could prove a recovery that never happens.
		 *
		 * What is NOT modelled is the whole point of the real method:
		 * `this._sizeChanged=!0` followed by a second getSize(), which is what
		 * re-reads clientWidth and clientHeight. Whether the container had a
		 * size by then is the question this file cannot ask at all.
		 *
		 * @param {*} options Recorded; a boolean means `animate` in Leaflet.
		 * @returns {StubMap} This map.
		 */
		invalidateSize( options ) {
			if ( ! this._loaded ) {
				return this;
			}

			calls.invalidateSize.push( { map: this, options: undefined === options ? null : options } );

			return this;
		}

		/**
		 * Leaflet's teardown, as far as this file can honestly model it.
		 *
		 * Recorded and emptied, and that is all. What the real `Map.remove()`
		 * does that matters — taking the `resize` listener back off `window`,
		 * clearing `_leaflet_id` off the container, removing the panes — this
		 * harness has no window listeners, no ids and no panes to undo, so
		 * modelling it would be inventing a result rather than measuring one.
		 *
		 * What a case can honestly say with this is **that the call was
		 * made**, which is the whole of the defect it exists for: nothing
		 * called it.
		 *
		 * @returns {StubMap} This map.
		 */
		remove() {
			calls.mapRemove.push( { map: this } );

			this.layers = [];

			return this;
		}
	}

	class StubLayer {
		constructor( kind, args ) {
			this.kind = kind;
			this.handlers = new Map();
			// Null until this layer is on a map; see StubMap.addLayer.
			this.element = null;
			// Null until something binds one; see bindPopup below.
			this.popup = null;
			this._popupHandlersAdded = false;
			Object.assign( this, args );
		}

		/**
		 * Evented.on, in the two-argument shape.
		 *
		 * @param {string}   type    Event type.
		 * @param {Function} handler Handler.
		 * @returns {StubLayer} This layer.
		 */
		on( type, handler ) {
			if ( ! this.handlers.has( type ) ) {
				this.handlers.set( type, [] );
			}

			this.handlers.get( type ).push( handler );

			return this;
		}

		off( type, handler ) {
			const list = this.handlers.get( type ) || [];
			const at = list.indexOf( handler );

			if ( -1 !== at ) {
				list.splice( at, 1 );
			}

			return this;
		}

		/**
		 * Fires an event at this layer, with a payload when there is one.
		 *
		 * `Evented.fire( type, data )` merges data into the event object, and
		 * Task 24b needs it: Leaflet's popupopen carries the popup that opened
		 * — `t.fire("popupopen",{popup:this})` — and a handler reads it off
		 * the event rather than off the layer.
		 *
		 * @param {string} type Event type.
		 * @param {object} data Extra properties for the event object.
		 * @returns {StubLayer} This layer.
		 */
		fire( type, data ) {
			( this.handlers.get( type ) || [] ).slice().forEach( ( handler ) => {
				handler( Object.assign( { type, target: this }, data || {} ) );
			} );

			return this;
		}

		getElement() {
			return this.element;
		}

		/**
		 * Where this layer is, normalised the way Leaflet normalises it.
		 *
		 * `getLatLng:function(){return this._latlng}`, and `_latlng` is
		 * `w(t)` — `toLatLng` — which turns a two-element array into a LatLng
		 * with `.lat` and `.lng`: `d(t)&&"object"!=typeof t[0] ? ... 2===
		 * t.length?new v(t[0],t[1]) ...`, byte offset 7498 of
		 * assets/leaflet/leaflet.js.
		 *
		 * The normalisation is the whole point of modelling this at all.
		 * `L.marker( [ 52, 21 ] ).getLatLng()` hands back an object, not the
		 * array that went in, so code reading `[0]` and `[1]` off it would
		 * work against a stub that stored the array verbatim and produce
		 * `undefined` in a browser. `this.latlng` itself is left exactly as
		 * it was passed, because the existing cases read the constructor
		 * argument off `calls.marker[ n ].latlng`.
		 *
		 * Not modelled: the third `alt` component, the LatLng prototype and
		 * every method on it, and the `null` a malformed argument produces.
		 *
		 * @returns {object|null} `{ lat, lng }`, or null when there is none.
		 */
		getLatLng() {
			const value = this.latlng;

			if ( Array.isArray( value ) && 2 === value.length ) {
				return { lat: value[ 0 ], lng: value[ 1 ] };
			}

			if ( value && 'object' === typeof value && 'lat' in value ) {
				return { lat: value.lat, lng: value.lng };
			}

			return null;
		}

		/**
		 * Moves this layer, and records the move.
		 *
		 * `setLatLng:function(t){var e=this._latlng;return this._latlng=w(t),
		 * this.update(),this.fire("move",{oldLatLng:e,latlng:this._latlng})}`.
		 * The `move` event is fired here because it is one line of the same
		 * expression; `update()` is not, because it repositions an icon in a
		 * DOM this file does not build.
		 *
		 * @param {*} latlng Where to move to.
		 * @returns {StubLayer} This layer.
		 */
		setLatLng( latlng ) {
			calls.setLatLng.push( { kind: this.kind, layer: this, latlng } );

			this.latlng = latlng;
			this.fire( 'move' );

			return this;
		}

		/**
		 * Binds a popup, and — the one thing this does beyond recording —
		 * binds the click handler Leaflet binds with it.
		 *
		 * `this.on({click:this._openPopup,…}),this._popupHandlersAdded=!0` in
		 * the vendored file, once per layer. Without it a case would have to
		 * open the popup by hand and would then be asserting that the harness
		 * opens popups rather than that a pointer on a pin does.
		 *
		 * @param {*}      content Content; an Element on every path this
		 *                         plugin takes, and a string on the path it
		 *                         must never take.
		 * @param {object} options Popup options.
		 * @returns {StubLayer} This layer.
		 */
		bindPopup( content, options ) {
			calls.bindPopup.push( { kind: this.kind, layer: this, content, options: options || null } );

			this.popup = new StubPopup( content );

			if ( ! this._popupHandlersAdded ) {
				this._popupHandlersAdded = true;

				this.on( 'click', () => {
					// `_openPopup` ends `this._map.hasLayer(this._popup)?
					// this.closePopup():this.openPopup(t.latlng)`. The toggle
					// is real; the map check it is written as is not modelled.
					if ( this.popup && this.popup.open ) {
						this.closePopup();
					} else {
						this.openPopup();
					}
				} );
			}

			return this;
		}

		/*
		 * Both of these fire on the source layer, which is what Leaflet does
		 * as well as firing on the map: `t.fire("popupopen",{popup:this}),
		 * this._source&&(this._source.fire("popupopen",{popup:this},!0)` in
		 * assets/leaflet/leaflet.js, and the same shape for popupclose. The
		 * map half is not modelled, because nothing under test listens there —
		 * attachPopup() has the marker in hand and listens on it.
		 */
		/**
		 * Opens this layer's popup, closing whichever one was open.
		 *
		 * The closing half is Task 34's growth and is not decoration: Leaflet's
		 * `Popup.options.autoClose` defaults to true and `Map.openPopup` opens
		 * with `this.closePopup()` ahead of it, so a second row press produces
		 * **popupclose for the old popup and then popupopen for the new one**,
		 * in that order, from one call. A harness that only ever fired
		 * popupopen could not see anything that goes wrong between those two
		 * events — and something did.
		 *
		 * What is still not modelled: `autoClose: false`, which nothing in
		 * this plugin sets; the pane the popup is moved into; and the animation
		 * either event is fired around. The ordering is the whole of what this
		 * reproduces, because the ordering is the whole of what the code under
		 * test has to survive.
		 *
		 * @returns {StubLayer} This layer.
		 */
		openPopup() {
			calls.openPopup.push( { kind: this.kind, layer: this } );

			if ( this.popup ) {
				if ( openPopupHolder && openPopupHolder !== this && openPopupHolder.popup && openPopupHolder.popup.open ) {
					openPopupHolder.closePopup();
				}

				openPopupHolder = this;
				this.popup.open = true;
				this.fire( 'popupopen', { popup: this.popup } );
			}

			return this;
		}

		closePopup() {
			calls.closePopup.push( { kind: this.kind, layer: this } );

			if ( openPopupHolder === this ) {
				openPopupHolder = null;
			}

			if ( this.popup ) {
				this.popup.open = false;
				this.fire( 'popupclose', { popup: this.popup } );
			}

			return this;
		}

		isPopupOpen() {
			return !! this.popup && this.popup.open;
		}

		getPopup() {
			return this.popup;
		}

		/**
		 * Replaces the bound popup's content.
		 *
		 * `setPopupContent:function(t){return this._popup&&this._popup.
		 * setContent(t),this}` — so on a layer with no popup this does
		 * nothing and does not throw, which is the real behaviour and is the
		 * shape the code under test relies on.
		 *
		 * @param {*} content The new content.
		 * @returns {StubLayer} This layer.
		 */
		setPopupContent( content ) {
			calls.setPopupContent.push( { kind: this.kind, layer: this, content } );

			if ( this.popup ) {
				this.popup.setContent( content );
			}

			return this;
		}

		addTo( map ) {
			calls.addTo.push( { kind: this.kind, layer: this, map } );
			map.addLayer( this );

			return this;
		}
	}

	/**
	 * A marker cluster group that clusters nothing.
	 *
	 * A bag with a log, and the two things the production code actually has to
	 * survive: a marker added here gets NO icon — where StubMap.addLayer gives
	 * one — and the test decides when a marker becomes visible. See the file
	 * header for what that does and does not prove.
	 */
	class StubClusterGroup extends StubLayer {
		constructor( options ) {
			super( 'markerClusterGroup', { options: options || {} } );

			this.markers = [];
		}

		addLayer( marker ) {
			calls.clusterAdd.push( { group: this, marker } );
			this.markers.push( marker );

			// Deliberately no icon. A marker inside a cluster is not on the
			// map, so `getElement:function(){return this._icon}` hands back the
			// null that _initIcon never replaced.
			return this;
		}

		removeLayer( marker ) {
			const at = this.markers.indexOf( marker );

			calls.clusterRemove.push( { group: this, marker } );

			if ( -1 !== at ) {
				this.markers.splice( at, 1 );
			}

			marker.element = null;

			return this;
		}

		clearLayers() {
			calls.clusterClear.push( { group: this, markers: this.markers.slice() } );

			this.markers.forEach( ( marker ) => {
				marker.element = null;
			} );

			this.markers = [];

			return this;
		}

		/**
		 * The zoom at which this marker stops being part of a bubble.
		 *
		 * Not a Leaflet method: the real library does this by itself when the
		 * view changes, and nothing here has a view. The behaviour it stands in
		 * for is real — the marker is added to the group's feature group, which
		 * is on the map, so Map._layerAdd builds its icon and fires 'add'.
		 *
		 * @param {StubLayer} marker The marker.
		 * @returns {StubClusterGroup} This group.
		 */
		uncluster( marker ) {
			if ( null === marker.element && ownerDocument ) {
				marker.element = ownerDocument.createElement( 'img' );
				marker.element.className = 'leaflet-marker-icon';
			}

			marker.fire( 'add' );

			return this;
		}

		/**
		 * The other direction: this marker is swallowed by a bubble again.
		 *
		 * @param {StubLayer} marker The marker.
		 * @returns {StubClusterGroup} This group.
		 */
		recluster( marker ) {
			marker.element = null;
			marker.fire( 'remove' );

			return this;
		}

		/**
		 * Gets a marker out of its bubble and then calls back.
		 *
		 * The one branch reproduced is the synchronous one, and it is quoted
		 * rather than guessed: `zoomToShowLayer:function(e,t){...e._icon&&
		 * this._map.getBounds().contains(e.getLatLng())?t():...}` at byte
		 * offset 6695 of assets/markercluster/leaflet.markercluster.js. A
		 * marker that already has an icon and is in view calls back at once.
		 *
		 * Every other branch of the real function waits — for the map's
		 * 'moveend' after a panTo, or for the group's 'animationend' after a
		 * zoomToBounds, or for 'spiderfied' — and this file has no map events,
		 * no zoom and no projection, so it waits for nothing and does nothing.
		 * A case that wants that path takes the callback off `clusterZoom` and
		 * runs it itself, the way a case settles a geolocation prompt.
		 *
		 * There is no bounds check either: nothing here projects a coordinate,
		 * so "in view" cannot be asked. A marker with an icon is treated as
		 * visible, which is the generous direction and is recorded as such.
		 *
		 * @param {StubLayer} marker   The marker to reveal.
		 * @param {Function}  callback What to do once it is out.
		 * @returns {StubClusterGroup} This group.
		 */
		zoomToShowLayer( marker, callback ) {
			const done = 'function' === typeof callback ? callback : () => {};

			calls.clusterZoom.push( { group: this, marker, callback: done } );

			if ( null !== marker.element ) {
				done();
			}

			return this;
		}
	}

	const L = {
		map( container, options ) {
			if ( mapErrors.length ) {
				const next = mapErrors.shift();

				if ( next instanceof Error ) {
					throw next;
				}
			}

			const map = new StubMap( container, options );

			calls.map.push( { container, options, map } );

			return map;
		},
		tileLayer( url, options ) {
			const layer = new StubLayer( 'tileLayer', { url, options } );

			calls.tileLayer.push( { url, options, layer } );

			return layer;
		},
		marker( latlng, options ) {
			const layer = new StubLayer( 'marker', { latlng, options } );

			calls.marker.push( { latlng, options, layer } );

			return layer;
		},
		markerClusterGroup( options ) {
			const group = new StubClusterGroup( options );

			calls.markerClusterGroup.push( { options, group } );

			return group;
		},
		divIcon( options ) {
			const icon = { kind: 'divIcon', options };

			calls.divIcon.push( { options, icon } );

			return icon;
		},
	};

	return { L, calls, mapErrors };
}

/**
 * The IntersectionObserver stand-in, and the log of the ones that were built.
 *
 * It observes nothing. There is no layout in this file, so "is this element
 * on screen" is not a question it can answer and it does not pretend to: a
 * case fires an entry by hand, exactly where a browser would have had a box
 * and a viewport.
 *
 * What is deliberately NOT reproduced is the initial callback. A real
 * IntersectionObserver queues one for every observed target as soon as it is
 * observed, asynchronously, reporting the state it is in — which is how the
 * picker learns that its map was born inside `<div id="metaboxes"
 * class="hidden">`. Nothing here queues anything, so a case that wants that
 * first delivery fires it, and a case whose outcome depends on *when* it
 * arrives is testing this file rather than the code.
 *
 * Also absent: root, rootMargin, thresholds, `intersectionRatio`,
 * `takeRecords()`, and the rule that `unobserve()` drops pending entries —
 * `disconnect()` is modelled, because "stops watching" is the difference
 * between re-measuring on every expansion and re-measuring once. The
 * entry a case delivers is a plain `{ target, isIntersecting }`, where a
 * browser passes an IntersectionObserverEntry — same kind of divergence as the
 * geolocation error and the AbortError, recorded for the same reason.
 *
 * @returns {object} The two observer classes and a log for each.
 */
function makeObservers() {
	const observers = [];
	const resizeObservers = [];

	/**
	 * The ResizeObserver stand-in, sharing the log above.
	 *
	 * Grown for Task 35, and it observes nothing by itself for the same reason
	 * its neighbour does not: there is no layout in this harness, so nothing
	 * here can decide that a box changed size. A case delivers by hand.
	 *
	 * The one browser rule modelled is the one that separates two features: a
	 * disconnected observer delivers nothing. Without it a case cannot tell
	 * "re-measures whenever the container changes" from "re-measures once and
	 * then stops", and the second is what a teardown has to produce.
	 *
	 * What a real ResizeObserver passes is a ResizeObserverEntry carrying
	 * contentRect and borderBoxSize. Nothing here builds one, because the code
	 * under test reads none of it: a resize is a signal, and what it triggers
	 * asks Leaflet to measure rather than taking a measurement from the event.
	 * A case that started reading an entry would have to grow this stub first,
	 * visibly.
	 */
	class StubResizeObserver {
		constructor( callback ) {
			this.callback = callback;
			this.targets = [];
			this.disconnected = false;

			resizeObservers.push( this );
		}

		observe( target ) {
			this.targets.push( target );
		}

		unobserve( target ) {
			const at = this.targets.indexOf( target );

			if ( -1 !== at ) {
				this.targets.splice( at, 1 );
			}
		}

		disconnect() {
			this.targets = [];
			this.disconnected = true;
		}

		/** Fires the callback, unless this observer has been disconnected. */
		deliver() {
			if ( this.disconnected ) {
				return;
			}

			this.callback( [], this );
		}
	}

	class StubIntersectionObserver {
		constructor( callback, options ) {
			this.callback = callback;
			this.options = options || null;
			this.targets = [];
			this.disconnected = false;

			observers.push( this );
		}

		observe( target ) {
			this.targets.push( target );
		}

		unobserve( target ) {
			const at = this.targets.indexOf( target );

			if ( -1 !== at ) {
				this.targets.splice( at, 1 );
			}
		}

		disconnect() {
			this.targets = [];
			this.disconnected = true;
		}

		/**
		 * Delivers entries, which is this file's name and not the browser's.
		 *
		 * A disconnected observer delivers nothing, which is the one rule here
		 * that is the browser's rather than this file's — `disconnect()` stops
		 * watching every target and cancels what was queued. It is modelled
		 * because without it a case cannot tell "re-measures every time the box
		 * is expanded" from "re-measures once and then unhooks itself", and
		 * those are two different features.
		 *
		 * `targets` is deliberately NOT consulted: a case delivering an entry
		 * for an element nobody observed is a case about this file, and the
		 * cases that matter read `targets` themselves to assert what is being
		 * watched.
		 *
		 * @param {Array} entries `{ target, isIntersecting }` objects.
		 * @returns {void}
		 */
		deliver( entries ) {
			if ( this.disconnected ) {
				return;
			}

			this.callback( entries, this );
		}
	}

	return {
		IntersectionObserver: StubIntersectionObserver,
		ResizeObserver: StubResizeObserver,
		observers,
		resizeObservers,
	};
}

/**
 * The Geolocation stand-in, plus its queue and its log.
 *
 * Queue-shaped like the fetch stub, and with the same rule: an answer nobody
 * queued is not invented. What is different is what an unqueued call means.
 * A fetch with nothing queued is a test that reached an unplanned request, so
 * it rejects and says so; a getCurrentPosition with nothing queued is the
 * ordinary state of a browser with a permission prompt open, so it simply never
 * answers and the case settles it by hand off `calls`.
 *
 * @returns {{geolocation: object, calls: Array, queue: Array}} The API, its log and its queue.
 */
function makeGeolocation() {
	const calls = [];
	const queue = [];

	const geolocation = {
		/**
		 * One position request.
		 *
		 * Synchronous delivery where a browser's is asynchronous; the file
		 * header says what that costs and why nothing under test may rely on
		 * the difference.
		 *
		 * @param {Function} success Called with a position.
		 * @param {Function} failure Called with an error.
		 * @param {object}   options PositionOptions; recorded, never honoured.
		 * @returns {void}
		 */
		getCurrentPosition( success, failure, options ) {
			calls.push( { success, failure, options: options || null } );

			if ( 0 === queue.length ) {
				return;
			}

			const next = queue.shift();

			if ( next && next.error ) {
				if ( 'function' === typeof failure ) {
					failure( next.error );
				}

				return;
			}

			if ( 'function' === typeof success ) {
				success( next && next.position ? next.position : next );
			}
		},
	};

	return { geolocation, calls, queue };
}

/**
 * A position, shaped the way GeolocationPosition is.
 *
 * Only the two members anything here reads. A real one also carries accuracy,
 * altitude, heading, speed and a timestamp, and nothing under test looks at any
 * of them — a fixture carrying them would invite a case to.
 *
 * @param {number} latitude  Latitude.
 * @param {number} longitude Longitude.
 * @returns {object} `{ position: { coords } }`, queue-shaped.
 */
function position( latitude, longitude ) {
	return { position: { coords: { latitude, longitude } } };
}

/**
 * A geolocation failure, queue-shaped.
 *
 * The codes are the Geolocation API's: 1 PERMISSION_DENIED, 2
 * POSITION_UNAVAILABLE, 3 TIMEOUT. A plain object rather than a
 * GeolocationPositionError; see the file header.
 *
 * @param {number} code    The code.
 * @param {string} message Optional message.
 * @returns {object} `{ error }`, queue-shaped.
 */
function positionError( code, message ) {
	return { error: { code, message: message || 'geolocation refused' } };
}

/**
 * A clock that only a test can move.
 *
 * The sandbox gets these as its `setTimeout` and `clearTimeout`, and gets no
 * `Date` at all, so there is no way for the code under test to read or wait
 * for real time. A debounce tested against a real timer is slow when it passes
 * and flaky when it fails; this one is neither.
 *
 * @returns {object} The clock: `setTimeout`, `clearTimeout`, `tick`, `pending`, `now`.
 */
function makeClock() {
	const timers = new Map();
	let now = 0;
	let sequence = 0;

	const clock = {
		get now() {
			return now;
		},

		/**
		 * How many callbacks are still waiting.
		 *
		 * @returns {number} The count.
		 */
		pending() {
			return timers.size;
		},

		setTimeout( handler, delay ) {
			const id = ++sequence;

			timers.set( id, { at: now + Math.max( 0, Number( delay ) || 0 ), handler } );

			// A number, as a browser's is. Node's returns an object, and code
			// that stored one and compared it would behave differently here.
			return id;
		},

		clearTimeout( id ) {
			timers.delete( id );
		},

		/**
		 * Moves time forward, running whatever comes due on the way.
		 *
		 * In scheduled order, earliest first, ties broken by the order they
		 * were scheduled in — and a callback that schedules another one inside
		 * the same window has it run too, which is what a browser does. What
		 * this cannot do is interleave microtasks between callbacks; see the
		 * file header.
		 *
		 * @param {number} ms Milliseconds to advance.
		 * @returns {number} How many callbacks ran.
		 */
		tick( ms ) {
			const target = now + Math.max( 0, Number( ms ) || 0 );
			let ran = 0;

			for ( ;; ) {
				let dueId = null;
				let due = null;

				timers.forEach( ( timer, id ) => {
					if ( timer.at <= target && ( null === due || timer.at < due.at ) ) {
						due = timer;
						dueId = id;
					}
				} );

				if ( null === due ) {
					break;
				}

				timers.delete( dueId );
				now = due.at;
				ran++;
				due.handler();
			}

			now = target;

			return ran;
		},
	};

	return clock;
}

/**
 * The error a fetch rejects with when its signal aborts.
 *
 * An ordinary Error with the right `name`. A browser throws a DOMException;
 * code branching on `error.name` cannot tell the two apart, and code branching
 * on the class would pass here and fail there. Stated in the header too.
 *
 * @returns {Error} The error.
 */
function abortError() {
	const error = new Error( 'The operation was aborted.' );

	error.name = 'AbortError';

	return error;
}

/**
 * A queued response that does not settle until the test says so.
 *
 * The only way to write "the slow answer arrives after the fast one", which is
 * the race an AbortController exists to lose on purpose. A plain queued
 * response resolves immediately and so can never be in a race at all.
 *
 * @returns {object} `{ deferred, promise, resolve, reject }`.
 */
function deferredResponse() {
	let settle = null;
	let fail = null;

	const promise = new Promise( ( resolve, reject ) => {
		settle = resolve;
		fail = reject;
	} );

	const box = {
		deferred: true,
		promise,
		// Set synchronously by the test, not a turn later when the promise
		// handlers run. That is what lets the abort wrapper below tell "not
		// answered yet" from "answered, and the microtask has not run".
		delivered: false,
		resolve: ( value ) => {
			box.delivered = true;
			settle( value );
		},
		reject: ( error ) => {
			box.delivered = true;
			fail( error );
		},
	};

	return box;
}

/**
 * The fetch stand-in, plus its queue and its log.
 *
 * @returns {{fetch: Function, queue: Array, calls: Array}} The function, its queue and its log.
 */
function makeFetch() {
	const queue = [];
	const calls = [];

	const fetchStub = function ( url, init ) {
		const signal = init && init.signal ? init.signal : null;

		calls.push( { url: String( url ), init: init || null, signal } );

		if ( 0 === queue.length ) {
			return Promise.reject(
				new Error( 'the harness fetch was called with nothing queued for it: ' + String( url ) )
			);
		}

		const next = queue.shift();

		// A signal that was already aborted refuses before anything else, the
		// way a real fetch does.
		if ( signal && signal.aborted ) {
			return Promise.reject( abortError() );
		}

		if ( next instanceof Error ) {
			return Promise.reject( next );
		}

		if ( next && true === next.deferred ) {
			if ( ! signal ) {
				return next.promise;
			}

			return new Promise( ( resolve, reject ) => {
				next.promise.then( resolve, reject );
				signal.addEventListener( 'abort', () => {
					// Only while the response is still on its way. A real
					// fetch promise, once resolved with a Response, cannot be
					// un-resolved — aborting after that rejects the *body*
					// promise instead. Without this check the harness rejected
					// a response it had already handed over, which is a
					// divergence in the passing direction sitting exactly
					// where the sequence numbers are supposed to earn their
					// keep: a case could "prove" a guard that a browser would
					// never exercise.
					if ( ! next.delivered ) {
						reject( abortError() );
					}
				} );
			} );
		}

		return Promise.resolve( next );
	};

	return { fetch: fetchStub, queue, calls };
}

/**
 * A Response-shaped object. Four members; see the file header.
 *
 * @param {*}      body   Value `json()` resolves to.
 * @param {object} extras Overrides, typically `{ ok: false, status: 500 }`.
 * @returns {object} The response.
 */
function jsonResponse( body, extras ) {
	return Object.assign(
		{
			ok: true,
			status: 200,
			json: () => Promise.resolve( body ),
			text: () => Promise.resolve( JSON.stringify( body ) ),
		},
		extras || {}
	);
}

/**
 * A response whose body is not json, the way an HTML error page arrives.
 *
 * @param {object} extras Overrides.
 * @returns {object} The response.
 */
function brokenJsonResponse( extras ) {
	return Object.assign(
		{
			ok: true,
			status: 200,
			json: () => Promise.reject( new SyntaxError( 'Unexpected token < in JSON at position 0' ) ),
			text: () => Promise.resolve( '<!DOCTYPE html>' ),
		},
		extras || {}
	);
}

/**
 * A response with no body at all, the way a 204 arrives.
 *
 * Distinct from brokenJsonResponse(), and the difference is the message rather
 * than the shape: an html error page rejects with "Unexpected token <" and an
 * *empty* body rejects with "Unexpected end of JSON input". Both are a
 * SyntaxError out of json(), so nothing branches on which — but a fixture that
 * says the wrong one teaches whoever reads it next a wrong fact about the
 * response it is standing in for.
 *
 * text() resolves to '' for the same reason. WP_REST_Server returns before
 * wp_json_encode() for a 204, so there is genuinely nothing there.
 *
 * @param {object} extras Overrides, typically `{ status: 204 }`.
 * @returns {object} The response.
 */
function emptyBodyResponse( extras ) {
	return Object.assign(
		{
			ok: true,
			status: 204,
			json: () => Promise.reject( new SyntaxError( 'Unexpected end of JSON input' ) ),
			text: () => Promise.resolve( '' ),
		},
		extras || {}
	);
}

/** Settings::defaults()' `radius_choices`, which every locator here is rendered from. */
const RADIUS_CHOICES = [ 5, 10, 25, 50, 100, 250, 500 ];

/** Settings::defaults()' `limit_choices`. */
const LIMIT_CHOICES = [ 10, 25, 50, 100, 250, 500 ];

/**
 * Shortcode::number_label(): a number as a person would write it.
 *
 * @param {number} value A choice.
 * @returns {string} The value with no trailing zeros and no exponent.
 */
function numberLabel( value ) {
	return String( Math.floor( value ) === value ? value : Math.round( value * 1000 ) / 1000 );
}

/**
 * Shortcode::with_current(): the offered steps, with the current value in them,
 * in order, once.
 *
 * This is why a locator configured with `radius: 500` gets a 500 option and one
 * configured with `radius: 7` gets a 7 wedged between 5 and 10 — and why a case
 * may drive either. A fixture that offered only the site's steps would let a
 * case choose a value the real select never renders.
 *
 * @param {number[]} choices The site's steps.
 * @param {number}   current The configured value.
 * @returns {number[]} The steps to render.
 */
function withCurrent( choices, current ) {
	// A config carrying no usable number is the `config: null` and
	// `config: 'not json'` fixtures, where init() fails before anything reads a
	// control. The steps go in without one of them merged, rather than an
	// option whose value is the string "NaN".
	if ( ! isFinite( current ) ) {
		return choices.slice();
	}

	const has = choices.some( ( choice ) => Math.abs( choice - current ) < 0.000001 );

	return has ? choices.slice() : choices.concat( [ current ] ).sort( ( first, second ) => first - second );
}

/**
 * What a browser would report as a select's `value`, given its options.
 *
 * The one place in this harness where a `<select>` is not the dumb string box
 * the header describes, and it is deliberate. The `value` of a real select is
 * never arbitrary: it is the value of the option carrying `selected`, or of the
 * first option when none does, or `''` when there are no options at all. A
 * fixture that seeded `value` with whatever the config said would hand a case a
 * selection no browser would have made — and a wire that only works for such a
 * selection would look fixed.
 *
 * Nothing here models what happens *after* somebody chooses; setting `.value`
 * by hand still stores any string, and the header says so.
 *
 * @param {Array}  options Rows of `value` and `selected`.
 * @returns {string} The value a browser would report.
 */
function selectedValue( options ) {
	const marked = options.filter( ( option ) => option.selected );

	if ( marked.length ) {
		return String( marked[ marked.length - 1 ].value );
	}

	return options.length ? String( options[ 0 ].value ) : '';
}

/**
 * One labelled `<select>`, the way Shortcode::field() writes it.
 *
 * @param {StubDocument} doc     The document to build into.
 * @param {string}       name    Short name; becomes `slosm__{name}`.
 * @param {string}       caption The label text.
 * @param {Array}        options Rows of `value`, `label` and `selected`.
 * @returns {StubElement} The `<label>`, with the select inside it.
 */
function amountField( doc, name, caption, options ) {
	const label = doc.createElement( 'label' );
	const text = doc.createElement( 'span' );
	const select = doc.createElement( 'select' );

	label.className = 'slosm__field slosm__field--' + name;
	text.className = 'slosm__field-label';
	text.textContent = caption;
	select.className = 'slosm__' + name;

	options.forEach( ( row ) => {
		const option = doc.createElement( 'option' );

		option.value = String( row.value );
		option.textContent = String( row.label );

		if ( row.selected ) {
			option.setAttribute( 'selected', 'selected' );
		}

		select.appendChild( option );
	} );

	select.value = selectedValue( options );

	label.appendChild( text );
	label.appendChild( select );

	return label;
}

/**
 * Shortcode::radius_options(), as rows.
 *
 * @param {object} settled The config.
 * @param {Array|undefined} override Raw option values, for markup this plugin never emits.
 * @returns {Array} Rows of `value`, `label` and `selected`.
 */
function radiusOptions( settled, override ) {
	const unit = 'mi' === settled.units ? 'mi' : 'km';

	if ( undefined !== override ) {
		return override.map( ( value ) => ( {
			value: String( value ),
			label: String( value ) + ' ' + unit,
			selected: false,
		} ) );
	}

	const current = Number( settled.radius );

	return withCurrent( RADIUS_CHOICES, current ).map( ( choice ) => ( {
		value: numberLabel( choice ),
		label: numberLabel( choice ) + ' ' + unit,
		selected: Math.abs( choice - current ) < 0.000001,
	} ) );
}

/**
 * Shortcode::limit_options(), as rows.
 *
 * @param {object} settled The config.
 * @param {Array|undefined} override Raw option values, for markup this plugin never emits.
 * @returns {Array} Rows of `value`, `label` and `selected`.
 */
function limitOptions( settled, override ) {
	if ( undefined !== override ) {
		return override.map( ( value ) => ( {
			value: String( value ),
			label: String( value ) + ' results',
			selected: false,
		} ) );
	}

	const current = Math.trunc( Number( settled.limit ) );

	return withCurrent( LIMIT_CHOICES, current ).map( ( choice ) => {
		const count = Math.trunc( choice );

		return {
			value: String( count ),
			label: String( count ) + ( 1 === count ? ' result' : ' results' ),
			selected: count === current,
		};
	} );
}

/**
 * The config Shortcode::config() produces, with every key present.
 *
 * Copied from includes/class-shortcode.php::config(). harness.test.js asserts
 * the key list against that method rather than trusting this comment.
 *
 * @param {object} overrides Keys to change.
 * @returns {object} A config.
 */
function defaultConfig( overrides ) {
	return assign(
		{
			routes: {
				stores: 'https://example.test/wp-json/slosm/v1/stores',
				store: 'https://example.test/wp-json/slosm/v1/stores/__ID__',
				geocode: 'https://example.test/wp-json/slosm/v1/geocode',
				suggest: 'https://example.test/wp-json/slosm/v1/suggest',
			},
			mode: 'preload',
			count: 3,
			threshold: 500,
			height: 480,
			zoom: 12,
			lat: null,
			lng: null,
			units: 'km',
			radius: 50,
			limit: 500,
			category: '',
			search: '',
			nearMe: true,
			autoLocate: false,
			cluster: false,

			// Task 21's seven site-wide keys. Every value here is the default
			// Settings::defaults() ships, because that is what makes the whole
			// suite a test of behaviour rather than of the settings screen: a
			// site that never opens the screen sees exactly this.
			tile: {
				url: 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
				attribution:
					'<a href="https://www.openstreetmap.org/copyright" rel="noreferrer">© OpenStreetMap contributors</a>',
				maxZoom: 19,
			},
			marker: { style: 'pin', colour: '#3388ff' },
			position: 'right',
			fields: [ 'name', 'address', 'city', 'distance', 'categories' ],
			popup: [ 'name', 'address', 'categories', 'phone', 'email', 'url', 'hours', 'description' ],
			directions: 'osm',
			autocomplete: true,
		},
		overrides
	);
}

/**
 * The markup Shortcode::render() emits, as far as the locator script reads it.
 *
 * A hand-copy, and therefore a lie waiting to happen — harness.test.js reads
 * includes/class-shortcode.php and asserts every class name below is really
 * emitted by it. What is deliberately left out: the html comments and every
 * attribute the script does not read. Adding them would be inventing markup no
 * test looks at. Task 14 added the search field and the <template> row, Task 15
 * the category select and the "use my location" button, and Task 29a the radius
 * and limit selects, because those are the tasks whose code reads them.
 *
 * The radius and limit selects are built the way Shortcode::radius_options()
 * and limit_options() build them, which is the part that matters and the part
 * a shorter fixture would have got wrong: the site's whole list of steps, with
 * the config's own value merged in when it is not one of them, and that one
 * carrying `selected`. A fixture with a single option in it would let a case
 * "choose" a value no visitor could have chosen.
 *
 * The category select is built the way Shortcode::category_options() builds it
 * and no fuller: one "All categories" option, plus the pinned one when the
 * config names a category. Filling it is the front end's job, which is the
 * whole of what Task 15's cases are about — a fixture that arrived with the
 * site's categories already in it would test nothing.
 *
 * The <input>'s `value` is seeded from the config's `search`, which is what
 * the parser does with the `value="…"` Shortcode::filters() writes. It is the
 * only tie between the value property and the value attribute in this harness.
 * The select's `value` is seeded from the config's `category` for the same
 * reason and with the same single tie; see the header on what a `<select>`
 * here does not model.
 *
 * @param {StubDocument} doc     The document to build into.
 * @param {object}       options `config` (object, null or a raw string),
 *                               `withMap`, `withResults`, `withSearch`,
 *                               `withTemplate`, `withRadius`, `withRadiusOptions`,
 *                               `withLimit`, `withLimitOptions`, `withCategory`,
 *                               `withStatus`,
 *                               `withLocate`, `withSubmit`, `search`.
 * @returns {StubElement} The `.slosm` container, already in the document body.
 */
function locatorMarkup( doc, options ) {
	const settings = assign(
		{
			config: defaultConfig(),
			withMap: true,
			withStatus: true,
			withResults: true,
			withSearch: true,
			withSubmit: true,
			withLabel: true,
			withTemplate: true,
			withRadius: true,
			withRadiusOptions: undefined,
			withLimit: true,
			withLimitOptions: undefined,
			withCategory: true,
			withLocate: true,
			search: undefined,
		},
		options
	);

	const settled = settings.config && 'object' === typeof settings.config ? settings.config : {};
	const container = doc.createElement( 'div' );

	container.className = 'slosm';

	if ( null !== settings.config ) {
		container.setAttribute(
			'data-slosm',
			'string' === typeof settings.config ? settings.config : JSON.stringify( settings.config )
		);
	}

	if (
		settings.withSearch ||
		settings.withSubmit ||
		settings.withRadius ||
		settings.withLimit ||
		settings.withCategory ||
		settings.withLocate
	) {
		const filters = doc.createElement( 'div' );

		filters.className = 'slosm__filters';
		filters.setAttribute( 'role', 'search' );

		if ( settings.withSearch ) {
			// `withLabel: false` is markup this plugin never emits: the field
			// outside the label that wraps it, the way a page builder or a
			// content filter can leave it. It exists so the front end's "put the
			// popup beside whichever of the two is there" branch has a case.
			const label = settings.withLabel ? doc.createElement( 'label' ) : filters;
			const caption = doc.createElement( 'span' );
			const input = doc.createElement( 'input' );

			caption.className = 'slosm__field-label';
			caption.textContent = 'Address, postcode or city';

			input.className = 'slosm__search';
			input.setAttribute( 'type', 'search' );
			input.setAttribute( 'maxlength', '200' );
			// Task 29c's, and nothing in locator.js reads it. It is here for
			// the same reason `type` and `maxlength` are: this fixture is a
			// hand-copy of Shortcode::filters(), and an attribute missing from
			// the copy is an attribute harness.test.js's markup pin is the only
			// thing standing between and a silent deletion on the server side.
			input.setAttribute( 'enterkeyhint', 'search' );
			input.setAttribute( 'autocomplete', 'off' );
			input.value =
				undefined !== settings.search ? String( settings.search ) : String( settled.search || '' );

			label.appendChild( caption );
			label.appendChild( input );

			if ( label !== filters ) {
				label.className = 'slosm__field slosm__field--search';
				filters.appendChild( label );
			}
		}

		// The submit control, a sibling of the label rather than inside it,
		// which is where Shortcode::filters() puts it and for a reason a
		// fixture cannot see: a click inside a <label> is a click on the
		// label, so a button in there would move the focus to the field as
		// well as run the search.
		//
		// Built independently of `withSearch`, because "a button over no
		// field" is markup a page builder can produce and locator.js has a
		// guard for it. `withSubmit: false` is the other half: the locator
		// every release before Task 29c shipped, driven by Enter alone.
		if ( settings.withSubmit ) {
			const submit = doc.createElement( 'button' );

			submit.className = 'slosm__submit';
			submit.setAttribute( 'type', 'button' );
			submit.textContent = 'Search';
			filters.appendChild( submit );
		}

		// Between the search field and the category select, which is the order
		// Shortcode::filters() writes them in. Nothing reads the order; it is
		// here because a fixture that reordered the controls would be one more
		// place this hand-copy differs from the markup.
		if ( settings.withRadius ) {
			filters.appendChild(
				amountField( doc, 'radius', 'Within', radiusOptions( settled, settings.withRadiusOptions ) )
			);
		}

		if ( settings.withLimit ) {
			filters.appendChild(
				amountField( doc, 'limit', 'Show at most', limitOptions( settled, settings.withLimitOptions ) )
			);
		}

		if ( settings.withCategory ) {
			const label = doc.createElement( 'label' );
			const caption = doc.createElement( 'span' );
			const select = doc.createElement( 'select' );
			const all = doc.createElement( 'option' );

			label.className = 'slosm__field slosm__field--category';
			caption.className = 'slosm__field-label';
			caption.textContent = 'Category';

			select.className = 'slosm__category';

			all.value = '';
			all.textContent = 'All categories';

			if ( '' === String( settled.category || '' ) ) {
				all.setAttribute( 'selected', 'selected' );
			}

			select.appendChild( all );

			// Shortcode::category_options() renders the pinned category as a
			// second, selected option and nothing else. Everything the site has
			// is the front end's to add.
			if ( '' !== String( settled.category || '' ) ) {
				const pinned = doc.createElement( 'option' );

				pinned.value = String( settled.category );
				pinned.textContent = String( settled.category );
				pinned.setAttribute( 'selected', 'selected' );
				select.appendChild( pinned );
			}

			select.value = String( settled.category || '' );

			label.appendChild( caption );
			label.appendChild( select );
			filters.appendChild( label );
		}

		// Shortcode::filters() emits the button only when near_me is on, which
		// is the default. `withLocate: false` is the other half of that
		// attribute rather than broken markup.
		if ( settings.withLocate ) {
			const locate = doc.createElement( 'button' );

			locate.className = 'slosm__locate';
			locate.setAttribute( 'type', 'button' );
			locate.textContent = 'Use my location';
			filters.appendChild( locate );
		}

		container.appendChild( filters );
	}

	// Shortcode::render() emits the status line between the filter row and the
	// map, and it is the only live region on a locator. `withStatus: false` is
	// the markup a page builder can produce by dropping it, which say() has a
	// fallback for.
	if ( settings.withStatus ) {
		const status = doc.createElement( 'p' );

		status.className = 'slosm__message';
		status.setAttribute( 'role', 'status' );
		status.setAttribute( 'aria-live', 'polite' );
		container.appendChild( status );
	}

	if ( settings.withMap ) {
		const map = doc.createElement( 'div' );

		map.className = 'slosm__map';
		map.setAttribute( 'role', 'region' );
		container.appendChild( map );
	}

	if ( settings.withResults ) {
		const results = doc.createElement( 'ol' );

		results.className = 'slosm__results';
		container.appendChild( results );
	}

	if ( settings.withTemplate ) {
		const template = doc.createElement( 'template' );
		const row = doc.createElement( 'li' );
		const open = doc.createElement( 'button' );
		const categories = doc.createElement( 'ul' );
		const directions = doc.createElement( 'a' );

		template.className = 'slosm__row';

		row.className = 'slosm__result';

		open.className = 'slosm__result-open';
		open.setAttribute( 'type', 'button' );

		[ 'name', 'address', 'city', 'distance' ].forEach( ( part ) => {
			const span = doc.createElement( 'span' );

			span.className = 'slosm__result-' + part;
			open.appendChild( span );
		} );

		categories.className = 'slosm__result-categories';

		directions.className = 'slosm__result-directions';
		directions.setAttribute( 'rel', 'noopener noreferrer' );
		directions.setAttribute( 'target', '_blank' );
		directions.textContent = 'Directions';

		row.appendChild( open );
		row.appendChild( categories );
		row.appendChild( directions );

		// Into the content, not into the template element. That is the whole
		// of the inertness: a walk of the container's children never reaches
		// these nodes, so `.slosm__result` does not match until a row has
		// actually been cloned into the list.
		template.content.appendChild( row );

		// A sibling of the results list, exactly as Shortcode::render() emits
		// it. say() clears the list with textContent = '', and that is only
		// safe while the template is not inside it.
		container.appendChild( template );
	}

	doc.body.appendChild( container );

	return container;
}

/**
 * The strings Assets::strings() localises, in English.
 *
 * harness.test.js asserts these keys against includes/class-assets.php, for
 * the same reason locatorMarkup() is checked against the shortcode.
 *
 * @param {object} overrides Keys to change.
 * @returns {object} The l10n payload.
 */
function defaultStrings( overrides ) {
	return assign(
		{
			noResults: 'No results',
			closePopup: 'Close popup',
			resultsFound: 'Locations found: %s',
			searching: 'Searching…',
			locating: 'Finding your location…',
			locationDenied: 'No location shared. Search for an address instead.',
			locationFailed: 'Your location could not be worked out. Search for an address instead.',
			locationUnsupported: 'This browser cannot share a location. Search for an address instead.',
			loadFailed: 'The locations could not be loaded.',
			configError: 'This map could not start: its settings are missing or unreadable.',
			searchNoMatch: 'No place matched that search.',
			searchBusy: 'The address lookup is busy right now. Try again in a moment.',
			searchFailed: 'That address could not be looked up right now.',
			distanceKm: '%s km',
			distanceMi: '%s mi',
			directions: 'Directions',
		},
		overrides
	);
}

/**
 * The globals a plugin script gets, and nothing else.
 *
 * One function for both entry points below, because a second copy of this
 * object is a second browser: a global present in one world and absent in the
 * other makes "the admin script uses nothing the front end does not" a claim
 * about this file rather than about the code. Everything that differs between
 * the two worlds is a flag, and every flag is named at its call site.
 *
 * What is *not* here is the point of it. No Date, no history, no localStorage,
 * no jQuery, no wp: a script reaching for any of them throws inside the case
 * that reached, instead of passing here and failing on a site.
 *
 * @param {StubDocument} doc      The document.
 * @param {object}       pieces   `leaflet`, `fetcher`, `clock`, `locator`, `consoleCalls`.
 * @param {object}       settings Resolved options; see the callers.
 * @returns {object} The sandbox, self-referencing through `window`.
 */
function makeSandbox( doc, pieces, settings ) {
	const sandbox = {
		document: doc,
		// A navigator with one member on it at most. Not a user agent string,
		// not languages, not a clipboard: the code under test reads exactly one
		// thing off it, and a richer stub would invite a case to read more.
		navigator: settings.withGeolocation ? { geolocation: pieces.locator.geolocation } : {},
		// The fake clock, and nothing else that can measure time. There is no
		// Date in here on purpose.
		setTimeout: ( handler, delay ) => pieces.clock.setTimeout( handler, delay ),
		clearTimeout: ( id ) => pieces.clock.clearTimeout( id ),
		// Enough Location for `new URL( route, base )` to have a base. No
		// navigation, no history, no origin checks, no hash.
		location: { href: settings.href },
		URL,
		URLSearchParams,
		Promise,
		Math,
		JSON,
		Object,
		Array,
		Number,
		String,
		Boolean,
		Error,
		WeakSet,
		Set,
		Map,
		isNaN,
		isFinite,
		parseFloat,
		parseInt,
		// Node's own. A script writing into a form field has to tell the page
		// it did — nothing else fires for a programmatic write — and
		// `new Event( type, { bubbles: true } )` is how. A hand-written stand-in
		// would be a second implementation of a platform class, and worse, one
		// whose shape the harness itself would then be free to get wrong.
		Event,
		console: {
			warn: ( ...args ) => pieces.consoleCalls.warn.push( args ),
			error: ( ...args ) => pieces.consoleCalls.error.push( args ),
			log: () => {},
		},
	};

	if ( settings.withLeaflet ) {
		sandbox.L = pieces.leaflet.L;
	}

	if ( settings.withFetch ) {
		sandbox.fetch = pieces.fetcher.fetch;
	}

	if ( settings.withAbortController ) {
		// Node's own, not a stub. It is the same web API, and a hand-written
		// one would be a second implementation of a thing the platform
		// already has right.
		sandbox.AbortController = AbortController;
	}

	// False leaves the sandbox without one, which is a browser older than
	// Safari 12.1 and is also every environment where the picker has to come
	// up anyway rather than throw on a constructor that is not there.
	if ( settings.withIntersectionObserver ) {
		sandbox.IntersectionObserver = pieces.observers.IntersectionObserver;
	}

	// The front end's, and given unconditionally rather than behind a flag:
	// unlike the picker's IntersectionObserver, nothing in locator.js needs a
	// world without one to be interesting — the code guards for its absence
	// and the guard has a case of its own that deletes this global.
	if ( pieces.observers && false !== settings.withResizeObserver ) {
		sandbox.ResizeObserver = pieces.observers.ResizeObserver;
	}

	// The asynchronous clipboard, when this world is supposed to have one. It
	// is put on the same navigator the geolocation branch above builds, so a
	// world can have both, either or neither — and "neither" is the shape of
	// `navigator` on plain http, where the Clipboard API's [SecureContext]
	// means the member is absent rather than refusing.
	if ( settings.withClipboard ) {
		sandbox.navigator.clipboard = pieces.clipboard.clipboard;
	}

	// null means "this browser does not have the flag at all", which is the
	// third world the code has to survive: `false === window.isSecureContext`
	// is false for an absent flag, so the decision falls through to whether
	// there is a clipboard object. null rather than undefined because assign()
	// above skips an undefined override, so a case could not ask for it.
	if ( null !== settings.secureContext && undefined !== settings.secureContext ) {
		sandbox.isSecureContext = settings.secureContext;
	}

	// window === globalThis in a browser, and the scripts rely on that: they
	// write window.SLOSM and read window.L. The self-reference is the faithful
	// shape, not a shortcut.
	sandbox.window = sandbox;
	sandbox.globalThis = sandbox;

	return sandbox;
}

/**
 * Builds a world, runs assets/js/locator.js in it, hands back both.
 *
 * @param {object} options `readyState`, `strings` (or null to omit slosmL10n
 *                         entirely), `withLeaflet` (false omits `L`),
 *                         `withFetch`, `withAbortController`, `withGeolocation`
 *                         (false leaves `navigator` without it), `href`,
 *                         `prepare`.
 * @returns {object} The sandbox, its pieces and `SLOSM`.
 */
function loadLocator( options ) {
	const settings = assign(
		{
			readyState: 'interactive',
			strings: defaultStrings(),
			withLeaflet: true,
			// False omits `fetch` from the sandbox entirely, the way
			// withLeaflet omits `L`. Not a browser this plugin supports —
			// every one of them has fetch — but a site whose service worker or
			// polyfill kit leaves the global uncallable, which is the case the
			// guard in init() is written for and which had no way to be
			// reached from a test until a reviewer pointed that out.
			withFetch: true,
			// False omits `AbortController`. Every browser WordPress 6.0
			// supports has had it since 2017, so this is not about a browser
			// either: it is the case that proves the sequence guard in the
			// suggestion handler does its own work, rather than passing
			// because the abort happened to get there first.
			withAbortController: true,
			// False gives the sandbox a `navigator` with no `geolocation` on
			// it, which is what an old browser and a hardened one both look
			// like. There is no third state where `navigator` itself is
			// missing: every browser has one, and inventing a world without it
			// would be a case for a guard nothing can reach.
			withGeolocation: true,
			// The front end never asks whether it is on screen, so its world
			// does not carry one. Adding it here would let a locator case
			// quietly start depending on a global the admin screen provides.
			withIntersectionObserver: false,
			href: 'https://example.test/find-us/',
			// Runs after the world exists and before locator.js does, so a
			// case can put markup on the page the way the html parser would
			// have: already there when the deferred script runs.
			prepare: null,
		},
		options
	);

	const doc = new StubDocument();

	doc.readyState = settings.readyState;

	const leaflet = makeLeaflet( doc );
	const fetcher = makeFetch();
	const clock = makeClock();
	const locator = makeGeolocation();
	const observers = makeObservers();
	const consoleCalls = { warn: [], error: [] };

	const sandbox = makeSandbox(
		doc,
		{ leaflet, fetcher, clock, locator, observers, consoleCalls },
		settings
	);

	if ( null !== settings.strings ) {
		sandbox.slosmL10n = settings.strings;
	}

	vm.createContext( sandbox );

	// Everything the sandbox had before the file ran, so a case can assert
	// that the file added exactly one global and nothing else. `window` and
	// `globalThis` are the self-references above, not the file's doing.
	const before = new Set( Object.keys( sandbox ) );

	if ( settings.prepare ) {
		settings.prepare( {
			document: doc,
			fetchQueue: fetcher.queue,
			locatorMarkup: ( opts ) => locatorMarkup( doc, opts ),
		} );
	}

	const code = fs.readFileSync( LOCATOR_PATH, 'utf8' );

	vm.runInContext( code, sandbox, { filename: LOCATOR_PATH } );

	/**
	 * Runs the file again in the same world.
	 *
	 * Not a curiosity: a caching or concatenating plugin that emits locator.js
	 * twice on one page is the case locator.js's own WeakSet docblock names,
	 * and it is the one case a per-evaluation counter cannot see — the second
	 * evaluation starts counting at zero again. Everything the file holds in a
	 * closure is rebuilt; everything in the document is not.
	 *
	 * @returns {void}
	 */
	const evaluate = () => {
		vm.runInContext( code, sandbox, { filename: LOCATOR_PATH } );
	};

	return {
		evaluate,
		sandbox,
		newGlobals: Object.keys( sandbox ).filter( ( key ) => ! before.has( key ) ),
		document: doc,
		L: leaflet.L,
		leafletCalls: leaflet.calls,
		leafletMapErrors: leaflet.mapErrors,
		fetchQueue: fetcher.queue,
		fetchCalls: fetcher.calls,
		geolocationQueue: locator.queue,
		geolocationCalls: locator.calls,
		clock,
		resizeObservers: observers.resizeObservers,
		consoleCalls,
		get SLOSM() {
			return sandbox.SLOSM;
		},
		locatorMarkup: ( opts ) => locatorMarkup( doc, opts ),
	};
}

/**
 * The config Admin::picker_config() puts in data-slosm-picker.
 *
 * A hand-copy, checked against admin/class-admin.php by
 * tests/js/admin-picker.test.js the same way defaultConfig() is checked
 * against the shortcode. Every value here is the plugin's own: there is no
 * stored post content in this config and there must never be, which is a claim
 * that file also makes.
 *
 * @param {object} overrides Keys to change.
 * @returns {object} A picker config.
 */
function defaultPickerConfig( overrides ) {
	return assign(
		{
			geocode: 'https://example.test/wp-json/slosm/v1/geocode',
			nonce: 'nonce:wp_rest',
			lat: 'slosm_lat',
			lng: 'slosm_lng',
			lookup: 'slosm_lookup',
			address: [
				'slosm_address',
				'slosm_address2',
				'slosm_city',
				'slosm_state',
				'slosm_zip',
				'slosm_country',
			],
			zoom: 15,
			decimals: 7,
			latLimit: 90,
			lngLimit: 180,

			// Task 21. The same three keys Settings::tile_config() builds for
			// the front end, because the edit screen and the public map are
			// handed the same object by the same method. The url carries all
			// three placeholders, because a picker whose tile url has not got
			// them draws no map at all — see tileFor() in assets/js/admin.js.
			tile: {
				url: 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
				attribution:
					'<a href="https://www.openstreetmap.org/copyright" rel="noreferrer">© OpenStreetMap contributors</a>',
				maxZoom: 19,
			},
		},
		overrides
	);
}

/**
 * The strings Assets::admin_strings() localises, in English.
 *
 * Checked against includes/class-assets.php, for the same reason
 * defaultStrings() is.
 *
 * @param {object} overrides Keys to change.
 * @returns {object} The l10n payload.
 */
function defaultAdminStrings( overrides ) {
	return assign(
		{
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
			lookupDone:
				'The address was found, so these coordinates will be looked up again if the address changes.',
			lookupNoMatch: 'No place matched that address.',
			lookupBusy: 'The address lookup is busy right now. Try again in a moment.',
			lookupFailed: 'That address could not be looked up right now.',
		},
		overrides
	);
}

/**
 * The markup Admin::render() emits, as far as the admin script reads it.
 *
 * A hand-copy of one metabox, and therefore the same lie waiting to happen as
 * locatorMarkup() — so admin-picker.test.js reads admin/class-admin.php and
 * asserts every class name and every id below is really emitted by it.
 *
 * What is deliberately left out: the labels' text, the nonce field, the
 * description paragraph, the hours textarea and the `widefat` class. What is
 * in is everything the picker resolves: the two coordinate inputs by id, the
 * six address inputs by id, the hidden lookup field, the picker container with
 * its config, the map div and the lookup button.
 *
 * The inputs' `value` is seeded from `record` the way the parser seeds it from
 * the `value="…"` attribute Admin::print_text_field() writes; see the file
 * header on how thin that tie is.
 *
 * @param {StubDocument} doc     The document to build into.
 * @param {object}       options `record`, `config` (object, string or null),
 *                               `withPicker`, `withMap`, `withButton`,
 *                               `withLookup`, `withLat`, `withLng`.
 * @returns {StubElement} The `.slosm-metabox__coordinates` element's parent form.
 */
function metaboxMarkup( doc, options ) {
	const settings = assign(
		{
			record: {},
			config: defaultPickerConfig(),
			withPicker: true,
			withMap: true,
			withButton: true,
			withLookup: true,
			withLat: true,
			withLng: true,
		},
		options
	);

	const form = doc.createElement( 'form' );
	const box = doc.createElement( 'div' );
	const coordinates = doc.createElement( 'div' );

	form.setAttribute( 'id', 'post' );
	box.className = 'slosm-metabox';
	coordinates.className = 'slosm-metabox__coordinates';

	/**
	 * One labelled text input, as print_text_field() emits it.
	 *
	 * @param {StubElement} parent Where it goes.
	 * @param {string}      field  Field name, without the prefix.
	 * @returns {StubElement} The input.
	 */
	const field = ( parent, name ) => {
		const wrap = doc.createElement( 'p' );
		const label = doc.createElement( 'label' );
		const input = doc.createElement( 'input' );

		wrap.className = 'slosm-metabox__field';
		label.setAttribute( 'for', name );

		input.setAttribute( 'type', 'text' );
		input.setAttribute( 'id', name );
		input.setAttribute( 'name', name );
		input.value = undefined === settings.record[ name ] ? '' : String( settings.record[ name ] );

		wrap.appendChild( label );
		wrap.appendChild( input );
		parent.appendChild( wrap );

		return input;
	};

	const column = doc.createElement( 'div' );

	column.className = 'slosm-metabox__column';

	[
		'slosm_address',
		'slosm_address2',
		'slosm_city',
		'slosm_state',
		'slosm_zip',
		'slosm_country',
	].forEach( ( name ) => field( column, name ) );

	box.appendChild( column );

	if ( settings.withLat ) {
		field( coordinates, 'slosm_lat' );
	}

	if ( settings.withLng ) {
		field( coordinates, 'slosm_lng' );
	}

	if ( settings.withPicker ) {
		const picker = doc.createElement( 'div' );

		picker.className = 'slosm-metabox__picker';

		if ( null !== settings.config ) {
			picker.setAttribute(
				'data-slosm-picker',
				'string' === typeof settings.config ? settings.config : JSON.stringify( settings.config )
			);
		}

		if ( settings.withMap ) {
			const map = doc.createElement( 'div' );

			map.className = 'slosm-metabox__map';
			picker.appendChild( map );
		}

		if ( settings.withButton ) {
			const actions = doc.createElement( 'p' );
			const button = doc.createElement( 'button' );

			actions.className = 'slosm-metabox__actions';
			button.className = 'slosm-metabox__lookup';
			button.setAttribute( 'type', 'button' );
			button.textContent = 'Look the address up';

			actions.appendChild( button );
			picker.appendChild( actions );
		}

		if ( settings.withLookup ) {
			const lookup = doc.createElement( 'input' );

			lookup.setAttribute( 'type', 'hidden' );
			lookup.setAttribute( 'id', 'slosm_lookup' );
			lookup.setAttribute( 'name', 'slosm_lookup' );
			lookup.value = '';

			picker.appendChild( lookup );
		}

		coordinates.appendChild( picker );
	}

	form.appendChild( box );
	form.appendChild( coordinates );
	doc.body.appendChild( form );

	return form;
}

/**
 * Builds a world, runs the locator and then assets/js/admin.js in it.
 *
 * The locator file is evaluated first and on purpose: admin.js declares
 * slosm-locator as a dependency and reads `window.SLOSM` for the tile layer,
 * the zoom range and the url builder, so a world without it is a world where
 * the admin script would have to carry a second copy of the OpenStreetMap
 * attribution. Running the real file rather than injecting a hand-made SLOSM
 * is what makes that a fact about the two files instead of about this one.
 *
 * `withSlosm: false` is the other half: the site whose optimiser or
 * conflicting plugin deregistered slosm-locator, where admin.js has to say so
 * rather than throw.
 *
 * @param {object} options `readyState`, `strings` (or null to omit
 *                         slosmAdminL10n), `withLeaflet`, `withFetch`,
 *                         `withSlosm`, `href`, `prepare`.
 * @returns {object} The sandbox, its pieces and `SLOSM_ADMIN`.
 */
function loadAdmin( options ) {
	const settings = assign(
		{
			readyState: 'interactive',
			strings: defaultAdminStrings(),
			withLeaflet: true,
			withFetch: true,
			withAbortController: true,
			// The admin screen asks for no position and never will: a picker
			// is about where the shop is, not where the editor is.
			withGeolocation: false,
			// The picker's one recovery from being born inside a hidden
			// container. False is the browser that has none.
			withIntersectionObserver: true,
			withSlosm: true,
			href: 'https://example.test/wp-admin/post.php?post=7&action=edit',
			prepare: null,
		},
		options
	);

	const doc = new StubDocument();

	doc.readyState = settings.readyState;

	const leaflet = makeLeaflet( doc );
	const fetcher = makeFetch();
	const clock = makeClock();
	const locator = makeGeolocation();
	const observers = makeObservers();
	const consoleCalls = { warn: [], error: [] };

	const sandbox = makeSandbox(
		doc,
		{ leaflet, fetcher, clock, locator, observers, consoleCalls },
		settings
	);

	if ( null !== settings.strings ) {
		sandbox.slosmAdminL10n = settings.strings;
	}

	vm.createContext( sandbox );

	const before = new Set( Object.keys( sandbox ) );

	if ( settings.prepare ) {
		settings.prepare( {
			document: doc,
			fetchQueue: fetcher.queue,
			metaboxMarkup: ( opts ) => metaboxMarkup( doc, opts ),
		} );
	}

	if ( settings.withSlosm ) {
		// The front-end file, exactly as the admin screen loads it. It finds no
		// `.slosm` container here — `slosm-metabox` is a different class token,
		// and the selector engine matches whole tokens — so all it leaves
		// behind is the frozen namespace.
		vm.runInContext( fs.readFileSync( LOCATOR_PATH, 'utf8' ), sandbox, { filename: LOCATOR_PATH } );
	}

	const code = fs.readFileSync( ADMIN_PATH, 'utf8' );

	vm.runInContext( code, sandbox, { filename: ADMIN_PATH } );

	/**
	 * Runs admin.js again in the same world.
	 *
	 * @returns {void}
	 */
	const evaluate = () => {
		vm.runInContext( code, sandbox, { filename: ADMIN_PATH } );
	};

	return {
		evaluate,
		sandbox,
		newGlobals: Object.keys( sandbox ).filter( ( key ) => ! before.has( key ) ),
		document: doc,
		L: leaflet.L,
		leafletCalls: leaflet.calls,
		leafletMapErrors: leaflet.mapErrors,
		fetchQueue: fetcher.queue,
		fetchCalls: fetcher.calls,
		clock,
		observers: observers.observers,
		resizeObservers: observers.resizeObservers,
		consoleCalls,
		get SLOSM() {
			return sandbox.SLOSM;
		},
		get SLOSM_ADMIN() {
			return sandbox.SLOSM_ADMIN;
		},
		metaboxMarkup: ( opts ) => metaboxMarkup( doc, opts ),
	};
}

/**
 * Fires one event at an element and hands back what the handlers saw.
 *
 * @param {StubElement} target Element to fire at.
 * @param {string}      type   Event type.
 * @param {object}      props  Extra members, typically `{ key: 'Enter' }`.
 * @returns {object} The event object the handlers were given.
 */
function fire( target, type, props ) {
	const event = Object.assign( { type }, props || {} );

	target.dispatchEvent( event );

	return event.detail;
}

/**
 * JavaScript source with its comments removed, strings left intact.
 *
 * A regex cannot do this. `'https://tile.openstreetmap.org/…'` contains `//`,
 * so a naive line-comment strip would eat the rest of the tile url and every
 * line after it. This walks the text tracking which of the five states it is
 * in, which is a dozen lines and correct.
 *
 * It exists because the scan below has to see code and not prose. locator.js
 * cites Leaflet's own `this._container.innerHTML=…` in a docblock, to warn a
 * later task off putting server data in a layer's attribution — a citation
 * that exists in order to protect the rule is not a violation of it, and a
 * scan that cannot tell the two apart teaches people to stop writing the
 * warning rather than to stop writing the code.
 *
 * Not a JavaScript parser: it does not know regex literals from division. Fine
 * here because locator.js contains no regex literal, and a case asserts that
 * so the shortcut cannot rot silently.
 *
 * @param {string} source JavaScript.
 * @returns {string} The same text with comment bodies blanked.
 */
function codeOnly( source ) {
	let out = '';
	let i = 0;

	while ( i < source.length ) {
		const two = source.slice( i, i + 2 );

		if ( '//' === two ) {
			while ( i < source.length && '\n' !== source[ i ] ) {
				i++;
			}
			continue;
		}

		if ( '/*' === two ) {
			i += 2;
			while ( i < source.length && '*/' !== source.slice( i, i + 2 ) ) {
				i++;
			}
			i += 2;
			continue;
		}

		if ( "'" === source[ i ] || '"' === source[ i ] || '`' === source[ i ] ) {
			const quote = source[ i ];

			out += source[ i++ ];

			while ( i < source.length && source[ i ] !== quote ) {
				out += '\\' === source[ i ] ? source[ i++ ] + ( source[ i++ ] || '' ) : source[ i++ ];
			}

			out += source[ i++ ] || '';
			continue;
		}

		out += source[ i++ ];
	}

	return out;
}

/**
 * A navigator.clipboard, and the log of everything written to it.
 *
 * `writeText` hands back a promise taken off a queue, oldest first, so a case
 * says what the browser is going to do before the click rather than reaching
 * into a promise afterwards. An empty queue resolves, which is the ordinary
 * case; pushing `{ reject: <anything> }` is the refusal a browser gives for an
 * unfocused document or a permissions policy that says no.
 *
 * What it does NOT model: the system clipboard. Nothing here can be read back,
 * there is no `readText`, there is no user-activation requirement and there is
 * no permission prompt. A case proves that writeText was called with the right
 * string; whether anything reached the clipboard is a manual check.
 *
 * @returns {{clipboard: object, calls: Array, queue: Array}} The API, what it was given, and what it will answer.
 */
function makeClipboard() {
	const calls = [];
	const queue = [];

	return {
		calls,
		queue,
		clipboard: {
			writeText( value ) {
				calls.push( value );

				const next = queue.shift();

				if ( next && Object.prototype.hasOwnProperty.call( next, 'reject' ) ) {
					return Promise.reject( next.reject );
				}

				return Promise.resolve();
			},
		},
	};
}

/**
 * A document.execCommand, and the log of everything asked of it.
 *
 * The real one returns false when the command is unsupported or the document
 * is not editable, and `true` is never a promise: it is synchronous, and that
 * is the only reason it is still the fallback. The stub answers from a queue
 * the same way the clipboard does, and defaults to true.
 *
 * @returns {{execCommand: Function, calls: Array, queue: Array}} The function, what it was given, and what it will answer.
 */
function makeExecCommand() {
	const calls = [];
	const queue = [];

	return {
		calls,
		queue,
		execCommand( name ) {
			calls.push( name );

			const next = queue.shift();

			return undefined === next ? true : next;
		},
	};
}

/**
 * The markup Admin\Shortcode_Generator::output() prints, built node by node.
 *
 * A hand-copy of PHP, exactly like locatorMarkup() and metaboxMarkup(), and it
 * can drift from the PHP exactly like them — so harness.test.js reads
 * admin/class-shortcode-generator.php and asserts every class name used here
 * is really in it. The button is deliberately absent: the script under test is
 * what creates it, and a fixture that already had one would make the case
 * "it adds a button" pass without the script doing anything.
 *
 * @param {StubDocument} doc     The document to build in.
 * @param {object}       options `tag` (what the textarea holds), `withStatus`.
 * @returns {StubElement} The container, already in the document body.
 */
function generatorMarkup( doc, options ) {
	const settings = assign( { tag: '[store_locator]', withStatus: true }, options );

	const container = doc.createElement( 'div' );

	container.className = 'slosm-shortcode__output';

	const field = doc.createElement( 'textarea' );

	field.className = 'slosm-shortcode__text large-text code';
	field.setAttribute( 'readonly', '' );
	field.value = settings.tag;

	container.appendChild( field );

	if ( settings.withStatus ) {
		const status = doc.createElement( 'p' );

		status.className = 'slosm-shortcode__status';
		status.setAttribute( 'role', 'status' );
		status.setAttribute( 'aria-live', 'polite' );

		container.appendChild( status );
	}

	doc.body.appendChild( container );

	return container;
}

/**
 * Builds a world, runs assets/js/shortcode.js in it, hands back both.
 *
 * No Leaflet, no fetch, no AbortController and no observers, because the file
 * uses none of them — the same narrowness Assets gives the real registration,
 * where this handle has no dependency at all.
 *
 * @param {object} options `readyState`, `strings` (null omits slosmCopyL10n),
 *                         `withClipboard`, `secureContext` (null leaves the
 *                         flag off entirely), `withExecCommand`, `prepare`.
 * @returns {object} The sandbox, its pieces and `SLOSM_COPY`.
 */
function loadShortcode( options ) {
	const settings = assign(
		{
			readyState: 'interactive',
			strings: defaultCopyStrings(),
			withLeaflet: false,
			withFetch: false,
			withAbortController: false,
			withGeolocation: false,
			withIntersectionObserver: false,
			withClipboard: true,
			secureContext: true,
			withExecCommand: true,
			href: 'https://example.test/wp-admin/edit.php?post_type=slosm_store&page=slosm-shortcode',
			prepare: null,
		},
		options
	);

	const doc = new StubDocument();

	doc.readyState = settings.readyState;

	const clipboard = makeClipboard();
	const command = makeExecCommand();
	const clock = makeClock();
	const consoleCalls = { warn: [], error: [] };

	if ( settings.withExecCommand ) {
		doc.execCommand = command.execCommand;
	}

	const sandbox = makeSandbox( doc, { clipboard, clock, consoleCalls }, settings );

	if ( null !== settings.strings ) {
		sandbox.slosmCopyL10n = settings.strings;
	}

	vm.createContext( sandbox );

	const before = new Set( Object.keys( sandbox ) );

	if ( settings.prepare ) {
		settings.prepare( {
			document: doc,
			generatorMarkup: ( opts ) => generatorMarkup( doc, opts ),
		} );
	}

	const code = fs.readFileSync( SHORTCODE_PATH, 'utf8' );

	vm.runInContext( code, sandbox, { filename: SHORTCODE_PATH } );

	return {
		evaluate: () => vm.runInContext( code, sandbox, { filename: SHORTCODE_PATH } ),
		sandbox,
		newGlobals: Object.keys( sandbox ).filter( ( key ) => ! before.has( key ) ),
		document: doc,
		clipboardCalls: clipboard.calls,
		clipboardQueue: clipboard.queue,
		commandCalls: command.calls,
		commandQueue: command.queue,
		consoleCalls,
		get SLOSM_COPY() {
			return sandbox.SLOSM_COPY;
		},
		generatorMarkup: ( opts ) => generatorMarkup( doc, opts ),
	};
}

/**
 * The strings Assets::copy_strings() localises, as the script receives them.
 *
 * Deliberately not the English in the script's own fallback table: a case that
 * proves a sentence came from the localised object has to be able to tell the
 * two apart, and identical strings would make every such case pass for free.
 *
 * @returns {object} The three sentences.
 */
function defaultCopyStrings() {
	return {
		copy: 'COPY (localised)',
		copied: 'COPIED (localised)',
		manual: 'MANUAL (localised)',
	};
}

/**
 * Reads one of the plugin's own source files as text.
 *
 * @param {...string} parts Path parts under the plugin root.
 * @returns {string} File contents.
 */
function pluginSource( ...parts ) {
	return fs.readFileSync( path.join( ROOT, ...parts ), 'utf8' );
}

module.exports = {
	ROOT,
	RADIUS_CHOICES,
	LIMIT_CHOICES,
	assign,
	plain,
	ADMIN_PATH,
	LOCATOR_PATH,
	SHORTCODE_PATH,
	StubElement,
	StubFragment,
	StubText,
	StubDocument,
	abortError,
	brokenJsonResponse,
	classFromSelector,
	codeOnly,
	defaultAdminStrings,
	defaultConfig,
	defaultCopyStrings,
	defaultPickerConfig,
	defaultStrings,
	deferredResponse,
	emptyBodyResponse,
	fire,
	generatorMarkup,
	jsonResponse,
	loadAdmin,
	loadLocator,
	loadShortcode,
	locatorMarkup,
	makeClipboard,
	makeClock,
	makeExecCommand,
	makeFetch,
	makeGeolocation,
	makeLeaflet,
	makeObservers,
	makeSandbox,
	metaboxMarkup,
	pluginSource,
	position,
	positionError,
};
