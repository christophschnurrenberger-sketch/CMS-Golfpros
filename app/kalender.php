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

/*
 * Einen Termin an eine andere Stelle ziehen.
 *
 * Die Arbeit macht Bookings::umbuchen() - dieselbe Funktion wie auf der
 * Terminseite, mit derselben Kollisionspruefung. Hier steht nur die
 * Uebergabe und die Frage, ob der Kunde davon erfahren soll.
 *
 * Die Benachrichtigung ist der eigentliche Grund fuer den Dialog. Ein
 * Termin, der stillschweigend eine Stunde weiterwandert, ist der
 * zuverlaessigste Weg, jemanden umsonst auf den Platz fahren zu lassen.
 */
if (App::istPost() && App::aktion() === 'verschieben') {
    Auth::csrfFordern();
    Auth::fordern('bookings.write');

    $id      = App::postInt('id');
    $start   = Util::zeitpunkt(App::post('tag'), App::post('von'));
    $zurueck = '/app/kalender.php?ansicht=' . App::post('ansicht', 'woche')
             . '&datum=' . App::post('tag');

    $termin = $id > 0 ? Tenant::find('bookings', $id) : null;
    if ($termin === null) {
        App::melden('Dieser Termin wurde nicht gefunden.', 'fehler');
        App::weiter($zurueck);
    }
    if ($start === '') {
        App::melden('Die neue Zeit war nicht lesbar. Bitte noch einmal ziehen.', 'fehler');
        App::weiter($zurueck);
    }

    $alt = (string) $termin['start'];
    [$okay, $fehler] = Bookings::umbuchen($id, $start, App::postInt('trainer_id'));
    if (!$okay) {
        App::melden($fehler, 'fehler');
        App::weiter($zurueck);
    }

    $kundeId = (int) $termin['customer_id'];
    $kunde   = $kundeId > 0 ? Tenant::find('customers', $kundeId) : null;
    if (App::postBool('benachrichtigen') && $kunde !== null) {
        $ort = (int) $termin['location_id'] > 0 ? Tenant::find('locations', (int) $termin['location_id']) : null;
        Mail::anKunden($kunde, 'Dein Termin wurde verschoben',
            "Hallo " . (string) $kunde['vorname'] . ",\n\n"
            . "dein Termin hat eine neue Zeit:\n\n"
            . "bisher: " . Util::datumLang($alt) . ", " . Util::uhrzeit($alt) . " Uhr\n"
            . "neu:    " . Util::datumLang($start) . ", " . Util::uhrzeit($start) . " Uhr\n\n"
            . (string) $termin['titel'] . "\n"
            . ($ort !== null ? (string) $ort['name'] . "\n" : '')
            . "\nPasst die neue Zeit nicht, melde dich einfach - dann finden wir\n"
            . "eine andere.\n\n"
            . Tenant::name());
    }

    App::melden('Termin verschoben auf ' . Util::datum($start) . ', ' . Util::uhrzeit($start) . ' Uhr.'
              . ($kunde !== null && App::postBool('benachrichtigen') ? ' Der Kunde wurde benachrichtigt.' : ''));
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
  <?php /* Die Legende gehoert neben den Kalender, nicht unter ihn: Wer
           die Farben nachschlagen will, schaut nicht ans Seitenende. */ ?>
  <div class="farblegende">
    <?php foreach (['' => 'Einzeln', 'lila' => 'Gruppe', 'info' => 'Video', 'akzent' => 'Kurs'] as $k => $n): ?>
      <span class="farblegende__teil"><i class="farblegende__punkt<?= $k !== '' ? ' farblegende__punkt--' . $k : '' ?>"></i><?= Util::h($n) ?></span>
    <?php endforeach; ?>
  </div>
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
  <?php /* Die Wochentage stehen ueber dem Raster, nicht darin. Vorher
           hafteten sie am Fenster und schoben sich beim Scrollen ueber die
           erste Woche - dann stand „MO DI MI" mitten zwischen den Tagen. */ ?>
  <div class="kalender kalender--monat">
    <div class="kalender__kopf">
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
  <?php
  /*
   * Zeitleiste fuer Tag und Woche.
   *
   * Ein Rollbereich, nicht zwei. Vorher war die Kopfzeile am Fenster
   * festgemacht, das Gitter rollte in einem eigenen Kasten - beim
   * Scrollen liefen sie auseinander, und die Wochentage landeten mitten
   * im Gitter. Jetzt steckt beides im selben Raster, die Kopfzeile haftet
   * oben daran, die Zeitspalte links. Damit koennen sie gar nicht mehr
   * verrutschen.
   *
   * Die Spalten haben eine Mindestbreite. Sieben Tage auf einem Telefon
   * ergeben sonst 45 Pixel je Tag, und darauf steht von „Miriam Seidel"
   * noch „M". Lieber seitlich rollen und lesen koennen.
   */
  $stundeHoehe = 64;
  $spaltenMin  = $ansicht === 'tag' ? 0 : 132;
  $jetztMin    = (int) date('G') * 60 + (int) date('i');
  $zeigtHeute  = in_array(Util::heute(), $tage, true);
  ?>
  <div class="kalender kalender--zeit" style="--spalten:<?= count($tage) ?>;--stunde:<?= $stundeHoehe ?>px;--spalte-min:<?= $spaltenMin ?>px">
    <div class="kalender__gitter" data-von-stunde="<?= (int) $vonStunde ?>"
         data-stundenhoehe="<?= $stundeHoehe ?>" data-raster="15">

      <div class="kalender__ecke"></div>
      <?php foreach ($tage as $tag):
        $heute = $tag === Util::heute(); ?>
        <div class="kalender__tag-kopf<?= $heute ? ' ist-heute' : '' ?>">
          <span class="kalender__wochentag"><?= Util::h(Util::tagKurz($tag)) ?></span>
          <span class="kalender__tag-zahl"><?= (int) date('j', strtotime($tag)) ?></span>
        </div>
      <?php endforeach; ?>

      <?php /* Die Uhrzeit steht auf der Linie, nicht darunter - deshalb
               sitzt die Beschriftung leicht darueber. */ ?>
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

          <?php if ($heute && $jetztMin >= $vonStunde * 60 && $jetztMin <= $bisStunde * 60): ?>
            <?php /* Wo stehen wir gerade? Die Frage stellt sich beim Blick
                     auf den Kalender als erste. */ ?>
            <div class="kalender__jetzt" aria-hidden="true"
                 style="top:<?= round(($jetztMin - $vonStunde * 60) / 60 * $stundeHoehe, 1) ?>px">
              <span class="kalender__jetzt-zeit"><?= date('H:i') ?></span>
            </div>
          <?php endif; ?>

          <?php
          /*
           * Gleichzeitige Termine versetzt statt geteilt.
           *
           * Geteilt bekam jeder bei drei Parallelen ein Drittel der Breite
           * - auf einer Wochenspalte 40 Pixel, auf denen nichts mehr steht.
           * Versetzt behaelt jeder fast die volle Breite, liegt ein Stueck
           * weiter rechts und ueber dem vorigen. Man sieht, dass es mehrere
           * sind, und kann jeden einzeln treffen.
           */
          $desTages = array_values($nachTag[$tag] ?? []);
          $spalten  = Bookings::spalten($desTages);
          foreach ($desTages as $nr => $t):
            $startMin = (int) date('G', strtotime((string) $t['start'])) * 60
                      + (int) date('i', strtotime((string) $t['start']));
            $dauer = max(20, (strtotime((string) $t['ende']) - strtotime((string) $t['start'])) / 60);
            $oben  = ($startMin - $vonStunde * 60) / 60 * $stundeHoehe;
            $hoehe = $dauer / 60 * $stundeHoehe - 3;
            [$spur, $spurenGesamt] = $spalten[$nr] ?? [0, 1];
            /*
             * Parallele Termine liegen versetzt uebereinander, nicht
             * nebeneinander.
             *
             * Geteilt bekam jeder bei drei Parallelen ein Drittel der
             * Spalte - rund 40 Pixel, auf denen kein Name mehr steht.
             * Anteilig ueberlappt waren es immer noch zwei Drittel, und
             * „Miriam Seidel" wurde zu „Miriam …". Versetzt behaelt der
             * oberste die volle Breite und ist ganz zu lesen; die darunter
             * schauen links hervor, sind anklickbar und kommen beim
             * Daraufzeigen nach vorn.
             *
             * Das ist eine Entscheidung gegen Gleichbehandlung und fuer
             * Lesbarkeit: Drei halb lesbare Namen helfen niemandem, einer
             * ganzer und zwei erreichbare schon.
             *
             * In der Tagesansicht gilt das Gegenteil: Dort ist die Spalte
             * ueber tausend Pixel breit, geteilt bleibt jedem reichlich
             * Platz - und nebeneinander sieht man die Parallele sofort,
             * statt sie unter dem Nachbarn zu suchen.
             */
            $breit = $ansicht === 'tag';
            $spurBreite = 100 / $spurenGesamt;
            $versatz = $spur * 16;
            $service = $leistungen[(int) $t['service_id']] ?? null;
            $farbe = $farben[(string) ($service['art'] ?? 'einzel')] ?? '';
            $kunde = (int) $t['customer_id'] > 0 ? Customers::nameVonId((int) $t['customer_id']) : '';
            $verschiebbar = Auth::darf('bookings.write') && (string) $t['status'] !== 'abgesagt';
            ?>
            <a class="termin<?= $farbe !== '' ? ' termin--' . $farbe : '' ?><?= (string) $t['status'] === 'abgesagt' ? ' termin--abgesagt' : '' ?><?= $verschiebbar ? ' termin--ziehbar' : '' ?><?= $hoehe < 34 ? ' termin--flach' : '' ?>"
               style="top:<?= round($oben, 1) ?>px;height:<?= round($hoehe, 1) ?>px;<?php
                 if ($breit): ?>left:calc(<?= round($spur * $spurBreite, 4) ?>% + 3px);width:calc(<?= round($spurBreite, 4) ?>% - 6px);right:auto<?php
                 else: ?>left:<?= $versatz + 3 ?>px;right:3px;width:auto<?php
                 endif; ?>;--spur:<?= $spur ?>"
               <?php if ($verschiebbar): ?>
               data-verschiebbar
               data-id="<?= (int) $t['id'] ?>"
               data-dauer="<?= (int) $dauer ?>"
               data-tag="<?= Util::attr(substr((string) $t['start'], 0, 10)) ?>"
               data-von="<?= Util::attr(Util::uhrzeit((string) $t['start'])) ?>"
               data-wer="<?= Util::attr($kunde !== '' ? $kunde : (string) $t['titel']) ?>"
               data-hat-kunde="<?= (int) $t['customer_id'] > 0 ? '1' : '0' ?>"
               <?php endif; ?>
               <?php /* Kein title-Attribut: Der Browser blendet sonst seine
                        eigene Sprechblase ueber die Karte. */ ?>
               href="<?= Util::attr(App::url('/app/buchung.php?id=' . (int) $t['id'])) ?>">
              <span class="termin__kopf">
                <span class="termin__zeit"><?= Util::h(Util::uhrzeit((string) $t['start'])) ?></span>
                <span class="termin__name"><?= Util::h($kunde !== '' ? $kunde : (string) $t['titel']) ?></span>
              </span>
              <?php if ($hoehe >= 52): ?>
                <span class="termin__was"><?= Util::h((string) $t['titel']) ?></span>
              <?php endif; ?>

              <?php
              /*
               * Was auf der Flaeche keinen Platz hat, steht hier - fertig
               * gesetzt, aber versteckt. Beim Daraufzeigen hebt das Skript
               * genau diesen Block in eine schwebende Karte.
               *
               * Fertig statt als Daten: Preise, Datumsangaben und Namen
               * werden hier von denselben Helfern formatiert wie ueberall
               * sonst. Im Skript nachzubauen hiesse, dieselbe Formatierung
               * ein zweites Mal zu pflegen - und beim naechsten Sonderfall
               * weichen die beiden voneinander ab.
               */
              $ort     = (int) $t['location_id'] > 0 ? Tenant::find('locations', (int) $t['location_id']) : null;
              $trainer = (int) $t['trainer_id'] > 0 ? Auth::trainerName((int) $t['trainer_id']) : '';
              ?>
              <span class="termin__mehr" hidden>
                <span class="vorschau__kopf">
                  <span class="vorschau__zeit"><?= Util::h(Util::uhrzeit((string) $t['start'])) ?>–<?= Util::h(Util::uhrzeit((string) $t['ende'])) ?></span>
                  <span class="vorschau__tag"><?= Util::h(Util::datumLang((string) $t['start'])) ?></span>
                </span>
                <span class="vorschau__name"><?= Util::h($kunde !== '' ? $kunde : (string) $t['titel']) ?></span>
                <span class="vorschau__was"><?= Util::h((string) $t['titel']) ?><?php
                  if ((int) $t['teilnehmer'] > 1): ?> · <?= (int) $t['teilnehmer'] ?> Teilnehmer<?php endif; ?></span>

                <span class="vorschau__zeilen">
                  <?php if ($trainer !== ''): ?>
                    <span class="vorschau__zeile"><?= Icon::svg('customers', 14) ?><?= Util::h($trainer) ?></span>
                  <?php endif; ?>
                  <?php if ($ort !== null): ?>
                    <span class="vorschau__zeile"><?= Icon::svg('pin', 14) ?><?= Util::h((string) $ort['name']) ?></span>
                  <?php endif; ?>
                  <?php if ((int) $t['preis_cent'] > 0): ?>
                    <span class="vorschau__zeile"><?= Icon::svg('euro', 14) ?><?= Util::h(Util::geld((int) $t['preis_cent'])) ?>
                      <?php if ((int) $t['customer_package_id'] > 0): ?>
                        <em class="vorschau__merk">aus dem Paket</em>
                      <?php elseif ((int) $t['bezahlt'] === 1): ?>
                        <em class="vorschau__merk vorschau__merk--gut">bezahlt</em>
                      <?php else: ?>
                        <em class="vorschau__merk vorschau__merk--offen">offen</em>
                      <?php endif; ?></span>
                  <?php endif; ?>
                  <?php if (trim((string) $t['interne_notiz']) !== ''): ?>
                    <span class="vorschau__zeile vorschau__zeile--notiz"><?= Icon::svg('message', 14) ?><?= Util::h(Util::kuerzen((string) $t['interne_notiz'], 90)) ?></span>
                  <?php endif; ?>
                </span>

                <span class="vorschau__fuss">
                  <?= pille(Bookings::statusName((string) $t['status']), Bookings::statusFarbe((string) $t['status'])) ?>
                  <span class="vorschau__hinweis"><?= $verschiebbar ? 'Klicken zum Öffnen · Ziehen zum Verschieben' : 'Klicken zum Öffnen' ?></span>
                </span>
              </span>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <?php if ($ansicht === 'tag'): ?>
    <?php
    /*
     * Neben der Zeitleiste die Liste des Tages.
     *
     * Ein Tag auf 1200 Pixel Breite war vorher eine Spalte mit viel Luft
     * rechts daneben. Die Liste nutzt den Platz fuer das, was auf der
     * Flaeche keinen Platz hat: Leistung, Preis, Status - und laesst sich
     * auf dem Telefon einfach untereinander lesen.
     */
    $desTages = array_values($nachTag[$datum] ?? []);
    ?>
    <?php if ($desTages !== []): ?>
      <div class="karte mt-4">
        <div class="karte__kopf"><h2 class="karte__titel">Der Tag in der Reihe</h2>
          <span class="pille"><?= count($desTages) ?></span></div>
        <div class="tagesliste">
          <?php foreach ($desTages as $t):
            $service = $leistungen[(int) $t['service_id']] ?? null;
            $farbe = $farben[(string) ($service['art'] ?? 'einzel')] ?? '';
            $kunde = (int) $t['customer_id'] > 0 ? Customers::nameVonId((int) $t['customer_id']) : '';
            ?>
            <a class="tagesliste__zeile<?= (string) $t['status'] === 'abgesagt' ? ' ist-abgesagt' : '' ?>"
               href="<?= Util::attr(App::url('/app/buchung.php?id=' . (int) $t['id'])) ?>">
              <span class="tagesliste__zeit">
                <strong><?= Util::h(Util::uhrzeit((string) $t['start'])) ?></strong>
                <span><?= Util::h(Util::uhrzeit((string) $t['ende'])) ?></span>
              </span>
              <span class="tagesliste__strich termin--<?= Util::attr($farbe !== '' ? $farbe : 'marke') ?>"></span>
              <span class="tagesliste__wer">
                <strong><?= Util::h($kunde !== '' ? $kunde : (string) $t['titel']) ?></strong>
                <span class="tagesliste__was"><?= Util::h((string) $t['titel']) ?>
                  <?php if ((int) $t['preis_cent'] > 0): ?>
                    · <?= Util::h(Util::geldKurz((int) $t['preis_cent'])) ?>
                  <?php endif; ?></span>
              </span>
              <span class="tagesliste__status">
                <?= pille(Bookings::statusName((string) $t['status']),
                          Bookings::statusFarbe((string) $t['status'])) ?>
              </span>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>
  <?php endif; ?>

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

<?php if (Auth::darf('bookings.write')): ?>
<?php /*
 * Der Dialog nach dem Aufziehen.
 *
 * Absichtlich kurz: Leistung, Kunde, fertig. Wer im Kalender eine Luecke
 * markiert, will buchen und nicht ein Formular ausfuellen - alles Weitere
 * steht danach auf der Terminseite. Die Zeit steht oben als Text und in
 * versteckten Feldern; wer sie aendern will, zieht neu.
 */ ?>
<?php /*
 * Der Dialog nach dem Ziehen.
 *
 * Er fragt nicht „wirklich?" - das waere eine Ruecknahme des Ziehens und
 * nervt beim zehnten Mal. Er zeigt, was passieren wird, und stellt die
 * eine Frage, die man beim Verschieben wirklich beantworten muss: Weiss
 * der Kunde schon Bescheid?
 */ ?>
<dialog class="modal modal--schmal" id="modal-verschieben">
  <form method="post">
    <?= Auth::csrfFeld() ?>
    <input type="hidden" name="aktion" value="verschieben">
    <input type="hidden" name="ansicht" value="<?= Util::attr($ansicht) ?>">
    <input type="hidden" name="id"  id="vs-id">
    <input type="hidden" name="tag" id="vs-tag">
    <input type="hidden" name="von" id="vs-von">

    <div class="modal__kopf">
      <h2>Termin verschieben</h2>
      <button type="button" class="rundknopf" data-modal-zu aria-label="Schließen">
        <?= Icon::svg('x', 17) ?></button>
    </div>

    <div class="modal__koerper">
      <p class="verschieben__wer" id="vs-wer">–</p>
      <div class="verschieben__zeiten">
        <div class="verschieben__zeit verschieben__zeit--alt">
          <span class="verschieben__label">bisher</span>
          <span id="vs-alt">–</span>
        </div>
        <span class="verschieben__pfeil" aria-hidden="true"><?= Icon::svg('arrow-right', 18) ?></span>
        <div class="verschieben__zeit verschieben__zeit--neu">
          <span class="verschieben__label">neu</span>
          <span id="vs-neu">–</span>
        </div>
      </div>

      <?php if (count(Auth::trainer()) > 1): ?>
        <div class="feld mt-4">
          <label class="feld__label" for="vs-trainer">Trainer</label>
          <select id="vs-trainer" name="trainer_id">
            <option value="0">unverändert</option>
            <?php foreach (Auth::trainer() as $tr): ?>
              <option value="<?= (int) $tr['id'] ?>"><?= Util::h((string) $tr['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      <?php endif; ?>

      <label class="haken mt-4" id="vs-melden-zeile">
        <input type="checkbox" name="benachrichtigen" value="1" id="vs-melden" checked>
        <span class="haken__text">Kunde per E-Mail benachrichtigen
          <span class="haken__hinweis">Mit alter und neuer Zeit. Ohne das erfährt er es erst,
            wenn er zur alten Zeit dasteht.</span></span>
      </label>
    </div>

    <div class="modal__fuss">
      <button type="button" class="btn" data-modal-zu>Abbrechen</button>
      <div class="fueller"></div>
      <button class="btn btn--primaer" type="submit">Verschieben</button>
    </div>
  </form>
</dialog>

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
