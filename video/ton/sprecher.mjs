/**
 * Die Sprecherstimme der langen Fassung – Satz für Satz, auf die Szenen gelegt.
 *
 *   node ton/sprecher.mjs                 Stimme erzeugen (Piper), prüfen, Untertitel schreiben
 *   node ton/sprecher.mjs --tempo 0.95    etwas schneller sprechen (Piper: length_scale)
 *
 * Woher die Stimme kommt, je Satz:
 *   1. Liegt public/ton/sprecher/eigene/NN-szene.wav, wird diese Aufnahme
 *      genommen – so lässt sich das Video mit einer echten Stimme vertonen
 *      (Handy-Aufnahme reicht, als WAV).
 *   2. Sonst spricht Piper mit dem Modell aus --modell bzw. PIPER_MODELL
 *      (Standard: ton/stimmen/de_DE-thorsten-high.onnx – siehe README).
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

const lage = [];
const fehler = [];
saetze.forEach((satz, n) => {
  const i = ablauf.findIndex((s) => s.name === satz.szene);
  if (i < 0) throw new Error(`Szene „${satz.szene}“ gibt es in src/ablauf-lang.json nicht.`);
  const name = `${String(n + 1).padStart(2, '0')}-${satz.szene}.wav`;
  const von = starts[i] + (satz.versatz ?? 10);
  const frist = i + 1 < ablauf.length ? starts[i + 1] + NACHLAUF : gesamt - 10;

  let datei = path.join(eigene, name);
  let quelle = 'eigene Aufnahme';
  let dauer;
  if (fs.existsSync(datei)) {
    dauer = wavDauer(datei);
  } else {
    datei = path.join(ordner, name);
    quelle = 'Piper';
    for (const ls of [tempo, tempo * 0.95, tempo * 0.9, tempo * 0.85]) {
      piper(satz.sprechen ?? satz.text, datei, ls);
      dauer = wavDauer(datei);
      if (von + Math.ceil(dauer * FPS) <= frist) break;
    }
  }
  const bis = von + Math.ceil(dauer * FPS);
  const platz = (frist - von) / FPS;
  console.log(`${name.padEnd(18)} ${dauer.toFixed(2)} s von ${platz.toFixed(2)} s  (${quelle})${bis > frist ? '  ← ZU LANG' : ''}`);
  if (bis > frist) fehler.push(`„${satz.text}“ braucht ${dauer.toFixed(2)} s, Platz ist ${platz.toFixed(2)} s.`);
  lage.push({szene: satz.szene, text: satz.text, datei: path.relative(path.join(wurzel, 'public'), datei).split(path.sep).join('/'), von, bis});
});

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
