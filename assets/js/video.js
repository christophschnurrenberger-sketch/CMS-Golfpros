/* ==========================================================================
   Videoanalyse – Zeichnen auf dem Video
   --------------------------------------------------------------------------
   Zeichnungen liegen als relative Koordinaten (0…1) im JSON, nicht als
   Bild. Damit sitzen sie auf jedem Bildschirm richtig, bleiben später
   veränderbar und kosten fast nichts an Speicher.
   ========================================================================== */

(function () {
  'use strict';

  const leinwand = document.getElementById('zeichenflaeche');
  if (!leinwand) return;

  const video   = document.getElementById('analyse-video');
  const ctx     = leinwand.getContext('2d');
  const feld    = document.getElementById('zeichnungen-feld');

  let formen    = [];
  let werkzeug  = 'linie';
  let farbe     = '#e0663c';
  let zeichnet  = false;
  let start     = null;
  let vorschau  = null;

  try { formen = JSON.parse(feld.value || '[]'); } catch (e) { formen = []; }

  /* ------------------------------------------------------------ Größe - */

  function anpassen() {
    const kasten = leinwand.parentElement.getBoundingClientRect();
    leinwand.width  = kasten.width;
    leinwand.height = kasten.height;
    zeichnen();
  }
  window.addEventListener('resize', anpassen);
  if (video) {
    video.addEventListener('loadedmetadata', anpassen);
  }
  setTimeout(anpassen, 60);

  /* ---------------------------------------------------------- Zeichnen */

  function punkt(e) {
    const k = leinwand.getBoundingClientRect();
    const x = ((e.touches ? e.touches[0].clientX : e.clientX) - k.left) / k.width;
    const y = ((e.touches ? e.touches[0].clientY : e.clientY) - k.top) / k.height;
    return [Math.min(1, Math.max(0, x)), Math.min(1, Math.max(0, y))];
  }

  function malen(f, hervor) {
    const b = leinwand.width, h = leinwand.height;
    ctx.strokeStyle = f.farbe || farbe;
    ctx.fillStyle   = f.farbe || farbe;
    ctx.lineWidth   = hervor ? 3 : 2;
    ctx.lineCap     = 'round';
    ctx.setLineDash([]);

    switch (f.werkzeug) {
      case 'linie':
        ctx.beginPath();
        ctx.moveTo(f.von[0] * b, f.von[1] * h);
        ctx.lineTo(f.bis[0] * b, f.bis[1] * h);
        ctx.stroke();
        break;

      case 'kreis': {
        const r = Math.hypot((f.bis[0] - f.von[0]) * b, (f.bis[1] - f.von[1]) * h);
        ctx.beginPath();
        ctx.arc(f.von[0] * b, f.von[1] * h, r, 0, Math.PI * 2);
        ctx.stroke();
        break;
      }

      case 'rechteck':
        ctx.strokeRect(f.von[0] * b, f.von[1] * h,
          (f.bis[0] - f.von[0]) * b, (f.bis[1] - f.von[1]) * h);
        break;

      case 'winkel': {
        // Zwei Schenkel plus die gemessene Gradzahl – das ist der Sinn
        // des Werkzeugs; eine Linie ohne Zahl sagt nichts.
        ctx.beginPath();
        ctx.moveTo(f.von[0] * b, f.von[1] * h);
        ctx.lineTo(f.bis[0] * b, f.bis[1] * h);
        ctx.stroke();
        ctx.setLineDash([5, 4]);
        ctx.beginPath();
        ctx.moveTo(f.von[0] * b, f.von[1] * h);
        ctx.lineTo(f.bis[0] * b, f.von[1] * h);
        ctx.stroke();
        ctx.setLineDash([]);
        const grad = Math.abs(Math.atan2((f.bis[1] - f.von[1]) * h, (f.bis[0] - f.von[0]) * b) * 180 / Math.PI);
        ctx.font = '600 14px Inter, sans-serif';
        ctx.fillText(Math.round(grad) + '°', f.von[0] * b + 10, f.von[1] * h - 8);
        break;
      }

      case 'frei':
        if (!f.punkte || f.punkte.length < 2) break;
        ctx.beginPath();
        ctx.moveTo(f.punkte[0][0] * b, f.punkte[0][1] * h);
        f.punkte.forEach(p => ctx.lineTo(p[0] * b, p[1] * h));
        ctx.stroke();
        break;

      case 'text':
        ctx.font = '600 15px Inter, sans-serif';
        ctx.fillText(f.text || '', f.von[0] * b, f.von[1] * h);
        break;
    }
  }

  function zeichnen() {
    ctx.clearRect(0, 0, leinwand.width, leinwand.height);
    formen.forEach(f => malen(f, false));
    if (vorschau) malen(vorschau, true);
  }

  /* ------------------------------------------------------------ Maus - */

  function anfang(e) {
    if (werkzeug === 'text') {
      const t = window.prompt('Text eingeben');
      if (t) {
        formen.push({ werkzeug: 'text', von: punkt(e), text: t, farbe: farbe });
        sichern();
      }
      return;
    }
    zeichnet = true;
    start = punkt(e);
    vorschau = werkzeug === 'frei'
      ? { werkzeug: 'frei', punkte: [start], farbe: farbe }
      : { werkzeug: werkzeug, von: start, bis: start, farbe: farbe };
    e.preventDefault();
  }

  function bewegen(e) {
    if (!zeichnet) return;
    const p = punkt(e);
    if (werkzeug === 'frei') vorschau.punkte.push(p);
    else vorschau.bis = p;
    zeichnen();
    e.preventDefault();
  }

  function ende() {
    if (!zeichnet) return;
    zeichnet = false;
    if (vorschau) {
      const lang = vorschau.werkzeug === 'frei'
        ? vorschau.punkte.length > 3
        : Math.hypot(vorschau.bis[0] - vorschau.von[0], vorschau.bis[1] - vorschau.von[1]) > 0.015;
      if (lang) formen.push(vorschau);
    }
    vorschau = null;
    sichern();
  }

  leinwand.addEventListener('mousedown', anfang);
  leinwand.addEventListener('mousemove', bewegen);
  window.addEventListener('mouseup', ende);
  leinwand.addEventListener('touchstart', anfang, { passive: false });
  leinwand.addEventListener('touchmove', bewegen, { passive: false });
  window.addEventListener('touchend', ende);

  function sichern() {
    feld.value = JSON.stringify(formen);
    zeichnen();
    const zaehler = document.getElementById('formen-zahl');
    if (zaehler) zaehler.textContent = formen.length;
  }

  /* --------------------------------------------------------- Werkzeuge */

  document.querySelectorAll('[data-werkzeug]').forEach(el => {
    el.addEventListener('click', () => {
      werkzeug = el.dataset.werkzeug;
      document.querySelectorAll('[data-werkzeug]').forEach(w => w.classList.remove('ist-aktiv'));
      el.classList.add('ist-aktiv');
    });
  });

  document.querySelectorAll('[data-farbe]').forEach(el => {
    el.addEventListener('click', () => {
      farbe = el.dataset.farbe;
      document.querySelectorAll('[data-farbe]').forEach(w => w.style.outline = '');
      el.style.outline = '2px solid var(--text)';
      el.style.outlineOffset = '2px';
    });
  });

  const zurueck = document.getElementById('knopf-zurueck');
  if (zurueck) zurueck.addEventListener('click', () => { formen.pop(); sichern(); });

  const leeren = document.getElementById('knopf-leeren');
  if (leeren) leeren.addEventListener('click', () => {
    if (formen.length && window.confirm('Alle Markierungen entfernen?')) { formen = []; sichern(); }
  });

  /* ------------------------------------------------------ Videosteuerung */

  if (!video) return;

  const spur       = document.getElementById('video-spur');
  const fortschritt = document.getElementById('video-fortschritt');
  const zeitAnzeige = document.getElementById('video-zeit');

  function zeitText(s) {
    if (!isFinite(s)) return '0:00.0';
    return Math.floor(s / 60) + ':' + String(Math.floor(s % 60)).padStart(2, '0')
         + '.' + Math.floor((s % 1) * 10);
  }

  video.addEventListener('timeupdate', () => {
    const anteil = video.duration ? video.currentTime / video.duration * 100 : 0;
    if (fortschritt) fortschritt.style.width = anteil + '%';
    if (zeitAnzeige) zeitAnzeige.textContent = zeitText(video.currentTime) + ' / ' + zeitText(video.duration);
  });

  if (spur) {
    spur.addEventListener('click', (e) => {
      const k = spur.getBoundingClientRect();
      video.currentTime = ((e.clientX - k.left) / k.width) * (video.duration || 0);
    });
  }

  document.querySelectorAll('[data-video]').forEach(el => {
    el.addEventListener('click', () => {
      const befehl = el.dataset.video;
      if (befehl === 'abspielen') {
        video.paused ? video.play() : video.pause();
      } else if (befehl === 'zurueck') {
        video.pause(); video.currentTime = Math.max(0, video.currentTime - 1 / 25);
      } else if (befehl === 'vor') {
        video.pause(); video.currentTime = Math.min(video.duration || 0, video.currentTime + 1 / 25);
      } else if (befehl === 'anfang') {
        video.currentTime = 0;
      }
    });
  });

  document.querySelectorAll('[data-tempo]').forEach(el => {
    el.addEventListener('click', () => {
      video.playbackRate = parseFloat(el.dataset.tempo);
      document.querySelectorAll('[data-tempo]').forEach(t => t.classList.remove('ist-aktiv'));
      el.classList.add('ist-aktiv');
    });
  });

  // Pfeiltasten schrittweise, Leertaste abspielen – wie in jedem Schnittprogramm
  document.addEventListener('keydown', (e) => {
    if (/^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement.tagName)) return;
    if (e.key === ' ') { e.preventDefault(); video.paused ? video.play() : video.pause(); }
    if (e.key === 'ArrowLeft')  { e.preventDefault(); video.pause(); video.currentTime -= 1 / 25; }
    if (e.key === 'ArrowRight') { e.preventDefault(); video.pause(); video.currentTime += 1 / 25; }
  });

})();
