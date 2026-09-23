<?php
if (!defined('GP_ROOT')) {
    exit;
}
$kBefehle = [];
foreach ([
    ['Neue Instanz anlegen', '/master/instanz-neu.php', 'plus', 'instanz neu anlegen erstellen kunde'],
    ['Instanzen', '/master/instanzen.php', 'building', 'instanzen liste kunden workspaces'],
    ['Übersicht', '/master/', 'dashboard', 'dashboard übersicht start'],
    ['Pakete', '/master/pakete.php', 'layers', 'pakete tarife preise'],
    ['Rechnungen', '/master/rechnungen.php', 'invoices', 'rechnungen abrechnung faktura offen bezahlt'],
    ['Benutzer', '/master/benutzer.php', 'customers', 'benutzer personen zugänge'],
    ['Auswertung', '/master/auswertung.php', 'analytics', 'auswertung analytics zahlen'],
    ['Aktivität', '/master/aktivitaet.php', 'activity', 'aktivität verlauf'],
    ['Audit-Log', '/master/protokoll.php', 'shield', 'audit protokoll log'],
    ['Einstellungen', '/master/einstellungen.php', 'settings', 'einstellungen betreiber schwellen'],
    ['System', '/master/system.php', 'monitor', 'system prüfung status'],
] as [$kT, $kU, $kI, $kW]) {
    $kBefehle[] = ['titel' => $kT, 'url' => App::url($kU), 'gruppe' => 'Betreiberzentrale',
                   'icon' => Icon::svg($kI, 17), 'schlagworte' => $kW, 'start' => true];
}
?>
  </main>
</div>
</div>

<script>
window.gpBasis = <?= Util::json(App::basis()) ?>;
window.gpCsrf  = <?= Util::json(Auth::csrf()) ?>;
window.gpBefehle = <?= Util::json($kBefehle) ?>;
window.gpSuche = <?= Util::json(App::url('/master/suche.php')) ?>;
</script>
<script src="<?= Util::attr(App::asset('assets/js/blick.js')) ?>"></script>
<script src="<?= Util::attr(App::asset('assets/js/app.js')) ?>"></script>
<?php if (!empty($skripte)) { echo $skripte; } ?>
</body>
</html>
