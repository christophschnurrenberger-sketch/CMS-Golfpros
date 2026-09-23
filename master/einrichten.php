<?php
/**
 * Der erste Master Admin – für Anlagen ohne Kommandozeile.
 *
 * Wer per SSH arbeitet, nimmt `php bin/betreiber.php anlegen …`. Auf
 * reinem FTP-Hosting gibt es keine Kommandozeile; dafür diese Seite, und
 * sie ist mit drei Riegeln versehen:
 *
 *   1. Sie tut nur etwas, solange es noch KEINEN Betreiber gibt. Danach
 *      antwortet sie mit 404, für immer.
 *   2. Sie braucht einen Schlüssel, der in der config.php steht
 *      ('betreiber_einrichtung'). Wer ihn eintragen kann, hat ohnehin
 *      Zugriff auf die Dateien der Anlage.
 *   3. Der Schlüssel wird zeitkonstant verglichen, Fehlversuche laufen
 *      über dieselbe Bremse wie die Anmeldung.
 *
 * Es gibt also keine Seite, auf der sich jemand selbst zum Master Admin
 * machen kann: nur einen einmaligen Einrichtungsschritt, den ausschließlich
 * der Betreiber des Servers freischalten kann – so wie install.php.
 */
require __DIR__ . '/../lib/bootstrap.php';

$schluessel = trim((string) Config::get('betreiber_einrichtung', ''));
$kennung = 'ip:' . Util::ip();

function nichtDa(): never
{
    http_response_code(404);
    exit('Nicht gefunden.');
}

if ($schluessel === '' || strlen($schluessel) < 24 || Betreiber::anzahl() > 0) {
    nichtDa();
}
if (Auth::versuchGesperrt($kennung, 'einrichtung_falsch')) {
    http_response_code(429);
    exit('Zu viele Versuche. Bitte in 15 Minuten erneut.');
}
$k = App::get('k', App::post('k'));
if (!hash_equals($schluessel, $k)) {
    Auth::versuchMerken($kennung, 'einrichtung_falsch');
    nichtDa();
}

$fehler = '';
$fertig = null;
if (App::istPost()) {
    Auth::csrfFordern();
    [$id, $token, $fehler] = Betreiber::anlegen(App::post('email'), App::post('name'), 'master_admin', 'Einrichtung');
    if ($id > 0) {
        $fertig = App::absolut('/passwort.php?token=' . $token);
    }
}
?>
<!DOCTYPE html>
<html lang="de" data-theme="hell">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="referrer" content="no-referrer">
<title>Betreiberzentrale einrichten · <?= Marke::NAME ?></title>
<link rel="stylesheet" href="<?= Util::attr(App::asset('assets/css/schriften.css')) ?>">
<link rel="stylesheet" href="<?= Util::attr(App::asset('assets/css/app.css')) ?>">
<link rel="icon" href="<?= Util::attr(Marke::favicon()) ?>">
</head>
<body>
<main class="schlicht-seite">
  <div class="schlicht-seite__karte">
    <div class="anmeldung-marke">
      <span class="anmeldung-marke__zeichen"><?= Marke::zeichen(28) ?></span>
      <b><?= Marke::NAME ?></b> <span class="master-marke">Master</span>
    </div>
    <?php if ($fertig !== null): ?>
      <h1>Angelegt</h1>
      <p class="gedimmt">Über diesen Link vergibst du dein Passwort. Er gilt sieben Tage und nur einmal.</p>
      <p><a class="btn btn--primaer" href="<?= Util::attr($fertig) ?>">Passwort vergeben</a></p>
      <div class="hinweis hinweis--warnung mt-4">
        <?= Icon::svg('alert', 17) ?>
        <div class="hinweis__text">Entferne jetzt <code>betreiber_einrichtung</code> aus der config.php.
          Die Seite ist ab sofort ohnehin gesperrt – der Eintrag hat aber keinen Zweck mehr.</div>
      </div>
    <?php else: ?>
      <h1>Ersten Master Admin anlegen</h1>
      <p class="gedimmt mb-5">Dieser Schritt geht genau einmal. Weitere Betreiber legst du danach in der
        Betreiberzentrale unter Einstellungen an.</p>
      <?php if ($fehler !== ''): ?>
        <div class="hinweis hinweis--gefahr mb-4"><?= Icon::svg('alert', 17) ?>
          <div class="hinweis__text"><?= Util::h($fehler) ?></div></div>
      <?php endif; ?>
      <form method="post" action="<?= Util::attr(App::url('/master/einrichten.php')) ?>">
        <?= Auth::csrfFeld() ?>
        <input type="hidden" name="k" value="<?= Util::attr($k) ?>">
        <div class="feld"><label class="feld__label" for="e-name">Name</label>
          <input class="eingabe" id="e-name" name="name" required value="<?= Util::attr(App::post('name')) ?>"></div>
        <div class="feld"><label class="feld__label" for="e-email">E-Mail-Adresse</label>
          <input class="eingabe" id="e-email" type="email" name="email" required value="<?= Util::attr(App::post('email')) ?>">
          <div class="feld__hinweis">Eine eigene Adresse – nicht die eines Zugangs in einer Instanz.</div></div>
        <button class="btn btn--primaer btn--voll" type="submit">Anlegen</button>
      </form>
    <?php endif; ?>
  </div>
</main>
</body>
</html>
