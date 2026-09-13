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
