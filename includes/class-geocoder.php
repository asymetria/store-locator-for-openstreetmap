<?php
/**
 * The one place this plugin talks to a geocoding service.
 *
 * @package Store_Locator_For_OpenStreetMap
 */

namespace Asymetria\StoreLocator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( __NAMESPACE__ . '\\Geocoder' ) ) {

	/**
	 * Turns an address into coordinates by asking Nominatim, server side only.
	 *
	 * A note on every mention of the usage policy below
	 * -------------------------------------------------
	 * This class is shaped by Nominatim's usage policy, so the policy is quoted
	 * from in several places — the User-Agent, the one request a second, the
	 * unsuitability of the public instance for autocomplete. All of it is the
	 * policy as understood when this was written, and none of it was re-read
	 * while writing it: nothing in this repository is allowed to make a network
	 * request, including to go and check. Treat those sentences as the reason
	 * the code has the shape it has, not as citations. If the policy has moved,
	 * the constants here are the things to move with it.
	 *
	 * Why server side
	 * ---------------
	 * The obvious design is to let the visitor's browser call Nominatim
	 * directly: no proxy, no cache to invalidate, no PHP blocked on a third
	 * party. It is also the design that gets a client's visitors blocked.
	 *
	 * Nominatim's usage policy asks for a stable User-Agent that identifies the
	 * application and for no more than one request a second. Neither survives
	 * being moved into the browser, and the second is the one that does the
	 * damage: requests would arrive from every visitor's address at once, with
	 * nothing anywhere able to count them, so a shop with a busy afternoon can
	 * exceed the limit without a single person doing anything unusual. The block
	 * that follows lands on the visitors, not on the site owner, and it is
	 * invisible from the admin screen.
	 *
	 * The identity is the same argument one step quieter. Whatever a browser
	 * could be persuaded to send, it would be per-visitor and per-browser rather
	 * than per-application, which is the opposite of the "stable" the policy
	 * asks for — and an operator who cannot tell an application's traffic apart
	 * has nothing to throttle except the address it came from. What exactly a
	 * browser will and will not let script set is a detail this comment
	 * deliberately does not claim, because it is a moving target and nothing
	 * here depends on the answer.
	 *
	 * The proxy is what makes the three things the policy actually asks for
	 * possible at all: one identity, one cache, and one rate limiter. None of
	 * them can exist in a browser, because none of them can be shared between
	 * browsers.
	 *
	 * The clock and the sleeper are constructor arguments
	 * ---------------------------------------------------
	 * Following Store_Repository's loader. A rate limiter that reads
	 * microtime() and calls usleep() is a rate limiter no test can prove: a case
	 * would have to actually sleep, and it could still only assert that time
	 * passed, never that the class asked for it. So both are injected, and the
	 * suite passes recorders. A global slosm_sleep() function was considered in
	 * Task 1 and rejected for the same reason the loader is not global: a seam
	 * that every instance shares is a seam two tests cannot use differently.
	 *
	 * What is not here
	 * ----------------
	 * No hooks and no REST routes. Task 10 wires this class into a controller;
	 * a geocoder that hooked itself on construction would register its callbacks
	 * again for every instance anything built, including the ones a test builds.
	 *
	 * No Accept-Language either, and that is a gap rather than a decision to be
	 * proud of: Nominatim picks the language of display_name from that header,
	 * so labels come back in whatever the place's local language is. Sending one
	 * would mean putting the language into the cache key as well — Task 6 has
	 * the whole argument for why a shared payload keyed without the language is
	 * a bug — and nothing has asked for it yet.
	 *
	 * What "never cache a failure" is protecting
	 * ------------------------------------------
	 * Every error path returns without writing anything. This is the single
	 * most valuable rule in the class: the cache lifetime is thirty days, so
	 * caching one HTTP 429 would leave a site unable to geocode anything for a
	 * month, with no error anywhere, until somebody thought to clear a transient
	 * nobody knows the name of. The failure is silent in both directions, which
	 * is why the tests for it assert on array_key_exists() rather than on
	 * get_transient() — the latter cannot tell a refusal from a cached false.
	 */
	final class Geocoder {

		/**
		 * The service that answers geocode(): a query in, one point out.
		 *
		 * @var string
		 */
		public const SERVICE_NOMINATIM = 'nominatim';

		/**
		 * The service that answers suggest(): a prefix in, a short list out.
		 *
		 * @var string
		 */
		public const SERVICE_PHOTON = 'photon';

		/**
		 * The option every setting this class reads lives in.
		 *
		 * Task 21 built the screen that writes it — the number here was wrong
		 * while it said 18, which is what a forward reference costs. Everything
		 * in this class still reads the option defensively, and that has not
		 * stopped being worth it now that a screen exists: Settings::sanitise()
		 * gates every update_option() on the site, but not a direct database
		 * edit, a restored backup taken before the screen existed, or another
		 * plugin that took the same option name.
		 *
		 * @var string
		 */
		public const SETTINGS_OPTION = 'slosm_settings';

		/**
		 * The prefix every cache key starts with.
		 *
		 * What it is for is recognition, not invalidation, and the distinction
		 * is the same one Store_Repository::CACHE_PREFIX draws: a sweep of
		 * wp_options for option_name LIKE '_transient_slosm_geo_%' finds nothing
		 * at all on a site with a persistent object cache, because transients
		 * there live in Redis or Memcached under keys no SQL can see. The
		 * uninstaller still needs the prefix — it runs once and can afford to
		 * sweep the sites where the sweep works — but a "clear cache" button
		 * that relied on it would do nothing on exactly the better-hosted sites,
		 * silently.
		 *
		 * So invalidation goes through GENERATION_OPTION instead. An earlier
		 * version of this docblock claimed the parallel with Store_Repository
		 * and did not carry the mechanism that makes the parallel true; the
		 * generation is that mechanism.
		 *
		 * @var string
		 */
		public const CACHE_PREFIX = 'slosm_geo';

		/**
		 * The option holding the generation every cache key carries.
		 *
		 * Incremented by flush_cache(), read by build_cache_key(), and that is
		 * the whole mechanism: one write makes every cached lookup on the site
		 * unreachable at once, in every language and under every country, with
		 * no enumeration and nothing for an object cache to hide. The
		 * unreachable entries then expire on their own ttl — up to thirty days
		 * of space a cache is built to reclaim, in exchange for an invalidation
		 * that does not depend on being able to list what it is invalidating.
		 *
		 * CACHE_VERSION cannot do this job. It is a code constant, so only a
		 * plugin release moves it, and "clear the geocode cache" is a thing a
		 * site does on a Tuesday because an address was fixed upstream.
		 *
		 * Autoload is off, unlike the repository's. That one is read on every
		 * front-end request, so it belongs in alloptions; this one is read only
		 * by requests that geocode, and a site can go weeks without one.
		 *
		 * Public because the uninstaller has to delete it by name — together
		 * with the transients, never one without the other, or a reinstall
		 * starts life pointing at a generation whose entries are still in the
		 * object cache.
		 *
		 * @var string
		 */
		public const GENERATION_OPTION = 'slosm_geocode_generation';

		/**
		 * The cached shape's version, carried in the key.
		 *
		 * Bump it when the array a lookup returns changes shape. Without it, an
		 * update that adds a key spends up to thirty days serving entries
		 * written by the previous version.
		 *
		 * @var int
		 */
		public const CACHE_VERSION = 1;

		/**
		 * How long a geocoded address is kept.
		 *
		 * Thirty days because addresses are the most stable thing this plugin
		 * handles: a street does not move, and when a location's address is
		 * edited the admin re-geocodes it under a different key anyway — the key
		 * is a hash of the query, so a changed address is a cache miss by
		 * construction and never a stale hit.
		 *
		 * @var int
		 */
		public const CACHE_TTL = 30 * DAY_IN_SECONDS;

		/**
		 * How long a suggestion list is kept.
		 *
		 * A day rather than thirty, and the difference is about cardinality
		 * rather than freshness. A geocode query is a whole address an editor
		 * saved: a site has as many of them as it has locations. A suggestion
		 * query is every prefix every visitor types, which is unbounded — on a
		 * site with no persistent object cache each one is a row in wp_options,
		 * and WordPress only sweeps expired transients on a daily cron. Thirty
		 * days of that is a table nobody asked for; a day keeps the benefit that
		 * matters, which is the same prefix typed again minutes later.
		 *
		 * @var int
		 */
		public const SUGGEST_CACHE_TTL = DAY_IN_SECONDS;

		/**
		 * The shortest gap allowed between two requests to one service, in seconds.
		 *
		 * Nominatim's usage policy states an absolute maximum of one request per
		 * second for the public instance. This is that number, not a fraction of
		 * it: a site configured against its own instance can raise its own
		 * limits by pointing at it, and slowing everyone else down by default
		 * buys nothing.
		 *
		 * @var float
		 */
		public const MIN_INTERVAL = 1.0;

		/**
		 * How long a request may take before it is abandoned, in seconds.
		 *
		 * Five, which is what WP_Http applies when nothing says otherwise —
		 * verified in wp-includes/class-wp-http.php of WordPress 6.9.1, where
		 * the default is apply_filters( 'http_request_timeout', 5, $url ). It is
		 * written out rather than inherited so that the number is visible, and
		 * so that a core change cannot move it under this plugin.
		 *
		 * Five is already a long time to block a front-end search on a third
		 * party, and the worst case is longer still: the rate limiter may sleep
		 * up to a second first, so a visitor can wait six. It is not lowered
		 * further because a geocode that times out is not a slow answer, it is
		 * no answer — the visitor gets an error and the address stays
		 * unresolved — and a public Nominatim instance under load genuinely
		 * takes seconds. The setting that would actually protect a busy site is
		 * its own instance, which is why the endpoint is configurable.
		 *
		 * Not a setting and not filterable. Task 21 owns the settings surface
		 * and deliberately left this off it: "how many seconds may a lookup
		 * block for" is a question a site owner has no way to answer, and the
		 * setting that actually protects a busy site — its own endpoint — is on
		 * the Advanced tab. A site that needs the number itself has
		 * 'http_request_timeout' in core.
		 *
		 * @var int
		 */
		public const HTTP_TIMEOUT = 5;

		/**
		 * How many suggestions to ask Photon for.
		 *
		 * A dropdown a person reads, not a data set. Five fits under a search
		 * field without scrolling.
		 *
		 * @var int
		 */
		public const SUGGEST_LIMIT = 5;

		/**
		 * The option each service's last-request timestamp lives under.
		 *
		 * The service name is appended; see last_request_option().
		 *
		 * @var string
		 */
		public const LAST_REQUEST_PREFIX = 'slosm_geocode_last_request_';

		/**
		 * The only protocols an endpoint may use.
		 *
		 * Passed to esc_url_raw() on every call, because its default is
		 * wp_allowed_protocols() and that list has twenty-two entries; see
		 * endpoint() for what an accepted ftp:// endpoint does to a site.
		 *
		 * @var string[]
		 */
		public const ENDPOINT_PROTOCOLS = array( 'http', 'https' );

		/**
		 * Where each service is asked, when nothing is configured.
		 *
		 * Both are the public instances, and both will have to be disclosed as
		 * external services in readme.txt, with their limits — WordPress.org
		 * requires it, and a site owner has to hear "this is a volunteer-run
		 * service with a hard limit" before an IP block rather than after one.
		 * Future tense on purpose: there is no readme.txt in this repository
		 * yet, and Task 25 is where it arrives.
		 *
		 * @var array<string, string>
		 */
		public const DEFAULT_ENDPOINTS = array(
			self::SERVICE_NOMINATIM => 'https://nominatim.openstreetmap.org/search',
			self::SERVICE_PHOTON    => 'https://photon.komoot.io/api',
		);

		/**
		 * Returns the current time as a float number of seconds.
		 *
		 * @var callable
		 */
		private $clock;

		/**
		 * Blocks for a number of seconds. Seconds, not microseconds.
		 *
		 * @var callable
		 */
		private $sleeper;

		/**
		 * When this instance last asked each service, keyed by service.
		 *
		 * A second line of defence behind the option, and it exists for one
		 * concrete failure: a read-only replica, a full disk or a crashed
		 * options table makes update_option() return false and store nothing, so
		 * every request in a loop would read a stale timestamp and fire
		 * immediately. That is the exact moment a bulk geocode run would hammer
		 * a public service and earn the block the limiter exists to avoid. The
		 * option is still the real limiter — it is the only part two php
		 * processes share — and this only narrows the damage to one process.
		 *
		 * @var array<string, float>
		 */
		private array $last_request = array();

		/**
		 * Builds a geocoder over a clock and a sleeper.
		 *
		 * The defaults are the production ones. usleep() takes microseconds and
		 * the seam is defined in seconds, so the default wraps the conversion
		 * rather than pushing it onto every caller: a sleeper that took
		 * microseconds would make the recorded value in a test a number nobody
		 * can read, and a unit mix-up here is a thousand-fold error in how long
		 * a front-end request blocks.
		 *
		 * microtime( true ) rather than time(), because the interval is
		 * sub-second and time() would round every gap to a whole second — which
		 * would make the limiter alternately too strict and too lax, by up to
		 * the whole interval.
		 *
		 * @param callable|null $clock   Returns the current time in seconds; defaults to microtime( true ).
		 * @param callable|null $sleeper Blocks for a number of seconds; defaults to usleep().
		 */
		public function __construct( ?callable $clock = null, ?callable $sleeper = null ) {
			$this->clock = null !== $clock ? $clock : static function (): float {
				return microtime( true );
			};

			$this->sleeper = null !== $sleeper ? $sleeper : static function ( float $seconds ): void {
				if ( 0.0 < $seconds ) {
					usleep( (int) round( $seconds * 1000000 ) );
				}
			};
		}

		/**
		 * The coordinates of an address.
		 *
		 * @param string $query   Address, as a person typed or saved it.
		 * @param string $country ISO 3166-1 alpha-2 code, or several comma separated; empty for no restriction.
		 * @return array|\WP_Error array( 'lat' => float, 'lng' => float, 'label' => string ), or why not.
		 */
		public function geocode( string $query, string $country = '' ) {
			$display    = $this->collapse( $query );
			$normalised = $this->normalise( $query );
			$country    = $this->normalise_country( $country );

			if ( '' === $normalised ) {
				return new \WP_Error(
					'slosm_geocode_empty_query',
					__( 'There is no address to look up.', 'store-locator-for-openstreetmap' )
				);
			}

			$key    = $this->build_cache_key( self::SERVICE_NOMINATIM, $normalised, $country );
			$cached = get_transient( $key );

			if ( $this->is_point( $cached ) ) {
				return $cached;
			}

			$params = array(
				'format' => 'jsonv2',
				'limit'  => 1,
				'q'      => $normalised,
			);

			if ( '' !== $country ) {
				$params['countrycodes'] = $country;
			}

			$decoded = $this->request( self::SERVICE_NOMINATIM, $params, 'slosm_geocode' );

			if ( is_wp_error( $decoded ) ) {
				return $decoded;
			}

			if ( array() === $decoded ) {
				return new \WP_Error(
					'slosm_geocode_no_results',
					__( 'No place matched that address.', 'store-locator-for-openstreetmap' )
				);
			}

			// Nominatim answers a rejected request with an object carrying an
			// 'error' key rather than with a list, and a custom endpoint can
			// answer with anything at all. array_is_list() would say this in one
			// line and arrived in PHP 8.1, one minor above this plugin's floor.
			if ( ! isset( $decoded[0] ) || ! is_array( $decoded[0] ) ) {
				return new \WP_Error(
					'slosm_geocode_bad_json',
					__( 'The geocoding service sent something this plugin could not read.', 'store-locator-for-openstreetmap' )
				);
			}

			$first = $decoded[0];

			// 'lon', not 'lng'. Every other coordinate in this plugin is 'lng',
			// which is exactly why this is worth a line of its own: reading the
			// key this codebase uses everywhere else gets null from a perfectly
			// good response, and the lookup reports "no coordinates" forever.
			$lat = $this->coordinate( $first['lat'] ?? null, 90.0 );
			$lng = $this->coordinate( $first['lon'] ?? null, 180.0 );

			if ( null === $lat || null === $lng ) {
				return new \WP_Error(
					'slosm_geocode_bad_coordinates',
					__( 'The geocoding service answered without usable coordinates.', 'store-locator-for-openstreetmap' )
				);
			}

			$label = isset( $first['display_name'] ) && is_string( $first['display_name'] ) && '' !== trim( $first['display_name'] )
				? trim( $first['display_name'] )
				// The collapsed query, not the normalised one: this string is
				// shown to a person, so it keeps the capitals they typed.
				: $display;

			$point = array(
				'lat'   => $lat,
				'lng'   => $lng,
				'label' => $label,
			);

			$this->cache( self::SERVICE_NOMINATIM, $key, $point );

			return $point;
		}

		/**
		 * Address suggestions for a partial query.
		 *
		 * Photon rather than Nominatim, and on engineering grounds first:
		 * autocomplete is one request per keystroke against a partial string,
		 * which is both the heaviest pattern a search index can be given and the
		 * one a geocoder's index is least built for. Photon is built on the same
		 * OpenStreetMap data for exactly this query shape. Nominatim's usage
		 * policy also rules the public instance out for this, subject to the
		 * caveat at the top of the class about every mention of that policy.
		 *
		 * It lives in this class rather than beside it because it shares three
		 * things with geocode() that are the whole substance of both: the
		 * normalisation, the rate limiter and the rule that a failure is never
		 * written down. A separate class would either duplicate all three or
		 * need a shared parent before there was a second reason for one.
		 *
		 * @param string $query   Whatever a visitor has typed so far.
		 * @param string $country ISO 3166-1 alpha-2 code; see below, Photon has no parameter for it.
		 * @return array|\WP_Error A list of array( 'label' => string, 'lat' => float, 'lng' => float ), or why not.
		 */
		public function suggest( string $query, string $country = '' ) {
			$normalised = $this->normalise( $query );
			$country    = $this->normalise_country( $country );

			if ( '' === $normalised ) {
				return new \WP_Error(
					'slosm_suggest_empty_query',
					__( 'There is nothing to suggest for an empty search.', 'store-locator-for-openstreetmap' )
				);
			}

			$key    = $this->build_cache_key( self::SERVICE_PHOTON, $normalised, $country );
			$cached = get_transient( $key );

			if ( $this->is_suggestion_list( $cached ) ) {
				return $cached;
			}

			/*
			 * The country is in the key and not in the request, because Photon
			 * has no country parameter — its filters are a bounding box and a
			 * location bias, not a code. So two countries mean two identical
			 * requests and one of them is waste.
			 *
			 * That waste is accepted deliberately. The alternative, leaving the
			 * country out of the key, is a cache that becomes wrong the day
			 * somebody adds a bbox bias here: it would serve one country's
			 * suggestions under the other country's lookup, silently, and the
			 * test that was supposed to catch it would still pass because the
			 * second lookup would be a cache hit. A redundant request is
			 * recoverable; a cache keyed on less than the call is not.
			 */
			$params = array(
				'q'     => $normalised,
				'limit' => self::SUGGEST_LIMIT,
			);

			$decoded = $this->request( self::SERVICE_PHOTON, $params, 'slosm_suggest' );

			if ( is_wp_error( $decoded ) ) {
				return $decoded;
			}

			if ( ! isset( $decoded['features'] ) || ! is_array( $decoded['features'] ) ) {
				return new \WP_Error(
					'slosm_suggest_bad_json',
					__( 'The suggestion service sent something this plugin could not read.', 'store-locator-for-openstreetmap' )
				);
			}

			$suggestions = array();

			foreach ( $decoded['features'] as $feature ) {
				$suggestion = $this->to_suggestion( $feature );

				if ( null !== $suggestion ) {
					$suggestions[] = $suggestion;
				}
			}

			if ( array() === $suggestions ) {
				return new \WP_Error(
					'slosm_suggest_no_results',
					__( 'Nothing matched that search.', 'store-locator-for-openstreetmap' )
				);
			}

			$this->cache( self::SERVICE_PHOTON, $key, $suggestions );

			return $suggestions;
		}

		/**
		 * The transient key a lookup lives under.
		 *
		 * Public for the same two reasons Store_Repository::cache_key() is: the
		 * uninstaller and the "clear cache" button have to recognise these keys,
		 * and a test that computed the key itself would agree with any mistake
		 * this method made — including a normalisation bug, since the key is
		 * where normalisation becomes observable.
		 *
		 * The query is hashed rather than carried. wp_options.option_name is
		 * varchar(191) — wp-admin/includes/schema.php in WordPress 6.9.1 — and a
		 * transient adds a 19-character '_transient_timeout_' prefix, so an
		 * address of any real length would be truncated, and two long addresses
		 * on the same street would then collide into one entry.
		 *
		 * @param string $service Service the lookup goes to.
		 * @param string $query   Raw query; normalised here, so callers need not.
		 * @param string $country Country code, normalised here too.
		 * @return string
		 */
		public function cache_key( string $service, string $query, string $country = '' ): string {
			return $this->build_cache_key(
				sanitize_key( $service ),
				$this->normalise( $query ),
				$this->normalise_country( $country )
			);
		}

		/**
		 * The option a service's last-request timestamp lives in.
		 *
		 * An option rather than a property, because the limit is a limit on the
		 * site, not on an object. Two visitors searching at the same moment are
		 * two php processes with no memory in common; a limiter held in an
		 * instance would let each of them fire immediately and the site would
		 * exceed one request a second without any single page doing anything
		 * wrong. The option is the only thing they share.
		 *
		 * It is written with autoload off. Every request on the site would
		 * otherwise carry this value in alloptions to serve the handful that
		 * geocode.
		 *
		 * One service per option rather than one for both. They are different
		 * hosts under different policies, and a shared timestamp would make an
		 * admin's bulk geocode run add a second to every visitor's autocomplete
		 * — throttling the search box to protect a limit that is not Photon's.
		 *
		 * What this is not is a lock, and it loses a race in two distinct ways.
		 * The first is the obvious one: two processes can read the same
		 * timestamp and both decide the gap has passed, because the options API
		 * offers no atomic compare-and-set. The second is worse, because it
		 * defeats a process that did everything right — throttle() re-reads the
		 * clock after sleeping but not the option, so a process that waited out
		 * another's second wakes and fires without checking whether a third one
		 * went in the meantime. Re-reading after the wait would narrow it and
		 * not close it, since the same race exists between that read and the
		 * request.
		 *
		 * So this is a courtesy limiter that keeps a site an order of magnitude
		 * inside the policy, not a guarantee that no two requests ever overlap.
		 * A guarantee would need a database lock held across every geocode,
		 * which costs more than it protects.
		 *
		 * Public so a test can name the option rather than reconstruct it, and
		 * so the uninstaller can delete it.
		 *
		 * @param string $service Service name.
		 * @return string
		 */
		public function last_request_option( string $service ): string {
			return self::LAST_REQUEST_PREFIX . sanitize_key( $service );
		}

		/**
		 * Asks a service, once the limiter allows it, and decodes what came back.
		 *
		 * Every failure gets its own code, prefixed per method, because the
		 * caller's sensible response differs for each: a 429 means wait, a
		 * transport error means the site cannot reach the internet, malformed
		 * json means the endpoint is not what it claims to be, and an empty
		 * result means the address is wrong. Collapsing them into one error is
		 * how a dns failure ends up on a visitor's screen as "no matches".
		 *
		 * @param string $service     Service to ask.
		 * @param array  $params      Query parameters, unencoded.
		 * @param string $code_prefix Prefix for the error codes this call returns.
		 * @return array|\WP_Error The decoded body, or why not.
		 */
		private function request( string $service, array $params, string $code_prefix ) {
			$url = $this->build_url( $this->endpoint( $service ), $params );

			$this->throttle( $service );

			$response = wp_remote_get( $url, $this->request_args( $service ) );

			/*
			 * First, and not as a formality. wp_remote_retrieve_body() on a
			 * WP_Error returns '' — it is a plain array read with a default —
			 * so a path that skipped this check would hand '' to json_decode(),
			 * get null, and report "no matches" for what was a dns failure, a
			 * refused connection or a timeout. The site would look like it had
			 * been asked about a place that does not exist.
			 */
			if ( is_wp_error( $response ) ) {
				return new \WP_Error(
					$code_prefix . '_http',
					__( 'Could not reach the geocoding service.', 'store-locator-for-openstreetmap' ),
					array(
						'upstream_code'    => $response->get_error_code(),
						'upstream_message' => $response->get_error_message(),
					)
				);
			}

			$status = (int) wp_remote_retrieve_response_code( $response );

			if ( 429 === $status ) {
				return new \WP_Error(
					$code_prefix . '_rate_limited',
					__( 'The geocoding service is asking for fewer requests. Try again in a moment.', 'store-locator-for-openstreetmap' ),
					array( 'upstream_status' => $status )
				);
			}

			if ( 200 !== $status ) {
				return new \WP_Error(
					$code_prefix . '_bad_status',
					__( 'The geocoding service answered with an error.', 'store-locator-for-openstreetmap' ),
					array( 'upstream_status' => $status )
				);
			}

			$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );

			// is_array(), not a json_last_error() check. A body of
			// '"try again later"' is valid json and decodes to a string, so
			// "did the decode fail" would let it through to code expecting rows.
			if ( ! is_array( $decoded ) ) {
				return new \WP_Error(
					$code_prefix . '_bad_json',
					__( 'The geocoding service sent something this plugin could not read.', 'store-locator-for-openstreetmap' ),
					array( 'upstream_status' => $status )
				);
			}

			return $decoded;
		}

		/**
		 * Whether asking this service now would make the limiter wait.
		 *
		 * A pure query. It makes no request, writes nothing and never sleeps; it
		 * answers the one question throttle() is about to answer for itself, off
		 * the same reading of the same state.
		 *
		 * It exists because a caller can reasonably prefer no answer to a slow
		 * one, and only the caller knows that. The REST controller's /suggest
		 * route is the case: a suggestion miss under contention would hold a
		 * PHP-FPM worker in usleep() for up to a second, and ten visitors typing
		 * at once is ten held workers — so it asks this first and answers with
		 * nothing instead. A bulk geocode run asks nothing of the sort and waits,
		 * which is right for it.
		 *
		 * This is not a non-blocking mode and must not become one. Nothing here
		 * lets a caller make a request without waiting its turn: the only thing
		 * on offer is the truth about whether a turn is due. A sleeper that
		 * returned immediately would break the courtesy limit rather than respect
		 * it, which is the opposite of what this class is for.
		 *
		 * It shares last_request_at() with throttle() rather than re-deriving the
		 * timestamp, and that is the whole point of it living here. An earlier
		 * version of this predicate lived in the controller and had already
		 * drifted twice: it read only the option, missing the per-instance
		 * fallback that exists for when update_option() fails, and it measured
		 * against a second clock seam with nothing pinning the two together. Two
		 * implementations of one subtraction is how a limiter quietly stops
		 * limiting.
		 *
		 * A timestamp in the future reads as blocking, because that is what
		 * throttle() does with one: it clamps the wait to a whole interval rather
		 * than firing immediately.
		 *
		 * @param string $service Service that would be asked.
		 * @return bool True when throttle() would sleep before the next request.
		 */
		public function would_throttle( string $service ): bool {
			$last = $this->last_request_at( $service );

			if ( 0.0 >= $last ) {
				return false;
			}

			return ( (float) call_user_func( $this->clock ) ) - $last < self::MIN_INTERVAL;
		}

		/**
		 * When this site last asked a service, as far as anything here can tell.
		 *
		 * The option first, because it is the only part two php processes share.
		 * Then the per-instance timestamp when it is newer, which is the fallback
		 * $last_request exists for: a read-only replica or a full disk makes
		 * update_option() store nothing, and without this a loop would read a
		 * stale option every time and fire on every iteration.
		 *
		 * Zero means "never asked", and a stored value that is not a number means
		 * the same thing — an importer or another plugin on the same option name
		 * leaves a string, and (float) 'yesterday' is 0.0 anyway. is_numeric() is
		 * what tells that apart from a real zero rather than guessing.
		 *
		 * One reading, used by throttle() and by would_throttle(), so the
		 * decision to wait and the prediction that there will be a wait cannot
		 * come apart.
		 *
		 * @param string $service Service name.
		 * @return float Seconds, or 0.0 when nothing usable is recorded.
		 */
		private function last_request_at( string $service ): float {
			$stored = get_option( $this->last_request_option( $service ), 0 );
			$last   = is_numeric( $stored ) ? (float) $stored : 0.0;

			if ( isset( $this->last_request[ $service ] ) && $this->last_request[ $service ] > $last ) {
				$last = $this->last_request[ $service ];
			}

			return $last;
		}

		/**
		 * Waits, if the last request to this service was less than a second ago.
		 *
		 * Called only on the path that actually makes a request. A cache hit
		 * neither waits nor moves the timestamp, which matters more than it
		 * sounds: a bulk run over five hundred locations that were already
		 * geocoded would otherwise take eight minutes to do nothing.
		 *
		 * Three things stored time can be that a naive subtraction gets wrong.
		 * Two of them are answered by last_request_at(), which reads the state —
		 * a value that is not a number, and a value left stale by a failed write
		 * — and this method deals with the third:
		 *
		 * - In the future. A restored database, a clock that jumped, a
		 *   multi-server setup with drifting time. The remainder of a second is
		 *   then one second *plus* the drift, so an unclamped wait of a billion
		 *   seconds is a request that never returns. The wait is clamped to the
		 *   interval, and the timestamp is then overwritten with the real now,
		 *   which is what repairs it. would_throttle() reports the same value as
		 *   blocking, so a caller that would rather skip than wait gets the
		 *   answer this method would have acted on.
		 *
		 * A VISITOR WHO WENT AWAY IS STILL WAITED FOR, AND THAT IS NOT FIXABLE HERE
		 * ========================================================================
		 * Asked and answered on 2026-09-20, so that it is not asked again. When a
		 * browser abandons a search — assets/js/locator.js aborts a superseded one
		 * through instance.searchController — this method still sleeps out the
		 * interval, still writes the timestamp, and the upstream call is still
		 * made, so the next visitor queues behind a request nobody will read.
		 *
		 * The obvious guard, checking connection_aborted() before sleeping rather
		 * than after, cannot be written: PHP does not notice a client that has
		 * gone away until it next tries to *write*, and measurement says so — over
		 * ten 200 ms steps with a client that left after 400 ms,
		 * connection_aborted() was 0 every time for a script that wrote nothing,
		 * and the one that wrote a byte per step was killed at step 1. A REST
		 * callback that writes before its json body has corrupted its own
		 * response, so the check would read 0 on every request that needed it.
		 *
		 * The plan's Task 28 section has the table, the script and the three
		 * alternatives that were each considered and are each worse.
		 *
		 * @param string $service Service about to be asked.
		 * @return void
		 */
		private function throttle( string $service ): void {
			$option = $this->last_request_option( $service );
			$last   = $this->last_request_at( $service );
			$now    = (float) call_user_func( $this->clock );

			if ( 0.0 < $last ) {
				$elapsed = $now - $last;

				if ( $elapsed < self::MIN_INTERVAL ) {
					$wait = self::MIN_INTERVAL - $elapsed;

					if ( $wait > self::MIN_INTERVAL ) {
						$wait = self::MIN_INTERVAL;
					}

					call_user_func( $this->sleeper, $wait );

					$now = (float) call_user_func( $this->clock );
				}
			}

			$this->last_request[ $service ] = $now;

			/*
			 * update_option()'s benign false — the stored value already equals
			 * the new one — cannot happen here: a write only follows either a
			 * wait or an elapsed interval, so $now has always moved. Any false
			 * is a real failure, and it is worth reporting rather than
			 * swallowing, because a limiter that cannot remember is a limiter
			 * that only works inside one process.
			 */
			if ( false === update_option( $option, $now, false ) ) {
				/**
				 * Fires when the rate limiter could not record a request.
				 *
				 * The limit then holds only within each php process, so several
				 * concurrent requests can exceed it. The usual cause is a
				 * read-only database or a full disk.
				 *
				 * @param string $option    Option that could not be written.
				 * @param float  $timestamp Timestamp the write was attempting.
				 */
				do_action( 'slosm_geocoder_throttle_write_failed', $option, $now );
			}
		}

		/**
		 * Writes a successful lookup to the cache, and says so when it cannot.
		 *
		 * Only successes reach this method; see the class docblock for why that
		 * is the most important rule here.
		 *
		 * A lifetime of zero or less means "do not write", not "write forever".
		 * WordPress reads a zero expiry as no expiry, so forwarding a configured
		 * zero would turn the setting a site used to switch the cache off into a
		 * permanent one — the exact opposite, and unnoticeable for as long as
		 * the answers stay right. A negative value is worse still: set_transient()
		 * treats it as already expired, so the site would pay for a write on
		 * every lookup and never read one back.
		 *
		 * @param string $service Service the value came from.
		 * @param string $key     Transient key.
		 * @param mixed  $value   Value to cache.
		 * @return void
		 */
		private function cache( string $service, string $key, $value ): void {
			$ttl = $this->cache_ttl( $service );

			if ( 0 >= $ttl ) {
				return;
			}

			if ( false === set_transient( $key, $value, $ttl ) ) {
				/**
				 * Fires when a lookup could not be written to the cache.
				 *
				 * Every repeat of that lookup then goes back to the network,
				 * which on a busy site is how a rate limit is reached honestly.
				 *
				 * @param string $key     Transient key that could not be written.
				 * @param string $service Service the value came from.
				 */
				do_action( 'slosm_geocoder_cache_write_failed', $key, $service );
			}
		}

		/**
		 * The key, from parts that are already normalised.
		 *
		 * @param string $service    Sanitised service name.
		 * @param string $normalised Normalised query.
		 * @param string $country    Normalised country code.
		 * @return string
		 */
		private function build_cache_key( string $service, string $normalised, string $country ): string {
			return self::CACHE_PREFIX
				. '_' . $service
				. '_v' . self::CACHE_VERSION
				. '_g' . self::generation()
				. '_' . md5( $normalised . '|' . $country );
		}

		/**
		 * The generation every key currently carries.
		 *
		 * absint() for the three values get_option() really returns here: false
		 * on a site that has never flushed, and an empty string or a word from
		 * an importer or another plugin that took the same option name.
		 * Concatenated raw, all three collapse the segment to nothing and give a
		 * key two different generations would share — an invalidation that
		 * quietly stops working.
		 *
		 * Public and static since Task 21, and the widening is the point rather
		 * than a convenience — the same argument coordinate_string() makes on
		 * the other side of the plugin. Admin::failure_key() carries this number
		 * so that "clear the caches" reaches the addresses a lookup has already
		 * failed on as well as the ones it answered; a second generation counter
		 * over there would be a second thing the button has to remember to move,
		 * and the one it forgot would be the one that keeps telling an editor an
		 * address cannot be found. Static because it reads an option and touches
		 * no instance state, so a caller with no geocoder need not build one to
		 * ask.
		 *
		 * @return int
		 */
		public static function generation(): int {
			return absint( get_option( self::GENERATION_OPTION, 0 ) );
		}

		/**
		 * Makes every cached lookup on the site unreachable.
		 *
		 * For Task 21's "clear cache" button and for an uninstall. Nothing calls
		 * it automatically, and nothing should: a geocode cache has no
		 * equivalent of a post save to hang off, because the thing that would
		 * invalidate an entry — a street being renumbered — happens at the far
		 * end and this side cannot see it. That is what the thirty-day lifetime
		 * is for, and this is for the day an editor knows better.
		 *
		 * There is no once-per-request guard, which Store_Repository::flush_cache()
		 * has and needs. That one is called from several hooks that all fire on
		 * one save; this one is called by a person pressing a button.
		 *
		 * The write is checked rather than ignored for the same reason it is
		 * there: a failed flush leaves every entry on the site reachable and
		 * correct-looking, and the button reports success.
		 *
		 * @return void
		 */
		public function flush_cache(): void {
			$generation = self::generation() + 1;

			if ( false === update_option( self::GENERATION_OPTION, $generation, false ) ) {
				/**
				 * Fires when the geocode cache generation could not be moved.
				 *
				 * Every cached lookup stays reachable until it expires on its
				 * own ttl, which is up to thirty days.
				 *
				 * @param string $option     Option that could not be written.
				 * @param int    $generation Generation the write was attempting.
				 */
				do_action( 'slosm_geocoder_flush_failed', self::GENERATION_OPTION, $generation );
			}
		}

		/**
		 * The url for a request, with every parameter encoded.
		 *
		 * rawurlencode() per parameter rather than http_build_query(), for two
		 * reasons that both show up on real addresses: an ampersand in a street
		 * name ends the q parameter and turns the rest of the address into a
		 * parameter of its own, and rawurlencode() spells a space '%20' rather
		 * than '+', which is what a test can assert on without knowing which of
		 * the two a service happens to accept.
		 *
		 * The separator is chosen rather than assumed, because a configured
		 * endpoint often carries a query string already — a paid provider's url
		 * with an api key in it. Appending '?' to that url makes a request that
		 * fails in a way nobody would guess from the error.
		 *
		 * @param string $endpoint Endpoint url.
		 * @param array  $params   Parameters, unencoded.
		 * @return string
		 */
		private function build_url( string $endpoint, array $params ): string {
			$pairs = array();

			foreach ( $params as $name => $value ) {
				$pairs[] = rawurlencode( (string) $name ) . '=' . rawurlencode( (string) $value );
			}

			if ( array() === $pairs ) {
				return $endpoint;
			}

			$separator = false === strpos( $endpoint, '?' ) ? '?' : '&';

			return $endpoint . $separator . implode( '&', $pairs );
		}

		/**
		 * The arguments every request goes out with.
		 *
		 * The agent is set twice, and the reason first given for that here was
		 * wrong. It said that setting one and not the other would leave the
		 * outcome depending on the host's transport, citing
		 * Requests/src/Transport/Fsockopen.php and Curl.php — and those two
		 * files say the opposite: Fsockopen emits $options['useragent'] exactly
		 * when no User-Agent header is present, and Curl sets CURLOPT_USERAGENT
		 * from the same value, so the 'user-agent' argument alone reaches the
		 * wire on both. The citations were accurate and the conclusion did not
		 * follow from them.
		 *
		 * Both are still set, for two reasons that are smaller and true. It
		 * costs nothing; and it does not depend on WP_Http continuing to forward
		 * 'user-agent' into the transport layer, which is an internal detail of
		 * core rather than a documented contract. The header is also where a
		 * reader looks to see what this plugin sends, which is worth something
		 * on the one value Nominatim's policy asks for by name.
		 *
		 * wp_remote_get() rather than wp_safe_remote_get(), which is the
		 * opposite of the usual advice and is deliberate. The safe variant
		 * passes the url through wp_http_validate_url(), which rejects private
		 * addresses and every port outside 80, 443 and 8080 — so the very case
		 * the configurable endpoint exists for, a client's own Nominatim on an
		 * internal host or a non-standard port, is the case it blocks. The url
		 * here is not user input: it is a site setting, filtered through
		 * esc_url_raw() to http or https, never anything a visitor supplies.
		 *
		 * @param string $service Service being asked.
		 * @return array
		 */
		private function request_args( string $service ): array {
			$agent = $this->user_agent( $service );

			return array(
				'timeout'    => self::HTTP_TIMEOUT,
				'user-agent' => $agent,
				'headers'    => array(
					'User-Agent' => $agent,
					'Accept'     => 'application/json',
				),
			);
		}

		/**
		 * Where a service is asked.
		 *
		 * Settings first, then a filter over the top, and esc_url_raw() over
		 * both: the setting comes from a text field and the filter from another
		 * plugin, and a value that is not http or https is not an endpoint at
		 * all. Either one falling through leaves the value before it rather than
		 * an empty url, so a bad filter cannot silently discard a good setting.
		 *
		 * The protocol list is passed explicitly, and leaving it out was a real
		 * bug rather than a tidying opportunity. esc_url_raw( $url ) is
		 * esc_url( $url, null, 'db' ), and a null list means
		 * wp_allowed_protocols() — twenty-two protocols, among them ftp, telnet
		 * and svn. So a bare call accepts 'ftp://geo.example.test/search'
		 * unchanged, the fallback below never fires, and Requests then refuses
		 * it with "Only HTTP(S) requests are handled": a site whose geocoding is
		 * permanently broken by a setting that looked accepted. Checked in
		 * wp-includes/formatting.php and wp-includes/functions.php of
		 * WordPress 6.9.1.
		 *
		 * This is also the compensating control that the wp_remote_get() choice
		 * in request_args() rests on, which is why it is spelled out in both
		 * places.
		 *
		 * @param string $service Service name.
		 * @return string
		 */
		private function endpoint( string $service ): string {
			$default = self::DEFAULT_ENDPOINTS[ $service ] ?? self::DEFAULT_ENDPOINTS[ self::SERVICE_NOMINATIM ];
			$setting = self::SERVICE_PHOTON === $service ? 'suggest_endpoint' : 'geocode_endpoint';

			$configured = $this->setting( $setting );
			$url        = is_string( $configured ) ? esc_url_raw( trim( $configured ), self::ENDPOINT_PROTOCOLS ) : '';

			if ( '' === $url ) {
				$url = $default;
			}

			/**
			 * Filters the endpoint a geocoding request goes to.
			 *
			 * For pointing a site at its own Nominatim or Photon instance, or at
			 * a paid provider that speaks the same shape.
			 *
			 * @param string $url     Endpoint url.
			 * @param string $service Either 'nominatim' or 'photon'.
			 */
			$filtered = apply_filters( 'slosm_geocoder_endpoint', $url, $service );
			$filtered = is_string( $filtered ) ? esc_url_raw( trim( $filtered ), self::ENDPOINT_PROTOCOLS ) : '';

			return '' === $filtered ? $url : $filtered;
		}

		/**
		 * The User-Agent every request identifies the site with.
		 *
		 * Nominatim's usage policy asks for an agent that identifies the
		 * application and gives a way to get in touch, which is what the site's
		 * own url is for. An empty one is the single value that must not
		 * survive, whatever a setting or a filter says, so both fall back rather
		 * than being honoured.
		 *
		 * Carriage returns and newlines are stripped. A header value is a line,
		 * and a value with a line break in it is a second header — this one
		 * comes from a settings field, so it is a field an admin can paste
		 * anything into.
		 *
		 * @param string $service Service being asked.
		 * @return string
		 */
		private function user_agent( string $service ): string {
			$configured = $this->setting( 'geocode_user_agent' );
			$agent      = is_string( $configured ) && '' !== trim( $configured ) ? $configured : $this->default_user_agent();

			/**
			 * Filters the User-Agent geocoding requests identify the site with.
			 *
			 * An empty string is ignored: Nominatim's policy is about being
			 * identifiable, and a site that sends nothing is the request that
			 * gets blocked.
			 *
			 * @param string $agent   User-Agent string.
			 * @param string $service Either 'nominatim' or 'photon'.
			 */
			$filtered = apply_filters( 'slosm_geocoder_user_agent', $agent, $service );

			if ( is_string( $filtered ) && '' !== trim( $filtered ) ) {
				$agent = $filtered;
			}

			return trim( (string) preg_replace( '/[\r\n]+/', ' ', $agent ) );
		}

		/**
		 * The agent a site that has configured nothing sends.
		 *
		 * SLOSM_VERSION comes from the main plugin file. The unit suite does not
		 * load that file — tests/bootstrap.php defines the constant itself, so
		 * the suite does see a version — but a bare process that requires this
		 * class alone does not, and 'unknown' is what that costs. It is still a
		 * string that identifies the application, which is what the policy asks
		 * for. The site url is dropped rather than left as an empty '(+)' when
		 * home_url() has nothing to say.
		 *
		 * @return string
		 */
		private function default_user_agent(): string {
			$agent = 'Store Locator for OpenStreetMap/' . ( defined( 'SLOSM_VERSION' ) ? (string) SLOSM_VERSION : 'unknown' );
			$home  = function_exists( 'home_url' ) ? trim( (string) home_url() ) : '';

			return '' === $home ? $agent : $agent . ' (+' . $home . ')';
		}

		/**
		 * How long a service's answers are kept, in seconds.
		 *
		 * @param string $service Service name.
		 * @return int Zero or less means do not cache; see cache().
		 */
		private function cache_ttl( string $service ): int {
			$is_photon = self::SERVICE_PHOTON === $service;
			$default   = $is_photon ? self::SUGGEST_CACHE_TTL : self::CACHE_TTL;
			$setting   = $is_photon ? 'suggest_cache_ttl' : 'geocode_cache_ttl';

			$configured = $this->setting( $setting );
			$ttl        = is_numeric( $configured ) ? (int) $configured : $default;

			/**
			 * Filters how long a geocoding answer is cached, in seconds.
			 *
			 * Zero or less means nothing is cached at all.
			 *
			 * @param int    $ttl     Lifetime in seconds.
			 * @param string $service Either 'nominatim' or 'photon'.
			 */
			$filtered = apply_filters( 'slosm_geocoder_cache_ttl', $ttl, $service );

			return is_numeric( $filtered ) ? (int) $filtered : $ttl;
		}

		/**
		 * One value out of the settings option.
		 *
		 * Read on every call rather than held, so a filter or a setting changed
		 * mid-request takes effect, and so an instance built before the settings
		 * screen saved anything is not stuck with the defaults it was born with.
		 *
		 * @param string $key Setting name.
		 * @return mixed Null when the option, or the key, is not there.
		 */
		private function setting( string $key ) {
			$settings = get_option( self::SETTINGS_OPTION, array() );

			return is_array( $settings ) && array_key_exists( $key, $settings ) ? $settings[ $key ] : null;
		}

		/**
		 * The query, trimmed and with its internal whitespace collapsed.
		 *
		 * This is the form a person sees — the label a lookup falls back to when
		 * the service sends none — so it keeps its capitals.
		 *
		 * The /u modifier is what makes a non-breaking space collapse, and that
		 * is measured rather than assumed. An earlier version of this method
		 * listed \x{00A0} in the character class and this docblock explained
		 * that \s could not match it without PCRE2_UCP — which is true of PCRE
		 * and false of PHP: php_pcre.c sets PCRE2_UCP alongside PCRE2_UTF for
		 * every /u pattern, so \s matches U+00A0, U+2009 and the rest of the
		 * Unicode space category. Checked on both binaries this suite runs,
		 * PCRE2 10.40 and 10.44. The explicit class was therefore doing nothing,
		 * and a mutation that deleted it changed no behaviour at all. It matters
		 * because a non-breaking space is ordinary in a pasted address, and one
		 * left in place is a second cache entry for the same street.
		 *
		 * The /u pattern can fail outright: preg_replace() returns null when the
		 * subject is not valid utf-8, and a query typed on a mis-encoded form is
		 * exactly that. So there are two fallbacks and they do different jobs.
		 * The ascii pattern still collapses ordinary runs of spaces in a
		 * malformed string — what it cannot do is recognise the Unicode spaces,
		 * which is a lost cache hit rather than a wrong answer. The bare $query
		 * behind it is for the case where even that fails, and keeps a malformed
		 * query a query instead of turning it into null and looking up the empty
		 * string.
		 *
		 * @param string $query Raw query.
		 * @return string
		 */
		private function collapse( string $query ): string {
			$collapsed = preg_replace( '/\s+/u', ' ', $query );

			if ( null === $collapsed ) {
				$collapsed = preg_replace( '/\s+/', ' ', $query );
			}

			return trim( null === $collapsed ? $query : $collapsed );
		}

		/**
		 * The query in the one form the cache key and the wire both use.
		 *
		 * Lowercased as well as collapsed, so that '  Warszawa  ' and 'warszawa'
		 * are one cache entry rather than two. The same string is what goes on
		 * the wire, which makes the key a function of the request: two lookups
		 * that share an entry would have made byte-identical requests, so a
		 * cache hit can never stand in for a different question.
		 *
		 * mb_strtolower() only behind function_exists(). mbstring is not a
		 * guaranteed extension on a WordPress host, and the CLI binaries this
		 * suite runs on do not have it — an unguarded call there is a fatal
		 * error, not a notice. What the fallback costs is exactly one thing: a
		 * query whose non-ascii letters are uppercase folds differently, so
		 * 'ŁÓDŹ' and 'łódź' become two cache entries instead of one. A duplicate
		 * entry, never a wrong answer, and the alternative — folding non-ascii
		 * by hand — is a table of locale rules this plugin has no business
		 * carrying.
		 *
		 * @param string $query Raw query.
		 * @return string
		 */
		private function normalise( string $query ): string {
			$collapsed = $this->collapse( $query );

			return function_exists( 'mb_strtolower' ) ? mb_strtolower( $collapsed, 'UTF-8' ) : strtolower( $collapsed );
		}

		/**
		 * A country restriction, reduced to what Nominatim accepts.
		 *
		 * Lowercase letters and commas: countrycodes takes ISO 3166-1 alpha-2
		 * codes, comma separated. Everything else is dropped rather than
		 * rejected, because this value comes from a settings field and the worst
		 * a stripped character can do is widen the search.
		 *
		 * @param string $country Raw country value.
		 * @return string
		 */
		private function normalise_country( string $country ): string {
			return trim( strtolower( (string) preg_replace( '/[^A-Za-z,]/', '', $country ) ), ',' );
		}

		/**
		 * One coordinate, or null when the value is not one.
		 *
		 * The same rule Store::coordinate() follows, and for the same reason: a
		 * float cast answers "what number is this closest to", which for a
		 * non-number is 0.0 — a point in the Gulf of Guinea, not an absence. A
		 * location placed there looks placed.
		 *
		 * Nominatim sends its coordinates as strings, so the cast itself is
		 * necessary; what is not necessary is doing it blind.
		 *
		 * The range check is here and not in Store because this is the boundary
		 * with a third party whose endpoint a site can repoint at anything. A
		 * latitude of 991 is not a coordinate, and clamping it would invent a
		 * point at the pole rather than admit the answer was unusable.
		 *
		 * @param mixed $value Raw value from the service.
		 * @param float $limit Largest absolute value that is on the earth.
		 * @return float|null
		 */
		private function coordinate( $value, float $limit ): ?float {
			if ( is_string( $value ) ) {
				$value = trim( $value );
			}

			if ( ! is_numeric( $value ) ) {
				return null;
			}

			$number = (float) $value;

			return abs( $number ) > $limit ? null : $number;
		}

		/**
		 * Whether a cached value is still a point this class would return.
		 *
		 * Checked rather than trusted, because a transient outlives the code
		 * that wrote it: a plugin update that changes the shape, another plugin
		 * on the same key, a partially restored object cache. Returning whatever
		 * was under the key would push the problem into the caller, which
		 * expects three keys and would read null out of two of them.
		 *
		 * @param mixed $cached Value from the cache.
		 * @return bool
		 */
		private function is_point( $cached ): bool {
			return is_array( $cached )
				&& isset( $cached['lat'], $cached['lng'], $cached['label'] )
				&& is_float( $cached['lat'] )
				&& is_float( $cached['lng'] )
				&& is_string( $cached['label'] );
		}

		/**
		 * Whether a cached value is still a suggestion list.
		 *
		 * An empty array fails on purpose: nothing ever caches one, so an empty
		 * list under a key means something else wrote it.
		 *
		 * @param mixed $cached Value from the cache.
		 * @return bool
		 */
		private function is_suggestion_list( $cached ): bool {
			if ( ! is_array( $cached ) || array() === $cached ) {
				return false;
			}

			foreach ( $cached as $suggestion ) {
				if ( ! is_array( $suggestion )
					|| ! isset( $suggestion['label'], $suggestion['lat'], $suggestion['lng'] )
					|| ! is_string( $suggestion['label'] )
					|| ! is_float( $suggestion['lat'] )
					|| ! is_float( $suggestion['lng'] )
				) {
					return false;
				}
			}

			return true;
		}

		/**
		 * One Photon feature as a suggestion, or null when it cannot be shown.
		 *
		 * GeoJSON puts coordinates in [ longitude, latitude ] order, the reverse
		 * of how everything else in this plugin writes a point, and Photon sends
		 * them as json numbers rather than as the strings Nominatim sends.
		 * Reading them in the intuitive order puts Warsaw off the coast of
		 * Somalia — a plausible-looking point, on the map, in the water.
		 *
		 * A feature with no label is dropped rather than shown blank. A row in
		 * an autocomplete list that a person cannot read is a row they cannot
		 * choose, and it pushes the one they wanted below the fold.
		 *
		 * @param mixed $feature One element of the features array.
		 * @return array|null
		 */
		private function to_suggestion( $feature ): ?array {
			/*
			 * No case can make this guard matter, and saying so is better than
			 * implying otherwise: json_decode( $body, true ) produces only
			 * arrays, scalars and null, and the null-coalescing read below
			 * already yields null for every one of those — a string subscripted
			 * by 'geometry' under ?? is null, not a warning. A mutation that
			 * deletes this line changes no behaviour and no test. It stays
			 * because the line below it is one refactor away from reading
			 * $feature twice, at which point it would matter, and because the
			 * shape this method accepts should be stated where it is accepted.
			 */
			if ( ! is_array( $feature ) ) {
				return null;
			}

			$coordinates = $feature['geometry']['coordinates'] ?? null;

			/*
			 * The isset() half of this, by contrast, is load-bearing, and not
			 * for the result — a missing index reads as null and
			 * coordinate() refuses it either way. It is for the "Undefined
			 * array key" warning that reading $coordinates[1] on a one-element
			 * list would raise. On a site with WP_DEBUG_DISPLAY on, that
			 * warning is printed into the body of the json response this list
			 * ends up in, and the parse fails at the browser end with an error
			 * naming neither the warning nor this line.
			 */
			if ( ! is_array( $coordinates ) || ! isset( $coordinates[0], $coordinates[1] ) ) {
				return null;
			}

			$lng = $this->coordinate( $coordinates[0], 180.0 );
			$lat = $this->coordinate( $coordinates[1], 90.0 );

			if ( null === $lat || null === $lng ) {
				return null;
			}

			$label = $this->suggestion_label( is_array( $feature['properties'] ?? null ) ? $feature['properties'] : array() );

			if ( '' === $label ) {
				return null;
			}

			return array(
				'label' => $label,
				'lat'   => $lat,
				'lng'   => $lng,
			);
		}

		/**
		 * A one-line label out of Photon's properties.
		 *
		 * Photon has no display_name the way Nominatim does; it sends the parts
		 * and leaves the joining to the client. The order here is
		 * name, street and number, postcode, city, state, country, which reads
		 * the way an address is written across most of Europe — and is wrong for
		 * the United States and the United Kingdom, where the number comes
		 * first. That is a real limitation rather than an oversight: getting it
		 * right means a per-country address format table, and nothing has asked
		 * for one. There is no filter over this yet for the same reason.
		 *
		 * Parts that repeat are dropped. Photon routinely sends a city's own
		 * name as both 'name' and 'city', and "Warszawa, Warszawa, Polska" reads
		 * like a bug to the person looking at it.
		 *
		 * @param array $properties Photon properties.
		 * @return string Empty when there is nothing worth showing.
		 */
		private function suggestion_label( array $properties ): string {
			$street = $this->text( $properties, 'street' );
			$number = $this->text( $properties, 'housenumber' );

			if ( '' !== $street && '' !== $number ) {
				$street .= ' ' . $number;
			} elseif ( '' === $street ) {
				$street = $number;
			}

			$parts = array(
				$this->text( $properties, 'name' ),
				$street,
				$this->text( $properties, 'postcode' ),
				$this->text( $properties, 'city' ),
				$this->text( $properties, 'state' ),
				$this->text( $properties, 'country' ),
			);

			$kept = array();

			foreach ( $parts as $part ) {
				if ( '' !== $part && ! in_array( $part, $kept, true ) ) {
					$kept[] = $part;
				}
			}

			return implode( ', ', $kept );
		}

		/**
		 * One string out of a service's response.
		 *
		 * is_scalar() rather than a cast: a nested array or an object would
		 * become 'Array' or fatal, and neither belongs in a label.
		 *
		 * @param array  $data Raw values.
		 * @param string $key  Key to read.
		 * @return string
		 */
		private function text( array $data, string $key ): string {
			return isset( $data[ $key ] ) && is_scalar( $data[ $key ] ) ? trim( (string) $data[ $key ] ) : '';
		}
	}
}
