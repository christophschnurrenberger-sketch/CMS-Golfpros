<?php
/**
 * Pakete – was eine Instanz einschalten darf und was es kostet.
 *
 * Bis zur Betreiberzentrale standen die vier Tarife als Konstante in
 * `Module::plaene()`, und welches Modul in welchem Tarif steckt, ergab
 * sich aus einer Rangfolge. Jetzt stehen sie in der Tabelle `pakete`,
 * und jedes Paket führt seine Module als Liste. Das ist die eine Stelle,
 * an der die Frage „darf diese Instanz das?" beantwortet wird:
 *
 *     Pakete::erlaubt('video', Tenant::plan())
 *
 * Darauf bauen `Tenant::modul()` (Menü, Seiten) und `Auth::darf('modul.*')`
 * (Zugriff über die Adresszeile) auf. Ein Paketwechsel ändert deshalb
 * sofort, was die Instanz sehen kann – ohne dass eine zweite Liste
 * nachgezogen werden muss.
 *
 * Die Vorgaben unten sind die bisherigen Tarife, unverändert in Name,
 * Preis und Umfang. Sie werden beim Nachrüsten einmalig in die Tabelle
 * geschrieben; danach gehören die Pakete der Betreiberzentrale. Solange
 * die Tabelle leer oder noch nicht angelegt ist, gelten die Vorgaben –
 * eine Anlage mitten im Update sperrt damit niemanden aus.
 *
 * Kommt ein neues Modul dazu, steht es in keinem gespeicherten Paket.
 * Das ist Absicht: Ob es zum Paket gehört, entscheidet der Betreiber.
 */
final class Pakete
{
    /**
     * Die bisherigen Tarife. `team` stammt aus der Grenze, die bis jetzt
     * fest in app/team.php stand; `rang` aus der früheren Rangfolge in
     * Module. Beides wird nur noch zum Befüllen gebraucht.
     */
    private const VORGABE = [
        'starter' => [
            'name' => 'Starter', 'preis' => 2900, 'rang' => 1, 'team' => 1,
            'zeile' => 'Website und Buchung – alles, um online zu starten.',
            'enthalten' => ['Website-Baukasten', 'Online-Buchung', 'Kundenakte', 'Kalender', 'Blog'],
        ],
        'pro' => [
            'name' => 'Pro', 'preis' => 5900, 'rang' => 2, 'team' => 3,
            'zeile' => 'Das volle Geschäft: Verkauf, Rechnungen, Marketing.',
            'enthalten' => ['Alles aus Starter', 'Leads & Pipeline', 'Produkte & Pakete', 'Zahlungen', 'Rechnungen',
                            'Newsletter', 'Trainingspläne', 'Kurse', 'Events'],
        ],
        'business' => [
            'name' => 'Business', 'preis' => 9900, 'rang' => 3, 'team' => 8,
            'zeile' => 'Mit KI, Videoanalyse und vollständiger Auswertung.',
            'enthalten' => ['Alles aus Pro', 'KI-Assistent', 'Videoanalyse', 'Automationen', 'Auswertung',
                            'Community', 'Smart Pricing'],
        ],
        'academy' => [
            'name' => 'Academy', 'preis' => 19900, 'rang' => 4, 'team' => 0,
            'zeile' => 'Für Akademien: mehrere Trainer, Standorte, eigene Marke.',
            'enthalten' => ['Alles aus Business', 'Mehrere Trainer', 'Mehrere Standorte', 'White Label',
                            'Eigene Domain', 'Rollen & Rechte', 'Vorrangiger Support'],
        ],
    ];

    private const RANG_MODUL = ['starter' => 1, 'pro' => 2, 'business' => 3, 'academy' => 4];

    /** @var array<string,array<string,mixed>>|null */
    private static ?array $cache = null;

    /* -------------------------------------------------------- Lesen --- */

    /**
     * Alle Pakete, nach Sortierung. Schlüssel ist `schluessel`.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function alle(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $liste = [];
        try {
            foreach (DB::all('SELECT * FROM pakete ORDER BY sortierung, id') as $z) {
                $z['module']    = array_values(array_filter(Util::ausJson((string) $z['module'], []), 'is_string'));
                $z['enthalten'] = array_values(array_filter(Util::ausJson((string) $z['enthalten'], []), 'is_string'));
                $liste[(string) $z['schluessel']] = $z;
            }
        } catch (Throwable $e) {
            $liste = [];
        }
        if ($liste === []) {
            $liste = self::vorgaben();
        }
        return self::$cache = $liste;
    }

    /** @return array<string,array<string,mixed>> nur die, die neu vergeben werden dürfen */
    public static function aktive(): array
    {
        return array_filter(self::alle(), static fn (array $p): bool => (int) $p['aktiv'] === 1);
    }

    public static function finden(string $schluessel): ?array
    {
        return self::alle()[$schluessel] ?? null;
    }

    public static function nachId(int $id): ?array
    {
        foreach (self::alle() as $p) {
            if ((int) ($p['id'] ?? 0) === $id) {
                return $p;
            }
        }
        return null;
    }

    public static function name(string $schluessel): string
    {
        $p = self::finden($schluessel);
        return $p !== null ? (string) $p['name'] : 'Nicht zugeordnet';
    }

    /** @return string[] Module, die dieses Paket freischaltet (ohne Kern). */
    public static function module(string $schluessel): array
    {
        return (array) (self::finden($schluessel)['module'] ?? []);
    }

    /**
     * Darf eine Instanz mit diesem Paket das Modul einschalten?
     *
     * Kernmodule immer. Schlüssel, die kein Modul sind (Unterpunkte wie
     * „Pakete" im Buchungsbereich), hängen an ihrem Hauptpunkt und werden
     * hier nicht gesperrt. Ein unbekanntes Paket schaltet nichts frei, was
     * über den Kern hinausgeht – eine Instanz ohne gültiges Paket soll
     * nicht mehr bekommen, sondern weniger.
     */
    public static function erlaubt(string $modul, string $schluessel): bool
    {
        if (Module::istKern($modul) || !array_key_exists($modul, Module::alle())) {
            return true;
        }
        return in_array($modul, self::module($schluessel), true);
    }

    /** 0 heißt unbegrenzt. */
    public static function teamGrenze(string $schluessel): int
    {
        return (int) (self::finden($schluessel)['team_grenze'] ?? 1);
    }

    /** Wie viele Instanzen stehen gerade auf diesem Paket? */
    public static function inBenutzung(string $schluessel): int
    {
        return DB::int('SELECT COUNT(*) FROM workspaces WHERE plan = :p', ['p' => $schluessel]);
    }

    public static function leeren(): void
    {
        self::$cache = null;
    }

    /* ---------------------------------------------------- Befüllen --- */

    /** @return array<string,array<string,mixed>> die Vorgaben in Tabellenform */
    private static function vorgaben(): array
    {
        $aus = [];
        $sortierung = 10;
        foreach (self::VORGABE as $schluessel => $v) {
            $module = [];
            foreach (Module::alle() as $key => $info) {
                if (Module::istKern($key)) {
                    continue;
                }
                $noetig = self::RANG_MODUL[(string) ($info['plan'] ?? 'starter')] ?? 1;
                if ($v['rang'] >= $noetig) {
                    $module[] = $key;
                }
            }
            $aus[$schluessel] = [
                'id' => 0, 'schluessel' => $schluessel, 'name' => $v['name'],
                'beschreibung' => $v['zeile'], 'preis_monat_cent' => $v['preis'], 'preis_jahr_cent' => 0,
                'module' => $module, 'enthalten' => $v['enthalten'], 'team_grenze' => $v['team'],
                'aktiv' => 1, 'sortierung' => $sortierung, 'erstellt' => null, 'geaendert' => null,
            ];
            $sortierung += 10;
        }
        return $aus;
    }

    /**
     * Schreibt die Vorgaben in die Tabelle, wenn sie leer ist.
     *
     * Dazu jedes Paket, das eine Instanz trägt, das es aber nicht gibt –
     * sonst stünde diese Instanz nach dem Update ohne Module da. Solch ein
     * Paket wird inaktiv angelegt (niemand soll es neu vergeben) und
     * schaltet frei, was die Instanz bisher hatte.
     */
    public static function grundausstattung(): void
    {
        if (!DB::tabelleExistiert('pakete')) {
            return;
        }
        if (DB::int('SELECT COUNT(*) FROM pakete') === 0) {
            foreach (self::vorgaben() as $p) {
                DB::insert('pakete', [
                    'schluessel' => $p['schluessel'], 'name' => $p['name'], 'beschreibung' => $p['beschreibung'],
                    'preis_monat_cent' => $p['preis_monat_cent'], 'preis_jahr_cent' => 0,
                    'module' => Util::json($p['module']), 'enthalten' => Util::json($p['enthalten']),
                    'team_grenze' => $p['team_grenze'], 'aktiv' => 1, 'sortierung' => $p['sortierung'],
                    'erstellt' => Util::jetzt(), 'geaendert' => Util::jetzt(),
                ]);
            }
        }
        $bekannt = array_column(DB::all('SELECT schluessel FROM pakete'), 'schluessel');
        foreach (DB::all('SELECT DISTINCT plan FROM workspaces') as $z) {
            $plan = (string) $z['plan'];
            if ($plan === '' || in_array($plan, $bekannt, true)) {
                continue;
            }
            DB::insert('pakete', [
                'schluessel' => $plan, 'name' => ucfirst($plan),
                'beschreibung' => 'Beim Update aus einem unbekannten Tarif übernommen.',
                'preis_monat_cent' => 0, 'preis_jahr_cent' => 0,
                'module' => Util::json(array_values(array_filter(array_keys(Module::alle()),
                    static fn ($k) => !Module::istKern($k)))),
                'enthalten' => Util::json([]), 'team_grenze' => 0, 'aktiv' => 0, 'sortierung' => 900,
                'erstellt' => Util::jetzt(), 'geaendert' => Util::jetzt(),
            ]);
            $bekannt[] = $plan;
        }
        self::leeren();
    }

    /* ---------------------------------------------------- Schreiben --- */

    /**
     * Legt ein Paket an oder ändert es. Nur aus der Betreiberzentrale.
     *
     * Der Schlüssel eines neuen Pakets wird vergeben, nicht aus dem Namen
     * gebildet: Wer „Pro" in „Pro 2026" umbenennt, soll nicht jede
     * Instanz auf dem alten Paket verwaisen lassen.
     *
     * @param array<string,mixed> $eingabe
     * @return array{0:int,1:string[]} [id, Fehler]
     */
    public static function speichern(array $eingabe, int $id = 0): array
    {
        $fehler = [];
        $name = trim((string) ($eingabe['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 80) {
            $fehler[] = 'Das Paket braucht einen Namen (höchstens 80 Zeichen).';
        }
        $monat = (int) ($eingabe['preis_monat_cent'] ?? 0);
        $jahr  = (int) ($eingabe['preis_jahr_cent'] ?? 0);
        if ($monat < 0 || $jahr < 0) {
            $fehler[] = 'Preise können nicht negativ sein.';
        }
        $module = [];
        foreach ((array) ($eingabe['module'] ?? []) as $m) {
            $m = (string) $m;
            if (array_key_exists($m, Module::alle()) && !Module::istKern($m)) {
                $module[] = $m;
            }
        }
        $enthalten = array_values(array_filter(array_map('trim',
            preg_split('/\r?\n/', (string) ($eingabe['enthalten'] ?? '')) ?: []), static fn ($z) => $z !== ''));
        $team = max(0, (int) ($eingabe['team_grenze'] ?? 0));

        $vorher = $id > 0 ? self::nachId($id) : null;
        if ($id > 0 && $vorher === null) {
            $fehler[] = 'Dieses Paket gibt es nicht mehr.';
        }
        $aktiv = !empty($eingabe['aktiv']) ? 1 : 0;
        if ($vorher !== null && $aktiv === 0 && (string) $vorher['schluessel'] === (string) Plattform::einstellung('standard_paket')) {
            $fehler[] = 'Das Standardpaket kann nicht deaktiviert werden. Wähle erst in den Einstellungen ein anderes.';
        }
        if ($fehler !== []) {
            return [0, $fehler];
        }

        $daten = [
            'name' => $name,
            'beschreibung' => mb_substr(trim((string) ($eingabe['beschreibung'] ?? '')), 0, 500),
            'preis_monat_cent' => $monat,
            'preis_jahr_cent' => $jahr,
            'module' => Util::json(array_values(array_unique($module))),
            'enthalten' => Util::json($enthalten),
            'team_grenze' => $team,
            'aktiv' => $aktiv,
            'sortierung' => (int) ($eingabe['sortierung'] ?? ($vorher['sortierung'] ?? 100)),
            'geaendert' => Util::jetzt(),
        ];

        if ($vorher !== null) {
            DB::update('pakete', $daten, 'id = :id', ['id' => $id]);
            self::leeren();
            Betreiberlog::schreiben('PACKAGE_UPDATED', [
                'objekt' => 'paket', 'objekt_id' => $id,
                'vorher' => self::fuersProtokoll($vorher), 'nachher' => self::fuersProtokoll(self::nachId($id) ?? []),
            ]);
            return [$id, []];
        }

        $daten['schluessel'] = 'p' . substr(Util::token(6), 0, 10);
        $daten['erstellt'] = Util::jetzt();
        $neu = DB::insert('pakete', $daten);
        self::leeren();
        Betreiberlog::schreiben('PACKAGE_CREATED', [
            'objekt' => 'paket', 'objekt_id' => $neu, 'nachher' => self::fuersProtokoll(self::nachId($neu) ?? []),
        ]);
        return [$neu, []];
    }

    /** @return array<string,mixed> */
    private static function fuersProtokoll(array $p): array
    {
        return [
            'schluessel' => $p['schluessel'] ?? '', 'name' => $p['name'] ?? '',
            'preis_monat_cent' => (int) ($p['preis_monat_cent'] ?? 0), 'preis_jahr_cent' => (int) ($p['preis_jahr_cent'] ?? 0),
            'module' => $p['module'] ?? [], 'team_grenze' => (int) ($p['team_grenze'] ?? 0), 'aktiv' => (int) ($p['aktiv'] ?? 0),
        ];
    }

    /**
     * Stellt eine Instanz auf ein anderes Paket.
     *
     * Die einzige Stelle, an der `workspaces.plan` geändert wird – ob aus
     * der Betreiberzentrale oder vom Inhaber auf der Tarifseite. Beim
     * Wechsel nach unten werden Module abgeschaltet, die das neue Paket
     * nicht enthält; die Daten dahinter bleiben vollständig erhalten, ein
     * Wechsel zurück macht alles wieder sichtbar.
     *
     * @param array{art:string,id:int,name:string} $wer  art: betreiber|benutzer
     */
    public static function zuweisen(int $workspaceId, string $neu, array $wer, string $grund = ''): bool
    {
        $paket = self::finden($neu);
        $ws = DB::one('SELECT id, plan FROM workspaces WHERE id = :id', ['id' => $workspaceId]);
        if ($paket === null || $ws === null) {
            return false;
        }
        $alt = (string) $ws['plan'];
        if ($alt === $neu) {
            return true;
        }

        DB::transaktion(static function () use ($workspaceId, $neu, $alt) {
            DB::update('workspaces', ['plan' => $neu], 'id = :id', ['id' => $workspaceId]);
            $satz = DB::one('SELECT id, wert FROM settings WHERE workspace_id = :w AND schluessel = :s',
                ['w' => $workspaceId, 's' => 'module']);
            if ($satz !== null) {
                $liste = Util::ausJson((string) $satz['wert'], []);
                $gefiltert = array_values(array_filter($liste,
                    static fn ($k) => is_string($k) && (Module::istKern($k) || self::erlaubt($k, $neu))));
                DB::update('settings', ['wert' => Util::json($gefiltert)], 'id = :id', ['id' => (int) $satz['id']]);
            }
        });
        if (Tenant::id() === $workspaceId) {
            Tenant::setzen($workspaceId);      // Zwischenspeicher der Instanz verwerfen
        }

        $von = $wer['art'] === 'betreiber' ? $wer['name'] : $wer['name'] . ' (Instanz)';
        Betreiberlog::schreiben('PLAN_CHANGED', [
            'objekt' => 'instanz', 'objekt_id' => $workspaceId, 'instanz_id' => $workspaceId,
            'vorher' => ['paket' => $alt, 'name' => self::name($alt)],
            'nachher' => ['paket' => $neu, 'name' => self::name($neu)],
            'grund' => $grund,
            'betreiber_id' => $wer['art'] === 'betreiber' ? $wer['id'] : 0,
            'akteur' => $von,
            'beschreibung' => $wer['art'] === 'betreiber' ? '' : 'Selbst gewechselt auf der Tarifseite',
        ]);
        return true;
    }
}
