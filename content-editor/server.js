const express = require('express');
const fs = require('fs');
const path = require('path');

const app = express();
const PORT = 3333;

app.use(express.json({ limit: '10mb' }));

// --- Resolve JSON paths ---

function findJsonFile(name, flagPath) {
  if (flagPath && fs.existsSync(flagPath)) return path.resolve(flagPath);
  const candidates = [
    path.resolve(__dirname, '..', 'data', `${name}.json`),
    path.resolve(__dirname, '..', `${name}.json`),
    path.resolve(__dirname, '..', 'src', 'data', `${name}.json`),
  ];
  for (const c of candidates) {
    if (fs.existsSync(c)) return c;
  }
  return null;
}

// Parse --poems and --songs flags
const args = process.argv.slice(2);
let flagPoems = null, flagSongs = null;
for (let i = 0; i < args.length; i++) {
  if (args[i] === '--poems' && args[i + 1]) flagPoems = args[++i];
  if (args[i] === '--songs' && args[i + 1]) flagSongs = args[++i];
}

const poemsPath = findJsonFile('poems', flagPoems);
const songsPath = findJsonFile('songs', flagSongs);

if (!poemsPath && !songsPath) {
  console.error('could not find poems.json or songs.json — use --poems / --songs flags');
  process.exit(1);
}

console.log(`poems: ${poemsPath || '(not found)'}`);
console.log(`songs: ${songsPath || '(not found)'}`);

// --- Migrate legacy backup ---
const legacyBackup = '/var/www/html/data/writing.json.backup';
const backupDir = path.join(__dirname, 'backups');
if (fs.existsSync(legacyBackup)) {
  if (!fs.existsSync(backupDir)) fs.mkdirSync(backupDir);
  fs.renameSync(legacyBackup, path.join(backupDir, 'writing.json.backup'));
  console.log('migrated legacy backup from /var/www/html/data/');
}

// --- Routes ---

app.get('/admin', (req, res) => {
  res.sendFile(path.join(__dirname, 'admin.html'));
});

app.get('/api/content', (req, res) => {
  const result = {};
  if (poemsPath) result.poems = JSON.parse(fs.readFileSync(poemsPath, 'utf8'));
  if (songsPath) result.songs = JSON.parse(fs.readFileSync(songsPath, 'utf8'));
  result._paths = { poems: poemsPath, songs: songsPath };
  res.json(result);
});

app.post('/api/content', (req, res) => {
  const { type, data } = req.body;
  const target = type === 'poems' ? poemsPath : type === 'songs' ? songsPath : null;
  if (!target) return res.status(400).json({ error: 'invalid type' });

  // backup
  const backupDir = path.join(__dirname, 'backups');
  if (!fs.existsSync(backupDir)) fs.mkdirSync(backupDir);
  const ts = new Date().toISOString().replace(/[:.]/g, '-');
  fs.copyFileSync(target, path.join(backupDir, `${type}-${ts}.json`));

  // prune old backups — keep only 5 most recent per type
  const backups = fs.readdirSync(backupDir)
    .filter(f => f.startsWith(`${type}-`) && f.endsWith('.json'))
    .sort()
    .reverse();
  for (const old of backups.slice(5)) {
    fs.unlinkSync(path.join(backupDir, old));
  }

  fs.writeFileSync(target, JSON.stringify(data, null, 2) + '\n', 'utf8');
  res.json({ ok: true, backup: `${type}-${ts}.json` });
});

app.listen(PORT, '127.0.0.1', () => {
  console.log(`\neditor running → http://localhost:${PORT}/admin\n`);
});
