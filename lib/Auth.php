<?php
/**
 * Auth – Anmeldung, Sitzung und Rechte.
 *
 * Rollen sind nicht einfach Etiketten: Jede Seite fragt vor der Ausgabe
 * `Auth::fordern('...')`. Wer ein Recht nicht hat, sieht den Bereich nicht im
 * Menü *und* kommt über die Adresszeile nicht hinein. Beides ist nötig –
 * ein verstecktes Menü ist keine Zugriffskontrolle.
 *
 * Ein Trainer sieht seine eigenen Termine und Kunden, aber keine Umsätze.
 * Ein Buchhalter sieht Rechnungen, aber keine Trainingsvideos. Das ist der
 * Sinn der Sache: Eine Akademie kann Leute hinzunehmen, ohne ihnen alles
 * zu zeigen.
 */
final class Auth
{
    private const SITZUNG = 'gp_user';
    private static ?array $benutzer = null;

    /** Rollen in der Reihenfolge ihrer Mächtigkeit. */
    public const ROLLEN = [
        'owner'      => 'Inhaber',
        'admin'      => 'Administration',
        'head_pro'   => 'Head Pro',
        'trainer'    => 'Trainer',
        'assistant'  => 'Assistenz',
        'accountant' => 'Buchhaltung',
        'marketing'  => 'Marketing',
    ];

    /**
     * Rechte je Rolle. '*' heißt alles; sonst gilt genaue Übereinstimmung
     * oder ein Präfix mit '*' am Ende ('customers.*').
     */
    private const RECHTE = [
        'owner' => ['*'],

        'admin' => [
            'modul.*', 'customers.*', 'leads.*', 'bookings.*', 'training.*', 'video.*',
            'courses.*', 'products.*', 'payments.*', 'invoices.*', 'marketing.*',
            'website.*', 'content.*', 'events.*', 'community.*', 'analytics.*',
            'ai.*', 'settings.allgemein', 'settings.team', 'settings.recht', 'audit.view',
        ],

        'head_pro' => [
            'modul.dashboard', 'modul.customers', 'modul.leads', 'modul.calendar', 'modul.bookings',
            'modul.training', 'modul.video', 'modul.courses', 'modul.events', 'modul.community',
            'modul.analytics', 'modul.ai', 'modul.products', 'modul.website', 'modul.content', 'modul.settings',
            'customers.*', 'leads.*', 'bookings.*', 'training.*', 'video.*', 'courses.*',
            'events.*', 'community.*', 'analytics.view', 'ai.use', 'products.view',
            'settings.allgemein', 'settings.team',
        ],

        'trainer' => [
            'modul.dashboard', 'modul.customers', 'modul.calendar', 'modul.bookings',
            'modul.training', 'modul.video', 'modul.courses',
            'customers.view', 'customers.write', 'bookings.view', 'bookings.write',
            'training.*', 'video.*', 'courses.view', 'ai.use',
        ],

        'assistant' => [
            'modul.dashboard', 'modul.customers', 'modul.leads', 'modul.calendar', 'modul.bookings', 'modul.events',
            'customers.view', 'customers.write', 'leads.*', 'bookings.view', 'bookings.write', 'events.view',
        ],

        'accountant' => [
            'modul.dashboard', 'modul.invoices', 'modul.payments', 'modul.products', 'modul.analytics', 'modul.customers',
            'invoices.*', 'payments.*', 'products.view', 'customers.view', 'analytics.view',
        ],

        'marketing' => [
            'modul.dashboard', 'modul.website', 'modul.content', 'modul.marketing', 'modul.newsletter',
            'modul.automations', 'modul.leads', 'modul.analytics', 'modul.ai', 'modul.community', 'modul.events',
            'website.*', 'content.*', 'marketing.*', 'newsletter.*', 'automations.*',
            'leads.view', 'leads.write', 'analytics.view', 'ai.use', 'community.*', 'events.view',
        ],
    ];

    /* ------------------------------------------------------- Sitzung --- */

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $sicher = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
               || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'httponly' => true,
            'secure'   => $sicher,
            'samesite' => 'Lax',
        ]);
        session_name('gpsid');
        @session_start();
    }

    /* ----------------------------------------------------- Anmeldung --- */

    /** @return array{0:bool,1:string} [Erfolg, Meldung] */
    public static function anmelden(string $email, string $passwort): array
    {
        self::start();
        $email = strtolower(trim($email));

        if (self::gesperrt($email)) {
            return [false, 'Zu viele Versuche. Bitte in 15 Minuten erneut probieren.'];
        }

        $benutzer = DB::one('SELECT * FROM users WHERE email = :e AND aktiv = 1', ['e' => $email]);
        if (!$benutzer || !password_verify($passwort, (string) $benutzer['passwort'])) {
            self::fehlversuchMerken($email);
            // Eine einzige Meldung für „Konto unbekannt“ und „Passwort falsch“:
            // sonst verrät die Anmeldemaske, welche Adressen es gibt.
            return [false, 'E-Mail-Adresse oder Passwort stimmt nicht.'];
        }

        if (password_needs_rehash((string) $benutzer['passwort'], PASSWORD_DEFAULT)) {
            DB::update('users', ['passwort' => password_hash($passwort, PASSWORD_DEFAULT)],
                'id = :id', ['id' => $benutzer['id']]);
        }

        session_regenerate_id(true);
        $_SESSION[self::SITZUNG] = (int) $benutzer['id'];
        $_SESSION['gp_zeit']     = time();
        self::$benutzer = $benutzer;
        Tenant::setzen((int) $benutzer['workspace_id']);

        DB::update('users', ['letzter_login' => Util::jetzt()], 'id = :id', ['id' => $benutzer['id']]);
        Audit::schreiben('login', 'user', (int) $benutzer['id'], 'Anmeldung erfolgreich');

        return [true, ''];
    }

    /** Anmeldung ohne Passwort – ausschließlich für den Demo-Workspace. */
    public static function demoAnmelden(int $userId): bool
    {
        $benutzer = DB::one('SELECT u.* FROM users u JOIN workspaces w ON w.id = u.workspace_id
                             WHERE u.id = :id AND w.demo = 1 AND u.aktiv = 1', ['id' => $userId]);
        if (!$benutzer) {
            return false;
        }
        self::start();
        session_regenerate_id(true);
        $_SESSION[self::SITZUNG] = (int) $benutzer['id'];
        $_SESSION['gp_zeit']     = time();
        self::$benutzer = $benutzer;
        Tenant::setzen((int) $benutzer['workspace_id']);
        return true;
    }

    public static function abmelden(): void
    {
        self::start();
        if (self::angemeldet()) {
            Audit::schreiben('logout', 'user', self::id(), 'Abmeldung');
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
        self::$benutzer = null;
    }

    public static function angemeldet(): bool
    {
        return self::benutzer() !== null;
    }

    /** @return array<string,mixed>|null */
    public static function benutzer(): ?array
    {
        if (self::$benutzer !== null) {
            return self::$benutzer;
        }
        self::start();
        $id = (int) ($_SESSION[self::SITZUNG] ?? 0);
        if ($id <= 0) {
            return null;
        }
        // Sitzungen laufen nach 12 Stunden Untätigkeit ab.
        if (time() - (int) ($_SESSION['gp_zeit'] ?? 0) > 43200) {
            self::abmelden();
            return null;
        }
        $_SESSION['gp_zeit'] = time();

        $benutzer = DB::one('SELECT * FROM users WHERE id = :id AND aktiv = 1', ['id' => $id]);
        if (!$benutzer) {
            self::abmelden();
            return null;
        }
        self::$benutzer = $benutzer;
        if (!Tenant::gesetzt()) {
            Tenant::setzen((int) $benutzer['workspace_id']);
        }
        return $benutzer;
    }

    public static function id(): int
    {
        return (int) (self::benutzer()['id'] ?? 0);
    }

    public static function name(): string
    {
        return (string) (self::benutzer()['name'] ?? '');
    }

    public static function rolle(): string
    {
        return (string) (self::benutzer()['rolle'] ?? '');
    }

    public static function rollenName(?string $rolle = null): string
    {
        return self::ROLLEN[$rolle ?? self::rolle()] ?? '';
    }

    public static function istInhaber(): bool
    {
        return self::rolle() === 'owner';
    }

    /* --------------------------------------------------------- Rechte --- */

    public static function darf(string $recht): bool
    {
        $benutzer = self::benutzer();
        if (!$benutzer) {
            return false;
        }
        $rolle  = (string) $benutzer['rolle'];
        $rechte = self::RECHTE[$rolle] ?? [];

        // Einzelrechte am Benutzer können ergänzen oder entziehen.
        $extra = Util::ausJson((string) ($benutzer['rechte'] ?? ''), []);
        foreach ((array) ($extra['entzogen'] ?? []) as $weg) {
            if (self::passt($recht, (string) $weg)) {
                return false;
            }
        }
        foreach ((array) ($extra['zusatz'] ?? []) as $dazu) {
            if (self::passt($recht, (string) $dazu)) {
                return true;
            }
        }
        foreach ($rechte as $muster) {
            if (self::passt($recht, (string) $muster)) {
                return true;
            }
        }
        return false;
    }

    private static function passt(string $recht, string $muster): bool
    {
        if ($muster === '*' || $muster === $recht) {
            return true;
        }
        if (str_ends_with($muster, '.*')) {
            $praefix = substr($muster, 0, -1);           // "customers."
            return str_starts_with($recht, $praefix);
        }
        return false;
    }

    /** Bricht mit einer sauberen Seite ab, wenn das Recht fehlt. */
    public static function fordern(string $recht): void
    {
        if (!self::angemeldet()) {
            $ziel = urlencode($_SERVER['REQUEST_URI'] ?? '/app/');
            header('Location: ' . App::url('/login.php?weiter=' . $ziel));
            exit;
        }
        if (!self::darf($recht)) {
            http_response_code(403);
            App::verweigert($recht);
            exit;
        }
    }

    /* ----------------------------------------------------------- CSRF --- */

    public static function csrf(): string
    {
        self::start();
        if (empty($_SESSION['gp_csrf'])) {
            $_SESSION['gp_csrf'] = Util::token(24);
        }
        return (string) $_SESSION['gp_csrf'];
    }

    public static function csrfFeld(): string
    {
        return '<input type="hidden" name="_csrf" value="' . Util::attr(self::csrf()) . '">';
    }

    public static function csrfPruefen(): bool
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            return true;
        }
        self::start();
        $gesendet = (string) ($_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF'] ?? '');
        return $gesendet !== '' && hash_equals((string) ($_SESSION['gp_csrf'] ?? ''), $gesendet);
    }

    /** Für POST-Verarbeitung: prüft und bricht bei Fälschung ab. */
    public static function csrfFordern(): void
    {
        /*
         * Ein zu grosser POST kommt hier als fehlendes CSRF-Merkmal an: PHP
         * hat den Inhalt samt Token verworfen, bevor eine Zeile dieser
         * Anwendung lief. Die Antwort „Sicherheitspruefung fehlgeschlagen"
         * waere zwar technisch richtig, schickt aber in die falsche
         * Richtung - der Fehler ist fast immer ein zu grosses Foto.
         */
        if (App::postVerworfen()) {
            http_response_code(413);
            $grenze = App::uploadGrenze();
            exit('Die Datei ist zu gross für diesen Server'
                . ($grenze > 0 ? ' (Grenze: ' . Util::bytes($grenze) . ')' : '')
                . '. Bitte das Bild verkleinern – 1600 Pixel Breite reichen für '
                . 'jede Website – oder beim Hoster post_max_size und '
                . 'upload_max_filesize heraufsetzen lassen.');
        }
        if (!self::csrfPruefen()) {
            http_response_code(400);
            exit('Sicherheitsprüfung fehlgeschlagen. Bitte die Seite neu laden.');
        }
    }

    /* -------------------------------------------------- Passwörter ----- */

    public static function hash(string $passwort): string
    {
        return password_hash($passwort, PASSWORD_DEFAULT);
    }

    /** @return array{0:bool,1:string} */
    public static function passwortPruefen(string $passwort): array
    {
        if (strlen($passwort) < 10) {
            return [false, 'Das Passwort braucht mindestens 10 Zeichen.'];
        }
        if (preg_match('/^[a-z]+$/', $passwort) || preg_match('/^[0-9]+$/', $passwort)) {
            return [false, 'Bitte Buchstaben, Zahlen und am besten ein Sonderzeichen mischen.'];
        }
        return [true, ''];
    }

    public static function resetAnfordern(string $email): ?string
    {
        $benutzer = DB::one('SELECT id FROM users WHERE email = :e AND aktiv = 1', ['e' => strtolower(trim($email))]);
        if (!$benutzer) {
            return null;
        }
        $token = Util::token(24);
        DB::update('users', [
            'reset_token' => hash('sha256', $token),
            'reset_bis'   => date('Y-m-d H:i:s', time() + 3600),
        ], 'id = :id', ['id' => $benutzer['id']]);
        return $token;
    }

    public static function resetEinloesen(string $token, string $neuesPasswort): bool
    {
        $hash = hash('sha256', $token);
        $benutzer = DB::one('SELECT id FROM users WHERE reset_token = :t AND reset_bis > :jetzt',
            ['t' => $hash, 'jetzt' => Util::jetzt()]);
        if (!$benutzer) {
            return false;
        }
        DB::update('users', [
            'passwort'    => self::hash($neuesPasswort),
            'reset_token' => '',
            'reset_bis'   => null,
        ], 'id = :id', ['id' => $benutzer['id']]);
        return true;
    }

    /* ---------------------------------------------------- Bremse ------- */

    /**
     * Die Bremse gegen Durchprobieren – für Mitarbeiter wie für Kunden.
     *
     * Gezählt wird in zwei Richtungen, und beide sind nötig:
     *
     *   **Nach Adresse.** Hält den Angreifer auf, der ein Konto mit einer
     *   Passwortliste bearbeitet.
     *
     *   **Nach Herkunft.** Hält den auf, der eine gestohlene Liste aus
     *   tausend Zugangsdaten durchprobiert und jede Adresse nur einmal
     *   anfasst. Eine Zählung allein nach Adresse sieht davon nichts –
     *   und genau so läuft das Durchprobieren heute.
     *
     * Die Grenze nach Herkunft liegt höher: Hinter einer IP kann ein
     * ganzer Golfclub sitzen, und wer die Familie aussperrt, weil der
     * Sohn sein Passwort vergessen hat, hat nichts gewonnen.
     */
    public const BREMSE_FENSTER = 900;      // 15 Minuten
    public const BREMSE_ADRESSE = 8;
    public const BREMSE_HERKUNFT = 30;

    public static function versuchMerken(string $kennung, string $aktion = 'login_fehlgeschlagen'): void
    {
        DB::insert('audit_log', [
            'workspace_id' => 0,
            'user_id'      => 0,
            'aktion'       => $aktion,
            'objekt'       => 'user',
            'objekt_id'    => 0,
            'beschreibung' => $kennung,
            'ip'           => Util::ip(),
            'erstellt'     => Util::jetzt(),
        ]);
    }

    public static function versuchGesperrt(string $kennung, string $aktion = 'login_fehlgeschlagen'): bool
    {
        $seit = date('Y-m-d H:i:s', time() - self::BREMSE_FENSTER);

        $nachAdresse = DB::int(
            'SELECT COUNT(*) FROM audit_log WHERE aktion = :a AND beschreibung = :e AND erstellt > :seit',
            ['a' => $aktion, 'e' => $kennung, 'seit' => $seit]
        );
        if ($nachAdresse >= self::BREMSE_ADRESSE) {
            return true;
        }

        $ip = Util::ip();
        if ($ip === '') {
            return false;
        }
        $nachHerkunft = DB::int(
            'SELECT COUNT(*) FROM audit_log WHERE aktion = :a AND ip = :ip AND erstellt > :seit',
            ['a' => $aktion, 'ip' => $ip, 'seit' => $seit]
        );
        return $nachHerkunft >= self::BREMSE_HERKUNFT;
    }

    private static function fehlversuchMerken(string $email): void
    {
        self::versuchMerken($email);
    }

    private static function gesperrt(string $email): bool
    {
        return self::versuchGesperrt($email);
    }

    /* ------------------------------------------------------- Team ------ */

    /** @return array<int,array<string,mixed>> Trainer des Workspace */
    public static function trainer(): array
    {
        $liste = [];
        foreach (Tenant::all('users', 'aktiv = 1', [], 'name') as $u) {
            $liste[(int) $u['id']] = $u;
        }
        return $liste;
    }

    public static function trainerName(int $id): string
    {
        static $karte = null;
        if ($karte === null) {
            $karte = [];
            foreach (Tenant::all('users', '', [], 'name') as $u) {
                $karte[(int) $u['id']] = (string) $u['name'];
            }
        }
        return $karte[$id] ?? '—';
    }
}
