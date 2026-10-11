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

/** Musik: 85 %, unter der Stimme auf ein Viertel (etwa −13 dB) – mit weichen Rampen. */
const musikLautstaerke = (f: number, mitStimme: boolean) => {
  const grund = interpolate(f, [0, 6, GESAMT2 - 20, GESAMT2], [0, 0.85, 0.85, 0], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'});
  if (!mitStimme) return grund;
  const unter = SAETZE.reduce((tief, s) => {
    const t = interpolate(f, [s.von - 8, s.von, s.bis, s.bis + 10], [0, 1, 1, 0], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'});
    return Math.max(tief, t);
  }, 0);
  return grund * (1 - 0.75 * unter);
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
                <Html5Audio src={staticFile(s.datei)} volume={1} />
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
