<?php
/** Rechnungsliste mit Export für die Buchhaltung. */
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/partials/helfer.php';

Auth::fordern('modul.invoices');
Invoices::faelligkeitPruefen();

if (App::get('aktion') === 'csv') {
    $von = App::get('von', date('Y-01-01'));
    $bis = App::get('bis', Util::heute());
    Audit::schreiben('export', 'invoice', 0, 'Buchungssätze ' . $von . ' bis ' . $bis);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="buchungssaetze-' . $von . '-bis-' . $bis . '.csv"');
    echo "\xEF\xBB\xBF" . Invoices::csv($von, $bis);
    exit;
}

$status = App::get('status', 'alle');
$suche  = App::get('q');
$seite  = max(1, App::getInt('seite', 1));
$proSeite = 50;

$wo = [];
$p  = [];
if ($status !== 'alle') {
    $wo[] = 'status = :s';
    $p['s'] = $status;
}
if ($suche !== '') {
    $wo[] = '(nummer LIKE :q OR empfaenger LIKE :q)';
    $p['q'] = '%' . $suche . '%';
}
$bedingung = implode(' AND ', $wo);
$gesamt = Tenant::count('invoices', $bedingung, $p);
$liste = Tenant::all('invoices', $bedingung, $p, 'id DESC', $proSeite, ($seite - 1) * $proSeite);

$offen = Invoices::offenerBetrag();
$ueberfaellig = Tenant::sum('invoices', 'summe_cent - bezahlt_cent', 'status = "ueberfaellig"');
$bezahltJahr = Tenant::sum('invoices', 'summe_cent', 'status = "bezahlt" AND datum >= :j',
    ['j' => date('Y-01-01')]);

$titel = 'Rechnungen';
$unter = Util::geld($offen) . ' offen' . ($ueberfaellig > 0
    ? ' · <span style="color:var(--gefahr)">' . Util::h(Util::geld($ueberfaellig)) . ' überfällig</span>' : '');
$aktionen = '<a class="btn" href="' . App::url('/app/rechnungen.php?aktion=csv') . '">'
          . Icon::svg('download', 15) . ' Buchhaltung</a>'
          . (Auth::darf('invoices.write')
             ? '<a class="btn btn--primaer" href="' . App::url('/app/rechnung.php?id=neu') . '">'
               . Icon::svg('plus', 15) . ' Rechnung</a>' : '');
require __DIR__ . '/partials/kopf.php';
?>

<div class="raster raster--3 mb-5">
  <?= kennzahl('Offen', Util::geld($offen), ['icon' => 'clock',
        'fuss' => Tenant::count('invoices', 'status IN ("offen","ueberfaellig")') . ' Rechnungen']) ?>
  <?= kennzahl('Überfällig', Util::geld($ueberfaellig), ['icon' => 'alert',
        'fuss' => Tenant::count('invoices', 'status = "ueberfaellig"') . ' Rechnungen',
        'url' => '/app/rechnungen.php?status=ueberfaellig']) ?>
  <?= kennzahl('Bezahlt ' . date('Y'), Util::geld($bezahltJahr), ['icon' => 'check',
        'fuss' => 'im laufenden Jahr']) ?>
</div>

<div class="karte">
  <form method="get" class="filterleiste">
    <div class="such-feld">
      <?= Icon::svg('search', 16) ?>
      <input class="eingabe" name="q" value="<?= Util::attr($suche) ?>" data-such-absenden
             placeholder="Nummer oder Name" aria-label="Rechnungen durchsuchen">
    </div>
    <select name="status" data-auto-absenden aria-label="Status">
      <option value="alle">Alle Status</option>
      <?php foreach (Invoices::STATUS as $k => [$n, $f]): ?>
        <option value="<?= $k ?>"<?= $status === $k ? ' selected' : '' ?>><?= Util::h($n) ?></option>
      <?php endforeach; ?>
    </select>
  </form>

  <?php if ($liste === []): ?>
    <div class="karte__koerper">
      <?= leerzustand('invoices', 'Keine Rechnungen in dieser Auswahl',
            'Rechnungen entstehen automatisch aus bezahlten Bestellungen. Für alles andere – '
            . 'Barzahlung, Überweisung, Firmenkunden – legst du sie hier von Hand an.',
            Auth::darf('invoices.write') ? [['Rechnung erstellen', '/app/rechnung.php?id=neu', 'plus']] : []) ?>
    </div>
  <?php else: ?>
    <div class="tabelle-huelle">
      <table class="tabelle tabelle--klickbar">
        <thead><tr><th>Nummer</th><th>Empfänger</th><th class="nicht-mobil">Datum</th>
          <th class="nicht-mobil">Fällig</th><th class="zahl">Betrag</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($liste as $r):
          $empfaenger = Util::ausJson((string) $r['empfaenger']);
          $url = App::url('/app/rechnung.php?id=' . (int) $r['id']); ?>
          <tr onclick="location.href='<?= Util::attr($url) ?>'">
            <td class="haupt mono"><?= Util::h((string) ($r['nummer'] ?: 'Entwurf')) ?>
              <?php if ((string) $r['art'] === 'gutschrift'): ?>
                <div class="winzig"><?= pille('Gutschrift', 'info') ?></div>
              <?php endif; ?>
            </td>
            <td><?= Util::h((string) ($empfaenger['name'] ?? '—')) ?></td>
            <td class="nicht-mobil gedimmt"><?= Util::h(Util::datum((string) $r['datum'])) ?></td>
            <td class="nicht-mobil">
              <?php $tage = Util::tageBis((string) $r['faellig']); ?>
              <span class="<?= $tage < 0 && (string) $r['status'] !== 'bezahlt' ? '' : 'gedimmt' ?>"
                    style="<?= $tage < 0 && (string) $r['status'] !== 'bezahlt' ? 'color:var(--gefahr)' : '' ?>">
                <?= Util::h(Util::datum((string) $r['faellig'])) ?></span>
            </td>
            <td class="zahl tabnum halbfett"><?= Util::h(Util::geld((int) $r['summe_cent'])) ?></td>
            <td><?= pille(Invoices::statusName((string) $r['status']), Invoices::statusFarbe((string) $r['status'])) ?></td>
            <td class="aktionen">
              <a class="btn btn--klein" target="_blank" rel="noopener" onclick="event.stopPropagation()"
                 href="<?= Util::attr(App::url('/app/pdf.php?art=rechnung&id=' . (int) $r['id'])) ?>"
                 aria-label="PDF"><?= Icon::svg('download', 14) ?></a>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?= App::seitenNavigation($gesamt, $proSeite, $seite,
          App::url('/app/rechnungen.php?status=' . $status . '&q=' . rawurlencode($suche))) ?>
  <?php endif; ?>
</div>

<div class="hinweis hinweis--still mt-4">
  <?= Icon::svg('info', 17) ?>
  <div class="hinweis__text">
    <span class="hinweis__titel">Übergabe an die Buchhaltung</span>
    Der Knopf <em>Buchhaltung</em> lädt alle Belege eines Zeitraums als CSV mit Belegnummer,
    Datum, Netto, Brutto, Steuersatz und Erlöskonto – in der Form, die DATEV, lexoffice und
    sevDesk erwarten. Rechnungsnummern sind lückenlos; storniert wird über eine Gutschrift,
    gelöscht wird nie.
  </div>
</div>

<?php require __DIR__ . '/partials/fuss.php'; ?>
