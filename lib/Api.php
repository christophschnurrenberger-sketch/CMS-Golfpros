<?php
/**
 * Api – die Schnittstelle, über die ein Newslettersystem die Empfänger abholt.
 *
 * Gebaut wurde sie für Acumen Mail, sie setzt aber nichts davon voraus: Wer
 * einen Schlüssel hat, bekommt JSON und kann damit machen, was er will.
 *
 * **Abholen statt schieben.** Das System hier weiß nicht, wann das
 * Newslettersystem erreichbar ist, ob es gerade umzieht oder ob sein
 * Schlüssel noch gilt. Ein Abruf dagegen liegt vollständig in der Hand
 * dessen, der die Daten braucht: Er holt, so oft er mag, wiederholt bei
 * einem Fehler und bestimmt selbst, wann ein Abgleich als erledigt gilt.
 * Ein Versand von hier aus bräuchte eine Warteschlange, Wiederholungen und
 * eine Überwachung – für dieselbe Wirkung.
 *
 * **Zwei Listen, nicht eine.** `empfaenger` liefert, wer eingewilligt hat.
 * `abmeldungen` liefert, wer eine Adresse hat und *nicht* (mehr)
 * eingewilligt hat. Ohne die zweite Liste verschwindet ein Abgemeldeter
 * einfach aus der ersten, und das andere System kann nicht unterscheiden,
 * ob jemand widerrufen hat oder ob die Seite nur zu Ende war. Eine
 * Abmeldung, die nicht ankommt, ist eine Abmahnung.
 *
 * **Nur, was ein Newsletter braucht.** Name, Adresse, Sprache, Etiketten,
 * Handicap, Heimatclub, Ort und Land – daraus lassen sich Empfängergruppen
 * bilden. Straße, Telefonnummer, Notizen, Umsätze und Termine bleiben hier.
 * Wer Daten weitergibt, gibt so wenige weiter wie möglich.
 *
 * Der Schlüssel wird nur als Abdruck gespeichert. Im Klartext steht er
 * genau einmal auf dem Bildschirm, wenn er erzeugt wird. Ein Auszug der
 * Datenbank gibt ihn dann nicht mehr her.
 */
final class Api
{
    public const VERSION = 'v1';

    private const PRO_SEITE     = 200;
    private const PRO_SEITE_MAX = 500;

    /** Ein Fehlversuch zählt unter dieser Aktion in die Bremse von Auth. */
    private const BREMSE = 'api_fehlversuch';

    /* ===================================================== Schlüssel === */

    /**
     * Erzeugt einen Schlüssel und gibt ihn **einmalig** im Klartext zurück.
     *
     * Die Kennung des Workspace steht vorn im Schlüssel. Sonst müsste die
     * Anmeldung den Abdruck jedes Workspace durchprobieren – und genau das
     * wäre die Stelle, an der später jemand einen Vergleich einbaut, der
     * nach der Laufzeit verrät, welcher Schlüssel fast gestimmt hat.
     */
    public static function schluesselErzeugen(): string
    {
        $klartext = 'gp_' . Tenant::id() . '_' . bin2hex(random_bytes(16));
        Tenant::einstellungSetzen('api_schluessel_hash', hash('sha256', $klartext));
        Tenant::einstellungSetzen('api_schluessel_kurz', self::kuerzen($klartext));
        Tenant::einstellungSetzen('api_schluessel_erstellt', Util::jetzt());
        Audit::schreiben('erstellt', 'api_schluessel', Tenant::id(), 'Neuer Schlüssel für die Schnittstelle');
        return $klartext;
    }

    public static function schluesselWiderrufen(): void
    {
        foreach (['api_schluessel_hash', 'api_schluessel_kurz', 'api_schluessel_erstellt',
                  'api_letzter_zugriff'] as $k) {
            Tenant::einstellungSetzen($k, '');
        }
        Audit::schreiben('geloescht', 'api_schluessel', Tenant::id(), 'Schlüssel für die Schnittstelle widerrufen');
    }

    public static function schluesselVorhanden(): bool
    {
        return (string) Tenant::einstellung('api_schluessel_hash', '') !== '';
    }

    public static function schluesselKurz(): string
    {
        return (string) Tenant::einstellung('api_schluessel_kurz', '');
    }

    public static function schluesselErstellt(): string
    {
        return (string) Tenant::einstellung('api_schluessel_erstellt', '');
    }

    public static function letzterZugriff(): string
    {
        return (string) Tenant::einstellung('api_letzter_zugriff', '');
    }

    /** „gp_2_a1b2c3d4…" – genug zum Wiedererkennen, zu wenig zum Benutzen. */
    private static function kuerzen(string $klartext): string
    {
        return substr($klartext, 0, strrpos($klartext, '_') + 9) . '…';
    }

    /* ====================================================== Anmeldung === */

    /**
     * Prüft den Schlüssel und setzt den Mandanten.
     *
     * @return array{0:bool,1:string,2:string} [gültig, Fehlercode, Fehlertext]
     */
    public static function anmelden(): array
    {
        $token = self::tokenAusAnfrage();
        if ($token === '') {
            return [false, 'kein_schluessel',
                'Es fehlt der Schlüssel. Erwartet wird die Kopfzeile "Authorization: Bearer …".'];
        }
        if (Auth::versuchGesperrt(self::bremsMarke($token), self::BREMSE)) {
            return [false, 'zu_viele_versuche',
                'Zu viele Fehlversuche. Bitte in 15 Minuten erneut probieren.'];
        }

        if (!preg_match('/^gp_(\d{1,9})_([a-f0-9]{32})$/', $token, $teile)) {
            self::fehlversuch($token);
            return [false, 'schluessel_ungueltig', 'Dieser Schlüssel stimmt nicht.'];
        }

        $workspace = DB::one('SELECT id, aktiv, status FROM workspaces WHERE id = :i', ['i' => (int) $teile[1]]);
        /*
         * Auch ohne Workspace wird verglichen – gegen einen Abdruck, der zu
         * nichts passt. Sonst antwortet die Schnittstelle auf eine erfundene
         * Kennung messbar schneller als auf eine echte und verrät damit,
         * welche Nummern vergeben sind.
         */
        $gespeichert = str_repeat('0', 64);
        /* Pausiert, gesperrt oder archiviert: Die Schnittstelle antwortet
           wie auf einen falschen Schlüssel. Wer den Status kennen muss,
           erfährt ihn vom Support, nicht von einem Skript. */
        if ($workspace !== null && (int) $workspace['aktiv'] === 1
            && Instanzen::zugangErlaubt((string) $workspace['status'])) {
            $gespeichert = (string) DB::value(
                'SELECT wert FROM settings WHERE workspace_id = :w AND schluessel = :s',
                ['w' => (int) $workspace['id'], 's' => 'api_schluessel_hash'],
                ''
            );
            if ($gespeichert === '') {
                $gespeichert = str_repeat('0', 64);
            }
        }

        if (!hash_equals($gespeichert, hash('sha256', $token))) {
            self::fehlversuch($token);
            return [false, 'schluessel_ungueltig', 'Dieser Schlüssel stimmt nicht.'];
        }

        Tenant::setzen((int) $teile[1]);
        self::zugriffMerken();
        return [true, '', ''];
    }

    /**
     * Der Schlüssel aus der Anfrage.
     *
     * Bevorzugt die Kopfzeile. Der Weg über die Adresse (`?schluessel=…`)
     * ist der Rückfall für Systeme, die keine eigenen Kopfzeilen setzen
     * können; er steht dafür in jedem Server- und Zwischenspeicherprotokoll,
     * und darauf weist die Beschreibung ausdrücklich hin.
     */
    private static function tokenAusAnfrage(): string
    {
        $kopf = (string) ($_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if ($kopf === '' && function_exists('getallheaders')) {
            foreach (getallheaders() as $name => $wert) {
                if (strcasecmp((string) $name, 'Authorization') === 0) {
                    $kopf = (string) $wert;
                    break;
                }
            }
        }
        if (preg_match('/^Bearer\s+(\S+)$/i', trim($kopf), $t)) {
            return $t[1];
        }
        return App::get('schluessel');
    }

    private static function fehlversuch(string $token): void
    {
        Auth::versuchMerken(self::bremsMarke($token), self::BREMSE);
    }

    /**
     * Woran die Bremse einen Schlüssel wiedererkennt.
     *
     * Ein Abdruck und nicht der Anfang des Schlüssels: Die Bremse schreibt
     * ihre Marke ins Protokoll, und der Anfang eines Schlüssels ist bereits
     * ein Stück des Geheimnisses. Nebenbei lässt sich so auch niemand
     * aussperren, der den in der Oberfläche sichtbaren Anfang kennt.
     */
    private static function bremsMarke(string $token): string
    {
        return 'api:' . substr(hash('sha256', $token), 0, 24);
    }

    /**
     * Den letzten Zugriff festhalten – aber nicht jeden einzelnen.
     *
     * In der Oberfläche steht „zuletzt abgeholt vor zehn Minuten"; dafür
     * genügt ein Zeitstempel. Ein Protokolleintrag je Abruf würde das
     * Protokoll bei einem stündlichen Abgleich mit sich selbst füllen,
     * deshalb höchstens einer je Stunde.
     */
    private static function zugriffMerken(): void
    {
        $vorher = strtotime((string) Tenant::einstellung('api_letzter_zugriff', '')) ?: 0;
        Tenant::einstellungSetzen('api_letzter_zugriff', Util::jetzt());
        if (time() - $vorher > 3600) {
            Audit::schreiben('gelesen', 'api', Tenant::id(), 'Empfänger über die Schnittstelle abgeholt');
        }
    }

    /* ======================================================== Ausgabe === */

    /** @param array<string,mixed> $daten */
    public static function antwort(array $daten, int $status = 200): never
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            /* Personenbezogene Daten gehören in keinen Zwischenspeicher und
               in keinen Suchindex. */
            header('Cache-Control: no-store, private');
            header('X-Robots-Tag: noindex, nofollow');
            header('Referrer-Policy: no-referrer');
        }
        echo Util::json($daten);
        exit;
    }

    public static function fehler(string $code, string $text, int $status): never
    {
        self::antwort(['fehler' => ['code' => $code, 'text' => $text]], $status);
    }

    /* ======================================================= Empfänger === */

    /**
     * Die Empfänger mit Einwilligung.
     *
     * @param array<string,mixed> $f seite, pro_seite, seit
     * @return array<string,mixed>
     */
    public static function empfaenger(array $f): array
    {
        return self::liste('newsletter = 1 AND email != "" AND status = "aktiv"', $f, 'empfaenger');
    }

    /**
     * Wer nicht (mehr) eingewilligt hat.
     *
     * Dazu zählt auch, wer inaktiv oder archiviert ist: Ein archivierter
     * Kunde soll keine Werbung mehr bekommen, selbst wenn der Haken von
     * damals noch steht.
     */
    public static function abmeldungen(array $f): array
    {
        return self::liste('email != "" AND (newsletter = 0 OR status != "aktiv")', $f, 'abmeldungen');
    }

    /**
     * @param array<string,mixed> $f
     * @return array<string,mixed>
     */
    private static function liste(string $wo, array $f, string $was): array
    {
        $proSeite = (int) ($f['pro_seite'] ?? self::PRO_SEITE);
        $proSeite = (int) Util::klemmen((float) ($proSeite > 0 ? $proSeite : self::PRO_SEITE), 1, self::PRO_SEITE_MAX);
        $seite    = max(1, (int) ($f['seite'] ?? 1));
        $p        = [];

        $seit = trim((string) ($f['seit'] ?? ''));
        if ($seit !== '') {
            $zeit = strtotime($seit);
            if ($zeit === false) {
                self::fehler('seit_unlesbar',
                    'Den Wert von "seit" konnte ich nicht als Zeitpunkt lesen. '
                    . 'Erlaubt sind zum Beispiel 2026-09-01 oder 2026-09-01T10:00:00Z.', 400);
            }
            $wo .= ' AND geaendert > :seit';
            $p['seit'] = date('Y-m-d H:i:s', $zeit);
        }

        $gesamt = Tenant::count('customers', $wo, $p);
        /*
         * Sortiert nach Änderungszeitpunkt, nicht nach Namen: Wer Seite für
         * Seite abholt, während nebenher jemand einen Kunden anlegt, bekommt
         * bei einer Sortierung nach Namen Datensätze doppelt oder gar nicht.
         * Die Kennung als zweites Merkmal macht die Reihenfolge eindeutig.
         */
        $zeilen = Tenant::all('customers', $wo, $p, 'geaendert, id', $proSeite, ($seite - 1) * $proSeite);

        $satz = [];
        foreach ($zeilen as $kunde) {
            $satz[] = self::satz($kunde);
        }

        $seiten = $gesamt > 0 ? (int) ceil($gesamt / $proSeite) : 0;
        return [
            'stand'     => Util::jetzt(),
            'gesamt'    => $gesamt,
            'seite'     => $seite,
            'seiten'    => $seiten,
            'pro_seite' => $proSeite,
            'weiter'    => $seite < $seiten ? self::url($was, [
                'seite' => $seite + 1, 'pro_seite' => $proSeite, 'seit' => $seit,
            ]) : null,
            $was        => $satz,
        ];
    }

    /**
     * Ein Kunde, wie ihn ein Newslettersystem braucht.
     *
     * @param array<string,mixed> $kunde
     * @return array<string,mixed>
     */
    private static function satz(array $kunde): array
    {
        $eingewilligt = (int) $kunde['newsletter'] === 1 && (string) $kunde['status'] === 'aktiv';
        $einwilligung = Tenant::one('consents',
            "customer_id = :k AND typ = 'newsletter'", ['k' => (int) $kunde['id']], 'id DESC');

        return [
            'id'          => (int) $kunde['id'],
            'email'       => (string) $kunde['email'],
            'vorname'     => (string) $kunde['vorname'],
            'nachname'    => (string) $kunde['nachname'],
            'name'        => Customers::name($kunde),
            'sprache'     => (string) (Tenant::workspace()['sprache'] ?? 'de'),
            'newsletter'  => $eingewilligt,
            'status'      => (string) $kunde['status'],
            'ort'         => (string) $kunde['ort'],
            'land'        => (string) $kunde['land'],
            'hcp'         => (string) $kunde['hcp'],
            'heimclub'    => (string) $kunde['heimclub'],
            'tags'        => Customers::tags($kunde),
            'kunde_seit'  => substr((string) $kunde['erstellt'], 0, 10),
            'geaendert'   => (string) $kunde['geaendert'],
            /* Der Abmeldelink gehört in jede Aussendung – auch in die aus
               dem anderen System. Er zeigt hierher, damit eine Abmeldung
               dort *und* hier gilt und nicht nur in einer der beiden
               Datenbanken steht. */
            'abmelde_url' => Customers::abmeldeLink($kunde),
            'einwilligung' => $einwilligung === null ? null : [
                'erteilt' => (int) $einwilligung['erteilt'] === 1,
                'am'      => (string) $einwilligung['erstellt'],
                'quelle'  => (string) $einwilligung['quelle'],
            ],
        ];
    }

    /* ========================================================= Auskunft === */

    /**
     * Ein kleiner Selbsttest: Stimmt der Schlüssel, und was ist zu holen?
     *
     * Die erste Frage beim Einrichten ist immer dieselbe – „komme ich
     * überhaupt rein?" –, und sie soll sich beantworten lassen, ohne dass
     * dabei ein einziger Kundendatensatz über die Leitung geht.
     *
     * @return array<string,mixed>
     */
    public static function auskunft(): array
    {
        return [
            'workspace'   => Tenant::name(),
            'version'     => self::VERSION,
            'zeit'        => Util::jetzt(),
            'zeitzone'    => (string) (Tenant::workspace()['zeitzone'] ?? 'Europe/Berlin'),
            'empfaenger'  => Tenant::count('customers', 'newsletter = 1 AND email != "" AND status = "aktiv"'),
            'abmeldungen' => Tenant::count('customers', 'email != "" AND (newsletter = 0 OR status != "aktiv")'),
            'adressen'    => [
                'empfaenger'  => self::url('empfaenger'),
                'abmeldungen' => self::url('abmeldungen'),
                'auskunft'    => self::url('auskunft'),
            ],
        ];
    }

    /** @param array<string,mixed> $parameter */
    public static function url(string $was, array $parameter = []): string
    {
        $abfrage = array_filter(['was' => $was] + $parameter,
            static fn ($w): bool => $w !== '' && $w !== null);
        return App::absolut('/api.php') . '?' . http_build_query($abfrage);
    }
}
