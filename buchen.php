<?php
/**
 * Online-Buchung.
 *
 * Drei Schritte, ein Ziel: ein bestätigter Termin, ohne Konto, ohne
 * Registrierung, ohne Umweg. Wer mehr verlangt, verliert unterwegs die
 * Hälfte der Interessenten.
 *
 *   1. Zeit wählen   – freie Zeiten aus dem echten Kalender
 *   2. Angaben       – Name, E-Mail, Telefon
 *   3. Bestätigung   – Termin steht, Bestätigung ist unterwegs
 */
require __DIR__ . '/lib/bootstrap.php';

Oeffentlich::mandantSetzen();

$leistungen = Tenant::all('services', 'aktiv = 1 AND online_buchbar = 1', [], 'position, name');
if ($leistungen === []) {
    Oeffentlich::seite('Buchung', Oeffentlich::kasten(
        'Online-Buchung', '',
        Oeffentlich::meldung('Zurzeit ist nichts online buchbar. Nimm gern direkt Kontakt auf.', 'warnung')
        . '<p class="vorgang__zurueck"><a href="' . Util::attr(Oeffentlich::url('/')) . '">Zurück zur Website</a></p>'
    ));
}

$schritte = ['Zeit wählen', 'Deine Angaben', 'Bestätigung'];

/* ------------------------------------------------------------ Buchen --- */

if (App::istPost() && App::aktion() === 'buchen') {
    $serviceId = App::postInt('service_id');
    $start     = App::post('start');
    $trainerId = App::postInt('trainer_id');
    $service   = Tenant::find('services', $serviceId);

    $vorname  = trim(App::post('vorname'));
    $nachname = trim(App::post('nachname'));
    $email    = strtolower(trim(App::post('email')));
    $telefon  = trim(App::post('telefon'));
    $notiz    = trim(App::post('notiz'));

    $fehler = '';
    if (Oeffentlich::istRoboter()) {
        $fehler = 'Das sah nach einem automatischen Versand aus. Bitte noch einmal versuchen.';
    } elseif ($service === null || (int) $service['online_buchbar'] !== 1) {
        $fehler = 'Diese Leistung ist nicht mehr buchbar.';
    } elseif ($vorname === '' || $nachname === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $fehler = 'Bitte Vorname, Nachname und eine gültige E-Mail-Adresse angeben.';
    } elseif (!App::postBool('einwilligung')) {
        $fehler = 'Ohne die Einwilligung zur Verarbeitung der Angaben geht es leider nicht.';
    } else {
        /* Gegen die echte Verfügbarkeit prüfen – der Browser könnte alt sein. */
        $frei = Bookings::freieZeiten($serviceId, substr($start, 0, 10), $trainerId,
            (int) $service['dauer_min'] >= 60 ? 30 : 15);
        $passt = false;
        foreach ($frei as $z) {
            if ((string) $z['start'] === $start) {
                $passt = true;
                $trainerId = (int) $z['trainer_id'];
                break;
            }
        }
        if (!$passt) {
            $fehler = 'Diese Zeit ist inzwischen vergeben. Bitte eine andere wählen – '
                    . 'oben stehen die aktuell freien Zeiten.';
        }
    }

    if ($fehler === '') {
        $kundeId = Oeffentlich::kundeFinden($email, $vorname, $nachname, $telefon);

        /* Ein offenes Paket wird zuerst verbraucht – der Kunde hat es bezahlt. */
        $paketId = 0;
        foreach (Commerce::offenePakete($kundeId) as $p) {
            $paketId = (int) $p['id'];
            break;
        }

        [$id, $meldung] = Bookings::buchen([
            'service_id'  => $serviceId,
            'customer_id' => $kundeId,
            'trainer_id'  => $trainerId,
            'start'       => $start,
            'dauer_min'   => (int) $service['dauer_min'],
            'titel'       => (string) $service['name'],
            'preis_cent'  => (int) $service['preis_cent'],
            'notiz'       => $notiz,
            'customer_package_id' => $paketId,
            'quelle'      => 'website',
        ]);

        if ($id === 0) {
            $fehler = $meldung;
        } else {
            Oeffentlich::einwilligung($kundeId, 0, 'buchung',
                'Einwilligung zur Verarbeitung der Angaben für die Terminbuchung.', 'buchen.php');

            $kunde = Tenant::find('customers', $kundeId);
            $ort   = Tenant::find('locations', (int) $service['location_id']);
            Mail::senden($email, 'Dein Termin am ' . Util::datum($start),
                "Hallo " . $vorname . ",\n\n"
                . "dein Termin steht:\n\n"
                . Util::datumLang($start) . ", " . Util::uhrzeit($start) . " Uhr\n"
                . $service['name'] . "\n"
                . ($ort !== null ? $ort['name'] . ($ort['strasse'] !== '' ? ', ' . $ort['strasse'] . ', ' . $ort['plz'] . ' ' . $ort['ort'] : '') . "\n" : '')
                . "mit " . Auth::trainerName($trainerId) . "\n\n"
                . ((string) Tenant::einstellung('buchung_bestaetigung', '') !== ''
                   ? Tenant::einstellung('buchung_bestaetigung') . "\n\n" : '')
                . "Absagen kannst du bis "
                . (int) Tenant::einstellung('stornofrist_stunden', 24) . " Stunden vorher kostenfrei – "
                . "am einfachsten in deinem Bereich:\n" . Customers::portalLink($kunde ?? []) . "\n\n"
                . Tenant::name());

            $ortZeile = $ort !== null ? Util::h((string) $ort['name']) : '';
            Oeffentlich::seite('Termin bestätigt', Oeffentlich::kasten(
                'Dein Termin steht', '',
                Oeffentlich::schritte($schritte, 3)
                . '<div class="vorgang__erfolg">'
                . '<div class="vorgang__erfolg-datum">' . Util::h(Util::datumLang($start)) . '</div>'
                . '<div class="vorgang__erfolg-zeit">' . Util::h(Util::uhrzeit($start)) . ' Uhr · '
                . Util::h((string) $service['name']) . '</div>'
                . ($ortZeile !== '' ? '<div class="vorgang__erfolg-ort">' . $ortZeile . '</div>' : '')
                . '</div>'
                . '<p class="vorgang__text">Eine Bestätigung ist an <strong>' . Util::h($email)
                . '</strong> unterwegs. Darin steht auch der Link zu deinem persönlichen Bereich – '
                . 'dort siehst du alle Termine und kannst absagen oder verschieben.</p>'
                . ($paketId > 0
                   ? Oeffentlich::meldung('Diese Einheit wurde von deinem Paket abgezogen.', 'info') : '')
                . '<p class="vorgang__zurueck"><a href="' . Util::attr(Oeffentlich::url('/'))
                . '">Zurück zur Website</a></p>'
            ));
        }
    }
}

/* ----------------------------------------------- Zeiten und Formular --- */

$serviceId = App::postInt('service_id') ?: App::getInt('leistung');
if ($serviceId === 0 || Tenant::find('services', $serviceId) === null) {
    $serviceId = (int) $leistungen[0]['id'];
}
$service = Tenant::find('services', $serviceId);

$datum = App::post('datum') !== '' ? App::post('datum') : App::get('datum', Util::heute());
if (strtotime($datum) === false || $datum < Util::heute()) {
    $datum = Util::heute();
}
$gewaehlt = App::post('start');

/*
 * Das Raster der angebotenen Startzeiten.
 *
 * Im Backend sind 15 Minuten richtig: Wer von Hand einträgt, will einen
 * Termin genau dort hinlegen, wo er hinpasst. Auf der Website wäre dieselbe
 * Feinheit eine Zumutung – ein leerer Tag ergäbe vierzig Knöpfe, und
 * niemand beginnt eine Stunde gern um 12:45. Halbe Stunden bei längeren
 * Einheiten, Viertelstunden bei kurzen.
 */
$raster = (int) $service['dauer_min'] >= 60 ? 30 : 15;

/*
 * Die nächsten Tage mit freien Zeiten – höchstens fünf, damit die Seite
 * nicht endlos wird und die Abfrage nicht ausufert.
 */
$tage = [];
$gefunden = 0;
for ($i = 0; $i < 21 && $gefunden < 5; $i++) {
    $tag  = date('Y-m-d', strtotime($datum . ' +' . $i . ' days'));
    $frei = Bookings::freieZeiten($serviceId, $tag, 0, $raster);
    if ($frei !== []) {
        $tage[$tag] = $frei;
        $gefunden++;
    }
}

$inhalt = Oeffentlich::schritte($schritte, $gewaehlt !== '' ? 2 : 1);

if (isset($fehler) && $fehler !== '') {
    $inhalt .= Oeffentlich::meldung($fehler, 'warnung');
}

/* Schritt 1: Leistung und Zeit */
$inhalt .= '<form method="post" class="vorgang__form">'
         . '<input type="hidden" name="w" value="' . Util::attr((string) (Tenant::workspace()['slug'] ?? '')) . '">'
         . '<div class="feld"><label for="leistung">Leistung</label>'
         . '<select id="leistung" name="service_id" onchange="this.form.submit()">';
foreach ($leistungen as $l) {
    $inhalt .= '<option value="' . (int) $l['id'] . '"' . ((int) $l['id'] === $serviceId ? ' selected' : '') . '>'
             . Util::h((string) $l['name']) . ' · ' . Util::h(Util::geldKurz((int) $l['preis_cent']))
             . '</option>';
}
$inhalt .= '</select></div>'
         . '<div class="feld"><label for="datum">Ab wann</label>'
         . '<input id="datum" type="date" name="datum" value="' . Util::attr($datum) . '" min="'
         . Util::attr(Util::heute()) . '" onchange="this.form.submit()"></div>'
         . '<noscript><button class="knopf knopf--klein" type="submit">Zeiten anzeigen</button></noscript>'
         . '</form>';

if ((string) $service['beschreibung'] !== '') {
    $inhalt .= '<p class="vorgang__text">' . Util::h((string) $service['beschreibung']) . '</p>';
}

if ($tage === []) {
    $inhalt .= Oeffentlich::meldung(
        'In den nächsten drei Wochen ist hier nichts frei. Schreib mir gern kurz – '
      . 'oft lässt sich doch etwas einrichten.', 'warnung')
      . '<p class="vorgang__zurueck"><a href="' . Util::attr(Oeffentlich::url('/', ['s' => 'kontakt']))
      . '">Zum Kontaktformular</a></p>';
} else {
    /*
     * Die freien Zeiten als Liste, nicht als Knopfwolke.
     *
     * Eine Wolke aus dreißig gleich aussehenden Uhrzeiten zwingt zum
     * Suchen; untereinander liest man Tag, Uhrzeit und Leistung in einer
     * Zeile. Der wechselnde Einzug nimmt der Liste die Strenge, und die
     * gewählte Zeile wird dunkel – das sieht man auch aus dem Augenwinkel.
     */
    $inhalt .= '<div class="zeitliste">';
    foreach ($tage as $tag => $frei) {
        /*
         * Das Datum steht nur in der ersten Zeile eines Tages. Viermal
         * „Donnerstag, 17. September" untereinander liest niemand – und
         * es verdeckt genau das, was sich von Zeile zu Zeile ändert.
         */
        $ersteDesTages = true;
        foreach ($frei as $z) {
            $aktiv = (string) $z['start'] === $gewaehlt;
            $inhalt .= '<form method="post" style="display:contents">'
                     . '<input type="hidden" name="w" value="' . Util::attr((string) (Tenant::workspace()['slug'] ?? '')) . '">'
                     . '<input type="hidden" name="service_id" value="' . $serviceId . '">'
                     . '<input type="hidden" name="datum" value="' . Util::attr($datum) . '">'
                     . '<input type="hidden" name="start" value="' . Util::attr((string) $z['start']) . '">'
                     . '<input type="hidden" name="trainer_id" value="' . (int) $z['trainer_id'] . '">'
                     . '<button class="zeit-zeile' . ($aktiv ? ' ist-gewaehlt' : '') . '" type="submit">'
                     . '<span class="zeit-zeile__tag">'
                     . ($ersteDesTages ? Util::h(Util::datumLang($tag)) : '') . '</span>'
                     . '<span class="zeit-zeile__zeit">' . Util::h(Util::uhrzeit((string) $z['start'])) . '</span>'
                     . '<span class="zeit-zeile__art">' . Util::h((string) $service['name'])
                     . ' · ' . (int) $service['dauer_min'] . ' Minuten</span>'
                     . '</button></form>';
            $ersteDesTages = false;
        }
    }
    $inhalt .= '</div>';
}

/* Schritt 2: Angaben – erst wenn eine Zeit gewählt ist */
if ($gewaehlt !== '') {
    $trainerId = App::postInt('trainer_id');
    $inhalt .= '<div class="vorgang__gewaehlt">'
             . '<strong>' . Util::h(Util::datumLang($gewaehlt)) . ', '
             . Util::h(Util::uhrzeit($gewaehlt)) . ' Uhr</strong> · '
             . Util::h((string) $service['name'])
             . ($trainerId > 0 ? ' · mit ' . Util::h(Auth::trainerName($trainerId)) : '')
             . '</div>'
             . '<form method="post" class="vorgang__form">'
             . '<input type="hidden" name="aktion" value="buchen">'
             . '<input type="hidden" name="w" value="' . Util::attr((string) (Tenant::workspace()['slug'] ?? '')) . '">'
             . '<input type="hidden" name="service_id" value="' . $serviceId . '">'
             . '<input type="hidden" name="start" value="' . Util::attr($gewaehlt) . '">'
             . '<input type="hidden" name="trainer_id" value="' . $trainerId . '">'
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
             . '<div class="feld"><label for="notiz">Möchtest du noch etwas dazusagen?</label>'
             . '<textarea id="notiz" name="notiz" rows="2">' . Util::h(App::post('notiz')) . '</textarea></div>'
             . '<label class="einwilligung"><input type="checkbox" name="einwilligung" value="1" required>'
             . '<span>Ich bin mit der Verarbeitung meiner Angaben zur Durchführung des Termins '
             . 'einverstanden. Die Einwilligung kann ich jederzeit widerrufen.</span></label>'
             . '<button class="knopf knopf--primaer" type="submit">Termin verbindlich buchen</button>'
             . '<p class="vorgang__klein">Absage bis '
             . (int) Tenant::einstellung('stornofrist_stunden', 24) . ' Stunden vorher kostenfrei. '
             . 'Bezahlt wird vor Ort oder per Rechnung.</p>'
             . '</form>';
}

Oeffentlich::seite('Termin buchen · ' . Tenant::name(),
    Oeffentlich::kasten('Termin buchen',
        'Freie Zeiten direkt aus dem Kalender – was du hier siehst, ist auch wirklich frei.',
        $inhalt));
