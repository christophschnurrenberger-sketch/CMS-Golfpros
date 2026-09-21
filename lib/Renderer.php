<?php
/**
 * Renderer – macht aus Bausteinen die fertige Seite.
 *
 * Getrennt von `Bloecke`, weil die Definition selten und der Aufbau oft
 * geändert wird. Der Renderer kennt nur eine Ausgabeform – das HTML der
 * öffentlichen Seite. Der Baukasten zeigt dasselbe, damit die Vorschau
 * nicht lügt.
 *
 * Die Gestaltung folgt einer redaktionellen Haltung: Listen statt
 * Kachelfelder, Handschrift als zweite Stimme, bewusste Asymmetrie. Wo
 * andere Baukästen drei gleich große Karten nebeneinander stellen, steht
 * hier eine Liste mit Trennlinien – man liest sie untereinander und
 * vergleicht Preise in einer Spalte statt über drei Kästen hinweg.
 */
final class Renderer
{
    /**
     * Im Baukasten schreibt man direkt in die Vorschau.
     *
     * Ist dieser Schalter an, bekommt jedes Feld, das sich sinnvoll an Ort
     * und Stelle ändern lässt, seine Herkunft als Angabe mit ins HTML:
     * `data-feld`, bei Listen zusätzlich `data-nr` und `data-unter`. Das
     * Skript im Baukasten macht daraus beschreibbare Stellen.
     *
     * Zweitens bleiben leere Felder sichtbar. Auf der Website fällt ein
     * leerer Titel weg – im Baukasten muss er stehen bleiben, sonst gibt
     * es keine Stelle, in die man hineinschreiben könnte. Deshalb prüfen
     * die Bausteine nicht mehr auf `!== ''`, sondern über `self::zeigen()`.
     *
     * Auf der Website ändert sich dadurch nichts: Der Schalter ist aus,
     * `self::feld()` liefert eine leere Zeichenkette, und `zeigen()`
     * verhält sich wie der Vergleich vorher.
     */
    private static bool $bearbeiten = false;

    /**
     * Die Felder des Bausteins, der gerade ausgegeben wird.
     *
     * Gebraucht, weil sich mehrere Bausteine eine Kopfzeile teilen, aber
     * nicht dieselben Felder haben: `kopfzeile()` gibt Kleinzeile,
     * Überschrift und Text aus – eine Galerie kennt aber nur die
     * Überschrift. Ohne diese Liste stünden im Baukasten Stellen zum
     * Hineinschreiben, die der Baustein gar nicht speichern kann. Man
     * tippt, und nichts passiert; das ist schlimmer als ein fehlendes Feld.
     *
     * @var array<int,string>|null
     */
    private static ?array $felder = null;

    /** Nur der Baukasten schaltet das an, und zwar um die Vorschau herum. */
    public static function bearbeitbar(bool $an): void
    {
        self::$bearbeiten = $an;
    }

    /** Hat der Baustein, der gerade ausgegeben wird, dieses Feld? */
    private static function hatFeld(string $name): bool
    {
        return self::$felder === null || in_array($name, self::$felder, true);
    }

    /**
     * Die Angaben, mit denen das Skript ein Feld wiederfindet.
     *
     * @param string   $name  Feldname im Baustein
     * @param string   $art   text | mehrzeilig | marker | bild
     * @param int|null $nr    Nummer des Listeneintrags
     * @param string   $unter Feldname innerhalb des Eintrags
     */
    private static function feld(string $name, string $art = 'text',
                                 ?int $nr = null, string $unter = ''): string
    {
        if (!self::$bearbeiten || !self::hatFeld($name)) {
            return '';
        }
        return ' data-feld="' . Util::attr($name) . '" data-art="' . Util::attr($art) . '"'
             . ($nr !== null ? ' data-nr="' . $nr . '"' : '')
             . ($unter !== '' ? ' data-unter="' . Util::attr($unter) . '"' : '');
    }

    /**
     * Soll dieses Feld ausgegeben werden?
     *
     * Auf der Website nur, wenn etwas drinsteht. Im Baukasten immer – ein
     * leeres Feld ist dort kein Nichts, sondern eine Stelle zum
     * Hineinschreiben.
     */
    private static function zeigen(?string $wert): bool
    {
        return trim((string) $wert) !== '' || self::$bearbeiten;
    }

    /** Ein Hinweis, der im Baukasten in einem leeren Feld steht. */
    private static function leer(string $text): string
    {
        return self::$bearbeiten ? ' data-leer="' . Util::attr($text) . '"' : '';
    }

    /** Nur im Baukasten: ein Knopf, eine Hülle, eine Markierung. */
    private static function nurBaukasten(string $html): string
    {
        return self::$bearbeiten ? $html : '';
    }

    /**
     * Ein Text in einer eigenen Hülle – aber nur im Baukasten.
     *
     * Gebraucht überall dort, wo der Entwurf Zeichen um den Wert legt, die
     * nicht zum Wert gehören: die Anführungszeichen eines Zitats etwa.
     * Ohne Hülle läge die beschreibbare Stelle um beides, und die
     * Anführungszeichen wanderten beim Speichern in den Text.
     */
    private static function huelle(string $text, string $marke): string
    {
        return self::$bearbeiten
            ? '<span' . $marke . '>' . Util::h($text) . '</span>'
            : Util::h($text);
    }

    /** Markierung eines Listeneintrags – daran hängt das Kreuz zum Entfernen. */
    private static function eintrag(string $feld, int $nr): string
    {
        return self::$bearbeiten
            ? ' data-eintrag="' . Util::attr($feld) . '" data-nr="' . $nr . '"'
            : '';
    }

    /** Der Knopf, der einen weiteren Eintrag anhängt. */
    private static function plus(string $feld, string $was): string
    {
        return self::nurBaukasten(
            '<button type="button" class="bau-plus" data-plus="' . Util::attr($feld)
            . '"><span class="bau-plus__zeichen">+</span> ' . Util::h($was) . '</button>');
    }

    public static function seite(array $bloecke): string
    {
        $html = '';
        foreach ($bloecke as $block) {
            $html .= self::block((array) $block);
        }
        return $html;
    }

    public static function block(array $block): string
    {
        $typ = (string) ($block['typ'] ?? '');
        $d   = (array) ($block['daten'] ?? []);
        $methode = 'block' . ucfirst($typ);

        if (!method_exists(self::class, $methode)) {
            return '';
        }
        self::$felder = array_keys((array) (Bloecke::typ($typ)['felder'] ?? []));
        $inhalt = self::$methode($d);
        self::$felder = null;

        if (trim($inhalt) === '') {
            return '';
        }

        /*
         * Der Aufmacher bringt seinen eigenen Abstand mit – er beginnt
         * direkt unter der Kopfzeile, ohne die übliche Luft darüber.
         */
        $klasse = $typ === 'hero' ? 'abschnitt--hero' : 'abschnitt';

        return '<section class="' . $klasse . ' abschnitt--' . Util::attr($typ) . '" id="'
             . Util::attr((string) ($block['id'] ?? '')) . '">' . $inhalt . '</section>';
    }

    /* ---------------------------------------------------------- Hilfen */

    private static function h(array $d, string $feld, string $standard = ''): string
    {
        return Util::h((string) ($d[$feld] ?? $standard));
    }

    /**
     * Überschrift mit Textmarker.
     *
     * `Besser Golf spielen. *Mit einem Plan.*` setzt den zweiten Satz auf
     * Gelb. Ein Sternchenpaar ist schneller erklärt als ein Farbwähler und
     * überlebt jedes Kopieren aus einem anderen Programm.
     */
    private static function mitMarker(string $text): string
    {
        $roh = Util::h($text);
        $roh = preg_replace('/\*([^*]+)\*/u', '<mark>$1</mark>', $roh) ?? $roh;
        return nl2br($roh);
    }

    private static function absatz(array $d, string $feld): string
    {
        $text = trim((string) ($d[$feld] ?? ''));
        if ($text === '') {
            return '';
        }
        $html = '';
        foreach (preg_split('/\n{2,}/', $text) ?: [] as $teil) {
            $html .= '<p>' . nl2br(Util::h(trim($teil))) . '</p>';
        }
        return $html;
    }

    private static function knopf(array $d, string $textFeld, string $urlFeld, string $klasse = 'knopf'): string
    {
        $text = trim((string) ($d[$textFeld] ?? ''));
        if (!self::zeigen($text)) {
            return '';
        }
        $url = (string) ($d[$urlFeld] ?? '#');

        /*
         * Im Baukasten ist der Knopf kein Link, sondern die Beschriftung
         * zum Überschreiben. Ein `href` würde beim Klick die Vorschau
         * verlassen; das Ziel selbst steht rechts in den Einstellungen,
         * denn ein Link-Ziel lässt sich nicht sinnvoll „an Ort und Stelle"
         * eintippen.
         */
        if (self::$bearbeiten) {
            return '<span class="' . $klasse . '"' . self::feld($textFeld)
                 . self::leer('Knopf') . '>' . Util::h($text) . '</span>';
        }
        return '<a class="' . $klasse . '" href="' . Util::attr($url) . '">' . Util::h($text) . '</a>';
    }

    private static function bildUrl(string $pfad): string
    {
        if ($pfad === '') {
            return '';
        }
        return str_starts_with($pfad, 'http') ? $pfad : App::url($pfad);
    }

    /**
     * Bild oder gestreifter Platzhalter in einer der vier Formen.
     *
     * Ein fehlendes Bild ist in diesem Entwurf kein grauer Kasten, sondern
     * eine schräg gestreifte Fläche mit Beschriftung. Sie sieht nach
     * Absicht aus statt nach Lücke, und man erkennt, was dort hingehört –
     * hilfreich, solange die Seite noch im Aufbau ist.
     */
    private static function bildOderStreifen(string $pfad, string $form, string $beschriftung,
                                            string $stil = '', string $marke = ''): string
    {
        $url = self::bildUrl($pfad);
        $s   = $stil !== '' ? ' style="' . Util::attr($stil) . '"' : '';

        if ($url !== '') {
            return '<div class="' . $form . '"' . $s . $marke . '>'
                 . '<img src="' . Util::attr($url) . '" alt="" loading="lazy">'
                 . self::nurBaukasten('<span class="bau-bild__wechseln">Bild wechseln</span>')
                 . '</div>';
        }
        return '<div class="' . $form . ' platzhalter"' . $s . $marke . '>'
             . '<span class="platzhalter__text">' . Util::h($beschriftung) . '</span>'
             . self::nurBaukasten('<span class="bau-bild__plus">+</span>')
             . '</div>';
    }

    /** Die wiederkehrende Kopfzeile: Handschriftzeile, Überschrift, Text. */
    private static function kopfzeile(array $d, string $klasse = ''): string
    {
        $ober  = self::h($d, 'obertitel');
        $titel = trim((string) ($d['titel'] ?? ''));
        $text  = self::absatz($d, 'text');
        if ($ober === '' && $titel === '' && $text === '' && !self::$bearbeiten) {
            return '';
        }
        $aus = (string) ($d['ausrichtung'] ?? 'links');

        /* Leer stehen bleibt ein Feld nur, wenn der Baustein es auch kennt. */
        $hat = fn (string $w, string $f): bool
            => $w !== '' || (self::$bearbeiten && self::hatFeld($f));

        return '<div class="kopfzeile' . ($aus === 'mitte' ? ' kopfzeile--mitte' : '') . ' ' . $klasse . '">'
             . ($hat($ober, 'obertitel') ? '<p class="kopfzeile__ober"' . self::feld('obertitel')
                . self::leer('Kleine Zeile darüber') . '>' . $ober . '</p>' : '')
             . ($hat($titel, 'titel') ? '<h2' . self::feld('titel', 'marker')
                . self::leer('Überschrift') . '>' . self::mitMarker($titel) . '</h2>' : '')
             . ($hat($text, 'text') ? '<div class="kopfzeile__text"' . self::feld('text', 'mehrzeilig')
                . self::leer('Text') . '>' . $text . '</div>' : '')
             . '</div>';
    }

    /* --------------------------------------------------------- Aufbau - */

    /**
     * Der Aufmacher.
     *
     * Bild rechts, über den Rand hinaus, mit großem Bogen unten links.
     * Die Überschrift schiebt sich mit negativem Abstand darüber und läuft
     * per mix-blend-mode ins Bild hinein, statt es mit einem Kasten zu
     * durchschlagen. Darunter Text, Knöpfe und – in Handschrift – der Satz,
     * den man am Telefon sagen würde.
     */
    private static function blockHero(array $d): string
    {
        $aus = (string) ($d['ausrichtung'] ?? 'geteilt');

        if ($aus === 'mitte') {
            return '<div class="inhalt-breite abschnitt--eng mitte">'
                 . (self::zeigen(self::h($d, 'obertitel'))
                    ? '<p class="hand hand--mittel"' . self::feld('obertitel')
                      . self::leer('Kleine Zeile darüber') . '>' . self::h($d, 'obertitel') . '</p>' : '')
                 . '<h1 style="margin:10px auto 0;max-width:16ch"' . self::feld('titel', 'marker')
                 . self::leer('Überschrift') . '>' . self::mitMarker((string) ($d['titel'] ?? '')) . '</h1>'
                 . '<div class="vorgang__unter" style="margin:26px auto 30px"'
                 . self::feld('text', 'mehrzeilig') . self::leer('Text') . '>' . self::absatz($d, 'text') . '</div>'
                 . '<div class="knopf-reihe" style="justify-content:center">'
                 . self::knopf($d, 'knopf_text', 'knopf_url')
                 . self::knopf($d, 'knopf2_text', 'knopf2_url', 'knopf knopf--strich')
                 . '</div></div>';
        }

        $bild = self::bildOderStreifen(
            (string) ($d['bild'] ?? ''), 'form-bogen hero__bild',
            'Aufmacher: Range im Abendlicht', '', self::feld('bild', 'bild')
        );
        // Platzhalterbild im Aufmacher trägt den wärmeren Streifen.
        $bild = str_replace('platzhalter"', 'platzhalter platzhalter--warm"', $bild);

        $notiz  = trim((string) ($d['notiz'] ?? ''));
        $fakten = trim((string) ($d['fakten'] ?? ''));

        return '<div class="hero">'
             . '<div class="hero__bild-huelle">' . $bild . '</div>'
             . '<div class="hero__titel-huelle"><h1 class="hero__titel"'
             . self::feld('titel', 'marker') . self::leer('Überschrift') . '>'
             . self::mitMarker((string) ($d['titel'] ?? '')) . '</h1></div>'
             . '<div class="hero__unten">'
             . '<div class="hero__text">'
             . '<div class="gedimmt eng" style="margin-bottom:28px"' . self::feld('text', 'mehrzeilig')
             . self::leer('Text') . '>' . self::absatz($d, 'text') . '</div>'
             . '<div class="knopf-reihe">'
             . self::knopf($d, 'knopf_text', 'knopf_url')
             . self::knopf($d, 'knopf2_text', 'knopf2_url', 'knopf knopf--strich')
             . '</div></div>'
             . (self::zeigen($notiz) ? '<p class="hero__notiz"' . self::feld('notiz')
                . self::leer('Handschriftliche Notiz') . '>' . Util::h($notiz) . '</p>' : '')
             . '</div>'
             . (self::zeigen($fakten) ? '<p class="hero__fakten"' . self::feld('fakten')
                . self::leer('Faktenzeile') . '>' . Util::h($fakten) . '</p>' : '')
             . '</div>';
    }

    private static function blockUeberschrift(array $d): string
    {
        return '<div class="inhalt-breite">' . self::kopfzeile($d) . '</div>';
    }

    private static function blockText(array $d): string
    {
        $titel = trim((string) ($d['titel'] ?? ''));
        return '<div class="inhalt-breite inhalt-breite--schmal">'
             . (self::zeigen($titel) ? '<h2 style="margin-bottom:.6em"' . self::feld('titel', 'marker')
                . self::leer('Überschrift') . '>' . self::mitMarker($titel) . '</h2>' : '')
             . '<div class="beitrag__text" style="padding:0;margin:0"' . self::feld('text', 'mehrzeilig')
             . self::leer('Text') . '>' . self::absatz($d, 'text') . '</div></div>';
    }

    /** Drei bis vier kurze Punkte – als versetzte Karten, nicht als Raster. */
    private static function blockSpalten(array $d): string
    {
        $karten = '';
        foreach ((array) ($d['eintraege'] ?? []) as $i => $e) {
            $karten .= '<article class="karte"' . self::eintrag('eintraege', (int) $i) . '>'
                     . '<p class="hand hand--klein" style="margin-bottom:12px">'
                     . str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) . '</p>'
                     . '<h3 style="margin-bottom:12px"' . self::feld('eintraege', 'text', (int) $i, 'titel')
                     . self::leer('Titel') . '>' . Util::h((string) ($e['titel'] ?? '')) . '</h3>'
                     . '<p class="gedimmt" style="font-size:16px;margin:0"'
                     . self::feld('eintraege', 'mehrzeilig', (int) $i, 'text') . self::leer('Text') . '>'
                     . Util::h((string) ($e['text'] ?? '')) . '</p></article>';
        }
        if ($karten === '' && !self::$bearbeiten) {
            return '';
        }
        return '<div class="inhalt-breite">' . self::kopfzeile($d)
             . '<div class="karten">' . $karten . '</div>'
             . self::plus('eintraege', 'Punkt') . '</div>';
    }

    private static function blockTrenner(array $d): string
    {
        return empty($d['sichtbar'])
            ? '<div class="inhalt-breite" style="padding-top:0;padding-bottom:0"></div>'
            : '<div class="inhalt-breite"><hr class="trenner" style="margin:0"></div>';
    }

    private static function blockBild(array $d): string
    {
        $breite = (string) ($d['breite'] ?? 'breit');
        $form   = $breite === 'schmal' ? 'form-karte' : 'form-bogen';
        $bild   = self::bildOderStreifen((string) ($d['bild'] ?? ''), $form,
            (string) ($d['alt'] ?? '') !== '' ? (string) $d['alt'] : 'Bild',
            '', self::feld('bild', 'bild'));
        $text   = trim((string) ($d['bildtext'] ?? ''));

        return '<div class="inhalt-breite' . ($breite === 'schmal' ? ' inhalt-breite--schmal' : '') . '">'
             . $bild
             . (self::zeigen($text) ? '<p class="hand hand--klein" style="margin-top:14px"'
                . self::feld('bildtext') . self::leer('Bildunterschrift') . '>'
                . Util::h($text) . '</p>' : '')
             . '</div>';
    }

    /** Galerie: drei Formen im Wechsel, damit kein Rasterfeld entsteht. */
    private static function blockGalerie(array $d): string
    {
        $formen = ['form-karte', 'form-blob', 'form-blob-r'];
        $bilder = '';
        foreach ((array) ($d['bilder'] ?? []) as $i => $b) {
            $bilder .= self::bildOderStreifen((string) ($b['bild'] ?? ''), $formen[$i % 3],
                (string) ($b['alt'] ?? '') !== '' ? (string) $b['alt'] : 'Bild ' . ($i + 1),
                '', self::feld('bilder', 'bild', (int) $i, 'bild') . self::eintrag('bilder', (int) $i));
        }
        if ($bilder === '' && !self::$bearbeiten) {
            return '';
        }
        return '<div class="inhalt-breite">' . self::kopfzeile($d)
             . '<div class="karten">' . $bilder . '</div>'
             . self::plus('bilder', 'Bild') . '</div>';
    }

    private static function blockVideo(array $d): string
    {
        $url = trim((string) ($d['url'] ?? ''));
        if ($url === '') {
            /*
             * Ohne Adresse gibt es auf der Website nichts zu zeigen. Im
             * Baukasten muss der Baustein trotzdem dastehen, sonst wäre er
             * nach dem Hinzufügen unsichtbar – und die Adresse trägt man
             * rechts ein, ein Video lässt sich nicht „hineinschreiben".
             */
            return self::nurBaukasten('<div class="inhalt-breite inhalt-breite--schmal">'
                 . self::kopfzeile($d)
                 . '<div class="einbettung platzhalter"><span class="platzhalter__text">'
                 . 'Video-Adresse rechts eintragen (YouTube oder Vimeo)</span></div></div>');
        }
        $einbetten = $url;
        if (preg_match('~youtu(?:\.be/|be\.com/watch\?v=)([A-Za-z0-9_-]{6,})~', $url, $t)) {
            $einbetten = 'https://www.youtube-nocookie.com/embed/' . $t[1];
        } elseif (preg_match('~vimeo\.com/(\d+)~', $url, $t)) {
            $einbetten = 'https://player.vimeo.com/video/' . $t[1];
        }

        return '<div class="inhalt-breite inhalt-breite--schmal">' . self::kopfzeile($d)
             . '<div class="einbettung"><iframe src="' . Util::attr($einbetten) . '" loading="lazy" '
             . 'title="Video" allowfullscreen></iframe></div>'
             . (self::zeigen((string) ($d['text'] ?? ''))
                ? '<p class="hand hand--klein" style="margin-top:14px"' . self::feld('text', 'mehrzeilig')
                  . self::leer('Text darunter') . '>' . self::h($d, 'text') . '</p>' : '')
             . '</div>';
    }

    private static function blockKarten(array $d): string
    {
        $karten = '';
        foreach ((array) ($d['eintraege'] ?? []) as $i => $e) {
            $url   = trim((string) ($e['url'] ?? ''));
            $innen = self::bildOderStreifen((string) ($e['bild'] ?? ''), 'form-karte',
                        (string) ($e['titel'] ?? 'Bild'), 'aspect-ratio:4/3;margin-bottom:20px',
                        self::feld('eintraege', 'bild', (int) $i, 'bild'))
                   . '<h3 style="margin-bottom:10px"' . self::feld('eintraege', 'text', (int) $i, 'titel')
                   . self::leer('Titel') . '>' . Util::h((string) ($e['titel'] ?? '')) . '</h3>'
                   . '<p class="gedimmt" style="font-size:16px;margin:0"'
                   . self::feld('eintraege', 'mehrzeilig', (int) $i, 'text') . self::leer('Text') . '>'
                   . Util::h((string) ($e['text'] ?? '')) . '</p>';

            /* Im Baukasten bleibt die Karte ein Kasten: Ein Link würde beim
               Klick auf den Text die Vorschau verlassen. */
            $karten .= ($url !== '' && !self::$bearbeiten)
                ? '<a class="karte" href="' . Util::attr($url) . '">' . $innen . '</a>'
                : '<article class="karte"' . self::eintrag('eintraege', (int) $i) . '>' . $innen . '</article>';
        }
        if ($karten === '' && !self::$bearbeiten) {
            return '';
        }
        return '<div class="inhalt-breite">' . self::kopfzeile($d)
             . '<div class="karten">' . $karten . '</div>'
             . self::plus('eintraege', 'Karte') . '</div>';
    }

    /** Zahlen ohne Kasten – die Ziffer ist groß genug, um allein zu stehen. */
    private static function blockZahlen(array $d): string
    {
        $teile = '';
        foreach ((array) ($d['eintraege'] ?? []) as $i => $e) {
            $teile .= '<div class="zahl-teil"' . self::eintrag('eintraege', (int) $i) . '>'
                    . '<span class="zahl-teil__wert"' . self::feld('eintraege', 'text', (int) $i, 'wert')
                    . self::leer('18') . '>' . Util::h((string) ($e['wert'] ?? '')) . '</span>'
                    . '<span class="zahl-teil__label"' . self::feld('eintraege', 'text', (int) $i, 'label')
                    . self::leer('Bezeichnung') . '>' . Util::h((string) ($e['label'] ?? '')) . '</span>'
                    . '</div>';
        }
        if ($teile === '' && !self::$bearbeiten) {
            return '';
        }
        return '<div class="inhalt-breite abschnitt--eng"><div class="zahlen">' . $teile . '</div>'
             . self::plus('eintraege', 'Zahl') . '</div>';
    }

    private static function blockZitat(array $d): string
    {
        $text = trim((string) ($d['text'] ?? ''));
        if (!self::zeigen($text)) {
            return '';
        }
        /*
         * Die Anführungszeichen gehören dem Entwurf, nicht dem Zitat.
         * Stünden sie mit in der beschreibbaren Stelle, würden sie beim
         * ersten Speichern Teil des Textes – und beim zweiten stünden vier
         * davon da. Deshalb umschließt im Baukasten eine eigene Hülle
         * genau den Text.
         */
        return '<div class="inhalt-breite"><figure style="margin:0;max-width:26ch">'
             . '<blockquote class="stimme__text" style="margin:0 0 12px">„'
             . self::huelle($text, self::feld('text', 'mehrzeilig') . self::leer('Das Zitat'))
             . '"</blockquote>'
             . (self::zeigen((string) ($d['autor'] ?? ''))
                ? '<figcaption class="stimme__wer"' . self::feld('autor')
                  . self::leer('Wer hat das gesagt?') . '>' . self::h($d, 'autor') . '</figcaption>' : '')
             . '</figure></div>';
    }

    /**
     * Leistungen als Liste.
     *
     * Kein Kachelfeld: Preise vergleicht man in einer Spalte, nicht über
     * drei Kästen hinweg. Der abwechselnde Einzug nimmt der Liste die
     * Strenge, ohne dass die Preisspalte verrutscht.
     */
    private static function blockLeistungen(array $d): string
    {
        $eintraege = (array) ($d['eintraege'] ?? []);

        if (!empty($d['automatisch'])) {
            $eintraege = [];
            foreach (Tenant::all('services', 'aktiv = 1 AND online_buchbar = 1', [], 'position, name', 8) as $s) {
                $eintraege[] = [
                    'titel' => (string) $s['name'],
                    'text'  => Util::kuerzen((string) $s['beschreibung'], 220),
                    'preis' => Util::geldKurz((int) $s['preis_cent']),
                    'dauer' => (int) $s['dauer_min'] . ' Minuten',
                    'id'    => (int) $s['id'],
                ];
            }
        }
        if ($eintraege === [] && !self::$bearbeiten) {
            return '';
        }

        /*
         * „Trainer aus dem System übernehmen" lässt sich nicht von Hand
         * überschreiben – die Einträge kommen aus den Leistungen. Deshalb
         * sind sie im Baukasten auch nicht beschreibbar; das Feld dafür
         * steht unter Leistungen, nicht auf der Seite.
         */
        $eigen = empty($d['automatisch']);
        $mark  = fn (string $art, int $i, string $unter): string
            => $eigen ? self::feld('eintraege', $art, $i, $unter) : '';

        $slug  = (string) (Tenant::workspace()['slug'] ?? '');
        $liste = '';
        foreach ($eintraege as $i => $e) {
            $ziel = !empty($e['id'])
                ? App::url('/buchen.php') . '?w=' . rawurlencode($slug) . '&leistung=' . (int) $e['id']
                : '#buchung';

            $zeig = fn (string $f): bool => $eigen ? self::zeigen((string) ($e[$f] ?? ''))
                                                    : !empty($e[$f]);

            $liste .= '<article class="liste__teil"'
                    . ($eigen ? self::eintrag('eintraege', (int) $i) : '') . '>'
                    . '<div class="liste__kopf">'
                    . '<span class="liste__nummer">' . str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) . '</span>'
                    . '<h3 class="liste__titel"' . $mark('text', (int) $i, 'titel')
                    . self::leer('Name der Leistung') . '>' . Util::h((string) ($e['titel'] ?? '')) . '</h3>'
                    . '<span class="liste__preis"' . $mark('text', (int) $i, 'preis')
                    . self::leer('Preis') . '>' . Util::h((string) ($e['preis'] ?? '')) . '</span>'
                    . '</div>'
                    . '<div class="liste__koerper">'
                    . '<p class="liste__text"' . $mark('mehrzeilig', (int) $i, 'text')
                    . self::leer('Beschreibung') . '>' . Util::h((string) ($e['text'] ?? '')) . '</p>'
                    . '<div class="liste__neben">'
                    . ($zeig('dauer') ? '<p class="leise" style="margin-bottom:6px"'
                        . $mark('text', (int) $i, 'dauer') . self::leer('Dauer') . '>'
                        . Util::h((string) ($e['dauer'] ?? '')) . '</p>' : '')
                    . ($zeig('wer') ? '<p class="gedimmt" style="font-size:15.5px;margin:0"'
                        . $mark('text', (int) $i, 'wer') . self::leer('Für wen?') . '>'
                        . Util::h((string) ($e['wer'] ?? '')) . '</p>' : '')
                    . '</div></div>'
                    . '<div class="liste__fuss">'
                    . (self::$bearbeiten
                        ? '<span class="knopf knopf--klein">Diesen Kurs buchen</span>'
                        : '<a class="knopf knopf--klein" href="' . Util::attr($ziel) . '">Diesen Kurs buchen</a>')
                    . ($zeig('note') ? '<span class="hand hand--klein"' . $mark('text', (int) $i, 'note')
                        . self::leer('Notiz') . '>' . Util::h((string) ($e['note'] ?? '')) . '</span>' : '')
                    . '</div></article>';
        }

        return '<div class="inhalt-breite" id="leistungen">' . self::kopfzeile($d)
             . '<div class="liste">' . $liste . '</div>'
             . ($eigen ? self::plus('eintraege', 'Leistung') : '') . '</div>';
    }

    /** Preistafeln in derselben Listenform – ein Aufbau für beide Blöcke. */
    private static function blockPreise(array $d): string
    {
        $liste = '';
        foreach ((array) ($d['eintraege'] ?? []) as $i => $e) {
            $merkmale = '';
            foreach (preg_split('/\r?\n/', (string) ($e['merkmale'] ?? '')) ?: [] as $m) {
                if (trim($m) === '') {
                    continue;
                }
                $merkmale .= '<p class="gedimmt" style="font-size:15.5px;margin:0 0 4px">'
                           . Util::h(trim($m)) . '</p>';
            }

            /* Der Knopf im Preisteil trägt den Text des Eintrags, nicht den
               des Bausteins – deshalb hier die Nummer mit im Feldnamen. */
            $knopf = self::$bearbeiten
                ? '<span class="knopf knopf--klein"' . self::feld('eintraege', 'text', (int) $i, 'knopf_text')
                  . self::leer('Knopf') . '>' . Util::h((string) ($e['knopf_text'] ?? '')) . '</span>'
                : self::knopf($e, 'knopf_text', 'knopf_url', 'knopf knopf--klein');

            $liste .= '<article class="liste__teil"' . self::eintrag('eintraege', (int) $i) . '>'
                    . '<div class="liste__kopf">'
                    . '<span class="liste__nummer">' . str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) . '</span>'
                    . '<h3 class="liste__titel"' . self::feld('eintraege', 'text', (int) $i, 'titel')
                    . self::leer('Name') . '>' . Util::h((string) ($e['titel'] ?? '')) . '</h3>'
                    . '<span class="liste__preis"' . self::feld('eintraege', 'text', (int) $i, 'preis')
                    . self::leer('Preis') . '>' . Util::h((string) ($e['preis'] ?? '')) . '</span>'
                    . '</div>'
                    . '<div class="liste__koerper">'
                    . '<div class="liste__text"' . self::feld('eintraege', 'zeilen', (int) $i, 'merkmale')
                    . self::leer('Eine Leistung je Zeile') . '>' . $merkmale . '</div>'
                    . '<div class="liste__neben">'
                    . (self::zeigen((string) ($e['zusatz'] ?? '')) ? '<p class="leise" style="margin:0"'
                        . self::feld('eintraege', 'text', (int) $i, 'zusatz') . self::leer('Zusatz') . '>'
                        . Util::h((string) ($e['zusatz'] ?? '')) . '</p>' : '')
                    . '</div></div>'
                    . (self::zeigen((string) ($e['knopf_text'] ?? ''))
                       ? '<div class="liste__fuss">' . $knopf
                         . (!empty($e['hervorheben'])
                            ? '<span class="hand hand--klein">am häufigsten gebucht</span>' : '')
                         . '</div>'
                       : '')
                    . '</article>';
        }
        if ($liste === '' && !self::$bearbeiten) {
            return '';
        }
        return '<div class="inhalt-breite">' . self::kopfzeile($d)
             . '<div class="liste">' . $liste . '</div>'
             . self::plus('eintraege', 'Preis') . '</div>';
    }

    /**
     * Kundenstimmen versetzt untereinander statt in drei gleichen Kästen.
     *
     * Drei nebeneinander liest niemand – man überfliegt sie und merkt sich
     * keine. Versetzt untereinander liest man jede einzeln.
     */
    private static function blockTestimonials(array $d): string
    {
        $stimmen = '';
        foreach ((array) ($d['eintraege'] ?? []) as $i => $e) {
            $text = trim((string) ($e['text'] ?? ''));
            if ($text === '' && !self::$bearbeiten) {
                continue;
            }
            $wer = trim((string) ($e['name'] ?? ''));
            if (trim((string) ($e['zusatz'] ?? '')) !== '') {
                $wer .= '<span class="zusammen"> · ' . Util::h((string) $e['zusatz']) . '</span>';
            } else {
                $wer = Util::h($wer);
            }
            if (trim((string) ($e['name'] ?? '')) !== '' && str_contains($wer, '<span')) {
                $wer = Util::h((string) $e['name']) . substr($wer, strpos($wer, '<span'));
            }

            /* Im Baukasten stehen Name und Zusatz getrennt, sonst ließen sie
               sich nicht einzeln überschreiben. */
            $unten = self::$bearbeiten
                ? '<span' . self::feld('eintraege', 'text', (int) $i, 'name') . self::leer('Name') . '>'
                  . Util::h((string) ($e['name'] ?? '')) . '</span>'
                  . '<span class="zusammen"> · </span>'
                  . '<span' . self::feld('eintraege', 'text', (int) $i, 'zusatz') . self::leer('Zusatz') . '>'
                  . Util::h((string) ($e['zusatz'] ?? '')) . '</span>'
                : $wer;

            /*
             * Kein `style="margin:0"` mehr. Das stand hier, um den
             * Vorgabeabstand des <figure> loszuwerden – und schlug dabei
             * jede Regel aus dem Stilblatt: Die Stimmen sollten versetzt
             * stehen, konnten es aber nicht, weil ein Stil am Element
             * jeden Stil aus der Datei übertrumpft. Übrig blieb eine
             * schmale Spalte, in der alle Stimmen untereinander standen
             * und zwei Drittel der Seite leer. Der Abstand wird jetzt in
             * `site.css` gesetzt, wo auch das Nebeneinander steht.
             */
            $stimmen .= '<figure class="stimme"' . self::eintrag('eintraege', (int) $i) . '>'
                      . '<blockquote class="stimme__text" style="margin:0 0 10px">„'
                      . self::huelle($text, self::feld('eintraege', 'mehrzeilig', (int) $i, 'text')
                                            . self::leer('Was gesagt wurde'))
                      . '"</blockquote>'
                      . '<figcaption class="stimme__wer">' . $unten . '</figcaption></figure>';
        }
        if ($stimmen === '' && !self::$bearbeiten) {
            return '';
        }
        return '<div class="inhalt-breite">'
             . (self::zeigen(self::h($d, 'titel'))
                ? '<p class="hand hand--gross" style="margin-bottom:clamp(30px,4vw,54px)"'
                  . self::feld('titel') . self::leer('Überschrift') . '>'
                  . self::h($d, 'titel') . '</p>' : '')
             . '<div class="stimmen">' . $stimmen . '</div>'
             . self::plus('eintraege', 'Stimme') . '</div>';
    }

    /**
     * Fragen und Antworten.
     *
     * Ohne <details>: Der eingebaute Aufklapper lässt sich nicht so
     * gestalten, dass das Pluszeichen sich beim Öffnen dreht, und in
     * Safari stimmt die Zeilenhöhe der Zusammenfassung nicht. Zehn Zeilen
     * JavaScript sind hier die ehrlichere Lösung – und ohne JavaScript
     * stehen alle Antworten offen da, was für Suchmaschinen sogar besser ist.
     */
    private static function blockFaq(array $d): string
    {
        $liste = '';
        foreach ((array) ($d['eintraege'] ?? []) as $i => $e) {
            $frage = trim((string) ($e['frage'] ?? ''));
            if ($frage === '' && !self::$bearbeiten) {
                continue;
            }
            /*
             * Im Baukasten steht die Frage nicht in einem Knopf: Ein Klick
             * soll den Textzeiger setzen, nicht die Antwort zuklappen. Und
             * die Antwort steht offen da, sonst schriebe man ins Dunkle.
             */
            $kopf = self::$bearbeiten
                ? '<div class="frage__knopf"><span class="frage__zeichen">+</span>'
                  . '<span' . self::feld('eintraege', 'text', (int) $i, 'frage') . self::leer('Frage') . '>'
                  . Util::h($frage) . '</span></div>'
                : '<button class="frage__knopf" type="button" data-frage>'
                  . '<span class="frage__zeichen">+</span>'
                  . '<span>' . Util::h($frage) . '</span></button>';

            $liste .= '<div class="frage' . (self::$bearbeiten ? ' ist-offen' : '') . '"'
                    . self::eintrag('eintraege', (int) $i) . '>' . $kopf
                    . '<div class="frage__antwort"' . self::feld('eintraege', 'mehrzeilig', (int) $i, 'antwort')
                    . self::leer('Antwort') . '>'
                    . nl2br(Util::h((string) ($e['antwort'] ?? ''))) . '</div>'
                    . '</div>';
        }
        if ($liste === '' && !self::$bearbeiten) {
            return '';
        }
        return '<div class="inhalt-breite inhalt-breite--schmal">' . self::kopfzeile($d)
             . '<div class="fragen">' . $liste . '</div>'
             . self::plus('eintraege', 'Frage') . '</div>';
    }

    /**
     * Das Team: je Person eine Doppelseite.
     *
     * Porträt als Blob, daneben Kurzprofil, Kennwerte als Zeilen und
     * darunter der Name in Handschrift wie eine Unterschrift. Bei der
     * zweiten Person ist die Seite gespiegelt – das ist der Unterschied
     * zwischen einem Team und einer Personalliste.
     */
    private static function blockTeam(array $d): string
    {
        $leute = Tenant::all('users', 'aktiv = 1', [], 'id', 6);
        if ($leute === []) {
            return '';
        }
        /*
         * Namen, Kurzprofil und Foto stehen am Teammitglied, nicht am
         * Baustein – deshalb lassen sie sich hier nicht überschreiben. Der
         * Platzhalter sagt im Baukasten, wo sie herkommen, statt eine
         * Bearbeitung vorzugaukeln, die ins Leere liefe.
         */

        $html = self::kopfzeile($d);
        foreach ($leute as $i => $u) {
            $links = $i % 2 === 0;
            $bild  = self::bildOderStreifen((string) $u['bild'],
                $links ? 'form-blob' : 'form-blob-r',
                self::$bearbeiten
                    ? 'Foto hinterlegst du unter Team'
                    : 'Porträt ' . explode(' ', (string) $u['name'])[0]);

            $zeilen = '';
            foreach ([
                'Schwerpunkt' => (string) ($u['titel'] ?? ''),
                'Unterricht'  => (string) Tenant::einstellung('unterrichtszeiten', 'nach Vereinbarung'),
            ] as $was => $wert) {
                if (trim($wert) === '') {
                    continue;
                }
                $zeilen .= '<div class="zeilen__teil"><span class="zeilen__was">' . Util::h($was) . '</span>'
                         . '<span class="zeilen__wert">' . Util::h($wert) . '</span></div>';
            }

            $text = '<div class="breit"' . ($links ? '' : ' style="order:-1"') . '>'
                  . '<h2 style="margin-bottom:8px">' . Util::h((string) $u['name']) . '</h2>'
                  . (trim((string) $u['titel']) !== ''
                     ? '<p class="leise" style="margin-bottom:22px">' . Util::h((string) $u['titel']) . '</p>' : '')
                  . (trim((string) $u['bio']) !== ''
                     ? '<div class="gedimmt eng-3" style="margin-bottom:22px">'
                       . self::absatz(['t' => (string) $u['bio']], 't') . '</div>' : '')
                  . ($zeilen !== '' ? '<div class="zeilen">' . $zeilen . '</div>' : '')
                  . '<p class="hand" style="font-size:38px;margin:24px 0 0;color:var(--text)">'
                  . Util::h((string) $u['name']) . '</p>'
                  . '</div>';

            $html .= '<div class="zwei zwei--mitte" style="margin-top:clamp(40px,5vw,86px)">'
                   . '<div class="breit" style="flex:1 1 300px;'
                   . ($links ? 'margin-left:clamp(-60px,-4vw,0px)' : 'margin-right:clamp(-60px,-4vw,0px)') . '">'
                   . $bild . '</div>'
                   . $text . '</div>';
        }
        return '<div class="inhalt-breite">' . $html . '</div>';
    }

    private static function blockLogos(array $d): string
    {
        $logos = '';
        foreach ((array) ($d['bilder'] ?? []) as $i => $b) {
            $url = self::bildUrl((string) ($b['bild'] ?? ''));
            if ($url === '' && !self::$bearbeiten) {
                continue;
            }
            /* Im Baukasten braucht auch ein noch leeres Logo eine Fläche,
               die man anklicken kann. */
            $logos .= $url !== ''
                ? '<img src="' . Util::attr($url) . '" alt="' . Util::attr((string) ($b['alt'] ?? '')) . '" '
                  . 'loading="lazy" style="max-height:44px;width:auto;opacity:.65"'
                  . self::feld('bilder', 'bild', (int) $i, 'bild') . self::eintrag('bilder', (int) $i) . '>'
                : '<span class="bau-logo-leer"' . self::feld('bilder', 'bild', (int) $i, 'bild')
                  . self::eintrag('bilder', (int) $i) . '><span class="bau-bild__plus">+</span></span>';
        }
        if ($logos === '' && !self::$bearbeiten) {
            return '';
        }
        return '<div class="inhalt-breite abschnitt--eng">'
             . (self::zeigen(self::h($d, 'titel'))
                ? '<p class="leise" style="margin-bottom:22px"' . self::feld('titel')
                  . self::leer('Kleine Zeile') . '>' . self::h($d, 'titel') . '</p>' : '')
             . '<div style="display:flex;flex-wrap:wrap;gap:clamp(28px,4vw,60px);align-items:center">'
             . $logos . '</div>' . self::plus('bilder', 'Logo') . '</div>';
    }

    /* -------------------------------------------------------- Handlung */

    /** Das Band vor dem Fuß: eine große Frage, ein Knopf, eine Notiz. */
    private static function blockCta(array $d): string
    {
        $stil  = (string) ($d['stil'] ?? 'marke');
        $notiz = trim((string) ($d['notiz'] ?? ''));

        return '<div class="abschnitt--band' . ($stil === 'still' ? '-2' : '') . '" '
             . 'style="margin-inline:calc(50% - 50vw);width:100vw">'
             . '<div class="inhalt-breite" style="padding-block:clamp(48px,6vw,104px)">'
             . '<div class="zwei">'
             . '<div class="breit">'
             . '<h2 style="font-size:clamp(30px,4.6vw,62px);line-height:1.02;letter-spacing:-0.03em;'
             . 'max-width:18ch;margin-bottom:22px"' . self::feld('titel', 'marker') . self::leer('Überschrift')
             . '>' . self::mitMarker((string) ($d['titel'] ?? '')) . '</h2>'
             . '<div class="gedimmt eng-2" style="margin-bottom:26px"' . self::feld('text', 'mehrzeilig')
             . self::leer('Text') . '>' . self::absatz($d, 'text') . '</div>'
             . self::knopf($d, 'knopf_text', 'knopf_url') . '</div>'
             . (self::zeigen($notiz)
                ? '<p class="schmal hand hand--mittel hand--schief" style="flex:0 1 320px"'
                  . self::feld('notiz') . self::leer('Handschriftliche Notiz') . '>'
                  . Util::h($notiz) . '</p>' : '')
             . '</div></div></div>';
    }

    /**
     * Das Buchungsfenster auf der Website: ein Kalender, keine Maske.
     *
     * Vorher standen hier zwei Felder und ein Knopf „Freie Zeiten
     * anzeigen". Wer buchen wollte, musste also erst raten, ob überhaupt
     * etwas frei ist, dann eine Seite weiter, und erst dort sah er es.
     * Zwei Schritte, bevor die eigentliche Frage beantwortet war: Wann
     * kann ich?
     *
     * Jetzt beantwortet der Baustein sie sofort. Vier Wochen am Stück,
     * freie Tage hervorgehoben, ein Klick öffnet die Uhrzeiten dieses
     * Tages, ein zweiter führt mit gewählter Zeit direkt in die Buchung.
     *
     * Ohne JavaScript stehen alle Tage mit ihren Zeiten untereinander –
     * dieselbe Entscheidung wie bei den Fragen und Antworten: Die Seite
     * ist vollständig, das Skript macht sie nur kompakter. Suchmaschinen
     * und Vorleseprogramme sehen so jede einzelne freie Zeit.
     *
     * Gerechnet wird gegen denselben Kalender wie überall sonst. Vier
     * Wochen einer Leistung kosten rund zwanzig Millisekunden; deshalb
     * wird nicht zwischengespeichert, was sonst veralten könnte – eine
     * angezeigte Zeit, die längst vergeben ist, ärgert mehr als eine
     * Seite, die zwanzig Millisekunden später kommt.
     */
    private static function blockBuchung(array $d): string
    {
        $serviceId = (int) ($d['service_id'] ?? 0);
        $wo = 'aktiv = 1 AND online_buchbar = 1';
        $p  = [];
        if ($serviceId > 0) {
            $wo .= ' AND id = :id';
            $p['id'] = $serviceId;
        }
        $leistungen = Tenant::all('services', $wo, $p, 'position, name');
        if ($leistungen === []) {
            return '';
        }

        /* Welche Leistung der Kalender zeigt. `bl` kommt vom Auswahlfeld
           weiter unten; ohne Angabe die erste. */
        $gewaehlteId = App::getInt('bl');
        $service = null;
        foreach ($leistungen as $l) {
            if ((int) $l['id'] === $gewaehlteId) {
                $service = $l;
                break;
            }
        }
        $service ??= $leistungen[0];
        $sid = (int) $service['id'];

        /* Den Kalender baut Oeffentlich::kalender – dieselbe Fassung wie
           auf buchen.php. */
        $wochen = max(1, min(8, (int) ($d['wochen'] ?? 4)));
        $kal    = Oeffentlich::kalender($service, $wochen);
        $slug   = (string) (Tenant::workspace()['slug'] ?? '');

        /* Die Leistung wechseln: ein Formular an dieselbe Adresse. Die
           übrigen Werte der Adresszeile müssen mit, sonst landet ein
           Wechsel auf der Startseite statt auf der Seite, auf der man
           gerade steht. */
        $mitnehmen = '';
        foreach (['w', 's', 'beitrag'] as $name) {
            $wert = App::get($name);
            if ($wert !== '') {
                $mitnehmen .= '<input type="hidden" name="' . $name . '" value="' . Util::attr($wert) . '">';
            }
        }
        $auswahl = '';
        if (count($leistungen) > 1) {
            $optionen = '';
            foreach ($leistungen as $l) {
                $optionen .= '<option value="' . (int) $l['id'] . '"' . ((int) $l['id'] === $sid ? ' selected' : '') . '>'
                           . Util::h((string) $l['name']) . ' · ' . Util::geldKurz((int) $l['preis_cent'])
                           . '</option>';
            }
            $auswahl = '<form method="get" action="" class="buchkal__wahl">' . $mitnehmen
                     . '<div class="feld"><label for="bk-leistung-' . $sid . '">Leistung</label>'
                     . '<select id="bk-leistung-' . $sid . '" name="bl" onchange="if(window.Blick)window.Blick.merken(this);this.form.submit()">'
                     . $optionen . '</select></div>'
                     . '<noscript><button class="knopf knopf--klein" type="submit">Kalender zeigen</button></noscript>'
                     . '</form>';
        }

        $alle = App::url('/buchen.php') . '?' . http_build_query(array_filter([
            'w' => $slug, 'service_id' => $sid,
        ]));

        /*
         * Der Weg zu mehr: Der Baustein zeigt vier Wochen, buchen.php acht.
         * „Alle freien Zeiten" heisst deshalb nicht mehr „eine Liste
         * ansehen", sondern „weiter nach vorn schauen".
         */
        $kalender = $kal['frei'] === 0
            ? '<p class="buchkal__leer">In den nächsten ' . $wochen . ' Wochen ist für diese Leistung '
              . 'nichts mehr frei. <a href="' . Util::attr($alle) . '">Weitere Wochen ansehen</a> '
              . 'oder kurz Kontakt aufnehmen – oft lässt sich doch etwas einrichten.</p>'
            : $kal['html']
              . '<p class="buchkal__alle"><a href="' . Util::attr($alle) . '">Weitere Wochen ansehen</a></p>';

        return '<div class="inhalt-breite inhalt-breite--schmal" id="buchung">' . self::kopfzeile($d)
             . $auswahl . $kalender . '</div>';
    }

    /**
     * Der Kundenzugang auf der Website.
     *
     * Drei Zustände, und alle drei sind gemeint:
     *
     *   * **Angemeldet** – dann wirbt nichts mehr, dann steht da ein Weg
     *     in den eigenen Bereich. Jemandem ein Anmeldeformular zu zeigen,
     *     der schon angemeldet ist, wirkt wie eine Seite, die ihn nicht
     *     kennt.
     *   * **Registrierung abgeschaltet** – dann verschwindet der Baustein
     *     ganz. Ein Formular, das nichts anlegt, ist schlimmer als keins.
     *   * **Sonst** – Formular oder Knopf, je nach Einstellung.
     *
     * Das Formular schickt direkt an portal/registrieren.php; dieselbe
     * Prüfung, dieselbe Roboterfalle, dasselbe Protokoll wie dort. Die
     * Seite baut nichts nach, was es schon gibt.
     */
    private static function blockKonto(array $d): string
    {
        $slug = (string) (Tenant::workspace()['slug'] ?? '');
        $ziel = Oeffentlich::url('/portal/registrieren.php');

        $angemeldet = Kundenlogin::kunde();
        if ($angemeldet !== null) {
            return '<div class="inhalt-breite inhalt-breite--schmal" id="konto">'
                 . self::kopfzeile($d)
                 . '<div class="kontokasten kontokasten--bekannt">'
                 . '<p class="kontokasten__gruss">Schön, dass du da bist, '
                 . Util::h((string) $angemeldet['vorname']) . '.</p>'
                 . '<a class="knopf knopf--primaer" href="' . Util::attr(Oeffentlich::url('/portal/')) . '">'
                 . 'Zu deinem Bereich</a>'
                 . '</div></div>';
        }

        if (!Kundenlogin::registrierungOffen()) {
            /* Im Baukasten bleibt wenigstens die Kopfzeile stehen; sonst
               verschwände der Baustein, sobald die Registrierung zu ist. */
            return self::nurBaukasten('<div class="inhalt-breite inhalt-breite--schmal" id="konto">'
                 . self::kopfzeile($d)
                 . '<p class="leise">Die Registrierung ist in den Einstellungen abgeschaltet – '
                 . 'auf der Website erscheint dieser Baustein deshalb nicht.</p></div>');
        }

        $vorteile = '';
        foreach ((array) ($d['vorteile'] ?? []) as $i => $v) {
            $zeile = trim((string) ($v['text'] ?? ''));
            if ($zeile !== '' || self::$bearbeiten) {
                /* Feld und Eintrag liegen hier auf zwei Ebenen: Das Kreuz
                   zum Entfernen hängt am <li>, die beschreibbare Stelle
                   liegt darin – sonst stünde das Kreuz mitten im Text. */
                $vorteile .= '<li' . self::eintrag('vorteile', (int) $i) . '>'
                           . self::huelle($zeile, self::feld('vorteile', 'text', (int) $i, 'text')
                                                  . self::leer('Vorteil'))
                           . '</li>';
            }
        }
        $vorteile = $vorteile !== '' ? '<ul class="kontokasten__vorteile">' . $vorteile . '</ul>' : '';
        $vorteile .= self::plus('vorteile', 'Vorteil');

        $knopf = trim((string) ($d['knopf_text'] ?? '')) ?: 'Konto anlegen';
        $anmelden = '<p class="kontokasten__klein">Schon ein Konto? '
                  . '<a href="' . Util::attr(Oeffentlich::url('/portal/')) . '">Anmelden</a></p>';

        /* Nur ein Knopf: für Seiten, auf denen schon genug Formulare stehen. */
        if ((string) ($d['stil'] ?? 'formular') === 'knopf') {
            return '<div class="inhalt-breite inhalt-breite--schmal" id="konto">'
                 . self::kopfzeile($d)
                 . '<div class="kontokasten">'
                 . $vorteile
                 . '<a class="knopf knopf--primaer" href="' . Util::attr($ziel) . '"'
                 . self::feld('knopf_text') . '>' . Util::h($knopf) . '</a>'
                 . $anmelden
                 . '</div></div>';
        }

        return '<div class="inhalt-breite inhalt-breite--schmal" id="konto">'
             . self::kopfzeile($d)
             . '<div class="kontokasten">'
             . $vorteile
             . '<form class="vorgang__form" method="post" action="' . Util::attr($ziel) . '">'
             . Auth::csrfFeld()
             . '<input type="hidden" name="aktion" value="registrieren">'
             . '<input type="hidden" name="w" value="' . Util::attr($slug) . '">'
             . '<input type="hidden" name="begonnen" value="' . time() . '">'
             . '<input type="text" name="website" class="honigtopf" tabindex="-1" autocomplete="off" aria-hidden="true">'
             . '<div class="feld-paar">'
             . '<div class="feld"><label for="kk-vorname">Vorname</label>'
             . '<input id="kk-vorname" name="vorname" required autocomplete="given-name"></div>'
             . '<div class="feld"><label for="kk-nachname">Nachname</label>'
             . '<input id="kk-nachname" name="nachname" required autocomplete="family-name"></div>'
             . '</div>'
             . '<div class="feld-paar">'
             . '<div class="feld"><label for="kk-email">E-Mail</label>'
             . '<input id="kk-email" type="email" name="email" required autocomplete="email"></div>'
             . '<div class="feld"><label for="kk-passwort">Passwort</label>'
             . '<input id="kk-passwort" type="password" name="passwort" required'
             . ' autocomplete="new-password" minlength="10"></div>'
             . '</div>'
             . '<label class="einwilligung"><input type="checkbox" name="einwilligung" value="1" required>'
             . '<span>Ich bin mit der Verarbeitung meiner Angaben für meinen Kundenzugang '
             . 'einverstanden. Die Einwilligung kann ich jederzeit widerrufen.</span></label>'
             . '<button class="knopf knopf--primaer" type="submit"' . self::feld('knopf_text') . '>'
             . Util::h($knopf) . '</button>'
             . '</form>'
             . $anmelden
             . '</div></div>';
    }

    private static function blockFormular(array $d): string
    {
        $formId = (int) ($d['form_id'] ?? 0);
        $form = $formId > 0 ? Tenant::find('forms', $formId) : Tenant::one('forms');
        $felder = $form ? Util::ausJson((string) $form['felder'], []) : [];
        if ($felder === []) {
            $felder = [
                ['name' => 'name', 'label' => 'Name', 'typ' => 'text', 'pflicht' => true],
                ['name' => 'email', 'label' => 'E-Mail', 'typ' => 'email', 'pflicht' => true],
                ['name' => 'telefon', 'label' => 'Telefon', 'typ' => 'tel'],
                ['name' => 'interesse', 'label' => 'Worum geht es?', 'typ' => 'auswahl',
                 'optionen' => ['Platzreife', 'Einzeltraining', 'Videoanalyse', 'Gruppentraining', 'Etwas anderes']],
                ['name' => 'nachricht', 'label' => 'Ihre Nachricht', 'typ' => 'mehrzeilig'],
            ];
        }

        /*
         * Kurze Felder stehen paarweise, lange über die ganze Breite. Das
         * spart Höhe, ohne dass ein Formular wie ein Antragsformular wirkt.
         */
        $html = '';
        $offen = false;
        foreach ($felder as $f) {
            $name  = Util::attr((string) ($f['name'] ?? ''));
            $label = Util::h((string) ($f['label'] ?? ''));
            $typ   = (string) ($f['typ'] ?? 'text');
            $pflicht = !empty($f['pflicht']) ? ' required' : '';
            $kurz  = in_array($typ, ['text', 'email', 'tel', 'date', 'number'], true);

            if ($kurz && !$offen) {
                $html .= '<div class="feld-paar">';
                $offen = true;
            } elseif (!$kurz && $offen) {
                $html .= '</div>';
                $offen = false;
            }

            $html .= '<div class="feld"><label for="ff-' . $name . '">' . $label . '</label>';
            switch ($typ) {
                case 'mehrzeilig':
                    $html .= '<textarea id="ff-' . $name . '" name="' . $name . '" rows="3"' . $pflicht . '></textarea>';
                    break;
                case 'auswahl':
                    $html .= '<select id="ff-' . $name . '" name="' . $name . '"' . $pflicht . '>';
                    foreach ((array) ($f['optionen'] ?? []) as $o) {
                        $html .= '<option>' . Util::h((string) $o) . '</option>';
                    }
                    $html .= '</select>';
                    break;
                default:
                    $html .= '<input id="ff-' . $name . '" type="' . Util::attr($typ)
                           . '" name="' . $name . '"' . $pflicht . '>';
            }
            $html .= '</div>';
        }
        if ($offen) {
            $html .= '</div>';
        }

        return '<div class="inhalt-breite inhalt-breite--schmal" id="kontakt">' . self::kopfzeile($d)
             . '<form class="vorgang__form" method="post" action="' . Util::attr(App::url('/anfrage.php')) . '">'
             . '<input type="hidden" name="w" value="' . Util::attr((string) (Tenant::workspace()['slug'] ?? '')) . '">'
             . '<input type="hidden" name="form_id" value="' . ($form ? (int) $form['id'] : 0) . '">'
             . '<input type="hidden" name="begonnen" value="' . time() . '">'
             . '<input type="text" name="website" class="honigtopf" tabindex="-1" autocomplete="off" aria-hidden="true">'
             . $html
             . '<label class="einwilligung"><input type="checkbox" name="einwilligung" value="1" required>'
             . '<span>Ich bin mit der Verarbeitung meiner Angaben zur Bearbeitung der Anfrage einverstanden. '
             . 'Die Einwilligung kann ich jederzeit widerrufen.</span></label>'
             . '<button class="knopf" type="submit"' . self::feld('knopf_text') . '>'
             . self::h($d, 'knopf_text', 'Anfrage senden') . '</button>'
             . '</form></div>';
    }

    private static function blockProdukte(array $d): string
    {
        $art = (string) ($d['art'] ?? 'alle');
        $wo  = 'aktiv = 1';
        $p   = [];
        if ($art !== 'alle') {
            $wo .= ' AND art = :art';
            $p['art'] = $art;
        }
        $produkte = Tenant::all('products', $wo, $p, 'position, id', max(1, (int) ($d['anzahl'] ?? 6)));
        if ($produkte === []) {
            return '';
        }

        $slug  = (string) (Tenant::workspace()['slug'] ?? '');
        $liste = '';
        foreach ($produkte as $i => $pr) {
            $url = App::url('/kaufen.php') . '?w=' . rawurlencode($slug) . '&p=' . (int) $pr['id'];
            $liste .= '<article class="liste__teil">'
                    . '<div class="liste__kopf">'
                    . '<span class="liste__nummer">' . str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) . '</span>'
                    . '<h3 class="liste__titel">' . Util::h((string) $pr['name']) . '</h3>'
                    . '<span class="liste__preis">' . Util::geldKurz((int) $pr['preis_cent']) . '</span>'
                    . '</div>'
                    . '<div class="liste__koerper">'
                    . '<p class="liste__text">'
                    . Util::h(Util::kuerzen((string) ($pr['kurztext'] ?: $pr['beschreibung']), 200)) . '</p>'
                    . '<div class="liste__neben"><p class="leise" style="margin:0">'
                    . Util::h(Commerce::PRODUKT_ARTEN[(string) $pr['art']][0] ?? '') . '</p></div>'
                    . '</div>'
                    . '<div class="liste__fuss">'
                    . '<a class="knopf knopf--klein" href="' . Util::attr($url) . '">Kaufen</a>'
                    . ((int) $pr['vergleichspreis_cent'] > (int) $pr['preis_cent']
                       ? '<span class="hand hand--klein">statt '
                         . Util::h(Util::geldKurz((int) $pr['vergleichspreis_cent'])) . '</span>' : '')
                    . '</div></article>';
        }
        return '<div class="inhalt-breite" id="angebote">' . self::kopfzeile($d)
             . '<div class="liste">' . $liste . '</div></div>';
    }

    private static function blockKurse(array $d): string
    {
        $kurse = Tenant::all('courses', "status = 'veroeffentlicht'", [], 'position, id',
            max(1, (int) ($d['anzahl'] ?? 3)));
        $karten = '';
        foreach ($kurse as $k) {
            $karten .= '<article class="karte">'
                     . self::bildOderStreifen((string) $k['bild'], 'form-karte', (string) $k['titel'],
                         'aspect-ratio:16/10;margin-bottom:20px')
                     . '<h3 style="margin-bottom:10px">' . Util::h((string) $k['titel']) . '</h3>'
                     . '<p class="gedimmt" style="font-size:16px;margin-bottom:18px">'
                     . Util::h(Util::kuerzen((string) $k['kurztext'], 130)) . '</p>'
                     . '<div style="display:flex;justify-content:space-between;align-items:baseline;gap:14px">'
                     . '<span class="leise">' . Courses::lektionenAnzahl((int) $k['id']) . ' Lektionen</span>'
                     . '<span class="liste__preis" style="font-size:22px">'
                     . ((int) $k['preis_cent'] > 0 ? Util::geldKurz((int) $k['preis_cent']) : 'kostenlos')
                     . '</span></div></article>';
        }
        if ($karten === '') {
            return '';
        }
        return '<div class="inhalt-breite">' . self::kopfzeile($d)
             . '<div class="karten">' . $karten . '</div></div>';
    }

    /** Termine als Liste mit dem Datum in Handschrift. */
    private static function blockEvents(array $d): string
    {
        $events = Tenant::all('events', "status = 'veroeffentlicht' AND start >= :jetzt",
            ['jetzt' => Util::jetzt()], 'start', max(1, (int) ($d['anzahl'] ?? 3)));
        $liste = '';
        foreach ($events as $e) {
            $frei = Events::freiePlaetze($e);
            $liste .= '<article class="liste__teil">'
                    . '<div class="liste__kopf">'
                    . '<span class="liste__nummer">' . Util::h(Util::datum((string) $e['start'], false)) . '</span>'
                    . '<h3 class="liste__titel">' . Util::h((string) $e['titel']) . '</h3>'
                    . '<span class="liste__preis">' . Util::h(Util::geldKurz((int) $e['preis_cent'])) . '</span>'
                    . '</div>'
                    . '<div class="liste__koerper">'
                    . '<p class="liste__text">' . Util::h(Util::datumLang((string) $e['start'])) . ' · '
                    . Util::h((string) ($e['ort_text'] ?: Events::ortName((int) $e['location_id']))) . '</p>'
                    . '<div class="liste__neben"><p class="leise" style="margin:0">'
                    . ($frei > 0 ? 'noch ' . ($frei === 1 ? '1 Platz' : $frei . ' Plätze') : 'ausgebucht') . '</p></div>'
                    . '</div></article>';
        }
        if ($liste === '') {
            return '';
        }
        return '<div class="inhalt-breite">' . self::kopfzeile($d)
             . '<div class="liste">' . $liste . '</div></div>';
    }

    /**
     * Golfreisen als Kacheln, jede ein Weg auf ihre eigene Seite.
     *
     * Was auf der Kachel steht, ist die Entscheidungshilfe: wohin, wann,
     * wie lange, was es kostet und ob noch etwas frei ist. Alles Weitere –
     * Hotel, Programm, Leistungen – steht auf reise.php. Eine Reise in
     * eine Kachel zu quetschen hiesse, sie unlesbar zu machen; sie hier
     * ganz auszubreiten hiesse, drei Bildschirme Startseite.
     *
     * Ausgebuchte Reisen verschwinden nicht: „ausgebucht" ist eine
     * Empfehlung fuers naechste Mal, und wer eine Warteliste fuehrt, will
     * genau dann gefunden werden.
     */
    private static function blockReisen(array $d): string
    {
        $reisen = Trips::kommende(max(1, (int) ($d['anzahl'] ?? 3)));
        if ($reisen === []) {
            return '';
        }

        $kacheln = '';
        foreach ($reisen as $r) {
            $frei = Trips::freiePlaetze($r);
            $ziel = Oeffentlich::url('/reise.php', ['r' => (string) $r['slug']]);
            $ort  = trim((string) $r['ziel'] . ' · ' . (string) $r['land'], ' ·');

            $kacheln .= '<a class="reisekachel" href="' . Util::attr($ziel) . '">'
                      . ((string) $r['bild'] !== ''
                         ? '<span class="reisekachel__bild"><img src="'
                           . Util::attr(App::url((string) $r['bild'])) . '" alt="" loading="lazy"></span>'
                         : '')
                      . '<span class="reisekachel__koerper">'
                      . ($ort !== '' ? '<span class="reisekachel__ort">' . Util::h($ort) . '</span>' : '')
                      . '<span class="reisekachel__titel">' . Util::h((string) $r['titel']) . '</span>'
                      . '<span class="reisekachel__zeit">'
                      . Util::h(Util::datum((string) $r['start'])) . ' – '
                      . Util::h(Util::datum((string) $r['ende'])) . ' · '
                      . (int) $r['naechte'] . ' Nächte</span>'
                      . ((string) $r['kurztext'] !== ''
                         ? '<span class="reisekachel__text">' . Util::h((string) $r['kurztext']) . '</span>' : '')
                      . '<span class="reisekachel__fuss">'
                      . '<span class="reisekachel__preis">ab ' . Util::h(Util::geldKurz((int) $r['preis_cent']))
                      . '</span>'
                      . '<span class="reisekachel__frei">'
                      . ($frei > 0 ? 'noch ' . ($frei === 1 ? '1 Platz' : $frei . ' Plätze') : 'ausgebucht') . '</span>'
                      . '</span></span></a>';
        }

        return '<div class="inhalt-breite" id="reisen">' . self::kopfzeile($d)
             . '<div class="reisekacheln">' . $kacheln . '</div></div>';
    }

    /** Beiträge als abwechselnd eingerückte Zeilen mit Bild und Text. */
    private static function blockBlog(array $d): string
    {
        $posts = Tenant::all('posts', "status = 'veroeffentlicht'", [], 'veroeffentlicht DESC',
            max(1, (int) ($d['anzahl'] ?? 3)));
        $slug  = (string) (Tenant::workspace()['slug'] ?? '');
        $liste = '';
        foreach ($posts as $b) {
            $url = App::url('/site.php') . '?w=' . rawurlencode($slug)
                 . '&beitrag=' . rawurlencode((string) $b['slug']);
            $liste .= '<a class="beitrag-karte" href="' . Util::attr($url) . '">'
                    . '<div class="beitrag-karte__bild">'
                    . self::bildOderStreifen((string) $b['bild'], 'form-karte', (string) $b['titel'],
                        'aspect-ratio:4/3')
                    . '</div>'
                    . '<div class="beitrag-karte__text">'
                    . '<p class="hand hand--klein" style="margin-bottom:8px">'
                    . Util::h(Util::datumLang((string) $b['veroeffentlicht'])) . '</p>'
                    . '<h3 style="margin-bottom:10px">' . Util::h((string) $b['titel']) . '</h3>'
                    . '<p class="gedimmt" style="margin:0">'
                    . Util::h(Util::kuerzen((string) $b['auszug'], 150)) . '</p></div></a>';
        }
        if ($liste === '') {
            return '';
        }
        return '<div class="inhalt-breite">' . self::kopfzeile($d)
             . '<div class="beitrag-liste">' . $liste . '</div></div>';
    }

    private static function blockKontakt(array $d): string
    {
        $ort = (int) ($d['location_id'] ?? 0) > 0
            ? Tenant::find('locations', (int) $d['location_id'])
            : Tenant::one('locations', 'aktiv = 1');

        $telefon = trim((string) Tenant::einstellung('telefon', ''));
        $mail    = trim((string) Tenant::einstellung('mail_absender', ''));

        $zeilen = '';
        if ($ort) {
            $zeilen .= '<div class="zeilen__teil"><span class="zeilen__was">Anschrift</span>'
                     . '<span class="zeilen__wert">' . Util::h((string) $ort['name']) . '<br>'
                     . Util::h((string) $ort['strasse']) . '<br>'
                     . Util::h(trim(((string) $ort['plz']) . ' ' . ((string) $ort['ort']))) . '</span></div>';
            if (trim((string) $ort['notiz']) !== '') {
                $zeilen .= '<div class="zeilen__teil"><span class="zeilen__was">Anfahrt</span>'
                         . '<span class="zeilen__wert">' . Util::h((string) $ort['notiz']) . '</span></div>';
            }
        }
        if ($telefon !== '') {
            $zeilen .= '<div class="zeilen__teil"><span class="zeilen__was">Telefon</span>'
                     . '<span class="zeilen__wert">' . Util::h($telefon) . '</span></div>';
        }
        if ($mail !== '') {
            $zeilen .= '<div class="zeilen__teil"><span class="zeilen__was">E-Mail</span>'
                     . '<span class="zeilen__wert"><a href="mailto:' . Util::attr($mail) . '">'
                     . Util::h($mail) . '</a></span></div>';
        }

        $karte = '';
        if ($ort && !empty($d['karte'])) {
            $suche = urlencode(trim(((string) $ort['name']) . ', ' . ((string) $ort['strasse'])
                   . ', ' . ((string) $ort['plz']) . ' ' . ((string) $ort['ort'])));
            $karte = self::bildOderStreifen('', 'form-blob-r', 'Anfahrt ' . (string) $ort['ort'])
                   . '<p style="margin-top:14px"><a href="https://www.openstreetmap.org/search?query=' . $suche
                   . '" target="_blank" rel="noopener">In Karten öffnen</a></p>';
        }

        return '<div class="inhalt-breite" id="kontakt"><div class="zwei">'
             . '<div class="breit">' . self::kopfzeile($d)
             . ($zeilen !== '' ? '<div class="zeilen" style="max-width:none">' . $zeilen . '</div>' : '')
             . '</div>'
             . ($karte !== '' ? '<div class="breit" style="flex:1 1 300px">' . $karte . '</div>' : '')
             . '</div></div>';
    }
}
