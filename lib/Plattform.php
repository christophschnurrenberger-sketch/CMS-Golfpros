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

        /* Rechnungsabsender – wer die Rechnungen an die Instanzen stellt.
           Pflichtangaben nach § 14 UStG; ohne Firma, Anschrift und
           Steuernummer oder USt-IdNr. lässt sich keine Rechnung ausstellen. */
        'rg_firma'            => ['', 0, 0],
        'rg_inhaber'          => ['', 0, 0],
        'rg_strasse'          => ['', 0, 0],
        'rg_plz'              => ['', 0, 0],
        'rg_ort'              => ['', 0, 0],
        'rg_land'             => ['DE', 0, 0],
        'rg_email'            => ['', 0, 0],
        'rg_telefon'          => ['', 0, 0],
        'rg_web'              => ['', 0, 0],
        'rg_ust_id'           => ['', 0, 0],
        'rg_steuernummer'     => ['', 0, 0],
        'rg_bank'             => ['', 0, 0],
        'rg_praefix'          => ['TP', 0, 0],
        'rg_zahlungsziel'     => [14, 0, 90],
        'rg_steuersatz'       => [19, 0, 30],
        'rg_kleinunternehmer' => [0, 0, 1],
        'rg_text'             => ['', 0, 0],
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
