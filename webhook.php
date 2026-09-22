<?php
/**
 * Stripe-Webhook.
 *
 * Die verlässliche Quelle für „bezahlt". Die Rückkehrseite im Browser kann
 * ausbleiben – der Kunde schließt den Tab, das Netz bricht ab –, der
 * Webhook kommt trotzdem. Deshalb wird die Bestellung hier verbucht und
 * nicht dort.
 *
 * Antwortet immer mit 200, sobald die Signatur stimmt: Ein Fehlercode
 * lässt Stripe stundenlang erneut zustellen, obwohl das Ereignis
 * angekommen und verstanden ist.
 */
require __DIR__ . '/lib/bootstrap.php';

$koerper   = (string) file_get_contents('php://input');
$signatur  = (string) ($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '');

if ($koerper === '') {
    http_response_code(400);
    exit('leer');
}

/*
 * Ohne hinterlegtes Webhook-Geheimnis wird nichts verarbeitet. Sonst
 * könnte jeder, der die Adresse kennt, Bestellungen als bezahlt melden.
 */
if (!Stripe::signaturPruefen($koerper, $signatur)) {
    http_response_code(400);
    error_log('TeePilot: Stripe-Webhook mit ungültiger Signatur abgewiesen.');
    exit('signatur');
}

$ereignis = Util::ausJson($koerper, []);
$typ      = (string) ($ereignis['type'] ?? '');
$objekt   = (array) ($ereignis['data']['object'] ?? []);

/* Der Mandant steht in den Metadaten, die checkout() mitgegeben hat. */
$workspaceId = (int) ($objekt['metadata']['workspace_id'] ?? 0);
$orderId     = (int) ($objekt['metadata']['order_id'] ?? ($objekt['client_reference_id'] ?? 0));

if ($workspaceId > 0) {
    Tenant::setzen($workspaceId);
}

http_response_code(200);

if ($orderId === 0 || !Tenant::gesetzt()) {
    exit('ohne bezug');
}

switch ($typ) {
    case 'checkout.session.completed':
    case 'payment_intent.succeeded':
        /*
         * alsBezahlt() ist absichtlich mehrfach aufrufbar und tut beim
         * zweiten Mal nichts – Stripe stellt dasselbe Ereignis gelegentlich
         * doppelt zu, und eine doppelte Rechnung wäre ein echter Schaden.
         */
        Commerce::alsBezahlt($orderId, 'karte', (string) ($objekt['id'] ?? ''));
        Audit::schreiben('bezahlt', 'order', $orderId, 'Stripe: ' . $typ);
        break;

    case 'charge.refunded':
    case 'payment_intent.payment_failed':
        $order = Tenant::find('orders', $orderId);
        if ($order !== null) {
            $neu = $typ === 'charge.refunded' ? 'erstattet' : 'fehlgeschlagen';
            Tenant::update('orders', $orderId, ['status' => $neu]);
            Audit::schreiben('geaendert', 'order', $orderId, 'Stripe: ' . $neu);
            Notify::senden('payment',
                $typ === 'charge.refunded' ? 'Zahlung erstattet' : 'Zahlung fehlgeschlagen',
                (string) $order['nummer'] . ' · ' . Util::geld((int) $order['summe_cent']),
                '/app/zahlungen.php');
        }
        break;

    default:
        // Alles andere wird bewusst ignoriert, aber quittiert.
        break;
}

echo 'ok';
