/*
 * Die Seite einer Golfreise – was JavaScript dort dazutut.
 *
 * Ohne dieses Skript ist die Seite vollständig: Die Bilder öffnen als
 * Datei, alle sechs Personenzeilen stehen da, und „Preis neu berechnen"
 * lädt die Seite mit der Rechnung neu. Das Skript macht daraus eine
 * Bildergalerie im Fenster, blendet leere Personen aus und rechnet den
 * Preis bei jeder Änderung – beim Server, der ihn auch beim Buchen
 * rechnet. Eine zweite Preislogik gibt es hier absichtlich nicht.
 */
(function () {
  'use strict';

  /* ------------------------------------------------ Bildergalerie --- */

  /*
   * Ein Fenster für alle Bilder einer Gruppe: Pfeile, Pfeiltasten,
   * Wischen, Escape. Das dialog-Element bringt Fokusfalle und Escape
   * selbst mit; ohne dialog bleibt es bei den Links auf die Bilder.
   */
  var gruppen = {};
  document.querySelectorAll('[data-bildfenster]').forEach(function (a) {
    var g = a.getAttribute('data-bildfenster');
    (gruppen[g] = gruppen[g] || []).push(a);
  });

  var fenster = document.createElement('dialog');
  if (Object.keys(gruppen).length && typeof fenster.showModal === 'function') {
    var pfeil = function (d) {
      return '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"'
           + ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="' + d + '"/></svg>';
    };
    fenster.className = 'bildfenster';
    fenster.setAttribute('aria-label', 'Bilder');
    fenster.innerHTML =
        '<figure class="bildfenster__bild"><img alt=""></figure>'
      + '<p class="bildfenster__zahl" aria-live="polite"></p>'
      + '<button type="button" class="bildfenster__knopf bildfenster__zu" aria-label="Schließen">' + pfeil('M6 6l12 12M18 6L6 18') + '</button>'
      + '<button type="button" class="bildfenster__knopf bildfenster__zurueck" aria-label="Vorheriges Bild">' + pfeil('M15 5l-7 7 7 7') + '</button>'
      + '<button type="button" class="bildfenster__knopf bildfenster__weiter" aria-label="Nächstes Bild">' + pfeil('M9 5l7 7-7 7') + '</button>';
    document.body.appendChild(fenster);

    var bild = fenster.querySelector('img');
    var zahl = fenster.querySelector('.bildfenster__zahl');
    var liste = [];
    var stelle = 0;

    var zeigen = function (n) {
      stelle = (n + liste.length) % liste.length;
      bild.src = liste[stelle].getAttribute('href');
      zahl.textContent = (stelle + 1) + ' / ' + liste.length;
      fenster.classList.toggle('ist-einzeln', liste.length < 2);
      /* Das nächste Bild schon laden – sonst wartet man bei jedem Pfeil. */
      if (liste.length > 1) { (new Image()).src = liste[(stelle + 1) % liste.length].getAttribute('href'); }
    };
    var oeffnen = function (gruppe, n) {
      liste = gruppen[gruppe] || [];
      if (!liste.length) return;
      zeigen(n);
      document.documentElement.classList.add('ist-bildfenster');
      fenster.showModal();
    };
    fenster.addEventListener('close', function () {
      document.documentElement.classList.remove('ist-bildfenster');
      bild.removeAttribute('src');
    });

    document.addEventListener('click', function (e) {
      var start = e.target.closest('[data-bildfenster-start]');
      var a = start ? null : e.target.closest('[data-bildfenster]');
      if (start) {
        e.preventDefault();
        oeffnen(start.getAttribute('data-bildfenster-start'), 0);
      } else if (a) {
        e.preventDefault();
        var g = a.getAttribute('data-bildfenster');
        oeffnen(g, gruppen[g].indexOf(a));
      }
    });

    fenster.addEventListener('click', function (e) {
      if (e.target.closest('.bildfenster__zu') || e.target === fenster) { fenster.close(); }
      else if (e.target.closest('.bildfenster__zurueck')) { zeigen(stelle - 1); }
      else if (e.target.closest('.bildfenster__weiter')) { zeigen(stelle + 1); }
    });
    fenster.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowLeft') { zeigen(stelle - 1); }
      if (e.key === 'ArrowRight') { zeigen(stelle + 1); }
    });

    /* Wischen auf dem Telefon: waagerecht mehr als 50 Pixel und mehr als senkrecht. */
    var von = null;
    fenster.addEventListener('pointerdown', function (e) { von = { x: e.clientX, y: e.clientY }; });
    fenster.addEventListener('pointerup', function (e) {
      if (!von) return;
      var dx = e.clientX - von.x, dy = e.clientY - von.y;
      von = null;
      if (Math.abs(dx) > 50 && Math.abs(dx) > Math.abs(dy)) { zeigen(stelle + (dx < 0 ? 1 : -1)); }
    });
  }

  /* Viele Bilder: fünf zeigen, auf dem fünften steht, wie viele noch kommen. */
  document.querySelectorAll('[data-reisegalerie]').forEach(function (g) {
    if (g.children.length > 5) g.classList.add('ist-kompakt');
  });

  /* ------------------------------------------------- Das Formular --- */

  var form = document.querySelector('[data-reiseformular]');
  if (!form) return;

  form.querySelectorAll('[data-ohne-skript]').forEach(function (el) { el.hidden = true; });

  var zeilen = Array.prototype.slice.call(form.querySelectorAll('[data-reisender]'));
  var neu = form.querySelector('[data-reisender-neu]');
  var felder = function (z) { return Array.prototype.slice.call(z.querySelectorAll('input, select')); };
  var istLeer = function (z) {
    return felder(z).every(function (f) { return f.tagName === 'SELECT' || f.value.trim() === ''; });
  };
  var sichtbar = function () { return zeilen.filter(function (z) { return !z.hidden; }); };

  /* Eine sichtbare Person braucht einen Namen; eine ausgeblendete zählt nicht. */
  var pflichtSetzen = function () {
    zeilen.forEach(function (z, i) {
      if (i === 0) return;
      z.querySelectorAll('input[name$="[vorname]"], input[name$="[nachname]"]').forEach(function (f) {
        f.required = !z.hidden;
      });
      var weg = z.querySelector('[data-reisender-weg]');
      if (weg) weg.hidden = z.hidden;
    });
    if (neu) neu.hidden = sichtbar().length >= zeilen.length;
    extrasBegrenzen();
  };

  /* Wer nicht Golf spielt, hat kein Handicap – das Feld tritt zurück. */
  var hcpZeigen = function (z) {
    var wahl = z.querySelector('[data-golfer]');
    var feld = z.querySelector('[data-hcp-feld]');
    if (!wahl || !feld) return;
    var golfer = wahl.value !== '0';
    feld.classList.toggle('ist-aus', !golfer);
    feld.querySelector('input').disabled = !golfer;
  };

  /* Eine Zusatzleistung je Person gibt es höchstens so oft, wie Personen reisen. */
  function extrasBegrenzen() {
    var n = sichtbar().length;
    form.querySelectorAll('[data-extra-person]').forEach(function (s) {
      Array.prototype.forEach.call(s.options, function (o) { o.disabled = parseInt(o.value, 10) > n; });
      if (parseInt(s.value, 10) > n) s.value = String(n);
    });
  }

  zeilen.forEach(function (z, i) {
    if (i > 0) {
      z.hidden = istLeer(z);
      var opt = z.querySelector('.reisender__optional');
      if (opt) opt.remove();
    }
    hcpZeigen(z);
  });
  if (neu) {
    neu.hidden = false;
    neu.addEventListener('click', function () {
      var naechste = zeilen.filter(function (z) { return z.hidden; })[0];
      if (!naechste) return;
      naechste.hidden = false;
      pflichtSetzen();
      naechste.querySelector('input').focus();
      rechnen();
    });
  }

  /*
   * Entfernen rückt die folgenden Personen nach oben, statt eine Lücke zu
   * lassen – sonst stünde „Person 4" unter „Person 2".
   */
  form.addEventListener('click', function (e) {
    var weg = e.target.closest('[data-reisender-weg]');
    if (!weg) return;
    var i = zeilen.indexOf(weg.closest('[data-reisender]'));
    var offen = sichtbar().length;
    for (var k = i; k < offen - 1; k++) {
      var ziel = felder(zeilen[k]), quelle = felder(zeilen[k + 1]);
      ziel.forEach(function (f, j) { f.value = quelle[j].value; });
      hcpZeigen(zeilen[k]);
    }
    var letzte = zeilen[offen - 1];
    felder(letzte).forEach(function (f) { f.value = f.tagName === 'SELECT' ? '1' : ''; });
    hcpZeigen(letzte);
    letzte.hidden = true;
    pflichtSetzen();
    (neu && !neu.hidden ? neu : form.querySelector('input')).focus();
    rechnen();
  });

  /* ------------------------------------------------ Die Rechnung --- */

  var ziel = form.querySelector('[data-reiserechnung]');
  var knopf = form.querySelector('[data-reise-absenden]');
  var vorschau = knopf && knopf.disabled;
  var zeitgeber = null;
  var laufend = null;

  function rechnen() {
    clearTimeout(zeitgeber);
    zeitgeber = setTimeout(abfragen, 160);
  }

  function abfragen() {
    if (!ziel || !window.fetch) return;
    var daten = new FormData(form);
    daten.set('aktion', 'rechnen');
    daten.set('format', 'json');
    daten.set('personen', String(sichtbar().length));
    if (laufend) laufend.abort();
    laufend = window.AbortController ? new AbortController() : null;
    ziel.classList.add('ist-rechnend');
    fetch(form.action, {
      method: 'POST', body: daten, credentials: 'same-origin',
      headers: { Accept: 'application/json' }, signal: laufend ? laufend.signal : undefined
    })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) {
        ziel.classList.remove('ist-rechnend');
        if (!d) return;
        ziel.innerHTML = d.html;
        if (knopf && !vorschau) {
          knopf.textContent = d.knopf;
          knopf.disabled = !d.moeglich;
        }
      })
      .catch(function () { ziel.classList.remove('ist-rechnend'); });
  }

  form.addEventListener('change', function (e) {
    var z = e.target.closest('[data-reisender]');
    if (z && e.target.matches('[data-golfer]')) hcpZeigen(z);
    rechnen();
  });

  /* Die erste Rechnung hat der Server schon mitgeschickt – erst bei einer Änderung fragen. */
  pflichtSetzen();

  /* ---------------------------------------- Leiste auf dem Telefon --- */

  /* Die Leiste mit dem Preis verschwindet, sobald das Formular zu sehen ist –
     zweimal „Buchen" übereinander ist einmal zu viel. */
  var leiste = document.querySelector('[data-reiseleiste]');
  var buchen = document.getElementById('buchen');
  if (leiste && buchen && 'IntersectionObserver' in window) {
    new IntersectionObserver(function (eintraege) {
      leiste.classList.toggle('ist-weg', eintraege[0].isIntersecting);
    }, { rootMargin: '0px 0px -30% 0px' }).observe(buchen);
  }
})();
