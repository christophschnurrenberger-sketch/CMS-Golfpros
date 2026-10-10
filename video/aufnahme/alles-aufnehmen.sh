#!/usr/bin/env bash
# Frische Demo-Anlage in einem Wegwerf-Ordner, App starten, Screens
# aufnehmen, App beenden, Ordner löschen.
#
#   bash aufnahme/alles-aufnehmen.sh          (aus dem Ordner video/)
#
# Die eigene Datenbank (data/golfpro.sqlite) und die config.php der Anlage
# werden nicht angefasst: Alles läuft gegen eine eigene SQLite-Datei mit dem
# Demo-Workspace, angelegt mit dem Datum von heute – damit Kalender und
# Portal „diese Woche“ auch wirklich Termine zeigen.
set -euo pipefail

HIER=$(cd "$(dirname "$0")" && pwd)
REPO=$(cd "$HIER/../.." && pwd)
PORT=${PORT:-8090}
ORDNER=$(mktemp -d "${TMPDIR:-/tmp}/teepilot-video-XXXXXX")
SERVER=""

aufraeumen() {
  [ -n "$SERVER" ] && kill "$SERVER" 2>/dev/null || true
  rm -rf "$ORDNER"
}
trap aufraeumen EXIT

cat > "$ORDNER/config.php" <<EOF
<?php return [
    'db' => ['driver' => 'sqlite', 'path' => '$ORDNER/video.sqlite'],
    'secret' => '$(php -r 'echo bin2hex(random_bytes(32));')',
    'base_url' => 'http://127.0.0.1:$PORT',
    'demo_zugang' => false,
    'mail' => ['from_name' => 'TeePilot', 'from_email' => 'video@example.org', 'transport' => 'keiner'],
    'debug' => false,
];
EOF

echo "Demo-Anlage anlegen …"
(cd "$REPO" && GP_CONFIG="$ORDNER/config.php" php -r '$_SERVER["REMOTE_ADDR"] = "127.0.0.1"; require "lib/bootstrap.php"; Demo::anlegen();')

echo "App starten auf Port $PORT …"
GP_CONFIG="$ORDNER/config.php" PHP_CLI_SERVER_WORKERS=4 \
  php -d log_errors=1 -d error_log="$ORDNER/fehler.log" -S "127.0.0.1:$PORT" -t "$REPO" > "$ORDNER/server.log" 2>&1 &
SERVER=$!
for _ in $(seq 1 50); do
  if php -r 'exit(@fsockopen("127.0.0.1", (int) $argv[1]) ? 0 : 1);' "$PORT"; then break; fi
  sleep 0.1
done

TEEPILOT_URL="http://127.0.0.1:$PORT" node "$HIER/aufnehmen.js"

if [ -s "$ORDNER/fehler.log" ]; then
  echo "Achtung – PHP hat während der Aufnahme Fehler gemeldet:" >&2
  cat "$ORDNER/fehler.log" >&2
  exit 1
fi
