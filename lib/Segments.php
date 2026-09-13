<?php
/**
 * Segments – dynamische Kundengruppen.
 *
 * Ein Segment ist keine Liste, sondern eine Regel. „Anfänger“ enthält heute
 * andere Leute als nächsten Monat, ohne dass jemand pflegt. Deshalb wird die
 * Regel bei jeder Abfrage in SQL übersetzt statt eine Zuordnungstabelle zu
 * führen, die sofort veraltet.
 */
final class Segments
{
    /** Felder, über die eine Regel gehen kann. */
    public const FELDER = [
        'hcp'            => ['Handicap', 'zahl'],
        'health_score'   => ['Gesundheitswert', 'zahl'],
        'status'         => ['Status', 'auswahl'],
        'quelle'         => ['Quelle', 'text'],
        'heimclub'       => ['Heimclub', 'text'],
        'trainer_id'     => ['Trainer', 'trainer'],
        'newsletter'     => ['Newsletter', 'jaNein'],
        'tage_seit_termin' => ['Tage seit letztem Termin', 'zahl'],
        'tage_seit_anlage' => ['Tage seit Anlage', 'zahl'],
        'umsatz_cent'    => ['Gesamtumsatz', 'geld'],
        'termine_gesamt' => ['Termine gesamt', 'zahl'],
        'alter'          => ['Alter', 'zahl'],
        'offene_einheiten' => ['Offene Paketeinheiten', 'zahl'],
        'paket_tage'     => ['Tage bis Paketablauf', 'zahl'],
        'tag'            => ['Schlagwort', 'text'],
    ];

    public const OPERATOREN = [
        '='   => 'ist', '!='  => 'ist nicht',
        '<'   => 'kleiner als', '<='  => 'höchstens',
        '>'   => 'größer als', '>='  => 'mindestens',
        'wie' => 'enthält', 'leer' => 'ist leer', 'nicht_leer' => 'ist gefüllt',
    ];

    /**
     * Übersetzt eine Regel in eine WHERE-Bedingung auf `customers`.
     * Zeitbezogene Felder werden als Unterabfrage formuliert – das ist
     * langsamer als eine gepflegte Spalte, aber immer richtig.
     *
     * @return array{0:string,1:array<string,mixed>}
     */
    public static function bedingung(array $regeln): array
    {
        $bedingungen = (array) ($regeln['bedingungen'] ?? []);
        $verbinder   = strtoupper((string) ($regeln['verknuepfung'] ?? 'und')) === 'ODER' ? ' OR ' : ' AND ';
        $teile = [];
        $p     = [];
        $ws    = Tenant::id();

        foreach ($bedingungen as $i => $b) {
            $feld = (string) ($b['feld'] ?? '');
            $op   = (string) ($b['op'] ?? '=');
            $wert = (string) ($b['wert'] ?? '');
            if (!isset(self::FELDER[$feld]) || !isset(self::OPERATOREN[$op])) {
                continue;
            }
            $schluessel = 'seg' . $i;

            $ausdruck = match ($feld) {
                'hcp' => 'CAST(NULLIF(customers.hcp, "") AS REAL)',
                'umsatz_cent' => "(SELECT COALESCE(SUM(o.summe_cent),0) FROM orders o
                                    WHERE o.customer_id = customers.id AND o.status = 'bezahlt')",
                'termine_gesamt' => "(SELECT COUNT(*) FROM bookings b
                                       WHERE b.customer_id = customers.id AND b.status IN ('bestaetigt','erschienen'))",
                'tage_seit_termin' => "(SELECT COALESCE(CAST(julianday('now') - julianday(MAX(b.start)) AS INTEGER), 9999)
                                         FROM bookings b WHERE b.customer_id = customers.id
                                         AND b.status IN ('bestaetigt','erschienen') AND b.start <= datetime('now'))",
                'tage_seit_anlage' => "CAST(julianday('now') - julianday(customers.erstellt) AS INTEGER)",
                'alter' => "CAST((julianday('now') - julianday(customers.geburtstag)) / 365.25 AS INTEGER)",
                'offene_einheiten' => "(SELECT COALESCE(SUM(cp.einheiten_gesamt - cp.einheiten_genutzt),0)
                                         FROM customer_packages cp WHERE cp.customer_id = customers.id
                                         AND cp.status = 'aktiv')",
                'paket_tage' => "(SELECT COALESCE(MIN(CAST(julianday(cp.laeuft_ab) - julianday('now') AS INTEGER)), 9999)
                                   FROM customer_packages cp WHERE cp.customer_id = customers.id
                                   AND cp.status = 'aktiv')",
                'tag' => 'customers.tags',
                default => 'customers.' . $feld,
            };

            // MySQL kennt julianday nicht – dieselbe Rechnung mit DATEDIFF.
            if (!DB::istSqlite()) {
                $ausdruck = str_replace(
                    ["CAST(julianday('now') - julianday(MAX(b.start)) AS INTEGER)",
                     "CAST(julianday('now') - julianday(customers.erstellt) AS INTEGER)",
                     "CAST((julianday('now') - julianday(customers.geburtstag)) / 365.25 AS INTEGER)",
                     "CAST(julianday(cp.laeuft_ab) - julianday('now') AS INTEGER)",
                     "datetime('now')"],
                    ['DATEDIFF(NOW(), MAX(b.start))',
                     'DATEDIFF(NOW(), customers.erstellt)',
                     'TIMESTAMPDIFF(YEAR, customers.geburtstag, NOW())',
                     'DATEDIFF(cp.laeuft_ab, NOW())',
                     'NOW()'],
                    $ausdruck
                );
            }

            if ($op === 'leer') {
                $teile[] = '(' . $ausdruck . ' IS NULL OR ' . $ausdruck . ' = "")';
                continue;
            }
            if ($op === 'nicht_leer') {
                $teile[] = '(' . $ausdruck . ' IS NOT NULL AND ' . $ausdruck . ' != "")';
                continue;
            }
            if ($op === 'wie') {
                $teile[] = $ausdruck . ' LIKE :' . $schluessel;
                $p[$schluessel] = $feld === 'tag' ? '%"' . $wert . '"%' : '%' . $wert . '%';
                continue;
            }
            $teile[] = $ausdruck . ' ' . $op . ' :' . $schluessel;
            $p[$schluessel] = is_numeric($wert) ? $wert + 0 : $wert;
        }

        return [implode($verbinder, $teile), $p];
    }

    /** @return array<int,array<string,mixed>> Kunden im Segment */
    public static function kunden(array $segment, int $limit = 0): array
    {
        [$wo, $p] = self::bedingung(Util::ausJson((string) $segment['regeln']));
        return Tenant::all('customers', $wo, $p, 'nachname, vorname', $limit);
    }

    public static function anzahl(array $segment): int
    {
        [$wo, $p] = self::bedingung(Util::ausJson((string) $segment['regeln']));
        try {
            return Tenant::count('customers', $wo, $p);
        } catch (Throwable $e) {
            return 0;
        }
    }

    /** @return array<int,array<string,mixed>> */
    public static function alle(): array
    {
        return Tenant::all('segments', '', [], 'system DESC, name');
    }

    /**
     * Die Segmente, die jeder Workspace mitbekommt. Sie decken die Fragen ab,
     * die ein Pro tatsächlich stellt – nicht die, die sich gut aufzählen.
     */
    public static function standard(): array
    {
        return [
            ['Anfänger', 'Handicap 37 oder ohne Angabe – der klassische Platzreife-Bereich.', '#2f7db8',
             ['verknuepfung' => 'oder', 'bedingungen' => [
                 ['feld' => 'hcp', 'op' => '>=', 'wert' => '37'],
                 ['feld' => 'hcp', 'op' => 'leer', 'wert' => ''],
             ]]],
            ['Fortgeschritten', 'Handicap zwischen 12 und 36.', '#0d6b4f',
             ['bedingungen' => [
                 ['feld' => 'hcp', 'op' => '<', 'wert' => '37'],
                 ['feld' => 'hcp', 'op' => '>', 'wert' => '11.9'],
             ]]],
            ['Einstellige', 'Handicap unter 12 – Spieler mit Turnierambition.', '#b08829',
             ['bedingungen' => [['feld' => 'hcp', 'op' => '<=', 'wert' => '11.9'],
                                ['feld' => 'hcp', 'op' => 'nicht_leer', 'wert' => '']]]],
            ['Junior', 'Unter 18 Jahren.', '#6b4ea8',
             ['bedingungen' => [['feld' => 'alter', 'op' => '<', 'wert' => '18']]]],
            ['VIP', 'Mehr als 1.500 € Umsatz.', '#b4242b',
             ['bedingungen' => [['feld' => 'umsatz_cent', 'op' => '>=', 'wert' => '150000']]]],
            ['Neukunden', 'In den letzten 30 Tagen dazugekommen.', '#17794f',
             ['bedingungen' => [['feld' => 'tage_seit_anlage', 'op' => '<=', 'wert' => '30']]]],
            ['Inaktiv', 'Seit über 60 Tagen kein Termin – hier lohnt ein Anruf.', '#a6640d',
             ['bedingungen' => [['feld' => 'tage_seit_termin', 'op' => '>', 'wert' => '60']]]],
            ['Paket läuft aus', 'Restguthaben vorhanden, Ablauf in 30 Tagen.', '#1d5fa8',
             ['bedingungen' => [
                 ['feld' => 'paket_tage', 'op' => '<=', 'wert' => '30'],
                 ['feld' => 'offene_einheiten', 'op' => '>', 'wert' => '0'],
             ]]],
            ['Gefährdet', 'Gesundheitswert unter 40.', '#b4242b',
             ['bedingungen' => [['feld' => 'health_score', 'op' => '<', 'wert' => '40'],
                                ['feld' => 'status', 'op' => '=', 'wert' => 'aktiv']]]],
        ];
    }

    public static function standardAnlegen(): void
    {
        foreach (self::standard() as [$name, $beschreibung, $farbe, $regeln]) {
            if (Tenant::count('segments', 'name = :n', ['n' => $name]) > 0) {
                continue;
            }
            Tenant::insert('segments', [
                'name' => $name, 'beschreibung' => $beschreibung,
                'farbe' => $farbe, 'regeln' => Util::json($regeln), 'system' => 1,
            ]);
        }
    }

    /** Menschenlesbare Fassung einer Regel – für die Segmentliste. */
    public static function beschreiben(array $regeln): string
    {
        $teile = [];
        foreach ((array) ($regeln['bedingungen'] ?? []) as $b) {
            $feld = self::FELDER[$b['feld'] ?? ''][0] ?? ($b['feld'] ?? '');
            $op   = self::OPERATOREN[$b['op'] ?? '='] ?? '';
            $wert = (string) ($b['wert'] ?? '');
            if (($b['feld'] ?? '') === 'umsatz_cent' && $wert !== '') {
                $wert = Util::geld((int) $wert);
            }
            $teile[] = trim($feld . ' ' . $op . ' ' . $wert);
        }
        $verbinder = strtoupper((string) ($regeln['verknuepfung'] ?? 'und')) === 'ODER' ? ' oder ' : ' und ';
        return implode($verbinder, $teile);
    }
}
