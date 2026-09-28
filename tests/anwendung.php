<?php
/**
 * Jede Seite der Anwendung wird vollständig ausgeliefert – gegen eine
 * Wegwerf-Datenbank mit den Demodaten.
 *
 *   php tests/anwendung.php
 *
 * Anlass: Die Kopfzeile (app/partials/kopf.php) läuft im
 * Gültigkeitsbereich der Seite und setzte `$kunde` auf die Markenfarbe.
 * Auf der Kundenakte war danach aus dem Kunden ein Farbwert geworden, die
 * Seite brach hinter der Kopfzeile ab – man sah Name und Überschrift,
 * darunter eine leere Karte. Ein Absturz mitten in der Seite fällt beim
 * Durchklicken leicht als „leere Ansicht“ durch. Deshalb hier: jede Seite
 * muss bis `</html>` kommen, und der Fehlerlog des Servers bleibt leer.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
define('GP_ROOT', dirname(__DIR__));

$ordner = sys_get_temp_dir() . '/teepilot-app-' . bin2hex(random_bytes(4));
mkdir($ordner, 0700, true);
$port = 19000 + random_int(0, 999);
file_put_contents($ordner . '/config.php', '<?php return ' . var_export([
    'db' => ['driver' => 'sqlite', 'path' => $ordner . '/test.sqlite'],
    'secret' => bin2hex(random_bytes(32)),
    'base_url' => 'http://127.0.0.1:' . $port,
    'demo_zugang' => false,
    'mail' => ['from_name' => 'TeePilot Test', 'from_email' => 'test@example.org', 'transport' => 'keiner'],
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

/* ------------------------------------------------- Regel der Rahmen --- */

abschnitt('Kopf und Fuß überschreiben keine Variablen der Seite');
/*
 * Erlaubt sind die Werte, die eine Seite vor dem Einbinden setzen darf
 * (siehe Kopfkommentar der Dateien), und eigene mit $k und Großbuchstabe.
 * `$kunde` beginnt auch mit k – genau das war der Fehler.
 *
 * Als Schreiben zählt: Zuweisung (auch .= ??= ++), und jede Variable
 * hinter `as` einer Schleife bis zur schließenden Klammer – so fallen
 * auch `as [$ziel, $name]` und `as $k => $v` auf.
 */
$eingaben = ['titel', 'unter', 'aktionen', 'brotkrumen', 'bereich', 'ansicht', 'inhaltKlasse', 'ohneKopf', 'vollbild'];
$schreibend = static function (string $code): array {
    $zuweisung = [T_CONCAT_EQUAL, T_PLUS_EQUAL, T_MINUS_EQUAL, T_MUL_EQUAL, T_DIV_EQUAL, T_MOD_EQUAL,
        T_COALESCE_EQUAL, T_POW_EQUAL, T_AND_EQUAL, T_OR_EQUAL, T_XOR_EQUAL, T_SL_EQUAL, T_SR_EQUAL, T_INC, T_DEC];
    $zeichen = array_values(array_filter(token_get_all($code),
        static fn ($z) => !is_array($z) || !in_array($z[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
    $namen = [];
    $inSchleife = false;
    $tiefe = 0;
    foreach ($zeichen as $i => $z) {
        if (is_array($z) && $z[0] === T_AS) {
            $inSchleife = true;
            $tiefe = 0;
            continue;
        }
        if ($inSchleife) {
            if ($z === '(' || $z === '[') {
                $tiefe++;
            } elseif ($z === ']') {
                $tiefe--;
            } elseif ($z === ')') {
                if ($tiefe === 0) {
                    $inSchleife = false;
                } else {
                    $tiefe--;
                }
            }
        }
        if (!is_array($z) || $z[0] !== T_VARIABLE) {
            continue;
        }
        $naechstes = $zeichen[$i + 1] ?? null;
        if ($inSchleife || $naechstes === '=' || (is_array($naechstes) && in_array($naechstes[0], $zuweisung, true))) {
            $namen[substr($z[1], 1)] = true;
        }
    }
    return array_keys($namen);
};
foreach (['app', 'master', 'portal'] as $teil) {
    foreach (['kopf', 'fuss'] as $rahmen) {
        $datei = $teil . '/partials/' . $rahmen . '.php';
        $fremd = array_filter($schreibend((string) file_get_contents(GP_ROOT . '/' . $datei)),
            static fn ($n) => !in_array($n, $eingaben, true) && !preg_match('/^k[A-Z]/', $n));
        pruefe($fremd === [], $datei . ($fremd === [] ? '' : ': ' . implode(', ', array_map(static fn ($n) => '$' . $n, $fremd))));
    }
}
$probe = $schreibend('<?php $kunde = 1; $kMarke = 2; $a .= "x"; foreach ($l as [$ziel, $name]) {} foreach ($l as $k => $v) {}');
sort($probe);
pruefe($probe === ['a', 'k', 'kMarke', 'kunde', 'name', 'v', 'ziel'], 'die Prüfung selbst erkennt Zuweisung, .= und Schleifen');

/* ------------------------------------------------------------ Bestand --- */

$demo = Demo::anlegen(['email' => 'demo@teepilot.test', 'passwort' => 'Test!2026sicher']);
Tenant::setzen($demo);
$kunden = array_map('intval', array_column(Tenant::all('customers', '1=1', [], 'id'), 'id'));
Tenant::setzen(0);

/* ------------------------------------------------------------- Server --- */

$server = proc_open([PHP_BINARY, '-d', 'log_errors=1', '-d', 'error_log=' . $ordner . '/fehler.log',
        '-S', '127.0.0.1:' . $port, '-t', GP_ROOT],
    [0 => ['pipe', 'r'], 1 => ['file', $ordner . '/server.log', 'a'], 2 => ['file', $ordner . '/server.log', 'a']],
    $rohre, GP_ROOT, ['GP_CONFIG' => $ordner . '/config.php', 'PATH' => getenv('PATH')]);
for ($i = 0; $i < 50; $i++) {
    if (@fsockopen('127.0.0.1', $port)) {
        break;
    }
    usleep(100000);
}

final class Besucher
{
    private string $kekse;
    public int $status = 0;
    public string $inhalt = '';
    public string $art = '';
    public string $ort = '';

    public function __construct(private string $basis, string $ordner)
    {
        $this->kekse = $ordner . '/kekse-' . bin2hex(random_bytes(4));
    }

    public function holen(string $pfad, ?array $post = null): self
    {
        $c = curl_init((str_starts_with($pfad, 'http') ? '' : $this->basis) . $pfad);
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEJAR => $this->kekse, CURLOPT_COOKIEFILE => $this->kekse, CURLOPT_TIMEOUT => 30]);
        if ($post !== null) {
            curl_setopt($c, CURLOPT_POST, true);
            curl_setopt($c, CURLOPT_POSTFIELDS, http_build_query($post));
        }
        $this->inhalt = (string) curl_exec($c);
        $this->status = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
        $this->art = (string) curl_getinfo($c, CURLINFO_CONTENT_TYPE);
        $this->ort = (string) curl_getinfo($c, CURLINFO_REDIRECT_URL);
        curl_close($c);
        return $this;
    }

    public function csrf(string $pfad): string
    {
        $this->holen($pfad);
        preg_match('/name="_csrf" value="([^"]+)"/', $this->inhalt, $m);
        return $m[1] ?? '';
    }

    public function anmelden(string $email, string $pw): self
    {
        return $this->holen('/login.php', ['_csrf' => $this->csrf('/login.php'), 'email' => $email, 'passwort' => $pw]);
    }

    /** Eine HTML-Seite, die bis zum Ende ausgeliefert wurde. */
    public function vollstaendig(): bool
    {
        return $this->status === 200 && str_contains(substr(rtrim($this->inhalt), -40), '</html>');
    }
}

$basis = 'http://127.0.0.1:' . $port;
try {
    $pro = (new Besucher($basis, $ordner))->anmelden('demo@teepilot.test', 'Test!2026sicher');
    pruefe($pro->status === 302 && str_contains($pro->ort, '/app/'), 'Anmeldung als Inhaber der Demo');

    abschnitt('Kunde anlegen und ansehen');
    $pro->holen('/app/kunde.php?id=neu', ['_csrf' => $pro->csrf('/app/kunde.php?id=neu'), 'aktion' => 'speichern',
        'vorname' => 'Max', 'nachname' => 'Musti', 'email' => 'max.musti@example.org', 'hcp' => '49,2',
        'heimclub' => 'Golfclub Ottobeuren', 'status' => 'aktiv']);
    $neu = preg_match('/kunde\.php\?id=(\d+)/', $pro->ort, $m) ? (int) $m[1] : 0;
    pruefe($neu > 0, 'Speichern leitet auf die Akte des neuen Kunden');
    $pro->holen('/app/kunde.php?id=' . $neu);
    pruefe($pro->vollstaendig(), 'Akte des neuen Kunden wird bis zum Ende ausgeliefert');
    pruefe(substr_count($pro->inhalt, 'data-reiter-feld=') === 8, 'alle acht Reiter sind da');
    pruefe(str_contains($pro->inhalt, 'max.musti@example.org') && str_contains($pro->inhalt, 'Golfclub Ottobeuren'),
        'Stammdaten stehen in der Akte');
    pruefe(preg_match('/avatar--riesig" style="background:#[0-9a-f]{6}">\s*MM\s*</', $pro->inhalt) === 1,
        'Avatar mit Initialen statt Abbruch');

    $pro->holen('/app/trainingsplan.php?id=neu&ki=1&kunde=' . $neu);
    pruefe($pro->vollstaendig() && str_contains($pro->inhalt, 'HCP 49,2'),
        'Trainingsplan mit KI: Vorgabe aus dem Kunden vorbefüllt');
    $pro->holen('/app/trainingsplan.php?id=neu&ki=1');
    pruefe($pro->vollstaendig(), 'Trainingsplan mit KI ohne Kunden');
    $pro->holen('/app/trainingsplan.php?id=neu&kunde=' . $neu);
    pruefe($pro->vollstaendig(), 'Trainingsplan ohne KI für den Kunden');

    abschnitt('Alle Kundenakten der Demo');
    $kaputt = [];
    foreach ($kunden as $id) {
        if (!$pro->holen('/app/kunde.php?id=' . $id)->vollstaendig()) {
            $kaputt[] = $id;
        }
    }
    pruefe($kunden !== [] && $kaputt === [], count($kunden) . ' Akten vollständig'
        . ($kaputt === [] ? '' : ' – abgebrochen: ' . implode(', ', $kaputt)));

    abschnitt('Alle Seiten der Anwendung');
    foreach (glob(GP_ROOT . '/app/*.php') as $datei) {
        $seite = basename($datei);
        $pro->holen('/app/' . $seite);
        if ($pro->status >= 500) {
            pruefe(false, $seite . ': ' . $pro->status);
        } elseif ($pro->status === 200 && str_starts_with($pro->art, 'text/html')) {
            pruefe($pro->vollstaendig(), $seite);
        }
    }
    pruefe($pro->holen('/app/kunden.php')->vollstaendig(), 'danach noch angemeldet');

    abschnitt('Serverlog');
    $log = is_file($ordner . '/fehler.log') ? trim((string) file_get_contents($ordner . '/fehler.log')) : '';
    pruefe($log === '', 'keine PHP-Fehler oder Warnungen' . ($log === '' ? '' : ":\n" . substr($log, 0, 1500)));
} finally {
    proc_terminate($server);
    proc_close($server);
    if (is_dir($ordner)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ordner, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($ordner);
    }
}

fwrite(STDOUT, PHP_EOL . $ergebnisse['ok'] . ' bestanden, ' . $ergebnisse['fehl'] . ' fehlgeschlagen.' . PHP_EOL);
exit($ergebnisse['fehl'] === 0 ? 0 : 1);
