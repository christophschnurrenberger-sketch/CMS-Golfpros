<?php
/**
 * Benutzer über alle Instanzen – wer hat einen Zugang, wo, mit welcher Rolle.
 *
 * Keine Passwörter, keine Tokens, keine Hashes: Die Abfrage nennt ihre
 * Spalten einzeln, statt `*` zu holen. Ändern lässt sich hier nichts –
 * dafür gibt es den Reiter „Benutzer" in der jeweiligen Instanz.
 */
require __DIR__ . '/partials/start.php';

$q = mb_substr(trim(App::get('q')), 0, 120);
$rolle = App::get('rolle');
$status = App::get('status');
$instanz = App::getInt('instanz');
$seite = max(1, App::getInt('seite', 1));
$pro = 50;

$wo = [];
$p = ['jetzt' => Util::jetzt()];
if ($q !== '') {
    $wo[] = "(u.name LIKE :q ESCAPE '!' OR u.email LIKE :q ESCAPE '!' OR w.name LIKE :q ESCAPE '!')";
    $p['q'] = '%' . strtr($q, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
}
if (isset(Auth::ROLLEN[$rolle])) {
    $wo[] = 'u.rolle = :rolle';
    $p['rolle'] = $rolle;
}
if ($status === 'aktiv') {
    $wo[] = 'u.aktiv = 1';
} elseif ($status === 'inaktiv') {
    $wo[] = 'u.aktiv = 0';
} elseif ($status === 'eingeladen') {
    $wo[] = "u.aktiv = 1 AND (u.letzter_login IS NULL OR u.letzter_login = '') AND u.reset_token != '' AND u.reset_bis > :jetzt";
} elseif ($status === 'nie') {
    $wo[] = "(u.letzter_login IS NULL OR u.letzter_login = '')";
}
if ($instanz > 0) {
    $wo[] = 'u.workspace_id = :instanz';
    $p['instanz'] = $instanz;
}
$bedingung = $wo === [] ? '' : ' WHERE ' . implode(' AND ', $wo);
$gesamt = DB::int('SELECT COUNT(*) FROM users u JOIN workspaces w ON w.id = u.workspace_id' . $bedingung,
    array_diff_key($p, $status === 'eingeladen' ? [] : ['jetzt' => 1]));
$benutzer = DB::all("SELECT u.id, u.name, u.email, u.rolle, u.aktiv, u.letzter_login, u.erstellt, u.workspace_id,
        CASE WHEN u.reset_token != '' AND u.reset_bis > :jetzt THEN 1 ELSE 0 END AS einladung_offen,
        w.name AS instanz, w.status AS instanz_status, w.demo
    FROM users u JOIN workspaces w ON w.id = u.workspace_id" . $bedingung
    . ' ORDER BY u.name, u.id LIMIT ' . $pro . ' OFFSET ' . (($seite - 1) * $pro), $p);

$parameter = ['q' => $q, 'rolle' => $rolle, 'status' => $status, 'instanz' => $instanz];
$titel = 'Benutzer';
$unter = Util::zahl($gesamt) . ' Zugänge in Instanzen · Passwörter sind nirgends einsehbar';
$bereich = 'benutzer';
$brotkrumen = [['Übersicht', '/master/'], ['Benutzer', null]];
require __DIR__ . '/partials/kopf.php';
?>

<div class="karte">
  <form method="get" class="filterleiste" action="<?= Util::attr(App::url('/master/benutzer.php')) ?>">
    <div class="such-feld"><?= Icon::svg('search', 16) ?>
      <input class="eingabe" type="search" name="q" value="<?= Util::attr($q) ?>" data-such-absenden placeholder="Name, E-Mail oder Instanz" aria-label="Benutzer durchsuchen"></div>
    <select name="rolle" data-auto-absenden aria-label="Rolle">
      <option value="">Alle Rollen</option>
      <?php foreach (Auth::ROLLEN as $k => $v): ?><option value="<?= $k ?>"<?= $rolle === $k ? ' selected' : '' ?>><?= Util::h($v) ?></option><?php endforeach; ?>
    </select>
    <select name="status" data-auto-absenden aria-label="Status">
      <?php foreach (['' => 'Jeder Status', 'aktiv' => 'Aktiv', 'inaktiv' => 'Deaktiviert', 'eingeladen' => 'Einladung offen', 'nie' => 'Nie angemeldet'] as $k => $v): ?>
        <option value="<?= $k ?>"<?= $status === $k ? ' selected' : '' ?>><?= Util::h($v) ?></option>
      <?php endforeach; ?>
    </select>
    <?php if ($instanz > 0): ?><input type="hidden" name="instanz" value="<?= $instanz ?>">
      <a class="pille pille--marke" href="<?= Util::attr(masterUrl('/master/benutzer.php', $parameter, ['instanz' => ''])) ?>">Instanz #<?= $instanz ?> ✕</a><?php endif; ?>
  </form>
  <?php if ($benutzer === []): ?>
    <div class="karte__koerper"><?= leerzustand('search', 'Keine Treffer', 'Für diese Auswahl gibt es keinen Benutzer.') ?></div>
  <?php else: ?>
    <div class="tabelle-huelle">
      <table class="tabelle">
        <thead><tr><th>Name</th><th>E-Mail</th><th>Rolle</th><th>Instanz</th><th>Status</th><th>Letzte Anmeldung</th></tr></thead>
        <tbody>
          <?php foreach ($benutzer as $u): ?>
            <tr>
              <td class="tabelle__haupt"><?= Util::h((string) $u['name']) ?></td>
              <td><?= Util::h((string) $u['email']) ?></td>
              <td><?= Util::h(Auth::ROLLEN[$u['rolle']] ?? (string) $u['rolle']) ?></td>
              <td><a href="<?= Util::attr(App::url('/master/instanz.php?id=' . (int) $u['workspace_id'] . '&reiter=benutzer')) ?>"><?= Util::h((string) $u['instanz']) ?></a><?= demoPille($u) ?>
                <div><?= Instanzen::statusPille((string) $u['instanz_status']) ?></div></td>
              <td><?= (int) $u['aktiv'] !== 1 ? pille('Deaktiviert', 'offen')
                  : ((int) $u['einladung_offen'] === 1 && !$u['letzter_login'] ? pille('Einladung offen', 'info') : pille('Aktiv', 'erfolg')) ?></td>
              <td><?= wann($u['letzter_login'] ?: null) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="karte__fuss"><?= App::seitenNavigation($gesamt, $pro, $seite, masterUrl('/master/benutzer.php', $parameter)) ?></div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/partials/fuss.php';
