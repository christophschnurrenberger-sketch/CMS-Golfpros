<?php
/**
 * Ausgabe persönlicher Dateien – mit Prüfung, wer sie sehen darf.
 *
 * Schwungvideos und Unterlagen lagen bis hierher als gewöhnliche Adressen
 * unter `uploads/` und waren damit für jeden abrufbar, der die Adresse
 * kannte oder erriet: keine Sitzung, keine Prüfung, kein Mandant. Der
 * Haken „sichtbar im Portal" blendete nur den Link aus, nicht die Datei.
 *
 * Jetzt liegen sie unter `data/`, wo die .htaccess jeden direkten Abruf
 * verweigert, und kommen nur noch hier heraus. Geprüft wird:
 *
 *   * Der Datensatz gehört zum Mandanten des Abrufenden – dafür sorgt
 *     `Tenant::find()` von selbst.
 *   * Ein Mitarbeiter braucht das passende Recht.
 *   * Ein Kunde bekommt nur seine eigenen Dateien, und Unterlagen nur,
 *     wenn sie für das Portal freigegeben sind.
 *
 * Wer nicht darf, bekommt 404 und nicht 403: Ein „verboten" bestätigt,
 * dass es die Datei gibt.
 */
require __DIR__ . '/lib/bootstrap.php';

/** Immer dieselbe Antwort, egal woran es lag. */
function nichtDa(): never
{
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Nicht gefunden.');
}

$art = App::get('art');
$id  = App::getInt('id');
if ($id <= 0 || !in_array($art, ['video', 'dokument'], true)) {
    nichtDa();
}

/*
 * Mandant zuerst: Ein angemeldeter Kunde bringt seinen eigenen mit, ein
 * Mitarbeiter den seiner Anmeldung. Ohne beides gibt es nichts zu holen.
 */
$kunde     = Kundenlogin::kunde();
$mitarbeit = Auth::angemeldet();
if ($kunde === null && !$mitarbeit) {
    nichtDa();
}

$satz = Tenant::find($art === 'video' ? 'videos' : 'documents', $id);
if ($satz === null) {
    nichtDa();
}

if ($kunde !== null) {
    if ((int) $satz['customer_id'] !== (int) $kunde['id']) {
        nichtDa();
    }
    /* Unterlagen sieht der Kunde nur, wenn der Trainer sie freigegeben hat. */
    if ($art === 'dokument' && (int) ($satz['sichtbar_portal'] ?? 0) !== 1) {
        nichtDa();
    }
} elseif (!Auth::darf($art === 'video' ? 'video.view' : 'customers.view')) {
    nichtDa();
}

/* ------------------------------------------------------------ Datei --- */

$relativ = (string) ($art === 'video' ? $satz['datei'] : $satz['pfad']);
if ($relativ === '') {
    nichtDa();
}

/*
 * Der Pfad kommt aus der Datenbank, nicht aus der Adresszeile – trotzdem
 * geprüft: Ein „../" in einem alten Datensatz oder aus einer künftigen
 * Importfunktion würde sonst jede Datei des Servers ausliefern, config.php
 * eingeschlossen.
 */
$voll = realpath(GP_ROOT . '/' . ltrim($relativ, '/'));
$wurzeln = [realpath(GP_ROOT . '/data/privat'), realpath(GP_ROOT . '/uploads')];
$drin = false;
foreach ($wurzeln as $wurzel) {
    if ($wurzel !== false && $voll !== false && str_starts_with($voll, $wurzel . DIRECTORY_SEPARATOR)) {
        $drin = true;
        break;
    }
}
if (!$drin || !is_file($voll)) {
    nichtDa();
}

$typen = [
    'mp4' => 'video/mp4', 'mov' => 'video/quicktime', 'webm' => 'video/webm',
    'pdf' => 'application/pdf', 'png' => 'image/png', 'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg', 'webp' => 'image/webp', 'gif' => 'image/gif',
];
$endung = strtolower((string) pathinfo($voll, PATHINFO_EXTENSION));
$typ    = $typen[$endung] ?? 'application/octet-stream';

$name = (string) ($satz['titel'] ?? 'datei');
$name = Util::slug($name, 60) . '.' . ($endung !== '' ? $endung : 'bin');

header('Content-Type: ' . $typ);
header('Content-Length: ' . (string) filesize($voll));
/* nosniff, damit der Browser eine hochgeladene Datei nicht doch als
   etwas Ausführbares deutet; inline, damit Videos im Portal abspielen. */
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: inline; filename="' . $name . '"');
header('Cache-Control: private, max-age=0, no-store');

readfile($voll);
