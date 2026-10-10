/**
 * Ein Browserfenster mit einer Desktop-Aufnahme der App. Statt einer
 * erfundenen Adresse steht oben ein Tab mit dem echten Seitentitel und dem
 * Favicon von TeePilot (dem TP-Monogramm aus lib/Marke.php).
 *
 * Wie beim Telefon kann die Seite scrollen: Seitenleiste und Kopfzeile sind
 * eigene Aufnahmen und bleiben stehen, die Seite läuft darunter durch.
 */
import React from 'react';
import {Img, interpolate, staticFile, useCurrentFrame} from 'remotion';
import {aufnahme, farbe, schrift} from '../marke';
import {scrollBei, type Zustand} from './Telefon';

const FENSTER_B = 1440;
const FENSTER_H = 900;

export const Monogramm: React.FC<{groesse: number}> = ({groesse}) => (
  <svg viewBox="0 0 100 100" width={groesse} height={groesse} style={{display: 'block', flex: 'none'}}>
    <rect width="100" height="100" rx="26" fill={farbe.pine} />
    <path d="M39 22V78" stroke={farbe.chalk} strokeWidth="11" strokeLinecap="round" fill="none" />
    <path d="M20 22H39" stroke={farbe.chalk} strokeWidth="11" strokeLinecap="round" fill="none" />
    <path d="M39 22C66 22 80 30 80 39.5C80 49 66 56 39 56" stroke={farbe.signal} strokeWidth="11" strokeLinecap="round" fill="none" />
  </svg>
);

const Seite: React.FC<{z: Zustand; k: number; frame: number}> = ({z, k, frame}) => {
  const a = aufnahme(z.screen);
  const fenster = a.fenster ?? FENSTER_H;
  const scroll = Math.min(scrollBei(frame, z.scroll), Math.max(0, a.hoehe - fenster));
  return (
    <div style={{position: 'absolute', inset: 0, overflow: 'hidden', background: '#f6f5f0'}}>
      <Img src={staticFile(a.bild)} style={{position: 'absolute', left: 0, top: 0, width: FENSTER_B * k, transform: `translateY(${-scroll * k}px)`}} />
      {a.overlays.map((o) => (
        <Img key={o.bild} src={staticFile(o.bild)} style={{position: 'absolute', left: o.x * k, top: o.y * k, width: o.breite * k, height: o.hoehe * k}} />
      ))}
    </div>
  );
};

export const Browser: React.FC<{
  /** Eine einzelne Aufnahme, ohne Scrollen … */
  screen?: string;
  /** … oder eine Folge von Zuständen wie beim Telefon. */
  zustaende?: Zustand[];
  breite: number;
  titel: string;
  style?: React.CSSProperties;
}> = ({screen, zustaende, breite, titel, style}) => {
  const frame = useCurrentFrame();
  const folge = zustaende ?? [{screen: screen ?? '', ab: 0}];
  const k = breite / FENSTER_B;
  const m = breite / 1000;
  const leiste = 50 * m;

  let i = 0;
  folge.forEach((z, n) => {
    if (frame >= z.ab) i = n;
  });
  const jetzt = folge[i];
  const vorher = i > 0 ? folge[i - 1] : null;
  const neu = interpolate(frame - jetzt.ab, [0, 6], [0, 1], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'});

  return (
    <div
      style={{
        position: 'absolute', width: breite, borderRadius: 18 * m, overflow: 'hidden', background: '#e6e5de',
        boxShadow: `0 ${50 * m}px ${110 * m}px rgba(0,0,0,0.5), 0 0 0 ${1.5 * m}px rgba(255,255,255,0.12)`,
        ...style,
      }}
    >
      <div style={{height: leiste, position: 'relative', background: '#dedcd6'}}>
        {['#ff5f57', '#febc2e', '#28c840'].map((c, n) => (
          <div
            key={c}
            style={{position: 'absolute', left: (20 + n * 22) * m, top: (leiste - 13 * m) / 2, width: 13 * m, height: 13 * m, borderRadius: '50%', background: c}}
          />
        ))}
        <div
          style={{
            position: 'absolute', left: 100 * m, bottom: 0, height: leiste - 10 * m, width: 380 * m,
            background: '#f6f5f0', borderRadius: `${10 * m}px ${10 * m}px 0 0`, display: 'flex', alignItems: 'center',
            gap: 10 * m, padding: `0 ${14 * m}px`, boxSizing: 'border-box',
          }}
        >
          <Monogramm groesse={18 * m} />
          <span style={{fontFamily: schrift.titel, fontSize: 15 * m, fontWeight: 500, color: '#40433c', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis'}}>
            {titel}
          </span>
        </div>
      </div>
      <div style={{position: 'relative', width: breite, height: FENSTER_H * k}}>
        {vorher && neu < 1 ? <Seite z={vorher} k={k} frame={frame} /> : null}
        <div style={{position: 'absolute', inset: 0, opacity: vorher ? neu : 1}}>
          <Seite z={jetzt} k={k} frame={frame} />
        </div>
      </div>
    </div>
  );
};

export const browserHoehe = (breite: number) => (FENSTER_H / FENSTER_B) * breite + 50 * (breite / 1000);
