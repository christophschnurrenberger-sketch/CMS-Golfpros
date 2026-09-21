<?php
/**
 * Ein Feld eines Bausteins ändern – ohne die Seite zu verlassen.
 *
 * Der Baukasten lässt inzwischen direkt in der Vorschau schreiben: Man
 * klickt auf die Überschrift und tippt. Diese Datei nimmt das Ergebnis
 * entgegen. Sie kennt drei Handlungen, und alle drei betreffen genau ein
 * Feld oder genau einen Listeneintrag:
 *
 *   feld          einen Wert setzen (oben im Baustein oder in einem Eintrag)
 *   eintrag_neu   einen leeren Eintrag anhängen
 *   eintrag_weg   einen Eintrag entfernen
 *
 * Was hereinkommt, wird gegen die Baustein-Definition geprüft – nicht
 * gegen das, was der Aufruf behauptet. Ein Feld, das der Baustein nicht
 * hat, wird nicht geschrieben; eine Liste nimmt nur ihre eigenen
 * Unterfelder an. Sonst könnte jeder beliebige Schlüssel in den
 * Bausteindaten landen, und die stehen später im HTML der Website.
 *
 * Die Werte selbst bleiben roh, genau wie beim Formular: Das Escaping
 * macht der Renderer bei der Ausgabe. Würde hier schon escaped, stünde
 * beim nächsten Bearbeiten `&amp;` im Feld.
 */
require __DIR__ . '/../lib/bootstrap.php';

/* Ein JSON-Endpunkt schickt niemanden auf die Anmeldeseite – im Baukasten
   käme das als unlesbare HTML-Antwort an. */
if (!Auth::angemeldet()) {
    App::json(['fehler' => 'Die Sitzung ist abgelaufen. Bitte neu anmelden.'], 401);
}
if (!Auth::darf('website.write')) {
    App::json(['fehler' => 'Dafür fehlt die Berechtigung.'], 403);
}
if (!App::istPost()) {
    App::json(['fehler' => 'Nur als POST.'], 405);
}
Auth::csrfFordern();

/* Tenant::find() gibt bei einer fremden Seite null zurück – die Prüfung
   auf den Mandanten steckt dort und muss hier nicht wiederholt werden. */
$seiteId = App::postInt('seite');
$seite   = Tenant::find('pages', $seiteId);
if (!$seite) {
    App::json(['fehler' => 'Diese Seite gibt es nicht.'], 404);
}

$bloecke = Pages::bloecke($seite);
$blockId = App::post('block');
$nr      = App::postInt('nr', -1);

$i = null;
foreach ($bloecke as $k => $b) {
    if ((string) ($b['id'] ?? '') === $blockId) {
        $i = $k;
        break;
    }
}
if ($i === null) {
    App::json(['fehler' => 'Diesen Baustein gibt es auf der Seite nicht.'], 404);
}

$typ = Bloecke::typ((string) $bloecke[$i]['typ']);
if ($typ === null) {
    App::json(['fehler' => 'Unbekannter Bausteintyp.'], 400);
}

$feld = App::post('feld');
$def  = (array) ($typ['felder'][$feld] ?? []);
if ($def === []) {
    App::json(['fehler' => 'Dieses Feld hat der Baustein nicht.'], 400);
}
$art    = (string) ($def[1] ?? 'text');
$daten  = (array) ($bloecke[$i]['daten'] ?? []);
$aktion = App::aktion();

/* ------------------------------------------------------------ Eintrag - */

if ($aktion === 'eintrag_neu' || $aktion === 'eintrag_weg') {
    if ($art !== 'liste') {
        App::json(['fehler' => 'Dieses Feld ist keine Liste.'], 400);
    }
    $liste = array_values((array) ($daten[$feld] ?? []));

    if ($aktion === 'eintrag_neu') {
        /* Ein neuer Eintrag hat alle Unterfelder, alle leer – sonst fehlten
           in der Vorschau die Stellen zum Hineinschreiben. */
        $leer = [];
        foreach (array_keys((array) ($def[2] ?? [])) as $uf) {
            $leer[$uf] = '';
        }
        $liste[] = $leer;
    } else {
        if ($nr < 0 || $nr >= count($liste)) {
            App::json(['fehler' => 'Diesen Eintrag gibt es nicht.'], 400);
        }
        array_splice($liste, $nr, 1);
    }

    $daten[$feld] = $liste;
    $bloecke[$i]['daten'] = $daten;
    Pages::speichern(['bloecke' => Util::json($bloecke)], $seiteId);
    App::json(['ok' => true, 'anzahl' => count($liste), 'neu_laden' => true]);
}

/* --------------------------------------------------------------- Feld - */

if ($aktion !== 'feld') {
    App::json(['fehler' => 'Unbekannte Handlung.'], 400);
}

$wert = App::postRoh('wert');

/*
 * Zeilenenden vereinheitlichen. Der Browser schickt je nach Fassung \r\n
 * oder \n; der Renderer trennt Absätze an zwei Umbrüchen. Käme beides
 * gemischt an, stünden Absätze mal getrennt und mal nicht.
 */
$wert = str_replace(["\r\n", "\r"], "\n", $wert);

/* Mehr als zwei Umbrüche am Stück sind kein Absatz mehr, sondern ein
   Versehen beim Tippen. */
$wert = preg_replace("/\n{3,}/", "\n\n", $wert) ?? $wert;

if ($art === 'liste') {
    $unter = App::post('unter');
    if (!isset($def[2][$unter])) {
        App::json(['fehler' => 'Dieses Feld hat der Eintrag nicht.'], 400);
    }
    $liste = array_values((array) ($daten[$feld] ?? []));
    if ($nr < 0 || $nr >= count($liste)) {
        App::json(['fehler' => 'Diesen Eintrag gibt es nicht.'], 400);
    }
    $liste[$nr] = (array) $liste[$nr];
    $liste[$nr][$unter] = $wert;
    $daten[$feld] = $liste;
} else {
    if (!in_array($art, ['text', 'mehrzeilig', 'url', 'bild'], true)) {
        /* Auswahl, Schalter und Zahl haben in der Vorschau keine Gestalt,
           in die man hineinschreiben könnte – die bleiben im Formular. */
        App::json(['fehler' => 'Dieses Feld lässt sich nur rechts ändern.'], 400);
    }
    $daten[$feld] = $wert;
}

$bloecke[$i]['daten'] = $daten;
Pages::speichern(['bloecke' => Util::json($bloecke)], $seiteId);

/*
 * Ob die Vorschau neu geladen werden muss.
 *
 * Beim Tippen nicht: Was man sieht, hat man gerade selbst geschrieben.
 * Bei einem Bild schon – da steht bis eben noch der gestreifte
 * Platzhalter, und der muss dem Bild weichen.
 */
App::json(['ok' => true, 'neu_laden' => $art === 'bild']);
