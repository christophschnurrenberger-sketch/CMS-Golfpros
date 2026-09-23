<?php
/**
 * Systempruefung – was die Seite „System" der Betreiberzentrale anzeigt.
 *
 * Jede Zeile ist eine Prüfung, die in diesem Moment wirklich läuft: eine
 * Abfrage, ein Dateizugriff, ein Vergleich. Was sich von hier aus nicht
 * prüfen lässt – ob eine Mail wirklich ankommt, ob Stripe antwortet –,
 * steht als „nicht geprüft" da und nicht als grüner Haken. Ein
 * Gesamturteil wie „alles gesund" gibt es bewusst nicht.
 *
 * Zustand: true = in Ordnung, false = Problem, null = nur Auskunft.
 */
final class Systempruefung
{
    /** @return array<string,array<int,array{name:string,zustand:?bool,wert:string,rat:string}>> */
    public static function alle(): array
    {
        return [
            'Laufzeit'  => self::laufzeit(),
            'Datenbank' => self::datenbank(),
            'Dateien'   => self::dateien(),
            'Betrieb'   => self::betrieb(),
        ];
    }

    private static function zeile(string $name, ?bool $zustand, string $wert, string $rat = ''): array
    {
        return ['name' => $name, 'zustand' => $zustand, 'wert' => $wert, 'rat' => $rat];
    }

    private static function laufzeit(): array
    {
        $treiber = DB::istSqlite() ? 'pdo_sqlite' : 'pdo_mysql';
        return [
            self::zeile('PHP-Version', PHP_VERSION_ID >= 80100, PHP_VERSION, 'Mindestens PHP 8.1.'),
            self::zeile($treiber, extension_loaded($treiber), extension_loaded($treiber) ? 'geladen' : 'fehlt'),
            self::zeile('mbstring', extension_loaded('mbstring'), extension_loaded('mbstring') ? 'geladen' : 'fehlt'),
            self::zeile('Bildbearbeitung', extension_loaded('gd') || extension_loaded('imagick') ? true : false,
                extension_loaded('gd') ? 'GD' : (extension_loaded('imagick') ? 'Imagick' : 'fehlt'), 'Für Vorschaubilder.'),
            self::zeile('Fehleranzeige', !Config::get('debug', false), Config::get('debug', false) ? 'an' : 'aus',
                'Auf einem Live-System gehört debug in der config.php auf false.'),
            self::zeile('Speichergrenze', null, (string) ini_get('memory_limit')),
        ];
    }

    private static function datenbank(): array
    {
        $zeilen = [];
        $start = microtime(true);
        try {
            DB::value('SELECT 1');
            $ms = (microtime(true) - $start) * 1000;
            $zeilen[] = self::zeile('Verbindung', $ms < 500, Util::zahl($ms, 1) . ' ms für SELECT 1',
                'Über 500 ms deutet auf einen überlasteten Datenbankserver.');
        } catch (Throwable $e) {
            return [self::zeile('Verbindung', false, 'keine Verbindung', 'Zugangsdaten in der config.php prüfen.')];
        }
        $gespeichert = (int) DB::value("SELECT wert FROM settings WHERE workspace_id = 0 AND schluessel = 'schema_version'", [], 0);
        $zeilen[] = self::zeile('Schema-Stand', $gespeichert === Schema::VERSION,
            'Datenbank ' . $gespeichert . ' · Code ' . Schema::VERSION, 'Weicht beides ab, läuft die Migration beim nächsten Aufruf.');

        if (DB::istSqlite()) {
            try {
                $ergebnis = (string) DB::value('PRAGMA quick_check', [], '');
                $zeilen[] = self::zeile('Integrität (quick_check)', $ergebnis === 'ok', $ergebnis === '' ? 'keine Antwort' : Util::kuerzen($ergebnis, 80));
            } catch (Throwable $e) {
                $zeilen[] = self::zeile('Integrität (quick_check)', false, 'Prüfung fehlgeschlagen');
            }
            $datei = (string) Config::get('db.path', GP_ROOT . '/data/golfpro.sqlite');
            $zeilen[] = self::zeile('Datenbankdatei', is_file($datei) ? is_writable($datei) : false,
                is_file($datei) ? Util::bytes((int) filesize($datei)) . (is_writable($datei) ? ', beschreibbar' : ', NICHT beschreibbar') : 'nicht gefunden');
            $ausloeser = DB::int("SELECT COUNT(*) FROM sqlite_master WHERE type = 'trigger' AND name LIKE 'betreiber_log_kein_%'");
            $zeilen[] = self::zeile('Schreibschutz Audit-Log', $ausloeser === 2, $ausloeser === 2 ? 'Auslöser aktiv' : $ausloeser . ' von 2 Auslösern',
                'Ohne Auslöser verhindert nur die Anwendung selbst das Ändern von Einträgen.');
        } else {
            $ausloeser = DB::int("SELECT COUNT(*) FROM information_schema.TRIGGERS
                                  WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME LIKE 'betreiber_log_kein_%'");
            $zeilen[] = self::zeile('Schreibschutz Audit-Log', $ausloeser === 2 ? true : null,
                $ausloeser === 2 ? 'Auslöser aktiv' : 'keine Auslöser (Hoster erlaubt sie vermutlich nicht)',
                'Dann verhindert nur die Anwendung selbst das Ändern von Einträgen.');
        }
        $zeilen[] = self::zeile('Instanzen', null, (string) DB::int('SELECT COUNT(*) FROM workspaces'));
        return $zeilen;
    }

    private static function dateien(): array
    {
        $zeilen = [];
        foreach (['data', 'uploads'] as $ordner) {
            $pfad = GP_ROOT . '/' . $ordner;
            $zeilen[] = self::zeile($ordner . '/ beschreibbar', is_dir($pfad) && is_writable($pfad), is_dir($pfad) ? (is_writable($pfad) ? 'ja' : 'nein') : 'fehlt');
        }
        $frei = @disk_free_space(GP_ROOT);
        $zeilen[] = self::zeile('Freier Speicher', $frei === false ? null : $frei > 1024 ** 3,
            $frei === false ? 'vom Hoster nicht mitgeteilt' : Util::bytes((int) $frei), 'Unter 1 GB wird es für Uploads und Sicherungen eng.');
        $zeilen[] = self::zeile('install.php entfernt', !is_file(GP_ROOT . '/install.php'),
            is_file(GP_ROOT . '/install.php') ? 'liegt noch auf dem Server' : 'ja', 'Nach der Einrichtung löschen.');
        $demo = Config::get('demo_zugang', false);
        $zeilen[] = self::zeile('Demo-Zugang', $demo === true ? false : true,
            $demo === true ? 'offen für jeden' : ($demo ? 'mit Schlüssel' : 'aus'), 'Offen heißt: jeder kommt ohne Passwort in die Demo-Instanz.');
        $zeilen[] = self::zeile('Einrichtungsschlüssel', (string) Config::get('betreiber_einrichtung', '') === '' ? true : null,
            (string) Config::get('betreiber_einrichtung', '') === '' ? 'nicht gesetzt' : 'noch in der config.php',
            'Nach dem Anlegen des ersten Betreibers aus der config.php entfernen.');
        return $zeilen;
    }

    private static function betrieb(): array
    {
        $zeilen = [];
        $zeilen[] = self::zeile('Basisadresse', (string) Config::get('base_url', '') !== '',
            (string) Config::get('base_url', '') ?: 'nicht gesetzt', 'Ohne base_url entstehen Links in E-Mails aus der Anfrage.');
        $zeilen[] = self::zeile('Sitzungsgeheimnis', strlen((string) Config::get('secret', '')) >= 32,
            strlen((string) Config::get('secret', '')) >= 32 ? 'gesetzt' : 'zu kurz oder fehlt');
        $mail = function_exists('mail') && (string) Config::get('mail.transport', 'mail') === 'mail';
        $zeilen[] = self::zeile('Mailversand', $mail ? null : false, $mail ? 'mail() verfügbar – Zustellung nicht geprüft' : 'kein Versandweg',
            'Ob Mails ankommen, lässt sich von hier nicht prüfen. Eine Einladung an die eigene Adresse zeigt es.');
        $letzte = 0;
        foreach (DB::all("SELECT wert FROM settings WHERE schluessel = 'wartung_letzter_lauf' AND workspace_id > 0") as $z) {
            $letzte = max($letzte, (int) $z['wert']);
        }
        $zeilen[] = self::zeile('Wartung zuletzt', $letzte === 0 ? null : time() - $letzte < 86400,
            $letzte === 0 ? 'noch nie gelaufen' : Util::relativ(date('Y-m-d H:i:s', $letzte)),
            'Erinnerungen und Fälligkeiten brauchen die Wartung. Ein Cronjob auf cron.php macht sie pünktlich.');
        $fehler = DB::int("SELECT COUNT(*) FROM audit_log WHERE aktion = 'wartung_fehler' AND erstellt > :s",
            ['s' => date('Y-m-d H:i:s', time() - 86400)]);
        $zeilen[] = self::zeile('Wartungsfehler, 24 Stunden', $fehler === 0, (string) $fehler);
        $abgewiesen = DB::int("SELECT COUNT(*) FROM betreiber_log WHERE aktion IN ('ACCESS_DENIED', 'MASTER_LOGIN_FAILED') AND zeit > :s",
            ['s' => date('Y-m-d H:i:s', time() - 86400)]);
        $zeilen[] = self::zeile('Abgewiesene Zugriffe auf die Zentrale, 24 Stunden', $abgewiesen < 10 ? true : false, (string) $abgewiesen,
            'Viele davon: Das Audit-Log zeigt, von wem und woher.');
        $zeilen[] = self::zeile('Stripe', null, Stripe::eingerichtet() ? 'eingerichtet – Verbindung nicht geprüft' : 'nicht eingerichtet');
        return $zeilen;
    }
}
