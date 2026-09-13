<?php
/**
 * Wartung – was regelmäßig laufen muss, ohne dass ein Cronjob da ist.
 *
 * Viele günstige Hoster bieten keine zeitgesteuerten Aufgaben an, und wer
 * eine anbietet, richtet sie oft nicht ein. Deshalb läuft die Wartung beim
 * Öffnen des Dashboards mit – höchstens einmal je Viertelstunde und mit
 * begrenzter Stückzahl, damit keine Seite darauf wartet.
 *
 * Wer einen Cronjob hat, ruft `cron.php` auf; dann läuft dieselbe Routine
 * zuverlässiger und pünktlicher. Beides schließt sich nicht aus: Die
 * Sperre über den Zeitstempel verhindert doppelte Arbeit.
 */
final class Wartung
{
    private const ABSTAND_SEKUNDEN = 900;

    /** @return array<string,int> was getan wurde */
    public static function laufen(bool $erzwingen = false): array
    {
        $letzter = (int) Tenant::einstellung('wartung_letzter_lauf', 0);
        if (!$erzwingen && time() - $letzter < self::ABSTAND_SEKUNDEN) {
            return [];
        }
        Tenant::einstellungSetzen('wartung_letzter_lauf', time());

        $ergebnis = [];
        try {
            $ergebnis['rechnungen_faellig'] = Invoices::faelligkeitPruefen();
            $ergebnis['pakete_abgelaufen']  = Commerce::paketeAufraeumen();
            $ergebnis['erinnerungen']       = Bookings::erinnerungenVersenden();
            $ergebnis['automationen']       = Automations::laufen(15);
            $ergebnis['zeitgesteuert']      = Automations::zeitgesteuertPruefen();
            $ergebnis['termine_vergangen']  = self::vergangeneTermine();
            Customers::scoresAuffrischen(25);
        } catch (Throwable $e) {
            // Wartung darf das Dashboard nie blockieren.
            Audit::schreiben('wartung_fehler', 'system', 0, $e->getMessage());
        }
        return array_filter($ergebnis);
    }

    /**
     * Bestätigte Termine, die länger als sechs Stunden vorbei sind, gelten
     * als wahrgenommen. Sechs Stunden Puffer, weil ein Pro nicht zwischen
     * zwei Stunden ins Backend geht, um Haken zu setzen.
     */
    private static function vergangeneTermine(): int
    {
        $grenze = date('Y-m-d H:i:s', time() - 6 * 3600);
        $betroffen = Tenant::all('bookings', 'status = "bestaetigt" AND ende < :g', ['g' => $grenze], 'ende', 50);
        foreach ($betroffen as $b) {
            Tenant::update('bookings', (int) $b['id'], ['status' => 'erschienen']);
            if ((int) $b['customer_id'] > 0) {
                Gamification::punkte((int) $b['customer_id'], 25, 'Training wahrgenommen');
                Automations::ausloesen('booking_done', [
                    'customer_id' => (int) $b['customer_id'], 'booking_id' => (int) $b['id'],
                ]);
            }
        }
        return count($betroffen);
    }
}
