require('dotenv').config();
const express = require('express');
const path = require('path');
const fs = require('fs');
const { generateAlbum, regenerateSong } = require('./generator');

const app = express();
const PORT = 3000;

app.use(express.json({ limit: '5mb' }));
app.use(express.static(__dirname));

// Load lyrics pool from songs.json
function loadLyricsPool() {
  const songsPath = path.resolve(__dirname, '..', 'data', 'songs.json');
  if (!fs.existsSync(songsPath)) return [];
  const songs = JSON.parse(fs.readFileSync(songsPath, 'utf8'));
  return Object.entries(songs).map(([key, song]) => ({
    key,
    title: song.title,
    lyrics: song.lyrics || '',
  })).filter(s => s.lyrics);
}

// Generate album via SSE (songs stream in as they complete)
app.post('/api/generate', async (req, res) => {
  const { albumName, artists, mood, songCount, lyricsMode } = req.body;
  const lyricsPool = (lyricsMode !== 'new') ? loadLyricsPool() : [];

  res.setHeader('Content-Type', 'text/event-stream');
  res.setHeader('Cache-Control', 'no-cache');
  res.setHeader('Connection', 'keep-alive');
  res.flushHeaders();

  function send(event, data) {
    res.write(`event: ${event}\ndata: ${JSON.stringify(data)}\n\n`);
  }

  try {
    const album = await generateAlbum(
      {
        albumName: albumName || '',
        artists: artists || '',
        mood: mood || '',
        songCount: parseInt(songCount) || 10,
        lyricsMode: lyricsMode || 'new',
        lyricsPool,
      },
      (progress) => {
        if (progress.phase === 'song-complete') {
          send('song', { songIndex: progress.songIndex, song: progress.song });
        } else {
          send('status', { message: progress.message });
        }
      },
    );

    send('done', { title: album.title, artistReference: album.artistReference, styleBrief: album.styleBrief });
    res.end();
  } catch (err) {
    console.error('Generation error:', err);
    send('error', { message: err.message });
    res.end();
  }
});

// Regenerate single song
app.post('/api/regenerate-song', async (req, res) => {
  const { album, songIndex } = req.body;

  try {
    const song = await regenerateSong({ album, songIndex });
    res.json(song);
  } catch (err) {
    console.error('Regeneration error:', err);
    res.status(500).json({ error: err.message });
  }
});

// Save album as JSON
app.post('/api/save', (req, res) => {
  const { album } = req.body;
  const outDir = path.resolve(__dirname, 'generated-albums');
  if (!fs.existsSync(outDir)) fs.mkdirSync(outDir, { recursive: true });

  const slug = (album.title || 'untitled').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '');
  const timestamp = new Date().toISOString().slice(0, 10);
  const filename = `${slug}-${timestamp}.json`;
  fs.writeFileSync(path.join(outDir, filename), JSON.stringify(album, null, 2));
  res.json({ filename });
});

app.listen(PORT, () => {
  console.log(`album generator running at http://localhost:${PORT}`);
});
