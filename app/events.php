<?php
/** Events: Workshops, Camps, Turniere, Golfreisen. */
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/partials/helfer.php';

Auth::fordern('modul.events');

if (App::istPost()) {
    Auth::csrfFordern();
    Auth::fordern('events.write');
    if (App::aktion() === 'neu') {
        $id = Events::speichern([
            'titel' => App::post('titel'), 'art' => App::post('art', 'workshop'),
            'start' => App::post('start') . ' ' . App::post('start_zeit', '09:00') . ':00',
            'ende' => (App::post('ende') ?: App::post('start')) . ' ' . App::post('ende_zeit', '17:00') . ':00',
            'kapazitaet' => max(1, App::postInt('kapazitaet', 12)),
            'preis_cent' => Util::centAus(App::post('preis')),
            'beschreibung' => App::post('beschreibung'),
            'location_id' => App::postInt('location_id'),
            'trainer_id' => App::postInt('trainer_id', Auth::id()),
            'status' => 'geplant',
        ]);
        App::melden('Event angelegt.');
        App::weiter('/app/event.php?id=' . $id);
    }
}

$zeit = App::get('zeit', 'kommend');
$wo = $zeit === 'vergangen' ? 'start < :jetzt' : 'start >= :jetzt';
$events = Tenant::all('events', $wo, ['jetzt' => Util::jetzt()],
    $zeit === 'vergangen' ? 'start DESC' : 'start');

$titel = 'Events';
$unter = Tenant::count('events', 'start >= :jetzt', ['jetzt' => Util::jetzt()]) . ' kommende Termine · '
       . Tenant::count('event_registrations', 'status = "angemeldet"') . ' Anmeldungen';
$aktionen = Auth::darf('events.write')
    ? '<button class="btn btn--primaer" data-modal-auf="modal-event">' . Icon::svg('plus', 15) . ' Event anlegen</button>' : '';
require __DIR__ . '/partials/kopf.php';
?>

<div class="reiter">
  <a class="reiter__teil<?= $zeit === 'kommend' ? ' ist-aktiv' : '' ?>"
     href="<?= Util::attr(App::url('/app/events.php')) ?>">Kommende</a>
  <a class="reiter__teil<?= $zeit === 'vergangen' ? ' ist-aktiv' : '' ?>"
     href="<?= Util::attr(App::url('/app/events.php?zeit=vergangen')) ?>">Vergangene</a>
</div>

<?php if ($events === []): ?>
  <div class="karte"><div class="karte__koerper">
    <?= leerzustand('events', 'Keine Events in dieser Auswahl',
          'Ein Workshop mit acht Teilnehmern bringt an einem Vormittag so viel wie acht '
          . 'Einzelstunden – und die Teilnehmer buchen danach häufig weiter. Kapazität, '
          . 'Warteliste, Bezahlung und Check-in sind eingebaut.',
          Auth::darf('events.write') ? [] : []) ?>
  </div></div>
<?php else: ?>
  <div class="raster raster--auto-gross">
    <?php foreach ($events as $e):
      $belegt = Events::belegt((int) $e['id']);
      $frei = Events::freiePlaetze($e);
      $warteliste = count(Events::anmeldungen((int) $e['id'], 'warteliste'));
      $quote = (int) $e['kapazitaet'] > 0 ? (int) round($belegt / (int) $e['kapazitaet'] * 100) : 0; ?>
      <a class="karte" href="<?= Util::attr(App::url('/app/event.php?id=' . (int) $e['id'])) ?>"
         style="display:block;color:inherit">
        <div class="karte__koerper">
          <div class="reihe reihe--eng mb-3">
            <?= pille(Events::ARTEN[(string) $e['art']][0] ?? (string) $e['art'], 'offen') ?>
            <?= pille((string) $e['status'], (string) $e['status'] === 'veroeffentlicht' ? 'erfolg' : '') ?>
            <div class="fueller"></div>
            <span class="halbfett"><?= Util::h(Util::geldKurz((int) $e['preis_cent'])) ?></span>
          </div>
          <h3 class="mb-2"><?= Util::h((string) $e['titel']) ?></h3>
          <div class="klein gedimmt reihe reihe--eng">
            <?= Icon::svg('calendar', 14) ?>
            <?= Util::h(Util::datumLang((string) $e['start'])) ?>
          </div>
          <?php if ((string) ($e['ort_text'] ?: Events::ortName((int) $e['location_id'])) !== ''): ?>
            <div class="klein gedimmt reihe reihe--eng mt-2">
              <?= Icon::svg('pin', 14) ?>
              <?= Util::h((string) ($e['ort_text'] ?: Events::ortName((int) $e['location_id']))) ?>
            </div>
          <?php endif; ?>
          <div class="mt-4">
            <div class="reihe klein mb-2">
              <span class="gedimmt"><?= $belegt ?> von <?= (int) $e['kapazitaet'] ?> Plätzen</span>
              <div class="fueller"></div>
              <?php if ($warteliste > 0): ?>
                <span class="pille pille--warnung"><?= $warteliste ?> auf Warteliste</span>
              <?php elseif ($frei === 0): ?>
                <?= pille('ausgebucht', 'gefahr') ?>
              <?php else: ?>
                <span class="gedimmt"><?= $frei ?> frei</span>
              <?php endif; ?>
            </div>
            <?= balken($quote, $quote >= 100 ? 'gefahr' : ($quote >= 80 ? 'warnung' : '')) ?>
          </div>
        </div>
        <div class="karte__fuss">
          <span class="klein gedimmt">Umsatz <?= Util::h(Util::geldKurz(Events::umsatz((int) $e['id']))) ?></span>
          <div class="fueller"></div>
          <?= Icon::svg('chevron-right', 15) ?>
        </div>
      </a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if (Auth::darf('events.write')): ?>
<dialog class="modal" id="modal-event">
  <form method="post">
    <?= Auth::csrfFeld() ?>
    <input type="hidden" name="aktion" value="neu">
    <div class="modal__kopf"><h2>Event anlegen</h2>
      <button type="button" class="rundknopf" data-modal-zu aria-label="Schließen"><?= Icon::svg('x', 17) ?></button></div>
    <div class="modal__koerper">
      <div class="feld-reihe feld-reihe--2">
        <div class="feld"><label class="feld__label" for="ev-titel">Titel</label>
          <input class="eingabe" id="ev-titel" name="titel" required autofocus
                 placeholder="Kurzspiel-Workshop"></div>
        <div class="feld"><label class="feld__label" for="ev-art">Art</label>
          <select id="ev-art" name="art">
            <?php foreach (Events::ARTEN as $k => [$n, $i]): ?>
              <option value="<?= $k ?>"><?= Util::h($n) ?></option>
            <?php endforeach; ?>
          </select></div>
      </div>
      <div class="feld-reihe feld-reihe--2">
        <div class="feld"><label class="feld__label" for="ev-start">Beginn</label>
          <div class="reihe reihe--eng">
            <input class="eingabe" id="ev-start" type="date" name="start" required
                   value="<?= Util::attr(date('Y-m-d', strtotime('+14 days'))) ?>">
            <input class="eingabe" type="time" name="start_zeit" value="09:00" style="width:110px"
                   aria-label="Beginn Uhrzeit">
          </div></div>
        <div class="feld"><label class="feld__label" for="ev-ende">Ende</label>
          <div class="reihe reihe--eng">
            <input class="eingabe" id="ev-ende" type="date" name="ende">
            <input class="eingabe" type="time" name="ende_zeit" value="17:00" style="width:110px"
                   aria-label="Ende Uhrzeit">
          </div></div>
      </div>
      <div class="feld-reihe feld-reihe--2">
        <div class="feld"><label class="feld__label" for="ev-kap">Plätze</label>
          <input class="eingabe" id="ev-kap" type="number" name="kapazitaet" value="12" min="1"></div>
        <div class="feld"><label class="feld__label" for="ev-preis">Preis je Person</label>
          <div class="eingabe-gruppe">
            <input class="eingabe" id="ev-preis" name="preis" value="89,00">
            <span class="eingabe-gruppe__anhang"><?= Util::h(Tenant::waehrung()) ?></span></div></div>
      </div>
      <div class="feld"><label class="feld__label" for="ev-text">Beschreibung</label>
        <textarea class="eingabe" id="ev-text" name="beschreibung" rows="4" data-waechst
                  placeholder="Was passiert an diesem Tag? Was ist enthalten, was mitzubringen?"></textarea></div>
      <?php $orte = Tenant::all('locations', 'aktiv = 1', [], 'name');
      if (count($orte) > 1): ?>
        <div class="feld"><label class="feld__label" for="ev-ort">Standort</label>
          <select id="ev-ort" name="location_id">
            <?php foreach ($orte as $o): ?>
              <option value="<?= (int) $o['id'] ?>"><?= Util::h((string) $o['name']) ?></option>
            <?php endforeach; ?>
          </select></div>
      <?php endif; ?>
    </div>
    <div class="modal__fuss"><div class="fueller"></div>
      <button type="button" class="btn" data-modal-zu>Abbrechen</button>
      <button type="submit" class="btn btn--primaer">Anlegen</button></div>
  </form>
</dialog>
<?php endif; ?>

<?php require __DIR__ . '/partials/fuss.php'; ?>
