<?php
/**
 * Systemprüfung.
 *
 * Beantwortet die Frage, die bei jedem Umzug und jedem Hosterwechsel als
 * erstes kommt: „Läuft hier alles?" Sichtbar ist sie nur für angemeldete
 * Inhaber – oder solange es noch keine config.php gibt, denn dann hilft
 * sie beim Einrichten.
 */
require __DIR__ . '/lib/bootstrap.php';

$vorInstallation = !Config::installed();
if (!$vorInstallation && !(Auth::angemeldet() && Auth::istInhaber())) {
    http_response_code(403);
    exit('Diese Seite ist nur für Inhaber sichtbar.');
}

/** @return array{0:string,1:bool|null,2:string,3:string} Name, Zustand (null = Hinweis), Wert, Rat */
function pruefen(string $name, $zustand, string $wert, string $rat = ''): array
{
    return [$name, $zustand, $wert, $rat];
}

$gruppen = [];

/* ------------------------------------------------------------- PHP ---- */

$gruppen['PHP und Erweiterungen'] = [
    pruefen('PHP-Version', PHP_VERSION_ID >= 80100, PHP_VERSION,
        'Ab 8.1 aufwärts. Bei den meisten Hostern im Kundenbereich umstellbar.'),
    pruefen('PDO', extension_loaded('pdo'), extension_loaded('pdo') ? 'geladen' : 'fehlt',
        'Ohne PDO gibt es keinen Datenbankzugriff.'),
    pruefen('pdo_sqlite', extension_loaded('pdo_sqlite'), extension_loaded('pdo_sqlite') ? 'geladen' : 'fehlt',
        'Nur nötig, wenn SQLite verwendet wird.'),
    pruefen('pdo_mysql', extension_loaded('pdo_mysql'), extension_loaded('pdo_mysql') ? 'geladen' : 'fehlt',
        'Nur nötig, wenn MySQL verwendet wird.'),
    pruefen('mbstring', extension_loaded('mbstring'), extension_loaded('mbstring') ? 'geladen' : 'fehlt',
        'Für Umlaute in Namen, Texten und E-Mails.'),
    pruefen('GD oder Imagick',
        extension_loaded('gd') || extension_loaded('imagick'),
        extension_loaded('gd') ? 'GD' : (extension_loaded('imagick') ? 'Imagick' : 'fehlt'),
        'Ohne Bildbibliothek werden hochgeladene Bilder nicht verkleinert.'),
    pruefen('cURL oder allow_url_fopen',
        extension_loaded('curl') || (bool) ini_get('allow_url_fopen'),
        extension_loaded('curl') ? 'cURL' : ((bool) ini_get('allow_url_fopen') ? 'fopen' : 'fehlt'),
        'Wird für Stripe und die KI-Anbindung gebraucht. Ohne beides laufen die '
        . 'übrigen Funktionen weiter.'),
    pruefen('mail()', function_exists('mail'), function_exists('mail') ? 'verfügbar' : 'gesperrt',
        'Ohne mail() bleiben Bestätigungen und Erinnerungen aus.'),
    pruefen('Speichergrenze', null, (string) ini_get('memory_limit'),
        'Für Videoanalysen sind 128M angenehm, 64M reichen aber.'),
    pruefen('Uploadgröße', null,
        ini_get('upload_max_filesize') . ' / post_max_size ' . ini_get('post_max_size'),
        'Schwungvideos sind schnell 30 MB groß. Der kleinere der beiden Werte gilt.'),
    pruefen('Laufzeitgrenze', null, ini_get('max_execution_time') . ' s',
        'Der Newsletterversand arbeitet in Schüben und kommt auch mit 30 Sekunden aus.'),
];

/* ----------------------------------------------------------- Ordner --- */

$ordner = [];
foreach (['data', 'uploads', 'uploads/bilder', 'uploads/videos', 'uploads/dokumente'] as $o) {
    $pfad = __DIR__ . '/' . $o;
    $ordner[] = pruefen($o . '/', is_dir($pfad) && is_writable($pfad),
        is_dir($pfad) ? (is_writable($pfad) ? 'beschreibbar' : 'nur lesbar') : 'fehlt',
        'Rechte meist 755, bei manchen Hostern 775.');
}
$ordner[] = pruefen('config.php geschützt',
    !is_file(__DIR__ . '/config.php') ? null : ((fileperms(__DIR__ . '/config.php') & 0004) === 0),
    is_file(__DIR__ . '/config.php')
        ? substr(sprintf('%o', fileperms(__DIR__ . '/config.php')), -4) : 'noch keine',
    'Die Datei enthält Zugangsdaten. 640 ist richtig, 644 vertretbar, 666 nicht.');
$ordner[] = pruefen('install.php entfernt', !is_file(__DIR__ . '/install.php'),
    is_file(__DIR__ . '/install.php') ? 'liegt noch da' : 'entfernt',
    'Nach der Einrichtung löschen.');
$gruppen['Dateien und Ordner'] = $ordner;

/* --------------------------------------------------------- Datenbank -- */

if (!$vorInstallation) {
    $db = [];
    try {
        $treiber = (string) Config::get('db.driver', 'sqlite');
        $db[] = pruefen('Verbindung', true, $treiber === 'mysql' ? 'MySQL/MariaDB' : 'SQLite');
        $db[] = pruefen('Schema-Stand', true, 'Version ' . Schema::VERSION);

        $tabellen = 0;
        foreach (['workspaces', 'users', 'customers', 'bookings', 'invoices', 'pages'] as $t) {
            try {
                DB::int('SELECT COUNT(*) FROM ' . $t);
                $tabellen++;
            } catch (Throwable $e) {
                $db[] = pruefen('Tabelle ' . $t, false, 'fehlt', 'Schema erneut migrieren lassen.');
            }
        }
        $db[] = pruefen('Kerntabellen', $tabellen === 6, $tabellen . ' von 6 lesbar');

        if ($treiber === 'sqlite') {
            $datei = (string) Config::get('db.path', __DIR__ . '/data/golfpro.sqlite');
            $db[] = pruefen('Datenbankdatei', is_writable($datei),
                is_file($datei) ? Util::bytes((int) filesize($datei)) : 'fehlt',
                'Muss für PHP beschreibbar sein.');
            $db[] = pruefen('Nicht öffentlich erreichbar',
                is_file(__DIR__ . '/data/.htaccess') || !str_starts_with(realpath($datei) ?: '', __DIR__),
                is_file(__DIR__ . '/data/.htaccess') ? 'per .htaccess gesperrt' : 'ungeschützt',
                'Ohne Apache: data/ außerhalb des Webverzeichnisses ablegen oder über die '
                . 'Serverkonfiguration sperren.');
        }

        $db[] = pruefen('Workspaces', true, (string) DB::int('SELECT COUNT(*) FROM workspaces'));
        $db[] = pruefen('Kunden gesamt', true, (string) DB::int('SELECT COUNT(*) FROM customers'));
    } catch (Throwable $e) {
        $db[] = pruefen('Verbindung', false, $e->getMessage(), 'Zugangsdaten in der config.php prüfen.');
    }
    $gruppen['Datenbank'] = $db;

    /* ------------------------------------------------------- Dienste -- */

    $gruppen['Dienste'] = [
        pruefen('Basisadresse', (string) Config::get('base_url', '') !== '',
            (string) Config::get('base_url', '') ?: 'nicht gesetzt',
            'Steht in Links in E-Mails. Ohne sie wird geraten.'),
        pruefen('Sitzungsgeheimnis', strlen((string) Config::get('secret', '')) >= 32,
            strlen((string) Config::get('secret', '')) . ' Zeichen',
            'Mindestens 32 Zeichen. Der Installer erzeugt es.'),
        pruefen('Stripe', Stripe::eingerichtet() ? true : null,
            Stripe::eingerichtet() ? (Stripe::testmodus() ? 'Testmodus' : 'aktiv') : 'nicht eingerichtet',
            'Ohne Stripe laufen Verkäufe auf Rechnung weiter.'),
        pruefen('Stripe-Webhook', (string) Config::get('stripe.webhook_secret', '') !== '' ? true : null,
            (string) Config::get('stripe.webhook_secret', '') !== '' ? 'hinterlegt' : 'fehlt',
            'Ohne Webhook-Geheimnis werden Zahlungen nicht automatisch verbucht.'),
        pruefen('KI-Schlüssel', (string) Config::get('ai.api_key', '') !== '' ? true : null,
            (string) Config::get('ai.api_key', '') !== '' ? 'hinterlegt' : 'nicht hinterlegt',
            'Ohne Schlüssel arbeiten die Textwerkzeuge mit dem eingebauten Generator weiter.'),
        pruefen('Wartung zuletzt', null,
            (int) Tenant::einstellung('wartung_letzter_lauf', 0) > 0
                ? Util::relativ(date('Y-m-d H:i:s', (int) Tenant::einstellung('wartung_letzter_lauf', 0)))
                : 'noch nie',
            'Läuft beim Öffnen des Dashboards mit, höchstens alle 15 Minuten.'),
        pruefen('Zeitzone', date_default_timezone_get() === 'Europe/Berlin',
            date_default_timezone_get() . ' · jetzt ' . date('H:i'),
            'Termine werden in dieser Zeitzone gespeichert und angezeigt.'),
    ];
}

/* -------------------------------------------------------- Darstellung - */

$probleme = 0;
foreach ($gruppen as $eintraege) {
    foreach ($eintraege as [, $zustand]) {
        if ($zustand === false) {
            $probleme++;
        }
    }
}
?><!DOCTYPE html>
<html lang="de"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Systemprüfung · GolfPro CMS</title>
<style>
*{box-sizing:border-box}
body{margin:0;background:#f6f7f6;color:#16191a;
  font:15px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif}
.huelle{max-width:760px;margin:0 auto;padding:44px 20px 70px}
h1{font-size:23px;letter-spacing:-.02em;margin:0 0 6px}
.unter{color:#5b6360;margin:0 0 26px}
.karte{background:#fff;border:1px solid #e3e6e4;border-radius:14px;padding:6px 20px;margin-bottom:16px}
h2{font-size:13px;text-transform:uppercase;letter-spacing:.06em;color:#78817d;margin:16px 0 6px}
.zeile{display:flex;align-items:baseline;gap:14px;padding:10px 0;border-bottom:1px solid #f0f2f1}
.zeile:last-child{border-bottom:0}
.name{flex:1;min-width:0;font-weight:560}
.wert{font-variant-numeric:tabular-nums;color:#5b6360;text-align:right;word-break:break-word}
.marke{width:9px;height:9px;border-radius:50%;flex:none;margin-top:7px}
.ok{background:#17794f}.weg{background:#b4242b}.egal{background:#c9cdca}
.rat{font-size:12.5px;color:#868d89;margin-top:2px;font-weight:400}
.kopfkarte{border-radius:14px;padding:18px 20px;margin-bottom:22px;font-weight:560}
.gut{background:#e8f5ee;border:1px solid #b9dfc9;color:#12603d}
.schlecht{background:#fdeceb;border:1px solid #f2c3c0;color:#8f2721}
a.zurueck{display:inline-block;margin-top:10px;color:#1d6f4a}
@media(max-width:520px){.zeile{flex-wrap:wrap}.wert{text-align:left;width:100%}}
</style></head><body><div class="huelle">
<h1>Systemprüfung</h1>
<p class="unter">Was der Server kann und was noch fehlt.</p>

<div class="kopfkarte <?= $probleme === 0 ? 'gut' : 'schlecht' ?>">
  <?= $probleme === 0
      ? 'Alles Nötige ist vorhanden. Graue Punkte sind Hinweise, keine Fehler.'
      : $probleme . ' Punkt' . ($probleme === 1 ? '' : 'e') . ' müssen noch in Ordnung gebracht werden – '
        . 'sie sind unten rot markiert.' ?>
</div>

<?php foreach ($gruppen as $titel => $eintraege): ?>
  <h2><?= htmlspecialchars($titel, ENT_QUOTES, 'UTF-8') ?></h2>
  <div class="karte">
    <?php foreach ($eintraege as [$name, $zustand, $wert, $rat]): ?>
      <div class="zeile">
        <span class="marke <?= $zustand === true ? 'ok' : ($zustand === false ? 'weg' : 'egal') ?>"></span>
        <span class="name"><?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>
          <?php if ($zustand === false && $rat !== ''): ?>
            <span class="rat"><?= htmlspecialchars($rat, ENT_QUOTES, 'UTF-8') ?></span>
          <?php endif; ?>
        </span>
        <span class="wert"><?= htmlspecialchars($wert, ENT_QUOTES, 'UTF-8') ?></span>
      </div>
    <?php endforeach; ?>
  </div>
<?php endforeach; ?>

<a class="zurueck" href="<?= $vorInstallation ? 'install.php' : 'app/' ?>">
  <?= $vorInstallation ? 'Zur Einrichtung' : 'Zurück zum Dashboard' ?></a>
</div></body></html>
