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

  /* ------------------------------------------- Kästchen zeigt Feld ---- */

  /*
   * Ein Kontrollkästchen blendet einen Abschnitt ein: data-zeigt="#ziel".
   *
   * Der Abschnitt steht im HTML offen da – zugeklappt wird er erst hier.
   * Ohne Skript sieht man also alles und kann alles ausfüllen; nur die
   * Bequemlichkeit fehlt. Umgekehrt (im HTML zu, per Skript auf) wäre das
   * Feld ohne Skript unerreichbar.
   */
  $$('[data-zeigt]').forEach(kaestchen => {
    const ziel = $(kaestchen.dataset.zeigt);
    if (!ziel) return;
    const um = () => { ziel.hidden = !kaestchen.checked; };
    um();
    kaestchen.addEventListener('change', um);
  });

  /* --------------------------------------------------- Bildwähler ----- */

  /*
   * Ein Bild aus der Mediathek holen, ohne die Seite zu verlassen.
   *
   * Vorher stand an jedem Bildfeld „Mediathek öffnen und Pfad einfügen".
   * Das ist kein Bedienschritt, das ist eine Bastelanleitung – zweiter Tab,
   * hochladen, Pfad abschreiben, zurückwechseln. Entsprechend standen auf
   * den Websites die Ersatzstreifen statt Bildern.
   *
   * Das Fenster entsteht erst beim ersten Klick und wird danach
   * wiederverwendet: Es enthält eine Liste, die der Server liefert, und
   * lädt auf Wunsch gleich eine neue Datei hoch.
   */
  (function () {
    /* Auch die Vorschau im Baukasten ruft den Wähler – dort hängt er an
       keinem Formularfeld, sondern an einem Bild in der Seite. */
    if (!document.querySelector('[data-bild-waehlen], [data-art="bild"]')) return;

    let fenster = null;
    let ziel = null;
    let rueckruf = null;

    /* Eine Adresse von außerhalb bleibt, wie sie ist; ein Pfad aus der
       eigenen Mediathek bekommt die Basis davor. */
    const adresse = (pfad) => (/^(https?:)?\/\//.test(pfad) || pfad.charAt(0) === '/')
      ? pfad
      : (window.gpBasis || '') + '/' + pfad;

    const vorschau = (feld) => {
      const huelle = feld.closest('[data-bildfeld]');
      if (!huelle) return;
      const pfad  = (feld.value || '').trim();
      const schau = $('.bildfeld__schau', huelle);
      const weg   = $('[data-bild-weg]', huelle);
      if (schau) { schau.style.backgroundImage = pfad ? 'url("' + adresse(pfad) + '")' : ''; }
      if (weg) { weg.hidden = !pfad; }
    };

    const setzen = (feld, pfad) => {
      feld.value = pfad;
      vorschau(feld);
    };

    /* Ein Pfad ist gewählt: entweder in ein Feld, oder an den Aufrufer. */
    const uebergeben = (pfad) => {
      if (rueckruf) { const r = rueckruf; rueckruf = null; r(pfad); return true; }
      if (ziel) { setzen(ziel, pfad); return true; }
      return false;
    };

    /* Auch wenn der Wert von woanders kommt – etwa aus der Vorbelegung
       eines Dialogs –, soll die Vorschau stimmen. */
    document.addEventListener('change', (e) => {
      if (e.target.matches && e.target.matches('[data-bildfeld] input')) vorschau(e.target);
    });
    document.addEventListener('input', (e) => {
      if (e.target.matches && e.target.matches('[data-bildfeld] input')) vorschau(e.target);
    });
    $$('[data-bildfeld] input').forEach(vorschau);

    const bauen = () => {
      const d = document.createElement('dialog');
      d.className = 'modal bildwahl';
      d.innerHTML =
        '<div class="modal__kopf"><h2>Bild wählen</h2>'
        + '<button class="btn btn--klein" type="button" data-modal-zu aria-label="Schließen">✕</button></div>'
        + '<div class="modal__koerper">'
        + '<label class="ablage ablage--klein" style="margin-bottom:var(--r3)">'
        + '<span class="halbfett">Neues Bild hochladen</span>'
        + '<span class="klein gedimmt">JPG, PNG, WebP, SVG oder GIF</span>'
        + '<input type="file" accept="image/*" class="bildwahl__datei"'
        + ' style="position:absolute;opacity:0;width:1px;height:1px"></label>'
        + '<p class="klein gedimmt bildwahl__stand" hidden></p>'
        + '<div class="bildwahl__gitter"></div></div>';
      document.body.appendChild(d);

      d.addEventListener('click', (e) => {
        if (e.target.closest('[data-modal-zu]') || e.target === d) d.close();
        const kachel = e.target.closest('[data-pfad]');
        if (kachel && uebergeben(kachel.dataset.pfad)) { d.close(); }
      });
      $('.bildwahl__datei', d).addEventListener('change', function () {
        if (!this.files || !this.files[0]) return;
        hochladen(d, this.files[0]);
        this.value = '';
      });
      return d;
    };

    const stand = (d, text, fehler) => {
      const p = $('.bildwahl__stand', d);
      p.hidden = !text;
      p.textContent = text || '';
      p.style.color = fehler ? 'var(--gefahr)' : '';
    };

    const laden = (d) => {
      const gitter = $('.bildwahl__gitter', d);
      gitter.innerHTML = '<p class="klein gedimmt">Wird geladen …</p>';
      fetch((window.gpBasis || '') + '/app/bilder.php', { headers: { Accept: 'application/json' } })
        .then(r => r.json())
        .then(a => {
          if (!a.bilder || !a.bilder.length) {
            gitter.innerHTML = '<p class="klein gedimmt">Noch keine Bilder. '
              + 'Lad oben eines hoch – es landet auch in der Mediathek.</p>';
            return;
          }
          gitter.innerHTML = a.bilder.map(b =>
            '<button type="button" class="bildwahl__kachel" data-pfad="' + b.pfad + '" '
            + 'title="' + (b.name || '').replace(/"/g, '&quot;') + '">'
            + '<img src="' + b.url + '" alt="" loading="lazy"></button>').join('');
        })
        .catch(() => { gitter.innerHTML = '<p class="klein" style="color:var(--gefahr)">'
          + 'Die Mediathek war nicht erreichbar.</p>'; });
    };

    const hochladen = (d, datei) => {
      stand(d, 'Wird hochgeladen …', false);
      const daten = new FormData();
      daten.append('datei', datei);
      daten.append('_csrf', window.gpCsrf || '');
      fetch((window.gpBasis || '') + '/app/bilder.php', { method: 'POST', body: daten })
        .then(r => r.json().then(a => ({ ok: r.ok, a })))
        .then(({ ok, a }) => {
          if (!ok || !a.pfad) { stand(d, a.fehler || 'Das hat nicht geklappt.', true); return; }
          stand(d, '', false);
          /* Gleich einsetzen: Wer hochlädt, will genau dieses Bild. */
          if (uebergeben(a.pfad)) { d.close(); }
        })
        .catch(() => stand(d, 'Keine Verbindung zum Server.', true));
    };

    /*
     * Zu welchem Feld gehört der Knopf?
     *
     * In einer Liste (Galerie, Karten, Logos) hat das Feld keine eigene
     * Kennung – es gibt beliebig viele Zeilen mit demselben Namen. Deshalb
     * zählt zuerst die Hülle, in der der Knopf steht; die Kennung ist nur
     * der Rückfall für Knöpfe, die außerhalb stehen.
     */
    const feldVon = (knopf, kennung) => {
      const huelle = knopf.closest('[data-bildfeld]');
      const drin = huelle ? $('input', huelle) : null;
      return drin || (kennung ? document.getElementById(kennung) : null);
    };

    document.addEventListener('click', (e) => {
      const auf = e.target.closest('[data-bild-waehlen]');
      if (auf) {
        ziel = feldVon(auf, auf.dataset.bildWaehlen);
        rueckruf = null;
        if (!ziel) return;
        oeffnen();
        return;
      }
      const weg = e.target.closest('[data-bild-weg]');
      if (weg) {
        const feld = feldVon(weg, weg.dataset.bildWeg);
        if (feld) setzen(feld, '');
      }
    });

    const oeffnen = () => {
      if (!fenster) fenster = bauen();
      laden(fenster);
      stand(fenster, '', false);
      fenster.showModal();
    };

    /*
     * Für alles, was kein Formularfeld ist: der Baukasten ruft den Wähler
     * für ein Bild mitten in der Seite und bekommt den Pfad zurück.
     */
    window.gpBildWaehlen = (fertig) => {
      ziel = null;
      rueckruf = fertig;
      oeffnen();
    };
  })();

  /* ----------------------------------------------- Seitenbaum ziehen -- */

  /*
   * Eine Seite an ihren Platz ziehen.
   *
   * Der Unterschied zur Bausteinliste weiter unten: Dort gibt es nur eine
   * Reihenfolge, hier auch eine Ebene. Deshalb entscheidet die Stelle, an
   * der losgelassen wird, was gemeint war:
   *
   *   mitten auf einer Zeile  ->  wird deren Unterseite
   *   am oberen Rand          ->  wird zum Geschwister davor
   *   am unteren Rand         ->  zum Geschwister dahinter
   *
   * Gezogen wird am Griff, nicht an der Zeile: Die ganze Zeile ist ein
   * Link auf die Seite, und ein Browser, der nach dem Ziehen noch einen
   * Klick nachschiebt, öffnete sonst die Seite, die man gerade einsortiert
   * hat.
   *
   * Abgeschickt wird ein gewöhnliches Formular statt fetch(): Der Server
   * ordnet ein, prüft auf Ringe und Tiefe und antwortet mit der neuen
   * Liste. Eine Umsortierung im Browser, die der Server danach ablehnt,
   * wäre eine Lüge auf dem Bildschirm.
   */
  (function () {
    const koerper = $('[data-baum]');
    if (!koerper) return;
    const url = koerper.dataset.baum;
    let zeile = null;

    const zieleWeg = () => $$('tr', koerper).forEach(t =>
      t.classList.remove('ist-ziel-unter', 'ist-ziel-vor', 'ist-ziel-nach'));

    /* Wo genau in der Zielzeile? Die Ränder sind bewusst schmal: Wer
       einordnen will, trifft die Mitte leichter als den Rand. */
    const modusFuer = (ziel, y) => {
      const k = ziel.getBoundingClientRect();
      const anteil = (y - k.top) / k.height;
      if (anteil < 0.25) return 'vor';
      if (anteil > 0.75) return 'nach';
      return 'unter';
    };

    $$('.baum__griff', koerper).forEach(griff => {
      griff.addEventListener('dragstart', (e) => {
        zeile = griff.closest('tr');
        if (!zeile) return;
        zeile.classList.add('wird-gezogen');
        e.dataTransfer.effectAllowed = 'move';
        /* Ohne Nutzlast bricht Firefox das Ziehen sofort ab. */
        e.dataTransfer.setData('text/plain', zeile.dataset.id || '');
        if (e.dataTransfer.setDragImage) {
          e.dataTransfer.setDragImage(zeile, 24, 16);
        }
      });
      griff.addEventListener('dragend', () => {
        if (zeile) zeile.classList.remove('wird-gezogen');
        zeile = null;
        zieleWeg();
      });
    });

    koerper.addEventListener('dragover', (e) => {
      if (!zeile) return;
      const ziel = e.target.closest('tr');
      /* Nicht auf sich selbst, und nicht auf die Startseite: Die ist die
         Marke oben links und hat im Baum nichts zu suchen. */
      if (!ziel || ziel === zeile || ziel.dataset.fest === '1') return;
      e.preventDefault();
      e.dataTransfer.dropEffect = 'move';
      const modus = modusFuer(ziel, e.clientY);
      zieleWeg();
      ziel.classList.add('ist-ziel-' + modus);
    });

    koerper.addEventListener('dragleave', (e) => {
      if (!koerper.contains(e.relatedTarget)) zieleWeg();
    });

    koerper.addEventListener('drop', (e) => {
      if (!zeile) return;
      const ziel = e.target.closest('tr');
      if (!ziel || ziel === zeile || ziel.dataset.fest === '1') return;
      e.preventDefault();
      const modus = modusFuer(ziel, e.clientY);
      zieleWeg();

      /* Nach dem Neuladen soll die Zeile wieder dort stehen, wo sie war. */
      if (window.Blick) window.Blick.merken(ziel);

      const f = document.createElement('form');
      f.method = 'post';
      f.action = url;
      [['aktion', 'baum_ablegen'], ['seite_id', zeile.dataset.id],
       ['ziel_id', ziel.dataset.id], ['modus', modus],
       ['_csrf', window.gpCsrf || '']].forEach(([n, w]) => {
        const i = document.createElement('input');
        i.type = 'hidden'; i.name = n; i.value = w;
        f.appendChild(i);
      });
      document.body.appendChild(f);
      f.submit();
    });
  })();

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

  /*
   * Die Vorgaben jedes Fensters, einmal beim Laden gemerkt.
   *
   * Ein Fenster bedient alle Zeilen einer Seite. Bisher blieb nach einem
   * „Bearbeiten" alles stehen, was der naechste Oeffner nicht ausdruecklich
   * ueberschreibt - beim Anlegen also auch das versteckte Feld `id`. „Neu"
   * hiess dann in Wahrheit „den zuletzt geoeffneten Datensatz
   * ueberschreiben", ohne dass etwas darauf hindeutete.
   *
   * `form.reset()` genuegt dafuer nicht: Bei einem versteckten Feld ist
   * `value` dasselbe wie das Attribut im Markup. Das Vorbelegen aendert
   * also die Vorgabe selbst, und reset() stellt hinterher genau den
   * ueberschriebenen Wert wieder her. Deshalb eine eigene Aufnahme,
   * gemacht bevor irgendein Oeffner etwas hineinschreiben konnte.
   */
  const modalVorgaben = new WeakMap();
  $$('dialog').forEach(d => {
    modalVorgaben.set(d, $$('input, select, textarea', d).map(f => [
      f, (f.type === 'checkbox' || f.type === 'radio') ? f.checked : f.value,
    ]));
  });

  document.addEventListener('click', (e) => {
    const auf = e.target.closest('[data-modal-auf]');
    if (auf) {
      e.preventDefault();
      const d = document.getElementById(auf.dataset.modalAuf);
      if (d && typeof d.showModal === 'function') {
        // Erst auf die Vorgaben zurueck, dann vorbelegen.
        (modalVorgaben.get(d) || []).forEach(([f, wert]) => {
          if (f.type === 'checkbox' || f.type === 'radio') { f.checked = wert; }
          else { f.value = wert; }
        });

        // Werte vorbelegen: data-setz-<feldname>
        Object.keys(auf.dataset).forEach(k => {
          if (k.indexOf('setz') === 0 && k.length > 4) {
            const name = k.slice(4).toLowerCase();
            const feld = d.querySelector('[name="' + name + '"]');
            if (feld) feld.value = auf.dataset[k];
          }
        });
        /* Felder mit eigener Anzeige – etwa die Bildvorschau – erfahren
           sonst nichts davon, dass sich ihr Wert geändert hat. */
        $$('[data-bildfeld] input', d).forEach(
          f => f.dispatchEvent(new Event('change', { bubbles: true })));
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

  /*
   * Diese Felder laden die Seite neu. Vorher merken sie sich, wo sie im
   * Bild standen – sonst schaut man nach dem Filtern wieder auf die
   * Überschrift statt auf die Liste, die man gerade gefiltert hat.
   * Die Arbeit macht blick.js.
   */
  const platzMerken = el => { if (window.Blick) window.Blick.merken(el); };

  $$('[data-auto-absenden]').forEach(el => {
    el.addEventListener('change', () => { platzMerken(el); el.closest('form').submit(); });
  });

  let suchTakt;
  $$('[data-such-absenden]').forEach(el => {
    el.addEventListener('input', () => {
      clearTimeout(suchTakt);
      suchTakt = setTimeout(() => { platzMerken(el); el.closest('form').submit(); }, 450);
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
      /* Im Zug die volle Spaltenbreite: Der Versatz gilt nur fuer
         Parallelen am alten Platz, am neuen weiss man sie noch nicht. */
      zug.el.style.top    = oben + 'px';
      zug.el.style.left   = '3px';
      zug.el.style.right  = '3px';
      zug.el.style.width  = 'auto';
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

    /* ------------------------------------------- Stapel auffaechern -- */

    /*
     * Zeigt man auf parallele Termine, ruecken sie auseinander.
     *
     * In der Woche liegen Parallele versetzt uebereinander: Der vordere
     * verdeckt den hinteren bis auf einen 16 Pixel breiten Streifen. Man
     * sieht, DASS da noch etwas ist, aber nicht WAS. Den angefassten nach
     * vorn zu holen half nur ihm selbst - bei dreien blieben zwei genauso
     * verdeckt wie vorher.
     *
     * Also faechert der ganze Stapel auf: Alle Termine derselben
     * Ueberschneidungsgruppe teilen sich fuer die Dauer des Hinsehens die
     * Spalte. Welche zusammengehoeren, hat der Server schon ausgerechnet
     * und als data-stapel an jeden Termin geschrieben - im Skript
     * Ueberschneidungen zu suchen hiesse, dieselbe Rechnung ein zweites
     * Mal zu pflegen.
     *
     * Die Gruppennummer gilt je Spalte: In jedem Tag faengt sie wieder
     * bei 0 an, also wird immer innerhalb der Spalte gesucht.
     */
    const STUNDENLEISTE = 58;   // Breite der Uhrzeitenspalte, siehe app.css
    let stapel = null;          // { spalte, gruppe, teile[] }
    let faecherBis = 0;         // bis dahin sind Zeigerwechsel Nachbeben

    /* In der Tagesansicht stehen Parallele ohnehin nebeneinander - da gaebe
       es nichts aufzufaechern, und die Karte soll am Termin haengen
       bleiben statt an einer tausend Pixel breiten Spalte. */
    const geteilt = !!gitter.closest('.kalender--tag');

    /*
     * Wie weit muss der Faecher nach links, damit er im Kalender bleibt?
     *
     * Gerechnet, nicht gemessen: Wer mitten in der Bewegung misst, bekommt
     * den halben Weg. Die Spaltenkante steht dagegen still, und die
     * Kastenbreite ist dieselbe Formel wie im Stilblatt.
     */
    function schub(spalte, spuren) {
      const breite = Math.max(140, (spalte.clientWidth - 6) / spuren - 4);
      const sp = spalte.getBoundingClientRect(), g = gitter.getBoundingClientRect();
      const rechts = sp.left + 3 + (spuren - 1) * (breite + 4) + breite;
      /* Hoechstens so weit nach links, wie noch Kalender da ist: Hinter der
         Stundenleiste faengt der Rollbereich an, und dahinter ist der
         Faecher abgeschnitten - dann saehe man wieder nichts. */
      const platz = Math.max(0, sp.left + 3 - (g.left + STUNDENLEISTE));
      return Math.round(Math.min(platz, Math.max(0, rechts - (g.right - 8))));
    }

    function faecherWeg() {
      if (!stapel) return;
      const teile = stapel.teile, spalte = stapel.spalte;
      stapel = null;
      teile.forEach((el) => el.classList.remove('ist-gefaechert'));
      if (spalte) spalte.style.removeProperty('--schub');
      /* Die Karte gehoerte zu einem der Weggeraeumten: Sie haette sonst
         neben einem Termin stehen bleiben koennen, der nicht mehr dort
         liegt. Eine geplante Karte ueberlebt - die gilt schon dem naechsten. */
      if (karteFuer && teile.indexOf(karteFuer) !== -1) karteFort();
    }

    function faechern(el) {
      if (geteilt) return;
      const gruppe = el.dataset.stapel;
      if (!/^\d+$/.test(gruppe || '')) { faecherWeg(); return; }
      const spalte = el.closest('.kalender__spalte');
      if (!spalte) return;
      if (stapel && stapel.spalte === spalte && stapel.gruppe === gruppe) return;

      faecherWeg();
      const teile = [].slice.call(
        spalte.querySelectorAll('.termin[data-stapel="' + gruppe + '"]'));
      if (teile.length < 2) return;

      const spuren = Math.max(1, parseInt(getComputedStyle(teile[0]).getPropertyValue('--spuren'), 10) || 1);
      /* Der Schub steht an der Spalte, nicht an den Terminen: Er gilt fuer
         alle gleich, und das style-Attribut der Termine bleibt sauber -
         das Verschieben legt es beim Anfassen beiseite und spielt es
         hinterher zurueck. */
      const weg = schub(spalte, spuren);
      if (weg > 0) spalte.style.setProperty('--schub', weg + 'px');
      teile.forEach((t) => t.classList.add('ist-gefaechert'));
      stapel = { spalte: spalte, gruppe: gruppe, teile: teile };
      faecherBis = Date.now() + 260;
    }

    /* Liegt der Zeiger noch am Faecher? Grosszuegig gemessen: Zwischen den
       aufgefaecherten Kaesten sind ein paar Pixel Luft, und dort hindurch
       zu fahren darf ihn nicht zuklappen. */
    function amFaecher(x, y) {
      if (!stapel) return false;
      const rand = 10;
      return stapel.teile.some((t) => {
        const r = t.getBoundingClientRect();
        return x >= r.left - rand && x <= r.right + rand
            && y >= r.top - rand  && y <= r.bottom + rand;
      });
    }

    /*
     * Warum mousemove und nicht mouseout?
     *
     * Beim Auffaechern rutschen die Kaesten unter dem stillstehenden
     * Zeiger weg. mouseout wuerde sofort zuklappen, der Kasten kaeme
     * zurueck unter den Zeiger, mouseover faecherte wieder auf - ein
     * Flackern, das nie zur Ruhe kommt. Die Zeigerposition dagegen ist
     * unabhaengig davon, was gerade unter ihr liegt.
     */
    gitter.addEventListener('mousemove', (e) => {
      if (zug) { faecherWeg(); return; }
      const el = e.target.closest('.termin[data-stapel]');
      if (el) { faechern(el); return; }
      if (stapel && !amFaecher(e.clientX, e.clientY)) faecherWeg();
    });
    gitter.addEventListener('mouseleave', faecherWeg);
    gitter.addEventListener('scroll', faecherWeg, { passive: true });
    /* Wer daneben drueckt, zieht eine Zeit auf. Der Faecher laege ueber der
       aufgezogenen Flaeche - also weg damit. Auf einem Termin bleibt er:
       Dort faengt entweder ein Klick oder ein Verschieben an, und beides
       soll den Kasten nicht unter dem Zeiger wegziehen. */
    gitter.addEventListener('mousedown', (e) => {
      if (!e.target.closest('.termin')) faecherWeg();
    });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') faecherWeg(); });

    /* ------------------------------------------- Vorschaukarte ------- */

    /*
     * Beim Daraufzeigen die ganze Auskunft.
     *
     * Auf der Flaeche steht nur, was hineinpasst – bei einem halbstuendigen
     * Termin in einer Wochenspalte sind das Uhrzeit und Name. Trainer, Ort,
     * Preis, Zahlungsstand und die interne Notiz haetten dort nie Platz,
     * sind aber genau das, was man wissen will, bevor man klickt.
     *
     * Die Karte schwebt frei am Fenster, nicht im Kalender: Sonst schnitte
     * der Rollbereich sie an seiner Kante ab – und angeschnitten ist eine
     * Vorschau wertlos.
     */
    const VERZOEGERUNG = 180;
    let karte = null, karteFuer = null, warten = null;

    /* Nur die Karte, die gerade dasteht. Eine schon eingeplante bleibt
       eingeplant - beim Wechsel von einem Termin zum naechsten ist sie
       naemlich bereits die des neuen. */
    function karteFort() {
      if (karte) { karte.remove(); karte = null; }
      karteFuer = null;
    }

    function karteWeg() {
      clearTimeout(warten);
      karteFort();
    }

    /*
     * Woran weicht die Karte aus?
     *
     * Normal am Termin selbst. Ist der Stapel aber aufgefaechert, dann am
     * ganzen Faecher - sonst stellt sich die Karte genau auf die Nachbarn,
     * die das Auffaechern eben erst sichtbar gemacht hat. Das waere die
     * Sache ad absurdum gefuehrt: aufraeumen und sofort wieder zudecken.
     *
     * Oben bleibt die Kante des angefassten Termins, damit die Karte auf
     * seiner Hoehe steht und nicht irgendwo am Stapel.
     */
    function ankerkasten(el) {
      if (!stapel || stapel.teile.indexOf(el) === -1) return el.getBoundingClientRect();
      const eigen = el.getBoundingClientRect();
      let links = Infinity, rechts = -Infinity, unten = -Infinity;
      stapel.teile.forEach((t) => {
        const k = t.getBoundingClientRect();
        links  = Math.min(links, k.left);
        rechts = Math.max(rechts, k.right);
        unten  = Math.max(unten, k.bottom);
      });
      return { left: links, right: rechts, top: eigen.top, bottom: unten };
    }

    function karteZeigen(el) {
      const quelle = el.querySelector('.termin__mehr');
      if (!quelle) return;

      karteWeg();
      karte = document.createElement('div');
      karte.className = 'vorschau';
      karte.setAttribute('role', 'tooltip');
      karte.innerHTML = quelle.innerHTML;
      document.body.appendChild(karte);
      karteFuer = el;

      /* Erst messen, dann setzen: Die Hoehe steht erst fest, wenn der
         Inhalt im Dokument haengt. */
      const k = ankerkasten(el);
      const v = karte.getBoundingClientRect();
      const luft = 10;

      let links = k.right + luft;
      if (links + v.width > window.innerWidth - luft) {
        links = k.left - v.width - luft;               // dann nach links
      }
      if (links < luft) {
        links = Math.max(luft, Math.min(k.left, window.innerWidth - v.width - luft));
      }

      let oben = k.top - 4;
      if (oben + v.height > window.innerHeight - luft) {
        oben = window.innerHeight - v.height - luft;   // am unteren Rand anheben
      }
      if (oben < luft) { oben = luft; }

      karte.style.left = Math.round(links) + 'px';
      karte.style.top  = Math.round(oben) + 'px';
      requestAnimationFrame(() => karte && karte.classList.add('ist-da'));
    }

    /* Waehrend der Faecher aufgeht, wandern die Kaesten unter dem ruhenden
       Zeiger hindurch und loesen mouseover/mouseout aus, ohne dass sich
       jemand bewegt haette. Das ist kein Zeigerwechsel: Wer auf den
       zweiten Termin gezeigt hat, will dessen Karte sehen und nicht die
       des ersten, nur weil der gerade unter den Zeiger gerutscht ist. */
    const nachbeben = (el) =>
      Date.now() < faecherBis && stapel && stapel.teile.indexOf(el) !== -1;

    gitter.addEventListener('mouseover', (e) => {
      const el = e.target.closest('.termin');
      if (!el || el === karteFuer || zug) return;
      if (nachbeben(el)) return;
      clearTimeout(warten);
      warten = setTimeout(() => karteZeigen(el), VERZOEGERUNG);
    });

    gitter.addEventListener('mouseout', (e) => {
      const el = e.target.closest('.termin');
      if (!el) return;
      // Innerhalb desselben Termins von Kind zu Kind: nichts tun.
      if (e.relatedTarget && el.contains(e.relatedTarget)) return;
      if (nachbeben(el)) return;
      karteWeg();
    });

    /* Tastatur: Wer sich durchtabbt, bekommt dieselbe Auskunft. */
    gitter.addEventListener('focusin', (e) => {
      const el = e.target.closest('.termin');
      if (el) karteZeigen(el);
    });
    gitter.addEventListener('focusout', karteWeg);

    /* Sobald etwas anderes passiert, ist die Karte im Weg. */
    gitter.addEventListener('mousedown', karteWeg);
    gitter.addEventListener('scroll', karteWeg, { passive: true });
    window.addEventListener('scroll', karteWeg, { passive: true });
    window.addEventListener('resize', karteWeg);
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') karteWeg(); });

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
