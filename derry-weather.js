/**
 * derry-weather.js - A minimalist script to display Derry weather
 * For rad.typo - DIY music archive
 */

const derryWeather = {
  // Config options
  config: {
    refreshInterval: 10800000, // 3 hours in milliseconds
    apiKey: '71a06e0de306469e84500416252002',    // Replace with your actual API key
    location: 'Derry,Northern Ireland',
    container: 'derry-weather',
    showDate: true,
    showTime: true
  },
  
  // Weather symbols in text/ASCII
  symbols: {
    sunny: '☼',
    partlyCloudy: '☀☁',
    cloudy: '☁',
    rain: '☂',
    snow: '❄',
    fog: '▒',
    thunder: '⚡',
    night: '☽'
  },
  
  /**
   * Initialize the weather display
   * @param {Object} options - Optional configuration overrides
   */
  init: function(options = {}) {
    // Merge user options with defaults
    this.config = {...this.config, ...options};
    
    // Attempt to get weather immediately
    this.updateWeather();
    
    // Setup refresh interval if enabled
    if (this.config.refreshInterval > 0) {
      setInterval(() => this.updateWeather(), this.config.refreshInterval);
    }
    
    console.log('Derry weather initialized');
  },
  
  /**
   * Fetch and update the weather display
   */
  updateWeather: async function() {
    const container = document.getElementById(this.config.container);
    if (!container) return;
    
    try {
      // Check for cached weather data first
      const weather = await this.getWeatherData();
      if (!weather) {
        // If we couldn't get weather, just show date/time
        this.updateDateTime(container);
        return;
      }
      
      // Get the appropriate symbol
      const symbol = this.getWeatherSymbol(weather);
      
      // Format temperature
      const temp = Math.round(weather.current.temp_c) + '°C';
      
      // Build display string
      let displayText = `Derry: ${temp} ${symbol}`;
      
      // Add date and time if configured
      if (this.config.showTime || this.config.showDate) {
        displayText += ' | ';
        
        if (this.config.showTime) {
          const now = new Date();
          const hours = String(now.getHours()).padStart(2, '0');
          const minutes = String(now.getMinutes()).padStart(2, '0');
          displayText += `${hours}:${minutes}`;
        }
        
        if (this.config.showDate && this.config.showTime) {
          displayText += ' | ';
        }
        
        if (this.config.showDate) {
          const now = new Date();
          const year = now.getFullYear();
          const month = String(now.getMonth() + 1).padStart(2, '0');
          const day = String(now.getDate()).padStart(2, '0');
          displayText += `${year}-${month}-${day}`;
        }
      }
      
      // Update the container
      container.textContent = displayText;
      container.setAttribute('title', weather.current.condition.text);
      
    } catch (error) {
      console.error('Failed to update weather:', error);
      this.updateDateTime(container);
    }
  },
  
  /**
   * Fall back to just showing date/time if weather fails
   */
  updateDateTime: function(container) {
    if (!container) return;
    
    const now = new Date();
    let displayText = 'Derry';
    
    if (this.config.showTime) {
      const hours = String(now.getHours()).padStart(2, '0');
      const minutes = String(now.getMinutes()).padStart(2, '0');
      displayText += ` | ${hours}:${minutes}`;
    }
    
    if (this.config.showDate) {
      const year = now.getFullYear();
      const month = String(now.getMonth() + 1).padStart(2, '0');
      const day = String(now.getDate()).padStart(2, '0');
      displayText += ` | ${year}-${month}-${day}`;
    }
    
    container.textContent = displayText;
  },
  
  /**
   * Fetch weather data, using cache when appropriate
   */
  getWeatherData: async function() {
    try {
      // Check cache first
      const cachedWeather = localStorage.getItem('derryWeather');
      const cachedTime = localStorage.getItem('derryWeatherTime');
      
      // Use cache if it's still fresh
      if (cachedWeather && cachedTime) {
        const cacheAge = Date.now() - Number(cachedTime);
        if (cacheAge < this.config.refreshInterval) {
          return JSON.parse(cachedWeather);
        }
      }
      
      // Fetch fresh data if cache is stale or missing
      const response = await fetch(
        `https://api.weatherapi.com/v1/current.json?key=${this.config.apiKey}&q=${this.config.location}`
      );
      
      if (!response.ok) {
        throw new Error(`Weather API responded with ${response.status}`);
      }
      
      const data = await response.json();
      
      // Cache the response
      localStorage.setItem('derryWeather', JSON.stringify(data));
      localStorage.setItem('derryWeatherTime', Date.now().toString());
      
      return data;
    } catch (error) {
      console.error('Error fetching weather:', error);
      return null;
    }
  },
  
  /**
   * Determine the appropriate weather symbol
   */
  getWeatherSymbol: function(weather) {
    if (!weather || !weather.current) return '';
    
    const code = weather.current.condition.code;
    const isDay = weather.current.is_day === 1;
    
    // Determine weather symbol based on condition code and day/night
    if (!isDay) {
      return this.symbols.night;
    }
    
    // Using WeatherAPI.com condition codes
    // Rain conditions
    if ([1063, 1180, 1183, 1186, 1189, 1192, 1195, 1240, 1243, 1246].includes(code)) {
      return this.symbols.rain;
    }
    
    // Snow conditions
    if ([1066, 1114, 1117, 1210, 1213, 1216, 1219, 1222, 1225, 1255, 1258].includes(code)) {
      return this.symbols.snow;
    }
    
    // Thunderstorm
    if ([1087, 1273, 1276, 1279, 1282].includes(code)) {
      return this.symbols.thunder;
    }
    
    // Fog, mist
    if ([1030, 1135, 1147].includes(code)) {
      return this.symbols.fog;
    }
    
    // Cloudy conditions
    if ([1003, 1006, 1009].includes(code)) {
      return this.symbols.cloudy;
    }
    
    // Partly cloudy
    if ([1003].includes(code)) {
      return this.symbols.partlyCloudy;
    }
    
    // Default to sunny for clear conditions
    return this.symbols.sunny;
  }
};

// Initialize when DOM is loaded
document.addEventListener('DOMContentLoaded', () => {
  derryWeather.init();
});
