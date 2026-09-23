<?php
/**
 * Eine Rechnung von TeePilot an diese Instanz – als PDF.
 *
 * Nur ausgestellte Rechnungen, nur die der eigenen Instanz: Die Abfrage
 * nennt die Instanz aus der Sitzung, nie aus der Adresse. Eine fremde ID
 * ergibt dasselbe 404 wie eine erfundene.
 */
require __DIR__ . '/../lib/bootstrap.php';

Auth::fordern('modul.settings');
Auth::fordern('settings.allgemein');

$r = DB::one("SELECT * FROM betreiber_rechnungen WHERE id = :id AND instanz_id = :w AND status != 'entwurf'",
    ['id' => App::getInt('id'), 'w' => Tenant::id()]);
$inhalt = $r !== null ? Betreiberrechnungen::dateiInhalt($r) : null;
if ($inhalt === null) {
    http_response_code(404);
    exit('Diese Rechnung gibt es nicht.');
}
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Z0-9-]/', '', (string) $r['nummer']) . '.pdf"');
header('Content-Length: ' . strlen($inhalt));
header('Cache-Control: private, no-store');
echo $inhalt;
