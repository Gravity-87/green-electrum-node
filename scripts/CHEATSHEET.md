# CHEATSHEET.md
Operational cheat sheet for `green-electrum-site`

---

## 0) Projektordner

    cd /home/umbrel/green-electrum-site

---

## 1) Was ist wofür?

- `scripts/git-update.sh`  
  → Änderungen in Git committen + nach GitHub pushen (mit Safety-Checks)

- `scripts/deploy.sh`  
  → Lokal/Server live schalten und Health prüfen (Container, status.php, Tor)

- `scripts/release.sh`  
  → Minimaler Wrapper: erst GitHub-Update, dann Deploy

---

## 2) Typische Workflows

### A) Nur schnell prüfen, ob alles läuft

    ./scripts/deploy.sh status

### B) Nur Code zu GitHub (kein Deploy)

    ./scripts/git-update.sh --all -m "Your commit message"

### C) Nur ausgewählte Dateien zu GitHub

    ./scripts/git-update.sh --files "html/index.html html/style.css" -m "UI tweaks"

### D) Deploy ausführen (live schalten)

    ./scripts/deploy.sh

### E) Alles in einem Schritt (GitHub + Deploy)

    ./scripts/release.sh --all -m "Your release message"

---

## 3) Dry-Run (sicher testen ohne Änderungen)

### Git update dry-run

    ./scripts/git-update.sh --all --dry-run

### Deploy dry-run

    ./scripts/deploy.sh --dry-run

### Release dry-run

    ./scripts/release.sh --all --dry-run

---

## 4) Wann brauche ich Deploy wirklich?

### Deploy meist nötig bei Änderungen an:
- `docker-compose.yml`
- `torrc`
- `stunnel.conf`
- PHP-Dateien (`html/*.php`)
- Container-/Service-Setup

### Deploy oft optional bei:
- `html/*.css`
- `html/*.js`
- `html/*.html`

(bei reinem Frontend reicht oft Browser-Reload)

---

## 5) Standard-Routine nach Änderungen (empfohlen)

1. Optional prüfen:

       ./scripts/deploy.sh status

2. GitHub aktualisieren:

       ./scripts/git-update.sh --all -m "Describe change"

3. Wenn nötig live schalten:

       ./scripts/deploy.sh

---

## 6) Wenn `git push` meckert (fetch first)

    git pull --rebase origin main
    git push

Bei Konflikten:
1. Konfliktdateien bearbeiten  
2. `git add <files>`  
3. `git rebase --continue`  
4. `git push`

---

## 7) Schnelle Status-/Diagnosebefehle

### Containerstatus

    sudo docker compose ps

### Status-JSON lokal

    curl -s http://127.0.0.1:8090/status.php

### Tor-Logs

    sudo docker logs --tail=120 green-electrum-tor

### Web-Logs

    sudo docker logs --tail=120 green-electrum-web

---

## 8) Nützliche Git-Befehle

### Geänderte Dateien

    git status --short

### Letzte Commits

    git log --oneline -n 10

### Unterschied anzeigen

    git diff

### Nur staged diff

    git diff --cached

---

## 9) Sicherheit / No-Go Dateien

Diese sollen nicht im Repo landen:
- `certs/`
- `tor-data/`
- `*.key`, `*.pem`, `*.p12`, `*.pfx`
- lokale Logs/Debug-Artefakte

Safety-Checks sind in `git-update.sh` eingebaut, aber trotzdem kurz mitdenken.

---

## 10) Häufige Probleme + Quick Fix

### Problem: „Last seen“ hängt
- Ursache oft: Schreibrechte (z. B. `html` read-only)
- Check:

      curl -s http://127.0.0.1:8090/status.php

### Problem: Onion nicht erreichbar
- Check:

      sudo docker compose ps
      sudo docker logs --tail=120 green-electrum-tor

- Auf laufenden Tor-Container achten

### Problem: Script startet im falschen Pfad
- Immer aus Repo starten:

      cd /home/umbrel/green-electrum-site

---

## 11) Hilfe zu Scripts

    ./scripts/git-update.sh --help
    ./scripts/deploy.sh --help
    ./scripts/release.sh --help

---

## 12) Persönliche Notiz (Empfehlung)

Wenn du unsicher bist:
1. erst `--dry-run`
2. dann echten Lauf

Das reduziert Fehler fast auf null.
