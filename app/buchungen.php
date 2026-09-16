<?php
/**
 * Buchungsübersicht als Liste – für alles, was im Kalender schwer zu
 * finden ist: Absagen der letzten Wochen, unbezahlte Termine, Buchungen
 * über die Website.
 */
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/partials/helfer.php';

Auth::fordern('modul.bookings');

$zeitraum  = App::get('zeit', 'kommend');
$status    = App::get('status', 'alle');
$trainerId = App::getInt('trainer', Auth::rolle() === 'trainer' ? Auth::id() : 0);
$suche     = App::get('q');
$seite     = max(1, App::getInt('seite', 1));
$proSeite  = 50;

$wo = [];
$p  = [];
switch ($zeitraum) {
    case 'heute':
        $wo[] = 'start >= :von AND start <= :bis';
        $p['von'] = Util::heute() . ' 00:00:00';
        $p['bis'] = Util::heute() . ' 23:59:59';
        break;
    case 'woche':
        $wo[] = 'start >= :von AND start <= :bis';
        $p['von'] = date('Y-m-d', strtotime('monday this week')) . ' 00:00:00';
        $p['bis'] = date('Y-m-d', strtotime('sunday this week')) . ' 23:59:59';
        break;
    case 'vergangen':
        $wo[] = 'start < :jetzt';
        $p['jetzt'] = Util::jetzt();
        break;
    case 'alle':
        break;
    default:
        $zeitraum = 'kommend';
        $wo[] = 'start >= :jetzt';
        $p['jetzt'] = Util::jetzt();
}
if ($status !== 'alle') {
    $wo[] = 'status = :status';
    $p['status'] = $status;
}
if ($trainerId > 0) {
    $wo[] = 'trainer_id = :trainer';
    $p['trainer'] = $trainerId;
}
if ($suche !== '') {
    $wo[] = '(titel LIKE :q OR customer_id IN (SELECT id FROM customers
             WHERE workspace_id = ' . Tenant::id() . ' AND (vorname LIKE :q OR nachname LIKE :q)))';
    $p['q'] = '%' . $suche . '%';
}
$bedingung = implode(' AND ', $wo);
$sortierung = $zeitraum === 'vergangen' ? 'start DESC' : 'start';

$gesamt = Tenant::count('bookings', $bedingung, $p);
$liste  = Tenant::all('bookings', $bedingung, $p, $sortierung, $proSeite, ($seite - 1) * $proSeite);

$umsatzOffen = Tenant::sum('bookings', 'preis_cent',
    'bezahlt = 0 AND customer_package_id = 0 AND status IN ("bestaetigt","erschienen")');

$titel = 'Buchungen';
$unter = Util::zahl($gesamt) . ' Termine' . ($umsatzOffen > 0
    ? ' · <span class="pille pille--warnung">' . Util::h(Util::geld($umsatzOffen)) . ' unbezahlt</span>' : '');
$aktionen = '<a class="btn" href="' . App::url('/app/leistungen.php') . '">' . Icon::svg('list', 15) . ' Leistungen</a>'
          . '<a class="btn" href="' . App::url('/app/pakete.php') . '">' . Icon::svg('ticket', 15) . ' Pakete</a>'
          . '<a class="btn" href="' . App::url('/app/verfuegbarkeit.php') . '">' . Icon::svg('clock', 15) . ' Zeiten</a>'
          . (Auth::darf('bookings.write')
             ? '<a class="btn btn--primaer" href="' . App::url('/app/buchung.php?id=neu') . '">'
               . Icon::svg('plus', 15) . ' Termin</a>' : '');
require __DIR__ . '/partials/kopf.php';
?>

<div class="karte">
  <form method="get" class="filterleiste">
    <div class="such-feld">
      <?= Icon::svg('search', 16) ?>
      <input class="eingabe" name="q" value="<?= Util::attr($suche) ?>" data-such-absenden
             placeholder="Kunde oder Leistung" aria-label="Buchungen durchsuchen">
    </div>
    <select name="zeit" data-auto-absenden aria-label="Zeitraum">
      <?php foreach (['kommend' => 'Kommende', 'heute' => 'Heute', 'woche' => 'Diese Woche',
                      'vergangen' => 'Vergangene', 'alle' => 'Alle'] as $k => $v): ?>
        <option value="<?= $k ?>"<?= $zeitraum === $k ? ' selected' : '' ?>><?= $v ?></option>
      <?php endforeach; ?>
    </select>
    <select name="status" data-auto-absenden aria-label="Status">
      <option value="alle">Alle Status</option>
      <?php foreach (Bookings::STATUS as $k => [$name, $farbe]): ?>
        <option value="<?= $k ?>"<?= $status === $k ? ' selected' : '' ?>><?= Util::h($name) ?></option>
      <?php endforeach; ?>
    </select>
    <?php if (count(Auth::trainer()) > 1): ?>
      <select name="trainer" data-auto-absenden aria-label="Trainer">
        <option value="0">Alle Trainer</option>
        <?php foreach (Auth::trainer() as $t): ?>
          <option value="<?= (int) $t['id'] ?>"<?= $trainerId === (int) $t['id'] ? ' selected' : '' ?>>
            <?= Util::h((string) $t['name']) ?></option>
        <?php endforeach; ?>
      </select>
    <?php endif; ?>
  </form>

  <?php if ($liste === []): ?>
    <div class="karte__koerper">
      <?= leerzustand('bookings', 'Keine Buchungen in dieser Auswahl',
            'Über die Online-Buchung auf deiner Website können Kunden selbst Termine wählen – '
            . 'sie landen direkt hier und im Kalender.',
            [['Zum Kalender', '/app/kalender.php', 'calendar'],
             ['Leistungen prüfen', '/app/leistungen.php', 'list']]) ?>
    </div>
  <?php else: ?>
    <div class="tabelle-huelle">
      <table class="tabelle tabelle--klickbar">
        <thead><tr>
          <th>Wann</th><th>Leistung</th><th>Kunde</th>
          <th class="nicht-mobil">Trainer</th><th class="nicht-mobil">Quelle</th>
          <th class="zahl">Preis</th><th>Status</th>
        </tr></thead>
        <tbody>
        <?php foreach ($liste as $b):
          $url = App::url('/app/buchung.php?id=' . (int) $b['id']); ?>
          <tr onclick="location.href='<?= Util::attr($url) ?>'">
            <td class="umbruch-nein">
              <span class="haupt"><?= Util::h(Util::datum((string) $b['start'], false)) ?></span>
              <div class="winzig gedimmt-2"><?= Util::h(Util::uhrzeit((string) $b['start'])) ?> Uhr</div>
            </td>
            <td><?= Util::h((string) $b['titel']) ?>
              <?php if ((int) $b['teilnehmer'] > 1): ?>
                <span class="pille pille--offen"><?= (int) $b['teilnehmer'] ?> TN</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ((int) $b['customer_id'] > 0): ?>
                <?= Util::h(Customers::nameVonId((int) $b['customer_id'])) ?>
              <?php else: ?><span class="gedimmt-2">—</span><?php endif; ?>
            </td>
            <td class="nicht-mobil gedimmt"><?= Util::h(Auth::trainerName((int) $b['trainer_id'])) ?></td>
            <td class="nicht-mobil">
              <?php /* Website und Kundenzugang sind beides Selbstbuchungen und
                       beide in Ordnung. Amber hiesse „da stimmt etwas nicht" -
                       das galt frueher nur deshalb auch fuer den Zugang, weil
                       ihn nichts erzeugt hat. */ ?>
              <?= pille(['backend' => 'Backend', 'website' => 'Website', 'portal' => 'Zugang',
                         'automation' => 'Automation'][(string) $b['quelle']] ?? (string) $b['quelle'],
                        in_array((string) $b['quelle'], ['website', 'portal'], true) ? 'info' : 'offen') ?>
            </td>
            <td class="zahl tabnum">
              <?php if ((int) $b['customer_package_id'] > 0): ?>
                <span class="gedimmt-2">Paket</span>
              <?php else: ?>
                <?= Util::h(Util::geldKurz((int) $b['preis_cent'])) ?>
                <?php if ((int) $b['bezahlt'] === 0 && (string) $b['status'] !== 'abgesagt'): ?>
                  <div class="winzig" style="color:var(--warnung)">offen</div>
                <?php endif; ?>
              <?php endif; ?>
            </td>
            <td><?= pille(Bookings::statusName((string) $b['status']), Bookings::statusFarbe((string) $b['status'])) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?= App::seitenNavigation($gesamt, $proSeite, $seite,
          App::url('/app/buchungen.php?zeit=' . $zeitraum . '&status=' . $status . '&trainer=' . $trainerId)) ?>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/partials/fuss.php'; ?>
