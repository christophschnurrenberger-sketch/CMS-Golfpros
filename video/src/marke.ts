/**
 * Farben und Schriften von TeePilot – dieselben Werte wie in
 * lib/Marke.php und assets/css/app.css.
 */
import {cancelRender, continueRender, delayRender, staticFile} from 'remotion';
import screensJson from './screens.json';

export const farbe = {
  pine: '#0b2b22',
  fairway: '#12513f',
  signal: '#c3e35c',
  horizon: '#8fcbb4',
  sand: '#e4dac4',
  chalk: '#f6f5f0',
  anthrazit: '#1c201d',
};

export const schrift = {
  titel: '"Schibsted Grotesk", system-ui, sans-serif',
  hand: 'Caveat, cursive',
  mono: '"IBM Plex Mono", ui-monospace, monospace',
};

/* Die Schriften liegen in public/fonts – dieselben Dateien wie in der App. */
const warten = delayRender('Schriften laden');
Promise.all(
  [
    new FontFace('Schibsted Grotesk', `url(${staticFile('fonts/schibsted-grotesk-400-900-latin.woff2')}) format('woff2')`, {weight: '400 900'}),
    new FontFace('Caveat', `url(${staticFile('fonts/caveat-latin.woff2')}) format('woff2')`, {weight: '400 700'}),
    new FontFace('IBM Plex Mono', `url(${staticFile('fonts/ibm-plex-mono-500-latin.woff2')}) format('woff2')`, {weight: '500'}),
  ].map((f) => f.load()),
)
  .then((geladen) => {
    // FontFaceSet.add fehlt in manchen DOM-Typen, im Browser gibt es sie.
    geladen.forEach((f) => (document.fonts as unknown as {add: (f: FontFace) => void}).add(f));
    continueRender(warten);
  })
  .catch((e) => cancelRender(e));

/* ------------------------------------------------------- Aufnahmen --- */

export type Rechteck = {x: number; y: number; breite: number; hoehe: number};
export type Leiste = Rechteck & {bild: string; unten: boolean};
export type Aufnahme = {
  bild: string;
  breite: number;
  hoehe: number;
  /** Höhe des sichtbaren Fensters beim Aufnehmen (Telefon 844, Desktop 900). */
  fenster?: number;
  statusfarbe: string | null;
  overlays: Leiste[];
  marken: Record<string, Rechteck>;
  scrollZiel?: number;
};

/** Von aufnahme/aufnehmen.js geschrieben – Maße in CSS-Pixeln der Seite. */
export const screens = screensJson as unknown as Record<string, Aufnahme>;

export const aufnahme = (name: string): Aufnahme => {
  const a = screens[name];
  if (!a) {
    throw new Error(`Aufnahme „${name}“ fehlt – erst npm run aufnehmen ausführen.`);
  }
  return a;
};

/** Mitte einer Marke, als Tipp-Punkt im sichtbaren Ausschnitt. */
export const mitte = (r: Rechteck, scroll = 0) => ({x: r.x + r.breite / 2, y: r.y + r.hoehe / 2 - scroll});
