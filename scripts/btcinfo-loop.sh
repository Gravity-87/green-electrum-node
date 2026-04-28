#!/bin/sh
set -eu

apk add --no-cache curl jq >/dev/null

while true; do
  COOKIE=""
  for p in /run/bitcoin-data/bitcoin/.cookie /run/bitcoin-data/.bitcoin/.cookie /run/bitcoin-data/.cookie; do
    [ -r "$p" ] && COOKIE="$p" && break
  done

  if [ -n "$COOKIE" ]; then
    CREDS="$(cat "$COOKIE")"
    U="${CREDS%%:*}"
    P="${CREDS#*:}"

    PAYLOAD='[{"jsonrpc":"1.0","id":"net","method":"getnetworkinfo","params":[]},{"jsonrpc":"1.0","id":"chain","method":"getblockchaininfo","params":[]}]'

    RAW="$(curl -s --max-time 4 --user "$U:$P" \
      -H "Content-Type: application/json" \
      --data "$PAYLOAD" \
      http://host.docker.internal:8332/ || true)"

    if [ -n "$RAW" ]; then
	echo "$RAW" | jq -c '{
	  version:(.[0].result.subversion // "unknown"),
	  blocks:(.[1].result.blocks // null),
	  sync:(if ((.[1].result.initialblockdownload // true) == false or ((.[1].result.verificationprogress // 0) >= 0.9999))
	        then "synced" else "syncing" end),
	  generated_at:(now|floor)
	}' > /state/btcinfo-host.json.tmp && mv /state/btcinfo-host.json.tmp /state/btcinfo-host.json
    fi
  fi

  sleep 60
done
