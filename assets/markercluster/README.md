# Leaflet.markercluster 1.5.3 — vendored

An unmodified copy of Leaflet.markercluster, which collapses nearby pins into a
counted cluster. Vendored for the same reasons as `../leaflet/`: WordPress.org
requires bundled libraries to ship with the plugin, and a CDN would put a third
party on every page a locator appears on.

This library is loaded **conditionally** — only when clustering is on, or when
a locator carries enough locations to need it. A site with twenty branches
never downloads it.

Nothing here is edited. When the version moves, replace the whole directory and
update this file.

## Provenance

| Item | Value |
|---|---|
| Version | 1.5.3 |
| Source | `https://unpkg.com/leaflet.markercluster@1.5.3/dist/` |
| Retrieved | 2026-09-17 |
| Licence | MIT, see `LICENSE` — GPL-compatible |
| Upstream | https://github.com/Leaflet/Leaflet.markercluster |

## Integrity

Verified against a **second, independent** distributor rather than against the
one the files came from. The SHA-512 digests below were computed on the files
in this directory and compared with the `sri` field the cdnjs API publishes for
1.5.3. All three agree.

```
leaflet.markercluster.js   sha512-OFs3W4DIZ5ZkrDhBFtsCP6JXtMEDGmhl0QPlmWYBJay40TT1n3gt2Xuw8Pf/iezgW9CdabjkNChRqozl/YADmg==
MarkerCluster.css          sha512-mQ77VzAakzdpWdgfL/lM1ksNy89uFgibRQANsNneSTMD/bj0Y/8+94XMwYhnbzx8eki2hrbPpDm0vD0CiT2lcg==
MarkerCluster.Default.css  sha512-6ZCLMiYwTeli2rVh3XAPxy3YoR5fVxGdH/pz+KMCzRY2M65Emgkw00Yqmhh8qLGeYQ3LbVZGdmOX9KUjSKr0TA==
```

To re-check after any change:

```bash
openssl dgst -sha512 -binary leaflet.markercluster.js | openssl base64 -A
```

`.gitattributes` marks this directory `-text` so the repository-wide `eol=lf`
rule cannot rewrite line endings and quietly break these digests — which it did
to `../leaflet/leaflet.css` before anyone noticed.

## A hazard worth knowing before touching the cluster icon

`L.DivIcon` sets its content with `innerHTML`, and the cluster icon is a
`DivIcon`. Anything derived from a location — a name, a category, a count
formatted with server text — handed to `iconCreateFunction` is parsed as
markup, inside Leaflet, where this plugin's own source scan for `innerHTML`
cannot see it. Build cluster labels from numbers this file computed, not from
payload strings.

## Contents

- `leaflet.markercluster.js` — the minified build, 1.5.3
- `MarkerCluster.css` — positioning and the spiderfy lines
- `MarkerCluster.Default.css` — the default green/yellow/orange bubbles
- `LICENSE` — MIT, David Leaver and contributors
