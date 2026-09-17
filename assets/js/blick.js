/**
 * Blick – die Seite lädt neu, der Blick bleibt, wo er war.
 *
 * An mehreren Stellen lädt ein Auswahlfeld die Seite neu, weil der Server
 * etwas Neues ausrechnen muss: die freien Zeiten zu einer anderen Leistung,
 * eine gefilterte Liste, ein anderer Zeitraum. Technisch ist das ein ganz
 * gewöhnlicher Seitenaufruf – und der beginnt im Browser immer oben.
 *
 * Für den, der das Feld gerade bedient hat, sieht das aus, als wäre die
 * Seite weggesprungen: Er stand mitten im Formular, hat eine andere
 * Leistung gewählt, und schaut plötzlich wieder auf die Überschrift.
 *
 * Deshalb merkt sich `merken()` vor dem Neuladen, wie weit das bediente
 * Feld unter der Oberkante des Fensters stand. Nach dem Laden rückt
 * `halten()` die Seite so zurecht, dass es wieder genau dort steht.
 *
 * Gemerkt wird der Abstand des Feldes, nicht die Rollhöhe der Seite: Über
 * dem Feld kann sich etwas geändert haben – eine Meldung ist dazugekommen,
 * eine Liste ist kürzer geworden –, und dann wäre dieselbe Rollhöhe der
 * falsche Ort. Das Feld ist der Fixpunkt, den der Benutzer im Auge hat.
 *
 * Eigene Datei, weil beide Oberflächen sie brauchen: die Anwendung
 * (app.js) und die öffentliche Website (site.js). Zwei Kopien desselben
 * Dutzends Zeilen laufen früher oder später auseinander.
 */
(function () {
  'use strict';

  var SCHLUESSEL = 'gp-blick';

  /* Nichts davon darf je einen Klick verschlucken: Im privaten Fenster
     wirft schon der Zugriff auf sessionStorage. */
  function lesen() {
    try {
      var roh = sessionStorage.getItem(SCHLUESSEL);
      sessionStorage.removeItem(SCHLUESSEL);
      return roh ? JSON.parse(roh) : null;
    } catch (e) {
      return null;
    }
  }

  /*
   * Das Feld wiederfinden – aber nur, wenn es eindeutig ist.
   *
   * Eine Kennung ist eindeutig, ein Feldname nicht: In einer Liste heißt
   * das Auswahlfeld jeder Zeile `status`. Auf das erste davon zu rollen
   * wäre schlimmer als gar nichts. Dann greift die gemerkte Rollhöhe.
   */
  function finden(marke) {
    if (!marke) return null;
    var el = document.getElementById(marke);
    if (el) return el;
    try {
      var treffer = document.querySelectorAll('[name="' + marke.replace(/"/g, '\\"') + '"]');
      return treffer.length === 1 ? treffer[0] : null;
    } catch (e) {
      return null;
    }
  }

  var Blick = {
    /** Vor einem Neuladen aufrufen, mit dem Feld, das es ausgelöst hat. */
    merken: function (el) {
      if (!el || typeof el.getBoundingClientRect !== 'function') return;
      var marke = el.id || el.name || '';
      if (!marke) return;
      try {
        sessionStorage.setItem(SCHLUESSEL, JSON.stringify({
          pfad:  location.pathname,
          marke: marke,
          oben:  Math.round(el.getBoundingClientRect().top),
          hoehe: Math.round(window.scrollY || window.pageYOffset || 0)
        }));
      } catch (e) {
        /* Ohne Sitzungsspeicher bleibt es beim Sprung nach oben – ärgerlich,
           aber nichts, wofür man eine Seite abbrechen lässt. */
      }
    },

    /**
     * Nach dem Laden: das gemerkte Feld wieder an seinen Platz rücken.
     *
     * Nur auf derselben Seite. Wer nach dem Wechsel woandershin geklickt
     * hat, soll dort nicht an eine fremde Stelle gerollt werden.
     */
    halten: function () {
      var stand = lesen();
      if (!stand || stand.pfad !== location.pathname) return;

      function ruecken() {
        /*
         * Erst die alte Rollhöhe – die stimmt, solange sich über dem Feld
         * nichts geändert hat, und sie braucht das Feld nicht.
         */
        window.scrollTo(0, stand.hoehe || 0);

        /*
         * Dann nachjustieren, wenn das Feld eindeutig wiederzufinden ist:
         * Über ihm kann eine Meldung dazugekommen oder eine Liste kürzer
         * geworden sein, und dann ist dieselbe Rollhöhe der falsche Ort.
         */
        var el = finden(stand.marke);
        if (!el) return;
        var weg = Math.round(el.getBoundingClientRect().top - stand.oben);
        if (Math.abs(weg) > 1) {
          window.scrollBy(0, weg);
        }
      }

      ruecken();
      /* Noch einmal, wenn Schriften und Bilder da sind: Bis dahin kann
         sich über dem Feld die Höhe geändert haben. Das Rücken ist
         wiederholbar – es zielt jedes Mal auf denselben Abstand. */
      window.addEventListener('load', ruecken, { once: true });
    }
  };

  window.Blick = Blick;
  Blick.halten();
})();
