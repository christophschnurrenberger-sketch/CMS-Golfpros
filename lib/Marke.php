<?php
/**
 * Marke – Name, Zeichen und Claim von TeePilot an einer Stelle.
 *
 * Vorher stand der Name als Zeichenkette in elf Dateien und das Zeichen
 * war ein Buchstabe in einem Farbverlauf. Beim nächsten Namenswechsel
 * hätte man wieder alle elf gesucht – und eine übersehen.
 *
 * Das Zeichen ist ein Punkt und eine Linie: der Ball auf dem Tee und die
 * Bahn, die er nimmt. Dieselbe Form liest sich auf einer Karte als
 * Standort mit geplanter Route. Beides stimmt, und beides meint dasselbe.
 *
 * Alle Formen liegen als Pfade vor, nicht als Bilddatei: Sie erben damit
 * die Textfarbe (`currentColor`), skalieren verlustfrei und kosten keine
 * zweite Anfrage an den Server.
 */
final class Marke
{
    public const NAME  = 'TeePilot';
    public const CLAIM = 'Your digital golf presence, under control.';
    public const CLAIM_DE = 'Dein Auftritt. In deiner Hand.';

    /** Pine – der Grund der Marke. Steht auch in app.css als --pine. */
    public const PINE   = '#0b2b22';
    /** Fairway – Knöpfe, Links, aktive Zustände. */
    public const GRUEN  = '#12513f';
    /** Signal – Akzent, nur als Fläche auf Dunkel. */
    public const SIGNAL = '#c3e35c';

    /**
     * Die Bildmarke: Punkt und Bogen, in der Farbe des Elternelements.
     *
     * Unter 18 Pixeln nicht verwenden – dann nimmt man das Monogramm.
     */
    public static function zeichen(int $groesse = 28): string
    {
        return '<svg viewBox="0 0 100 100" fill="none" aria-hidden="true" focusable="false"'
             . ' width="' . $groesse . '" height="' . $groesse . '"'
             . ' style="display:block">'
             . '<circle cx="23" cy="71" r="14" fill="currentColor"/>'
             . '<path d="M42 59C52 41 65 28 86 20" stroke="currentColor" stroke-width="9"'
             . ' stroke-linecap="round"/>'
             . '</svg>';
    }

    /**
     * Das TP-Monogramm im abgerundeten Quadrat.
     *
     * T und P teilen sich einen Stamm; die Schale des P ist derselbe Bogen
     * wie in der Bildmarke. Für Favicon, App-Symbol und Avatar – überall
     * dort, wo es sehr klein wird und trotzdem erkennbar bleiben muss.
     */
    public static function monogramm(int $groesse = 32): string
    {
        return '<svg viewBox="0 0 100 100" aria-hidden="true" focusable="false"'
             . ' width="' . $groesse . '" height="' . $groesse . '" style="display:block">'
             . '<rect width="100" height="100" rx="26" fill="' . self::PINE . '"/>'
             . '<path d="M39 22V78" stroke="#f6f5f0" stroke-width="11" stroke-linecap="round" fill="none"/>'
             . '<path d="M20 22H39" stroke="#f6f5f0" stroke-width="11" stroke-linecap="round" fill="none"/>'
             . '<path d="M39 22C66 22 80 30 80 39.5C80 49 66 56 39 56" stroke="' . self::SIGNAL
             . '" stroke-width="11" stroke-linecap="round" fill="none"/>'
             . '</svg>';
    }

    /**
     * Zeichen und Schriftzug nebeneinander.
     *
     * Der Abstand ist 0,3 × Zeichenhöhe, die Laufweite eng – so steht es
     * im Markenhandbuch, und so muss es auch im Code stehen, sonst weicht
     * die Anwendung vom Handbuch ab.
     */
    public static function logo(int $groesse = 28, string $farbe = 'currentColor'): string
    {
        $abstand = max(6, (int) round($groesse * 0.3));
        $schrift = (int) round($groesse * 0.78);
        return '<span style="display:inline-flex;align-items:center;gap:' . $abstand . 'px;'
             . 'color:' . Util::attr($farbe) . '">'
             . self::zeichen($groesse)
             . '<span style="font-size:' . $schrift . 'px;font-weight:700;letter-spacing:-.04em;'
             . 'line-height:1">' . self::NAME . '</span></span>';
    }

    /**
     * Das Monogramm als Data-URI fürs Favicon.
     *
     * Als SVG statt als .ico: eine Datei weniger, und in jeder Größe
     * scharf. `#` muss in einer Data-URI als %23 stehen.
     */
    public static function favicon(): string
    {
        $svg = "<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'>"
             . "<rect width='100' height='100' rx='26' fill='" . self::PINE . "'/>"
             . "<path d='M39 22V78' stroke='#f6f5f0' stroke-width='11' stroke-linecap='round' fill='none'/>"
             . "<path d='M20 22H39' stroke='#f6f5f0' stroke-width='11' stroke-linecap='round' fill='none'/>"
             . "<path d='M39 22C66 22 80 30 80 39.5C80 49 66 56 39 56' stroke='" . self::SIGNAL
             . "' stroke-width='11' stroke-linecap='round' fill='none'/>"
             . "</svg>";
        return 'data:image/svg+xml,' . str_replace(['#', '"'], ['%23', '%22'], $svg);
    }
}
