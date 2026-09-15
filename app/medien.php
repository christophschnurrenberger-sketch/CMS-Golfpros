<?php
/** Mediathek – Bilder für Website, Beiträge und Newsletter. */
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/partials/helfer.php';

Auth::fordern('modul.content');

if (App::istPost()) {
    Auth::csrfFordern();
    Auth::fordern('content.write');

    if (App::aktion() === 'hochladen' && isset($_FILES['dateien'])) {
        $erlaubt = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
                    'image/svg+xml' => 'svg', 'image/gif' => 'gif', 'application/pdf' => 'pdf'];
        $ordner = GP_ROOT . '/uploads/w' . Tenant::id() . '/medien';
        if (!is_dir($ordner)) {
            @mkdir($ordner, 0750, true);
        }
        $anzahl = 0;
        $abgelehnt = [];
        $grenze = App::uploadGrenze();
        $namen = (array) ($_FILES['dateien']['name'] ?? []);
        foreach (array_keys($namen) as $i) {
            $wie = (string) $namen[$i];

            /*
             * Jeder abgewiesene Upload bekommt einen Grund. Vorher wurden
             * sie stillschweigend uebersprungen, und am Ende stand nur
             * „Nichts hochgeladen" - womit niemand etwas anfangen kann.
             */
            $fehlercode = (int) $_FILES['dateien']['error'][$i];
            if ($fehlercode !== UPLOAD_ERR_OK) {
                $abgelehnt[] = $wie . ' – ' . match ($fehlercode) {
                    UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE =>
                        'zu gross für diesen Server'
                        . ($grenze > 0 ? ' (Grenze: ' . Util::bytes($grenze) . ')' : ''),
                    UPLOAD_ERR_PARTIAL   => 'nur halb angekommen, bitte noch einmal',
                    UPLOAD_ERR_NO_FILE   => 'keine Datei ausgewählt',
                    UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE =>
                        'der Server konnte nicht schreiben – das muss der Hoster beheben',
                    UPLOAD_ERR_EXTENSION => 'vom Server abgelehnt',
                    default              => 'unbekannter Fehler ' . $fehlercode,
                };
                continue;
            }

            $typ = (string) $_FILES['dateien']['type'][$i];
            if (!isset($erlaubt[$typ])) {
                $abgelehnt[] = $wie . ' – Dateityp nicht erlaubt (' . ($typ !== '' ? $typ : 'unbekannt') . ')';
                continue;
            }
            if ((int) $_FILES['dateien']['size'][$i] > 12 * 1024 * 1024) {
                $abgelehnt[] = $wie . ' – über 12 MB';
                continue;
            }
            $name = Util::slug(pathinfo((string) $namen[$i], PATHINFO_FILENAME), 40)
                  . '-' . substr(Util::token(3), 0, 5) . '.' . $erlaubt[$typ];
            if (!move_uploaded_file((string) $_FILES['dateien']['tmp_name'][$i], $ordner . '/' . $name)) {
                $abgelehnt[] = $wie . ' – konnte nicht gespeichert werden. Ist uploads/ beschreibbar?';
                continue;
            }
            $pfad = 'uploads/w' . Tenant::id() . '/medien/' . $name;
            $masse = @getimagesize($ordner . '/' . $name) ?: [0, 0];
            Tenant::insert('media', [
                'dateiname' => (string) $namen[$i], 'pfad' => $pfad, 'mime' => $typ,
                'groesse' => (int) $_FILES['dateien']['size'][$i],
                'breite' => (int) $masse[0], 'hoehe' => (int) $masse[1],
                'user_id' => Auth::id(),
            ]);
            $anzahl++;
        }
        if ($anzahl > 0) {
            App::melden($anzahl === 1 ? 'Eine Datei hochgeladen.' : $anzahl . ' Dateien hochgeladen.', 'erfolg');
        }
        if ($abgelehnt !== []) {
            App::melden('Nicht übernommen: ' . implode(' · ', $abgelehnt), 'fehler');
        }
        if ($anzahl === 0 && $abgelehnt === []) {
            App::melden('Es kam nichts an. Erlaubt sind JPG, PNG, WebP, SVG, GIF und PDF.', 'fehler');
        }
    }

    if (App::aktion() === 'loeschen') {
        $m = Tenant::find('media', App::postInt('id'));
        if ($m) {
            if (is_file(GP_ROOT . '/' . $m['pfad'])) {
                @unlink(GP_ROOT . '/' . $m['pfad']);
            }
            Tenant::delete('media', (int) $m['id']);
            App::melden('Datei gelöscht.');
        }
    }
    App::weiter('/app/medien.php');
}

$medien = Tenant::all('media', '', [], 'id DESC', 200);
$belegt = Tenant::sum('media', 'groesse');

$titel = 'Mediathek';
$unter = (count($medien) === 1 ? 'Eine Datei' : count($medien) . ' Dateien')
       . ' · ' . Util::bytes($belegt) . ' belegt';
$brotkrumen = [['Inhalte', '/app/inhalte.php'], ['Mediathek', null]];
require __DIR__ . '/partials/kopf.php';
?>

<?php if (Auth::darf('content.write')): ?>
<form method="post" enctype="multipart/form-data" class="mb-5">
  <?= Auth::csrfFeld() ?>
  <input type="hidden" name="aktion" value="hochladen">
  <label class="ablage" for="dateien">
    <div class="ablage__symbol"><?= Icon::svg('upload', 24) ?></div>
    <div class="halbfett">Dateien auswählen oder hierher ziehen</div>
    <?php /* Nicht „12 MB" behaupten, wenn der Server bei 2 MB dichtmacht. */
      $grenze = App::uploadGrenze();
      $zeigen = $grenze > 0 ? min($grenze, 12 * 1024 * 1024) : 12 * 1024 * 1024; ?>
    <div class="klein gedimmt">JPG, PNG, WebP, SVG, GIF und PDF · bis <?= Util::h(Util::bytes($zeigen)) ?> je Datei</div>
    <input id="dateien" type="file" name="dateien[]" multiple
           accept="image/jpeg,image/png,image/webp,image/svg+xml,image/gif,application/pdf"
           style="position:absolute;opacity:0;width:1px;height:1px" onchange="this.form.submit()">
  </label>
</form>
<?php endif; ?>

<?php if ($medien === []): ?>
  <div class="karte"><div class="karte__koerper">
    <?= leerzustand('image', 'Noch keine Dateien',
          'Bilder für Titelbereiche, Beiträge und Galerien landen hier. Ein Tipp: Fotos vor dem '
          . 'Hochladen auf etwa 1600 Pixel Breite verkleinern – das spart Ladezeit, die Google misst.', []) ?>
  </div></div>
<?php else: ?>
  <div class="medien-raster">
    <?php foreach ($medien as $m):
      $istBild = str_starts_with((string) $m['mime'], 'image/'); ?>
      <div class="medium">
        <div class="medium__bild">
          <?php if ($istBild): ?>
            <img src="<?= Util::attr(App::url((string) $m['pfad'])) ?>" alt="" loading="lazy">
          <?php else: ?>
            <?= Icon::svg('invoices', 24) ?>
          <?php endif; ?>
        </div>
        <div class="medium__text">
          <span class="medium__name" title="<?= Util::attr((string) $m['dateiname']) ?>">
            <?= Util::h((string) $m['dateiname']) ?></span>
          <?= Util::h(Util::bytes((int) $m['groesse'])) ?>
          <?php if ((int) $m['breite'] > 0): ?> · <?= (int) $m['breite'] ?>×<?= (int) $m['hoehe'] ?><?php endif; ?>
          <div class="reihe reihe--eng mt-2">
            <button class="btn btn--klein btn--geist btn--nur-symbol"
                    data-kopieren="<?= Util::attr((string) $m['pfad']) ?>"
                    title="Pfad kopieren" aria-label="Pfad kopieren"><?= Icon::svg('copy', 13) ?></button>
            <a class="btn btn--klein btn--geist btn--nur-symbol" target="_blank" rel="noopener"
               href="<?= Util::attr(App::url((string) $m['pfad'])) ?>"
               aria-label="Öffnen"><?= Icon::svg('external', 13) ?></a>
            <div class="fueller"></div>
            <?php if (Auth::darf('content.write')): ?>
              <form method="post" data-bestaetigen="Datei löschen? Sie verschwindet auch dort, wo sie eingebunden ist.">
                <?= Auth::csrfFeld() ?>
                <input type="hidden" name="aktion" value="loeschen">
                <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                <button class="btn btn--klein btn--geist btn--nur-symbol" type="submit"
                        aria-label="Löschen"><?= Icon::svg('trash', 13) ?></button>
              </form>
            <?php endif; ?>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/partials/fuss.php'; ?>
