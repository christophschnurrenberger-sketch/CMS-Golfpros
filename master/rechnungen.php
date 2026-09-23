<?php
/**
 * Rechnungen an die Instanzen – Liste, Kennzahlen, Monatsentwürfe.
 */
require __DIR__ . '/partials/start.php';
Betreiber::fordern('rechnungen.view');

if (App::istPost()) {
    Auth::csrfFordern();
    Betreiber::fordern('rechnungen.write');
    if (App::aktion() === 'monat') {
        $e = Betreiberrechnungen::monatsentwuerfe(App::post('monat'));
        App::melden($e['angelegt'] . ' ' . ($e['angelegt'] === 1 ? 'Entwurf' : 'Entwürfe') . ' angelegt.'
            . ($e['uebersprungen'] !== [] ? ' Übersprungen: ' . implode(', ', $e['uebersprungen']) . '.' : ''),
            $e['angelegt'] > 0 ? 'erfolg' : 'info');
        App::weiter('/master/rechnungen.php?status=entwurf');
    }
    if (App::aktion() === 'neu') {
        $id = App::postInt('instanz');
        if (Instanzen::finden($id) === null) {
            App::melden('Bitte eine Instanz wählen.', 'fehler');
            App::weiter('/master/rechnungen.php');
        }
        App::weiter('/master/rechnung.php?instanz=' . $id . (App::postBool('vertrag') ? '&vertrag=1' : ''));
    }
}

$f = ['status' => App::get('status'), 'instanz' => App::getInt('instanz'), 'jahr' => App::getInt('jahr'), 'q' => App::get('q')];
$seite = max(1, App::getInt('seite', 1));
[$rechnungen, $gesamt] = Betreiberrechnungen::liste($f, $seite, 50);
$k = Betreiberrechnungen::kennzahlen();
$luecken = Betreiberrechnungen::absenderLuecken();
$instanzen = DB::all("SELECT id, name, demo FROM workspaces WHERE status != 'archiviert' ORDER BY name");

$titel = 'Rechnungen';
$unter = 'Rechnungen an die Instanzen – fortlaufend nummeriert, nach dem Ausstellen unveränderlich.';
$bereich = 'rechnungen';
$brotkrumen = [['Übersicht', '/master/'], ['Rechnungen', null]];
$aktionen = '<button class="btn" data-modal-auf="modal-monat">' . Icon::svg('repeat', 15) . ' Monatsentwürfe</button>'
          . '<button class="btn btn--primaer" data-modal-auf="modal-neu">' . Icon::svg('plus', 15) . ' Neue Rechnung</button>';
require __DIR__ . '/partials/kopf.php';
?>

<?php if ($luecken !== []): ?>
  <div class="hinweis hinweis--warnung mb-4"><?= Icon::svg('alert', 17) ?>
    <div class="hinweis__text">Bevor die erste Rechnung ausgestellt werden kann, fehlt im Rechnungsabsender noch:
      <b><?= Util::h(implode(', ', $luecken)) ?></b>. <a href="<?= Util::attr(App::url('/master/einstellungen.php#rechnungsabsender')) ?>">Jetzt ergänzen</a>.
      Entwürfe lassen sich schon anlegen.</div></div>
<?php endif; ?>

<div class="raster raster--4 mb-5">
  <?= kennzahl('Offen', Util::geld($k['offen_summe']), ['icon' => 'invoices', 'url' => '/master/rechnungen.php?status=offen',
       'fuss' => $k['offen_anzahl'] . ' ' . ($k['offen_anzahl'] === 1 ? 'Rechnung' : 'Rechnungen')]) ?>
  <?= kennzahl('Überfällig', Util::geld($k['ueber_summe']), ['icon' => 'alert', 'url' => '/master/rechnungen.php?status=ueberfaellig',
       'fuss' => $k['ueber_anzahl'] . ' nach Zahlungsziel']) ?>
  <?= kennzahl('Eingegangen diesen Monat', Util::geld($k['bezahlt_monat']), ['icon' => 'check', 'fuss' => 'als bezahlt vermerkt']) ?>
  <?= kennzahl('Umsatz ' . date('Y') . ' (netto)', Util::geld($k['umsatz_jahr']), ['icon' => 'euro',
       'fuss' => 'diesen Monat ' . Util::geld($k['umsatz_monat']) . ' · Stornos abgezogen']) ?>
</div>

<div class="karte">
  <form method="get" class="filterleiste" action="<?= Util::attr(App::url('/master/rechnungen.php')) ?>">
    <div class="such-feld"><?= Icon::svg('search', 16) ?>
      <input class="eingabe" type="search" name="q" value="<?= Util::attr($f['q']) ?>" data-such-absenden placeholder="Nummer, Empfänger, Instanz" aria-label="Rechnungen durchsuchen"></div>
    <select name="status" data-auto-absenden aria-label="Status">
      <option value="">Jeder Status</option>
      <?php foreach (Betreiberrechnungen::STATUS as $s => [$name]): ?>
        <option value="<?= $s ?>"<?= $f['status'] === $s ? ' selected' : '' ?>><?= Util::h($name) ?></option>
      <?php endforeach; ?>
      <option value="ueberfaellig"<?= $f['status'] === 'ueberfaellig' ? ' selected' : '' ?>>Überfällig</option>
    </select>
    <select name="jahr" data-auto-absenden aria-label="Jahr">
      <option value="0">Jedes Jahr</option>
      <?php for ($j = (int) date('Y'); $j >= (int) date('Y') - 5; $j--): ?>
        <option value="<?= $j ?>"<?= $f['jahr'] === $j ? ' selected' : '' ?>><?= $j ?></option>
      <?php endfor; ?>
    </select>
    <?php if ($f['instanz'] > 0): ?><input type="hidden" name="instanz" value="<?= $f['instanz'] ?>">
      <a class="pille pille--marke" href="<?= Util::attr(masterUrl('/master/rechnungen.php', $f, ['instanz' => ''])) ?>">Instanz #<?= $f['instanz'] ?> ✕</a><?php endif; ?>
    <?php if ($k['entwuerfe'] > 0 && $f['status'] !== 'entwurf'): ?>
      <div class="fueller"></div>
      <a class="klein" href="<?= Util::attr(App::url('/master/rechnungen.php?status=entwurf')) ?>"><?= $k['entwuerfe'] ?> <?= $k['entwuerfe'] === 1 ? 'Entwurf' : 'Entwürfe' ?> offen</a>
    <?php endif; ?>
  </form>
  <?php if ($rechnungen === []): ?>
    <div class="karte__koerper"><?= leerzustand('invoices', 'Keine Rechnungen', 'Lege eine Rechnung für eine Instanz an – oder lass dir für einen Monat Entwürfe aus allen laufenden Verträgen erstellen.') ?></div>
  <?php else: ?>
    <div class="tabelle-huelle"><table class="tabelle">
      <thead><tr><th>Nummer</th><th>Instanz · Empfänger</th><th>Datum</th><th>Zeitraum</th><th class="zahl">Betrag</th><th>Status</th><th>Fällig</th></tr></thead>
      <tbody>
        <?php foreach ($rechnungen as $r): $emp = Util::ausJson((string) $r['empfaenger'], []); ?>
          <tr>
            <td class="tabelle__haupt"><a href="<?= Util::attr(App::url('/master/rechnung.php?id=' . (int) $r['id'])) ?>">
              <?= (string) $r['nummer'] !== '' ? '<span class="mono">' . Util::h((string) $r['nummer']) . '</span>' : 'Entwurf #' . (int) $r['id'] ?></a></td>
            <td><?= $r['instanz'] !== null ? Util::h((string) $r['instanz']) : '<span class="gedimmt">gelöschte Instanz #' . (int) $r['instanz_id'] . '</span>' ?>
              <div class="klein gedimmt"><?= Util::h((string) (($emp['firma'] ?? '') ?: ($emp['name'] ?? ''))) ?></div></td>
            <td class="umbruch-nein"><?= $r['datum'] ? Util::h(Util::datum((string) $r['datum'])) : '<span class="gedimmt">—</span>' ?></td>
            <td class="klein"><?= Util::h(Betreiberrechnungen::zeitraum($r)) ?></td>
            <td class="zahl"><?= Util::h(Util::geld((int) $r['brutto_cent'])) ?></td>
            <td><?= Betreiberrechnungen::statusPille((string) $r['status'], $r['faellig'] ? (string) $r['faellig'] : null) ?></td>
            <td class="umbruch-nein"><?= (string) $r['status'] === 'offen' && $r['faellig'] ? Util::h(Util::datum((string) $r['faellig'])) : '<span class="gedimmt">—</span>' ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
    <div class="karte__fuss"><?= App::seitenNavigation($gesamt, 50, $seite, masterUrl('/master/rechnungen.php', $f)) ?></div>
  <?php endif; ?>
</div>

<dialog class="modal" id="modal-neu">
  <form method="post" action="<?= Util::attr(App::url('/master/rechnungen.php')) ?>">
    <?= Auth::csrfFeld() ?><input type="hidden" name="aktion" value="neu">
    <div class="modal__kopf"><h2>Neue Rechnung</h2>
      <button type="button" class="rundknopf" data-modal-zu aria-label="Schließen"><?= Icon::svg('x', 17) ?></button></div>
    <div class="modal__koerper">
      <div class="feld"><label class="feld__label" for="n-instanz">An welche Instanz?</label>
        <select class="eingabe" id="n-instanz" name="instanz" required>
          <option value="">Bitte wählen</option>
          <?php foreach ($instanzen as $w): ?>
            <option value="<?= (int) $w['id'] ?>"<?= $f['instanz'] === (int) $w['id'] ? ' selected' : '' ?>><?= Util::h((string) $w['name']) ?><?= (int) $w['demo'] === 1 ? ' (Demo)' : '' ?></option>
          <?php endforeach; ?>
        </select></div>
      <label class="haken"><input type="checkbox" name="vertrag" value="1" checked>
        <span class="haken__text">Aus dem Vertrag vorbefüllen<span class="haken__hinweis">Paket, Betrag und Leistungszeitraum des aktuellen Monats. Alles bleibt änderbar.</span></span></label>
    </div>
    <div class="modal__fuss"><button type="button" class="btn" data-modal-zu>Abbrechen</button>
      <button type="submit" class="btn btn--primaer">Entwurf anlegen</button></div>
  </form>
</dialog>

<dialog class="modal" id="modal-monat">
  <form method="post" action="<?= Util::attr(App::url('/master/rechnungen.php')) ?>">
    <?= Auth::csrfFeld() ?><input type="hidden" name="aktion" value="monat">
    <div class="modal__kopf"><h2>Entwürfe aus Verträgen</h2>
      <button type="button" class="rundknopf" data-modal-zu aria-label="Schließen"><?= Icon::svg('x', 17) ?></button></div>
    <div class="modal__koerper">
      <p>Für jede Instanz mit laufendem Vertrag entsteht ein <b>Entwurf</b> – monatliche und individuelle Verträge jeden Monat,
        Jahresverträge im Monat ihres Beginns. Ausgestellt wird erst, wenn du jeden Entwurf durchgesehen hast.</p>
      <p class="klein gedimmt">Übersprungen wird, wer für diesen Monat schon einen Entwurf oder eine Rechnung aus demselben Vertrag hat,
        und wer keinen Betrag hätte (Testphase, kostenlos). Demo-Instanzen nie.</p>
      <div class="feld"><label class="feld__label" for="m-monat">Leistungsmonat</label>
        <input class="eingabe" id="m-monat" type="month" name="monat" value="<?= date('Y-m') ?>" required></div>
    </div>
    <div class="modal__fuss"><button type="button" class="btn" data-modal-zu>Abbrechen</button>
      <button type="submit" class="btn btn--primaer">Entwürfe anlegen</button></div>
  </form>
</dialog>

<?php require __DIR__ . '/partials/fuss.php';
