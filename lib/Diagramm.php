<?php
/**
 * Diagramm – Charts als reines SVG, ohne Bibliothek.
 *
 * Eine Chart-Bibliothek wäre 200 KB für sechs Diagrammformen. Hier wird
 * gerechnet und ein SVG geschrieben: schnell, druckbar, im dunklen Thema
 * korrekt (die Farben kommen aus CSS-Variablen) und ohne Abhängigkeit,
 * die irgendwann nicht mehr gepflegt wird.
 */
final class Diagramm
{
    /** Kleine Verlaufskurve ohne Achsen – für Kennzahl-Kacheln. */
    public static function kurve(array $werte, int $breite = 200, int $hoehe = 40): string
    {
        $werte = array_values(array_map('floatval', $werte));
        $n = count($werte);
        if ($n < 2) {
            return '';
        }
        $min = min($werte);
        $max = max($werte);
        $spanne = $max - $min;
        if ($spanne <= 0) {
            $spanne = max(1.0, abs($max));
            $min = $max - $spanne;
        }
        $rand = 3;
        $nutz = $hoehe - 2 * $rand;

        $punkte = [];
        foreach ($werte as $i => $w) {
            $x = $i / ($n - 1) * $breite;
            $y = $rand + $nutz - (($w - $min) / $spanne) * $nutz;
            $punkte[] = [round($x, 1), round($y, 1)];
        }
        $linie = self::glatt($punkte);
        $flaeche = $linie . ' L ' . $punkte[$n - 1][0] . ' ' . $hoehe . ' L ' . $punkte[0][0] . ' ' . $hoehe . ' Z';
        $ab = $werte[$n - 1] < $werte[0] ? ' kurve--ab' : '';

        return '<svg class="kurve' . $ab . '" viewBox="0 0 ' . $breite . ' ' . $hoehe . '" '
             . 'preserveAspectRatio="none" width="100%" height="' . $hoehe . '" aria-hidden="true">'
             . '<path class="kurve__flaeche" d="' . $flaeche . '"/>'
             . '<path d="' . $linie . '"/></svg>';
    }

    /** Katmull-Rom-artige Glättung – gerade Linien wirken hart und billig. */
    private static function glatt(array $p): string
    {
        $n = count($p);
        $d = 'M ' . $p[0][0] . ' ' . $p[0][1];
        for ($i = 0; $i < $n - 1; $i++) {
            $a = $p[max(0, $i - 1)];
            $b = $p[$i];
            $c = $p[$i + 1];
            $e = $p[min($n - 1, $i + 2)];
            $c1x = $b[0] + ($c[0] - $a[0]) / 6;
            $c1y = $b[1] + ($c[1] - $a[1]) / 6;
            $c2x = $c[0] - ($e[0] - $b[0]) / 6;
            $c2y = $c[1] - ($e[1] - $b[1]) / 6;
            $d .= sprintf(' C %.1f %.1f %.1f %.1f %.1f %.1f', $c1x, $c1y, $c2x, $c2y, $c[0], $c[1]);
        }
        return $d;
    }

    /**
     * Säulendiagramm mit Netz und Beschriftung.
     *
     * @param string[] $labels
     * @param float[]  $werte
     * @param array{hoehe?:int,format?:string,vergleich?:float[],einheit?:string} $o
     */
    public static function saeulen(array $labels, array $werte, array $o = []): string
    {
        $hoehe   = (int) ($o['hoehe'] ?? 220);
        $format  = (string) ($o['format'] ?? 'zahl');   // zahl | geld
        $vergl   = $o['vergleich'] ?? null;
        $n       = count($werte);
        if ($n === 0) {
            return '<div class="leerzustand"><p>Noch keine Daten für diesen Zeitraum.</p></div>';
        }

        $breite  = 720;
        $linksRand = 54;
        $untenRand = 26;
        $obenRand  = 10;
        $nutzB   = $breite - $linksRand - 8;
        $nutzH   = $hoehe - $untenRand - $obenRand;

        $max = max(array_merge([0.0], array_map('floatval', $werte), $vergl ? array_map('floatval', $vergl) : []));
        $max = self::rundeHoch($max);
        $schritt = $nutzB / $n;
        $breiteSaeule = min(46, $schritt * ($vergl ? 0.34 : 0.56));

        $svg = '<svg class="diagramm" viewBox="0 0 ' . $breite . ' ' . $hoehe . '" '
             . 'width="100%" height="' . $hoehe . '" role="img">';

        /* Netzlinien und Achsenbeschriftung */
        for ($i = 0; $i <= 4; $i++) {
            $y = $obenRand + $nutzH - ($i / 4) * $nutzH;
            $wert = $max * $i / 4;
            $svg .= '<line class="netz" x1="' . $linksRand . '" y1="' . round($y, 1)
                  . '" x2="' . ($breite - 8) . '" y2="' . round($y, 1) . '"/>';
            $svg .= '<text x="' . ($linksRand - 8) . '" y="' . round($y + 3.5, 1) . '" text-anchor="end">'
                  . Util::h(self::kurzWert($wert, $format)) . '</text>';
        }

        foreach ($werte as $i => $w) {
            $w = (float) $w;
            $h = $max > 0 ? ($w / $max) * $nutzH : 0;
            $x = $linksRand + $i * $schritt + ($schritt - $breiteSaeule * ($vergl ? 2.15 : 1)) / 2;
            $y = $obenRand + $nutzH - $h;

            if ($vergl !== null) {
                $wv = (float) ($vergl[$i] ?? 0);
                $hv = $max > 0 ? ($wv / $max) * $nutzH : 0;
                $svg .= '<rect class="saeule saeule--nebel" x="' . round($x, 1) . '" y="' . round($obenRand + $nutzH - $hv, 1)
                      . '" width="' . round($breiteSaeule, 1) . '" height="' . round(max(1, $hv), 1) . '" rx="3">'
                      . '<title>' . Util::h(($labels[$i] ?? '') . ' (Vorjahr): ' . self::wert($wv, $format)) . '</title></rect>';
                $x += $breiteSaeule + 3;
            }

            $svg .= '<rect class="saeule" x="' . round($x, 1) . '" y="' . round($y, 1)
                  . '" width="' . round($breiteSaeule, 1) . '" height="' . round(max(1, $h), 1) . '" rx="3">'
                  . '<title>' . Util::h(($labels[$i] ?? '') . ': ' . self::wert($w, $format)) . '</title></rect>';

            if ($n <= 16 || $i % (int) ceil($n / 12) === 0) {
                $svg .= '<text x="' . round($linksRand + $i * $schritt + $schritt / 2, 1) . '" y="'
                      . ($hoehe - 8) . '" text-anchor="middle">' . Util::h((string) ($labels[$i] ?? '')) . '</text>';
            }
        }

        return $svg . '</svg>';
    }

    /**
     * Liniendiagramm, eine oder zwei Reihen.
     *
     * @param string[] $labels
     * @param array<string,float[]> $reihen
     */
    public static function linie(array $labels, array $reihen, array $o = []): string
    {
        $hoehe  = (int) ($o['hoehe'] ?? 230);
        $format = (string) ($o['format'] ?? 'zahl');
        $umgekehrt = (bool) ($o['umgekehrt'] ?? false);  // für HCP: kleiner ist besser

        $erste = reset($reihen);
        if (!$erste || count($erste) < 2) {
            return '<div class="leerzustand"><p>Für eine Kurve braucht es mindestens zwei Messpunkte.</p></div>';
        }

        $breite = 720;
        $linksRand = 54; $untenRand = 26; $obenRand = 12;
        $nutzB = $breite - $linksRand - 12;
        $nutzH = $hoehe - $untenRand - $obenRand;

        $alle = [];
        foreach ($reihen as $werte) {
            foreach ($werte as $w) { $alle[] = (float) $w; }
        }
        $min = min($alle); $max = max($alle);
        $luft = max(0.0001, ($max - $min) * 0.12);
        $min = max(0.0, $min - $luft);
        $max = $max + $luft;
        if ($max - $min < 0.001) { $max = $min + 1; }

        $n = count($erste);
        $xAus = fn(int $i): float => $linksRand + ($n > 1 ? $i / ($n - 1) * $nutzB : 0);
        $yAus = function (float $w) use ($min, $max, $obenRand, $nutzH, $umgekehrt): float {
            $anteil = ($w - $min) / ($max - $min);
            if ($umgekehrt) { $anteil = 1 - $anteil; }
            return $obenRand + $nutzH - $anteil * $nutzH;
        };

        $svg = '<svg class="diagramm" viewBox="0 0 ' . $breite . ' ' . $hoehe . '" width="100%" height="' . $hoehe . '" role="img">';
        $svg .= '<defs><linearGradient id="verlauf-marke" x1="0" y1="0" x2="0" y2="1">'
              . '<stop offset="0%" stop-color="currentColor" stop-opacity=".16"/>'
              . '<stop offset="100%" stop-color="currentColor" stop-opacity="0"/></linearGradient></defs>';

        for ($i = 0; $i <= 4; $i++) {
            $y = $obenRand + $nutzH - ($i / 4) * $nutzH;
            $wert = $umgekehrt ? $max - ($max - $min) * $i / 4 : $min + ($max - $min) * $i / 4;
            $svg .= '<line class="netz" x1="' . $linksRand . '" y1="' . round($y, 1)
                  . '" x2="' . ($breite - 12) . '" y2="' . round($y, 1) . '"/>'
                  . '<text x="' . ($linksRand - 8) . '" y="' . round($y + 3.5, 1) . '" text-anchor="end">'
                  . Util::h(self::kurzWert($wert, $format)) . '</text>';
        }

        $index = 0;
        foreach ($reihen as $name => $werte) {
            $punkte = [];
            foreach (array_values($werte) as $i => $w) {
                $punkte[] = [round($xAus($i), 1), round($yAus((float) $w), 1)];
            }
            $pfad = self::glatt($punkte);
            if ($index === 0) {
                $svg .= '<path class="flaeche" style="color:var(--marke)" d="' . $pfad
                      . ' L ' . $punkte[count($punkte) - 1][0] . ' ' . ($obenRand + $nutzH)
                      . ' L ' . $punkte[0][0] . ' ' . ($obenRand + $nutzH) . ' Z"/>';
            }
            $svg .= '<path class="linie' . ($index > 0 ? ' linie--zwei' : '') . '" d="' . $pfad . '"/>';
            foreach ($punkte as $i => [$x, $y]) {
                if ($n <= 24 || $i === $n - 1) {
                    $svg .= '<circle class="punkt" cx="' . $x . '" cy="' . $y . '" r="3">'
                          . '<title>' . Util::h(($labels[$i] ?? '') . ': ' . self::wert((float) array_values($werte)[$i], $format)) . '</title></circle>';
                }
            }
            $index++;
        }

        $schritt = max(1, (int) ceil($n / 10));
        foreach ($labels as $i => $label) {
            if ($i % $schritt === 0 || $i === $n - 1) {
                $svg .= '<text x="' . round($xAus((int) $i), 1) . '" y="' . ($hoehe - 8) . '" text-anchor="middle">'
                      . Util::h((string) $label) . '</text>';
            }
        }

        return $svg . '</svg>';
    }

    /** Ring für Prozentwerte – Auslastung, Fortschritt, Score. */
    public static function ring(int $prozent, int $groesse = 72, string $farbe = 'var(--marke)'): string
    {
        $p = (int) Util::klemmen((float) $prozent, 0, 100);
        $r = $groesse / 2 - 6;
        $umfang = 2 * M_PI * $r;
        $offset = $umfang * (1 - $p / 100);
        return '<span class="ring" style="width:' . $groesse . 'px;height:' . $groesse . 'px">'
             . '<svg width="' . $groesse . '" height="' . $groesse . '" aria-hidden="true">'
             . '<circle class="ring__kreis" cx="' . ($groesse / 2) . '" cy="' . ($groesse / 2) . '" r="' . $r . '" stroke-width="6"/>'
             . '<circle class="ring__wert" cx="' . ($groesse / 2) . '" cy="' . ($groesse / 2) . '" r="' . $r . '" stroke-width="6" '
             . 'style="stroke:' . Util::attr($farbe) . '" stroke-dasharray="' . round($umfang, 1) . '" '
             . 'stroke-dashoffset="' . round($offset, 1) . '"/></svg>'
             . '<span class="ring__text">' . $p . '<small>%</small></span></span>';
    }

    /**
     * Ringdiagramm mit mehreren Teilen.
     *
     * @param array<int,array{name:string,wert:float,farbe:string}> $teile
     */
    public static function donut(array $teile, int $groesse = 160): string
    {
        $summe = 0.0;
        foreach ($teile as $t) { $summe += (float) $t['wert']; }
        if ($summe <= 0) {
            return '<div class="leerzustand"><p>Noch keine Daten.</p></div>';
        }
        $r = $groesse / 2 - 10;
        $umfang = 2 * M_PI * $r;
        $svg = '<svg width="' . $groesse . '" height="' . $groesse . '" viewBox="0 0 ' . $groesse . ' ' . $groesse . '" role="img">';
        $svg .= '<g transform="rotate(-90 ' . ($groesse / 2) . ' ' . ($groesse / 2) . ')">';
        $bisher = 0.0;
        foreach ($teile as $t) {
            $anteil = (float) $t['wert'] / $summe;
            $laenge = $umfang * $anteil;
            $svg .= '<circle cx="' . ($groesse / 2) . '" cy="' . ($groesse / 2) . '" r="' . $r . '" fill="none" '
                  . 'stroke="' . Util::attr((string) $t['farbe']) . '" stroke-width="14" '
                  . 'stroke-dasharray="' . round($laenge - 2, 1) . ' ' . round($umfang - $laenge + 2, 1) . '" '
                  . 'stroke-dashoffset="' . round(-$umfang * $bisher, 1) . '">'
                  . '<title>' . Util::h((string) $t['name']) . ': ' . Util::prozent($anteil * 100, 1) . '</title></circle>';
            $bisher += $anteil;
        }
        return $svg . '</g></svg>';
    }

    /* ------------------------------------------------------- Formate --- */

    private static function wert(float $w, string $format): string
    {
        return $format === 'geld' ? Util::geld((int) round($w)) : Util::zahl($w, $w == (int) $w ? 0 : 1);
    }

    private static function kurzWert(float $w, string $format): string
    {
        if ($format === 'geld') {
            $euro = $w / 100;
            if (abs($euro) >= 1000) {
                return Util::zahl($euro / 1000, $euro >= 10000 ? 0 : 1) . 'k';
            }
            return Util::zahl($euro, 0);
        }
        if (abs($w) >= 1000) {
            return Util::zahl($w / 1000, 1) . 'k';
        }
        return Util::zahl($w, $w == (int) $w ? 0 : 1);
    }

    /** Sorgt für glatte Achsenwerte statt 4713. */
    private static function rundeHoch(float $max): float
    {
        if ($max <= 0) {
            return 1;
        }
        $stelle = 10 ** floor(log10($max));
        foreach ([1, 1.25, 1.5, 2, 2.5, 3, 4, 5, 7.5, 10] as $f) {
            if ($max <= $stelle * $f) {
                return $stelle * $f;
            }
        }
        return $stelle * 10;
    }
}
