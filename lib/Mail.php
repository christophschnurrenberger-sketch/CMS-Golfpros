<?php
/**
 * Mail – Versand über PHP mail() oder SMTP.
 *
 * Jede ausgehende Nachricht wird in `communications` protokolliert. Das ist
 * kein Selbstzweck: In der Kundenakte soll stehen, was der Kunde bekommen
 * hat – sonst fragt der Pro beim Anruf nach etwas, das längst versendet wurde.
 *
 * Auf Systemen ohne Postausgang (viele Entwicklungsumgebungen) wird nur
 * protokolliert. Der Ablauf bricht dadurch nie ab.
 *
 * mail() ist der Weg auf dem Webspace: Dort steht ein Postausgang des
 * Hosters bereit. Ein eigener Server hat keinen – dort geht es über SMTP
 * zu einem Postfach oder Versanddienst (`mail.transport = smtp`). Der
 * SMTP-Weg steht hier selbst, ohne Bibliothek: Mehr als Verbinden,
 * verschlüsseln, anmelden, abgeben braucht es nicht, und eine Abhängigkeit
 * weniger heißt ein Update weniger.
 */
final class Mail
{
    public static function senden(string $an, string $betreff, string $text, array $o = []): bool
    {
        $transport = (string) Config::get('mail.transport', 'mail');
        $vonName  = (string) (($o['von_name'] ?? '') ?: (Tenant::einstellung('mail_absender_name', '') ?: Config::get('mail.from_name', 'TeePilot')));
        $vonMail  = self::adresse((string) (Tenant::einstellung('mail_absender', '') ?: Config::get('mail.from_email', '')));
        if ($vonMail === '') {
            $vonMail = 'noreply@' . preg_replace('/:\d+$/', '', App::host());
        }
        $antwort = self::adresse((string) ($o['antwort'] ?? '')) ?: $vonMail;

        /*
         * Über SMTP schreibt man nur in eigenem Namen: Der Postausgang nimmt
         * als Absender die Adresse des Kontos an, mit dem man sich anmeldet.
         * Eine fremde lehnt er ab, oder die Mail landet im Spam, weil SPF und
         * DKIM nicht zur Domain passen. Die Adresse des Betriebs wandert
         * deshalb nach Reply-To – Antworten gehen trotzdem an den Pro, und
         * im Absender steht weiter sein Name.
         */
        if ($transport === 'smtp') {
            $konto = self::adresse((string) (Config::get('mail.from_email', '') ?: Config::get('mail.smtp.user', '')));
            if ($konto !== '') {
                $vonMail = $konto;
            }
        }

        /* Eine Empfängeradresse mit Zeilenumbruch wäre eine eingeschleuste
           Kopfzeile. Was keine Adresse ist, wird nicht verschickt. */
        $an = self::adresse($an);
        if ($an === '') {
            self::protokollieren($an, $betreff, $text, $o, false);
            return false;
        }

        $html = $o['html'] ?? self::vorlage($betreff, $text, $o);
        $grenze = '=_' . Util::token(12);

        $kopf = [
            'From: ' . self::kodieren($vonName) . ' <' . $vonMail . '>',
            'Reply-To: ' . $antwort,
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $grenze . '"',
            'X-Mailer: TeePilot',
        ];

        /*
         * quoted-printable statt 8bit: Eine Zeile darf in einer Mail höchstens
         * 998 Zeichen lang sein, und die HTML-Vorlage ist eine einzige Zeile.
         * Manche Postausgänge brechen sie irgendwo um – mitten in einem
         * Attribut –, andere lehnen die Mail ab. So bleibt jede Zeile kurz.
         */
        $qp = static fn (string $t): string => quoted_printable_encode(preg_replace('/\r\n|\r|\n/', "\r\n", $t) ?? $t);
        $koerper = "--{$grenze}\r\nContent-Type: text/plain; charset=UTF-8\r\n"
                 . "Content-Transfer-Encoding: quoted-printable\r\n\r\n" . $qp($text) . "\r\n\r\n"
                 . "--{$grenze}\r\nContent-Type: text/html; charset=UTF-8\r\n"
                 . "Content-Transfer-Encoding: quoted-printable\r\n\r\n" . $qp((string) $html) . "\r\n\r\n"
                 . "--{$grenze}--";

        /*
         * Anhänge: Text und HTML bleiben als Alternative beisammen, außen
         * herum kommt ein multipart/mixed mit den Dateien. Die Dateinamen
         * werden auf harmlose Zeichen beschränkt – sie stehen in einer
         * Kopfzeile, und ein Zeilenumbruch darin wäre eine eingeschleuste.
         *
         * @var array<int,array{name:string,typ:string,inhalt:string}> $o['anhaenge']
         */
        if (!empty($o['anhaenge'])) {
            $aussen = '=_' . Util::token(12);
            $kopf[3] = 'Content-Type: multipart/mixed; boundary="' . $aussen . '"';
            $gemischt = "--{$aussen}\r\nContent-Type: multipart/alternative; boundary=\"{$grenze}\"\r\n\r\n"
                      . $koerper . "\r\n";
            foreach ((array) $o['anhaenge'] as $a) {
                $name = preg_replace('/[^A-Za-z0-9._-]/', '_', (string) ($a['name'] ?? 'anhang')) ?: 'anhang';
                $typ  = preg_match('#^[a-z]+/[a-z0-9.+-]+$#', (string) ($a['typ'] ?? '')) ? (string) $a['typ'] : 'application/octet-stream';
                $gemischt .= "--{$aussen}\r\nContent-Type: {$typ}; name=\"{$name}\"\r\n"
                           . "Content-Transfer-Encoding: base64\r\n"
                           . "Content-Disposition: attachment; filename=\"{$name}\"\r\n\r\n"
                           . chunk_split(base64_encode((string) ($a['inhalt'] ?? '')), 76, "\r\n") . "\r\n";
            }
            $koerper = $gemischt . "--{$aussen}--";
        }

        $ok = false;
        if ($transport === 'smtp') {
            $domain = substr((string) strrchr($vonMail, '@'), 1) ?: 'teepilot.local';
            $ok = self::smtp($an, $vonMail, array_merge([
                'Date: ' . date(DATE_RFC2822),
                'To: ' . $an,
                'Subject: ' . self::kodieren($betreff),
                'Message-ID: <' . Util::token(16) . '@' . $domain . '>',
            ], $kopf), $koerper);
        } elseif (function_exists('mail') && $transport === 'mail') {
            $ok = @mail($an, self::kodieren($betreff), $koerper, implode("\r\n", $kopf));
        }

        self::protokollieren($an, $betreff, $text, $o, $ok);
        return $ok;
    }

    /** Warum der letzte SMTP-Versand gescheitert ist – für bin/mail-test.php. */
    public static string $letzterFehler = '';

    /**
     * Die Adresse, wenn es eine ist – sonst leer.
     *
     * Bewusst kein FILTER_VALIDATE_EMAIL: Das lehnt Domains mit Umlaut ab
     * (info@golfschule-müller.de), und die gibt es. Ausgeschlossen wird,
     * was eine Kopfzeile oder einen SMTP-Befehl verbiegen könnte:
     * Zeilenumbrüche, Leerraum, spitze Klammern, Trennzeichen.
     */
    private static function adresse(string $adresse): string
    {
        $adresse = trim($adresse);
        return preg_match('/^[^@\s<>,;:"\\\\\x00-\x1F\x7F]+@[^@\s<>,;:"\\\\\x00-\x1F\x7F]+\.[^@\s<>,;:"\\\\\x00-\x1F\x7F]+$/u', $adresse) === 1
            ? $adresse : '';
    }

    /**
     * Eine fertige Nachricht über SMTP abgeben.
     *
     * `secure` = tls heißt STARTTLS auf Port 587, ssl heißt von Anfang an
     * verschlüsselt auf Port 465. Das Zertifikat der Gegenstelle wird immer
     * geprüft; ohne Prüfung könnte jeder dazwischen das Passwort mitlesen.
     * Unverschlüsselt (`secure` leer) nur zu einem Postausgang auf
     * demselben Rechner – und dann ohne Anmeldung, sonst ginge das Passwort
     * im Klartext über die Leitung.
     *
     * Fehlermeldungen nennen den Befehl und die Antwort des Servers, nie
     * die Zugangsdaten.
     *
     * @param list<string> $kopf
     */
    private static function smtp(string $an, string $absender, array $kopf, string $koerper): bool
    {
        self::$letzterFehler = '';
        $c    = (array) Config::get('mail.smtp', []);
        $host = trim((string) ($c['host'] ?? ''));
        $art  = strtolower(trim((string) ($c['secure'] ?? 'tls')));
        $port = (int) ($c['port'] ?? 0) ?: ($art === 'ssl' ? 465 : 587);
        $user = (string) ($c['user'] ?? '');
        $pass = (string) ($c['pass'] ?? '');

        if ($host === '') {
            return self::smtpFehler('In der config.php steht kein SMTP-Server (mail.smtp.host).');
        }
        $lokal = in_array(strtolower($host), ['localhost', '127.0.0.1', '::1'], true);
        if ($art === '' && !$lokal) {
            return self::smtpFehler('Unverschlüsselt nur zu einem Postausgang auf diesem Rechner – bitte secure = tls oder ssl.');
        }

        $kontext = stream_context_create(['ssl' => [
            'verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host,
        ]]);
        $verbindung = @stream_socket_client(($art === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port,
            $nummer, $meldung, 15, STREAM_CLIENT_CONNECT, $kontext);
        if ($verbindung === false) {
            return self::smtpFehler('Keine Verbindung zu ' . $host . ':' . $port . ' (' . trim((string) $meldung) . ').');
        }
        stream_set_timeout($verbindung, 30);

        try {
            self::smtpAntwort($verbindung, [220], 'Begrüßung');
            $faehig = self::smtpBefehl($verbindung, 'EHLO ' . self::smtpName(), [250]);
            if ($art === 'tls') {
                self::smtpBefehl($verbindung, 'STARTTLS', [220]);
                if (!@stream_socket_enable_crypto($verbindung, true,
                        STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                    throw new RuntimeException('STARTTLS: Verschlüsselung kam nicht zustande (Zertifikat prüfen).');
                }
                $faehig = self::smtpBefehl($verbindung, 'EHLO ' . self::smtpName(), [250]);
            }
            if ($user !== '') {
                if (preg_match('/^250[ -]AUTH\b[^\r\n]*\bPLAIN\b/mi', $faehig) === 1) {
                    self::smtpBefehl($verbindung, 'AUTH PLAIN ' . base64_encode("\0" . $user . "\0" . $pass), [235], 'Anmeldung');
                } else {
                    self::smtpBefehl($verbindung, 'AUTH LOGIN', [334]);
                    self::smtpBefehl($verbindung, base64_encode($user), [334], 'Anmeldung');
                    self::smtpBefehl($verbindung, base64_encode($pass), [235], 'Anmeldung');
                }
            }
            self::smtpBefehl($verbindung, 'MAIL FROM:<' . $absender . '>', [250]);
            self::smtpBefehl($verbindung, 'RCPT TO:<' . $an . '>', [250, 251]);
            self::smtpBefehl($verbindung, 'DATA', [354]);

            /* Einheitlich CRLF, und eine Zeile, die mit einem Punkt beginnt,
               bekommt einen zweiten – sonst hielte der Server sie für das
               Ende der Nachricht (RFC 5321, 4.5.2). */
            $daten = preg_replace('/\r\n|\r|\n/', "\r\n", implode("\r\n", $kopf) . "\r\n\r\n" . $koerper) ?? '';
            $daten = preg_replace('/^\./m', '..', $daten) ?? '';
            self::smtpSchreiben($verbindung, $daten . "\r\n.\r\n");
            self::smtpAntwort($verbindung, [250], 'Nachricht');
            @fwrite($verbindung, "QUIT\r\n");
            return true;
        } catch (RuntimeException $e) {
            return self::smtpFehler($e->getMessage());
        } finally {
            fclose($verbindung);
        }
    }

    /** @param list<int> $erwartet */
    private static function smtpBefehl($verbindung, string $befehl, array $erwartet, string $name = ''): string
    {
        self::smtpSchreiben($verbindung, $befehl . "\r\n");
        /* Im Fehlertext steht der Befehl ohne Argumente – bei der Anmeldung
           wären das die Zugangsdaten. */
        return self::smtpAntwort($verbindung, $erwartet, $name !== '' ? $name : strtok($befehl, ' :'));
    }

    /** @param list<int> $erwartet */
    private static function smtpAntwort($verbindung, array $erwartet, string $name): string
    {
        $text = '';
        while (($zeile = fgets($verbindung, 2048)) !== false) {
            $text .= $zeile;
            /* „250-…" heißt: es kommt noch eine Zeile, „250 …" ist die letzte. */
            if (strlen($zeile) < 4 || $zeile[3] !== '-') {
                break;
            }
        }
        if ($text === '') {
            throw new RuntimeException($name . ': keine Antwort vom Server (Zeitüberschreitung).');
        }
        if (!in_array((int) substr($text, 0, 3), $erwartet, true)) {
            $letzte = trim((string) preg_replace('/^.*\n(?=.)/s', '', trim($text)));
            throw new RuntimeException($name . ': ' . mb_substr($letzte, 0, 200));
        }
        return $text;
    }

    private static function smtpSchreiben($verbindung, string $daten): void
    {
        while ($daten !== '') {
            $geschrieben = @fwrite($verbindung, $daten);
            if ($geschrieben === false || $geschrieben === 0) {
                throw new RuntimeException('Verbindung abgebrochen.');
            }
            $daten = (string) substr($daten, $geschrieben);
        }
    }

    /** Der Name, mit dem sich dieser Server im EHLO vorstellt. */
    private static function smtpName(): string
    {
        $name = (string) parse_url((string) Config::get('base_url', ''), PHP_URL_HOST);
        if ($name === '') {
            $name = (string) gethostname();
        }
        return preg_replace('/[^A-Za-z0-9.-]/', '', $name) ?: 'localhost';
    }

    private static function smtpFehler(string $meldung): bool
    {
        self::$letzterFehler = $meldung;
        error_log('TeePilot SMTP: ' . $meldung);
        return false;
    }

    public static function anKunden(array $kunde, string $betreff, string $text, array $o = []): bool
    {
        $o['customer_id'] = (int) $kunde['id'];
        return self::senden((string) $kunde['email'], $betreff, $text, $o);
    }

    private static function protokollieren(string $an, string $betreff, string $text, array $o, bool $ok): void
    {
        /* Mails mit einem Zugangslink tragen 'protokoll' => false: Der Link
           ist ein Schlüssel, und der gehört nicht im Klartext in einen
           Verlauf, den das ganze Team lesen kann. */
        if (!Tenant::gesetzt() || ($o['protokoll'] ?? true) === false) {
            return;
        }
        try {
            Tenant::insert('communications', [
                'customer_id' => (int) ($o['customer_id'] ?? 0),
                'lead_id'     => (int) ($o['lead_id'] ?? 0),
                'kanal'       => 'email',
                'richtung'    => 'aus',
                'betreff'     => $betreff,
                'text'        => $text,
                'user_id'     => Auth::id(),
                'status'      => $ok ? 'gesendet' : 'nicht_zugestellt',
            ]);
        } catch (Throwable $e) {
            // Protokollieren darf den Versand nicht verhindern.
        }
    }

    private static function kodieren(string $text): string
    {
        return preg_match('/[^\x20-\x7E]/', $text)
            ? '=?UTF-8?B?' . base64_encode($text) . '?='
            : $text;
    }

    /**
     * Eine schlichte, gut lesbare HTML-Vorlage. Bewusst tabellenbasiert und
     * ohne moderne CSS-Eigenschaften – Outlook ist das Maß, nicht der Browser.
     */
    public static function vorlage(string $titel, string $text, array $o = []): string
    {
        $branding = Tenant::branding();
        $farbe    = Util::h((string) $branding['primaer']);
        $name     = Util::h(Tenant::name());
        $absatz   = '';
        foreach (preg_split('/\n{2,}/', trim($text)) ?: [] as $teil) {
            $absatz .= '<p style="margin:0 0 16px;line-height:1.6;color:#2b2b28;font-size:15px">'
                     . nl2br(Util::h(trim($teil))) . '</p>';
        }
        $knopf = '';
        if (!empty($o['knopf_text']) && !empty($o['knopf_url'])) {
            $knopf = '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:26px 0">'
                   . '<tr><td style="background:' . $farbe . ';border-radius:8px">'
                   . '<a href="' . Util::attr((string) $o['knopf_url']) . '" style="display:inline-block;'
                   . 'padding:12px 24px;color:#fff;text-decoration:none;font-weight:600;font-size:15px">'
                   . Util::h((string) $o['knopf_text']) . '</a></td></tr></table>';
        }

        return '<!DOCTYPE html><html><head><meta charset="utf-8">'
             . '<meta name="viewport" content="width=device-width,initial-scale=1"></head>'
             . '<body style="margin:0;padding:0;background:#f5f5f3;'
             . 'font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif">'
             . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f5f3;padding:32px 16px">'
             . '<tr><td align="center">'
             . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#fff;border-radius:14px;overflow:hidden;border:1px solid #e7e7e3">'
             . '<tr><td style="padding:24px 32px;border-bottom:1px solid #f0f0ed">'
             . '<span style="font-size:16px;font-weight:700;color:' . $farbe . '">' . $name . '</span></td></tr>'
             . '<tr><td style="padding:32px">'
             . '<h1 style="margin:0 0 18px;font-size:20px;font-weight:650;color:#1a1a18">' . Util::h($titel) . '</h1>'
             . $absatz . $knopf
             . '</td></tr>'
             . '<tr><td style="padding:20px 32px;background:#fafaf8;border-top:1px solid #f0f0ed;'
             . 'font-size:12px;color:#76766f;line-height:1.6">'
             . $name . (!empty($o['fuss']) ? '<br>' . Util::h((string) $o['fuss']) : '')
             . '</td></tr></table></td></tr></table></body></html>';
    }
}
