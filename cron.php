<?php
/**
 * Zeitgesteuerte Wartung.
 *
 * Wer beim Hoster einen Cronjob einrichten kann, ruft diese Datei
 * viertelstündlich auf – dann laufen Erinnerungen, Automationen und
 * Fälligkeiten pünktlich statt beim nächsten Dashboard-Aufruf.
 *
 *   Beispiel (alle 15 Minuten):
 *   php /pfad/zur/installation/cron.php
 *   oder als URL: https://deine-domain.de/cron.php?schluessel=…
 *
 * Über den Browser braucht es den Schlüssel aus den Einstellungen; von der
 * Kommandozeile nicht, denn dort kommt niemand von außen hin.
 */
require __DIR__ . '/lib/bootstrap.php';

$konsole = PHP_SAPI === 'cli';

if (!$konsole) {
    $erwartet = (string) DB::value(
        'SELECT wert FROM settings WHERE workspace_id = 0 AND schluessel = :s', ['s' => 'cron_schluessel'], '');
    if ($erwartet === '') {
        // Beim ersten Aufruf einen erzeugen, damit niemand einen ausdenken muss.
        $erwartet = Util::token(16);
        DB::insert('settings', ['workspace_id' => 0, 'schluessel' => 'cron_schluessel', 'wert' => $erwartet]);
    }
    if (!hash_equals($erwartet, App::get('schluessel'))) {
        http_response_code(403);
        exit("Falscher oder fehlender Schlüssel.\n");
    }
}

header('Content-Type: text/plain; charset=utf-8');

$begonnen = microtime(true);
$zeilen   = [];

/*
 * Jeder Workspace einzeln: Die Wartung arbeitet mandantengebunden, und
 * ein Fehler in einem Betrieb darf die anderen nicht anhalten.
 */
foreach (DB::all('SELECT id, name FROM workspaces WHERE aktiv = 1 ORDER BY id') as $w) {
    Tenant::setzen((int) $w['id']);
    try {
        $ergebnis = Wartung::laufen(true);
        $zeilen[] = $w['name'] . ': ' . ($ergebnis === []
            ? 'nichts zu tun'
            : implode(', ', array_map(
                static fn ($k, $v) => $k . '=' . $v, array_keys($ergebnis), $ergebnis)));
    } catch (Throwable $e) {
        $zeilen[] = $w['name'] . ': FEHLER – ' . $e->getMessage();
    }

    /* Fällige Kampagnen in kleinen Schüben – Massenversand mag kein Hoster. */
    try {
        foreach (Tenant::all('campaigns', "status = 'geplant' AND geplant <= :jetzt",
                             ['jetzt' => Util::jetzt()], 'geplant', 3) as $k) {
            $r = Campaigns::versenden((int) $k['id'], 25);
            $zeilen[] = '  Newsletter „' . $k['name'] . '": ' . (int) ($r['gesendet'] ?? 0) . ' verschickt';
        }
    } catch (Throwable $e) {
        $zeilen[] = '  Newsletter: FEHLER – ' . $e->getMessage();
    }
}

$zeilen[] = sprintf('Fertig in %.2f Sekunden.', microtime(true) - $begonnen);
echo implode("\n", $zeilen), "\n";
