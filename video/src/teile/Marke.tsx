/**
 * Bildmarke und Schriftzug von TeePilot – Punkt und Bogen: der Ball auf dem
 * Tee und die Bahn, die er nimmt (lib/Marke.php). Hier lässt sich der Bogen
 * „fliegen“: `bogen` von 0 bis 1 zeichnet ihn.
 */
import React from 'react';
import {farbe, schrift} from '../marke';

export const Zeichen: React.FC<{groesse: number; ball?: number; bogen?: number; ballFarbe?: string; bogenFarbe?: string}> = ({
  groesse,
  ball = 1,
  bogen = 1,
  ballFarbe = farbe.chalk,
  bogenFarbe = farbe.signal,
}) => (
  <svg viewBox="0 0 100 100" width={groesse} height={groesse} style={{display: 'block', flex: 'none', overflow: 'visible'}}>
    <circle cx="23" cy="71" r="14" fill={ballFarbe} style={{transform: `scale(${ball})`, transformOrigin: '23px 71px'}} />
    <path
      d="M42 59C52 41 65 28 86 20"
      fill="none"
      stroke={bogenFarbe}
      strokeWidth="9"
      strokeLinecap="round"
      pathLength={1}
      strokeDasharray={1}
      strokeDashoffset={1 - bogen}
      opacity={bogen > 0.001 ? 1 : 0}
    />
  </svg>
);

export const Logo: React.FC<{groesse: number; ball?: number; bogen?: number; schriftzug?: number}> = ({
  groesse,
  ball = 1,
  bogen = 1,
  schriftzug = 1,
}) => (
  <div style={{display: 'flex', alignItems: 'center', gap: groesse * 0.3}}>
    <Zeichen groesse={groesse} ball={ball} bogen={bogen} />
    <div style={{overflow: 'hidden', paddingRight: groesse * 0.1}}>
      <div
        style={{
          fontFamily: schrift.titel, fontWeight: 700, fontSize: groesse * 0.78, letterSpacing: '-0.04em', lineHeight: 1.1,
          color: farbe.chalk, transform: `translateX(${(schriftzug - 1) * 105}%)`, opacity: Math.min(1, schriftzug * 1.5),
        }}
      >
        TeePilot
      </div>
    </div>
  </div>
);
