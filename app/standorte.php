<?php
/**
 * Standorte – Club, Range, Indoor, Platz.
 *
 * Ein Standort ist mehr als eine Adresse: Er hängt an Leistungen, Terminen,
 * Trainern und an der Anfahrt auf der Website. Deshalb wird ein genutzter
 * Standort nie gelöscht, sondern nur stillgelegt – sonst verlieren alte
 * Termine ihren Ort.
 */
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/partials/helfer.php';

Auth::fordern('modul.settings');
Auth::fordern('settings.allgemein');

if (App::istPost()) {
    Auth::csrfFordern();
    $id = App::postInt('id');

    if (App::aktion() === 'speichern') {
        $daten = [
            'name'    => App::post('name'),
            'typ'     => App::post('typ', 'club'),
            'strasse' => App::post('strasse'),
            'plz'     => App::post('plz'),
            'ort'     => App::post('ort'),
            'land'    => App::post('land', 'DE'),
            'lat'     => App::post('lat'),
            'lng'     => App::post('lng'),
            'notiz'   => App::post('notiz'),
            'farbe'   => App::post('farbe', '#0d6b4f'),
            'aktiv'   => App::postBool('aktiv') ? 1 : 0,
        ];
        if ($daten['name'] === '') {
            App::melden('Der Standort braucht einen Namen.', 'fehler');
        } elseif ($id > 0) {
            Tenant::update('locations', $id, $daten);
            Audit::schreiben('geaendert', 'location', $id, $daten['name']);
            App::melden('Standort gespeichert.');
        } else {
            $daten['erstellt'] = Util::jetzt();
            $neu = Tenant::insert('locations', $daten);
            Audit::schreiben('erstellt', 'location', $neu, $daten['name']);
            App::melden('Standort angelegt.');
        }
    }

    if (App::aktion() === 'loeschen' && $id > 0) {
        $genutzt = Tenant::count('bookings', 'location_id = :l', ['l' => $id])
                 + Tenant::count('services', 'location_id = :l', ['l' => $id]);
        if ($genutzt > 0) {
            Tenant::update('locations', $id, ['aktiv' => 0]);
            App::melden('Der Standort hängt an ' . $genutzt . ' Einträgen und wurde deshalb nur '
                      . 'stillgelegt. So bleiben alte Termine nachvollziehbar.', 'info');
        } else {
            Tenant::delete('locations', $id);
            Audit::schreiben('geloescht', 'location', $id);
            App::melden('Standort gelöscht.');
        }
    }
    App::weiter('/app/standorte.php');
}

$orte = Tenant::all('locations', '', [], 'aktiv DESC, name');
$typen = ['club' => 'Golfclub', 'range' => 'Driving Range', 'indoor' => 'Indoor / Simulator',
          'platz' => 'Platz', 'extern' => 'Auswärts'];

$titel = 'Standorte';
$unter = 'Wo trainiert wird – für Kalender, Buchung und die Anfahrt auf der Website.';
$brotkrumen = [['Einstellungen', '/app/einstellungen.php'], ['Standorte', null]];
$aktionen = '<button class="btn btn--primaer" data-modal-auf="modal-ort" data-modal-titel="Standort anlegen">'
          . Icon::svg('plus', 15) . ' Standort anlegen</button>';
require __DIR__ . '/partials/kopf.php';
?>

<?php if ($orte === []): ?>
  <div class="karte"><div class="karte__koerper">
    <?= leerzustand('pin', 'Noch kein Standort hinterlegt',
          'Mit mindestens einem Standort weiß die Online-Buchung, wo der Termin stattfindet, '
          . 'und auf der Website erscheint eine Anfahrt. Wer nur an einem Club unterrichtet, '
          . 'legt genau einen an und ist fertig.') ?>
    <div class="mitte">
      <button class="btn btn--primaer" data-modal-auf="modal-ort">
        <?= Icon::svg('plus', 15) ?> Ersten Standort anlegen</button>
    </div>
  </div></div>
<?php else: ?>
  <div class="raster raster--auto-gross">
    <?php foreach ($orte as $o):
      $termine = Tenant::count('bookings', 'location_id = :l AND status != "abgesagt"', ['l' => (int) $o['id']]);
      $anschrift = trim((string) $o['strasse'] . ', ' . (string) $o['plz'] . ' ' . (string) $o['ort'], ', '); ?>
      <div class="karte" style="<?= (int) $o['aktiv'] === 0 ? 'opacity:.6' : '' ?>">
        <div class="karte__koerper">
          <div class="reihe reihe--eng mb-3">
            <span style="width:10px;height:10px;border-radius:3px;background:<?= Util::attr((string) ($o['farbe'] ?: 'var(--marke)')) ?>"></span>
            <h3 style="flex:1"><?= Util::h((string) $o['name']) ?></h3>
            <?php if ((int) $o['aktiv'] === 0): ?><?= pille('stillgelegt') ?><?php endif; ?>
          </div>
          <div class="reihe reihe--eng klein gedimmt mb-2">
            <?= Icon::svg('pin', 14) ?>
            <span><?= $anschrift !== '' ? Util::h($anschrift) : 'keine Anschrift hinterlegt' ?></span>
          </div>
          <?php if ((string) $o['notiz'] !== ''): ?>
            <p class="klein gedimmt"><?= Util::h(Util::kuerzen((string) $o['notiz'], 130)) ?></p>
          <?php endif; ?>
          <div class="reihe reihe--eng reihe--umbruch mt-3">
            <?= pille((string) ($typen[(string) $o['typ']] ?? $o['typ'])) ?>
            <?php if ($termine > 0): ?><?= pille($termine . ' Termine', 'offen') ?><?php endif; ?>
          </div>
        </div>
        <div class="karte__fuss">
          <?php if ((string) $o['lat'] !== '' && (string) $o['lng'] !== ''): ?>
            <span class="winzig gedimmt mono"><?= Util::h((string) $o['lat']) ?>, <?= Util::h((string) $o['lng']) ?></span>
          <?php endif; ?>
          <div class="fueller"></div>
          <form method="post" class="inline" data-bestaetigen="Standort wirklich entfernen?">
            <?= Auth::csrfFeld() ?>
            <input type="hidden" name="aktion" value="loeschen">
            <input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
            <button class="btn btn--klein" type="submit"><?= Icon::svg('trash', 14) ?></button>
          </form>
          <button class="btn btn--klein" data-modal-auf="modal-ort"
                  data-modal-titel="Standort bearbeiten"
                  data-setzid="<?= (int) $o['id'] ?>"
                  data-setzname="<?= Util::attr((string) $o['name']) ?>"
                  data-setztyp="<?= Util::attr((string) $o['typ']) ?>"
                  data-setzstrasse="<?= Util::attr((string) $o['strasse']) ?>"
                  data-setzplz="<?= Util::attr((string) $o['plz']) ?>"
                  data-setzort="<?= Util::attr((string) $o['ort']) ?>"
                  data-setzlat="<?= Util::attr((string) $o['lat']) ?>"
                  data-setzlng="<?= Util::attr((string) $o['lng']) ?>"
                  data-setznotiz="<?= Util::attr((string) $o['notiz']) ?>"
                  data-setzfarbe="<?= Util::attr((string) ($o['farbe'] ?: '#0d6b4f')) ?>">
            <?= Icon::svg('edit', 14) ?> Bearbeiten</button>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<dialog class="modal modal--breit" id="modal-ort">
  <form method="post">
    <?= Auth::csrfFeld() ?>
    <input type="hidden" name="aktion" value="speichern">
    <input type="hidden" name="id" value="0">
    <div class="modal__kopf"><h2>Standort</h2>
      <button type="button" class="rundknopf" data-modal-zu aria-label="Schließen"><?= Icon::svg('x', 17) ?></button></div>
    <div class="modal__koerper">
      <div class="feld-reihe feld-reihe--2">
        <div class="feld"><label class="feld__label" for="o-name">Name</label>
          <input class="eingabe" id="o-name" name="name" required placeholder="Golfclub Sonnenhof"></div>
        <div class="feld"><label class="feld__label" for="o-typ">Art</label>
          <select id="o-typ" name="typ">
            <?php foreach ($typen as $k => $v): ?><option value="<?= $k ?>"><?= Util::h($v) ?></option><?php endforeach; ?>
          </select></div>
      </div>
      <div class="feld"><label class="feld__label" for="o-strasse">Straße und Hausnummer</label>
        <input class="eingabe" id="o-strasse" name="strasse"></div>
      <div class="feld-reihe feld-reihe--3">
        <div class="feld"><label class="feld__label" for="o-plz">PLZ</label>
          <input class="eingabe" id="o-plz" name="plz"></div>
        <div class="feld"><label class="feld__label" for="o-ort">Ort</label>
          <input class="eingabe" id="o-ort" name="ort"></div>
        <div class="feld"><label class="feld__label" for="o-land">Land</label>
          <select id="o-land" name="land">
            <?php foreach (['DE' => 'Deutschland', 'AT' => 'Österreich', 'CH' => 'Schweiz'] as $k => $v): ?>
              <option value="<?= $k ?>"><?= $v ?></option>
            <?php endforeach; ?>
          </select></div>
      </div>
      <div class="feld-reihe feld-reihe--3">
        <div class="feld"><label class="feld__label" for="o-lat">Breitengrad</label>
          <input class="eingabe mono" id="o-lat" name="lat" placeholder="48.1372">
          <div class="feld__hinweis">Optional – für die Karte auf der Website.</div></div>
        <div class="feld"><label class="feld__label" for="o-lng">Längengrad</label>
          <input class="eingabe mono" id="o-lng" name="lng" placeholder="11.5756"></div>
        <div class="feld"><label class="feld__label" for="o-farbe">Farbe im Kalender</label>
          <input class="eingabe eingabe--farbe" id="o-farbe" type="color" name="farbe" value="#0d6b4f"></div>
      </div>
      <div class="feld"><label class="feld__label" for="o-notiz">Hinweise für Kunden</label>
        <textarea class="eingabe" id="o-notiz" name="notiz" rows="2" data-waechst
                  placeholder="Parkplatz hinter dem Clubhaus, Treffpunkt an der Range."></textarea>
        <div class="feld__hinweis">Steht in der Buchungsbestätigung und in der Erinnerung.</div></div>
      <label class="haken"><input type="checkbox" name="aktiv" value="1" checked>
        <span class="haken__text">Aktiv
          <span class="haken__hinweis">Nur aktive Standorte erscheinen in der Online-Buchung.</span></span></label>
    </div>
    <div class="modal__fuss"><div class="fueller"></div>
      <button type="button" class="btn" data-modal-zu>Abbrechen</button>
      <button type="submit" class="btn btn--primaer">Speichern</button></div>
  </form>
</dialog>

<?php require __DIR__ . '/partials/fuss.php'; ?>
