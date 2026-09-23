<?php
/**
 * Plattformzahlen – was das Dashboard und die Auswertung der
 * Betreiberzentrale anzeigen.
 *
 * Jede Zahl hier ist eine Abfrage auf vorhandene Daten. Es gibt keine
 * Schätzung, keine Hochrechnung und keinen Platzhalter, der „erst mal
 * gut aussieht". Wo etwas nicht belegt ist, sagt die Oberfläche das.
 *
 * Demo-Instanzen (`workspaces.demo = 1`) zählen nie mit: Ihre Kunden,
 * Termine und Umsätze sind erfunden, und in einer Plattformzahl wären
 * sie von echten nicht mehr zu unterscheiden. Die Oberfläche nennt die
 * Zahl der ausgelassenen Demo-Instanzen dazu.
 */
final class Plattformzahlen
{
    /** Echte Instanzen, die nicht archiviert sind. */
    private const ECHT = "w.demo = 0 AND w.status != 'archiviert'";

    /** @return array<string,mixed> */
    public static function uebersicht(): array
    {
        $status = array_fill_keys(array_keys(Instanzen::STATUS), 0);
        foreach (DB::all('SELECT status, COUNT(*) AS n FROM workspaces WHERE demo = 0 GROUP BY status') as $z) {
            if (isset($status[$z['status']])) {
                $status[$z['status']] = (int) $z['n'];
            }
        }
        $monat    = date('Y-m-01 00:00:00');
        $naechst  = date('Y-m-01 00:00:00', strtotime('first day of next month'));
        $vormonat = date('Y-m-01 00:00:00', strtotime('first day of last month'));

        $termine = static fn (string $von, string $bis): int => DB::int(
            "SELECT COUNT(*) FROM bookings b JOIN workspaces w ON w.id = b.workspace_id
             WHERE " . self::ECHT . " AND b.status != 'abgesagt' AND b.start >= :a AND b.start < :b",
            ['a' => $von, 'b' => $bis]);

        $vertraege = 0;
        $wert = 0;
        $gekuendigt = 0;
        foreach (self::laufendeAbos() as $abo) {
            $vertraege++;
            $wert += Abos::monatswert($abo);
            if ((string) $abo['status'] === 'gekuendigt') {
                $gekuendigt++;
            }
        }
        $gesamt = array_sum($status) - $status['archiviert'];

        return [
            'gesamt'           => $gesamt,
            'status'           => $status,
            'benutzer'         => DB::int('SELECT COUNT(*) FROM users u JOIN workspaces w ON w.id = u.workspace_id
                                           WHERE ' . self::ECHT . ' AND u.aktiv = 1'),
            'termine_monat'    => $termine($monat, $naechst),
            'termine_vormonat' => $termine($vormonat, $monat),
            'pakete'           => count(Pakete::aktive()),
            'vertragswert'     => $wert,
            'vertraege'        => $vertraege,
            'ohne_vertrag'     => max(0, $gesamt - $vertraege),
            'gekuendigt'       => $gekuendigt,
            'neu_30'           => DB::int('SELECT COUNT(*) FROM workspaces w WHERE ' . self::ECHT . ' AND w.erstellt >= :s',
                                      ['s' => date('Y-m-d H:i:s', strtotime('-30 days'))]),
            'demo'             => DB::int('SELECT COUNT(*) FROM workspaces WHERE demo = 1'),
        ];
    }

    /** @return array<int,array<string,mixed>> die laufende Vertragszeile je echter Instanz */
    private static function laufendeAbos(): array
    {
        return DB::all("SELECT a.* FROM abos a JOIN workspaces w ON w.id = a.workspace_id
                        WHERE " . self::ECHT . " AND a.status != 'beendet'
                        AND a.id = (SELECT MAX(a2.id) FROM abos a2 WHERE a2.workspace_id = a.workspace_id AND a2.status != 'beendet')");
    }

    /**
     * Angelegte Instanzen je Monat und der Bestand am Monatsende.
     *
     * Der Bestand zählt, was heute noch existiert und bis dahin angelegt
     * war. Gelöschte Instanzen fehlen darin – ihre Zeilen gibt es nicht
     * mehr, und nachträglich erfinden lässt sich nichts.
     *
     * @return array{labels:string[],neu:int[],bestand:int[]}
     */
    public static function wachstum(int $monate = 12): array
    {
        $labels = [];
        $neu = [];
        $bestand = [];
        for ($i = $monate - 1; $i >= 0; $i--) {
            [$start, $ende] = self::monat($i);
            $labels[]  = Util::monatName((int) date('n', strtotime($start))) . ' ' . date('y', strtotime($start));
            $neu[]     = DB::int('SELECT COUNT(*) FROM workspaces WHERE demo = 0 AND erstellt >= :a AND erstellt < :b',
                ['a' => $start, 'b' => $ende]);
            $bestand[] = DB::int('SELECT COUNT(*) FROM workspaces WHERE demo = 0 AND erstellt < :b', ['b' => $ende]);
        }
        return ['labels' => $labels, 'neu' => $neu, 'bestand' => $bestand];
    }

    /** @return array{0:string,1:string} Anfang dieses und des nächsten Monats, $zurueck Monate vor heute */
    private static function monat(int $zurueck): array
    {
        $n = (int) date('n') - $zurueck;
        $j = (int) date('Y');
        return [date('Y-m-d H:i:s', mktime(0, 0, 0, $n, 1, $j)), date('Y-m-d H:i:s', mktime(0, 0, 0, $n + 1, 1, $j))];
    }

    /** @return array<string,int> Paketschlüssel => Instanzen */
    public static function paketVerteilung(): array
    {
        $aus = [];
        foreach (DB::all('SELECT w.plan, COUNT(*) AS n FROM workspaces w WHERE ' . self::ECHT
                         . ' GROUP BY w.plan ORDER BY n DESC') as $z) {
            $aus[(string) $z['plan']] = (int) $z['n'];
        }
        return $aus;
    }

    /** @return array<string,int> aktiv|niedrig|inaktiv|nie => Instanzen (nur aktive und Testphase) */
    public static function gesundheit(): array
    {
        $aus = array_fill_keys(array_keys(Instanzen::GESUNDHEIT), 0);
        foreach (DB::all("SELECT letzte_aktivitaet FROM workspaces WHERE demo = 0 AND status IN ('aktiv','test')") as $z) {
            $aus[Instanzen::gesundheit($z['letzte_aktivitaet'] !== null ? (string) $z['letzte_aktivitaet'] : null)]++;
        }
        return $aus;
    }

    /**
     * Was jemand ansehen sollte.
     *
     * @return array<string,array<int,array<string,mixed>>>
     */
    public static function aufmerksamkeit(): array
    {
        $inaktivAb = date('Y-m-d H:i:s', strtotime('-' . Plattform::zahl('inaktiv_tage') . ' days'));
        $wocheAlt  = date('Y-m-d H:i:s', strtotime('-7 days'));
        return [
            'test_endet' => DB::all("SELECT w.id, w.name, w.plan, a.test_bis FROM workspaces w
                JOIN abos a ON a.workspace_id = w.id AND a.status = 'test'
                WHERE w.demo = 0 AND w.status = 'test' AND a.test_bis <= :grenze
                ORDER BY a.test_bis LIMIT 10", ['grenze' => date('Y-m-d 23:59:59', strtotime('+7 days'))]),
            'inaktiv' => DB::all("SELECT w.id, w.name, w.plan, w.letzte_aktivitaet, w.erstellt FROM workspaces w
                WHERE w.demo = 0 AND w.status IN ('aktiv','test')
                  AND ((w.letzte_aktivitaet != '' AND w.letzte_aktivitaet < :ab)
                       OR ((w.letzte_aktivitaet IS NULL OR w.letzte_aktivitaet = '') AND w.erstellt < :woche))
                ORDER BY CASE WHEN w.letzte_aktivitaet IS NULL OR w.letzte_aktivitaet = '' THEN 0 ELSE 1 END,
                         w.letzte_aktivitaet LIMIT 10", ['ab' => $inaktivAb, 'woche' => $wocheAlt]),
            'neu' => DB::all('SELECT w.id, w.name, w.plan, w.status, w.erstellt, w.letzte_aktivitaet FROM workspaces w
                WHERE w.demo = 0 AND w.erstellt >= :woche ORDER BY w.erstellt DESC LIMIT 10', ['woche' => $wocheAlt]),
            'kuendigung' => DB::all("SELECT w.id, w.name, w.plan, a.ende FROM workspaces w
                JOIN abos a ON a.workspace_id = w.id AND a.status = 'gekuendigt'
                WHERE w.demo = 0 AND w.status != 'archiviert' AND a.ende <= :grenze
                ORDER BY a.ende LIMIT 10", ['grenze' => date('Y-m-d 23:59:59', strtotime('+30 days'))]),
        ];
    }

    /**
     * Wie viele echte Instanzen haben welches Modul eingeschaltet – und
     * wie viele benutzen es wirklich? „Wirklich" wird nur dort gezählt,
     * wo es eine eindeutige Spur gibt; sonst bleibt die Spalte leer.
     *
     * @return array<string,array{an:int,genutzt:?int}>
     */
    public static function modulNutzung(): array
    {
        $instanzen = DB::all('SELECT w.id, w.plan FROM workspaces w WHERE ' . self::ECHT);
        $aus = [];
        foreach (array_keys(Module::alle()) as $key) {
            $aus[$key] = ['an' => 0, 'genutzt' => null];
        }
        foreach ($instanzen as $w) {
            foreach (Instanzen::moduleAn((int) $w['id'], (string) $w['plan']) as $key) {
                $aus[$key]['an']++;
            }
        }
        $vor30 = date('Y-m-d H:i:s', strtotime('-30 days'));
        $spuren = [
            'bookings'   => ['SELECT COUNT(DISTINCT b.workspace_id) FROM bookings b JOIN workspaces w ON w.id = b.workspace_id WHERE '
                             . self::ECHT . ' AND b.erstellt >= :s', true],
            'customers'  => ['SELECT COUNT(DISTINCT c.workspace_id) FROM customers c JOIN workspaces w ON w.id = c.workspace_id WHERE '
                             . self::ECHT . ' AND c.erstellt >= :s', true],
            'invoices'   => ['SELECT COUNT(DISTINCT i.workspace_id) FROM invoices i JOIN workspaces w ON w.id = i.workspace_id WHERE '
                             . self::ECHT . ' AND i.erstellt >= :s', true],
            'newsletter' => ["SELECT COUNT(DISTINCT k.workspace_id) FROM campaigns k JOIN workspaces w ON w.id = k.workspace_id WHERE "
                             . self::ECHT . " AND k.status = 'versendet' AND k.erstellt >= :s", true],
            'website'    => ["SELECT COUNT(DISTINCT p.workspace_id) FROM pages p JOIN workspaces w ON w.id = p.workspace_id WHERE "
                             . self::ECHT . " AND p.status = 'veroeffentlicht'", false],
            'leads'      => ['SELECT COUNT(DISTINCT l.workspace_id) FROM leads l JOIN workspaces w ON w.id = l.workspace_id WHERE '
                             . self::ECHT . ' AND l.erstellt >= :s', true],
        ];
        foreach ($spuren as $key => [$sql, $mitZeit]) {
            try {
                $aus[$key]['genutzt'] = DB::int($sql, $mitZeit ? ['s' => $vor30] : []);
            } catch (Throwable $e) {
                $aus[$key]['genutzt'] = null;
            }
        }
        return $aus;
    }

    /** @return array{labels:string[],werte:int[]} Termine je Monat über alle echten Instanzen */
    public static function termineJeMonat(int $monate = 12): array
    {
        $labels = [];
        $werte = [];
        for ($i = $monate - 1; $i >= 0; $i--) {
            [$start, $ende] = self::monat($i);
            $labels[] = Util::monatName((int) date('n', strtotime($start))) . ' ' . date('y', strtotime($start));
            $werte[]  = DB::int("SELECT COUNT(*) FROM bookings b JOIN workspaces w ON w.id = b.workspace_id
                                 WHERE " . self::ECHT . " AND b.status != 'abgesagt' AND b.start >= :a AND b.start < :b",
                ['a' => $start, 'b' => $ende]);
        }
        return ['labels' => $labels, 'werte' => $werte];
    }
}
