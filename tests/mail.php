<?php
/**
 * Prüfungen des Mailversands über SMTP – gegen einen nachgebauten Server.
 *
 *   php tests/mail.php
 *
 * Der Server hier ist kein echter Postausgang, sondern ein Gegenüber, das
 * mitschreibt: was angekündigt, wie angemeldet, welcher Absender, welche
 * Nachricht. Er spricht unverschlüsselt, mit STARTTLS und von Anfang an
 * verschlüsselt; das Zertifikat dafür entsteht beim Start (openssl muss
 * dafür auf der Kommandozeile liegen, sonst entfallen die TLS-Prüfungen).
 *
 * Gesendet wird jeweils in einem eigenen PHP-Prozess: Nur so lässt sich
 * dem Absender das Testzertifikat als vertrauenswürdig unterschieben
 * (openssl.cafile) – und für die letzte Prüfung gerade nicht.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
define('GP_ROOT', dirname(__DIR__));

/* ------------------------------------------------- Teil 1: der Server --- */

if (($argv[1] ?? '') === '--server') {
    /*
     * php tests/mail.php --server <port> <protokolldatei> <art> <auth> [cert]
     *   art:  klar | starttls | ssl
     *   auth: plain | login | keine
     */
    [, , $port, $protokoll, $art, $auth] = $argv;
    $zert = $argv[6] ?? '';
    $kontext = stream_context_create(['ssl' => [
        'local_cert' => $zert, 'verify_peer' => false, 'allow_self_signed' => true,
    ]]);
    $server = stream_socket_server(($art === 'ssl' ? 'ssl' : 'tcp') . '://127.0.0.1:' . $port, $nr, $fehler,
        STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $kontext);
    if ($server === false) {
        fwrite(STDERR, "Server: $fehler\n");
        exit(1);
    }
    fwrite(STDOUT, "bereit\n");
    while ($c = @stream_socket_accept($server, 30)) {
        $mitschrift = ['befehle' => [], 'daten' => '', 'tls' => $art === 'ssl'];
        $senden = static function (string $t) use ($c): void { fwrite($c, $t . "\r\n"); };
        $senden('220 test.example ESMTP bereit');
        $angemeldet = $auth === 'keine';
        while (($zeile = fgets($c)) !== false) {
            $zeile = rtrim($zeile, "\r\n");
            $mitschrift['befehle'][] = $zeile;
            $befehl = strtoupper(strtok($zeile, ' ') ?: '');
            if ($befehl === 'EHLO') {
                $angebot = ['250-test.example'];
                if ($art === 'starttls' && !$mitschrift['tls']) {
                    $angebot[] = '250-STARTTLS';
                }
                if ($auth !== 'keine') {
                    $angebot[] = '250-AUTH ' . ($auth === 'plain' ? 'LOGIN PLAIN' : 'LOGIN');
                }
                $angebot[] = '250 SIZE 10240000';
                fwrite($c, implode("\r\n", $angebot) . "\r\n");
            } elseif ($befehl === 'STARTTLS') {
                $senden('220 los');
                stream_context_set_option($c, 'ssl', 'local_cert', $zert);
                $mitschrift['tls'] = @stream_socket_enable_crypto($c, true, STREAM_CRYPTO_METHOD_TLS_SERVER);
                if (!$mitschrift['tls']) {
                    break;
                }
            } elseif ($befehl === 'AUTH') {
                $teile = explode(' ', $zeile);
                if (strtoupper($teile[1] ?? '') === 'PLAIN') {
                    $roh = base64_decode($teile[2] ?? '');
                    $mitschrift['anmeldung'] = ['PLAIN', $roh];
                } else {
                    $senden('334 VXNlcm5hbWU6');
                    $u = base64_decode(rtrim((string) fgets($c)));
                    $senden('334 UGFzc3dvcmQ6');
                    $p = base64_decode(rtrim((string) fgets($c)));
                    $roh = "\0" . $u . "\0" . $p;
                    $mitschrift['anmeldung'] = ['LOGIN', $roh];
                }
                if ($roh === "\0konto@betrieb.example\0geheim-Passwort!") {
                    $angemeldet = true;
                    $senden('235 angemeldet');
                } else {
                    $senden('535 5.7.8 Zugang abgelehnt');
                }
            } elseif ($befehl === 'MAIL') {
                $senden($angemeldet ? '250 ok' : '530 erst anmelden');
            } elseif ($befehl === 'RCPT') {
                $senden(str_contains($zeile, 'unbekannt@') ? '550 5.1.1 Postfach unbekannt' : '250 ok');
            } elseif ($befehl === 'DATA') {
                $senden('354 her damit');
                /* Wie ein echter Server: die Verdopplung wieder entfernen,
                   aber mitzählen, dass sie da war. */
                while (($d = fgets($c)) !== false && $d !== ".\r\n") {
                    if (str_starts_with($d, '..')) {
                        $mitschrift['verdoppelt'] = ($mitschrift['verdoppelt'] ?? 0) + 1;
                        $d = substr($d, 1);
                    }
                    $mitschrift['daten'] .= $d;
                }
                $senden('250 angenommen');
            } elseif ($befehl === 'QUIT') {
                $senden('221 tschüss');
                break;
            } else {
                $senden('502 unbekannt');
            }
        }
        fclose($c);
        file_put_contents($protokoll, json_encode($mitschrift) . "\n", FILE_APPEND);
    }
    exit(0);
}

/* ---------------------------------------------- Teil 2: der Absender --- */

if (($argv[1] ?? '') === '--senden') {
    /* php tests/mail.php --senden <empfänger>  (Konfiguration über GP_CONFIG) */
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    require GP_ROOT . '/lib/bootstrap.php';
    $ws = (int) DB::value("SELECT id FROM workspaces WHERE slug = 'mailtest'");
    Tenant::setzen($ws);
    $ok = Mail::senden($argv[2], 'Grüße aus dem Test – Ä', "Erste Zeile\n.Zeile mit Punkt am Anfang\n\nEnde.",
        ['protokoll' => false]);
    fwrite(STDOUT, json_encode(['ok' => $ok, 'fehler' => Mail::$letzterFehler]));
    exit(0);
}

/* ----------------------------------------------- Teil 3: die Prüfung --- */

$ordner = sys_get_temp_dir() . '/teepilot-mail-' . bin2hex(random_bytes(4));
mkdir($ordner, 0700, true);

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

$config = static function (string $datei, array $mail) use ($ordner): string {
    file_put_contents($datei, '<?php return ' . var_export([
        'db' => ['driver' => 'sqlite', 'path' => $ordner . '/test.sqlite'],
        'secret' => 'test-' . str_repeat('x', 40),
        'base_url' => 'https://app.betrieb.example',
        'mail' => $mail + ['from_name' => 'TeePilot'],
        'debug' => false,
    ], true) . ';');
    return $datei;
};

/* Datenbank mit einem Betrieb, der eine eigene Absenderadresse hat. */
$grund = $config($ordner . '/grund.php', ['transport' => 'keiner']);
putenv('GP_CONFIG=' . $grund);
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require GP_ROOT . '/lib/bootstrap.php';
$ws = DB::insert('workspaces', ['slug' => 'mailtest', 'name' => 'Golfschule Test', 'aktiv' => 1, 'erstellt' => date('Y-m-d H:i:s')]);
Tenant::setzen($ws);
Tenant::einstellungSetzen('mail_absender', 'pro@golfschule.example');
Tenant::einstellungSetzen('mail_absender_name', 'Golfschule Test');
Tenant::setzen(0);

/* Zertifikat für localhost – wenn openssl da ist. */
$zert = $ordner . '/zert.pem';
exec('openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj /CN=localhost -addext subjectAltName=DNS:localhost'
    . ' -keyout ' . escapeshellarg($ordner . '/schluessel.pem') . ' -out ' . escapeshellarg($ordner . '/nur-zert.pem')
    . ' 2>/dev/null', $aus, $rc);
$mitTls = $rc === 0;
if ($mitTls) {
    file_put_contents($zert, file_get_contents($ordner . '/nur-zert.pem') . file_get_contents($ordner . '/schluessel.pem'));
}

$server = null;
$serverStarten = static function (string $art, string $auth) use ($ordner, $zert, &$server): array {
    if ($server !== null) {
        proc_terminate($server);
        proc_close($server);
    }
    $port = 25000 + random_int(0, 4000);
    $protokoll = $ordner . '/protokoll-' . $port . '.jsonl';
    $server = proc_open([PHP_BINARY, __FILE__, '--server', (string) $port, $protokoll, $art, $auth, $zert],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $ordner . '/server.log', 'a']], $rohre);
    fgets($rohre[1]);   // „bereit"
    return [$port, $protokoll];
};

$senden = static function (array $mail, string $an = 'kundin@example.org', bool $vertrauen = true) use ($config, $ordner, $zert): array {
    $datei = $config($ordner . '/c-' . bin2hex(random_bytes(3)) . '.php', $mail);
    $befehl = [PHP_BINARY];
    if ($vertrauen) {
        array_push($befehl, '-d', 'openssl.cafile=' . $zert);
    }
    array_push($befehl, __FILE__, '--senden', $an);
    $p = proc_open($befehl, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rohre, GP_ROOT, ['GP_CONFIG' => $datei, 'PATH' => getenv('PATH')]);
    $aus = stream_get_contents($rohre[1]);
    proc_close($p);
    return (array) json_decode((string) $aus, true);
};

$mitschrift = static function (string $protokoll): array {
    $zeilen = is_file($protokoll) ? file($protokoll, FILE_IGNORE_NEW_LINES) : [];
    return (array) json_decode((string) end($zeilen), true);
};

$smtp = static fn (int $port, string $secure, string $user = '', string $pass = '', array $mehr = []): array => $mehr + [
    'transport' => 'smtp', 'from_email' => 'konto@betrieb.example',
    'smtp' => ['host' => $secure === '' ? '127.0.0.1' : 'localhost', 'port' => $port, 'user' => $user, 'pass' => $pass, 'secure' => $secure],
];

try {
    abschnitt('Unverschlüsselt zu einem Postausgang auf demselben Rechner');
    [$port, $prot] = $serverStarten('klar', 'keine');
    $e = $senden($smtp($port, ''));
    $m = $mitschrift($prot);
    pruefe(($e['ok'] ?? false) === true, 'angenommen');
    pruefe(in_array('MAIL FROM:<konto@betrieb.example>', $m['befehle'] ?? [], true),
        'Absender im Umschlag ist das Konto, nicht die Adresse des Betriebs');
    $daten = (string) ($m['daten'] ?? '');
    pruefe(str_contains($daten, "From: Golfschule Test <konto@betrieb.example>\r\n")
        && str_contains($daten, "Reply-To: pro@golfschule.example\r\n"), 'From trägt den Namen des Betriebs, Reply-To seine Adresse');
    pruefe(preg_match('/^Date: /m', $daten) === 1 && str_contains($daten, "To: kundin@example.org\r\n")
        && str_contains($daten, 'Subject: =?UTF-8?B?'), 'Date, To und kodierter Betreff stehen im Kopf');
    pruefe(preg_match('/^Message-ID: <[a-z0-9]+@betrieb\.example>\r$/m', $daten) === 1, 'Message-ID mit der Domain des Absenders');
    pruefe(($m['verdoppelt'] ?? 0) >= 1 && str_contains($daten, "\r\n.Zeile mit Punkt am Anfang"),
        'eine Zeile mit Punkt am Anfang wird verdoppelt und kommt einfach an');
    pruefe(max(array_map('strlen', explode("\r\n", $daten))) <= 998, 'keine Zeile länger als 998 Zeichen, auch nicht das HTML');
    pruefe(!preg_match("/(?<!\r)\n/", $daten), 'nur CRLF als Zeilenende');
    pruefe(str_contains(quoted_printable_decode($daten), "Erste Zeile\r\n.Zeile mit Punkt"), 'Text kommt nach dem Dekodieren unverändert an');

    abschnitt('Schutz');
    $e = $senden($smtp(1, ''), "kundin@example.org\r\nBcc: fremd@example.org");
    pruefe(($e['ok'] ?? true) === false, 'Empfänger mit eingeschleuster Kopfzeile: nicht verschickt');
    $e = $senden($smtp(1, '', '', '', ['smtp' => ['host' => 'smtp.example.org', 'port' => 25, 'secure' => '']]));
    pruefe(($e['ok'] ?? true) === false && str_contains((string) $e['fehler'], 'Unverschlüsselt nur'),
        'unverschlüsselt zu einem fremden Server: verweigert, bevor verbunden wird');
    [$port, $prot] = $serverStarten('klar', 'keine');
    $e = $senden($smtp($port, ''), 'unbekannt@example.org');
    pruefe(($e['ok'] ?? true) === false && str_contains((string) $e['fehler'], 'RCPT: 550'), 'abgelehnter Empfänger: Fehler mit Antwort des Servers');

    if (!$mitTls) {
        abschnitt('TLS – übersprungen, openssl fehlt auf der Kommandozeile');
    } else {
        abschnitt('STARTTLS');
        [$port, $prot] = $serverStarten('starttls', 'plain');
        $e = $senden($smtp($port, 'tls', 'konto@betrieb.example', 'geheim-Passwort!'));
        $m = $mitschrift($prot);
        pruefe(($e['ok'] ?? false) === true && ($m['tls'] ?? false) === true, 'verschlüsselt und angenommen');
        pruefe(($m['anmeldung'][0] ?? '') === 'PLAIN', 'Anmeldung mit PLAIN, wenn angeboten');
        $vorTls = array_slice($m['befehle'] ?? [], 0, (int) array_search('STARTTLS', $m['befehle'] ?? [], true));
        pruefe(!preg_grep('/^AUTH/', $vorTls), 'keine Anmeldung vor der Verschlüsselung');

        [$port, $prot] = $serverStarten('starttls', 'plain');
        $e = $senden($smtp($port, 'tls', 'konto@betrieb.example', 'falsch-Passwort!'));
        pruefe(($e['ok'] ?? true) === false && str_contains((string) $e['fehler'], 'Anmeldung: 535')
            && !str_contains((string) $e['fehler'], 'falsch-Passwort') && !str_contains((string) $e['fehler'], base64_encode("\0konto@betrieb.example\0falsch-Passwort!")),
            'falsches Passwort: Fehler nennt die Antwort, nicht die Zugangsdaten');

        [$port, $prot] = $serverStarten('starttls', 'login');
        $e = $senden($smtp($port, 'tls', 'konto@betrieb.example', 'geheim-Passwort!'));
        pruefe(($e['ok'] ?? false) === true && ($mitschrift($prot)['anmeldung'][0] ?? '') === 'LOGIN', 'Anmeldung mit LOGIN, wenn PLAIN fehlt');

        [$port, $prot] = $serverStarten('starttls', 'plain');
        $e = $senden($smtp($port, 'tls', 'konto@betrieb.example', 'geheim-Passwort!'), 'kundin@example.org', false);
        $m = $mitschrift($prot);
        pruefe(($e['ok'] ?? true) === false && !isset($m['anmeldung']), 'Zertifikat nicht vertrauenswürdig: abgebrochen, Passwort nie gesendet');

        abschnitt('Von Anfang an verschlüsselt (Port 465)');
        [$port, $prot] = $serverStarten('ssl', 'plain');
        $e = $senden($smtp($port, 'ssl', 'konto@betrieb.example', 'geheim-Passwort!'));
        pruefe(($e['ok'] ?? false) === true && ($mitschrift($prot)['tls'] ?? false) === true, 'angenommen');
    }
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
