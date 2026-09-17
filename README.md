# GolfPro CMS

Das digitale Betriebssystem für selbstständige Golf Professionals, Coaches
und Academies: Website, Buchung, Kundenakte, Training, Verkauf, Rechnungen,
Marketing und Auswertung – in einer Anwendung, auf einem gewöhnlichen
Webhosting-Paket.

## Was es besonders macht

**Läuft überall.** PHP 8.1 und PDO, sonst nichts. Kein Composer, kein
Node, keine Kommandozeile, kein Docker. Hochladen, `install.php` aufrufen,
drei Felder ausfüllen – fertig. Die Datenbank ist SQLite (eine Datei) oder
MySQL, das entscheidet ein Auswahlfeld beim Einrichten.

**Einfach im Standard, mächtig bei Bedarf.** Es gibt 21 Bereiche, aber ein
Einsteiger sieht neun. Was im Menü erscheint, legt jeder selbst fest unter
*Einstellungen → Tarif*. Ausgeschaltete Bereiche verlieren keine Daten, sie
treten nur zur Seite.

**Die Einrichtung endet mit einer Website, nicht mit einer Checkliste.**
Neun Schritte, danach steht eine veröffentlichte Seite mit Startseite,
Leistungen, Kontakt, Impressum, Datenschutz und AGB – und die Online-Buchung
nimmt Termine an.

## Installation

1. Alle Dateien per FTP hochladen.
2. `https://deine-domain.de/install.php` im Browser aufrufen.
3. Datenbank wählen, Namen, E-Mail und Passwort eintragen.
4. `install.php` vom Server löschen.
5. Anmelden – die geführte Einrichtung startet von selbst.

`systemcheck.php` sagt jederzeit, was der Server kann und was noch fehlt.
Die Seite ist nach der Installation nur für Inhaber sichtbar.

### Anforderungen

| | |
|---|---|
| PHP | 8.1 oder neuer |
| Erweiterungen | `pdo`, `pdo_sqlite` **oder** `pdo_mysql`, `mbstring` |
| Empfohlen | `gd` oder `imagick` (Bilder verkleinern), `curl` (Stripe, KI) |
| Schreibrechte | `data/`, `uploads/`, und einmalig der Hauptordner für die `config.php` |

Alles darüber hinaus ist optional. Ohne Stripe wird auf Rechnung verkauft,
ohne KI-Schlüssel schreiben die Textwerkzeuge mit einem eingebauten,
regelbasierten Generator weiter, ohne Cronjob läuft die Wartung beim
Öffnen des Dashboards mit.

## Aufbau

```
lib/          46 Klassen – je Datei eine, Autoloader ohne Composer
app/          Die Anwendung: 54 Seiten, eine je Bereich
portal/       Kundenportal und Registrierung, mobil zuerst gedacht
site.php      Die öffentliche Website aller Mandanten
buchen.php    Online-Buchung – angemeldet oder als Gast, nie mit Zwang
kaufen.php    Kauf mit Stripe oder auf Rechnung
anfrage.php   Formulareingang → Lead
datei.php     Videos und Unterlagen – erst prüfen, dann herausgeben
cron.php      Wartung für alle, die einen Cronjob haben
webhook.php   Stripe meldet Zahlungen hierher
install.php   Einrichtung, danach löschen
assets/       Stylesheets, Skripte und die Schriften auf eigenem Server
docs/         Architektur, Betrieb und Upload im Detail
.github/      Ein Workflow: Syntax prüfen, dann per FTPS hochladen
```

## Die tragenden Entscheidungen

**Mandantentrennung an einer Stelle.** Jeder Datenbankzugriff der Anwendung
läuft über `Tenant::all()`, `Tenant::find()`, `Tenant::insert()`. Diese
Klasse setzt `workspace_id` selbst in jede Bedingung – man kann den Filter
nicht vergessen, weil man ihn nie schreibt. `Tenant::find()` gibt für einen
fremden Datensatz `null` zurück, nicht den Datensatz; Kennungen lassen sich
damit nicht abtasten.

**Geld ist immer eine Ganzzahl in Cent.** 399,00 € ist `39900`. Gerundet
wird ausschließlich in `Util`, und Beträge werden nach `Util::verteilen()`
aufgeteilt, damit die Summe der Teile den Gesamtbetrag ergibt – auf den
Cent.

**Belege sind Dokumente, keine Ansichten.** Eine Rechnungsposition führt
Titel, Preis und Steuersatz als eigene Werte. Ändert sich später der Preis
der Leistung, bleibt die Rechnung, wie sie war. Rechnungsnummern haben
keine Lücken; gelöscht wird nie, korrigiert wird mit einer Gutschrift.

**Die KI entscheidet nichts.** Sie schlägt vor, fasst zusammen und
beantwortet Fragen zu den eigenen Zahlen. Preisänderungen, Kundendaten,
Rechnungen, Versand und Löschungen laufen immer über eine ausdrückliche
Bestätigung. In der Videoanalyse stehen „KI-Hinweise" und „Pro-Analyse" als
getrennte Blöcke nebeneinander – die fachliche Bewertung trifft der Trainer,
und das soll man sehen.

**Die Website sieht nicht nach Verwaltung aus.** Der Renderer setzt keine
Kachelraster, sondern eine redaktionelle Ordnung: eine Titelzeile über dem
Bild, Listen mit Haarlinien, versetzte Zitate, ein handschriftlicher
Einwurf dort, wo eine Person spricht. Kunden buchen Unterricht bei einem
Menschen; zwölf gleiche Kacheln erzählen das Gegenteil. Farben, Rundungen
und Abstände stehen als Variablen in `assets/css/site.css`, aus dem
Workspace kommt nur die Marke.

**Die Schriften liegen auf dem eigenen Server.** Archivo und Caveat als
variable Schnitte in `assets/fonts/`, zusammen 180 KB, kein Aufruf zu
Google. Das Landgericht München I hat am 20.01.2022 (Az. 3 O 17493/20)
entschieden, dass die Einbindung von Google Fonts die IP-Adresse des
Besuchers ohne Einwilligung überträgt. Wer hiermit seine Betriebswebsite
veröffentlicht, soll deswegen keine Post bekommen.

**Zählen ohne Cookies.** Die Websitestatistik bildet aus der IP-Adresse und
einem täglich wechselnden Zufallswert eine Prüfsumme. Wiederkehrende
Besuche eines Tages sind damit erkennbar, eine Person nicht. Die IP wird
nirgends gespeichert.

## Demo

Der Installer legt auf Wunsch einen zweiten, vollständig getrennten
Workspace an: 40 Kunden, über tausend Termine, Rechnungen, Trainingspläne,
Videoanalysen, Kurse, Kampagnen und eine fertige Website. Zahlen, die
zueinander passen – die Paketeinheiten sind von echten Terminen verbraucht,
der Umsatz entspricht den Preisen. Gut, um alles auszuprobieren, ohne die
eigenen Daten anzufassen.

## Weiterlesen

* [docs/ARCHITEKTUR.md](docs/ARCHITEKTUR.md) – wie die Teile zusammenhängen
  und warum sie so geschnitten sind
* [docs/BETRIEB.md](docs/BETRIEB.md) – Sicherung, Umzug, Cronjob, Stripe,
  Fehlersuche
* [docs/DEPLOY.md](docs/DEPLOY.md) – der automatische Upload auf den
  Webspace: was einzurichten ist und was er nicht anfasst
* [docs/API.md](docs/API.md) – die Schnittstelle, über die ein
  Newslettersystem die Empfänger abholt. Zum Weitergeben gedacht

## Lizenz

Noch nicht festgelegt.
