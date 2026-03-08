// Shared status bar: KP colors, weather, KP index, CPU temp, sessionStorage cache

const KP_COLORS = {
    0: { color: '#2ecc71', label: 'Quiet' },
    1: { color: '#2ecc71', label: 'Quiet' },
    2: { color: '#2ecc71', label: 'Quiet' },
    3: { color: '#f1c40f', label: 'Unsettled' },
    4: { color: '#f39c12', label: 'Active' },
    5: { color: '#e67e22', label: 'Minor Storm' },
    6: { color: '#e74c3c', label: 'Moderate Storm' },
    7: { color: '#c0392b', label: 'Strong Storm' },
    8: { color: '#8e44ad', label: 'Severe Storm' },
    9: { color: '#6c3483', label: 'Extreme Storm' }
};

async function updateCpuTemp() {
    try {
        const response = await fetch('/api/stats.json');
        const data = await response.json();
        if (data.thermal?.cpu_temp) {
            const temp = Math.round(parseFloat(data.thermal.cpu_temp.replace('\u00b0C', '')));
            document.getElementById('cpu-temp').textContent = temp;
            try { sessionStorage.setItem('rt_cpu', JSON.stringify({ temp })); } catch(e) {}
        }
    } catch (_) {}
}

async function updateWeather() {
    try {
        const response = await fetch('https://api.open-meteo.com/v1/forecast?latitude=55.014662&longitude=-7.302903&current=temperature_2m,weather_code&timezone=Europe%2FLondon');
        const data = await response.json();
        if (data.current) {
            const temp = Math.round(data.current.temperature_2m);
            const code = data.current.weather_code;
            let icon = '\u26c5';
            if (code === 0)       icon = '\u2600\ufe0f';
            else if (code <= 3)   icon = '\u26c5';
            else if (code <= 48)  icon = '\ud83c\udf2b\ufe0f';
            else if (code <= 67)  icon = '\ud83c\udf27\ufe0f';
            else if (code <= 77)  icon = '\ud83c\udf28\ufe0f';
            else if (code <= 82)  icon = '\ud83c\udf26\ufe0f';
            else if (code >= 95)  icon = '\u26c8\ufe0f';
            document.getElementById('weather-temp').textContent = temp;
            document.getElementById('weather-icon').textContent = icon;
            try { sessionStorage.setItem('rt_weather', JSON.stringify({ temp: String(temp), icon })); } catch(e) {}
        }
    } catch (_) {}
}

async function updateKPIndex() {
    try {
        const response = await fetch('https://services.swpc.noaa.gov/products/noaa-planetary-k-index.json');
        const data = await response.json();
        const kpValue = parseFloat(data[data.length - 1][1]);
        const kpClamped = Math.min(9, Math.max(0, kpValue));
        const kpRounded = Math.round(kpClamped);
        const colorData = KP_COLORS[kpRounded];
        const circle = document.querySelector('.kp-circle');
        if (circle) circle.style.setProperty('--kp-color', colorData.color);
        const kpEl = document.getElementById('kp-value');
        if (kpEl) kpEl.textContent = kpClamped.toFixed(1);
        try { sessionStorage.setItem('rt_kp', JSON.stringify({ value: kpClamped.toFixed(1), color: colorData.color, label: colorData.label })); } catch(e) {}
    } catch (_) {}
}

// Restore status bar from cache instantly before fetching fresh data
(function() {
    try {
        const w = sessionStorage.getItem('rt_weather');
        if (w) { const d = JSON.parse(w); document.getElementById('weather-temp').textContent = d.temp; document.getElementById('weather-icon').textContent = d.icon; }
        const k = sessionStorage.getItem('rt_kp');
        if (k) { const d = JSON.parse(k); const c = document.querySelector('.kp-circle'); if (c) c.style.setProperty('--kp-color', d.color); const el = document.getElementById('kp-value'); if (el) el.textContent = d.value; }
        const c = sessionStorage.getItem('rt_cpu');
        if (c) { const d = JSON.parse(c); document.getElementById('cpu-temp').textContent = d.temp; }
    } catch(e) {}
})();

updateCpuTemp();
updateWeather();
updateKPIndex();
setInterval(updateCpuTemp, 30000);
setInterval(updateWeather, 300000);
setInterval(updateKPIndex, 300000);
