<?php
/**
 * Gamification – Punkte, Stufen, Serien, Abzeichen.
 *
 * Bewusst zurückhaltend: Punkte gibt es für Dinge, die tatsächlich mit
 * Training zu tun haben – buchen, erscheinen, Runde erfassen, Kurs
 * abschließen. Nicht fürs Einloggen. Ein Belohnungssystem, das Anwesenheit
 * statt Fortschritt belohnt, verliert binnen Wochen seine Glaubwürdigkeit.
 */
final class Gamification
{
    /** Für Stufe n werden n × 250 Punkte benötigt – linear und nachvollziehbar. */
    private const PRO_STUFE = 250;

    public static function stand(int $kundeId): array
    {
        $z = Tenant::one('customer_gamification', 'customer_id = :k', ['k' => $kundeId]);
        if (!$z) {
            $id = Tenant::insert('customer_gamification', [
                'customer_id' => $kundeId, 'xp' => 0, 'level' => 1, 'streak' => 0, 'bester_streak' => 0,
            ]);
            $z = Tenant::find('customer_gamification', $id) ?? [];
        }
        $xp = (int) ($z['xp'] ?? 0);
        $stufe = max(1, (int) floor($xp / self::PRO_STUFE) + 1);
        return [
            'xp'             => $xp,
            'level'          => $stufe,
            'streak'         => (int) ($z['streak'] ?? 0),
            'bester_streak'  => (int) ($z['bester_streak'] ?? 0),
            'bis_naechste'   => $stufe * self::PRO_STUFE - $xp,
            'fortschritt'    => (int) round((($xp % self::PRO_STUFE) / self::PRO_STUFE) * 100),
        ];
    }

    public static function punkte(int $kundeId, int $xp, string $grund = ''): void
    {
        if ($kundeId <= 0 || !Tenant::modul('community')) {
            return;
        }
        $stand = self::stand($kundeId);
        $neuXp = $stand['xp'] + $xp;
        $heute = Util::heute();

        $z = Tenant::one('customer_gamification', 'customer_id = :k', ['k' => $kundeId]);
        $letzte = (string) ($z['letzte_aktivitaet'] ?? '');
        $streak = (int) ($z['streak'] ?? 0);

        if ($letzte === date('Y-m-d', strtotime('-1 day'))) {
            $streak++;
        } elseif ($letzte !== $heute) {
            $streak = 1;
        }

        Tenant::updateWhere('customer_gamification', [
            'xp'                => $neuXp,
            'level'             => max(1, (int) floor($neuXp / self::PRO_STUFE) + 1),
            'streak'            => $streak,
            'bester_streak'     => max($streak, (int) ($z['bester_streak'] ?? 0)),
            'letzte_aktivitaet' => $heute,
        ], 'customer_id = :k', ['k' => $kundeId]);

        self::abzeichenPruefen($kundeId);
    }

    /** @return array<int,array<string,mixed>> */
    public static function abzeichen(int $kundeId): array
    {
        return DB::all(
            'SELECT b.*, cb.erhalten FROM badges b
             LEFT JOIN customer_badges cb ON cb.badge_id = b.id AND cb.customer_id = :k
             WHERE b.workspace_id = :w ORDER BY cb.erhalten IS NULL, b.schwelle',
            ['k' => $kundeId, 'w' => Tenant::id()]
        );
    }

    private static function abzeichenPruefen(int $kundeId): void
    {
        $stand = self::stand($kundeId);
        $termine = Tenant::count('bookings', "customer_id = :k AND status IN ('bestaetigt','erschienen')", ['k' => $kundeId]);
        $runden  = Tenant::count('performance_entries', 'customer_id = :k', ['k' => $kundeId]);
        $kurse   = Tenant::count('course_enrollments', 'customer_id = :k AND abgeschlossen IS NOT NULL', ['k' => $kundeId]);

        foreach (Tenant::all('badges') as $b) {
            $wert = match ((string) $b['kriterium']) {
                'termine' => $termine,
                'runden'  => $runden,
                'kurse'   => $kurse,
                'streak'  => $stand['bester_streak'],
                'xp'      => $stand['xp'],
                default   => 0,
            };
            if ($wert < (int) $b['schwelle']) {
                continue;
            }
            if (Tenant::count('customer_badges', 'customer_id = :k AND badge_id = :b',
                ['k' => $kundeId, 'b' => (int) $b['id']]) > 0) {
                continue;
            }
            Tenant::insert('customer_badges', [
                'customer_id' => $kundeId, 'badge_id' => (int) $b['id'], 'erhalten' => Util::jetzt(),
            ]);
            Notify::senden('customer', 'Abzeichen erreicht: ' . $b['name'],
                Customers::nameVonId($kundeId) . ' hat „' . $b['name'] . '“ freigeschaltet.',
                '/app/kunde.php?id=' . $kundeId);
        }
    }

    /** @return array<int,array<string,mixed>> */
    public static function rangliste(int $limit = 10): array
    {
        return DB::all(
            "SELECT g.*, c.vorname, c.nachname FROM customer_gamification g
             JOIN customers c ON c.id = g.customer_id
             WHERE g.workspace_id = :w AND c.status = 'aktiv'
             ORDER BY g.xp DESC LIMIT " . (int) $limit,
            ['w' => Tenant::id()]
        );
    }

    public static function standardAbzeichen(): array
    {
        return [
            ['Erste Stunde', 'Die erste Trainingsstunde absolviert.', '🏌', 'termine', 1, 50],
            ['Zehn Stunden', 'Zehn Trainingseinheiten gesammelt.', '🎯', 'termine', 10, 150],
            ['Fünfzig Stunden', 'Fünfzig Einheiten – da steckt Arbeit drin.', '🏆', 'termine', 50, 400],
            ['Erste Runde erfasst', 'Die erste Runde dokumentiert.', '📊', 'runden', 1, 40],
            ['Zehn Runden', 'Zehn Runden mit Statistik.', '📈', 'runden', 10, 160],
            ['7-Tage-Serie', 'Sieben Tage in Folge aktiv.', '🔥', 'streak', 7, 200],
            ['30-Tage-Serie', 'Einen ganzen Monat am Ball geblieben.', '⚡', 'streak', 30, 500],
            ['Erster Kurs', 'Einen Online-Kurs abgeschlossen.', '🎓', 'kurse', 1, 200],
        ];
    }

    public static function standardAnlegen(): void
    {
        if (Tenant::count('badges') > 0) {
            return;
        }
        foreach (self::standardAbzeichen() as [$name, $text, $symbol, $kriterium, $schwelle, $xp]) {
            Tenant::insert('badges', [
                'name' => $name, 'beschreibung' => $text, 'symbol' => $symbol,
                'kriterium' => $kriterium, 'schwelle' => $schwelle, 'xp' => $xp,
            ]);
        }
    }
}
