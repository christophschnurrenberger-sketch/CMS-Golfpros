/* ==========================================================================
   GolfPro CMS – Oberflächenlogik
   --------------------------------------------------------------------------
   Kein Framework. Die Seiten kommen fertig vom Server; dieses Skript macht
   sie lebendig: Befehlspalette, Menüs, Dialoge, Ziehen und Ablegen, Thema.
   Alles hängt an data-Attributen, damit PHP nur Markup schreiben muss.
   ========================================================================== */

(function () {
  'use strict';

  const $  = (w, k) => (k || document).querySelector(w);
  const $$ = (w, k) => Array.from((k || document).querySelectorAll(w));

  /* ------------------------------------------------------------- Thema - */

  const Thema = {
    lesen() {
      try { return localStorage.getItem('gp-thema') || 'system'; } catch (e) { return 'system'; }
    },
    setzen(wert) {
      try { localStorage.setItem('gp-thema', wert); } catch (e) { /* privater Modus */ }
      Thema.anwenden(wert);
    },
    anwenden(wert) {
      const dunkel = wert === 'dunkel' ||
        (wert === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
      document.documentElement.setAttribute('data-theme', dunkel ? 'dunkel' : 'hell');
      $$('[data-thema-wert]').forEach(el => {
        el.classList.toggle('ist-aktiv', el.dataset.themaWert === wert);
      });
    },
    umschalten() {
      const jetzt = document.documentElement.getAttribute('data-theme');
      Thema.setzen(jetzt === 'dunkel' ? 'hell' : 'dunkel');
    }
  };
  Thema.anwenden(Thema.lesen());
  window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
    if (Thema.lesen() === 'system') Thema.anwenden('system');
  });

  /* --------------------------------------------------------- Menüs ---- */

  document.addEventListener('click', (e) => {
    const ausloeser = e.target.closest('[data-aufklapp]');
    if (ausloeser) {
      const huelle = ausloeser.closest('.aufklapp');
      const offen  = huelle.classList.contains('ist-offen');
      $$('.aufklapp.ist-offen').forEach(el => el.classList.remove('ist-offen'));
      if (!offen) huelle.classList.add('ist-offen');
      e.stopPropagation();
      return;
    }
    if (!e.target.closest('.aufklapp__menue')) {
      $$('.aufklapp.ist-offen').forEach(el => el.classList.remove('ist-offen'));
    }
  });

  /* ------------------------------------------------- Seitenleiste mobil */

  const leiste    = $('.seitenleiste');
  const verdunkler = $('.verdunkler');
  function leisteUm(auf) {
    if (!leiste) return;
    leiste.classList.toggle('ist-offen', auf);
    if (verdunkler) verdunkler.classList.toggle('ist-offen', auf);
    document.body.style.overflow = auf ? 'hidden' : '';
  }
  document.addEventListener('click', (e) => {
    if (e.target.closest('[data-menue-auf]')) { leisteUm(true); }
    else if (e.target.closest('[data-menue-zu]') || e.target === verdunkler) { leisteUm(false); }
  });

  /* ---------------------------------------------------------- Dialoge - */

  document.addEventListener('click', (e) => {
    const auf = e.target.closest('[data-modal-auf]');
    if (auf) {
      e.preventDefault();
      const d = document.getElementById(auf.dataset.modalAuf);
      if (d && typeof d.showModal === 'function') {
        // Werte vorbelegen: data-setz-<feldname>
        Object.keys(auf.dataset).forEach(k => {
          if (k.indexOf('setz') === 0 && k.length > 4) {
            const name = k.slice(4).toLowerCase();
            const feld = d.querySelector('[name="' + name + '"]');
            if (feld) feld.value = auf.dataset[k];
          }
        });
        const titel = auf.dataset.modalTitel;
        if (titel) { const h = d.querySelector('.modal__kopf h2'); if (h) h.textContent = titel; }
        d.showModal();
        const erstes = d.querySelector('input:not([type=hidden]):not([readonly]), textarea, select');
        if (erstes && window.innerWidth > 700) setTimeout(() => erstes.focus(), 60);
      }
      return;
    }
    const zu = e.target.closest('[data-modal-zu]');
    if (zu) { e.preventDefault(); const d = zu.closest('dialog'); if (d) d.close(); }
  });

  // Klick auf den Hintergrund schließt den Dialog.
  document.addEventListener('click', (e) => {
    if (e.target.tagName === 'DIALOG' && e.target.classList.contains('modal')) {
      const k = e.target.getBoundingClientRect();
      if (e.clientX < k.left || e.clientX > k.right || e.clientY < k.top || e.clientY > k.bottom) {
        e.target.close();
      }
    }
  });

  /* --------------------------------------------------- Rückfrage ------ */

  document.addEventListener('submit', (e) => {
    const frage = e.target.dataset.bestaetigen;
    if (frage && !window.confirm(frage)) { e.preventDefault(); }
  }, true);

  document.addEventListener('click', (e) => {
    const el = e.target.closest('[data-bestaetigen]');
    if (el && el.tagName !== 'FORM' && !el.closest('form[data-bestaetigen]')) {
      if (!window.confirm(el.dataset.bestaetigen)) { e.preventDefault(); e.stopPropagation(); }
    }
  }, true);

  /* ------------------------------------------------------- Meldungen -- */

  function melden(text, typ) {
    let huelle = $('.meldungen');
    if (!huelle) {
      huelle = document.createElement('div');
      huelle.className = 'meldungen';
      document.body.appendChild(huelle);
    }
    const el = document.createElement('div');
    el.className = 'meldung meldung--' + (typ || 'erfolg');
    el.innerHTML = '<div class="meldung__text"></div>';
    el.querySelector('.meldung__text').textContent = text;
    huelle.appendChild(el);
    setTimeout(() => { el.style.opacity = '0'; setTimeout(() => el.remove(), 300); }, 4200);
  }
  window.gpMelden = melden;

  $$('.meldung').forEach(el => {
    setTimeout(() => { el.style.transition = 'opacity .3s'; el.style.opacity = '0';
      setTimeout(() => el.remove(), 320); }, 5000);
  });

  /* ---------------------------------------------------- Zwischenablage */

  document.addEventListener('click', (e) => {
    const el = e.target.closest('[data-kopieren]');
    if (!el) return;
    e.preventDefault();
    const text = el.dataset.kopieren;
    const fertig = () => melden('In die Zwischenablage kopiert.', 'erfolg');
    if (navigator.clipboard) {
      navigator.clipboard.writeText(text).then(fertig).catch(() => {});
    } else {
      const f = document.createElement('textarea');
      f.value = text; document.body.appendChild(f); f.select();
      try { document.execCommand('copy'); fertig(); } catch (err) {}
      f.remove();
    }
  });

  /* -------------------------------------------------------- Reiter ---- */

  document.addEventListener('click', (e) => {
    const teil = e.target.closest('[data-reiter]');
    if (!teil) return;
    const gruppe = teil.closest('.reiter');
    const ziel   = teil.dataset.reiter;
    $$('[data-reiter]', gruppe).forEach(t => t.classList.toggle('ist-aktiv', t === teil));
    $$('[data-reiter-feld]').forEach(f => {
      if (f.dataset.reiterGruppe === gruppe.dataset.reiterGruppe) {
        f.classList.toggle('versteckt', f.dataset.reiterFeld !== ziel);
      }
    });
    if (history.replaceState) {
      history.replaceState(null, '', '#' + ziel);
    }
  });

  // Reiter aus der Adresse übernehmen
  if (location.hash) {
    const t = $('[data-reiter="' + location.hash.slice(1) + '"]');
    if (t) t.click();
  }

  /* ------------------------------------------------- Filter absenden -- */

  $$('[data-auto-absenden]').forEach(el => {
    el.addEventListener('change', () => { el.closest('form').submit(); });
  });

  let suchTakt;
  $$('[data-such-absenden]').forEach(el => {
    el.addEventListener('input', () => {
      clearTimeout(suchTakt);
      suchTakt = setTimeout(() => el.closest('form').submit(), 450);
    });
  });

  /* ======================================================= Befehlspalette */

  const palette = {
    huelle: null, eingabe: null, liste: null, eintraege: [], gewaehlt: 0, alle: [], takt: null,

    bauen() {
      if (this.huelle) return;
      const h = document.createElement('div');
      h.className = 'palette-huelle';
      h.innerHTML =
        '<div class="palette" role="dialog" aria-label="Befehle">' +
          '<div class="palette__kopf">' +
            '<svg class="ico" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m20.5 20.5-4.3-4.3"/></svg>' +
            '<input class="palette__eingabe" placeholder="Suchen oder Befehl eingeben…" autocomplete="off" spellcheck="false">' +
            '<kbd>Esc</kbd>' +
          '</div>' +
          '<div class="palette__liste"></div>' +
          '<div class="palette__fuss">' +
            '<span><kbd>↑</kbd><kbd>↓</kbd> wählen</span>' +
            '<span><kbd>⏎</kbd> öffnen</span>' +
            '<span><kbd>Esc</kbd> schließen</span>' +
          '</div>' +
        '</div>';
      document.body.appendChild(h);
      this.huelle  = h;
      this.eingabe = $('.palette__eingabe', h);
      this.liste   = $('.palette__liste', h);

      this.eingabe.addEventListener('input', () => this.filtern());
      this.eingabe.addEventListener('keydown', (e) => this.taste(e));
      h.addEventListener('click', (e) => { if (e.target === h) this.zu(); });
    },

    auf() {
      this.bauen();
      this.alle = window.gpBefehle || [];
      this.huelle.classList.add('ist-offen');
      this.eingabe.value = '';
      this.eingabe.focus();
      this.filtern();
      document.body.style.overflow = 'hidden';
    },

    zu() {
      if (!this.huelle) return;
      this.huelle.classList.remove('ist-offen');
      document.body.style.overflow = '';
    },

    filtern() {
      const q = this.eingabe.value.trim().toLowerCase();
      let treffer = this.alle;
      if (q) {
        treffer = this.alle
          .map(b => ({ b: b, p: this.punkte(b, q) }))
          .filter(x => x.p > 0)
          .sort((a, b) => b.p - a.p)
          .map(x => x.b);
      } else {
        treffer = this.alle.filter(b => b.start).slice(0, 9);
      }
      this.zeichnen(treffer.slice(0, 40), q);
      if (q.length >= 2) {
        clearTimeout(this.takt);
        this.takt = setTimeout(() => this.serverSuche(q), 220);
      }
    },

    /* Einfache, aber wirksame Bewertung: Wortanfang schlägt Teiltreffer. */
    punkte(b, q) {
      const t = (b.titel || '').toLowerCase();
      const s = (b.schlagworte || '').toLowerCase();
      if (t === q) return 100;
      if (t.indexOf(q) === 0) return 80;
      if (t.indexOf(' ' + q) > -1) return 60;
      if (t.indexOf(q) > -1) return 40;
      if (s.indexOf(q) > -1) return 25;
      // Buchstaben der Reihe nach („kne" findet „Kunde neu")
      let i = 0;
      for (const z of t) { if (z === q[i]) i++; if (i === q.length) return 12; }
      return 0;
    },

    serverSuche(q) {
      const url = (window.gpBasis || '') + '/app/suche.php?q=' + encodeURIComponent(q) + '&format=json';
      fetch(url, { headers: { 'X-Requested-With': 'fetch' } })
        .then(r => r.ok ? r.json() : null)
        .then(daten => {
          if (!daten || !daten.treffer || this.eingabe.value.trim().toLowerCase() !== q) return;
          const befehle = this.alle
            .map(b => ({ b: b, p: this.punkte(b, q) }))
            .filter(x => x.p > 0).sort((a, b) => b.p - a.p).map(x => x.b).slice(0, 6);
          this.zeichnen(befehle.concat(daten.treffer), q);
        })
        .catch(() => {});
    },

    zeichnen(liste, q) {
      if (!liste.length) {
        this.liste.innerHTML = '<div class="palette__leer">Nichts gefunden für „' +
          q.replace(/[<>&]/g, '') + '".</div>';
        this.eintraege = [];
        return;
      }
      let html = '';
      let gruppe = '';
      liste.forEach((b, i) => {
        if (b.gruppe && b.gruppe !== gruppe) {
          gruppe = b.gruppe;
          html += '<div class="palette__gruppe">' + this.esc(gruppe) + '</div>';
        }
        html += '<a class="palette__eintrag' + (i === 0 ? ' ist-aktiv' : '') + '" href="' + this.esc(b.url) + '">' +
                  (b.icon ? b.icon : '') +
                  '<span class="palette__eintrag-text">' +
                    '<span class="palette__eintrag-titel">' + this.esc(b.titel) + '</span>' +
                    (b.unter ? '<span class="palette__eintrag-unter">' + this.esc(b.unter) + '</span>' : '') +
                  '</span>' +
                  (b.weg ? '<span class="palette__eintrag-weg">' + this.esc(b.weg) + '</span>' : '') +
                '</a>';
      });
      this.liste.innerHTML = html;
      this.eintraege = $$('.palette__eintrag', this.liste);
      this.gewaehlt = 0;
    },

    esc(s) {
      return String(s == null ? '' : s).replace(/[&<>"']/g, c =>
        ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    },

    taste(e) {
      if (e.key === 'Escape') { this.zu(); return; }
      if (!this.eintraege.length) return;
      if (e.key === 'ArrowDown' || (e.key === 'n' && e.ctrlKey)) {
        e.preventDefault(); this.bewegen(1);
      } else if (e.key === 'ArrowUp' || (e.key === 'p' && e.ctrlKey)) {
        e.preventDefault(); this.bewegen(-1);
      } else if (e.key === 'Enter') {
        e.preventDefault();
        const ziel = this.eintraege[this.gewaehlt];
        if (ziel) window.location.href = ziel.getAttribute('href');
      }
    },

    bewegen(schritt) {
      this.eintraege[this.gewaehlt].classList.remove('ist-aktiv');
      this.gewaehlt = (this.gewaehlt + schritt + this.eintraege.length) % this.eintraege.length;
      const el = this.eintraege[this.gewaehlt];
      el.classList.add('ist-aktiv');
      el.scrollIntoView({ block: 'nearest' });
    }
  };

  window.gpPalette = palette;

  document.addEventListener('keydown', (e) => {
    if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
      e.preventDefault();
      palette.huelle && palette.huelle.classList.contains('ist-offen') ? palette.zu() : palette.auf();
      return;
    }
    if (e.key === 'Escape') {
      palette.zu();
      leisteUm(false);
      $$('.aufklapp.ist-offen').forEach(el => el.classList.remove('ist-offen'));
    }
    // "/" öffnet die Suche, sofern nicht gerade getippt wird
    if (e.key === '/' && !/^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement.tagName)
        && !document.activeElement.isContentEditable) {
      e.preventDefault(); palette.auf();
    }
  });

  document.addEventListener('click', (e) => {
    if (e.target.closest('[data-palette]')) { e.preventDefault(); palette.auf(); }
    if (e.target.closest('[data-thema-um]')) { e.preventDefault(); Thema.umschalten(); }
    const tw = e.target.closest('[data-thema-wert]');
    if (tw) { e.preventDefault(); Thema.setzen(tw.dataset.themaWert); }
  });

  /* ================================================= Ziehen und Ablegen */

  /**
   * Kanban: Karten zwischen Spalten verschieben. Der Server erfährt die
   * neue Spalte per fetch; scheitert das, springt die Karte zurück –
   * eine stille Falschanzeige wäre schlimmer als ein sichtbarer Fehler.
   */
  let gezogen = null;

  document.addEventListener('dragstart', (e) => {
    const karte = e.target.closest('[data-ziehbar]');
    if (!karte) return;
    gezogen = karte;
    karte.classList.add('wird-gezogen');
    e.dataTransfer.effectAllowed = 'move';
    try { e.dataTransfer.setData('text/plain', karte.dataset.id || ''); } catch (err) {}
  });

  document.addEventListener('dragend', () => {
    if (gezogen) gezogen.classList.remove('wird-gezogen');
    $$('.ist-ziel').forEach(el => el.classList.remove('ist-ziel'));
    gezogen = null;
  });

  document.addEventListener('dragover', (e) => {
    const ziel = e.target.closest('[data-ablegen]');
    if (!ziel || !gezogen) return;
    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';
    $$('.ist-ziel').forEach(el => { if (el !== ziel) el.classList.remove('ist-ziel'); });
    ziel.classList.add('ist-ziel');
  });

  document.addEventListener('dragleave', (e) => {
    const ziel = e.target.closest('[data-ablegen]');
    if (ziel && !ziel.contains(e.relatedTarget)) ziel.classList.remove('ist-ziel');
  });

  document.addEventListener('drop', (e) => {
    const ziel = e.target.closest('[data-ablegen]');
    if (!ziel || !gezogen) return;
    e.preventDefault();
    ziel.classList.remove('ist-ziel');

    const koerper = ziel.querySelector('[data-ablage-koerper]') || ziel;
    const vorher  = gezogen.parentElement;
    koerper.appendChild(gezogen);
    zaehlerAuffrischen();

    const url = ziel.dataset.ablegen;
    if (!url) return;
    const daten = new FormData();
    daten.append('id', gezogen.dataset.id || '');
    daten.append('ziel', ziel.dataset.ablegenWert || '');
    daten.append('_csrf', window.gpCsrf || '');
    fetch(url, { method: 'POST', body: daten })
      .then(r => r.json())
      .then(a => {
        if (a && a.ok) { melden(a.meldung || 'Gespeichert.', 'erfolg'); }
        else { vorher.appendChild(gezogen); zaehlerAuffrischen(); melden((a && a.fehler) || 'Konnte nicht gespeichert werden.', 'fehler'); }
      })
      .catch(() => { vorher.appendChild(gezogen); zaehlerAuffrischen(); melden('Keine Verbindung zum Server.', 'fehler'); });
  });

  function zaehlerAuffrischen() {
    $$('[data-ablegen]').forEach(sp => {
      const koerper = sp.querySelector('[data-ablage-koerper]') || sp;
      const zahl = koerper.querySelectorAll('[data-ziehbar]').length;
      const anzeige = sp.closest('.spalte') ? sp.closest('.spalte').querySelector('.spalte__zahl') : null;
      if (anzeige) anzeige.textContent = zahl;
    });
  }

  /* -------------------------------------------------- Reihenfolge ---- */

  /** Listen umsortieren (Bausteine, Lektionen, Übungen). */
  document.addEventListener('dragover', (e) => {
    const liste = e.target.closest('[data-sortierbar]');
    if (!liste || !gezogen || !liste.contains(gezogen)) return;
    e.preventDefault();
    const nach = nachbarFinden(liste, e.clientY);
    if (nach == null) liste.appendChild(gezogen);
    else liste.insertBefore(gezogen, nach);
  });

  function nachbarFinden(liste, y) {
    const andere = Array.from(liste.querySelectorAll('[data-ziehbar]:not(.wird-gezogen)'));
    let naechster = null, abstand = Number.NEGATIVE_INFINITY;
    andere.forEach(el => {
      const k = el.getBoundingClientRect();
      const d = y - k.top - k.height / 2;
      if (d < 0 && d > abstand) { abstand = d; naechster = el; }
    });
    return naechster;
  }

  document.addEventListener('drop', (e) => {
    const liste = e.target.closest('[data-sortierbar]');
    // Ohne diese Abfrage schickt auch ein Baustein aus dem Vorrat eine
    // Umsortierung los - und zwar die alte Reihenfolge, die den gerade
    // eingefuegten Baustein noch gar nicht kennt.
    if (!liste || !gezogen) return;
    const url = liste.dataset.sortierbar;
    if (!url) return;
    const reihen = Array.from(liste.querySelectorAll('[data-ziehbar]')).map(el => el.dataset.id);
    const daten = new FormData();
    // Ohne aktion faellt der POST beim Server durch alle Abfragen hindurch
    // und wird still verworfen: Die Bausteine springen an Ort und Stelle,
    // und beim naechsten Laden steht die alte Reihenfolge wieder da.
    daten.append('aktion', 'reihenfolge');
    daten.append('reihenfolge', reihen.join(','));
    daten.append('_csrf', window.gpCsrf || '');
    fetch(url, { method: 'POST', body: daten })
      .then(r => { if (!r.ok) melden('Reihenfolge konnte nicht gespeichert werden.', 'fehler'); })
      .catch(() => melden('Keine Verbindung zum Server.', 'fehler'));
  });

  /* ------------------------------------- Bausteine aus dem Vorrat ---- */

  /**
   * Einen neuen Baustein an die Stelle ziehen, an der er stehen soll.
   *
   * Der Vorrat links besteht aus echten Submit-Knoepfen: Ein Klick haengt
   * den Baustein hinter den gerade gewaehlten, und das funktioniert auch
   * ohne JavaScript. Was fehlte, war das, was man von einem Baukasten
   * erwartet - den Baustein dorthin ziehen, wo er hin soll.
   *
   * Zwei Flaechen nehmen ihn an: die Bausteinliste links und die Seite in
   * der Mitte. Beide zeigen waehrend des Ziehens eine Linie an der Stelle,
   * an der er landen wuerde. Ohne diese Linie raet man.
   */
  let neuerTyp = null;
  let marke = null;

  function markeWeg() {
    if (marke) { marke.remove(); marke = null; }
    $$('.ist-bau-ziel').forEach(el => el.classList.remove('ist-bau-ziel'));
  }

  function markeSetzen(vor, elternteil) {
    if (!marke) {
      marke = document.createElement('div');
      marke.className = 'einfuege-marke';
      marke.setAttribute('aria-hidden', 'true');
    }
    if (vor) elternteil.insertBefore(marke, vor);
    else elternteil.appendChild(marke);
  }

  /**
   * Wo landet der Baustein?
   *
   * Rueckgabe ist die Kennung des Bausteins, HINTER den eingefuegt wird -
   * so erwartet es der Server. '' heisst ans Ende, 'anfang' ganz nach vorn.
   */
  /**
   * Die Kennung des Bausteins vor diesem - ueber alles hinweg, was kein
   * Baustein ist.
   *
   * Das ist nicht Vorsicht auf Verdacht: Die Einfuegelinie selbst steht
   * beim Ablegen noch zwischen den Bausteinen. Wer stumpf
   * previousElementSibling nimmt, greift sie ab, findet keine Kennung und
   * setzt den neuen Baustein ganz nach vorn statt an die gezeigte Stelle.
   */
  function kennungDavor(teil) {
    let vor = teil.previousElementSibling;
    while (vor && !(vor.dataset.blockId || vor.dataset.id)) {
      vor = vor.previousElementSibling;
    }
    return vor ? (vor.dataset.blockId || vor.dataset.id) : 'anfang';
  }

  function einfuegeStelle(flaeche, y) {
    const teile = Array.from(flaeche.querySelectorAll('[data-block-id], [data-ziehbar][data-id]'));
    if (!teile.length) return { nach: '', vor: null, elternteil: flaeche };

    for (const teil of teile) {
      const k = teil.getBoundingClientRect();
      if (y < k.top + k.height / 2) {
        return { nach: kennungDavor(teil), vor: teil, elternteil: teil.parentElement };
      }
    }
    const letzter = teile[teile.length - 1];
    return {
      nach: letzter.dataset.blockId || letzter.dataset.id || '',
      vor: null,
      elternteil: letzter.parentElement,
    };
  }

  document.addEventListener('dragstart', (e) => {
    const quelle = e.target.closest('[data-neuer-typ]');
    if (!quelle) return;
    neuerTyp = quelle.dataset.neuerTyp;
    quelle.classList.add('wird-gezogen');
    e.dataTransfer.effectAllowed = 'copy';
    try { e.dataTransfer.setData('text/plain', 'baustein:' + neuerTyp); } catch (err) {}
  });

  document.addEventListener('dragend', (e) => {
    const quelle = e.target.closest('[data-neuer-typ]');
    if (quelle) quelle.classList.remove('wird-gezogen');
    neuerTyp = null;
    markeWeg();
  });

  document.addEventListener('dragover', (e) => {
    if (!neuerTyp) return;
    const flaeche = e.target.closest('[data-bau-ziel]');
    if (!flaeche) { markeWeg(); return; }
    e.preventDefault();
    e.dataTransfer.dropEffect = 'copy';
    flaeche.classList.add('ist-bau-ziel');
    const stelle = einfuegeStelle(flaeche, e.clientY);
    markeSetzen(stelle.vor, stelle.elternteil);
  });

  document.addEventListener('drop', (e) => {
    if (!neuerTyp) return;
    const flaeche = e.target.closest('[data-bau-ziel]');
    if (!flaeche) return;
    e.preventDefault();
    e.stopPropagation();

    // Erst die Linie raus, dann rechnen - sie ist selbst ein Element im
    // Fluss und wuerde die Nachbarschaft verfaelschen.
    markeWeg();
    const stelle = einfuegeStelle(flaeche, e.clientY);
    const typ = neuerTyp;
    neuerTyp = null;

    /*
     * Abgeschickt wird ein ganz normales Formular, kein fetch: Der Server
     * legt den Baustein an und leitet auf ihn weiter, sodass seine Felder
     * gleich rechts aufgehen. Genau dasselbe passiert beim Anklicken.
     */
    const form = document.createElement('form');
    form.method = 'post';
    form.style.display = 'none';
    [['aktion', 'block_hinzu'], ['typ', typ], ['nach', stelle.nach],
     ['_csrf', window.gpCsrf || '']].forEach(([name, wert]) => {
      const f = document.createElement('input');
      f.type = 'hidden'; f.name = name; f.value = wert;
      form.appendChild(f);
    });
    document.body.appendChild(form);
    form.submit();
  });

  /* ------------------------------------------- Zeit aufziehen -------- */

  /**
   * Im Kalender eine Lücke markieren und buchen.
   *
   * Der Pro denkt in Flächen, nicht in Formularen: „von hier bis hier, und
   * zwar für den". Deshalb zieht man die Zeit auf, wie man es von Outlook
   * kennt, und bekommt erst danach den kurzen Dialog.
   *
   * Gerastert wird auf 15 Minuten. Feiner gezogen bringt nichts – kein
   * Training beginnt um 10:07 –, und gröber verliert die Viertelstunde,
   * die im Golfunterricht durchaus vorkommt.
   */
  const gitter = $('.kalender__gitter[data-von-stunde]');
  if (gitter) {
    const vonStunde = parseInt(gitter.dataset.vonStunde, 10) || 0;
    const hoehe     = parseInt(gitter.dataset.stundenhoehe, 10) || 52;
    const raster    = parseInt(gitter.dataset.raster, 10) || 15;

    let spalte = null, startY = null, flaeche = null;

    /** Pixel ab Spaltenoberkante -> Minuten seit Mitternacht, gerastert. */
    function minuten(sp, y) {
      const k = sp.getBoundingClientRect();
      const roh = (y - k.top) / hoehe * 60 + vonStunde * 60;
      const gerastert = Math.round(roh / raster) * raster;
      return Math.max(vonStunde * 60, Math.min(24 * 60, gerastert));
    }

    const alsUhrzeit = (m) =>
      String(Math.floor(m / 60) % 24).padStart(2, '0') + ':' + String(m % 60).padStart(2, '0');

    function flaecheZeigen(vonMin, bisMin) {
      if (!flaeche) {
        flaeche = document.createElement('div');
        flaeche.className = 'zeitwahl-flaeche';
        flaeche.setAttribute('aria-hidden', 'true');
        spalte.appendChild(flaeche);
      }
      const oben = (Math.min(vonMin, bisMin) - vonStunde * 60) / 60 * hoehe;
      const hoch = Math.abs(bisMin - vonMin) / 60 * hoehe;
      flaeche.style.top = oben + 'px';
      flaeche.style.height = Math.max(hoch, 2) + 'px';
      flaeche.textContent = alsUhrzeit(Math.min(vonMin, bisMin)) + '–' + alsUhrzeit(Math.max(vonMin, bisMin));
    }

    function aufraeumen() {
      if (flaeche) { flaeche.remove(); flaeche = null; }
      spalte = null; startY = null;
    }

    gitter.addEventListener('mousedown', (e) => {
      if (e.button !== 0) return;
      const sp = e.target.closest('[data-aufziehbar]');
      // Auf einem Termin will man den Termin öffnen, nicht daneben buchen.
      if (!sp || e.target.closest('.termin')) return;
      e.preventDefault();
      spalte = sp;
      startY = minuten(sp, e.clientY);
      flaecheZeigen(startY, startY + raster);
    });

    document.addEventListener('mousemove', (e) => {
      if (!spalte) return;
      flaecheZeigen(startY, minuten(spalte, e.clientY));
    });

    document.addEventListener('mouseup', (e) => {
      if (!spalte) return;
      const bis = minuten(spalte, e.clientY);
      const a = Math.min(startY, bis);
      // Ein einzelner Klick ist kein Aufziehen, meint aber sichtbar eine
      // Stunde ab dieser Stelle - das ist die bequemere Auslegung.
      const b = Math.abs(bis - startY) < raster ? a + 60 : Math.max(startY, bis);
      const tag = spalte.dataset.tag;
      aufraeumen();

      const d = document.getElementById('modal-schnellbuchung');
      if (!d || typeof d.showModal !== 'function') return;
      d.querySelector('#sb-tag').value = tag;
      d.querySelector('#sb-von').value = alsUhrzeit(a);
      d.querySelector('#sb-bis').value = alsUhrzeit(b);
      const anzeige = d.querySelector('#sb-zeit');
      if (anzeige) {
        anzeige.textContent = new Date(tag + 'T00:00:00')
          .toLocaleDateString('de-DE', { weekday: 'long', day: 'numeric', month: 'long' })
          + ', ' + alsUhrzeit(a) + '–' + alsUhrzeit(b) + ' Uhr';
      }
      d.showModal();
    });

    // Verlässt die Maus das Fenster mitten im Zug, bleibt sonst ein
    // Rechteck stehen, das auf nichts mehr reagiert.
    document.addEventListener('mouseleave', () => { if (spalte) aufraeumen(); });

    /* ------------------------------------------- Termin verschieben --- */

    /*
     * Einen bestehenden Termin an eine andere Stelle ziehen.
     *
     * Bewusst mit Maus-Ereignissen statt mit der Zieh-und-Ablege-Technik
     * des Browsers: Die hängt an einem Bild, das der Browser malt, lässt
     * sich nicht rastern und sieht auf keinem zwei Geräten gleich aus.
     * Hier wandert der Termin selbst mit – auf die Viertelstunde genau,
     * über Tagesgrenzen hinweg, und man sieht die ganze Zeit, wo er
     * landen wird.
     *
     * Der Termin ist ein Link. Ein Klick soll ihn weiter öffnen, also
     * beginnt das Ziehen erst nach ein paar Pixeln – und nur dann wird
     * der Klick danach unterdrückt.
     */
    const SCHWELLE = 4;
    let zug = null;

    /** Die Spalte unter dem Zeiger – auch über Tagesgrenzen hinweg. */
    function spalteUnter(x, y) {
      const unten = document.elementsFromPoint(x, y) || [];
      for (const el of unten) {
        const sp = el.closest && el.closest('.kalender__spalte[data-tag]');
        if (sp) return sp;
      }
      return null;
    }

    gitter.addEventListener('mousedown', (e) => {
      if (e.button !== 0) return;
      const el = e.target.closest('.termin[data-verschiebbar]');
      if (!el) return;
      e.preventDefault();

      const k = el.getBoundingClientRect();
      zug = {
        el,
        heimat: el.parentElement,
        griff: e.clientY - k.top,          // wo im Termin man angefasst hat
        startX: e.clientX, startY: e.clientY,
        stil: el.getAttribute('style'),
        dauer: parseInt(el.dataset.dauer, 10) || 60,
        aktiv: false,
        zielTag: el.dataset.tag,
        zielMin: null,
      };
    });

    document.addEventListener('mousemove', (e) => {
      if (!zug) return;
      if (!zug.aktiv) {
        if (Math.abs(e.clientX - zug.startX) < SCHWELLE
         && Math.abs(e.clientY - zug.startY) < SCHWELLE) return;
        zug.aktiv = true;
        zug.el.classList.add('ist-zug');
        document.body.classList.add('zieht-termin');
      }

      const sp = spalteUnter(e.clientX, e.clientY) || zug.el.parentElement;
      if (sp !== zug.el.parentElement) {
        sp.appendChild(zug.el);          // in den anderen Tag umhängen
      }
      zug.zielTag = sp.dataset.tag;
      zug.zielMin = minuten(sp, e.clientY - zug.griff);

      const oben = (zug.zielMin - vonStunde * 60) / 60 * hoehe;
      zug.el.style.top    = oben + 'px';
      zug.el.style.left   = '3px';
      zug.el.style.width  = 'calc(100% - 6px)';
      zug.el.style.height = (zug.dauer / 60 * hoehe - 3) + 'px';
      zug.el.dataset.zielzeit = alsUhrzeit(zug.zielMin);
    });

    document.addEventListener('mouseup', () => {
      if (!zug) return;
      const z = zug;
      zug = null;
      if (!z.aktiv) return;              // war doch nur ein Klick

      document.body.classList.remove('zieht-termin');
      z.el.classList.remove('ist-zug');
      delete z.el.dataset.zielzeit;

      /* Zurück an den alten Platz. Verschoben wird erst, wenn der Dialog
         bestätigt ist – sonst zeigt der Kalender einen Termin an einer
         Stelle, an der er in der Datenbank nicht steht. */
      const zurueck = () => {
        z.heimat.appendChild(z.el);
        z.el.setAttribute('style', z.stil);
      };

      const gleich = z.zielTag === z.el.dataset.tag
                  && alsUhrzeit(z.zielMin) === z.el.dataset.von;
      const d = document.getElementById('modal-verschieben');
      if (gleich || z.zielMin === null || !d || typeof d.showModal !== 'function') {
        zurueck();
        return;
      }

      // Der Klick, der auf das Loslassen folgt, darf den Termin nicht öffnen.
      z.el.addEventListener('click', (ev) => ev.preventDefault(), { once: true, capture: true });

      const langesDatum = (tag) => new Date(tag + 'T00:00:00')
        .toLocaleDateString('de-DE', { weekday: 'long', day: 'numeric', month: 'long' });

      d.querySelector('#vs-id').value  = z.el.dataset.id;
      d.querySelector('#vs-tag').value = z.zielTag;
      d.querySelector('#vs-von').value = alsUhrzeit(z.zielMin);
      d.querySelector('#vs-wer').textContent = z.el.dataset.wer || 'Termin';
      d.querySelector('#vs-alt').textContent =
        langesDatum(z.el.dataset.tag) + ', ' + z.el.dataset.von + ' Uhr';
      d.querySelector('#vs-neu').textContent =
        langesDatum(z.zielTag) + ', ' + alsUhrzeit(z.zielMin) + ' Uhr';

      /* Ohne Kunde gibt es niemanden zu benachrichtigen – dann weg damit,
         statt ein Kästchen anzubieten, das nichts tut. */
      const zeile = d.querySelector('#vs-melden-zeile');
      const haken = d.querySelector('#vs-melden');
      if (zeile && haken) {
        const hatKunde = z.el.dataset.hatKunde === '1';
        zeile.hidden = !hatKunde;
        haken.checked = hatKunde;
      }

      // Abbrechen oder Wegklicken bringt den Termin zurück.
      d.addEventListener('close', zurueck, { once: true });
      d.showModal();
    });

    // Mitten im Zug abgebrochen: Escape räumt auf wie der Dialog.
    document.addEventListener('keydown', (e) => {
      if (e.key !== 'Escape' || !zug || !zug.aktiv) return;
      const z = zug;
      zug = null;
      document.body.classList.remove('zieht-termin');
      z.el.classList.remove('ist-zug');
      z.heimat.appendChild(z.el);
      z.el.setAttribute('style', z.stil);
    });
  }

  /* ------------------------------------------------------ Kleinkram --- */

  // Textfelder wachsen mit
  $$('[data-waechst]').forEach(el => {
    const anpassen = () => { el.style.height = 'auto'; el.style.height = (el.scrollHeight + 2) + 'px'; };
    el.addEventListener('input', anpassen); anpassen();
  });

  // Zeichen zählen
  $$('[data-zaehler]').forEach(el => {
    const anzeige = document.getElementById(el.dataset.zaehler);
    if (!anzeige) return;
    const max = parseInt(el.getAttribute('maxlength') || '0', 10);
    const zeigen = () => {
      anzeige.textContent = el.value.length + (max ? ' / ' + max : '') + ' Zeichen';
      if (max) anzeige.style.color = el.value.length > max * 0.92 ? 'var(--warnung)' : '';
    };
    el.addEventListener('input', zeigen); zeigen();
  });

  // Alle Kästchen einer Liste
  $$('[data-alle-waehlen]').forEach(haupt => {
    haupt.addEventListener('change', () => {
      $$('[name="' + haupt.dataset.alleWaehlen + '[]"]').forEach(k => { k.checked = haupt.checked; });
    });
  });

  // Balken und Ringe erst beim Sichtbarwerden füllen
  if ('IntersectionObserver' in window) {
    const beobachter = new IntersectionObserver((eintraege) => {
      eintraege.forEach(e => {
        if (!e.isIntersecting) return;
        const el = e.target;
        if (el.dataset.breite) el.style.width = el.dataset.breite;
        beobachter.unobserve(el);
      });
    }, { threshold: .2 });
    $$('[data-breite]').forEach(el => { el.style.width = '0'; beobachter.observe(el); });
  } else {
    $$('[data-breite]').forEach(el => { el.style.width = el.dataset.breite; });
  }

  // Formular nur einmal abschicken
  document.addEventListener('submit', (e) => {
    const f = e.target;
    if (f.dataset.mehrfach === 'ja') return;
    const knopf = f.querySelector('button[type="submit"]:not([data-kein-sperren])');
    if (knopf) {
      setTimeout(() => {
        knopf.disabled = true;
        knopf.classList.add('ist-aus');
      }, 10);
      setTimeout(() => { knopf.disabled = false; knopf.classList.remove('ist-aus'); }, 6000);
    }
  });

})();
