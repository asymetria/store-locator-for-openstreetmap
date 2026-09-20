# Store Locator for OpenStreetMap

A WordPress store locator built on [Leaflet](https://leafletjs.com/) and
OpenStreetMap. No Google Maps API key, no billing account, no third-party map
cookies.

Locations are a custom post type with an address and coordinates. A visitor
types a place, picks a radius, and gets the nearest locations as pins on a map
and as a list beside it. It ships as a shortcode and as a **Bricks Builder
element**.

**Status: pre-release.** It runs, and there are known gaps — see
[Known gaps](#known-gaps) before installing it anywhere that matters.

---

## What it does

- **Search by address, postcode or city**, with suggestions as you type and a
  search button beside the field
- **Radius filter** in kilometres or miles, and a result-count filter
- **"Use my location"**, off by default because a permission prompt nobody
  asked for is a good way to be refused
- **Category filter**, backed by a taxonomy
- **Marker clustering** once there are enough pins to need it
- **Directions** from a result to the visitor's map application of choice
- **Bulk geocoding** from the admin, with a report of what happened to each row
- **A settings screen** with a closed list of options rather than a free-text
  field that can hold anything

## How it is built

Some of this is the point of the repository, so it is worth stating plainly.

- **No dependencies and no build step.** No npm, no Composer, no bundler. What
  is in the repository is what runs. Leaflet and Leaflet.markercluster are
  vendored, with SHA-512 digests verified against a *second, independent*
  distributor and recorded in [`assets/leaflet/README.md`](assets/leaflet/README.md).
- **No jQuery.** One frozen `window.SLOSM` namespace on the front end.
- **REST, not `admin-ajax`.** Four routes, all read-only and all narrow.
- **One storage seam.** Everything reads through `Store_Repository`, so moving
  to a custom table later is one class and a migration, not a rewrite.
- **Conditional asset loading.** The cluster bundle downloads only when a
  locator actually needs it. A site with twenty branches never sees it.
- **Geocoding through a server-side proxy** with a 30-day cache and a
  one-request-per-second courtesy limit, so the visitor's browser never talks
  to the geocoder directly and failures are never cached.

## Tests

`tests/` holds a hand-written harness — no PHPUnit, no Jest, no dependency of
any kind — plus WordPress stubs.

```bash
php tests/run.php                                  # 947 cases
node --test --test-concurrency=1 "tests/js/*.test.js"   # 439 cases
```

Every feature was also **mutation tested**: the code was deliberately broken,
one change at a time, and any mutant the suite did not catch was either killed
with a new case or recorded in a comment as a known survivor. Every new case
was first run against an emptied class, because a case that passes against a
skeleton is not testing what its name says.

## Bricks Builder

`admin/class-bricks-element.php` registers a `\Bricks\Element` when Bricks is
present, and nothing at all when it is not. Its controls are **derived** from
the shortcode generator rather than written a second time, and a case asserts
its control keys against the shortcode's own defaults — so an attribute added
in one place and forgotten in another fails the suite instead of shipping.

It renders through the same method the shortcode does, so the two cannot drift.

## Requirements

| | |
|---|---|
| WordPress | 6.0 or later |
| PHP | 8.0 or later |
| Browsers | modern ones — the front end uses `Promise`, `WeakSet` and `MutationObserver`, and is not transpiled because there is no build step |
| Licence | GPL-2.0-or-later |

## Two things worth telling a client

Both of these belong in the conversation before a deployment, not after one.

**The public Nominatim service allows about one request per second and forbids
heavy use.** This plugin respects that limit and caches aggressively, but a
shop with real traffic needs its own instance or a paid provider. That is a
property of the free service, not of this plugin, and pretending otherwise
would be setting a client up to be blocked.

**Leaflet is bundled, not loaded from a CDN.** WordPress.org requires it, and a
CDN would put a third party on every page a locator appears on — which is the
opposite of what a plugin whose selling point is "no Google" should do.

## Known gaps

Found by using the plugin on a real site rather than by testing it, and
recorded in full in the plan:

- ~~**There is no search button, and the address field needs Enter.**~~ Closed
  in Task 29c: the address field now has a **Search** button beside it, and it
  runs the same search Enter runs — with the suggestion list open it takes the
  highlighted suggestion, exactly as Enter does, rather than looking the text
  up a second time. There is deliberately **no `<form>`**: a form
  brings implicit submission, which is a page reload for anybody this plugin's
  JavaScript has not reached yet, and a form inside a page builder's own form
  is dropped by the parser with its controls adopted by the outer one. The
  field also carries `enterkeyhint="search"`, which is what labels the action
  key on a touch keyboard.
- **The default stylesheet is layout-only**, which on a theme that styles no
  form controls leaves raw browser widgets. Being addressed — Task 30.
- **Several manual checks are still open** — clustering at scale, the metabox
  map in the block editor, accessibility and internationalisation passes.

## Documentation

The reasoning lives beside the code it explains. Where a decision is not
obvious from the line that makes it — why the map frames itself rather than
trusting a configured centre, why the popup owns the Escape key, why the
stylesheet is two files — the comment above it says what was measured and what
was rejected.

## Licence

GPL-2.0-or-later. Written from scratch; it carries no code from any other
store locator plugin.

Leaflet is BSD-2-Clause and Leaflet.markercluster is MIT, both GPL-compatible,
both unmodified, with provenance recorded beside them.

Map data © [OpenStreetMap](https://www.openstreetmap.org/copyright) contributors.
