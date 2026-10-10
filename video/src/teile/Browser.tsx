/**
 * Ein Browserfenster mit einer Desktop-Aufnahme der App. Statt einer
 * erfundenen Adresse steht oben ein Tab mit dem echten Seitentitel und dem
 * Favicon von TeePilot (dem TP-Monogramm aus lib/Marke.php).
 */
import React from 'react';
import {Img, staticFile} from 'remotion';
import {aufnahme, farbe, schrift} from '../marke';

export const Monogramm: React.FC<{groesse: number}> = ({groesse}) => (
  <svg viewBox="0 0 100 100" width={groesse} height={groesse} style={{display: 'block', flex: 'none'}}>
    <rect width="100" height="100" rx="26" fill={farbe.pine} />
    <path d="M39 22V78" stroke={farbe.chalk} strokeWidth="11" strokeLinecap="round" fill="none" />
    <path d="M20 22H39" stroke={farbe.chalk} strokeWidth="11" strokeLinecap="round" fill="none" />
    <path d="M39 22C66 22 80 30 80 39.5C80 49 66 56 39 56" stroke={farbe.signal} strokeWidth="11" strokeLinecap="round" fill="none" />
  </svg>
);

export const Browser: React.FC<{screen: string; breite: number; titel: string; style?: React.CSSProperties}> = ({
  screen,
  breite,
  titel,
  style,
}) => {
  const a = aufnahme(screen);
  const k = breite / 1000;
  const leiste = 50 * k;
  return (
    <div
      style={{
        position: 'absolute', width: breite, borderRadius: 18 * k, overflow: 'hidden', background: '#e6e5de',
        boxShadow: `0 ${50 * k}px ${110 * k}px rgba(0,0,0,0.5), 0 0 0 ${1.5 * k}px rgba(255,255,255,0.12)`,
        ...style,
      }}
    >
      <div style={{height: leiste, position: 'relative', background: '#dedcd6'}}>
        {['#ff5f57', '#febc2e', '#28c840'].map((c, i) => (
          <div
            key={c}
            style={{position: 'absolute', left: (20 + i * 22) * k, top: (leiste - 13 * k) / 2, width: 13 * k, height: 13 * k, borderRadius: '50%', background: c}}
          />
        ))}
        <div
          style={{
            position: 'absolute', left: 100 * k, bottom: 0, height: leiste - 10 * k, width: 380 * k,
            background: '#f6f5f0', borderRadius: `${10 * k}px ${10 * k}px 0 0`, display: 'flex', alignItems: 'center',
            gap: 10 * k, padding: `0 ${14 * k}px`, boxSizing: 'border-box',
          }}
        >
          <Monogramm groesse={18 * k} />
          <span style={{fontFamily: schrift.titel, fontSize: 15 * k, fontWeight: 500, color: '#40433c', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis'}}>
            {titel}
          </span>
        </div>
      </div>
      <Img src={staticFile(a.bild)} style={{display: 'block', width: breite, height: (a.hoehe / a.breite) * breite}} />
    </div>
  );
};

export const browserHoehe = (breite: number) => (900 / 1440) * breite + 50 * (breite / 1000);
