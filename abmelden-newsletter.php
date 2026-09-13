<?php
/**
 * Newsletter abbestellen – ein Klick, keine Rückfrage, kein Konto.
 *
 * Rechtlich vorgeschrieben und geschäftlich klug: Wer sich nicht mit zwei
 * Klicks abmelden kann, drückt stattdessen auf „Spam" – und das beschädigt
 * die Zustellbarkeit aller künftigen Mails.
 */
require __DIR__ . '/lib/bootstrap.php';

$token = App::get('t');
$kunde = $token !== ''
    ? DB::one("SELECT * FROM customers WHERE portal_token = :t AND portal_token != ''", ['t' => $token])
    : null;

if ($kunde === null) {
    http_response_code(404);
    $erster = Tenant::erster();
    if ($erster !== null) {
        Tenant::setzen((int) $erster['id']);
    }
    Oeffentlich::seite('Link ungültig', Oeffentlich::kasten('Der Link stimmt nicht mehr', '',
        Oeffentlich::meldung('Dieser Abmeldelink gehört zu keiner Adresse mehr. Vielleicht ist die '
          . 'Abmeldung schon erfolgt.', 'warnung')));
}

Tenant::setzen((int) $kunde['workspace_id']);
$kundeId = (int) $kunde['id'];

/* Die Abmeldung wirkt sofort – nicht erst nach einer Bestätigung. */
$warAn = (int) $kunde['newsletter'] === 1;
if ($warAn) {
    Tenant::update('customers', $kundeId, ['newsletter' => 0]);
    Tenant::insert('consents', [
        'customer_id' => $kundeId,
        'typ'     => 'newsletter',
        'erteilt' => 0,
        'text'    => 'Widerruf über den Abmeldelink im Newsletter.',
        'quelle'  => 'abmelden-newsletter.php',
        'ip'      => '',
        'erstellt' => Util::jetzt(),
    ]);
    Audit::schreiben('geaendert', 'customer', $kundeId, 'Newsletter abbestellt');
}

/* Wieder anmelden, falls jemand versehentlich geklickt hat. */
if (App::istPost() && App::aktion() === 'zurueck') {
    Tenant::update('customers', $kundeId, ['newsletter' => 1]);
    Tenant::insert('consents', [
        'customer_id' => $kundeId,
        'typ'     => 'newsletter',
        'erteilt' => 1,
        'text'    => 'Erneute Einwilligung direkt nach dem Abmelden.',
        'quelle'  => 'abmelden-newsletter.php',
        'ip'      => '',
        'erstellt' => Util::jetzt(),
    ]);
    Oeffentlich::seite('Wieder angemeldet', Oeffentlich::kasten('Du bleibst dabei', '',
        '<p class="vorgang__text">Du bekommst den Newsletter weiterhin. Abmelden kannst du dich '
        . 'jederzeit wieder – der Link steht unter jeder Mail.</p>'
        . '<p class="vorgang__zurueck"><a href="' . Util::attr(Oeffentlich::url('/'))
        . '">Zur Website</a></p>'));
}

Oeffentlich::seite('Abgemeldet', Oeffentlich::kasten(
    $warAn ? 'Du bist abgemeldet' : 'Du warst schon abgemeldet', '',
    '<p class="vorgang__text">An <strong>' . Util::h((string) $kunde['email']) . '</strong> geht kein '
    . 'Newsletter mehr. Terminbestätigungen und Erinnerungen zu gebuchten Stunden bekommst du '
    . 'weiterhin – die gehören zum Training, nicht zur Werbung.</p>'
    . ($warAn
       ? '<form method="post" class="vorgang__form">'
         . '<input type="hidden" name="aktion" value="zurueck">'
         . '<button class="knopf knopf--rand" type="submit">Das war ein Versehen – wieder anmelden</button>'
         . '</form>' : '')
    . '<p class="vorgang__zurueck"><a href="' . Util::attr(Oeffentlich::url('/'))
    . '">Zur Website</a></p>'));
