<?php
/**
 * Campaigns – Newsletter.
 *
 * Der Newsletter besteht aus denselben Bausteinen wie die Website, nur mit
 * einem E-Mail-tauglichen Renderer: Tabellen statt Flexbox, feste Breiten,
 * Inline-Styles. Was im Baukasten funktioniert, funktioniert damit auch im
 * Postfach – und der Pro muss nicht zweimal lernen, wie man einen Absatz
 * einfügt.
 *
 * Versendet wird nur an Empfänger mit Einwilligung. Der Abmeldelink steht
 * in jeder Mail; ohne ihn ist ein Newsletter in Europa nicht zulässig.
 */
final class Campaigns
{
    public static function speichern(array $daten, int $id = 0): int
    {
        $satz = array_intersect_key($daten, array_flip([
            'name', 'betreff', 'vorschautext', 'bloecke', 'segment_id',
            'status', 'ab_test', 'ab_betreff', 'geplant',
        ]));
        if ($id > 0) {
            Tenant::update('campaigns', $id, $satz);
            return $id;
        }
        $neu = Tenant::insert('campaigns', $satz);
        Audit::schreiben('erstellt', 'campaign', $neu, (string) ($satz['name'] ?? ''));
        return $neu;
    }

    /** @return array<int,array<string,mixed>> Empfänger mit Einwilligung */
    public static function empfaenger(int $campaignId): array
    {
        $k = Tenant::find('campaigns', $campaignId);
        if (!$k) {
            return [];
        }
        $wo = 'newsletter = 1 AND email != "" AND status = "aktiv"';
        $p  = [];
        if ((int) $k['segment_id'] > 0) {
            $segment = Tenant::find('segments', (int) $k['segment_id']);
            if ($segment) {
                [$sWo, $sP] = Segments::bedingung(Util::ausJson((string) $segment['regeln']));
                if ($sWo !== '') {
                    $wo .= ' AND (' . $sWo . ')';
                    $p  += $sP;
                }
            }
        }
        return Tenant::all('customers', $wo, $p, 'nachname');
    }

    /**
     * Versendet in Schüben. Ein Shared Host bricht bei tausend Mails in
     * einer Anfrage ab – deshalb ein Schub je Aufruf, mit Fortschritt.
     *
     * @return array{gesendet:int,offen:int}
     */
    public static function versenden(int $campaignId, int $schub = 25): array
    {
        $k = Tenant::find('campaigns', $campaignId);
        if (!$k || $k['status'] === 'versendet') {
            return ['gesendet' => 0, 'offen' => 0];
        }
        $alle = self::empfaenger($campaignId);
        $schonGesendet = (int) $k['empfaenger'];
        $teil = array_slice($alle, $schonGesendet, $schub);

        $html = self::html($k);
        $text = self::text($k);
        $gesendet = 0;

        foreach ($teil as $kunde) {
            $abmelden = App::absolut('/abmelden-newsletter.php?t=' . rawurlencode((string) $kunde['portal_token']));
            $betreff = (int) $k['ab_test'] === 1 && $gesendet % 2 === 1 && (string) $k['ab_betreff'] !== ''
                ? (string) $k['ab_betreff']
                : (string) $k['betreff'];

            Mail::anKunden($kunde,
                Automations::platzhalter($betreff, $kunde),
                Automations::platzhalter($text, $kunde) . "\n\n—\nAbmelden: " . $abmelden,
                ['html' => str_replace(
                    ['{{abmelden}}', '{vorname}', '{name}'],
                    [$abmelden, (string) $kunde['vorname'], Customers::name($kunde)],
                    $html
                 )]);
            $gesendet++;
        }

        $neuGesamt = $schonGesendet + $gesendet;
        $fertig = $neuGesamt >= count($alle);
        Tenant::update('campaigns', $campaignId, [
            'empfaenger' => $neuGesamt,
            'status'     => $fertig ? 'versendet' : 'geplant',
            'versendet'  => $fertig ? Util::jetzt() : null,
        ]);
        if ($fertig) {
            Audit::schreiben('versendet', 'campaign', $campaignId, $neuGesamt . ' Empfänger');
            Notify::senden('marketing', 'Newsletter versendet: ' . $k['name'],
                $neuGesamt . ' Empfänger.', '/app/kampagne.php?id=' . $campaignId);
        }
        return ['gesendet' => $gesendet, 'offen' => max(0, count($alle) - $neuGesamt)];
    }

    /** Bausteine → E-Mail-HTML (tabellenbasiert, Outlook-tauglich). */
    public static function html(array $kampagne): string
    {
        $bloecke = Util::ausJson((string) $kampagne['bloecke'], []);
        $branding = Tenant::branding();
        $farbe = Util::h((string) $branding['primaer']);
        $inhalt = '';

        foreach ($bloecke as $b) {
            $typ = (string) ($b['typ'] ?? 'text');
            $d   = (array) ($b['daten'] ?? []);
            switch ($typ) {
                case 'ueberschrift':
                    $inhalt .= '<tr><td style="padding:0 32px 12px"><h2 style="margin:0;font-size:20px;'
                             . 'font-weight:650;color:#1a1a18">' . Util::h((string) ($d['text'] ?? '')) . '</h2></td></tr>';
                    break;
                case 'text':
                    $inhalt .= '<tr><td style="padding:0 32px 16px;font-size:15px;line-height:1.65;color:#2b2b28">'
                             . nl2br(Util::h((string) ($d['text'] ?? ''))) . '</td></tr>';
                    break;
                case 'knopf':
                    $inhalt .= '<tr><td style="padding:8px 32px 24px"><table role="presentation" cellpadding="0" cellspacing="0">'
                             . '<tr><td style="background:' . $farbe . ';border-radius:8px">'
                             . '<a href="' . Util::attr((string) ($d['url'] ?? '#')) . '" style="display:inline-block;'
                             . 'padding:12px 26px;color:#fff;text-decoration:none;font-weight:600;font-size:15px">'
                             . Util::h((string) ($d['text'] ?? 'Jetzt ansehen')) . '</a></td></tr></table></td></tr>';
                    break;
                case 'bild':
                    if ((string) ($d['url'] ?? '') !== '') {
                        $inhalt .= '<tr><td style="padding:0 0 20px"><img src="' . Util::attr((string) $d['url'])
                                 . '" alt="' . Util::attr((string) ($d['alt'] ?? '')) . '" style="width:100%;display:block"></td></tr>';
                    }
                    break;
                case 'trenner':
                    $inhalt .= '<tr><td style="padding:8px 32px 24px"><div style="height:1px;background:#eceae6"></div></td></tr>';
                    break;
                case 'angebot':
                    $inhalt .= '<tr><td style="padding:0 32px 20px">'
                             . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" '
                             . 'style="background:#fafaf8;border:1px solid #eceae6;border-radius:12px"><tr><td style="padding:20px">'
                             . '<div style="font-size:16px;font-weight:650;color:#1a1a18;margin-bottom:6px">'
                             . Util::h((string) ($d['titel'] ?? '')) . '</div>'
                             . '<div style="font-size:14px;color:#5a5a54;line-height:1.6;margin-bottom:12px">'
                             . Util::h((string) ($d['text'] ?? '')) . '</div>'
                             . '<div style="font-size:20px;font-weight:700;color:' . $farbe . '">'
                             . Util::h((string) ($d['preis'] ?? '')) . '</div>'
                             . '</td></tr></table></td></tr>';
                    break;
            }
        }

        return '<!DOCTYPE html><html><head><meta charset="utf-8">'
             . '<meta name="viewport" content="width=device-width,initial-scale=1"></head>'
             . '<body style="margin:0;background:#f5f5f3;font-family:-apple-system,BlinkMacSystemFont,'
             . '\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif">'
             . '<div style="display:none;max-height:0;overflow:hidden">'
             . Util::h((string) $kampagne['vorschautext']) . '</div>'
             . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="padding:32px 16px">'
             . '<tr><td align="center"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" '
             . 'style="max-width:600px;background:#fff;border-radius:14px;overflow:hidden;border:1px solid #e7e7e3">'
             . '<tr><td style="padding:26px 32px 20px"><span style="font-size:17px;font-weight:700;color:' . $farbe . '">'
             . Util::h(Tenant::name()) . '</span></td></tr>'
             . $inhalt
             . '<tr><td style="padding:22px 32px;background:#fafaf8;border-top:1px solid #f0f0ed;'
             . 'font-size:12px;color:#76766f;line-height:1.7">'
             . Util::h(Tenant::name()) . '<br>'
             . '<a href="{{abmelden}}" style="color:#76766f">Newsletter abbestellen</a>'
             . '</td></tr></table></td></tr></table></body></html>';
    }

    public static function text(array $kampagne): string
    {
        $bloecke = Util::ausJson((string) $kampagne['bloecke'], []);
        $zeilen = [];
        foreach ($bloecke as $b) {
            $d = (array) ($b['daten'] ?? []);
            switch ((string) ($b['typ'] ?? '')) {
                case 'ueberschrift': $zeilen[] = strtoupper((string) ($d['text'] ?? '')); break;
                case 'text':         $zeilen[] = (string) ($d['text'] ?? ''); break;
                case 'knopf':        $zeilen[] = ((string) ($d['text'] ?? '')) . ': ' . ((string) ($d['url'] ?? '')); break;
                case 'angebot':      $zeilen[] = ((string) ($d['titel'] ?? '')) . "\n" . ((string) ($d['text'] ?? ''))
                                                 . "\n" . ((string) ($d['preis'] ?? '')); break;
                case 'trenner':      $zeilen[] = '—'; break;
            }
        }
        return implode("\n\n", array_filter($zeilen));
    }

    /** Öffnungs- und Klickrate in Prozent. */
    public static function quoten(array $k): array
    {
        $e = max(1, (int) $k['empfaenger']);
        return [
            'oeffnung' => round((int) $k['geoeffnet'] / $e * 100, 1),
            'klick'    => round((int) $k['geklickt'] / $e * 100, 1),
            'abmeldung'=> round((int) $k['abgemeldet'] / $e * 100, 1),
        ];
    }
}
