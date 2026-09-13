<?php
/**
 * Einstiegspunkt der Installation.
 *
 * Liegt hinter dieser Adresse eine veröffentlichte Website, wird sie
 * ausgeliefert – der Pro will seine Seite sehen, nicht ein Login. Gibt es
 * keine, geht es zur Anmeldung.
 */
require __DIR__ . '/lib/bootstrap.php';

$host = strtolower(preg_replace('/^www\./', '', (string) ($_SERVER['HTTP_HOST'] ?? '')) ?? '');
$workspace = Tenant::nachDomain($host);

if (!$workspace) {
    $anzahl = DB::int('SELECT COUNT(*) FROM workspaces WHERE aktiv = 1');
    if ($anzahl === 1) {
        $workspace = Tenant::erster();
    }
}

if ($workspace) {
    Tenant::setzen((int) $workspace['id']);
    if (Tenant::count('pages', "status = 'veroeffentlicht'") > 0) {
        $_GET['w'] = (string) $workspace['slug'];
        require __DIR__ . '/site.php';
        exit;
    }
}

App::weiter(Auth::angemeldet() ? '/app/' : '/login.php');
