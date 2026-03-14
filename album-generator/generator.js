const Anthropic = require('@anthropic-ai/sdk');

const MODEL = 'claude-sonnet-4-20250514';
const client = new Anthropic();

// Continue a response if the server-side tool loop hit its iteration limit
async function continueResponse(messages, tools, maxContinuations = 5) {
  let response = await client.messages.create({
    model: MODEL,
    max_tokens: 16000,
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
      max_tokens: 16000,
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

  // Extract text from response
  const textBlocks = response.content.filter(b => b.type === 'text');
  return textBlocks.map(b => b.text).join('\n');
}

// Phase 2: generate the album using research + lyrics pool
async function generateAlbumContent(params, research) {
  const { albumName, artists, mood, songCount, lyricsMode, lyricsPool } = params;

  let lyricsContext = '';
  if (lyricsMode === 'existing' && lyricsPool.length > 0) {
    lyricsContext = `\n\nEXISTING LYRICS POOL — draw from, remix, or reference these:\n${lyricsPool.map(s => `--- ${s.title} ---\n${s.lyrics}`).join('\n\n')}`;
  } else if (lyricsMode === 'blend' && lyricsPool.length > 0) {
    lyricsContext = `\n\nEXISTING LYRICS POOL — blend fragments from these with newly generated lyrics:\n${lyricsPool.map(s => `--- ${s.title} ---\n${s.lyrics}`).join('\n\n')}`;
  }

  const albumNameInstruction = albumName
    ? `Album title: "${albumName}"`
    : 'Generate an evocative album title that fits the mood and style.';

  const prompt = `You are a songwriter and producer creating a complete album concept.

REFERENCE ARTIST RESEARCH:
${research}

BRIEF:
${albumNameInstruction}
Reference artist(s): ${artists}
Stylistic mood: ${mood || 'not specified — infer from the reference artist research'}
Number of songs: ${songCount}
${lyricsContext}

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

For each song, output:
- title
- key (e.g. "E major", "D minor")
- tempo (BPM as integer)
- timeSignature (e.g. "4/4", "3/4", "6/8")
- capo (integer, 0 if none)
- moodNote (where this sits in the album arc, one sentence)
- chords (array of chord objects with "name" and "voicing" fields, e.g. {"name": "Em7", "voicing": "022030"})
- structure (array of section objects, each with "section" label like "Verse 1", "Chorus", etc., and "chords" as a string showing the chord progression for that section, and "strumming" note)
- leadTab (ASCII guitar tab for the main riff/hook, as a multi-line string)
- lyrics (full lyrics with [Verse 1], [Chorus], [Bridge] etc. labels)
- suggestedLength (e.g. "3:45")

Return ONLY valid JSON matching this exact structure:
{
  "title": "album title",
  "artistReference": "${artists}",
  "styleBrief": "the mood/style summary",
  "songs": [ { ...song objects... } ]
}`;

  const response = await client.messages.create({
    model: MODEL,
    max_tokens: 16000,
    messages: [{ role: 'user', content: prompt }],
  });

  const textBlocks = response.content.filter(b => b.type === 'text');
  const text = textBlocks.map(b => b.text).join('');

  // Extract JSON from response (may be wrapped in markdown code fence)
  const jsonMatch = text.match(/```(?:json)?\s*([\s\S]*?)```/) || [null, text];
  return JSON.parse(jsonMatch[1].trim());
}

async function generateAlbum(params) {
  console.log('Phase 1: researching', params.artists);
  const research = await researchArtist(params.artists);

  console.log('Phase 2: generating album');
  const album = await generateAlbumContent(params, research);

  return album;
}

async function regenerateSong(params) {
  const { album, songIndex, lyricsPool } = params;
  const song = album.songs[songIndex];

  // Get adjacent keys to avoid
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

  const textBlocks = response.content.filter(b => b.type === 'text');
  const text = textBlocks.map(b => b.text).join('');
  const jsonMatch = text.match(/```(?:json)?\s*([\s\S]*?)```/) || [null, text];
  return JSON.parse(jsonMatch[1].trim());
}

module.exports = { generateAlbum, regenerateSong };
