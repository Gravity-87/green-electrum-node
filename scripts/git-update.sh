#!/usr/bin/env bash

# Examples starting the script....
#
# ./scripts/git-update.sh --help
# ./scripts/git-update.sh --all --dry-run
# ./scripts/git-update.sh --files "html/index.html html/status.php" --dry-run


set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="$(git -C "$SCRIPT_DIR" rev-parse --show-toplevel 2>/dev/null || true)"
if [[ -z "${REPO_DIR:-}" ]]; then
  echo "ERROR: Could not detect git repository root."
  exit 1
fi

# Dann immer aus Repo-root arbeiten:
cd "$REPO_DIR"


STAGE_MODE=""
COMMIT_MSG=""
DRY_RUN=0
FILES=()

usage() {
  cat << 'USAGE'
Usage:
  ./git-update.sh [options]

Options:
  -a, --all                 Stage all changes
  -f, --files "A B C"       Stage only specific files (space-separated)
  -m, --message "text"      Commit message (otherwise prompt)
  -d, --dir PATH            Repo directory (default: script directory)
      --dry-run             Show what would happen, do not commit/push
  -h, --help, /?            Show this help

Examples:
  ./git-update.sh --all -m "Update status logic"
  ./git-update.sh --files "html/index.html html/style.css" -m "UI polish"
  ./git-update.sh --all --dry-run
USAGE
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    -a|--all) STAGE_MODE="all"; shift ;;
    -f|--files)
      STAGE_MODE="files"; shift
      [[ $# -gt 0 ]] || { echo "ERROR: --files needs a value"; exit 1; }
      read -r -a FILES <<< "$1"; shift
      ;;
    -m|--message)
      shift
      [[ $# -gt 0 ]] || { echo "ERROR: --message needs a value"; exit 1; }
      COMMIT_MSG="$1"; shift
      ;;
    -d|--dir)
      shift
      [[ $# -gt 0 ]] || { echo "ERROR: --dir needs a path"; exit 1; }
      REPO_DIR="$1"; shift
      ;;
    --dry-run) DRY_RUN=1; shift ;;
    -h|--help|"/?") usage; exit 0 ;;
    *) echo "Unknown option: $1"; usage; exit 1 ;;
  esac
done

cd "$REPO_DIR"
git rev-parse --is-inside-work-tree >/dev/null 2>&1 || {
  echo "ERROR: Not a git repository: $REPO_DIR"
  exit 1
}

echo "== Repo: $REPO_DIR =="
echo "== Current changes =="
git status --short

if [[ -z "$(git status --porcelain)" ]]; then
  echo "No changes detected."
  exit 0
fi

if [[ -z "$STAGE_MODE" ]]; then
  read -rp "Stage [a]ll or [s]elected files? (a/s): " choice
  case "${choice,,}" in
    a) STAGE_MODE="all" ;;
    s) STAGE_MODE="files" ;;
    *) echo "Invalid choice."; exit 1 ;;
  esac
fi

CANDIDATES=()
if [[ "$STAGE_MODE" == "all" ]]; then
  # take changed tracked/untracked files from porcelain
  while IFS= read -r line; do
    f="${line:3}"
    [[ -n "$f" ]] && CANDIDATES+=("$f")
  done < <(git status --porcelain)
else
  if [[ ${#FILES[@]} -eq 0 ]]; then
    echo "Changed files:"
    git status --short
    read -rp "Enter files to stage (space-separated): " file_input
    read -r -a FILES <<< "$file_input"
  fi
  [[ ${#FILES[@]} -gt 0 ]] || { echo "No files given."; exit 1; }
  CANDIDATES=("${FILES[@]}")
fi

echo
echo "== Candidate files =="
printf '%s
' "${CANDIDATES[@]}"

# safety check
FORBIDDEN_HIT=0
echo
echo "== Safety check =="

for f in "${CANDIDATES[@]}"; do
  if [[ "$f" =~ ^certs/ ]] || \
     [[ "$f" =~ ^tor-data/ ]] || \
     [[ "$f" =~ \.key$ ]] || \
     [[ "$f" =~ \.pem$ ]] || \
     [[ "$f" =~ \.p12$ ]] || \
     [[ "$f" =~ \.pfx$ ]] || \
     [[ "$f" =~ ^html/debug\.php$ ]]; then
    echo "BLOCKED: $f"
    FORBIDDEN_HIT=1
  fi
done

if [[ "$FORBIDDEN_HIT" -eq 1 ]]; then
  echo "ERROR: Sensitive/forbidden files included."
  exit 1
fi

echo "OK: no blocked files detected."

if [[ "$DRY_RUN" -eq 1 ]]; then
  echo
  echo "== DRY RUN =="
  echo "Would run:"
  if [[ "$STAGE_MODE" == "all" ]]; then
    echo "  git add ."
  else
    echo "  git add ${CANDIDATES[*]}"
  fi
  [[ -n "$COMMIT_MSG" ]] && echo "  git commit -m \"$COMMIT_MSG\"" || echo "  git commit -m \"<your message>\""
  echo "  git pull --rebase origin main"
  echo "  git push"
  echo
  echo "No changes were committed/pushed."
  exit 0
fi

# real run
git reset -q
if [[ "$STAGE_MODE" == "all" ]]; then
  git add .
else
  git add "${CANDIDATES[@]}"
fi

if [[ -z "$COMMIT_MSG" ]]; then
  read -rp "Commit message: " COMMIT_MSG
fi
[[ -n "$COMMIT_MSG" ]] || { echo "Empty commit message. Abort."; exit 1; }

git commit -m "$COMMIT_MSG"

if ! git pull --rebase origin main; then
  echo "Rebase conflict. Resolve and continue manually:"
  echo "  git add <files>"
  echo "  git rebase --continue"
  echo "  git push"
  exit 1
fi

git push
echo "✅ Done."
