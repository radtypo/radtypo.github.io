#!/usr/bin/env python3
"""Build the register of works into index.html (experimental "register" branch).

Run from anywhere after changing songs.json / poems.json / releases.json:

    python3 scripts/build-register.py

What it does:
- Lists every public work, newest first:
    songs    released only: full YYYY-MM-DD date on or before today, not "demo": true
    poems    every entry in poems.json
    releases "visible" is not false (vinyl -> record, painting, page), dated by
             "published" if set, else "date"
- Gives songs and poems a permanent register number. data/register-numbers.json is
  append-only: new works get the next number (in date order) and existing numbers never
  change, even if a work is later withdrawn (its number is simply retired).
  Releases use their binary catalogue number instead.
- Writes the rows (plain HTML, so the page works without JS) between the
  <!-- register:rows --> markers in index.html, plus the entry count.

Weight = words: lyrics for a song, text for a poem, the summed lyrics of a record's
tracks; paintings and pages have no words ("—").
"""

import html
import json
import re
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
DATA = ROOT / 'data'
NUMBERS = DATA / 'register-numbers.json'
INDEX = ROOT / 'index.html'

FULL_DATE = re.compile(r'^\d{4}-\d{2}-\d{2}$')
YEAR = re.compile(r'^\d{4}$')
KIND = {'vinyl': 'record', 'painting': 'painting', 'page': 'page'}


def load(name):
    return json.loads((DATA / name).read_text(encoding='utf-8'))


def words(text):
    return len(re.findall(r"[\w'’-]+", text or ''))


def sort_date(d):
    """Year-only dates sort as YYYY-01-01; missing dates sort last (oldest)."""
    if FULL_DATE.match(d or ''):
        return d
    if YEAR.match(d or ''):
        return f'{d}-01-01'
    return '0000-00-00'


def release_medium(r):
    f = r.get('format') or {}
    kind = r.get('type')
    if kind == 'vinyl':
        return ' '.join(x for x in (f.get('size'), f.get('type')) if x)
    if kind == 'painting':
        return ', '.join(x for x in (f.get('type'), f.get('size')) if x)
    t = f.get('type') or ''
    return t if 'html' in t else f'{t} · html'.strip(' ·')


def collect():
    today = datetime.now(timezone.utc).date().isoformat()
    songs, poems, releases = load('songs.json'), load('poems.json'), load('releases.json')

    # songs a visible release explicitly unlinks ("link": false) stay unlinked here too
    unlinked = {t['slug'] for r in releases.values() if r.get('visible') is not False
                for t in (r.get('tracks') or []) if t.get('link') is False}

    works = []
    for slug, s in songs.items():
        if s.get('demo'):
            continue
        d = s.get('date') or ''
        if not (FULL_DATE.match(d) and d <= today):
            continue  # unreleased
        works.append({
            'key': f'song:{slug}', 'kind': 'song', 'title': s['title'], 'date': d,
            'medium': 'audio', 'weight': words(s.get('lyrics')),
            'href': None if slug in unlinked else f'/songs/{slug}.html',
        })

    for slug, p in poems.items():
        d = p.get('date') or ''
        if not FULL_DATE.match(d) or d > today:
            continue
        works.append({
            'key': f'poem:{slug}', 'kind': 'poem', 'title': p['title'], 'date': d,
            'medium': 'text', 'weight': words(p.get('text')),
            'href': f'/poems/{slug}.html',
        })

    for rid, r in releases.items():
        if r.get('visible') is False:
            continue
        # "published" = the day it went up on the site (paintings/pages carry only the
        # year they were made in "date"); fall back to "date", then format.date's year
        d = r.get('published') or r.get('date') or ''
        if not (FULL_DATE.match(d) or YEAR.match(d)):
            fd = (r.get('format') or {}).get('date') or ''
            d = fd if YEAR.match(fd) else ''
        kind = KIND.get(r.get('type'), 'release')
        weight = None
        if kind == 'record':
            weight = sum(words((songs.get(t['slug']) or {}).get('lyrics')) for t in r.get('tracks') or [])
        works.append({
            'key': f'release:{rid}', 'kind': kind, 'title': r['title'], 'date': d,
            'medium': release_medium(r), 'weight': weight or None,
            'href': f'/releases/{rid}.html', 'binary': r.get('binary'),
        })
    return works


def assign_numbers(works):
    reg = json.loads(NUMBERS.read_text(encoding='utf-8')) if NUMBERS.exists() else {
        '_comment': ('Permanent register numbers for songs and poems. Append-only: '
                     'scripts/build-register.py gives new works the next number (in date order) '
                     'and never changes or reuses an existing one. Releases use their catalogue binary.'),
        'next': 1, 'numbers': {}}
    nums = reg['numbers']
    new = sorted((w for w in works if 'binary' not in w and w['key'] not in nums),
                 key=lambda w: (sort_date(w['date']), w['kind'], w['title']))
    for w in new:
        nums[w['key']] = reg['next']
        reg['next'] += 1
    NUMBERS.write_text(json.dumps(reg, indent=2, ensure_ascii=False) + '\n', encoding='utf-8')
    for w in works:
        if 'binary' not in w:
            w['no'] = nums[w['key']]
    return len(new)


def cat_number(binary):
    return int(''.join('1' if c == '■' else '0' for c in binary or ''), 2) if binary else 0


def row(w, newest_release):
    esc = html.escape
    title = (f'<a href="{esc(w["href"])}">{esc(w["title"])}</a>' if w['href']
             else esc(w['title']))
    if 'binary' in w:
        no_html = f'<span class="cat" title="catalogue {cat_number(w["binary"])}">{esc(w["binary"])}</span>'
        no_sort = cat_number(w['binary'])          # releases sort first, by catalogue number
        group = 0
    else:
        no_html = f'{w["no"]:03d}'
        no_sort = w['no']
        group = 1
    weight = f'{w["weight"]}w' if w['weight'] else '—'
    cls = ' class="is-newest"' if w['key'] == newest_release else ''
    return (
        f'<tr{cls} data-key="{esc(w["key"])}" data-kind="{w["kind"]}" '
        f'data-no="{group}{no_sort:05d}" data-date="{sort_date(w["date"])}" '
        f'data-weight="{w["weight"] or 0}">'
        f'<td class="c-no">{no_html}</td>'
        f'<td class="c-date">{esc(w["date"] or "—")}</td>'
        f'<td class="c-kind">{w["kind"]}</td>'
        f'<td class="c-title">{title}</td>'
        f'<td class="c-medium">{esc(w["medium"])}</td>'
        f'<td class="c-weight">{weight}</td>'
        f'<td class="c-plays">—</td>'
        f'</tr>'
    )


def main():
    works = collect()
    added = assign_numbers(works)
    # newest first; on the same day a release sits above its songs
    works.sort(key=lambda w: (sort_date(w['date']), 'binary' in w, w['kind'], w['title']), reverse=True)
    releases = [w for w in works if 'binary' in w]
    newest = max(releases, key=lambda w: sort_date(w['date']))['key'] if releases else None

    rows = '\n'.join(row(w, newest) for w in works)
    page = INDEX.read_text(encoding='utf-8')
    page, n1 = re.subn(r'<!-- register:rows -->.*?<!-- /register:rows -->',
                       lambda m: f'<!-- register:rows -->\n{rows}\n<!-- /register:rows -->',
                       page, flags=re.S)
    page, n2 = re.subn(r'(<span id="entry-count"[^>]*>)\d*(</span>)',
                       lambda m: f'{m.group(1)}{len(works)}{m.group(2)}', page)
    assert n1 == 1 and n2 == 1, 'markers missing in index.html'
    INDEX.write_text(page, encoding='utf-8')
    kinds = {k: sum(w['kind'] == k for w in works) for k in ('song', 'poem', 'record', 'painting', 'page')}
    print(f'{len(works)} entries {kinds} · {added} new register numbers · newest release: {newest}')


if __name__ == '__main__':
    main()
