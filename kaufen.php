<?php
/**
 * Kauf eines Produkts: Paket, Kurs, Gutschein, Workshop, Ausrüstung.
 *
 * Ein Schritt für die Angaben, dann entweder Stripe oder – wenn kein
 * Schlüssel hinterlegt ist – die Bestellung auf Rechnung. Ohne Stripe soll
 * nichts abbrechen: Ein Pro, der noch kein Konto eingerichtet hat, muss
 * trotzdem verkaufen können.
 */
require __DIR__ . '/lib/bootstrap.php';

Oeffentlich::mandantSetzen();

$schritte = ['Auswahl', 'Deine Angaben', 'Bezahlen'];

/* ------------------------------------------------ Rückkehr von Stripe --- */

if (App::get('erfolg') !== '') {
    $order = Tenant::find('orders', App::getInt('bestellung'));
    if ($order === null) {
        http_response_code(404);
        Oeffentlich::seite('Nicht gefunden', Oeffentlich::kasten('Bestellung nicht gefunden', '',
            Oeffentlich::meldung('Diese Bestellung gibt es nicht.', 'fehler')));
    }
    /*
     * Der Webhook ist die verlässliche Quelle; diese Seite sagt nur, was
     * gerade gilt. Steht die Zahlung noch aus, wird das ehrlich benannt,
     * statt einen Erfolg zu behaupten, den niemand bestätigt hat.
     */
    $bezahlt = (string) $order['status'] === 'bezahlt';
    Oeffentlich::seite('Danke', Oeffentlich::kasten(
        $bezahlt ? 'Danke für deinen Kauf' : 'Bestellung eingegangen', '',
        Oeffentlich::schritte($schritte, 3)
        . '<div class="vorgang__erfolg">'
        . '<div class="vorgang__erfolg-datum">' . Util::h((string) $order['nummer']) . '</div>'
        . '<div class="vorgang__erfolg-zeit">' . Util::h(Util::geld((int) $order['summe_cent'])) . '</div>'
        . '</div>'
        . '<p class="vorgang__text">'
        . ($bezahlt
           ? 'Die Zahlung ist eingegangen. Die Bestätigung mit allen Angaben ist an '
             . Util::h((string) $order['email']) . ' unterwegs.'
           : 'Die Bestellung ist angekommen. Sobald die Zahlung bestätigt ist – das dauert '
             . 'meist nur Sekunden –, bekommst du die Bestätigung an '
             . Util::h((string) $order['email']) . '.')
        . '</p>'
        . '<p class="vorgang__zurueck"><a href="' . Util::attr(Oeffentlich::url('/'))
        . '">Zurück zur Website</a></p>'
    ));
}

/* ------------------------------------------------------------ Produkt --- */

$produktId = App::postInt('p') ?: App::getInt('p');
$produkt   = $produktId > 0 ? Tenant::find('products', $produktId) : null;

if ($produkt === null || (int) $produkt['aktiv'] !== 1) {
    $angebot = Tenant::all('products', 'aktiv = 1', [], 'position, name', 12);
    $liste = '';
    foreach ($angebot as $p) {
        $liste .= '<a class="posten__zeile" href="' . Util::attr(Oeffentlich::url('/kaufen.php', ['p' => (int) $p['id']])) . '">'
                . '<span class="posten__text"><span class="posten__name">' . Util::h((string) $p['name']) . '</span>'
                . '<span class="posten__unter">' . Util::h(Commerce::PRODUKT_ARTEN[(string) $p['art']][0] ?? '') . '</span></span>'
                . '<span class="posten__preis">' . Util::h(Util::geldKurz((int) $p['preis_cent'])) . '</span></a>';
    }
    Oeffentlich::seite('Angebot', Oeffentlich::kasten('Angebot',
        $liste !== '' ? 'Such dir aus, was zu dir passt.' : '',
        $liste !== ''
            ? '<div class="posten">' . $liste . '</div>'
            : Oeffentlich::meldung('Zurzeit steht nichts zum Kauf bereit.', 'warnung')));
}

$menge = max(1, min(20, App::postInt('menge', 1)));

/* --------------------------------------------------------- Bestellen --- */

$fehler = '';
if (App::istPost() && App::aktion() === 'kaufen') {
    $vorname  = trim(App::post('vorname'));
    $nachname = trim(App::post('nachname'));
    $email    = strtolower(trim(App::post('email')));
    $telefon  = trim(App::post('telefon'));
    $code     = trim(App::post('rabattcode'));

    if (Oeffentlich::istRoboter()) {
        $fehler = 'Das sah nach einem automatischen Versand aus. Bitte noch einmal versuchen.';
    } elseif ($vorname === '' || $nachname === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $fehler = 'Bitte Vorname, Nachname und eine gültige E-Mail-Adresse angeben.';
    } elseif (!App::postBool('agb')) {
        $fehler = 'Bitte die Geschäftsbedingungen und die Einwilligung bestätigen.';
    } elseif ((int) $produkt['bestand'] >= 0 && (string) $produkt['art'] === 'merch'
              && (int) $produkt['bestand'] < $menge) {
        $fehler = 'Davon sind nur noch ' . (int) $produkt['bestand'] . ' Stück da.';
    } else {
        $kundeId = Oeffentlich::kundeFinden($email, $vorname, $nachname, $telefon);

        $orderId = Commerce::bestellen([[
            'product_id' => (int) $produkt['id'],
            'titel'      => (string) $produkt['name'],
            'menge'      => $menge,
            'preis_cent' => (int) $produkt['preis_cent'],
            'steuersatz' => (int) $produkt['steuersatz'],
        ]], [
            'customer_id' => $kundeId,
            'email'       => $email,
            'name'        => trim($vorname . ' ' . $nachname),
            'rabattcode'  => $code,
            'quelle'      => 'website',
            'notiz'       => trim(App::post('notiz')),
        ]);

        Oeffentlich::einwilligung($kundeId, 0, 'kauf',
            'Einwilligung zur Verarbeitung der Angaben für Kauf und Abwicklung.', 'kaufen.php');
        if (App::postBool('newsletter')) {
            Tenant::update('customers', $kundeId, ['newsletter' => 1]);
            Oeffentlich::einwilligung($kundeId, 0, 'newsletter',
                'Einwilligung zum Empfang des Newsletters, erteilt beim Kauf.', 'kaufen.php');
        }

        [$url, $stripeFehler] = Stripe::checkout(
            $orderId,
            App::absolut('/kaufen.php') . '?' . http_build_query([
                'w' => (string) (Tenant::workspace()['slug'] ?? ''),
                'erfolg' => 1, 'bestellung' => $orderId,
            ]),
            App::absolut('/kaufen.php') . '?' . http_build_query([
                'w' => (string) (Tenant::workspace()['slug'] ?? ''), 'p' => (int) $produkt['id'],
            ])
        );

        if ($url !== '') {
            header('Location: ' . $url);
            exit;
        }
        $fehler = $stripeFehler !== '' ? $stripeFehler : 'Die Zahlung ließ sich nicht starten.';
    }
}

/* -------------------------------------------------------- Darstellung --- */

$einzel = (int) $produkt['preis_cent'];
$summe  = $einzel * $menge;

$inhalt = Oeffentlich::schritte($schritte, 2);
if ($fehler !== '') {
    $inhalt .= Oeffentlich::meldung($fehler, 'fehler');
}

$inhalt .= '<div class="posten">'
         . '<div class="posten__zeile">'
         . '<span class="posten__text"><span class="posten__name">' . Util::h((string) $produkt['name']) . '</span>'
         . '<span class="posten__unter">' . Util::h(Commerce::PRODUKT_ARTEN[(string) $produkt['art']][0] ?? '')
         . ($menge > 1 ? ' · ' . $menge . ' ×' : '') . '</span></span>'
         . '<span class="posten__preis">' . Util::h(Util::geld($summe)) . '</span>'
         . '</div>'
         . '<div class="posten__zeile posten__summe">'
         . '<span class="posten__text"><span class="posten__name">Gesamt</span>'
         . '<span class="posten__unter">'
         . ((int) Tenant::einstellung('kleinunternehmer', false) === 1
            ? 'Kein Ausweis von Umsatzsteuer nach § 19 UStG.'
            : 'inklusive ' . (int) $produkt['steuersatz'] . ' % Umsatzsteuer')
         . '</span></span>'
         . '<span class="posten__preis">' . Util::h(Util::geld($summe)) . '</span>'
         . '</div></div>';

if ((string) $produkt['beschreibung'] !== '') {
    $inhalt .= '<p class="vorgang__text">' . nl2br(Util::h((string) $produkt['beschreibung'])) . '</p>';
}

$inhalt .= '<form method="post" class="vorgang__form">'
         . '<input type="hidden" name="aktion" value="kaufen">'
         . '<input type="hidden" name="w" value="' . Util::attr((string) (Tenant::workspace()['slug'] ?? '')) . '">'
         . '<input type="hidden" name="p" value="' . (int) $produkt['id'] . '">'
         . '<input type="hidden" name="begonnen" value="' . time() . '">'
         . '<input type="text" name="website" class="honigtopf" tabindex="-1" autocomplete="off" aria-hidden="true">'
         . '<div class="vorgang__paar">'
         . '<div class="vorgang__feld"><label for="vorname">Vorname</label>'
         . '<input id="vorname" name="vorname" required value="' . Util::attr(App::post('vorname')) . '"></div>'
         . '<div class="vorgang__feld"><label for="nachname">Nachname</label>'
         . '<input id="nachname" name="nachname" required value="' . Util::attr(App::post('nachname')) . '"></div>'
         . '</div>'
         . '<div class="vorgang__paar">'
         . '<div class="vorgang__feld"><label for="email">E-Mail</label>'
         . '<input id="email" type="email" name="email" required value="' . Util::attr(App::post('email')) . '"></div>'
         . '<div class="vorgang__feld"><label for="telefon">Telefon (freiwillig)</label>'
         . '<input id="telefon" type="tel" name="telefon" value="' . Util::attr(App::post('telefon')) . '"></div>'
         . '</div>'
         . '<div class="vorgang__paar">'
         . '<div class="vorgang__feld"><label for="menge">Anzahl</label>'
         . '<input id="menge" type="number" name="menge" min="1" max="20" value="' . $menge . '"></div>'
         . '<div class="vorgang__feld"><label for="rabattcode">Gutschein- oder Rabattcode</label>'
         . '<input id="rabattcode" name="rabattcode" value="' . Util::attr(App::post('rabattcode')) . '"></div>'
         . '</div>';

if ((string) $produkt['art'] === 'gutschein') {
    $inhalt .= '<div class="vorgang__feld"><label for="notiz">Für wen ist der Gutschein?</label>'
             . '<input id="notiz" name="notiz" placeholder="Name der beschenkten Person"'
             . ' value="' . Util::attr(App::post('notiz')) . '"></div>';
}

$inhalt .= '<label class="einwilligung"><input type="checkbox" name="agb" value="1" required>'
         . '<span>Ich akzeptiere die <a href="' . Util::attr(Oeffentlich::url('/', ['s' => 'agb']))
         . '">Geschäftsbedingungen</a> und bin mit der Verarbeitung meiner Angaben zur Abwicklung '
         . 'des Kaufs einverstanden.</span></label>'
         . '<label class="einwilligung"><input type="checkbox" name="newsletter" value="1">'
         . '<span>Ich möchte Tipps und Termine per E-Mail bekommen. Abbestellen geht jederzeit '
         . 'mit einem Klick.</span></label>'
         . '<button class="knopf" type="submit">'
         . (Stripe::eingerichtet() ? 'Weiter zur Zahlung' : 'Jetzt kaufen') . '</button>'
         . '<p class="vorgang__klein">'
         . (Stripe::eingerichtet()
            ? 'Die Zahlung läuft über Stripe – Karte, Apple Pay, Google Pay oder SEPA. '
              . 'Kartendaten werden nicht auf diesem Server gespeichert.'
            : 'Du bekommst eine Rechnung per E-Mail.')
         . '</p></form>';

Oeffentlich::seite('Kaufen · ' . Tenant::name(),
    Oeffentlich::kasten((string) $produkt['name'], (string) $produkt['kurztext'], $inhalt));
