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
 */
final class Mail
{
    public static function senden(string $an, string $betreff, string $text, array $o = []): bool
    {
        $vonName  = (string) (Tenant::einstellung('mail_absender_name', '') ?: Config::get('mail.from_name', 'TeePilot'));
        $vonMail  = (string) (Tenant::einstellung('mail_absender', '') ?: Config::get('mail.from_email', ''));
        if ($vonMail === '') {
            $vonMail = 'noreply@' . (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        }

        $html = $o['html'] ?? self::vorlage($betreff, $text, $o);
        $grenze = '=_' . Util::token(12);

        $kopf = [
            'From: ' . self::kodieren($vonName) . ' <' . $vonMail . '>',
            'Reply-To: ' . ($o['antwort'] ?? $vonMail),
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $grenze . '"',
            'X-Mailer: TeePilot',
        ];

        $koerper = "--{$grenze}\r\nContent-Type: text/plain; charset=UTF-8\r\n"
                 . "Content-Transfer-Encoding: 8bit\r\n\r\n" . $text . "\r\n\r\n"
                 . "--{$grenze}\r\nContent-Type: text/html; charset=UTF-8\r\n"
                 . "Content-Transfer-Encoding: 8bit\r\n\r\n" . $html . "\r\n\r\n"
                 . "--{$grenze}--";

        $ok = false;
        if (function_exists('mail') && (string) Config::get('mail.transport', 'mail') === 'mail') {
            $ok = @mail($an, self::kodieren($betreff), $koerper, implode("\r\n", $kopf));
        }

        self::protokollieren($an, $betreff, $text, $o, $ok);
        return $ok;
    }

    public static function anKunden(array $kunde, string $betreff, string $text, array $o = []): bool
    {
        $o['customer_id'] = (int) $kunde['id'];
        return self::senden((string) $kunde['email'], $betreff, $text, $o);
    }

    private static function protokollieren(string $an, string $betreff, string $text, array $o, bool $ok): void
    {
        if (!Tenant::gesetzt()) {
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
