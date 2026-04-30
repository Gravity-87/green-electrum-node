#!/bin/sh
set -eu

apk add --no-cache curl jq >/dev/null

LOG="/state/uptime-events.ndjson"
HEART="/state/uptimeprobe-heartbeat.txt"
i=0

while true; do
  ts="$(date +%s)"
  raw="$(curl -s --max-time 5 http://host.docker.internal:8090/status.php || true)"

  status="offline"
  latency="null"

  if [ -n "$raw" ]; then
    s="$(echo "$raw" | jq -r '.status // empty' 2>/dev/null || true)"
    l="$(echo "$raw" | jq -r '.latency // empty' 2>/dev/null || true)"

    case "$s" in
      online|offline) status="$s" ;;
    esac

    if echo "$l" | grep -Eq '^[0-9]+([.][0-9]+)?$'; then
      latency="$l"
    fi
  fi

  jq -cn \
    --argjson ts "$ts" \
    --arg status "$status" \
    --argjson latency_ms "$latency" \
    '{ts:$ts,status:$status,latency_ms:$latency_ms}' >> "$LOG"

  echo "$ts" > "$HEART"

  i=$((i+1))
  # alle ~60 min auf 8 Tage begrenzen
  if [ $((i % 240)) -eq 0 ]; then
    cutoff=$((ts - 8*24*3600))
    if [ -s "$LOG" ]; then
      jq -c "select(.ts >= $cutoff)" "$LOG" > "${LOG}.tmp" && mv "${LOG}.tmp" "$LOG"
    fi
  fi

  sleep 15
done
