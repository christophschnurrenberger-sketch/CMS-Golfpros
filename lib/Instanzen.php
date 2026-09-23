<?php
/**
 * Instanzen – die Workspaces, von oben gesehen.
 *
 * Innen heißt es weiter „Workspace" und `workspace_id`, in der
 * Oberfläche der Betreiberzentrale „Instanz". Diese Klasse ist die
 * einzige Stelle, die über Mandantengrenzen hinweg liest und schreibt –
 * deshalb geht sie nie über `Tenant::all()` (das ist ja gerade auf einen
 * Mandanten beschränkt), sondern nennt `workspace_id` immer ausdrücklich,
 * und jede Methode, die schreibt, wird nur aus /master/ aufgerufen,
 * hinter `Betreiber::fordern()`.
 *
 * Status und Wirkung:
 *
 *   test, aktiv   alles läuft
 *   pausiert      das Team kann sich nicht anmelden, die Schnittstelle
 *                 antwortet nicht; Website, Buchung und Kundenportal
 *                 bleiben erreichbar – die Kunden des Pros sollen nicht
 *                 vor verschlossener Tür stehen, weil etwas zwischen ihm
 *                 und TeePilot zu klären ist
 *   gesperrt      zusätzlich ist die Website offline
 *   archiviert    wie gesperrt, und aus den Standardlisten ausgeblendet;
 *                 alle Daten bleiben erhalten
 *
 * `workspaces.aktiv` behält seine alte Bedeutung „Website erreichbar"
 * und wird hier mitgeführt. Damit greifen die vorhandenen Abfragen der
 * öffentlichen Seiten (`WHERE aktiv = 1`), ohne dass eine davon
 * angefasst werden muss.
 */
final class Instanzen
{
    /** Status => [Bezeichnung, Farbe der Pille] */
    public const STATUS = [
        'test'       => ['Testphase', 'info'],
        'aktiv'      => ['Aktiv', 'erfolg'],
        'pausiert'   => ['Pausiert', 'warnung'],
        'gesperrt'   => ['Gesperrt', 'gefahr'],
        'archiviert' => ['Archiviert', 'offen'],
    ];

    public const WIRKUNG = [
        'test'       => 'Alles läuft. Die Testphase endet laut Vertragsdaten.',
        'aktiv'      => 'Alles läuft: Anmeldung, Website, Buchung, Schnittstelle.',
        'pausiert'   => 'Das Team kann sich nicht anmelden, die Schnittstelle antwortet nicht. '
                      . 'Website, Online-Buchung und Kundenportal bleiben erreichbar.',
        'gesperrt'   => 'Anmeldung, Schnittstelle, Website, Buchung und Kundenportal sind gesperrt. '
                      . 'Alle Daten bleiben erhalten.',
        'archiviert' => 'Wie gesperrt – zusätzlich aus den Standardlisten ausgeblendet. '
                      . 'Alle Daten bleiben erhalten; Reaktivieren ist jederzeit möglich.',
    ];

    /** Wohin ein Betreiber eine Instanz setzen kann – „Testphase" entsteht nur beim Anlegen. */
    public const ZIELE = ['aktiv', 'pausiert', 'gesperrt', 'archiviert'];

    private const STATUS_AKTION = [
        'aktiv' => 'TENANT_ACTIVATED', 'pausiert' => 'TENANT_PAUSED',
        'gesperrt' => 'TENANT_LOCKED', 'archiviert' => 'TENANT_ARCHIVED',
    ];

    public const GESUNDHEIT = [
        'aktiv'   => ['Aktiv genutzt', 'erfolg'],
        'niedrig' => ['Wenig genutzt', 'warnung'],
        'inaktiv' => ['Inaktiv', 'gefahr'],
        'nie'     => ['Noch nie genutzt', 'offen'],
    ];

    public const WAEHRUNGEN = ['EUR' => 'Euro', 'CHF' => 'Schweizer Franken', 'GBP' => 'Pfund', 'USD' => 'US-Dollar'];

    public const TYPEN = ['pro' => 'Golfpro', 'academy' => 'Akademie'];

    /* ------------------------------------------------- Status, Zugang --- */

    public static function statusName(string $status): string
    {
        return self::STATUS[$status][0] ?? 'Unbekannt';
    }

    public static function statusPille(string $status): string
    {
        [$text, $art] = self::STATUS[$status] ?? ['Unbekannt', 'offen'];
        return '<span class="pille pille--' . $art . ' pille--punkt">' . Util::h($text) . '</span>';
    }

    /** Darf sich das Team anmelden? Ein leerer Status stammt aus der Zeit vor der Spalte. */
    public static function zugangErlaubt(string $status): bool
    {
        return in_array($status, ['', 'test', 'aktiv'], true);
    }

    public static function sperrText(string $status): string
    {
        return match ($status) {
            'pausiert' => 'Dieser Zugang ist vorübergehend pausiert. Bitte wende dich an den TeePilot Support.',
            default    => 'Dieser Zugang ist gesperrt. Bitte wende dich an den TeePilot Support.',
        };
    }

    /**
     * Merkt sich, dass das Team gerade arbeitet – höchstens alle zehn
     * Minuten je Sitzung, damit nicht jeder Klick einen Schreibzugriff
     * auf die Instanz auslöst.
     */
    public static function aktivitaetMerken(int $workspaceId): void
    {
        if ($workspaceId <= 0 || time() - (int) ($_SESSION['gp_aktiv_gemerkt'] ?? 0) < 600) {
            return;
        }
        $_SESSION['gp_aktiv_gemerkt'] = time();
        try {
            DB::update('workspaces', ['letzte_aktivitaet' => Util::jetzt()], 'id = :id', ['id' => $workspaceId]);
        } catch (Throwable $e) {
            // Vor dem Nachrüsten gibt es die Spalte noch nicht.
        }
    }

    /** aktiv | niedrig | inaktiv | nie – nach den Schwellen in den Einstellungen. */
    public static function gesundheit(?string $letzte): string
    {
        if ($letzte === null || $letzte === '') {
            return 'nie';
        }
        $tage = Util::tageSeit($letzte);
        if ($tage <= Plattform::zahl('aktiv_tage')) {
            return 'aktiv';
        }
        return $tage <= Plattform::zahl('inaktiv_tage') ? 'niedrig' : 'inaktiv';
    }

    public static function gesundheitPille(?string $letzte): string
    {
        [$text, $art] = self::GESUNDHEIT[self::gesundheit($letzte)];
        return '<span class="pille pille--' . $art . '">' . Util::h($text) . '</span>';
    }

    /* ---------------------------------------------------------- Lesen --- */

    /** Die Spalten, die jede Liste und jede Detailseite braucht. */
    private static function spalten(): string
    {
        return "w.*,
            (SELECT u.name FROM users u WHERE u.workspace_id = w.id AND u.rolle = 'owner'
               ORDER BY u.aktiv DESC, u.id LIMIT 1) AS inhaber,
            (SELECT u.email FROM users u WHERE u.workspace_id = w.id AND u.rolle = 'owner'
               ORDER BY u.aktiv DESC, u.id LIMIT 1) AS inhaber_email,
            (SELECT l.name FROM locations l WHERE l.workspace_id = w.id AND l.aktiv = 1
               ORDER BY CASE WHEN l.typ = 'club' THEN 0 ELSE 1 END, l.id LIMIT 1) AS club,
            (SELECT COUNT(*) FROM users u WHERE u.workspace_id = w.id AND u.aktiv = 1) AS benutzer";
    }

    public static function finden(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        return DB::one('SELECT ' . self::spalten() . ' FROM workspaces w WHERE w.id = :id', ['id' => $id]);
    }

    public const SORTIERUNG = [
        'name'       => 'w.name',
        'erstellt'   => 'w.erstellt',
        'aktivitaet' => "CASE WHEN w.letzte_aktivitaet IS NULL OR w.letzte_aktivitaet = '' THEN 1 ELSE 0 END, w.letzte_aktivitaet",
        'status'     => 'w.status',
        'paket'      => 'w.plan',
        'benutzer'   => 'benutzer',
        'id'         => 'w.id',
    ];

    /**
     * Filter aus der Adresszeile, geprüft und auf Bekanntes beschränkt.
     *
     * @param array<string,mixed> $roh
     * @return array<string,string|int>
     */
    public static function filter(array $roh): array
    {
        $datum = static fn ($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : '';
        $status = (string) ($roh['status'] ?? '');
        $sort = (string) ($roh['sort'] ?? 'erstellt');
        $pro = (int) ($roh['pro'] ?? 25);
        return [
            'q'       => mb_substr(trim((string) ($roh['q'] ?? '')), 0, 120),
            'status'  => isset(self::STATUS[$status]) || $status === 'alle' ? $status : '',
            'paket'   => Pakete::finden((string) ($roh['paket'] ?? '')) !== null ? (string) $roh['paket'] : '',
            'nutzung' => isset(self::GESUNDHEIT[(string) ($roh['nutzung'] ?? '')]) ? (string) $roh['nutzung'] : '',
            'von'     => $datum($roh['von'] ?? ''),
            'bis'     => $datum($roh['bis'] ?? ''),
            'demo'    => in_array($roh['demo'] ?? '', ['ohne', 'nur'], true) ? (string) $roh['demo'] : '',
            'sort'    => isset(self::SORTIERUNG[$sort]) ? $sort : 'erstellt',
            'richtung'=> ($roh['richtung'] ?? 'desc') === 'asc' ? 'asc' : 'desc',
            'pro'     => in_array($pro, [25, 50, 100], true) ? $pro : 25,
        ];
    }

    /**
     * @param array<string,string|int> $f aus filter()
     * @return array{0:array<int,array<string,mixed>>,1:int}
     */
    public static function liste(array $f, int $seite = 1, ?int $proSeite = null): array
    {
        [$wo, $p] = self::bedingung($f);
        $gesamt = DB::int('SELECT COUNT(*) FROM workspaces w' . $wo, $p);
        $pro = $proSeite ?? (int) $f['pro'];
        $richtung = $f['richtung'] === 'asc' ? 'ASC' : 'DESC';
        $sortierung = implode(', ', array_map(
            static fn ($teil) => trim($teil) . (str_starts_with(trim($teil), 'CASE') ? '' : ' ' . $richtung),
            explode(', ', self::SORTIERUNG[(string) $f['sort']])
        ));
        $sql = 'SELECT ' . self::spalten() . ' FROM workspaces w' . $wo
             . ' ORDER BY ' . $sortierung . ', w.id ' . $richtung;
        if ($pro > 0) {
            $sql .= ' LIMIT ' . $pro . ' OFFSET ' . max(0, ($seite - 1) * $pro);
        }
        return [DB::all($sql, $p), $gesamt];
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private static function bedingung(array $f): array
    {
        $wo = [];
        $p  = [];
        $q = (string) ($f['q'] ?? '');
        if ($q !== '') {
            $muster = '%' . strtr($q, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
            $teile = [
                "w.name LIKE :q ESCAPE '!'", "w.slug LIKE :q ESCAPE '!'", "w.domain LIKE :q ESCAPE '!'",
                "EXISTS (SELECT 1 FROM users u WHERE u.workspace_id = w.id
                         AND (u.name LIKE :q ESCAPE '!' OR u.email LIKE :q ESCAPE '!'))",
                "EXISTS (SELECT 1 FROM locations l WHERE l.workspace_id = w.id AND l.name LIKE :q ESCAPE '!')",
            ];
            $p['q'] = $muster;
            if (ctype_digit(ltrim($q, '#'))) {
                $teile[] = 'w.id = :qid';
                $p['qid'] = (int) ltrim($q, '#');
            }
            $wo[] = '(' . implode(' OR ', $teile) . ')';
        }
        $status = (string) ($f['status'] ?? '');
        if ($status === '') {
            $wo[] = "w.status != 'archiviert'";
        } elseif ($status !== 'alle') {
            $wo[] = 'w.status = :status';
            $p['status'] = $status;
        }
        if (($f['paket'] ?? '') !== '') {
            $wo[] = 'w.plan = :paket';
            $p['paket'] = (string) $f['paket'];
        }
        if (($f['von'] ?? '') !== '') {
            $wo[] = 'w.erstellt >= :von';
            $p['von'] = $f['von'] . ' 00:00:00';
        }
        if (($f['bis'] ?? '') !== '') {
            $wo[] = 'w.erstellt <= :bis';
            $p['bis'] = $f['bis'] . ' 23:59:59';
        }
        if (($f['demo'] ?? '') === 'ohne') {
            $wo[] = 'w.demo = 0';
        } elseif (($f['demo'] ?? '') === 'nur') {
            $wo[] = 'w.demo = 1';
        }
        $aktivAb   = date('Y-m-d H:i:s', strtotime('-' . Plattform::zahl('aktiv_tage') . ' days'));
        $inaktivAb = date('Y-m-d H:i:s', strtotime('-' . Plattform::zahl('inaktiv_tage') . ' days'));
        switch ((string) ($f['nutzung'] ?? '')) {
            case 'aktiv':
                $wo[] = 'w.letzte_aktivitaet >= :aktivab';
                $p['aktivab'] = $aktivAb;
                break;
            case 'niedrig':
                $wo[] = 'w.letzte_aktivitaet < :aktivab AND w.letzte_aktivitaet >= :inaktivab';
                $p['aktivab'] = $aktivAb;
                $p['inaktivab'] = $inaktivAb;
                break;
            case 'inaktiv':
                $wo[] = "w.letzte_aktivitaet < :inaktivab AND w.letzte_aktivitaet != ''";
                $p['inaktivab'] = $inaktivAb;
                break;
            case 'nie':
                $wo[] = "(w.letzte_aktivitaet IS NULL OR w.letzte_aktivitaet = '')";
                break;
        }
        return [$wo === [] ? '' : ' WHERE ' . implode(' AND ', $wo), $p];
    }

    /** @return array<int,array<string,mixed>> Benutzer einer Instanz, ohne Geheimnisse */
    public static function benutzer(int $workspaceId): array
    {
        return DB::all("SELECT id, name, email, rolle, aktiv, letzter_login, erstellt,
                               CASE WHEN reset_token != '' AND reset_bis > :jetzt THEN 1 ELSE 0 END AS einladung_offen
                        FROM users WHERE workspace_id = :w ORDER BY aktiv DESC,
                        CASE rolle WHEN 'owner' THEN 0 WHEN 'admin' THEN 1 ELSE 2 END, name",
            ['w' => $workspaceId, 'jetzt' => Util::jetzt()]);
    }

    /**
     * Nutzungszahlen – Anzahlen, keine Inhalte.
     *
     * Wer hier schaut, sieht, ob eine Instanz lebt, nicht, mit wem sie
     * Geschäfte macht. Namen, Beträge und Texte bleiben in der Instanz;
     * wer sie braucht, öffnet den Support Mode, und der wird protokolliert.
     *
     * @return array<string,mixed>
     */
    public static function nutzung(int $workspaceId): array
    {
        $w = ['w' => $workspaceId];
        $zahl = static fn (string $sql, array $p = []) => DB::int($sql, $p + $w);
        $monat = date('Y-m-01 00:00:00');
        $naechster = date('Y-m-01 00:00:00', strtotime('first day of next month'));
        $vor30 = date('Y-m-d H:i:s', strtotime('-30 days'));
        return [
            'benutzer_aktiv' => $zahl('SELECT COUNT(*) FROM users WHERE workspace_id = :w AND aktiv = 1'),
            'benutzer'       => $zahl('SELECT COUNT(*) FROM users WHERE workspace_id = :w'),
            'kunden'         => $zahl("SELECT COUNT(*) FROM customers WHERE workspace_id = :w AND status != 'archiviert'"),
            'kunden_neu_30'  => $zahl('SELECT COUNT(*) FROM customers WHERE workspace_id = :w AND erstellt >= :s', ['s' => $vor30]),
            'termine'        => $zahl('SELECT COUNT(*) FROM bookings WHERE workspace_id = :w'),
            'termine_monat'  => $zahl("SELECT COUNT(*) FROM bookings WHERE workspace_id = :w AND status != 'abgesagt'
                                       AND start >= :a AND start < :b", ['a' => $monat, 'b' => $naechster]),
            'termine_neu_30' => $zahl('SELECT COUNT(*) FROM bookings WHERE workspace_id = :w AND erstellt >= :s', ['s' => $vor30]),
            'seiten'         => $zahl("SELECT COUNT(*) FROM pages WHERE workspace_id = :w AND status = 'veroeffentlicht'"),
            'beitraege'      => $zahl("SELECT COUNT(*) FROM posts WHERE workspace_id = :w AND status = 'veroeffentlicht'"),
            'rechnungen'     => $zahl('SELECT COUNT(*) FROM invoices WHERE workspace_id = :w'),
            'newsletter'     => $zahl("SELECT COUNT(*) FROM campaigns WHERE workspace_id = :w AND status = 'versendet'"),
            'kurse'          => $zahl('SELECT COUNT(*) FROM courses WHERE workspace_id = :w'),
            'events'         => $zahl('SELECT COUNT(*) FROM events WHERE workspace_id = :w'),
            'letzter_login'  => (string) DB::value('SELECT MAX(letzter_login) FROM users WHERE workspace_id = :w', $w, ''),
            'speicher'       => self::speicher($workspaceId),
        ];
    }

    /** Bytes in den Ordnern der Instanz – echte Dateien, nicht geschätzt. */
    public static function speicher(int $workspaceId): int
    {
        $summe = 0;
        foreach (self::ordner($workspaceId) as $ordner) {
            if (!is_dir($ordner)) {
                continue;
            }
            try {
                $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ordner, FilesystemIterator::SKIP_DOTS));
                foreach ($it as $datei) {
                    if ($datei->isFile()) {
                        $summe += (int) $datei->getSize();
                    }
                }
            } catch (Throwable $e) {
                // Nicht lesbar – gezählt wird, was lesbar ist.
            }
        }
        return $summe;
    }

    /** @return string[] */
    private static function ordner(int $workspaceId): array
    {
        return [GP_ROOT . '/uploads/w' . $workspaceId, GP_ROOT . '/data/privat/w' . $workspaceId];
    }

    /**
     * Welche Module sind in dieser Instanz tatsächlich eingeschaltet?
     * Dieselbe Regel wie Tenant::modul(), nur für eine fremde Instanz.
     *
     * @return string[]
     */
    public static function moduleAn(int $workspaceId, string $paket): array
    {
        $wert = DB::value('SELECT wert FROM settings WHERE workspace_id = :w AND schluessel = :s',
            ['w' => $workspaceId, 's' => 'module'], null);
        $liste = $wert !== null ? Util::ausJson((string) $wert, []) : Module::standardFuerPlan($paket);
        $an = [];
        foreach (array_keys(Module::alle()) as $key) {
            if (Module::istKern($key) || (in_array($key, $liste, true) && Pakete::erlaubt($key, $paket))) {
                $an[] = $key;
            }
        }
        return $an;
    }

    /**
     * Was das Team zuletzt getan hat – Art und Zeitpunkt, ohne den
     * Beschreibungstext. Der enthält oft Kundennamen, und die gehören
     * nicht in die Betreiberzentrale.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function aktivitaet(int $workspaceId, int $limit = 40): array
    {
        return DB::all('SELECT a.id, a.aktion, a.objekt, a.erstellt, a.betreiber_id, a.user_id, u.name AS person
                        FROM audit_log a LEFT JOIN users u ON u.id = a.user_id AND u.workspace_id = a.workspace_id
                        WHERE a.workspace_id = :w ORDER BY a.id DESC LIMIT ' . max(1, $limit),
            ['w' => $workspaceId]);
    }

    /** @return array<int,array<string,mixed>> */
    public static function notizen(int $workspaceId): array
    {
        return DB::all('SELECT * FROM betreiber_notizen WHERE workspace_id = :w ORDER BY id DESC', ['w' => $workspaceId]);
    }

    public static function notizHinzufuegen(int $workspaceId, string $text): bool
    {
        $text = trim($text);
        if ($text === '' || self::finden($workspaceId) === null) {
            return false;
        }
        $id = DB::insert('betreiber_notizen', [
            'workspace_id' => $workspaceId, 'betreiber_id' => Betreiber::id(), 'akteur' => Betreiber::name(),
            'text' => mb_substr($text, 0, 4000), 'erstellt' => Util::jetzt(),
        ]);
        Betreiberlog::schreiben('NOTE_ADDED', ['objekt' => 'notiz', 'objekt_id' => $id, 'instanz_id' => $workspaceId]);
        return true;
    }

    /* ------------------------------------------------------ Prüfungen --- */

    public static function slugNormal(string $slug): string
    {
        return trim((string) preg_replace('/[^a-z0-9-]+/', '-', strtolower(trim($slug))), '-');
    }

    public static function slugFehler(string $slug, int $ausser = 0): string
    {
        if (!preg_match('/^[a-z0-9](?:[a-z0-9-]{0,62}[a-z0-9])?$/', $slug) || strlen($slug) < 2) {
            return 'Die Kurzadresse braucht 2 bis 64 Zeichen: Kleinbuchstaben, Ziffern und Bindestriche.';
        }
        if (DB::int('SELECT COUNT(*) FROM workspaces WHERE slug = :s AND id != :i', ['s' => $slug, 'i' => $ausser]) > 0) {
            return 'Diese Kurzadresse ist schon vergeben.';
        }
        return '';
    }

    public static function domainNormal(string $domain): string
    {
        $domain = strtolower(trim($domain));
        $domain = (string) preg_replace('#^[a-z]+://#', '', $domain);
        $domain = (string) preg_replace('#[/?\#].*$#', '', $domain);
        return (string) preg_replace('/^www\./', '', $domain);
    }

    public static function domainFehler(string $domain, int $ausser = 0): string
    {
        if ($domain === '') {
            return '';
        }
        if (!preg_match('/^(?=.{4,160}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain)) {
            return 'Die Domain sieht nicht gültig aus (Beispiel: golfschule-muster.de).';
        }
        $eigen = (string) parse_url((string) Config::get('base_url', ''), PHP_URL_HOST);
        if ($eigen !== '' && preg_replace('/^www\./', '', strtolower($eigen)) === $domain) {
            return 'Das ist die Adresse von TeePilot selbst.';
        }
        if (DB::int('SELECT COUNT(*) FROM workspaces WHERE domain = :d AND id != :i', ['d' => $domain, 'i' => $ausser]) > 0) {
            return 'Diese Domain gehört schon zu einer anderen Instanz.';
        }
        return '';
    }

    /** Eine Adresse gehört höchstens einer Person – als Benutzer oder als Betreiber. */
    public static function emailFehler(string $email): string
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 'Bitte eine gültige E-Mail-Adresse angeben.';
        }
        if (DB::int('SELECT COUNT(*) FROM users WHERE email = :e', ['e' => $email]) > 0) {
            return 'Zu dieser Adresse gibt es schon einen Zugang in einer Instanz. Bitte eine andere verwenden.';
        }
        if (DB::int('SELECT COUNT(*) FROM betreiber WHERE email = :e', ['e' => $email]) > 0) {
            return 'Diese Adresse gehört einem Betreiber.';
        }
        return '';
    }

    /* -------------------------------------------------------- Anlegen --- */

    /**
     * Legt eine Instanz vollständig an – oder gar nicht.
     *
     * Workspace, Inhaber, Grundeinstellungen, Standort und Vertrag
     * entstehen in einer Transaktion. Scheitert ein Schritt, bleibt
     * nichts zurück: keine Instanz ohne Inhaber, kein Inhaber ohne
     * Instanz. Die Einladung geht erst nach dem Festschreiben hinaus –
     * sonst bekäme jemand einen Link zu etwas, das es nicht gibt.
     *
     * Der Inhaber bekommt kein Passwort, sondern einen Link, über den er
     * es selbst setzt. Niemand in der Betreiberzentrale kennt es je.
     *
     * @param array<string,mixed> $e
     * @return array{id:int,fehler:array<string,string>,einladung:?bool}
     */
    public static function anlegen(array $e): array
    {
        [$daten, $fehler] = self::pruefenNeu($e);
        if ($fehler !== []) {
            return ['id' => 0, 'fehler' => $fehler, 'einladung' => null];
        }
        if (Betreiber::gebremst('TENANT_CREATED', 20)) {
            return ['id' => 0, 'fehler' => ['_' => 'Zu viele neue Instanzen in kurzer Zeit. Bitte in 15 Minuten erneut versuchen.'],
                    'einladung' => null];
        }

        $token = Util::token(24);
        $vorher = Tenant::id();
        try {
            $id = DB::transaktion(static function () use ($daten, $token) {
                $jetzt = Util::jetzt();
                $wsId = DB::insert('workspaces', [
                    'slug' => $daten['slug'], 'name' => $daten['name'], 'typ' => $daten['typ'],
                    'plan' => $daten['paket'], 'domain' => $daten['domain'],
                    'branding' => Util::json(['primaer' => '#2e6a3e', 'akzent' => '#f2c64b',
                                              'schrift' => 'Archivo', 'radius' => 14, 'stil' => 'modern']),
                    'waehrung' => $daten['waehrung'], 'sprache' => 'de', 'zeitzone' => $daten['zeitzone'],
                    'onboarding_schritt' => 0, 'aktiv' => 1,
                    'status' => $daten['abo']['laufzeit'] === 'test' ? 'test' : 'aktiv',
                    'demo' => 0, 'erstellt' => $jetzt,
                ]);
                Tenant::setzen($wsId);
                DB::insert('users', [
                    'workspace_id' => $wsId,
                    'email'        => $daten['email'],
                    'passwort'     => password_hash(Util::token(24), PASSWORD_DEFAULT),
                    'name'         => $daten['inhaber'],
                    'telefon'      => $daten['telefon'],
                    'rolle'        => 'owner',
                    'farbe'        => '#1d6f4a',
                    'aktiv'        => 1,
                    'reset_token'  => hash('sha256', $token),
                    'reset_bis'    => date('Y-m-d H:i:s', time() + 7 * 86400),
                    'erstellt'     => $jetzt,
                ]);
                Tenant::einstellungSetzen('module', Module::standardFuerPlan($daten['paket']));
                Tenant::einstellungSetzen('mail_absender', $daten['email']);
                Tenant::einstellungSetzen('mail_absender_name', $daten['name']);
                Segments::standardAnlegen();
                if ($daten['club'] !== '') {
                    Tenant::insert('locations', ['name' => $daten['club'], 'typ' => 'club',
                                                 'ort' => $daten['ort'], 'aktiv' => 1]);
                }
                Abos::beginnen($wsId, $daten['paket'], $daten['abo'], Betreiber::name());
                return $wsId;
            });
        } catch (Throwable $ex) {
            Betreiberlog::schreiben('TENANT_CREATED', [
                'ergebnis' => 'fehler', 'nachher' => ['name' => $daten['name'], 'slug' => $daten['slug']],
                'beschreibung' => mb_substr($ex->getMessage(), 0, 300),
            ]);
            return ['id' => 0, 'fehler' => ['_' => 'Die Instanz konnte nicht angelegt werden. Es wurde nichts gespeichert. '
                                                 . 'Bitte noch einmal versuchen; das Protokoll enthält die technische Ursache.'],
                    'einladung' => null];
        } finally {
            Tenant::setzen($vorher);
        }

        $inhaberId = DB::int('SELECT id FROM users WHERE workspace_id = :w AND rolle = :r', ['w' => $id, 'r' => 'owner']);
        Betreiberlog::schreiben('TENANT_CREATED', [
            'objekt' => 'instanz', 'objekt_id' => $id, 'instanz_id' => $id,
            'nachher' => ['name' => $daten['name'], 'slug' => $daten['slug'], 'domain' => $daten['domain'],
                          'paket' => $daten['paket'], 'laufzeit' => $daten['abo']['laufzeit'],
                          'status' => $daten['abo']['laufzeit'] === 'test' ? 'test' : 'aktiv'],
        ]);
        Betreiberlog::schreiben('USER_CREATED', [
            'objekt' => 'benutzer', 'objekt_id' => $inhaberId, 'instanz_id' => $id,
            'nachher' => ['name' => $daten['inhaber'], 'email' => $daten['email'], 'rolle' => 'owner'],
        ]);

        $einladung = null;
        if ($daten['einladen']) {
            $einladung = self::einladungSenden($id, $inhaberId, $token);
        }
        return ['id' => $id, 'fehler' => [], 'einladung' => $einladung];
    }

    /**
     * @param array<string,mixed> $e
     * @return array{0:array<string,mixed>,1:array<string,string>}
     */
    public static function pruefenNeu(array $e): array
    {
        $f = [];
        $name = trim((string) ($e['name'] ?? ''));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 160) {
            $f['name'] = 'Bitte einen Namen mit 2 bis 160 Zeichen angeben.';
        }
        $typ = (string) ($e['typ'] ?? 'pro');
        if (!isset(self::TYPEN[$typ])) {
            $typ = 'pro';
        }
        $waehrung = (string) ($e['waehrung'] ?? 'EUR');
        if (!isset(self::WAEHRUNGEN[$waehrung])) {
            $f['waehrung'] = 'Unbekannte Währung.';
        }
        $zeitzone = (string) ($e['zeitzone'] ?? 'Europe/Berlin');
        if (!in_array($zeitzone, DateTimeZone::listIdentifiers(), true)) {
            $f['zeitzone'] = 'Unbekannte Zeitzone.';
        }
        $inhaber = trim((string) ($e['inhaber'] ?? ''));
        if ($inhaber === '' || mb_strlen($inhaber) > 160) {
            $f['inhaber'] = 'Bitte den Namen des Golfpros angeben.';
        }
        $email = strtolower(trim((string) ($e['email'] ?? '')));
        $emailFehler = self::emailFehler($email);
        if ($emailFehler !== '') {
            $f['email'] = $emailFehler;
        }
        $paket = (string) ($e['paket'] ?? '');
        if (!isset(Pakete::aktive()[$paket])) {
            $f['paket'] = 'Bitte ein Paket wählen.';
        }
        [$abo, $aboFehler] = Abos::pruefen($e, $paket);
        if ($aboFehler !== []) {
            $f['laufzeit'] = implode(' ', $aboFehler);
        }
        $slug = self::slugNormal((string) ($e['slug'] ?? '') !== '' ? (string) $e['slug'] : Util::slug($name, 60));
        $slugFehler = self::slugFehler($slug);
        if ($slugFehler !== '') {
            $f['slug'] = $slugFehler;
        }
        $domain = self::domainNormal((string) ($e['domain'] ?? ''));
        $domainFehler = self::domainFehler($domain);
        if ($domainFehler !== '') {
            $f['domain'] = $domainFehler;
        }
        return [[
            'name' => $name, 'typ' => $typ, 'waehrung' => $waehrung, 'zeitzone' => $zeitzone,
            'club' => mb_substr(trim((string) ($e['club'] ?? '')), 0, 160),
            'ort' => mb_substr(trim((string) ($e['ort'] ?? '')), 0, 120),
            'inhaber' => $inhaber, 'email' => $email,
            'telefon' => mb_substr(trim((string) ($e['telefon'] ?? '')), 0, 64),
            'paket' => $paket, 'abo' => $abo, 'slug' => $slug, 'domain' => $domain,
            'einladen' => !empty($e['einladen']),
        ], $f];
    }

    /* ------------------------------------------------------- Ändern --- */

    /**
     * @param array<string,mixed> $e
     * @return array<string,string> Fehler je Feld; leer = gespeichert
     */
    public static function bearbeiten(int $id, array $e): array
    {
        $alt = self::finden($id);
        if ($alt === null) {
            return ['_' => 'Diese Instanz gibt es nicht mehr.'];
        }
        $f = [];
        $neu = [
            'name'     => trim((string) ($e['name'] ?? '')),
            'typ'      => (string) ($e['typ'] ?? $alt['typ']),
            'waehrung' => (string) ($e['waehrung'] ?? $alt['waehrung']),
            'zeitzone' => (string) ($e['zeitzone'] ?? $alt['zeitzone']),
            'slug'     => self::slugNormal((string) ($e['slug'] ?? '')),
            'domain'   => self::domainNormal((string) ($e['domain'] ?? '')),
        ];
        if (mb_strlen($neu['name']) < 2 || mb_strlen($neu['name']) > 160) {
            $f['name'] = 'Bitte einen Namen mit 2 bis 160 Zeichen angeben.';
        }
        if (!isset(self::TYPEN[$neu['typ']])) {
            $f['typ'] = 'Unbekannter Typ.';
        }
        if (!isset(self::WAEHRUNGEN[$neu['waehrung']])) {
            $f['waehrung'] = 'Unbekannte Währung.';
        }
        if (!in_array($neu['zeitzone'], DateTimeZone::listIdentifiers(), true)) {
            $f['zeitzone'] = 'Unbekannte Zeitzone.';
        }
        if (($m = self::slugFehler($neu['slug'], $id)) !== '') {
            $f['slug'] = $m;
        }
        if (($m = self::domainFehler($neu['domain'], $id)) !== '') {
            $f['domain'] = $m;
        }
        if ($f !== []) {
            return $f;
        }
        $vorher = [];
        $nachher = [];
        foreach ($neu as $k => $v) {
            if ((string) $alt[$k] !== $v) {
                $vorher[$k] = $alt[$k];
                $nachher[$k] = $v;
            }
        }
        if ($nachher === []) {
            return [];
        }
        DB::update('workspaces', $nachher, 'id = :id', ['id' => $id]);
        Betreiberlog::schreiben('TENANT_UPDATED', [
            'objekt' => 'instanz', 'objekt_id' => $id, 'instanz_id' => $id,
            'vorher' => $vorher, 'nachher' => $nachher,
        ]);
        return [];
    }

    /**
     * Setzt den Status. Nur wenn er noch der ist, den der Betreiber auf
     * dem Bildschirm hatte – sonst hätte ein zweiter Betreiber in der
     * Zwischenzeit etwas entschieden, das hier stillschweigend überfahren
     * würde.
     *
     * @return array{0:bool,1:string}
     */
    public static function statusSetzen(int $id, string $neu, string $erwartet, string $grund = ''): array
    {
        if (!in_array($neu, self::ZIELE, true)) {
            return [false, 'Diesen Status gibt es nicht.'];
        }
        $alt = DB::one('SELECT id, name, status FROM workspaces WHERE id = :id', ['id' => $id]);
        if ($alt === null) {
            return [false, 'Diese Instanz gibt es nicht mehr.'];
        }
        if ((string) $alt['status'] === $neu) {
            return [true, 'Die Instanz ist bereits ' . strtolower(self::statusName($neu)) . '.'];
        }
        $geaendert = DB::update('workspaces',
            ['status' => $neu, 'aktiv' => in_array($neu, ['gesperrt', 'archiviert'], true) ? 0 : 1],
            'id = :id AND status = :alt', ['id' => $id, 'alt' => $erwartet]);
        if ($geaendert === 0) {
            return [false, 'Der Status wurde inzwischen geändert (jetzt: ' . self::statusName((string) $alt['status'])
                         . '). Bitte prüfen und erneut entscheiden.'];
        }
        Betreiberlog::schreiben(self::STATUS_AKTION[$neu], [
            'objekt' => 'instanz', 'objekt_id' => $id, 'instanz_id' => $id,
            'vorher' => ['status' => $alt['status']], 'nachher' => ['status' => $neu], 'grund' => $grund,
        ]);
        return [true, '„' . $alt['name'] . '" ist jetzt ' . strtolower(self::statusName($neu)) . '.'];
    }

    /**
     * Stellt das Paket um und – wenn gewünscht – die Vertragsdaten.
     *
     * @param array<string,mixed>|null $abo Formularwerte für Abos::pruefen(), null = Vertrag unverändert
     * @return array{0:bool,1:string}
     */
    public static function paketAendern(int $id, string $paket, ?array $abo, string $grund = ''): array
    {
        $ws = self::finden($id);
        if ($ws === null) {
            return [false, 'Diese Instanz gibt es nicht mehr.'];
        }
        if (!isset(Pakete::aktive()[$paket]) && $paket !== (string) $ws['plan']) {
            return [false, 'Dieses Paket kann nicht vergeben werden.'];
        }
        if (Betreiber::gebremst('PLAN_CHANGED', 30)) {
            return [false, 'Zu viele Paketwechsel in kurzer Zeit. Bitte in 15 Minuten erneut versuchen.'];
        }
        $daten = null;
        if ($abo !== null) {
            [$daten, $fehler] = Abos::pruefen($abo, $paket);
            if ($fehler !== []) {
                return [false, implode(' ', $fehler)];
            }
        }
        if ($paket !== (string) $ws['plan']) {
            Pakete::zuweisen($id, $paket, Betreiber::akteur(), $grund);
        }
        if ($daten !== null) {
            $vorher = Abos::aktuell($id);
            $neuId = Abos::beginnen($id, $paket, $daten, Betreiber::name());
            Betreiberlog::schreiben('SUBSCRIPTION_CHANGED', [
                'objekt' => 'abo', 'objekt_id' => $neuId, 'instanz_id' => $id, 'grund' => $grund,
                'vorher' => $vorher !== null ? ['paket' => $vorher['paket'], 'laufzeit' => $vorher['laufzeit'],
                                                'preis_cent' => (int) $vorher['preis_cent'], 'status' => $vorher['status']] : null,
                'nachher' => ['paket' => $paket, 'laufzeit' => $daten['laufzeit'], 'preis_cent' => $daten['preis_cent'],
                              'status' => $daten['status']],
            ]);
            /* Aus der Testphase in einen Vertrag: Die Instanz ist dann aktiv.
               Umgekehrt nicht – eine laufende Instanz wird nicht nachträglich
               zur Testinstanz, nur weil jemand die Laufzeit so einträgt. */
            if ((string) $ws['status'] === 'test' && $daten['laufzeit'] !== 'test') {
                self::statusSetzen($id, 'aktiv', 'test', 'Vertrag eingetragen');
            }
        }
        return [true, 'Paket und Vertragsdaten gespeichert.'];
    }

    /* ------------------------------------------------------ Benutzer --- */

    /** Neuer Link zum Passwortsetzen, gültig sieben Tage. */
    public static function einladen(int $workspaceId, int $userId): bool
    {
        $u = DB::one('SELECT id FROM users WHERE id = :u AND workspace_id = :w AND aktiv = 1',
            ['u' => $userId, 'w' => $workspaceId]);
        if ($u === null) {
            return false;
        }
        $token = Util::token(24);
        DB::update('users', ['reset_token' => hash('sha256', $token), 'reset_bis' => date('Y-m-d H:i:s', time() + 7 * 86400)],
            'id = :id', ['id' => $userId]);
        return self::einladungSenden($workspaceId, $userId, $token);
    }

    private static function einladungSenden(int $workspaceId, int $userId, string $token): bool
    {
        $u = DB::one('SELECT name, email FROM users WHERE id = :u AND workspace_id = :w', ['u' => $userId, 'w' => $workspaceId]);
        $ws = DB::one('SELECT name FROM workspaces WHERE id = :w', ['w' => $workspaceId]);
        if ($u === null || $ws === null) {
            return false;
        }
        $link = App::absolut('/passwort.php?token=' . $token);
        /* Ohne Mandant verschickt: Die Mail kommt von TeePilot, nicht von
           der Instanz, und sie landet nicht im Kommunikationsverlauf der
           Instanz – dort stünde sonst der Link im Klartext. */
        $vorher = Tenant::id();
        Tenant::setzen(0);
        try {
            $ok = Mail::senden((string) $u['email'], 'Dein Zugang zu TeePilot',
                'Hallo ' . $u['name'] . ",\n\n"
                . 'für „' . $ws['name'] . '" ist bei TeePilot alles vorbereitet. '
                . "Über diesen Link vergibst du dein Passwort:\n\n" . $link . "\n\n"
                . "Der Link gilt sieben Tage. Danach schickt dir der Support gern einen neuen.",
                ['knopf_text' => 'Passwort vergeben', 'knopf_url' => $link, 'protokoll' => false]);
        } finally {
            Tenant::setzen($vorher);
        }
        Betreiberlog::schreiben('USER_INVITED', [
            'objekt' => 'benutzer', 'objekt_id' => $userId, 'instanz_id' => $workspaceId,
            'ergebnis' => $ok ? 'ok' : 'fehler',
            'beschreibung' => $ok ? 'Einladung verschickt' : 'Versand fehlgeschlagen – Mailserver prüfen',
        ]);
        return $ok;
    }

    /** @return array{0:bool,1:string} */
    public static function benutzerAktiv(int $workspaceId, int $userId, bool $aktiv): array
    {
        $u = DB::one('SELECT * FROM users WHERE id = :u AND workspace_id = :w', ['u' => $userId, 'w' => $workspaceId]);
        if ($u === null) {
            return [false, 'Diesen Benutzer gibt es in dieser Instanz nicht.'];
        }
        if (!$aktiv && (string) $u['rolle'] === 'owner'
            && DB::int("SELECT COUNT(*) FROM users WHERE workspace_id = :w AND rolle = 'owner' AND aktiv = 1",
                ['w' => $workspaceId]) <= 1) {
            return [false, 'Das ist der einzige aktive Inhaber. Eine Instanz ohne Inhaber lässt sich nicht mehr verwalten – '
                         . 'sperre stattdessen die Instanz.'];
        }
        DB::update('users', ['aktiv' => $aktiv ? 1 : 0], 'id = :id', ['id' => $userId]);
        Betreiberlog::schreiben($aktiv ? 'USER_UPDATED' : 'USER_DISABLED', [
            'objekt' => 'benutzer', 'objekt_id' => $userId, 'instanz_id' => $workspaceId,
            'vorher' => ['aktiv' => (int) $u['aktiv']], 'nachher' => ['aktiv' => $aktiv ? 1 : 0],
        ]);
        return [true, $aktiv ? 'Zugang wieder aktiv.' : 'Zugang deaktiviert.'];
    }

    /* ------------------------------------------------------- Löschen --- */

    /**
     * Löscht eine Instanz mit allem, was dazugehört. Unwiderruflich.
     *
     * Nur aus dem Archiv heraus, nur mit dem genau abgetippten Namen und
     * höchstens dreimal in fünfzehn Minuten. Gelöscht wird jede Zeile
     * jeder Tabelle mit dieser `workspace_id` – die Tabellen werden
     * gesucht, nicht aufgezählt, damit eine künftige Tabelle nicht
     * vergessen wird. Danach die Ordner mit Uploads und privaten Dateien.
     * Das Betreiberprotokoll bleibt; es hat keine `workspace_id`.
     *
     * @return array{0:bool,1:string}
     */
    public static function loeschen(int $id, string $bestaetigung, string $grund = ''): array
    {
        $ws = DB::one('SELECT id, name, slug, status, plan, demo FROM workspaces WHERE id = :id', ['id' => $id]);
        if ($ws === null) {
            return [false, 'Diese Instanz gibt es nicht mehr.'];
        }
        if ((string) $ws['status'] !== 'archiviert') {
            return [false, 'Gelöscht werden kann nur eine archivierte Instanz. Archiviere sie zuerst.'];
        }
        if (trim($bestaetigung) !== (string) $ws['name']) {
            return [false, 'Der eingegebene Name stimmt nicht mit dem Namen der Instanz überein.'];
        }
        if (Betreiber::gebremst('TENANT_DELETED', 3)) {
            return [false, 'Zu viele Löschungen in kurzer Zeit. Bitte in 15 Minuten erneut versuchen.'];
        }

        $tabellen = self::tabellenMitWorkspace();
        $zaehlung = [];
        DB::transaktion(static function () use ($id, $tabellen, &$zaehlung) {
            foreach ($tabellen as $t) {
                $n = DB::delete($t, 'workspace_id = :w', ['w' => $id]);
                if ($n > 0) {
                    $zaehlung[$t] = $n;
                }
            }
            DB::delete('workspaces', 'id = :id', ['id' => $id]);
        });
        foreach (self::ordner($id) as $ordner) {
            self::ordnerLoeschen($ordner);
        }
        Betreiberlog::schreiben('TENANT_DELETED', [
            'objekt' => 'instanz', 'objekt_id' => $id, 'instanz_id' => $id, 'grund' => $grund,
            'vorher' => ['name' => $ws['name'], 'slug' => $ws['slug'], 'paket' => $ws['plan'],
                         'demo' => (int) $ws['demo'], 'zeilen' => $zaehlung],
        ]);
        return [true, '„' . $ws['name'] . '" wurde endgültig gelöscht.'];
    }

    /** @return string[] */
    public static function tabellenMitWorkspace(): array
    {
        $liste = [];
        if (DB::istSqlite()) {
            foreach (DB::all("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'") as $t) {
                foreach (DB::all('PRAGMA table_info(' . $t['name'] . ')') as $s) {
                    if ((string) $s['name'] === 'workspace_id') {
                        $liste[] = (string) $t['name'];
                        break;
                    }
                }
            }
        } else {
            foreach (DB::all("SELECT TABLE_NAME AS name FROM information_schema.COLUMNS
                              WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'workspace_id'") as $t) {
                $liste[] = (string) $t['name'];
            }
        }
        return array_values(array_filter($liste, static fn ($t) => preg_match('/^[a-z0-9_]+$/', $t) === 1));
    }

    private static function ordnerLoeschen(string $ordner): void
    {
        $echt = realpath($ordner);
        $wurzeln = array_filter([realpath(GP_ROOT . '/uploads'), realpath(GP_ROOT . '/data/privat')]);
        if ($echt === false || !is_dir($echt)) {
            return;
        }
        $erlaubt = false;
        foreach ($wurzeln as $w) {
            if (str_starts_with($echt, $w . DIRECTORY_SEPARATOR) && preg_match('#/w\d+$#', str_replace('\\', '/', $echt))) {
                $erlaubt = true;
            }
        }
        if (!$erlaubt) {
            return;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($echt, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $datei) {
            $datei->isDir() && !$datei->isLink() ? @rmdir($datei->getPathname()) : @unlink($datei->getPathname());
        }
        @rmdir($echt);
    }
}
