<?php
/**
 * Prüfungen des Baukastens – gegen eine Wegwerf-Datenbank.
 *
 *   php tests/baukasten.php
 *
 * Wie tests/betreiber.php: eigene config.php und SQLite-Datei in einem
 * temporären Ordner, ein eigener PHP-Entwicklungsserver, geprüft wird über
 * HTTP. Die echte Datenbank wird nicht angefasst.
 *
 * Geprüft wird vor allem, was man in der Oberfläche nicht sieht: dass
 * app/bauen.php nur die eigene Seite anfasst, nur Bausteine annimmt, die
 * es gibt, nur Felder speichert, die der Baustein hat, und eine
 * Reihenfolge ablehnt, die nicht mehr zur Seite passt.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
define('GP_ROOT', dirname(__DIR__));

$ordner = sys_get_temp_dir() . '/teepilot-bau-' . bin2hex(random_bytes(4));
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

/* ------------------------------------------------------------ Bestand --- */

/* Hohe IDs, damit Aufräumarbeiten nie einen echten Ordner treffen. */
$start = 910000 + random_int(0, 80000);
DB::insert('workspaces', ['id' => $start, 'slug' => 'platzhalter', 'name' => 'x', 'erstellt' => date('Y-m-d H:i:s')]);
DB::delete('workspaces', 'id = :i', ['i' => $start]);

$a = Instanzen::anlegen(['name' => 'Golfschule Alpha', 'inhaber' => 'Anna Alpha', 'email' => 'anna@alpha.example',
    'paket' => 'starter', 'laufzeit' => 'monat', 'slug' => 'bau-alpha']);
$b = Instanzen::anlegen(['name' => 'Golfschule Beta', 'inhaber' => 'Bert Beta', 'email' => 'bert@beta.example',
    'paket' => 'starter', 'laufzeit' => 'monat', 'slug' => 'bau-beta']);
$A = (int) $a['id'];
$B = (int) $b['id'];
$pw = 'Test!2026sicher';
DB::update('users', ['passwort' => password_hash($pw, PASSWORD_DEFAULT)], "email IN ('anna@alpha.example', 'bert@beta.example')");
DB::insert('users', ['workspace_id' => $A, 'email' => 'hanna@alpha.example', 'name' => 'Hanna Headpro', 'rolle' => 'head_pro',
    'passwort' => password_hash($pw, PASSWORD_DEFAULT), 'aktiv' => 1, 'erstellt' => date('Y-m-d H:i:s')]);

$seiteMit = static function (int $ws, array $typen): array {
    Tenant::setzen($ws);
    $bloecke = array_map(static fn ($t) => Bloecke::neu($t), $typen);
    $id = Pages::speichern(['titel' => 'Testseite', 'bloecke' => Util::json($bloecke), 'status' => 'veroeffentlicht', 'art' => 'seite']);
    Tenant::setzen(0);
    return [$id, array_column($bloecke, 'id')];
};
[$seiteA, $kA] = $seiteMit($A, ['hero', 'text', 'cta']);
[$seiteB, $kB] = $seiteMit($B, ['hero', 'faq']);
$folge = static fn (int $seite): array => array_column(Util::ausJson((string) DB::value('SELECT bloecke FROM pages WHERE id = :i', ['i' => $seite]), []), 'id');
$daten = static function (int $seite, string $block): array {
    foreach (Util::ausJson((string) DB::value('SELECT bloecke FROM pages WHERE id = :i', ['i' => $seite]), []) as $bl) {
        if ($bl['id'] === $block) {
            return (array) $bl['daten'];
        }
    }
    return [];
};

/* ------------------------------------------------------------- Server --- */

$server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', GP_ROOT],
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
    public array $json = [];
    public string $csrf = '';

    public function __construct(private string $basis, string $ordner)
    {
        $this->kekse = $ordner . '/kekse-' . bin2hex(random_bytes(4));
    }

    public function holen(string $pfad, ?array $post = null): self
    {
        $c = curl_init($this->basis . $pfad);
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEJAR => $this->kekse, CURLOPT_COOKIEFILE => $this->kekse, CURLOPT_TIMEOUT => 20]);
        if ($post !== null) {
            curl_setopt($c, CURLOPT_POST, true);
            curl_setopt($c, CURLOPT_POSTFIELDS, http_build_query($post));
        }
        $this->inhalt = (string) curl_exec($c);
        $this->status = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
        curl_close($c);
        $this->json = (array) (json_decode($this->inhalt, true) ?? []);
        if (preg_match('/window\.gpCsrf\s*=\s*"([^"]+)"/', $this->inhalt, $m)) {
            $this->csrf = $m[1];
        }
        return $this;
    }

    public function anmelden(string $email, string $pw): self
    {
        $this->holen('/login.php');
        preg_match('/name="_csrf" value="([^"]+)"/', $this->inhalt, $m);
        $this->holen('/login.php', ['_csrf' => $m[1] ?? '', 'email' => $email, 'passwort' => $pw]);
        return $this->holen('/app/kunden.php');
    }

    /** Eine Handlung des Baukastens. */
    public function bauen(int $seite, string $aktion, array $daten = []): self
    {
        return $this->holen('/app/bauen.php', ['_csrf' => $this->csrf, 'seite' => $seite, 'aktion' => $aktion] + $daten);
    }
}

$basis = 'http://127.0.0.1:' . $port;
try {
    $anna = (new Besucher($basis, $ordner))->anmelden('anna@alpha.example', $pw);
    $bert = (new Besucher($basis, $ordner))->anmelden('bert@beta.example', $pw);
    $hanna = (new Besucher($basis, $ordner))->anmelden('hanna@alpha.example', $pw);
    $gast = new Besucher($basis, $ordner);

    abschnitt('Zugang');
    $gast->holen('/app/bauen.php', ['seite' => $seiteA, 'aktion' => 'hinzu', 'typ' => 'text']);
    pruefe($gast->status === 401 && isset($gast->json['fehler']), 'ohne Anmeldung: 401 mit Meldung, keine Anmeldeseite');
    $anna->holen('/app/bauen.php');
    pruefe($anna->status === 405, 'GET: 405');
    $anna->holen('/app/bauen.php', ['seite' => $seiteA, 'aktion' => 'hinzu', 'typ' => 'text']);
    pruefe($anna->status === 400 && count($folge($seiteA)) === 3, 'ohne CSRF-Merkmal: abgewiesen, nichts geändert');
    $hanna->bauen($seiteA, 'hinzu', ['typ' => 'text']);
    pruefe($hanna->status === 403 && count($folge($seiteA)) === 3, 'Head Pro ohne Schreibrecht: 403, nichts geändert');
    $hanna->holen('/app/seite.php?id=' . $seiteA);
    pruefe($hanna->status === 200 && str_contains($hanna->inhalt, 'data-bau') && str_contains($hanna->inhalt, 'data-seite=""')
        && !str_contains($hanna->inhalt, 'data-feld='), 'Head Pro sieht den Baukasten nur lesend (keine beschreibbaren Felder)');

    abschnitt('Mandantengrenze');
    $bert->bauen($seiteA, 'hinzu', ['typ' => 'text']);
    pruefe($bert->status === 404 && count($folge($seiteA)) === 3, 'fremde Seite: 404, nichts geändert');
    $bert->bauen($seiteA, 'weg', ['block' => $kA[0]]);
    pruefe($bert->status === 404 && count($folge($seiteA)) === 3, 'fremden Baustein entfernen: 404');
    $bert->bauen($seiteB, 'weg', ['block' => $kA[0]]);
    pruefe($bert->status === 404 && count($folge($seiteA)) === 3, 'fremde Bausteinkennung auf eigener Seite: 404');
    $bert->bauen($seiteA, 'panel', ['block' => $kA[0]]);
    pruefe($bert->status === 404 && !str_contains($bert->inhalt, 'data-block-form'), 'fremde Felder lesen: 404');
    $bert->bauen($seiteB, 'reihenfolge', ['reihenfolge' => implode(',', array_merge($kB, [$kA[0]]))]);
    pruefe($bert->status === 409 && $folge($seiteB) === $kB, 'fremde Kennung in der Reihenfolge: 409');

    abschnitt('Hinzufügen und Kopieren');
    $anna->bauen($seiteA, 'hinzu', ['typ' => 'zitat', 'nach' => $kA[0]]);
    $neu = (string) ($anna->json['block']['id'] ?? '');
    $f = $folge($seiteA);
    pruefe($anna->status === 200 && $neu !== '' && $f[1] === $neu, 'Zitat hinter dem ersten Baustein eingesetzt');
    pruefe(str_contains((string) ($anna->json['block']['html'] ?? ''), 'data-block-id="' . $neu . '"')
        && str_contains((string) ($anna->json['block']['html'] ?? ''), 'data-feld=')
        && str_contains((string) ($anna->json['block']['teil'] ?? ''), 'data-teil="' . $neu . '"'),
        'Antwort: Baustein für die Leinwand (mit beschreibbaren Feldern) und Zeile für den Aufbau');
    $anna->bauen($seiteA, 'hinzu', ['typ' => 'anfang', 'nach' => 'anfang']);
    $anna->bauen($seiteA, 'hinzu', ['typ' => 'boese', 'nach' => '']);
    pruefe($anna->status === 400 && count($folge($seiteA)) === 4, 'unbekannter Bausteintyp: 400, nichts eingesetzt');
    $anna->bauen($seiteA, 'hinzu', ['typ' => 'trenner', 'nach' => 'anfang']);
    $trenner = (string) ($anna->json['block']['id'] ?? '');
    pruefe($folge($seiteA)[0] === $trenner, '„anfang": ganz nach vorn');
    $anna->bauen($seiteA, 'hinzu', ['typ' => 'bild', 'nach' => 'gibt-es-nicht']);
    $bildId = (string) ($anna->json['block']['id'] ?? '');
    $f = $folge($seiteA);
    pruefe(end($f) === $bildId, 'unbekannter Vorgänger: ans Ende statt verloren');
    $anna->bauen($seiteA, 'kopie', ['block' => $kA[1]]);
    $kopie = (string) ($anna->json['block']['id'] ?? '');
    $f = $folge($seiteA);
    pruefe($kopie !== $kA[1] && $f[array_search($kA[1], $f, true) + 1] === $kopie && $daten($seiteA, $kopie) === $daten($seiteA, $kA[1]),
        'Kopie mit eigener Kennung direkt hinter dem Original, gleiche Inhalte');

    abschnitt('Reihenfolge');
    $vorher = $folge($seiteA);
    $umgedreht = array_reverse($vorher);
    $anna->bauen($seiteA, 'reihenfolge', ['reihenfolge' => implode(',', $umgedreht)]);
    pruefe($anna->status === 200 && $folge($seiteA) === $umgedreht, 'Umstellung derselben Bausteine: angenommen');
    $anna->bauen($seiteA, 'reihenfolge', ['reihenfolge' => implode(',', array_slice($umgedreht, 1))]);
    pruefe($anna->status === 409 && $folge($seiteA) === $umgedreht, 'einer fehlt: 409, kein Baustein verschwindet');
    $doppelt = $umgedreht;
    $doppelt[1] = $doppelt[0];
    $anna->bauen($seiteA, 'reihenfolge', ['reihenfolge' => implode(',', $doppelt)]);
    pruefe($anna->status === 409 && $folge($seiteA) === $umgedreht, 'einer doppelt: 409');

    abschnitt('Entfernen und zurückholen');
    $vorher = $folge($seiteA);
    $ziel = $vorher[2];
    $anna->bauen($seiteA, 'weg', ['block' => $ziel]);
    pruefe($anna->status === 200 && !in_array($ziel, $folge($seiteA), true) && ($anna->json['nach'] ?? '') === $vorher[1],
        'entfernt; Antwort nennt den Vorgänger');
    $anna->bauen($seiteA, 'einsetzen', ['block' => $ziel, 'nach' => $vorher[1]]);
    pruefe($anna->status === 200 && $folge($seiteA) === $vorher, 'aus der Ablage an derselben Stelle zurück');
    $anna->bauen($seiteA, 'einsetzen', ['block' => $ziel, 'nach' => $vorher[1]]);
    pruefe($anna->status === 410 && $folge($seiteA) === $vorher, 'ein zweites Mal: 410, kein Doppel');
    $bert->bauen($seiteB, 'einsetzen', ['block' => $ziel, 'nach' => '']);
    pruefe($bert->status === 410 && $folge($seiteB) === $kB, 'die Ablage gehört der Sitzung: fremder Zugriff 410');

    abschnitt('Felder speichern');
    $anna->bauen($seiteA, 'speichern', ['block' => $kA[0], 'f_titel' => 'Neu <script>alert(1)</script>',
        'f_ausrichtung' => 'mitte', 'f_boese' => 'nicht speichern']);
    $d = $daten($seiteA, $kA[0]);
    pruefe($anna->status === 200 && ($d['titel'] ?? '') === 'Neu <script>alert(1)</script>' && ($d['ausrichtung'] ?? '') === 'mitte',
        'Felder übernommen (roh gespeichert, wie beim Formular)');
    pruefe(!array_key_exists('boese', $d), 'Feld, das der Baustein nicht hat: nicht gespeichert');
    $html = (string) ($anna->json['block']['html'] ?? '');
    pruefe(!str_contains($html, '<script>alert(1)') && str_contains($html, '&lt;script&gt;'), 'Ausgabe maskiert – kein Skript in der Seite');
    [$faqSeite, $faqK] = $seiteMit($A, ['faq']);
    $anna->bauen($faqSeite, 'speichern', ['block' => $faqK[0], 'f_titel' => 'Fragen',
        'l_eintraege_frage' => ['Wie lange?', '', 'Was kostet es?'], 'l_eintraege_antwort' => ['Acht Wochen.', '', '349 €']]);
    $d = $daten($faqSeite, $faqK[0]);
    $fragen = (array) ($d['eintraege'] ?? []);
    pruefe($anna->status === 200 && count($fragen) === 2, 'Liste: leere Einträge fallen weg (' . count($fragen) . ' Einträge)');

    $anna->bauen($seiteA, 'panel', ['block' => $kA[0]]);
    pruefe($anna->status === 200 && str_contains((string) ($anna->json['html'] ?? ''), 'data-block-form')
        && str_contains((string) ($anna->json['html'] ?? ''), 'value="' . $kA[0] . '"'), 'rechte Spalte eines Bausteins');
    $anna->bauen($seiteA, 'panel', ['block' => '']);
    pruefe($anna->status === 200 && str_contains((string) ($anna->json['html'] ?? ''), 'seite_speichern'), 'ohne Baustein: die Angaben zur Seite');

    abschnitt('Formulare ohne JavaScript');
    $anna->holen('/app/seite.php?id=' . $seiteA);
    preg_match('/name="_csrf" value="([^"]+)"/', $anna->inhalt, $m);
    $vorher = $folge($seiteA);
    $anna->holen('/app/seite.php?id=' . $seiteA, ['_csrf' => $m[1] ?? '', 'aktion' => 'block_hinzu', 'typ' => 'boese', 'nach' => '']);
    pruefe($folge($seiteA) === $vorher, 'unbekannter Typ auch über das Formular: nichts eingesetzt');
    $anna->holen('/app/seite.php?id=' . $seiteA, ['_csrf' => $m[1] ?? '', 'aktion' => 'block_schieben', 'block_id' => $vorher[1], 'richtung' => 'hoch']);
    $f = $folge($seiteA);
    pruefe($f[0] === $vorher[1] && $f[1] === $vorher[0], 'nach oben schieben');

    abschnitt('Die Website bleibt die Website');
    $anna->holen('/site.php?w=bau-alpha&s=' . rawurlencode((string) DB::value('SELECT slug FROM pages WHERE id = :i', ['i' => $seiteA])));
    pruefe($anna->status === 200 && str_contains($anna->inhalt, '&lt;script&gt;alert(1)'), 'öffentliche Seite zeigt den gespeicherten Inhalt, maskiert');
    pruefe(!str_contains($anna->inhalt, 'data-feld=') && !str_contains($anna->inhalt, 'bau-block')
        && !str_contains($anna->inhalt, 'contenteditable'), 'ohne Spuren des Baukastens');
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
    foreach ([$A ?? 0, $B ?? 0] as $w) {
        foreach ([GP_ROOT . '/uploads/w' . $w, GP_ROOT . '/data/privat/w' . $w] as $rest) {
            if ($w > 900000 && is_dir($rest) && count(scandir($rest)) === 2) {
                @rmdir($rest);
            }
        }
    }
}

fwrite(STDOUT, PHP_EOL . $ergebnisse['ok'] . ' bestanden, ' . $ergebnisse['fehl'] . ' fehlgeschlagen.' . PHP_EOL);
exit($ergebnisse['fehl'] === 0 ? 0 : 1);
