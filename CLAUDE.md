# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

rad.typo is a pure static website for an independent music artist based in Derry, UK. No build system, no package manager, no framework — files are served directly from an Apache server running on a Raspberry Pi 3B+. The site is also mirrored on GitHub Pages.

## No Build Process

There is no `npm`, `make`, `build`, or test command. Edit files directly and they are live. To preview locally, serve files with any static server (e.g. `python3 -m http.server`).

## Architecture

The site uses a **JSON + template system**:

- **`data/songs.json`** — single source of truth for all song metadata (title, date, lyrics, artwork filename, audio filename, equipment, credits, resources)
- **`data/poems.json`** — same for poetry/written works
- **`song-template.html`** — single HTML file that serves all song pages; reads the song ID from the URL path/query param, fetches `songs.json`, and renders content client-side
- **`poems-template.html`** — same pattern for poem pages
- **`data/releases.json`** — all release metadata, keyed by release id (title, visible, date, format, stock, catalog binary, carousel, tracks, download, price, shipping, stripeLinks)
- **`release-template.html`** — single HTML file that serves all release pages; reads the id from `?id=` or the `/releases/<id>.html` path, fetches `releases.json`, and renders carousel, tracklist, price, shipping and buy buttons client-side
- **`index.html`** — homepage; unified chronological list with filter buttons (songs / poems / releases). Release items are hand-written `<li data-type="releases">` entries

URL routing for templates is handled via `.htaccess` Apache rewrite rules (`/releases/<id>.html` → `/release-template.html?id=<id>`). 301 redirects are in place for old paths: `/writing/` → `/poems/`, `/albums/` → `/releases/`.

## Adding Content

**New song:** Add an entry to `data/songs.json`, place the audio file in `audio/`, and place artwork in `images/`. Add a `data-type="songs"` item to `index.html` by hand (the homepage list is static and not re-sorted, so source order is display order). `radio.html` picks the song up automatically once its `date` is a full `YYYY-MM-DD` on or before today. Add an `"mp3 download"` entry to `resources` (`../audio/<slug>.mp3`). Set `"hideDetails": true` to hide the details box until it's populated.

**New poem:** Same pattern using `data/poems.json`.

**New release:** Add an entry to `data/releases.json` (use `hard-hitter` as the reference for field shapes), put artwork in `images/`, and add a `data-type="releases"` item to `index.html`. Do not create a standalone page in `releases/`: `release-template.html` renders `/releases/<id>.html` via the `.htaccess` rewrite. Buy buttons appear only once `stripeLinks` are non-null. Stock lives only in `stock.remaining` in `releases.json`: the release page and the homepage (`<span data-stock="<id>">`, filled by JS; its text is just a no-JS fallback) both read it, and 0 shows as "sold out". After a sale, update `stock.remaining` (optionally the fallback text too). Tracks link to `/songs/<slug>.html` by default; add `"link": false` to a track to show it as plain text (e.g. unpublished songs). Then add it to `sitemap.html` manually (tree view + chrono list + stats line). To preview locally (no Apache rewrite), open `/release-template.html?id=<id>`.

After adding content, update `sitemap.html` (tree view, chrono list, stats count/date).

## Released vs Unreleased (date convention)

A song is **released** only when its `date` in `songs.json` is a full `YYYY-MM-DD` on or before today (UTC). An optional `"liveFrom": "YYYY-MM-DD"` overrides that check only (the page still shows `date`), to switch a song on early; song page, rad.io and telemetry all honour it. A **year-only date** (e.g. `"2026"`) or a future date means **unreleased**:

- `song-template.html` shows only the title + "out soon" (no artwork, player, lyrics, chords or downloads; `noindex`), so a guessed `/songs/<slug>.html` URL can't play or reveal the track.
- `radio.html` skips it.
- Don't list or link it anywhere (homepage, sitemap, release tracklists — use `"link": false` on release tracks).

To release a song, set its real `YYYY-MM-DD` date and add its homepage/sitemap entries.

Note: `data/songs.json` itself is public, so unreleased lyrics/chords there are readable by anyone who fetches the file — only the audio is actually protected (by not being on the server, or by `.htaccess`).

**Test demos:** mark a song `"demo": true` in `songs.json` (currently `the-most-generic-experience-ever`, `old-enough-to-handle-it`, `late-stage-capitalismz`). The entry stays, but it is never listed or linked; `song-template.html` shows it as "not found" (same as a nonexistent slug) and `radio.html` skips it. Its audio must also be blocked with `Require all denied` in `.htaccess` (as is all of `/audio/jets-to-oblivia/` until that album is out) — the flag hides pages, `.htaccess` protects the file. The files stay in git.

## "Don't Count Me" Cookie

Visiting any page with `?me=1` sets cookie `rt_me=1` (1 year, `Path=/`, `SameSite=Lax`); `?me=0` clears it (`js/status.js`, which every page loads; the `me` param is then stripped from the URL). `.htaccess` sets env var `rt_me` from that cookie, and the Pi's vhost config logs those requests to `me_access.log` instead of `other_vhosts_access.log`.

## Consistent Page Structure

All content pages share the same head/header/footer/status-bar pattern:

**`<head>` (font + favicon)** — every page, before its own `<style>`/stylesheets:
```html
<link rel="preload" href="/fonts/ibm-plex-mono-latin-400-normal.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="/css/fonts.css">
<link rel="icon" href="/favicon.svg?v=2" type="image/svg+xml">
<link rel="icon" href="/favicon.ico?v=2" sizes="32x32">
<link rel="apple-touch-icon" href="/apple-touch-icon.png?v=2">
```

**Typography:** IBM Plex Mono, self-hosted in `fonts/` (latin subset: 400, 400 italic, 700; OFL in `fonts/OFL.txt`). `css/fonts.css` defines the `@font-face` rules and `--mono`. Always use `font-family: var(--mono)`, never bare `monospace` (it bypasses the font). Sole exception: the "rad.typo" wordmark (`.site-title, .header h1` in `fonts.css`) deliberately stays system `monospace`. The latin subset has no arrows, box drawing, □■ or █░ glyphs; those fall back per character to the system monospace font. Fonts and favicons are cached for a year (`.htaccess`): to change a favicon, keep the filename and bump the `?v=N` on its three `<link>` hrefs in every page (and here); to change a font, give it a new filename.

**Header:**
```html
<header class="header">
    <a href="/" class="back">← back</a>
    <h1><a href="/" class="site-title">rad.typo</a></h1>
    <div class="status">
        derry: <span id="weather-temp">-</span>°C <span id="weather-icon">⛅</span> |
        kp: <span class="kp-circle"></span><span id="kp-value">-</span> |
        cpu: <span id="cpu-temp">-</span>°C<span class="breathing-dots"></span>
    </div>
</header>
```

**Footer:**
```html
<footer class="footer">
    └─ <a href="/infrastructure.html">infrastructure</a> | <a href="/telemetry.html">telemetry</a>
</footer>
```

**Status bar JS** — all pages include `KP_COLORS`, `updateCpuTemp()`, `updateWeather()`, `updateKPIndex()`, a sessionStorage restore IIFE (runs immediately, keys: `rt_weather`, `rt_kp`, `rt_cpu`), and polling intervals (CPU: 30s, weather/KP: 5min). The IIFE restores cached values synchronously on load for smooth transitions between pages.

When adding or auditing a page, verify it matches this structure exactly — use `poems-template.html` as the reference.

## Live Telemetry (telemetry.html, "a day in derry")

`scripts/telemetry_stats.py` (Python 3 stdlib only) runs on the Pi every 5 minutes from cron, as a user in the `adm` group so it can read `/var/log/apache2`:

```
*/5 * * * * /usr/bin/python3 /var/www/html/scripts/telemetry_stats.py >/dev/null 2>&1
```

It reads `other_vhosts_access.log` and `.1` (and `.2.gz` only while yesterday isn't recorded yet, since logrotate runs ~06:25), counts visitors, plays, data sent, `here_now` and `listening_now`, reads the CPU temperature and `vcgencmd get_throttled` (null if unavailable), and every 15 minutes fetches Open-Meteo (Derry) and NOAA Kp so visitors' browsers never call outside services (on failure it keeps the last good values and marks them `stale`). Moon phase is computed locally. Run `python3 scripts/telemetry_stats.py --backfill` once to also read the `.N.gz` rotations and add every past day to history (it never duplicates or deletes days). `--logs`, `--out`, `--now` and `--no-fetch` exist for testing.

- **Counting rules:** visitor = distinct IP + user agent per day with a non-asset request; play = first request for a song's audio by a visitor within 30 minutes; plays count only for released songs (same rule as above: full date on or before today, not `demo`). Skipped: my own visits (Apache routes them to `me_access.log`), IPs in `scripts/telemetry_exclude.txt`, private/Tailscale ranges, bots (user-agent regex), and statuses other than 200/206.
- **Privacy:** IP + user agent are hashed with a random salt that exists only in memory for one run; no IP or user agent is ever written. `scripts/telemetry_exclude.txt` (one IP per line) holds my own IPs, so it's gitignored and created by hand on the Pi; `.htaccess` denies all of `/scripts/`.
- **Outputs** (in `data/`, written atomically, generated on the Pi and **never committed**: both are in `.gitignore`): `telemetry.json` (rewritten every run, read by `telemetry.html`) and `telemetry-history.json` (persistent: one record per finished day, a rolling ~24h list of sensor samples, the last good sky data).
- **Energy:** `ESTIMATED_WATTS` (top of the script, 3.4) is an estimate, not a measurement: energy today = watts × hours since local midnight, per visit = that ÷ visitors. Always shown as "est." on the page.
- **Battery hook (future sensor):** when `data/power.json` exists (`{"battery_pct": 71, "volts": 12.6, "charging": true}`, written by whatever reads the sensor), its fields are copied into `telemetry.json` with an hourly series and the overnight low; otherwise `"battery": null` and the page shows "battery sensor coming". `power.json` is gitignored too.

`telemetry.html` polls `/data/telemetry.json` and `/api/stats.json` (memory/disk/uptime) every 60s while the tab is visible. Charts are inline SVG, no libraries. (The shared header's `js/status.js` still fetches weather and Kp itself on every page.)

## Radio Player

`radio.html` is a minimal shuffle-only music player (Fisher-Yates queue, play/pause + skip, no persistence). `radio-archive.html` is the original full-featured player (progress bar, playlist, artwork, shuffle/loop toggles), accessible from the radio page.

## Key Files

| File | Purpose |
|------|---------|
| `data/songs.json` | All song metadata |
| `data/poems.json` | All poem metadata |
| `song-template.html` | Song page template |
| `poems-template.html` | Poem page template |
| `data/releases.json` | All release metadata (stock, price, shipping, stripe links, tracks) |
| `release-template.html` | Release page template (`/releases/<id>.html` via `.htaccess`) |
| `index.html` | Homepage (chronological feed with filters) |
| `sitemap.html` | Full site map (tree + chrono views) — update manually when adding content |
| `radio.html` | Minimal shuffle player |
| `radio-archive.html` | Full-featured player (legacy) |
| `releases/` | Release media assets only (zips, videos, stills); pages come from `release-template.html` |
| `releases.html` | Changelog page rendered from generated `data/commits.json` (not a release list) |
| `.htaccess` | Apache routing/rewrites + 301 redirects |
| `api/stats.json` | Raspberry Pi server stats (CPU temp, uptime, etc.) |
| `scripts/telemetry_stats.py` | Pi cron script: logs + sensors + sky → `data/telemetry.json` (see Live Telemetry) |
| `telemetry.html` | "a day in derry" live page, reads `data/telemetry.json` |
| `css/fonts.css` + `fonts/` | Self-hosted IBM Plex Mono and `--mono` |
| `favicon.svg`, `favicon.ico`, `apple-touch-icon.png` | Catalogue-grid tab icon (ICO/PNG drawn pixel-exact from the SVG design) |

## Design Constraints (Intentional)

No JS frameworks, no database, no build pipeline, monospace typography (one self-hosted font, no third-party font services), self-hosted on low-power hardware. Avoid introducing dependencies or build steps — keep it static and vanilla. Use sed or Python for bulk text replacements when needed (note: sed fails on multi-byte UTF-8 characters like `└──` — use Python instead).
