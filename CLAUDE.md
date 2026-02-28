# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

rad.typo is a pure static website for an independent music artist based in Derry, UK. No build system, no package manager, no framework — files are served directly from an Apache server running on a Raspberry Pi 3B+. The site is also mirrored on GitHub Pages.

## No Build Process

There is no `npm`, `make`, `build`, or test command. Edit files directly and they are live. To preview locally, serve files with any static server (e.g. `python3 -m http.server`).

## Architecture

The site recently migrated from individual HTML files to a **JSON + template system**:

- **`data/songs.json`** — single source of truth for all song metadata (title, date, lyrics, artwork filename, audio filename, equipment, credits, resources)
- **`data/writing.json`** — same for poetry/written works
- **`song-template.html`** — single HTML file that serves all song pages; reads the song ID from the URL path/query param, fetches `songs.json`, and renders content client-side
- **`writing-template.html`** — same pattern for writing pages
- **`index.html`** — homepage; fetches both JSON files and renders a unified chronological list of all songs and writing

URL routing for templates is handled via `.htaccess` Apache rewrite rules.

## Adding Content

**New song:** Add an entry to `data/songs.json`, place the audio file in `audio/`, and place artwork in `images/`. The song will automatically appear on the homepage and be accessible via its key as the URL slug.

**New writing:** Same pattern using `data/writing.json`.

## Dynamic Features in Templates

Each template page shows a live status bar fetching:
- **Weather** — Open-Meteo API (Derry coordinates)
- **KP Index** — NOAA API (geomagnetic activity, color-coded)
- **CPU temperature** — local `/api/stats.json` from the Raspberry Pi

These are best-effort fetches; failures are handled gracefully.

## Key Files

| File | Purpose |
|------|---------|
| `data/songs.json` | All song metadata |
| `data/writing.json` | All writing/poetry metadata |
| `song-template.html` | Song page template |
| `writing-template.html` | Writing page template |
| `index.html` | Homepage (chronological feed) |
| `.htaccess` | Apache routing/rewrites |
| `subscribe.php` | Email list subscription (PHP, file-based) |
| `api/stats.json` | Raspberry Pi server stats (CPU temp, uptime, etc.) |

## Design Constraints (Intentional)

The project philosophy embraces constraints: no JS frameworks, no database, no build pipeline, monospace typography, and self-hosted on low-power hardware. Avoid introducing dependencies or build steps — keep it static and vanilla.
