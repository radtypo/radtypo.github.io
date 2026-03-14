const Anthropic = require('@anthropic-ai/sdk');

const MODEL = 'claude-sonnet-4-20250514';
const BATCH_SIZE = 3;
const BATCH_DELAY_MS = 5000;
const client = new Anthropic();

function sleep(ms) {
  return new Promise(resolve => setTimeout(resolve, ms));
}

function extractJSON(text) {
  const match = text.match(/```(?:json)?\s*([\s\S]*?)```/) || [null, text];
  return JSON.parse(match[1].trim());
}

function extractText(response) {
  return response.content.filter(b => b.type === 'text').map(b => b.text).join('');
}

// Continue a response if the server-side tool loop hit its iteration limit
async function continueResponse(messages, tools, maxContinuations = 5) {
  let response = await client.messages.create({
    model: MODEL,
    max_tokens: 8000,
    tools,
    messages,
  });

  let continuations = 0;
  while (response.stop_reason === 'pause_turn' && continuations < maxContinuations) {
    continuations++;
    messages = [
      ...messages,
      { role: 'assistant', content: response.content },
    ];
    response = await client.messages.create({
      model: MODEL,
      max_tokens: 8000,
      tools,
      messages,
    });
  }

  return response;
}

// Phase 1: research the reference artist via web search
async function researchArtist(artists) {
  const tools = [
    { type: 'web_search_20250305', name: 'web_search' },
  ];

  const prompt = `Research the musical characteristics of: ${artists}

I need concrete, specific data for songwriting reference:
- Typical BPMs and time signatures used
- Common keys and modes
- Chord vocabulary (specific voicings, e.g. sus2, add9, power chords, open chords)
- Typical song structures (verse/chorus ratios, bridge usage, intros, outros)
- Guitar tone and playing style (strumming patterns, fingerpicking, palm muting, etc.)
- Typical tunings and capo usage
- Lyrical themes and phrasing style
- Dynamic range and how energy builds/drops across songs

Return your findings as a detailed summary. Be specific — cite actual songs, chords, and techniques where possible.`;

  const response = await continueResponse(
    [{ role: 'user', content: prompt }],
    tools,
  );

  return extractText(response);
}

// Phase 2: plan the album outline (title + per-song skeleton)
async function planAlbum(params, research) {
  const { albumName, artists, mood, songCount } = params;

  const albumNameInstruction = albumName
    ? `Album title: "${albumName}"`
    : 'Generate an evocative album title that fits the mood and style.';

  const prompt = `You are a songwriter and producer planning an album.

REFERENCE ARTIST RESEARCH:
${research}

BRIEF:
${albumNameInstruction}
Reference artist(s): ${artists}
Stylistic mood: ${mood || 'not specified — infer from the reference artist research'}
Number of songs: ${songCount}

ALBUM ARC REQUIREMENTS:
- Track 1: Mid-tempo opener, sets the tone, anthemic or inviting
- Tracks 2-3: Build energy, driving rhythms
- Track 4: Peak intensity or a standout single
- Track 5: Shift — change of pace, texture, or key centre
- Track 6: Emotional centrepiece — the most vulnerable or intense moment
- Track 7-8: Recovery and variation, explore different feels
- Track ${songCount - 1}: The quiet one — stripped back, intimate
- Track ${songCount}: The closer — resolution, catharsis, or open-ended

Each song MUST differ in key, tempo, and feel. No two consecutive songs in the same key.

Return ONLY valid JSON with this structure:
{
  "title": "album title",
  "artistReference": "${artists}",
  "styleBrief": "one-sentence mood/style summary",
  "songOutlines": [
    {
      "trackNumber": 1,
      "title": "song title",
      "key": "E major",
      "tempo": 120,
      "timeSignature": "4/4",
      "capo": 0,
      "moodNote": "one sentence describing this track's role in the album arc"
    }
  ]
}`;

  const response = await client.messages.create({
    model: MODEL,
    max_tokens: 4000,
    messages: [{ role: 'user', content: prompt }],
  });

  return extractJSON(extractText(response));
}

// Phase 3: generate a batch of full songs from their outlines
async function generateSongBatch(outlines, albumPlan, research, params) {
  const { artists, mood, lyricsMode, lyricsPool } = params;

  let lyricsContext = '';
  if (lyricsMode === 'existing' && lyricsPool.length > 0) {
    lyricsContext = `\n\nEXISTING LYRICS POOL — draw from, remix, or reference these:\n${lyricsPool.map(s => `--- ${s.title} ---\n${s.lyrics}`).join('\n\n')}`;
  } else if (lyricsMode === 'blend' && lyricsPool.length > 0) {
    lyricsContext = `\n\nEXISTING LYRICS POOL — blend fragments from these with newly generated lyrics:\n${lyricsPool.map(s => `--- ${s.title} ---\n${s.lyrics}`).join('\n\n')}`;
  }

  const trackList = outlines.map(o =>
    `Track ${o.trackNumber}: "${o.title}" — ${o.key}, ${o.tempo} bpm, ${o.timeSignature}, capo ${o.capo}, arc: ${o.moodNote}`
  ).join('\n');

  const prompt = `You are a songwriter and producer. Generate FULL song details for these ${outlines.length} tracks.

REFERENCE ARTIST RESEARCH:
${research}

ALBUM: "${albumPlan.title}"
Reference: ${artists}
Style: ${mood || albumPlan.styleBrief}

TRACKS TO GENERATE:
${trackList}
${lyricsContext}

For each song, output ALL of:
- title, key, tempo (integer), timeSignature, capo (integer), moodNote
- chords: array of {"name": "Em7", "voicing": "022030"}
- structure: array of {"section": "Verse 1", "chords": "Em - G - D - C", "strumming": "down-down-up-down-up"}
- leadTab: ASCII guitar tab for main riff/hook (multi-line string)
- lyrics: full lyrics with [Verse 1], [Chorus], [Bridge] labels
- suggestedLength: e.g. "3:45"

Return ONLY a valid JSON array of ${outlines.length} song objects. No wrapping object — just the array.`;

  const response = await client.messages.create({
    model: MODEL,
    max_tokens: 8000,
    messages: [{ role: 'user', content: prompt }],
  });

  return extractJSON(extractText(response));
}

// Main: generate album with batched song generation and progress callback
async function generateAlbum(params, onProgress) {
  onProgress && onProgress({ phase: 'research', message: 'researching reference artists...' });
  const research = await researchArtist(params.artists);

  onProgress && onProgress({ phase: 'plan', message: 'planning album outline...' });
  await sleep(BATCH_DELAY_MS);
  const albumPlan = await planAlbum(params, research);

  const allSongs = [];
  const outlines = albumPlan.songOutlines;

  for (let i = 0; i < outlines.length; i += BATCH_SIZE) {
    const batch = outlines.slice(i, i + BATCH_SIZE);
    const batchNum = Math.floor(i / BATCH_SIZE) + 1;
    const totalBatches = Math.ceil(outlines.length / BATCH_SIZE);

    onProgress && onProgress({
      phase: 'songs',
      message: `generating songs ${i + 1}–${Math.min(i + BATCH_SIZE, outlines.length)} of ${outlines.length} (batch ${batchNum}/${totalBatches})...`,
    });

    if (i > 0) {
      await sleep(BATCH_DELAY_MS);
    }

    const songs = await generateSongBatch(batch, albumPlan, research, params);
    const songsArray = Array.isArray(songs) ? songs : [songs];

    for (const song of songsArray) {
      allSongs.push(song);
      onProgress && onProgress({
        phase: 'song-complete',
        songIndex: allSongs.length - 1,
        song,
      });
    }
  }

  return {
    title: albumPlan.title,
    artistReference: albumPlan.artistReference,
    styleBrief: albumPlan.styleBrief,
    songs: allSongs,
  };
}

async function regenerateSong(params) {
  const { album, songIndex } = params;
  const song = album.songs[songIndex];

  const prevKey = songIndex > 0 ? album.songs[songIndex - 1].key : null;
  const nextKey = songIndex < album.songs.length - 1 ? album.songs[songIndex + 1].key : null;
  const avoidKeys = [prevKey, nextKey].filter(Boolean);

  const prompt = `Regenerate song ${songIndex + 1} for the album "${album.title}".

Reference artist: ${album.artistReference}
Style: ${album.styleBrief}
Current song's arc position: ${song.moodNote}

Keys to AVOID (used by adjacent tracks): ${avoidKeys.join(', ') || 'none'}

Generate a completely different song for this position in the album. Different title, key, tempo, chords, and lyrics. Keep the same arc position/mood role.

Return ONLY valid JSON for one song object with these fields:
title, key, tempo, timeSignature, capo, moodNote, chords, structure, leadTab, lyrics, suggestedLength

Use the same format as this example:
${JSON.stringify(song, null, 2)}`;

  const response = await client.messages.create({
    model: MODEL,
    max_tokens: 8000,
    messages: [{ role: 'user', content: prompt }],
  });

  return extractJSON(extractText(response));
}

module.exports = { generateAlbum, regenerateSong };
