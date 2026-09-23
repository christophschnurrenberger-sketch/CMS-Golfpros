<?php
/**
 * Instanzen – die Liste aller Workspaces.
 *
 * Suche, Filter und Sortierung laufen auf dem Server, geblättert wird in
 * Seiten zu 25, 50 oder 100. Die zuletzt benutzten Filter merkt sich die
 * Sitzung: Wer aus einer Instanz zurückkommt, findet seine Liste so vor,
 * wie er sie verlassen hat.
 *
 * Der Export ist ein POST mit ausdrücklicher Bestätigung, er landet im
 * Audit-Log und wird direkt ausgeliefert – es entsteht keine Datei auf
 * dem Server, die jemand später abrufen könnte.
 */
require __DIR__ . '/partials/start.php';

Auth::start();
$roh = $_GET;
if (isset($roh['zuruecksetzen'])) {
    unset($_SESSION['gp_master_filter']);
    App::weiter('/master/instanzen.php');
}
unset($roh['seite']);
if ($roh === [] && is_array($_SESSION['gp_master_filter'] ?? null) && $_SESSION['gp_master_filter'] !== []) {
    $roh = $_SESSION['gp_master_filter'];
}
$f = Instanzen::filter($roh);
$gemerkt = array_filter($f, static fn ($v, $k) => !in_array($k, ['sort', 'richtung', 'pro'], true) && $v !== '',
    ARRAY_FILTER_USE_BOTH) + array_intersect_key($f, ['sort' => 1, 'richtung' => 1, 'pro' => 1]);
$_SESSION['gp_master_filter'] = $gemerkt;
$seite = max(1, App::getInt('seite', 1));

/* ------------------------------------------------------------ Export --- */

if (App::istPost() && App::aktion() === 'export') {
    Auth::csrfFordern();
    $f = Instanzen::filter($_POST);
    if (!App::postBool('bestaetigt')) {
        App::melden('Bitte bestätige, dass dir der Umgang mit den personenbezogenen Daten im Export klar ist.', 'fehler');
        App::weiter('/master/instanzen.php');
    }
    if (Betreiber::gebremst('EXPORT_CREATED', 10)) {
        App::melden('Zu viele Exporte in kurzer Zeit. Bitte in 15 Minuten erneut versuchen.', 'fehler');
        App::weiter('/master/instanzen.php');
    }
    [$zeilen, $gesamt] = Instanzen::liste($f, 1, 0);
    Betreiberlog::schreiben('EXPORT_CREATED', [
        'objekt' => 'instanzen', 'nachher' => ['filter' => array_filter($f, static fn ($v) => $v !== ''), 'zeilen' => $gesamt],
        'beschreibung' => 'CSV-Export der Instanzliste',
    ]);
    /* Eine Zelle, die mit = + - @ beginnt, liest eine Tabellenkalkulation
       als Formel. Ein Instanzname wie „=HYPERLINK(…)" wäre sonst ein Angriff
       auf den, der die Datei öffnet. */
    $zelle = static fn ($v): string => preg_match('/^[=+\-@\t\r]/', (string) $v) ? "'" . $v : (string) $v;
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="teepilot-instanzen-' . date('Y-m-d') . '.csv"');
    header('Cache-Control: no-store');
    $aus = fopen('php://output', 'w');
    fwrite($aus, "\xEF\xBB\xBF");
    fputcsv($aus, ['ID', 'Name', 'Status', 'Paket', 'Inhaber', 'E-Mail Inhaber', 'Club', 'Kurzadresse', 'Domain',
                   'Aktive Benutzer', 'Angelegt', 'Letzte Aktivität', 'Nutzung', 'Demo'], ';');
    foreach ($zeilen as $w) {
        fputcsv($aus, array_map($zelle, [
            $w['id'], $w['name'], Instanzen::statusName((string) $w['status']), Pakete::name((string) $w['plan']),
            $w['inhaber'] ?? '', $w['inhaber_email'] ?? '', $w['club'] ?? '', $w['slug'], $w['domain'],
            $w['benutzer'], Util::datum((string) $w['erstellt']),
            $w['letzte_aktivitaet'] ? Util::datumZeit((string) $w['letzte_aktivitaet']) : '',
            Instanzen::GESUNDHEIT[Instanzen::gesundheit($w['letzte_aktivitaet'] ?: null)][0],
            (int) $w['demo'] === 1 ? 'ja' : 'nein',
        ]), ';');
    }
    exit;
}

[$instanzen, $gesamt] = Instanzen::liste($f, $seite);
$pakete = Pakete::alle();

$titel = 'Instanzen';
$unter = Util::zahl($gesamt) . ' ' . ($gesamt === 1 ? 'Instanz' : 'Instanzen')
       . ($f['status'] === '' ? ' · archivierte ausgeblendet' : '');
$bereich = 'instanzen';
$brotkrumen = [['Übersicht', '/master/'], ['Instanzen', null]];
$aktionen = '<button class="btn" data-modal-auf="modal-export">' . Icon::svg('download', 15) . ' Export</button>'
          . '<a class="btn btn--primaer" href="' . Util::attr(App::url('/master/instanz-neu.php')) . '">'
          . Icon::svg('plus', 15) . ' Neue Instanz</a>';
require __DIR__ . '/partials/kopf.php';
$aktiveFilter = array_filter([$f['q'], $f['status'], $f['paket'], $f['nutzung'], $f['von'], $f['bis'], $f['demo']]);
?>

<div class="karte">
  <form method="get" class="filterleiste filterleiste--umbruch" action="<?= Util::attr(App::url('/master/instanzen.php')) ?>">
    <div class="such-feld">
      <?= Icon::svg('search', 16) ?>
      <input class="eingabe" type="search" name="q" value="<?= Util::attr((string) $f['q']) ?>" data-such-absenden
             placeholder="Name, Golfpro, E-Mail, Club, Domain, ID …" aria-label="Instanzen durchsuchen">
    </div>
    <select name="status" data-auto-absenden aria-label="Status">
      <option value="">Alle außer Archiv</option>
      <?php foreach (Instanzen::STATUS as $k => [$name]): ?>
        <option value="<?= $k ?>"<?= $f['status'] === $k ? ' selected' : '' ?>><?= Util::h($name) ?></option>
      <?php endforeach; ?>
      <option value="alle"<?= $f['status'] === 'alle' ? ' selected' : '' ?>>Alle einschließlich Archiv</option>
    </select>
    <select name="paket" data-auto-absenden aria-label="Paket">
      <option value="">Alle Pakete</option>
      <?php foreach ($pakete as $k => $p): ?>
        <option value="<?= Util::attr($k) ?>"<?= $f['paket'] === $k ? ' selected' : '' ?>><?= Util::h((string) $p['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="nutzung" data-auto-absenden aria-label="Letzte Aktivität">
      <option value="">Jede Nutzung</option>
      <?php foreach (Instanzen::GESUNDHEIT as $k => [$name]): ?>
        <option value="<?= $k ?>"<?= $f['nutzung'] === $k ? ' selected' : '' ?>><?= Util::h($name) ?></option>
      <?php endforeach; ?>
    </select>
    <label class="filter-datum"><span>Angelegt ab</span>
      <input class="eingabe" type="date" name="von" value="<?= Util::attr((string) $f['von']) ?>" data-auto-absenden></label>
    <label class="filter-datum"><span>bis</span>
      <input class="eingabe" type="date" name="bis" value="<?= Util::attr((string) $f['bis']) ?>" data-auto-absenden></label>
    <select name="demo" data-auto-absenden aria-label="Demo-Instanzen">
      <option value="">Mit Demo</option>
      <option value="ohne"<?= $f['demo'] === 'ohne' ? ' selected' : '' ?>>Ohne Demo</option>
      <option value="nur"<?= $f['demo'] === 'nur' ? ' selected' : '' ?>>Nur Demo</option>
    </select>
    <input type="hidden" name="sort" value="<?= Util::attr((string) $f['sort']) ?>">
    <input type="hidden" name="richtung" value="<?= Util::attr((string) $f['richtung']) ?>">
    <div class="fueller"></div>
    <select name="pro" data-auto-absenden aria-label="Einträge je Seite">
      <?php foreach ([25, 50, 100] as $n): ?>
        <option value="<?= $n ?>"<?= (int) $f['pro'] === $n ? ' selected' : '' ?>><?= $n ?> je Seite</option>
      <?php endforeach; ?>
    </select>
    <?php if ($aktiveFilter !== []): ?>
      <a class="btn btn--geist btn--klein" href="<?= Util::attr(App::url('/master/instanzen.php?zuruecksetzen=1')) ?>">
        <?= Icon::svg('x', 14) ?> Filter zurücksetzen</a>
    <?php endif; ?>
    <noscript><button class="btn btn--klein" type="submit">Anwenden</button></noscript>
  </form>

  <?php if ($instanzen === []): ?>
    <div class="karte__koerper">
      <?php if ($aktiveFilter !== []): ?>
        <?= leerzustand('search', 'Keine Treffer', 'Für diese Auswahl gibt es keine Instanz.',
              [['Filter zurücksetzen', '/master/instanzen.php?zuruecksetzen=1', 'refresh']]) ?>
      <?php else: ?>
        <?= leerzustand('building', 'Noch keine Instanzen', 'Lege die erste Instanz an – mit Inhaber, Paket und Laufzeit.',
              [['Neue Instanz', '/master/instanz-neu.php', 'plus']]) ?>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="tabelle-huelle">
      <table class="tabelle tabelle--instanzen">
        <thead>
          <tr>
            <?= sortKopf('Instanz', 'name', $f, '/master/instanzen.php') ?>
            <?= sortKopf('Status', 'status', $f, '/master/instanzen.php') ?>
            <?= sortKopf('Paket', 'paket', $f, '/master/instanzen.php') ?>
            <th>Golfpro</th>
            <th class="nicht-mobil">Club</th>
            <?= sortKopf('Benutzer', 'benutzer', $f, '/master/instanzen.php', 'zahl') ?>
            <?= sortKopf('Angelegt', 'erstellt', $f, '/master/instanzen.php') ?>
            <?= sortKopf('Letzte Aktivität', 'aktivitaet', $f, '/master/instanzen.php') ?>
            <th class="aktionen"><span class="nur-lesbar">Aktionen</span></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($instanzen as $w): $url = App::url('/master/instanz.php?id=' . (int) $w['id']); ?>
            <tr>
              <td class="tabelle__haupt">
                <a href="<?= Util::attr($url) ?>"><?= Util::h((string) $w['name']) ?></a><?= demoPille($w) ?>
                <div class="klein gedimmt mono">#<?= (int) $w['id'] ?> · <?= Util::h((string) ($w['domain'] ?: $w['slug'])) ?></div>
              </td>
              <td><?= Instanzen::statusPille((string) $w['status']) ?></td>
              <td><?= paketPille((string) $w['plan']) ?></td>
              <td><?= Util::h((string) ($w['inhaber'] ?? '—')) ?>
                <div class="klein gedimmt"><?= Util::h((string) ($w['inhaber_email'] ?? '')) ?></div></td>
              <td class="nicht-mobil"><?= Util::h((string) ($w['club'] ?? '')) ?: '<span class="gedimmt">—</span>' ?></td>
              <td class="zahl"><?= (int) $w['benutzer'] ?></td>
              <td class="umbruch-nein"><?= Util::h(Util::datum((string) $w['erstellt'])) ?></td>
              <td class="umbruch-nein"><?= wann($w['letzte_aktivitaet'] ?: null) ?>
                <div><?= Instanzen::gesundheitPille($w['letzte_aktivitaet'] ?: null) ?></div></td>
              <td class="aktionen"><a class="btn btn--klein" href="<?= Util::attr($url) ?>">Öffnen</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="karte__fuss">
      <?= App::seitenNavigation($gesamt, (int) $f['pro'], $seite, masterUrl('/master/instanzen.php', $f)) ?>
    </div>
  <?php endif; ?>
</div>

<dialog class="modal" id="modal-export">
  <form method="post" action="<?= Util::attr(App::url('/master/instanzen.php')) ?>">
    <?= Auth::csrfFeld() ?>
    <input type="hidden" name="aktion" value="export">
    <?php foreach ($f as $k => $v): if ($v === '' || $k === 'pro') { continue; } ?>
      <input type="hidden" name="<?= Util::attr($k) ?>" value="<?= Util::attr((string) $v) ?>">
    <?php endforeach; ?>
    <div class="modal__kopf"><h2>Instanzen exportieren</h2>
      <button type="button" class="rundknopf" data-modal-zu aria-label="Schließen"><?= Icon::svg('x', 17) ?></button></div>
    <div class="modal__koerper">
      <p>Exportiert werden die <b><?= Util::zahl($gesamt) ?></b> Instanzen der aktuellen Auswahl als CSV-Datei.</p>
      <div class="hinweis hinweis--warnung mb-4"><?= Icon::svg('alert', 17) ?>
        <div class="hinweis__text">Die Datei enthält personenbezogene Daten: Namen und E-Mail-Adressen der Inhaber.
          Speichere sie nur, wo sie hingehört, und lösche sie, wenn du sie nicht mehr brauchst. Der Export wird im Audit-Log vermerkt.</div></div>
      <label class="haken"><input type="checkbox" name="bestaetigt" value="1" required>
        <span class="haken__text">Verstanden – ich gehe mit der Datei entsprechend um.</span></label>
    </div>
    <div class="modal__fuss">
      <button type="button" class="btn" data-modal-zu>Abbrechen</button>
      <button type="submit" class="btn btn--primaer"><?= Icon::svg('download', 15) ?> CSV herunterladen</button>
    </div>
  </form>
</dialog>

<?php require __DIR__ . '/partials/fuss.php';
