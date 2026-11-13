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

**New song:** Add an entry to `data/songs.json`, place the audio file in `audio/`, and place artwork in `images/`. The song will automatically appear on the homepage.

**New poem:** Same pattern using `data/poems.json`.

**New release:** Add an entry to `data/releases.json` (use `hard-hitter` as the reference for field shapes), put artwork in `images/`, and add a `data-type="releases"` item to `index.html`. Do not create a standalone page in `releases/`: `release-template.html` renders `/releases/<id>.html` via the `.htaccess` rewrite. Buy buttons appear only once `stripeLinks` are non-null. Tracks link to `/songs/<slug>.html` by default; add `"link": false` to a track to show it as plain text (e.g. unpublished songs). Then add it to `sitemap.html` manually (tree view + chrono list + stats line). To preview locally (no Apache rewrite), open `/release-template.html?id=<id>`.

After adding content, update `sitemap.html` (tree view, chrono list, stats count/date).

## Consistent Page Structure

All content pages share the same header/footer/status-bar pattern:

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

## Design Constraints (Intentional)

No JS frameworks, no database, no build pipeline, monospace typography, self-hosted on low-power hardware. Avoid introducing dependencies or build steps — keep it static and vanilla. Use sed or Python for bulk text replacements when needed (note: sed fails on multi-byte UTF-8 characters like `└──` — use Python instead).
