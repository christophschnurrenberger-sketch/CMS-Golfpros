<?php
/** Liefert Rechnungen, Gutscheine und Trainingspläne als PDF aus. */
require __DIR__ . '/../lib/bootstrap.php';

Auth::fordern('modul.dashboard');

$art = App::get('art');
$id  = App::getInt('id');

/*
 * Die Zweige brauchen kein break: ausliefern() ist als `never` deklariert
 * und beendet die Anfrage mit dem fertigen PDF. Faellt der Aufruf doch
 * einmal durch, greift am Ende der Datei die 400er-Antwort.
 */
try {
    switch ($art) {
        case 'rechnung':
            Auth::fordern('invoices.view');
            $r = Tenant::find('invoices', $id);
            Belege::rechnung($id)->ausliefern(
                'Rechnung-' . ($r['nummer'] ?? $id) . '.pdf', App::get('download') === '1');

        case 'gutschein':
            Auth::fordern('products.view');
            $g = Tenant::find('vouchers', $id);
            Belege::gutschein($id)->ausliefern('Gutschein-' . ($g['code'] ?? $id) . '.pdf');

        case 'trainingsplan':
            Auth::fordern('training.view');
            $p = Tenant::find('training_plans', $id);
            Belege::trainingsplan($id)->ausliefern(
                'Trainingsplan-' . Util::slug((string) ($p['name'] ?? 'plan')) . '.pdf');
    }
} catch (Throwable $e) {
    http_response_code(404);
    exit('Dokument nicht gefunden.');
}

http_response_code(400);
exit('Unbekannte Dokumentart.');
