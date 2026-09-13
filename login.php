<?php
/**
 * Anmeldung.
 *
 * Eine Seite, die man selten sieht und deshalb gern vernachlässigt – zu
 * Unrecht: Sie ist der erste Eindruck und der einzige Ort, an dem ein
 * Fehler den Zugang ganz verhindert. Deshalb: eine Meldung für beide
 * Fehlerarten (sonst verrät die Maske, welche Adressen es gibt), eine
 * Bremse nach acht Fehlversuchen, und ein Weg zurück über die E-Mail.
 */
require __DIR__ . '/lib/bootstrap.php';

if (Auth::angemeldet()) {
    App::weiter('/app/');
}

$fehler = '';
$email  = App::post('email');
$weiter = App::get('weiter', App::post('weiter'));

if (App::istPost()) {
    Auth::csrfFordern();
    $aktion = App::aktion();

    if ($aktion === 'reset_anfordern') {
        $token = Auth::resetAnfordern(App::post('email'));
        if ($token !== null) {
            $benutzer = DB::one('SELECT * FROM users WHERE email = :e', ['e' => strtolower(App::post('email'))]);
            if ($benutzer) {
                Tenant::setzen((int) $benutzer['workspace_id']);
                Mail::senden((string) $benutzer['email'], 'Passwort zurücksetzen',
                    "Hallo " . $benutzer['name'] . ",\n\n"
                    . "du kannst dein Passwort über den folgenden Link neu setzen. Der Link ist eine Stunde gültig.\n\n"
                    . App::absolut('/passwort.php?token=' . $token) . "\n\n"
                    . "Wenn du das nicht warst, ignoriere diese Nachricht – dein Passwort bleibt unverändert.",
                    ['knopf_text' => 'Neues Passwort setzen',
                     'knopf_url' => App::absolut('/passwort.php?token=' . $token)]);
            }
        }
        // Immer dieselbe Rückmeldung – sonst wird die Maske zur Adressprüfung.
        App::melden('Wenn es ein Konto mit dieser Adresse gibt, ist die E-Mail unterwegs.', 'info');
        App::weiter('/login.php');
    }

    [$ok, $meldung] = Auth::anmelden($email, App::postRoh('passwort'));
    if ($ok) {
        $ziel = $weiter !== '' && str_starts_with($weiter, '/') ? $weiter : '/app/';
        header('Location: ' . $ziel);
        exit;
    }
    $fehler = $meldung;
}

$demoBenutzer = null;
try {
    $demoBenutzer = DB::one("SELECT u.id, u.name, u.email FROM users u
                             JOIN workspaces w ON w.id = u.workspace_id
                             WHERE w.demo = 1 AND u.rolle = 'owner' LIMIT 1");
} catch (Throwable $e) {
    $demoBenutzer = null;
}
$meldungen = App::meldungen();
?>
<!DOCTYPE html>
<html lang="de" data-theme="hell">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Anmelden · GolfPro CMS</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= Util::attr(App::asset('assets/css/app.css')) ?>">
<script>(function(){try{var t=localStorage.getItem('gp-thema')||'system';
var d=t==='dunkel'||(t==='system'&&matchMedia('(prefers-color-scheme: dark)').matches);
document.documentElement.setAttribute('data-theme',d?'dunkel':'hell');}catch(e){}})();</script>
<style>
.anmeldung { min-height:100dvh; display:grid; grid-template-columns: 1fr 1fr; }
.anmeldung__form { display:grid; place-items:center; padding:var(--r6); }
.anmeldung__karte { width:100%; max-width:384px; }
.anmeldung__marke { display:flex; align-items:center; gap:10px; margin-bottom:var(--r8); }
.anmeldung__zeichen { width:34px;height:34px;border-radius:10px;
  background:linear-gradient(140deg,var(--marke),color-mix(in srgb,var(--marke) 60%,#000));
  color:#fff;display:grid;place-items:center;font-weight:700;font-size:15px; }
.anmeldung__buehne {
  background:linear-gradient(160deg, color-mix(in srgb,var(--marke) 96%,#000), color-mix(in srgb,var(--marke) 55%,#000));
  color:#fff; padding:var(--r9); display:flex; flex-direction:column; justify-content:space-between;
  position:relative; overflow:hidden;
}
.anmeldung__buehne::after {
  content:""; position:absolute; inset:0;
  background:radial-gradient(1000px 520px at 78% 8%, rgba(255,255,255,.13), transparent 62%);
  pointer-events:none;
}
.anmeldung__spruch { font-size:30px; font-weight:640; letter-spacing:-.028em; line-height:1.22; max-width:15em; }
.anmeldung__liste { list-style:none; padding:0; margin:var(--r6) 0 0; display:grid; gap:12px; max-width:26em; }
.anmeldung__liste li { display:flex; gap:11px; align-items:flex-start; font-size:14.5px; opacity:.93; line-height:1.5; }
.anmeldung__liste .ico { margin-top:2px; opacity:.72; }
.anmeldung__fuss { font-size:12.5px; opacity:.7; position:relative; }
@media (max-width: 900px) { .anmeldung { grid-template-columns:1fr; } .anmeldung__buehne { display:none; } }
</style>
</head>
<body>
<div class="anmeldung">
  <div class="anmeldung__form">
    <div class="anmeldung__karte">
      <div class="anmeldung__marke">
        <span class="anmeldung__zeichen">G</span>
        <div>
          <div style="font-weight:640;letter-spacing:-.02em">GolfPro CMS</div>
          <div class="klein gedimmt" style="margin-top:-2px">Dein digitales Golf-Business</div>
        </div>
      </div>

      <h1 style="margin-bottom:6px">Anmelden</h1>
      <p class="gedimmt mb-5">Weiter zu deinem Arbeitsbereich.</p>

      <?php foreach ($meldungen as $m): ?>
        <div class="hinweis hinweis--<?= $m['typ'] === 'info' ? 'still' : 'erfolg' ?> mb-4">
          <?= Icon::svg('info', 17) ?><div class="hinweis__text"><?= Util::h($m['text']) ?></div>
        </div>
      <?php endforeach; ?>

      <?php if ($fehler !== ''): ?>
        <div class="hinweis hinweis--gefahr mb-4">
          <?= Icon::svg('alert', 17) ?><div class="hinweis__text"><?= Util::h($fehler) ?></div>
        </div>
      <?php endif; ?>

      <form method="post" action="<?= Util::attr(App::url('/login.php')) ?>">
        <?= Auth::csrfFeld() ?>
        <input type="hidden" name="weiter" value="<?= Util::attr($weiter) ?>">
        <div class="feld">
          <label class="feld__label" for="email">E-Mail-Adresse</label>
          <input class="eingabe" id="email" type="email" name="email" required autofocus
                 autocomplete="username" value="<?= Util::attr($email) ?>">
        </div>
        <div class="feld">
          <label class="feld__label" for="passwort">Passwort</label>
          <input class="eingabe" id="passwort" type="password" name="passwort" required
                 autocomplete="current-password">
        </div>
        <button class="btn btn--primaer btn--gross btn--voll" type="submit">Anmelden</button>
      </form>

      <div class="trenner-text">oder</div>

      <?php if ($demoBenutzer): ?>
        <form method="post" action="<?= Util::attr(App::url('/demo.php')) ?>">
          <?= Auth::csrfFeld() ?>
          <button class="btn btn--voll" type="submit">
            <?= Icon::svg('play', 15) ?> Demo ansehen
          </button>
          <p class="klein gedimmt mitte mt-2" style="margin-bottom:0">
            Vollständig eingerichtete Akademie mit echten Daten – ohne Anmeldung.
          </p>
        </form>
      <?php else: ?>
        <p class="klein gedimmt mitte">Noch kein Zugang? Der Inhaber legt dich unter
          <span class="halbfett">Einstellungen → Team</span> an.</p>
      <?php endif; ?>

      <details class="mt-5">
        <summary class="klein gedimmt" style="cursor:pointer">Passwort vergessen?</summary>
        <form method="post" action="<?= Util::attr(App::url('/login.php')) ?>" class="mt-3">
          <?= Auth::csrfFeld() ?>
          <input type="hidden" name="aktion" value="reset_anfordern">
          <div class="feld">
            <input class="eingabe" type="email" name="email" required placeholder="deine@adresse.de"
                   aria-label="E-Mail-Adresse für das Zurücksetzen">
          </div>
          <button class="btn btn--voll" type="submit">Link zum Zurücksetzen schicken</button>
        </form>
      </details>
    </div>
  </div>

  <div class="anmeldung__buehne">
    <div style="position:relative">
      <div class="versal" style="opacity:.62;margin-bottom:var(--r4)">Ein System statt sieben Werkzeuge</div>
      <div class="anmeldung__spruch">Deine Website, deine Kunden, dein ganzes Golf-Business.</div>
      <ul class="anmeldung__liste">
        <li><?= Icon::svg('check', 17) ?><span>Website und Online-Buchung, die zusammenarbeiten –
            der Termin landet direkt im Kalender.</span></li>
        <li><?= Icon::svg('check', 17) ?><span>Kundenakte mit Handicap, Trainingshistorie, Videos,
            Paketen und Zahlungen an einem Ort.</span></li>
        <li><?= Icon::svg('check', 17) ?><span>Rechnungen, Pakete und Gutscheine ohne zweite
            Software und ohne Tabellenkalkulation.</span></li>
        <li><?= Icon::svg('check', 17) ?><span>Empfehlungen aus deinen eigenen Zahlen: wen du
            heute anrufen solltest und warum.</span></li>
      </ul>
    </div>
    <div class="anmeldung__fuss">
      GolfPro CMS <?= Util::h(GP_VERSION) ?> · Gebaut für Golf Professionals, Coaches und Academies.
    </div>
  </div>
</div>
<script src="<?= Util::attr(App::asset('assets/js/app.js')) ?>"></script>
</body>
</html>
