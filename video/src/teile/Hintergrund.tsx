/**
 * Der Grund des ganzen Videos: Pine, etwas Fairway von oben, und die Bahn
 * aus der Bildmarke als feine Linien, die langsam treiben.
 */
import React from 'react';
import {AbsoluteFill, useCurrentFrame} from 'remotion';
import {farbe} from '../marke';

const BAHNEN = [
  {d: 'M-120 1720 C 260 1180, 640 760, 1220 420', w: 3, o: 0.09},
  {d: 'M-160 1980 C 300 1460, 760 1040, 1260 760', w: 2, o: 0.06},
  {d: 'M-80 1320 C 240 880, 580 520, 1180 160', w: 2, o: 0.05},
];

export const Hintergrund: React.FC = () => {
  const frame = useCurrentFrame();
  return (
    <AbsoluteFill
      style={{
        background: `radial-gradient(130% 60% at 10% -5%, ${farbe.fairway} 0%, rgba(18,81,63,0) 60%),
                     radial-gradient(90% 45% at 100% 105%, rgba(143,203,180,0.16) 0%, rgba(143,203,180,0) 70%), ${farbe.pine}`,
      }}
    >
      {/* `slice`: im Querformat füllen dieselben Linien die Fläche, statt schmal in der Mitte zu stehen. */}
      <svg viewBox="0 0 1080 1920" preserveAspectRatio="xMidYMid slice" style={{position: 'absolute', inset: 0, width: '100%', height: '100%'}}>
        {BAHNEN.map((b, i) => (
          <path
            key={i}
            d={b.d}
            fill="none"
            stroke={farbe.signal}
            strokeOpacity={b.o}
            strokeWidth={b.w}
            strokeLinecap="round"
            transform={`translate(${Math.sin((frame + i * 70) / 110) * 24} ${Math.cos((frame + i * 50) / 130) * 18})`}
          />
        ))}
      </svg>
    </AbsoluteFill>
  );
};
