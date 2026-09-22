<?php
/**
 * Die Schnittstelle für angeschlossene Systeme.
 *
 *   GET /api.php?was=empfaenger      Empfänger mit Newsletter-Einwilligung
 *   GET /api.php?was=abmeldungen     Adressen, die nicht (mehr) einwilligen
 *   GET /api.php?was=auskunft        Selbsttest: Schlüssel gültig? Wie viele?
 *
 * Mit mod_rewrite geht auch der saubere Weg: /api/v1/empfaenger.
 *
 * Angemeldet wird über eine Kopfzeile:
 *
 *   Authorization: Bearer gp_2_a1b2c3…
 *
 * Den Schlüssel erzeugt der Inhaber unter Einstellungen → Schnittstelle.
 * Die vollständige Beschreibung steht in docs/API.md – die Datei ist zum
 * Weitergeben gedacht.
 *
 * Eine eigene Datei im Wurzelverzeichnis und kein Ordner mit Umschreibungen:
 * Auf günstigem Hosting ist mod_rewrite nicht sicher vorhanden, und eine
 * Schnittstelle, die je nach Server einmal läuft und einmal nicht, ist
 * schlimmer als eine mit einer unschönen Adresse.
 */
require __DIR__ . '/lib/bootstrap.php';

/*
 * Lesen heißt lesen. Ein POST auf diese Datei kann nichts bewirken, also
 * wird er auch nicht erst angenommen – das erspart die Frage, ob hier
 * jemand ohne CSRF-Merkmal etwas verändern könnte.
 */
$verfahren = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($verfahren, ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    Api::fehler('verfahren_nicht_erlaubt', 'Diese Schnittstelle liest nur. Erlaubt ist GET.', 405);
}

[$angemeldet, $code, $text] = Api::anmelden();
if (!$angemeldet) {
    if ($code === 'zu_viele_versuche') {
        header('Retry-After: 900');
        Api::fehler($code, $text, 429);
    }
    /* WWW-Authenticate sagt dem Aufrufer, wie er es richtig macht. */
    header('WWW-Authenticate: Bearer realm="TeePilot"');
    Api::fehler($code, $text, 401);
}

$was = strtolower(App::get('was', 'auskunft'));

$daten = match ($was) {
    'auskunft'    => Api::auskunft(),
    'empfaenger'  => Api::empfaenger([
        'seite'     => App::getInt('seite', 1),
        'pro_seite' => App::getInt('pro_seite', 0),
        'seit'      => App::get('seit'),
    ]),
    'abmeldungen' => Api::abmeldungen([
        'seite'     => App::getInt('seite', 1),
        'pro_seite' => App::getInt('pro_seite', 0),
        'seit'      => App::get('seit'),
    ]),
    default       => null,
};

if ($daten === null) {
    Api::fehler('unbekannt', 'Das gibt es hier nicht. Möglich sind: empfaenger, abmeldungen, auskunft.', 404);
}

Api::antwort($daten);
