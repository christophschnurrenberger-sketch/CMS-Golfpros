<?php
/**
 * Eine Golfreise auf der Website – alles, was man vor dem Anmelden wissen
 * will, und dann das Anmeldeformular.
 *
 * Bewusst eine eigene Seite und kein Baustein: Eine Reise trägt Programm,
 * Leistungen und Preise, das sind zwei Bildschirme. In einer Liste auf der
 * Startseite steht deshalb nur die Kachel; hier steht alles.
 *
 * Angemeldet wird ohne Konto, wie überall auf dieser Website. Eine Reise
 * ist eine Anfrage mit Verbindlichkeit: Der Pro bestätigt sie, nicht das
 * System – deshalb steht am Ende „Anmeldung eingegangen", nicht „gebucht".
 */
require __DIR__ . '/lib/bootstrap.php';

Oeffentlich::mandantSetzen();

$slug  = App::get('r');
$reise = $slug !== '' ? Trips::nachSlug($slug) : null;

if ($reise === null || (string) $reise['status'] !== Trips::OEFFENTLICH) {
    http_response_code(404);
    Oeffentlich::seite('Reise nicht gefunden', Oeffentlich::kasten(
        'Diese Reise gibt es nicht',
        'Vielleicht ist sie schon gelaufen oder der Link ist veraltet.',
        '<p class="vorgang__zurueck"><a href="' . Util::attr(Oeffentlich::url('/'))
        . '">Zurück zur Website</a></p>'
    ));
}

$frei   = Trips::freiePlaetze($reise);
$offen  = strtotime((string) $reise['start']) > time();
$fehler = '';

/* ---------------------------------------------------------- Anmelden --- */

if (App::istPost() && App::aktion() === 'anmelden') {
    $vorname  = trim(App::post('vorname'));
    $nachname = trim(App::post('nachname'));
    $email    = strtolower(trim(App::post('email')));
    $telefon  = trim(App::post('telefon'));
    $zimmer   = App::post('zimmer') === 'ez' ? 'ez' : 'dz';

    if (Oeffentlich::istRoboter()) {
        $fehler = 'Das sah nach einem automatischen Versand aus. Bitte noch einmal versuchen.';
    } elseif ($vorname === '' || $nachname === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $fehler = 'Bitte Vorname, Nachname und eine gültige E-Mail-Adresse angeben.';
    } elseif (!App::postBool('einwilligung')) {
        $fehler = 'Ohne die Einwilligung zur Verarbeitung der Angaben geht es leider nicht.';
    } else {
        $kundeId = Oeffentlich::kundeFinden($email, $vorname, $nachname, $telefon);

        [$anmeldungId, $meldung] = Trips::anmelden((int) $reise['id'], [
            'name'         => $vorname . ' ' . $nachname,
            'email'        => $email,
            'telefon'      => $telefon,
            'zimmer'       => $zimmer,
            'mitreisender' => App::post('mitreisender'),
            'hcp'          => App::post('hcp'),
            'notiz'        => App::post('notiz'),
            'customer_id'  => $kundeId,
        ]);

        if ($anmeldungId === 0) {
            $fehler = $meldung;
        } else {
            Oeffentlich::einwilligung($kundeId, 0, 'reise',
                'Einwilligung zur Verarbeitung der Angaben für die Anmeldung zu einer Golfreise.',
                'reise.php');

            $preis = Trips::preis($reise, $zimmer);
            Mail::senden($email, 'Deine Anmeldung: ' . (string) $reise['titel'],
                "Hallo " . $vorname . ",\n\n"
                . "deine Anmeldung ist angekommen:\n\n"
                . (string) $reise['titel'] . "\n"
                . trim((string) $reise['ziel'] . ', ' . (string) $reise['land'], ', ') . "\n"
                . Util::datumLang((string) $reise['start']) . " bis "
                . Util::datumLang((string) $reise['ende']) . " · "
                . (int) $reise['naechte'] . " Nächte\n"
                . (Trips::ZIMMER[$zimmer] ?? $zimmer) . " · " . Util::geld($preis) . " pro Person\n\n"
                . ((int) $reise['anzahlung_cent'] > 0
                   ? "Fällig wird zunächst eine Anzahlung von " . Util::geld((int) $reise['anzahlung_cent'])
                     . ".\n\n" : '')
                . ($meldung !== '' ? $meldung . "\n\n" : '')
                . "Ich melde mich mit der Bestätigung und allen Einzelheiten bei dir.\n\n"
                . Tenant::name());

            Oeffentlich::seite('Anmeldung eingegangen · ' . Tenant::name(), Oeffentlich::kasten(
                'Deine Anmeldung ist da', '',
                '<div class="vorgang__erfolg">'
                . '<div class="vorgang__erfolg-datum">' . Util::h((string) $reise['titel']) . '</div>'
                . '<div class="vorgang__erfolg-zeit">'
                . Util::h(Util::datum((string) $reise['start'])) . '–'
                . Util::h(Util::datum((string) $reise['ende'])) . ' · '
                . Util::h(Trips::ZIMMER[$zimmer] ?? $zimmer) . ' · '
                . Util::h(Util::geld($preis)) . '</div></div>'
                . ($meldung !== '' ? Oeffentlich::meldung($meldung, 'warnung') : '')
                . '<p class="vorgang__text">Eine Bestätigung ist an <strong>' . Util::h($email)
                . '</strong> unterwegs. Ich melde mich mit allen Einzelheiten – '
                . 'verbindlich wird die Reise erst mit meiner Bestätigung.</p>'
                . '<p class="vorgang__zurueck"><a href="' . Util::attr(Oeffentlich::url('/'))
                . '">Zurück zur Website</a></p>'
            ));
        }
    }
}

/* ------------------------------------------------------------ Ausgabe --- */

$leistungen = Trips::zeilen((string) $reise['leistungen']);
$nicht      = Trips::zeilen((string) $reise['nicht_enthalten']);
$programm   = Trips::programm((string) $reise['programm']);

$eckdaten = [];
if ((string) $reise['hotel'] !== '') {
    $eckdaten[] = ['Hotel', (string) $reise['hotel']];
}
$eckdaten[] = ['Reisezeit', Util::datum((string) $reise['start']) . ' – ' . Util::datum((string) $reise['ende'])
             . ' · ' . (int) $reise['naechte'] . ' Nächte'];
$eckdaten[] = ['Anreise', (Trips::ANREISE[(string) $reise['anreise']] ?? (string) $reise['anreise'])
             . ((string) $reise['abflug_ort'] !== '' ? ' ab ' . (string) $reise['abflug_ort'] : '')];
/* Preis und Zuschlag getrennt: zusammen in einer Zelle werden daraus drei
   Zeilen, und der Zuschlag ist ohnehin eine eigene Entscheidung. */
$eckdaten[] = ['Preis', Util::geld((int) $reise['preis_cent']) . ' p. P. im Doppelzimmer'];
if ((int) $reise['ez_zuschlag_cent'] > 0) {
    $eckdaten[] = ['Einzelzimmer', '+' . Util::geld((int) $reise['ez_zuschlag_cent']) . ' Zuschlag'];
}
if ((int) $reise['anzahlung_cent'] > 0) {
    $eckdaten[] = ['Anzahlung', Util::geld((int) $reise['anzahlung_cent']) . ' bei der Bestätigung'];
}
$eckdaten[] = ['Plätze', $frei > 0 ? $frei . ' von ' . (int) $reise['plaetze'] . ' frei' : 'ausgebucht'];
if ((int) $reise['trainer_id'] > 0) {
    $eckdaten[] = ['Begleitung', Auth::trainerName((int) $reise['trainer_id'])];
}

$inhalt = '';

if ((string) $reise['bild'] !== '') {
    $inhalt .= '<div class="reise__bild"><img src="' . Util::attr(App::url((string) $reise['bild']))
             . '" alt="' . Util::attr((string) $reise['titel']) . '" loading="lazy"></div>';
}

if ((string) $reise['kurztext'] !== '') {
    $inhalt .= '<p class="reise__kurz">' . Util::h((string) $reise['kurztext']) . '</p>';
}

$inhalt .= '<dl class="reise__eck">';
foreach ($eckdaten as [$label, $wert]) {
    $inhalt .= '<div class="reise__eck-teil"><dt>' . Util::h($label) . '</dt>'
             . '<dd>' . Util::h($wert) . '</dd></div>';
}
$inhalt .= '</dl>';

if ((string) $reise['beschreibung'] !== '') {
    $inhalt .= '<div class="reise__text">';
    foreach (preg_split('/\n\s*\n/', (string) $reise['beschreibung']) ?: [] as $absatz) {
        $absatz = trim($absatz);
        if ($absatz !== '') {
            $inhalt .= '<p>' . nl2br(Util::h($absatz)) . '</p>';
        }
    }
    $inhalt .= '</div>';
}

if ($leistungen !== [] || $nicht !== []) {
    $inhalt .= '<div class="reise__spalten">';
    if ($leistungen !== []) {
        $inhalt .= '<div><h3 class="reise__untertitel">Im Preis enthalten</h3><ul class="reise__liste">';
        foreach ($leistungen as $l) {
            $inhalt .= '<li>' . Util::h($l) . '</li>';
        }
        $inhalt .= '</ul></div>';
    }
    if ($nicht !== []) {
        $inhalt .= '<div><h3 class="reise__untertitel">Nicht enthalten</h3>'
                 . '<ul class="reise__liste reise__liste--ohne">';
        foreach ($nicht as $l) {
            $inhalt .= '<li>' . Util::h($l) . '</li>';
        }
        $inhalt .= '</ul></div>';
    }
    $inhalt .= '</div>';
}

if ($programm !== []) {
    $inhalt .= '<h3 class="reise__untertitel">Der Ablauf</h3><ol class="reise__programm">';
    foreach ($programm as $nr => $tag) {
        $inhalt .= '<li class="reise__tag">'
                 . '<span class="reise__tag-nr">Tag ' . ($nr + 1) . '</span>'
                 . '<div><strong>' . Util::h($tag['titel']) . '</strong>'
                 . ($tag['text'] !== '' ? '<p>' . Util::h($tag['text']) . '</p>' : '')
                 . '</div></li>';
    }
    $inhalt .= '</ol>';
}

/* ------------------------------------------------------- Das Formular --- */

$inhalt .= '<h3 class="reise__untertitel" id="anmelden">Mitfahren</h3>';

if ($fehler !== '') {
    $inhalt .= Oeffentlich::meldung($fehler, 'warnung');
}

if (!$offen) {
    $inhalt .= Oeffentlich::meldung('Diese Reise hat bereits begonnen.', 'warnung');
} elseif ($frei <= 0 && (int) $reise['warteliste'] !== 1) {
    $inhalt .= Oeffentlich::meldung('Die Reise ist ausgebucht. Schreib mir gern – '
        . 'bei Absagen rücke ich nach.', 'warnung');
} else {
    if ($frei <= 0) {
        $inhalt .= Oeffentlich::meldung('Die Reise ist ausgebucht. Du kannst dich auf die '
            . 'Warteliste setzen lassen und rückst nach, sobald ein Platz frei wird.', 'warnung');
    }
    $zimmerwahl = '';
    foreach (Trips::ZIMMER as $k => $n) {
        $aufpreis = $k === 'ez' && (int) $reise['ez_zuschlag_cent'] > 0
            ? ' (+' . Util::geldKurz((int) $reise['ez_zuschlag_cent']) . ')' : '';
        $zimmerwahl .= '<option value="' . Util::attr($k) . '"'
                     . (App::post('zimmer') === $k ? ' selected' : '') . '>'
                     . Util::h($n . $aufpreis) . '</option>';
    }

    $inhalt .= '<form method="post" class="vorgang__form">'
             . '<input type="hidden" name="aktion" value="anmelden">'
             . '<input type="hidden" name="w" value="' . Util::attr((string) (Tenant::workspace()['slug'] ?? '')) . '">'
             . '<input type="hidden" name="r" value="' . Util::attr((string) $reise['slug']) . '">'
             . '<input type="hidden" name="begonnen" value="' . time() . '">'
             . '<input type="text" name="website" class="honigtopf" tabindex="-1" autocomplete="off" aria-hidden="true">'
             . '<div class="feld-paar">'
             . '<div class="feld"><label for="vorname">Vorname</label>'
             . '<input id="vorname" name="vorname" required value="' . Util::attr(App::post('vorname')) . '"></div>'
             . '<div class="feld"><label for="nachname">Nachname</label>'
             . '<input id="nachname" name="nachname" required value="' . Util::attr(App::post('nachname')) . '"></div>'
             . '</div>'
             . '<div class="feld-paar">'
             . '<div class="feld"><label for="email">E-Mail</label>'
             . '<input id="email" type="email" name="email" required value="' . Util::attr(App::post('email')) . '"></div>'
             . '<div class="feld"><label for="telefon">Telefon</label>'
             . '<input id="telefon" type="tel" name="telefon" value="' . Util::attr(App::post('telefon')) . '"></div>'
             . '</div>'
             . '<div class="feld-paar">'
             . '<div class="feld"><label for="zimmer">Zimmer</label>'
             . '<select id="zimmer" name="zimmer">' . $zimmerwahl . '</select></div>'
             . '<div class="feld"><label for="mitreisender">Mitreisende Person</label>'
             . '<input id="mitreisender" name="mitreisender" placeholder="Name, falls im Doppelzimmer"'
             . ' value="' . Util::attr(App::post('mitreisender')) . '"></div>'
             . '</div>'
             . '<div class="feld"><label for="hcp">Handicap</label>'
             . '<input id="hcp" name="hcp" placeholder="z. B. 24,8" value="' . Util::attr(App::post('hcp')) . '"></div>'
             . '<div class="feld"><label for="notiz">Möchtest du noch etwas dazusagen?</label>'
             . '<textarea id="notiz" name="notiz" rows="2">' . Util::h(App::post('notiz')) . '</textarea></div>'
             . '<label class="einwilligung"><input type="checkbox" name="einwilligung" value="1" required>'
             . '<span>Ich bin mit der Verarbeitung meiner Angaben für diese Reise einverstanden. '
             . 'Die Einwilligung kann ich jederzeit widerrufen.</span></label>'
             . '<button class="knopf knopf--primaer" type="submit">'
             . ($frei > 0 ? 'Verbindlich anmelden' : 'Auf die Warteliste') . '</button>'
             . '<p class="vorgang__klein">Die Anmeldung wird verbindlich, sobald ich sie bestätige. '
             . 'Du bekommst vorher alle Einzelheiten.</p>'
             . '</form>';
}

$inhalt .= '<p class="vorgang__zurueck"><a href="' . Util::attr(Oeffentlich::url('/'))
         . '">Zurück zur Website</a></p>';

Oeffentlich::seite((string) $reise['titel'] . ' · ' . Tenant::name(),
    Oeffentlich::kasten(
        (string) $reise['titel'],
        trim((string) $reise['ziel'] . ' · ' . (string) $reise['land'], ' ·'),
        $inhalt
    ),
    ['beschreibung' => (string) $reise['kurztext']]);
