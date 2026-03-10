#!/bin/bash
set -e

cd "$(dirname "$0")"

# Check Node is available
if ! command -v node &> /dev/null; then
  echo "error: node.js is not installed"
  exit 1
fi

# Install deps if needed
if [ ! -d "node_modules" ]; then
  echo "installing dependencies..."
  npm install
fi

echo "starting rad.typo content editor..."

# Start server and open browser
node server.js "$@" &
SERVER_PID=$!

sleep 1
open "http://localhost:3333/admin" 2>/dev/null || xdg-open "http://localhost:3333/admin" 2>/dev/null || echo "open http://localhost:3333/admin in your browser"

# Wait for server process
wait $SERVER_PID
