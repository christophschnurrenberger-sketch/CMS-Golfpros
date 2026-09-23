<?php
require __DIR__ . '/lib/bootstrap.php';

/* Im Support Mode endet nur die Sicht auf die Instanz – zurück geht es in
   die Betreiberzentrale, auf die Seite der Instanz, die gerade offen war. */
if (Support::vermerkt()) {
    $instanz = Support::beenden('abgemeldet');
    App::weiter($instanz > 0 ? '/master/instanz.php?id=' . $instanz : '/master/');
}
if (Betreiber::angemeldet()) {
    Betreiber::abmelden();
    App::weiter('/login.php');
}
Auth::abmelden();
App::weiter('/login.php');
