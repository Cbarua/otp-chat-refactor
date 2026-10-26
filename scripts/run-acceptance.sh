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

# A port that is already served (e.g. by `docker compose up`, the production-like stack) is reused as is.
port_busy() { lsof -nP -iTCP:"$1" -sTCP:LISTEN > /dev/null 2>&1; }

# When the compose stack runs, Codeception runs inside it too: the Cests reset logs/userlog.sqlite, and SQLite
# locks don't cross Docker Desktop's bind mount (a php-fpm child died with SIGBUS when the host wrote it).
IN_COMPOSE=""
if docker compose ps --status running -q app 2>/dev/null | grep -q .; then
  IN_COMPOSE=1
fi

# 1. Start front-end server
if port_busy 8080; then
  echo "▶ Front-end server already listening on http://localhost:8080, reusing it"
else
  echo "▶ Starting front-end server (http://localhost:8080)..."
  php -S localhost:8080 -t public > /dev/null 2>&1 &
  FRONT_PID=$!
  echo "   → PID $FRONT_PID"
fi

# 2. Start backend server
if port_busy 8081; then
  echo "▶ Mock API server already listening on http://localhost:8081, reusing it"
else
  echo "▶ Starting backend mock API server (http://localhost:8081)..."
  php -S localhost:8081 ./public/backend-test-api/index.php > /dev/null 2>&1 &
  BACKEND_PID=$!
  echo "   → PID $BACKEND_PID"
fi

sleep 2

# 3. Start WebDriver
DRIVER_ARGS="--port=10888"
if [[ -n "$IN_COMPOSE" ]]; then
  # The container reaches the driver as host.docker.internal; Docker Desktop proxies it from loopback, so the
  # LAN stays rejected.
  DRIVER_ARGS="$DRIVER_ARGS --allowed-ips=127.0.0.1 --allowed-origins=http://host.docker.internal:10888"
fi
if [[ "$ios" == "Linux" || "$ios" == "Darwin" ]]; then
  if [[ -x "$EDGE_DRIVER" ]]; then
    echo "▶ Starting Edge WebDriver: $EDGE_DRIVER $DRIVER_ARGS"
    $EDGE_DRIVER $DRIVER_ARGS > /dev/null 2>&1 &
    DRIVER_PID=$!
  elif [[ -x "$CHROME_DRIVER" ]]; then
    echo "▶ Starting Chrome WebDriver: $CHROME_DRIVER $DRIVER_ARGS"
    $CHROME_DRIVER $DRIVER_ARGS > /dev/null 2>&1 &
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
CODECEPT=(vendor/bin/codecept run)
if [[ -n "$IN_COMPOSE" ]]; then
  CODECEPT=(docker compose exec -T app vendor/bin/codecept run -o "modules: config: WebDriver: host: host.docker.internal")
  echo "▶ Running Codeception acceptance tests inside the compose app container..."
else
  echo "▶ Running Codeception acceptance tests..."
fi
set +e
if [ $# -eq 0 ]; then
  "${CODECEPT[@]}" Acceptance,NoJs
else
  "${CODECEPT[@]}" "$@"
fi
TEST_EXIT=$?
set -e

exit $TEST_EXIT
