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
        $inhalt = self::$methode($d);
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
        if ($text === '') {
            return '';
        }
        $url = (string) ($d[$urlFeld] ?? '#');
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
    private static function bildOderStreifen(string $pfad, string $form, string $beschriftung, string $stil = ''): string
    {
        $url = self::bildUrl($pfad);
        $s   = $stil !== '' ? ' style="' . Util::attr($stil) . '"' : '';

        if ($url !== '') {
            return '<div class="' . $form . '"' . $s . '>'
                 . '<img src="' . Util::attr($url) . '" alt="" loading="lazy"></div>';
        }
        return '<div class="' . $form . ' platzhalter"' . $s . '>'
             . '<span class="platzhalter__text">' . Util::h($beschriftung) . '</span></div>';
    }

    /** Die wiederkehrende Kopfzeile: Handschriftzeile, Überschrift, Text. */
    private static function kopfzeile(array $d, string $klasse = ''): string
    {
        $ober  = self::h($d, 'obertitel');
        $titel = trim((string) ($d['titel'] ?? ''));
        $text  = self::absatz($d, 'text');
        if ($ober === '' && $titel === '' && $text === '') {
            return '';
        }
        $aus = (string) ($d['ausrichtung'] ?? 'links');

        return '<div class="kopfzeile' . ($aus === 'mitte' ? ' kopfzeile--mitte' : '') . ' ' . $klasse . '">'
             . ($ober !== '' ? '<p class="kopfzeile__ober">' . $ober . '</p>' : '')
             . ($titel !== '' ? '<h2>' . self::mitMarker($titel) . '</h2>' : '')
             . ($text !== '' ? '<div class="kopfzeile__text">' . $text . '</div>' : '')
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
                 . (self::h($d, 'obertitel') !== ''
                    ? '<p class="hand hand--mittel">' . self::h($d, 'obertitel') . '</p>' : '')
                 . '<h1 style="margin:10px auto 0;max-width:16ch">' . self::mitMarker((string) ($d['titel'] ?? '')) . '</h1>'
                 . '<div class="vorgang__unter" style="margin:26px auto 30px">' . self::absatz($d, 'text') . '</div>'
                 . '<div class="knopf-reihe" style="justify-content:center">'
                 . self::knopf($d, 'knopf_text', 'knopf_url')
                 . self::knopf($d, 'knopf2_text', 'knopf2_url', 'knopf knopf--strich')
                 . '</div></div>';
        }

        $bild = self::bildOderStreifen(
            (string) ($d['bild'] ?? ''), 'form-bogen hero__bild',
            'Aufmacher: Range im Abendlicht'
        );
        // Platzhalterbild im Aufmacher trägt den wärmeren Streifen.
        $bild = str_replace('platzhalter"', 'platzhalter platzhalter--warm"', $bild);

        $notiz  = trim((string) ($d['notiz'] ?? ''));
        $fakten = trim((string) ($d['fakten'] ?? ''));

        return '<div class="hero">'
             . '<div class="hero__bild-huelle">' . $bild . '</div>'
             . '<div class="hero__titel-huelle"><h1 class="hero__titel">'
             . self::mitMarker((string) ($d['titel'] ?? '')) . '</h1></div>'
             . '<div class="hero__unten">'
             . '<div class="hero__text">'
             . '<div class="gedimmt eng" style="margin-bottom:28px">' . self::absatz($d, 'text') . '</div>'
             . '<div class="knopf-reihe">'
             . self::knopf($d, 'knopf_text', 'knopf_url')
             . self::knopf($d, 'knopf2_text', 'knopf2_url', 'knopf knopf--strich')
             . '</div></div>'
             . ($notiz !== '' ? '<p class="hero__notiz">' . Util::h($notiz) . '</p>' : '')
             . '</div>'
             . ($fakten !== '' ? '<p class="hero__fakten">' . Util::h($fakten) . '</p>' : '')
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
             . ($titel !== '' ? '<h2 style="margin-bottom:.6em">' . self::mitMarker($titel) . '</h2>' : '')
             . '<div class="beitrag__text" style="padding:0;margin:0">' . self::absatz($d, 'text') . '</div></div>';
    }

    /** Drei bis vier kurze Punkte – als versetzte Karten, nicht als Raster. */
    private static function blockSpalten(array $d): string
    {
        $karten = '';
        foreach ((array) ($d['eintraege'] ?? []) as $i => $e) {
            $karten .= '<article class="karte">'
                     . '<p class="hand hand--klein" style="margin-bottom:12px">'
                     . str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) . '</p>'
                     . '<h3 style="margin-bottom:12px">' . Util::h((string) ($e['titel'] ?? '')) . '</h3>'
                     . '<p class="gedimmt" style="font-size:16px;margin:0">'
                     . Util::h((string) ($e['text'] ?? '')) . '</p></article>';
        }
        if ($karten === '') {
            return '';
        }
        return '<div class="inhalt-breite">' . self::kopfzeile($d)
             . '<div class="karten">' . $karten . '</div></div>';
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
            (string) ($d['alt'] ?? '') !== '' ? (string) $d['alt'] : 'Bild');
        $text   = trim((string) ($d['bildtext'] ?? ''));

        return '<div class="inhalt-breite' . ($breite === 'schmal' ? ' inhalt-breite--schmal' : '') . '">'
             . $bild
             . ($text !== '' ? '<p class="hand hand--klein" style="margin-top:14px">' . Util::h($text) . '</p>' : '')
             . '</div>';
    }

    /** Galerie: drei Formen im Wechsel, damit kein Rasterfeld entsteht. */
    private static function blockGalerie(array $d): string
    {
        $formen = ['form-karte', 'form-blob', 'form-blob-r'];
        $bilder = '';
        foreach ((array) ($d['bilder'] ?? []) as $i => $b) {
            $bilder .= self::bildOderStreifen((string) ($b['bild'] ?? ''), $formen[$i % 3],
                (string) ($b['alt'] ?? '') !== '' ? (string) $b['alt'] : 'Bild ' . ($i + 1));
        }
        if ($bilder === '') {
            return '';
        }
        return '<div class="inhalt-breite">' . self::kopfzeile($d)
             . '<div class="karten">' . $bilder . '</div></div>';
    }

    private static function blockVideo(array $d): string
    {
        $url = trim((string) ($d['url'] ?? ''));
        if ($url === '') {
            return '';
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
             . (trim((string) ($d['text'] ?? '')) !== ''
                ? '<p class="hand hand--klein" style="margin-top:14px">' . self::h($d, 'text') . '</p>' : '')
             . '</div>';
    }

    private static function blockKarten(array $d): string
    {
        $karten = '';
        foreach ((array) ($d['eintraege'] ?? []) as $i => $e) {
            $url   = trim((string) ($e['url'] ?? ''));
            $innen = self::bildOderStreifen((string) ($e['bild'] ?? ''), 'form-karte',
                        (string) ($e['titel'] ?? 'Bild'), 'aspect-ratio:4/3;margin-bottom:20px')
                   . '<h3 style="margin-bottom:10px">' . Util::h((string) ($e['titel'] ?? '')) . '</h3>'
                   . '<p class="gedimmt" style="font-size:16px;margin:0">'
                   . Util::h((string) ($e['text'] ?? '')) . '</p>';

            $karten .= $url !== ''
                ? '<a class="karte" href="' . Util::attr($url) . '">' . $innen . '</a>'
                : '<article class="karte">' . $innen . '</article>';
        }
        if ($karten === '') {
            return '';
        }
        return '<div class="inhalt-breite">' . self::kopfzeile($d)
             . '<div class="karten">' . $karten . '</div></div>';
    }

    /** Zahlen ohne Kasten – die Ziffer ist groß genug, um allein zu stehen. */
    private static function blockZahlen(array $d): string
    {
        $teile = '';
        foreach ((array) ($d['eintraege'] ?? []) as $e) {
            $teile .= '<div class="zahl-teil">'
                    . '<span class="zahl-teil__wert">' . Util::h((string) ($e['wert'] ?? '')) . '</span>'
                    . '<span class="zahl-teil__label">' . Util::h((string) ($e['label'] ?? '')) . '</span>'
                    . '</div>';
        }
        if ($teile === '') {
            return '';
        }
        return '<div class="inhalt-breite abschnitt--eng"><div class="zahlen">' . $teile . '</div></div>';
    }

    private static function blockZitat(array $d): string
    {
        $text = trim((string) ($d['text'] ?? ''));
        if ($text === '') {
            return '';
        }
        return '<div class="inhalt-breite"><figure style="margin:0;max-width:26ch">'
             . '<blockquote class="stimme__text" style="margin:0 0 12px">„' . Util::h($text) . '"</blockquote>'
             . (trim((string) ($d['autor'] ?? '')) !== ''
                ? '<figcaption class="stimme__wer">' . self::h($d, 'autor') . '</figcaption>' : '')
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
        if ($eintraege === []) {
            return '';
        }

        $slug  = (string) (Tenant::workspace()['slug'] ?? '');
        $liste = '';
        foreach ($eintraege as $i => $e) {
            $ziel = !empty($e['id'])
                ? App::url('/buchen.php') . '?w=' . rawurlencode($slug) . '&leistung=' . (int) $e['id']
                : '#buchung';

            $liste .= '<article class="liste__teil">'
                    . '<div class="liste__kopf">'
                    . '<span class="liste__nummer">' . str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) . '</span>'
                    . '<h3 class="liste__titel">' . Util::h((string) ($e['titel'] ?? '')) . '</h3>'
                    . '<span class="liste__preis">' . Util::h((string) ($e['preis'] ?? '')) . '</span>'
                    . '</div>'
                    . '<div class="liste__koerper">'
                    . '<p class="liste__text">' . Util::h((string) ($e['text'] ?? '')) . '</p>'
                    . '<div class="liste__neben">'
                    . (!empty($e['dauer']) ? '<p class="leise" style="margin-bottom:6px">'
                        . Util::h((string) $e['dauer']) . '</p>' : '')
                    . (!empty($e['wer']) ? '<p class="gedimmt" style="font-size:15.5px;margin:0">'
                        . Util::h((string) $e['wer']) . '</p>' : '')
                    . '</div></div>'
                    . '<div class="liste__fuss">'
                    . '<a class="knopf knopf--klein" href="' . Util::attr($ziel) . '">Diesen Kurs buchen</a>'
                    . (!empty($e['note']) ? '<span class="hand hand--klein">' . Util::h((string) $e['note']) . '</span>' : '')
                    . '</div></article>';
        }

        return '<div class="inhalt-breite" id="leistungen">' . self::kopfzeile($d)
             . '<div class="liste">' . $liste . '</div></div>';
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

            $liste .= '<article class="liste__teil">'
                    . '<div class="liste__kopf">'
                    . '<span class="liste__nummer">' . str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) . '</span>'
                    . '<h3 class="liste__titel">' . Util::h((string) ($e['titel'] ?? '')) . '</h3>'
                    . '<span class="liste__preis">' . Util::h((string) ($e['preis'] ?? '')) . '</span>'
                    . '</div>'
                    . '<div class="liste__koerper">'
                    . '<div class="liste__text">' . $merkmale . '</div>'
                    . '<div class="liste__neben">'
                    . (!empty($e['zusatz']) ? '<p class="leise" style="margin:0">' . Util::h((string) $e['zusatz']) . '</p>' : '')
                    . '</div></div>'
                    . (trim((string) ($e['knopf_text'] ?? '')) !== ''
                       ? '<div class="liste__fuss">'
                         . self::knopf($e, 'knopf_text', 'knopf_url', 'knopf knopf--klein')
                         . (!empty($e['hervorheben'])
                            ? '<span class="hand hand--klein">am häufigsten gebucht</span>' : '')
                         . '</div>'
                       : '')
                    . '</article>';
        }
        if ($liste === '') {
            return '';
        }
        return '<div class="inhalt-breite">' . self::kopfzeile($d)
             . '<div class="liste">' . $liste . '</div></div>';
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
        foreach ((array) ($d['eintraege'] ?? []) as $e) {
            $text = trim((string) ($e['text'] ?? ''));
            if ($text === '') {
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

            $stimmen .= '<figure class="stimme" style="margin:0">'
                      . '<blockquote class="stimme__text" style="margin:0 0 10px">„' . Util::h($text) . '"</blockquote>'
                      . '<figcaption class="stimme__wer">' . $wer . '</figcaption></figure>';
        }
        if ($stimmen === '') {
            return '';
        }
        return '<div class="inhalt-breite">'
             . (self::h($d, 'titel') !== ''
                ? '<p class="hand hand--gross" style="margin-bottom:clamp(30px,4vw,54px)">'
                  . self::h($d, 'titel') . '</p>' : '')
             . '<div class="stimmen">' . $stimmen . '</div></div>';
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
        foreach ((array) ($d['eintraege'] ?? []) as $e) {
            $frage = trim((string) ($e['frage'] ?? ''));
            if ($frage === '') {
                continue;
            }
            $liste .= '<div class="frage">'
                    . '<button class="frage__knopf" type="button" data-frage>'
                    . '<span class="frage__zeichen">+</span>'
                    . '<span>' . Util::h($frage) . '</span></button>'
                    . '<div class="frage__antwort">' . nl2br(Util::h((string) ($e['antwort'] ?? ''))) . '</div>'
                    . '</div>';
        }
        if ($liste === '') {
            return '';
        }
        return '<div class="inhalt-breite inhalt-breite--schmal">' . self::kopfzeile($d)
             . '<div class="fragen">' . $liste . '</div></div>';
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

        $html = self::kopfzeile($d);
        foreach ($leute as $i => $u) {
            $links = $i % 2 === 0;
            $bild  = self::bildOderStreifen((string) $u['bild'],
                $links ? 'form-blob' : 'form-blob-r', 'Porträt ' . explode(' ', (string) $u['name'])[0]);

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
        foreach ((array) ($d['bilder'] ?? []) as $b) {
            $url = self::bildUrl((string) ($b['bild'] ?? ''));
            if ($url === '') {
                continue;
            }
            $logos .= '<img src="' . Util::attr($url) . '" alt="' . Util::attr((string) ($b['alt'] ?? '')) . '" '
                    . 'loading="lazy" style="max-height:44px;width:auto;opacity:.65">';
        }
        if ($logos === '') {
            return '';
        }
        return '<div class="inhalt-breite abschnitt--eng">'
             . (self::h($d, 'titel') !== ''
                ? '<p class="leise" style="margin-bottom:22px">' . self::h($d, 'titel') . '</p>' : '')
             . '<div style="display:flex;flex-wrap:wrap;gap:clamp(28px,4vw,60px);align-items:center">'
             . $logos . '</div></div>';
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
             . 'max-width:18ch;margin-bottom:22px">' . self::mitMarker((string) ($d['titel'] ?? '')) . '</h2>'
             . '<div class="gedimmt eng-2" style="margin-bottom:26px">' . self::absatz($d, 'text') . '</div>'
             . self::knopf($d, 'knopf_text', 'knopf_url') . '</div>'
             . ($notiz !== ''
                ? '<p class="schmal hand hand--mittel hand--schief" style="flex:0 1 320px">'
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
            return '';
        }

        $vorteile = '';
        foreach ((array) ($d['vorteile'] ?? []) as $v) {
            $zeile = trim((string) ($v['text'] ?? ''));
            if ($zeile !== '') {
                $vorteile .= '<li>' . Util::h($zeile) . '</li>';
            }
        }
        $vorteile = $vorteile !== '' ? '<ul class="kontokasten__vorteile">' . $vorteile . '</ul>' : '';

        $knopf = trim((string) ($d['knopf_text'] ?? '')) ?: 'Konto anlegen';
        $anmelden = '<p class="kontokasten__klein">Schon ein Konto? '
                  . '<a href="' . Util::attr(Oeffentlich::url('/portal/')) . '">Anmelden</a></p>';

        /* Nur ein Knopf: für Seiten, auf denen schon genug Formulare stehen. */
        if ((string) ($d['stil'] ?? 'formular') === 'knopf') {
            return '<div class="inhalt-breite inhalt-breite--schmal" id="konto">'
                 . self::kopfzeile($d)
                 . '<div class="kontokasten">'
                 . $vorteile
                 . '<a class="knopf knopf--primaer" href="' . Util::attr($ziel) . '">' . Util::h($knopf) . '</a>'
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
             . '<button class="knopf knopf--primaer" type="submit">' . Util::h($knopf) . '</button>'
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
             . '<button class="knopf" type="submit">' . self::h($d, 'knopf_text', 'Anfrage senden') . '</button>'
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
                    . ($frei > 0 ? 'noch ' . $frei . ' Plätze' : 'ausgebucht') . '</p></div>'
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
                      . ($frei > 0 ? 'noch ' . $frei . ' Plätze' : 'ausgebucht') . '</span>'
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
