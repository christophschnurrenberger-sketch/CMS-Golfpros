/**
 * TeePilot – lange Fassung: 58 Sekunden, 1080×1920, mit Musik.
 *
 * Mehr Funktionen, jede am Schreibtisch und auf dem Telefon. Die Musik
 * (ton/musik.mjs, 120 BPM) ist für genau diesen Ablauf geschrieben: Alle
 * Szenenlängen und der Übergang sind Vielfache eines Schlags (15 Frames),
 * jeder Schnitt fällt auf einen Schlag. Der Einschlag bei 4 s bringt das
 * Logo, der bei 52 s den Schluss (ton/musik.mjs: SCHLUSS).
 */
import React from 'react';
import {AbsoluteFill, Html5Audio, interpolate, Sequence, staticFile} from 'remotion';
import {linearTiming, springTiming, TransitionSeries} from '@remotion/transitions';
import {fade} from '@remotion/transitions/fade';
import {slide} from '@remotion/transitions/slide';
import {Buchung, Hook, Marke, Schluss} from './Szenen';
import {Auswertung2, Dashboard2, Kalender2, Kunde2, Leads2, Portal2, Rechnungen2, Reisen2, Training2, Website2} from './Szenen2';
import {Hintergrund} from './teile/Hintergrund';
import {MitTon} from './teile/Ton';
import type {WerbevideoProps} from './Werbevideo';

export const SCHLAG = 15;

/* Was die lange Fassung zeigt – am Ende noch einmal als Liste. */
const BEREICHE2 = ['Online-Buchung', 'Kalender', 'Dashboard', 'Kundenakte', 'Training', 'Leads', 'Website', 'Golfreisen', 'Rechnungen', 'Auswertung', 'Kundenportal'];
export const UEBERGANG2 = SCHLAG;

type Szene = {name: string; dauer: number; zu: 'blende' | 'wisch'; element: (p: WerbevideoProps, dauer: number) => React.ReactNode};

/*
 * Jeder Bildschirm steht gut vier Sekunden (zwei Takte) – so lange, dass man
 * Überschrift und Screen in Ruhe lesen kann. `zu`: wie die Szene
 * hereinkommt (die erste ignoriert es).
 */
const SZENEN2: Szene[] = [
  {name: 'hook', dauer: 105, zu: 'blende', element: () => <Hook />},
  {name: 'marke', dauer: 90, zu: 'blende', element: () => <Marke verzoegerung={28} />},
  {name: 'buchung', dauer: 165, zu: 'blende', element: () => <Buchung />},
  {name: 'kalender', dauer: 135, zu: 'wisch', element: (_, d) => <Kalender2 dauer={d} />},
  {name: 'dashboard', dauer: 135, zu: 'wisch', element: (_, d) => <Dashboard2 dauer={d} />},
  {name: 'kunde', dauer: 150, zu: 'wisch', element: (_, d) => <Kunde2 dauer={d} />},
  {name: 'training', dauer: 135, zu: 'wisch', element: (_, d) => <Training2 dauer={d} />},
  {name: 'leads', dauer: 135, zu: 'wisch', element: (_, d) => <Leads2 dauer={d} />},
  {name: 'website', dauer: 150, zu: 'wisch', element: (_, d) => <Website2 dauer={d} />},
  {name: 'reisen', dauer: 150, zu: 'wisch', element: (_, d) => <Reisen2 dauer={d} />},
  {name: 'rechnungen', dauer: 150, zu: 'wisch', element: (_, d) => <Rechnungen2 dauer={d} />},
  {name: 'auswertung', dauer: 135, zu: 'wisch', element: (_, d) => <Auswertung2 dauer={d} />},
  {name: 'portal', dauer: 120, zu: 'wisch', element: (_, d) => <Portal2 dauer={d} />},
  {name: 'schluss', dauer: 180, zu: 'blende', element: (p) => <Schluss {...p} desktop="dashboard-desk" bereiche={BEREICHE2} />},
];

/** Startframe jeder Szene im fertigen Video. */
export const STARTS2 = SZENEN2.reduce<number[]>((s, sz, i) => [...s, i === 0 ? 0 : s[i - 1] + SZENEN2[i - 1].dauer - UEBERGANG2], []);

export const GESAMT2 = SZENEN2.reduce((a, s) => a + s.dauer, 0) - UEBERGANG2 * (SZENEN2.length - 1);

export const Werbevideo2: React.FC<WerbevideoProps> = (props) => (
  <MitTon>
    <AbsoluteFill>
      <Hintergrund />
      <TransitionSeries>
        {SZENEN2.map((sz, i) => (
          <React.Fragment key={sz.name}>
            {i > 0 ? (
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

      <Html5Audio
        src={staticFile('ton/musik-lang.wav')}
        volume={(f) => interpolate(f, [0, 6, GESAMT2 - 20, GESAMT2], [0, 0.85, 0.85, 0], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'})}
      />
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
