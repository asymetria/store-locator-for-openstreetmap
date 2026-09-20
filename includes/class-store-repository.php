<?php
/**
 * The one place this plugin knows where a location is kept.
 *
 * @package Store_Locator_For_OpenStreetMap
 */

namespace Asymetria\StoreLocator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( __NAMESPACE__ . '\\Store_Repository' ) ) {

	/**
	 * Loads locations, and is the only class allowed to know how they are stored.
	 *
	 * Nothing else in the plugin calls $wpdb, get_posts(), get_post(),
	 * get_post_meta() or get_the_terms(). That is the whole design: everywhere
	 * else a location is a Store, and the day locations move into a custom table
	 * this class's body plus a migration is the change. A single get_post_meta()
	 * in a template or a REST controller is what turns that one-file change into
	 * an audit of the whole plugin, which is why the rule is worth stating as a
	 * rule.
	 *
	 * The loader is a constructor argument
	 * ------------------------------------
	 * find_all() does not call get_posts(). It calls whatever callable it was
	 * built with, which defaults to a closure that calls get_posts(). That seam
	 * is the reason the cache, the mapping and the coordinate filtering can be
	 * tested at all: the suite runs with no database, no WordPress and no
	 * fixtures beyond an array of rows, so a cache case can count how many times
	 * the loader ran, which is the only thing that actually proves a cache.
	 *
	 * The seam is also where the custom table would arrive. A repository built
	 * with a table-reading loader needs no other change, because everything below
	 * to_store() is already expressed as "rows in, Stores out".
	 *
	 * And the bounded query is a second one
	 * -------------------------------------
	 * find_near() does not narrow that loader, it has its own. The first loader
	 * feeds the shared cache: there is one key per language and one
	 * flush_cache(), so a bounding box forwarded through it would write one
	 * visitor's few nearby locations under the key every other visitor's map
	 * reads, and the map would then be missing locations for everybody until the
	 * transient expired. Nothing about that failure is visible from a result —
	 * every caller gets locations, just not all of them — so the two seams are
	 * kept apart structurally rather than by remembering the rule, and a test
	 * counts both.
	 *
	 * What is cached, and why it is the lean shape
	 * --------------------------------------------
	 * The transient holds Store::to_lean_array() rows, not to_full_array() ones.
	 * The payload is built for every visitor and, up to the preload threshold,
	 * shipped whole: seven keys per location rather than seventeen measures about
	 * 250 bytes a row against about 660 for the full record — 2.7 times, on a
	 * location with a two-line description — on up to 500 locations, on every page
	 * the shortcode appears on. The ten fields left out are popup content:
	 * description, hours, phone, email, wanted one location at a time, which is
	 * what find_by_id() is for.
	 *
	 * That decision has one consequence worth naming, because it is a trap for
	 * Task 12's bulk geocoder: lat_locked is not in the cache. A geocode run must
	 * read it per location from storage, never from this payload, or it will see
	 * every location as unlocked and overwrite every pin an editor placed by hand.
	 *
	 * A location with no usable coordinates is not in the payload either. It
	 * cannot be drawn, and shipping it with lat => null only moves the filtering
	 * into the browser, on every render, for a row that can never become a
	 * marker. The admin warning that tells an editor a location is unplaced reads
	 * storage, not this.
	 *
	 * The payload is request-scoped, so the key has to be too
	 * -------------------------------------------------------
	 * The default loader passes suppress_filters => false, which is what lets WPML
	 * and Polylang narrow the query to the current language. That makes the
	 * payload a view of one request rather than of the site, and a payload like
	 * that cannot live under one global key: whichever language rebuilt first
	 * would be the language every other visitor's map showed until the transient
	 * expired. So cache_key() carries the language, and two languages hold two
	 * payloads.
	 *
	 * It does not generalise as far as it should, and that limit is worth stating
	 * rather than discovering. Any filter that narrows the query per *user* or per
	 * context — a membership plugin hiding branches from logged-out visitors, a
	 * visibility plugin keyed on a role — narrows the shared payload for everyone,
	 * because nothing about the user is in the key. Putting a role or a user id in
	 * the key would multiply the payloads and still not be a general answer. A
	 * site doing that needs its own cache key, which is what the
	 * slosm_cache_key filter in Task 20 is for.
	 *
	 * How one flush reaches every language
	 * ------------------------------------
	 * Per-language keys mean one delete_transient() can never be enough: it names
	 * the language of the request it runs in, so a save in Polish would leave the
	 * English payload stale for a day. So the key carries a generation number as
	 * well, held in GENERATION_OPTION, and flush_cache() increments it. Every
	 * language's key changes at once, atomically, with no enumeration; the
	 * payloads written under the old generation become unreachable and expire on
	 * their own ttl.
	 *
	 * Two alternatives were considered and are worse, which is worth recording
	 * because both look simpler:
	 *
	 * - Sweeping wp_options with one DELETE against
	 *   option_name LIKE '_transient_slosm_stores_lean_%'. That finds nothing at
	 *   all on a site with a persistent object cache, because transients never
	 *   reach wp_options there — they live in Redis or Memcached under keys no
	 *   SQL can see. The sweep matches nothing, every language stays stale, and
	 *   the failure appears only on the better-hosted sites and never in testing.
	 * - An index option listing the keys that have a payload. It is a
	 *   read-modify-write on every rebuild, so two languages rebuilding at once
	 *   can drop an entry, and a dropped entry is a language that never gets
	 *   flushed again — the bug being fixed, arrived at from the other side.
	 *
	 * The generation costs one option read per request, which is why it is
	 * written with autoload on: it is then part of the alloptions the site loads
	 * anyway rather than a query of its own on every front-end page.
	 *
	 * What it does not buy is a shorter reach than a delete. Every payload
	 * written under an old generation stays wherever transients live until its
	 * ttl runs out, occupying space nothing will ever read again. That is the
	 * trade: memory a cache is already built to reclaim, in exchange for an
	 * invalidation that does not depend on being able to enumerate what it is
	 * invalidating.
	 */
	final class Store_Repository {

		/**
		 * The prefix every payload key starts with.
		 *
		 * Everything that has to recognise these transients recognises them by
		 * this: the uninstaller, and the "clear cache" button. A key without the
		 * plugin's own prefix is a key both of those would leave behind on every
		 * site.
		 *
		 * Invalidation does not use it. The generation in the key is what makes
		 * a flush reach every language, and it needs no prefix match and no
		 * enumeration; see the class docblock. The prefix still matters for the
		 * two callers above, which run once and can afford to sweep — and which
		 * have to delete GENERATION_OPTION as well, or an uninstall followed by
		 * a reinstall starts life pointing at a generation whose payloads are
		 * still in the object cache.
		 *
		 * @var string
		 */
		public const CACHE_PREFIX = 'slosm_stores_lean';

		/**
		 * The option holding the generation every cache key carries.
		 *
		 * Incremented by flush_cache(), read by cache_key(), and that is the
		 * whole mechanism: one write moves every language's key at once.
		 *
		 * Public because the uninstaller has to delete it by name, and because a
		 * test that computed the name itself would agree with any rename.
		 *
		 * @var string
		 */
		public const GENERATION_OPTION = 'slosm_cache_generation';

		/**
		 * The payload shape's version, carried in the key.
		 *
		 * Bump it whenever the lean row changes shape. Without it, a plugin
		 * update that adds a key to to_lean_array() spends up to a day serving
		 * payloads written by the old version, and Store::from_array() — which
		 * ignores what it does not recognise and defaults what is missing —
		 * rebuilds them with the new field silently blank. No fatal, no notice,
		 * just a field that is empty everywhere until the transient expires.
		 * Changing the key ends that window at the moment of the upgrade.
		 *
		 * No test can demonstrate what this buys, and the suite does not pretend
		 * to: proving it would mean running two versions of this class in one
		 * process. What the tests pin is that the version is in the key.
		 *
		 * @var int
		 */
		public const CACHE_VERSION = 1;

		/**
		 * How long a payload with locations in it lives.
		 *
		 * Plugin::boot() hooks the saves, the deletes and the term changes, and
		 * those are the real invalidation — a day-old map on a site that just
		 * edited a branch would be unacceptable and this lifetime would not save
		 * it. What the ttl buys is the case the hooks cannot see: an importer
		 * writing meta with $wpdb, a WP-CLI run, a database restored underneath
		 * the site. Those sites heal within a day instead of staying wrong until
		 * somebody edits a location.
		 *
		 * The cost of being wrong in the other direction is one rebuild per day
		 * per language: one query for the whole list, then thirteen meta reads
		 * per location and no query at all for the categories, both served from
		 * the caches that query primed. See to_store() for why that is a claim
		 * about WordPress rather than about this class.
		 *
		 * @var int
		 */
		public const CACHE_TTL = DAY_IN_SECONDS;

		/**
		 * How long an empty payload lives.
		 *
		 * Empty is cached, because the site with nothing to show is otherwise the
		 * one that rebuilds on every page load. But empty is also what every
		 * quiet failure looks like — a misfiring pre_get_posts, a query that ran
		 * before the post type was registered, a database hiccup — and the
		 * invalidation hooks cannot rescue any of them, because nothing is being
		 * saved. A full
		 * day of a blank map sitewide is too much to risk on that; five minutes
		 * of rebuilding is not.
		 *
		 * @var int
		 */
		public const EMPTY_CACHE_TTL = 5 * MINUTE_IN_SECONDS;

		/**
		 * The fields that are not post meta.
		 *
		 * Four of Store::FIELDS: id is the post id, name the title, description
		 * the content, and categories the taxonomy.
		 *
		 * No production code reads this constant, and that is worth admitting
		 * rather than dressing up: to_store() writes those four keys out
		 * literally, because each comes from a different place and a loop over
		 * this list would need a branch per field to say where. What the constant
		 * is for is the test that checks this list plus META_KEYS against
		 * Store::FIELDS — a field in neither is a field nothing reads, which is
		 * the failure this class is shaped to make loud, and stating the split
		 * here is what makes it checkable at all.
		 *
		 * @var string[]
		 */
		public const POST_FIELDS = array( 'id', 'name', 'description', 'categories' );

		/**
		 * Field name to meta key, for the thirteen fields that are post meta.
		 *
		 * This table is the mapping, and it lives here because storage is this
		 * class's knowledge. Store::FIELDS deliberately carries no prefix and no
		 * hint of where a field lives.
		 *
		 * Written out rather than derived from the field name with a prefix, for
		 * a reason that is not style. A derived key welds the storage layout to
		 * the field names forever: renaming a field would then silently rename a
		 * meta key, and every row already in wp_postmeta on every site would stop
		 * being found — no error, just a blank field everywhere. With the table
		 * written out, a rename is one line here plus a deliberate migration, or
		 * no migration at all.
		 *
		 * The keys are checked against Store::FIELDS by a test, because
		 * Store::from_array() ignores a key it does not know: 'adress' here is
		 * not an error anywhere, it is one field that is empty on every location
		 * on every site forever.
		 *
		 * @var array<string, string>
		 */
		public const META_KEYS = array(
			'address'    => '_slosm_address',
			'address2'   => '_slosm_address2',
			'city'       => '_slosm_city',
			'state'      => '_slosm_state',
			'zip'        => '_slosm_zip',
			'country'    => '_slosm_country',
			'lat'        => '_slosm_lat',
			'lng'        => '_slosm_lng',
			'lat_locked' => '_slosm_lat_locked',
			'phone'      => '_slosm_phone',
			'email'      => '_slosm_email',
			'url'        => '_slosm_url',
			'hours'      => '_slosm_hours',
		);

		/**
		 * Returns the rows to build locations from.
		 *
		 * Untyped, because callable is not a legal property type in PHP: a
		 * `private callable $loader` is a fatal at compile time, not a subtlety.
		 *
		 * @var callable
		 */
		private $loader;

		/**
		 * Returns the rows inside a bounding box, for a proximity search.
		 *
		 * Untyped for the same reason as $loader: callable is not a legal
		 * property type.
		 *
		 * @var callable
		 */
		private $bounded_loader;

		/**
		 * The payload this request has already loaded, if it has.
		 *
		 * Two shortcodes on a page, or a shortcode and a REST call in the same
		 * request, would otherwise each read the transient and unserialize it —
		 * 120 KB at 500 locations, for an answer that cannot have changed. It is
		 * also what makes Task 7's plan sentence true: find_near() is supposed to
		 * filter and sort "the cached lean list when it is already loaded", and
		 * until this existed no such list was ever loaded.
		 *
		 * @var array[]|null
		 */
		private ?array $memo = null;

		/**
		 * The key $memo was loaded under.
		 *
		 * Kept because the key can change inside one request: WPML switches
		 * language on demand, and a memo answering for the wrong language is the
		 * bug the per-language key exists to prevent, moved into PHP.
		 *
		 * @var string
		 */
		private string $memo_key = '';

		/**
		 * Whether the taxonomy refused to answer during the current build.
		 *
		 * Reset at the top of build_payload(), set by categories_for(). See
		 * find_all() for what it prevents.
		 *
		 * @var bool
		 */
		private bool $taxonomy_failed = false;

		/**
		 * Whether this request has already spent a generation.
		 *
		 * One save fires several of the hooks Plugin::boot() registers — the
		 * terms are set, the post is saved, a term the save touched is edited —
		 * and each one calls flush_cache(). Three increments would be harmless,
		 * since the first already made every payload unreachable, but they would
		 * rewrite an autoloaded option three times for every save on the site and
		 * make the generation impossible to reason about in a test.
		 *
		 * Cleared again the moment anything is known to be cached under the
		 * current generation, and that is not tidiness. A request that saves,
		 * renders the map and then saves again has written a payload under the
		 * new generation in between; a guard that refused to move past it would
		 * leave that payload — built before the second save — served to every
		 * visitor for a full day. The flag says "nothing is known to be cached
		 * since the last increment", not "this request has flushed once".
		 *
		 * Two places clear it, and the second matters for the same reason as the
		 * first: find_all() when it writes a payload, and loaded_rows() when it
		 * reads one. A read means a *concurrent* request built a payload under
		 * this generation, which is exactly as stale after a second save as one
		 * this request built itself.
		 *
		 * It never blocks the flush that closes the meta-write window, because
		 * that one is forced; see flush_cache().
		 *
		 * @var bool
		 */
		private bool $generation_spent = false;

		/**
		 * Builds a repository over a source of rows.
		 *
		 * The default loader is the production one and is the only get_posts()
		 * call in the plugin. Four of its arguments are there because the
		 * defaults would be wrong in ways nothing would report:
		 *
		 * - numberposts defaults to 5. Left alone, a site with six branches shows
		 *   five of them and says nothing at all about the sixth.
		 * - post_status defaults to 'publish' for get_posts(), which is what we
		 *   want, and it is written out because "drafts are not on the map" is a
		 *   decision rather than an accident of the default.
		 * - suppress_filters defaults to true, which is get_posts()'s own
		 *   peculiarity and means pre_get_posts and the query filters do not run.
		 *   WPML and Polylang filter the query, so leaving it true would put every
		 *   language's locations on every language's map. Turning it on is half a
		 *   fix on its own; the other half is in cache_key(), and the class
		 *   docblock has the reasoning.
		 * - orderby defaults to date, newest first, which is an order nobody
		 *   reading a list of branches has any use for. Alphabetical is the only
		 *   order that means something before a visitor gives a position; after
		 *   that the browser sorts by distance.
		 *
		 * The second loader is the bounded one, and it is separate on purpose;
		 * the class docblock has the reasoning. It is handed the box from
		 * Geo::bounding_box() and returns the same kind of rows, narrowed to it.
		 * Three things about its default query are decisions rather than
		 * defaults:
		 *
		 * - The meta_query type is DECIMAL(10,6). 'NUMERIC' is what
		 *   WP_Meta_Query turns into a CAST to SIGNED, which truncates 52.2297
		 *   to 52 — twenty-five kilometres of latitude — and a bare 'DECIMAL' is
		 *   DECIMAL(10,2), about 1.1 km. Either one compares the wrong numbers
		 *   and returns a plausible list of the wrong locations.
		 * - numberposts stays -1. The results are ordered by distance, and MySQL
		 *   does not know the distances: a LIMIT here would cut the rows in
		 *   title order before anything measured them, so a search would drop
		 *   nearer locations and keep farther ones.
		 * - suppress_filters stays false, so WPML and Polylang narrow this query
		 *   the same way they narrow the other one. Nothing from this query is
		 *   cached, so this half needs no key.
		 *
		 * @param callable|null $loader         Returns rows; defaults to the published locations.
		 * @param callable|null $bounded_loader Takes a bounding box, returns the rows inside it; defaults to a meta_query.
		 */
		public function __construct( ?callable $loader = null, ?callable $bounded_loader = null ) {
			$this->loader = null !== $loader ? $loader : static function (): array {
				return (array) get_posts(
					array(
						'post_type'        => Post_Type::POST_TYPE,
						'post_status'      => 'publish',
						'numberposts'      => -1,
						'orderby'          => 'title',
						'order'            => 'ASC',
						'suppress_filters' => false,
					)
				);
			};

			$this->bounded_loader = null !== $bounded_loader ? $bounded_loader : static function ( array $box ): array {
				return (array) get_posts(
					array(
						'post_type'        => Post_Type::POST_TYPE,
						'post_status'      => 'publish',
						/*
						 * The map loader's -1 is justified by "a site with six
						 * branches must not show five". This one has a different
						 * and stronger reason, so it gets its own line rather
						 * than borrowing that one: the results are ordered by
						 * distance and MySQL does not know the distances, so any
						 * LIMIT here cuts the rows in title order before anything
						 * measures them. A search would then drop nearer
						 * locations and keep farther ones, and look fine doing
						 * it. The box is what keeps the row count sane.
						 */
						// phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_numberposts
						'numberposts'      => -1,
						'orderby'          => 'title',
						'order'            => 'ASC',
						'suppress_filters' => false,
						/*
						 * Plugin Check is expected to raise
						 * WordPress.DB.SlowDBQuery on this meta_query — expected
						 * rather than observed, since this suite runs no sniffs
						 * and nothing here has been through Plugin Check yet. The
						 * sniff is right in general and wrong about this
						 * query. A meta_query is slow because meta_value is a
						 * text column no index can help — which is exactly why
						 * the trigonometry is in PHP and only a BETWEEN is here.
						 * The alternative is not a faster query, it is loading
						 * every location on the site into PHP on every search:
						 * the box does not make the scan cheaper, it cuts how
						 * many rows cross into PHP and how much memory they
						 * occupy. A site that outgrows that wants coordinates in
						 * their own indexed columns, which is the custom table
						 * this class's seams exist to make possible.
						 *
						 * The four edges go into the query as floats and
						 * $wpdb->prepare() binds them through %s, which
						 * string-casts them. That is safe here only because of
						 * the PHP floor: locale-independent float-to-string
						 * arrived in PHP 8.0, and before it a site that had
						 * called setlocale( LC_NUMERIC, 'pl_PL' ) — which plenty
						 * of plugins do — would have bound '49,6' and matched
						 * nothing at all, on that site only.
						 */
						// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						'meta_query'       => array(
							'relation' => 'AND',
							array(
								'key'     => self::META_KEYS['lat'],
								'value'   => array( $box['min_lat'], $box['max_lat'] ),
								'compare' => 'BETWEEN',
								'type'    => 'DECIMAL(10,6)',
							),
							array(
								'key'     => self::META_KEYS['lng'],
								'value'   => array( $box['min_lng'], $box['max_lng'] ),
								'compare' => 'BETWEEN',
								'type'    => 'DECIMAL(10,6)',
							),
						),
					)
				);
			};
		}

		/**
		 * The transient key the payload for this request lives under.
		 *
		 * Four segments, and each one is load-bearing: the plugin's prefix, the
		 * payload shape's version, the generation, and the language. The
		 * generation is the one that changes at runtime, and it is what makes an
		 * invalidation reach a language this request has never heard of.
		 *
		 * Public because Task 20's "clear cache" button has to name it, and
		 * because a test that computed the key itself would agree with any
		 * mistake this method made.
		 *
		 * @return string
		 */
		public function cache_key(): string {
			return self::CACHE_PREFIX
				. '_v' . self::CACHE_VERSION
				. '_g' . $this->generation()
				. '_' . $this->current_language();
		}

		/**
		 * The generation every key currently carries.
		 *
		 * absint() is the whole of the defence, and it is needed for three real
		 * values rather than as a formality. get_option() answers false for an
		 * option nothing has written — every site, on the day it installs the
		 * plugin — and it answers with whatever an importer, a half-finished
		 * migration or another plugin on the same name left behind, which can be
		 * an empty string or a word. Concatenated raw, all three collapse the
		 * segment to nothing and give 'slosm_stores_lean_v1_g_pl': a key two
		 * different generations would share, which is an invalidation that
		 * silently stops working.
		 *
		 * Zero is a generation like any other, and losing the option is not
		 * symmetrical. Forwards it costs nothing: payloads written under the
		 * generations the site forgot simply become unreachable and expire.
		 * Backwards it is a real failure, and it is the one CACHE_PREFIX's
		 * docblock states for uninstall — a site that drops this option while its
		 * payloads survive in Redis falls back to 0, and any surviving _g0
		 * payload becomes live again, however old and however wrong. That is why
		 * an uninstaller has to delete the payloads and this option together, and
		 * why nothing should ever reset it on its own.
		 *
		 * @return int
		 */
		private function generation(): int {
			return absint( get_option( self::GENERATION_OPTION, 0 ) );
		}

		/**
		 * Every location that can be drawn on a map.
		 *
		 * Built once per language and held in a transient; see the class docblock
		 * for what the transient holds and why. The Stores that come back carry
		 * the seven lean fields and empties for the rest, because that is what was
		 * cached.
		 *
		 * Three things are deliberately not cached:
		 *
		 * - A payload built while the taxonomy was refusing to answer. Called
		 *   before init — a plugin reading locations at plugins_loaded, a badly
		 *   ordered hook — every get_the_terms() call returns
		 *   WP_Error( 'invalid_taxonomy' ), categories_for() turns that into no
		 *   categories, and caching the result would put a map with no filter
		 *   chips in front of every visitor for a day. Skipping the write costs
		 *   this one request a rebuild; it also means that when the same request
		 *   asks again after init, the answer is right.
		 * - The same payload in the memo, for the same reason and with the same
		 *   benefit inside one request.
		 * - Nothing at all, when set_transient() says it failed. It is checked
		 *   rather than ignored because the failure is real and silent:
		 *   Memcached's default item limit is 1 MB, so a site past roughly 4,000
		 *   locations writes nothing and rebuilds on every request, invisibly and
		 *   only on some object caches. slosm_cache_write_failed is how a site
		 *   can see it; the memo is what keeps it to one rebuild per request
		 *   rather than one per call.
		 *
		 * About $args: it is reserved, and nothing in it changes the answer
		 * today. The signature is the one the design document specifies, and the
		 * parameter is kept so that callers written against it do not have to
		 * change — but a filter cannot simply be forwarded to the loader. One key
		 * serves every caller in a language, so a loader asked for a filtered list
		 * would write that list under the key everybody else reads, and the map
		 * would show one category's locations to every visitor. When a filter does
		 * arrive, it filters the loaded list in PHP after the read.
		 *
		 * @param array $args Reserved; no argument changes the result today.
		 * @return Store[] Locations, keyed from zero.
		 */
		public function find_all( array $args = array() ): array {
			$key  = $this->cache_key();
			$rows = $this->loaded_rows( $key );

			// Loaded means the memo or the transient, and loaded_rows() is the
			// one place that decides which — find_near() asks the same question
			// and has to get the same answer, or a site would search a list its
			// own map is not showing.
			if ( null === $rows ) {
				$rows = $this->build_payload();

				if ( $this->taxonomy_failed ) {
					return $this->to_stores( $rows );
				}

				$ttl = array() === $rows ? self::EMPTY_CACHE_TTL : self::CACHE_TTL;

				if ( false !== set_transient( $key, $rows, $ttl ) ) {
					// Something is now cached under the current generation, so a
					// later flush in this same request has something to make
					// unreachable and must not be swallowed by the guard.
					$this->generation_spent = false;
				} else {
					/**
					 * Fires when the map payload could not be written to the cache.
					 *
					 * Every request then rebuilds it. The usual cause is an object
					 * cache refusing an item over its size limit — Memcached's
					 * default is 1 MB, which this payload reaches at roughly 4,000
					 * locations.
					 *
					 * @param string $key   Transient key that could not be written.
					 * @param int    $count Locations in the payload.
					 */
					do_action( 'slosm_cache_write_failed', $key, count( $rows ) );
				}
			}

			$this->memo     = $rows;
			$this->memo_key = $key;

			return $this->to_stores( $rows );
		}

		/**
		 * The locations within a radius of a point, nearest first.
		 *
		 * Two paths, chosen automatically by what is already in hand:
		 *
		 * - The list for this language is loaded — in the memo, or in the
		 *   transient this request has not read yet. Then the answer is already
		 *   in memory and the search is a filter and a sort over it, with no
		 *   query at all. This is the common case on a site inside the preload
		 *   threshold, where the map has just been rendered from that same list.
		 * - Nothing is loaded. Then the bounded loader is asked for the rows
		 *   inside Geo::bounding_box(). It deliberately does not build the shared
		 *   payload: a search is a poor moment to spend a full rebuild, and the
		 *   list it would build is one this caller does not need whole.
		 *
		 * Neither path writes the cache. The transient holds the map payload for
		 * a language and nothing else; there is no per-search cache here, and
		 * there should not be one keyed on coordinates a browser reports to six
		 * decimal places.
		 *
		 * One thing does differ between the paths, and it is not the shape:
		 * freshness. The preloaded path answers from a payload that can be up to
		 * CACHE_TTL old — a day, on a site where nothing has been saved — while the bounded
		 * path reads storage as it is now. A location saved a minute ago is
		 * therefore in one answer and not the other, which is the same window
		 * the map itself has and not a wider one.
		 *
		 * The preloaded path builds a Store for every cached location before it
		 * measures anything, so its cost scales with the site rather than with
		 * the answer: five hundred objects per search at the preload ceiling. It
		 * is in-memory work with no I/O. Cutting it means deciding what a
		 * coordinate is from the raw cached row, which is Store's knowledge, and
		 * a second place that decides it is a worse trade than the objects.
		 *
		 * The box is a prefilter, never the answer
		 * ----------------------------------------
		 * A latitude/longitude rectangle that contains a circle also contains
		 * four corners that are outside it — at a 100 km radius from Radom, a
		 * corner of the box is 141 km away — so the exact distance is computed
		 * for every candidate and anything past the radius is dropped. Skipping
		 * that step turns the prefilter into the filter, and the failure is
		 * quiet: results appear, they are simply farther away than the visitor
		 * asked for, in a list sorted so the extras are at the bottom.
		 *
		 * The same is true in the other direction on the preloaded path, which
		 * has no box at all: both paths measure, so both answer the same
		 * question.
		 *
		 * What comes back
		 * ---------------
		 * Store objects carrying the seven lean fields and a distance, on both
		 * paths. The bounded path reads whole rows and then reduces them, which
		 * looks wasteful and is deliberate: which path ran depends on whether a
		 * payload happens to be cached, so a result shape that differed between
		 * them would make a caller's behaviour depend on the site's size and its
		 * cache state. Task 10's list response would then carry every match's
		 * phone number and opening hours on one kind of site and not on the
		 * other. A caller that needs the whole record asks find_by_id() for one
		 * location.
		 *
		 * Which means these are partial Stores, on both paths, and Task 10 has to
		 * know it. Ten of the seventeen fields are empty strings, and a partial
		 * Store still answers to_full_array() with a seventeen-key record that
		 * looks complete — there is no marker and the return type cannot tell the
		 * two apart. A list response built from that would be an HTTP 200 whose
		 * every match has a blank phone number, blank hours and a blank
		 * description. Build it from to_lean_array() plus the distance read off
		 * the object, and fetch the whole record per location with find_by_id().
		 *
		 * The distance is in $unit, and nothing here validates $unit. Geo treats
		 * anything but 'mi' as kilometres, in the box and in the distance alike,
		 * so the two cannot come to disagree; rejecting a misspelled unit belongs
		 * where somebody typed it — the settings sanitiser, the shortcode
		 * handler, the REST validate_callback — and Geo::UNITS is the list all
		 * three check against.
		 *
		 * @param float  $lat    Latitude of the point searched from, in degrees.
		 * @param float  $lng    Longitude of the point searched from, in degrees.
		 * @param float  $radius Search radius, in $unit.
		 * @param int    $limit  How many results at most; below one returns none.
		 * @param string $unit   'mi' for miles; anything else means kilometres.
		 * @return Store[] Locations inside the radius, nearest first, each carrying its distance.
		 */
		public function find_near( float $lat, float $lng, float $radius, int $limit, string $unit = 'km' ): array {
			// A count of results, not get_posts()'s -1-means-everything. Nobody
			// is served by a limit that arrived empty from a request parameter
			// turning into the whole list, and no query is needed to return none.
			if ( 1 > $limit ) {
				return array();
			}

			// NAN is not a radius, and it cannot be left to the comparison
			// below: every comparison against NAN is false, so `$distance >
			// $radius` would drop nothing and the preloaded path would answer a
			// nonsensical search with every location on the site. The bounded
			// path would answer the same search with nothing, because
			// Geo::bounding_box() already turns NAN into zero and boxes a point
			// — so without this the result would depend on whether a transient
			// happened to be warm. Zero here is Geo's own answer to the same
			// input, for its own reason: finding nothing for a radius that is
			// not a number is at least true.
			//
			// A negative radius needs no guard. The comparison handles it — no
			// distance is below zero, so the list empties — and that agrees with
			// the box, which Geo inverts to a point for the same input.
			$radius = is_nan( $radius ) ? 0.0 : $radius;

			$rows = $this->loaded_rows( $this->cache_key() );

			$candidates = null !== $rows
				? $this->to_stores( $rows )
				: $this->bounded_candidates( $lat, $lng, $radius, $unit );

			$found = array();

			foreach ( $candidates as $store ) {
				// The preloaded list cannot contain one of these; an injected or
				// future loader can, and a location at 0,0 would otherwise be
				// measured as a real place in the Gulf of Guinea.
				if ( ! $store->has_coordinates() ) {
					continue;
				}

				$distance = Geo::distance( $lat, $lng, (float) $store->lat, (float) $store->lng, $unit );

				if ( $distance > $radius ) {
					continue;
				}

				$found[] = $store->with_distance( $distance );
			}

			usort(
				$found,
				static function ( Store $a, Store $b ): int {
					return (float) $a->distance <=> (float) $b->distance;
				}
			);

			// After the sort, never before it: slicing the candidates first would
			// keep whichever ones the loader happened to return — alphabetically
			// first, in the default query — and drop nearer locations.
			return array_slice( $found, 0, $limit );
		}

		/**
		 * One location, whole.
		 *
		 * Always read from storage. Never from the cached payload, and that is
		 * the contract rather than an implementation detail: the payload holds
		 * the lean seven fields, so a Store rebuilt from it has empty strings
		 * where the description, the opening hours, the phone number and the
		 * email belong — and answers to_full_array() with a record that looks
		 * complete and is not. Task 10's /stores/<id> served from that list would
		 * be an HTTP 200 with the popup's content silently blank, on every
		 * location, on every site inside the preload threshold. The test 'reads
		 * the whole record even when the lean payload is cached' warms the cache
		 * and then asserts a field that only the full record has.
		 *
		 * The cost is one get_post() and thirteen meta reads per call, and it is
		 * paid once per popup opened rather than once per location shipped. A
		 * popup cache belongs in the browser, which is where Task 15 puts it.
		 *
		 * Null for anything that is not a published location of this plugin's
		 * post type. The type check is what stops /stores/<id> reading any post
		 * on the site through a numeric id; the status check matches find_all(),
		 * which ships published locations only, so that a popup endpoint cannot
		 * become a way to read a draft. An admin-side caller that needs drafts —
		 * a bulk geocoder run over unpublished locations — needs a deliberate
		 * widening here rather than the silence of a missing check.
		 *
		 * Null as well for a location carrying a post password, and this is the
		 * check that closes a real hole rather than a theoretical one. to_store()
		 * maps post_content into `description`, so without it Task 10's
		 * /stores/<id> hands the whole body of a published-but-protected location
		 * to any anonymous caller, while the same content on the front end sits
		 * behind a password form.
		 *
		 * post_password is read directly and post_password_required() is
		 * deliberately not called, which is the opposite of what core's posts
		 * controller does and is right here for a reason specific to this post
		 * type. That function answers "has this visitor entered the password",
		 * by hashing a wp-postpass_ cookie — and this post type is registered
		 * public => false with no rewrite, so it has no front-end URL, so there
		 * is no password form anywhere on the site that could ever set that
		 * cookie for it. Calling it would be asking a question whose answer is
		 * always "no", through a phpass hash, on every popup. Reading the field
		 * asks the question that can actually be answered: is this location
		 * fenced off.
		 *
		 * And the answer is null rather than core's — core blanks content and
		 * excerpt and returns the item — because there is no useful protected
		 * version of this record. The whole of it is popup content: the
		 * description, the opening hours, the phone number, the email. Returning
		 * a 200 with those blanked is the exact failure this plugin keeps
		 * finding, an answer that looks complete and is not; a 404 is a state
		 * somebody can see and explain.
		 *
		 * One asymmetry is left open on purpose rather than closed quietly.
		 * find_all() still carries such a location's seven lean fields — name,
		 * address, city, coordinates, categories — because build_payload() reads
		 * every published row and does not look at post_password either. That is
		 * a smaller question than this one and it is a genuine question, not an
		 * oversight: core gates post_content and post_excerpt and nothing else,
		 * so a protected post's title is public, its meta is public and its
		 * existence is public. The fields the payload ships are exactly the ones
		 * a public map exists to publish. Whether a password on one of these
		 * posts should also take its marker off the map is a decision for
		 * whoever owns the payload, and it is written down here so that it is a
		 * decision rather than a silence.
		 *
		 * That is the whole of the agreement, and the rest is worth stating
		 * because it is easy to assume. find_all()'s loader runs with
		 * suppress_filters => false, so WPML and Polylang narrow it to the
		 * language of the request; get_post() takes a primary key and no language
		 * filter touches it. So this method answers for a location that is not in
		 * this request's payload at all — a Polish branch fetched while the site
		 * is being served in English. That is the right answer for a deep link
		 * and for a popup opened from a link somebody shared, and it is chosen
		 * rather than inherited: an id is an id, and returning null for a real
		 * published location because of the visitor's language would be a 404
		 * nobody could explain.
		 *
		 * @param int $id Post id.
		 * @return Store|null The whole record, or null when there is no such readable published location.
		 */
		public function find_by_id( int $id ): ?Store {
			if ( 1 > $id ) {
				return null;
			}

			$post = get_post( $id );

			// Null for an id that is nothing; anything else shaped like a post is
			// read for its type and status rather than trusted to be one.
			if ( ! is_object( $post ) ) {
				return null;
			}

			if ( Post_Type::POST_TYPE !== ( $post->post_type ?? '' ) ) {
				return null;
			}

			if ( 'publish' !== ( $post->post_status ?? '' ) ) {
				return null;
			}

			// '' for an unprotected post, and the property is absent entirely on
			// a row an injected loader built by hand, so both have to read as
			// "not protected" without a warning.
			if ( '' !== (string) ( $post->post_password ?? '' ) ) {
				return null;
			}

			return $this->to_store( $post );
		}

		/**
		 * One location, whole, for a screen that is already behind a capability
		 * check.
		 *
		 * find_by_id() with two of its three gates taken off, deliberately, and
		 * its docblock names this method's case: "An admin-side caller that
		 * needs drafts — a bulk geocoder run over unpublished locations — needs
		 * a deliberate widening here rather than the silence of a missing
		 * check." Task 19's list table is the first such caller.
		 *
		 * The status gate goes because the list table lists every status there
		 * is. A draft location is the one an editor is most likely to be working
		 * on, and find_by_id() would answer null for it — three empty cells in
		 * the row of the location that most needs looking at.
		 *
		 * The password gate goes for a narrower reason, and it is worth stating
		 * rather than inheriting. It exists in find_by_id() because that method
		 * feeds a public popup endpoint and this record carries post_content;
		 * here the caller is an admin screen that has already asked
		 * current_user_can(), and the fields it prints — the address, the city —
		 * are meta, which core does not gate behind a post password at all.
		 * Returning null would blank the row of a protected location while the
		 * editor can see its title two columns to the left.
		 *
		 * The post type gate stays. It is what stops a caller reading any post
		 * on the site through a numeric id, and it costs nothing.
		 *
		 * **The caller owns the capability check, and the name does not enforce
		 * one.** "for_admin" describes the intended caller, not a gate: this
		 * method is public, takes an int, asks current_user_can() nothing, and
		 * returns the whole record — post_content included — for any status and
		 * for a password-protected location. That is correct today because the
		 * only caller is a column callback on a screen WordPress has already put
		 * behind edit_posts. It stops being correct the moment something calls
		 * it from a wp_ajax_ handler or a REST permission-less route, which
		 * Tasks 20 and 22 both plausibly want to do, and the next caller will
		 * not have read the commit that added this. A caller that is not already
		 * behind a capability check must make one, or use find_by_id().
		 *
		 * Nothing here is cached, and the cost is one get_post() and thirteen
		 * meta reads per call — all of which a list table's own WP_Query has
		 * already primed; see to_store() for why that is a claim about
		 * WordPress rather than about this class, and for where it stops holding.
		 *
		 * @param int $id Post id.
		 * @return Store|null The whole record, or null when there is no such location.
		 */
		public function find_for_admin( int $id ): ?Store {
			if ( 1 > $id ) {
				return null;
			}

			$post = get_post( $id );

			if ( ! is_object( $post ) ) {
				return null;
			}

			if ( Post_Type::POST_TYPE !== ( $post->post_type ?? '' ) ) {
				return null;
			}

			return $this->to_store( $post );
		}

		/**
		 * The meta clauses that select a location with no usable coordinates.
		 *
		 * Here rather than in the admin screen for the reason this class exists:
		 * these are meta keys, and no other class learns one. What the caller
		 * gets is a clause group it can put into a WP_Query, and the day
		 * locations move into a custom table this method is one of the things
		 * that changes rather than one of the places the change has to be
		 * hunted down.
		 *
		 * Five clauses in an OR, and each one catches a state the others do not:
		 *
		 * - A latitude or longitude with no row at all. That is an import, or a
		 *   location nobody has opened since the plugin was installed.
		 *   'NOT EXISTS' is the compare that means it, and it is also what makes
		 *   the whole query work: WP_Meta_Query joins a NOT EXISTS clause with a
		 *   LEFT JOIN and then converts every other join in the query to LEFT as
		 *   well — its own comment says "Otherwise posts with no metadata will
		 *   be excluded from results", wp-includes/class-wp-meta-query.php of
		 *   WordPress 6.9.1. Without one, the query would be an INNER JOIN and
		 *   would exclude exactly the rows it is looking for.
		 * - A latitude or longitude that is an empty string. That is what this
		 *   plugin itself writes: the metabox stores '' rather than deleting the
		 *   row, so an editor who cleared both fields leaves two empty strings.
		 *   A filter written only around NOT EXISTS would miss every location
		 *   this plugin has ever unplaced.
		 * - The pair 0,0, which is what a failed geocode leaves behind and which
		 *   has_coordinates() calls unplaced. Both halves have to be zero, and
		 *   that is the whole reason this is a nested AND rather than two more
		 *   clauses in the OR: a location at 0,21 is in the Gulf of Guinea and
		 *   one at 52,0 is in Cambridgeshire, and an OR would take both of them
		 *   off a map they belong on.
		 *
		 * What it does not catch, stated narrowly, because the obvious statement
		 * of it is wider than the truth
		 * -------------------------------------------------------------------
		 * The tempting sentence is "a coordinate holding text is missed". It is
		 * not. MySQL casts a non-numeric string to 0, so a pair like ( 'brak',
		 * 'brak' ) — or an empty string beside one — is caught by the nested AND
		 * above, exactly as 0,0 is. What escapes is narrower and worth naming
		 * precisely: **text that casts to something other than zero in at least
		 * one of the two halves**. CAST takes the leading numeric prefix, so
		 * '52,2297' out of a Polish spreadsheet casts to 52, the AND fails, and
		 * the row is not found — while Store reads it as no coordinate and the
		 * admin warning marks it. That one shape, and only it.
		 *
		 * Closing it needs a REGEXP over meta_value for each coordinate, which
		 * is a second unindexed scan per key and still not is_numeric() — PHP
		 * accepts '1e5' and a leading space and a regexp written to match would
		 * not. The plugin's own writes cannot produce the state. There is a case
		 * on each side of the line, so it is a decision rather than a
		 * discovery.
		 *
		 * One fact about how WordPress joins these, because it is load-bearing
		 * and invisible
		 * -------------------------------------------------------------------
		 * find_compatible_table_alias() lets clauses under an OR share one join
		 * whenever both compares are "positive" — and *regardless of key*
		 * (class-wp-meta-query.php). So the two empty-string clauses above share
		 * an alias, which is harmless: the join carries no key, each branch
		 * names its own key in the WHERE, and a post matches if any joined row
		 * satisfies any branch.
		 *
		 * The nested AND is the one that would break if they shared, and it
		 * cannot: under AND the same function shares a join only for *negative*
		 * compares on the *same* key, so the two halves of the 0,0 test get
		 * separate aliases. Sharing one would produce `alias.meta_key =
		 * '_slosm_lat' AND alias.meta_key = '_slosm_lng'` on a single row, which
		 * no row can satisfy, and the 0,0 test would silently find nothing.
		 *
		 * @return array A meta_query clause group.
		 */
		public function unplaced_meta_query(): array {
			return array(
				'relation' => 'OR',
				array(
					'key'     => self::META_KEYS['lat'],
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => self::META_KEYS['lng'],
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => self::META_KEYS['lat'],
					'value'   => '',
					'compare' => '=',
				),
				array(
					'key'     => self::META_KEYS['lng'],
					'value'   => '',
					'compare' => '=',
				),
				array(
					'relation' => 'AND',
					array(
						'key'     => self::META_KEYS['lat'],
						'value'   => 0,
						'compare' => '=',
						'type'    => 'DECIMAL(10,6)',
					),
					array(
						'key'     => self::META_KEYS['lng'],
						'value'   => 0,
						'compare' => '=',
						'type'    => 'DECIMAL(10,6)',
					),
				),
			);
		}

		/**
		 * The meta clauses that let a column be sorted without hiding rows.
		 *
		 * WP_Query sorts by meta through `meta_key` plus
		 * `orderby => meta_value`, and that spelling is an INNER JOIN on
		 * wp_postmeta: every post with no row for that key drops out of the
		 * result. On a locations list that is every location an import never
		 * gave a city, vanishing the moment somebody clicks the column heading,
		 * with nothing anywhere to say so.
		 *
		 * The EXISTS/NOT EXISTS pair is what fixes it, and it fixes it through
		 * the same line of WP_Meta_Query the unplaced filter leans on: one LEFT
		 * JOIN in the query turns them all LEFT, so nothing is dropped.
		 *
		 * Where those rows land, which is not where an earlier version of this
		 * docblock said
		 * -------------------------------------------------------------------
		 * That version said they "come back with NULL and sort to one end". They
		 * do not, and the difference is worth tracing because the half that
		 * matters is still true and the half that is wrong is user-visible.
		 *
		 * The trace, read out of WordPress 6.9.1:
		 *
		 * - A clause that is not NOT EXISTS joins with
		 *   `ON ( wp_posts.ID = alias.post_id )` and *no* meta_key; the key test
		 *   lands in the WHERE instead, as `$alias.meta_key = %s`
		 *   (class-wp-meta-query.php lines 596-611 and 671).
		 * - The NOT EXISTS sibling is the reverse — key in the ON, `post_id IS
		 *   NULL` in the WHERE — and its LEFT JOIN converts every join in the
		 *   query to LEFT (lines 374-378).
		 * - A meta_query at all gives `GROUP BY wp_posts.ID`
		 *   (class-wp-query.php line 2387), and wpdb strips ONLY_FULL_GROUP_BY
		 *   from the session's sql_mode (class-wpdb.php line 646), so MySQL
		 *   picks one row per group without complaining.
		 *
		 * For a location that *has* a city, only the OR's first branch can be
		 * satisfied, and that branch pins `alias.meta_key = '_slosm_city'` — so
		 * exactly one joined row survives and the sort key is the city. That is
		 * the ordering the heading promises, and it holds.
		 *
		 * For a location that has none, the NOT EXISTS branch is what keeps it,
		 * and that branch says nothing about the primary alias — which is then
		 * free to range over every other meta row the location has: the address,
		 * the phone number, the opening hours, the coordinates. ORDER BY reads
		 * whichever of them the group collapsed to. So cityless locations are
		 * **scattered through the sorted list at positions set by an unrelated
		 * value**, not gathered at one end. Only a location with no meta at all
		 * sorts as NULL.
		 *
		 * The sentence is corrected rather than the SQL, and that is a decision.
		 * WordPress's clause builder will not put a key test in the ON for a
		 * positive compare, so making the order deterministic means replacing
		 * this whole arrangement with a `posts_clauses` filter emitting
		 * `LEFT JOIN wp_postmeta AS … ON ( ID = …post_id AND …meta_key = %s )`
		 * by hand. That is the first raw SQL in a plugin that has none, on a
		 * screen every editor loads, to fix the *position* of rows that are
		 * already all present — and this suite cannot execute a line of it. The
		 * property the pair was added for is "no location disappears when
		 * somebody clicks a heading", and that property holds. Whoever wants the
		 * grouping as well has the join written out above, and the custom table
		 * this class's seams exist for would give it for nothing.
		 *
		 * The EXISTS half is *named*, and that is the mechanism rather than a
		 * label. WP_Query::parse_orderby() allows an orderby that is the key of
		 * a meta_query clause and renders it as
		 * `CAST( alias.meta_value AS CHAR )` — wp-includes/class-wp-query.php of
		 * WordPress 6.9.1 — and only a *first-order* clause gets a key at all:
		 * get_sql_for_query() passes the array key to get_sql_for_clause() for a
		 * leaf and recurses past it for a group. So the name has to be on the
		 * leaf, and the caller has to ask for that same name in its orderby.
		 *
		 * What it costs, said out loud rather than shipped quietly. wp_postmeta
		 * is indexed on post_id and on meta_key(191) and on nothing else;
		 * meta_value is a LONGTEXT column no index can order. So a sorted page
		 * is a join plus a filesort over every location on the site, not over
		 * the twenty being shown — MySQL has to order the whole set before it
		 * knows which twenty those are. It is paid only when an editor clicks
		 * the heading, and a site big enough for that to hurt is a site that
		 * wants the coordinates and the city in their own indexed columns, which
		 * is the custom table this class's seams exist to make possible.
		 *
		 * @param string $field  Field name; anything the mapping does not know gives no clauses.
		 * @param string $clause The name the caller will order by.
		 * @return array A meta_query clause group, or an empty array.
		 */
		public function sortable_meta_query( string $field, string $clause ): array {
			if ( ! isset( self::META_KEYS[ $field ] ) || '' === $clause ) {
				return array();
			}

			return array(
				'relation' => 'OR',
				$clause    => array(
					'key'     => self::META_KEYS[ $field ],
					'compare' => 'EXISTS',
				),
				array(
					'key'     => self::META_KEYS[ $field ],
					'compare' => 'NOT EXISTS',
				),
			);
		}

		/**
		 * Builds one location from one raw row.
		 *
		 * Public because it is the mapping, which is the one piece of knowledge
		 * this class exists to hold, and because nothing else can observe it: ten
		 * of the seventeen fields never appear in the lean payload, so a mistyped
		 * meta key for the phone number or the opening hours is invisible through
		 * find_all() and would ship. A test calls this directly and compares the
		 * whole record against a row written out by hand.
		 *
		 * The alternative was to keep it private and have the test reach through
		 * visibility with reflection. That hides nothing in practice — the test
		 * still depends on the name and the signature — while pretending the
		 * mapping is not part of what this class promises.
		 *
		 * Thirteen meta reads and one term read per location, and both should be
		 * free after the loader's own query. This is verified against WordPress
		 * 6.9.1's source rather than measured: WP_Query defaults
		 * update_post_meta_cache and update_post_term_cache to true and calls
		 * update_post_caches(), which primes update_postmeta_cache() and
		 * update_object_term_cache() for the whole result set in one query each;
		 * get_post_meta() and get_the_terms() both read those caches first.
		 * Nothing in this suite can demonstrate it — there is no object cache to
		 * observe and the stubs answer from arrays — and it wants measuring on a
		 * real site with Query Monitor before anyone leans on it. It also does not
		 * hold for an injected loader that is not a WP_Query: nothing primed
		 * anything, and get_the_terms() falls back to a query per location.
		 *
		 * @param object $row A post, or anything shaped like one.
		 * @return Store
		 */
		public function to_store( object $row ): Store {
			$id = isset( $row->ID ) && is_scalar( $row->ID ) ? (int) $row->ID : 0;

			$data = array(
				'id'          => $id,
				'name'        => $row->post_title ?? '',
				'description' => $row->post_content ?? '',
				'categories'  => $this->categories_for( $id ),
			);

			foreach ( self::META_KEYS as $field => $meta_key ) {
				$data[ $field ] = get_post_meta( $id, $meta_key, true );
			}

			// Store::from_array() casts and defaults every one of these; it does
			// not sanitise, and neither does this class. Values reaching here
			// were sanitised on write, which is where the untrusted value was.
			return Store::from_array( $data );
		}

		/**
		 * Writes some of a location's fields, through the mapping above.
		 *
		 * Here rather than in the metabox for the reason this class exists: the
		 * day locations move into a custom table, the change is this class's
		 * body plus a migration, and a single update_post_meta() in an admin
		 * screen is what turns that into an audit of the whole plugin. The
		 * metabox knows field names; it does not know that a field is meta at
		 * all.
		 *
		 * Partial on purpose. Task 17 saves ten text fields and two coordinates
		 * in one call and then, if a lookup happened, two coordinates in
		 * another; Task 20's bulk geocoder will only ever write the pair. A
		 * method that demanded the whole record would make both of those read
		 * the record first in order to write back the parts they were not
		 * changing.
		 *
		 * A field the mapping does not know is dropped rather than turned into
		 * a meta key. Inventing '_slosm_notes' from a caller's typo writes a row
		 * that to_store() never reads and Store::from_array() would ignore
		 * anyway: a write-only field, which is worse than an error because it
		 * looks like it worked.
		 *
		 * The slashing is the one piece of storage convention this method owns,
		 * and it is not optional. update_post_meta() unslashes what it is given
		 * — core's update_metadata() calls wp_unslash( $meta_value ) at
		 * wp-includes/meta.php line 222 of WordPress 6.9.1 — so a caller passing
		 * clean text loses every backslash in it. wp_slash() and wp_unslash()
		 * are exact inverses on strings, so this round trip is lossless; only
		 * strings are touched, which leaves an int or a float alone.
		 *
		 * Nothing is flushed here. Every caller is inside a post save, and
		 * Plugin::boot()'s hooks flush that; a flush here would spend a
		 * generation per call and hide from its callers that they are the ones
		 * who have to think about it. A caller writing meta outside a save —
		 * which is the gap the class docblock names — has to flush explicitly.
		 *
		 * @param int   $post_id Post id.
		 * @param array $fields  Field name to clean, unslashed value.
		 * @return void
		 */
		public function save_fields( int $post_id, array $fields ): void {
			foreach ( $fields as $field => $value ) {
				if ( ! isset( self::META_KEYS[ $field ] ) ) {
					continue;
				}

				update_post_meta( $post_id, self::META_KEYS[ $field ], wp_slash( $value ) );
			}
		}

		/**
		 * Makes every cached map payload unreachable, in every language.
		 *
		 * One increment of one option, and nothing is deleted or enumerated. The
		 * class docblock has why that beats a delete and why it beats a sweep;
		 * the short version is that a delete_transient() here could only name the
		 * language of the request it runs in, and a sweep of wp_options finds
		 * nothing on a site with a persistent object cache.
		 *
		 * The memo goes first and goes unconditionally. A flush that left this
		 * request answering from memory would make the save-then-render sequence
		 * this method exists to serve show the editor exactly the data they just
		 * changed away from — and the early return below must not be allowed to
		 * skip it, which is why it comes after.
		 *
		 * What $force is for
		 * ------------------
		 * A location's post row, its terms and its meta are written in that
		 * order, and only the first of the three has a hook here. So the flush
		 * that a save fires arrives *before* the coordinates it is invalidating
		 * are in the database, and a concurrent front-end request landing in that
		 * window builds the payload from the old coordinates and caches it under
		 * the generation the flush has just moved to. Nothing invalidates it
		 * afterwards: the save is over. Milliseconds of window, a full
		 * CACHE_TTL of wrong pins.
		 *
		 * The early flush is what opens that window — without it the concurrent
		 * request would have cached under the old generation, which the flush
		 * would then have made unreachable. So the answer is a second flush after
		 * the writes, from wp_after_insert_post, and it has to be allowed past
		 * the guard or it is the very flush the guard swallows. Two increments
		 * per save is the correct shape and the cheap half of the trade; one is
		 * the unsafe one.
		 *
		 * autoload is on, and that is a considered choice rather than a free one.
		 * cache_key() reads this option on every request that draws a map, so
		 * leaving it out of alloptions would be a query per such request, paid
		 * forever. The cost is on the other side: update_option() with an
		 * explicit autoload takes the branch that deletes the option from cache
		 * and then calls wp_load_alloptions( true ), a forced refresh that
		 * bypasses the runtime cache — so every flush fetches, unserializes,
		 * re-serializes and rewrites the whole alloptions blob. On a site with
		 * 500 KB of autoloaded options that is roughly a megabyte of object-cache
		 * traffic per location save, twice now. Passing null instead only trades
		 * it for a SELECT of the autoload column on every flush, and the read
		 * side is the one paid on every map request.
		 *
		 * Hooks are not registered here. Plugin::boot() owns them, because a
		 * repository that hooked itself on construction would register its
		 * callbacks again for every instance anything builds, including the ones
		 * a test builds.
		 *
		 * @param bool $force Spend a generation even if this request already has.
		 * @return void
		 */
		public function flush_cache( bool $force = false ): void {
			$this->memo     = null;
			$this->memo_key = '';

			// See $generation_spent: one save fires several hooks, and nothing is
			// known to be cached since the first of them moved the generation.
			if ( $this->generation_spent && ! $force ) {
				return;
			}

			$generation = $this->generation() + 1;

			// Checked rather than ignored, and for a worse reason than the
			// set_transient() check in find_all(). A failed write here leaves
			// every payload on the site reachable and correct-looking for a full
			// ttl — and setting the flag first would mean no later flush in this
			// request could recover, because the guard would swallow every one of
			// them. So the flag follows the write.
			//
			// update_option()'s benign false — the value was already what it is —
			// cannot happen here: the value always increments.
			if ( false === update_option( self::GENERATION_OPTION, $generation, true ) ) {
				/**
				 * Fires when the cache generation could not be moved.
				 *
				 * Every cached map payload on the site stays reachable, in every
				 * language, until it expires on its own ttl — so the map keeps
				 * serving what it had before the change that triggered this.
				 *
				 * @param string $option     Option that could not be written.
				 * @param int    $generation Generation the write was attempting.
				 */
				do_action( 'slosm_cache_flush_failed', self::GENERATION_OPTION, $generation );

				return;
			}

			$this->generation_spent = true;
		}

		/**
		 * The lean rows for this language, if this request can have them for free.
		 *
		 * Free means the memo or the transient. What it deliberately does not do
		 * is build the payload: find_all() is where a rebuild belongs, and a
		 * search that triggered one would spend a full read of every location on
		 * the site to answer a question about a few of them.
		 *
		 * A payload read here is memoised, exactly as find_all() memoises it. It
		 * is the same list under the same key, so a find_all() later in the
		 * request is answered from memory rather than from a second unserialize.
		 *
		 * The key is passed in rather than asked for again, so that a caller and
		 * this method cannot end up talking about two different languages: WPML
		 * switches language on demand, and cache_key() runs a filter that a site
		 * can answer differently between two calls in one request.
		 *
		 * @param string $key Transient key for this request's language.
		 * @return array[]|null Lean rows, or null when nothing is loaded.
		 */
		private function loaded_rows( string $key ): ?array {
			if ( null !== $this->memo && $key === $this->memo_key ) {
				return $this->memo;
			}

			$rows = get_transient( $key );

			// Not an array covers both the miss and the value another plugin or
			// a truncated object cache left on the key; both mean "not loaded",
			// and the bounded path answers without it.
			if ( ! is_array( $rows ) ) {
				return null;
			}

			$this->memo     = $rows;
			$this->memo_key = $key;

			// Something is cached under the current generation — built by a
			// concurrent request rather than by this one, which makes no
			// difference to how stale it will be after the next save. See
			// $generation_spent.
			$this->generation_spent = false;

			return $rows;
		}

		/**
		 * The locations inside the bounding box, in the shape the payload uses.
		 *
		 * Reduced through to_lean_array() so that both of find_near()'s paths
		 * answer in one shape; find_near() has why. Nothing here is cached: this
		 * list is one search's candidates, not the map payload, and the key it
		 * would be written under is the one every visitor reads.
		 *
		 * @param float  $lat    Latitude of the point searched from.
		 * @param float  $lng    Longitude of the point searched from.
		 * @param float  $radius Search radius, in $unit.
		 * @param string $unit   'mi' for miles; anything else means kilometres.
		 * @return Store[] Candidates, unmeasured and unsorted.
		 */
		private function bounded_candidates( float $lat, float $lng, float $radius, string $unit ): array {
			$box = Geo::bounding_box( $lat, $lng, $radius, $unit );

			$candidates = array();

			foreach ( (array) ( $this->bounded_loader )( $box ) as $row ) {
				// Same guard as build_payload(): a loader asked for ids, or a
				// filtered list with a null in it, would otherwise be a TypeError
				// on a front-end search.
				if ( ! is_object( $row ) ) {
					continue;
				}

				$candidates[] = Store::from_array( $this->to_store( $row )->to_lean_array() );
			}

			return $candidates;
		}

		/**
		 * Rebuilds Stores from cached rows.
		 *
		 * @param array $rows Lean rows.
		 * @return Store[]
		 */
		private function to_stores( array $rows ): array {
			$stores = array();

			foreach ( $rows as $row ) {
				// A row that is not an array cannot have come from here. Whatever
				// left it — a key collision with another plugin, a truncated
				// object cache value — Store::from_array() would be a TypeError
				// on a front-end page, and one unreadable row is not worth that.
				if ( ! is_array( $row ) ) {
					continue;
				}

				$stores[] = Store::from_array( $row );
			}

			return $stores;
		}

		/**
		 * Turns the loader's rows into the array the transient holds.
		 *
		 * The list is re-keyed from zero by construction — rows are appended, not
		 * filtered in place — and that is not cosmetic. array_filter() preserves
		 * keys, and json_encode() turns array( 0 => ..., 2 => ... ) into an object
		 * with the keys "0" and "2" rather than a JSON array, which is a front end
		 * that silently stops iterating the moment one location loses its
		 * coordinates.
		 *
		 * @return array[] Lean rows.
		 */
		private function build_payload(): array {
			$this->taxonomy_failed = false;

			$payload = array();

			foreach ( (array) ( $this->loader )() as $row ) {
				// 'fields' => 'ids' on the query would hand back integers, and a
				// filtered list can carry a null. Skipping is quiet, but the
				// alternative is a TypeError on a front-end page, and the case
				// only arises from a mistake inside this class.
				if ( ! is_object( $row ) ) {
					continue;
				}

				$store = $this->to_store( $row );

				if ( ! $store->has_coordinates() ) {
					continue;
				}

				$payload[] = $store->to_lean_array();
			}

			return $payload;
		}

		/**
		 * The category names on one location.
		 *
		 * get_the_terms() rather than wp_get_object_terms(), which is the change
		 * that takes a rebuild from one query per location to none. Verified in
		 * WordPress 6.9.1's source: get_the_terms() reads
		 * get_object_term_cache() first, which is the cache the loader's own
		 * WP_Query primed for every row at once, while wp_get_object_terms() goes
		 * straight to WP_Term_Query and queries per object id. On 500 locations
		 * that is 500 queries a rebuild against none — and it matters most
		 * immediately after a save, when every concurrent visitor is rebuilding.
		 * See to_store() for why this is stated as verified rather than measured.
		 *
		 * Three shapes come back, and all three are real:
		 *
		 * - WP_Term[], the ordinary case, plucked down to names. Store drops a
		 *   non-scalar category rather than casting it, because a WP_Term has no
		 *   __toString() and a cast would give a blank chip with the right count.
		 * - false, which is how get_the_terms() says "no categories on this one",
		 *   and also what it says when the post does not exist.
		 * - WP_Error, which is what an unregistered taxonomy looks like — a call
		 *   before init. That is the one this method cannot just absorb: see
		 *   find_all() for why the build stops being cached.
		 *
		 * Returning the WP_Error would be a TypeError raised inside this class,
		 * since this method declares an array return. Store itself would have
		 * survived it — Store::categories() checks is_array() and gives back an
		 * empty list — so the guard is about where the failure lands, not about
		 * whether the value could reach a Store.
		 *
		 * One caveat to carry forward: get_the_terms() applies the get_the_terms
		 * filter, so third-party code can change what lands in a payload shared by
		 * every visitor in that language. wp_get_object_terms() has filters of its
		 * own, so this is a different surface rather than a new one.
		 *
		 * @param int $id Post id.
		 * @return string[]
		 */
		private function categories_for( int $id ): array {
			$terms = get_the_terms( $id, Post_Type::TAXONOMY );

			if ( is_wp_error( $terms ) ) {
				$this->taxonomy_failed = true;

				return array();
			}

			if ( ! is_array( $terms ) ) {
				return array();
			}

			return array_values( wp_list_pluck( $terms, 'name' ) );
		}

		/**
		 * The language this request is being served in.
		 *
		 * Asked of WPML first, then Polylang, then WordPress. The order is not
		 * arbitrary: a site running WPML has a filter that answers and a locale
		 * that may not have changed yet at the moment the payload is built, and
		 * the plugin that is doing the filtering is the one whose answer matches
		 * the rows the loader returned.
		 *
		 * determine_locale() is the fallback and is not a multilingual answer at
		 * all — it is the site locale on the front end, which is constant, so a
		 * monolingual site gets exactly one key. In wp-admin it is the current
		 * user's own locale, so an editor whose profile is Polish on an English
		 * site builds a payload under a key of their own. That is a wasted
		 * rebuild rather than a wrong answer, and it is the price of not special
		 * casing the admin.
		 *
		 * sanitize_key() because this goes into an option name: a locale is
		 * already safe, but a filtered language code is whatever a plugin returned.
		 *
		 * @return string
		 */
		private function current_language(): string {
			/*
			 * Reported by Plugin Check as an unprefixed hook name, and it is a
			 * false positive worth leaving a sentence about rather than a bare
			 * annotation — the reviewer reads the same report.
			 *
			 * The sniff's rule is "a hook a plugin *invents* should carry that
			 * plugin's prefix", and it is right. This hook is not invented
			 * here: `wpml_current_language` is WPML's own, documented by WPML,
			 * and applying it is how anything asks WPML what language the page
			 * is in. Prefixing it would rename it to something WPML has never
			 * heard of, so the filter would answer null on every site that has
			 * WPML and the locator would stop being multilingual. The sniff
			 * cannot tell consuming somebody else's published filter from
			 * minting an unprefixed one of your own; a reader can.
			 */
			/** This filter is documented in WPML, which is where it comes from. */
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own documented filter, consumed rather than invented.
			$language = apply_filters( 'wpml_current_language', null );

			if ( ! is_string( $language ) || '' === $language ) {
				$language = function_exists( 'pll_current_language' ) ? pll_current_language() : null;
			}

			if ( ! is_string( $language ) || '' === $language ) {
				$language = determine_locale();
			}

			return sanitize_key( $language );
		}
	}
}
