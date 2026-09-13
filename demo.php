<?php
/**
 * Demo-Zugang ohne Passwort.
 *
 * Gilt ausschließlich für Workspaces mit `demo = 1`. Jeder andere Zugang
 * geht über die normale Anmeldung – auch dann, wenn jemand diese Adresse
 * errät.
 */
require __DIR__ . '/lib/bootstrap.php';

$benutzer = DB::one("SELECT u.id FROM users u JOIN workspaces w ON w.id = u.workspace_id
                     WHERE w.demo = 1 AND u.rolle = 'owner' AND u.aktiv = 1 LIMIT 1");

if ($benutzer && Auth::demoAnmelden((int) $benutzer['id'])) {
    App::melden('Du siehst den Demo-Bereich. Änderungen sind möglich und bleiben erhalten.', 'info');
    App::weiter('/app/');
}
App::melden('Es ist kein Demo-Bereich eingerichtet.', 'fehler');
App::weiter('/login.php');
