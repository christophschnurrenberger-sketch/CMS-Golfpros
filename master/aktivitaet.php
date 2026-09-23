<?php
/**
 * Aktivität – was in den Instanzen und in der Betreiberzentrale passiert.
 *
 * Aus den Instanzen kommen nur Art, Bereich, Person und Zeitpunkt. Den
 * Beschreibungstext des Protokolls zeigt diese Seite nie: Er nennt oft
 * Kunden beim Namen, und die gehören der Instanz.
 */
require __DIR__ . '/partials/start.php';

$quelle = App::get('quelle', 'instanzen') === 'betreiber' ? 'betreiber' : 'instanzen';
$instanz = App::getInt('instanz');
$art = App::get('art');
$mitDemo = App::get('demo') === '1';
$seite = max(1, App::getInt('seite', 1));
$pro = 50;
$arten = ['' => 'Alle Arten', 'login' => 'Anmeldungen', 'erstellt' => 'Angelegt', 'geaendert' => 'Geändert',
          'geloescht' => 'Gelöscht', 'veroeffentlicht' => 'Veröffentlicht', 'versendet' => 'Versendet', 'export' => 'Exporte',
          'support_beginn' => 'Support-Zugriffe'];

if ($quelle === 'instanzen') {
    $wo = ['a.workspace_id > 0', "a.aktion NOT IN ('login_fehlgeschlagen', 'token_falsch', 'einrichtung_falsch', 'wartung_fehler')"];
    $p = [];
    if (!$mitDemo) {
        $wo[] = 'w.demo = 0';
    }
    if ($instanz > 0) {
        $wo[] = 'a.workspace_id = :i';
        $p['i'] = $instanz;
    }
    if ($art !== '' && isset($arten[$art])) {
        $wo[] = 'a.aktion = :art';
        $p['art'] = $art;
    }
    $bedingung = ' WHERE ' . implode(' AND ', $wo);
    $gesamt = DB::int('SELECT COUNT(*) FROM audit_log a JOIN workspaces w ON w.id = a.workspace_id' . $bedingung, $p);
    $eintraege = DB::all('SELECT a.aktion, a.objekt, a.erstellt, a.betreiber_id, a.user_id, a.workspace_id, w.name AS instanz, u.name AS person
        FROM audit_log a JOIN workspaces w ON w.id = a.workspace_id
        LEFT JOIN users u ON u.id = a.user_id AND u.workspace_id = a.workspace_id' . $bedingung
        . ' ORDER BY a.id DESC LIMIT ' . $pro . ' OFFSET ' . (($seite - 1) * $pro), $p);
} else {
    $f = $instanz > 0 ? ['instanz_id' => $instanz] : [];
    $gesamt = Betreiberlog::anzahl($f);
    $eintraege = Betreiberlog::liste($f, $pro, ($seite - 1) * $pro);
}
$objekte = ['customer' => 'Kunde', 'booking' => 'Termin', 'invoice' => 'Rechnung', 'order' => 'Bestellung', 'user' => 'Team',
            'page' => 'Seite', 'post' => 'Beitrag', 'service' => 'Leistung', 'workspace' => 'Instanz', 'campaign' => 'Kampagne',
            'location' => 'Standort', 'system' => 'System'];
$parameter = ['quelle' => $quelle, 'instanz' => $instanz, 'art' => $art, 'demo' => $mitDemo ? '1' : ''];

$titel = 'Aktivität';
$unter = $quelle === 'instanzen' ? 'Was die Teams in ihren Instanzen tun – Art und Zeitpunkt, keine Inhalte.'
                                 : 'Was in der Betreiberzentrale passiert.';
$bereich = 'aktivitaet';
$brotkrumen = [['Übersicht', '/master/'], ['Aktivität', null]];
require __DIR__ . '/partials/kopf.php';
?>

<nav class="reiter mb-4" aria-label="Quelle">
  <a class="reiter__teil<?= $quelle === 'instanzen' ? ' ist-aktiv' : '' ?>" href="<?= Util::attr(App::url('/master/aktivitaet.php')) ?>">In den Instanzen</a>
  <a class="reiter__teil<?= $quelle === 'betreiber' ? ' ist-aktiv' : '' ?>" href="<?= Util::attr(App::url('/master/aktivitaet.php?quelle=betreiber')) ?>">Betreiberzentrale</a>
</nav>

<div class="karte">
  <?php if ($quelle === 'instanzen'): ?>
    <form method="get" class="filterleiste" action="<?= Util::attr(App::url('/master/aktivitaet.php')) ?>">
      <select name="art" data-auto-absenden aria-label="Art">
        <?php foreach ($arten as $k => $v): ?><option value="<?= $k ?>"<?= $art === $k ? ' selected' : '' ?>><?= Util::h($v) ?></option><?php endforeach; ?>
      </select>
      <label class="haken haken--eng"><input type="checkbox" name="demo" value="1"<?= $mitDemo ? ' checked' : '' ?> data-auto-absenden>
        <span class="haken__text">Demo-Instanzen zeigen</span></label>
      <?php if ($instanz > 0): ?><input type="hidden" name="instanz" value="<?= $instanz ?>">
        <a class="pille pille--marke" href="<?= Util::attr(masterUrl('/master/aktivitaet.php', $parameter, ['instanz' => ''])) ?>">Instanz #<?= $instanz ?> ✕</a><?php endif; ?>
    </form>
  <?php endif; ?>

  <?php if ($eintraege === []): ?>
    <div class="karte__koerper"><p class="gedimmt mb-0">Keine Einträge.</p></div>
  <?php elseif ($quelle === 'instanzen'): ?>
    <div class="tabelle-huelle"><table class="tabelle tabelle--eng">
      <thead><tr><th>Wann</th><th>Instanz</th><th>Wer</th><th>Was</th><th>Bereich</th></tr></thead>
      <tbody>
        <?php foreach ($eintraege as $a): ?>
          <tr><td class="umbruch-nein"><?= wann((string) $a['erstellt']) ?></td>
            <td><a href="<?= Util::attr(App::url('/master/instanz.php?id=' . (int) $a['workspace_id'])) ?>"><?= Util::h((string) $a['instanz']) ?></a></td>
            <td><?= (int) $a['betreiber_id'] > 0 ? '<span class="pille pille--warnung">TeePilot Support</span>'
                : ((int) $a['user_id'] > 0 ? Util::h((string) ($a['person'] ?? 'Benutzer #' . (int) $a['user_id'])) : '<span class="gedimmt">System</span>') ?></td>
            <td><?= Util::h(Audit::text((string) $a['aktion'])) ?></td>
            <td><?= Util::h($objekte[$a['objekt']] ?? (string) $a['objekt']) ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php else: ?>
    <div class="tabelle-huelle"><table class="tabelle tabelle--eng">
      <thead><tr><th>Wann</th><th>Wer</th><th>Was</th><th>Instanz</th><th>Ergebnis</th></tr></thead>
      <tbody>
        <?php foreach ($eintraege as $z): ?>
          <tr><td class="umbruch-nein"><?= wann((string) $z['zeit']) ?></td>
            <td><?= Util::h((string) $z['akteur'] ?: '—') ?></td>
            <td><a href="<?= Util::attr(App::url('/master/protokoll.php?id=' . (int) $z['id'])) ?>"><?= Util::h(Betreiberlog::text((string) $z['aktion'])) ?></a></td>
            <td><?= (int) $z['instanz_id'] > 0 ? '<a href="' . Util::attr(App::url('/master/instanz.php?id=' . (int) $z['instanz_id'])) . '">#' . (int) $z['instanz_id'] . '</a>' : '<span class="gedimmt">—</span>' ?></td>
            <td><?= Util::h(Betreiberlog::ERGEBNIS[$z['ergebnis']] ?? (string) $z['ergebnis']) ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
  <div class="karte__fuss"><?= App::seitenNavigation($gesamt, $pro, $seite, masterUrl('/master/aktivitaet.php', $parameter)) ?></div>
</div>

<?php require __DIR__ . '/partials/fuss.php';
