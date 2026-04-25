#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(git -C "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)" rev-parse --show-toplevel)"
cd "$ROOT_DIR"

echo "== Release: Git update =="
./scripts/git-update.sh "$@"

echo
echo "== Release: Deploy =="
./scripts/deploy.sh deploy -y
