#!/usr/bin/env bash
# Smoke test: runs the app in demo mode on PHP's built-in server and checks every endpoint.
# Usage: tests/smoke.sh   (from the repo root)
set -euo pipefail

PORT="${PORT:-8765}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
LOG="$(mktemp)"
fail() { echo "FAIL: $*" >&2; cat "$LOG" >&2; exit 1; }

if [ -f "$ROOT/config.php" ]; then
  echo "config.php exists — move it aside first so the test runs in demo mode." >&2
  exit 2
fi

rm -f "$ROOT"/cache/*.json
php -S "127.0.0.1:$PORT" -t "$ROOT" >"$LOG" 2>&1 &
SERVER=$!
trap 'kill $SERVER 2>/dev/null || true; rm -f "$ROOT"/cache/*.json "$LOG"' EXIT
for _ in $(seq 1 50); do curl -s "http://127.0.0.1:$PORT/" >/dev/null && break; sleep 0.1; done

get() { curl -sf "http://127.0.0.1:$PORT/$1" || fail "GET $1"; }
check() { # url, php-expression on $d that must be true
  local body; body="$(get "$1")"
  php -r '$d = json_decode(stream_get_contents(STDIN), true); if (!is_array($d) || isset($d["error"]) || !('"$2"')) { fwrite(STDERR, substr(json_encode($d), 0, 400)); exit(1); }' <<<"$body" \
    || fail "$1 — expected: $2"
  echo "ok  $1"
}

get "" | grep -q "GA4 Portfolio Dashboard" || fail "index page"
echo "ok  index"

check "api.php?action=properties"            'count($d["properties"]) > 5 && $d["meta"]["demo"] === true'
check "api.php?action=realtime"              '$d["totals"]["users30"] > 0 && count($d["minutes"]) === 30 && count($d["sites"]) > 5'
check "api.php?action=summary&days=7"        'count($d["series"]) === 7 && $d["period"]["cur"]["activeUsers"] > 0 && is_string($d["series"][0]["date"])'
check "api.php?action=summary&days=28"       'count($d["series"]) === 28 && count($d["hourly"]["users"]["yest"]) === 24'
PID="$(get "api.php?action=properties" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["properties"][0]["id"];')"
check "api.php?action=property&id=$PID&days=28" 'count($d["pages"]) > 0 && count($d["channels"]) > 0 && $d["realtime"]["totals"]["users30"] >= 0'

code="$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:$PORT/api.php?action=property&id=1")"
[ "$code" = "404" ] || fail "unknown property should 404, got $code"
echo "ok  unknown property → 404"

code="$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:$PORT/api.php?action=nope")"
[ "$code" = "400" ] || fail "unknown action should 400, got $code"
echo "ok  unknown action → 400"

echo "All smoke tests passed."
