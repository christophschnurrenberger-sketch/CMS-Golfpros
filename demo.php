<?php
/**
 * Demo-Zugang ohne Passwort.
 *
 * Standardmäßig aus. Wer diese Seite aufruft, bekam bis hierher eine
 * gültige Sitzung mit der Rolle „owner“ – ohne Passwort, ohne Bremse,
 * ohne Protokollzeile. Für eine öffentliche Vorführung ist das gewollt,
 * auf dem Server eines Golflehrers ist es eine offene Tür: Der Demo-
 * Workspace steht dort mit, weil das Häkchen bei der Einrichtung gesetzt
 * war, und niemand denkt später an diese Datei.
 *
 * Darum muss der Zugang jetzt ausdrücklich erlaubt werden:
 *
 *     'demo_zugang' => true,      // in config.php
 *
 * Ohne diesen Eintrag verhält sich die Seite, als gäbe es sie nicht. Am
 * sichersten bleibt es, sie auf dem Produktivserver zu löschen – der
 * Schalter ist die zweite Sicherung, nicht die erste.
 *
 * Gilt auch dann ausschließlich für Workspaces mit `demo = 1`.
 */
require __DIR__ . '/lib/bootstrap.php';

if (!Config::get('demo_zugang', false)) {
    http_response_code(404);
    exit('Nicht gefunden.');
}

$benutzer = DB::one("SELECT u.id FROM users u JOIN workspaces w ON w.id = u.workspace_id
                     WHERE w.demo = 1 AND u.rolle = 'owner' AND u.aktiv = 1 LIMIT 1");

if ($benutzer && Auth::demoAnmelden((int) $benutzer['id'])) {
    App::melden('Du siehst den Demo-Bereich. Änderungen sind möglich und bleiben erhalten.', 'info');
    App::weiter('/app/');
}
App::melden('Es ist kein Demo-Bereich eingerichtet.', 'fehler');
App::weiter('/login.php');
