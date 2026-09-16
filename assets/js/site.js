/* Öffentliche Website – das Wenige, was JavaScript braucht. */
(function () {
  'use strict';

  /* Sanftes Scrollen zu Ankern, ohne die Adresszeile zu fluten */
  document.addEventListener('click', function (e) {
    var a = e.target.closest('a[href^="#"]');
    if (!a) return;
    var ziel = document.querySelector(a.getAttribute('href'));
    if (!ziel) return;
    e.preventDefault();
    ziel.scrollIntoView({ behavior: 'smooth', block: 'start' });
    var navi = document.querySelector('.kopf__navi.ist-offen');
    if (navi) navi.classList.remove('ist-offen');
  });

  /*
   * Fragen und Antworten.
   *
   * Ohne JavaScript stehen alle Antworten offen da – das ist nicht nur
   * eine Notlösung, sondern für Suchmaschinen sogar die bessere Fassung.
   * Erst dieses Skript klappt sie zu; deshalb steht die Klasse hier und
   * nicht im HTML.
   */
  document.querySelectorAll('.frage').forEach(function (f, i) {
    f.classList.add('ist-zu');
    if (i === 0) f.classList.add('ist-offen');
  });
  document.addEventListener('click', function (e) {
    var k = e.target.closest('[data-frage]');
    if (!k) return;
    k.parentElement.classList.toggle('ist-offen');
  });

  /*
   * Der Buchungskalender.
   *
   * Ohne dieses Skript stehen alle freien Tage mit ihren Uhrzeiten
   * untereinander – vollständig, nur lang. Das ist die Fassung, die
   * Suchmaschinen und Vorleseprogramme sehen, und sie funktioniert auch
   * ohne einen einzigen Klick.
   *
   * Mit Skript wird daraus ein Kalender: Ein Klick auf einen freien Tag
   * öffnet ein Fenster mit genau dessen Uhrzeiten. Warum ein Fenster und
   * nicht eine Liste unter dem Gitter: Auf dem Telefon steht die Liste
   * sonst unter der Falz – man tippt auf den 22., und scheinbar passiert
   * nichts. Das Fenster liegt vor dem Kalender, egal wie groß der Bildschirm
   * ist.
   *
   * Die Liste wird verschoben, nicht kopiert: Kopiert stünden dieselben
   * Kennungen zweimal im Dokument, und aria-controls zeigte auf zwei
   * Elemente gleichzeitig.
   */
  document.querySelectorAll('[data-buchkal]').forEach(function (kal) {
    var listen = Array.prototype.slice.call(kal.querySelectorAll('.buchkal__tagzeiten'));
    var tage   = Array.prototype.slice.call(kal.querySelectorAll('.buchkal__tag[data-tag]'));
    var heim   = kal.querySelector('.buchkal__zeiten');
    if (!listen.length || !heim) return;

    function markieren(tag) {
      tage.forEach(function (t) {
        var ist = t.dataset.tag === tag;
        t.classList.toggle('ist-gewaehlt', ist);
        t.setAttribute('aria-expanded', ist ? 'true' : 'false');
      });
    }

    var fenster = document.createElement('dialog');

    /*
     * Kein dialog-Element, kein Fenster: Dann bleibt es beim Auf- und
     * Zuklappen unter dem Gitter. Lieber die ältere Fassung als gar
     * keine Uhrzeiten.
     */
    if (typeof fenster.showModal !== 'function') {
      var zeigen = function (tag) {
        listen.forEach(function (l) { l.hidden = l.dataset.tag !== tag; });
        markieren(tag);
      };
      zeigen(listen[0].dataset.tag);
      kal.addEventListener('click', function (e) {
        var t = e.target.closest('.buchkal__tag[data-tag]');
        if (t) zeigen(t.dataset.tag);
      });
      return;
    }

    fenster.className = 'buchkal__fenster';
    fenster.innerHTML = '<button type="button" class="buchkal__fenster-zu" aria-label="Schließen">'
                      + '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
                      + ' stroke-width="1.8" stroke-linecap="round" aria-hidden="true">'
                      + '<path d="M6 6l12 12M18 6L6 18"/></svg></button>'
                      + '<div class="buchkal__fenster-inhalt"></div>';
    kal.appendChild(fenster);
    kal.classList.add('ist-fenster');
    var inhalt = fenster.querySelector('.buchkal__fenster-inhalt');

    /* Alles wieder an seinen Platz – auch dann, wenn das Fenster über
       Escape oder den Hintergrund geschlossen wurde. */
    function zurueck() {
      while (inhalt.firstChild) { heim.appendChild(inhalt.firstChild); }
      listen.forEach(function (l) { l.hidden = true; });
      markieren('');
    }
    zurueck();

    function oeffnen(tag) {
      zurueck();
      var liste = null;
      listen.forEach(function (l) { if (l.dataset.tag === tag) liste = l; });
      if (!liste) return;

      liste.hidden = false;
      inhalt.appendChild(liste);
      markieren(tag);

      var datum = liste.querySelector('.buchkal__datum');
      fenster.setAttribute('aria-label', datum ? datum.textContent.trim() : 'Freie Zeiten');
      fenster.showModal();
    }

    kal.addEventListener('click', function (e) {
      if (e.target.closest('.buchkal__fenster-zu')) { fenster.close(); return; }
      var t = e.target.closest('.buchkal__tag[data-tag]');
      if (t) { oeffnen(t.dataset.tag); }
    });

    /* Klick auf den Hintergrund schließt – das Fenster selbst füllt nur
       seine Mitte, der Rest gehört dem dialog-Element. */
    fenster.addEventListener('click', function (e) {
      if (e.target === fenster) { fenster.close(); }
    });
    fenster.addEventListener('close', zurueck);
  });

  /* OpenStreetMap braucht eine Bounding-Box; die rechnen wir aus der Suche */
  document.querySelectorAll('.kartenrahmen iframe[data-suche]').forEach(function (rahmen) {
    var suche = rahmen.dataset.suche;
    if (!suche) return;
    fetch('https://nominatim.openstreetmap.org/search?format=json&limit=1&q=' + suche)
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (daten) {
        if (!daten || !daten[0]) return;
        var lat = parseFloat(daten[0].lat), lon = parseFloat(daten[0].lon), d = 0.006;
        rahmen.src = 'https://www.openstreetmap.org/export/embed.html?bbox='
          + (lon - d) + ',' + (lat - d / 1.7) + ',' + (lon + d) + ',' + (lat + d / 1.7)
          + '&layer=mapnik&marker=' + lat + ',' + lon;
      })
      .catch(function () { /* ohne Karte ist die Seite trotzdem vollständig */ });
  });

})();
