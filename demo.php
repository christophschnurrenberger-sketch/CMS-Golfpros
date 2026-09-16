<?php
/**
 * Demo-Zugang ohne Passwort.
 *
 * Diese Seite meldet den Aufrufenden als Inhaber eines Workspace an, der
 * `demo = 1` trägt – ohne Passwort. Für eine öffentliche Vorführung ist
 * das genau richtig. Auf dem Server eines Golflehrers, der die Demo-Daten
 * bei der Einrichtung mitangelegt hat und diese Datei längst vergessen
 * hat, ist es eine offene Tür: Jeder, der die Adresse errät, sieht die
 * Kundenliste des Demo-Bereichs und kann über die Kampagnenfunktion Mails
 * unter der Absenderadresse des Betreibers verschicken.
 *
 * Darum entscheidet ein Eintrag in der config.php, und zwar in drei
 * Abstufungen:
 *
 *     'demo_zugang' => false,          // aus (Standard)
 *     'demo_zugang' => 'mein-schluessel',  // nur /demo.php?k=mein-schluessel
 *     'demo_zugang' => true,           // offen für jeden
 *
 * Die mittlere Fassung ist für den Normalfall gedacht: Der Schlüssel
 * steht im Link auf der eigenen Website, und wer nur /demo.php probiert,
 * findet nichts. Das ist kein Passwortschutz – der Schlüssel steht ja im
 * Link –, aber es nimmt die Seite aus dem Blickfeld jedes Scanners, der
 * bekannte Adressen durchprobiert. Genau darum geht es hier.
 *
 * Am sichersten bleibt es, die Datei auf dem Produktivserver zu löschen.
 */
require __DIR__ . '/lib/bootstrap.php';

$erlaubnis = Config::get('demo_zugang', false);
$offen     = $erlaubnis === true;
$schluessel = is_string($erlaubnis) ? trim($erlaubnis) : '';

if ($schluessel !== '') {
    $offen = hash_equals($schluessel, App::get('k'));
}

if (!$offen) {
    /*
     * 404 mit einer Seite, die sich selbst erklärt.
     *
     * Vorher stand hier ein zweizeiliges „Nicht gefunden." – und manche
     * Hoster ersetzen einen so kurzen Fehlerkörper durch ihre eigene
     * Seite. Der Betreiber sah dann „Inhalt nicht gefunden" und hatte
     * keine Möglichkeit zu erkennen, dass nicht der Server kaputt ist,
     * sondern ein Schalter aus. Verraten wird dabei nichts, was hilft:
     * Wer den Schalter umlegen kann, hat ohnehin Zugriff auf die Dateien.
     */
    http_response_code(404);
    ?><!DOCTYPE html>
<html lang="de"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Demo-Zugang ist aus</title>
<style>
  :root { color-scheme: light dark; }
  body { margin: 0; min-height: 100vh; display: grid; place-items: center;
         background: #f6f7f4; color: #131b18; padding: 24px;
         font: 16px/1.6 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
  .kasten { max-width: 34rem; }
  h1 { font-size: 22px; margin: 0 0 12px; }
  p { margin: 0 0 14px; color: #414c47; }
  code { font-family: ui-monospace, Menlo, monospace; font-size: 14px;
         background: #e7eae5; padding: 2px 6px; border-radius: 3px; }
  pre { background: #e7eae5; padding: 14px 16px; border-radius: 6px; overflow-x: auto;
        font-family: ui-monospace, Menlo, monospace; font-size: 13.5px; margin: 0 0 14px; }
  .klein { font-size: 14px; color: #6d7973; }
  a { color: #2a5c3f; }
  @media (prefers-color-scheme: dark) {
    body { background: #121715; color: #e8ece9; }
    p { color: #b3bdb8; } code, pre { background: #222a26; }
    .klein { color: #869089; } a { color: #6db78b; }
  }
</style></head><body>
<div class="kasten">
  <h1>Der Demo-Zugang ist hier nicht aktiv</h1>
  <p>Diese Adresse meldet Besucher ohne Passwort im Demo-Bereich an. Sie ist
     standardmäßig geschlossen, damit sie auf einem Produktivserver nicht
     versehentlich offen steht.</p>
  <p class="klein">Wenn Sie diese Installation betreuen: In der
     <code>config.php</code> steuert ein Eintrag den Zugang.</p>
  <pre>'demo_zugang' =&gt; 'ein-eigener-schluessel',   // /demo.php?k=ein-eigener-schluessel
'demo_zugang' =&gt; true,                      // offen für jeden
'demo_zugang' =&gt; false,                     // aus (Standard)</pre>
  <p class="klein">Der mittlere Weg ist für eine öffentliche Vorführung gedacht:
     Der Schlüssel steht im Link auf Ihrer Website, und wer nur die nackte
     Adresse probiert, findet nichts.</p>
  <p><a href="<?= htmlspecialchars(App::url('/login.php'), ENT_QUOTES, 'UTF-8') ?>">Zur normalen Anmeldung</a></p>
</div>
</body></html><?php
    exit;
}

$benutzer = DB::one("SELECT u.id FROM users u JOIN workspaces w ON w.id = u.workspace_id
                     WHERE w.demo = 1 AND u.rolle = 'owner' AND u.aktiv = 1 LIMIT 1");

if ($benutzer && Auth::demoAnmelden((int) $benutzer['id'])) {
    App::melden('Du siehst den Demo-Bereich. Änderungen sind möglich und bleiben erhalten.', 'info');
    App::weiter('/app/');
}
App::melden('Es ist kein Demo-Bereich eingerichtet.', 'fehler');
App::weiter('/login.php');
