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
    // Steht sie hier vollständig (mit https://), dann gilt sie auch als
    // der zulässige Hostname – Links in E-Mails entstehen daraus und nicht
    // aus der Host-Kopfzeile der Anfrage, die jeder fälschen kann.
    'base_url' => '',

    // Weitere Hostnamen, unter denen diese Anlage erreichbar sein soll.
    // Eigene Domains der Workspaces zählen automatisch mit.
    'erlaubte_hosts' => [],

    // Anmeldung ohne Passwort über /demo.php – für öffentliche
    // Vorführungen. Drei Abstufungen:
    //
    //   false              aus (Standard)
    //   'ein-schluessel'   nur /demo.php?k=ein-schluessel
    //   true               offen für jeden
    //
    // Der mittlere Weg ist der übliche: Der Schlüssel steht im Link auf
    // der eigenen Website, und wer nur die nackte Adresse probiert,
    // findet nichts. Auf einem Produktivserver ohne Vorführung gehört
    // demo.php gelöscht – der Schalter ist die zweite Sicherung.
    'demo_zugang' => false,

    // Nur zum Anlegen des ERSTEN Master Admins auf Hosting ohne
    // Kommandozeile: einen langen Zufallswert (mindestens 24 Zeichen)
    // eintragen, master/einrichten.php?k=<Wert> öffnen, danach den Eintrag
    // wieder löschen. Sobald ein Betreiber existiert, ist die Seite ohnehin
    // gesperrt. Mit SSH geht es ohne: php bin/betreiber.php anlegen …
    // Siehe docs/BETREIBER.md.
    // 'betreiber_einrichtung' => '',

    // Absender für Systemmails.
    'mail' => [
        'from_name'  => 'TeePilot',
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

    // SMS-Versand für Terminerinnerungen. Ohne Zugangsdaten geht keine SMS
    // raus – die E-Mail dazu schon, und in der Oberfläche steht, was fehlt.
    // Es wird nichts stillschweigend verschluckt und nichts vorgetäuscht.
    'sms' => [
        // 'seven' (seven.io, deutscher Anbieter) oder 'twilio'. Leer = aus.
        'anbieter' => '',
        'api_key'  => '',
        // Nur bei Twilio: die Account SID.
        'konto'    => '',
        // Was beim Kunden als Absender steht. Höchstens 11 Zeichen, sonst
        // kürzen die Netze. Leer = der Name des Workspace.
        'absender' => '',
    ],

    // WhatsApp Business Cloud API. Vorbereitet, aber erst aktiv, wenn alle
    // drei Werte stehen. Meta lässt außerhalb eines laufenden Gesprächs nur
    // geprüfte Vorlagen zu; 'vorlage' ist deren Name. Die Platzhalter der
    // Vorlage bekommen der Reihe nach: Vorname, Leistung, Datum, Uhrzeit,
    // Name der Schule.
    'whatsapp' => [
        'token'           => '',
        'phone_number_id' => '',
        'vorlage'         => '',
        'sprache'         => 'de',
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
