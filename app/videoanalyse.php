<?php
/**
 * Analysefenster.
 *
 * Links das Video mit Zeichenfläche und Einzelbildschritt, rechts die
 * Auswertung. Der wichtigste Punkt steht im Aufbau: **KI-Hinweise und
 * Pro-Analyse sind zwei getrennte Blöcke.** Die Einschätzung des Trainers
 * ist die verbindliche; die KI liefert Prüfpunkte, die er übernimmt oder
 * verwirft. Ein gemeinsames Textfeld würde diese Grenze verwischen – und
 * genau darum geht es hier.
 */
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/partials/helfer.php';

Auth::fordern('modul.video');

$videoId   = App::getInt('video');
$analyseId = App::getInt('analyse');
$video     = $videoId > 0 ? Tenant::find('videos', $videoId) : null;
$analyse   = $analyseId > 0 ? Tenant::find('video_analyses', $analyseId) : null;

if ($analyse && !$video) {
    $video = Tenant::find('videos', (int) $analyse['video_id']);
}
if (!$video) {
    App::melden('Dieses Video wurde nicht gefunden.', 'fehler');
    App::weiter('/app/videos.php');
}
if (!$analyse) {
    $analyse = Tenant::one('video_analyses', 'video_id = :v', ['v' => (int) $video['id']], 'id DESC');
}

if (App::istPost()) {
    Auth::csrfFordern();
    Auth::fordern('video.write');
    $aktion = App::aktion();

    if ($aktion === 'speichern') {
        $daten = [
            'video_id' => (int) $video['id'],
            'customer_id' => (int) $video['customer_id'],
            'titel' => App::post('titel') ?: ('Analyse ' . $video['titel']),
            'zeichnungen' => App::postRoh('zeichnungen'),
            'pro_analyse' => App::postRoh('pro_analyse'),
            'empfehlungen' => App::post('empfehlungen'),
            'ai_bestaetigt' => App::postBool('ai_bestaetigt') ? 1 : 0,
            'status' => App::post('status', 'entwurf'),
        ];
        if ($analyse === null) {
            $daten['ai_analyse'] = Util::json(Videos::pruefpunkte((string) $video['ansicht']));
        }
        $neueId = Videos::analyseSpeichern($daten, (int) ($analyse['id'] ?? 0));
        App::melden('Analyse gespeichert.');
        App::weiter('/app/videoanalyse.php?analyse=' . $neueId);
    }

    if ($aktion === 'freigeben' && $analyse) {
        Videos::freigeben((int) $analyse['id']);
        App::melden('Analyse freigegeben. Der Kunde hat eine E-Mail bekommen.');
        App::weiter('/app/videoanalyse.php?analyse=' . (int) $analyse['id']);
    }
}

$kunde   = (int) $video['customer_id'] > 0 ? Tenant::find('customers', (int) $video['customer_id']) : null;
$kiDaten = $analyse ? Util::ausJson((string) $analyse['ai_analyse'], []) : Videos::pruefpunkte((string) $video['ansicht']);
$weitere = Tenant::all('videos', 'customer_id = :k AND id != :v',
    ['k' => (int) $video['customer_id'], 'v' => (int) $video['id']], 'id DESC', 6);

$titel = (string) $video['titel'];
$unter = Util::h(Videos::ANSICHTEN[(string) $video['ansicht']] ?? '')
       . ((string) $video['schlaeger'] !== '' ? ' · ' . Util::h((string) $video['schlaeger']) : '')
       . ($kunde ? ' · <a href="' . App::url('/app/kunde.php?id=' . (int) $kunde['id']) . '">'
                   . Util::h(Customers::name($kunde)) . '</a>' : '');
$brotkrumen = [['Videoanalyse', '/app/videos.php'], [(string) $video['titel'], null]];

$aktionen = '';
if ($analyse && (string) $analyse['status'] !== 'freigegeben' && Auth::darf('video.write')) {
    $aktionen .= '<form method="post" style="display:inline">' . Auth::csrfFeld()
               . '<input type="hidden" name="aktion" value="freigeben">'
               . '<button class="btn btn--primaer" type="submit">' . Icon::svg('send', 15)
               . ' Für den Kunden freigeben</button></form>';
} elseif ($analyse) {
    $aktionen .= '<span class="pille pille--erfolg pille--gross">' . Icon::svg('check', 13)
               . ' Freigegeben ' . Util::h(Util::datum((string) $analyse['freigegeben'])) . '</span>';
}
$inhaltKlasse = 'inhalt--breit';
$skripte = '<script src="' . Util::attr(App::asset('assets/js/video.js')) . '"></script>';
require __DIR__ . '/partials/kopf.php';
?>

<form method="post" class="video-arbeit">
  <?= Auth::csrfFeld() ?>
  <input type="hidden" name="aktion" value="speichern">
  <input type="hidden" name="zeichnungen" id="zeichnungen-feld"
         value="<?= Util::attr((string) ($analyse['zeichnungen'] ?? '[]')) ?>">

  <div>
    <div class="video-buehne">
      <?php if ((string) $video['datei'] !== '' && is_file(GP_ROOT . '/' . $video['datei'])): ?>
        <video id="analyse-video" src="<?= Util::attr(App::url('/datei.php?art=video&id=' . (int) $video['id'])) ?>"
               playsinline preload="auto"></video>
      <?php else: ?>
        <div class="video-buehne__platzhalter">
          <?= Icon::svg('video', 34) ?>
          <p class="mt-3">Zu diesem Eintrag liegt keine Videodatei vor.<br>
            Zeichnen und Analysieren funktionieren trotzdem.</p>
        </div>
      <?php endif; ?>
      <canvas class="video-buehne__leinwand" id="zeichenflaeche"></canvas>
    </div>

    <div class="video-leiste">
      <button type="button" class="btn btn--klein btn--nur-symbol" data-video="anfang" aria-label="Zum Anfang">
        <?= Icon::svg('chevron-left', 15) ?></button>
      <button type="button" class="btn btn--klein btn--nur-symbol" data-video="zurueck" aria-label="Ein Bild zurück">
        <?= Icon::svg('arrow-left', 15) ?></button>
      <button type="button" class="btn btn--primaer btn--nur-symbol" data-video="abspielen" aria-label="Abspielen">
        <?= Icon::svg('play', 15) ?></button>
      <button type="button" class="btn btn--klein btn--nur-symbol" data-video="vor" aria-label="Ein Bild vor">
        <?= Icon::svg('arrow-right', 15) ?></button>

      <span class="video-spur" id="video-spur"><span class="video-spur__fortschritt" id="video-fortschritt"></span></span>
      <span class="video-zeit" id="video-zeit">0:00.0 / 0:00.0</span>

      <div class="segment">
        <?php foreach (['0.15' => '¼×', '0.25' => '⅓×', '0.5' => '½×', '1' => '1×'] as $wert => $name): ?>
          <button type="button" class="segment__teil<?= $wert === '1' ? ' ist-aktiv' : '' ?>"
                  data-tempo="<?= $wert ?>"><?= $name ?></button>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="karte mt-4">
      <div class="karte__koerper karte__koerper--eng">
        <div class="reihe reihe--umbruch">
          <div class="werkzeuge">
            <?php foreach (Videos::WERKZEUGE as $k => [$name, $icon]): ?>
              <button type="button" class="werkzeug<?= $k === 'linie' ? ' ist-aktiv' : '' ?>"
                      data-werkzeug="<?= $k ?>" title="<?= Util::attr($name) ?>" aria-label="<?= Util::attr($name) ?>">
                <?= Icon::svg($icon, 15) ?></button>
            <?php endforeach; ?>
          </div>
          <div class="werkzeuge">
            <?php foreach (['#e0663c', '#3b6ef6', '#17794f', '#b08829', '#ffffff'] as $i => $f): ?>
              <button type="button" class="werkzeug" data-farbe="<?= $f ?>"
                      style="background:<?= $f ?>;border-color:var(--rand-2)<?= $i === 0 ? ';outline:2px solid var(--text);outline-offset:2px' : '' ?>"
                      aria-label="Farbe <?= $i + 1 ?>"></button>
            <?php endforeach; ?>
          </div>
          <div class="fueller"></div>
          <span class="klein gedimmt"><span id="formen-zahl"><?= count(Util::ausJson((string) ($analyse['zeichnungen'] ?? ''), [])) ?></span> Markierungen</span>
          <button type="button" class="btn btn--klein" id="knopf-zurueck">
            <?= Icon::svg('refresh', 14) ?> Rückgängig</button>
          <button type="button" class="btn btn--klein" id="knopf-leeren">
            <?= Icon::svg('trash', 14) ?> Leeren</button>
        </div>
        <div class="klein gedimmt mt-3">
          Leertaste spielt ab, Pfeiltasten gehen Einzelbilder durch.
          Markierungen liegen als Koordinaten im Datensatz und bleiben bearbeitbar.
        </div>
      </div>
    </div>
  </div>

  <div class="stapel">
    <div class="karte">
      <div class="karte__kopf">
        <h3><?= Icon::svg('customers', 16) ?> Pro-Analyse</h3>
        <div class="fueller"></div>
        <span class="pille pille--marke">verbindlich</span>
      </div>
      <div class="karte__koerper">
        <div class="feld">
          <label class="feld__label" for="titel">Titel</label>
          <input class="eingabe" id="titel" name="titel"
                 value="<?= Util::attr((string) ($analyse['titel'] ?? ('Analyse ' . $video['titel']))) ?>">
        </div>
        <div class="feld">
          <label class="feld__label" for="pro_analyse">Deine Einschätzung</label>
          <textarea class="eingabe" id="pro_analyse" name="pro_analyse" rows="10" data-waechst
            placeholder="Was fällt auf? Was ist die Ursache, was die Folge? Welche eine Sache soll der Kunde als Nächstes verändern?"><?= Util::h((string) ($analyse['pro_analyse'] ?? '')) ?></textarea>
          <div class="feld__hinweis">Dieser Text geht an den Kunden. Eine Sache konkret ist mehr wert als fünf allgemeine.</div>
        </div>
        <div class="feld">
          <label class="feld__label" for="empfehlungen">Übungen zum Mitnehmen</label>
          <input class="eingabe" id="empfehlungen" name="empfehlungen"
                 value="<?= Util::attr((string) ($analyse['empfehlungen'] ?? '')) ?>"
                 placeholder="Rhythmus mit Füßen zusammen, Divot nach dem Ball">
        </div>
        <div class="feld">
          <label class="feld__label" for="status">Status</label>
          <select id="status" name="status">
            <option value="entwurf"<?= (string) ($analyse['status'] ?? 'entwurf') === 'entwurf' ? ' selected' : '' ?>>
              Entwurf – der Kunde sieht nichts</option>
            <option value="freigegeben"<?= (string) ($analyse['status'] ?? '') === 'freigegeben' ? ' selected' : '' ?>>
              Freigegeben</option>
          </select>
        </div>
      </div>
      <?php if (Auth::darf('video.write')): ?>
        <div class="karte__fuss"><div class="fueller"></div>
          <button class="btn btn--primaer" type="submit"><?= Icon::svg('save', 15) ?> Analyse speichern</button></div>
      <?php endif; ?>
    </div>

    <div class="ki-block">
      <div class="ki-block__kopf"><?= Icon::svg('ai', 15) ?> KI-Hinweise · Vorschlag</div>
      <div class="ki-block__text">
        <p class="klein" style="margin-bottom:var(--r3)">
          Prüfpunkte zur Perspektive „<?= Util::h(Videos::ANSICHTEN[(string) $video['ansicht']] ?? '') ?>“.
          <?= KI::verfuegbar() ? '' : 'Ohne hinterlegten KI-Zugang stehen hier die eingebauten Prüfpunkte.' ?>
        </p>
        <div class="stapel stapel--eng">
          <?php foreach ($kiDaten as $h): ?>
            <div style="padding:9px 11px;background:var(--flaeche);border-radius:var(--radius);
                        border:1px solid var(--rand)">
              <div class="reihe reihe--eng">
                <span class="pille pille--ki"><?= Util::h((string) ($h['bereich'] ?? '')) ?></span>
                <?php if (!empty($h['sicherheit'])): ?>
                  <div class="fueller"></div>
                  <span class="winzig gedimmt-2">Sicherheit: <?= Util::h((string) $h['sicherheit']) ?></span>
                <?php endif; ?>
              </div>
              <div class="klein halbfett mt-2"><?= Util::h((string) ($h['hinweis'] ?? '')) ?></div>
              <?php if (!empty($h['pruefen'])): ?>
                <div class="winzig gedimmt mt-2"><?= Util::h((string) $h['pruefen']) ?></div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="ki-block__fuss">
        <label class="haken">
          <input type="checkbox" name="ai_bestaetigt" value="1"
                 <?= (int) ($analyse['ai_bestaetigt'] ?? 0) === 1 ? ' checked' : '' ?>>
          <span class="haken__text klein">Ich habe die Hinweise geprüft</span>
        </label>
      </div>
      <div class="ki-warnung">
        <?= Icon::svg('info', 13) ?>
        Hinweise, keine Diagnose. Die fachliche Bewertung trifft der Trainer – deshalb stehen
        beide Blöcke getrennt.
      </div>
    </div>

    <?php if ($weitere !== []): ?>
    <div class="karte">
      <div class="karte__kopf"><h3>Weitere Videos dieses Kunden</h3></div>
      <div class="karte__koerper karte__koerper--eng">
        <div class="stapel stapel--eng">
          <?php foreach ($weitere as $w): ?>
            <a class="reihe" href="<?= Util::attr(App::url('/app/videoanalyse.php?video=' . (int) $w['id'])) ?>"
               style="padding:6px;border-radius:8px;color:inherit">
              <span class="kachel-link__symbol" style="width:30px;height:30px"><?= Icon::svg('video', 14) ?></span>
              <div style="flex:1;min-width:0">
                <div class="klein halbfett"><?= Util::h((string) $w['titel']) ?></div>
                <div class="winzig gedimmt-2"><?= Util::h(Util::datum((string) $w['erstellt'])) ?></div>
              </div>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <?php endif; ?>
  </div>
</form>

<?php require __DIR__ . '/partials/fuss.php'; ?>
