/**
 * Die Sprecherstimme der langen Fassung – Satz für Satz, auf die Szenen gelegt.
 *
 *   node ton/sprecher.mjs                 Stimme erzeugen (Piper), prüfen, Untertitel schreiben
 *   node ton/sprecher.mjs --tempo 0.95    etwas schneller sprechen (Piper: length_scale)
 *   node ton/sprecher.mjs --takes 6       je Satz sechs Takes, der deutlichste gewinnt
 *
 * Woher die Stimme kommt, je Satz:
 *   1. Liegt public/ton/sprecher/eigene/NN-szene.wav, wird diese Aufnahme
 *      genommen – so lässt sich das Video mit einer echten Stimme vertonen
 *      (Handy-Aufnahme reicht, als WAV).
 *   2. Sonst spricht Piper mit dem Modell aus --modell bzw. PIPER_MODELL
 *      (Standard: ton/stimmen/de_DE-thorsten-high.onnx – siehe README).
 *
 * Piper klingt bei jedem Durchlauf etwas anders, und mal gerät ein Wort
 * undeutlich. Mit --takes N spricht es jeden Satz N-mal; ton/abhoeren.py
 * schreibt mit Whisper auf, was zu verstehen ist, und genommen wird der Take,
 * dessen Abschrift dem Text am nächsten kommt (Modell: --asr bzw.
 * WHISPER_MODELL, Standard ton/stimmen/sherpa-onnx-whisper-small).
 *
 * Der Text steht in src/sprechertext.json, der Ablauf in src/ablauf-lang.json –
 * dieselbe Datei, aus der das Video seine Szenen nimmt. Jeder Satz beginnt
 * `versatz` Frames nach seiner Szene und muss vor der nächsten fertig sein;
 * passt einer nicht, wird Piper bis 15 % schneller, danach bricht das Skript
 * mit dem Satz ab, der zu lang ist.
 *
 * Ergebnis: public/ton/sprecher/*.wav, src/sprecher.json (Lage in Frames) und
 * out/teepilot-untertitel.srt / .vtt für YouTube und die Website.
 */
import {execFileSync} from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';

const FPS = 30;
const UEBERGANG = 15;
/* So weit darf ein Satz in den Übergang zur nächsten Szene hineinreichen. */
const NACHLAUF = 6;

const wurzel = path.join(path.dirname(fileURLToPath(import.meta.url)), '..');
const arg = (name, standard) => {
  const i = process.argv.indexOf(name);
  return i > 0 ? process.argv[i + 1] : standard;
};
const modell = arg('--modell', process.env.PIPER_MODELL ?? path.join(wurzel, 'ton', 'stimmen', 'de_DE-thorsten-high.onnx'));
const tempo = Number(arg('--tempo', '1'));
const takes = Math.max(1, Number(arg('--takes', '1')));
const asr = arg('--asr', process.env.WHISPER_MODELL ?? path.join(wurzel, 'ton', 'stimmen', 'sherpa-onnx-whisper-small'));
const python = process.env.PYTHON ?? 'python3';

const ablauf = JSON.parse(fs.readFileSync(path.join(wurzel, 'src', 'ablauf-lang.json'), 'utf8'));
const {saetze} = JSON.parse(fs.readFileSync(path.join(wurzel, 'src', 'sprechertext.json'), 'utf8'));

/* Startframes wie in src/Werbevideo2.tsx: Übergänge überlappen, harte Schnitte nicht. */
const starts = [];
ablauf.forEach((s, i) => starts.push(i === 0 ? 0 : starts[i - 1] + ablauf[i - 1].dauer - (s.zu === 'schnitt' ? 0 : UEBERGANG)));
const gesamt = starts.at(-1) + ablauf.at(-1).dauer;

/** Dauer einer WAV-Datei in Sekunden, aus dem RIFF-Kopf. */
function wavDauer(datei) {
  const b = fs.readFileSync(datei);
  if (b.toString('ascii', 0, 4) !== 'RIFF' || b.toString('ascii', 8, 12) !== 'WAVE') {
    throw new Error(`${datei} ist keine WAV-Datei.`);
  }
  let o = 12;
  let byteRate = 0;
  while (o + 8 <= b.length) {
    const id = b.toString('ascii', o, o + 4);
    const groesse = b.readUInt32LE(o + 4);
    if (id === 'fmt ') byteRate = b.readUInt32LE(o + 16);
    if (id === 'data') return groesse / byteRate;
    o += 8 + groesse + (groesse % 2);
  }
  throw new Error(`${datei}: kein Audio gefunden.`);
}

function piper(text, ziel, lengthScale) {
  if (!fs.existsSync(modell)) {
    throw new Error(
      `Stimme fehlt: ${modell}\n` +
        'Piper-Stimme herunterladen (siehe README, Abschnitt „Sprecher“) oder eigene Aufnahmen unter public/ton/sprecher/eigene/ ablegen.',
    );
  }
  execFileSync(python, ['-m', 'piper', '-m', modell, '-f', ziel, '--length-scale', String(lengthScale), '--sentence-silence', '0.18'], {
    input: text,
    stdio: ['pipe', 'ignore', 'pipe'],
  });
}

const ordner = path.join(wurzel, 'public', 'ton', 'sprecher');
const eigene = path.join(ordner, 'eigene');
fs.mkdirSync(eigene, {recursive: true});

/** Text zum Vergleichen: klein, nur Buchstaben und Ziffern, den Markennamen einheitlich. */
const vergleichbar = (t) =>
  t
    .toLowerCase()
    .replace(/\b(t|te|tee|ti|tie|tea)[\s-]*pilot/g, 'teepilot')
    .replace(/[^a-z0-9äöüß]/g, '');

/** Ähnlichkeit 0…1 über die Levenshtein-Distanz. */
function aehnlichkeit(a, b) {
  a = vergleichbar(a);
  b = vergleichbar(b);
  if (!a.length || !b.length) return 0;
  let zeile = Array.from({length: b.length + 1}, (_, j) => j);
  for (let i = 1; i <= a.length; i++) {
    const neu = [i];
    for (let j = 1; j <= b.length; j++) {
      neu[j] = Math.min(zeile[j] + 1, neu[j - 1] + 1, zeile[j - 1] + (a[i - 1] === b[j - 1] ? 0 : 1));
    }
    zeile = neu;
  }
  return 1 - zeile[b.length] / Math.max(a.length, b.length);
}

/** Whisper-Abschrift je Datei – ein Aufruf für alle, das Modell lädt nur einmal. */
function abhoeren(dateien) {
  for (const f of ['small-encoder.int8.onnx', 'small-decoder.int8.onnx', 'small-tokens.txt']) {
    if (!fs.existsSync(path.join(asr, f))) {
      throw new Error(`Für --takes fehlt das Whisper-Modell: ${path.join(asr, f)}\nDownload siehe README, Abschnitt „Sprecher“.`);
    }
  }
  const aus = execFileSync(python, [path.join(wurzel, 'ton', 'abhoeren.py'), asr, ...dateien], {
    encoding: 'utf8',
    stdio: ['ignore', 'pipe', 'pipe'],
    maxBuffer: 16 * 1024 * 1024,
  });
  return new Map(
    aus
      .split('\n')
      .filter((z) => z.startsWith('{'))
      .map((z) => JSON.parse(z))
      .map((z) => [z.datei, z.text]),
  );
}

/* Erst alle Takes sprechen, dann alle auf einmal abhören, dann je Satz wählen. */
const plan = saetze.map((satz, n) => {
  const i = ablauf.findIndex((s) => s.name === satz.szene);
  if (i < 0) throw new Error(`Szene „${satz.szene}“ gibt es in src/ablauf-lang.json nicht.`);
  const name = `${String(n + 1).padStart(2, '0')}-${satz.szene}.wav`;
  const von = starts[i] + (satz.versatz ?? 10);
  const frist = i + 1 < ablauf.length ? starts[i + 1] + NACHLAUF : gesamt - 10;
  const passt = (dauer) => von + Math.ceil(dauer * FPS) <= frist;

  const eigen = path.join(eigene, name);
  if (fs.existsSync(eigen)) {
    return {satz, name, von, frist, datei: eigen, quelle: 'eigene Aufnahme', kandidaten: [{datei: eigen, dauer: wavDauer(eigen)}]};
  }
  const kandidaten = [];
  for (let k = 1; k <= takes; k++) {
    const datei = path.join(ordner, takes > 1 ? name.replace(/\.wav$/, `.take${k}.wav`) : name);
    let dauer;
    for (const ls of [tempo, tempo * 0.95, tempo * 0.9, tempo * 0.85]) {
      piper(satz.sprechen ?? satz.text, datei, ls);
      dauer = wavDauer(datei);
      if (passt(dauer)) break;
    }
    kandidaten.push({datei, dauer});
  }
  return {satz, name, von, frist, datei: path.join(ordner, name), quelle: 'Piper', kandidaten, passt};
});

const abschrift = takes > 1 ? abhoeren(plan.filter((p) => p.quelle === 'Piper').flatMap((p) => p.kandidaten.map((k) => k.datei))) : new Map();

const lage = [];
const fehler = [];
for (const p of plan) {
  const {datei} = p;
  let {quelle} = p;
  let wahl = p.kandidaten[0];
  if (p.quelle === 'Piper' && takes > 1) {
    for (const k of p.kandidaten) {
      k.text = abschrift.get(k.datei) ?? '';
      k.wert = aehnlichkeit(k.text, p.satz.text);
    }
    /* Passt vor deutlich, deutlich vor kurz. */
    const rang = [...p.kandidaten].sort((a, b) => Number(p.passt(b.dauer)) - Number(p.passt(a.dauer)) || b.wert - a.wert || a.dauer - b.dauer);
    wahl = rang[0];
    fs.renameSync(wahl.datei, datei);
    for (const k of p.kandidaten) if (k !== wahl) fs.rmSync(k.datei, {force: true});
    quelle = `Piper, Take ${p.kandidaten.indexOf(wahl) + 1}/${takes}: „${wahl.text}“ ${Math.round(wahl.wert * 100)} %`;
  }
  const bis = p.von + Math.ceil(wahl.dauer * FPS);
  const platz = (p.frist - p.von) / FPS;
  console.log(`${p.name.padEnd(18)} ${wahl.dauer.toFixed(2)} s von ${platz.toFixed(2)} s  (${quelle})${bis > p.frist ? '  ← ZU LANG' : ''}`);
  if (bis > p.frist) fehler.push(`„${p.satz.text}“ braucht ${wahl.dauer.toFixed(2)} s, Platz ist ${platz.toFixed(2)} s.`);
  lage.push({szene: p.satz.szene, text: p.satz.text, datei: path.relative(path.join(wurzel, 'public'), datei).split(path.sep).join('/'), von: p.von, bis});
}

if (fehler.length) {
  console.error('\nZu lang – Satz kürzen oder eigene Aufnahme schneller sprechen:\n  ' + fehler.join('\n  '));
  process.exit(1);
}

fs.writeFileSync(path.join(wurzel, 'src', 'sprecher.json'), JSON.stringify(lage, null, 2) + '\n');

/* Untertitel: der Text, wie er gesprochen wird, auf die Zeit des Satzes. */
const zeit = (frames, trenner) => {
  const ms = Math.round((frames / FPS) * 1000);
  const h = Math.floor(ms / 3600000);
  const m = Math.floor((ms % 3600000) / 60000);
  const s = Math.floor((ms % 60000) / 1000);
  return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}${trenner}${String(ms % 1000).padStart(3, '0')}`;
};
const srt = lage.map((l, n) => `${n + 1}\n${zeit(l.von, ',')} --> ${zeit(l.bis, ',')}\n${l.text}\n`).join('\n');
const vtt = 'WEBVTT\n\n' + lage.map((l) => `${zeit(l.von, '.')} --> ${zeit(l.bis, '.')}\n${l.text}\n`).join('\n');
fs.mkdirSync(path.join(wurzel, 'out'), {recursive: true});
fs.writeFileSync(path.join(wurzel, 'out', 'teepilot-untertitel.srt'), srt);
fs.writeFileSync(path.join(wurzel, 'out', 'teepilot-untertitel.vtt'), vtt);
console.log(`\n${lage.length} Sätze → src/sprecher.json, out/teepilot-untertitel.srt und .vtt`);
