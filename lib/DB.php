<?php
/**
 * DB – schlanker PDO-Wrapper für SQLite (Standard) und MySQL/MariaDB.
 *
 * Zwei Festlegungen, die sich durch das ganze System ziehen:
 *
 *   * Zeitstempel sind Text im Format 'Y-m-d H:i:s'. Damit sortieren und
 *     vergleichen beide Datenbanken gleich, ohne Sonderbehandlung.
 *   * Geldbeträge sind Ganzzahlen in Cent. 399,00 € ist 39900. Fließkomma auf
 *     Preisen erzeugt genau die Rundungsfehler, die der Kunde auf der Rechnung
 *     sieht und die Buchhaltung nicht ausgleichen kann.
 */
final class DB
{
    private static ?PDO $pdo = null;
    private static string $treiber = 'sqlite';
    private static int $tiefe = 0;

    public static function init(?array $cfg = null): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }
        $cfg     = $cfg ?? (array) Config::get('db', []);
        $treiber = (string) ($cfg['driver'] ?? 'sqlite');
        self::$treiber = $treiber === 'mysql' ? 'mysql' : 'sqlite';

        $optionen = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        if (self::$treiber === 'mysql') {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                (string) ($cfg['host'] ?? 'localhost'),
                (int) ($cfg['port'] ?? 3306),
                (string) ($cfg['name'] ?? '')
            );
            self::$pdo = new PDO($dsn, (string) ($cfg['user'] ?? ''), (string) ($cfg['pass'] ?? ''), $optionen);
            self::$pdo->exec("SET sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'");
        } else {
            $pfad = (string) ($cfg['path'] ?? (GP_ROOT . '/data/golfpro.sqlite'));
            if (!is_dir(dirname($pfad))) {
                @mkdir(dirname($pfad), 0750, true);
            }
            self::$pdo = new PDO('sqlite:' . $pfad, null, null, $optionen);
            self::$pdo->exec('PRAGMA journal_mode = WAL');
            self::$pdo->exec('PRAGMA busy_timeout = 10000');
            self::$pdo->exec('PRAGMA foreign_keys = ON');
        }
        return self::$pdo;
    }

    public static function pdo(): PDO
    {
        return self::$pdo ?? self::init();
    }

    public static function treiber(): string
    {
        return self::$treiber;
    }

    public static function istSqlite(): bool
    {
        return self::$treiber === 'sqlite';
    }

    /**
     * Bindet jeden Wert mit seinem Typ.
     *
     * `PDOStatement::execute($werte)` bindet alles als Text. In SQLite ist
     * Text in der Sortierordnung größer als jede Zahl – `spalte >= '60'`
     * liefert dann dauerhaft nichts, ohne dass ein Fehler auftritt. Genau
     * solche Vergleiche stecken in Segmenten, Auswertungen und Filtern.
     * Deshalb wird hier gebunden statt übergeben.
     */
    public static function query(string $sql, array $parameter = []): PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        foreach ($parameter as $name => $wert) {
            $schluessel = is_int($name) ? $name + 1 : ':' . ltrim((string) $name, ':');
            $typ = match (true) {
                is_int($wert)  => PDO::PARAM_INT,
                is_bool($wert) => PDO::PARAM_INT,
                is_null($wert) => PDO::PARAM_NULL,
                default        => PDO::PARAM_STR,
            };
            if (is_bool($wert)) {
                $wert = $wert ? 1 : 0;
            } elseif (is_float($wert)) {
                $wert = (string) $wert;
            }
            $stmt->bindValue($schluessel, $wert, $typ);
        }
        $stmt->execute();
        return $stmt;
    }

    /** Alle Zeilen. */
    public static function all(string $sql, array $parameter = []): array
    {
        return self::query($sql, $parameter)->fetchAll();
    }

    /** Erste Zeile oder null. */
    public static function one(string $sql, array $parameter = []): ?array
    {
        $zeile = self::query($sql, $parameter)->fetch();
        return $zeile === false ? null : $zeile;
    }

    /** Erste Spalte der ersten Zeile. */
    public static function value(string $sql, array $parameter = [], $standard = null)
    {
        $wert = self::query($sql, $parameter)->fetchColumn();
        return $wert === false ? $standard : $wert;
    }

    public static function int(string $sql, array $parameter = [], int $standard = 0): int
    {
        return (int) self::value($sql, $parameter, $standard);
    }

    public static function insert(string $tabelle, array $daten): int
    {
        $spalten = array_keys($daten);
        $sql = 'INSERT INTO ' . $tabelle . ' (' . implode(', ', $spalten) . ') VALUES ('
             . implode(', ', array_map(static fn($s) => ':' . $s, $spalten)) . ')';
        self::query($sql, $daten);
        return (int) self::pdo()->lastInsertId();
    }

    public static function update(string $tabelle, array $daten, string $bedingung, array $parameter = []): int
    {
        $setzen = [];
        foreach (array_keys($daten) as $spalte) {
            $setzen[] = $spalte . ' = :set_' . $spalte;
        }
        $gebunden = [];
        foreach ($daten as $k => $v) {
            $gebunden['set_' . $k] = $v;
        }
        $sql = 'UPDATE ' . $tabelle . ' SET ' . implode(', ', $setzen) . ' WHERE ' . $bedingung;
        return self::query($sql, $gebunden + $parameter)->rowCount();
    }

    public static function delete(string $tabelle, string $bedingung, array $parameter = []): int
    {
        return self::query('DELETE FROM ' . $tabelle . ' WHERE ' . $bedingung, $parameter)->rowCount();
    }

    /**
     * Verschachtelte Aufrufe sind erlaubt: nur die äußerste Klammer schreibt
     * wirklich fest. Das erspart jeder Fachklasse die Frage, ob sie gerade
     * innerhalb einer fremden Transaktion läuft.
     */
    public static function transaktion(callable $arbeit)
    {
        if (self::$tiefe === 0) {
            self::pdo()->beginTransaction();
        }
        self::$tiefe++;
        try {
            $ergebnis = $arbeit();
            self::$tiefe--;
            if (self::$tiefe === 0) {
                self::pdo()->commit();
            }
            return $ergebnis;
        } catch (Throwable $e) {
            self::$tiefe--;
            if (self::$tiefe === 0 && self::pdo()->inTransaction()) {
                self::pdo()->rollBack();
            }
            throw $e;
        }
    }

    public static function tabelleExistiert(string $name): bool
    {
        try {
            self::pdo()->query('SELECT 1 FROM ' . $name . ' LIMIT 1');
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** Nur für Tests und den Installer. */
    public static function schliessen(): void
    {
        self::$pdo = null;
    }
}
