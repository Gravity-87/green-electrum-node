# Green Electrum Node (Tor-only)

Public Electrum endpoint over Tor, privacy-first and self-hosted.

## Status

http://yfo2ys4ddwlwkshcdrfr2kndhe7c3hutpcvqyiiwbm2djddjmm2dj4id.onion/

## Endpoint

- **Host:** `yfo2ys4ddwlwkshcdrfr2kndhe7c3hutpcvqyiiwbm2djddjmm2dj4id.onion`
- **Port:** `50001`
- **Protocol:** `tcp` (`:t`)
- **Network:** `mainnet`

## Quick connect (Electrum desktop)

1. Disable auto-connect
2. Add server manually:
   `yfo2ys4ddwlwkshcdrfr2kndhe7c3hutpcvqyiiwbm2djddjmm2dj4id.onion:50001:t`

## Notes

- Public endpoint
- Tor-only access
- No tracking on microsite

Use `./scripts/git-update.sh --help` for all options.


## Operations (Quick Guide)

### 1) GitHub aktualisieren (Code-Versionierung)
Use when you changed files and want to save/publish in GitHub.

- Preview:
  `./scripts/git-update.sh --all --dry-run`
- Commit + push all:
  `./scripts/git-update.sh --all -m "your message"`
- Commit + push selected files:
  `./scripts/git-update.sh --files "html/index.html html/style.css" -m "your message"`
- Help function:
  `./scripts/git-update.sh --help`

### 2) Live-Status prüfen (ohne Änderungen)
Use to quickly check container + status endpoint + Tor health.

- `./scripts/deploy.sh status`

### 3) Deploy auf Server (Änderungen live schalten)
Use after infra/config changes or when you want a clean restart/check.

- Preview:
  `./scripts/deploy.sh --dry-run`
- Apply:
  `./scripts/deploy.sh`
- With git pull before deploy:
  `./scripts/deploy.sh --pull`

### 4) Minimal release flow (GitHub + Deploy)
Use when you want one command for both steps.

- `./scripts/release.sh --all -m "your message"`
