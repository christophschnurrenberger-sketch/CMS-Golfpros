/**
 * TeePilot – lange Fassung: 60 Sekunden, 1080×1920, mit Musik.
 *
 * Mehr Funktionen, jede am Schreibtisch und auf dem Telefon. Die Musik
 * (ton/musik.mjs, 120 BPM) ist für genau diesen Ablauf geschrieben: Alle
 * Szenenlängen und der Übergang sind Vielfache eines Schlags (15 Frames),
 * jeder Schnitt fällt auf einen Schlag. Auf dem Einschlag bei 4 s kommt mit
 * einem harten Schnitt Logo und Zielgruppe, auf dem bei 56 s der Schluss
 * (ton/musik.mjs: SCHLUSS).
 */
import React from 'react';
import {AbsoluteFill, Html5Audio, interpolate, Sequence, staticFile} from 'remotion';
import {linearTiming, springTiming, TransitionSeries} from '@remotion/transitions';
import {fade} from '@remotion/transitions/fade';
import {slide} from '@remotion/transitions/slide';
import {Buchung, Hook, Schluss} from './Szenen';
import {
  Auswertung2,
  Dashboard2,
  Kalender2,
  Kunde2,
  Leads2,
  Portal2,
  Rechnungen2,
  Reisen2,
  Training2,
  Website2,
  Zielgruppe,
} from './Szenen2';
import ablauf from './ablauf-lang.json';
import sprecherLage from './sprecher.json';
import {Hintergrund} from './teile/Hintergrund';
import {MitTon} from './teile/Ton';
import type {WerbevideoProps} from './Werbevideo';

export const SCHLAG = 15;

/* Was die lange Fassung zeigt – am Ende noch einmal als Liste. */
const BEREICHE2 = [
  'Online-Buchung',
  'Kalender',
  'Dashboard',
  'Kundenakte',
  'Training',
  'Leads',
  'Website',
  'Golfreisen',
  'Rechnungen',
  'Auswertung',
  'Kundenportal',
];
export const UEBERGANG2 = SCHLAG;

/* `schnitt` ist ein harter Schnitt ohne Überlappung – auf dem Einschlag der Musik. */
type Szene = {
  name: string;
  dauer: number;
  zu: 'blende' | 'wisch' | 'schnitt';
  element: (p: WerbevideoProps, dauer: number) => React.ReactNode;
};

/* Was in welcher Szene zu sehen ist. Längen und Übergänge stehen in
   src/ablauf-lang.json – dieselbe Datei liest ton/sprecher.mjs, damit
   Stimme und Bild nie auseinanderlaufen. */
const INHALT: Record<string, Szene['element']> = {
  hook: () => <Hook />,
  zielgruppe: () => <Zielgruppe />,
  buchung: () => <Buchung />,
  kalender: (_, d) => <Kalender2 dauer={d} />,
  dashboard: (_, d) => <Dashboard2 dauer={d} />,
  kunde: (_, d) => <Kunde2 dauer={d} />,
  training: (_, d) => <Training2 dauer={d} />,
  leads: (_, d) => <Leads2 dauer={d} />,
  website: (_, d) => <Website2 dauer={d} />,
  reisen: (_, d) => <Reisen2 dauer={d} />,
  rechnungen: (_, d) => <Rechnungen2 dauer={d} />,
  auswertung: (_, d) => <Auswertung2 dauer={d} />,
  portal: (_, d) => <Portal2 dauer={d} />,
  schluss: (p) => <Schluss {...p} desktop="dashboard-desk" bereiche={BEREICHE2} />,
};

/*
 * Jeder Bildschirm steht gut vier Sekunden (zwei Takte) – so lange, dass man
 * Überschrift und Screen in Ruhe lesen kann. `zu`: wie die Szene
 * hereinkommt (die erste ignoriert es).
 */
const SZENEN2: Szene[] = (ablauf as Array<Omit<Szene, 'element'>>).map((a) => {
  const element = INHALT[a.name];
  if (!element) throw new Error(`Szene „${a.name}“ aus ablauf-lang.json hat keinen Inhalt.`);
  return {...a, element};
});

const ueberlappung = (sz: Szene) => (sz.zu === 'schnitt' ? 0 : UEBERGANG2);

/** Startframe jeder Szene im fertigen Video. */
export const STARTS2 = SZENEN2.reduce<number[]>((s, sz, i) => [...s, i === 0 ? 0 : s[i - 1] + SZENEN2[i - 1].dauer - ueberlappung(sz)], []);

export const GESAMT2 = STARTS2[STARTS2.length - 1] + SZENEN2[SZENEN2.length - 1].dauer;

/* Sätze der Sprecherstimme, von ton/sprecher.mjs in Frames gelegt. Leer, solange keine Stimme erzeugt ist. */
type Satz = {szene: string; text: string; datei: string; von: number; bis: number};
const SAETZE = sprecherLage as Satz[];

/* Piper liefert die Sätze auf 0 dBFS normalisiert – etwas Luft, damit Stimme und Musik zusammen nicht übersteuern. */
const STIMME = 0.9;

/*
 * Musik: 85 %. Solange gesprochen wird, liegt sie als leises Bett darunter
 * (BETT) und geht unter jedem Satz noch tiefer (UNTER) – gut 20 dB unter der
 * Stimme, wie bei einem Sprechertext üblich; bei 25 % hat die
 * Spracherkennung in der zweiten Hälfte (FM-Glocke) schon Wörter verloren.
 * Zwischen den Sätzen (rund 2 s) hebt sie sich nur ein Stück, damit sie
 * nicht im Takt der Sätze pumpt; nach dem letzten Satz kommt sie ganz zurück.
 */
const BETT = 0.2;
const UNTER = 0.08;
const musikLautstaerke = (f: number, mitStimme: boolean) => {
  const grund = interpolate(f, [0, 6, GESAMT2 - 20, GESAMT2], [0, 0.85, 0.85, 0], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'});
  if (!mitStimme) return grund;
  const erster = SAETZE[0].von;
  const letzter = SAETZE[SAETZE.length - 1].bis;
  const bett = interpolate(f, [erster - 10, erster, letzter, letzter + 20], [1, BETT, BETT, 1], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'});
  const unter = SAETZE.reduce((tief, s) => {
    const t = interpolate(f, [s.von - 8, s.von, s.bis, s.bis + 20], [0, 1, 1, 0], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'});
    return Math.max(tief, t);
  }, 0);
  return grund * bett * (1 - (1 - UNTER / BETT) * unter);
};

export const Werbevideo2: React.FC<WerbevideoProps> = (props) => {
  const mitStimme = (props.stimme ?? true) && SAETZE.length > 0;
  return (
    <MitTon>
      <AbsoluteFill>
        <Hintergrund />
        <TransitionSeries>
          {SZENEN2.map((sz, i) => (
            <React.Fragment key={sz.name}>
              {i > 0 && sz.zu !== 'schnitt' ? (
                <TransitionSeries.Transition
                  presentation={sz.zu === 'wisch' ? slide({direction: 'from-bottom'}) : fade()}
                  timing={
                    sz.zu === 'wisch'
                      ? springTiming({config: {damping: 200}, durationInFrames: UEBERGANG2})
                      : linearTiming({durationInFrames: UEBERGANG2})
                  }
                />
              ) : null}
              <TransitionSeries.Sequence durationInFrames={sz.dauer}>{sz.element(props, sz.dauer)}</TransitionSeries.Sequence>
            </React.Fragment>
          ))}
        </TransitionSeries>

        <Html5Audio src={staticFile('ton/musik-lang.wav')} volume={(f) => musikLautstaerke(f, mitStimme)} />
        {mitStimme
          ? SAETZE.map((s) => (
              <Sequence key={s.datei} from={s.von} durationInFrames={s.bis - s.von + 15} layout="none">
                <Html5Audio src={staticFile(s.datei)} volume={STIMME} />
              </Sequence>
            ))
          : null}
        {SZENEN2.map((sz, i) =>
          i > 0 && sz.zu === 'wisch' ? (
            <Sequence key={'wisch' + sz.name} from={STARTS2[i] - 3} durationInFrames={18} layout="none">
              <Html5Audio src={staticFile('ton/wisch.wav')} volume={0.32} />
            </Sequence>
          ) : null,
        )}
      </AbsoluteFill>
    </MitTon>
  );
};
