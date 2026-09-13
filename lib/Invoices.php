<?php
/**
 * Invoices – Rechnungen und Gutschriften.
 *
 * Eine Rechnung ist ein Dokument, keine Sicht auf den Kunden: Anschrift,
 * Positionen, Preise und Steuersätze werden hineinkopiert. Zieht der Kunde
 * um oder ändert der Pro seine Preise, bleibt die Rechnung so, wie sie
 * gestellt wurde – anders wäre sie kein Beleg.
 *
 * Rechnungsnummern sind lückenlos fortlaufend. Deshalb wird nie gelöscht,
 * sondern storniert: Eine Stornorechnung trägt den Betrag negativ und
 * verweist auf das Original.
 *
 * Die Schnittstelle zu DATEV, lexoffice oder sevDesk ist vorbereitet:
 * `export()` liefert den vollständigen Datensatz, `buchungssaetze()` die
 * Form, die eine Buchhaltung erwartet.
 */
final class Invoices
{
    public const STATUS = [
        'entwurf'     => ['Entwurf', ''],
        'offen'       => ['Offen', 'warnung'],
        'bezahlt'     => ['Bezahlt', 'erfolg'],
        'ueberfaellig'=> ['Überfällig', 'gefahr'],
        'storniert'   => ['Storniert', ''],
    ];

    public static function statusName(string $s): string
    {
        return self::STATUS[$s][0] ?? $s;
    }

    public static function statusFarbe(string $s): string
    {
        return self::STATUS[$s][1] ?? '';
    }

    /**
     * @param array<int,array{titel:string,beschreibung?:string,menge:int,preis_cent:int,steuersatz?:int}> $positionen
     */
    public static function erstellen(array $positionen, array $o = []): int
    {
        return DB::transaktion(function () use ($positionen, $o) {
            $kundeId = (int) ($o['customer_id'] ?? 0);
            $kunde   = $kundeId > 0 ? Tenant::find('customers', $kundeId) : null;

            $brutto = 0;
            $steuer = 0;
            foreach ($positionen as $p) {
                $zeile = (int) $p['preis_cent'] * (int) $p['menge'];
                $satz  = (int) ($p['steuersatz'] ?? self::standardSteuersatz());
                $brutto += $zeile;
                $steuer += (int) round($zeile - $zeile / (1 + $satz / 100));
            }

            $zahlungsziel = (int) Tenant::einstellung('zahlungsziel_tage', 14);
            $status = (string) ($o['status'] ?? 'offen');

            $id = Tenant::insert('invoices', [
                'nummer'      => $status === 'entwurf' ? '' : Commerce::naechsteNummer('invoices', self::praefix()),
                'customer_id' => $kundeId,
                'order_id'    => (int) ($o['order_id'] ?? 0),
                'art'         => (string) ($o['art'] ?? 'rechnung'),
                'empfaenger'  => Util::json(self::anschrift($kunde, $o)),
                'datum'       => (string) ($o['datum'] ?? Util::heute()),
                'faellig'     => (string) ($o['faellig'] ?? date('Y-m-d', strtotime('+' . $zahlungsziel . ' days'))),
                'netto_cent'  => $brutto - $steuer,
                'steuer_cent' => $steuer,
                'summe_cent'  => $brutto,
                'status'      => $status,
                'notiz'       => (string) ($o['notiz'] ?? ''),
                'storno_von'  => (int) ($o['storno_von'] ?? 0),
            ]);

            foreach (array_values($positionen) as $i => $p) {
                Tenant::insert('invoice_items', [
                    'invoice_id'       => $id,
                    'titel'            => (string) $p['titel'],
                    'beschreibung'     => (string) ($p['beschreibung'] ?? ''),
                    'menge'            => (int) $p['menge'],
                    'einzelpreis_cent' => (int) $p['preis_cent'],
                    'steuersatz'       => (int) ($p['steuersatz'] ?? self::standardSteuersatz()),
                    'summe_cent'       => (int) $p['preis_cent'] * (int) $p['menge'],
                    'position'         => $i,
                ]);
            }

            Audit::schreiben('erstellt', 'invoice', $id, 'Rechnung über ' . Util::geld($brutto));
            return $id;
        });
    }

    /** Rechnung aus einer bezahlten Bestellung – der Normalfall. */
    public static function ausBestellung(int $orderId): int
    {
        $order = Tenant::find('orders', $orderId);
        if (!$order) {
            return 0;
        }
        if (Tenant::count('invoices', "order_id = :o AND art = 'rechnung'", ['o' => $orderId]) > 0) {
            return 0;    // schon geschrieben
        }
        $positionen = [];
        foreach (Tenant::all('order_items', 'order_id = :o', ['o' => $orderId], 'id') as $z) {
            $positionen[] = [
                'titel'       => (string) $z['titel'],
                'beschreibung'=> (string) $z['variante'],
                'menge'       => (int) $z['menge'],
                'preis_cent'  => (int) $z['einzelpreis_cent'],
                'steuersatz'  => (int) $z['steuersatz'],
            ];
        }
        if ((int) $order['rabatt_cent'] > 0) {
            $positionen[] = [
                'titel' => 'Rabatt' . ($order['rabattcode'] ? ' (' . $order['rabattcode'] . ')' : ''),
                'menge' => 1, 'preis_cent' => -(int) $order['rabatt_cent'],
                'steuersatz' => self::standardSteuersatz(),
            ];
        }
        $id = self::erstellen($positionen, [
            'customer_id' => (int) $order['customer_id'],
            'order_id'    => $orderId,
            'name'        => (string) $order['name'],
            'email'       => (string) $order['email'],
            'status'      => 'bezahlt',
        ]);
        if ($id > 0) {
            Tenant::update('invoices', $id, [
                'bezahlt_cent' => (int) $order['summe_cent'],
                'bezahlt_am'   => Util::jetzt(),
            ]);
        }
        return $id;
    }

    public static function alsBezahlt(int $id, int $betragCent = 0): void
    {
        $r = Tenant::find('invoices', $id);
        if (!$r) {
            return;
        }
        $betrag = $betragCent > 0 ? $betragCent : (int) $r['summe_cent'];
        Tenant::update('invoices', $id, [
            'bezahlt_cent' => $betrag,
            'status'       => $betrag >= (int) $r['summe_cent'] ? 'bezahlt' : 'offen',
            'bezahlt_am'   => Util::jetzt(),
        ]);
        if ((int) $r['customer_id'] > 0) {
            Tenant::insert('payments', [
                'invoice_id' => $id, 'customer_id' => (int) $r['customer_id'],
                'anbieter' => 'manuell', 'methode' => 'ueberweisung',
                'betrag_cent' => $betrag, 'status' => 'bezahlt',
            ]);
        }
        Audit::schreiben('bezahlt', 'invoice', $id, Util::geld($betrag));
    }

    /** Storno erzeugt eine Gutschrift – die Originalrechnung bleibt bestehen. */
    public static function stornieren(int $id, string $grund = ''): int
    {
        $r = Tenant::find('invoices', $id);
        if (!$r || $r['status'] === 'storniert') {
            return 0;
        }
        $positionen = [];
        foreach (Tenant::all('invoice_items', 'invoice_id = :i', ['i' => $id], 'position') as $z) {
            $positionen[] = [
                'titel' => (string) $z['titel'], 'beschreibung' => (string) $z['beschreibung'],
                'menge' => (int) $z['menge'], 'preis_cent' => -(int) $z['einzelpreis_cent'],
                'steuersatz' => (int) $z['steuersatz'],
            ];
        }
        $gutschrift = self::erstellen($positionen, [
            'customer_id' => (int) $r['customer_id'],
            'art'         => 'gutschrift',
            'status'      => 'bezahlt',
            'storno_von'  => $id,
            'notiz'       => 'Storno zu Rechnung ' . $r['nummer'] . ($grund !== '' ? ': ' . $grund : ''),
        ]);
        Tenant::update('invoices', $id, ['status' => 'storniert']);
        Audit::schreiben('storniert', 'invoice', $id, (string) $r['nummer'] . ' ' . $grund);
        return $gutschrift;
    }

    /** Läuft beim Dashboard mit: Fälligkeit prüfen. */
    public static function faelligkeitPruefen(): int
    {
        return Tenant::updateWhere('invoices', ['status' => 'ueberfaellig'],
            "status = 'offen' AND faellig != '' AND faellig < :heute", ['heute' => Util::heute()]);
    }

    /* ------------------------------------------------------------ Daten */

    public static function positionen(int $id): array
    {
        return Tenant::all('invoice_items', 'invoice_id = :i', ['i' => $id], 'position, id');
    }

    public static function offenerBetrag(): int
    {
        return Tenant::sum('invoices', 'summe_cent - bezahlt_cent',
            'status IN ("offen","ueberfaellig")');
    }

    private static function standardSteuersatz(): int
    {
        return (int) Tenant::einstellung('steuersatz', 19);
    }

    private static function praefix(): string
    {
        return (string) Tenant::einstellung('rechnung_praefix', 'R');
    }

    /** @return array<string,string> eingefrorene Empfängeranschrift */
    private static function anschrift(?array $kunde, array $o): array
    {
        if ($kunde) {
            return [
                'name'    => trim(((string) $kunde['vorname']) . ' ' . ((string) $kunde['nachname'])),
                'strasse' => (string) $kunde['strasse'],
                'plz'     => (string) $kunde['plz'],
                'ort'     => (string) $kunde['ort'],
                'land'    => (string) $kunde['land'],
                'email'   => (string) $kunde['email'],
            ];
        }
        return [
            'name'  => (string) ($o['name'] ?? ''),
            'email' => (string) ($o['email'] ?? ''),
            'strasse' => '', 'plz' => '', 'ort' => '', 'land' => 'DE',
        ];
    }

    /**
     * Buchungssätze für die Übergabe an DATEV, lexoffice oder sevDesk.
     * Die Felder folgen dem kleinsten gemeinsamen Nenner der drei; die
     * eigentliche Schnittstelle ist damit ein Formatierer, kein Umbau.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function buchungssaetze(string $von, string $bis): array
    {
        $saetze = [];
        foreach (Tenant::all('invoices',
            "status != 'entwurf' AND datum >= :von AND datum <= :bis",
            ['von' => $von, 'bis' => $bis], 'datum, nummer') as $r) {
            $empfaenger = Util::ausJson((string) $r['empfaenger']);
            foreach (self::positionen((int) $r['id']) as $z) {
                $saetze[] = [
                    'belegnummer' => (string) $r['nummer'],
                    'belegdatum'  => (string) $r['datum'],
                    'kunde'       => (string) ($empfaenger['name'] ?? ''),
                    'bezeichnung' => (string) $z['titel'],
                    'menge'       => (int) $z['menge'],
                    'brutto'      => (int) $z['summe_cent'] / 100,
                    'steuersatz'  => (int) $z['steuersatz'],
                    'netto'       => round((int) $z['summe_cent'] / (1 + (int) $z['steuersatz'] / 100) / 100, 2),
                    'konto'       => (string) Tenant::einstellung('erloeskonto', '8400'),
                    'art'         => (string) $r['art'],
                ];
            }
        }
        return $saetze;
    }

    /** CSV für die Buchhaltung. */
    public static function csv(string $von, string $bis): string
    {
        $zeilen = self::buchungssaetze($von, $bis);
        $aus = fopen('php://temp', 'r+');
        fputcsv($aus, ['Belegnummer', 'Belegdatum', 'Kunde', 'Bezeichnung', 'Menge',
                       'Brutto', 'Netto', 'Steuersatz', 'Konto', 'Art'], ';');
        foreach ($zeilen as $z) {
            fputcsv($aus, [
                $z['belegnummer'], $z['belegdatum'], $z['kunde'], $z['bezeichnung'], $z['menge'],
                number_format($z['brutto'], 2, ',', ''), number_format($z['netto'], 2, ',', ''),
                $z['steuersatz'], $z['konto'], $z['art'],
            ], ';');
        }
        rewind($aus);
        return (string) stream_get_contents($aus);
    }
}
