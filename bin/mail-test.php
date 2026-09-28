<?php
/**
 * Eine Probemail verschicken – um den Postausgang zu prüfen.
 *
 *   php bin/mail-test.php empfaenger@example.org
 *
 * Nimmt dieselben Einstellungen wie die Anwendung (mail.transport,
 * mail.smtp in der config.php) und sagt bei einem Fehler, an welchem
 * Schritt es hing: Verbindung, Verschlüsselung, Anmeldung, Absender,
 * Empfänger. Die Zugangsdaten erscheinen nie in der Ausgabe.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
define('GP_ROOT', dirname(__DIR__));
require GP_ROOT . '/lib/bootstrap.php';

$an = trim((string) ($argv[1] ?? ''));
if (!filter_var($an, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Aufruf: php bin/mail-test.php empfaenger@example.org\n");
    exit(1);
}

$transport = (string) Config::get('mail.transport', 'mail');
fwrite(STDOUT, 'Versand über ' . ($transport === 'smtp'
    ? 'SMTP ' . Config::get('mail.smtp.host', '?') . ':' . Config::get('mail.smtp.port', '?')
      . ' (' . (Config::get('mail.smtp.secure', 'tls') ?: 'unverschlüsselt') . ')'
    : 'PHP mail()') . " an $an …\n");

$ok = Mail::senden($an, 'TeePilot: Probemail', "Diese Nachricht kommt von bin/mail-test.php.\n\n"
    . 'Wenn sie angekommen ist, funktioniert der Postausgang. Gesendet am ' . date('d.m.Y \u\m H:i') . ' Uhr.',
    ['protokoll' => false]);

if ($ok) {
    fwrite(STDOUT, "Angenommen. Jetzt im Postfach nachsehen – auch im Spam-Ordner.\n");
    exit(0);
}
fwrite(STDERR, 'Nicht verschickt: ' . (Mail::$letzterFehler !== '' ? Mail::$letzterFehler
    : 'mail() hat abgelehnt – auf einem eigenen Server gibt es meist keinen Postausgang; dann SMTP eintragen.') . "\n");
exit(1);
