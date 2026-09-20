/**
 * Task 18: the map under the coordinate fields.
 *
 * Everything here happens in a browser on one admin screen: a draggable pin
 * that writes two inputs, two inputs that move the pin, and a button that asks
 * the site where an address is. What the *save* then does with all of that is
 * PHP and lives in tests/test-admin-picker.php; the two files meet at two
 * facts, and each is asserted on both sides rather than argued in a comment:
 *
 * - the number of decimals a coordinate is written to, so that a pin dragged
 *   back where it started posts the string the save already has and is not
 *   read as a change;
 * - the way the six address fields are joined, so the button asks the
 *   geocoder the same question Admin::address_query() would ask on save.
 *
 * What the harness cannot ask here, restated because it bounds every case
 * below: there is no layout, so whether the map div has a height is a manual
 * check; there is no Draggable, so a `dragend` is fired by hand where a
 * pointer would have caused it; and nothing projects a coordinate, so whether
 * the pin is visible after a move is a manual check too.
 */

'use strict';

const { test } = require( 'node:test' );
const assert = require( 'node:assert' );

const {
	ADMIN_PATH,
	codeOnly,
	defaultAdminStrings,
	defaultPickerConfig,
	deferredResponse,
	fire,
	jsonResponse,
	loadAdmin,
	plain,
	pluginSource,
} = require( './harness.js' );

/** Warsaw, and the place the lookup button finds instead. */
const WARSAW = { lat: 52.2297, lng: 21.0122 };
const POZNAN = { lat: 52.4064, lng: 16.9252 };

/**
 * A metabox with a picker in it, already initialised.
 *
 * @param {object} options `record`, `config`, plus anything loadAdmin takes.
 * @returns {object} The harness, plus `picker` and `instance`.
 */
function picker( options ) {
	const settings = Object.assign( {}, options || {} );
	const markup = {
		record: settings.record,
		config: settings.config,
		withPicker: settings.withPicker,
		withMap: settings.withMap,
		withButton: settings.withButton,
		withLookup: settings.withLookup,
		withLat: settings.withLat,
		withLng: settings.withLng,
	};

	delete settings.record;
	delete settings.config;
	delete settings.withPicker;
	delete settings.withMap;
	delete settings.withButton;
	delete settings.withLookup;
	delete settings.withLat;
	delete settings.withLng;

	const harness = loadAdmin(
		Object.assign( settings, {
			prepare: ( world ) => world.metaboxMarkup( markup ),
		} )
	);

	harness.picker = harness.document.querySelector( '.slosm-metabox__picker' );
	harness.field = ( id ) => harness.document.getElementById( id );
	harness.message = () => {
		const node = harness.document.querySelector( '.slosm-metabox__notice' );

		return node ? node.textContent : null;
	};

	return harness;
}

/**
 * Waits for the lookup chain to finish, and says so when it does not.
 *
 * The locator exposes its own pending promise and its cases await that; a
 * picker has one container and no handle on it from outside, so this drains
 * the microtask queue instead — bounded, and against a condition rather than a
 * count, so a chain that grew a step fails here with a sentence instead of
 * passing three ticks short and asserting about a half-finished request.
 *
 * Nothing real is waited for: there is no timer and no clock tick in it.
 *
 * @param {object} harness The harness.
 * @returns {Promise<void>} Resolves once the button is free again.
 */
async function settled( harness ) {
	const button = harness.picker.querySelector( '.slosm-metabox__lookup' );

	for ( let turn = 0; turn < 50; turn++ ) {
		if ( ! button.hasAttribute( 'disabled' ) ) {
			return;
		}

		await Promise.resolve();
	}

	throw new Error( 'the lookup never finished: the button is still disabled after 50 microtask turns' );
}

/**
 * The marker the picker put on the map, or null.
 *
 * @param {object} harness The harness.
 * @returns {object|null} The layer.
 */
function pin( harness ) {
	const calls = harness.leafletCalls.marker;

	return calls.length ? calls[ calls.length - 1 ].layer : null;
}

test( 'defines exactly one global, and it is namespaced', () => {
	const harness = picker();

	// SLOSM is the locator's, and it was already there before admin.js ran.
	assert.deepStrictEqual( harness.newGlobals.sort(), [ 'SLOSM', 'SLOSM_ADMIN' ] );
} );

test( 'the namespace is frozen, so a plugin cannot quietly replace a piece of it', () => {
	const harness = picker();

	// The object first. Object.isFrozen( undefined ) is true, so a namespace
	// that was never defined passes a bare freeze assertion.
	assert.strictEqual( typeof harness.SLOSM_ADMIN, 'object' );
	assert.ok( Object.isFrozen( harness.SLOSM_ADMIN ) );
	assert.ok( Object.isFrozen( harness.SLOSM_ADMIN.STRINGS ) );
} );

test( 'the file writes no markup anywhere: no innerHTML, no HTML parsing at all', () => {
	const code = codeOnly( pluginSource( 'assets', 'js', 'admin.js' ) );

	[ 'innerHTML', 'outerHTML', 'insertAdjacentHTML', 'document.write', 'createContextualFragment' ].forEach(
		( forbidden ) => {
			assert.ok( ! code.includes( forbidden ), 'admin.js uses ' + forbidden );
		}
	);

	// The control. Every assertion above is satisfied by an empty file, so
	// the scan has to be shown to be looking at a file that writes text at
	// all — and writes it the one way that is allowed.
	assert.ok( code.includes( 'textContent' ), 'admin.js writes no text anywhere' );
	assert.ok( code.includes( 'SLOSM_ADMIN' ) );
} );

test( 'the markup scan would catch a violation, and only a real one', () => {
	assert.ok( codeOnly( 'var a = 1; // innerHTML\n' ).includes( 'innerHTML' ) === false );
	assert.ok( codeOnly( 'node.innerHTML = x;' ).includes( 'innerHTML' ) );
} );

test( 'the file contains no regex literal, which is what the comment stripper assumes', () => {
	const code = codeOnly( pluginSource( 'assets', 'js', 'admin.js' ) );

	// codeOnly() does not know a regex literal from a division, so a `/…/`
	// here would make every scan above unreliable without saying so.
	assert.ok( ! code.includes( 'RegExp' ) );
	assert.ok( ! code.includes( '.match(' ) );
	assert.ok( ! code.includes( '.replace(' ) );

	// The control, as above: the scan is looking at the real file.
	assert.ok( code.includes( 'SLOSM_ADMIN' ) );
} );

test( 'uses no jQuery', () => {
	const code = codeOnly( pluginSource( 'assets', 'js', 'admin.js' ) );

	assert.ok( ! code.includes( 'jQuery' ) );
	assert.ok( ! code.includes( '$(' ) );
	assert.ok( code.includes( 'SLOSM_ADMIN' ) );
} );

test( 'takes the tile layer from the config rather than carrying a second copy', () => {
	const code = pluginSource( 'assets', 'js', 'admin.js' );

	// The attribution is a licence condition, not decoration: the
	// OpenStreetMap tile usage policy and the ODbL both require the line on
	// the map. Two copies of it is one copy that can be edited alone.
	//
	// This used to be satisfied by reading window.SLOSM.TILE, which cost a
	// 150 KB dependency on the front-end locator; Task 21 made the tile url a
	// setting, so the server sends it here and the dependency went. The
	// no-duplication rule is unchanged, and so is this scan.
	assert.ok( ! code.includes( 'tile.openstreetmap.org' ), 'admin.js carries its own tile url' );
	assert.ok( ! code.includes( 'openstreetmap.org/copyright' ), 'admin.js carries its own attribution' );

	const config = defaultPickerConfig();
	const harness = picker( { record: { slosm_lat: '52.2297', slosm_lng: '21.0122' } } );

	assert.strictEqual( harness.leafletCalls.tileLayer.length, 1 );
	assert.strictEqual( harness.leafletCalls.tileLayer[ 0 ].url, config.tile.url );
	assert.strictEqual(
		harness.leafletCalls.tileLayer[ 0 ].options.attribution,
		config.tile.attribution
	);
	assert.strictEqual( harness.leafletCalls.tileLayer[ 0 ].options.maxZoom, config.tile.maxZoom );
} );

test( 'draws no map at all when the config carries no usable tile layer', () => {
	// Deliberate rather than defensive. A built-in fallback would be a second
	// copy of the attribution line living in admin.js, which is exactly what
	// the scan above forbids; and a map drawn with no attribution is a licence
	// breach that looks like a working map. The coordinate fields work without
	// one, so saying so is the honest answer.
	[
		{ url: 'https://tiles.example.test/tile.png', attribution: 'x', maxZoom: 19 },
		{ url: 'https://tiles.example.test/{z}/{x}/y.png', attribution: 'x', maxZoom: 19 },
		null,
	].forEach( ( tile ) => {
		const harness = picker( {
			config: defaultPickerConfig( { tile } ),
			record: { slosm_lat: '52.2297', slosm_lng: '21.0122' },
		} );

		assert.strictEqual( harness.leafletCalls.tileLayer.length, 0, JSON.stringify( tile ) );
		assert.strictEqual( harness.leafletCalls.map.length, 0, JSON.stringify( tile ) );
		assert.strictEqual( harness.message(), defaultAdminStrings().mapFailed, JSON.stringify( tile ) );
	} );

	// The control: the same fixture with the tile layer left alone draws one.
	assert.strictEqual(
		picker( { record: { slosm_lat: '52.2297', slosm_lng: '21.0122' } } ).leafletCalls.tileLayer.length,
		1
	);
} );

test( 'carries url() as a byte-for-byte copy of the locator’s, or not at all', () => {
	// Task 21 retired this file's dependency on slosm-locator, which is where
	// url() used to come from. A copy is what that cost, and this is what keeps
	// the copy honest — the arrangement Geo's duplication in locator.js already
	// set as the precedent. The routes Shortcode ships have two shapes and only
	// one of them can be appended to by hand, so a divergence here is a picker
	// whose lookup button builds a url with two question marks on every site
	// with plain permalinks.
	const between = ( source, start ) => {
		const from = source.indexOf( start );

		assert.ok( -1 !== from, 'url() is gone from ' + start );

		const end = source.indexOf( '\n\t}\n', from );

		assert.ok( -1 !== end, 'url() has no end in ' + start );

		return source.slice( from, end );
	};

	const marker = 'function url( route, params ) {';

	assert.strictEqual(
		between( pluginSource( 'assets', 'js', 'admin.js' ), marker ),
		between( pluginSource( 'assets', 'js', 'locator.js' ), marker )
	);
} );

test( 'the fixture markup uses the class names and ids the metabox really emits', () => {
	const php = pluginSource( 'admin', 'class-admin.php' );

	// Five of the six are printed by the metabox, and this is the check that
	// they are not this fixture's invention.
	[
		'slosm-metabox__picker',
		'slosm-metabox__map',
		'slosm-metabox__actions',
		'slosm-metabox__lookup',
		'data-slosm-picker',
	].forEach( ( name ) => {
		assert.ok( php.includes( name ), 'the fixture invents ' + name );
	} );

	// The sixth is not, and the list used to say it was. slosm-metabox__notice
	// is created by this script — the node it builds for its own messages — and
	// the only thing on the server side that ever mentioned it was
	// Admin::print_styles(). Task 21 moved that block into
	// assets/css/admin.css, so the honest check is against the stylesheet: a
	// class the script invents and nothing styles is an unstyled message, and
	// a class the stylesheet styles and the script does not create is dead css.
	const css = pluginSource( 'assets', 'css', 'admin.css' );

	assert.ok( css.includes( '.slosm-metabox__notice' ), 'nothing styles the message the script prints' );
	assert.ok( css.includes( '.slosm-metabox__map' ), 'nothing gives the picker map a height' );

	// The ids the script resolves. They are the field prefix plus the field
	// name, which is how print_text_field() builds them.
	[ 'slosm_lat', 'slosm_lng', 'slosm_lookup' ].forEach( ( id ) => {
		const field = id.slice( 'slosm_'.length );

		assert.ok(
			php.includes( "'" + field + "'" ) || php.includes( 'LOOKUP_FIELDS' ),
			'the fixture invents the id ' + id
		);
	} );
} );

test( 'the fixture config has exactly the keys the metabox puts in the attribute', () => {
	const php = pluginSource( 'admin', 'class-admin.php' );
	const body = php.slice( php.indexOf( 'public static function picker_config' ) );
	const keys = [];
	const end = body.indexOf( "\n\t\t}" );

	body
		.slice( 0, end )
		.split( '\n' )
		.forEach( ( line ) => {
			const match = line.match( /^\t+'([A-Za-z]+)' +=>/ );

			if ( match ) {
				keys.push( match[ 1 ] );
			}
		} );

	assert.deepStrictEqual( keys.sort(), Object.keys( defaultPickerConfig() ).sort() );
} );

/**
 * The keys Assets::admin_strings() returns, read out of the PHP.
 *
 * @returns {string[]} The keys, sorted.
 */
function adminStringKeys() {
	const php = pluginSource( 'includes', 'class-assets.php' );
	const body = php.slice( php.indexOf( 'public function admin_strings' ) );
	const keys = [];
	const end = body.indexOf( "\n\t\t}" );

	body
		.slice( 0, end )
		.split( '\n' )
		.forEach( ( line ) => {
			const match = line.match( /^\t+'([A-Za-z]+)' +=>/ );

			if ( match ) {
				keys.push( match[ 1 ] );
			}
		} );

	assert.ok( keys.length > 0, 'read no keys out of admin_strings(), so this control proves nothing' );

	return keys.sort();
}

test( 'the fixture strings have exactly the keys Assets::admin_strings() returns', () => {
	assert.deepStrictEqual( adminStringKeys(), Object.keys( defaultAdminStrings() ).sort() );
} );

test( "the picker's own fallback table has exactly the keys Assets::admin_strings() returns", () => {
	// Task 24a is why this exists, and the reason is the same one Task 29b
	// found in locator.js: three separate comments said this case was already
	// here and it was not. assets/js/admin.js's FALLBACK_STRINGS says "The keys are
	// asserted against Assets::admin_strings() in tests/js/admin-picker.test.js,
	// so neither list can grow alone", the file header says the same thing a
	// second time, and tests/js/harness.test.js's own copy of this case says
	// "admin.js has had this case since Task 22". All three were wrong. The
	// case above pins the *fixture* — a hand-copy in harness.js — against the
	// PHP, which is a different claim and leaves admin.js pinned to nothing.
	//
	// Measured before it was written: adding one key to FALLBACK_STRINGS alone
	// and running the whole suite gave 413 passed, 0 failed.
	//
	// What it prevents: text() returns window.slosmAdminL10n[ key ] when the
	// inline l10n arrived and FALLBACK_STRINGS[ key ] when it did not, so a key
	// Assets localises and admin.js has no English for writes the word
	// "undefined" into the metabox notice on any screen where a plugin has
	// dequeued the handle or an optimiser has moved the inline script.
	assert.deepStrictEqual(
		Object.keys( picker( { strings: null } ).SLOSM_ADMIN.STRINGS ).sort(),
		adminStringKeys(),
		'admin.js and Assets::admin_strings() no longer agree on the picker strings'
	);
} );

test( 'the l10n variable name is the one Assets localises to', () => {
	const php = pluginSource( 'includes', 'class-assets.php' );

	assert.ok( php.includes( "L10N_ADMIN_OBJECT = 'slosmAdminL10n'" ) );
} );

test( 'the decimals it writes are the ones the save compares at', () => {
	const php = pluginSource( 'admin', 'class-admin.php' );

	// The one number both sides have to agree on, and it travels rather than
	// being restated: the PHP puts COORDINATE_DECIMALS in the config, and the
	// script formats with whatever arrived. A case in the PHP suite asserts
	// the config really carries the constant.
	assert.ok( php.includes( 'public const COORDINATE_DECIMALS = 7;' ) );

	const harness = picker();

	assert.strictEqual( harness.SLOSM_ADMIN.fixed( 52.22970001234, 7 ), '52.2297' );
	assert.strictEqual( harness.SLOSM_ADMIN.fixed( 52.2301234567, 7 ), '52.2301235' );
	assert.strictEqual( harness.SLOSM_ADMIN.fixed( 180, 7 ), '180' );
	assert.strictEqual( harness.SLOSM_ADMIN.fixed( -0.5, 7 ), '-0.5' );

	// Which is character for character what Admin::coordinate_string() does:
	// sprintf( '%.7F' ), then the trailing zeros and any bare point come off.
	assert.ok( php.includes( "rtrim( rtrim( $formatted, '0' ), '.' )" ) );
} );

test( 'draws the map at the stored pair, with a draggable pin on it', () => {
	const harness = picker( { record: { slosm_lat: '52.2297', slosm_lng: '21.0122' } } );

	assert.strictEqual( harness.leafletCalls.map.length, 1 );
	assert.strictEqual( harness.leafletCalls.map[ 0 ].container, harness.picker.querySelector( '.slosm-metabox__map' ) );

	// scroll-wheel zoom off: this map sits in the middle of a long form, and
	// Leaflet's default swallows the page scroll of anybody passing over it.
	assert.strictEqual( harness.leafletCalls.map[ 0 ].options.scrollWheelZoom, false );

	assert.deepStrictEqual( plain( harness.leafletCalls.setView[ 0 ].center ), [ WARSAW.lat, WARSAW.lng ] );
	assert.strictEqual( harness.leafletCalls.setView[ 0 ].zoom, 15 );

	const marker = pin( harness );

	assert.ok( marker, 'no pin was put on the map' );
	assert.strictEqual( marker.options.draggable, true );
	assert.strictEqual( marker.options.autoPan, true );
	assert.strictEqual( marker.options.title, defaultAdminStrings().markerTitle );
	// Leaflet's own default alt is the untranslated word "Marker".
	assert.strictEqual( marker.options.alt, defaultAdminStrings().markerTitle );
	assert.ok( harness.leafletCalls.addTo.some( ( call ) => call.layer === marker ) );
} );

test( 'a location with no coordinates shows the map and no pin', () => {
	const harness = picker();

	assert.strictEqual( harness.leafletCalls.map.length, 1, 'no map at all for a location with no pair' );
	assert.strictEqual( harness.leafletCalls.fitWorld.length, 1, 'a location with no pair was framed somewhere specific' );
	assert.strictEqual( harness.leafletCalls.setView.length, 0 );
	assert.strictEqual( pin( harness ), null, 'a pin was invented for a location that has no coordinates' );
	assert.strictEqual( harness.message(), null, 'a location with no coordinates was complained about' );
} );

test( 'a stored half pair shows no pin and says nothing about it', () => {
	const harness = picker( { record: { slosm_lat: '52.2297' } } );

	// An import can leave one of the two behind. The save leaves such a pair
	// alone when it comes back unchanged, so the picker does too: a pin
	// cannot be drawn from half a pair, and a complaint on load would be
	// about something the person reading it has not done.
	assert.strictEqual( pin( harness ), null );
	assert.strictEqual( harness.leafletCalls.fitWorld.length, 1 );
	assert.strictEqual( harness.message(), null );
} );

test( 'a stored coordinate off the earth shows no pin either', () => {
	const harness = picker( { record: { slosm_lat: '91', slosm_lng: '21.0122' } } );

	// The save refuses it, so drawing it would put a pin where no save will
	// ever agree to keep one. Leaflet would take it: it clamps to 85.05.
	assert.strictEqual( pin( harness ), null );
	assert.strictEqual( harness.leafletCalls.fitWorld.length, 1 );
} );

test( 'a missing config leaves a readable message rather than throwing', () => {
	const harness = picker( { config: null } );

	assert.strictEqual( harness.message(), defaultAdminStrings().configError );
	assert.strictEqual( harness.leafletCalls.map.length, 0 );
} );

test( 'a malformed config leaves a readable message rather than throwing', () => {
	[
		'{',
		'null',
		'[]',
		'"a string"',
		'{}',
		'{"lat":"slosm_lat"}',
		// Each of the three shapes readConfig checks, one member at a time: a
		// string that is not one, a number that is not one, and the address
		// list that is not a list. Without all three, two of the loops could
		// be deleted with every case still green.
		JSON.stringify( defaultPickerConfig( { nonce: '' } ) ),
		JSON.stringify( defaultPickerConfig( { decimals: null } ) ),
		JSON.stringify( defaultPickerConfig( { latLimit: '90' } ) ),
		JSON.stringify( defaultPickerConfig( { address: 'slosm_city' } ) ),
	].forEach( ( raw ) => {
		const harness = picker( { config: raw } );

		assert.strictEqual( harness.message(), defaultAdminStrings().configError, 'config ' + raw );
		assert.strictEqual( harness.leafletCalls.map.length, 0, 'config ' + raw );
	} );
} );

test( 'Leaflet failing to load is a known failure, not a crash in the log', () => {
	const harness = picker( { withLeaflet: false, record: { slosm_lat: '52.2297', slosm_lng: '21.0122' } } );

	assert.strictEqual( harness.message(), defaultAdminStrings().mapFailed );

	// The fields are still there and still typeable, which is the whole
	// reason this degrades rather than fails: the picker is a convenience on
	// top of two text inputs that work without it.
	assert.strictEqual( harness.field( 'slosm_lat' ).value, '52.2297' );
} );

test( 'the locator namespace missing is no longer a failure at all', () => {
	const harness = picker( { withSlosm: false, record: { slosm_lat: '52.2297', slosm_lng: '21.0122' } } );

	// This case used to assert the opposite, and the change is the point of
	// Task 21's retirement rather than a relaxation. admin.js read the tile
	// layer and the url builder off window.SLOSM, so a site whose optimiser
	// dropped the locator handle got a sentence instead of a map — a picker
	// broken by an asset it had no business needing. It carries its own url()
	// now and is handed its tile layer in the config, so the locator's absence
	// is nothing to it.
	assert.strictEqual( harness.message(), null );
	assert.strictEqual( harness.leafletCalls.map.length, 1 );
	assert.strictEqual( harness.leafletCalls.tileLayer.length, 1 );
} );

test( 'a picker with no map div, or no coordinate fields, says so and builds nothing', () => {
	assert.strictEqual( picker( { withMap: false } ).leafletCalls.map.length, 0 );
	assert.strictEqual( picker( { withLat: false } ).leafletCalls.map.length, 0 );
	assert.strictEqual( picker( { withLng: false } ).leafletCalls.map.length, 0 );
	assert.strictEqual( picker( { withLookup: false } ).leafletCalls.map.length, 0 );

	assert.strictEqual( picker( { withMap: false } ).message(), defaultAdminStrings().configError );
} );

test( 'a map Leaflet refuses to build is reported rather than thrown', () => {
	// The markup arrives after the file has run, so this is the first time
	// this container is initialised: a second initAll() on a container that
	// already came up is a no-op, which would hide the throw rather than
	// report it.
	const harness = loadAdmin( {} );

	harness.leafletMapErrors.push( new Error( 'Map container is already initialized.' ) );
	harness.metaboxMarkup( {} );

	harness.SLOSM_ADMIN.initAll( harness.document );

	const notices = harness.document.querySelectorAll( '.slosm-metabox__notice' );

	assert.strictEqual( notices.length, 1 );
	assert.strictEqual( notices[ 0 ].textContent, defaultAdminStrings().mapFailed );
	assert.strictEqual( harness.consoleCalls.error.length, 1 );
} );

test( 'initialising twice does not build a second map on the same container', () => {
	const harness = picker( { record: { slosm_lat: '52.2297', slosm_lng: '21.0122' } } );

	harness.SLOSM_ADMIN.initAll( harness.document );

	assert.strictEqual( harness.leafletCalls.map.length, 1 );
} );

test( 'a script that runs before the parser has finished waits for it', () => {
	const harness = picker( { readyState: 'loading', record: { slosm_lat: '52.2297', slosm_lng: '21.0122' } } );

	assert.strictEqual( harness.leafletCalls.map.length, 0 );

	// Two listeners, because locator.js is on this screen and took the same
	// branch. The count is asserted rather than ignored: a file that added its
	// listener twice, or not at all, is the failure this case exists for.
	assert.strictEqual( harness.document.dispatchEvent( 'DOMContentLoaded' ), 2 );
	assert.strictEqual( harness.leafletCalls.map.length, 1 );
} );

test( 'watches for the map becoming visible, and re-measures when it does', () => {
	const harness = picker( { record: { slosm_lat: '52.2297', slosm_lng: '21.0122' } } );

	// The block editor prints every metabox inside
	// `<div id="metaboxes" class="hidden">` and moves it into view from a
	// React effect, long after this script has run. Leaflet reads
	// clientWidth once and caches it, so the map is built 0 by 0 and the only
	// thing in the vendored library that would re-measure is a window resize.
	assert.strictEqual( harness.observers.length, 1, 'nothing is watching the map for it becoming visible' );

	const observer = harness.observers[ 0 ];

	assert.deepStrictEqual( observer.targets, [ harness.picker.querySelector( '.slosm-metabox__map' ) ] );
	assert.strictEqual( harness.leafletCalls.invalidateSize.length, 0 );

	// The first delivery a real observer makes is the state the target is
	// already in, which on this screen is "not on screen".
	observer.deliver( [ { target: observer.targets[ 0 ], isIntersecting: false } ] );

	assert.strictEqual(
		harness.leafletCalls.invalidateSize.length,
		0,
		'the map re-measured itself while it was still hidden'
	);

	observer.deliver( [ { target: observer.targets[ 0 ], isIntersecting: true } ] );

	assert.strictEqual( harness.leafletCalls.invalidateSize.length, 1 );

	// No argument: `invalidateSize:function(t){…l({animate:!1,pan:!0},…)}` in
	// the vendored Leaflet, so the default keeps the centre and does not
	// animate the correction.
	assert.strictEqual( harness.leafletCalls.invalidateSize[ 0 ].options, null );
} );

test( 'a box collapsed and expanded again re-measures every time', () => {
	const harness = picker( { record: { slosm_lat: '52.2297', slosm_lng: '21.0122' } } );
	const observer = harness.observers[ 0 ];
	const target = observer.targets[ 0 ];

	// A Location box in the classic editor can be collapsed and expanded as
	// often as anybody likes, and the collapsed state is remembered per user.
	// Each expansion is a container that has just gone from no box to a box.
	observer.deliver( [ { target, isIntersecting: true } ] );
	observer.deliver( [ { target, isIntersecting: false } ] );
	observer.deliver( [ { target, isIntersecting: true } ] );

	assert.strictEqual( harness.leafletCalls.invalidateSize.length, 2 );
} );

test( 'a browser with no IntersectionObserver still gets a map', () => {
	const harness = picker( {
		withIntersectionObserver: false,
		record: { slosm_lat: '52.2297', slosm_lng: '21.0122' },
	} );

	assert.strictEqual( harness.observers.length, 0 );
	assert.strictEqual( harness.leafletCalls.map.length, 1, 'the picker refused to start without the observer' );
	assert.ok( pin( harness ), 'no pin without the observer' );
	assert.strictEqual( harness.message(), null );
} );

test( 'two bad fields are two sentences, not the first one', () => {
	const harness = picker( { record: { slosm_lat: '52.2297', slosm_lng: '21.0122' } } );

	harness.field( 'slosm_lat' ).value = 'x';
	harness.field( 'slosm_lng' ).value = '200';
	fire( harness.field( 'slosm_lat' ), 'input' );
	harness.clock.tick( harness.SLOSM_ADMIN.DELAY );

	// The save reports both — print_messages() joins its texts with a space —
	// so reporting one here means finding the other by fixing the first and
	// pressing a key.
	assert.strictEqual(
		harness.message(),
		'Latitude “x” is not a single number, so the previous value will be kept. ' +
			'Longitude “200” is outside −180 to 180, so the previous value will be kept.'
	);
} );

test( 'dragging the pin writes both fields, at the precision the save compares at', () => {
	const harness = picker( { record: { slosm_lat: '52.2297', slosm_lng: '21.0122' } } );
	const marker = pin( harness );

	marker.latlng = { lat: 52.23012345678, lng: 21.0130000001 };
	marker.fire( 'dragend' );

	assert.strictEqual( harness.field( 'slosm_lat' ).value, '52.2301235' );
	assert.strictEqual( harness.field( 'slosm_lng' ).value, '21.013' );
} );

test( 'a pin dragged back to where it started writes the string that was already there', () => {
	const harness = picker( { record: { slosm_lat: '52.2297', slosm_lng: '21.0122' } } );
	const marker = pin( harness );

	marker.latlng = { lat: 52.2297, lng: 21.0122 };
	marker.fire( 'dragend' );

	// The save compares at COORDINATE_DECIMALS, so an identical string is a
	// save that changes nothing and locks nothing. A picker writing more
	// decimals than the save keeps would lock every location it touched.
	assert.strictEqual( harness.field( 'slosm_lat' ).value, '52.2297' );
	assert.strictEqual( harness.field( 'slosm_lng' ).value, '21.0122' );
} );

test( 'dragging announces the change on the fields and does not re-enter itself', () => {
	const harness = picker( { record: { slosm_lat: '52.2297', slosm_lng: '21.0122' } } );
	const marker = pin( harness );
	const seen = [];

	[ 'slosm_lat', 'slosm_lng' ].forEach( ( id ) => {
		harness.field( id ).addEventListener( 'change', () => seen.push( 'change:' + id ) );
		harness.field( id ).addEventListener( 'input', () => seen.push( 'input:' + id ) );
	} );

	marker.latlng = { lat: 52.24, lng: 21.02 };
	marker.fire( 'dragend' );

	harness.clock.tick( 1000 );

	// `change` so that anything else on the screen — an unsaved-changes
	// watcher, another plugin — knows the field moved. Never `input`, which
	// is the event the picker itself listens for: firing it would make every
	// drag re-run the typing path and fight the pin it just moved.
	assert.deepStrictEqual( seen, [ 'change:slosm_lat', 'change:slosm_lng' ] );
	assert.strictEqual( harness.leafletCalls.setLatLng.length, 0 );
} );

test( 'dragging clears the lookup field, because a pin somebody moved is a pin somebody placed', () => {
	const harness = picker( { record: { slosm_lat: '52.2297', slosm_lng: '21.0122' } } );

	harness.field( 'slosm_lookup' ).value = '52.2297,21.0122';

	const marker = pin( harness );

	marker.latlng = { lat: 52.24, lng: 21.02 };
	marker.fire( 'dragend' );

	// Left alone, the save would read "a lookup put this here" about a pin
	// that was dragged afterwards, and would leave the location unlocked for
	// the geocoder to overwrite.
	assert.strictEqual( harness.field( 'slosm_lookup' ).value, '' );
} );

test( 'typing a pair moves the pin, once, after the field has settled', () => {
	const harness = picker( { record: { slosm_lat: '52.2297', slosm_lng: '21.0122' } } );
	const marker = pin( harness );

	'52.4064'.split( '' ).forEach( ( _, index ) => {
		harness.field( 'slosm_lat' ).value = '52.4064'.slice( 0, index + 1 );
		fire( harness.field( 'slosm_lat' ), 'input' );
	} );

	// Nothing has moved yet: a map that jumped on every keystroke would jump
	// to "5", then to "52", then to "52.4" on the way to one coordinate.
	assert.strictEqual( harness.leafletCalls.setLatLng.length, 0 );

	// The number, not whatever the file says it is. Ticking by the constant
	// alone is satisfied by a delay of zero, which is no debounce at all.
	assert.strictEqual( harness.SLOSM_ADMIN.DELAY, 300 );

	harness.clock.tick( 299 );

	assert.strictEqual( harness.leafletCalls.setLatLng.length, 0, 'the map moved before the field settled' );

	harness.clock.tick( 1 );

	assert.strictEqual( harness.leafletCalls.setLatLng.length, 1 );
	assert.deepStrictEqual( marker.getLatLng(), { lat: 52.4064, lng: 21.0122 } );
} );

test( 'typing a pair into an empty location creates the pin', () => {
	const harness = picker();

	assert.strictEqual( pin( harness ), null );

	harness.field( 'slosm_lat' ).value = '52.2297';
	harness.field( 'slosm_lng' ).value = '21.0122';
	fire( harness.field( 'slosm_lng' ), 'input' );
	harness.clock.tick( harness.SLOSM_ADMIN.DELAY );

	const marker = pin( harness );

	assert.ok( marker, 'typing a pair did not put a pin on the map' );
	assert.deepStrictEqual( marker.getLatLng(), { lat: WARSAW.lat, lng: WARSAW.lng } );
	assert.strictEqual( marker.options.draggable, true );
	assert.deepStrictEqual( plain( harness.leafletCalls.setView[ 0 ].center ), [ WARSAW.lat, WARSAW.lng ] );
} );

test( 'clearing both fields takes the pin off and says what the save will do', () => {
	const harness = picker( { record: { slosm_lat: '52.2297', slosm_lng: '21.0122' } } );
	const marker = pin( harness );

	harness.field( 'slosm_lat' ).value = '';
	harness.field( 'slosm_lng' ).value = '';
	fire( harness.field( 'slosm_lat' ), 'input' );
	harness.clock.tick( harness.SLOSM_ADMIN.DELAY );

	assert.ok( harness.leafletCalls.removeLayer.some( ( call ) => call.layer === marker ) );
	assert.strictEqual( harness.message(), defaultAdminStrings().willLookUp );
} );

test( 'clearing one field says a pair is needed and leaves the pin alone', () => {
	const harness = picker( { record: { slosm_lat: '52.2297', slosm_lng: '21.0122' } } );
	const marker = pin( harness );

	harness.field( 'slosm_lng' ).value = '';
	fire( harness.field( 'slosm_lng' ), 'input' );
	harness.clock.tick( harness.SLOSM_ADMIN.DELAY );

	// The save puts both values back and says so; the picker says it first,
	// on the screen, while the person who pressed Backspace is still looking
	// at it. That is the whole of what this half of the task is for.
	assert.strictEqual( harness.message(), defaultAdminStrings().pairNeeded );
	assert.deepStrictEqual( marker.getLatLng(), { lat: WARSAW.lat, lng: WARSAW.lng } );
	assert.strictEqual( harness.leafletCalls.removeLayer.length, 0 );
} );

test( 'a comma decimal is announced, and the pin goes where the save will read it', () => {
	const harness = picker( { record: { slosm_lat: '52.2297', slosm_lng: '21.0122' } } );
	const marker = pin( harness );

	harness.field( 'slosm_lat' ).value = '52,4064';
	fire( harness.field( 'slosm_lat' ), 'input' );
	harness.clock.tick( harness.SLOSM_ADMIN.DELAY );

	assert.strictEqual(
		harness.message(),
		'Latitude “52,4064” will be read as 52.4064. Use a dot for the decimal point.'
	);
	assert.deepStrictEqual( marker.getLatLng(), { lat: 52.4064, lng: 21.0122 } );
} );

test( 'a whole pair pasted into one field is refused, as the save refuses it', () => {
	const harness = picker( { record: { slosm_lat: '52.2297', slosm_lng: '21.0122' } } );
	const marker = pin( harness );

	harness.field( 'slosm_lat' ).value = '52.2297, 21.0122';
	fire( harness.field( 'slosm_lat' ), 'input' );
	harness.clock.tick( harness.SLOSM_ADMIN.DELAY );

	assert.strictEqual(
		harness.message(),
		'Latitude “52.2297, 21.0122” is not a single number, so the previous value will be kept.'
	);
	assert.deepStrictEqual( marker.getLatLng(), { lat: WARSAW.lat, lng: WARSAW.lng } );
} );

test( 'a coordinate off the earth is announced against its own limit', () => {
	const harness = picker( { record: { slosm_lat: '52.2297', slosm_lng: '21.0122' } } );

	harness.field( 'slosm_lat' ).value = '120';
	fire( harness.field( 'slosm_lat' ), 'input' );
	harness.clock.tick( harness.SLOSM_ADMIN.DELAY );

	assert.strictEqual(
		harness.message(),
		'Latitude “120” is outside −90 to 90, so the previous value will be kept.'
	);

	// 120 is a longitude and not a latitude, which is the whole reason the
	// two limits are two numbers rather than one.
	harness.field( 'slosm_lat' ).value = '52.2297';
	harness.field( 'slosm_lng' ).value = '120';
	fire( harness.field( 'slosm_lng' ), 'input' );
	harness.clock.tick( harness.SLOSM_ADMIN.DELAY );

	assert.strictEqual( harness.message(), null );

	harness.field( 'slosm_lng' ).value = '200';
	fire( harness.field( 'slosm_lng' ), 'input' );
	harness.clock.tick( harness.SLOSM_ADMIN.DELAY );

	assert.strictEqual(
		harness.message(),
		'Longitude “200” is outside −180 to 180, so the previous value will be kept.'
	);
} );

test( 'typing clears the lookup field too', () => {
	const harness = picker( { record: { slosm_lat: '52.2297', slosm_lng: '21.0122' } } );

	harness.field( 'slosm_lookup' ).value = '52.2297,21.0122';
	harness.field( 'slosm_lat' ).value = '52.4064';
	fire( harness.field( 'slosm_lat' ), 'input' );
	harness.clock.tick( harness.SLOSM_ADMIN.DELAY );

	assert.strictEqual( harness.field( 'slosm_lookup' ).value, '' );
} );

test( 'the lookup button asks the site where the address is, and asks it nothing else', async () => {
	const harness = picker( {
		record: {
			slosm_address: ' Nowy Świat 1 ',
			slosm_address2: '',
			slosm_city: 'Warszawa',
			slosm_state: '',
			slosm_zip: '00-001',
			slosm_country: 'Polska',
		},
	} );

	harness.fetchQueue.push( jsonResponse( { lat: POZNAN.lat, lng: POZNAN.lng, label: 'Poznań' } ) );

	fire( harness.picker.querySelector( '.slosm-metabox__lookup' ), 'click' );

	await settled( harness );

	assert.strictEqual( harness.fetchCalls.length, 1 );

	const asked = new URL( harness.fetchCalls[ 0 ].url );

	// Joined the way Admin::address_query() joins it: trimmed, empties
	// skipped, ', ' between. A different join is a different place.
	assert.strictEqual( asked.searchParams.get( 'q' ), 'Nowy Świat 1, Warszawa, 00-001, Polska' );
	assert.deepStrictEqual( Array.from( asked.searchParams.keys() ), [ 'q' ] );

	// The country goes into the query and never into the `country` parameter,
	// which Nominatim reads as an ISO 3166-1 alpha-2 code: "Polska" as a
	// country code restricts the search to a country that does not exist.
	assert.strictEqual( asked.searchParams.get( 'country' ), null );

	const init = harness.fetchCalls[ 0 ].init;

	assert.strictEqual( init.credentials, 'same-origin' );
	assert.strictEqual( init.headers[ 'X-WP-Nonce' ], 'nonce:wp_rest' );
	assert.deepStrictEqual( Object.keys( init.headers ), [ 'X-WP-Nonce' ] );
} );

test( 'a successful lookup fills the fields, moves the pin and records that it did', async () => {
	const harness = picker( {
		record: { slosm_lat: '52.2297', slosm_lng: '21.0122', slosm_city: 'Poznań' },
	} );

	harness.fetchQueue.push( jsonResponse( { lat: POZNAN.lat, lng: POZNAN.lng, label: 'Poznań' } ) );

	fire( harness.picker.querySelector( '.slosm-metabox__lookup' ), 'click' );

	await settled( harness );

	assert.strictEqual( harness.field( 'slosm_lat' ).value, '52.4064' );
	assert.strictEqual( harness.field( 'slosm_lng' ).value, '16.9252' );

	// The pair, not a flag. The save compares what was posted against what
	// the lookup wrote, so a pin dragged after the lookup reads as a hand
	// and locks — which a bare "a lookup happened" could not tell apart.
	assert.strictEqual( harness.field( 'slosm_lookup' ).value, '52.4064,16.9252' );

	assert.deepStrictEqual( pin( harness ).getLatLng(), { lat: POZNAN.lat, lng: POZNAN.lng } );
	assert.strictEqual( harness.message(), defaultAdminStrings().lookupDone );
} );

test( 'the lookup says what it is doing while it does it, and re-enables the button', async () => {
	const harness = picker( { record: { slosm_city: 'Poznań' } } );
	const deferred = deferredResponse();
	const button = harness.picker.querySelector( '.slosm-metabox__lookup' );

	harness.fetchQueue.push( deferred );

	fire( button, 'click' );

	assert.strictEqual( harness.message(), defaultAdminStrings().looking );
	assert.strictEqual( button.getAttribute( 'disabled' ), 'disabled' );

	// A second click while the first is in flight asks nothing: the button is
	// disabled, and the harness does not model a browser refusing a click on
	// a disabled control, so the guard has to be the script's own.
	fire( button, 'click' );

	assert.strictEqual( harness.fetchCalls.length, 1 );

	deferred.resolve( jsonResponse( { lat: POZNAN.lat, lng: POZNAN.lng, label: 'Poznań' } ) );

	await settled( harness );

	assert.strictEqual( button.hasAttribute( 'disabled' ), false );
	assert.strictEqual( harness.message(), defaultAdminStrings().lookupDone );
} );

test( 'an empty address is said rather than asked about', () => {
	const harness = picker();

	fire( harness.picker.querySelector( '.slosm-metabox__lookup' ), 'click' );

	assert.strictEqual( harness.fetchCalls.length, 0 );
	assert.strictEqual( harness.message(), defaultAdminStrings().lookupNoAddress );

	// A field holding only spaces is a field somebody cleared.
	harness.field( 'slosm_city' ).value = '   ';
	fire( harness.picker.querySelector( '.slosm-metabox__lookup' ), 'click' );

	assert.strictEqual( harness.fetchCalls.length, 0 );
} );

test( 'each way the lookup can fail says a different thing', async () => {
	const cases = [
		[ 404, 'lookupNoMatch' ],
		[ 400, 'lookupNoMatch' ],
		[ 429, 'lookupBusy' ],
		[ 502, 'lookupFailed' ],
		[ 500, 'lookupFailed' ],
	];

	for ( const [ status, key ] of cases ) {
		const harness = picker( { record: { slosm_city: 'Nowhere' } } );

		harness.fetchQueue.push( jsonResponse( { code: 'x' }, { ok: false, status } ) );

		fire( harness.picker.querySelector( '.slosm-metabox__lookup' ), 'click' );

		await settled( harness );

		assert.strictEqual( harness.message(), defaultAdminStrings()[ key ], 'status ' + status );
		assert.strictEqual( harness.field( 'slosm_lat' ).value, '', 'status ' + status + ' wrote a coordinate' );
	}
} );

test( 'a 200 that is not a place is a failure, not a pin in the Gulf of Guinea', async () => {
	for ( const body of [ {}, { lat: '52.4064', lng: '16.9252' }, { lat: 91, lng: 16.9252 }, null ] ) {
		const harness = picker( { record: { slosm_city: 'Poznań' } } );

		harness.fetchQueue.push( jsonResponse( body ) );

		fire( harness.picker.querySelector( '.slosm-metabox__lookup' ), 'click' );

		await settled( harness );

		assert.strictEqual( harness.message(), defaultAdminStrings().lookupFailed, JSON.stringify( body ) );
		assert.strictEqual( pin( harness ), null, JSON.stringify( body ) );
		assert.strictEqual( harness.field( 'slosm_lookup' ).value, '' );

		// The console line is for a developer and is the only place the four
		// bodies differ. Without the shape check a null body reaches
		// `body.lat` and the log says "Cannot read properties of null", which
		// describes this file rather than the answer it was given.
		assert.strictEqual( harness.consoleCalls.warn.length, 1, JSON.stringify( body ) );
		assert.ok(
			harness.consoleCalls.warn[ 0 ][ 0 ].includes( 'did not answer with a place' ),
			JSON.stringify( body ) + ' logged: ' + harness.consoleCalls.warn[ 0 ][ 0 ]
		);
	}
} );

test( 'a failed lookup leaves the coordinates that were already there', async () => {
	const harness = picker( {
		record: { slosm_lat: '52.2297', slosm_lng: '21.0122', slosm_city: 'Nowhere' },
	} );

	harness.fetchQueue.push( jsonResponse( null, { ok: false, status: 404 } ) );

	fire( harness.picker.querySelector( '.slosm-metabox__lookup' ), 'click' );

	await settled( harness );

	// The service having nothing to say about an address is not evidence
	// about where the shop is.
	assert.strictEqual( harness.field( 'slosm_lat' ).value, '52.2297' );
	assert.deepStrictEqual( pin( harness ).getLatLng(), { lat: WARSAW.lat, lng: WARSAW.lng } );
} );

test( 'a lookup with no route to call says so rather than fetching nothing', () => {
	const harness = picker( {
		config: defaultPickerConfig( { geocode: 'http://[' } ),
		record: { slosm_city: 'Poznań' },
	} );

	fire( harness.picker.querySelector( '.slosm-metabox__lookup' ), 'click' );

	assert.strictEqual( harness.fetchCalls.length, 0 );
	assert.strictEqual( harness.message(), defaultAdminStrings().lookupFailed );
} );

test( 'only one message is on screen at a time', () => {
	const harness = picker( { record: { slosm_lat: '52.2297', slosm_lng: '21.0122' } } );

	harness.field( 'slosm_lat' ).value = 'x';
	fire( harness.field( 'slosm_lat' ), 'input' );
	harness.clock.tick( harness.SLOSM_ADMIN.DELAY );

	harness.field( 'slosm_lat' ).value = '52,4064';
	fire( harness.field( 'slosm_lat' ), 'input' );
	harness.clock.tick( harness.SLOSM_ADMIN.DELAY );

	assert.strictEqual( harness.document.querySelectorAll( '.slosm-metabox__notice' ).length, 1 );
	assert.strictEqual(
		harness.message(),
		'Latitude “52,4064” will be read as 52.4064. Use a dot for the decimal point.'
	);

	// And a good value takes the message away again rather than leaving a
	// refusal on screen beside a field that no longer holds one.
	harness.field( 'slosm_lat' ).value = '52.4064';
	fire( harness.field( 'slosm_lat' ), 'input' );
	harness.clock.tick( harness.SLOSM_ADMIN.DELAY );

	assert.strictEqual( harness.message(), null );
} );

test( 'a message full of markup stays text', () => {
	const harness = picker( {
		strings: defaultAdminStrings( {
			notANumber: '<img src=x onerror=alert(1)> %1$s %2$s',
		} ),
		record: { slosm_lat: '52.2297', slosm_lng: '21.0122' },
	} );

	harness.field( 'slosm_lat' ).value = 'x';
	fire( harness.field( 'slosm_lat' ), 'input' );
	harness.clock.tick( harness.SLOSM_ADMIN.DELAY );

	// The strings come out of a .po file somebody may have edited. The
	// harness has no innerHTML at all, so a path to a parser is a thrown
	// error in this case rather than a quiet pass.
	assert.strictEqual( harness.message(), '<img src=x onerror=alert(1)> Latitude x' );
} );

test( 'falls back to English when slosmAdminL10n never arrived', () => {
	const harness = picker( { strings: null, config: null } );

	assert.strictEqual( harness.message(), harness.SLOSM_ADMIN.STRINGS.configError );
	assert.strictEqual( harness.SLOSM_ADMIN.STRINGS.configError, defaultAdminStrings().configError );
} );

test( 'falls back per key, not all or nothing', () => {
	const harness = picker( { strings: { configError: '' }, config: null } );

	assert.strictEqual( harness.message(), defaultAdminStrings().configError );
} );

test( 'the address query is built the way the server builds it', () => {
	const harness = picker();

	// Admin::address_query(): trim each component, skip the empty ones, join
	// with ', '. The PHP suite asserts the same three rules on its side.
	assert.strictEqual(
		harness.SLOSM_ADMIN.query( [ ' a ', '', '   ', 'b' ] ),
		'a, b'
	);
	assert.strictEqual( harness.SLOSM_ADMIN.query( [] ), '' );
	assert.strictEqual( harness.SLOSM_ADMIN.query( [ ' ', '' ] ), '' );

	const php = pluginSource( 'admin', 'class-admin.php' );

	assert.ok( php.includes( "return implode( ', ', $parts );" ) );
	assert.ok( php.includes( "$value = trim( self::text( $fields, $field ) );" ) );
} );

test( 'reads a coordinate exactly the way the save reads it', () => {
	const harness = picker();
	const read = ( raw, limit ) => harness.SLOSM_ADMIN.coordinate( raw, limit );

	assert.strictEqual( read( '', 90 ).status, 'empty' );
	assert.strictEqual( read( '  ', 90 ).status, 'empty' );
	assert.strictEqual( read( '52.2297', 90 ).status, 'ok' );
	assert.strictEqual( read( '52.2297', 90 ).value, 52.2297 );
	assert.strictEqual( read( '52,2297', 90 ).status, 'normalised' );
	assert.strictEqual( read( '52,2297', 90 ).value, 52.2297 );
	assert.strictEqual( read( '-52,2297', 90 ).status, 'normalised' );
	assert.strictEqual( read( '52.2297, 21.0122', 90 ).status, 'not_a_number' );
	assert.strictEqual( read( '1,234,567', 90 ).status, 'not_a_number' );

	// The separating input for the comma rule's own digit check. Without it
	// '5,3e2' becomes '5.3e2', which *is* a number — 530 — so the field would
	// report a latitude off the earth where the save reports a value that is
	// not a single number. Both sides refuse it, and they refuse it the same
	// way.
	assert.strictEqual( read( '5,3e2', 90 ).status, 'not_a_number' );
	assert.strictEqual( read( 'x', 90 ).status, 'not_a_number' );
	assert.strictEqual( read( '91', 90 ).status, 'out_of_range' );
	assert.strictEqual( read( '90', 90 ).status, 'ok' );
	assert.strictEqual( read( '-90', 90 ).status, 'ok' );
	assert.strictEqual( read( '0', 90 ).status, 'ok' );

	// The typed string is carried through, because every message quotes it
	// back and quoting back a value nobody typed is worse than saying nothing.
	assert.strictEqual( read( ' 52,2297 ', 90 ).typed, '52,2297' );

	// The same narrow comma rule the PHP has, character for character.
	const php = pluginSource( 'admin', 'class-admin.php' );

	assert.ok( php.includes( "preg_match( '/^[+-]?[0-9]+,[0-9]+$/', $typed )" ) );
} );

test( 'the file it runs is the file the enqueue points at', () => {
	// A small thing and the reason ADMIN_PATH exists: the whole of this file
	// is worth nothing if the harness is evaluating something the plugin does
	// not ship.
	const php = pluginSource( 'includes', 'class-assets.php' );

	assert.ok( php.includes( "SRC_ADMIN = 'assets/js/admin.js'" ) );
	assert.ok( ADMIN_PATH.endsWith( 'admin.js' ) );
} );
