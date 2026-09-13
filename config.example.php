<?php
/**
 * Vorlage für die Konfiguration. install.php erzeugt daraus die config.php.
 *
 * Wer von Hand einrichtet: Datei nach config.php kopieren und ausfüllen.
 * Die config.php gehört nicht ins Versionsverwaltungssystem – sie enthält
 * Zugangsdaten.
 */
return [
    // 'sqlite' braucht nichts weiter als einen beschreibbaren data-Ordner.
    'db' => [
        'driver' => 'sqlite',
        'path'   => __DIR__ . '/data/golfpro.sqlite',
        // Für MySQL/MariaDB stattdessen:
        // 'driver' => 'mysql',
        // 'host' => 'localhost', 'port' => 3306,
        // 'name' => 'golfpro', 'user' => '', 'pass' => '',
    ],

    // Zufällig erzeugt beim Installieren. Signiert Sitzungen und Tokens.
    'secret' => '',

    // Basisadresse der Installation, ohne Schrägstrich am Ende.
    'base_url' => '',

    // Absender für Systemmails.
    'mail' => [
        'from_name'  => 'GolfPro CMS',
        'from_email' => '',
        // 'transport' => 'mail' | 'smtp'
        'transport'  => 'mail',
        'smtp'       => ['host' => '', 'port' => 587, 'user' => '', 'pass' => '', 'secure' => 'tls'],
    ],

    // Zahlungsanbieter. Ohne Schlüssel läuft alles im Testmodus weiter.
    'stripe' => [
        'public_key'     => '',
        'secret_key'     => '',
        'webhook_secret' => '',
    ],

    // KI-Funktionen. Ohne Schlüssel arbeitet die Plattform mit dem
    // eingebauten, regelbasierten Generator weiter – nichts bricht ab.
    'ai' => [
        'provider' => 'anthropic',
        'api_key'  => '',
        'model'    => 'claude-sonnet-5',
    ],

    // true blendet Fehlermeldungen im Klartext ein. Auf Live-Systemen: false.
    'debug' => false,
];
