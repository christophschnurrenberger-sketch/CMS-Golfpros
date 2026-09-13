<?php
/**
 * Leads – die Anfragen-Pipeline.
 *
 * Sechs Stufen von der Anfrage bis zum Kunden. Die letzte Stufe legt einen
 * echten Kunden an und verknüpft ihn – der Lead bleibt als Herkunftsnachweis
 * bestehen, statt gelöscht zu werden. Sonst wüsste am Jahresende niemand
 * mehr, welcher Kanal tatsächlich Kunden gebracht hat.
 */
final class Leads
{
    public const STUFEN = [
        'neu'          => ['Neue Anfrage', '#2f7db8'],
        'kontaktiert'  => ['Kontaktiert', '#6b4ea8'],
        'qualifiziert' => ['Qualifiziert', '#b08829'],
        'angebot'      => ['Angebot', '#a6640d'],
        'gebucht'      => ['Gebucht', '#17794f'],
        'kunde'        => ['Kunde', '#0d6b4f'],
        'verloren'     => ['Verloren', '#9d9d97'],
    ];

    public static function stufeName(string $s): string
    {
        return self::STUFEN[$s][0] ?? $s;
    }

    public static function stufeFarbe(string $s): string
    {
        return self::STUFEN[$s][1] ?? '#9d9d97';
    }

    /** @return array<string,array<int,array<string,mixed>>> nach Stufe gebündelt */
    public static function pipeline(array $f = []): array
    {
        $wo = [];
        $p  = [];
        if (!empty($f['suche'])) {
            $wo[] = '(name LIKE :q OR email LIKE :q OR interesse LIKE :q)';
            $p['q'] = '%' . $f['suche'] . '%';
        }
        if (!empty($f['quelle'])) {
            $wo[] = 'quelle = :quelle';
            $p['quelle'] = $f['quelle'];
        }
        if (!empty($f['user'])) {
            $wo[] = 'user_id = :u';
            $p['u'] = (int) $f['user'];
        }
        $spalten = [];
        foreach (array_keys(self::STUFEN) as $stufe) {
            $spalten[$stufe] = [];
        }
        foreach (Tenant::all('leads', implode(' AND ', $wo), $p, 'id DESC') as $lead) {
            $spalten[(string) $lead['stufe']][] = $lead;
        }
        return $spalten;
    }

    public static function speichern(array $daten, int $id = 0): int
    {
        $satz = array_intersect_key($daten, array_flip([
            'name', 'email', 'telefon', 'quelle', 'stufe', 'interesse',
            'wert_cent', 'notiz', 'nachricht', 'user_id',
        ]));
        if ($id > 0) {
            Tenant::update('leads', $id, $satz);
            return $id;
        }
        $neu = Tenant::insert('leads', $satz + ['stufe' => $satz['stufe'] ?? 'neu']);
        Notify::senden('customer', 'Neue Anfrage: ' . ($satz['name'] ?? ''),
            (string) ($satz['interesse'] ?? '') . ' · über ' . ($satz['quelle'] ?? 'Website'),
            '/app/lead.php?id=' . $neu);
        Automations::ausloesen('lead_created', ['lead_id' => $neu]);
        Audit::schreiben('erstellt', 'lead', $neu, (string) ($satz['name'] ?? ''));
        return $neu;
    }

    public static function stufeSetzen(int $id, string $stufe): bool
    {
        if (!isset(self::STUFEN[$stufe])) {
            return false;
        }
        $lead = Tenant::find('leads', $id);
        if (!$lead) {
            return false;
        }
        $satz = ['stufe' => $stufe];
        if (in_array($stufe, ['kunde', 'verloren'], true)) {
            $satz['geschlossen'] = Util::jetzt();
        }
        if ($stufe === 'kontaktiert' && !$lead['letzter_kontakt']) {
            $satz['letzter_kontakt'] = Util::jetzt();
        }
        Tenant::update('leads', $id, $satz);

        if ($stufe === 'kunde' && (int) $lead['customer_id'] === 0) {
            self::zuKunde($id);
        }
        Audit::schreiben('geaendert', 'lead', $id, 'Stufe: ' . self::stufeName($stufe));
        return true;
    }

    /** Macht aus einem Lead einen Kunden. Der Lead bleibt als Herkunft stehen. */
    public static function zuKunde(int $id): int
    {
        $lead = Tenant::find('leads', $id);
        if (!$lead) {
            return 0;
        }
        if ((int) $lead['customer_id'] > 0) {
            return (int) $lead['customer_id'];
        }
        $teile = preg_split('/\s+/', trim((string) $lead['name'])) ?: [''];
        $nachname = count($teile) > 1 ? array_pop($teile) : '';
        $vorname  = implode(' ', $teile);

        $kundeId = Customers::speichern([
            'vorname'  => $vorname,
            'nachname' => $nachname,
            'email'    => (string) $lead['email'],
            'telefon'  => (string) $lead['telefon'],
            'quelle'   => (string) $lead['quelle'],
            'notiz'    => trim(((string) $lead['interesse']) . "\n" . ((string) $lead['notiz'])),
            'trainer_id' => (int) $lead['user_id'],
        ]);
        Tenant::update('leads', $id, ['customer_id' => $kundeId, 'stufe' => 'kunde', 'geschlossen' => Util::jetzt()]);
        return $kundeId;
    }

    public static function kontaktiert(int $id): void
    {
        Tenant::update('leads', $id, ['letzter_kontakt' => Util::jetzt()]);
    }

    /** @return array<string,mixed> Trichterzahlen für die Auswertung */
    public static function trichter(int $tage = 90): array
    {
        $seit = date('Y-m-d', strtotime('-' . $tage . ' days'));
        $zahlen = [];
        foreach (array_keys(self::STUFEN) as $stufe) {
            $zahlen[$stufe] = Tenant::count('leads', 'stufe = :s AND erstellt >= :seit',
                ['s' => $stufe, 'seit' => $seit]);
        }
        $gesamt = array_sum($zahlen);
        $gewonnen = $zahlen['kunde'] ?? 0;
        return [
            'stufen'  => $zahlen,
            'gesamt'  => $gesamt,
            'quote'   => $gesamt > 0 ? round($gewonnen / $gesamt * 100, 1) : 0.0,
            'wert'    => Tenant::sum('leads', 'wert_cent', "stufe NOT IN ('kunde','verloren')"),
        ];
    }

    /** @return array<int,array{quelle:string,anzahl:int,kunden:int}> */
    public static function quellen(int $tage = 180): array
    {
        $seit = date('Y-m-d', strtotime('-' . $tage . ' days'));
        $zeilen = DB::all(
            'SELECT quelle, COUNT(*) AS anzahl,
                    SUM(CASE WHEN stufe = "kunde" THEN 1 ELSE 0 END) AS kunden
             FROM leads WHERE workspace_id = :w AND erstellt >= :seit
             GROUP BY quelle ORDER BY anzahl DESC',
            ['w' => Tenant::id(), 'seit' => $seit]
        );
        return array_map(static fn($z) => [
            'quelle' => (string) ($z['quelle'] ?: 'unbekannt'),
            'anzahl' => (int) $z['anzahl'],
            'kunden' => (int) $z['kunden'],
        ], $zeilen);
    }

    /** Leads, die zu lange unbeantwortet liegen – der häufigste Umsatzverlust. */
    public static function unbeantwortet(int $stundenGrenze = 24): array
    {
        return Tenant::all('leads',
            'stufe = "neu" AND letzter_kontakt IS NULL AND erstellt < :grenze',
            ['grenze' => date('Y-m-d H:i:s', time() - $stundenGrenze * 3600)], 'erstellt');
    }
}
