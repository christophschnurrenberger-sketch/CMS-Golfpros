<?php
/**
 * Kanäle – ein Weg zum Kunden, drei Ausprägungen.
 *
 * E-Mail, SMS und WhatsApp unterscheiden sich technisch erheblich, fachlich
 * aber kaum: Jemand soll eine Nachricht bekommen. Deshalb liegt hinter allen
 * dreien dieselbe Anfrage – `Kanaele::senden($kunde, $kanal, …)` – und alles
 * Anbieterspezifische bleibt in dieser Datei.
 *
 * Zugangsdaten stehen in der `config.php`, nicht in der Datenbank. Das ist im
 * ganzen System so (Stripe, KI) und hat einen Grund: Ein Datenbankauszug aus
 * Versehen weitergegeben kostet dann keine Zugangsdaten.
 *
 * **Ohne Zugangsdaten wird nicht so getan, als wäre versendet worden.**
 * Beim Bezahlen gibt es einen Testmodus, hier nicht: Eine stille Scheinsendung
 * hieße, dass der Pro sich auf eine Erinnerung verlässt, die der Kunde nie
 * bekommen hat. `senden()` sagt in dem Fall Nein und schreibt den Grund in die
 * Kundenakte.
 *
 * WhatsApp ist vorbereitet, nicht eingeschaltet: Die Anbindung an die
 * WhatsApp Business Cloud API steht hier vollständig, aber Meta verlangt für
 * Nachrichten außerhalb eines laufenden Gesprächs eine freigegebene Vorlage.
 * Solange in der `config.php` keine Vorlage hinterlegt ist, meldet der Kanal
 * sich als nicht eingerichtet – siehe docs/BETRIEB.md.
 */
final class Kanaele
{
    /** kanal => [Name, Symbol] */
    public const LISTE = [
        'email'    => ['E-Mail', 'mail'],
        'sms'      => ['SMS', 'phone'],
        'whatsapp' => ['WhatsApp', 'message'],
    ];

    /**
     * Landesvorwahlen für die Nummern, die ohne + gespeichert sind.
     *
     * Bewusst kurz: Was hier fehlt, muss mit + davor eingetragen werden –
     * das ist besser als eine geratene Vorwahl, die die SMS zu einem
     * fremden Anschluss schickt.
     */
    private const VORWAHLEN = [
        'DE' => '49', 'AT' => '43', 'CH' => '41', 'LU' => '352', 'LI' => '423',
        'NL' => '31', 'BE' => '32', 'FR' => '33', 'IT' => '39', 'ES' => '34',
        'DK' => '45', 'SE' => '46', 'PL' => '48', 'CZ' => '420', 'GB' => '44',
    ];

    public const STUMM_GRUND = 'Vorführdaten: aus dieser Umgebung wird nichts nach außen versendet.';

    /** Ein Vorführ-Workspace schweigt nach außen. */
    public static function stumm(): bool
    {
        return (int) (Tenant::workspace()['demo'] ?? 0) === 1;
    }

    public static function name(string $kanal): string
    {
        return self::LISTE[$kanal][0] ?? $kanal;
    }

    public static function icon(string $kanal): string
    {
        return self::LISTE[$kanal][1] ?? 'send';
    }

    /**
     * Ist der Kanal einsatzbereit?
     *
     * E-Mail ist es immer – notfalls über das `mail()` des Servers. Die
     * beiden anderen brauchen Zugangsdaten.
     */
    public static function eingerichtet(string $kanal): bool
    {
        return match ($kanal) {
            'email'    => true,
            'sms'      => self::smsAnbieter() !== '',
            'whatsapp' => self::whatsappBereit(),
            default    => false,
        };
    }

    /**
     * Der Zustand eines Kanals für die Oberfläche.
     *
     * @return array{0:string,1:string} [bereit|fehlt, Satz zum Anzeigen]
     */
    public static function zustand(string $kanal): array
    {
        if ($kanal === 'email') {
            return ['bereit', 'Läuft über den Postausgang des Servers. Nichts einzurichten.'];
        }
        if ($kanal === 'sms') {
            $anbieter = self::smsAnbieter();
            if ($anbieter === '') {
                return ['fehlt', 'Noch kein SMS-Anbieter in der config.php. Ohne Zugangsdaten '
                    . 'geht keine SMS raus – die E-Mail dazu schon.'];
            }
            return ['bereit', 'Versand über ' . self::smsAnbieterName($anbieter) . '.'];
        }
        if ($kanal === 'whatsapp') {
            if ((string) Config::get('whatsapp.token', '') === ''
                || (string) Config::get('whatsapp.phone_number_id', '') === '') {
                return ['fehlt', 'Vorbereitet, aber noch nicht verbunden: Es fehlen Zugangsdaten '
                    . 'der WhatsApp Business Cloud API in der config.php.'];
            }
            if ((string) Config::get('whatsapp.vorlage', '') === '') {
                return ['fehlt', 'Verbunden, aber ohne freigegebene Vorlage. Meta lässt außerhalb '
                    . 'eines laufenden Gesprächs nur Nachrichten aus geprüften Vorlagen zu.'];
            }
            return ['bereit', 'Versand über die Vorlage „'
                . (string) Config::get('whatsapp.vorlage', '') . '".'];
        }
        return ['fehlt', 'Unbekannter Kanal.'];
    }

    /**
     * Hat dieser Kunde, was der Kanal braucht?
     *
     * Getrennt von `eingerichtet()`, weil beides unabhängig schiefgehen kann:
     * Der Anbieter steht, aber beim Kunden fehlt die Nummer – oder umgekehrt.
     */
    public static function erreichbar(array $kunde, string $kanal): bool
    {
        if ($kanal === 'email') {
            return trim((string) ($kunde['email'] ?? '')) !== '';
        }
        return self::telefonE164((string) ($kunde['telefon'] ?? ''), (string) ($kunde['land'] ?? 'DE')) !== '';
    }

    /**
     * Versendet über den gewählten Kanal.
     *
     * @param array<string,mixed> $o kurz: Kurzfassung für SMS und WhatsApp,
     *                               parameter: Werte für die WhatsApp-Vorlage
     * @return array{0:bool,1:string} [gesendet, Grund wenn nicht]
     */
    public static function senden(array $kunde, string $kanal, string $betreff, string $text, array $o = []): array
    {
        if (!isset(self::LISTE[$kanal])) {
            return [false, 'Unbekannter Kanal: ' . $kanal];
        }
        /*
         * Aus einem Vorführ-Workspace geht nichts nach draußen.
         *
         * Dessen Kunden heißen „thomas.berger@beispiel.de" und tragen
         * erfundene Mobilnummern. Jede Erinnerung dorthin wäre ein Rückläufer
         * – und ein paar hundert Rückläufer ruinieren den Ruf der Absender-
         * domain für die echten Kunden gleich mit.
         */
        if (self::stumm()) {
            return [false, self::STUMM_GRUND];
        }
        if (!self::eingerichtet($kanal)) {
            return [false, self::name($kanal) . ' ist nicht eingerichtet.'];
        }
        if (!self::erreichbar($kunde, $kanal)) {
            return [false, $kanal === 'email'
                ? 'Für diesen Kunden ist keine E-Mail-Adresse hinterlegt.'
                : 'Für diesen Kunden ist keine verwertbare Telefonnummer hinterlegt.'];
        }

        if ($kanal === 'email') {
            $ok = Mail::anKunden($kunde, $betreff, $text, $o);
            return [$ok, $ok ? '' : 'Der Postausgang hat die Nachricht nicht angenommen.'];
        }

        $nummer = self::telefonE164((string) $kunde['telefon'], (string) ($kunde['land'] ?? 'DE'));
        $kurz   = trim((string) ($o['kurz'] ?? $text));

        [$ok, $grund] = $kanal === 'sms'
            ? self::smsSenden($nummer, $kurz)
            : self::whatsappSenden($nummer, $kurz, (array) ($o['parameter'] ?? []));

        self::protokollieren($kunde, $kanal, $betreff, $kurz, $ok, $grund);
        return [$ok, $grund];
    }

    /**
     * Eine Telefonnummer in der Form, die jeder Anbieter versteht: +49170…
     *
     * Geraten wird dabei nicht. Eine Nummer ohne +, ohne 00 und ohne führende
     * 0 lässt sich nicht eindeutig einem Land zuordnen; die käme im Zweifel
     * bei einem fremden Anschluss an. Dann lieber nichts senden und in der
     * Oberfläche sagen, dass die Nummer nicht reicht.
     */
    public static function telefonE164(string $nummer, string $land = 'DE'): string
    {
        $roh = trim($nummer);
        if ($roh === '') {
            return '';
        }
        $plus   = str_starts_with($roh, '+');
        $ziffern = preg_replace('/\D/', '', $roh) ?? '';
        if ($ziffern === '') {
            return '';
        }

        if ($plus) {
            $e164 = '+' . $ziffern;
        } elseif (str_starts_with($ziffern, '00')) {
            $e164 = '+' . substr($ziffern, 2);
        } elseif (str_starts_with($ziffern, '0')) {
            $vorwahl = self::VORWAHLEN[strtoupper($land)] ?? self::VORWAHLEN['DE'];
            $e164 = '+' . $vorwahl . substr($ziffern, 1);
        } else {
            return '';
        }

        /* Kürzer als eine Landesvorwahl plus Anschluss kann keine Nummer sein. */
        return strlen($e164) >= 8 && strlen($e164) <= 16 ? $e164 : '';
    }

    /* ------------------------------------------------------------- SMS */

    public static function smsAnbieter(): string
    {
        $anbieter = strtolower(trim((string) Config::get('sms.anbieter', '')));
        if ($anbieter === '' || $anbieter === 'aus') {
            return '';
        }
        $schluessel = match ($anbieter) {
            'seven'  => (string) Config::get('sms.api_key', ''),
            'twilio' => (string) Config::get('sms.api_key', '') . (string) Config::get('sms.konto', ''),
            default  => '',
        };
        return trim($schluessel) === '' ? '' : $anbieter;
    }

    public static function smsAnbieterName(string $anbieter): string
    {
        return ['seven' => 'seven.io', 'twilio' => 'Twilio'][$anbieter] ?? $anbieter;
    }

    /** @return array{0:bool,1:string} */
    private static function smsSenden(string $nummer, string $text): array
    {
        $absender = trim((string) Config::get('sms.absender', '')) ?: substr(Tenant::name(), 0, 11);

        if (self::smsAnbieter() === 'twilio') {
            $konto = (string) Config::get('sms.konto', '');
            [$code, $antwort] = self::http(
                'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode($konto) . '/Messages.json',
                http_build_query(['To' => $nummer, 'From' => $absender, 'Body' => $text]),
                [], $konto . ':' . (string) Config::get('sms.api_key', '')
            );
            if ($code >= 200 && $code < 300) {
                return [true, ''];
            }
            $daten = json_decode($antwort, true);
            return [false, 'Twilio: ' . (string) (is_array($daten) ? ($daten['message'] ?? $antwort) : $antwort)];
        }

        /* seven.io – deutscher Anbieter, einfache Schnittstelle, kein SDK nötig. */
        [$code, $antwort] = self::http(
            'https://gateway.seven.io/api/sms',
            http_build_query(['to' => $nummer, 'text' => $text, 'from' => $absender, 'json' => 1]),
            ['X-Api-Key: ' . (string) Config::get('sms.api_key', '')]
        );
        if ($code < 200 || $code >= 300) {
            return [false, 'seven.io antwortete mit HTTP ' . $code . '.'];
        }
        $daten = json_decode($antwort, true);
        $erfolg = is_array($daten) ? (string) ($daten['success'] ?? '') : '';
        if ($erfolg === '100') {
            return [true, ''];
        }
        return [false, 'seven.io meldet Code ' . ($erfolg !== '' ? $erfolg : '?') . '.'];
    }

    /* -------------------------------------------------------- WhatsApp */

    private static function whatsappBereit(): bool
    {
        return trim((string) Config::get('whatsapp.token', '')) !== ''
            && trim((string) Config::get('whatsapp.phone_number_id', '')) !== ''
            && trim((string) Config::get('whatsapp.vorlage', '')) !== '';
    }

    /**
     * WhatsApp Business Cloud API.
     *
     * Versendet wird eine Vorlage, kein freier Text: Meta lässt freie Texte
     * nur innerhalb von 24 Stunden nach einer Nachricht des Kunden zu, und
     * eine Terminerinnerung kommt fast immer außerhalb dieses Fensters.
     * Die Vorlage wird bei Meta angelegt und geprüft; hier stehen nur ihr
     * Name und die Werte, die in ihre Platzhalter wandern.
     *
     * @param array<int,string> $parameter Werte für {{1}}, {{2}}, …
     * @return array{0:bool,1:string}
     */
    private static function whatsappSenden(string $nummer, string $text, array $parameter): array
    {
        $vorlage = (string) Config::get('whatsapp.vorlage', '');
        $sprache = (string) Config::get('whatsapp.sprache', 'de');
        $werte   = [];
        foreach ($parameter !== [] ? $parameter : [$text] as $wert) {
            $werte[] = ['type' => 'text', 'text' => (string) $wert];
        }

        [$code, $antwort] = self::http(
            'https://graph.facebook.com/v21.0/' . rawurlencode((string) Config::get('whatsapp.phone_number_id', '')) . '/messages',
            Util::json([
                'messaging_product' => 'whatsapp',
                'to'   => ltrim($nummer, '+'),
                'type' => 'template',
                'template' => [
                    'name' => $vorlage,
                    'language'   => ['code' => $sprache],
                    'components' => [['type' => 'body', 'parameters' => $werte]],
                ],
            ]),
            ['Authorization: Bearer ' . (string) Config::get('whatsapp.token', ''),
             'Content-Type: application/json']
        );

        if ($code >= 200 && $code < 300) {
            return [true, ''];
        }
        $daten = json_decode($antwort, true);
        $grund = is_array($daten) ? (string) ($daten['error']['message'] ?? '') : '';
        return [false, 'WhatsApp: ' . ($grund !== '' ? $grund : 'HTTP ' . $code)];
    }

    /* ----------------------------------------------------------- Technik */

    /**
     * Eine POST-Anfrage, mehr braucht es hier nicht.
     *
     * @param array<int,string> $kopf
     * @return array{0:int,1:string} [HTTP-Code, Antwort]
     */
    private static function http(string $url, string $koerper, array $kopf = [], string $benutzer = ''): array
    {
        if (!function_exists('curl_init')) {
            return [0, 'Auf diesem Server fehlt cURL.'];
        }
        $ch = curl_init($url);
        $einstellungen = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $koerper,
            CURLOPT_TIMEOUT        => 15,
        ];
        if ($kopf !== []) {
            $einstellungen[CURLOPT_HTTPHEADER] = $kopf;
        }
        if ($benutzer !== '') {
            $einstellungen[CURLOPT_USERPWD] = $benutzer;
        }
        curl_setopt_array($ch, $einstellungen);
        $antwort = curl_exec($ch);
        $code    = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $fehler  = curl_error($ch);
        curl_close($ch);

        if ($antwort === false) {
            return [0, $fehler !== '' ? $fehler : 'Keine Antwort.'];
        }
        return [$code, (string) $antwort];
    }

    /**
     * Was rausging, steht in der Kundenakte – wie bei der E-Mail auch.
     *
     * Sonst fragt der Pro beim Anruf nach etwas, das der Kunde längst
     * bekommen hat, oder verlässt sich auf eine SMS, die nie ankam.
     */
    private static function protokollieren(array $kunde, string $kanal, string $betreff,
                                           string $text, bool $ok, string $grund): void
    {
        if (!Tenant::gesetzt()) {
            return;
        }
        try {
            Tenant::insert('communications', [
                'customer_id' => (int) ($kunde['id'] ?? 0),
                'kanal'       => $kanal,
                'richtung'    => 'aus',
                'betreff'     => $betreff,
                'text'        => $ok ? $text : $text . "\n\n[nicht zugestellt: " . $grund . ']',
                'user_id'     => Auth::id(),
                'status'      => $ok ? 'gesendet' : 'nicht_zugestellt',
            ]);
        } catch (Throwable $e) {
            // Protokollieren darf den Versand nicht verhindern.
        }
    }
}
