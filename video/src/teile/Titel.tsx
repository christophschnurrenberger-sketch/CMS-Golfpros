/**
 * Überschrift, die Wort für Wort aus einer Kante steigt, und eine
 * handgeschriebene Notiz darunter – Caveat, wie auf den Websites der Pros.
 */
import React from 'react';
import {Easing, interpolate, spring, useCurrentFrame, useVideoConfig} from 'remotion';
import {farbe, schrift} from '../marke';

export type Zeile = {text: string; farbe?: string};

export const Titel: React.FC<{
  zeilen: Zeile[];
  start?: number;
  groesse?: number;
  oben?: number;
  notiz?: string;
  notizStart?: number;
  mitte?: boolean;
}> = ({zeilen, start = 4, groesse = 104, oben = 270, notiz, notizStart = 22, mitte = false}) => {
  const frame = useCurrentFrame();
  const {fps} = useVideoConfig();
  let nr = 0;
  return (
    <div style={{position: 'absolute', top: oben, left: 80, right: 80, textAlign: mitte ? 'center' : 'left'}}>
      {zeilen.map((z, i) => (
        <div key={i} style={{overflow: 'hidden', padding: `0 0 ${groesse * 0.1}px`, margin: `0 0 ${-groesse * 0.1}px`}}>
          <div
            style={{
              display: 'flex', flexWrap: 'wrap', justifyContent: mitte ? 'center' : 'flex-start', columnGap: groesse * 0.24,
              fontFamily: schrift.titel, fontWeight: 800, fontSize: groesse, lineHeight: 1.02, letterSpacing: '-0.035em',
              color: z.farbe ?? farbe.chalk,
            }}
          >
            {z.text.split(' ').map((wort, j) => {
              const p = spring({frame: frame - start - nr++ * 3, fps, config: {damping: 16, stiffness: 170, mass: 0.6}});
              return (
                <span key={j} style={{display: 'inline-block', transform: `translateY(${(1 - p) * 115}%)`}}>
                  {wort}
                </span>
              );
            })}
          </div>
        </div>
      ))}
      {notiz ? <Notiz text={notiz} start={notizStart} mitte={mitte} /> : null}
    </div>
  );
};

export const Notiz: React.FC<{text: string; start: number; groesse?: number; mitte?: boolean; farbeText?: string}> = ({
  text,
  start,
  groesse = 64,
  mitte = false,
  farbeText = farbe.signal,
}) => {
  const frame = useCurrentFrame();
  const {fps} = useVideoConfig();
  const p = spring({frame: frame - start, fps, config: {damping: 14, stiffness: 120}});
  const strich = interpolate(frame - start, [6, 26], [0, 1], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp', easing: Easing.inOut(Easing.cubic)});
  return (
    <div
      style={{
        marginTop: groesse * 0.35, display: 'inline-block', position: 'relative', fontFamily: schrift.hand, fontWeight: 600,
        fontSize: groesse, lineHeight: 1.05, color: farbeText, opacity: p, transformOrigin: mitte ? 'center' : 'left center',
        transform: `rotate(-2.5deg) translateY(${(1 - p) * 30}px)`,
      }}
    >
      {text}
      <svg
        viewBox="0 0 300 20"
        preserveAspectRatio="none"
        style={{position: 'absolute', left: '2%', right: '2%', bottom: -groesse * 0.16, width: '96%', height: groesse * 0.28, overflow: 'visible'}}
      >
        <path
          d="M2 14 C 80 4, 190 2, 298 9"
          fill="none"
          stroke={farbeText}
          strokeWidth="3.5"
          strokeLinecap="round"
          pathLength={1}
          strokeDasharray={1}
          strokeDashoffset={1 - strich}
          vectorEffect="non-scaling-stroke"
        />
      </svg>
    </div>
  );
};
