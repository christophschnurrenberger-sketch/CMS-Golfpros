#!/usr/bin/env bash
# ==========================================================================
# TeePilot – einen frischen Debian-Server einrichten
# --------------------------------------------------------------------------
# Als root auf dem Server ausführen:
#
#   bash server-einrichten.sh <adresse-der-anlage> <e-mail-fuer-zertifikate>
#   bash server-einrichten.sh app.teepilot.de technik@teepilot.de
#
# Gedacht für Debian 12 und 13. Die Schritte im Einzelnen und warum sie so
# sind, stehen in docs/SERVER.md. Kurz:
#
#   1. System aktualisieren, Sicherheitsupdates automatisch, Zeitzone
#   2. Firewall: nur SSH, HTTP und HTTPS
#   3. Benutzer „teepilot": lädt per SFTP hoch, sonst nichts; PHP läuft
#      unter ihm – wie auf einem Webspace
#   4. SSH nur noch mit Schlüssel – aber nur, wenn einer hinterlegt ist
#   5. PHP-FPM mit eigenem Pool, Caddy aus der offiziellen Paketquelle
#   6. Caddyfile: die Anlage, die eigenen Domains der Instanzen mit
#      Zertifikat auf Zuruf, alle Sperren aus der .htaccess
#   7. Cronjob alle 15 Minuten, Sicherung jede Nacht
#
# Mehrfach ausführen schadet nicht. Jeder Schritt prüft, ob er schon
# erledigt ist; ersetzt werden nur Dateien, die dieses Skript selbst
# angelegt hat – die alte Fassung bleibt als .vorher liegen.
# ==========================================================================
set -euo pipefail

APP_DOMAIN="${1:-}"
ACME_EMAIL="${2:-}"
BENUTZER="teepilot"
WURZEL="/var/www/teepilot"
SOCKET="/run/php/teepilot.sock"

schritt() { printf '\n\033[1;32m== %s\033[0m\n' "$1"; }
hinweis() { printf '\033[1;33m   %s\033[0m\n' "$1"; }
abbruch() { printf '\033[1;31mAbbruch: %s\033[0m\n' "$1" >&2; exit 1; }

# Eine Datei schreiben, die alte Fassung aufheben, wenn sie anders war.
schreiben() {
  local ziel="$1" neu
  neu="$(mktemp)"
  cat > "$neu"
  if [ -f "$ziel" ] && ! cmp -s "$neu" "$ziel"; then
    cp -a "$ziel" "$ziel.vorher"
  fi
  install -m "${2:-644}" "$neu" "$ziel"
  rm -f "$neu"
}

# --------------------------------------------------------------- Prüfen ---

[ "$(id -u)" = "0" ] || abbruch "bitte als root ausführen (oder mit sudo)."
[ -r /etc/os-release ] && . /etc/os-release
case "${ID:-}" in
  debian|ubuntu) ;;
  *) abbruch "gedacht für Debian (12 oder 13). Gefunden: ${PRETTY_NAME:-unbekannt}." ;;
esac
if ! printf '%s' "$APP_DOMAIN" | grep -Eq '^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$'; then
  abbruch "Aufruf: bash server-einrichten.sh app.deine-domain.de technik@deine-domain.de"
fi
printf '%s' "$ACME_EMAIL" | grep -Eq '^[^@ ]+@[^@ ]+\.[a-z]{2,}$' \
  || abbruch "Die zweite Angabe muss eine E-Mail-Adresse sein (Let's Encrypt schreibt dorthin, wenn ein Zertifikat klemmt)."

export DEBIAN_FRONTEND=noninteractive

# ------------------------------------------------------ 1. Grundsystem ---

schritt "1/7 System aktualisieren"
apt-get update -q
apt-get -y -q -o Dpkg::Options::=--force-confold full-upgrade
apt-get -y -q install ca-certificates curl gnupg ufw unattended-upgrades cron rsync sqlite3 \
  php-fpm php-cli php-sqlite3 php-mbstring php-curl php-gd

# Sicherheitsupdates spielt Debian damit jede Nacht selbst ein.
schreiben /etc/apt/apt.conf.d/20auto-upgrades <<'EOF'
APT::Periodic::Update-Package-Lists "1";
APT::Periodic::Unattended-Upgrade "1";
EOF

timedatectl set-timezone Europe/Berlin 2>/dev/null || ln -sf /usr/share/zoneinfo/Europe/Berlin /etc/localtime

# Kleine Server haben oft keinen Auslagerungsspeicher. Zwei Gigabyte als
# Puffer – damit ein großer Upload nicht den ganzen Server ausbremst.
if ! swapon --show | grep -q .; then
  if [ ! -f /swapfile ]; then
    fallocate -l 2G /swapfile 2>/dev/null || dd if=/dev/zero of=/swapfile bs=1M count=2048 status=none
    chmod 600 /swapfile
    mkswap /swapfile >/dev/null
  fi
  swapon /swapfile 2>/dev/null || true
  grep -q '^/swapfile ' /etc/fstab || echo '/swapfile none swap sw 0 0' >> /etc/fstab
fi

# ---------------------------------------------------------- 2. Firewall ---

schritt "2/7 Firewall"
ufw default deny incoming >/dev/null
ufw default allow outgoing >/dev/null
ufw allow 22/tcp >/dev/null
ufw allow 80/tcp >/dev/null
ufw allow 443/tcp >/dev/null
ufw allow 443/udp >/dev/null   # HTTP/3
ufw --force enable >/dev/null
ufw status | sed 's/^/   /'

# -------------------------------------------------------- 3. Benutzer ---

schritt "3/7 Benutzer $BENUTZER"
if ! id "$BENUTZER" >/dev/null 2>&1; then
  useradd --system --create-home --home-dir "/home/$BENUTZER" --shell /usr/sbin/nologin "$BENUTZER"
fi
# „*" heißt: kein Passwort, aber auch nicht gesperrt – die Anmeldung mit
# Schlüssel funktioniert, eine mit Passwort nie.
usermod -p '*' "$BENUTZER"
install -d -o "$BENUTZER" -g "$BENUTZER" -m 755 "$WURZEL"
install -d -o "$BENUTZER" -g "$BENUTZER" -m 755 "$WURZEL/data" "$WURZEL/uploads"
install -d -o "$BENUTZER" -g "$BENUTZER" -m 750 /var/log/teepilot
install -d -o "$BENUTZER" -g "$BENUTZER" -m 700 "/home/$BENUTZER/.ssh"

# Der Schlüssel für den automatischen Upload aus GitHub. Entsteht einmal;
# der private Teil gehört danach in GitHub und nicht auf diesen Server.
SCHLUESSEL="/root/teepilot-upload-schluessel"
if [ ! -s "/home/$BENUTZER/.ssh/authorized_keys" ]; then
  ssh-keygen -q -t ed25519 -N '' -C "github-upload-teepilot" -f "$SCHLUESSEL"
  install -o "$BENUTZER" -g "$BENUTZER" -m 600 "$SCHLUESSEL.pub" "/home/$BENUTZER/.ssh/authorized_keys"
  rm -f "$SCHLUESSEL.pub"
  chmod 600 "$SCHLUESSEL"
  NEUER_SCHLUESSEL=ja
else
  NEUER_SCHLUESSEL=nein
fi

# ------------------------------------------------------------- 4. SSH ---

schritt "4/7 SSH"
# Der Upload-Benutzer darf nur Dateien übertragen: keine Kommandozeile,
# keine Weiterleitungen. Der Block steht am Ende der Hauptdatei, weil ein
# Match-Block bis zum Dateiende gilt.
if ! grep -q '^# TeePilot: Upload-Benutzer' /etc/ssh/sshd_config; then
  cat >> /etc/ssh/sshd_config <<EOF

# TeePilot: Upload-Benutzer nur für SFTP (bin/server-einrichten.sh)
Match User $BENUTZER
    ForceCommand internal-sftp
    AllowTcpForwarding no
    X11Forwarding no
    PermitTTY no
EOF
fi

# Anmeldung nur noch mit Schlüssel. Ohne hinterlegten Schlüssel für root
# wäre das die Tür, die man hinter sich zuschließt, während man draußen
# steht – dann bleibt es bei einem Hinweis.
if [ -s /root/.ssh/authorized_keys ]; then
  schreiben /etc/ssh/sshd_config.d/10-teepilot.conf <<'EOF'
# TeePilot (bin/server-einrichten.sh): Anmeldung nur mit Schlüssel.
PasswordAuthentication no
KbdInteractiveAuthentication no
PermitRootLogin prohibit-password
EOF
  SSH_SCHLUESSEL=ja
else
  SSH_SCHLUESSEL=nein
fi
if sshd -t; then
  systemctl reload ssh 2>/dev/null || systemctl reload sshd
else
  abbruch "die SSH-Konfiguration ist fehlerhaft (sshd -t). Nichts neu geladen."
fi

# ------------------------------------------------------ 5. PHP & Caddy ---

schritt "5/7 Caddy und PHP"
if [ ! -f /usr/share/keyrings/caddy-stable-archive-keyring.gpg ]; then
  curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' \
    | gpg --dearmor --yes -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg
  curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' \
    > /etc/apt/sources.list.d/caddy-stable.list
  chmod o+r /usr/share/keyrings/caddy-stable-archive-keyring.gpg /etc/apt/sources.list.d/caddy-stable.list
  apt-get update -q
fi
apt-get -y -q install caddy

PHPV="$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')"
FPM="php$PHPV-fpm"
POOLS="/etc/php/$PHPV/fpm/pool.d"

# Wie viele PHP-Prozesse gleichzeitig: grob 60 MB je Prozess, 600 MB
# bleiben für System und Caddy. Mindestens 6, höchstens 40.
RAM_MB=$(( $(awk '/MemTotal/ {print $2}' /proc/meminfo) / 1024 ))
KINDER=$(( (RAM_MB - 600) / 60 ))
[ "$KINDER" -lt 6 ] && KINDER=6
[ "$KINDER" -gt 40 ] && KINDER=40

schreiben "$POOLS/teepilot.conf" <<EOF
; TeePilot (bin/server-einrichten.sh). PHP läuft als $BENUTZER – derselbe
; Benutzer, der hochlädt. So wie auf einem Webspace: Die Anwendung darf
; data/ und uploads/ beschreiben, Caddy liest nur, was öffentlich ist.
[teepilot]
user = $BENUTZER
group = $BENUTZER
listen = $SOCKET
listen.owner = caddy
listen.group = caddy
listen.mode = 0660

pm = dynamic
pm.max_children = $KINDER
pm.start_servers = 3
pm.min_spare_servers = 2
pm.max_spare_servers = 6
pm.max_requests = 500
request_terminate_timeout = 300

; Schwungvideos sind schnell 100 MB groß.
php_admin_value[upload_max_filesize] = 256M
php_admin_value[post_max_size] = 260M
php_admin_value[memory_limit] = 256M
php_admin_value[max_execution_time] = 120
php_admin_value[max_input_vars] = 5000
php_admin_value[date.timezone] = Europe/Berlin
php_admin_flag[display_errors] = off
php_admin_flag[log_errors] = on
php_admin_value[error_log] = /var/log/teepilot/php-fehler.log
EOF

schreiben "/etc/php/$PHPV/fpm/conf.d/90-teepilot.ini" <<'EOF'
; TeePilot (bin/server-einrichten.sh)
expose_php = Off
opcache.enable = 1
opcache.memory_consumption = 128
; Der Upload tauscht Dateien im laufenden Betrieb – geänderte Dateien
; müssen nach spätestens zwei Sekunden gelten.
opcache.validate_timestamps = 1
opcache.revalidate_freq = 2
EOF

# Der mitgelieferte Pool läuft als www-data und wird nicht gebraucht.
if [ -f "$POOLS/www.conf" ]; then
  mv "$POOLS/www.conf" "$POOLS/www.conf.aus"
fi

schreiben /etc/logrotate.d/teepilot <<'EOF'
/var/log/teepilot/*.log {
    weekly
    rotate 8
    compress
    missingok
    notifempty
    copytruncate
}
EOF

php-fpm$PHPV -t 2>&1 | sed 's/^/   /'
systemctl enable --now "$FPM" >/dev/null
systemctl restart "$FPM"

# ------------------------------------------------------- 6. Caddyfile ---

schritt "6/7 Caddyfile"
install -d -m 755 /etc/caddy
[ -f /etc/caddy/eigenes.caddy ] || printf '# Eigene Ergänzungen – bleiben erhalten, wenn das Skript erneut läuft.\n' > /etc/caddy/eigenes.caddy

schreiben /etc/caddy/Caddyfile <<EOF
# ==========================================================================
# TeePilot – geschrieben von bin/server-einrichten.sh
# --------------------------------------------------------------------------
# Wird ersetzt, wenn das Skript erneut läuft (die alte Fassung bleibt als
# Caddyfile.vorher). Eigene Ergänzungen gehören nach eigenes.caddy.
# Nach einer Änderung:  caddy validate --config /etc/caddy/Caddyfile
#                       systemctl reload caddy
# ==========================================================================
{
	email $ACME_EMAIL

	# Zertifikate für die eigenen Domains der Instanzen entstehen beim
	# ersten Aufruf – aber erst, nachdem die Anwendung bestätigt hat, dass
	# die Domain zu einer Instanz gehört (tls-freigabe.php).
	on_demand_tls {
		ask http://127.0.0.1:9123/tls-freigabe.php
	}
}

(teepilot) {
	root * $WURZEL
	encode zstd gzip

	# --- Was nie ausgeliefert wird -------------------------------------
	# Dasselbe, was unter Apache die .htaccess-Dateien sperren: Zugangs-
	# daten, Datenbank, Bibliotheken, Werkzeuge, Prüfungen, Unterlagen,
	# Punktdateien. 404 statt 403 – ein „verboten" bestätigt, dass es die
	# Datei gibt.
	@gesperrt {
		path /data/* /lib/* /bin/* /tests/* /docs/* /vendor/*
		path /app/partials/* /portal/partials/* /master/partials/*
		path /config.php /config.example.php /tls-freigabe.php
		path *.sqlite *.sqlite3 *.sqlite-wal *.sqlite-shm *.db *.log *.ini *.sql *.bak *.md *.sh
	}
	respond @gesperrt 404

	@punktdatei path_regexp /\.
	respond @punktdatei 404

	# Hochgeladenes wird ausgeliefert, nie ausgeführt.
	@hochgeladenerCode path_regexp (?i)^/(uploads|assets)/.*\.(php[0-9]?|phtml|phar|phps|pl|py|cgi|sh)$
	respond @hochgeladenerCode 404

	# SVG kann Skripte enthalten. Als Bild eingebunden läuft keines davon –
	# direkt geöffnet schon. Deshalb zum Speichern angeboten und in einen
	# Sandkasten gesperrt; als Logo oder Bild funktioniert es unverändert.
	@svg path_regexp (?i)^/uploads/.*\.svgz?$
	header @svg {
		Content-Disposition attachment
		Content-Security-Policy "default-src 'none'; style-src 'unsafe-inline'; sandbox"
	}

	header {
		X-Content-Type-Options nosniff
		Referrer-Policy strict-origin-when-cross-origin
		X-Frame-Options SAMEORIGIN
		Permissions-Policy "camera=(), microphone=(), geolocation=()"
		-Server
	}
	@statisch path /assets/* /uploads/*
	header @statisch Cache-Control "public, max-age=604800"

	# --- Saubere Adressen, wie in der .htaccess --------------------------
	@api path_regexp api ^/api/v1/([a-z]+)/?$
	rewrite @api /api.php?was={re.api.1}&{query}
	@beitrag path_regexp beitrag ^/blog/([A-Za-z0-9_-]+)/?$
	rewrite @beitrag /site.php?beitrag={re.beitrag.1}&{query}
	@blog path_regexp ^/blog/?$
	rewrite @blog /site.php?s=blog&{query}

	@mDashboard path_regexp ^/master/dashboard/?$
	rewrite @mDashboard /master/index.php?{query}
	@mInstanzen path_regexp ^/master/instances/?$
	rewrite @mInstanzen /master/instanzen.php?{query}
	@mInstanzNeu path_regexp ^/master/instances/new/?$
	rewrite @mInstanzNeu /master/instanz-neu.php?{query}
	@mInstanz path_regexp mi ^/master/instances/([0-9]+)/?$
	rewrite @mInstanz /master/instanz.php?id={re.mi.1}&{query}
	@mInstanzReiter path_regexp mr ^/master/instances/([0-9]+)/(activity|users|subscription|usage|settings)/?$
	rewrite @mInstanzReiter /master/instanz.php?id={re.mr.1}&reiter={re.mr.2}&{query}
	@mPakete path_regexp ^/master/packages/?$
	rewrite @mPakete /master/pakete.php?{query}
	@mRechnungen path_regexp ^/master/invoices/?$
	rewrite @mRechnungen /master/rechnungen.php?{query}
	@mRechnung path_regexp mre ^/master/invoices/([0-9]+)/?$
	rewrite @mRechnung /master/rechnung.php?id={re.mre.1}&{query}
	@mBenutzer path_regexp ^/master/users/?$
	rewrite @mBenutzer /master/benutzer.php?{query}
	@mAuswertung path_regexp ^/master/analytics/?$
	rewrite @mAuswertung /master/auswertung.php?{query}
	@mAktivitaet path_regexp ^/master/activity/?$
	rewrite @mAktivitaet /master/aktivitaet.php?{query}
	@mProtokoll path_regexp ^/master/audit-log/?$
	rewrite @mProtokoll /master/protokoll.php?{query}
	@mEinstellungen path_regexp ^/master/settings/?$
	rewrite @mEinstellungen /master/einstellungen.php?{query}
	@mSystem path_regexp ^/master/system/?$
	rewrite @mSystem /master/system.php?{query}

	# Alles andere mit einem einzigen Namen ist eine Seite der Website –
	# außer, es gibt eine Datei oder einen Ordner dieses Namens (/app/).
	@seite {
		not file {path} {path}/
		path_regexp seite ^/([A-Za-z0-9_-]+)/?$
	}
	rewrite @seite /site.php?s={re.seite.1}&{query}

	# Ordner führen zu ihrer index.php (/app/ -> /app/index.php); was es
	# nicht gibt, ist 404 – und nicht still die Startseite.
	php_fastcgi unix/$SOCKET {
		try_files {path}/index.php {path} =404
		read_timeout 300s
		write_timeout 300s
	}
	file_server
}

# Die Anlage selbst. HSTS setzt die Anwendung selbst (lib/bootstrap.php).
$APP_DOMAIN {
	import teepilot
}

# Die eigenen Domains der Instanzen: HTTPS ab dem ersten Aufruf.
https:// {
	tls {
		on_demand
	}
	import teepilot
}

http:// {
	redir https://{host}{uri} permanent
}

# Nur für Caddy selbst, nur auf diesem Rechner: die Frage, ob eine Domain
# ein Zertifikat bekommen darf.
http://127.0.0.1:9123 {
	bind 127.0.0.1
	root * $WURZEL
	rewrite * /tls-freigabe.php?{query}
	php_fastcgi unix/$SOCKET
}

import /etc/caddy/eigenes.caddy
EOF

caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile 2>&1 | tail -n 1 | sed 's/^/   /'
systemctl enable --now caddy >/dev/null
systemctl reload caddy || systemctl restart caddy

# -------------------------------------------- 7. Cronjob und Sicherung ---

schritt "7/7 Cronjob und Sicherung"
schreiben /usr/local/sbin/teepilot-sichern 755 <<'EOF'
#!/bin/sh
# TeePilot – nächtliche Sicherung (bin/server-einrichten.sh).
#
# Je Nacht ein Ordner unter /var/backups/teepilot mit Datenbank,
# config.php, data/ und uploads/. Unveränderte Dateien sind feste
# Verweise auf die Nacht davor und kosten keinen Platz – vierzehn Nächte
# Schwungvideos brauchen damit kaum mehr als eine.
#
# Die Datenbank wird mit .backup kopiert, nicht mit cp: Das ist eine
# stimmige Momentaufnahme, auch wenn gerade jemand bucht.
#
# Diese Sicherung liegt auf demselben Server. Gegen einen Plattenschaden
# oder einen gelöschten Server hilft nur eine Kopie woanders – siehe
# docs/SERVER.md, „Sicherung".
set -eu
QUELLE=/var/www/teepilot
ZIEL=/var/backups/teepilot
TAG=$(date +%Y-%m-%d)
NEU="$ZIEL/$TAG"
umask 077
mkdir -p "$ZIEL"
[ -f "$QUELLE/config.php" ] || exit 0

LETZTE=$(find "$ZIEL" -mindepth 1 -maxdepth 1 -type d -name '20*' ! -name "$TAG" | sort | tail -n 1)
mkdir -p "$NEU"
rsync -a --delete ${LETZTE:+--link-dest="$LETZTE"} --exclude='data/*.sqlite*' \
  "$QUELLE/config.php" "$QUELLE/data" "$QUELLE/uploads" "$NEU/"

DB=$(php -r '$c = require "/var/www/teepilot/config.php"; echo (string) ($c["db"]["path"] ?? "");')
if [ -n "$DB" ] && [ -f "$DB" ]; then
  sqlite3 "$DB" ".backup '$NEU/golfpro.sqlite'"
fi

find "$ZIEL" -mindepth 1 -maxdepth 1 -type d -name '20*' -mtime +14 -exec rm -rf {} +
EOF

schreiben /etc/cron.d/teepilot <<EOF
# TeePilot (bin/server-einrichten.sh)
SHELL=/bin/sh
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
# Wartung: Erinnerungen, Fälligkeiten, Warteschlangen – alle 15 Minuten.
*/15 * * * * $BENUTZER [ -f $WURZEL/cron.php ] && php $WURZEL/cron.php >/dev/null 2>&1
# Sicherung jede Nacht um 3:30.
30 3 * * * root /usr/local/sbin/teepilot-sichern
EOF

# ---------------------------------------------------------------- Fertig ---

IP4="$(ip -4 route get 1.1.1.1 2>/dev/null | awk '{for (i = 1; i < NF; i++) if ($i == "src") print $(i + 1)}')"
[ -n "$IP4" ] || IP4="$(hostname -I | awk '{print $1}')"
schritt "Fertig. Als Nächstes:"
cat <<EOF
   1. DNS: Beim Anbieter der Domain einen A-Eintrag
        $APP_DOMAIN  ->  $IP4
      anlegen. Sobald er gilt, holt Caddy das Zertifikat von selbst.

   2. GitHub (Settings -> Secrets and variables -> Actions):
        Secret  FTP_SERVER        $IP4
        Secret  FTP_BENUTZER      $BENUTZER
        Secret  SFTP_SCHLUESSEL   Inhalt von $SCHLUESSEL (siehe unten)
        Secret  SFTP_HOSTKEY      $(cut -d' ' -f1,2 /etc/ssh/ssh_host_ed25519_key.pub)
        Variable FTP_PROTOKOLL    sftp
        Variable FTP_VERZEICHNIS  $WURZEL/
      Das Secret FTP_PASSWORT wird für diesen Server nicht gebraucht.

   3. Daten und config.php übernehmen – docs/SERVER.md, „Umzug".
EOF
if [ "$NEUER_SCHLUESSEL" = "ja" ]; then
  hinweis "Der private Upload-Schlüssel liegt in $SCHLUESSEL."
  hinweis "Anzeigen:  cat $SCHLUESSEL   – komplett nach GitHub kopieren, dann:  rm $SCHLUESSEL"
fi
if [ "$SSH_SCHLUESSEL" = "nein" ]; then
  hinweis "Für root ist kein SSH-Schlüssel hinterlegt – die Anmeldung mit Passwort bleibt"
  hinweis "deshalb an. Schlüssel hinterlegen (docs/SERVER.md, Schritt 3), dann dieses Skript"
  hinweis "noch einmal ausführen."
fi
