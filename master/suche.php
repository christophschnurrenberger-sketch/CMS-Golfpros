<?php
/**
 * Suche der Befehlspalette in der Betreiberzentrale: Instanzen und
 * Personen, höchstens je acht Treffer. Antwortet nur Betreibern.
 */
$masterJson = true;
require __DIR__ . '/partials/start.php';

$q = mb_substr(trim(App::get('q')), 0, 80);
if (mb_strlen($q) < 2) {
    App::json(['treffer' => []]);
}
$treffer = [];
[$instanzen] = Instanzen::liste(Instanzen::filter(['q' => $q, 'status' => 'alle', 'sort' => 'name', 'richtung' => 'asc']), 1, 8);
foreach ($instanzen as $w) {
    $treffer[] = ['titel' => (string) $w['name'], 'unter' => Instanzen::statusName((string) $w['status']) . ' · '
        . Pakete::name((string) $w['plan']) . ' · #' . (int) $w['id'],
        'url' => App::url('/master/instanz.php?id=' . (int) $w['id']), 'gruppe' => 'Instanzen', 'icon' => Icon::svg('building', 17)];
}
$muster = '%' . strtr($q, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
foreach (DB::all("SELECT u.name, u.email, u.workspace_id, w.name AS instanz FROM users u JOIN workspaces w ON w.id = u.workspace_id
                  WHERE u.name LIKE :q ESCAPE '!' OR u.email LIKE :q ESCAPE '!' ORDER BY u.name LIMIT 8", ['q' => $muster]) as $u) {
    $treffer[] = ['titel' => (string) $u['name'], 'unter' => $u['email'] . ' · ' . $u['instanz'],
        'url' => App::url('/master/instanz.php?id=' . (int) $u['workspace_id'] . '&reiter=benutzer'),
        'gruppe' => 'Benutzer', 'icon' => Icon::svg('user', 17)];
}
App::json(['treffer' => $treffer]);
