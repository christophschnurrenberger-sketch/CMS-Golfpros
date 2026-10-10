/**
 * Die zehn Szenen des Werbevideos. Alle Bildschirme sind Aufnahmen aus der
 * App (siehe aufnahme/aufnehmen.js), alle Tipps echte Klicks, deren Ziel
 * die Aufnahme vermessen hat (screens.json → marken).
 */
import React from 'react';
import {AbsoluteFill, Easing, interpolate, spring, useCurrentFrame, useVideoConfig} from 'remotion';
import {aufnahme, farbe, mitte, schrift} from './marke';
import {Browser} from './teile/Browser';
import {useQuer} from './teile/Format';
import {Logo, Zeichen} from './teile/Marke';
import {Telefon} from './teile/Telefon';
import {Notiz, Titel} from './teile/Titel';

const klemmen = {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'} as const;

/** Federnder Aufstieg ab `start` – 0 unten, 1 an seinem Platz. */
export const useAufstieg = (start = 0, steif = 110) => {
  const frame = useCurrentFrame();
  const {fps} = useVideoConfig();
  return spring({frame: frame - start, fps, config: {damping: 17, stiffness: steif, mass: 0.9}});
};

/* Das Telefon der Funktionsszenen: 560 px breit, mittig – so hoch, dass feste Leisten unten noch im Bild sind. */
const TEL_B = 560;
const TEL_LINKS = (1080 - TEL_B * 1.09) / 2;
const TEL_OBEN = 615;

/* Im Querformat steht es rechts neben der Überschrift, so hoch wie das Bild. */
const TEL_QUER = {breite: 400, links: 1152, oben: 66};

export const Funktionstelefon: React.FC<Omit<React.ComponentProps<typeof Telefon>, 'breite'> & {start?: number}> = ({start = 0, style, ...rest}) => {
  const p = useAufstieg(start);
  const quer = useQuer();
  return (
    <Telefon
      {...rest}
      breite={quer ? TEL_QUER.breite : TEL_B}
      style={{left: quer ? TEL_QUER.links : TEL_LINKS, top: quer ? TEL_QUER.oben : TEL_OBEN, transform: `translateY(${(1 - p) * 320}px)`, ...style}}
    />
  );
};

/* ----------------------------------------------------------- 1 Hook --- */

export const Hook: React.FC = () => {
  const frame = useCurrentFrame();
  const quer = useQuer();
  const ball = useAufstieg(56, 160);
  const bogen = interpolate(frame, [60, 84], [0, 1], {...klemmen, easing: Easing.inOut(Easing.cubic)});
  /* Hoch: Text über die Breite, das Zeichen unten rechts. Quer: Text links, das Zeichen groß rechts. */
  const l = quer
    ? {oben: 250, links: 160, rechts: 560, groesse: 128, zeile: 131, notizOben: 800, zeichen: {left: 1440, top: 470, groesse: 300}}
    : {oben: 520, links: 80, rechts: 80, groesse: 136, zeile: 139, notizOben: 1140, zeichen: {left: 790, top: 1290, groesse: 200}};
  return (
    <AbsoluteFill>
      <Titel oben={l.oben} links={l.links} rechts={l.rechts} groesse={l.groesse} start={3} zeilen={[{text: 'Du gibst'}, {text: 'Golfstunden.'}]} />
      <Titel
        oben={l.oben + 2 * l.zeile}
        links={l.links}
        rechts={l.rechts}
        groesse={l.groesse}
        start={30}
        zeilen={[{text: 'Keine', farbe: farbe.signal}, {text: 'Bürostunden.', farbe: farbe.signal}]}
      />
      <div style={{position: 'absolute', top: l.notizOben, left: l.links}}>
        <Notiz text="Für den Rest gibt’s TeePilot." start={54} groesse={74} farbeText={farbe.sand} />
      </div>
      <div style={{position: 'absolute', left: l.zeichen.left, top: l.zeichen.top, opacity: ball > 0.01 ? 1 : 0}}>
        <Zeichen groesse={l.zeichen.groesse} ball={ball} bogen={bogen} />
      </div>
    </AbsoluteFill>
  );
};

/* ---------------------------------------------------------- 2 Marke --- */

/** `verzoegerung`: In der langen Fassung erscheint der Ball genau auf dem Einschlag der Musik. */
export const Marke: React.FC<{verzoegerung?: number}> = ({verzoegerung: v = 0}) => {
  const frame = useCurrentFrame();
  const ball = useAufstieg(2 + v, 180);
  const bogen = interpolate(frame, [6 + v, 24 + v], [0, 1], {...klemmen, easing: Easing.inOut(Easing.cubic)});
  const zug = useAufstieg(12 + v, 140);
  const unter = interpolate(frame, [22 + v, 36 + v], [0, 1], klemmen);
  return (
    <AbsoluteFill style={{alignItems: 'center', justifyContent: 'center'}}>
      <div style={{marginTop: -120}}>
        <Logo groesse={150} ball={ball} bogen={bogen} schriftzug={zug} />
      </div>
      <div
        style={{
          marginTop: 70, textAlign: 'center', fontFamily: schrift.titel, fontWeight: 500, fontSize: 58, lineHeight: 1.25,
          color: farbe.chalk, opacity: unter * 0.88, transform: `translateY(${(1 - unter) * 24}px)`,
        }}
      >
        Die App für Golfpros,
        <br />
        Coaches und Academies.
      </div>
    </AbsoluteFill>
  );
};

/* -------------------------------------------------------- 3 Buchung --- */

export const Buchung: React.FC = () => {
  const kal = aufnahme('buchen-kalender');
  const zeiten = aufnahme('buchen-zeiten');
  const angaben = aufnahme('buchen-angaben');
  const ziel = kal.scrollZiel ?? 0;
  return (
    <AbsoluteFill>
      <Titel zeilen={[{text: 'Schüler buchen'}, {text: 'online.', farbe: farbe.signal}]} notiz="rund um die Uhr" />
      <Funktionstelefon
        start={2}
        zustaende={[
          {screen: 'buchen-kalender', ab: 0, scroll: [[22, 0], [58, ziel]]},
          {screen: 'buchen-zeiten', ab: 72},
          {screen: 'buchen-angaben', ab: 104, scroll: [[112, 0], [136, Math.max(0, angaben.marken.gewaehlt.y - 200)]]},
        ]}
        tipps={[
          {frame: 70, ...mitte(kal.marken.tag, ziel)},
          {frame: 101, ...mitte(zeiten.marken.zeit)},
        ]}
      />
    </AbsoluteFill>
  );
};

/* ------------------------------------------------------- 4 Kalender --- */

export const Kalender: React.FC = () => {
  const frame = useCurrentFrame();
  const p = useAufstieg(2, 90);
  const zoom = interpolate(frame, [26, 98], [1, 1.85], {...klemmen, easing: Easing.inOut(Easing.cubic)});
  return (
    <AbsoluteFill>
      <Titel zeilen={[{text: 'Alles landet in'}, {text: 'deinem Kalender.', farbe: farbe.signal}]} notiz="mit Trainern, Standorten & Kursen" />
      <div
        style={{
          position: 'absolute', left: 40, top: 760, width: 1000, perspective: 2200,
          transform: `scale(${zoom})`, transformOrigin: '74% 34%',
        }}
      >
        <Browser
          screen="kalender"
          breite={1000}
          titel="Kalender · Golf Academy Bergmann"
          style={{position: 'relative', transform: `translateY(${(1 - p) * 360}px) rotateX(${(1 - p) * 24}deg)`, transformOrigin: 'center top'}}
        />
      </div>
    </AbsoluteFill>
  );
};

/* -------------------------------------------------------- 5 Kunde ----- */

export const Kunde: React.FC = () => {
  const k = aufnahme('kunde');
  const handicap = Math.max(0, k.marken.handicap.y - 130);
  return (
    <AbsoluteFill>
      <Titel zeilen={[{text: 'Jeder Schüler'}, {text: 'auf einen Blick.', farbe: farbe.signal}]} notiz="Handicap · Pakete · Verlauf" />
      <Funktionstelefon start={2} zustaende={[{screen: 'kunde', ab: 0, scroll: [[22, 0], [44, 380], [56, 380], [90, handicap]]}]} />
    </AbsoluteFill>
  );
};

/* ------------------------------------------------------ 6 Training ---- */

export const Training: React.FC = () => (
  <AbsoluteFill>
    <Titel zeilen={[{text: 'Trainingspläne'}, {text: 'für jeden Schüler.', farbe: farbe.signal}]} notiz="Woche für Woche, Übung für Übung" />
    <Funktionstelefon start={2} zustaende={[{screen: 'plan', ab: 0, scroll: [[20, 0], [82, 980]]}]} />
  </AbsoluteFill>
);

/* ------------------------------------------------------- 7 Website ---- */

export const Website: React.FC = () => {
  const frame = useCurrentFrame();
  const fenster = useAufstieg(2, 90);
  const tel = useAufstieg(12, 120);
  const zoom = interpolate(frame, [20, 98], [1, 1.08], klemmen);
  return (
    <AbsoluteFill>
      <Titel zeilen={[{text: 'Deine Website.'}, {text: 'Ohne Agentur.', farbe: farbe.signal}]} notiz="Text anklicken, ändern, fertig." />
      <div style={{position: 'absolute', left: 20, top: 770, width: 900, perspective: 2000, transform: `scale(${zoom})`, transformOrigin: 'left center'}}>
        <Browser
          screen="baukasten"
          breite={900}
          titel="Startseite · Golf Academy Bergmann"
          style={{
            position: 'relative', transformOrigin: 'left center',
            transform: `translateX(${(1 - fenster) * -500}px) rotateY(${10 + (1 - fenster) * 20}deg)`,
          }}
        />
      </div>
      <Telefon
        breite={390}
        zustaende={[{screen: 'website', ab: 0}]}
        style={{left: 640, top: 960, transform: `translateY(${(1 - tel) * 700}px) rotate(${(1 - tel) * 8 + 3}deg)`}}
      />
    </AbsoluteFill>
  );
};

/* -------------------------------------------------------- 8 Reisen ---- */

export const Reisen: React.FC = () => {
  const r = aufnahme('reise');
  return (
    <AbsoluteFill>
      <Titel zeilen={[{text: 'Golfreisen'}, {text: 'wie im Reisebüro.', farbe: farbe.signal}]} notiz="Buchung für bis zu sechs Reisende" />
      <Funktionstelefon start={2} zustaende={[{screen: 'reise', ab: 0, scroll: [[34, 0], [84, Math.max(0, r.marken.bilder.y - 200)]]}]} />
    </AbsoluteFill>
  );
};

/* ---------------------------------------------------- 9 Rechnungen ---- */

export const Rechnungen: React.FC = () => {
  const frame = useCurrentFrame();
  const r = aufnahme('rechnungen');
  const ziel = r.scrollZiel ?? 0;
  const naeher = interpolate(frame, [58, 88], [1, 1.1], {...klemmen, easing: Easing.out(Easing.cubic)});
  return (
    <AbsoluteFill>
      <Titel zeilen={[{text: 'Rechnung?'}, {text: 'Ein Klick.', farbe: farbe.signal}]} notiz="aus allen offenen Terminen" />
      <Funktionstelefon
        start={2}
        style={{transform: `scale(${naeher})`, transformOrigin: '50% 8%'}}
        zustaende={[
          {screen: 'rechnungen', ab: 0, scroll: [[10, 0], [38, ziel]]},
          {screen: 'rechnung-neu', ab: 54},
        ]}
        tipps={[{frame: 51, ...mitte(r.marken.knopf, ziel)}]}
      />
    </AbsoluteFill>
  );
};

/* -------------------------------------------------------- 10 Schluss -- */

const BEREICHE = ['Online-Buchung', 'Kalender', 'Kundenakte', 'Training', 'Website', 'Golfreisen', 'Rechnungen'];

/** `desktop`: In der langen Fassung steht hinter den Telefonen noch ein Browserfenster. */
export const Schluss: React.FC<{aufruf: string; adresse: string; desktop?: string; bereiche?: string[]}> = ({
  aufruf,
  adresse,
  desktop,
  bereiche = BEREICHE,
}) => {
  const frame = useCurrentFrame();
  const {fps} = useVideoConfig();
  const quer = useQuer();
  const logo = useAufstieg(0, 150);
  const knopf = spring({frame: frame - 30, fps, config: {damping: 11, stiffness: 160}});
  const puls = 1 + Math.max(0, Math.sin((frame - 60) / 7)) * 0.025 * (frame > 60 ? 1 : 0);
  if (quer) {
    return <SchlussQuer {...{aufruf, adresse, desktop, bereiche, logo, knopf, puls}} />;
  }
  const telefone = [
    {screen: 'kunde', x: 30, y: 1290, dreh: -11, start: 6},
    {screen: 'reise', x: 650, y: 1290, dreh: 11, start: 10},
    {screen: 'portal', x: 330, y: 1200, dreh: 0, start: 2},
  ];
  return (
    <AbsoluteFill>
      {desktop ? <SchlussFenster screen={desktop} /> : null}
      {telefone.map((t) => (
        <SchlussTelefon key={t.screen} {...t} />
      ))}
      <AbsoluteFill style={{background: 'linear-gradient(180deg, rgba(11,43,34,1) 0%, rgba(11,43,34,0.95) 50%, rgba(11,43,34,0.0) 74%)'}} />
      <AbsoluteFill style={{alignItems: 'center'}}>
        <div style={{marginTop: 290, transform: `translateY(${(1 - logo) * 60}px)`, opacity: logo}}>
          <Logo groesse={118} />
        </div>
        <Titel mitte oben={480} groesse={96} start={8} zeilen={[{text: 'Dein Auftritt.'}, {text: 'In deiner Hand.', farbe: farbe.signal}]} />
        <div
          style={{
            position: 'absolute', top: 760, padding: '30px 64px', borderRadius: 999, background: farbe.signal, color: farbe.pine,
            fontFamily: schrift.titel, fontWeight: 750, fontSize: 52, letterSpacing: '-0.02em',
            transform: `scale(${knopf * puls})`, boxShadow: '0 20px 60px rgba(195,227,92,0.25)',
          }}
        >
          {aufruf}
        </div>
        {adresse ? (
          <div
            style={{
              position: 'absolute', top: 905, fontFamily: schrift.mono, fontWeight: 500, fontSize: 38, color: farbe.chalk,
              opacity: interpolate(frame, [40, 52], [0, 1], klemmen),
            }}
          >
            {adresse}
          </div>
        ) : null}
        <div style={{position: 'absolute', top: adresse ? 990 : 940, left: 90, right: 90, display: 'flex', flexWrap: 'wrap', justifyContent: 'center', gap: '14px 16px'}}>
          {bereiche.map((b, i) => (
            <span
              key={b}
              style={{
                fontFamily: schrift.mono, fontWeight: 500, fontSize: 27, color: farbe.chalk, padding: '8px 18px',
                border: '1.5px solid rgba(246,245,240,0.28)', borderRadius: 999,
                opacity: interpolate(frame, [44 + i * 2, 54 + i * 2], [0, 0.9], klemmen),
              }}
            >
              {b}
            </span>
          ))}
        </div>
      </AbsoluteFill>
    </AbsoluteFill>
  );
};

/** Querformat: links Marke, Satz, Knopf und Bereiche – rechts Schreibtisch und Telefone. */
const SchlussQuer: React.FC<{
  aufruf: string;
  adresse: string;
  desktop?: string;
  bereiche: string[];
  logo: number;
  knopf: number;
  puls: number;
}> = ({aufruf, adresse, desktop, bereiche, logo, knopf, puls}) => {
  const frame = useCurrentFrame();
  const fenster = useAufstieg(0, 80);
  const telefone = [
    {screen: 'kunde', x: 1010, y: 430, dreh: -7, start: 8, breite: 280},
    {screen: 'portal', x: 1570, y: 330, dreh: 6, start: 4, breite: 300},
  ];
  return (
    <AbsoluteFill>
      {desktop ? (
        <div style={{position: 'absolute', left: 960, top: 170, width: 880, perspective: 2200}}>
          <Browser
            screen={desktop}
            breite={880}
            titel="Dashboard · Golf Academy Bergmann"
            style={{position: 'relative', transformOrigin: 'right center', transform: `translateY(${(1 - fenster) * 500}px) rotateY(${-8 * (0.6 + 0.4 * fenster)}deg)`}}
          />
        </div>
      ) : null}
      {telefone.map((t) => (
        <SchlussTelefon key={t.screen} {...t} />
      ))}
      <AbsoluteFill style={{background: 'linear-gradient(90deg, rgba(11,43,34,1) 0%, rgba(11,43,34,0.94) 40%, rgba(11,43,34,0) 56%)'}} />
      <div style={{position: 'absolute', left: 140, top: 210, transform: `translateY(${(1 - logo) * 40}px)`, opacity: logo}}>
        <Logo groesse={100} />
      </div>
      <Titel oben={370} links={140} rechts={980} groesse={96} start={8} zeilen={[{text: 'Dein Auftritt.'}, {text: 'In deiner Hand.', farbe: farbe.signal}]} />
      <div
        style={{
          position: 'absolute', left: 140, top: 620, padding: '26px 56px', borderRadius: 999, background: farbe.signal, color: farbe.pine,
          fontFamily: schrift.titel, fontWeight: 750, fontSize: 46, letterSpacing: '-0.02em', transformOrigin: 'left center',
          transform: `scale(${knopf * puls})`, boxShadow: '0 20px 60px rgba(195,227,92,0.25)',
        }}
      >
        {aufruf}
      </div>
      {adresse ? (
        <div style={{position: 'absolute', left: 146, top: 750, fontFamily: schrift.mono, fontWeight: 500, fontSize: 34, color: farbe.chalk, opacity: interpolate(frame, [40, 52], [0, 1], klemmen)}}>
          {adresse}
        </div>
      ) : null}
      <div style={{position: 'absolute', left: 140, width: 780, top: adresse ? 820 : 770, display: 'flex', flexWrap: 'wrap', gap: '12px 14px'}}>
        {bereiche.map((b, i) => (
          <span
            key={b}
            style={{
              fontFamily: schrift.mono, fontWeight: 500, fontSize: 24, color: farbe.chalk, padding: '7px 16px',
              border: '1.5px solid rgba(246,245,240,0.28)', borderRadius: 999,
              opacity: interpolate(frame, [44 + i * 2, 54 + i * 2], [0, 0.9], klemmen),
            }}
          >
            {b}
          </span>
        ))}
      </div>
    </AbsoluteFill>
  );
};

const SchlussFenster: React.FC<{screen: string}> = ({screen}) => {
  const p = useAufstieg(0, 80);
  return (
    <div style={{position: 'absolute', left: 60, top: 1110, width: 960, perspective: 2000}}>
      <Browser
        screen={screen}
        breite={960}
        titel="Dashboard · Golf Academy Bergmann"
        style={{position: 'relative', opacity: 0.9, transform: `translateY(${(1 - p) * 500}px) rotateX(${14 + (1 - p) * 20}deg)`, transformOrigin: 'center bottom'}}
      />
    </div>
  );
};

const SchlussTelefon: React.FC<{screen: string; x: number; y: number; dreh: number; start: number; breite?: number}> = ({
  screen,
  x,
  y,
  dreh,
  start,
  breite = 390,
}) => {
  const p = useAufstieg(start, 90);
  return (
    <Telefon breite={breite} zustaende={[{screen, ab: 0}]} style={{left: x, top: y, transform: `translateY(${(1 - p) * 600}px) rotate(${dreh}deg)`}} />
  );
};
