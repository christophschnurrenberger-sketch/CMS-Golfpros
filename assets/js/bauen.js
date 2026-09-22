/* ==========================================================================
   TeePilot – direkt in der Vorschau schreiben
   --------------------------------------------------------------------------
   Bis hierher lief der Baukasten so: links den Baustein anklicken, rechts
   das Feld suchen, dort tippen, speichern, warten, hinsehen. Vier Schritte
   zwischen „das Wort gefällt mir nicht" und „das Wort ist weg".

   Jetzt klickt man das Wort an und schreibt. Die Vorschau ist das
   Formular. Rechts bleibt nur, was in der Seite keine Gestalt hat und sich
   deshalb auch nicht anklicken lässt: Ausrichtung, Höhe, Schalter, das
   Ziel eines Knopfes.

   Der Renderer sagt, was wohin gehört – jedes bearbeitbare Stück trägt
   `data-feld`, bei Listen zusätzlich `data-nr` und `data-unter`. Dieses
   Skript kennt die Bausteine nicht und muss es auch nicht: Es liest die
   Angaben, schickt den Wert an app/baustein.php und lässt den Server
   entscheiden, ob es ihn annimmt.
   ========================================================================== */

(function () {
  'use strict';

  const leinwand = document.getElementById('leinwand');
  if (!leinwand || !leinwand.dataset.seite) return;

  const $  = (w, k) => (k || document).querySelector(w);
  const $$ = (w, k) => Array.from((k || document).querySelectorAll(w));
  const seiteId = leinwand.dataset.seite;

  /* ------------------------------------------------------- Stand-Anzeige */

  /*
   * Eine Zeile am unteren Rand, die sagt, was gerade passiert. Ohne sie
   * wüsste niemand, ob das Getippte angekommen ist – und ein Baukasten,
   * der ohne sichtbaren Speicherknopf arbeitet, muss das sagen.
   */
  const anzeige = document.createElement('div');
  anzeige.className = 'bau-stand';
  anzeige.setAttribute('aria-live', 'polite');
  document.body.appendChild(anzeige);
  let standUhr = null;

  const stand = (text, art) => {
    clearTimeout(standUhr);
    anzeige.textContent = text;
    anzeige.className = 'bau-stand' + (art ? ' bau-stand--' + art : '') + (text ? ' ist-da' : '');
    if (art !== 'fehler' && text) {
      standUhr = setTimeout(() => anzeige.classList.remove('ist-da'), 1600);
    }
  };

  /* ---------------------------------------------------------- Auslesen - */

  /**
   * Aus dem bearbeiteten HTML wieder den Text machen, der gespeichert wird.
   *
   * Die Formen unterscheiden sich, weil der Renderer sie unterschiedlich
   * ausgibt:
   *
   *   text        eine Zeile; Umbrüche wären im Ergebnis ohnehin keine
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
      return roh.replace(/\u00a0/g, ' ').replace(/\s+/g, ' ').trim();
    }

    const absatz = art === 'zeilen' ? '\n' : '\n\n';
    let text = '';

    const gehe = (knoten) => {
      Array.prototype.forEach.call(knoten.childNodes, (n) => {
        if (n.nodeType === 3) { text += n.nodeValue; return; }
        if (n.nodeType !== 1) return;
        /* Was der Baukasten selbst angebaut hat, gehört nicht zum Text.
           Ohne diese Zeile landete das Kreuz zum Entfernen im Wert. */
        if (n.hasAttribute('data-bau-zutat')) return;
        if (n.tagName === 'BR') { text += '\n'; return; }
        if (n.tagName === 'MARK') { text += '*' + (n.textContent || '') + '*'; return; }
        /* Ein neuer Absatz beginnt – aber nicht vor dem ersten. */
        if (/^(P|DIV|LI)$/.test(n.tagName) && text !== '' && !/\n$/.test(text)) {
          text += absatz;
        }
        gehe(n);
      });
    };
    gehe(el);

    return text.replace(/ /g, ' ').replace(/\n{3,}/g, '\n\n').trim();
  };

  /* ---------------------------------------------------------- Speichern - */

  const paket = (daten) => {
    const koerper = new FormData();
    koerper.append('_csrf', window.gpCsrf || '');
    koerper.append('seite', seiteId);
    Object.keys(daten).forEach(k => koerper.append(k, daten[k]));
    return koerper;
  };

  const senden = (daten) => {
    /*
     * `keepalive` hält die Anfrage am Leben, auch wenn die Seite in
     * derselben Sekunde gewechselt wird. Ohne das wäre der letzte Satz
     * weg, sobald man nach dem Tippen sofort weiterklickt – und ein
     * Baukasten, der den letzten Satz verliert, ist keiner.
     */
    return fetch((window.gpBasis || '') + '/app/baustein.php',
                 { method: 'POST', body: paket(daten), keepalive: true })
      .then(r => r.json().then(a => ({ ok: r.ok, a })))
      .then(({ ok, a }) => {
        if (!ok) { throw new Error(a.fehler || 'Das hat nicht geklappt.'); }
        return a;
      });
  };

  /** Die Angaben, mit denen der Server das Feld wiederfindet. */
  const herkunft = (el) => {
    const block = el.closest('[data-block-id]');
    if (!block) return null;
    const d = { block: block.dataset.blockId, feld: el.dataset.feld };
    if (el.dataset.nr !== undefined) d.nr = el.dataset.nr;
    if (el.dataset.unter) d.unter = el.dataset.unter;
    return d;
  };

  const speichern = (el) => {
    const wert = auslesen(el, el.dataset.art);
    if (wert === el.dataset.stand) return Promise.resolve();

    const woher = herkunft(el);
    if (!woher) return Promise.resolve();

    woher.aktion = 'feld';
    woher.wert = wert;
    el.dataset.stand = wert;
    leer(el);

    stand('Wird gespeichert …');
    return senden(woher)
      .then(() => stand('Gespeichert', 'gut'))
      .catch((f) => {
        stand(f.message, 'fehler');
        el.classList.add('ist-fehler');
        setTimeout(() => el.classList.remove('ist-fehler'), 2500);
      });
  };

  /* Leeres Feld: der blasse Hinweis erscheint wieder. `:empty` reicht
     nicht – der Browser lässt beim Leeren gern ein <br> zurück. */
  const leer = (el) => {
    el.classList.toggle('ist-leer', auslesen(el, el.dataset.art) === '');
  };

  /* ------------------------------------------------------- Text bearbeiten */

  const felder = $$('[data-feld]', leinwand).filter(el => el.dataset.art !== 'bild');

  felder.forEach(el => {
    el.setAttribute('contenteditable', 'true');
    el.setAttribute('spellcheck', 'true');
    el.classList.add('bau-feld');
    el.dataset.stand = auslesen(el, el.dataset.art);
    leer(el);
  });

  let uhr = null;

  leinwand.addEventListener('input', (e) => {
    const el = e.target.closest('[data-feld]');
    if (!el || el.dataset.art === 'bild') return;
    leer(el);
    clearTimeout(uhr);
    uhr = setTimeout(() => speichern(el), 800);
  });

  leinwand.addEventListener('focusout', (e) => {
    const el = e.target.closest('[data-feld]');
    if (!el || el.dataset.art === 'bild') return;
    clearTimeout(uhr);
    speichern(el);
  });

  leinwand.addEventListener('keydown', (e) => {
    const el = e.target.closest('[data-feld]');
    if (!el) return;

    /* In einer Überschrift beendet die Eingabetaste die Eingabe. Ein
       Umbruch in einer einzeiligen Angabe käme nie in der Ausgabe an. */
    if (e.key === 'Enter' && (el.dataset.art === 'text' || el.dataset.art === 'marker')) {
      e.preventDefault();
      el.blur();
      return;
    }
    /* Escape nimmt zurück, was seit dem Hineinklicken getippt wurde. */
    if (e.key === 'Escape') {
      e.preventDefault();
      el.textContent = el.dataset.vorher || '';
      leer(el);
      el.blur();
    }
  });

  leinwand.addEventListener('focusin', (e) => {
    const el = e.target.closest('[data-feld]');
    if (el) { el.dataset.vorher = el.textContent; }
  });

  /*
   * Eingefügter Text kommt als Text an, nicht als fremdes HTML.
   *
   * Ohne das landet beim Einfügen aus Word eine Wolke aus <span
   * style="mso-..."> in der Seite – und weil der Renderer beim Ausgeben
   * escaped, stünde dieser Unrat danach sichtbar auf der Website.
   */
  leinwand.addEventListener('paste', (e) => {
    const el = e.target.closest('[data-feld]');
    if (!el) return;
    e.preventDefault();
    const text = (e.clipboardData || window.clipboardData).getData('text/plain');
    document.execCommand('insertText', false, text);
  });

  /* Kein Ziehen in die Felder hinein – sonst fällt fremdes HTML herein. */
  leinwand.addEventListener('drop', (e) => {
    if (e.target.closest('[data-feld]')) e.preventDefault();
  });

  /* ------------------------------------------------- Der gelbe Textmarker */

  /*
   * `*Mit einem Plan.*` setzt einen Teil der Überschrift auf Gelb. Das
   * Sternchenpaar ist im Formular schnell erklärt – in der Vorschau sieht
   * man aber nur das Gelb und wüsste nicht, wie man es hinbekommt. Deshalb
   * erscheint über einer markierten Stelle ein kleiner Knopf.
   */
  const markerLeiste = document.createElement('div');
  markerLeiste.className = 'bau-marker';
  markerLeiste.innerHTML = '<button type="button">Gelb hervorheben</button>';
  document.body.appendChild(markerLeiste);

  const markerZeigen = () => {
    const auswahl = window.getSelection();
    if (!auswahl || auswahl.isCollapsed || auswahl.rangeCount === 0) {
      markerLeiste.classList.remove('ist-da');
      return;
    }
    const el = (auswahl.anchorNode.nodeType === 1 ? auswahl.anchorNode : auswahl.anchorNode.parentNode)
      .closest('[data-feld][data-art="marker"]');
    if (!el || !leinwand.contains(el)) {
      markerLeiste.classList.remove('ist-da');
      return;
    }
    const k = auswahl.getRangeAt(0).getBoundingClientRect();
    markerLeiste.style.left = Math.round(k.left + k.width / 2) + 'px';
    markerLeiste.style.top  = Math.round(k.top - 8) + 'px';
    markerLeiste.classList.add('ist-da');
  };

  document.addEventListener('selectionchange', markerZeigen);

  markerLeiste.addEventListener('mousedown', (e) => e.preventDefault());
  markerLeiste.addEventListener('click', () => {
    const auswahl = window.getSelection();
    if (!auswahl || auswahl.isCollapsed) return;
    const el = (auswahl.anchorNode.nodeType === 1 ? auswahl.anchorNode : auswahl.anchorNode.parentNode)
      .closest('[data-feld][data-art="marker"]');
    if (!el) return;

    const bereich = auswahl.getRangeAt(0);
    const schon = (auswahl.anchorNode.parentNode.closest &&
                   auswahl.anchorNode.parentNode.closest('mark'));
    if (schon) {
      /* Wieder abnehmen: den Inhalt an die Stelle der Markierung setzen. */
      const eltern = schon.parentNode;
      while (schon.firstChild) { eltern.insertBefore(schon.firstChild, schon); }
      eltern.removeChild(schon);
      eltern.normalize();
    } else {
      const mark = document.createElement('mark');
      mark.appendChild(bereich.extractContents());
      bereich.insertNode(mark);
    }
    markerLeiste.classList.remove('ist-da');
    speichern(el);
  });

  /* ------------------------------------------------------------- Bilder - */

  leinwand.addEventListener('click', (e) => {
    const bild = e.target.closest('[data-feld][data-art="bild"]');
    if (!bild || !window.gpBildWaehlen) return;
    e.preventDefault();
    e.stopPropagation();

    const woher = herkunft(bild);
    if (!woher) return;
    window.gpBildWaehlen((pfad) => {
      woher.aktion = 'feld';
      woher.wert = pfad;
      stand('Wird gespeichert …');
      senden(woher)
        .then(() => location.reload())
        .catch(f => stand(f.message, 'fehler'));
    });
  }, true);

  /* -------------------------------------------------- Einträge in Listen - */

  leinwand.addEventListener('click', (e) => {
    const plus = e.target.closest('[data-plus]');
    if (plus) {
      e.preventDefault();
      e.stopPropagation();
      const block = plus.closest('[data-block-id]');
      stand('Wird angelegt …');
      senden({ aktion: 'eintrag_neu', block: block.dataset.blockId, feld: plus.dataset.plus })
        .then(() => location.reload())
        .catch(f => stand(f.message, 'fehler'));
      return;
    }

    const weg = e.target.closest('[data-eintrag-weg]');
    if (weg) {
      e.preventDefault();
      e.stopPropagation();
      const teil = weg.closest('[data-eintrag]');
      const block = weg.closest('[data-block-id]');
      stand('Wird entfernt …');
      senden({ aktion: 'eintrag_weg', block: block.dataset.blockId,
               feld: teil.dataset.eintrag, nr: teil.dataset.nr })
        .then(() => location.reload())
        .catch(f => stand(f.message, 'fehler'));
    }
  }, true);

  /*
   * An jeden Eintrag ein Kreuz. Es steht nicht im HTML des Renderers,
   * weil es dort auf der Website mitgeschleppt würde – hier ist es an
   * einer Stelle angebaut und nicht in fünfzehn Bausteinen verteilt.
   */
  $$('[data-eintrag]', leinwand).forEach(teil => {
    const kreuz = document.createElement('button');
    kreuz.type = 'button';
    kreuz.className = 'bau-eintrag-weg';
    kreuz.setAttribute('data-eintrag-weg', '');
    kreuz.setAttribute('data-bau-zutat', '');
    kreuz.setAttribute('aria-label', 'Diesen Eintrag entfernen');
    kreuz.textContent = '✕';
    teil.appendChild(kreuz);
    teil.classList.add('bau-eintrag');
  });

  /* ----------------------------------------------- Baustein auswählen --- */

  /*
   * Ein Klick auf den Baustein wählt ihn aus – aber nicht, wenn er einem
   * Feld, einem Knopf oder der Werkzeugleiste gilt. Vorher stand das als
   * `onclick` am Baustein selbst; mit beschreibbaren Feldern darin wäre
   * jeder Versuch, den Textzeiger zu setzen, ein Seitenwechsel gewesen.
   */
  leinwand.addEventListener('click', (e) => {
    /*
     * Die Werkzeugleiste hält ihre Klicks selbst auf (stopPropagation am
     * Baustein), hier stehen nur die Stellen, die zum Schreiben da sind.
     * Die Marke mit dem Namen des Bausteins gehört ausdrücklich nicht
     * dazu: Sie ist der sichtbarste Griff, um einen Baustein auszuwählen.
     */
    if (e.target.closest('[data-feld], [data-plus], [data-eintrag-weg], a, button')) {
      return;
    }
    const block = e.target.closest('[data-block-url]');
    if (block) { location.href = block.dataset.blockUrl; }
  });

  /* ---------------------------------------------- Nichts verloren geben - */

  /*
   * Wer im letzten Feld noch tippt und dann die Seite wechselt, soll den
   * Satz nicht verlieren – aber auch nicht gefragt werden, ob er die Seite
   * wirklich verlassen will. Diese Rückfrage ist die schlechteste Antwort
   * auf das Problem: Sie hält auf und speichert trotzdem nichts.
   *
   * `sendBeacon` schickt den Wert los und kümmert sich nicht mehr darum,
   * ob die Seite noch steht. Genau dafür gibt es das.
   */
  window.addEventListener('pagehide', () => {
    const el = document.activeElement;
    if (!el || !el.dataset || !el.dataset.feld || el.dataset.art === 'bild') return;

    const wert = auslesen(el, el.dataset.art);
    if (wert === el.dataset.stand) return;

    const woher = herkunft(el);
    if (!woher || !navigator.sendBeacon) return;
    woher.aktion = 'feld';
    woher.wert = wert;
    el.dataset.stand = wert;
    navigator.sendBeacon((window.gpBasis || '') + '/app/baustein.php', paket(woher));
  });
})();
