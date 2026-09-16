<?php
/**
 * Bloecke – die Bausteine des Website-Baukastens.
 *
 * Ein Baustein ist zweierlei an einer Stelle: seine **Felder** (was der Pro
 * ausfüllt) und sein **Aufbau** (was daraus wird). Beides zusammen zu halten
 * ist der Grund, warum ein neuer Baustein hier eine Ergänzung ist und kein
 * Eingriff an fünf Stellen.
 *
 * Die Seite wird als JSON gespeichert, nicht als HTML. Damit bleibt jeder
 * Baustein nachträglich bearbeitbar, das Design lässt sich global umstellen,
 * und derselbe Inhalt kann im Newsletter anders aussehen als auf der Seite.
 */
final class Bloecke
{
    /**
     * felder: schluessel => [Beschriftung, Typ, Zusatz]
     * Typen: text, mehrzeilig, html, bild, url, zahl, farbe, auswahl, schalter, liste
     */
    private const TYPEN = [

        /* ------------------------------------------------------ Aufbau */

        'hero' => [
            'name' => 'Titelbereich', 'icon' => 'layers', 'gruppe' => 'Aufbau',
            'felder' => [
                'obertitel'   => ['Kleine Zeile darüber', 'text'],
                'titel'       => ['Überschrift', 'text'],
                'text'        => ['Text', 'mehrzeilig'],
                'knopf_text'  => ['Knopf', 'text'],
                'knopf_url'   => ['Knopf-Ziel', 'url'],
                'knopf2_text' => ['Zweiter Knopf', 'text'],
                'knopf2_url'  => ['Ziel des zweiten Knopfs', 'url'],
                'bild'        => ['Bild', 'bild'],
                'notiz'       => ['Handschriftliche Notiz', 'text'],
                'fakten'      => ['Faktenzeile ganz unten', 'text'],
                'ausrichtung' => ['Ausrichtung', 'auswahl', ['mitte' => 'Mittig', 'geteilt' => 'Bild daneben']],
                'hoehe'       => ['Höhe', 'auswahl', ['normal' => 'Normal', 'gross' => 'Bildschirmfüllend', 'klein' => 'Kompakt']],
            ],
            /*
             * Sternchen um ein Wort setzen es auf den gelben Textmarker:
             * „Besser Golf spielen. *Mit einem Plan.*" Ein Sternchenpaar
             * ist schneller erklärt als ein Farbwähler und überlebt jedes
             * Kopieren aus einem anderen Programm.
             */
            'standard' => [
                'obertitel' => 'PGA Golf Professional',
                'titel' => 'Eine Golfschule, die Sie beim *Namen kennt.*',
                'text' => 'Platzreife, Einzelstunden und ein Saisonprogramm für Spieler, die ihr Handicap ernst nehmen. Kleine Gruppen. Keine Kurspakete, die niemand versteht.',
                'knopf_text' => 'Freie Termine ansehen', 'knopf_url' => '#buchung',
                'knopf2_text' => 'Preise, alle', 'knopf2_url' => '#leistungen',
                'notiz' => 'Erste Stunde? Schläger und Bälle stelle ich — Sportschuhe genügen.',
                'fakten' => 'PGA Professional · Videoanalyse · Di bis Sa',
                'ausrichtung' => 'geteilt', 'hoehe' => 'normal',
            ],
        ],

        'ueberschrift' => [
            'name' => 'Überschrift', 'icon' => 'type', 'gruppe' => 'Aufbau',
            'felder' => [
                'obertitel'   => ['Kleine Zeile darüber', 'text'],
                'titel'       => ['Überschrift', 'text'],
                'text'        => ['Text darunter', 'mehrzeilig'],
                'ausrichtung' => ['Ausrichtung', 'auswahl', ['links' => 'Links', 'mitte' => 'Mittig']],
            ],
            'standard' => ['titel' => 'Ein Abschnitt', 'ausrichtung' => 'mitte'],
        ],

        'text' => [
            'name' => 'Textabschnitt', 'icon' => 'content', 'gruppe' => 'Aufbau',
            'felder' => [
                'titel'   => ['Überschrift', 'text'],
                'text'    => ['Text', 'mehrzeilig'],
                'spalten' => ['Spalten', 'auswahl', ['1' => 'Eine Spalte', '2' => 'Zwei Spalten']],
            ],
            'standard' => ['text' => 'Hier steht dein Text.', 'spalten' => '1'],
        ],

        'spalten' => [
            'name' => 'Spalten', 'icon' => 'grid', 'gruppe' => 'Aufbau',
            'felder' => [
                'obertitel'=> ['Kleine Zeile darüber', 'text'],
                'titel'    => ['Überschrift', 'text'],
                'text'     => ['Text unter der Überschrift', 'mehrzeilig'],
                'eintraege'=> ['Spalten', 'liste', [
                    'icon'  => ['Symbol', 'auswahl', 'icons'],
                    'titel' => ['Titel', 'text'],
                    'text'  => ['Text', 'mehrzeilig'],
                ]],
            ],
            'standard' => ['titel' => 'Was dich erwartet', 'eintraege' => [
                ['icon' => 'target', 'titel' => 'Klares Ziel', 'text' => 'Wir legen gemeinsam fest, woran du arbeitest – messbar statt gefühlt.'],
                ['icon' => 'video', 'titel' => 'Videoanalyse', 'text' => 'Dein Schwung in Zeitlupe, mit Linien und Anmerkungen zum Nachschauen.'],
                ['icon' => 'training', 'titel' => 'Trainingsplan', 'text' => 'Übungen für zu Hause und die Range, abgestimmt auf deine Zeit.'],
            ]],
        ],

        'trenner' => [
            'name' => 'Trennlinie', 'icon' => 'minus', 'gruppe' => 'Aufbau',
            'felder' => ['sichtbar' => ['Linie zeigen', 'schalter']],
            'standard' => ['sichtbar' => true],
        ],

        /* ------------------------------------------------------ Inhalt */

        'bild' => [
            'name' => 'Bild', 'icon' => 'image', 'gruppe' => 'Inhalt',
            'felder' => [
                'bild'       => ['Bild', 'bild'],
                'alt'        => ['Bildbeschreibung', 'text'],
                'bildtext'   => ['Bildunterschrift', 'text'],
                'breite'     => ['Breite', 'auswahl', ['inhalt' => 'Inhaltsbreite', 'voll' => 'Volle Breite']],
            ],
            'standard' => ['breite' => 'inhalt'],
        ],

        'galerie' => [
            'name' => 'Galerie', 'icon' => 'grid', 'gruppe' => 'Inhalt',
            'felder' => [
                'titel'  => ['Überschrift', 'text'],
                'bilder' => ['Bilder', 'liste', [
                    'bild' => ['Bild', 'bild'],
                    'alt'  => ['Beschreibung', 'text'],
                ]],
            ],
            'standard' => ['titel' => 'Eindrücke', 'bilder' => []],
        ],

        'video' => [
            'name' => 'Video', 'icon' => 'video', 'gruppe' => 'Inhalt',
            'felder' => [
                'titel' => ['Überschrift', 'text'],
                'url'   => ['Video-Adresse (YouTube oder Vimeo)', 'url'],
                'text'  => ['Text darunter', 'mehrzeilig'],
            ],
            'standard' => [],
        ],

        'karten' => [
            'name' => 'Karten', 'icon' => 'layers', 'gruppe' => 'Inhalt',
            'felder' => [
                'titel'    => ['Überschrift', 'text'],
                'eintraege'=> ['Karten', 'liste', [
                    'bild'  => ['Bild', 'bild'],
                    'titel' => ['Titel', 'text'],
                    'text'  => ['Text', 'mehrzeilig'],
                    'url'   => ['Ziel', 'url'],
                ]],
            ],
            'standard' => ['eintraege' => []],
        ],

        'zahlen' => [
            'name' => 'Zahlen', 'icon' => 'analytics', 'gruppe' => 'Inhalt',
            'felder' => [
                'eintraege' => ['Zahlen', 'liste', [
                    'wert'  => ['Zahl', 'text'],
                    'label' => ['Beschriftung', 'text'],
                ]],
            ],
            'standard' => ['eintraege' => [
                ['wert' => '18', 'label' => 'Jahre Erfahrung'],
                ['wert' => '700+', 'label' => 'Betreute Golfer'],
                ['wert' => '4,9', 'label' => 'Bewertung'],
            ]],
        ],

        'zitat' => [
            'name' => 'Zitat', 'icon' => 'message', 'gruppe' => 'Inhalt',
            'felder' => [
                'text'  => ['Zitat', 'mehrzeilig'],
                'autor' => ['Von', 'text'],
            ],
            'standard' => [],
        ],

        /* ------------------------------------------------ Überzeugung */

        'leistungen' => [
            'name' => 'Leistungen', 'icon' => 'list', 'gruppe' => 'Überzeugung',
            'felder' => [
                'obertitel'=> ['Kleine Zeile darüber', 'text'],
                'titel'    => ['Überschrift', 'text'],
                'text'     => ['Text', 'mehrzeilig'],
                'automatisch' => ['Leistungen aus dem System übernehmen', 'schalter'],
                'eintraege'=> ['Leistungen von Hand', 'liste', [
                    'titel' => ['Titel', 'text'],
                    'text'  => ['Beschreibung', 'mehrzeilig'],
                    'preis' => ['Preis', 'text'],
                    'dauer' => ['Umfang', 'text'],
                    'wer'   => ['Für wen', 'text'],
                    'note'  => ['Handschriftliche Notiz', 'text'],
                ]],
            ],
            'standard' => ['titel' => 'Kursangebot & Preise', 'automatisch' => true, 'eintraege' => []],
        ],

        'preise' => [
            'name' => 'Preistabelle', 'icon' => 'euro', 'gruppe' => 'Überzeugung',
            'felder' => [
                'titel'    => ['Überschrift', 'text'],
                'eintraege'=> ['Pakete', 'liste', [
                    'titel'      => ['Name', 'text'],
                    'preis'      => ['Preis', 'text'],
                    'zusatz'     => ['Zusatz', 'text'],
                    'merkmale'   => ['Enthalten (eine Zeile je Punkt)', 'mehrzeilig'],
                    'knopf_text' => ['Knopf', 'text'],
                    'knopf_url'  => ['Ziel', 'url'],
                    'hervorheben'=> ['Hervorheben', 'schalter'],
                ]],
            ],
            'standard' => ['titel' => 'Pakete', 'eintraege' => []],
        ],

        'testimonials' => [
            'name' => 'Stimmen', 'icon' => 'star', 'gruppe' => 'Überzeugung',
            'felder' => [
                'titel'    => ['Überschrift', 'text'],
                'eintraege'=> ['Stimmen', 'liste', [
                    'text'   => ['Zitat', 'mehrzeilig'],
                    'name'   => ['Name', 'text'],
                    'zusatz' => ['Zusatz (z. B. HCP)', 'text'],
                    'sterne' => ['Sterne', 'zahl'],
                ]],
            ],
            'standard' => ['titel' => 'Was meine Kunden sagen', 'eintraege' => []],
        ],

        'faq' => [
            'name' => 'Häufige Fragen', 'icon' => 'help', 'gruppe' => 'Überzeugung',
            'felder' => [
                'titel'    => ['Überschrift', 'text'],
                'eintraege'=> ['Fragen', 'liste', [
                    'frage'   => ['Frage', 'text'],
                    'antwort' => ['Antwort', 'mehrzeilig'],
                ]],
            ],
            'standard' => ['titel' => 'Häufige Fragen', 'eintraege' => []],
        ],

        'team' => [
            'name' => 'Team', 'icon' => 'customers', 'gruppe' => 'Überzeugung',
            'felder' => [
                'titel'       => ['Überschrift', 'text'],
                'automatisch' => ['Trainer aus dem System übernehmen', 'schalter'],
            ],
            'standard' => ['titel' => 'Dein Trainerteam', 'automatisch' => true],
        ],

        'logos' => [
            'name' => 'Logoleiste', 'icon' => 'building', 'gruppe' => 'Überzeugung',
            'felder' => [
                'titel'  => ['Kleine Zeile', 'text'],
                'bilder' => ['Logos', 'liste', ['bild' => ['Bild', 'bild'], 'alt' => ['Name', 'text']]],
            ],
            'standard' => [],
        ],

        /* ---------------------------------------------------- Handlung */

        'cta' => [
            'name' => 'Handlungsaufruf', 'icon' => 'zap', 'gruppe' => 'Handlung',
            'felder' => [
                'titel'      => ['Überschrift', 'text'],
                'text'       => ['Text', 'mehrzeilig'],
                'knopf_text' => ['Knopf', 'text'],
                'knopf_url'  => ['Ziel', 'url'],
                'notiz'      => ['Handschriftliche Notiz', 'text'],
                'stil'       => ['Stil', 'auswahl', ['marke' => 'Ruhiges Band', 'still' => 'Sehr hell']],
            ],
            'standard' => [
                'titel' => 'Sagen Sie kurz, was Sie vorhaben.',
                'text' => 'Drei Sätze genügen. Sie bekommen einen konkreten Vorschlag zurück, in der Regel noch am selben Abend.',
                'knopf_text' => 'Zum Kontaktformular', 'knopf_url' => '#kontakt',
                'notiz' => 'Anrufen geht meistens schneller.',
                'stil' => 'marke',
            ],
        ],

        /*
         * Zeigt einen Kalender, keine Maske: freie Tage zum Anklicken,
         * darunter die Uhrzeiten des gewählten Tages. `wochen` bestimmt,
         * wie weit er reicht – vier ist der Standard, acht das Höchste.
         * Weiter vorauszuschauen kostet Rechenzeit für Tage, die ohnehin
         * kaum jemand anklickt.
         */
        'buchung' => [
            'name' => 'Buchungskalender', 'icon' => 'calendar', 'gruppe' => 'Handlung',
            'felder' => [
                'obertitel'  => ['Kleine Zeile darüber', 'text'],
                'titel'      => ['Überschrift', 'text'],
                'text'       => ['Text', 'mehrzeilig'],
                'service_id' => ['Nur diese Leistung (0 = alle)', 'zahl'],
                'wochen'     => ['Wie viele Wochen zeigen (1–8, leer = 4)', 'zahl'],
            ],
            'standard' => ['titel' => 'Termin buchen',
                           'text' => 'Such dir einen freien Tag aus – die Zeiten kommen direkt aus meinem Kalender.',
                           'service_id' => 0, 'wochen' => 4],
        ],

        /*
         * Der Weg zum Konto, direkt auf der Website.
         *
         * Bis hierher gab es ihn nur im Kundenzugang – wer ihn nicht
         * suchte, fand ihn nicht. Auf der Startseite steht er da, wo die
         * Leute ohnehin sind.
         *
         * Wichtig bleibt die Reihenfolge der Dinge: Gebucht wird weiter
         * ohne Konto. Dieser Baustein wirbt für den Bereich danach –
         * Termine, Trainingsplan, Unterlagen –, nicht für eine Hürde vor
         * der ersten Buchung.
         */
        'konto' => [
            'name' => 'Kundenzugang', 'icon' => 'lock', 'gruppe' => 'Handlung',
            'felder' => [
                'titel'      => ['Überschrift', 'text'],
                'text'       => ['Text', 'mehrzeilig'],
                'vorteile'   => ['Was der Kunde davon hat', 'liste', [
                    'text' => ['Zeile', 'text'],
                ]],
                'knopf_text' => ['Knopf', 'text'],
                'stil'       => ['Darstellung', 'auswahl', [
                    'formular' => 'Formular direkt auf der Seite',
                    'knopf'    => 'Nur ein Knopf zum Anmeldebereich',
                ]],
            ],
            'standard' => [
                'titel' => 'Dein Bereich bei uns',
                'text'  => 'Termine, Trainingsplan und Unterlagen an einem Ort – '
                         . 'auf dem Telefon genauso wie am Rechner.',
                'vorteile' => [
                    ['text' => 'Alle Termine auf einen Blick, absagen mit zwei Klicks'],
                    ['text' => 'Trainingsplan und Videoanalysen jederzeit griffbereit'],
                    ['text' => 'Rechnungen und Unterlagen zum Nachlesen'],
                ],
                'knopf_text' => 'Konto anlegen',
                'stil' => 'formular',
            ],
        ],

        'formular' => [
            'name' => 'Formular', 'icon' => 'inbox', 'gruppe' => 'Handlung',
            'felder' => [
                'titel'        => ['Überschrift', 'text'],
                'text'         => ['Text', 'mehrzeilig'],
                'form_id'      => ['Formular', 'zahl'],
                'knopf_text'   => ['Knopf', 'text'],
                'bestaetigung' => ['Text nach dem Absenden', 'text'],
            ],
            'standard' => ['titel' => 'Schreib mir', 'knopf_text' => 'Absenden',
                           'bestaetigung' => 'Danke! Ich melde mich in Kürze.'],
        ],

        'produkte' => [
            'name' => 'Produkte', 'icon' => 'products', 'gruppe' => 'Handlung',
            'felder' => [
                'titel'  => ['Überschrift', 'text'],
                'art'    => ['Art', 'auswahl', ['alle' => 'Alle', 'paket' => 'Pakete', 'kurs' => 'Kurse',
                                                'gutschein' => 'Gutscheine', 'merch' => 'Ausrüstung']],
                'anzahl' => ['Wie viele', 'zahl'],
            ],
            'standard' => ['titel' => 'Pakete und Angebote', 'art' => 'alle', 'anzahl' => 6],
        ],

        'kurse' => [
            'name' => 'Kurse', 'icon' => 'courses', 'gruppe' => 'Handlung',
            'felder' => ['titel' => ['Überschrift', 'text'], 'anzahl' => ['Wie viele', 'zahl']],
            'standard' => ['titel' => 'Online-Kurse', 'anzahl' => 3],
        ],

        'events' => [
            'name' => 'Events', 'icon' => 'events', 'gruppe' => 'Handlung',
            'felder' => ['titel' => ['Überschrift', 'text'], 'anzahl' => ['Wie viele', 'zahl']],
            'standard' => ['titel' => 'Kommende Termine', 'anzahl' => 3],
        ],

        'blog' => [
            'name' => 'Beiträge', 'icon' => 'content', 'gruppe' => 'Handlung',
            'felder' => ['titel' => ['Überschrift', 'text'], 'anzahl' => ['Wie viele', 'zahl']],
            'standard' => ['titel' => 'Aus dem Blog', 'anzahl' => 3],
        ],

        /*
         * Golfreisen als Kacheln.
         *
         * Nur die Kachel, nicht die ganze Reise: Programm, Leistungen und
         * Preise sind zwei Bildschirme voll: Die stehen auf der eigenen
         * Seite, hier stehen Ziel, Zeitraum, Preis und freie Plätze –
         * genug, um zu entscheiden, ob man weiterliest.
         */
        'reisen' => [
            'name' => 'Golfreisen', 'icon' => 'globe', 'gruppe' => 'Überzeugung',
            'felder' => [
                'obertitel' => ['Kleine Zeile darüber', 'text'],
                'titel'     => ['Überschrift', 'text'],
                'text'      => ['Text', 'mehrzeilig'],
                'anzahl'    => ['Wie viele zeigen', 'zahl'],
            ],
            'standard' => ['titel' => 'Golfreisen',
                           'text' => 'Eine Woche spielen, wo andere Urlaub machen – in kleiner Gruppe.',
                           'anzahl' => 3],
        ],

        'kontakt' => [
            'name' => 'Kontakt & Anfahrt', 'icon' => 'pin', 'gruppe' => 'Handlung',
            'felder' => [
                'obertitel' => ['Kleine Zeile darüber', 'text'],
                'titel'   => ['Überschrift', 'text'],
                'text'    => ['Text', 'mehrzeilig'],
                'karte'   => ['Karte einbetten', 'schalter'],
                'location_id' => ['Standort', 'zahl'],
            ],
            'standard' => ['titel' => 'So findest du mich', 'karte' => true, 'location_id' => 0],
        ],
    ];

    /** @return array<string,array<string,mixed>> */
    public static function typen(): array
    {
        return self::TYPEN;
    }

    public static function typ(string $key): ?array
    {
        return self::TYPEN[$key] ?? null;
    }

    public static function name(string $key): string
    {
        return (string) (self::TYPEN[$key]['name'] ?? $key);
    }

    public static function icon(string $key): string
    {
        return (string) (self::TYPEN[$key]['icon'] ?? 'layers');
    }

    /** @return array<string,array<int,string>> Gruppe => Bausteinschlüssel */
    public static function nachGruppe(): array
    {
        $gruppen = [];
        foreach (self::TYPEN as $key => $info) {
            $gruppen[(string) $info['gruppe']][] = $key;
        }
        return $gruppen;
    }

    public static function neu(string $typ): array
    {
        return [
            'id'   => 'b' . substr(Util::token(4), 0, 7),
            'typ'  => $typ,
            'daten'=> (array) (self::TYPEN[$typ]['standard'] ?? []),
        ];
    }
}
