<?php
/** Zahlungen – was eingegangen ist und was noch aussteht. */
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/partials/helfer.php';

Auth::fordern('modul.payments');

$zeit = App::get('zeit', '30tage');
$z = Analytics::zeitraum($zeit);

$zahlungen = Tenant::all('payments', 'erstellt >= :von AND erstellt <= :bis',
    ['von' => $z['von'] . ' 00:00:00', 'bis' => $z['bis'] . ' 23:59:59'], 'id DESC', 120);
$bestellungen = Tenant::all('orders', 'erstellt >= :von AND erstellt <= :bis',
    ['von' => $z['von'] . ' 00:00:00', 'bis' => $z['bis'] . ' 23:59:59'], 'id DESC', 60);

$eingegangen = Tenant::sum('payments', 'betrag_cent',
    'status = "bezahlt" AND erstellt >= :von AND erstellt <= :bis',
    ['von' => $z['von'] . ' 00:00:00', 'bis' => $z['bis'] . ' 23:59:59']);
$gebuehren = Tenant::sum('payments', 'gebuehr_cent',
    'status = "bezahlt" AND erstellt >= :von AND erstellt <= :bis',
    ['von' => $z['von'] . ' 00:00:00', 'bis' => $z['bis'] . ' 23:59:59']);
$offenTermine = Tenant::sum('bookings', 'preis_cent',
    'bezahlt = 0 AND customer_package_id = 0 AND status IN ("bestaetigt","erschienen")');

$methoden = DB::all(
    "SELECT methode, COUNT(*) AS anzahl, SUM(betrag_cent) AS summe FROM payments
     WHERE workspace_id = :w AND status = 'bezahlt' AND erstellt >= :von
     GROUP BY methode ORDER BY summe DESC",
    ['w' => Tenant::id(), 'von' => $z['von'] . ' 00:00:00']
);

$titel = 'Zahlungen';
$unter = $z['name'] . ' · ' . Util::geld($eingegangen) . ' eingegangen';
$aktionen = '<form method="get" style="display:inline"><select name="zeit" data-auto-absenden aria-label="Zeitraum">';
foreach (Analytics::ZEITRAEUME as $k => $v) {
    $aktionen .= '<option value="' . $k . '"' . ($zeit === $k ? ' selected' : '') . '>' . Util::h($v) . '</option>';
}
$aktionen .= '</select></form>';
require __DIR__ . '/partials/kopf.php';
?>

<?php if (!Stripe::eingerichtet()): ?>
  <div class="hinweis hinweis--warnung mb-4">
    <?= Icon::svg('alert', 17) ?>
    <div class="hinweis__text">
      <span class="hinweis__titel">Testmodus</span>
      Es ist kein Stripe-Schlüssel hinterlegt. Bestellungen werden angelegt und als bezahlt
      verbucht, damit du alles ausprobieren kannst – es fließt aber kein Geld.
      Für echte Zahlungen den Schlüssel in der <code>config.php</code> eintragen.
    </div>
  </div>
<?php endif; ?>

<div class="raster raster--4 mb-5">
  <?= kennzahl('Eingegangen', Util::geld($eingegangen), ['icon' => 'euro', 'fuss' => $z['name']]) ?>
  <?= kennzahl('Gebühren', Util::geld($gebuehren), ['icon' => 'percent',
        'fuss' => $eingegangen > 0 ? Util::prozent($gebuehren / max(1, $eingegangen) * 100, 2) . ' vom Umsatz' : '']) ?>
  <?= kennzahl('Netto', Util::geld($eingegangen - $gebuehren), ['icon' => 'trend-up',
        'fuss' => 'nach Zahlungsgebühren']) ?>
  <?= kennzahl('Unbezahlte Termine', Util::geld($offenTermine), ['icon' => 'clock',
        'fuss' => 'noch nicht abgerechnet', 'url' => '/app/buchungen.php?zeit=alle']) ?>
</div>

<div class="raster raster--haupt-neben">
  <div class="karte">
    <div class="karte__kopf"><h2>Zahlungseingänge</h2>
      <span class="pille"><?= count($zahlungen) ?></span></div>
    <?php if ($zahlungen === []): ?>
      <div class="karte__koerper">
        <?= leerzustand('payments', 'Keine Zahlungen in diesem Zeitraum',
              'Sobald ein Kunde online bezahlt oder du eine Rechnung als bezahlt verbuchst, '
              . 'steht der Eingang hier.', []) ?>
      </div>
    <?php else: ?>
      <div class="tabelle-huelle">
        <table class="tabelle">
          <thead><tr><th>Datum</th><th>Kunde</th><th class="nicht-mobil">Methode</th>
            <th class="zahl">Betrag</th><th class="zahl nicht-mobil">Gebühr</th><th>Status</th></tr></thead>
          <tbody>
          <?php foreach ($zahlungen as $p): ?>
            <tr>
              <td class="umbruch-nein"><?= Util::h(Util::datum((string) $p['erstellt'])) ?>
                <div class="winzig gedimmt-2"><?= Util::h(Util::uhrzeit((string) $p['erstellt'])) ?></div></td>
              <td><?= (int) $p['customer_id'] > 0
                    ? '<a href="' . Util::attr(App::url('/app/kunde.php?id=' . (int) $p['customer_id'])) . '">'
                      . Util::h(Customers::nameVonId((int) $p['customer_id'])) . '</a>'
                    : '<span class="gedimmt-2">—</span>' ?></td>
              <td class="nicht-mobil"><?= pille(Stripe::methodeName((string) $p['methode']), 'offen') ?></td>
              <td class="zahl tabnum halbfett"><?= Util::h(Util::geld((int) $p['betrag_cent'])) ?></td>
              <td class="zahl tabnum nicht-mobil gedimmt">
                <?= (int) $p['gebuehr_cent'] > 0 ? Util::h(Util::geld((int) $p['gebuehr_cent'])) : '—' ?></td>
              <td><?= pille((string) $p['status'],
                    (string) $p['status'] === 'bezahlt' ? 'erfolg'
                    : ((string) $p['status'] === 'fehlgeschlagen' ? 'gefahr' : 'warnung')) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <div class="stapel">
    <div class="karte">
      <div class="karte__kopf"><h3>Nach Zahlungsart</h3></div>
      <div class="karte__koerper">
        <?php if ($methoden === []): ?>
          <p class="klein gedimmt mitte">Noch keine Daten.</p>
        <?php else:
          $max = max(array_map(static fn($m) => (int) $m['summe'], $methoden)); ?>
          <div class="rangliste">
            <?php foreach ($methoden as $m): ?>
              <div class="rang">
                <span class="rang__name"><?= Util::h(Stripe::methodeName((string) $m['methode'])) ?></span>
                <span class="rang__wert"><?= Util::h(Util::geldKurz((int) $m['summe'])) ?></span>
                <span class="rang__spur"><span class="rang__fuellung"
                      data-breite="<?= (int) round((int) $m['summe'] / max(1, $max) * 100) ?>%"></span></span>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="karte">
      <div class="karte__kopf"><h3>Letzte Bestellungen</h3>
        <div class="fueller"></div>
        <a class="klein" href="<?= Util::attr(App::url('/app/rechnungen.php')) ?>">Rechnungen</a></div>
      <div class="karte__koerper karte__koerper--eng">
        <?php if ($bestellungen === []): ?>
          <p class="klein gedimmt mitte" style="padding:var(--r3) 0">Keine Bestellungen.</p>
        <?php else: ?>
          <div class="stapel stapel--eng">
            <?php foreach (array_slice($bestellungen, 0, 10) as $o): ?>
              <div class="reihe" style="padding:6px">
                <div style="flex:1;min-width:0">
                  <div class="klein halbfett">
                    <?= Util::h((string) ($o['name'] ?: Customers::nameVonId((int) $o['customer_id']))) ?></div>
                  <div class="winzig gedimmt-2"><?= Util::h((string) $o['nummer']) ?> ·
                    <?= Util::h(Util::datum((string) $o['erstellt'])) ?></div>
                </div>
                <span class="klein tabnum halbfett"><?= Util::h(Util::geldKurz((int) $o['summe_cent'])) ?></span>
                <?= pille((string) $o['status'], (string) $o['status'] === 'bezahlt' ? 'erfolg' : 'warnung') ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/partials/fuss.php'; ?>
