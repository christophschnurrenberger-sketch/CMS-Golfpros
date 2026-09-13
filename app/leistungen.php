<?php
/**
 * Leistungen und Preise – die Grundlage für Buchung, Website und Rechnung.
 */
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/partials/helfer.php';

Auth::fordern('modul.bookings');

if (App::istPost()) {
    Auth::csrfFordern();
    Auth::fordern('bookings.write');
    $id = App::postInt('id');

    if (App::aktion() === 'speichern') {
        $daten = [
            'name' => App::post('name'),
            'slug' => Util::slug(App::post('name')),
            'art' => App::post('art', 'einzel'),
            'beschreibung' => App::post('beschreibung'),
            'dauer_min' => max(5, App::postInt('dauer_min', 60)),
            'puffer_min' => App::postInt('puffer_min'),
            'preis_cent' => Util::centAus(App::post('preis')),
            'kapazitaet' => max(1, App::postInt('kapazitaet', 1)),
            'location_id' => App::postInt('location_id'),
            'trainer_ids' => Util::json(array_map('intval', App::postListe('trainer_ids'))),
            'farbe' => App::post('farbe'),
            'online_buchbar' => App::postBool('online_buchbar') ? 1 : 0,
            'vorlauf_stunden' => App::postInt('vorlauf_stunden', 12),
            'stornofrist_stunden' => App::postInt('stornofrist_stunden', 24),
            'aktiv' => App::postBool('aktiv') ? 1 : 0,
        ];
        if ($daten['name'] === '') {
            App::melden('Die Leistung braucht einen Namen.', 'fehler');
        } elseif ($id > 0) {
            Tenant::update('services', $id, $daten);
            Audit::schreiben('geaendert', 'service', $id, $daten['name']);
            App::melden('Leistung gespeichert.');
        } else {
            $daten['position'] = Tenant::count('services');
            $neu = Tenant::insert('services', $daten);
            Audit::schreiben('erstellt', 'service', $neu, $daten['name']);
            App::melden('Leistung angelegt.');
        }
    }

    if (App::aktion() === 'loeschen' && $id > 0) {
        $genutzt = Tenant::count('bookings', 'service_id = :s', ['s' => $id]);
        if ($genutzt > 0) {
            Tenant::update('services', $id, ['aktiv' => 0]);
            App::melden('Die Leistung wird in ' . $genutzt . ' Terminen verwendet und wurde '
                      . 'deshalb nur deaktiviert – so bleibt die Historie lesbar.', 'info');
        } else {
            Tenant::delete('services', $id);
            App::melden('Leistung gelöscht.');
        }
    }
    App::weiter('/app/leistungen.php');
}

$leistungen = Tenant::all('services', '', [], 'aktiv DESC, position, name');
$orte = Tenant::all('locations', 'aktiv = 1', [], 'name');
$trainer = Auth::trainer();

$titel = 'Leistungen und Preise';
$unter = 'Was du anbietest – Grundlage für Online-Buchung, Website und Rechnungen.';
$aktionen = Auth::darf('bookings.write')
    ? '<button class="btn btn--primaer" data-modal-auf="modal-leistung" data-modal-titel="Neue Leistung">'
      . Icon::svg('plus', 15) . ' Leistung anlegen</button>' : '';
require __DIR__ . '/partials/kopf.php';
?>

<?php if ($leistungen === []): ?>
  <div class="karte"><div class="karte__koerper">
    <?= leerzustand('list', 'Noch keine Leistungen',
          'Eine Leistung ist alles, was gebucht werden kann: Einzeltraining, Videoanalyse, '
          . 'Platzreifekurs, Gruppentraining. Dauer und Preis bestimmen, was die Online-Buchung '
          . 'auf deiner Website anzeigt.', []) ?>
    <?php if (Auth::darf('bookings.write')): ?>
      <div class="mitte">
        <button class="btn btn--primaer" data-modal-auf="modal-leistung">
          <?= Icon::svg('plus', 15) ?> Erste Leistung anlegen</button>
      </div>
    <?php endif; ?>
  </div></div>
<?php else: ?>
  <div class="raster raster--auto-gross">
    <?php foreach ($leistungen as $s):
      $trainerIds = Util::ausJson((string) $s['trainer_ids'], []);
      $buchungen = Tenant::count('bookings', 'service_id = :s AND status != "abgesagt"', ['s' => (int) $s['id']]); ?>
      <div class="karte" style="<?= (int) $s['aktiv'] === 0 ? 'opacity:.6' : '' ?>">
        <div class="karte__koerper">
          <div class="reihe reihe--eng mb-3">
            <span style="width:10px;height:10px;border-radius:3px;background:<?= Util::attr((string) ($s['farbe'] ?: 'var(--marke)')) ?>"></span>
            <h3 style="flex:1"><?= Util::h((string) $s['name']) ?></h3>
            <?php if ((int) $s['aktiv'] === 0): ?><?= pille('inaktiv') ?><?php endif; ?>
            <?php if ((int) $s['online_buchbar'] === 1 && (int) $s['aktiv'] === 1): ?>
              <span class="tipp" data-tipp="Auf der Website buchbar"><?= Icon::svg('globe', 15) ?></span>
            <?php endif; ?>
          </div>
          <p class="klein gedimmt" style="min-height:2.6em"><?= Util::h(Util::kuerzen((string) $s['beschreibung'], 130)) ?></p>
          <div class="reihe reihe--eng reihe--umbruch mt-3">
            <?= pille((int) $s['dauer_min'] . ' Min') ?>
            <?= pille(Util::geldKurz((int) $s['preis_cent']), 'marke') ?>
            <?php if ((int) $s['kapazitaet'] > 1): ?><?= pille('bis ' . (int) $s['kapazitaet'] . ' TN', 'lila') ?><?php endif; ?>
            <?php if ($buchungen > 0): ?><?= pille($buchungen . '× gebucht', 'offen') ?><?php endif; ?>
          </div>
        </div>
        <?php if (Auth::darf('bookings.write')): ?>
        <div class="karte__fuss">
          <span class="klein gedimmt">
            <?= count($trainerIds) > 0 ? count($trainerIds) . ' Trainer' : 'alle Trainer' ?>
          </span>
          <div class="fueller"></div>
          <button class="btn btn--klein" data-modal-auf="modal-leistung"
                  data-setzid="<?= (int) $s['id'] ?>"
                  data-setzname="<?= Util::attr((string) $s['name']) ?>"
                  data-setzart="<?= Util::attr((string) $s['art']) ?>"
                  data-setzbeschreibung="<?= Util::attr((string) $s['beschreibung']) ?>"
                  data-setzdauer_min="<?= (int) $s['dauer_min'] ?>"
                  data-setzpuffer_min="<?= (int) $s['puffer_min'] ?>"
                  data-setzpreis="<?= Util::attr(number_format((int) $s['preis_cent'] / 100, 2, ',', '')) ?>"
                  data-setzkapazitaet="<?= (int) $s['kapazitaet'] ?>"
                  data-setzvorlauf_stunden="<?= (int) $s['vorlauf_stunden'] ?>"
                  data-setzstornofrist_stunden="<?= (int) $s['stornofrist_stunden'] ?>"
                  data-modal-titel="Leistung bearbeiten">
            <?= Icon::svg('edit', 14) ?> Bearbeiten</button>
        </div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if (Auth::darf('bookings.write')): ?>
<dialog class="modal modal--breit" id="modal-leistung">
  <form method="post">
    <?= Auth::csrfFeld() ?>
    <input type="hidden" name="aktion" value="speichern">
    <input type="hidden" name="id" value="0">
    <div class="modal__kopf"><h2>Leistung</h2>
      <button type="button" class="rundknopf" data-modal-zu aria-label="Schließen"><?= Icon::svg('x', 17) ?></button></div>
    <div class="modal__koerper">
      <div class="feld-reihe feld-reihe--2">
        <div class="feld"><label class="feld__label" for="l-name">Name</label>
          <input class="eingabe" id="l-name" name="name" required placeholder="Einzeltraining 60 Minuten"></div>
        <div class="feld"><label class="feld__label" for="l-art">Art</label>
          <select id="l-art" name="art">
            <?php foreach (['einzel' => 'Einzeltraining', 'gruppe' => 'Gruppentraining', 'kurs' => 'Kurs',
                            'video' => 'Videoanalyse', 'workshop' => 'Workshop', 'camp' => 'Camp',
                            'online' => 'Online-Coaching', 'reise' => 'Golfreise'] as $k => $v): ?>
              <option value="<?= $k ?>"><?= $v ?></option>
            <?php endforeach; ?>
          </select></div>
      </div>
      <div class="feld"><label class="feld__label" for="l-beschreibung">Beschreibung</label>
        <textarea class="eingabe" id="l-beschreibung" name="beschreibung" rows="3" data-waechst
                  placeholder="Was passiert in dieser Einheit? Dieser Text erscheint auch auf der Website."></textarea></div>
      <div class="feld-reihe feld-reihe--3">
        <div class="feld"><label class="feld__label" for="l-dauer">Dauer (Minuten)</label>
          <input class="eingabe" id="l-dauer" type="number" name="dauer_min" value="60" min="5" step="5"></div>
        <div class="feld"><label class="feld__label" for="l-puffer">Puffer danach</label>
          <input class="eingabe" id="l-puffer" type="number" name="puffer_min" value="10" min="0" step="5">
          <div class="feld__hinweis">Zeit zum Aufräumen und Umziehen.</div></div>
        <div class="feld"><label class="feld__label" for="l-preis">Preis</label>
          <div class="eingabe-gruppe">
            <input class="eingabe" id="l-preis" name="preis" value="0,00">
            <span class="eingabe-gruppe__anhang"><?= Util::h(Tenant::waehrung()) ?></span>
          </div></div>
      </div>
      <div class="feld-reihe feld-reihe--3">
        <div class="feld"><label class="feld__label" for="l-kapazitaet">Teilnehmer maximal</label>
          <input class="eingabe" id="l-kapazitaet" type="number" name="kapazitaet" value="1" min="1"></div>
        <div class="feld"><label class="feld__label" for="l-vorlauf">Vorlauf (Stunden)</label>
          <input class="eingabe" id="l-vorlauf" type="number" name="vorlauf_stunden" value="12" min="0">
          <div class="feld__hinweis">So kurzfristig darf online gebucht werden.</div></div>
        <div class="feld"><label class="feld__label" for="l-storno">Stornofrist (Stunden)</label>
          <input class="eingabe" id="l-storno" type="number" name="stornofrist_stunden" value="24" min="0"></div>
      </div>
      <?php if (count($orte) > 1): ?>
      <div class="feld"><label class="feld__label" for="l-ort">Standort</label>
        <select id="l-ort" name="location_id">
          <option value="0">Kein fester Standort</option>
          <?php foreach ($orte as $o): ?>
            <option value="<?= (int) $o['id'] ?>"><?= Util::h((string) $o['name']) ?></option>
          <?php endforeach; ?>
        </select></div>
      <?php endif; ?>
      <?php if (count($trainer) > 1): ?>
      <div class="feld">
        <span class="feld__label">Wer bietet das an?</span>
        <div class="stapel stapel--eng">
          <?php foreach ($trainer as $t): ?>
            <label class="haken"><input type="checkbox" name="trainer_ids[]" value="<?= (int) $t['id'] ?>" checked>
              <span class="haken__text"><?= Util::h((string) $t['name']) ?></span></label>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>
      <div class="feld-reihe feld-reihe--2">
        <label class="haken"><input type="checkbox" name="online_buchbar" value="1" checked>
          <span class="haken__text">Auf der Website buchbar</span></label>
        <label class="haken"><input type="checkbox" name="aktiv" value="1" checked>
          <span class="haken__text">Aktiv</span></label>
      </div>
    </div>
    <div class="modal__fuss"><div class="fueller"></div>
      <button type="button" class="btn" data-modal-zu>Abbrechen</button>
      <button type="submit" class="btn btn--primaer">Speichern</button></div>
  </form>
</dialog>
<?php endif; ?>

<?php require __DIR__ . '/partials/fuss.php'; ?>
