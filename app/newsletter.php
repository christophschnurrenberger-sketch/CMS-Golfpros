<?php
/** Newsletter-Übersicht. */
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/partials/helfer.php';

Auth::fordern('modul.newsletter');

$kampagnen = Tenant::all('campaigns', '', [], 'id DESC');
$empfaenger = Tenant::count('customers', "newsletter = 1 AND status = 'aktiv'");
$ohneEinwilligung = Tenant::count('customers', "newsletter = 0 AND status = 'aktiv' AND email != ''");

$versendet = array_values(array_filter($kampagnen, static fn($k) => (string) $k['status'] === 'versendet'));
$oeffnungSchnitt = 0.0;
if ($versendet !== []) {
    $summe = 0.0;
    foreach ($versendet as $k) {
        $summe += Campaigns::quoten($k)['oeffnung'];
    }
    $oeffnungSchnitt = $summe / count($versendet);
}

$titel = 'Newsletter';
$unter = Util::zahl($empfaenger) . ' Empfänger mit Einwilligung · '
       . count($versendet) . ' versendete Newsletter';
$aktionen = Auth::darf('newsletter.write')
    ? '<a class="btn btn--primaer" href="' . App::url('/app/kampagne.php?id=neu') . '">'
      . Icon::svg('plus', 15) . ' Newsletter schreiben</a>' : '';
require __DIR__ . '/partials/kopf.php';
?>

<div class="raster raster--3 mb-5">
  <?= kennzahl('Empfänger', Util::zahl($empfaenger), ['icon' => 'customers',
        'fuss' => $ohneEinwilligung > 0 ? $ohneEinwilligung . ' ohne Einwilligung' : 'alle mit Einwilligung']) ?>
  <?= kennzahl('Öffnungsrate', $versendet !== [] ? Util::prozent($oeffnungSchnitt, 1) : '—',
        ['icon' => 'eye', 'fuss' => 'Durchschnitt · gut sind 25–35 %']) ?>
  <?= kennzahl('Versendet', (string) count($versendet), ['icon' => 'send',
        'fuss' => 'insgesamt']) ?>
</div>

<div class="karte">
  <div class="karte__kopf"><h2>Newsletter</h2></div>
  <?php if ($kampagnen === []): ?>
    <div class="karte__koerper">
      <?= leerzustand('newsletter', 'Noch kein Newsletter',
            'Ein Newsletter alle vier bis sechs Wochen hält dich im Gedächtnis, ohne zu nerven. '
            . 'Eine Übung, ein Tipp, ein Termin – mehr braucht es nicht. Versendet wird nur an '
            . 'Empfänger mit Einwilligung, und in jeder Mail steht der Abmeldelink.',
            Auth::darf('newsletter.write')
              ? [['Ersten Newsletter schreiben', '/app/kampagne.php?id=neu', 'plus']] : []) ?>
    </div>
  <?php else: ?>
    <div class="tabelle-huelle">
      <table class="tabelle tabelle--klickbar">
        <thead><tr><th>Newsletter</th><th class="nicht-mobil">Versendet</th>
          <th class="zahl">Empfänger</th><th class="nicht-mobil">Geöffnet</th>
          <th class="nicht-mobil">Geklickt</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($kampagnen as $k):
          $q = Campaigns::quoten($k);
          $url = App::url('/app/kampagne.php?id=' . (int) $k['id']); ?>
          <tr onclick="location.href='<?= Util::attr($url) ?>'">
            <td>
              <span class="haupt"><?= Util::h((string) $k['name']) ?></span>
              <div class="winzig gedimmt-2"><?= Util::h(Util::kuerzen((string) $k['betreff'], 60)) ?></div>
            </td>
            <td class="nicht-mobil gedimmt">
              <?= $k['versendet'] ? Util::h(Util::datum((string) $k['versendet'])) : '—' ?></td>
            <td class="zahl tabnum"><?= Util::zahl((int) $k['empfaenger']) ?></td>
            <td class="nicht-mobil">
              <?php if ((int) $k['empfaenger'] > 0): ?>
                <?= pille(Util::prozent($q['oeffnung'], 0), $q['oeffnung'] >= 25 ? 'erfolg' : '') ?>
              <?php else: ?><span class="gedimmt-2">—</span><?php endif; ?>
            </td>
            <td class="nicht-mobil">
              <?php if ((int) $k['empfaenger'] > 0): ?>
                <span class="gedimmt"><?= Util::prozent($q['klick'], 1) ?></span>
              <?php else: ?><span class="gedimmt-2">—</span><?php endif; ?>
            </td>
            <td><?= pille((string) $k['status'],
                  (string) $k['status'] === 'versendet' ? 'erfolg'
                  : ((string) $k['status'] === 'geplant' ? 'warnung' : '')) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php if ($ohneEinwilligung > 0): ?>
  <div class="hinweis hinweis--still mt-4">
    <?= Icon::svg('info', 17) ?>
    <div class="hinweis__text">
      <span class="hinweis__titel"><?= $ohneEinwilligung ?> Kunden ohne Newsletter-Einwilligung</span>
      Sie bekommen weiterhin Terminerinnerungen und Rechnungen – das gehört zur Leistung.
      Werbliche Mails gehen nur mit ausdrücklicher Zustimmung raus. Frag beim nächsten Training
      kurz nach; ein Haken in der Kundenakte genügt.
    </div>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/partials/fuss.php'; ?>
