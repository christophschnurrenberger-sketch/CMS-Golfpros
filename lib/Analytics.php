<?php
/**
 * Analytics – die Zahlen des Geschäfts.
 *
 * Jede Kennzahl wird aus den Belegen gerechnet, nicht gespeichert. Eine
 * gespeicherte Umsatzsumme läuft mit der ersten Stornierung auseinander –
 * und ein Zahlenwerk, dem man nicht traut, wird nicht benutzt.
 *
 * Websitezahlen kommen aus der eigenen, cookiefreien Zählung: gespeichert
 * wird ein täglich wechselnder Hash, keine IP. Google Analytics kann
 * zusätzlich eingebunden werden, ist aber nie Voraussetzung.
 */
final class Analytics
{
    /* ------------------------------------------------------ Zeiträume - */

    /** @return array{von:string,bis:string,name:string,vergleich_von:string,vergleich_bis:string} */
    public static function zeitraum(string $key): array
    {
        $heute = Util::heute();
        return match ($key) {
            'heute' => [
                'von' => $heute, 'bis' => $heute, 'name' => 'Heute',
                'vergleich_von' => date('Y-m-d', strtotime('-1 day')),
                'vergleich_bis' => date('Y-m-d', strtotime('-1 day')),
            ],
            'woche' => [
                'von' => date('Y-m-d', strtotime('monday this week')), 'bis' => $heute, 'name' => 'Diese Woche',
                'vergleich_von' => date('Y-m-d', strtotime('monday last week')),
                'vergleich_bis' => date('Y-m-d', strtotime('sunday last week')),
            ],
            'monat' => [
                'von' => date('Y-m-01'), 'bis' => $heute, 'name' => 'Dieser Monat',
                'vergleich_von' => date('Y-m-01', strtotime('-1 month')),
                'vergleich_bis' => date('Y-m-t', strtotime('-1 month')),
            ],
            'quartal' => [
                'von' => date('Y-m-01', strtotime('-2 months')), 'bis' => $heute, 'name' => 'Letzte 3 Monate',
                'vergleich_von' => date('Y-m-01', strtotime('-5 months')),
                'vergleich_bis' => date('Y-m-t', strtotime('-3 months')),
            ],
            'jahr' => [
                'von' => date('Y-01-01'), 'bis' => $heute, 'name' => 'Dieses Jahr',
                'vergleich_von' => date('Y-01-01', strtotime('-1 year')),
                'vergleich_bis' => date('Y-m-d', strtotime('-1 year')),
            ],
            default => [
                'von' => date('Y-m-d', strtotime('-29 days')), 'bis' => $heute, 'name' => 'Letzte 30 Tage',
                'vergleich_von' => date('Y-m-d', strtotime('-59 days')),
                'vergleich_bis' => date('Y-m-d', strtotime('-30 days')),
            ],
        };
    }

    public const ZEITRAEUME = [
        'heute' => 'Heute', 'woche' => 'Diese Woche', 'monat' => 'Dieser Monat',
        '30tage' => 'Letzte 30 Tage', 'quartal' => 'Letzte 3 Monate', 'jahr' => 'Dieses Jahr',
    ];

    /* ----------------------------------------------------- Kennzahlen - */

    /** @return array<string,array{wert:float,vorher:float,delta:?float,format:string}> */
    public static function kennzahlen(array $z): array
    {
        $k = [];

        $umsatz  = Commerce::umsatz($z['von'], $z['bis']);
        $vorher  = Commerce::umsatz($z['vergleich_von'], $z['vergleich_bis']);
        $k['umsatz'] = self::wert($umsatz, $vorher, 'geld');

        $bestellungen = Tenant::count('orders',
            "status = 'bezahlt' AND bezahlt_am >= :von AND bezahlt_am <= :bis",
            ['von' => $z['von'] . ' 00:00:00', 'bis' => $z['bis'] . ' 23:59:59']);
        $bestellungenVorher = Tenant::count('orders',
            "status = 'bezahlt' AND bezahlt_am >= :von AND bezahlt_am <= :bis",
            ['von' => $z['vergleich_von'] . ' 00:00:00', 'bis' => $z['vergleich_bis'] . ' 23:59:59']);
        $k['bestellungen'] = self::wert($bestellungen, $bestellungenVorher, 'zahl');

        $k['warenkorb'] = self::wert(
            $bestellungen > 0 ? $umsatz / $bestellungen : 0,
            $bestellungenVorher > 0 ? $vorher / $bestellungenVorher : 0,
            'geld'
        );

        $buchungen = Tenant::count('bookings',
            "start >= :von AND start <= :bis AND status != 'abgesagt'",
            ['von' => $z['von'] . ' 00:00:00', 'bis' => $z['bis'] . ' 23:59:59']);
        $buchungenVorher = Tenant::count('bookings',
            "start >= :von AND start <= :bis AND status != 'abgesagt'",
            ['von' => $z['vergleich_von'] . ' 00:00:00', 'bis' => $z['vergleich_bis'] . ' 23:59:59']);
        $k['buchungen'] = self::wert($buchungen, $buchungenVorher, 'zahl');

        $neukunden = Tenant::count('customers', 'erstellt >= :von AND erstellt <= :bis',
            ['von' => $z['von'] . ' 00:00:00', 'bis' => $z['bis'] . ' 23:59:59']);
        $neukundenVorher = Tenant::count('customers', 'erstellt >= :von AND erstellt <= :bis',
            ['von' => $z['vergleich_von'] . ' 00:00:00', 'bis' => $z['vergleich_bis'] . ' 23:59:59']);
        $k['neukunden'] = self::wert($neukunden, $neukundenVorher, 'zahl');

        $k['kunden'] = self::wert(Tenant::count('customers', "status = 'aktiv'"), 0, 'zahl');

        $leads = Tenant::count('leads', 'erstellt >= :von AND erstellt <= :bis',
            ['von' => $z['von'] . ' 00:00:00', 'bis' => $z['bis'] . ' 23:59:59']);
        $leadsVorher = Tenant::count('leads', 'erstellt >= :von AND erstellt <= :bis',
            ['von' => $z['vergleich_von'] . ' 00:00:00', 'bis' => $z['vergleich_bis'] . ' 23:59:59']);
        $k['leads'] = self::wert($leads, $leadsVorher, 'zahl');

        $besucher = self::besucher($z['von'], $z['bis']);
        $besucherVorher = self::besucher($z['vergleich_von'], $z['vergleich_bis']);
        $k['besucher'] = self::wert($besucher, $besucherVorher, 'zahl');

        $k['conversion'] = self::wert(
            $besucher > 0 ? $leads / $besucher * 100 : 0,
            $besucherVorher > 0 ? $leadsVorher / $besucherVorher * 100 : 0,
            'prozent'
        );

        $auslastung = Bookings::auslastung($z['von'], $z['bis']);
        $k['auslastung'] = self::wert($auslastung ?? 0.0, 0, 'prozent');
        $k['auslastung']['messbar'] = $auslastung !== null;

        return $k;
    }

    private static function wert(float $neu, float $alt, string $format): array
    {
        return [
            'wert' => $neu, 'vorher' => $alt,
            'delta' => $alt > 0 ? Util::wachstum($neu, $alt) : null,
            'format' => $format,
        ];
    }

    public static function formatieren(array $kennzahl): string
    {
        return match ($kennzahl['format']) {
            'geld'    => Util::geld((int) round($kennzahl['wert'])),
            'prozent' => Util::prozent($kennzahl['wert'], 1),
            default   => Util::zahl($kennzahl['wert']),
        };
    }

    /* ------------------------------------------------------- Verläufe - */

    /** @return array{labels:string[],werte:float[]} Umsatz je Tag */
    public static function umsatzVerlauf(string $von, string $bis): array
    {
        $labels = [];
        $werte  = [];
        $tage = max(1, (int) ceil((strtotime($bis) - strtotime($von)) / 86400) + 1);
        $proMonat = $tage > 92;

        if ($proMonat) {
            $zeiger = strtotime(date('Y-m-01', strtotime($von)));
            while ($zeiger <= strtotime($bis)) {
                $monatVon = date('Y-m-01', $zeiger);
                $monatBis = date('Y-m-t', $zeiger);
                $labels[] = substr(Util::monatName((int) date('n', $zeiger)), 0, 3);
                $werte[]  = (float) Commerce::umsatz($monatVon, min($monatBis, $bis));
                $zeiger = strtotime('+1 month', $zeiger);
            }
            return ['labels' => $labels, 'werte' => $werte];
        }

        $schritt = $tage > 45 ? 7 : 1;
        for ($t = strtotime($von); $t <= strtotime($bis); $t += 86400 * $schritt) {
            $bisTag = date('Y-m-d', min(strtotime($bis), $t + 86400 * ($schritt - 1)));
            $labels[] = date($schritt > 1 ? 'd.m.' : 'd.m.', $t);
            $werte[]  = (float) Commerce::umsatz(date('Y-m-d', $t), $bisTag);
        }
        return ['labels' => $labels, 'werte' => $werte];
    }

    /** Umsatz je Monat, aktuelles Jahr gegen Vorjahr. */
    public static function jahresvergleich(): array
    {
        $labels = [];
        $jetzt  = [];
        $vorjahr = [];
        for ($m = 1; $m <= 12; $m++) {
            $labels[] = substr(Util::monatName($m), 0, 3);
            $jetzt[]  = (float) Commerce::umsatz(
                sprintf('%s-%02d-01', date('Y'), $m),
                date('Y-m-t', strtotime(sprintf('%s-%02d-01', date('Y'), $m)))
            );
            $vorjahr[] = (float) Commerce::umsatz(
                sprintf('%s-%02d-01', date('Y') - 1, $m),
                date('Y-m-t', strtotime(sprintf('%s-%02d-01', date('Y') - 1, $m)))
            );
        }
        return ['labels' => $labels, 'jetzt' => $jetzt, 'vorjahr' => $vorjahr];
    }

    /* ------------------------------------------------------ Website --- */

    public static function besuchZaehlen(string $pfad, string $titel = ''): void
    {
        if (!Tenant::gesetzt()) {
            return;
        }
        $referrer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        $host = $referrer !== '' ? (string) (parse_url($referrer, PHP_URL_HOST) ?: '') : '';
        $eigener = (string) ($_SERVER['HTTP_HOST'] ?? '');

        $quelle = 'direkt';
        if ($host !== '' && $host !== $eigener) {
            $quelle = match (true) {
                str_contains($host, 'google')    => 'Google',
                str_contains($host, 'bing')      => 'Bing',
                str_contains($host, 'facebook')  => 'Facebook',
                str_contains($host, 'instagram') => 'Instagram',
                str_contains($host, 'linkedin')  => 'LinkedIn',
                str_contains($host, 'youtube')   => 'YouTube',
                default => $host,
            };
        }
        $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        if (preg_match('/bot|crawler|spider|slurp|preview/i', $ua)) {
            return;
        }

        try {
            Tenant::insert('web_visits', [
                'pfad'     => substr($pfad, 0, 250),
                'titel'    => substr($titel, 0, 190),
                'referrer' => substr($referrer, 0, 250),
                'quelle'   => $quelle,
                'geraet'   => preg_match('/Mobile|Android|iPhone/i', $ua) ? 'mobil'
                            : (preg_match('/iPad|Tablet/i', $ua) ? 'tablet' : 'desktop'),
                'besucher' => Util::besucherSchluessel((string) Tenant::id()),
                'datum'    => Util::heute(),
            ]);
        } catch (Throwable $e) {
            // Zählen darf die Seite nie aufhalten.
        }
    }

    public static function besucher(string $von, string $bis): int
    {
        return DB::int(
            'SELECT COUNT(DISTINCT besucher) FROM web_visits
             WHERE workspace_id = :w AND datum >= :von AND datum <= :bis',
            ['w' => Tenant::id(), 'von' => $von, 'bis' => $bis]
        );
    }

    public static function seitenaufrufe(string $von, string $bis): int
    {
        return Tenant::count('web_visits', 'datum >= :von AND datum <= :bis', ['von' => $von, 'bis' => $bis]);
    }

    /** @return array<int,array{name:string,anzahl:int}> */
    public static function topSeiten(string $von, string $bis, int $limit = 8): array
    {
        $zeilen = DB::all(
            'SELECT pfad, titel, COUNT(*) AS anzahl FROM web_visits
             WHERE workspace_id = :w AND datum >= :von AND datum <= :bis
             GROUP BY pfad ORDER BY anzahl DESC LIMIT ' . (int) $limit,
            ['w' => Tenant::id(), 'von' => $von, 'bis' => $bis]
        );
        return array_map(static fn($z) => [
            'name' => (string) ($z['titel'] ?: $z['pfad']), 'anzahl' => (int) $z['anzahl'],
        ], $zeilen);
    }

    /** @return array<int,array{name:string,anzahl:int}> */
    public static function quellen(string $von, string $bis, int $limit = 6): array
    {
        $zeilen = DB::all(
            'SELECT quelle, COUNT(DISTINCT besucher) AS anzahl FROM web_visits
             WHERE workspace_id = :w AND datum >= :von AND datum <= :bis
             GROUP BY quelle ORDER BY anzahl DESC LIMIT ' . (int) $limit,
            ['w' => Tenant::id(), 'von' => $von, 'bis' => $bis]
        );
        return array_map(static fn($z) => [
            'name' => (string) $z['quelle'], 'anzahl' => (int) $z['anzahl'],
        ], $zeilen);
    }

    public static function besucherVerlauf(string $von, string $bis): array
    {
        $labels = [];
        $werte  = [];
        for ($t = strtotime($von); $t <= strtotime($bis); $t += 86400) {
            $tag = date('Y-m-d', $t);
            $labels[] = date('d.m.', $t);
            $werte[]  = (float) self::besucher($tag, $tag);
        }
        return ['labels' => $labels, 'werte' => $werte];
    }

    /* ------------------------------------------------------- Retention */

    /**
     * Wie viele Kunden aus dem Vorjahr sind noch aktiv? Die ehrlichste
     * Zahl im ganzen System – sie sagt, ob das Geschäft trägt.
     */
    public static function retention(): array
    {
        $vorJahr = date('Y-m-d H:i:s', strtotime('-12 months'));
        $alt = Tenant::count('customers', 'erstellt < :v', ['v' => $vorJahr]);
        if ($alt === 0) {
            return ['quote' => null, 'alt' => 0, 'aktiv' => 0];
        }
        $nochAktiv = DB::int(
            "SELECT COUNT(DISTINCT c.id) FROM customers c
             JOIN bookings b ON b.customer_id = c.id AND b.start >= :halb AND b.status != 'abgesagt'
             WHERE c.workspace_id = :w AND c.erstellt < :v",
            ['w' => Tenant::id(), 'v' => $vorJahr, 'halb' => date('Y-m-d', strtotime('-6 months'))]
        );
        return ['quote' => round($nochAktiv / $alt * 100, 1), 'alt' => $alt, 'aktiv' => $nochAktiv];
    }

    /** Durchschnittlicher Kundenwert über die gesamte Beziehung. */
    public static function kundenwert(): int
    {
        $kunden = Tenant::count('customers');
        if ($kunden === 0) {
            return 0;
        }
        return (int) round(Tenant::sum('orders', 'summe_cent', 'status = "bezahlt"') / $kunden);
    }

    /** @return array<int,array{name:string,wert:int,farbe:string}> Umsatz nach Produktart */
    public static function umsatzNachArt(string $von, string $bis): array
    {
        $farben = ['#0d6b4f', '#1d5fa8', '#b08829', '#6b4ea8', '#b4242b', '#17794f', '#a6640d'];
        $zeilen = DB::all(
            "SELECT p.art, SUM(i.summe_cent) AS summe
             FROM order_items i
             JOIN orders o ON o.id = i.order_id
             LEFT JOIN products p ON p.id = i.product_id
             WHERE i.workspace_id = :w AND o.status = 'bezahlt'
               AND o.bezahlt_am >= :von AND o.bezahlt_am <= :bis
             GROUP BY p.art ORDER BY summe DESC",
            ['w' => Tenant::id(), 'von' => $von . ' 00:00:00', 'bis' => $bis . ' 23:59:59']
        );
        $ergebnis = [];
        foreach ($zeilen as $i => $z) {
            $art = (string) ($z['art'] ?? '');
            $ergebnis[] = [
                'name'  => Commerce::PRODUKT_ARTEN[$art][0] ?? 'Sonstiges',
                'wert'  => (int) $z['summe'],
                'farbe' => $farben[$i % count($farben)],
            ];
        }
        return $ergebnis;
    }
}
