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

## How to publish updates

In this repo, I use a script to publish my changed files:

Run a safe preview first: `./scripts/git-update.sh --all --dry-run`  
Publish all changes: `./scripts/git-update.sh --all -m "your commit message"`  
Publish selected files only: `./scripts/git-update.sh --files "html/index.html html/style.css" -m "your commit message"`  
Use `./scripts/git-update.sh --help` for all options.
