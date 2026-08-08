#!/usr/bin/env bash
# run-acceptance.sh – cross‑platform helper for Codeception acceptance tests

set -e

ios=$(uname -s)

EDGE_DRIVER="./webdriver/msedgedriver"
CHROME_DRIVER="./webdriver/chromedriver"

FRONT_PID=""
BACKEND_PID=""
DRIVER_PID=""

cleanup() {
  echo "▶ Cleaning up test background processes..."
  if [[ -n "$DRIVER_PID" ]]; then
    echo "  - Stopping WebDriver (PID $DRIVER_PID)"
    kill $DRIVER_PID 2>/dev/null || true
  fi
  if [[ -n "$FRONT_PID" ]]; then
    echo "  - Stopping front-end server (PID $FRONT_PID)"
    kill $FRONT_PID 2>/dev/null || true
  fi
  if [[ -n "$BACKEND_PID" ]]; then
    echo "  - Stopping backend server (PID $BACKEND_PID)"
    kill $BACKEND_PID 2>/dev/null || true
  fi
  pkill -P $$ 2>/dev/null || true
}

trap cleanup EXIT INT TERM

# 0. Clean up any stale background servers on test ports
pkill -f "php -S localhost:8080" 2>/dev/null || true
pkill -f "php -S localhost:8081" 2>/dev/null || true
sleep 1

# 1. Start front-end server
echo "▶ Starting front-end server (http://localhost:8080)..."
php -S localhost:8080 -t public > /dev/null 2>&1 &
FRONT_PID=$!
echo "   → PID $FRONT_PID"

# 2. Start backend server
echo "▶ Starting backend mock API server (http://localhost:8081)..."
php -S localhost:8081 ./public/backend-test-api/index.php > /dev/null 2>&1 &
BACKEND_PID=$!
echo "   → PID $BACKEND_PID"

sleep 2

# 3. Start WebDriver
if [[ "$ios" == "Linux" || "$ios" == "Darwin" ]]; then
  if [[ -x "$EDGE_DRIVER" ]]; then
    echo "▶ Starting Edge WebDriver: $EDGE_DRIVER --port=10888"
    $EDGE_DRIVER --port=10888 > /dev/null 2>&1 &
    DRIVER_PID=$!
  elif [[ -x "$CHROME_DRIVER" ]]; then
    echo "▶ Starting Chrome WebDriver: $CHROME_DRIVER --port=10888"
    $CHROME_DRIVER --port=10888 > /dev/null 2>&1 &
    DRIVER_PID=$!
  else
    echo "Error: No executable WebDriver binary found in ./webdriver. Expected msedgedriver or chromedriver." >&2
    exit 1
  fi
else
  echo "▶ Starting Edge WebDriver on Windows (port 10888)..."
  start /B C:\webdriver\msedgedriver.exe --port=10888
fi

if [[ -n "$DRIVER_PID" ]]; then
  echo "   → WebDriver PID: $DRIVER_PID"
fi

sleep 3

# 4. Run Codeception acceptance tests
echo "▶ Running Codeception acceptance tests..."
set +e
if [ $# -eq 0 ]; then
  vendor/bin/codecept run Acceptance,NoJs
else
  vendor/bin/codecept run "$@"
fi
TEST_EXIT=$?
set -e

exit $TEST_EXIT
