<?php
/**
 * Kalender – Tag, Woche, Monat.
 *
 * Die Wochenansicht ist die Voreinstellung, weil ein Golf Professional in
 * Wochen plant: „Dienstagabend ist voll“ ist die Information, nicht „am
 * 17. um 18 Uhr“. Termine liegen absolut positioniert über einem
 * Stundenraster – so stimmen Länge und Überlappung optisch mit der
 * Wirklichkeit überein.
 */
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/partials/helfer.php';

Auth::fordern('modul.calendar');

/*
 * Aufgezogene Zeit wird hier gebucht, nicht auf der Terminseite.
 *
 * Die eigentliche Arbeit macht trotzdem Bookings::buchen() - dieselbe
 * Funktion wie im Formular, mit derselben Kollisionspruefung. Hier steht
 * nur die Uebergabe, damit der Pro nach dem Buchen im Kalender bleibt und
 * nicht auf einer anderen Seite landet.
 */
if (App::istPost() && App::aktion() === 'schnellbuchung') {
    Auth::csrfFordern();
    Auth::fordern('bookings.write');

    $start = Util::zeitpunkt(App::post('tag'), App::post('von'));
    $ende  = Util::zeitpunkt(App::post('tag'), App::post('bis'));
    $zurueck = '/app/kalender.php?ansicht=' . App::post('ansicht', 'woche')
             . '&datum=' . App::post('tag');

    if ($start === '' || $ende === '' || strtotime($ende) <= strtotime($start)) {
        App::melden('Die aufgezogene Zeit war nicht lesbar. Bitte noch einmal markieren.', 'fehler');
        App::weiter($zurueck);
    }

    $leistung = App::postInt('service_id') > 0 ? Tenant::find('services', App::postInt('service_id')) : null;
    [$neueId, $fehler] = Bookings::buchen([
        'service_id'  => App::postInt('service_id'),
        'customer_id' => App::postInt('customer_id'),
        'trainer_id'  => App::postInt('trainer_id') ?: Auth::id(),
        'location_id' => App::postInt('location_id'),
        'start'       => $start,
        'ende'        => $ende,
        'titel'       => App::post('titel') !== '' ? App::post('titel') : (string) ($leistung['name'] ?? 'Termin'),
        'interne_notiz' => App::post('interne_notiz'),
        'preis_cent'  => (int) ($leistung['preis_cent'] ?? 0),
        /* Der Pro zieht bewusst ueber eine Luecke - die Vorlauffrist der
           Online-Buchung gilt ihm nicht. Die Kollisionspruefung schon. */
    ]);

    if ($neueId > 0) {
        App::melden('Termin angelegt: ' . Util::datum($start) . ', '
                  . Util::uhrzeit($start) . '–' . Util::uhrzeit($ende) . ' Uhr.');
    } else {
        App::melden($fehler ?: 'Der Termin konnte nicht angelegt werden.', 'fehler');
    }
    App::weiter($zurueck);
}

$ansicht   = App::get('ansicht', 'woche');
$datum     = App::get('datum', Util::heute());
$trainerId = App::getInt('trainer', Auth::rolle() === 'trainer' ? Auth::id() : 0);
$ortId     = App::getInt('ort');

$zeiger = strtotime($datum) ?: time();

switch ($ansicht) {
    case 'tag':
        $von = date('Y-m-d', $zeiger);
        $bis = $von;
        $tage = [$von];
        $zurueck = date('Y-m-d', $zeiger - 86400);
        $vor     = date('Y-m-d', $zeiger + 86400);
        $ueberschrift = Util::datumLang($von);
        break;
    case 'monat':
        $von = date('Y-m-01', $zeiger);
        $bis = date('Y-m-t', $zeiger);
        $zurueck = date('Y-m-d', strtotime('-1 month', $zeiger));
        $vor     = date('Y-m-d', strtotime('+1 month', $zeiger));
        $ueberschrift = Util::monatName((int) date('n', $zeiger)) . ' ' . date('Y', $zeiger);
        break;
    default:
        $ansicht = 'woche';
        $montag = strtotime('monday this week', $zeiger);
        $von = date('Y-m-d', $montag);
        $bis = date('Y-m-d', $montag + 6 * 86400);
        $tage = [];
        for ($i = 0; $i < 7; $i++) {
            $tage[] = date('Y-m-d', $montag + $i * 86400);
        }
        $zurueck = date('Y-m-d', $montag - 7 * 86400);
        $vor     = date('Y-m-d', $montag + 7 * 86400);
        $ueberschrift = 'KW ' . date('W', $montag) . ' · '
                      . Util::datum($von, false) . ' – ' . Util::datum($bis);
}

$filter = [];
if ($trainerId > 0) { $filter['trainer'] = $trainerId; }
if ($ortId > 0)     { $filter['location'] = $ortId; }
$termine = Bookings::zeitraum($von . ' 00:00:00', $bis . ' 23:59:59', $filter + ['mit_abgesagt' => true]);

/* Termine nach Tag bündeln */
$nachTag = [];
foreach ($termine as $t) {
    $nachTag[date('Y-m-d', strtotime((string) $t['start']))][] = $t;
}

/* Sichtbarer Stundenbereich aus den Arbeitszeiten ableiten */
$vonMin = 480;
$bisMin = 1260;
$fenster = Tenant::all('availability', 'aktiv = 1' . ($trainerId > 0 ? ' AND user_id = :u' : ''),
    $trainerId > 0 ? ['u' => $trainerId] : []);
if ($fenster !== []) {
    $vonMin = min(array_map(static fn($f) => (int) $f['von_min'], $fenster));
    $bisMin = max(array_map(static fn($f) => (int) $f['bis_min'], $fenster));
}
foreach ($termine as $t) {
    $s = (int) date('G', strtotime((string) $t['start'])) * 60;
    $e = (int) date('G', strtotime((string) $t['ende'])) * 60 + 60;
    $vonMin = min($vonMin, $s);
    $bisMin = max($bisMin, $e);
}
$vonStunde = max(0, intdiv($vonMin, 60) - 1);
$bisStunde = min(24, (int) ceil($bisMin / 60) + 1);

$farben = ['einzel' => '', 'gruppe' => 'lila', 'kurs' => 'akzent', 'video' => 'info',
           'workshop' => 'warnung', 'event' => 'warnung', 'online' => 'info', 'camp' => 'akzent'];
$leistungen = Tenant::nachId('services');

$titel = 'Kalender';
$unter = $ueberschrift . ' · ' . count($termine) . ' Termin' . (count($termine) === 1 ? '' : 'e');
$basisUrl = '/app/kalender.php?ansicht=' . $ansicht . '&trainer=' . $trainerId . '&ort=' . $ortId . '&datum=';

$aktionen = '<div class="segment">'
  . '<a class="segment__teil' . ($ansicht === 'tag' ? ' ist-aktiv' : '') . '" href="'
  . App::url('/app/kalender.php?ansicht=tag&datum=' . $datum . '&trainer=' . $trainerId) . '">Tag</a>'
  . '<a class="segment__teil' . ($ansicht === 'woche' ? ' ist-aktiv' : '') . '" href="'
  . App::url('/app/kalender.php?ansicht=woche&datum=' . $datum . '&trainer=' . $trainerId) . '">Woche</a>'
  . '<a class="segment__teil' . ($ansicht === 'monat' ? ' ist-aktiv' : '') . '" href="'
  . App::url('/app/kalender.php?ansicht=monat&datum=' . $datum . '&trainer=' . $trainerId) . '">Monat</a>'
  . '</div>'
  . (Auth::darf('bookings.write')
     ? '<a class="btn btn--primaer" href="' . App::url('/app/buchung.php?id=neu') . '">'
       . Icon::svg('plus', 15) . ' Termin</a>' : '');

$inhaltKlasse = 'inhalt--breit';
require __DIR__ . '/partials/kopf.php';
?>

<div class="reihe reihe--umbruch mb-4">
  <div class="btn-gruppe">
    <a class="btn btn--nur-symbol" href="<?= Util::attr(App::url($basisUrl . $zurueck)) ?>"
       aria-label="Zurück"><?= Icon::svg('chevron-left', 16) ?></a>
    <a class="btn" href="<?= Util::attr(App::url($basisUrl . Util::heute())) ?>">Heute</a>
    <a class="btn btn--nur-symbol" href="<?= Util::attr(App::url($basisUrl . $vor)) ?>"
       aria-label="Vor"><?= Icon::svg('chevron-right', 16) ?></a>
  </div>
  <h2 style="font-size:15px"><?= Util::h($ueberschrift) ?></h2>
  <div class="fueller"></div>
  <form method="get" class="reihe reihe--eng">
    <input type="hidden" name="ansicht" value="<?= Util::attr($ansicht) ?>">
    <input type="hidden" name="datum" value="<?= Util::attr($datum) ?>">
    <?php if (count(Auth::trainer()) > 1): ?>
      <select name="trainer" data-auto-absenden aria-label="Trainer">
        <option value="0">Alle Trainer</option>
        <?php foreach (Auth::trainer() as $t): ?>
          <option value="<?= (int) $t['id'] ?>"<?= $trainerId === (int) $t['id'] ? ' selected' : '' ?>>
            <?= Util::h((string) $t['name']) ?></option>
        <?php endforeach; ?>
      </select>
    <?php endif; ?>
    <?php $orte = Tenant::all('locations', 'aktiv = 1', [], 'name');
    if (count($orte) > 1): ?>
      <select name="ort" data-auto-absenden aria-label="Standort">
        <option value="0">Alle Standorte</option>
        <?php foreach ($orte as $o): ?>
          <option value="<?= (int) $o['id'] ?>"<?= $ortId === (int) $o['id'] ? ' selected' : '' ?>>
            <?= Util::h((string) $o['name']) ?></option>
        <?php endforeach; ?>
      </select>
    <?php endif; ?>
  </form>
</div>

<?php if ($ansicht === 'monat'): ?>
  <?php
  $ersterTag = strtotime(date('Y-m-01', $zeiger));
  $startTag  = strtotime('monday this week', $ersterTag);
  if (date('N', $ersterTag) === '1') { $startTag = $ersterTag; }
  ?>
  <div class="kalender">
    <div class="kalender__kopf" style="grid-template-columns:repeat(7,minmax(0,1fr))">
      <?php foreach (['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'] as $wt): ?>
        <div class="kalender__tag-kopf"><span class="kalender__wochentag"><?= $wt ?></span></div>
      <?php endforeach; ?>
    </div>
    <div class="monat">
      <?php for ($i = 0; $i < 42; $i++):
        $tag = date('Y-m-d', $startTag + $i * 86400);
        if ($i >= 35 && date('m', strtotime($tag)) !== date('m', $zeiger)) { break; }
        $fremd = date('m', strtotime($tag)) !== date('m', $zeiger);
        $heute = $tag === Util::heute();
        $liste = $nachTag[$tag] ?? []; ?>
        <div class="monat__tag<?= $fremd ? ' ist-fremd' : '' ?><?= $heute ? ' ist-heute' : '' ?>">
          <div class="monat__zahl"><?= (int) date('j', strtotime($tag)) ?></div>
          <?php foreach (array_slice($liste, 0, 3) as $t):
            $service = $leistungen[(int) $t['service_id']] ?? null;
            $farbe = $farben[(string) ($service['art'] ?? 'einzel')] ?? ''; ?>
            <a class="termin termin--kompakt<?= $farbe !== '' ? ' termin--' . $farbe : '' ?><?= (string) $t['status'] === 'abgesagt' ? ' termin--abgesagt' : '' ?>"
               style="position:static" href="<?= Util::attr(App::url('/app/buchung.php?id=' . (int) $t['id'])) ?>">
              <span class="termin__titel"><?= Util::h(Util::uhrzeit((string) $t['start'])) ?>
                <?= Util::h(Util::kuerzen((string) ($t['customer_id'] ? Customers::nameVonId((int) $t['customer_id']) : $t['titel']), 16)) ?></span>
            </a>
          <?php endforeach; ?>
          <?php if (count($liste) > 3): ?>
            <a class="monat__mehr" href="<?= Util::attr(App::url('/app/kalender.php?ansicht=tag&datum=' . $tag)) ?>">
              +<?= count($liste) - 3 ?> weitere</a>
          <?php endif; ?>
        </div>
      <?php endfor; ?>
    </div>
  </div>

<?php else: ?>
  <?php $spalten = count($tage); ?>
  <div class="kalender" style="--spalten:<?= $spalten ?>">
    <div class="kalender__kopf">
      <div class="kalender__tag-kopf"></div>
      <?php foreach ($tage as $tag):
        $heute = $tag === Util::heute(); ?>
        <div class="kalender__tag-kopf<?= $heute ? ' ist-heute' : '' ?>">
          <span class="kalender__wochentag"><?= Util::h(Util::tagKurz($tag)) ?></span>
          <span class="kalender__tag-zahl"><?= (int) date('j', strtotime($tag)) ?></span>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="kalender__gitter" data-von-stunde="<?= (int) $vonStunde ?>"
         data-stundenhoehe="52" data-raster="15">
      <div class="kalender__stunden">
        <?php for ($h = $vonStunde; $h < $bisStunde; $h++): ?>
          <div class="kalender__stunde"><span><?= sprintf('%02d:00', $h) ?></span></div>
        <?php endfor; ?>
      </div>
      <?php foreach ($tage as $tag):
        $heute = $tag === Util::heute(); ?>
        <?php /* data-tag und die Eckstunden sagen dem Skript, welche Zeit
                 an welcher Stelle liegt - sonst muesste es rechnen, was
                 hier ohnehin schon feststeht. */ ?>
        <div class="kalender__spalte<?= $heute ? ' ist-heute' : '' ?>"
             data-tag="<?= Util::attr($tag) ?>"
             <?= Auth::darf('bookings.write') ? 'data-aufziehbar' : '' ?>>
          <?php for ($h = $vonStunde; $h < $bisStunde; $h++): ?>
            <div class="kalender__zelle"></div>
          <?php endfor; ?>
          <?php
          /*
           * Gleichzeitige Termine nebeneinander statt uebereinander. Ohne
           * das verdeckt der obere den unteren - und ausgerechnet eine
           * Doppelbuchung, die man sehen muss, waere unsichtbar.
           */
          $desTages = array_values($nachTag[$tag] ?? []);
          $spalten  = Bookings::spalten($desTages);
          foreach ($desTages as $nr => $t):
            $startMin = (int) date('G', strtotime((string) $t['start'])) * 60
                      + (int) date('i', strtotime((string) $t['start']));
            $dauer = max(20, (strtotime((string) $t['ende']) - strtotime((string) $t['start'])) / 60);
            $oben  = ($startMin - $vonStunde * 60) / 60 * 52;
            $hoehe = $dauer / 60 * 52 - 3;
            [$spur, $spurenGesamt] = $spalten[$nr] ?? [0, 1];
            $breite = 100 / $spurenGesamt;
            $service = $leistungen[(int) $t['service_id']] ?? null;
            $farbe = $farben[(string) ($service['art'] ?? 'einzel')] ?? '';
            $kunde = (int) $t['customer_id'] > 0 ? Customers::nameVonId((int) $t['customer_id']) : ''; ?>
            <a class="termin<?= $farbe !== '' ? ' termin--' . $farbe : '' ?><?= (string) $t['status'] === 'abgesagt' ? ' termin--abgesagt' : '' ?>"
               style="top:<?= round($oben, 1) ?>px;height:<?= round($hoehe, 1) ?>px;left:calc(<?= round($spur * $breite, 4) ?>% + 3px);width:calc(<?= round($breite, 4) ?>% - 6px);right:auto"
               href="<?= Util::attr(App::url('/app/buchung.php?id=' . (int) $t['id'])) ?>"
               title="<?= Util::attr(Util::uhrzeit((string) $t['start']) . '–' . Util::uhrzeit((string) $t['ende'])
                       . ' · ' . $t['titel'] . ($kunde !== '' ? ' · ' . $kunde : '')) ?>">
              <span class="termin__zeit"><?= Util::h(Util::uhrzeit((string) $t['start'])) ?></span>
              <span class="termin__titel"><?= Util::h($kunde !== '' ? $kunde : (string) $t['titel']) ?></span>
              <?php if ($hoehe > 44 && $spurenGesamt < 3): ?>
                <span class="termin__zeit"><?= Util::h(Util::kuerzen((string) $t['titel'], 28)) ?></span>
              <?php endif; ?>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <?php if ($termine === []): ?>
    <div class="karte mt-4"><div class="karte__koerper">
      <?= leerzustand('calendar', 'Kein Termin in diesem Zeitraum',
            'Freie Zeiten füllen sich am schnellsten über die Online-Buchung auf deiner Website – '
            . 'oder mit einer kurzen Nachricht an Kunden, die lange nicht da waren.',
            Auth::darf('bookings.write')
              ? [['Termin anlegen', '/app/buchung.php?id=neu', 'plus'],
                 ['Inaktive Kunden ansehen', '/app/kunden.php?filter=inaktiv', 'customers']]
              : []) ?>
    </div></div>
  <?php endif; ?>
<?php endif; ?>

<div class="reihe reihe--umbruch mt-4 klein gedimmt">
  <span class="legende__teil"><span class="legende__farbe" style="background:var(--marke)"></span> Einzeltraining</span>
  <span class="legende__teil"><span class="legende__farbe" style="background:var(--lila)"></span> Gruppe</span>
  <span class="legende__teil"><span class="legende__farbe" style="background:var(--info)"></span> Videoanalyse</span>
  <span class="legende__teil"><span class="legende__farbe" style="background:var(--akzent)"></span> Kurs</span>
</div>

<?php if (Auth::darf('bookings.write')): ?>
<?php /*
 * Der Dialog nach dem Aufziehen.
 *
 * Absichtlich kurz: Leistung, Kunde, fertig. Wer im Kalender eine Luecke
 * markiert, will buchen und nicht ein Formular ausfuellen - alles Weitere
 * steht danach auf der Terminseite. Die Zeit steht oben als Text und in
 * versteckten Feldern; wer sie aendern will, zieht neu.
 */ ?>
<dialog class="modal" id="modal-schnellbuchung">
  <form method="post">
    <?= Auth::csrfFeld() ?>
    <input type="hidden" name="aktion" value="schnellbuchung">
    <input type="hidden" name="ansicht" value="<?= Util::attr($ansicht) ?>">
    <input type="hidden" name="tag" id="sb-tag">
    <input type="hidden" name="von" id="sb-von">
    <input type="hidden" name="bis" id="sb-bis">

    <div class="modal__kopf">
      <h2>Termin anlegen</h2>
      <button type="button" class="rundknopf" data-modal-zu aria-label="Schließen">
        <?= Icon::svg('x', 17) ?></button>
    </div>

    <div class="modal__koerper">
      <div class="hinweis hinweis--still mb-4">
        <?= Icon::svg('calendar', 17) ?>
        <div class="hinweis__text" id="sb-zeit">–</div>
      </div>

      <div class="feld">
        <label class="feld__label" for="sb-service">Leistung</label>
        <select id="sb-service" name="service_id">
          <?php foreach (Tenant::all('services', 'aktiv = 1', [], 'position, name') as $s): ?>
            <option value="<?= (int) $s['id'] ?>"
                    data-preis="<?= Util::attr(Util::geldKurz((int) $s['preis_cent'])) ?>">
              <?= Util::h((string) $s['name']) ?> · <?= Util::h(Util::geldKurz((int) $s['preis_cent'])) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="feld">
        <label class="feld__label" for="sb-kunde">Kunde</label>
        <select id="sb-kunde" name="customer_id">
          <option value="0">Ohne Kunde (Blocker, interner Termin)</option>
          <?php foreach (Tenant::all('customers', "status = 'aktiv'", [], 'nachname, vorname') as $k): ?>
            <option value="<?= (int) $k['id'] ?>"><?= Util::h(Customers::name($k)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="feld-reihe feld-reihe--2">
        <div class="feld">
          <label class="feld__label" for="sb-trainer">Trainer</label>
          <select id="sb-trainer" name="trainer_id">
            <?php foreach (Auth::trainer() as $tr): ?>
              <option value="<?= (int) $tr['id'] ?>"<?= (int) $tr['id'] === Auth::id() ? ' selected' : '' ?>>
                <?= Util::h((string) $tr['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="feld">
          <label class="feld__label" for="sb-ort">Ort</label>
          <select id="sb-ort" name="location_id">
            <option value="0">—</option>
            <?php foreach (Tenant::all('locations', 'aktiv = 1', [], 'name') as $o): ?>
              <option value="<?= (int) $o['id'] ?>"><?= Util::h((string) $o['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="feld">
        <label class="feld__label" for="sb-titel">Titel</label>
        <input class="eingabe" id="sb-titel" name="titel"
               placeholder="leer lassen – dann steht die Leistung drin">
      </div>
    </div>

    <div class="modal__fuss">
      <div class="fueller"></div>
      <button type="button" class="btn" data-modal-zu>Abbrechen</button>
      <button class="btn btn--primaer" type="submit">Termin anlegen</button>
    </div>
  </form>
</dialog>
<?php endif; ?>

<?php require __DIR__ . '/partials/fuss.php'; ?>
