<?php
/**
 * One location, as a value.
 *
 * @package Store_Locator_For_OpenStreetMap
 */

namespace Asymetria\StoreLocator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( __NAMESPACE__ . '\\Store' ) ) {

	/**
	 * A single location: the value every other part of the plugin passes around.
	 *
	 * It knows three things and nothing else — how to build itself from an array
	 * of raw values, whether it can appear on a map, and how to present itself as
	 * an array. It deliberately does not know how to load itself, save itself,
	 * geocode itself or render itself. Store_Repository owns storage, the
	 * geocoder owns coordinates, the front end owns markup; each of those can be
	 * replaced without this file changing, which is the entire point of keeping
	 * it this small.
	 *
	 * Immutability
	 * ------------
	 * Treat every property as read-only after construction. It is a convention
	 * here, not a language guarantee: readonly properties arrived in PHP 8.1 and
	 * this plugin supports 8.0, where the keyword is a parse error — the file
	 * would not compile on the minimum version it claims to support.
	 * Constructor property promotion itself is 8.0 and is used freely.
	 *
	 * Nothing in the plugin writes to a Store after building one, and the one
	 * place that changes a Store — with_distance() — clones it and writes to the
	 * copy. That has a consequence for the day the floor moves: adding `readonly`
	 * is no longer a one-line change, because writing to a cloned readonly
	 * property is illegal until PHP 8.5's `clone with`. Measured on both binaries
	 * this suite runs: readonly plus a write to the clone is "Cannot modify
	 * readonly property" on 8.2 and on 8.5 alike, and `clone( $this, array(...) )`
	 * works on 8.5 from inside the class — a readonly property is implicitly
	 * protected(set), so the same expression from outside fails there too — and
	 * is a parse error on 8.2. PHP 8.3's amendment does not
	 * help either — it only lets a readonly property be reinitialised from inside
	 * __clone(), which this method does not use — so on 8.1 through 8.4 the
	 * keyword would turn every search result into a fatal.
	 *
	 * So the move wants either an 8.5 floor or a with_distance() that builds a
	 * new instance from all eighteen values — which is the version this file
	 * deliberately does not have, because a field forgotten in that list would
	 * vanish from every search result with nothing to announce it.
	 *
	 * The alternative — private properties behind seventeen getters — would
	 * simulate the guarantee at the cost of several hundred lines that exist only
	 * to return a value. That is more code standing between a reader and the
	 * data, for a rule a comment states just as well.
	 *
	 * Sanitising: this class does not, and here is why
	 * ------------------------------------------------
	 * from_array() casts and defaults. It does not call sanitize_text_field(),
	 * esc_url_raw() or is_email(), and its input is assumed to be already clean.
	 *
	 * The decision is not "sanitising is unnecessary", it is "sanitising has an
	 * owner, and it is not this class". Two reasons carry it:
	 *
	 * - Sanitising belongs where the untrusted value arrives, and every one of
	 *   those places is somewhere else: the metabox save handler, the REST
	 *   endpoints, any future importer. Escaping belongs where the value leaves,
	 *   because only the destination knows whether it is going into HTML, an
	 *   attribute, JSON or a URL. A string is not "safe" in the abstract, only
	 *   safe for somewhere, so a pass in the middle of the pipeline adds no
	 *   safety that the two ends do not already own.
	 * - It would hide bugs. If unsanitised text can reach a Store at all, the
	 *   leak is in a save path, and a Store that quietly launders it on every
	 *   read is a Store that keeps that leak invisible for as long as it exists.
	 *
	 * Two costs follow, neither of them the reason but both real. Sanitising
	 * here would mean choosing a sanitiser per field — sanitize_textarea_field()
	 * for hours, because sanitize_text_field() collapses the newlines out of an
	 * opening-hours block; esc_url_raw() for url; sanitize_email() for email —
	 * which is precisely the save handler's knowledge, restated in a second
	 * place to drift from the first. And it would run on trusted data on the hot
	 * path: twelve text fields per location, on 500 locations, on every cache
	 * rebuild, re-cleaning what was cleaned on write.
	 *
	 * So the contract is: whoever builds a Store from untrusted input sanitises
	 * first. The test 'takes its input as already clean and changes no text'
	 * pins this, so the decision cannot drift silently either way.
	 *
	 * Coordinates are nullable on purpose
	 * -----------------------------------
	 * $lat and $lng are ?float, and anything that is not a number — absent, an
	 * empty string, 'abc', or the '52,2297' a Polish or German spreadsheet
	 * exports — becomes null, never 0.0. A float cast would turn every one of
	 * those into 0.0, which is not "no coordinate" but a coordinate in the Gulf
	 * of Guinea; '52,2297' would become 52.0, a marker about twenty-five
	 * kilometres from the shop. Null is the only value that says "unknown"
	 * without naming a place, and it is what lets the admin warning tell an
	 * editor the truth.
	 *
	 * Normalising a comma decimal here was considered and rejected: a value
	 * object that guesses at locale is one that can place a marker confidently
	 * in the wrong country. An importer that knows its input is Polish may
	 * normalise before calling; from the database, a comma means something wrote
	 * a bad value and that should surface as "unplaced", visibly, not be
	 * repaired in passing.
	 *
	 * Range is deliberately not validated here. A latitude of 91 is bad data, but
	 * it is bad data with an owner — the save handler and the geocoder, which can
	 * reject it where the editor can see the rejection. has_coordinates() answers
	 * exactly one question, "is there a coordinate pair at all", and widening it
	 * into validation would make every caller's meaning less clear.
	 */
	final class Store {

		/**
		 * Every field a location has, in declaration order.
		 *
		 * The list is otherwise stated three times in this file — the
		 * constructor signature, from_array() and to_full_array() — and Task 6's
		 * cache mapping and Task 17's metabox will state it twice more. Nothing
		 * makes those agree on its own: from_array() ignores a key it does not
		 * know, so one mistyped name in a mapping elsewhere is one permanently
		 * blank field on every location, with no error and nothing to notice it.
		 * This constant is the one statement the others are checked against, and
		 * a test does exactly that check.
		 *
		 * These are field names, not storage. There is no _slosm_ prefix here
		 * and there should never be one: four of the seventeen are not meta at
		 * all — id, name and description are post columns, categories is a
		 * taxonomy — so where a field lives is the repository's knowledge, and
		 * putting any of it here would be the first step in this class learning
		 * how to load itself.
		 *
		 * @var string[]
		 */
		public const FIELDS = array(
			'id',
			'name',
			'description',
			'address',
			'address2',
			'city',
			'state',
			'zip',
			'country',
			'lat',
			'lng',
			'lat_locked',
			'phone',
			'email',
			'url',
			'hours',
			'categories',
		);

		/**
		 * Builds a location from already-typed values.
		 *
		 * Public so that a caller with real types in hand — a test, or
		 * with_distance() — can construct one directly. Everything coming out of
		 * post meta arrives as strings and should go through from_array()
		 * instead.
		 *
		 * $distance is the one parameter that is not a field of the record. It
		 * is an annotation a proximity search attaches to its own results, it is
		 * not in self::FIELDS, not in either array shape and never stored; see
		 * with_distance() for why it is a constructor parameter at all rather
		 * than something assigned from outside.
		 *
		 * @param int        $id          Post id.
		 * @param string     $name        Location name; the post title.
		 * @param string     $description Description; the post content.
		 * @param string     $address     Street address.
		 * @param string     $address2    Second address line.
		 * @param string     $city        City.
		 * @param string     $state       State, region or voivodeship.
		 * @param string     $zip         Postal code.
		 * @param string     $country     Country.
		 * @param float|null $lat         Latitude, or null when unknown.
		 * @param float|null $lng         Longitude, or null when unknown.
		 * @param bool       $lat_locked  Whether coordinates were set by hand and must survive re-geocoding.
		 * @param string     $phone       Phone number.
		 * @param string     $email       Email address.
		 * @param string     $url         Website url.
		 * @param string     $hours       Opening hours, free text, newlines significant.
		 * @param string[]   $categories  Category term names or slugs.
		 * @param float|null $distance    Distance from the point of one search, in that search's unit; null unless a search put it there.
		 */
		public function __construct(
			public int $id = 0,
			public string $name = '',
			public string $description = '',
			public string $address = '',
			public string $address2 = '',
			public string $city = '',
			public string $state = '',
			public string $zip = '',
			public string $country = '',
			public ?float $lat = null,
			public ?float $lng = null,
			public bool $lat_locked = false,
			public string $phone = '',
			public string $email = '',
			public string $url = '',
			public string $hours = '',
			public array $categories = array(),
			public ?float $distance = null
		) {
		}

		/**
		 * The same location, carrying how far it is from the point of a search.
		 *
		 * A copy, and the copy is the point. A Store is a value: the same object
		 * is reachable from the repository's memo and from every caller holding
		 * the list, so writing a distance in place would leave one visitor's
		 * search result attached to a list everything else reads — and the next
		 * search would read a distance measured from somewhere else.
		 *
		 * The distance is a constructor parameter rather than a property
		 * assigned from outside because this class is final with declared
		 * properties and no such field: `$store->distance = 4.2` on a class
		 * without it raises "Creation of dynamic property" on every result row
		 * under WP_DEBUG, and is a fatal error in PHP 9.
		 *
		 * It is deliberately not a field of the record. self::FIELDS stays at
		 * seventeen, to_full_array() does not carry it, and to_lean_array() —
		 * which is what the repository caches and ships to every visitor in a
		 * language — does not either. A distance is a fact about one search from
		 * one point, so a cached one is an answer to a question somebody else
		 * asked, served with nothing to mark it as wrong. Task 10 puts the
		 * distance in the REST response by reading this property off the result,
		 * not by finding it in an array shape.
		 *
		 * clone rather than a new self(...) with eighteen arguments, and that is
		 * a safety choice rather than a brevity one: a rebuild would restate the
		 * field list a fourth time, and a field left out of it would be silently
		 * empty on every search result while the same location read any other way
		 * looked fine. clone copies whatever the class has.
		 *
		 * No unit is carried with the number. It is in the unit the search was
		 * made in, which is the caller's own argument; storing a unit here would
		 * invite a formatter to trust it instead of the request.
		 *
		 * @param float $distance Distance from the search point, in that search's unit.
		 * @return self A copy carrying the distance; this instance is unchanged.
		 */
		public function with_distance( float $distance ): self {
			$copy = clone $this;

			$copy->distance = $distance;

			return $copy;
		}

		/**
		 * Builds a location from raw values, typically one row of post meta.
		 *
		 * Every field is cast and defaulted, so a missing key is an empty of the
		 * right type rather than a warning. Named arguments are used rather than
		 * seventeen positional ones: a transposed pair of adjacent strings here
		 * would silently swap a city and a postal code on every location on the
		 * site, and no test shape would catch it.
		 *
		 * This method assumes its input is already clean: it casts and defaults,
		 * and it never sanitises. Whoever holds the untrusted value sanitises
		 * before calling — the metabox save handler, the REST endpoints, an
		 * importer — and output is escaped where it is printed. The class
		 * docblock has the reasoning; the short version is that doing it here
		 * would duplicate the save handler's per-field knowledge and hide a leak
		 * in the save path rather than fix it.
		 *
		 * Unknown keys are ignored. Keys are the names in self::FIELDS, and a
		 * caller building that array from somewhere else should be checked
		 * against the constant rather than trusted to spell all seventeen.
		 *
		 * @param array $data Raw values keyed by field name; see self::FIELDS.
		 * @return self
		 */
		public static function from_array( array $data ): self {
			return new self(
				id: isset( $data['id'] ) && is_scalar( $data['id'] ) ? (int) $data['id'] : 0,
				name: self::text( $data, 'name' ),
				description: self::text( $data, 'description' ),
				address: self::text( $data, 'address' ),
				address2: self::text( $data, 'address2' ),
				city: self::text( $data, 'city' ),
				state: self::text( $data, 'state' ),
				zip: self::text( $data, 'zip' ),
				country: self::text( $data, 'country' ),
				lat: self::coordinate( $data, 'lat' ),
				lng: self::coordinate( $data, 'lng' ),
				lat_locked: ! empty( $data['lat_locked'] ),
				phone: self::text( $data, 'phone' ),
				email: self::text( $data, 'email' ),
				url: self::text( $data, 'url' ),
				hours: self::text( $data, 'hours' ),
				categories: self::categories( $data )
			);
		}

		/**
		 * Whether this location can be drawn on a map.
		 *
		 * False when either coordinate is unknown — from_array() has already
		 * turned anything non-numeric into null — and false for the exact pair
		 * 0,0, which is what a failed geocode writes and which is otherwise a
		 * point in the Gulf of Guinea. A location genuinely at 0,0 is not a case
		 * this plugin needs to serve; one at 0,21 or 52,0 is, and both still
		 * pass, because only the pair is refused.
		 *
		 * @return bool
		 */
		public function has_coordinates(): bool {
			if ( null === $this->lat || null === $this->lng ) {
				return false;
			}

			return 0.0 !== $this->lat || 0.0 !== $this->lng;
		}

		/**
		 * The map payload: what it takes to draw one marker and one result row.
		 *
		 * Seven keys, and adding an eighth is a decision with a price. This array
		 * ships for every location in one response — up to the preload threshold,
		 * five hundred of them — so a field added here is paid for on every page
		 * load by every visitor, whether or not anyone opens a popup. Anything a
		 * popup needs belongs in to_full_array(), which is fetched for one
		 * location at a time.
		 *
		 * The distance from with_distance() is not here either, and this is the
		 * shape where its absence matters most: this array is what the repository
		 * caches under a key shared by every visitor in a language, so a distance
		 * written into it is one visitor's search answer handed to the next
		 * visitor as though it were their own.
		 *
		 * @return array
		 */
		public function to_lean_array(): array {
			return array(
				'id'         => $this->id,
				'name'       => $this->name,
				'lat'        => $this->lat,
				'lng'        => $this->lng,
				'address'    => $this->address,
				'city'       => $this->city,
				'categories' => $this->categories,
			);
		}

		/**
		 * The whole record: what a popup and an admin screen need.
		 *
		 * Lossless, and that is a property worth keeping. The keys are exactly
		 * self::FIELDS, so a Store survives a round trip through this array
		 * unchanged, and a stored record, a REST response and an object can all
		 * be the same record without a translation layer between them.
		 *
		 * One value does not survive that round trip, and should not: the
		 * distance a search attached with with_distance(). It is not a field of
		 * this record, because it is not a fact about the location — it is how
		 * far the location was from where somebody happened to be looking.
		 *
		 * This is the record shape, not the cache shape. The repository caches
		 * to_lean_array() — the map payload is built for every visitor and seven
		 * keys are cheaper than seventeen — and this array is what find_by_id()
		 * returns and what anything working on one location at a time reads.
		 *
		 * lat_locked is here even though no popup displays it, and dropping it as
		 * "admin-only" would be expensive in a way that is easy to miss. The bulk
		 * geocoder reads this record per location and reads lat_locked to decide
		 * whether it may overwrite that location's coordinates. With the key
		 * missing it sees every location unlocked and silently overwrites every
		 * pin an editor placed by hand on the map picker. There is no error and
		 * no way back; the coordinates are simply gone.
		 *
		 * @return array
		 */
		public function to_full_array(): array {
			return array(
				'id'          => $this->id,
				'name'        => $this->name,
				'description' => $this->description,
				'address'     => $this->address,
				'address2'    => $this->address2,
				'city'        => $this->city,
				'state'       => $this->state,
				'zip'         => $this->zip,
				'country'     => $this->country,
				'lat'         => $this->lat,
				'lng'         => $this->lng,
				'lat_locked'  => $this->lat_locked,
				'phone'       => $this->phone,
				'email'       => $this->email,
				'url'         => $this->url,
				'hours'       => $this->hours,
				'categories'  => $this->categories,
			);
		}

		/**
		 * Reads one text field.
		 *
		 * The is_scalar() check is what keeps an oddly-shaped value quiet and
		 * honest: (string) on an array is a Warning in PHP 8 and yields the word
		 * "Array", and on an object it is an Error unless the class has
		 * __toString(). Null is not the reason — an explicit (string) null is
		 * silent on every PHP 8 and gives ''; the familiar deprecation is about
		 * passing null to an internal function's string parameter, which is a
		 * different thing and does not apply to a cast.
		 *
		 * @param array  $data Raw values.
		 * @param string $key  Field name.
		 * @return string
		 */
		private static function text( array $data, string $key ): string {
			return isset( $data[ $key ] ) && is_scalar( $data[ $key ] ) ? (string) $data[ $key ] : '';
		}

		/**
		 * Reads one coordinate, or null when the value is not a number.
		 *
		 * is_numeric() rather than a cast, for the reason in the class docblock:
		 * a cast answers "what number is this closest to", which for a non-number
		 * is always a real place on the map and never the right one.
		 *
		 * @param array  $data Raw values.
		 * @param string $key  Field name.
		 * @return float|null
		 */
		private static function coordinate( array $data, string $key ): ?float {
			$value = $data[ $key ] ?? null;

			if ( is_string( $value ) ) {
				$value = trim( $value );
			}

			return is_numeric( $value ) ? (float) $value : null;
		}

		/**
		 * Reads the category list.
		 *
		 * Always a list of non-empty strings, so no caller has to check before
		 * looping and none of them can render a blank filter chip.
		 *
		 * Only an array is accepted, and inventing a one-element list out of a
		 * stray string would be the same kind of guess the coordinates refuse to
		 * make. Elements that are not scalar are dropped rather than cast: a
		 * WP_Term has no __toString(), so casting one yields '' — a value that
		 * survives ! empty(), survives a foreach, and shows up as an empty chip
		 * with the right count. Dropping it makes the list visibly short instead,
		 * which is a bug somebody can see.
		 *
		 * The repository is the caller that has to get this right, and there is no
		 * argument that will do it for it: get_the_terms() returns WP_Term objects
		 * and has no 'fields' parameter, so the names have to be plucked out
		 * before they are handed here. WP_Term objects are exactly the shape this
		 * method throws away.
		 *
		 * @param array $data Raw values.
		 * @return string[]
		 */
		private static function categories( array $data ): array {
			if ( empty( $data['categories'] ) || ! is_array( $data['categories'] ) ) {
				return array();
			}

			$names = array();

			foreach ( $data['categories'] as $category ) {
				// Scalars only, and '' is not a category. '0' is left alone: an
				// unlikely slug, but a real one, and array_filter() would eat it.
				if ( ! is_scalar( $category ) || '' === (string) $category ) {
					continue;
				}

				$names[] = (string) $category;
			}

			return $names;
		}
	}
}
