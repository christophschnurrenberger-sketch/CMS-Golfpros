<?php
/**
 * Installer.
 *
 * Läuft ohne Kommandozeile, ohne Composer und ohne Node: hochladen,
 * aufrufen, drei Felder ausfüllen. Wer keine eigene Datenbank hat, nimmt
 * SQLite – dann reicht ein beschreibbarer Ordner.
 *
 * Nach erfolgreicher Einrichtung verweigert diese Datei den Dienst, solange
 * eine config.php existiert. Sie sollte trotzdem gelöscht werden; darauf
 * weist der letzte Schritt hin.
 */
/*
 * bootstrap.php kennt den Fall „noch keine config.php": Es registriert den
 * Autoloader, lädt die (leere) Konfiguration und kehrt zurück, ohne die
 * Datenbank anzufassen. Genau das braucht der Installer.
 */
require __DIR__ . '/lib/bootstrap.php';
if (Config::installed() && !isset($_GET['weiter'])) {
    fertigSeite('Bereits eingerichtet',
        'Es gibt schon eine config.php. Wenn du wirklich neu einrichten willst, lösche sie zuerst '
      . 'vom Server – solange sie existiert, tut dieser Installer nichts.',
        [['Zur Anmeldung', 'login.php']]);
}

$fehler  = [];
$meldung = '';

/* ------------------------------------------------------------ Prüfung --- */

$pruefungen = [
    ['PHP ab 8.1', PHP_VERSION_ID >= 80100, PHP_VERSION],
    ['PDO vorhanden', extension_loaded('pdo'), extension_loaded('pdo') ? 'ja' : 'fehlt'],
    ['SQLite-Treiber', extension_loaded('pdo_sqlite'), extension_loaded('pdo_sqlite') ? 'ja' : 'fehlt'],
    ['MySQL-Treiber', extension_loaded('pdo_mysql'), extension_loaded('pdo_mysql') ? 'ja' : 'nicht nötig für SQLite'],
    ['mbstring', extension_loaded('mbstring'), extension_loaded('mbstring') ? 'ja' : 'fehlt'],
    ['Ordner beschreibbar', is_writable(__DIR__), is_writable(__DIR__) ? 'ja' : 'nein'],
    ['data/ beschreibbar', is_dir(__DIR__ . '/data') ? is_writable(__DIR__ . '/data') : is_writable(__DIR__),
        is_dir(__DIR__ . '/data') ? (is_writable(__DIR__ . '/data') ? 'ja' : 'nein') : 'wird angelegt'],
    ['uploads/ beschreibbar', is_dir(__DIR__ . '/uploads') ? is_writable(__DIR__ . '/uploads') : is_writable(__DIR__),
        is_dir(__DIR__ . '/uploads') ? (is_writable(__DIR__ . '/uploads') ? 'ja' : 'nein') : 'wird angelegt'],
];
$pflicht = ['PHP ab 8.1', 'PDO vorhanden', 'mbstring', 'Ordner beschreibbar'];
$bereit  = true;
foreach ($pruefungen as [$name, $ok, $wert]) {
    if (in_array($name, $pflicht, true) && !$ok) {
        $bereit = false;
    }
}

/* ---------------------------------------------------------- Einrichten --- */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $bereit) {
    $treiber = ($_POST['treiber'] ?? 'sqlite') === 'mysql' ? 'mysql' : 'sqlite';
    $name    = trim((string) ($_POST['workspace'] ?? ''));
    $email   = strtolower(trim((string) ($_POST['email'] ?? '')));
    $pass    = (string) ($_POST['passwort'] ?? '');
    $demo    = !empty($_POST['demo']);

    if ($name === '')                                  { $fehler[] = 'Der Betrieb braucht einen Namen.'; }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))    { $fehler[] = 'Die E-Mail-Adresse sieht nicht richtig aus.'; }
    [$passOk, $passMeldung] = Auth::passwortPruefen($pass);
    if (!$passOk)                                      { $fehler[] = $passMeldung; }

    $dbCfg = ['driver' => 'sqlite', 'path' => __DIR__ . '/data/golfpro.sqlite'];
    if ($treiber === 'mysql') {
        $dbCfg = [
            'driver' => 'mysql',
            'host' => trim((string) ($_POST['host'] ?? 'localhost')),
            'port' => (int) ($_POST['port'] ?? 3306),
            'name' => trim((string) ($_POST['dbname'] ?? '')),
            'user' => trim((string) ($_POST['dbuser'] ?? '')),
            'pass' => (string) ($_POST['dbpass'] ?? ''),
        ];
        if ($dbCfg['name'] === '') {
            $fehler[] = 'Ohne Datenbanknamen geht es nicht.';
        }
    }

    if ($fehler === []) {
        foreach (['data', 'uploads', 'uploads/bilder', 'uploads/videos', 'uploads/dokumente'] as $ordner) {
            if (!is_dir(__DIR__ . '/' . $ordner)) {
                @mkdir(__DIR__ . '/' . $ordner, 0750, true);
            }
            // Verzeichnislisten verhindern, falls der Server sie erlaubt.
            if (!is_file(__DIR__ . '/' . $ordner . '/index.html')) {
                @file_put_contents(__DIR__ . '/' . $ordner . '/index.html', '<!-- nichts zu sehen -->');
            }
        }

        try {
            DB::init($dbCfg);
            Schema::migrate();
        } catch (Throwable $e) {
            $fehler[] = 'Die Datenbank antwortet nicht: ' . $e->getMessage();
        }
    }

    if ($fehler === []) {
        $basis = rtrim((string) ($_POST['base_url'] ?? ''), '/');
        if ($basis === '') {
            $schema = !empty($_SERVER['HTTPS']) ? 'https' : 'http';
            $pfad   = rtrim(str_replace('\\', '/', dirname((string) $_SERVER['SCRIPT_NAME'])), '/');
            $basis  = $schema . '://' . (string) ($_SERVER['HTTP_HOST'] ?? 'localhost') . $pfad;
        }

        $konfig = [
            'db'       => $dbCfg,
            'secret'   => bin2hex(random_bytes(32)),
            'base_url' => $basis,
            /* Der Zugang ohne Passwort bleibt aus. Wer eine öffentliche
               Vorführung betreibt, setzt ihn von Hand auf true. */
            'demo_zugang' => false,
            'mail'     => [
                'from_name'  => $name,
                'from_email' => $email,
                'transport'  => 'mail',
                'smtp'       => ['host' => '', 'port' => 587, 'user' => '', 'pass' => '', 'secure' => 'tls'],
            ],
            'stripe'   => ['public_key' => '', 'secret_key' => '', 'webhook_secret' => ''],
            'ai'       => ['provider' => 'anthropic', 'api_key' => '', 'model' => 'claude-sonnet-5'],
            'debug'    => false,
        ];

        if (!Config::write($konfig)) {
            $fehler[] = 'Die config.php lässt sich nicht schreiben. Bitte die Schreibrechte des '
                      . 'Hauptordners prüfen (meist 755) und noch einmal versuchen.';
        }
    }

    if ($fehler === []) {
        try {
            /* Der eigene Workspace. */
            $wsId = DB::insert('workspaces', [
                'slug'     => Util::slug($name) ?: 'workspace',
                'name'     => $name,
                'typ'      => 'pro',
                'plan'     => 'academy',      // ohne Abrechnung: alles sichtbar, Module regeln den Rest
                'branding' => Util::json(['primaer' => '#2e6a3e', 'akzent' => '#f2c64b',
                                          'schrift' => 'Archivo', 'radius' => 14, 'stil' => 'modern']),
                'waehrung' => 'EUR', 'sprache' => 'de', 'zeitzone' => 'Europe/Berlin',
                'onboarding_schritt' => 0, 'aktiv' => 1, 'demo' => 0,
                'erstellt' => date('Y-m-d H:i:s'),
            ]);
            Tenant::setzen($wsId);
            DB::insert('users', [
                'workspace_id' => $wsId,
                'email'    => $email,
                'passwort' => Auth::hash($pass),
                'name'     => (string) ($_POST['person'] ?? '') ?: $name,
                'rolle'    => 'owner',
                'farbe'    => '#1d6f4a',
                'aktiv'    => 1,
                'erstellt' => date('Y-m-d H:i:s'),
            ]);
            Tenant::einstellungSetzen('module', Module::standardFuerPlan('academy'));
            Tenant::einstellungSetzen('mail_absender', $email);
            Tenant::einstellungSetzen('mail_absender_name', $name);
            Segments::standardAnlegen();

            /* Der Demo-Workspace ist optional und völlig getrennt. */
            if ($demo) {
                Demo::anlegen();
                Tenant::setzen($wsId);
            }

            $meldung = 'fertig';
        } catch (Throwable $e) {
            $fehler[] = 'Beim Anlegen ist etwas schiefgegangen: ' . $e->getMessage();
            @unlink(__DIR__ . '/config.php');   // halbe Installation ist schlimmer als keine
        }
    }
}

if ($meldung === 'fertig') {
    fertigSeite('Fertig eingerichtet',
        'Melde dich jetzt mit deiner E-Mail und deinem Passwort an. Der nächste Schritt ist die '
      . 'geführte Einrichtung – an ihrem Ende steht eine veröffentlichte Website.',
        [['Zur Anmeldung', 'login.php']],
        'Lösche install.php vom Server. Solange die Datei liegen bleibt, ist sie eine '
      . 'unnötige Angriffsfläche – auch wenn sie sich jetzt selbst sperrt.');
}

/* -------------------------------------------------------- Darstellung --- */

function fertigSeite(string $titel, string $text, array $knoepfe, string $warnung = ''): never
{
    rahmenAuf($titel);
    echo '<p class="text">' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</p>';
    if ($warnung !== '') {
        echo '<div class="warnung">' . htmlspecialchars($warnung, ENT_QUOTES, 'UTF-8') . '</div>';
    }
    echo '<div class="knoepfe">';
    foreach ($knoepfe as [$beschriftung, $ziel]) {
        echo '<a class="knopf" href="' . htmlspecialchars($ziel, ENT_QUOTES, 'UTF-8') . '">'
           . htmlspecialchars($beschriftung, ENT_QUOTES, 'UTF-8') . '</a>';
    }
    echo '</div>';
    rahmenZu();
    exit;
}

function rahmenAuf(string $titel): void
{
    ?><!DOCTYPE html>
<html lang="de"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= htmlspecialchars($titel, ENT_QUOTES, 'UTF-8') ?> · GolfPro CMS</title>
<style>
/* Eigenständige Gestaltung: Der Installer läuft, bevor es Branding gibt. */
*{box-sizing:border-box}
body{margin:0;background:#f6f7f6;color:#16191a;
  font:15px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif}
.huelle{max-width:620px;margin:0 auto;padding:44px 20px 70px}
.marke{display:flex;align-items:center;gap:11px;margin-bottom:26px}
.marke span{width:36px;height:36px;border-radius:10px;background:#1d6f4a;color:#fff;
  display:grid;place-items:center;font-weight:700}
.marke b{font-size:16px}
h1{font-size:23px;letter-spacing:-.02em;margin:0 0 8px}
.text{color:#5b6360;margin:0 0 22px}
.karte{background:#fff;border:1px solid #e3e6e4;border-radius:14px;padding:22px;margin-bottom:18px}
.karte h2{font-size:14px;margin:0 0 14px;text-transform:uppercase;letter-spacing:.06em;color:#78817d}
label{display:block;font-size:13px;font-weight:600;margin:0 0 5px}
.hinweis{font-size:12px;color:#78817d;margin-top:5px}
input[type=text],input[type=email],input[type=password],input[type=number],select{
  width:100%;padding:9px 11px;border:1px solid #d4d9d6;border-radius:9px;font:inherit;background:#fff}
input:focus,select:focus{outline:2px solid #1d6f4a33;border-color:#1d6f4a}
.feld{margin-bottom:15px}
.paar{display:grid;grid-template-columns:1fr 1fr;gap:13px}
.zeile{display:flex;justify-content:space-between;gap:12px;padding:7px 0;border-bottom:1px solid #eef0ef;font-size:13.5px}
.zeile:last-child{border-bottom:0}
.ja{color:#17794f;font-weight:600}.nein{color:#b4242b;font-weight:600}.egal{color:#78817d}
.knopf{display:inline-block;background:#1d6f4a;color:#fff;text-decoration:none;border:0;
  padding:11px 20px;border-radius:9px;font:inherit;font-weight:600;cursor:pointer}
.knopf:hover{background:#175c3d}
.knoepfe{display:flex;gap:10px;margin-top:6px}
.warnung{background:#fdf6e6;border:1px solid #f0dfae;color:#7a5c12;padding:13px 15px;
  border-radius:10px;font-size:13.5px;margin-bottom:18px}
.fehler{background:#fdeceb;border:1px solid #f2c3c0;color:#8f2721;padding:13px 15px;
  border-radius:10px;font-size:13.5px;margin-bottom:18px}
.fehler ul{margin:6px 0 0;padding-left:18px}
.haken{display:flex;gap:9px;align-items:flex-start;font-size:13.5px;font-weight:400}
.haken input{margin-top:3px}
@media(max-width:540px){.paar{grid-template-columns:1fr}}
</style></head><body><div class="huelle">
<div class="marke"><span>G</span><b>GolfPro CMS</b></div>
<h1><?= htmlspecialchars($titel, ENT_QUOTES, 'UTF-8') ?></h1>
<?php
}

function rahmenZu(): void
{
    echo '</div></body></html>';
}

rahmenAuf('Einrichtung');
?>
<p class="text">Drei Angaben, dann läuft das System. Alles Weitere lässt sich später ändern.</p>

<?php if ($fehler !== []): ?>
  <div class="fehler"><strong>Das hat noch nicht geklappt:</strong>
    <ul><?php foreach ($fehler as $f): ?><li><?= htmlspecialchars($f, ENT_QUOTES, 'UTF-8') ?></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>

<div class="karte">
  <h2>Server</h2>
  <?php foreach ($pruefungen as [$name, $ok, $wert]):
    $klasse = $ok ? 'ja' : (in_array($name, $pflicht, true) ? 'nein' : 'egal'); ?>
    <div class="zeile"><span><?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?></span>
      <span class="<?= $klasse ?>"><?= htmlspecialchars((string) $wert, ENT_QUOTES, 'UTF-8') ?></span></div>
  <?php endforeach; ?>
</div>

<?php if (!$bereit): ?>
  <div class="warnung">Es fehlt noch etwas auf dem Server. Die rot markierten Punkte müssen
    stimmen, sonst läuft die Installation nicht. Bei den meisten Hostern lässt sich die
    PHP-Version im Kundenbereich umstellen.</div>
<?php else: ?>

<form method="post">
  <div class="karte">
    <h2>Datenbank</h2>
    <div class="feld">
      <label for="treiber">Art</label>
      <select id="treiber" name="treiber" onchange="document.getElementById('mysql').style.display=this.value==='mysql'?'block':'none'">
        <option value="sqlite">SQLite – empfohlen, braucht keine Einrichtung</option>
        <option value="mysql">MySQL / MariaDB</option>
      </select>
      <div class="hinweis">SQLite legt eine Datei unter data/ an. Das reicht für einen Betrieb
        mit einigen tausend Kunden vollkommen aus.</div>
    </div>
    <div id="mysql" style="display:none">
      <div class="paar">
        <div class="feld"><label for="host">Server</label>
          <input type="text" id="host" name="host" value="localhost"></div>
        <div class="feld"><label for="port">Port</label>
          <input type="number" id="port" name="port" value="3306"></div>
      </div>
      <div class="feld"><label for="dbname">Datenbank</label>
        <input type="text" id="dbname" name="dbname"></div>
      <div class="paar">
        <div class="feld"><label for="dbuser">Benutzer</label>
          <input type="text" id="dbuser" name="dbuser"></div>
        <div class="feld"><label for="dbpass">Passwort</label>
          <input type="password" id="dbpass" name="dbpass"></div>
      </div>
    </div>
  </div>

  <div class="karte">
    <h2>Dein Zugang</h2>
    <div class="feld"><label for="workspace">Name deines Betriebs</label>
      <input type="text" id="workspace" name="workspace" required placeholder="Golfschule Mustermann"
             value="<?= htmlspecialchars((string) ($_POST['workspace'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></div>
    <div class="feld"><label for="person">Dein Name</label>
      <input type="text" id="person" name="person" placeholder="Max Mustermann"
             value="<?= htmlspecialchars((string) ($_POST['person'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></div>
    <div class="paar">
      <div class="feld"><label for="email">E-Mail</label>
        <input type="email" id="email" name="email" required
               value="<?= htmlspecialchars((string) ($_POST['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></div>
      <div class="feld"><label for="passwort">Passwort</label>
        <input type="password" id="passwort" name="passwort" required autocomplete="new-password">
        <div class="hinweis">Mindestens zehn Zeichen.</div></div>
    </div>
    <label class="haken">
      <input type="checkbox" name="demo" value="1">
      <span>Demo-Bereich mitanlegen
        <span class="hinweis">Ein zweiter, vollständig getrennter Workspace mit 40 Kunden,
          Terminen, Rechnungen und einer fertigen Website – gut, um alles auszuprobieren,
          ohne die eigenen Daten anzufassen. Später löschbar. Nicht vorausgewählt:
          Was nicht da ist, kann auch nicht offen stehen.</span></span>
    </label>
  </div>

  <button class="knopf" type="submit">Jetzt einrichten</button>
</form>
<?php endif; ?>

<?php rahmenZu(); ?>
