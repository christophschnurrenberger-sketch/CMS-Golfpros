<?php
/**
 * Tenant – die Mandantengrenze.
 *
 * Jede fachliche Tabelle trägt `workspace_id`. Statt in sechzig Abfragen
 * daran zu denken, läuft jeder Datenzugriff durch diese Klasse: Sie hängt die
 * Einschränkung an, und zwar immer. Ein vergessener Filter kann deshalb nicht
 * entstehen – die Stelle, an der man ihn vergessen könnte, gibt es nicht.
 *
 *     Tenant::all('customers', 'status = :s', ['s' => 'aktiv'], 'nachname')
 *     Tenant::find('bookings', 42)        // null, wenn die Buchung fremd ist
 *     Tenant::insert('leads', [...])      // workspace_id wird gesetzt
 *
 * `find()` liefert für fremde Datensätze null statt eines Fehlers. Das ist
 * Absicht: Wer eine fremde ID errät, soll nicht einmal erfahren, dass es den
 * Datensatz gibt.
 */
final class Tenant
{
    private static int $id = 0;
    private static ?array $workspace = null;
    private static array $einstellungen = [];

    /* ------------------------------------------------------- Umschalten */

    public static function setzen(int $workspaceId): void
    {
        self::$id            = $workspaceId;
        self::$workspace     = null;
        self::$einstellungen = [];
    }

    public static function id(): int
    {
        return self::$id;
    }

    public static function gesetzt(): bool
    {
        return self::$id > 0;
    }

    /** @return array<string,mixed>|null */
    public static function workspace(): ?array
    {
        if (self::$workspace === null && self::$id > 0) {
            $zeile = DB::one('SELECT * FROM workspaces WHERE id = :id', ['id' => self::$id]);
            self::$workspace = $zeile ?: null;
        }
        return self::$workspace;
    }

    public static function name(): string
    {
        return (string) (self::workspace()['name'] ?? Marke::NAME);
    }

    public static function plan(): string
    {
        return (string) (self::workspace()['plan'] ?? 'starter');
    }

    public static function waehrung(): string
    {
        $code = (string) (self::workspace()['waehrung'] ?? 'EUR');
        return ['EUR' => '€', 'CHF' => 'CHF', 'GBP' => '£', 'USD' => '$'][$code] ?? $code;
    }

    /** @return array<string,mixed> */
    public static function branding(): array
    {
        /*
         * Die Rückfallwerte sind die des Entwurfs, nicht irgendein Grün:
         * Tannengrün auf warmem Papier, mit gelbem Textmarker als Akzent.
         * Ein Workspace, der nichts einstellt, sieht damit richtig aus.
         */
        $standard = [
            'primaer'   => '#2e6a3e',
            'akzent'    => '#f2c64b',
            'schrift'   => 'Archivo',
            'radius'    => 14,
            'stil'      => 'modern',
            'dunkel'    => false,
        ];
        return Util::ausJson((string) (self::workspace()['branding'] ?? ''), []) + $standard;
    }

    public static function aktualisieren(array $daten): void
    {
        if (self::$id <= 0) {
            return;
        }
        DB::update('workspaces', $daten, 'id = :id', ['id' => self::$id]);
        self::$workspace = null;
    }

    /* ------------------------------------------ Auflösen über Kennungen */

    public static function nachSlug(string $slug): ?array
    {
        return DB::one('SELECT * FROM workspaces WHERE slug = :s AND aktiv = 1', ['s' => $slug]);
    }

    public static function nachDomain(string $host): ?array
    {
        $host = strtolower(preg_replace('/^www\./', '', $host) ?? $host);
        if ($host === '') {
            return null;
        }
        return DB::one('SELECT * FROM workspaces WHERE domain = :d AND aktiv = 1', ['d' => $host]);
    }

    public static function erster(): ?array
    {
        return DB::one('SELECT * FROM workspaces WHERE aktiv = 1 ORDER BY id LIMIT 1');
    }

    /* ------------------------------------------------- Einstellungen ---- */

    public static function einstellung(string $schluessel, $standard = null)
    {
        if (self::$einstellungen === []) {
            foreach (DB::all('SELECT schluessel, wert FROM settings WHERE workspace_id = :w', ['w' => self::$id]) as $z) {
                self::$einstellungen[$z['schluessel']] = $z['wert'];
            }
        }
        if (!array_key_exists($schluessel, self::$einstellungen)) {
            return $standard;
        }
        $wert = self::$einstellungen[$schluessel];
        $daten = json_decode((string) $wert, true);
        return json_last_error() === JSON_ERROR_NONE ? $daten : $wert;
    }

    public static function einstellungSetzen(string $schluessel, $wert): void
    {
        $text = is_array($wert) || is_bool($wert) ? Util::json($wert) : (string) $wert;
        $da = DB::int(
            'SELECT COUNT(*) FROM settings WHERE workspace_id = :w AND schluessel = :s',
            ['w' => self::$id, 's' => $schluessel]
        );
        if ($da > 0) {
            DB::update('settings', ['wert' => $text], 'workspace_id = :w AND schluessel = :s',
                ['w' => self::$id, 's' => $schluessel]);
        } else {
            DB::insert('settings', ['workspace_id' => self::$id, 'schluessel' => $schluessel, 'wert' => $text]);
        }
        self::$einstellungen[$schluessel] = $text;
    }

    /** Ist ein Modul für diesen Workspace eingeschaltet? */
    public static function modul(string $name): bool
    {
        $module = self::einstellung('module', null);
        if (!is_array($module)) {
            $module = Module::standardFuerPlan(self::plan());
        }
        return in_array($name, $module, true);
    }

    /* -------------------------------------------------- Datenzugriff --- */

    private static function wo(string $bedingung = ''): string
    {
        $wo = 'workspace_id = ' . (int) self::$id;
        return $bedingung !== '' ? $wo . ' AND (' . $bedingung . ')' : $wo;
    }

    public static function all(
        string $tabelle,
        string $bedingung = '',
        array $parameter = [],
        string $sortierung = '',
        int $limit = 0,
        int $versatz = 0,
        string $spalten = '*'
    ): array {
        $sql = 'SELECT ' . $spalten . ' FROM ' . $tabelle . ' WHERE ' . self::wo($bedingung);
        if ($sortierung !== '') {
            $sql .= ' ORDER BY ' . $sortierung;
        }
        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit . ($versatz > 0 ? ' OFFSET ' . $versatz : '');
        }
        return DB::all($sql, $parameter);
    }

    public static function one(string $tabelle, string $bedingung = '', array $parameter = [], string $sortierung = ''): ?array
    {
        $sql = 'SELECT * FROM ' . $tabelle . ' WHERE ' . self::wo($bedingung);
        if ($sortierung !== '') {
            $sql .= ' ORDER BY ' . $sortierung;
        }
        return DB::one($sql . ' LIMIT 1', $parameter);
    }

    public static function find(string $tabelle, int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        return DB::one(
            'SELECT * FROM ' . $tabelle . ' WHERE ' . self::wo('id = :pk') . ' LIMIT 1',
            ['pk' => $id]
        );
    }

    public static function count(string $tabelle, string $bedingung = '', array $parameter = []): int
    {
        return DB::int('SELECT COUNT(*) FROM ' . $tabelle . ' WHERE ' . self::wo($bedingung), $parameter);
    }

    public static function sum(string $tabelle, string $spalte, string $bedingung = '', array $parameter = []): int
    {
        return (int) DB::value(
            'SELECT COALESCE(SUM(' . $spalte . '), 0) FROM ' . $tabelle . ' WHERE ' . self::wo($bedingung),
            $parameter,
            0
        );
    }

    public static function insert(string $tabelle, array $daten): int
    {
        $daten['workspace_id'] = self::$id;
        if (!array_key_exists('erstellt', $daten) && self::hatSpalte($tabelle, 'erstellt')) {
            $daten['erstellt'] = Util::jetzt();
        }
        return DB::insert($tabelle, self::gestempelt($tabelle, $daten));
    }

    public static function update(string $tabelle, int $id, array $daten): int
    {
        unset($daten['workspace_id'], $daten['id']);
        if ($daten === []) {
            return 0;
        }
        return DB::update($tabelle, self::gestempelt($tabelle, $daten), self::wo('id = :pk'), ['pk' => $id]);
    }

    public static function updateWhere(string $tabelle, array $daten, string $bedingung, array $parameter = []): int
    {
        unset($daten['workspace_id'], $daten['id']);
        return $daten === []
            ? 0
            : DB::update($tabelle, self::gestempelt($tabelle, $daten), self::wo($bedingung), $parameter);
    }

    /**
     * Felder, deren Änderung den Datensatz nach außen nicht verändert.
     *
     * Der Gesundheitswert wird alle 15 Minuten für 25 Kunden neu gerechnet,
     * und ein Zugangslink oder ein Aktivitätsstempel sagt über die Person
     * nichts aus. Würden diese Schreibvorgänge den Änderungszeitpunkt
     * mitnehmen, meldete die Schnittstelle bei jedem Abgleich dieselben
     * Leute erneut – und „nur das Neue holen" wäre wertlos.
     */
    private const OHNE_STEMPEL = [
        'health_score', 'letzte_aktivitaet', 'portal_token', 'portal_token_bis',
        /* Welche Seite die Startseite ist, ändert an keiner Seite etwas –
           und das Umhängen setzt die Marke bei allen anderen zurück. Ohne
           diese Ausnahme stünde danach überall „geändert: gerade eben". */
        'startseite',
    ];

    /**
     * Setzt `geaendert`, wo die Tabelle die Spalte führt.
     *
     * Zentral und nicht an den dreißig Stellen, die einen Kunden ändern:
     * Eine davon wird vergessen, und dann fehlt genau dieser Kunde im
     * nächsten Abgleich mit dem Newslettersystem. Ein ausdrücklich
     * mitgegebener Wert bleibt stehen – einer Datenübernahme muss es
     * freistehen, einen alten Zeitpunkt zu schreiben.
     *
     * @param array<string,mixed> $daten
     * @return array<string,mixed>
     */
    private static function gestempelt(string $tabelle, array $daten): array
    {
        if (array_key_exists('geaendert', $daten)) {
            return $daten;
        }
        if (array_diff(array_keys($daten), self::OHNE_STEMPEL) === []) {
            return $daten;
        }
        if (self::hatSpalte($tabelle, 'geaendert')) {
            $daten['geaendert'] = Util::jetzt();
        }
        return $daten;
    }

    public static function delete(string $tabelle, int $id): int
    {
        return DB::delete($tabelle, self::wo('id = :pk'), ['pk' => $id]);
    }

    public static function deleteWhere(string $tabelle, string $bedingung, array $parameter = []): int
    {
        return DB::delete($tabelle, self::wo($bedingung), $parameter);
    }

    /** @return array<int,array<string,mixed>> nach id gebündelt */
    public static function nachId(string $tabelle, string $bedingung = '', array $parameter = []): array
    {
        $karte = [];
        foreach (self::all($tabelle, $bedingung, $parameter) as $zeile) {
            $karte[(int) $zeile['id']] = $zeile;
        }
        return $karte;
    }

    private static array $spaltenCache = [];

    private static function hatSpalte(string $tabelle, string $spalte): bool
    {
        if (!isset(self::$spaltenCache[$tabelle])) {
            $namen = [];
            try {
                if (DB::istSqlite()) {
                    foreach (DB::all('PRAGMA table_info(' . $tabelle . ')') as $z) {
                        $namen[] = (string) $z['name'];
                    }
                } else {
                    foreach (DB::all('SHOW COLUMNS FROM ' . $tabelle) as $z) {
                        $namen[] = (string) ($z['Field'] ?? '');
                    }
                }
            } catch (Throwable $e) {
                $namen = [];
            }
            self::$spaltenCache[$tabelle] = $namen;
        }
        return in_array($spalte, self::$spaltenCache[$tabelle], true);
    }
}
