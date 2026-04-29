# Samples

This directory ships with the built site.

## Sample sourcing policy

Samples shipped in this repository must meet *one* of the following:

1. **Permissively licensed** — explicit CC0, CC-BY, MIT, public domain, or equivalent statement in the source repo or per-sample metadata.
2. **Open-source music ecosystem samples** — samples redistributed by reputable open-source music projects (TidalCycles, SuperDirt, Strudel, etc.) for ≥5 years without licensing challenge, with full attribution to the source repo, commit hash, and (where known) original author preserved in `NOTICES.md`.

Category 2 samples are not legally identical to permissively-licensed material. They are included on the basis that they are part of established open-source music infrastructure, that this project is non-commercial and educational in nature, and that any sample can be removed and replaced with synth fallback at any time.

If you are the original creator of any sample shipped in this repo and would like it removed, open an issue and it will be removed within 48 hours.

## Expected layout

One subdirectory per kit, files named by voice:

```
samples/
├── default/
│   ├── kick.wav
│   ├── snare.wav
│   ├── hat.wav
│   ├── tom.wav
│   └── clap.wav
├── idioteque/
├── daftpunky/
├── air/
├── prodigy/
├── phardrop/
├── soulquarians/
└── NOTICES.md     # per-sample attribution (required)
```

Kit keys must match the keys in `src/machines/phonetic-rhythms/modules/audio/kits.ts`. Populating a kit here also requires adding a `samples` field to that kit's definition in `kits.ts`.

## Loader behaviour

`src/machines/phonetic-rhythms/modules/audio/samples.ts` fetches the declared WAVs lazily — on first play and on kit change. If a voice's fetch or decode fails, that voice silently falls back to synth (a console message records it). The bass voice always stays synth since it needs per-vowel pitches.

All shipped WAVs are peak-normalised to −1 dBFS via ffmpeg so kits are level-matched against each other.
