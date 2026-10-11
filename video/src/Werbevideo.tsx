/**
 * TeePilot – Werbevideo, 30 Sekunden, 1080×1920 (9:16).
 *
 * Aufbau: Haken → Marke → sieben Funktionen aus der App → Aufruf.
 * Die Szenen überlappen sich um UEBERGANG Frames; GESAMT ergibt sich daraus
 * und muss 900 (30 s × 30 fps) sein.
 */
import React from 'react';
import {AbsoluteFill} from 'remotion';
import {linearTiming, springTiming, TransitionSeries} from '@remotion/transitions';
import {fade} from '@remotion/transitions/fade';
import {slide} from '@remotion/transitions/slide';
import {Buchung, Hook, Kalender, Kunde, Marke, Rechnungen, Reisen, Schluss, Training, Website} from './Szenen';
import {Hintergrund} from './teile/Hintergrund';

export const FPS = 30;
export const UEBERGANG = 12;

export const SZENEN = {
  hook: 93,
  marke: 70,
  buchung: 150,
  kalender: 100,
  kunde: 100,
  training: 90,
  website: 100,
  reisen: 95,
  rechnungen: 90,
  schluss: 120,
} as const;

export const GESAMT =
  Object.values(SZENEN).reduce((a, b) => a + b, 0) - UEBERGANG * (Object.keys(SZENEN).length - 1);

export type WerbevideoProps = {
  /** Text auf dem Knopf am Ende. */
  aufruf: string;
  /** Adresse unter dem Knopf – leer lassen, wenn es (noch) keine gibt. */
  adresse: string;
  /** Lange Fassung: Sprecherstimme dazu (sofern ton/sprecher.mjs eine erzeugt hat). Nur Musik: false. */
  stimme?: boolean;
};

export const werbevideoStandard: WerbevideoProps = {
  aufruf: 'Jetzt Demo anfragen',
  adresse: '',
};

const wisch = () => (
  <TransitionSeries.Transition
    presentation={slide({direction: 'from-bottom'})}
    timing={springTiming({config: {damping: 200}, durationInFrames: UEBERGANG})}
  />
);
const blende = () => (
  <TransitionSeries.Transition presentation={fade()} timing={linearTiming({durationInFrames: UEBERGANG})} />
);

export const Werbevideo: React.FC<WerbevideoProps> = ({aufruf, adresse}) => (
  <AbsoluteFill>
    <Hintergrund />
    <TransitionSeries>
      <TransitionSeries.Sequence durationInFrames={SZENEN.hook}>
        <Hook />
      </TransitionSeries.Sequence>
      {blende()}
      <TransitionSeries.Sequence durationInFrames={SZENEN.marke}>
        <Marke />
      </TransitionSeries.Sequence>
      {blende()}
      <TransitionSeries.Sequence durationInFrames={SZENEN.buchung}>
        <Buchung />
      </TransitionSeries.Sequence>
      {wisch()}
      <TransitionSeries.Sequence durationInFrames={SZENEN.kalender}>
        <Kalender />
      </TransitionSeries.Sequence>
      {wisch()}
      <TransitionSeries.Sequence durationInFrames={SZENEN.kunde}>
        <Kunde />
      </TransitionSeries.Sequence>
      {wisch()}
      <TransitionSeries.Sequence durationInFrames={SZENEN.training}>
        <Training />
      </TransitionSeries.Sequence>
      {wisch()}
      <TransitionSeries.Sequence durationInFrames={SZENEN.website}>
        <Website />
      </TransitionSeries.Sequence>
      {wisch()}
      <TransitionSeries.Sequence durationInFrames={SZENEN.reisen}>
        <Reisen />
      </TransitionSeries.Sequence>
      {wisch()}
      <TransitionSeries.Sequence durationInFrames={SZENEN.rechnungen}>
        <Rechnungen />
      </TransitionSeries.Sequence>
      {blende()}
      <TransitionSeries.Sequence durationInFrames={SZENEN.schluss}>
        <Schluss aufruf={aufruf} adresse={adresse} />
      </TransitionSeries.Sequence>
    </TransitionSeries>
  </AbsoluteFill>
);
