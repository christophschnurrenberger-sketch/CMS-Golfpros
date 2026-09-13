<?php
/** Videos und Analysen – Übersicht und Upload. */
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/partials/helfer.php';

Auth::fordern('modul.video');

if (App::istPost()) {
    Auth::csrfFordern();
    Auth::fordern('video.write');

    if (App::aktion() === 'hochladen') {
        $datei = $_FILES['video'] ?? null;
        $pfad  = '';
        $groesse = 0;

        if ($datei && (int) $datei['error'] === UPLOAD_ERR_OK) {
            $erlaubt = ['video/mp4' => 'mp4', 'video/quicktime' => 'mov', 'video/webm' => 'webm'];
            $typ = (string) ($datei['type'] ?? '');
            if (!isset($erlaubt[$typ])) {
                App::melden('Nur MP4, MOV und WebM werden unterstützt.', 'fehler');
                App::weiter('/app/videos.php');
            }
            if ((int) $datei['size'] > 200 * 1024 * 1024) {
                App::melden('Das Video ist größer als 200 MB. Bitte kürzen oder verkleinern.', 'fehler');
                App::weiter('/app/videos.php');
            }
            $ordner = GP_ROOT . '/uploads/w' . Tenant::id() . '/video';
            if (!is_dir($ordner)) {
                @mkdir($ordner, 0750, true);
            }
            $name = 'v' . Util::token(8) . '.' . $erlaubt[$typ];
            if (move_uploaded_file((string) $datei['tmp_name'], $ordner . '/' . $name)) {
                $pfad = 'uploads/w' . Tenant::id() . '/video/' . $name;
                $groesse = (int) $datei['size'];
            }
        }

        $id = Videos::speichern([
            'customer_id' => App::postInt('customer_id'),
            'titel' => App::post('titel') ?: 'Schwungvideo',
            'datei' => $pfad,
            'ansicht' => App::post('ansicht', 'face-on'),
            'schlaeger' => App::post('schlaeger'),
            'groesse' => $groesse,
            'quelle' => $pfad !== '' ? 'upload' : 'ohne_datei',
        ]);
        App::melden($pfad !== ''
            ? 'Video hochgeladen.'
            : 'Eintrag angelegt – ohne Datei. Du kannst die Analyse trotzdem vorbereiten.', $pfad !== '' ? 'erfolg' : 'info');
        App::weiter('/app/videoanalyse.php?video=' . $id);
    }

    if (App::aktion() === 'loeschen') {
        $v = Tenant::find('videos', App::postInt('id'));
        if ($v) {
            if ((string) $v['datei'] !== '' && is_file(GP_ROOT . '/' . $v['datei'])) {
                @unlink(GP_ROOT . '/' . $v['datei']);
            }
            Tenant::deleteWhere('video_analyses', 'video_id = :v', ['v' => (int) $v['id']]);
            Tenant::delete('videos', (int) $v['id']);
            App::melden('Video gelöscht.');
        }
    }
    App::weiter('/app/videos.php');
}

$kundeFilter = App::getInt('kunde');
$wo = $kundeFilter > 0 ? 'customer_id = :k' : '';
$p  = $kundeFilter > 0 ? ['k' => $kundeFilter] : [];
$videos = Tenant::all('videos', $wo, $p, 'id DESC', 60);
$analysen = [];
foreach (Tenant::all('video_analyses') as $a) {
    $analysen[(int) $a['video_id']][] = $a;
}

$titel = 'Videoanalyse';
$unter = count($videos) . ' Videos · ' . Tenant::count('video_analyses') . ' Analysen';
$aktionen = Auth::darf('video.write')
    ? '<button class="btn btn--primaer" data-modal-auf="modal-video">' . Icon::svg('upload', 15) . ' Video hochladen</button>' : '';
require __DIR__ . '/partials/kopf.php';
?>

<?php if ($videos === []): ?>
  <div class="karte"><div class="karte__koerper">
    <?= leerzustand('video', 'Noch kein Video',
          'Eine Aufnahme von vorn und eine von der Ziellinie genügen. Im Analysefenster kannst du '
          . 'Linien, Kreise und Winkel einzeichnen, Einzelbilder durchgehen und deine Anmerkungen '
          . 'schreiben. Der Kunde sieht alles später in seinem Bereich.', []) ?>
    <?php if (Auth::darf('video.write')): ?>
      <div class="mitte">
        <button class="btn btn--primaer" data-modal-auf="modal-video">
          <?= Icon::svg('upload', 15) ?> Erstes Video hochladen</button>
      </div>
    <?php endif; ?>
  </div></div>
<?php else: ?>
  <div class="raster raster--auto-gross">
    <?php foreach ($videos as $v):
      $meine = $analysen[(int) $v['id']] ?? [];
      $kunde = (int) $v['customer_id'] > 0 ? Tenant::find('customers', (int) $v['customer_id']) : null; ?>
      <div class="karte">
        <a href="<?= Util::attr(App::url('/app/videoanalyse.php?video=' . (int) $v['id'])) ?>"
           style="display:block;color:inherit">
          <div style="aspect-ratio:16/10;background:#0b0d0e;display:grid;place-items:center;
                      border-radius:var(--radius-m) var(--radius-m) 0 0;color:#5a5a54;position:relative">
            <?php if ((string) $v['datei'] !== ''): ?>
              <video src="<?= Util::attr(App::url((string) $v['datei'])) ?>" preload="metadata"
                     style="width:100%;height:100%;object-fit:contain"></video>
            <?php else: ?>
              <?= Icon::svg('video', 34) ?>
            <?php endif; ?>
            <span style="position:absolute;inset:0;display:grid;place-items:center">
              <span style="width:46px;height:46px;border-radius:50%;background:rgba(255,255,255,.92);
                           display:grid;place-items:center;color:#1a1a18">
                <?= Icon::svg('play', 19) ?></span>
            </span>
          </div>
        </a>
        <div class="karte__koerper karte__koerper--eng">
          <div class="halbfett"><?= Util::h((string) $v['titel']) ?></div>
          <div class="klein gedimmt">
            <?= Util::h(Videos::ANSICHTEN[(string) $v['ansicht']] ?? '') ?>
            <?php if ((string) $v['schlaeger'] !== ''): ?> · <?= Util::h((string) $v['schlaeger']) ?><?php endif; ?>
          </div>
          <div class="reihe reihe--eng mt-3">
            <?php if ($kunde): ?>
              <a class="klein" href="<?= Util::attr(App::url('/app/kunde.php?id=' . (int) $kunde['id'])) ?>">
                <?= Util::h(Customers::name($kunde)) ?></a>
            <?php endif; ?>
            <div class="fueller"></div>
            <?php if ($meine !== []): ?>
              <?= pille(count($meine) . ' Analyse' . (count($meine) === 1 ? '' : 'n'), 'erfolg') ?>
            <?php else: ?>
              <?= pille('ohne Analyse', 'warnung') ?>
            <?php endif; ?>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if (Auth::darf('video.write')): ?>
<dialog class="modal" id="modal-video">
  <form method="post" enctype="multipart/form-data">
    <?= Auth::csrfFeld() ?>
    <input type="hidden" name="aktion" value="hochladen">
    <div class="modal__kopf"><h2>Video hochladen</h2>
      <button type="button" class="rundknopf" data-modal-zu aria-label="Schließen"><?= Icon::svg('x', 17) ?></button></div>
    <div class="modal__koerper">
      <div class="feld">
        <label class="ablage" for="v-datei">
          <div class="ablage__symbol"><?= Icon::svg('upload', 24) ?></div>
          <div class="halbfett">Video auswählen</div>
          <div class="klein gedimmt">MP4, MOV oder WebM · bis 200 MB</div>
          <input id="v-datei" type="file" name="video" accept="video/mp4,video/quicktime,video/webm"
                 style="position:absolute;opacity:0;width:1px;height:1px">
        </label>
        <div class="feld__hinweis">Kurze Aufnahmen genügen – fünf bis zehn Sekunden je Schwung.</div>
      </div>
      <div class="feld-reihe feld-reihe--2">
        <div class="feld"><label class="feld__label" for="v-kunde">Kunde</label>
          <select id="v-kunde" name="customer_id">
            <option value="0">Ohne Zuordnung</option>
            <?php foreach (Tenant::all('customers', "status = 'aktiv'", [], 'nachname') as $k): ?>
              <option value="<?= (int) $k['id'] ?>"<?= $kundeFilter === (int) $k['id'] ? ' selected' : '' ?>>
                <?= Util::h(Customers::name($k)) ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="feld"><label class="feld__label" for="v-ansicht">Perspektive</label>
          <select id="v-ansicht" name="ansicht">
            <?php foreach (Videos::ANSICHTEN as $k => $n): ?>
              <option value="<?= $k ?>"><?= Util::h($n) ?></option>
            <?php endforeach; ?>
          </select></div>
      </div>
      <div class="feld-reihe feld-reihe--2">
        <div class="feld"><label class="feld__label" for="v-titel">Titel</label>
          <input class="eingabe" id="v-titel" name="titel" placeholder="Driver von vorn"></div>
        <div class="feld"><label class="feld__label" for="v-schlaeger">Schläger</label>
          <input class="eingabe" id="v-schlaeger" name="schlaeger" placeholder="Eisen 7"></div>
      </div>
    </div>
    <div class="modal__fuss"><div class="fueller"></div>
      <button type="button" class="btn" data-modal-zu>Abbrechen</button>
      <button type="submit" class="btn btn--primaer">Hochladen</button></div>
  </form>
</dialog>
<script>
document.getElementById('v-datei').addEventListener('change', function () {
  var l = this.closest('.ablage');
  if (this.files && this.files[0]) {
    l.querySelector('.halbfett').textContent = this.files[0].name;
    l.classList.add('ist-drueber');
  }
});
</script>
<?php endif; ?>

<?php require __DIR__ . '/partials/fuss.php'; ?>
