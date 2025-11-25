<?php
// Get all audio files from library
$library_path = '/home/andrewferris/works-event/library/';
$audio_files = glob($library_path . '*.{m4a,mp3,wav}', GLOB_BRACE);

$tracks = [];
foreach ($audio_files as $audio_file) {
    $basename = basename($audio_file);
    $name_without_ext = pathinfo($basename, PATHINFO_FILENAME);
    
    // Read metadata file
    $txt_file = $library_path . $name_without_ext . '.txt';
    $type = 'demo';
    $description = '';
    $fragment = '';
    
    if (file_exists($txt_file)) {
        $content = file_get_contents($txt_file);
        if (preg_match('/type:\s*(.+)/i', $content, $matches)) {
            $type = trim($matches[1]);
        }
        if (preg_match('/description:\s*(.+)/i', $content, $matches)) {
            $description = trim($matches[1]);
        }
        if (preg_match('/fragment:\s*(.+)/i', $content, $matches)) {
            $fragment = trim($matches[1]);
        }
    }
    
    // Get file duration
    $duration_seconds = filesize($audio_file) / (128000 / 8);
    $minutes = floor($duration_seconds / 60);
    $seconds = floor($duration_seconds % 60);
    $duration = sprintf('%d:%02d', $minutes, $seconds);
    
    // Clean up display name
    $display_name = preg_replace('/[-_]/', ' ', $name_without_ext);
    $display_name = preg_replace('/#\d+/', '', $display_name);
    $display_name = preg_replace('/\d{2}_\d{2}_\d{4},?\s*\d{2}\.\d{2}/', '', $display_name);
    $display_name = trim($display_name);
    
    $tracks[] = [
        'file' => $basename,
        'name' => $display_name,
        'type' => $type,
        'description' => $description,
        'fragment' => $fragment,
        'duration' => $duration,
        'duration_seconds' => floor($duration_seconds)
    ];
}

// Build playlist for JavaScript
$playlist = [];
foreach ($tracks as $track) {
    $playlist[] = '/works-event/audio/' . rawurlencode($track['file']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>works.event • rad.typo</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: 'Courier New', monospace;
            background: #000;
            color: #fff;
            line-height: 1.6;
            padding: 20px;
        }
        .container { max-width: 600px; margin: 0 auto; }
        
        .header {
            margin-bottom: 24px;
            padding-bottom: 20px;
            border-bottom: 1px solid #333;
        }
        h1 { font-size: 24px; font-weight: normal; margin-bottom: 8px; }
        .subtitle { font-size: 13px; color: #888; }
        
        .trigger-row {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 24px;
        }
        
        .trigger {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            font-size: 13px;
            padding: 10px 16px;
            background: #1a0000;
            border: 1px solid #e63946;
            border-radius: 4px;
        }
        .trigger-icon { font-size: 20px; line-height: 1; }
        .trigger-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #e63946;
            animation: pulse 2s infinite;
        }
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.3; }
        }
        
        .control-btn {
            background: #111;
            border: 1px solid #333;
            color: #fff;
            padding: 10px 16px;
            font-family: 'Courier New', monospace;
            font-size: 13px;
            cursor: pointer;
            border-radius: 4px;
        }
        .control-btn:hover {
            background: #222;
            border-color: #555;
        }
        .control-btn.primary {
            background: #e63946;
            border-color: #e63946;
        }
        .control-btn.primary:hover {
            background: #d62839;
        }
        
        #startBtn {
            display: inline-block;
        }
        #muteBtn {
            display: none;
        }
        
        .now-playing {
            background: #000;
            border: 1px solid #fff;
            padding: 20px;
            margin-bottom: 16px;
            border-radius: 4px;
        }
        .now-playing-label { font-size: 11px; color: #888; margin-bottom: 8px; }
        .track-title { font-size: 18px; margin-bottom: 4px; }
        .track-meta { 
            display: inline-block;
            font-size: 12px; 
            color: #888; 
            padding: 4px 8px;
            background: #111;
            border: 1px solid #333;
            border-radius: 3px;
            margin-bottom: 12px;
        }
        .track-description { font-size: 13px; line-height: 1.5; margin-bottom: 12px; }
        .track-fragment { font-size: 12px; color: #888; font-style: italic; }
        
        .coming-up {
            background: #111;
            border: 1px solid #333;
            padding: 16px;
            border-radius: 4px;
            margin-bottom: 16px;
        }
        .coming-up-title { font-size: 11px; color: #888; margin-bottom: 12px; }
        .queue-item {
            font-size: 13px;
            padding: 8px 0;
            border-bottom: 1px solid #222;
        }
        .queue-item:last-child { border-bottom: none; }
        .queue-time { color: #888; }
        .queue-title { margin: 4px 0; }
        .queue-meta { font-size: 12px; color: #666; }
        
        .footer {
            text-align: center;
            font-size: 11px;
            color: #666;
            padding-top: 20px;
            margin-top: 24px;
            border-top: 1px solid #333;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>works.event</h1>
            <div class="subtitle">demos · fragments · works in progress</div>
        </div>

        <div class="trigger-row">
            <div class="trigger">
                <span class="trigger-icon">☀︎</span>
                <span class="trigger-dot"></span>
                <span>test broadcast · live</span>
            </div>
            <button class="control-btn primary" id="startBtn" onclick="startBroadcast()">start broadcast</button>
            <button class="control-btn" id="muteBtn" onclick="toggleMute()">mute</button>
        </div>

        <div class="now-playing" id="nowPlaying">
            <div class="now-playing-label">♪ now playing</div>
            <div class="track-title" id="currentTitle">Loading...</div>
            <div class="track-meta" id="currentMeta"></div>
            <div class="track-description" id="currentDescription"></div>
            <div class="track-fragment" id="currentFragment"></div>
        </div>

        <div class="coming-up">
            <div class="coming-up-title">coming up</div>
            <div id="queueList"></div>
        </div>

        <div class="footer">
            a rad.typo project
        </div>
    </div>

    <audio id="player" style="display: none;"></audio>

    <script>
    const tracks = <?php echo json_encode($tracks); ?>;
    const playlist = <?php echo json_encode($playlist); ?>;
    let currentIndex = 0;
    const player = document.getElementById('player');
    let isStarted = false;
    
    function startBroadcast() {
        if (!isStarted) {
            loadTrack(0);
            isStarted = true;
            document.getElementById('startBtn').style.display = 'none';
            document.getElementById('muteBtn').style.display = 'inline-block';
        }
    }
    
    function loadTrack(index) {
        currentIndex = index;
        const track = tracks[index];
        
        // Update now playing
        document.getElementById('currentTitle').textContent = track.name;
        document.getElementById('currentMeta').textContent = track.type + ' · ' + track.duration;
        document.getElementById('currentDescription').textContent = track.description || '';
        document.getElementById('currentFragment').textContent = track.fragment ? '"' + track.fragment + '"' : '';
        
        // Load and play audio
        player.src = playlist[index];
        player.play().catch(e => console.log('Play failed:', e));
        
        // Update coming up list
        updateQueue();
    }
    
    function updateQueue() {
        const queueList = document.getElementById('queueList');
        queueList.innerHTML = '';
        
        let cumulativeTime = tracks[currentIndex].duration_seconds;
        
        for (let i = 1; i <= Math.min(4, tracks.length - 1); i++) {
            const nextIndex = (currentIndex + i) % tracks.length;
            const track = tracks[nextIndex];
            
            const minutes = Math.floor(cumulativeTime / 60);
            const itemNum = nextIndex + 1;
            
            const item = document.createElement('div');
            item.className = 'queue-item';
            item.innerHTML = `
                <div class="queue-time">in ${minutes} min · item ${itemNum} of ${tracks.length}</div>
                <div class="queue-title">${track.name}</div>
                <div class="queue-meta">${track.type} · ${track.duration}</div>
            `;
            queueList.appendChild(item);
            
            cumulativeTime += track.duration_seconds;
        }
    }
    
    function toggleMute() {
        player.muted = !player.muted;
        document.getElementById('muteBtn').textContent = player.muted ? 'unmute' : 'mute';
    }
    
    // Auto-advance to next track
    player.addEventListener('ended', function() {
        loadTrack((currentIndex + 1) % tracks.length);
    });
    
    // Initialize display
    updateQueue();
    const track = tracks[0];
    document.getElementById('currentTitle').textContent = track.name;
    document.getElementById('currentMeta').textContent = track.type + ' · ' + track.duration;
    document.getElementById('currentDescription').textContent = track.description || '';
    document.getElementById('currentFragment').textContent = track.fragment ? '"' + track.fragment + '"' : '';
    </script>
</body>
</html>
