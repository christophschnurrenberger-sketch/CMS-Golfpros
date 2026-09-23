<?php
/**
 * Audit-Log der Betreiberzentrale. Nur lesen – es gibt hier keinen Knopf,
 * der einen Eintrag ändert oder löscht, und die Datenbank würde es auch
 * nicht zulassen.
 */
require __DIR__ . '/partials/start.php';
Betreiber::fordern('audit.view');

/* ------------------------------------------------ Einzelner Eintrag --- */

$id = App::getInt('id');
if ($id > 0) {
    $e = Betreiberlog::eintrag($id);
    $titel = $e !== null ? Betreiberlog::text((string) $e['aktion']) : 'Eintrag nicht gefunden';
    $unter = $e !== null ? 'Eintrag #' . $id . ' · ' . Util::h(Util::datumZeit((string) $e['zeit'])) : '';
    $bereich = 'protokoll';
    $brotkrumen = [['Übersicht', '/master/'], ['Audit-Log', '/master/protokoll.php'], ['#' . $id, null]];
    require __DIR__ . '/partials/kopf.php';
    if ($e === null) {
        echo leerzustand('shield', 'Nicht gefunden', 'Diesen Eintrag gibt es nicht.', [['Zum Audit-Log', '/master/protokoll.php', 'arrow-left']]);
    } else {
        $vorher = Util::ausJson((string) $e['vorher'], []);
        $nachher = Util::ausJson((string) $e['nachher'], []);
        $schluessel = array_unique(array_merge(array_keys($vorher), array_keys($nachher)));
        $zeigen = static fn ($v): string => is_array($v) ? Util::h(Util::json($v)) : Util::h(var_export($v, true) === 'NULL' ? '—' : (string) $v);
        ?>
        <div class="raster raster--haupt-neben">
          <?= karteAuf('Vorher und nachher') ?>
            <?php if ($schluessel === []): ?>
              <div class="karte__koerper"><p class="gedimmt mb-0">Keine Datenänderung in diesem Eintrag.</p></div>
            <?php else: ?>
              <div class="tabelle-huelle"><table class="tabelle tabelle--eng vergleich">
                <thead><tr><th>Feld</th><th>Vorher</th><th>Nachher</th></tr></thead>
                <tbody>
                  <?php foreach ($schluessel as $k): ?>
                    <tr><td class="mono"><?= Util::h((string) $k) ?></td>
                      <td class="vergleich__alt"><?= array_key_exists($k, $vorher) ? $zeigen($vorher[$k]) : '<span class="gedimmt">—</span>' ?></td>
                      <td class="vergleich__neu"><?= array_key_exists($k, $nachher) ? $zeigen($nachher[$k]) : '<span class="gedimmt">—</span>' ?></td></tr>
                  <?php endforeach; ?>
                </tbody>
              </table></div>
            <?php endif; ?>
          </div>
          <?= karteAuf('Angaben') ?>
            <div class="karte__koerper">
              <dl class="angaben">
                <?= angabe('Zeit', Util::h(Util::datumZeit((string) $e['zeit']))) ?>
                <?= angabe('Akteur', Util::h((string) $e['akteur'] ?: '—') . ((int) $e['betreiber_id'] > 0 ? ' <span class="gedimmt mono">#' . (int) $e['betreiber_id'] . '</span>' : '')) ?>
                <?= angabe('Aktion', '<span class="mono">' . Util::h((string) $e['aktion']) . '</span>') ?>
                <?= angabe('Schwere', schwerePille((string) $e['schwere'])) ?>
                <?= angabe('Ergebnis', Util::h(Betreiberlog::ERGEBNIS[$e['ergebnis']] ?? (string) $e['ergebnis'])) ?>
                <?= angabe('Objekt', Util::h((string) $e['objekt']) . ((int) $e['objekt_id'] > 0 ? ' #' . (int) $e['objekt_id'] : '')) ?>
                <?= angabe('Instanz', (int) $e['instanz_id'] > 0 ? '<a href="' . Util::attr(App::url('/master/instanz.php?id=' . (int) $e['instanz_id'])) . '">#' . (int) $e['instanz_id'] . '</a>' : '—') ?>
                <?= angabe('Grund', Util::h((string) $e['grund']) ?: '<span class="gedimmt">—</span>') ?>
                <?= angabe('Beschreibung', Util::h((string) $e['beschreibung']) ?: '<span class="gedimmt">—</span>') ?>
                <?= angabe('Anfrage-ID', '<span class="mono">' . Util::h((string) $e['anfrage_id']) . '</span>') ?>
                <?= angabe('IP-Adresse', '<span class="mono">' . Util::h((string) $e['ip']) . '</span>') ?>
              </dl>
            </div>
          </div>
        </div>
        <?php
    }
    require __DIR__ . '/partials/fuss.php';
    exit;
}

/* ------------------------------------------------------------ Liste --- */

$f = [
    'aktion' => App::get('aktion'), 'schwere' => App::get('schwere'), 'ergebnis' => App::get('ergebnis'),
    'instanz_id' => App::getInt('instanz'), 'betreiber_id' => App::getInt('betreiber'),
    'von' => App::get('von'), 'bis' => App::get('bis'),
];
$seite = max(1, App::getInt('seite', 1));
$pro = 50;
$gesamt = Betreiberlog::anzahl($f);
$eintraege = Betreiberlog::liste($f, $pro, ($seite - 1) * $pro);
$betreiber = DB::all('SELECT id, name FROM betreiber ORDER BY name');
$parameter = ['aktion' => $f['aktion'], 'schwere' => $f['schwere'], 'ergebnis' => $f['ergebnis'], 'instanz' => $f['instanz_id'],
              'betreiber' => $f['betreiber_id'], 'von' => $f['von'], 'bis' => $f['bis']];

$titel = 'Audit-Log';
$unter = Util::zahl($gesamt) . ' Einträge · nur lesbar, wird nie geändert oder gelöscht';
$bereich = 'protokoll';
$brotkrumen = [['Übersicht', '/master/'], ['Audit-Log', null]];
require __DIR__ . '/partials/kopf.php';
?>

<div class="karte">
  <form method="get" class="filterleiste filterleiste--umbruch" action="<?= Util::attr(App::url('/master/protokoll.php')) ?>">
    <select name="aktion" data-auto-absenden aria-label="Aktion">
      <option value="">Alle Aktionen</option>
      <?php foreach (Betreiberlog::AKTIONEN as $k => [$name]): ?>
        <option value="<?= $k ?>"<?= $f['aktion'] === $k ? ' selected' : '' ?>><?= Util::h($name) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="schwere" data-auto-absenden aria-label="Schwere">
      <option value="">Jede Schwere</option>
      <?php foreach (Betreiberlog::SCHWERE as $k => $v): ?><option value="<?= $k ?>"<?= $f['schwere'] === $k ? ' selected' : '' ?>><?= Util::h($v) ?></option><?php endforeach; ?>
    </select>
    <select name="ergebnis" data-auto-absenden aria-label="Ergebnis">
      <option value="">Jedes Ergebnis</option>
      <?php foreach (Betreiberlog::ERGEBNIS as $k => $v): ?><option value="<?= $k ?>"<?= $f['ergebnis'] === $k ? ' selected' : '' ?>><?= Util::h($v) ?></option><?php endforeach; ?>
    </select>
    <select name="betreiber" data-auto-absenden aria-label="Betreiber">
      <option value="0">Alle Betreiber</option>
      <?php foreach ($betreiber as $b): ?><option value="<?= (int) $b['id'] ?>"<?= $f['betreiber_id'] === (int) $b['id'] ? ' selected' : '' ?>><?= Util::h((string) $b['name']) ?></option><?php endforeach; ?>
    </select>
    <label class="filter-datum"><span>Von</span><input class="eingabe" type="date" name="von" value="<?= Util::attr($f['von']) ?>" data-auto-absenden></label>
    <label class="filter-datum"><span>bis</span><input class="eingabe" type="date" name="bis" value="<?= Util::attr($f['bis']) ?>" data-auto-absenden></label>
    <?php if ($f['instanz_id'] > 0): ?><input type="hidden" name="instanz" value="<?= $f['instanz_id'] ?>">
      <a class="pille pille--marke" href="<?= Util::attr(masterUrl('/master/protokoll.php', $parameter, ['instanz' => ''])) ?>">Instanz #<?= $f['instanz_id'] ?> ✕</a><?php endif; ?>
  </form>
  <?php if ($eintraege === []): ?>
    <div class="karte__koerper"><?= leerzustand('shield', 'Keine Einträge', 'Für diese Auswahl gibt es keinen Eintrag.') ?></div>
  <?php else: ?>
    <div class="tabelle-huelle"><table class="tabelle tabelle--eng">
      <thead><tr><th>Zeit</th><th>Akteur</th><th>Aktion</th><th>Objekt</th><th>Instanz</th><th>Schwere</th><th>Ergebnis</th><th>Grund</th></tr></thead>
      <tbody>
        <?php foreach ($eintraege as $z): ?>
          <tr>
            <td class="umbruch-nein mono klein"><?= Util::h((string) $z['zeit']) ?></td>
            <td><?= Util::h((string) $z['akteur'] ?: '—') ?></td>
            <td><a href="<?= Util::attr(App::url('/master/protokoll.php?id=' . (int) $z['id'])) ?>"><?= Util::h(Betreiberlog::text((string) $z['aktion'])) ?></a></td>
            <td class="klein"><?= Util::h((string) $z['objekt']) ?><?= (int) $z['objekt_id'] > 0 ? ' #' . (int) $z['objekt_id'] : '' ?></td>
            <td><?= (int) $z['instanz_id'] > 0 ? '<a href="' . Util::attr(App::url('/master/instanz.php?id=' . (int) $z['instanz_id'])) . '">#' . (int) $z['instanz_id'] . '</a>' : '<span class="gedimmt">—</span>' ?></td>
            <td><?= schwerePille((string) $z['schwere']) ?></td>
            <td><?= (string) $z['ergebnis'] === 'ok' ? '<span class="gedimmt">ok</span>' : pille(Betreiberlog::ERGEBNIS[$z['ergebnis']] ?? '', 'gefahr') ?></td>
            <td class="klein"><?= Util::h(Util::kuerzen((string) $z['grund'], 60)) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
    <div class="karte__fuss"><?= App::seitenNavigation($gesamt, $pro, $seite, masterUrl('/master/protokoll.php', $parameter)) ?></div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/partials/fuss.php';
