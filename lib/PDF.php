<?php
/**
 * PDF – ein kleiner Schreiber für Rechnungen, Gutscheine und Trainingspläne.
 *
 * Warum keine Bibliothek: Ein PDF mit Text, Linien und Flächen in den
 * eingebauten Schriften ist überschaubar – und eine Abhängigkeit, die man
 * per FTP mit hochladen und pflegen müsste, wäre für diesen Zweck ein
 * schlechter Tausch. Wer Bilder oder eigene Schriften braucht, ersetzt
 * diese Klasse, ohne dass der Rest davon erfährt.
 *
 * Maße in Millimetern, Ursprung oben links – so, wie man ein Blatt liest.
 */
final class PDF
{
    private array $seiten = [];
    private string $aktuell = '';
    private float $breite;
    private float $hoehe;

    public function __construct(string $format = 'A4')
    {
        [$this->breite, $this->hoehe] = $format === 'A5' ? [148.0, 210.0] : [210.0, 297.0];
        $this->neueSeite();
    }

    public function neueSeite(): void
    {
        if ($this->aktuell !== '') {
            $this->seiten[] = $this->aktuell;
        }
        $this->aktuell = '';
    }

    /* ------------------------------------------------------- Zeichnen -- */

    public function text(float $x, float $y, string $text, float $groesse = 10, bool $fett = false, array $farbe = [0, 0, 0]): void
    {
        $this->aktuell .= sprintf(
            "BT /F%d %.1f Tf %.3f %.3f %.3f rg %.2f %.2f Td (%s) Tj ET\n",
            $fett ? 2 : 1,
            $groesse,
            $farbe[0] / 255, $farbe[1] / 255, $farbe[2] / 255,
            $this->mm($x),
            $this->mmY($y),
            $this->maskieren($text)
        );
    }

    /** Text rechtsbündig an x. */
    public function textRechts(float $x, float $y, string $text, float $groesse = 10, bool $fett = false, array $farbe = [0, 0, 0]): void
    {
        $this->text($x - $this->textBreite($text, $groesse, $fett), $y, $text, $groesse, $fett, $farbe);
    }

    /** Umbricht Text auf eine Breite und gibt die verbrauchte Höhe zurück. */
    public function absatz(float $x, float $y, float $breite, string $text, float $groesse = 10, float $zeile = 4.6): float
    {
        $zeilen = [];
        foreach (preg_split('/\r?\n/', $text) ?: [] as $rohzeile) {
            $worte = preg_split('/\s+/', trim($rohzeile)) ?: [];
            $puffer = '';
            foreach ($worte as $wort) {
                $versuch = $puffer === '' ? $wort : $puffer . ' ' . $wort;
                if ($this->textBreite($versuch, $groesse) > $breite && $puffer !== '') {
                    $zeilen[] = $puffer;
                    $puffer = $wort;
                } else {
                    $puffer = $versuch;
                }
            }
            $zeilen[] = $puffer;
        }
        foreach ($zeilen as $i => $z) {
            $this->text($x, $y + $i * $zeile, $z, $groesse);
        }
        return count($zeilen) * $zeile;
    }

    public function linie(float $x1, float $y1, float $x2, float $y2, float $staerke = 0.3, array $farbe = [200, 200, 195]): void
    {
        $this->aktuell .= sprintf(
            "%.3f %.3f %.3f RG %.2f w %.2f %.2f m %.2f %.2f l S\n",
            $farbe[0] / 255, $farbe[1] / 255, $farbe[2] / 255, $staerke,
            $this->mm($x1), $this->mmY($y1), $this->mm($x2), $this->mmY($y2)
        );
    }

    public function rechteck(float $x, float $y, float $breite, float $hoehe, array $farbe = [245, 245, 243]): void
    {
        $this->aktuell .= sprintf(
            "%.3f %.3f %.3f rg %.2f %.2f %.2f %.2f re f\n",
            $farbe[0] / 255, $farbe[1] / 255, $farbe[2] / 255,
            $this->mm($x), $this->mmY($y + $hoehe), $this->mm($breite), $this->mm($hoehe)
        );
    }

    public function seitenBreite(): float
    {
        return $this->breite;
    }

    public function seitenHoehe(): float
    {
        return $this->hoehe;
    }

    /* --------------------------------------------------------- Ausgabe - */

    public function ausgabe(): string
    {
        if ($this->aktuell !== '') {
            $this->seiten[] = $this->aktuell;
            $this->aktuell = '';
        }
        $anzahl = count($this->seiten);

        $objekte = [];
        $objekte[1] = "<< /Type /Catalog /Pages 2 0 R >>";

        $kinder = [];
        for ($i = 0; $i < $anzahl; $i++) {
            $kinder[] = (3 + $i * 2) . ' 0 R';
        }
        $objekte[2] = "<< /Type /Pages /Kids [" . implode(' ', $kinder) . "] /Count {$anzahl} >>";

        $schriftObjekt = 3 + $anzahl * 2;
        for ($i = 0; $i < $anzahl; $i++) {
            $seitenNr  = 3 + $i * 2;
            $inhaltNr  = $seitenNr + 1;
            $objekte[$seitenNr] = sprintf(
                "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2f %.2f] "
                . "/Resources << /Font << /F1 %d 0 R /F2 %d 0 R >> >> /Contents %d 0 R >>",
                $this->mm($this->breite), $this->mm($this->hoehe),
                $schriftObjekt, $schriftObjekt + 1, $inhaltNr
            );
            $strom = $this->seiten[$i];
            $objekte[$inhaltNr] = "<< /Length " . strlen($strom) . " >>\nstream\n" . $strom . "endstream";
        }
        $objekte[$schriftObjekt]     = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>";
        $objekte[$schriftObjekt + 1] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>";

        ksort($objekte);
        $pdf = "%PDF-1.4\n";
        $versatz = [];
        foreach ($objekte as $nr => $inhalt) {
            $versatz[$nr] = strlen($pdf);
            $pdf .= $nr . " 0 obj\n" . $inhalt . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $anzahlObjekte = count($objekte) + 1;

        $pdf .= "xref\n0 {$anzahlObjekte}\n0000000000 65535 f \n";
        for ($nr = 1; $nr < $anzahlObjekte; $nr++) {
            $pdf .= sprintf("%010d 00000 n \n", $versatz[$nr] ?? 0);
        }
        $pdf .= "trailer\n<< /Size {$anzahlObjekte} /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";

        return $pdf;
    }

    public function speichern(string $pfad): bool
    {
        if (!is_dir(dirname($pfad))) {
            @mkdir(dirname($pfad), 0750, true);
        }
        return @file_put_contents($pfad, $this->ausgabe()) !== false;
    }

    public function ausliefern(string $dateiname, bool $anhang = false): never
    {
        $daten = $this->ausgabe();
        header('Content-Type: application/pdf');
        header('Content-Disposition: ' . ($anhang ? 'attachment' : 'inline')
             . '; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $dateiname) . '"');
        header('Content-Length: ' . strlen($daten));
        echo $daten;
        exit;
    }

    /* ---------------------------------------------------------- Technik */

    private function mm(float $mm): float
    {
        return $mm * 72 / 25.4;
    }

    private function mmY(float $mm): float
    {
        return ($this->hoehe - $mm) * 72 / 25.4;
    }

    private function maskieren(string $text): string
    {
        $text = @iconv('UTF-8', 'CP1252//TRANSLIT', $text);
        if ($text === false) {
            $text = '';
        }
        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', '', ''], $text);
    }

    /**
     * Breitentabelle für Helvetica – die Zeichenbreiten sind Teil der
     * Schrift, nicht schätzbar. Ohne sie sitzen rechtsbündige Beträge
     * daneben, und genau die liest ein Kunde zuerst.
     */
    private const BREITEN = [
        ' ' => 278, '!' => 278, '"' => 355, '#' => 556, '$' => 556, '%' => 889, '&' => 667,
        "'" => 191, '(' => 333, ')' => 333, '*' => 389, '+' => 584, ',' => 278, '-' => 333,
        '.' => 278, '/' => 278, '0' => 556, '1' => 556, '2' => 556, '3' => 556, '4' => 556,
        '5' => 556, '6' => 556, '7' => 556, '8' => 556, '9' => 556, ':' => 278, ';' => 278,
        '<' => 584, '=' => 584, '>' => 584, '?' => 556, '@' => 1015, 'A' => 667, 'B' => 667,
        'C' => 722, 'D' => 722, 'E' => 667, 'F' => 611, 'G' => 778, 'H' => 722, 'I' => 278,
        'J' => 500, 'K' => 667, 'L' => 556, 'M' => 833, 'N' => 722, 'O' => 778, 'P' => 667,
        'Q' => 778, 'R' => 722, 'S' => 667, 'T' => 611, 'U' => 722, 'V' => 667, 'W' => 944,
        'X' => 667, 'Y' => 667, 'Z' => 611, '[' => 278, '\\' => 278, ']' => 278, '^' => 469,
        '_' => 556, '`' => 333, 'a' => 556, 'b' => 556, 'c' => 500, 'd' => 556, 'e' => 556,
        'f' => 278, 'g' => 556, 'h' => 556, 'i' => 222, 'j' => 222, 'k' => 500, 'l' => 222,
        'm' => 833, 'n' => 556, 'o' => 556, 'p' => 556, 'q' => 556, 'r' => 333, 's' => 500,
        't' => 278, 'u' => 556, 'v' => 500, 'w' => 722, 'x' => 500, 'y' => 500, 'z' => 500,
        '{' => 334, '|' => 260, '}' => 334, '~' => 584,
    ];

    public function textBreite(string $text, float $groesse = 10, bool $fett = false): float
    {
        $summe = 0;
        $zeichen = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($zeichen as $z) {
            $summe += self::BREITEN[$z] ?? 556;
        }
        if ($fett) {
            $summe = (int) round($summe * 1.06);
        }
        return $summe / 1000 * $groesse * 25.4 / 72;
    }
}
