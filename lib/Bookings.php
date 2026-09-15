<?php
/**
 * Bookings – die Buchungsmaschine.
 *
 * Freie Zeiten entstehen nicht aus einer Liste, sondern aus einer Rechnung:
 * Arbeitszeit minus Urlaub minus Sperrzeiten minus bestehende Termine minus
 * Pufferzeiten minus Vorlauffrist. Wer freie Slots speichert, hat beim
 * nächsten Urlaub eine Doppelbuchung.
 *
 * Gebuchte Termine kopieren ihren Preis. Ändert der Pro später seinen
 * Stundensatz, bleibt der alte Termin so, wie er gebucht wurde – sonst
 * ändert sich rückwirkend, was der Kunde zu zahlen glaubte.
 */
final class Bookings
{
    public const STATUS = [
        'angefragt'       => ['Angefragt', 'warnung'],
        'bestaetigt'      => ['Bestätigt', 'erfolg'],
        'erschienen'      => ['Erschienen', 'info'],
        'abgesagt'        => ['Abgesagt', 'gefahr'],
        'nicht_erschienen'=> ['Nicht erschienen', 'gefahr'],
    ];

    public static function statusName(string $status): string
    {
        return self::STATUS[$status][0] ?? $status;
    }

    public static function statusFarbe(string $status): string
    {
        return self::STATUS[$status][1] ?? '';
    }

    /* ------------------------------------------------------ Verfügbarkeit */

    /**
     * Freie Startzeiten eines Tages.
     *
     * @return array<int,array{start:string,ende:string,trainer_id:int}>
     */
    public static function freieZeiten(int $serviceId, string $datum, int $trainerId = 0, int $raster = 15): array
    {
        $service = Tenant::find('services', $serviceId);
        if (!$service || (int) $service['aktiv'] !== 1) {
            return [];
        }
        $dauer  = (int) $service['dauer_min'];
        $puffer = (int) $service['puffer_min'];
        $vorlauf = (int) $service['vorlauf_stunden'];
        $wochentag = (int) date('w', strtotime($datum));

        $trainerListe = $trainerId > 0
            ? [$trainerId]
            : array_map('intval', Util::ausJson((string) $service['trainer_ids'], []));
        if ($trainerListe === []) {
            $trainerListe = array_map(static fn($u) => (int) $u['id'], Tenant::all('users', 'aktiv = 1'));
        }

        $fruehestens = strtotime('+' . $vorlauf . ' hours');
        $frei = [];

        foreach ($trainerListe as $tid) {
            $fenster = Tenant::all('availability',
                'user_id = :u AND wochentag = :wt AND aktiv = 1',
                ['u' => $tid, 'wt' => $wochentag], 'von_min');
            if ($fenster === []) {
                continue;
            }
            if (self::hatFrei($tid, $datum)) {
                continue;
            }
            $belegt = self::belegteZeiten($tid, $datum);

            foreach ($fenster as $f) {
                $von = (int) $f['von_min'];
                $bis = (int) $f['bis_min'];
                for ($m = $von; $m + $dauer <= $bis; $m += $raster) {
                    $startZeit = strtotime($datum . ' ' . Util::minutenZuZeit($m));
                    if ($startZeit < $fruehestens) {
                        continue;
                    }
                    $endeMin = $m + $dauer + $puffer;
                    $kollision = false;
                    foreach ($belegt as [$bVon, $bBis]) {
                        if ($m < $bBis && $endeMin > $bVon) {
                            $kollision = true;
                            break;
                        }
                    }
                    if ($kollision) {
                        continue;
                    }
                    $frei[] = [
                        'start'      => date('Y-m-d H:i:s', $startZeit),
                        'ende'       => date('Y-m-d H:i:s', $startZeit + $dauer * 60),
                        'trainer_id' => $tid,
                    ];
                }
            }
        }

        usort($frei, static fn($a, $b) => strcmp($a['start'], $b['start']));

        // Doppelte Startzeiten verschiedener Trainer auf eine reduzieren.
        $gesehen = [];
        $ergebnis = [];
        foreach ($frei as $z) {
            if (isset($gesehen[$z['start']])) {
                continue;
            }
            $gesehen[$z['start']] = true;
            $ergebnis[] = $z;
        }
        return $ergebnis;
    }

    /** @return array<int,array{0:int,1:int}> belegte Minutenfenster des Tages */
    private static function belegteZeiten(int $trainerId, string $datum): array
    {
        $zeilen = Tenant::all('bookings',
            "trainer_id = :u AND start >= :a AND start < :b AND status != 'abgesagt'",
            ['u' => $trainerId, 'a' => $datum . ' 00:00:00', 'b' => $datum . ' 23:59:59'],
            'start');
        $belegt = [];
        foreach ($zeilen as $b) {
            $von = (int) date('G', strtotime((string) $b['start'])) * 60 + (int) date('i', strtotime((string) $b['start']));
            $bis = (int) date('G', strtotime((string) $b['ende'])) * 60 + (int) date('i', strtotime((string) $b['ende']));
            $service = $b['service_id'] ? Tenant::find('services', (int) $b['service_id']) : null;
            $bis += (int) ($service['puffer_min'] ?? 0);
            $belegt[] = [$von, $bis];
        }
        foreach (self::sperrzeiten($trainerId, $datum) as [$von, $bis]) {
            $belegt[] = [$von, $bis];
        }
        return $belegt;
    }

    private static function hatFrei(int $trainerId, string $datum): bool
    {
        return Tenant::count('time_off',
            '(user_id = :u OR user_id = 0) AND ganztags = 1 AND von <= :d1 AND bis >= :d2',
            ['u' => $trainerId, 'd1' => $datum . ' 23:59:59', 'd2' => $datum . ' 00:00:00']) > 0;
    }

    /** @return array<int,array{0:int,1:int}> */
    private static function sperrzeiten(int $trainerId, string $datum): array
    {
        $liste = [];
        foreach (Tenant::all('time_off',
            '(user_id = :u OR user_id = 0) AND ganztags = 0 AND von <= :d1 AND bis >= :d2',
            ['u' => $trainerId, 'd1' => $datum . ' 23:59:59', 'd2' => $datum . ' 00:00:00']) as $z) {
            $von = max(strtotime($datum . ' 00:00:00'), strtotime((string) $z['von']));
            $bis = min(strtotime($datum . ' 23:59:59'), strtotime((string) $z['bis']));
            $liste[] = [
                (int) date('G', $von) * 60 + (int) date('i', $von),
                (int) date('G', $bis) * 60 + (int) date('i', $bis),
            ];
        }
        return $liste;
    }

    /* ------------------------------------------------------------ Buchen */

    /**
     * Legt einen Termin an und verrechnet, was dazugehört.
     *
     * @return array{0:int,1:string} [booking_id, Fehlermeldung]
     */
    public static function buchen(array $daten): array
    {
        $serviceId  = (int) ($daten['service_id'] ?? 0);
        $kundeId    = (int) ($daten['customer_id'] ?? 0);
        $trainerId  = (int) ($daten['trainer_id'] ?? 0);
        $start      = (string) ($daten['start'] ?? '');
        $quelle     = (string) ($daten['quelle'] ?? 'backend');

        if ($start === '') {
            return [0, 'Es fehlt der Zeitpunkt. Bitte Tag und Uhrzeit wählen.'];
        }
        $service = $serviceId > 0 ? Tenant::find('services', $serviceId) : null;
        $dauer   = (int) ($daten['dauer_min'] ?? ($service['dauer_min'] ?? 60));
        $ende    = (string) ($daten['ende'] ?? date('Y-m-d H:i:s', strtotime($start) + $dauer * 60));

        if ($trainerId > 0 && empty($daten['ohne_pruefung']) && self::kollidiert($trainerId, $start, $ende)) {
            return [0, 'Zu dieser Zeit liegt bereits ein Termin. Bitte eine andere Zeit wählen.'];
        }

        $preis = isset($daten['preis_cent']) ? (int) $daten['preis_cent'] : (int) ($service['preis_cent'] ?? 0);
        $paketId = (int) ($daten['customer_package_id'] ?? 0);

        return DB::transaktion(function () use ($daten, $service, $kundeId, $trainerId, $start, $ende, $preis, $paketId, $quelle, $serviceId) {
            /* Wird aus einem Paket bezahlt, sinkt dort das Guthaben. */
            $bezahlt = (int) ($daten['bezahlt'] ?? 0);
            if ($paketId > 0) {
                $paket = Tenant::find('customer_packages', $paketId);
                if (!$paket || (int) $paket['einheiten_genutzt'] >= (int) $paket['einheiten_gesamt']) {
                    throw new RuntimeException('Das Paket hat keine freie Einheit mehr.');
                }
                Tenant::update('customer_packages', $paketId, [
                    'einheiten_genutzt' => (int) $paket['einheiten_genutzt'] + 1,
                    'status' => ((int) $paket['einheiten_genutzt'] + 1 >= (int) $paket['einheiten_gesamt'])
                        ? 'aufgebraucht' : 'aktiv',
                ]);
                $bezahlt = 1;
            }

            $id = Tenant::insert('bookings', [
                'service_id'  => $serviceId,
                'customer_id' => $kundeId,
                'trainer_id'  => $trainerId,
                'location_id' => (int) ($daten['location_id'] ?? ($service['location_id'] ?? 0)),
                'event_id'    => (int) ($daten['event_id'] ?? 0),
                'titel'       => (string) ($daten['titel'] ?? ($service['name'] ?? 'Termin')),
                'start'       => $start,
                'ende'        => $ende,
                'status'      => (string) ($daten['status'] ?? 'bestaetigt'),
                'teilnehmer'  => (int) ($daten['teilnehmer'] ?? 1),
                'preis_cent'  => $preis,
                'bezahlt'     => $bezahlt,
                'customer_package_id' => $paketId,
                'notiz'       => (string) ($daten['notiz'] ?? ''),
                'interne_notiz' => (string) ($daten['interne_notiz'] ?? ''),
                'quelle'      => $quelle,
            ]);

            if ($kundeId > 0) {
                Customers::aktivitaet($kundeId);
                Gamification::punkte($kundeId, 20, 'Training gebucht');
            }
            Audit::schreiben('erstellt', 'booking', $id, (string) ($daten['titel'] ?? 'Termin') . ' am ' . Util::datumZeit($start));
            Automations::ausloesen('booking_created', ['customer_id' => $kundeId, 'booking_id' => $id]);

            if ($quelle !== 'backend') {
                Notify::senden('booking', 'Neue Buchung online',
                    Customers::nameVonId($kundeId) . ' · ' . Util::datumZeit($start),
                    '/app/buchung.php?id=' . $id);
            }
            return [$id, ''];
        });
    }

    public static function kollidiert(int $trainerId, string $start, string $ende, int $ausser = 0): bool
    {
        $wo = 'trainer_id = :u AND status != "abgesagt" AND start < :ende AND ende > :start';
        $p  = ['u' => $trainerId, 'start' => $start, 'ende' => $ende];
        if ($ausser > 0) {
            $wo .= ' AND id != :ausser';
            $p['ausser'] = $ausser;
        }
        return Tenant::count('bookings', $wo, $p) > 0;
    }

    public static function absagen(int $id, string $grund = '', bool $einheitZurueck = true): void
    {
        $b = Tenant::find('bookings', $id);
        if (!$b || $b['status'] === 'abgesagt') {
            return;
        }
        DB::transaktion(function () use ($b, $id, $grund, $einheitZurueck) {
            Tenant::update('bookings', $id, [
                'status' => 'abgesagt',
                'abgesagt_am' => Util::jetzt(),
                'abgesagt_grund' => $grund,
            ]);
            $paketId = (int) $b['customer_package_id'];
            if ($einheitZurueck && $paketId > 0) {
                $paket = Tenant::find('customer_packages', $paketId);
                if ($paket) {
                    Tenant::update('customer_packages', $paketId, [
                        'einheiten_genutzt' => max(0, (int) $paket['einheiten_genutzt'] - 1),
                        'status' => 'aktiv',
                    ]);
                }
            }
            self::wartelisteNachruecken($id);
        });
        Audit::schreiben('storniert', 'booking', $id, $grund);
    }

    public static function umbuchen(int $id, string $neuerStart, int $trainerId = 0): array
    {
        $b = Tenant::find('bookings', $id);
        if (!$b) {
            return [false, 'Der Termin wurde nicht gefunden.'];
        }
        if (trim($neuerStart) === '') {
            return [false, 'Es fehlt der neue Zeitpunkt. Bitte Tag und Uhrzeit wählen.'];
        }
        $dauer = (strtotime((string) $b['ende']) - strtotime((string) $b['start'])) / 60;
        $ende  = date('Y-m-d H:i:s', strtotime($neuerStart) + (int) $dauer * 60);
        $trainerId = $trainerId ?: (int) $b['trainer_id'];

        if (self::kollidiert($trainerId, $neuerStart, $ende, $id)) {
            return [false, 'Zu dieser Zeit liegt bereits ein Termin.'];
        }
        Tenant::update('bookings', $id, [
            'start' => $neuerStart, 'ende' => $ende, 'trainer_id' => $trainerId,
            'erinnerung_24' => null, 'erinnerung_1' => null,
        ]);
        Audit::schreiben('geaendert', 'booking', $id, 'Umgebucht auf ' . Util::datumZeit($neuerStart));
        return [true, ''];
    }

    private static function wartelisteNachruecken(int $bookingId): void
    {
        $naechster = Tenant::one('waitlist', 'booking_id = :b AND benachrichtigt IS NULL',
            ['b' => $bookingId], 'position, id');
        if (!$naechster) {
            return;
        }
        Tenant::update('waitlist', (int) $naechster['id'], ['benachrichtigt' => Util::jetzt()]);
        Notify::senden('booking', 'Warteliste: Platz frei geworden',
            Customers::nameVonId((int) $naechster['customer_id']) . ' kann nachrücken.',
            '/app/buchung.php?id=' . $bookingId);
    }

    /* --------------------------------------------------------- Abfragen */

    /** @return array<int,array<string,mixed>> */
    public static function zeitraum(string $von, string $bis, array $f = []): array
    {
        $wo = 'start >= :von AND start <= :bis';
        $p  = ['von' => $von, 'bis' => $bis];
        if (!empty($f['trainer'])) {
            $wo .= ' AND trainer_id = :t';
            $p['t'] = (int) $f['trainer'];
        }
        if (!empty($f['location'])) {
            $wo .= ' AND location_id = :l';
            $p['l'] = (int) $f['location'];
        }
        if (empty($f['mit_abgesagt'])) {
            $wo .= ' AND status != "abgesagt"';
        }
        return Tenant::all('bookings', $wo, $p, 'start');
    }

    public static function kommende(int $limit = 8, int $trainerId = 0): array
    {
        $wo = 'start >= :jetzt AND status IN ("bestaetigt","angefragt")';
        $p  = ['jetzt' => Util::jetzt()];
        if ($trainerId > 0) {
            $wo .= ' AND trainer_id = :t';
            $p['t'] = $trainerId;
        }
        return Tenant::all('bookings', $wo, $p, 'start', $limit);
    }

    public static function heute(int $trainerId = 0): array
    {
        return self::zeitraum(Util::heute() . ' 00:00:00', Util::heute() . ' 23:59:59',
            $trainerId > 0 ? ['trainer' => $trainerId] : []);
    }

    /**
     * Auslastung in Prozent: gebuchte Minuten geteilt durch Arbeitszeit.
     * Ohne hinterlegte Arbeitszeiten ist die Zahl bedeutungslos – dann
     * liefert die Methode null statt einer erfundenen Prozentangabe.
     */
    public static function auslastung(string $von, string $bis, int $trainerId = 0): ?float
    {
        $gebucht = 0;
        foreach (self::zeitraum($von . ' 00:00:00', $bis . ' 23:59:59', $trainerId ? ['trainer' => $trainerId] : []) as $b) {
            $gebucht += (strtotime((string) $b['ende']) - strtotime((string) $b['start'])) / 60;
        }

        $wo = $trainerId > 0 ? 'user_id = :u AND aktiv = 1' : 'aktiv = 1';
        $fenster = Tenant::all('availability', $wo, $trainerId > 0 ? ['u' => $trainerId] : []);
        if ($fenster === []) {
            return null;
        }
        $jeWochentag = [];
        foreach ($fenster as $f) {
            $jeWochentag[(int) $f['wochentag']] = ($jeWochentag[(int) $f['wochentag']] ?? 0)
                                                + ((int) $f['bis_min'] - (int) $f['von_min']);
        }
        $kapazitaet = 0;
        for ($t = strtotime($von); $t <= strtotime($bis); $t += 86400) {
            $kapazitaet += $jeWochentag[(int) date('w', $t)] ?? 0;
        }
        if ($kapazitaet <= 0) {
            return null;
        }
        return min(100.0, $gebucht / $kapazitaet * 100);
    }

    /**
     * Auslastung nach Wochentag und Zeitblock – Grundlage für Smart Pricing
     * und für den Hinweis „Dienstagabend ist zu 94 % ausgelastet“.
     *
     * @return array<int,array<string,array{gebucht:int,kapazitaet:int}>>
     */
    public static function auslastungsRaster(int $tage = 90): array
    {
        $seit = date('Y-m-d', strtotime('-' . $tage . ' days'));
        $raster = [];
        foreach (self::zeitraum($seit . ' 00:00:00', Util::heute() . ' 23:59:59') as $b) {
            $zeit = strtotime((string) $b['start']);
            $wt   = (int) date('w', $zeit);
            $block = self::block((int) date('G', $zeit));
            $raster[$wt][$block]['gebucht'] = ($raster[$wt][$block]['gebucht'] ?? 0) + 1;
        }
        $wochen = max(1, (int) ceil($tage / 7));
        foreach (Tenant::all('availability', 'aktiv = 1') as $f) {
            $wt = (int) $f['wochentag'];
            for ($m = (int) $f['von_min']; $m < (int) $f['bis_min']; $m += 60) {
                $block = self::block(intdiv($m, 60));
                $raster[$wt][$block]['kapazitaet'] = ($raster[$wt][$block]['kapazitaet'] ?? 0) + $wochen;
            }
        }
        return $raster;
    }

    /**
     * Verteilt gleichzeitige Termine auf nebeneinanderliegende Spalten.
     *
     * Ein Kalender, der nur `top` und `height` setzt, zeichnet zwei Termine
     * zur selben Stunde uebereinander: Der obere verdeckt den unteren, und
     * genau die Doppelbuchung, die man sehen muesste, ist unsichtbar.
     *
     * Deshalb dasselbe Verfahren, das jeder Kalender benutzt:
     *
     *   1. Termine nach Beginn sortieren.
     *   2. Alles, was sich zeitlich beruehrt, zu einer Gruppe zusammenfassen.
     *      Beruehren ist ansteckend - A und C gehoeren zusammen, wenn beide
     *      B ueberlappen, auch wenn sie sich selbst nicht begegnen.
     *   3. Innerhalb der Gruppe bekommt jeder Termin die erste Spur, die zu
     *      seiner Startzeit frei ist.
     *   4. Alle Termine der Gruppe teilen sich die Breite durch die Zahl der
     *      benutzten Spuren - sonst waeren die Kaesten unterschiedlich
     *      breit, je nachdem, wen man gerade ansieht.
     *
     * @param  list<array{start:string,ende:string}> $termine
     * @return list<array{0:int,1:int}> je Termin [Spur, Spuren insgesamt],
     *         in der Reihenfolge der Eingabe
     */
    public static function spalten(array $termine): array
    {
        $zeiten = [];
        foreach ($termine as $i => $t) {
            $a = strtotime((string) $t['start']) ?: 0;
            $e = strtotime((string) $t['ende']) ?: $a;
            $zeiten[] = ['i' => $i, 'a' => $a, 'e' => max($e, $a + 60)];
        }
        usort($zeiten, static fn ($x, $y) => [$x['a'], $x['e']] <=> [$y['a'], $y['e']]);

        $ergebnis = array_fill(0, count($termine), [0, 1]);
        $gruppe   = [];   // Termine der laufenden Gruppe
        $spuren   = [];   // Spur => Ende des letzten Termins darin
        $endeMax  = null; // spaetestes Ende der Gruppe

        $gruppeAbschliessen = static function () use (&$gruppe, &$spuren, &$ergebnis): void {
            $breite = max(1, count($spuren));
            foreach ($gruppe as [$index, $spur]) {
                $ergebnis[$index] = [$spur, $breite];
            }
            $gruppe = [];
            $spuren = [];
        };

        foreach ($zeiten as $z) {
            // Beginnt der Termin erst, wenn die ganze Gruppe vorbei ist,
            // faengt eine neue an.
            if ($endeMax !== null && $z['a'] >= $endeMax) {
                $gruppeAbschliessen();
                $endeMax = null;
            }

            $spur = null;
            foreach ($spuren as $nr => $frei) {
                if ($frei <= $z['a']) {
                    $spur = $nr;
                    break;
                }
            }
            if ($spur === null) {
                $spur = count($spuren);
            }
            $spuren[$spur] = $z['e'];
            $gruppe[] = [$z['i'], $spur];
            $endeMax = $endeMax === null ? $z['e'] : max($endeMax, $z['e']);
        }
        $gruppeAbschliessen();

        return $ergebnis;
    }

    public static function block(int $stunde): string
    {
        if ($stunde < 11) {
            return 'morgens';
        }
        if ($stunde < 14) {
            return 'mittags';
        }
        if ($stunde < 17) {
            return 'nachmittags';
        }
        return 'abends';
    }

    public const BLOECKE = [
        'morgens' => '07–11 Uhr', 'mittags' => '11–14 Uhr',
        'nachmittags' => '14–17 Uhr', 'abends' => '17–21 Uhr',
    ];

    /* ------------------------------------------------------ Erinnerungen */

    /**
     * Fällige Erinnerungen. Wird beim Öffnen des Dashboards ausgeführt –
     * so funktioniert die Erinnerung auch ohne Cronjob, den viele Hoster
     * nicht anbieten. Ein echter Cronjob auf mail.php ist genauer und in
     * docs/BETRIEB.md beschrieben.
     */
    public static function erinnerungenVersenden(): int
    {
        $versendet = 0;
        $morgen = date('Y-m-d H:i:s', time() + 86400);

        foreach (Tenant::all('bookings',
            "status = 'bestaetigt' AND erinnerung_24 IS NULL AND start > :jetzt AND start <= :morgen",
            ['jetzt' => Util::jetzt(), 'morgen' => $morgen], 'start', 25) as $b) {
            if (self::erinnerung($b, '24')) {
                $versendet++;
            }
        }
        $gleich = date('Y-m-d H:i:s', time() + 3600);
        foreach (Tenant::all('bookings',
            "status = 'bestaetigt' AND erinnerung_1 IS NULL AND start > :jetzt AND start <= :gleich",
            ['jetzt' => Util::jetzt(), 'gleich' => $gleich], 'start', 25) as $b) {
            if (self::erinnerung($b, '1')) {
                $versendet++;
            }
        }
        return $versendet;
    }

    private static function erinnerung(array $b, string $art): bool
    {
        $kunde = Tenant::find('customers', (int) $b['customer_id']);
        if (!$kunde || (string) $kunde['email'] === '') {
            Tenant::update('bookings', (int) $b['id'], ['erinnerung_' . $art => Util::jetzt()]);
            return false;
        }
        $wann = $art === '24' ? 'morgen' : 'in einer Stunde';
        Mail::anKunden($kunde, 'Erinnerung: dein Termin ' . $wann,
            "Hallo " . $kunde['vorname'] . ",\n\n"
            . "dein Termin \"" . $b['titel'] . "\" ist " . $wann . " – am "
            . Util::datumLang((string) $b['start']) . " um " . Util::uhrzeit((string) $b['start']) . " Uhr.\n\n"
            . "Bis dann!\n" . Tenant::name());
        Tenant::update('bookings', (int) $b['id'], ['erinnerung_' . $art => Util::jetzt()]);
        return true;
    }
}
