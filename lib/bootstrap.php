<?php
/**
 * bootstrap – der gemeinsame Einstieg jeder Seite.
 *
 * Lädt Klassen, öffnet die Datenbank und bestimmt den Mandanten. Ohne
 * Composer: ein Autoloader über lib/ reicht bei einer Klasse je Datei völlig
 * und erspart eine Abhängigkeit, die auf günstigem Hosting gern Ärger macht.
 */

declare(strict_types=1);

if (!defined('GP_ROOT')) {
    define('GP_ROOT', dirname(__DIR__));
}
define('GP_VERSION', '1.0.0');

mb_internal_encoding('UTF-8');

spl_autoload_register(static function (string $klasse): void {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $klasse)) {
        return;
    }
    $datei = GP_ROOT . '/lib/' . $klasse . '.php';
    if (is_file($datei)) {
        require_once $datei;
    }
});

Config::load();

date_default_timezone_set('Europe/Berlin');

/*
 * Sicherheitskopfzeilen – hier und nicht nur in der .htaccess.
 *
 * Dort stehen sie in einem <IfModule mod_headers.c>. Fehlt das Modul,
 * ignoriert der Hoster die .htaccess oder läuft nginx, verschwinden sie
 * ohne jede Fehlermeldung, und niemand merkt es. Aus PHP heraus gesetzt
 * sind sie da, solange PHP läuft. Doppelt gesetzte Kopfzeilen sind kein
 * Problem: `header()` ersetzt, es hängt nicht an.
 *
 * Die CSP läuft vorerst nur im Beobachtungsmodus. Die Oberfläche benutzt
 * an einigen Stellen onclick-Attribute und eingebettete Stile; die
 * scharfe Fassung würde sie heute zerlegen. Report-Only blockiert nichts
 * und zeigt dem Browser-Werkzeugkasten trotzdem jeden Verstoß.
 */
if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-Frame-Options: SAMEORIGIN');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header('Content-Security-Policy-Report-Only: '
         . "default-src 'self'; "
         . "img-src 'self' data:; "
         . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
         . "font-src 'self' https://fonts.gstatic.com; "
         . "script-src 'self' 'unsafe-inline'; "
         . "frame-ancestors 'self'; "
         . "base-uri 'self'; "
         . "form-action 'self'");

    /* HSTS nur, wenn die Anfrage wirklich über HTTPS kam – sonst sperrt
       man eine Anlage aus, die noch kein Zertifikat hat, für ein Jahr aus. */
    if ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') {
        header('Strict-Transport-Security: max-age=31536000');
    }
}

if (Config::get('debug', false)) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
    ini_set('display_errors', '0');
}

/*
 * Ohne config.php ist nur der Installer erlaubt. Jede andere Seite schickt
 * dorthin – das erspart eine Fehlerseite, die niemandem weiterhilft.
 */
if (!Config::installed()) {
    $skript = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if (!in_array($skript, ['install.php', 'systemcheck.php'], true)) {
        $tiefe = substr_count(trim(str_replace(
            str_replace('\\', '/', GP_ROOT),
            '',
            str_replace('\\', '/', (string) ($_SERVER['SCRIPT_FILENAME'] ?? ''))
        ), '/'), '/');
        header('Location: ' . str_repeat('../', max(0, $tiefe)) . 'install.php');
        exit;
    }
    return;
}

DB::init();

/*
 * Das Schema zieht sich selbst nach, aber nicht bei jedem Aufruf: Die
 * Versionsnummer steht in den Einstellungen des Systems (workspace_id 0).
 */
try {
    $gespeichert = (int) DB::value(
        'SELECT wert FROM settings WHERE workspace_id = 0 AND schluessel = :s',
        ['s' => 'schema_version'],
        0
    );
} catch (Throwable $e) {
    $gespeichert = 0;
}
if ($gespeichert !== Schema::VERSION) {
    Schema::migrate();
    $da = DB::int('SELECT COUNT(*) FROM settings WHERE workspace_id = 0 AND schluessel = :s', ['s' => 'schema_version']);
    if ($da > 0) {
        DB::update('settings', ['wert' => (string) Schema::VERSION], 'workspace_id = 0 AND schluessel = :s', ['s' => 'schema_version']);
    } else {
        DB::insert('settings', ['workspace_id' => 0, 'schluessel' => 'schema_version', 'wert' => (string) Schema::VERSION]);
    }
}

Auth::start();
