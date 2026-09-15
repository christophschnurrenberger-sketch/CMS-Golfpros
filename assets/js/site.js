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
