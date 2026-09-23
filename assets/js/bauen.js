/* ==========================================================================
   TeePilot – der Baukasten
   --------------------------------------------------------------------------
   Der Baukasten arbeitet an Ort und Stelle. Bis hierher war jede Handlung
   ein Formular mit Weiterleitung: Man verschob einen Baustein, die Seite
   lud neu, man stand wieder oben und suchte, wo er gelandet war. Gezogen
   wurde in einer schmalen Namensliste, die Linie dazu schob beim Ziehen die
   Liste hin und her, und auf dem Tablet ging Ziehen gar nicht.

   Jetzt:

     * Nichts lädt neu. Hinzufügen, Kopieren, Entfernen, Verschieben und
       Speichern gehen an app/bauen.php; zurück kommt genau das HTML, das
       sich geändert hat.
     * Gezogen wird auf der Seite selbst – am Namensschild eines Bausteins –,
       im Aufbau links oder aus dem Vorrat. Eine Linie mit Beschriftung
       zeigt, wo er landet („Zwischen Titelbereich und Leistungen"), in der
       Seite und im Aufbau zugleich. Sie liegt über der Seite und schiebt
       nichts. Am Rand rollt die Seite mit. Esc bricht ab.
     * Zeigerereignisse statt HTML-Drag-and-Drop: dasselbe mit Maus, Stift
       und Finger.
     * „+" an jeder Kante zwischen zwei Bausteinen öffnet die Auswahl genau
       dort. Die Auswahl hat eine Suche.
     * Rückgängig und Wiederholen für alles, was die Folge der Bausteine
       ändert. Ein entfernter Baustein kommt mit einem Klick zurück.
     * Die rechte Spalte speichert selbst, und die Seite zeigt es sofort.

   Alle Anfragen laufen durch eine Warteschlange, eine nach der anderen.
   Sonst könnte ein Verschieben und ein gleichzeitiges Speichern dieselbe
   alte Fassung der Seite lesen, und eines von beiden ginge verloren.
   ========================================================================== */

(function () {
  'use strict';

  const bau = document.querySelector('[data-bau]');
  if (!bau) return;

  const $  = (w, k) => (k || document).querySelector(w);
  const $$ = (w, k) => Array.from((k || document).querySelectorAll(w));
  const basis = window.gpBasis || '';
  const bewegungArm = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  bau.classList.add('ist-js');
  $$('[data-nur-mit-js]', bau).forEach(el => { el.hidden = false; });

  const seiteId  = bau.dataset.seite || '';
  const schreiben = seiteId !== '';
  const leinwand = $('#leinwand', bau);
  const bloeckeEl = $('[data-bloecke]', leinwand);
  const buehne   = $('[data-buehne]', bau);
  const rolle    = $('[data-rolle]', bau);
  const flaeche  = $('.bau__flaeche', bau);
  const massstab = $('[data-massstab]', bau);
  const ueber    = $('[data-ueber]', bau);
  const aufbau   = $('[data-aufbau]', bau);
  const rechts   = $('[data-rechts]', bau);
  const vorratRoh = $('[data-vorrat]', bau);

  const bloecke  = () => $$(':scope > .bau-block', bloeckeEl);
  const block    = (id) => id ? $('.bau-block[data-block-id="' + CSS.escape(id) + '"]', bloeckeEl) : null;
  const teil     = (id) => id ? $('.bau-teil[data-teil="' + CSS.escape(id) + '"]', aufbau) : null;
  const kennungen = () => bloecke().map(b => b.dataset.blockId);
  const nameVon  = (id) => (block(id) || {}).dataset?.name || 'Baustein';

  /* ================================================== Rückmeldung ==== */

  const speicher = $('[data-speicher]', bau);
  let offen = 0;
  let fehlerStand = false;

  const standSetzen = (stand, text) => {
    if (!speicher) return;
    speicher.dataset.stand = stand;
    speicher.textContent = text || ({ ok: 'Alles gespeichert', laeuft: 'Speichert …' })[stand] || '';
    speicher.title = stand === 'fehler' ? 'Neu laden, um den aktuellen Stand zu sehen' : '';
  };
  standSetzen('ok');
  if (speicher) {
    speicher.addEventListener('click', () => { if (fehlerStand) location.reload(); });
  }

  const toastEl = document.createElement('div');
  toastEl.className = 'bau-toast';
  toastEl.setAttribute('role', 'status');
  toastEl.setAttribute('aria-live', 'polite');
  document.body.appendChild(toastEl);
  let toastUhr = null;

  /** Eine Zeile unten, auf Wunsch mit einem Knopf („Rückgängig"). */
  const toast = (text, o = {}) => {
    clearTimeout(toastUhr);
    toastEl.className = 'bau-toast' + (o.fehler ? ' bau-toast--fehler' : '');
    toastEl.textContent = '';
    const t = document.createElement('span');
    t.textContent = text;
    toastEl.appendChild(t);
    if (o.knopf) {
      const k = document.createElement('button');
      k.type = 'button';
      k.textContent = o.knopf;
      k.addEventListener('click', () => { toastEl.classList.remove('ist-da'); o.dann(); });
      toastEl.appendChild(k);
    }
    requestAnimationFrame(() => toastEl.classList.add('ist-da'));
    toastUhr = setTimeout(() => toastEl.classList.remove('ist-da'), o.fehler ? 9000 : 6000);
  };

  /* ================================================ Warteschlange ===== */

  let schlange = Promise.resolve();

  const anfrage = (pfad, daten) => {
    const koerper = daten instanceof FormData ? daten : new FormData();
    if (!(daten instanceof FormData)) {
      Object.keys(daten).forEach(k => { if (daten[k] !== undefined && daten[k] !== null) koerper.append(k, daten[k]); });
    }
    if (!koerper.has('_csrf')) koerper.append('_csrf', window.gpCsrf || '');
    return fetch(basis + pfad, { method: 'POST', body: koerper, keepalive: true, credentials: 'same-origin' })
      .catch(() => { throw new Error('Keine Verbindung zum Server.'); })
      .then(r => r.json().catch(() => ({ fehler: 'Unerwartete Antwort vom Server.' })).then(a => {
        if (!r.ok || !a || a.fehler) throw new Error((a && a.fehler) || 'Das hat nicht geklappt.');
        return a;
      }));
  };

  /**
   * Eine Anfrage hinten anstellen. `daten` darf eine Funktion sein – dann
   * wird erst gelesen, wenn die Anfrage an der Reihe ist. Wichtig für das
   * Formular rechts: Es soll mit dem Stand gehen, den es beim Absenden hat,
   * nicht mit dem beim Einreihen.
   */
  const einreihen = (pfad, daten, o = {}) => {
    if (!o.still) { offen++; standSetzen('laeuft'); }
    const p = schlange.then(() => anfrage(pfad, typeof daten === 'function' ? daten() : daten));
    schlange = p.catch(() => {});
    p.then(() => {
      if (!o.still && --offen === 0 && !fehlerStand) standSetzen('ok');
    }, (f) => {
      if (!o.still) { offen--; }
      fehlerStand = true;
      standSetzen('fehler', 'Nicht gespeichert');
      toast(f.message, { fehler: true, knopf: 'Neu laden', dann: () => location.reload() });
    });
    return p;
  };

  const bauen = (aktion, daten, o) => einreihen('/app/bauen.php',
    typeof daten === 'function'
      ? () => { const d = daten(); d.set('aktion', aktion); d.set('seite', seiteId); return d; }
      : Object.assign({}, daten, { aktion, seite: seiteId }), o);

  window.addEventListener('beforeunload', (e) => {
    if (offen > 0) { e.preventDefault(); e.returnValue = ''; }
  });

  /* ================================================ Maßstab ========== */

  /*
   * Die Seite wird in der Breite gezeigt, für die sie gebaut ist – ein
   * Computer 1280 Pixel, ein Tablet 768, ein Handy 390 –, und dann so
   * verkleinert, dass sie in die Bühne passt. Weil die Leinwand ein
   * Container ist und die Website-Stile mit `--vw: 1cqi` rechnen, sieht
   * die Handy-Ansicht aus wie auf einem Handy und nicht wie eine
   * zusammengedrückte Computerseite.
   */
  const BREITE = { desktop: 1280, tablet: 768, mobil: 390 };
  const merkeLesen = (k, v) => { try { return localStorage.getItem(k) || v; } catch (e) { return v; } };
  const merkeSetzen = (k, v) => { try { localStorage.setItem(k, v); } catch (e) {} };

  let geraet = merkeLesen('gp-bau-geraet', 'desktop');
  if (!BREITE[geraet]) geraet = 'desktop';
  /* Auf dem Handy selbst gibt es nur eine sinnvolle Ansicht. Gemerkt wird
     das nicht – am Rechner soll die gewohnte Wahl gelten. */
  const handy = window.matchMedia('(max-width: 640px)');
  if (handy.matches) geraet = 'mobil';
  let einpassen = merkeLesen('gp-bau-zoom', 'einpassen') !== '100';
  let faktor = 1;
  const zoomKnopf = $('[data-zoom]', bau);

  const messen = () => {
    const stil = getComputedStyle(flaeche);
    const verfuegbar = rolle.clientWidth - parseFloat(stil.paddingLeft) - parseFloat(stil.paddingRight);
    const w = BREITE[geraet];
    faktor = einpassen ? Math.min(1, Math.max(.2, verfuegbar / w)) : 1;
    leinwand.style.width = w + 'px';
    leinwand.style.transform = faktor < 1 ? 'scale(' + faktor + ')' : '';
    massstab.style.width = Math.round(w * faktor) + 'px';
    massstab.style.height = Math.ceil(leinwand.offsetHeight * faktor) + 'px';
    flaeche.classList.toggle('ist-breit', w * faktor > verfuegbar + 1);
    if (zoomKnopf) {
      zoomKnopf.hidden = false;
      zoomKnopf.textContent = Math.round(faktor * 100) + ' %';
      zoomKnopf.title = einpassen ? 'Eingepasst – klicken für 100 %' : '100 % – klicken zum Einpassen';
    }
    zeichnen();
  };

  let messUhr = 0;
  const bald = () => { cancelAnimationFrame(messUhr); messUhr = requestAnimationFrame(messen); };
  if ('ResizeObserver' in window) {
    const ro = new ResizeObserver(bald);
    ro.observe(leinwand);
    ro.observe(rolle);
  }
  window.addEventListener('resize', bald);
  /* Bilder und Schriften kommen nach – die Höhe der Leinwand ändert sich mit. */
  leinwand.addEventListener('load', bald, true);

  const geraetSetzen = (g, merken = true) => {
    geraet = g;
    if (merken && !handy.matches) merkeSetzen('gp-bau-geraet', g);
    leinwand.dataset.geraet = g;
    $$('[data-geraet]', bau).forEach(k => {
      const an = k.dataset.geraet === g;
      k.classList.toggle('ist-aktiv', an);
      k.setAttribute('aria-pressed', an ? 'true' : 'false');
    });
    messen();
    if (wahlId) requestAnimationFrame(() => blockZeigen(wahlId, false));
  };
  $$('[data-geraet]', bau).forEach(k => k.addEventListener('click', () => geraetSetzen(k.dataset.geraet)));
  if (zoomKnopf) {
    zoomKnopf.addEventListener('click', () => {
      einpassen = !einpassen;
      merkeSetzen('gp-bau-zoom', einpassen ? 'einpassen' : '100');
      messen();
    });
  }

  /* ================================================ Überlagerung ====== */

  /*
   * Rahmen, Namensschild, Werkzeuge und „+" liegen über der Seite, nicht
   * in ihr. In der Seite wären sie mit verkleinert – und sie würden das
   * HTML der Website berühren, das dieselbe Funktion ausgibt wie online.
   */
  const neu = (tag, klasse, html) => {
    const el = document.createElement(tag);
    el.className = klasse;
    if (html) el.innerHTML = html;
    return el;
  };
  const SVG = {
    grip: '<svg class="ico" width="14" height="14" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="9" cy="6" r="1.6"/><circle cx="15" cy="6" r="1.6"/><circle cx="9" cy="12" r="1.6"/><circle cx="15" cy="12" r="1.6"/><circle cx="9" cy="18" r="1.6"/><circle cx="15" cy="18" r="1.6"/></svg>',
    hoch: '<svg class="ico" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 19V5M5 12l7-7 7 7"/></svg>',
    runter: '<svg class="ico" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14M19 12l-7 7-7-7"/></svg>',
    kopie: '<svg class="ico" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="12" height="12" rx="2.5"/><path d="M5 15H4a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v1"/></svg>',
    weg: '<svg class="ico" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14"/></svg>',
    plus: '<svg class="ico" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>',
  };

  const rahmenHover = neu('div', 'bau-rahmen bau-rahmen--hover');
  const rahmenWahl  = neu('div', 'bau-rahmen bau-rahmen--wahl');
  const schildHover = neu('div', 'bau-schild');
  const schildWahl  = neu('div', 'bau-schild');
  const werkzeug    = neu('div', 'bau-werkzeug');
  const einsetzer   = neu('button', 'bau-einsetzer', SVG.plus);
  const einsLinie   = neu('div', 'bau-einsetzer__linie');
  const linie       = neu('div', 'bau-linie', '<span class="bau-linie__schild"></span>');
  const linieAufbau = neu('div', 'bau-aufbau__linie');
  einsetzer.type = 'button';

  [schildHover, schildWahl].forEach(s => {
    s.setAttribute('role', 'button');
    s.setAttribute('tabindex', '-1');
    s.title = 'Ziehen, um den Baustein zu verschieben';
  });
  werkzeug.setAttribute('role', 'toolbar');
  werkzeug.setAttribute('aria-label', 'Baustein');
  werkzeug.innerHTML =
    '<button type="button" data-w="hoch" title="Nach oben (Alt + ↑)" aria-label="Nach oben">' + SVG.hoch + '</button>' +
    '<button type="button" data-w="runter" title="Nach unten (Alt + ↓)" aria-label="Nach unten">' + SVG.runter + '</button>' +
    '<span class="bau-werkzeug__trenner"></span>' +
    '<button type="button" data-w="kopie" title="Kopieren (Strg + D)" aria-label="Kopieren">' + SVG.kopie + '</button>' +
    '<button type="button" data-w="weg" class="ist-gefahr" title="Entfernen (Entf)" aria-label="Entfernen">' + SVG.weg + '</button>';

  [rahmenHover, rahmenWahl, einsLinie, linie].forEach(el => { el.hidden = true; ueber.appendChild(el); });
  if (schreiben) {
    [schildHover, schildWahl, werkzeug, einsetzer].forEach(el => { el.hidden = true; ueber.appendChild(el); });
  }
  linieAufbau.hidden = true;
  aufbau.appendChild(linieAufbau);

  let hoverId = '';
  let wahlId  = bau.dataset.gewaehlt || '';
  let einsetzerZiel = null;     // { nach, y }
  let zug = null;               // läuft gerade ein Ziehen?

  const buehnenRect = () => buehne.getBoundingClientRect();
  const lage = (el, b) => {
    const r = el.getBoundingClientRect();
    return { x: r.left - b.left, y: r.top - b.top, w: r.width, h: r.height };
  };
  const setze = (el, x, y, w, h) => {
    el.style.left = Math.round(x) + 'px';
    el.style.top = Math.round(y) + 'px';
    if (w !== undefined) el.style.width = Math.round(w) + 'px';
    if (h !== undefined) el.style.height = Math.round(h) + 'px';
  };

  let zeichenUhr = 0;
  function zeichnen() {
    cancelAnimationFrame(zeichenUhr);
    zeichenUhr = requestAnimationFrame(zeichnenJetzt);
  }

  function zeichnenJetzt() {
    const b = buehnenRect();
    const hoch = b.height;
    const zieht = !!(zug && zug.aktiv);

    /* Gewählt: fester Rahmen, Schild mit Namen, Werkzeuge. */
    const wEl = block(wahlId);
    if (wEl && !zieht) {
      const l = lage(wEl, b);
      rahmenWahl.hidden = false;
      setze(rahmenWahl, l.x, l.y, l.w, l.h);
      if (schreiben) {
        /* Schild und Werkzeuge bleiben sichtbar, solange der Baustein es
           ist – auch wenn seine Oberkante längst aus dem Bild gerollt ist. */
        const sichtbar = l.y + l.h > 40 && l.y < hoch - 30;
        schildWahl.hidden = !sichtbar;
        werkzeug.hidden = !sichtbar;
        if (sichtbar) {
          schildZeigen(schildWahl, wahlId, l);
          const wy = Math.min(Math.max(l.y + 8, 8), l.y + l.h - 46);
          setze(werkzeug, l.x + l.w - werkzeug.offsetWidth - 8, wy);
          const i = kennungen().indexOf(wahlId);
          $('[data-w="hoch"]', werkzeug).disabled = i <= 0;
          $('[data-w="runter"]', werkzeug).disabled = i === -1 || i >= bloecke().length - 1;
        }
      }
    } else {
      rahmenWahl.hidden = true;
      schildWahl.hidden = true;
      werkzeug.hidden = true;
    }

    /* Darüber gezeigt: gestrichelter Rahmen und ein Schild zum Anfassen. */
    const hEl = hoverId !== wahlId ? block(hoverId) : null;
    if (hEl && !zieht) {
      const l = lage(hEl, b);
      rahmenHover.hidden = false;
      setze(rahmenHover, l.x, l.y, l.w, l.h);
      if (schreiben) {
        schildHover.hidden = l.y + l.h < 30;
        schildZeigen(schildHover, hoverId, l);
      }
    } else {
      rahmenHover.hidden = true;
      schildHover.hidden = true;
    }

    /* „+" an der Kante, der der Zeiger am nächsten ist. */
    if (schreiben && einsetzerZiel && !zieht) {
      const l = lage(leinwand, b);
      einsetzer.hidden = false;
      setze(einsetzer, l.x + l.w / 2, einsetzerZiel.y);
      einsLinie.hidden = false;
      setze(einsLinie, l.x, einsetzerZiel.y, l.w);
    } else {
      einsetzer.hidden = true;
      einsLinie.hidden = true;
      einsLinie.classList.remove('ist-da');
    }

    if (zieht) zugLinienZeichnen(b);
  }

  /* Das Schild sitzt über der Oberkante; ist dort kein Platz, darin. */
  const schildZeigen = (s, id, l) => {
    if (s.dataset.fuer !== id) {
      s.innerHTML = SVG.grip + '<span>' + esc(nameVon(id)) + '</span>';
      s.dataset.fuer = id;
    }
    const innen = l.y < 26;
    s.classList.toggle('ist-innen', innen);
    setze(s, l.x, innen ? Math.min(Math.max(l.y, 0), l.y + l.h - 26) : l.y - 26);
  };

  const esc = (t) => String(t).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]);

  rolle.addEventListener('scroll', zeichnen, { passive: true });

  /*
   * Wo steht der Zeiger? Der Baustein darunter bekommt den Rahmen; ist er
   * nah an einer Kante, erscheint dort das „+".
   */
  const KANTE = 22;
  buehne.addEventListener('pointermove', (e) => {
    if (zug) return;
    if (e.target.closest('.bau-werkzeug, .bau-schild, .bau-einsetzer')) return;
    const bl = e.target.closest('.bau-block');
    const id = bl ? bl.dataset.blockId : '';
    if (id !== hoverId) { hoverId = id; aufbauHover(id); }

    einsetzerZiel = null;
    if (schreiben && bl) {
      const r = bl.getBoundingClientRect();
      const b = buehnenRect();
      const liste = kennungen();
      const i = liste.indexOf(id);
      if (e.clientY - r.top < KANTE) {
        einsetzerZiel = { nach: i === 0 ? 'anfang' : liste[i - 1], y: r.top - b.top };
      } else if (r.bottom - e.clientY < KANTE) {
        einsetzerZiel = { nach: id, y: r.bottom - b.top };
      }
    }
    zeichnen();
  });
  buehne.addEventListener('pointerleave', () => {
    if (zug) return;
    hoverId = '';
    einsetzerZiel = null;
    aufbauHover('');
    zeichnen();
  });
  einsetzer.addEventListener('pointerenter', () => einsLinie.classList.add('ist-da'));
  einsetzer.addEventListener('pointerleave', () => einsLinie.classList.remove('ist-da'));
  einsetzer.addEventListener('click', (e) => {
    if (!einsetzerZiel) return;
    vorratOeffnen({ nach: einsetzerZiel.nach, x: e.clientX, y: e.clientY });
  });

  const aufbauHover = (id) => {
    $$('.bau-teil.ist-hover', aufbau).forEach(t => t.classList.remove('ist-hover'));
    const t = teil(id);
    if (t) t.classList.add('ist-hover');
  };
  aufbau.addEventListener('pointerover', (e) => {
    if (zug) return;
    const t = e.target.closest('.bau-teil');
    hoverId = t ? t.dataset.teil : '';
    zeichnen();
  });
  aufbau.addEventListener('pointerleave', () => { if (!zug) { hoverId = ''; zeichnen(); } });

  werkzeug.addEventListener('click', (e) => {
    const k = e.target.closest('[data-w]');
    if (!k || !wahlId) return;
    const w = k.dataset.w;
    if (w === 'hoch') schieben(wahlId, -1);
    if (w === 'runter') schieben(wahlId, 1);
    if (w === 'kopie') kopieren(wahlId);
    if (w === 'weg') entfernen(wahlId);
  });

  /* ================================================ Auswahl ========== */

  const urlSetzen = (id) => {
    const u = new URL(location.href);
    if (id) u.searchParams.set('block', id); else u.searchParams.delete('block');
    history.replaceState(null, '', u.toString());
  };

  /**
   * Den Baustein ins Bild holen, wenn er nicht (ganz) zu sehen ist.
   *
   * Gerechnet wird mit der Lage im Fluss (`offsetTop`) und nicht mit
   * getBoundingClientRect(): Während der kurzen Bewegung nach dem
   * Verschieben steht der Baustein dort noch an seinem alten Platz.
   */
  const blockZeigen = (id, sanft = true) => {
    const el = block(id);
    if (!el) return;
    const m = massstab.getBoundingClientRect();
    const b = rolle.getBoundingClientRect();
    const oben = m.top + el.offsetTop * faktor;
    if (oben >= b.top + 40 && oben <= b.bottom - 120) return;
    rolle.scrollTo({ top: Math.max(0, rolle.scrollTop + (oben - b.top) - 48), behavior: sanft && !bewegungArm ? 'smooth' : 'auto' });
  };

  function waehlen(id, o = {}) {
    if (!block(id)) id = '';
    const wechsel = id !== wahlId;
    wahlId = id;
    bloecke().forEach(b => b.classList.toggle('ist-gewaehlt', b.dataset.blockId === id));
    $$('.bau-teil', aufbau).forEach(t => t.classList.toggle('ist-gewaehlt', t.dataset.teil === id));
    urlSetzen(id);
    bau.classList.toggle('ist-panel-offen', !!id);
    const t = teil(id);
    if (t) t.scrollIntoView({ block: 'nearest' });
    if (o.zeigen) blockZeigen(id);
    if (schreiben && (wechsel || o.panel)) panelLaden(id);
    zeichnen();
  }

  /*
   * Klick in die Seite wählt den Baustein aus. Ein Klick auf einen Text
   * wählt ihn auch aus und setzt zugleich den Textzeiger – beides ist
   * gemeint. Links und Formulare der Website tun im Baukasten nichts.
   */
  leinwand.addEventListener('click', (e) => {
    const a = e.target.closest('a[href]');
    if (a && leinwand.contains(a)) e.preventDefault();
    if (e.target.closest('[data-plus], [data-eintrag-weg]')) return;
    const bl = e.target.closest('.bau-block');
    if (!bl || bl.dataset.blockId === wahlId) return;
    if (!schreiben) {
      const t = teil(bl.dataset.blockId);
      if (t) location.href = t.href;
      return;
    }
    waehlen(bl.dataset.blockId);
  });
  leinwand.addEventListener('submit', (e) => e.preventDefault());

  /* Ins Graue neben der Seite geklickt: Auswahl aufheben, die Seite zeigen. */
  rolle.addEventListener('click', (e) => {
    if (e.target === rolle || e.target === flaeche || e.target === massstab) waehlen('');
  });

  aufbau.addEventListener('click', (e) => {
    const t = e.target.closest('.bau-teil');
    if (!t || !schreiben) return;             // ohne Schreibrecht bleibt die Zeile ein Link
    e.preventDefault();
    if (zugGeradeVorbei) return;
    waehlen(t.dataset.teil, { zeigen: true });
    bau.classList.remove('ist-aufbau-offen');
  });

  bau.addEventListener('click', (e) => {
    const a = e.target.closest('[data-bau-aktion]');
    if (!a || !schreiben) return;
    const w = a.dataset.bauAktion;
    e.preventDefault();
    if (w === 'abwaehlen') { waehlen(''); return; }
    const id = wahlId;
    if (!id) return;
    if (w === 'hoch') schieben(id, -1);
    if (w === 'runter') schieben(id, 1);
    if (w === 'kopie') kopieren(id);
    if (w === 'weg') entfernen(id);
  });

  const aufbauKnopf = $('[data-aufbau-auf]', bau);
  if (aufbauKnopf) {
    aufbauKnopf.addEventListener('click', () => bau.classList.toggle('ist-aufbau-offen'));
  }

  /* ================================================ Rechte Spalte ===== */

  let panelUhr = null;

  function panelLaden(id) {
    const p = $('[data-panel]', rechts);
    if (p) p.classList.add('ist-laedt');
    bauen('panel', { block: id || '' }, { still: true })
      .then(a => {
        if ((wahlId || '') !== (id || '')) return;   // inzwischen etwas anderes gewählt
        rechts.innerHTML = a.html;
        panelEinrichten();
        panelAusLeinwand(id);
      })
      .catch(() => { if (p) p.classList.remove('ist-laedt'); });
  }

  function panelEinrichten() {
    const p = $('[data-panel]', rechts);
    if (!p) return;
    $$('[data-nur-mit-js]', p).forEach(el => { el.hidden = false; });
    $$('[data-waechst]', p).forEach(wachsen);
    $$('[data-zaehler]', p).forEach(zaehlen);
    $$('[data-liste]', p).forEach(listenfeld);
    const form = $('[data-block-form]', p);
    if (form && schreiben) {
      form.addEventListener('input', () => panelVormerken(form));
      form.addEventListener('change', () => panelVormerken(form));
      form.addEventListener('submit', (e) => { e.preventDefault(); panelSpeichern(form); });
    }
    panelStelle();
  }

  const wachsen = (el) => {
    const an = () => { el.style.height = 'auto'; el.style.height = (el.scrollHeight + 2) + 'px'; };
    el.addEventListener('input', an);
    an();
  };
  const zaehlen = (el) => {
    const anzeige = document.getElementById(el.dataset.zaehler);
    if (!anzeige) return;
    const max = parseInt(el.getAttribute('maxlength') || '0', 10);
    const zeig = () => { anzeige.textContent = el.value.length + (max ? ' / ' + max : '') + ' Zeichen'; };
    el.addEventListener('input', zeig);
    zeig();
  };

  /*
   * Listen rechts: statt der leeren Karte am Ende ein Knopf „Eintrag
   * hinzufügen" und an jeder Karte einer zum Entfernen. Die leere Karte
   * wird zur Vorlage.
   */
  function listenfeld(feld) {
    const eintraege = $('[data-eintraege]', feld);
    const vorlageEl = $('[data-vorlage]', eintraege);
    const vorlage = vorlageEl ? vorlageEl.cloneNode(true) : null;
    if (vorlageEl) vorlageEl.remove();
    const neuNummern = () => {
      const karten = $$('[data-eintragkarte]', eintraege);
      karten.forEach((k, n) => { const a = $('[data-nr-anzeige]', k); if (a) a.textContent = 'Eintrag ' + (n + 1); });
      const z = $('[data-listen-zahl]', feld);
      if (z) z.textContent = karten.length;
    };
    $$('[data-eintrag-entfernen], [data-eintrag-hinzu]', feld).forEach(k => { k.hidden = false; });
    feld.addEventListener('click', (e) => {
      const weg = e.target.closest('[data-eintrag-entfernen]');
      if (weg) {
        weg.closest('[data-eintragkarte]').remove();
        neuNummern();
        eintraege.dispatchEvent(new Event('change', { bubbles: true }));
        return;
      }
      const hinzu = e.target.closest('[data-eintrag-hinzu]');
      if (hinzu && vorlage) {
        const k = vorlage.cloneNode(true);
        k.removeAttribute('data-vorlage');
        $$('[data-eintrag-entfernen]', k).forEach(x => { x.hidden = false; });
        eintraege.appendChild(k);
        $$('[data-waechst]', k).forEach(wachsen);
        neuNummern();
        const erstes = $('input:not([type=hidden]), textarea', k);
        if (erstes) erstes.focus();
      }
    });
  }

  function panelVormerken(form) {
    clearTimeout(panelUhr);
    panelUhr = setTimeout(() => panelSpeichern(form), 450);
  }

  function panelSpeichern(form) {
    clearTimeout(panelUhr);
    const id = form.elements.block_id.value;
    return bauen('speichern', () => {
      const d = new FormData(form);
      d.set('block', id);
      return d;
    }).then(a => blockErsetzen(a.block));
  }

  /** „Baustein 3 von 9" und die Pfeile unten nach dem Verschieben stimmen lassen. */
  function panelStelle() {
    const p = $('[data-panel-block]', rechts);
    if (!p) return;
    const liste = kennungen();
    const i = liste.indexOf(p.dataset.panelBlock);
    const s = $('[data-panel-stelle]', p);
    if (s && i > -1) s.textContent = 'Baustein ' + (i + 1) + ' von ' + liste.length;
    const hoch = $('[data-bau-aktion="hoch"]', p);
    const runter = $('[data-bau-aktion="runter"]', p);
    if (hoch) hoch.disabled = i <= 0;
    if (runter) runter.disabled = i === -1 || i >= liste.length - 1;
  }

  /**
   * Die rechte Spalte mit dem abgleichen, was gerade in der Seite steht.
   *
   * Nötig, weil beides unterwegs sein kann: Wer in einen Text klickt, wählt
   * den Baustein und tippt sofort – die Spalte kommt vom Server aber mit dem
   * Stand von vor dem Tippen. Ohne Abgleich stünde rechts der alte Satz, und
   * die nächste Änderung dort schriebe ihn zurück. Die Seite hat immer den
   * neuesten Stand; also gewinnt sie.
   */
  function panelAusLeinwand(id) {
    const b = block(id);
    if (!b) return;
    $$('[data-feld]', b).forEach(el => {
      if (el.dataset.art === 'bild') return;
      const woher = herkunft(el);
      if (woher) panelAbgleichen(woher, auslesen(el, el.dataset.art));
    });
  }

  /** Was direkt in der Seite geschrieben wurde, auch rechts eintragen. */
  function panelAbgleichen(woher, wert) {
    const form = $('[data-block-form]', rechts);
    if (!form || form.elements.block_id.value !== woher.block) return;
    let feld = null;
    if (woher.nr !== undefined && woher.unter) {
      const karte = $$('[data-liste="' + CSS.escape(woher.feld) + '"] [data-eintragkarte]', form)[parseInt(woher.nr, 10)];
      feld = karte ? $('[name="l_' + CSS.escape(woher.feld) + '_' + CSS.escape(woher.unter) + '[]"]', karte) : null;
    } else {
      feld = form.elements['f_' + woher.feld];
    }
    if (feld && 'value' in feld && document.activeElement !== feld) {
      feld.value = wert;
      if (feld.matches('[data-waechst]')) { feld.style.height = 'auto'; feld.style.height = (feld.scrollHeight + 2) + 'px'; }
    }
  }

  /* ================================================ Bausteine ======== */

  const ausHtml = (html) => {
    const t = document.createElement('template');
    t.innerHTML = html.trim();
    return t.content.firstElementChild;
  };

  /** Hinter `nach` einsetzen: '' = ans Ende, 'anfang' = ganz vorn. */
  const einfuegen = (behaelter, el, nach, finden) => {
    if (nach === 'anfang') { behaelter.insertBefore(el, behaelter.firstElementChild); return; }
    const vor = nach ? finden(nach) : null;
    if (vor) vor.after(el);
    else {
      const leer = $(':scope > [data-aufbau-leer]', behaelter);
      if (leer) behaelter.insertBefore(el, leer); else behaelter.appendChild(el);
    }
  };

  function blockEinsetzen(daten, nach) {
    const b = ausHtml(daten.html);
    einfuegen(bloeckeEl, b, nach, block);
    const t = ausHtml(daten.teil);
    einfuegen(aufbau, t, nach, teil);
    aufbau.appendChild(linieAufbau);
    felderEinrichten(b);
    zaehlerStand();
    return b;
  }

  function blockErsetzen(daten) {
    const alt = block(daten.id);
    if (alt) {
      const b = ausHtml(daten.html);
      b.classList.toggle('ist-gewaehlt', daten.id === wahlId);
      alt.replaceWith(b);
      felderEinrichten(b);
    }
    const altT = teil(daten.id);
    if (altT) {
      const t = ausHtml(daten.teil);
      t.classList.toggle('ist-gewaehlt', daten.id === wahlId);
      altT.replaceWith(t);
    }
    bald();
  }

  /** Den Baustein frisch vom Server holen – nach Bild, Eintrag hinzu oder weg. */
  function blockNeu(id) {
    return bauen('block', { block: id }, { still: true }).then(a => {
      blockErsetzen(a.block);
      if (id === wahlId) panelLaden(id);
    });
  }

  function zaehlerStand() {
    const n = bloecke().length;
    const anzahl = $('[data-anzahl]', bau);
    if (anzahl) anzahl.textContent = n;
    const leer = $('[data-leer]', leinwand);
    if (leer) leer.hidden = n > 0;
    const aLeer = $('[data-aufbau-leer]', aufbau);
    if (aLeer) aLeer.hidden = n > 0;
    const ende = $('.bau-ende', bau);
    if (ende) ende.toggleAttribute('data-leer-versteckt', n === 0);
    panelStelle();
    bald();
  }

  const aufleuchten = (el) => {
    if (!el || bewegungArm) return;
    el.classList.remove('ist-neu');
    void el.offsetWidth;
    el.classList.add('ist-neu');
    setTimeout(() => el.classList.remove('ist-neu'), 1400);
  };

  /* ------------------------------------------------ Verlauf --------- */

  const verlauf = { zurueck: [], vor: [] };
  const knopfZurueck = $('[data-rueckgaengig]', bau);
  const knopfVor = $('[data-wiederholen]', bau);
  const verlaufKnoepfe = () => {
    if (knopfZurueck) {
      knopfZurueck.disabled = !verlauf.zurueck.length;
      knopfZurueck.title = verlauf.zurueck.length ? 'Rückgängig: ' + verlauf.zurueck[verlauf.zurueck.length - 1].was + ' (Strg + Z)' : 'Rückgängig (Strg + Z)';
    }
    if (knopfVor) {
      knopfVor.disabled = !verlauf.vor.length;
      knopfVor.title = verlauf.vor.length ? 'Wiederholen: ' + verlauf.vor[verlauf.vor.length - 1].was + ' (Strg + Umschalt + Z)' : 'Wiederholen (Strg + Umschalt + Z)';
    }
  };
  const merken = (schritt) => {
    verlauf.zurueck.push(schritt);
    if (verlauf.zurueck.length > 60) verlauf.zurueck.shift();
    verlauf.vor = [];
    verlaufKnoepfe();
  };

  function rueckgaengig() {
    const s = verlauf.zurueck.pop();
    if (!s) return;
    verlauf.vor.push(s);
    verlaufKnoepfe();
    if (s.art === 'reihe') ordnen(s.vorher, s.id);
    if (s.art === 'neu') entfernen(s.id, { stumm: true });
    if (s.art === 'weg') zurueckholen(s.id, s.nach);
    toast('Rückgängig: ' + s.was);
  }

  function wiederholen() {
    const s = verlauf.vor.pop();
    if (!s) return;
    verlauf.zurueck.push(s);
    verlaufKnoepfe();
    if (s.art === 'reihe') ordnen(s.nachher, s.id);
    if (s.art === 'neu') zurueckholen(s.id, s.nach);
    if (s.art === 'weg') entfernen(s.id, { stumm: true });
    toast('Wiederholt: ' + s.was);
  }

  if (knopfZurueck) knopfZurueck.addEventListener('click', rueckgaengig);
  if (knopfVor) knopfVor.addEventListener('click', wiederholen);

  /* ------------------------------------------------ Handlungen ------ */

  /**
   * Einen neuen Baustein einsetzen. Sofort erscheint ein Platzhalter an
   * der Stelle – man soll nicht auf den Server warten, um zu sehen, wo
   * er hinkommt.
   */
  function hinzufuegen(typ, nach, name) {
    const platz = neu('div', 'bau-block bau-block--kommt',
      '<div class="bau-block__leer"><p class="halbfett">' + esc(name || 'Baustein') + ' wird eingefügt …</p></div>');
    einfuegen(bloeckeEl, platz, nach, block);
    zaehlerStand();
    const b = rolle.getBoundingClientRect();
    const r = platz.getBoundingClientRect();
    if (r.top < b.top || r.bottom > b.bottom) {
      rolle.scrollTo({ top: rolle.scrollTop + (r.top - b.top) - 80, behavior: bewegungArm ? 'auto' : 'smooth' });
    }
    return bauen('hinzu', { typ, nach })
      .then(a => {
        platz.remove();
        const el = blockEinsetzen(a.block, nach);
        waehlen(a.block.id, { zeigen: true });
        aufleuchten(el);
        merken({ art: 'neu', id: a.block.id, nach, was: a.block.name + ' eingefügt' });
      })
      .catch(() => { platz.remove(); zaehlerStand(); });
  }

  function kopieren(id) {
    return bauen('kopie', { block: id }).then(a => {
      const el = blockEinsetzen(a.block, id);
      waehlen(a.block.id, { zeigen: true });
      aufleuchten(el);
      merken({ art: 'neu', id: a.block.id, nach: id, was: a.block.name + ' kopiert' });
      toast(a.block.name + ' kopiert');
    });
  }

  function entfernen(id, o = {}) {
    const el = block(id);
    const t = teil(id);
    if (!el) return Promise.resolve();
    const liste = kennungen();
    const i = liste.indexOf(id);
    const nach = i <= 0 ? 'anfang' : liste[i - 1];
    const name = nameVon(id);

    /* Sofort weg, damit es sich nicht zäh anfühlt – kommt der Server nicht
       mit, steht er wieder da. */
    const platzB = el.nextElementSibling;
    const platzT = t ? t.nextElementSibling : null;
    el.remove();
    if (t) t.remove();
    if (wahlId === id) waehlen('');
    if (hoverId === id) hoverId = '';
    zaehlerStand();

    return bauen('weg', { block: id })
      .then(() => {
        if (!o.stumm) {
          merken({ art: 'weg', id, nach, was: name + ' entfernt' });
          toast('„' + name + '" entfernt', { knopf: 'Rückgängig', dann: rueckgaengig });
        }
      })
      .catch(() => {
        bloeckeEl.insertBefore(el, platzB && platzB.parentNode === bloeckeEl ? platzB : null);
        if (t) aufbau.insertBefore(t, platzT && platzT.parentNode === aufbau ? platzT : linieAufbau);
        zaehlerStand();
      });
  }

  function zurueckholen(id, nach) {
    return bauen('einsetzen', { block: id, nach }).then(a => {
      const el = blockEinsetzen(a.block, nach);
      waehlen(a.block.id, { zeigen: true });
      aufleuchten(el);
    });
  }

  /**
   * Die Bausteine in eine neue Folge bringen – mit einer kurzen Bewegung,
   * damit man sieht, was wohin gewandert ist.
   */
  function ordnen(folge, bewegt) {
    const vorB = new Map(bloecke().map(b => [b, b.getBoundingClientRect().top]));
    const vorT = new Map($$('.bau-teil', aufbau).map(t => [t, t.getBoundingClientRect().top]));

    folge.forEach(id => {
      const b = block(id);
      if (b) bloeckeEl.appendChild(b);
      const t = teil(id);
      if (t) aufbau.insertBefore(t, $('[data-aufbau-leer]', aufbau) || linieAufbau);
    });
    aufbau.appendChild(linieAufbau);

    if (!bewegungArm) {
      vorB.forEach((alt, b) => {
        const d = (alt - b.getBoundingClientRect().top) / faktor;
        if (Math.abs(d) > 1) b.animate([{ transform: 'translateY(' + d + 'px)' }, { transform: 'none' }], { duration: 260, easing: 'cubic-bezier(.2,.8,.2,1)' });
      });
      vorT.forEach((alt, t) => {
        const d = alt - t.getBoundingClientRect().top;
        if (Math.abs(d) > 1) t.animate([{ transform: 'translateY(' + d + 'px)' }, { transform: 'none' }], { duration: 220, easing: 'cubic-bezier(.2,.8,.2,1)' });
      });
    }
    panelStelle();
    bald();
    if (bewegt) {
      blockZeigen(bewegt);
      aufleuchten(block(bewegt));
      zeichnen();
    }
    return bauen('reihenfolge', { reihenfolge: folge.join(',') });
  }

  function verschieben(id, index) {
    const vorher = kennungen();
    const alt = vorher.indexOf(id);
    if (alt === -1) return;
    const nachher = vorher.filter(k => k !== id);
    const ziel = index > alt ? index - 1 : index;
    nachher.splice(ziel, 0, id);
    if (nachher.join() === vorher.join()) return;
    ordnen(nachher, id);
    merken({ art: 'reihe', id, vorher, nachher, was: nameVon(id) + ' verschoben' });
  }

  function schieben(id, richtung) {
    const liste = kennungen();
    const i = liste.indexOf(id);
    const ziel = i + richtung;
    if (i === -1 || ziel < 0 || ziel >= liste.length) return;
    verschieben(id, richtung < 0 ? ziel : ziel + 1);
  }

  /* ================================================ Ziehen =========== */

  /*
   * Ein Ziehen beginnt erst, wenn der Zeiger sich ein paar Pixel bewegt
   * hat. Bis dahin ist es ein Klick – auf eine Zeile im Aufbau, auf ein
   * Schild, auf einen Eintrag im Vorrat.
   */
  let zugGeradeVorbei = false;

  const zugChip = neu('div', 'bau-zug');
  zugChip.hidden = true;
  document.body.appendChild(zugChip);

  function zugVorbereiten(e, art, wert, name, quelle) {
    if (!schreiben || e.button > 0 || zug) return;
    zug = { art, wert, name, quelle, x0: e.clientX, y0: e.clientY, aktiv: false, ziel: null, pointer: e.pointerId };
    document.addEventListener('pointermove', zugBewegen);
    document.addEventListener('pointerup', zugLoslassen);
    document.addEventListener('pointercancel', zugAbbrechen);
  }

  function zugStarten() {
    zug.aktiv = true;
    document.body.classList.add('bau-zieht');
    const symbol = zug.art === 'neu'
      ? ($('.bau-vorrat__symbol', zug.quelle) || {}).innerHTML || SVG.plus
      : ($('.bau-teil__symbol', teil(zug.wert) || document.createElement('i')) || {}).innerHTML || SVG.grip;
    zugChip.innerHTML = '<span class="bau-zug__symbol">' + symbol + '</span><span>' + esc(zug.name) + '</span>';
    zugChip.hidden = false;
    if (zug.art === 'block') {
      const b = block(zug.wert);
      const t = teil(zug.wert);
      if (b) b.classList.add('ist-gezogen');
      if (t) t.classList.add('ist-gezogen');
    } else {
      zug.quelle.classList.add('ist-gezogen');
      vorrat.classList.remove('ist-offen');      // die ganze Seite als Ziel frei machen
    }
    hoverId = '';
    einsetzerZiel = null;
    zeichnen();
  }

  function zugBewegen(e) {
    if (!zug || e.pointerId !== zug.pointer) return;
    if (!zug.aktiv) {
      if (Math.hypot(e.clientX - zug.x0, e.clientY - zug.y0) < 6) return;
      zugStarten();
    }
    e.preventDefault();
    zug.x = e.clientX;
    zug.y = e.clientY;
    zugChip.style.transform = 'translate(' + (e.clientX + 14) + 'px,' + (e.clientY + 12) + 'px)';
    zug.ziel = zielBestimmen(e.clientX, e.clientY);
    zugChip.classList.toggle('ist-aus', !zug.ziel || zug.ziel.gleich);
    rollenStarten();
    zeichnen();
  }

  /** Wo würde er landen? Index in der aktuellen Folge – oder null. */
  function zielBestimmen(x, y, z = zug) {
    if (!z) return null;
    const liste = kennungen();
    const imBereich = (el) => { const r = el.getBoundingClientRect(); return x >= r.left && x <= r.right && y >= r.top && y <= r.bottom; };
    let ort = null;
    let elemente = [];
    if (imBereich(aufbau)) { ort = 'aufbau'; elemente = $$('.bau-teil', aufbau); }
    else if (imBereich(buehne)) { ort = 'leinwand'; elemente = bloecke(); }
    if (!ort) return null;

    let index = elemente.length;
    for (let i = 0; i < elemente.length; i++) {
      const r = elemente[i].getBoundingClientRect();
      if (y < r.top + r.height / 2) { index = i; break; }
    }
    /* Dieselbe Stelle, an der er schon steht: kein Ziel. */
    if (z.art === 'block') {
      const alt = liste.indexOf(z.wert);
      if (index === alt || index === alt + 1) return { ort, index: alt, gleich: true };
    }
    return { ort, index, gleich: false };
  }

  const zwischenText = (index) => {
    const liste = kennungen().filter(k => !(zug.art === 'block' && k === zug.wert));
    const alle = kennungen();
    const davor = alle[index - 1];
    const danach = alle[index];
    const n = (id) => id && !(zug.art === 'block' && id === zug.wert) ? nameVon(id) : '';
    const a = n(davor) || n(alle[index - 2]);
    const b = n(danach) || n(alle[index + 1]);
    if (!liste.length) return 'Hier einsetzen';
    if (!a) return 'Ganz oben';
    if (!b) return 'Ganz unten';
    return 'Zwischen „' + a + '" und „' + b + '"';
  };

  function zugLinienZeichnen(b) {
    const z = zug.ziel;
    if (!z) { linie.hidden = true; linieAufbau.hidden = true; return; }

    /* In der Seite. Steht der Baustein schon an dieser Stelle, zeigt die
       Linie das auch – gestrichelt, „bleibt, wo er ist" –, statt einfach
       zu verschwinden und rätseln zu lassen. */
    const liste = bloecke();
    let y;
    if (z.gleich) {
      const eigen = block(zug.wert).getBoundingClientRect();
      y = eigen.top + eigen.height / 2 - b.top;
    } else if (!liste.length) {
      const l = lage(leinwand, b);
      y = l.y + 40;
    } else if (z.index === 0) {
      y = liste[0].getBoundingClientRect().top - b.top;
    } else if (z.index >= liste.length) {
      y = liste[liste.length - 1].getBoundingClientRect().bottom - b.top;
    } else {
      const oben = liste[z.index - 1].getBoundingClientRect().bottom;
      const unten = liste[z.index].getBoundingClientRect().top;
      y = (oben + unten) / 2 - b.top;
    }
    const lw = lage(leinwand, b);
    const yImBild = Math.min(Math.max(y, 22), b.height - 22);
    linie.hidden = false;
    linie.classList.toggle('ist-gleich', !!z.gleich);
    setze(linie, lw.x + 8, z.gleich ? yImBild : Math.min(Math.max(y, 2), b.height - 2), lw.w - 16);
    const schild = $('.bau-linie__schild', linie);
    schild.textContent = z.gleich ? 'Bleibt an seiner Stelle' : zwischenText(z.index);
    schild.classList.toggle('ist-unten', yImBild < 44);
    linie.classList.toggle('ist-ausserhalb', !z.gleich && (y < 0 || y > b.height));

    /* Und im Aufbau – dieselbe Stelle, damit man beides zusammen sieht. */
    const teile = $$('.bau-teil', aufbau);
    let ty = 8;
    if (teile.length) {
      if (z.index >= teile.length) ty = teile[teile.length - 1].offsetTop + teile[teile.length - 1].offsetHeight + 1;
      else ty = teile[z.index].offsetTop - 1;
    }
    linieAufbau.hidden = !!z.gleich;
    linieAufbau.style.top = ty + 'px';
  }

  /* Am Rand rollt es mit – je näher am Rand, desto schneller. */
  let rollUhr = 0;
  function rollenStarten() {
    if (rollUhr) return;
    const schritt = () => {
      rollUhr = 0;
      if (!zug || !zug.aktiv) return;
      let bewegt = false;
      [[rolle, buehne], [aufbau, aufbau]].forEach(([el, bereich]) => {
        const r = bereich.getBoundingClientRect();
        if (zug.x < r.left || zug.x > r.right) return;
        const zone = Math.min(80, r.height / 4);
        let v = 0;
        if (zug.y < r.top + zone) v = -Math.ceil((r.top + zone - zug.y) / zone * 18);
        else if (zug.y > r.bottom - zone) v = Math.ceil((zug.y - (r.bottom - zone)) / zone * 18);
        if (v) {
          const vorher = el.scrollTop;
          el.scrollTop += v;
          if (el.scrollTop !== vorher) bewegt = true;
        }
      });
      if (bewegt) {
        zug.ziel = zielBestimmen(zug.x, zug.y);
        zeichnenJetzt();
        rollUhr = requestAnimationFrame(schritt);
      }
    };
    rollUhr = requestAnimationFrame(schritt);
  }

  function zugAufraeumen() {
    document.removeEventListener('pointermove', zugBewegen);
    document.removeEventListener('pointerup', zugLoslassen);
    document.removeEventListener('pointercancel', zugAbbrechen);
    document.body.classList.remove('bau-zieht');
    zugChip.hidden = true;
    linie.hidden = true;
    linieAufbau.hidden = true;
    $$('.ist-gezogen', bau).forEach(el => el.classList.remove('ist-gezogen'));
    cancelAnimationFrame(rollUhr);
    rollUhr = 0;
  }

  function zugLoslassen(e) {
    if (!zug || e.pointerId !== zug.pointer) return;
    const z = zug;
    zug = null;
    zugAufraeumen();
    if (!z.aktiv) { zeichnen(); return; }    // war ein Klick – den erledigt das Klick-Ereignis
    zugGeradeVorbei = true;
    setTimeout(() => { zugGeradeVorbei = false; }, 60);

    const ziel = zielBestimmen(e.clientX, e.clientY, z) || z.ziel;
    if (ziel && !ziel.gleich) {
      if (z.art === 'block') {
        verschieben(z.wert, ziel.index);
      } else {
        const liste = kennungen();
        hinzufuegen(z.wert, ziel.index === 0 ? 'anfang' : liste[ziel.index - 1], z.name);
      }
    } else if (z.art === 'neu') {
      toast('Zum Einfügen in die Seite oder den Aufbau ziehen – oder einfach anklicken.');
    }
    zeichnen();
  }

  function zugAbbrechen() {
    if (!zug) return;
    const war = zug.aktiv;
    zug = null;
    zugAufraeumen();
    if (war) { zugGeradeVorbei = true; setTimeout(() => { zugGeradeVorbei = false; }, 60); }
    zeichnen();
  }

  /* Wer zieht, klickt nicht: das Klick-Ereignis nach dem Loslassen schlucken. */
  document.addEventListener('click', (e) => {
    if (zugGeradeVorbei) { e.preventDefault(); e.stopPropagation(); }
  }, true);

  if (schreiben) {
    [schildWahl, schildHover].forEach(s => {
      s.addEventListener('pointerdown', (e) => {
        const id = s === schildWahl ? wahlId : hoverId;
        if (!id) return;
        e.preventDefault();
        zugVorbereiten(e, 'block', id, nameVon(id), s);
      });
      s.addEventListener('click', () => {
        const id = s === schildWahl ? wahlId : hoverId;
        if (id && id !== wahlId) waehlen(id);
      });
    });

    aufbau.addEventListener('pointerdown', (e) => {
      const t = e.target.closest('.bau-teil');
      if (!t) return;
      /* Mit dem Finger nur am Griff – sonst ließe sich die Liste nicht mehr rollen. */
      if (e.pointerType === 'touch' && !e.target.closest('[data-griff]')) return;
      zugVorbereiten(e, 'block', t.dataset.teil, t.dataset.name || 'Baustein', t);
    });
    aufbau.addEventListener('dragstart', (e) => e.preventDefault());
  }

  /* ================================================ Vorrat =========== */

  /*
   * Aus der Klappliste unten links wird eine Auswahl, die über der Seite
   * aufgeht: neben der Spalte (Knopf „+ Baustein") oder genau dort, wo man
   * auf „+" geklickt hat. Anklicken setzt den Baustein ein, Ziehen legt
   * ihn dorthin, wo man loslässt.
   */
  let vorratNach = null;     // null: hinter den gewählten oder ans Ende
  let vorrat = vorratRoh;
  let suche = null;

  if (vorrat && schreiben) {
    /* Aus <details> wird ein gewöhnlicher Kasten: Den Inhalt eines
       <details> legt der Browser in eine eigene Hülle, und darin greift
       die Höhenbegrenzung nicht – die Liste lief über den Rand hinaus. */
    const kasten = document.createElement('div');
    kasten.className = vorrat.className;
    kasten.setAttribute('data-vorrat', '');
    Array.from(vorrat.children).forEach(k => { if (k.tagName !== 'SUMMARY') kasten.appendChild(k); });
    vorrat.remove();
    vorrat = kasten;
    bau.appendChild(vorrat);
    const kopf = neu('div', 'bau-vorrat__kopf',
      '<span>Baustein hinzufügen<small>Anklicken fügt ein – oder in die Seite ziehen.</small></span>' +
      '<button type="button" class="rundknopf rundknopf--klein" aria-label="Schließen" data-vorrat-zu>' +
      '<svg class="ico" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg></button>');
    vorrat.insertBefore(kopf, vorrat.firstChild);
    vorrat.setAttribute('role', 'dialog');
    vorrat.setAttribute('aria-label', 'Baustein hinzufügen');
    suche = $('[data-vorrat-suche]', vorrat);

    $$('.bau-vorrat__teil', vorrat).forEach(k => {
      k.addEventListener('pointerdown', (e) => {
        if (e.pointerType === 'touch') return;        // auf dem Tablet: antippen
        zugVorbereiten(e, 'neu', k.dataset.neuerTyp, k.dataset.name, k);
      });
      k.addEventListener('dragstart', (e) => e.preventDefault());
    });

    vorrat.addEventListener('submit', (e) => e.preventDefault());
    vorrat.addEventListener('click', (e) => {
      if (e.target.closest('[data-vorrat-zu]')) { vorratSchliessen(); return; }
      const k = e.target.closest('.bau-vorrat__teil');
      if (!k) return;
      e.preventDefault();
      const nach = vorratNach !== null ? vorratNach : (wahlId || '');
      vorratSchliessen();
      hinzufuegen(k.dataset.neuerTyp, nach, k.dataset.name);
    });

    if (suche) {
      suche.addEventListener('input', vorratFiltern);
      suche.addEventListener('keydown', (e) => {
        const sichtbar = $$('.bau-vorrat__teil', vorrat).filter(k => !k.closest('form').hidden);
        const i = sichtbar.findIndex(k => k.classList.contains('ist-aktiv'));
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
          e.preventDefault();
          const n = e.key === 'ArrowDown' ? Math.min(sichtbar.length - 1, i + 1) : Math.max(0, i - 1);
          sichtbar.forEach((k, j) => k.classList.toggle('ist-aktiv', j === n));
          if (sichtbar[n]) sichtbar[n].scrollIntoView({ block: 'nearest' });
        } else if (e.key === 'Enter') {
          e.preventDefault();
          const k = sichtbar[i > -1 ? i : 0];
          if (k) k.click();
        }
      });
    }
  }

  function vorratFiltern() {
    const worte = (suche.value || '').toLowerCase().split(/\s+/).filter(Boolean);
    let treffer = 0;
    $$('.bau-vorrat__form', vorrat).forEach(f => {
      const k = $('.bau-vorrat__teil', f);
      const passt = worte.every(w => (k.dataset.suche || '').includes(w));
      f.hidden = !passt;
      k.classList.remove('ist-aktiv');
      if (passt) treffer++;
    });
    /* Gruppenüberschriften ohne sichtbaren Eintrag darunter ausblenden. */
    $$('[data-vorrat-gruppe]', vorrat).forEach(g => {
      let el = g.nextElementSibling;
      let sichtbar = false;
      while (el && !el.matches('[data-vorrat-gruppe], [data-vorrat-nichts]')) {
        if (el.matches('.bau-vorrat__form') && !el.hidden) sichtbar = true;
        el = el.nextElementSibling;
      }
      g.hidden = !sichtbar;
    });
    const erster = $$('.bau-vorrat__form', vorrat).find(f => !f.hidden);
    if (erster && worte.length) $('.bau-vorrat__teil', erster).classList.add('ist-aktiv');
    const nichts = $('[data-vorrat-nichts]', vorrat);
    if (nichts) nichts.hidden = treffer > 0;
  }

  function vorratOeffnen(o = {}) {
    if (!vorrat || !schreiben) return;
    vorratNach = o.nach !== undefined ? o.nach : null;
    const b = bau.getBoundingClientRect();
    const breite = Math.min(360, b.width - 24);
    if (o.x !== undefined) {
      /* Als Blase an der Stelle, an der geklickt wurde. */
      const hoehe = Math.min(520, b.height - 90);
      let x = o.x - b.left - breite / 2;
      x = Math.max(12, Math.min(x, b.width - breite - 12));
      let y = o.y - b.top + 18;
      if (y + hoehe > b.height - 12) y = Math.max(64, o.y - b.top - hoehe - 18);
      Object.assign(vorrat.style, { left: x + 'px', top: y + 'px', width: breite + 'px', height: hoehe + 'px', bottom: 'auto' });
    } else {
      const links = $('.bau__links', bau).getBoundingClientRect();
      const x = links.width > 0 && getComputedStyle($('.bau__links', bau)).position !== 'absolute' ? links.right - b.left + 10 : 12;
      Object.assign(vorrat.style, { left: x + 'px', top: '66px', width: breite + 'px', height: 'auto', bottom: '12px' });
    }
    if (suche) { suche.value = ''; vorratFiltern(); }
    vorrat.classList.add('ist-offen');
    bau.classList.remove('ist-aufbau-offen');
    setTimeout(() => { if (suche) suche.focus(); }, 30);
  }

  function vorratSchliessen() {
    if (vorrat) vorrat.classList.remove('ist-offen');
  }

  $$('[data-vorrat-auf]', bau).forEach(k => k.addEventListener('click', () => {
    if (vorrat.classList.contains('ist-offen')) vorratSchliessen(); else vorratOeffnen();
  }));
  $$('[data-einfuegen]', bau).forEach(k => k.addEventListener('click', (e) => {
    const r = k.getBoundingClientRect();
    vorratOeffnen({ nach: k.dataset.einfuegen || '', x: r.left + r.width / 2, y: r.top });
    e.stopPropagation();
  }));
  document.addEventListener('pointerdown', (e) => {
    if (!vorrat || !vorrat.classList.contains('ist-offen') || zug) return;
    if (e.target.closest('[data-vorrat], [data-vorrat-auf], [data-einfuegen], .bau-einsetzer')) return;
    vorratSchliessen();
  });

  /* ================================================ Tastatur ========= */

  const schreibtGerade = (el) => el && (el.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(el.tagName));

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      if (zug) { zugAbbrechen(); return; }
      if (vorrat && vorrat.classList.contains('ist-offen')) { vorratSchliessen(); return; }
    }
    if (!schreiben || schreibtGerade(document.activeElement) || document.querySelector('dialog[open]')) return;
    const strg = e.ctrlKey || e.metaKey;

    if (strg && e.key.toLowerCase() === 'z') { e.preventDefault(); if (e.shiftKey) wiederholen(); else rueckgaengig(); return; }
    if (strg && e.key.toLowerCase() === 'y') { e.preventDefault(); wiederholen(); return; }
    if (e.key === 'Escape' && wahlId) { waehlen(''); return; }
    if (!wahlId) return;
    if (e.key === 'Delete' || e.key === 'Backspace') { e.preventDefault(); entfernen(wahlId); return; }
    if (strg && e.key.toLowerCase() === 'd') { e.preventDefault(); kopieren(wahlId); return; }
    if (e.altKey && (e.key === 'ArrowUp' || e.key === 'ArrowDown')) { e.preventDefault(); schieben(wahlId, e.key === 'ArrowUp' ? -1 : 1); return; }
    if (!e.altKey && !strg && (e.key === 'ArrowUp' || e.key === 'ArrowDown')) {
      const liste = kennungen();
      const i = liste.indexOf(wahlId) + (e.key === 'ArrowUp' ? -1 : 1);
      if (liste[i]) { e.preventDefault(); waehlen(liste[i], { zeigen: true }); }
    }
  });

  /* ==========================================================================
     Direkt in der Vorschau schreiben
     --------------------------------------------------------------------------
     Man klickt das Wort an und schreibt. Der Renderer sagt, was wohin gehört –
     jedes bearbeitbare Stück trägt `data-feld`, bei Listen zusätzlich
     `data-nr` und `data-unter`. Gespeichert wird über app/baustein.php.
     ========================================================================== */

  /**
   * Aus dem bearbeiteten HTML wieder den Text machen, der gespeichert wird.
   *
   *   text        eine Zeile
   *   marker      wie text, aber <mark> wird wieder zu *Sternchen*
   *   mehrzeilig  Absätze (<p>) trennen zwei Umbrüche, <br> einen
   *   zeilen      jede Zeile ein Eintrag (die Merkmale einer Preistafel)
   */
  const auslesen = (el, art) => {
    if (art === 'text') {
      let roh = '';
      Array.prototype.forEach.call(el.childNodes, (n) => {
        if (n.nodeType === 1 && n.hasAttribute && n.hasAttribute('data-bau-zutat')) return;
        roh += n.textContent || '';
      });
      return roh.replace(/ /g, ' ').replace(/\s+/g, ' ').trim();
    }
    const absatz = art === 'zeilen' ? '\n' : '\n\n';
    let text = '';
    const gehe = (knoten) => {
      Array.prototype.forEach.call(knoten.childNodes, (n) => {
        if (n.nodeType === 3) { text += n.nodeValue; return; }
        if (n.nodeType !== 1) return;
        if (n.hasAttribute('data-bau-zutat')) return;
        if (n.tagName === 'BR') { text += '\n'; return; }
        if (n.tagName === 'MARK') { text += '*' + (n.textContent || '') + '*'; return; }
        if (/^(P|DIV|LI)$/.test(n.tagName) && text !== '' && !/\n$/.test(text)) text += absatz;
        gehe(n);
      });
    };
    gehe(el);
    return text.replace(/ /g, ' ').replace(/\n{3,}/g, '\n\n').trim();
  };

  const herkunft = (el) => {
    const b = el.closest('[data-block-id]');
    if (!b) return null;
    const d = { block: b.dataset.blockId, feld: el.dataset.feld };
    if (el.dataset.nr !== undefined) d.nr = el.dataset.nr;
    if (el.dataset.unter) d.unter = el.dataset.unter;
    return d;
  };

  const leer = (el) => el.classList.toggle('ist-leer', auslesen(el, el.dataset.art) === '');

  const feldSpeichern = (el) => {
    const wert = auslesen(el, el.dataset.art);
    if (wert === el.dataset.stand) return Promise.resolve();
    const woher = herkunft(el);
    if (!woher) return Promise.resolve();
    el.dataset.stand = wert;
    leer(el);
    panelAbgleichen(woher, wert);
    const b = el.closest('.bau-block');
    return einreihen('/app/baustein.php', Object.assign({ aktion: 'feld', wert, seite: seiteId }, woher))
      .then(() => {
        /* Die Zeile im Aufbau zeigt die Überschrift – sie soll mitgehen. */
        const t = b ? teil(b.dataset.blockId) : null;
        const kurz = t ? $('.bau-teil__kurz', t) : null;
        if (kurz && (woher.feld === 'titel' || woher.feld === 'ueberschrift') && woher.nr === undefined) {
          kurz.textContent = wert.replace(/\*/g, '').slice(0, 48);
        }
      })
      .catch(() => {
        el.classList.add('ist-fehler');
        setTimeout(() => el.classList.remove('ist-fehler'), 2500);
      });
  };

  function felderEinrichten(wurzel) {
    if (!schreiben) return;
    $$('[data-feld]', wurzel).filter(el => el.dataset.art !== 'bild').forEach(el => {
      el.setAttribute('contenteditable', 'true');
      el.setAttribute('spellcheck', 'true');
      el.classList.add('bau-feld');
      el.dataset.stand = auslesen(el, el.dataset.art);
      leer(el);
    });
    /*
     * An jeden Eintrag ein Kreuz. Es steht nicht im HTML des Renderers,
     * weil es dort auf der Website mitgeschleppt würde.
     */
    $$('[data-eintrag]', wurzel).forEach(t => {
      if ($(':scope > .bau-eintrag-weg', t)) return;
      const k = document.createElement('button');
      k.type = 'button';
      k.className = 'bau-eintrag-weg';
      k.setAttribute('data-eintrag-weg', '');
      k.setAttribute('data-bau-zutat', '');
      k.setAttribute('aria-label', 'Diesen Eintrag entfernen');
      k.textContent = '✕';
      t.appendChild(k);
      t.classList.add('bau-eintrag');
    });
  }

  if (schreiben) {
    felderEinrichten(leinwand);
    let tippUhr = null;

    leinwand.addEventListener('input', (e) => {
      const el = e.target.closest('[data-feld]');
      if (!el || el.dataset.art === 'bild') return;
      leer(el);
      clearTimeout(tippUhr);
      tippUhr = setTimeout(() => feldSpeichern(el), 700);
      bald();
    });
    leinwand.addEventListener('focusout', (e) => {
      const el = e.target.closest('[data-feld]');
      if (!el || el.dataset.art === 'bild') return;
      clearTimeout(tippUhr);
      feldSpeichern(el);
    });
    leinwand.addEventListener('focusin', (e) => {
      const el = e.target.closest('[data-feld]');
      if (el) el.dataset.vorher = el.innerHTML;
    });
    leinwand.addEventListener('keydown', (e) => {
      const el = e.target.closest('[data-feld]');
      if (!el) return;
      /* In einer Überschrift beendet die Eingabetaste die Eingabe. */
      if (e.key === 'Enter' && (el.dataset.art === 'text' || el.dataset.art === 'marker')) {
        e.preventDefault();
        el.blur();
        return;
      }
      /* Escape nimmt zurück, was seit dem Hineinklicken getippt wurde. */
      if (e.key === 'Escape') {
        e.preventDefault();
        e.stopPropagation();
        el.innerHTML = el.dataset.vorher || '';
        leer(el);
        el.blur();
      }
    });
    /* Eingefügter Text kommt als Text an, nicht als fremdes HTML aus Word. */
    leinwand.addEventListener('paste', (e) => {
      const el = e.target.closest('[data-feld]');
      if (!el) return;
      e.preventDefault();
      const text = (e.clipboardData || window.clipboardData).getData('text/plain');
      document.execCommand('insertText', false, text);
    });
    leinwand.addEventListener('drop', (e) => { if (e.target.closest('[data-feld]')) e.preventDefault(); });

    /* Bilder: anklicken heißt wechseln. */
    leinwand.addEventListener('click', (e) => {
      const bild = e.target.closest('[data-feld][data-art="bild"]');
      if (!bild || !window.gpBildWaehlen) return;
      e.preventDefault();
      const woher = herkunft(bild);
      if (!woher) return;
      const bl = bild.closest('.bau-block');
      if (bl && bl.dataset.blockId !== wahlId) waehlen(bl.dataset.blockId);
      window.gpBildWaehlen((pfad) => {
        einreihen('/app/baustein.php', Object.assign({ aktion: 'feld', wert: pfad, seite: seiteId }, woher))
          .then(() => blockNeu(woher.block));
      });
    });

    /* Einträge in Listen: anhängen und entfernen. */
    leinwand.addEventListener('click', (e) => {
      const plus = e.target.closest('[data-plus]');
      if (plus) {
        e.preventDefault();
        const b = plus.closest('[data-block-id]');
        einreihen('/app/baustein.php', { aktion: 'eintrag_neu', block: b.dataset.blockId, feld: plus.dataset.plus, seite: seiteId })
          .then(() => blockNeu(b.dataset.blockId));
        return;
      }
      const weg = e.target.closest('[data-eintrag-weg]');
      if (weg) {
        e.preventDefault();
        const t = weg.closest('[data-eintrag]');
        const b = weg.closest('[data-block-id]');
        t.style.opacity = '.3';
        einreihen('/app/baustein.php', { aktion: 'eintrag_weg', block: b.dataset.blockId,
          feld: t.dataset.eintrag, nr: t.dataset.nr, seite: seiteId })
          .then(() => blockNeu(b.dataset.blockId))
          .catch(() => { t.style.opacity = ''; });
      }
    });

    /* Der gelbe Textmarker: `*Mit einem Plan.*` setzt einen Teil auf Gelb. */
    const markerLeiste = document.createElement('div');
    markerLeiste.className = 'bau-marker';
    markerLeiste.innerHTML = '<button type="button">Gelb hervorheben</button>';
    document.body.appendChild(markerLeiste);
    const markerFeld = () => {
      const a = window.getSelection();
      if (!a || a.isCollapsed || !a.rangeCount) return null;
      const n = a.anchorNode.nodeType === 1 ? a.anchorNode : a.anchorNode.parentNode;
      const el = n && n.closest ? n.closest('[data-feld][data-art="marker"]') : null;
      return el && leinwand.contains(el) ? el : null;
    };
    document.addEventListener('selectionchange', () => {
      const el = markerFeld();
      if (!el) { markerLeiste.classList.remove('ist-da'); return; }
      const k = window.getSelection().getRangeAt(0).getBoundingClientRect();
      markerLeiste.style.left = Math.round(k.left + k.width / 2) + 'px';
      markerLeiste.style.top = Math.round(k.top - 8) + 'px';
      markerLeiste.classList.add('ist-da');
    });
    markerLeiste.addEventListener('mousedown', (e) => e.preventDefault());
    markerLeiste.addEventListener('click', () => {
      const el = markerFeld();
      if (!el) return;
      const a = window.getSelection();
      const bereich = a.getRangeAt(0);
      const schon = a.anchorNode.parentNode.closest && a.anchorNode.parentNode.closest('mark');
      if (schon) {
        const eltern = schon.parentNode;
        while (schon.firstChild) eltern.insertBefore(schon.firstChild, schon);
        eltern.removeChild(schon);
        eltern.normalize();
      } else {
        const mark = document.createElement('mark');
        mark.appendChild(bereich.extractContents());
        bereich.insertNode(mark);
      }
      markerLeiste.classList.remove('ist-da');
      feldSpeichern(el);
    });

    /*
     * Wer im letzten Feld noch tippt und die Seite wechselt, soll den Satz
     * nicht verlieren – `sendBeacon` schickt ihn los, auch wenn die Seite
     * schon geht.
     */
    window.addEventListener('pagehide', () => {
      const el = document.activeElement;
      if (!el || !el.dataset || !el.dataset.feld || el.dataset.art === 'bild') return;
      const wert = auslesen(el, el.dataset.art);
      if (wert === el.dataset.stand) return;
      const woher = herkunft(el);
      if (!woher || !navigator.sendBeacon) return;
      const d = new FormData();
      Object.entries(Object.assign({ aktion: 'feld', wert, seite: seiteId, _csrf: window.gpCsrf || '' }, woher))
        .forEach(([k, v]) => d.append(k, v));
      navigator.sendBeacon(basis + '/app/baustein.php', d);
    });
  }

  /* ================================================ Rollposition ===== */

  /* Wer die Seite neu lädt (etwa nach „Seite speichern"), steht wieder,
     wo er war – nicht oben. */
  const rollSchluessel = 'gp-bau-rolle-' + (seiteId || location.search);
  window.addEventListener('pagehide', () => {
    try { sessionStorage.setItem(rollSchluessel, String(rolle.scrollTop)); } catch (e) {}
  });

  /* ================================================ Los ============== */

  panelEinrichten();
  geraetSetzen(geraet, false);
  requestAnimationFrame(() => {
    let alt = null;
    try { alt = sessionStorage.getItem(rollSchluessel); } catch (e) {}
    if (wahlId) blockZeigen(wahlId, false);
    else if (alt) rolle.scrollTop = parseFloat(alt) || 0;
    bau.classList.toggle('ist-panel-offen', !!wahlId);
    zeichnen();
  });
  if (document.fonts && document.fonts.ready) document.fonts.ready.then(bald);
})();
