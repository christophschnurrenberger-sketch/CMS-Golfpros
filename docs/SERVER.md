# Eigener Server: TeePilot auf einem netcup-VPS

Diese Anleitung führt von einem leeren virtuellen Server bis zu einer
laufenden Anlage mit HTTPS, automatischem Upload aus GitHub, Mailversand,
eigenen Domains für die Golfschulen und nächtlicher Sicherung. Sie ist
für netcup geschrieben, gilt aber für jeden Server mit Debian.

Zeitbedarf: etwa eine Stunde, davon die meiste Zeit Warten auf DNS.

**Was du brauchst**

* den VPS bei netcup und die Zugangsdaten zum **Server Control Panel (SCP)**
  aus der Mail von netcup,
* eine Domain, bei der du DNS-Einträge setzen kannst (zum Beispiel bei
  IONOS),
* ein Postfach für den Mailversand (Adresse und Passwort),
* einen Rechner mit Terminal: unter Windows die **PowerShell**, auf dem
  Mac das **Terminal**.

**Was am Ende läuft**

| Teil | Was es ist |
|---|---|
| Debian 12 oder 13 | das Betriebssystem, Sicherheitsupdates spielen sich jede Nacht selbst ein |
| Caddy | Webserver, holt und erneuert die HTTPS-Zertifikate selbst – auch für die Domains der Golfschulen |
| PHP-FPM | führt TeePilot aus, als eigener Benutzer `teepilot` |
| SQLite | die Datenbank, eine Datei unter `/var/www/teepilot/data/` |
| Cronjob | Erinnerungen und Fälligkeiten alle 15 Minuten, Sicherung jede Nacht |

Alles Einrichten erledigt ein Skript: [`bin/server-einrichten.sh`](../bin/server-einrichten.sh).
Die Schritte hier davor und danach kann es nicht übernehmen.

> In den Befehlen steht `203.0.113.10` für die IP-Adresse deines Servers
> und `app.deine-domain.de` für die Adresse der Anlage. Beides durch die
> echten Werte ersetzen.

---

## Schritt 0 – Zwei Dinge vorher festlegen

**Die Adresse der Anlage.** Unter ihr melden sich die Pros an, und aus ihr
entstehen die Links in allen E-Mails. Gut ist eine eigene Subdomain,
zum Beispiel `app.deine-domain.de` oder `procms.newsletter-consulting.de`.
Später ändern geht, aber jeder schon verschickte Link zeigt dann auf die
alte Adresse.

**Das Postfach für den Versand.** Ein Server hat keinen Postausgang, wie
ihn ein Webspace mitbringt – TeePilot verschickt deshalb über SMTP, also
über ein gewöhnliches Postfach. Für den Anfang genügt eines bei deinem
Mailanbieter, etwa `noreply@deine-domain.de`. Server, Port und
Verschlüsselung stehen im Kundenbereich des Anbieters unter den
E-Mail-Einstellungen (bei IONOS zum Beispiel `smtp.ionos.de`, Port `587`,
STARTTLS). Wenn später viele Erinnerungen am Tag hinausgehen, lohnt sich
ein Versanddienst mit Serverstandort in der EU – die Einstellungen sind
dieselben.

---

## Schritt 1 – Debian auf den Server bringen

Ein neuer VPS bei netcup ist oft leer oder trägt ein System, das du nicht
willst. Das Betriebssystem spielst du im **Server Control Panel** auf:

1. Unter <https://www.servercontrolpanel.de> mit den SCP-Zugangsdaten aus
   der Mail von netcup anmelden. (Das ist nicht das Kundenkonto CCP, in
   dem die Rechnungen liegen.)
2. Den Server anklicken, dann **Medien → Images**.
3. **Debian 13** wählen – wenn es fehlt, **Debian 12**. Gibt es mehrere
   Fassungen, die schlanke nehmen („minimal" oder ohne Zusätze), kein
   Image mit vorinstalliertem Webserver oder Panel.
4. Bei der Partitionierung „eine große Partition" (oder wie es dort
   heißt: die ganze Platte für das System).
5. Wenn das Formular ein Feld für einen **SSH-Schlüssel** anbietet: den
   öffentlichen Schlüssel aus Schritt 2 dort eintragen. Sonst vergibt
   netcup ein Root-Passwort und zeigt es an oder schickt es per Mail.
6. Installation starten. Nach wenigen Minuten läuft der Server.

Die Beschriftungen im SCP ändern sich gelegentlich. Gesucht ist immer:
*ein fertiges Image installieren*. Gibt es das bei deinem Tarif nicht,
geht es auch von Hand: unter **Medien → DVD-Laufwerk** das offizielle
Debian-ISO einlegen, den Server neu starten, über die Konsole im SCP
(**Bildschirm** oder **VNC**) den Installer durchklicken und bei der
Softwareauswahl nur **SSH server** und **Standard-Systemwerkzeuge**
anhaken – keine Desktop-Umgebung, keinen Webserver.

**Notieren:** Unter **Netzwerk** stehen die **IPv4-Adresse** und die
**IPv6-Adresse** des Servers. Beide brauchst du gleich.

---

## Schritt 2 – Ein SSH-Schlüssel statt Passwort

Mit einem Schlüssel meldest du dich ohne Passwort an, und niemand kann das
Root-Passwort durchprobieren. Das Einrichtungsskript schaltet die Anmeldung
mit Passwort ab – aber nur, wenn ein Schlüssel hinterlegt ist.

**Auf deinem Rechner** einen Schlüssel erzeugen (Windows: PowerShell,
Mac: Terminal):

```
ssh-keygen -t ed25519 -C "mein-laptop"
```

Den vorgeschlagenen Speicherort mit Enter bestätigen und eine Passphrase
vergeben – sie schützt den Schlüssel, falls der Laptop wegkommt.

**Den öffentlichen Teil auf den Server bringen** (nur nötig, wenn du ihn
nicht schon in Schritt 1 im SCP eingetragen hast). Beim ersten Mal fragt
SSH, ob du dem Server vertraust – mit `yes` bestätigen –, danach nach dem
Root-Passwort von netcup.

Mac und Linux:

```
ssh-copy-id root@203.0.113.10
```

Windows (PowerShell):

```
type $env:USERPROFILE\.ssh\id_ed25519.pub | ssh root@203.0.113.10 "mkdir -p ~/.ssh && cat >> ~/.ssh/authorized_keys && chmod 700 ~/.ssh && chmod 600 ~/.ssh/authorized_keys"
```

**Prüfen:** `ssh root@203.0.113.10` muss jetzt ohne Root-Passwort
hineinführen (nur die Passphrase des Schlüssels wird gefragt). Erst wenn
das klappt, weiter.

> Falls du dich doch einmal aussperrst: Die Konsole im SCP (**Bildschirm**)
> funktioniert immer, auch ohne SSH.

---

## Schritt 3 – DNS-Eintrag setzen

Jetzt, damit er wirkt, während der Server eingerichtet wird. Beim Anbieter
der Domain:

| Typ | Name | Wert |
|---|---|---|
| A | `app` (für `app.deine-domain.de`) | die IPv4-Adresse |
| AAAA | `app` | die IPv6-Adresse |

Den AAAA-Eintrag entweder auf die IPv6 des Servers setzen oder ganz
weglassen – aber **nie auf eine alte Adresse zeigen lassen**. Let's Encrypt
prüft bevorzugt über IPv6; zeigt der Eintrag ins Leere, gibt es kein
Zertifikat.

Prüfen, ob er schon gilt (auf deinem Rechner):

```
nslookup app.deine-domain.de
```

Erscheint die Adresse des Servers, ist er da. Das dauert von wenigen
Minuten bis zu ein paar Stunden.

---

## Schritt 4 – Das Einrichtungsskript

Das Skript liegt im Repository unter `bin/server-einrichten.sh`. Auf GitHub
die Datei öffnen, oben rechts **Download raw file** – oder aus einem
ausgecheckten Repository nehmen. Dann auf den Server kopieren und dort
ausführen:

```
scp server-einrichten.sh root@203.0.113.10:/root/
ssh root@203.0.113.10
bash /root/server-einrichten.sh app.deine-domain.de technik@deine-domain.de
```

Die zweite Angabe ist die Adresse, an die Let's Encrypt schreibt, wenn ein
Zertifikat nicht erneuert werden kann. Das Skript braucht drei bis fünf
Minuten und sagt bei jedem Schritt, was es tut:

1. **System aktualisieren.** Dazu Caddy aus der offiziellen Paketquelle,
   PHP mit den Erweiterungen, die TeePilot braucht, `sqlite3`, `rsync`.
   Sicherheitsupdates spielen sich ab jetzt jede Nacht selbst ein.
   Zeitzone Berlin. Zwei Gigabyte Auslagerungsspeicher, falls keiner da
   ist.
2. **Firewall.** Offen sind nur SSH (22), HTTP (80) und HTTPS (443).
3. **Benutzer `teepilot`.** Unter ihm läuft PHP, und er lädt die Dateien
   hoch – wie auf einem Webspace. Er kann nur Dateien übertragen, keine
   Kommandozeile öffnen. Das Skript erzeugt für ihn einen
   **Upload-Schlüssel** für GitHub.
4. **SSH.** Anmeldung nur noch mit Schlüssel – sofern für root einer
   hinterlegt ist. Sonst bleibt es beim Passwort, und das Skript sagt es.
5. **PHP-FPM** mit eigenem Pool: Uploads bis 256 MB (Schwungvideos),
   Fehler ins Protokoll statt auf den Bildschirm, so viele Prozesse, wie
   der Arbeitsspeicher trägt.
6. **Caddyfile** unter `/etc/caddy/Caddyfile`:
   * die Anlage unter `app.deine-domain.de`,
   * jede Domain, die eine Golfschule in TeePilot einträgt – das
     Zertifikat entsteht beim ersten Aufruf, nachdem TeePilot bestätigt
     hat, dass die Domain zu einer Instanz gehört (`tls-freigabe.php`),
   * alle Sperren, die unter Apache in den `.htaccess`-Dateien stehen:
     `config.php`, `data/`, `lib/`, `bin/`, `tests/`, `docs/`, Punktdateien,
     Datenbankdateien; PHP-Dateien in `uploads/` werden nie ausgeführt,
     SVG aus `uploads/` wird zum Speichern angeboten,
   * die sauberen Adressen (`/preise`, `/blog/…`, `/api/v1/…`,
     `/master/…`).
7. **Cronjob und Sicherung.** Wartung alle 15 Minuten, jede Nacht um 3:30
   eine Sicherung nach `/var/backups/teepilot` (siehe unten).

Am Ende stehen die Werte, die du für GitHub brauchst. Das Skript kann
gefahrlos noch einmal laufen – etwa nachdem du einen SSH-Schlüssel
nachgetragen hast. Es ersetzt nur Dateien, die es selbst angelegt hat,
und hebt die alte Fassung als `.vorher` auf. Eigene Ergänzungen an Caddy
gehören nach `/etc/caddy/eigenes.caddy`; die bleiben.

---

## Schritt 5 – Den Upload aus GitHub umstellen

Auf GitHub im Repository unter **Settings → Secrets and variables →
Actions**. Die Werte hat das Skript am Ende ausgegeben:

| Art | Name | Wert |
|---|---|---|
| Secret | `FTP_SERVER` | IP-Adresse des Servers |
| Secret | `FTP_BENUTZER` | `teepilot` |
| Secret | `SFTP_SCHLUESSEL` | der private Upload-Schlüssel (siehe unten) |
| Secret | `SFTP_HOSTKEY` | die Zeile `ssh-ed25519 AAAA…`, die das Skript ausgegeben hat |
| Variable | `FTP_PROTOKOLL` | `sftp` |
| Variable | `FTP_VERZEICHNIS` | `/var/www/teepilot/` |
| Variable | `SEITEN_URL` | `https://app.deine-domain.de/login.php` |

**Den privaten Upload-Schlüssel** zeigst du auf dem Server an:

```
cat /root/teepilot-upload-schluessel
```

Alles von `-----BEGIN OPENSSH PRIVATE KEY-----` bis einschließlich
`-----END OPENSSH PRIVATE KEY-----` in das Secret `SFTP_SCHLUESSEL`
kopieren. Danach gehört er nicht mehr auf den Server:

```
rm /root/teepilot-upload-schluessel
```

`SFTP_HOSTKEY` sorgt dafür, dass GitHub nur mit genau diesem Server
spricht und nicht mit einem, der sich dazwischenschiebt. Das Secret
`FTP_PASSWORT` wird für diesen Server nicht gebraucht und kann weg, sobald
der Umzug fertig ist.

Dann einmal von Hand hochladen: **Actions → Auf den Webspace laden → Run
workflow**. Nach dem Lauf liegen die Dateien auf dem Server:

```
ls /var/www/teepilot
```

Die Seite antwortet aber erst nach Schritt 6 – es fehlt noch die
`config.php`.

---

## Schritt 6 – Daten übernehmen

### A: Umzug vom bisherigen Webspace

Die Anlage läuft schon, zum Beispiel bei IONOS unter
`newsletter-consulting.de/procms`. Mitkommen müssen `config.php`, der
Ordner `data/` (Datenbank und private Dateien wie Schwungvideos) und der
Ordner `uploads/`.

**1. Einen ruhigen Zeitpunkt wählen.** Was nach dem Kopieren auf dem alten
Webspace gebucht wird, fehlt auf dem neuen Server. Abends oder früh am
Morgen ist gut.

**2. Die Dateien direkt vom alten Webspace holen** – auf dem Server, ohne
Umweg über deinen Rechner. Zugangsdaten und Ordner sind dieselben, die
bisher in GitHub standen (`FTP_SERVER`, `FTP_BENUTZER`, `FTP_VERZEICHNIS`):

```
apt install -y lftp
mkdir -p /root/umzug && cd /root/umzug
lftp -u 'IONOS-BENUTZER' sftp://IONOS-SERVER -e "cd /PFAD/ZUR/ANLAGE; get config.php; mirror data data; mirror uploads uploads; bye"
```

`lftp` fragt nach dem Passwort des Webspace.

**3. An ihren Platz legen:**

```
cp /root/umzug/config.php /var/www/teepilot/config.php
rsync -a /root/umzug/data/ /var/www/teepilot/data/
rsync -a /root/umzug/uploads/ /var/www/teepilot/uploads/
chown -R teepilot:teepilot /var/www/teepilot
chmod 600 /var/www/teepilot/config.php
```

**4. Die `config.php` anpassen:**

```
nano /var/www/teepilot/config.php
```

* **Datenbank:** Der Pfad zeigt noch auf den alten Webspace
  (`/homepages/…/data/golfpro.sqlite`). Ersetzen durch
  `'path' => __DIR__ . '/data/golfpro.sqlite',`
* **`base_url`:** `'https://app.deine-domain.de'` – ohne Schrägstrich am
  Ende.
* **`secret`:** **nicht ändern.** Damit sind Anmeldelinks und Schlüssel
  signiert; ein neuer Wert machte jeden verschickten Link ungültig.
* **`mail`:** siehe Schritt 7.

Speichern mit `Strg+O`, Enter, beenden mit `Strg+X`.

**5. Prüfen:**

```
sqlite3 /var/www/teepilot/data/golfpro.sqlite "PRAGMA integrity_check;"
```

muss `ok` sagen. Dann `https://app.deine-domain.de/login.php` im Browser
öffnen und anmelden. `https://app.deine-domain.de/systemcheck.php` zeigt,
ob alles stimmt.

**6. Den alten Webspace umleiten.** Im Ordner der alten Anlage die Datei
`.htaccess` durch diese beiden Zeilen ersetzen:

```
RewriteEngine On
RewriteRule ^(.*)$ https://app.deine-domain.de/$1 [R=301,L]
```

Die Pfade sind auf beiden Seiten dieselben – jeder alte Link, auch aus
schon verschickten Mails, landet damit an der richtigen Stelle.
**Wichtig:** Erst die GitHub-Secrets umstellen (Schritt 5), dann umleiten.
Sonst lädt der nächste Push die alte `.htaccess` wieder hoch.

**7. Was sonst noch auf die alte Adresse zeigt:**

* ein Cronjob beim alten Hoster auf `cron.php` – dort löschen,
* die Webhook-Adresse bei **Stripe** – auf
  `https://app.deine-domain.de/webhook.php` ändern,
* ein Newslettersystem, das über `/api/v1/…` abholt – neue Adresse
  eintragen.

**8. Die alten Daten löschen**, sobald ein paar Tage alles läuft:
Datenbank, `data/` und `uploads/` auf dem alten Webspace sind
Kundendaten und gehören dort nicht auf Dauer hin. Die Umleitung bleibt.
Auch `/root/umzug` auf dem Server kann dann weg.

### B: Neu anfangen

Ohne bestehende Daten: `install.php` aus dem Repository einmal von Hand
hochladen (der automatische Upload lässt ihn absichtlich aus), aufrufen,
die drei Felder ausfüllen – und danach wieder löschen.

```
scp install.php root@203.0.113.10:/var/www/teepilot/
ssh root@203.0.113.10 "chown teepilot:teepilot /var/www/teepilot/install.php"
```

Dann `https://app.deine-domain.de/install.php` öffnen. Nach der
Einrichtung:

```
rm /var/www/teepilot/install.php
```

Den ersten Betreiber legst du auf der Kommandozeile an –
siehe [BETREIBER.md](BETREIBER.md):

```
runuser -u teepilot -- php /var/www/teepilot/bin/betreiber.php anlegen du@deine-domain.de "Dein Name"
```

---

## Schritt 7 – Mailversand über SMTP

In der `config.php` den Block `mail` so setzen:

```php
'mail' => [
    'from_name'  => 'TeePilot',
    'from_email' => 'noreply@deine-domain.de',
    'transport'  => 'smtp',
    'smtp'       => [
        'host'   => 'smtp.ionos.de',
        'port'   => 587,
        'user'   => 'noreply@deine-domain.de',
        'pass'   => 'das-Passwort-des-Postfachs',
        'secure' => 'tls',       // tls = STARTTLS auf 587, ssl = Port 465
    ],
],
```

**Was im Absender steht.** Ein Postausgang nimmt nur die Adresse des
Kontos an, mit dem man sich anmeldet – bei einer fremden lehnt er ab, oder
die Mail landet im Spam. TeePilot verschickt deshalb immer von
`from_email`, schreibt aber den Namen der Golfschule davor, und ihre
eigene Adresse (*Einstellungen → Allgemein → Absenderadresse*) steht als
**Antwortadresse** darin. Antwortet ein Kunde, landet die Antwort bei der Golfschule.

**Prüfen:**

```
runuser -u teepilot -- php /var/www/teepilot/bin/mail-test.php deine@adresse.de
```

Das sagt entweder „Angenommen" – dann im Postfach nachsehen, auch im
Spam-Ordner – oder woran es hing: Verbindung, Verschlüsselung, Anmeldung,
Absender oder Empfänger. Das Passwort erscheint nie in der Ausgabe.

---

## Schritt 8 – Eigene Domains der Golfschulen

Eine Golfschule will ihre Website unter `golfschule-mueller.de` statt
unter der Adresse der Anlage. Dafür ist am Server nichts zu tun:

1. **Beim Anbieter ihrer Domain** zwei Einträge:

   | Typ | Name | Wert |
   |---|---|---|
   | A | `@` (die Domain selbst) | IPv4 des Servers |
   | CNAME | `www` | `app.deine-domain.de` |

   Nur diese Einträge ändern. Die Nameserver und die **MX-Einträge**
   bleiben, wie sie sind – sonst kommt die E-Mail der Golfschule nicht
   mehr an. Einen alten AAAA-Eintrag entfernen oder auf die IPv6 des
   Servers setzen.

2. **In TeePilot** unter *Einstellungen → Allgemein → Eigene Domain* die
   Domain eintragen (ohne `www`, ohne `https://`).

3. Die Domain im Browser öffnen. Beim ersten Aufruf fragt Caddy bei
   TeePilot nach, ob die Domain zu einer Instanz gehört, und holt das
   Zertifikat. Das dauert ein paar Sekunden; danach ist die Seite unter
   HTTPS da. `www.` und die Domain ohne `www` zeigen dieselbe Instanz.

Warum `www` als CNAME und nicht auch als A-Eintrag: Ziehst du einmal auf
einen anderen Server um, änderst du nur den Eintrag von
`app.deine-domain.de`. Die Hauptdomain kann technisch kein CNAME sein und
müsste dann von jeder Golfschule nachgezogen werden.

Eine Domain, die in keiner Instanz eingetragen ist, bekommt kein
Zertifikat – auch wenn sie auf den Server zeigt. Ohne diese Prüfung
könnte jeder mit einer beliebigen Domain Zertifikate auf deine Kosten
bestellen und die Anlage in die Ratenbegrenzung von Let's Encrypt
treiben.

---

## Sicherung

**Was das Skript einrichtet.** Jede Nacht um 3:30 ein Ordner je Tag unter
`/var/backups/teepilot/`: die Datenbank (als stimmige Momentaufnahme, auch
wenn gerade jemand bucht), `config.php`, `data/` und `uploads/`. Vierzehn
Tage bleiben liegen. Unveränderte Dateien sind feste Verweise auf den
Vortag – vierzehn Nächte mit denselben Schwungvideos brauchen kaum mehr
Platz als eine.

Von Hand auslösen: `/usr/local/sbin/teepilot-sichern`

**Was es nicht kann:** Diese Sicherung liegt auf demselben Server. Gegen
einen Plattenschaden, einen gelöschten Server oder einen Einbruch hilft nur
eine Kopie woanders. Zwei einfache Wege:

* **Snapshots im SCP** (*Snapshots*): vor jedem größeren Eingriff einen
  anlegen. Das ist eine Kopie des ganzen Servers, mit einem Klick
  zurückgespielt.
* **Regelmäßig herunterladen**, zum Beispiel einmal die Woche den
  neuesten Tagesordner auf deinen Rechner:

  ```
  scp -r root@203.0.113.10:/var/backups/teepilot/2026-09-28 .
  ```

  oder – besser, weil es niemand vergisst – automatisch per `rsync` auf
  einen Speicherplatz bei einem anderen Anbieter.

**Zurückspielen:**

```
systemctl stop caddy
cp /var/backups/teepilot/2026-09-28/golfpro.sqlite /var/www/teepilot/data/golfpro.sqlite
rm -f /var/www/teepilot/data/golfpro.sqlite-wal /var/www/teepilot/data/golfpro.sqlite-shm
rsync -a /var/backups/teepilot/2026-09-28/uploads/ /var/www/teepilot/uploads/
chown -R teepilot:teepilot /var/www/teepilot
systemctl start caddy
```

---

## Laufender Betrieb

| Wofür | Befehl |
|---|---|
| Läuft alles? | `systemctl status caddy php*-fpm` |
| Protokoll des Webservers | `journalctl -u caddy -n 100` |
| PHP-Fehler | `tail -n 50 /var/log/teepilot/php-fehler.log` |
| Sicherungen | `ls /var/backups/teepilot` |
| Updates von Hand (einmal im Monat) | `apt update && apt full-upgrade` |
| Neustart nach Kernel-Update | `reboot` |
| Caddy nach einer Änderung | `caddy validate --config /etc/caddy/Caddyfile && systemctl reload caddy` |
| Probemail | `runuser -u teepilot -- php /var/www/teepilot/bin/mail-test.php du@adresse.de` |

Sicherheitsupdates von Debian spielen sich selbst ein. Caddy kommt aus
einer eigenen Paketquelle und wird mit `apt full-upgrade` aktuell
gehalten.

---

## Wenn es klemmt

**`ssh: connect to host … port 22: Connection refused`** – Der Server
läuft noch nicht fertig oder SSH ist nicht installiert. In der Konsole im
SCP nachsehen.

**`Permission denied (publickey)`** – Der Schlüssel ist nicht hinterlegt.
Über die Konsole im SCP anmelden und den öffentlichen Schlüssel in
`/root/.ssh/authorized_keys` eintragen.

**Die Seite lädt, aber ohne HTTPS / Zertifikatsfehler** – meist DNS. Mit
`nslookup app.deine-domain.de` prüfen, ob A- und AAAA-Eintrag auf den
Server zeigen. Dann `journalctl -u caddy -n 50` lesen: Caddy schreibt
genau hin, woran Let's Encrypt gescheitert ist.

**Eine Golfschulen-Domain bekommt kein Zertifikat** – Ist sie in TeePilot
genau so eingetragen, wie sie aufgerufen wird? Die Prüfung von Hand:

```
curl "http://127.0.0.1:9123/tls-freigabe.php?domain=golfschule-mueller.de"
```

`ja` heißt freigegeben; dann liegt es am DNS der Domain.

**`502 Bad Gateway`** – PHP läuft nicht: `systemctl restart php*-fpm`, dann
`journalctl -u 'php*-fpm' -n 50`.

**Upload aus GitHub scheitert mit `Host key verification failed`** – In
`SFTP_HOSTKEY` steht nicht der Schlüssel dieses Servers. Neu auslesen mit
`cat /etc/ssh/ssh_host_ed25519_key.pub` und die ersten beiden Teile
(`ssh-ed25519 AAAA…`) eintragen. Nach einer Neuinstallation des Servers ist
das immer nötig.

**Upload scheitert mit `Permission denied`** – `SFTP_SCHLUESSEL` ist
unvollständig kopiert (die Zeilen `-----BEGIN` und `-----END` gehören dazu)
oder `FTP_BENUTZER` ist nicht `teepilot`.

**Mails kommen nicht an** – `bin/mail-test.php` sagt, an welchem Schritt
es hängt. „Anmeldung: 535" heißt falsches Passwort; „STARTTLS" oder
„Zertifikat" heißt meist falscher Port zur Verschlüsselung (587 mit `tls`,
465 mit `ssl`).
