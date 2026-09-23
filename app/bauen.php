<?php
/**
 * Der Baukasten arbeitet an Ort und Stelle – hier ist seine Gegenstelle.
 *
 * Jede Handlung, die früher ein Formular mit Weiterleitung war
 * (hinzufügen, kopieren, entfernen, verschieben, speichern), kommt hier als
 * kleiner POST an und bekommt als Antwort genau das HTML zurück, das sich
 * geändert hat: den einen Baustein für die Leinwand und seine Zeile für
 * den Aufbau links. Die Seite bleibt stehen, die Rollposition auch.
 *
 *   hinzu        typ, nach          neuer Baustein hinter `nach`
 *   kopie        block              Kopie direkt dahinter
 *   weg          block              entfernen (bleibt in der Ablage der Sitzung)
 *   einsetzen    block, nach        aus der Ablage zurückholen („Rückgängig")
 *   reihenfolge  reihenfolge        alle Kennungen in der neuen Folge
 *   speichern    block, f_*, l_*    Felder aus der rechten Spalte
 *   block        block              den Baustein neu ausgeben
 *   panel        block              die rechte Spalte für diesen Baustein ('' = die Seite)
 *
 * Was hier ankommt, wird gegen die Seite des eigenen Mandanten geprüft:
 * `Tenant::find()` liefert eine fremde Seite nicht, und jede Kennung eines
 * Bausteins muss auf dieser Seite stehen. Das HTML kommt aus denselben
 * Teilstücken wie beim Laden der Seite.
 */
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/partials/helfer.php';

if (!Auth::angemeldet()) {
    App::json(['fehler' => 'Die Sitzung ist abgelaufen. Bitte neu anmelden.'], 401);
}
if (!Auth::darf('modul.website') || !Auth::darf('website.write')) {
    App::json(['fehler' => 'Dafür fehlt die Berechtigung.'], 403);
}
if (!App::istPost()) {
    App::json(['fehler' => 'Nur als POST.'], 405);
}
Auth::csrfFordern();

$seiteId = App::postInt('seite');
[$seite, $bloecke] = Baukasten::laden($seiteId);
if ($seite === null) {
    App::json(['fehler' => 'Diese Seite gibt es nicht.'], 404);
}

$aktion  = App::aktion();
$blockId = App::post('block');

/** Ein Teilstück als Zeichenkette – mit denselben Variablen wie in seite.php. */
$teil = static function (string $datei, array $vars): string {
    extract($vars, EXTR_SKIP);
    ob_start();
    require __DIR__ . '/partials/' . $datei . '.php';
    return (string) ob_get_clean();
};

/** Leinwand-HTML und Aufbau-Zeile eines Bausteins. */
$ausgabe = static function (array $block) use ($teil, $seiteId): array {
    Renderer::bearbeitbar(true);
    $html = $teil('bau-block', ['bauBlock' => $block, 'bauGewaehlt' => '']);
    Renderer::bearbeitbar(false);
    return [
        'id'    => (string) $block['id'],
        'typ'   => (string) $block['typ'],
        'name'  => Bloecke::name((string) $block['typ']),
        'html'  => $html,
        'teil'  => $teil('bau-teil', ['bauBlock' => $block, 'bauSeiteId' => $seiteId, 'bauGewaehlt' => '']),
    ];
};

switch ($aktion) {
    case 'hinzu':
        [$neu, $grund] = Baukasten::hinzufuegen($seiteId, App::post('typ'), App::post('nach'));
        if ($neu === null) {
            App::json(['fehler' => $grund], 400);
        }
        App::json(['ok' => true, 'block' => $ausgabe($neu)]);

    case 'kopie':
        $kopie = Baukasten::kopieren($seiteId, $blockId);
        if ($kopie === null) {
            App::json(['fehler' => 'Diesen Baustein gibt es auf der Seite nicht.'], 404);
        }
        App::json(['ok' => true, 'block' => $ausgabe($kopie), 'nach' => $blockId]);

    case 'weg':
        $vorher = Baukasten::entfernen($seiteId, $blockId);
        if ($vorher === null) {
            App::json(['fehler' => 'Diesen Baustein gibt es auf der Seite nicht.'], 404);
        }
        App::json(['ok' => true, 'nach' => $vorher]);

    case 'einsetzen':
        $block = Baukasten::zurueckholen($seiteId, $blockId, App::post('nach'));
        if ($block === null) {
            App::json(['fehler' => 'Dieser Baustein lässt sich nicht mehr zurückholen.'], 410);
        }
        App::json(['ok' => true, 'block' => $ausgabe($block)]);

    case 'reihenfolge':
        $kennungen = array_values(array_filter(explode(',', App::post('reihenfolge')), 'strlen'));
        if (!Baukasten::ordnen($seiteId, $kennungen)) {
            App::json(['fehler' => 'Die Reihenfolge passt nicht mehr zur Seite. Bitte neu laden.'], 409);
        }
        App::json(['ok' => true]);

    case 'speichern':
        $i = Baukasten::stelle($bloecke, $blockId);
        if ($i === null) {
            App::json(['fehler' => 'Diesen Baustein gibt es auf der Seite nicht.'], 404);
        }
        $block = Baukasten::datenSetzen($seiteId, $blockId, Baukasten::datenAusFormular((string) $bloecke[$i]['typ']));
        App::json(['ok' => true, 'block' => $ausgabe((array) $block)]);

    case 'block':
        $i = Baukasten::stelle($bloecke, $blockId);
        if ($i === null) {
            App::json(['fehler' => 'Diesen Baustein gibt es auf der Seite nicht.'], 404);
        }
        App::json(['ok' => true, 'block' => $ausgabe($bloecke[$i])]);

    case 'panel':
        $i = $blockId !== '' ? Baukasten::stelle($bloecke, $blockId) : null;
        if ($blockId !== '' && $i === null) {
            App::json(['fehler' => 'Diesen Baustein gibt es auf der Seite nicht.'], 404);
        }
        App::json(['ok' => true, 'html' => $teil('bau-panel', [
            'bauSeite' => $seite, 'bauBloecke' => $bloecke,
            'bauBlock' => $i !== null ? $bloecke[$i] : null, 'bauSchreiben' => true,
        ])]);

    default:
        App::json(['fehler' => 'Unbekannte Handlung.'], 400);
}
