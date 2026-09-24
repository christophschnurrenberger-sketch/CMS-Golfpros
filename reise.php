<?php
/**
 * Eine Golfreise auf der Website – so, wie man sie aus einem Reisekatalog
 * kennt: großes Bild, die Eckdaten auf einen Blick, Eindrücke vom Hotel
 * und von den Plätzen, der Ablauf Tag für Tag, die Preise – und am Ende
 * das Buchen für bis zu sechs Reisende mit Zimmer und Zusatzleistungen.
 *
 * Bewusst eine eigene Seite und kein Baustein: Eine Reise trägt Bilder,
 * Programm, Leistungen und Preise, das sind mehrere Bildschirme. Auf der
 * Startseite steht die Kachel, im Katalog stehen alle Kacheln, hier
 * steht alles.
 *
 * Gebucht wird ohne Konto, wie überall auf dieser Website. Eine Buchung
 * ist eine Anfrage mit Verbindlichkeit: Der Pro bestätigt sie, nicht das
 * System – deshalb heißt es am Ende „Buchung eingegangen", nicht „gebucht".
 *
 * Den Preis rechnet ausschließlich Trips::berechnen(). Die Rechnung neben
 * dem Formular holt sich reise.js bei jeder Änderung hier ab (aktion
 * „rechnen", format „json") – eine zweite Preislogik in JavaScript liefe
 * beim ersten neuen Rabatt auseinander. Ohne Skript rechnet der Knopf
 * „Preis neu berechnen" dasselbe mit einem Neuladen.
 */
require __DIR__ . '/lib/bootstrap.php';

Oeffentlich::mandantSetzen();

$slug  = App::get('r') !== '' ? App::get('r') : App::post('r');
$reise = $slug !== '' && Tenant::modul('travel') ? Trips::nachSlug($slug) : null;

/*
 * Vorschau: Eine noch nicht veröffentlichte Reise sieht nur, wer im
 * Backend genau dieses Betriebs Reisen sehen darf. Nach der Anmeldung wird
 * erst hier gefragt – so bekommt nicht jeder Besucher eine Sitzung.
 */
$vorschau = false;
if ($reise !== null && (string) $reise['status'] !== Trips::OEFFENTLICH) {
    $benutzer = Auth::benutzer();
    $vorschau = $benutzer !== null
        && (int) $benutzer['workspace_id'] === Tenant::id()
        && Auth::darf('modul.travel');
    if (!$vorschau) {
        $reise = null;
    }
}

if ($reise === null) {
    http_response_code(404);
    Oeffentlich::seite('Reise nicht gefunden', Oeffentlich::kasten(
        'Diese Reise gibt es nicht',
        'Vielleicht ist sie schon gelaufen oder der Link ist veraltet.',
        '<p class="vorgang__zurueck"><a href="'
        . Util::attr(Tenant::modul('travel') ? Oeffentlich::url('/reisen.php') : Oeffentlich::url('/'))
        . '">' . (Tenant::modul('travel') ? 'Alle Reisen ansehen' : 'Zurück zur Website') . '</a></p>'
    ));
}

$id         = (int) $reise['id'];
$w          = Tenant::waehrung();
$frei       = Trips::freiePlaetze($reise);
$begonnen   = strtotime((string) $reise['start']) <= time();
$warteliste = (int) $reise['warteliste'] === 1;
$buchbar    = !$begonnen && (string) $reise['status'] === Trips::OEFFENTLICH && ($frei > 0 || $warteliste);
$extras     = Trips::extras((string) $reise['extras']);
$zeitraum   = Reiseseite::zeitraum((string) $reise['start'], (string) $reise['ende']);
$naechte    = (int) $reise['naechte'] . ((int) $reise['naechte'] === 1 ? ' Nacht' : ' Nächte');
$ort        = trim((string) $reise['ziel'] . ', ' . (string) $reise['land'], ', ');

/* ----------------------------------------------------- Eingaben lesen --- */

/*
 * Die Felder so, wie sie hereinkommen – für die Rechnung, die Buchung und
 * zum Wiederbefüllen nach einem Fehler. Alles, was kein Text ist, zählt
 * als leer: Ein reisende[0][vorname][]=x soll keine Warnung werfen.
 */
$text = static fn ($v): string => is_string($v) ? trim($v) : '';
$eingaben = ['zeilen' => [], 'zimmer' => App::post('zimmer') === 'ez' ? 'ez' : 'dz', 'extras' => []];
$roh = $_POST['reisende'] ?? [];
for ($i = 0; $i < Trips::PERSONEN_MAX; $i++) {
    $z = is_array($roh) && is_array($roh[$i] ?? null) ? $roh[$i] : [];
    $eingaben['zeilen'][] = [
        'vorname'  => mb_substr($text($z['vorname'] ?? ''), 0, 80),
        'nachname' => mb_substr($text($z['nachname'] ?? ''), 0, 80),
        'golfer'   => $text($z['golfer'] ?? '1') !== '0',
        'hcp'      => mb_substr($text($z['hcp'] ?? ''), 0, 16),
    ];
}
foreach ((array) ($_POST['extra'] ?? []) as $nr => $anzahl) {
    if (is_scalar($anzahl)) {
        $eingaben['extras'][(int) $nr] = max(0, (int) $anzahl);
    }
}

/*
 * Die Reisenden für die Rechnung oder die Buchung.
 *
 * Zum Buchen zählt nur, wer einen Namen hat. Zum Rechnen zählt, was auf
 * dem Bildschirm steht: reise.js schickt mit, wie viele Personen gerade
 * sichtbar sind, und wer noch keinen Namen eingetippt hat, rechnet als
 * „Person 2" mit – sonst spränge der Preis erst beim ersten Buchstaben.
 */
$reisendeFuer = static function (bool $rechnen) use ($eingaben): array {
    $sichtbar = max(0, min(Trips::PERSONEN_MAX, App::postInt('personen')));
    $liste = [];
    foreach ($eingaben['zeilen'] as $i => $z) {
        $name = trim($z['vorname'] . ' ' . $z['nachname']);
        if ($rechnen && $sichtbar > 0) {
            if ($i >= $sichtbar) {
                break;
            }
            $name = $name !== '' ? $name : 'Person ' . ($i + 1);
        }
        if ($name !== '') {
            $liste[] = ['name' => $name, 'golfer' => $z['golfer'], 'hcp' => $z['hcp']];
        }
    }
    if ($rechnen && $liste === []) {
        $liste[] = ['name' => 'Person 1', 'golfer' => $eingaben['zeilen'][0]['golfer'], 'hcp' => ''];
    }
    return $liste;
};

$rechnung = Trips::berechnen($reise, [
    'reisende' => $reisendeFuer(true), 'zimmer' => $eingaben['zimmer'], 'extras' => $eingaben['extras'],
]);

$knopfText = static fn (int $personen): string => $personen > $frei ? 'Auf die Warteliste' : 'Buchung absenden';

/* Die Rechnung für reise.js – dieselbe HTML-Ausgabe wie beim ersten Aufruf. */
if (App::istPost() && App::aktion() === 'rechnen' && App::post('format') === 'json') {
    App::json([
        'html'     => Reiseseite::rechnung($reise, $rechnung, $frei),
        'knopf'    => $knopfText($rechnung['personen']),
        'moeglich' => $rechnung['personen'] <= $frei || $warteliste,
    ]);
}

/* ---------------------------------------------------------- Buchen --- */

$fehler = '';

if (App::istPost() && App::aktion() === 'anmelden') {
    $erste    = $eingaben['zeilen'][0];
    $email    = strtolower(App::post('email'));
    $telefon  = mb_substr(App::post('telefon'), 0, 40);
    $reisende = $reisendeFuer(false);

    if ($vorschau) {
        $fehler = 'In der Vorschau kann nicht gebucht werden. Erst veröffentlichen, dann geht es.';
    } elseif (!$buchbar) {
        $fehler = $begonnen ? 'Diese Reise hat bereits begonnen.' : 'Diese Reise ist ausgebucht.';
    } elseif (Oeffentlich::istRoboter()) {
        $fehler = 'Das sah nach einem automatischen Versand aus. Bitte noch einmal versuchen.';
    } elseif ($erste['vorname'] === '' || $erste['nachname'] === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $fehler = 'Bitte Vor- und Nachname der ersten Person und eine gültige E-Mail-Adresse angeben.';
    } elseif (!App::postBool('einwilligung')) {
        $fehler = 'Ohne die Einwilligung zur Verarbeitung der Angaben geht es leider nicht.';
    } else {
        $kundeId = Oeffentlich::kundeFinden($email, $erste['vorname'], $erste['nachname'], $telefon);

        [$buchungId, $meldung, $neu] = Trips::anmelden($id, [
            'reisende'    => $reisende,
            'email'       => $email,
            'telefon'     => $telefon,
            'zimmer'      => $eingaben['zimmer'],
            'extras'      => $eingaben['extras'],
            'notiz'       => mb_substr(App::post('notiz'), 0, 2000),
            'customer_id' => $kundeId,
        ]);

        $zurueck = '<p class="vorgang__zurueck reise-zurueck">'
                 . '<a href="' . Util::attr(Reiseseite::adresse($reise)) . '">Zur Reise</a>'
                 . '<a href="' . Util::attr(Oeffentlich::url('/reisen.php')) . '">Alle Reisen</a></p>';

        if ($buchungId === 0) {
            $fehler = $meldung;
        } elseif (!$neu) {
            /* Zweimal abgeschickt oder später noch einmal versucht: nichts
               Neues anlegen, keine zweite E-Mail – aber auch kein Fehler. */
            Oeffentlich::seite('Buchung liegt vor · ' . Tenant::name(), Oeffentlich::kasten(
                'Deine Buchung liegt schon vor', '',
                '<p class="vorgang__text">Für <strong>' . Util::h($email) . '</strong> gibt es bereits eine Buchung '
                . 'dieser Reise. Soll sich etwas ändern – mehr Personen, anderes Zimmer –, schreib mir einfach.</p>'
                . $zurueck
            ));
        } else {
            Oeffentlich::einwilligung($kundeId, 0, 'reise',
                'Einwilligung zur Verarbeitung der Angaben für die Buchung einer Golfreise.', 'reise.php');

            $buchung = Tenant::find('trip_signups', $buchungId) ?? [];
            $aufWarteliste = (string) ($buchung['status'] ?? '') === 'warteliste';
            $rechnung = Trips::berechnen($reise, [
                'reisende' => $reisende, 'zimmer' => $eingaben['zimmer'], 'extras' => $eingaben['extras'],
            ]);
            $zimmer = Trips::ZIMMER[$rechnung['zimmer']] ?? '';

            $liste = '';
            foreach ($rechnung['reisende'] as $p) {
                $liste .= '· ' . $p['name']
                        . ($p['golfer'] ? ($p['hcp'] !== '' ? ' (HCP ' . $p['hcp'] . ')' : '') : ' (Nichtgolfer)') . "\n";
            }
            Mail::senden($email, ($aufWarteliste ? 'Warteliste: ' : 'Deine Buchung: ') . (string) $reise['titel'],
                'Hallo ' . $erste['vorname'] . ",\n\n"
                . ($aufWarteliste ? "danke – du stehst auf der Warteliste für diese Reise:\n\n"
                                  : "deine Buchung ist angekommen:\n\n")
                . (string) $reise['titel'] . "\n"
                . ($ort !== '' ? $ort . "\n" : '')
                . $zeitraum . ' · ' . $naechte . "\n"
                . ((string) $reise['hotel'] !== '' ? 'Hotel: ' . (string) $reise['hotel'] . "\n" : '')
                . "\nReisende:\n" . $liste
                . 'Zimmer: ' . $zimmer . "\n\n"
                . Trips::rechnungText($rechnung) . "\n"
                . ($meldung !== '' ? $meldung . "\n\n" : '')
                . 'Verbindlich wird die Buchung mit meiner Bestätigung'
                . ((int) $rechnung['anzahlung'] > 0 ? ' – erst dann wird die Anzahlung fällig' : '')
                . ". Ich melde mich mit allen Einzelheiten.\n\n"
                . Tenant::name(), ['customer_id' => $kundeId]);

            $schritte = '<li><strong>Zusammenfassung per E-Mail</strong>'
                      . '<span>Ist an ' . Util::h($email) . ' unterwegs.</span></li>';
            if ($aufWarteliste) {
                $schritte .= '<li><strong>Nachrücken</strong><span>Wird genug frei, melde ich mich und bestätige '
                           . 'die Buchung. Bis dahin entsteht nichts Verbindliches.</span></li>';
            } else {
                $schritte .= '<li><strong>Meine Bestätigung</strong><span>Ich prüfe die Buchung und bestätige sie – '
                           . 'verbindlich wird sie erst damit.</span></li>';
                if ((int) $rechnung['anzahlung'] > 0) {
                    $schritte .= '<li><strong>Anzahlung</strong><span>'
                               . Util::h(Util::geldKurz((int) $rechnung['anzahlung'], $w)) . ' nach der Bestätigung, '
                               . 'der Rest von ' . Util::h(Util::geldKurz((int) $rechnung['rest'], $w))
                               . ' bis ' . Util::h(Util::datum((string) $rechnung['rest_faellig'])) . '.</span></li>';
                }
                $schritte .= '<li><strong>Reiseunterlagen</strong><span>Alles Weitere kommt rechtzeitig vor der Abreise.</span></li>';
            }

            Oeffentlich::seite(($aufWarteliste ? 'Warteliste' : 'Buchung eingegangen') . ' · ' . Tenant::name(),
                Oeffentlich::kasten($aufWarteliste ? 'Du stehst auf der Warteliste' : 'Deine Buchung ist da', '',
                    '<div class="vorgang__erfolg">'
                    . '<div class="vorgang__erfolg-datum">' . Util::h((string) $reise['titel']) . '</div>'
                    . '<div class="vorgang__erfolg-zeit">' . Util::h($zeitraum . ' · ' . $naechte) . '</div>'
                    . '<div class="vorgang__erfolg-ort">'
                    . Util::h($rechnung['personen'] . ($rechnung['personen'] === 1 ? ' Person' : ' Personen') . ' · ' . $zimmer)
                    . '</div></div>'
                    . ($meldung !== '' ? Oeffentlich::meldung($meldung, 'warnung') : '')
                    . '<div class="reiserechnung reiserechnung--fest">' . Reiseseite::rechnung($reise, $rechnung) . '</div>'
                    . '<h2 class="reiseteil__titel reiseteil__titel--klein">So geht es weiter</h2>'
                    . '<ol class="reiseschritte">' . $schritte . '</ol>'
                    . $zurueck
                ));
        }
    }
}

/* ---------------------------------------------------- Seite bauen --- */

$h = static fn (string $s): string => Util::h($s);

$bild      = Reiseseite::bildUrl((string) $reise['bild']);
$galerie   = Trips::galerie($reise);
$highlights = Trips::zeilen((string) ($reise['highlights'] ?? ''));
$plaetze   = Trips::golfplaetze((string) ($reise['golfplaetze'] ?? ''));
$programm  = Trips::programm((string) $reise['programm']);
$leistungen = Trips::zeilen((string) $reise['leistungen']);
$nicht     = Trips::zeilen((string) $reise['nicht_enthalten']);
$trainer   = (int) $reise['trainer_id'] > 0 ? Auth::trainerName((int) $reise['trainer_id']) : '';
$trainer   = $trainer === '—' ? '' : $trainer;
$fruehbucher = Trips::istFruehbucher($reise);
$preisNicht = (int) $reise['preis_nichtgolfer_cent'];
$katalog   = Oeffentlich::url('/reisen.php');

$inhalt = '<article class="reiseseite">';

if ($vorschau) {
    $inhalt .= '<div class="inhalt-breite"><p class="reise-vorschau">Vorschau – diese Reise ist '
             . $h(Trips::STATUS[(string) $reise['status']] ?? (string) $reise['status'])
             . ' und für Besucher nicht sichtbar. Nur du siehst diese Seite.</p></div>';
}

/* Der Kopf: Bild mit Titel darüber, ohne Bild eine ruhige Fläche. */
$inhalt .= '<header class="inhalt-breite"><div class="reisekopf' . ($bild === '' ? ' reisekopf--ohne-bild' : '') . '">'
         . ($bild !== '' ? '<img class="reisekopf__bild" src="' . Util::attr($bild) . '" alt="" fetchpriority="high">' : '')
         . '<div class="reisekopf__innen">'
         . '<nav class="reisekopf__pfad" aria-label="Pfad"><a href="' . Util::attr($katalog) . '">Golfreisen</a>'
         . ((string) $reise['land'] !== ''
            ? '<span aria-hidden="true">/</span><a href="' . Util::attr(Oeffentlich::url('/reisen.php', ['land' => (string) $reise['land']])) . '">'
              . $h((string) $reise['land']) . '</a>' : '')
         . '</nav>'
         . Reiseseite::markenHtml(Reiseseite::marken($reise, $frei), 'reisemarken reisekopf__marken')
         . '<h1 class="reisekopf__titel">' . $h((string) $reise['titel']) . '</h1>'
         . '<p class="reisekopf__unter">' . $h(trim($ort . ' · ' . $zeitraum . ' · ' . $naechte, ' ·')) . '</p>'
         . '</div>'
         . ($galerie !== []
            ? '<a class="reisekopf__bilder" href="#bilder" data-bildfenster-start="galerie">'
              . '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">'
              . '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/>'
              . '<rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg>'
              . count($galerie) . (count($galerie) === 1 ? ' Bild' : ' Bilder') . '</a>'
            : '')
         . '</div></header>';

/* Die Eckdaten – was man in einem Reisekatalog zuerst vergleicht. */
$fakten = [['Reisezeit', $zeitraum, $naechte]];
if ((string) $reise['hotel'] !== '') {
    $fakten[] = ['Unterkunft', (string) $reise['hotel'], '', (int) $reise['hotel_sterne']];
}
if ($plaetze !== []) {
    $fakten[] = ['Golf', count($plaetze) === 1 ? '1 Golfplatz' : count($plaetze) . ' Golfplätze',
                 implode(' · ', array_map(static fn ($p) => $p['name'], array_slice($plaetze, 0, 3)))];
}
$fakten[] = ['Anreise', Trips::ANREISE[(string) $reise['anreise']] ?? (string) $reise['anreise'],
             (string) $reise['abflug_ort'] !== '' ? 'ab ' . (string) $reise['abflug_ort'] : ''];
$fakten[] = ['Gruppe', 'bis ' . (int) $reise['plaetze'] . ' Personen',
             $frei > 0 ? 'noch ' . ($frei === 1 ? '1 Platz' : $frei . ' Plätze') . ' frei' : 'ausgebucht'];
if ($trainer !== '') {
    $fakten[] = ['Begleitung', $trainer, ''];
}
$inhalt .= '<div class="inhalt-breite"><dl class="reisefakten">';
foreach ($fakten as $f) {
    $inhalt .= '<div class="reisefakten__teil"><dt>' . $h($f[0]) . '</dt><dd>' . $h($f[1])
             . (isset($f[3]) && $f[3] > 0 ? ' ' . Reiseseite::sterne($f[3]) : '')
             . ($f[2] !== '' ? '<span class="reisefakten__unter">' . $h($f[2]) . '</span>' : '') . '</dd></div>';
}
$inhalt .= '</dl></div>';

/* ---------------------------------------------- Hauptspalte --- */

$haupt = '';

if ((string) $reise['kurztext'] !== '') {
    $haupt .= '<p class="reise__kurz">' . $h((string) $reise['kurztext']) . '</p>';
}

if ($highlights !== []) {
    $haupt .= '<section class="reiseteil"><h2 class="reiseteil__titel">Darauf freuen wir uns</h2>'
            . '<ul class="reisehighlights">';
    foreach ($highlights as $hl) {
        $haupt .= '<li>' . $h($hl) . '</li>';
    }
    $haupt .= '</ul></section>';
}

if ($galerie !== []) {
    $anzahl = count($galerie);
    $haupt .= '<section class="reiseteil" id="bilder"><h2 class="reiseteil__titel">Eindrücke</h2>'
            . '<div class="reisegalerie reisegalerie--' . min($anzahl, 5) . '" data-reisegalerie>';
    foreach ($galerie as $i => $b) {
        $url = Reiseseite::bildUrl($b);
        $haupt .= '<a class="reisegalerie__bild" href="' . Util::attr($url) . '" data-bildfenster="galerie"'
                . ' aria-label="' . Util::attr('Bild ' . ($i + 1) . ' von ' . $anzahl . ' vergrößern') . '">'
                . '<img src="' . Util::attr($url) . '" alt="" loading="lazy">'
                . ($i === 4 && $anzahl > 5 ? '<span class="reisegalerie__mehr">+' . ($anzahl - 5) . '</span>' : '')
                . '</a>';
    }
    $haupt .= '</div></section>';
}

if ((string) $reise['beschreibung'] !== '') {
    $haupt .= '<section class="reiseteil"><h2 class="reiseteil__titel">Die Reise</h2><div class="reise__text">';
    foreach (preg_split('/\n\s*\n/', (string) $reise['beschreibung']) ?: [] as $absatz) {
        $absatz = trim($absatz);
        if ($absatz !== '') {
            $haupt .= '<p>' . nl2br($h($absatz)) . '</p>';
        }
    }
    $haupt .= '</div></section>';
}

$hotelBild = Reiseseite::bildUrl((string) ($reise['hotel_bild'] ?? ''));
if ((string) $reise['hotel'] !== '' || (string) ($reise['hotel_text'] ?? '') !== '' || $hotelBild !== '') {
    $hotelUrl = (string) ($reise['hotel_url'] ?? '');
    $haupt .= '<section class="reiseteil" id="hotel"><div class="reisehotel' . ($hotelBild === '' ? ' reisehotel--ohne-bild' : '') . '">'
            . ($hotelBild !== '' ? '<div class="reisehotel__bild"><img src="' . Util::attr($hotelBild) . '" alt="" loading="lazy"></div>' : '')
            . '<div class="reisehotel__text">'
            . '<p class="reiseteil__marke">Unterkunft</p>'
            . '<h2 class="reiseteil__titel">' . $h((string) $reise['hotel'] !== '' ? (string) $reise['hotel'] : 'Das Hotel') . '</h2>'
            . Reiseseite::sterne((int) ($reise['hotel_sterne'] ?? 0));
    foreach (preg_split('/\n\s*\n/', (string) ($reise['hotel_text'] ?? '')) ?: [] as $absatz) {
        if (trim($absatz) !== '') {
            $haupt .= '<p>' . nl2br($h(trim($absatz))) . '</p>';
        }
    }
    if (preg_match('#^https?://#i', $hotelUrl) === 1) {
        $haupt .= '<p><a href="' . Util::attr($hotelUrl) . '" target="_blank" rel="noopener nofollow">Zur Website des Hotels</a></p>';
    }
    $haupt .= '</div></div></section>';
}

if ($plaetze !== []) {
    $haupt .= '<section class="reiseteil" id="golf"><h2 class="reiseteil__titel">Die Plätze</h2>'
            . '<div class="golfplatzkarten">';
    foreach ($plaetze as $p) {
        $pb = Reiseseite::bildUrl($p['bild']);
        $daten = array_filter([$p['loecher'] > 0 ? $p['loecher'] . ' Loch' : '', $p['par'] > 0 ? 'Par ' . $p['par'] : '']);
        $haupt .= '<div class="golfplatzkarte">'
                . ($pb !== ''
                   ? '<div class="golfplatzkarte__bild"><img src="' . Util::attr($pb) . '" alt="" loading="lazy"></div>'
                   : '<div class="golfplatzkarte__bild platzhalter"><span class="platzhalter__text">' . $h($p['name']) . '</span></div>')
                . '<div class="golfplatzkarte__text"><h3>' . $h($p['name']) . '</h3>'
                . ($daten !== [] ? '<p class="golfplatzkarte__daten">' . $h(implode(' · ', $daten)) . '</p>' : '')
                . ($p['text'] !== '' ? '<p>' . $h($p['text']) . '</p>' : '')
                . '</div></div>';
    }
    $haupt .= '</div></section>';
}

if ($programm !== []) {
    $haupt .= '<section class="reiseteil" id="programm"><h2 class="reiseteil__titel">Tag für Tag</h2><ol class="reiseablauf">';
    $start = strtotime(substr((string) $reise['start'], 0, 10));
    $ende  = strtotime(substr((string) $reise['ende'], 0, 10));
    foreach ($programm as $nr => $tag) {
        $datum = $start !== false ? strtotime('+' . $nr . ' days', $start) : false;
        $datumText = $datum !== false && ($ende === false || $datum <= $ende)
            ? Util::tagKurz(date('Y-m-d', $datum)) . ', ' . (int) date('j', $datum) . '. ' . Util::monatName((int) date('n', $datum))
            : '';
        $tb = Reiseseite::bildUrl($tag['bild']);
        $haupt .= '<li class="reiseablauf__tag">'
                . '<div class="reiseablauf__marke"><span class="reiseablauf__nr">Tag ' . ($nr + 1) . '</span>'
                . ($datumText !== '' ? '<span class="reiseablauf__datum">' . $h($datumText) . '</span>' : '')
                . '</div><div class="reiseablauf__inhalt">'
                . ($tag['titel'] !== '' ? '<h3>' . $h($tag['titel']) . '</h3>' : '')
                . ($tag['text'] !== '' ? '<p>' . nl2br($h($tag['text'])) . '</p>' : '')
                . ($tb !== '' ? '<div class="reiseablauf__bild"><img src="' . Util::attr($tb) . '" alt="" loading="lazy"></div>' : '')
                . '</div></li>';
    }
    $haupt .= '</ol></section>';
}

if ($leistungen !== [] || $nicht !== []) {
    $haupt .= '<section class="reiseteil" id="leistungen"><h2 class="reiseteil__titel">Leistungen</h2><div class="reise__spalten">';
    if ($leistungen !== []) {
        $haupt .= '<div><h3 class="reise__untertitel">Im Preis enthalten</h3><ul class="reise__liste">';
        foreach ($leistungen as $l) {
            $haupt .= '<li>' . $h($l) . '</li>';
        }
        $haupt .= '</ul></div>';
    }
    if ($nicht !== []) {
        $haupt .= '<div><h3 class="reise__untertitel">Nicht enthalten</h3><ul class="reise__liste reise__liste--ohne">';
        foreach ($nicht as $l) {
            $haupt .= '<li>' . $h($l) . '</li>';
        }
        $haupt .= '</ul></div>';
    }
    $haupt .= '</div></section>';
}

/* Die Preise als Tabelle: jede Zeile ein Betrag pro Person. */
$preiszeilen = '<tr><th scope="row">Golfer im Doppelzimmer</th><td>' . $h(Util::geldKurz((int) $reise['preis_cent'], $w)) . '</td></tr>';
if ($preisNicht > 0 && $preisNicht !== (int) $reise['preis_cent']) {
    $preiszeilen .= '<tr><th scope="row">Nichtgolfer im Doppelzimmer</th><td>' . $h(Util::geldKurz($preisNicht, $w)) . '</td></tr>';
}
if ((int) $reise['ez_zuschlag_cent'] > 0) {
    $preiszeilen .= '<tr><th scope="row">Einzelzimmerzuschlag</th><td>+ ' . $h(Util::geldKurz((int) $reise['ez_zuschlag_cent'], $w)) . '</td></tr>';
}
if ($fruehbucher) {
    $preiszeilen .= '<tr class="ist-rabatt"><th scope="row">Frühbucherrabatt bei Buchung bis '
                  . $h(Util::datum((string) $reise['fruehbucher_bis'])) . '</th><td>− '
                  . $h(Util::geldKurz((int) $reise['fruehbucher_rabatt_cent'], $w)) . '</td></tr>';
}
$extrazeilen = '';
foreach ($extras as $x) {
    $extrazeilen .= '<tr><th scope="row">' . $h($x['name']) . ' <span class="preistabelle__je">'
                  . $h(Trips::EXTRA_JE[$x['je']] ?? '') . '</span></th><td>' . $h(Util::geldKurz($x['preis_cent'], $w)) . '</td></tr>';
}
$zahlung = [];
if ((int) $reise['anzahlung_cent'] > 0) {
    $zahlung[] = 'Anzahlung ' . Util::geldKurz((int) $reise['anzahlung_cent'], $w) . ' pro Person nach der Bestätigung.';
}
$zahlung[] = ((int) $reise['anzahlung_cent'] > 0 ? 'Restzahlung ' : 'Zahlung ')
           . (int) $reise['restzahlung_tage'] . ' Tage vor der Abreise.';
if ((int) $reise['mindest_teilnehmer'] > 0) {
    $zahlung[] = 'Die Reise findet ab ' . (int) $reise['mindest_teilnehmer'] . ' Teilnehmenden statt.';
}
$haupt .= '<section class="reiseteil" id="preise"><h2 class="reiseteil__titel">Preise</h2>'
        . '<table class="preistabelle"><caption>Pro Person</caption><tbody>' . $preiszeilen . '</tbody>'
        . ($extrazeilen !== '' ? '<tbody><tr><th colspan="2" class="preistabelle__gruppe" scope="colgroup">Zum Dazubuchen</th></tr>'
                                 . $extrazeilen . '</tbody>' : '')
        . '</table><p class="reise-zahlung">' . $h(implode(' ', $zahlung)) . '</p></section>';

if (trim((string) ($reise['hinweise'] ?? '')) !== '') {
    $haupt .= '<section class="reiseteil"><h2 class="reiseteil__titel">Gut zu wissen</h2><div class="reise__text reise__text--klein">';
    foreach (preg_split('/\n\s*\n/', (string) $reise['hinweise']) ?: [] as $absatz) {
        if (trim($absatz) !== '') {
            $haupt .= '<p>' . nl2br($h(trim($absatz))) . '</p>';
        }
    }
    $haupt .= '</div></section>';
}

/* ------------------------------------------------- Das Formular --- */

$buchen = '<section class="reisebuchung" id="buchen">'
        . '<p class="hand hand--mittel">Mitfahren</p><h2 class="reiseteil__titel">Reise buchen</h2>';

if ($fehler !== '') {
    $buchen .= Oeffentlich::meldung($fehler, 'fehler');
}

if ($begonnen) {
    $buchen .= Oeffentlich::meldung('Diese Reise hat bereits begonnen.', 'info');
} elseif (!$vorschau && !$buchbar) {
    $buchen .= Oeffentlich::meldung('Die Reise ist ausgebucht. Die nächsten Reisen stehen im Katalog.', 'warnung')
            . '<p><a class="knopf" href="' . Util::attr($katalog) . '">Alle Reisen ansehen</a></p>';
} else {
    if ($vorschau) {
        $buchen .= Oeffentlich::meldung('Vorschau: So sieht das Buchen aus. Absenden geht erst, wenn die Reise veröffentlicht ist.', 'info');
    } elseif ($frei <= 0) {
        $buchen .= Oeffentlich::meldung('Die Reise ist ausgebucht. Du kannst dich auf die Warteliste setzen lassen '
            . 'und rückst nach, sobald ein Platz frei wird.', 'warnung');
    }

    $nichtgolferZusatz = $preisNicht > 0 && $preisNicht !== (int) $reise['preis_cent']
        ? ' (' . Util::geldKurz($preisNicht, $w) . ')' : '';

    $personen = '';
    foreach ($eingaben['zeilen'] as $i => $z) {
        $n = $i + 1;
        $pflicht = $i === 0 ? ' required' : '';
        $personen .= '<div class="reisender" data-reisender>'
                   . '<div class="reisender__kopf"><span class="reisender__nr">' . $n . '</span>'
                   . '<span class="reisender__titel">' . ($i === 0
                        ? 'Erste Person <span class="leise">· Ansprechpartner der Buchung</span>'
                        : 'Person ' . $n . ' <span class="leise reisender__optional">· optional</span>') . '</span>'
                   . ($i > 0 ? '<button type="button" class="reisender__weg" data-reisender-weg hidden>Entfernen</button>' : '')
                   . '</div>'
                   . '<div class="feld-paar">'
                   . '<div class="feld"><label for="rv' . $i . '">Vorname</label>'
                   . '<input id="rv' . $i . '" name="reisende[' . $i . '][vorname]"' . $pflicht
                   . ($i === 0 ? ' autocomplete="given-name"' : ' autocomplete="off"') . ' value="' . Util::attr($z['vorname']) . '"></div>'
                   . '<div class="feld"><label for="rn' . $i . '">Nachname</label>'
                   . '<input id="rn' . $i . '" name="reisende[' . $i . '][nachname]"' . $pflicht
                   . ($i === 0 ? ' autocomplete="family-name"' : ' autocomplete="off"') . ' value="' . Util::attr($z['nachname']) . '"></div>'
                   . '</div><div class="feld-paar">'
                   . '<div class="feld"><label for="rg' . $i . '">Spielt Golf?</label>'
                   . '<select id="rg' . $i . '" name="reisende[' . $i . '][golfer]" data-golfer>'
                   . '<option value="1"' . ($z['golfer'] ? ' selected' : '') . '>Golfer</option>'
                   . '<option value="0"' . (!$z['golfer'] ? ' selected' : '') . '>Nichtgolfer' . $h($nichtgolferZusatz) . '</option>'
                   . '</select></div>'
                   . '<div class="feld" data-hcp-feld><label for="rh' . $i . '">Handicap</label>'
                   . '<input id="rh' . $i . '" name="reisende[' . $i . '][hcp]" inputmode="decimal" placeholder="z. B. 24,8"'
                   . ' value="' . Util::attr($z['hcp']) . '"></div>'
                   . '</div></div>';
    }

    $zimmerwahl = '';
    foreach (Trips::ZIMMER as $k => $name) {
        $preisText = $k === 'ez'
            ? ((int) $reise['ez_zuschlag_cent'] > 0 ? '+ ' . Util::geldKurz((int) $reise['ez_zuschlag_cent'], $w) . ' p. P.' : 'ohne Aufpreis')
            : 'im Reisepreis';
        $zimmerwahl .= '<label class="zimmerkarte"><input type="radio" name="zimmer" value="' . $k . '"'
                     . ($eingaben['zimmer'] === $k ? ' checked' : '') . '>'
                     . '<span class="zimmerkarte__koerper"><span class="zimmerkarte__name">' . $h($name) . '</span>'
                     . '<span class="zimmerkarte__preis">' . $h($preisText) . '</span>'
                     . '<span class="zimmerkarte__text">' . ($k === 'dz' ? 'Zu zweit in einem Zimmer.' : 'Jede Person in einem eigenen Zimmer.') . '</span>'
                     . '</span></label>';
    }

    $extraswahl = '';
    foreach ($extras as $nr => $x) {
        $wert = (int) ($eingaben['extras'][$nr] ?? 0);
        $feld = 'extra[' . $nr . ']';
        if ($x['je'] === 'buchung') {
            $wahl = '<label class="extrazeile__haken"><input type="checkbox" name="' . $feld . '" value="1"'
                  . ($wert > 0 ? ' checked' : '') . '><span>Dazubuchen</span></label>';
        } else {
            $optionen = '';
            for ($a = 0; $a <= Trips::PERSONEN_MAX; $a++) {
                $optionen .= '<option value="' . $a . '"' . ($a === $wert ? ' selected' : '') . '>'
                           . ($a === 0 ? 'keine' : $a . ' ×') . '</option>';
            }
            $wahl = '<div class="feld extrazeile__anzahl"><select name="' . $feld . '" data-extra-person'
                  . ' aria-label="' . Util::attr('Anzahl: ' . $x['name']) . '">' . $optionen . '</select></div>';
        }
        $extraswahl .= '<div class="extrazeile"><div class="extrazeile__text"><span class="extrazeile__name">' . $h($x['name']) . '</span>'
                     . '<span class="extrazeile__preis">' . $h(Util::geldKurz($x['preis_cent'], $w) . ' ' . (Trips::EXTRA_JE[$x['je']] ?? '')) . '</span>'
                     . '</div>' . $wahl . '</div>';
    }

    $nr = 0;
    $legende = static function (string $text) use (&$nr): string {
        $nr++;
        return '<legend class="reiseformular__legende"><span class="reiseformular__nr">' . $nr . '</span>' . Util::h($text) . '</legend>';
    };

    $buchen .= '<form method="post" class="reiseformular" action="' . Util::attr(Reiseseite::adresse($reise) . '#buchen') . '" data-reiseformular>'
            . '<input type="hidden" name="w" value="' . Util::attr((string) (Tenant::workspace()['slug'] ?? '')) . '">'
            . '<input type="hidden" name="r" value="' . Util::attr((string) $reise['slug']) . '">'
            . '<input type="hidden" name="begonnen" value="' . time() . '">'
            . '<input type="text" name="website" class="honigtopf" tabindex="-1" autocomplete="off" aria-hidden="true">'
            . '<div class="reiseformular__felder">'
            . '<fieldset class="reiseformular__teil">' . $legende('Wer reist mit?')
            . '<div class="reisende" data-reisende>' . $personen . '</div>'
            . '<button type="button" class="reiseformular__plus" data-reisender-neu hidden>+ Weitere Person</button>'
            . '<p class="leise">Bis zu ' . Trips::PERSONEN_MAX . ' Personen in einer Buchung. Größere Gruppen fragen bitte direkt an.</p>'
            . '</fieldset>'
            . '<fieldset class="reiseformular__teil">' . $legende('Zimmer')
            . '<div class="zimmerwahl">' . $zimmerwahl . '</div></fieldset>'
            . ($extraswahl !== ''
               ? '<fieldset class="reiseformular__teil">' . $legende('Dazubuchen')
                 . '<div class="extraliste">' . $extraswahl . '</div></fieldset>'
               : '')
            . '<fieldset class="reiseformular__teil">' . $legende('Kontakt')
            . '<div class="feld-paar">'
            . '<div class="feld"><label for="r-email">E-Mail</label>'
            . '<input id="r-email" type="email" name="email" autocomplete="email" required value="' . Util::attr(App::post('email')) . '"></div>'
            . '<div class="feld"><label for="r-telefon">Telefon</label>'
            . '<input id="r-telefon" type="tel" name="telefon" autocomplete="tel" value="' . Util::attr(App::post('telefon')) . '"></div>'
            . '</div>'
            . '<div class="feld"><label for="r-notiz">Wünsche und Anmerkungen</label>'
            . '<textarea id="r-notiz" name="notiz" rows="3" placeholder="z. B. Zimmer nebeneinander, Essenswünsche, gemeinsame Startzeiten">'
            . $h(App::post('notiz')) . '</textarea></div>'
            . '</fieldset>'
            . '</div>'
            . '<div class="reiseformular__seite">'
            . '<div class="reiserechnung" aria-live="polite"><p class="reiserechnung__kopf">Dein Reisepreis</p>'
            . '<div data-reiserechnung>' . Reiseseite::rechnung($reise, $rechnung, $frei) . '</div></div>'
            . '<label class="einwilligung"><input type="checkbox" name="einwilligung" value="1" required>'
            . '<span>Ich bin mit der Verarbeitung meiner Angaben und der meiner Mitreisenden für diese Reise '
            . 'einverstanden. Die Einwilligung kann ich jederzeit widerrufen.</span></label>'
            . '<button class="knopf knopf--gross knopf--voll" type="submit" name="aktion" value="anmelden" data-reise-absenden'
            . ($vorschau ? ' disabled' : '') . '>' . $h($knopfText($rechnung['personen'])) . '</button>'
            . '<button class="knopf knopf--strich knopf--klein reiseformular__rechnen" type="submit" name="aktion" value="rechnen"'
            . ' formnovalidate data-ohne-skript>Preis neu berechnen</button>'
            . '<p class="vorgang__klein">Verbindlich wird die Buchung mit meiner Bestätigung. '
            . 'Vorher bekommst du alle Einzelheiten per E-Mail.</p>'
            . '</div></form>';
}
$buchen .= '</section>';

/* ------------------------------------------- Seitenspalte und Leiste --- */

$ctaText = $vorschau || $frei > 0 ? 'Jetzt buchen' : 'Auf die Warteliste';
$box = '<div class="reisebox">'
     . '<p class="reisebox__ab">ab</p>'
     . '<p class="reisebox__preis">' . Reiseseite::abPreisHtml($reise) . '</p>'
     . '<p class="reisebox__pp">pro Person im Doppelzimmer'
     . ($fruehbucher ? '<br><strong>Frühbucherpreis bis ' . $h(Util::datum((string) $reise['fruehbucher_bis'])) . '</strong>' : '')
     . '</p>'
     . '<ul class="reisebox__liste">'
     . '<li>' . $h($zeitraum) . '</li>'
     . '<li>' . $h($naechte . ((string) $reise['anreise'] !== 'eigen' ? ' · ' . (Trips::ANREISE[(string) $reise['anreise']] ?? '') : '')) . '</li>'
     . '<li>' . ($frei > 0 ? 'noch ' . $frei . ' von ' . (int) $reise['plaetze'] . ' Plätzen frei' : 'ausgebucht') . '</li>'
     . '</ul>'
     . ($buchbar || $vorschau
        ? '<a class="knopf knopf--voll" href="#buchen">' . $h($ctaText) . '</a>' : '')
     . ((int) $reise['anzahlung_cent'] > 0
        ? '<p class="reisebox__klein">Anzahlung ' . $h(Util::geldKurz((int) $reise['anzahlung_cent'], $w)) . ' p. P. nach der Bestätigung</p>' : '')
     . '</div>';

$inhalt .= '<div class="inhalt-breite reiselayout">'
         . '<div class="reiselayout__haupt">' . $haupt . '</div>'
         . '<aside class="reiselayout__seite" aria-label="Preis und Buchung">' . $box . '</aside>'
         . '</div>'
         . '<div class="inhalt-breite">' . $buchen . '</div>';

if ($buchbar || $vorschau) {
    $inhalt .= '<div class="reiseleiste" data-reiseleiste>'
             . '<div class="reiseleiste__preis"><span class="reiseleiste__ab">ab</span> '
             . $h(Util::geldKurz(Trips::abPreis($reise), $w)) . ' <span class="reiseleiste__pp">p. P.</span></div>'
             . '<a class="knopf knopf--klein" href="#buchen">' . $h($ctaText) . '</a></div>';
}

$inhalt .= '<p class="inhalt-breite vorgang__zurueck reise-zurueck"><a href="' . Util::attr($katalog) . '">Alle Reisen</a></p>'
         . '</article>'
         . ($vorschau ? '' : Reiseseite::strukturierteDaten($reise, $frei))
         . '<script src="' . Util::attr(App::asset('assets/js/reise.js')) . '" defer></script>';

Oeffentlich::seite(($vorschau ? 'Vorschau · ' : '') . (string) $reise['titel'] . ' · ' . Tenant::name(), $inhalt, [
    'beschreibung' => (string) $reise['kurztext'] !== ''
        ? (string) $reise['kurztext'] : Util::kuerzen((string) $reise['beschreibung'], 160),
    'index'        => !$vorschau,
    'bild'         => Trips::pfad((string) $reise['bild']),
]);
