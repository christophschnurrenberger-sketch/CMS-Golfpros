/**
 * Ob Geräusche (Fingertipp, Wischen) zu hören sind. Die kurze Fassung bleibt
 * stumm; die lange schaltet den Ton für ihre Szenen ein.
 */
import React, {createContext, useContext} from 'react';

const TonKontext = createContext(false);

export const MitTon: React.FC<{children: React.ReactNode}> = ({children}) => (
  <TonKontext.Provider value>{children}</TonKontext.Provider>
);

export const useTon = () => useContext(TonKontext);
