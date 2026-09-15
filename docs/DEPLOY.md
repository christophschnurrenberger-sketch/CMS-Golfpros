# Automatischer Upload

Jeder Push auf den Hauptzweig lädt die geänderten Dateien per FTPS auf den
Webspace. Nichts wird gebaut, nichts wird kompiliert – das System hat
weder Composer noch Node, die Dateien im Repository sind schon die
Dateien, die laufen sollen.

Der Ablauf steht in [`.github/workflows/deploy.yml`](../.github/workflows/deploy.yml).

## Was einmal einzurichten ist

Auf GitHub unter **Settings → Secrets and variables → Actions**.

### Secrets (verschlüsselt, nicht mehr lesbar)

| Name           | Beispiel                | Woher                              |
|----------------|-------------------------|------------------------------------|
| `FTP_SERVER`   | `ftp.golfschule.de`     | Kundenbereich des Hosters          |
| `FTP_BENUTZER` | `w01234abc`             | ebenda                             |
| `FTP_PASSWORT` | –                       | ebenda                             |

Wenn der Hoster es anbietet, für diesen Zweck einen **eigenen FTP-Zugang**
anlegen, der nur in das Webverzeichnis darf. Dann liegt nicht das
Hauptpasswort bei GitHub, und der Zugang lässt sich einzeln sperren, ohne
dass sonst etwas stehen bleibt.

### Variables (im Klartext lesbar, keine Geheimnisse)

| Name              | Voreinstellung | Wofür                                              |
|-------------------|----------------|----------------------------------------------------|
| `FTP_VERZEICHNIS` | `./`           | Zielordner, **mit Schrägstrich am Ende**           |
| `FTP_PROTOKOLL`   | `ftps`         | `ftps`, `ftps-legacy` oder `ftp` – **nicht** `sftp`, siehe unten |
| `FTP_PORT`        | `21`           | nur wenn der Hoster einen anderen nennt            |
| `FTP_SICHERHEIT`  | `strict`       | `loose` bei einem Zertifikat, das nicht passt      |
| `SEITEN_URL`      | –              | z. B. `https://golfschule.de` – dann wird nach dem Upload nachgesehen, ob die Seite antwortet |

Alle fünf sind freiwillig. Ohne sie wird in den Wurzelordner geladen, per
FTPS über Port 21.

Solange `FTP_SERVER` fehlt, läuft der Workflow zwar durch, lädt aber
nichts hoch und sagt das als Warnung. Der erste Lauf nach dem Anlegen ist
also grün und nicht rot – er wartet nur auf die Zugangsdaten.

### SFTP ist nicht FTPS

Die beiden Namen sehen aus wie Varianten desselben, sind aber verschiedene
Protokolle:

* **FTPS** ist FTP mit TLS obendrauf – dieselben Befehle wie immer, nur
  verschlüsselt. Läuft über Port 21 (oder 990).
* **SFTP** ist Dateiübertragung durch eine SSH-Verbindung, Port 22. Mit FTP
  hat es außer den drei Buchstaben nichts gemeinsam.

Diese Action spricht nur die FTP-Familie. Steht `FTP_PROTOKOLL` auf
`sftp`, bricht der Lauf mit einem Hinweis darauf ab, statt in einer
unverständlichen Meldung zu enden.

Meist ist `ftps` gemeint. Bietet der Hoster tatsächlich nur SFTP an – das
kommt vor, vor allem bei Servern mit SSH-Zugang –, dann braucht es einen
anderen Upload-Weg; sag Bescheid, dann wird der Workflow entsprechend
umgebaut.

Der Zielordner heißt je nach Hoster anders. Üblich sind `./`, `/httpdocs/`,
`/html/`, `/public_html/` oder `/www/`. Einmal mit einem FTP-Programm
nachsehen, in welchem Ordner die Dateien landen sollen – das ist der Wert.

## Die erste Einrichtung

Beim ersten Durchlauf lädt der Abgleich alles hoch. Danach fehlt auf dem
Server noch zweierlei:

1. **`install.php`.** Die Datei wird bewusst nicht mitgeladen (siehe
   unten). Für die Einrichtung einmal von Hand hochladen – mit einem
   FTP-Programm oder direkt aus dem Repository heruntergeladen –, im
   Browser aufrufen, die drei Felder ausfüllen und sie danach wieder
   **löschen**.
2. **Schreibrechte** für `data/` und `uploads/`. Bei den meisten Hostern
   passt das von allein; wenn nicht, im FTP-Programm auf `755` setzen
   (manche Hoster brauchen `775`).

`systemcheck.php` sagt anschließend, ob alles stimmt. Die Seite ist nur
für angemeldete Inhaber sichtbar – und solange es noch keine `config.php`
gibt, für alle, weil sie dann beim Einrichten hilft.

Ab da genügt ein Push.

## Was der Upload nicht anfasst

Das ist der wichtigste Teil. Der Abgleich überträgt das Repository, und
das Repository enthält den Betrieb nicht:

* **`config.php`** – Zugangsdaten zur Datenbank, Stripe-Schlüssel,
  Mailversand. Steht in `.gitignore` und ist zusätzlich ausgeschlossen.
* **`data/*.sqlite`** – die Datenbank mit allen Kunden, Terminen und
  Rechnungen.
* **`uploads/w*/`** – Logos, Schwungvideos, Trainingsunterlagen.
  `uploads/.htaccess` und `uploads/index.html` gehen weiterhin mit; sie
  sperren den Ordner für den Browser.
* **`install.php`** – siehe oben. Ein Upload, der den Installer nach jeder
  Änderung zurückbringt, macht die Empfehlung, ihn zu löschen, wertlos.
  Solange eine `config.php` existiert, verweigert er zwar den Dienst –
  aber wenn die einmal fehlt, könnte jeder die Anlage neu einrichten.
* **`docs/`, `README.md`** – interne Unterlagen gehören nicht unter die
  eigene Domain.

Die Ausschlussliste steht im Workflow und ist dort Zeile für Zeile
kommentiert. Zwei Dinge, die beim Ändern leicht schiefgehen:

* Der Block unter `exclude:` ist ein **wörtlicher Textblock**. Eine Raute
  darin ist kein Kommentar, sondern ein Suchmuster – Erklärungen gehören
  über den Block.
* Ein Muster **ohne Schrägstrich trifft den Dateinamen überall im Baum**
  (die Action vergleicht mit `matchBase`). `config.php` sperrt also jede
  Datei dieses Namens, in welchem Ordner auch immer. Wer nur eine
  bestimmte Datei meint, schreibt den Pfad dazu.

Warum das trotzdem hält, auch wenn jemand die Liste falsch ändert: Die
Action löscht ausschließlich, was in ihrer eigenen Abgleichsdatei steht.
Was dort nie stand – Datenbank, Uploads, `config.php` –, kennt sie nicht
und kann sie folglich nicht löschen. Beim allerersten Lauf ist diese Datei
leer, es wird also nichts gelöscht, sondern nur hochgeladen.

> **`dangerous-clean-slate` niemals einschalten.** Diese Einstellung der
> Action löscht vor dem Upload den ganzen Zielordner, Ausschlussliste hin
> oder her. Auf diesem Server liegt die Datenbank einer Golfschule.

## Warum vorher geprüft wird

Vor dem Upload läuft `php -l` über jede PHP-Datei. Der Upload startet erst,
wenn das durchgeht.

Das ist kein Zierrat. Auf einem Webspace gibt es keine Kommandozeile und
keine Testumgebung: Ein Tippfehler wird zur weißen Seite, und man erfährt
davon, wenn ein Kunde anruft. Geprüft wird gegen **PHP 8.1**, die
niedrigste unterstützte Version – nicht gegen die neueste, sonst fällt
erst auf dem Server auf, dass eine Schreibweise dort noch nicht existiert.

Der zweite Schritt sieht nach, ob `config.php` oder eine Datenbankdatei
versehentlich eingecheckt wurde. Beides würde beim Upload die Daten des
laufenden Betriebs überschreiben, und das ist nicht rückgängig zu machen.

Zuletzt – wenn `SEITEN_URL` gesetzt ist – wird die Startseite aufgerufen.
Ein erfolgreicher Upload heißt noch nicht, dass die Seite läuft.

## Wie der Abgleich funktioniert

Die Action legt im Zielordner eine Datei `.ftp-deploy-sync-state.json` an
und merkt sich darin, welche Datei in welcher Fassung oben liegt. Beim
nächsten Lauf werden nur die Unterschiede übertragen – nach der ersten
vollständigen Übertragung dauert ein Upload meist wenige Sekunden.

Zwei Folgen davon:

* Die Datei darf auf dem Server nicht gelöscht werden. Ist sie weg, lädt
  der nächste Lauf wieder alles hoch. Schlimm ist das nicht, nur langsam.
* Sie zählt jede Datei der Anlage auf. Die `.htaccess` sperrt deshalb alle
  Dateien, deren Name mit einem Punkt beginnt. Wer nginx einsetzt,
  überträgt die Regel sinngemäß.

Dateien, die der Upload noch nie angefasst hat – die Datenbank, die
Uploads –, stehen nicht in dieser Liste und werden folglich auch nie
gelöscht.

Zwei Läufe gleichzeitig würden sich die Abgleichsdatei gegenseitig
verderben, deshalb wartet der zweite (`concurrency`). Abgebrochen wird
nicht: Ein abgebrochener Upload hinterlässt halbe Dateien auf dem Server.

## Wenn es klemmt

**`530 Login incorrect`** – Benutzername oder Passwort stimmen nicht.
Manche Hoster wollen den Benutzernamen in der Form `benutzer@domain.de`.

**Zeitüberschreitung beim Verbinden** – meist FTPS über Port 21, das der
Hoster nicht so anbietet. Der Reihe nach probieren: `FTP_PROTOKOLL` auf
`ftps-legacy` (impliziter FTPS, dann oft `FTP_PORT` auf `990`), danach
`FTP_SICHERHEIT` auf `loose`.

**`ECONNREFUSED` oder Zertifikatsfehler** – `FTP_SICHERHEIT` auf `loose`.
Die Verbindung bleibt verschlüsselt, das Zertifikat wird nur nicht mehr
geprüft. Viele Shared-Hoster verwenden ein Zertifikat, das nicht auf den
FTP-Hostnamen ausgestellt ist.

**Nur `ftp` funktioniert** – dann geht das Passwort im Klartext über das
Netz. Vertretbar, wenn es ein eigener, eingeschränkter Zugang ist; beim
Hoster nachfragen, ob FTPS nachgerüstet werden kann. `sftp` unterstützt
diese Action nicht.

**Gelbe Warnung „Node.js 20 is deprecated“** – kein Fehler und nichts, was
sich hier beheben lässt. Die FTP-Action gibt in ihrer Beschreibung noch
Node 20 an; GitHub führt sie trotzdem auf Node 24 aus. Verschwindet, sobald
der Autor der Action nachzieht.

**Die Dateien landen im falschen Ordner** – `FTP_VERZEICHNIS` prüfen.
Fehlt der Schrägstrich am Ende, ergänzt der Workflow ihn und sagt es als
Hinweis; die Action selbst würde daran abbrechen.

**`protocol: invalid parameter`** – in `FTP_PROTOKOLL` steht etwas, das
die Action nicht kennt. Erlaubt sind nur `ftp`, `ftps` und `ftps-legacy`;
zu `sftp` siehe oben.

**Alles grün, aber die Seite ändert sich nicht** – am Browser liegt es
meistens nicht: `App::asset()` hängt an jede CSS- und JS-Adresse eine
Prüfsumme aus dem Änderungsdatum der Datei. Wird die Datei neu
hochgeladen, ändert sich die Adresse, und der Browser holt sie neu – trotz
der sieben Tage, die `mod_expires` in der `.htaccess` erlaubt.

Bleibt eine Änderung trotzdem unsichtbar, sind zwei Ursachen
wahrscheinlich. Erstens: Die Datei wurde gar nicht übertragen. Im Protokoll
des Laufs steht Datei für Datei, was hochgeladen wurde; steht sie nicht
darin, greift vermutlich eine Zeile der Ausschlussliste. Zweitens: Der
Hoster hält den kompilierten PHP-Code kurz vor (OPcache). Das erledigt
sich meist innerhalb einer Minute von selbst.

**Der Upload läuft, aber die Seite ist weiß** – in der `config.php` auf
dem Server `'debug' => true` setzen, Seite aufrufen, Ursache lesen,
**danach wieder auf `false`**. Siehe [BETRIEB.md](BETRIEB.md).

## Wenn der Zweig anders heißt

Im Workflow steht unter `on: push: branches:`, welche Zweige live gehen.
Zurzeit sind das `main`, `master` und `claude/fervent-knuth-cr7shs`. Wer
sich auf einen festlegt, nimmt die anderen heraus – sonst lädt ein Push
auf einen Nebenzweig die Seite hoch.

Ein Lauf lässt sich außerdem jederzeit von Hand starten: auf GitHub unter
**Actions → Auf den Webspace laden → Run workflow**.

## Wenn zwischen Push und Live noch jemand schauen soll

Für den Fall, dass nicht jeder Push sofort auf die Kundenseite soll: Unter
**Settings → Environments** eine Umgebung anlegen, dort *Required
reviewers* setzen und im Workflow beim Job `hochladen` eine Zeile
ergänzen:

```yaml
  hochladen:
    name: Per FTPS übertragen
    needs: pruefen
    environment: produktion     # <- diese Zeile
    runs-on: ubuntu-latest
```

Dann wartet jeder Upload auf eine ausdrückliche Freigabe.
