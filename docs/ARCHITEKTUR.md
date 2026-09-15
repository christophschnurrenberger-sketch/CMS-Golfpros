# Architektur

Dieses Dokument erklärt, wie GolfPro CMS aufgebaut ist – und vor allem,
warum. Wer nur installieren will, ist mit der README schneller fertig.

## Die Randbedingung, aus der alles folgt

Die Zielgruppe sind selbstständige Golf Professionals. Sie haben ein
Webhosting-Paket für ein paar Euro im Monat, FTP-Zugang und eine
Verwaltungsoberfläche im Browser. Sie haben keine Kommandozeile, keinen
Docker-Host und niemanden, der `npm run build` ausführt.

Das ist keine Einschränkung, die man wegdiskutiert, sondern die Grundlage
des Entwurfs:

* **Kein Composer.** Ein Autoloader über `lib/` reicht bei einer Klasse je
  Datei völlig und erspart eine Abhängigkeit, die auf günstigem Hosting
  gern Ärger macht.
* **Kein Node, kein Bundler.** Das CSS ist handgeschrieben und nutzt
  Custom Properties statt eines Präprozessors. Das JavaScript ist eine
  Datei ohne Framework.
* **Keine externen Dienste als Pflicht.** Stripe, KI und SMTP sind
  Verbesserungen, keine Voraussetzungen. Ohne sie fehlt eine Funktion,
  nichts bricht ab.
* **Kein Cronjob als Pflicht.** Wer einen hat, ruft `cron.php` auf. Wer
  keinen hat, dem läuft die Wartung beim Öffnen des Dashboards mit –
  gedrosselt auf einmal je Viertelstunde und mit begrenzter Stückzahl,
  damit keine Seite darauf wartet.

## Schichten

```
  Browser
     │
     ├── site.php, buchen.php, kaufen.php, anfrage.php   öffentlich
     ├── portal/                                          Kunde
     └── app/                                             Anwendung
              │
        lib/Kundenlogin.php  eine Kundensitzung für Portal und Buchung
              │
        lib/vorlage.php      Ausgabebausteine (kennzahl, pille, person …)
              │
        lib/*.php            Fachlogik: Customers, Bookings, Commerce,
              │              Invoices, Training, Campaigns, KI …
              │
        lib/Tenant.php       Mandantengrenze – hier und nur hier
              │
        lib/DB.php           PDO, typisiert gebunden
              │
        SQLite oder MySQL
```

Seiten enthalten Darstellung und den Ablauf eines Formulars. Alles, was
mehr als eine Seite betrifft, steht in `lib/`. Die Regel ist grob, aber
sie hält: Wenn zwei Seiten dieselben zwanzig Zeilen brauchen, gehören sie
in eine Klasse.

## Die Mandantengrenze

Der wichtigste Teil des Systems ist auch der kleinste:

```php
private static function wo(string $bedingung = ''): string
{
    $wo = 'workspace_id = ' . (int) self::$id;
    return $bedingung !== '' ? $wo . ' AND (' . $bedingung . ')' : $wo;
}
```

Jede Abfrage der Anwendung geht durch `Tenant::all()`, `one()`, `find()`,
`count()`, `sum()`, `insert()`, `update()`, `delete()`. Keine dieser
Methoden lässt sich ohne den Filter aufrufen, weil der Aufrufer ihn gar
nicht formuliert. Ein vergessenes `WHERE workspace_id = …` – der klassische
Fehler in Mandantensystemen – ist so nicht möglich.

`Tenant::find()` liefert für einen fremden Datensatz `null`. Wer eine
fremde Kennung in die Adresszeile schreibt, bekommt „nicht gefunden" und
erfährt nicht einmal, dass es den Datensatz gibt.

Die wenigen Stellen mit direktem `DB::`-Zugriff sind Verbünde über mehrere
Tabellen; sie tragen `workspace_id` ausdrücklich in der Bedingung und sind
im Code als Ausnahme kenntlich.

## Geld, Zeit, Zahlen

**Geld** ist immer `int` in Cent. Fließkomma auf Preisen erzeugt genau die
Rundungsfehler, die der Kunde auf der Rechnung sieht und die Buchhaltung
nicht ausgleichen kann. Rabatte werden mit `Util::verteilen()` auf die
Positionen aufgeteilt: Die Funktion verteilt den Rest nach dem größten
Bruchteil, damit die Summe der Teile exakt dem Ganzen entspricht.

**Zeitstempel** sind Text im Format `Y-m-d H:i:s`. Damit sortieren und
vergleichen SQLite und MySQL gleich, ohne Sonderbehandlung.

**Dezimalzahlen aus Formularen** gehen durch `Util::zahlAus()`. PHPs
eigener Cast hört beim Komma auf: `(float) "13,6"` ergibt `13` – still und
ohne Fehlermeldung. Handicaps stehen deshalb mit Punkt in der Datenbank
(`Util::hcpNormal`) und werden erst bei der Ausgabe deutsch formatiert
(`Util::hcp`).

**Gebundene Parameter** bekommen ihren Typ mit. `DB::query()` bindet
Ganzzahlen als `PDO::PARAM_INT`, nicht als Text. Das ist kein
Schönheitsfehler: SQLite ordnet TEXT über INTEGER, und ein als Text
gebundenes `60` ließ einen Vergleich `… >= '60'` lautlos ins Leere laufen.

## Das Schema

67 Tabellen, in einer Datei (`lib/Schema.php`) mit Platzhaltern, die je
Treiber übersetzt werden:

| Platzhalter | SQLite | MySQL |
|---|---|---|
| `%PK%` | `INTEGER PRIMARY KEY AUTOINCREMENT` | `INT UNSIGNED … AUTO_INCREMENT` |
| `%STR(n)%` | `TEXT` | `VARCHAR(n)` |
| `%TEXT%` | `TEXT` | `MEDIUMTEXT` |
| `%DT%` | `TEXT` | `DATETIME` |

Die Migration läuft selbsttätig: `lib/bootstrap.php` vergleicht
`Schema::VERSION` mit dem Wert in `settings` (Zeile mit `workspace_id = 0`)
und ruft bei Unterschied `Schema::migrate()`. Neue Spalten kommen über
`Schema::spalteSicherstellen()` dazu – idempotent, in beiden Treibern.

Fremdschlüssel gibt es bewusst nicht als Datenbankbedingung. Auf
Shared Hosting sind sie je nach Engine unterschiedlich streng, und die
Anwendung achtet ohnehin selbst auf die Beziehungen – meist mit mehr
Rücksicht, als ein `ON DELETE CASCADE` nehmen würde (siehe unten).

## Belege sind Dokumente

Eine Rechnungsposition speichert Titel, Einzelpreis und Steuersatz als
eigene Werte, nicht als Verweis auf das Produkt. Dasselbe gilt für
Bestellpositionen und für Termine, die Titel und Preis der Leistung beim
Anlegen kopieren.

Das ist bewusst redundant. Wer im Frühjahr den Preis erhöht, darf die
Rechnungen des Vorjahres nicht verändern – weder in der Anzeige noch in
der Summe. Ein Beleg ist eine Momentaufnahme, keine Ansicht auf aktuelle
Stammdaten.

Daraus folgt der Umgang mit dem Löschen: Rechnungsnummern haben keine
Lücken, eine ausgestellte Rechnung wird nie gelöscht, sondern mit einer
Gutschrift korrigiert. Auch die DSGVO-Löschung eines Kunden
(`Customers::loeschen()`) lässt bezahlte Rechnungen stehen – dort gilt die
handelsrechtliche Aufbewahrungspflicht – kappt aber den Bezug zur Akte und
friert den Namen im Beleg ein.

## Rechte

Sieben Rollen, definiert in `lib/Auth.php` als Listen von Mustern:

```php
'trainer' => [
    'modul.dashboard', 'modul.customers', 'modul.calendar', …
    'customers.view', 'customers.write', 'training.*', 'video.*', …
],
```

`Auth::darf('training.plan.zuweisen')` prüft auf genaue Übereinstimmung
oder auf ein Präfix mit `*`. `Auth::fordern()` beendet die Seite mit einer
erklärenden Meldung statt mit einem nackten 403.

Bereiche, die eine Rolle nicht sehen darf, erscheinen gar nicht erst im
Menü (`Module::menue()`). Niemand klickt gern auf eine Sperre.

## Module und Tarif

`Module::LISTE` beschreibt 21 Bereiche mit Gruppe, Symbol und Mindesttarif.
Zwei Ebenen entscheiden, was sichtbar ist:

1. **Der Tarif** legt fest, was verfügbar *wäre* (`Module::imPlan()`).
2. **Die Schalter** unter *Einstellungen → Tarif* legen fest, was
   tatsächlich erscheint (`Tenant::modul()`).

Sechs Bereiche sind Kern und lassen sich nicht abschalten: Dashboard,
Website, Kunden, Kalender, Buchungen, Einstellungen. Alles andere ist
Angebot. `Module::standardFuerPlan()` schaltet für Einsteiger absichtlich
wenig ein – wer mit 22 Menüpunkten anfängt, benutzt am Ende drei.

## Der Website-Baukasten

Seiten bestehen aus Blöcken; ein Block ist JSON mit `typ`, `id` und
`daten`. `lib/Bloecke.php` beschreibt 25 Typen mit ihren Feldern,
`lib/Renderer.php` macht HTML daraus.

Entscheidend: Der Baukasten in der Anwendung und die öffentliche Website
benutzen **denselben** Renderer. Die Vorschau kann deshalb nicht von der
Wirklichkeit abweichen – sie ist die Wirklichkeit, nur in einem anderen
Rahmen. Ein zweiter Renderer für die Vorschau wäre eine Fehlerquelle, die
sich nie ganz schließen lässt.

Gerendert wird auf dem Server. Das hält die Website schnell, macht sie für
Suchmaschinen lesbar und funktioniert ohne JavaScript.

## Gestaltung

Der Renderer erzeugt kein Raster aus gleich großen Kacheln. Die
öffentliche Website folgt einer redaktionellen Ordnung: eine Titelzeile,
die über das Bild darunter läuft, Listen mit Haarlinien statt Boxen,
Zitate, die versetzt untereinander stehen, ein handschriftlicher Einwurf
dort, wo eine Person spricht.

Der Grund ist nicht Geschmack. Eine Seite aus zwölf gleichen Kacheln sieht
aus wie ein Verwaltungswerkzeug, und genau so wird sie gelesen. Die Kunden
dieser Trainer buchen Unterricht bei einem Menschen, nicht bei einem
Betrieb. Die Seite muss das aushalten.

Die Werte stehen als CSS-Variablen im `:root` von `assets/css/site.css`:
Creme `#FBF8F0` als Grund, Tannengrün `#2E6A3E` als Marke, ein
Beinahe-Schwarz `#17271C` für Text, Gelb `#F2C64B` als Marker. Die
Marke überschreibt der Mandant im Kopf der Seite – nur sie, nicht das
System dahinter; sonst könnte eine Farbwahl im Einrichtungsdialog die
Lesbarkeit zerstören.

Die eine Form-Einstellung, die der Pro hat, ist die Rundung (0 bis 28).
Sie steht nicht als fertige Ecke im Stylesheet, sondern als Maß, aus dem
`--ecke`, `--ecke-klein` und `--ecke-knopf` abgeleitet werden. Bei 0 wird
die Seite streng und kantig, bei 28 weich; die eine abweichende kleine
Ecke der Karten bleibt als Signatur erhalten. Ein Regler, der auf nichts
mehr wirkt, ist schlimmer als kein Regler – deshalb hängt hier alles, was
„Rundung“ heißt, an diesem einen Wert.

Schriftgrößen sind fließend (`clamp()`), nicht gestuft. Zwischen Telefon
und Schreibtisch liegen bei der Titelzeile 42 und 126 Pixel; das über
Haltepunkte zu schalten ergäbe Sprünge, die man sieht.

Ein paar Regeln, die von Fehlern kommen und deshalb aufgeschrieben sind:

* Absichtliche Überstände (`margin-right: clamp(-44px, -3vw, 0)`) werden
  mit `overflow-x: clip` am Abschnitt gefangen, **nicht** mit `hidden`:
  `hidden` erzeugt einen Scroll-Container und nimmt dem Kopf sein
  `position: sticky`. Am `body` wirkt beides gar nicht – der Browser
  reicht die Angabe an `html` weiter.
* Rasterspalten sind `minmax(0, 1fr)`, nicht `1fr`. Sonst wächst eine
  lange URL die Spalte über den Bildschirm hinaus.
* Der Schrittanzeiger blendet unter 680 Pixeln alle Namen außer dem
  aktuellen aus. Drei Namen nebeneinander passen nicht in 390 Pixel, und
  die Seite ließe sich seitlich verschieben.

### Schriften liegen auf dem eigenen Server

`assets/fonts/` enthält Archivo und Caveat als variable Schriftschnitte,
`assets/css/schriften.css` bindet sie ein. Kein Aufruf zu Google.

Das ist keine Vorsicht, sondern die Rechtslage: Das Landgericht München I
hat am 20.01.2022 (Az. 3 O 17493/20) entschieden, dass die Einbindung von
Google Fonts die IP-Adresse des Besuchers ohne Einwilligung an einen
Dritten überträgt und einen Unterlassungs- und Schadenersatzanspruch
begründet. Wer mit dieser Software die Website seines Betriebs
veröffentlicht, soll deswegen keine Post bekommen.

Ein variabler Schnitt je Familie und Zeichensatz deckt alle Stärken ab;
zusammen 180 KB. Wählt ein Mandant im Einrichtungsdialog eine andere
Schrift, lädt `Website::schriften()` diese weiterhin bei Google – dann
aber als bewusste Entscheidung und mit dem Hinweis daneben.

## Verfügbarkeit ist Rechnen, nicht Speichern

`Bookings::freieZeiten()` speichert keine Zeitfenster. Es nimmt die
Wochenarbeitszeiten (`availability`), zieht Abwesenheiten (`time_off`) und
bestehende Termine ab, berücksichtigt Dauer, Puffer und Vorlauf der
Leistung und gibt zurück, was übrig bleibt.

Gespeicherte Fenster müssten bei jeder Änderung nachgeführt werden und
wären nach dem ersten Sonderfall falsch. Gerechnet ist immer richtig.

Die Online-Buchung prüft die gewählte Zeit vor dem Schreiben noch einmal
gegen `freieZeiten()` – der Browser des Besuchers könnte seit zwanzig
Minuten offen liegen.

## Der Kunde meldet sich einmal an, nicht zweimal

`lib/Kundenlogin.php` ist die einzige Stelle, die die Kundensitzung setzt
und liest – für das Portal (`portal/`) und für die Buchung
(`buchen.php`) dieselbe. Wer sich im Portal angemeldet hat, ist beim
Buchen angemeldet und umgekehrt.

Vorher hatte das Portal eine eigene Sitzung namens `gp_portal`. Der Grund
war richtig: Ein abmeldender Kunde soll nicht den Trainer mit abmelden,
der denselben Browser benutzt. Das Mittel war es nicht – PHP hält je
Aufruf nur **eine** Sitzung offen, also war ein im Portal angemeldeter
Kunde beim Buchen wieder ein Fremder. Heute ist es eine Sitzung mit
getrennten Schlüsseln; `Kundenlogin::abmelden()` räumt nur die des Kunden
weg, die Anmeldung im Backend bleibt stehen.

**Die Kundensitzung verschiebt die Mandantengrenze nicht.** Steht der
Workspace schon fest – auf den öffentlichen Seiten aus Domain oder `?w=`
–, dann gilt er; ein Kunde aus einem anderen Betrieb ist dort schlicht
nicht angemeldet (`passtZumMandanten()`). Ohne diese Prüfung hätte seine
Sitzung die Seite unbemerkt umgeschaltet: Der Besucher sieht die
Leistungen der einen Golfschule und bucht in der anderen. Nur wo gar kein
Mandant feststeht – im Portal, das weder Domain noch `?w=` kennt –,
bestimmt ihn der Kunde.

Zwei Wege führen hinein: der Schlüssel aus der Terminbestätigung
(`mitToken()`, kein Passwort nötig) und E-Mail mit Passwort
(`mitPasswort()`). Beide antworten nur mit wahr oder falsch. Wer aus
„Passwort falsch" gegen „Adresse unbekannt" unterscheiden kann, hat ein
Kundenverzeichnis – deshalb prüft `mitPasswort()` auch ohne Treffer gegen
einen Wegwerf-Hash, damit die Antwort gleich lange dauert.

**Die Anmeldung bleibt freiwillig.** Auf `buchen.php` steht sie als
zugeklappter Kasten über dem Formular; daneben führt „Für jemand anderen
buchen" jederzeit zur Gastbuchung zurück. Eine Buchung, die eine
Registrierung verlangt, verliert genau die Kundschaft, die man gewinnen
wollte.

**Ein Konto gibt es auch ohne Termin.** `portal/registrieren.php` legt
eines an, ohne dass gebucht wird – verlinkt aus dem Kundenzugang, aus dem
Fuß jeder Website und klein aus dem Anmeldekasten der Buchung. Der Pro
kann den Weg abschalten (`registrierung_offen`, Einstellungen →
Buchung); die Gastbuchung bleibt davon unberührt, denn sie ist kein
Konto.

`Kundenlogin::registrieren()` antwortet mit einem von drei Wörtern:
`fehler`, `angemeldet` oder `mail`. Der dritte Fall ist der wichtige. Ist
die Adresse hier schon bekannt, sagt die Seite das **nicht** – sonst
ließe sich durchprobieren, wer bei diesem Pro Kunde ist, und das ist eine
Kundenliste. Stattdessen geht eine Mail mit dem Zugangslink an die
Adresse selbst; wer sie besitzt, kommt hinein, alle anderen erfahren
nichts. Nach außen sieht dieser Fall genauso aus wie eine frische
Registrierung. Ein bestehendes Konto wird dabei nie überschrieben:
„Registrieren" mit einer fremden Adresse wäre sonst eine Übernahme.

Beim Angemeldeten gelten seine hinterlegten Daten, nicht die aus dem
Formular – sonst könnte ein manipuliertes Formular auf fremde Namen
buchen. Und weil er den Einwilligungshaken gar nicht sieht, wird für ihn
auch keine Einwilligung protokolliert: Ein Eintrag über eine Erklärung,
die niemand abgegeben hat, ist als Nachweis wertlos.

## Segmente speichern Regeln

Ein Segment ist keine Liste von Kunden, sondern eine Bedingung:
„Handicap über 36 und seit 60 Tagen kein Termin". `Segments::bedingung()`
übersetzt die Regel in SQL, `Segments::kunden()` fragt beim Ansehen ab.

Eine gespeicherte Mitgliederliste wäre am nächsten Tag falsch, und
niemand würde sie pflegen.

## KI

Zwei getrennte Wege in `lib/KI.php`:

**Fragen zu eigenen Zahlen** („Wie viel Umsatz im Mai?") beantwortet
`datenAntwort()` mit SQL. Kein Modell, keine Übertragung nach außen, keine
erfundenen Zahlen.

**Textentwürfe** gehen an die Anthropic-API, wenn ein Schlüssel hinterlegt
ist. Ist keiner da, erzeugt `entwurfAusRegeln()` aus einer kuratierten
Textbasis einen brauchbaren Entwurf. Der Unterschied ist Qualität, nicht
Verfügbarkeit.

Vorschläge werden in `ai_suggestions` abgelegt und warten auf Annahme oder
Ablehnung. Kritische Aktionen – Preise, Kundendaten, Rechnungen, Versand,
Löschung – führt die KI grundsätzlich nicht selbst aus.

In der Videoanalyse sind `ai_analyse` und `pro_analyse` getrennte Spalten
und getrennte Blöcke in der Oberfläche. Die Trennung ist Absicht: Ein
Modell kann auf ein Bewegungsmuster hinweisen, die fachliche Bewertung
trifft der Trainer.

## Wartung ohne Cronjob

`Wartung::laufen()` erledigt, was regelmäßig anfällt: Rechnungen fällig
stellen, abgelaufene Pakete aufräumen, Erinnerungen versenden,
Automationen ausführen, vergangene Termine abschließen, Gesundheitswerte
auffrischen, alte Daten nach Aufbewahrungsfrist löschen.

Aufgerufen wird sie vom Dashboard – höchstens alle 15 Minuten, gesteuert
über einen Zeitstempel in den Einstellungen. Wer `cron.php` einrichtet,
bekommt dieselbe Routine pünktlicher. Beides zusammen schadet nicht: Die
Zeitsperre verhindert doppelte Arbeit.

Ein Fehler in der Wartung darf das Dashboard nie blockieren; deshalb liegt
alles in einem `try`, und Fehler landen im Protokoll statt auf der Seite.

## PDF ohne Bibliothek

`lib/PDF.php` schreibt Rechnungen und Trainingspläne selbst: Helvetica aus
den 14 Standardschriften, WinAnsi-Kodierung, korrekt berechnete
xref-Verweise. Gut 230 Zeilen – deutlich weniger Aufwand, als eine
Bibliothek ohne Composer aktuell zu halten, und ohne die Annahme, dass der
Hoster irgendetwas installiert hat.

## Wo die Grenzen liegen

Ehrlichkeitshalber:

* SQLite schreibt zu einem Zeitpunkt nur einmal. Für einen Betrieb mit
  einigen tausend Kunden ist das reichlich; für eine Academy mit zwanzig
  gleichzeitig buchenden Trainern ist MySQL die richtige Wahl.
* Der Newsletterversand geht über `mail()` beziehungsweise SMTP in
  Schüben. Für ein paar hundert Empfänger ist das in Ordnung; für
  Zehntausende gehört ein Versanddienst davor.
* Videos liegen im Dateisystem und werden unverändert ausgeliefert. Es
  gibt keine Umkodierung – die würde `ffmpeg` voraussetzen.
* Es gibt keine automatisierten Tests. Geprüft wurde mit `php -l` über
  alle Dateien und mit Abrufen aller Routen; das ersetzt keine Testsuite.
