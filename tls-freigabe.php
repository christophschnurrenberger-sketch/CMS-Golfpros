<?php
/**
 * Darf Caddy für diese Domain ein Zertifikat holen?
 *
 * Auf einem eigenen Server holt Caddy die Zertifikate der Instanzen
 * selbst: Zeigt golfschule-mueller.de auf den Server und ruft jemand die
 * Seite auf, fragt Caddy zuerst hier nach – 200 heißt ja, alles andere
 * nein. So bekommt jede Instanz mit eigener Domain HTTPS, ohne dass am
 * Server jemand etwas einträgt. Die Regel selbst steht in
 * App::zertifikatErlaubt().
 *
 * Antwortet nur Anfragen vom selben Rechner. Von außen soll niemand
 * durchprobieren können, welche Domains hier eingetragen sind; die
 * Caddy-Konfiguration sperrt die Datei für alle öffentlichen Adressen
 * zusätzlich. Siehe docs/SERVER.md.
 */
require __DIR__ . '/lib/bootstrap.php';

if (!in_array((string) ($_SERVER['REMOTE_ADDR'] ?? ''), ['127.0.0.1', '::1'], true)) {
    http_response_code(404);
    exit;
}

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

if (App::zertifikatErlaubt(App::get('domain'))) {
    echo "ja\n";
    exit;
}
http_response_code(404);
echo "nein\n";
