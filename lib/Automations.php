<?php
/**
 * Automations – Abläufe aus Auslöser, Bedingung, Wartezeit und Aktion.
 *
 * Eine Automation ist eine Kette von Schritten. Trifft ein Auslöser ein,
 * entsteht ein Lauf; der Lauf merkt sich, bei welchem Schritt er steht und
 * wann er weitergehen darf. Fällige Läufe werden beim Seitenaufruf
 * abgearbeitet – so funktioniert das auch auf Hosting ohne Cronjob.
 *
 * **Nichts wird ohne Zustimmung versendet.** Eine Automation im Entwurf
 * läuft nicht, und E-Mail-Schritte prüfen die Newsletter-Einwilligung,
 * bevor sie senden. Werbung an Menschen, die nicht zugestimmt haben, ist
 * in Europa nicht nur unhöflich, sondern rechtswidrig.
 */
final class Automations
{
    public const AUSLOESER = [
        'customer_created' => ['Kunde wurde angelegt', 'user-plus'],
        'lead_created'     => ['Anfrage ist eingegangen', 'leads'],
        'booking_created'  => ['Termin wurde gebucht', 'calendar'],
        'booking_done'     => ['Termin war erfolgreich', 'check'],
        'purchase'         => ['Kauf abgeschlossen', 'products'],
        'package_low'      => ['Paket fast aufgebraucht', 'ticket'],
        'package_expiring' => ['Paket läuft bald ab', 'clock'],
        'inactive_60'      => ['60 Tage ohne Termin', 'alert'],
        'birthday'         => ['Geburtstag', 'gift'],
        'course_completed'  => ['Kurs abgeschlossen', 'courses'],
    ];

    public const SCHRITTE = [
        'email'     => ['E-Mail senden', 'mail'],
        'warten'    => ['Warten', 'clock'],
        'tag'       => ['Schlagwort setzen', 'list'],
        'aufgabe'   => ['Aufgabe für mich anlegen', 'check'],
        'angebot'   => ['Angebot vorschlagen', 'gift'],
        'bedingung' => ['Nur wenn…', 'split'],
        'sms'       => ['SMS senden', 'phone-device'],
        'segment'   => ['Segment prüfen', 'filter'],
    ];

    /* ------------------------------------------------------- Definition */

    public static function schritte(int $automationId): array
    {
        return Tenant::all('automation_steps', 'automation_id = :a', ['a' => $automationId], 'position, id');
    }

    public static function schrittHinzufuegen(int $automationId, string $art, array $config): int
    {
        $position = Tenant::count('automation_steps', 'automation_id = :a', ['a' => $automationId]);
        return Tenant::insert('automation_steps', [
            'automation_id' => $automationId,
            'position'      => $position,
            'art'           => $art,
            'config'        => Util::json($config),
        ]);
    }

    /* --------------------------------------------------------- Auslösen */

    /** Startet alle aktiven Automationen mit diesem Auslöser. */
    public static function ausloesen(string $ausloeser, array $kontext): int
    {
        $gestartet = 0;
        foreach (Tenant::all('automations', 'ausloeser = :a AND status = "aktiv"', ['a' => $ausloeser]) as $auto) {
            $kundeId = (int) ($kontext['customer_id'] ?? 0);
            $leadId  = (int) ($kontext['lead_id'] ?? 0);

            // Niemanden zweimal in denselben laufenden Ablauf stecken.
            if ($kundeId > 0 && Tenant::count('automation_runs',
                'automation_id = :a AND customer_id = :k AND status = "laufend"',
                ['a' => (int) $auto['id'], 'k' => $kundeId]) > 0) {
                continue;
            }
            Tenant::insert('automation_runs', [
                'automation_id'  => (int) $auto['id'],
                'customer_id'    => $kundeId,
                'lead_id'        => $leadId,
                'schritt'        => 0,
                'status'         => 'laufend',
                'naechster_lauf' => Util::jetzt(),
            ]);
            Tenant::update('automations', (int) $auto['id'], ['gestartet' => (int) $auto['gestartet'] + 1]);
            $gestartet++;
        }
        return $gestartet;
    }

    /**
     * Arbeitet fällige Läufe ab. Läuft beim Öffnen des Dashboards mit und
     * verarbeitet höchstens eine Handvoll pro Aufruf – eine Seite darf nicht
     * langsam werden, weil jemand tausend Kunden in einen Ablauf gesteckt hat.
     */
    public static function laufen(int $maximal = 15): int
    {
        $erledigt = 0;
        $faellig = Tenant::all('automation_runs',
            'status = "laufend" AND (naechster_lauf IS NULL OR naechster_lauf <= :jetzt)',
            ['jetzt' => Util::jetzt()], 'naechster_lauf', $maximal);

        foreach ($faellig as $lauf) {
            $schritte = self::schritte((int) $lauf['automation_id']);
            $index = (int) $lauf['schritt'];

            if (!isset($schritte[$index])) {
                Tenant::update('automation_runs', (int) $lauf['id'], ['status' => 'abgeschlossen']);
                $auto = Tenant::find('automations', (int) $lauf['automation_id']);
                if ($auto) {
                    Tenant::update('automations', (int) $auto['id'],
                        ['abgeschlossen' => (int) $auto['abgeschlossen'] + 1]);
                }
                continue;
            }

            $schritt = $schritte[$index];
            $config  = Util::ausJson((string) $schritt['config']);
            $weiter  = self::schrittAusfuehren((string) $schritt['art'], $config, $lauf);

            if ($weiter === null) {
                Tenant::update('automation_runs', (int) $lauf['id'], ['status' => 'abgebrochen']);
                continue;
            }
            Tenant::update('automation_runs', (int) $lauf['id'], [
                'schritt'        => $index + 1,
                'naechster_lauf' => $weiter,
            ]);
            $erledigt++;
        }
        return $erledigt;
    }

    /** @return string|null nächster Zeitpunkt, oder null für Abbruch */
    private static function schrittAusfuehren(string $art, array $config, array $lauf): ?string
    {
        $kunde = (int) $lauf['customer_id'] > 0 ? Tenant::find('customers', (int) $lauf['customer_id']) : null;

        switch ($art) {
            case 'warten':
                $tage = (int) ($config['tage'] ?? 1);
                $stunden = (int) ($config['stunden'] ?? 0);
                return date('Y-m-d H:i:s', time() + $tage * 86400 + $stunden * 3600);

            case 'email':
                if (!$kunde || (string) $kunde['email'] === '') {
                    return Util::jetzt();
                }
                // Werbliche Schritte nur mit Einwilligung.
                if (!empty($config['werblich']) && (int) $kunde['newsletter'] !== 1) {
                    return Util::jetzt();
                }
                Mail::anKunden($kunde,
                    self::platzhalter((string) ($config['betreff'] ?? ''), $kunde),
                    self::platzhalter((string) ($config['text'] ?? ''), $kunde),
                    ['knopf_text' => (string) ($config['knopf_text'] ?? ''),
                     'knopf_url'  => (string) ($config['knopf_url'] ?? '')]);
                return Util::jetzt();

            case 'sms':
                // Ohne eingerichteten SMS-Anbieter wird nur protokolliert.
                if ($kunde) {
                    Tenant::insert('communications', [
                        'customer_id' => (int) $kunde['id'], 'kanal' => 'sms', 'richtung' => 'aus',
                        'betreff' => 'SMS', 'text' => self::platzhalter((string) ($config['text'] ?? ''), $kunde),
                        'status' => Tenant::einstellung('sms_anbieter', '') ? 'gesendet' : 'nicht_eingerichtet',
                    ]);
                }
                return Util::jetzt();

            case 'tag':
                if ($kunde) {
                    $tags = Customers::tags($kunde);
                    $tags[] = (string) ($config['tag'] ?? '');
                    Customers::tagsSetzen((int) $kunde['id'], $tags);
                }
                return Util::jetzt();

            case 'aufgabe':
                Tenant::insert('tasks', [
                    'titel'       => self::platzhalter((string) ($config['titel'] ?? 'Aufgabe'), $kunde),
                    'customer_id' => (int) ($lauf['customer_id'] ?? 0),
                    'lead_id'     => (int) ($lauf['lead_id'] ?? 0),
                    'faellig'     => date('Y-m-d H:i:s', time() + (int) ($config['tage'] ?? 0) * 86400),
                    'quelle'      => 'automation',
                ]);
                return Util::jetzt();

            case 'angebot':
                if ($kunde) {
                    Notify::senden('marketing', 'Angebot vorschlagen: ' . Customers::name($kunde),
                        (string) ($config['text'] ?? 'Der Ablauf schlägt ein Angebot vor.'),
                        '/app/kunde.php?id=' . (int) $kunde['id']);
                }
                return Util::jetzt();

            case 'bedingung':
            case 'segment':
                $segmentId = (int) ($config['segment_id'] ?? 0);
                if ($segmentId > 0 && $kunde) {
                    $segment = Tenant::find('segments', $segmentId);
                    if ($segment) {
                        [$wo, $p] = Segments::bedingung(Util::ausJson((string) $segment['regeln']));
                        $drin = $wo === '' || Tenant::count('customers',
                            '(' . $wo . ') AND id = :self', $p + ['self' => (int) $kunde['id']]) > 0;
                        if (!$drin) {
                            return null;     // Bedingung nicht erfüllt: Ablauf endet
                        }
                    }
                }
                return Util::jetzt();
        }
        return Util::jetzt();
    }

    public static function platzhalter(string $text, ?array $kunde): string
    {
        $ersatz = [
            '{vorname}'   => (string) ($kunde['vorname'] ?? ''),
            '{nachname}'  => (string) ($kunde['nachname'] ?? ''),
            '{name}'      => $kunde ? Customers::name($kunde) : '',
            '{hcp}'       => (string) ($kunde['hcp'] ?? ''),
            '{pro}'       => Tenant::name(),
            '{heimclub}'  => (string) ($kunde['heimclub'] ?? ''),
        ];
        return strtr($text, $ersatz);
    }

    /**
     * Zeitgesteuerte Auslöser, die kein Ereignis haben: Geburtstag,
     * auslaufende Pakete, Inaktivität. Wird einmal täglich geprüft.
     */
    public static function zeitgesteuertPruefen(): int
    {
        $letzterLauf = (string) Tenant::einstellung('automation_tageslauf', '');
        if ($letzterLauf === Util::heute()) {
            return 0;
        }
        Tenant::einstellungSetzen('automation_tageslauf', Util::heute());
        $gestartet = 0;

        foreach (Tenant::all('customers',
            'status = "aktiv" AND geburtstag != "" AND substr(geburtstag, 6, 5) = :heute',
            ['heute' => date('m-d')]) as $k) {
            $gestartet += self::ausloesen('birthday', ['customer_id' => (int) $k['id']]);
        }

        foreach (Tenant::all('customer_packages',
            'status = "aktiv" AND einheiten_gesamt - einheiten_genutzt <= 1 AND einheiten_genutzt > 0') as $p) {
            $gestartet += self::ausloesen('package_low', ['customer_id' => (int) $p['customer_id']]);
        }

        $grenze = date('Y-m-d H:i:s', strtotime('+14 days'));
        foreach (Tenant::all('customer_packages',
            'status = "aktiv" AND laeuft_ab <= :g AND laeuft_ab > :jetzt AND einheiten_genutzt < einheiten_gesamt',
            ['g' => $grenze, 'jetzt' => Util::jetzt()]) as $p) {
            $gestartet += self::ausloesen('package_expiring', ['customer_id' => (int) $p['customer_id']]);
        }

        $segment = ['verknuepfung' => 'und', 'bedingungen' => [
            ['feld' => 'tage_seit_termin', 'op' => '>=', 'wert' => '60'],
            ['feld' => 'tage_seit_termin', 'op' => '<', 'wert' => '67'],
            ['feld' => 'status', 'op' => '=', 'wert' => 'aktiv'],
        ]];
        [$wo, $p] = Segments::bedingung($segment);
        if ($wo !== '') {
            foreach (Tenant::all('customers', $wo, $p, '', 30) as $k) {
                $gestartet += self::ausloesen('inactive_60', ['customer_id' => (int) $k['id']]);
            }
        }
        return $gestartet;
    }

    /** Vorlagen, die beim Einrichten als Vorschlag angeboten werden. */
    public static function vorlagen(): array
    {
        return [
            [
                'name' => 'Willkommen für neue Kunden',
                'beschreibung' => 'Begrüßung, Trainingsimpuls nach drei Tagen, Paketangebot nach zwei Wochen.',
                'ausloeser' => 'customer_created',
                'schritte' => [
                    ['email', ['betreff' => 'Willkommen bei {pro}, {vorname}!',
                               'text' => "Hallo {vorname},\n\nschön, dass du da bist. Ich freue mich auf unser gemeinsames Training.\n\nWenn du magst, schreib mir kurz, woran du in den nächsten Wochen am liebsten arbeiten möchtest – dann stelle ich das Training darauf ein."]],
                    ['warten', ['tage' => 3]],
                    ['email', ['betreff' => 'Eine Übung für zwischendurch',
                               'text' => "Hallo {vorname},\n\nhier eine Übung, die zu Hause funktioniert und in fünf Minuten erledigt ist: der Uhrzeigerdrill für kurze Putts.\n\nLeg dir zwölf Bälle im Kreis um ein Ziel, etwa einen Meter Abstand. Loche alle nacheinander. Verfehlst du einen, fängst du von vorn an."]],
                    ['warten', ['tage' => 11]],
                    ['angebot', ['text' => 'Kunde ist zwei Wochen dabei – Zeitpunkt für ein Paketangebot.']],
                ],
            ],
            [
                'name' => 'Anfrage nachfassen',
                'beschreibung' => 'Antwort sofort, Erinnerung nach zwei Tagen, Aufgabe nach fünf.',
                'ausloeser' => 'lead_created',
                'schritte' => [
                    ['email', ['betreff' => 'Danke für deine Anfrage',
                               'text' => "Hallo,\n\ndanke für dein Interesse! Ich melde mich innerhalb von 24 Stunden persönlich bei dir.\n\nFalls es eilig ist, ruf mich gern direkt an."]],
                    ['warten', ['tage' => 2]],
                    ['aufgabe', ['titel' => 'Anfrage nachfassen: {name}', 'tage' => 0]],
                ],
            ],
            [
                'name' => 'Paket läuft aus',
                'beschreibung' => 'Hinweis, wenn das Guthaben zur Neige geht.',
                'ausloeser' => 'package_low',
                'schritte' => [
                    ['email', ['werblich' => true, 'betreff' => 'Deine letzte Einheit steht an',
                               'text' => "Hallo {vorname},\n\ndein Trainingspaket ist fast aufgebraucht – eine Einheit ist noch offen.\n\nWenn du weitermachen möchtest, sag einfach Bescheid. Ich halte dir deinen gewohnten Termin frei."]],
                ],
            ],
            [
                'name' => 'Lange nicht gesehen',
                'beschreibung' => 'Freundlicher Anstoß nach 60 Tagen ohne Termin.',
                'ausloeser' => 'inactive_60',
                'schritte' => [
                    ['email', ['betreff' => 'Lange nicht gesehen, {vorname}',
                               'text' => "Hallo {vorname},\n\nunser letztes Training ist eine Weile her. Wie läuft es auf dem Platz?\n\nWenn du wieder einsteigen möchtest, finde ich sicher einen Termin, der passt."]],
                    ['warten', ['tage' => 7]],
                    ['aufgabe', ['titel' => 'Persönlich anrufen: {name}', 'tage' => 0]],
                ],
            ],
            [
                'name' => 'Geburtstagsgruß',
                'beschreibung' => 'Einmal im Jahr, ohne Verkaufsabsicht.',
                'ausloeser' => 'birthday',
                'schritte' => [
                    ['email', ['betreff' => 'Alles Gute, {vorname}!',
                               'text' => "Hallo {vorname},\n\nalles Gute zum Geburtstag und ein schönes Golfjahr!\n\nHerzliche Grüße"]],
                ],
            ],
        ];
    }

    public static function vorlageAnlegen(array $vorlage): int
    {
        $id = Tenant::insert('automations', [
            'name'         => (string) $vorlage['name'],
            'beschreibung' => (string) $vorlage['beschreibung'],
            'ausloeser'    => (string) $vorlage['ausloeser'],
            'status'       => 'entwurf',
        ]);
        foreach ($vorlage['schritte'] as [$art, $config]) {
            self::schrittHinzufuegen($id, $art, $config);
        }
        return $id;
    }
}
