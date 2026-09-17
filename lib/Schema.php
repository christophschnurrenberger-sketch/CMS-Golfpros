<?php
/**
 * Schema – legt die Tabellen an und hält sie aktuell.
 *
 * Die DDL ist einmal generisch formuliert; Platzhalter werden je nach
 * Datenbank übersetzt:
 *   %PK%      Auto-Increment-Primärschlüssel
 *   %INT%     Ganzzahl
 *   %STR(n)%  kurzer Text, indizierbar (VARCHAR bei MySQL)
 *   %TEXT%    Langtext
 *   %DT%      Zeitstempel als Text 'Y-m-d H:i:s'
 *   %ENGINE%  Tabellen-Suffix (nur MySQL)
 *
 * migrate() ist idempotent. Nach einem Update genügt es, die Dateien zu
 * überschreiben – die Datenbank zieht beim nächsten Seitenaufruf nach.
 *
 * Grundsätze im Datenmodell
 * -------------------------
 * **Alles hängt am Workspace.** Jede fachliche Tabelle trägt `workspace_id`.
 * Es gibt im ganzen System keine Abfrage ohne diese Einschränkung – dafür
 * sorgt `Tenant::wo()`. Ein Mandant kann die Daten eines anderen nicht sehen,
 * auch nicht durch einen vergessenen Filter, weil der Filter nicht von Hand
 * geschrieben wird.
 *
 * **Geld ist eine Ganzzahl in Cent.** Immer, überall, ohne Ausnahme.
 *
 * **Belege sind Dokumente, keine Sicht auf Stammdaten.** Rechnungs- und
 * Bestellzeilen kopieren Titel, Preis und Steuersatz. Ändert der Pro später
 * seinen Stundensatz, bleibt die alte Rechnung, wie der Kunde sie bekam.
 *
 * **Gebuchte Termine kopieren den Preis.** Aus demselben Grund.
 */
final class Schema
{
    /*
     * Die Schemaversion.
     *
     * bootstrap.php vergleicht sie mit dem Wert in den Einstellungen und
     * laesst migrate() nur laufen, wenn sie abweichen. Wer eine Spalte
     * ergaenzt, MUSS diese Zahl erhoehen - sonst laeuft der Nachtrag auf
     * bestehenden Anlagen nie, und die erste Abfrage auf die neue Spalte
     * endet mit einem Serverfehler. Genau das ist bei der Terminabrechnung
     * passiert.
     *
     *   1  erste Fassung
     *   2  bookings.invoice_id - Termine auf Rechnungen
     *   3  customers.portal_token_bis und .abmelde_token - befristeter
     *      Zugangslink, dauerhafter Abmeldelink
     *   4  trips und trip_signups - das Reisemodul
     *   5  reminders und bookings.erinnerungen - Terminerinnerungen mit
     *      frei gewaehltem Vorlauf ueber E-Mail, SMS und WhatsApp
     *   6  customers.geaendert - Zeitpunkt der letzten Aenderung, damit
     *      die Schnittstelle nur Neues ausliefern kann
     */
    public const VERSION = 6;

    public static function migrate(): void
    {
        foreach (self::tabellen() as $sql) {
            DB::pdo()->exec(self::uebersetzen($sql));
        }
        self::nachtragen();
        foreach (self::indizes() as $sql) {
            if (!DB::istSqlite()) {
                $sql = str_replace('CREATE INDEX IF NOT EXISTS', 'CREATE INDEX', $sql);
            }
            try {
                DB::pdo()->exec($sql);
            } catch (Throwable $e) {
                $meldung = strtolower($e->getMessage());
                if (!str_contains($meldung, 'duplicate') && !str_contains($meldung, 'exist')) {
                    throw $e;
                }
            }
        }
    }

    /**
     * Spalten, die nach der ersten Fassung dazugekommen sind.
     *
     * CREATE TABLE IF NOT EXISTS lässt eine bestehende Tabelle in Ruhe –
     * auch dann, wenn die Definition oben inzwischen eine Spalte mehr
     * kennt. Wer aktualisiert, statt neu zu installieren, bekommt sie
     * deshalb hier nachgereicht. Die Liste wächst mit jeder Fassung und
     * darf nie schrumpfen: Sie ist die einzige Verbindung zwischen einer
     * alten Datenbank und dem heutigen Code.
     */
    private static function nachtragen(): void
    {
        /* Seit der Terminabrechnung: Auf welcher Rechnung steht dieser
           Termin? 0 heißt „noch auf keiner". */
        self::spalteSicherstellen('bookings', 'invoice_id', '%INT% NOT NULL DEFAULT 0');

        /*
         * Der Zugangslink bekommt ein Ablaufdatum, der Abmeldelink einen
         * eigenen, dauerhaften Schlüssel.
         *
         * Vorher war beides derselbe Wert, und der galt unbegrenzt: Eine
         * weitergeleitete Terminbestätigung von vor zwei Jahren meldete
         * heute noch an. Beides zusammenzulegen ging auch deshalb nicht,
         * weil der Abmeldelink eines Newsletters funktionieren muss –
         * dauerhaft, ohne Anmeldung, ohne Ablauf.
         */
        self::spalteSicherstellen('customers', 'portal_token_bis', '%DT%');
        self::spalteSicherstellen('customers', 'abmelde_token', '%STR(64)% NOT NULL DEFAULT ""');
        self::tokenNachruesten();
        self::videosWegraeumen();
        self::modulNachruesten('travel', 'events');

        /* Seit den Terminerinnerungen: Vorlauf und Kanäle je Termin. */
        self::spalteSicherstellen('bookings', 'erinnerungen', '%TEXT%');
        self::erinnerungenNachruesten();

        /*
         * Seit der Newsletter-Schnittstelle: Wann wurde dieser Kunde
         * zuletzt geändert?
         *
         * Ohne diesen Zeitpunkt kann ein angeschlossenes System nur immer
         * wieder alles holen. Für bestehende Datensätze gilt zunächst der
         * Zeitpunkt der Anlage – das ist die einzige belastbare Aussage,
         * die sich nachträglich treffen lässt, und sie sorgt dafür, dass
         * der erste Abgleich alle mitnimmt.
         */
        self::spalteSicherstellen('customers', 'geaendert', '%DT%');
        try {
            DB::pdo()->exec("UPDATE customers SET geaendert = erstellt"
                . " WHERE geaendert IS NULL OR geaendert = ''");
        } catch (Throwable $e) {
            Audit::schreiben('wartung_fehler', 'system', 0, 'geaendert nachtragen: ' . $e->getMessage());
        }
    }

    /**
     * Die alten Erinnerungsschalter ziehen um – und die schon gebuchten
     * Termine bekommen ihren Plan.
     *
     * Vorher gab es zwei Haken: „einen Tag vorher" und „eine Stunde vorher",
     * beide nur per E-Mail. Daraus wird die neue Vorlaufliste. Wer nichts
     * eingestellt hatte, bekommt die Vorgabe – einen Tag vorher.
     *
     * Der zweite Teil ist der wichtigere: Ohne ihn stünde die neue Funktion
     * da, ohne dass ein einziger der bereits gebuchten Termine davon
     * berührt wäre. Geplant wird deshalb einmalig für alles, was noch
     * bevorsteht. Termine, deren alte 24-Stunden-Erinnerung schon rausging,
     * bekommen den Vorlauf nicht noch einmal – sonst käme sie doppelt.
     */
    private static function erinnerungenNachruesten(): void
    {
        $vorher = Tenant::id();
        try {
            foreach (DB::all('SELECT id FROM workspaces WHERE aktiv = 1') as $zeile) {
                $workspaceId = (int) $zeile['id'];
                Tenant::setzen($workspaceId);

                if (Tenant::einstellung('erinnerung_vorlauf', null) === null) {
                    $vorlauf = [];
                    if ((bool) Tenant::einstellung('erinnerung_24', true)) {
                        $vorlauf[] = 1440;
                    }
                    if ((bool) Tenant::einstellung('erinnerung_1', false)) {
                        $vorlauf[] = 60;
                    }
                    Tenant::einstellungSetzen('erinnerung_vorlauf',
                        $vorlauf !== [] ? $vorlauf : Erinnerungen::VORGABE_VORLAUF);
                    Tenant::einstellungSetzen('erinnerung_kanaele', Erinnerungen::VORGABE_KANAELE);
                    Tenant::einstellungSetzen('erinnerungen_aktiv', $vorlauf !== []);
                }

                if (Tenant::count('reminders') > 0) {
                    continue;
                }
                foreach (Tenant::all('bookings',
                    "status = 'bestaetigt' AND start > :jetzt", ['jetzt' => Util::jetzt()], 'start', 500) as $termin) {
                    $regeln = Erinnerungen::regeln($termin);
                    if ((string) $termin['erinnerung_24'] !== '' && $termin['erinnerung_24'] !== null) {
                        $regeln = array_values(array_filter($regeln,
                            static fn (array $r): bool => $r['vorlauf'] !== 1440));
                    }
                    if ((string) $termin['erinnerung_1'] !== '' && $termin['erinnerung_1'] !== null) {
                        $regeln = array_values(array_filter($regeln,
                            static fn (array $r): bool => $r['vorlauf'] !== 60));
                    }
                    Erinnerungen::planen((int) $termin['id'], $regeln);
                }
            }
        } catch (Throwable $e) {
            Audit::schreiben('wartung_fehler', 'system', 0,
                'Erinnerungen nachrüsten: ' . $e->getMessage());
        } finally {
            Tenant::setzen($vorher);
        }
    }

    /**
     * Ein neues Modul bei bestehenden Anlagen dazuschalten.
     *
     * Welche Module an sind, steht je Workspace als Liste in den
     * Einstellungen. Ein neu ausgeliefertes Modul steht dort nicht – es
     * bliebe also unsichtbar, und der Pro müsste erst ahnen, dass es
     * etwas Neues gibt, um es im Tarifbildschirm zu suchen.
     *
     * Angeschaltet wird nur, wo das verwandte Modul schon an ist: Wer
     * keine Events führt, will nicht plötzlich Reisen im Menü haben.
     * Wer die Liste nie angefasst hat, bekommt ohnehin den Standard
     * seines Tarifs und wird hier nicht angerührt.
     */
    private static function modulNachruesten(string $neu, string $wenn): void
    {
        foreach (DB::all('SELECT id FROM settings WHERE schluessel = :s', ['s' => 'module']) as $zeile) {
            $satz = DB::one('SELECT * FROM settings WHERE id = :i', ['i' => (int) $zeile['id']]);
            if ($satz === null) {
                continue;
            }
            $liste = Util::ausJson((string) $satz['wert'], []);
            if (!is_array($liste) || $liste === []) {
                continue;
            }
            if (!in_array($wenn, $liste, true) || in_array($neu, $liste, true)) {
                continue;
            }
            $liste[] = $neu;
            DB::update('settings', ['wert' => Util::json(array_values($liste))],
                'id = :i', ['i' => (int) $satz['id']]);
        }
    }

    /**
     * Schwungvideos aus dem offenen uploads/ nach data/privat/ holen.
     *
     * Unter uploads/ war jedes Video für jeden abrufbar, der die Adresse
     * kannte – ohne Anmeldung, ohne Mandantenprüfung. Der Ordner data/
     * ist über die .htaccess dicht; herausgegeben wird nur noch über
     * datei.php, das vorher prüft, wer fragt.
     *
     * Lässt sich eine Datei nicht verschieben (Rechte, laufender Zugriff),
     * bleibt der alte Pfad in der Datenbank stehen: Ein Video, das noch
     * abspielt, ist besser als ein toter Verweis. datei.php liefert beide
     * Orte aus, prüft aber in jedem Fall die Berechtigung.
     */
    private static function videosWegraeumen(): void
    {
        if (!DB::tabelleExistiert('videos')) {
            return;
        }
        try {
            $zeilen = DB::all("SELECT id, workspace_id, datei FROM videos
                               WHERE datei LIKE 'uploads/%'");
        } catch (Throwable $e) {
            return;
        }
        foreach ($zeilen as $z) {
            $alt = GP_ROOT . '/' . (string) $z['datei'];
            if (!is_file($alt)) {
                continue;
            }
            $ordner = GP_ROOT . '/data/privat/w' . (int) $z['workspace_id'] . '/video';
            if (!is_dir($ordner) && !@mkdir($ordner, 0750, true) && !is_dir($ordner)) {
                continue;
            }
            $name = basename((string) $z['datei']);
            if (@rename($alt, $ordner . '/' . $name)) {
                DB::update('videos',
                    ['datei' => 'data/privat/w' . (int) $z['workspace_id'] . '/video/' . $name],
                    'id = :id', ['id' => (int) $z['id']]);
            }
        }
    }

    /**
     * Bestandskunden mit den beiden neuen Werten versorgen.
     *
     * Der Abmeldeschlüssel wird aus dem bisherigen Zugangsschlüssel
     * übernommen – die Links in bereits versendeten Newslettern tragen
     * genau diesen Wert und sollen weiter funktionieren.
     *
     * Der Zugangsschlüssel bekommt eine Frist von 14 Tagen ab jetzt.
     * Sofort ungültig zu setzen hieße, allen Kunden gleichzeitig den Weg
     * ins Portal abzuschneiden; in diesen zwei Wochen erneuert ihn jede
     * Terminbestätigung von selbst.
     */
    private static function tokenNachruesten(): void
    {
        if (!DB::tabelleExistiert('customers')) {
            return;
        }
        try {
            DB::pdo()->exec("UPDATE customers SET abmelde_token = portal_token
                             WHERE abmelde_token = '' AND portal_token != ''");
            DB::update('customers',
                ['portal_token_bis' => date('Y-m-d H:i:s', time() + 14 * 86400)],
                "portal_token_bis IS NULL AND portal_token != ''");
        } catch (Throwable $e) {
            // Beim allerersten Anlegen gibt es noch nichts nachzurüsten.
        }
    }

    /** Ergänzt eine Spalte, wenn sie fehlt. Für Updates bestehender Installationen. */
    public static function spalteSicherstellen(string $tabelle, string $spalte, string $definition): void
    {
        if (!DB::tabelleExistiert($tabelle)) {
            return;
        }
        try {
            DB::pdo()->query('SELECT ' . $spalte . ' FROM ' . $tabelle . ' LIMIT 1');
        } catch (Throwable $e) {
            DB::pdo()->exec('ALTER TABLE ' . $tabelle . ' ADD COLUMN ' . $spalte . ' ' . self::uebersetzen($definition));
        }
    }

    private static function uebersetzen(string $sql): string
    {
        if (DB::istSqlite()) {
            $sql = str_replace('%PK%', 'INTEGER PRIMARY KEY AUTOINCREMENT', $sql);
            $sql = str_replace('%INT%', 'INTEGER', $sql);
            $sql = preg_replace('/%STR\((\d+)\)%/', 'TEXT', $sql) ?? $sql;
            $sql = str_replace('%TEXT%', 'TEXT', $sql);
            $sql = str_replace('%DT%', 'TEXT', $sql);
            $sql = str_replace('%ENGINE%', '', $sql);
        } else {
            $sql = str_replace('%PK%', 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY', $sql);
            $sql = str_replace('%INT%', 'INT', $sql);
            $sql = preg_replace('/%STR\((\d+)\)%/', 'VARCHAR($1)', $sql) ?? $sql;
            $sql = str_replace('%TEXT%', 'MEDIUMTEXT', $sql);
            $sql = str_replace('%DT%', 'DATETIME', $sql);
            $sql = str_replace('%ENGINE%', ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci', $sql);
        }
        return $sql;
    }

    /** @return string[] */
    private static function tabellen(): array
    {
        return [

            /* ============================== Mandant, Zugang, Protokoll === */

            'CREATE TABLE IF NOT EXISTS workspaces (
                id %PK%,
                slug %STR(64)% NOT NULL,
                name %STR(160)% NOT NULL DEFAULT "",
                typ %STR(24)% NOT NULL DEFAULT "pro",          -- pro | academy
                plan %STR(24)% NOT NULL DEFAULT "starter",     -- starter|pro|business|academy
                domain %STR(160)% NOT NULL DEFAULT "",
                logo %STR(255)% NOT NULL DEFAULT "",
                branding %TEXT%,                               -- JSON: Farben, Schrift, Radius
                einstellungen %TEXT%,                          -- JSON: Module, Defaults
                waehrung %STR(8)% NOT NULL DEFAULT "EUR",
                sprache %STR(8)% NOT NULL DEFAULT "de",
                zeitzone %STR(48)% NOT NULL DEFAULT "Europe/Berlin",
                onboarding_schritt %INT% NOT NULL DEFAULT 0,
                aktiv %INT% NOT NULL DEFAULT 1,
                demo %INT% NOT NULL DEFAULT 0,
                erstellt %DT%
            )%ENGINE%',

            'CREATE TABLE IF NOT EXISTS users (
                id %PK%,
                workspace_id %INT% NOT NULL DEFAULT 0,
                email %STR(190)% NOT NULL,
                passwort %STR(255)% NOT NULL DEFAULT "",
                name %STR(160)% NOT NULL DEFAULT "",
                rolle %STR(24)% NOT NULL DEFAULT "trainer",
                titel %STR(120)% NOT NULL DEFAULT "",
                telefon %STR(64)% NOT NULL DEFAULT "",
                bild %STR(255)% NOT NULL DEFAULT "",
                farbe %STR(16)% NOT NULL DEFAULT "",
                bio %TEXT%,
                standorte %TEXT%,                              -- JSON: Liste von location_id
                rechte %TEXT%,                                 -- JSON: Zusatzrechte/Entzug
                zwei_faktor %STR(64)% NOT NULL DEFAULT "",
                aktiv %INT% NOT NULL DEFAULT 1,
                letzter_login %DT%,
                reset_token %STR(64)% NOT NULL DEFAULT "",
                reset_bis %DT%,
                erstellt %DT%
            )%ENGINE%',

            'CREATE TABLE IF NOT EXISTS audit_log (
                id %PK%,
                workspace_id %INT% NOT NULL DEFAULT 0,
                user_id %INT% NOT NULL DEFAULT 0,
                aktion %STR(64)% NOT NULL DEFAULT "",
                objekt %STR(64)% NOT NULL DEFAULT "",
                objekt_id %INT% NOT NULL DEFAULT 0,
                beschreibung %TEXT%,
                ip %STR(64)% NOT NULL DEFAULT "",
                erstellt %DT%
            )%ENGINE%',

            'CREATE TABLE IF NOT EXISTS locations (
                id %PK%,
                workspace_id %INT% NOT NULL,
                name %STR(160)% NOT NULL DEFAULT "",
                typ %STR(32)% NOT NULL DEFAULT "club",         -- club|range|indoor|platz
                strasse %STR(160)% NOT NULL DEFAULT "",
                plz %STR(16)% NOT NULL DEFAULT "",
                ort %STR(120)% NOT NULL DEFAULT "",
                land %STR(8)% NOT NULL DEFAULT "DE",
                lat %STR(32)% NOT NULL DEFAULT "",
                lng %STR(32)% NOT NULL DEFAULT "",
                notiz %TEXT%,
                farbe %STR(16)% NOT NULL DEFAULT "",
                aktiv %INT% NOT NULL DEFAULT 1,
                erstellt %DT%
            )%ENGINE%',

            'CREATE TABLE IF NOT EXISTS settings (
                id %PK%,
                workspace_id %INT% NOT NULL DEFAULT 0,
                schluessel %STR(120)% NOT NULL,
                wert %TEXT%
            )%ENGINE%',

            'CREATE TABLE IF NOT EXISTS notifications (
                id %PK%,
                workspace_id %INT% NOT NULL,
                user_id %INT% NOT NULL DEFAULT 0,
                kategorie %STR(32)% NOT NULL DEFAULT "system", -- booking|payment|customer|marketing|system|ai
                titel %STR(200)% NOT NULL DEFAULT "",
                text %TEXT%,
                link %STR(255)% NOT NULL DEFAULT "",
                gelesen %DT%,
                erstellt %DT%
            )%ENGINE%',

            'CREATE TABLE IF NOT EXISTS tasks (
                id %PK%,
                workspace_id %INT% NOT NULL,
                titel %STR(220)% NOT NULL DEFAULT "",
                notiz %TEXT%,
                faellig %DT%,
                user_id %INT% NOT NULL DEFAULT 0,
                customer_id %INT% NOT NULL DEFAULT 0,
                lead_id %INT% NOT NULL DEFAULT 0,
                prioritaet %STR(16)% NOT NULL DEFAULT "normal",
                erledigt %DT%,
                quelle %STR(32)% NOT NULL DEFAULT "manuell",   -- manuell|automation|ai
                erstellt %DT%
            )%ENGINE%',

            /* ================================================== CRM ===== */

            'CREATE TABLE IF NOT EXISTS customers (
                id %PK%,
                workspace_id %INT% NOT NULL,
                vorname %STR(80)% NOT NULL DEFAULT "",
                nachname %STR(80)% NOT NULL DEFAULT "",
                email %STR(190)% NOT NULL DEFAULT "",
                telefon %STR(64)% NOT NULL DEFAULT "",
                geburtstag %STR(16)% NOT NULL DEFAULT "",
                strasse %STR(160)% NOT NULL DEFAULT "",
                plz %STR(16)% NOT NULL DEFAULT "",
                ort %STR(120)% NOT NULL DEFAULT "",
                land %STR(8)% NOT NULL DEFAULT "DE",
                hcp %STR(16)% NOT NULL DEFAULT "",
                heimclub %STR(160)% NOT NULL DEFAULT "",
                ziele %TEXT%,
                dominante_hand %STR(16)% NOT NULL DEFAULT "rechts",
                status %STR(24)% NOT NULL DEFAULT "aktiv",     -- aktiv|inaktiv|archiviert
                quelle %STR(48)% NOT NULL DEFAULT "",
                trainer_id %INT% NOT NULL DEFAULT 0,
                location_id %INT% NOT NULL DEFAULT 0,
                tags %TEXT%,
                felder %TEXT%,                                 -- JSON: eigene Felder
                notiz %TEXT%,
                health_score %INT% NOT NULL DEFAULT 50,
                portal_token %STR(64)% NOT NULL DEFAULT "",
                portal_token_bis %DT%,
                abmelde_token %STR(64)% NOT NULL DEFAULT "",
                portal_passwort %STR(255)% NOT NULL DEFAULT "",
                newsletter %INT% NOT NULL DEFAULT 0,
                letzte_aktivitaet %DT%,
                geaendert %DT%,                                -- fuer den Abgleich mit dem Newslettersystem
                erstellt %DT%
            )%ENGINE%',

            'CREATE TABLE IF NOT EXISTS customer_notes (
                id %PK%,
                workspace_id %INT% NOT NULL,
                customer_id %INT% NOT NULL,
                user_id %INT% NOT NULL DEFAULT 0,
                text %TEXT%,
                angeheftet %INT% NOT NULL DEFAULT 0,
                erstellt %DT%
            )%ENGINE%',

            'CREATE TABLE IF NOT EXISTS communications (
                id %PK%,
                workspace_id %INT% NOT NULL,
                customer_id %INT% NOT NULL DEFAULT 0,
                lead_id %INT% NOT NULL DEFAULT 0,
                kanal %STR(24)% NOT NULL DEFAULT "email",      -- email|sms|whatsapp|telefon|notiz|portal
                richtung %STR(12)% NOT NULL DEFAULT "aus",     -- ein|aus
                betreff %STR(220)% NOT NULL DEFAULT "",
                text %TEXT%,
                user_id %INT% NOT NULL DEFAULT 0,
                status %STR(24)% NOT NULL DEFAULT "gesendet",
                gelesen %DT%,
                erstellt %DT%
            )%ENGINE%',

            'CREATE TABLE IF NOT EXISTS tags (
                id %PK%,
                workspace_id %INT% NOT NULL,
                name %STR(80)% NOT NULL,
                farbe %STR(16)% NOT NULL DEFAULT "",
                erstellt %DT%
            )%ENGINE%',

            'CREATE TABLE IF NOT EXISTS segments (
                id %PK%,
                workspace_id %INT% NOT NULL,
                name %STR(120)% NOT NULL,
                beschreibung %STR(255)% NOT NULL DEFAULT "",
                regeln %TEXT%,                                 -- JSON: Bedingungen
                farbe %STR(16)% NOT NULL DEFAULT "",
                system %INT% NOT NULL DEFAULT 0,
                erstellt %DT%
            )%ENGINE%',

            'CREATE TABLE IF NOT EXISTS custom_fields (
                id %PK%,
                workspace_id %INT% NOT NULL,
                objekt %STR(32)% NOT NULL DEFAULT "customer",
                schluessel %STR(64)% NOT NULL,
                label %STR(120)% NOT NULL DEFAULT "",
                typ %STR(24)% NOT NULL DEFAULT "text",         -- text|zahl|datum|auswahl|checkbox
                optionen %TEXT%,
                position %INT% NOT NULL DEFAULT 0,
                erstellt %DT%
            )%ENGINE%',

            'CREATE TABLE IF NOT EXISTS leads (
                id %PK%,
                workspace_id %INT% NOT NULL,
                name %STR(160)% NOT NULL DEFAULT "",
                email %STR(190)% NOT NULL DEFAULT "",
                telefon %STR(64)% NOT NULL DEFAULT "",
                quelle %STR(64)% NOT NULL DEFAULT "website",
                stufe %STR(24)% NOT NULL DEFAULT "neu",        -- neu|kontaktiert|qualifiziert|angebot|gebucht|kunde|verloren
                interesse %STR(160)% NOT NULL DEFAULT "",
                wert_cent %INT% NOT NULL DEFAULT 0,
                notiz %TEXT%,
                nachricht %TEXT%,
                user_id %INT% NOT NULL DEFAULT 0,
                customer_id %INT% NOT NULL DEFAULT 0,
                letzter_kontakt %DT%,
                geschlossen %DT%,
                erstellt %DT%
            )%ENGINE%',

            'CREATE TABLE IF NOT EXISTS consents (
                id %PK%,
                workspace_id %INT% NOT NULL,
                customer_id %INT% NOT NULL DEFAULT 0,
                lead_id %INT% NOT NULL DEFAULT 0,
                typ %STR(48)% NOT NULL DEFAULT "newsletter",
                erteilt %INT% NOT NULL DEFAULT 1,
                text %TEXT%,
                quelle %STR(64)% NOT NULL DEFAULT "",
                ip %STR(64)% NOT NULL DEFAULT "",
                erstellt %DT%
            )%ENGINE%',

            'CREATE TABLE IF NOT EXISTS data_requests (
                id %PK%,
                workspace_id %INT% NOT NULL,
                customer_id %INT% NOT NULL DEFAULT 0,
                typ %STR(24)% NOT NULL DEFAULT "export",       -- export|loeschung
                status %STR(24)% NOT NULL DEFAULT "offen",
                notiz %TEXT%,
                erledigt %DT%,
                erstellt %DT%
            )%ENGINE%',

            /* ============================================== Buchungen === */

            'CREATE TABLE IF NOT EXISTS services (
                id %PK%,
                workspace_id %INT% NOT NULL,
                name %STR(160)% NOT NULL DEFAULT "",
                slug %STR(160)% NOT NULL DEFAULT "",
                art %STR(32)% NOT NULL DEFAULT "einzel",       -- einzel|gruppe|kurs|workshop|event|camp|video|reise|online
                beschreibung %TEXT%,
                dauer_min %INT% NOT NULL DEFAULT 60,
                puffer_min %INT% NOT NULL DEFAULT 0,
                preis_cent %INT% NOT NULL DEFAULT 0,
                steuersatz %INT% NOT NULL DEFAULT 19,
                kapazitaet %INT% NOT NULL DEFAULT 1,
                location_id %INT% NOT NULL DEFAULT 0,
                trainer_ids %TEXT%,
                farbe %STR(16)% NOT NULL DEFAULT "",
                bild %STR(255)% NOT NULL DEFAULT "",
                online_buchbar %INT% NOT NULL DEFAULT 1,
                vorlauf_stunden %INT% NOT NULL DEFAULT 12,
                stornofrist_stunden %INT% NOT NULL DEFAULT 24,
                position %INT% NOT NULL DEFAULT 0,
                aktiv %INT% NOT NULL DEFAULT 1,
                erstellt %DT%
            )%ENGINE%',

            'CREATE TABLE IF NOT EXISTS availability (
                id %PK%,
                workspace_id %INT% NOT NULL,
                user_id %INT% NOT NULL DEFAULT 0,
                location_id %INT% NOT NULL DEFAULT 0,
                wochentag %INT% NOT NULL DEFAULT 1,            -- 0 = Sonntag
                von_min %INT% NOT NULL DEFAULT 540,
                bis_min %INT% NOT NULL DEFAULT 1080,
                aktiv %INT% NOT NULL DEFAULT 1
            )%ENGINE%',

            'CREATE TABLE IF NOT EXISTS time_off (
                id %PK%,
                workspace_id %INT% NOT NULL,
                user_id %INT% NOT NULL DEFAULT 0,
                titel %STR(160)% NOT NULL DEFAULT "",
                art %STR(24)% NOT NULL DEFAULT "urlaub",       -- urlaub|sperrzeit|feiertag
                von %DT%,
                bis %DT%,
                ganztags %INT% NOT NULL DEFAULT 1,
                erstellt %DT%
            )%ENGINE%',

            'CREATE TABLE IF NOT EXISTS bookings (
                id %PK%,
                workspace_id %INT% NOT NULL,
                service_id %INT% NOT NULL DEFAULT 0,
                customer_id %INT% NOT NULL DEFAULT 0,
                trainer_id %INT% NOT NULL DEFAULT 0,
                location_id %INT% NOT NULL DEFAULT 0,
                event_id %INT% NOT NULL DEFAULT 0,
                titel %STR(200)% NOT NULL DEFAULT "",
                start %DT%,
                ende %DT%,
                status %STR(24)% NOT NULL DEFAULT "bestaetigt",-- angefragt|bestaetigt|erschienen|abgesagt|nicht_erschienen
                teilnehmer %INT% NOT NULL DEFAULT 1,
                preis_cent %INT% NOT NULL DEFAULT 0,
                bezahlt %INT% NOT NULL DEFAULT 0,
                customer_package_id %INT% NOT NULL DEFAULT 0,
                order_id %INT% NOT NULL DEFAULT 0,
                invoice_id %INT% NOT NULL DEFAULT 0,           -- abgerechnet auf dieser Rechnung
                notiz %TEXT%,
                interne_notiz %TEXT%,
                quelle %STR(32)% NOT NULL DEFAULT "backend",   -- backend|website|portal|automation
                erinnerung_24 %DT%,                            -- nur noch Altbestand, siehe reminders
                erinnerung_1 %DT%,                             -- dito
                erinnerungen %TEXT%,                           -- JSON: Vorlauf und Kanäle dieses Termins
                abgesagt_am %DT%,
                abgesagt_grund %STR(255)% NOT NULL DEFAULT "",
                erstellt %DT%
            )%ENGINE%',

            'CREATE TABLE IF NOT EXISTS booking_participants (
                id %PK%,
                workspace_id %INT% NOT NULL,
                booking_id %INT% NOT NULL,
                customer_id %INT% NOT NULL,
                status %STR(24)% NOT NULL DEFAULT "gebucht",
                bezahlt %INT% NOT NULL DEFAULT 0,
                erstellt %DT%
            )%ENGINE%',

            /*
             * Geplante und versendete Terminerinnerungen.
             *
             * Eine Zeile je Kunde, Vorlauf und Kanal. Getrennt statt einer
             * Zeile mit Kanalliste, weil jeder Kanal für sich gelingen oder
             * scheitern kann: Die E-Mail kam an, die SMS nicht – das muss
             * ablesbar bleiben, und wiederholt wird nur der gescheiterte Teil.
             */
            'CREATE TABLE IF NOT EXISTS reminders (
                id %PK%,
                workspace_id %INT% NOT NULL,
                booking_id %INT% NOT NULL,
                customer_id %INT% NOT NULL DEFAULT 0,
                vorlauf_min %INT% NOT NULL DEFAULT 1440,
                kanal %STR(24)% NOT NULL DEFAULT "email",      -- email|sms|whatsapp
                faellig %DT%,
                status %STR(24)% NOT NULL DEFAULT "geplant",   -- geplant|gesendet|fehlgeschlagen|uebersprungen|abgesagt
                gesendet %DT%,
                versuche %INT% NOT NULL DEFAULT 0,
                grund %STR(255)% NOT NULL DEFAULT "",
                erstellt %DT%
            )%ENGINE%',

            'CREATE TABLE IF NOT EXISTS waitlist (
                id %PK%,
                workspace_id %INT% NOT NULL,
                booking_id %INT% NOT NULL DEFAULT 0,
                event_id %INT% NOT NULL DEFAULT 0,
                customer_id %INT% NOT NULL,
                position %INT% NOT NULL DEFAULT 0,
                benachrichtigt %DT%,
                erstellt %DT%
            )%ENGINE%',

            /* =============================================== Pakete ===== */

            'CREATE TABLE IF NOT EXISTS packages (
                id %PK%,
                workspace_id %INT% NOT NULL,
                name %STR(160)% NOT NULL DEFAULT "",
                service_id %INT% NOT NULL DEFAULT 0,
                einheiten %INT% NOT NULL DEFAULT 5,
                dauer_min %INT% NOT NULL DEFAULT 60,
                preis_cent %INT% NOT NULL DEFAULT 0,
                gueltig_tage %INT% NOT NULL DEFAULT 365,
                beschreibung %TEXT%,
                farbe %STR(16)% NOT NULL DEFAULT "",
                aktiv %INT% NOT NULL DEFAULT 1,
                position %INT% NOT NULL DEFAULT 0,
                erstellt %DT%
            )%ENGINE%',

            'CREATE TABLE IF NOT EXISTS customer_packages (
                id %PK%,
                workspace_id %INT% NOT NULL,
                customer_id %INT% NOT NULL,
                package_id %INT% NOT NULL DEFAULT 0,
                name %STR(160)% NOT NULL DEFAULT "",
                einheiten_gesamt %INT% NOT NULL DEFAULT 0,
                einheiten_genutzt %INT% NOT NULL DEFAULT 0,
                preis_cent %INT% NOT NULL DEFAULT 0,
                order_id %INT% NOT NULL DEFAULT 0,
                gekauft %DT%,
                laeuft_ab %DT%,
                status %STR(24)% NOT NULL DEFAULT "aktiv",     -- aktiv|aufgebraucht|abgelaufen|storniert
                erstellt %DT%
            )%ENGINE%',

            /* ============================================ Warenwelt ===== */

            'CREATE TABLE IF NOT EXISTS products (
                id %PK%,
                workspace_id %INT% NOT NULL,
                art %STR(24)% NOT NULL DEFAULT "paket",        -- paket|einzel|kurs|gutschein|workshop|event|merch
                name %STR(200)% NOT NULL DEFAULT "",
                slug %STR(200)% NOT NULL DEFAULT "",
                kurztext %STR(255)% NOT NULL DEFAULT "",
                beschreibung %TEXT%,
                preis_cent %INT% NOT NULL DEFAULT 0,
                vergleichspreis_cent %INT% NOT NULL DEFAULT 0,
                steuersatz %INT% NOT NULL DEFAULT 19,
                bild %STR(255)% NOT NULL DEFAULT "",
                bilder %TEXT%,
                varianten %TEXT%,                              -- JSON
                bestand %INT% NOT NULL DEFAULT -1,             -- -1 = unbegrenzt
                ref_id %INT% NOT NULL DEFAULT 0,               -- package_id | course_id | event_id
                aktiv %INT% NOT NULL DEFAULT 1,
                position %INT% NOT NULL DEFAULT 0,
                erstellt %DT%
            )%ENGINE%',

            'CREATE TABLE IF NOT EXISTS discounts (
                id %PK%,
                workspace_id %INT% NOT NULL,
                code %STR(64)% NOT NULL,
                art %STR(16)% NOT NULL DEFAULT "prozent",      -- prozent|betrag
                wert %INT% NOT NULL DEFAULT 0,
                mindestwert_cent %INT% NOT NULL DEFAULT 0,
                gueltig_von %DT%,
                gueltig_bis %DT%,
                limit_gesamt %INT% NOT NULL DEFAULT 0,
                genutzt %INT% NOT NULL DEFAULT 0,
                aktiv %INT% NOT NULL DEFAULT 1,
                erstellt %DT%
            )%ENGINE%',

            'CREATE TABLE IF NOT EXISTS orders (
                id %PK%,
                workspace_id %INT% NOT NULL,
                nummer %STR(32)% NOT NULL DEFAULT "",
                customer_id %INT% NOT NULL DEFAULT 0,
                email %STR(190)% NOT NULL DEFAULT "",
                name %STR(160)% NOT NULL DEFAULT "",
                netto_cent %INT% NOT NULL DEFAULT 0,
                steuer_cent %INT% NOT NULL DEFAULT 0,
                rabatt_cent %INT% NOT NULL DEFAULT 0,
                summe_cent %INT% NOT NULL DEFAULT 0,
                waehrung %STR(8)% NOT NULL DEFAULT "EUR",
                status %STR(24)% NOT NULL DEFAULT "offen",     -- offen|bezahlt|storniert|erstattet
                zahlungsart %STR(32)% NOT NULL DEFAULT "",
                rabattcode %STR(64)% NOT NULL DEFAULT "",
                notiz %TEXT%,
                quelle %STR(32)% NOT NULL DEFAULT "website",
                bezahlt_am %DT%,
                erstellt %DT%
            )%ENGINE%',

            'CREATE TABLE IF NOT EXISTS order_items (
                id %PK%,
                workspace_id %INT% NOT NULL,
                order_id %INT% NOT NULL,
                product_id %INT% NOT NULL DEFAULT 0,
                titel %STR(220)% NOT NULL DEFAULT "",
                variante %STR(120)% NOT NULL DEFAULT "",
                menge %INT% NOT NULL DEFAULT 1,
                einzelpreis_cent %INT% NOT NULL DEFAULT 0,
                steuersatz %INT% NOT NULL DEFAULT 19,
                summe_cent %INT% NOT NULL DEFAULT 0
            )%ENGINE%',

            'CREATE TABLE IF NOT EXISTS payments (
                id %PK%,
                workspace_id %INT% NOT NULL,
                order_id %INT% NOT NULL DEFAULT 0,
                customer_id %INT% NOT NULL DEFAULT 0,
                invoice_id %INT% NOT NULL DEFAULT 0,
                anbieter %STR(32)% NOT NULL DEFAULT "stripe",
                methode %STR(32)% NOT NULL DEFAULT "karte",    -- karte|sepa|applepay|googlepay|ueberweisung|bar
                betrag_cent %INT% NOT NULL DEFAULT 0,
                gebuehr_cent %INT% NOT NULL DEFAULT 0,
                status %STR(24)% NOT NULL DEFAULT "offen",     -- offen|bezahlt|fehlgeschlagen|erstattet
                extern_id %STR(120)% NOT NULL DEFAULT "",
                art %STR(24)% NOT NULL DEFAULT "einmalig",     -- einmalig|abo|anzahlung
                notiz %STR(255)% NOT NULL DEFAULT "",
                erstellt %DT%
            )%ENGINE%',

            'CREATE TABLE IF NOT EXISTS invoices (
                id %PK%,
                workspace_id %INT% NOT NULL,
                nummer %STR(32)% NOT NULL DEFAULT "",
                customer_id %INT% NOT NULL DEFAULT 0,
                order_id %INT% NOT NULL DEFAULT 0,
                art %STR(16)% NOT NULL DEFAULT "rechnung",     -- rechnung|gutschrift
                empfaenger %TEXT%,                             -- JSON: eingefrorene Anschrift
                datum %STR(16)% NOT NULL DEFAULT "",
                faellig %STR(16)% NOT NULL DEFAULT "",
                netto_cent %INT% NOT NULL DEFAULT 0,
                steuer_cent %INT% NOT NULL DEFAULT 0,
                summe_cent %INT% NOT NULL DEFAULT 0,
                bezahlt_cent %INT% NOT NULL DEFAULT 0,
                status %STR(24)% NOT NULL DEFAULT "entwurf",   -- entwurf|offen|bezahlt|ueberfaellig|storniert
                notiz %TEXT%,
                storno_von %INT% NOT NULL DEFAULT 0,
                bezahlt_am %DT%,
                versendet_am %DT%,
                erstellt %DT%
            )%ENGINE%',

            'CREATE TABLE IF NOT EXISTS invoice_items (
                id %PK%,
                workspace_id %INT% NOT NULL,
                invoice_id %INT% NOT NULL,
                titel %STR(220)% NOT NULL DEFAULT "",
                beschreibung %STR(255)% NOT NULL DEFAULT "",
                menge %INT% NOT NULL DEFAULT 1,
                einzelpreis_cent %INT% NOT NULL DEFAULT 0,
                steuersatz %INT% NOT NULL DEFAULT 19,
                summe_cent %INT% NOT NULL DEFAULT 0,
                position %INT% NOT NULL DEFAULT 0
            )%ENGINE%',

            'CREATE TABLE IF NOT EXISTS vouchers (
                id %PK%,
                workspace_id %INT% NOT NULL,
                code %STR(48)% NOT NULL,
                art %STR(16)% NOT NULL DEFAULT "wert",         -- wert|leistung
                wert_cent %INT% NOT NULL DEFAULT 0,
                rest_cent %INT% NOT NULL DEFAULT 0,
                service_id %INT% NOT NULL DEFAULT 0,
                leistung %STR(200)% NOT NULL DEFAULT "",
                kaeufer %STR(160)% NOT NULL DEFAULT "",
                empfaenger %STR(160)% NOT NULL DEFAULT "",
                nachricht %TEXT%,
                customer_id %INT% NOT NULL DEFAULT 0,
                order_id %INT% NOT NULL DEFAULT 0,
                gueltig_bis %STR(16)% NOT NULL DEFAULT "",
                eingeloest %DT%,
                status %STR(24)% NOT NULL DEFAULT "aktiv",     -- aktiv|eingeloest|abgelaufen|storniert
                erstellt %DT%
            )%ENGINE%',

            /* ============================================== Training ==== */

            'CREATE TABLE IF NOT EXISTS exercises (
                id %PK%,
                workspace_id %INT% NOT NULL,
                titel %STR(200)% NOT NULL DEFAULT "",
                beschreibung %TEXT%,
                kategorie %STR(48)% NOT NULL DEFAULT "allgemein", -- putting|chipping|pitching|bunker|eisen|driver|mental|fitness|regeln
                video_url %STR(255)% NOT NULL DEFAULT "",
                bild %STR(255)% NOT NULL DEFAULT "",
                dauer_min %INT% NOT NULL DEFAULT 15,
                wiederholungen %STR(48)% NOT NULL DEFAULT "",
                ziel %STR(255)% NOT NULL DEFAULT "",
                schwierigkeit %STR(16)% NOT NULL DEFAULT "mittel", -- leicht|mittel|schwer
                ausruestung %STR(200)% NOT NULL DEFAULT "",
                erstellt %DT%
            )%ENGINE%',

            'CREATE TABLE IF NOT EXISTS training_plans (
                id %PK%,
                workspace_id %INT% NOT NULL,
                name %STR(200)% NOT NULL DEFAULT "",
                beschreibung %TEXT%,
                customer_id %INT% NOT NULL DEFAULT 0,
                vorlage %INT% NOT NULL DEFAULT 0,
                wochen %INT% NOT NULL DEFAULT 4,
                einheiten_woche %INT% NOT NULL DEFAULT 2,
                ziel %STR(255)% NOT NULL DEFAULT "",
                niveau %STR(24)% NOT NULL DEFAULT "anfaenger",
                status %STR(24)% NOT NULL DEFAULT "entwurf",   -- entwurf|aktiv|abgeschlossen
                quelle %STR(24)% NOT NULL DEFAULT "manuell",   -- manuell|ai
                user_id %INT% NOT NULL DEFAULT 0,
                start %STR(16)% NOT NULL DEFAULT "",
                erstellt %DT%
            )%ENGINE%',

            'CREATE TABLE IF NOT EXISTS plan_items (
                id %PK%,
                workspace_id %INT% NOT NULL,
                plan_id %INT% NOT NULL,
                exercise_id %INT% NOT NULL DEFAULT 0,
                woche %INT% NOT NULL DEFAULT 1,
                einheit %INT% NOT NULL DEFAULT 1,
                titel %STR(200)% NOT NULL DEFAULT "",
                notiz %TEXT%,
                dauer_min %INT% NOT NULL DEFAULT 15,
                wiederholungen %STR(48)% NOT NULL DEFAULT "",
                position %INT% NOT NULL DEFAULT 0,
                erledigt %DT%
            )%ENGINE%',

            'CREATE TABLE IF NOT EXISTS plan_assignments (
                id %PK%,
                workspace_id %INT% NOT NULL,
                plan_id %INT% NOT NULL,
                customer_id %INT% NOT NULL,
                start %STR(16)% NOT NULL DEFAULT "",
                fortschritt %INT% NOT NULL DEFAULT 0,
                status %STR(24)% NOT NULL DEFAULT "aktiv",
                erstellt %DT%
            )%ENGINE%',

            'CREATE TABLE IF NOT EXISTS performance_entries (
                id %PK%,
                workspace_id %INT% NOT NULL,
                customer_id %INT% NOT NULL,
                datum %STR(16)% NOT NULL DEFAULT "",
                hcp %STR(16)% NOT NULL DEFAULT "",
                score %INT% NOT NULL DEFAULT 0,
                fairways %INT% NOT NULL DEFAULT 0,
                gir %INT% NOT NULL DEFAULT 0,
                putts %INT% NOT NULL DEFAULT 0,
                penalties %INT% NOT NULL DEFAULT 0,
                driving_distance %INT% NOT NULL DEFAULT 0,
                carry %INT% NOT NULL DEFAULT 0,
                club_speed %INT% NOT NULL DEFAULT 0,
                ball_speed %INT% NOT NULL DEFAULT 0,
                scrambling %INT% NOT NULL DEFAULT 0,
                up_and_down %INT% NOT NULL DEFAULT 0,
                platz %STR(160)% NOT NULL DEFAULT "",
                notiz %STR(255)% NOT NULL DEFAULT "",
                quelle %STR(24)% NOT NULL DEFAULT "trainer",   -- trainer|portal
                erstellt %DT%
            )%ENGINE%',

            /* =========================================== Videoanalyse === */

            'CREATE TABLE IF NOT EXISTS videos (
                id %PK%,
                workspace_id %INT% NOT NULL,
                customer_id %INT% NOT NULL DEFAULT 0,
                titel %STR(200)% NOT NULL DEFAULT "",
                datei %STR(255)% NOT NULL DEFAULT "",
                vorschau %STR(255)% NOT NULL DEFAULT "",
                quelle %STR(24)% NOT NULL DEFAULT "upload",
                ansicht %STR(24)% NOT NULL DEFAULT "face-on",  -- face-on|down-the-line|hinten
                schlaeger %STR(48)% NOT NULL DEFAULT "",
                dauer_sek %INT% NOT NULL DEFAULT 0,
                groesse %INT% NOT NULL DEFAULT 0,
                user_id %INT% NOT NULL DEFAULT 0,
                erstellt %DT%
            )%ENGINE%',

            'CREATE TABLE IF NOT EXISTS video_analyses (
                id %PK%,
                workspace_id %INT% NOT NULL,
                video_id %INT% NOT NULL DEFAULT 0,
                vergleich_video_id %INT% NOT NULL DEFAULT 0,
                customer_id %INT% NOT NULL DEFAULT 0,
                user_id %INT% NOT NULL DEFAULT 0,
                titel %STR(200)% NOT NULL DEFAULT "",
                zeichnungen %TEXT%,                            -- JSON: Linien, Kreise, Winkel, Text
                pro_analyse %TEXT%,
                ai_analyse %TEXT%,                             -- JSON: Hinweise der KI, getrennt gehalten
                ai_bestaetigt %INT% NOT NULL DEFAULT 0,
                empfehlungen %TEXT%,
                status %STR(24)% NOT NULL DEFAULT "entwurf",   -- entwurf|freigegeben
                freigegeben %DT%,
                erstellt %DT%
            )%ENGINE%',

            /* ================================================= Kurse ==== */

            'CREATE TABLE IF NOT EXISTS courses (
                id %PK%,
                workspace_id %INT% NOT NULL,
                titel %STR(200)% NOT NULL DEFAULT "",
                slug %STR(200)% NOT NULL DEFAULT "",
                kurztext %STR(255)% NOT NULL DEFAULT "",
                beschreibung %TEXT%,
                bild %STR(255)% NOT NULL DEFAULT "",
                preis_cent %INT% NOT NULL DEFAULT 0,
                niveau %STR(24)% NOT NULL DEFAULT "anfaenger",
                dauer_min %INT% NOT NULL DEFAULT 0,
                zertifikat %INT% NOT NULL DEFAULT 0,
                status %STR(24)% NOT NULL DEFAULT "entwurf",   -- entwurf|veroeffentlicht
                position %INT% NOT NULL DEFAULT 0,
                erstellt %DT%
            )%ENGINE%',

            'CREATE TABLE IF NOT EXISTS course_modules (
                id %PK%,
                workspace_id %INT% NOT NULL,
                course_id %INT% NOT NULL,
                titel %STR(200)% NOT NULL DEFAULT "",
                beschreibung %STR(255)% NOT NULL DEFAULT "",
                position %INT% NOT NULL DEFAULT 0
            )%ENGINE%',

            'CREATE TABLE IF NOT EXISTS course_lessons (
                id %PK%,
                workspace_id %INT% NOT NULL,
                course_id %INT% NOT NULL,
                module_id %INT% NOT NULL DEFAULT 0,
                titel %STR(200)% NOT NULL DEFAULT "",
                art %STR(24)% NOT NULL DEFAULT "video",        -- video|text|pdf|quiz|aufgabe
                inhalt %TEXT%,
                video_url %STR(255)% NOT NULL DEFAULT "",
                datei %STR(255)% NOT NULL DEFAULT "",
                quiz %TEXT%,                                   -- JSON
                dauer_min %INT% NOT NULL DEFAULT 5,
                position %INT% NOT NULL DEFAULT 0,
                vorschau %INT% NOT NULL DEFAULT 0
            )%ENGINE%',

            'CREATE TABLE IF NOT EXISTS course_enrollments (
                id %PK%,
                workspace_id %INT% NOT NULL,
                course_id %INT% NOT NULL,
                customer_id %INT% NOT NULL,
                order_id %INT% NOT NULL DEFAULT 0,
                fortschritt %INT% NOT NULL DEFAULT 0,
                begonnen %DT%,
                abgeschlossen %DT%,
                zertifikat_code %STR(48)% NOT NULL DEFAULT "",
                erstellt %DT%
            )%ENGINE%',

            'CREATE TABLE IF NOT EXISTS lesson_progress (
                id %PK%,
                workspace_id %INT% NOT NULL,
                enrollment_id %INT% NOT NULL,
                lesson_id %INT% NOT NULL,
                punkte %INT% NOT NULL DEFAULT 0,
                abgeschlossen %DT%
            )%ENGINE%',

            /* ============================================== Marketing === */

            'CREATE TABLE IF NOT EXISTS automations (
                id %PK%,
                workspace_id %INT% NOT NULL,
                name %STR(200)% NOT NULL DEFAULT "",
                beschreibung %STR(255)% NOT NULL DEFAULT "",
                ausloeser %STR(48)% NOT NULL DEFAULT "customer_created",
                ausloeser_config %TEXT%,
                status %STR(24)% NOT NULL DEFAULT "entwurf",   -- entwurf|aktiv|pausiert
                gestartet %INT% NOT NULL DEFAULT 0,
                abgeschlossen %INT% NOT NULL DEFAULT 0,
                erstellt %DT%
            )%ENGINE%',

            'CREATE TABLE IF NOT EXISTS automation_steps (
                id %PK%,
                workspace_id %INT% NOT NULL,
                automation_id %INT% NOT NULL,
                position %INT% NOT NULL DEFAULT 0,
                art %STR(32)% NOT NULL DEFAULT "email",        -- email|sms|warten|tag|segment|aufgabe|angebot|bedingung
                config %TEXT%,
                erstellt %DT%
            )%ENGINE%',

            'CREATE TABLE IF NOT EXISTS automation_runs (
                id %PK%,
                workspace_id %INT% NOT NULL,
                automation_id %INT% NOT NULL,
                customer_id %INT% NOT NULL DEFAULT 0,
                lead_id %INT% NOT NULL DEFAULT 0,
                schritt %INT% NOT NULL DEFAULT 0,
                status %STR(24)% NOT NULL DEFAULT "laufend",   -- laufend|abgeschlossen|abgebrochen
                naechster_lauf %DT%,
                erstellt %DT%
            )%ENGINE%',

            'CREATE TABLE IF NOT EXISTS campaigns (
                id %PK%,
                workspace_id %INT% NOT NULL,
                name %STR(200)% NOT NULL DEFAULT "",
                betreff %STR(220)% NOT NULL DEFAULT "",
                vorschautext %STR(220)% NOT NULL DEFAULT "",
                bloecke %TEXT%,                                -- JSON: Newsletter-Bausteine
                segment_id %INT% NOT NULL DEFAULT 0,
                status %STR(24)% NOT NULL DEFAULT "entwurf",   -- entwurf|geplant|versendet
                ab_test %INT% NOT NULL DEFAULT 0,
                ab_betreff %STR(220)% NOT NULL DEFAULT "",
                geplant %DT%,
                versendet %DT%,
                empfaenger %INT% NOT NULL DEFAULT 0,
                geoeffnet %INT% NOT NULL DEFAULT 0,
                geklickt %INT% NOT NULL DEFAULT 0,
                abgemeldet %INT% NOT NULL DEFAULT 0,
                erstellt %DT%
            )%ENGINE%',

            'CREATE TABLE IF NOT EXISTS forms (
                id %PK%,
                workspace_id %INT% NOT NULL,
                name %STR(160)% NOT NULL DEFAULT "",
                felder %TEXT%,                                 -- JSON
                ziel %STR(32)% NOT NULL DEFAULT "lead",
                bestaetigung %STR(255)% NOT NULL DEFAULT "",
                benachrichtigung %STR(190)% NOT NULL DEFAULT "",
                eingaenge %INT% NOT NULL DEFAULT 0,
                erstellt %DT%
            )%ENGINE%',

            /* ================================================ Inhalte === */

            'CREATE TABLE IF NOT EXISTS pages (
                id %PK%,
                workspace_id %INT% NOT NULL,
                titel %STR(200)% NOT NULL DEFAULT "",
                slug %STR(200)% NOT NULL DEFAULT "",
                bloecke %TEXT%,                                -- JSON: der Baukasten
                seo %TEXT%,                                    -- JSON: title, description, og
                startseite %INT% NOT NULL DEFAULT 0,
                im_menue %INT% NOT NULL DEFAULT 1,
                position %INT% NOT NULL DEFAULT 0,
                status %STR(24)% NOT NULL DEFAULT "entwurf",   -- entwurf|veroeffentlicht
                aufrufe %INT% NOT NULL DEFAULT 0,
                conversions %INT% NOT NULL DEFAULT 0,
                art %STR(24)% NOT NULL DEFAULT "seite",        -- seite|landingpage
                geaendert %DT%,
                erstellt %DT%
            )%ENGINE%',

            'CREATE TABLE IF NOT EXISTS posts (
                id %PK%,
                workspace_id %INT% NOT NULL,
                titel %STR(220)% NOT NULL DEFAULT "",
                slug %STR(220)% NOT NULL DEFAULT "",
                auszug %STR(400)% NOT NULL DEFAULT "",
                text %TEXT%,
                bild %STR(255)% NOT NULL DEFAULT "",
                category_id %INT% NOT NULL DEFAULT 0,
                tags %STR(255)% NOT NULL DEFAULT "",
                user_id %INT% NOT NULL DEFAULT 0,
                status %STR(24)% NOT NULL DEFAULT "entwurf",
                seo %TEXT%,
                quelle %STR(24)% NOT NULL DEFAULT "manuell",
                veroeffentlicht %DT%,
                aufrufe %INT% NOT NULL DEFAULT 0,
                erstellt %DT%
            )%ENGINE%',

            'CREATE TABLE IF NOT EXISTS categories (
                id %PK%,
                workspace_id %INT% NOT NULL,
                name %STR(120)% NOT NULL DEFAULT "",
                slug %STR(120)% NOT NULL DEFAULT "",
                art %STR(24)% NOT NULL DEFAULT "blog",
                position %INT% NOT NULL DEFAULT 0
            )%ENGINE%',

            'CREATE TABLE IF NOT EXISTS media (
                id %PK%,
                workspace_id %INT% NOT NULL,
                dateiname %STR(255)% NOT NULL DEFAULT "",
                pfad %STR(255)% NOT NULL DEFAULT "",
                mime %STR(80)% NOT NULL DEFAULT "",
                groesse %INT% NOT NULL DEFAULT 0,
                breite %INT% NOT NULL DEFAULT 0,
                hoehe %INT% NOT NULL DEFAULT 0,
                alt %STR(255)% NOT NULL DEFAULT "",
                ordner %STR(120)% NOT NULL DEFAULT "",
                user_id %INT% NOT NULL DEFAULT 0,
                erstellt %DT%
            )%ENGINE%',

            'CREATE TABLE IF NOT EXISTS documents (
                id %PK%,
                workspace_id %INT% NOT NULL,
                customer_id %INT% NOT NULL DEFAULT 0,
                titel %STR(220)% NOT NULL DEFAULT "",
                art %STR(32)% NOT NULL DEFAULT "pdf",          -- rechnung|trainingsplan|vertrag|einwilligung|pdf
                pfad %STR(255)% NOT NULL DEFAULT "",
                groesse %INT% NOT NULL DEFAULT 0,
                sichtbar_portal %INT% NOT NULL DEFAULT 1,
                user_id %INT% NOT NULL DEFAULT 0,
                erstellt %DT%
            )%ENGINE%',

            /* ================================================ Events ==== */

            'CREATE TABLE IF NOT EXISTS events (
                id %PK%,
                workspace_id %INT% NOT NULL,
                titel %STR(200)% NOT NULL DEFAULT "",
                slug %STR(200)% NOT NULL DEFAULT "",
                art %STR(32)% NOT NULL DEFAULT "workshop",     -- workshop|camp|gruppe|reise|turnier
                beschreibung %TEXT%,
                bild %STR(255)% NOT NULL DEFAULT "",
                start %DT%,
                ende %DT%,
                location_id %INT% NOT NULL DEFAULT 0,
                ort_text %STR(200)% NOT NULL DEFAULT "",
                kapazitaet %INT% NOT NULL DEFAULT 12,
                preis_cent %INT% NOT NULL DEFAULT 0,
                trainer_id %INT% NOT NULL DEFAULT 0,
                warteliste %INT% NOT NULL DEFAULT 1,
                status %STR(24)% NOT NULL DEFAULT "geplant",   -- geplant|veroeffentlicht|laeuft|abgeschlossen|abgesagt
                erstellt %DT%
            )%ENGINE%',

            'CREATE TABLE IF NOT EXISTS event_registrations (
                id %PK%,
                workspace_id %INT% NOT NULL,
                event_id %INT% NOT NULL,
                customer_id %INT% NOT NULL,
                status %STR(24)% NOT NULL DEFAULT "angemeldet",-- angemeldet|warteliste|abgesagt
                bezahlt %INT% NOT NULL DEFAULT 0,
                order_id %INT% NOT NULL DEFAULT 0,
                eingecheckt %DT%,
                erstellt %DT%
            )%ENGINE%',

            /* ============================================= Reisen ======= */

            /*
             * Eine Golfreise ist kein Event mit langem Datum.
             *
             * Sie hat ein Ziel und ein Hotel, Nächte statt Stunden, einen
             * Preis pro Person im Doppelzimmer und einen Zuschlag fürs
             * Einzelzimmer, eine Anzahlung, eine Mindestteilnehmerzahl,
             * eine Liste dessen, was enthalten ist, und einen Ablauf über
             * mehrere Tage. Nichts davon passt in die Eventtabelle, ohne
             * dass dort zehn Spalten stünden, die für Workshops immer leer
             * bleiben.
             *
             * `leistungen`, `nicht_enthalten`, `programm` und `bilder`
             * stehen als JSON: Es sind Listen ohne eigene Bedeutung für
             * Abfragen - niemand sucht Reisen danach, dass Halbpension
             * enthalten ist. Eigene Tabellen dafür wären vier Joins für
             * eine Aufzählung.
             */
            'CREATE TABLE IF NOT EXISTS trips (
                id %PK%,
                workspace_id %INT% NOT NULL,
                titel %STR(200)% NOT NULL DEFAULT "",
                slug %STR(200)% NOT NULL DEFAULT "",
                ziel %STR(160)% NOT NULL DEFAULT "",
                land %STR(120)% NOT NULL DEFAULT "",
                hotel %STR(200)% NOT NULL DEFAULT "",
                kurztext %STR(255)% NOT NULL DEFAULT "",
                beschreibung %TEXT%,
                leistungen %TEXT%,                            -- JSON: was enthalten ist
                nicht_enthalten %TEXT%,                       -- JSON: was nicht
                programm %TEXT%,                              -- JSON: [{titel, text}] je Tag
                bild %STR(255)% NOT NULL DEFAULT "",
                bilder %TEXT%,                                -- JSON: weitere Bilder
                start %DT%,
                ende %DT%,
                naechte %INT% NOT NULL DEFAULT 7,
                anreise %STR(32)% NOT NULL DEFAULT "eigen",   -- eigen|flug|bus
                abflug_ort %STR(120)% NOT NULL DEFAULT "",
                preis_cent %INT% NOT NULL DEFAULT 0,          -- pro Person im Doppelzimmer
                ez_zuschlag_cent %INT% NOT NULL DEFAULT 0,
                anzahlung_cent %INT% NOT NULL DEFAULT 0,
                plaetze %INT% NOT NULL DEFAULT 12,
                mindest_teilnehmer %INT% NOT NULL DEFAULT 0,
                trainer_id %INT% NOT NULL DEFAULT 0,
                warteliste %INT% NOT NULL DEFAULT 1,
                status %STR(24)% NOT NULL DEFAULT "geplant",  -- geplant|veroeffentlicht|abgeschlossen|abgesagt
                position %INT% NOT NULL DEFAULT 0,
                erstellt %DT%
            )%ENGINE%',

            /*
             * Die Anmeldung steht auch ohne Kundendatensatz: Wer über die
             * Website bucht, ist beim Absenden noch niemand. customer_id
             * wird nachgetragen, sobald der Kunde angelegt ist.
             */
            'CREATE TABLE IF NOT EXISTS trip_signups (
                id %PK%,
                workspace_id %INT% NOT NULL,
                trip_id %INT% NOT NULL,
                customer_id %INT% NOT NULL DEFAULT 0,
                name %STR(160)% NOT NULL DEFAULT "",
                email %STR(190)% NOT NULL DEFAULT "",
                telefon %STR(60)% NOT NULL DEFAULT "",
                zimmer %STR(16)% NOT NULL DEFAULT "dz",       -- dz|ez
                mitreisender %STR(160)% NOT NULL DEFAULT "",
                hcp %STR(16)% NOT NULL DEFAULT "",
                notiz %TEXT%,
                preis_cent %INT% NOT NULL DEFAULT 0,
                anzahlung_bezahlt %INT% NOT NULL DEFAULT 0,
                bezahlt %INT% NOT NULL DEFAULT 0,
                status %STR(24)% NOT NULL DEFAULT "angemeldet",-- angemeldet|bestaetigt|warteliste|storniert
                order_id %INT% NOT NULL DEFAULT 0,
                erstellt %DT%
            )%ENGINE%',

            /* ============================================= Community ==== */

            'CREATE TABLE IF NOT EXISTS community_groups (
                id %PK%,
                workspace_id %INT% NOT NULL,
                name %STR(160)% NOT NULL DEFAULT "",
                beschreibung %STR(255)% NOT NULL DEFAULT "",
                bild %STR(255)% NOT NULL DEFAULT "",
                privat %INT% NOT NULL DEFAULT 0,
                mitglieder %INT% NOT NULL DEFAULT 0,
                erstellt %DT%
            )%ENGINE%',

            'CREATE TABLE IF NOT EXISTS community_posts (
                id %PK%,
                workspace_id %INT% NOT NULL,
                group_id %INT% NOT NULL DEFAULT 0,
                autor_typ %STR(16)% NOT NULL DEFAULT "customer", -- customer|user
                autor_id %INT% NOT NULL DEFAULT 0,
                text %TEXT%,
                bild %STR(255)% NOT NULL DEFAULT "",
                likes %INT% NOT NULL DEFAULT 0,
                kommentare %INT% NOT NULL DEFAULT 0,
                angeheftet %INT% NOT NULL DEFAULT 0,
                erstellt %DT%
            )%ENGINE%',

            'CREATE TABLE IF NOT EXISTS community_comments (
                id %PK%,
                workspace_id %INT% NOT NULL,
                post_id %INT% NOT NULL,
                autor_typ %STR(16)% NOT NULL DEFAULT "customer",
                autor_id %INT% NOT NULL DEFAULT 0,
                text %TEXT%,
                erstellt %DT%
            )%ENGINE%',

            'CREATE TABLE IF NOT EXISTS challenges (
                id %PK%,
                workspace_id %INT% NOT NULL,
                titel %STR(200)% NOT NULL DEFAULT "",
                beschreibung %STR(255)% NOT NULL DEFAULT "",
                symbol %STR(16)% NOT NULL DEFAULT "",
                ziel_art %STR(32)% NOT NULL DEFAULT "einheiten",
                ziel_wert %INT% NOT NULL DEFAULT 7,
                start %STR(16)% NOT NULL DEFAULT "",
                ende %STR(16)% NOT NULL DEFAULT "",
                xp %INT% NOT NULL DEFAULT 100,
                aktiv %INT% NOT NULL DEFAULT 1,
                erstellt %DT%
            )%ENGINE%',

            'CREATE TABLE IF NOT EXISTS challenge_participants (
                id %PK%,
                workspace_id %INT% NOT NULL,
                challenge_id %INT% NOT NULL,
                customer_id %INT% NOT NULL,
                fortschritt %INT% NOT NULL DEFAULT 0,
                abgeschlossen %DT%,
                erstellt %DT%
            )%ENGINE%',

            'CREATE TABLE IF NOT EXISTS badges (
                id %PK%,
                workspace_id %INT% NOT NULL,
                name %STR(120)% NOT NULL DEFAULT "",
                beschreibung %STR(255)% NOT NULL DEFAULT "",
                symbol %STR(16)% NOT NULL DEFAULT "",
                kriterium %STR(48)% NOT NULL DEFAULT "",
                schwelle %INT% NOT NULL DEFAULT 1,
                xp %INT% NOT NULL DEFAULT 50
            )%ENGINE%',

            'CREATE TABLE IF NOT EXISTS customer_badges (
                id %PK%,
                workspace_id %INT% NOT NULL,
                customer_id %INT% NOT NULL,
                badge_id %INT% NOT NULL,
                erhalten %DT%
            )%ENGINE%',

            'CREATE TABLE IF NOT EXISTS customer_gamification (
                id %PK%,
                workspace_id %INT% NOT NULL,
                customer_id %INT% NOT NULL,
                xp %INT% NOT NULL DEFAULT 0,
                level %INT% NOT NULL DEFAULT 1,
                streak %INT% NOT NULL DEFAULT 0,
                bester_streak %INT% NOT NULL DEFAULT 0,
                letzte_aktivitaet %STR(16)% NOT NULL DEFAULT ""
            )%ENGINE%',

            /* ============================================= Analytics ==== */

            'CREATE TABLE IF NOT EXISTS web_visits (
                id %PK%,
                workspace_id %INT% NOT NULL,
                pfad %STR(255)% NOT NULL DEFAULT "",
                titel %STR(200)% NOT NULL DEFAULT "",
                referrer %STR(255)% NOT NULL DEFAULT "",
                quelle %STR(48)% NOT NULL DEFAULT "direkt",
                geraet %STR(16)% NOT NULL DEFAULT "desktop",
                besucher %STR(48)% NOT NULL DEFAULT "",
                datum %STR(16)% NOT NULL DEFAULT "",
                erstellt %DT%
            )%ENGINE%',

            /* ==================================================== KI ==== */

            'CREATE TABLE IF NOT EXISTS ai_messages (
                id %PK%,
                workspace_id %INT% NOT NULL,
                user_id %INT% NOT NULL DEFAULT 0,
                konversation %STR(48)% NOT NULL DEFAULT "",
                rolle %STR(16)% NOT NULL DEFAULT "user",
                text %TEXT%,
                daten %TEXT%,
                erstellt %DT%
            )%ENGINE%',

            'CREATE TABLE IF NOT EXISTS ai_suggestions (
                id %PK%,
                workspace_id %INT% NOT NULL,
                art %STR(48)% NOT NULL DEFAULT "",
                titel %STR(220)% NOT NULL DEFAULT "",
                text %TEXT%,
                nutzdaten %TEXT%,
                objekt %STR(48)% NOT NULL DEFAULT "",
                objekt_id %INT% NOT NULL DEFAULT 0,
                status %STR(24)% NOT NULL DEFAULT "offen",     -- offen|angenommen|abgelehnt
                user_id %INT% NOT NULL DEFAULT 0,
                entschieden %DT%,
                erstellt %DT%
            )%ENGINE%',
        ];
    }

    /** @return string[] */
    private static function indizes(): array
    {
        $spalten = [
            'users' => ['workspace_id', 'email'],
            'audit_log' => ['workspace_id', 'erstellt'],
            'locations' => ['workspace_id'],
            'settings' => ['workspace_id', 'schluessel'],
            'notifications' => ['workspace_id', 'user_id'],
            'tasks' => ['workspace_id', 'erledigt'],
            'customers' => ['workspace_id', 'nachname', 'email', 'status'],
            'customer_notes' => ['customer_id'],
            'communications' => ['workspace_id', 'customer_id'],
            'tags' => ['workspace_id'],
            'segments' => ['workspace_id'],
            'custom_fields' => ['workspace_id'],
            'leads' => ['workspace_id', 'stufe'],
            'consents' => ['workspace_id', 'customer_id'],
            'data_requests' => ['workspace_id'],
            'services' => ['workspace_id', 'slug'],
            'availability' => ['workspace_id', 'user_id'],
            'time_off' => ['workspace_id', 'user_id'],
            'bookings' => ['workspace_id', 'start', 'customer_id', 'trainer_id', 'status', 'invoice_id'],
            'booking_participants' => ['booking_id', 'customer_id'],
            'reminders' => ['workspace_id', 'booking_id', 'status', 'faellig'],
            'waitlist' => ['workspace_id', 'event_id'],
            'packages' => ['workspace_id'],
            'customer_packages' => ['workspace_id', 'customer_id', 'status'],
            'products' => ['workspace_id', 'slug', 'art'],
            'discounts' => ['workspace_id', 'code'],
            'orders' => ['workspace_id', 'customer_id', 'status', 'erstellt'],
            'order_items' => ['order_id'],
            'payments' => ['workspace_id', 'customer_id', 'status'],
            'invoices' => ['workspace_id', 'customer_id', 'status'],
            'invoice_items' => ['invoice_id'],
            'vouchers' => ['workspace_id', 'code'],
            'exercises' => ['workspace_id', 'kategorie'],
            'training_plans' => ['workspace_id', 'customer_id'],
            'plan_items' => ['plan_id'],
            'plan_assignments' => ['workspace_id', 'customer_id'],
            'performance_entries' => ['workspace_id', 'customer_id', 'datum'],
            'videos' => ['workspace_id', 'customer_id'],
            'video_analyses' => ['workspace_id', 'customer_id'],
            'courses' => ['workspace_id', 'slug'],
            'course_modules' => ['course_id'],
            'course_lessons' => ['course_id', 'module_id'],
            'course_enrollments' => ['workspace_id', 'customer_id', 'course_id'],
            'lesson_progress' => ['enrollment_id'],
            'automations' => ['workspace_id'],
            'automation_steps' => ['automation_id'],
            'automation_runs' => ['workspace_id', 'automation_id'],
            'campaigns' => ['workspace_id', 'status'],
            'forms' => ['workspace_id'],
            'pages' => ['workspace_id', 'slug'],
            'posts' => ['workspace_id', 'slug', 'status'],
            'categories' => ['workspace_id'],
            'media' => ['workspace_id'],
            'documents' => ['workspace_id', 'customer_id'],
            'events' => ['workspace_id', 'start'],
            'event_registrations' => ['event_id', 'customer_id'],
            'trips' => ['workspace_id', 'slug', 'start'],
            'trip_signups' => ['trip_id', 'customer_id'],
            'community_groups' => ['workspace_id'],
            'community_posts' => ['workspace_id', 'group_id'],
            'community_comments' => ['post_id'],
            'challenges' => ['workspace_id'],
            'challenge_participants' => ['challenge_id', 'customer_id'],
            'badges' => ['workspace_id'],
            'customer_badges' => ['customer_id'],
            'customer_gamification' => ['workspace_id', 'customer_id'],
            'web_visits' => ['workspace_id', 'datum'],
            'ai_messages' => ['workspace_id', 'konversation'],
            'ai_suggestions' => ['workspace_id', 'status'],
        ];
        $sql = ['CREATE INDEX IF NOT EXISTS idx_workspaces_slug ON workspaces (slug)'];
        foreach ($spalten as $tabelle => $liste) {
            foreach ($liste as $spalte) {
                $sql[] = "CREATE INDEX IF NOT EXISTS idx_{$tabelle}_{$spalte} ON {$tabelle} ({$spalte})";
            }
        }
        return $sql;
    }
}
