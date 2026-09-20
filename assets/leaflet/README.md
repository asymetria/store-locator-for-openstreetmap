# Leaflet 1.9.4 — vendored

This directory holds an unmodified copy of Leaflet, the map library this
plugin is built on. It is **vendored deliberately, not fetched from a CDN**:
WordPress.org requires bundled libraries to ship with the plugin, and a CDN
would add a third party to every page a locator appears on — which is the
opposite of what a plugin whose selling point is "no Google Maps" should do.

Nothing here is edited. When the version moves, replace the whole directory
and update this file.

## Provenance

| Item | Value |
|---|---|
| Version | 1.9.4 |
| Source | `https://unpkg.com/leaflet@1.9.4/dist/` |
| Retrieved | 2026-09-16 |
| Licence | BSD-2-Clause, see `LICENSE` — GPL-compatible |
| Upstream | https://leafletjs.com/ |

## Integrity

Verified against a **second, independent** distributor rather than against the
one the files came from. The SHA-512 digests below were computed on the files
in this directory and compared byte for byte with the `sri` field that the
cdnjs API publishes for Leaflet 1.9.4. Both agree.

```
leaflet.js   sha512-BwHfrr4c9kmRkLw6iXFdzcdWV/PGkVgiIyIWLLlTSXzWQzxuSg4DiQUCpauz/EWjgk5TYQqX/kvn9pG1NpYfqg==
leaflet.css  sha512-Zcn6bjR/8RZbLEpLIeOwNtzREBAJnUKESxces60Mpoj+2okopSAcSUIUOseddDm0cxnGQzxIR7vJgsLZbdLE3w==
```

To re-check after any change to this directory:

```bash
openssl dgst -sha512 -binary leaflet.js | openssl base64 -A
```

## Contents

- `leaflet.js` — the minified build, 1.9.4
- `leaflet.css` — the stylesheet, which references `images/layers.png`,
  `images/layers-2x.png` and `images/marker-icon.png` by relative path, so the
  `images/` directory must stay where it is
- `images/` — the default marker and layer-control sprites. `marker-icon-2x.png`
  and `marker-shadow.png` are referenced by `L.Icon.Default` from JavaScript
  rather than from the stylesheet, which is why they are here but do not appear
  in a grep of the CSS
- `LICENSE` — BSD 2-Clause, Volodymyr Agafonkin and contributors

## What is not here

The source build (`leaflet-src.js`) and its source map. A minified library with
no map is what ships; carrying the unminified copy as well would roughly double
the plugin's download for something only a developer debugging Leaflet itself
would open, and they can get it from upstream at the version pinned above.
