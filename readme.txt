=== Store Locator for OpenStreetMap ===
Contributors: TODO-wordpress-org-username
Tags: store locator, openstreetmap, leaflet, map, store finder
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A store locator built on Leaflet and OpenStreetMap. No Google Maps API key, no billing account, no third-party map cookies.

== Description ==

Locations are a custom post type with an address and coordinates. A visitor
types a place, picks a radius, and gets the nearest locations as pins on a map
and as a list beside it. It ships as a shortcode and as a Bricks Builder
element.

= What it does =

* Search by address, postcode or city, with suggestions as you type and a
  search button beside the field
* A radius filter in kilometres or miles, and a result-count filter
* "Use my location", which asks the browser for a position only when the
  visitor presses the button
* A category filter, backed by a taxonomy
* Marker clustering, which switches itself on once a locator would draw fifty
  pins, and which can be forced on or off
* A directions link from a result to OpenStreetMap or to Google Maps, or none
  at all, whichever the site chooses
* Bulk geocoding from the admin, with a report of what happened to each row
* A settings screen whose fields are a closed list of options rather than a
  free-text box that can hold anything

= How it is built =

* **No dependencies and no build step.** No npm, no Composer, no bundler. What
  is in the plugin is what runs.
* **No jQuery.** One frozen `window.SLOSM` namespace on the front end.
* **The REST API rather than admin-ajax.** Four narrow, read-only routes.
* **Conditional assets.** The clustering bundle downloads only when a locator
  actually needs it; a site with twenty branches never sees it.
* **Geocoding through a server-side proxy**, with a thirty-day cache and a
  one-request-per-second gap, so a visitor's browser never talks to the
  geocoder itself and a failure is never cached.
* Leaflet and Leaflet.markercluster are bundled rather than loaded from a CDN.

= Known limitations in this release =

Worth reading before this goes on a site that matters.

* **The search button is new in this release, and there is no form around
  it.** The address field has a Search button beside it now, running the same
  search Enter runs; with the suggestion list open, both take the highlighted
  suggestion rather than looking the text up again. What this markup
  deliberately does not have is a `<form>`: that would make Enter submit the
  page for anybody the plugin's JavaScript has not reached yet, and a form
  inside a page builder's own form is discarded by the browser's parser with
  its controls handed to the outer one. If your theme or builder rebuilds the
  filter bar and drops the button, the field still searches on Enter.
* **The styling is two files, and you can switch one of them off.** One holds
  the layout — the map's box and its height floor, the results list beside or
  under it, the suggestion popup, the dot marker — and is always loaded. The
  other is a skin: the spacing of the filter row and a border and padding on
  the search field, the selects and the buttons, in the site's own font and
  colour rather than the browser's. Untick "Default styles" on the Advanced tab
  to drop the skin and write your own; the map keeps its layout either way, so
  switching it off cannot leave you with a map of no height, which is the usual
  way this kind of plugin appears broken.
* **The accessibility and translation passes are done; what is left is
  checking them with real software.** The search field is a combobox with a
  listbox of suggestions, the arrow keys walk it, one status line announces
  what happened — including how many locations a search found — and opening a
  location's bubble moves the keyboard focus into it and gives it back when the
  bubble closes. The map's close button is labelled in your site's language
  rather than in English. None of that has been through NVDA, VoiceOver or
  Orca yet, and that check is listed as open rather than claimed as done.
* One thing is deliberately left alone: the name inside a location's bubble is
  bold text and not a heading, because a heading needs a level and the right
  level depends on the page you drop the locator into.
* Clustering at scale has not been measured on a real site either.

= The free services have limits, and a busy shop will meet them =

The public OpenStreetMap geocoding service allows roughly one request a second
and asks that heavy use go elsewhere. This plugin keeps to that limit and
caches hard, but a shop with real traffic needs its own instance or a paid
provider. That is a property of a free public service rather than of this
plugin, and the endpoints are settings so a site can point at its own.

= Credits and licences =

* Leaflet 1.9.4, unmodified, BSD-2-Clause — https://leafletjs.com/
* Leaflet.markercluster 1.5.3, unmodified, MIT —
  https://github.com/Leaflet/Leaflet.markercluster
* Map data © OpenStreetMap contributors —
  https://www.openstreetmap.org/copyright

Both bundled libraries are GPL-compatible, and each ships with its own licence
file and a note recording where it came from and what its SHA-512 digest is.

== External services ==

This plugin contacts three external services. Two of them are contacted by
your server; the third is contacted by every visitor's browser. None of them
is contacted until something on the site actually asks for it.

= 1. Nominatim, the OpenStreetMap geocoding service =

**What it is for.** Turning an address into coordinates.

**When.** When a location is saved with an address and no coordinates, when
the bulk geocoder is run from the admin, and when a visitor submits a search.
A result is cached for thirty days, so the same address is sent once.

**What is sent.** The address or search text, normalised; an ISO country code
when the site has set one; and a User-Agent naming this plugin, its version
and your site's home URL, which the service's usage policy asks for. The
request is made by your server, so the visitor's IP address and cookies are
not part of it.

**Where.** `https://nominatim.openstreetmap.org/search` by default. The
endpoint is a setting: a site may point it at its own instance or at a paid
provider instead.

**Terms and privacy.** TODO — the operator's usage policy and privacy policy
URLs. They were not filled in here because the session that wrote this file
was not allowed to make any network request, including to go and read them,
and an invented URL is worse than a marked gap.

= 2. Photon, the address-suggestion service =

**What it is for.** The suggestions that appear while a visitor types in the
search field.

**When.** While typing, after a pause, and only when suggestions are switched
on. A suggestion list is cached for a day.

**What is sent.** The text typed so far and a result limit. As above, the
request is made by your server rather than by the visitor's browser.

**Where.** `https://photon.komoot.io/api` by default, and also a setting.

**Terms and privacy.** TODO — the operator's terms of use and privacy policy
URLs, for the same reason as above.

= 3. The OpenStreetMap tile server =

**What it is for.** The map images themselves.

**When.** Every time a page carrying a map is viewed, from the visitor's own
browser.

**What is sent.** What any image request sends: the visitor's IP address, their
browser's User-Agent, and the coordinates of the tiles being looked at. This
plugin sets no cookie of its own and neither the geocoding nor the suggestion
request carries one.

**Where.** `https://tile.openstreetmap.org/{z}/{x}/{y}.png` by default, and a
setting, so a site may use any tile provider it has the right to use.

**Terms and privacy.** Map data and attribution:
https://www.openstreetmap.org/copyright . TODO — the tile usage policy and the
foundation's privacy policy URLs, not filled in for the reason given above.

= Not a request, but worth saying =

A "directions" link opens OpenStreetMap, or Google Maps if the site chose
that, in a new tab **when a visitor clicks it**. Nothing is sent anywhere until
they do, and the link can be turned off in the settings.

== Installation ==

1. Upload the plugin to `wp-content/plugins/`, or install it from the
   Plugins screen, and activate it.
2. Add locations under **Locations**. Enter an address and press the button
   that geocodes it, or type the coordinates directly.
3. Put a locator on a page: use the shortcode builder under **Locations →
   Shortcode**, or add the **Store Locator** element in Bricks.
4. Settings live under **Locations → Settings**.

No API key, no account, and nothing to configure before the first map draws.

== Frequently Asked Questions ==

= Do I need a Google Maps API key, or a billing account? =

No. The map, the geocoding and the suggestions all use OpenStreetMap services,
and the plugin works with the defaults it ships with.

= Can I use it on a busy shop? =

Not on the free public services alone. They allow about one request a second
and ask that heavy use be moved elsewhere, and a shop with real traffic will
reach that. The geocoding endpoint, the suggestion endpoint and the tile URL
are all settings, so the answer is to point them at your own instance or at a
provider you pay. Please have that conversation before the traffic arrives
rather than after.

= What happens to my locations when I delete the plugin? =

They stay, unless you have asked for them to go.

By default, deleting the plugin removes its settings, its two cache
invalidation counters and its own cached entries, and nothing else. Your
locations, their addresses and coordinates, and their categories are posts and
terms in your database, and removing a plugin is not by itself a decision to
delete them.

If you want them gone, the Advanced tab has a checkbox — *"Delete every
location, category and setting when this plugin is deleted"*. It is **off by
default**, and while it is off, uninstalling keeps every location. Tick it and
deleting the plugin also deletes the `slosm_store` posts with their meta,
including any in the trash, and the location categories. Nothing else on the
site is touched: other post types, other taxonomies and other plugins' data are
left alone.

That deletion cannot be undone from here, so please take a backup first if
there is any chance you will want the addresses back.

= Does it work with Bricks Builder? =

Yes. The element registers itself when Bricks is present and does nothing at
all when it is not, and it renders through the same code the shortcode uses, so
the two cannot drift apart. It was built against Bricks 2.4.

= The buttons do not look like the rest of my site's buttons =

There is a field for that: "Button class" on the Advanced tab, empty by
default. Whatever you type is added to the Search and "Use my location"
buttons, so they can take the class your theme already styles — on Bricks that
is `bricks-button`, and most themes and frameworks have one of their own. One
locator can override the site-wide value with `button_class` in the shortcode
or in the element's own control.

The plugin does not fill this in for you, deliberately. Writing another theme's
class name into a plugin means inheriting that theme's renames as your own
breakages, and a primary-button class on a secondary control is rarely what a
page wants. The Bricks element also carries a STYLE tab, so a locator can be
styled in the builder without touching CSS at all.

= Can I use a different map style or tile provider? =

Yes — the tile URL and its attribution line are settings. Please use a provider
whose terms allow your traffic, and keep the attribution that provider requires.

= Where does the plugin store anything? =

One option for the settings, two counters used to invalidate caches, two
timestamps that keep the plugin inside the geocoder's rate limit, and
transients for the cached map payload, the cached geocoding results and a few
admin notices. All of those go when the plugin is deleted. Locations
themselves are ordinary posts, and they go only if you asked for them to —
see the question above.

== Changelog ==

= 1.0.0 =
* First release.
* The map frames what it is showing — on load, after a search, and when a
  location is opened from the list — and redraws itself when its container
  changes size inside a page builder, a tab or an accordion.
* Keyboard and screen reader: the popup takes the focus, Escape closes it and
  gives the focus back to the row that opened it, and that row says what it
  opened for as long as it is open.
* Map, search, radius and category filters, "use my location", clustering,
  directions links, bulk geocoding, a settings screen, a shortcode builder and
  a Bricks Builder element.
* The address field has a Search button, and carries enterkeyhint="search" so
  that a touch keyboard labels its action key. No form element is involved:
  the button runs the same code path Enter runs.
* Changing the radius or the result count updates the results on its own.
