<?php
/**
 * Der Bildwähler – Liste und Upload, als JSON.
 *
 * Damit sich ein Bildfeld im Baukasten ohne Seitenwechsel füllen lässt:
 * Bisher stand dort ein Textfeld mit dem Hinweis „Mediathek öffnen und
 * Pfad einfügen". Wer ein Foto auf seine Website stellen wollte, musste
 * einen zweiten Tab öffnen, hochladen, den Pfad abschreiben und
 * zurückwechseln. Das hat niemand gemacht – und deshalb standen auf den
 * Websites die Ersatzstreifen statt Bildern.
 *
 *   GET  /app/bilder.php          Liste der Bilder dieses Workspace
 *   POST /app/bilder.php          ein Bild hochladen, liefert seinen Pfad
 *
 * Kein Teil der öffentlichen Schnittstelle (api.php): Das hier setzt eine
 * angemeldete Sitzung voraus und gehört zur Oberfläche.
 */
require __DIR__ . '/../lib/bootstrap.php';

/* Ein JSON-Endpunkt schickt niemanden auf die Anmeldeseite – das würde
   im Wähler als unlesbare HTML-Antwort ankommen. */
if (!Auth::angemeldet()) {
    App::json(['fehler' => 'Die Sitzung ist abgelaufen. Bitte neu anmelden.'], 401);
}

/*
 * Wer irgendwo im System ein Bild einsetzen darf, darf es auch hochladen.
 * Das Recht kommt aus drei Richtungen: vom Baukasten, von den Inhalten –
 * und vom Team, denn das Foto eines Trainers hängt am Teammitglied und
 * nicht an einer Seite. Ein Head-Pro etwa pflegt das Team, hat aber keine
 * Schreibrechte an der Website; ohne diese dritte Zeile liefe er beim
 * Klick auf „Foto wählen" gegen eine 403.
 */
if (!Auth::darf('website.write') && !Auth::darf('content.write')
    && !Auth::darf('settings.team')) {
    App::json(['fehler' => 'Dafür fehlt die Berechtigung.'], 403);
}

if (App::istPost()) {
    Auth::csrfFordern();
    if (!isset($_FILES['datei'])) {
        App::json(['fehler' => 'Es kam keine Datei an.'], 400);
    }
    [$pfad, $grund] = Medien::uebernehmen((array) $_FILES['datei']);
    if ($pfad === '') {
        App::json(['fehler' => $grund], 400);
    }
    App::json(['pfad' => $pfad, 'url' => App::url($pfad)]);
}

$bilder = [];
foreach (Medien::bilder() as $m) {
    $bilder[] = [
        'pfad'   => (string) $m['pfad'],
        'url'    => App::url((string) $m['pfad']),
        'name'   => (string) $m['dateiname'],
        'breite' => (int) $m['breite'],
        'hoehe'  => (int) $m['hoehe'],
    ];
}
App::json(['bilder' => $bilder]);
