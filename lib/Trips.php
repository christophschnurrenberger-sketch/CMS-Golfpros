<?php
/**
 * Golfreisen – mehrtägige Reisen mit Buchung, Zimmerwahl und Anzahlung.
 *
 * Warum eine eigene Klasse und nicht eine Art von Event: Ein Event hat
 * einen Tag, eine Uhrzeit und einen Preis. Eine Reise hat ein Ziel, ein
 * Hotel, Golfplätze, Nächte, mehrere Preise (Golfer, Nichtgolfer,
 * Einzelzimmer, Frühbucher, Zusatzleistungen), eine Anzahlung und eine
 * Restzahlung, eine Mindestteilnehmerzahl und einen Ablauf über mehrere
 * Tage. Das in die Eventtabelle zu drücken hieße, dort zwanzig Spalten zu
 * führen, die für jeden Workshop leer bleiben.
 *
 * Seit Schema 10 ist eine Buchung nicht mehr eine Person, sondern das, was
 * man im Reisebüro bucht: bis zu sechs Reisende, ein Zimmerwunsch,
 * gewählte Zusatzleistungen. Belegte Plätze zählen deshalb Personen, nicht
 * Zeilen. Den Preis rechnet genau eine Stelle aus – `berechnen()` –, für
 * die Website, das Backend, die E-Mail und das Portal.
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

    public const STATUS = [
        'geplant'         => 'Geplant',
        'veroeffentlicht' => 'Veröffentlicht',
        'abgeschlossen'   => 'Abgeschlossen',
        'abgesagt'        => 'Abgesagt',
    ];

    public const BUCHUNGSSTATUS = [
        'angemeldet' => 'Angemeldet',
        'bestaetigt' => 'Bestätigt',
        'warteliste' => 'Warteliste',
        'storniert'  => 'Storniert',
    ];

    public const EXTRA_JE = [
        'person'  => 'je Person',
        'buchung' => 'je Buchung',
    ];

    /** So viele Reisende nimmt eine Buchung auf – mehr sind eine Gruppe, und die ruft an. */
    public const PERSONEN_MAX = 6;

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
            'leistungen', 'nicht_enthalten', 'programm', 'bild', 'bilder', 'highlights',
            'hotel_sterne', 'hotel_text', 'hotel_bild', 'hotel_url', 'golfplaetze',
            'start', 'ende', 'naechte', 'anreise', 'abflug_ort',
            'preis_cent', 'ez_zuschlag_cent', 'anzahlung_cent', 'preis_nichtgolfer_cent',
            'fruehbucher_bis', 'fruehbucher_rabatt_cent', 'extras', 'restzahlung_tage', 'hinweise',
            'plaetze', 'mindest_teilnehmer', 'trainer_id', 'warteliste', 'status',
        ]));

        if (isset($satz['status']) && !isset(self::STATUS[(string) $satz['status']])) {
            $satz['status'] = 'geplant';
        }
        if (isset($satz['hotel_sterne'])) {
            $satz['hotel_sterne'] = max(0, min(5, (int) $satz['hotel_sterne']));
        }

        /*
         * Der Kurzname in der Adresse folgt dem Titel – aber nur beim
         * Anlegen und beim Umbenennen. Sonst bräche jeder verschickte Link
         * auf die Reise, sobald jemand den Titel nachschärft.
         */
        if (isset($daten['titel']) && (string) $daten['titel'] !== '') {
            $alt = $id > 0 ? Tenant::find('trips', $id) : null;
            if ($alt === null || (string) $alt['titel'] !== (string) $daten['titel'] || (string) $alt['slug'] === '') {
                $satz['slug'] = self::freierSlug(Util::slug((string) $daten['titel']), $id);
            }
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

    /** Belegte Plätze – Personen, nicht Buchungen. Warteliste und Stornos zählen nicht mit. */
    public static function belegt(int $tripId): int
    {
        return Tenant::sum('trip_signups', 'personen',
            "trip_id = :t AND status IN ('angemeldet', 'bestaetigt')", ['t' => $tripId]);
    }

    /** @param array<string,mixed> $reise */
    public static function freiePlaetze(array $reise): int
    {
        return max(0, (int) $reise['plaetze'] - self::belegt((int) $reise['id']));
    }

    /** Summe der bestätigten und angemeldeten Buchungen. */
    public static function umsatz(int $tripId): int
    {
        return (int) Tenant::sum('trip_signups', 'preis_cent',
            "trip_id = :t AND status IN ('angemeldet', 'bestaetigt')", ['t' => $tripId]);
    }

    /**
     * Fehlen noch Teilnehmer, damit die Reise stattfindet?
     *
     * @param array<string,mixed> $reise
     */
    public static function fehlend(array $reise): int
    {
        return max(0, (int) $reise['mindest_teilnehmer'] - self::belegt((int) $reise['id']));
    }

    /* --------------------------------------------------- Inhalte lesen --- */

    /**
     * Listen aus dem Datensatz – Leistungen, Nichtenthaltenes, Highlights.
     * Leere Zeilen fliegen raus.
     *
     * @return list<string>
     */
    public static function zeilen(string $json): array
    {
        $aus = [];
        foreach ((array) Util::ausJson($json, []) as $z) {
            $z = trim(is_scalar($z) ? (string) $z : '');
            if ($z !== '') {
                $aus[] = $z;
            }
        }
        return $aus;
    }

    /**
     * Das Tagesprogramm – je Tag Titel, Text und, wenn gewünscht, ein Bild.
     *
     * @return list<array{titel: string, text: string, bild: string}>
     */
    public static function programm(string $json): array
    {
        $aus = [];
        foreach ((array) Util::ausJson($json, []) as $tag) {
            $tag   = is_array($tag) ? $tag : [];
            $titel = trim((string) ($tag['titel'] ?? ''));
            $text  = trim((string) ($tag['text'] ?? ''));
            $bild  = self::pfad((string) ($tag['bild'] ?? ''));
            if ($titel !== '' || $text !== '') {
                $aus[] = ['titel' => $titel, 'text' => $text, 'bild' => $bild];
            }
        }
        return $aus;
    }

    /**
     * Die Galerie, ohne das Titelbild.
     *
     * @param array<string,mixed> $reise
     * @return list<string>
     */
    public static function galerie(array $reise): array
    {
        $aus = [];
        foreach ((array) Util::ausJson((string) ($reise['bilder'] ?? ''), []) as $b) {
            $b = self::pfad(is_scalar($b) ? (string) $b : '');
            if ($b !== '' && $b !== (string) $reise['bild'] && !in_array($b, $aus, true)) {
                $aus[] = $b;
            }
        }
        return $aus;
    }

    /**
     * Die Golfplätze der Reise.
     *
     * @return list<array{name: string, loecher: int, par: int, text: string, bild: string}>
     */
    public static function golfplaetze(string $json): array
    {
        $aus = [];
        foreach ((array) Util::ausJson($json, []) as $g) {
            $g = is_array($g) ? $g : [];
            $name = trim((string) ($g['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $aus[] = [
                'name'    => $name,
                'loecher' => max(0, min(36, (int) ($g['loecher'] ?? 18))),
                'par'     => max(0, min(144, (int) ($g['par'] ?? 0))),
                'text'    => trim((string) ($g['text'] ?? '')),
                'bild'    => self::pfad((string) ($g['bild'] ?? '')),
            ];
        }
        return $aus;
    }

    /**
     * Die buchbaren Zusatzleistungen.
     *
     * @return list<array{name: string, preis_cent: int, je: string}>
     */
    public static function extras(string $json): array
    {
        $aus = [];
        foreach ((array) Util::ausJson($json, []) as $x) {
            $x = is_array($x) ? $x : [];
            $name = trim((string) ($x['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $aus[] = [
                'name'       => mb_substr($name, 0, 120),
                'preis_cent' => max(0, (int) ($x['preis_cent'] ?? 0)),
                'je'         => ($x['je'] ?? '') === 'buchung' ? 'buchung' : 'person',
            ];
        }
        return $aus;
    }

    /**
     * Ein Bildpfad, wie er im Datensatz stehen darf.
     *
     * Nur Pfade in der eigenen Anlage oder vollständige https-Adressen. Ein
     * `javascript:` oder ein `//fremd` hätte im src-Attribut nichts zu
     * suchen, auch wenn der Renderer es maskiert.
     */
    public static function pfad(string $pfad): string
    {
        $pfad = trim($pfad);
        if ($pfad === '') {
            return '';
        }
        if (preg_match('#^https://[^\s"\'<>]+$#i', $pfad) === 1) {
            return $pfad;
        }
        $pfad = ltrim($pfad, '/');
        return preg_match('#^(uploads|assets)/[A-Za-z0-9._/-]+$#', $pfad) === 1 && !str_contains($pfad, '..') ? $pfad : '';
    }

    /* ------------------------------------------------------ Preise --- */

    /**
     * Gilt heute der Frühbucherrabatt?
     *
     * @param array<string,mixed> $reise
     */
    public static function istFruehbucher(array $reise, ?int $zeit = null): bool
    {
        $bis = (string) ($reise['fruehbucher_bis'] ?? '');
        if ($bis === '' || (int) ($reise['fruehbucher_rabatt_cent'] ?? 0) <= 0) {
            return false;
        }
        return ($zeit ?? time()) <= strtotime(substr($bis, 0, 10) . ' 23:59:59');
    }

    /**
     * Der Preis, der auf einer Kachel „ab" steht: Golfer im Doppelzimmer,
     * mit Frühbucherrabatt, solange er gilt.
     *
     * @param array<string,mixed> $reise
     */
    public static function abPreis(array $reise, ?int $zeit = null): int
    {
        return max(0, (int) $reise['preis_cent']
            - (self::istFruehbucher($reise, $zeit) ? (int) $reise['fruehbucher_rabatt_cent'] : 0));
    }

    /**
     * Die Reisenden einer Buchung in einheitlicher Form.
     *
     * @param array<int,mixed> $roh
     * @return list<array{name: string, golfer: bool, hcp: string}>
     */
    public static function reisendeAus(array $roh): array
    {
        $aus = [];
        foreach ($roh as $r) {
            $r = is_array($r) ? $r : [];
            $name = trim(preg_replace('/\s+/', ' ', (string) ($r['name'] ?? '')) ?? '');
            if ($name === '') {
                continue;
            }
            $aus[] = [
                'name'   => mb_substr($name, 0, 160),
                'golfer' => !array_key_exists('golfer', $r) || filter_var($r['golfer'], FILTER_VALIDATE_BOOLEAN),
                'hcp'    => mb_substr(trim((string) ($r['hcp'] ?? '')), 0, 16),
            ];
            if (count($aus) >= self::PERSONEN_MAX) {
                break;
            }
        }
        return $aus;
    }

    /**
     * Die Reisenden einer gespeicherten Buchung – auch einer von vor
     * Schema 10, die nur einen Namen und einen „Mitreisenden" kannte.
     *
     * @param array<string,mixed> $buchung
     * @return list<array{name: string, golfer: bool, hcp: string}>
     */
    public static function reisende(array $buchung): array
    {
        $liste = self::reisendeAus((array) Util::ausJson((string) ($buchung['reisende'] ?? ''), []));
        if ($liste !== []) {
            return $liste;
        }
        $aus = [['name' => (string) $buchung['name'], 'golfer' => true, 'hcp' => (string) ($buchung['hcp'] ?? '')]];
        if (trim((string) ($buchung['mitreisender'] ?? '')) !== '') {
            $aus[] = ['name' => trim((string) $buchung['mitreisender']), 'golfer' => true, 'hcp' => ''];
        }
        return $aus;
    }

    /**
     * Was eine Buchung kostet – die eine Stelle dafür.
     *
     * Jede Zeile nennt Menge und Einzelpreis, damit man auf der Website,
     * im Backend und in der E-Mail dieselbe Rechnung nachvollziehen kann.
     * Zusatzleistungen werden über ihre Nummer in der Liste der Reise
     * gewählt; Name und Preis kommen immer aus der Reise, nie aus dem
     * Formular.
     *
     * @param array<string,mixed> $reise
     * @param array{reisende?: array<int,mixed>, zimmer?: string, extras?: array<int|string,mixed>} $wahl
     * @return array{posten: list<array{0:string,1:int,2:int,3:int}>, summe: int, rabatt: int,
     *               anzahlung: int, rest: int, rest_faellig: string, personen: int,
     *               golfer: int, nichtgolfer: int, zimmer: string,
     *               extras: list<array{name:string, preis_cent:int, anzahl:int}>, reisende: list<array>}
     */
    public static function berechnen(array $reise, array $wahl, ?int $zeit = null): array
    {
        $reisende = self::reisendeAus((array) ($wahl['reisende'] ?? []));
        $n        = max(1, count($reisende));
        $zimmer   = ($wahl['zimmer'] ?? 'dz') === 'ez' ? 'ez' : 'dz';
        $golfer   = count(array_filter($reisende, static fn ($r) => $r['golfer']));
        if ($reisende === []) {
            $golfer = 1;
        }
        $nicht    = $n - $golfer;

        $preisGolfer = (int) $reise['preis_cent'];
        $preisNicht  = (int) ($reise['preis_nichtgolfer_cent'] ?? 0) > 0 ? (int) $reise['preis_nichtgolfer_cent'] : $preisGolfer;
        $posten = [];
        $zimmerWort = $zimmer === 'ez' ? '' : ' im Doppelzimmer';
        if ($golfer > 0) {
            $posten[] = ['Reisepreis Golfer' . $zimmerWort, $golfer, $preisGolfer, $golfer * $preisGolfer];
        }
        if ($nicht > 0) {
            $posten[] = ['Reisepreis Nichtgolfer' . $zimmerWort, $nicht, $preisNicht, $nicht * $preisNicht];
        }
        if ($zimmer === 'ez' && (int) $reise['ez_zuschlag_cent'] > 0) {
            $posten[] = ['Einzelzimmerzuschlag', $n, (int) $reise['ez_zuschlag_cent'], $n * (int) $reise['ez_zuschlag_cent']];
        }

        $gewaehlt = [];
        $angebot  = self::extras((string) ($reise['extras'] ?? ''));
        foreach ((array) ($wahl['extras'] ?? []) as $nr => $anzahl) {
            $nr = (int) $nr;
            if (!isset($angebot[$nr])) {
                continue;
            }
            $x = $angebot[$nr];
            $anzahl = max(0, min($x['je'] === 'buchung' ? 1 : $n, (int) $anzahl));
            if ($anzahl === 0) {
                continue;
            }
            $posten[] = [$x['name'], $anzahl, $x['preis_cent'], $anzahl * $x['preis_cent']];
            $gewaehlt[] = ['name' => $x['name'], 'preis_cent' => $x['preis_cent'], 'anzahl' => $anzahl];
        }

        $rabatt = 0;
        if (self::istFruehbucher($reise, $zeit)) {
            $rabatt = $n * (int) $reise['fruehbucher_rabatt_cent'];
            $posten[] = ['Frühbucherrabatt bis ' . Util::datum((string) $reise['fruehbucher_bis']), $n,
                -(int) $reise['fruehbucher_rabatt_cent'], -$rabatt];
        }

        $summe = max(0, array_sum(array_map(static fn ($p) => $p[3], $posten)));
        $anzahlung = (int) $reise['anzahlung_cent'] > 0 ? min($summe, $n * (int) $reise['anzahlung_cent']) : 0;

        $heute = date('Y-m-d', $zeit ?? time());
        $faellig = date('Y-m-d', strtotime(substr((string) $reise['start'], 0, 10) . ' -'
            . max(0, (int) ($reise['restzahlung_tage'] ?? 30)) . ' days'));
        if ($faellig < $heute) {
            $faellig = $heute;
        }

        return [
            'posten' => $posten, 'summe' => $summe, 'rabatt' => $rabatt,
            'anzahlung' => $anzahlung, 'rest' => $summe - $anzahlung, 'rest_faellig' => $faellig,
            'personen' => $n, 'golfer' => $golfer, 'nichtgolfer' => $nicht, 'zimmer' => $zimmer,
            'extras' => $gewaehlt, 'reisende' => $reisende,
        ];
    }

    /**
     * Die Preisrechnung als Text – für E-Mails.
     *
     * @param array<string,mixed> $rechnung Ergebnis von berechnen()
     */
    public static function rechnungText(array $rechnung): string
    {
        $t = '';
        foreach ($rechnung['posten'] as [$text, $menge, $einzel, $summe]) {
            $t .= ($menge > 1 ? $menge . ' × ' : '') . $text . ': ' . Util::geld($summe) . "\n";
        }
        $t .= 'Gesamt: ' . Util::geld((int) $rechnung['summe']) . "\n";
        if ((int) $rechnung['anzahlung'] > 0) {
            $t .= 'Anzahlung: ' . Util::geld((int) $rechnung['anzahlung']) . "\n"
                . 'Restzahlung: ' . Util::geld((int) $rechnung['rest']) . ' bis ' . Util::datum((string) $rechnung['rest_faellig']) . "\n";
        }
        return $t;
    }

    /* ------------------------------------------------------ Buchung --- */

    /**
     * Buchen – über die Website oder von Hand im Backend.
     *
     * Reichen die freien Plätze nicht für alle Reisenden, kommt die ganze
     * Buchung auf die Warteliste, sofern die Reise eine führt. Andernfalls
     * wird abgelehnt: Eine Buchung anzunehmen, für die es keinen Platz
     * gibt, wäre ein Versprechen, das niemand einlöst. Eine Buchung wird
     * nie geteilt – wer zu zweit reist, will zu zweit reisen.
     *
     * @param  array<string,mixed> $daten reisende (Liste) oder name (+ mitreisender), email,
     *                                    telefon, zimmer, extras (Nummer => Anzahl), notiz, customer_id
     * @return array{0:int, 1:string, 2:bool} Kennung, Meldung und ob die Buchung neu ist.
     *         0 heißt abgelehnt; eine Kennung mit `false` ist die schon vorhandene
     *         Buchung derselben Adresse – ein zweites „Absenden" legt nichts an.
     */
    public static function anmelden(int $tripId, array $daten, ?int $zeit = null): array
    {
        $reise = Tenant::find('trips', $tripId);
        if ($reise === null) {
            return [0, 'Diese Reise gibt es nicht mehr.', false];
        }
        if ((string) $reise['status'] === 'abgesagt') {
            return [0, 'Diese Reise wurde abgesagt.', false];
        }
        if (strtotime((string) $reise['start']) < ($zeit ?? time())) {
            return [0, 'Diese Reise hat bereits begonnen.', false];
        }

        $roh = (array) ($daten['reisende'] ?? []);
        if ($roh === []) {
            $roh[] = ['name' => (string) ($daten['name'] ?? ''), 'golfer' => true, 'hcp' => (string) ($daten['hcp'] ?? '')];
            if (trim((string) ($daten['mitreisender'] ?? '')) !== '') {
                $roh[] = ['name' => (string) $daten['mitreisender'], 'golfer' => true];
            }
        }
        $reisende = self::reisendeAus($roh);
        if ($reisende === []) {
            return [0, 'Bitte mindestens einen Namen angeben.', false];
        }
        $email = strtolower(trim((string) ($daten['email'] ?? '')));

        $rechnung = self::berechnen($reise, [
            'reisende' => $reisende, 'zimmer' => (string) ($daten['zimmer'] ?? 'dz'),
            'extras' => (array) ($daten['extras'] ?? []),
        ], $zeit);
        $personen = $rechnung['personen'];
        $frei     = self::freiePlaetze($reise);

        if ($frei >= $personen) {
            $status = 'angemeldet';
        } elseif ((int) $reise['warteliste'] === 1) {
            $status = 'warteliste';
        } else {
            return [0, $frei > 0
                ? 'Es sind nur noch ' . $frei . ($frei === 1 ? ' Platz' : ' Plätze') . ' frei.'
                : 'Diese Reise ist ausgebucht.', false];
        }

        /* Doppelte Buchung derselben Adresse abfangen – zweimal auf
           „Absenden" ist kein zweiter Reisewunsch. */
        if ($email !== '') {
            $schon = Tenant::one('trip_signups',
                "trip_id = :t AND email = :e AND status != 'storniert'", ['t' => $tripId, 'e' => $email]);
            if ($schon !== null) {
                return [(int) $schon['id'], 'Für diese Adresse gibt es bereits eine Buchung dieser Reise.', false];
            }
        }

        $kundeId = (int) ($daten['customer_id'] ?? 0);
        $namen = array_column($reisende, 'name');
        $id = Tenant::insert('trip_signups', [
            'trip_id'        => $tripId,
            'customer_id'    => $kundeId,
            'name'           => $namen[0],
            'email'          => $email,
            'telefon'        => trim((string) ($daten['telefon'] ?? '')),
            'zimmer'         => $rechnung['zimmer'],
            'mitreisender'   => mb_substr(implode(', ', array_slice($namen, 1)), 0, 160),
            'hcp'            => $reisende[0]['hcp'],
            'notiz'          => trim((string) ($daten['notiz'] ?? '')),
            'preis_cent'     => $rechnung['summe'],
            'personen'       => $personen,
            'reisende'       => Util::json($reisende),
            'extras'         => Util::json($rechnung['extras']),
            'rabatt_cent'    => $rechnung['rabatt'],
            'anzahlung_cent' => $rechnung['anzahlung'],
            'rest_faellig'   => $rechnung['rest_faellig'] . ' 00:00:00',
            'status'         => $status,
        ]);

        if ($kundeId > 0) {
            Customers::aktivitaet($kundeId);
        }
        Audit::schreiben('erstellt', 'trip_signup', $id, (string) $reise['titel'] . ' · ' . $namen[0]);
        Notify::senden('booking',
            ($status === 'warteliste' ? 'Warteliste: ' : 'Reisebuchung: ') . $reise['titel'],
            $namen[0] . ($personen > 1 ? ' + ' . ($personen - 1) : '') . ' · '
            . (self::ZIMMER[$rechnung['zimmer']] ?? '') . ' · ' . Util::geld($rechnung['summe']),
            '/app/reise.php?id=' . $tripId . '#buchungen');

        return [$id, $status === 'warteliste'
            ? ($frei > 0
                ? 'Es sind nur noch ' . $frei . ($frei === 1 ? ' Platz' : ' Plätze') . ' frei – eure Buchung steht auf der Warteliste und rückt nach, sobald genug Plätze frei werden.'
                : 'Die Reise ist ausgebucht – du stehst auf der Warteliste und rückst nach, sobald ein Platz frei wird.')
            : '', true];
    }

    /** Vom Pro bestätigt, storniert oder von der Warteliste nachgerückt. */
    public static function statusSetzen(int $anmeldungId, string $status): bool
    {
        if (!isset(self::BUCHUNGSSTATUS[$status])) {
            return false;
        }
        Tenant::update('trip_signups', $anmeldungId, ['status' => $status]);
        Audit::schreiben('geaendert', 'trip_signup', $anmeldungId, $status);
        return true;
    }

    /** Anzahlung oder Restzahlung als eingegangen vermerken – oder zurücknehmen. */
    public static function zahlungSetzen(int $anmeldungId, string $was, bool $bezahlt): void
    {
        $spalte = $was === 'rest' ? 'bezahlt' : 'anzahlung_bezahlt';
        Tenant::update('trip_signups', $anmeldungId, [$spalte => $bezahlt ? 1 : 0]);
        Audit::schreiben('geaendert', 'trip_signup', $anmeldungId,
            ($was === 'rest' ? 'Restzahlung ' : 'Anzahlung ') . ($bezahlt ? 'eingegangen' : 'offen'));
    }

    /**
     * Die Bestätigung an die Reisenden: was gebucht ist, was es kostet,
     * wann was zu zahlen ist und wohin.
     *
     * @param array<string,mixed> $buchung
     * @param array<string,mixed> $reise
     */
    public static function bestaetigungText(array $buchung, array $reise): string
    {
        $reisende = self::reisende($buchung);
        $anzahlung = (int) $buchung['anzahlung_cent'];
        $summe = (int) $buchung['preis_cent'];
        $bank = trim((string) Tenant::einstellung('bank', ''));
        $vorname = explode(' ', (string) $buchung['name'])[0];

        $t = 'Hallo ' . $vorname . ",\n\n"
           . "ich freue mich: Eure Reise ist bestätigt.\n\n"
           . (string) $reise['titel'] . "\n"
           . trim((string) $reise['ziel'] . ', ' . (string) $reise['land'], ', ') . "\n"
           . Util::datumLang((string) $reise['start']) . ' bis ' . Util::datumLang((string) $reise['ende'])
           . ' · ' . (int) $reise['naechte'] . " Nächte\n"
           . ((string) $reise['hotel'] !== '' ? 'Hotel: ' . (string) $reise['hotel'] . "\n" : '')
           . "\nReisende:\n";
        foreach ($reisende as $r) {
            $t .= '· ' . $r['name'] . ($r['golfer'] ? ($r['hcp'] !== '' ? ' (HCP ' . $r['hcp'] . ')' : '') : ' (Nichtgolfer)') . "\n";
        }
        $t .= 'Zimmer: ' . (self::ZIMMER[(string) $buchung['zimmer']] ?? (string) $buchung['zimmer']) . "\n";
        foreach ((array) Util::ausJson((string) ($buchung['extras'] ?? ''), []) as $x) {
            $t .= 'Dazu: ' . (int) ($x['anzahl'] ?? 1) . ' × ' . (string) ($x['name'] ?? '') . "\n";
        }
        $t .= "\nReisepreis gesamt: " . Util::geld($summe) . "\n";
        if ($anzahlung > 0) {
            $t .= 'Anzahlung jetzt: ' . Util::geld($anzahlung) . "\n"
                . 'Restzahlung: ' . Util::geld($summe - $anzahlung) . ' bis ' . Util::datum((string) $buchung['rest_faellig']) . "\n";
        } else {
            $t .= 'Zahlbar bis ' . Util::datum((string) $buchung['rest_faellig']) . "\n";
        }
        if ($bank !== '') {
            $t .= "\nBitte überweise auf: " . $bank . "\nVerwendungszweck: " . (string) $reise['titel'] . ' · ' . (string) $buchung['name'] . "\n";
        }
        return $t . "\nAlle weiteren Unterlagen bekommst du rechtzeitig vor der Abreise.\n\nBis bald\n" . Tenant::name();
    }

    /* ------------------------------------------------------- Listen --- */

    /**
     * Teilnehmer- oder Zimmerliste als CSV – für das Hotel, den Bus, die
     * Startzeiten. Semikolon und BOM, damit Excel sie ohne Umwege öffnet.
     *
     * Werte, die mit = + - @ beginnen, bekommen ein Hochkomma davor: Ein
     * Name wie „=HYPERLINK(…)" aus dem Anmeldeformular wäre in Excel
     * sonst eine Formel.
     *
     * @param array<string,mixed> $reise
     */
    public static function csv(array $reise, string $art): string
    {
        $sicher = static fn ($v) => preg_match('/^[=+\-@\t\r]/', (string) $v) === 1 ? "'" . $v : (string) $v;
        $aus = fopen('php://temp', 'r+');
        fwrite($aus, "\xEF\xBB\xBF");
        $buchungen = array_values(array_filter(self::anmeldungen((int) $reise['id']),
            static fn ($b) => in_array((string) $b['status'], ['angemeldet', 'bestaetigt'], true)));

        if ($art === 'zimmer') {
            fputcsv($aus, ['Zimmer', 'Art', 'Belegung', 'Anreise', 'Abreise', 'Nächte', 'Buchung'], ';');
            $nr = 0;
            foreach ($buchungen as $b) {
                $namen = array_column(self::reisende($b), 'name');
                /* Im Doppelzimmer zu zweit, im Einzelzimmer jeder für sich. */
                $gruppen = (string) $b['zimmer'] === 'ez' ? array_map(static fn ($n) => [$n], $namen) : array_chunk($namen, 2);
                foreach ($gruppen as $g) {
                    $nr++;
                    fputcsv($aus, array_map($sicher, [$nr, self::ZIMMER[(string) $b['zimmer']] ?? '', implode(' & ', $g),
                        Util::datum((string) $reise['start']), Util::datum((string) $reise['ende']), (int) $reise['naechte'],
                        '#' . (int) $b['id']]), ';');
                }
            }
        } else {
            fputcsv($aus, ['Name', 'Golfer', 'HCP', 'Zimmer', 'Buchung', 'E-Mail', 'Telefon', 'Status',
                'Anzahlung', 'Restzahlung', 'Zusatzleistungen', 'Anmerkung'], ';');
            foreach ($buchungen as $b) {
                $extras = implode(', ', array_map(static fn ($x) => (int) ($x['anzahl'] ?? 1) . '× ' . ($x['name'] ?? ''),
                    (array) Util::ausJson((string) ($b['extras'] ?? ''), [])));
                foreach (self::reisende($b) as $i => $r) {
                    fputcsv($aus, array_map($sicher, [$r['name'], $r['golfer'] ? 'ja' : 'nein', $r['hcp'],
                        self::ZIMMER[(string) $b['zimmer']] ?? '', '#' . (int) $b['id'],
                        $i === 0 ? $b['email'] : '', $i === 0 ? $b['telefon'] : '',
                        self::BUCHUNGSSTATUS[(string) $b['status']] ?? '',
                        $i === 0 ? ((int) $b['anzahlung_bezahlt'] === 1 ? 'bezahlt' : 'offen') : '',
                        $i === 0 ? ((int) $b['bezahlt'] === 1 ? 'bezahlt' : 'offen') : '',
                        $i === 0 ? $extras : '', $i === 0 ? (string) $b['notiz'] : '']), ';');
                }
            }
        }
        rewind($aus);
        return (string) stream_get_contents($aus);
    }
}
