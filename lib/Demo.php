<?php
/**
 * Demo – legt einen vollständig eingerichteten Beispiel-Workspace an.
 *
 * Der wichtigste Satz der Produktbeschreibung lautet: Beim ersten Öffnen
 * soll ein Pro verstehen, dass er damit sein ganzes Geschäft führen kann.
 * Eine leere Oberfläche schafft das nie – deshalb entsteht hier eine
 * Akademie mit zwei Standorten, drei Trainern, 24 Kunden, einem Jahr
 * Buchungshistorie, Rechnungen, Trainingsplänen, Kursen, einer fertigen
 * Website und Websitezahlen.
 *
 * Die Daten sind erfunden, aber plausibel: Handicaps passen zu den
 * Buchungsfrequenzen, Umsätze zu den Preisen, Termine zu den Arbeitszeiten.
 * Ein Demo-Datensatz, bei dem die Zahlen nicht zusammenpassen, verrät sich
 * sofort.
 */
final class Demo
{
    private static int $ws = 0;
    private static array $trainer = [];
    private static array $standorte = [];
    private static array $leistungen = [];
    private static array $pakete = [];
    private static array $kunden = [];

    private const VORNAMEN_M = ['Thomas', 'Michael', 'Andreas', 'Stefan', 'Markus', 'Christian', 'Peter',
                                'Tobias', 'Jan', 'Florian', 'Sebastian', 'Daniel', 'Matthias', 'Oliver'];
    private const VORNAMEN_W = ['Sandra', 'Katrin', 'Julia', 'Nicole', 'Anja', 'Christine', 'Petra',
                                'Stefanie', 'Miriam', 'Claudia', 'Franziska', 'Bettina', 'Susanne'];
    private const NACHNAMEN  = ['Berger', 'Wagner', 'Hoffmann', 'Keller', 'Lindner', 'Brandt', 'Kaufmann',
                                'Roth', 'Seidel', 'Vogt', 'Hartmann', 'Werner', 'Ziegler', 'Krause',
                                'Sommer', 'Winkler', 'Baumann', 'Rieger', 'Schuster', 'Neumann',
                                'Frank', 'Haas', 'Lorenz', 'Pohl', 'Engel'];
    private const CLUBS = ['GC Lindenhof', 'GC Hohenstein', 'Golfpark Rheintal', 'GC Waldsee',
                           'GC Am Mühlbach', 'Golfresort Seeblick', 'GC Birkenau', ''];

    /* ==================================================== Einstiegspunkt */

    /**
     * Ist dieser Demo-Termin schon bezahlt?
     *
     * Abgesagte nie. Aus dem Paket immer. Von den Einzelstunden der
     * letzten Wochen bleibt gut ein Drittel offen - das ist der Stapel,
     * den ein Pro am Monatsende abrechnet, und ohne ihn haette die
     * Demo-Anlage nichts zu zeigen.
     */
    private static function terminBezahlt(string $status, int $paketId, string $start): int
    {
        if ($status === 'abgesagt') {
            return 0;
        }
        if ($paketId > 0) {
            return 1;
        }
        $liegtZurueck = strtotime($start) <= time();
        $jung = strtotime($start) >= strtotime('-35 days');
        if ($liegtZurueck && $jung && mt_rand(1, 10) <= 4) {
            return 0;
        }
        return 1;
    }

    public static function anlegen(array $o = []): int
    {
        mt_srand(20260913);

        $name  = (string) ($o['name'] ?? 'Golf Academy Bergmann');
        $email = (string) ($o['email'] ?? 'demo@golfpro-cms.de');
        $pass  = (string) ($o['passwort'] ?? 'demo1234!GolfPro');

        return DB::transaktion(static function () use ($name, $email, $pass) {
            self::$ws = DB::insert('workspaces', [
                'slug'      => 'demo',
                'name'      => $name,
                'typ'       => 'academy',
                'plan'      => 'academy',
                'domain'    => '',
                'branding'  => Util::json([
                    'primaer' => '#2e6a3e', 'akzent' => '#f2c64b',
                    'schrift' => 'Archivo', 'radius' => 14, 'stil' => 'modern',
                ]),
                'waehrung'  => 'EUR',
                'sprache'   => 'de',
                'onboarding_schritt' => 9,
                'aktiv'     => 1,
                'demo'      => 1,
                'erstellt'  => date('Y-m-d H:i:s', strtotime('-2 years')),
            ]);
            Tenant::setzen(self::$ws);

            self::einstellungen();
            self::team($email, $pass);
            self::orte();
            self::leistungen();
            self::verfuegbarkeit();
            self::paketeAnlegen();
            self::produkte();
            self::kundenAnlegen();
            self::segmenteUndTags();
            self::einwilligungen();
            self::verkaeufe();
            self::buchungen();
            self::trainingsdaten();
            self::videos();
            self::kurse();
            self::events();
            self::leads();
            self::inhalte();
            self::website();
            self::marketing();
            self::communityDaten();
            self::websitezahlen();
            self::aufgabenUndMeldungen();

            return self::$ws;
        });
    }

    public static function vorhanden(): bool
    {
        return DB::int('SELECT COUNT(*) FROM workspaces WHERE demo = 1') > 0;
    }

    /* ------------------------------------------------------ Grunddaten - */

    private static function einstellungen(): void
    {
        foreach ([
            'module' => Module::standardFuerPlan('academy'),
            'steuersatz' => 19,
            'zahlungsziel_tage' => 14,
            'rechnung_praefix' => 'R',
            'rechnung_absender' => 'Golf Academy Bergmann · Lindenhofweg 4 · 76135 Karlsruhe',
            'rechnung_fuss' => 'Golf Academy Bergmann · Inhaber Daniel Bergmann · Lindenhofweg 4 · 76135 Karlsruhe',
            'steuernummer' => '35 123 45678',
            'bank' => 'Volksbank Karlsruhe · IBAN DE00 1234 5678 9012 3456 00',
            'website_beschreibung' => 'Golftraining für Einsteiger, Fortgeschrittene und Junioren '
                                    . 'in Karlsruhe – mit Videoanalyse und Trainingsplan.',
            'mail_absender_name' => 'Golf Academy Bergmann',
            /* Die Handschriftzeile im Kopf und die Zeile im Trainerprofil. */
            'kopf_zusatz' => 'Golfschule · Karlsruhe',
            'unterrichtszeiten' => 'Di – Sa, nach Vereinbarung',
            'stornofrist_stunden' => 24,
        ] as $k => $v) {
            Tenant::einstellungSetzen($k, $v);
        }
    }

    private static function team(string $email, string $pass): void
    {
        self::$trainer['owner'] = Tenant::insert('users', [
            'email' => $email, 'passwort' => Auth::hash($pass),
            'name' => 'Daniel Bergmann', 'rolle' => 'owner',
            'titel' => 'PGA Golf Professional · Head Pro',
            'telefon' => '0721 5540120',
            'bio' => 'Seit 18 Jahren Golflehrer, seit 2019 mit eigener Akademie. Schwerpunkt: '
                   . 'Einsteiger auf dem Weg zur Platzreife und Spieler, die vom mittleren ins '
                   . 'einstellige Handicap wollen.',
            'aktiv' => 1, 'letzter_login' => Util::jetzt(),
            'erstellt' => date('Y-m-d H:i:s', strtotime('-2 years')),
        ]);
        self::$trainer['pro2'] = Tenant::insert('users', [
            'email' => 'lena@golf-academy-bergmann.de', 'passwort' => Auth::hash(Util::token(12)),
            'name' => 'Lena Hoffmann', 'rolle' => 'head_pro',
            'titel' => 'PGA Golf Professional',
            'bio' => 'Kurzspiel und Juniorentraining. Betreut die Nachwuchsgruppen und das '
                   . 'Leistungstraining am Wochenende.',
            'aktiv' => 1, 'erstellt' => date('Y-m-d H:i:s', strtotime('-14 months')),
        ]);
        self::$trainer['assist'] = Tenant::insert('users', [
            'email' => 'buero@golf-academy-bergmann.de', 'passwort' => Auth::hash(Util::token(12)),
            'name' => 'Miriam Voss', 'rolle' => 'assistant',
            'titel' => 'Sekretariat',
            'aktiv' => 1, 'erstellt' => date('Y-m-d H:i:s', strtotime('-9 months')),
        ]);
    }

    private static function orte(): void
    {
        self::$standorte['club'] = Tenant::insert('locations', [
            'name' => 'Golfclub Lindenhof', 'typ' => 'club',
            'strasse' => 'Lindenhofweg 4', 'plz' => '76135', 'ort' => 'Karlsruhe',
            'notiz' => 'Übungsanlage mit Driving Range, drei Übungsgrüns und Kurzplatz.',
            'farbe' => '#0d6b4f', 'aktiv' => 1,
        ]);
        self::$standorte['indoor'] = Tenant::insert('locations', [
            'name' => 'Indoor Studio Stadtmitte', 'typ' => 'indoor',
            'strasse' => 'Kaiserstraße 118', 'plz' => '76133', 'ort' => 'Karlsruhe',
            'notiz' => 'Zwei Abschlagplätze mit Launch Monitor, Puttingfläche. Ganzjährig.',
            'farbe' => '#1d5fa8', 'aktiv' => 1,
        ]);
    }

    private static function leistungen(): void
    {
        $liste = [
            ['Einzeltraining 60 Minuten', 'einzel', 60, 8900, 1,
             'Individuelles Training an deinem Schwerpunkt – mit Videoaufnahme und schriftlicher Zusammenfassung.', '#0d6b4f'],
            ['Einzeltraining 30 Minuten', 'einzel', 30, 4900, 1,
             'Kurze Einheit für einen konkreten Punkt. Gut zwischendurch oder zur Vorbereitung auf eine Runde.', '#17794f'],
            ['Videoanalyse 60 Minuten', 'video', 60, 11900, 1,
             'Aufnahme aus zwei Perspektiven, Auswertung mit Linien und Winkeln, Übungen zum Mitnehmen.', '#1d5fa8'],
            ['Platzreifekurs (8 Einheiten)', 'kurs', 90, 34900, 6,
             'Von den ersten Schlägen bis zur Prüfung. Leihschläger, Bälle und Prüfungsgebühr sind enthalten.', '#b08829'],
            ['Gruppentraining 90 Minuten', 'gruppe', 90, 3900, 6,
             'Training in kleiner Gruppe – günstiger als Einzelunterricht und oft motivierender.', '#6b4ea8'],
            ['Platztraining 9 Löcher', 'einzel', 120, 14900, 2,
             'Training auf dem Platz: Schlägerwahl, Strategie und Umgang mit schwierigen Lagen.', '#a6640d'],
            ['Juniorentraining', 'gruppe', 60, 2200, 8,
             'Altersgerechtes Gruppentraining für Kinder und Jugendliche von 6 bis 16 Jahren.', '#b4242b'],
            ['Online-Coaching (Videocheck)', 'online', 30, 4500, 1,
             'Du schickst zwei Videos, ich schicke die Analyse mit Übungen zurück – ortsunabhängig.', '#0ea5a5'],
        ];
        foreach ($liste as $i => [$name, $art, $dauer, $preis, $kapazitaet, $text, $farbe]) {
            self::$leistungen[] = Tenant::insert('services', [
                'name' => $name, 'slug' => Util::slug($name), 'art' => $art,
                'beschreibung' => $text, 'dauer_min' => $dauer, 'puffer_min' => $dauer >= 90 ? 15 : 10,
                'preis_cent' => $preis, 'steuersatz' => 19, 'kapazitaet' => $kapazitaet,
                'location_id' => $art === 'online' ? 0 : self::$standorte['club'],
                'trainer_ids' => Util::json($art === 'gruppe'
                    ? [self::$trainer['owner'], self::$trainer['pro2']]
                    : [self::$trainer['owner']]),
                'farbe' => $farbe, 'online_buchbar' => 1,
                'vorlauf_stunden' => 12, 'stornofrist_stunden' => 24,
                'position' => $i, 'aktiv' => 1,
            ]);
        }
    }

    private static function verfuegbarkeit(): void
    {
        /*
         * Realistische Unterrichtszeiten, nicht Öffnungszeiten. Wer hier
         * 07:00 bis 21:00 einträgt, bekommt eine Auslastungszahl, die nach
         * Leerlauf aussieht, obwohl der Kalender voll ist.
         */
        // Head Pro: Dienstag bis Samstag
        foreach ([2 => [600, 1080], 3 => [600, 1080], 4 => [600, 1140], 5 => [600, 1080], 6 => [540, 900]] as $tag => [$von, $bis]) {
            Tenant::insert('availability', [
                'user_id' => self::$trainer['owner'], 'location_id' => self::$standorte['club'],
                'wochentag' => $tag, 'von_min' => $von, 'bis_min' => $bis, 'aktiv' => 1,
            ]);
        }
        // Zweite Pro: Mittwoch bis Sonntag
        foreach ([3 => [840, 1140], 4 => [840, 1140], 5 => [840, 1140], 6 => [540, 840], 0 => [600, 840]] as $tag => [$von, $bis]) {
            Tenant::insert('availability', [
                'user_id' => self::$trainer['pro2'], 'location_id' => self::$standorte['club'],
                'wochentag' => $tag, 'von_min' => $von, 'bis_min' => $bis, 'aktiv' => 1,
            ]);
        }
        Tenant::insert('time_off', [
            'user_id' => self::$trainer['owner'], 'titel' => 'Fortbildung PGA',
            'art' => 'sperrzeit',
            'von' => date('Y-m-d 09:00:00', strtotime('+18 days')),
            'bis' => date('Y-m-d 18:00:00', strtotime('+19 days')),
            'ganztags' => 1,
        ]);
    }

    private static function paketeAnlegen(): void
    {
        $liste = [
            ['Golf Starter 5', 5, 60, 39900, 'Fünf Einzelstunden à 60 Minuten. Der übliche Einstieg – '
             . 'genug Zeit, um eine Veränderung wirklich zu festigen.', '#0d6b4f'],
            ['Golf Fortschritt 10', 10, 60, 74900, 'Zehn Einzelstunden mit Videoanalyse in Stunde 1, 5 und 10. '
             . 'Für alle, die in einer Saison ein Handicap-Ziel erreichen wollen.', '#1d5fa8'],
            ['Kurzspiel 5', 5, 45, 29900, 'Fünf Einheiten à 45 Minuten, ausschließlich Chippen, Pitchen und Putten. '
             . 'Hier liegen die meisten Schläge.', '#b08829'],
            ['Winterpaket Indoor 8', 8, 45, 51900, 'Acht Indoor-Einheiten mit Launch Monitor zwischen November '
             . 'und März. Damit im Frühjahr nichts fehlt.', '#6b4ea8'],
        ];
        foreach ($liste as $i => [$name, $einheiten, $dauer, $preis, $text, $farbe]) {
            self::$pakete[] = Tenant::insert('packages', [
                'name' => $name, 'service_id' => self::$leistungen[0],
                'einheiten' => $einheiten, 'dauer_min' => $dauer, 'preis_cent' => $preis,
                'gueltig_tage' => 365, 'beschreibung' => $text, 'farbe' => $farbe,
                'aktiv' => 1, 'position' => $i,
            ]);
        }
    }

    private static function produkte(): void
    {
        foreach (self::$pakete as $i => $paketId) {
            $paket = Tenant::find('packages', $paketId);
            Tenant::insert('products', [
                'art' => 'paket', 'name' => (string) $paket['name'],
                'slug' => Util::slug((string) $paket['name']),
                'kurztext' => (int) $paket['einheiten'] . ' Einheiten à ' . (int) $paket['dauer_min'] . ' Minuten',
                'beschreibung' => (string) $paket['beschreibung'],
                'preis_cent' => (int) $paket['preis_cent'],
                'vergleichspreis_cent' => (int) round((int) $paket['einheiten'] * 8900),
                'ref_id' => $paketId, 'aktiv' => 1, 'position' => $i, 'bestand' => -1,
            ]);
        }
        Tenant::insert('products', [
            'art' => 'gutschein', 'name' => 'Geschenkgutschein', 'slug' => 'geschenkgutschein',
            'kurztext' => 'Frei wählbarer Betrag',
            'beschreibung' => 'Ein Gutschein für Golftraining – einlösbar für alle Leistungen, drei Jahre gültig. '
                            . 'Wird als PDF sofort zum Ausdrucken geliefert.',
            'preis_cent' => 10000, 'aktiv' => 1, 'position' => 10, 'bestand' => -1,
        ]);
        Tenant::insert('products', [
            'art' => 'merch', 'name' => 'Trainingsball-Set (12 Stück)', 'slug' => 'trainingsball-set',
            'kurztext' => 'Softbälle für das Training zu Hause',
            'beschreibung' => 'Zwölf Schaumstoffbälle mit realistischem Flugverhalten über kurze Distanz – '
                            . 'für Chippen und Pitchen im Garten oder Wohnzimmer.',
            'preis_cent' => 2400, 'aktiv' => 1, 'position' => 20, 'bestand' => 24,
        ]);
        Tenant::insert('discounts', [
            'code' => 'FRUEHLING26', 'art' => 'prozent', 'wert' => 10,
            'mindestwert_cent' => 20000,
            'gueltig_von' => date('Y-m-d H:i:s', strtotime('-40 days')),
            'gueltig_bis' => date('Y-m-d H:i:s', strtotime('+40 days')),
            'limit_gesamt' => 50, 'genutzt' => 7, 'aktiv' => 1,
        ]);
    }

    /* -------------------------------------------------------- Kunden -- */

    private static function kundenAnlegen(): void
    {
        $vorlage = [
            // [Geschlecht, HCP, Monate dabei, Aktivität 0..3, Newsletter]
            ['m', '11,4', 22, 3, 1], ['w', '18,7', 19, 3, 1], ['m', '26,3', 16, 2, 1],
            ['w', '36,0', 3, 3, 1],  ['m', '54', 2, 3, 0],    ['m', '8,2', 24, 3, 1],
            ['w', '23,1', 14, 1, 1], ['m', '32,5', 11, 2, 1], ['w', '45,0', 6, 2, 1],
            ['m', '15,8', 20, 2, 0], ['w', '29,4', 9, 1, 1],  ['m', '54', 1, 3, 1],
            ['m', '20,6', 18, 0, 1], ['w', '12,9', 21, 2, 1], ['m', '41,2', 7, 1, 0],
            ['w', '54', 1, 3, 1],    ['m', '17,3', 13, 2, 1], ['w', '34,8', 10, 0, 1],
            ['m', '9,6', 23, 3, 1],  ['m', '27,9', 8, 1, 1],  ['w', '21,5', 15, 2, 1],
            ['m', '36,0', 4, 2, 1],  ['w', '48,0', 5, 1, 0],  ['m', '14,1', 17, 3, 1],
            ['w', '30,2', 12, 3, 1], ['m', '19,9', 26, 3, 1], ['w', '54', 2, 2, 1],
            ['m', '24,7', 15, 3, 0], ['w', '16,4', 28, 3, 1], ['m', '38,1', 6, 2, 1],
            ['w', '10,8', 30, 3, 1], ['m', '45,0', 4, 3, 1],  ['w', '25,6', 13, 2, 1],
            ['m', '13,2', 19, 3, 1], ['w', '33,7', 8, 1, 1],  ['m', '54', 3, 2, 0],
            // Frisch dazugekommen – damit „Neukunden“ im Dashboard nicht null ist
            ['w', '54', 0, 3, 1],    ['m', '54', 0, 3, 1],    ['w', '42,3', 0, 2, 1],
            ['m', '36,0', 0, 3, 0],
        ];

        foreach ($vorlage as $i => [$g, $hcp, $monate, $aktivitaet, $newsletter]) {
            $vorname  = $g === 'm'
                ? self::VORNAMEN_M[$i % count(self::VORNAMEN_M)]
                : self::VORNAMEN_W[$i % count(self::VORNAMEN_W)];
            $nachname = self::NACHNAMEN[$i % count(self::NACHNAMEN)];
            $seit = $monate > 0
                ? date('Y-m-d H:i:s', strtotime('-' . $monate . ' months -' . mt_rand(0, 25) . ' days'))
                : date('Y-m-d H:i:s', strtotime('-' . mt_rand(3, 26) . ' days'));

            $letzteAktivitaet = match ($aktivitaet) {
                3 => date('Y-m-d H:i:s', strtotime('-' . mt_rand(1, 12) . ' days')),
                2 => date('Y-m-d H:i:s', strtotime('-' . mt_rand(20, 45) . ' days')),
                1 => date('Y-m-d H:i:s', strtotime('-' . mt_rand(65, 110) . ' days')),
                default => date('Y-m-d H:i:s', strtotime('-' . mt_rand(150, 260) . ' days')),
            };

            $ziele = [
                'Platzreife im Frühjahr', 'Handicap unter 20 spielen', 'Sicherer im Bunker werden',
                'Weniger Putts pro Runde', 'Erste Turnierteilnahme', 'Konstanter mit den Eisen',
                'Wieder regelmäßig spielen', 'Mehr Länge vom Abschlag',
            ];

            $id = Tenant::insert('customers', [
                'vorname' => $vorname, 'nachname' => $nachname,
                'email' => strtolower($vorname . '.' . self::umlaute($nachname)) . '@beispiel.de',
                'telefon' => '01' . mt_rand(50, 79) . ' ' . mt_rand(1000000, 9999999),
                // Jede sechste Akte ist ein Junior: Eine Academy ohne Jugend
                // waere unrealistisch, und das Segment „Junior" bliebe leer.
                'geburtstag' => date('Y-m-d', strtotime(
                    '-' . ($i % 6 === 2 ? mt_rand(10, 17) : mt_rand(19, 68)) . ' years -'
                    . mt_rand(0, 364) . ' days')),
                'strasse' => ['Rosenweg', 'Am Hang', 'Bahnhofstraße', 'Lindenallee', 'Uferweg'][$i % 5] . ' ' . mt_rand(1, 88),
                'plz' => (string) mt_rand(76131, 76229), 'ort' => 'Karlsruhe',
                'hcp' => Util::hcpNormal($hcp), 'heimclub' => self::CLUBS[$i % count(self::CLUBS)],
                'ziele' => $ziele[$i % count($ziele)],
                'dominante_hand' => $i % 9 === 0 ? 'links' : 'rechts',
                'status' => 'aktiv',
                'quelle' => ['Website', 'Empfehlung', 'Google', 'Club', 'Instagram', 'Website'][$i % 6],
                'trainer_id' => $i % 3 === 0 ? self::$trainer['pro2'] : self::$trainer['owner'],
                'location_id' => self::$standorte['club'],
                'tags' => Util::json(self::tagsFuer(Util::zahlAus($hcp), $monate)),
                'newsletter' => $newsletter,
                'portal_token' => Util::token(16),
                'letzte_aktivitaet' => $letzteAktivitaet,
                'erstellt' => $seit,
            ]);
            self::$kunden[] = ['id' => $id, 'aktivitaet' => $aktivitaet, 'hcp' => $hcp, 'seit' => $seit];
        }

        /* Notizen bei einigen Kunden */
        $notizen = [
            'Arbeitet im Schichtdienst – Termine am besten vormittags anbieten.',
            'Will im Sommer beim Clubturnier mitspielen. Darauf hinarbeiten.',
            'Hatte Rückenprobleme im Winter. Belastung im Auge behalten.',
            'Sehr ehrgeizig, neigt dazu, zu viel auf einmal umsetzen zu wollen.',
            'Kommt meistens mit der Partnerin zusammen – Doppeltermine anbieten.',
        ];
        foreach (array_slice(self::$kunden, 0, 5) as $i => $k) {
            Tenant::insert('customer_notes', [
                'customer_id' => $k['id'], 'user_id' => self::$trainer['owner'],
                'text' => $notizen[$i], 'angeheftet' => $i === 0 ? 1 : 0,
                'erstellt' => date('Y-m-d H:i:s', strtotime('-' . mt_rand(10, 200) . ' days')),
            ]);
        }
    }

    private static function tagsFuer(float $hcp, int $monate): array
    {
        $tags = [];
        if ($hcp >= 37) {
            $tags[] = 'Einsteiger';
        } elseif ($hcp <= 12) {
            $tags[] = 'Turnierspieler';
        }
        if ($monate >= 18) {
            $tags[] = 'Stammkunde';
        }
        if ($monate <= 3) {
            $tags[] = 'Neu';
        }
        return $tags;
    }

    private static function umlaute(string $text): string
    {
        return strtr($text, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss',
                             'Ä' => 'ae', 'Ö' => 'oe', 'Ü' => 'ue']);
    }

    private static function segmenteUndTags(): void
    {
        Segments::standardAnlegen();
        foreach (['Einsteiger' => '#2f7db8', 'Stammkunde' => '#0d6b4f', 'Turnierspieler' => '#b08829',
                  'Neu' => '#17794f'] as $name => $farbe) {
            Tenant::insert('tags', ['name' => $name, 'farbe' => $farbe]);
        }
        Tenant::insert('custom_fields', [
            'objekt' => 'customer', 'schluessel' => 'schlaeger', 'label' => 'Schlägermarke',
            'typ' => 'text', 'position' => 0,
        ]);
        Tenant::insert('custom_fields', [
            'objekt' => 'customer', 'schluessel' => 'mitglied', 'label' => 'Clubmitglied',
            'typ' => 'checkbox', 'position' => 1,
        ]);
    }

    /**
     * Das Einwilligungsprotokoll.
     *
     * Ohne Einträge sähe der Datenschutzbereich leer aus, und genau dort
     * soll man sehen, wie der Nachweis nach Artikel 7 Absatz 1 DSGVO
     * aussieht: Wortlaut, Zeitpunkt und Quelle, für jede Zustimmung
     * einzeln. Zwei Widerrufe sind auch dabei – ein Protokoll, in dem nur
     * Zustimmungen stehen, ist keins.
     */
    private static function einwilligungen(): void
    {
        foreach (Tenant::all('customers', '', [], 'id') as $i => $k) {
            $angelegt = (string) $k['erstellt'];

            if ((int) $k['newsletter'] === 1) {
                Tenant::insert('consents', [
                    'customer_id' => (int) $k['id'], 'typ' => 'newsletter', 'erteilt' => 1,
                    'text' => 'Ich möchte Tipps und Termine per E-Mail bekommen. '
                            . 'Abbestellen geht jederzeit mit einem Klick.',
                    'quelle' => $i % 3 === 0 ? 'Formular: Platzreife-Anfrage' : 'buchen.php',
                    'ip' => '', 'erstellt' => $angelegt,
                ]);
            }

            /* Die Einwilligung zur Buchung hat jeder gegeben – sonst gäbe es keinen Termin. */
            Tenant::insert('consents', [
                'customer_id' => (int) $k['id'], 'typ' => 'buchung', 'erteilt' => 1,
                'text' => 'Einwilligung zur Verarbeitung der Angaben für die Terminbuchung.',
                'quelle' => $i % 4 === 0 ? 'Backend' : 'buchen.php',
                'ip' => '', 'erstellt' => $angelegt,
            ]);

            /* Zwei Widerrufe, damit auch dieser Fall im Protokoll steht. */
            if ($i === 6 || $i === 19) {
                Tenant::insert('consents', [
                    'customer_id' => (int) $k['id'], 'typ' => 'newsletter', 'erteilt' => 0,
                    'text' => 'Widerruf über den Abmeldelink im Newsletter.',
                    'quelle' => 'abmelden-newsletter.php', 'ip' => '',
                    'erstellt' => date('Y-m-d H:i:s', strtotime('-' . (17 + $i) . ' days')),
                ]);
                Tenant::update('customers', (int) $k['id'], ['newsletter' => 0]);
            }
        }

        /* Eine offene Auskunftsanfrage – damit der Vorgang sichtbar ist. */
        $wer = Tenant::one('customers', '', [], 'id DESC');
        if ($wer !== null) {
            Tenant::insert('data_requests', [
                'customer_id' => (int) $wer['id'], 'typ' => 'export', 'status' => 'offen',
                'notiz' => 'Über das Kundenportal gestellt.',
                'erstellt' => date('Y-m-d H:i:s', strtotime('-3 days')),
            ]);
        }
    }

    /* ------------------------------------------------------ Buchungen - */

    private static function buchungen(): void
    {
        $leistungen = [];
        foreach (self::$leistungen as $id) {
            $leistungen[$id] = Tenant::find('services', $id);
        }

        foreach (self::$kunden as $k) {
            /*
             * Die gekauften Pakete wurden bereits angelegt. Ihre verbrauchten
             * Einheiten bekommen jetzt echte Termine – sonst stünde in der
             * Kundenakte „3 von 5 genutzt“ ohne dass man sähe, wann.
             */
            $offenePaketEinheiten = [];
            foreach (Tenant::all('customer_packages', 'customer_id = :k', ['k' => $k['id']]) as $cp) {
                for ($n = 0; $n < (int) $cp['einheiten_genutzt']; $n++) {
                    $offenePaketEinheiten[] = (int) $cp['id'];
                }
            }

            // Wie oft war jemand da? Hängt an der Aktivitätsstufe.
            $anzahl = [0 => 8, 1 => 16, 2 => 34, 3 => 56][$k['aktivitaet']];
            $abstand = [0 => [14, 28], 1 => [11, 20], 2 => [7, 13], 3 => [4, 9]][$k['aktivitaet']];
            $letzterTag = match ($k['aktivitaet']) {
                3 => mt_rand(2, 9), 2 => mt_rand(20, 38), 1 => mt_rand(68, 100), default => mt_rand(150, 230),
            };
            $seitTage = min(720, max(30, (int) ((time() - strtotime($k['seit'])) / 86400)));

            for ($n = 0; $n < $anzahl; $n++) {
                $tageZurueck = $letzterTag + $n * mt_rand($abstand[0], $abstand[1]);
                if ($tageZurueck > $seitTage) {
                    break;
                }
                $zeit = strtotime('-' . $tageZurueck . ' days');
                if ((int) date('w', $zeit) === 1) {
                    $zeit += 86400;   // montags ist geschlossen
                }
                if ((int) date('w', $zeit) === 0) {
                    $zeit -= 86400;
                }
                $serviceId = self::$leistungen[array_rand(array_slice(self::$leistungen, 0, 6, true))];
                $service = $leistungen[$serviceId];
                $start = date('Y-m-d', $zeit) . sprintf(' %02d:%02d:00', mt_rand(9, 18), mt_rand(0, 1) * 30);
                $ende = date('Y-m-d H:i:s', strtotime($start) + (int) $service['dauer_min'] * 60);

                $status = mt_rand(1, 100) <= 92 ? 'erschienen' : (mt_rand(0, 1) ? 'abgesagt' : 'nicht_erschienen');
                $paketId = ($status === 'erschienen' && $offenePaketEinheiten !== [])
                    ? array_pop($offenePaketEinheiten) : 0;

                Tenant::insert('bookings', [
                    'service_id' => $serviceId, 'customer_id' => $k['id'],
                    'trainer_id' => mt_rand(1, 10) <= 7 ? self::$trainer['owner'] : self::$trainer['pro2'],
                    'location_id' => self::$standorte['club'],
                    'titel' => (string) $service['name'],
                    'start' => $start, 'ende' => $ende,
                    'status' => $status, 'teilnehmer' => 1,
                    'preis_cent' => (int) $service['preis_cent'],
                    /*
                     * Ein Termin aus dem Paket ist bezahlt, zählt aber nicht
                     * noch einmal als Umsatz – das regelt Commerce::umsatz().
                     *
                     * Ein Teil der jüngsten Einzelstunden bleibt bewusst
                     * offen: So hat der Demo-Betrieb auch etwas zum
                     * Abrechnen, und die Liste der offenen Posten steht
                     * nicht leer da. Ohne Paket, denn aus einem Paket
                     * bezahlte Stunden schreibt man nicht noch einmal an.
                     */
                    'bezahlt' => self::terminBezahlt($status, $paketId, $start),
                    'customer_package_id' => $paketId,
                    'quelle' => mt_rand(1, 10) <= 4 ? 'website' : 'backend',
                    'erstellt' => date('Y-m-d H:i:s', strtotime($start) - mt_rand(2, 20) * 86400),
                ]);
            }
        }

        /* Kommende Termine – der Kalender der nächsten Wochen soll voll wirken. */
        $aktive = array_values(array_filter(self::$kunden, static fn($k) => $k['aktivitaet'] >= 2));
        for ($tag = 0; $tag < 28; $tag++) {
            $zeit = strtotime('+' . $tag . ' days');
            $wochentag = (int) date('w', $zeit);
            if ($wochentag === 1) {
                continue;   // Ruhetag
            }
            $stunden = $wochentag === 6 || $wochentag === 0 ? [9, 10, 11, 12, 13, 14] : [10, 11, 12, 13, 14, 15, 16, 17];
            shuffle($stunden);
            $proTag = min(count($stunden), $wochentag === 0 ? mt_rand(2, 3) : mt_rand(5, 8));

            foreach (array_slice($stunden, 0, $proTag) as $stunde) {
                $k = $aktive[array_rand($aktive)];
                $serviceId = self::$leistungen[array_rand(array_slice(self::$leistungen, 0, 6, true))];
                $service = $leistungen[$serviceId];
                $start = date('Y-m-d', $zeit) . sprintf(' %02d:00:00', $stunde);

                $paket = Tenant::one('customer_packages',
                    "customer_id = :k AND status = 'aktiv' AND einheiten_genutzt < einheiten_gesamt",
                    ['k' => $k['id']]);

                $buchungId = Tenant::insert('bookings', [
                    'service_id' => $serviceId, 'customer_id' => $k['id'],
                    'trainer_id' => mt_rand(1, 10) <= 7 ? self::$trainer['owner'] : self::$trainer['pro2'],
                    'location_id' => self::$standorte['club'],
                    'titel' => (string) $service['name'],
                    'start' => $start,
                    'ende' => date('Y-m-d H:i:s', strtotime($start) + (int) $service['dauer_min'] * 60),
                    'status' => ($tag === 0 && $stunde < (int) date('G')) ? 'erschienen' : 'bestaetigt',
                    'teilnehmer' => 1, 'preis_cent' => (int) $service['preis_cent'],
                    'bezahlt' => $paket ? 1 : mt_rand(0, 1),
                    'customer_package_id' => $paket ? (int) $paket['id'] : 0,
                    'quelle' => mt_rand(1, 10) <= 5 ? 'website' : 'backend',
                    'erstellt' => date('Y-m-d H:i:s', strtotime('-' . mt_rand(1, 14) . ' days')),
                ]);

                /* Gruppentermine bekommen mehrere Teilnehmer. */
                if ((string) $service['art'] === 'gruppe') {
                    $teilnehmer = mt_rand(3, min(6, (int) $service['kapazitaet']));
                    $gemischt = $aktive;
                    shuffle($gemischt);
                    foreach (array_slice($gemischt, 0, $teilnehmer) as $t) {
                        Tenant::insert('booking_participants', [
                            'booking_id' => $buchungId, 'customer_id' => $t['id'],
                            'status' => 'gebucht', 'bezahlt' => 1,
                        ]);
                    }
                    Tenant::update('bookings', $buchungId, ['teilnehmer' => $teilnehmer]);
                }
            }
        }
    }

    /* ------------------------------------------------- Käufe & Belege - */

    private static function verkaeufe(): void
    {
        $produkte = Tenant::all('products', "art = 'paket'", [], 'position');

        foreach (self::$kunden as $i => $k) {
            if ($k['aktivitaet'] === 0 && $i % 3 !== 0) {
                continue;
            }
            $anzahlKaeufe = $k['aktivitaet'] >= 2 ? mt_rand(1, 3) : 1;

            for ($n = 0; $n < $anzahlKaeufe; $n++) {
                $produkt = $produkte[array_rand($produkte)];
                // Quadratisch zum Jetzt hin gewichtet: Ein Geschäft, das
                // laeuft, macht in den letzten Monaten mehr Umsatz als vor
                // zwei Jahren. Gleichverteilte Kaeufe sehen tot aus.
                $tageHer = (int) round(pow(mt_rand(0, 1000) / 1000, 1.35) * 660) + mt_rand(1, 8);
                $wann = date('Y-m-d H:i:s', strtotime('-' . $tageHer . ' days'));

                $orderId = Tenant::insert('orders', [
                    'nummer' => Commerce::naechsteNummer('orders', 'B'),
                    'customer_id' => $k['id'],
                    'email' => (string) (Tenant::find('customers', $k['id'])['email'] ?? ''),
                    'name' => Customers::nameVonId($k['id']),
                    'netto_cent' => (int) round((int) $produkt['preis_cent'] / 1.19),
                    'steuer_cent' => (int) $produkt['preis_cent'] - (int) round((int) $produkt['preis_cent'] / 1.19),
                    'summe_cent' => (int) $produkt['preis_cent'],
                    'status' => 'bezahlt',
                    'zahlungsart' => ['karte', 'sepa', 'ueberweisung', 'applepay'][array_rand(['karte', 'sepa', 'ueberweisung', 'applepay'])],
                    'quelle' => mt_rand(1, 10) <= 6 ? 'website' : 'backend',
                    'bezahlt_am' => $wann, 'erstellt' => $wann,
                ]);
                Tenant::insert('order_items', [
                    'order_id' => $orderId, 'product_id' => (int) $produkt['id'],
                    'titel' => (string) $produkt['name'], 'menge' => 1,
                    'einzelpreis_cent' => (int) $produkt['preis_cent'], 'steuersatz' => 19,
                    'summe_cent' => (int) $produkt['preis_cent'],
                ]);
                Tenant::insert('payments', [
                    'order_id' => $orderId, 'customer_id' => $k['id'],
                    'anbieter' => 'stripe', 'methode' => 'karte',
                    'betrag_cent' => (int) $produkt['preis_cent'],
                    'gebuehr_cent' => Stripe::gebuehr((int) $produkt['preis_cent']),
                    'status' => 'bezahlt', 'extern_id' => 'pi_demo_' . Util::token(6),
                    'erstellt' => $wann,
                ]);

                /* Paketguthaben */
                $paket = Tenant::find('packages', (int) $produkt['ref_id']);
                if ($paket) {
                    $genutzt = mt_rand(0, (int) $paket['einheiten']);
                    Tenant::insert('customer_packages', [
                        'customer_id' => $k['id'], 'package_id' => (int) $paket['id'],
                        'name' => (string) $paket['name'],
                        'einheiten_gesamt' => (int) $paket['einheiten'],
                        'einheiten_genutzt' => $genutzt,
                        'preis_cent' => (int) $paket['preis_cent'], 'order_id' => $orderId,
                        'gekauft' => $wann,
                        'laeuft_ab' => date('Y-m-d H:i:s', strtotime($wann . ' +365 days')),
                        'status' => $genutzt >= (int) $paket['einheiten'] ? 'aufgebraucht' : 'aktiv',
                        'erstellt' => $wann,
                    ]);
                }

                /* Rechnung */
                $rechnungId = Tenant::insert('invoices', [
                    'nummer' => Commerce::naechsteNummer('invoices', 'R'),
                    'customer_id' => $k['id'], 'order_id' => $orderId, 'art' => 'rechnung',
                    'empfaenger' => Util::json(self::anschrift($k['id'])),
                    'datum' => date('Y-m-d', strtotime($wann)),
                    'faellig' => date('Y-m-d', strtotime($wann . ' +14 days')),
                    'netto_cent' => (int) round((int) $produkt['preis_cent'] / 1.19),
                    'steuer_cent' => (int) $produkt['preis_cent'] - (int) round((int) $produkt['preis_cent'] / 1.19),
                    'summe_cent' => (int) $produkt['preis_cent'],
                    'bezahlt_cent' => (int) $produkt['preis_cent'],
                    'status' => 'bezahlt', 'bezahlt_am' => $wann, 'erstellt' => $wann,
                ]);
                Tenant::insert('invoice_items', [
                    'invoice_id' => $rechnungId, 'titel' => (string) $produkt['name'],
                    'beschreibung' => (string) $produkt['kurztext'], 'menge' => 1,
                    'einzelpreis_cent' => (int) $produkt['preis_cent'], 'steuersatz' => 19,
                    'summe_cent' => (int) $produkt['preis_cent'], 'position' => 0,
                ]);
            }
        }

        /* Drei offene, zwei überfällige Rechnungen – so sieht Alltag aus. */
        foreach ([[-4, 'offen'], [-2, 'offen'], [3, 'offen'], [-22, 'ueberfaellig'], [-38, 'ueberfaellig']] as $n => [$tage, $status]) {
            $k = self::$kunden[($n * 5) % count(self::$kunden)];
            $betrag = [8900, 11900, 34900, 14900, 39900][$n];
            $datum = date('Y-m-d', strtotime(($tage - 14) . ' days'));
            $rechnungId = Tenant::insert('invoices', [
                'nummer' => Commerce::naechsteNummer('invoices', 'R'),
                'customer_id' => $k['id'], 'art' => 'rechnung',
                'empfaenger' => Util::json(self::anschrift($k['id'])),
                'datum' => $datum,
                'faellig' => date('Y-m-d', strtotime($datum . ' +14 days')),
                'netto_cent' => (int) round($betrag / 1.19),
                'steuer_cent' => $betrag - (int) round($betrag / 1.19),
                'summe_cent' => $betrag, 'bezahlt_cent' => 0,
                'status' => $status,
                'erstellt' => $datum . ' 10:00:00',
            ]);
            Tenant::insert('invoice_items', [
                'invoice_id' => $rechnungId,
                'titel' => ['Einzeltraining 60 Minuten', 'Videoanalyse 60 Minuten', 'Platzreifekurs (8 Einheiten)',
                            'Platztraining 9 Löcher', 'Golf Starter 5'][$n],
                'menge' => 1, 'einzelpreis_cent' => $betrag, 'steuersatz' => 19,
                'summe_cent' => $betrag, 'position' => 0,
            ]);
        }

        /* Gutscheine */
        foreach ([[10000, 'aktiv', 'Lisa Berger'], [5000, 'aktiv', 'Onkel Werner'],
                  [15000, 'eingeloest', 'Team Vertrieb']] as [$wert, $status, $empfaenger]) {
            Tenant::insert('vouchers', [
                'code' => 'GP-' . Util::code(8), 'art' => 'wert',
                'wert_cent' => $wert, 'rest_cent' => $status === 'eingeloest' ? 0 : $wert,
                'empfaenger' => $empfaenger, 'kaeufer' => Customers::nameVonId(self::$kunden[0]['id']),
                'nachricht' => 'Alles Gute zum Geburtstag – viel Freude beim Training!',
                'gueltig_bis' => date('Y-m-d', strtotime('+2 years')),
                'status' => $status,
                'eingeloest' => $status === 'eingeloest' ? date('Y-m-d H:i:s', strtotime('-30 days')) : null,
                'erstellt' => date('Y-m-d H:i:s', strtotime('-' . mt_rand(20, 200) . ' days')),
            ]);
        }
    }

    private static function anschrift(int $kundeId): array
    {
        $k = Tenant::find('customers', $kundeId) ?? [];
        return [
            'name' => Customers::name($k), 'strasse' => (string) ($k['strasse'] ?? ''),
            'plz' => (string) ($k['plz'] ?? ''), 'ort' => (string) ($k['ort'] ?? ''),
            'land' => 'DE', 'email' => (string) ($k['email'] ?? ''),
        ];
    }

    /* ------------------------------------------------------- Training - */

    private static function trainingsdaten(): void
    {
        Training::standardUebungenAnlegen();

        /* Drei Pläne: zwei zugewiesen, einer als Vorlage */
        $plaene = [
            ['4 Wochen Putting-Grundlagen', 'putting', 4, 'Weniger Putts pro Runde – Ziel: unter 34.', 'anfaenger'],
            ['6 Wochen Kurzspiel', 'chipping', 6, 'Scrambling-Quote von 20 auf 35 Prozent.', 'mittel'],
            ['Wintertraining Indoor', 'fitness', 8, 'Beweglichkeit und Schlaggeschwindigkeit über den Winter halten.', 'mittel'],
        ];
        foreach ($plaene as $i => [$name, $kategorie, $wochen, $ziel, $niveau]) {
            $kundeId = $i < 2 ? self::$kunden[$i * 3]['id'] : 0;
            $planId = Tenant::insert('training_plans', [
                'name' => $name, 'customer_id' => $kundeId,
                'vorlage' => $kundeId === 0 ? 1 : 0,
                'wochen' => $wochen, 'einheiten_woche' => 2,
                'ziel' => $ziel, 'niveau' => $niveau,
                'status' => $kundeId > 0 ? 'aktiv' : 'entwurf',
                'quelle' => $i === 1 ? 'ai' : 'manuell',
                'user_id' => self::$trainer['owner'],
                'start' => date('Y-m-d', strtotime('-' . ($i * 14 + 7) . ' days')),
                'erstellt' => date('Y-m-d H:i:s', strtotime('-' . ($i * 14 + 8) . ' days')),
            ]);

            $uebungen = Training::uebungen(['kategorie' => $kategorie]);
            $ergaenzung = Training::uebungen(['kategorie' => 'fitness']);
            $alle = array_merge($uebungen, $ergaenzung);
            if ($alle === []) {
                continue;
            }
            $n = 0;
            for ($woche = 1; $woche <= $wochen; $woche++) {
                for ($einheit = 1; $einheit <= 2; $einheit++) {
                    for ($pos = 0; $pos < 3; $pos++) {
                        $u = $alle[$n++ % count($alle)];
                        Tenant::insert('plan_items', [
                            'plan_id' => $planId, 'exercise_id' => (int) $u['id'],
                            'woche' => $woche, 'einheit' => $einheit,
                            'titel' => (string) $u['titel'], 'notiz' => (string) $u['beschreibung'],
                            'dauer_min' => (int) $u['dauer_min'],
                            'wiederholungen' => (string) $u['wiederholungen'],
                            'position' => $pos,
                            'erledigt' => ($woche <= 2 && $kundeId > 0)
                                ? date('Y-m-d H:i:s', strtotime('-' . mt_rand(3, 20) . ' days')) : null,
                        ]);
                    }
                }
            }
            if ($kundeId > 0) {
                Tenant::insert('plan_assignments', [
                    'plan_id' => $planId, 'customer_id' => $kundeId,
                    'start' => date('Y-m-d', strtotime('-' . ($i * 14 + 7) . ' days')),
                    'fortschritt' => $i === 0 ? 42 : 28, 'status' => 'aktiv',
                ]);
            }
        }

        /* Leistungsdaten für aktive Spieler */
        foreach (array_slice(self::$kunden, 0, 10) as $k) {
            $hcp = Util::zahlAus((string) $k['hcp']);
            if ($hcp >= 50) {
                continue;
            }
            /*
             * Die Reihe endet auf dem Handicap, das in der Kundenakte steht.
             * Sonst zeigt das Portal oben „Handicap 11,4" und darunter eine
             * Tabelle, deren jüngste Runde 13,0 sagt - ein Widerspruch, den
             * jeder Kunde sofort bemerkt.
             */
            $runden  = mt_rand(5, 14);
            $spanne  = min(2.6, max(0.6, $hcp * 0.16));
            $schritt = $spanne / max(1, $runden - 1);
            for ($n = $runden; $n > 0; $n--) {
                $aktuellerHcp = max(1.0, $hcp + ($n - 1) * $schritt);
                Tenant::insert('performance_entries', [
                    'customer_id' => $k['id'],
                    'datum' => date('Y-m-d', strtotime('-' . ($n * mt_rand(12, 26)) . ' days')),
                    'hcp' => number_format($aktuellerHcp, 1, '.', ''),
                    'score' => (int) round(72 + $aktuellerHcp * 0.9 + mt_rand(-3, 4)),
                    'fairways' => mt_rand(4, 12), 'gir' => mt_rand(2, 13),
                    'putts' => mt_rand(28, 38), 'penalties' => mt_rand(0, 4),
                    'driving_distance' => mt_rand(175, 245), 'carry' => mt_rand(160, 225),
                    'club_speed' => mt_rand(78, 104), 'ball_speed' => mt_rand(112, 150),
                    'scrambling' => mt_rand(15, 48), 'up_and_down' => mt_rand(20, 55),
                    'platz' => self::CLUBS[array_rand(array_slice(self::CLUBS, 0, 6, true))],
                    'quelle' => mt_rand(0, 1) ? 'trainer' : 'portal',
                    'erstellt' => date('Y-m-d H:i:s', strtotime('-' . ($n * mt_rand(12, 26)) . ' days')),
                ]);
            }
        }
    }

    private static function videos(): void
    {
        $titel = ['Driver von vorn', 'Eisen 7 Ziellinie', 'Pitch 50 Meter',
                  'Putting-Stroke', 'Bunkerschlag', 'Driver nach 6 Wochen'];
        foreach (array_slice(self::$kunden, 0, 6) as $i => $k) {
            $videoId = Tenant::insert('videos', [
                'customer_id' => $k['id'], 'titel' => $titel[$i],
                'datei' => '', 'quelle' => 'upload',
                'ansicht' => $i % 2 === 0 ? 'face-on' : 'down-the-line',
                'schlaeger' => ['Driver', 'Eisen 7', 'Pitching Wedge', 'Putter', 'Sand Wedge', 'Driver'][$i],
                'dauer_sek' => mt_rand(6, 14), 'groesse' => mt_rand(2_000_000, 9_000_000),
                'user_id' => self::$trainer['owner'],
                'erstellt' => date('Y-m-d H:i:s', strtotime('-' . mt_rand(5, 120) . ' days')),
            ]);

            if ($i < 3) {
                Tenant::insert('video_analyses', [
                    'video_id' => $videoId, 'customer_id' => $k['id'],
                    'user_id' => self::$trainer['owner'],
                    'titel' => 'Analyse ' . $titel[$i],
                    'zeichnungen' => Util::json([
                        ['werkzeug' => 'linie', 'von' => [0.42, 0.18], 'bis' => [0.46, 0.88], 'farbe' => '#e0663c'],
                        ['werkzeug' => 'linie', 'von' => [0.28, 0.86], 'bis' => [0.74, 0.86], 'farbe' => '#3b6ef6'],
                        ['werkzeug' => 'kreis', 'mitte' => [0.45, 0.32], 'radius' => 0.07, 'farbe' => '#b08829'],
                    ]),
                    'pro_analyse' => "Die Schulterachse kippt im Rückschwung nach links – dadurch kommst du zu steil "
                        . "an den Ball. Der Divot beginnt vor dem Ball statt danach.\n\n"
                        . "Wir arbeiten an zwei Punkten:\n"
                        . "1. Das Gewicht bleibt bis zum Ende des Rückschwungs mittig.\n"
                        . "2. Der Übergang beginnt aus der Hüfte, nicht aus den Armen.\n\n"
                        . "Übung: Halbe Schwünge mit Füßen zusammen, 20 Bälle. Wenn du dabei das Gleichgewicht "
                        . "hältst, ist die Reihenfolge richtig.",
                    'ai_analyse' => Util::json([
                        ['bereich' => 'Haltung', 'hinweis' => 'Wirbelsäulenwinkel verändert sich zwischen Ansprechen und Treffmoment',
                         'sicherheit' => 'mittel'],
                        ['bereich' => 'Balance', 'hinweis' => 'Gewicht im Treffmoment überwiegend hinten',
                         'sicherheit' => 'hoch'],
                        ['bereich' => 'Tempo', 'hinweis' => 'Verhältnis Rück- zu Durchschwung etwa 2:1 statt 3:1',
                         'sicherheit' => 'niedrig'],
                    ]),
                    'ai_bestaetigt' => $i === 0 ? 1 : 0,
                    'empfehlungen' => 'Rhythmus mit Füßen zusammen, Divot nach dem Ball, Tempo 3:1',
                    'status' => 'freigegeben',
                    'freigegeben' => date('Y-m-d H:i:s', strtotime('-' . mt_rand(4, 100) . ' days')),
                    'erstellt' => date('Y-m-d H:i:s', strtotime('-' . mt_rand(5, 110) . ' days')),
                ]);
            }
        }
    }

    /* --------------------------------------------------------- Kurse -- */

    private static function kurse(): void
    {
        $kursId = Tenant::insert('courses', [
            'titel' => 'Putten von Grund auf', 'slug' => 'putten-von-grund-auf',
            'kurztext' => 'Der Onlinekurs für alle, die auf dem Grün Schläge verschenken.',
            'beschreibung' => "Putten entscheidet über rund 40 Prozent aller Schläge einer Runde – und ist der "
                . "Bereich, in dem sich mit wenig Zeit am meisten verbessern lässt.\n\n"
                . "In sechs Lektionen gehen wir Ansprechposition, Stroke, Distanzkontrolle und Grünlesen durch. "
                . "Zu jeder Lektion gehört eine Übung, die du in zehn Minuten machen kannst.",
            'preis_cent' => 7900, 'niveau' => 'anfaenger', 'zertifikat' => 1,
            'status' => 'veroeffentlicht', 'position' => 0,
            'erstellt' => date('Y-m-d H:i:s', strtotime('-8 months')),
        ]);

        $module = [
            ['Grundlagen', ['Warum Putten über dein Ergebnis entscheidet' => ['video', 6],
                            'Die Ansprechposition' => ['video', 9],
                            'Griff und Schulterachse' => ['video', 7]]],
            ['Der Stroke', ['Pendel statt Handgelenk' => ['video', 11],
                            'Tempo und Rhythmus' => ['video', 8],
                            'Selbsttest: Dein Stroke' => ['aufgabe', 15]]],
            ['Auf dem Grün', ['Distanzkontrolle' => ['video', 12],
                              'Grünlesen ohne Hokuspokus' => ['video', 10],
                              'Abschlussquiz' => ['quiz', 5]]],
        ];
        $position = 0;
        foreach ($module as $mi => [$modulTitel, $lektionen]) {
            $modulId = Tenant::insert('course_modules', [
                'course_id' => $kursId, 'titel' => $modulTitel, 'position' => $mi,
            ]);
            foreach ($lektionen as $titel => [$art, $dauer]) {
                Tenant::insert('course_lessons', [
                    'course_id' => $kursId, 'module_id' => $modulId,
                    'titel' => $titel, 'art' => $art, 'dauer_min' => $dauer,
                    'inhalt' => $art === 'quiz'
                        ? ''
                        : 'In dieser Lektion geht es um ' . mb_strtolower($titel) . '.',
                    'quiz' => $art === 'quiz' ? Util::json([
                        ['frage' => 'Wie viele Putts hat ein Spieler mit Handicap 30 im Schnitt pro Runde?',
                         'optionen' => ['28', '34', '40', '45'], 'richtig' => 1],
                        ['frage' => 'Woher kommt die Bewegung beim Putten hauptsächlich?',
                         'optionen' => ['Aus den Handgelenken', 'Aus den Schultern', 'Aus der Hüfte', 'Aus den Knien'], 'richtig' => 1],
                        ['frage' => 'Worauf achtest du beim Grünlesen zuerst?',
                         'optionen' => ['Auf die Grasfarbe', 'Auf das allgemeine Gefälle', 'Auf die Fahnenposition', 'Auf den Wind'], 'richtig' => 1],
                    ]) : null,
                    'position' => $position++,
                    'vorschau' => $position === 1 ? 1 : 0,
                ]);
            }
        }

        Tenant::insert('courses', [
            'titel' => 'Platzreife-Vorbereitung', 'slug' => 'platzreife-vorbereitung',
            'kurztext' => 'Regeln, Etikette und Ablauf – das Wissen für die Prüfung.',
            'beschreibung' => 'Der theoretische Teil der Platzreife, den du dir bequem zu Hause aneignen kannst. '
                            . 'Auf dem Platz bleibt dann Zeit für das Spielen.',
            'preis_cent' => 0, 'niveau' => 'anfaenger', 'zertifikat' => 1,
            'status' => 'veroeffentlicht', 'position' => 1,
            'erstellt' => date('Y-m-d H:i:s', strtotime('-5 months')),
        ]);

        /* Einschreibungen mit unterschiedlichem Fortschritt */
        foreach (array_slice(self::$kunden, 0, 7) as $i => $k) {
            $fortschritt = [100, 78, 55, 33, 100, 11, 0][$i];
            $eid = Tenant::insert('course_enrollments', [
                'course_id' => $kursId, 'customer_id' => $k['id'],
                'fortschritt' => $fortschritt,
                'begonnen' => $fortschritt > 0 ? date('Y-m-d H:i:s', strtotime('-' . mt_rand(20, 150) . ' days')) : null,
                'abgeschlossen' => $fortschritt >= 100 ? date('Y-m-d H:i:s', strtotime('-' . mt_rand(3, 60) . ' days')) : null,
                'zertifikat_code' => $fortschritt >= 100 ? 'Z-' . Util::code(10) : '',
                'erstellt' => date('Y-m-d H:i:s', strtotime('-' . mt_rand(25, 160) . ' days')),
            ]);
            $lektionen = Tenant::all('course_lessons', 'course_id = :c', ['c' => $kursId], 'position');
            $fertig = (int) round(count($lektionen) * $fortschritt / 100);
            foreach (array_slice($lektionen, 0, $fertig) as $l) {
                Tenant::insert('lesson_progress', [
                    'enrollment_id' => $eid, 'lesson_id' => (int) $l['id'],
                    'abgeschlossen' => date('Y-m-d H:i:s', strtotime('-' . mt_rand(1, 100) . ' days')),
                ]);
            }
        }
    }

    private static function events(): void
    {
        $events = [
            ['Platzreifekurs Frühjahr', 'camp', '+21 days', '+23 days', 12, 34900,
             "Drei Tage kompakt: Technik, Regeln, Etikette und die Prüfung am letzten Nachmittag.\n\n"
             . "Leihschläger, Bälle und Prüfungsgebühr sind enthalten. Mitzubringen sind nur "
             . "bequeme Kleidung und Schuhe mit flacher Sohle."],
            ['Kurzspiel-Workshop', 'workshop', '+9 days', '+9 days', 8, 8900,
             "Ein Vormittag ausschließlich um das Grün herum: Chippen, Pitchen, Bunker. "
             . "Am Ende weißt du für jede Lage, welcher Schläger der richtige ist."],
            ['Golfreise Mallorca', 'reise', '+95 days', '+102 days', 14, 189000,
             "Sieben Tage, vier Plätze, täglich Training am Morgen und Spiel am Nachmittag. "
             . "Flug und Halbpension inklusive, Greenfees enthalten."],
            ['Clubmeisterschaft Vorbereitung', 'workshop', '-30 days', '-30 days', 10, 6900,
             'Platzstrategie und Umgang mit Turnierdruck – direkt vor der Clubmeisterschaft.'],
        ];
        foreach ($events as $i => [$titel, $art, $von, $bis, $kapazitaet, $preis, $text]) {
            $eventId = Tenant::insert('events', [
                'titel' => $titel, 'slug' => Util::slug($titel), 'art' => $art,
                'beschreibung' => $text,
                'start' => date('Y-m-d 09:00:00', strtotime($von)),
                'ende' => date('Y-m-d 17:00:00', strtotime($bis)),
                'location_id' => self::$standorte['club'],
                'ort_text' => $art === 'reise' ? 'Mallorca, Golf Park Puntiró' : '',
                'kapazitaet' => $kapazitaet, 'preis_cent' => $preis,
                'trainer_id' => $i === 1 ? self::$trainer['pro2'] : self::$trainer['owner'],
                'warteliste' => 1,
                'status' => strtotime($bis) < time() ? 'abgeschlossen' : 'veroeffentlicht',
                'erstellt' => date('Y-m-d H:i:s', strtotime('-' . mt_rand(20, 90) . ' days')),
            ]);

            $anmeldungen = min($kapazitaet + 2, mt_rand((int) ($kapazitaet * 0.6), $kapazitaet + 2));
            $gemischt = self::$kunden;
            shuffle($gemischt);
            foreach (array_slice($gemischt, 0, $anmeldungen) as $n => $k) {
                Tenant::insert('event_registrations', [
                    'event_id' => $eventId, 'customer_id' => $k['id'],
                    'status' => $n < $kapazitaet ? 'angemeldet' : 'warteliste',
                    'bezahlt' => $n < $kapazitaet ? 1 : 0,
                    'eingecheckt' => strtotime($bis) < time() && $n < $kapazitaet
                        ? date('Y-m-d H:i:s', strtotime($von)) : null,
                    'erstellt' => date('Y-m-d H:i:s', strtotime('-' . mt_rand(5, 60) . ' days')),
                ]);
            }
        }
    }

    private static function leads(): void
    {
        $anfragen = [
            ['Julia Brenner', 'Platzreife', 'neu', 'Website', 34900, 0,
             'Hallo, ich möchte gern im Frühjahr mit Golf anfangen. Gibt es Kurse für komplette Anfänger?'],
            ['Markus Ohlert', 'Einzeltraining', 'neu', 'Google', 39900, 0,
             'Spiele seit drei Jahren mit HCP 28 und komme nicht weiter. Suche jemanden, der sich das mal ansieht.'],
            ['Familie Kern', 'Juniorentraining', 'kontaktiert', 'Empfehlung', 39000, 2,
             'Unsere Tochter (9) möchte Golf ausprobieren. Gibt es eine Schnupperstunde?'],
            ['Sabine Wolter', 'Videoanalyse', 'qualifiziert', 'Instagram', 11900, 4,
             'Habe Ihr Video zur Schwungbahn gesehen. Wie läuft so eine Analyse ab?'],
            ['Dr. Hendrik Baur', 'Firmenevent', 'angebot', 'Empfehlung', 240000, 6,
             'Wir suchen für unser Sommerfest (25 Personen) ein Golf-Schnupperprogramm.'],
            ['Tim Osterhoff', 'Platzreife', 'gebucht', 'Website', 34900, 9,
             'Kurs ab April passt. Kann ich per Rechnung zahlen?'],
            ['Nina Grasser', 'Einzeltraining', 'kontaktiert', 'Club', 8900, 3,
             'Wurde vom Sekretariat an Sie verwiesen – suche Training für den Einstieg ins Turnierspiel.'],
            ['Peter Lang', 'Gruppentraining', 'verloren', 'Google', 3900, 21,
             'Hat sich für einen Anbieter näher am Wohnort entschieden.'],
            ['Carola Fink', 'Golfreise', 'qualifiziert', 'Newsletter', 189000, 5,
             'Interesse an Mallorca. Ist ein Einzelzimmer möglich?'],
            ['Sven Deichmann', 'Videoanalyse', 'neu', 'Website', 11900, 0,
             'Slice seit Monaten. Ich weiß nicht mehr weiter.'],
        ];
        foreach ($anfragen as $i => [$name, $interesse, $stufe, $quelle, $wert, $tageHer, $nachricht]) {
            Tenant::insert('leads', [
                'name' => $name,
                'email' => strtolower(str_replace([' ', '.', 'Dr. '], ['.', '', ''], self::umlaute($name))) . '@beispiel.de',
                'telefon' => mt_rand(0, 1) ? '01' . mt_rand(50, 79) . ' ' . mt_rand(1000000, 9999999) : '',
                'quelle' => $quelle, 'stufe' => $stufe, 'interesse' => $interesse,
                'wert_cent' => $wert, 'nachricht' => $nachricht,
                'user_id' => self::$trainer['owner'],
                'letzter_kontakt' => $stufe === 'neu' ? null
                    : date('Y-m-d H:i:s', strtotime('-' . max(0, $tageHer - 1) . ' days')),
                'geschlossen' => in_array($stufe, ['kunde', 'verloren'], true)
                    ? date('Y-m-d H:i:s', strtotime('-' . $tageHer . ' days')) : null,
                'erstellt' => date('Y-m-d H:i:s', strtotime('-' . ($tageHer + mt_rand(1, 4)) . ' days')),
            ]);
        }
    }

    /* ------------------------------------------------------- Inhalte -- */

    private static function inhalte(): void
    {
        $kategorieId = Tenant::insert('categories', ['name' => 'Training', 'slug' => 'training', 'art' => 'blog']);
        Tenant::insert('categories', ['name' => 'Ausrüstung', 'slug' => 'ausruestung', 'art' => 'blog', 'position' => 1]);
        Tenant::insert('categories', ['name' => 'Aus der Akademie', 'slug' => 'akademie', 'art' => 'blog', 'position' => 2]);

        $beitraege = [
            ['Die drei häufigsten Fehler beim Putten',
             'Zu schneller Rückschwung, wandernder Kopf, fehlende Routine – und was dagegen hilft.',
             "Putten sieht einfach aus, und genau darin liegt das Problem: Weil die Bewegung klein ist, "
             . "übt sie kaum jemand ernsthaft. Dabei entscheidet sie über fast vierzig Prozent aller Schläge.\n\n"
             . "**Fehler 1: Der Rückschwung ist zu schnell.**\n"
             . "Wer den Putter zurückreißt, muss im Durchschwung abbremsen. Das Ergebnis sind Putts, die "
             . "mal zwei Meter zu kurz und mal drei zu lang sind. Zähl beim Üben laut mit: „eins“ zurück, "
             . "„zwei“ durch. Das klingt albern und wirkt sofort.\n\n"
             . "**Fehler 2: Der Kopf geht mit.**\n"
             . "Der Blick folgt dem Ball, bevor er getroffen ist. Dadurch öffnet sich die Schulterachse und "
             . "der Putt geht nach links. Übung: Nach dem Treffen bis drei zählen, bevor du aufschaust.\n\n"
             . "**Fehler 3: Keine Routine.**\n"
             . "Auf dem Übungsgrün funktioniert alles, auf der Runde nicht. Der Unterschied ist selten die "
             . "Technik, sondern die fehlende gleiche Abfolge. Lege dir eine fest: Linie lesen, zwei Probeschwünge, "
             . "ansprechen, putten. Immer dieselbe, immer in dieser Reihenfolge.\n\n"
             . "Wenn du an einem dieser Punkte arbeiten willst, bring ihn in die nächste Stunde mit – "
             . "eine halbe Stunde reicht meistens für eine sichtbare Veränderung.", 45],
            ['Platzreife: Was dich tatsächlich erwartet',
             'Ablauf, Kosten, Dauer – und was vorher selten jemand sagt.',
             "Die Platzreife ist keine Prüfung im schulischen Sinn, auch wenn der Name danach klingt. "
             . "Sie ist der Nachweis, dass du dich auf einem Golfplatz sicher bewegen kannst, ohne dich "
             . "oder andere zu gefährden.\n\n"
             . "**Der Ablauf**\n"
             . "Bei mir sind es acht Einheiten à 90 Minuten. Die ersten vier auf der Übungsanlage, "
             . "danach geht es auf den Kurzplatz und zum Schluss auf neun Löcher.\n\n"
             . "**Was es kostet**\n"
             . "349 Euro inklusive Leihschläger, Bällen und Prüfungsgebühr. Dazu kommt später die "
             . "Mitgliedschaft oder eine Fernmitgliedschaft, wenn du regelmäßig spielen willst.\n\n"
             . "**Was selten jemand sagt**\n"
             . "Die Platzreife ist der Anfang, nicht das Ziel. Danach beginnt das eigentliche Lernen. "
             . "Wer das weiß, geht entspannter in den Kurs – und hat mehr davon.", 132],
            ['Wintertraining: So verlierst du in der Pause nichts',
             'Vier Übungen für zu Hause, die im Frühjahr den Unterschied machen.',
             "Zwischen November und März verlieren die meisten Golfer mehr, als sie im Sommer aufgebaut "
             . "haben. Das muss nicht sein – und es braucht keine Halle.\n\n"
             . "**1. Beweglichkeit der Brustwirbelsäule**\n"
             . "Im Sitzen, Stock über den Schultern, nach links und rechts drehen. Die Hüfte bleibt ruhig. "
             . "Drei Sätze à zwölf, dreimal pro Woche.\n\n"
             . "**2. Gleichgewicht**\n"
             . "Einbeinstand mit geschlossenen Augen, 30 Sekunden je Seite. Klingt harmlos, ist es nicht.\n\n"
             . "**3. Putten auf Teppich**\n"
             . "Zwei Meter, ein Ziel, zehn Minuten. Nicht auf Länge, sondern auf Richtung.\n\n"
             . "**4. Chippen mit Softbällen**\n"
             . "Ein Handtuch als Landepunkt, zehn Bälle. Der erste Bodenkontakt ist das Ziel, nicht die Fahne.\n\n"
             . "Zwanzig Minuten, dreimal die Woche. Im März merkst du den Unterschied.", 88],
            ['Kurzspiel schlägt Länge: Wo deine Schläge liegen bleiben',
             'Eine einfache Rechnung, die die meisten überrascht.',
             "Fast jeder Amateur wünscht sich mehr Länge vom Abschlag. Die Zahlen sagen etwas anderes.\n\n"
             . "Ein Spieler mit Handicap 24 verliert pro Runde im Schnitt acht bis zehn Schläge innerhalb "
             . "von 50 Metern ums Grün. Vom Abschlag verliert er vielleicht zwei.\n\n"
             . "Zwanzig Meter mehr vom Tee bringen etwa einen halben Schlag. Zehn Putts weniger bringen "
             . "zehn Schläge. Das ist keine Meinung, das ist Arithmetik.\n\n"
             . "Trotzdem verbringen die meisten 80 Prozent ihrer Übungszeit auf der Driving Range mit dem "
             . "Driver. Wenn du in dieser Saison eine Sache änderst: Dreh das Verhältnis um.", 67],
            ['Welcher Schläger passt zu dir?',
             'Ein Leitfaden ohne Verkaufsabsicht – ich verkaufe keine Schläger.',
             "Ich verdiene nichts an Schlägern, deshalb kann ich das offen sagen: Für den Einstieg "
             . "brauchst du kein Fitting und keine neuen Schläger.\n\n"
             . "**Am Anfang**\n"
             . "Ein gebrauchtes Halbset für 200 bis 400 Euro reicht für das erste Jahr vollkommen. Du wirst "
             . "deinen Schwung in dieser Zeit so stark verändern, dass ein Fitting jetzt wenig Sinn ergibt.\n\n"
             . "**Nach ein bis zwei Jahren**\n"
             . "Wenn dein Schwung stabil ist, lohnt sich ein Fitting wirklich. Schaftlänge, Flex und "
             . "Lie-Winkel machen dann einen messbaren Unterschied.\n\n"
             . "**Was sich immer lohnt**\n"
             . "Ein Putter, der zu dir passt, und Schuhe, die halten. Beides kostet zusammen weniger als "
             . "ein einzelnes Eisen und wirkt sich häufiger aus.", 154],
            ['Drei Schläge weniger in einer Saison',
             'Der realistische Weg – und warum die meisten zu viel auf einmal wollen.',
             "Drei Schläge in einer Saison sind ein gutes Ziel. Nicht zehn. Wer zehn will, arbeitet an "
             . "allem gleichzeitig und verbessert nichts.\n\n"
             . "**So funktioniert es**\n"
             . "Wir suchen den einen Bereich, in dem du am meisten verlierst. Bei den meisten ist es das "
             . "Kurzspiel innerhalb von 50 Metern. Dort arbeiten wir acht bis zwölf Wochen konzentriert.\n\n"
             . "Danach der nächste Bereich. Nicht parallel, nacheinander.\n\n"
             . "**Der Trainingsaufwand**\n"
             . "Eine Stunde Unterricht alle zwei Wochen, zwei eigene Einheiten pro Woche à 45 Minuten. "
             . "Das ist realistisch neben Beruf und Familie – und reicht für drei Schläge.", 41],
        ];

        foreach ($beitraege as $i => [$titel, $auszug, $text, $aufrufe]) {
            Tenant::insert('posts', [
                'titel' => $titel, 'slug' => Util::slug($titel), 'auszug' => $auszug,
                'text' => $text, 'category_id' => $kategorieId,
                'tags' => 'Training, Tipps', 'user_id' => self::$trainer['owner'],
                'status' => $i < 5 ? 'veroeffentlicht' : 'entwurf',
                'seo' => Util::json([
                    'titel' => Util::kuerzen($titel . ' · Golf Academy Bergmann', 60),
                    'beschreibung' => Util::kuerzen($auszug, 155),
                ]),
                'veroeffentlicht' => $i < 5 ? date('Y-m-d H:i:s', strtotime('-' . ($i * 23 + 6) . ' days')) : null,
                'aufrufe' => $aufrufe,
                'erstellt' => date('Y-m-d H:i:s', strtotime('-' . ($i * 23 + 8) . ' days')),
            ]);
        }
    }

    private static function website(): void
    {
        $entwurf = KI::websiteEntwurf('Golf Professional mit Schwerpunkt Anfänger und Platzreife, '
            . 'dazu Leistungstraining und Junioren', 'Golf Academy Bergmann');

        /*
         * Der Kundenzugang gehört auf die Startseite der Vorführung, damit
         * man ihn beim Durchklicken sieht. Für eine echte Golfschule setzt
         * ihn der Pro selbst – manche wollen ihn, manche nicht.
         */
        $kontoBlock = self::block('konto', []);
        $vorKontakt = array_search('kontakt', array_column($entwurf['bloecke'], 'typ'), true);
        if ($vorKontakt === false) {
            $entwurf['bloecke'][] = $kontoBlock;
        } else {
            /* Vor „Kontakt & Anfahrt": Wer bis hierher gelesen hat, ist
               überzeugt – dann kommt das Angebot und erst danach die
               Adresse. Hinter der Anfahrt wirkt es angehängt. */
            array_splice($entwurf['bloecke'], (int) $vorKontakt, 0, [$kontoBlock]);
        }

        $startId = Tenant::insert('pages', [
            'titel' => 'Startseite', 'slug' => 'start',
            'bloecke' => Util::json($entwurf['bloecke']),
            'seo' => Util::json([
                'titel' => 'Golftraining in Karlsruhe · Golf Academy Bergmann',
                'beschreibung' => 'Golfunterricht für Einsteiger, Fortgeschrittene und Junioren in Karlsruhe. '
                                . 'Platzreifekurse, Videoanalyse und Trainingspläne. Jetzt Termin buchen.',
            ]),
            'startseite' => 1, 'im_menue' => 0, 'position' => 0,
            'status' => 'veroeffentlicht', 'aufrufe' => 1247, 'conversions' => 38,
            'geaendert' => date('Y-m-d H:i:s', strtotime('-12 days')),
            'erstellt' => date('Y-m-d H:i:s', strtotime('-14 months')),
        ]);

        /* Weitere Seiten */
        $ueberMich = [
            self::block('hero', ['obertitel' => 'Der Trainer', 'ausrichtung' => 'mitte',
                'titel' => 'Daniel Bergmann',
                'text' => 'PGA Golf Professional seit 2008. Seit 2019 mit eigener Akademie in Karlsruhe.',
                'ausrichtung' => 'links', 'hoehe' => 'klein', 'knopf_text' => '', 'knopf2_text' => '']),
            self::block('text', ['titel' => 'Wie ich arbeite', 'text' =>
                "Ich unterrichte seit achtzehn Jahren, und in dieser Zeit habe ich vor allem eines gelernt: "
                . "Der beste Rat nützt nichts, wenn der Spieler ihn nicht umsetzen kann.\n\n"
                . "Deshalb arbeite ich mit wenigen, klaren Punkten statt mit vollständigen Schwunganalysen. "
                . "Wir suchen die eine Sache, die den größten Unterschied macht, und bleiben so lange dabei, "
                . "bis sie sitzt.\n\n"
                . "Zu jeder Stunde bekommst du eine kurze schriftliche Zusammenfassung und eine Übung für "
                . "zu Hause. Wer zwischen den Stunden zwanzig Minuten übt, kommt deutlich schneller voran "
                . "als jemand, der nur zur Stunde erscheint."]),
            self::block('team', ['titel' => 'Das Team', 'automatisch' => true]),
            self::block('zahlen', ['eintraege' => [
                ['wert' => '18', 'label' => 'Jahre Unterricht'],
                ['wert' => '700+', 'label' => 'Betreute Golfer'],
                ['wert' => '340', 'label' => 'Platzreifen'],
                ['wert' => '4,9', 'label' => 'Bewertung'],
            ]]),
            self::block('cta', []),
        ];
        Tenant::insert('pages', [
            'titel' => 'Über mich', 'slug' => 'ueber-mich',
            'bloecke' => Util::json($ueberMich),
            'seo' => Util::json(['titel' => 'Daniel Bergmann – PGA Golf Professional in Karlsruhe',
                'beschreibung' => 'PGA Golf Professional seit 2008, eigene Akademie seit 2019. '
                                . 'Schwerpunkte: Platzreife, Kurzspiel und Leistungstraining.']),
            'im_menue' => 1, 'position' => 1, 'status' => 'veroeffentlicht',
            'aufrufe' => 412, 'geaendert' => date('Y-m-d H:i:s', strtotime('-40 days')),
            'erstellt' => date('Y-m-d H:i:s', strtotime('-14 months')),
        ]);

        $preise = [
            self::block('hero', ['obertitel' => 'Kursangebot & Preise',
                'titel' => 'Was Training *wirklich kostet.*',
                'text' => 'Alle Preise inklusive Mehrwertsteuer. Pakete sind günstiger als Einzelstunden.',
                'ausrichtung' => 'mitte', 'hoehe' => 'klein', 'knopf_text' => '', 'knopf2_text' => '']),
            self::block('leistungen', ['titel' => 'Einzelleistungen', 'automatisch' => true]),
            self::block('produkte', ['titel' => 'Trainingspakete', 'art' => 'paket', 'anzahl' => 4]),
            self::block('faq', ['titel' => 'Fragen zur Bezahlung', 'eintraege' => [
                ['frage' => 'Wie kann ich bezahlen?',
                 'antwort' => 'Online per Kreditkarte, SEPA-Lastschrift, Apple Pay oder Google Pay. Vor Ort auch per Rechnung.'],
                ['frage' => 'Wie lange ist ein Paket gültig?',
                 'antwort' => 'Ein Jahr ab Kaufdatum. Bei längerer Verletzung finden wir eine Lösung – sprich mich einfach an.'],
                ['frage' => 'Kann ich einen Termin absagen?',
                 'antwort' => 'Bis 24 Stunden vorher kostenfrei. Danach wird die Einheit berechnet, weil die Zeit für andere blockiert war.'],
            ]]),
            self::block('cta', []),
        ];
        Tenant::insert('pages', [
            'titel' => 'Preise', 'slug' => 'preise', 'bloecke' => Util::json($preise),
            'seo' => Util::json(['titel' => 'Preise für Golftraining in Karlsruhe',
                'beschreibung' => 'Einzelstunden ab 49 €, Platzreifekurs 349 €, Trainingspakete ab 299 €. '
                                . 'Alle Preise und Leistungen im Überblick.']),
            'im_menue' => 1, 'position' => 2, 'status' => 'veroeffentlicht',
            'aufrufe' => 689, 'conversions' => 21,
            'geaendert' => date('Y-m-d H:i:s', strtotime('-20 days')),
            'erstellt' => date('Y-m-d H:i:s', strtotime('-14 months')),
        ]);

        /* Landingpage mit schwacher Conversion – gibt dem Dashboard eine echte Empfehlung */
        $landing = KI::landingpage('Platzreife-Kurs im Frühjahr für Anfänger');
        Tenant::insert('pages', [
            'titel' => 'Platzreife im Frühjahr', 'slug' => 'platzreife-fruehjahr',
            'bloecke' => Util::json($landing['bloecke']),
            'seo' => Util::json($landing['seo']),
            'im_menue' => 0, 'position' => 8, 'status' => 'veroeffentlicht', 'art' => 'landingpage',
            'aufrufe' => 318, 'conversions' => 2,
            'geaendert' => date('Y-m-d H:i:s', strtotime('-6 days')),
            'erstellt' => date('Y-m-d H:i:s', strtotime('-45 days')),
        ]);

        foreach ([
            ['Impressum', 'impressum', self::rechtstext('impressum')],
            ['Datenschutz', 'datenschutz', self::rechtstext('datenschutz')],
            ['AGB', 'agb', self::rechtstext('agb')],
        ] as $i => [$titel, $slug, $text]) {
            Tenant::insert('pages', [
                'titel' => $titel, 'slug' => $slug,
                'bloecke' => Util::json([self::block('text', ['titel' => $titel, 'text' => $text])]),
                'seo' => Util::json(['titel' => $titel, 'beschreibung' => '', 'index' => false]),
                'im_menue' => 0, 'position' => 90 + $i, 'status' => 'veroeffentlicht',
                'erstellt' => date('Y-m-d H:i:s', strtotime('-14 months')),
            ]);
        }

        Tenant::insert('forms', [
            'name' => 'Kontaktformular',
            'felder' => Util::json([
                ['name' => 'name', 'label' => 'Name', 'typ' => 'text', 'pflicht' => true],
                ['name' => 'email', 'label' => 'E-Mail', 'typ' => 'email', 'pflicht' => true],
                ['name' => 'telefon', 'label' => 'Telefon', 'typ' => 'tel'],
                ['name' => 'interesse', 'label' => 'Worum geht es?', 'typ' => 'auswahl',
                 'optionen' => ['Platzreife', 'Einzeltraining', 'Videoanalyse', 'Juniorentraining',
                                'Gruppentraining', 'Etwas anderes']],
                ['name' => 'nachricht', 'label' => 'Deine Nachricht', 'typ' => 'mehrzeilig'],
            ]),
            'ziel' => 'lead',
            'bestaetigung' => 'Danke für deine Nachricht! Ich melde mich innerhalb von 24 Stunden.',
            'eingaenge' => 47,
        ]);
    }

    private static function block(string $typ, array $daten): array
    {
        $block = Bloecke::neu($typ);
        $block['daten'] = array_merge($block['daten'], $daten);
        return $block;
    }

    private static function rechtstext(string $art): string
    {
        if ($art === 'impressum') {
            return "Angaben gemäß § 5 TMG\n\n"
                 . "Golf Academy Bergmann\nDaniel Bergmann\nLindenhofweg 4\n76135 Karlsruhe\n\n"
                 . "Telefon: 0721 5540120\nE-Mail: info@golf-academy-bergmann.de\n\n"
                 . "Umsatzsteuer-Identifikationsnummer gemäß § 27 a UStG: DE123456789\n\n"
                 . "Verantwortlich für den Inhalt nach § 18 Abs. 2 MStV: Daniel Bergmann, Anschrift wie oben.\n\n"
                 . "Plattform der EU-Kommission zur Online-Streitbeilegung: https://ec.europa.eu/consumers/odr\n"
                 . "Zur Teilnahme an einem Streitbeilegungsverfahren vor einer Verbraucher-"
                 . "schlichtungsstelle sind wir nicht verpflichtet und nicht bereit.\n\n"
                 . "— Dies ist ein Beispieltext der Demo. Vor der Veröffentlichung bitte durch die "
                 . "eigenen Angaben ersetzen und rechtlich prüfen lassen.";
        }
        if ($art === 'agb') {
            return "Allgemeine Geschäftsbedingungen\n\n"
                 . "**1. Geltung**\n"
                 . "Diese Bedingungen gelten für alle Trainingsleistungen der Golf Academy Bergmann.\n\n"
                 . "**2. Buchung und Zahlung**\n"
                 . "Eine Buchung kommt mit unserer Bestätigung zustande. Der Betrag ist vor der Einheit "
                 . "oder nach Rechnung innerhalb von 14 Tagen fällig.\n\n"
                 . "**3. Absage und Verschiebung**\n"
                 . "Termine können bis 24 Stunden vor Beginn kostenfrei abgesagt oder verschoben werden – "
                 . "am einfachsten im Kundenbereich. Danach wird die Einheit berechnet. Bei Absagen "
                 . "unsererseits wird der Termin nachgeholt oder der Betrag erstattet.\n\n"
                 . "**4. Pakete und Gutscheine**\n"
                 . "Trainingspakete sind zwölf Monate ab Kauf gültig. Gutscheine sind drei Jahre ab Ende "
                 . "des Kaufjahres einlösbar und übertragbar, aber nicht in bar auszahlbar.\n\n"
                 . "**5. Witterung**\n"
                 . "Training findet auch bei leichtem Regen statt. Bei Gewitter, Sturm oder gesperrtem "
                 . "Platz wird der Termin ohne Kosten nachgeholt.\n\n"
                 . "**6. Haftung**\n"
                 . "Die Teilnahme erfolgt auf eigene Gefahr. Für Schäden haften wir nur bei Vorsatz und "
                 . "grober Fahrlässigkeit; unberührt bleibt die Haftung für Schäden aus der Verletzung "
                 . "des Lebens, des Körpers oder der Gesundheit.\n\n"
                 . "— Dies ist ein Beispieltext der Demo. Vor der Veröffentlichung bitte anpassen und "
                 . "rechtlich prüfen lassen.";
        }
        return "Datenschutzerklärung\n\n"
             . "Der Schutz deiner Daten ist uns wichtig. Diese Erklärung beschreibt, welche Daten wir "
             . "erheben und wozu.\n\n"
             . "**Verantwortliche Stelle**\nGolf Academy Bergmann, Daniel Bergmann, Lindenhofweg 4, "
             . "76135 Karlsruhe, info@golf-academy-bergmann.de\n\n"
             . "**Welche Daten wir verarbeiten**\n"
             . "Bei einer Anfrage über das Kontaktformular: Name, E-Mail-Adresse, Telefonnummer und deine "
             . "Nachricht. Diese Daten nutzen wir ausschließlich zur Bearbeitung der Anfrage.\n\n"
             . "Bei einer Buchung zusätzlich: Termin, gebuchte Leistung und Zahlungsdaten. Zahlungen "
             . "wickeln wir über Stripe ab; Kartendaten erreichen unsere Server nicht.\n\n"
             . "**Websitezahlen**\n"
             . "Wir zählen Seitenaufrufe ohne Cookies und ohne Speicherung der IP-Adresse. Aus IP-Adresse "
             . "und Browserkennung wird ein täglich wechselnder Prüfwert gebildet, der keine Rückverfolgung "
             . "auf eine Person erlaubt.\n\n"
             . "**Deine Rechte**\n"
             . "Du hast jederzeit das Recht auf Auskunft, Berichtigung, Löschung, Einschränkung der "
             . "Verarbeitung, Datenübertragbarkeit und Widerspruch. Eine kurze Mail genügt.\n\n"
             . "**Aufbewahrung**\n"
             . "Kundendaten löschen wir, sobald der Zweck entfällt. Rechnungen bewahren wir zehn Jahre auf – "
             . "dazu sind wir handelsrechtlich verpflichtet.\n\n"
             . "— Dies ist ein Beispieltext der Demo. Vor der Veröffentlichung bitte anpassen und "
             . "rechtlich prüfen lassen.";
    }

    /* ------------------------------------------------------ Marketing - */

    private static function marketing(): void
    {
        foreach (Automations::vorlagen() as $i => $vorlage) {
            $id = Automations::vorlageAnlegen($vorlage);
            if ($i < 3) {
                Tenant::update('automations', $id, [
                    'status' => 'aktiv',
                    'gestartet' => [34, 21, 12][$i],
                    'abgeschlossen' => [28, 17, 9][$i],
                ]);
            }
        }

        $segmentInaktiv = Tenant::one('segments', 'name = :n', ['n' => 'Inaktiv']);

        Tenant::insert('campaigns', [
            'name' => 'Frühjahrsstart 2026', 'betreff' => 'Die Saison beginnt – hast du einen Plan?',
            'vorschautext' => 'Drei Dinge, die du jetzt angehen solltest.',
            'bloecke' => Util::json([
                ['typ' => 'ueberschrift', 'daten' => ['text' => 'Die Saison beginnt']],
                ['typ' => 'text', 'daten' => ['text' => "Hallo {vorname},\n\n"
                    . "die Plätze öffnen wieder, und mit ihnen stellt sich jedes Jahr dieselbe Frage: "
                    . "Woran arbeite ich in dieser Saison?\n\n"
                    . "Meine Empfehlung: Such dir eine Sache aus. Nicht drei."]],
                ['typ' => 'angebot', 'daten' => ['titel' => 'Golf Starter 5',
                    'text' => 'Fünf Einzelstunden à 60 Minuten – genug Zeit, um eine Veränderung zu festigen.',
                    'preis' => '399 €']],
                ['typ' => 'knopf', 'daten' => ['text' => 'Termin buchen', 'url' => '#']],
            ]),
            'status' => 'versendet',
            'versendet' => date('Y-m-d H:i:s', strtotime('-38 days')),
            'empfaenger' => 19, 'geoeffnet' => 13, 'geklickt' => 6, 'abgemeldet' => 0,
            'erstellt' => date('Y-m-d H:i:s', strtotime('-41 days')),
        ]);

        Tenant::insert('campaigns', [
            'name' => 'Rückholaktion Inaktive', 'betreff' => 'Lange nicht gesehen, {vorname}',
            'vorschautext' => 'Wie läuft es auf dem Platz?',
            'segment_id' => (int) ($segmentInaktiv['id'] ?? 0),
            'bloecke' => Util::json([
                ['typ' => 'text', 'daten' => ['text' => "Hallo {vorname},\n\n"
                    . "unser letztes Training ist eine Weile her. Ich wollte kurz fragen, wie es bei dir läuft.\n\n"
                    . "Falls du wieder einsteigen möchtest: Ich halte diese Woche zwei Termine frei."]],
                ['typ' => 'knopf', 'daten' => ['text' => 'Freie Zeiten ansehen', 'url' => '#']],
            ]),
            'status' => 'entwurf',
            'erstellt' => date('Y-m-d H:i:s', strtotime('-3 days')),
        ]);
    }

    private static function communityDaten(): void
    {
        Gamification::standardAnlegen();
        Community::standardAnlegen();

        $gruppe = Tenant::one('community_groups', 'name = :n', ['n' => 'Allgemein']);
        $gruppeId = (int) ($gruppe['id'] ?? 0);

        $beitraege = [
            ['user', self::$trainer['owner'],
             "Die Range ist ab Montag wieder komplett geöffnet – auch die hinteren Abschlagplätze. "
             . "Wer über den Winter fleißig war, merkt es jetzt. 🏌", 12, 4, 1],
            ['customer', self::$kunden[0]['id'],
             "Erste Runde des Jahres: 94 Schläge. Letztes Jahr um die Zeit waren es 103. "
             . "Das Kurzspieltraining zahlt sich aus.", 18, 6, 0],
            ['customer', self::$kunden[3]['id'],
             "Platzreife geschafft! Danke für die Geduld in den letzten acht Wochen.", 24, 9, 0],
            ['user', self::$trainer['pro2'],
             "Erinnerung: Die Juniorengruppe trifft sich ab April wieder donnerstags um 16 Uhr.", 7, 1, 0],
        ];
        foreach ($beitraege as $i => [$typ, $autorId, $text, $likes, $kommentare, $angeheftet]) {
            $postId = Tenant::insert('community_posts', [
                'group_id' => $gruppeId, 'autor_typ' => $typ, 'autor_id' => $autorId,
                'text' => $text, 'likes' => $likes, 'kommentare' => $kommentare,
                'angeheftet' => $angeheftet,
                'erstellt' => date('Y-m-d H:i:s', strtotime('-' . ($i * 4 + 2) . ' days')),
            ]);
            if ($kommentare > 0) {
                Tenant::insert('community_comments', [
                    'post_id' => $postId, 'autor_typ' => 'user', 'autor_id' => self::$trainer['owner'],
                    'text' => ['Sehr gut! Weiter so.', 'Glückwunsch – verdient!',
                               'Freut mich zu hören.', 'Bis Donnerstag!'][$i],
                    'erstellt' => date('Y-m-d H:i:s', strtotime('-' . ($i * 4 + 1) . ' days')),
                ]);
            }
        }

        /* Punktestände und Abzeichen */
        foreach (self::$kunden as $i => $k) {
            $xp = [3 => mt_rand(400, 900), 2 => mt_rand(150, 450), 1 => mt_rand(50, 180), 0 => mt_rand(0, 80)][$k['aktivitaet']];
            Tenant::insert('customer_gamification', [
                'customer_id' => $k['id'], 'xp' => $xp,
                'level' => max(1, (int) floor($xp / 250) + 1),
                'streak' => $k['aktivitaet'] === 3 ? mt_rand(3, 14) : mt_rand(0, 4),
                'bester_streak' => $k['aktivitaet'] >= 2 ? mt_rand(7, 32) : mt_rand(1, 6),
                'letzte_aktivitaet' => date('Y-m-d', strtotime('-' . mt_rand(0, 20) . ' days')),
            ]);
        }
        foreach (Tenant::all('badges') as $b) {
            foreach (array_slice(self::$kunden, 0, mt_rand(3, 9)) as $k) {
                Tenant::insert('customer_badges', [
                    'customer_id' => $k['id'], 'badge_id' => (int) $b['id'],
                    'erhalten' => date('Y-m-d H:i:s', strtotime('-' . mt_rand(5, 300) . ' days')),
                ]);
            }
        }
        foreach (Tenant::all('challenges') as $c) {
            foreach (array_slice(self::$kunden, 0, mt_rand(4, 11)) as $k) {
                Tenant::insert('challenge_participants', [
                    'challenge_id' => (int) $c['id'], 'customer_id' => $k['id'],
                    'fortschritt' => mt_rand(0, (int) $c['ziel_wert']),
                    'erstellt' => date('Y-m-d H:i:s', strtotime('-' . mt_rand(1, 25) . ' days')),
                ]);
            }
        }
    }

    private static function websitezahlen(): void
    {
        $seiten = [
            ['/', 'Startseite', 42],
            ['/preise', 'Preise', 19],
            ['/ueber-mich', 'Über mich', 12],
            ['/platzreife-fruehjahr', 'Platzreife im Frühjahr', 11],
            ['/blog/die-drei-haeufigsten-fehler-beim-putten', 'Die drei häufigsten Fehler beim Putten', 9],
            ['/blog/platzreife-was-dich-tatsaechlich-erwartet', 'Platzreife: Was dich erwartet', 7],
        ];
        $quellen = ['Google' => 46, 'direkt' => 24, 'Instagram' => 12, 'Facebook' => 8,
                    'Empfehlung' => 6, 'Bing' => 4];
        $geraete = ['mobil' => 58, 'desktop' => 34, 'tablet' => 8];

        for ($tag = 89; $tag >= 0; $tag--) {
            $datum = date('Y-m-d', strtotime('-' . $tag . ' days'));
            $wochentag = (int) date('w', strtotime($datum));
            // Wochenende und Frühjahr bringen mehr Besucher – das macht die Kurve glaubhaft.
            $basis = in_array($wochentag, [0, 6], true) ? mt_rand(14, 26) : mt_rand(8, 19);
            $saison = 1 + (89 - $tag) / 260;
            $anzahl = (int) round($basis * $saison);

            for ($n = 0; $n < $anzahl; $n++) {
                $seite = self::gewichtetWaehlen(array_column($seiten, 2));
                Tenant::insert('web_visits', [
                    'pfad' => $seiten[$seite][0], 'titel' => $seiten[$seite][1],
                    'quelle' => self::gewichtetSchluessel($quellen),
                    'geraet' => self::gewichtetSchluessel($geraete),
                    'besucher' => substr(md5($datum . $n . mt_rand()), 0, 32),
                    'datum' => $datum,
                    'erstellt' => $datum . sprintf(' %02d:%02d:00', mt_rand(7, 22), mt_rand(0, 59)),
                ]);
            }
        }
    }

    private static function gewichtetWaehlen(array $gewichte): int
    {
        $summe = array_sum($gewichte);
        $wurf = mt_rand(1, max(1, $summe));
        $bisher = 0;
        foreach ($gewichte as $i => $g) {
            $bisher += $g;
            if ($wurf <= $bisher) {
                return $i;
            }
        }
        return 0;
    }

    private static function gewichtetSchluessel(array $karte): string
    {
        $schluessel = array_keys($karte);
        return $schluessel[self::gewichtetWaehlen(array_values($karte))];
    }

    private static function aufgabenUndMeldungen(): void
    {
        $aufgaben = [
            ['Angebot für Firmenevent (25 Personen) schicken', '+1 day', 'hoch'],
            ['Rechnung R-2026-0041 nachfassen', '+2 days', 'normal'],
            ['Platzreifekurs Frühjahr: Material bestellen', '+5 days', 'normal'],
            ['Neue Videos für Instagram schneiden', '+7 days', 'niedrig'],
            ['Jahresgespräch mit Lena', '+12 days', 'normal'],
        ];
        foreach ($aufgaben as [$titel, $faellig, $prio]) {
            Tenant::insert('tasks', [
                'titel' => $titel, 'faellig' => date('Y-m-d H:i:s', strtotime($faellig)),
                'user_id' => self::$trainer['owner'], 'prioritaet' => $prio,
                'erstellt' => date('Y-m-d H:i:s', strtotime('-' . mt_rand(1, 10) . ' days')),
            ]);
        }
        Tenant::insert('tasks', [
            'titel' => 'Website-Texte für die Preisseite überarbeiten',
            'faellig' => date('Y-m-d H:i:s', strtotime('-3 days')),
            'user_id' => self::$trainer['owner'], 'prioritaet' => 'normal',
            'erledigt' => date('Y-m-d H:i:s', strtotime('-2 days')),
            'erstellt' => date('Y-m-d H:i:s', strtotime('-9 days')),
        ]);

        $meldungen = [
            ['booking', 'Neue Buchung online', 'Sandra Wagner · Videoanalyse 60 Minuten', '/app/buchungen.php', 2],
            ['payment', 'Zahlung eingegangen: 399,00 €', 'Thomas Berger · Golf Starter 5', '/app/zahlungen.php', 6],
            ['customer', 'Neue Anfrage: Sven Deichmann', 'Videoanalyse · über Website', '/app/leads.php', 9],
            ['ai', '5 Kunden ohne Buchung seit 60 Tagen', 'Empfehlung aus der Auswertung', '/app/kunden.php?filter=inaktiv', 20],
            ['marketing', 'Newsletter „Frühjahrsstart 2026“ versendet', '19 Empfänger · 68 % geöffnet', '/app/newsletter.php', 38 * 24],
            ['booking', 'Warteliste: Platz frei geworden', 'Kurzspiel-Workshop', '/app/events.php', 30],
        ];
        foreach ($meldungen as $i => [$kategorie, $titel, $text, $link, $stundenHer]) {
            Tenant::insert('notifications', [
                'user_id' => 0, 'kategorie' => $kategorie, 'titel' => $titel,
                'text' => $text, 'link' => $link,
                'gelesen' => $i >= 3 ? date('Y-m-d H:i:s', strtotime('-' . ($stundenHer - 1) . ' hours')) : null,
                'erstellt' => date('Y-m-d H:i:s', strtotime('-' . $stundenHer . ' hours')),
            ]);
        }

        foreach (self::$kunden as $i => $k) {
            Customers::scoreSpeichern($k['id']);
        }
    }
}
