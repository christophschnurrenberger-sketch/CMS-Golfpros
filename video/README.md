# TeePilot – Werbevideos

Zwei Fassungen im Hochformat 1080 × 1920 (9:16), 30 fps – für Reels, TikTok,
Shorts und den Status. Gebaut mit [Remotion](https://www.remotion.dev).

| Komposition | Länge | Ton | Inhalt |
|---|---|---|---|
| `TeePilot` | 30 s | ohne | sieben Funktionen, überwiegend auf dem Telefon |
| `TeePilotLang` | 45 s | eigene Musik + Geräusche | elf Funktionen, jede am Schreibtisch **und** auf dem Telefon |

Alle Bildschirme sind **echte Aufnahmen aus der App** – keine nachgebauten
Oberflächen. Jeder Fingertipp ist ein Klick, den das Aufnahmeskript wirklich
ausgeführt hat; das Ergebnis danach (die Uhrzeiten, das Formular, die neue
Rechnung) ist das, was die App darauf geantwortet hat.

## Ablauf der kurzen Fassung (30 s)

| Sekunde | Szene | Aufnahme |
|---|---|---|
| 0–3 | „Du gibst Golfstunden. Keine Bürostunden.“ | – |
| 3–5 | Marke: TeePilot, die App für Golfpros, Coaches und Academies | – |
| 5–9 | Schüler buchen online: Kalender → Tag → Uhrzeit → Angaben | `buchen.php` |
| 9–12 | Alles landet in deinem Kalender | Wochenansicht, Desktop |
| 12–15 | Jeder Schüler auf einen Blick | Kundenakte bis zum Handicap-Verlauf |
| 15–18 | Trainingspläne für jeden Schüler | Trainingsplan |
| 18–21 | Deine Website. Ohne Agentur. | Baukasten + Website auf dem Telefon |
| 21–23 | Golfreisen wie im Reisebüro | Reiseseite mit Buchungsleiste |
| 23–26 | Rechnung? Ein Klick. | „Noch nicht abgerechnet“ → Rechnung |
| 26–30 | Dein Auftritt. In deiner Hand. – Aufruf | Akte, Portal, Reise |

## Ablauf der langen Fassung (45 s, mit Musik)

Die Musik hat 120 BPM; jede Szene beginnt auf einem Schlag.

| Sekunde | Musik | Szene | Schreibtisch | Telefon |
|---|---|---|---|---|
| 0–3 | Fläche, Filter öffnet sich | Golfstunden statt Bürostunden | – | – |
| 3–5,5 | **Einschlag bei 4 s** | Marke – der Ball erscheint auf dem Schlag | – | – |
| 5,5–10 | Groove | Schüler buchen online | – | Buchung mit zwei Tipps |
| 10–13 | | Alles landet in deinem Kalender | Wochenansicht | Tagesansicht |
| 13–16 | | Dein Tag. Sortiert. | Dashboard, scrollt | Dashboard |
| 16–19,5 | Pause, Steigerung | Jeder Schüler auf einen Blick | Kundenakte | Kundenakte bis Handicap |
| 19,5–22,5 | zweiter Teil mit Glocke | Trainingspläne | Trainingsplan | Trainingsplan |
| 22,5–25 | | Aus Anfragen werden Schüler | Leads-Pipeline | – |
| 25–28,5 | | Deine Website. Ohne Agentur. | Baukasten → fertige Seite | Website |
| 28,5–32 | | Golfreisen wie im Reisebüro | Reise-Verwaltung | Reiseseite |
| 32–35,5 | | Rechnung? Ein Klick. | die neue Rechnung | Tipp → Rechnung |
| 35,5–38 | | Zahlen, die du verstehst | Auswertung | – |
| 38–40 | Wirbel, Steigerung | Deine Schüler haben ihr Portal | – | Kundenportal |
| 40–45 | **Einschlag bei 40 s**, Schlussakkord bei 44 s | Dein Auftritt. In deiner Hand. – Aufruf | Dashboard | Akte, Portal, Reise |

## Benutzen

```bash
cd video
npm install

npm run aufnehmen      # frische Demo-Anlage, App starten, Screens aufnehmen
npm run musik          # Musik und Geräusche neu erzeugen (public/ton/)
npm run studio         # Vorschau im Browser, mit Zeitleiste
npm run rendern        # → out/teepilot-werbevideo.mp4            (30 s)
npm run rendern:lang   # → out/teepilot-werbevideo-45s-musik.mp4  (45 s)
```

`npm run aufnehmen` legt in einem Wegwerf-Ordner eine eigene SQLite-Datei mit
dem Demo-Workspace „Golf Academy Bergmann“ an – datiert auf heute, damit der
Kalender „nächste Woche“ wirklich voll ist –, startet die App mit dem
eingebauten PHP-Server und nimmt mit Playwright auf: das Telefon mit
dreifacher, den Schreibtisch mit doppelter Pixeldichte. Die eigene Datenbank
und die `config.php` der Anlage werden nicht angefasst. Ergebnis:
`public/screens/*.png` und `src/screens.json` (Maße, feste Leisten und die
Stellen, an denen getippt wird).

Gebraucht werden PHP 8.1+ mit `pdo_sqlite`, Node 18+ und Playwright mit
Chromium. Liegt Playwright nicht unter `/opt/node22/lib/node_modules`, den
Pfad mit `PLAYWRIGHT_PFAD` angeben, den Browser mit `CHROMIUM_PFAD`. Für das
Rendern nimmt Remotion ein vorhandenes Chrome Headless Shell
(`REMOTION_CHROME`), sonst lädt es eines herunter.

### Knopf und Adresse am Ende

Text auf dem Knopf und eine Adresse darunter sind Eingaben der Kompositionen:

```bash
npx remotion render TeePilotLang out/teepilot-werbevideo-45s-musik.mp4 \
  --props='{"aufruf":"Jetzt Demo anfragen","adresse":"teepilot.de"}'
```

Ohne `adresse` bleibt die Zeile weg – es steht keine erfundene Domain im Bild.

## Die Musik

`ton/musik.mjs` komponiert und synthetisiert die Spur im Code – kein Sample,
keine fremde Aufnahme, darum keine Lizenzfrage und keine Stummschaltung auf
den Plattformen. House-Groove in D-Dur (D – A – h – G), 120 BPM: Bassdrum mit
Tonhöhenhüllkurve, Clap und Hi-Hats aus gefiltertem Rauschen, Bass auf den
Offbeats, eine Fläche aus verstimmten Sägezähnen, ein Arpeggio mit
Ping-Pong-Echo, ab der zweiten Hälfte eine FM-Glocke. Die Bassdrum duckt
Bass, Fläche und Arpeggio; dazu Hall nach Freeverb. Gemastert auf −1 dBFS
Spitze, etwa −13 dBFS RMS; im Video läuft sie mit 85 %.

Die WAV-Dateien stehen nicht im Repository: `npm run studio` und
`npm run rendern:lang` erzeugen sie vorher selbst (2–3 Sekunden, jedes Mal
dieselbe Spur). Wer direkt `npx remotion …` aufruft, startet vorher
`npm run musik`.

`PEGEL=1 npm run musik` zeigt die Pegel jeder Spur – Musik prüft man ohne
Lautsprecher mit Zahlen. Wer lieber ein Stück aus einer Bibliothek nimmt,
legt es als `public/ton/musik-45s.wav` ab; die Schnitte bleiben auf den
Schlägen, wenn es 120 BPM hat.

## Was bewusst so ist

* **Die kurze Fassung hat keinen Ton** – für Plattformen, auf denen man die
  Musik aus deren Bibliothek wählt.
* **Sichere Zonen.** Überschriften stehen zwischen 270 und 600 px von oben,
  der Aufruf am Ende um die Mitte – dort, wo weder Profilzeile noch
  Beschreibung und Knöpfe der Plattformen liegen.
* **Das Bild auf der Startseite.** Der Demo-Workspace hat dort nur den
  gestreiften Platzhalter. Das Aufnahmeskript setzt über den Endpunkt des
  Bildwählers (`app/baustein.php`) eine der mitgelieferten Illustrationen
  (`assets/demo/reisen/gruen.svg`) – so, wie ein Pro sein eigenes Foto setzt.
* **Die neue Rechnung ist echt.** Der Tipp auf „Rechnung“ legt in der
  Wegwerf-Anlage wirklich eine Rechnung an; der Schreibtisch zeigt danach
  genau diese. Deshalb läuft die Aufnahme nie gegen die eigene Datenbank.

## Lizenzen

* Remotion ist für Einzelpersonen und Firmen bis drei Mitarbeitende
  kostenlos; größere Firmen brauchen eine Firmenlizenz
  (<https://www.remotion.dev/license>).
* Die Schriften in `public/fonts` stehen unter der SIL Open Font License,
  siehe `public/fonts/LIZENZ.txt`.
* Musik und Geräusche in `public/ton` entstehen in `ton/musik.mjs` und
  gehören zu diesem Projekt.

## Aufbau

```
aufnahme/alles-aufnehmen.sh   Demo-Anlage, App, Aufnahme, Aufräumen
aufnahme/aufnehmen.js         Playwright: Seiten, Klicks, feste Leisten
ton/musik.mjs                 Musik und Geräusche, im Code synthetisiert
src/Werbevideo.tsx            kurze Fassung: Reihenfolge, Längen, Übergänge
src/Werbevideo2.tsx           lange Fassung: dasselbe, dazu Musik und Geräusche
src/Szenen.tsx, Szenen2.tsx   die Szenen
src/teile/                    Telefon, Browserfenster, Überschrift, Marke, Grund, Ton
src/marke.ts                  Farben und Schriften aus lib/Marke.php / app.css
```
