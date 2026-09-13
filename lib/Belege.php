<?php
/**
 * Belege – die druckbaren Dokumente: Rechnung, Gutschein, Trainingsplan.
 *
 * Getrennt von den Fachklassen, weil sich das Aussehen eines Belegs
 * häufiger ändert als seine Berechnung. Alle drei benutzen dieselbe
 * Kopfzeile mit der Marke des Pros – auch das Papier ist Markenauftritt.
 */
final class Belege
{
    private static function farbe(): array
    {
        $hex = ltrim((string) Tenant::branding()['primaer'], '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }

    private static function kopf(PDF $pdf, string $titel, string $nebentitel = ''): float
    {
        $farbe = self::farbe();
        $pdf->rechteck(0, 0, $pdf->seitenBreite(), 3, $farbe);
        $pdf->text(20, 24, Tenant::name(), 15, true, $farbe);

        $absender = trim((string) Tenant::einstellung('rechnung_absender', ''));
        if ($absender === '') {
            $ort = Tenant::one('locations', 'aktiv = 1');
            $absender = $ort
                ? trim(((string) $ort['strasse']) . ', ' . ((string) $ort['plz']) . ' ' . ((string) $ort['ort']))
                : '';
        }
        if ($absender !== '') {
            $pdf->text(20, 30, $absender, 8, false, [110, 110, 104]);
        }

        $pdf->textRechts($pdf->seitenBreite() - 20, 24, $titel, 15, true);
        if ($nebentitel !== '') {
            $pdf->textRechts($pdf->seitenBreite() - 20, 31, $nebentitel, 9, false, [110, 110, 104]);
        }
        return 44;
    }

    private static function fuss(PDF $pdf): void
    {
        $y = $pdf->seitenHoehe() - 22;
        $pdf->linie(20, $y, $pdf->seitenBreite() - 20, $y);
        $zeilen = array_filter([
            trim((string) Tenant::einstellung('rechnung_fuss', '')),
            trim((string) Tenant::einstellung('steuernummer', '') !== ''
                ? 'Steuernummer: ' . Tenant::einstellung('steuernummer', '') : ''),
            trim((string) Tenant::einstellung('bank', '')),
        ]);
        foreach (array_values($zeilen) as $i => $zeile) {
            $pdf->text(20, $y + 5 + $i * 4, $zeile, 7.5, false, [120, 120, 114]);
        }
    }

    /* ------------------------------------------------------- Rechnung -- */

    public static function rechnung(int $id): PDF
    {
        $r = Tenant::find('invoices', $id);
        if (!$r) {
            throw new RuntimeException('Rechnung nicht gefunden.');
        }
        $empfaenger = Util::ausJson((string) $r['empfaenger']);
        $positionen = Invoices::positionen($id);
        $gutschrift = $r['art'] === 'gutschrift';

        $pdf = new PDF();
        $y = self::kopf($pdf, $gutschrift ? 'Gutschrift' : 'Rechnung',
            (string) ($r['nummer'] ?: 'Entwurf'));

        /* Empfänger */
        $pdf->text(20, $y, 'Rechnung an', 8, false, [140, 140, 134]);
        $y += 6;
        foreach (array_filter([
            (string) ($empfaenger['name'] ?? ''),
            (string) ($empfaenger['strasse'] ?? ''),
            trim(((string) ($empfaenger['plz'] ?? '')) . ' ' . ((string) ($empfaenger['ort'] ?? ''))),
        ]) as $zeile) {
            $pdf->text(20, $y, $zeile, 10);
            $y += 5;
        }

        /* Eckdaten rechts */
        $rechts = $pdf->seitenBreite() - 20;
        $yr = 50;
        foreach ([
            ['Rechnungsdatum', Util::datum((string) $r['datum'])],
            ['Fällig am', Util::datum((string) $r['faellig'])],
            ['Status', Invoices::statusName((string) $r['status'])],
        ] as [$label, $wert]) {
            $pdf->textRechts($rechts - 30, $yr, $label, 8.5, false, [140, 140, 134]);
            $pdf->textRechts($rechts, $yr, $wert, 8.5, true);
            $yr += 5;
        }

        $y = max($y, $yr) + 12;

        /* Positionen */
        $spalten = [20, 118, 138, 165, $pdf->seitenBreite() - 20];
        $pdf->rechteck(20, $y - 4.5, $pdf->seitenBreite() - 40, 8, [246, 246, 243]);
        $pdf->text($spalten[0] + 2, $y, 'Leistung', 8.5, true, [90, 90, 84]);
        $pdf->textRechts($spalten[2], $y, 'Menge', 8.5, true, [90, 90, 84]);
        $pdf->textRechts($spalten[3], $y, 'Einzel', 8.5, true, [90, 90, 84]);
        $pdf->textRechts($spalten[4] - 2, $y, 'Summe', 8.5, true, [90, 90, 84]);
        $y += 9;

        foreach ($positionen as $p) {
            $pdf->text($spalten[0] + 2, $y, Util::kuerzen((string) $p['titel'], 58), 9.5);
            $pdf->textRechts($spalten[2], $y, (string) (int) $p['menge'], 9.5);
            $pdf->textRechts($spalten[3], $y, Util::geld((int) $p['einzelpreis_cent']), 9.5);
            $pdf->textRechts($spalten[4] - 2, $y, Util::geld((int) $p['summe_cent']), 9.5, true);
            if ((string) $p['beschreibung'] !== '') {
                $y += 4.2;
                $pdf->text($spalten[0] + 2, $y, Util::kuerzen((string) $p['beschreibung'], 70), 8, false, [130, 130, 124]);
            }
            $y += 6.5;
            $pdf->linie(20, $y - 2.5, $pdf->seitenBreite() - 20, $y - 2.5, 0.2, [232, 232, 228]);
        }

        /* Summen */
        $y += 4;
        $linksSumme = $pdf->seitenBreite() - 90;
        foreach ([
            ['Netto', Util::geld((int) $r['netto_cent']), false],
            ['Umsatzsteuer', Util::geld((int) $r['steuer_cent']), false],
        ] as [$label, $wert, $fett]) {
            $pdf->text($linksSumme, $y, $label, 9.5, $fett, [110, 110, 104]);
            $pdf->textRechts($pdf->seitenBreite() - 20, $y, $wert, 9.5, $fett);
            $y += 5.5;
        }
        $pdf->linie($linksSumme, $y - 1, $pdf->seitenBreite() - 20, $y - 1, 0.4, [60, 60, 56]);
        $y += 4;
        $pdf->text($linksSumme, $y, $gutschrift ? 'Gutschriftbetrag' : 'Gesamtbetrag', 11, true);
        $pdf->textRechts($pdf->seitenBreite() - 20, $y, Util::geld((int) $r['summe_cent']), 11, true, self::farbe());

        $y += 14;
        if ((string) $r['notiz'] !== '') {
            $y += $pdf->absatz(20, $y, 120, (string) $r['notiz'], 9);
            $y += 4;
        }

        $kleinunternehmer = (bool) Tenant::einstellung('kleinunternehmer', false);
        if ($kleinunternehmer) {
            $pdf->absatz(20, $y, 120, 'Gemäß § 19 UStG wird keine Umsatzsteuer berechnet.', 8.5);
            $y += 8;
        }
        if ($r['status'] === 'offen' || $r['status'] === 'ueberfaellig') {
            $pdf->absatz(20, $y, 120,
                'Bitte überweise den Betrag bis zum ' . Util::datum((string) $r['faellig'])
                . ' unter Angabe der Rechnungsnummer ' . $r['nummer'] . '.', 9);
        }

        self::fuss($pdf);
        return $pdf;
    }

    /* ------------------------------------------------------- Gutschein - */

    public static function gutschein(int $id): PDF
    {
        $g = Tenant::find('vouchers', $id);
        if (!$g) {
            throw new RuntimeException('Gutschein nicht gefunden.');
        }
        $farbe = self::farbe();
        $pdf = new PDF('A5');

        $pdf->rechteck(0, 0, $pdf->seitenBreite(), 42, $farbe);
        $pdf->text(16, 20, Tenant::name(), 15, true, [255, 255, 255]);
        $pdf->text(16, 29, 'Gutschein', 11, false, [235, 240, 237]);

        $y = 60;
        $wert = $g['art'] === 'wert'
            ? Util::geldKurz((int) $g['wert_cent'])
            : (string) $g['leistung'];
        $pdf->text(16, $y, $wert, 30, true, $farbe);
        $y += 16;

        if ((string) $g['empfaenger'] !== '') {
            $pdf->text(16, $y, 'Für ' . $g['empfaenger'], 11);
            $y += 7;
        }
        if ((string) $g['nachricht'] !== '') {
            $y += $pdf->absatz(16, $y, $pdf->seitenBreite() - 32, (string) $g['nachricht'], 9.5) + 4;
        }

        $y = $pdf->seitenHoehe() - 54;
        $pdf->linie(16, $y, $pdf->seitenBreite() - 16, $y);
        $y += 8;
        $pdf->text(16, $y, 'Code', 8, false, [140, 140, 134]);
        $pdf->text(16, $y + 6, (string) $g['code'], 14, true);
        if ((string) $g['gueltig_bis'] !== '') {
            $pdf->textRechts($pdf->seitenBreite() - 16, $y, 'Gültig bis', 8, false, [140, 140, 134]);
            $pdf->textRechts($pdf->seitenBreite() - 16, $y + 6, Util::datum((string) $g['gueltig_bis']), 11, true);
        }
        $pdf->text(16, $pdf->seitenHoehe() - 20,
            'Einzulösen bei ' . Tenant::name() . '. Keine Barauszahlung.', 7.5, false, [130, 130, 124]);

        return $pdf;
    }

    /* ---------------------------------------------------- Trainingsplan */

    public static function trainingsplan(int $id): PDF
    {
        $plan = Tenant::find('training_plans', $id);
        if (!$plan) {
            throw new RuntimeException('Trainingsplan nicht gefunden.');
        }
        $kunde = (int) $plan['customer_id'] > 0 ? Tenant::find('customers', (int) $plan['customer_id']) : null;

        $pdf = new PDF();
        $y = self::kopf($pdf, 'Trainingsplan', $kunde ? Customers::name($kunde) : '');

        $pdf->text(20, $y, (string) $plan['name'], 14, true);
        $y += 7;
        if ((string) $plan['ziel'] !== '') {
            $pdf->text(20, $y, 'Ziel: ' . $plan['ziel'], 9.5, false, [110, 110, 104]);
            $y += 6;
        }
        $pdf->text(20, $y, (int) $plan['wochen'] . ' Wochen · ' . (int) $plan['einheiten_woche']
                 . ' Einheiten pro Woche', 9, false, [110, 110, 104]);
        $y += 10;

        $einheiten = Training::planNachWochen($id);
        foreach ($einheiten as $woche => $wochenEinheiten) {
            if ($y > $pdf->seitenHoehe() - 45) {
                self::fuss($pdf);
                $pdf->neueSeite();
                $y = self::kopf($pdf, 'Trainingsplan', $kunde ? Customers::name($kunde) : '');
            }
            $pdf->rechteck(20, $y - 4.5, $pdf->seitenBreite() - 40, 8, [246, 246, 243]);
            $pdf->text(22, $y, 'Woche ' . $woche, 10, true, self::farbe());
            $y += 10;

            foreach ($wochenEinheiten as $nummer => $uebungen) {
                $pdf->text(22, $y, 'Einheit ' . $nummer, 9, true);
                $y += 5.5;
                foreach ($uebungen as $u) {
                    $pdf->text(26, $y, '– ' . ((string) $u['titel']), 9.5);
                    $rechts = trim(((int) $u['dauer_min'] > 0 ? (int) $u['dauer_min'] . ' Min' : '')
                            . ((string) $u['wiederholungen'] !== '' ? '  ' . $u['wiederholungen'] : ''));
                    if ($rechts !== '') {
                        $pdf->textRechts($pdf->seitenBreite() - 20, $y, $rechts, 8.5, false, [120, 120, 114]);
                    }
                    $y += 5;
                    if ((string) $u['notiz'] !== '') {
                        $y += $pdf->absatz(29, $y, 140, (string) $u['notiz'], 8) + 1;
                    }
                }
                $y += 3;
            }
            $y += 3;
        }

        self::fuss($pdf);
        return $pdf;
    }
}
