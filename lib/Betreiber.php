<?php
/**
 * Betreiber – die Menschen hinter TeePilot, die alle Instanzen sehen.
 *
 * Sie melden sich über dieselbe Anmeldemaske an wie jeder Golfpro, mit
 * derselben Bremse gegen Durchprobieren, demselben Passwort-Hashing und
 * demselben CSRF-Schutz. Getrennt ist, wo sie stehen und was ihre
 * Sitzung trägt:
 *
 *   **Eigene Tabelle.** Ein Betreiber ist kein Benutzer mit besonders
 *   hoher Rolle. Stünde „master_admin" als Rolle in `users`, wäre jede
 *   Stelle, die dort eine Rolle schreibt – Teamverwaltung, Import, eine
 *   künftige Schnittstelle –, ein möglicher Weg nach oben. So gibt es
 *   diesen Weg nicht: Keine Seite einer Instanz schreibt in `betreiber`.
 *
 *   **Eigener Sitzungsschlüssel.** `gp_betreiber` statt `gp_user`. Wer
 *   als Golfpro angemeldet ist, hat keinen Wert unter diesem Schlüssel,
 *   und `fordern()` prüft ihn bei jedem Aufruf gegen die Datenbank.
 *
 * Rechte laufen wie bei den Instanzen über Rollen und Rechtenamen, nicht
 * über Adressen. Heute gibt es eine Rolle mit allem; die Tabelle ist so
 * angelegt, dass eine Support- oder Auswertungsrolle nur eine Zeile in
 * RECHTE braucht.
 */
final class Betreiber
{
    private const SITZUNG = 'gp_betreiber';
    private const ZEIT    = 'gp_betreiber_zeit';

    /** Nach zwei Stunden ohne Klick ist die Sitzung vorbei. */
    private const LEERLAUF = 7200;

    public const ROLLEN = [
        'master_admin' => 'Master Admin',
    ];

    /**
     * Rechte je Rolle; '*' heißt alles.
     *
     * Vorgesehen, aber noch nicht vergeben – eine Zeile genügt:
     *   'support_admin' => ['zentrale.view', 'instanzen.view', 'benutzer.view', 'support.start'],
     *   'analyst'       => ['zentrale.view', 'instanzen.view', 'auswertung.view'],
     */
    private const RECHTE = [
        'master_admin' => ['*'],
    ];

    private static ?array $betreiber = null;

    /* ------------------------------------------------------ Anmeldung --- */

    /**
     * @return bool|null null: Es gibt keinen Betreiber mit dieser Adresse –
     *                   die Anmeldemaske versucht es dann als Golfpro.
     *                   false: Betreiber, aber falsches Passwort oder gebremst.
     */
    public static function anmelden(string $email, string $passwort): ?bool
    {
        Auth::start();
        $email = strtolower(trim($email));
        $zeile = DB::one('SELECT * FROM betreiber WHERE email = :e', ['e' => $email]);
        if ($zeile === null) {
            return null;
        }
        if (Auth::versuchGesperrt($email)) {
            return false;
        }
        if ((int) $zeile['aktiv'] !== 1 || !password_verify($passwort, (string) $zeile['passwort'])) {
            Auth::versuchMerken($email);
            Betreiberlog::schreiben('MASTER_LOGIN_FAILED', [
                'objekt' => 'betreiber', 'objekt_id' => (int) $zeile['id'],
                'betreiber_id' => 0, 'akteur' => $email, 'ergebnis' => 'verweigert',
            ]);
            return false;
        }
        if (password_needs_rehash((string) $zeile['passwort'], PASSWORD_DEFAULT)) {
            DB::update('betreiber', ['passwort' => password_hash($passwort, PASSWORD_DEFAULT)],
                'id = :id', ['id' => (int) $zeile['id']]);
        }

        session_regenerate_id(true);
        /* Eine Sitzung ist entweder Golfpro oder Betreiber – nie beides
           nebeneinander, außer im ausdrücklich begonnenen Support Mode. */
        unset($_SESSION['gp_user'], $_SESSION['gp_zeit'], $_SESSION['gp_support']);
        $_SESSION[self::SITZUNG] = (int) $zeile['id'];
        $_SESSION[self::ZEIT]    = time();
        self::$betreiber = $zeile;

        DB::update('betreiber', ['letzter_login' => Util::jetzt()], 'id = :id', ['id' => (int) $zeile['id']]);
        Betreiberlog::schreiben('MASTER_LOGIN', ['objekt' => 'betreiber', 'objekt_id' => (int) $zeile['id']]);
        return true;
    }

    public static function abmelden(): void
    {
        Auth::start();
        if (self::angemeldet()) {
            Betreiberlog::schreiben('MASTER_LOGOUT', ['objekt' => 'betreiber', 'objekt_id' => self::id()]);
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
        self::$betreiber = null;
    }

    public static function angemeldet(): bool
    {
        return self::benutzer() !== null;
    }

    /** @return array<string,mixed>|null */
    public static function benutzer(): ?array
    {
        if (self::$betreiber !== null) {
            return self::$betreiber;
        }
        if (session_status() !== PHP_SESSION_ACTIVE) {
            if (PHP_SAPI === 'cli') {
                return null;        // Kommandozeile: keine Sitzung, kein Betreiber
            }
            Auth::start();
        }
        $id = (int) ($_SESSION[self::SITZUNG] ?? 0);
        if ($id <= 0) {
            return null;
        }
        if (time() - (int) ($_SESSION[self::ZEIT] ?? 0) > self::LEERLAUF) {
            unset($_SESSION[self::SITZUNG], $_SESSION[self::ZEIT]);
            return null;
        }
        $zeile = DB::one('SELECT * FROM betreiber WHERE id = :id AND aktiv = 1', ['id' => $id]);
        if ($zeile === null) {
            unset($_SESSION[self::SITZUNG], $_SESSION[self::ZEIT]);
            return null;
        }
        $_SESSION[self::ZEIT] = time();
        return self::$betreiber = $zeile;
    }

    public static function id(): int
    {
        return (int) (self::benutzer()['id'] ?? 0);
    }

    public static function name(): string
    {
        return (string) (self::benutzer()['name'] ?? '');
    }

    public static function rollenName(?string $rolle = null): string
    {
        return self::ROLLEN[$rolle ?? (string) (self::benutzer()['rolle'] ?? '')] ?? '';
    }

    /** Als Akteur für Pakete::zuweisen() und Ähnliches. */
    public static function akteur(): array
    {
        return ['art' => 'betreiber', 'id' => self::id(), 'name' => self::name()];
    }

    /* ---------------------------------------------------------- Rechte --- */

    public static function darf(string $recht): bool
    {
        $b = self::benutzer();
        if ($b === null) {
            return false;
        }
        foreach (self::RECHTE[(string) $b['rolle']] ?? [] as $muster) {
            if ($muster === '*' || $muster === $recht
                || (str_ends_with($muster, '.*') && str_starts_with($recht, substr($muster, 0, -1)))) {
                return true;
            }
        }
        return false;
    }

    /**
     * Tür jeder Seite unter /master/.
     *
     * Nicht angemeldet: zur Anmeldung, wie überall. Angemeldet als
     * Golfpro – oder als Betreiber ohne dieses Recht: 403 mit einer
     * eigenen Seite, und ein Eintrag im Protokoll. Ein Golfpro, der
     * /master/ aufruft, hat sich nicht verklickt; das soll jemand sehen.
     */
    public static function fordern(string $recht = 'zentrale.view', bool $json = false): void
    {
        if (!self::angemeldet() && !Auth::angemeldet()) {
            if ($json) {
                App::json(['ok' => false, 'fehler' => 'Nicht angemeldet.'], 401);
            }
            $ziel = urlencode((string) ($_SERVER['REQUEST_URI'] ?? '/master/'));
            header('Location: ' . App::url('/login.php?weiter=' . $ziel));
            exit;
        }
        if (self::darf($recht)) {
            return;
        }
        Betreiberlog::schreiben('ACCESS_DENIED', [
            'betreiber_id' => self::id(),
            'akteur' => self::angemeldet() ? self::name() : 'Benutzer #' . Auth::id() . ' (Instanz #' . Tenant::id() . ')',
            'ergebnis' => 'verweigert',
            'beschreibung' => mb_substr((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET') . ' '
                . (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), 0, 200),
        ]);
        http_response_code(403);
        if ($json) {
            App::json(['ok' => false, 'fehler' => 'Keine Berechtigung.'], 403);
        }
        self::verweigert();
        exit;
    }

    private static function verweigert(): void
    {
        $zurueck = Auth::angemeldet() ? App::url('/app/') : App::url('/master/');
        echo '<!DOCTYPE html><html lang="de" data-theme="hell"><head><meta charset="utf-8">'
           . '<meta name="viewport" content="width=device-width, initial-scale=1">'
           . '<meta name="robots" content="noindex, nofollow"><title>Keine Berechtigung · TeePilot</title>'
           . '<link rel="stylesheet" href="' . Util::attr(App::asset('assets/css/schriften.css')) . '">'
           . '<link rel="stylesheet" href="' . Util::attr(App::asset('assets/css/app.css')) . '">'
           . '<link rel="icon" href="' . Util::attr(Marke::favicon()) . '"></head><body>'
           . '<main class="verweigert-seite"><div class="leerzustand leerzustand--gross">'
           . '<div class="leerzustand__symbol">' . Icon::svg('lock', 30) . '</div>'
           . '<h1>Keine Berechtigung</h1>'
           . '<p>Dieser Bereich ist der Betreiberzentrale von TeePilot vorbehalten. '
           . 'Dein Zugang gehört zu einer Instanz und hat hier keine Rechte.</p>'
           . '<a class="btn btn--primaer" href="' . Util::attr($zurueck) . '">Zurück</a>'
           . '</div></main></body></html>';
    }

    /* ------------------------------------------------ Anlegen, Zugang --- */

    /**
     * Legt einen Betreiber an – ohne Passwort. Zurück kommt ein Token,
     * mit dem die Person ihr Passwort selbst setzt. Das Passwort kennt
     * danach nur sie; kein anderer Betreiber hat es je gesehen.
     *
     * @return array{0:int,1:string,2:string} [id, token, Fehler]
     */
    public static function anlegen(string $email, string $name, string $rolle = 'master_admin', string $akteur = ''): array
    {
        $email = strtolower(trim($email));
        $name  = trim($name);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return [0, '', 'Bitte eine gültige E-Mail-Adresse angeben.'];
        }
        if ($name === '') {
            return [0, '', 'Bitte einen Namen angeben.'];
        }
        if (!isset(self::ROLLEN[$rolle])) {
            return [0, '', 'Unbekannte Rolle.'];
        }
        if (DB::int('SELECT COUNT(*) FROM betreiber WHERE email = :e', ['e' => $email]) > 0) {
            return [0, '', 'Mit dieser Adresse gibt es schon einen Betreiber.'];
        }
        /* Dieselbe Adresse als Golfpro und als Betreiber hieße: Die
           Anmeldemaske müsste raten, wer gemeint ist. */
        if (DB::int('SELECT COUNT(*) FROM users WHERE email = :e', ['e' => $email]) > 0) {
            return [0, '', 'Diese Adresse gehört schon zu einem Benutzer einer Instanz. '
                         . 'Betreiber brauchen eine eigene Adresse.'];
        }
        $token = Util::token(24);
        $id = DB::insert('betreiber', [
            'email' => $email, 'name' => $name, 'rolle' => $rolle, 'aktiv' => 1,
            'passwort' => password_hash(Util::token(24), PASSWORD_DEFAULT),
            'reset_token' => hash('sha256', $token),
            'reset_bis' => date('Y-m-d H:i:s', time() + 7 * 86400),
            'erstellt' => Util::jetzt(),
        ]);
        Betreiberlog::schreiben('OPERATOR_CREATED', [
            'objekt' => 'betreiber', 'objekt_id' => $id,
            'nachher' => ['email' => $email, 'name' => $name, 'rolle' => $rolle],
            'akteur' => $akteur !== '' ? $akteur : self::name(),
        ]);
        return [$id, $token, ''];
    }

    public static function anzahl(): int
    {
        try {
            return DB::int('SELECT COUNT(*) FROM betreiber');
        } catch (Throwable $e) {
            return 0;
        }
    }

    public static function resetAnfordern(string $email): ?string
    {
        $zeile = DB::one('SELECT id FROM betreiber WHERE email = :e AND aktiv = 1', ['e' => strtolower(trim($email))]);
        if ($zeile === null) {
            return null;
        }
        $token = Util::token(24);
        DB::update('betreiber', [
            'reset_token' => hash('sha256', $token),
            'reset_bis'   => date('Y-m-d H:i:s', time() + 3600),
        ], 'id = :id', ['id' => (int) $zeile['id']]);
        return $token;
    }

    /**
     * Die Bremse für folgenreiche Handlungen: höchstens $grenze Mal in
     * fünfzehn Minuten. Wer darüber liegt, ist entweder ein Skript oder
     * jemand, der gerade einen Fehler in großer Zahl wiederholt.
     */
    public static function gebremst(string $aktion, int $grenze): bool
    {
        return Betreiberlog::haeufigkeit($aktion, self::id()) >= $grenze;
    }
}
