/**
 * Nimmt die Screens für das Werbevideo aus der laufenden App auf.
 *
 *   TEEPILOT_URL=http://127.0.0.1:8090 node aufnahme/aufnehmen.js
 *
 * Erwartet eine Anlage mit dem Demo-Workspace (Golf Academy Bergmann),
 * siehe README.md in diesem Ordner. Nichts hier ist nachgebaut: Jedes Bild
 * ist ein Screenshot der echten Seite, jeder Tipp im Video ein Klick, den
 * dieses Skript wirklich ausgeführt hat.
 *
 * Damit das Scrollen im Video so aussieht wie im Browser, werden feste
 * Leisten (Kopfzeile, Buchungsleiste, Navigation im Portal) einzeln
 * aufgenommen und in der ganzseitigen Aufnahme ausgeblendet. Das Video legt
 * sie wieder darüber – sie bleiben stehen, die Seite läuft darunter durch.
 *
 * Ergebnis: public/screens/*.png und src/screens.json (Maße, Leisten und
 * die Stellen, an denen getippt wird – in CSS-Pixeln der Seite).
 */
const fs = require('fs');
const path = require('path');

const playwrightPfad = process.env.PLAYWRIGHT_PFAD || '/opt/node22/lib/node_modules/playwright';
const { chromium } = require(playwrightPfad);

const BASIS = (process.env.TEEPILOT_URL || 'http://127.0.0.1:8090').replace(/\/$/, '');
const EMAIL = process.env.TEEPILOT_EMAIL || 'demo@teepilot.golf';
const PASSWORT = process.env.TEEPILOT_PASSWORT || 'demo1234!TeePilot';
const CHROMIUM = process.env.CHROMIUM_PFAD || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';

const WURZEL = path.join(__dirname, '..');
const BILDER = path.join(WURZEL, 'public', 'screens');
const MANIFEST = path.join(WURZEL, 'src', 'screens.json');

const TELEFON = { width: 390, height: 844 };
const DESKTOP = { width: 1440, height: 900 };

const manifest = {};

const warten = (p) => p.waitForLoadState('networkidle').then(() => p.waitForTimeout(350));

async function anmelden(p) {
  await p.goto(BASIS + '/login.php');
  await p.fill('#email', EMAIL);
  await p.fill('#passwort', PASSWORT);
  await Promise.all([p.waitForNavigation(), p.click('form button[type=submit]')]);
  if (!p.url().includes('/app/')) {
    throw new Error('Anmeldung fehlgeschlagen – stimmen TEEPILOT_EMAIL und TEEPILOT_PASSWORT?');
  }
}

/** Rechteck eines Elements in Seitenkoordinaten (CSS-Pixel). */
async function rechteck(p, selektor) {
  const r = await p.locator(selektor).first().evaluate((e) => {
    const b = e.getBoundingClientRect();
    return { x: b.x + scrollX, y: b.y + scrollY, breite: b.width, hoehe: b.height };
  });
  return Object.fromEntries(Object.entries(r).map(([k, v]) => [k, Math.round(v)]));
}

/**
 * Ganze Seite aufnehmen, feste Leisten getrennt.
 * leisten: Selektoren für Kopf- und Fußleisten, die stehen bleiben sollen.
 */
async function ganzeSeite(p, name, { leisten = [], hoechstens = 3200, marken = {} } = {}) {
  await p.evaluate(() => window.scrollTo(0, 0));
  await p.waitForTimeout(150);
  const statusfarbe = await obenFarbe(p);
  const overlays = [];
  for (const sel of leisten) {
    const loc = p.locator(sel).first();
    if (!(await loc.count())) continue;
    const r = await loc.evaluate((e) => {
      const b = e.getBoundingClientRect();
      return { x: Math.round(b.x), y: Math.round(b.y), breite: Math.round(b.width), hoehe: Math.round(b.height) };
    });
    const datei = `${name}--${overlays.length}.png`;
    await loc.screenshot({ path: path.join(BILDER, datei) });
    overlays.push({ bild: 'screens/' + datei, ...r, unten: r.y > TELEFON.height / 2 });
  }
  const gefundeneMarken = {};
  for (const [k, sel] of Object.entries(marken)) gefundeneMarken[k] = await rechteck(p, sel);
  const stil = await p.addStyleTag({ content: leisten.map((s) => `${s}{visibility:hidden!important}`).join('\n') });
  const hoehe = Math.min(hoechstens, await p.evaluate(() => document.documentElement.scrollHeight));
  await p.screenshot({ path: path.join(BILDER, name + '.png'), fullPage: true, clip: { x: 0, y: 0, width: TELEFON.width, height: hoehe } });
  // Wieder einblenden: Was danach auf dieser Seite aufgenommen wird, soll aussehen wie im Browser.
  await stil.evaluate((e) => e.remove());
  manifest[name] = { bild: `screens/${name}.png`, breite: TELEFON.width, hoehe, statusfarbe, overlays, marken: gefundeneMarken };
  console.log(`  ${name}: ${hoehe}px, ${overlays.length} Leisten, Marken: ${Object.keys(gefundeneMarken).join(', ') || '–'}`);
}

/** Nur der sichtbare Ausschnitt – für Dialoge und Zustände nach einem Tipp. */
async function ausschnitt(p, name, viewport, marken = {}) {
  const gefundeneMarken = {};
  for (const [k, sel] of Object.entries(marken)) {
    const r = await p.locator(sel).first().evaluate((e) => {
      const b = e.getBoundingClientRect();
      return { x: Math.round(b.x), y: Math.round(b.y), breite: Math.round(b.width), hoehe: Math.round(b.height) };
    });
    gefundeneMarken[k] = r;
  }
  const statusfarbe = viewport === TELEFON ? await obenFarbe(p) : null;
  await p.screenshot({ path: path.join(BILDER, name + '.png') });
  manifest[name] = { bild: `screens/${name}.png`, breite: viewport.width, hoehe: viewport.height, statusfarbe, overlays: [], marken: gefundeneMarken };
  console.log(`  ${name}: Ausschnitt ${viewport.width}×${viewport.height}`);
}

/** Hintergrundfarbe ganz oben – das Video setzt die Statusleiste des Telefons darin. */
function obenFarbe(p) {
  return p.evaluate(() => {
    let e = document.elementFromPoint(195, 4);
    while (e) {
      const c = getComputedStyle(e).backgroundColor;
      if (c && c !== 'transparent' && !/rgba\(.*,\s*0\)$/.test(c)) return c;
      e = e.parentElement;
    }
    return getComputedStyle(document.body).backgroundColor || '#ffffff';
  });
}

function isoTag(d) {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

(async () => {
  fs.mkdirSync(BILDER, { recursive: true });
  for (const f of fs.readdirSync(BILDER)) if (f.endsWith('.png')) fs.unlinkSync(path.join(BILDER, f));

  const browser = await chromium.launch({ executablePath: CHROMIUM });

  /* ---------------------------------------------------- Vorbereiten --- */
  /*
   * Der Demo-Workspace hat für das Bild der Startseite nur den gestreiften
   * Platzhalter. Hier wird – über denselben Endpunkt, den der Bildwähler im
   * Baukasten benutzt – eine der mitgelieferten Demo-Illustrationen gesetzt,
   * so wie es ein Golfpro mit seinem eigenen Foto tut.
   */
  const vor = await browser.newContext({ viewport: DESKTOP, locale: 'de-DE' });
  const v = await vor.newPage();
  await anmelden(v);
  await v.goto(BASIS + '/app/seite.php?id=1');
  await warten(v);
  const gesetzt = await v.evaluate(async (bild) => {
    const block = document.querySelector('[data-block-id][data-typ="hero"]');
    if (!block) return 'kein Titelbereich';
    const f = new FormData();
    f.append('_csrf', window.gpCsrf);
    f.append('seite', '1');
    f.append('block', block.dataset.blockId);
    f.append('feld', 'bild');
    f.append('aktion', 'feld');
    f.append('wert', bild);
    const r = await fetch('baustein.php', { method: 'POST', body: f, credentials: 'same-origin' });
    return r.ok ? 'ok' : 'Fehler ' + r.status + ' ' + (await r.text());
  }, 'assets/demo/reisen/gruen.svg');
  console.log('Bild der Startseite:', gesetzt);
  await vor.close();

  /* ------------------------------------------------------ Telefon --- */
  const tel = await browser.newContext({ viewport: TELEFON, deviceScaleFactor: 3, isMobile: true, hasTouch: true, locale: 'de-DE', colorScheme: 'light' });
  const p = await tel.newPage();
  await anmelden(p);
  console.log('Telefon');

  // Online-Buchung: Kalender → Tag antippen → Uhrzeit antippen → Angaben
  await p.goto(BASIS + '/buchen.php');
  await warten(p);
  const tag = await p.evaluate(() => {
    const ab = Date.now() + 2 * 86400000;
    const frei = [...document.querySelectorAll('button.buchkal__tag.ist-frei[data-tag]')]
      .filter((b) => new Date(b.dataset.tag).getTime() >= ab && b.querySelector('.buchkal__anzahl') && parseInt(b.querySelector('.buchkal__anzahl').textContent, 10) >= 3);
    return frei.length ? frei[0].dataset.tag : null;
  });
  if (!tag) throw new Error('Kein freier Tag mit mindestens drei Zeiten im Buchungskalender.');
  const tagSel = `button.buchkal__tag.ist-frei[data-tag="${tag}"]`;
  await ganzeSeite(p, 'buchen-kalender', { leisten: ['header.kopf-band'], hoechstens: 1700, marken: { tag: tagSel, kalender: '.buchkal' } });
  const kal = manifest['buchen-kalender'].marken.kalender;
  const scrollY = Math.max(0, kal.y - 200);
  manifest['buchen-kalender'].scrollZiel = scrollY;
  await p.evaluate((y) => window.scrollTo(0, y), scrollY);
  await p.waitForTimeout(200);
  await p.locator(tagSel).click();
  await p.waitForTimeout(500);
  await ausschnitt(p, 'buchen-zeiten', TELEFON, { zeit: '.zeitknopf:visible' });
  await Promise.all([p.waitForNavigation(), p.locator('.zeitknopf:visible').first().click()]);
  await warten(p);
  await ganzeSeite(p, 'buchen-angaben', { leisten: ['header.kopf-band'], hoechstens: 1500, marken: { gewaehlt: '.vorgang__gewaehlt' } });

  // Kundenakte: Kopf, Gesundheitswert, Handicap-Verlauf
  await p.goto(BASIS + '/app/kunde.php?id=1');
  await warten(p);
  await ganzeSeite(p, 'kunde', { leisten: ['header.kopf'], hoechstens: 3000, marken: { handicap: '.karte:has-text("Handicap-Entwicklung")' } });
  const portalLink = await p.locator('a[href*="/portal/?t="]').first().getAttribute('href');

  // Trainingsplan
  await p.goto(BASIS + '/app/trainingsplan.php?id=1');
  await warten(p);
  await ganzeSeite(p, 'plan', { leisten: ['header.kopf'], hoechstens: 2600 });

  // Rechnungen: abrechnen, was noch offen ist
  await p.goto(BASIS + '/app/rechnungen.php');
  await warten(p);
  await ganzeSeite(p, 'rechnungen', { leisten: ['header.kopf'], hoechstens: 2600, marken: { abrechnen: '.karte:has-text("Noch nicht abgerechnet")', knopf: '.karte:has-text("Noch nicht abgerechnet") button:has-text("Rechnung"), .karte:has-text("Noch nicht abgerechnet") a:has-text("Rechnung")' } });

  // Der Tipp auf „Rechnung“: Aus den offenen Terminen wird wirklich eine
  // Rechnung. Darum nur gegen eine Demo-Datenbank laufen lassen.
  const knopf = '.karte:has-text("Noch nicht abgerechnet") button:has-text("Rechnung")';
  await p.evaluate((y) => window.scrollTo(0, y), Math.max(0, manifest.rechnungen.marken.abrechnen.y - 140));
  await Promise.all([p.waitForNavigation(), p.locator(knopf).first().click()]);
  await warten(p);
  await ausschnitt(p, 'rechnung-neu', TELEFON);
  manifest.rechnungen.scrollZiel = Math.max(0, manifest.rechnungen.marken.abrechnen.y - 140);

  // Golfreise auf der Website
  await p.goto(BASIS + '/reise.php?r=golfwoche-mallorca');
  await warten(p);
  await ganzeSeite(p, 'reise', { leisten: ['header.kopf-band', '.reiseleiste'], hoechstens: 3400, marken: { bilder: '.bildfenster, .reise-galerie, h2:has-text("Eindrücke")' } });

  // Startseite der Website
  await p.goto(BASIS + '/');
  await warten(p);
  await ausschnitt(p, 'website', TELEFON);

  // Kundenportal – aus der Akte geöffnet, wie der Pro es auch tut
  await p.goto(portalLink);
  await warten(p);
  await ausschnitt(p, 'portal', TELEFON);

  // Dashboard
  await p.goto(BASIS + '/app/');
  await warten(p);
  await ausschnitt(p, 'dashboard', TELEFON);
  await tel.close();

  /* ------------------------------------------------------ Desktop --- */
  const desk = await browser.newContext({ viewport: DESKTOP, deviceScaleFactor: 2, locale: 'de-DE', colorScheme: 'light' });
  const d = await desk.newPage();
  await anmelden(d);
  console.log('Desktop');
  const naechsteWoche = new Date(Date.now() + 7 * 86400000);
  await d.goto(BASIS + '/app/kalender.php?ansicht=woche&datum=' + isoTag(naechsteWoche));
  await warten(d);
  await d.mouse.move(DESKTOP.width - 5, DESKTOP.height - 5);
  await ausschnitt(d, 'kalender', DESKTOP);
  await d.goto(BASIS + '/app/seite.php?id=1');
  await warten(d);
  await d.mouse.move(DESKTOP.width - 5, DESKTOP.height - 5);
  await ausschnitt(d, 'baukasten', DESKTOP);
  await desk.close();

  await browser.close();
  fs.writeFileSync(MANIFEST, JSON.stringify(manifest, null, 2) + '\n');
  console.log(`\n${Object.keys(manifest).length} Aufnahmen → ${path.relative(WURZEL, MANIFEST)}`);
})().catch((e) => {
  console.error(e);
  process.exit(1);
});
