<?php
/**
 * Prüfungen für den Betrieb auf einem eigenen Server – gegen eine
 * Wegwerf-Datenbank.
 *
 *   php tests/server.php
 *
 * Im Mittelpunkt steht die Frage, die Caddy vor jedem Zertifikat stellt:
 * Gehört diese Domain hierher? Ein Ja zu viel heißt Zertifikate für
 * Fremde auf Kosten der Anlage, ein Nein zu viel eine Golfschule ohne
 * HTTPS. Die Caddy-Konfiguration selbst prüft docs/SERVER.md von Hand;
 * hier geht es um die Antwort der Anwendung.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
define('GP_ROOT', dirname(__DIR__));

$ordner = sys_get_temp_dir() . '/teepilot-server-' . bin2hex(random_bytes(4));
mkdir($ordner, 0700, true);
$port = 19000 + random_int(0, 999);
file_put_contents($ordner . '/config.php', '<?php return ' . var_export([
    'db' => ['driver' => 'sqlite', 'path' => $ordner . '/test.sqlite'],
    'secret' => bin2hex(random_bytes(32)),
    'base_url' => 'https://app.teepilot.example',
    'erlaubte_hosts' => ['zweit.teepilot.example:8443'],
    'mail' => ['transport' => 'keiner'],
    'debug' => false,
], true) . ';');
putenv('GP_CONFIG=' . $ordner . '/config.php');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require GP_ROOT . '/lib/bootstrap.php';

$ergebnisse = ['ok' => 0, 'fehl' => 0];
function pruefe(bool $bedingung, string $text): void
{
    global $ergebnisse;
    $ergebnisse[$bedingung ? 'ok' : 'fehl']++;
    fwrite(STDOUT, ($bedingung ? "  \033[32m✓\033[0m " : "  \033[31m✗ FEHLER:\033[0m ") . $text . PHP_EOL);
}
function abschnitt(string $titel): void
{
    fwrite(STDOUT, PHP_EOL . $titel . PHP_EOL);
}

$jetzt = date('Y-m-d H:i:s');
DB::insert('workspaces', ['slug' => 'mueller', 'name' => 'Golfschule Müller', 'domain' => 'golfschule-mueller.example', 'aktiv' => 1, 'erstellt' => $jetzt]);
DB::insert('workspaces', ['slug' => 'alt', 'name' => 'Alte Schule', 'domain' => 'alte-schule.example', 'aktiv' => 0, 'erstellt' => $jetzt]);

$server = null;
try {
    abschnitt('Wer ein Zertifikat bekommt');
    $faelle = [
        ['app.teepilot.example', true, 'die Adresse der Anlage'],
        ['APP.TeePilot.example', true, 'Groß- und Kleinschreibung egal'],
        ['zweit.teepilot.example', true, 'ein weiterer Name aus erlaubte_hosts'],
        ['golfschule-mueller.example', true, 'die Domain einer aktiven Instanz'],
        ['www.golfschule-mueller.example', true, 'dieselbe mit www'],
        ['alte-schule.example', false, 'die Domain einer abgeschalteten Instanz'],
        ['fremd.example', false, 'eine fremde Domain'],
        ['mueller.example', false, 'ein Teil einer eingetragenen Domain'],
        ['x.golfschule-mueller.example', false, 'eine Subdomain einer eingetragenen Domain'],
        ['golfschule-mueller.example.boese.example', false, 'eingetragene Domain als Vorsilbe'],
        ['', false, 'leer'],
        ['127.0.0.1', false, 'eine IP-Adresse'],
        ['golfschule-mueller.example:443', false, 'mit Port'],
        ["golfschule-mueller.example\r\nX: y", false, 'mit Zeilenumbruch'],
        ['*.golfschule-mueller.example', false, 'Platzhalter'],
        [str_repeat('a', 250) . '.example', false, 'zu lang'],
    ];
    foreach ($faelle as [$domain, $erwartet, $text]) {
        pruefe(App::zertifikatErlaubt($domain) === $erwartet, ($erwartet ? 'ja: ' : 'nein: ') . $text);
    }

    abschnitt('Die Freigabe über HTTP');
    $server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', GP_ROOT],
        [0 => ['pipe', 'r'], 1 => ['file', $ordner . '/server.log', 'a'], 2 => ['file', $ordner . '/server.log', 'a']],
        $rohre, GP_ROOT, ['GP_CONFIG' => $ordner . '/config.php', 'PATH' => getenv('PATH')]);
    for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) {
        usleep(100000);
    }
    $frage = static function (string $domain) use ($port): array {
        $c = curl_init('http://127.0.0.1:' . $port . '/tls-freigabe.php?domain=' . rawurlencode($domain));
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_PROXY => '']);
        $text = (string) curl_exec($c);
        $code = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
        curl_close($c);
        return [$code, trim($text)];
    };
    pruefe($frage('golfschule-mueller.example') === [200, 'ja'], 'eingetragene Domain: 200');
    pruefe($frage('fremd.example')[0] === 404, 'fremde Domain: 404');
    pruefe($frage('alte-schule.example')[0] === 404, 'abgeschaltete Instanz: 404');

    abschnitt('Nur vom selben Rechner');
    $code = trim((string) shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg(
        '$_SERVER["REMOTE_ADDR"] = "203.0.113.7"; $_GET["domain"] = "golfschule-mueller.example";'
        . ' ob_start(); register_shutdown_function(function () { $aus = ob_get_clean();'
        . ' fwrite(STDOUT, http_response_code() . "|" . $aus); });'
        . ' require "' . GP_ROOT . '/tls-freigabe.php";'
    ) . ' 2>/dev/null'));
    pruefe(str_starts_with($code, '404|') && !str_contains($code, 'ja'), 'Anfrage von außen: 404, keine Auskunft');
} finally {
    if ($server !== null) {
        proc_terminate($server);
        proc_close($server);
    }
    foreach (glob($ordner . '/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($ordner);
}

fwrite(STDOUT, PHP_EOL . $ergebnisse['ok'] . ' bestanden, ' . $ergebnisse['fehl'] . ' fehlgeschlagen.' . PHP_EOL);
exit($ergebnisse['fehl'] === 0 ? 0 : 1);
