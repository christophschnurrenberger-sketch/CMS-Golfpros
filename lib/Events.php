<?php
/**
 * Events – Workshops, Camps, Turniere, Golfreisen.
 *
 * Ein Event ist kein Termin: Es hat Teilnehmer, ein Limit, eine Warteliste
 * und einen Check-in. Deshalb eine eigene Tabelle statt einer Sonderform
 * der Buchung – sonst wäre jede Abfrage auf den Kalender mit „und wenn es
 * ein Event ist, dann anders" gespickt.
 */
final class Events
{
    public const ARTEN = [
        'workshop' => ['Workshop', 'events'],
        'camp'     => ['Camp', 'flag'],
        'gruppe'   => ['Gruppentraining', 'customers'],
        'reise'    => ['Golfreise', 'globe'],
        'turnier'  => ['Turnier', 'trophy'],
    ];

    public static function speichern(array $daten, int $id = 0): int
    {
        $satz = array_intersect_key($daten, array_flip([
            'titel', 'art', 'beschreibung', 'bild', 'start', 'ende', 'location_id',
            'ort_text', 'kapazitaet', 'preis_cent', 'trainer_id', 'warteliste', 'status',
        ]));
        if (isset($daten['titel'])) {
            $satz['slug'] = Util::slug((string) $daten['titel']);
        }
        if ($id > 0) {
            Tenant::update('events', $id, $satz);
            return $id;
        }
        $neu = Tenant::insert('events', $satz);
        Audit::schreiben('erstellt', 'event', $neu, (string) ($satz['titel'] ?? ''));
        return $neu;
    }

    public static function anmeldungen(int $eventId, string $status = 'angemeldet'): array
    {
        $wo = 'event_id = :e';
        $p  = ['e' => $eventId];
        if ($status !== 'alle') {
            $wo .= ' AND status = :s';
            $p['s'] = $status;
        }
        return Tenant::all('event_registrations', $wo, $p, 'id');
    }

    public static function belegt(int $eventId): int
    {
        return Tenant::count('event_registrations', "event_id = :e AND status = 'angemeldet'", ['e' => $eventId]);
    }

    public static function freiePlaetze(array $event): int
    {
        return max(0, (int) $event['kapazitaet'] - self::belegt((int) $event['id']));
    }

    /** @return array{0:bool,1:string} [angemeldet oder Warteliste, Meldung] */
    public static function anmelden(int $eventId, int $kundeId, int $orderId = 0): array
    {
        $event = Tenant::find('events', $eventId);
        if (!$event) {
            return [false, 'Das Event wurde nicht gefunden.'];
        }
        if (Tenant::count('event_registrations', "event_id = :e AND customer_id = :k AND status != 'abgesagt'",
            ['e' => $eventId, 'k' => $kundeId]) > 0) {
            return [false, 'Diese Person ist bereits angemeldet.'];
        }

        $voll = self::freiePlaetze($event) <= 0;
        if ($voll && (int) $event['warteliste'] !== 1) {
            return [false, 'Das Event ist ausgebucht.'];
        }

        Tenant::insert('event_registrations', [
            'event_id'    => $eventId,
            'customer_id' => $kundeId,
            'status'      => $voll ? 'warteliste' : 'angemeldet',
            'bezahlt'     => $orderId > 0 ? 1 : 0,
            'order_id'    => $orderId,
        ]);
        Customers::aktivitaet($kundeId);

        $kunde = Tenant::find('customers', $kundeId);
        if ($kunde && (string) $kunde['email'] !== '') {
            Mail::anKunden($kunde,
                $voll ? 'Du stehst auf der Warteliste' : 'Anmeldung bestätigt: ' . $event['titel'],
                "Hallo " . $kunde['vorname'] . ",\n\n"
                . ($voll
                    ? "das Event \"" . $event['titel'] . "\" ist ausgebucht. Du stehst auf der Warteliste und rückst automatisch nach, sobald ein Platz frei wird."
                    : "deine Anmeldung für \"" . $event['titel'] . "\" ist bestätigt.\n\n"
                      . "Wann: " . Util::datumLang((string) $event['start']) . ", " . Util::uhrzeit((string) $event['start']) . " Uhr\n"
                      . "Wo: " . ($event['ort_text'] ?: self::ortName((int) $event['location_id']))));
        }
        Notify::senden('booking', ($voll ? 'Warteliste: ' : 'Anmeldung: ') . $event['titel'],
            Customers::nameVonId($kundeId), '/app/event.php?id=' . $eventId);

        return [!$voll, $voll ? 'Auf die Warteliste gesetzt.' : 'Angemeldet.'];
    }

    public static function abmelden(int $anmeldungId): void
    {
        $a = Tenant::find('event_registrations', $anmeldungId);
        if (!$a) {
            return;
        }
        Tenant::update('event_registrations', $anmeldungId, ['status' => 'abgesagt']);

        // Erster von der Warteliste rückt nach.
        $naechster = Tenant::one('event_registrations',
            "event_id = :e AND status = 'warteliste'", ['e' => (int) $a['event_id']], 'id');
        if ($naechster) {
            Tenant::update('event_registrations', (int) $naechster['id'], ['status' => 'angemeldet']);
            $kunde = Tenant::find('customers', (int) $naechster['customer_id']);
            $event = Tenant::find('events', (int) $a['event_id']);
            if ($kunde && (string) $kunde['email'] !== '' && $event) {
                Mail::anKunden($kunde, 'Ein Platz ist frei geworden',
                    "Hallo " . $kunde['vorname'] . ",\n\nbei \"" . $event['titel']
                    . "\" ist ein Platz frei geworden – du bist jetzt fest dabei.\n\n"
                    . "Wann: " . Util::datumLang((string) $event['start']));
            }
            Notify::senden('booking', 'Nachgerückt von der Warteliste',
                Customers::nameVonId((int) $naechster['customer_id']),
                '/app/event.php?id=' . (int) $a['event_id']);
        }
    }

    public static function einchecken(int $anmeldungId): void
    {
        Tenant::update('event_registrations', $anmeldungId, ['eingecheckt' => Util::jetzt()]);
    }

    public static function kommende(int $limit = 10): array
    {
        return Tenant::all('events', "start >= :jetzt AND status != 'abgesagt'",
            ['jetzt' => Util::jetzt()], 'start', $limit);
    }

    public static function ortName(int $locationId): string
    {
        if ($locationId <= 0) {
            return '';
        }
        $o = Tenant::find('locations', $locationId);
        return $o ? (string) $o['name'] : '';
    }

    public static function umsatz(int $eventId): int
    {
        $event = Tenant::find('events', $eventId);
        $bezahlt = Tenant::count('event_registrations',
            "event_id = :e AND status = 'angemeldet' AND bezahlt = 1", ['e' => $eventId]);
        return $bezahlt * (int) ($event['preis_cent'] ?? 0);
    }
}
