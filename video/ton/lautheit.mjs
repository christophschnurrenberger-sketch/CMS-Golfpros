/**
 * Bringt die Tonspur fertiger Videos auf die Lautheit der Plattformen.
 *
 *   node ton/lautheit.mjs out/a.mp4 [out/b.mp4 …]
 *   node ton/lautheit.mjs --ziel -16 out/a.mp4      andere Ziel-Lautheit (LUFS)
 *
 * Mit Sprecher liegt der Mix bei etwa −19 LUFS – YouTube, Instagram und
 * TikTok spielen um −14 LUFS ab, das Video wäre dort leiser als alles drum
 * herum. Einfach lauter drehen geht nicht: Die Spitzen der Stimme (Plosive)
 * kämen über 0 dB. Darum: gleichmäßige Verstärkung auf −14 LUFS (gemessen
 * nach EBU R128 mit ffmpegs loudnorm) und ein schneller Begrenzer mit
 * Vorausschau, der nur die wenigen Spitzen auf −1,5 dBFS fängt. Anders als
 * loudnorm im dynamischen Modus regelt das nicht über Sekunden nach – die
 * Musik bleibt unter der Stimme so tief, wie Werbevideo2 sie legt.
 *
 * Das Bild wird nur kopiert, der Ton neu als AAC in der bisherigen Bitrate
 * geschrieben, die Datei an Ort und Stelle ersetzt. Zweimal laufen lassen
 * schadet nicht – beim zweiten Mal ist die Verstärkung null.
 */
import {spawnSync} from 'node:child_process';
import fs from 'node:fs';

const RATE = 48000;
const VORAUS = Math.round(RATE * 0.002); // 2 ms Vorausschau
const LOSLASSEN = 1 - Math.exp(-1 / (RATE * 0.15)); // 150 ms Rückkehr – weich wie ein Kompressor, pumpt nicht

const args = process.argv.slice(2);
const i = args.indexOf('--ziel');
const ziel = i >= 0 ? Number(args.splice(i, 2)[1]) : -14;
const decke = 10 ** (-1.5 / 20);
const dateien = args;
if (!dateien.length) {
  console.error('Aufruf: node ton/lautheit.mjs [--ziel -14] out/video.mp4 …');
  process.exit(1);
}

/* Remotion bringt ffmpeg mit – für jede Plattform das passende. */
function werkzeug(name, argumente, eingabe) {
  const r = spawnSync('npx', ['remotion', name, ...argumente], {input: eingabe, maxBuffer: 1 << 30});
  if (r.status !== 0) throw new Error(`${name} ${argumente.join(' ')}\n${r.stderr.toString().slice(-2000)}`);
  return r;
}

/** Lautheit (LUFS) und echte Spitze (dBTP) einer Datei – oder, mit `ton`, eines Puffers im Speicher. */
function messen(datei, ton) {
  const quelle = ton ? ['-f', 'f32le', '-ar', String(RATE), '-ac', '2', '-i', 'pipe:0'] : ['-i', datei];
  const r = werkzeug('ffmpeg', ['-hide_banner', '-nostats', ...quelle, '-vn', '-af', 'loudnorm=print_format=json', '-f', 'null', '-'], ton && Buffer.from(ton.buffer));
  const text = r.stderr.toString();
  const json = JSON.parse(text.slice(text.lastIndexOf('{'), text.lastIndexOf('}') + 1));
  return {lufs: Number(json.input_i), spitze: Number(json.input_tp)};
}

function bitrate(datei) {
  const r = werkzeug('ffprobe', ['-v', 'error', '-select_streams', 'a:0', '-show_entries', 'stream=bit_rate', '-of', 'csv=p=0', datei]);
  const bps = Number(r.stdout.toString().trim());
  return [128, 192, 256, 320].reduce((a, b) => (Math.abs(b * 1000 - bps) < Math.abs(a * 1000 - bps) ? b : a));
}

/* Die mitgelieferte ffmpeg-Fassung schreibt kein rohes Float, aber WAV in 24 Bit – das reicht. */
function lesen(datei) {
  const wav = werkzeug('ffmpeg', ['-v', 'error', '-i', datei, '-vn', '-ac', '2', '-ar', String(RATE), '-c:a', 'pcm_s24le', '-f', 'wav', 'pipe:1']).stdout;
  let o = 12;
  while (wav.toString('ascii', o, o + 4) !== 'data') o += 8 + wav.readUInt32LE(o + 4);
  o += 8; // Über eine Pipe steht in der Größe nichts Verlässliches – bis zum Ende lesen.
  const n = Math.floor((wav.length - o) / 3);
  const ton = new Float32Array(n);
  for (let k = 0; k < n; k++) ton[k] = wav.readIntLE(o + 3 * k, 3) / 8388608;
  return ton;
}

/** Gleichmäßig verstärken, Spitzen mit Vorausschau begrenzen. Liefert die größte Absenkung in dB. */
function begrenzen(ton, verstaerkung) {
  const n = ton.length / 2;
  /* Nötige Absenkung je Sample, damit es unter der Decke bleibt. */
  const noetig = new Float32Array(n + VORAUS);
  for (let k = 0; k < n; k++) {
    const p = Math.max(Math.abs(ton[2 * k]), Math.abs(ton[2 * k + 1])) * verstaerkung;
    noetig[k] = p > decke ? decke / p : 1;
  }
  noetig.fill(1, n);
  /* Minimum über die nächsten VORAUS Samples (monotone Warteschlange) … */
  const minimum = new Float32Array(n);
  const schlange = new Int32Array(n + VORAUS);
  let kopf = 0;
  let ende = 0;
  for (let k = 0; k < n + VORAUS; k++) {
    while (ende > kopf && noetig[schlange[ende - 1]] >= noetig[k]) ende--;
    schlange[ende++] = k;
    const start = k - VORAUS;
    if (start >= 0) {
      while (schlange[kopf] < start) kopf++;
      minimum[start] = noetig[schlange[kopf]];
    }
  }
  /* … über VORAUS Samples gemittelt: weicher Einsatz, der nie zu spät kommt … */
  let summe = 0;
  let tiefste = 1;
  let huelle = 1;
  for (let k = 0; k < n; k++) {
    summe += minimum[k] - (k >= VORAUS ? minimum[k - VORAUS] : 1);
    const soll = (summe + VORAUS) / VORAUS;
    /* … und langsam zurück. */
    huelle = soll < huelle ? soll : huelle + (soll - huelle) * LOSLASSEN;
    const g = verstaerkung * huelle;
    ton[2 * k] *= g;
    ton[2 * k + 1] *= g;
    tiefste = Math.min(tiefste, huelle);
  }
  return -20 * Math.log10(tiefste);
}

for (const datei of dateien) {
  const vorher = messen(datei);
  const original = lesen(datei);
  /* Der Begrenzer nimmt ein wenig Lautheit weg – nachmessen und die Verstärkung nachziehen, bis es passt. */
  let verstaerkung = ziel - vorher.lufs;
  let ton;
  let absenkung;
  for (let runde = 0; runde < 4; runde++) {
    ton = original.slice();
    absenkung = begrenzen(ton, 10 ** (verstaerkung / 20));
    const abweichung = ziel - messen(null, ton).lufs;
    if (Math.abs(abweichung) < 0.15) break;
    verstaerkung += abweichung;
  }

  const neu = datei.replace(/\.mp4$/, '.lautheit.mp4');
  werkzeug(
    'ffmpeg',
    ['-v', 'error', '-y', '-i', datei, '-f', 'f32le', '-ar', String(RATE), '-ac', '2', '-i', 'pipe:0',
      '-map', '0:v', '-map', '1:a', '-c:v', 'copy', '-c:a', 'aac', '-b:a', `${bitrate(datei)}k`, '-movflags', '+faststart', neu],
    Buffer.from(ton.buffer),
  );
  fs.renameSync(neu, datei);
  const nachher = messen(datei);
  console.log(
    `${datei}: ${vorher.lufs.toFixed(1)} → ${nachher.lufs.toFixed(1)} LUFS, Spitze ${vorher.spitze.toFixed(1)} → ${nachher.spitze.toFixed(1)} dBTP ` +
      `(${verstaerkung >= 0 ? '+' : ''}${verstaerkung.toFixed(1)} dB, Begrenzer höchstens −${absenkung.toFixed(1)} dB)`,
  );
}
