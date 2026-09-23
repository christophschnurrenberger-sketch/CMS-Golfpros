<?php
/**
 * Abos – Vertragsdaten je Instanz.
 *
 * Was hier steht: welches Paket, seit wann, mit welcher Laufzeit, zu
 * welchem Listenpreis, ob gekündigt. Was hier NICHT steht: Zahlungen.
 * Eine Abrechnung ist noch nicht angeschlossen, und Zahlungsdaten
 * gehören, wenn es so weit ist, in eine eigene Tabelle – ein Vertrag ist
 * nicht dasselbe wie das Geld, das für ihn eingegangen ist.
 *
 * Die laufende Zeile ist die jüngste, die nicht „beendet" ist. Ein
 * Wechsel schließt sie und öffnet eine neue; so entsteht die Geschichte
 * von selbst, ohne dass eine Zeile je überschrieben wird.
 *
 * Instanzen aus der Zeit vor der Betreiberzentrale haben keine Zeile.
 * Das bleibt so, bis ein Betreiber Vertragsdaten einträgt – erfundene
 * Verträge wären erfundener Umsatz.
 */
final class Abos
{
    public const STATUS = [
        'test'       => 'Testphase',
        'aktiv'      => 'Läuft',
        'gekuendigt' => 'Gekündigt',
        'beendet'    => 'Beendet',
    ];

    public const LAUFZEIT = [
        'test'        => 'Testphase',
        'monat'       => 'Monatlich',
        'jahr'        => 'Jährlich',
        'individuell' => 'Individuell',
    ];

    /** Die laufende Vertragszeile oder null. */
    public static function aktuell(int $workspaceId): ?array
    {
        return DB::one("SELECT * FROM abos WHERE workspace_id = :w AND status != 'beendet'
                        ORDER BY id DESC LIMIT 1", ['w' => $workspaceId]);
    }

    /** @return array<int,array<string,mixed>> alle Zeilen, neueste zuerst */
    public static function verlauf(int $workspaceId): array
    {
        return DB::all('SELECT * FROM abos WHERE workspace_id = :w ORDER BY id DESC', ['w' => $workspaceId]);
    }

    /**
     * Prüft und normalisiert Vertragsdaten aus einem Formular.
     *
     * @param array<string,mixed> $e laufzeit, beginn, test_bis, preis_cent, notiz
     * @return array{0:array<string,mixed>,1:string[]}
     */
    public static function pruefen(array $e, string $paket): array
    {
        $fehler = [];
        $laufzeit = (string) ($e['laufzeit'] ?? '');
        if (!isset(self::LAUFZEIT[$laufzeit])) {
            $fehler[] = 'Bitte eine Laufzeit wählen.';
            $laufzeit = 'monat';
        }
        $beginn = (string) ($e['beginn'] ?? '');
        if (!self::istDatum($beginn)) {
            $beginn = date('Y-m-d');
        }
        $testBis = null;
        if ($laufzeit === 'test') {
            $testBis = (string) ($e['test_bis'] ?? '');
            if (!self::istDatum($testBis)) {
                $testBis = date('Y-m-d', strtotime($beginn . ' +' . Plattform::zahl('test_tage') . ' days'));
            }
            if ($testBis <= $beginn) {
                $fehler[] = 'Die Testphase muss nach dem Beginn enden.';
            }
        }
        $p = Pakete::finden($paket);
        $preis = match ($laufzeit) {
            'test'        => 0,
            'monat'       => (int) ($p['preis_monat_cent'] ?? 0),
            'jahr'        => (int) ($p['preis_jahr_cent'] ?? 0),
            'individuell' => max(0, (int) ($e['preis_cent'] ?? 0)),
        };
        if ($laufzeit === 'jahr' && $preis === 0) {
            $fehler[] = 'Für dieses Paket ist kein Jahrespreis festgelegt. Trag ihn unter Pakete ein '
                      . 'oder wähle „Individuell".';
        }
        return [[
            'laufzeit'   => $laufzeit,
            'status'     => $laufzeit === 'test' ? 'test' : 'aktiv',
            'preis_cent' => $preis,
            'beginn'     => $beginn . ' 00:00:00',
            'test_bis'   => $testBis !== null ? $testBis . ' 23:59:59' : null,
            'notiz'      => mb_substr(trim((string) ($e['notiz'] ?? '')), 0, 500),
        ], $fehler];
    }

    /**
     * Schließt die laufende Zeile und öffnet eine neue.
     *
     * @param array<string,mixed> $daten aus pruefen()
     */
    public static function beginnen(int $workspaceId, string $paket, array $daten, string $von): int
    {
        return DB::transaktion(static function () use ($workspaceId, $paket, $daten, $von) {
            $alt = self::aktuell($workspaceId);
            if ($alt !== null) {
                DB::update('abos', ['status' => 'beendet', 'ende' => Util::jetzt()], 'id = :id', ['id' => (int) $alt['id']]);
            }
            return DB::insert('abos', [
                'workspace_id' => $workspaceId,
                'paket'        => $paket,
                'status'       => $daten['status'],
                'laufzeit'     => $daten['laufzeit'],
                'preis_cent'   => (int) $daten['preis_cent'],
                'beginn'       => $daten['beginn'],
                'test_bis'     => $daten['test_bis'],
                'ende'         => null,
                'gekuendigt'   => null,
                'notiz'        => $daten['notiz'],
                'erstellt'     => Util::jetzt(),
                'erstellt_von' => mb_substr($von, 0, 190),
            ]);
        });
    }

    /**
     * Kündigung vormerken. Die Instanz läuft bis zum Ende weiter; was
     * danach passiert, entscheidet ein Betreiber – es gibt keine
     * Abrechnung, die das Ende selbst durchsetzen könnte.
     */
    public static function kuendigen(int $workspaceId, string $zum, string $grund = ''): bool
    {
        $abo = self::aktuell($workspaceId);
        if ($abo === null || !self::istDatum($zum)) {
            return false;
        }
        DB::update('abos', ['status' => 'gekuendigt', 'gekuendigt' => Util::jetzt(), 'ende' => $zum . ' 23:59:59'],
            'id = :id', ['id' => (int) $abo['id']]);
        Betreiberlog::schreiben('SUBSCRIPTION_CHANGED', [
            'objekt' => 'abo', 'objekt_id' => (int) $abo['id'], 'instanz_id' => $workspaceId,
            'vorher' => ['status' => $abo['status']], 'nachher' => ['status' => 'gekuendigt', 'ende' => $zum],
            'grund' => $grund, 'beschreibung' => 'Kündigung zum ' . Util::datum($zum),
        ]);
        return true;
    }

    /**
     * Monatlicher Gegenwert zum Listenpreis, in Cent.
     *
     * Ein Jahresvertrag zählt mit einem Zwölftel, eine Testphase mit
     * nichts. Das ist ein Vertragswert, kein Umsatz: Ob das Geld je
     * eingegangen ist, weiß dieses System nicht.
     */
    public static function monatswert(?array $abo): int
    {
        if ($abo === null || !in_array((string) $abo['status'], ['aktiv', 'gekuendigt'], true)) {
            return 0;
        }
        $preis = (int) $abo['preis_cent'];
        return match ((string) $abo['laufzeit']) {
            'jahr'  => (int) round($preis / 12),
            'test'  => 0,
            default => $preis,
        };
    }

    /**
     * Der Vertrag in einer Zeile: „Monatlich · 29,00 € / Monat".
     *
     * Steht hier und nicht in einer Vorlage, weil ihn zwei Seiten zeigen,
     * die sich keine Helfer teilen – die Betreiberzentrale und die Seite
     * „Konto & Abrechnung" in der Instanz. Zwei Fassungen liefen
     * auseinander, und dann stünde beim Kunden etwas anderes als beim
     * Betreiber.
     */
    public static function beschreibung(?array $abo, string $leer = 'Keine Vertragsdaten erfasst'): string
    {
        if ($abo === null) {
            return $leer;
        }
        $teile = [self::LAUFZEIT[$abo['laufzeit']] ?? (string) $abo['laufzeit']];
        if ((string) $abo['laufzeit'] === 'test' && $abo['test_bis']) {
            $tage = Util::tageBis((string) $abo['test_bis']);
            $teile[] = $tage >= 0 ? 'endet am ' . Util::datum((string) $abo['test_bis']) . ' (in ' . $tage . ' ' . ($tage === 1 ? 'Tag' : 'Tagen') . ')'
                                  : 'seit ' . Util::datum((string) $abo['test_bis']) . ' abgelaufen';
        } elseif ((int) $abo['preis_cent'] > 0) {
            $teile[] = Util::geld((int) $abo['preis_cent']) . ((string) $abo['laufzeit'] === 'jahr' ? ' / Jahr' : ' / Monat');
        } elseif ((string) $abo['laufzeit'] !== 'test') {
            $teile[] = 'ohne Berechnung';
        }
        if ((string) $abo['status'] === 'gekuendigt') {
            $teile[] = 'gekündigt zum ' . Util::datum((string) $abo['ende']);
        }
        return implode(' · ', $teile);
    }

    private static function istDatum(string $d): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;
    }
}
