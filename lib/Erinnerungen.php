<?php
/**
 * Erinnerungen – wer wird wann und auf welchem Weg an seinen Termin erinnert.
 *
 * Zwei Dinge werden hier bewusst getrennt gehalten:
 *
 *   **Der Wunsch** steht am Termin (`bookings.erinnerungen`, JSON): „einen Tag
 *   vorher per E-Mail und SMS". Ist dort nichts hinterlegt, gilt die Vorgabe
 *   des Workspace.
 *
 *   **Der Plan** steht in `reminders`: eine Zeile je Kunde, Vorlauf und Kanal,
 *   mit Fälligkeit und Ergebnis. Diese Zeilen sind gleichzeitig das Protokoll –
 *   am Termin ist danach ablesbar, was wann rausging und was nicht.
 *
 * Warum nicht weiter zwei Spalten am Termin wie früher (`erinnerung_24`,
 * `erinnerung_1`)? Weil jede neue Vorlaufzeit eine neue Spalte gebraucht
 * hätte, jeder zusätzliche Kanal noch eine, und weil sich mit zwei
 * Zeitstempeln nicht festhalten lässt, dass die SMS scheiterte, während die
 * E-Mail ankam.
 *
 * Versendet wird aus `Wartung::laufen()` heraus – beim Öffnen des Dashboards
 * oder aus einem Cronjob auf `cron.php`. Fällige Erinnerungen holt sich diese
 * Klasse selbst; niemand muss einen Termin „anstoßen".
 */
final class Erinnerungen
{
    /**
     * Die Vorlaufzeiten zur Auswahl, in Minuten.
     *
     * Eine feste Liste statt eines freien Feldes: „3 Tage, 4 Stunden und 12
     * Minuten vorher" hilft niemandem, und eine Auswahl ist auf dem Telefon
     * mit einem Tipp erledigt.
     */
    public const VORLAUF = [
        10080 => 'Eine Woche vorher',
        4320  => 'Drei Tage vorher',
        2880  => 'Zwei Tage vorher',
        1440  => 'Einen Tag vorher',
        240   => 'Vier Stunden vorher',
        120   => 'Zwei Stunden vorher',
        60    => 'Eine Stunde vorher',
        30    => 'Eine halbe Stunde vorher',
    ];

    /** Voreinstellung, wenn nie etwas anderes eingestellt wurde. */
    public const VORGABE_VORLAUF  = [1440];
    public const VORGABE_KANAELE  = ['email', 'sms'];

    /** Höchstens so oft wird ein misslungener Versand wiederholt. */
    private const VERSUCHE = 3;

    /* ----------------------------------------------------- Vorgaben */

    public static function aktiv(): bool
    {
        return (bool) Tenant::einstellung('erinnerungen_aktiv', true);
    }

    /** @return array<int,int> Vorlaufzeiten in Minuten, absteigend */
    public static function vorgabeVorlauf(): array
    {
        $liste = Tenant::einstellung('erinnerung_vorlauf', null);
        if (!is_array($liste)) {
            $liste = is_string($liste) ? Util::ausJson($liste, self::VORGABE_VORLAUF) : self::VORGABE_VORLAUF;
        }
        return self::vorlaufSaeubern($liste);
    }

    /** @return array<int,string> */
    public static function vorgabeKanaele(): array
    {
        $liste = Tenant::einstellung('erinnerung_kanaele', null);
        if (!is_array($liste)) {
            $liste = is_string($liste) ? Util::ausJson($liste, self::VORGABE_KANAELE) : self::VORGABE_KANAELE;
        }
        return self::kanaeleSaeubern($liste);
    }

    /**
     * Die Regeln eines Termins: was am Termin steht, sonst die Vorgabe.
     *
     * @return array<int,array{vorlauf:int,kanaele:array<int,string>}>
     */
    public static function regeln(array $termin): array
    {
        $eigen = (string) ($termin['erinnerungen'] ?? '');
        if ($eigen !== '') {
            return self::regelnSaeubern(Util::ausJson($eigen, []));
        }
        if (!self::aktiv()) {
            return [];
        }
        $kanaele = self::vorgabeKanaele();
        $regeln  = [];
        foreach (self::vorgabeVorlauf() as $vorlauf) {
            $regeln[] = ['vorlauf' => $vorlauf, 'kanaele' => $kanaele];
        }
        return $regeln;
    }

    /* ------------------------------------------------------- Planen */

    /**
     * Legt die Erinnerungen eines Termins neu an.
     *
     * Was schon versendet (oder endgültig gescheitert) ist, bleibt stehen –
     * das ist Protokoll. Neu geplant wird nur, was noch offen war.
     *
     * @param array<int,array{vorlauf:int,kanaele:array<int,string>}>|null $regeln
     *        null: die Regeln des Termins beziehungsweise die Vorgabe
     * @return int Anzahl der geplanten Erinnerungen
     */
    public static function planen(int $bookingId, ?array $regeln = null): int
    {
        $termin = Tenant::find('bookings', $bookingId);
        if (!$termin) {
            return 0;
        }
        /*
         * Weg kommt, was noch offen war – und das, was am alten Plan
         * entfallen ist. Stehen bleiben `gesendet` und `fehlgeschlagen`
         * (das wurde wirklich versucht) sowie `abgesagt` (dazu gehört ein
         * Ereignis, nämlich die Absage des Termins).
         *
         * Ohne die „entfällt"-Zeilen wuchs die Liste bei jedem Neuplanen:
         * Ein Termin, dessen Vorlauf längst abgelaufen ist, bekam bei jeder
         * Änderung der Vorgabe zwei weitere davon dazu.
         */
        Tenant::deleteWhere('reminders',
            "booking_id = :b AND status IN ('geplant', 'uebersprungen')",
            ['b' => $bookingId]);

        if ((string) $termin['status'] === 'abgesagt') {
            return 0;
        }
        $regeln = $regeln === null ? self::regeln($termin) : self::regelnSaeubern($regeln);
        if ($regeln === []) {
            return 0;
        }

        $start = strtotime((string) $termin['start']);
        $jetzt = time();
        $anzahl = 0;

        foreach (self::empfaenger($bookingId, (int) $termin['customer_id']) as $kundeId) {
            foreach ($regeln as $regel) {
                $faellig = $start - $regel['vorlauf'] * 60;
                foreach ($regel['kanaele'] as $kanal) {
                    /*
                     * Ein Vorlauf, der beim Buchen schon abgelaufen ist, wird
                     * nicht sofort versendet: „Erinnerung: morgen" zwei Stunden
                     * vor dem Termin ist schlimmer als gar keine Erinnerung.
                     * Die Zeile entsteht trotzdem, damit am Termin steht,
                     * warum nichts kommt.
                     */
                    $zuSpaet = $faellig <= $jetzt;
                    Tenant::insert('reminders', [
                        'booking_id'  => $bookingId,
                        'customer_id' => $kundeId,
                        'vorlauf_min' => $regel['vorlauf'],
                        'kanal'       => $kanal,
                        'faellig'     => date('Y-m-d H:i:s', $faellig),
                        'status'      => $zuSpaet ? 'uebersprungen' : 'geplant',
                        'grund'       => $zuSpaet ? 'Der Termin lag beim Anlegen schon näher als der Vorlauf.' : '',
                    ]);
                    $anzahl += $zuSpaet ? 0 : 1;
                }
            }
        }
        return $anzahl;
    }

    /**
     * Nach dem Verschieben eines Termins.
     *
     * Die Regeln bleiben, die Fälligkeiten wandern mit. Eine Erinnerung, die
     * für den alten Zeitpunkt schon rausging, bleibt im Protokoll stehen und
     * wird für den neuen Zeitpunkt erneut geplant – der Kunde hat den alten
     * Termin im Kalender und muss vom neuen erfahren.
     */
    public static function neuPlanen(int $bookingId): int
    {
        return self::planen($bookingId);
    }

    /** Alles, was noch nicht raus ist, verfällt mit der Absage. */
    public static function absagen(int $bookingId): int
    {
        return Tenant::updateWhere('reminders',
            ['status' => 'abgesagt', 'grund' => 'Der Termin wurde abgesagt.'],
            'booking_id = :b AND status = :s', ['b' => $bookingId, 's' => 'geplant']);
    }

    /**
     * Speichert die Regeln am Termin und plant neu.
     *
     * @param array<int,array{vorlauf:int,kanaele:array<int,string>}> $regeln
     */
    public static function speichern(int $bookingId, array $regeln): int
    {
        $regeln = self::regelnSaeubern($regeln);
        Tenant::update('bookings', $bookingId, ['erinnerungen' => Util::json($regeln)]);
        return self::planen($bookingId, $regeln);
    }

    /**
     * Nach einer Änderung der Vorgabe: alle künftigen Termine neu planen,
     * die keine eigene Regel haben.
     *
     * Ohne das gälte eine neu eingestellte Vorlaufzeit erst für Termine, die
     * danach gebucht werden – und der Pro, der gerade „zwei Tage vorher"
     * eingestellt hat, wundert sich, warum an seinen Terminen weiter „einen
     * Tag vorher" steht.
     */
    public static function vorgabeNeuPlanen(int $limit = 500): int
    {
        $anzahl = 0;
        foreach (Tenant::all('bookings',
            "status IN ('bestaetigt','angefragt') AND start > :jetzt"
            . " AND (erinnerungen IS NULL OR erinnerungen = '')",
            ['jetzt' => Util::jetzt()], 'start', $limit) as $termin) {
            $anzahl += self::planen((int) $termin['id']);
        }
        return $anzahl;
    }

    /** @return array<int,array<string,mixed>> */
    public static function fuerTermin(int $bookingId): array
    {
        return Tenant::all('reminders', 'booking_id = :b', ['b' => $bookingId], 'faellig, kanal');
    }

    /* ------------------------------------------------------ Versenden */

    /**
     * Versendet, was fällig ist.
     *
     * Die Stückzahl ist begrenzt, weil das hier im Seitenaufbau des
     * Dashboards mitläuft: Niemand soll auf hundert SMS warten. Was
     * übrig bleibt, geht beim nächsten Durchlauf raus.
     */
    public static function versenden(int $limit = 25): int
    {
        $faellig = Tenant::all('reminders',
            "status = 'geplant' AND faellig <= :jetzt", ['jetzt' => Util::jetzt()], 'faellig', $limit);

        $raus = 0;
        foreach ($faellig as $zeile) {
            if (self::einzelnVersenden($zeile)) {
                $raus++;
            }
        }
        return $raus;
    }

    /**
     * Eine einzelne Erinnerung – auch von Hand aus der Terminansicht heraus.
     *
     * @return array{0:bool,1:string}
     */
    public static function jetztSenden(int $reminderId): array
    {
        $zeile = Tenant::find('reminders', $reminderId);
        if (!$zeile) {
            return [false, 'Diese Erinnerung wurde nicht gefunden.'];
        }
        $ok = self::einzelnVersenden($zeile, true);
        $neu = Tenant::find('reminders', $reminderId);
        return [$ok, $ok ? '' : (string) ($neu['grund'] ?? 'Der Versand ist nicht gelungen.')];
    }

    private static function einzelnVersenden(array $zeile, bool $vonHand = false): bool
    {
        $id     = (int) $zeile['id'];
        $termin = Tenant::find('bookings', (int) $zeile['booking_id']);
        $kanal  = (string) $zeile['kanal'];

        if (!$termin || (string) $termin['status'] === 'abgesagt') {
            self::abschliessen($id, 'abgesagt', 'Der Termin besteht nicht mehr.');
            return false;
        }
        /* Eine Anfrage ist noch kein Termin. Wer sie nicht bestätigt hat,
           will den Kunden nicht an etwas erinnern, das vielleicht nicht
           stattfindet. */
        if (!$vonHand && (string) $termin['status'] === 'angefragt') {
            self::abschliessen($id, 'uebersprungen', 'Der Termin war zur Fälligkeit noch nicht bestätigt.');
            return false;
        }
        if (!$vonHand && strtotime((string) $termin['start']) < time()) {
            self::abschliessen($id, 'uebersprungen', 'Der Termin war vorbei, bevor die Erinnerung rausging.');
            return false;
        }

        $kunde = Tenant::find('customers', (int) $zeile['customer_id']);
        if (!$kunde) {
            self::abschliessen($id, 'uebersprungen', 'Zu diesem Termin ist kein Kunde hinterlegt.');
            return false;
        }
        if (Kanaele::stumm()) {
            self::abschliessen($id, 'uebersprungen', Kanaele::STUMM_GRUND);
            return false;
        }
        if (!Kanaele::eingerichtet($kanal)) {
            self::abschliessen($id, 'uebersprungen', Kanaele::zustand($kanal)[1]);
            return false;
        }
        if (!Kanaele::erreichbar($kunde, $kanal)) {
            self::abschliessen($id, 'uebersprungen', $kanal === 'email'
                ? 'Für diesen Kunden ist keine E-Mail-Adresse hinterlegt.'
                : 'Für diesen Kunden fehlt eine Telefonnummer mit Vorwahl.');
            return false;
        }

        [$betreff, $text, $kurz, $parameter] = self::nachricht($termin, $kunde, (int) $zeile['vorlauf_min']);
        [$ok, $grund] = Kanaele::senden($kunde, $kanal, $betreff, $text,
            ['kurz' => $kurz, 'parameter' => $parameter]);

        if ($ok) {
            self::abschliessen($id, 'gesendet', '');
            return true;
        }

        /*
         * Ein misslungener Versand ist selten endgültig – ein Anbieter kann
         * für eine Minute nicht erreichbar sein. Nach drei Anläufen ist es
         * aber kein Aussetzer mehr, und dann muss es jemand erfahren.
         */
        $versuche = (int) $zeile['versuche'] + 1;
        if ($versuche >= self::VERSUCHE) {
            self::abschliessen($id, 'fehlgeschlagen', $grund, $versuche);
            Notify::senden('booking', Kanaele::name($kanal) . '-Erinnerung nicht zugestellt',
                Customers::name($kunde) . ' · ' . Util::datumZeit((string) $termin['start']) . ' · ' . $grund,
                '/app/buchung.php?id=' . (int) $termin['id']);
        } else {
            Tenant::update('reminders', $id, ['versuche' => $versuche, 'grund' => $grund]);
        }
        return false;
    }

    private static function abschliessen(int $id, string $status, string $grund, int $versuche = 0): void
    {
        $daten = ['status' => $status, 'grund' => $grund];
        if ($status === 'gesendet') {
            $daten['gesendet'] = Util::jetzt();
        }
        if ($versuche > 0) {
            $daten['versuche'] = $versuche;
        }
        Tenant::update('reminders', $id, $daten);
    }

    /**
     * Der Wortlaut – einmal lang für die E-Mail, einmal kurz für SMS und
     * WhatsApp.
     *
     * Kurz heißt hier wirklich kurz: Eine SMS kostet ab 160 Zeichen doppelt,
     * und eine Erinnerung, die in zwei Teilen ankommt, sieht nach Panne aus.
     *
     * @return array{0:string,1:string,2:string,3:array<int,string>}
     */
    public static function nachricht(array $termin, array $kunde, int $vorlaufMin): array
    {
        $wann    = self::wann($vorlaufMin);
        $tag     = Util::datumLang((string) $termin['start']);
        $uhr     = Util::uhrzeit((string) $termin['start']);
        $was     = (string) $termin['titel'];
        $schule  = Tenant::name();
        $ort     = '';
        if ((int) $termin['location_id'] > 0) {
            $standort = Tenant::find('locations', (int) $termin['location_id']);
            $ort = $standort ? (string) $standort['name'] : '';
        }

        $betreff = 'Erinnerung: dein Termin ' . $wann;
        $text = 'Hallo ' . (string) $kunde['vorname'] . ",\n\n"
              . 'dein Termin „' . $was . '" ist ' . $wann . ' – am ' . $tag . ' um ' . $uhr . " Uhr"
              . ($ort !== '' ? ', ' . $ort : '') . ".\n\n"
              . 'Passt etwas nicht mehr? Sag bitte rechtzeitig Bescheid, dann wird die Zeit wieder frei.'
              . "\n\nBis dann!\n" . $schule;

        /* „Do., 17.09. um 10:00" – Tag und Uhrzeit reichen, den Rest weiß der Kunde. */
        $kurz = 'Erinnerung: ' . Util::kuerzen($was, 40) . ' ' . $wann . ', '
              . Util::tagKurz((string) $termin['start']) . ' '
              . Util::datum((string) $termin['start'], false) . ' um ' . $uhr . ' Uhr. ' . $schule;

        $parameter = [(string) $kunde['vorname'], $was, $tag, $uhr, $schule];

        return [$betreff, $text, $kurz, $parameter];
    }

    /** „morgen", „in zwei Stunden" – aus dem Vorlauf, nicht aus der Uhr. */
    public static function wann(int $vorlaufMin): string
    {
        if ($vorlaufMin <= 45) {
            return 'gleich';
        }
        if ($vorlaufMin < 1440) {
            $stunden = (int) round($vorlaufMin / 60);
            return $stunden <= 1 ? 'in einer Stunde'
                : 'in ' . self::zahlwort($stunden) . ' Stunden';
        }
        if ($vorlaufMin < 2880) {
            return 'morgen';
        }
        if ($vorlaufMin < 4320) {
            return 'übermorgen';
        }
        $tage = (int) round($vorlaufMin / 1440);
        return $tage === 7 ? 'in einer Woche' : 'in ' . self::zahlwort($tage) . ' Tagen';
    }

    public static function vorlaufName(int $minuten): string
    {
        if (isset(self::VORLAUF[$minuten])) {
            return self::VORLAUF[$minuten];
        }
        return ucfirst(self::wann($minuten)) . ' (' . $minuten . ' Minuten vorher)';
    }

    /**
     * Ein Satz aus Vorlaufzeiten und Kanälen: „Einen Tag vorher per E-Mail
     * und SMS". Steht über jedem Termin und in den Einstellungen – eine
     * Reihe angekreuzter Kästchen liest sich nicht.
     *
     * @param array<int,int>    $vorlauf
     * @param array<int,string> $kanaele
     */
    public static function satz(array $vorlauf, array $kanaele): string
    {
        $vorlauf = self::vorlaufSaeubern($vorlauf);
        $kanaele = self::kanaeleSaeubern($kanaele);
        if ($vorlauf === [] || $kanaele === []) {
            return 'Keine Erinnerung';
        }
        /*
         * Nur der erste Buchstabe wandert nach unten, nicht das ganze Wort:
         * „Einen Tag" wird zu „einen Tag", nicht zu „einen tag". Deutsche
         * Substantive bleiben groß, auch mitten im Satz.
         */
        $zeiten = [];
        foreach ($vorlauf as $minuten) {
            $name = str_replace(' vorher', '', self::vorlaufName($minuten));
            $zeiten[] = mb_strtolower(mb_substr($name, 0, 1)) . mb_substr($name, 1);
        }
        $wege = [];
        foreach ($kanaele as $kanal) {
            $wege[] = Kanaele::name($kanal);
        }
        $satz = self::undListe($zeiten);
        return mb_strtoupper(mb_substr($satz, 0, 1)) . mb_substr($satz, 1)
             . ' vorher per ' . self::undListe($wege);
    }

    /** „a, b und c" */
    private static function undListe(array $teile): string
    {
        if (count($teile) <= 1) {
            return (string) ($teile[0] ?? '');
        }
        $letztes = array_pop($teile);
        return implode(', ', $teile) . ' und ' . $letztes;
    }

    /** Der Satz zu einem Termin – eigene Regel oder Vorgabe. */
    public static function satzZuTermin(array $termin): string
    {
        $regeln  = self::regeln($termin);
        $vorlauf = array_map(static fn (array $r): int => $r['vorlauf'], $regeln);
        $kanaele = $regeln === [] ? [] : $regeln[0]['kanaele'];
        return self::satz($vorlauf, $kanaele);
    }

    public static function statusName(string $status): string
    {
        return [
            'geplant'        => 'geplant',
            'gesendet'       => 'versendet',
            'fehlgeschlagen' => 'nicht zugestellt',
            'uebersprungen'  => 'entfällt',
            'abgesagt'       => 'abgesagt',
        ][$status] ?? $status;
    }

    public static function statusFarbe(string $status): string
    {
        return [
            'geplant' => '', 'gesendet' => 'erfolg', 'fehlgeschlagen' => 'gefahr',
            'uebersprungen' => 'warnung', 'abgesagt' => '',
        ][$status] ?? '';
    }

    /* -------------------------------------------------------- Innereien */

    /**
     * Wer erinnert wird: der Kunde des Termins und, bei Gruppenterminen,
     * jeder eingetragene Teilnehmer.
     *
     * @return array<int,int>
     */
    private static function empfaenger(int $bookingId, int $kundeId): array
    {
        $ids = $kundeId > 0 ? [$kundeId] : [];
        foreach (Tenant::all('booking_participants', 'booking_id = :b', ['b' => $bookingId]) as $tn) {
            $ids[] = (int) $tn['customer_id'];
        }
        return array_values(array_unique(array_filter($ids, static fn ($id) => $id > 0)));
    }

    /**
     * @param array<int,mixed> $liste
     * @return array<int,int>
     */
    public static function vorlaufSaeubern(array $liste): array
    {
        $sauber = [];
        foreach ($liste as $wert) {
            $minuten = (int) $wert;
            if ($minuten > 0 && $minuten <= 43200 && !in_array($minuten, $sauber, true)) {
                $sauber[] = $minuten;
            }
        }
        rsort($sauber);
        return $sauber;
    }

    /**
     * @param array<int,mixed> $liste
     * @return array<int,string>
     */
    public static function kanaeleSaeubern(array $liste): array
    {
        $sauber = [];
        foreach ($liste as $kanal) {
            $kanal = (string) $kanal;
            if (isset(Kanaele::LISTE[$kanal]) && !in_array($kanal, $sauber, true)) {
                $sauber[] = $kanal;
            }
        }
        return $sauber;
    }

    /**
     * @param array<int,mixed> $regeln
     * @return array<int,array{vorlauf:int,kanaele:array<int,string>}>
     */
    public static function regelnSaeubern(array $regeln): array
    {
        $sauber = [];
        $gesehen = [];
        foreach ($regeln as $regel) {
            if (!is_array($regel)) {
                continue;
            }
            $vorlauf = self::vorlaufSaeubern([$regel['vorlauf'] ?? 0]);
            $kanaele = self::kanaeleSaeubern((array) ($regel['kanaele'] ?? []));
            if ($vorlauf === [] || $kanaele === [] || in_array($vorlauf[0], $gesehen, true)) {
                continue;
            }
            $gesehen[] = $vorlauf[0];
            $sauber[]  = ['vorlauf' => $vorlauf[0], 'kanaele' => $kanaele];
        }
        usort($sauber, static fn ($a, $b) => $b['vorlauf'] <=> $a['vorlauf']);
        return $sauber;
    }

    private static function zahlwort(int $zahl): string
    {
        return [2 => 'zwei', 3 => 'drei', 4 => 'vier', 5 => 'fünf', 6 => 'sechs',
                7 => 'sieben', 8 => 'acht', 9 => 'neun', 10 => 'zehn'][$zahl] ?? (string) $zahl;
    }
}
