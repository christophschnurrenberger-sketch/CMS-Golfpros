<?php
/**
 * SEO – Prüfung der Website auf die Dinge, die tatsächlich zählen.
 *
 * Bewusst keine „SEO-Punktzahl“ mit 87 Kriterien, von denen 80 niemandem
 * helfen. Geprüft wird, was ein Golf Professional selbst beheben kann und
 * was Google nachweislich auswertet: Titel, Beschreibung, eine H1 je Seite,
 * Bildbeschreibungen, interne Verweise, Ladezeit, lokale Angaben.
 */
final class SEO
{
    /**
     * @return array{score:int,pruefungen:array<int,array{titel:string,status:string,text:string,seite:string,link:string}>}
     */
    public static function pruefen(): array
    {
        $pruefungen = [];
        $seiten = Pages::alle();

        foreach ($seiten as $seite) {
            if ((string) $seite['status'] !== 'veroeffentlicht') {
                continue;
            }
            $seo = Pages::seo($seite);
            $name = (string) $seite['titel'];
            $link = '/app/seite.php?id=' . (int) $seite['id'];

            $titel = trim((string) $seo['titel']);
            $laenge = mb_strlen($titel);
            $pruefungen[] = [
                'titel' => 'Seitentitel',
                'status' => $laenge >= 30 && $laenge <= 60 ? 'gut' : ($laenge > 0 ? 'mittel' : 'schlecht'),
                'text' => $laenge === 0
                    ? 'Kein Titel hinterlegt – Google zeigt dann irgendetwas von der Seite.'
                    : ($laenge < 30 ? 'Mit ' . $laenge . ' Zeichen sehr kurz. 50 bis 60 nutzen den Platz besser.'
                       : ($laenge > 60 ? 'Mit ' . $laenge . ' Zeichen zu lang; Google schneidet nach etwa 60 ab.'
                          : 'Gute Länge (' . $laenge . ' Zeichen).')),
                'seite' => $name, 'link' => $link,
            ];

            $beschreibung = trim((string) $seo['beschreibung']);
            $bl = mb_strlen($beschreibung);
            $pruefungen[] = [
                'titel' => 'Meta-Beschreibung',
                'status' => $bl >= 120 && $bl <= 160 ? 'gut' : ($bl > 0 ? 'mittel' : 'schlecht'),
                'text' => $bl === 0
                    ? 'Fehlt. Sie ist der Werbetext im Suchergebnis – ohne sie entscheidet Google.'
                    : ($bl < 120 ? 'Mit ' . $bl . ' Zeichen kurz; 140 bis 155 sind ideal.'
                       : ($bl > 160 ? 'Mit ' . $bl . ' Zeichen zu lang.' : 'Gute Länge (' . $bl . ' Zeichen).')),
                'seite' => $name, 'link' => $link,
            ];

            $bloecke = Pages::bloecke($seite);
            $h1 = 0;
            $bilderOhneAlt = 0;
            $woerter = 0;
            foreach ($bloecke as $b) {
                if (($b['typ'] ?? '') === 'hero') {
                    $h1++;
                }
                $daten = (array) ($b['daten'] ?? []);
                foreach ($daten as $schluessel => $wert) {
                    if (is_string($wert)) {
                        $woerter += str_word_count(strip_tags($wert));
                    }
                    if ($schluessel === 'bild' && $wert !== '' && empty($daten['alt'])) {
                        $bilderOhneAlt++;
                    }
                }
            }
            $pruefungen[] = [
                'titel' => 'Eine Hauptüberschrift',
                'status' => $h1 === 1 ? 'gut' : ($h1 === 0 ? 'schlecht' : 'mittel'),
                'text' => $h1 === 1 ? 'Genau eine H1 – so soll es sein.'
                    : ($h1 === 0 ? 'Keine H1. Füge einen Titelbereich hinzu.' : $h1 . ' Titelbereiche; nur einer sollte H1 sein.'),
                'seite' => $name, 'link' => $link,
            ];
            if ($bilderOhneAlt > 0) {
                $pruefungen[] = [
                    'titel' => 'Bildbeschreibungen',
                    'status' => 'mittel',
                    'text' => $bilderOhneAlt . ' Bild(er) ohne Beschreibung. Sie helfen Suchmaschinen und blinden Besuchern.',
                    'seite' => $name, 'link' => $link,
                ];
            }
            $pruefungen[] = [
                'titel' => 'Textumfang',
                'status' => $woerter >= 300 ? 'gut' : ($woerter >= 120 ? 'mittel' : 'schlecht'),
                'text' => $woerter < 120
                    ? 'Nur etwa ' . $woerter . ' Wörter. Für eine gute Platzierung sind 300+ üblich.'
                    : 'Etwa ' . $woerter . ' Wörter.',
                'seite' => $name, 'link' => $link,
            ];
        }

        /* Übergreifendes */
        $ort = Tenant::one('locations', 'aktiv = 1');
        $pruefungen[] = [
            'titel' => 'Lokale Angaben',
            'status' => $ort && (string) $ort['ort'] !== '' ? 'gut' : 'schlecht',
            'text' => $ort && (string) $ort['ort'] !== ''
                ? 'Standort hinterlegt: ' . $ort['ort'] . '. Wichtig für „Golftrainer in deiner Nähe“.'
                : 'Kein Standort hinterlegt. Ohne Ort taucht die Seite bei lokalen Suchen nicht auf.',
            'seite' => 'Gesamt', 'link' => '/app/standorte.php',
        ];

        $impressum = Tenant::count('pages', "slug IN ('impressum','datenschutz')");
        $pruefungen[] = [
            'titel' => 'Pflichtseiten',
            'status' => $impressum >= 2 ? 'gut' : 'schlecht',
            'text' => $impressum >= 2
                ? 'Impressum und Datenschutzerklärung sind vorhanden.'
                : 'Impressum und Datenschutzerklärung fehlen – in Deutschland abmahnfähig.',
            'seite' => 'Gesamt', 'link' => '/app/website.php',
        ];

        $beitraege = Tenant::count('posts', "status = 'veroeffentlicht'");
        $pruefungen[] = [
            'titel' => 'Regelmäßige Inhalte',
            'status' => $beitraege >= 5 ? 'gut' : ($beitraege >= 1 ? 'mittel' : 'schlecht'),
            'text' => $beitraege === 0
                ? 'Noch keine Beiträge. Ein Blog bringt Besucher, die noch nicht nach dir suchen.'
                : $beitraege . ' veröffentlichte Beiträge.',
            'seite' => 'Gesamt', 'link' => '/app/inhalte.php',
        ];

        $domain = (string) (Tenant::workspace()['domain'] ?? '');
        $pruefungen[] = [
            'titel' => 'Eigene Domain',
            'status' => $domain !== '' ? 'gut' : 'mittel',
            'text' => $domain !== '' ? 'Läuft auf ' . $domain . '.'
                : 'Noch keine eigene Domain. Eine eigene Adresse wirkt seriöser und rankt besser.',
            'seite' => 'Gesamt', 'link' => '/app/einstellungen.php',
        ];

        $punkte = 0;
        foreach ($pruefungen as $p) {
            $punkte += $p['status'] === 'gut' ? 2 : ($p['status'] === 'mittel' ? 1 : 0);
        }
        $score = count($pruefungen) > 0 ? (int) round($punkte / (count($pruefungen) * 2) * 100) : 0;

        return ['score' => $score, 'pruefungen' => $pruefungen];
    }

    /** sitemap.xml für die veröffentlichte Website. */
    public static function sitemap(string $basis): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
             . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach (Pages::alle() as $seite) {
            if ((string) $seite['status'] !== 'veroeffentlicht') {
                continue;
            }
            $xml .= '  <url><loc>' . Util::h($basis . ((int) $seite['startseite'] === 1 ? '' : '/' . $seite['slug']))
                  . '</loc><lastmod>' . date('Y-m-d', strtotime((string) ($seite['geaendert'] ?: 'now'))) . '</lastmod>'
                  . '<priority>' . ((int) $seite['startseite'] === 1 ? '1.0' : '0.8') . '</priority></url>' . "\n";
        }
        foreach (Tenant::all('posts', "status = 'veroeffentlicht'", [], 'veroeffentlicht DESC') as $b) {
            $xml .= '  <url><loc>' . Util::h($basis . '/blog/' . $b['slug']) . '</loc><lastmod>'
                  . date('Y-m-d', strtotime((string) ($b['veroeffentlicht'] ?: 'now'))) . '</lastmod>'
                  . '<priority>0.6</priority></url>' . "\n";
        }
        return $xml . '</urlset>';
    }

    /** JSON-LD für die lokale Suche – das lohnt sich für einen Golf Pro. */
    public static function strukturierteDaten(): string
    {
        $ort = Tenant::one('locations', 'aktiv = 1');
        $daten = [
            '@context' => 'https://schema.org',
            '@type'    => 'SportsActivityLocation',
            'name'     => Tenant::name(),
            'description' => (string) Tenant::einstellung('website_beschreibung', ''),
            'url'      => App::absolut('/'),
            'sport'    => 'Golf',
        ];
        if ($ort) {
            $daten['address'] = [
                '@type' => 'PostalAddress',
                'streetAddress'   => (string) $ort['strasse'],
                'postalCode'      => (string) $ort['plz'],
                'addressLocality' => (string) $ort['ort'],
                'addressCountry'  => (string) $ort['land'],
            ];
        }
        $zeiten = [];
        $tage = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        foreach (Tenant::all('availability', 'aktiv = 1', [], 'wochentag, von_min') as $f) {
            $zeiten[] = [
                '@type' => 'OpeningHoursSpecification',
                'dayOfWeek' => 'https://schema.org/' . $tage[(int) $f['wochentag']],
                'opens'  => Util::minutenZuZeit((int) $f['von_min']),
                'closes' => Util::minutenZuZeit((int) $f['bis_min']),
            ];
        }
        if ($zeiten !== []) {
            $daten['openingHoursSpecification'] = $zeiten;
        }
        return '<script type="application/ld+json">' . Util::json($daten) . '</script>';
    }
}
