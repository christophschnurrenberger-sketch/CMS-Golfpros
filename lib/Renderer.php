<?php
/**
 * Renderer – macht aus Bausteinen die fertige Seite.
 *
 * Getrennt von `Bloecke`, weil die Definition selten und der Aufbau oft
 * geändert wird. Der Renderer kennt nur eine Ausgabeform – das HTML der
 * öffentlichen Seite. Der Baukasten zeigt dasselbe, damit die Vorschau
 * nicht lügt.
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
        return '<section class="abschnitt abschnitt--' . Util::attr($typ) . '" id="' . Util::attr((string) ($block['id'] ?? '')) . '">'
             . $inhalt . '</section>';
    }

    /* ---------------------------------------------------------- Hilfen */

    private static function h(array $d, string $feld, string $standard = ''): string
    {
        return Util::h((string) ($d[$feld] ?? $standard));
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

    private static function knopf(array $d, string $textFeld, string $urlFeld, string $klasse = 'knopf knopf--primaer'): string
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

    private static function kopfzeile(array $d, string $klasse = ''): string
    {
        $ober  = self::h($d, 'obertitel');
        $titel = self::h($d, 'titel');
        $text  = self::absatz($d, 'text');
        if ($ober === '' && $titel === '' && $text === '') {
            return '';
        }
        $aus = (string) ($d['ausrichtung'] ?? 'mitte');
        return '<div class="abschnitt__kopf abschnitt__kopf--' . Util::attr($aus) . ' ' . $klasse . '">'
             . ($ober !== '' ? '<span class="obertitel">' . $ober . '</span>' : '')
             . ($titel !== '' ? '<h2>' . $titel . '</h2>' : '')
             . ($text !== '' ? '<div class="fliesstext">' . $text . '</div>' : '')
             . '</div>';
    }

    /* --------------------------------------------------------- Aufbau - */

    private static function blockHero(array $d): string
    {
        $aus   = (string) ($d['ausrichtung'] ?? 'geteilt');
        $hoehe = (string) ($d['hoehe'] ?? 'normal');
        $bild  = self::bildUrl((string) ($d['bild'] ?? ''));

        $text = '<div class="hero__text">'
              . (self::h($d, 'obertitel') !== '' ? '<span class="obertitel">' . self::h($d, 'obertitel') . '</span>' : '')
              . '<h1>' . self::h($d, 'titel') . '</h1>'
              . '<div class="hero__absatz">' . self::absatz($d, 'text') . '</div>'
              . '<div class="knopfreihe">'
              . self::knopf($d, 'knopf_text', 'knopf_url')
              . self::knopf($d, 'knopf2_text', 'knopf2_url', 'knopf knopf--rand')
              . '</div></div>';

        $bildHtml = $bild !== ''
            ? '<div class="hero__bild"><img src="' . Util::attr($bild) . '" alt="" loading="eager"></div>'
            : '<div class="hero__bild hero__bild--leer"></div>';

        return '<div class="inhalt-breite hero hero--' . Util::attr($aus) . ' hero--' . Util::attr($hoehe) . '">'
             . $text . ($aus === 'geteilt' ? $bildHtml : '') . '</div>';
    }

    private static function blockUeberschrift(array $d): string
    {
        return '<div class="inhalt-breite">' . self::kopfzeile($d) . '</div>';
    }

    private static function blockText(array $d): string
    {
        $spalten = (string) ($d['spalten'] ?? '1') === '2' ? ' fliesstext--zwei' : '';
        return '<div class="inhalt-breite inhalt-breite--schmal">'
             . (self::h($d, 'titel') !== '' ? '<h2 class="mb-4">' . self::h($d, 'titel') . '</h2>' : '')
             . '<div class="fliesstext' . $spalten . '">' . self::absatz($d, 'text') . '</div></div>';
    }

    private static function blockSpalten(array $d): string
    {
        $karten = '';
        foreach ((array) ($d['eintraege'] ?? []) as $e) {
            $karten .= '<div class="merkmal">'
                     . (!empty($e['icon']) ? '<span class="merkmal__symbol">' . Icon::svg((string) $e['icon'], 22) . '</span>' : '')
                     . '<h3>' . Util::h((string) ($e['titel'] ?? '')) . '</h3>'
                     . '<p>' . nl2br(Util::h((string) ($e['text'] ?? ''))) . '</p></div>';
        }
        return '<div class="inhalt-breite">' . self::kopfzeile($d)
             . '<div class="raster-auto">' . $karten . '</div></div>';
    }

    private static function blockTrenner(array $d): string
    {
        return '<div class="inhalt-breite">'
             . (!empty($d['sichtbar']) ? '<hr class="trennlinie">' : '<div style="height:1px"></div>') . '</div>';
    }

    /* --------------------------------------------------------- Inhalt - */

    private static function blockBild(array $d): string
    {
        $bild = self::bildUrl((string) ($d['bild'] ?? ''));
        if ($bild === '') {
            return '';
        }
        $voll = (string) ($d['breite'] ?? 'inhalt') === 'voll';
        return '<div class="' . ($voll ? 'inhalt-voll' : 'inhalt-breite') . '">'
             . '<figure class="bildblock"><img src="' . Util::attr($bild) . '" alt="'
             . self::h($d, 'alt') . '" loading="lazy">'
             . (self::h($d, 'bildtext') !== '' ? '<figcaption>' . self::h($d, 'bildtext') . '</figcaption>' : '')
             . '</figure></div>';
    }

    private static function blockGalerie(array $d): string
    {
        $bilder = '';
        foreach ((array) ($d['bilder'] ?? []) as $b) {
            $url = self::bildUrl((string) ($b['bild'] ?? ''));
            if ($url === '') {
                continue;
            }
            $bilder .= '<img src="' . Util::attr($url) . '" alt="' . Util::attr((string) ($b['alt'] ?? '')) . '" loading="lazy">';
        }
        if ($bilder === '') {
            return '';
        }
        return '<div class="inhalt-breite">' . self::kopfzeile($d) . '<div class="galerie">' . $bilder . '</div></div>';
    }

    private static function blockVideo(array $d): string
    {
        $url = (string) ($d['url'] ?? '');
        $einbetten = '';
        if (preg_match('~(?:youtube\.com/watch\?v=|youtu\.be/)([A-Za-z0-9_-]{6,})~', $url, $t)) {
            $einbetten = 'https://www.youtube-nocookie.com/embed/' . $t[1];
        } elseif (preg_match('~vimeo\.com/(\d+)~', $url, $t)) {
            $einbetten = 'https://player.vimeo.com/video/' . $t[1];
        }
        $rahmen = $einbetten !== ''
            ? '<div class="videorahmen"><iframe src="' . Util::attr($einbetten) . '" loading="lazy" '
              . 'allowfullscreen title="Video"></iframe></div>'
            : '<div class="videorahmen videorahmen--leer">Hier erscheint dein Video.</div>';
        return '<div class="inhalt-breite inhalt-breite--schmal">' . self::kopfzeile($d) . $rahmen . '</div>';
    }

    private static function blockKarten(array $d): string
    {
        $karten = '';
        foreach ((array) ($d['eintraege'] ?? []) as $e) {
            $bild = self::bildUrl((string) ($e['bild'] ?? ''));
            $inhalt = ($bild !== '' ? '<div class="karte-bild"><img src="' . Util::attr($bild) . '" alt="" loading="lazy"></div>' : '')
                    . '<div class="karte-text"><h3>' . Util::h((string) ($e['titel'] ?? '')) . '</h3>'
                    . '<p>' . nl2br(Util::h((string) ($e['text'] ?? ''))) . '</p></div>';
            $karten .= !empty($e['url'])
                ? '<a class="inhaltskarte" href="' . Util::attr((string) $e['url']) . '">' . $inhalt . '</a>'
                : '<div class="inhaltskarte">' . $inhalt . '</div>';
        }
        return '<div class="inhalt-breite">' . self::kopfzeile($d) . '<div class="raster-auto">' . $karten . '</div></div>';
    }

    private static function blockZahlen(array $d): string
    {
        $zahlen = '';
        foreach ((array) ($d['eintraege'] ?? []) as $e) {
            $zahlen .= '<div class="zahl"><span class="zahl__wert">' . Util::h((string) ($e['wert'] ?? ''))
                     . '</span><span class="zahl__label">' . Util::h((string) ($e['label'] ?? '')) . '</span></div>';
        }
        return '<div class="inhalt-breite"><div class="zahlenreihe">' . $zahlen . '</div></div>';
    }

    private static function blockZitat(array $d): string
    {
        return '<div class="inhalt-breite inhalt-breite--schmal"><blockquote class="grosszitat">'
             . '<p>' . self::h($d, 'text') . '</p>'
             . (self::h($d, 'autor') !== '' ? '<cite>' . self::h($d, 'autor') . '</cite>' : '')
             . '</blockquote></div>';
    }

    /* ---------------------------------------------------- Überzeugung - */

    private static function blockLeistungen(array $d): string
    {
        $eintraege = (array) ($d['eintraege'] ?? []);

        if (!empty($d['automatisch'])) {
            $eintraege = [];
            foreach (Tenant::all('services', 'aktiv = 1 AND online_buchbar = 1', [], 'position, name', 8) as $s) {
                $eintraege[] = [
                    'titel' => (string) $s['name'],
                    'text'  => Util::kuerzen((string) $s['beschreibung'], 160),
                    'preis' => Util::geldKurz((int) $s['preis_cent']),
                    'dauer' => (int) $s['dauer_min'] . ' Minuten',
                    'id'    => (int) $s['id'],
                ];
            }
        }

        $liste = '';
        foreach ($eintraege as $e) {
            $liste .= '<div class="leistung">'
                    . '<div class="leistung__text"><h3>' . Util::h((string) ($e['titel'] ?? '')) . '</h3>'
                    . '<p>' . Util::h((string) ($e['text'] ?? '')) . '</p></div>'
                    . '<div class="leistung__preis">'
                    . '<span class="leistung__betrag">' . Util::h((string) ($e['preis'] ?? '')) . '</span>'
                    . (!empty($e['dauer']) ? '<span class="leistung__dauer">' . Util::h((string) $e['dauer']) . '</span>' : '')
                    . (!empty($e['id']) ? '<a class="knopf knopf--klein" href="#buchung">Buchen</a>' : '')
                    . '</div></div>';
        }
        if ($liste === '') {
            return '';
        }
        return '<div class="inhalt-breite" id="leistungen">' . self::kopfzeile($d)
             . '<div class="leistungsliste">' . $liste . '</div></div>';
    }

    private static function blockPreise(array $d): string
    {
        $karten = '';
        foreach ((array) ($d['eintraege'] ?? []) as $e) {
            $merkmale = '';
            foreach (preg_split('/\r?\n/', (string) ($e['merkmale'] ?? '')) ?: [] as $m) {
                if (trim($m) === '') {
                    continue;
                }
                $merkmale .= '<li>' . Icon::svg('check', 15) . Util::h(trim($m)) . '</li>';
            }
            $karten .= '<div class="preiskarte' . (!empty($e['hervorheben']) ? ' preiskarte--hervor' : '') . '">'
                     . (!empty($e['hervorheben']) ? '<span class="preiskarte__marke">Beliebt</span>' : '')
                     . '<h3>' . Util::h((string) ($e['titel'] ?? '')) . '</h3>'
                     . '<div class="preiskarte__preis">' . Util::h((string) ($e['preis'] ?? ''))
                     . (!empty($e['zusatz']) ? '<span>' . Util::h((string) $e['zusatz']) . '</span>' : '') . '</div>'
                     . ($merkmale !== '' ? '<ul class="hakenliste">' . $merkmale . '</ul>' : '')
                     . self::knopf($e, 'knopf_text', 'knopf_url', 'knopf knopf--primaer knopf--voll')
                     . '</div>';
        }
        return '<div class="inhalt-breite">' . self::kopfzeile($d) . '<div class="preisraster">' . $karten . '</div></div>';
    }

    private static function blockTestimonials(array $d): string
    {
        $karten = '';
        foreach ((array) ($d['eintraege'] ?? []) as $e) {
            $sterne = (int) ($e['sterne'] ?? 5);
            $sternHtml = '';
            for ($i = 0; $i < max(0, min(5, $sterne)); $i++) {
                $sternHtml .= Icon::svg('star', 14);
            }
            $karten .= '<figure class="stimme">'
                     . ($sternHtml !== '' ? '<div class="stimme__sterne">' . $sternHtml . '</div>' : '')
                     . '<blockquote>' . Util::h((string) ($e['text'] ?? '')) . '</blockquote>'
                     . '<figcaption><strong>' . Util::h((string) ($e['name'] ?? '')) . '</strong>'
                     . (!empty($e['zusatz']) ? '<span>' . Util::h((string) $e['zusatz']) . '</span>' : '')
                     . '</figcaption></figure>';
        }
        if ($karten === '') {
            return '';
        }
        return '<div class="inhalt-breite">' . self::kopfzeile($d) . '<div class="raster-auto">' . $karten . '</div></div>';
    }

    private static function blockFaq(array $d): string
    {
        $liste = '';
        foreach ((array) ($d['eintraege'] ?? []) as $e) {
            $liste .= '<details class="frage"><summary>' . Util::h((string) ($e['frage'] ?? ''))
                    . Icon::svg('chevron-down', 17) . '</summary>'
                    . '<div class="frage__antwort">' . nl2br(Util::h((string) ($e['antwort'] ?? ''))) . '</div></details>';
        }
        if ($liste === '') {
            return '';
        }
        return '<div class="inhalt-breite inhalt-breite--schmal">' . self::kopfzeile($d)
             . '<div class="fragen">' . $liste . '</div></div>';
    }

    private static function blockTeam(array $d): string
    {
        $karten = '';
        foreach (Tenant::all('users', 'aktiv = 1', [], 'id', 8) as $u) {
            $bild = self::bildUrl((string) $u['bild']);
            $karten .= '<div class="trainer">'
                     . ($bild !== ''
                        ? '<img class="trainer__bild" src="' . Util::attr($bild) . '" alt="" loading="lazy">'
                        : '<span class="trainer__bild trainer__bild--leer" style="background:'
                          . Util::attr(Util::avatarFarbe((string) $u['name'])) . '">'
                          . Util::h(Util::initialen((string) $u['name'])) . '</span>')
                     . '<h3>' . Util::h((string) $u['name']) . '</h3>'
                     . (!empty($u['titel']) ? '<span class="trainer__titel">' . Util::h((string) $u['titel']) . '</span>' : '')
                     . (!empty($u['bio']) ? '<p>' . Util::h(Util::kuerzen((string) $u['bio'], 180)) . '</p>' : '')
                     . '</div>';
        }
        return '<div class="inhalt-breite">' . self::kopfzeile($d) . '<div class="raster-auto">' . $karten . '</div></div>';
    }

    private static function blockLogos(array $d): string
    {
        $logos = '';
        foreach ((array) ($d['bilder'] ?? []) as $b) {
            $url = self::bildUrl((string) ($b['bild'] ?? ''));
            if ($url === '') {
                continue;
            }
            $logos .= '<img src="' . Util::attr($url) . '" alt="' . Util::attr((string) ($b['alt'] ?? '')) . '" loading="lazy">';
        }
        if ($logos === '') {
            return '';
        }
        return '<div class="inhalt-breite"><div class="logoreihe">'
             . (self::h($d, 'titel') !== '' ? '<span class="logoreihe__titel">' . self::h($d, 'titel') . '</span>' : '')
             . '<div class="logoreihe__bilder">' . $logos . '</div></div></div>';
    }

    /* -------------------------------------------------------- Handlung */

    private static function blockCta(array $d): string
    {
        $stil = (string) ($d['stil'] ?? 'marke');
        return '<div class="inhalt-breite"><div class="aufruf aufruf--' . Util::attr($stil) . '">'
             . '<div class="aufruf__text"><h2>' . self::h($d, 'titel') . '</h2>'
             . '<p>' . self::h($d, 'text') . '</p></div>'
             . '<div>' . self::knopf($d, 'knopf_text', 'knopf_url', 'knopf knopf--gross knopf--auf-farbe') . '</div>'
             . '</div></div>';
    }

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

        $auswahl = '';
        foreach ($leistungen as $s) {
            $auswahl .= '<option value="' . (int) $s['id'] . '">' . Util::h((string) $s['name'])
                      . ' · ' . (int) $s['dauer_min'] . ' Min · ' . Util::geldKurz((int) $s['preis_cent']) . '</option>';
        }
        if ($auswahl === '') {
            return '';
        }

        return '<div class="inhalt-breite inhalt-breite--schmal" id="buchung">' . self::kopfzeile($d)
             . '<div class="buchungsfenster">'
             . '<form method="get" action="' . Util::attr(App::url('/buchen.php')) . '" class="buchungsform">'
             . '<input type="hidden" name="w" value="' . Util::attr((string) (Tenant::workspace()['slug'] ?? '')) . '">'
             . '<div class="buchungsform__feld"><label for="bf-leistung">Leistung</label>'
             . '<select id="bf-leistung" name="leistung">' . $auswahl . '</select></div>'
             . '<div class="buchungsform__feld"><label for="bf-datum">Ab wann</label>'
             . '<input id="bf-datum" type="date" name="datum" value="' . Util::attr(Util::heute()) . '" min="'
             . Util::attr(Util::heute()) . '"></div>'
             . '<button class="knopf knopf--primaer" type="submit">Freie Zeiten anzeigen</button>'
             . '</form></div></div>';
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
                ['name' => 'nachricht', 'label' => 'Nachricht', 'typ' => 'mehrzeilig'],
            ];
        }

        $html = '';
        foreach ($felder as $f) {
            $name  = Util::attr((string) ($f['name'] ?? ''));
            $label = Util::h((string) ($f['label'] ?? ''));
            $pflicht = !empty($f['pflicht']) ? ' required' : '';
            $html .= '<div class="formfeld"><label for="ff-' . $name . '">' . $label
                   . (!empty($f['pflicht']) ? ' <span class="pflicht">*</span>' : '') . '</label>';
            switch ((string) ($f['typ'] ?? 'text')) {
                case 'mehrzeilig':
                    $html .= '<textarea id="ff-' . $name . '" name="' . $name . '" rows="4"' . $pflicht . '></textarea>';
                    break;
                case 'auswahl':
                    $html .= '<select id="ff-' . $name . '" name="' . $name . '"' . $pflicht . '>';
                    foreach ((array) ($f['optionen'] ?? []) as $o) {
                        $html .= '<option>' . Util::h((string) $o) . '</option>';
                    }
                    $html .= '</select>';
                    break;
                default:
                    $html .= '<input id="ff-' . $name . '" type="' . Util::attr((string) ($f['typ'] ?? 'text'))
                           . '" name="' . $name . '"' . $pflicht . '>';
            }
            $html .= '</div>';
        }

        return '<div class="inhalt-breite inhalt-breite--schmal" id="kontakt">' . self::kopfzeile($d)
             . '<form class="anfrageform" method="post" action="' . Util::attr(App::url('/anfrage.php')) . '">'
             . '<input type="hidden" name="w" value="' . Util::attr((string) (Tenant::workspace()['slug'] ?? '')) . '">'
             . '<input type="hidden" name="form_id" value="' . ($form ? (int) $form['id'] : 0) . '">'
             . '<input type="text" name="website" class="honigtopf" tabindex="-1" autocomplete="off" aria-hidden="true">'
             . $html
             . '<label class="einwilligung"><input type="checkbox" name="einwilligung" value="1" required>'
             . '<span>Ich bin mit der Verarbeitung meiner Angaben zur Bearbeitung der Anfrage einverstanden. '
             . 'Die Einwilligung kann ich jederzeit widerrufen.</span></label>'
             . '<button class="knopf knopf--primaer" type="submit">'
             . self::h($d, 'knopf_text', 'Absenden') . '</button>'
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

        $karten = '';
        foreach ($produkte as $pr) {
            $bild = self::bildUrl((string) $pr['bild']);
            $karten .= '<a class="produktkarte" href="' . Util::attr(App::url('/kaufen.php?w='
                     . rawurlencode((string) (Tenant::workspace()['slug'] ?? '')) . '&p=' . (int) $pr['id'])) . '">'
                     . ($bild !== '' ? '<div class="karte-bild"><img src="' . Util::attr($bild) . '" alt="" loading="lazy"></div>' : '')
                     . '<div class="karte-text">'
                     . '<span class="produktkarte__art">' . Util::h(Commerce::PRODUKT_ARTEN[(string) $pr['art']][0] ?? '') . '</span>'
                     . '<h3>' . Util::h((string) $pr['name']) . '</h3>'
                     . '<p>' . Util::h(Util::kuerzen((string) ($pr['kurztext'] ?: $pr['beschreibung']), 110)) . '</p>'
                     . '<div class="produktkarte__preis">' . Util::geldKurz((int) $pr['preis_cent'])
                     . ((int) $pr['vergleichspreis_cent'] > (int) $pr['preis_cent']
                        ? '<s>' . Util::geldKurz((int) $pr['vergleichspreis_cent']) . '</s>' : '')
                     . '</div></div></a>';
        }
        if ($karten === '') {
            return '';
        }
        return '<div class="inhalt-breite" id="angebote">' . self::kopfzeile($d)
             . '<div class="raster-auto">' . $karten . '</div></div>';
    }

    private static function blockKurse(array $d): string
    {
        $kurse = Tenant::all('courses', "status = 'veroeffentlicht'", [], 'position, id', max(1, (int) ($d['anzahl'] ?? 3)));
        $karten = '';
        foreach ($kurse as $k) {
            $bild = self::bildUrl((string) $k['bild']);
            $karten .= '<div class="inhaltskarte">'
                     . ($bild !== '' ? '<div class="karte-bild"><img src="' . Util::attr($bild) . '" alt="" loading="lazy"></div>' : '')
                     . '<div class="karte-text"><h3>' . Util::h((string) $k['titel']) . '</h3>'
                     . '<p>' . Util::h(Util::kuerzen((string) $k['kurztext'], 120)) . '</p>'
                     . '<div class="karte-fuss"><span>' . Courses::lektionenAnzahl((int) $k['id']) . ' Lektionen</span>'
                     . '<strong>' . ((int) $k['preis_cent'] > 0 ? Util::geldKurz((int) $k['preis_cent']) : 'Kostenlos') . '</strong>'
                     . '</div></div></div>';
        }
        if ($karten === '') {
            return '';
        }
        return '<div class="inhalt-breite">' . self::kopfzeile($d) . '<div class="raster-auto">' . $karten . '</div></div>';
    }

    private static function blockEvents(array $d): string
    {
        $events = Tenant::all('events', "status = 'veroeffentlicht' AND start >= :jetzt",
            ['jetzt' => Util::jetzt()], 'start', max(1, (int) ($d['anzahl'] ?? 3)));
        $liste = '';
        foreach ($events as $e) {
            $frei = Events::freiePlaetze($e);
            $liste .= '<div class="eventzeile">'
                    . '<div class="eventzeile__datum"><span class="eventzeile__tag">'
                    . date('j', strtotime((string) $e['start'])) . '</span><span class="eventzeile__monat">'
                    . substr(Util::monatName((int) date('n', strtotime((string) $e['start']))), 0, 3) . '</span></div>'
                    . '<div class="eventzeile__text"><h3>' . Util::h((string) $e['titel']) . '</h3>'
                    . '<p>' . Util::h(Util::datumLang((string) $e['start'])) . ' · '
                    . Util::h((string) ($e['ort_text'] ?: Events::ortName((int) $e['location_id']))) . '</p></div>'
                    . '<div class="eventzeile__aktion">'
                    . '<span class="eventzeile__preis">' . Util::geldKurz((int) $e['preis_cent']) . '</span>'
                    . ($frei > 0
                        ? '<span class="eventzeile__frei">noch ' . $frei . ' Plätze</span>'
                        : '<span class="eventzeile__frei eventzeile__frei--voll">ausgebucht</span>')
                    . '</div></div>';
        }
        if ($liste === '') {
            return '';
        }
        return '<div class="inhalt-breite">' . self::kopfzeile($d) . '<div class="eventliste">' . $liste . '</div></div>';
    }

    private static function blockBlog(array $d): string
    {
        $posts = Tenant::all('posts', "status = 'veroeffentlicht'", [], 'veroeffentlicht DESC',
            max(1, (int) ($d['anzahl'] ?? 3)));
        $karten = '';
        $slug = (string) (Tenant::workspace()['slug'] ?? '');
        foreach ($posts as $b) {
            $bild = self::bildUrl((string) $b['bild']);
            $karten .= '<a class="inhaltskarte" href="' . Util::attr(App::url('/site.php?w=' . rawurlencode($slug)
                     . '&beitrag=' . rawurlencode((string) $b['slug']))) . '">'
                     . ($bild !== '' ? '<div class="karte-bild"><img src="' . Util::attr($bild) . '" alt="" loading="lazy"></div>' : '')
                     . '<div class="karte-text"><span class="karte-datum">'
                     . Util::h(Util::datum((string) $b['veroeffentlicht'])) . '</span>'
                     . '<h3>' . Util::h((string) $b['titel']) . '</h3>'
                     . '<p>' . Util::h(Util::kuerzen((string) $b['auszug'], 110)) . '</p></div></a>';
        }
        if ($karten === '') {
            return '';
        }
        return '<div class="inhalt-breite">' . self::kopfzeile($d) . '<div class="raster-auto">' . $karten . '</div></div>';
    }

    private static function blockKontakt(array $d): string
    {
        $ort = (int) ($d['location_id'] ?? 0) > 0
            ? Tenant::find('locations', (int) $d['location_id'])
            : Tenant::one('locations', 'aktiv = 1');

        $adresse = '';
        $karte = '';
        if ($ort) {
            $adresse = '<address class="anschrift"><strong>' . Util::h((string) $ort['name']) . '</strong><br>'
                     . Util::h((string) $ort['strasse']) . '<br>'
                     . Util::h(trim(((string) $ort['plz']) . ' ' . ((string) $ort['ort']))) . '</address>';
            if (!empty($d['karte'])) {
                $suche = urlencode(trim(((string) $ort['name']) . ', ' . ((string) $ort['strasse'])
                       . ', ' . ((string) $ort['plz']) . ' ' . ((string) $ort['ort'])));
                $karte = '<div class="kartenrahmen"><iframe loading="lazy" title="Karte" '
                       . 'src="https://www.openstreetmap.org/export/embed.html?bbox=&layer=mapnik&marker="'
                       . ' data-suche="' . Util::attr($suche) . '"></iframe>'
                       . '<a class="kartenrahmen__link" target="_blank" rel="noopener" '
                       . 'href="https://www.openstreetmap.org/search?query=' . $suche . '">In Karten öffnen</a></div>';
            }
        }

        return '<div class="inhalt-breite"><div class="kontaktblock">'
             . '<div>' . self::kopfzeile($d, 'abschnitt__kopf--links') . $adresse . '</div>'
             . '<div>' . $karte . '</div></div></div>';
    }
}
