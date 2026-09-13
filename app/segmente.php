<?php
/**
 * Segmente – gespeicherte Fragen an den Kundenbestand.
 *
 * Ein Segment ist keine Liste, sondern eine Regel: „Handicap über 36 und
 * seit 60 Tagen kein Termin". Wer heute dazugehört, ergibt sich beim
 * Nachsehen. Deshalb wird nie eine Mitgliederliste gespeichert – das wäre
 * am nächsten Tag falsch.
 */
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/partials/helfer.php';

Auth::fordern('modul.customers');
Auth::fordern('customers.view');

if (App::istPost()) {
    Auth::csrfFordern();
    Auth::fordern('customers.write');
    $id = App::postInt('id');

    if (App::aktion() === 'speichern') {
        $felder = App::postListe('feld');
        $ops    = App::postListe('op');
        $werte  = App::postListe('wert');

        $bedingungen = [];
        foreach ($felder as $i => $feld) {
            $op = (string) ($ops[$i] ?? '=');
            if (!isset(Segments::FELDER[$feld]) || !isset(Segments::OPERATOREN[$op])) {
                continue;
            }
            $wert = trim((string) ($werte[$i] ?? ''));
            // „ist leer“ und „ist gefüllt“ brauchen keinen Wert, alles andere schon.
            if ($wert === '' && !in_array($op, ['leer', 'nicht_leer'], true)) {
                continue;
            }
            if ($feld === 'umsatz_cent' && $wert !== '') {
                $wert = (string) Util::centAus($wert);
            }
            $bedingungen[] = ['feld' => $feld, 'op' => $op, 'wert' => $wert];
        }

        $regeln = [
            'verknuepfung' => App::post('verknuepfung', 'und') === 'oder' ? 'oder' : 'und',
            'bedingungen'  => $bedingungen,
        ];
        $daten = [
            'name'         => App::post('name'),
            'beschreibung' => App::post('beschreibung'),
            'farbe'        => App::post('farbe', '#0d6b4f'),
            'regeln'       => Util::json($regeln),
        ];

        if ($daten['name'] === '') {
            App::melden('Das Segment braucht einen Namen.', 'fehler');
        } elseif ($bedingungen === []) {
            App::melden('Ohne Bedingung wäre das Segment einfach die ganze Kundenliste.', 'fehler');
        } elseif ($id > 0) {
            Tenant::update('segments', $id, $daten);
            Audit::schreiben('geaendert', 'segment', $id, $daten['name']);
            App::melden('Segment gespeichert.');
        } else {
            $neu = Tenant::insert('segments', $daten + ['system' => 0, 'erstellt' => Util::jetzt()]);
            Audit::schreiben('erstellt', 'segment', $neu, $daten['name']);
            App::melden('Segment angelegt.');
        }
    }

    if (App::aktion() === 'loeschen' && $id > 0) {
        $s = Tenant::find('segments', $id);
        if ($s !== null && (int) $s['system'] === 1) {
            App::melden('Vorgegebene Segmente lassen sich nicht löschen – aber bearbeiten.', 'info');
        } elseif ($s !== null) {
            Tenant::delete('segments', $id);
            Audit::schreiben('geloescht', 'segment', $id, (string) $s['name']);
            App::melden('Segment gelöscht. Die Kunden bleiben selbstverständlich.');
        }
    }
    App::weiter('/app/segmente.php');
}

if (Tenant::count('segments') === 0) {
    Segments::standardAnlegen();
}
$segmente = Segments::alle();

$titel = 'Segmente';
$unter = 'Gespeicherte Fragen an den Kundenbestand – für Newsletter, Kampagnen und Auswertung.';
$brotkrumen = [['Kunden', '/app/kunden.php'], ['Segmente', null]];
$aktionen = Auth::darf('customers.write')
    ? '<button class="btn btn--primaer" data-modal-auf="modal-segment" data-modal-titel="Segment anlegen">'
      . Icon::svg('plus', 15) . ' Segment anlegen</button>' : '';
require __DIR__ . '/partials/kopf.php';
?>

<div class="raster raster--auto-gross">
  <?php foreach ($segmente as $s):
    $regeln = Util::ausJson((string) $s['regeln'], []);
    $anzahl = Segments::anzahl($s); ?>
    <div class="karte">
      <div class="karte__koerper">
        <div class="reihe reihe--eng mb-3">
          <span style="width:10px;height:10px;border-radius:3px;background:<?= Util::attr((string) ($s['farbe'] ?: 'var(--marke)')) ?>"></span>
          <h3 style="flex:1"><?= Util::h((string) $s['name']) ?></h3>
          <?php if ((int) $s['system'] === 1): ?><?= pille('vorgegeben') ?><?php endif; ?>
        </div>
        <div class="kennzahl__wert" style="font-size:26px;margin-bottom:6px">
          <?= Util::zahl($anzahl) ?> <small class="gedimmt"><?= $anzahl === 1 ? 'Kunde' : 'Kunden' ?></small>
        </div>
        <p class="klein gedimmt" style="min-height:2.4em"><?= Util::h((string) $s['beschreibung']) ?></p>
        <div class="hinweis hinweis--still mt-3">
          <?= Icon::svg('filter', 15) ?>
          <div class="hinweis__text klein"><?= Util::h(Segments::beschreiben($regeln)) ?></div>
        </div>
      </div>
      <div class="karte__fuss">
        <a class="btn btn--klein" href="<?= Util::attr(App::url('/app/kunden.php?segment=' . (int) $s['id'])) ?>">
          <?= Icon::svg('customers', 14) ?> Kunden zeigen</a>
        <div class="fueller"></div>
        <?php if (Auth::darf('customers.write')): ?>
          <?php if ((int) $s['system'] === 0): ?>
            <form method="post" class="inline" data-bestaetigen="Segment „<?= Util::attr((string) $s['name']) ?>“ wirklich löschen? Die Kunden bleiben erhalten.">
              <?= Auth::csrfFeld() ?>
              <input type="hidden" name="aktion" value="loeschen">
              <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
              <button class="btn btn--klein" type="submit"><?= Icon::svg('trash', 14) ?></button>
            </form>
          <?php endif; ?>
          <button class="btn btn--klein" data-modal-auf="modal-segment"
                  data-modal-titel="Segment bearbeiten"
                  data-setzid="<?= (int) $s['id'] ?>"
                  data-setzname="<?= Util::attr((string) $s['name']) ?>"
                  data-setzbeschreibung="<?= Util::attr((string) $s['beschreibung']) ?>"
                  data-setzfarbe="<?= Util::attr((string) ($s['farbe'] ?: '#0d6b4f')) ?>"
                  data-setzverknuepfung="<?= Util::attr((string) ($regeln['verknuepfung'] ?? 'und')) ?>"
                  data-regeln="<?= Util::attr(Util::json((array) ($regeln['bedingungen'] ?? []))) ?>">
            <?= Icon::svg('edit', 14) ?> Bearbeiten</button>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<?php if (Auth::darf('customers.write')): ?>
<dialog class="modal modal--breit" id="modal-segment">
  <form method="post">
    <?= Auth::csrfFeld() ?>
    <input type="hidden" name="aktion" value="speichern">
    <input type="hidden" name="id" value="0">
    <div class="modal__kopf"><h2>Segment</h2>
      <button type="button" class="rundknopf" data-modal-zu aria-label="Schließen"><?= Icon::svg('x', 17) ?></button></div>
    <div class="modal__koerper">
      <div class="feld-reihe feld-reihe--2">
        <div class="feld"><label class="feld__label" for="s-name">Name</label>
          <input class="eingabe" id="s-name" name="name" required placeholder="Anfänger ohne Termin"></div>
        <div class="feld"><label class="feld__label" for="s-farbe">Farbe</label>
          <input class="eingabe eingabe--farbe" id="s-farbe" type="color" name="farbe" value="#0d6b4f"></div>
      </div>
      <div class="feld"><label class="feld__label" for="s-beschreibung">Wozu ist das Segment da?</label>
        <input class="eingabe" id="s-beschreibung" name="beschreibung"
               placeholder="Für die Einladung zum Platzreifekurs im Frühjahr."></div>

      <div class="feld">
        <label class="feld__label" for="s-verknuepfung">Wie werden die Bedingungen verknüpft?</label>
        <select id="s-verknuepfung" name="verknuepfung" style="max-width:280px">
          <option value="und">Alle Bedingungen müssen zutreffen</option>
          <option value="oder">Mindestens eine Bedingung muss zutreffen</option>
        </select>
      </div>

      <div class="feld">
        <span class="feld__label">Bedingungen</span>
        <div id="s-regeln" class="stapel stapel--eng"></div>
        <button type="button" class="btn btn--klein mt-3" id="s-plus">
          <?= Icon::svg('plus', 14) ?> Bedingung hinzufügen</button>
      </div>

      <div class="hinweis hinweis--still mt-3">
        <?= Icon::svg('info', 17) ?>
        <div class="hinweis__text">Ein Segment speichert die Regel, nicht die Namen. Wer morgen
          dazupasst, ist morgen dabei – ohne dass jemand die Liste pflegen muss.</div>
      </div>
    </div>
    <div class="modal__fuss"><div class="fueller"></div>
      <button type="button" class="btn" data-modal-zu>Abbrechen</button>
      <button type="submit" class="btn btn--primaer">Speichern</button></div>
  </form>
</dialog>

<script>
/*
 * Der Regelbauer. Bewusst ohne Bibliothek: Es sind drei Auswahlfelder je
 * Zeile, und die Zeilen werden beim Absenden als parallele Arrays
 * übertragen (feld[], op[], wert[]) – die PHP-Seite setzt sie über den
 * Index wieder zusammen.
 */
(function () {
  var FELDER = <?= Util::json(array_map(static fn ($f) => $f[0], Segments::FELDER)) ?>;
  var OPS    = <?= Util::json(Segments::OPERATOREN) ?>;
  var huelle = document.getElementById('s-regeln');
  var plus   = document.getElementById('s-plus');
  if (!huelle || !plus) { return; }

  function zeile(feld, op, wert) {
    var reihe = document.createElement('div');
    reihe.className = 'reihe reihe--eng';

    var fs = document.createElement('select');
    fs.name = 'feld[]';
    Object.keys(FELDER).forEach(function (k) {
      var o = document.createElement('option');
      o.value = k; o.textContent = FELDER[k];
      if (k === feld) { o.selected = true; }
      fs.appendChild(o);
    });

    var os = document.createElement('select');
    os.name = 'op[]';
    os.style.maxWidth = '150px';
    Object.keys(OPS).forEach(function (k) {
      var o = document.createElement('option');
      o.value = k; o.textContent = OPS[k];
      if (k === op) { o.selected = true; }
      os.appendChild(o);
    });

    var ws = document.createElement('input');
    ws.className = 'eingabe';
    ws.name = 'wert[]';
    ws.value = wert || '';
    ws.style.maxWidth = '150px';

    function wertZeigen() {
      var ohne = os.value === 'leer' || os.value === 'nicht_leer';
      ws.style.visibility = ohne ? 'hidden' : 'visible';
    }
    os.addEventListener('change', wertZeigen);

    var weg = document.createElement('button');
    weg.type = 'button';
    weg.className = 'btn btn--klein';
    weg.textContent = '×';
    weg.setAttribute('aria-label', 'Bedingung entfernen');
    weg.addEventListener('click', function () { reihe.remove(); });

    reihe.appendChild(fs); reihe.appendChild(os); reihe.appendChild(ws); reihe.appendChild(weg);
    huelle.appendChild(reihe);
    wertZeigen();
  }

  plus.addEventListener('click', function () { zeile('hcp', '>=', ''); });

  /* Beim Öffnen des Modals die gespeicherten Bedingungen aufbauen. */
  document.addEventListener('click', function (e) {
    var knopf = e.target.closest('[data-modal-auf="modal-segment"]');
    if (!knopf) { return; }
    huelle.innerHTML = '';
    var roh = knopf.getAttribute('data-regeln');
    var liste = [];
    if (roh) { try { liste = JSON.parse(roh); } catch (err) { liste = []; } }
    if (!liste.length) { zeile('hcp', '>=', ''); return; }
    liste.forEach(function (b) { zeile(b.feld, b.op, b.wert); });
  });
})();
</script>
<?php endif; ?>

<?php require __DIR__ . '/partials/fuss.php'; ?>
