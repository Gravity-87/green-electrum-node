#!/usr/bin/env bash
set -euo pipefail

PROJECT_DIR="/home/umbrel/green-electrum-site"
BACKUP_ROOT="/home/umbrel/backups/green-electrum-site"
KEEP_LAST=10
STOP_FIRST=0
PRINT_SCP=0
HOST_HINT="${HOST_HINT:-$(hostname -I | awk '{print $1}')}"  # kann via env überschrieben werden

usage() {
  cat <<'EOF'
Usage:
  backup-microsite.sh [options]

Options:
  --stop              Stoppt docker compose vor Backup und startet danach wieder
  --keep N            Behalte N letzte Backups (Default: 10)
  --print-scp         Gibt nach Backup den scp-Befehl aus (zum lokalen Rechner)
  -h, --help          Hilfe anzeigen

Examples:
  backup-microsite.sh
  backup-microsite.sh --stop --print-scp
  HOST_HINT=umbrel.local backup-microsite.sh --print-scp
EOF
}

# --- args ---
while [[ $# -gt 0 ]]; do
  case "$1" in
    --stop) STOP_FIRST=1; shift ;;
    --keep) KEEP_LAST="${2:-}"; shift 2 ;;
    --print-scp) PRINT_SCP=1; shift ;;
    -h|--help) usage; exit 0 ;;
    *) echo "Unknown option: $1"; usage; exit 1 ;;
  esac
done

DATE_TAG="$(date +%F_%H%M%S)"
BACKUP_FILE="${BACKUP_ROOT}/green-electrum-site_${DATE_TAG}.tar.gz"
SHA_FILE="${BACKUP_FILE}.sha256"

mkdir -p "$BACKUP_ROOT"
echo "==> Backup started: ${BACKUP_FILE}"

if [[ "$STOP_FIRST" -eq 1 ]]; then
  echo "==> Stopping containers..."
  cd "$PROJECT_DIR"
  docker compose down
fi

for p in "$PROJECT_DIR/html" "$PROJECT_DIR/data" "$PROJECT_DIR/docker-compose.yml"; do
  [[ -e "$p" ]] || { echo "ERROR: Missing required path: $p"; exit 1; }
done

# normal tar first, fallback sudo (tor-data permissions)
if ! tar -czf "$BACKUP_FILE" \
  -C "$PROJECT_DIR" \
  html data tor-data torrc docker-compose.yml; then
  echo "==> Normal tar failed (likely tor-data permissions). Retrying with sudo..."
  sudo tar -czf "$BACKUP_FILE" \
    -C "$PROJECT_DIR" \
    html data tor-data torrc docker-compose.yml
  sudo chown umbrel:umbrel "$BACKUP_FILE"
fi

# checksum with basename only (portable for local verification)
(
  cd "$(dirname "$BACKUP_FILE")"
  sha256sum "$(basename "$BACKUP_FILE")" > "$(basename "$SHA_FILE")"
)

echo "==> Verifying checksum..."
(
  cd "$(dirname "$BACKUP_FILE")"
  sha256sum -c "$(basename "$SHA_FILE")"
)

if [[ "$STOP_FIRST" -eq 1 ]]; then
  echo "==> Starting containers again..."
  cd "$PROJECT_DIR"
  docker compose up -d
fi

echo "==> Cleanup old backups (keep last ${KEEP_LAST})..."
ls -1t "${BACKUP_ROOT}"/green-electrum-site_*.tar.gz 2>/dev/null | tail -n +$((KEEP_LAST + 1)) | xargs -r rm -f
ls -1t "${BACKUP_ROOT}"/green-electrum-site_*.tar.gz.sha256 2>/dev/null | tail -n +$((KEEP_LAST + 1)) | xargs -r rm -f

echo "==> Done."
echo "Backup: $BACKUP_FILE"
echo "SHA256: $SHA_FILE"

if [[ "$PRINT_SCP" -eq 1 ]]; then
  base="$(basename "$BACKUP_FILE")"
  echo
  echo "Run THIS on your local computer (outside SSH session):"
  echo "scp umbrel@${HOST_HINT}:${BACKUP_ROOT}/${base} ."
  echo "scp umbrel@${HOST_HINT}:${BACKUP_ROOT}/${base}.sha256 ."
fi
