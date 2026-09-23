<?php
/**
 * Plattform – Einstellungen der Betreiberzentrale.
 *
 * Sie stehen in `settings` unter workspace_id 0, wo auch schon die
 * Schemaversion und der Cron-Schlüssel liegen – den Einstellungen der
 * Anlage selbst, die keinem Mandanten gehören. Vorangestellt ist
 * `master_`, damit sie sich mit nichts anderem in die Quere kommen.
 *
 * Nichts davon ist im Code festgeschrieben, was ein Betreiber anders
 * sehen könnte: Ab wann gilt eine Instanz als inaktiv, wie lange dauert
 * eine Testphase, welches Paket ist vorausgewählt.
 */
final class Plattform
{
    /** Schlüssel => [Vorgabe, Untergrenze, Obergrenze] (Obergrenze 0 = Text) */
    public const EINSTELLUNGEN = [
        'aktiv_tage'      => [7, 1, 90],
        'inaktiv_tage'    => [30, 2, 365],
        'test_tage'       => [14, 1, 90],
        'support_minuten' => [60, 5, 240],
        'standard_paket'  => ['starter', 0, 0],
    ];

    private static array $cache = [];

    /** @return int|string */
    public static function einstellung(string $schluessel)
    {
        if (!isset(self::EINSTELLUNGEN[$schluessel])) {
            throw new InvalidArgumentException('Unbekannte Einstellung: ' . $schluessel);
        }
        if (!array_key_exists($schluessel, self::$cache)) {
            $wert = DB::value('SELECT wert FROM settings WHERE workspace_id = 0 AND schluessel = :s',
                ['s' => 'master_' . $schluessel], null);
            [$vorgabe, $min, $max] = self::EINSTELLUNGEN[$schluessel];
            if ($wert === null || $wert === '') {
                $wert = $vorgabe;
            } elseif (is_int($vorgabe)) {
                $wert = (int) Util::klemmen((float) $wert, $min, $max);
            }
            self::$cache[$schluessel] = $wert;
        }
        return self::$cache[$schluessel];
    }

    public static function zahl(string $schluessel): int
    {
        return (int) self::einstellung($schluessel);
    }

    public static function setzen(string $schluessel, $wert): void
    {
        if (!isset(self::EINSTELLUNGEN[$schluessel])) {
            throw new InvalidArgumentException('Unbekannte Einstellung: ' . $schluessel);
        }
        [$vorgabe, $min, $max] = self::EINSTELLUNGEN[$schluessel];
        $text = is_int($vorgabe) ? (string) (int) Util::klemmen((float) $wert, $min, $max) : trim((string) $wert);
        $s = 'master_' . $schluessel;
        if (DB::int('SELECT COUNT(*) FROM settings WHERE workspace_id = 0 AND schluessel = :s', ['s' => $s]) > 0) {
            DB::update('settings', ['wert' => $text], 'workspace_id = 0 AND schluessel = :s', ['s' => $s]);
        } else {
            DB::insert('settings', ['workspace_id' => 0, 'schluessel' => $s, 'wert' => $text]);
        }
        unset(self::$cache[$schluessel]);
    }

    /** @return array<string,int|string> */
    public static function alle(): array
    {
        $aus = [];
        foreach (array_keys(self::EINSTELLUNGEN) as $k) {
            $aus[$k] = self::einstellung($k);
        }
        return $aus;
    }
}
