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

// Generate album
app.post('/api/generate', async (req, res) => {
  const { albumName, artists, mood, songCount, lyricsMode } = req.body;
  const lyricsPool = (lyricsMode !== 'new') ? loadLyricsPool() : [];

  try {
    const album = await generateAlbum({
      albumName: albumName || '',
      artists: artists || '',
      mood: mood || '',
      songCount: parseInt(songCount) || 10,
      lyricsMode: lyricsMode || 'new',
      lyricsPool,
    });
    res.json(album);
  } catch (err) {
    console.error('Generation error:', err);
    res.status(500).json({ error: err.message });
  }
});

// Regenerate single song
app.post('/api/regenerate-song', async (req, res) => {
  const { album, songIndex } = req.body;

  try {
    const lyricsPool = loadLyricsPool();
    const song = await regenerateSong({ album, songIndex, lyricsPool });
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
