<?php
/**
 * Kleine Ausgabebausteine, die auf vielen Seiten vorkommen.
 *
 * Absichtlich Funktionen statt einer Klasse: Sie werden in Vorlagen
 * aufgerufen, und `kennzahl(...)` liest sich dort besser als
 * `Vorlage::kennzahl(...)`.
 */
if (!defined('GP_ROOT')) {
    exit;
}

/** Kachel mit Zahl, Vergleich und optionaler Kurve. */
function kennzahl(string $label, string $wert, array $o = []): string
{
    $delta   = $o['delta']   ?? null;   // float|null  Veränderung in Prozent
    $fuss    = $o['fuss']    ?? '';
    $icon    = $o['icon']    ?? '';
    $kurve   = $o['kurve']   ?? null;   // int[]
    $url     = $o['url']     ?? '';
    $klein   = $o['klein']   ?? '';

    $html = '';
    $html .= '<div class="kennzahl__kopf">';
    $html .= '<span class="kennzahl__label">' . Util::h($label) . '</span>';
    if ($icon !== '') {
        $html .= '<span class="kennzahl__symbol">' . Icon::svg($icon, 16) . '</span>';
    }
    $html .= '</div>';
    $html .= '<div class="kennzahl__wert">' . Util::h($wert)
           . ($klein !== '' ? ' <small>' . Util::h($klein) . '</small>' : '') . '</div>';

    if ($delta !== null || $fuss !== '') {
        $html .= '<div class="kennzahl__fuss">';
        if ($delta !== null) {
            $richtung = $delta > 0.5 ? 'auf' : ($delta < -0.5 ? 'ab' : 'neutral');
            $pfeil    = $delta > 0.5 ? 'trend-up' : ($delta < -0.5 ? 'trend-down' : 'arrow-right');
            /*
             * Jenseits von 300 Prozent sagt eine Prozentzahl nichts mehr –
             * „+996 %" heißt in Wahrheit „der Vergleichszeitraum war fast
             * leer". Dann ist der Faktor die ehrlichere Angabe.
             */
            $anzeige = abs($delta) > 300
                ? '×' . Util::zahl(1 + $delta / 100, 1)
                : ($delta > 0 ? '+' : '') . Util::zahl($delta, 1) . ' %';
            $html .= '<span class="delta delta--' . $richtung . '">' . Icon::svg($pfeil, 13)
                   . $anzeige . '</span>';
        }
        if ($fuss !== '') {
            $html .= '<span class="gedimmt">' . $fuss . '</span>';
        }
        $html .= '</div>';
    }
    if (is_array($kurve) && count($kurve) > 1) {
        $html .= '<div class="kennzahl__kurve">' . Diagramm::kurve($kurve, 200, 34) . '</div>';
    }

    $klasse = 'kennzahl';
    return $url !== ''
        ? '<a class="' . $klasse . '" href="' . Util::attr(App::url($url)) . '">' . $html . '</a>'
        : '<div class="' . $klasse . '">' . $html . '</div>';
}

/** Farbige Statusmarke. */
function pille(string $text, string $art = '', bool $punkt = false): string
{
    $klasse = 'pille' . ($art !== '' ? ' pille--' . $art : '') . ($punkt ? ' pille--punkt' : '');
    return '<span class="' . $klasse . '">' . Util::h($text) . '</span>';
}

/** Avatar mit Namen, optional verlinkt. */
function person(string $name, array $o = []): string
{
    $unter = $o['unter'] ?? '';
    $url   = $o['url']   ?? '';
    $bild  = $o['bild']  ?? '';
    $gross = $o['gross'] ?? false;

    $avatar = '<span class="avatar' . ($gross ? ' avatar--gross' : ' avatar--klein') . '" style="background:'
            . Util::attr(Util::avatarFarbe($name)) . '">'
            . ($bild !== '' ? '<img src="' . Util::attr(App::url($bild)) . '" alt="">' : Util::h(Util::initialen($name)))
            . '</span>';

    $inhalt = $avatar . '<span class="person__text"><span class="person__name">' . Util::h($name) . '</span>'
            . ($unter !== '' ? '<span class="person__unter">' . Util::h($unter) . '</span>' : '') . '</span>';

    return $url !== ''
        ? '<a class="person" href="' . Util::attr(App::url($url)) . '">' . $inhalt . '</a>'
        : '<span class="person">' . $inhalt . '</span>';
}

/** Leerzustand mit Handlungsaufforderung – jeder leere Bereich hat einen. */
function leerzustand(string $icon, string $titel, string $text, array $knoepfe = []): string
{
    $html = '<div class="leerzustand"><div class="leerzustand__symbol">' . Icon::svg($icon, 26) . '</div>'
          . '<h3>' . Util::h($titel) . '</h3><p>' . Util::h($text) . '</p>';
    if ($knoepfe !== []) {
        $html .= '<div class="leerzustand__knoepfe">';
        foreach ($knoepfe as $i => [$beschriftung, $url, $ikon]) {
            $klasse = $i === 0 ? 'btn btn--primaer' : 'btn';
            $html .= '<a class="' . $klasse . '" href="' . Util::attr(App::url($url)) . '">'
                   . ($ikon ? Icon::svg($ikon, 15) : '') . Util::h($beschriftung) . '</a>';
        }
        $html .= '</div>';
    }
    return $html . '</div>';
}

/** Punktestand 0–100 mit Balken (Health Score, SEO Score). */
function punktestand(int $wert, bool $mitZahl = true): string
{
    $stufe = $wert >= 70 ? 'gut' : ($wert >= 40 ? 'mittel' : 'schlecht');
    return '<span class="punktestand punktestand--' . $stufe . '">'
         . ($mitZahl ? '<span class="punktestand__zahl">' . $wert . '</span>' : '')
         . '<span class="punktestand__spur"><span class="punktestand__fuellung" data-breite="'
         . max(3, min(100, $wert)) . '%"></span></span></span>';
}

/** Fortschrittsbalken mit Beschriftung. */
function balken(int $prozent, string $farbe = ''): string
{
    $p = (int) Util::klemmen((float) $prozent, 0, 100);
    $k = $farbe !== '' ? ' balken__fuellung--' . $farbe : '';
    return '<div class="balken"><div class="balken__fuellung' . $k . '" data-breite="' . $p . '%"></div></div>';
}

/** Karte mit Kopfzeile – spart auf jeder Seite fünf Zeilen. */
function karteAuf(string $titel = '', string $aktionen = '', string $klasse = ''): string
{
    $html = '<div class="karte ' . Util::attr($klasse) . '">';
    if ($titel !== '' || $aktionen !== '') {
        $html .= '<div class="karte__kopf"><h2>' . Util::h($titel) . '</h2>'
               . ($aktionen !== '' ? '<div class="karte__kopf-aktionen">' . $aktionen . '</div>' : '')
               . '</div>';
    }
    return $html;
}

/**
 * Ein Bildfeld: Vorschau, „Bild wählen", „Entfernen" und der Pfad.
 *
 * Steht hier und nicht dreimal im Quelltext, weil es inzwischen an drei
 * Stellen gebraucht wird: oben im Baustein, in den Listen eines Bausteins
 * (Galerie, Karten, Logos) und beim Teammitglied. Drei Kopien laufen
 * auseinander, und die Fassung in der Liste war schon vorher nur ein
 * nacktes Textfeld – genau der Grund, weshalb in Galerien nichts stand.
 *
 * Das Skript findet das Eingabefeld über die Hülle `[data-bildfeld]`;
 * eine Kennung braucht es nur für die Beschriftung. Deshalb funktionieren
 * auch die Zeilen einer Liste, die gar keine eindeutige Kennung haben.
 *
 * @param array{id?:string,versteckt?:bool,eng?:bool,hinweis?:string} $o
 */
function bildfeld(string $label, string $name, string $wert, array $o = []): string
{
    $id      = (string) ($o['id'] ?? '');
    $eng     = !empty($o['eng']);
    $hinweis = (string) ($o['hinweis'] ?? '');
    $bild    = $wert !== '' ? Util::attr(App::url($wert)) : '';

    $html = '<div class="feld bildfeld' . ($eng ? ' bildfeld--eng' : '') . '" data-bildfeld>';
    $html .= $id !== ''
        ? '<label class="feld__label" for="' . Util::attr($id) . '">' . Util::h($label) . '</label>'
        : '<span class="feld__label">' . Util::h($label) . '</span>';

    $html .= '<div class="bildfeld__reihe">'
           . '<span class="bildfeld__schau"'
           . ($bild !== '' ? ' style="background-image:url(' . $bild . ')"' : '') . '>'
           . ($wert === '' ? Icon::svg('image', $eng ? 14 : 18) : '') . '</span>'
           . '<div class="bildfeld__knoepfe">'
           . '<button class="btn btn--klein" type="button" data-bild-waehlen="' . Util::attr($id) . '">'
           . Icon::svg('image', 14) . ' Bild wählen</button>'
           . '<button class="btn btn--klein" type="button" data-bild-weg="' . Util::attr($id) . '"'
           . ($wert === '' ? ' hidden' : '') . '>Entfernen</button>'
           . '</div></div>';

    $html .= !empty($o['versteckt'])
        ? '<input type="hidden"' . ($id !== '' ? ' id="' . Util::attr($id) . '"' : '')
          . ' name="' . Util::attr($name) . '" value="' . Util::attr($wert) . '">'
        : '<input class="eingabe bildfeld__pfad"' . ($id !== '' ? ' id="' . Util::attr($id) . '"' : '')
          . ' name="' . Util::attr($name) . '" value="' . Util::attr($wert) . '"'
          . ' placeholder="uploads/… oder https://">';

    if ($hinweis !== '') {
        $html .= '<div class="feld__hinweis">' . Util::h($hinweis) . '</div>';
    }
    return $html . '</div>';
}
