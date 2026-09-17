<?php
/**
 * Änderungsprotokoll.
 *
 * Wer hat wann was geändert. Das Protokoll ist bewusst nur lesbar – es
 * gibt keine Funktion, Einträge zu löschen, sonst wäre es wertlos.
 * Ältere Einträge räumt `Wartung::laufen()` nach der eingestellten
 * Aufbewahrungsfrist selbst weg.
 */
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/partials/helfer.php';

Auth::fordern('modul.settings');
Auth::fordern('audit.view');

$objekte = ['' => 'Alle Bereiche', 'customer' => 'Kunden', 'booking' => 'Termine',
            'invoice' => 'Rechnungen', 'order' => 'Bestellungen', 'user' => 'Team',
            'page' => 'Seiten', 'post' => 'Beiträge', 'service' => 'Leistungen',
            'workspace' => 'Workspace', 'campaign' => 'Kampagnen', 'location' => 'Standorte'];

$objekt = App::get('objekt');
$wer    = App::getInt('wer');
$seite  = max(1, App::getInt('seite', 1));
$proSeite = 50;

$bedingung = [];
$p = [];
if ($objekt !== '' && isset($objekte[$objekt])) {
    $bedingung[] = 'objekt = :o';
    $p['o'] = $objekt;
}
if ($wer > 0) {
    $bedingung[] = 'user_id = :u';
    $p['u'] = $wer;
}
$wo = implode(' AND ', $bedingung);

$gesamt   = Tenant::count('audit_log', $wo, $p);
$eintraege = Tenant::all('audit_log', $wo, $p, 'id DESC', $proSeite, ($seite - 1) * $proSeite);
$team     = Tenant::all('users', '', [], 'name');
$namen    = [];
foreach ($team as $t) {
    $namen[(int) $t['id']] = (string) $t['name'];
}

$titel = 'Änderungsprotokoll';
$unter = $gesamt . ' Einträge. Wer hat wann was geändert.';
$brotkrumen = [['Einstellungen', '/app/einstellungen.php'], ['Protokoll', null]];
require __DIR__ . '/partials/kopf.php';
?>

<form method="get" class="filterleiste mb-4">
  <select name="objekt" onchange="if(window.Blick)window.Blick.merken(this);this.form.submit()">
    <?php foreach ($objekte as $k => $v): ?>
      <option value="<?= Util::attr($k) ?>"<?= $objekt === $k ? ' selected' : '' ?>><?= Util::h($v) ?></option>
    <?php endforeach; ?>
  </select>
  <select name="wer" onchange="if(window.Blick)window.Blick.merken(this);this.form.submit()">
    <option value="0">Alle Personen</option>
    <?php foreach ($team as $t): ?>
      <option value="<?= (int) $t['id'] ?>"<?= $wer === (int) $t['id'] ? ' selected' : '' ?>>
        <?= Util::h((string) $t['name']) ?></option>
    <?php endforeach; ?>
  </select>
  <div class="fueller"></div>
  <?php if ($objekt !== '' || $wer > 0): ?>
    <a class="btn btn--klein" href="<?= Util::attr(App::url('/app/protokoll.php')) ?>">Filter zurücksetzen</a>
  <?php endif; ?>
</form>

<div class="karte">
  <?php if ($eintraege === []): ?>
    <div class="karte__koerper">
      <?= leerzustand('list', 'Keine Einträge',
            'Sobald etwas geändert wird, steht es hier – mit Zeitpunkt, Person und Bereich.') ?>
    </div>
  <?php else: ?>
    <div class="tabelle-huelle">
      <table class="tabelle tabelle--eng">
        <thead><tr>
          <th style="width:170px">Zeitpunkt</th><th>Person</th><th>Vorgang</th>
          <th class="nicht-mobil">Bereich</th><th class="nicht-mobil">Details</th>
        </tr></thead>
        <tbody>
        <?php foreach ($eintraege as $e):
          $name = $namen[(int) $e['user_id']] ?? ((int) $e['user_id'] === 0 ? 'System' : 'Gelöschter Zugang'); ?>
          <tr>
            <td class="umbruch-nein klein gedimmt">
              <?= Util::h(Util::datumZeit((string) $e['erstellt'])) ?>
            </td>
            <td class="klein"><?= Util::h($name) ?></td>
            <td><?= pille(Audit::text((string) $e['aktion']),
                  in_array((string) $e['aktion'], ['geloescht', 'storniert'], true) ? 'gefahr'
                  : ((string) $e['aktion'] === 'erstellt' ? 'erfolg' : '')) ?></td>
            <td class="nicht-mobil klein gedimmt">
              <?= Util::h((string) ($objekte[(string) $e['objekt']] ?? $e['objekt'])) ?>
              <?php if ((int) $e['objekt_id'] > 0): ?>
                <span class="mono winzig">#<?= (int) $e['objekt_id'] ?></span>
              <?php endif; ?>
            </td>
            <td class="nicht-mobil klein gedimmt"><?= Util::h(Util::kuerzen((string) $e['beschreibung'], 70)) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?= App::seitenNavigation($gesamt, $proSeite, $seite,
          App::url('/app/protokoll.php?objekt=' . urlencode($objekt) . '&wer=' . $wer)) ?>
  <?php endif; ?>
</div>

<div class="hinweis hinweis--still mt-4">
  <?= Icon::svg('info', 17) ?>
  <div class="hinweis__text">Das Protokoll lässt sich nicht bearbeiten – nur so ist es als Nachweis
    brauchbar. Einträge werden nach der unter
    <a href="<?= Util::attr(App::url('/app/datenschutz.php')) ?>">Datenschutz</a> eingestellten Frist
    automatisch gelöscht.</div>
</div>

<?php require __DIR__ . '/partials/fuss.php'; ?>
