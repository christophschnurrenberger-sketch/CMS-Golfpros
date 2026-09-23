<?php
/**
 * System – echte Prüfungen, jede mit ihrem Ergebnis. Kein Gesamturteil.
 */
require __DIR__ . '/partials/start.php';
Betreiber::fordern('system.view');

$gruppen = Systempruefung::alle();
$zaehler = ['ok' => 0, 'problem' => 0, 'info' => 0];
foreach ($gruppen as $zeilen) {
    foreach ($zeilen as $z) {
        $zaehler[$z['zustand'] === true ? 'ok' : ($z['zustand'] === false ? 'problem' : 'info')]++;
    }
}
$titel = 'System';
$unter = 'Geprüft um <span class="mono">' . date('H:i:s') . '</span> · ' . $zaehler['ok'] . ' in Ordnung, '
       . $zaehler['problem'] . ' mit Handlungsbedarf, ' . $zaehler['info'] . ' nur zur Auskunft';
$bereich = 'system';
$brotkrumen = [['Übersicht', '/master/'], ['System', null]];
$aktionen = '<a class="btn" href="' . Util::attr(App::url('/master/system.php')) . '">' . Icon::svg('refresh', 15) . ' Neu prüfen</a>';
require __DIR__ . '/partials/kopf.php';
?>

<div class="raster raster--2">
  <?php foreach ($gruppen as $name => $zeilen): ?>
    <?= karteAuf($name) ?>
      <ul class="pruefliste">
        <?php foreach ($zeilen as $z):
          $art = $z['zustand'] === true ? 'ok' : ($z['zustand'] === false ? 'problem' : 'info'); ?>
          <li class="pruefliste__zeile pruefliste__zeile--<?= $art ?>">
            <span class="pruefliste__zeichen" aria-hidden="true"><?= Icon::svg($art === 'ok' ? 'check' : ($art === 'problem' ? 'alert' : 'info'), 15) ?></span>
            <span class="pruefliste__name"><?= Util::h($z['name']) ?>
              <span class="nur-lesbar"><?= $art === 'ok' ? 'in Ordnung' : ($art === 'problem' ? 'Handlungsbedarf' : 'Auskunft') ?></span>
              <?php if ($art === 'problem' && $z['rat'] !== ''): ?><span class="pruefliste__rat"><?= Util::h($z['rat']) ?></span><?php endif; ?></span>
            <span class="pruefliste__wert"><?= Util::h($z['wert']) ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endforeach; ?>
</div>

<?php require __DIR__ . '/partials/fuss.php';
