<?php
/**
 * Website – die öffentliche Seite des Pros.
 *
 * Das Aussehen kommt aus wenigen Werten, die im Workspace liegen: eine
 * Markenfarbe, eine Schrift, ein Radius, ein Stil. Daraus werden
 * CSS-Variablen. Der Vorteil gegenüber fertigen Themes: Es gibt nur ein
 * Stylesheet, das gepflegt wird, und die Seite eines Pros unterscheidet
 * sich trotzdem sichtbar von der des nächsten.
 */
final class Website
{
    public const STILE = [
        'modern'   => ['Modern', 'Klare Flächen, viel Luft, zurückhaltende Schatten.'],
        'klassisch'=> ['Klassisch', 'Serifenschrift in Überschriften, ruhiger, traditioneller.'],
        'kraftvoll'=> ['Kraftvoll', 'Große Typografie, starke Kontraste, dunkler Titelbereich.'],
    ];

    public const SCHRIFTEN = [
        'Inter'           => 'Inter · sachlich, sehr gut lesbar',
        'Source Sans 3'   => 'Source Sans · freundlich, offen',
        'DM Sans'         => 'DM Sans · rund, modern',
        'Libre Baskerville' => 'Libre Baskerville · Serife, klassisch',
        'Manrope'         => 'Manrope · technisch, präzise',
    ];

    /** CSS-Variablen der Marke – als style-Attribut auf den Seitenrahmen. */
    public static function stilVariablen(): string
    {
        $b = Tenant::branding();
        $stil = (string) ($b['stil'] ?? 'modern');
        return implode(';', [
            '--marke: ' . self::sauber((string) $b['primaer']),
            '--marke-dunkel: color-mix(in srgb, ' . self::sauber((string) $b['primaer']) . ' 80%, #000)',
            '--marke-hell: color-mix(in srgb, ' . self::sauber((string) $b['primaer']) . ' 8%, #fff)',
            '--akzent: ' . self::sauber((string) $b['akzent']),
            '--radius: ' . (int) $b['radius'] . 'px',
            '--schrift: "' . self::sauber((string) $b['schrift']) . '", system-ui, sans-serif',
            '--titel-schrift: ' . ($stil === 'klassisch'
                ? '"Libre Baskerville", Georgia, serif'
                : '"' . self::sauber((string) $b['schrift']) . '", system-ui, sans-serif'),
        ]) . ';';
    }

    private static function sauber(string $wert): string
    {
        return preg_replace('/[^A-Za-z0-9#,.%()\s-]/', '', $wert) ?? '';
    }

    public static function schriftLink(): string
    {
        $b = Tenant::branding();
        $schriften = [(string) $b['schrift']];
        if ((string) ($b['stil'] ?? '') === 'klassisch') {
            $schriften[] = 'Libre Baskerville';
        }
        $teile = [];
        foreach (array_unique($schriften) as $s) {
            $teile[] = 'family=' . str_replace(' ', '+', $s) . ':wght@400;500;600;700';
        }
        return 'https://fonts.googleapis.com/css2?' . implode('&', $teile) . '&display=swap';
    }

    /* ------------------------------------------------------- Bausteine - */

    public static function kopf(?array $aktuelleSeite = null): string
    {
        $logo = (string) (Tenant::workspace()['logo'] ?? '');
        $slug = (string) (Tenant::workspace()['slug'] ?? '');

        $menue = '';
        foreach (Pages::menue() as $s) {
            $aktiv = $aktuelleSeite && (int) $aktuelleSeite['id'] === (int) $s['id'];
            $menue .= '<a class="kopf__link' . ($aktiv ? ' ist-aktiv' : '') . '" href="'
                    . Util::attr(Pages::url($s)) . '">' . Util::h((string) $s['titel']) . '</a>';
        }

        $start = Pages::startseite();
        $startUrl = $start ? Pages::url($start) : App::url('/site.php?w=' . rawurlencode($slug));

        return '<header class="kopf-band"><div class="inhalt-breite kopf-band__innen">'
             . '<a class="kopf__marke" href="' . Util::attr($startUrl) . '">'
             . ($logo !== '' && is_file(GP_ROOT . '/' . ltrim($logo, '/'))
                ? '<img src="' . Util::attr(App::url($logo)) . '" alt="' . Util::attr(Tenant::name()) . '">'
                : '<span>' . Util::h(Tenant::name()) . '</span>')
             . '</a>'
             . '<nav class="kopf__navi">' . $menue . '</nav>'
             . '<a class="knopf knopf--primaer knopf--klein kopf__aktion" href="#buchung">Termin buchen</a>'
             . '<button class="kopf__menue" aria-label="Menü" onclick="document.querySelector(\'.kopf__navi\').classList.toggle(\'ist-offen\')">'
             . Icon::svg('menu', 20) . '</button>'
             . '</div></header>';
    }

    public static function fuss(): string
    {
        $slug = (string) (Tenant::workspace()['slug'] ?? '');
        $ort = Tenant::one('locations', 'aktiv = 1');

        $rechtliches = '';
        foreach (Tenant::all('pages', "status = 'veroeffentlicht' AND slug IN ('impressum','datenschutz','agb')",
                 [], 'position') as $s) {
            $rechtliches .= '<a href="' . Util::attr(Pages::url($s)) . '">' . Util::h((string) $s['titel']) . '</a>';
        }

        $menue = '';
        foreach (Pages::menue() as $s) {
            $menue .= '<a href="' . Util::attr(Pages::url($s)) . '">' . Util::h((string) $s['titel']) . '</a>';
        }

        return '<footer class="fuss-band"><div class="inhalt-breite">'
             . '<div class="fuss-band__spalten">'
             . '<div><div class="fuss-band__marke">' . Util::h(Tenant::name()) . '</div>'
             . ($ort ? '<address class="fuss-band__adresse">'
                 . Util::h((string) $ort['strasse']) . '<br>'
                 . Util::h(trim(((string) $ort['plz']) . ' ' . ((string) $ort['ort']))) . '</address>' : '')
             . '</div>'
             . ($menue !== '' ? '<nav class="fuss-band__navi"><span>Seiten</span>' . $menue . '</nav>' : '')
             . ($rechtliches !== '' ? '<nav class="fuss-band__navi"><span>Rechtliches</span>' . $rechtliches . '</nav>' : '')
             . '</div>'
             . '<div class="fuss-band__unten">'
             . '<span>© ' . date('Y') . ' ' . Util::h(Tenant::name()) . '</span>'
             . '<a href="' . Util::attr(App::url('/portal/')) . '">Kundenzugang</a>'
             . '</div></div></footer>'
             . self::hinweisband();
    }

    /**
     * Cookie-Hinweis – nur wenn er eingeschaltet ist.
     *
     * Für die eigene, cookiefreie Zählung ist keine Einwilligung nötig:
     * Es wird nichts auf dem Gerät gespeichert und nichts an Dritte
     * gegeben. Wer zusätzlich Karten, Videos oder externe Schriften
     * einbindet, schaltet den Hinweis unter Einstellungen → Datenschutz
     * ein. Ein Banner, das man nicht braucht, ist kein Datenschutz,
     * sondern nur eine Hürde vor dem Inhalt.
     *
     * Die Entscheidung merkt sich der Browser selbst; sie verlässt das
     * Gerät nicht und landet in keiner Datenbank.
     */
    private static function hinweisband(): string
    {
        if (!Tenant::einstellung('cookie_banner', false)) {
            return '';
        }
        $datenschutz = Tenant::one('pages', "slug = 'datenschutz' AND status = 'veroeffentlicht'");
        $link = $datenschutz !== null
            ? '<a href="' . Util::attr(Pages::url($datenschutz)) . '">Mehr dazu</a>' : '';

        return '<div class="hinweisband" id="hinweisband" hidden>'
             . '<div class="hinweisband__text">Diese Website kommt ohne Werbe-Cookies aus. '
             . 'Für eingebundene Inhalte wie Karten oder Videos werden beim Anzeigen Daten an '
             . 'deren Anbieter übertragen. ' . $link . '</div>'
             . '<button class="knopf knopf--klein" type="button" id="hinweisband-ok">Verstanden</button>'
             . '</div>'
             . '<script>(function(){'
             . 'var b=document.getElementById("hinweisband");if(!b)return;'
             . 'try{if(localStorage.getItem("gp-hinweis")==="1")return;}catch(e){}'
             . 'b.hidden=false;'
             . 'document.getElementById("hinweisband-ok").addEventListener("click",function(){'
             . 'b.hidden=true;try{localStorage.setItem("gp-hinweis","1");}catch(e){}});'
             . '})();</script>';
    }

    /**
     * Die vollständige Seite. Der Renderer kennt nur Bausteine – Kopf,
     * Fuß und Kopfdaten kommen von hier.
     */
    public static function ausgeben(array $seite, string $inhalt = ''): string
    {
        $seo = Pages::seo($seite);
        $branding = Tenant::branding();
        $bild = (string) ($seo['bild'] ?? '');

        $html = '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8">'
              . '<meta name="viewport" content="width=device-width, initial-scale=1">'
              . '<title>' . Util::h((string) ($seo['titel'] ?: $seite['titel'])) . '</title>';

        if ((string) ($seo['beschreibung'] ?? '') !== '') {
            $html .= '<meta name="description" content="' . Util::attr((string) $seo['beschreibung']) . '">';
        }
        if (empty($seo['index'])) {
            $html .= '<meta name="robots" content="noindex">';
        }
        $html .= '<meta property="og:title" content="' . Util::attr((string) ($seo['titel'] ?: $seite['titel'])) . '">'
               . '<meta property="og:description" content="' . Util::attr((string) ($seo['beschreibung'] ?? '')) . '">'
               . '<meta property="og:type" content="website">'
               . ($bild !== '' ? '<meta property="og:image" content="' . Util::attr(App::absolut($bild)) . '">' : '')
               . '<link rel="preconnect" href="https://fonts.googleapis.com">'
               . '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
               . '<link href="' . Util::attr(self::schriftLink()) . '" rel="stylesheet">'
               . '<link rel="stylesheet" href="' . Util::attr(App::asset('assets/css/site.css')) . '">'
               . SEO::strukturierteDaten()
               . '</head><body class="seite seite--' . Util::attr((string) ($branding['stil'] ?? 'modern')) . '" '
               . 'style="' . Util::attr(self::stilVariablen()) . '">'
               . self::kopf($seite)
               . '<main>' . $inhalt . '</main>'
               . self::fuss()
               . '<script src="' . Util::attr(App::asset('assets/js/site.js')) . '"></script>'
               . '</body></html>';

        return $html;
    }
}
