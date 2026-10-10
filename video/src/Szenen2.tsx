/**
 * Szenen der langen Fassung (45 s, mit Musik): jede Funktion am Schreibtisch
 * und auf dem Telefon. Alle Bildschirme sind Aufnahmen aus der App
 * (aufnahme/aufnehmen.js) – die Desktop-Seiten scrollen mit fester
 * Seitenleiste und Kopfzeile, wie im Browser.
 */
import React from 'react';
import {AbsoluteFill, Easing, interpolate, spring, useCurrentFrame, useVideoConfig} from 'remotion';
import {aufnahme, farbe, mitte, schrift} from './marke';
import {Funktionstelefon, useAufstieg} from './Szenen';
import {Browser} from './teile/Browser';
import {useQuer} from './teile/Format';
import {Logo} from './teile/Marke';
import {Telefon, type Tipp, type Zustand} from './teile/Telefon';
import {Notiz, Titel, type Zeile} from './teile/Titel';

const klemmen = {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'} as const;

type Kamera = {bis: number; ursprung: string; von?: number; start?: number; ende?: number};

/* Im Querformat darf ein Zoom nie nach links in die Überschrift wachsen:
   die linke Kante bleibt stehen, nur die Höhe des Ursprungs gilt. */
const ursprungFuer = (ursprung: string | undefined, quer: boolean) =>
  quer ? `0% ${(ursprung ?? 'center center').split(' ')[1] ?? '50%'}` : (ursprung ?? 'center');

/**
 * Schreibtisch und Telefon: ein Browserfenster, leicht gedreht, davor das
 * Telefon. `seite` sagt, wo das Telefon steht – abwechselnd, damit die
 * Szenen nicht gleich aussehen.
 */
const Arbeitsplatz: React.FC<{
  zeilen: Zeile[];
  notiz: string;
  desktop: {zustaende: Zustand[]; titel: string; start?: number};
  telefon?: {zustaende: Zustand[]; tipps?: Tipp[]; start?: number};
  seite?: 'rechts' | 'links';
  kamera?: Kamera;
}> = ({zeilen, notiz, desktop, telefon, seite = 'rechts', kamera}) => {
  const frame = useCurrentFrame();
  const fenster = useAufstieg(desktop.start ?? 2, 90);
  const tel = useAufstieg(telefon?.start ?? 10, 115);
  const zoom = kamera
    ? interpolate(frame, [kamera.start ?? 20, kamera.ende ?? 100], [kamera.von ?? 1, kamera.bis], {...klemmen, easing: Easing.inOut(Easing.cubic)})
    : 1;
  const rechts = seite === 'rechts';
  const quer = useQuer();
  /* Hoch: Fenster unter der Überschrift, Telefon davor. Quer: beides rechts neben der Überschrift. */
  const l = quer
    ? {
        fenster: rechts ? {left: 860, top: 190, width: 980} : {left: 920, top: 190, width: 960},
        tel: {left: rechts ? 1590 : 830, top: 380, breite: 280},
      }
    : {fenster: {left: 70, top: 680, width: 940}, tel: {left: rechts ? 640 : 26, top: 1000, breite: 380}};
  return (
    <AbsoluteFill>
      <Titel zeilen={zeilen} notiz={notiz} />
      <div
        style={{
          position: 'absolute', left: l.fenster.left, top: l.fenster.top, width: l.fenster.width, perspective: 2400,
          transform: `scale(${zoom})`, transformOrigin: ursprungFuer(kamera?.ursprung, quer),
        }}
      >
        <Browser
          zustaende={desktop.zustaende}
          breite={l.fenster.width}
          titel={desktop.titel}
          style={{
            position: 'relative', transformOrigin: rechts ? 'left center' : 'right center',
            transform: `translateY(${(1 - fenster) * 420}px) rotateY(${(rechts ? 9 : -9) * (0.6 + 0.4 * fenster)}deg) rotateX(${(1 - fenster) * 18}deg)`,
            opacity: Math.min(1, fenster * 2),
          }}
        />
      </div>
      {telefon ? (
        <Telefon
          breite={l.tel.breite}
          zustaende={telefon.zustaende}
          tipps={telefon.tipps}
          style={{
            left: l.tel.left, top: l.tel.top,
            transform: `translateY(${(1 - tel) * 760}px) rotate(${(rechts ? 3 : -3) + (1 - tel) * (rechts ? 8 : -8)}deg)`,
          }}
        />
      ) : null}
    </AbsoluteFill>
  );
};

/** Nur der Schreibtisch, groß – für Seiten, die man am Telefon selten aufmacht. */
const GrossesFenster: React.FC<{zeilen: Zeile[]; notiz: string; zustaende: Zustand[]; titel: string; kamera: Kamera}> = ({
  zeilen,
  notiz,
  zustaende,
  titel,
  kamera,
}) => {
  const frame = useCurrentFrame();
  const p = useAufstieg(2, 90);
  const quer = useQuer();
  const zoom = interpolate(frame, [kamera.start ?? 10, kamera.ende ?? 90], [kamera.von ?? 1.1, kamera.bis], {...klemmen, easing: Easing.inOut(Easing.cubic)});
  const l = quer ? {left: 860, top: 170, width: 1000} : {left: 40, top: 720, width: 1000};
  return (
    <AbsoluteFill>
      <Titel zeilen={zeilen} notiz={notiz} />
      <div style={{position: 'absolute', left: l.left, top: l.top, width: l.width, perspective: 2400, transform: `scale(${zoom})`, transformOrigin: ursprungFuer(kamera.ursprung, quer)}}>
        <Browser
          zustaende={zustaende}
          breite={l.width}
          titel={titel}
          style={{position: 'relative', transformOrigin: 'center top', transform: `translateY(${(1 - p) * 420}px) rotateX(${(1 - p) * 22}deg)`}}
        />
      </div>
    </AbsoluteFill>
  );
};

/* ------------------------------------------------------------- Szenen --- */

/**
 * Für wen das ist – gleich nach dem Haken, auf dem Einschlag der Musik.
 * Erst das Logo, dann je Schlag eine Zielgruppe, dann wofür. Sechs
 * Sekunden: Wer hier weiterwischt, soll vorher wissen, ob er gemeint ist.
 */
const ZIELGRUPPEN = ['Golfpros,', 'Coaches', '& Academies.'];
/* Oberkante des Blocks – so, dass er in der Mitte der sicheren Zone steht. */
const ZG_OBEN_HOCH = 430;
const ZG_OBEN_QUER = 140;

export const Zielgruppe: React.FC = () => {
  const frame = useCurrentFrame();
  const {fps} = useVideoConfig();
  const ball = useAufstieg(1, 200);
  const bogen = interpolate(frame, [3, 18], [0, 1], {...klemmen, easing: Easing.inOut(Easing.cubic)});
  const zug = useAufstieg(7, 150);
  const auftritt = (start: number) => spring({frame: frame - start, fps, config: {damping: 16, stiffness: 150, mass: 0.7}});
  const ZG_OBEN = useQuer() ? ZG_OBEN_QUER : ZG_OBEN_HOCH;
  const fuer = auftritt(16);
  const zeile: React.CSSProperties = {
    position: 'absolute', left: 0, right: 0, textAlign: 'center', fontFamily: schrift.titel, letterSpacing: '-0.035em', overflow: 'hidden',
  };
  return (
    <AbsoluteFill>
      <div style={{position: 'absolute', top: ZG_OBEN, left: 0, right: 0, display: 'flex', justifyContent: 'center'}}>
        <Logo groesse={120} ball={ball} bogen={bogen} schriftzug={zug} />
      </div>
      <div style={{...zeile, top: ZG_OBEN + 256, fontWeight: 650, fontSize: 84, lineHeight: 1.15, color: farbe.chalk}}>
        <div style={{transform: `translateY(${(1 - fuer) * 110}%)`}}>Die App für</div>
      </div>
      {ZIELGRUPPEN.map((g, i) => {
        const p = auftritt(30 + i * 15);
        return (
          <div key={g} style={{...zeile, top: ZG_OBEN + 366 + i * 124, fontWeight: 800, fontSize: 118, lineHeight: 1.08, color: farbe.signal}}>
            <div style={{transform: `translateY(${(1 - p) * 110}%)`}}>{g}</div>
          </div>
        );
      })}
      <div style={{position: 'absolute', top: ZG_OBEN + 760, left: 0, right: 0, display: 'flex', justifyContent: 'center'}}>
        <Notiz text="für alles neben dem Unterricht" start={84} groesse={74} mitte farbeText={farbe.sand} />
      </div>
    </AbsoluteFill>
  );
};

/*
 * Lesbar vor schnell: Erst steht die Überschrift (ANFANG), dann bewegt sich
 * der Bildschirm, und vor dem Schnitt hält er still (RUHE vor dem Ende –
 * eine Sekunde Stillstand plus der halbe Übergang). Alle Zeiten hängen an
 * der Länge der Szene, damit eine längere Szene nicht nur länger steht.
 */
const ANFANG = 30;
const RUHE = 45;
type Laenge = {dauer: number};

export const Kalender2: React.FC<Laenge> = ({dauer}) => (
  <Arbeitsplatz
    zeilen={[{text: 'Alles landet in'}, {text: 'deinem Kalender.', farbe: farbe.signal}]}
    notiz="am Schreibtisch und unterwegs"
    desktop={{zustaende: [{screen: 'kalender', ab: 0}], titel: 'Kalender · Golf Academy Bergmann'}}
    telefon={{zustaende: [{screen: 'kalender-tag', ab: 0, scroll: [[ANFANG, 0], [dauer - RUHE, 300]]}]}}
    kamera={{bis: 1.18, ursprung: '30% 30%', start: ANFANG, ende: dauer - RUHE}}
  />
);

export const Dashboard2: React.FC<Laenge> = ({dauer}) => (
  <Arbeitsplatz
    zeilen={[{text: 'Dein Tag.'}, {text: 'Sortiert.', farbe: farbe.signal}]}
    notiz="Umsatz, Termine, Aufgaben"
    seite="links"
    desktop={{zustaende: [{screen: 'dashboard-desk', ab: 0, scroll: [[ANFANG + 5, 0], [dauer - RUHE, 470]]}], titel: 'Dashboard · Golf Academy Bergmann'}}
    telefon={{zustaende: [{screen: 'dashboard', ab: 0}]}}
  />
);

export const Kunde2: React.FC<Laenge> = ({dauer}) => {
  const k = aufnahme('kunde');
  const handicap = Math.max(0, k.marken.handicap.y - 130);
  return (
    <Arbeitsplatz
      zeilen={[{text: 'Jeder Schüler'}, {text: 'auf einen Blick.', farbe: farbe.signal}]}
      notiz="Handicap · Pakete · Verlauf"
      desktop={{zustaende: [{screen: 'kunde-desk', ab: 0, scroll: [[ANFANG + 5, 0], [dauer - RUHE, 330]]}], titel: 'Thomas Berger · Golf Academy Bergmann'}}
      telefon={{
        zustaende: [{screen: 'kunde', ab: 0, scroll: [[ANFANG, 0], [ANFANG + 25, 380], [ANFANG + 45, 380], [dauer - RUHE, handicap]]}],
      }}
    />
  );
};

export const Training2: React.FC<Laenge> = ({dauer}) => (
  <Arbeitsplatz
    zeilen={[{text: 'Trainingspläne'}, {text: 'für jeden Schüler.', farbe: farbe.signal}]}
    notiz="Woche für Woche, Übung für Übung"
    seite="links"
    desktop={{zustaende: [{screen: 'plan-desk', ab: 0, scroll: [[ANFANG + 5, 0], [dauer - RUHE, 380]]}], titel: '4 Wochen Putting-Grundlagen · Golf Academy Bergmann'}}
    telefon={{zustaende: [{screen: 'plan', ab: 0, scroll: [[ANFANG, 0], [dauer - RUHE, 900]]}]}}
  />
);

export const Leads2: React.FC<Laenge> = ({dauer}) => (
  <GrossesFenster
    zeilen={[{text: 'Aus Anfragen'}, {text: 'werden Schüler.', farbe: farbe.signal}]}
    notiz="jede Anfrage landet in der Pipeline"
    zustaende={[{screen: 'leads-desk', ab: 0}]}
    titel="Leads · Golf Academy Bergmann"
    kamera={{von: 1.08, bis: 1.5, ursprung: '40% 35%', start: ANFANG - 10, ende: dauer - RUHE}}
  />
);

export const Website2: React.FC<Laenge> = ({dauer}) => (
  <Arbeitsplatz
    zeilen={[{text: 'Deine Website.'}, {text: 'Ohne Agentur.', farbe: farbe.signal}]}
    notiz="Text anklicken, ändern, fertig."
    desktop={{zustaende: [{screen: 'baukasten', ab: 0}, {screen: 'website-desk', ab: Math.round(dauer / 2)}], titel: 'Startseite · Golf Academy Bergmann'}}
    telefon={{zustaende: [{screen: 'website', ab: 0}], start: 16}}
  />
);

export const Reisen2: React.FC<Laenge> = ({dauer}) => {
  const r = aufnahme('reise');
  return (
    <Arbeitsplatz
      zeilen={[{text: 'Golfreisen'}, {text: 'wie im Reisebüro.', farbe: farbe.signal}]}
      notiz="Buchung für bis zu sechs Reisende"
      seite="links"
      desktop={{zustaende: [{screen: 'reise-desk', ab: 0}], titel: 'Golfwoche Mallorca · Golf Academy Bergmann'}}
      telefon={{zustaende: [{screen: 'reise', ab: 0, scroll: [[ANFANG + 10, 0], [dauer - RUHE, Math.max(0, r.marken.bilder.y - 200)]]}]}}
      kamera={{bis: 1.22, ursprung: '75% 20%', start: ANFANG, ende: dauer - RUHE}}
    />
  );
};

export const Rechnungen2: React.FC<Laenge> = () => {
  const r = aufnahme('rechnungen');
  const ziel = r.scrollZiel ?? 0;
  return (
    <Arbeitsplatz
      zeilen={[{text: 'Rechnung?'}, {text: 'Ein Klick.', farbe: farbe.signal}]}
      notiz="aus allen offenen Terminen"
      desktop={{zustaende: [{screen: 'rechnung-desk', ab: 0}], titel: 'Rechnung · Golf Academy Bergmann', start: 72}}
      telefon={{
        start: 0,
        zustaende: [
          {screen: 'rechnungen', ab: 0, scroll: [[20, 0], [48, ziel]]},
          {screen: 'rechnung-neu', ab: 65},
        ],
        tipps: [{frame: 62, ...mitte(r.marken.knopf, ziel)}],
      }}
    />
  );
};

export const Auswertung2: React.FC<Laenge> = ({dauer}) => (
  <GrossesFenster
    zeilen={[{text: 'Zahlen,'}, {text: 'die du verstehst.', farbe: farbe.signal}]}
    notiz="Umsatz, Auslastung, Kunden"
    zustaende={[{screen: 'auswertung-desk', ab: 0, scroll: [[ANFANG, 0], [dauer - RUHE, 430]]}]}
    titel="Auswertung · Golf Academy Bergmann"
    kamera={{von: 1.1, bis: 1.32, ursprung: '55% 40%', start: 10, ende: dauer - RUHE}}
  />
);

export const Portal2: React.FC<Laenge> = () => (
  <AbsoluteFill>
    <Titel zeilen={[{text: 'Deine Schüler'}, {text: 'haben ihr Portal.', farbe: farbe.signal}]} notiz="Termine, Training, Unterlagen" notizStart={16} />
    <Funktionstelefon start={2} zustaende={[{screen: 'portal', ab: 0}]} />
  </AbsoluteFill>
);
