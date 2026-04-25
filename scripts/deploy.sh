#!/usr/bin/env bash
set -euo pipefail

# =========================
# Defaults
# =========================
ACTION="deploy"      # deploy | status
DRY_RUN=0
DO_PULL=0
RESTART_ONLY=0
CHECK_TOR=1
ASSUME_YES=0
PROJECT_DIR=""
COMPOSE_FILE="docker-compose.yml"

# =========================
# Helpers
# =========================
usage() {
  cat << 'USAGE'
Usage:
  ./scripts/deploy.sh [deploy|status] [options]

Commands:
  deploy              Apply changes (default)
  status              Show runtime status only (no changes)

Options:
  --pull              git pull --rebase origin main before deploy
  --restart-only      use 'docker compose restart' instead of 'up -d'
  --no-tor-check      skip Tor bootstrap check
  --dry-run           show actions only, do not execute
  -y, --yes           skip confirmation prompts
  -d, --dir PATH      project directory (auto-detected if omitted)
  -h, --help, /?      show this help

Examples:
  ./scripts/deploy.sh status
  ./scripts/deploy.sh --dry-run
  ./scripts/deploy.sh deploy --pull
USAGE
}

info() { echo -e "\033[1;34m[INFO]\033[0m $*"; }
ok()   { echo -e "\033[1;32m[OK]\033[0m   $*"; }
warn() { echo -e "\033[1;33m[WARN]\033[0m $*"; }
fail() { echo -e "\033[1;31m[ERR]\033[0m  $*"; exit 1; }

run_cmd() {
  if [[ "$DRY_RUN" -eq 1 ]]; then
    echo "[DRY-RUN] $*"
  else
    eval "$@"
  fi
}

detect_repo_root() {
  local script_dir
  script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
  if [[ -n "$PROJECT_DIR" ]]; then
    echo "$PROJECT_DIR"
  else
    git -C "$script_dir" rev-parse --show-toplevel 2>/dev/null || true
  fi
}

# =========================
# Parse args
# =========================
if [[ $# -gt 0 ]]; then
  case "$1" in
    deploy|status) ACTION="$1"; shift ;;
  esac
fi

while [[ $# -gt 0 ]]; do
  case "$1" in
    --pull) DO_PULL=1; shift ;;
    --restart-only) RESTART_ONLY=1; shift ;;
    --no-tor-check) CHECK_TOR=0; shift ;;
    --dry-run) DRY_RUN=1; shift ;;
    -y|--yes) ASSUME_YES=1; shift ;;
    -d|--dir)
      shift
      [[ $# -gt 0 ]] || fail "--dir needs a path"
      PROJECT_DIR="$1"
      shift
      ;;
    -h|--help|"/?")
      usage; exit 0
      ;;
    *)
      fail "Unknown option: $1 (use --help)"
      ;;
  esac
done

PROJECT_DIR="$(detect_repo_root)"
[[ -n "$PROJECT_DIR" ]] || fail "Could not detect repo root. Use --dir PATH."
cd "$PROJECT_DIR"
[[ -f "$COMPOSE_FILE" ]] || fail "No $COMPOSE_FILE found in $PROJECT_DIR"

# =========================
# Status function
# =========================
show_status() {
  info "Project: $PROJECT_DIR"
  info "Container status:"
  run_cmd "sudo docker compose ps"

  info "HTTP status endpoint:"
  if [[ "$DRY_RUN" -eq 1 ]]; then
    echo "[DRY-RUN] curl -fsS http://127.0.0.1:8090/status.php"
  else
    if curl -fsS http://127.0.0.1:8090/status.php; then
      echo
      ok "status.php reachable"
    else
      echo
      warn "status.php not reachable"
    fi
  fi

  if [[ "$CHECK_TOR" -eq 1 ]]; then
    info "Tor health check:"
    if [[ "$DRY_RUN" -eq 1 ]]; then
      echo "[DRY-RUN] sudo docker compose ps tor"
      echo "[DRY-RUN] sudo docker logs --tail=120 green-electrum-tor"
    else
      # 1) Primär: Container läuft?
      tor_state="$(sudo docker compose ps --format json tor 2>/dev/null | sed -n 's/.*"State":"\([^"]*\)".*/\1/p' | head -n1 || true)"

      if [[ -z "${tor_state:-}" ]]; then
        # Fallback, falls JSON-Format nicht verfügbar
        tor_state="$(sudo docker inspect -f '{{.State.Status}}' green-electrum-tor 2>/dev/null || true)"
      fi

      if [[ "$tor_state" == "running" ]]; then
        ok "Tor container is running."
      else
        warn "Tor container state: ${tor_state:-unknown}"
      fi

      # 2) Zusatzinfo aus Logs (nur informativ)
      tor_log="$(sudo docker logs --tail=120 green-electrum-tor 2>&1 || true)"
      if echo "$tor_log" | grep -qiE "Bootstrapped 100%.*Done|Bootstrapped 100% \(done\)"; then
        ok "Tor bootstrap marker found in recent logs."
      else
        info "No recent bootstrap marker in last logs (normal on long-running container)."
      fi
    fi
  fi
}

# =========================
# Change analysis
# =========================
analyze_changes() {
  mapfile -t changed < <(git status --porcelain | sed 's/^...//')
  if [[ ${#changed[@]} -eq 0 ]]; then
    info "No local git changes detected."
    echo "none"
    return
  fi

  local infra=0
  local frontend_only=1

  for f in "${changed[@]}"; do
    # infra files -> deploy recommended
    if [[ "$f" =~ ^docker-compose\.yml$ || "$f" =~ ^torrc$ || "$f" =~ ^stunnel\.conf$ || "$f" =~ ^tor/ || "$f" =~ ^Dockerfile ]]; then
      infra=1
    fi

    # if anything outside static frontend appears, not frontend-only
    if [[ ! "$f" =~ ^html/.*\.(html|htm|css|js|png|jpg|jpeg|svg|webp|gif)$ ]]; then
      frontend_only=0
    fi
  done

  echo
  info "Detected changed files:"
  printf ' - %s
' "${changed[@]}"

  if [[ "$infra" -eq 1 ]]; then
    echo
    warn "Infra/config changes detected -> DEPLOY recommended."
    echo "infra"
  elif [[ "$frontend_only" -eq 1 ]]; then
    echo
    warn "Only static frontend changes detected -> deploy usually optional (browser refresh often enough)."
    echo "frontend-only"
  else
    echo
    info "App/runtime changes detected (e.g. PHP/scripts) -> deploy optional but recommended for consistency."
    echo "app"
  fi
}

confirm_continue() {
  local recommendation="$1"
  if [[ "$ASSUME_YES" -eq 1 || "$DRY_RUN" -eq 1 ]]; then
    return
  fi

  echo
  case "$recommendation" in
    frontend-only)
      read -rp "Proceed with deploy anyway? [y/N]: " ans
      [[ "${ans,,}" == "y" ]] || { info "Cancelled."; exit 0; }
      ;;
    none)
      read -rp "No changes detected. Run deploy/status anyway? [y/N]: " ans
      [[ "${ans,,}" == "y" ]] || { info "Cancelled."; exit 0; }
      ;;
    *)
      read -rp "Proceed with deploy? [Y/n]: " ans
      [[ -z "$ans" || "${ans,,}" == "y" ]] || { info "Cancelled."; exit 0; }
      ;;
  esac
}

# =========================
# Main flow
# =========================
if [[ "$ACTION" == "status" ]]; then
  show_status
  exit 0
fi

rec="$(analyze_changes)"
confirm_continue "$rec"

if [[ "$DO_PULL" -eq 1 ]]; then
  info "Running git pull --rebase origin main ..."
  run_cmd "git pull --rebase origin main"
fi

if [[ "$RESTART_ONLY" -eq 1 ]]; then
  info "Restarting services ..."
  run_cmd "sudo docker compose restart"
else
  info "Applying compose changes (up -d) ..."
  run_cmd "sudo docker compose up -d"
fi

echo
show_status
ok "Deploy finished."
