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

/*
 * Eine Maske für beide: Golfpros landen in ihrer Instanz, Betreiber in
 * der Betreiberzentrale. Wohin, entscheidet die Tabelle, in der die
 * Adresse steht – nicht die Adresse selbst und keine Liste im Code.
 */
if (Auth::angemeldet()) {
    App::weiter('/app/');
}
if (Betreiber::angemeldet()) {
    App::weiter('/master/');
}

$fehler = '';
$email  = App::post('email');
$weiter = App::get('weiter', App::post('weiter'));

if (App::istPost()) {
    Auth::csrfFordern();
    $aktion = App::aktion();

    if ($aktion === 'reset_anfordern') {
        $adresse = strtolower(App::post('email'));
        $text = static fn (string $name, string $token): string => "Hallo " . $name . ",\n\n"
            . "du kannst dein Passwort über den folgenden Link neu setzen. Der Link ist eine Stunde gültig.\n\n"
            . App::absolut('/passwort.php?token=' . $token) . "\n\n"
            . "Wenn du das nicht warst, ignoriere diese Nachricht – dein Passwort bleibt unverändert.";
        /* 'protokoll' => false: Der Link ist ein Schlüssel. Im
           Kommunikationsverlauf der Instanz könnte ihn sonst jeder aus dem
           Team lesen – und damit das Konto des Inhabers übernehmen. */
        $token = Auth::resetAnfordern($adresse);
        if ($token !== null) {
            $benutzer = DB::one('SELECT * FROM users WHERE email = :e', ['e' => $adresse]);
            if ($benutzer) {
                Tenant::setzen((int) $benutzer['workspace_id']);
                Mail::senden((string) $benutzer['email'], 'Passwort zurücksetzen', $text((string) $benutzer['name'], $token),
                    ['knopf_text' => 'Neues Passwort setzen', 'protokoll' => false,
                     'knopf_url' => App::absolut('/passwort.php?token=' . $token)]);
            }
        }
        $token = Betreiber::resetAnfordern($adresse);
        if ($token !== null) {
            $betreiber = DB::one('SELECT name, email FROM betreiber WHERE email = :e', ['e' => $adresse]);
            Mail::senden((string) $betreiber['email'], 'Passwort zurücksetzen', $text((string) $betreiber['name'], $token),
                ['knopf_text' => 'Neues Passwort setzen', 'protokoll' => false,
                 'knopf_url' => App::absolut('/passwort.php?token=' . $token)]);
        }
        // Immer dieselbe Rückmeldung – sonst wird die Maske zur Adressprüfung.
        App::melden('Wenn es ein Konto mit dieser Adresse gibt, ist die E-Mail unterwegs.', 'info');
        App::weiter('/login.php');
    }

    $eigenerPfad = static fn (string $ziel): bool => $ziel !== ''
        && str_starts_with($ziel, '/')
        && !str_starts_with($ziel, '//')
        && !str_starts_with($ziel, '/\\')
        && !preg_match('/[\r\n]/', $ziel);

    $betreiber = Betreiber::anmelden($email, App::postRoh('passwort'));
    if ($betreiber === true) {
        if ($eigenerPfad($weiter) && str_contains($weiter, '/master/')) {
            header('Location: ' . $weiter);
            exit;
        }
        App::weiter('/master/');
    }
    [$ok, $meldung] = $betreiber === false
        ? [false, Auth::versuchGesperrt(strtolower(trim($email)))
            ? 'Zu viele Versuche. Bitte in 15 Minuten erneut probieren.'
            : 'E-Mail-Adresse oder Passwort stimmt nicht.']
        : Auth::anmelden($email, App::postRoh('passwort'));
    if ($ok) {
        /*
         * Wohin nach der Anmeldung?
         *
         * Ohne Ziel auf die Startseite der Anwendung – über App::url().
         * Vorher stand hier ein nacktes „/app/". In einer Anlage im
         * Unterordner schickte das auf example.de/app/ statt auf
         * example.de/procms/app/: Anmeldung erfolgreich, Seite nicht
         * gefunden. Wer sich anmeldet, landete im Nichts.
         *
         * Mit Ziel: `weiter` kommt aus Auth::fordern() und ist dort die
         * vollständige angefragte Adresse samt Installationspfad. Sie
         * wird unverändert übernommen und NICHT noch einmal durch
         * App::url() geschickt – das hänge die Basis ein zweites Mal
         * davor.
         *
         * Erlaubt ist nur ein Pfad dieser Anlage: genau ein Schrägstrich
         * am Anfang. „//fremd.de" liest der Browser als fremden Host,
         * „/\fremd.de" ebenso, weil er den umgekehrten Schrägstrich
         * geraderückt. Ungeprüft wäre die Anmeldemaske ein Sprungbrett
         * fürs Phishing: echte Seite, echtes Passwort, fremdes Ziel.
         */
        /* Ein Golfpro wird nie in die Betreiberzentrale weitergeleitet –
           dort erwartete ihn ohnehin nur die Seite „Keine Berechtigung". */
        $eigen = $eigenerPfad($weiter) && !str_contains($weiter, '/master/');
        if ($eigen) {
            header('Location: ' . $weiter);
            exit;
        }
        App::weiter('/app/');
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
<title>Anmelden · TeePilot</title>
<?php /* Schrift vom eigenen Server: keine Verbindung zu Google beim Anmelden. */ ?>
<link rel="stylesheet" href="<?= Util::attr(App::asset('assets/css/schriften.css')) ?>">
<link rel="stylesheet" href="<?= Util::attr(App::asset('assets/css/app.css')) ?>">
<script>(function(){try{var t=localStorage.getItem('gp-thema')||'system';
var d=t==='dunkel'||(t==='system'&&matchMedia('(prefers-color-scheme: dark)').matches);
document.documentElement.setAttribute('data-theme',d?'dunkel':'hell');}catch(e){}})();</script>
<style>
.anmeldung { min-height:100dvh; display:grid; grid-template-columns: 1fr 1fr; }
.anmeldung__form { display:grid; place-items:center; padding:var(--r6); }
.anmeldung__karte { width:100%; max-width:384px; }
/*
 * Kein Verlauf, kein Lichtschein. Die Bühne ist eine Fläche in Pine, auf
 * der zwei Bahnen liegen – dieselbe Geste wie im Zeichen. Alles andere
 * wäre Dekoration, und das Markenhandbuch verbietet sie ausdrücklich.
 */
.anmeldung__marke { display:flex; align-items:center; gap:10px; margin-bottom:var(--r8); }
.anmeldung__zeichen { color:var(--marke); display:grid; place-items:center; }
.anmeldung__buehne {
  background:var(--pine);
  color:#f6f5f0; padding:var(--r9); display:flex; flex-direction:column; justify-content:space-between;
  position:relative; overflow:hidden;
}
.anmeldung__bahnen { position:absolute; inset:0; width:100%; height:100%; pointer-events:none; }
.anmeldung__spruch { font-size:34px; font-weight:700; letter-spacing:-.035em; line-height:1.14; max-width:14em; position:relative; }
.anmeldung__spruch em { font-style:normal; color:var(--signal); }
.anmeldung__liste { list-style:none; padding:0; margin:var(--r6) 0 0; display:grid; gap:12px; max-width:26em; position:relative; }
.anmeldung__liste li { display:flex; gap:11px; align-items:flex-start; font-size:14.5px; color:#a8b5ae; line-height:1.55; }
.anmeldung__liste .ico { margin-top:2px; color:var(--signal); }
.anmeldung__fuss { font-family:var(--mono); font-size:11.5px; color:#7d9c8c; position:relative; }
.anmeldung__oben { position:relative; display:flex; align-items:center; gap:11px; color:var(--signal); }
.anmeldung__oben b { font-size:19px; font-weight:700; letter-spacing:-.035em; color:#f6f5f0; }
@media (max-width: 900px) { .anmeldung { grid-template-columns:1fr; } .anmeldung__buehne { display:none; } }
</style>
</head>
<body>
<div class="anmeldung">
  <div class="anmeldung__form">
    <div class="anmeldung__karte">
      <div class="anmeldung__marke">
        <span class="anmeldung__zeichen"><?= Marke::zeichen(32) ?></span>
        <div>
          <div style="font-weight:700;letter-spacing:-.035em;font-size:19px"><?= Marke::NAME ?></div>
          <div class="klein gedimmt" style="margin-top:-2px"><?= Util::h(Marke::CLAIM_DE) ?></div>
        </div>
      </div>

      <h1 style="margin-bottom:6px">Anmelden</h1>
      <p class="gedimmt mb-5">Weiter zu deinem Arbeitsbereich.</p>

      <?php foreach ($meldungen as $m): ?>
        <div class="hinweis hinweis--<?= ['info' => 'still', 'fehler' => 'gefahr', 'warnung' => 'warnung'][$m['typ']] ?? 'erfolg' ?> mb-4">
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
    <?php /* Zwei Bahnen, angeschnitten, sehr schwach – die Geste des Zeichens. */ ?>
    <svg class="anmeldung__bahnen" viewBox="0 0 720 900" fill="none" aria-hidden="true" preserveAspectRatio="xMidYMid slice">
      <path d="M-40 840C120 760 320 600 520 380C600 292 660 214 720 140" stroke="#164537" stroke-width="1.5"/>
      <path d="M-40 940C140 850 360 676 570 444C650 356 700 280 760 210" stroke="#164537" stroke-width="1.5"/>
      <circle cx="520" cy="380" r="4.5" fill="#c3e35c"/>
    </svg>
    <div class="anmeldung__oben">
      <?= Marke::zeichen(26) ?><b><?= Marke::NAME ?></b>
    </div>
    <div style="position:relative">
      <div class="anmeldung__spruch">Deine Website, deine Kunden, <em>dein ganzes Golf-Business.</em></div>
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
      <?= Marke::NAME ?> <?= Util::h(GP_VERSION) ?> · Gebaut für Golf Professionals, Coaches und Academies.
    </div>
  </div>
</div>
<script src="<?= Util::attr(App::asset('assets/js/app.js')) ?>"></script>
</body>
</html>
