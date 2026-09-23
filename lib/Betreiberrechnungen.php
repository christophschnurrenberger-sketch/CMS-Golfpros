<?php
/**
 * Betreiberrechnungen – Rechnungen der Betreiberzentrale an die Instanzen.
 *
 * Der Ablauf ist der einer ordentlichen Buchhaltung:
 *
 *   Entwurf      frei änderbar, ohne Nummer, darf gelöscht werden
 *   Ausstellen   fortlaufende Nummer, Absender und Empfänger werden als
 *                Kopie eingefroren, das PDF wird einmal erzeugt und abgelegt
 *   danach       unveränderlich (GoBD) – nur noch versenden, als bezahlt
 *                markieren oder stornieren. Eine Stornorechnung ist eine
 *                eigene Rechnung mit eigener Nummer und negativen Beträgen.
 *
 * Die Unveränderlichkeit steht zweimal: Diese Klasse bietet keinen Weg,
 * eine ausgestellte Rechnung zu ändern, und die Datenbank weist es über
 * Auslöser ab (Schema::rechnungsschutz). Ausgeliefert wird immer die beim
 * Ausstellen abgelegte Datei – geprüft gegen ihren Abdruck –, nie ein
 * neu erzeugtes PDF. Sonst könnte eine spätere Änderung am Layout eine
 * alte Rechnung verändern.
 *
 * Rechnungen haben keine workspace_id. Wird eine Instanz gelöscht, bleiben
 * ihre Rechnungen stehen: Aufbewahrungspflicht zehn Jahre.
 *
 * Steuer: Pro Rechnung ein Steuerfall. Regelbesteuerung mit Sätzen je
 * Position; Kleinunternehmer (§ 19 UStG); Reverse Charge für Unternehmen
 * im EU-Ausland mit USt-IdNr.; nicht steuerbar für das Drittland (etwa die
 * Schweiz). Die Umsatzsteuer wird je Steuersatz auf die Nettosumme
 * gerechnet und kaufmännisch gerundet. Welcher Fall zutrifft, bleibt eine
 * Frage an die Steuerberatung – die Vorauswahl ist ein Vorschlag.
 */
final class Betreiberrechnungen
{
    /** Status => [Bezeichnung, Farbe der Pille] */
    public const STATUS = [
        'entwurf'   => ['Entwurf', 'offen'],
        'offen'     => ['Offen', 'info'],
        'bezahlt'   => ['Bezahlt', 'erfolg'],
        'storniert' => ['Storniert', 'offen'],
        'storno'    => ['Stornorechnung', 'warnung'],
    ];

    public const STEUERFALL = [
        'regel'     => 'Umsatzsteuer (Regelbesteuerung)',
        'klein'     => 'Kleinunternehmer (§ 19 UStG)',
        'rc'        => 'Reverse Charge (Unternehmen im EU-Ausland)',
        'drittland' => 'Nicht im Inland steuerbar (Drittland)',
    ];

    private const HINWEIS = [
        'regel'     => '',
        'klein'     => 'Gemäß § 19 UStG wird keine Umsatzsteuer berechnet.',
        'rc'        => 'Steuerschuldnerschaft des Leistungsempfängers (Reverse Charge). '
                     . 'Die Umsatzsteuer schuldet der Leistungsempfänger.',
        'drittland' => 'Nicht im Inland steuerbare Leistung (§ 3a Abs. 2 UStG).',
    ];

    public const EU = ['AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR', 'GR', 'HR', 'HU', 'IE',
                       'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK'];

    public const LAENDER = [
        'DE' => 'Deutschland', 'AT' => 'Österreich', 'CH' => 'Schweiz', 'LI' => 'Liechtenstein',
        'LU' => 'Luxemburg', 'NL' => 'Niederlande', 'BE' => 'Belgien', 'FR' => 'Frankreich',
        'IT' => 'Italien', 'ES' => 'Spanien', 'PT' => 'Portugal', 'DK' => 'Dänemark', 'SE' => 'Schweden',
        'PL' => 'Polen', 'CZ' => 'Tschechien', 'IE' => 'Irland', 'GB' => 'Vereinigtes Königreich',
    ];

    public const EINHEITEN = ['Monat', 'Jahr', 'Stück', 'Stunde', 'pauschal'];

    /* ================================================== Empfänger === */

    /**
     * Die Rechnungsanschrift einer Instanz – gespeichert oder, beim ersten
     * Mal, vorbelegt aus dem, was die Instanz schon weiß.
     *
     * @return array<string,string>
     */
    public static function rechnungsdaten(int $workspaceId): array
    {
        $z = DB::one('SELECT * FROM rechnungsdaten WHERE workspace_id = :w', ['w' => $workspaceId]);
        if ($z !== null) {
            return ['firma' => (string) $z['firma'], 'name' => (string) $z['name'], 'strasse' => (string) $z['strasse'],
                    'plz' => (string) $z['plz'], 'ort' => (string) $z['ort'], 'land' => (string) $z['land'],
                    'email' => (string) $z['email'], 'ust_id' => (string) $z['ust_id'], 'gespeichert' => '1'];
        }
        $ws = Instanzen::finden($workspaceId);
        $ort = DB::one("SELECT strasse, plz, ort, land FROM locations WHERE workspace_id = :w AND aktiv = 1
                        ORDER BY CASE WHEN typ = 'club' THEN 0 ELSE 1 END, id LIMIT 1", ['w' => $workspaceId]);
        return [
            'firma' => (string) ($ws['name'] ?? ''), 'name' => (string) ($ws['inhaber'] ?? ''),
            'strasse' => (string) ($ort['strasse'] ?? ''), 'plz' => (string) ($ort['plz'] ?? ''),
            'ort' => (string) ($ort['ort'] ?? ''), 'land' => strtoupper((string) ($ort['land'] ?? 'DE')) ?: 'DE',
            'email' => (string) ($ws['inhaber_email'] ?? ''), 'ust_id' => '', 'gespeichert' => '',
        ];
    }

    /**
     * Die Anschrift, die die Instanz unter „Konto & Abrechnung" sieht –
     * und woher sie stammt.
     *
     * Gespeichert gilt vor allem anderen: Auf sie lauten die nächsten
     * Rechnungen. Fehlt sie, aber es gibt schon eine Rechnung, dann steht
     * dort, was auf der letzten stand – der Betreiber kann den Empfänger
     * im Entwurf auch direkt eintragen, und die Instanz soll nicht einen
     * Vorschlag sehen, der mit ihren Rechnungen nichts zu tun hat. Erst
     * ganz ohne beides der Vorschlag aus den Angaben der Instanz.
     *
     * @return array{0:array<string,string>,1:string,2:string} Anschrift, Quelle (gespeichert|rechnung|vorschlag), Rechnungsnummer
     */
    public static function anschriftFuerInstanz(int $workspaceId): array
    {
        $d = self::rechnungsdaten($workspaceId);
        if ($d['gespeichert'] === '1') {
            return [$d, 'gespeichert', ''];
        }
        $letzte = DB::one("SELECT nummer, empfaenger FROM betreiber_rechnungen
                           WHERE instanz_id = :w AND status != 'entwurf' ORDER BY datum DESC, id DESC LIMIT 1", ['w' => $workspaceId]);
        if ($letzte !== null) {
            [$aus] = self::empfaengerAus(Util::ausJson((string) $letzte['empfaenger'], []));
            return [$aus, 'rechnung', (string) $letzte['nummer']];
        }
        return [$d, 'vorschlag', ''];
    }

    /**
     * @param array<string,mixed> $e
     * @return array{0:array<string,string>,1:string[]}
     */
    public static function empfaengerAus(array $e): array
    {
        $d = [
            'firma'   => mb_substr(trim((string) ($e['firma'] ?? '')), 0, 190),
            'name'    => mb_substr(trim((string) ($e['name'] ?? '')), 0, 160),
            'strasse' => mb_substr(trim((string) ($e['strasse'] ?? '')), 0, 160),
            'plz'     => mb_substr(trim((string) ($e['plz'] ?? '')), 0, 16),
            'ort'     => mb_substr(trim((string) ($e['ort'] ?? '')), 0, 120),
            'land'    => strtoupper(substr(trim((string) ($e['land'] ?? 'DE')), 0, 2)) ?: 'DE',
            'email'   => strtolower(trim((string) ($e['email'] ?? ''))),
            'ust_id'  => strtoupper(str_replace(' ', '', trim((string) ($e['ust_id'] ?? '')))),
        ];
        $fehler = [];
        if (!preg_match('/^[A-Z]{2}$/', $d['land'])) {
            $fehler[] = 'Das Land braucht einen zweistelligen Ländercode (DE, AT, CH …).';
        }
        if ($d['email'] !== '' && !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) {
            $fehler[] = 'Die E-Mail-Adresse für Rechnungen sieht nicht gültig aus.';
        }
        if ($d['ust_id'] !== '' && !preg_match('/^[A-Z]{2}[A-Z0-9]{2,13}$/', $d['ust_id'])) {
            $fehler[] = 'Die USt-IdNr. beginnt mit dem Ländercode, z. B. ATU12345678.';
        }
        return [$d, $fehler];
    }

    /** @return string[] was für eine gültige Rechnung am Empfänger fehlt */
    public static function empfaengerLuecken(array $d): array
    {
        $fehlt = [];
        if (trim(($d['firma'] ?? '') . ($d['name'] ?? '')) === '') {
            $fehlt[] = 'Name oder Firma des Empfängers';
        }
        foreach (['strasse' => 'Straße', 'plz' => 'PLZ', 'ort' => 'Ort'] as $k => $n) {
            if (trim((string) ($d[$k] ?? '')) === '') {
                $fehlt[] = $n . ' des Empfängers';
            }
        }
        return $fehlt;
    }

    /** @return string[] */
    public static function rechnungsdatenSpeichern(int $workspaceId, array $e): array
    {
        [$d, $fehler] = self::empfaengerAus($e);
        if ($fehler !== []) {
            return $fehler;
        }
        $vorher = DB::one('SELECT * FROM rechnungsdaten WHERE workspace_id = :w', ['w' => $workspaceId]);
        $daten = $d + ['geaendert' => Util::jetzt()];
        if ($vorher !== null) {
            DB::update('rechnungsdaten', $daten, 'workspace_id = :w', ['w' => $workspaceId]);
        } else {
            DB::insert('rechnungsdaten', $daten + ['workspace_id' => $workspaceId]);
        }
        Betreiberlog::schreiben('BILLING_DATA_CHANGED', [
            'objekt' => 'rechnungsdaten', 'instanz_id' => $workspaceId,
            'vorher' => $vorher !== null ? array_intersect_key($vorher, $d) : null, 'nachher' => $d,
        ]);
        return [];
    }

    /* ======================================================= Steuer === */

    public static function steuerfallVorschlag(array $empfaenger): string
    {
        if ((int) Plattform::einstellung('rg_kleinunternehmer') === 1) {
            return 'klein';
        }
        $eigen = strtoupper((string) Plattform::einstellung('rg_land')) ?: 'DE';
        $land = strtoupper((string) ($empfaenger['land'] ?? 'DE'));
        if ($land === $eigen) {
            return 'regel';
        }
        if (in_array($land, self::EU, true)) {
            return (string) ($empfaenger['ust_id'] ?? '') !== '' ? 'rc' : 'regel';
        }
        return 'drittland';
    }

    public static function hinweis(string $steuerfall): string
    {
        return self::HINWEIS[$steuerfall] ?? '';
    }

    /**
     * Positionen normalisieren und Summen rechnen.
     *
     * @param array<int,array<string,mixed>> $positionen  text, menge_hundertstel, einheit, einzel_cent, steuersatz
     * @return array{positionen:array<int,array<string,mixed>>,netto:int,steuer:int,brutto:int,steuern:array<int,array{netto:int,steuer:int}>}
     */
    public static function berechnen(array $positionen, string $steuerfall): array
    {
        $aus = [];
        $jeSatz = [];
        foreach (array_values($positionen) as $i => $p) {
            $menge = (int) ($p['menge_hundertstel'] ?? 100);
            $einzel = (int) ($p['einzel_cent'] ?? 0);
            $satz = $steuerfall === 'regel' ? max(0, min(30, (int) ($p['steuersatz'] ?? 0))) : 0;
            $netto = (int) round($menge * $einzel / 100);
            $aus[] = [
                'pos' => $i + 1, 'text' => (string) ($p['text'] ?? ''), 'menge_hundertstel' => $menge,
                'einheit' => (string) ($p['einheit'] ?? ''), 'einzel_cent' => $einzel, 'steuersatz' => $satz,
                'netto_cent' => $netto,
            ];
            $jeSatz[$satz] = ($jeSatz[$satz] ?? 0) + $netto;
        }
        ksort($jeSatz);
        $steuern = [];
        $netto = 0;
        $steuer = 0;
        foreach ($jeSatz as $satz => $summe) {
            $betrag = (int) round($summe * $satz / 100);
            $steuern[$satz] = ['netto' => $summe, 'steuer' => $betrag];
            $netto += $summe;
            $steuer += $betrag;
        }
        return ['positionen' => $aus, 'netto' => $netto, 'steuer' => $steuer, 'brutto' => $netto + $steuer, 'steuern' => $steuern];
    }

    /* ======================================================== Lesen === */

    public static function finden(int $id): ?array
    {
        return $id > 0 ? DB::one('SELECT * FROM betreiber_rechnungen WHERE id = :id', ['id' => $id]) : null;
    }

    /** @return array<int,array<string,mixed>> */
    public static function positionen(int $id): array
    {
        return DB::all('SELECT * FROM betreiber_rechnungspositionen WHERE rechnung_id = :r ORDER BY pos, id', ['r' => $id]);
    }

    public static function statusPille(string $status, ?string $faellig = null): string
    {
        [$text, $art] = self::STATUS[$status] ?? ['Unbekannt', 'offen'];
        if ($status === 'offen' && $faellig && $faellig < date('Y-m-d 00:00:00')) {
            [$text, $art] = ['Überfällig', 'gefahr'];
        }
        return '<span class="pille pille--' . $art . ' pille--punkt">' . Util::h($text) . '</span>';
    }

    /**
     * @param array{status?:string,instanz?:int,jahr?:int,q?:string} $f
     * @return array{0:array<int,array<string,mixed>>,1:int}
     */
    public static function liste(array $f, int $seite = 1, int $pro = 50): array
    {
        $wo = [];
        $p = [];
        $status = (string) ($f['status'] ?? '');
        if ($status === 'ueberfaellig') {
            $wo[] = "r.status = 'offen' AND r.faellig < :heute";
            $p['heute'] = date('Y-m-d 00:00:00');
        } elseif (isset(self::STATUS[$status])) {
            $wo[] = 'r.status = :status';
            $p['status'] = $status;
        }
        if ((int) ($f['instanz'] ?? 0) > 0) {
            $wo[] = 'r.instanz_id = :instanz';
            $p['instanz'] = (int) $f['instanz'];
        }
        if ((int) ($f['jahr'] ?? 0) > 2000) {
            $wo[] = "(r.datum >= :jv AND r.datum < :jb OR (r.status = 'entwurf' AND r.erstellt >= :jv AND r.erstellt < :jb))";
            $p['jv'] = (int) $f['jahr'] . '-01-01 00:00:00';
            $p['jb'] = ((int) $f['jahr'] + 1) . '-01-01 00:00:00';
        }
        if (trim((string) ($f['q'] ?? '')) !== '') {
            $wo[] = "(r.nummer LIKE :q ESCAPE '!' OR r.empfaenger LIKE :q ESCAPE '!' OR w.name LIKE :q ESCAPE '!')";
            $p['q'] = '%' . strtr(trim((string) $f['q']), ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        }
        $bedingung = $wo === [] ? '' : ' WHERE ' . implode(' AND ', $wo);
        $von = ' FROM betreiber_rechnungen r LEFT JOIN workspaces w ON w.id = r.instanz_id';
        $gesamt = DB::int('SELECT COUNT(*)' . $von . $bedingung, $p);
        $zeilen = DB::all('SELECT r.*, w.name AS instanz' . $von . $bedingung
            . " ORDER BY CASE WHEN r.status = 'entwurf' THEN 0 ELSE 1 END, r.datum DESC, r.id DESC LIMIT "
            . max(1, $pro) . ' OFFSET ' . max(0, ($seite - 1) * $pro), $p);
        return [$zeilen, $gesamt];
    }

    /** @return array<string,int> */
    public static function kennzahlen(): array
    {
        $heute = date('Y-m-d 00:00:00');
        $monat = date('Y-m-01 00:00:00');
        $jahr = date('Y-01-01 00:00:00');
        $ausgestellt = "status IN ('offen', 'bezahlt', 'storniert', 'storno')";
        return [
            'offen_anzahl'   => DB::int("SELECT COUNT(*) FROM betreiber_rechnungen WHERE status = 'offen'"),
            'offen_summe'    => DB::int("SELECT COALESCE(SUM(brutto_cent), 0) FROM betreiber_rechnungen WHERE status = 'offen'"),
            'ueber_anzahl'   => DB::int("SELECT COUNT(*) FROM betreiber_rechnungen WHERE status = 'offen' AND faellig < :h", ['h' => $heute]),
            'ueber_summe'    => DB::int("SELECT COALESCE(SUM(brutto_cent), 0) FROM betreiber_rechnungen WHERE status = 'offen' AND faellig < :h", ['h' => $heute]),
            'bezahlt_monat'  => DB::int("SELECT COALESCE(SUM(brutto_cent), 0) FROM betreiber_rechnungen WHERE status = 'bezahlt' AND bezahlt >= :m", ['m' => $monat]),
            'umsatz_monat'   => DB::int("SELECT COALESCE(SUM(netto_cent), 0) FROM betreiber_rechnungen WHERE $ausgestellt AND datum >= :m", ['m' => $monat]),
            'umsatz_jahr'    => DB::int("SELECT COALESCE(SUM(netto_cent), 0) FROM betreiber_rechnungen WHERE $ausgestellt AND datum >= :j", ['j' => $jahr]),
            'entwuerfe'      => DB::int("SELECT COUNT(*) FROM betreiber_rechnungen WHERE status = 'entwurf'"),
        ];
    }

    /**
     * Ausgestellte Rechnungen einer Instanz – für die Instanz selbst.
     * Entwürfe und interne Notizen gehören nicht dazu.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function fuerInstanz(int $workspaceId): array
    {
        return DB::all("SELECT id, nummer, art, bezug_id, status, datum, leistung_von, leistung_bis, faellig, brutto_cent, bezahlt, versendet
                        FROM betreiber_rechnungen WHERE instanz_id = :w AND status != 'entwurf'
                        ORDER BY datum DESC, id DESC", ['w' => $workspaceId]);
    }

    /**
     * Was die Instanz TeePilot noch schuldet: offene Rechnungen, die
     * älteste Fälligkeit zuerst. Für den Hinweis auf dem Dashboard.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function offenFuerInstanz(int $workspaceId): array
    {
        return DB::all("SELECT id, nummer, datum, faellig, brutto_cent FROM betreiber_rechnungen
                        WHERE instanz_id = :w AND status = 'offen' ORDER BY faellig, id", ['w' => $workspaceId]);
    }

    /** Anzahl offener Rechnungen – für die Zahl im Menü, auf jeder Seite. */
    public static function anzahlOffenFuerInstanz(int $workspaceId): int
    {
        return DB::int("SELECT COUNT(*) FROM betreiber_rechnungen WHERE instanz_id = :w AND status = 'offen'",
            ['w' => $workspaceId]);
    }

    /* ====================================================== Entwurf === */

    /**
     * Positionen aus einem Formular (Listen text[], menge[], einheit[], einzel[], steuersatz[]).
     *
     * @return array<int,array<string,mixed>>
     */
    public static function positionenAusFormular(array $post): array
    {
        $aus = [];
        $texte = (array) ($post['text'] ?? []);
        foreach ($texte as $i => $text) {
            $text = trim((string) $text);
            $einzel = Util::centAus((string) ($post['einzel'][$i] ?? ''));
            if ($text === '' && $einzel === 0) {
                continue;
            }
            $menge = (int) round(Util::zahlAus((string) ($post['menge'][$i] ?? '1'), 1.0) * 100);
            $aus[] = [
                'text' => mb_substr($text, 0, 500), 'menge_hundertstel' => $menge,
                'einheit' => mb_substr(trim((string) ($post['einheit'][$i] ?? '')), 0, 24),
                'einzel_cent' => $einzel, 'steuersatz' => (int) ($post['steuersatz'][$i] ?? 19),
            ];
        }
        return $aus;
    }

    /**
     * Legt einen Entwurf an oder ändert ihn. Ausgestellte Rechnungen
     * werden hier nie angefasst.
     *
     * @param array<string,mixed> $kopf  steuerfall, datum, leistung_von, leistung_bis, text_oben, notiz, notiz_intern, abo_id, empfaenger
     * @param array<int,array<string,mixed>> $positionen
     * @return array{0:int,1:string[]}
     */
    public static function entwurfSpeichern(int $id, int $workspaceId, array $kopf, array $positionen): array
    {
        $fehler = [];
        $alt = $id > 0 ? self::finden($id) : null;
        if ($id > 0 && ($alt === null || (string) $alt['status'] !== 'entwurf')) {
            return [0, ['Nur Entwürfe lassen sich ändern. Eine ausgestellte Rechnung wird storniert, nicht korrigiert.']];
        }
        if ($alt !== null) {
            $workspaceId = (int) $alt['instanz_id'];
        }
        if (DB::int('SELECT COUNT(*) FROM workspaces WHERE id = :w', ['w' => $workspaceId]) === 0 && $alt === null) {
            return [0, ['Diese Instanz gibt es nicht.']];
        }
        [$empfaenger, $empFehler] = self::empfaengerAus((array) ($kopf['empfaenger'] ?? []));
        $fehler = array_merge($fehler, $empFehler);
        $steuerfall = isset(self::STEUERFALL[(string) ($kopf['steuerfall'] ?? '')]) ? (string) $kopf['steuerfall']
                    : self::steuerfallVorschlag($empfaenger);
        $datum = static fn ($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d . ' 00:00:00' : null;
        foreach ($positionen as $p) {
            if ((int) $p['menge_hundertstel'] <= 0 && ($alt['art'] ?? 'rechnung') !== 'storno') {
                $fehler[] = 'Jede Position braucht eine Menge größer als null.';
                break;
            }
        }
        if ($fehler !== []) {
            return [0, $fehler];
        }
        $summen = self::berechnen($positionen, $steuerfall);
        $daten = [
            'steuerfall'   => $steuerfall,
            'datum'        => $datum($kopf['datum'] ?? null),
            'leistung_von' => $datum($kopf['leistung_von'] ?? null),
            'leistung_bis' => $datum($kopf['leistung_bis'] ?? null),
            'empfaenger'   => Util::json($empfaenger),
            'netto_cent'   => $summen['netto'],
            'steuer_cent'  => $summen['steuer'],
            'brutto_cent'  => $summen['brutto'],
            'steuern'      => Util::json($summen['steuern']),
            'text_oben'    => mb_substr(trim((string) ($kopf['text_oben'] ?? '')), 0, 2000),
            'notiz'        => mb_substr(trim((string) ($kopf['notiz'] ?? '')), 0, 2000),
            'notiz_intern' => mb_substr(trim((string) ($kopf['notiz_intern'] ?? '')), 0, 2000),
        ];

        $neuId = DB::transaktion(static function () use ($id, $alt, $workspaceId, $daten, $summen, $kopf) {
            if ($alt !== null) {
                DB::update('betreiber_rechnungen', $daten, "id = :id AND status = 'entwurf'", ['id' => $id]);
                DB::delete('betreiber_rechnungspositionen', 'rechnung_id = :r', ['r' => $id]);
            } else {
                $id = DB::insert('betreiber_rechnungen', $daten + [
                    'instanz_id' => $workspaceId, 'art' => (string) ($kopf['art'] ?? 'rechnung'),
                    'bezug_id' => (int) ($kopf['bezug_id'] ?? 0), 'status' => 'entwurf',
                    'abo_id' => (int) ($kopf['abo_id'] ?? 0), 'erstellt' => Util::jetzt(),
                    'erstellt_von' => mb_substr(Betreiber::name() ?: (string) ($kopf['von'] ?? ''), 0, 190),
                ]);
            }
            foreach ($summen['positionen'] as $p) {
                DB::insert('betreiber_rechnungspositionen', $p + ['rechnung_id' => $id]);
            }
            return $id;
        });
        Betreiberlog::schreiben($alt === null ? 'INVOICE_CREATED' : 'INVOICE_UPDATED', [
            'objekt' => 'rechnung', 'objekt_id' => $neuId, 'instanz_id' => $workspaceId,
            'nachher' => ['netto_cent' => $summen['netto'], 'brutto_cent' => $summen['brutto'],
                          'positionen' => count($summen['positionen']), 'steuerfall' => $steuerfall],
        ]);
        return [$neuId, []];
    }

    /**
     * Was eine Rechnung aus dem laufenden Vertrag enthalten würde.
     *
     * @return array{kopf:array<string,mixed>,positionen:array<int,array<string,mixed>>,hinweis:string}
     */
    public static function vorschlagAusVertrag(int $workspaceId, string $monat = ''): array
    {
        $monat = preg_match('/^\d{4}-\d{2}$/', $monat) ? $monat : date('Y-m');
        $start = $monat . '-01';
        $ws = Instanzen::finden($workspaceId);
        $abo = Abos::aktuell($workspaceId);
        $paketKey = (string) ($abo['paket'] ?? ($ws['plan'] ?? ''));
        $paket = Pakete::finden($paketKey);
        $name = 'TeePilot ' . ($paket['name'] ?? 'Paket');
        $satz = (int) Plattform::einstellung('rg_steuersatz');
        $empfaenger = self::rechnungsdaten($workspaceId);
        $hinweis = '';

        $laufzeit = (string) ($abo['laufzeit'] ?? '');
        if ($laufzeit === 'jahr') {
            $von = $start;
            $bis = date('Y-m-d', strtotime($start . ' +1 year -1 day'));
            $pos = ['text' => $name . ' – Jahreslizenz ' . Util::datum($von) . ' bis ' . Util::datum($bis),
                    'menge_hundertstel' => 100, 'einheit' => 'Jahr', 'einzel_cent' => (int) $abo['preis_cent'], 'steuersatz' => $satz];
        } else {
            $von = $start;
            $bis = date('Y-m-t', strtotime($start));
            $preis = in_array($laufzeit, ['monat', 'individuell'], true) ? (int) $abo['preis_cent'] : (int) ($paket['preis_monat_cent'] ?? 0);
            $pos = ['text' => $name . ' – ' . Util::monatName((int) date('n', strtotime($start))) . ' ' . date('Y', strtotime($start)),
                    'menge_hundertstel' => 100, 'einheit' => 'Monat', 'einzel_cent' => $preis, 'steuersatz' => $satz];
            if ($abo === null || $laufzeit === 'test') {
                $hinweis = $abo === null
                    ? 'Für diese Instanz sind keine Vertragsdaten erfasst – vorgeschlagen ist der Listenpreis des Pakets.'
                    : 'Die Instanz ist in der Testphase – vorgeschlagen ist der Listenpreis des Pakets.';
            }
        }
        unset($empfaenger['gespeichert']);
        return [
            'kopf' => ['steuerfall' => self::steuerfallVorschlag($empfaenger), 'leistung_von' => $von, 'leistung_bis' => $bis,
                       'empfaenger' => $empfaenger, 'abo_id' => (int) ($abo['id'] ?? 0),
                       'text_oben' => (string) Plattform::einstellung('rg_text')],
            'positionen' => [$pos],
            'hinweis' => $hinweis,
        ];
    }

    /**
     * Entwürfe für einen Monat aus allen laufenden Verträgen.
     *
     * Nur Entwürfe – ausgestellt wird erst nach dem Durchsehen. Übersprungen
     * wird, wer für diesen Zeitraum schon eine Rechnung oder einen Entwurf
     * aus demselben Vertrag hat, und wer keinen Betrag hätte.
     *
     * @return array{angelegt:int,uebersprungen:array<int,string>}
     */
    public static function monatsentwuerfe(string $monat): array
    {
        if (!preg_match('/^\d{4}-(\d{2})$/', $monat, $m)) {
            return ['angelegt' => 0, 'uebersprungen' => ['Ungültiger Monat.']];
        }
        $start = $monat . '-01 00:00:00';
        $ende = date('Y-m-t 23:59:59', strtotime($monat . '-01'));
        $angelegt = 0;
        $uebersprungen = [];
        $abos = DB::all("SELECT a.*, w.name AS instanz FROM abos a JOIN workspaces w ON w.id = a.workspace_id
                         WHERE w.demo = 0 AND w.status != 'archiviert' AND a.status IN ('aktiv', 'gekuendigt')
                         AND a.id = (SELECT MAX(a2.id) FROM abos a2 WHERE a2.workspace_id = a.workspace_id AND a2.status != 'beendet')
                         ORDER BY w.name");
        foreach ($abos as $abo) {
            $name = (string) $abo['instanz'];
            if ((int) $abo['preis_cent'] <= 0) {
                $uebersprungen[] = $name . ' (ohne Betrag)';
                continue;
            }
            if ((string) $abo['laufzeit'] === 'jahr' && date('m', strtotime((string) $abo['beginn'])) !== $m[1]) {
                continue;       // Jahresvertrag: nur im Monat des Vertragsbeginns
            }
            if ((string) $abo['status'] === 'gekuendigt' && (string) $abo['ende'] !== '' && (string) $abo['ende'] < $start) {
                $uebersprungen[] = $name . ' (Vertrag beendet)';
                continue;
            }
            $schon = DB::int("SELECT COUNT(*) FROM betreiber_rechnungen WHERE instanz_id = :w AND abo_id = :a
                              AND status NOT IN ('storniert', 'storno') AND leistung_von >= :s AND leistung_von <= :e",
                ['w' => (int) $abo['workspace_id'], 'a' => (int) $abo['id'], 's' => $start, 'e' => $ende]);
            if ($schon > 0) {
                $uebersprungen[] = $name . ' (schon vorhanden)';
                continue;
            }
            $v = self::vorschlagAusVertrag((int) $abo['workspace_id'], $monat);
            $kopf = $v['kopf'];
            $kopf['leistung_von'] = (string) $kopf['leistung_von'];
            [$id] = self::entwurfSpeichern(0, (int) $abo['workspace_id'], $kopf, $v['positionen']);
            if ($id > 0) {
                $angelegt++;
            }
        }
        return ['angelegt' => $angelegt, 'uebersprungen' => $uebersprungen];
    }

    public static function entwurfLoeschen(int $id): bool
    {
        $r = self::finden($id);
        if ($r === null || (string) $r['status'] !== 'entwurf') {
            return false;
        }
        DB::transaktion(static function () use ($id) {
            DB::delete('betreiber_rechnungspositionen', 'rechnung_id = :r', ['r' => $id]);
            DB::delete('betreiber_rechnungen', "id = :id AND status = 'entwurf'", ['id' => $id]);
        });
        Betreiberlog::schreiben('INVOICE_DELETED', ['objekt' => 'rechnung', 'objekt_id' => $id,
            'instanz_id' => (int) $r['instanz_id'], 'beschreibung' => 'Entwurf gelöscht']);
        return true;
    }

    /* ==================================================== Absender === */

    /** @return array<string,string> */
    public static function absender(): array
    {
        $a = [];
        foreach (['firma', 'inhaber', 'strasse', 'plz', 'ort', 'land', 'email', 'telefon', 'web', 'ust_id',
                  'steuernummer', 'bank'] as $k) {
            $a[$k] = trim((string) Plattform::einstellung('rg_' . $k));
        }
        return $a;
    }

    /** @return string[] */
    public static function absenderLuecken(): array
    {
        $a = self::absender();
        $fehlt = [];
        foreach (['firma' => 'Firma', 'strasse' => 'Straße', 'plz' => 'PLZ', 'ort' => 'Ort'] as $k => $n) {
            if ($a[$k] === '') {
                $fehlt[] = $n;
            }
        }
        if ($a['ust_id'] === '' && $a['steuernummer'] === '') {
            $fehlt[] = 'Steuernummer oder USt-IdNr.';
        }
        return $fehlt;
    }

    /* ==================================================== Ausstellen === */

    public static function ablage(): string
    {
        $pfad = (string) Config::get('rechnungsablage', '');
        return rtrim($pfad !== '' ? $pfad : GP_ROOT . '/data/privat/betreiber/rechnungen', '/');
    }

    private static function naechsteNummer(string $datum): string
    {
        $praefix = preg_replace('/[^A-Z0-9]/', '', strtoupper((string) Plattform::einstellung('rg_praefix'))) ?: 'TP';
        $praefix = substr($praefix, 0, 8);
        $jahr = substr($datum, 0, 4);
        $hoechste = 0;
        foreach (DB::all('SELECT nummer FROM betreiber_rechnungen WHERE nummer LIKE :m', ['m' => $praefix . '-' . $jahr . '-%']) as $z) {
            $hoechste = max($hoechste, (int) substr((string) $z['nummer'], strrpos((string) $z['nummer'], '-') + 1));
        }
        return $praefix . '-' . $jahr . '-' . str_pad((string) ($hoechste + 1), 4, '0', STR_PAD_LEFT);
    }

    /**
     * Stellt einen Entwurf aus: Nummer, Datum, Fälligkeit, Absender, PDF.
     * Alles in einer Transaktion – scheitert das Ablegen des PDFs, bleibt
     * der Entwurf ein Entwurf und die Nummer wird nicht verbraucht.
     *
     * @return array{0:bool,1:string}
     */
    public static function ausstellen(int $id): array
    {
        $r = self::finden($id);
        if ($r === null || (string) $r['status'] !== 'entwurf') {
            return [false, 'Nur ein Entwurf kann ausgestellt werden.'];
        }
        $positionen = self::positionen($id);
        if ($positionen === []) {
            return [false, 'Die Rechnung hat keine Positionen.'];
        }
        $luecken = self::absenderLuecken();
        if ($luecken !== []) {
            return [false, 'Im Rechnungsabsender fehlt noch: ' . implode(', ', $luecken) . '. Ergänze es unter Einstellungen.'];
        }
        $empfaenger = Util::ausJson((string) $r['empfaenger'], []);
        $luecken = self::empfaengerLuecken($empfaenger);
        if ($luecken !== []) {
            return [false, 'Es fehlt noch: ' . implode(', ', $luecken) . '.'];
        }
        if ((string) $r['steuerfall'] === 'rc' && (($empfaenger['ust_id'] ?? '') === ''
            || !in_array((string) ($empfaenger['land'] ?? ''), self::EU, true)
            || (string) ($empfaenger['land'] ?? '') === strtoupper((string) Plattform::einstellung('rg_land')))) {
            return [false, 'Reverse Charge setzt einen Empfänger im EU-Ausland mit USt-IdNr. voraus.'];
        }
        if ((string) $r['art'] !== 'storno' && (int) $r['brutto_cent'] <= 0) {
            return [false, 'Der Rechnungsbetrag muss größer als null sein.'];
        }
        if (Betreiber::id() > 0 && Betreiber::gebremst('INVOICE_ISSUED', 60)) {
            return [false, 'Zu viele Rechnungen in kurzer Zeit. Bitte in 15 Minuten erneut versuchen.'];
        }

        $datum = $r['datum'] ? substr((string) $r['datum'], 0, 10) : date('Y-m-d');
        $dateiVoll = '';
        try {
            $fertig = DB::transaktion(static function () use ($r, $id, $positionen, $datum, &$dateiVoll) {
                $nummer = self::naechsteNummer($datum);
                $fertig = array_merge($r, [
                    'nummer'       => $nummer,
                    'status'       => (string) $r['art'] === 'storno' ? 'storno' : 'offen',
                    'datum'        => $datum . ' 00:00:00',
                    'leistung_von' => $r['leistung_von'] ?: $datum . ' 00:00:00',
                    'faellig'      => date('Y-m-d 00:00:00', strtotime($datum . ' +' . (int) Plattform::einstellung('rg_zahlungsziel') . ' days')),
                    'absender'     => Util::json(self::absender()),
                    'ausgestellt'  => Util::jetzt(),
                ]);
                $inhalt = self::pdf($fertig, $positionen)->ausgabe();
                $relativ = substr($datum, 0, 4) . '/' . $nummer . '.pdf';
                $dateiVoll = self::ablage() . '/' . $relativ;
                if (!is_dir(dirname($dateiVoll)) && !@mkdir(dirname($dateiVoll), 0750, true) && !is_dir(dirname($dateiVoll))) {
                    throw new RuntimeException('Der Ablageordner für Rechnungen lässt sich nicht anlegen.');
                }
                if (is_file($dateiVoll) || @file_put_contents($dateiVoll, $inhalt, LOCK_EX) === false) {
                    $dateiVoll = '';
                    throw new RuntimeException('Das PDF lässt sich nicht ablegen.');
                }
                $geaendert = DB::update('betreiber_rechnungen', [
                    'nummer' => $nummer, 'status' => $fertig['status'], 'datum' => $fertig['datum'],
                    'leistung_von' => $fertig['leistung_von'], 'faellig' => $fertig['faellig'],
                    'absender' => $fertig['absender'], 'ausgestellt' => $fertig['ausgestellt'],
                    'datei' => $relativ, 'datei_hash' => hash('sha256', $inhalt),
                ], "id = :id AND status = 'entwurf'", ['id' => $id]);
                if ($geaendert !== 1) {
                    throw new RuntimeException('Die Rechnung wurde inzwischen verändert.');
                }
                if ((string) $r['art'] === 'storno' && (int) $r['bezug_id'] > 0) {
                    DB::update('betreiber_rechnungen', ['status' => 'storniert'],
                        "id = :b AND status IN ('offen', 'bezahlt')", ['b' => (int) $r['bezug_id']]);
                }
                return $fertig;
            });
        } catch (Throwable $e) {
            if ($dateiVoll !== '' && is_file($dateiVoll)) {
                @unlink($dateiVoll);
            }
            Betreiberlog::schreiben('INVOICE_ISSUED', ['objekt' => 'rechnung', 'objekt_id' => $id,
                'instanz_id' => (int) $r['instanz_id'], 'ergebnis' => 'fehler', 'beschreibung' => mb_substr($e->getMessage(), 0, 300)]);
            return [false, 'Nicht ausgestellt: ' . $e->getMessage()];
        }
        Betreiberlog::schreiben('INVOICE_ISSUED', ['objekt' => 'rechnung', 'objekt_id' => $id, 'instanz_id' => (int) $r['instanz_id'],
            'nachher' => ['nummer' => $fertig['nummer'], 'brutto_cent' => (int) $r['brutto_cent'], 'art' => $r['art']]]);
        self::instanzBenachrichtigen($fertig);
        return [true, ((string) $r['art'] === 'storno' ? 'Stornorechnung ' : 'Rechnung ') . $fertig['nummer']
            . ' ist ausgestellt. Die Instanz sieht sie ab jetzt unter „Konto & Abrechnung".'];
    }

    /**
     * Sagt der Instanz Bescheid, dass eine Rechnung für sie da ist.
     *
     * Eine Meldung an der Glocke, sichtbar für alle dort, die die
     * Abrechnung sehen dürfen (`Notify` filtert beim Lesen). Die E-Mail
     * bleibt ein eigener Schritt – ausgestellt ist nicht versendet, aber
     * sehen kann die Instanz die Rechnung ab jetzt, also soll sie es auch
     * erfahren.
     *
     * Nie auf Kosten der Rechnung: Sie ist in diesem Moment schon
     * ausgestellt und unveränderlich. Geht die Meldung schief, steht das
     * im Fehlerprotokoll des Servers, und die Rechnung bleibt, wie sie ist.
     *
     * @param array<string,mixed> $r die ausgestellte Rechnung
     */
    private static function instanzBenachrichtigen(array $r): void
    {
        try {
            $ws   = (int) $r['instanz_id'];
            $link = '/app/konto.php#r' . (int) $r['id'];
            if ((string) $r['art'] === 'storno') {
                $original = (int) $r['bezug_id'] > 0 ? self::finden((int) $r['bezug_id']) : null;
                Notify::anInstanz($ws, 'teepilot', 'Rechnung storniert',
                    'Die Rechnung ' . (string) ($original['nummer'] ?? '') . ' ist storniert. Dazu liegt die Stornorechnung '
                    . $r['nummer'] . ' über ' . Util::geld((int) $r['brutto_cent']) . ' für dich bereit.', $link);
                return;
            }
            Notify::anInstanz($ws, 'teepilot', 'Neue Rechnung von ' . Marke::NAME,
                $r['nummer'] . ' über ' . Util::geld((int) $r['brutto_cent']) . ' · fällig am '
                . Util::datum((string) $r['faellig']) . '.', $link);
        } catch (Throwable $e) {
            error_log('TeePilot: Meldung zur Rechnung ' . (string) ($r['nummer'] ?? '') . ' nicht geschrieben – ' . $e->getMessage());
        }
    }

    /**
     * Storniert eine ausgestellte Rechnung mit einer Stornorechnung.
     *
     * @return array{0:bool,1:string,2:int}
     */
    public static function stornieren(int $id, string $grund): array
    {
        $r = self::finden($id);
        if ($r === null || (string) $r['art'] !== 'rechnung' || !in_array((string) $r['status'], ['offen', 'bezahlt'], true)) {
            return [false, 'Stornieren lässt sich nur eine ausgestellte, noch nicht stornierte Rechnung.', 0];
        }
        if (trim($grund) === '') {
            return [false, 'Bitte einen Grund angeben – er steht auf der Stornorechnung.', 0];
        }
        $positionen = array_map(static fn (array $p) => [
            'text' => (string) $p['text'], 'menge_hundertstel' => (int) $p['menge_hundertstel'],
            'einheit' => (string) $p['einheit'], 'einzel_cent' => -(int) $p['einzel_cent'], 'steuersatz' => (int) $p['steuersatz'],
        ], self::positionen($id));
        $kopf = [
            'art' => 'storno', 'bezug_id' => $id, 'steuerfall' => (string) $r['steuerfall'],
            'datum' => date('Y-m-d'),
            'leistung_von' => $r['leistung_von'] ? substr((string) $r['leistung_von'], 0, 10) : null,
            'leistung_bis' => $r['leistung_bis'] ? substr((string) $r['leistung_bis'], 0, 10) : null,
            'empfaenger' => Util::ausJson((string) $r['empfaenger'], []),
            'text_oben' => 'Stornorechnung zur Rechnung ' . $r['nummer'] . ' vom ' . Util::datum((string) $r['datum']) . '.',
            'notiz' => 'Grund: ' . mb_substr(trim($grund), 0, 500), 'abo_id' => (int) $r['abo_id'],
        ];
        [$stornoId, $fehler] = self::entwurfSpeichern(0, (int) $r['instanz_id'], $kopf, $positionen);
        if ($stornoId === 0) {
            return [false, implode(' ', $fehler), 0];
        }
        [$ok, $meldung] = self::ausstellen($stornoId);
        if (!$ok) {
            self::entwurfLoeschen($stornoId);
            return [false, $meldung, 0];
        }
        Betreiberlog::schreiben('INVOICE_CANCELLED', ['objekt' => 'rechnung', 'objekt_id' => $id,
            'instanz_id' => (int) $r['instanz_id'], 'grund' => $grund,
            'vorher' => ['status' => $r['status']], 'nachher' => ['status' => 'storniert', 'storno_id' => $stornoId]]);
        return [true, 'Rechnung ' . $r['nummer'] . ' ist storniert. ' . $meldung, $stornoId];
    }

    /** @return array{0:bool,1:string} */
    public static function bezahltSetzen(int $id, bool $bezahlt, string $datum = ''): array
    {
        $r = self::finden($id);
        if ($r === null || (string) $r['art'] !== 'rechnung') {
            return [false, 'Diese Rechnung gibt es nicht.'];
        }
        if ($bezahlt && (string) $r['status'] !== 'offen') {
            return [false, 'Als bezahlt markieren lässt sich nur eine offene Rechnung.'];
        }
        if (!$bezahlt && (string) $r['status'] !== 'bezahlt') {
            return [false, 'Die Rechnung ist nicht als bezahlt markiert.'];
        }
        $tag = preg_match('/^\d{4}-\d{2}-\d{2}$/', $datum) ? $datum : date('Y-m-d');
        DB::update('betreiber_rechnungen', $bezahlt ? ['status' => 'bezahlt', 'bezahlt' => $tag . ' 00:00:00']
                                                    : ['status' => 'offen', 'bezahlt' => null], 'id = :id', ['id' => $id]);
        Betreiberlog::schreiben($bezahlt ? 'INVOICE_PAID' : 'INVOICE_UPDATED', ['objekt' => 'rechnung', 'objekt_id' => $id,
            'instanz_id' => (int) $r['instanz_id'], 'vorher' => ['status' => $r['status']],
            'nachher' => ['status' => $bezahlt ? 'bezahlt' : 'offen'] + ($bezahlt ? ['bezahlt' => $tag] : [])]);
        return [true, $bezahlt ? 'Zahlungseingang vermerkt.' : 'Wieder auf offen gesetzt.'];
    }

    /** @return array{0:bool,1:string} */
    public static function senden(int $id): array
    {
        $r = self::finden($id);
        if ($r === null || (string) $r['status'] === 'entwurf') {
            return [false, 'Versenden lässt sich nur eine ausgestellte Rechnung.'];
        }
        $empfaenger = Util::ausJson((string) $r['empfaenger'], []);
        $an = (string) ($empfaenger['email'] ?? '');
        if (!filter_var($an, FILTER_VALIDATE_EMAIL)) {
            return [false, 'Am Empfänger steht keine E-Mail-Adresse. Die Rechnung hat sie beim Ausstellen übernommen – '
                         . 'für diese Rechnung bleibt nur der Download.'];
        }
        $inhalt = self::dateiInhalt($r);
        if ($inhalt === null) {
            return [false, 'Die abgelegte Datei fehlt oder wurde verändert. Bitte im Audit-Log nachsehen.'];
        }
        $absender = Util::ausJson((string) $r['absender'], []);
        $storno = (string) $r['art'] === 'storno';
        $anrede = trim((string) ($empfaenger['name'] ?? '')) !== '' ? 'Hallo ' . $empfaenger['name'] : 'Guten Tag';
        $text = $anrede . ",\n\n"
            . ($storno ? 'anbei die Stornorechnung ' . $r['nummer'] . '.'
                       : 'anbei die Rechnung ' . $r['nummer'] . ' über ' . Util::geld((int) $r['brutto_cent'])
                         . ($r['leistung_von'] ? ' für ' . self::zeitraum($r) : '') . '.'
                         . ((string) $r['status'] === 'offen' ? "\nBitte überweise den Betrag bis zum " . Util::datum((string) $r['faellig'])
                            . ' unter Angabe der Rechnungsnummer.' : ''))
            . "\n\nDeine Rechnungen findest du jederzeit auch in TeePilot unter Einstellungen → Konto & Abrechnung.\n\n"
            . "Viele Grüße\n" . ((string) ($absender['firma'] ?? '') ?: Marke::NAME);
        $vorher = Tenant::id();
        Tenant::setzen(0);
        try {
            $ok = Mail::senden($an, ($storno ? 'Stornorechnung ' : 'Rechnung ') . $r['nummer'], $text, [
                'von_name' => (string) ($absender['firma'] ?? ''),
                'antwort' => filter_var((string) ($absender['email'] ?? ''), FILTER_VALIDATE_EMAIL) ? (string) $absender['email'] : null,
                'anhaenge' => [['name' => $r['nummer'] . '.pdf', 'typ' => 'application/pdf', 'inhalt' => $inhalt]],
                'protokoll' => false,
            ]);
        } finally {
            Tenant::setzen($vorher);
        }
        if ($ok) {
            DB::update('betreiber_rechnungen', ['versendet' => Util::jetzt()], 'id = :id', ['id' => $id]);
        }
        Betreiberlog::schreiben('INVOICE_SENT', ['objekt' => 'rechnung', 'objekt_id' => $id, 'instanz_id' => (int) $r['instanz_id'],
            'ergebnis' => $ok ? 'ok' : 'fehler', 'beschreibung' => ($ok ? 'an ' : 'Versand fehlgeschlagen an ') . $an]);
        return [$ok, $ok ? 'Rechnung an ' . $an . ' verschickt.' : 'Die Mail ging nicht raus. Prüfe den Mailversand unter System.'];
    }

    /* ========================================================= Datei === */

    /**
     * Die beim Ausstellen abgelegte Datei – nur, wenn sie noch dieselbe ist.
     */
    public static function dateiInhalt(array $r): ?string
    {
        if ((string) $r['datei'] === '' || !preg_match('#^\d{4}/[A-Z0-9-]+\.pdf$#', (string) $r['datei'])) {
            return null;
        }
        $pfad = self::ablage() . '/' . $r['datei'];
        if (!is_file($pfad)) {
            return null;
        }
        $inhalt = (string) file_get_contents($pfad);
        return hash_equals((string) $r['datei_hash'], hash('sha256', $inhalt)) ? $inhalt : null;
    }

    public static function zeitraum(array $r): string
    {
        $von = (string) ($r['leistung_von'] ?? '');
        $bis = (string) ($r['leistung_bis'] ?? '');
        if ($von === '') {
            return '';
        }
        return $bis !== '' && substr($bis, 0, 10) !== substr($von, 0, 10)
            ? Util::datum($von) . ' – ' . Util::datum($bis) : Util::datum($von);
    }

    /* =========================================================== PDF === */

    private static function betrag(int $cent): string
    {
        return ($cent < 0 ? '-' : '') . number_format(abs($cent) / 100, 2, ',', '.') . ' EUR';
    }

    private static function menge(int $hundertstel): string
    {
        return $hundertstel % 100 === 0 ? (string) intdiv($hundertstel, 100)
            : rtrim(rtrim(number_format($hundertstel / 100, 2, ',', ''), '0'), ',');
    }

    /**
     * Setzt die Rechnung als PDF. Für ausgestellte Rechnungen wird das
     * nur einmal aufgerufen – beim Ausstellen –, danach gilt die Datei.
     *
     * @param array<string,mixed> $r
     * @param array<int,array<string,mixed>> $positionen
     */
    public static function pdf(array $r, array $positionen): PDF
    {
        $entwurf = (string) $r['status'] === 'entwurf';
        $a = $entwurf ? self::absender() : Util::ausJson((string) $r['absender'], []);
        $e = Util::ausJson((string) $r['empfaenger'], []);
        $storno = (string) $r['art'] === 'storno';
        $pine = [11, 43, 34];
        $grau = [110, 112, 106];
        $pdf = new PDF();
        $breite = $pdf->seitenBreite();
        $rechts = $breite - 20;

        $fuss = static function () use ($pdf, $a, $breite, $grau): void {
            $y = $pdf->seitenHoehe() - 24;
            $pdf->linie(20, $y, $breite - 20, $y);
            $spalten = [
                [(string) ($a['firma'] ?? ''), (string) ($a['inhaber'] ?? ''), (string) ($a['strasse'] ?? ''),
                 trim(($a['plz'] ?? '') . ' ' . ($a['ort'] ?? ''))],
                [(string) ($a['email'] ?? ''), (string) ($a['telefon'] ?? ''), (string) ($a['web'] ?? '')],
                array_merge(
                    [($a['ust_id'] ?? '') !== '' ? 'USt-IdNr.: ' . $a['ust_id'] : '',
                     ($a['steuernummer'] ?? '') !== '' ? 'Steuernummer: ' . $a['steuernummer'] : ''],
                    /* Die Bankverbindung steht in einer Zeile („Bank · IBAN … · BIC …")
                       und wird hier an den Punkten umbrochen – abgeschnitten wäre
                       eine IBAN wertlos. */
                    array_map('trim', preg_split('/\s*[·|]\s*/u', (string) ($a['bank'] ?? '')) ?: [])
                ),
            ];
            foreach ($spalten as $s => $zeilen) {
                $i = 0;
                $breite = $s === 2 ? 60 : 46;
                foreach (array_filter($zeilen) as $zeile) {
                    $pdf->text(20 + $s * 56, $y + 5 + $i * 3.8, Util::kuerzen($zeile, $breite), 7, false, $grau);
                    $i++;
                }
            }
        };

        /* Kopf */
        $pdf->rechteck(0, 0, $breite, 3, $pine);
        $pdf->text(20, 24, (string) (($a['firma'] ?? '') ?: 'Absender fehlt'), 14, true, $pine);
        $pdf->textRechts($rechts, 24, $storno ? 'Stornorechnung' : 'Rechnung', 15, true);
        $pdf->textRechts($rechts, 31, $entwurf ? 'ENTWURF – nicht gültig' : (string) $r['nummer'], 9, $entwurf,
            $entwurf ? [178, 59, 50] : $grau);

        /* Absenderzeile und Empfänger (Anschriftfeld wie DIN 5008) */
        $zeile = implode(' · ', array_filter([(string) ($a['firma'] ?? ''), (string) ($a['strasse'] ?? ''),
            trim(($a['plz'] ?? '') . ' ' . ($a['ort'] ?? ''))]));
        $pdf->text(20, 50, Util::kuerzen($zeile, 90), 7, false, $grau);
        $pdf->linie(20, 51.5, 105, 51.5, 0.2, [220, 220, 214]);
        $y = 57;
        $eigenesLand = strtoupper((string) ($a['land'] ?? 'DE')) ?: 'DE';
        foreach (array_filter([
            (string) ($e['firma'] ?? ''),
            (string) ($e['name'] ?? '') !== (string) ($e['firma'] ?? '') ? (string) ($e['name'] ?? '') : '',
            (string) ($e['strasse'] ?? ''),
            trim(($e['plz'] ?? '') . ' ' . ($e['ort'] ?? '')),
            strtoupper((string) ($e['land'] ?? 'DE')) !== $eigenesLand
                ? (self::LAENDER[strtoupper((string) ($e['land'] ?? ''))] ?? strtoupper((string) ($e['land'] ?? ''))) : '',
        ]) as $z) {
            $pdf->text(20, $y, Util::kuerzen($z, 50), 10);
            $y += 5;
        }

        /* Eckdaten */
        $yr = 57;
        $datum = $r['datum'] ? Util::datum((string) $r['datum']) : 'beim Ausstellen';
        foreach (array_filter([
            ['Rechnungsnummer', $entwurf ? '—' : (string) $r['nummer']],
            ['Rechnungsdatum', $datum],
            $r['leistung_von'] ? ['Leistungszeitraum', self::zeitraum($r)] : null,
            ['Kundennummer', 'I-' . (int) $r['instanz_id']],
            !$storno ? ['Fällig am', $r['faellig'] ? Util::datum((string) $r['faellig'])
                : Plattform::zahl('rg_zahlungsziel') . ' Tage nach Rechnungsdatum'] : null,
            (string) $r['steuerfall'] === 'rc' && ($e['ust_id'] ?? '') !== '' ? ['USt-IdNr. Kunde', (string) $e['ust_id']] : null,
        ]) as [$label, $wert]) {
            $pdf->text(120, $yr, $label, 8.5, false, $grau);
            $pdf->textRechts($rechts, $yr, $wert, 8.5, true);
            $yr += 5;
        }
        $y = max($y, $yr) + 12;

        if (trim((string) $r['text_oben']) !== '') {
            $y += $pdf->absatz(20, $y, $breite - 40, (string) $r['text_oben'], 9.5) + 4;
        }

        /* Positionen */
        $kopfzeile = static function (float $y) use ($pdf, $breite, $rechts): float {
            $pdf->rechteck(20, $y - 4.5, $breite - 40, 8, [244, 244, 240]);
            foreach ([[22, 'Pos.'], [32, 'Beschreibung']] as [$x, $t]) {
                $pdf->text($x, $y, $t, 8, true, [90, 90, 84]);
            }
            $pdf->textRechts(128, $y, 'Menge', 8, true, [90, 90, 84]);
            $pdf->textRechts(152, $y, 'Einzelpreis', 8, true, [90, 90, 84]);
            $pdf->textRechts(164, $y, 'USt', 8, true, [90, 90, 84]);
            $pdf->textRechts($rechts - 2, $y, 'Betrag', 8, true, [90, 90, 84]);
            return $y + 8;
        };
        $y = $kopfzeile($y);
        foreach ($positionen as $p) {
            if ($y > $pdf->seitenHoehe() - 60) {
                $fuss();
                $pdf->neueSeite();
                $pdf->rechteck(0, 0, $breite, 3, $pine);
                $pdf->text(20, 18, ($storno ? 'Stornorechnung ' : 'Rechnung ') . ($entwurf ? '(Entwurf)' : (string) $r['nummer'])
                    . ' – Fortsetzung', 9, false, $grau);
                $y = $kopfzeile(30);
            }
            $pdf->text(22, $y, (string) (int) $p['pos'], 9);
            $hoehe = $pdf->absatz(32, $y, 78, (string) $p['text'], 9, 4.2);
            $pdf->textRechts(128, $y, self::menge((int) $p['menge_hundertstel']) . ((string) $p['einheit'] !== '' ? ' ' . $p['einheit'] : ''), 9);
            $pdf->textRechts(152, $y, self::betrag((int) $p['einzel_cent']), 9);
            $pdf->textRechts(164, $y, (int) $p['steuersatz'] . ' %', 9);
            $pdf->textRechts($rechts - 2, $y, self::betrag((int) $p['netto_cent']), 9, true);
            $y += max(6.5, $hoehe + 2.3);
            $pdf->linie(20, $y - 3, $breite - 20, $y - 3, 0.2, [230, 230, 226]);
        }

        /* Summen */
        $y += 3;
        $links = $breite - 95;
        $pdf->text($links, $y, 'Nettobetrag', 9.5, false, $grau);
        $pdf->textRechts($rechts, $y, self::betrag((int) $r['netto_cent']), 9.5);
        $y += 5.5;
        foreach (Util::ausJson((string) $r['steuern'], []) as $satz => $s) {
            if ((int) $satz === 0) {
                continue;
            }
            $pdf->text($links, $y, 'Umsatzsteuer ' . (int) $satz . ' % auf ' . self::betrag((int) $s['netto']), 9.5, false, $grau);
            $pdf->textRechts($rechts, $y, self::betrag((int) $s['steuer']), 9.5);
            $y += 5.5;
        }
        $pdf->linie($links, $y - 1.5, $rechts, $y - 1.5, 0.4, [60, 60, 56]);
        $y += 4;
        $pdf->text($links, $y, 'Gesamtbetrag', 11, true);
        $pdf->textRechts($rechts, $y, self::betrag((int) $r['brutto_cent']), 11, true, $pine);
        $y += 13;

        $hinweis = self::hinweis((string) $r['steuerfall']);
        foreach (array_filter([$hinweis, trim((string) $r['notiz'])]) as $absatz) {
            if ($y > $pdf->seitenHoehe() - 40) {
                $fuss();
                $pdf->neueSeite();
                $y = 30;
            }
            $y += $pdf->absatz(20, $y, $breite - 40, $absatz, 9) + 3;
        }
        if (!$storno && (int) $r['brutto_cent'] > 0) {
            $y += 2;
            $pdf->absatz(20, $y, $breite - 40, 'Bitte überweise den Gesamtbetrag '
                . ($r['faellig'] ? 'bis zum ' . Util::datum((string) $r['faellig']) : 'innerhalb von '
                   . Plattform::zahl('rg_zahlungsziel') . ' Tagen')
                . ' unter Angabe der Rechnungsnummer' . (($a['bank'] ?? '') !== '' ? ' auf das unten genannte Konto' : '') . '.', 9);
        }
        $fuss();
        return $pdf;
    }
}
