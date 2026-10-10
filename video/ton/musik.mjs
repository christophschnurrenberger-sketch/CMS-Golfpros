/**
 * Die Musik zum Video – hier im Code komponiert und synthetisiert.
 *
 *   node ton/musik.mjs
 *
 * Kein Sample, keine fremde Spur: Jeder Ton entsteht in dieser Datei. Damit
 * gibt es keine Lizenzfrage, und Instagram, TikTok oder YouTube schalten das
 * Video nicht stumm. Ergebnis in public/ton/:
 *
 *   musik-lang.wav  58 Sekunden, 120 BPM, D-Dur (D – A – h – G)
 *   tipp.wav        ein leiser Fingertipp
 *   wisch.wav       ein kurzes Rauschen für die Übergänge
 *
 * Ablauf (ein Takt = 2 s, die Schnitte im Video fallen auf Schläge):
 *    0–4 s        Fläche und Arpeggio, Filter öffnet sich, Steigerung
 *    4 s          Einschlag – das Logo erscheint
 *    4–BRUCH      Groove: Bassdrum, Clap, Hi-Hats, Bass, Fläche, Arpeggio
 *    BRUCH        ein halber Takt ohne Bassdrum, Steigerung
 *    danach       zweiter Teil: offene Hi-Hats und eine Glocke mit kleinem Motiv
 *    SCHLUSS−2    Wirbel und Steigerung
 *    SCHLUSS      Einschlag – der Schluss mit dem Knopf
 *    ENDE         Schlussakkord, ausklingen bis DAUER
 */
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';

const SR = 44100;
const BPM = 120;
const SCHLAG = 60 / BPM;
const TAKT = SCHLAG * 4;
const SECHZEHNTEL = SCHLAG / 4;
const DAUER = 58;
/* Wo die Musik etwas tut – passend zu src/Werbevideo2.tsx (Startframe / 30). */
const BRUCH = 29; //   ein halber Takt ohne Bassdrum, danach der zweite Teil
const SCHLUSS = 52; // Einschlag: der Schluss mit dem Knopf beginnt
const ENDE = 56; //    Schlussakkord, klingt bis DAUER aus
const N = Math.ceil(DAUER * SR);

const ziel = path.join(path.dirname(fileURLToPath(import.meta.url)), '..', 'public', 'ton');

/* Reproduzierbares Rauschen: dieselbe Datei bei jedem Lauf. */
let saat = 20261010;
const zufall = () => {
  saat = (saat * 1664525 + 1013904223) >>> 0;
  return saat / 4294967296;
};
const rauschen = () => zufall() * 2 - 1;

const mtof = (m) => 440 * Math.pow(2, (m - 69) / 12);

class Spur {
  constructor(n = N) {
    this.l = new Float32Array(n);
    this.r = new Float32Array(n);
  }
  add(i, wert, pan = 0) {
    if (i < 0 || i >= this.l.length) return;
    this.l[i] += wert * Math.cos((pan + 1) * Math.PI / 4);
    this.r[i] += wert * Math.sin((pan + 1) * Math.PI / 4);
  }
}

/* Biquad nach dem Audio-EQ-Kochbuch (R. Bristow-Johnson). */
class Biquad {
  constructor(art, f, q = 0.707) {
    this.art = art;
    this.z1 = 0;
    this.z2 = 0;
    this.setze(f, q);
  }
  setze(f, q = this.q) {
    this.q = q;
    const w = (2 * Math.PI * Math.min(f, SR * 0.45)) / SR;
    const a = Math.sin(w) / (2 * q);
    const c = Math.cos(w);
    let b0, b1, b2;
    if (this.art === 'tp') [b0, b1, b2] = [(1 - c) / 2, 1 - c, (1 - c) / 2];
    else if (this.art === 'hp') [b0, b1, b2] = [(1 + c) / 2, -(1 + c), (1 + c) / 2];
    else [b0, b1, b2] = [a, 0, -a];
    const a0 = 1 + a;
    this.b0 = b0 / a0;
    this.b1 = b1 / a0;
    this.b2 = b2 / a0;
    this.a1 = (-2 * c) / a0;
    this.a2 = (1 - a) / a0;
  }
  tick(x) {
    const y = this.b0 * x + this.z1;
    this.z1 = this.b1 * x - this.a1 * y + this.z2;
    this.z2 = this.b2 * x - this.a2 * y;
    return y;
  }
}

/* Sägezahn mit PolyBLEP – ohne das klirren hohe Töne. */
const polyblep = (t, dt) => {
  if (t < dt) {
    t /= dt;
    return t + t - t * t - 1;
  }
  if (t > 1 - dt) {
    t = (t - 1) / dt;
    return t * t + t + t + 1;
  }
  return 0;
};
const saege = (phase, dt) => 2 * phase - 1 - polyblep(phase, dt);

/* ------------------------------------------------------------ Harmonie */

const AKKORDE = [
  {grund: 38, flaeche: [62, 66, 69], arp: [74, 78, 81, 86]}, // D
  {grund: 45, flaeche: [61, 64, 69], arp: [73, 76, 81, 85]}, // A
  {grund: 47, flaeche: [59, 62, 66], arp: [71, 74, 78, 83]}, // h
  {grund: 43, flaeche: [59, 62, 67], arp: [71, 74, 79, 83]}, // G
];
const akkordBei = (t) => AKKORDE[Math.floor(t / TAKT) % 4];

/* ---------------------------------------------------------- Instrumente */

const bassdrum = new Spur();
const schlag = new Spur();
const bass = new Spur();
const flaeche = new Spur();
const zupf = new Spur();
const glocke = new Spur();
const effekte = new Spur();
const hall = new Spur();
const kicks = [];

function kick(t, staerke = 1) {
  kicks.push(t);
  const i0 = Math.round(t * SR);
  let phase = 0;
  const tp = new Biquad('tp', 3000);
  for (let k = 0; k < 0.5 * SR; k++) {
    const s = k / SR;
    const f = 46 + 120 * Math.exp(-s / 0.03);
    phase += (2 * Math.PI * f) / SR;
    const huelle = Math.min(1, s / 0.002) * Math.exp(-s / 0.2);
    const klick = tp.tick(rauschen()) * Math.exp(-s / 0.004) * 0.5;
    const wert = Math.tanh(1.6 * (Math.sin(phase) * huelle + klick)) * 0.6 * staerke;
    bassdrum.add(i0 + k, wert);
  }
}

function clap(t, staerke = 1) {
  const i0 = Math.round(t * SR);
  const bp = new Biquad('bp', 1400, 0.9);
  for (let k = 0; k < 0.35 * SR; k++) {
    const s = k / SR;
    let huelle = 0;
    for (const v of [0, 0.011, 0.022]) if (s >= v) huelle = Math.max(huelle, Math.exp(-(s - v) / 0.006));
    if (s >= 0.022) huelle = Math.max(huelle, 0.55 * Math.exp(-(s - 0.022) / 0.11));
    const wert = bp.tick(rauschen()) * huelle * 1.3 * staerke;
    schlag.add(i0 + k, wert, (zufall() - 0.5) * 0.3);
    hall.add(i0 + k, wert * 0.35);
  }
}

function hihat(t, offen = false, staerke = 1, pan = 0) {
  const i0 = Math.round(t * SR);
  const hp = new Biquad('hp', 7200, 0.8);
  const laenge = offen ? 0.3 : 0.06;
  for (let k = 0; k < laenge * SR; k++) {
    const s = k / SR;
    const huelle = Math.exp(-s / (offen ? 0.13 : 0.025));
    schlag.add(i0 + k, hp.tick(rauschen()) * huelle * 0.38 * staerke, pan);
  }
}

function bassNote(t, midi, laenge) {
  const i0 = Math.round(t * SR);
  const f = mtof(midi);
  const dt = f / SR;
  let ph = 0;
  let ph2 = 0;
  const tp = new Biquad('tp', 600, 1.1);
  for (let k = 0; k < (laenge + 0.03) * SR; k++) {
    const s = k / SR;
    ph = (ph + dt) % 1;
    ph2 = (ph2 + dt) % 1;
    if (k % 16 === 0) tp.setze(260 + 1500 * Math.exp(-s / 0.07), 1.1);
    const roh = 0.55 * Math.sin(2 * Math.PI * ph2) + 0.45 * saege(ph, dt);
    const huelle = Math.min(1, s / 0.004) * (s < laenge ? 1 - 0.3 * (s / laenge) : Math.max(0, 1 - (s - laenge) / 0.03));
    bass.add(i0 + k, tp.tick(roh) * huelle * 1.09);
  }
}

/** Fläche: je Ton drei leicht verstimmte Sägezähne, weich gefiltert. */
function flaechenAkkord(t, laenge, toene, filter = () => 1900, staerke = 1) {
  const i0 = Math.round(t * SR);
  toene.forEach((m, n) => {
    [-7, 0, 7].forEach((cent, v) => {
      const f = mtof(m) * Math.pow(2, cent / 1200);
      const dt = f / SR;
      let ph = zufall();
      const tp = new Biquad('tp', 1900, 0.6);
      const pan = (v - 1) * 0.55;
      for (let k = 0; k < (laenge + 0.6) * SR; k++) {
        const s = k / SR;
        ph = (ph + dt) % 1;
        if (k % 64 === 0) tp.setze(filter(t + s), 0.6);
        const huelle = Math.min(1, s / 0.35) * (s < laenge ? 1 : Math.max(0, 1 - (s - laenge) / 0.6));
        const wert = tp.tick(saege(ph, dt)) * huelle * 0.061 * staerke;
        flaeche.add(i0 + k, wert, pan + (n - 1) * 0.1);
        hall.add(i0 + k, wert * 0.45);
      }
    });
  });
}

function zupfNote(t, midi, staerke, pan, filterOffen = 1) {
  const i0 = Math.round(t * SR);
  const f = mtof(midi);
  const dt = f / SR;
  const dt2 = (f * 1.006) / SR;
  let a = 0;
  let b = 0.3;
  const tp = new Biquad('tp', 3000, 0.9);
  for (let k = 0; k < 0.45 * SR; k++) {
    const s = k / SR;
    a = (a + dt) % 1;
    b = (b + dt2) % 1;
    if (k % 32 === 0) tp.setze((500 + 4200 * Math.exp(-s / 0.045)) * filterOffen, 0.9);
    const huelle = Math.min(1, s / 0.002) * Math.exp(-s / 0.16);
    const wert = tp.tick(0.5 * saege(a, dt) + 0.5 * saege(b, dt2)) * huelle * 0.39 * staerke;
    zupf.add(i0 + k, wert, pan);
    hall.add(i0 + k, wert * 0.25);
  }
}

/** Glocke: FM, Träger zu Modulator 1 : 3,5, der Index klingt aus. */
function glockenNote(t, midi, staerke = 1) {
  const i0 = Math.round(t * SR);
  const f = mtof(midi);
  for (let k = 0; k < 1.6 * SR; k++) {
    const s = k / SR;
    const index = 2.2 * Math.exp(-s / 0.25);
    const mod = Math.sin(2 * Math.PI * f * 3.5 * s) * index;
    const huelle = Math.min(1, s / 0.003) * Math.exp(-s / 0.55);
    const wert = Math.sin(2 * Math.PI * f * s + mod) * huelle * 0.17 * staerke;
    glocke.add(i0 + k, wert, 0.15);
    hall.add(i0 + k, wert * 0.5);
  }
}

function steigerung(von, bis, staerke = 1) {
  const i0 = Math.round(von * SR);
  const bp = new Biquad('bp', 300, 1.2);
  const n = Math.round((bis - von) * SR);
  for (let k = 0; k < n; k++) {
    const x = k / n;
    if (k % 32 === 0) bp.setze(300 * Math.pow(20, x), 1.2);
    const wert = bp.tick(rauschen()) * x * x * 0.5 * staerke;
    effekte.add(i0 + k, wert, Math.sin(x * Math.PI * 3) * 0.4);
    hall.add(i0 + k, wert * 0.3);
  }
}

function einschlag(t) {
  const i0 = Math.round(t * SR);
  const hp = new Biquad('hp', 3500, 0.7);
  for (let k = 0; k < 2.2 * SR; k++) {
    const s = k / SR;
    const tief = Math.sin(2 * Math.PI * (38 + 30 * Math.exp(-s / 0.08)) * s) * Math.exp(-s / 0.7) * 0.4;
    const becken = hp.tick(rauschen()) * Math.exp(-s / 0.6) * 0.16;
    effekte.add(i0 + k, tief);
    effekte.add(i0 + k, becken, (zufall() - 0.5) * 0.6);
    hall.add(i0 + k, becken * 0.6);
  }
}

/* ----------------------------------------------------------- Arrangement */

const ARP = [0, 1, 2, 1, 3, 2, 1, 2, 0, 1, 2, 3, 2, 1, 3, 2];
const MOTIV = [
  [[0, 81], [1.5, 78], [3, 76]],
  [[0, 76], [1.5, 73], [2.5, 76], [3, 81]],
  [[0, 78], [1.5, 74], [3, 73]],
  [[0, 74], [1.5, 71], [2.5, 74], [3, 79]],
];

// Fläche: ganze Zeit, im Intro öffnet sich das Filter.
for (let takt = 0; takt * TAKT < ENDE; takt++) {
  const t = takt * TAKT;
  const a = akkordBei(t);
  const filter = (zeit) => (zeit < 4 ? 350 + 1550 * Math.pow(zeit / 4, 2) : 1900);
  flaechenAkkord(t, TAKT, a.flaeche, filter, t < 4 ? 0.9 : 1);
}

// Arpeggio in Sechzehnteln; im Intro gedämpft.
for (let s = 0; s * SECHZEHNTEL < ENDE; s++) {
  const t = s * SECHZEHNTEL;
  const a = akkordBei(t);
  const schritt = s % 16;
  const betont = schritt % 4 === 0 ? 1 : 0.7;
  const offen = t < 4 ? 0.25 + 0.75 * (t / 4) : 1;
  zupfNote(t, a.arp[ARP[schritt]], betont * (t < 4 ? 0.8 : 1), schritt % 2 ? 0.35 : -0.35, offen);
}

// Hi-Hats leise ab 2 s, Steigerung ins Logo.
for (let s = 4; s < 8; s++) hihat(s * SCHLAG + SCHLAG / 2, false, 0.6, 0.2);
steigerung(2, 4);
einschlag(4);

const groove = (von, bis, mitOffen) => {
  for (let b = Math.round(von / SCHLAG); b * SCHLAG < bis - 1e-6; b++) {
    const t = b * SCHLAG;
    kick(t);
    if (b % 2 === 1) clap(t);
    hihat(t + SCHLAG / 2, mitOffen, mitOffen ? 0.8 : 1, 0.15);
    hihat(t + SCHLAG / 4, false, 0.45, -0.25);
    hihat(t + (3 * SCHLAG) / 4, false, 0.45, -0.25);
  }
  for (let takt = Math.round(von / TAKT); takt * TAKT < bis - 1e-6; takt++) {
    const t0 = takt * TAKT;
    const g = akkordBei(t0).grund;
    // Bass auf den Offbeats, der letzte springt eine Oktave hoch.
    [0.5, 1.5, 2.5, 3.5].forEach((schlagNr, n) => bassNote(t0 + schlagNr * SCHLAG, n === 3 ? g + 12 : g, SCHLAG * 0.42));
    bassNote(t0, g, SCHLAG * 0.3);
  }
};

groove(4, BRUCH, false);
// Ein halber Takt Luft – ohne Bassdrum, nur Clap und Steigerung in den zweiten Teil.
for (let s = 0; s < 4; s++) clap(BRUCH + s * SECHZEHNTEL * 2, 0.4 + s * 0.12);
steigerung(BRUCH, BRUCH + 1, 0.7);
groove(BRUCH + 1, SCHLUSS - 2, true);
for (let takt = Math.ceil((BRUCH + 1) / TAKT); takt * TAKT < SCHLUSS - 2; takt++) {
  const t0 = takt * TAKT;
  for (const [schlagNr, midi] of MOTIV[takt % 4]) glockenNote(t0 + schlagNr * SCHLAG, midi);
}

// Wirbel und Steigerung in den Schluss.
groove(SCHLUSS - 2, SCHLUSS - 1, true);
for (let s = 0; s < 8; s++) clap(SCHLUSS - 1 + s * SECHZEHNTEL, 0.35 + s * 0.09);
for (let b = (SCHLUSS - 1) / SCHLAG; b < SCHLUSS / SCHLAG; b++) kick(b * SCHLAG, 0.9);
steigerung(SCHLUSS - 2, SCHLUSS, 1.2);
einschlag(SCHLUSS);
groove(SCHLUSS, ENDE, true);
for (let takt = SCHLUSS / TAKT; takt * TAKT < ENDE; takt++) {
  for (const [schlagNr, midi] of MOTIV[takt % 4]) glockenNote(takt * TAKT + schlagNr * SCHLAG, midi);
}

// Schlussakkord.
kick(ENDE);
einschlag(ENDE);
flaechenAkkord(ENDE, 0.9, [62, 66, 69, 74], () => 2400, 1.3);
for (const m of [74, 78, 81, 86]) zupfNote(ENDE, m, 1.1, 0);
glockenNote(ENDE, 81, 1.2);
bassNote(ENDE, 38, 0.8);

/* ----------------------------------------------------------- Mischung */

/* Die Bassdrum duckt Bass, Fläche und Arpeggio – das macht den Groove. */
const ducken = new Float32Array(N).fill(1);
kicks.sort((a, b) => a - b);
for (const t of kicks) {
  const i0 = Math.round(t * SR);
  for (let k = 0; k < 0.45 * SR && i0 + k < N; k++) {
    const g = 1 - 0.6 * Math.exp(-(k / SR) / 0.11);
    ducken[i0 + k] = Math.min(ducken[i0 + k], g);
  }
}

/* Ping-Pong-Echo auf dem Arpeggio, punktierte Achtel: links hinein, jede
   Wiederholung springt auf die andere Seite. */
function echo(spur, zeit, rueck, anteil) {
  const d = Math.round(zeit * SR);
  const linieL = new Float32Array(N);
  const linieR = new Float32Array(N);
  for (let i = 0; i < N; i++) {
    const ausL = i >= d ? linieL[i - d] : 0;
    const ausR = i >= d ? linieR[i - d] : 0;
    linieL[i] = (spur.l[i] + spur.r[i]) * 0.5 + ausR * rueck;
    linieR[i] = ausL * rueck;
    spur.l[i] += ausL * anteil;
    spur.r[i] += ausR * anteil;
  }
}
echo(zupf, SCHLAG * 0.75, 0.38, 0.32);

/* Hall nach Freeverb (Jezar): acht Kammfilter, vier Allpässe je Kanal. */
function freeverb(eingang, raum = 0.84, daempfung = 0.3) {
  const kamm = [1116, 1188, 1277, 1356, 1422, 1491, 1557, 1617];
  const allpass = [556, 441, 341, 225];
  const kanal = (x, versatz) => {
    const aus = new Float32Array(N);
    const puffer = kamm.map((n) => ({b: new Float32Array(n + versatz), i: 0, f: 0}));
    const ap = allpass.map((n) => ({b: new Float32Array(n + versatz), i: 0}));
    for (let i = 0; i < N; i++) {
      const ein = x[i] * 0.015;
      let s = 0;
      for (const c of puffer) {
        const y = c.b[c.i];
        c.f = y * (1 - daempfung) + c.f * daempfung;
        c.b[c.i] = ein + c.f * raum;
        c.i = (c.i + 1) % c.b.length;
        s += y;
      }
      for (const a of ap) {
        const y = a.b[a.i];
        a.b[a.i] = s + y * 0.5;
        a.i = (a.i + 1) % a.b.length;
        s = y - s;
      }
      aus[i] = s;
    }
    return aus;
  };
  const mono = new Float32Array(N);
  for (let i = 0; i < N; i++) mono[i] = (eingang.l[i] + eingang.r[i]) * 0.5;
  return {l: kanal(mono, 0), r: kanal(mono, 23)};
}
const halle = freeverb(hall);

const L = new Float32Array(N);
const R = new Float32Array(N);
for (let i = 0; i < N; i++) {
  const d = ducken[i];
  L[i] = bassdrum.l[i] * 0.9 + schlag.l[i] + bass.l[i] * d + flaeche.l[i] * (0.4 + 0.6 * d) + zupf.l[i] * (0.5 + 0.5 * d) + glocke.l[i] + effekte.l[i] + halle.l[i] * 0.9;
  R[i] = bassdrum.r[i] * 0.9 + schlag.r[i] + bass.r[i] * d + flaeche.r[i] * (0.4 + 0.6 * d) + zupf.r[i] * (0.5 + 0.5 * d) + glocke.r[i] + effekte.r[i] + halle.r[i] * 0.9;
}

/* Pegel je Spur und vor dem Mastern – Musik prüft man hier mit Zahlen. */
const dbfs = (l, r, von = 4, bis = SCHLUSS - 2) => {
  let s = 0;
  let m = 0;
  for (let i = Math.round(von * SR); i < Math.round(bis * SR); i++) {
    s += l[i] * l[i] + r[i] * r[i];
    m = Math.max(m, Math.abs(l[i]), Math.abs(r[i]));
  }
  return `RMS ${(10 * Math.log10(s / (2 * (bis - von) * SR) + 1e-12)).toFixed(1)}  Spitze ${(20 * Math.log10(m + 1e-12)).toFixed(1)}`;
};
if (process.env.PEGEL) {
  for (const [name, sp] of Object.entries({bassdrum, schlag, bass, flaeche, zupf, glocke, effekte})) console.log(name.padEnd(9), dbfs(sp.l, sp.r));
  console.log('hall'.padEnd(9), dbfs(halle.l, halle.r));
  console.log('summe'.padEnd(9), dbfs(L, R));
}

/* Ein- und Ausblenden, sanft sättigen, auf −1 dBFS bringen. */
function meistern(l, r, {einblenden = 0, ausblenden = 0, antrieb = 1.4, spitze = 0.89} = {}) {
  const n = l.length;
  for (let i = 0; i < n; i++) {
    const t = i / SR;
    let g = 1;
    if (einblenden > 0 && t < einblenden) g *= t / einblenden;
    if (ausblenden > 0 && t > n / SR - ausblenden) g *= Math.max(0, (n / SR - t) / ausblenden);
    l[i] = Math.tanh(l[i] * antrieb) * g;
    r[i] = Math.tanh(r[i] * antrieb) * g;
  }
  let m = 0;
  for (let i = 0; i < n; i++) m = Math.max(m, Math.abs(l[i]), Math.abs(r[i]));
  const k = m > 0 ? spitze / m : 1;
  let summe = 0;
  for (let i = 0; i < n; i++) {
    l[i] *= k;
    r[i] *= k;
    summe += l[i] * l[i] + r[i] * r[i];
  }
  return 20 * Math.log10(Math.sqrt(summe / (2 * n)));
}

function wav(datei, l, r) {
  const n = l.length;
  const puffer = Buffer.alloc(44 + n * 4);
  puffer.write('RIFF', 0);
  puffer.writeUInt32LE(36 + n * 4, 4);
  puffer.write('WAVEfmt ', 8);
  puffer.writeUInt32LE(16, 16);
  puffer.writeUInt16LE(1, 20);
  puffer.writeUInt16LE(2, 22);
  puffer.writeUInt32LE(SR, 24);
  puffer.writeUInt32LE(SR * 4, 28);
  puffer.writeUInt16LE(4, 32);
  puffer.writeUInt16LE(16, 34);
  puffer.write('data', 36);
  puffer.writeUInt32LE(n * 4, 40);
  for (let i = 0; i < n; i++) {
    // Dreieck-Dither gegen Quantisierungsrauschen in den leisen Stellen.
    const d = (zufall() - zufall()) / 32768;
    puffer.writeInt16LE(Math.max(-32768, Math.min(32767, Math.round((l[i] + d) * 32767))), 44 + i * 4);
    puffer.writeInt16LE(Math.max(-32768, Math.min(32767, Math.round((r[i] + d) * 32767))), 46 + i * 4);
  }
  fs.mkdirSync(path.dirname(datei), {recursive: true});
  fs.writeFileSync(datei, puffer);
}

const rms = meistern(L, R, {einblenden: 0.05, ausblenden: 0.7, antrieb: 1.0});
wav(path.join(ziel, 'musik-lang.wav'), L, R);
console.log(`musik-lang.wav  ${DAUER} s, RMS ${rms.toFixed(1)} dBFS`);

/* ------------------------------------------------------------ Geräusche */

{
  const n = Math.round(0.09 * SR);
  const l = new Float32Array(n);
  const r = new Float32Array(n);
  const hp = new Biquad('hp', 2500);
  let ph = 0;
  for (let k = 0; k < n; k++) {
    const s = k / SR;
    ph += (2 * Math.PI * (1500 - 600 * Math.min(1, s / 0.02))) / SR;
    const wert = Math.sin(ph) * Math.exp(-s / 0.014) * 0.6 + hp.tick(rauschen()) * Math.exp(-s / 0.002) * 0.4;
    l[k] = wert;
    r[k] = wert;
  }
  meistern(l, r, {antrieb: 1, spitze: 0.5});
  wav(path.join(ziel, 'tipp.wav'), l, r);
  console.log('tipp.wav');
}
{
  const n = Math.round(0.5 * SR);
  const l = new Float32Array(n);
  const r = new Float32Array(n);
  const bp = new Biquad('bp', 500, 1.4);
  for (let k = 0; k < n; k++) {
    const x = k / n;
    if (k % 32 === 0) bp.setze(450 + 3200 * Math.sin(x * Math.PI * 0.85), 1.4);
    const wert = bp.tick(rauschen()) * Math.pow(Math.sin(x * Math.PI), 2);
    l[k] = wert * (1 - x * 0.6);
    r[k] = wert * (0.4 + x * 0.6);
  }
  meistern(l, r, {antrieb: 1, spitze: 0.45});
  wav(path.join(ziel, 'wisch.wav'), l, r);
  console.log('wisch.wav');
}
