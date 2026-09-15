# Betrieb

Was nach der Installation zu tun ist – und was zu tun ist, wenn etwas
klemmt.

## Nach der Einrichtung

1. **`install.php` löschen.** Die Datei sperrt sich selbst, sobald eine
   `config.php` existiert, aber weg ist besser als gesperrt.
2. **`systemcheck.php` aufrufen.** Grüne Punkte sind in Ordnung, graue
   sind Hinweise, rote gehören behoben. Die Seite ist nach der
   Installation nur für Inhaber sichtbar.
3. **Rechte prüfen.** `config.php` sollte `640` haben, `data/` und
   `uploads/` müssen für PHP beschreibbar sein (meist `755`, bei manchen
   Hostern `775`).
4. **HTTPS erzwingen.** Fast jeder Hoster bietet ein kostenloses
   Zertifikat. Ohne HTTPS wandern Passwörter im Klartext durchs Netz.

## Sicherung

Zwei Dinge müssen gesichert werden:

* **Die Datenbank.** Bei SQLite ist das die Datei `data/golfpro.sqlite`.
  Sie sollte im Ruhezustand kopiert werden – am einfachsten nachts, wenn
  niemand arbeitet. Bei MySQL macht das der Hoster meist automatisch;
  nachsehen lohnt trotzdem.
* **Der Ordner `uploads/`.** Dort liegen Bilder, Videos und Dokumente.
  Diese Dateien stehen in keiner Datenbank und sind nach einem Verlust weg.

Die `config.php` gehört ebenfalls dazu – sie enthält das
Sitzungsgeheimnis. Ohne sie melden sich nach einer Wiederherstellung alle
Benutzer neu an, was verkraftbar, aber unnötig ist.

Eine Sicherung, die nie zurückgespielt wurde, ist eine Vermutung. Einmal
im Jahr auf einer Testadresse ausprobieren.

## Umzug auf einen anderen Server

1. Alle Dateien kopieren, `data/` und `uploads/` eingeschlossen.
2. Bei MySQL zusätzlich die Datenbank exportieren und einspielen.
3. In der `config.php` `base_url` anpassen.
4. `systemcheck.php` aufrufen.

Mehr ist nicht nötig. Es gibt keine absoluten Pfade in der Datenbank; alle
Verweise auf Dateien sind relativ zur Installation.

Wer die Dateien nicht von Hand hochlädt: Der automatische Upload aus
GitHub ist in [DEPLOY.md](DEPLOY.md) beschrieben. Er fasst Datenbank,
`config.php` und den `uploads`-Ordner nicht an – ein Umzug betrifft also
nur die Zugangsdaten im Repository.

## Schemaänderungen

Die Datenbank zieht sich selbst nach, aber nicht bei jedem Aufruf: In den
Einstellungen steht eine Versionsnummer, und `lib/bootstrap.php` lässt
`Schema::migrate()` nur laufen, wenn sie von `Schema::VERSION` abweicht.

**Wer eine Spalte ergänzt, muss diese Zahl erhöhen.** Sonst läuft der
Nachtrag auf bestehenden Anlagen nie – eine frische Installation
funktioniert, eine aktualisierte quittiert die erste Abfrage auf die neue
Spalte mit einem Serverfehler. Neue Spalten gehören außerdem in
`Schema::nachtragen()`, denn `CREATE TABLE IF NOT EXISTS` lässt eine
bestehende Tabelle in Ruhe.

Nach dem Hochladen genügt ein Seitenaufruf: Der erste Aufruf nach dem
Update trägt nach und merkt sich die neue Version.

## Cronjob

Ohne Cronjob läuft die Wartung beim Öffnen des Dashboards mit. Das genügt
für den Anfang, hat aber zwei Nachteile: Erinnerungen gehen erst raus,
wenn jemand ins Backend schaut, und geplante Newsletter warten.

Mit Cronjob – alle 15 Minuten:

```
*/15 * * * * /usr/bin/php /pfad/zur/installation/cron.php >/dev/null 2>&1
```

Wenn der Hoster nur URLs erlaubt:

```
*/15 * * * * curl -s "https://deine-domain.de/cron.php?schluessel=DEIN_SCHLUESSEL"
```

Den Schlüssel erzeugt `cron.php` beim ersten Aufruf selbst; er steht in der
Tabelle `settings` unter `cron_schluessel` (Zeile mit `workspace_id = 0`).
Ohne Schlüssel antwortet die Datei im Browser mit 403 – über die
Kommandozeile wird keiner verlangt, dort kommt niemand von außen hin.

## Stripe

1. Im Stripe-Dashboard die Schlüssel holen (`pk_live_…`, `sk_live_…`).
2. In `config.php` unter `stripe` eintragen.
3. Einen Webhook anlegen, der auf `https://deine-domain.de/webhook.php`
   zeigt, mit den Ereignissen `checkout.session.completed`,
   `payment_intent.succeeded`, `payment_intent.payment_failed` und
   `charge.refunded`.
4. Das Webhook-Geheimnis (`whsec_…`) ebenfalls in die `config.php`.

**Der Webhook ist nicht optional, wenn Stripe benutzt wird.** Eine Zahlung
gilt erst als eingegangen, wenn Stripe sie meldet. Die Rückkehrseite im
Browser kann ausbleiben – der Kunde schließt den Tab, das Netz bricht ab –,
der Webhook kommt trotzdem. Ohne Webhook-Geheimnis wird jede Meldung
abgewiesen, denn sonst könnte jeder, der die Adresse kennt, Bestellungen
als bezahlt melden.

Ohne Stripe-Schlüssel bleibt alles benutzbar: Käufe werden als offene
Bestellung angelegt und auf Rechnung abgewickelt.

## E-Mail

Standardmäßig wird PHPs `mail()` benutzt. Das reicht oft, landet aber
häufiger im Spam-Ordner, weil dem Hoster die Absenderberechtigung fehlt.

Besser: In der `config.php` unter `mail` den Transport auf `smtp` stellen
und die Zugangsdaten des Postfachs eintragen, das auch als Absender
auftritt. Dazu im DNS der Domain einen SPF-Eintrag setzen, der den Hoster
erlaubt.

Jede verschickte Mail steht in der Kundenakte unter *Kommunikation* – auch
dann, wenn der Versand fehlgeschlagen ist. Das ist die erste Stelle zum
Nachsehen, wenn jemand sagt, er habe nichts bekommen.

## KI-Funktionen

In `config.php` unter `ai` den Schlüssel eintragen. Ohne Schlüssel bleiben
alle KI-Bereiche bedienbar: Fragen zu den eigenen Zahlen werden ohnehin per
SQL beantwortet, Textentwürfe kommen aus dem eingebauten Generator.

Fragen zu Zahlen verlassen den Server nie. Bei Textentwürfen wird der
Entwurfsauftrag übertragen – wer das nicht möchte, lässt das Feld leer.

## Datenschutz im Betrieb

* Unter *Einstellungen → Datenschutz* stehen die Aufbewahrungsfristen für
  Protokoll, Websitezahlen und verlorene Leads. Die Wartung räumt danach
  von selbst auf.
* Auskunfts- und Löschanfragen aus dem Kundenportal landen dort als Liste
  mit Frist. Die Frist beträgt einen Monat.
* Der Datenexport eines Kunden ist eine JSON-Datei mit allem, was zu ihm
  gespeichert ist – ohne Passwörter und ohne interne Zugangstoken.
* Die Schriften der öffentlichen Website liegen in `assets/fonts/` auf dem
  eigenen Server; es geht kein Aufruf an Google. Wer unter *Website →
  Design* eine andere Schrift wählt, holt sie wieder von dort – dann
  gehört ein Hinweis in die Datenschutzerklärung und, je nach Auslegung,
  eine Einwilligung davor.
* Die erzeugten Texte für Impressum, Datenschutzerklärung und AGB sind
  Gerüste mit Platzhaltern. Sie sind keine Rechtsberatung und sollten vor
  dem Online-Gehen von jemandem mit Fachkenntnis angesehen werden.

## Fehlersuche

**Weiße Seite.** In der `config.php` `'debug' => true` setzen und die Seite
erneut aufrufen – dann steht die Ursache im Klartext da. **Danach wieder
auf `false` stellen**, sonst sehen Besucher Dateipfade.

**„Diese Adresse gehört zu keiner Website."** Die Domain ist keinem
Workspace zugeordnet. Unter *Einstellungen → Allgemein* die Domain
eintragen, ohne `https://` und ohne `www.`.

**Buchung zeigt keine freien Zeiten.** In dieser Reihenfolge prüfen:
Ist die Leistung aktiv und *online buchbar*? Sind unter *Buchungen →
Verfügbarkeit* Zeiten für den Trainer hinterlegt? Liegt der gewünschte
Termin weiter in der Zukunft als der Vorlauf der Leistung?

**Bestätigungsmails kommen nicht an.** Erst in der Kundenakte unter
*Kommunikation* nachsehen, ob der Versand überhaupt stattgefunden hat.
Steht die Mail dort als gesendet, liegt es am Empfang – siehe Abschnitt
E-Mail. Steht sie gar nicht dort, ist `mail()` gesperrt; `systemcheck.php`
zeigt das an.

**Zahlung wird nicht verbucht.** Im Stripe-Dashboard unter *Webhooks* die
Zustellversuche ansehen. 400 heißt: Signatur abgelehnt – dann stimmt das
Webhook-Geheimnis in der `config.php` nicht.

**„Kein Speicherplatz" beim Hochladen.** `upload_max_filesize` und
`post_max_size` in der PHP-Konfiguration. Der kleinere der beiden Werte
gilt. Schwungvideos sind schnell 30 MB groß.

**Datenbank gesperrt (SQLite).** Tritt auf, wenn zwei Schreibvorgänge
zusammenfallen. Das System wartet bis zu zehn Sekunden. Häuft sich die
Meldung, ist der Betrieb aus SQLite herausgewachsen – dann auf MySQL
wechseln.

## Auf MySQL wechseln

Es gibt kein Werkzeug dafür, aber der Weg ist kurz: Eine leere
MySQL-Datenbank anlegen, in der `config.php` den Treiber umstellen,
irgendeine Seite aufrufen – das Schema zieht sich selbst nach. Danach die
Daten mit einem Werkzeug wie `sqlite3` und `mysqlimport` tabellenweise
übertragen. Die Spalten sind in beiden Treibern identisch benannt.

Bei nennenswertem Datenbestand lohnt es sich, das vorher auf einer
Testadresse zu üben.

## Was im Protokoll steht

*Einstellungen → Protokoll* zeigt, wer wann was geändert hat: angelegt,
geändert, gelöscht, versendet, storniert, als bezahlt verbucht,
exportiert, KI-Vorschlag angenommen oder verworfen.

Das Protokoll lässt sich nicht bearbeiten – nur so ist es als Nachweis
brauchbar. Einträge werden nach der eingestellten Frist automatisch
gelöscht.
