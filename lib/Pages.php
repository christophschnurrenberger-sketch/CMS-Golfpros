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
        $hero = Bloecke::neu('hero');
        $hero['daten']['titel'] = match ($schwerpunkt) {
            'anfaenger' => 'Dein Weg zur Platzreife – ohne Umwege',
            'leistung'  => 'Vom soliden Spieler zum Turnierspieler',
            'junior'    => 'Golf, das Kindern Spaß macht',
            default     => 'Besser Golf spielen. Mit einem Plan.',
        };
        $hero['daten']['obertitel'] = $name;
        $hero['daten']['text'] = match ($schwerpunkt) {
            'anfaenger' => 'In wenigen Wochen von den ersten Schlägen bis auf den Platz – in kleinen Gruppen, mit klarem Ablauf und ohne Druck.',
            'leistung'  => 'Videoanalyse, Leistungsdaten und ein Trainingsplan, der auf dein Handicap-Ziel ausgerichtet ist.',
            'junior'    => 'Spielerisches Training für Kinder und Jugendliche, in Gruppen mit gleichaltrigen Spielerinnen und Spielern.',
            default     => 'Individuelles Training für Einsteiger und Fortgeschrittene – mit klaren Zielen, Videoanalyse und einem Plan, der zu deinem Alltag passt.',
        };

        $spalten = Bloecke::neu('spalten');
        $zahlen  = Bloecke::neu('zahlen');
        $leistungen = Bloecke::neu('leistungen');
        $stimmen = Bloecke::neu('testimonials');
        $stimmen['daten']['eintraege'] = [
            ['text' => 'Nach acht Wochen hatte ich meine Platzreife – und zum ersten Mal das Gefühl, zu wissen, was ich da tue.',
             'name' => 'Sandra K.', 'zusatz' => 'seit 2024 dabei', 'sterne' => 5],
            ['text' => 'Die Videoanalyse war der Wendepunkt. Ich sehe jetzt selbst, woran ich arbeiten muss.',
             'name' => 'Michael B.', 'zusatz' => 'HCP 18,4', 'sterne' => 5],
            ['text' => 'Klare Ansagen, kein Fachchinesisch, und nach jeder Stunde weiß ich, was ich üben soll.',
             'name' => 'Tobias R.', 'zusatz' => 'HCP 26,1', 'sterne' => 5],
        ];
        $faq = Bloecke::neu('faq');
        $faq['daten']['eintraege'] = [
            ['frage' => 'Brauche ich eigene Schläger?',
             'antwort' => 'Für die ersten Stunden nicht. Leihschläger stehen bereit – und wir schauen gemeinsam, was zu dir passt, bevor du etwas kaufst.'],
            ['frage' => 'Wie lange dauert es bis zur Platzreife?',
             'antwort' => 'Die meisten brauchen sechs bis zehn Wochen bei einer Einheit pro Woche. Es kommt darauf an, wie oft du zusätzlich übst.'],
            ['frage' => 'Kann ich einen Termin verschieben?',
             'antwort' => 'Ja, bis 24 Stunden vorher kostenfrei – am einfachsten über deinen Zugang oder telefonisch.'],
            ['frage' => 'Gibt es Training für Kinder?',
             'antwort' => 'Ja, in altersgerechten Gruppen. Sprich mich gern an, dann finden wir die passende Gruppe.'],
        ];
        $cta = Bloecke::neu('cta');
        $buchung = Bloecke::neu('buchung');
        $kontakt = Bloecke::neu('kontakt');

        return [$hero, $zahlen, $spalten, $leistungen, $stimmen, $buchung, $faq, $cta, $kontakt];
    }
}
