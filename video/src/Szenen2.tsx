/**
 * Szenen der langen Fassung (45 s, mit Musik): jede Funktion am Schreibtisch
 * und auf dem Telefon. Alle Bildschirme sind Aufnahmen aus der App
 * (aufnahme/aufnehmen.js) – die Desktop-Seiten scrollen mit fester
 * Seitenleiste und Kopfzeile, wie im Browser.
 */
import React from 'react';
import {AbsoluteFill, Easing, interpolate, useCurrentFrame} from 'remotion';
import {aufnahme, farbe, mitte} from './marke';
import {Funktionstelefon, useAufstieg} from './Szenen';
import {Browser} from './teile/Browser';
import {Telefon, type Tipp, type Zustand} from './teile/Telefon';
import {Titel, type Zeile} from './teile/Titel';

const klemmen = {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'} as const;

type Kamera = {bis: number; ursprung: string; von?: number; start?: number; ende?: number};

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
  return (
    <AbsoluteFill>
      <Titel zeilen={zeilen} notiz={notiz} />
      <div
        style={{
          position: 'absolute', left: 70, top: 680, width: 940, perspective: 2400,
          transform: `scale(${zoom})`, transformOrigin: kamera?.ursprung ?? 'center',
        }}
      >
        <Browser
          zustaende={desktop.zustaende}
          breite={940}
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
          breite={380}
          zustaende={telefon.zustaende}
          tipps={telefon.tipps}
          style={{
            left: rechts ? 640 : 26, top: 1000,
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
  const zoom = interpolate(frame, [kamera.start ?? 10, kamera.ende ?? 90], [kamera.von ?? 1.1, kamera.bis], {...klemmen, easing: Easing.inOut(Easing.cubic)});
  return (
    <AbsoluteFill>
      <Titel zeilen={zeilen} notiz={notiz} />
      <div style={{position: 'absolute', left: 40, top: 720, width: 1000, perspective: 2400, transform: `scale(${zoom})`, transformOrigin: kamera.ursprung}}>
        <Browser
          zustaende={zustaende}
          breite={1000}
          titel={titel}
          style={{position: 'relative', transformOrigin: 'center top', transform: `translateY(${(1 - p) * 420}px) rotateX(${(1 - p) * 22}deg)`}}
        />
      </div>
    </AbsoluteFill>
  );
};

/* ------------------------------------------------------------- Szenen --- */

export const Kalender2: React.FC = () => (
  <Arbeitsplatz
    zeilen={[{text: 'Alles landet in'}, {text: 'deinem Kalender.', farbe: farbe.signal}]}
    notiz="am Schreibtisch und unterwegs"
    desktop={{zustaende: [{screen: 'kalender', ab: 0}], titel: 'Kalender · Golf Academy Bergmann'}}
    telefon={{zustaende: [{screen: 'kalender-tag', ab: 0, scroll: [[24, 0], [96, 300]]}]}}
    kamera={{bis: 1.18, ursprung: '30% 30%'}}
  />
);

export const Dashboard2: React.FC = () => (
  <Arbeitsplatz
    zeilen={[{text: 'Dein Tag.'}, {text: 'Sortiert.', farbe: farbe.signal}]}
    notiz="Umsatz, Termine, Aufgaben"
    seite="links"
    desktop={{zustaende: [{screen: 'dashboard-desk', ab: 0, scroll: [[28, 0], [96, 470]]}], titel: 'Dashboard · Golf Academy Bergmann'}}
    telefon={{zustaende: [{screen: 'dashboard', ab: 0}]}}
  />
);

export const Kunde2: React.FC = () => {
  const k = aufnahme('kunde');
  const handicap = Math.max(0, k.marken.handicap.y - 130);
  return (
    <Arbeitsplatz
      zeilen={[{text: 'Jeder Schüler'}, {text: 'auf einen Blick.', farbe: farbe.signal}]}
      notiz="Handicap · Pakete · Verlauf"
      desktop={{zustaende: [{screen: 'kunde-desk', ab: 0, scroll: [[34, 0], [108, 330]]}], titel: 'Thomas Berger · Golf Academy Bergmann'}}
      telefon={{zustaende: [{screen: 'kunde', ab: 0, scroll: [[22, 0], [46, 380], [60, 380], [108, handicap]]}]}}
    />
  );
};

export const Training2: React.FC = () => (
  <Arbeitsplatz
    zeilen={[{text: 'Trainingspläne'}, {text: 'für jeden Schüler.', farbe: farbe.signal}]}
    notiz="Woche für Woche, Übung für Übung"
    seite="links"
    desktop={{zustaende: [{screen: 'plan-desk', ab: 0, scroll: [[28, 0], [96, 380]]}], titel: '4 Wochen Putting-Grundlagen · Golf Academy Bergmann'}}
    telefon={{zustaende: [{screen: 'plan', ab: 0, scroll: [[20, 0], [96, 900]]}]}}
  />
);

export const Leads2: React.FC = () => (
  <GrossesFenster
    zeilen={[{text: 'Aus Anfragen'}, {text: 'werden Schüler.', farbe: farbe.signal}]}
    notiz="jede Anfrage landet in der Pipeline"
    zustaende={[{screen: 'leads-desk', ab: 0}]}
    titel="Leads · Golf Academy Bergmann"
    kamera={{von: 1.08, bis: 1.5, ursprung: '40% 35%'}}
  />
);

export const Website2: React.FC = () => (
  <Arbeitsplatz
    zeilen={[{text: 'Deine Website.'}, {text: 'Ohne Agentur.', farbe: farbe.signal}]}
    notiz="Text anklicken, ändern, fertig."
    desktop={{zustaende: [{screen: 'baukasten', ab: 0}, {screen: 'website-desk', ab: 62}], titel: 'Startseite · Golf Academy Bergmann'}}
    telefon={{zustaende: [{screen: 'website', ab: 0}], start: 16}}
  />
);

export const Reisen2: React.FC = () => {
  const r = aufnahme('reise');
  return (
    <Arbeitsplatz
      zeilen={[{text: 'Golfreisen'}, {text: 'wie im Reisebüro.', farbe: farbe.signal}]}
      notiz="Buchung für bis zu sechs Reisende"
      seite="links"
      desktop={{zustaende: [{screen: 'reise-desk', ab: 0}], titel: 'Golfwoche Mallorca · Golf Academy Bergmann'}}
      telefon={{zustaende: [{screen: 'reise', ab: 0, scroll: [[34, 0], [104, Math.max(0, r.marken.bilder.y - 200)]]}]}}
      kamera={{bis: 1.22, ursprung: '75% 20%'}}
    />
  );
};

export const Rechnungen2: React.FC = () => {
  const r = aufnahme('rechnungen');
  const ziel = r.scrollZiel ?? 0;
  return (
    <Arbeitsplatz
      zeilen={[{text: 'Rechnung?'}, {text: 'Ein Klick.', farbe: farbe.signal}]}
      notiz="aus allen offenen Terminen"
      desktop={{zustaende: [{screen: 'rechnung-desk', ab: 0}], titel: 'Rechnung · Golf Academy Bergmann', start: 58}}
      telefon={{
        start: 0,
        zustaende: [
          {screen: 'rechnungen', ab: 0, scroll: [[10, 0], [38, ziel]]},
          {screen: 'rechnung-neu', ab: 54},
        ],
        tipps: [{frame: 51, ...mitte(r.marken.knopf, ziel)}],
      }}
    />
  );
};

export const Auswertung2: React.FC = () => (
  <GrossesFenster
    zeilen={[{text: 'Zahlen,'}, {text: 'die du verstehst.', farbe: farbe.signal}]}
    notiz="Umsatz, Auslastung, Kunden"
    zustaende={[{screen: 'auswertung-desk', ab: 0, scroll: [[22, 0], [86, 430]]}]}
    titel="Auswertung · Golf Academy Bergmann"
    kamera={{von: 1.1, bis: 1.32, ursprung: '55% 40%'}}
  />
);

export const Portal2: React.FC = () => (
  <AbsoluteFill>
    <Titel zeilen={[{text: 'Deine Schüler'}, {text: 'haben ihr Portal.', farbe: farbe.signal}]} notiz="Termine, Training, Unterlagen" notizStart={16} />
    <Funktionstelefon start={2} zustaende={[{screen: 'portal', ab: 0}]} />
  </AbsoluteFill>
);
