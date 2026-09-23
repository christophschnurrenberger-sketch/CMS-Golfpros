/*
 * Betreiberzentrale – der Assistent „Neue Instanz" und die Rückfrage mit
 * Namenseingabe beim Löschen.
 *
 * Alles hier ist Bequemlichkeit, keine Sicherheit: Ohne dieses Skript
 * stehen alle Schritte untereinander, und der Server prüft ohnehin jede
 * Angabe noch einmal vollständig.
 */
(function () {
  'use strict';

  const $ = (s, w) => (w || document).querySelector(s);
  const $$ = (s, w) => Array.from((w || document).querySelectorAll(s));

  /* ------------------------------------------------------- Assistent --- */

  const form = $('[data-assistent]');
  if (form) {
    const schritte = $$('[data-schritt]', form);
    const marken = $$('[data-schritt-marke]', form);
    const zurueck = $('[data-zurueck]', form);
    const weiter = $('[data-weiter]', form);
    const anlegen = $('[data-anlegen]', form);
    let jetzt = parseInt(form.dataset.startSchritt || '1', 10);
    form.classList.add('ist-js');

    const geld = (cent) => (cent / 100).toLocaleString('de-DE', { style: 'currency', currency: 'EUR' });

    function zusammenfassen() {
      const wert = (name) => { const f = form.elements[name]; return f ? (f.value || '').trim() : ''; };
      const paket = $('input[name="paket"]:checked', form);
      const laufzeit = $('input[name="laufzeit"]:checked', form);
      const setze = (key, text) => { const el = $('[data-zeige="' + key + '"]', form); if (el) el.textContent = text || '—'; };
      setze('name', wert('name'));
      setze('inhaber', wert('inhaber'));
      setze('email', wert('email'));
      setze('slug', wert('slug'));
      setze('paket', paket ? paket.dataset.name : '');
      let lz = laufzeit ? $('.wahl-karte__titel', laufzeit.closest('label')).textContent : '';
      if (laufzeit && paket) {
        if (laufzeit.value === 'test') lz += ' bis ' + (wert('test_bis') ? new Date(wert('test_bis')).toLocaleDateString('de-DE') : '—');
        if (laufzeit.value === 'monat') lz += ' · ' + (parseInt(paket.dataset.monat, 10) > 0 ? geld(parseInt(paket.dataset.monat, 10)) + ' / Monat' : 'ohne Listenpreis');
        if (laufzeit.value === 'jahr') lz += ' · ' + (parseInt(paket.dataset.jahr, 10) > 0 ? geld(parseInt(paket.dataset.jahr, 10)) + ' / Jahr' : 'kein Jahrespreis festgelegt');
        if (laufzeit.value === 'individuell') lz += ' · ' + (wert('preis') || '0,00') + ' € / Monat';
      }
      setze('laufzeit', lz);
      setze('einladen', form.elements.einladen && form.elements.einladen.checked
        ? 'wird sofort an ' + (wert('email') || '—') + ' geschickt' : 'noch nicht – später unter „Benutzer"');
    }

    function laufzeitFelder() {
      const gewaehlt = ($('input[name="laufzeit"]:checked', form) || {}).value;
      $$('[data-nur-laufzeit]', form).forEach(el => { el.hidden = el.dataset.nurLaufzeit !== gewaehlt; });
    }

    function zeigen(n) {
      jetzt = Math.max(1, Math.min(schritte.length, n));
      schritte.forEach(s => s.classList.toggle('ist-aktiv', parseInt(s.dataset.schritt, 10) === jetzt));
      marken.forEach(m => {
        const k = parseInt(m.dataset.schrittMarke, 10);
        m.classList.toggle('ist-aktiv', k === jetzt);
        m.classList.toggle('ist-fertig', k < jetzt);
        if (k === jetzt) m.setAttribute('aria-current', 'step'); else m.removeAttribute('aria-current');
      });
      zurueck.hidden = jetzt === 1;
      weiter.hidden = jetzt === schritte.length;
      anlegen.hidden = jetzt !== schritte.length;
      if (jetzt === schritte.length) zusammenfassen();
      const erstes = $('[data-schritt="' + jetzt + '"] input:not([type=hidden]), [data-schritt="' + jetzt + '"] select', form);
      if (erstes && window.innerWidth > 700) setTimeout(() => erstes.focus(), 30);
    }

    /* Nur die Felder des aktuellen Schritts prüfen – der Browser zeigt
       seine eigene Meldung am Feld. */
    function schrittGueltig() {
      const felder = $$('[data-schritt="' + jetzt + '"] input, [data-schritt="' + jetzt + '"] select', form);
      for (const f of felder) {
        if (!f.checkValidity()) { f.reportValidity(); return false; }
      }
      return true;
    }

    weiter.addEventListener('click', () => { if (schrittGueltig()) zeigen(jetzt + 1); });
    zurueck.addEventListener('click', () => zeigen(jetzt - 1));
    form.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' && e.target.tagName === 'INPUT' && jetzt < schritte.length) {
        e.preventDefault();
        if (schrittGueltig()) zeigen(jetzt + 1);
      }
    });
    marken.forEach(m => m.addEventListener('click', () => {
      const k = parseInt(m.dataset.schrittMarke, 10);
      if (k < jetzt) zeigen(k);
    }));

    /* Kurzadresse aus dem Namen, solange niemand sie selbst angefasst hat. */
    const slug = $('#n-slug', form);
    const name = $('#n-name', form);
    const vorschau = $('[data-slug-vorschau]', form);
    let slugVonHand = slug.value !== '';
    const umwandeln = (t) => t.toLowerCase()
      .replace(/ä/g, 'ae').replace(/ö/g, 'oe').replace(/ü/g, 'ue').replace(/ß/g, 'ss')
      .normalize('NFD').replace(/[̀-ͯ]/g, '')
      .replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 60);
    const vorschauSetzen = () => { if (vorschau) vorschau.textContent = form.dataset.basis + '?w=' + (slug.value || '…'); };
    slug.addEventListener('input', () => { slugVonHand = slug.value !== ''; slug.value = slug.value.toLowerCase(); vorschauSetzen(); });
    name.addEventListener('input', () => { if (!slugVonHand) { slug.value = umwandeln(name.value); vorschauSetzen(); } });
    vorschauSetzen();

    $$('input[name="laufzeit"]', form).forEach(r => r.addEventListener('change', laufzeitFelder));
    laufzeitFelder();
    form.addEventListener('submit', () => { anlegen.disabled = true; anlegen.textContent = 'Wird angelegt …'; });
    zeigen(jetzt);
  }

  /* -------------------------------- Löschen: Namen genau abtippen --- */

  $$('[data-name-bestaetigen]').forEach(feld => {
    const knopf = $(feld.dataset.nameBestaetigen);
    const soll = feld.dataset.soll || '';
    const pruefen = () => { if (knopf) knopf.disabled = feld.value.trim() !== soll; };
    feld.addEventListener('input', pruefen);
    pruefen();
  });

  /* -------------------------------- Zwischenablage für die Instanz-ID --- */
  /* app.js kann das schon über [data-kopieren]; hier nichts zu tun. */
})();
