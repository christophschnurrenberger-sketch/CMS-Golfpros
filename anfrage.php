<?php
/**
 * Formulareingang der Website.
 *
 * Jede Anfrage wird ein Lead – nicht sofort ein Kunde. Der Unterschied ist
 * wichtig: Aus einem Kontaktformular wird in etwa jeder dritte Fall ein
 * Kunde, und eine Kundenliste voller Namen ohne Termin ist wertlos.
 *
 * Antwortet immer mit einer sichtbaren Seite, nie mit einer weißen –
 * ein Formular, das ins Leere läuft, kostet genau den Interessenten, der
 * sich gerade gemeldet hat.
 */
require __DIR__ . '/lib/bootstrap.php';

Oeffentlich::mandantSetzen();

if (!App::istPost()) {
    App::weiter('/');
}

$formId = App::postInt('form_id');
$form   = $formId > 0 ? Tenant::find('forms', $formId) : null;

$name    = trim(App::post('name'));
$email   = strtolower(trim(App::post('email')));
$telefon = trim(App::post('telefon'));
$text    = trim(App::post('nachricht') !== '' ? App::post('nachricht') : App::post('text'));
$betreff = trim(App::post('betreff'));

/*
 * Alles, was das Formular sonst noch mitschickt, wird mitgeschrieben.
 * Ein Pro, der sein Formular um „Welches Handicap?" erweitert, soll die
 * Antwort auch sehen, ohne dass jemand den Code anfasst.
 */
$bekannt = ['w', 'form_id', 'name', 'email', 'telefon', 'nachricht', 'text', 'betreff',
            'einwilligung', 'newsletter', 'website', 'begonnen', 'aktion', 'csrf'];
$weitere = [];
foreach ($_POST as $feld => $wert) {
    if (in_array($feld, $bekannt, true) || !is_string($wert) || trim($wert) === '') {
        continue;
    }
    $weitere[] = ucfirst(str_replace('_', ' ', (string) $feld)) . ': ' . trim($wert);
}

$fehler = '';
if (Oeffentlich::istRoboter()) {
    /*
     * Keine Fehlermeldung für Roboter: Wer nichts erfährt, verbessert sein
     * Skript nicht. Für Menschen ist der Fall ohnehin unerreichbar.
     */
    Oeffentlich::seite('Danke', Oeffentlich::kasten('Danke für deine Nachricht',
        '', '<p class="vorgang__text">Ich melde mich so bald wie möglich.</p>'
        . '<p class="vorgang__zurueck"><a href="' . Util::attr(Oeffentlich::url('/')) . '">Zurück zur Website</a></p>'));
}
if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $fehler = 'Bitte einen Namen und eine gültige E-Mail-Adresse angeben, damit ich antworten kann.';
} elseif (!App::postBool('einwilligung')) {
    $fehler = 'Ohne die Einwilligung zur Verarbeitung der Angaben darf ich die Anfrage nicht bearbeiten.';
}

if ($fehler !== '') {
    Oeffentlich::seite('Anfrage', Oeffentlich::kasten('Das hat noch nicht geklappt', '',
        Oeffentlich::meldung($fehler, 'fehler')
        . '<p class="vorgang__zurueck"><a href="' . Util::attr(Oeffentlich::url('/', ['s' => 'kontakt']))
        . '">Zurück zum Formular</a></p>'));
}

/* ------------------------------------------------------------- Lead ---- */

$volltext = $text . ($weitere !== [] ? "\n\n" . implode("\n", $weitere) : '');

/* Meldet sich jemand zum zweiten Mal, wird der bestehende Lead ergänzt. */
$vorhanden = Tenant::one('leads', "email = :e AND stufe NOT IN ('kunde','verloren')", ['e' => $email], 'id DESC');

if ($vorhanden !== null) {
    $leadId = (int) $vorhanden['id'];
    Tenant::update('leads', $leadId, [
        'nachricht' => trim((string) $vorhanden['nachricht'] . "\n\n--- " . Util::datumZeit(Util::jetzt()) . " ---\n" . $volltext),
        'telefon'   => $telefon !== '' ? $telefon : (string) $vorhanden['telefon'],
    ]);
} else {
    $leadId = Leads::speichern([
        'name'      => $name,
        'email'     => $email,
        'telefon'   => $telefon,
        'quelle'    => $form !== null ? (string) $form['name'] : 'Website',
        'stufe'     => 'neu',
        'interesse' => $betreff !== '' ? $betreff : ($form !== null ? (string) $form['name'] : 'Allgemeine Anfrage'),
        'nachricht' => $volltext,
    ]);
}

Oeffentlich::einwilligung(0, $leadId, 'anfrage',
    'Einwilligung zur Verarbeitung der Angaben für die Bearbeitung der Anfrage.',
    $form !== null ? 'Formular: ' . $form['name'] : 'anfrage.php');

if (App::postBool('newsletter')) {
    Oeffentlich::einwilligung(0, $leadId, 'newsletter',
        'Einwilligung zum Empfang des Newsletters, erteilt über das Formular.', 'anfrage.php');
}

if ($form !== null) {
    Tenant::update('forms', $formId, ['eingaenge' => (int) $form['eingaenge'] + 1]);
}

Notify::senden('lead', 'Neue Anfrage: ' . $name, Util::kuerzen($volltext, 120), '/app/lead.php?id=' . $leadId);
Automations::ausloesen('lead_created', ['lead_id' => $leadId]);

/* Benachrichtigung an den Betrieb – eine Anfrage, die niemand sieht, ist keine. */
$anBetrieb = (string) Tenant::einstellung('mail_absender', '');
if ($form !== null && (string) $form['benachrichtigung'] !== '') {
    $anBetrieb = (string) $form['benachrichtigung'];
}
if ($anBetrieb !== '') {
    Mail::senden($anBetrieb, 'Neue Anfrage über die Website: ' . $name,
        "Name: " . $name . "\nE-Mail: " . $email . ($telefon !== '' ? "\nTelefon: " . $telefon : '')
        . "\n\n" . $volltext
        . "\n\nIm Backend ansehen: " . App::absolut('/app/lead.php?id=' . $leadId),
        ['antwort' => $email, 'lead_id' => $leadId]);
}

/* Eingangsbestätigung an den Absender – Erwartung setzen statt hoffen lassen. */
$bestaetigung = $form !== null && (string) $form['bestaetigung'] !== ''
    ? (string) $form['bestaetigung']
    : "vielen Dank für deine Nachricht. Ich habe sie erhalten und melde mich in der Regel "
      . "innerhalb eines Werktags.";

Mail::senden($email, 'Deine Anfrage bei ' . Tenant::name(),
    "Hallo " . $name . ",\n\n" . $bestaetigung . "\n\n"
    . "Das hast du geschrieben:\n" . $volltext . "\n\n"
    . Tenant::name(), ['lead_id' => $leadId]);

Oeffentlich::seite('Danke', Oeffentlich::kasten('Danke für deine Nachricht', '',
    '<p class="vorgang__text">' . Util::h($bestaetigung) . '</p>'
    . '<p class="vorgang__text">Eine Eingangsbestätigung ist an <strong>' . Util::h($email)
    . '</strong> unterwegs.</p>'
    . '<p class="vorgang__zurueck"><a href="' . Util::attr(Oeffentlich::url('/'))
    . '">Zurück zur Website</a></p>'));
