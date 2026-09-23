<?php
/**
 * Passwort setzen – über einen Link aus einer E-Mail.
 *
 * Drei Wege führen hierher: „Passwort vergessen" auf der Anmeldeseite,
 * die Einladung eines neuen Teammitglieds und die Einladung, die ein
 * Betreiber beim Anlegen einer Instanz verschickt. Alle drei tragen
 * denselben Token, und in der Datenbank steht nur dessen SHA-256-Abdruck.
 * Wer die Datenbank liest, kann damit nichts anfangen.
 *
 * Bis hierher gab es diese Seite nicht: Die Mails zeigten auf
 * /passwort.php bzw. /login.php?token=…, und beide Wege endeten im
 * Nichts. Wer eingeladen wurde, kam nie hinein.
 */
require __DIR__ . '/lib/bootstrap.php';

$token = App::get('token', App::post('token'));

/**
 * Wem gehört der Token? Benutzer einer Instanz oder Betreiber.
 *
 * @return array{art:string,id:int,name:string,workspace_id:int}|null
 */
function passwortZiel(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{32,128}$/', $token)) {
        return null;
    }
    $abdruck = hash('sha256', $token);
    $jetzt = Util::jetzt();
    $u = DB::one('SELECT id, name, workspace_id FROM users WHERE reset_token = :t AND reset_bis > :j AND aktiv = 1',
        ['t' => $abdruck, 'j' => $jetzt]);
    if ($u !== null) {
        return ['art' => 'benutzer', 'id' => (int) $u['id'], 'name' => (string) $u['name'],
                'workspace_id' => (int) $u['workspace_id']];
    }
    $b = DB::one('SELECT id, name FROM betreiber WHERE reset_token = :t AND reset_bis > :j AND aktiv = 1',
        ['t' => $abdruck, 'j' => $jetzt]);
    if ($b !== null) {
        return ['art' => 'betreiber', 'id' => (int) $b['id'], 'name' => (string) $b['name'], 'workspace_id' => 0];
    }
    return null;
}

$kennung  = 'ip:' . Util::ip();
$gesperrt = Auth::versuchGesperrt($kennung, 'token_falsch');
$ziel = $gesperrt ? null : passwortZiel($token);
if ($ziel === null && $token !== '' && !$gesperrt) {
    Auth::versuchMerken($kennung, 'token_falsch');
}

$fehler = '';
if (App::istPost() && $ziel !== null) {
    Auth::csrfFordern();
    $neu = App::postRoh('passwort');
    [$ok, $meldung] = Auth::passwortPruefen($neu);
    if (!$ok) {
        $fehler = $meldung;
    } elseif (!hash_equals($neu, App::postRoh('passwort2'))) {
        $fehler = 'Die beiden Eingaben stimmen nicht überein.';
    } else {
        $tabelle = $ziel['art'] === 'betreiber' ? 'betreiber' : 'users';
        DB::update($tabelle, ['passwort' => Auth::hash($neu), 'reset_token' => '', 'reset_bis' => null],
            'id = :id', ['id' => $ziel['id']]);
        if ($ziel['art'] === 'betreiber') {
            Betreiberlog::schreiben('OPERATOR_UPDATED', [
                'objekt' => 'betreiber', 'objekt_id' => $ziel['id'], 'betreiber_id' => $ziel['id'],
                'akteur' => $ziel['name'], 'beschreibung' => 'Passwort über Link gesetzt',
            ]);
        } else {
            Tenant::setzen($ziel['workspace_id']);
            DB::insert('audit_log', [
                'workspace_id' => $ziel['workspace_id'], 'user_id' => $ziel['id'], 'aktion' => 'geaendert',
                'objekt' => 'user', 'objekt_id' => $ziel['id'], 'beschreibung' => 'Passwort über Link gesetzt',
                'ip' => Util::ip(), 'erstellt' => Util::jetzt(),
            ]);
        }
        App::melden('Passwort gespeichert. Du kannst dich jetzt anmelden.', 'erfolg');
        App::weiter('/login.php');
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
<title>Passwort setzen · <?= Marke::NAME ?></title>
<link rel="stylesheet" href="<?= Util::attr(App::asset('assets/css/schriften.css')) ?>">
<link rel="stylesheet" href="<?= Util::attr(App::asset('assets/css/app.css')) ?>">
<link rel="icon" href="<?= Util::attr(Marke::favicon()) ?>">
<script>(function(){try{var t=localStorage.getItem('gp-thema')||'system';
var d=t==='dunkel'||(t==='system'&&matchMedia('(prefers-color-scheme: dark)').matches);
document.documentElement.setAttribute('data-theme',d?'dunkel':'hell');}catch(e){}})();</script>
</head>
<body>
<main class="schlicht-seite">
  <div class="schlicht-seite__karte">
    <div class="anmeldung-marke">
      <span class="anmeldung-marke__zeichen"><?= Marke::zeichen(28) ?></span>
      <b><?= Marke::NAME ?></b>
    </div>

    <?php if ($ziel === null): ?>
      <h1>Link nicht mehr gültig</h1>
      <p class="gedimmt"><?= $gesperrt
          ? 'Zu viele ungültige Links in kurzer Zeit. Bitte in 15 Minuten erneut versuchen.'
          : 'Dieser Link ist abgelaufen oder wurde schon benutzt. Auf der Anmeldeseite kannst du über '
            . '„Passwort vergessen?" einen neuen anfordern.' ?></p>
      <a class="btn btn--primaer" href="<?= Util::attr(App::url('/login.php')) ?>">Zur Anmeldung</a>
    <?php else: ?>
      <h1>Passwort vergeben</h1>
      <p class="gedimmt mb-5">Hallo <?= Util::h($ziel['name']) ?>. Wähle ein Passwort mit mindestens
        10 Zeichen – Buchstaben, Zahlen und am besten ein Sonderzeichen.</p>
      <?php if ($fehler !== ''): ?>
        <div class="hinweis hinweis--gefahr mb-4">
          <?= Icon::svg('alert', 17) ?><div class="hinweis__text"><?= Util::h($fehler) ?></div>
        </div>
      <?php endif; ?>
      <form method="post" action="<?= Util::attr(App::url('/passwort.php')) ?>">
        <?= Auth::csrfFeld() ?>
        <input type="hidden" name="token" value="<?= Util::attr($token) ?>">
        <div class="feld">
          <label class="feld__label" for="passwort">Neues Passwort</label>
          <input class="eingabe" id="passwort" type="password" name="passwort" required minlength="10"
                 autocomplete="new-password" autofocus>
        </div>
        <div class="feld">
          <label class="feld__label" for="passwort2">Noch einmal</label>
          <input class="eingabe" id="passwort2" type="password" name="passwort2" required minlength="10"
                 autocomplete="new-password">
        </div>
        <button class="btn btn--primaer btn--gross btn--voll" type="submit">Passwort speichern</button>
      </form>
    <?php endif; ?>
  </div>
</main>
</body>
</html>
