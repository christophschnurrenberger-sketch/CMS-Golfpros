<?php
/**
 * Reiseseite – wie eine Golfreise auf der Website aussieht.
 *
 * Trips weiß, was eine Reise ist und was sie kostet; hier steht, wie sie
 * sich zeigt: die Kachel im Katalog und im Baustein, der Zeitraum in der
 * Schreibweise eines Reisekatalogs, die Preisrechnung neben dem
 * Buchungsformular und die strukturierten Daten für Suchmaschinen.
 *
 * Die Kachel steht bewusst genau einmal hier. Katalog und Baustein auf
 * der Startseite zeigen dieselbe Reise – zwei Fassungen liefen beim
 * nächsten Feinschliff sofort auseinander.
 */
final class Reiseseite
{
    /** Ab so wenigen freien Plätzen steht „Nur noch …" auf dem Bild. */
    public const KNAPP = 3;

    /**
     * Der Zeitraum, wie ihn ein Reisekatalog schreibt: „12.–19. April 2027",
     * „28. März – 4. April 2027" oder über den Jahreswechsel mit beiden
     * Jahren.
     */
    public static function zeitraum(string $start, string $ende): string
    {
        $a = strtotime($start);
        $b = strtotime($ende);
        if ($a === false) {
            return '';
        }
        $tag = static fn (int $t, bool $jahr = true): string =>
            (int) date('j', $t) . '. ' . Util::monatName((int) date('n', $t)) . ($jahr ? ' ' . date('Y', $t) : '');

        if ($b === false || date('Y-m-d', $a) === date('Y-m-d', $b)) {
            return $tag($a);
        }
        if (date('Y-m', $a) === date('Y-m', $b)) {
            return (int) date('j', $a) . '.–' . $tag($b);
        }
        if (date('Y', $a) === date('Y', $b)) {
            return $tag($a, false) . ' – ' . $tag($b);
        }
        return $tag($a) . ' – ' . $tag($b);
    }

    /** Adresse eines Bildes aus dem Datensatz – leer, wenn keines da ist. */
    public static function bildUrl(string $pfad): string
    {
        $pfad = Trips::pfad($pfad);
        return $pfad === '' ? '' : App::url($pfad);
    }

    /**
     * Hotelsterne. Die Zeichen sind Schmuck; Vorleseprogramme bekommen die
     * Zahl als Wort, sonst hörten sie viermal „schwarzer Stern".
     */
    public static function sterne(int $anzahl): string
    {
        $anzahl = max(0, min(5, $anzahl));
        if ($anzahl === 0) {
            return '';
        }
        return '<span class="sterne" role="img" aria-label="' . $anzahl . ($anzahl === 1 ? ' Stern' : ' Sterne') . '">'
             . str_repeat('★', $anzahl) . '</span>';
    }

    public static function adresse(array $reise): string
    {
        return Oeffentlich::url('/reise.php', ['r' => (string) $reise['slug']]);
    }

    /**
     * Die Marken auf dem Bild: Frühbucher, knappe Plätze, ausgebucht.
     *
     * Höchstens zwei, und nur solche, die eine Entscheidung auslösen. Ein
     * Bild voller Aufkleber sieht nach Resterampe aus.
     *
     * @return list<array{0: string, 1: string}> Text und Art (akzent, hell, dunkel)
     */
    public static function marken(array $reise, int $frei): array
    {
        $marken = [];
        if (Trips::istFruehbucher($reise)) {
            $marken[] = ['Frühbucher bis ' . Util::datum((string) $reise['fruehbucher_bis'], false), 'akzent'];
        }
        if ($frei <= 0) {
            $marken[] = [(int) $reise['warteliste'] === 1 ? 'Ausgebucht · Warteliste' : 'Ausgebucht', 'dunkel'];
        } elseif ($frei <= self::KNAPP) {
            $marken[] = [$frei === 1 ? 'Nur noch 1 Platz' : 'Nur noch ' . $frei . ' Plätze', 'hell'];
        }
        return $marken;
    }

    public static function markenHtml(array $marken, string $klasse = 'reisemarken'): string
    {
        if ($marken === []) {
            return '';
        }
        $html = '<span class="' . Util::attr($klasse) . '">';
        foreach ($marken as [$text, $art]) {
            $html .= '<span class="reisemarke reisemarke--' . Util::attr($art) . '">' . Util::h($text) . '</span>';
        }
        return $html . '</span>';
    }

    /** Der Preis „ab" – mit dem alten Preis durchgestrichen, solange der Frühbucherrabatt gilt. */
    public static function abPreisHtml(array $reise): string
    {
        $ab    = Trips::abPreis($reise);
        $html  = Util::h(Util::geldKurz($ab, Tenant::waehrung()));
        if ($ab < (int) $reise['preis_cent']) {
            $html .= ' <s class="preis-alt"><span class="nur-vorlesen">statt </span>'
                   . Util::h(Util::geldKurz((int) $reise['preis_cent'], Tenant::waehrung())) . '</s>';
        }
        return $html;
    }

    /**
     * Eine Reise als Kachel – im Katalog und im Baustein „Golfreisen".
     *
     * Bild zuerst: Eine Reise verkauft sich über das Ziel. Darunter, was
     * man zum Vergleichen braucht – Zeitraum, Nächte, Anreise, der Preis
     * ab – und ganz unten, ob noch Platz ist.
     */
    public static function kachel(array $reise): string
    {
        $frei = Trips::freiePlaetze($reise);
        $ort  = trim((string) $reise['ziel'] . ' · ' . (string) $reise['land'], ' ·');
        $bild = self::bildUrl((string) $reise['bild']);

        $fakten = [(int) $reise['naechte'] . ((int) $reise['naechte'] === 1 ? ' Nacht' : ' Nächte')];
        $plaetze = count(Trips::golfplaetze((string) ($reise['golfplaetze'] ?? '')));
        if ($plaetze > 0) {
            $fakten[] = $plaetze === 1 ? '1 Golfplatz' : $plaetze . ' Golfplätze';
        }
        if ((string) $reise['anreise'] !== 'eigen') {
            $fakten[] = Trips::ANREISE[(string) $reise['anreise']] ?? '';
        }

        return '<a class="reisekachel" href="' . Util::attr(self::adresse($reise)) . '">'
             . '<span class="reisekachel__bild">'
             . ($bild !== ''
                ? '<img src="' . Util::attr($bild) . '" alt="" loading="lazy">'
                : '<span class="reisekachel__ohne platzhalter"><span class="platzhalter__text">'
                  . Util::h($ort !== '' ? $ort : (string) $reise['titel']) . '</span></span>')
             . self::markenHtml(self::marken($reise, $frei))
             . '</span>'
             . '<span class="reisekachel__koerper">'
             . ($ort !== '' ? '<span class="reisekachel__ort">' . Util::h($ort) . '</span>' : '')
             . '<span class="reisekachel__titel">' . Util::h((string) $reise['titel']) . '</span>'
             . '<span class="reisekachel__zeit">'
             . Util::h(self::zeitraum((string) $reise['start'], (string) $reise['ende'])) . '</span>'
             . '<span class="reisekachel__fakten">' . Util::h(implode(' · ', array_filter($fakten)))
             . ((int) ($reise['hotel_sterne'] ?? 0) > 0 ? ' · ' . self::sterne((int) $reise['hotel_sterne']) : '')
             . '</span>'
             . ((string) $reise['kurztext'] !== ''
                ? '<span class="reisekachel__text">' . Util::h(Util::kuerzen((string) $reise['kurztext'], 150)) . '</span>' : '')
             . '<span class="reisekachel__fuss">'
             . '<span class="reisekachel__preis"><span class="reisekachel__ab">ab</span> '
             . self::abPreisHtml($reise) . ' <span class="reisekachel__pp">p. P.</span></span>'
             . '<span class="reisekachel__frei">'
             . ($frei > 0 ? 'noch ' . ($frei === 1 ? '1 Platz' : $frei . ' Plätze') : 'ausgebucht') . '</span>'
             . '</span></span></a>';
    }

    /**
     * Die Preisrechnung neben dem Formular und auf der Bestätigung.
     *
     * Jede Zeile mit Menge und Einzelpreis, darunter der Zahlungsplan:
     * Wer eine Reise für zwei bucht, will vor dem Absenden wissen, was
     * wann fällig wird – nicht erst in der E-Mail.
     *
     * @param array<string,mixed> $rechnung Ergebnis von Trips::berechnen()
     */
    public static function rechnung(array $reise, array $rechnung, ?int $frei = null): string
    {
        $w = Tenant::waehrung();
        $zeilen = '';
        foreach ($rechnung['posten'] as [$text, $menge, $einzel, $summe]) {
            $zeilen .= '<tr' . ($summe < 0 ? ' class="ist-rabatt"' : '') . '><th scope="row">'
                     . ($menge > 1 ? '<span class="reiserechnung__menge">' . (int) $menge . ' × </span>' : '')
                     . Util::h($text)
                     . ($menge > 1 ? '<span class="reiserechnung__einzel">je ' . Util::h(Util::geldKurz(abs($einzel), $w)) . '</span>' : '')
                     . '</th><td>' . Util::h(Util::geldKurz($summe, $w)) . '</td></tr>';
        }

        $plan = '';
        if ((int) $rechnung['anzahlung'] > 0) {
            $plan = '<div><dt>Anzahlung nach der Bestätigung</dt><dd>'
                  . Util::h(Util::geldKurz((int) $rechnung['anzahlung'], $w)) . '</dd></div>';
            if ((int) $rechnung['rest'] > 0) {
                $plan .= '<div><dt>Restzahlung bis ' . Util::h(Util::datum((string) $rechnung['rest_faellig'])) . '</dt><dd>'
                       . Util::h(Util::geldKurz((int) $rechnung['rest'], $w)) . '</dd></div>';
            }
        } else {
            $plan = '<div><dt>Zahlbar bis ' . Util::h(Util::datum((string) $rechnung['rest_faellig'])) . '</dt><dd>'
                  . Util::h(Util::geldKurz((int) $rechnung['summe'], $w)) . '</dd></div>';
        }

        $hinweis = '';
        if ($frei !== null && (int) $rechnung['personen'] > $frei) {
            $n = (int) $rechnung['personen'];
            $hinweis = $frei > 0
                ? 'Für ' . $n . ' Personen reichen die freien Plätze nicht mehr (noch ' . $frei . ').'
                : 'Die Reise ist ausgebucht.';
            $hinweis .= (int) $reise['warteliste'] === 1
                ? ' Die Buchung kommt auf die Warteliste und rückt nach, sobald genug Plätze frei sind.'
                : '';
            $hinweis = '<p class="reiserechnung__hinweis">' . Util::h($hinweis) . '</p>';
        }

        return '<table class="reiserechnung__tabelle"><caption class="nur-vorlesen">Preisrechnung</caption><tbody>'
             . $zeilen . '</tbody></table>'
             . '<div class="reiserechnung__summe"><span>Gesamt</span><strong>'
             . Util::h(Util::geldKurz((int) $rechnung['summe'], $w)) . '</strong></div>'
             . '<dl class="reiserechnung__plan">' . $plan . '</dl>'
             . $hinweis;
    }

    /**
     * Die Reise als TouristTrip für Suchmaschinen.
     *
     * json_encode mit JSON_HEX_TAG: Ein Titel mit „</script>" darin
     * beendete sonst den Block und stünde als Markup in der Seite.
     */
    public static function strukturierteDaten(array $reise, int $frei): string
    {
        $bilder = [];
        foreach (array_merge([(string) $reise['bild']], Trips::galerie($reise)) as $b) {
            if (Trips::pfad($b) !== '') {
                $bilder[] = App::absolut(Trips::pfad($b));
            }
        }
        $adresse = Oeffentlich::urlAbsolut('/reise.php', ['r' => (string) $reise['slug']]);

        $programm = [];
        foreach (Trips::programm((string) $reise['programm']) as $i => $tag) {
            $programm[] = ['@type' => 'ListItem', 'position' => $i + 1,
                           'name' => 'Tag ' . ($i + 1) . ($tag['titel'] !== '' ? ': ' . $tag['titel'] : ''),
                           'description' => $tag['text']];
        }

        $daten = [
            '@context'      => 'https://schema.org',
            '@type'         => 'TouristTrip',
            'name'          => (string) $reise['titel'],
            'description'   => (string) $reise['kurztext'] !== ''
                ? (string) $reise['kurztext'] : Util::kuerzen((string) $reise['beschreibung'], 300),
            'url'           => $adresse,
            'touristType'   => 'Golfer',
            'departureTime' => date('Y-m-d', (int) strtotime((string) $reise['start'])),
            'arrivalTime'   => date('Y-m-d', (int) strtotime((string) $reise['ende'])),
            'provider'      => ['@type' => 'Organization', 'name' => Tenant::name()],
            'offers'        => [
                '@type'         => 'Offer',
                'price'         => number_format(Trips::abPreis($reise) / 100, 2, '.', ''),
                'priceCurrency' => (string) (Tenant::workspace()['waehrung'] ?? 'EUR'),
                'availability'  => 'https://schema.org/' . ($frei > 0 ? ($frei <= self::KNAPP ? 'LimitedAvailability' : 'InStock') : 'SoldOut'),
                'validThrough'  => date('Y-m-d', (int) strtotime((string) $reise['start'])),
                'url'           => $adresse,
            ],
        ];
        if ($bilder !== []) {
            $daten['image'] = $bilder;
        }
        if ($programm !== []) {
            $daten['itinerary'] = ['@type' => 'ItemList', 'numberOfItems' => count($programm),
                                   'itemListElement' => $programm];
        }
        return '<script type="application/ld+json">'
             . json_encode($daten, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP)
             . '</script>';
    }
}
