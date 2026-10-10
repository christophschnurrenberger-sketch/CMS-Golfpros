/**
 * Ein Telefon mit echten Screens aus der App.
 *
 * Die Seite ist eine ganzseitige Aufnahme; „Scrollen“ verschiebt sie hinter
 * dem Bildschirmausschnitt. Feste Leisten (Kopfzeile, Buchungsleiste,
 * Navigation) liegen als eigene Aufnahmen darüber und bleiben stehen – wie
 * im Browser. Ein Zustandswechsel ist ein echter Klick aus der Aufnahme.
 */
import React from 'react';
import {Easing, Img, interpolate, staticFile, useCurrentFrame} from 'remotion';
import {aufnahme, farbe, schrift} from '../marke';

const SEITE_B = 390;
const SEITE_H = 844;
const STATUS = 46;

export type Zustand = {
  /** Name der Aufnahme in screens.json */
  screen: string;
  /** Ab diesem Frame (relativ zur Szene) ist der Zustand zu sehen. */
  ab: number;
  /** Scroll-Stützpunkte: [Frame, CSS-Pixel] – dazwischen weich. */
  scroll?: Array<[number, number]>;
};

export type Tipp = {frame: number; x: number; y: number};

const scrollBei = (frame: number, punkte: Array<[number, number]> | undefined): number => {
  if (!punkte || punkte.length === 0) return 0;
  if (frame <= punkte[0][0]) return punkte[0][1];
  for (let i = 1; i < punkte.length; i++) {
    const [f0, y0] = punkte[i - 1];
    const [f1, y1] = punkte[i];
    if (frame <= f1) {
      return interpolate(frame, [f0, f1], [y0, y1], {easing: Easing.bezier(0.45, 0, 0.2, 1)});
    }
  }
  return punkte[punkte.length - 1][1];
};

const Seite: React.FC<{z: Zustand; s: number; frame: number}> = ({z, s, frame}) => {
  const a = aufnahme(z.screen);
  const scroll = Math.min(scrollBei(frame, z.scroll), Math.max(0, a.hoehe - SEITE_H));
  return (
    <div style={{position: 'absolute', left: 0, top: STATUS * s, width: SEITE_B * s, height: SEITE_H * s, overflow: 'hidden', background: '#fff'}}>
      <Img
        src={staticFile(a.bild)}
        style={{position: 'absolute', left: 0, top: 0, width: SEITE_B * s, transform: `translateY(${-scroll * s}px)`}}
      />
      {a.overlays.map((o) => (
        <Img
          key={o.bild}
          src={staticFile(o.bild)}
          style={{position: 'absolute', left: o.x * s, top: o.y * s, width: o.breite * s, height: o.hoehe * s}}
        />
      ))}
    </div>
  );
};

const Statusleiste: React.FC<{s: number; hintergrund: string}> = ({s, hintergrund}) => (
  <div style={{position: 'absolute', left: 0, top: 0, width: SEITE_B * s, height: STATUS * s, background: '#fff'}}>
    <div style={{position: 'absolute', inset: 0, background: hintergrund}} />
    <div
      style={{
        position: 'absolute', left: 30 * s, top: 15 * s, fontFamily: schrift.titel, fontWeight: 650,
        fontSize: 15.5 * s, color: farbe.anthrazit, letterSpacing: '-0.01em',
      }}
    >
      9:41
    </div>
    <div
      style={{
        position: 'absolute', left: (SEITE_B / 2 - 60) * s, top: 9 * s, width: 120 * s, height: 33 * s,
        borderRadius: 17 * s, background: '#000',
      }}
    />
    <svg
      viewBox="0 0 74 14"
      style={{position: 'absolute', right: 26 * s, top: 17 * s, width: 74 * s, height: 14 * s}}
      fill={farbe.anthrazit}
    >
      <rect x="0" y="9" width="3" height="5" rx="1" />
      <rect x="5" y="6" width="3" height="8" rx="1" />
      <rect x="10" y="3" width="3" height="11" rx="1" />
      <rect x="15" y="0" width="3" height="14" rx="1" />
      <path d="M31 4.5a10 10 0 0 1 13 0l-1.6 1.7a7.6 7.6 0 0 0-9.8 0Z M34.2 8a5.3 5.3 0 0 1 6.6 0l-1.6 1.7a3 3 0 0 0-3.4 0Z M37.5 11.2l1.5 1.6-1.5 1.5-1.5-1.5Z" />
      <rect x="50" y="1.5" width="21" height="11" rx="3.2" fill="none" stroke={farbe.anthrazit} strokeOpacity=".45" strokeWidth="1.2" />
      <rect x="52" y="3.5" width="15" height="7" rx="1.8" />
      <rect x="72" y="5" width="1.6" height="4" rx=".8" fillOpacity=".45" />
    </svg>
  </div>
);

/** Ein Fingertipp: Druck, dann ein Ring, der ausläuft. */
const Fingertipp: React.FC<{t: Tipp; s: number; frame: number}> = ({t, s, frame}) => {
  const d = frame - t.frame;
  if (d < -9 || d > 18) return null;
  const klemmen = {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'} as const;
  const druck = interpolate(d, [-9, -2, 2], [0.4, 1, 0.82], klemmen);
  const sicht = interpolate(d, [-9, -5, 6, 12], [0, 1, 1, 0], klemmen);
  const ring = interpolate(d, [0, 13], [1, 2.6], {...klemmen, easing: Easing.out(Easing.cubic)});
  const ringSicht = interpolate(d, [0, 13], [0.85, 0], klemmen);
  const g = 46 * s;
  const lage: React.CSSProperties = {position: 'absolute', left: t.x * s - g / 2, top: (STATUS + t.y) * s - g / 2, width: g, height: g, borderRadius: '50%'};
  return (
    <>
      <div style={{...lage, border: `${2.5 * s}px solid rgba(255,255,255,${ringSicht})`, transform: `scale(${ring})`, boxShadow: `0 0 0 ${1 * s}px rgba(11,43,34,${ringSicht * 0.35})`}} />
      <div
        style={{
          ...lage, opacity: sicht, transform: `scale(${druck})`, background: 'rgba(11,43,34,0.32)',
          border: `${2.5 * s}px solid rgba(255,255,255,0.95)`, boxShadow: `0 ${4 * s}px ${14 * s}px rgba(0,0,0,0.25)`,
        }}
      />
    </>
  );
};

export const Telefon: React.FC<{
  zustaende: Zustand[];
  tipps?: Tipp[];
  breite: number;
  style?: React.CSSProperties;
}> = ({zustaende, tipps = [], breite, style}) => {
  const frame = useCurrentFrame();
  const s = breite / SEITE_B;
  const rand = breite * 0.045;
  const radius = breite * 0.15;

  let i = 0;
  zustaende.forEach((z, k) => {
    if (frame >= z.ab) i = k;
  });
  const jetzt = zustaende[i];
  const vorher = i > 0 ? zustaende[i - 1] : null;
  /* Nach einem Tipp kommt die neue Seite nicht schlagartig – drei Frames Überblendung. */
  const neu = interpolate(frame - jetzt.ab, [0, 3], [0, 1], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'});
  const status = aufnahme(jetzt.screen).statusfarbe ?? '#fff';

  return (
    <div
      style={{
        position: 'absolute', width: breite + rand * 2, padding: rand, borderRadius: radius,
        background: 'linear-gradient(150deg, #3a3e38 0%, #121512 22%, #0b0d0b 70%, #2a2d28 100%)',
        boxShadow: `0 ${breite * 0.09}px ${breite * 0.2}px rgba(0,0,0,0.5), inset 0 0 0 ${breite * 0.004}px rgba(255,255,255,0.18)`,
        ...style,
      }}
    >
      <div
        style={{
          position: 'relative', width: breite, height: (SEITE_H + STATUS) * s, borderRadius: radius - rand,
          overflow: 'hidden', background: '#fff',
        }}
      >
        {vorher && neu < 1 ? <Seite z={vorher} s={s} frame={frame} /> : null}
        <div style={{position: 'absolute', inset: 0, opacity: vorher ? neu : 1}}>
          <Seite z={jetzt} s={s} frame={frame} />
        </div>
        <Statusleiste s={s} hintergrund={status} />
        {tipps.map((t) => (
          <Fingertipp key={t.frame} t={t} s={s} frame={frame} />
        ))}
        {/* Home-Indikator */}
        <div
          style={{
            position: 'absolute', left: (SEITE_B / 2 - 67) * s, bottom: 8 * s, width: 134 * s, height: 5 * s,
            borderRadius: 3 * s, background: 'rgba(0,0,0,0.75)',
          }}
        />
      </div>
    </div>
  );
};

export const telefonHoehe = (breite: number) => (SEITE_H + STATUS) * (breite / SEITE_B) + breite * 0.09;
