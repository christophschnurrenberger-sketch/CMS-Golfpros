<?php
/**
 * Baukasten – was sich an der Folge der Bausteine einer Seite ändern lässt.
 *
 * Bis hierher steckte das in app/seite.php, als Zweige eines großen
 * Formular-Handlers: Jede Handlung war ein POST mit Weiterleitung, und
 * nach jeder lud die ganze Seite neu. Man verschob einen Baustein und
 * stand danach wieder oben auf der Seite, ohne zu sehen, wo er gelandet
 * war.
 *
 * Jetzt liegen die Handlungen hier, und zwei Aufrufer teilen sie sich:
 * das Formular in seite.php (ohne JavaScript, wie bisher) und der
 * Endpunkt app/bauen.php, über den der Baukasten an Ort und Stelle
 * arbeitet. Was die Seite am Ende enthält, entscheidet in beiden Fällen
 * dieselbe Stelle.
 *
 * Positionen werden als „hinter welchem Baustein" angegeben: '' heißt ans
 * Ende, 'anfang' ganz nach vorn, sonst die Kennung des Vorgängers. So
 * bleibt eine Angabe richtig, auch wenn sich davor etwas verschoben hat.
 */
final class Baukasten
{
    /** Sitzungsschlüssel der Ablage für entfernte Bausteine. */
    private const ABLAGE = 'gp_bau_ablage';

    /** So viele entfernte Bausteine hält die Ablage je Sitzung. */
    private const ABLAGE_GROESSE = 40;

    /* ================================================== Lesen === */

    /** @return array{0:array<string,mixed>|null,1:array<int,array<string,mixed>>} Seite und ihre Bausteine */
    public static function laden(int $seiteId): array
    {
        $seite = $seiteId > 0 ? Tenant::find('pages', $seiteId) : null;
        return [$seite, $seite !== null ? array_values(Pages::bloecke($seite)) : []];
    }

    public static function stelle(array $bloecke, string $blockId): ?int
    {
        foreach ($bloecke as $i => $b) {
            if ((string) ($b['id'] ?? '') === $blockId) {
                return $i;
            }
        }
        return null;
    }

    /** Die Kennung des Vorgängers – so, wie `einfuegen()` sie erwartet. */
    public static function vorgaenger(array $bloecke, string $blockId): string
    {
        $i = self::stelle($bloecke, $blockId);
        return $i === null || $i === 0 ? 'anfang' : (string) $bloecke[$i - 1]['id'];
    }

    /**
     * Eine Zeile, an der man einen Baustein in der Liste wiedererkennt.
     *
     * „Titelbereich" steht auf vielen Seiten dreimal untereinander, wenn es
     * mehrere gibt – „Leistungen" ebenso. Die Überschrift darunter sagt,
     * welcher gemeint ist. Die Sternchen des Textmarkers gehören nicht dazu.
     */
    public static function kurz(array $block): string
    {
        $d = (array) ($block['daten'] ?? []);
        foreach (['titel', 'ueberschrift', 'name', 'zitat', 'text', 'obertitel'] as $feld) {
            $wert = trim(str_replace('*', '', (string) (is_string($d[$feld] ?? null) ? $d[$feld] : '')));
            if ($wert !== '') {
                return Util::kuerzen(preg_replace('/\s+/', ' ', $wert) ?? $wert, 48);
            }
        }
        return '';
    }

    /* ============================================== Verändern === */

    /**
     * Einen neuen Baustein einsetzen.
     *
     * @return array{0:array<string,mixed>|null,1:string} der neue Baustein oder null und ein Grund
     */
    public static function hinzufuegen(int $seiteId, string $typ, string $nach): array
    {
        if (Bloecke::typ($typ) === null) {
            return [null, 'Diesen Baustein gibt es nicht.'];
        }
        [$seite, $bloecke] = self::laden($seiteId);
        if ($seite === null) {
            return [null, 'Diese Seite gibt es nicht.'];
        }
        $neu = Bloecke::neu($typ);
        self::sichern($seiteId, self::einfuegen($bloecke, $neu, $nach));
        return [$neu, ''];
    }

    /** @return array<string,mixed>|null die Kopie, direkt hinter dem Original */
    public static function kopieren(int $seiteId, string $blockId): ?array
    {
        [$seite, $bloecke] = self::laden($seiteId);
        $i = $seite !== null ? self::stelle($bloecke, $blockId) : null;
        if ($i === null) {
            return null;
        }
        $kopie = $bloecke[$i];
        $kopie['id'] = self::neueKennung($bloecke);
        self::sichern($seiteId, self::einfuegen($bloecke, $kopie, $blockId));
        return $kopie;
    }

    /**
     * Einen Baustein entfernen – und in der Ablage behalten.
     *
     * Die Ablage liegt in der Sitzung und nicht beim Aufrufer: „Rückgängig"
     * schickt nur die Kennung zurück, nicht den Baustein. Sonst könnte
     * jeder beliebige Daten als „wiederhergestellten Baustein" einschleusen.
     *
     * @return string|null der Vorgänger (für die Stelle beim Zurückholen) oder null
     */
    public static function entfernen(int $seiteId, string $blockId): ?string
    {
        [$seite, $bloecke] = self::laden($seiteId);
        $i = $seite !== null ? self::stelle($bloecke, $blockId) : null;
        if ($i === null) {
            return null;
        }
        $vorher = self::vorgaenger($bloecke, $blockId);
        $block = $bloecke[$i];
        array_splice($bloecke, $i, 1);
        self::sichern($seiteId, $bloecke);

        Auth::start();
        $ablage = (array) ($_SESSION[self::ABLAGE] ?? []);
        $ablage[$seiteId . ':' . $blockId] = $block;
        $_SESSION[self::ABLAGE] = array_slice($ablage, -self::ABLAGE_GROESSE, null, true);
        return $vorher;
    }

    /**
     * Einen entfernten Baustein zurückholen.
     *
     * @return array<string,mixed>|null der Baustein oder null, wenn er nicht (mehr) in der Ablage liegt
     */
    public static function zurueckholen(int $seiteId, string $blockId, string $nach): ?array
    {
        Auth::start();
        $schluessel = $seiteId . ':' . $blockId;
        $block = $_SESSION[self::ABLAGE][$schluessel] ?? null;
        [$seite, $bloecke] = self::laden($seiteId);
        if (!is_array($block) || $seite === null) {
            return null;
        }
        /* Steht er schon wieder da (doppelt geklickt), nicht ein zweites Mal. */
        if (self::stelle($bloecke, $blockId) === null) {
            self::sichern($seiteId, self::einfuegen($bloecke, $block, $nach));
        }
        unset($_SESSION[self::ABLAGE][$schluessel]);
        return $block;
    }

    /**
     * Neue Reihenfolge.
     *
     * Angenommen wird nur eine Umstellung derselben Bausteine: Fehlt einer
     * oder ist einer zu viel, bleibt alles, wie es war. Eine Liste, die
     * nicht mehr zur Seite passt, kommt aus einem veralteten Fenster – und
     * das soll nicht still Bausteine verschwinden lassen.
     */
    public static function ordnen(int $seiteId, array $kennungen): bool
    {
        [$seite, $bloecke] = self::laden($seiteId);
        if ($seite === null || count($kennungen) !== count($bloecke)) {
            return false;
        }
        $nachKennung = [];
        foreach ($bloecke as $b) {
            $nachKennung[(string) $b['id']] = $b;
        }
        $neu = [];
        foreach ($kennungen as $k) {
            $k = (string) $k;
            if (!isset($nachKennung[$k])) {
                return false;
            }
            $neu[] = $nachKennung[$k];
            unset($nachKennung[$k]);
        }
        if ($nachKennung !== []) {
            return false;
        }
        self::sichern($seiteId, $neu);
        return true;
    }

    /** Um eine Stelle nach oben (-1) oder unten (+1). */
    public static function schieben(int $seiteId, string $blockId, int $richtung): bool
    {
        [$seite, $bloecke] = self::laden($seiteId);
        $i = $seite !== null ? self::stelle($bloecke, $blockId) : null;
        $ziel = $i === null ? -1 : $i + ($richtung < 0 ? -1 : 1);
        if ($i === null || $ziel < 0 || $ziel >= count($bloecke)) {
            return false;
        }
        [$bloecke[$i], $bloecke[$ziel]] = [$bloecke[$ziel], $bloecke[$i]];
        self::sichern($seiteId, $bloecke);
        return true;
    }

    /** @return array<string,mixed>|null der Baustein mit den neuen Daten */
    public static function datenSetzen(int $seiteId, string $blockId, array $daten): ?array
    {
        [$seite, $bloecke] = self::laden($seiteId);
        $i = $seite !== null ? self::stelle($bloecke, $blockId) : null;
        if ($i === null) {
            return null;
        }
        $bloecke[$i]['daten'] = $daten;
        self::sichern($seiteId, $bloecke);
        return $bloecke[$i];
    }

    /**
     * Die Felder eines Bausteins aus dem Formular rechts.
     *
     * Gelesen wird nur, was der Baustein laut Definition hat – nicht, was
     * im Formular steht. Listen bestehen aus parallelen Feldern
     * (`l_<liste>_<unterfeld>[]`); ein Eintrag, dessen Unterfelder alle
     * leer sind, fällt weg. So entfernt man einen Eintrag, indem man ihn
     * leert, und die leere Karte am Ende hängt keinen leeren Eintrag an.
     *
     * @return array<string,mixed>
     */
    public static function datenAusFormular(string $typ): array
    {
        $daten = [];
        foreach ((array) (Bloecke::typ($typ)['felder'] ?? []) as $feld => $def) {
            $art = $def[1] ?? 'text';
            if ($art === 'liste') {
                $unterfelder = array_keys((array) ($def[2] ?? []));
                $anzahl = 0;
                foreach ($unterfelder as $uf) {
                    $anzahl = max($anzahl, count(App::postListe('l_' . $feld . '_' . $uf)));
                }
                $eintraege = [];
                for ($n = 0; $n < $anzahl; $n++) {
                    $eintrag = [];
                    $leer = true;
                    foreach ($unterfelder as $uf) {
                        $werte = App::postListe('l_' . $feld . '_' . $uf);
                        $eintrag[$uf] = (string) ($werte[$n] ?? '');
                        if (trim($eintrag[$uf]) !== '') {
                            $leer = false;
                        }
                    }
                    if (!$leer) {
                        $eintraege[] = $eintrag;
                    }
                }
                $daten[$feld] = $eintraege;
            } elseif ($art === 'schalter') {
                $daten[$feld] = App::postBool('f_' . $feld);
            } elseif ($art === 'zahl') {
                $daten[$feld] = App::postInt('f_' . $feld);
            } else {
                $daten[$feld] = str_replace(["\r\n", "\r"], "\n", App::postRoh('f_' . $feld));
            }
        }
        return $daten;
    }

    /* ============================================== Intern === */

    /** @param array<int,array<string,mixed>> $bloecke */
    private static function einfuegen(array $bloecke, array $neu, string $nach): array
    {
        if ($nach === 'anfang') {
            array_unshift($bloecke, $neu);
            return $bloecke;
        }
        $i = $nach !== '' ? self::stelle($bloecke, $nach) : null;
        if ($i === null) {
            $bloecke[] = $neu;       // unbekannter Vorgänger: ans Ende, statt ihn zu verlieren
            return $bloecke;
        }
        array_splice($bloecke, $i + 1, 0, [$neu]);
        return $bloecke;
    }

    private static function neueKennung(array $bloecke): string
    {
        do {
            $k = 'b' . substr(Util::token(4), 0, 7);
        } while (self::stelle($bloecke, $k) !== null);
        return $k;
    }

    private static function sichern(int $seiteId, array $bloecke): void
    {
        Pages::speichern(['bloecke' => Util::json(array_values($bloecke))], $seiteId);
    }
}
