# RECOVERY.md – Green Electrum Microsite (Umbrel)

Stand: 2026-04-28  
Projektpfad: `/home/umbrel/green-electrum-site`

---

## 1) Quick Overview

Diese Datei dokumentiert die Recovery-Schritte nach Umbrel-Updates für:

- `green-electrum-web` (PHP/Apache)
- `green-electrum-tor`
- `green-electrum-btcinfo` (Sidecar für Bitcoin Core Infos)
- Abhängigkeiten zu Umbrel Bitcoin / Electrs

---

## 2) Wichtige Pfade

- Projekt: `/home/umbrel/green-electrum-site`
- HTML: `/home/umbrel/green-electrum-site/html`
- State/Data: `/home/umbrel/green-electrum-site/data`
- Backup-Ordner: `/home/umbrel/backups/green-electrum-site`

---

## 3) Pre-Update (vor Umbrel-Upgrade)

### 3.1 Backup erstellen
```bash
/home/umbrel/green-electrum-site/backup-microsite.sh --print-scp

Optional „kaltes“ Backup:
/home/umbrel/green-electrum-site/backup-microsite.sh --stop --print-scp

### 3.2 Backup lokal ziehen (auf lokalem Rechner)
scp umbrel@<UMBREL-IP>:/home/umbrel/backups/green-electrum-site/<BACKUP>.tar.gz .
scp umbrel@<UMBREL-IP>:/home/umbrel/backups/green-electrum-site/<BACKUP>.tar.gz.sha256 .
sha256sum -c <BACKUP>.tar.gz.sha256

### 3.3 Vorheriger Health-Check
curl -s http://127.0.0.1:8090/status.php | jq '.status,.blockheight'
curl -s http://127.0.0.1:8090/uptime.php | jq '.uptime_24h_pct,.coverage_24h_pct'

---

## 4) Post-Update Standard Recovery

### 4.1 Container starten
cd /home/umbrel/green-electrum-site
sudo docker compose up -d
sudo docker compose ps

### 4.2 Schnelltest Endpoints

curl -s http://127.0.0.1:8090/status.php | jq '.status,.blockheight,.server_version,.mempool_vbytes,.fees'
curl -s http://127.0.0.1:8090/uptime.php | jq '.uptime_24h_pct,.coverage_24h_pct,.incidents_24h'
curl -s http://127.0.0.1:8090/btcinfo.php | jq

### 4.3 Vollcheck-Skript (Post-Update)

sudo /home/umbrel/green-electrum-site/post-update-check.sh

---

## 5) Bekannte Fehlerbilder + Fix

### 5.1 Problem: status.php online, aber blockheight = null, fees/mempool = null

Ursache: Electrs antwortet nicht korrekt oder ist instabil.

Prüfen:

sudo docker logs --tail 200 electrs_electrs_1
sudo docker exec -it green-electrum-web php -r '
$fp=@fsockopen("host.docker.internal",50001,$e,$s,5);
if(!$fp){echo "CONNECT_FAIL $e $s
"; exit(1);}
stream_set_timeout($fp,5);
fwrite($fp, "{\"id\":1,\"method\":\"server.version\",\"params\":[\"probe\",\"1.4\"]}
");
var_dump(fgets($fp));
'

Erwartung: 

Eine JSON-Zeile wie:

{"id":1,"jsonrpc":"2.0","result":["electrs/0.11.0","1.4"]}

### 5.2 Problem: Bitcoind/Electrs Fehler mit .cookie (Directory statt File)

Typische Logs:

   - Is a directory (os error 21)
   - Unable to rename cookie authentication file ...

Fix

sudo rm -rf /home/umbrel/umbrel/app-data/bitcoin/data/bitcoin/.cookie
sudo rm -f  /home/umbrel/umbrel/app-data/bitcoin/data/bitcoin/.cookie.tmp
sudo chown -R umbrel:umbrel /home/umbrel/umbrel/app-data/bitcoin/data/bitcoin
sudo docker restart bitcoin_app_1
sudo docker restart electrs_electrs_1

Prüfen mit:

sudo ls -l /home/umbrel/umbrel/app-data/bitcoin/data/bitcoin/.cookie

Muss Datei sein (-rw-------), kein Verzeichnis!

### 5.3 Problem: Infra-Card zeigt syncing/leer/falsche Version

Lösung: Infra-Card zieht aus btcinfo.php (Sidecar-Datei), nicht aus status.php.

loadBTC() erwartet:

    version (z. B. /Satoshi:30.2.0/)
    blocks
    sync (synced / syncing)

---

## 6) Soll-Zustand docker-compose (wichtig)

Web-Service sollte enthalten:

    ./html:/var/www/html
    /home/umbrel/green-electrum-site/data:/state
    /home/umbrel/umbrel/app-data/bitcoin/data:/run/bitcoin-data:ro
    extra_hosts: host.docker.internal:host-gateway

Nicht wieder zurück auf direkten File-Mount .cookie:/run/bitcoin/.cookie, wenn es vermeidbar ist.

---

## 7) Betriebschecks (laufend)

Containerstatus

cd /home/umbrel/green-electrum-site
sudo docker compose ps


Logs (bei Auffälligkeiten)

sudo docker compose logs --tail=100 web
sudo docker logs --tail=200 electrs_electrs_1
sudo docker logs --tail=200 bitcoin_app_1
sudo docker logs --tail=100 green-electrum-btcinfo


Endpoints

curl -s http://127.0.0.1:8090/status.php | jq
curl -s http://127.0.0.1:8090/uptime.php | jq
curl -s http://127.0.0.1:8090/btcinfo.php | jq

---

## 8) Recovery in 60 Sekunden (Kurzfassung)

cd /home/umbrel/green-electrum-site
sudo docker compose up -d
sudo docker compose ps
curl -s http://127.0.0.1:8090/status.php | jq '.status,.blockheight,.server_version'
curl -s http://127.0.0.1:8090/btcinfo.php | jq '.version,.blocks,.sync'

Wenn blockheight null bleibt:

  1.  sudo docker logs --tail 200 electrs_electrs_1
  2.  .cookie-Thema prüfen/fixen (siehe 5.2)

---

## 9) Restore (wenn alles brennt)

cd /home/umbrel
tar -xzf /home/umbrel/backups/green-electrum-site/<BACKUP>.tar.gz -C /home/umbrel/green-electrum-site
cd /home/umbrel/green-electrum-site
sudo docker compose up -d

---

## 10) Notizen

- Nach Umbrel-Updates starten Custom-Compose-Stacks ggf. nicht automatisch.
- restart: unless-stopped hilft, garantiert aber nicht jeden Sonderfall.
- Off-device Backup (lokal/NAS) bleibt Pflicht.
- post-update-check.sh nach jedem Upgrade laufen lassen.

---
