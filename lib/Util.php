<?php
/**
 * Util – die kleinen Werkzeuge, die überall gebraucht werden.
 *
 * Gerundet wird ausschließlich hier. Wer anderswo im System auf Cent rechnet,
 * ruft diese Klasse – sonst laufen zwei Rechenwege irgendwann um einen Cent
 * auseinander, und das sieht der Kunde zuerst.
 */
final class Util
{
    /**
     * Setzt Tag und Uhrzeit zu einem Zeitpunkt zusammen - oder zu nichts.
     *
     * Der Rueckgabewert '' ist der eigentliche Zweck. Wer die beiden Teile
     * einfach mit einem Leerzeichen aneinanderhaengt, bekommt aus einem
     * fehlenden Datum " 17:45:00", und strtotime() macht daraus
     * klaglos *heute* um 17:45. Der Fehler faellt dann nicht auf, sondern
     * legt einen Termin am falschen Tag an - oder scheitert an einer
     * Kollision, die niemand versteht, weil auf dem Bildschirm ein ganz
     * anderer Tag steht.
     *
     * @param  string $datum 'JJJJ-MM-TT'
     * @param  string $zeit  'HH:MM' oder 'HH:MM:SS'
     * @return string 'JJJJ-MM-TT HH:MM:SS', leer wenn etwas nicht stimmt
     */
    public static function zeitpunkt(string $datum, string $zeit): string
    {
        $datum = trim($datum);
        $zeit  = trim($zeit);
        if ($datum === '' || $zeit === '') {
            return '';
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $datum)) {
            return '';
        }
        if (!preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $zeit, $teile)) {
            return '';
        }
        [$jahr, $monat, $tag] = array_map('intval', explode('-', $datum));
        if (!checkdate($monat, $tag, $jahr)) {
            return '';
        }
        $stunde = (int) $teile[1];
        $minute = (int) $teile[2];
        $sekunde = (int) ($teile[3] ?? 0);
        if ($stunde > 23 || $minute > 59 || $sekunde > 59) {
            return '';
        }
        return sprintf('%s %02d:%02d:%02d', $datum, $stunde, $minute, $sekunde);
    }

    /* ---------------------------------------------------------- Ausgabe */

    /** Escaping für HTML. Kurz, weil es auf jeder Seite hundertfach vorkommt. */
    public static function h(?string $text): string
    {
        return htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function attr(?string $text): string
    {
        return self::h($text);
    }

    /* ------------------------------------------------------------- Geld */

    /** 39900 → "399,00 €" */
    public static function geld(int $cent, string $waehrung = '€'): string
    {
        $vorzeichen = $cent < 0 ? '−' : '';
        $cent = abs($cent);
        return $vorzeichen . number_format($cent / 100, 2, ',', '.') . ' ' . $waehrung;
    }

    /** 39900 → "399 €" (ohne Nachkommastellen, wenn glatt) */
    public static function geldKurz(int $cent, string $waehrung = '€'): string
    {
        if ($cent % 100 === 0) {
            return number_format($cent / 100, 0, ',', '.') . ' ' . $waehrung;
        }
        return self::geld($cent, $waehrung);
    }

    /** "399,00" oder "399.00" oder "399 €" → 39900 */
    public static function centAus(string $eingabe): int
    {
        $roh = preg_replace('/[^0-9,.\-]/', '', $eingabe) ?? '';
        if ($roh === '') {
            return 0;
        }
        // Letztes Trennzeichen entscheidet: "1.299,50" und "1,299.50" gehen beide.
        $komma = strrpos($roh, ',');
        $punkt = strrpos($roh, '.');
        $trenn = max($komma === false ? -1 : $komma, $punkt === false ? -1 : $punkt);
        if ($trenn >= 0 && strlen($roh) - $trenn - 1 <= 2) {
            $ganz = preg_replace('/[^0-9\-]/', '', substr($roh, 0, $trenn)) ?? '0';
            $rest = preg_replace('/[^0-9]/', '', substr($roh, $trenn + 1)) ?? '0';
            $rest = str_pad(substr($rest, 0, 2), 2, '0');
            $minus = str_starts_with(trim($eingabe), '-') || str_contains($ganz, '-');
            $wert = (int) (preg_replace('/[^0-9]/', '', $ganz) ?: '0') * 100 + (int) $rest;
            return $minus ? -$wert : $wert;
        }
        return (int) (preg_replace('/[^0-9\-]/', '', $roh) ?: '0') * 100;
    }

    /**
     * Deutsche Dezimalschreibweise zu float: „13,6" → 13.6.
     *
     * PHPs eigener Cast hört beim Komma auf und macht aus „13,6" eine 13 –
     * still und ohne Fehler. Genau das hat einmal eine ganze
     * Handicap-Kurve flachgelegt. Deshalb geht jede Zahl, die aus einem
     * Formular oder aus der Datenbank kommt, durch diese Funktion.
     */
    public static function zahlAus(?string $eingabe, float $standard = 0.0): float
    {
        $roh = trim((string) $eingabe);
        if ($roh === '') {
            return $standard;
        }
        $roh = str_replace([' ', "\u{00a0}"], '', $roh);
        // Tausenderpunkte fallen weg, das Komma wird zum Punkt.
        if (str_contains($roh, ',')) {
            $roh = str_replace('.', '', $roh);
            $roh = str_replace(',', '.', $roh);
        }
        $roh = preg_replace('/[^0-9.\-]/', '', $roh) ?? '';
        return $roh === '' || $roh === '-' ? $standard : (float) $roh;
    }

    /**
     * Ein Handicap für die Datenbank: immer mit Punkt, immer eine
     * Nachkommastelle. Leere Eingabe bleibt leer – „kein Handicap" ist
     * etwas anderes als „Handicap 0".
     */
    public static function hcpNormal(?string $eingabe): string
    {
        return trim((string) $eingabe) === '' ? '' : number_format(self::zahlAus($eingabe), 1, '.', '');
    }

    /** Dasselbe Handicap für die Anzeige: „13.6" → „13,6". */
    public static function hcp(?string $wert): string
    {
        return trim((string) $wert) === '' ? '–' : str_replace('.', ',', number_format(self::zahlAus($wert), 1, '.', ''));
    }

    /**
     * Verteilt einen Betrag proportional, ohne dass Cent verloren gehen.
     * Der Rest wandert an die Positionen mit dem größten Rundungsverlust.
     *
     * @param  int[] $gewichte
     * @return int[]
     */
    public static function verteilen(int $betrag, array $gewichte): array
    {
        $summe = array_sum($gewichte);
        if ($summe <= 0) {
            return array_fill(0, count($gewichte), 0);
        }
        $anteile = [];
        $reste   = [];
        $vergeben = 0;
        foreach ($gewichte as $i => $gewicht) {
            $exakt      = $betrag * $gewicht / $summe;
            $anteile[$i] = (int) floor($exakt);
            $reste[$i]   = $exakt - $anteile[$i];
            $vergeben   += $anteile[$i];
        }
        arsort($reste);
        $offen = $betrag - $vergeben;
        foreach (array_keys($reste) as $i) {
            if ($offen <= 0) {
                break;
            }
            $anteile[$i]++;
            $offen--;
        }
        ksort($anteile);
        return $anteile;
    }

    /* ------------------------------------------------------------ Datum */

    public static function jetzt(): string
    {
        return date('Y-m-d H:i:s');
    }

    public static function heute(): string
    {
        return date('Y-m-d');
    }

    private const MONATE = [
        1 => 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni',
        'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember',
    ];
    private const TAGE = ['Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag'];

    public static function datum(?string $zeit, bool $mitJahr = true): string
    {
        if (!$zeit) {
            return '—';
        }
        $t = strtotime($zeit);
        return $t === false ? '—' : date($mitJahr ? 'd.m.Y' : 'd.m.', $t);
    }

    public static function datumZeit(?string $zeit): string
    {
        if (!$zeit) {
            return '—';
        }
        $t = strtotime($zeit);
        return $t === false ? '—' : date('d.m.Y, H:i', $t) . ' Uhr';
    }

    public static function uhrzeit(?string $zeit): string
    {
        if (!$zeit) {
            return '—';
        }
        $t = strtotime($zeit);
        return $t === false ? '—' : date('H:i', $t);
    }

    public static function datumLang(?string $zeit): string
    {
        if (!$zeit) {
            return '—';
        }
        $t = strtotime($zeit);
        if ($t === false) {
            return '—';
        }
        return self::TAGE[(int) date('w', $t)] . ', ' . (int) date('j', $t) . '. '
             . self::MONATE[(int) date('n', $t)] . ' ' . date('Y', $t);
    }

    public static function monatName(int $monat): string
    {
        return self::MONATE[$monat] ?? '';
    }

    public static function tagKurz(string $zeit): string
    {
        $t = strtotime($zeit);
        return $t === false ? '' : substr(self::TAGE[(int) date('w', $t)], 0, 2);
    }

    /** "vor 3 Tagen", "in 2 Stunden", "gerade eben" */
    public static function relativ(?string $zeit): string
    {
        if (!$zeit) {
            return '—';
        }
        $t = strtotime($zeit);
        if ($t === false) {
            return '—';
        }
        $diff = time() - $t;
        $zukunft = $diff < 0;
        $diff = abs($diff);
        $stufen = [
            [60, 1, 'Sekunde', 'Sekunden'],
            [3600, 60, 'Minute', 'Minuten'],
            [86400, 3600, 'Stunde', 'Stunden'],
            [2592000, 86400, 'Tag', 'Tagen'],
            [31536000, 2592000, 'Monat', 'Monaten'],
            [PHP_INT_MAX, 31536000, 'Jahr', 'Jahren'],
        ];
        if ($diff < 45) {
            return $zukunft ? 'gleich' : 'gerade eben';
        }
        foreach ($stufen as [$grenze, $teiler, $eins, $viele]) {
            if ($diff < $grenze) {
                $n = max(1, (int) round($diff / $teiler));
                $wort = $n === 1 ? $eins : $viele;
                if ($n === 1 && !$zukunft) {
                    $wort = ['Sekunde' => 'Sekunde', 'Minute' => 'Minute', 'Stunde' => 'Stunde',
                             'Tag' => 'Tag', 'Monat' => 'Monat', 'Jahr' => 'Jahr'][$eins];
                }
                return $zukunft ? "in {$n} {$wort}" : "vor {$n} {$wort}";
            }
        }
        return self::datum($zeit);
    }

    /** Ganze Tage zwischen heute und dem Zeitpunkt; negativ = Vergangenheit. */
    public static function tageBis(?string $zeit): int
    {
        if (!$zeit) {
            return 0;
        }
        $t = strtotime($zeit);
        return $t === false ? 0 : (int) floor(($t - time()) / 86400);
    }

    public static function tageSeit(?string $zeit): int
    {
        return -self::tageBis($zeit);
    }

    /** Minuten seit Mitternacht → "17:30" */
    public static function minutenZuZeit(int $minuten): string
    {
        return sprintf('%02d:%02d', intdiv($minuten, 60), $minuten % 60);
    }

    public static function zeitZuMinuten(string $zeit): int
    {
        [$h, $m] = array_pad(explode(':', $zeit), 2, '0');
        return ((int) $h) * 60 + (int) $m;
    }

    /* ------------------------------------------------------------ Texte */

    public static function slug(string $text, int $max = 80): string
    {
        $text = strtr($text, [
            'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue',
            'ß' => 'ss', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'á' => 'a', 'à' => 'a',
            'â' => 'a', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'í' => 'i', 'ì' => 'i',
            'ú' => 'u', 'ù' => 'u', 'ç' => 'c', 'ñ' => 'n', 'å' => 'a', 'ø' => 'o',
        ]);
        $text = strtolower(trim($text));
        $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? '';
        $text = trim($text, '-');
        if (strlen($text) > $max) {
            $text = rtrim(substr($text, 0, $max), '-');
        }
        return $text !== '' ? $text : 'seite';
    }

    public static function kuerzen(?string $text, int $laenge = 120): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $text)) ?? '');
        if (mb_strlen($text) <= $laenge) {
            return $text;
        }
        return rtrim(mb_substr($text, 0, $laenge - 1)) . '…';
    }

    /** "Christoph Schnurrenberger" → "CS" */
    public static function initialen(string $name): string
    {
        $teile = preg_split('/\s+/u', trim($name)) ?: [];
        $teile = array_values(array_filter($teile));
        if ($teile === []) {
            return '?';
        }
        if (count($teile) === 1) {
            return mb_strtoupper(mb_substr($teile[0], 0, 2));
        }
        return mb_strtoupper(mb_substr($teile[0], 0, 1) . mb_substr($teile[count($teile) - 1], 0, 1));
    }

    /**
     * Stabile Farbe zu einem Namen. Damit bekommt jeder Kunde ohne Bild
     * dasselbe Avatar – wiedererkennbar und ohne gespeichertes Feld.
     */
    public static function avatarFarbe(string $text): string
    {
        $paletten = ['#3b6ef6', '#7c5cff', '#0ea5a5', '#e0663c', '#d4405f',
                     '#2f9e55', '#8a6d3b', '#4a5568', '#b8862d', '#5b7fa6'];
        return $paletten[abs(crc32($text)) % count($paletten)];
    }

    public static function einfachesMarkdown(string $text): string
    {
        $html = self::h($text);
        $html = preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $html) ?? $html;
        $html = preg_replace('/\*(.+?)\*/u', '<em>$1</em>', $html) ?? $html;
        $html = preg_replace('/`(.+?)`/u', '<code>$1</code>', $html) ?? $html;
        return nl2br($html);
    }

    /* ------------------------------------------------------------ Zahlen */

    public static function zahl(float $zahl, int $nachkomma = 0): string
    {
        return number_format($zahl, $nachkomma, ',', '.');
    }

    public static function prozent(float $anteil, int $nachkomma = 0): string
    {
        return self::zahl($anteil, $nachkomma) . ' %';
    }

    /** Wachstum in Prozent, mit Vorzeichen. Division durch Null ergibt null. */
    public static function wachstum(float $neu, float $alt): ?float
    {
        if ($alt == 0.0) {
            return $neu > 0 ? null : 0.0;
        }
        return ($neu - $alt) / $alt * 100;
    }

    /* ------------------------------------------------------------ Technik */

    public static function token(int $bytes = 16): string
    {
        return bin2hex(random_bytes($bytes));
    }

    /** Kurzer, gut vorlesbarer Code – ohne 0/O und 1/I. */
    public static function code(int $laenge = 8, string $trenner = '-', int $block = 4): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = '';
        for ($i = 0; $i < $laenge; $i++) {
            if ($i > 0 && $block > 0 && $i % $block === 0) {
                $code .= $trenner;
            }
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $code;
    }

    public static function json($wert): string
    {
        return (string) json_encode($wert, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function ausJson(?string $json, array $standard = []): array
    {
        if (!$json) {
            return $standard;
        }
        $daten = json_decode($json, true);
        return is_array($daten) ? $daten : $standard;
    }

    public static function ip(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    }

    /** Datenschutzfreundlicher Besucher-Schlüssel: IP wird nie gespeichert. */
    public static function besucherSchluessel(string $salz): string
    {
        return substr(hash('sha256', $salz . '|' . self::ip() . '|' . ($_SERVER['HTTP_USER_AGENT'] ?? '') . '|' . date('Y-m-d')), 0, 32);
    }

    public static function bytes(int $bytes): string
    {
        $einheiten = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $wert = (float) $bytes;
        while ($wert >= 1024 && $i < count($einheiten) - 1) {
            $wert /= 1024;
            $i++;
        }
        return self::zahl($wert, $i > 1 ? 1 : 0) . ' ' . $einheiten[$i];
    }

    public static function klemmen(float $wert, float $min, float $max): float
    {
        return max($min, min($max, $wert));
    }
}
