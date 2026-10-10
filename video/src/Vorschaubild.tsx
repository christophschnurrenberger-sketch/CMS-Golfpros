/**
 * Vorschaubild für YouTube (1280×720) und Standbild vor dem Abspielen auf
 * der Website (1920×1080). Muss auch klein lesbar sein – darum nur wenige
 * große Wörter: für wen, und daneben echte Screens.
 */
import React from 'react';
import {AbsoluteFill} from 'remotion';
import {farbe, schrift} from './marke';
import {Browser} from './teile/Browser';
import {Hintergrund} from './teile/Hintergrund';
import {Logo} from './teile/Marke';
import {Telefon} from './teile/Telefon';

export const Vorschaubild: React.FC = () => (
  <AbsoluteFill>
    <Hintergrund />
    <div style={{position: 'absolute', left: 900, top: 150, width: 940, perspective: 2200}}>
      <Browser
        screen="dashboard-desk"
        breite={940}
        titel="Dashboard · Golf Academy Bergmann"
        style={{position: 'relative', transformOrigin: 'right center', transform: 'rotateY(-9deg)'}}
      />
    </div>
    <Telefon breite={270} zustaende={[{screen: 'kunde', ab: 0}]} style={{left: 960, top: 450, transform: 'rotate(-7deg)'}} />
    <Telefon breite={300} zustaende={[{screen: 'portal', ab: 0}]} style={{left: 1560, top: 330, transform: 'rotate(6deg)'}} />
    <AbsoluteFill style={{background: 'linear-gradient(90deg, rgba(11,43,34,1) 0%, rgba(11,43,34,0.93) 42%, rgba(11,43,34,0) 56%)'}} />
    <div style={{position: 'absolute', left: 120, top: 170}}>
      <Logo groesse={92} />
    </div>
    <div style={{position: 'absolute', left: 120, top: 330, fontFamily: schrift.titel, letterSpacing: '-0.035em'}}>
      <div style={{fontWeight: 650, fontSize: 76, lineHeight: 1.1, color: farbe.chalk}}>Die App für</div>
      <div style={{fontWeight: 800, fontSize: 132, lineHeight: 1.0, color: farbe.signal}}>
        Golfpros,
        <br />
        Coaches &amp;
        <br />
        Academies.
      </div>
    </div>
  </AbsoluteFill>
);
