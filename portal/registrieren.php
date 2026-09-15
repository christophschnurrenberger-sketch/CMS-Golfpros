<?php
/**
 * Konto anlegen – ohne dass ein Termin im Spiel ist.
 *
 * Bisher entstand ein Kundenkonto nur nebenbei: beim Buchen, beim Kaufen
 * oder weil der Pro den Kunden eingetragen hat. Wer sich vorher in Ruhe
 * anmelden wollte, um Trainingsplan und Unterlagen zu sehen, hatte keinen
 * Weg dorthin.
 *
 * Das hier ist dieser Weg – und er bleibt der zweite. Gebucht wird
 * weiterhin ohne Konto; ein Pflichtkonto vor der ersten Buchung kostet
 * genau die Interessenten, die man gewinnen wollte.
 */
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/partials/zugang.php';

/*
 * Welcher Betrieb? Das ist hier keine Nebensache: Die Antwort entscheidet,
 * in wessen Kartei der neue Kunde landet. Deshalb dieselbe Reihenfolge wie
 * überall – eigene Domain, dann ?w=, dann die einzige Installation – und
 * lieber ein 404 als geraten.
 */
Oeffentlich::mandantSetzen();

/* Wer schon angemeldet ist, braucht kein zweites Konto. */
if (Kundenlogin::angemeldet()) {
    App::weiter('/portal/');
}

$slug = (string) (Tenant::workspace()['slug'] ?? '');
$zum  = static fn(string $pfad): string => Util::attr(Oeffentlich::url($pfad));

if (!Kundenlogin::registrierungOffen()) {
    zugangSeite('Kein Konto nötig', '', ''
        . '<div class="hinweis hinweis--still">' . Icon::svg('info', 17)
        . '<div class="hinweis__text">Ein Konto legt hier der Trainer an. '
        . 'Buchen kannst du trotzdem jederzeit – dafür braucht es keines, '
        . 'und den Zugang zu deinem Bereich bekommst du danach per E-Mail.</div></div>'
        . '<p class="zugang__wechsel"><a href="' . $zum('/portal/') . '">Zur Anmeldung</a></p>');
}

/* ------------------------------------------------------- Absenden ----- */

$fehler  = '';
$fertig  = '';

if (App::istPost() && App::aktion() === 'registrieren') {
    Auth::csrfFordern();
    if (Oeffentlich::istRoboter()) {
        $fehler = 'Das sah nach einem automatischen Versand aus. Bitte noch einmal versuchen.';
    } else {
        [$ergebnis, $meldung] = Kundenlogin::registrieren([
            'vorname'      => App::post('vorname'),
            'nachname'     => App::post('nachname'),
            'email'        => App::post('email'),
            'telefon'      => App::post('telefon'),
            'passwort'     => App::postRoh('passwort'),
            'einwilligung' => App::postBool('einwilligung'),
        ]);
        if ($ergebnis === 'angemeldet') {
            App::weiter('/portal/');
        }
        if ($ergebnis === 'mail') {
            $fertig = $meldung;
        } else {
            $fehler = $meldung;
        }
    }
}

/* --------------------------------------------------------- Ausgabe ---- */

if ($fertig !== '') {
    /*
     * Dieselbe Antwort wie nach einer frischen Registrierung. Ob es die
     * Adresse hier schon gab, steht bewusst nicht in der Meldung – sonst
     * ließe sich durchprobieren, wer bei diesem Pro Kunde ist.
     */
    zugangSeite('Schau in dein Postfach', '', ''
        . '<div class="hinweis hinweis--erfolg">' . Icon::svg('check', 17)
        . '<div class="hinweis__text">' . Util::h($fertig) . '</div></div>'
        . '<p class="zugang__wechsel"><a href="' . $zum('/portal/') . '">Zur Anmeldung</a></p>');
}

$wert = static fn(string $feld): string => Util::attr(App::post($feld));

zugangSeite('Konto anlegen', 'Termine, Trainingsplan und Unterlagen an einem Ort.', ''
    . ($fehler !== ''
       ? '<div class="hinweis hinweis--gefahr mb-4">' . Icon::svg('alert', 17)
         . '<div class="hinweis__text">' . Util::h($fehler) . '</div></div>'
       : '')
    . '<form method="post" class="karte">'
    . Auth::csrfFeld()
    . '<input type="hidden" name="aktion" value="registrieren">'
    . '<input type="hidden" name="w" value="' . Util::attr($slug) . '">'
    . '<input type="hidden" name="begonnen" value="' . time() . '">'
    . '<input type="text" name="website" class="honigtopf" tabindex="-1" autocomplete="off" aria-hidden="true">'
    . '<div class="karte__koerper">'
    /* Untereinander, nicht nebeneinander: Die Spalte ist 420 Pixel breit,
       zwei Felder daneben wären auf dem Telefon ohnehin umgebrochen – und
       eine Reihe, die nirgends eine ist, ist nur Auszeichnung ohne Wirkung. */
    . '<div class="feld"><label class="feld__label" for="r-vorname">Vorname</label>'
    . '<input class="eingabe" id="r-vorname" name="vorname" required autocomplete="given-name"'
    . ' value="' . $wert('vorname') . '"></div>'
    . '<div class="feld"><label class="feld__label" for="r-nachname">Nachname</label>'
    . '<input class="eingabe" id="r-nachname" name="nachname" required autocomplete="family-name"'
    . ' value="' . $wert('nachname') . '"></div>'
    . '<div class="feld"><label class="feld__label" for="r-email">E-Mail</label>'
    . '<input class="eingabe" id="r-email" type="email" name="email" required autocomplete="email"'
    . ' value="' . $wert('email') . '"></div>'
    . '<div class="feld"><label class="feld__label" for="r-telefon">Telefon <span class="gedimmt">(freiwillig)</span></label>'
    . '<input class="eingabe" id="r-telefon" type="tel" name="telefon" autocomplete="tel"'
    . ' value="' . $wert('telefon') . '"></div>'
    . '<div class="feld"><label class="feld__label" for="r-passwort">Passwort</label>'
    . '<input class="eingabe" id="r-passwort" type="password" name="passwort" required'
    . ' autocomplete="new-password" minlength="10">'
    . '<div class="feld__hinweis">Mindestens 10 Zeichen, gern Buchstaben und Zahlen gemischt.</div></div>'
    . '<label class="haken mb-4"><input type="checkbox" name="einwilligung" value="1" required>'
    . '<span class="haken__text">Ich bin mit der Verarbeitung meiner Angaben für meinen Kundenzugang '
    . 'einverstanden. Die Einwilligung kann ich jederzeit widerrufen.</span></label>'
    . '<button class="btn btn--primaer btn--voll" type="submit">Konto anlegen</button>'
    . '</div></form>'
    . '<p class="zugang__wechsel">Schon ein Konto? <a href="' . $zum('/portal/') . '">Anmelden</a></p>');
