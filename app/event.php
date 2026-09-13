<?php
/** Einzelnes Event: Teilnehmer, Warteliste, Check-in. */
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/partials/helfer.php';

Auth::fordern('modul.events');

$id = App::getInt('id');
$event = Tenant::find('events', $id);
if (!$event) {
    App::melden('Dieses Event wurde nicht gefunden.', 'fehler');
    App::weiter('/app/events.php');
}

if (App::istPost()) {
    Auth::csrfFordern();
    Auth::fordern('events.write');
    $aktion = App::aktion();

    if ($aktion === 'speichern') {
        Events::speichern([
            'titel' => App::post('titel'), 'art' => App::post('art'),
            'beschreibung' => App::post('beschreibung'),
            'start' => App::post('start') . ' ' . App::post('start_zeit') . ':00',
            'ende' => App::post('ende') . ' ' . App::post('ende_zeit') . ':00',
            'kapazitaet' => max(1, App::postInt('kapazitaet')),
            'preis_cent' => Util::centAus(App::post('preis')),
            'ort_text' => App::post('ort_text'), 'location_id' => App::postInt('location_id'),
            'status' => App::post('status'), 'warteliste' => App::postBool('warteliste') ? 1 : 0,
        ], $id);
        App::melden('Event gespeichert.');
    }
    if ($aktion === 'anmelden') {
        [$ok, $meldung] = Events::anmelden($id, App::postInt('customer_id'));
        App::melden($meldung, $ok ? 'erfolg' : 'info');
    }
    if ($aktion === 'abmelden') {
        Events::abmelden(App::postInt('anmeldung_id'));
        App::melden('Abgemeldet. Der Nächste auf der Warteliste ist nachgerückt.');
    }
    if ($aktion === 'checkin') {
        Events::einchecken(App::postInt('anmeldung_id'));
    }
    if ($aktion === 'bezahlt') {
        Tenant::update('event_registrations', App::postInt('anmeldung_id'), ['bezahlt' => 1]);
    }
    App::weiter('/app/event.php?id=' . $id);
}

$angemeldet = Events::anmeldungen($id, 'angemeldet');
$warteliste = Events::anmeldungen($id, 'warteliste');
$frei = Events::freiePlaetze($event);

$titel = (string) $event['titel'];
$unter = Util::datumLang((string) $event['start']) . ' · ' . count($angemeldet) . ' von '
       . (int) $event['kapazitaet'] . ' Plätzen belegt';
$brotkrumen = [['Events', '/app/events.php'], [(string) $event['titel'], null]];
$aktionen = Auth::darf('events.write')
    ? '<button class="btn btn--primaer" data-modal-auf="modal-anmelden">'
      . Icon::svg('user-plus', 15) . ' Teilnehmer hinzufügen</button>' : '';
require __DIR__ . '/partials/kopf.php';
?>

<div class="raster raster--4 mb-5">
  <?= kennzahl('Angemeldet', count($angemeldet) . ' / ' . (int) $event['kapazitaet'], ['icon' => 'customers']) ?>
  <?= kennzahl('Warteliste', (string) count($warteliste), ['icon' => 'clock',
        'fuss' => count($warteliste) > 0 ? 'ein zweiter Termin lohnt sich' : '']) ?>
  <?= kennzahl('Umsatz', Util::geld(Events::umsatz($id)), ['icon' => 'euro', 'fuss' => 'bezahlte Plätze']) ?>
  <?= kennzahl('Eingecheckt', (string) count(array_filter($angemeldet, static fn($a) => $a['eingecheckt'])),
        ['icon' => 'check']) ?>
</div>

<div class="raster raster--haupt-neben">
  <div class="stapel">
    <div class="karte">
      <div class="karte__kopf"><h2>Teilnehmer</h2>
        <span class="pille"><?= count($angemeldet) ?></span></div>
      <?php if ($angemeldet === []): ?>
        <div class="karte__koerper">
          <?= leerzustand('customers', 'Noch keine Anmeldungen',
                'Sobald das Event veröffentlicht ist, können sich Kunden über die Website anmelden. '
                . 'Du kannst auch direkt jemanden eintragen.', []) ?>
        </div>
      <?php else: ?>
        <div class="tabelle-huelle">
          <table class="tabelle">
            <thead><tr><th>Teilnehmer</th><th>Bezahlt</th><th>Check-in</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($angemeldet as $a): ?>
              <tr>
                <td><?= person(Customers::nameVonId((int) $a['customer_id']),
                      ['url' => '/app/kunde.php?id=' . (int) $a['customer_id']]) ?></td>
                <td>
                  <?php if ((int) $a['bezahlt'] === 1): ?>
                    <?= pille('bezahlt', 'erfolg') ?>
                  <?php elseif (Auth::darf('events.write')): ?>
                    <form method="post"><?= Auth::csrfFeld() ?>
                      <input type="hidden" name="aktion" value="bezahlt">
                      <input type="hidden" name="anmeldung_id" value="<?= (int) $a['id'] ?>">
                      <button class="btn btn--klein" type="submit">als bezahlt</button></form>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($a['eingecheckt']): ?>
                    <span style="color:var(--erfolg)"><?= Icon::svg('check', 16) ?></span>
                  <?php elseif (Auth::darf('events.write')): ?>
                    <form method="post"><?= Auth::csrfFeld() ?>
                      <input type="hidden" name="aktion" value="checkin">
                      <input type="hidden" name="anmeldung_id" value="<?= (int) $a['id'] ?>">
                      <button class="btn btn--klein" type="submit">Check-in</button></form>
                  <?php endif; ?>
                </td>
                <td class="aktionen">
                  <?php if (Auth::darf('events.write')): ?>
                    <form method="post" data-bestaetigen="Teilnehmer abmelden? Der Erste auf der Warteliste rückt nach.">
                      <?= Auth::csrfFeld() ?>
                      <input type="hidden" name="aktion" value="abmelden">
                      <input type="hidden" name="anmeldung_id" value="<?= (int) $a['id'] ?>">
                      <button class="btn btn--klein btn--geist btn--nur-symbol" type="submit"
                              aria-label="Abmelden"><?= Icon::svg('x', 14) ?></button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

    <?php if ($warteliste !== []): ?>
    <div class="karte">
      <div class="karte__kopf"><h3>Warteliste</h3>
        <span class="pille pille--warnung"><?= count($warteliste) ?></span>
        <div class="fueller"></div>
        <span class="klein gedimmt">rücken automatisch nach</span></div>
      <div class="karte__koerper karte__koerper--eng">
        <div class="stapel stapel--eng">
          <?php foreach ($warteliste as $i => $a): ?>
            <div class="reihe" style="padding:6px">
              <span class="gedimmt-2 mono" style="width:24px"><?= $i + 1 ?>.</span>
              <?= person(Customers::nameVonId((int) $a['customer_id']),
                    ['url' => '/app/kunde.php?id=' . (int) $a['customer_id']]) ?>
              <div class="fueller"></div>
              <span class="winzig gedimmt-2"><?= Util::h(Util::relativ((string) $a['erstellt'])) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <form method="post" class="karte">
    <?= Auth::csrfFeld() ?>
    <input type="hidden" name="aktion" value="speichern">
    <div class="karte__kopf"><h3>Event</h3></div>
    <div class="karte__koerper">
      <div class="feld"><label class="feld__label" for="e-titel">Titel</label>
        <input class="eingabe" id="e-titel" name="titel" value="<?= Util::attr((string) $event['titel']) ?>"></div>
      <div class="feld"><label class="feld__label" for="e-art">Art</label>
        <select id="e-art" name="art">
          <?php foreach (Events::ARTEN as $k => [$n, $i]): ?>
            <option value="<?= $k ?>"<?= (string) $event['art'] === $k ? ' selected' : '' ?>><?= Util::h($n) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="feld"><label class="feld__label" for="e-start">Beginn</label>
        <div class="reihe reihe--eng">
          <input class="eingabe" id="e-start" type="date" name="start"
                 value="<?= Util::attr(date('Y-m-d', strtotime((string) $event['start']))) ?>">
          <input class="eingabe" type="time" name="start_zeit" style="width:105px"
                 value="<?= Util::attr(date('H:i', strtotime((string) $event['start']))) ?>" aria-label="Beginn"></div></div>
      <div class="feld"><label class="feld__label" for="e-ende">Ende</label>
        <div class="reihe reihe--eng">
          <input class="eingabe" id="e-ende" type="date" name="ende"
                 value="<?= Util::attr(date('Y-m-d', strtotime((string) $event['ende']))) ?>">
          <input class="eingabe" type="time" name="ende_zeit" style="width:105px"
                 value="<?= Util::attr(date('H:i', strtotime((string) $event['ende']))) ?>" aria-label="Ende"></div></div>
      <div class="feld-reihe feld-reihe--2">
        <div class="feld"><label class="feld__label" for="e-kap">Plätze</label>
          <input class="eingabe" id="e-kap" type="number" name="kapazitaet"
                 value="<?= (int) $event['kapazitaet'] ?>" min="1"></div>
        <div class="feld"><label class="feld__label" for="e-preis">Preis</label>
          <div class="eingabe-gruppe">
            <input class="eingabe" id="e-preis" name="preis"
                   value="<?= Util::attr(number_format((int) $event['preis_cent'] / 100, 2, ',', '')) ?>">
            <span class="eingabe-gruppe__anhang"><?= Util::h(Tenant::waehrung()) ?></span></div></div>
      </div>
      <div class="feld"><label class="feld__label" for="e-ort">Ort (freier Text)</label>
        <input class="eingabe" id="e-ort" name="ort_text" value="<?= Util::attr((string) $event['ort_text']) ?>"
               placeholder="Mallorca, Golf Park Puntiró"></div>
      <div class="feld"><label class="feld__label" for="e-text">Beschreibung</label>
        <textarea class="eingabe" id="e-text" name="beschreibung" rows="5" data-waechst><?= Util::h((string) $event['beschreibung']) ?></textarea></div>
      <div class="feld"><label class="feld__label" for="e-status">Status</label>
        <select id="e-status" name="status">
          <?php foreach (['geplant' => 'Geplant (nicht sichtbar)', 'veroeffentlicht' => 'Veröffentlicht',
                          'laeuft' => 'Läuft', 'abgeschlossen' => 'Abgeschlossen',
                          'abgesagt' => 'Abgesagt'] as $k => $v): ?>
            <option value="<?= $k ?>"<?= (string) $event['status'] === $k ? ' selected' : '' ?>><?= Util::h($v) ?></option>
          <?php endforeach; ?>
        </select></div>
      <label class="haken"><input type="checkbox" name="warteliste" value="1"<?= (int) $event['warteliste'] === 1 ? ' checked' : '' ?>>
        <span class="haken__text">Warteliste führen
          <span class="haken__hinweis">Bei vollem Event können sich Kunden trotzdem eintragen und rücken automatisch nach.</span></span></label>
    </div>
    <?php if (Auth::darf('events.write')): ?>
      <div class="karte__fuss"><div class="fueller"></div>
        <button class="btn btn--primaer" type="submit">Speichern</button></div>
    <?php endif; ?>
  </form>
</div>

<?php if (Auth::darf('events.write')): ?>
<dialog class="modal modal--schmal" id="modal-anmelden">
  <form method="post">
    <?= Auth::csrfFeld() ?>
    <input type="hidden" name="aktion" value="anmelden">
    <div class="modal__kopf"><h2>Teilnehmer hinzufügen</h2>
      <button type="button" class="rundknopf" data-modal-zu aria-label="Schließen"><?= Icon::svg('x', 17) ?></button></div>
    <div class="modal__koerper">
      <div class="feld"><label class="feld__label" for="an-kunde">Kunde</label>
        <select id="an-kunde" name="customer_id" required>
          <?php foreach (Tenant::all('customers', 'status = "aktiv"', [], 'nachname') as $k): ?>
            <option value="<?= (int) $k['id'] ?>"><?= Util::h(Customers::name($k)) ?></option>
          <?php endforeach; ?>
        </select></div>
      <?php if ($frei <= 0): ?>
        <div class="hinweis hinweis--warnung">
          <?= Icon::svg('alert', 16) ?>
          <div class="hinweis__text">Das Event ist voll. Der Teilnehmer landet auf der Warteliste.</div>
        </div>
      <?php endif; ?>
    </div>
    <div class="modal__fuss"><div class="fueller"></div>
      <button type="button" class="btn" data-modal-zu>Abbrechen</button>
      <button type="submit" class="btn btn--primaer">Hinzufügen</button></div>
  </form>
</dialog>
<?php endif; ?>

<?php require __DIR__ . '/partials/fuss.php'; ?>
