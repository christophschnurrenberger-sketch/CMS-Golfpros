<?php
/**
 * Newsletter schreiben.
 *
 * Derselbe Baukastengedanke wie bei der Website, nur mit E-Mail-tauglichen
 * Bausteinen. Versendet wird in Schüben von 25 – ein Shared Host bricht bei
 * tausend Mails in einer Anfrage ab, und ein halb versendeter Newsletter
 * ohne Fortschrittsanzeige ist das Schlimmste, was passieren kann.
 */
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/partials/helfer.php';

Auth::fordern('modul.newsletter');

$istNeu = App::get('id') === 'neu';
$id = $istNeu ? 0 : App::getInt('id');
$kampagne = $istNeu ? null : Tenant::find('campaigns', $id);

if (!$istNeu && !$kampagne) {
    App::melden('Dieser Newsletter wurde nicht gefunden.', 'fehler');
    App::weiter('/app/newsletter.php');
}

if (App::istPost()) {
    Auth::csrfFordern();
    Auth::fordern('newsletter.write');
    $aktion = App::aktion();

    if ($aktion === 'speichern') {
        $bloecke = [];
        $typen = App::postListe('block_typ');
        foreach ($typen as $i => $typ) {
            $daten = [];
            foreach (['text', 'url', 'titel', 'preis', 'alt'] as $feld) {
                $werte = App::postListe('block_' . $feld);
                if (isset($werte[$i]) && trim((string) $werte[$i]) !== '') {
                    $daten[$feld] = (string) $werte[$i];
                }
            }
            if ($daten !== [] || $typ === 'trenner') {
                $bloecke[] = ['typ' => $typ, 'daten' => $daten];
            }
        }
        $daten = [
            'name' => App::post('name') ?: 'Newsletter',
            'betreff' => App::post('betreff'),
            'vorschautext' => App::post('vorschautext'),
            'segment_id' => App::postInt('segment_id'),
            'ab_test' => App::postBool('ab_test') ? 1 : 0,
            'ab_betreff' => App::post('ab_betreff'),
            'bloecke' => Util::json($bloecke),
            'geplant' => App::post('geplant') !== '' ? App::post('geplant') . ' 09:00:00' : null,
        ];
        $neuId = Campaigns::speichern($daten, $id);
        App::melden('Newsletter gespeichert.');
        App::weiter('/app/kampagne.php?id=' . $neuId);
    }

    if ($aktion === 'baustein' && $id > 0) {
        $bloecke = Util::ausJson((string) $kampagne['bloecke'], []);
        $bloecke[] = ['typ' => App::post('typ', 'text'), 'daten' => []];
        Campaigns::speichern(['bloecke' => Util::json($bloecke)], $id);
        App::weiter('/app/kampagne.php?id=' . $id);
    }

    if ($aktion === 'test' && $id > 0) {
        $benutzer = Auth::benutzer();
        Mail::senden((string) $benutzer['email'], '[Test] ' . $kampagne['betreff'],
            Campaigns::text($kampagne), ['html' => str_replace('{{abmelden}}', '#',
                Campaigns::html($kampagne))]);
        App::melden('Testmail an ' . $benutzer['email'] . ' geschickt.');
        App::weiter('/app/kampagne.php?id=' . $id);
    }

    if ($aktion === 'versenden' && $id > 0) {
        $ergebnis = Campaigns::versenden($id, 25);
        App::melden($ergebnis['offen'] > 0
            ? $ergebnis['gesendet'] . ' Mails versendet, ' . $ergebnis['offen']
              . ' offen. Auf „Weiter versenden“ klicken.'
            : $ergebnis['gesendet'] . ' Mails versendet. Der Newsletter ist raus.',
            $ergebnis['offen'] > 0 ? 'info' : 'erfolg');
        App::weiter('/app/kampagne.php?id=' . $id);
    }

    if ($aktion === 'loeschen' && $id > 0) {
        Tenant::delete('campaigns', $id);
        App::melden('Newsletter gelöscht.');
        App::weiter('/app/newsletter.php');
    }
}

if ($istNeu) {
    $id = Campaigns::speichern([
        'name' => 'Newsletter ' . Util::datum(Util::heute()),
        'betreff' => '',
        'bloecke' => Util::json([
            ['typ' => 'ueberschrift', 'daten' => ['text' => 'Hallo {vorname},']],
            ['typ' => 'text', 'daten' => ['text' => "hier kommt dein Tipp für diese Woche.\n\n"
                . "Schreib hier, was du zu sagen hast – eine Übung, ein Gedanke, ein Termin."]],
            ['typ' => 'knopf', 'daten' => ['text' => 'Termin buchen', 'url' => App::absolut('/')]],
        ]),
        'status' => 'entwurf',
    ]);
    App::weiter('/app/kampagne.php?id=' . $id);
}

/*
 * Baustein anhängen per Verweis, nicht per verschachteltem Formular –
 * ein <form> in einem <form> ist ungültiges HTML, und Browser lösen es
 * unterschiedlich auf.
 */
if (App::get('hinzu') !== '' && Auth::darf('newsletter.write')) {
    $bloecke = Util::ausJson((string) $kampagne['bloecke'], []);
    $bloecke[] = ['typ' => App::get('hinzu'), 'daten' => []];
    Campaigns::speichern(['bloecke' => Util::json($bloecke)], $id);
    App::weiter('/app/kampagne.php?id=' . $id);
}

$bloecke = Util::ausJson((string) $kampagne['bloecke'], []);
$empfaengerZahl = count(Campaigns::empfaenger($id));
$q = Campaigns::quoten($kampagne);
$versendet = (string) $kampagne['status'] === 'versendet';

$titel = (string) $kampagne['name'];
$unter = $versendet
    ? 'Versendet am ' . Util::datum((string) $kampagne['versendet']) . ' an '
      . (int) $kampagne['empfaenger'] . ' Empfänger'
    : $empfaengerZahl . ' Empfänger würden diesen Newsletter bekommen';
$brotkrumen = [['Newsletter', '/app/newsletter.php'], [(string) $kampagne['name'], null]];

$aktionen = '';
if (!$versendet && Auth::darf('newsletter.write')) {
    $aktionen .= '<form method="post" style="display:inline">' . Auth::csrfFeld()
               . '<input type="hidden" name="aktion" value="test">'
               . '<button class="btn" type="submit">' . Icon::svg('send', 15) . ' Testmail</button></form>'
               . '<button class="btn btn--primaer" data-modal-auf="modal-versenden">'
               . Icon::svg('send', 15) . ' Versenden</button>';
} elseif ((int) $kampagne['empfaenger'] > 0 && (string) $kampagne['status'] === 'geplant') {
    $aktionen .= '<form method="post" style="display:inline">' . Auth::csrfFeld()
               . '<input type="hidden" name="aktion" value="versenden">'
               . '<button class="btn btn--primaer" type="submit">' . Icon::svg('send', 15)
               . ' Weiter versenden</button></form>';
}
require __DIR__ . '/partials/kopf.php';
?>

<?php if ($versendet): ?>
  <div class="raster raster--4 mb-5">
    <?= kennzahl('Empfänger', Util::zahl((int) $kampagne['empfaenger']), ['icon' => 'customers']) ?>
    <?= kennzahl('Geöffnet', Util::prozent($q['oeffnung'], 1), ['icon' => 'eye',
          'fuss' => (int) $kampagne['geoeffnet'] . ' Personen']) ?>
    <?= kennzahl('Geklickt', Util::prozent($q['klick'], 1), ['icon' => 'link',
          'fuss' => (int) $kampagne['geklickt'] . ' Klicks']) ?>
    <?= kennzahl('Abmeldungen', Util::prozent($q['abmeldung'], 2), ['icon' => 'logout',
          'fuss' => (int) $kampagne['abgemeldet'] . ' Personen']) ?>
  </div>
<?php endif; ?>

<form method="post" class="raster raster--haupt-neben">
  <?= Auth::csrfFeld() ?>
  <input type="hidden" name="aktion" value="speichern">

  <div class="stapel">
    <div class="karte">
      <div class="karte__kopf"><h2>Inhalt</h2>
        <div class="fueller"></div>
        <span class="klein gedimmt">{vorname} wird ersetzt</span></div>
      <div class="karte__koerper">
        <div class="feld">
          <label class="feld__label" for="betreff">Betreff</label>
          <input class="eingabe" id="betreff" name="betreff" maxlength="120" data-zaehler="z-betreff"
                 value="<?= Util::attr((string) $kampagne['betreff']) ?>"
                 placeholder="Die Saison beginnt – hast du einen Plan?">
          <div class="feld__hinweis" id="z-betreff"></div>
        </div>
        <div class="feld">
          <label class="feld__label" for="vorschautext">Vorschautext</label>
          <input class="eingabe" id="vorschautext" name="vorschautext"
                 value="<?= Util::attr((string) $kampagne['vorschautext']) ?>"
                 placeholder="Steht im Postfach hinter dem Betreff.">
        </div>

        <hr>

        <?php foreach ($bloecke as $i => $b):
          $d = (array) ($b['daten'] ?? []);
          $typ = (string) ($b['typ'] ?? 'text'); ?>
          <div style="border:1px solid var(--rand);border-radius:var(--radius);padding:var(--r4);margin-bottom:var(--r3)">
            <input type="hidden" name="block_typ[]" value="<?= Util::attr($typ) ?>">
            <div class="reihe reihe--eng mb-3">
              <?= pille(['ueberschrift' => 'Überschrift', 'text' => 'Text', 'knopf' => 'Knopf',
                         'bild' => 'Bild', 'angebot' => 'Angebot', 'trenner' => 'Trennlinie'][$typ] ?? $typ, 'offen') ?>
            </div>
            <?php if ($typ === 'ueberschrift'): ?>
              <input class="eingabe" name="block_text[]" value="<?= Util::attr((string) ($d['text'] ?? '')) ?>"
                     placeholder="Überschrift" aria-label="Überschrift">
              <input type="hidden" name="block_url[]" value=""><input type="hidden" name="block_titel[]" value="">
              <input type="hidden" name="block_preis[]" value=""><input type="hidden" name="block_alt[]" value="">
            <?php elseif ($typ === 'text'): ?>
              <textarea class="eingabe" name="block_text[]" rows="5" data-waechst
                        aria-label="Text"><?= Util::h((string) ($d['text'] ?? '')) ?></textarea>
              <input type="hidden" name="block_url[]" value=""><input type="hidden" name="block_titel[]" value="">
              <input type="hidden" name="block_preis[]" value=""><input type="hidden" name="block_alt[]" value="">
            <?php elseif ($typ === 'knopf'): ?>
              <div class="feld-reihe feld-reihe--2">
                <input class="eingabe" name="block_text[]" value="<?= Util::attr((string) ($d['text'] ?? '')) ?>"
                       placeholder="Beschriftung" aria-label="Beschriftung">
                <input class="eingabe" name="block_url[]" value="<?= Util::attr((string) ($d['url'] ?? '')) ?>"
                       placeholder="https://" aria-label="Ziel">
              </div>
              <input type="hidden" name="block_titel[]" value=""><input type="hidden" name="block_preis[]" value="">
              <input type="hidden" name="block_alt[]" value="">
            <?php elseif ($typ === 'angebot'): ?>
              <div class="feld"><input class="eingabe" name="block_titel[]"
                     value="<?= Util::attr((string) ($d['titel'] ?? '')) ?>" placeholder="Golf Starter 5"
                     aria-label="Angebotstitel"></div>
              <div class="feld"><textarea class="eingabe" name="block_text[]" rows="2" data-waechst
                     placeholder="Worum geht es?" aria-label="Angebotstext"><?= Util::h((string) ($d['text'] ?? '')) ?></textarea></div>
              <input class="eingabe" name="block_preis[]" value="<?= Util::attr((string) ($d['preis'] ?? '')) ?>"
                     placeholder="399 €" aria-label="Preis">
              <input type="hidden" name="block_url[]" value=""><input type="hidden" name="block_alt[]" value="">
            <?php elseif ($typ === 'bild'): ?>
              <div class="feld-reihe feld-reihe--2">
                <input class="eingabe" name="block_url[]" value="<?= Util::attr((string) ($d['url'] ?? '')) ?>"
                       placeholder="Bildadresse" aria-label="Bild">
                <input class="eingabe" name="block_alt[]" value="<?= Util::attr((string) ($d['alt'] ?? '')) ?>"
                       placeholder="Bildbeschreibung" aria-label="Beschreibung">
              </div>
              <input type="hidden" name="block_text[]" value=""><input type="hidden" name="block_titel[]" value="">
              <input type="hidden" name="block_preis[]" value="">
            <?php else: ?>
              <p class="klein gedimmt">Trennlinie</p>
              <input type="hidden" name="block_text[]" value="-"><input type="hidden" name="block_url[]" value="">
              <input type="hidden" name="block_titel[]" value=""><input type="hidden" name="block_preis[]" value="">
              <input type="hidden" name="block_alt[]" value="">
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="karte__fuss">
        <div class="fueller"></div>
        <button class="btn btn--primaer" type="submit">Speichern</button>
      </div>
    </div>
  </div>

  <div class="stapel">
    <div class="karte">
      <div class="karte__kopf"><h3>Empfänger</h3></div>
      <div class="karte__koerper">
        <div class="feld">
          <label class="feld__label" for="segment_id">Segment</label>
          <select id="segment_id" name="segment_id">
            <option value="0">Alle mit Einwilligung (<?= Tenant::count('customers',
              'newsletter = 1 AND status = "aktiv"') ?>)</option>
            <?php foreach (Segments::alle() as $s): ?>
              <option value="<?= (int) $s['id'] ?>"<?= (int) $kampagne['segment_id'] === (int) $s['id'] ? ' selected' : '' ?>>
                <?= Util::h((string) $s['name']) ?> (<?= Segments::anzahl($s) ?>)</option>
            <?php endforeach; ?>
          </select>
          <div class="feld__hinweis">Innerhalb des Segments bekommen nur Kunden mit Einwilligung die Mail.</div>
        </div>
        <div class="hinweis hinweis--still">
          <?= Icon::svg('customers', 16) ?>
          <div class="hinweis__text"><strong><?= $empfaengerZahl ?></strong> Empfänger nach
            aktueller Auswahl.</div>
        </div>
      </div>
    </div>

    <div class="karte">
      <div class="karte__kopf"><h3>Bausteine</h3></div>
      <div class="karte__koerper karte__koerper--eng">
        <div class="stapel stapel--eng">
          <?php foreach (['ueberschrift' => ['Überschrift', 'type'], 'text' => ['Text', 'content'],
                          'knopf' => ['Knopf', 'zap'], 'angebot' => ['Angebot', 'gift'],
                          'bild' => ['Bild', 'image'], 'trenner' => ['Trennlinie', 'minus']] as $k => [$n, $ikon]): ?>
            <a class="bau-vorrat__teil"
               href="<?= Util::attr(App::url('/app/kampagne.php?id=' . $id . '&hinzu=' . $k)) ?>">
              <?= Icon::svg($ikon, 15) ?> <span><?= Util::h($n) ?></span>
              <span class="fueller"></span><?= Icon::svg('plus', 13) ?>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <div class="karte">
      <div class="karte__kopf"><h3>A/B-Test</h3></div>
      <div class="karte__koerper">
        <label class="haken mb-3">
          <input type="checkbox" name="ab_test" value="1"<?= (int) $kampagne['ab_test'] === 1 ? ' checked' : '' ?>>
          <span class="haken__text">Zwei Betreffzeilen testen
            <span class="haken__hinweis">Die Hälfte bekommt die zweite Zeile. Nach dem Versand
              siehst du, welche besser geöffnet wurde.</span></span>
        </label>
        <div class="feld">
          <label class="feld__label" for="ab_betreff">Zweiter Betreff</label>
          <input class="eingabe" id="ab_betreff" name="ab_betreff"
                 value="<?= Util::attr((string) $kampagne['ab_betreff']) ?>">
        </div>
        <div class="feld">
          <label class="feld__label" for="geplant">Planen für</label>
          <input class="eingabe" id="geplant" type="date" name="geplant"
                 value="<?= Util::attr($kampagne['geplant'] ? date('Y-m-d', strtotime((string) $kampagne['geplant'])) : '') ?>">
          <div class="feld__hinweis">Ohne Cronjob startet der Versand beim nächsten Aufruf des Dashboards.</div>
        </div>
        <div class="feld">
          <label class="feld__label" for="name">Interner Name</label>
          <input class="eingabe" id="name" name="name" value="<?= Util::attr((string) $kampagne['name']) ?>">
        </div>
      </div>
    </div>
  </div>
</form>

<div class="karte mt-5">
  <div class="karte__kopf"><h3>Vorschau</h3>
    <div class="fueller"></div>
    <span class="klein gedimmt">so kommt der Newsletter an</span></div>
  <div class="karte__koerper" style="background:var(--flaeche-3)">
    <div style="max-width:600px;margin:0 auto;background:#fff;border-radius:14px;overflow:hidden;
                border:1px solid var(--rand)">
      <iframe srcdoc="<?= Util::attr(str_replace('{{abmelden}}', '#', Campaigns::html($kampagne))) ?>"
              style="width:100%;height:560px;border:0;display:block" title="Newsletter-Vorschau"></iframe>
    </div>
  </div>
</div>

<?php if (!$versendet && Auth::darf('newsletter.write')): ?>
<dialog class="modal modal--schmal" id="modal-versenden">
  <form method="post">
    <?= Auth::csrfFeld() ?>
    <input type="hidden" name="aktion" value="versenden">
    <div class="modal__kopf"><h2>Newsletter versenden</h2></div>
    <div class="modal__koerper">
      <p>Der Newsletter geht an <strong><?= $empfaengerZahl ?> Empfänger</strong> mit
        Newsletter-Einwilligung.</p>
      <div class="hinweis hinweis--still">
        <?= Icon::svg('info', 16) ?>
        <div class="hinweis__text">Versendet wird in Schüben von 25. Bei mehr Empfängern
          erscheint danach „Weiter versenden“ – so bricht auch günstiges Hosting nicht ab.</div>
      </div>
      <p class="klein gedimmt mt-4">Vorher eine Testmail an dich selbst schicken ist eine gute
        Gewohnheit. Versendetes lässt sich nicht zurückholen.</p>
    </div>
    <div class="modal__fuss"><div class="fueller"></div>
      <button type="button" class="btn" data-modal-zu>Abbrechen</button>
      <button type="submit" class="btn btn--primaer">Jetzt versenden</button></div>
  </form>
</dialog>
<?php endif; ?>

<?php if (Auth::darf('newsletter.write') && !$versendet): ?>
<form method="post" class="mt-4" data-bestaetigen="Diesen Newsletter löschen?">
  <?= Auth::csrfFeld() ?>
  <input type="hidden" name="aktion" value="loeschen">
  <button class="btn" type="submit" style="color:var(--gefahr)">
    <?= Icon::svg('trash', 15) ?> Newsletter löschen</button>
</form>
<?php endif; ?>

<?php require __DIR__ . '/partials/fuss.php'; ?>
