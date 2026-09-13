<?php
/**
 * Config – liest die config.php einmal ein und gibt Werte über Punktpfade aus.
 *
 *   Config::get('db.driver', 'sqlite')
 *   Config::get('stripe.secret_key')
 *
 * Fehlt die config.php, ist das kein Fehler: Der Installer braucht die
 * Plattform genau in diesem Zustand.
 */
final class Config
{
    private static ?array $werte = null;

    public static function load(?string $pfad = null): void
    {
        $pfad = $pfad ?? GP_ROOT . '/config.php';
        if (is_file($pfad)) {
            $daten = require $pfad;
            self::$werte = is_array($daten) ? $daten : [];
        } else {
            self::$werte = [];
        }
    }

    public static function installed(): bool
    {
        if (self::$werte === null) {
            self::load();
        }
        return self::$werte !== [] && (string) self::get('db.driver', '') !== '';
    }

    /** @return mixed */
    public static function get(string $pfad, $standard = null)
    {
        if (self::$werte === null) {
            self::load();
        }
        $wert = self::$werte;
        foreach (explode('.', $pfad) as $teil) {
            if (!is_array($wert) || !array_key_exists($teil, $wert)) {
                return $standard;
            }
            $wert = $wert[$teil];
        }
        return $wert === '' && $standard !== null ? $standard : $wert;
    }

    /** Schreibt eine neue config.php. Wird nur vom Installer benutzt. */
    public static function write(array $werte, ?string $pfad = null): bool
    {
        $pfad  = $pfad ?? GP_ROOT . '/config.php';
        $code  = "<?php\n/* Erzeugt von install.php am " . date('d.m.Y H:i') . ". */\nreturn "
               . self::export($werte, 0) . ";\n";
        $ok    = @file_put_contents($pfad, $code) !== false;
        if ($ok) {
            @chmod($pfad, 0640);
            self::$werte = $werte;
        }
        return $ok;
    }

    private static function export(array $werte, int $tiefe): string
    {
        $ein  = str_repeat('    ', $tiefe + 1);
        $zu   = str_repeat('    ', $tiefe);
        $teil = [];
        foreach ($werte as $k => $v) {
            $schluessel = is_int($k) ? (string) $k : "'" . addslashes((string) $k) . "'";
            if (is_array($v)) {
                $teil[] = $ein . $schluessel . ' => ' . self::export($v, $tiefe + 1);
            } elseif (is_bool($v)) {
                $teil[] = $ein . $schluessel . ' => ' . ($v ? 'true' : 'false');
            } elseif (is_int($v) || is_float($v)) {
                $teil[] = $ein . $schluessel . ' => ' . $v;
            } elseif ($v === null) {
                $teil[] = $ein . $schluessel . ' => null';
            } else {
                $teil[] = $ein . $schluessel . " => '" . addslashes((string) $v) . "'";
            }
        }
        return "[\n" . implode(",\n", $teil) . ",\n" . $zu . ']';
    }
}
