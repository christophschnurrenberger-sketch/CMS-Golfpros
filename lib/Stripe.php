<?php
/**
 * Stripe – die Zahlungsanbindung.
 *
 * Die Klasse arbeitet in zwei Betriebsarten. Mit hinterlegtem Schlüssel
 * spricht sie die Stripe-API. Ohne Schlüssel läuft sie im **Testmodus**:
 * Zahlungen werden angelegt und als bezahlt verbucht, aber es fließt kein
 * Geld. Das ist für Demo und Einrichtung gedacht und wird in der
 * Oberfläche deutlich gekennzeichnet – eine stille Scheinzahlung wäre
 * gefährlich.
 *
 * Die Anbindung nutzt Checkout Sessions statt eigener Kartenformulare:
 * Damit liegen Kartendaten nie auf dem eigenen Server, und die PCI-Pflichten
 * bleiben bei Stripe. Apple Pay, Google Pay und SEPA kommen dadurch ohne
 * weiteren Aufwand mit.
 */
final class Stripe
{
    public static function eingerichtet(): bool
    {
        return trim((string) Config::get('stripe.secret_key', '')) !== '';
    }

    public static function testmodus(): bool
    {
        return !self::eingerichtet() || str_starts_with((string) Config::get('stripe.secret_key', ''), 'sk_test');
    }

    /**
     * Erzeugt eine Checkout-Sitzung und liefert die Adresse, auf die der
     * Kunde geschickt wird. Ohne Schlüssel wird direkt auf die
     * Erfolgsseite verwiesen und die Bestellung als bezahlt gebucht.
     *
     * @return array{0:string,1:string} [URL, Fehlermeldung]
     */
    public static function checkout(int $orderId, string $erfolgUrl, string $abbruchUrl): array
    {
        $order = Tenant::find('orders', $orderId);
        if (!$order) {
            return ['', 'Bestellung nicht gefunden.'];
        }

        if (!self::eingerichtet()) {
            Commerce::alsBezahlt($orderId, 'test');
            return [$erfolgUrl . (str_contains($erfolgUrl, '?') ? '&' : '?') . 'test=1', ''];
        }

        $positionen = [];
        foreach (Tenant::all('order_items', 'order_id = :o', ['o' => $orderId]) as $i => $z) {
            $positionen["line_items[{$i}][quantity]"] = (int) $z['menge'];
            $positionen["line_items[{$i}][price_data][currency]"] = strtolower((string) $order['waehrung']);
            $positionen["line_items[{$i}][price_data][unit_amount]"] = (int) $z['einzelpreis_cent'];
            $positionen["line_items[{$i}][price_data][product_data][name]"] = (string) $z['titel'];
        }

        $antwort = self::api('checkout/sessions', [
            'mode'        => 'payment',
            'success_url' => $erfolgUrl . (str_contains($erfolgUrl, '?') ? '&' : '?') . 'sitzung={CHECKOUT_SESSION_ID}',
            'cancel_url'  => $abbruchUrl,
            'client_reference_id' => (string) $orderId,
            'customer_email' => (string) $order['email'],
            'metadata[order_id]' => (string) $orderId,
            'metadata[workspace_id]' => (string) Tenant::id(),
        ] + $positionen);

        if (!isset($antwort['url'])) {
            return ['', (string) ($antwort['error']['message'] ?? 'Stripe hat die Zahlung abgelehnt.')];
        }
        return [(string) $antwort['url'], ''];
    }

    /** Prüft die Signatur einer Webhook-Meldung. */
    public static function signaturPruefen(string $koerper, string $signatur): bool
    {
        $geheim = (string) Config::get('stripe.webhook_secret', '');
        if ($geheim === '') {
            return false;
        }
        $zeit = '';
        $hash = '';
        foreach (explode(',', $signatur) as $teil) {
            [$k, $v] = array_pad(explode('=', trim($teil), 2), 2, '');
            if ($k === 't') {
                $zeit = $v;
            } elseif ($k === 'v1') {
                $hash = $v;
            }
        }
        if ($zeit === '' || $hash === '' || abs(time() - (int) $zeit) > 300) {
            return false;
        }
        return hash_equals(hash_hmac('sha256', $zeit . '.' . $koerper, $geheim), $hash);
    }

    /** Übliche Stripe-Gebühr in Deutschland – für die Ertragsrechnung. */
    public static function gebuehr(int $betragCent, string $methode = 'karte'): int
    {
        return match ($methode) {
            'sepa'  => min(600, (int) round($betragCent * 0.008) + 0),
            default => (int) round($betragCent * 0.014) + 25,
        };
    }

    public static function methodeName(string $m): string
    {
        return [
            'karte' => 'Kreditkarte', 'sepa' => 'SEPA-Lastschrift',
            'applepay' => 'Apple Pay', 'googlepay' => 'Google Pay',
            'ueberweisung' => 'Überweisung', 'bar' => 'Bar', 'test' => 'Testzahlung',
        ][$m] ?? $m;
    }

    /** @return array<string,mixed> */
    private static function api(string $pfad, array $daten): array
    {
        $ch = curl_init('https://api.stripe.com/v1/' . $pfad);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($daten),
            CURLOPT_USERPWD        => (string) Config::get('stripe.secret_key', '') . ':',
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_HTTPHEADER     => ['Stripe-Version: 2024-06-20'],
        ]);
        $antwort = curl_exec($ch);
        $fehler  = curl_error($ch);
        curl_close($ch);

        if ($antwort === false) {
            return ['error' => ['message' => 'Stripe war nicht erreichbar: ' . $fehler]];
        }
        $daten = json_decode((string) $antwort, true);
        return is_array($daten) ? $daten : ['error' => ['message' => 'Unerwartete Antwort von Stripe.']];
    }
}
