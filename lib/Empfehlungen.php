<?php
/**
 * Empfehlungen – „Was sollte ich heute tun?“
 *
 * Das Herzstück des Dashboards. Jede Empfehlung nennt **eine Zahl aus den
 * eigenen Daten**, **einen Grund** und **eine Handlung mit Ziel**. Ohne
 * alle drei ist es keine Empfehlung, sondern eine Meinung – und die hilft
 * niemandem beim Arbeiten.
 *
 * Die Regeln laufen auf der Datenbank, nicht über ein Sprachmodell. Das ist
 * schneller, kostet nichts, funktioniert offline und ist nachvollziehbar:
 * Der Pro kann jede Zahl anklicken und die dahinterliegende Liste ansehen.
 * Der KI-Assistent formuliert daraus auf Wunsch Texte – die Entscheidung,
 * *was* wichtig ist, trifft er nicht.
 */
final class Empfehlungen
{
    /**
     * @return array<int,array{
     *   titel:string, grund:string, icon:string, farbe:string,
     *   knopf:string, url:string, gewicht:int
     * }>
     */
    public static function fuerDashboard(int $limit = 6): array
    {
        $liste = array_merge(
            self::inaktiveKunden(),
            self::auslaufendePakete(),
            self::unbeantworteteLeads(),
            self::offeneRechnungen(),
            self::hoheAuslastung(),
            self::schwacheLandingpage(),
            self::geburtstage(),
            self::wartelisten(),
            self::leereWoche(),
            self::einrichtungLuecken(),
            self::newsletterFaellig(),
            self::paketeOhneTermin()
        );

        usort($liste, static fn($a, $b) => $b['gewicht'] <=> $a['gewicht']);
        return array_slice($liste, 0, $limit);
    }

    /* ------------------------------------------------------- die Regeln */

    private static function inaktiveKunden(): array
    {
        [$wo, $p] = Segments::bedingung(['bedingungen' => [
            ['feld' => 'tage_seit_termin', 'op' => '>', 'wert' => '60'],
            ['feld' => 'status', 'op' => '=', 'wert' => 'aktiv'],
        ]]);
        if ($wo === '') {
            return [];
        }
        $anzahl = Tenant::count('customers', $wo, $p);
        if ($anzahl === 0) {
            return [];
        }
        return [[
            'titel'   => $anzahl . ' ' . ($anzahl === 1 ? 'Kunde hat' : 'Kunden haben') . ' seit über 60 Tagen nicht gebucht',
            'grund'   => 'Ein kurzer persönlicher Anstoß bringt erfahrungsgemäß jeden Vierten zurück – günstiger als jede Neukundengewinnung.',
            'icon'    => 'customers', 'farbe' => 'warnung',
            'knopf'   => 'Liste ansehen', 'url' => '/app/kunden.php?filter=inaktiv',
            'gewicht' => 80 + min(20, $anzahl),
        ]];
    }

    private static function auslaufendePakete(): array
    {
        $anzahl = Tenant::count('customer_packages',
            "status = 'aktiv' AND einheiten_genutzt < einheiten_gesamt AND laeuft_ab <= :g AND laeuft_ab > :jetzt",
            ['g' => date('Y-m-d H:i:s', strtotime('+30 days')), 'jetzt' => Util::jetzt()]);
        if ($anzahl === 0) {
            return [];
        }
        return [[
            'titel'   => $anzahl . ' Trainingspaket' . ($anzahl === 1 ? ' läuft' : 'e laufen') . ' in den nächsten 30 Tagen ab',
            'grund'   => 'Offene Einheiten, die verfallen, ärgern den Kunden und kosten dich die Folgebuchung.',
            'icon'    => 'ticket', 'farbe' => 'akzent',
            'knopf'   => 'Pakete ansehen', 'url' => '/app/pakete.php?filter=laeuft_ab',
            'gewicht' => 85 + min(15, $anzahl * 3),
        ]];
    }

    private static function unbeantworteteLeads(): array
    {
        $offen = Leads::unbeantwortet(24);
        $anzahl = count($offen);
        if ($anzahl === 0) {
            return [];
        }
        $aeltester = $offen[0] ?? null;
        $tage = $aeltester ? Util::tageSeit((string) $aeltester['erstellt']) : 1;
        return [[
            'titel'   => $anzahl . ' Anfrage' . ($anzahl === 1 ? '' : 'n') . ' warten auf Antwort',
            'grund'   => 'Die älteste liegt seit ' . max(1, $tage) . ' Tag' . ($tage === 1 ? '' : 'en')
                       . ' da. Wer innerhalb eines Tages antwortet, gewinnt deutlich häufiger den Auftrag.',
            'icon'    => 'leads', 'farbe' => 'gefahr',
            'knopf'   => 'Pipeline öffnen', 'url' => '/app/leads.php',
            'gewicht' => 95 + min(10, $anzahl * 2),
        ]];
    }

    private static function offeneRechnungen(): array
    {
        $ueberfaellig = Tenant::count('invoices', "status = 'ueberfaellig'");
        if ($ueberfaellig === 0) {
            return [];
        }
        $betrag = Tenant::sum('invoices', 'summe_cent - bezahlt_cent', 'status = "ueberfaellig"');
        return [[
            'titel'   => $ueberfaellig . ' überfällige Rechnung' . ($ueberfaellig === 1 ? '' : 'en')
                       . ' über ' . Util::geld($betrag),
            'grund'   => 'Eine freundliche Erinnerung nach der Fälligkeit klärt die meisten Fälle sofort.',
            'icon'    => 'invoices', 'farbe' => 'gefahr',
            'knopf'   => 'Rechnungen öffnen', 'url' => '/app/rechnungen.php?status=ueberfaellig',
            'gewicht' => 90 + min(10, $ueberfaellig * 2),
        ]];
    }

    private static function hoheAuslastung(): array
    {
        $raster = Bookings::auslastungsRaster(90);
        $beste = null;
        foreach ($raster as $wochentag => $bloecke) {
            foreach ($bloecke as $block => $zahlen) {
                $kapazitaet = (int) ($zahlen['kapazitaet'] ?? 0);
                $gebucht    = (int) ($zahlen['gebucht'] ?? 0);
                if ($kapazitaet < 6) {
                    continue;
                }
                $quote = $gebucht / $kapazitaet * 100;
                if ($quote >= 80 && ($beste === null || $quote > $beste['quote'])) {
                    $beste = ['wochentag' => $wochentag, 'block' => $block, 'quote' => $quote];
                }
            }
        }
        if ($beste === null) {
            return [];
        }
        $tage = ['Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag'];
        return [[
            'titel'   => $tage[$beste['wochentag']] . ' ' . (Bookings::BLOECKE[$beste['block']] ?? '')
                       . ' ist zu ' . round($beste['quote']) . ' % ausgelastet',
            'grund'   => 'Bei dieser Nachfrage ist ein höherer Preis für diese Zeit vertretbar – oder ein zusätzlicher Slot.',
            'icon'    => 'trend-up', 'farbe' => 'info',
            'knopf'   => 'Preise ansehen', 'url' => '/app/leistungen.php',
            'gewicht' => 55,
        ]];
    }

    private static function schwacheLandingpage(): array
    {
        foreach (Pages::alle('landingpage') as $seite) {
            $quote = Pages::conversion($seite);
            if ($quote !== null && $quote < 1.0 && (int) $seite['aufrufe'] >= 50) {
                return [[
                    'titel'   => '„' . Util::kuerzen((string) $seite['titel'], 40) . '“ wandelt nur '
                               . Util::prozent($quote, 1) . ' der Besucher um',
                    'grund'   => (int) $seite['aufrufe'] . ' Aufrufe, aber kaum Anfragen. Meist fehlt ein klarer '
                               . 'Handlungsaufruf über der Falz oder das Formular ist zu lang.',
                    'icon'    => 'target', 'farbe' => 'warnung',
                    'knopf'   => 'Seite bearbeiten', 'url' => '/app/seite.php?id=' . (int) $seite['id'],
                    'gewicht' => 65,
                ]];
            }
        }
        return [];
    }

    private static function geburtstage(): array
    {
        $naechste = [];
        for ($i = 0; $i < 7; $i++) {
            $naechste[] = date('m-d', strtotime('+' . $i . ' days'));
        }
        $platzhalter = [];
        $p = [];
        foreach ($naechste as $i => $tag) {
            $platzhalter[] = ':g' . $i;
            $p['g' . $i] = $tag;
        }
        $anzahl = Tenant::count('customers',
            "status = 'aktiv' AND geburtstag != '' AND substr(geburtstag, 6, 5) IN (" . implode(',', $platzhalter) . ')', $p);
        if ($anzahl === 0) {
            return [];
        }
        return [[
            'titel'   => $anzahl . ' Kunde' . ($anzahl === 1 ? '' : 'n') . ' hat diese Woche Geburtstag',
            'grund'   => 'Ein kurzer Gruß ohne Verkaufsabsicht bleibt hängen – und kostet zwei Minuten.',
            'icon'    => 'gift', 'farbe' => 'marke',
            'knopf'   => 'Kunden ansehen', 'url' => '/app/kunden.php?filter=geburtstag',
            'gewicht' => 40,
        ]];
    }

    private static function wartelisten(): array
    {
        $anzahl = Tenant::count('event_registrations', "status = 'warteliste'");
        if ($anzahl === 0) {
            return [];
        }
        return [[
            'titel'   => $anzahl . ' Person' . ($anzahl === 1 ? ' steht' : 'en stehen') . ' auf einer Warteliste',
            'grund'   => 'Bei so viel Nachfrage lohnt sich ein zweiter Termin desselben Angebots.',
            'icon'    => 'events', 'farbe' => 'info',
            'knopf'   => 'Events öffnen', 'url' => '/app/events.php',
            'gewicht' => 60,
        ]];
    }

    private static function leereWoche(): array
    {
        $von = date('Y-m-d', strtotime('monday next week'));
        $bis = date('Y-m-d', strtotime('sunday next week'));
        $auslastung = Bookings::auslastung($von, $bis);
        if ($auslastung === null || $auslastung > 40) {
            return [];
        }
        return [[
            'titel'   => 'Nächste Woche ist erst zu ' . round($auslastung) . ' % gebucht',
            'grund'   => 'Eine kurze Nachricht an inaktive Kunden oder ein Angebot für freie Zeiten füllt den Kalender.',
            'icon'    => 'calendar', 'farbe' => 'warnung',
            'knopf'   => 'Kalender ansehen', 'url' => '/app/kalender.php',
            'gewicht' => 70,
        ]];
    }

    private static function paketeOhneTermin(): array
    {
        $zeilen = DB::all(
            "SELECT COUNT(*) AS anzahl FROM customer_packages cp
             WHERE cp.workspace_id = :w AND cp.status = 'aktiv'
               AND cp.einheiten_genutzt < cp.einheiten_gesamt
               AND NOT EXISTS (SELECT 1 FROM bookings b WHERE b.customer_id = cp.customer_id
                               AND b.start >= :jetzt AND b.status != 'abgesagt')",
            ['w' => Tenant::id(), 'jetzt' => Util::jetzt()]
        );
        $anzahl = (int) ($zeilen[0]['anzahl'] ?? 0);
        if ($anzahl < 2) {
            return [];
        }
        return [[
            'titel'   => $anzahl . ' Kunden haben Guthaben, aber keinen Termin',
            'grund'   => 'Sie haben bezahlt und kommen nicht dazu. Ein Terminvorschlag von dir löst das meist sofort.',
            'icon'    => 'ticket', 'farbe' => 'akzent',
            'knopf'   => 'Pakete ansehen', 'url' => '/app/pakete.php?filter=ohne_termin',
            'gewicht' => 75,
        ]];
    }

    private static function newsletterFaellig(): array
    {
        if (!Tenant::modul('newsletter')) {
            return [];
        }
        $empfaenger = Tenant::count('customers', "newsletter = 1 AND status = 'aktiv'");
        if ($empfaenger < 15) {
            return [];
        }
        $letzter = (string) DB::value(
            "SELECT MAX(versendet) FROM campaigns WHERE workspace_id = :w AND status = 'versendet'",
            ['w' => Tenant::id()], ''
        );
        $tage = $letzter !== '' ? Util::tageSeit($letzter) : 999;
        if ($tage < 45) {
            return [];
        }
        return [[
            'titel'   => $letzter === ''
                ? 'Noch kein Newsletter an ' . $empfaenger . ' Empfänger'
                : 'Letzter Newsletter vor ' . $tage . ' Tagen',
            'grund'   => $empfaenger . ' Menschen haben zugestimmt, von dir zu hören. Eine Übung, ein Tipp, ein Termin – mehr braucht es nicht.',
            'icon'    => 'newsletter', 'farbe' => 'info',
            'knopf'   => 'Newsletter schreiben', 'url' => '/app/kampagne.php?id=neu',
            'gewicht' => 50,
        ]];
    }

    /** Was bei der Einrichtung noch fehlt – nur in den ersten Wochen. */
    private static function einrichtungLuecken(): array
    {
        $liste = [];

        if (Tenant::count('pages', "status = 'veroeffentlicht'") === 0) {
            $liste[] = [
                'titel' => 'Deine Website ist noch nicht veröffentlicht',
                'grund' => 'Ohne veröffentlichte Seite kann dich niemand finden und niemand buchen.',
                'icon' => 'website', 'farbe' => 'gefahr',
                'knopf' => 'Website öffnen', 'url' => '/app/website.php', 'gewicht' => 100,
            ];
        }
        if (Tenant::count('availability', 'aktiv = 1') === 0) {
            $liste[] = [
                'titel' => 'Es sind keine Arbeitszeiten hinterlegt',
                'grund' => 'Ohne Arbeitszeiten zeigt die Online-Buchung keine freien Termine an.',
                'icon' => 'clock', 'farbe' => 'gefahr',
                'knopf' => 'Zeiten festlegen', 'url' => '/app/verfuegbarkeit.php', 'gewicht' => 98,
            ];
        }
        if (Tenant::count('services', 'aktiv = 1') === 0) {
            $liste[] = [
                'titel' => 'Noch keine Leistungen angelegt',
                'grund' => 'Leistungen sind die Grundlage für Buchung, Preise und Pakete.',
                'icon' => 'list', 'farbe' => 'gefahr',
                'knopf' => 'Leistung anlegen', 'url' => '/app/leistungen.php', 'gewicht' => 97,
            ];
        }
        if (Tenant::modul('payments') && !Stripe::eingerichtet()) {
            $liste[] = [
                'titel' => 'Zahlungen laufen im Testmodus',
                'grund' => 'Ohne hinterlegte Stripe-Schlüssel wird kein Geld eingezogen – für den Start in Ordnung, für echte Kunden nicht.',
                'icon' => 'payments', 'farbe' => 'warnung',
                'knopf' => 'Zahlungen einrichten', 'url' => '/app/einstellungen.php#zahlungen', 'gewicht' => 62,
            ];
        }
        return $liste;
    }

    /* -------------------------------------------- Kundenbezogen (NBA) - */

    /**
     * „Wen sollte ich heute anrufen?“ – eine sortierte Liste mit Begründung.
     *
     * @return array<int,array{kunde:array,grund:string,dringlichkeit:string}>
     */
    public static function naechsteKontakte(int $limit = 5): array
    {
        $vorschlaege = [];

        foreach (Tenant::all('customers', "status = 'aktiv'", [], 'health_score', 60) as $k) {
            $kundeId = (int) $k['id'];
            $letzter = (string) DB::value(
                "SELECT MAX(start) FROM bookings WHERE workspace_id = :w AND customer_id = :k
                 AND status IN ('bestaetigt','erschienen') AND start <= :jetzt",
                ['w' => Tenant::id(), 'k' => $kundeId, 'jetzt' => Util::jetzt()], ''
            );
            $tage = $letzter !== '' ? Util::tageSeit($letzter) : 0;

            $kommend = Tenant::count('bookings',
                "customer_id = :k AND start >= :jetzt AND status != 'abgesagt'",
                ['k' => $kundeId, 'jetzt' => Util::jetzt()]);
            if ($kommend > 0) {
                continue;    // hat schon einen Termin – kein Anruf nötig
            }

            $guthaben = Tenant::sum('customer_packages', 'einheiten_gesamt - einheiten_genutzt',
                'customer_id = :k AND status = "aktiv"', ['k' => $kundeId]);

            $gewicht = 0;
            $grund = '';
            if ($guthaben > 0) {
                $gewicht = 90;
                $grund = $guthaben . ' offene Einheit' . ($guthaben === 1 ? '' : 'en') . ', aber kein Termin vereinbart.';
            } elseif ($tage >= 90) {
                $gewicht = 80;
                $grund = 'Letzte Buchung vor ' . $tage . ' Tagen.';
            } elseif ($tage >= 45) {
                $gewicht = 60;
                $grund = 'Seit ' . $tage . ' Tagen nicht mehr da gewesen.';
            } elseif ((int) $k['health_score'] < 40) {
                $gewicht = 50;
                $grund = 'Gesundheitswert bei ' . (int) $k['health_score'] . ' – die Bindung lässt nach.';
            }
            if ($gewicht === 0) {
                continue;
            }

            $vorschlaege[] = [
                'kunde' => $k,
                'grund' => $grund,
                'dringlichkeit' => $gewicht >= 80 ? 'hoch' : ($gewicht >= 55 ? 'mittel' : 'niedrig'),
                'gewicht' => $gewicht,
            ];
        }

        usort($vorschlaege, static fn($a, $b) => $b['gewicht'] <=> $a['gewicht']);
        return array_slice($vorschlaege, 0, $limit);
    }

    /**
     * Preisempfehlungen aus der tatsächlichen Auslastung (Smart Pricing).
     *
     * @return array<int,array{titel:string,text:string,richtung:string}>
     */
    public static function preisempfehlungen(): array
    {
        $tage = ['Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag'];
        $ergebnis = [];

        foreach (Bookings::auslastungsRaster(90) as $wochentag => $bloecke) {
            foreach ($bloecke as $block => $zahlen) {
                $kapazitaet = (int) ($zahlen['kapazitaet'] ?? 0);
                $gebucht    = (int) ($zahlen['gebucht'] ?? 0);
                if ($kapazitaet < 6) {
                    continue;
                }
                $quote = $gebucht / $kapazitaet * 100;
                if ($quote >= 85) {
                    $ergebnis[] = [
                        'titel' => $tage[$wochentag] . ', ' . (Bookings::BLOECKE[$block] ?? ''),
                        'text'  => round($quote) . ' % ausgelastet. Ein Aufschlag von 10 bis 15 % ist hier '
                                 . 'durchsetzbar, ohne dass Buchungen wegbrechen.',
                        'richtung' => 'hoch',
                        'quote' => $quote,
                    ];
                } elseif ($quote <= 25 && $kapazitaet >= 10) {
                    $ergebnis[] = [
                        'titel' => $tage[$wochentag] . ', ' . (Bookings::BLOECKE[$block] ?? ''),
                        'text'  => 'Nur ' . round($quote) . ' % gebucht. Ein vergünstigter Tarif für diese Zeit '
                                 . 'füllt Lücken, ohne den Normalpreis zu beschädigen.',
                        'richtung' => 'runter',
                        'quote' => $quote,
                    ];
                }
            }
        }
        usort($ergebnis, static fn($a, $b) => $b['quote'] <=> $a['quote']);
        return array_slice($ergebnis, 0, 6);
    }
}
