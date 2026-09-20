<?php
/**
 * Task 22: the screen that writes a [store_locator] tag.
 *
 * THE TWO CLAIMS THIS FILE EXISTS TO HOLD
 * =======================================
 * Everything else here is ordinary admin-screen work — a capability, a form,
 * some escaping. Two things are not, and they are what most of the cases below
 * are about.
 *
 * **The generator has no second copy of the attribute list.** Not the names,
 * not the bounds, not the defaults. The names come from
 * `Shortcode::raw_defaults()`, the bounds from the constants `Shortcode` and
 * `Rest_Controller` clamp with, the closed lists from `Geo::UNITS` and
 * `Settings::CLUSTER_CHOICES`, and there is no default table anywhere in
 * admin/class-shortcode-generator.php at all — "differs from the site
 * defaults" is decided by *asking the resolver*, not by comparing against a
 * remembered value. The case *has a control for every attribute the shortcode
 * takes, and for nothing else* is the tripwire the brief asked for: adding an
 * attribute to `Shortcode` and forgetting this screen fails it. The mutation
 * sweep does exactly that and records it.
 *
 * **The preview cannot differ from the front end.** It is not a rendering of
 * the locator and it is not a second resolver. The screen composes a string,
 * parses that string with core's own regex and core's own attribute parser,
 * and hands the result to `Shortcode::attributes()` and `Shortcode::config()`
 * — the same two calls `Shortcode::render()` makes. The case *the preview is
 * what attributes() makes of the composed string, worked out again here* does
 * the same thing independently and compares, so "the preview is the parse of
 * the string" is an assertion rather than a design note.
 *
 * WHAT THE BOOTSTRAP HAD TO GROW FOR THIS, AND WHY IT IS COPIED VERBATIM
 * =====================================================================
 * `get_shortcode_regex()`, `get_shortcode_atts_regex()` and
 * `shortcode_parse_atts()`, reproduced character for character out of
 * wp-includes/shortcodes.php of WordPress 6.9.1 with the line numbers on them.
 * Verbatim and not approximated, because the whole value of the round-trip
 * cases is that they parse the way a site will: a stub that accepted a quote
 * inside a quoted value, or that let a `]` through the middle of a tag, would
 * turn every escaping case below into a test of the stub.
 *
 * @package Store_Locator_For_OpenStreetMap
 */

require_once dirname( __DIR__ ) . '/includes/class-geo.php';
require_once dirname( __DIR__ ) . '/includes/class-store.php';
require_once dirname( __DIR__ ) . '/includes/class-post-type.php';
require_once dirname( __DIR__ ) . '/includes/class-store-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-geocoder.php';
require_once dirname( __DIR__ ) . '/includes/class-rest-controller.php';
require_once dirname( __DIR__ ) . '/includes/class-assets.php';
require_once dirname( __DIR__ ) . '/includes/class-shortcode.php';
require_once dirname( __DIR__ ) . '/includes/class-settings.php';
require_once dirname( __DIR__ ) . '/includes/class-plugin.php';
// boot() constructs all five admin objects, and one case here boots a plugin to
// see which hooks this screen is wired on. The suite has no autoloader.
require_once dirname( __DIR__ ) . '/admin/class-admin.php';
require_once dirname( __DIR__ ) . '/admin/class-locations-list.php';
require_once dirname( __DIR__ ) . '/admin/class-bulk-geocode.php';
require_once dirname( __DIR__ ) . '/admin/class-settings-screen.php';
require_once dirname( __DIR__ ) . '/admin/class-shortcode-generator.php';

use Asymetria\StoreLocator\Admin\Shortcode_Generator;
use Asymetria\StoreLocator\Assets;
use Asymetria\StoreLocator\Geo;
use Asymetria\StoreLocator\Plugin;
use Asymetria\StoreLocator\Post_Type;
use Asymetria\StoreLocator\Rest_Controller;
use Asymetria\StoreLocator\Settings;
use Asymetria\StoreLocator\Shortcode;

if ( ! function_exists( 'slosm_generator' ) ) {
	/**
	 * A generator over a real Shortcode, which is the only kind there is.
	 *
	 * There is deliberately no double here. The whole subject of this screen is
	 * what the front end's resolver says, so a fake resolver would leave
	 * nothing worth asserting.
	 *
	 * @return Shortcode_Generator
	 */
	function slosm_generator(): Shortcode_Generator {
		return new Shortcode_Generator( new Shortcode() );
	}
}

if ( ! function_exists( 'slosm_generator_settings' ) ) {
	/**
	 * Stages the settings option.
	 *
	 * @param array $settings Keys to set; everything else stays default.
	 * @return void
	 */
	function slosm_generator_settings( array $settings ): void {
		$GLOBALS['slosm_stub']['options'][ Settings::OPTION ] = $settings;
	}
}

if ( ! function_exists( 'slosm_generator_render' ) ) {
	/**
	 * The screen's html, rendered for a user who may see it.
	 *
	 * @param array $atts What the query string asks for.
	 * @return string
	 */
	function slosm_generator_render( array $atts = array() ): string {
		$GLOBALS['slosm_stub']['capabilities'][ Shortcode_Generator::CAPABILITY ] = true;
		$GLOBALS['slosm_stub']['post_counts'][ Post_Type::POST_TYPE ]             = (object) array( 'publish' => 4 );

		$_GET[ Shortcode_Generator::FIELD ] = $atts;

		ob_start();
		slosm_generator()->render();
		$html = (string) ob_get_clean();

		unset( $_GET[ Shortcode_Generator::FIELD ] );

		return $html;
	}
}

if ( ! function_exists( 'slosm_generator_boot' ) ) {
	/**
	 * A freshly booted plugin, standing for one request.
	 *
	 * A fresh instance rather than Plugin::instance(), for the reason
	 * tests/test-cache-invalidation.php gives at length: the singleton boots at
	 * most once per process, so by the time this file runs a
	 * `Plugin::instance()->boot()` would register nothing at all and every case
	 * about the wiring would pass against an empty table.
	 *
	 * @return Plugin
	 */
	function slosm_generator_boot(): Plugin {
		$construct = Closure::bind(
			static function () {
				return new Plugin();
			},
			null,
			Plugin::class
		);

		$plugin = $construct();
		$plugin->boot();

		return $plugin;
	}
}

if ( ! function_exists( 'slosm_generator_resolve' ) ) {
	/**
	 * What a shortcode really resolves to, worked out without the generator.
	 *
	 * Core's regex, core's attribute parser, the front end's resolver — the
	 * three steps `do_shortcode()` takes, written out here so that a case can
	 * compare the screen's preview against something that is not the screen.
	 *
	 * @param string $tag A shortcode as text.
	 * @return array
	 */
	function slosm_generator_resolve( string $tag ): array {
		$pattern = get_shortcode_regex( array( Shortcode::TAG ) );

		if ( 1 !== preg_match( "/$pattern/", $tag, $match ) ) {
			throw new Assertion_Failed( 'the composed tag does not match core\'s shortcode regex: ' . $tag );
		}

		$shortcode = new Shortcode();

		return $shortcode->attributes( shortcode_parse_atts( $match[3] ) );
	}
}

describe(
	'Shortcode_Generator — where the screen is',
	function () {
		it(
			'adds one page, under the locations menu beside Settings',
			function () {
				slosm_generator()->add_page();

				$pages = $GLOBALS['slosm_stub']['admin_pages'];

				assert_same( 1, count( $pages ) );
				assert_same( 'edit.php?post_type=' . Post_Type::POST_TYPE, $pages[0]['parent_slug'] );
				assert_same( Shortcode_Generator::PAGE, $pages[0]['menu_slug'] );
			}
		);

		it(
			'asks for edit_posts, which is what the locations list already needs',
			function () {
				slosm_generator()->add_page();

				assert_same( 'edit_posts', $GLOBALS['slosm_stub']['admin_pages'][0]['capability'] );
				assert_same( 'edit_posts', Shortcode_Generator::CAPABILITY );

				// And not the settings screen's capability. An author who may
				// write a page may build the shortcode that goes on it.
				assert_false( 'manage_options' === Shortcode_Generator::CAPABILITY );
			}
		);

		it(
			'has the slug Assets recognises, so the screen gets its stylesheet and its script',
			function () {
				assert_same( Assets::SCREEN_SHORTCODE, Shortcode_Generator::PAGE );
				assert_same( 'slosm-shortcode', Shortcode_Generator::PAGE );

				// Core builds the hook suffix as {page_type}_page_{slug}, and
				// Assets matches on the ending. The stub builds the same shape.
				assert_same(
					Post_Type::POST_TYPE . '_page_' . Shortcode_Generator::PAGE,
					slosm_generator()->add_page()
				);
			}
		);

		it(
			'links back to itself with the two arguments that name the screen',
			function () {
				$url = Shortcode_Generator::url();

				assert_contains( 'post_type=' . Post_Type::POST_TYPE, $url );
				assert_contains( 'page=' . Shortcode_Generator::PAGE, $url );
			}
		);

		it(
			'is wired from boot(), on admin_menu and on nothing else',
			function () {
				slosm_generator_boot();

				$hooks = array();

				foreach ( $GLOBALS['slosm_stub']['actions'] as $hook => $entries ) {
					foreach ( $entries as $entry ) {
						if ( is_array( $entry['callback'] )
							&& is_object( $entry['callback'][0] )
							&& $entry['callback'][0] instanceof Shortcode_Generator ) {
							$hooks[] = $hook . ':' . $entry['callback'][1];
						}
					}
				}

				assert_same( array( 'admin_menu:add_page' ), $hooks );
			}
		);
	}
);

describe(
	'Shortcode_Generator — who may see it',
	function () {
		it(
			'renders for a user who may edit posts, so the refusal below is a refusal',
			function () {
				$html = slosm_generator_render();

				assert_contains( 'slosm-shortcode__text', $html );
				assert_same( array(), $GLOBALS['slosm_stub']['died'] );
			}
		);

		it(
			'refuses a user who may not edit posts',
			function () {
				$GLOBALS['slosm_stub']['capabilities'][ Shortcode_Generator::CAPABILITY ] = false;

				$died = false;

				try {
					ob_start();
					slosm_generator()->render();
					ob_end_clean();
				} catch ( Slosm_Died $e ) {
					$died = true;
				}

				assert_true( $died, 'the screen rendered for a user without the capability' );
				assert_same( 403, $GLOBALS['slosm_stub']['died'][0]['args'] );
			}
		);

		it(
			'writes nothing, so it registers no admin_post handler and needs no nonce',
			function () {
				slosm_generator_boot();

				$found = 0;

				foreach ( array_keys( $GLOBALS['slosm_stub']['actions'] ) as $hook ) {
					foreach ( $GLOBALS['slosm_stub']['actions'][ $hook ] as $entry ) {
						if ( is_array( $entry['callback'] )
							&& is_object( $entry['callback'][0] )
							&& $entry['callback'][0] instanceof Shortcode_Generator ) {
							$found++;

							assert_false(
								str_starts_with( $hook, 'admin_post' ),
								'the generator hung something on ' . $hook
							);
						}
					}
				}

				// The control for the absence: the screen is registered
				// somewhere, so "on nothing beginning with admin_post" is a
				// fact about this class rather than about a screen that was
				// never wired at all.
				assert_same( 1, $found, 'the generator is not wired from boot() at all' );

				// The control for that absence: the screen this one sits beside
				// *does* have one, so "no admin_post handler" is a fact about
				// this class rather than about the stub.
				assert_true( isset( $GLOBALS['slosm_stub']['actions']['admin_post_slosm_clear_cache'] ) );

				// And nothing on the screen asks for a nonce back.
				assert_false( str_contains( slosm_generator_render(), 'wp_nonce' ) );
				assert_false( str_contains( slosm_generator_render(), '_wpnonce' ) );
			}
		);
	}
);

describe(
	'Shortcode_Generator — what the screen loads',
	function () {
		it(
			'gets the copy script on its own screen and on no other',
			function () {
				$assets = new Assets();
				$hook   = Post_Type::POST_TYPE . '_page_' . Shortcode_Generator::PAGE;

				foreach (
					array(
						array( $hook, 'slosm_store_page_slosm-shortcode', true ),
						array( 'slosm_store_page_slosm-settings', 'slosm_store_page_slosm-settings', false ),
						array( 'post.php', 'post', false ),
						array( 'edit.php', 'edit', false ),
						array( 'index.php', 'dashboard', false ),
					) as $screen
				) {
					slosm_stub_reset();
					slosm_stub_screen( $screen[1], Post_Type::POST_TYPE );

					$assets->enqueue_admin( $screen[0] );

					assert_same(
						$screen[2],
						in_array( Assets::SCRIPT_SHORTCODE, $GLOBALS['slosm_stub']['script_queue'], true ),
						$screen[0] . ' got the copy script wrong'
					);
				}
			}
		);

		it(
			'gets the shared admin stylesheet, the way the settings screen does',
			function () {
				$assets = new Assets();

				slosm_stub_screen( 'slosm_store_page_slosm-shortcode', Post_Type::POST_TYPE );
				$assets->enqueue_admin( Post_Type::POST_TYPE . '_page_' . Shortcode_Generator::PAGE );

				assert_true(
					in_array( Assets::STYLE_ADMIN, $GLOBALS['slosm_stub']['style_queue'], true ),
					'the generator screen got no stylesheet'
				);

				// The control for what it does *not* get: no Leaflet, no
				// front-end locator, no marker cluster. That is the whole cost
				// argument for a preview made of text.
				assert_false( in_array( Assets::STYLE_LEAFLET, $GLOBALS['slosm_stub']['style_queue'], true ) );
				assert_false( in_array( Assets::SCRIPT_LEAFLET, $GLOBALS['slosm_stub']['script_queue'], true ) );
				assert_false( in_array( Assets::SCRIPT_LOCATOR, $GLOBALS['slosm_stub']['script_queue'], true ) );
				assert_false( in_array( Assets::SCRIPT_CLUSTER, $GLOBALS['slosm_stub']['script_queue'], true ) );
				assert_false( in_array( Assets::SCRIPT_ADMIN, $GLOBALS['slosm_stub']['script_queue'], true ) );
			}
		);

		it(
			'declares the copy script with no dependency at all, and defers it',
			function () {
				$assets = new Assets();

				slosm_stub_screen( 'slosm_store_page_slosm-shortcode', Post_Type::POST_TYPE );
				$assets->enqueue_admin( Post_Type::POST_TYPE . '_page_' . Shortcode_Generator::PAGE );

				$registered = $GLOBALS['slosm_stub']['scripts'][ Assets::SCRIPT_SHORTCODE ] ?? null;

				assert_true( is_array( $registered ), 'the copy script was never registered' );
				assert_same( array(), $registered['deps'] );
				assert_contains( Assets::SRC_SHORTCODE, $registered['src'] );
				assert_same( 'defer', $registered['extra']['strategy'] ?? '' );
			}
		);

		it(
			'localises its three sentences, to an object of their own',
			function () {
				$assets = new Assets();

				slosm_stub_screen( 'slosm_store_page_slosm-shortcode', Post_Type::POST_TYPE );
				$assets->enqueue_admin( Post_Type::POST_TYPE . '_page_' . Shortcode_Generator::PAGE );

				$localised = $GLOBALS['slosm_stub']['scripts'][ Assets::SCRIPT_SHORTCODE ]['l10n'] ?? array();

				assert_same( 1, count( $localised ) );
				assert_same( Assets::L10N_SHORTCODE_OBJECT, $localised[0]['object'] );
				assert_same( 'slosmCopyL10n', Assets::L10N_SHORTCODE_OBJECT );
				assert_same( array_keys( $assets->copy_strings() ), array_keys( $localised[0]['data'] ) );
				assert_same( array( 'copy', 'copied', 'manual' ), array_keys( $assets->copy_strings() ) );
			}
		);
	}
);

describe(
	'Shortcode_Generator — the attribute list is the shortcode\'s',
	function () {
		it(
			'has a control for every attribute the shortcode takes, and for nothing else',
			function () {
				// The tripwire. An attribute added to Shortcode::raw_defaults()
				// and not to Shortcode_Generator::controls() fails here, in the
				// same order, so the failure names the attribute.
				assert_same(
					array_keys( Shortcode::raw_defaults() ),
					array_keys( Shortcode_Generator::controls() )
				);
			}
		);

		it(
			'gives every one of them a label somebody wrote',
			function () {
				// The control for a loop: an empty control list would satisfy
				// every assertion inside it.
				assert_same(
					count( Shortcode::raw_defaults() ),
					count( Shortcode_Generator::controls() )
				);

				foreach ( Shortcode_Generator::controls() as $key => $control ) {
					assert_true(
						isset( $control['label'] ) && is_string( $control['label'] ) && '' !== $control['label'],
						$key . ' has no label'
					);
					assert_false( $control['label'] === $key, $key . ' is labelled with its own attribute name' );
				}
			}
		);

		it(
			'prints a named field for every attribute',
			function () {
				$html = slosm_generator_render();

				foreach ( array_keys( Shortcode::raw_defaults() ) as $key ) {
					assert_contains( 'name="' . Shortcode_Generator::field_name( $key ) . '"', $html );
					assert_contains( 'id="' . Shortcode_Generator::field_id( $key ) . '"', $html );
					assert_contains( 'for="' . Shortcode_Generator::field_id( $key ) . '"', $html );
				}

				// And the same thing spelled out, because the loop above builds
				// its expectations with the very methods it is checking: a
				// field_name() that answered the same string for every
				// attribute would satisfy all thirteen of them.
				assert_contains( 'name="slosm_atts[height]"', $html );
				assert_contains( 'name="slosm_atts[cluster]"', $html );
				assert_contains( 'id="slosm-att-near-me"', $html );
				assert_contains( 'id="slosm-att-auto-locate"', $html );
			}
		);

		it(
			'bounds the number fields with the constants that enforce them',
			function () {
				$controls = Shortcode_Generator::controls();

				assert_same( (string) Shortcode::MIN_HEIGHT, $controls['height']['min'] );
				assert_same( (string) Shortcode::MAX_HEIGHT, $controls['height']['max'] );
				assert_same( (string) Shortcode::MIN_ZOOM, $controls['zoom']['min'] );
				assert_same( (string) Shortcode::MAX_ZOOM, $controls['zoom']['max'] );
				assert_same( (string) Rest_Controller::MAX_RADIUS, $controls['radius']['max'] );
				assert_same( (string) Rest_Controller::MAX_LIMIT, $controls['limit']['max'] );

				$html = slosm_generator_render();

				assert_contains( 'min="' . Shortcode::MIN_HEIGHT . '" max="' . Shortcode::MAX_HEIGHT . '"', $html );
				assert_contains( 'min="' . Shortcode::MIN_ZOOM . '" max="' . Shortcode::MAX_ZOOM . '"', $html );
			}
		);

		it(
			'offers the units Geo validates against, and the groupings Settings does',
			function () {
				$controls = Shortcode_Generator::controls();

				assert_same(
					array_merge( array( '' ), Geo::UNITS ),
					array_keys( $controls['units']['choices'] )
				);
				assert_same(
					array_merge( array( '' ), Settings::CLUSTER_CHOICES ),
					array_keys( $controls['cluster']['choices'] )
				);
			}
		);

		it(
			'offers a value that has no label yet, spelled as itself',
			function () {
				// Reached directly, because every value in both closed lists
				// has a label today and this branch is what would carry a
				// third unit added to Geo. Without this case a generator that
				// silently dropped the unlabelled value passed every other one.
				assert_same(
					array(
						''   => 'Inherit',
						'km' => 'Kilometres',
						'mi' => 'Miles',
						'nm' => 'nm',
					),
					Shortcode_Generator::choices(
						array( 'km', 'mi', 'nm' ),
						array(
							'km' => 'Kilometres',
							'mi' => 'Miles',
						),
						'Inherit'
					)
				);
			}
		);

		it(
			'lets every select say nothing at all, which a checkbox could not',
			function () {
				foreach ( Shortcode_Generator::controls() as $key => $control ) {
					if ( 'select' !== ( $control['type'] ?? '' ) ) {
						continue;
					}

					$values = array_keys( $control['choices'] );

					assert_same( '', $values[0], $key . '\'s first option is not the empty one' );
				}

				// The control: there is at least one select, so the loop above
				// is not passing by never running.
				$selects = array_filter(
					Shortcode_Generator::controls(),
					static function ( $control ) {
						return 'select' === ( $control['type'] ?? '' );
					}
				);

				assert_same( 4, count( $selects ) );
			}
		);

		it(
			'shows back what was asked for, so a rejected character is visible',
			function () {
				$html = slosm_generator_render(
					array(
						'height'  => '600',
						'cluster' => 'yes',
					)
				);

				assert_contains( 'name="' . Shortcode_Generator::field_name( 'height' ) . '" value="600"', $html );

				// selected='selected', which is what core's selected() prints
				// (wp-includes/general-template.php, __checked_selected_helper).
				assert_contains( "<option value=\"yes\" selected='selected'>", $html );

				// The control: the option that was not asked for is not marked.
				assert_contains( '<option value="no">', $html );
			}
		);
	}
);

describe(
	'Shortcode_Generator — the shortcode carries only what differs',
	function () {
		it(
			'writes the bare tag when nothing was asked for',
			function () {
				assert_same( '[store_locator]', slosm_generator()->compose( array() ) );
			}
		);

		it(
			'leaves out a value that equals the site setting',
			function () {
				slosm_generator_settings( array( 'map_height' => 600 ) );

				assert_same( '[store_locator]', slosm_generator()->compose( array( 'height' => '600' ) ) );

				// The control: a different value is written, so the omission
				// above is a decision rather than a generator that writes
				// nothing.
				assert_same(
					'[store_locator height="640"]',
					slosm_generator()->compose( array( 'height' => '640' ) )
				);
			}
		);

		it(
			'changes its mind when the setting changes, which a table of defaults could not',
			function () {
				// The same input, twice, with only the site setting moved. This
				// is the case that proves "differs" is measured against the
				// resolver and not against a remembered default.
				slosm_generator_settings( array( 'map_height' => 480 ) );

				assert_same(
					'[store_locator height="600"]',
					slosm_generator()->compose( array( 'height' => '600' ) )
				);

				slosm_generator_settings( array( 'map_height' => 600 ) );

				assert_same( '[store_locator]', slosm_generator()->compose( array( 'height' => '600' ) ) );
			}
		);

		it(
			'leaves out a value that would be clamped back onto the default',
			function () {
				// A tile server that stops at 19 and a default zoom of 19: a
				// zoom of 25 resolves to 19 either way, so it says nothing. A
				// comparison against the default value would have kept it,
				// because 25 is not 19.
				slosm_generator_settings(
					array(
						'tile_max_zoom' => 19,
						'default_zoom'  => 19,
					)
				);

				assert_same( '[store_locator]', slosm_generator()->compose( array( 'zoom' => '25' ) ) );
			}
		);

		it(
			'keeps a clamped value that lands somewhere else, and does not rewrite it',
			function () {
				slosm_generator_settings(
					array(
						'tile_max_zoom' => 19,
						'default_zoom'  => 12,
					)
				);

				// Typed 25, resolves to 19, and is written back as 25: the
				// screen never edits an answer it kept. The preview is where 19
				// is said out loud, and the case for that is below.
				assert_same( '[store_locator zoom="25"]', slosm_generator()->compose( array( 'zoom' => '25' ) ) );
				assert_same( 19, slosm_generator_resolve( '[store_locator zoom="25"]' )['zoom'] );
			}
		);

		it(
			'leaves out a boolean whose default is not a setting at all',
			function () {
				// auto_locate falls back to a hard-coded false rather than to
				// an option, and the omission test does not know the difference.
				assert_same( '[store_locator]', slosm_generator()->compose( array( 'auto_locate' => 'no' ) ) );
				assert_same(
					'[store_locator auto_locate="yes"]',
					slosm_generator()->compose( array( 'auto_locate' => 'yes' ) )
				);
			}
		);

		it(
			'keeps a centre of exactly nought, which is not the same as having none',
			function () {
				// 0,0 is in the Gulf of Guinea, and a site with no default
				// centre resolves lat and lng to null. `null == 0.0` is true in
				// PHP, so a minimiser comparing loosely drops both attributes
				// and the shortcode stops saying where the map opens.
				assert_same(
					'[store_locator lat="0" lng="0"]',
					slosm_generator()->compose(
						array(
							'lat' => '0',
							'lng' => '0',
						)
					)
				);

				// The control: the same two attributes, at the centre the site
				// already has, are dropped.
				slosm_generator_settings(
					array(
						'default_lat' => 0.0,
						'default_lng' => 0.0,
					)
				);

				assert_same(
					'[store_locator]',
					slosm_generator()->compose(
						array(
							'lat' => '0',
							'lng' => '0',
						)
					)
				);
			}
		);

		it(
			'keeps a category that matches nothing, because it changes what the locator says',
			function () {
				// Dropping it would change category_missing, which is what
				// render() prints its "no category matched" comment from. The
				// editor is told the filter is broken rather than having it
				// silently deleted.
				assert_same(
					'[store_locator category="nowhere"]',
					slosm_generator()->compose( array( 'category' => 'nowhere' ) )
				);
			}
		);

		it(
			'keeps a category that matches, and keeps what was typed rather than the term name',
			function () {
				$GLOBALS['slosm_stub']['terms'][ Post_Type::TAXONOMY ] = array(
					array(
						'term_id' => 7,
						'slug'    => 'cafes',
						'name'    => 'Cafés',
					),
				);

				assert_same(
					'[store_locator category="cafes"]',
					slosm_generator()->compose( array( 'category' => 'cafes' ) )
				);
			}
		);

		it(
			'writes attributes in the shortcode\'s order, whatever order they were asked in',
			function () {
				$tag = slosm_generator()->compose(
					array(
						'cluster' => 'yes',
						'label'   => 'Branches',
						'height'  => '600',
					)
				);

				assert_same( '[store_locator height="600" label="Branches" cluster="yes"]', $tag );
			}
		);

		it(
			'ignores anything that is not an attribute of this shortcode',
			function () {
				$tag = slosm_generator()->compose(
					array(
						'height'      => '600',
						'onclick'     => 'alert(1)',
						'tile_url'    => 'https://evil.test/{z}/{x}/{y}.png',
						'category_missing' => 'x',
					)
				);

				assert_same( '[store_locator height="600"]', $tag );
			}
		);

		it(
			'ignores a value that is not a scalar',
			function () {
				assert_same(
					'[store_locator]',
					slosm_generator()->compose( array( 'label' => array( 'a', 'b' ) ) )
				);
			}
		);

		it(
			'reads the request into the same shape, and drops the empty fields a form always sends',
			function () {
				$_GET[ Shortcode_Generator::FIELD ] = array(
					'height'  => '600',
					'zoom'    => '',
					'label'   => '   ',
					'cluster' => 'yes',
					'nonsense' => 'x',
				);

				$chosen = slosm_generator()->chosen();

				unset( $_GET[ Shortcode_Generator::FIELD ] );

				assert_same(
					array(
						'height'  => '600',
						'cluster' => 'yes',
					),
					$chosen
				);
			}
		);

		it(
			'reads nothing at all from a request argument that is not an array',
			function () {
				$_GET[ Shortcode_Generator::FIELD ] = 'height=600';

				$chosen = slosm_generator()->chosen();

				// The control: the same read, of the same argument, when it is
				// the array a form really sends.
				$_GET[ Shortcode_Generator::FIELD ] = array( 'height' => '600' );

				$control = slosm_generator()->chosen();

				unset( $_GET[ Shortcode_Generator::FIELD ] );

				assert_same( array(), $chosen );
				assert_same( array( 'height' => '600' ), $control );
			}
		);
	}
);

describe(
	'Shortcode_Generator — a value a shortcode parser can carry',
	function () {
		it(
			'removes the four characters a shortcode attribute cannot hold',
			function () {
				assert_same( 'ab', Shortcode_Generator::value( 'a"b' ) );
				assert_same( 'ab', Shortcode_Generator::value( 'a[b' ) );
				assert_same( 'ab', Shortcode_Generator::value( 'a]b' ) );
				assert_same( 'ab', Shortcode_Generator::value( 'a\\b' ) );

				assert_same( 4, count( Shortcode_Generator::FORBIDDEN ) );

				// The control: an ordinary value, apostrophes and accents
				// included, goes through untouched. A sanitiser that emptied
				// everything would pass all four assertions above.
				assert_same( 'Bob’s Café & Co', Shortcode_Generator::value( 'Bob’s Café & Co' ) );
			}
		);

		it(
			'hands back a value sanitize_text_field can no longer change',
			function () {
				// The property the order of value() exists for: what the
				// generator writes into the tag is what Shortcode::text() will
				// read back out of it, character for character. A version that
				// sanitised first fails this on the second input.
				foreach (
					array(
						'a ] b',
						'Shops "A" [x]',
						"a\n\tb",
						' padded ',
						'Bob’s Café & Co',
						'a<b',
						'x" cluster="yes',
					) as $raw
				) {
					$value = Shortcode_Generator::value( $raw );

					assert_same( $value, sanitize_text_field( $value ), 'not a fixed point: ' . $raw );
				}

				// And the input that separates the two orders, spelled out:
				// sanitising first leaves the space the bracket was sitting in.
				assert_same( 'a b', Shortcode_Generator::value( 'a ] b' ) );
				assert_same( 'abc', Shortcode_Generator::value( '] abc' ) );
			}
		);

		it(
			'survives the round trip character for character, paste from Word included',
			function () {
				// The fixed point above is only half of it. Core folds two code
				// points to a space *before* the attribute regex runs — line
				// 616 of wp-includes/shortcodes.php — and until value() folded
				// them too, a label pasted out of a word processor went in with
				// a non-breaking space and came back with an ordinary one. That
				// is the ordinary way this field gets filled in.
				$nbsp = "a\u{00a0}b";
				$zwsp = "a\u{200b}b";

				// A space, not nothing, for both: the fold here is core's fold,
				// and core replaces a run of either with one space rather than
				// deleting it. Folding a zero-width space away instead would
				// make the tag and the page disagree again, the other way
				// round.
				assert_same( 'a b', Shortcode_Generator::value( $nbsp ) );
				assert_same( 'a b', Shortcode_Generator::value( $zwsp ) );

				foreach (
					array(
						$nbsp,
						$zwsp,
						"Rue de la Paix\u{00a0}12",
						'Shops "A" [x]',
						'a ] b',
						'a<b',
						'< >',
						'Bob’s Café & Co',
					) as $typed
				) {
					$tag    = slosm_generator()->compose( array( 'label' => $typed ) );
					$parsed = Shortcode_Generator::parse( $tag );

					assert_same(
						Shortcode_Generator::value( $typed ),
						$parsed['label'] ?? '',
						'the round trip changed ' . var_export( $typed, true )
					);
				}

				// The control: the fold is real, and core would have done it
				// anyway. An unfolded value does NOT survive, which is the bug
				// this case exists for.
				assert_same(
					array( 'label' => 'a b' ),
					shortcode_parse_atts( " label=\"a\u{00a0}b\"" )
				);
			}
		);

		it(
			'folds the two code points before it measures anything, so no attribute is kept twice over',
			function () {
				// The knock-on, and the reason the fold belongs in value()
				// rather than in a caveat: minimise() compares the values it is
				// about to write, so two spellings core cannot tell apart have
				// to be two spellings this screen cannot tell apart either.
				$plain = slosm_generator()->compose( array( 'label' => 'Warszawa Centrum' ) );

				assert_same( '[store_locator label="Warszawa Centrum"]', $plain );

				foreach ( array( "Warszawa\u{00a0}Centrum", "Warszawa \u{200b}Centrum", "Warszawa\u{00a0}\u{200b}Centrum" ) as $typed ) {
					assert_same(
						$plain,
						slosm_generator()->compose( array( 'label' => $typed ) ),
						'a spelling core folds away composed a different tag: ' . bin2hex( $typed )
					);
				}

				// The control: a label that really is different still composes
				// a different tag.
				assert_same(
					'[store_locator label="Warszawa Centrum Dwa"]',
					slosm_generator()->compose( array( 'label' => 'Warszawa Centrum Dwa' ) )
				);
			}
		);

		it(
			'lets a raw angle bracket through when core does, and it still parses',
			function () {
				// `<` is off the forbidden list, and the reason is NOT that
				// nothing raw survives — that was this file's stated reason and
				// it is false. wp_pre_kses_less_than() escapes a run only when
				// the run contains no `>`, so `a<b` is escaped and `< >` is
				// not.
				assert_same( 'a&lt;b', Shortcode_Generator::value( 'a<b' ) );
				assert_same( '< >', Shortcode_Generator::value( '< >' ) );
				assert_same( 'a < > b', Shortcode_Generator::value( 'a < > b' ) );
				assert_same( 'a>b', Shortcode_Generator::value( 'a>b' ) );

				// What makes that safe is the pairing rather than the removal:
				// a run kept raw is `<`, no `<`, then its `>`, which is exactly
				// what core's unclosed-element check accepts. A value it did
				// not accept would come back as an empty string.
				foreach ( array( 'a<b', '< >', 'a < > b', '< 1 > 2', '< ><b>x', '< >>', 'a<>>b' ) as $typed ) {
					$value = Shortcode_Generator::value( $typed );
					$tag   = slosm_generator()->compose( array( 'label' => $typed ) );

					assert_same(
						array( 'label' => $value ),
						Shortcode_Generator::parse( $tag ),
						'core did not read back what was written for ' . var_export( $typed, true )
					);
				}

				// The control for that loop: core really does blank a value
				// whose `<` has no `>` after it, so the round trips above are
				// not a parser that accepts everything.
				assert_same(
					array( 'label' => '' ),
					shortcode_parse_atts( ' label="a<b"' )
				);
			}
		);

		it(
			'still strips a tag, and still folds the whitespace a form can send',
			function () {
				assert_same( 'Shops', Shortcode_Generator::value( 'Shops<script>alert(1)</script>' ) );
				assert_same( 'a b', Shortcode_Generator::value( "a\n\tb" ) );
			}
		);

		it(
			'cannot be talked into writing a second attribute',
			function () {
				$tag = slosm_generator()->compose( array( 'label' => 'x" cluster="yes' ) );

				assert_same( '[store_locator label="x cluster=yes"]', $tag );

				// And read back the way a site will read it: one attribute.
				assert_same(
					array( 'label' => 'x cluster=yes' ),
					Shortcode_Generator::parse( $tag )
				);
				assert_same( 'auto', slosm_generator_resolve( $tag )['cluster'] );
			}
		);

		it(
			'cannot be talked into closing the tag and opening another shortcode',
			function () {
				$tag = slosm_generator()->compose( array( 'label' => 'x][store_locator cluster="yes' ) );

				// One tag, and the whole of what was typed is inside the label.
				assert_same( 1, substr_count( $tag, '[' ) );
				assert_same( 1, substr_count( $tag, ']' ) );
				assert_same( array( 'label' => 'xstore_locator cluster=yes' ), Shortcode_Generator::parse( $tag ) );
			}
		);

		it(
			'composes the same tag whether the value was cleaned on the way in or not',
			function () {
				$dirty = array( 'label' => 'Shops "A" [x]' );
				$tag   = slosm_generator()->compose( $dirty );

				assert_same(
					slosm_generator()->compose( array( 'label' => Shortcode_Generator::value( 'Shops "A" [x]' ) ) ),
					$tag
				);

				// The control: two empty strings are also equal to each other,
				// so the tag has to be the one that was meant.
				assert_same( '[store_locator label="Shops A x"]', $tag );
			}
		);

		it(
			'everything it composes matches core\'s shortcode regex exactly once',
			function () {
				$pattern = get_shortcode_regex( array( Shortcode::TAG ) );

				$tags = array(
					slosm_generator()->compose( array() ),
					slosm_generator()->compose( array( 'label' => 'a/b' ) ),
					slosm_generator()->compose( array( 'label' => 'Bob’s Café & Co' ) ),
					slosm_generator()->compose( array( 'search' => 'ul. Piękna 1/3' ) ),
					slosm_generator()->compose( array( 'label' => 'x" cluster="yes' ) ),
					slosm_generator()->compose( array( 'label' => 'x][store_locator' ) ),
				);

				foreach ( $tags as $tag ) {
					assert_same( 1, preg_match_all( "/$pattern/", $tag ), 'did not match once: ' . $tag );
				}
			}
		);
	}
);

describe(
	'Shortcode_Generator — parsing a tag the way core does',
	function () {
		it(
			'reads the attributes core\'s own parser reads',
			function () {
				assert_same(
					array(
						'height' => '600',
						'label'  => 'Branches',
					),
					Shortcode_Generator::parse( '[store_locator height="600" label="Branches"]' )
				);
			}
		);

		it(
			'is not fooled by a tag that only looks like one',
			function () {
				assert_same( null, Shortcode_Generator::parse( 'no shortcode here' ) );
				assert_same( null, Shortcode_Generator::parse( '[store_locators height="600"]' ) );
				assert_same( null, Shortcode_Generator::parse( '[other height="600"]' ) );

				// The control: a parser that answered null to everything would
				// pass all three.
				assert_same(
					array( 'height' => '600' ),
					Shortcode_Generator::parse( '[store_locator height="600"]' )
				);
			}
		);

		it(
			'refuses core\'s [[escaped]] form, which renders as text rather than a locator',
			function () {
				assert_same( null, Shortcode_Generator::parse( '[[store_locator height="600"]]' ) );

				// The control: the same tag without the doubled brackets parses.
				assert_same(
					array( 'height' => '600' ),
					Shortcode_Generator::parse( '[store_locator height="600"]' )
				);
			}
		);

		it(
			'stops where core stops, at the first closing bracket',
			function () {
				// This is what a `]` inside a value would do, and it is why one
				// is removed. The parser is the real one, so the damage is
				// core's rather than this file's opinion of core's — and it is
				// worse than a truncated value: the tag ends at the `]`, the
				// attribute text is ` label="x` with no closing quote, so the
				// name="value" branch of the attribute regex never matches and
				// the whole thing lands in the *positional* attributes as one
				// bare word. There is no `label` at all.
				$parsed = Shortcode_Generator::parse( '[store_locator label="x] cluster="yes"]' );

				assert_same( array( 0 => 'label="x' ), $parsed );
				assert_false( array_key_exists( 'label', $parsed ) );

				// The control: the same tag with the bracket taken out parses
				// into the two attributes it was meant to carry.
				assert_same(
					array(
						'label'   => 'x',
						'cluster' => 'yes',
					),
					Shortcode_Generator::parse( '[store_locator label="x" cluster="yes"]' )
				);
			}
		);
	}
);

describe(
	'Shortcode_Generator — the preview is the shortcode read back',
	function () {
		it(
			'is what attributes() makes of the composed string, worked out again here',
			function () {
				slosm_generator_settings(
					array(
						'map_height'     => 520,
						'units'          => 'mi',
						'default_radius' => 25.0,
					)
				);

				$GLOBALS['slosm_stub']['post_counts'][ Post_Type::POST_TYPE ] = (object) array( 'publish' => 4 );

				$chosen = array(
					'height' => '640',
					'label'  => 'Shops "A" [x]',
					'zoom'   => '15',
				);

				$tag      = slosm_generator()->compose( $chosen );
				$preview  = slosm_generator()->preview( $tag );
				$expected = slosm_generator_resolve( $tag );

				foreach ( $preview['rows'] as $row ) {
					assert_same(
						$expected[ $row['key'] ],
						$row['value'],
						$row['key'] . ' in the preview is not what the shortcode resolves to'
					);
				}

				// Every attribute, and not a subset of them.
				assert_same( count( Shortcode::raw_defaults() ), count( $preview['rows'] ) );
			}
		);

		it(
			'previews the string and not the form, so a rejected character is gone from both',
			function () {
				$tag     = slosm_generator()->compose( array( 'label' => 'Shops "A" [x]' ) );
				$preview = slosm_generator()->preview( $tag );
				$label   = '';

				foreach ( $preview['rows'] as $row ) {
					if ( 'label' === $row['key'] ) {
						$label = $row['value'];
					}
				}

				assert_same( 'Shops A x', $label );
				assert_contains( 'label="Shops A x"', $tag );
			}
		);

		it(
			'says which values came from the shortcode and which from the site',
			function () {
				$preview = slosm_generator()->preview( slosm_generator()->compose( array( 'height' => '640' ) ) );
				$sources = array();

				foreach ( $preview['rows'] as $row ) {
					$sources[ $row['key'] ] = $row['shortcode'];
				}

				assert_true( $sources['height'] );
				assert_false( $sources['zoom'] );
				assert_false( $sources['cluster'] );
			}
		);

		it(
			'names the category that matched nothing',
			function () {
				$preview = slosm_generator()->preview( '[store_locator category="nowhere"]' );

				assert_same( 'nowhere', $preview['missing'] );

				// The control: a category that matches leaves it empty.
				$GLOBALS['slosm_stub']['terms'][ Post_Type::TAXONOMY ] = array(
					array(
						'term_id' => 7,
						'slug'    => 'cafes',
						'name'    => 'Cafés',
					),
				);

				assert_same( '', slosm_generator()->preview( '[store_locator category="cafes"]' )['missing'] );
			}
		);

		it(
			'carries the config the front-end script would be handed',
			function () {
				$GLOBALS['slosm_stub']['post_counts'][ Post_Type::POST_TYPE ] = (object) array( 'publish' => 4 );

				$shortcode = new Shortcode();
				$tag       = '[store_locator cluster="yes"]';
				$preview   = slosm_generator()->preview( $tag );

				assert_same(
					$shortcode->config( slosm_generator_resolve( $tag ) ),
					$preview['config']
				);
				assert_true( $preview['config']['cluster'] );
			}
		);

		it(
			'has nothing to show for a tag that is not a locator',
			function () {
				assert_same( null, slosm_generator()->preview( 'not a shortcode' ) );

				// The control: a preview that answered null to everything would
				// pass that, and the screen would print its "could not be read
				// back" notice on every load.
				$preview = slosm_generator()->preview( '[store_locator]' );

				assert_true( is_array( $preview ) );
				assert_same( count( Shortcode::raw_defaults() ), count( $preview['rows'] ) );
			}
		);

		it(
			'reads a boolean and an empty value as words rather than as debris',
			function () {
				assert_same( 'Yes', Shortcode_Generator::readable( true ) );
				assert_same( 'No', Shortcode_Generator::readable( false ) );
				assert_same( '—', Shortcode_Generator::readable( '' ) );
				assert_same( '—', Shortcode_Generator::readable( null ) );
				assert_same( '25', Shortcode_Generator::readable( 25.0 ) );
				assert_same( '12.5', Shortcode_Generator::readable( 12.5 ) );
				assert_same( '600', Shortcode_Generator::readable( 600 ) );

				// A coordinate, which is where a float in this table really
				// comes from, and where casting it instead would print
				// whatever php.ini's `precision` happens to be: 52.22970001
				// rather than the seven decimals the picker writes.
				assert_same( '52.2297', Shortcode_Generator::readable( 52.22970001 ) );
			}
		);
	}
);

describe(
	'Shortcode_Generator — what the screen prints',
	function () {
		it(
			'prints the composed tag into a readonly textarea with no name of its own',
			function () {
				$html = slosm_generator_render( array( 'height' => '640' ) );

				assert_contains( '<textarea class="slosm-shortcode__text large-text code" rows="2" readonly', $html );
				assert_contains( esc_textarea( '[store_locator height="640"]' ), $html );

				// No name: the tag must not come back as a request argument
				// when the form is submitted again.
				assert_false(
					1 === preg_match( '/<textarea[^>]*\bname=/', $html ),
					'the textarea would be submitted with the form'
				);
			}
		);

		it(
			'escapes the tag rather than printing quotes into the markup',
			function () {
				$html = slosm_generator_render( array( 'label' => 'Branches' ) );

				assert_contains( '[store_locator label=&quot;Branches&quot;]', $html );
				assert_false( str_contains( $html, '[store_locator label="Branches"]' ) );
			}
		);

		it(
			'escapes a category it repeats back in the warning',
			function () {
				$html = slosm_generator_render( array( 'category' => 'a&b' ) );

				assert_contains( 'a&amp;b', $html );
				assert_false( 1 === preg_match( '/matches [^<]*“a&b”/', $html ) );
			}
		);

		it(
			'escapes a resolved value, which is somebody else\'s text',
			function () {
				// A term name is editorial content and nothing sanitises it on
				// the way out of get_term_by(): tests/test-shortcode.php has
				// the front end carrying one full of markup through intact. The
				// preview prints that name in a table cell.
				$GLOBALS['slosm_stub']['terms'][ Post_Type::TAXONOMY ] = array(
					array(
						'term_id' => 7,
						'slug'    => 'cafes',
						'name'    => '<b>Caf&és</b>',
					),
				);

				$html = slosm_generator_render( array( 'category' => 'cafes' ) );

				assert_contains( '&lt;b&gt;Caf&amp;és&lt;/b&gt;', $html );
				assert_false(
					str_contains( $html, '<code><b>' ),
					'a term name reached the page as markup'
				);
			}
		);

		it(
			'prints the live region empty, and prints no button of its own',
			function () {
				$html = slosm_generator_render();

				assert_contains(
					'<p class="slosm-shortcode__status" role="status" aria-live="polite"></p>',
					$html
				);
				assert_false(
					str_contains( $html, 'slosm-shortcode__copy' ),
					'a copy button in the markup is a promise the page cannot keep without JavaScript'
				);
			}
		);

		it(
			'carries the two arguments a GET form would otherwise lose',
			function () {
				$html = slosm_generator_render();

				assert_contains( 'method="get"', $html );
				assert_contains( '<input type="hidden" name="post_type" value="' . Post_Type::POST_TYPE . '" />', $html );
				assert_contains( '<input type="hidden" name="page" value="' . Shortcode_Generator::PAGE . '" />', $html );
			}
		);

		it(
			'prints one row of preview per attribute, and says where each came from',
			function () {
				$html = slosm_generator_render( array( 'height' => '640' ) );

				assert_contains( 'This shortcode', $html );
				assert_contains( 'The site settings', $html );

				// The preview table only: the form above it is a form-table
				// with a row per control, so counting rows over the whole page
				// would count both and pass for a preview with nothing in it.
				$at    = strpos( $html, 'slosm-shortcode__preview' );
				$table = false === $at ? '' : substr( $html, $at );

				assert_same( count( Shortcode::raw_defaults() ), substr_count( $table, '<tr><th scope="row">' ) );
			}
		);

		it(
			'says how many locations there are and how they will be loaded',
			function () {
				$GLOBALS['slosm_stub']['capabilities'][ Shortcode_Generator::CAPABILITY ] = true;
				$GLOBALS['slosm_stub']['post_counts'][ Post_Type::POST_TYPE ]             = (object) array( 'publish' => 4 );

				ob_start();
				slosm_generator()->render();
				$html = (string) ob_get_clean();

				assert_contains( 'This site has 4 published locations', $html );
				assert_contains( 'Every pin is drawn on its own.', $html );

				// The control: a site over the preload threshold is told the
				// other thing, and the number moves with it.
				$GLOBALS['slosm_stub']['post_counts'][ Post_Type::POST_TYPE ] = (object) array(
					'publish' => Shortcode::PRELOAD_THRESHOLD + 1,
				);

				ob_start();
				slosm_generator()->render();
				$html = (string) ob_get_clean();

				assert_contains( 'asks the server as the visitor searches', $html );
			}
		);

		it(
			'says in as many words that it is not a picture of the map',
			function () {
				$html = slosm_generator_render();

				assert_contains( 'This is not a picture of the map', $html );
			}
		);
	}
);

