<?php
/**
 * Kundenakte – die wichtigste Einzelseite des Systems.
 *
 * Alles, was über einen Kunden bekannt ist, an einem Ort: Stammdaten,
 * Handicap-Entwicklung, Termine, Pakete, Zahlungen, Trainingspläne, Videos,
 * Kurse, Nachrichten und Notizen. Auf Reiter verteilt, weil sonst niemand
 * die Seite überblickt – aber ohne Nachladen, damit das Blättern nichts
 * kostet.
 */
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/partials/helfer.php';

Auth::fordern('modul.customers');

$istNeu = App::get('id') === 'neu';
$id     = $istNeu ? 0 : App::getInt('id');
$kunde  = $istNeu ? null : Tenant::find('customers', $id);

if (!$istNeu && !$kunde) {
    App::melden('Dieser Kunde wurde nicht gefunden.', 'fehler');
    App::weiter('/app/kunden.php');
}

/* ------------------------------------------------------------ Aktionen */

if (App::istPost()) {
    Auth::csrfFordern();
    Auth::fordern('customers.write');
    $aktion = App::aktion();

    if ($aktion === 'speichern') {
        $daten = [
            'vorname' => App::post('vorname'), 'nachname' => App::post('nachname'),
            'email' => strtolower(App::post('email')), 'telefon' => App::post('telefon'),
            'geburtstag' => App::post('geburtstag'), 'strasse' => App::post('strasse'),
            'plz' => App::post('plz'), 'ort' => App::post('ort'),
            'hcp' => App::post('hcp'), 'heimclub' => App::post('heimclub'),
            'ziele' => App::post('ziele'), 'dominante_hand' => App::post('dominante_hand', 'rechts'),
            'status' => App::post('status', 'aktiv'), 'quelle' => App::post('quelle'),
            'trainer_id' => App::postInt('trainer_id'), 'notiz' => App::post('notiz'),
            'newsletter' => App::postBool('newsletter') ? 1 : 0,
        ];
        $felder = [];
        foreach (Tenant::all('custom_fields', 'objekt = "customer"', [], 'position') as $cf) {
            $felder[(string) $cf['schluessel']] = App::post('feld_' . $cf['schluessel']);
        }
        $daten['felder'] = Util::json($felder);

        if ($daten['vorname'] === '' && $daten['nachname'] === '') {
            App::melden('Bitte mindestens einen Namen angeben.', 'fehler');
        } else {
            $neueId = Customers::speichern($daten, $id);
            Customers::tagsSetzen($neueId, array_map('trim', explode(',', App::post('tags'))));
            Customers::scoreSpeichern($neueId);
            App::melden($id > 0 ? 'Kunde gespeichert.' : 'Kunde angelegt.');
            App::weiter('/app/kunde.php?id=' . $neueId);
        }
    }

    if ($aktion === 'notiz' && $id > 0) {
        $text = App::post('text');
        if ($text !== '') {
            Tenant::insert('customer_notes', [
                'customer_id' => $id, 'user_id' => Auth::id(), 'text' => $text,
            ]);
            App::melden('Notiz gespeichert.');
        }
        App::weiter('/app/kunde.php?id=' . $id . '#notizen');
    }

    if ($aktion === 'notiz_loeschen' && $id > 0) {
        Tenant::deleteWhere('customer_notes', 'id = :n AND customer_id = :k',
            ['n' => App::postInt('notiz_id'), 'k' => $id]);
        App::weiter('/app/kunde.php?id=' . $id . '#notizen');
    }

    if ($aktion === 'leistung' && $id > 0) {
        Training::leistungSpeichern($id, $_POST);
        App::melden('Runde erfasst.');
        App::weiter('/app/kunde.php?id=' . $id . '#leistung');
    }

    if ($aktion === 'paket_gutschreiben' && $id > 0) {
        Auth::fordern('bookings.write');
        Commerce::paketGutschreiben($id, App::postInt('package_id'));
        App::melden('Paket gutgeschrieben.');
        App::weiter('/app/kunde.php?id=' . $id . '#finanzen');
    }

    if ($aktion === 'loeschen' && $id > 0) {
        Auth::fordern('customers.delete');
        Customers::loeschen($id);
        App::melden('Der Kunde wurde vollständig gelöscht. Bezahlte Rechnungen bleiben aus '
                  . 'handelsrechtlichen Gründen bestehen, ohne Bezug zur Person.', 'info');
        App::weiter('/app/kunden.php');
    }
}

/* ------------------------------------------------------------- Anlegen */

if ($istNeu) {
    $titel = 'Neuer Kunde';
    $unter = 'Nur Name und E-Mail sind nötig – alles Weitere kann später dazukommen.';
    $brotkrumen = [['Kunden', '/app/kunden.php'], ['Neuer Kunde', null]];
    require __DIR__ . '/partials/kopf.php';
    ?>
    <form method="post" class="karte" style="max-width:780px">
      <?= Auth::csrfFeld() ?>
      <input type="hidden" name="aktion" value="speichern">
      <div class="karte__koerper">
        <div class="feld-reihe feld-reihe--2">
          <div class="feld">
            <label class="feld__label" for="vorname">Vorname</label>
            <input class="eingabe" id="vorname" name="vorname" autofocus required>
          </div>
          <div class="feld">
            <label class="feld__label" for="nachname">Nachname</label>
            <input class="eingabe" id="nachname" name="nachname">
          </div>
        </div>
        <div class="feld-reihe feld-reihe--2">
          <div class="feld">
            <label class="feld__label" for="email">E-Mail</label>
            <input class="eingabe" id="email" type="email" name="email">
            <div class="feld__hinweis">Nötig für Terminerinnerungen und den Kundenzugang.</div>
          </div>
          <div class="feld">
            <label class="feld__label" for="telefon">Telefon</label>
            <input class="eingabe" id="telefon" type="tel" name="telefon">
          </div>
        </div>
        <div class="feld-reihe feld-reihe--3">
          <div class="feld">
            <label class="feld__label" for="hcp">Handicap</label>
            <input class="eingabe" id="hcp" name="hcp" placeholder="z. B. 26,4 oder 54">
          </div>
          <div class="feld">
            <label class="feld__label" for="heimclub">Heimclub</label>
            <input class="eingabe" id="heimclub" name="heimclub">
          </div>
          <div class="feld">
            <label class="feld__label" for="quelle">Woher</label>
            <input class="eingabe" id="quelle" name="quelle" list="quellen" placeholder="Empfehlung">
            <datalist id="quellen">
              <option>Website</option><option>Empfehlung</option><option>Google</option>
              <option>Club</option><option>Instagram</option><option>Laufkundschaft</option>
            </datalist>
          </div>
        </div>
        <div class="feld">
          <label class="feld__label" for="ziele">Ziel</label>
          <input class="eingabe" id="ziele" name="ziele" placeholder="Platzreife im Frühjahr">
        </div>
        <?php if (count(Auth::trainer()) > 1): ?>
        <div class="feld">
          <label class="feld__label" for="trainer_id">Betreuender Trainer</label>
          <select id="trainer_id" name="trainer_id">
            <?php foreach (Auth::trainer() as $t): ?>
              <option value="<?= (int) $t['id'] ?>"<?= (int) $t['id'] === Auth::id() ? ' selected' : '' ?>>
                <?= Util::h((string) $t['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>
        <label class="haken">
          <input type="checkbox" name="newsletter" value="1">
          <span class="haken__text">Newsletter-Einwilligung liegt vor
            <span class="haken__hinweis">Nur ankreuzen, wenn die Person tatsächlich zugestimmt hat.
              Ohne Einwilligung wird sie von Newslettern und werblichen Automationen ausgenommen.</span>
          </span>
        </label>
      </div>
      <div class="karte__fuss">
        <div class="fueller"></div>
        <a class="btn" href="<?= Util::attr(App::url('/app/kunden.php')) ?>">Abbrechen</a>
        <button class="btn btn--primaer" type="submit">Kunde anlegen</button>
      </div>
    </form>
    <?php
    require __DIR__ . '/partials/fuss.php';
    exit;
}

/* -------------------------------------------------------------- Ansicht */

$akte      = Customers::akte($id);
$zahlen    = Customers::kennzahlen($id);
$scoreDaten = Customers::scoreBerechnen($id);
$score     = $scoreDaten['score'];
[$stufe, $stufeName, $stufeFarbe] = Customers::stufe($score);
$tags      = Customers::tags($kunde);
$felder    = Util::ausJson((string) $kunde['felder']);
$eigenFelder = Tenant::all('custom_fields', 'objekt = "customer"', [], 'position');
$spiel     = Gamification::stand($id);

$titel = Customers::name($kunde);
$unter = trim(implode(' · ', array_filter([
    (string) $kunde['hcp'] !== '' ? 'HCP ' . Util::hcp((string) $kunde['hcp']) : '',
    (string) $kunde['heimclub'],
    'Kunde seit ' . Util::datum((string) $kunde['erstellt']),
])));
$brotkrumen = [['Kunden', '/app/kunden.php'], [Customers::name($kunde), null]];

$aktionen = '';
if (Auth::darf('bookings.write')) {
    $aktionen .= '<a class="btn btn--primaer" href="' . App::url('/app/buchung.php?id=neu&kunde=' . $id) . '">'
               . Icon::svg('calendar', 15) . ' Termin buchen</a>';
}
$aktionen .= '<div class="aufklapp"><button class="btn" data-aufklapp>' . Icon::svg('more', 15) . '</button>'
           . '<div class="aufklapp__menue">';
if ((string) $kunde['email'] !== '') {
    $aktionen .= '<a class="aufklapp__eintrag" href="mailto:' . Util::attr((string) $kunde['email']) . '">'
               . Icon::svg('mail', 16) . ' E-Mail schreiben</a>';
}
if ((string) $kunde['telefon'] !== '') {
    $aktionen .= '<a class="aufklapp__eintrag" href="tel:' . Util::attr((string) $kunde['telefon']) . '">'
               . Icon::svg('phone', 16) . ' Anrufen</a>';
}
if (Auth::darf('invoices.write')) {
    $aktionen .= '<a class="aufklapp__eintrag" href="' . App::url('/app/rechnung.php?id=neu&kunde=' . $id) . '">'
               . Icon::svg('invoices', 16) . ' Rechnung erstellen</a>';
}
if (Auth::darf('training.write')) {
    $aktionen .= '<a class="aufklapp__eintrag" href="' . App::url('/app/trainingsplan.php?id=neu&kunde=' . $id) . '">'
               . Icon::svg('training', 16) . ' Trainingsplan erstellen</a>';
}
$aktionen .= '<a class="aufklapp__eintrag" href="' . Util::attr(Customers::portalLink($kunde)) . '" target="_blank" rel="noopener">'
           . Icon::svg('external', 16) . ' Kundenzugang öffnen</a>'
           . '<button class="aufklapp__eintrag" data-kopieren="' . Util::attr(Customers::portalLink($kunde)) . '">'
           . Icon::svg('copy', 16) . ' Zugangslink kopieren</button>'
           . '<a class="aufklapp__eintrag" href="' . App::url('/app/datenschutz.php?kunde=' . $id) . '">'
           . Icon::svg('download', 16) . ' Daten exportieren</a>';
if (Auth::darf('customers.delete')) {
    $aktionen .= '<div class="aufklapp__trenner"></div>'
               . '<button class="aufklapp__eintrag aufklapp__eintrag--gefahr" data-modal-auf="modal-loeschen">'
               . Icon::svg('trash', 16) . ' Kunde löschen</button>';
}
$aktionen .= '</div></div>';

require __DIR__ . '/partials/kopf.php';
?>

<div class="raster raster--neben-haupt">

  <?php /* ========================================== Linke Spalte ===== */ ?>
  <div class="stapel">
    <div class="karte">
      <div class="karte__koerper mitte">
        <span class="avatar avatar--riesig" style="background:<?= Util::attr(Util::avatarFarbe(Customers::name($kunde))) ?>">
          <?= Util::h(Util::initialen(Customers::name($kunde))) ?>
        </span>
        <h2 class="mt-3"><?= Util::h(Customers::name($kunde)) ?></h2>
        <?php if ($tags !== []): ?>
          <div class="reihe reihe--eng reihe--umbruch mt-2" style="justify-content:center">
            <?php foreach ($tags as $t): ?><?= pille($t, 'offen') ?><?php endforeach; ?>
          </div>
        <?php endif; ?>

        <div class="mt-4" style="display:flex;gap:var(--r4);justify-content:center;align-items:center">
          <?= Diagramm::ring($score, 66,
                $score >= 70 ? 'var(--erfolg)' : ($score >= 40 ? 'var(--warnung)' : 'var(--gefahr)')) ?>
          <div style="text-align:left">
            <div class="halbfett"><?= Util::h($stufeName) ?></div>
            <div class="klein gedimmt">Gesundheitswert</div>
          </div>
        </div>
      </div>

      <div class="karte__koerper" style="border-top:1px solid var(--rand)">
        <div class="stapel stapel--eng">
          <?php
          $kontakt = [
            ['mail', (string) $kunde['email'], 'mailto:' . $kunde['email']],
            ['phone', (string) $kunde['telefon'], 'tel:' . $kunde['telefon']],
            ['pin', trim(((string) $kunde['strasse']) . ', ' . ((string) $kunde['plz']) . ' ' . ((string) $kunde['ort']), ' ,'), ''],
            ['gift', (string) $kunde['geburtstag'] !== ''
                ? Util::datum((string) $kunde['geburtstag']) . ' (' . (int) ((time() - strtotime((string) $kunde['geburtstag'])) / 31557600) . ' Jahre)'
                : '', ''],
            ['customers', Auth::trainerName((int) $kunde['trainer_id']), ''],
          ];
          foreach ($kontakt as [$icon, $wert, $link]):
            if (trim($wert) === '') { continue; } ?>
            <div class="reihe reihe--eng" style="font-size:13px">
              <span style="color:var(--text-4)"><?= Icon::svg($icon, 15) ?></span>
              <?php if ($link !== ''): ?>
                <a href="<?= Util::attr($link) ?>" style="word-break:break-word"><?= Util::h($wert) ?></a>
              <?php else: ?>
                <span style="word-break:break-word"><?= Util::h($wert) ?></span>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="karte__koerper" style="border-top:1px solid var(--rand)">
        <div class="versal gedimmt-2 mb-3">Zahlen</div>
        <div class="stapel stapel--eng">
          <?php foreach ([
            ['Umsatz gesamt', Util::geld($zahlen['umsatz'])],
            ['Termine', Util::zahl($zahlen['termine'])],
            ['Offene Einheiten', Util::zahl($zahlen['einheiten'])],
            ['Offene Rechnungen', $zahlen['offen'] > 0 ? Util::geld($zahlen['offen']) : '—'],
          ] as [$label, $wert]): ?>
            <div class="reihe" style="font-size:13px">
              <span class="gedimmt"><?= Util::h($label) ?></span>
              <div class="fueller"></div>
              <span class="halbfett tabnum"><?= Util::h($wert) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <?php if (Tenant::modul('community')): ?>
      <div class="karte__koerper" style="border-top:1px solid var(--rand)">
        <div class="versal gedimmt-2 mb-3">Fortschritt</div>
        <div class="reihe mb-2">
          <span class="pille pille--akzent">Stufe <?= $spiel['level'] ?></span>
          <div class="fueller"></div>
          <span class="klein gedimmt"><?= Util::zahl($spiel['xp']) ?> Punkte</span>
        </div>
        <?= balken($spiel['fortschritt'], 'akzent') ?>
        <div class="klein gedimmt mt-2">
          Noch <?= Util::zahl($spiel['bis_naechste']) ?> Punkte bis Stufe <?= $spiel['level'] + 1 ?>
          <?php if ($spiel['streak'] > 1): ?>
            · <?= Icon::svg('flame', 13) ?> <?= $spiel['streak'] ?> Tage in Folge
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>
    </div>

    <div class="karte">
      <div class="karte__kopf"><h3>Warum <?= $score ?>?</h3></div>
      <div class="karte__koerper karte__koerper--eng">
        <div class="stapel stapel--eng">
          <?php foreach ($scoreDaten['teile'] as $t): ?>
            <div>
              <div class="reihe" style="font-size:12.5px">
                <span><?= Util::h($t['name']) ?></span>
                <div class="fueller"></div>
                <span class="gedimmt tabnum"><?= $t['wert'] ?>/<?= $t['max'] ?></span>
              </div>
              <?= balken((int) round($t['wert'] / $t['max'] * 100)) ?>
              <div class="winzig gedimmt-2"><?= Util::h($t['text']) ?></div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>

  <?php /* ========================================= Rechte Spalte ===== */ ?>
  <div>
    <div class="reiter" data-reiter-gruppe="kunde">
      <?php
      $reiter = [
        'uebersicht' => ['Übersicht', ''],
        'termine'    => ['Termine', count($akte['buchungen'])],
        'training'   => ['Training', count($akte['plaene'])],
        'leistung'   => ['Leistungsdaten', count($akte['leistung'])],
        'finanzen'   => ['Finanzen', count($akte['rechnungen'])],
        'videos'     => ['Videos', count($akte['videos'])],
        'notizen'    => ['Notizen', count($akte['notizen'])],
        'stammdaten' => ['Stammdaten', ''],
      ];
      if (!Tenant::modul('video')) { unset($reiter['videos']); }
      if (!Tenant::modul('training')) { unset($reiter['training'], $reiter['leistung']); }
      if (!Auth::darf('invoices.view')) { unset($reiter['finanzen']); }
      $erster = array_key_first($reiter);
      foreach ($reiter as $key => [$name, $zahl]): ?>
        <button class="reiter__teil<?= $key === $erster ? ' ist-aktiv' : '' ?>" data-reiter="<?= $key ?>">
          <?= Util::h($name) ?>
          <?php if ($zahl !== '' && $zahl > 0): ?><span class="navi__zahl"><?= $zahl ?></span><?php endif; ?>
        </button>
      <?php endforeach; ?>
    </div>

    <?php /* ---------------------------------------------- Übersicht */ ?>
    <div data-reiter-feld="uebersicht" data-reiter-gruppe="kunde">
      <div class="stapel">
        <?php if ((string) $kunde['ziele'] !== ''): ?>
          <div class="hinweis hinweis--still">
            <?= Icon::svg('target', 17) ?>
            <div class="hinweis__text"><span class="hinweis__titel">Ziel</span><?= Util::h((string) $kunde['ziele']) ?></div>
          </div>
        <?php endif; ?>

        <div class="raster raster--2">
          <div class="karte">
            <div class="karte__kopf"><h3>Nächste Termine</h3></div>
            <div class="karte__koerper karte__koerper--eng">
              <?php if ($akte['kommend'] === []): ?>
                <p class="klein gedimmt mitte" style="padding:var(--r3) 0">
                  Kein Termin vereinbart.<br>
                  <?php if ($zahlen['einheiten'] > 0): ?>
                    <span style="color:var(--warnung)"><?= $zahlen['einheiten'] ?> Einheiten liegen ungenutzt.</span>
                  <?php endif; ?>
                </p>
              <?php else: ?>
                <div class="strahl">
                  <?php foreach ($akte['kommend'] as $b): ?>
                    <div class="strahl__punkt strahl__punkt--marke">
                      <div class="strahl__zeit"><?= Util::h(Util::datumZeit((string) $b['start'])) ?></div>
                      <a class="strahl__titel" href="<?= Util::attr(App::url('/app/buchung.php?id=' . (int) $b['id'])) ?>">
                        <?= Util::h((string) $b['titel']) ?></a>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>
          </div>

          <div class="karte">
            <div class="karte__kopf"><h3>Pakete</h3>
              <?php if (Auth::darf('bookings.write')): ?>
                <div class="fueller"></div>
                <button class="btn btn--klein btn--geist" data-modal-auf="modal-paket"><?= Icon::svg('plus', 14) ?></button>
              <?php endif; ?>
            </div>
            <div class="karte__koerper karte__koerper--eng">
              <?php if ($akte['pakete'] === []): ?>
                <p class="klein gedimmt mitte" style="padding:var(--r3) 0">Kein Paket gekauft.</p>
              <?php else: ?>
                <div class="stapel stapel--eng">
                  <?php foreach ($akte['pakete'] as $pk):
                    $rest = (int) $pk['einheiten_gesamt'] - (int) $pk['einheiten_genutzt'];
                    $prozent = (int) $pk['einheiten_gesamt'] > 0
                      ? (int) round((int) $pk['einheiten_genutzt'] / (int) $pk['einheiten_gesamt'] * 100) : 0; ?>
                    <div>
                      <div class="reihe" style="font-size:13px">
                        <span class="halbfett"><?= Util::h((string) $pk['name']) ?></span>
                        <div class="fueller"></div>
                        <span class="tabnum <?= $rest > 0 ? 'halbfett' : 'gedimmt' ?>">
                          <?= $rest ?>/<?= (int) $pk['einheiten_gesamt'] ?></span>
                      </div>
                      <?= balken($prozent, $rest === 0 ? 'gefahr' : ($rest <= 1 ? 'warnung' : '')) ?>
                      <div class="winzig gedimmt-2">
                        <?= (string) $pk['status'] === 'aktiv'
                            ? 'gültig bis ' . Util::datum((string) $pk['laeuft_ab'])
                            : ucfirst((string) $pk['status']) ?>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <?php if (Tenant::modul('training') && $akte['leistung'] !== []): ?>
        <div class="karte">
          <div class="karte__kopf"><h3>Handicap-Entwicklung</h3></div>
          <div class="karte__koerper">
            <?php $v = Training::verlauf($id, 'hcp', 20); ?>
            <?= Diagramm::linie($v['labels'], ['HCP' => $v['werte']], ['hoehe' => 190, 'umgekehrt' => true]) ?>
          </div>
        </div>
        <?php endif; ?>

        <div class="karte">
          <div class="karte__kopf"><h3>Verlauf</h3></div>
          <div class="karte__koerper">
            <?php
            $verlauf = [];
            foreach (array_slice($akte['buchungen'], 0, 12) as $b) {
                $verlauf[] = ['zeit' => (string) $b['start'], 'art' => 'buchung',
                    'titel' => (string) $b['titel'],
                    'text' => Bookings::statusName((string) $b['status'])];
            }
            foreach (array_slice($akte['zahlungen'], 0, 6) as $z) {
                $verlauf[] = ['zeit' => (string) $z['erstellt'], 'art' => 'zahlung',
                    'titel' => 'Zahlung ' . Util::geld((int) $z['betrag_cent']),
                    'text' => Stripe::methodeName((string) $z['methode'])];
            }
            foreach (array_slice($akte['nachrichten'], 0, 6) as $n) {
                $verlauf[] = ['zeit' => (string) $n['erstellt'], 'art' => 'nachricht',
                    'titel' => (string) $n['betreff'], 'text' => 'E-Mail ' . ($n['richtung'] === 'aus' ? 'versendet' : 'erhalten')];
            }
            usort($verlauf, static fn($a, $b) => strcmp($b['zeit'], $a['zeit']));
            $verlauf = array_slice($verlauf, 0, 14);
            $farben = ['buchung' => 'marke', 'zahlung' => 'erfolg', 'nachricht' => ''];
            ?>
            <?php if ($verlauf === []): ?>
              <p class="klein gedimmt mitte">Noch nichts passiert.</p>
            <?php else: ?>
              <div class="strahl">
                <?php foreach ($verlauf as $e): ?>
                  <div class="strahl__punkt<?= $farben[$e['art']] !== '' ? ' strahl__punkt--' . $farben[$e['art']] : '' ?>">
                    <div class="strahl__zeit"><?= Util::h(Util::datumZeit($e['zeit'])) ?></div>
                    <div class="strahl__titel"><?= Util::h($e['titel']) ?></div>
                    <div class="strahl__text"><?= Util::h($e['text']) ?></div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <?php if (Auth::darf('ai.use') && Tenant::modul('ai')): ?>
        <div class="ki-block">
          <div class="ki-block__kopf"><?= Icon::svg('ai', 15) ?> Kunde analysieren</div>
          <div class="ki-block__text"><?= Util::einfachesMarkdown(KI::kundenAnalyse($id)) ?></div>
          <div class="ki-warnung mt-3"><?= Icon::svg('info', 13) ?>
            Aus den Daten dieses Kunden berechnet. Der Vorschlag ersetzt nicht dein Urteil.</div>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <?php /* ------------------------------------------------ Termine */ ?>
    <div data-reiter-feld="termine" data-reiter-gruppe="kunde" class="versteckt">
      <div class="karte">
        <div class="karte__kopf"><h3>Alle Termine</h3>
          <div class="fueller"></div>
          <?php if (Auth::darf('bookings.write')): ?>
            <a class="btn btn--klein btn--primaer" href="<?= Util::attr(App::url('/app/buchung.php?id=neu&kunde=' . $id)) ?>">
              <?= Icon::svg('plus', 14) ?> Termin</a>
          <?php endif; ?>
        </div>
        <?php if ($akte['buchungen'] === []): ?>
          <div class="karte__koerper">
            <?= leerzustand('calendar', 'Noch kein Termin',
                  'Sobald ein Training gebucht ist, steht die ganze Historie hier.',
                  Auth::darf('bookings.write') ? [['Termin buchen', '/app/buchung.php?id=neu&kunde=' . $id, 'plus']] : []) ?>
          </div>
        <?php else: ?>
          <div class="tabelle-huelle">
            <table class="tabelle">
              <thead><tr><th>Wann</th><th>Leistung</th><th class="nicht-mobil">Trainer</th>
                <th class="zahl nicht-mobil">Preis</th><th>Status</th></tr></thead>
              <tbody>
              <?php foreach ($akte['buchungen'] as $b): ?>
                <tr>
                  <td class="umbruch-nein">
                    <a class="haupt" href="<?= Util::attr(App::url('/app/buchung.php?id=' . (int) $b['id'])) ?>">
                      <?= Util::h(Util::datum((string) $b['start'])) ?></a>
                    <div class="winzig gedimmt-2"><?= Util::h(Util::uhrzeit((string) $b['start'])) ?> Uhr</div>
                  </td>
                  <td><?= Util::h((string) $b['titel']) ?>
                    <?php if ((int) $b['customer_package_id'] > 0): ?>
                      <span class="pille pille--offen">aus Paket</span>
                    <?php endif; ?>
                  </td>
                  <td class="nicht-mobil gedimmt"><?= Util::h(Auth::trainerName((int) $b['trainer_id'])) ?></td>
                  <td class="zahl nicht-mobil tabnum">
                    <?= (int) $b['customer_package_id'] > 0 ? '—' : Util::h(Util::geldKurz((int) $b['preis_cent'])) ?></td>
                  <td><?= pille(Bookings::statusName((string) $b['status']), Bookings::statusFarbe((string) $b['status'])) ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <?php /* ----------------------------------------------- Training */ ?>
    <div data-reiter-feld="training" data-reiter-gruppe="kunde" class="versteckt">
      <div class="stapel">
        <div class="karte">
          <div class="karte__kopf"><h3>Trainingspläne</h3>
            <div class="fueller"></div>
            <?php if (Auth::darf('training.write')): ?>
              <a class="btn btn--klein" href="<?= Util::attr(App::url('/app/trainingsplan.php?id=neu&kunde=' . $id)) ?>">
                <?= Icon::svg('plus', 14) ?> Plan</a>
              <a class="btn btn--klein btn--primaer" href="<?= Util::attr(App::url('/app/trainingsplan.php?id=neu&kunde=' . $id . '&ki=1')) ?>">
                <?= Icon::svg('ai', 14) ?> Mit KI</a>
            <?php endif; ?>
          </div>
          <div class="karte__koerper">
            <?php if ($akte['plaene'] === []): ?>
              <?= leerzustand('training', 'Noch kein Trainingsplan',
                    'Ein Plan gibt dem Kunden zwischen den Stunden etwas zu tun – das ist der '
                    . 'größte Unterschied zwischen langsamem und schnellem Fortschritt.',
                    Auth::darf('training.write')
                      ? [['Plan erstellen', '/app/trainingsplan.php?id=neu&kunde=' . $id, 'plus'],
                         ['Mit KI erstellen', '/app/trainingsplan.php?id=neu&kunde=' . $id . '&ki=1', 'ai']]
                      : []) ?>
            <?php else: ?>
              <div class="stapel stapel--eng">
                <?php foreach ($akte['plaene'] as $pl): ?>
                  <a class="kachel-link" href="<?= Util::attr(App::url('/app/trainingsplan.php?id=' . (int) $pl['id'])) ?>">
                    <span class="kachel-link__symbol"><?= Icon::svg('training', 17) ?></span>
                    <div style="flex:1;min-width:0">
                      <div class="kachel-link__titel"><?= Util::h((string) $pl['name']) ?></div>
                      <div class="kachel-link__text"><?= Util::h((string) $pl['ziel']) ?></div>
                      <div class="mt-2"><?= balken((int) $pl['fortschritt']) ?></div>
                    </div>
                    <span class="pille pille--<?= (string) $pl['zuweisung_status'] === 'aktiv' ? 'erfolg' : 'offen' ?>">
                      <?= (int) $pl['fortschritt'] ?> %</span>
                  </a>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <?php if ($akte['kurse'] !== []): ?>
        <div class="karte">
          <div class="karte__kopf"><h3>Kurse</h3></div>
          <div class="karte__koerper karte__koerper--eng">
            <div class="stapel stapel--eng">
              <?php foreach ($akte['kurse'] as $kurs): ?>
                <div class="reihe" style="padding:6px">
                  <span class="kachel-link__symbol" style="width:30px;height:30px"><?= Icon::svg('courses', 15) ?></span>
                  <div style="flex:1;min-width:0">
                    <div class="klein halbfett"><?= Util::h((string) $kurs['titel']) ?></div>
                    <?= balken((int) $kurs['fortschritt']) ?>
                  </div>
                  <span class="klein tabnum"><?= (int) $kurs['fortschritt'] ?> %</span>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <?php /* ------------------------------------------- Leistungsdaten */ ?>
    <div data-reiter-feld="leistung" data-reiter-gruppe="kunde" class="versteckt">
      <div class="stapel">
        <div class="karte">
          <div class="karte__kopf"><h3>Runden erfassen</h3>
            <div class="fueller"></div>
            <button class="btn btn--klein btn--primaer" data-modal-auf="modal-leistung">
              <?= Icon::svg('plus', 14) ?> Runde eintragen</button>
          </div>
          <div class="karte__koerper">
            <?php if ($akte['leistung'] === []): ?>
              <?= leerzustand('analytics', 'Noch keine Daten',
                    'Score, Fairways, Grüns in Regulation und Putts – wer das drei Runden lang '
                    . 'notiert, sieht sofort, wo die Schläge liegen bleiben.', []) ?>
            <?php else: ?>
              <div class="raster raster--2 mb-5">
                <?php foreach (['score' => 'Score', 'putts' => 'Putts'] as $metrik => $name):
                  $v = Training::verlauf($id, $metrik, 15);
                  if (count($v['werte']) < 2) { continue; } ?>
                  <div>
                    <div class="versal gedimmt-2 mb-2"><?= Util::h($name) ?></div>
                    <?= Diagramm::linie($v['labels'], [$name => $v['werte']], ['hoehe' => 150, 'umgekehrt' => true]) ?>
                  </div>
                <?php endforeach; ?>
              </div>
              <div class="tabelle-huelle">
                <table class="tabelle tabelle--eng">
                  <thead><tr><th>Datum</th><th>Platz</th><th class="zahl">HCP</th><th class="zahl">Score</th>
                    <th class="zahl nicht-mobil">FW</th><th class="zahl nicht-mobil">GIR</th>
                    <th class="zahl">Putts</th><th class="zahl nicht-mobil">Drive</th></tr></thead>
                  <tbody>
                  <?php foreach (array_slice($akte['leistung'], 0, 20) as $l): ?>
                    <tr>
                      <td class="umbruch-nein"><?= Util::h(Util::datum((string) $l['datum'])) ?></td>
                      <td class="gedimmt"><?= Util::h(Util::kuerzen((string) $l['platz'], 20)) ?></td>
                      <td class="zahl"><?= Util::h(Util::hcp((string) $l['hcp'])) ?></td>
                      <td class="zahl halbfett"><?= (int) $l['score'] ?: '—' ?></td>
                      <td class="zahl nicht-mobil"><?= (int) $l['fairways'] ?: '—' ?></td>
                      <td class="zahl nicht-mobil"><?= (int) $l['gir'] ?: '—' ?></td>
                      <td class="zahl"><?= (int) $l['putts'] ?: '—' ?></td>
                      <td class="zahl nicht-mobil"><?= (int) $l['driving_distance'] ? (int) $l['driving_distance'] . ' m' : '—' ?></td>
                    </tr>
                  <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <?php /* ----------------------------------------------- Finanzen */ ?>
    <?php if (Auth::darf('invoices.view')): ?>
    <div data-reiter-feld="finanzen" data-reiter-gruppe="kunde" class="versteckt">
      <div class="stapel">
        <div class="karte">
          <div class="karte__kopf"><h3>Rechnungen</h3>
            <div class="fueller"></div>
            <?php if (Auth::darf('invoices.write')): ?>
              <a class="btn btn--klein" href="<?= Util::attr(App::url('/app/rechnung.php?id=neu&kunde=' . $id)) ?>">
                <?= Icon::svg('plus', 14) ?> Rechnung</a>
            <?php endif; ?>
          </div>
          <?php if ($akte['rechnungen'] === []): ?>
            <div class="karte__koerper"><p class="klein gedimmt mitte">Noch keine Rechnung.</p></div>
          <?php else: ?>
            <div class="tabelle-huelle">
              <table class="tabelle">
                <thead><tr><th>Nummer</th><th>Datum</th><th class="zahl">Betrag</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($akte['rechnungen'] as $r): ?>
                  <tr>
                    <td><a class="haupt" href="<?= Util::attr(App::url('/app/rechnung.php?id=' . (int) $r['id'])) ?>">
                      <?= Util::h((string) ($r['nummer'] ?: 'Entwurf')) ?></a></td>
                    <td class="gedimmt"><?= Util::h(Util::datum((string) $r['datum'])) ?></td>
                    <td class="zahl tabnum"><?= Util::h(Util::geld((int) $r['summe_cent'])) ?></td>
                    <td><?= pille(Invoices::statusName((string) $r['status']), Invoices::statusFarbe((string) $r['status'])) ?></td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>

        <?php if ($akte['zahlungen'] !== []): ?>
        <div class="karte">
          <div class="karte__kopf"><h3>Zahlungen</h3></div>
          <div class="tabelle-huelle">
            <table class="tabelle">
              <thead><tr><th>Datum</th><th>Methode</th><th class="zahl">Betrag</th><th>Status</th></tr></thead>
              <tbody>
              <?php foreach ($akte['zahlungen'] as $z): ?>
                <tr>
                  <td><?= Util::h(Util::datum((string) $z['erstellt'])) ?></td>
                  <td><?= Util::h(Stripe::methodeName((string) $z['methode'])) ?></td>
                  <td class="zahl tabnum"><?= Util::h(Util::geld((int) $z['betrag_cent'])) ?></td>
                  <td><?= pille((string) $z['status'], (string) $z['status'] === 'bezahlt' ? 'erfolg' : 'warnung') ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
        <?php endif; ?>

        <?php if ($akte['gutscheine'] !== []): ?>
        <div class="karte">
          <div class="karte__kopf"><h3>Gutscheine</h3></div>
          <div class="karte__koerper karte__koerper--eng">
            <?php foreach ($akte['gutscheine'] as $g): ?>
              <div class="reihe" style="padding:6px">
                <?= Icon::svg('gift', 16) ?>
                <span class="mono"><?= Util::h((string) $g['code']) ?></span>
                <div class="fueller"></div>
                <span class="tabnum halbfett"><?= Util::h(Util::geld((int) $g['rest_cent'])) ?></span>
                <?= pille((string) $g['status'], Vouchers::statusFarbe((string) $g['status'])) ?>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <?php /* ------------------------------------------------- Videos */ ?>
    <?php if (Tenant::modul('video')): ?>
    <div data-reiter-feld="videos" data-reiter-gruppe="kunde" class="versteckt">
      <div class="karte">
        <div class="karte__kopf"><h3>Videos und Analysen</h3>
          <div class="fueller"></div>
          <?php if (Auth::darf('video.write')): ?>
            <a class="btn btn--klein btn--primaer" href="<?= Util::attr(App::url('/app/videos.php?aktion=neu&kunde=' . $id)) ?>">
              <?= Icon::svg('upload', 14) ?> Video</a>
          <?php endif; ?>
        </div>
        <div class="karte__koerper">
          <?php if ($akte['videos'] === [] && $akte['analysen'] === []): ?>
            <?= leerzustand('video', 'Noch kein Video',
                  'Eine Aufnahme von vorn und eine von der Ziellinie genügen. Die Analyse kann '
                  . 'der Kunde später jederzeit in seinem Zugang ansehen.',
                  Auth::darf('video.write') ? [['Video hochladen', '/app/videos.php?aktion=neu&kunde=' . $id, 'upload']] : []) ?>
          <?php else: ?>
            <div class="medien-raster">
              <?php foreach ($akte['videos'] as $v): ?>
                <a class="medium" href="<?= Util::attr(App::url('/app/videoanalyse.php?video=' . (int) $v['id'])) ?>">
                  <div class="medium__bild"><?= Icon::svg('play', 26) ?></div>
                  <div class="medium__text">
                    <span class="medium__name"><?= Util::h((string) $v['titel']) ?></span>
                    <?= Util::h(Util::datum((string) $v['erstellt'])) ?>
                    · <?= Util::h(Videos::ANSICHTEN[(string) $v['ansicht']] ?? '') ?>
                  </div>
                </a>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <?php /* ------------------------------------------------ Notizen */ ?>
    <div data-reiter-feld="notizen" data-reiter-gruppe="kunde" class="versteckt">
      <div class="stapel">
        <?php if (Auth::darf('customers.write')): ?>
        <form method="post" class="karte">
          <?= Auth::csrfFeld() ?>
          <input type="hidden" name="aktion" value="notiz">
          <div class="karte__koerper">
            <textarea class="eingabe" name="text" rows="3" data-waechst
                      placeholder="Was war heute wichtig? Was soll beim nächsten Mal beachtet werden?"></textarea>
          </div>
          <div class="karte__fuss">
            <div class="fueller"></div>
            <button class="btn btn--primaer btn--klein" type="submit">Notiz speichern</button>
          </div>
        </form>
        <?php endif; ?>

        <?php if ($akte['notizen'] === []): ?>
          <div class="karte"><div class="karte__koerper">
            <?= leerzustand('content', 'Noch keine Notizen',
                  'Kurze Vermerke nach der Stunde sind das, was ein Jahr später den Unterschied macht.', []) ?>
          </div></div>
        <?php else: ?>
          <?php foreach ($akte['notizen'] as $n): ?>
            <div class="karte">
              <div class="karte__koerper karte__koerper--eng">
                <div class="reihe reihe--eng mb-2">
                  <?= person(Auth::trainerName((int) $n['user_id'])) ?>
                  <div class="fueller"></div>
                  <span class="winzig gedimmt-2"><?= Util::h(Util::relativ((string) $n['erstellt'])) ?></span>
                  <?php if ((int) $n['angeheftet'] === 1): ?>
                    <?= pille('angeheftet', 'akzent') ?>
                  <?php endif; ?>
                  <?php if (Auth::darf('customers.write')): ?>
                    <form method="post" style="display:inline"
                          data-bestaetigen="Diese Notiz löschen?">
                      <?= Auth::csrfFeld() ?>
                      <input type="hidden" name="aktion" value="notiz_loeschen">
                      <input type="hidden" name="notiz_id" value="<?= (int) $n['id'] ?>">
                      <button class="btn btn--klein btn--geist btn--nur-symbol" type="submit"
                              aria-label="Notiz löschen"><?= Icon::svg('trash', 14) ?></button>
                    </form>
                  <?php endif; ?>
                </div>
                <div style="font-size:13.5px;line-height:1.6"><?= nl2br(Util::h((string) $n['text'])) ?></div>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

    <?php /* --------------------------------------------- Stammdaten */ ?>
    <div data-reiter-feld="stammdaten" data-reiter-gruppe="kunde" class="versteckt">
      <form method="post" class="karte">
        <?= Auth::csrfFeld() ?>
        <input type="hidden" name="aktion" value="speichern">
        <div class="karte__koerper">
          <div class="feld-reihe feld-reihe--2">
            <div class="feld"><label class="feld__label" for="s-vorname">Vorname</label>
              <input class="eingabe" id="s-vorname" name="vorname" value="<?= Util::attr((string) $kunde['vorname']) ?>"></div>
            <div class="feld"><label class="feld__label" for="s-nachname">Nachname</label>
              <input class="eingabe" id="s-nachname" name="nachname" value="<?= Util::attr((string) $kunde['nachname']) ?>"></div>
          </div>
          <div class="feld-reihe feld-reihe--2">
            <div class="feld"><label class="feld__label" for="s-email">E-Mail</label>
              <input class="eingabe" id="s-email" type="email" name="email" value="<?= Util::attr((string) $kunde['email']) ?>"></div>
            <div class="feld"><label class="feld__label" for="s-telefon">Telefon</label>
              <input class="eingabe" id="s-telefon" type="tel" name="telefon" value="<?= Util::attr((string) $kunde['telefon']) ?>"></div>
          </div>
          <div class="feld-reihe feld-reihe--3">
            <div class="feld"><label class="feld__label" for="s-geb">Geburtstag</label>
              <input class="eingabe" id="s-geb" type="date" name="geburtstag" value="<?= Util::attr((string) $kunde['geburtstag']) ?>"></div>
            <div class="feld"><label class="feld__label" for="s-hcp">Handicap</label>
              <input class="eingabe" id="s-hcp" name="hcp" value="<?= Util::attr((string) $kunde['hcp'] !== '' ? Util::hcp((string) $kunde['hcp']) : '') ?>"></div>
            <div class="feld"><label class="feld__label" for="s-hand">Schlaghand</label>
              <select id="s-hand" name="dominante_hand">
                <option value="rechts"<?= $kunde['dominante_hand'] === 'rechts' ? ' selected' : '' ?>>Rechts</option>
                <option value="links"<?= $kunde['dominante_hand'] === 'links' ? ' selected' : '' ?>>Links</option>
              </select></div>
          </div>
          <div class="feld"><label class="feld__label" for="s-club">Heimclub</label>
            <input class="eingabe" id="s-club" name="heimclub" value="<?= Util::attr((string) $kunde['heimclub']) ?>"></div>
          <div class="feld"><label class="feld__label" for="s-ziel">Ziel</label>
            <input class="eingabe" id="s-ziel" name="ziele" value="<?= Util::attr((string) $kunde['ziele']) ?>"></div>
          <div class="feld-reihe feld-reihe--3">
            <div class="feld"><label class="feld__label" for="s-strasse">Straße</label>
              <input class="eingabe" id="s-strasse" name="strasse" value="<?= Util::attr((string) $kunde['strasse']) ?>"></div>
            <div class="feld"><label class="feld__label" for="s-plz">PLZ</label>
              <input class="eingabe" id="s-plz" name="plz" value="<?= Util::attr((string) $kunde['plz']) ?>"></div>
            <div class="feld"><label class="feld__label" for="s-ort">Ort</label>
              <input class="eingabe" id="s-ort" name="ort" value="<?= Util::attr((string) $kunde['ort']) ?>"></div>
          </div>
          <div class="feld-reihe feld-reihe--3">
            <div class="feld"><label class="feld__label" for="s-status">Status</label>
              <select id="s-status" name="status">
                <?php foreach (['aktiv' => 'Aktiv', 'inaktiv' => 'Inaktiv', 'archiviert' => 'Archiviert'] as $k => $v): ?>
                  <option value="<?= $k ?>"<?= $kunde['status'] === $k ? ' selected' : '' ?>><?= $v ?></option>
                <?php endforeach; ?>
              </select></div>
            <div class="feld"><label class="feld__label" for="s-quelle">Quelle</label>
              <input class="eingabe" id="s-quelle" name="quelle" value="<?= Util::attr((string) $kunde['quelle']) ?>"></div>
            <div class="feld"><label class="feld__label" for="s-trainer">Trainer</label>
              <select id="s-trainer" name="trainer_id">
                <option value="0">—</option>
                <?php foreach (Auth::trainer() as $t): ?>
                  <option value="<?= (int) $t['id'] ?>"<?= (int) $kunde['trainer_id'] === (int) $t['id'] ? ' selected' : '' ?>>
                    <?= Util::h((string) $t['name']) ?></option>
                <?php endforeach; ?>
              </select></div>
          </div>
          <div class="feld"><label class="feld__label" for="s-tags">Schlagworte</label>
            <input class="eingabe" id="s-tags" name="tags" value="<?= Util::attr(implode(', ', $tags)) ?>"
                   placeholder="Einsteiger, Stammkunde">
            <div class="feld__hinweis">Mit Komma trennen. Schlagworte lassen sich in Segmenten und Newslettern nutzen.</div>
          </div>
          <?php foreach ($eigenFelder as $cf): ?>
            <div class="feld">
              <label class="feld__label" for="cf-<?= Util::attr((string) $cf['schluessel']) ?>">
                <?= Util::h((string) $cf['label']) ?></label>
              <input class="eingabe" id="cf-<?= Util::attr((string) $cf['schluessel']) ?>"
                     name="feld_<?= Util::attr((string) $cf['schluessel']) ?>"
                     type="<?= (string) $cf['typ'] === 'zahl' ? 'number' : ((string) $cf['typ'] === 'datum' ? 'date' : 'text') ?>"
                     value="<?= Util::attr((string) ($felder[(string) $cf['schluessel']] ?? '')) ?>">
            </div>
          <?php endforeach; ?>
          <div class="feld"><label class="feld__label" for="s-notiz">Interne Notiz</label>
            <textarea class="eingabe" id="s-notiz" name="notiz" rows="3" data-waechst><?= Util::h((string) $kunde['notiz']) ?></textarea></div>
          <label class="haken">
            <input type="checkbox" name="newsletter" value="1"<?= (int) $kunde['newsletter'] === 1 ? ' checked' : '' ?>>
            <span class="haken__text">Newsletter-Einwilligung
              <span class="haken__hinweis">Ohne Einwilligung wird dieser Kunde von Newslettern
                und werblichen Automationen ausgenommen.</span></span>
          </label>
        </div>
        <div class="karte__fuss">
          <span class="klein gedimmt">Zuletzt geändert: <?= Util::h(Util::relativ((string) $kunde['letzte_aktivitaet'])) ?></span>
          <div class="fueller"></div>
          <button class="btn btn--primaer" type="submit">Speichern</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php /* ------------------------------------------------------- Dialoge */ ?>
<dialog class="modal" id="modal-leistung">
  <form method="post">
    <?= Auth::csrfFeld() ?>
    <input type="hidden" name="aktion" value="leistung">
    <div class="modal__kopf"><h2>Runde eintragen</h2>
      <button type="button" class="rundknopf" data-modal-zu aria-label="Schließen"><?= Icon::svg('x', 17) ?></button>
    </div>
    <div class="modal__koerper">
      <div class="feld-reihe feld-reihe--2">
        <div class="feld"><label class="feld__label" for="l-datum">Datum</label>
          <input class="eingabe" id="l-datum" type="date" name="datum" value="<?= Util::attr(Util::heute()) ?>"></div>
        <div class="feld"><label class="feld__label" for="l-platz">Platz</label>
          <input class="eingabe" id="l-platz" name="platz" value="<?= Util::attr((string) $kunde['heimclub']) ?>"></div>
      </div>
      <div class="feld-reihe feld-reihe--3">
        <div class="feld"><label class="feld__label" for="l-hcp">Handicap</label>
          <input class="eingabe" id="l-hcp" name="hcp" value="<?= Util::attr((string) $kunde['hcp'] !== '' ? Util::hcp((string) $kunde['hcp']) : '') ?>"></div>
        <div class="feld"><label class="feld__label" for="l-score">Score</label>
          <input class="eingabe" id="l-score" type="number" name="score" placeholder="88"></div>
        <div class="feld"><label class="feld__label" for="l-putts">Putts</label>
          <input class="eingabe" id="l-putts" type="number" name="putts" placeholder="34"></div>
      </div>
      <div class="feld-reihe feld-reihe--3">
        <div class="feld"><label class="feld__label" for="l-fw">Fairways (von 14)</label>
          <input class="eingabe" id="l-fw" type="number" name="fairways" max="14"></div>
        <div class="feld"><label class="feld__label" for="l-gir">GIR (von 18)</label>
          <input class="eingabe" id="l-gir" type="number" name="gir" max="18"></div>
        <div class="feld"><label class="feld__label" for="l-pen">Strafschläge</label>
          <input class="eingabe" id="l-pen" type="number" name="penalties"></div>
      </div>
      <div class="feld-reihe feld-reihe--3">
        <div class="feld"><label class="feld__label" for="l-dd">Driving Distance (m)</label>
          <input class="eingabe" id="l-dd" type="number" name="driving_distance"></div>
        <div class="feld"><label class="feld__label" for="l-cs">Schlägerkopf (mph)</label>
          <input class="eingabe" id="l-cs" type="number" name="club_speed"></div>
        <div class="feld"><label class="feld__label" for="l-scr">Scrambling (%)</label>
          <input class="eingabe" id="l-scr" type="number" name="scrambling" max="100"></div>
      </div>
      <div class="feld"><label class="feld__label" for="l-notiz">Notiz</label>
        <input class="eingabe" id="l-notiz" name="notiz" placeholder="Wind, Platzzustand, Besonderheiten"></div>
    </div>
    <div class="modal__fuss">
      <div class="fueller"></div>
      <button type="button" class="btn" data-modal-zu>Abbrechen</button>
      <button type="submit" class="btn btn--primaer">Speichern</button>
    </div>
  </form>
</dialog>

<?php if (Auth::darf('bookings.write')): ?>
<dialog class="modal modal--schmal" id="modal-paket">
  <form method="post">
    <?= Auth::csrfFeld() ?>
    <input type="hidden" name="aktion" value="paket_gutschreiben">
    <div class="modal__kopf"><h2>Paket gutschreiben</h2>
      <button type="button" class="rundknopf" data-modal-zu aria-label="Schließen"><?= Icon::svg('x', 17) ?></button>
    </div>
    <div class="modal__koerper">
      <div class="feld">
        <label class="feld__label" for="p-paket">Paket</label>
        <select id="p-paket" name="package_id" required>
          <?php foreach (Tenant::all('packages', 'aktiv = 1', [], 'position') as $pk): ?>
            <option value="<?= (int) $pk['id'] ?>">
              <?= Util::h((string) $pk['name']) ?> · <?= (int) $pk['einheiten'] ?> Einheiten ·
              <?= Util::h(Util::geldKurz((int) $pk['preis_cent'])) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="hinweis hinweis--still">
        <?= Icon::svg('info', 16) ?>
        <div class="hinweis__text">Schreibt das Guthaben sofort gut – ohne Bestellung und ohne Rechnung.
          Für bar oder per Überweisung bezahlte Pakete.</div>
      </div>
    </div>
    <div class="modal__fuss">
      <div class="fueller"></div>
      <button type="button" class="btn" data-modal-zu>Abbrechen</button>
      <button type="submit" class="btn btn--primaer">Gutschreiben</button>
    </div>
  </form>
</dialog>
<?php endif; ?>

<?php if (Auth::darf('customers.delete')): ?>
<dialog class="modal modal--schmal" id="modal-loeschen">
  <form method="post">
    <?= Auth::csrfFeld() ?>
    <input type="hidden" name="aktion" value="loeschen">
    <div class="modal__kopf"><h2>Kunde löschen</h2></div>
    <div class="modal__koerper">
      <p>Alle Daten von <strong><?= Util::h(Customers::name($kunde)) ?></strong> werden gelöscht:
        Termine, Pakete, Notizen, Videos, Leistungsdaten und Nachrichten.</p>
      <div class="hinweis hinweis--warnung">
        <?= Icon::svg('alert', 17) ?>
        <div class="hinweis__text">Bezahlte Rechnungen bleiben bestehen – dafür gilt die
          handelsrechtliche Aufbewahrungsfrist. Der Bezug zur Person wird gekappt.</div>
      </div>
      <p class="klein gedimmt mt-4">Das lässt sich nicht rückgängig machen.</p>
    </div>
    <div class="modal__fuss">
      <div class="fueller"></div>
      <button type="button" class="btn" data-modal-zu>Abbrechen</button>
      <button type="submit" class="btn btn--gefahr">Endgültig löschen</button>
    </div>
  </form>
</dialog>
<?php endif; ?>

<?php require __DIR__ . '/partials/fuss.php'; ?>
