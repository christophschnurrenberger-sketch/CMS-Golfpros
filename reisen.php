<?php
/**
 * Der Reisekatalog – alle ausgeschriebenen Golfreisen auf einer Seite.
 *
 * Der Baustein „Golfreisen" zeigt auf der Startseite die nächsten drei;
 * hier steht alles, was buchbar ist, mit einem Filter nach Land. Mehr
 * Filter braucht ein Pro mit fünf Reisen im Jahr nicht – ein Reisebüro
 * mit fünfhundert schon, aber das ist nicht die Aufgabe.
 *
 * Nur veröffentlichte Reisen, deren Ende noch nicht vorbei ist. Wie in
 * reise.php hängt die Seite am Modul: Ist es im Paket oder beim Pro aus,
 * gibt es keinen Katalog.
 */
require __DIR__ . '/lib/bootstrap.php';

Oeffentlich::mandantSetzen();

if (!Tenant::modul('travel')) {
    http_response_code(404);
    Oeffentlich::seite('Seite nicht gefunden', Oeffentlich::kasten(
        'Diese Seite gibt es nicht', '',
        '<p class="vorgang__zurueck"><a href="' . Util::attr(Oeffentlich::url('/')) . '">Zurück zur Website</a></p>'
    ));
}

$alle = array_values(array_filter(Trips::kommende(100),
    static fn ($r) => strtotime((string) $r['start']) > time()));

$laender = [];
foreach ($alle as $r) {
    $land = trim((string) $r['land']);
    if ($land !== '') {
        $laender[$land] = ($laender[$land] ?? 0) + 1;
    }
}
ksort($laender, SORT_LOCALE_STRING);

/* Nur Länder, die es gibt – ein ?land=… aus einem alten Link filtert nicht ins Leere. */
$land   = App::get('land');
$land   = isset($laender[$land]) ? $land : '';
$reisen = $land === '' ? $alle : array_values(array_filter($alle, static fn ($r) => trim((string) $r['land']) === $land));

$inhalt = '<section class="abschnitt abschnitt--eng reisekatalog"><div class="inhalt-breite">'
        . '<div class="reisekatalog__kopf">'
        . '<p class="hand hand--mittel">Unterwegs mit ' . Util::h(Tenant::name()) . '</p>'
        . '<h1 class="titel--unter">Golfreisen</h1>'
        . '<p class="reisekatalog__einleitung">Alle Reisen mit Termin, Preis und freien Plätzen. '
        . 'Gebucht wird direkt auf der Seite der Reise – verbindlich wird es mit meiner Bestätigung.</p>'
        . '</div>';

/* Ein Filter mit einem einzigen Land wäre ein Knopf ohne Wahl. */
if (count($laender) > 1) {
    $inhalt .= '<nav class="pillen reisekatalog__filter" aria-label="Reisen nach Land">'
             . '<a class="pille' . ($land === '' ? ' ist-aktiv' : '') . '"'
             . ($land === '' ? ' aria-current="page"' : '')
             . ' href="' . Util::attr(Oeffentlich::url('/reisen.php')) . '">Alle '
             . '<span class="pille__zahl">' . count($alle) . '</span></a>';
    foreach ($laender as $name => $anzahl) {
        $ist = $name === $land;
        $inhalt .= '<a class="pille' . ($ist ? ' ist-aktiv' : '') . '"' . ($ist ? ' aria-current="page"' : '')
                 . ' href="' . Util::attr(Oeffentlich::url('/reisen.php', ['land' => $name])) . '">'
                 . Util::h($name) . ' <span class="pille__zahl">' . $anzahl . '</span></a>';
    }
    $inhalt .= '</nav>';
}

if ($reisen === []) {
    /* Wer hier landet, will verreisen – also nicht nur „leider nichts",
       sondern die Möglichkeit, sich melden zu lassen. Das Formular geht an
       anfrage.php und wird dort eine Anfrage wie jede andere. */
    $inhalt .= '<div class="karte reisekatalog__leer">'
             . '<h2 class="reisekatalog__leer-titel">Gerade ist keine Reise ausgeschrieben</h2>'
             . '<p class="gedimmt">Wer zuerst von der nächsten hören will, hinterlässt hier Name und '
             . 'E-Mail – ich melde mich, sobald Termin und Preis feststehen.</p>'
             . '<form method="post" action="' . Util::attr(App::url('/anfrage.php')) . '" class="reisekatalog__melden">'
             . '<input type="hidden" name="w" value="' . Util::attr((string) (Tenant::workspace()['slug'] ?? '')) . '">'
             . '<input type="hidden" name="betreff" value="Golfreisen">'
             . '<input type="hidden" name="nachricht" value="Bitte Bescheid geben, sobald eine neue Golfreise ausgeschrieben ist.">'
             . '<input type="hidden" name="begonnen" value="' . time() . '">'
             . '<input type="text" name="website" class="honigtopf" tabindex="-1" autocomplete="off" aria-hidden="true">'
             . '<div class="feld-paar">'
             . '<div class="feld"><label for="rk-name">Name</label><input id="rk-name" name="name" autocomplete="name" required></div>'
             . '<div class="feld"><label for="rk-email">E-Mail</label><input id="rk-email" type="email" name="email" autocomplete="email" required></div>'
             . '</div>'
             . '<label class="einwilligung"><input type="checkbox" name="einwilligung" value="1" required>'
             . '<span>Ich bin damit einverstanden, dass meine Angaben für diese Nachricht gespeichert werden.</span></label>'
             . '<button class="knopf" type="submit">Bescheid geben lassen</button>'
             . '</form></div>';
} else {
    $inhalt .= '<div class="reisekacheln reisekacheln--katalog">';
    foreach ($reisen as $r) {
        $inhalt .= Reiseseite::kachel($r);
    }
    $inhalt .= '</div>';
}

$inhalt .= '</div></section>';

$titelbild = '';
foreach ($reisen as $r) {
    if (Trips::pfad((string) $r['bild']) !== '') {
        $titelbild = Trips::pfad((string) $r['bild']);
        break;
    }
}

Oeffentlich::seite('Golfreisen · ' . Tenant::name(), $inhalt, [
    'beschreibung' => 'Golfreisen mit ' . Tenant::name() . ': Termine, Preise und freie Plätze auf einen Blick.',
    'index'        => $land === '' && $reisen !== [],
    'bild'         => $titelbild,
]);
