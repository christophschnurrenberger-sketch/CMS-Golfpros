<?php
/**
 * Commerce – Produkte, Bestellungen, Pakete, Gutscheine.
 *
 * Ein verkauftes Paket ist nicht dasselbe wie das Paket im Katalog: Beim
 * Kauf wird eine Kopie mit Guthaben, Ablaufdatum und Preis angelegt. Ändert
 * der Pro später Preis oder Umfang, bleibt das gekaufte Guthaben, wie es
 * gekauft wurde. Dasselbe gilt für Bestellzeilen – sie kopieren Titel und
 * Preis, damit alte Belege reproduzierbar bleiben.
 */
final class Commerce
{
    public const PRODUKT_ARTEN = [
        'paket'     => ['Trainingspaket', 'ticket'],
        'einzel'    => ['Einzeltraining', 'calendar'],
        'kurs'      => ['Online-Kurs', 'courses'],
        'gutschein' => ['Gutschein', 'gift'],
        'workshop'  => ['Workshop', 'events'],
        'event'     => ['Event', 'flag'],
        'merch'     => ['Ausrüstung', 'products'],
    ];

    /**
     * Welche Produktart woran hängt.
     *
     * `ref_id` am Produkt ist die Verbindung zu dem, was der Kauf auslöst:
     * ein Paket gutschreiben, in einen Kurs einschreiben, zu einem Event
     * anmelden – siehe positionEinloesen(). Ohne diese Verbindung kauft
     * jemand ein Paket und bekommt kein Guthaben, und niemand merkt es.
     *
     * Die übrigen Arten stehen für sich: Ein Gutschein entsteht aus dem
     * Preis, ein Ball ist ein Ball.
     */
    public const BEZUG = [
        'paket'    => 'packages',
        'kurs'     => 'courses',
        'workshop' => 'events',
        'event'    => 'events',
    ];

    /* ------------------------------------------------------- Bestellung */

    /**
     * Legt eine Bestellung mit Positionen an und rechnet Steuer heraus.
     * Die Preise sind Bruttopreise – so werden sie in Deutschland
     * ausgezeichnet, und so hat sie der Kunde gesehen.
     *
     * @param array<int,array{product_id?:int,titel:string,menge:int,preis_cent:int,steuersatz?:int,variante?:string}> $positionen
     */
    public static function bestellen(array $positionen, array $o = []): int
    {
        return DB::transaktion(function () use ($positionen, $o) {
            $brutto = 0;
            foreach ($positionen as $p) {
                $brutto += (int) $p['preis_cent'] * (int) $p['menge'];
            }

            $rabatt = 0;
            $code = strtoupper(trim((string) ($o['rabattcode'] ?? '')));
            if ($code !== '') {
                $rabatt = self::rabattBerechnen($code, $brutto);
            }
            $summe = max(0, $brutto - $rabatt);

            /* Steuer aus dem Bruttobetrag herausrechnen, Position für Position. */
            $steuer = 0;
            foreach ($positionen as $p) {
                $zeile = (int) $p['preis_cent'] * (int) $p['menge'];
                $anteil = $brutto > 0 ? $zeile / $brutto : 0;
                $zeileNachRabatt = (int) round($zeile - $rabatt * $anteil);
                $satz = (int) ($p['steuersatz'] ?? 19);
                $steuer += (int) round($zeileNachRabatt - $zeileNachRabatt / (1 + $satz / 100));
            }

            $orderId = Tenant::insert('orders', [
                'nummer'      => self::naechsteNummer('orders', 'B'),
                'customer_id' => (int) ($o['customer_id'] ?? 0),
                'email'       => (string) ($o['email'] ?? ''),
                'name'        => (string) ($o['name'] ?? ''),
                'netto_cent'  => $summe - $steuer,
                'steuer_cent' => $steuer,
                'rabatt_cent' => $rabatt,
                'summe_cent'  => $summe,
                'waehrung'    => (string) (Tenant::workspace()['waehrung'] ?? 'EUR'),
                'status'      => (string) ($o['status'] ?? 'offen'),
                'zahlungsart' => (string) ($o['zahlungsart'] ?? ''),
                'rabattcode'  => $code,
                'notiz'       => (string) ($o['notiz'] ?? ''),
                'quelle'      => (string) ($o['quelle'] ?? 'website'),
            ]);

            foreach ($positionen as $p) {
                $zeile = (int) $p['preis_cent'] * (int) $p['menge'];
                Tenant::insert('order_items', [
                    'order_id'         => $orderId,
                    'product_id'       => (int) ($p['product_id'] ?? 0),
                    'titel'            => (string) $p['titel'],
                    'variante'         => (string) ($p['variante'] ?? ''),
                    'menge'            => (int) $p['menge'],
                    'einzelpreis_cent' => (int) $p['preis_cent'],
                    'steuersatz'       => (int) ($p['steuersatz'] ?? 19),
                    'summe_cent'       => $zeile,
                ]);
            }

            if ($code !== '' && $rabatt > 0) {
                DB::query('UPDATE discounts SET genutzt = genutzt + 1 WHERE workspace_id = :w AND code = :c',
                    ['w' => Tenant::id(), 'c' => $code]);
            }
            Audit::schreiben('erstellt', 'order', $orderId, 'Bestellung über ' . Util::geld($summe));
            return $orderId;
        });
    }

    /**
     * Bucht eine Bestellung als bezahlt und löst aus, was daran hängt:
     * Paket gutschreiben, Kurs freischalten, Gutschein erzeugen, Rechnung
     * schreiben. Genau einmal – ein zweiter Aufruf tut nichts.
     */
    public static function alsBezahlt(int $orderId, string $zahlungsart = 'karte', string $externId = ''): void
    {
        $order = Tenant::find('orders', $orderId);
        if (!$order || $order['status'] === 'bezahlt') {
            return;
        }
        DB::transaktion(function () use ($order, $orderId, $zahlungsart, $externId) {
            Tenant::update('orders', $orderId, [
                'status' => 'bezahlt', 'zahlungsart' => $zahlungsart, 'bezahlt_am' => Util::jetzt(),
            ]);
            Tenant::insert('payments', [
                'order_id'    => $orderId,
                'customer_id' => (int) $order['customer_id'],
                'anbieter'    => $externId !== '' ? 'stripe' : 'manuell',
                'methode'     => $zahlungsart,
                'betrag_cent' => (int) $order['summe_cent'],
                'gebuehr_cent'=> $externId !== '' ? Stripe::gebuehr((int) $order['summe_cent'], $zahlungsart) : 0,
                'status'      => 'bezahlt',
                'extern_id'   => $externId,
            ]);

            foreach (Tenant::all('order_items', 'order_id = :o', ['o' => $orderId]) as $zeile) {
                self::positionEinloesen($order, $zeile);
            }

            if ((int) $order['customer_id'] > 0) {
                Customers::aktivitaet((int) $order['customer_id']);
                Automations::ausloesen('purchase', [
                    'customer_id' => (int) $order['customer_id'], 'order_id' => $orderId,
                ]);
            }
            Invoices::ausBestellung($orderId);
        });

        Notify::senden('payment', 'Zahlung eingegangen: ' . Util::geld((int) $order['summe_cent']),
            ($order['name'] ?: Customers::nameVonId((int) $order['customer_id'])) . ' · Bestellung ' . $order['nummer'],
            '/app/zahlungen.php');
        Audit::schreiben('bezahlt', 'order', $orderId, Util::geld((int) $order['summe_cent']));
    }

    /** Was beim Bezahlen je Produktart geschieht. */
    private static function positionEinloesen(array $order, array $zeile): void
    {
        $produkt = (int) $zeile['product_id'] > 0 ? Tenant::find('products', (int) $zeile['product_id']) : null;
        if (!$produkt) {
            return;
        }
        $kundeId = (int) $order['customer_id'];

        for ($i = 0; $i < (int) $zeile['menge']; $i++) {
            switch ((string) $produkt['art']) {
                case 'paket':
                    if ($kundeId > 0 && (int) $produkt['ref_id'] > 0) {
                        self::paketGutschreiben($kundeId, (int) $produkt['ref_id'], (int) $order['id']);
                    }
                    break;

                case 'kurs':
                    if ($kundeId > 0 && (int) $produkt['ref_id'] > 0) {
                        Courses::einschreiben((int) $produkt['ref_id'], $kundeId, (int) $order['id']);
                    }
                    break;

                case 'gutschein':
                    Vouchers::erzeugen([
                        'art' => 'wert', 'wert_cent' => (int) $zeile['einzelpreis_cent'],
                        'kaeufer' => (string) $order['name'], 'customer_id' => $kundeId,
                        'order_id' => (int) $order['id'],
                    ]);
                    break;

                case 'event':
                case 'workshop':
                    if ($kundeId > 0 && (int) $produkt['ref_id'] > 0) {
                        Events::anmelden((int) $produkt['ref_id'], $kundeId, (int) $order['id']);
                    }
                    break;

                case 'merch':
                    if ((int) $produkt['bestand'] >= 0) {
                        Tenant::update('products', (int) $produkt['id'],
                            ['bestand' => max(0, (int) $produkt['bestand'] - 1)]);
                    }
                    break;
            }
        }
    }

    /* ----------------------------------------------------------- Pakete */

    public static function paketGutschreiben(int $kundeId, int $paketId, int $orderId = 0): int
    {
        $paket = Tenant::find('packages', $paketId);
        if (!$paket) {
            return 0;
        }
        $id = Tenant::insert('customer_packages', [
            'customer_id'      => $kundeId,
            'package_id'       => $paketId,
            'name'             => (string) $paket['name'],
            'einheiten_gesamt' => (int) $paket['einheiten'],
            'einheiten_genutzt'=> 0,
            'preis_cent'       => (int) $paket['preis_cent'],
            'order_id'         => $orderId,
            'gekauft'          => Util::jetzt(),
            'laeuft_ab'        => date('Y-m-d H:i:s', strtotime('+' . (int) $paket['gueltig_tage'] . ' days')),
            'status'           => 'aktiv',
        ]);
        Notify::senden('customer', 'Paket verkauft: ' . $paket['name'],
            Customers::nameVonId($kundeId) . ' hat ' . (int) $paket['einheiten'] . ' Einheiten.',
            '/app/kunde.php?id=' . $kundeId);
        return $id;
    }

    /** @return array<int,array<string,mixed>> nutzbare Pakete eines Kunden */
    public static function offenePakete(int $kundeId): array
    {
        return Tenant::all('customer_packages',
            "customer_id = :k AND status = 'aktiv' AND einheiten_genutzt < einheiten_gesamt",
            ['k' => $kundeId], 'laeuft_ab');
    }

    /** Läuft beim Dashboard mit: abgelaufene Pakete schließen. */
    public static function paketeAufraeumen(): int
    {
        return Tenant::updateWhere('customer_packages', ['status' => 'abgelaufen'],
            "status = 'aktiv' AND laeuft_ab IS NOT NULL AND laeuft_ab < :jetzt", ['jetzt' => Util::jetzt()]);
    }

    /* --------------------------------------------------------- Rabatte */

    public static function rabattBerechnen(string $code, int $betrag): int
    {
        $r = Tenant::one('discounts', 'code = :c AND aktiv = 1', ['c' => strtoupper($code)]);
        if (!$r) {
            return 0;
        }
        if ($r['gueltig_von'] && $r['gueltig_von'] > Util::jetzt()) {
            return 0;
        }
        if ($r['gueltig_bis'] && $r['gueltig_bis'] < Util::jetzt()) {
            return 0;
        }
        if ((int) $r['limit_gesamt'] > 0 && (int) $r['genutzt'] >= (int) $r['limit_gesamt']) {
            return 0;
        }
        if ($betrag < (int) $r['mindestwert_cent']) {
            return 0;
        }
        return $r['art'] === 'prozent'
            ? (int) round($betrag * (int) $r['wert'] / 100)
            : min($betrag, (int) $r['wert']);
    }

    /* -------------------------------------------------------- Nummern -- */

    /**
     * Fortlaufende Nummer je Jahr: B-2026-0007.
     * Die Lücke, die eine gelöschte Bestellung hinterlässt, bleibt – bei
     * Rechnungen verlangt das die Finanzverwaltung, und zwei Systeme mit
     * unterschiedlicher Logik wären eine Fehlerquelle mehr.
     */
    public static function naechsteNummer(string $tabelle, string $praefix): string
    {
        $jahr = date('Y');
        $muster = $praefix . '-' . $jahr . '-%';
        $letzte = (string) DB::value(
            'SELECT nummer FROM ' . $tabelle . ' WHERE workspace_id = :w AND nummer LIKE :m ORDER BY id DESC LIMIT 1',
            ['w' => Tenant::id(), 'm' => $muster], ''
        );
        $n = $letzte !== '' ? ((int) substr($letzte, strrpos($letzte, '-') + 1)) + 1 : 1;
        return sprintf('%s-%s-%04d', $praefix, $jahr, $n);
    }

    /* -------------------------------------------------------- Auswertung */

    /**
     * Umsatz eines Zeitraums.
     *
     * Zwei Quellen, weil das Geld aus zwei Richtungen kommt: bezahlte
     * Bestellungen aus dem Shop und **direkt bezahlte Termine** – die
     * Einzelstunde, die am Tresen oder per Überweisung beglichen wird.
     * Termine, die aus einem Paket laufen, zählen hier nicht: Sie wurden
     * beim Paketkauf bereits vereinnahmt. Doppelt zu zählen wäre die
     * bequemere, aber falsche Rechnung.
     */
    public static function umsatz(string $von, string $bis): int
    {
        $bestellungen = Tenant::sum('orders', 'summe_cent',
            'status = "bezahlt" AND bezahlt_am >= :von AND bezahlt_am <= :bis',
            ['von' => $von . ' 00:00:00', 'bis' => $bis . ' 23:59:59']);

        $termine = Tenant::sum('bookings', 'preis_cent',
            'bezahlt = 1 AND order_id = 0 AND customer_package_id = 0
             AND status IN ("bestaetigt","erschienen")
             AND start >= :von AND start <= :bis',
            ['von' => $von . ' 00:00:00', 'bis' => $bis . ' 23:59:59']);

        return $bestellungen + $termine;
    }

    /** Nur der Anteil aus Terminen – für die Aufschlüsselung in der Auswertung. */
    public static function umsatzTermine(string $von, string $bis): int
    {
        return Tenant::sum('bookings', 'preis_cent',
            'bezahlt = 1 AND order_id = 0 AND customer_package_id = 0
             AND status IN ("bestaetigt","erschienen")
             AND start >= :von AND start <= :bis',
            ['von' => $von . ' 00:00:00', 'bis' => $bis . ' 23:59:59']);
    }

    /** @return array<int,array{titel:string,anzahl:int,umsatz:int}> */
    public static function bestseller(int $limit = 6, int $tage = 90): array
    {
        $zeilen = DB::all(
            "SELECT i.titel, SUM(i.menge) AS anzahl, SUM(i.summe_cent) AS umsatz
             FROM order_items i JOIN orders o ON o.id = i.order_id
             WHERE i.workspace_id = :w AND o.status = 'bezahlt' AND o.bezahlt_am >= :seit
             GROUP BY i.titel ORDER BY umsatz DESC LIMIT " . (int) $limit,
            ['w' => Tenant::id(), 'seit' => date('Y-m-d', strtotime('-' . $tage . ' days'))]
        );
        $liste = [];
        foreach ($zeilen as $z) {
            $liste[(string) $z['titel']] = ['titel' => (string) $z['titel'],
                'anzahl' => (int) $z['anzahl'], 'umsatz' => (int) $z['umsatz']];
        }

        /* Direkt bezahlte Termine gehören in dieselbe Rangliste – für einen
           Pro ist die Einzelstunde das meistverkaufte Produkt. */
        foreach (DB::all(
            "SELECT titel, COUNT(*) AS anzahl, SUM(preis_cent) AS umsatz FROM bookings
             WHERE workspace_id = :w AND bezahlt = 1 AND order_id = 0 AND customer_package_id = 0
               AND status IN ('bestaetigt','erschienen') AND start >= :seit
             GROUP BY titel",
            ['w' => Tenant::id(), 'seit' => date('Y-m-d', strtotime('-' . $tage . ' days'))]
        ) as $z) {
            $titel = (string) $z['titel'];
            if (isset($liste[$titel])) {
                $liste[$titel]['anzahl'] += (int) $z['anzahl'];
                $liste[$titel]['umsatz'] += (int) $z['umsatz'];
            } else {
                $liste[$titel] = ['titel' => $titel, 'anzahl' => (int) $z['anzahl'], 'umsatz' => (int) $z['umsatz']];
            }
        }

        usort($liste, static fn($a, $b) => $b['umsatz'] <=> $a['umsatz']);
        return array_slice($liste, 0, $limit);
    }
}
