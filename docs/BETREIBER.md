# Betreiberzentrale

Die Betreiberzentrale (`/master/`) ist die Ebene über allen Instanzen.
Hier sieht das TeePilot-Team jede Instanz, legt neue an, vergibt Pakete,
pausiert, sperrt, archiviert – und öffnet eine Instanz im Support Mode,
ohne das Passwort des Kunden zu kennen.

In der Oberfläche heißt ein Mandant „Instanz", im Code und in der
Datenbank weiter „Workspace" (`workspaces`, `workspace_id`). Es gibt keine
zweite Anwendung und keine zweite Datenbank: Die Zentrale liest und
schreibt dieselben Tabellen, über dieselbe Anmeldung, mit denselben
Bausteinen der Oberfläche.

## Den ersten Master Admin anlegen

Es gibt keine Seite, auf der sich jemand selbst zum Master Admin machen
kann. Der erste Betreiber entsteht auf einem von zwei Wegen:

**Mit Kommandozeile (SSH):**

```
php bin/betreiber.php anlegen chef@deine-firma.de "Vorname Nachname"
```

Ausgegeben wird ein Link, über den die Person ihr Passwort selbst vergibt
(sieben Tage gültig, einmal verwendbar). Weitere Befehle:

```
php bin/betreiber.php liste
php bin/betreiber.php link <email>          neuer Link zum Passwortsetzen
php bin/betreiber.php sperren <email>
php bin/betreiber.php entsperren <email>
```

**Ohne Kommandozeile (reines FTP-Hosting):**

1. In der `config.php` einen langen Zufallswert eintragen:
   `'betreiber_einrichtung' => '…mindestens 24 Zeichen…',`
2. `https://deine-adresse/master/einrichten.php?k=<derselbe Wert>` öffnen,
   Name und E-Mail eintragen, dem Link zum Passwortsetzen folgen.
3. Den Eintrag wieder aus der `config.php` entfernen.

Die Seite antwortet mit 404, sobald es einen Betreiber gibt – auch mit
richtigem Schlüssel. Fehlversuche laufen über dieselbe Bremse wie die
Anmeldung. Die Systemseite der Zentrale erinnert daran, den Schlüssel zu
entfernen.

Weitere Master Admins lädt ein Betreiber unter **Einstellungen** ein.

**Die Adresse eines Betreibers muss eine eigene sein** – nicht dieselbe
wie ein Zugang in einer Instanz. Die Anmeldemaske ist für beide dieselbe;
steht eine Adresse in der Betreibertabelle, gilt sie als Betreiber.

## Anmelden

Über `/login.php`, wie jeder Golfpro. Betreiber landen in der
Betreiberzentrale, Golfpros in ihrer Instanz. Eine Sitzung ist entweder
das eine oder das andere; der Support Mode ist die einzige, ausdrücklich
begonnene Ausnahme. Die Sitzung eines Betreibers endet nach zwei Stunden
ohne Klick.

Ruft ein Golfpro `/master/…` auf, bekommt er **403 „Keine Berechtigung"**
– keine Weiterleitung – und der Versuch steht im Audit-Log.

## Adressen

Die Anwendung verlinkt selbst immer die Dateinamen; mit `mod_rewrite`
(Apache, `.htaccess`) gelten zusätzlich die sprechenden Adressen:

| Sprechend | Datei |
|---|---|
| `/master/dashboard` | `master/index.php` |
| `/master/instances` | `master/instanzen.php` |
| `/master/instances/new` | `master/instanz-neu.php` |
| `/master/instances/12` | `master/instanz.php?id=12` |
| `/master/instances/12/users` · `/activity` · `/subscription` · `/usage` · `/settings` | Reiter der Instanz |
| `/master/packages` · `/invoices` · `/users` · `/analytics` · `/activity` · `/audit-log` · `/settings` · `/system` | die übrigen Seiten |

## Was die Zentrale kann

**Übersicht.** Instanzen gesamt und nach Status, aktive Benutzer, Termine
im Monat, Pakete, Kündigungen, Wachstum über zwölf Monate,
Paketverteilung (Klick filtert die Liste), „Aufmerksamkeit erforderlich"
(Testphase endet in 7 Tagen, inaktiv, neu, Kündigung wird wirksam) und
die letzten Einträge im Audit-Log.

**Subscription-Wert.** Summe der Listenpreise aus erfassten Verträgen, je
Monat (Jahresverträge mit einem Zwölftel). Das ist ausdrücklich **kein
Umsatz** und kein Zahlungseingang, und die Kachel sagt das. Instanzen
ohne Vertragsdaten zählen nicht mit und werden genannt. Umsatz, offene
und überfällige Rechnungen zeigen die Kacheln aus den ausgestellten
Rechnungen (siehe unten).

**Instanzen.** Suche über Name, Golfpro, E-Mail, Club, Domain,
Kurzadresse und ID; Filter nach Status, Paket, Nutzung, Anlagedatum und
Demo; Sortierung per Klick auf den Spaltenkopf; 25/50/100 je Seite. Die
Filter merkt sich die Sitzung. Export als CSV nur nach ausdrücklicher
Bestätigung, im Audit-Log vermerkt, ohne Datei auf dem Server.

**Neue Instanz.** Sechs Schritte: Grunddaten, Account des Golfpros,
Paket, Laufzeit, Technik, Bestätigung. Angelegt wird alles in einer
Transaktion – Workspace, Inhaber, Grundeinstellungen, Standort, Vertrag.
Der Inhaber bekommt kein Passwort, sondern eine Einladung mit Link zum
Passwortsetzen.

**Instanz im Detail.** Reiter Übersicht (mit Verlauf und internen
Notizen, die nie in der Instanz erscheinen), Benutzer (ohne Passwörter;
Link erneut senden, deaktivieren), Paket (Wechsel, Vertragsdaten,
Kündigung vormerken, Verlauf), Nutzung (nur Anzahlen, keine Inhalte),
Aktivität (Art und Zeitpunkt, keine Beschreibungstexte), Einstellungen,
Audit. Aktionen: Als Support öffnen, Bearbeiten, Paket ändern,
Aktivieren, Pausieren, Sperren, Archivieren – und Löschen, nur aus dem
Archiv und nur mit abgetipptem Namen.

**Pakete.** Stehen in der Datenbank (`pakete`), nicht im Code. Name,
Beschreibung, Monats- und Jahrespreis, Module, Team-Grenze, angeboten
ja/nein, Reihenfolge. Die vier bisherigen Tarife wurden beim Update
unverändert übernommen. Ein Paket mit Instanzen wird nicht gelöscht,
sondern nicht mehr angeboten.

**Benutzer, Auswertung, Aktivität, Audit-Log, Einstellungen, System** –
siehe die jeweilige Seite; jede sagt selbst, woher ihre Zahlen kommen.

## Status einer Instanz

| Status | Anmeldung Team | Schnittstelle | Website, Buchung, Portal |
|---|---|---|---|
| Testphase | ja | ja | ja |
| Aktiv | ja | ja | ja |
| Pausiert | nein | nein | ja |
| Gesperrt | nein | nein | nein |
| Archiviert | nein | nein | nein (zusätzlich aus Standardlisten ausgeblendet) |

Wird eine Instanz pausiert, während jemand angemeldet ist, endet dessen
Sitzung mit dem nächsten Klick. Die Anmeldung erklärt, woran es liegt.
Statuswechsel prüfen, ob der Status noch der ist, den der Betreiber auf
dem Bildschirm hatte – zwei Betreiber überschreiben sich nicht still.

## Nutzung einer Instanz

`workspaces.letzte_aktivitaet` wird bei jeder Anfrage eines angemeldeten
Teammitglieds gesetzt (höchstens alle zehn Minuten je Sitzung). Zugriffe
im Support Mode zählen nicht. Bei der Migration wurde der Wert aus der
letzten Anmeldung und dem letzten Protokolleintrag einer Person
nachgetragen.

Einordnung nach den Schwellen unter **Einstellungen** (Vorgabe):
aktiv genutzt bis 7 Tage, wenig genutzt bis 30, inaktiv darüber,
„noch nie genutzt" ohne jede Spur.

## Pakete und Freischaltung

Die eine Stelle, die entscheidet, ob eine Instanz ein Modul benutzen
darf, ist `Pakete::erlaubt($modul, $paket)`. Darauf bauen auf:

* `Tenant::modul($key)` – im Paket **und** vom Pro eingeschaltet
* `Auth::darf('modul.x')` – Rolle erlaubt es **und** das Modul ist
  verfügbar. Damit ist ein Modul außerhalb des Pakets nicht nur aus dem
  Menü verschwunden, sondern auch über die Adresszeile gesperrt (403 mit
  „Modul nicht aktiv").

`Pakete::zuweisen()` ist die einzige Stelle, die `workspaces.plan`
ändert – aus der Zentrale und von der Tarifseite des Inhabers. Beim
Wechsel nach unten werden Module abgeschaltet, die das neue Paket nicht
enthält; Daten bleiben erhalten.

Auf der Tarifseite kann ein Inhaber sein Paket nur noch selbst wechseln,
solange TeePilot für seine Instanz **keine Vertragsdaten** führt – also
wie bisher in Anlagen ohne Betreiberzentrale. Sonst liefen Paket und
Vertrag auseinander. Jeder Selbstwechsel steht im Audit-Log.

Kommt ein neues Modul hinzu, steht es in keinem gespeicherten Paket. Ob
es dazugehört, entscheidet ein Betreiber unter **Pakete**.

Die Team-Grenze eines Pakets setzt `app/team.php` durch – sie stand
vorher fest im Code und hat dieselben Werte (1, 3, 8, unbegrenzt).

## Verträge

`abos` hält Paket, Laufzeit (Testphase, monatlich, jährlich,
individuell), Listenpreis, Beginn, Ende der Testphase und Kündigung. Ein
Wechsel schließt die laufende Zeile und beginnt eine neue; die
Geschichte entsteht so von selbst. Zahlungen stehen hier nicht.

**Instanzen aus der Zeit vor der Betreiberzentrale haben keinen
Vertrag** und zeigen „Keine Vertragsdaten erfasst". Beim Update wurde
bewusst keiner erfunden – er stünde sonst als Umsatz im Dashboard.

Eine Kündigung wird vorgemerkt; es gibt keine Abrechnung, die das Ende
durchsetzt. Die Übersicht zeigt Kündigungen, die in 30 Tagen wirksam
werden, und Testphasen, die in 7 Tagen enden. Was dann passiert,
entscheidet ein Betreiber.

## Rechnungen an die Instanzen

Unter **Rechnungen** stellt die Zentrale Rechnungen an die Instanzen –
für Pakete, Einrichtung, Sonderleistungen, was immer anfällt.

**Einmal einrichten:** Unter Einstellungen → **Rechnungsabsender** Firma,
Anschrift, Steuernummer oder USt-IdNr., Bankverbindung, Nummernkreis
(Vorgabe `TP` → `TP-2026-0001`), Zahlungsziel und Steuersatz eintragen.
Ohne Firma, Anschrift und Steuernummer/USt-IdNr. lässt sich keine
Rechnung ausstellen (Pflichtangaben nach § 14 UStG); Entwürfe gehen schon.
Kleinunternehmer nach § 19 UStG ist ein Haken.

**Ablauf:**

1. **Entwurf** – leer oder „aus Vertrag" (Paket, Betrag und
   Leistungszeitraum des Monats aus den Vertragsdaten). Positionen mit
   Menge, Einheit, Einzelpreis netto und Steuersatz; die Summen rechnet
   die Seite mit, maßgeblich ist die Rechnung des Servers. Entwürfe sind
   frei änderbar und löschbar. Unter **Monatsentwürfe** entstehen für
   einen Monat Entwürfe aus allen laufenden Verträgen – nichts doppelt,
   Demo-Instanzen nie.
2. **Ausstellen** – vergibt die nächste fortlaufende Nummer, friert
   Absender und Empfänger als Kopie ein, setzt Rechnungsdatum und
   Fälligkeit und legt das PDF einmal ab (`data/privat/betreiber/
   rechnungen/<Jahr>/<Nummer>.pdf`, mit SHA-256-Abdruck in der
   Datenbank). Scheitert das Ablegen, bleibt es ein Entwurf und die
   Nummer ist nicht verbraucht.
3. **Danach unveränderlich** (GoBD). Möglich sind nur noch: per E-Mail
   senden (PDF im Anhang, Absendername = Firma, Antwort an die
   Rechnungs-E-Mail), Zahlungseingang vermerken (und zurücknehmen),
   **stornieren**. Eine Stornorechnung ist eine eigene Rechnung mit
   eigener Nummer, negativen Beträgen und dem Grund; die Ursprungsrechnung
   wird als storniert markiert. Beide bleiben erhalten.

Die Unveränderlichkeit steht zweimal: Die Anwendung hat keine Funktion
dafür, und Datenbank-Auslöser weisen Änderungen an Nummer, Beträgen,
Empfänger, Absender, Positionen und PDF-Verweis ebenso ab wie das Löschen.
Ausgeliefert wird immer die abgelegte Datei – stimmt ihr Abdruck nicht
mehr, verweigert die Zentrale die Auslieferung und zeigt das an.

**Steuer:** je Rechnung ein Steuerfall, vorgeschlagen aus Land und
USt-IdNr. des Empfängers – Regelbesteuerung (Sätze je Position,
Steuer je Satz auf die Nettosumme gerundet), Kleinunternehmer (§ 19),
Reverse Charge für Unternehmen im EU-Ausland mit USt-IdNr., nicht
steuerbar für das Drittland (etwa die Schweiz). Der passende Hinweis
steht automatisch auf der Rechnung. Welcher Fall zutrifft, entscheidet
im Zweifel die Steuerberatung. Paketpreise gelten auf Rechnungen als
Nettopreise.

**Rechnungsanschrift** je Instanz im Reiter **Rechnungen** der Instanz
(vorbelegt aus Instanz, Inhaber und Standort). Jede Rechnung kopiert sie
beim Speichern und kann für sich abweichen.

**Die Instanz sieht ihre Rechnungen** unter **Konto & Abrechnung**
(`app/konto.php`) – im Menü direkt unter „Einstellungen", mit der Zahl
offener Rechnungen daneben, außerdem im Profilmenü, als Kachel in den
Einstellungen und in der Befehlssuche. Dort stehen Paket und Vertrag,
offener Betrag und nächste Fälligkeit, alle ausgestellten Rechnungen mit
PDF und die Rechnungsanschrift (nur zum Lesen – sie pflegt der Betreiber).
Nur ausgestellte, nur die eigenen, nie Entwürfe oder interne Notizen.

**Benachrichtigung der Instanz.** Beim Ausstellen – nicht erst beim
Versand – bekommt die Instanz eine Meldung an der Glocke: „Neue Rechnung
von TeePilot" mit Nummer, Betrag und Fälligkeit, bei einem Storno
„Rechnung storniert" mit beiden Nummern. Der Klick führt zur Rechnung und
hakt die Meldung ab; das Öffnen von Konto & Abrechnung hakt alle dazu ab.

Solange eine Rechnung offen ist, steht oben auf dem **Dashboard** ein
Hinweis mit PDF und Weg zu Konto & Abrechnung, rot, sobald sie überfällig
ist. Er verschwindet, wenn der Zahlungseingang vermerkt ist, und lässt
sich nicht wegklicken.

Wer das sieht: wer in der Instanz die allgemeinen Einstellungen sehen darf
(Inhaber, Administration, Head Pro). Trainer, Assistenz, Buchhaltung und
Marketing sehen weder Meldung noch Hinweis noch Menüpunkt. Die Meldung wird
dafür nicht an einzelne Personen adressiert, sondern beim Lesen nach Recht
gefiltert (`Notify::NUR_MIT_RECHT`) – wer das Recht später bekommt, sieht
sie. Im Support Mode bleiben Meldungen ungelesen; sie gehören dem Inhaber.

Geht das Schreiben der Meldung schief, bleibt die Rechnung trotzdem
ausgestellt – der Fehler steht im Fehlerprotokoll des Servers.

**Löschen einer Instanz** lässt ihre Rechnungen samt PDF stehen: Sie
tragen `instanz_id`, nicht `workspace_id`, und haben Empfänger und
Absender als Kopie. Aufbewahrungspflicht zehn Jahre.

**Kennzahlen:** Die Übersicht zeigt Umsatz laut Rechnungen (netto, Stornos
abgezogen), offene und überfällige Rechnungen und die vermerkten
Zahlungseingänge; überfällige Rechnungen stehen unter „Aufmerksamkeit
erforderlich". Ein Zahlungsanbieter ist nicht angeschlossen – Eingänge
werden von Hand vermerkt.

**E-Rechnung:** Die Rechnungen sind PDF. Für Rechnungen an Unternehmen im
Inland verlangt das Gesetz ab 2027 (Vorjahresumsatz über 800.000 €) bzw.
ab 2028 (alle) eine E-Rechnung im strukturierten Format (XRechnung oder
ZUGFeRD). Bis dahin ist PDF zulässig. ZUGFeRD ist noch nicht eingebaut.

**Sicherung:** Der Ordner `data/privat/betreiber/rechnungen/` gehört in
jede Sicherung, zusammen mit der Datenbank – zehn Jahre lang.

## Support Mode

* Start aus der Instanz („Als Support öffnen"), mit optionalem Grund.
* Die Sitzung wird auf dem Server umgestellt: Der Betreiber sieht die
  Instanz mit den Rechten des Inhabers (sonst eines Administrators).
  Kein Passwort, kein Token des Kunden wird gebraucht oder angezeigt.
* Auf jeder Seite steht oben: **„Support Mode – Du greifst gerade als
  TeePilot Master Admin auf diese Instanz zu."** mit „Support Mode
  beenden". Beenden (oder Abmelden) führt zurück zur Instanz in der
  Zentrale; die Betreibersitzung bleibt.
* Die Sitzung endet nach der eingestellten Zeit von selbst (Vorgabe 60
  Minuten) – und sofort, wenn der Betreiber gesperrt wird.
* Gesperrt im Support Mode: Passwort und Profil des Inhabers, das Team
  (keine neuen Zugänge, keine Links zum Passwortsetzen), Erzeugen und
  Widerrufen des Schnittstellenschlüssels.
* Protokoll: Beginn und Ende mit Grund und Dauer im Audit-Log der
  Zentrale. Im Änderungsprotokoll der Instanz steht „TeePilot Support hat
  die Instanz geöffnet/verlassen", und jede Änderung in dieser Zeit ist
  dort als „TeePilot Support" gekennzeichnet (`audit_log.betreiber_id`).
* „Letzte Anmeldung" des Inhabers bleibt unberührt.
* Höchstens 20 Support-Sitzungen je Betreiber in 15 Minuten.

## Audit-Log

`betreiber_log` ist nur zum Anhängen: Es gibt in der Anwendung keine
Funktion zum Ändern oder Löschen, und zwei Datenbank-Auslöser weisen
`UPDATE` und `DELETE` ab (unter MySQL nur, wenn der Hoster Auslöser
erlaubt – die Systemseite zeigt, ob sie aktiv sind).

Jeder Eintrag: Zeit, Akteur, Aktion, Schwere (Info, Warnung, Kritisch),
Objekt, Instanz, Ergebnis, vorher/nachher, Grund, Anfrage-ID, IP.
Schlüssel, die nach Passwort, Token, Hash oder Schlüssel aussehen,
werden aus vorher/nachher entfernt, bevor sie gespeichert werden.

Das Log hat keine `workspace_id`, sondern `instanz_id`: Beim Löschen
einer Instanz verschwindet jede Zeile mit ihrer `workspace_id` – der
Eintrag „Instanz gelöscht" soll bleiben.

Aktionen: `TENANT_CREATED`, `TENANT_UPDATED`, `TENANT_ACTIVATED`,
`TENANT_PAUSED`, `TENANT_LOCKED`, `TENANT_ARCHIVED`, `TENANT_DELETED`,
`PLAN_CHANGED`, `SUBSCRIPTION_CHANGED`, `PACKAGE_CREATED`,
`PACKAGE_UPDATED`, `USER_CREATED`, `USER_UPDATED`, `USER_DISABLED`,
`USER_INVITED`, `SUPPORT_SESSION_STARTED`, `SUPPORT_SESSION_ENDED`,
`NOTE_ADDED`, `EXPORT_CREATED`, `SETTINGS_CHANGED`, `INVOICE_CREATED`,
`INVOICE_UPDATED`, `INVOICE_DELETED`, `INVOICE_ISSUED`, `INVOICE_SENT`,
`INVOICE_PAID`, `INVOICE_CANCELLED`, `BILLING_DATA_CHANGED`, `OPERATOR_CREATED`,
`OPERATOR_UPDATED`, `MASTER_LOGIN`, `MASTER_LOGIN_FAILED`,
`MASTER_LOGOUT`, `ACCESS_DENIED`.

## Sicherheit im Überblick

* Jede Seite unter `/master/` bindet `master/partials/start.php` ein, das
  `Betreiber::fordern()` aufruft – vergessen lässt sich die Prüfung
  nicht. Handlungen verlangen zusätzlich ihr eigenes Recht.
* Betreiber stehen in einer eigenen Tabelle, nicht als Rolle in `users`.
  Keine Seite einer Instanz schreibt dorthin; eine Rolle „master_admin"
  in einer Instanz wird zu „trainer".
* Rechte laufen über Rollen und Rechtenamen (`Betreiber::RECHTE`), nie
  über E-Mail-Adressen. Weitere Rollen (Support, Auswertung) brauchen dort
  eine Zeile; die Rechtenamen sind in den Seiten schon gesetzt.
* Alle Handlungen: POST mit CSRF-Merkmal. Die ID aus der Adresse sagt nur,
  welche Instanz geladen wird; geladen wird sie immer aus der Datenbank.
* Bremsen je Betreiber in 15 Minuten: 20 neue Instanzen, 3 Löschungen,
  20 Support-Sitzungen, 30 Paketwechsel, 10 Exporte; Anmeldung wie bisher.
* Keine Passwörter, Hashes, Tokens oder Schlüssel in der Oberfläche: Die
  Abfragen nennen ihre Spalten einzeln. Einladungs- und Reset-Tokens
  stehen nur als SHA-256-Abdruck in der Datenbank, und Mails mit einem
  solchen Link landen nicht im Kommunikationsverlauf der Instanz.
* Demo-Instanzen zählen in keiner Plattformzahl mit.

## Löschen

Nur eine archivierte Instanz, nur mit genau abgetipptem Namen und einem
Grund. Gelöscht wird jede Zeile jeder Tabelle mit dieser `workspace_id`
(die Tabellen werden gesucht, nicht aufgezählt) und die Ordner
`uploads/w<ID>` und `data/privat/w<ID>`. Unwiderruflich – darum ist
Archivieren der Normalfall.

## Datenmodell (Schema 8 und 9)

| Tabelle / Spalte | Zweck |
|---|---|
| `betreiber` | Master Admins: E-Mail, Passwort-Hash, Name, Rolle, aktiv |
| `betreiber_log` | Audit-Log der Zentrale, nur zum Anhängen |
| `pakete` | Pakete mit Preisen, Modulen, Team-Grenze |
| `abos` | Vertragsdaten je Instanz, die offene Zeile ist die laufende |
| `betreiber_notizen` | Interne Notizen je Instanz |
| `workspaces.status` | test, aktiv, pausiert, gesperrt, archiviert |
| `workspaces.letzte_aktivitaet` | Letzte Nutzung durch das eigene Team |
| `audit_log.betreiber_id` | > 0: im Support Mode entstanden |
| `settings` (workspace_id 0, `master_*`) | Schwellen, Testphase, Standardpaket, Support-Dauer, Rechnungsabsender (`master_rg_*`) |
| `betreiber_rechnungen` | Rechnungen an Instanzen (Schema 9); `instanz_id`, Empfänger/Absender als Kopie, PDF-Verweis mit Abdruck |
| `betreiber_rechnungspositionen` | Positionen; nach dem Ausstellen per Auslöser gesperrt |
| `rechnungsdaten` | Rechnungsanschrift je Instanz (geht mit der Instanz) |

Die Migration läuft wie jede andere beim ersten Aufruf nach dem Upload.
Bestehende Instanzen erscheinen sofort in der Zentrale; ein Workspace mit
`aktiv = 0` wird „gesperrt", keiner wird gelöscht.

## Prüfungen

```
php tests/betreiber.php
```

Legt eine Wegwerf-Datenbank und einen eigenen Entwicklungsserver an und
prüft über HTTP: Migration ohne Datenverlust, unveränderliches Log,
403 für Golfpros auf jeder Seite der Zentrale, manipulierte IDs, keine
Rechteausweitung, Mandantengrenze, Anlegen, Bearbeiten, Paketwechsel mit
sofortiger Wirkung auf die Freischaltung, Pausieren/Sperren/Aktivieren,
Support Mode samt Kennzeichnung und Sperren, Archivieren und Löschen,
Export – und Rechnungen: Rundung, Steuerfälle, fortlaufende Nummern,
Unveränderlichkeit per Datenbank, Storno, Sichtbarkeit nur in der eigenen
Instanz, Erhalt nach dem Löschen, Erkennen einer veränderten Ablagedatei.
Die echte Datenbank und die echte Rechnungsablage der Anlage werden nicht
angefasst.

## Bewusst noch nicht dabei

* Zahlungsanbieter (Lastschrift, Karte, automatischer Abgleich),
  Mahnwesen und E-Rechnung (ZUGFeRD/XRechnung) – Rechnungen gibt es,
  Zahlungseingänge werden von Hand vermerkt.
* Zwei-Faktor-Anmeldung: Die Spalte `users.zwei_faktor` existiert, eine
  Anmeldung damit noch nicht – weder für Golfpros noch für Betreiber.
* Weitere Betreiberrollen (Support, Auswertung), Schlagworte an
  Instanzen, Massenaktionen, Benachrichtigungen per Mail.
* Subdomains je Instanz – TeePilot unterstützt eigene Domains und die
  Kurzadresse `?w=…`, keine Subdomains.
