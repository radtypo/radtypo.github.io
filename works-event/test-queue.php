<?php
// Handle metadata save requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    if ($_POST['action'] === 'save_metadata') {
        $filename = $_POST['filename'] ?? '';
        $title = $_POST['title'] ?? '';
        $date = $_POST['date'] ?? '';
        $type = $_POST['type'] ?? '';
        $description = $_POST['description'] ?? '';
        
        if ($filename) {
            $audioDir = __DIR__ . '/audio';
            $txtFile = $audioDir . '/' . pathinfo($filename, PATHINFO_FILENAME) . '.txt';
            
            $content = "title: " . trim($title) . "\n";
            $content .= "date: " . trim($date) . "\n";
            $content .= "type: " . trim($type) . "\n";
            $content .= "description: " . trim($description);
            
            if (file_put_contents($txtFile, $content)) {
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Could not write file']);
            }
        } else {
            echo json_encode(['success' => false, 'error' => 'No filename provided']);
        }
        exit;
    }
    
    if ($_POST['action'] === 'save_order') {
        $order = json_decode($_POST['order'] ?? '[]', true);
        $audioDir = __DIR__ . '/audio';
        $orderFile = $audioDir . '/playlist-order.json';
        
        if (file_put_contents($orderFile, json_encode($order, JSON_PRETTY_PRINT))) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Could not write order file']);
        }
        exit;
    }
}

// Scan audio directory for files
$audioDir = __DIR__ . '/audio';
$audioFiles = [];

if (is_dir($audioDir)) {
    $files = scandir($audioDir);
    foreach ($files as $file) {
        if (pathinfo($file, PATHINFO_EXTENSION) === 'm4a') {
            // Extract basic info from filename
            $name = pathinfo($file, PATHINFO_FILENAME);
            
            // Try to extract date if present (format: 20_06_2023, 11.44)
            $dateParts = '';
            if (preg_match('/(\d{2}_\d{2}_\d{4},?\s*\d{2}\.\d{2})/', $name, $matches)) {
                $dateParts = $matches[1];
                $name = trim(preg_replace('/[\-_]\s*' . preg_quote($matches[1], '/') . '/', '', $name));
            }
            
            // Clean up filename
            $name = preg_replace('/[_\-]+/', ' ', $name);
            $name = preg_replace('/#\d+/', '', $name); // Remove #1, #2 etc
            $name = trim($name);
            
            // Look for metadata in .txt file
            $txtFile = $audioDir . '/' . pathinfo($file, PATHINFO_FILENAME) . '.txt';
            $title = $name; // Default to cleaned filename
            $date = $dateParts;
            $type = '';
            $description = '';
            
            if (file_exists($txtFile)) {
                $content = file_get_contents($txtFile);
                $lines = explode("\n", $content);
                
                foreach ($lines as $line) {
                    if (preg_match('/^title:\s*(.+)$/i', $line, $matches)) {
                        $title = trim($matches[1]);
                    } elseif (preg_match('/^date:\s*(.+)$/i', $line, $matches)) {
                        $date = trim($matches[1]);
                    } elseif (preg_match('/^type:\s*(.+)$/i', $line, $matches)) {
                        $type = trim($matches[1]);
                    } elseif (preg_match('/^description:\s*(.+)$/i', $line, $matches)) {
                        $description = trim($matches[1]);
                    }
                }
            }
            
            $audioFiles[] = [
                'filename' => $file,
                'title' => $title,
                'date' => $date,
                'type' => $type,
                'description' => $description,
                'url' => '/works-event/audio/' . rawurlencode($file)
            ];
        }
    }
}

// Sort alphabetically by title
usort($audioFiles, function($a, $b) {
    return strcmp($a['title'], $b['title']);
});

// Load saved order if it exists
$audioDir = __DIR__ . '/audio';
$orderFile = $audioDir . '/playlist-order.json';
if (file_exists($orderFile)) {
    $savedOrder = json_decode(file_get_contents($orderFile), true);
    if (is_array($savedOrder)) {
        // Reorder based on saved order
        $orderedFiles = [];
        foreach ($savedOrder as $filename) {
            foreach ($audioFiles as $track) {
                if ($track['filename'] === $filename) {
                    $orderedFiles[] = $track;
                    break;
                }
            }
        }
        // Add any new files not in saved order
        foreach ($audioFiles as $track) {
            if (!in_array($track['filename'], $savedOrder)) {
                $orderedFiles[] = $track;
            }
        }
        $audioFiles = $orderedFiles;
    }
}

$trackCount = count($audioFiles);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>test queue • works.event</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: 'Courier New', monospace;
            background: #000;
            color: #fff;
            line-height: 1.6;
            padding: 20px;
        }
        .container { max-width: 900px; margin: 0 auto; }
        
        h1 { font-size: 24px; font-weight: normal; margin-bottom: 16px; }
        
        .summary {
            background: #111;
            border: 1px solid #333;
            padding: 10px 14px;
            border-radius: 4px;
            margin-bottom: 20px;
            font-size: 12px;
        }
        .summary-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
        }
        .summary-controls {
            display: flex;
            gap: 8px;
            align-items: center;
            flex-wrap: wrap;
        }
        
        .transport-btn {
            background: #222;
            border: 1px solid #333;
            color: #fff;
            width: 32px;
            height: 32px;
            border-radius: 4px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            transition: all 0.2s;
        }
        .transport-btn:hover {
            background: #333;
            border-color: #555;
        }
        .transport-btn:active {
            background: #444;
        }
        .transport-btn.play-all {
            width: auto;
            padding: 0 12px;
            font-size: 11px;
        }
        
        .total-time {
            color: #0f0;
            font-size: 13px;
        }
        
        #trackList {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
        }
        
        .track-test {
            background: #111;
            border: 1px solid #333;
            padding: 12px;
            border-radius: 4px;
            cursor: grab;
            transition: all 0.2s;
        }
        .track-test:active {
            cursor: grabbing;
        }
        .track-test.playing {
            border-color: #0f0;
            background: #001a00;
        }
        .track-test.dragging {
            opacity: 0.5;
        }
        .track-test.drag-over {
            border-color: #0f0;
            border-style: dashed;
        }
        
        .track-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
        }
        .track-number { 
            color: #666; 
            font-size: 10px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .drag-handle {
            color: #444;
            cursor: grab;
            user-select: none;
            font-size: 10px;
        }
        .drag-handle:active {
            cursor: grabbing;
        }
        
        .track-duration { 
            font-size: 10px; 
            color: #888;
        }
        .duration-loaded { color: #0f0; }
        
        .track-title-edit { 
            font-size: 13px; 
            margin-bottom: 2px; 
            font-weight: bold;
            cursor: text;
            padding: 2px 4px;
            border-radius: 2px;
            transition: background 0.2s;
        }
        .track-title-edit:hover {
            background: #222;
        }
        .track-title-edit:focus {
            outline: 1px solid #0f0;
            background: #1a1a1a;
        }
        .track-title-edit:empty:before {
            content: 'click to add title...';
            color: #444;
        }
        
        .track-date-edit { 
            font-size: 10px; 
            color: #888; 
            margin-bottom: 4px;
            cursor: text;
            padding: 2px 4px;
            border-radius: 2px;
            transition: background 0.2s;
        }
        .track-date-edit:hover {
            background: #222;
        }
        .track-date-edit:focus {
            outline: 1px solid #0f0;
            background: #1a1a1a;
        }
        .track-date-edit:empty:before {
            content: 'click to add date...';
            color: #444;
        }
        
        .metadata-field {
            font-size: 10px;
            margin-bottom: 4px;
            display: flex;
            gap: 4px;
        }
        .metadata-label {
            color: #666;
            min-width: 60px;
        }
        .metadata-value {
            color: #fff;
            flex: 1;
            cursor: text;
            padding: 2px 4px;
            border-radius: 2px;
            transition: background 0.2s;
        }
        .metadata-value:hover {
            background: #222;
        }
        .metadata-value:focus {
            outline: 1px solid #0f0;
            background: #1a1a1a;
        }
        .metadata-value:empty:before {
            content: 'click to add...';
            color: #444;
        }
        
        .save-indicator {
            font-size: 9px;
            color: #0f0;
            margin-left: 4px;
            opacity: 0;
            transition: opacity 0.3s;
        }
        .save-indicator.show {
            opacity: 1;
        }
        
        /* Hide native audio controls */
        audio {
            display: none;
        }
        
        /* Custom player */
        .custom-player {
            background: #000;
            border: 1px solid #222;
            border-radius: 4px;
            padding: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 8px;
        }
        
        .play-btn {
            width: 24px;
            height: 24px;
            background: #fff;
            border: none;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            transition: background 0.2s;
        }
        .play-btn:hover {
            background: #ccc;
        }
        .play-btn::before {
            content: '▶';
            color: #000;
            font-size: 10px;
            margin-left: 2px;
        }
        .play-btn.playing {
            background: #0f0;
        }
        .play-btn.playing::before {
            content: '■';
            margin-left: 0;
        }
        
        .progress-container {
            flex: 1;
            height: 4px;
            background: #222;
            border-radius: 2px;
            cursor: pointer;
            position: relative;
        }
        .progress-bar {
            height: 100%;
            background: #0f0;
            border-radius: 2px;
            width: 0%;
            transition: width 0.1s linear;
        }
        
        .time-display {
            font-size: 9px;
            color: #666;
            font-family: 'Courier New', monospace;
            flex-shrink: 0;
            min-width: 35px;
            text-align: right;
        }
        
        @media (max-width: 768px) {
            #trackList {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>test queue</h1>
        
        <div class="summary">
            <div class="summary-top">
                <span><span id="trackCount"><?php echo $trackCount; ?></span> tracks</span>
                <span class="total-time" id="totalTime">calculating...</span>
            </div>
            <div class="summary-controls">
                <button class="transport-btn play-all" onclick="playFullBroadcast()" title="Play full broadcast from start">▶ play all</button>
                <button class="transport-btn" onclick="previousTrack()" title="Previous track">◀◀</button>
                <button class="transport-btn" id="masterPlayBtn" onclick="toggleMasterPlay()" title="Play/Pause">▶</button>
                <button class="transport-btn" onclick="nextTrack()" title="Next track">▶▶</button>
            </div>
        </div>

        <div id="trackList">
            <?php foreach ($audioFiles as $index => $track): ?>
            <div class="track-test" draggable="true" data-index="<?php echo $index; ?>" data-filename="<?php echo htmlspecialchars($track['filename']); ?>">
                <div class="track-header">
                    <span class="track-number">
                        <span class="drag-handle">☰</span>
                        <span class="track-num"><?php echo $index + 1; ?></span>
                    </span>
                    <span class="track-duration" id="duration-<?php echo $index; ?>">--:--</span>
                </div>
                
                <div class="track-title-edit" 
                     contenteditable="true" 
                     data-field="title"
                     data-filename="<?php echo htmlspecialchars($track['filename']); ?>"><?php echo htmlspecialchars($track['title']); ?></div>
                
                <div class="track-date-edit" 
                     contenteditable="true" 
                     data-field="date"
                     data-filename="<?php echo htmlspecialchars($track['filename']); ?>"><?php echo htmlspecialchars($track['date']); ?></div>
                
                <div class="metadata-field">
                    <span class="metadata-label">type:</span>
                    <div class="metadata-value" 
                         contenteditable="true" 
                         data-field="type"
                         data-filename="<?php echo htmlspecialchars($track['filename']); ?>"><?php echo htmlspecialchars($track['type']); ?></div>
                    <span class="save-indicator">✓</span>
                </div>
                
                <div class="metadata-field">
                    <span class="metadata-label">description:</span>
                    <div class="metadata-value" 
                         contenteditable="true" 
                         data-field="description"
                         data-filename="<?php echo htmlspecialchars($track['filename']); ?>"><?php echo htmlspecialchars($track['description']); ?></div>
                    <span class="save-indicator">✓</span>
                </div>
                
                <audio id="audio-<?php echo $index; ?>" preload="metadata">
                    <source src="<?php echo htmlspecialchars($track['url']); ?>" type="audio/mp4">
                </audio>
                <div class="custom-player">
                    <button class="play-btn" data-track="<?php echo $index; ?>"></button>
                    <div class="progress-container" data-track="<?php echo $index; ?>">
                        <div class="progress-bar" id="progress-<?php echo $index; ?>"></div>
                    </div>
                    <div class="time-display" id="time-<?php echo $index; ?>">0:00</div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <script>
    let currentTrack = 0;
    let trackDurations = {};
    const totalTracks = <?php echo $trackCount; ?>;
    let isPlayingBroadcast = false;
    let masterPlayBtn = null;
    
    // Initialize after DOM loads
    document.addEventListener('DOMContentLoaded', () => {
        masterPlayBtn = document.getElementById('masterPlayBtn');
    });
    
    // Format seconds to mm:ss
    function formatTime(seconds) {
        if (!seconds || isNaN(seconds)) return '--:--';
        const mins = Math.floor(seconds / 60);
        const secs = Math.floor(seconds % 60);
        return `${mins}:${secs.toString().padStart(2, '0')}`;
    }
    
    // Save metadata
    async function saveMetadata(filename, field) {
        const trackCard = document.querySelector(`[data-filename="${filename}"]`);
        const titleField = trackCard.querySelector('[data-field="title"]');
        const dateField = trackCard.querySelector('[data-field="date"]');
        const typeField = trackCard.querySelector('[data-field="type"]');
        const descField = trackCard.querySelector('[data-field="description"]');
        
        // Find save indicator
        let indicator;
        if (field.dataset.field === 'title' || field.dataset.field === 'date') {
            indicator = trackCard.querySelector('.metadata-field .save-indicator');
        } else {
            indicator = field.nextElementSibling;
        }
        
        const formData = new FormData();
        formData.append('action', 'save_metadata');
        formData.append('filename', filename);
        formData.append('title', titleField.textContent);
        formData.append('date', dateField.textContent);
        formData.append('type', typeField.textContent);
        formData.append('description', descField.textContent);
        
        try {
            const response = await fetch(window.location.href, {
                method: 'POST',
                body: formData
            });
            const result = await response.json();
            
            if (result.success) {
                indicator.classList.add('show');
                setTimeout(() => indicator.classList.remove('show'), 1000);
            } else {
                console.error('Save failed:', result.error);
            }
        } catch (error) {
            console.error('Save error:', error);
        }
    }
    
    // Editable field handlers
    document.querySelectorAll('[contenteditable="true"]').forEach(field => {
        let saveTimeout;
        
        field.addEventListener('blur', () => {
            clearTimeout(saveTimeout);
            saveMetadata(field.dataset.filename, field);
        });
        
        field.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                field.blur();
            }
        });
        
        field.addEventListener('input', () => {
            clearTimeout(saveTimeout);
            saveTimeout = setTimeout(() => {
                saveMetadata(field.dataset.filename, field);
            }, 1000);
        });
    });
    
    // Load track durations
    function loadDurations() {
        document.querySelectorAll('audio').forEach((audio, index) => {
            audio.addEventListener('loadedmetadata', () => {
                trackDurations[index] = audio.duration;
                const durationEl = document.getElementById(`duration-${index}`);
                durationEl.textContent = formatTime(audio.duration);
                durationEl.classList.add('duration-loaded');
                document.getElementById(`time-${index}`).textContent = formatTime(audio.duration);
                updateTotalTime();
            });
            
            // Update progress bar
            audio.addEventListener('timeupdate', () => {
                const progress = (audio.currentTime / audio.duration) * 100;
                document.getElementById(`progress-${index}`).style.width = progress + '%';
                document.getElementById(`time-${index}`).textContent = formatTime(audio.currentTime);
            });
            
            // Reset when ended
            audio.addEventListener('ended', () => {
                const btn = document.querySelector(`.play-btn[data-track="${index}"]`);
                btn.classList.remove('playing');
                document.getElementById(`progress-${index}`).style.width = '0%';
                document.getElementById(`time-${index}`).textContent = formatTime(audio.duration);
                
                // Auto-advance to next track if playing broadcast
                if (isPlayingBroadcast && index < totalTracks - 1) {
                    currentTrack = index + 1;
                    setTimeout(() => togglePlay(currentTrack), 500);
                } else {
                    isPlayingBroadcast = false;
                    updateMasterPlayButton();
                }
            });
        });
    }
    
    // Update total broadcast time
    function updateTotalTime() {
        const total = Object.values(trackDurations).reduce((sum, dur) => sum + dur, 0);
        document.getElementById('totalTime').textContent = formatTime(total);
    }
    
    // Play button handlers
    document.querySelectorAll('.play-btn').forEach((btn, index) => {
        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            const trackIndex = parseInt(btn.dataset.track);
            togglePlay(trackIndex);
        });
    });
    
    // Progress bar click handlers
    document.querySelectorAll('.progress-container').forEach((container, index) => {
        container.addEventListener('click', (e) => {
            e.stopPropagation();
            const trackIndex = parseInt(container.dataset.track);
            const audio = document.getElementById(`audio-${trackIndex}`);
            const rect = container.getBoundingClientRect();
            const percent = (e.clientX - rect.left) / rect.width;
            audio.currentTime = percent * audio.duration;
        });
    });
    
    // Toggle play/pause
    function togglePlay(index) {
        const audio = document.getElementById(`audio-${index}`);
        const btn = document.querySelector(`.play-btn[data-track="${index}"]`);
        
        if (audio.paused) {
            // Stop all other tracks
            document.querySelectorAll('audio').forEach((a, i) => {
                if (i !== index) {
                    a.pause();
                    document.querySelector(`.play-btn[data-track="${i}"]`).classList.remove('playing');
                }
            });
            document.querySelectorAll('.track-test').forEach(t => t.classList.remove('playing'));
            
            audio.play();
            btn.classList.add('playing');
            document.querySelector(`[data-index="${index}"]`).classList.add('playing');
            currentTrack = index;
            updateMasterPlayButton();
        } else {
            audio.pause();
            btn.classList.remove('playing');
            document.querySelector(`[data-index="${index}"]`).classList.remove('playing');
            updateMasterPlayButton();
        }
    }
    
    // Master transport controls
    function playFullBroadcast() {
        isPlayingBroadcast = true;
        currentTrack = 0;
        togglePlay(0);
    }
    
    function toggleMasterPlay() {
        const audio = document.getElementById(`audio-${currentTrack}`);
        if (audio.paused) {
            togglePlay(currentTrack);
        } else {
            audio.pause();
            document.querySelector(`.play-btn[data-track="${currentTrack}"]`).classList.remove('playing');
            document.querySelector(`[data-index="${currentTrack}"]`).classList.remove('playing');
            updateMasterPlayButton();
        }
    }
    
    function previousTrack() {
        if (currentTrack > 0) {
            const wasPlaying = !document.getElementById(`audio-${currentTrack}`).paused;
            if (wasPlaying) {
                document.getElementById(`audio-${currentTrack}`).pause();
                document.querySelector(`.play-btn[data-track="${currentTrack}"]`).classList.remove('playing');
            }
            currentTrack--;
            if (wasPlaying) {
                togglePlay(currentTrack);
            }
            scrollToCurrentTrack();
        }
    }
    
    function nextTrack() {
        if (currentTrack < totalTracks - 1) {
            const wasPlaying = !document.getElementById(`audio-${currentTrack}`).paused;
            if (wasPlaying) {
                document.getElementById(`audio-${currentTrack}`).pause();
                document.querySelector(`.play-btn[data-track="${currentTrack}"]`).classList.remove('playing');
            }
            currentTrack++;
            if (wasPlaying) {
                togglePlay(currentTrack);
            }
            scrollToCurrentTrack();
        }
    }
    
    function updateMasterPlayButton() {
        if (!masterPlayBtn) return;
        const audio = document.getElementById(`audio-${currentTrack}`);
        masterPlayBtn.textContent = audio.paused ? '▶' : '■';
    }
    
    function scrollToCurrentTrack() {
        const tracks = document.querySelectorAll('.track-test');
        if (tracks[currentTrack]) {
            tracks[currentTrack].scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }
    
    // Navigate tracks with keyboard
    function navigateTrack(direction) {
        const tracks = document.querySelectorAll('.track-test');
        const newIndex = Math.max(0, Math.min(tracks.length - 1, currentTrack + direction));
        currentTrack = newIndex;
        tracks[newIndex].scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
    
    // Keyboard shortcuts
    document.addEventListener('keydown', (e) => {
        if (e.target.tagName === 'INPUT' || e.target.contentEditable === 'true') return;
        
        switch(e.key) {
            case ' ':
                e.preventDefault();
                toggleMasterPlay();
                break;
            case 'ArrowUp':
                e.preventDefault();
                navigateTrack(-1);
                break;
            case 'ArrowDown':
                e.preventDefault();
                navigateTrack(1);
                break;
            case 'ArrowLeft':
                e.preventDefault();
                const audioLeft = document.getElementById(`audio-${currentTrack}`);
                audioLeft.currentTime = Math.max(0, audioLeft.currentTime - 5);
                break;
            case 'ArrowRight':
                e.preventDefault();
                const audioRight = document.getElementById(`audio-${currentTrack}`);
                audioRight.currentTime = Math.min(audioRight.duration, audioRight.currentTime + 5);
                break;
        }
    });
    
    // Drag and drop reordering
    let draggedElement = null;
    
    document.querySelectorAll('.track-test').forEach(track => {
        track.addEventListener('dragstart', (e) => {
            // Don't allow drag if editing text
            if (document.activeElement.contentEditable === 'true') {
                e.preventDefault();
                return;
            }
            draggedElement = track;
            track.classList.add('dragging');
            e.dataTransfer.effectAllowed = 'move';
        });
        
        track.addEventListener('dragend', (e) => {
            track.classList.remove('dragging');
            document.querySelectorAll('.track-test').forEach(t => t.classList.remove('drag-over'));
        });
        
        track.addEventListener('dragover', (e) => {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            
            if (draggedElement !== track) {
                track.classList.add('drag-over');
            }
        });
        
        track.addEventListener('dragleave', (e) => {
            track.classList.remove('drag-over');
        });
        
        track.addEventListener('drop', (e) => {
            e.preventDefault();
            track.classList.remove('drag-over');
            
            if (draggedElement !== track) {
                const trackList = document.getElementById('trackList');
                const allTracks = [...trackList.children];
                const draggedIndex = allTracks.indexOf(draggedElement);
                const targetIndex = allTracks.indexOf(track);
                
                if (draggedIndex < targetIndex) {
                    trackList.insertBefore(draggedElement, track.nextSibling);
                } else {
                    trackList.insertBefore(draggedElement, track);
                }
                
                updateTrackNumbers();
            }
        });
    });
    
    // Update track numbers after reordering
    function updateTrackNumbers() {
        document.querySelectorAll('.track-test').forEach((track, index) => {
            track.querySelector('.track-num').textContent = `${index + 1}`;
        });
        
        // Save the new order
        savePlaylistOrder();
    }
    
    // Save playlist order
    async function savePlaylistOrder() {
        const order = [];
        document.querySelectorAll('.track-test').forEach(track => {
            order.push(track.dataset.filename);
        });
        
        const formData = new FormData();
        formData.append('action', 'save_order');
        formData.append('order', JSON.stringify(order));
        
        try {
            const response = await fetch(window.location.href, {
                method: 'POST',
                body: formData
            });
            const result = await response.json();
            if (!result.success) {
                console.error('Failed to save order:', result.error);
            }
        } catch (error) {
            console.error('Error saving order:', error);
        }
    }
    
    // Initialize
    loadDurations();
    </script>
</body>
</html>
