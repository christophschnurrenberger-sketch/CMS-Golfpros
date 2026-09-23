<?php
/**
 * Betreiber verwalten – von der Kommandozeile.
 *
 *   php bin/betreiber.php anlegen <email> "<Name>"
 *   php bin/betreiber.php liste
 *   php bin/betreiber.php link <email>        neuer Link zum Passwortsetzen
 *   php bin/betreiber.php sperren <email>
 *   php bin/betreiber.php entsperren <email>
 *
 * So entsteht der erste Master Admin: ohne Seite im Netz, die jemand
 * finden könnte, und ohne Passwort, das durch ein Terminal oder eine
 * Shell-Historie wandert. Ausgegeben wird ein Link, der sieben Tage gilt;
 * das Passwort vergibt die Person selbst auf passwort.php.
 *
 * Wer keinen Zugang zur Kommandozeile hat (reines FTP-Hosting), nimmt
 * den Weg über master/einrichten.php – siehe docs/BETREIBER.md.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
define('GP_ROOT', dirname(__DIR__));
require GP_ROOT . '/lib/bootstrap.php';

$befehl = $argv[1] ?? '';
$email  = strtolower(trim((string) ($argv[2] ?? '')));

function ausgeben(string $text): void
{
    fwrite(STDOUT, $text . PHP_EOL);
}

function abbrechen(string $text): never
{
    fwrite(STDERR, 'Fehler: ' . $text . PHP_EOL);
    exit(1);
}

function linkZeigen(string $token): void
{
    $basis = rtrim((string) Config::get('base_url', ''), '/');
    if ($basis === '') {
        ausgeben('Hinweis: base_url ist in der config.php nicht gesetzt. Setze den Link aus deiner Adresse zusammen:');
        $basis = 'https://DEINE-ADRESSE';
    }
    ausgeben('');
    ausgeben('Link zum Passwortsetzen (gilt 7 Tage, nur einmal verwendbar):');
    ausgeben($basis . '/passwort.php?token=' . $token);
    ausgeben('');
}

switch ($befehl) {
    case 'anlegen':
        $name = trim((string) ($argv[3] ?? ''));
        [$id, $token, $fehler] = Betreiber::anlegen($email, $name, 'master_admin', 'Kommandozeile');
        if ($id === 0) {
            abbrechen($fehler);
        }
        ausgeben('Betreiber #' . $id . ' angelegt: ' . $name . ' <' . $email . '>, Rolle Master Admin.');
        linkZeigen($token);
        break;

    case 'liste':
        foreach (DB::all('SELECT id, email, name, rolle, aktiv, letzter_login FROM betreiber ORDER BY id') as $b) {
            ausgeben(sprintf('#%d  %-32s %-24s %-14s %s  zuletzt: %s', $b['id'], $b['email'], $b['name'],
                Betreiber::ROLLEN[$b['rolle']] ?? $b['rolle'], (int) $b['aktiv'] === 1 ? 'aktiv   ' : 'gesperrt',
                $b['letzter_login'] ?: 'nie'));
        }
        break;

    case 'link':
        $b = DB::one('SELECT id FROM betreiber WHERE email = :e AND aktiv = 1', ['e' => $email]);
        if ($b === null) {
            abbrechen('Kein aktiver Betreiber mit dieser Adresse.');
        }
        $token = Util::token(24);
        DB::update('betreiber', ['reset_token' => hash('sha256', $token),
            'reset_bis' => date('Y-m-d H:i:s', time() + 7 * 86400)], 'id = :id', ['id' => (int) $b['id']]);
        Betreiberlog::schreiben('OPERATOR_UPDATED', ['objekt' => 'betreiber', 'objekt_id' => (int) $b['id'],
            'akteur' => 'Kommandozeile', 'beschreibung' => 'Neuer Link zum Passwortsetzen']);
        linkZeigen($token);
        break;

    case 'sperren':
    case 'entsperren':
        $b = DB::one('SELECT id, aktiv FROM betreiber WHERE email = :e', ['e' => $email]);
        if ($b === null) {
            abbrechen('Kein Betreiber mit dieser Adresse.');
        }
        $aktiv = $befehl === 'entsperren' ? 1 : 0;
        if ($aktiv === 0 && DB::int('SELECT COUNT(*) FROM betreiber WHERE aktiv = 1 AND id != :id', ['id' => (int) $b['id']]) === 0) {
            abbrechen('Das ist der letzte aktive Betreiber.');
        }
        DB::update('betreiber', ['aktiv' => $aktiv], 'id = :id', ['id' => (int) $b['id']]);
        Betreiberlog::schreiben('OPERATOR_UPDATED', ['objekt' => 'betreiber', 'objekt_id' => (int) $b['id'],
            'akteur' => 'Kommandozeile', 'vorher' => ['aktiv' => (int) $b['aktiv']], 'nachher' => ['aktiv' => $aktiv]]);
        ausgeben($aktiv === 1 ? 'Entsperrt.' : 'Gesperrt.');
        break;

    default:
        ausgeben('Aufruf: php bin/betreiber.php anlegen <email> "<Name>" | liste | link <email> | sperren <email> | entsperren <email>');
        exit($befehl === '' ? 0 : 1);
}
