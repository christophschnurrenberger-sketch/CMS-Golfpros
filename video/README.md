# TeePilot – Werbevideo

30 Sekunden, Hochformat 1080 × 1920 (9:16), 30 fps – für Reels, TikTok,
Shorts und den Status. Gebaut mit [Remotion](https://www.remotion.dev).

Alle Bildschirme im Video sind **echte Aufnahmen aus der App** – keine
nachgebauten Oberflächen. Jeder Fingertipp im Video ist ein Klick, den das
Aufnahmeskript wirklich ausgeführt hat; das Ergebnis danach (die Uhrzeiten,
das Formular, die neue Rechnung) ist das, was die App darauf geantwortet hat.

## Ablauf

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

## Benutzen

```bash
cd video
npm install

npm run aufnehmen   # frische Demo-Anlage, App starten, Screens aufnehmen
npm run studio      # Vorschau im Browser, mit Zeitleiste
npm run rendern     # → out/teepilot-werbevideo.mp4
```

`npm run aufnehmen` legt in einem Wegwerf-Ordner eine eigene SQLite-Datei mit
dem Demo-Workspace „Golf Academy Bergmann“ an – datiert auf heute, damit der
Kalender „nächste Woche“ wirklich voll ist –, startet die App mit dem
eingebauten PHP-Server und nimmt mit Playwright auf. Die eigene Datenbank und
die `config.php` der Anlage werden nicht angefasst. Ergebnis:
`public/screens/*.png` und `src/screens.json` (Maße, feste Leisten und die
Stellen, an denen getippt wird).

Gebraucht werden PHP 8.1+ mit `pdo_sqlite`, Node 18+ und Playwright mit
Chromium. Liegt Playwright nicht unter `/opt/node22/lib/node_modules`, den
Pfad mit `PLAYWRIGHT_PFAD` angeben, den Browser mit `CHROMIUM_PFAD`. Für das
Rendern nimmt Remotion ein vorhandenes Chrome Headless Shell
(`REMOTION_CHROME`), sonst lädt es eines herunter.

### Knopf und Adresse am Ende

Text auf dem Knopf und eine Adresse darunter sind Eingaben der Komposition:

```bash
npx remotion render TeePilot out/teepilot-werbevideo.mp4 \
  --props='{"aufruf":"Jetzt Demo anfragen","adresse":"teepilot.de"}'
```

Ohne `adresse` bleibt die Zeile weg – es steht keine erfundene Domain im Bild.

## Was bewusst so ist

* **Kein Ton.** Musik kommt auf Instagram, TikTok und YouTube aus deren
  lizenzierter Bibliothek; eine eingebettete Spur ohne Lizenz würde dort
  stummgeschaltet. Wer eigene, lizenzierte Musik hat, legt sie als
  `<Audio>` in `src/Werbevideo.tsx`.
* **Sichere Zonen.** Überschriften stehen zwischen 270 und 600 px von oben,
  der Aufruf am Ende um die Mitte – dort, wo weder Profilzeile noch
  Beschreibung und Knöpfe der Plattformen liegen.
* **Das Bild auf der Startseite.** Der Demo-Workspace hat dort nur den
  gestreiften Platzhalter. Das Aufnahmeskript setzt über den Endpunkt des
  Bildwählers (`app/baustein.php`) eine der mitgelieferten Illustrationen
  (`assets/demo/reisen/gruen.svg`) – so, wie ein Pro sein eigenes Foto setzt.
* **Die neue Rechnung ist echt.** Der Tipp auf „Rechnung“ legt in der
  Wegwerf-Anlage wirklich eine Rechnung an. Deshalb läuft die Aufnahme nie
  gegen die eigene Datenbank.

## Lizenzen

* Remotion ist für Einzelpersonen und Firmen bis drei Mitarbeitende
  kostenlos; größere Firmen brauchen eine Firmenlizenz
  (<https://www.remotion.dev/license>).
* Die Schriften in `public/fonts` stehen unter der SIL Open Font License,
  siehe `public/fonts/LIZENZ.txt`.

## Aufbau

```
aufnahme/alles-aufnehmen.sh   Demo-Anlage, App, Aufnahme, Aufräumen
aufnahme/aufnehmen.js         Playwright: Seiten, Klicks, feste Leisten
src/Werbevideo.tsx            Reihenfolge, Längen, Übergänge
src/Szenen.tsx                die zehn Szenen
src/teile/                    Telefon, Browserfenster, Überschrift, Marke, Grund
src/marke.ts                  Farben und Schriften aus lib/Marke.php / app.css
```
