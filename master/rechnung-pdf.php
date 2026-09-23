<?php
/**
 * Das PDF einer Rechnung – für Betreiber.
 *
 * Ausgestellt: die beim Ausstellen abgelegte Datei, geprüft gegen ihren
 * Abdruck. Entwurf: eine Vorschau, jedes Mal neu gesetzt und deutlich als
 * Entwurf gekennzeichnet.
 */
require __DIR__ . '/partials/start.php';
Betreiber::fordern('rechnungen.view');

$r = Betreiberrechnungen::finden(App::getInt('id'));
if ($r === null) {
    http_response_code(404);
    exit('Diese Rechnung gibt es nicht.');
}
if ((string) $r['status'] === 'entwurf') {
    Betreiberrechnungen::pdf($r, Betreiberrechnungen::positionen((int) $r['id']))->ausliefern('entwurf-' . (int) $r['id'] . '.pdf');
}
$inhalt = Betreiberrechnungen::dateiInhalt($r);
if ($inhalt === null) {
    http_response_code(409);
    exit('Die abgelegte Datei fehlt oder stimmt nicht mehr mit dem Abdruck beim Ausstellen überein.');
}
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Z0-9-]/', '', (string) $r['nummer']) . '.pdf"');
header('Content-Length: ' . strlen($inhalt));
header('Cache-Control: private, no-store');
echo $inhalt;
