<?php
/**
 * Support Mode starten und beenden. Nur POST, nur mit CSRF-Merkmal.
 *
 * Die Instanz kommt aus dem Formular, aber was mit ihr geschieht,
 * entscheidet Support::starten(): Es lädt sie selbst, sucht selbst den
 * Inhaber und prüft selbst das Recht – eine manipulierte ID führt
 * höchstens zu „Diese Instanz gibt es nicht".
 */
require __DIR__ . '/partials/start.php';

if (!App::istPost()) {
    http_response_code(405);
    header('Allow: POST');
    exit('Nur per Formular.');
}
Auth::csrfFordern();

if (App::aktion() === 'ende') {
    $instanz = Support::beenden('beendet');
    App::melden('Support Mode beendet.', 'info');
    App::weiter($instanz > 0 ? '/master/instanz.php?id=' . $instanz : '/master/');
}

Betreiber::fordern('support.start');
$id = App::postInt('id');
[$ok, $meldung] = Support::starten($id, App::post('grund'));
if (!$ok) {
    App::melden($meldung, 'fehler');
    App::weiter('/master/instanz.php?id=' . $id);
}
App::weiter('/app/');
