#!/usr/bin/env bash
set -euo pipefail

PROJECT_DIR="/home/umbrel/green-electrum-site"
STATUS_URL="http://127.0.0.1:8090/status.php"
UPTIME_URL="http://127.0.0.1:8090/uptime.php"
STATE_DIR="/home/umbrel/green-electrum-site/data"

DOCKER_CMD="docker"
if ! docker ps >/dev/null 2>&1; then
  DOCKER_CMD="sudo docker"
fi


ok()   { echo -e "\e[32m[OK]\e[0m $*"; }
warn() { echo -e "\e[33m[WARN]\e[0m $*"; }
err()  { echo -e "\e[31m[ERR]\e[0m $*"; }

echo "== Post-update check =="
echo "Date: $(date)"
echo

# 1) docker compose services
if [[ -f "$PROJECT_DIR/docker-compose.yml" ]]; then
  cd "$PROJECT_DIR"
  if $DOCKER_CMD compose ps >/tmp/ge_ps.txt 2>/tmp/ge_ps_err.txt; then
    ok "docker compose reachable"
    cat /tmp/ge_ps.txt
  else
    err "docker compose ps failed"
    cat /tmp/ge_ps_err.txt || true
    exit 1
  fi
else
  err "Missing docker-compose.yml in $PROJECT_DIR"
  exit 1
fi

echo
# 2) status.php reachable + key fields
if STATUS_JSON="$(curl -fsS "$STATUS_URL")"; then
  ok "status.php reachable"
else
  err "status.php not reachable at $STATUS_URL"
  exit 1
fi

status=$(echo "$STATUS_JSON" | jq -r '.status // empty')
height=$(echo "$STATUS_JSON" | jq -r '.blockheight // empty')
pace_samples=$(echo "$STATUS_JSON" | jq -r '.block_pace_samples // empty')
pace_avg=$(echo "$STATUS_JSON" | jq -r '.block_pace_avg_sec // empty')

if [[ "$status" == "online" ]]; then ok "status=online"; else warn "status=$status"; fi
if [[ -n "$height" && "$height" != "null" ]]; then ok "blockheight=$height"; else warn "blockheight missing"; fi
if [[ -n "$pace_samples" && "$pace_samples" != "null" ]]; then ok "block_pace_samples=$pace_samples"; else warn "block_pace_samples missing"; fi
if [[ -n "$pace_avg" && "$pace_avg" != "null" ]]; then ok "block_pace_avg_sec=$pace_avg"; else warn "block_pace_avg_sec is null (can be normal with few samples)"; fi

echo
# 3) uptime.php reachable + key fields
if UPTIME_JSON="$(curl -fsS "$UPTIME_URL")"; then
  ok "uptime.php reachable"
else
  err "uptime.php not reachable at $UPTIME_URL"
  exit 1
fi

uptime_pct=$(echo "$UPTIME_JSON" | jq -r '.uptime_24h_pct // empty')
coverage_pct=$(echo "$UPTIME_JSON" | jq -r '.coverage_24h_pct // empty')
incidents=$(echo "$UPTIME_JSON" | jq -r '.incidents_24h // empty')

if [[ -n "$uptime_pct" && "$uptime_pct" != "null" ]]; then ok "uptime_24h_pct=$uptime_pct"; else warn "uptime_24h_pct missing"; fi
if [[ -n "$coverage_pct" && "$coverage_pct" != "null" ]]; then ok "coverage_24h_pct=$coverage_pct"; else warn "coverage_24h_pct missing"; fi
if [[ -n "$incidents" && "$incidents" != "null" ]]; then ok "incidents_24h=$incidents"; else warn "incidents_24h missing"; fi

echo
# 4) state files
for f in block_pace_state.json uptime.log uptime.lock; do
  if [[ -e "$STATE_DIR/$f" ]]; then
    ok "state file exists: $STATE_DIR/$f"
  else
    warn "state file missing: $STATE_DIR/$f"
  fi
done

echo
# 5) quick JSON validity checks
if echo "$STATUS_JSON" | jq . >/dev/null 2>&1; then ok "status.php returns valid JSON"; else err "status.php invalid JSON"; fi
if echo "$UPTIME_JSON" | jq . >/dev/null 2>&1; then ok "uptime.php returns valid JSON"; else err "uptime.php invalid JSON"; fi

echo
ok "Post-update check complete."
