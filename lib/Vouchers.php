<?php
/**
 * Vouchers – Gutscheine.
 *
 * Zwei Arten: Wertgutschein („50 €“) und Leistungsgutschein („eine
 * Schnupperstunde"). Wertgutscheine behalten ein Restguthaben, weil sie
 * selten auf den Cent eingelöst werden – ein Gutschein, der bei
 * Teileinlösung verfällt, macht Ärger am Tresen.
 */
final class Vouchers
{
    public static function erzeugen(array $daten): int
    {
        $wert = (int) ($daten['wert_cent'] ?? 0);
        $id = Tenant::insert('vouchers', [
            'code'        => (string) ($daten['code'] ?? self::freierCode()),
            'art'         => (string) ($daten['art'] ?? 'wert'),
            'wert_cent'   => $wert,
            'rest_cent'   => $wert,
            'service_id'  => (int) ($daten['service_id'] ?? 0),
            'leistung'    => (string) ($daten['leistung'] ?? ''),
            'kaeufer'     => (string) ($daten['kaeufer'] ?? ''),
            'empfaenger'  => (string) ($daten['empfaenger'] ?? ''),
            'nachricht'   => (string) ($daten['nachricht'] ?? ''),
            'customer_id' => (int) ($daten['customer_id'] ?? 0),
            'order_id'    => (int) ($daten['order_id'] ?? 0),
            'gueltig_bis' => (string) ($daten['gueltig_bis'] ?? date('Y-m-d', strtotime('+3 years'))),
            'status'      => 'aktiv',
        ]);
        Audit::schreiben('erstellt', 'voucher', $id, 'Gutschein über ' . Util::geld($wert));
        return $id;
    }

    private static function freierCode(): string
    {
        do {
            $code = 'GP-' . Util::code(8);
        } while (Tenant::count('vouchers', 'code = :c', ['c' => $code]) > 0);
        return $code;
    }

    /** @return array{0:bool,1:string,2:int} [ok, Meldung, eingelöster Betrag] */
    public static function einloesen(string $code, int $betragCent = 0, int $kundeId = 0): array
    {
        $g = Tenant::one('vouchers', 'code = :c', ['c' => strtoupper(trim($code))]);
        if (!$g) {
            return [false, 'Diesen Gutscheincode gibt es nicht.', 0];
        }
        if ($g['status'] !== 'aktiv') {
            return [false, 'Der Gutschein ist bereits eingelöst oder storniert.', 0];
        }
        if ($g['gueltig_bis'] !== '' && $g['gueltig_bis'] < Util::heute()) {
            Tenant::update('vouchers', (int) $g['id'], ['status' => 'abgelaufen']);
            return [false, 'Der Gutschein ist am ' . Util::datum((string) $g['gueltig_bis']) . ' abgelaufen.', 0];
        }

        if ($g['art'] === 'leistung') {
            Tenant::update('vouchers', (int) $g['id'], [
                'status' => 'eingeloest', 'eingeloest' => Util::jetzt(),
                'customer_id' => $kundeId ?: (int) $g['customer_id'],
            ]);
            Audit::schreiben('geaendert', 'voucher', (int) $g['id'], 'Eingelöst: ' . $g['leistung']);
            return [true, 'Leistungsgutschein eingelöst.', 0];
        }

        $rest    = (int) $g['rest_cent'];
        $nutzung = $betragCent > 0 ? min($betragCent, $rest) : $rest;
        $neuerRest = $rest - $nutzung;

        Tenant::update('vouchers', (int) $g['id'], [
            'rest_cent'  => $neuerRest,
            'status'     => $neuerRest > 0 ? 'aktiv' : 'eingeloest',
            'eingeloest' => $neuerRest > 0 ? null : Util::jetzt(),
            'customer_id'=> $kundeId ?: (int) $g['customer_id'],
        ]);
        Audit::schreiben('geaendert', 'voucher', (int) $g['id'], 'Eingelöst: ' . Util::geld($nutzung));

        return [true, $neuerRest > 0
            ? Util::geld($nutzung) . ' eingelöst, ' . Util::geld($neuerRest) . ' Restguthaben.'
            : Util::geld($nutzung) . ' eingelöst. Der Gutschein ist damit aufgebraucht.', $nutzung];
    }

    /**
     * QR-Code als SVG. Enthält keine Fremdbibliothek: Für die Einlösung
     * genügt der Code als Text, der QR-Block ist die bequeme Abkürzung.
     * Erzeugt wird ein Aztec-artiges Muster aus dem Hash – ein optisches
     * Kennzeichen, kein maschinenlesbarer QR. Wer echtes Scannen braucht,
     * hinterlegt in den Einstellungen einen QR-Dienst.
     */
    public static function codeBild(string $code, int $groesse = 120): string
    {
        $hash = hash('sha256', $code);
        $raster = 9;
        $zelle = $groesse / $raster;
        $svg = '<svg width="' . $groesse . '" height="' . $groesse . '" viewBox="0 0 ' . $groesse . ' ' . $groesse
             . '" role="img" aria-label="Gutscheincode ' . Util::attr($code) . '">'
             . '<rect width="' . $groesse . '" height="' . $groesse . '" fill="#fff"/>';
        for ($y = 0; $y < $raster; $y++) {
            for ($x = 0; $x < $raster; $x++) {
                $i = ($y * $raster + $x) % 64;
                if ((hexdec($hash[$i]) % 2) === 1) {
                    $svg .= '<rect x="' . round($x * $zelle, 2) . '" y="' . round($y * $zelle, 2)
                          . '" width="' . round($zelle, 2) . '" height="' . round($zelle, 2) . '" fill="#111"/>';
                }
            }
        }
        return $svg . '</svg>';
    }

    public static function statusFarbe(string $status): string
    {
        return ['aktiv' => 'erfolg', 'eingeloest' => 'info', 'abgelaufen' => 'warnung', 'storniert' => 'gefahr'][$status] ?? '';
    }
}
