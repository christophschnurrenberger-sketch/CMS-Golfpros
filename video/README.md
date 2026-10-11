# TeePilot – Werbevideos

Gebaut mit [Remotion](https://www.remotion.dev), 30 fps.

| Komposition | Format | Länge | Ton | Wofür |
|---|---|---|---|---|
| `TeePilot` | 1080 × 1920 (9:16) | 30 s | ohne | Reels, TikTok, Status – Musik aus der Plattform |
| `TeePilotLang` | 1080 × 1920 (9:16) | 60 s | eigene Musik | Reels, TikTok, YouTube Shorts |
| `TeePilotQuer` | 1920 × 1080 (16:9) | 60 s | eigene Musik | YouTube und die eigene Website |
| `Vorschaubild` | 1920 × 1080 | Standbild | – | Poster auf der Website, als 1280 × 720 das YouTube-Thumbnail |

`TeePilotQuer` ist dieselbe Fassung wie `TeePilotLang` – gleicher Ablauf,
gleiche Musik. Die Szenen fragen das Format ab (`src/teile/Format.ts`) und
ordnen sich selbst um: hoch steht die Überschrift oben und die Geräte
darunter, quer steht sie links und rechts daneben Schreibtisch und Telefon.

## Quer: YouTube und Website

```bash
npm run rendern:quer   # → out/teepilot-youtube-1080p.mp4 und out/teepilot-website-1080p.mp4
npm run vorschaubild   # → out/teepilot-vorschau-1920x1080.jpg und out/teepilot-youtube-thumbnail-1280x720.jpg
```

* **YouTube:** `teepilot-youtube-1080p.mp4` hochladen (höchste Qualität –
  YouTube kodiert ohnehin neu) und `teepilot-youtube-thumbnail-1280x720.jpg`
  als benutzerdefiniertes Thumbnail setzen. Das Hochformat (`TeePilotLang`)
  geht als Short.
* **Website:** `teepilot-website-1080p.mp4` ist kleiner (CRF 25, Ton
  128 kbit/s) und beginnt sofort zu spielen, weil das Inhaltsverzeichnis der
  Datei vorne steht. Selbst gehostet statt YouTube eingebettet: Dann lädt
  die Seite nichts von Google, und es braucht dafür keine Einwilligung –
  wie bei den Schriften der App.

```html
<video controls playsinline preload="metadata"
       poster="/videos/teepilot-vorschau-1920x1080.jpg"
       width="1920" height="1080" style="width:100%;height:auto;border-radius:12px">
  <source src="/videos/teepilot-website-1080p.mp4" type="video/mp4">
</video>
```

`preload="metadata"` lädt nur die ersten Kilobyte, bis jemand auf Abspielen
drückt; Autoplay mit Ton blockieren die Browser ohnehin.

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

## Ablauf der langen Fassung (60 s, mit Musik)

Die ersten zehn Sekunden sagen, für wen TeePilot ist: erst der Haken, dann
auf dem Einschlag der Musik ein harter Schnitt auf Logo und Zielgruppe –
„Die App für Golfpros, Coaches & Academies“, je Gruppe ein Schlag, darunter
„für alles neben dem Unterricht“. Sechs Sekunden, damit es auch ankommt.

Danach steht jeder Bildschirm gut vier Sekunden – zwei Takte der Musik
(120 BPM), jede Szene beginnt auf einem Schlag. Innerhalb einer Szene gilt:
erst steht die Überschrift (1 s), dann scrollt oder zoomt der Bildschirm,
und vor dem Schnitt hält er eine Sekunde still. Das ist die übliche
Faustregel für Text im Video: so lange zeigen, dass man ihn zweimal lesen
kann.

| Sekunde | Musik | Szene | Schreibtisch | Telefon |
|---|---|---|---|---|
| 0–4 | Fläche, Filter öffnet sich | Du gibst Golfstunden. Keine Bürostunden. | – | – |
| 4–10 | **Einschlag bei 4 s**, harter Schnitt | Die App für Golfpros, Coaches & Academies | – | – |
| 10–15 | Groove | Schüler buchen online | – | Buchung mit zwei Tipps |
| 15–19 | | Alles landet in deinem Kalender | Wochenansicht | Tagesansicht |
| 19–23 | | Dein Tag. Sortiert. | Dashboard, scrollt | Dashboard |
| 23–27,5 | | Jeder Schüler auf einen Blick | Kundenakte | Kundenakte bis Handicap |
| 27,5–31,5 | | Trainingspläne | Trainingsplan | Trainingsplan |
| 31,5–35,5 | Pause bei 31 s, zweiter Teil mit Glocke | Aus Anfragen werden Schüler | Leads-Pipeline | – |
| 35,5–40 | | Deine Website. Ohne Agentur. | Baukasten → fertige Seite | Website |
| 40–44,5 | | Golfreisen wie im Reisebüro | Reise-Verwaltung | Reiseseite |
| 44,5–49 | | Rechnung? Ein Klick. | die neue Rechnung | Tipp → Rechnung |
| 49–53 | | Zahlen, die du verstehst | Auswertung | – |
| 53–56 | Wirbel, Steigerung | Deine Schüler haben ihr Portal | – | Kundenportal |
| 56–60 | **Einschlag bei 56 s**, Schlussakkord bei 58 s | Dein Auftritt. In deiner Hand. – Aufruf | Dashboard | Akte, Portal, Reise |

## Benutzen

```bash
cd video
npm install

npm run aufnehmen      # frische Demo-Anlage, App starten, Screens aufnehmen
npm run musik          # Musik und Geräusche neu erzeugen (public/ton/)
npm run studio         # Vorschau im Browser, mit Zeitleiste
npm run rendern        # → out/teepilot-werbevideo.mp4            (30 s)
npm run rendern:lang   # → out/teepilot-werbevideo-60s.mp4        (60 s, Musik und Sprecher)
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
npx remotion render TeePilotLang out/teepilot-werbevideo-60s.mp4 \
  --props='{"aufruf":"Jetzt Demo anfragen","adresse":"teepilot.de"}'
```

Ohne `adresse` bleibt die Zeile weg – es steht keine erfundene Domain im Bild.

## Sprecher

Die lange Fassung (hoch und quer) kann eine deutsche Sprecherstimme tragen:
ein Satz je Szene, der die Überschrift ergänzt, statt sie vorzulesen. Die
Musik wird unter der Stimme auf ein Viertel gezogen. Text und Lautschrift
stehen in `src/sprechertext.json`; Ablauf und Längen der Szenen in
`src/ablauf-lang.json` – dieselbe Datei, aus der das Video seine Szenen
nimmt, damit Stimme und Bild nicht auseinanderlaufen.

```bash
npm run sprecher -- --takes 6   # Stimme je Satz erzeugen, besten Take wählen, Untertitel schreiben
npm run rendern:quer            # danach wie gewohnt rendern – die Stimme ist dabei
```

`npm run sprecher` prüft, dass jeder Satz in seine Szene passt (wird Piper
bis 15 % schneller, sonst Abbruch mit dem Satz, der zu lang ist), schreibt
`src/sprecher.json` und die Untertitel `out/teepilot-untertitel.srt` (für
YouTube) und `.vtt` (für `<track>` auf der Website). Ohne Stimme rendert das
Video wie bisher nur mit Musik; mit `--props='{"stimme":false}'` auch dann.

Die gesprochenen Sätze (`public/ton/sprecher/*.wav`, zusammen gut 1 MB)
stehen im Repository – anders als die Musik: Piper klingt bei jedem
Durchlauf etwas anders, die ausgewählten Takes ließen sich also nicht
wiederherstellen. Rendern geht darum ohne Piper; neu sprechen nur, wenn sich
der Text ändert.

**Woher die Stimme kommt** – je Satz das Erste, was da ist:

1. **Eigene Aufnahme:** `public/ton/sprecher/eigene/01-hook.wav`,
   `02-zielgruppe.wav` … (Nummer und Szene wie in `src/sprechertext.json`).
   Eine echte Stimme – etwa die des Pros selbst – wirkt in einer Anzeige
   glaubwürdiger als jede Synthese. Handy-Aufnahme in ruhigem Raum genügt.
2. **Piper** (offline, neuronale Sprachsynthese): Stimme
   „Thorsten“ (`de_DE-thorsten-high`), deren Sprecher sie unter CC0
   freigegeben hat – auch für Werbung nutzbar. `pip install piper-tts`,
   dann Modell und Konfiguration nach `ton/stimmen/` laden – von Hugging Face
   oder, falls das gesperrt ist, aus dem Paket von sherpa-onnx auf GitHub
   (dieselbe Stimme, mit Modellkarte):

   ```bash
   mkdir -p ton/stimmen && cd ton/stimmen
   curl -LO https://huggingface.co/rhasspy/piper-voices/resolve/main/de/de_DE/thorsten/high/de_DE-thorsten-high.onnx
   curl -LO https://huggingface.co/rhasspy/piper-voices/resolve/main/de/de_DE/thorsten/high/de_DE-thorsten-high.onnx.json
   # oder:
   curl -L https://github.com/k2-fsa/sherpa-onnx/releases/download/tts-models/vits-piper-de_DE-thorsten-high.tar.bz2 \
     | tar -xj --strip-components=1 vits-piper-de_DE-thorsten-high/de_DE-thorsten-high.onnx vits-piper-de_DE-thorsten-high/de_DE-thorsten-high.onnx.json
   ```

   Synthetische Stimmen sprechen englische Wörter deutsch – darum steht in
   `src/sprechertext.json` neben dem Text eine Lautschrift („Kootsches“,
   „Eckädemies“, „Paiplain“). In `[[ … ]]` gehen Laute direkt an die Stimme
   (IPA, wie espeak sie schreibt): So wird „TeePilot“ zu „Ti-Pilot“ mit
   deutscher Betonung auf „-lot“ – `[[ tˈiːpiːlˈoːt. ]]`.

**Takes:** Piper klingt bei jedem Durchlauf etwas anders, und mal gerät ein
Wort undeutlich. `--takes 6` spricht jeden Satz sechsmal, `ton/abhoeren.py`
schreibt mit Whisper auf, was zu verstehen ist, und genommen wird der Take,
dessen Abschrift dem Text am nächsten kommt; das Protokoll zeigt je Satz die
Abschrift. Dafür `pip install sherpa-onnx` und das Whisper-Modell (offline,
MIT-Lizenz) nach `ton/stimmen/`:

```bash
cd ton/stimmen
curl -L https://github.com/k2-fsa/sherpa-onnx/releases/download/asr-models/sherpa-onnx-whisper-small.tar.bz2 \
  | tar -xj sherpa-onnx-whisper-small/small-encoder.int8.onnx sherpa-onnx-whisper-small/small-decoder.int8.onnx sherpa-onnx-whisper-small/small-tokens.txt
```

Abhören ersetzt das Anhören nicht ganz – Whisper schreibt etwa „T-Pilot“ für
„Ti-Pilot“ –, aber es findet zuverlässig den Take, in dem ein Wort verwaschen
ist.

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
legt es als `public/ton/musik-lang.wav` ab (60 s); die Schnitte bleiben auf den
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
* Die Sprecherstimme in `public/ton/sprecher` ist mit Piper und der Stimme
  „Thorsten“ erzeugt; Thorsten Müller hat Stimme und Datensatz unter CC0
  freigegeben (<https://github.com/thorstenMueller/Thorsten-Voice>) – frei,
  auch für Werbung, ohne Namensnennung.

## Aufbau

```
aufnahme/alles-aufnehmen.sh   Demo-Anlage, App, Aufnahme, Aufräumen
aufnahme/aufnehmen.js         Playwright: Seiten, Klicks, feste Leisten
ton/musik.mjs                 Musik und Geräusche, im Code synthetisiert
ton/sprecher.mjs              Sprecherstimme je Satz (Piper oder eigene Aufnahme), Untertitel
ton/abhoeren.py               Whisper-Abschrift der Takes (für --takes)
src/ablauf-lang.json          Szenen und Längen der langen Fassung
src/sprechertext.json         was gesprochen wird, mit Lautschrift
src/Werbevideo.tsx            kurze Fassung: Reihenfolge, Längen, Übergänge
src/Werbevideo2.tsx           lange Fassung: dasselbe, dazu Musik und Geräusche
src/Szenen.tsx, Szenen2.tsx   die Szenen – hoch und quer
src/Vorschaubild.tsx          Thumbnail und Poster
src/teile/                    Telefon, Browserfenster, Überschrift, Marke, Grund, Ton, Format
src/marke.ts                  Farben und Schriften aus lib/Marke.php / app.css
```
