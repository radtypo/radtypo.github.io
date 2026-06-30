#!/usr/bin/env python3
"""Measure integrated LUFS + true peak for catalogue tracks and the Method test master.

Writes data/loudness.json. Run from the repo root (or anywhere — paths are resolved
relative to this script's parent dir).
"""

import json
import re
import subprocess
import sys
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parent.parent
DATA_PATH = REPO_ROOT / "data" / "loudness.json"

CATALOGUE_DIRS = [
    ("audio", REPO_ROOT / "audio"),
]
TEST_MASTER = REPO_ROOT / "album-staging" / "method-to-her-madness-mastertest-300626.mp3"

# Tracks excluded from the audit (kept out of the catalogue median + the page).
EXCLUDE = {
    "old-enough-to-handle-it.mp3",
    "the-most-generic-experience-ever.mp3",
}


def collect_files():
    items = []
    for folder, path in CATALOGUE_DIRS:
        if not path.is_dir():
            continue
        for f in sorted(path.glob("*.mp3")):
            if f.name in EXCLUDE:
                continue
            items.append((folder, f, False))
    if TEST_MASTER.exists():
        items.append(("album-staging", TEST_MASTER, True))
    else:
        print(f"warning: test master not found at {TEST_MASTER}", file=sys.stderr)
    return items


def measure_loudness(path: Path):
    """Run ffmpeg loudnorm in analysis mode and parse the JSON summary."""
    cmd = [
        "ffmpeg",
        "-nostdin",
        "-hide_banner",
        "-i", str(path),
        "-af", "loudnorm=I=-16:TP=-1.5:LRA=11:print_format=json",
        "-f", "null",
        "-",
    ]
    proc = subprocess.run(cmd, capture_output=True, text=True)
    # loudnorm prints the JSON summary to stderr at the end
    match = re.search(r"\{[^{}]*\"input_i\"[\s\S]*?\}", proc.stderr)
    if not match:
        raise RuntimeError(f"could not parse loudnorm output for {path.name}")
    data = json.loads(match.group(0))
    return float(data["input_i"]), float(data["input_tp"])


def measure_duration(path: Path):
    cmd = [
        "ffprobe",
        "-v", "error",
        "-show_entries", "format=duration",
        "-of", "default=noprint_wrappers=1:nokey=1",
        str(path),
    ]
    proc = subprocess.run(cmd, capture_output=True, text=True, check=True)
    return float(proc.stdout.strip())


def main():
    items = collect_files()
    print(f"scanning {len(items)} files…", flush=True)
    results = []
    for i, (folder, path, is_test) in enumerate(items, 1):
        label = path.name
        marker = " [TEST MASTER]" if is_test else ""
        print(f"  [{i}/{len(items)}] {folder}/{label}{marker}", flush=True)
        try:
            lufs, true_peak = measure_loudness(path)
            duration = measure_duration(path)
        except Exception as e:
            print(f"    ! failed: {e}", file=sys.stderr, flush=True)
            continue
        print(f"    lufs={lufs:.2f}  tp={true_peak:.2f}  dur={duration:.1f}s", flush=True)
        results.append({
            "file": label,
            "folder": folder,
            "lufs": round(lufs, 2),
            "truePeak": round(true_peak, 2),
            "duration": round(duration, 2),
            "isTestMaster": is_test,
        })
    DATA_PATH.parent.mkdir(parents=True, exist_ok=True)
    DATA_PATH.write_text(json.dumps(results, indent=2) + "\n")
    print(f"wrote {DATA_PATH.relative_to(REPO_ROOT)} ({len(results)} entries)")


if __name__ == "__main__":
    main()
