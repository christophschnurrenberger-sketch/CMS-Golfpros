<?php
/**
 * Kundenliste.
 *
 * Die Liste ist nach Segmenten filterbar, weil ein Pro selten „alle Kunden“
 * sucht, sondern eine Gruppe: die Anfänger, die Inaktiven, die mit
 * auslaufendem Paket. Deshalb stehen die Segmente als Filterzeile über der
 * Tabelle und nicht in einem Untermenü.
 */
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/partials/helfer.php';

Auth::fordern('modul.customers');

/* Schnellfilter aus dem Dashboard */
$filter    = App::get('filter');
$suche     = App::get('q');
$status    = App::get('status', 'aktiv');
$segmentId = App::getInt('segment');
$trainerId = App::getInt('trainer');
$sortierung = App::get('sort', 'name');
$seite     = max(1, App::getInt('seite', 1));
$proSeite  = 40;

$zusatzWo = '';
$zusatzP  = [];
$filterName = '';

if ($filter === 'inaktiv') {
    [$zusatzWo, $zusatzP] = Segments::bedingung(['bedingungen' => [
        ['feld' => 'tage_seit_termin', 'op' => '>', 'wert' => '60'],
    ]]);
    $filterName = 'Seit über 60 Tagen kein Termin';
} elseif ($filter === 'geburtstag') {
    $tage = [];
    for ($i = 0; $i < 7; $i++) {
        $tage[] = "'" . date('m-d', strtotime('+' . $i . ' days')) . "'";
    }
    $zusatzWo = 'geburtstag != "" AND substr(geburtstag, 6, 5) IN (' . implode(',', $tage) . ')';
    $filterName = 'Geburtstag in dieser Woche';
} elseif ($filter === 'guthaben') {
    $zusatzWo = 'id IN (SELECT customer_id FROM customer_packages WHERE status = "aktiv"
                        AND einheiten_genutzt < einheiten_gesamt)';
    $filterName = 'Mit offenem Paketguthaben';
}

$f = [
    'suche' => $suche, 'status' => $status, 'segment' => $segmentId,
    'trainer' => $trainerId, 'sortierung' => $sortierung,
];

/* Der Zusatzfilter wird als eigene Bedingung angehängt. */
$wo = [];
$p  = [];
if ($suche !== '') {
    $wo[] = '(vorname LIKE :q OR nachname LIKE :q OR email LIKE :q OR telefon LIKE :q OR heimclub LIKE :q)';
    $p['q'] = '%' . $suche . '%';
}
if ($status !== 'alle') {
    $wo[] = 'status = :status';
    $p['status'] = $status;
}
if ($trainerId > 0) {
    $wo[] = 'trainer_id = :trainer';
    $p['trainer'] = $trainerId;
}
if ($segmentId > 0) {
    $segment = Tenant::find('segments', $segmentId);
    if ($segment) {
        [$sWo, $sP] = Segments::bedingung(Util::ausJson((string) $segment['regeln']));
        if ($sWo !== '') {
            $wo[] = '(' . $sWo . ')';
            $p += $sP;
        }
        $filterName = (string) $segment['name'];
    }
}
if ($zusatzWo !== '') {
    $wo[] = '(' . $zusatzWo . ')';
    $p += $zusatzP;
}
$bedingung = implode(' AND ', $wo);

$sortierungen = [
    'name' => 'nachname, vorname', 'neu' => 'id DESC',
    'aktiv' => 'letzte_aktivitaet DESC', 'score' => 'health_score DESC',
    'hcp' => 'CAST(NULLIF(hcp, "") AS REAL), nachname',
];
$sort = $sortierungen[$sortierung] ?? $sortierungen['name'];

$gesamt = Tenant::count('customers', $bedingung, $p);
$kunden = Tenant::all('customers', $bedingung, $p, $sort, $proSeite, ($seite - 1) * $proSeite);

/* Kennzahlen je Kunde in einem Rutsch – sonst 40 Einzelabfragen je Spalte. */
$ids = array_map(static fn($k) => (int) $k['id'], $kunden);
$guthaben = [];
$umsatz   = [];
$naechster = [];
if ($ids !== []) {
    $liste = implode(',', $ids);
    foreach (DB::all("SELECT customer_id, SUM(einheiten_gesamt - einheiten_genutzt) AS rest
                      FROM customer_packages WHERE workspace_id = :w AND status = 'aktiv'
                      AND customer_id IN ({$liste}) GROUP BY customer_id", ['w' => Tenant::id()]) as $z) {
        $guthaben[(int) $z['customer_id']] = (int) $z['rest'];
    }
    foreach (DB::all("SELECT customer_id, SUM(summe_cent) AS summe FROM orders
                      WHERE workspace_id = :w AND status = 'bezahlt'
                      AND customer_id IN ({$liste}) GROUP BY customer_id", ['w' => Tenant::id()]) as $z) {
        $umsatz[(int) $z['customer_id']] = (int) $z['summe'];
    }
    foreach (DB::all("SELECT customer_id, MIN(start) AS naechster FROM bookings
                      WHERE workspace_id = :w AND start >= :jetzt AND status != 'abgesagt'
                      AND customer_id IN ({$liste}) GROUP BY customer_id",
                      ['w' => Tenant::id(), 'jetzt' => Util::jetzt()]) as $z) {
        $naechster[(int) $z['customer_id']] = (string) $z['naechster'];
    }
}

$segmente = Segments::alle();
$trainer  = Auth::trainer();

$titel = 'Kunden';
$unter = Util::zahl($gesamt) . ' ' . ($gesamt === 1 ? 'Kunde' : 'Kunden')
       . ($filterName !== '' ? ' · <span class="pille pille--marke">' . Util::h($filterName) . '</span>' : '');
$aktionen = '<a class="btn" href="' . App::url('/app/kunden.php?aktion=export'
              . ($bedingung !== '' ? '&q=' . rawurlencode($suche) . '&status=' . rawurlencode($status) : '')) . '">'
          . Icon::svg('download', 15) . ' Export</a>'
          . (Auth::darf('customers.write')
             ? '<a class="btn btn--primaer" href="' . App::url('/app/kunde.php?id=neu') . '">'
               . Icon::svg('user-plus', 15) . ' Neuer Kunde</a>' : '');

/* CSV-Export */
if (App::get('aktion') === 'export' && Auth::darf('customers.view')) {
    Audit::schreiben('export', 'customer', 0, $gesamt . ' Kunden');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="kunden-' . date('Y-m-d') . '.csv"');
    $aus = fopen('php://output', 'w');
    fwrite($aus, "\xEF\xBB\xBF");    // BOM, damit Excel Umlaute richtig liest
    fputcsv($aus, ['Vorname', 'Nachname', 'E-Mail', 'Telefon', 'HCP', 'Heimclub', 'Status',
                   'Quelle', 'Angelegt', 'Letzte Aktivität', 'Gesundheitswert'], ';');
    foreach (Tenant::all('customers', $bedingung, $p, $sort) as $k) {
        fputcsv($aus, [$k['vorname'], $k['nachname'], $k['email'], $k['telefon'], $k['hcp'],
                       $k['heimclub'], $k['status'], $k['quelle'],
                       Util::datum((string) $k['erstellt']), Util::datum((string) $k['letzte_aktivitaet']),
                       $k['health_score']], ';');
    }
    exit;
}

require __DIR__ . '/partials/kopf.php';
?>

<?php if ($segmente !== []): ?>
<div class="reihe reihe--eng reihe--umbruch mb-4">
  <a class="pille <?= $segmentId === 0 && $filter === '' ? 'pille--marke' : 'pille--offen' ?>"
     href="<?= Util::attr(App::url('/app/kunden.php')) ?>">Alle</a>
  <?php foreach ($segmente as $s):
    $anzahl = Segments::anzahl($s);
    if ($anzahl === 0 && (int) $s['system'] === 1) { continue; } ?>
    <a class="pille <?= $segmentId === (int) $s['id'] ? 'pille--marke' : 'pille--offen' ?>"
       href="<?= Util::attr(App::url('/app/kunden.php?segment=' . (int) $s['id'])) ?>"
       title="<?= Util::attr((string) $s['beschreibung']) ?>">
      <?= Util::h((string) $s['name']) ?>
      <span class="gedimmt-2"><?= $anzahl ?></span>
    </a>
  <?php endforeach; ?>
  <a class="pille pille--offen" href="<?= Util::attr(App::url('/app/segmente.php')) ?>">
    <?= Icon::svg('plus', 12) ?> Segment</a>
</div>
<?php endif; ?>

<div class="karte">
  <form method="get" class="filterleiste">
    <div class="such-feld">
      <?= Icon::svg('search', 16) ?>
      <input class="eingabe" name="q" value="<?= Util::attr($suche) ?>" data-such-absenden
             placeholder="Name, E-Mail, Telefon, Club …" aria-label="Kunden durchsuchen">
    </div>
    <?php if ($segmentId > 0): ?>
      <input type="hidden" name="segment" value="<?= $segmentId ?>">
    <?php endif; ?>
    <?php if ($filter !== ''): ?>
      <input type="hidden" name="filter" value="<?= Util::attr($filter) ?>">
    <?php endif; ?>
    <select name="status" data-auto-absenden aria-label="Status">
      <?php foreach (['aktiv' => 'Aktive', 'inaktiv' => 'Inaktive', 'archiviert' => 'Archivierte',
                      'alle' => 'Alle Status'] as $k => $v): ?>
        <option value="<?= $k ?>"<?= $status === $k ? ' selected' : '' ?>><?= Util::h($v) ?></option>
      <?php endforeach; ?>
    </select>
    <?php if (count($trainer) > 1): ?>
    <select name="trainer" data-auto-absenden aria-label="Trainer">
      <option value="0">Alle Trainer</option>
      <?php foreach ($trainer as $t): ?>
        <option value="<?= (int) $t['id'] ?>"<?= $trainerId === (int) $t['id'] ? ' selected' : '' ?>>
          <?= Util::h((string) $t['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <?php endif; ?>
    <div class="fueller"></div>
    <select name="sort" data-auto-absenden aria-label="Sortierung">
      <?php foreach (['name' => 'Name A–Z', 'neu' => 'Zuletzt angelegt', 'aktiv' => 'Zuletzt aktiv',
                      'score' => 'Gesundheitswert', 'hcp' => 'Handicap'] as $k => $v): ?>
        <option value="<?= $k ?>"<?= $sortierung === $k ? ' selected' : '' ?>><?= Util::h($v) ?></option>
      <?php endforeach; ?>
    </select>
  </form>

  <?php if ($kunden === []): ?>
    <div class="karte__koerper">
      <?php if ($suche !== '' || $segmentId > 0 || $filter !== ''): ?>
        <?= leerzustand('search', 'Keine Treffer',
              'Für diese Auswahl gibt es keinen Kunden. Filter zurücksetzen und noch einmal versuchen.',
              [['Filter zurücksetzen', '/app/kunden.php', 'refresh']]) ?>
      <?php else: ?>
        <?= leerzustand('customers', 'Noch keine Kunden',
              'Hier entsteht deine Kundenakte: Handicap, Trainingshistorie, Pakete, Zahlungen und '
              . 'Videos an einem Ort. Leg den ersten Kunden an – oder lass Anfragen von der Website '
              . 'automatisch hier landen.',
              [['Ersten Kunden anlegen', '/app/kunde.php?id=neu', 'user-plus'],
               ['Website-Formular ansehen', '/app/marketing.php', 'inbox']]) ?>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="tabelle-huelle">
      <table class="tabelle tabelle--klickbar">
        <thead>
          <tr>
            <th>Kunde</th>
            <th class="nicht-mobil">HCP</th>
            <th class="nicht-mobil">Heimclub</th>
            <th class="nicht-mobil">Nächster Termin</th>
            <th class="nicht-mobil">Guthaben</th>
            <th class="zahl nicht-mobil">Umsatz</th>
            <th>Gesundheit</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($kunden as $k):
          $id = (int) $k['id'];
          $url = App::url('/app/kunde.php?id=' . $id);
          $score = (int) $k['health_score'];
          [$stufe, $stufeName] = Customers::stufe($score); ?>
          <tr onclick="location.href='<?= Util::attr($url) ?>'">
            <td>
              <?= person(Customers::name($k), [
                    'unter' => (string) ($k['email'] ?: $k['telefon']),
                    'url' => '/app/kunde.php?id=' . $id,
                  ]) ?>
            </td>
            <td class="nicht-mobil">
              <?= (string) $k['hcp'] !== '' ? Util::h((string) $k['hcp']) : '<span class="gedimmt-2">—</span>' ?>
            </td>
            <td class="nicht-mobil gedimmt">
              <?= Util::h(Util::kuerzen((string) $k['heimclub'], 24)) ?: '—' ?>
            </td>
            <td class="nicht-mobil">
              <?php if (isset($naechster[$id])): ?>
                <span class="umbruch-nein"><?= Util::h(Util::datum($naechster[$id], false)) ?>,
                  <?= Util::h(Util::uhrzeit($naechster[$id])) ?></span>
              <?php else: ?>
                <span class="gedimmt-2">kein Termin</span>
              <?php endif; ?>
            </td>
            <td class="nicht-mobil">
              <?php if (!empty($guthaben[$id])): ?>
                <?= pille($guthaben[$id] . ' Einheiten', isset($naechster[$id]) ? 'erfolg' : 'warnung') ?>
              <?php else: ?>
                <span class="gedimmt-2">—</span>
              <?php endif; ?>
            </td>
            <td class="zahl nicht-mobil tabnum">
              <?= Util::h(Util::geldKurz($umsatz[$id] ?? 0)) ?>
            </td>
            <td>
              <span class="tipp" data-tipp="<?= Util::attr($stufeName) ?>"><?= punktestand($score) ?></span>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?= App::seitenNavigation($gesamt, $proSeite, $seite,
          App::url('/app/kunden.php?q=' . rawurlencode($suche) . '&status=' . rawurlencode($status)
                   . '&segment=' . $segmentId . '&sort=' . rawurlencode($sortierung))) ?>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/partials/fuss.php'; ?>
