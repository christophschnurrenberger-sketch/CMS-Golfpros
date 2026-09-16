<?php
/**
 * Golfreisen – mehrtägige Reisen mit Anmeldung, Zimmerwahl und Anzahlung.
 *
 * Warum eine eigene Klasse und nicht eine Art von Event: Ein Event hat
 * einen Tag, eine Uhrzeit und einen Preis. Eine Reise hat ein Ziel, ein
 * Hotel, Nächte, zwei Preise (Doppel- und Einzelzimmer), eine Anzahlung,
 * eine Mindestteilnehmerzahl und einen Ablauf über mehrere Tage. Das in
 * die Eventtabelle zu drücken hieße, dort zehn Spalten zu führen, die für
 * jeden Workshop leer bleiben – und jede Abfrage mit „und wenn es eine
 * Reise ist, dann anders" zu spicken.
 */
final class Trips
{
    public const ANREISE = [
        'eigen' => 'Eigene Anreise',
        'flug'  => 'Flug inklusive',
        'bus'   => 'Busanreise',
    ];

    public const ZIMMER = [
        'dz' => 'Doppelzimmer',
        'ez' => 'Einzelzimmer',
    ];

    /** Was der Baukasten und die Website als buchbar ansehen. */
    public const OEFFENTLICH = 'veroeffentlicht';

    /* ------------------------------------------------------- Speichern --- */

    /**
     * @param array<string,mixed> $daten
     */
    public static function speichern(array $daten, int $id = 0): int
    {
        $satz = array_intersect_key($daten, array_flip([
            'titel', 'ziel', 'land', 'hotel', 'kurztext', 'beschreibung',
            'leistungen', 'nicht_enthalten', 'programm', 'bild', 'bilder',
            'start', 'ende', 'naechte', 'anreise', 'abflug_ort',
            'preis_cent', 'ez_zuschlag_cent', 'anzahlung_cent',
            'plaetze', 'mindest_teilnehmer', 'trainer_id', 'warteliste', 'status',
        ]));

        /*
         * Der Kurzname in der Adresse folgt dem Titel – aber nur beim
         * Anlegen und beim Umbenennen. Sonst bräche jeder verschickte Link
         * auf die Reise, sobald jemand den Titel nachschärft.
         */
        if (isset($daten['titel']) && (string) $daten['titel'] !== '') {
            $satz['slug'] = self::freierSlug(Util::slug((string) $daten['titel']), $id);
        }

        /* Die Nächte ergeben sich aus den Daten, wenn beide dastehen –
           zwei Angaben, die sich widersprechen können, sind eine zu viel. */
        if (!empty($satz['start']) && !empty($satz['ende'])) {
            $satz['naechte'] = max(0, (int) round(
                (strtotime((string) $satz['ende']) - strtotime((string) $satz['start'])) / 86400
            ));
        }

        if ($id > 0) {
            Tenant::update('trips', $id, $satz);
            Audit::schreiben('geaendert', 'trip', $id, (string) ($satz['titel'] ?? ''));
            return $id;
        }

        $satz['position'] = Tenant::count('trips');
        $neu = Tenant::insert('trips', $satz);
        Audit::schreiben('erstellt', 'trip', $neu, (string) ($satz['titel'] ?? ''));
        return $neu;
    }

    /**
     * Ein Kurzname, den es noch nicht gibt.
     *
     * Zwei Reisen „Mallorca im Frühjahr" in zwei Jahren sind keine
     * Seltenheit, und die Adresse muss trotzdem eindeutig bleiben –
     * sonst zeigt der Link auf die falsche.
     */
    private static function freierSlug(string $slug, int $ausser = 0): string
    {
        $slug = $slug !== '' ? $slug : 'reise';
        $kandidat = $slug;
        $n = 2;
        while (Tenant::one('trips', 'slug = :s AND id != :i', ['s' => $kandidat, 'i' => $ausser]) !== null) {
            $kandidat = $slug . '-' . $n;
            $n++;
        }
        return $kandidat;
    }

    /* ------------------------------------------------------- Abfragen --- */

    /** @return array<int,array<string,mixed>> */
    public static function kommende(int $limit = 12, bool $nurOeffentlich = true): array
    {
        $wo = 'ende >= :jetzt';
        $p  = ['jetzt' => Util::jetzt()];
        if ($nurOeffentlich) {
            $wo .= ' AND status = :st';
            $p['st'] = self::OEFFENTLICH;
        }
        return Tenant::all('trips', $wo, $p, 'start', $limit);
    }

    public static function nachSlug(string $slug): ?array
    {
        return Tenant::one('trips', 'slug = :s', ['s' => $slug]);
    }

    /** @return array<int,array<string,mixed>> */
    public static function anmeldungen(int $tripId, string $status = ''): array
    {
        $wo = 'trip_id = :t';
        $p  = ['t' => $tripId];
        if ($status !== '') {
            $wo .= ' AND status = :s';
            $p['s'] = $status;
        }
        return Tenant::all('trip_signups', $wo, $p, 'erstellt');
    }

    /** Belegte Plätze – Warteliste und Stornos zählen nicht mit. */
    public static function belegt(int $tripId): int
    {
        return Tenant::count('trip_signups',
            "trip_id = :t AND status IN ('angemeldet', 'bestaetigt')", ['t' => $tripId]);
    }

    /** @param array<string,mixed> $reise */
    public static function freiePlaetze(array $reise): int
    {
        return max(0, (int) $reise['plaetze'] - self::belegt((int) $reise['id']));
    }

    /**
     * Was diese Person zahlt.
     *
     * Der Preis am Datensatz gilt pro Person im Doppelzimmer; wer allein
     * schläft, zahlt den Zuschlag dazu. So steht auf der Website eine
     * Zahl, und der Zuschlag erscheint erst, wenn er zutrifft.
     *
     * @param array<string,mixed> $reise
     */
    public static function preis(array $reise, string $zimmer = 'dz'): int
    {
        return (int) $reise['preis_cent']
             + ($zimmer === 'ez' ? (int) $reise['ez_zuschlag_cent'] : 0);
    }

    /** Summe der bestätigten und angemeldeten Anmeldungen. */
    public static function umsatz(int $tripId): int
    {
        return (int) Tenant::sum('trip_signups', 'preis_cent',
            "trip_id = :t AND status IN ('angemeldet', 'bestaetigt')", ['t' => $tripId]);
    }

    /**
     * Fehlen noch Teilnehmer, damit die Reise stattfindet?
     *
     * Eine Reise mit Mindestteilnehmerzahl kann bis kurz vorher kippen.
     * Das gehört auf die Übersicht des Pro, nicht in seinen Kopf.
     *
     * @param array<string,mixed> $reise
     */
    public static function fehlend(array $reise): int
    {
        return max(0, (int) $reise['mindest_teilnehmer'] - self::belegt((int) $reise['id']));
    }

    /**
     * Listen aus dem Datensatz – Leistungen, Nichtenthaltenes, Programm.
     *
     * Leere Zeilen fliegen raus: Im Backend steht ein Textfeld mit einer
     * Zeile je Punkt, und dort bleibt beim Tippen schnell eine leer.
     *
     * @return list<string>
     */
    public static function zeilen(string $json): array
    {
        $liste = Util::ausJson($json, []);
        $aus = [];
        foreach ((array) $liste as $z) {
            $z = trim((string) $z);
            if ($z !== '') {
                $aus[] = $z;
            }
        }
        return $aus;
    }

    /**
     * Das Tagesprogramm.
     *
     * @return list<array{titel: string, text: string}>
     */
    public static function programm(string $json): array
    {
        $aus = [];
        foreach ((array) Util::ausJson($json, []) as $tag) {
            $titel = trim((string) (is_array($tag) ? ($tag['titel'] ?? '') : ''));
            $text  = trim((string) (is_array($tag) ? ($tag['text'] ?? '') : ''));
            if ($titel !== '' || $text !== '') {
                $aus[] = ['titel' => $titel, 'text' => $text];
            }
        }
        return $aus;
    }

    /* ------------------------------------------------------ Anmeldung --- */

    /**
     * Anmelden – über die Website oder von Hand im Backend.
     *
     * Ist die Reise voll, kommt die Anmeldung auf die Warteliste, sofern
     * die Reise eine führt. Andernfalls wird abgelehnt: Eine Anmeldung
     * anzunehmen, für die es keinen Platz gibt, wäre ein Versprechen, das
     * niemand einlöst.
     *
     * @param  array<string,mixed> $daten
     * @return array{0:int, 1:string} Kennung und Meldung; 0 heißt abgelehnt
     */
    public static function anmelden(int $tripId, array $daten): array
    {
        $reise = Tenant::find('trips', $tripId);
        if ($reise === null) {
            return [0, 'Diese Reise gibt es nicht mehr.'];
        }
        if ((string) $reise['status'] === 'abgesagt') {
            return [0, 'Diese Reise wurde abgesagt.'];
        }
        if (strtotime((string) $reise['start']) < time()) {
            return [0, 'Diese Reise hat bereits begonnen.'];
        }

        $zimmer = (string) ($daten['zimmer'] ?? 'dz') === 'ez' ? 'ez' : 'dz';
        $frei   = self::freiePlaetze($reise);
        $status = $frei > 0 ? 'angemeldet' : 'warteliste';

        if ($frei <= 0 && (int) $reise['warteliste'] !== 1) {
            return [0, 'Diese Reise ist ausgebucht.'];
        }

        $kundeId = (int) ($daten['customer_id'] ?? 0);

        /* Doppelte Anmeldung derselben Person abfangen – zweimal auf
           „Absenden" ist kein zweiter Reisewunsch. */
        $schon = Tenant::one('trip_signups',
            "trip_id = :t AND email = :e AND status != 'storniert'",
            ['t' => $tripId, 'e' => strtolower(trim((string) ($daten['email'] ?? '')))]);
        if ($schon !== null) {
            return [(int) $schon['id'], 'Du bist für diese Reise bereits angemeldet.'];
        }

        $id = Tenant::insert('trip_signups', [
            'trip_id'      => $tripId,
            'customer_id'  => $kundeId,
            'name'         => trim((string) ($daten['name'] ?? '')),
            'email'        => strtolower(trim((string) ($daten['email'] ?? ''))),
            'telefon'      => trim((string) ($daten['telefon'] ?? '')),
            'zimmer'       => $zimmer,
            'mitreisender' => trim((string) ($daten['mitreisender'] ?? '')),
            'hcp'          => trim((string) ($daten['hcp'] ?? '')),
            'notiz'        => trim((string) ($daten['notiz'] ?? '')),
            'preis_cent'   => self::preis($reise, $zimmer),
            'status'       => $status,
        ]);

        if ($kundeId > 0) {
            Customers::aktivitaet($kundeId);
        }
        Audit::schreiben('erstellt', 'trip_signup', $id,
            (string) $reise['titel'] . ' · ' . (string) ($daten['name'] ?? ''));
        Notify::senden('booking',
            $status === 'warteliste' ? 'Warteliste: ' . $reise['titel'] : 'Reiseanmeldung: ' . $reise['titel'],
            (string) ($daten['name'] ?? '') . ' · ' . (self::ZIMMER[$zimmer] ?? $zimmer),
            '/app/reise.php?id=' . $tripId);

        return [$id, $status === 'warteliste'
            ? 'Die Reise ist ausgebucht – du stehst auf der Warteliste und rückst nach, sobald ein Platz frei wird.'
            : ''];
    }

    /** Vom Pro bestätigt, storniert oder von der Warteliste nachgerückt. */
    public static function statusSetzen(int $anmeldungId, string $status): void
    {
        $erlaubt = ['angemeldet', 'bestaetigt', 'warteliste', 'storniert'];
        if (!in_array($status, $erlaubt, true)) {
            return;
        }
        Tenant::update('trip_signups', $anmeldungId, ['status' => $status]);
        Audit::schreiben('geaendert', 'trip_signup', $anmeldungId, $status);
    }
}
