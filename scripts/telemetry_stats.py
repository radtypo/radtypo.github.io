#!/usr/bin/env python3
"""rad.typo live telemetry: Apache logs + Pi sensors + sky -> data/telemetry.json.

Runs on the Pi every 5 minutes (cron, as a user in the adm group so it can read
/var/log/apache2). Python 3 stdlib only, and light: one pass over the current and
previous access log, one sensor read, and an outside fetch at most every 15 minutes.

    */5 * * * * /usr/bin/python3 /var/www/html/scripts/telemetry_stats.py >/dev/null 2>&1

    python3 scripts/telemetry_stats.py --backfill   # once: also read the .N.gz rotations
                                                     # and add every past day to history

Outputs (generated on the Pi, never committed; written atomically):
    data/telemetry.json           rewritten every run, read by telemetry.html
    data/telemetry-history.json   persistent: one record per finished day, a rolling
                                  24h list of sensor samples, the last good sky data

Privacy: an IP or user agent is never written anywhere. Visitors are counted by
hashing IP + user agent with a random salt that exists only in memory for one run
(every run recomputes from the logs, so nothing linking visits survives it).
My own visits go to me_access.log (Apache, via the rt_me cookie); IPs in
scripts/telemetry_exclude.txt, private/Tailscale ranges and bots are skipped too.
"""

import argparse
import gzip
import hashlib
import ipaddress
import json
import math
import os
import re
import subprocess
import sys
import tempfile
import urllib.request
from datetime import datetime, timedelta, timezone
from pathlib import Path
from urllib.parse import unquote
from zoneinfo import ZoneInfo

# ── settings ─────────────────────────────────────────────────────────────
ESTIMATED_WATTS = 3.4          # estimated average draw of the Pi (no meter yet): all energy figures are "est."
LAT, LON = 55.014662, -7.302903
LOCAL = ZoneInfo('Europe/London')
LOG_NAME = 'other_vhosts_access.log'
SKY_EVERY = timedelta(minutes=14)   # cron runs every 5 min, so this means "every 15 minutes"
NOW_WINDOW = timedelta(minutes=5)
PLAY_WINDOW = timedelta(minutes=30)
SAMPLE_KEEP = timedelta(hours=25)   # rolling sensor samples (a little over 24h so a finished day is whole)

ROOT = Path(__file__).resolve().parent.parent
EXCLUDE_FILE = ROOT / 'scripts' / 'telemetry_exclude.txt'
METEO_URL = ('https://api.open-meteo.com/v1/forecast'
             f'?latitude={LAT}&longitude={LON}&timezone=Europe%2FLondon'
             '&current=temperature_2m,apparent_temperature,weather_code,wind_speed_10m,'
             'wind_direction_10m,relative_humidity_2m,cloud_cover'
             '&hourly=shortwave_radiation,cloud_cover&daily=sunrise,sunset'
             '&past_days=1&forecast_days=1')
KP_URL = 'https://services.swpc.noaa.gov/products/noaa-planetary-k-index.json'

PRIVATE = [ipaddress.ip_network(n) for n in
           ('10.0.0.0/8', '192.168.0.0/16', '100.64.0.0/10', '127.0.0.0/8', '::1/128', 'fc00::/7', 'fe80::/10')]
BOTS = re.compile(r'bot|crawl|spider|slurp|curl|wget|python|headless|monitor|preview|'
                  r'facebookexternalhit|scan|gptbot|claudebot|ccbot', re.I)
ASSET_EXT = {'css', 'js', 'woff', 'woff2', 'ttf', 'otf', 'eot',
             'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'ico', 'avif'}
# pages that exist but aren't public (gitignored internal tools) or are templates
NOT_PUBLIC = {'/claude-test.html', '/server-status.html', '/book-builder.html', '/album-maker.html',
              '/song-template.html', '/poems-template.html', '/release-template.html'}

# vhost_combined: %v:%p %h %l %u %t "%r" %>s %O "%{Referer}i" "%{User-Agent}i"
Q = r'"((?:[^"\\]|\\.)*)"'
LINE = re.compile(r'^(\S+) (\S+) \S+ \S+ \[([^\]]+)\] ' + Q + r' (\d{3}) (\S+) ' + Q + ' ' + Q)
MONTHS = {m: i for i, m in enumerate(
    ('Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'), 1)}
SUPPLY_RANK = {None: -1, 'ok': 0, 'under-voltage-since-boot': 1, 'under-voltage-now': 2}
MOON_NAMES = ('new', 'waxing crescent', 'first quarter', 'waxing gibbous',
              'full', 'waning gibbous', 'last quarter', 'waning crescent')


# ── small helpers ────────────────────────────────────────────────────────
def load_json(path, default):
    try:
        return json.loads(Path(path).read_text(encoding='utf-8'))
    except (OSError, ValueError):
        return default


def write_atomic(path, obj):
    path = Path(path)
    fd, tmp = tempfile.mkstemp(dir=path.parent, prefix='.' + path.name, suffix='.tmp')
    try:
        with os.fdopen(fd, 'w', encoding='utf-8') as f:
            json.dump(obj, f, ensure_ascii=False, separators=(',', ':'))
        os.chmod(tmp, 0o644)
        os.replace(tmp, path)
    except BaseException:
        try:
            os.unlink(tmp)
        except OSError:
            pass
        raise


def parse_time(s):
    """'18/Nov/2026:14:20:02 +0000' -> aware UTC datetime (much faster than strptime)."""
    off = (int(s[22:24]) * 3600 + int(s[24:26]) * 60) * (1 if s[21] == '+' else -1)
    return (datetime(int(s[7:11]), MONTHS[s[3:6]], int(s[0:2]),
                     int(s[12:14]), int(s[15:17]), int(s[18:20]), tzinfo=timezone.utc)
            - timedelta(seconds=off))


def r1(x):
    return round(x, 1)


def mb(b):
    return round(b / 1e6, 1)


def compass(deg):
    if deg is None:
        return None
    return ('n', 'ne', 'e', 'se', 's', 'sw', 'w', 'nw')[int((deg % 360) / 45 + 0.5) % 8]


def moon(now):
    """Age (days since new moon) from a reference new moon and the mean synodic month."""
    synodic = 29.530588853
    ref = datetime(2000, 1, 6, 18, 14, tzinfo=timezone.utc)
    age = ((now - ref).total_seconds() / 86400) % synodic
    return {'age_days': r1(age), 'name': MOON_NAMES[int(age / synodic * 8 + 0.5) % 8]}


# ── the site's own rules (released songs, public pages) ─────────────────
class Site:
    def __init__(self, root, today_utc):
        songs = load_json(root / 'data' / 'songs.json', {})
        poems = load_json(root / 'data' / 'poems.json', {})
        releases = load_json(root / 'data' / 'releases.json', {})
        full = re.compile(r'^\d{4}-\d{2}-\d{2}$')
        # released = full YYYY-MM-DD on or before today (UTC), not "demo": true (same rule as the site)
        # ("liveFrom", if set, switches a song on before its shown date)
        self.released = {slug: s['title'] for slug, s in songs.items()
                         if not s.get('demo') and full.match(s.get('liveFrom') or s.get('date') or '')
                         and (s.get('liveFrom') or s['date']) <= today_utc}
        self.audio = {}   # "/audio/<file>" -> slug, released songs only
        for slug in self.released:
            f = songs[slug].get('audio')
            if f:
                self.audio['/audio/' + f] = slug
        self.poems = set(poems)
        self.releases = {k for k, r in releases.items() if r.get('visible') is not False}
        self.root = root

    def page(self, path):
        """Public page path for the 'recent' list (without .html), or None."""
        if path in ('/', '/index.html'):
            return '/'
        if not path.endswith('.html') or path in NOT_PUBLIC or '..' in path:
            return None
        m = re.match(r'^/(songs|poems|releases)/([^/]+)\.html$', path)
        if m:
            kind, slug = m.groups()
            ok = {'songs': self.released, 'poems': self.poems, 'releases': self.releases}[kind]
            return path[:-5] if slug in ok else None
        return path[:-5] if (self.root / path.lstrip('/')).is_file() else None


# ── log reading ──────────────────────────────────────────────────────────
def log_files(log_dir, backfill, need_yesterday):
    log_dir = Path(log_dir)
    files = []
    if not backfill and need_yesterday:
        # logrotate runs ~06:25, so after that the start of yesterday is already in .2.gz
        two = log_dir / (LOG_NAME + '.2.gz')
        if two.exists():
            files.append(two)
    if backfill:
        gz = []
        for p in log_dir.glob(LOG_NAME + '.*.gz'):
            m = re.match(re.escape(LOG_NAME) + r'\.(\d+)\.gz$', p.name)
            if m:
                gz.append((int(m.group(1)), p))
        files += [p for _, p in sorted(gz, reverse=True)]      # oldest first
    for name in (LOG_NAME + '.1', LOG_NAME):
        if (log_dir / name).exists():
            files.append(log_dir / name)
    return files


def read_lines(path):
    opener = gzip.open if path.suffix == '.gz' else open
    with opener(path, 'rt', encoding='utf-8', errors='replace') as f:
        yield from f


class Day:
    __slots__ = ('visitors', 'v_hour', 'plays', 'p_hour', 'by_song', 'bytes', 'audio_bytes', 'b_hour')

    def __init__(self):
        self.visitors = set()
        self.v_hour = [set() for _ in range(24)]
        self.plays = 0
        self.p_hour = [0] * 24
        self.by_song = {}
        self.bytes = 0
        self.audio_bytes = 0
        self.b_hour = [0] * 24


def scan(files, site, exclude, now):
    salt = os.urandom(16)                      # lives only for this run, never stored
    ip_ok, ua_ok = {}, {}
    days, last_play, recent = {}, {}, []
    here, listening = set(), {}
    earliest = None
    since_now = now - NOW_WINDOW

    for path in files:
        for line in read_lines(path):
            m = LINE.match(line)
            if not m:
                continue
            _, ip, ts, req, status, size, _, ua = m.groups()
            try:
                t = parse_time(ts)
            except (ValueError, KeyError, IndexError):
                continue
            if earliest is None or t < earliest:
                earliest = t
            if status not in ('200', '206'):
                continue
            ok = ip_ok.get(ip)
            if ok is None:
                try:
                    a = ipaddress.ip_address(ip)
                    ok = ip not in exclude and not any(a in n for n in PRIVATE)
                except ValueError:
                    ok = False
                ip_ok[ip] = ok
            if not ok:
                continue
            ok = ua_ok.get(ua)
            if ok is None:
                ok = ua_ok[ua] = not BOTS.search(ua)
            if not ok:
                continue
            parts = req.split(' ')
            if len(parts) < 2 or parts[0] != 'GET':
                continue
            p = unquote(parts[1].split('?', 1)[0].split('#', 1)[0])

            who = hashlib.sha256(salt + ip.encode() + b'\0' + ua.encode()).digest()[:12]
            lt = t.astimezone(LOCAL)
            d = days.get(lt.date())
            if d is None:
                d = days[lt.date()] = Day()
            h = lt.hour
            n = int(size) if size.isdigit() else 0
            d.bytes += n
            d.b_hour[h] += n
            ext = p.rsplit('.', 1)[-1].lower() if '.' in p.rsplit('/', 1)[-1] else ''
            if ext not in ASSET_EXT:
                d.visitors.add(who)
                d.v_hour[h].add(who)
            if t >= since_now:
                here.add(who)

            if p.startswith('/audio/'):
                d.audio_bytes += n
                slug = site.audio.get(p)
                if slug:
                    if t >= since_now:
                        listening[who] = slug
                    k = (who, slug)
                    prev = last_play.get(k)
                    if prev is None or t - prev >= PLAY_WINDOW or t < prev - PLAY_WINDOW:
                        last_play[k] = t
                        d.plays += 1
                        d.p_hour[h] += 1
                        d.by_song[slug] = d.by_song.get(slug, 0) + 1
                        recent.append((t, 'play', slug))
            elif ext not in ASSET_EXT:
                page = site.page(p)
                if page and status == '200':
                    recent.append((t, 'visit', page))
            if len(recent) > 200:
                recent.sort(key=lambda e: e[0])
                del recent[:-50]

    recent.sort(key=lambda e: e[0])
    return days, recent[-10:], here, listening, earliest


# ── sensors ──────────────────────────────────────────────────────────────
def read_cpu():
    try:
        return r1(int(Path('/sys/class/thermal/thermal_zone0/temp').read_text().strip()) / 1000)
    except (OSError, ValueError):
        return None


def read_supply():
    try:
        out = subprocess.run(['vcgencmd', 'get_throttled'], capture_output=True, text=True, timeout=5).stdout
        bits = int(out.strip().split('=')[1], 16)
    except (OSError, subprocess.SubprocessError, ValueError, IndexError):
        return None
    if bits & 0x1:
        return 'under-voltage-now'
    if bits & 0x10000:
        return 'under-voltage-since-boot'
    return 'ok'


def read_battery(out_dir):
    p = load_json(Path(out_dir) / 'power.json', None)
    if not isinstance(p, dict):
        return None
    return {k: p.get(k) for k in ('battery_pct', 'volts', 'charging')}


# ── sky (fetched here so visitors' browsers never call outside services) ─
def fetch_json(url):
    req = urllib.request.Request(url, headers={'User-Agent': 'radtypo-telemetry/1.0'})
    with urllib.request.urlopen(req, timeout=12) as r:
        return json.loads(r.read().decode('utf-8'))


def update_sky(hist, now):
    sky = hist.setdefault('sky', {})
    last = hist.get('sky_fetched_at')
    if last and now - datetime.fromisoformat(last) < SKY_EVERY:
        return
    hist['sky_fetched_at'] = now.isoformat(timespec='seconds')
    failed = False
    try:
        m = fetch_json(METEO_URL)
        cur, hourly, daily = m['current'], m['hourly'], m['daily']
        sky['meteo'] = {
            'temp': cur.get('temperature_2m'), 'feels': cur.get('apparent_temperature'),
            'weather_code': cur.get('weather_code'), 'wind_kmh': cur.get('wind_speed_10m'),
            'wind_deg': cur.get('wind_direction_10m'), 'humidity': cur.get('relative_humidity_2m'),
            'cloud_pct': cur.get('cloud_cover'),
            'hourly': {t: [r, c] for t, r, c in zip(hourly['time'], hourly['shortwave_radiation'],
                                                   hourly['cloud_cover'])},
            'daily': {d: [sr, ss] for d, sr, ss in zip(daily['time'], daily['sunrise'], daily['sunset'])},
            'at': now.isoformat(timespec='seconds')}
    except Exception:
        failed = True
    try:
        rows = fetch_json(KP_URL)
        kp = {}
        for row in rows:
            if isinstance(row, dict):
                tag, val = row.get('time_tag'), row.get('Kp')
            else:                                  # older array-of-arrays format
                tag, val = row[0], row[1]
            try:
                kp[str(tag).replace(' ', 'T')[:13]] = float(val)
            except (TypeError, ValueError):
                continue
        if kp:
            sky['kp'] = {'values': kp, 'at': now.isoformat(timespec='seconds')}
        else:
            failed = True
    except Exception:
        failed = True
    sky['stale'] = failed


def sky_output(hist, now):
    sky = hist.get('sky') or {}
    lt = now.astimezone(LOCAL)
    today = lt.date().isoformat()
    m = sky.get('meteo') or {}
    out = {k: m.get(k) for k in ('temp', 'feels', 'weather_code', 'wind_kmh', 'humidity', 'cloud_pct')}
    out['wind_dir'] = compass(m.get('wind_deg'))
    sr, ss = (m.get('daily') or {}).get(today, [None, None])
    out['sunrise'], out['sunset'] = (sr[11:16] if sr else None), (ss[11:16] if ss else None)
    hourly = m.get('hourly') or {}
    rad, cloud = [], []
    for h in range(24):
        v = hourly.get(f'{today}T{h:02d}:00')
        measured = h <= lt.hour
        rad.append({'v': v[0] if v else None, 'measured': measured})
        cloud.append({'v': v[1] if v else None, 'measured': measured})
    out['radiation_by_hour'], out['cloud_by_hour'] = rad, cloud
    kp = (sky.get('kp') or {}).get('values') or {}
    utc_day = now.astimezone(timezone.utc).date().isoformat()
    out['kp_today'] = [kp.get(f'{utc_day}T{h:02d}') for h in range(0, 24, 3)]
    out['kp_now'] = kp[max(kp)] if kp else None
    out['moon'] = moon(now)
    updated = m.get('at')
    # stale: the last fetch failed, or the good values are old (e.g. the network was down)
    out['stale'] = bool(sky.get('stale')) or not updated or \
        now - datetime.fromisoformat(updated) > timedelta(minutes=45)
    out['updated_at'] = updated
    return out


# ── main ─────────────────────────────────────────────────────────────────
def day_record(date, d, samples):
    s = [x for x in samples if datetime.fromtimestamp(x[0], LOCAL).date() == date]
    cpus = [x[1] for x in s if x[1] is not None]
    worst = max((x[2] for x in s), key=lambda v: SUPPLY_RANK.get(v, -1), default=None)
    return {
        'date': date.isoformat(),
        'visitors': len(d.visitors) if d else 0,
        'plays': d.plays if d else 0,
        'plays_by_song': dict(sorted((d.by_song if d else {}).items())),
        'data_sent_mb': mb(d.bytes) if d else 0,
        'cpu_avg': r1(sum(cpus) / len(cpus)) if cpus else None,
        'cpu_max': max(cpus) if cpus else None,
        'supply_worst': worst,
        'energy_wh_est': r1(ESTIMATED_WATTS * 24),
    }


def top(by_song, titles, n):
    rows = sorted(by_song.items(), key=lambda kv: (-kv[1], kv[0]))[:n]
    return [{'slug': s, 'title': titles.get(s, s), 'plays': c} for s, c in rows]


def main():
    ap = argparse.ArgumentParser(description=__doc__.split('\n')[0])
    ap.add_argument('--backfill', action='store_true', help='also read the .N.gz rotations; add all past days')
    ap.add_argument('--logs', default='/var/log/apache2')
    ap.add_argument('--out', default=str(ROOT / 'data'))
    ap.add_argument('--now', help='pretend time (ISO, for testing)')
    ap.add_argument('--no-fetch', action='store_true', help="don't fetch sky data (testing)")
    a = ap.parse_args()

    now = datetime.fromisoformat(a.now).astimezone(timezone.utc) if a.now else datetime.now(timezone.utc)
    lt = now.astimezone(LOCAL)
    today = lt.date()
    out_dir = Path(a.out)
    hist_path = out_dir / 'telemetry-history.json'
    hist = load_json(hist_path, {})
    hist.setdefault('days', [])

    site = Site(ROOT, now.date().isoformat())
    try:
        exclude = {l.strip() for l in EXCLUDE_FILE.read_text().splitlines()
                   if l.strip() and not l.startswith('#')}
    except OSError:
        exclude = set()
    need_yday = (today - timedelta(days=1)).isoformat() not in {r['date'] for r in hist['days']}
    files = log_files(a.logs, a.backfill, need_yday)
    days, recent, here, listening, earliest = scan(files, site, exclude, now)

    # sensors: one sample per run, kept for ~24h
    cpu, supply, battery = read_cpu(), read_supply(), read_battery(out_dir)
    samples = [x for x in hist.get('samples', []) if now.timestamp() - x[0] <= SAMPLE_KEEP.total_seconds()]
    samples.append([int(now.timestamp()), cpu, supply,
                    battery.get('battery_pct') if battery else None,
                    battery.get('charging') if battery else None])

    # finished days -> history (once; never rewritten or deleted). A normal run only adds a
    # day the logs fully cover; --backfill adds every past day it finds.
    have = {r['date'] for r in hist['days']}
    for date in sorted(days):
        if date >= today or date.isoformat() in have:
            continue
        start = datetime.combine(date, datetime.min.time(), LOCAL)
        if a.backfill or (earliest and earliest <= start):
            hist['days'].append(day_record(date, days[date], samples))
    # a quiet day with no counted requests still gets a (zero) record once it's over
    yday = today - timedelta(days=1)
    if (yday.isoformat() not in {r['date'] for r in hist['days']} and hist['days']
            and earliest and earliest <= datetime.combine(yday, datetime.min.time(), LOCAL)):
        hist['days'].append(day_record(yday, days.get(yday), samples))
    hist['days'].sort(key=lambda r: r['date'])

    if not a.no_fetch:
        update_sky(hist, now)
    hist['samples'] = samples

    # ── today ──
    d = days.get(today) or Day()
    hours = (lt - lt.replace(hour=0, minute=0, second=0, microsecond=0)).total_seconds() / 3600
    energy = ESTIMATED_WATTS * hours
    titles = site.released

    cpu_by_hour = []
    for h in range(24):
        v = [x[1] for x in samples if x[1] is not None
             and now.timestamp() - x[0] <= 86400 and datetime.fromtimestamp(x[0], LOCAL).hour == h]
        cpu_by_hour.append(r1(sum(v) / len(v)) if v else None)

    if battery is not None:
        by_hour, overnight = [], []
        night_from = datetime.combine(today - timedelta(days=1), datetime.min.time(), LOCAL).replace(hour=18)
        night_to = datetime.combine(today, datetime.min.time(), LOCAL).replace(hour=9)
        for h in range(24):
            hs = [x for x in samples if x[3] is not None
                  and datetime.fromtimestamp(x[0], LOCAL).date() == today
                  and datetime.fromtimestamp(x[0], LOCAL).hour == h]
            by_hour.append({'pct': r1(sum(x[3] for x in hs) / len(hs)) if hs else None,
                            'charging': any(x[4] for x in hs) if hs else None})
        overnight = [x[3] for x in samples if x[3] is not None
                     and night_from.timestamp() <= x[0] <= night_to.timestamp()]
        battery = dict(battery, by_hour=by_hour, overnight_low=min(overnight) if overnight else None)

    all_by_song = {}
    for r in hist['days']:
        for s, c in r.get('plays_by_song', {}).items():
            all_by_song[s] = all_by_song.get(s, 0) + c
    for s, c in d.by_song.items():
        all_by_song[s] = all_by_song.get(s, 0) + c

    telemetry = {
        'generated_at': now.astimezone(LOCAL).isoformat(timespec='seconds'),
        'here_now': len(here),
        'listening_now': [{'slug': s, 'title': titles.get(s, s)} for s in listening.values()],
        'today': {
            'date': today.isoformat(),
            'visitors': len(d.visitors),
            'plays': d.plays,
            'data_sent_mb': mb(d.bytes),
            'audio_share': round(d.audio_bytes / d.bytes, 2) if d.bytes else 0,
            'visitors_by_hour': [len(s) for s in d.v_hour],
            'plays_by_hour': d.p_hour,
            'data_mb_by_hour': [mb(b) for b in d.b_hour],
            'top_songs': top(d.by_song, titles, 5),
            'energy_wh_est': r1(energy),
            'wh_per_visit_est': round(energy / max(len(d.visitors), 1), 2),
            'hours_since_midnight': round(hours, 2),
        },
        'estimated_watts': ESTIMATED_WATTS,
        'since': hist['days'][0]['date'] if hist['days'] else today.isoformat(),
        'all_time': {
            'visitors': sum(r['visitors'] for r in hist['days']) + len(d.visitors),
            'plays': sum(r['plays'] for r in hist['days']) + d.plays,
            'top_songs': top(all_by_song, titles, 10),
        },
        'recent': [dict({'time': t.astimezone(LOCAL).isoformat(timespec='seconds'), 'type': kind},
                        **({'slug': x, 'title': titles.get(x, x)} if kind == 'play' else {'path': x}))
                   for t, kind, x in reversed(recent)],
        'cpu': cpu,
        'cpu_by_hour': cpu_by_hour,
        'supply': {'status': supply, 'checked_at': now.astimezone(LOCAL).isoformat(timespec='seconds')},
        'sky': sky_output(hist, now),
        'battery': battery,
    }

    write_atomic(hist_path, hist)
    write_atomic(out_dir / 'telemetry.json', telemetry)
    if a.backfill:
        print(f"since {telemetry['since']} · {len(hist['days'])} days in history")
        print(f"all-time visitors {telemetry['all_time']['visitors']} · plays {telemetry['all_time']['plays']}")
        for i, s in enumerate(telemetry['all_time']['top_songs'], 1):
            print(f"{i:>3}  {s['title']:<50} {s['plays']}")


if __name__ == '__main__':
    sys.exit(main())
