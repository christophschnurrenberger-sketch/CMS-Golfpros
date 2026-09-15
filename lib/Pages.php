<?php
/**
 * Pages – Seiten und Landingpages.
 *
 * Eine Seite ist eine Liste von Bausteinen plus SEO-Angaben. Es gibt genau
 * eine Startseite je Workspace; sie lässt sich umziehen, aber nicht löschen –
 * eine Website ohne Startseite ist kaputt, und das soll man nicht aus
 * Versehen erreichen können.
 */
final class Pages
{
    public static function alle(string $art = ''): array
    {
        $wo = $art !== '' ? 'art = :a' : '';
        return Tenant::all('pages', $wo, $art !== '' ? ['a' => $art] : [], 'startseite DESC, position, titel');
    }

    public static function startseite(): ?array
    {
        return Tenant::one('pages', 'startseite = 1');
    }

    public static function nachSlug(string $slug): ?array
    {
        return Tenant::one('pages', 'slug = :s', ['s' => $slug]);
    }

    public static function menue(): array
    {
        return Tenant::all('pages', "status = 'veroeffentlicht' AND im_menue = 1 AND startseite = 0",
            [], 'position, titel');
    }

    public static function bloecke(array $seite): array
    {
        return Util::ausJson((string) ($seite['bloecke'] ?? ''), []);
    }

    public static function speichern(array $daten, int $id = 0): int
    {
        $satz = array_intersect_key($daten, array_flip([
            'titel', 'bloecke', 'seo', 'im_menue', 'position', 'status', 'art',
        ]));
        $satz['geaendert'] = Util::jetzt();

        if (isset($daten['slug']) && (string) $daten['slug'] !== '') {
            $satz['slug'] = self::freierSlug(Util::slug((string) $daten['slug']), $id);
        } elseif ($id === 0 && isset($daten['titel'])) {
            $satz['slug'] = self::freierSlug(Util::slug((string) $daten['titel']), 0);
        }

        if ($id > 0) {
            Tenant::update('pages', $id, $satz);
            Audit::schreiben('geaendert', 'page', $id, (string) ($satz['titel'] ?? ''));
            return $id;
        }
        $neu = Tenant::insert('pages', $satz);
        Audit::schreiben('erstellt', 'page', $neu, (string) ($satz['titel'] ?? ''));
        return $neu;
    }

    public static function freierSlug(string $slug, int $ausser = 0): string
    {
        $basis = $slug !== '' ? $slug : 'seite';
        $versuch = $basis;
        $n = 2;
        while (Tenant::count('pages', 'slug = :s AND id != :id', ['s' => $versuch, 'id' => $ausser]) > 0) {
            $versuch = $basis . '-' . $n++;
        }
        return $versuch;
    }

    public static function startseiteSetzen(int $id): void
    {
        Tenant::updateWhere('pages', ['startseite' => 0], '1=1');
        Tenant::update('pages', $id, ['startseite' => 1, 'status' => 'veroeffentlicht']);
    }

    public static function loeschen(int $id): bool
    {
        $seite = Tenant::find('pages', $id);
        if (!$seite || (int) $seite['startseite'] === 1) {
            return false;
        }
        Tenant::delete('pages', $id);
        Audit::schreiben('geloescht', 'page', $id, (string) $seite['titel']);
        return true;
    }

    public static function seo(array $seite): array
    {
        return Util::ausJson((string) ($seite['seo'] ?? ''), []) + [
            'titel' => (string) $seite['titel'],
            'beschreibung' => '',
            'bild' => '',
            'index' => true,
        ];
    }

    public static function url(array $seite): string
    {
        $slug = (string) (Tenant::workspace()['slug'] ?? '');
        if ((int) $seite['startseite'] === 1) {
            return App::url('/site.php?w=' . rawurlencode($slug));
        }
        return App::url('/site.php?w=' . rawurlencode($slug) . '&s=' . rawurlencode((string) $seite['slug']));
    }

    /** Conversion-Rate einer Landingpage: Anfragen je Aufruf. */
    public static function conversion(array $seite): ?float
    {
        $aufrufe = (int) $seite['aufrufe'];
        if ($aufrufe < 20) {
            return null;   // unter 20 Aufrufen ist jede Quote Zufall
        }
        return (int) $seite['conversions'] / $aufrufe * 100;
    }

    /**
     * Die Seiten, die ein neuer Workspace bekommt. Eine leere Website wäre
     * der schlechteste erste Eindruck – hier steht eine vollständige, die
     * der Pro nur noch anpassen muss.
     */
    public static function vorlage(string $name, string $schwerpunkt = 'allgemein'): array
    {
        $ort = Tenant::one('locations', 'aktiv = 1');
        $stadt = $ort ? (string) $ort['ort'] : '';

        /*
         * Der Aufmacher.
         *
         * Die Sternchen im Titel setzen den zweiten Satz auf den gelben
         * Textmarker. Die Notiz darunter ist der Satz, den man am Telefon
         * sagen würde – sie steht in Handschrift und nimmt genau die
         * Sorge vorweg, die Anfänger vom Anrufen abhält.
         */
        $hero = Bloecke::neu('hero');
        $hero['daten']['obertitel'] = $name;
        $hero['daten']['titel'] = match ($schwerpunkt) {
            'anfaenger' => 'Ihr Weg zur Platzreife. *Ohne Umwege.*',
            'leistung'  => 'Vom soliden Spieler *zum Turnierspieler.*',
            'junior'    => 'Golf, das Kindern *Spaß macht.*',
            default     => 'Eine Golfschule, die Sie *beim Namen kennt.*',
        };
        $hero['daten']['text'] = match ($schwerpunkt) {
            'anfaenger' => 'In wenigen Wochen von den ersten Schlägen bis auf den Platz – in kleinen Gruppen, mit klarem Ablauf und ohne Druck.',
            'leistung'  => 'Videoanalyse, Leistungsdaten und ein Trainingsplan, der auf Ihr Handicap-Ziel ausgerichtet ist. Eine Baustelle pro Quartal, nicht fünf.',
            'junior'    => 'Spielerisches Training für Kinder und Jugendliche, in Gruppen mit gleichaltrigen Spielerinnen und Spielern.',
            default     => 'Platzreife, Einzelstunden und ein Saisonprogramm für Spieler, die ihr Handicap ernst nehmen. Kleine Gruppen. Keine Kurspakete, die niemand versteht.',
        };
        $hero['daten']['notiz'] = match ($schwerpunkt) {
            'junior'    => 'Schläger in der richtigen Länge sind da — mitbringen muss man nichts.',
            'leistung'  => 'Wer schon spielt, fängt am besten mit einer Standortbestimmung an.',
            default     => 'Erste Stunde? Schläger und Bälle stelle ich — Sportschuhe genügen.',
        };
        $hero['daten']['fakten'] = 'PGA Professional · Videoanalyse · Kleine Gruppen'
            . ($stadt !== '' ? ' · ' . $stadt : '');
        $hero['daten']['knopf_text'] = 'Freie Termine ansehen';
        $hero['daten']['knopf2_text'] = 'Preise, alle';

        /* Was gerade ansteht – drei kurze Karten mit einem Datum. */
        $aktuell = Bloecke::neu('spalten');
        $aktuell['daten']['titel'] = 'Was hier gerade los ist';
        $aktuell['daten']['text'] = 'Kurzfristige Plätze, Termine und was sonst auf dem Zettel steht.';
        $aktuell['daten']['eintraege'] = [
            ['titel' => 'Platzreifekurs im Frühjahr',
             'text' => 'Sechs Termine, kleine Gruppe, Prüfung inklusive. Der Kurs startet, sobald sechs Plätze belegt sind.'],
            ['titel' => 'Kurzfristige Plätze',
             'text' => 'Diese Woche sind noch Einzelstunden frei. Wer flexibel ist, findet fast immer einen Termin.'],
            ['titel' => 'Wintertraining indoor',
             'text' => 'Von November bis März mit Abschlagnetz und Videoanalyse – an der Technik lässt sich auch ohne Platz arbeiten.'],
        ];

        $zahlen = Bloecke::neu('zahlen');
        $zahlen['daten']['eintraege'] = [
            ['wert' => '12', 'label' => 'Jahre Unterricht'],
            ['wert' => '340', 'label' => 'begleitete Platzreifen'],
            ['wert' => '4,9', 'label' => 'Bewertung'],
        ];

        $trainer = Bloecke::neu('team');
        $trainer['daten']['titel'] = '';

        $leistungen = Bloecke::neu('leistungen');
        $leistungen['daten']['obertitel'] = 'Kursangebot & Preise';
        $leistungen['daten']['titel'] = 'Alle Wege, besser zu werden.';
        $leistungen['daten']['text'] = 'Sie brauchen genau einen davon. Welchen, klären wir in zehn Minuten am Telefon.';

        $stimmen = Bloecke::neu('testimonials');
        $stimmen['daten']['titel'] = 'Was Spieler sagen';
        $stimmen['daten']['eintraege'] = [
            ['text' => 'In zwei Saisons von 28 auf 16 — vor allem, weil wir irgendwann aufgehört haben, am Schwung zu basteln.',
             'name' => 'Andreas B.', 'zusatz' => 'Hcp 16,4', 'sterne' => 5],
            ['text' => 'Er erklärt nie mehr, als man in dem Moment gebrauchen kann. Das ist seltener, als man denkt.',
             'name' => 'Dr. Miriam K.', 'zusatz' => 'Platzreife 2025', 'sterne' => 5],
            ['text' => 'Vier Playing Lessons haben mehr gebracht als drei Jahre allein auf der Range.',
             'name' => 'Stefan L.', 'zusatz' => 'Hcp 9,1', 'sterne' => 5],
        ];

        $faq = Bloecke::neu('faq');
        $faq['daten']['titel'] = 'Was am häufigsten gefragt wird';
        $faq['daten']['eintraege'] = [
            ['frage' => 'Brauche ich eigene Schläger?',
             'antwort' => 'Für die ersten Stunden nicht. Leihschläger stehen bereit – und wir schauen gemeinsam, was zu Ihnen passt, bevor Sie etwas kaufen.'],
            ['frage' => 'Wie lange dauert es bis zur Platzreife?',
             'antwort' => 'Die meisten brauchen sechs bis zehn Wochen bei einer Einheit pro Woche. Es kommt darauf an, wie oft Sie zusätzlich üben.'],
            ['frage' => 'Kann ich einen Termin verschieben?',
             'antwort' => 'Ja, bis 24 Stunden vorher kostenfrei – am einfachsten über Ihren Zugang oder telefonisch.'],
            ['frage' => 'Findet das Training auch bei Regen statt?',
             'antwort' => 'Bei leichtem Regen ja. Bei Gewitter, Sturm oder gesperrtem Platz holen wir den Termin ohne Kosten nach.'],
        ];

        $buchung = Bloecke::neu('buchung');
        $buchung['daten']['obertitel'] = 'Buchung';
        $buchung['daten']['titel'] = 'Drei Angaben, dann haben Sie den Termin.';
        $buchung['daten']['text'] = 'Keine Registrierung, keine Kreditkarte. Bezahlt wird vor Ort oder per Rechnung.';

        $cta = Bloecke::neu('cta');
        $cta['daten']['notiz'] = 'Anrufen geht meistens schneller.';

        $kontakt = Bloecke::neu('kontakt');
        $kontakt['daten']['obertitel'] = 'Kontakt';
        $kontakt['daten']['titel'] = 'So finden Sie her.';
        $kontakt['daten']['karte'] = true;

        return [$hero, $aktuell, $trainer, $zahlen, $leistungen, $stimmen, $buchung, $faq, $cta, $kontakt];
    }
}
